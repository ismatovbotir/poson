<?php

namespace App\Actions\Office;

use App\Enums\ActivityType;
use App\Models\BusinessChat;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Assign a client business chat (plan 41 §4, VO-9). The policy decides who
 * may (Admin any chat; a sales manager only a chat assigned to them); the
 * target must be an active user with office.chats (a content manager is
 * refused). Removing the assignee (unassigned = Admin only) needs
 * office.chats-all. The assignee becomes the manager of the next lead.
 * Logged as an Assignment activity on the chat.
 */
class AssignBusinessChat
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, BusinessChat $chat, ?int $assigneeId): BusinessChat
    {
        if (! Gate::forUser($actor)->allows('assign', $chat)) {
            throw new AuthorizationException;
        }

        if ($assigneeId === null && ! $actor->can('office.chats-all')) {
            throw new AuthorizationException;
        }

        $target = $assigneeId !== null ? OfficeStaff::requireAssignable($assigneeId) : null;

        return DB::transaction(function () use ($actor, $chat, $target): BusinessChat {
            $locked = BusinessChat::query()->whereKey($chat->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isClient()) {
                throw new AuthorizationException;
            }

            $from = $locked->assignee_id;

            if ($from === $target?->id) {
                return $locked;
            }

            $locked->assignee_id = $target?->id;
            $locked->save();

            $locked->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::Assignment,
                'meta' => ['from_id' => $from, 'to_id' => $target?->id, 'to_name' => $target?->name],
                'created_at' => now(),
            ]);

            return $locked;
        });
    }
}
