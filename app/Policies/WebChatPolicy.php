<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WebChat;

/**
 * Website chat inbox access (plan 28 C5 §19.5, CB-21), mirroring
 * TelegramUserPolicy with no new permissions. Record rule in
 * WebChat::isVisibleTo() / scopeVisibleTo(): telegram.inbox-all sees every
 * chat; telegram.inbox only handed-off chats that are unassigned or the
 * user's own (bot-only chats never). Every ability except viewAny and delete
 * builds on view. Visitors never pass through here: they reach their chat by
 * the cookie only. Admins also pass through Gate::before.
 */
class WebChatPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('telegram.inbox');
    }

    public function view(User $user, WebChat $webChat): bool
    {
        return $webChat->isVisibleTo($user);
    }

    public function reply(User $user, WebChat $webChat): bool
    {
        return $this->view($user, $webChat);
    }

    public function convert(User $user, WebChat $webChat): bool
    {
        return $this->view($user, $webChat);
    }

    public function close(User $user, WebChat $webChat): bool
    {
        return $this->view($user, $webChat);
    }

    public function assign(User $user, WebChat $webChat): bool
    {
        return $this->view($user, $webChat) && $user->can('telegram.reassign');
    }

    public function ban(User $user, WebChat $webChat): bool
    {
        return $this->view($user, $webChat) && $user->can('telegram.ban');
    }

    /**
     * Delete a chat (Admin only: needs both inbox-all and bot).
     */
    public function delete(User $user, WebChat $webChat): bool
    {
        return $user->can('telegram.inbox-all') && $user->can('telegram.bot');
    }
}
