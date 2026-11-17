<?php

namespace App\Livewire\Admin\Chat\Web;

use App\Actions\Chat\Web\AssignWebChat;
use App\Actions\Chat\Web\ChangeWebChatStatus;
use App\Actions\Chat\Web\ConvertWebChatToLead;
use App\Actions\Chat\Web\MarkWebChatRead;
use App\Actions\Chat\Web\SendWebChatReply;
use App\Actions\Chat\Web\SetWebChatBan;
use App\Actions\Telegram\InboxRefused;
use App\Enums\ChatAuthor;
use App\Enums\LeadType;
use App\Models\Lead;
use App\Models\User;
use App\Models\WebChat;
use App\Models\WebChatMessage;
use App\Support\Chat\WebChatVersion;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Website chat thread (plan 28 C5c, /admin/chats/web/{id}): the latest 50
 * messages, staff-only system lines, the reply box, take / assign, close /
 * reopen, ban, convert to a lead and the lead link. Name and phone are
 * labelled "given by the visitor, not verified" (WC-9); first_page is plain
 * text, never a link. Visitor text is stored redacted and escaped on output.
 *
 * Only the chat id is state (locked); the row is loaded on every request and
 * "view" is authorized in mount, hydrate and every method (CB-21), so a
 * reassignment gives 403 on the next poll. The 10 s poll renders only when
 * the chat's version marker changed. Every write is an action, which checks
 * policy and state again and bumps the version.
 */
#[Layout('layouts::admin')]
class Show extends Component
{
    public const PAGE_SIZE = 50;

    #[Locked]
    public int $chatId = 0;

    #[Locked]
    public int $viewerId = 0;

    #[Locked]
    public ?string $seenVersion = null;

    public string $reply = '';

    public ?int $assignTarget = null;

    public bool $assignOpen = false;

    public bool $convertOpen = false;

    public string $convertType = LeadType::Quote->value;

    /**
     * Columns the screen, policy and actions' callers read: never the token
     * hash, IP hash or dialog (actions re-read the locked row themselves).
     */
    private const COLUMNS = [
        'id', 'locale', 'name', 'phone', 'consent_at', 'consent_version', 'mode', 'conversation_status', 'manager_id',
        'unread_count', 'last_message_at', 'last_inbound_at', 'last_staff_reply_at', 'last_alert_at', 'handoff_at',
        'closed_at', 'ai_context_at', 'first_page', 'lead_id', 'customer_id', 'banned_at', 'banned_by_id', 'purged_at',
        'created_at', 'updated_at',
    ];

    private ?WebChat $loaded = null;

    private bool $gone = false;

    /**
     * Not a bound model: a chat deleted meanwhile must redirect, not 404.
     * A missing id looks like a foreign chat to staff without inbox-all (403).
     */
    public function mount(WebChat|int|string $webChat, MarkWebChatRead $markRead): void
    {
        $chat = $webChat instanceof WebChat ? $webChat : WebChat::query()->find($webChat);

        if ($chat === null) {
            abort($this->user()->can('telegram.inbox-all') ? 404 : 403);
        }

        $this->authorize('view', $chat);
        $this->chatId = $chat->id;
        $this->viewerId = $this->user()->id;
        $this->seenVersion = WebChatVersion::get($chat->id);

        $markRead->handle($chat, $this->user());
    }

