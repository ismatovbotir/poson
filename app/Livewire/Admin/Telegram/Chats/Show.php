<?php

namespace App\Livewire\Admin\Telegram\Chats;

use App\Actions\Telegram\MarkConversationRead;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Read-only conversation (plan 14 v2 §9, plan 21 A1): the latest 50 messages
 * with "load older", a read-only profile panel, stuck deliveries highlighted.
 * Opening it marks the thread read. No reply box yet.
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
            'conversation' => $conversation->loadMissing('manager:id,name'),
            'messages' => $newestFirst->take($this->limit)->reverse()->values(),
            'hasOlder' => $hasOlder && $this->limit < self::MAX_MESSAGES,
        ])->title(__('telegram_inbox.show.title'));
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
