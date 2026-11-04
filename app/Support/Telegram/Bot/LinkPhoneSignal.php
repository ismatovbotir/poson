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

    public const NO_MATCH = 'no_match';

    public const ANONYMOUS = 'anonymous';

    public static function for(?string $phone, Customer $customer, ?Contact $contact = null): string
    {
        if (RegisterTelegramUser::isAnonymousNumber($phone)) {
            return self::ANONYMOUS;
        }

        $normalized = Phone::normalizeAny($phone);

        if ($normalized === null) {
            return self::NO_MATCH;
        }

        return in_array($normalized, [Phone::normalizeAny($customer->phone), Phone::normalizeAny($contact?->phone)], true)
            ? self::MATCHES
            : self::NO_MATCH;
    }
}
