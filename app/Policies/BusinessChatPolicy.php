<?php

namespace App\Policies;

use App\Models\BusinessChat;
use App\Models\User;

/**
 * Virtual office chat access (plan 41 §7, VO-9). The record rule is
 * BusinessChat::isVisibleTo() / scopeVisibleTo(): Admin (office.chats-all)
 * every client chat, everyone else with office.chats only the client chats
 * assigned to them; unassigned, unmarked, employee and ignored chats are
 * Admin only. Every ability builds on view, so a tampered id never reaches
 * another user's chat. Admins also pass through Gate::before.
 */
class BusinessChatPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('office.chats');
    }

    public function view(User $user, BusinessChat $businessChat): bool
    {
        return $businessChat->isVisibleTo($user);
    }

    /**
     * "Analyse now" (VO-11 limits in the action).
     */
    public function analyse(User $user, BusinessChat $businessChat): bool
    {
        return $this->view($user, $businessChat) && $user->can('office.analyse');
    }

    /**
     * Hand the chat to another office user: office.assign and, without
     * office.chats-all, only a chat assigned to the user (sales: own → other).
     */
    public function assign(User $user, BusinessChat $businessChat): bool
    {
        return $this->view($user, $businessChat)
            && $user->can('office.assign')
            && ($user->can('office.chats-all') || $businessChat->assignee_id === $user->id);
    }

    /**
     * Mark as client / employee / ignored (Admin only).
     */
    public function mark(User $user, BusinessChat $businessChat): bool
    {
        return $user->can('office.mark');
    }

    /**
     * The "Create lead" form of a chat without a known phone.
     */
    public function createLead(User $user, BusinessChat $businessChat): bool
    {
        return $this->view($user, $businessChat) && $user->can('office.analyse') && $user->can('leads.create');
    }
}
