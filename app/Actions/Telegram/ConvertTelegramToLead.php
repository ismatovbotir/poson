<?php

namespace App\Actions\Telegram;

use App\Actions\Leads\CreateLead;
use App\Data\Leads\LeadData;
use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Enums\Role;
use App\Enums\TelegramDirection;
use App\Models\Lead;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Support\Phone;
use App\Support\Telegram\Bot\BotText;
use App\Support\Telegram\Bot\LinkPhoneSignal;
use App\Support\Telegram\InboxMessage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Turn selected customer messages of one conversation into a CRM lead (plan 14
 * v2 §14 B6, plan 21 B2, T21-10, T21-19, T21-25).
 *
 * Under the conversation row lock, then the message row locks: policy "convert"
 * plus LeadPolicy::create for the type, so an actor without the type is refused
 * rather than leaving an unassigned lead (Q7). Then what a policy cannot refuse
 * for an Admin: registered, not banned (T21-40), a type staff may pick, a usable
 * phone. An anonymous +888 number is refused, because a lead needs a phone and
 * that one never goes to the CRM (TG-29).
 *
 * Only ids and a type come from the caller. Every id must be an inbound message
 * of this thread with text. Any message already on a lead links nothing and
 * creates nothing, which is what a second or concurrent attempt finds. A selection
 * longer than the lead keeps is refused, never cut. The per-user limit is hit last,
 * so a refused call uses nothing (Admin exempt).
 *
 * The lead's phone and name come from the thread, its customer only from the
 * thread's link (never a phone match, so an unlinked thread gets no customer),
 * and only a verified link or one whose phone signal agrees (T21-39): otherwise a
 * stranger's lead could reach a real customer's quote, Dealer prices and PDF.
 * Leads have no contact column, so the linked contact is recorded in the
 * Activity meta. The text is cleaned message text only: no media parameters.
 * CreateLead raises LeadCreated once, after this transaction commits.
 *
 * The Activity row holds ids and flags only, never text.
 */
class ConvertTelegramToLead
{
    public const MAX_MESSAGES = 50;

    public const PER_HOUR = 30;

    public const LIMIT_KEY = 'telegram-convert:';

    /**
     * The types staff may pick, as on the lead form: checkout comes from the site only.
     */
    public const TYPES = [LeadType::Quote, LeadType::Callback, LeadType::ServiceRequest];

    public const SEPARATOR = "\n\n";

    public function __construct(protected CreateLead $createLead) {}

    /**
     * @param  array<array-key, mixed>  $messageIds  Untrusted: checked here, then loaded through this thread
     * @return Lead|null the new lead, or null when a selected message is already on a lead
     *
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function handle(TelegramUser $conversation, User $actor, array $messageIds, LeadType $type): ?Lead
    {
        return DB::transaction(function () use ($conversation, $actor, $messageIds, $type): ?Lead {
            $locked = TelegramUser::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('convert', $locked);
            Gate::forUser($actor)->authorize('create', [Lead::class, $type]);

            if (! $locked->isRegistered()) {
                throw new InboxRefused('unregistered');
            }

            if ($locked->isBanned()) {
                throw new InboxRefused('banned');
            }

            if (! in_array($type, self::TYPES, true)) {
                throw new InboxRefused('invalid_type');
            }

            $phone = $this->phone($locked);
            $ids = $this->ids($messageIds);

            $messages = TelegramMessage::query()
                ->where('telegram_user_id', $locked->id)
                ->where('direction', TelegramDirection::In)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'text', 'lead_id']);

            if ($messages->count() !== count($ids)) {
                throw new InboxRefused('invalid_selection');
            }

            if ($messages->contains(fn (TelegramMessage $message): bool => $message->lead_id !== null)) {
                return null;
            }

            $text = $messages->map(fn (TelegramMessage $message): string => $this->text($message))->implode(self::SEPARATOR);

            if (mb_strlen($text) > CreateLead::MESSAGE_LIMIT) {
                throw new InboxRefused('text_too_long');
            }

            $managerId = $this->managerId($locked, $actor, $type);
            [$attachCustomer, $signal] = self::customerDecision($locked);

            // Admins are exempt. Atomic: the hit itself decides, after every refusal above.
            $limited = ! $actor->hasRole(Role::Admin);

            if ($limited && RateLimiter::hit(self::LIMIT_KEY.$actor->id, 3600) > self::PER_HOUR) {
                throw new InboxRefused('too_many_actions');
            }

            try {
                $lead = $this->createLead->handle(new LeadData(
                    source: LeadSource::Telegram,
                    type: $type,
                    name: $this->name($locked),
                    phone: $phone,
                    locale: $locked->locale ?? (string) config('app.locale'),
                    company: $locked->declared_company,
                    message: $text,
                    managerId: $managerId,
                    createdById: $actor->id,
                    matchCustomer: false,
                    customerId: $attachCustomer ? $locked->customer_id : null,
                ));

                // No timestamps: updated_at dates a stuck delivery or file copy, and this is neither.
                TelegramMessage::query()->whereKey($ids)->whereNull('lead_id')->toBase()->update(['lead_id' => $lead->id]);

                $locked->activities()->create([
                    'user_id' => $actor->id,
                    'type' => ActivityType::StatusChange,
                    'meta' => [
                        'action' => 'convert',
                        'lead_id' => $lead->id,
                        'lead_type' => $type->value,
                        'message_ids' => $ids,
                        'customer_id' => $locked->customer_id,
                        'contact_id' => $locked->contact_id,
                        'customer_attached' => $attachCustomer,
                        'phone_signal' => $signal,
                        'link_verified' => $locked->isLinkVerified(),
                        'account_changed' => $locked->account_changed,
                    ],
                    'created_at' => now(),
                ]);
            } catch (Throwable $failed) {
                if ($limited) {
                    RateLimiter::decrement(self::LIMIT_KEY.$actor->id);
                }

                throw $failed;
            }

            return $lead;
        });
    }

    /**
     * Whether the lead may carry the thread's linked customer (T21-39), and the
     * phone signal behind that. Reads the customer and contact relations, so it
     * queries: only under the action's lock, or while the control is open.
     *
     * @return array{0: bool, 1: ?string} attach, phone signal (null when unlinked)
     */
    public static function customerDecision(TelegramUser $conversation): array
    {
        $customer = $conversation->customer;

        if ($customer === null) {
            return [false, null];
        }

        $signal = LinkPhoneSignal::for($conversation->phone, $customer, $conversation->contact);

        return [
            $conversation->isLinkVerified() || in_array($signal, [LinkPhoneSignal::MATCHES, LinkPhoneSignal::MATCHES_OTHER], true),
            $signal,
        ];
    }

