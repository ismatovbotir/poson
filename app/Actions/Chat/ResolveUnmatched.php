<?php

namespace App\Actions\Chat;

use App\Models\ChatRule;
use App\Models\ChatUnmatched;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Close a phrase on the "unmatched" list (plan 28 §5): "ignore" (no rule) or,
 * after the editor created a rule for it, resolved by that rule. Needs
 * chatbot.rules. Does not touch the rule cache.
 */
class ResolveUnmatched
{
    /**
     * @return bool Whether an open row was closed
     */
    public function handle(ChatUnmatched $unmatched, User $actor, ?ChatRule $rule = null): bool
    {
        Gate::forUser($actor)->authorize('update', $unmatched);

        return ChatUnmatched::query()
            ->whereKey($unmatched->getKey())
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_rule_id' => $rule?->id, 'updated_at' => now()]) > 0;
    }
}
