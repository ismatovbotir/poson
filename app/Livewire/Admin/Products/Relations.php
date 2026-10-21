<?php

namespace App\Livewire\Admin\Products;

use App\Actions\Catalog\AddProductRelation;
use App\Actions\Catalog\RemoveProductRelation;
use App\Enums\RelationType;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Models\Product;
use App\Models\ProductRelation;
use App\Support\Search;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Related products (child of the product form, after the first save):
 * search picker + relation type (accessory, consumable, alternative,
 * software), remove.
 */
class Relations extends Component
{
    use HandlesActionErrors;

    public Product $product;

    public string $search = '';

    public string $type = 'accessory';

    public function mount(Product $product): void
    {
        $this->authorize('view', $product);
        $this->product = $product;
    }

    /**
     * @return Collection<int, ProductRelation>
     */
    #[Computed]
    public function relations(): Collection
    {
        return ProductRelation::query()
            ->where('product_id', $this->product->id)
            ->with(['related.media'])
            ->orderBy('type')
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /**
     * Picker results: other products (not yet linked with the chosen type).
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function results(): Collection
    {
        if (mb_strlen(trim($this->search)) < 2) {
            return new Collection;
        }

        $linked = $this->relations
            ->filter(fn (ProductRelation $relation): bool => $relation->type->value === $this->type)
            ->pluck('related_id')
            ->all();

        return Product::query()
            ->whereKeyNot($this->product->id)
            ->whereNotIn('id', $linked)
            ->whereRaw("search_text like ? escape '!'", [Search::normalizedPattern($this->search)])
            ->orderByDesc('is_active')
            ->orderBy('sku')
            ->limit(10)
            ->get();
    }

    public function add(int $relatedId, AddProductRelation $addRelation): void
    {
        $this->authorize('update', $this->product);

        $this->validate(['type' => ['required', Rule::enum(RelationType::class)]]);

        $related = Product::query()->findOrFail($relatedId);

        if (! $this->runToastAction(fn () => $addRelation->handle($this->product, $related, RelationType::from($this->type)))) {
            return;
        }

        $this->search = '';
        unset($this->relations, $this->results);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.products.relations.added', ['name' => $related->name]));
    }

    public function remove(int $relationId, RemoveProductRelation $removeRelation): void
    {
        $this->authorize('update', $this->product);

        $removeRelation->handle($this->product, $relationId);
        unset($this->relations, $this->results);

        $this->dispatch('toast', title: __('admin.toast.deleted'), body: __('admin.products.relations.removed'));
    }

    public function render(): View
    {
        return view('livewire.admin.products.relations', [
            'canEdit' => auth()->user()->can('update', $this->product),
        ]);
    }
}
