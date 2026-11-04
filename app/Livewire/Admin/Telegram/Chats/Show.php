<?php

namespace App\Livewire\Admin\Telegram\Chats;

use App\Actions\Leads\CreateLead;
use App\Actions\Telegram\AssignConversation;
use App\Actions\Telegram\ChangeConversationStatus;
use App\Actions\Telegram\ConvertTelegramToLead;
use App\Actions\Telegram\DeleteConversation;
use App\Actions\Telegram\InboxRefused;
use App\Actions\Telegram\LinkConversationToCustomer;
use App\Actions\Telegram\MarkConversationRead;
use App\Actions\Telegram\ResendTelegramMessage;
use App\Actions\Telegram\RetryForward;
use App\Actions\Telegram\SendTelegramReply;
use App\Actions\Telegram\SetConversationBan;
use App\Actions\Telegram\VerifyConversationLink;
use App\Enums\ActivityType;
use App\Enums\ConversationStatus;
use App\Enums\LeadType;
use App\Enums\TelegramDirection;
use App\Jobs\DeleteForwardedCopies;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\Bot\InboxChat;
use App\Support\Telegram\Bot\LinkPhoneSignal;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Conversation (plan 14 v2 §9, plan 21 A1 to A5, A7): the latest 50 messages with
 * "load older", a profile panel, stuck deliveries highlighted, the reply box,
 * resend of undelivered replies, assign / take, close / reopen, ban, the CRM
 * link, retry of customer files, convert to a lead and the Admin delete. Opening it marks the thread read. Writes go
 * through actions, which check policy and state again.
 *
 * The thread polls every 10 s, so the manager list, customer picker, history and
 * delete warnings are queried only while their control is open. The lead reference
 * on a message comes from its own row, with no query for the lead.
 *
 * Only the conversation id is held in state (T21-10): the row is loaded on
 * every request without telegram_id, and hydrate() authorizes "view" again,
 * so a reassignment gives 403 on the next poll. Message ids are never taken
 * from the client; messages are always read through this thread, with no
 * file_id (none is stored) and no telegram_id.
 */
#[Layout('layouts::admin')]
class Show extends Component
{
    public const PAGE_SIZE = 50;

    public const MAX_MESSAGES = 1000;

    public const LINK_RESULTS = 8;

    public const HISTORY_ROWS = 10;

    private const COLUMNS = [
        'id', 'username', 'first_name', 'last_name', 'locale', 'name', 'phone', 'declared_company', 'declared_inn',
        'consent_at', 'registered_at', 'customer_id', 'contact_id', 'link_verified_at', 'link_verified_by_id',
        'account_changed', 'conversation_status', 'manager_id', 'unread_count',
        'last_message_at', 'last_inbound_at', 'closed_at', 'blocked_at', 'banned_at', 'created_at',
    ];

    #[Locked]
    public int $conversationId = 0;

    /**
     * Whose snapshot this is: another user replaying it gets 403 before any lookup.
     * A snapshot from before this property reads as 0 and is refused, so a reload fixes it.
     */
    #[Locked]
    public int $viewerId = 0;

    #[Locked]
    public int $limit = self::PAGE_SIZE;

    public string $reply = '';

    public ?int $assignTarget = null;

    public bool $assignOpen = false;

    public bool $linkOpen = false;

    public bool $historyOpen = false;

    public bool $deleteOpen = false;

    public string $customerSearch = '';

    public ?int $linkCustomerId = null;

    public ?int $linkContactId = null;

    public bool $convertOpen = false;

    public string $convertType = LeadType::Quote->value;

    /**
     * Message ids ticked for a lead. Untrusted: ConvertTelegramToLead type-checks
     * and caps them, then loads them through this thread. Nothing here reaches a
     * query at render.
     *
     * @var array<array-key, mixed>
     */
    public array $convertSelected = [];

    /**
     * The row loaded in this request (never part of the Livewire state).
     */
    private ?TelegramUser $loaded = null;

    /**
     * The chat was deleted (another tab or person): this request only redirects.
     */
    private bool $gone = false;

