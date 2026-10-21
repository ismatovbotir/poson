<?php

namespace App\Livewire\Admin\Products;

use App\Actions\Catalog\DeleteProduct;
use App\Actions\Catalog\ForceDeleteProduct;
use App\Actions\Catalog\RestoreProduct;
use App\Actions\Catalog\SetProductActive;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\Brand;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;
use App\Support\Search;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Products list (plan 03 T12): tabs Active · Drafts · Specs incomplete ·
 * Trash, search on search_text, filters (category with its subcategories,
 * brand, type, stock), whitelisted sort, bulk publish / unpublish / delete,
 * and restore / permanent delete in the trash (permanent: Admin only).
 *
 * Row and bulk writes authorize each product (a service engineer may only
 * touch software and service products). A bulk action handles at most
 * MAX_BULK products, all or nothing.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use HandlesActionErrors, WithAdminPagination;

    public const TABS = ['active', 'drafts', 'incomplete', 'trash'];

    /**
     * Sort key → [column, direction].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const SORTS = [
        'updated' => ['updated_at', 'desc'],
        'newest' => ['created_at', 'desc'],
        'name' => ['name->uz', 'asc'],
        'sku' => ['sku', 'asc'],
        'price_asc' => ['price', 'asc'],
        'price_desc' => ['price', 'desc'],
    ];

    public const PER_PAGE = 25;

    /**
     * Most products one bulk action touches.
     */
    public const MAX_BULK = 200;

    #[Url(except: 'active')]
    public string $tab = 'active';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $brand = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $stock = '';

    #[Url(except: 'updated')]
    public string $sort = 'updated';

    /**
     * Selected product ids for the bulk actions.
     *
     * @var list<int>
     */
    public array $selected = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Product::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'search', 'category', 'brand', 'type', 'stock', 'sort'], true)) {
            $this->selected = [];
            $this->resetPage();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'active';
        $this->selected = [];
        $this->resetPage();
    }

    public function clearFilter(string $filter): void
    {
        if (in_array($filter, ['search', 'category', 'brand', 'type', 'stock'], true)) {
            $this->reset($filter);
            $this->selected = [];
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'category', 'brand', 'type', 'stock');
        $this->selected = [];
        $this->resetPage();
    }

    public function bulkActivate(SetProductActive $setActive): void
    {
        $this->bulkSetActive($setActive, true);
    }

    public function bulkDeactivate(SetProductActive $setActive): void
    {
        $this->bulkSetActive($setActive, false);
    }

    public function bulkDelete(DeleteProduct $deleteProduct): void
    {
        $products = $this->selectedProducts();

        foreach ($products as $product) {
            $this->authorize('delete', $product);
        }

        DB::transaction(function () use ($products, $deleteProduct): void {
            foreach ($products as $product) {
                $deleteProduct->handle($product);
            }
        });

        $this->selected = [];
        $this->dispatch('toast', title: __('admin.toast.deleted'), body: trans_choice('admin.products.bulk.moved_to_trash', $products->count(), ['count' => $products->count()]));
    }

    public function delete(int $id, DeleteProduct $deleteProduct): void
    {
        $product = Product::query()->findOrFail($id);
        $this->authorize('delete', $product);

        $deleteProduct->handle($product);
        $this->selected = array_values(array_diff($this->selected, [$id]));

        $this->dispatch('toast', title: __('admin.toast.deleted'), body: __('admin.products.moved_to_trash', ['name' => $product->name]));
    }

    public function restore(int $id, RestoreProduct $restoreProduct): void
    {
        $product = Product::onlyTrashed()->findOrFail($id);
        $this->authorize('restore', $product);

        $restoreProduct->handle($product);

        $this->dispatch('toast', title: __('admin.toast.done'), body: __('admin.products.restored', ['name' => $product->name]));
    }

    public function forceDelete(int $id, ForceDeleteProduct $forceDeleteProduct): void
    {
        $product = Product::onlyTrashed()->findOrFail($id);
        $this->authorize('forceDelete', $product);

        $forceDeleteProduct->handle($product);

        $this->dispatch('toast', title: __('admin.toast.deleted'), body: __('admin.products.force_deleted', ['name' => $product->name]));
    }

    /**
     * Product counts per tab (with the current search and filters).
     *
     * @return array<string, int>
     */
    #[Computed]
    public function tabCounts(): array
    {
        return [
            'active' => $this->filtered(Product::query())->where('is_active', true)->count(),
            'drafts' => $this->filtered(Product::query())->where('is_active', false)->count(),
            'incomplete' => $this->filtered(Product::query())->where('has_missing_specs', true)->count(),
            'trash' => $this->filtered(Product::onlyTrashed())->count(),
        ];
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        $options = [];

        foreach (CategoryTree::flattened() as $node) {
            $options[$node['id']] = str_repeat('— ', $node['depth'] - 1).CategoryTree::name($node['id']);
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function brandOptions(): array
    {
        return Brand::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Active filters for the chips: filter => label.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function activeFilters(): array
    {
        $chips = [];

        if ($this->categoryId() !== null) {
            $chips['category'] = __('admin.products.fields.category').': '.CategoryTree::name($this->categoryId());
        }

        if (filled($this->brand) && isset($this->brandOptions[(int) $this->brand])) {
            $chips['brand'] = __('admin.products.fields.brand').': '.$this->brandOptions[(int) $this->brand];
        }

        if ($type = ProductType::tryFrom($this->type)) {
            $chips['type'] = __('admin.products.fields.type').': '.$type->label();
        }

        if ($stock = StockStatus::tryFrom($this->stock)) {
            $chips['stock'] = __('admin.products.fields.stock_status').': '.$stock->label();
        }

        return $chips;
    }

    public function render(): View
    {
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'active';
        [$column, $direction] = self::SORTS[$this->sort] ?? self::SORTS['updated'];

        $query = $this->filtered($tab === 'trash' ? Product::onlyTrashed() : Product::query())
            ->when($tab === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($tab === 'drafts', fn (Builder $query) => $query->where('is_active', false))
            ->when($tab === 'incomplete', fn (Builder $query) => $query->where('has_missing_specs', true))
            ->with(['brand', 'media'])
            ->orderBy($column, $direction)
            ->orderByDesc('id');

        $products = $query->paginate(self::PER_PAGE);
        $user = auth()->user();

        return view('livewire.admin.products.index', [
            'items' => $products,
            'currentTab' => $tab,
            'filtering' => trim($this->search) !== '' || $this->activeFilters !== [],
            'canCreate' => $user->can('create', Product::class),
            'editable' => $products->getCollection()->filter(fn (Product $product): bool => $user->can('update', $product))->modelKeys(),
            'deletable' => $products->getCollection()->filter(fn (Product $product): bool => $user->can('delete', $product))->modelKeys(),
            'canForceDelete' => $user->can('forceDelete', Product::class),
        ])->title(__('admin.products.title'));
    }

    /**
     * Search and filters (not the tab).
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function filtered(Builder $query): Builder
    {
        $categoryId = $this->categoryId();

        return $query
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->whereRaw("search_text like ? escape '!'", [Search::normalizedPattern($this->search)]))
            ->when($categoryId !== null, fn (Builder $query) => $query->whereIn('category_id', [$categoryId, ...CategoryTree::descendantIds($categoryId)]))
            ->when(filled($this->brand), fn (Builder $query) => $query->where('brand_id', (int) $this->brand))
            ->when(ProductType::tryFrom($this->type) !== null, fn (Builder $query) => $query->where('type', $this->type))
            ->when(StockStatus::tryFrom($this->stock) !== null, fn (Builder $query) => $query->where('stock_status', $this->stock));
    }

    private function categoryId(): ?int
    {
        $id = (int) $this->category;

        return $id > 0 && CategoryTree::find($id) !== null ? $id : null;
    }

    /**
     * @return Collection<int, Product>
     */
    private function selectedProducts(): Collection
    {
        $ids = array_slice(array_values(array_unique(array_map('intval', $this->selected))), 0, self::MAX_BULK);

        return Product::query()->whereIn('id', $ids)->get();
    }

    private function bulkSetActive(SetProductActive $setActive, bool $active): void
    {
        $products = $this->selectedProducts();

        foreach ($products as $product) {
            $this->authorize('update', $product);
        }

        $done = 0;
        $skipped = 0;

        DB::transaction(function () use ($products, $setActive, $active, &$done, &$skipped): void {
            foreach ($products as $product) {
                $setActive->handle($product, $active) ? $done++ : $skipped++;
            }
        });

        $this->selected = [];

        $body = trans_choice($active ? 'admin.products.bulk.activated' : 'admin.products.bulk.deactivated', $done, ['count' => $done]);

        if ($skipped > 0) {
            $body .= ' '.trans_choice('admin.products.bulk.skipped_incomplete', $skipped, ['count' => $skipped]);
        }

        $this->dispatch('toast', title: __('admin.toast.done'), body: $body, type: $skipped > 0 ? 'error' : 'success');
    }
}
