<?php

namespace App\Livewire\Forms\Admin\News;

use App\Enums\NewsStatus;
use App\Models\NewsPost;
use App\Rules\BrandSafeText;
use App\Support\Slug;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;
use Throwable;

/**
 * State and validation of the news post form. The component owns the post
 * ($this->component->newsPost); saving goes through SaveNewsPost and
 * UpdateCatalogImage (cover). published_at is typed in Tashkent time
 * ("Y-m-d\TH:i", datetime-local) and handed to the action in UTC.
 */
class NewsPostForm extends Form
{
    public const DATE_FORMAT = 'Y-m-d\TH:i';

    /**
     * Accepted publication dates (exclusive): typos such as year 0202 or 20266 are refused.
     */
    public const DATE_MIN = '2000-01-01';

    public const DATE_MAX = '2100-01-01';

    /**
     * Most related products / categories per post.
     */
    public const MAX_RELATED = 20;

    /** @var array<string, string> */
    public array $title = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $excerpt = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $body = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $meta_title = ['uz' => '', 'ru' => '', 'en' => ''];

    /** @var array<string, string> */
    public array $meta_description = ['uz' => '', 'ru' => '', 'en' => ''];

    /**
     * Stored slug, display only: never validated nor sent to the action
     * (generated once from the uz title, then fixed).
     */
    public string $slug = '';

    public string $status = 'draft';

    /**
     * Tashkent time, "Y-m-d\TH:i"; empty = now when published.
     */
    public string $published_at = '';

    /** @var list<int> */
    public array $product_ids = [];

    /** @var list<int> */
    public array $category_ids = [];

    /** @var TemporaryUploadedFile|null */
    public $image = null;

    /** @var array<string, string> */
    public array $image_alt = ['uz' => '', 'ru' => '', 'en' => ''];

    public bool $remove_image = false;

    public function setPost(NewsPost $post): void
    {
        $post->loadMissing(['media', 'products:id', 'categories:id']);

        $this->title = $this->locales($post->getTranslations('title'));
        $this->excerpt = $this->locales($post->getTranslations('excerpt'));
        $this->body = $this->locales($post->getTranslations('body'));
        $this->meta_title = $this->locales($post->getTranslations('meta_title'));
        $this->meta_description = $this->locales($post->getTranslations('meta_description'));
        $this->slug = $post->slug;
        $this->status = $post->status->value;
        $this->published_at = $post->localPublishedAt()?->format(self::DATE_FORMAT) ?? '';
        $this->product_ids = $post->products->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $this->category_ids = $post->categories->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $this->image = null;
        $this->image_alt = $post->imageAlts();
        $this->remove_image = false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'title.uz' => ['required', 'string', 'max:255', new BrandSafeText],
            'title.ru' => ['nullable', 'string', 'max:255', new BrandSafeText],
            'title.en' => ['nullable', 'string', 'max:255', new BrandSafeText],
            'excerpt.*' => ['nullable', 'string', 'max:500', new BrandSafeText],
            'body.*' => ['nullable', 'string', 'max:'.NewsPost::BODY_MAX],
            'meta_title.*' => ['nullable', 'string', 'max:255', new BrandSafeText],
            'meta_description.*' => ['nullable', 'string', 'max:500', new BrandSafeText],
            'status' => ['required', Rule::enum(NewsStatus::class)],
            'published_at' => ['nullable', 'date_format:'.self::DATE_FORMAT, 'after:'.self::DATE_MIN, 'before:'.self::DATE_MAX],
            'product_ids' => ['array', 'max:'.self::MAX_RELATED],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'category_ids' => ['array', 'max:'.self::MAX_RELATED],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'image_alt.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'published_at.after' => __('admin.news.errors.published_at_range'),
            'published_at.before' => __('admin.news.errors.published_at_range'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            'status' => __('admin.news.fields.status'),
            'published_at' => __('admin.news.fields.published_at'),
            'product_ids' => __('admin.news.fields.products'),
            'category_ids' => __('admin.news.fields.categories'),
            'image' => __('admin.news.fields.cover'),
        ];

        foreach (config('app.supported_locales') as $locale) {
            $suffix = ' ('.strtoupper($locale).')';

            foreach (['title', 'excerpt', 'body', 'meta_title', 'meta_description'] as $field) {
                $attributes["{$field}.{$locale}"] = __("admin.news.fields.{$field}").$suffix;
            }

            $attributes["image_alt.{$locale}"] = __('admin.image_upload.alt').$suffix;
        }

        return $attributes;
    }

    /**
     * Slug SaveNewsPost generates from the uz title on create (live preview while the record has none).
     */
    public function autoSlug(): string
    {
        return Slug::make($this->title['uz'] ?? '');
    }

    /**
     * The typed date (Tashkent) in UTC, or null when empty or invalid.
     */
    public function publishedAtUtc(): ?CarbonInterface
    {
        if (trim($this->published_at) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat(self::DATE_FORMAT, $this->published_at, NewsPost::TIMEZONE)?->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The date for SaveNewsPost: the stored one (with its seconds) while the
     * typed minute is unchanged, else the typed one in UTC.
     */
    public function publishedAtForSave(): ?CarbonInterface
    {
        $stored = $this->component->newsPost?->published_at;

        if ($stored !== null && $this->component->newsPost->localPublishedAt()?->format(self::DATE_FORMAT) === trim($this->published_at)) {
            return $stored;
        }

        return $this->publishedAtUtc();
    }

    /**
     * Input for SaveNewsPost.
     *
     * @return array{title: array<string, string>, excerpt: array<string, string>, body: array<string, string>, meta_title: array<string, string>, meta_description: array<string, string>, status: string, published_at: CarbonInterface|null, product_ids: list<int>, category_ids: list<int>}
     */
    public function postData(): array
    {
        return [
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'body' => $this->body,
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'status' => $this->status,
            'published_at' => $this->publishedAtForSave(),
            'product_ids' => array_values(array_map('intval', $this->product_ids)),
            'category_ids' => array_values(array_map('intval', $this->category_ids)),
        ];
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string>
     */
    private function locales(array $values): array
    {
        $result = [];

        foreach (config('app.supported_locales') as $locale) {
            $result[$locale] = (string) ($values[$locale] ?? '');
        }

        return $result;
    }
}
