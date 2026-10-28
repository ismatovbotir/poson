<?php

namespace App\Actions\Ai;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\AiPromptOverride;
use App\Models\SettingsAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Back to the default hint of one AI field (Settings → AI prompts, plan
 * 12): deletes the override row and audits it as Cleared, in one
 * transaction. Authorizes ai-prompts.manage itself. Resetting a field
 * without an override is a no-op (returns false). The PromptOverrides
 * cache is flushed by the model after the commit.
 */
class ResetAiPromptOverride
{
    /**
     * @return bool whether an override was deleted
     */
    public function handle(string $fieldKey, User $user, ?string $ip): bool
    {
        Gate::forUser($user)->authorize('update', AiPromptOverride::class);

        return DB::transaction(function () use ($fieldKey, $user, $ip): bool {
            $override = AiPromptOverride::query()->where('field_key', $fieldKey)->lockForUpdate()->first();

            if ($override === null) {
                return false;
            }

            $override->delete();
            SettingsAudit::record(SettingsArea::AiPrompts, $fieldKey, AuditAction::Cleared, $user, $ip);

            return true;
        });
    }
}
