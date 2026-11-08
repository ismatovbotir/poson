<?php

namespace App\Actions\Chat;

use App\Models\ChatRule;
use App\Models\ChatStatDaily;
use App\Models\User;
use App\Support\Chat\RuleIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delete a chat rule (plan 28, CB-12). A system rule (chatbot:install-defaults)
 * is never deleted, whoever asks (switch it off instead). Keywords cascade;
 * the rule's stat rows go with it (chat_stats_daily has no foreign key);
 * "resolved by" links in the teach list are set to null by the database.
 * The compiled-rule cache is invalidated after the commit.
 */
class DeleteChatRule
{
    /**
     * @throws ValidationException
     */
    public function handle(ChatRule $rule, ?User $actor = null): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('delete', $rule);
        }

        DB::transaction(function () use ($rule): void {
            $locked = ChatRule::query()->whereKey($rule->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            if ($locked->is_system) {
                throw ValidationException::withMessages(['rule' => __('chatbot.errors.system_rule_delete')]);
            }

            ChatStatDaily::query()->where('chat_rule_id', $locked->id)->delete();
            $locked->delete();

            RuleIndex::bump();
        });
    }
}
