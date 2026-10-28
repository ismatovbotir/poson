<?php

namespace App\Policies;

use App\Models\User;

/**
 * Settings → AI prompts (plan 12). ai-prompts.manage is seeded for Admin
 * only (who also passes through Gate::before). Keyed by field, so both
 * abilities are class-level: $this->authorize('update', AiPromptOverride::class).
 */
class AiPromptOverridePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ai-prompts.manage');
    }

    public function update(User $user): bool
    {
        return $user->can('ai-prompts.manage');
    }
}
