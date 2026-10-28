<?php

namespace App\Livewire\Admin\Pages;

use App\Actions\Catalog\UpdateCatalogImage;
use App\Actions\Pages\DeletePage;
use App\Actions\Pages\SavePage;
use App\Enums\PageTemplate;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Forms\Admin\Pages\PageForm;
use App\Models\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Create / edit a static page (plan 07 7a): title and rich-text body per
 * locale, SEO texts, read-only slug (generated once), template (fixed on
 * system pages), cover image with alt per locale, active and the
 * "lawyer review needed" flag, plus the page FAQ (after the first save).
 */
#[Layout('layouts::admin')]
class Form extends Component
{
    use HandlesActionErrors, WithFileUploads;

    #[Locked]
    public ?Page $page = null;

    public PageForm $form;

    public function mount(?Page $page = null): void
    {
        if ($page?->exists) {
            $this->authorize('view', $page);
            $this->page = $page;
            $this->form->setPage($page);

            return;
        }

        $this->authorize('create', Page::class);
    }

    /**
     * Validate a new upload right away (type and size), before saving.
     */
    public function updatedFormImage(): void
    {
        $this->validateOnly('form.image');
    }

    /**
     * Clear a new upload, or mark the saved cover for removal on save.
     */
    public function removeImage(): void
    {
        if ($this->form->image !== null) {
            $this->form->image = null;

            return;
        }

        $this->form->remove_image = true;
    }

    public function save(SavePage $savePage, UpdateCatalogImage $updateImage): void
    {
        if ($this->page) {
            $this->authorize('update', $this->page);
        } else {
            $this->authorize('create', Page::class);
        }

        // The slug is never client input: SavePage keeps the stored one or generates it.
        $this->form->slug = (string) $this->page?->slug;

        $this->form->validate();

        $creating = $this->page === null;

        // All or nothing: a rejected cover rolls the page save back.
        $page = $this->runFormAction(fn (): Page => DB::transaction(function () use ($savePage, $updateImage): Page {
            $page = $savePage->handle($this->page, $this->form->pageData());
            $updateImage->handle($page, $this->form->image, $this->form->image_alt, $this->form->remove_image);

            return $page;
        }));

        if ($creating) {
            session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => __('admin.pages.created', ['name' => (string) $page->title]), 'type' => 'success']);
            $this->redirectRoute('admin.pages.edit', $page, navigate: true);

            return;
        }

        $this->page = $page->fresh();
        $this->form->setPage($this->page);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: __('admin.pages.saved', ['name' => (string) $page->title]));
    }

    public function delete(DeletePage $deletePage): void
    {
        if ($this->page === null) {
            return;
        }

        $this->authorize('delete', $this->page);

        if ($this->runToastAction(fn () => $deletePage->handle($this->page))) {
            session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => (string) $this->page->title, 'type' => 'success']);
            $this->redirectRoute('admin.pages.index', navigate: true);
        }
    }

    /**
     * The template in effect: fixed on system pages, else the form's choice.
     */
    public function template(): PageTemplate
    {
        return $this->page?->is_system ? $this->page->template : $this->form->templateEnum();
    }

    public function render(): View
    {
        $title = $this->page ? (string) $this->page->title : __('admin.pages.new');
        $template = $this->template();

        return view('livewire.admin.pages.form', [
            'title' => $title,
            'template' => $template,
            'isSystem' => (bool) $this->page?->is_system,
            'publicUrl' => $this->page?->is_active ? route('site.page', ['locale' => config('app.default_locale', 'uz'), 'page' => $this->page->slug]) : null,
            'currentImageUrl' => $this->form->remove_image ? null : $this->page?->imageUrl(),
            'imageProcessing' => ! $this->form->remove_image && $this->form->image === null && (bool) $this->page?->imageProcessing(),
            'canSave' => $this->page ? auth()->user()->can('update', $this->page) : true,
            'canDelete' => $this->page && ! $this->page->is_system && auth()->user()->can('delete', $this->page),
        ])->title($title);
    }
}
