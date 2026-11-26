<?php

namespace App\Policies;

use App\Models\TelegramGroup;
use App\Models\User;

/**
 * Telegram group access (plan 44 §2, OG-7). The record rule is
 * TelegramGroup::isVisibleTo() / scopeVisibleTo(): Admin every group
 * (untracked and ignored ones are Admin only), anyone else with
 * office.chats only a tracked group assigned to them. Track, ignore,
 * member roles, refresh and forget are office.mark (Admin); "Analyse now"
 * is office.analyse on a visible group. No new permissions. Admins also pass through Gate::before.
 */
class TelegramGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('office.chats') || $user->can('office.mark');
    }

    public function view(User $user, TelegramGroup $telegramGroup): bool
    {
        return $telegramGroup->isVisibleTo($user);
    }

    /**
     * "Analyse now" on a group the user may see (plan 44 T6; gates, caps and throttle in RequestGroupAnalysis).
     */
    public function analyse(User $user, TelegramGroup $telegramGroup): bool
    {
        return $user->can('office.analyse') && $this->view($user, $telegramGroup);
    }

    /**
     * Track, ignore, set member roles, refresh admins, forget a member.
     */
    public function mark(User $user, TelegramGroup $telegramGroup): bool
    {
        return $user->can('office.mark') && $this->view($user, $telegramGroup);
    }
}
