<?php

namespace App\Livewire\Admin\Products;

use App\Actions\Catalog\DeleteProductDocument;
use App\Actions\Catalog\SaveProductDocument;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Products\DocumentForm;
use App\Models\Product;
use App\Models\ProductDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Product documents (child of the product form, after the first save):
 * datasheets, manuals, drivers, ... as a private file (staff download route)
 * or an external https link; type, language and title per locale.
 */
class Documents extends Component
{
    use HandlesActionErrors;
    use WithFileUploads {
        _startUpload as protected startTemporaryUpload;
        _finishUpload as protected finishTemporaryUpload;
    }

    public Product $product;

    public DocumentForm $form;

    /**
     * The add / edit dialog is open.
     */
    public bool $editing = false;

    public function mount(Product $product): void
    {
        $this->authorize('view', $product);
        $this->product = $product;
    }

    /**
     * Uploads are writes: authorize before Livewire accepts the temporary
     * file (the trait's own methods run only for allowed users).
     */
    public function _startUpload($name, $fileInfo, $isMultiple)
    {
        $this->authorize('update', $this->product);

        return $this->startTemporaryUpload($name, $fileInfo, $isMultiple);
    }

    public function _finishUpload($name, $tmpPath, $isMultiple, $append = true)
    {
        $this->authorize('update', $this->product);

        return $this->finishTemporaryUpload($name, $tmpPath, $isMultiple, $append);
    }

    /**
     * @return Collection<int, ProductDocument>
     */
    #[Computed]
    public function documents(): Collection
    {
        return ProductDocument::query()->where('product_id', $this->product->id)->orderBy('sort')->orderBy('id')->get();
    }

    public function create(): void
    {
        $this->authorize('update', $this->product);

        $this->form->setDocument(null);
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('update', $this->product);

        $this->form->setDocument($this->find($id));
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->form->setDocument(null);
        $this->editing = false;
    }

    public function save(SaveProductDocument $saveDocument): void
    {
        $this->authorize('update', $this->product);

        $this->form->url = trim($this->form->url);
        $this->form->validate();

        $document = $this->form->id !== null ? $this->find($this->form->id) : null;
        $file = $this->form->source === 'file' ? $this->form->file : null;

        $this->runFormAction(fn () => $saveDocument->handle($this->product, $document, $this->form->documentData(), $file));

        $this->form->setDocument(null);
        $this->editing = false;
        unset($this->documents);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.products.documents.saved'));
    }

    public function delete(int $id, DeleteProductDocument $deleteDocument): void
    {
        $this->authorize('update', $this->product);

        $deleteDocument->handle($this->find($id));
        unset($this->documents);

        $this->dispatch('toast', title: __('admin.toast.deleted'), body: __('admin.products.documents.deleted'));
    }

    public function render(): View
    {
        return view('livewire.admin.products.documents', [
            'canEdit' => auth()->user()->can('update', $this->product),
        ]);
    }

    private function find(int $id): ProductDocument
    {
        return ProductDocument::query()->where('product_id', $this->product->id)->findOrFail($id);
    }
}
