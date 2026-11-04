<?php

namespace App\Actions\Telegram;

use App\Enums\ActivityType;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Give a bot conversation to a manager, or take an unassigned one (plan 21 A3,
 * TG-35, T21-17). Policy "assign" (view + telegram.reassign) is checked against
 * the row as it is now. The target is re-checked at action time: an active user
 * with telegram.inbox. Both writes are conditional on the owner just read, so a
 * lost race refuses. An unregistered conversation has no assignable state: its
 * manager could not see it (T21-11).
 */
class AssignConversation
{
    /**
     * @return bool whether the owner changed
     *
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function assign(TelegramUser $conversation, User $actor, int $targetId): bool
    {
        return DB::transaction(function () use ($conversation, $actor, $targetId): bool {
            $fresh = $this->authorized($conversation, $actor);
            $target = $this->target($targetId);

            if ($fresh->manager_id === $target->id) {
                return false;
            }

            $this->write($fresh, $actor, $target, 'assign');

            return true;
        });
    }

    /**
     * @return bool whether the owner changed
     *
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function take(TelegramUser $conversation, User $actor): bool
    {
        return DB::transaction(function () use ($conversation, $actor): bool {
            $fresh = $this->authorized($conversation, $actor);
            $target = $this->target($actor->id);

            if ($fresh->manager_id === $target->id) {
                return false;
            }

            if ($fresh->manager_id !== null) {
                throw new InboxRefused('taken');
            }

            $this->write($fresh, $actor, $target, 'take');

            return true;
        });
    }

    /**
     * People a conversation can be given to (ordered by name).
     *
     * @return Collection<int, User>
     */
    public function candidates(): Collection
    {
        return User::query()->permission('telegram.inbox')->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    private function authorized(TelegramUser $conversation, User $actor): TelegramUser
    {
        $fresh = TelegramUser::query()->findOrFail($conversation->getKey());

        Gate::forUser($actor)->authorize('assign', $fresh);

        if (! $fresh->isRegistered()) {
            throw new InboxRefused('unregistered');
        }

        return $fresh;
    }

    /**
     * @throws InboxRefused
     */
    private function target(int $targetId): User
    {
        $target = User::query()->whereKey($targetId)->where('is_active', true)->first();

        if (! $target instanceof User || ! $target->can('telegram.inbox')) {
            throw new InboxRefused('invalid_target');
        }

        return $target;
    }

    /**
     * @throws InboxRefused
     */
    private function write(TelegramUser $fresh, User $actor, User $target, string $action): void
    {
        $query = TelegramUser::query()->whereKey($fresh->id);

        $fresh->manager_id === null ? $query->whereNull('manager_id') : $query->where('manager_id', $fresh->manager_id);

        if ($query->update(['manager_id' => $target->id]) !== 1) {
            throw new InboxRefused('taken');
        }

        $fresh->activities()->create([
            'user_id' => $actor->id,
            'type' => ActivityType::Assignment,
            'meta' => ['action' => $action, 'from_id' => $fresh->manager_id, 'to_id' => $target->id],
            'created_at' => now(),
        ]);
    }
}
