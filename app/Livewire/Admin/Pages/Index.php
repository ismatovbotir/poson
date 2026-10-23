<?php

namespace App\Livewire\Admin\Pages;

use App\Actions\Pages\DeletePage;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\Page;
use App\Support\Search;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Static pages list (plan 07 7a): title, URL, template, status, system and
 * "lawyer review needed" badges; search, status filter and delete (custom
 * pages only, DeletePage).
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
        $this->authorize('viewAny', Page::class);
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

    public function delete(int $id, DeletePage $deletePage): void
    {
        $page = Page::query()->findOrFail($id);
        $this->authorize('delete', $page);

        if ($this->runToastAction(fn () => $deletePage->handle($page))) {
            $this->dispatch('toast', title: __('admin.toast.deleted'), body: (string) $page->title);
        }
    }

    public function render(): View
    {
        $search = trim($this->search);

        $pages = Page::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw("lower(slug) like ? escape '!'", [Search::likePattern($search)])
                ->orWhere(fn ($titles) => Search::orWhereTranslatable($titles, 'title', $search))))
            ->when(in_array($this->status, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $this->status === 'active'))
            ->when($this->status === 'review', fn ($query) => $query->where('needs_review', true))
            ->ordered()
            ->paginate(25);

        return view('livewire.admin.pages.index', [
            'items' => $pages,
            'filtering' => $search !== '' || $this->status !== '',
        ])->title(__('admin.pages.title'));
    }
}
