<?php

namespace App\Actions\Chat\Web;

use App\Actions\Leads\CreateLead;
use App\Actions\Telegram\InboxRefused;
use App\Data\Leads\LeadData;
use App\Enums\ChatAuthor;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\User;
use App\Models\WebChat;
use App\Models\WebChatMessage;
use App\Support\Chat\WebChatVersion;
use App\Support\Phone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Staff turn a website chat into a CRM lead (plan 28 C5 §19.3, CB-21, WC-9;
 * mirrors ConvertTelegramToLead, T21-10/19/25).
 *
 * Under the web_chats row lock: policy "convert" (WebChatPolicy, CB-21
 * scope) plus LeadPolicy::create for the type; then what a policy can't
 * refuse for an Admin: not banned, a type staff may pick, a usable phone.
 * Name and phone come from the chat row (what the visitor gave), never from
 * the caller; without a phone the convert is refused. The visitor's
 * identity is never verified, so the lead never gets a customer: no phone
 * match (matchCustomer false) and no customer_id, and no manager follows
 * from a customer (WC-9). One lead per chat: a chat that already has one
 * creates nothing (null). The selected messages (visitor messages of this
 * chat only, stored redacted) become the lead text. The per-user limit is
 * hit before the transaction (Admin exempt; WC-3: a rollback refunds nothing). LeadCreated fires
 * once after commit (CreateLead).
 */
class ConvertWebChatToLead
{
    public const MAX_MESSAGES = 50;

    public const PER_HOUR = 30;

    public const LIMIT_KEY = 'web-chat-convert:';

    public const TYPES = [LeadType::Quote, LeadType::Callback, LeadType::ServiceRequest];

    public const SEPARATOR = "\n\n";

    public function __construct(protected CreateLead $createLead) {}

    /**
     * @param  array<array-key, mixed>  $messageIds  Untrusted: checked here, then loaded through this chat
     *
     * @throws AuthorizationException
     * @throws InboxRefused
     */
    public function handle(WebChat $chat, User $actor, LeadType $type, array $messageIds = []): ?Lead
    {
        Gate::forUser($actor)->authorize('convert', $chat);

        if (! $actor->hasRole(Role::Admin) && RateLimiter::hit(self::LIMIT_KEY.$actor->id, 3600) > self::PER_HOUR) {
            throw new InboxRefused('too_many_actions');
        }

        return DB::transaction(function () use ($chat, $actor, $type, $messageIds): ?Lead {
            $locked = WebChat::query()->whereKey($chat->getKey())->lockForUpdate()->firstOrFail();

            Gate::forUser($actor)->authorize('convert', $locked);
            Gate::forUser($actor)->authorize('create', [Lead::class, $type]);

            if ($locked->isBanned()) {
                throw new InboxRefused('banned');
            }

            if (! in_array($type, self::TYPES, true)) {
                throw new InboxRefused('invalid_type');
            }

            if ($locked->lead_id !== null) {
                return null;
            }

            $phone = Phone::normalizeAny($locked->phone);

            if ($phone === null) {
                throw new InboxRefused('no_phone');
            }

            $ids = $this->ids($messageIds);
            $messages = $ids === [] ? collect() : WebChatMessage::query()
                ->where('web_chat_id', $locked->id)
                ->where('author', ChatAuthor::Visitor)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->get(['id', 'text']);

            if ($messages->count() !== count($ids)) {
                throw new InboxRefused('invalid_selection');
            }

            $text = $messages->pluck('text')->map(fn (string $line): string => trim($line))->filter()->implode(self::SEPARATOR);

            if (mb_strlen($text) > CreateLead::MESSAGE_LIMIT) {
                throw new InboxRefused('text_too_long');
            }

            $lead = $this->createLead->handle(new LeadData(
                source: LeadSource::Site,
                type: $type,
                name: filled($locked->name) ? (string) $locked->name : __('telegram_inbox.unnamed'),
                phone: $phone,
                locale: $locked->locale,
                message: $text === '' ? null : $text,
                managerId: $this->managerId($locked, $actor, $type),
                createdById: $actor->id,
                matchCustomer: false,
                customerId: null,
            ));

            if ($ids !== []) {
                WebChatMessage::query()->whereKey($ids)->whereNull('lead_id')->update(['lead_id' => $lead->id]);
            }

            $locked->forceFill(['lead_id' => $lead->id])->save();
            WebChatVersion::bump($locked->id);

            return $lead;
        });
    }

    /**
     * @param  array<array-key, mixed>  $messageIds
     * @return list<int>
     *
     * @throws InboxRefused
     */
    private function ids(array $messageIds): array
    {
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
     * The chat's manager when they can work with this type; otherwise the
     * actor, except a supervisor or Admin, whose lead stays unassigned.
     */
    private function managerId(WebChat $chat, User $actor, LeadType $type): ?int
    {
        $owner = $chat->manager_id !== null
            ? User::query()->whereKey($chat->manager_id)->where('is_active', true)->first()
            : null;

        if ($owner instanceof User && $owner->can($type->workPermission())) {
            return $owner->id;
        }

        return $actor->can('leads.view-all') ? null : $actor->id;
    }
}
