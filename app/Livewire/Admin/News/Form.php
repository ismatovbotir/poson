<?php

namespace App\Livewire\Admin\News;

use App\Actions\Catalog\UpdateCatalogImage;
use App\Actions\News\DeleteNewsPost;
use App\Actions\News\SaveNewsPost;
use App\Enums\NewsKind;
use App\Enums\NewsStatus;
use App\Livewire\Admin\Concerns\HandlesActionErrors;
use App\Livewire\Admin\Concerns\WithAiAssist;
use App\Livewire\Forms\Admin\News\NewsPostForm;
use App\Models\Category;
use App\Models\NewsPost;
use App\Models\Product;
use App\Support\Ai\AiContext;
use App\Support\Ai\NewsAiContext;
use App\Support\Catalog\CategoryTree;
use App\Support\Search;
use App\Support\Slug;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Create / edit a news post (plan 07 7b): title, excerpt and rich-text body
 * per locale, SEO texts, slug (from the uz title when empty), status and
 * publication date (Tashkent time; a future date schedules the post), cover
 * image with alt per locale, and the related products and categories
 * (search pickers, saved with the post).
 *
 * SI assist on title, excerpt, body and SEO; the subject and the related
 * names are resolved server-side (NewsAiContext).
 *
 * The kind is fixed by the screen (kind()): App\Livewire\Admin\Articles\Form
 * creates and edits articles; a post of the other kind is a 404 here.
 */
#[Layout('layouts::admin')]
class Form extends Component
{
    use HandlesActionErrors, WithAiAssist, WithFileUploads;

    /**
     * Picker results shown per search.
     */
    public const PICKER_LIMIT = 10;

    #[Locked]
    public ?NewsPost $newsPost = null;

    public NewsPostForm $form;

    public string $productSearch = '';

    public string $categorySearch = '';

    /**
     * The kind this screen creates and edits.
     */
    protected function kind(): NewsKind
    {
        return NewsKind::News;
    }