    /**
     * Not a bound model on the route: Livewire binds the route again on every
     * update, which would turn a chat deleted meanwhile into a 404 and an error
     * modal before hydrate() could redirect. A missing id looks the same as a
     * foreign thread to anyone without telegram.inbox-all (403).
     */
    public function mount(TelegramUser|int|string $telegramUser, MarkConversationRead $markRead): void
    {
        $telegramUser = $telegramUser instanceof TelegramUser ? $telegramUser : TelegramUser::query()->find($telegramUser);

        if ($telegramUser === null) {
            abort($this->user()->can('telegram.inbox-all') ? 404 : 403);
        }

        $this->authorize('view', $telegramUser);
        $this->conversationId = $telegramUser->id;
        $this->viewerId = $this->user()->id;

        $markRead->handle($telegramUser, $this->user());
    }

    /**
     * A chat deleted meanwhile redirects to the list with a notice instead of the
     * error modal, and nothing is rendered. The call in this request fails on the
     * missing row before it does anything, and exception() ends it quietly.
     * Someone who lost inbox access gets 403 first, and learns nothing of the row.
     */
    public function hydrate(): void
    {
        abort_unless($this->viewerId === (int) auth()->id(), 403);

        $this->authorize('viewAny', TelegramUser::class);

        $this->loaded = TelegramUser::query()->select(self::COLUMNS)->find($this->conversationId);

        if ($this->loaded === null) {
            $this->gone = true;
            $this->skipRender();
            session()->flash('toast', ['title' => __('telegram_inbox.toasts.chat_gone'), 'type' => 'info']);
            $this->redirectRoute('admin.telegram.chats', navigate: true);

            return;
        }

        $this->authorize('view', $this->loaded);
    }

    /**
     * Livewire's exception hook. Its parameters stay untyped: Livewire resolves
     * this call through the container, and a class type here makes it recurse.
     *
     * @param  Throwable  $e
     * @param  callable  $stopPropagation
     */
    public function exception($e, $stopPropagation): void
    {
        if ($this->gone && $e instanceof ModelNotFoundException) {
            $stopPropagation();
        }
    }

    public function loadOlder(): void
    {
        $this->authorize('view', $this->conversation());
        $this->limit = min($this->limit + self::PAGE_SIZE, self::MAX_MESSAGES);
    }

    public function refreshThread(MarkConversationRead $markRead): void
    {
        $markRead->handle($this->conversation(), $this->user());
    }

    /**
     * @param  string|null  $token  Generated by the reply form per draft; a repeat is ignored
     */
    public function sendReply(?string $token = null): void
    {
        $this->authorize('reply', $this->conversation());

        $send = app(SendTelegramReply::class);
        $token = $token !== null && preg_match('/^[\w-]{1,64}$/D', $token) === 1 ? $token : null;

        // The second of two serialized submits arrives with the draft already cleared.
        if ($token !== null && trim($this->reply) === '' && $send->alreadySent($this->conversationId, $this->user(), $token)) {
            return;
        }

        $this->validate(
            ['reply' => ['required', 'string', 'max:'.SendTelegramReply::MAX_LENGTH]],
            attributes: ['reply' => __('telegram_inbox.reply.label')],
        );

        $this->attempt(function () use ($send, $token): ?string {
            $sent = $send->handle($this->conversation(), $this->user(), $this->reply, $token ?? 'text-'.sha1($this->reply));

            // Only reached when the message was sent or already had been: a refusal or error keeps the draft.
            $this->reset('reply');

            return $sent === null ? 'already_sent' : null;
        }, 'reply_queued');
    }

    public function resend(int $messageId, ResendTelegramMessage $resend): void
    {
        $this->authorize('view', $this->conversation());

        $message = TelegramMessage::query()
            ->where('telegram_user_id', $this->conversationId)
            ->where('direction', TelegramDirection::Out)
            ->findOrFail($messageId);

        $this->attempt(function () use ($resend, $message): void {
            if (! $resend->handle($message, $this->user())) {
                throw new InboxRefused('not_retryable');
            }
        }, 'resent');
    }

