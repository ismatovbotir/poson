<?php

namespace App\Actions\Ai;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\AiPromptOverride;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Ai\FieldRegistry;
use App\Support\Ai\PromptTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Save the admin text of one AI field's default hint (Settings → AI
 * prompts, plan 12). Authorizes ai-prompts.manage (AiPromptOverridePolicy)
 * itself; the key must be registered; the text is normalized (LF, trimmed)
 * and must be PromptTemplate::MIN_LENGTH..MAX_LENGTH characters with only
 * the field's allowed placeholders. Missing placeholders and brand-rule
 * words are warnings for the screen, not errors.
 *
 * A text equal to the lang default of any locale is not an override: the
 * row (if any) is deleted and audited as Cleared. Otherwise the row is
 * created or updated and audited as Updated (an unchanged text is a no-op).
 * One transaction; settings_audits never stores the text. The
 * PromptOverrides cache is flushed by the model after the commit.
 */
class SaveAiPromptOverride
{
    /**
     * @return AiPromptOverride|null The saved override, or null when the default applies
     *
     * @throws ValidationException
     */
    public function handle(string $fieldKey, string $prompt, User $user, ?string $ip): ?AiPromptOverride
    {
        Gate::forUser($user)->authorize('update', AiPromptOverride::class);

        if (! FieldRegistry::has($fieldKey)) {
            throw ValidationException::withMessages(['prompt' => __('ai.errors.unknown_field')]);
        }

        $field = FieldRegistry::get($fieldKey);
        $prompt = PromptTemplate::normalize($prompt);
        $length = mb_strlen($prompt);

        if ($length < PromptTemplate::MIN_LENGTH || $length > PromptTemplate::MAX_LENGTH) {
            throw ValidationException::withMessages(['prompt' => __('admin.ai_prompts.errors.length', ['min' => PromptTemplate::MIN_LENGTH, 'max' => PromptTemplate::MAX_LENGTH])]);
        }

        $unknown = PromptTemplate::unknownPlaceholders($field, $prompt);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['prompt' => __('admin.ai_prompts.errors.unknown_placeholders', ['list' => implode(', ', $unknown)])]);
        }

        $isDefault = collect(config('app.supported_locales'))
            ->contains(fn (string $locale): bool => PromptTemplate::normalize($field->defaultTemplate($locale)) === $prompt);

        return DB::transaction(function () use ($fieldKey, $prompt, $isDefault, $user, $ip): ?AiPromptOverride {
            $override = AiPromptOverride::query()->where('field_key', $fieldKey)->lockForUpdate()->first();

            if ($isDefault) {
                if ($override !== null) {
                    $override->delete();
                    SettingsAudit::record(SettingsArea::AiPrompts, $fieldKey, AuditAction::Cleared, $user, $ip);
                }

                return null;
            }

            if ($override?->prompt === $prompt) {
                return $override;
            }

            $override ??= new AiPromptOverride(['field_key' => $fieldKey]);
            $override->fill(['prompt' => $prompt, 'updated_by' => $user->id])->save();

            SettingsAudit::record(SettingsArea::AiPrompts, $fieldKey, AuditAction::Updated, $user, $ip);

            return $override;
        });
    }
}
