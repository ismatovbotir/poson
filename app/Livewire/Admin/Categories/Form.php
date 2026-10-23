<?php

namespace App\Livewire\Admin\Categories;

use App\Actions\Catalog\DeleteCategory;
use App\Actions\Catalog\SaveCategory;
use App\Actions\Catalog\SyncCategoryAttributes;
use App\Actions\Catalog\UpdateCatalogImage;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAiAssist;
use App\Livewire\Forms\Admin\Categories\CategoryForm;
use App\Models\Attribute;
use App\Models\Category;
use App\Support\Ai\AiContext;
use App\Support\Ai\CategoryAiContext;
use App\Support\Catalog\CategoryTree;
use App\Support\Search;
use App\Support\Slug;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Create / edit a category: content in uz/ru/en, placement in the tree,
 * slug, icon, image and the category's attributes (own ones editable,
 * inherited ones read-only with their source category).
 */
#[Layout('layouts::admin')]
class Form extends Component
{
    use HandlesActionErrors, WithAiAssist, WithFileUploads;

    public ?Category $category = null;

    public CategoryForm $form;

    /**
     * Search in the "attach from library" picker.
     */
    public string $attributeSearch = '';

    public function mount(?Category $category = null): void
    {
        if ($category?->exists) {
            $this->authorize('view', $category);
            $this->category = $category;
            $this->form->setCategory($category);

            return;
        }

        $this->authorize('create', Category::class);

        $parentId = (int) request()->query('parent');

        if ($parentId > 0 && CategoryTree::find($parentId) !== null && CategoryTree::parentError(null, $parentId) === null) {
            $this->form->parent_id = (string) $parentId;
        }
    }

    /**
     * Allowed parents: every category except this one, its subtree and
     * levels that would exceed the maximum depth.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function parentOptions(): array
    {
        $options = [];

        foreach (CategoryTree::flattened() as $node) {
            if (CategoryTree::parentError($this->category?->id, $node['id']) === null) {
                $options[$node['id']] = str_repeat('— ', $node['depth'] - 1).CategoryTree::name($node['id']);
            }
        }

        return $options;
    }

    /**
     * Attributes inherited from the selected parent and its ancestors, nearest
     * first, each attribute once (from the nearest category that assigns it).
     *
     * @return list<array{category_id: int, name: string, attributes: list<Attribute>}>
     */
    #[Computed]
    public function inheritedGroups(): array
    {
        $parentId = filled($this->form->parent_id) ? (int) $this->form->parent_id : null;

        if ($parentId === null || CategoryTree::find($parentId) === null) {
            return [];
        }

        $chain = [$parentId, ...CategoryTree::ancestorIds($parentId)];
        $categories = Category::query()->whereIn('id', $chain)->with('attributes')->get()->keyBy('id');
        $seen = [];
        $groups = [];

        foreach ($chain as $categoryId) {
            $attributes = [];

            foreach ($categories->get($categoryId)?->getRelationValue('attributes') ?? [] as $attribute) {
                if (! isset($seen[$attribute->id])) {
                    $seen[$attribute->id] = true;
                    $attributes[] = $attribute;
                }
            }

            if ($attributes !== []) {
                $groups[] = ['category_id' => $categoryId, 'name' => CategoryTree::name($categoryId), 'attributes' => $attributes];
            }
        }

        return $groups;
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function inheritedIds(): array
    {
        return collect($this->inheritedGroups)->flatMap(fn (array $group): array => array_map(fn (Attribute $attribute): int => $attribute->id, $group['attributes']))->all();
    }

    /**
     * Library attributes of the own rows, keyed by id.
     *
     * @return Collection<int, Attribute>
     */
    #[Computed]
    public function specAttributes(): Collection
    {
        return Attribute::query()->whereIn('id', array_column($this->form->specs, 'attribute_id'))->get()->keyBy('id');
    }

    /**
     * Picker results: library attributes not assigned yet (own or inherited).
     *
     * @return Collection<int, Attribute>
     */
    #[Computed]
    public function attributeResults(): Collection
    {
        $exclude = [...array_column($this->form->specs, 'attribute_id'), ...$this->inheritedIds];

        return Attribute::query()
            ->withCount('categories')
            ->whereNotIn('id', $exclude)
            ->when(trim($this->attributeSearch) !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw("lower(code) like ? escape '!'", [Search::likePattern($this->attributeSearch)])
                ->orWhere(fn ($names) => Search::orWhereTranslatable($names, 'name', $this->attributeSearch))))
            ->orderBy('sort')
            ->orderBy('id')
            ->limit(20)
            ->get();
    }

    /**
     * Order in which a new product of this category gets its attributes:
     * inherited ones (nearest ancestor first), then the category's own.
     *
     * @return list<array{name: string, required: bool, filter: bool}>
     */
    #[Computed]
    public function productFormPreview(): array
    {
        $rows = [];

        foreach ($this->inheritedGroups as $group) {
            foreach ($group['attributes'] as $attribute) {
                $rows[] = ['name' => $attribute->name, 'required' => $attribute->pivot->is_required, 'filter' => $attribute->pivot->is_filterable];
            }
        }

        foreach ($this->form->specs as $row) {
            $attribute = $this->specAttributes->get((int) $row['attribute_id']);

            if ($attribute !== null && ! in_array($attribute->id, $this->inheritedIds, true)) {
                $rows[] = ['name' => $attribute->name, 'required' => (bool) $row['is_required'], 'filter' => (bool) $row['is_filterable']];
            }
        }

        return $rows;
    }

