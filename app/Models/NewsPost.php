<?php

namespace App\Models;

use App\Enums\NewsKind;
use App\Enums\NewsState;
use App\Enums\NewsStatus;
use App\Models\Concerns\HasCatalogImage;
use App\Models\Concerns\StoresTranslations;
use App\Support\Site\NewsDirectory;
use App\Support\Site\StructuredData;
use Database\Factories\NewsPostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Translatable\HasTranslations;

/**
 * News post (plan 07 7b) at /{locale}/news/{slug}, or a knowledge article
 * (kind = article, decision 2026-10-24) at /{locale}/articles/{slug}: title, excerpt, body
 * (sanitized HTML) and SEO texts per locale, an optional cover image
 * (medialibrary "cover", HasCatalogImage: private original, WebP
 * conversions, alt per locale), related products and categories.
 *
 * Public when status = published and published_at <= now (a future date is
 * "scheduled": no cron, the site compares with now()), and only in the
 * locales where both the title and the body are filled in ("locales", kept
 * on every save; decision 2026-10-23: no fallback to uz for news). Other
 * locales get a 404 and are left out of lists, RSS, the sitemap and hreflang.
 *
 * content_updated_at is set by SaveNewsPost when the title, excerpt or body
 * change (dateModified, sitemap lastmod). author_id is not fillable: set
 * once by SaveNewsPost on create. Written only through
 * App\Actions\News\*. Saving or deleting flushes the NewsDirectory (menus),
 * the sitemap and llms.txt after the commit.
 *
 * @property int $id
 * @property string $slug
 * @property NewsKind $kind
 * @property NewsStatus $status
 * @property Carbon|null $published_at
 * @property list<string>|null $locales
 * @property int|null $author_id
 * @property Carbon|null $content_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'kind', 'status', 'published_at', 'title', 'excerpt', 'body', 'meta_title', 'meta_description'])]
class NewsPost extends Model implements HasMedia
{
    /** @use HasFactory<NewsPostFactory> */
    use HasCatalogImage, HasFactory, HasTranslations, StoresTranslations;

    /**
     * Time zone of the admin date input and of the dates shown on the site.
     */
    public const TIMEZONE = 'Asia/Tashkent';

    /**
     * Longest body per locale (characters of HTML).
     */
    public const BODY_MAX = 60000;

    /**
     * @var list<string>
     */
    public array $translatable = ['title', 'excerpt', 'body', 'meta_title', 'meta_description'];

    protected static function booted(): void
    {
        static::saving(function (NewsPost $post): void {
            $post->locales = $post->translatedLocales();
        });

        $flush = function (): void {
            NewsDirectory::flush();
            DB::afterCommit(fn () => NewsDirectory::flushAll());
        };

        static::saved($flush);
        static::deleted($flush);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => NewsKind::class,
            'status' => NewsStatus::class,
            'published_at' => 'datetime',
            'locales' => 'array',
            'content_updated_at' => 'datetime',
        ];
    }

    public function imageCollection(): string
    {
        return 'cover';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * Posts of one kind (news or article).
     *
     * @param  Builder<NewsPost>  $query
     */
    public function scopeOfKind(Builder $query, NewsKind $kind): void
    {
        $query->where('news_posts.kind', $kind->value);
    }

    /**
     * Public URL in the locale (default: the current one), by kind.
     */
    public function url(?string $locale = null): string
    {
        return $this->kind->url($this->slug, $locale);
    }

    /**
     * Published with published_at in the past (in any locale).
     *
     * @param  Builder<NewsPost>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('news_posts.status', NewsStatus::Published->value)
            ->whereNotNull('news_posts.published_at')
            ->where('news_posts.published_at', '<=', now());
    }

    /**
     * Visible and translated in the locale (title and body filled in).
     *
     * @param  Builder<NewsPost>  $query
     */
    public function scopeVisibleIn(Builder $query, string $locale): void
    {
        $query->visible()->translatedIn($locale);
    }

    /**
     * @param  Builder<NewsPost>  $query
     */
    public function scopeTranslatedIn(Builder $query, string $locale): void
    {
        $query->whereJsonContains('news_posts.locales', $locale);
    }

    /**
     * Newest first.
     *
     * @param  Builder<NewsPost>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('news_posts.published_at')->orderByDesc('news_posts.id');
    }

    /**
     * Locales with both the title and the body filled in (plain text), in
     * the order of app.supported_locales.
     *
     * @return list<string>
     */
    public function translatedLocales(): array
    {
        $titles = $this->getTranslations('title');
        $bodies = $this->getTranslations('body');
        $locales = [];

        foreach (config('app.supported_locales') as $locale) {
            if (trim((string) ($titles[$locale] ?? '')) !== '' && StructuredData::plainText((string) ($bodies[$locale] ?? '')) !== '') {
                $locales[] = $locale;
            }
        }

        return $locales;
    }

    public function isTranslatedIn(string $locale): bool
    {
        return in_array($locale, (array) $this->locales, true);
    }

    /**
     * Public right now (in at least its translated locales).
     */
    public function isVisible(): bool
    {
        return $this->status === NewsStatus::Published
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }

    public function isVisibleIn(string $locale): bool
    {
        return $this->isVisible() && $this->isTranslatedIn($locale);
    }

    /**
     * Admin state: draft, scheduled (published, date in the future) or published.
     */
    public function state(): NewsState
    {
        return match (true) {
            $this->status !== NewsStatus::Published => NewsState::Draft,
            $this->isVisible() => NewsState::Published,
            default => NewsState::Scheduled,
        };
    }

    /**
     * When the title, excerpt or body last changed, never before the
     * publication date (a post written ahead and scheduled), else the last save.
     */
    public function contentUpdatedAt(): ?Carbon
    {
        $dates = array_filter([$this->content_updated_at, $this->published_at]);

        return $dates === [] ? $this->updated_at : max($dates);
    }

    /**
     * The publication date in Tashkent time (for display).
     */
    public function localPublishedAt(): ?Carbon
    {
        return $this->published_at?->copy()->setTimezone(self::TIMEZONE);
    }
}
