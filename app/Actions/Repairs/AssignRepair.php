<?php

namespace App\Actions\Repairs;

use App\Enums\ActivityType;
use App\Enums\RepairStatus;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who works on a repair (owner decision Q9: engineers take and release, the
 * Admin assigns). Components authorize with RepairPolicy (take / release /
 * assign) first; this action keeps the data rules and writes an
 * "assignment" activity (from / to with the names at that moment).
 *
 *  - take: an unassigned open repair goes to the user (first one wins).
 *  - release: the user's own open repair goes back to unassigned.
 *  - assign: pick an engineer from candidates(); null unassigns.
 *  - handOver(): what the staff handover (plan 24 A3) calls per repair, with
 *    openFor() to count / list the repairs it has to move.
 */
class AssignRepair
{
    /**
     * @throws ValidationException
     */
    public function take(Repair $repair, User $actor): Repair
    {
        return DB::transaction(function () use ($repair, $actor): Repair {
            $taken = Repair::query()->whereKey($repair->id)->whereNull('assigned_to_id')->whereIn('status', RepairStatus::valuesOf(RepairStatus::open()))
                ->update(['assigned_to_id' => $actor->id, 'updated_at' => now()]);

            if ($taken === 0) {
                throw ValidationException::withMessages(['assigned_to_id' => __('repairs.errors.already_assigned')]);
            }

            $repair->refresh();
            $this->log($repair, $actor, null, $actor, 'take');

            return $repair;
        });
    }

    /**
     * @throws ValidationException
     */
    public function release(Repair $repair, User $actor): Repair
    {
        return DB::transaction(function () use ($repair, $actor): Repair {
            $released = Repair::query()->whereKey($repair->id)->where('assigned_to_id', $actor->id)->whereIn('status', RepairStatus::valuesOf(RepairStatus::open()))
                ->update(['assigned_to_id' => null, 'updated_at' => now()]);

            if ($released === 0) {
                throw ValidationException::withMessages(['assigned_to_id' => __('repairs.errors.not_yours')]);
            }

            $repair->refresh();
            $this->log($repair, $actor, $actor, null, 'release');

            return $repair;
        });
    }

    /**
     * @throws ValidationException
     */
    public function assign(Repair $repair, ?int $userId, User $actor): Repair
    {
        $target = null;

        if ($userId !== null) {
            $target = static::eligibleUser($userId);

            if (! $target instanceof User) {
                throw ValidationException::withMessages(['assigned_to_id' => __('repairs.errors.assignee_invalid')]);
            }
        }

        return DB::transaction(function () use ($repair, $target, $actor): Repair {
            $row = Repair::query()->whereKey($repair->id)->lockForUpdate()->first(['id', 'assigned_to_id', 'status']);

            if (! $row instanceof Repair || $row->status->isClosed()) {
                throw ValidationException::withMessages(['assigned_to_id' => __('repairs.errors.closed')]);
            }

            if ($row->assigned_to_id === $target?->id) {
                $repair->assigned_to_id = $row->assigned_to_id;

                return $repair;
            }

            $previous = $row->assigned_to_id !== null ? User::query()->find($row->assigned_to_id) : null;

            $repair->assigned_to_id = $target?->id;
            $repair->save();

            $this->log($repair, $actor, $previous, $target, 'assign');

            return $repair;
        });
    }

    /**
     * Move one open repair from a leaving staff member to a colleague, or to
     * the pool (null). A conditional update: nothing happens, and false comes
     * back, when the repair is no longer theirs or already closed.
     */
    public function handOver(Repair $repair, User $from, ?User $to, User $actor): bool
    {
        if ($to instanceof User && static::eligibleUser($to->id) === null) {
            return false;
        }

        return DB::transaction(function () use ($repair, $from, $to, $actor): bool {
            $moved = Repair::query()->whereKey($repair->id)->where('assigned_to_id', $from->id)->whereIn('status', RepairStatus::valuesOf(RepairStatus::open()))
                ->update(['assigned_to_id' => $to?->id, 'updated_at' => now()]);

            if ($moved === 0) {
                return false;
            }

            $repair->refresh();
            $this->log($repair, $actor, $from, $to, 'handover');

            return true;
        });
    }

    /**
     * Open repairs currently assigned to the user.
     *
     * @return Builder<Repair>
     */
    public static function openFor(User $user): Builder
    {
        return Repair::query()->where('assigned_to_id', $user->id)->whereIn('status', RepairStatus::valuesOf(RepairStatus::open()));
    }

    /**
     * An active user who may work on repairs (null otherwise).
     */
    public static function eligibleUser(int $userId): ?User
    {
        return User::query()->permission('repairs.update')->where('is_active', true)->whereKey($userId)->first();
    }

    /**
     * People a repair may be given to (ordered by name).
     *
     * @return Collection<int, User>
     */
    public function candidates(): Collection
    {
        return User::query()
            ->permission('repairs.update')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function log(Repair $repair, User $actor, ?User $from, ?User $to, string $action): void
    {
        $repair->activities()->create([
            'user_id' => $actor->id,
            'type' => ActivityType::Assignment,
            'meta' => [
                'action' => $action,
                'from_id' => $from?->id,
                'from_name' => $from?->name,
                'to_id' => $to?->id,
                'to_name' => $to?->name,
            ],
            'created_at' => now(),
        ]);
    }
}
