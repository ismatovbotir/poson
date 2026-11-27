<?php

namespace App\Actions\Office;

use App\Enums\AgentRole;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Models\AgentProfile;
use App\Models\AgentProfileVersion;
use App\Models\OfficeDraftFeedback;
use App\Models\SettingsAudit;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Rules\BrandSafeText;
use App\Rules\ExampleTextGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Turn one feedback row into an example of the agent (plan 46 §2.2, ED-1, ED46-5): manual, Admin only (the
 * role is checked here, not only the permission), never automatic and never published.
 *
 *  - The Admin's password is checked in here with Hash::check, throttled to 5 attempts a minute (every
 *    attempt counts): a session that is already signed in as Admin is not enough.
 *  - The Admin writes the client and reply texts (the form is pre-filled from the redacted final text) and
 *    ticks "anonymised and rewritten". Then, in this order: ExampleTextGuard on every text (phones, cards,
 *    tax ids, e-mails, links, handles, digit runs, normalised against obfuscation), the names of the source
 *    chat's or group's participants (first and user names, 3+ characters, case-insensitive, the current
 *    members included), BrandSafeText.
 *  - Result: a NEW unpublished version = the published version of that agent plus this example (kind
 *    good or bad, with source_feedback_id), refused when its kind cap (6 good, 4 bad, 10 in total) is full.
 *    The feedback row records the version, the Admin and the time. Audited with ids only.
 */
class PromoteFeedbackToExample
{
    public const PASSWORD_PER_MINUTE = 5;

    /**
     * @param  array{kind?: mixed, language?: mixed, client?: mixed, reply?: mixed, note?: mixed, anonymised?: mixed, password?: mixed}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, OfficeDraftFeedback $feedback, array $data, ?string $ip = null): AgentProfileVersion
    {
        if (! $actor->hasRole(Role::Admin->value) || ! $actor->can('office.education')) {
            throw new AuthorizationException;
        }

        $this->confirmPassword($actor, $data['password'] ?? null);

        $limits = (array) config('office.education.guidance');
        $valid = Validator::make($data, [
            'kind' => ['required', Rule::in(['good', 'bad'])],
            'language' => ['required', Rule::in(['uz', 'ru', 'en'])],
            'client' => ['required', 'string', 'max:'.(int) config('office.education.example_chars')],
            'reply' => ['required', 'string', 'max:'.(int) config('office.education.example_chars')],
            'note' => ['nullable', 'string', 'max:'.(int) $limits['example_note_chars']],
            'anonymised' => ['accepted'],
        ])->validate();

        $fresh = OfficeDraftFeedback::query()->find($feedback->id);

        if (! $fresh instanceof OfficeDraftFeedback || ! in_array($fresh->role, AgentRole::agents(), true)) {
            throw ValidationException::withMessages(['feedback' => __('office.review.errors.gone')]);
        }

        if ($fresh->promoted_version_id !== null) {
            throw ValidationException::withMessages(['feedback' => __('office.review.errors.already')]);
        }

        $texts = [(string) $valid['client'], (string) $valid['reply'], (string) ($valid['note'] ?? '')];
        $names = $this->names($fresh);

        foreach ($texts as $text) {
            if (ExampleTextGuard::violates($text) || $this->mentionsName($text, $names)) {
                throw ValidationException::withMessages(['guard' => __('office.review.errors.guard')]);
            }

            if (BrandSafeText::violates($text, BrandSafeText::CAREERS)) {
                throw ValidationException::withMessages(['guard' => __('validation.brand_safe', ['attribute' => 'text'])]);
            }
        }

        return DB::transaction(function () use ($actor, $fresh, $valid, $ip): AgentProfileVersion {
            $profile = AgentProfile::query()->where('role', $fresh->role->value)->lockForUpdate()->first();
            $current = $profile?->currentVersion;

            if (! $profile instanceof AgentProfile || ! $current instanceof AgentProfileVersion) {
                throw ValidationException::withMessages(['feedback' => __('office.review.errors.no_version')]);
            }

            $examples = array_values((array) $current->examples);
            $kind = (string) $valid['kind'];
            $count = fn (string $of): int => count(array_filter($examples, fn (mixed $example): bool => is_array($example) && ($example['kind'] ?? 'good') === $of));

            if (count($examples) >= (int) config('office.education.examples_max')
                || $count($kind) >= (int) config('office.education.guidance.examples_'.$kind.'_max')) {
                throw ValidationException::withMessages(['kind' => __('office.review.errors.cap_full', ['kind' => __('office.education.kind_'.$kind)])]);
            }

            $examples[] = array_filter([
                'kind' => $kind,
                'language' => (string) $valid['language'],
                'client' => trim((string) $valid['client']),
                'reply' => trim((string) $valid['reply']),
                'note' => trim((string) ($valid['note'] ?? '')),
                'source_feedback_id' => $fresh->id,
            ], fn (mixed $value): bool => $value !== null);

            $version = $profile->versions()->create([
                'version' => (int) $profile->versions()->max('version') + 1,
                'instructions' => $current->instructions,
                'knowledge' => $current->knowledge,
                'rules' => $current->rules,
                'examples' => $examples,
                'guidance' => $current->guidance,
                'model' => $current->model,
                'max_tokens' => $current->max_tokens,
                'change_note' => 'Example from feedback #'.$fresh->id,
                'created_by_id' => $actor->id,
            ]);

            $fresh->forceFill(['promoted_version_id' => $version->id, 'promoted_by_id' => $actor->id, 'promoted_at' => now()])->save();

            SettingsAudit::recordText(SettingsArea::Office, 'office.education.'.$profile->role->value, AuditAction::Promoted, $actor, $ip, '', 'feedback='.$fresh->id.' version=v'.$version->version);

            return $version;
        });
    }

    /**
     * Hash::check against the signed-in Admin's own password; every attempt counts, 5 a minute.
     *
     * @throws ValidationException
     */
    private function confirmPassword(User $actor, mixed $password): void
    {
        $key = 'office-promote-password:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, self::PASSWORD_PER_MINUTE)) {
            throw ValidationException::withMessages(['password' => __('office.review.errors.password_throttled')]);
        }

