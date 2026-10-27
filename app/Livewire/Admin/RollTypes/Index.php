<?php

namespace App\Livewire\Admin\RollTypes;

use App\Actions\Catalog\DeleteRollType;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\RollType;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Label roll types (plan 08): labels per roll, core, how many products use
 * each, status filter and delete (blocked while attached).
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use HandlesActionErrors, WithAdminPagination;

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', RollType::class);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('status');
        $this->resetPage();
    }

    public function delete(int $id, DeleteRollType $deleteRollType): void
    {
        $rollType = RollType::query()->findOrFail($id);
        $this->authorize('delete', $rollType);

        if ($this->runToastAction(fn () => $deleteRollType->handle($rollType))) {
            $this->dispatch('toast', title: __('admin.toast.deleted'), body: $rollType->label());
        }
    }

    public function render(): View
    {
        $rollTypes = RollType::query()
            ->withCount('products')
            ->when(in_array($this->status, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $this->status === 'active'))
            ->ordered()
            ->paginate(25);

        return view('livewire.admin.roll-types.index', [
            'items' => $rollTypes,
            'filtering' => $this->status !== '',
        ])->title(__('admin.roll_types.title'));
    }
}
