<?php

namespace App\Livewire\Admin\Chatbot\Rules;

use App\Actions\Chat\DeleteChatRule;
use App\Actions\Chat\ToggleChatRule;
use App\Enums\ChatAction;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\ChatRule;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Chat bot → Rules (plan 28 C2, chatbot.rules): the list of rules with search,
 * action and state filters, an on/off switch and delete (not for system rules).
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use HandlesActionErrors, WithAdminPagination;

    public const PER_PAGE = 25;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', ChatRule::class);
    }

    public function hydrate(): void
    {
        $this->authorize('viewAny', ChatRule::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'action', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'action', 'status');
        $this->resetPage();
    }

    public function toggle(int $id, ToggleChatRule $toggleChatRule): void
    {
        $rule = ChatRule::query()->findOrFail($id);
        $this->authorize('update', $rule);

        $updated = $toggleChatRule->handle($rule, auth()->user(), ! $rule->is_active);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __($updated->is_active ? 'chatbot_admin.rules.switched_on' : 'chatbot_admin.rules.switched_off', ['name' => $updated->name]));
    }

    public function delete(int $id, DeleteChatRule $deleteChatRule): void
    {
        $rule = ChatRule::query()->findOrFail($id);
        $this->authorize('delete', $rule);

        if ($this->runToastAction(fn () => $deleteChatRule->handle($rule, auth()->user()))) {
            $this->dispatch('toast', title: __('admin.toast.deleted'), body: $rule->name);
        }
    }

    public function render(): View
    {
        $this->authorize('viewAny', ChatRule::class);

        $search = trim($this->search);

        $rules = ChatRule::query()
            ->withCount('keywords')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('key', 'like', '%'.addcslashes($search, '%_\\').'%')))
            ->when(ChatAction::tryFrom($this->action), fn (Builder $query, ChatAction $action) => $query->where('action', $action->value))
            ->when($this->status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($this->status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->orderByDesc('in_menu')
            ->orderBy('menu_sort')
            ->orderBy('name')
            ->paginate(self::PER_PAGE);

        return view('livewire.admin.chatbot.rules.index', [
            'items' => $rules,
            'filtering' => $search !== '' || $this->action !== '' || $this->status !== '',
        ])->title(__('chatbot_admin.rules.title'));
    }
}
