<?php

namespace App\Livewire\Admin\Products;

use App\Actions\Catalog\CreateAttributeOption;
use App\Actions\Catalog\DeleteProduct;
use App\Actions\Catalog\SaveProduct;
use App\Enums\AttributeType;
use App\Enums\ProductType;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAiAssist;
use App\Livewire\Forms\Admin\Products\ProductForm;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Product;
use App\Support\Ai\AiContext;
use App\Support\Ai\ProductAiContext;
use App\Support\Catalog\CategoryTree;
use App\Support\Catalog\ProductAttributeResolver;
use App\Support\Catalog\ResolvedAttribute;
use App\Support\Search;
use App\Support\Slug;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create / edit a product (plan 03 T13): Main (uz/ru/en), Price & ordering,
 * Specs (from the category chain, skip / restore, extras, key specs), SEO
 * and Publish. Images, documents and relations are child components shown
 * once the product exists.
 *
 * A service engineer (products.manage-services) may only pick the software
 * and service types and edit products of those types (ProductPolicy).
 */
#[Layout('layouts::admin')]
class Form extends Component
{
    use HandlesActionErrors, WithAiAssist;

    #[Locked]
    public ?Product $product = null;

    public ProductForm $form;

    /**
     * Search in the "add extra attribute" picker.
     */
    public string $extraSearch = '';

    /**
     * Names of attributes that became extras on the last category change.
     *
     * @var list<string>
     */
    public array $movedToExtras = [];

    public function mount(?Product $product = null): void
    {
        if ($product?->exists) {
            $this->authorize('view', $product);
            $this->product = $product;
            $this->form->setProduct($product, app(ProductAttributeResolver::class)->forProduct($product));

            return;
        }

        $this->authorize('create', Product::class);

        $this->form->type = ($this->typeOptions()[0] ?? ProductType::Hardware)->value;

        $categoryId = (int) request()->query('category');

        if ($categoryId > 0 && CategoryTree::find($categoryId) !== null) {
            $this->form->category_id = (string) $categoryId;
            $this->rebuildSpecs();
        }
    }

    /**
     * Types the user may give a product (service engineer: software, service).
     *
     * @return list<ProductType>
     */
    public function typeOptions(): array
    {
        return array_values(array_filter(
            ProductType::cases(),
            fn (ProductType $type): bool => auth()->user()->can('useType', [Product::class, $type]),
        ));
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
     * Resolved spec rows of the current state, keyed by attribute id.
     *
     * @return array<int, ResolvedAttribute>
     */
    #[Computed]
    public function specMeta(): array
    {
        $categoryId = filled($this->form->category_id) ? (int) $this->form->category_id : null;
        $rows = app(ProductAttributeResolver::class)->resolve($categoryId, [], array_column($this->form->specs, 'attribute_id'));

        return collect($rows)->keyBy(fn (ResolvedAttribute $row): int => $row->attribute->id)->all();
    }

    /**
     * Library attributes for the "add extra attribute" picker.
     *
     * @return Collection<int, Attribute>
     */
    #[Computed]
    public function extraResults(): Collection
    {
        return Attribute::query()
            ->whereNotIn('id', array_column($this->form->specs, 'attribute_id'))
            ->when(trim($this->extraSearch) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw("lower(code) like ? escape '!'", [Search::likePattern($this->extraSearch)])
                ->orWhere(fn ($names) => Search::orWhereTranslatable($names, 'name', $this->extraSearch))))
            ->orderBy('sort')
            ->orderBy('id')
            ->limit(20)
            ->get();
    }

    /**
     * Category change: the new chain's attributes are pre-filled; values of
     * attributes outside the new chain are kept as extras (with a notice);
     * empty ones are dropped. Extras added by hand stay.
     */
    public function updatedFormCategoryId(): void
    {
        $this->rebuildSpecs();
    }

    public function toggleSkip(int $attributeId): void
    {
        $meta = $this->specMeta[$attributeId] ?? null;
        $index = $this->rowIndex($attributeId);

        if ($meta === null || $index === null || $meta->required || $meta->isExtra()) {
            return;
        }

        $skipped = ! $this->form->specs[$index]['skipped'];
        $this->form->specs[$index]['skipped'] = $skipped;

        if ($skipped) {
            $this->form->specs[$index]['is_key'] = false;
        }
    }

