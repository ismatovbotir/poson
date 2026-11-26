<?php

namespace App\Actions\Office;

use App\Enums\AuditAction;
use App\Enums\GroupMemberRole;
use App\Enums\SettingsArea;
use App\Enums\TelegramParty;
use App\Models\Customer;
use App\Models\SettingsAudit;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupMember;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Bulk "Set role" of group members (plan 44 §2, OG-7, office.mark = Admin
 * only), tracked groups only:
 *
 *  - roles: employee (optional active office user, one member only),
 *    client (optional customer) or unknown. "bot" can neither be set nor
 *    removed: a selection that holds a bot is refused as a whole;
 *  - the member ids are re-queried scoped to THIS group: any id that is not
 *    a member of it (a tampered id) refuses the whole request, nothing is
 *    written;
 *  - each member gets role, links and role_set_by / role_set_at; a linked
 *    bot user (same Telegram id) gets the party (and staff account) back as
 *    MarkBusinessChat does, and every such global write is audited
 *    (office.telegram_user.{id}.party). Role "unknown" never clears a party.
 */
class SetGroupMemberRoles
{
    public const MAX_MEMBERS = 200;

    /**
     * @param  list<int|string>  $memberIds
     * @return int Members changed
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, TelegramGroup $group, array $memberIds, GroupMemberRole $role, ?int $staffUserId = null, ?int $customerId = null, ?string $ip = null): int
    {
        if (! Gate::forUser($actor)->allows('mark', $group)) {
            throw new AuthorizationException;
        }

        if ($role === GroupMemberRole::Bot) {
            throw ValidationException::withMessages(['role' => __('office.groups.errors.bot_role')]);
        }

        $ids = collect($memberIds)
            ->map(fn (mixed $id): int|false => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))
            ->unique()
            ->values();

        if ($ids->isEmpty() || $ids->contains(false) || $ids->count() > self::MAX_MEMBERS) {
            throw ValidationException::withMessages(['members' => __('office.groups.errors.members_invalid')]);
        }

        if ($staffUserId !== null && ($role !== GroupMemberRole::Employee || $ids->count() > 1)) {
            throw ValidationException::withMessages(['staffUserId' => __('office.groups.errors.staff_single')]);
        }

        $staff = $staffUserId !== null ? OfficeStaff::requireAssignable($staffUserId, 'staffUserId') : null;

        if ($customerId !== null) {
            if ($role !== GroupMemberRole::Client || ! Customer::query()->whereKey($customerId)->exists()) {
                throw ValidationException::withMessages(['customerId' => __('office.groups.errors.customer_invalid')]);
            }
        }

        return DB::transaction(function () use ($actor, $group, $ids, $role, $staff, $customerId, $ip): int {
            $locked = TelegramGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isTracked()) {
                throw ValidationException::withMessages(['group' => __('office.groups.errors.not_tracked')]);
            }

            $members = TelegramGroupMember::query()
                ->where('telegram_group_id', $locked->id)
                ->whereIn('id', $ids->all())
                ->lockForUpdate()
                ->get();

            if ($members->count() !== $ids->count()) {
                throw ValidationException::withMessages(['members' => __('office.groups.errors.members_invalid')]);
            }

            if ($members->contains(fn (TelegramGroupMember $member): bool => $member->is_bot || $member->role === GroupMemberRole::Bot)) {
                throw ValidationException::withMessages(['role' => __('office.groups.errors.bot_role')]);
            }

            $botUsers = TelegramUser::query()->whereIn('telegram_id', $members->pluck('telegram_id')->all())->get()->keyBy('telegram_id');
            $party = match ($role) {
                GroupMemberRole::Employee => TelegramParty::Employee,
                GroupMemberRole::Client => TelegramParty::Client,
                default => null,
            };

            foreach ($members as $member) {
                $botUser = $botUsers->get($member->telegram_id);

                $member->forceFill([
                    'role' => $role,
                    'staff_user_id' => $role === GroupMemberRole::Employee ? $staff?->id : null,
                    'customer_id' => $role === GroupMemberRole::Client ? $customerId : null,
                    'telegram_user_id' => $botUser?->id ?? $member->telegram_user_id,
                    'role_set_by_id' => $actor->id,
                    'role_set_at' => now(),
                ])->save();

                if ($botUser instanceof TelegramUser && $party !== null) {
                    $this->writeParty($actor, $botUser, $party, $staff, $ip);
                }
            }

            SettingsAudit::record(SettingsArea::Office, 'office.group.'.$locked->id.'.roles.'.$role->value.'.'.$members->count(), AuditAction::RolesSet, $actor, $ip);

            return $members->count();
        });
    }

    /**
     * The global bot-user party write-back, audited per user (never a name).
     */
    protected function writeParty(User $actor, TelegramUser $botUser, TelegramParty $party, ?User $staff, ?string $ip): void
    {
        $staffId = $party === TelegramParty::Employee ? ($staff?->id ?? $botUser->staff_user_id) : null;

        if ($botUser->party === $party && $botUser->staff_user_id === $staffId) {
            return;
        }

        $botUser->forceFill(['party' => $party, 'staff_user_id' => $staffId])->save();

        SettingsAudit::record(SettingsArea::Office, 'office.telegram_user.'.$botUser->id.'.party', AuditAction::Marked, $actor, $ip);
    }
}