    public function retryForward(int $messageId, RetryForward $retry): void
    {
        $this->authorize('view', $this->conversation());

        $message = TelegramMessage::query()
            ->where('telegram_user_id', $this->conversationId)
            ->where('direction', TelegramDirection::In)
            ->findOrFail($messageId);

        $this->attempt(fn () => $retry->handle($this->user(), $message, request()->ip()), 'forward_retried');
    }

    public function assign(AssignConversation $assign): void
    {
        $this->authorize('assign', $this->conversation());

        $this->attempt(function () use ($assign): bool {
            if ($this->assignTarget === null) {
                throw new InboxRefused('invalid_target');
            }

            $changed = $assign->assign($this->conversation(), $this->user(), $this->assignTarget);
            $this->reset('assignTarget', 'assignOpen');

            return $changed;
        }, 'assigned');

        $this->leaveIfNoLongerVisible();
    }

    public function take(AssignConversation $assign): void
    {
        $this->authorize('assign', $this->conversation());

        $this->attempt(fn () => $assign->take($this->conversation(), $this->user()), 'taken');
    }

    public function close(ChangeConversationStatus $status): void
    {
        $this->authorize('close', $this->conversation());

        $this->attempt(fn () => $status->close($this->conversation(), $this->user()), 'closed');
    }

    public function reopen(ChangeConversationStatus $status): void
    {
        $this->authorize('close', $this->conversation());

        $this->attempt(fn () => $status->reopen($this->conversation(), $this->user()), 'reopened');
    }

    public function ban(SetConversationBan $bans): void
    {
        $this->authorize('ban', $this->conversation());

        $this->attempt(fn () => $bans->ban($this->conversation(), $this->user(), request()->ip()), 'banned');
    }

    public function unban(SetConversationBan $bans): void
    {
        $this->authorize('ban', $this->conversation());

        $this->attempt(fn () => $bans->unban($this->conversation(), $this->user(), request()->ip()), 'unbanned');

        $this->leaveIfNoLongerVisible();
    }

    public function verifyLink(VerifyConversationLink $verify): void
    {
        $this->authorize('view', $this->conversation());

        $this->attempt(fn () => $verify->handle($this->conversation(), $this->user(), request()->ip()), 'verified');
    }

    public function chooseCustomer(int $customerId): void
    {
        $this->linkCustomerId = $customerId;
        $this->linkContactId = null;
    }

    public function link(LinkConversationToCustomer $link): void
    {
        $this->authorize('view', $this->conversation());

        $this->attempt(function () use ($link): bool {
            if ($this->linkCustomerId === null) {
                throw new InboxRefused('invalid_customer');
            }

            $changed = $link->handle($this->conversation(), $this->user(), $this->linkCustomerId, $this->linkContactId, request()->ip());
            $this->reset('customerSearch', 'linkCustomerId', 'linkContactId', 'linkOpen');

            return $changed;
        }, 'linked');
    }

    /**
     * Open the convert control, with the message whose button was pressed ticked.
     */
    public function openConvert(?int $messageId = null): void
    {
        $this->authorize('convert', $this->conversation());

        $this->convertOpen = true;
        $this->convertType = ($this->convertTypes()[0] ?? LeadType::Quote)->value;
        $this->convertSelected = $messageId !== null ? [$messageId] : [];
    }

    public function closeConvert(): void
    {
        $this->reset('convertOpen', 'convertSelected');
    }

    public function convert(ConvertTelegramToLead $convert): void
    {
        $this->authorize('convert', $this->conversation());

        $this->attempt(function () use ($convert): ?string {
            $type = LeadType::tryFrom($this->convertType);

            if ($type === null) {
                throw new InboxRefused('invalid_type');
            }

            $lead = $convert->handle($this->conversation(), $this->user(), $this->convertSelected, $type);
            $this->reset('convertOpen', 'convertSelected');

            return $lead === null ? 'already_converted' : null;
        }, 'converted');
    }

