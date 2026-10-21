<?php

namespace App\Livewire\Admin\Products;

use App\Actions\Catalog\AddProductImages;
use App\Actions\Catalog\UpdateProductImages;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Models\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Product images (child of the product form, after the first save):
 * multi-upload (JPG/PNG/WebP ≤ 5 MB, stored right away in one transaction),
 * drag sort (the first image is the main one), alt text per locale, delete.
 */
class Images extends Component
{
    use HandlesActionErrors, WithFileUploads;

    public Product $product;

    /** @var list<TemporaryUploadedFile> */
    public array $uploads = [];

    /**
     * Alt text being edited: media id => locale => text.
     *
     * @var array<int, array<string, string>>
     */
    public array $alts = [];

    public function mount(Product $product): void
    {
        $this->authorize('view', $product);
        $this->product = $product;
        $this->fillAlts();
    }

    /**
     * New files are validated and stored as soon as they are uploaded.
     */
    public function updatedUploads(AddProductImages $addImages): void
    {
        $this->authorize('update', $this->product);

        $this->validate([
            'uploads' => ['array', 'max:20'],
            'uploads.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [], ['uploads.*' => __('admin.image_upload.image')]);

        $count = count($this->uploads);
        $this->runFormAction(fn () => $addImages->handle($this->product, $this->uploads), '');
        $this->uploads = [];

        $this->refreshImages();
        $this->dispatch('toast', title: __('admin.toast.saved'), body: trans_choice('admin.products.images.added', $count, ['count' => $count]));
    }

    /**
     * Drag and drop (wire:sort): move an image to $position (0 = main image).
     */
    public function sortImage(int|string $id, int|string $position, UpdateProductImages $images): void
    {
        $this->authorize('update', $this->product);

        $images->move($this->product, (int) $id, (int) $position);
        $this->refreshImages();
    }

    /**
     * Arrow buttons on phones: one place earlier (-1) or later (+1).
     */
    public function moveImage(int $id, int $direction, UpdateProductImages $images): void
    {
        $this->authorize('update', $this->product);

        $index = $this->images->search(fn ($media): bool => $media->id === $id);

        if ($index !== false) {
            $images->move($this->product, $id, $index + ($direction < 0 ? -1 : 1));
            $this->refreshImages();
        }
    }

    public function saveAlt(int $id, UpdateProductImages $images): void
    {
        $this->authorize('update', $this->product);

        $this->validate(["alts.{$id}.*" => ['nullable', 'string', 'max:255']]);

        $images->setAlt($this->product, $id, $this->alts[$id] ?? []);
        $this->refreshImages();
        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.products.images.alt_saved'));
    }

    public function deleteImage(int $id, UpdateProductImages $images): void
    {
        $this->authorize('update', $this->product);

        $images->delete($this->product, $id);
        $this->refreshImages();
        $this->dispatch('toast', title: __('admin.toast.deleted'), body: __('admin.products.images.deleted'));
    }

    /**
     * @return Collection<int, Media>
     */
    #[Computed]
    public function images(): Collection
    {
        return collect($this->product->load('media')->getMedia(Product::IMAGES))->values();
    }

    public function render(): View
    {
        return view('livewire.admin.products.images', [
            'canEdit' => auth()->user()->can('update', $this->product),
        ]);
    }

    private function refreshImages(): void
    {
        unset($this->images);
        $this->fillAlts();
    }

    private function fillAlts(): void
    {
        $this->alts = [];

        foreach ($this->images as $media) {
            $alt = (array) ($media->getCustomProperty('alt') ?? []);
            $this->alts[$media->id] = collect(config('app.supported_locales'))
                ->mapWithKeys(fn (string $locale): array => [$locale => (string) ($alt[$locale] ?? '')])
                ->all();
        }
    }
}