    public function toggleKey(int $attributeId): void
    {
        $index = $this->rowIndex($attributeId);

        if ($index === null || $this->form->specs[$index]['skipped']) {
            return;
        }

        if (! $this->form->specs[$index]['is_key'] && $this->keyCount() >= Product::MAX_KEY_SPECS) {
            $this->dispatch('toast', title: __('admin.products.specs.key_limit_title', ['max' => Product::MAX_KEY_SPECS]), body: __('admin.products.specs.key_limit_body', ['max' => Product::MAX_KEY_SPECS]), type: 'error');

            return;
        }

        $this->form->specs[$index]['is_key'] = ! $this->form->specs[$index]['is_key'];
    }

    public function addExtra(int $attributeId): void
    {
        if ($this->rowIndex($attributeId) !== null || ! Attribute::query()->whereKey($attributeId)->exists()) {
            return;
        }

        $this->form->specs[] = ProductForm::blankRow($attributeId, extra: true);
        $this->extraSearch = '';
        unset($this->specMeta, $this->extraResults);
    }

    public function removeExtra(int $attributeId): void
    {
        $meta = $this->specMeta[$attributeId] ?? null;

        if ($meta === null || ! $meta->isExtra()) {
            return;
        }

        $this->form->specs = array_values(array_filter($this->form->specs, fn (array $row): bool => (int) $row['attribute_id'] !== $attributeId));
        unset($this->specMeta, $this->extraResults);
    }

    /**
     * Pick the value of a select spec (0 clears it).
     */
    public function selectOption(int $attributeId, int $optionId): void
    {
        $index = $this->rowIndex($attributeId);
        $meta = $this->specMeta[$attributeId] ?? null;

        if ($index === null || $meta === null || $meta->attribute->type !== AttributeType::Select) {
            return;
        }

        $valid = $optionId === 0 || $meta->attribute->options->contains('id', $optionId);
        $this->form->specs[$index]['option'] = $valid && $optionId > 0 ? (string) $optionId : '';
    }

    /**
     * Add or remove a value of a multiselect spec.
     */
    public function toggleOption(int $attributeId, int $optionId): void
    {
        $index = $this->rowIndex($attributeId);
        $meta = $this->specMeta[$attributeId] ?? null;

        if ($index === null || $meta === null || $meta->attribute->type !== AttributeType::Multiselect || ! $meta->attribute->options->contains('id', $optionId)) {
            return;
        }

        $chosen = array_map('intval', $this->form->specs[$index]['options']);
        $chosen = in_array($optionId, $chosen, true)
            ? array_values(array_diff($chosen, [$optionId]))
            : [...$chosen, $optionId];

        $this->form->specs[$index]['options'] = $chosen;
    }

    /**
     * Inline "new value" of a select / multiselect attribute (needs
     * attributes.update). The option is reusable everywhere; it is selected
     * in the row right away.
     */
    public function createOption(int $attributeId, string $value, CreateAttributeOption $createOption): void
    {
        $attribute = Attribute::query()->findOrFail($attributeId);
        $this->authorize('update', $attribute);

        $index = $this->rowIndex($attributeId);
        $value = mb_substr(trim($value), 0, 255);

        if ($index === null || $value === '') {
            return;
        }

        try {
            $option = $createOption->handle($attribute, ['uz' => $value]);
        } catch (ValidationException $exception) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: collect($exception->errors())->flatten()->first(), type: 'error');