    /**
     * Admin only, with a password confirmation from the last 15 minutes (T21-18).
     */
    public function deleteConversation(DeleteConversation $delete): void
    {
        $this->authorize('delete', $this->conversation());

        if (! PasswordConfirmation::isRecent()) {
            $this->redirect(PasswordConfirmation::url(route('admin.telegram.chats.show', $this->conversationId)), navigate: false);

            return;
        }

        $delete->handle($this->conversation(), $this->user(), request()->ip());

        session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => __('telegram_inbox.toasts.deleted'), 'type' => 'success']);
        $this->redirectRoute('admin.telegram.chats', navigate: true);
    }

    public function render(): View
    {
        $conversation = $this->conversation();

        $newestFirst = TelegramMessage::query()
            ->select([
                'id', 'telegram_user_id', 'direction', 'kind', 'text', 'media', 'user_id', 'status', 'error_code',
                'sent_at', 'forward_status', 'forward_error_code', 'lead_id', 'created_at', 'updated_at',
            ])
            ->where('telegram_user_id', $conversation->id)
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit($this->limit + 1)
            ->get();

        $hasOlder = $newestFirst->count() > $this->limit;
        $messages = $newestFirst->take($this->limit)->reverse()->values();
        $replyRefusal = app(SendTelegramReply::class)->refusal($conversation);

        return view('livewire.admin.telegram.chats.show', [
            'conversation' => $conversation->loadMissing('manager:id,name,is_active', 'customer:id,name,manager_id,phone', 'contact:id,name,phone', 'linkVerifiedBy:id,name'),
            'messages' => $messages,
            'hasOlder' => $hasOlder && $this->limit < self::MAX_MESSAGES,
            'olderHidden' => $hasOlder && $this->limit >= self::MAX_MESSAGES,
            'polling' => $this->limit === self::PAGE_SIZE,
            'replyRefusal' => $replyRefusal,
            'forwardNotConfigured' => InboxChat::id() === null,
            ...$this->linkPicker($conversation),
            ...$this->convertPanel($conversation, $messages),
            'canOpenLeads' => $this->user()->can('leads.view'),
            'candidates' => $this->assignOpen && $this->user()->can('assign', $conversation) ? app(AssignConversation::class)->candidates() : collect(),
            'history' => $this->historyOpen ? $this->history($conversation) : [],
            'deleteWarnings' => $this->deleteOpen ? $this->deleteWarnings($conversation) : [],
            'passwordConfirmed' => PasswordConfirmation::isRecent(),
        ])->title(__('telegram_inbox.show.title'));
    }

    /**
     * Run a write and report it. A write returning false changed nothing and one
     * returning a string names its own notice, so neither gets a success toast. A policy refusal stays a 403. The row is read again
     * for the render, because the write may have changed it.
     */
    private function attempt(Closure $write, string $toast): void
    {
        try {
            $result = $write();

            if ($result === false || is_string($result)) {
                $this->dispatch('toast', title: __('telegram_inbox.toasts.'.(is_string($result) ? $result : 'no_change')), type: 'info');
            } else {
                $this->dispatch('toast', title: __('admin.toast.done'), body: __('telegram_inbox.toasts.'.$toast), type: 'success');
            }
        } catch (InboxRefused $refused) {
            $this->dispatch('toast', title: $refused->translated(), type: 'error');
        }

        $this->loaded = null;
    }

    /**
     * Data for the link picker. Only customers the actor may edit are offered,
     * and a tampered chosen id finds nothing.
     *
     * @return array{canLink: bool, customerResults: Collection<int, Customer>, chosenCustomer: ?Customer, chosenContacts: Collection<int, Contact>, linkSignal: ?string, verifySignal: ?string}
     */
    private function linkPicker(TelegramUser $conversation): array
    {
        $user = $this->user();
        $canLink = $conversation->isRegistered() && ($conversation->customer_id === null
            ? $user->can('customers.update') || $user->can('customers.update-any')
            : $user->can('relink', $conversation));
        $open = $canLink && $this->linkOpen;
        $term = trim(mb_substr($this->customerSearch, 0, 100));

        $results = $open && mb_strlen($term) >= 2
            ? Customer::query()->visibleTo($user)->editableBy($user)->search($term)->orderBy('name')->limit(self::LINK_RESULTS)->get(['id', 'name', 'phone'])
            : new Collection;

        $chosen = $open && $this->linkCustomerId !== null
            ? Customer::query()->visibleTo($user)->editableBy($user)->whereKey($this->linkCustomerId)->first(['id', 'name', 'phone'])
            : null;

        $contacts = $chosen !== null
            ? Contact::query()->where('customer_id', $chosen->id)->orderBy('name')->get(['id', 'name', 'phone'])
            : new Collection;

        return [
            'canLink' => $canLink,
            'customerResults' => $results,
            'chosenCustomer' => $chosen,
            'chosenContacts' => $contacts,
            'linkSignal' => $chosen !== null ? LinkPhoneSignal::for($conversation->phone, $chosen, $contacts->firstWhere('id', $this->linkContactId)) : null,
            'verifySignal' => $conversation->customer !== null && ! $conversation->isLinkVerified() && $user->can('verifyLink', $conversation)
                ? LinkPhoneSignal::for($conversation->phone, $conversation->customer, $conversation->contact)
                : null,
        ];
    }

    /**
     * Data for the convert control. The text lengths come from the rows already
     * loaded. They and the two queries behind the notices run only while it is
     * open; closed, the per-message button needs only filled text, and the action
     * still refuses text that cleans to empty.
     * Ticks that stopped being convertible, or scrolled out of the loaded rows
     * with no checkbox left to untick, are dropped here. The action still checks
     * what the client sends.
     *
     * @param  Collection<int, TelegramMessage>  $messages
     * @return array{canConvert: bool, convertPhoneUsable: bool, convertTypes: list<LeadType>, convertEligible: bool, convertLengths: array<int, int>, convertTextLength: int, convertTextTooLong: bool, convertCustomerSkipped: bool, convertHasRecentLead: bool}
     */
    private function convertPanel(TelegramUser $conversation, Collection $messages): array
    {
        $types = $this->convertTypes();
        $canConvert = $conversation->isRegistered() && ! $conversation->isBanned() && $types !== [] && $this->user()->can('convert', $conversation);
        $phoneUsable = ConvertTelegramToLead::hasUsablePhone($conversation);
        $open = $this->convertOpen && $canConvert && $phoneUsable;
        $lengths = $open ? $this->convertLengths($messages) : [];

        if ($this->convertOpen) {
            $this->convertSelected = array_slice(array_values(array_filter(
                $this->convertSelected,
                fn (mixed $id): bool => (is_int($id) || (is_string($id) && ctype_digit($id))) && isset($lengths[(int) $id]),
            )), 0, ConvertTelegramToLead::MAX_MESSAGES);
        }

        $selected = array_unique(array_map(intval(...), $this->convertSelected));
        $length = array_sum(array_map(fn (int $id): int => $lengths[$id] ?? 0, $selected));
        $length += max(0, count($selected) - 1) * mb_strlen(ConvertTelegramToLead::SEPARATOR);

        return [
            'canConvert' => $canConvert,
            'convertPhoneUsable' => $phoneUsable,
            'convertTypes' => $open ? $types : [],
            'convertEligible' => $canConvert && $phoneUsable,
            'convertLengths' => $lengths,
            'convertTextLength' => $length,
            'convertTextTooLong' => $length > CreateLead::MESSAGE_LIMIT,
            'convertCustomerSkipped' => $open && $conversation->customer_id !== null && ! ConvertTelegramToLead::customerDecision($conversation)[0],
            'convertHasRecentLead' => $open && Lead::query()
                ->whereIn('id', TelegramMessage::query()->select('lead_id')->where('telegram_user_id', $conversation->id)->whereNotNull('lead_id'))
                ->where('created_at', '>=', now()->subDays(Lead::DUPLICATE_WINDOW_DAYS))
                ->exists(),
        ];
    }

    /**
     * Cleaned text length per message a lead can be made from: inbound, on no lead, with text.
     *
     * @param  Collection<int, TelegramMessage>  $messages
     * @return array<int, int>
     */
    private function convertLengths(Collection $messages): array
    {
        $lengths = [];

        foreach ($messages as $message) {
            if ($message->direction !== TelegramDirection::In || $message->lead_id !== null) {
                continue;
            }

            $length = mb_strlen(ConvertTelegramToLead::cleanText($message->text));

            if ($length > 0) {
                $lengths[$message->id] = $length;
            }
        }

        return $lengths;
    }

    /**
     * The lead types this actor may create from a chat.
     *
     * @return list<LeadType>
     */
    private function convertTypes(): array
    {
        return array_values(array_filter(
            ConvertTelegramToLead::TYPES,
            fn (LeadType $type): bool => $this->user()->can('create', [Lead::class, $type]),
        ));
    }

    /**
     * What the delete loses or leaves behind. Queried only while the control is
     * open and only for those who may delete.
     *
     * @return list<string>
     */
    private function deleteWarnings(TelegramUser $conversation): array
    {
        if (! $this->user()->can('delete', $conversation)) {
            return [];
        }

        return array_values(array_filter([
            $conversation->isBanned() ? __('telegram_inbox.manage.delete_warn_banned') : null,
            $conversation->messages()->whereNotNull('lead_id')->exists() ? __('telegram_inbox.manage.delete_warn_lead') : null,
            $this->copiesWouldStay() ? __('telegram_inbox.manage.delete_warn_bot_off') : null,
        ]));
    }

    /**
     * The bot cannot delete now, and there are copies young enough to delete. The
     * header columns are read here only: they are not part of the usual row.
     */
    private function copiesWouldStay(): bool
    {
        if (app(TelegramClient::class)->isConfigured() && BotSwitch::isEnabled()) {
            return false;
        }

        $header = TelegramUser::query()->select(['id', 'forward_header_chat_id', 'forward_header_message_id', 'forward_header_at'])->findOrFail($this->conversationId);

        return DeleteForwardedCopies::pairsFor($header) !== [];
    }

    /**
     * The latest staff actions on this thread: who and when, no message text.
     *
     * @return list<array{id: int, text: string, at: Carbon}>
     */
    private function history(TelegramUser $conversation): array
    {
        $rows = $conversation->activities()
            ->whereIn('type', [ActivityType::Assignment, ActivityType::StatusChange])
            ->latest('id')
            ->limit(self::HISTORY_ROWS)
            ->get(['id', 'user_id', 'type', 'meta', 'created_at']);

        $names = User::query()
            ->whereKey($rows->flatMap(fn (Activity $row): array => [$row->user_id, $row->meta['to_id'] ?? null])->filter()->unique()->values())
            ->pluck('name', 'id');

        return $rows
            ->map(function (Activity $row) use ($names): ?array {
                $key = $this->historyKey($row);

                return $key === null ? null : [
                    'id' => $row->id,
                    'text' => __('telegram_inbox.history.'.$key, [
                        'actor' => $names[$row->user_id ?? 0] ?? __('telegram_inbox.history.someone'),
                        'target' => $names[$row->meta['to_id'] ?? 0] ?? __('telegram_inbox.history.someone'),
                        'lead' => Lead::REFERENCE_PREFIX.($row->meta['lead_id'] ?? ''),
                    ]),
                    'at' => $row->created_at,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function historyKey(Activity $row): ?string
    {
        $action = $row->meta['action'] ?? null;

        return match (true) {
            in_array($action, ['first_reply', 'assign', 'take', 'link', 'verify_link', 'convert', 'ban', 'unban'], true) => $action,
            ($row->meta['to'] ?? null) === ConversationStatus::Closed->value => 'closed',
            ($row->meta['to'] ?? null) === ConversationStatus::Open->value => 'reopened',
            default => null,
        };
    }

    /**
     * A manager who gave the thread away cannot see it any more, and unbanning
     * a scrubbed row deletes it.
     */
    private function leaveIfNoLongerVisible(): void
    {
        $row = TelegramUser::query()->select(self::COLUMNS)->find($this->conversationId);

        if ($row === null || ! $this->user()->can('view', $row)) {
            $this->redirectRoute('admin.telegram.chats', navigate: true);
        }
    }

    private function conversation(bool $fresh = false): TelegramUser
    {
        if ($fresh || $this->loaded === null) {
            $this->loaded = TelegramUser::query()->select(self::COLUMNS)->findOrFail($this->conversationId);
        }

        return $this->loaded;
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
