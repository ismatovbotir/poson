<?php

namespace App\Livewire\Admin\Products;

use App\Models\Category;
use App\Models\Product;
use App\Support\Catalog\CategoryTree;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Choose a category" modal for a new product: lists the top-level categories only. The open/closed
 * state lives in Alpine; the server loads the roots after open() and re-validates the clicked id
 * (positive int, a root in the cached tree) before opening the create form. The subcategory is
 * picked later in the form.
 */
class CategoryChooser extends Component
{
    /**
     * Whether the modal was opened at least once (the roots are rendered only then).
     */
    #[Locked]
    public bool $chooserOpen = false;

    public function mount(): void
    {
        $this->authorize('create', Product::class);
    }

    #[On('open-category-chooser')]
    public function open(): void
    {
        $this->authorize('create', Product::class);

        $this->resetErrorBag('category');
        $this->chooserOpen = true;
        $this->dispatch('category-chooser-opened');
    }

    /**
     * Opens the create form with the clicked main category.
     */
    public function choose(mixed $id): mixed
    {
        $this->authorize('create', Product::class);
        $this->resetErrorBag('category');

        $id = match (true) {
            is_int($id) && $id > 0 => $id,
            is_string($id) && strlen($id) <= 10 && ctype_digit($id) && (int) $id > 0 => (int) $id,
            default => null,
        };

        if ($id === null || ! CategoryTree::isRoot($id)) {
            $this->addError('category', __('admin.products.chooser.invalid'));

            return null;
        }

        return $this->redirectRoute('admin.products.create', ['category' => $id], navigate: true);
    }

    /**
     * Top-level categories with a safe icon name. Empty until opened.
     *
     * @return list<array{id: int, name: string, icon: string, active: bool}>
     */
    public function roots(): array
    {
        if (! $this->chooserOpen) {
            return [];
        }

        return array_map(fn (array $node): array => [
            'id' => $node['id'],
            'name' => CategoryTree::name($node['id']),
            'icon' => in_array($node['icon'], Category::ICONS, true) ? $node['icon'] : 'grid',
            'active' => (bool) $node['is_active'],
        ], CategoryTree::children(null));
    }

    public function render(): View
    {
        return view('livewire.admin.products.category-chooser');
    }
}
