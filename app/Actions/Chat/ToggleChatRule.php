<?php

namespace App\Actions\Chat;

use App\Models\ChatRule;
use App\Models\User;
use App\Support\Chat\RuleIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Switch a chat rule on or off from the rules list (plan 28 C2). Needs
 * chatbot.rules. A system rule can be switched off (it cannot be deleted).
 * The compiled-rule cache is invalidated after the commit.
 */
class ToggleChatRule
{
    public function handle(ChatRule $rule, User $actor, bool $active): ChatRule
    {
        Gate::forUser($actor)->authorize('update', $rule);

        return DB::transaction(function () use ($rule, $actor, $active): ChatRule {
            $locked = ChatRule::query()->whereKey($rule->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->is_active !== $active) {
                $locked->forceFill(['is_active' => $active, 'updated_by_id' => $actor->id])->save();
                RuleIndex::bump();
            }

            return $locked;
        });
    }
}
