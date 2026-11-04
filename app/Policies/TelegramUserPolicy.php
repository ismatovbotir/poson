<?php

namespace App\Policies;

use App\Models\TelegramUser;
use App\Models\User;

/**
 * Bot inbox access (plan 14 v2 §10, TG-35). Record rule in
 * TelegramUser::isVisibleTo() / scopeVisibleTo(): telegram.inbox-all sees every
 * conversation, telegram.inbox the user's own and unassigned ones. Every
 * ability except viewAny and delete builds on view, so assign / ban can never
 * reach another manager's thread. Without telegram.inbox-all an unregistered
 * conversation is never visible (T21-11, in isVisibleTo()). Admins also pass through Gate::before.
 */
class TelegramUserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('telegram.inbox');
    }

    public function view(User $user, TelegramUser $telegramUser): bool
    {
        return $telegramUser->isVisibleTo($user);
    }

    public function reply(User $user, TelegramUser $telegramUser): bool
    {
        return $this->view($user, $telegramUser);
    }

    public function convert(User $user, TelegramUser $telegramUser): bool
    {
        return $this->view($user, $telegramUser);
    }

    public function close(User $user, TelegramUser $telegramUser): bool
    {
        return $this->view($user, $telegramUser);
    }

    /**
     * Pick a manager for the conversation.
     */
    public function assign(User $user, TelegramUser $telegramUser): bool
    {
        return $this->view($user, $telegramUser) && $user->can('telegram.reassign');
    }

    public function ban(User $user, TelegramUser $telegramUser): bool
    {
        return $this->view($user, $telegramUser) && $user->can('telegram.ban');
    }

    /**
     * Delete a conversation (Admin only: needs both inbox-all and bot).
     */
    public function delete(User $user, TelegramUser $telegramUser): bool
    {
        return $user->can('telegram.inbox-all') && $user->can('telegram.bot');
    }
}
