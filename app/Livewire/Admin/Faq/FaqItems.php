<?php

namespace App\Livewire\Admin\Faq;

use App\Actions\Faq\DeleteFaqItem;
use App\Actions\Faq\MoveFaqItem;
use App\Actions\Faq\SaveFaqItem;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Faq\FaqItemForm;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * FAQ editor (plan 04 T12), a child component of the category, product and
 * page forms (after the first save), and of Settings → Company → FAQ
 * (no owner = the general FAQ). Add, edit, delete and reorder; question and
 * plain-text answer per locale.
 *
 * Access follows the owner (FaqItemPolicy): viewing on mount, managing
 * before every write. Writes go through App\Actions\Faq\*.
 */
class FaqItems extends Component
{
    use HandlesActionErrors;

    #[Locked]
    public ?Category $category = null;

    #[Locked]
    public ?Product $product = null;

    #[Locked]
    public ?Page $page = null;

    public FaqItemForm $form;

    /**
     * The add / edit editor is open.
     */
    public bool $editing = false;

    public function mount(?Category $category = null, ?Product $product = null, ?Page $page = null): void
    {
        $this->category = $category?->exists ? $category : null;
        $this->product = $this->category === null && $product?->exists ? $product : null;
        $this->page = $this->category === null && $this->product === null && $page?->exists ? $page : null;

        $this->authorize('viewAny', [FaqItem::class, $this->owner()]);
    }

    /**
     * @return Collection<int, FaqItem>
     */
    #[Computed]
    public function items(): Collection
    {
        return FaqItem::query()->ownedBy($this->owner())->ordered()->get();
    }

    public function create(): void
    {
        $this->authorize('create', [FaqItem::class, $this->owner()]);

        $this->form->setItem(null);
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $item = $this->find($id);
        $this->authorize('update', $item);

        $this->form->setItem($item);
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->form->setItem(null);
        $this->editing = false;
    }

    public function save(SaveFaqItem $saveItem): void
    {
        $item = $this->form->id !== null ? $this->find($this->form->id) : null;
        $item !== null
            ? $this->authorize('update', $item)
            : $this->authorize('create', [FaqItem::class, $this->owner()]);

        $this->form->validate();

        $this->runFormAction(fn (): FaqItem => $saveItem->handle($this->owner(), $item, $this->form->itemData()));

        $this->form->setItem(null);
        $this->editing = false;
        unset($this->items);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.faq.saved'), type: 'success');
    }

    public function move(int $id, int $direction, MoveFaqItem $moveItem): void
    {
        $item = $this->find($id);
        $this->authorize('update', $item);

        $moveItem->handle($item, $direction);
        unset($this->items);
    }

    public function delete(int $id, DeleteFaqItem $deleteItem): void
    {
        $item = $this->find($id);
        $this->authorize('delete', $item);

        $deleteItem->handle($item);

        if ($this->form->id === $id) {
            $this->cancel();
        }

        unset($this->items);

        $this->dispatch('toast', title: __('admin.toast.deleted'), body: __('admin.faq.deleted'));
    }

    public function render(): View
    {
        return view('livewire.admin.faq.faq-items', [
            'canEdit' => (bool) auth()->user()?->can('manage', [FaqItem::class, $this->owner()]),
            'isGeneral' => $this->owner() === null,
        ]);
    }

    protected function owner(): ?Model
    {
        return $this->category ?? $this->product ?? $this->page;
    }

    private function find(int $id): FaqItem
    {
        return FaqItem::query()->ownedBy($this->owner())->findOrFail($id);
    }
}
