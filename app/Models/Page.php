<?php

namespace App\Models;

use App\Enums\PageTemplate;
use App\Models\Concerns\HasCatalogImage;
use App\Models\Concerns\StoresTranslations;
use App\Support\Site\PageDirectory;
use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\Translatable\HasTranslations;

/**
 * Static page (plan 07 7a) at /{locale}/page/{slug}: title, body (sanitized
 * HTML) and SEO texts per locale (uz required, ru/en fall back to uz), an
 * optional cover image (medialibrary "cover", HasCatalogImage: private
 * original, WebP conversions, alt per locale) and an FAQ (faq_items,
 * faqable = page).
 *
 * System pages (SYSTEM: fixed slug and template) are linked from the site
 * menus: they can be deactivated but never deleted (PagePolicy + DeletePage).
 * needs_review marks texts still waiting for the lawyer. content_updated_at
 * is set by SavePage only when the title or body change (the legal
 * "Updated" date, see contentUpdatedAt()).
 *
 * Written only through App\Actions\Pages\*. Saving or deleting flushes the
 * PageDirectory (menus), the sitemap and llms.txt after the commit.
 *
 * @property int $id
 * @property string $slug
 * @property PageTemplate $template
 * @property bool $is_system
 * @property bool $is_active
 * @property bool $needs_review
 * @property int $sort
 * @property Carbon|null $content_updated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'template', 'title', 'body', 'meta_title', 'meta_description', 'is_system', 'is_active', 'needs_review', 'sort'])]
class Page extends Model implements HasMedia
{
    /** @use HasFactory<PageFactory> */
    use HasCatalogImage, HasFactory, HasTranslations, StoresTranslations;

    /**
     * System pages: slug => template (plan 07 K3).
     *
     * @var array<string, PageTemplate>
     */
    public const SYSTEM = [
        'about' => PageTemplate::Default,
        'contacts' => PageTemplate::Contacts,
        'delivery-payment' => PageTemplate::Default,
        'warranty-service' => PageTemplate::Default,
        'b2b' => PageTemplate::Default,
        'privacy' => PageTemplate::Legal,
        'offer' => PageTemplate::Legal,
    ];

    /**
     * Slug of the "About us" page (AboutPage in JSON-LD).
     */
    public const ABOUT_SLUG = 'about';

    /**
     * Longest body per locale (characters of HTML).
     */
    public const BODY_MAX = 60000;

    /**
     * @var list<string>
     */
    public array $translatable = ['title', 'body', 'meta_title', 'meta_description'];

    protected static function booted(): void
    {
        $flush = function (): void {
            PageDirectory::flush();
            DB::afterCommit(fn () => PageDirectory::flushAll());
        };

        static::saved($flush);
        static::deleted($flush);
        static::deleted(fn (Page $page) => $page->faqItems()->delete());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template' => PageTemplate::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'needs_review' => 'boolean',
            'sort' => 'integer',
            'content_updated_at' => 'datetime',
        ];
    }

    public function imageCollection(): string
    {
        return 'cover';
    }

    /**
     * FAQ of the page (plan 07 decision 2026-10-23), in admin order.
     *
     * @return MorphMany<FaqItem, $this>
     */
    public function faqItems(): MorphMany
    {
        return $this->morphMany(FaqItem::class, 'faqable')->orderBy('sort')->orderBy('id');
    }

    /**
     * @param  Builder<Page>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<Page>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }

    public static function isSystemSlug(string $slug): bool
    {
        return array_key_exists($slug, self::SYSTEM);
    }

    /**
     * When the title or body last changed, else the last save or creation.
     */
    public function contentUpdatedAt(): ?Carbon
    {
        return $this->content_updated_at ?? $this->updated_at ?? $this->created_at;
    }

    public function isLegal(): bool
    {
        return $this->template === PageTemplate::Legal;
    }

    /**
     * Whether the SI assist may write the body (never on legal pages).
     */
    public function allowsAiBody(): bool
    {
        return $this->template->allowsAiBody();
    }
}
