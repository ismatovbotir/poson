<?php

namespace App\Livewire\Admin\Office;

use App\Actions\Office\MarkBusinessChat;
use App\Actions\Office\OfficeStaff;
use App\Enums\BusinessChatKind;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\BusinessChat;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Virtual office → Unmarked (/admin/office/unmarked, plan 41 §8.1,
 * office.mark = Admin only): Telegram Business chats waiting for a mark,
 * newest inbound first. A row shows only the stub (name, username, waiting
 * count, first / last inbound); nothing was stored from these chats (VO-4).
 * Mark as client (optional assignee), employee (optional staff account)
 * or ignore. Every method authorizes again; the chat is re-read as an
 * unmarked row (a tampered id is a 404).
 */
#[Layout('layouts::admin')]
class Unmarked extends Component
{
    use WithAdminPagination;

    public const PER_PAGE = 25;

    /** @var array<int|string, int|string|null> chat id => assignee id */
    public array $assignees = [];

    /** @var array<int|string, int|string|null> chat id => staff user id */
    public array $staff = [];

    public function mount(): void
    {
        $this->authorize('office.mark');
    }

    public function hydrate(): void
    {
        $this->authorize('office.mark');
    }

    public function markClient(int $chatId, MarkBusinessChat $mark): void
    {
        $this->mark($mark, $chatId, BusinessChatKind::Client, assigneeId: self::positiveId($this->assignees[$chatId] ?? null));
    }

    public function markEmployee(int $chatId, MarkBusinessChat $mark): void
    {
        $this->mark($mark, $chatId, BusinessChatKind::Employee, staffUserId: self::positiveId($this->staff[$chatId] ?? null));
    }

    public function ignore(int $chatId, MarkBusinessChat $mark): void
    {
        $this->mark($mark, $chatId, BusinessChatKind::Ignored);
    }

    protected function mark(MarkBusinessChat $mark, int $chatId, BusinessChatKind $kind, ?int $assigneeId = null, ?int $staffUserId = null): void
    {
        $this->authorize('office.mark');

        $this->resetErrorBag();

        $chat = BusinessChat::query()->whereKey($chatId)->where('kind', BusinessChatKind::Unmarked->value)->firstOrFail();

        $mark->handle($this->user(), $chat, $kind, $assigneeId, $staffUserId, request()->ip());

        unset($this->assignees[$chatId], $this->staff[$chatId]);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('office.unmarked.marked', ['kind' => $kind->label()]), type: 'success');
    }

    public function render(): View
    {
        $chats = BusinessChat::query()
            ->where('kind', BusinessChatKind::Unmarked->value)
            ->orderByDesc('last_inbound_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['id', 'first_name', 'username', 'unread_count', 'first_inbound_at', 'last_inbound_at', 'last_owner_at']);

        return view('livewire.admin.office.unmarked', [
            'chats' => $chats,
            'assignable' => OfficeStaff::assignable(),
            'activeStaff' => OfficeStaff::active(),
        ])->title(__('office.unmarked.title'));
    }

    private static function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
