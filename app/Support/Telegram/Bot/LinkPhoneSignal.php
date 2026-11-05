<?php

namespace App\Support\Telegram\Bot;

use App\Actions\Telegram\RegisterTelegramUser;
use App\Models\Contact;
use App\Models\Customer;
use App\Support\Phone;

/**
 * Does the phone a bot user shared match the CRM record staff are linking or
 * verifying (plan 21 T21-36)? Computed on the server only. The screen shows the
 * result, never the customer's phone, and the Activity meta records it.
 *
 * Anonymous Telegram numbers (+888) cannot prove anything, so they are never a match.
 */
final class LinkPhoneSignal
{
    public const MATCHES = 'matches';

    /**
     * The phone belongs to this customer, but not to the chosen contact.
     */
    public const MATCHES_OTHER = 'matches_other';

    /**
     * Lang key under telegram_inbox.crm.phone_signal when no contact is chosen:
     * the phone is then on one of the customer's contacts. The recorded value stays MATCHES_OTHER.
     */
    private const MATCHES_OTHER_NO_CONTACT = 'matches_other_no_contact';

    public const NO_MATCH = 'no_match';

    public const ANONYMOUS = 'anonymous';

    /**
     * The lang key that is true for this signal and this choice of contact.
     */
    public static function labelKey(string $signal, bool $contactChosen): string
    {
        return $signal === self::MATCHES_OTHER && ! $contactChosen ? self::MATCHES_OTHER_NO_CONTACT : $signal;
    }

    public static function for(?string $phone, Customer $customer, ?Contact $contact = null): string
    {
        if (RegisterTelegramUser::isAnonymousNumber($phone)) {
            return self::ANONYMOUS;
        }

        $normalized = Phone::normalizeAny($phone);

        if ($normalized === null) {
            return self::NO_MATCH;
        }

        $onCustomer = Phone::normalizeAny($customer->phone) === $normalized;

        if ($contact !== null ? Phone::normalizeAny($contact->phone) === $normalized : $onCustomer) {
            return self::MATCHES;
        }

        return $onCustomer || Contact::query()->where('customer_id', $customer->id)->matchingPhone($normalized)->exists()
            ? self::MATCHES_OTHER
            : self::NO_MATCH;
    }
}
