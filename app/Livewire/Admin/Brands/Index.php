<?php

namespace App\Livewire\Admin\Brands;

use App\Actions\Catalog\DeleteBrand;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\Brand;
use App\Support\Search;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Brands list with logo thumbnails, search, status filter and delete.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use HandlesActionErrors, WithAdminPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Brand::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }

    public function delete(int $id, DeleteBrand $deleteBrand): void
    {
        $brand = Brand::query()->findOrFail($id);
        $this->authorize('delete', $brand);

        if ($this->runToastAction(fn () => $deleteBrand->handle($brand))) {
            $this->dispatch('toast', title: __('admin.toast.deleted'), body: $brand->name);
        }
    }

    public function render(): View
    {
        $brands = Brand::query()
            ->with('media')
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw("lower(name) like ? escape '!'", [Search::likePattern($this->search)])
                ->orWhereRaw("lower(slug) like ? escape '!'", [Search::likePattern($this->search)])))
            ->when(in_array($this->status, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $this->status === 'active'))
            ->orderBy('sort')
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.admin.brands.index', [
            'items' => $brands,
            'filtering' => trim($this->search) !== '' || $this->status !== '',
        ])->title(__('admin.brands.title'));
    }
}
