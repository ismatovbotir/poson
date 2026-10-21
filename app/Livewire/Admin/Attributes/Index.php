<?php

namespace App\Livewire\Admin\Attributes;

use App\Actions\Catalog\DeleteAttribute;
use App\Enums\AttributeType;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\Attribute;
use App\Support\Search;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Attribute library: search (code / name), type filter, in-use count
 * (categories), delete with confirm.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use HandlesActionErrors, WithAdminPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Attribute::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'type');
        $this->resetPage();
    }

    public function delete(int $id, DeleteAttribute $deleteAttribute): void
    {
        $attribute = Attribute::query()->findOrFail($id);
        $this->authorize('delete', $attribute);

        if ($this->runToastAction(fn () => $deleteAttribute->handle($attribute))) {
            $this->dispatch('toast', title: __('admin.toast.deleted'), body: $attribute->name);
        }
    }

    public function render(): View
    {
        $type = in_array($this->type, AttributeType::values(), true) ? $this->type : null;

        $attributes = Attribute::query()
            ->withCount(['categories', 'options'])
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw("lower(code) like ? escape '!'", [Search::likePattern($this->search)])
                ->orWhere(fn ($names) => Search::orWhereTranslatable($names, 'name', $this->search))))
            ->when($type, fn ($query) => $query->where('type', $type))
            ->orderBy('sort')
            ->orderBy('id')
            ->paginate(25);

        return view('livewire.admin.attributes.index', [
            'items' => $attributes,
            'types' => AttributeType::cases(),
            'filtering' => trim($this->search) !== '' || $type !== null,
        ])->title(__('admin.attributes.title'));
    }
}
