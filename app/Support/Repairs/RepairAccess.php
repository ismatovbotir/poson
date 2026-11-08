<?php

namespace App\Support\Repairs;

use App\Models\Repair;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one record rule for repairs (plan 24 P24-19). Repair::isVisibleTo() /
 * isEditableBy() (RepairPolicy) and Repair::scopeVisibleTo() / scopeEditableBy()
 * (lists, panels, search, the receipt) all go through the levels computed
 * here, so a list and a direct URL cannot disagree.
 *
 *  - view: repairs.viewAny + repairs.view; with repairs.view-all every repair
 *    (sales manager, read-only; Admin), else own + unassigned.
 *  - edit: repairs.update; own + unassigned, every repair for whoever may
 *    assign engineers (repairs.assign, Admin).
 *
 * Admins pass every can() through Gate::before, so they land on "all".
 */
final class RepairAccess
{
    public const NONE = 'none';

    public const OWN = 'own';

    public const ALL = 'all';

    public static function viewLevel(User $user): string
    {
        if (! $user->can('repairs.viewAny') || ! $user->can('repairs.view')) {
            return self::NONE;
        }

        return $user->can('repairs.view-all') ? self::ALL : self::OWN;
    }

    public static function editLevel(User $user): string
    {
        $view = self::viewLevel($user);

        if ($view === self::NONE || ! $user->can('repairs.update')) {
            return self::NONE;
        }

        // Never wider than what the user can see.
        return $user->can('repairs.assign') && $view === self::ALL ? self::ALL : self::OWN;
    }

    public static function allows(string $level, ?int $assignedToId, User $user): bool
    {
        return match ($level) {
            self::ALL => true,
            self::OWN => $assignedToId === null || $assignedToId === $user->id,
            default => false,
        };
    }

    /**
     * @param  Builder<Repair>  $query
     */
    public static function constrain(Builder $query, string $level, User $user): void
    {
        match ($level) {
            self::ALL => null,
            self::OWN => $query->where(fn (Builder $inner) => $inner->whereNull('repairs.assigned_to_id')->orWhere('repairs.assigned_to_id', $user->id)),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
