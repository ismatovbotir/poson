<?php

namespace App\Actions\Telegram;

use App\Enums\ActivityType;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\TelegramUser;
use App\Models\User;
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
 * recomputes account_changed, on this thread and on an account left alone on
 * the former contact. The Activity row holds ids only.
 */
class LinkConversationToCustomer
{
    /**
     * @return bool whether the link changed
     *
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function handle(TelegramUser $conversation, User $actor, int $customerId, ?int $contactId = null): bool
    {
        return DB::transaction(function () use ($conversation, $actor, $customerId, $contactId): bool {
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

            $this->recomputeFormerContact($fromContactId, $contact?->id);

            $locked->activities()->create([
                'user_id' => $actor->id,
                'type' => ActivityType::StatusChange,
                'meta' => [
                    'action' => 'link',
                    'from_customer_id' => $fromCustomerId,
                    'from_contact_id' => $fromContactId,
                    'customer_id' => $customer->id,
                    'contact_id' => $contact?->id,
                ],
                'created_at' => now(),
            ]);

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

    /**
     * An account left alone on the former contact has no second account any more.
     */
    private function recomputeFormerContact(?int $fromContactId, ?int $toContactId): void
    {
        if ($fromContactId === null || $fromContactId === $toContactId) {
            return;
        }

        $remaining = TelegramUser::query()->where('contact_id', $fromContactId)->pluck('id');

        if ($remaining->count() === 1) {
            TelegramUser::query()->whereKey($remaining->first())->update(['account_changed' => false]);
        }
    }
}