            return;
        }

        if ($attribute->type === AttributeType::Multiselect) {
            $this->form->specs[$index]['options'] = array_values(array_unique([...array_map('intval', $this->form->specs[$index]['options']), $option->id]));
        } else {
            $this->form->specs[$index]['option'] = (string) $option->id;
        }

        unset($this->specMeta);

        $this->dispatch('toast', title: __('admin.products.specs.option_added'), body: __('admin.products.specs.option_added_body', ['value' => $option->value, 'name' => $attribute->name]));
    }

    public function save(SaveProduct $saveProduct): void
    {
        if ($this->product) {
            $this->authorize('update', $this->product);
        } else {
            $this->authorize('create', Product::class);
        }

        $type = ProductType::tryFrom($this->form->type);

        if ($type === null) {
            throw ValidationException::withMessages(['form.type' => __('validation.required', ['attribute' => __('admin.products.fields.type')])]);
        }

        $this->authorize('useType', [Product::class, $type]);

        $this->form->slug = Slug::make($this->form->slug);
        $this->form->sku = mb_strtoupper(trim($this->form->sku));
        $this->form->validate();

        $creating = $this->product === null;

        $product = $this->runFormAction(fn (): Product => $saveProduct->handle($this->product, $this->form->productData(), $this->form->specInput()));

        if ($creating) {
            session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('admin.products.created', ['name' => $product->name]), 'type' => 'success']);
            $this->redirectRoute('admin.products.edit', $product, navigate: true);

            return;
        }

        $this->product = $product->fresh();
        $this->form->setProduct($this->product, app(ProductAttributeResolver::class)->forProduct($this->product));
        $this->movedToExtras = [];
        unset($this->specMeta, $this->extraResults);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.products.saved', ['name' => $this->product->name]));
    }

    public function delete(DeleteProduct $deleteProduct): void
    {
        if ($this->product === null) {
            return;
        }

        $this->authorize('delete', $this->product);

        $deleteProduct->handle($this->product);

        session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => __('admin.products.moved_to_trash', ['name' => $this->product->name]), 'type' => 'success']);
        $this->redirectRoute('admin.products.index', navigate: true);
    }

    /**
     * @return array<string, string>
     */
    protected function aiTargets(): array
    {
        return [
            'form.name' => 'product.name',
            'form.short_description' => 'product.short_description',
            'form.description' => 'product.description',
            'form.meta_title' => 'product.meta_title',
            'form.meta_description' => 'product.meta_description',
        ];
    }

    protected function aiContext(string $fieldKey): AiContext
    {
        return ProductAiContext::fromForm($this->form, $this->product);
    }

    public function render(): View
    {
        $title = $this->product ? $this->product->name : __('admin.products.new');
        $user = auth()->user();

        return view('livewire.admin.products.form', [
            'title' => $title,
            'types' => $this->typeOptions(),
            'keyCount' => $this->keyCount(),
            'missingCount' => $this->missingCount(),
            'canSave' => $this->product ? $user->can('update', $this->product) : true,
            'canDelete' => $this->product && $user->can('delete', $this->product),
            'canCreateOptions' => $user->can('update', Attribute::class),
            'canCreateAttributes' => $user->can('create', Attribute::class),
        ])->title($title);
    }

    /**
     * Required rows (not skipped) without a value.
     */
    private function missingCount(): int
    {
        $missing = 0;

        foreach ($this->form->specs as $row) {
            $meta = $this->specMeta[(int) $row['attribute_id']] ?? null;

            if ($meta !== null && $meta->required && ! ProductForm::rowHasValue($row, $meta->attribute->type)) {
                $missing++;
            }
        }

        return $missing;
    }

    /**
     * Key specs that count towards the limit: marked, not skipped and with a value
     * (SaveProduct ignores the mark on an empty row).
     */
    private function keyCount(): int
    {
        return count(array_filter($this->form->specs, function (array $row): bool {
            $meta = $this->specMeta[(int) $row['attribute_id']] ?? null;

            return $row['is_key'] && ! $row['skipped'] && $meta !== null && ProductForm::rowHasValue($row, $meta->attribute->type);
        }));
    }

    private function rowIndex(int $attributeId): ?int
    {
        foreach ($this->form->specs as $index => $row) {
            if ((int) $row['attribute_id'] === $attributeId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Rebuild the spec rows for the selected category (see updatedFormCategoryId()).
     */
    private function rebuildSpecs(): void
    {
        $categoryId = filled($this->form->category_id) ? (int) $this->form->category_id : null;
        $resolver = app(ProductAttributeResolver::class);
        $old = collect($this->form->specs)->keyBy(fn (array $row): int => (int) $row['attribute_id']);
        $types = Attribute::query()->whereIn('id', $old->keys())->pluck('type', 'id');

        $specs = [];
        $moved = [];

        foreach ($resolver->categoryAttributes($categoryId) as $row) {
            $id = $row->attribute->id;
            $state = $old->get($id) ?? ProductForm::blankRow($id);
            $state['extra'] = false;
            $state['skipped'] = $state['skipped'] && ! $row->required;
            $specs[] = $state;
            $old->forget($id);
        }

        foreach ($old as $id => $state) {
            $type = $types->get($id);
            $hasValue = $type !== null && ProductForm::rowHasValue($state, $type);

            if (! $state['extra'] && ! $hasValue) {
                continue;
            }

            if (! $state['extra']) {
                $moved[] = $id;
            }

            $state['extra'] = true;
            $state['skipped'] = false;
            $specs[] = $state;
        }

        $this->form->specs = $specs;
        $this->movedToExtras = $moved === [] ? [] : Attribute::query()->whereIn('id', $moved)->get()->map(fn (Attribute $attribute): string => $attribute->name)->all();
        unset($this->specMeta, $this->extraResults);
    }
}
