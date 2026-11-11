<?php

namespace App\Livewire\Admin\Banners;

use App\Actions\Banners\DeleteBanner;
use App\Actions\Banners\SaveBanner;
use App\Enums\AiFieldKind;
use App\Enums\BannerPlacement;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAiFill;
use App\Livewire\Forms\Admin\Banners\BannerForm;
use App\Models\Banner;
use App\Support\Catalog\CategoryTree;
use App\Support\Content\AiFill\AiField;
use App\Support\Content\ContentLimits;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Create / edit a banner (plan 07 7c): placement (home slider or a
 * category, with the category picker), title, text and button label per
 * locale, link (internal path or https URL), Tashkent-time date window,
 * active switch, and the desktop (required) and mobile images with alt per
 * locale. Everything is saved by SaveBanner in one transaction.
 */
#[Layout('layouts::admin')]
class Form extends Component
{
    use HandlesActionErrors, WithAiFill, WithFileUploads;

    #[Locked]
    public ?Banner $banner = null;

    public BannerForm $form;

    public function mount(?Banner $banner = null): void
    {
        if ($banner?->exists) {
            $this->authorize('view', $banner);
            $this->banner = $banner;
            $this->form->setBanner($banner);

            return;
        }

        $this->authorize('create', Banner::class);
    }

    /**
     * Validate a new upload right away (type, size, dimensions), before saving.
     */
    public function updatedFormDesktop(): void
    {
        $this->validateOnly('form.desktop');
    }

    public function updatedFormMobile(): void
    {
        $this->validateOnly('form.mobile');
    }

    /**
     * The desktop image is required: only a new upload can be cleared.
     */
    public function removeDesktop(): void
    {
        $this->form->desktop = null;
    }

    /**
     * Clear a new mobile upload, or mark the saved one for removal on save.
     */
    public function removeMobile(): void
    {
        if ($this->form->mobile !== null) {
            $this->form->mobile = null;

            return;
        }

        $this->form->remove_mobile = true;
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

    public function save(SaveBanner $saveBanner): void
    {
        if ($this->banner) {
            $this->authorize('update', $this->banner);
        } else {
            $this->authorize('create', Banner::class);
        }

        if ($this->form->placement !== BannerPlacement::Category->value) {
            $this->form->category_id = '';
        }

        $this->form->validate();

        $creating = $this->banner === null;

        $banner = $this->runFormAction(fn (): Banner => $saveBanner->handle($this->banner, $this->form->bannerData(), $this->form->imageData()));

        if ($creating) {
            session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('admin.banners.created', ['name' => (string) $banner->title]), 'type' => 'success']);
            $this->redirectRoute('admin.banners.edit', $banner, navigate: true);

            return;
        }

        $this->banner = $banner->fresh();
        $this->form->setBanner($this->banner);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.banners.saved', ['name' => (string) $banner->title]));
    }

    public function delete(DeleteBanner $deleteBanner): void
    {
        if ($this->banner === null) {
            return;
        }

        $this->authorize('delete', $this->banner);

        if ($this->runToastAction(fn () => $deleteBanner->handle($this->banner))) {
            session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => (string) $this->banner->title, 'type' => 'success']);
            $this->redirectRoute('admin.banners.index', navigate: true);
        }
    }

    /**
     * @return list<AiField>
     */
    protected function aiFields(): array
    {
        return [
            new AiField('title', 'admin.banners.fields.title', AiFieldKind::Plain, Banner::TITLE_MAX, uzRequired: true, hint: 'Short headline on the banner.'),
            new AiField('text', 'admin.banners.fields.text', AiFieldKind::Plain, Banner::TEXT_MAX, hint: 'One short supporting sentence.', multiline: true),
            new AiField('button_label', 'admin.banners.fields.button_label', AiFieldKind::Plain, Banner::BUTTON_MAX, hint: 'Button text, one to three words.'),
            new AiField('desktop_alt', 'admin.banners.fields.desktop_alt', AiFieldKind::Plain, ContentLimits::SHORT_TEXT_MAX, hint: 'Alt text of the desktop banner image.'),
            new AiField('mobile_alt', 'admin.banners.fields.mobile_alt', AiFieldKind::Plain, ContentLimits::SHORT_TEXT_MAX, hint: 'Alt text of the mobile banner image.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function aiContext(): array
    {
        return [
            'placement' => BannerPlacement::tryFrom($this->form->placement)?->value,
        ];
    }

    protected function aiSubject(): string
    {
        return 'website banner';
    }

    protected function authorizeAiFill(): void
    {
        if ($this->banner) {
            $this->authorize('update', $this->banner);
        } else {
            $this->authorize('create', Banner::class);
        }
    }

    public function render(): View
    {
        $banner = $this->banner;
        $title = $banner ? (string) $banner->title : __('admin.banners.new');

        return view('livewire.admin.banners.form', [
            'title' => $title,
            'placements' => collect(BannerPlacement::cases())->mapWithKeys(fn (BannerPlacement $placement) => [$placement->value => $placement->label()])->all(),
            'desktopUrl' => $banner?->adminImageUrl('thumb'),
            'desktopProcessing' => $this->form->desktop === null && (bool) $banner?->bannerImageProcessing(Banner::DESKTOP),
            'mobileUrl' => $this->form->remove_mobile ? null : $banner?->adminImageUrl('mobile'),
            'mobileProcessing' => ! $this->form->remove_mobile && $this->form->mobile === null && (bool) $banner?->bannerImageProcessing(Banner::MOBILE),
            'canSave' => $banner ? auth()->user()->can('update', $banner) : true,
            'canDelete' => $banner && auth()->user()->can('delete', $banner),
            'timezone' => Banner::TIMEZONE,
        ])->title($title);
    }
}
