<?php

namespace App\Actions\Telegram;

use App\Enums\ActivityType;
use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Telegram\Bot\LinkPhoneSignal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Staff confirm that a bot account really belongs to its linked customer (plan
 * 21 A4, T21-14, T21-15, T21-25). Under the row lock: policy "verifyLink" (view
 * plus Gate update on that customer), then registered and linked, which a policy
 * cannot refuse for an Admin. The update is conditional on the customer just
 * read, and the rows it writes hold ids only. account_changed stays as it is
 * and is recorded in the Activity meta with the phone signal (T21-36), so a
 * later reader can see what the person had in front of them.
 */
class VerifyConversationLink
{
    /**
     * @return bool whether the link became verified
     *
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function handle(TelegramUser $conversation, User $actor, ?string $ip): bool
    {
        return DB::transaction(function () use ($conversation, $actor, $ip): bool {
            $locked = TelegramUser::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('verifyLink', $locked);

            if (! $locked->isRegistered()) {
                throw new InboxRefused('unregistered');
            }

            if ($locked->customer_id === null) {
                throw new InboxRefused('no_customer');
            }

            $changed = TelegramUser::query()
                ->whereKey($locked->id)
                ->where('customer_id', $locked->customer_id)
                ->whereNull('link_verified_at')
                ->update(['link_verified_at' => now(), 'link_verified_by_id' => $actor->id]);

            if ($changed !== 1) {
                return false;
            }

            $locked->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::StatusChange,
                'meta' => [
                    'action' => 'verify_link',
                    'customer_id' => $locked->customer_id,
                    'contact_id' => $locked->contact_id,
                    'account_changed' => $locked->account_changed,
                    'phone_signal' => $locked->customer !== null ? LinkPhoneSignal::for($locked->phone, $locked->customer, $locked->contact) : LinkPhoneSignal::NO_MATCH,
                ],
                'created_at' => now(),
            ]);

            SettingsAudit::record(SettingsArea::TelegramInbox, 'telegram.conversation.'.$locked->id, AuditAction::Verified, $actor, $ip);

            return true;
        });
    }
}
