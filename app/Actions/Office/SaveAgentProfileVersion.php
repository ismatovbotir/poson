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
use App\Services\Ai\AnthropicChatModel;
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

        return DB::transaction(function () use ($actor, $role, $valid, $ip, $publish): AgentProfileVersion {
            $profile = AgentProfile::query()->where('role', $role->value)->lockForUpdate()->first()
                ?? AgentProfile::query()->create(['role' => $role, 'use_site_knowledge' => true]);

            $previous = $profile->currentVersion;
            $next = (int) $profile->versions()->max('version') + 1;

            $version = $profile->versions()->create([
                'version' => $next,
                'instructions' => trim((string) $valid['instructions']),
                'knowledge' => self::nullable($valid['knowledge'] ?? null),
                'rules' => self::nullable($valid['rules'] ?? null),
                'examples' => self::examples($valid['examples'] ?? []),
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
     * @return array<string, mixed>
     */
    public static function rules(AgentRole $role): array
    {
        $limits = (array) config('office.education');
        $agent = (array) config('office.agents.'.$role->value);
        // The careers context allows "sales manager" / "sales team" as a team name only (config brand.allowances).
        $brand = new BrandSafeText(BrandSafeText::CAREERS);

        return [
            'instructions' => ['required', 'string', 'max:'.(int) $limits['instructions_max'], $brand],
            'knowledge' => ['nullable', 'string', 'max:'.(int) $limits['knowledge_max'], $brand],
            'rules' => ['nullable', 'string', 'max:'.(int) $limits['rules_max'], $brand],
            'examples' => ['nullable', 'array', 'max:'.(int) $limits['examples_max']],
            'examples.*.client' => ['nullable', 'string', 'max:'.(int) $limits['example_chars'], $brand],
            'examples.*.reply' => ['nullable', 'string', 'max:'.(int) $limits['example_chars'], $brand],
            'examples.*.note' => ['nullable', 'string', 'max:'.(int) $limits['example_chars'], $brand],
            'model' => ['required', 'string', Rule::in(AnthropicChatModel::MODELS)],
            'max_tokens' => ['required', 'integer', 'min:'.(int) ($agent['max_tokens_min'] ?? 100), 'max:'.(int) ($agent['max_tokens_max'] ?? 500)],
            'change_note' => ['nullable', 'string', 'max:'.(int) $limits['change_note_max']],
            'use_site_knowledge' => ['nullable', 'boolean'],
        ];
    }

    private static function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Non-empty examples only, as {client, reply, note}.
     *
     * @return list<array{client: string, reply: string, note: string}>
     */
    private static function examples(mixed $examples): array
    {
        $clean = [];

        foreach (is_array($examples) ? $examples : [] as $example) {
            $row = [
                'client' => trim((string) ($example['client'] ?? '')),
                'reply' => trim((string) ($example['reply'] ?? '')),
                'note' => trim((string) ($example['note'] ?? '')),
            ];

            if ($row['client'] !== '' || $row['reply'] !== '') {
                $clean[] = $row;
            }
        }

        return $clean;
    }
}