        RateLimiter::hit($key, 60);

        if (! is_string($password) || $password === '' || ! Hash::check($password, (string) $actor->password)) {
            throw ValidationException::withMessages(['password' => __('office.review.errors.password')]);
        }

        RateLimiter::clear($key);
    }

    /**
     * First names and user names of the source chat or group and its current members (3+ characters; a
     * several-word name also by word).
     *
     * @return list<string>
     */
    private function names(OfficeDraftFeedback $feedback): array
    {
        $raw = [];

        if ($feedback->business_chat_id !== null) {
            $chat = $feedback->businessChat;
            $raw = [$chat?->first_name, $chat?->username];
        }

        if ($feedback->telegram_group_id !== null) {
            $group = TelegramGroup::query()->with('members:id,telegram_group_id,first_name,username')->find($feedback->telegram_group_id);
            $raw = [$group?->title, $group?->username];

            foreach ($group?->members ?? [] as $member) {
                $raw[] = $member->first_name;
                $raw[] = $member->username;
            }
        }

        $names = [];

        foreach ($raw as $name) {
            $name = ltrim(self::fold((string) $name), '@');

            foreach ([$name, ...preg_split('/\s+/u', $name) ?: []] as $part) {
                if (mb_strlen($part) >= 3) {
                    $names[$part] = true;

                    foreach (self::latin($part) as $variant) {
                        $names[$variant] = true;
                    }
                }
            }
        }

        return array_keys($names);
    }

    /**
     * @param  list<string>  $names
     */
    private function mentionsName(string $text, array $names): bool
    {
        $text = self::fold($text);

        // The text as written and in Latin letters, against the names as written and in Latin letters.
        foreach ([$text, ...self::latin($text)] as $variant) {
            foreach ($names as $name) {
                if (mb_stripos($variant, $name) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * NFKC, invisible characters, case and the apostrophes (' ʻ ʼ ‘ ’ and look-alikes) folded away.
     */
    private static function fold(string $text): string
    {
        return str_replace(["'", 'ʻ', 'ʼ', '‘', '’', '`', '´', 'ʹ', 'ˈ'], '', ExampleTextGuard::normalise($text));
    }

    /**
     * A basic Cyrillic to Latin transliteration (Russian and Uzbek letters), so a name written in one script
     * is found in the other. Two spellings of х and ж (kh / x, zh / j) are tried.
     *
     * @return list<string>
     */
    private static function latin(string $text): array
    {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya', 'ў' => 'u', 'қ' => 'q', 'ғ' => 'g', 'ҳ' => 'h',
        ];

        return [
            strtr($text, $map + ['х' => 'kh', 'ж' => 'zh']),
            strtr($text, $map + ['х' => 'x', 'ж' => 'j']),
        ];
    }
}
