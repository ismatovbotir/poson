<?php

namespace App\Models;

use App\Support\CompanySettings;
use App\Support\Secrets;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Key/value override for the defaults in config/company.php. "group" is
 * company, seo or integrations (plan 04 S1). Write through the
 * UpdateCompanySettings / UpdateCompanyMedia actions, which also audit.
 *
 * Saving or deleting a model flushes the CompanySettings cache (or, for the
 * integrations group, the Secrets cache) once the surrounding transaction
 * commits (immediately when there is none).
 * Mass writes such as Setting::query()->update() or ->delete() bypass model
 * events and therefore do NOT flush the cache: call CompanySettings::flush()
 * yourself after them.
 *
 * The "logo" and "og_image" rows carry their image in a medialibrary
 * collection of the same name (plan 04 S10): JPEG/PNG/WebP only (no SVG),
 * the original on the private originals disk, queued public conversions
 * "logo" (WebP) and "og" (JPEG 1200×630). The collections are deliberately
 * not singleFile(): that would delete the old image as soon as a new one is
 * added, before the rest of the save succeeded. UpdateCompanyMedia keeps one
 * image per collection itself and removes the old one only at the end.
 *
 * @property int $id
 * @property string $key
 * @property string $group
 * @property mixed $value
 */
#[Fillable(['key', 'group', 'value'])]
class Setting extends Model implements HasMedia
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory, InteractsWithMedia;

    public const LOGO = 'logo';

    public const OG_IMAGE = 'og_image';

    /**
     * Accepted upload types (no SVG: it can carry scripts).
     */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Media key => [collection, public conversion].
     */
    public const MEDIA = [
        self::LOGO => ['collection' => self::LOGO, 'conversion' => 'logo'],
        self::OG_IMAGE => ['collection' => self::OG_IMAGE, 'conversion' => 'og'],
    ];

    public const STAMP = 'stamp';

    public const SIGNATURE = 'signature';

    /**
     * Private images used only inside quote PDFs (plan 06): PNG only, the
     * original on the private originals disk, no conversions, no URL ever.
     * Uploaded on Settings → Company → Legal (Admin).
     */
    public const PRIVATE_MEDIA = [
        self::STAMP => ['collection' => self::STAMP],
        self::SIGNATURE => ['collection' => self::SIGNATURE],
    ];

    public const PNG_MIMES = ['image/png'];

    /**
     * The medialibrary collection of a media key (public or private), or null.
     */
    public static function collectionFor(string $key): ?string
    {
        return self::MEDIA[$key]['collection'] ?? self::PRIVATE_MEDIA[$key]['collection'] ?? null;
    }

    /**
     * The newest stored image of a private media key as a data URI (for
     * dompdf), read from its (private) disk; null when there is none.
     */
    public static function privateImageDataUri(string $key): ?string
    {
        return array_key_exists($key, self::PRIVATE_MEDIA) ? static::originalDataUri($key, self::PNG_MIMES) : null;
    }

    /**
     * The newest original of a media key (public or private collection) as a
     * data URI when its type is one of $mimes; null otherwise. Read straight
     * from the media's disk, so it works for private files too.
     *
     * @param  list<string>  $mimes
     */
    public static function originalDataUri(string $key, array $mimes): ?string
    {
        $collection = static::collectionFor($key);

        if ($collection === null) {
            return null;
        }

        $setting = static::query()->where('key', $key)->with('media')->first();
        $media = $setting?->getMedia($collection)->last();

        if ($media === null || ! in_array($media->mime_type, $mimes, true)) {
            return null;
        }

        try {
            $contents = Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return is_string($contents) && $contents !== '' ? 'data:'.$media->mime_type.';base64,'.base64_encode($contents) : null;
    }

    protected static function booted(): void
    {
        static::saved(fn (Setting $setting) => DB::afterCommit(fn () => $setting->flushCaches()));
        static::deleted(fn (Setting $setting) => DB::afterCommit(fn () => $setting->flushCaches()));
    }

    /**
     * Company rows feed CompanySettings; integration rows feed Secrets.
     */
    public function flushCaches(): void
    {
        if ($this->group === 'integrations') {
            Secrets::flush();

            return;
        }

        CompanySettings::flush();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    public function registerMediaCollections(): void
    {
        foreach (self::MEDIA as $media) {
            $this->addMediaCollection($media['collection'])
                ->acceptsMimeTypes(self::IMAGE_MIMES)
                ->useDisk(config('media-library.originals_disk', 'local'))
                ->storeConversionsOnDisk(config('media-library.disk_name', 'public'));
        }

        // Stamp and signature: originals only, private disk, PNG (transparent background).
        foreach (self::PRIVATE_MEDIA as $media) {
            $this->addMediaCollection($media['collection'])
                ->acceptsMimeTypes(self::PNG_MIMES)
                ->useDisk(config('media-library.originals_disk', 'local'));
        }
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('logo')
            ->fit(Fit::Max, 600, 600)
            ->format('webp')
            ->performOnCollections(self::LOGO)
            ->queued();

        $this->addMediaConversion('og')
            ->fit(Fit::Crop, 1200, 630)
            ->format('jpg')
            ->performOnCollections(self::OG_IMAGE)
            ->queued();
    }

    /**
     * Public URL of the row's image conversion; null without an image or
     * while the queued conversion hasn't run (the original is never public).
     * Needs "media" loaded.
     */
    public function mediaUrl(): ?string
    {
        $config = self::MEDIA[$this->key] ?? null;

        if ($config === null) {
            return null;
        }

        // The newest item: while a replacement is in progress both may exist briefly.
        $media = $this->getMedia($config['collection'])->last();

        return $media !== null && $media->hasGeneratedConversion($config['conversion']) ? $media->getUrl($config['conversion']) : null;
    }
}
