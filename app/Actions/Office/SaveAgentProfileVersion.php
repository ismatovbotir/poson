<?php

namespace App\Actions\Office;

use App\Enums\AgentRole;
use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\AgentProfile;
use App\Models\AgentProfileVersion;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Rules\BrandSafeText;
use App\Rules\ExampleTextGuard;
use App\Services\Ai\AnthropicChatModel;
use App\Support\Office\AgentGuidance;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Save an agent's teaching as a new immutable version (plan 41 §5, VO-10;
 * office.education = Admin only). Validated here, whatever the screen sent:
 * size limits (config office.education), every text brand-safe
 * (BrandSafeText, careers context: "sales team" as a team name), the model in AnthropicChatModel::MODELS (checked again by
 * the adapter), max_tokens within the role's bounds. The version number is
 * taken under a lock on the profile row. The site-knowledge toggle is saved
 * on the profile. Audited (area office, key office.education.{role},
 * Created, old / new instructions). Publishing is a separate step.
 *
 * Plan 46: every text (instructions, rules, knowledge, examples and the
 * guidance fields) also passes ExampleTextGuard (no contact data or digit
 * runs, ED46-2); examples have a kind (good / bad / route, capped); the
 * structured guidance is validated by AgentGuidance::rules(), normalised by
 * AgentGuidance::parse() and its rendered prompt block is capped. Without a
 * "guidance" key (legacy callers) the version keeps guidance null.
 */
class SaveAgentProfileVersion
{
    /**
     * @param  array{instructions?: mixed, knowledge?: mixed, rules?: mixed, examples?: mixed, model?: mixed, max_tokens?: mixed, change_note?: mixed, use_site_knowledge?: mixed}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, AgentRole $role, array $data, ?string $ip = null, bool $publish = false): AgentProfileVersion
    {
        if (! $actor->can('office.education') || ! in_array($role, AgentRole::profiles(), true)) {
            throw new AuthorizationException;
        }

        $valid = Validator::make($data, self::rules($role))->validate();

        if (is_array($valid['guidance'] ?? null)
            && mb_strlen(AgentGuidance::parse($valid['guidance'], $role)->render()) > (int) config('office.education.guidance.rendered_max')) {
            throw ValidationException::withMessages(['guidance' => __('validation.guidance_too_long')]);
        }

        return DB::transaction(function () use ($actor, $role, $valid, $ip, $publish): AgentProfileVersion {
            $profile = AgentProfile::query()->where('role', $role->value)->lockForUpdate()->first()
                ?? AgentProfile::query()->create(['role' => $role, 'use_site_knowledge' => true]);

            $previous = $profile->currentVersion;
            $next = (int) $profile->versions()->max('version') + 1;
            $guidance = array_key_exists('guidance', $valid) && is_array($valid['guidance']) ? AgentGuidance::parse($valid['guidance'], $role) : null;

            $version = $profile->versions()->create([
                'version' => $next,
                'instructions' => trim((string) $valid['instructions']),
                'knowledge' => self::nullable($valid['knowledge'] ?? null),
                'rules' => self::nullable($valid['rules'] ?? null),
                'examples' => self::examples($valid['examples'] ?? [], $role),
                'guidance' => $guidance?->toArray(),
                'model' => (string) $valid['model'],
                'max_tokens' => (int) $valid['max_tokens'],
                'change_note' => self::nullable($valid['change_note'] ?? null),
                'created_by_id' => $actor->id,
            ]);

            $profile->use_site_knowledge = (bool) ($valid['use_site_knowledge'] ?? $profile->use_site_knowledge);
            $profile->save();

            SettingsAudit::recordText(SettingsArea::Office, 'office.education.'.$role->value, AuditAction::Created, $actor, $ip, (string) $previous?->instructions, $version->instructions);

            if ($publish) {
                app(PublishAgentProfileVersion::class)->handle($actor, $profile, $version->id, $ip);
            }

            return $version;
        });
    }

    /**
     * Whether the version still has a {placeholder} (the seeded service facts, or a leftover in the other
     * guidance, the rules, the knowledge or the examples): saving warns, publishing is refused.
     */
    public static function hasPlaceholders(AgentProfileVersion $version, AgentRole $role): bool
    {
        $guidance = $version->guidanceFor($role);

        if ($guidance === null) {
            return false;
        }

        $text = implode("\n", [
            $guidance->canonicalText(),
            (string) $version->rules,
            (string) $version->knowledge,
            ...array_map(fn (mixed $example): string => is_array($example) ? implode("\n", array_filter($example, 'is_string')) : '', (array) $version->examples),
        ]);

        return preg_match('/\{[^{}]*\}/u', $text) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(AgentRole $role): array
    {
        $limits = (array) config('office.education');
        $agent = (array) config('office.agents.'.$role->value);
        // The careers context allows "sales manager" / "sales team" as a team name only (config brand.allowances).
        $brand = new BrandSafeText(BrandSafeText::CAREERS);
        // ED46-2: no contact data or digit runs in any taught text (the company's own contacts are the only exception).
        $guard = new ExampleTextGuard;
        $guidance = (array) ($limits['guidance'] ?? []);

        return [
            'instructions' => ['required', 'string', 'max:'.(int) $limits['instructions_max'], $brand, $guard],
            'knowledge' => ['nullable', 'string', 'max:'.(int) $limits['knowledge_max'], $brand, $guard],
            'rules' => ['nullable', 'string', 'max:'.(int) $limits['rules_max'], $brand, $guard],
            'examples' => ['nullable', 'array', 'max:'.(int) $limits['examples_max'], self::exampleCaps($guidance)],
            'examples.*.kind' => ['nullable', 'string', Rule::in(self::KINDS)],
            'examples.*.language' => ['nullable', 'string', Rule::in(['uz', 'ru', 'en'])],
            'examples.*.role' => ['nullable', 'string', Rule::in(array_column(AgentRole::routable(), 'value'))],
            'examples.*.source_feedback_id' => ['nullable', 'integer', 'min:1'],
            'examples.*.client' => ['nullable', 'string', 'max:'.(int) $limits['example_chars'], $brand, $guard],
            'examples.*.reply' => ['nullable', 'string', 'max:'.(int) $limits['example_chars'], $brand, $guard],
            'examples.*.note' => ['nullable', 'string', 'max:'.(int) ($guidance['example_note_chars'] ?? $limits['example_chars']), $brand, $guard],
            'model' => ['required', 'string', Rule::in(AnthropicChatModel::MODELS)],
            'max_tokens' => ['required', 'integer', 'min:'.(int) ($agent['max_tokens_min'] ?? 100), 'max:'.(int) ($agent['max_tokens_max'] ?? 500)],
            'change_note' => ['nullable', 'string', 'max:'.(int) $limits['change_note_max']],
            'use_site_knowledge' => ['nullable', 'boolean'],
        ] + AgentGuidance::rules($role);
    }

    /**
     * "route" examples only for the router; at most N good and M bad examples (plan 46 §2.1).
     *
     * @param  array<string, mixed>  $guidance
     */
    private static function exampleCaps(array $guidance): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($guidance): void {
            $counts = ['good' => 0, 'bad' => 0, 'route' => 0];

            foreach (is_array($value) ? $value : [] as $example) {
                $kind = (string) (is_array($example) ? ($example['kind'] ?? 'good') : 'good');
                $kind = $kind === '' ? 'good' : $kind;

                if (isset($counts[$kind])) {
                    $counts[$kind]++;
                }
            }

            if ($counts['good'] > (int) ($guidance['examples_good_max'] ?? 6) || $counts['bad'] > (int) ($guidance['examples_bad_max'] ?? 4)) {
                $fail('validation.max.array')->translate(['max' => (int) ($guidance['examples_good_max'] ?? 6)]);
            }
        };
    }

