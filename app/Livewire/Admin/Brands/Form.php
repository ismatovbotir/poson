<?php

namespace App\Livewire\Admin\Brands;

use App\Actions\Catalog\DeleteBrand;
use App\Actions\Catalog\SaveBrand;
use App\Actions\Catalog\UpdateCatalogImage;
use App\Enums\AiFieldKind;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAiFill;
use App\Livewire\Forms\Admin\Brands\BrandForm;
use App\Models\Brand;
use App\Support\Content\AiFill\AiField;
use App\Support\Content\ContentLimits;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Create / edit a brand: name, slug, description and SEO meta title /
 * description (uz/ru/en), website, logo.
 */
#[Layout('layouts::admin')]
class Form extends Component
{
    use HandlesActionErrors, WithAiFill, WithFileUploads;

    #[Locked]
    public ?Brand $brand = null;

    public BrandForm $form;

    public function mount(?Brand $brand = null): void
    {
        if ($brand?->exists) {
            $this->authorize('view', $brand);
            $this->brand = $brand;
            $this->form->setBrand($brand);

            return;
        }

        $this->authorize('create', Brand::class);
    }

    /**
     * Validate a new upload right away (type and size), before saving.
     */
    public function updatedFormLogo(): void
    {
        $this->validateOnly('form.logo');
    }

    public function removeLogo(): void
    {
        if ($this->form->logo !== null) {
            $this->form->logo = null;

            return;
        }

        $this->form->remove_logo = true;
    }

    public function save(SaveBrand $saveBrand, UpdateCatalogImage $updateImage): void
    {
        if ($this->brand) {
            $this->authorize('update', $this->brand);
        } else {
            $this->authorize('create', Brand::class);
        }

        // The slug is never client input: SaveBrand keeps the stored one or generates it.
        $this->form->slug = (string) $this->brand?->slug;
        $this->form->website = trim($this->form->website);
        $this->form->validate();

        $creating = $this->brand === null;

        // All or nothing: a rejected logo rolls back the brand save.
        $brand = $this->runFormAction(fn (): Brand => DB::transaction(function () use ($saveBrand, $updateImage): Brand {
            $brand = $saveBrand->handle($this->brand, $this->form->brandData());
            $updateImage->handle($brand, $this->form->logo, $this->form->logo_alt, $this->form->remove_logo, 'logo');

            return $brand;
        }));

        if ($creating) {
            session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('admin.brands.created', ['name' => $brand->name]), 'type' => 'success']);
            $this->redirectRoute('admin.brands.edit', $brand, navigate: true);

            return;
        }

        $this->brand = $brand->fresh();
        $this->form->setBrand($this->brand);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.brands.saved', ['name' => $brand->name]));
    }

    public function delete(DeleteBrand $deleteBrand): void
    {
        if ($this->brand === null) {
            return;
        }

        $this->authorize('delete', $this->brand);

        if ($this->runToastAction(fn () => $deleteBrand->handle($this->brand))) {
            session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => $this->brand->name, 'type' => 'success']);
            $this->redirectRoute('admin.brands.index', navigate: true);
        }
    }

    /**
     * @return list<AiField>
     */
    protected function aiFields(): array
    {
        return [
            new AiField('description', 'admin.catalog.description', AiFieldKind::Html, Brand::DESCRIPTION_MAX, hint: 'About the brand and what Poson offers from it (selection, setup, warranty, service).'),
            new AiField('meta_title', 'admin.catalog.meta_title', AiFieldKind::Plain, Brand::META_TITLE_MAX, hint: 'Search result title.'),
            new AiField('meta_description', 'admin.catalog.meta_description', AiFieldKind::Plain, Brand::META_DESCRIPTION_MAX, hint: 'Search result snippet.', multiline: true),
            new AiField('logo_alt', 'admin.image_upload.alt', AiFieldKind::Plain, ContentLimits::SHORT_TEXT_MAX, hint: 'Alt text of the brand logo image.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function aiContext(): array
    {
        return [
            'brand name' => $this->form->name,
            'website' => mb_substr($this->form->website, 0, 200),
        ];
    }

    protected function aiSubject(): string
    {
        return 'brand page';
    }

    protected function authorizeAiFill(): void
    {
        if ($this->brand) {
            $this->authorize('update', $this->brand);
        } else {
            $this->authorize('create', Brand::class);
        }
    }

    public function render(): View
    {
        $title = $this->brand ? $this->brand->name : __('admin.brands.new');

        return view('livewire.admin.brands.form', [
            'title' => $title,
            'autoSlug' => $this->form->autoSlug(),
            'currentLogoUrl' => $this->form->remove_logo ? null : $this->brand?->adminImageUrl(),
            'logoProcessing' => ! $this->form->remove_logo && $this->form->logo === null && (bool) $this->brand?->imageProcessing(),
            'canSave' => $this->brand ? auth()->user()->can('update', $this->brand) : true,
            'canDelete' => $this->brand && auth()->user()->can('delete', $this->brand),
        ])->title($title);
    }
}
