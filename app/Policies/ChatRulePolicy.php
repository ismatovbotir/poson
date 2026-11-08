<?php

namespace App\Policies;

use App\Models\ChatRule;
use App\Models\User;

/**
 * Chat bot rules (plan 28): permission chatbot.rules (Admin and Content
 * manager, added with the C2 screens; Admins also pass Gate::before). A
 * system rule can be edited but never deleted.
 */
class ChatRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('chatbot.rules');
    }

    public function view(User $user, ChatRule $rule): bool
    {
        return $user->can('chatbot.rules');
    }

    public function create(User $user): bool
    {
        return $user->can('chatbot.rules');
    }

    public function update(User $user, ChatRule $rule): bool
    {
        return $user->can('chatbot.rules');
    }

    public function delete(User $user, ChatRule $rule): bool
    {
        return ! $rule->is_system && $user->can('chatbot.rules');
    }
}
