<?php

namespace App\Actions\Telegram;

use App\Enums\ActivityType;
use App\Enums\CustomerType;
use App\Enums\LeadSource;
use App\Enums\PriceLevel;
use App\Enums\TelegramUserState;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Telegram\Bot\BotLimits;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Finish a bot registration and link it to the CRM (plan 14 v2 §7, plan 14
 * "CRM matching", conditions 6 and 7). Called by AdvanceRegistration inside
 * HandleTelegramUpdate's transaction with the telegram_users row locked (D4).
 *
 * Matching by the shared (own) phone only:
 *  1. customers.phone → that customer; its contact with the phone, else a
 *     new contact (primary only when the customer had none);
 *  2. contacts.phone → that contact and its customer; contacts.telegram is
 *     filled only when empty;
 *  3. no match → a new customer (company when a company was declared, else
 *     individual), source = telegram (explicit assignment, TG-33), the
 *     declared INN only when valid and not used by another customer, and a
 *     primary contact. Over BotLimits::CUSTOMERS_PER_HOUR no customer is
 *     created (the conversation stays unlinked for staff to link).
 *
 * - Telegram anonymous numbers (+888…) never match (TG-29): always 3, and
 *   the new customer / contact get no phone (kept on telegram_users only).
 * - The INN never links (condition 6); the declared values stay on
 *   telegram_users only. Existing CRM fields are never overwritten (7).
 * - Conversation manager = the customer's manager when active with
 *   leads.sales, else unassigned.
 * - Several Telegram accounts per contact are allowed; a second one sets
 *   account_changed (staff banner, B5).
 * - History: "created" on a new customer (meta source telegram) and a
 *   "created" entry on the conversation with how it was matched.
 *
 * Returns nothing the bot could echo: replies are identical in every case.
 */
class RegisterTelegramUser
{
    public const MATCH_CUSTOMER_PHONE = 'customer_phone';

    public const MATCH_CONTACT_PHONE = 'contact_phone';

    public const MATCH_NEW = 'new';

    public const MATCH_CAPPED = 'capped';

    public const ANONYMOUS_PREFIX = '+888';

    public function handle(TelegramUser $user): void
    {
        [$customer, $contact, $match] = $this->match($user);

        $user->forceFill([
            'state' => TelegramUserState::Registered,
            'registered_at' => $user->registered_at ?? now(),
            'customer_id' => $customer?->id,
            'contact_id' => $contact?->id,
            'manager_id' => $this->manager($customer)?->id,
            'account_changed' => $contact !== null && $contact->telegramUsers()->whereKeyNot($user->id)->exists(),
        ])->save();

        $user->activities()->create([
            'type' => ActivityType::Created,
            'meta' => array_filter(['match' => $match, 'customer_id' => $customer?->id, 'contact_id' => $contact?->id]),
            'created_at' => now(),
        ]);
    }

    public static function isAnonymousNumber(?string $phone): bool
    {
        return $phone !== null && str_starts_with($phone, self::ANONYMOUS_PREFIX);
    }

    /**
     * @return array{0: ?Customer, 1: ?Contact, 2: string}
     */
    private function match(TelegramUser $user): array
    {
        $phone = $user->phone;

        if ($phone !== null && ! self::isAnonymousNumber($phone)) {
            $customer = Customer::query()->matchingPhone($phone)->orderBy('id')->first();

            if ($customer instanceof Customer) {
                $contact = Contact::query()->where('customer_id', $customer->id)->matchingPhone($phone)->orderBy('id')->first();

                if ($contact instanceof Contact) {
                    $this->fillTelegramIfEmpty($contact, $user);
                } else {
                    $contact = $this->createContact($customer, $user, primary: ! $customer->contacts()->exists());
                }

                return [$customer, $contact, self::MATCH_CUSTOMER_PHONE];
            }

            $contact = Contact::query()->matchingPhone($phone)->with('customer')->orderBy('id')->first();

            if ($contact instanceof Contact && $contact->customer instanceof Customer) {
                $this->fillTelegramIfEmpty($contact, $user);

                return [$contact->customer, $contact, self::MATCH_CONTACT_PHONE];
            }
        }

        if (! BotLimits::allowNewCustomer()) {
            return [null, null, self::MATCH_CAPPED];
        }

        $customer = $this->createCustomer($user);

        return [$customer, $this->createContact($customer, $user, primary: true), self::MATCH_NEW];
    }

    private function createCustomer(TelegramUser $user): Customer
    {
        $company = $user->declared_company;
        $inn = $company !== null && $user->declared_inn !== null && preg_match('/^\d{9}$/', $user->declared_inn)
            && ! Customer::query()->where('inn', $user->declared_inn)->exists() ? $user->declared_inn : null;

        $customer = new Customer([
            'type' => $company !== null ? CustomerType::Company : CustomerType::Individual,
            'name' => mb_substr($company ?? (string) $user->name, 0, 255),
            'inn' => $inn,
            'phone' => $this->crmPhone($user),
            'price_level' => PriceLevel::Retail,
            'manager_id' => null,
            'locale' => $user->locale ?? config('app.locale'),
        ]);
        // TG-33: set here only, never through fillable input.
        $customer->source = LeadSource::Telegram;

        try {
            DB::transaction(fn () => $customer->save());
        } catch (UniqueConstraintViolationException) {
            // The INN was taken in the meantime: keep it on telegram_users only.
            $customer->inn = null;
            $customer->exists = false;
            $customer->save();
        }

        $customer->activities()->create([
            'type' => ActivityType::Created,
            'meta' => ['source' => LeadSource::Telegram->value],
            'created_at' => now(),
        ]);

        return $customer;
    }

    private function createContact(Customer $customer, TelegramUser $user, bool $primary): Contact
    {
        return Contact::query()->create([
            'customer_id' => $customer->id,
            'name' => mb_substr((string) $user->name, 0, 255),
            'phone' => $this->crmPhone($user),
            'telegram' => $this->username($user),
            'is_primary' => $primary,
        ]);
    }

    /**
     * The phone copied into the CRM: never a Telegram anonymous +888 number
     * (H1/TG-29: it is not a reachable phone and must never become a match
     * key); it stays on telegram_users.phone only.
     */
    private function crmPhone(TelegramUser $user): ?string
    {
        return self::isAnonymousNumber($user->phone) ? null : $user->phone;
    }

    private function fillTelegramIfEmpty(Contact $contact, TelegramUser $user): void
    {
        $username = $this->username($user);

        if ($username !== null && blank($contact->telegram)) {
            $contact->forceFill(['telegram' => $username])->save();
        }
    }

    /**
     * "@username" when the profile has a valid one (5–32 of a-z, 0-9, _).
     */
    private function username(TelegramUser $user): ?string
    {
        return $user->username !== null && preg_match('/^[A-Za-z0-9_]{5,32}$/', $user->username) ? '@'.$user->username : null;
    }

    private function manager(?Customer $customer): ?User
    {
        if ($customer?->manager_id === null) {
            return null;
        }

        $manager = User::query()->whereKey($customer->manager_id)->where('is_active', true)->first();

        return $manager?->can('leads.sales') ? $manager : null;
    }
}