    public function mount(?NewsPost $newsPost = null): void
    {
        if ($newsPost?->exists) {
            $this->authorize('view', $newsPost);
            abort_unless($newsPost->kind === $this->kind(), 404);
            $this->newsPost = $newsPost;
            $this->form->setPost($newsPost);

            return;
        }

        $this->authorize('create', NewsPost::class);
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

    public function addProduct(int $id): void
    {
        if (count($this->form->product_ids) >= NewsPostForm::MAX_RELATED || in_array($id, $this->form->product_ids, true)) {
            return;
        }

        if (Product::query()->whereKey($id)->exists()) {
            $this->form->product_ids[] = $id;
        }

        $this->productSearch = '';
    }

    public function removeProduct(int $id): void
    {
        $this->form->product_ids = array_values(array_filter($this->form->product_ids, fn (int $productId): bool => $productId !== $id));
    }

    public function addCategory(int $id): void
    {
        if (count($this->form->category_ids) >= NewsPostForm::MAX_RELATED || in_array($id, $this->form->category_ids, true)) {
            return;
        }

        if (Category::query()->whereKey($id)->exists()) {
            $this->form->category_ids[] = $id;
        }

        $this->categorySearch = '';
    }

    public function removeCategory(int $id): void
    {
        $this->form->category_ids = array_values(array_filter($this->form->category_ids, fn (int $categoryId): bool => $categoryId !== $id));
    }

    /**
     * The chosen products, in the order they were added.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function selectedProducts(): Collection
    {
        if ($this->form->product_ids === []) {
            return new Collection;
        }

        $products = Product::query()->whereKey($this->form->product_ids)->get(['id', 'sku', 'name', 'is_active'])->keyBy('id');

        return new Collection(array_values(array_filter(array_map(fn (int $id) => $products->get($id), $this->form->product_ids))));
    }

    /**
     * Product picker: SKU, model or name (any locale), not chosen yet.
     *
     * @return Collection<int, Product>
     */
    #[Computed]
    public function productResults(): Collection
    {
        if (mb_strlen(trim($this->productSearch)) < 2) {
            return new Collection;
        }

        return Product::query()
            ->whereNotIn('id', $this->form->product_ids)
            ->whereRaw("search_text like ? escape '!'", [Search::normalizedPattern($this->productSearch)])
            ->orderByDesc('is_active')
            ->orderBy('sku')
            ->limit(self::PICKER_LIMIT)
            ->get(['id', 'sku', 'name', 'is_active']);
    }

    /**
     * Category picker: name in any locale or slug, not chosen yet.
     *
     * @return list<array{id: int, path: string, active: bool}>
     */
    #[Computed]
    public function categoryResults(): array
    {
        $term = trim($this->categorySearch);

        if (mb_strlen($term) < 2) {
            return [];
        }

        return Category::query()
            ->whereNotIn('id', $this->form->category_ids)
            ->where(fn ($query) => $query
                ->whereRaw("lower(slug) like ? escape '!'", [Search::likePattern($term)])
                ->orWhere(fn ($names) => Search::orWhereTranslatable($names, 'name', $term)))
            ->orderBy('sort')
            ->orderBy('id')
            ->limit(self::PICKER_LIMIT)
            ->pluck('id')
            ->map(fn ($id): array => ['id' => (int) $id, 'path' => CategoryTree::path((int) $id), 'active' => CategoryTree::isVisible((int) $id)])
            ->all();
    }

    /**
     * The chosen categories with their tree path.
     *
     * @return list<array{id: int, path: string, active: bool}>
     */
    #[Computed]
    public function selectedCategories(): array
    {
        $items = [];

        foreach ($this->form->category_ids as $id) {
            if (CategoryTree::find($id) !== null) {
                $items[] = ['id' => $id, 'path' => CategoryTree::path($id), 'active' => CategoryTree::isVisible($id)];
            }
        }

        return $items;
    }

    public function save(SaveNewsPost $savePost, UpdateCatalogImage $updateImage): void
    {
        if ($this->newsPost) {
            $this->authorize('update', $this->newsPost);
        } else {
            $this->authorize('create', NewsPost::class);
        }

        $this->form->slug = Slug::make($this->form->slug);
        $this->form->validate();

        $creating = $this->newsPost === null;

        // All or nothing: a rejected cover rolls the post save back.
        $post = $this->runFormAction(fn (): NewsPost => DB::transaction(function () use ($savePost, $updateImage): NewsPost {
            $post = $savePost->handle($this->newsPost, [...$this->form->postData(), 'kind' => $this->kind()->value], auth()->user());
            $updateImage->handle($post, $this->form->image, $this->form->image_alt, $this->form->remove_image);

            return $post;
        }));

        if ($creating) {
            session()->flash('toast', ['title' => __('admin.toast.saved'), 'body' => $this->kind()->adminText('created', ['name' => (string) $post->title]), 'type' => 'success']);
            $this->redirectRoute($this->kind()->adminRoute('edit'), $post, navigate: true);

            return;
        }

        $this->newsPost = $post->fresh();
        $this->form->setPost($this->newsPost);
        unset($this->selectedProducts, $this->selectedCategories);

        $this->dispatch('toast', title: __('admin.toast.saved'), body: $this->kind()->adminText('saved', ['name' => (string) $post->title]));
    }

    public function delete(DeleteNewsPost $deletePost): void
    {
        if ($this->newsPost === null) {
            return;
        }

        $this->authorize('delete', $this->newsPost);

        if ($this->runToastAction(fn () => $deletePost->handle($this->newsPost))) {
            session()->flash('toast', ['title' => __('admin.toast.deleted'), 'body' => (string) $this->newsPost->title, 'type' => 'success']);
            $this->redirectRoute($this->kind()->adminRoute('index'), navigate: true);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function aiTargets(): array
    {
        return [
            'form.title' => 'news.title',
            'form.excerpt' => 'news.excerpt',
            'form.body' => 'news.body',
            'form.meta_title' => 'news.meta_title',
            'form.meta_description' => 'news.meta_description',
        ];
    }

    protected function aiContext(string $fieldKey): AiContext
    {
        return NewsAiContext::fromForm($this->form, $this->newsPost, $this->kind());
    }

    public function render(): View
    {
        $kind = $this->kind();
        $title = $this->newsPost ? (string) $this->newsPost->title : $kind->adminText('new');
        $post = $this->newsPost;
        $publicLocale = $post !== null && $post->isVisible() ? ($post->isTranslatedIn('uz') ? 'uz' : ((array) $post->locales)[0] ?? null) : null;

        return view('livewire.admin.news.form', [
            'kind' => $kind,
            'title' => $title,
            'statuses' => collect(NewsStatus::cases())->mapWithKeys(fn (NewsStatus $status) => [$status->value => $status->label()])->all(),
            'publicUrl' => $publicLocale !== null ? $post->url($publicLocale) : null,
            'currentImageUrl' => $this->form->remove_image ? null : $post?->imageUrl(),
            'imageProcessing' => ! $this->form->remove_image && $this->form->image === null && (bool) $post?->imageProcessing(),
            'canSave' => $post ? auth()->user()->can('update', $post) : true,
            'canDelete' => $post && auth()->user()->can('delete', $post),
            'timezone' => NewsPost::TIMEZONE,
        ])->title($title);
    }
}
