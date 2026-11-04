<?php

namespace App\Actions\Telegram;

use App\Enums\ConversationStatus;
use App\Enums\TelegramUserState;
use App\Models\TelegramUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily scrub of banned bot users (plan 21 T21-34). A banned row is kept for
 * the ban only: once the retention has passed (30 days unregistered, 12
 * months registered, counted from the later of coalesce(last_message_at,
 * created_at) and banned_at), the messages and activities are deleted and the
 * personal data and the inbox state are nulled / closed. telegram_id, banned_at, banned_by_id and the
 * timestamps stay, so the ban keeps blocking; the state goes back to the
 * start of registration, so a future unban registers from scratch. Threads
 * with a lead-linked message stay whole. Idempotent. No Telegram call; the
 * log holds the count only.
 */
class ScrubBannedTelegramUsers
{
    /**
     * Columns set to null.
     *
     * @var list<string>
     */
    public const NULLED = [
        'username', 'first_name', 'last_name', 'phone', 'name', 'declared_company', 'declared_inn',
        'consent_at', 'registered_at', 'customer_id', 'contact_id', 'link_verified_at', 'link_verified_by_id',
        'forward_header_message_id', 'forward_header_at', 'forward_header_chat_id',
        'locale', 'last_message_at', 'last_inbound_at', 'last_alert_at', 'closed_at', 'blocked_at', 'manager_id',
    ];

    /**
     * @return int Rows scrubbed
     */
    public function handle(): int
    {
        $count = 0;

        TelegramUser::query()
            ->whereNotNull('banned_at')
            ->whereDoesntHave('messages', fn (Builder $messages) => $messages->whereNotNull('lead_id'))
            ->where(fn (Builder $dirty) => $dirty
                ->where('state', '!=', TelegramUserState::Language->value)
                ->orWhere('conversation_status', '!=', ConversationStatus::Closed->value)
                ->orWhere('account_changed', true)
                ->orWhere('unread_count', '>', 0)
                ->orWhereHas('messages')
                ->orWhereHas('activities')
                ->orWhere(function (Builder $fields): void {
                    foreach (self::NULLED as $column) {
                        $fields->orWhereNotNull($column);
                    }
                }))
            // Scrubbed rows leave this result set, so a chunked offset would skip rows.
            ->lazyById(200)
            ->each(function (TelegramUser $candidate) use (&$count): void {
                if ($this->scrub($candidate->id)) {
                    $count++;
                }
            });

        Log::info('Banned Telegram users scrubbed', ['count' => $count]);

        return $count;
    }

    private function pastRetention(TelegramUser $user): bool
    {
        $lastSeen = $user->last_message_at ?? $user->created_at;
        $from = $user->banned_at->gt($lastSeen) ? $user->banned_at : $lastSeen;
        $limit = $user->registered_at !== null
            ? now()->subMonths(TelegramUser::KEEP_THREAD_MONTHS)
            : now()->subDays(TelegramUser::KEEP_UNREGISTERED_DAYS);

        return $from instanceof Carbon && $from->lt($limit);
    }

    /**
     * One user in a transaction: refetched under a lock and every condition
     * checked again, so an unban or a new lead link since the select is respected.
     */
    private function scrub(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $user = TelegramUser::query()->whereKey($id)->lockForUpdate()->first();

            if (! $user instanceof TelegramUser
                || ! $user->isBanned()
                || $user->messages()->whereNotNull('lead_id')->exists()
                || ! $this->pastRetention($user)) {
                return false;
            }

            $user->messages()->delete();
            $user->activities()->delete();

            $user->forceFill([
                ...array_fill_keys(self::NULLED, null),
                'state' => TelegramUserState::Language,
                'conversation_status' => ConversationStatus::Closed,
                'account_changed' => false,
                'unread_count' => 0,
            ])->save();

            return true;
        });
    }
}