    public function attachAttribute(int $id): void
    {
        $attribute = Attribute::query()->find($id);

        if ($attribute === null || in_array($id, array_column($this->form->specs, 'attribute_id'), true) || in_array($id, $this->inheritedIds, true)) {
            return;
        }

        $this->form->specs[] = [
            'attribute_id' => $attribute->id,
            'is_required' => false,
            'is_filterable' => $attribute->type->canBeFilterable() && $attribute->is_filterable,
        ];

        $this->attributeSearch = '';
        unset($this->specAttributes, $this->attributeResults);
    }

    public function detachAttribute(int $id): void
    {
        $this->form->specs = array_values(array_filter($this->form->specs, fn (array $row): bool => (int) $row['attribute_id'] !== $id));
        unset($this->specAttributes, $this->attributeResults);
    }

    /**
     * Arrow buttons: move an own attribute one place up (-1) or down (+1).
     */
    public function moveAttribute(int $id, int $direction): void
    {
        $index = array_search($id, array_map('intval', array_column($this->form->specs, 'attribute_id')), true);

        if ($index !== false) {
            $this->sortAttribute($id, $index + ($direction < 0 ? -1 : 1));
        }
    }

    /**
     * Drag and drop (wire:sort) of an own attribute to $position.
     */
    public function sortAttribute(int|string $id, int|string $position): void
    {
        $rows = $this->form->specs;
        $index = array_search((int) $id, array_map('intval', array_column($rows, 'attribute_id')), true);

        if ($index === false) {
            return;
        }

        [$row] = array_splice($rows, $index, 1);
        array_splice($rows, max(0, min((int) $position, count($rows))), 0, [$row]);
        $this->form->specs = array_values($rows);
    }

    /**
     * Validate a new upload right away (type and size), before saving.
     */
    public function updatedFormImage(): void
    {
        $this->validateOnly('form.image');
    }

    /**
     * Clear a new upload, or mark the saved image for removal on save.
     */
    public function removeImage(): void
    {
        if ($this->form->image !== null) {
            $this->form->image = null;

            return;
        }

        $this->form->remove_image = true;
    }

    public function save(SaveCategory $saveCategory, SyncCategoryAttributes $syncAttributes, UpdateCatalogImage $updateImage): void
    {
        if ($this->category) {
            $this->authorize('update', $this->category);
        } else {
            $this->authorize('create', Category::class);
        }

        $this->form->slug = Slug::make($this->form->slug);
        $this->form->validate();

        $creating = $this->category === null;

        // All or nothing: a rejected image rolls back the category and its attributes.
        $category = $this->runFormAction(fn (): Category => DB::transaction(function () use ($saveCategory, $syncAttributes, $updateImage): Category {
            $category = $saveCategory->handle($this->category, $this->form->categoryData());
            $syncAttributes->handle($category, $this->form->specRows());
            $updateImage->handle($category, $this->form->image, $this->form->image_alt, $this->form->remove_image);

            return $category;
        }));

        if ($creating) {
            session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('admin.categories.created', ['name' => $category->name]), 'type' => 'success']);
            $this->redirectRoute('admin.categories.edit', $category, navigate: true);

            return;
        }

        $this->category = $category->fresh();
        $this->form->setCategory($this->category);
        unset($this->parentOptions, $this->inheritedGroups, $this->inheritedIds, $this->specAttributes, $this->attributeResults);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.categories.saved', ['name' => $category->name]));
    }

    public function delete(DeleteCategory $deleteCategory): void
    {
        if ($this->category === null) {
            return;
        }

        $this->authorize('delete', $this->category);

        if ($this->runToastAction(fn () => $deleteCategory->handle($this->category))) {
            session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => $this->category->name, 'type' => 'success']);
            $this->redirectRoute('admin.categories.index', navigate: true);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function aiTargets(): array
    {
        return [
            'form.name' => 'category.name',
            'form.description' => 'category.description',
            'form.meta_title' => 'category.meta_title',
            'form.meta_description' => 'category.meta_description',
        ];
    }

    protected function aiContext(string $fieldKey): AiContext
    {
        return CategoryAiContext::fromForm($this->form, $this->category);
    }

    public function render(): View
    {
        $title = $this->category ? $this->category->name : __('admin.categories.new');

        return view('livewire.admin.categories.form', [
            'title' => $title,
            'path' => $this->category ? CategoryTree::path($this->category->id) : null,
            'currentImageUrl' => $this->form->remove_image ? null : $this->category?->imageUrl(),
            'imageProcessing' => ! $this->form->remove_image && $this->form->image === null && (bool) $this->category?->imageProcessing(),
            'canSave' => $this->category ? auth()->user()->can('update', $this->category) : true,
            'canDelete' => $this->category && auth()->user()->can('delete', $this->category),
        ])->title($title);
    }
}
