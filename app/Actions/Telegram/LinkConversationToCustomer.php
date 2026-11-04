<?php

namespace App\Actions\Telegram;

use App\Enums\ActivityType;
use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\SettingsAudit;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Telegram\Bot\LinkPhoneSignal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Manually link a bot conversation to a CRM customer (plan 14 v2 §22, plan 21
 * A4, T21-14, T21-15, T21-25). For registered users the automatic match skipped,
 * for example over the 30/h customer cap.
 *
 * Under the row lock: an unlinked thread needs policy "link" (view plus Gate
 * update on the customer), a linked one policy "relink" (Admin only). Then
 * registered, which a policy cannot refuse for an Admin. A contact must belong
 * to that customer.
 *
 * Takes ids only: no phone or company comes from the client, and no CRM row is
 * written. A link is never verified here. Any change clears link_verified_* and
 * recomputes account_changed on this thread only: another thread's flag is never
 * cleared (T21-36). The Activity row holds ids and the phone signal, and the
 * SettingsAudit row the conversation id only (T21-35).
 */
class LinkConversationToCustomer
{
    /**
     * @return bool whether the link changed
     *
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function handle(TelegramUser $conversation, User $actor, int $customerId, ?int $contactId = null, ?string $ip = null): bool
    {
        return DB::transaction(function () use ($conversation, $actor, $customerId, $contactId, $ip): bool {
            $locked = TelegramUser::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('view', $locked);

            $customer = Customer::query()->find($customerId);

            if (! $customer instanceof Customer) {
                throw new InboxRefused('invalid_customer');
            }

            if ($locked->customer_id === null) {
                Gate::forUser($actor)->authorize('link', [$locked, $customer]);
            } else {
                Gate::forUser($actor)->authorize('relink', $locked);
            }

            if (! $locked->isRegistered()) {
                throw new InboxRefused('unregistered');
            }

            $contact = $this->contact($customer, $contactId);

            if ($locked->customer_id === $customer->id && $locked->contact_id === $contact?->id) {
                return false;
            }

            $fromCustomerId = $locked->customer_id;
            $fromContactId = $locked->contact_id;

            $locked->forceFill([
                'customer_id' => $customer->id,
                'contact_id' => $contact?->id,
                'link_verified_at' => null,
                'link_verified_by_id' => null,
                'account_changed' => $contact !== null && $contact->telegramUsers()->whereKeyNot($locked->id)->exists(),
            ])->save();

            $locked->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::StatusChange,
                'meta' => [
                    'action' => 'link',
                    'from_customer_id' => $fromCustomerId,
                    'from_contact_id' => $fromContactId,
                    'customer_id' => $customer->id,
                    'contact_id' => $contact?->id,
                    'phone_signal' => LinkPhoneSignal::for($locked->phone, $customer, $contact),
                ],
                'created_at' => now(),
            ]);

            SettingsAudit::record(SettingsArea::TelegramInbox, 'telegram.conversation.'.$locked->id, AuditAction::Linked, $actor, $ip);

            return true;
        });
    }

    /**
     * @throws InboxRefused
     */
    private function contact(Customer $customer, ?int $contactId): ?Contact
    {
        if ($contactId === null) {
            return null;
        }

        $contact = Contact::query()->where('customer_id', $customer->id)->find($contactId);

        if (! $contact instanceof Contact) {
            throw new InboxRefused('invalid_contact');
        }

        return $contact;
    }
}
