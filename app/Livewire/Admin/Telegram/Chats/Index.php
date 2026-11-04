<?php

namespace App\Livewire\Admin\Telegram\Chats;

use App\Enums\ConversationStatus;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\TelegramMessage;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
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
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use WithAdminPagination;

    public const SCOPES = ['all', 'mine', 'unassigned', 'unread', 'unlinked'];

    public const STATUSES = ['', 'open', 'closed'];

    public const PER_PAGE = 25;

    /**
     * Columns shown in the list. No telegram_id, no forwarding internals.
     */
    private const COLUMNS = [
        'id', 'username', 'first_name', 'last_name', 'name', 'phone', 'declared_company', 'registered_at',
        'conversation_status', 'manager_id', 'customer_id', 'unread_count', 'last_message_at', 'banned_at', 'blocked_at',
    ];

    #[Url(except: 'all')]
    public string $scope = 'all';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $search = '';

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
        if (in_array($property, ['scope', 'status', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function setScope(string $scope): void
    {
        $this->scope = in_array($scope, self::SCOPES, true) ? $scope : 'all';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('scope', 'status', 'search');
        $this->resetPage();
    }

    public function render(): View
    {
        $user = $this->user();
        $scope = in_array($this->scope, self::SCOPES, true) ? $this->scope : 'all';
        $status = ConversationStatus::tryFrom($this->status);

        $items = TelegramUser::query()
            ->select(self::COLUMNS)
            ->visibleTo($user)
            ->when($scope === 'mine', fn (Builder $query) => $query->where('telegram_users.manager_id', $user->id))
            ->when($scope === 'unassigned', fn (Builder $query) => $query->whereNull('telegram_users.manager_id'))
            ->when($scope === 'unread', fn (Builder $query) => $query->where('telegram_users.unread_count', '>', 0))
            ->when($scope === 'unlinked', fn (Builder $query) => $query->registered()->whereNull('telegram_users.customer_id'))
            ->when($status !== null, fn (Builder $query) => $query->where('telegram_users.conversation_status', $status))
            ->search(mb_substr($this->search, 0, 100))
            ->with('manager:id,name,is_active')
            ->orderByDesc('telegram_users.last_message_at')
            ->orderByDesc('telegram_users.id')
            ->paginate(self::PER_PAGE);

        $stuck = TelegramMessage::query()
            ->stuck()
            ->whereIn('telegram_user_id', $items->getCollection()->modelKeys())
            ->distinct()
            ->pluck('telegram_user_id')
            ->all();

        return view('livewire.admin.telegram.chats.index', [
            'items' => $items,
            'stuck' => $stuck,
            'filtering' => $scope !== 'all' || $status !== null || trim($this->search) !== '',
            'currentScope' => $scope,
            'marksInactiveManagers' => $user->can('telegram.inbox-all'),
        ])->title(__('telegram_inbox.title'));
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