    /** Example kinds (a missing kind reads as good). */
    private const KINDS = ['good', 'bad', 'route'];

    private static function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Non-empty examples only, as {kind, language, client, reply, note, role?, source_feedback_id?}.
     * Route examples are for the router only (an agent keeps them as good).
     *
     * @return list<array<string, mixed>>
     */
    private static function examples(mixed $examples, AgentRole $role): array
    {
        $clean = [];

        foreach (is_array($examples) ? $examples : [] as $example) {
            $kind = in_array($example['kind'] ?? null, self::KINDS, true) ? (string) $example['kind'] : 'good';
            $row = [
                'kind' => $kind === 'route' && $role !== AgentRole::Router ? 'good' : $kind,
                'language' => in_array($example['language'] ?? null, ['uz', 'ru', 'en'], true) ? (string) $example['language'] : 'uz',
                'client' => trim((string) ($example['client'] ?? '')),
                'reply' => trim((string) ($example['reply'] ?? '')),
                'note' => trim((string) ($example['note'] ?? '')),
            ];

            if ($row['kind'] === 'route') {
                $routed = AgentRole::tryFrom((string) ($example['role'] ?? ''));
                $row['role'] = $routed !== null && in_array($routed, AgentRole::routable(), true) ? $routed->value : AgentRole::Other->value;
            }

            if (is_numeric($example['source_feedback_id'] ?? null) && (int) $example['source_feedback_id'] > 0) {
                $row['source_feedback_id'] = (int) $example['source_feedback_id'];
            }

            if ($row['client'] !== '' || $row['reply'] !== '') {
                $clean[] = $row;
            }
        }

        return $clean;
    }
}
