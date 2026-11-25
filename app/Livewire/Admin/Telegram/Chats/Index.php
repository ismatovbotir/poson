<?php

namespace App\Livewire\Admin\Telegram\Chats;

use App\Enums\ConversationStatus;
use App\Enums\TelegramParty;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use App\Models\WebChat;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Telegram → Chats (/admin/telegram/chats, plan 14 v2 §9, plan 21 A1): the
 * staff inbox of bot conversations. Filters scope (all / mine / unassigned /
 * unread / unlinked, §22), status and search. Rows come from TelegramUser::visibleTo() only,
 * so the list matches the thread URLs (TelegramUserPolicy::view, T21-10, T21-11).
 * Staff with telegram.inbox-all see threads owned by an inactive manager marked
 * (T21-17). telegram_id is never selected. The list refreshes every 15 seconds while visible.
 *
 * Plan 28 C5c: a channel filter (all / telegram / web, allow-listed; unknown = all).
 * Each channel's rows come from its own visibleTo() scope (WC-12). "All" is a UNION ALL
 * of two base selects with the same columns (channel literal, id, manager_id, unread_count,
 * conversation_status, last_message_at), ordered by last_message_at desc, channel, id desc;
 * a single channel uses the same wrapper with one branch. The page is then hydrated per
 * channel with whereIn + visibleTo again and the manager eager-loaded. The Telegram-only
 * "unlinked" scope queries the Telegram branch only.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use WithAdminPagination;

    public const SCOPES = ['all', 'mine', 'unassigned', 'unread', 'unlinked'];

    public const CHANNELS = ['all', 'telegram', 'web'];

    public const STATUSES = ['', 'open', 'closed'];

    public const PER_PAGE = 25;

    /**
     * Columns shown in the list. No telegram_id, no forwarding internals.
     */
    private const COLUMNS = [
        'id', 'username', 'first_name', 'last_name', 'name', 'phone', 'declared_company', 'registered_at',
        'conversation_status', 'manager_id', 'customer_id', 'unread_count', 'last_message_at', 'banned_at', 'blocked_at',
    ];

    /**
     * Web chat columns shown in the list: no token, IP hash, dialog or page.
     */
    private const WEB_COLUMNS = [
        'id', 'name', 'phone', 'locale', 'mode', 'conversation_status', 'manager_id', 'unread_count', 'last_message_at',
        'last_inbound_at', 'last_staff_reply_at', 'handoff_at', 'closed_at', 'banned_at', 'created_at',
    ];

    #[Url(except: 'all')]
    public string $channel = 'all';

    #[Url(except: 'all')]
    public string $scope = 'all';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $search = '';

    /**
     * Plan 41 §4: client / employee mark of bot users (office.mark only; Telegram rows only).
     */
    #[Url(except: '')]
    public string $party = '';

    public function mount(): void
    {
        $this->authorize('viewAny', TelegramUser::class);
    }

    public function hydrate(): void
    {
        $this->authorize('viewAny', TelegramUser::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['channel', 'scope', 'status', 'search', 'party'], true)) {
            $this->resetPage();
        }
    }

    public function setScope(string $scope): void
    {
        $this->scope = in_array($scope, self::SCOPES, true) ? $scope : 'all';

        if ($this->scope === 'unlinked' && $this->channel === 'web') {
            $this->scope = 'all';
        }

        $this->resetPage();
    }

    public function setChannel(string $channel): void
    {
        $this->channel = in_array($channel, self::CHANNELS, true) ? $channel : 'all';

        if ($this->channel === 'web' && $this->scope === 'unlinked') {
            $this->scope = 'all';
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('channel', 'scope', 'status', 'search', 'party');
        $this->resetPage();
    }

    public function render(): View
    {
        $user = $this->user();
        $scope = in_array($this->scope, self::SCOPES, true) ? $this->scope : 'all';
        $channel = in_array($this->channel, self::CHANNELS, true) ? $this->channel : 'all';
        $scope = $scope === 'unlinked' && $channel === 'web' ? 'all' : $scope;
        $status = ConversationStatus::tryFrom($this->status);
        $search = mb_substr($this->search, 0, 100);

        $party = $user->can('office.mark') ? TelegramParty::tryFrom($this->party) : null;

        // "Unlinked" (and the plan 41 party filter) only exist for Telegram; the web channel ignores them.
        $telegramOnly = ($scope === 'unlinked' || $party !== null) && $channel !== 'web';
        $useTelegram = $channel !== 'web';
        $useWeb = $channel !== 'telegram' && ! $telegramOnly;

        $branches = [];

        if ($useTelegram) {
            $branches[] = TelegramUser::query()
                ->visibleTo($user)
                ->when($scope === 'mine', fn (Builder $query) => $query->where('telegram_users.manager_id', $user->id))
                ->when($scope === 'unassigned', fn (Builder $query) => $query->whereNull('telegram_users.manager_id'))
                ->when($scope === 'unread', fn (Builder $query) => $query->where('telegram_users.unread_count', '>', 0))
                ->when($scope === 'unlinked', fn (Builder $query) => $query->registered()->whereNull('telegram_users.customer_id'))
                ->when($status !== null, fn (Builder $query) => $query->where('telegram_users.conversation_status', $status))
                ->when($party !== null, fn (Builder $query) => $query->where('telegram_users.party', $party?->value))
                ->search($search)
                ->toBase()
                ->select([DB::raw("'telegram' as channel"), 'telegram_users.id', 'telegram_users.manager_id', 'telegram_users.unread_count', 'telegram_users.conversation_status', 'telegram_users.last_message_at']);
        }

        if ($useWeb) {
            $branches[] = WebChat::query()
                ->visibleTo($user)
                ->when($scope === 'mine', fn (Builder $query) => $query->where('web_chats.manager_id', $user->id))
                ->when($scope === 'unassigned', fn (Builder $query) => $query->whereNull('web_chats.manager_id'))
                ->when($scope === 'unread', fn (Builder $query) => $query->where('web_chats.unread_count', '>', 0))
                ->when($status !== null, fn (Builder $query) => $query->where('web_chats.conversation_status', $status))
                ->search($search)
                ->toBase()
                ->select([DB::raw("'web' as channel"), 'web_chats.id', 'web_chats.manager_id', 'web_chats.unread_count', 'web_chats.conversation_status', 'web_chats.last_message_at']);
        }

        $union = array_shift($branches);

        foreach ($branches as $branch) {
            $union->unionAll($branch);
        }

        $page = DB::query()
            ->fromSub($union, 'inbox')
            ->orderByDesc('last_message_at')
            ->orderBy('channel')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $items = $this->hydrateRows($page, $user);

        $telegramIds = $items->getCollection()->where('channel', 'telegram')->pluck('chat.id')->all();

        $stuck = $telegramIds === [] ? [] : TelegramMessage::query()
            ->stuck()
            ->whereIn('telegram_user_id', $telegramIds)
            ->distinct()
            ->pluck('telegram_user_id')
            ->all();

        return view('livewire.admin.telegram.chats.index', [
            'items' => $items,
            'stuck' => $stuck,
            'filtering' => $scope !== 'all' || $status !== null || trim($this->search) !== '' || $channel !== 'all' || $party !== null,
            'canFilterParty' => $user->can('office.mark'),
            'currentScope' => $scope,
            'currentChannel' => $channel,
            'marksInactiveManagers' => $user->can('telegram.inbox-all'),
        ])->title(__('telegram_inbox.title'));
    }

    /**
     * Replace the page's id rows with models, per channel, through the same visibleTo scope.
     *
     * @return LengthAwarePaginator<int, array{channel: string, chat: TelegramUser|WebChat}>
     */
    private function hydrateRows(LengthAwarePaginator $page, User $user): LengthAwarePaginator
    {
        $rows = $page->getCollection();

        $telegram = $rows->where('channel', 'telegram')->isEmpty() ? collect() : TelegramUser::query()
            ->select(self::COLUMNS)
            ->visibleTo($user)
            ->with('manager:id,name,is_active')
            ->whereIn('telegram_users.id', $rows->where('channel', 'telegram')->pluck('id')->all())
            ->get()
            ->keyBy('id');

        $web = $rows->where('channel', 'web')->isEmpty() ? collect() : WebChat::query()
            ->select(self::WEB_COLUMNS)
            ->visibleTo($user)
            ->with('manager:id,name,is_active')
            ->whereIn('web_chats.id', $rows->where('channel', 'web')->pluck('id')->all())
            ->get()
            ->keyBy('id');

        return $page->setCollection($rows->map(function (object $row) use ($telegram, $web): ?array {
            $chat = $row->channel === 'web' ? $web->get($row->id) : $telegram->get($row->id);

            return $chat === null ? null : ['channel' => $row->channel === 'web' ? 'web' : 'telegram', 'chat' => $chat];
        })->filter()->values());
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
