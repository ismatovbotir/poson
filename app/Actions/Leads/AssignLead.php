<?php

namespace App\Actions\Leads;

use App\Enums\ActivityType;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who works on a lead (decisions 2026-10-22). Components authorize with
 * LeadPolicy (take / release / assign) first; this action keeps the data
 * rules and writes an "assignment" activity (from / to, with the staff
 * names at that moment).
 *
 *  - take: an unassigned lead goes to the user (first one wins).
 *  - release: the user's own lead goes back to unassigned.
 *  - assign: pick a manager from candidates(): with leads.assign anyone
 *    active who works with the lead's type; with leads.reassign (sales
 *    leads only) anyone active with leads.sales. null unassigns.
 */
class AssignLead
{
    /**
     * @throws ValidationException
     */
    public function take(Lead $lead, User $actor): Lead
    {
        return DB::transaction(function () use ($lead, $actor): Lead {
            $taken = Lead::query()->whereKey($lead->id)->whereNull('manager_id')->update(['manager_id' => $actor->id, 'updated_at' => now()]);

            if ($taken === 0) {
                throw ValidationException::withMessages(['manager_id' => __('crm.leads.errors.already_assigned')]);
            }

            $lead->refresh();
            $this->log($lead, $actor, null, $actor, 'take');

            return $lead;
        });
    }

    /**
     * @throws ValidationException
     */
    public function release(Lead $lead, User $actor): Lead
    {
        return DB::transaction(function () use ($lead, $actor): Lead {
            $released = Lead::query()->whereKey($lead->id)->where('manager_id', $actor->id)->update(['manager_id' => null, 'updated_at' => now()]);

            if ($released === 0) {
                throw ValidationException::withMessages(['manager_id' => __('crm.leads.errors.not_yours')]);
            }

            $lead->refresh();
            $this->log($lead, $actor, $actor, null, 'release');

            return $lead;
        });
    }

    /**
     * @throws ValidationException
     */
    public function assign(Lead $lead, ?int $managerId, User $actor): Lead
    {
        $target = null;

        if ($managerId !== null) {
            $target = $this->candidates($lead, $actor)->firstWhere('id', $managerId);

            if (! $target instanceof User) {
                throw ValidationException::withMessages(['manager_id' => __('crm.leads.errors.manager_invalid')]);
            }
        }

        return DB::transaction(function () use ($lead, $target, $actor): Lead {
            // Re-read under a row lock so the logged "from" is the real previous manager.
            $currentId = Lead::query()->whereKey($lead->id)->lockForUpdate()->value('manager_id');
            $currentId = $currentId !== null ? (int) $currentId : null;

            if ($currentId === $target?->id) {
                $lead->manager_id = $currentId;

                return $lead;
            }

            $previous = $currentId !== null ? User::query()->find($currentId) : null;

            $lead->manager_id = $target?->id;
            $lead->save();

            $this->log($lead, $actor, $previous, $target, 'assign');

            return $lead;
        });
    }

    /**
     * People the actor may give this lead to (ordered by name).
     *
     * @return Collection<int, User>
     */
    public function candidates(Lead $lead, User $actor): Collection
    {
        $permission = match (true) {
            $actor->can('leads.assign') => $lead->type->workPermission(),
            $actor->can('leads.reassign') && $lead->type->isSales() => 'leads.sales',
            default => null,
        };

        if ($permission === null) {
            return new Collection;
        }

        return User::query()
            ->permission($permission)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function log(Lead $lead, User $actor, ?User $from, ?User $to, string $action): void
    {
        $lead->activities()->create([
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
