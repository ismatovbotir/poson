<?php

namespace App\Actions\Office;

use App\Enums\GroupMemberRole;
use App\Enums\GroupStatus;
use App\Enums\TelegramMessageKind;
use App\Jobs\FetchGroupAdministrators;
use App\Jobs\SendGroupMigrateAlert;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupMember;
use App\Models\TelegramGroupMessage;
use App\Support\Chat\ChatRedactor;
use App\Support\Office\OfficeLimits;
use App\Support\Office\OfficeSettings;
use App\Support\Phone;
use App\Support\Telegram\Bot\GroupUpdate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One group or supergroup update (plan 44 §2), called by
 * HandleTelegramUpdate inside its transaction right after the update_id
 * dedupe and the business branch, BEFORE any customer-bot check (OG-1):
 * nothing here touches telegram_users (read-only role hints), BotSwitch,
 * BotLimits, the registration or any send job. Nothing is sent to a group
 * and no payload field is logged (T2-4).
 *
 *  1. office.groups_enabled and office.ingest_enabled both off → nothing
 *     (the update_id dedupe is all that happened, OG-2).
 *  2. migrate_to / migrate_from_chat_id → rekey (OG-10), before anything else.
 *  3. Every other update: one cheap stub upsert (no lock, no members, no
 *     text): a new group row only within 50 new groups a day, then the seen
 *     counter, last_message_at, a refreshed title (not for ignored groups)
 *     and the bot's own status from my_chat_member.
 *  4. Only a TRACKED group goes on (locked): when the bot was kicked or left
 *     ingest stops at once; joins and leaves from service messages and
 *     chat_member; the author becomes a member with a suggested role; a
 *     sender_chat or automatic forward is counted only (the stub counter);
 *     bots are members but their messages are not stored; the text is stored
 *     encrypted (≤ 500 a day), media without a file_id and with a cleaned
 *     file name; an edit changes only a message the same author sent and
 *     that we stored; a shared contact's phone is kept on the member only
 *     when contact.user_id is the sender.
 */
class HandleGroupUpdate
{
    public const GROUPS_OFF = 'groups_off';

    public const LIMITED = 'limited';

    public const STUB = 'stub';

    public const IGNORED = 'ignored';

    public const STOPPED = 'stopped';

    public const MEMBERS = 'members';

    public const STORED = 'stored';

    public const NOTHING = 'nothing';

    public const MIGRATED = 'migrated';

    public const MIGRATE_CONFLICT = 'migrate_conflict';

    public function __construct(protected UpsertGroupMember $members) {}

    /**
     * @return self::* What happened (tests and the poll command)
     */
    public function handle(GroupUpdate $update): string
    {
        if (! OfficeSettings::groupsEnabled()) {
            return self::GROUPS_OFF;
        }

        if ($update->migrateToChatId !== null || $update->migrateFromChatId !== null) {
            return $this->migrate($update);
        }

        $group = $this->stub($update);

        if ($group === null) {
            return self::LIMITED;
        }

        if ($group->status === GroupStatus::Ignored) {
            return self::IGNORED;
        }

        if ($group->status !== GroupStatus::Tracked) {
            return self::STUB;
        }

        // The stub update above holds this row's lock and re-read status and bot_status (T7).

        if ($update->type === GroupUpdate::TYPE_BOT_MEMBER) {
            return $this->botStatus($group, $update);
        }

        // The bot was removed: nothing more is ingested until it is added again (OG-8).
        if (in_array($group->bot_status, ['left', 'kicked'], true)) {
            return self::STOPPED;
        }

        return match ($update->type) {
            GroupUpdate::TYPE_MEMBER => $this->memberChanged($group, $update),
            GroupUpdate::TYPE_EDITED => $this->edited($group, $update),
            default => $this->message($group, $update),
        };
    }

