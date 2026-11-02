<?php

namespace App\Models;

use App\Enums\VideoFetchStatus;
use App\Models\Concerns\StoresTranslations;
use App\Rules\BrandSafeText;
use App\Support\Content\VideoThumbnails;
use App\Support\Content\YoutubeVideo;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * A YouTube video of a product, category, brand or news post (plan 19).
 * Only the validated youtube_id is stored; every URL is built from it.
 * source_title / source_author come from oEmbed and are untrusted text:
 * always escaped on output; the title is used on the site only when it passes
 * the brand rule (re-checked at render time).
 *
 * Written only through App\Actions\Videos\*.
 *
 * @property int $id
 * @property string $videoable_type
 * @property int $videoable_id
 * @property string $youtube_id
 * @property string|null $source_title
 * @property string|null $source_author
 * @property bool $source_title_safe
 * @property Carbon|null $published_on
 * @property VideoFetchStatus $fetch_status
 * @property Carbon|null $fetched_at
 * @property Carbon|null $thumbnail_at
 * @property int $sort
 * @property bool $is_active
 */
#[Fillable([
    'videoable_type', 'videoable_id', 'youtube_id', 'title', 'source_title', 'source_author',
    'source_title_safe', 'published_on', 'fetch_status', 'fetched_at', 'thumbnail_at', 'sort', 'is_active',
])]
class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory, HasTranslations, StoresTranslations;

    public const MAX_PER_OWNER = 20;

    public const TITLE_MAX = 200;

    /**
     * @var list<string>
     */
    public array $translatable = ['title'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_title_safe' => 'boolean',
            'published_on' => 'date',
            'fetch_status' => VideoFetchStatus::class,
            'fetched_at' => 'datetime',
            'thumbnail_at' => 'datetime',
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function videoable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<Video>  $query
     */
    public function scopeOwnedBy(Builder $query, Model $owner): void
    {
        $query->where('videoable_type', $owner->getMorphClass())->where('videoable_id', $owner->getKey());
    }

    /**
     * @param  Builder<Video>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }

    /**
     * Shown on the public site: active and not reported unavailable by YouTube.
     *
     * @param  Builder<Video>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('is_active', true)->where('fetch_status', '!=', VideoFetchStatus::Unavailable->value);
    }

    public function isVisible(): bool
    {
        return $this->is_active && $this->fetch_status !== VideoFetchStatus::Unavailable;
    }

    /**
     * Public title: manual title of the locale, else manual uz, else the oEmbed
     * title when it is brand safe (checked again here), else null (the caller
     * uses the generic fallback). Plain text, escape on output.
     */
    public function displayTitle(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        $manual = $this->getTranslations('title');

        foreach ([$locale, 'uz'] as $key) {
            $text = $manual[$key] ?? null;

            if (is_string($text) && trim($text) !== '' && ! BrandSafeText::violates($text)) {
                return $text;
            }
        }

        if ($this->source_title_safe && filled($this->source_title) && ! BrandSafeText::violates($this->source_title)) {
            return $this->source_title;
        }

        return null;
    }

    public function embedUrl(): string
    {
        return YoutubeVideo::embedUrl($this->youtube_id);
    }

    public function seoEmbedUrl(): string
    {
        return YoutubeVideo::seoEmbedUrl($this->youtube_id);
    }

    public function watchUrl(): string
    {
        return YoutubeVideo::watchUrl($this->youtube_id);
    }

    /**
     * The cached local preview image, or null while it is not cached.
     */
    public function thumbnailUrl(): ?string
    {
        YoutubeVideo::assertId($this->youtube_id);

        return $this->thumbnail_at === null ? null : VideoThumbnails::url($this->youtube_id);
    }
}
