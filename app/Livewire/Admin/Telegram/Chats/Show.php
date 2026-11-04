<?php

namespace App\Livewire\Admin\Telegram\Chats;

use App\Actions\Telegram\AssignConversation;
use App\Actions\Telegram\ChangeConversationStatus;
use App\Actions\Telegram\InboxRefused;
use App\Actions\Telegram\MarkConversationRead;
use App\Actions\Telegram\ResendTelegramMessage;
use App\Actions\Telegram\SendTelegramReply;
use App\Enums\TelegramDirection;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Conversation (plan 14 v2 §9, plan 21 A1 to A3): the latest 50 messages with
 * "load older", a profile panel, stuck deliveries highlighted, the reply box,
 * resend of undelivered replies, assign / take and close / reopen. Opening it marks the thread read. Writes
 * go through actions, which check policy and state again.
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

    private const COLUMNS = [
        'id', 'username', 'first_name', 'last_name', 'locale', 'name', 'phone', 'declared_company', 'declared_inn',
        'consent_at', 'registered_at', 'account_changed', 'conversation_status', 'manager_id', 'unread_count',
        'last_message_at', 'last_inbound_at', 'closed_at', 'blocked_at', 'banned_at', 'created_at',
    ];

    #[Locked]
    public int $conversationId = 0;

    #[Locked]
    public int $limit = self::PAGE_SIZE;

    public string $reply = '';

    public ?int $assignTarget = null;

    /**
     * The row loaded in this request (never part of the Livewire state).
     */
    private ?TelegramUser $loaded = null;

    public function mount(TelegramUser $telegramUser, MarkConversationRead $markRead): void
    {
        $this->authorize('view', $telegramUser);
        $this->conversationId = $telegramUser->id;

        $markRead->handle($telegramUser, $this->user());
    }

    public function hydrate(): void
    {
        $this->authorize('view', $this->conversation(fresh: true));
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

    public function sendReply(SendTelegramReply $send): void
    {
        $this->authorize('reply', $this->conversation());

        $this->validate(
            ['reply' => ['required', 'string', 'max:'.SendTelegramReply::MAX_LENGTH]],
            attributes: ['reply' => __('telegram_inbox.reply.label')],
        );

        $this->attempt(function () use ($send): void {
            $send->handle($this->conversation(), $this->user(), $this->reply);
            $this->reset('reply');
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

    public function assign(AssignConversation $assign): void
    {
        $this->authorize('assign', $this->conversation());

        $this->attempt(function () use ($assign): void {
            if ($this->assignTarget === null) {
                throw new InboxRefused('invalid_target');
            }

            $assign->assign($this->conversation(), $this->user(), $this->assignTarget);
            $this->reset('assignTarget');
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

    public function render(): View
    {
        $conversation = $this->conversation();

        $newestFirst = TelegramMessage::query()
            ->select([
                'id', 'telegram_user_id', 'direction', 'kind', 'text', 'media', 'user_id', 'status', 'error_code',
                'sent_at', 'forward_status', 'forward_error_code', 'created_at', 'updated_at',
            ])
            ->where('telegram_user_id', $conversation->id)
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit($this->limit + 1)
            ->get();

        $hasOlder = $newestFirst->count() > $this->limit;

        return view('livewire.admin.telegram.chats.show', [
            'conversation' => $conversation->loadMissing('manager:id,name,is_active'),
            'messages' => $newestFirst->take($this->limit)->reverse()->values(),
            'hasOlder' => $hasOlder && $this->limit < self::MAX_MESSAGES,
            'olderHidden' => $hasOlder && $this->limit >= self::MAX_MESSAGES,
            'polling' => $this->limit === self::PAGE_SIZE,
            'replyRefusal' => app(SendTelegramReply::class)->refusal($conversation),
            'candidates' => $this->user()->can('assign', $conversation) ? app(AssignConversation::class)->candidates() : collect(),
        ])->title(__('telegram_inbox.show.title'));
    }

    /**
     * Run a write and report it. A policy refusal stays a 403. The row is read
     * again for the render, because the write may have changed it.
     */
    private function attempt(Closure $write, string $toast): void
    {
        try {
            $write();
            $this->dispatch('toast', title: __('admin.toast.done'), body: __('telegram_inbox.toasts.'.$toast), type: 'success');
        } catch (InboxRefused $refused) {
            $this->dispatch('toast', title: $refused->translated(), type: 'error');
        }

        $this->loaded = null;
    }

    /**
     * A manager who gave the thread away cannot see it any more.
     */
    private function leaveIfNoLongerVisible(): void
    {
        if (! $this->user()->can('view', $this->conversation(fresh: true))) {
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