    /**
     * The cheap stub upsert (OG-2). Null over the new-group flood limit (nothing is written).
     */
    protected function stub(GroupUpdate $update): ?TelegramGroup
    {
        $group = TelegramGroup::query()->where('telegram_chat_id', $update->chatId)->lockForUpdate()->first();
        $removed = $update->type === GroupUpdate::TYPE_BOT_MEMBER && in_array($update->memberStatus, ['left', 'kicked'], true);

        if ($group === null) {
            // A bot that left an unknown group is no news; a new row only within the daily limit.
            if ($removed || ! OfficeLimits::allowNewGroup()) {
                return null;
            }

            TelegramGroup::query()->insertOrIgnore([
                'telegram_chat_id' => $update->chatId,
                'type' => $update->chatType,
                'title' => $update->title,
                'status' => GroupStatus::Untracked->value,
                'seen_count' => 0,
                'analyses_today' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $group = TelegramGroup::query()->where('telegram_chat_id', $update->chatId)->first();

            if ($group === null) {
                return null;
            }
        }

        $changes = ['updated_at' => now()];

        // An edit is not new activity.
        if ($update->type === GroupUpdate::TYPE_MESSAGE) {
            $changes['last_message_at'] = now();
        }

        if ($group->status !== GroupStatus::Ignored && $update->title !== null && $update->title !== '') {
            $changes['title'] = $update->title;
        }

        if ($update->type === GroupUpdate::TYPE_BOT_MEMBER && $update->memberStatus !== null) {
            $changes['bot_status'] = $update->memberStatus;
            $changes['bot_is_admin'] = in_array($update->memberStatus, ['administrator', 'creator'], true);
        }

        TelegramGroup::query()->whereKey($group->id)->increment('seen_count', 1, $changes);

        // The model as stored now, without another query.
        $group->forceFill(['seen_count' => $group->seen_count + 1] + $changes)->syncOriginal();

        // Plan 44 (T7, OG-12): a tracked group is re-read under the row lock just taken by the update, so an
        // Untrack or Wipe that committed meanwhile is seen and nothing is stored (one extra query).
        if ($group->status === GroupStatus::Tracked) {
            $current = TelegramGroup::query()->whereKey($group->id)->lockForUpdate()->first(['id', 'status', 'bot_status']);

            if ($current === null) {
                return null;
            }

            $group->forceFill(['status' => $current->status, 'bot_status' => $current->bot_status])->syncOriginal();
        }

        return $group;
    }

    /**
     * The bot's own status in a tracked group: kicked or left stops ingest at once; becoming an admin
     * asks for the administrator list (a read call, at most once in 10 minutes).
     */
    protected function botStatus(TelegramGroup $group, GroupUpdate $update): string
    {
        if (in_array($update->memberStatus, ['left', 'kicked'], true)) {
            $group->forceFill(['analysis_due_at' => null])->save();

            return self::STOPPED;
        }

        if ($group->bot_is_admin) {
            FetchGroupAdministrators::dispatchFor($group);
        }

        return self::NOTHING;
    }

    /**
     * chat_member: another member's status changed (only while the bot is an admin).
     */
    protected function memberChanged(TelegramGroup $group, GroupUpdate $update): string
    {
        if ($update->memberUser === null || $update->memberStatus === null) {
            return self::NOTHING;
        }

        $this->members->handle($group, $update->memberUser, $update->memberStatus);

        return self::MEMBERS;
    }

    protected function message(TelegramGroup $group, GroupUpdate $update): string
    {
        $changed = false;

        foreach ($update->newMembers as $person) {
            $this->members->handle($group, $person, 'member');
            $changed = true;
        }

        if ($update->leftMember !== null) {
            $changed = TelegramGroupMember::query()->where('telegram_group_id', $group->id)->where('telegram_id', $update->leftMember['id'])->update(['member_status' => 'left', 'updated_at' => now()]) > 0 || $changed;
        }

        // Anonymous admins and linked channels: counted by the stub counter, never stored.
        if ($update->hasSenderChat || $update->isAutomaticForward || $update->from === null || $update->messageId === null) {
            return $changed ? self::MEMBERS : self::NOTHING;
        }

        if ($update->isService()) {
            return $changed ? self::MEMBERS : self::NOTHING;
        }

        $member = $this->members->handle($group, $update->from);

        // Bots are members, their messages are not stored.
        if ($member->role === GroupMemberRole::Bot || $update->from['isBot']) {
            return self::MEMBERS;
        }

        // Only the sender's own contact: a forwarded or somebody else's contact never sets a phone.
        if ($update->kind === TelegramMessageKind::Contact && $update->contactPhone !== null && $update->contactUserId === $update->from['id']) {
            $phone = Phone::normalizeAny($update->contactPhone);

            if ($phone !== null && $phone !== '') {
                $member->forceFill(['phone' => mb_substr($phone, 0, 24)])->save();
            }
        }

        if ($update->kind === TelegramMessageKind::Other && $update->text === null) {
            return self::NOTHING;
        }

        // The group row is locked for the transaction, so this check cannot race. A duplicate id does not use the daily cap (L1).
        if (TelegramGroupMessage::query()->where('telegram_group_id', $group->id)->where('telegram_message_id', $update->messageId)->exists()) {
            return self::STORED;
        }

        if (! OfficeLimits::allowStoredGroupMessage($group->id)) {
            return self::LIMITED;
        }

        (new TelegramGroupMessage)->forceFill([
            'telegram_group_id' => $group->id,
            'member_id' => $member->id,
            'telegram_message_id' => $update->messageId,
            'kind' => $update->kind ?? TelegramMessageKind::Other,
            'text' => $update->text,
            'media' => $this->media($update),
            'media_group_id' => $update->mediaGroupId,
            'reply_to_message_id' => $update->replyToMessageId,
            'sent_at' => $this->time($update->date),
        ])->save();

        $member->increment('message_count');
        $group->forceFill(['analysis_due_at' => now()->addMinutes(OfficeSettings::debounceMinutes())])->save();

        return self::STORED;
    }

    /**
     * An edit changes only a message the SAME author sent and we stored; anything else creates nothing (OG-11).
     */
    protected function edited(TelegramGroup $group, GroupUpdate $update): string
    {
        if ($update->from === null || $update->messageId === null || $update->hasSenderChat || $update->from['isBot']) {
            return self::NOTHING;
        }

        $member = TelegramGroupMember::query()->where('telegram_group_id', $group->id)->where('telegram_id', $update->from['id'])->first();

        if (! $member instanceof TelegramGroupMember) {
            return self::NOTHING;
        }

        $message = TelegramGroupMessage::query()
            ->where('telegram_group_id', $group->id)
            ->where('telegram_message_id', $update->messageId)
            ->where('member_id', $member->id)
            ->first();

        if (! $message instanceof TelegramGroupMessage) {
            return self::NOTHING;
        }

        $message->fill([
            'kind' => $update->kind ?? TelegramMessageKind::Other,
            'text' => $update->text,
            'media' => $this->media($update),
            'edited_at' => $this->time($update->editDate),
        ])->save();

        // At most one bump a minute for edits.
        $due = now()->addMinutes(OfficeSettings::debounceMinutes());

        if ($group->analysis_due_at === null || $group->analysis_due_at->lt($due->copy()->subMinute())) {
            $group->forceFill(['analysis_due_at' => $due])->save();
        }

        return self::STORED;
    }

    /**
     * Safe media metadata: no file_id (the parser drops it), a file name cleaned like a business one.
     *
     * @return array<string, int|string>|null
     */
    protected function media(GroupUpdate $update): ?array
    {
        $media = $update->media;

        if (isset($media['file_name'])) {
            $name = ChatRedactor::clean((string) $media['file_name'], 80);

            if ($name === '' || $name === ChatRedactor::MASK) {
                unset($media['file_name']);
            } else {
                $media['file_name'] = $name;
            }
        }

        unset($media['file_id'], $media['file_unique_id']);

        return $media === [] ? null : $media;
    }

    protected function time(?int $timestamp): Carbon
    {
        return $timestamp !== null ? Carbon::createFromTimestamp($timestamp) : now();
    }

    /**
     * A group became a supergroup (OG-10): rekey the TRACKED source row to the new chat id. Both rows are
     * locked in id order; an untracked (or ignored) target stub is merged into the source, a tracked target
     * is never overwritten (counts logged, the Admin alerted). An untracked, ignored or unknown source is
     * not rekeyed (OG-10, only a tracked source moves). An Ignored TARGET row is replaced like an untracked
     * one, so its ignore decision is lost by design: the tracked source is the same group.
     */
    protected function migrate(GroupUpdate $update): string
    {
        $source = $update->migrateToChatId !== null ? $update->chatId : $update->migrateFromChatId;
        $target = $update->migrateToChatId ?? $update->chatId;

        if ($source === null || $source >= 0 || $target >= 0 || $source === $target) {
            return self::NOTHING;
        }

        // A concurrent insert of the target stub between the read and the rekey hits the unique key: read again once.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return DB::transaction(fn (): string => $this->rekey($source, $target));
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        return self::NOTHING;
    }

    protected function rekey(int $source, int $target): string
    {
        // Lock one row at a time in ascending id order (a single locking SELECT does not promise an order).
        $ids = TelegramGroup::query()->whereIn('telegram_chat_id', [$source, $target])->orderBy('id')->pluck('id');
        $rows = collect();

        foreach ($ids as $id) {
            $row = TelegramGroup::query()->whereKey($id)->lockForUpdate()->first();

            if ($row instanceof TelegramGroup) {
                $rows->push($row);
            }
        }

        $from = $rows->firstWhere('telegram_chat_id', $source);
        $to = $rows->firstWhere('telegram_chat_id', $target);

        if (! $from instanceof TelegramGroup || ! $from->isTracked()) {
            return self::NOTHING;
        }

        if ($to instanceof TelegramGroup) {
            if ($to->isTracked()) {
                $counts = [TelegramGroupMessage::query()->where('telegram_group_id', $from->id)->count(), TelegramGroupMessage::query()->where('telegram_group_id', $to->id)->count()];

                // migrate_to + migrate_from describe the same move: one log line and one alert per pair and 10 minutes.
                if (Cache::add('office:group-migrate-conflict:'.hash('sha256', min($source, $target).':'.max($source, $target)), true, now()->addMinutes(10))) {
                    Log::warning('office.group_migrate_conflict', ['source_messages' => $counts[0], 'target_messages' => $counts[1]]);
                    dispatch(new SendGroupMigrateAlert($counts[0], $counts[1]));
                }

                return self::MIGRATE_CONFLICT;
            }

            // An untracked stub holds counters only: merged into the tracked source.
            $from->seen_count += $to->seen_count;
            $to->delete();
        }

        $from->forceFill(['telegram_chat_id' => $target, 'type' => 'supergroup'])->save();

        return self::MIGRATED;
    }
}
