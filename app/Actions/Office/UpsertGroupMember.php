<?php

namespace App\Actions\Office;

use App\Enums\BusinessChatKind;
use App\Enums\GroupMemberRole;
use App\Enums\TelegramParty;
use App\Models\BusinessChat;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupMember;
use App\Models\TelegramUser;
use App\Support\Office\OfficeSettings;
use Illuminate\Support\Carbon;

/**
 * One member row of a TRACKED group (plan 44 §2): created on the first
 * sighting (message, join, chat_member, administrator list) with a
 * suggested role, refreshed afterwards (name, @username, status, last seen).
 * Never called for an untracked group (OG-2).
 *
 * Role suggestion (§2 Roles, only on creation; staff set the real role,
 * which is what role_set_at / role_set_by_id mark): bots are "bot"; an
 * allowed owner or a bot user with party employee → employee; party client
 * or a business chat marked client → client; everyone else → unknown. An
 * existing member that turns out to be a bot becomes "bot" and stays so.
 */
class UpsertGroupMember
{
    /**
     * Telegram status → member_status (≤ 12 characters).
     */
    private const STATUSES = ['creator' => 'creator', 'administrator' => 'admin', 'member' => 'member', 'restricted' => 'restricted', 'left' => 'left', 'kicked' => 'kicked'];

    /**
     * @param  array{id: int, isBot: bool, firstName: ?string, username: ?string}  $person
     */
    public function handle(TelegramGroup $group, array $person, ?string $telegramStatus = null): TelegramGroupMember
    {
        $now = Carbon::now();
        $status = $telegramStatus !== null ? (self::STATUSES[$telegramStatus] ?? null) : null;

        $find = fn (): ?TelegramGroupMember => TelegramGroupMember::query()->where('telegram_group_id', $group->id)->where('telegram_id', $person['id'])->lockForUpdate()->first();
        $member = $find();

        // The role is suggested (and its lookups run) only for a new row.
        if ($member === null) {
            TelegramGroupMember::query()->insertOrIgnore([
                'telegram_group_id' => $group->id,
                'telegram_id' => $person['id'],
                'role' => $this->suggestRole($person['id'], $person['isBot'])->value,
                'is_bot' => $person['isBot'],
                'member_status' => $status ?? 'member',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'message_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $member = $find();
        }

        if (! $member instanceof TelegramGroupMember) {
            throw new \RuntimeException('Group member row missing after insert');
        }

        $member->fill(['last_seen_at' => $now, 'is_bot' => $member->is_bot || $person['isBot']]);

        if ($person['firstName'] !== null) {
            $member->first_name = $person['firstName'];
        }

        if ($person['username'] !== null) {
            $member->username = $person['username'];
        }

        if ($status !== null) {
            $member->member_status = $status;
        } elseif (in_array($member->member_status, ['left', 'kicked'], true)) {
            // Speaking again (no explicit status): a stale left / kicked mark is cleared.
            $member->member_status = 'member';
        }

        // A bot is always a bot (staff cannot change it).
        if ($member->is_bot && $member->role !== GroupMemberRole::Bot) {
            $member->forceFill(['role' => GroupMemberRole::Bot]);
        }

        $member->save();

        return $member;
    }

    protected function suggestRole(int $telegramId, bool $isBot): GroupMemberRole
    {
        if ($isBot) {
            return GroupMemberRole::Bot;
        }

        if (OfficeSettings::isOwner($telegramId)) {
            return GroupMemberRole::Employee;
        }

        $party = TelegramUser::query()->where('telegram_id', $telegramId)->value('party');
        $party = $party instanceof TelegramParty ? $party : TelegramParty::tryFrom((string) $party);

        if ($party === TelegramParty::Employee) {
            return GroupMemberRole::Employee;
        }

        if ($party === TelegramParty::Client
            || BusinessChat::query()->where('telegram_chat_id', $telegramId)->where('kind', BusinessChatKind::Client->value)->exists()) {
            return GroupMemberRole::Client;
        }

        return GroupMemberRole::Unknown;
    }
}