    /**
     * Message text as the lead keeps it. Never cut: an over-long selection is refused.
     */
    public static function cleanText(?string $text): string
    {
        return BotText::caption($text, PHP_INT_MAX);
    }

    /**
     * Whether this conversation has a phone a lead can carry (shown on the screen too).
     */
    public static function hasUsablePhone(TelegramUser $conversation): bool
    {
        return ! RegisterTelegramUser::isAnonymousNumber($conversation->phone)
            && Phone::normalizeAny($conversation->phone) !== null;
    }

    /**
     * @throws InboxRefused
     */
    private function phone(TelegramUser $conversation): string
    {
        if (RegisterTelegramUser::isAnonymousNumber($conversation->phone)) {
            throw new InboxRefused('anonymous_number');
        }

        $phone = Phone::normalizeAny($conversation->phone);

        if ($phone === null) {
            throw new InboxRefused('no_phone');
        }

        return $phone;
    }

    /**
     * Positive whole numbers only, capped before anything reaches a query.
     *
     * @param  array<array-key, mixed>  $messageIds
     * @return list<int>
     *
     * @throws InboxRefused
     */
    private function ids(array $messageIds): array
    {
        if ($messageIds === []) {
            throw new InboxRefused('nothing_selected');
        }

        if (count($messageIds) > self::MAX_MESSAGES) {
            throw new InboxRefused('too_many_selected');
        }

        $ids = [];

        foreach ($messageIds as $id) {
            $valid = is_int($id) ? $id > 0 : is_string($id) && preg_match('/^[1-9]\d{0,17}$/D', $id) === 1;

            if (! $valid) {
                throw new InboxRefused('invalid_selection');
            }

            $ids[(int) $id] = (int) $id;
        }

        return array_values($ids);
    }

    /**
     * The same cleaning as other inbound text kept for display. A lead is built
     * from text, so a message with none is refused rather than linked silently.
     *
     * @throws InboxRefused
     */
    private function text(TelegramMessage $message): string
    {
        $text = self::cleanText($message->text);

        if ($text === '') {
            throw new InboxRefused('no_text');
        }

        return $text;
    }

    private function name(TelegramUser $conversation): string
    {
        $name = InboxMessage::displayName($conversation);

        return $name !== '' ? $name : __('telegram_inbox.unnamed');
    }

    /**
     * The thread's manager when they can work with this type. Otherwise the
     * actor, so a manager keeps the lead they made, except a supervisor or
     * Admin, whose lead stays unassigned for the team (like an Admin's reply).
     */
    private function managerId(TelegramUser $conversation, User $actor, LeadType $type): ?int
    {
        $owner = $conversation->manager_id !== null
            ? User::query()->whereKey($conversation->manager_id)->where('is_active', true)->first()
            : null;

        if ($owner instanceof User && $owner->can($type->workPermission())) {
            return $owner->id;
        }

        return $actor->can('leads.view-all') ? null : $actor->id;
    }
}
