<?php

namespace App\Livewire\Admin\Products;

use App\Actions\Catalog\AddProductImages;
use App\Actions\Catalog\UpdateProductImages;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAiAssist;
use App\Models\Product;
use App\Support\Ai\AiContext;
use App\Support\Ai\ProductAiContext;
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
 *
 * AI assist for the alt text (plan 12, product.image_alt): offered only to
 * users who may update the product and only for the product's own images
 * (target "alts.{media id}"); the context is the saved product, its saved
 * specs and the image position, never the file. Apply fills the inputs,
 * the manager still saves each alt.
 */
class Images extends Component
{
    use HandlesActionErrors;
    use WithAiAssist {
        openAi as protected openAiFor;
    }
    use WithFileUploads {
        _startUpload as protected startTemporaryUpload;
        _finishUpload as protected finishTemporaryUpload;
    }

    public Product $product;

    /** @var list<TemporaryUploadedFile> */
    public array $uploads = [];

    /**
     * Alt text being edited: media id => locale => text.
     *
     * @var array<int, array<string, string>>
     */
    public array $alts = [];

    /**
     * Target of the openAi() call being handled (per request, never in the snapshot).
     */
    protected ?string $aiTarget = null;

    public function mount(Product $product): void
    {
        $this->authorize('view', $product);
        $this->product = $product;
        $this->fillAlts();
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
     * Open the assistant for one image's alt text (the target says which image).
     */
    public function openAi(string $target, string $locale = 'uz'): void
    {
        $this->aiTarget = $target;

        $this->openAiFor($target, $locale);
    }

    /**
     * "alts.{id}" of the product's own images, only for users who may update it.
     *
     * @return array<string, string>
     */
    protected function aiTargets(): array
    {
        if (! auth()->user()?->can('update', $this->product)) {
            return [];
        }

        return $this->images
            ->mapWithKeys(fn (Media $media): array => ["alts.{$media->id}" => 'product.image_alt'])
            ->all();
    }

    protected function aiContext(string $fieldKey): AiContext
    {
        $context = ProductAiContext::fromProduct($this->product)->toArray();
        $images = $this->images;
        $index = $images->search(fn (Media $media): bool => "alts.{$media->id}" === $this->aiTarget);

        if ($index !== false) {
            $context['facts']['Image'] = ($index + 1).' of '.$images->count().($index === 0 ? ' (main image)' : '');
        }

        $context['specs'] = ProductAiContext::savedSpecs($this->product);

        return AiContext::fromArray($context);
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
