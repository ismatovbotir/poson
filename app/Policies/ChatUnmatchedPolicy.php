<?php

namespace App\Policies;

use App\Models\ChatUnmatched;
use App\Models\User;

/**
 * The "teach the bot" list (plan 28): permission chatbot.rules.
 */
class ChatUnmatchedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('chatbot.rules');
    }

    public function update(User $user, ChatUnmatched $unmatched): bool
    {
        return $user->can('chatbot.rules');
    }

    public function delete(User $user, ChatUnmatched $unmatched): bool
    {
        return $user->can('chatbot.rules');
    }
}