    public function hydrate(): void
    {
        abort_unless($this->viewerId === (int) auth()->id(), 403);

        $this->authorize('viewAny', WebChat::class);

        $this->loaded = WebChat::query()->select(self::COLUMNS)->find($this->chatId);

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
     * @param  Throwable  $e
     * @param  callable  $stopPropagation
     */
    public function exception($e, $stopPropagation): void
    {
        if ($this->gone && $e instanceof ModelNotFoundException) {
            $stopPropagation();
        }
    }

    public function refreshThread(MarkWebChatRead $markRead): void
    {
        $chat = $this->chat();
        $markRead->handle($chat, $this->user());

        $version = WebChatVersion::get($this->chatId);

        if ($version !== null && $version === $this->seenVersion) {
            $this->skipRender();

            return;
        }

        $this->seenVersion = $version;
        $this->loaded = null;
    }

    public function sendReply(SendWebChatReply $send): void
    {
        $this->authorize('reply', $this->chat());

        $this->validate(
            ['reply' => ['required', 'string', 'max:'.(int) config('chatbot.web.message_max', 1000)]],
            attributes: ['reply' => __('telegram_inbox.reply.label')],
        );

        $this->attempt(function () use ($send): ?string {
            $sent = $send->handle($this->chat(), $this->user(), $this->reply);
            $this->reset('reply');

            return $sent === null ? 'already_sent' : null;
        }, 'reply_sent');
    }

    public function take(AssignWebChat $assign): void
    {
        $this->authorize('assign', $this->chat());

        $this->attempt(fn () => $assign->take($this->chat(), $this->user()), 'taken');
    }

    public function assign(AssignWebChat $assign): void
    {
        $this->authorize('assign', $this->chat());

        $this->attempt(function () use ($assign): bool {
            if ($this->assignTarget === null) {
                throw new InboxRefused('invalid_target');
            }

            $changed = $assign->assign($this->chat(), $this->user(), $this->assignTarget);
            $this->reset('assignTarget', 'assignOpen');

            return $changed;
        }, 'assigned');

        $this->leaveIfNoLongerVisible();
    }

    public function close(ChangeWebChatStatus $status): void
    {
        $this->authorize('close', $this->chat());

        $this->attempt(fn () => $status->close($this->chat(), $this->user()), 'closed');
    }

    public function reopen(ChangeWebChatStatus $status): void
    {
        $this->authorize('close', $this->chat());

        $this->attempt(fn () => $status->reopen($this->chat(), $this->user()), 'reopened');
    }

    public function ban(SetWebChatBan $bans): void
    {
        $this->authorize('ban', $this->chat());

        $this->attempt(fn () => $bans->ban($this->chat(), $this->user(), request()->ip()), 'banned');
    }

    public function unban(SetWebChatBan $bans): void
    {
        $this->authorize('ban', $this->chat());

        $this->attempt(fn () => $bans->unban($this->chat(), $this->user(), request()->ip()), 'unbanned');

        $this->leaveIfNoLongerVisible();
    }

    public function openConvert(): void
    {
        $this->authorize('convert', $this->chat());

        $this->convertOpen = true;
        $this->convertType = (collect(ConvertWebChatToLead::TYPES)->first(fn (LeadType $type): bool => $this->user()->can('create', [Lead::class, $type])) ?? LeadType::Quote)->value;
    }

    public function closeConvert(): void
    {
        $this->reset('convertOpen');
    }

    /**
     * The lead text is the visitor's latest messages of this chat (the action loads them through the chat).
     */
    public function convert(ConvertWebChatToLead $convert): void
    {
        $this->authorize('convert', $this->chat());

        $this->attempt(function () use ($convert): ?string {
            $type = LeadType::tryFrom($this->convertType);

            if ($type === null) {
                throw new InboxRefused('invalid_type');
            }

            $ids = WebChatMessage::query()
                ->where('web_chat_id', $this->chatId)
                ->where('author', ChatAuthor::Visitor)
                ->whereNull('lead_id')
                ->orderByDesc('id')
                ->limit(ConvertWebChatToLead::MAX_MESSAGES)
                ->pluck('id')
                ->all();

            $lead = $convert->handle($this->chat(), $this->user(), $type, $ids);
            $this->reset('convertOpen');

            return $lead === null ? 'already_converted' : null;
        }, 'converted');
    }

    public function render(): View
    {
        $chat = $this->chat();
        $user = $this->user();

        $newestFirst = WebChatMessage::query()
            ->where('web_chat_id', $chat->id)
            ->with('user:id,name', 'rule:id,name')
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE)
            ->get();

        $this->seenVersion = WebChatVersion::get($chat->id);

        return view('livewire.admin.chat.web.show', [
            'chat' => $chat->loadMissing('manager:id,name,is_active', 'lead:id'),
            'messages' => $newestFirst->reverse()->values(),
            'replyRefusal' => app(SendWebChatReply::class)->refusal($chat),
            'candidates' => $this->assignOpen && $user->can('assign', $chat) ? app(AssignWebChat::class)->candidates() : new Collection,
            'canOpenLeads' => $user->can('leads.view'),
            'canConvert' => $user->can('convert', $chat) && ! $chat->isBanned() && $chat->lead_id === null,
            'convertPhoneUsable' => filled($chat->phone),
            'convertTypes' => $this->convertOpen ? array_values(array_filter(ConvertWebChatToLead::TYPES, fn (LeadType $type): bool => $user->can('create', [Lead::class, $type]))) : [],
        ])->title(__('telegram_inbox.web.show_title'));
    }

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

    private function leaveIfNoLongerVisible(): void
    {
        $row = WebChat::query()->select(self::COLUMNS)->find($this->chatId);

        if ($row === null || ! $this->user()->can('view', $row)) {
            $this->redirectRoute('admin.telegram.chats', navigate: true);
        }
    }

    private function chat(): WebChat
    {
        return $this->loaded ??= WebChat::query()->select(self::COLUMNS)->findOrFail($this->chatId);
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
