<?php

namespace App\Livewire\Admin\Categories;

use App\Actions\Catalog\DeleteCategory;
use App\Actions\Catalog\ReorderCategory;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Models\Category;
use App\Support\Catalog\CategoryTree;
use App\Support\Search;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Category tree: collapse, drag sort among siblings (arrows on phones),
 * search and status filter (flat result list), delete with confirm.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use HandlesActionErrors;

    #[Url(except: '')]
    public string $search = '';

    /**
     * '' (all), 'active' or 'inactive'.
     */
    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Category::class);
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status');
    }

    /**
     * Drag and drop (wire:sort): put the category at $position among its siblings.
     */
    public function reorder(int|string $id, int|string $position, ReorderCategory $reorderCategory): void
    {
        $category = Category::query()->findOrFail((int) $id);
        $this->authorize('update', $category);

        $reorderCategory->handle($category, (int) $position);
    }

    /**
     * Arrow buttons: move one place up (-1) or down (+1) among the siblings.
     */
    public function move(int $id, int $direction, ReorderCategory $reorderCategory): void
    {
        $category = Category::query()->findOrFail($id);
        $this->authorize('update', $category);

        $siblingIds = array_column(CategoryTree::children($category->parent_id), 'id');
        $index = array_search($category->id, $siblingIds, true);

        if ($index === false) {
            return;
        }

        $reorderCategory->handle($category, $index + ($direction < 0 ? -1 : 1));
    }

    public function delete(int $id, DeleteCategory $deleteCategory): void
    {
        $category = Category::query()->findOrFail($id);
        $this->authorize('delete', $category);

        if ($this->runToastAction(fn () => $deleteCategory->handle($category))) {
            $this->dispatch('toast', title: __('admin.toast.deleted'), body: $category->name);
        }
    }

    public function render(): View
    {
        $categories = Category::query()
            ->withCount(['attributes', 'products'])
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $filtering = trim($this->search) !== '' || $this->status !== '';

        return view('livewire.admin.categories.index', [
            'tree' => $categories->groupBy(fn (Category $category): string => (string) $category->parent_id),
            'results' => $filtering ? $this->filter($categories) : null,
            'total' => $categories->count(),
        ])->title(__('admin.categories.title'));
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return Collection<int, Category>
     */
    private function filter(Collection $categories): Collection
    {
        $matches = $categories;

        if ($this->status !== '') {
            $matches = $matches->where('is_active', $this->status === 'active');
        }

        if (trim($this->search) !== '') {
            $ids = Category::query()
                ->where(function ($query): void {
                    Search::orWhereTranslatable($query, 'name', $this->search);
                    $query->orWhereRaw("lower(slug) like ? escape '!'", [Search::likePattern($this->search)]);
                })
                ->pluck('id')
                ->all();

            $matches = $matches->whereIn('id', $ids);
        }

        return $matches->sortBy(fn (Category $category): string => CategoryTree::path($category->id))->values();
    }
}
