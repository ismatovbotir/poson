<?php

namespace App\Actions\Settings;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\CompanySettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileNameNotAllowed;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileUnacceptableForCollection;
use Throwable;

/**
 * Replace or remove the company logo and/or default OG image (plan 04 S10).
 *
 * Call it after the settings transaction has committed. All-or-nothing
 * without a DB transaction (files can't be rolled back):
 *   1. every file is validated (content type, dimensions) before any write;
 *   2. the new files are stored next to the old ones;
 *   3. on any failure the newly created media (rows and files) are deleted
 *      and the old images stay untouched;
 *   4. only when every new file is stored are the old images (and those
 *      marked for removal) deleted, and the changes audited.
 *
 * Files get a random name with an extension derived from their content
 * (JPEG/PNG/WebP only, no SVG). Originals stay private, conversions public.
 */
class UpdateCompanyMedia
{
    /**
     * Content type → stored extension.
     */
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * Media key => [min width, min height]; every image is at most 6000×6000.
     */
    public const MIN_DIMENSIONS = [
        Setting::LOGO => [112, 112],
        Setting::OG_IMAGE => [1200, 630],
    ];

    public const MAX_DIMENSION = 6000;

    /**
     * @param  array<string, array{file: UploadedFile|null, remove: bool}>  $changes  media key => change
     * @return bool whether anything changed
     *
     * @throws ValidationException
     */
    public function handle(array $changes, ?User $user, ?string $ip): bool
    {
        $changes = $this->pending($changes);

        if ($changes === []) {
            return false;
        }

        $this->validate($changes);

        $settings = [];
        $previous = [];

        foreach (array_keys($changes) as $key) {
            $settings[$key] = Setting::query()->firstOrCreate(['key' => $key], ['group' => SettingsArea::Seo->group(), 'value' => null]);
            $previous[$key] = $this->mediaIds($settings[$key], $key);
        }

        try {
            foreach ($changes as $key => $change) {
                if ($change['file'] !== null) {
                    $this->store($settings[$key], $key, $change['file']);
                }
            }
        } catch (Throwable $exception) {
            $this->discardNewMedia($settings, $previous);

            if ($exception instanceof FileNameNotAllowed || $exception instanceof FileUnacceptableForCollection || $exception instanceof FileIsTooBig) {
                report($exception);

                throw ValidationException::withMessages([array_key_first($changes) => __('admin.image_upload.rejected')]);
            }

            throw $exception;
        }

        $changed = false;

        foreach ($changes as $key => $change) {
            $old = $settings[$key]->media()->whereIn('id', $previous[$key])->get();

            if ($change['file'] === null && $old->isEmpty()) {
                continue;
            }

            $old->each->delete();
            SettingsAudit::record(SettingsArea::Seo, $key, $change['file'] !== null ? AuditAction::Uploaded : AuditAction::Removed, $user, $ip);
            $changed = true;
        }

        CompanySettings::flush();

        return $changed;
    }

    /**
     * Check every file before anything is written.
     *
     * @param  array<string, array{file: UploadedFile|null, remove: bool}>  $changes
     *
     * @throws ValidationException
     */
    public function validate(array $changes): void
    {
        foreach ($this->pending($changes) as $key => $change) {
            $file = $change['file'];

            if ($file === null) {
                continue;
            }

            if (! isset(self::EXTENSIONS[(string) $file->getMimeType()])) {
                throw ValidationException::withMessages([$key => __('validation.mimes', ['attribute' => __('admin.image_upload.image'), 'values' => 'jpg, png, webp'])]);
            }

            $size = @getimagesize($file->getRealPath());
            [$minWidth, $minHeight] = self::MIN_DIMENSIONS[$key];

            if ($size === false || $size[0] < $minWidth || $size[1] < $minHeight || $size[0] > self::MAX_DIMENSION || $size[1] > self::MAX_DIMENSION) {
                throw ValidationException::withMessages([$key => __('validation.dimensions', ['attribute' => __('admin.image_upload.image')])]);
            }
        }
    }

    /**
     * Known keys with something to do.
     *
     * @param  array<string, array{file: UploadedFile|null, remove: bool}>  $changes
     * @return array<string, array{file: UploadedFile|null, remove: bool}>
     */
    private function pending(array $changes): array
    {
        foreach (array_keys($changes) as $key) {
            if (! array_key_exists($key, Setting::MEDIA)) {
                throw new InvalidArgumentException("Unknown media setting [{$key}].");
            }
        }

        return array_filter($changes, fn (array $change): bool => ($change['file'] ?? null) !== null || ($change['remove'] ?? false) === true);
    }

    /**
     * @return list<int>
     */
    private function mediaIds(Setting $setting, string $key): array
    {
        return $setting->media()->where('collection_name', Setting::MEDIA[$key]['collection'])->pluck('id')->all();
    }

    private function store(Setting $setting, string $key, UploadedFile $file): void
    {
        $collection = Setting::MEDIA[$key]['collection'];

        $setting->addMedia($file)
            ->usingName($collection)
            ->usingFileName(Str::uuid().'.'.self::EXTENSIONS[(string) $file->getMimeType()])
            ->toMediaCollection($collection);
    }

    /**
     * Delete media created during this call (rows, originals and conversions).
     *
     * @param  array<string, Setting>  $settings
     * @param  array<string, list<int>>  $previous
     */
    private function discardNewMedia(array $settings, array $previous): void
    {
        foreach ($settings as $key => $setting) {
            $setting->media()
                ->where('collection_name', Setting::MEDIA[$key]['collection'])
                ->whereNotIn('id', $previous[$key])
                ->get()
                ->each(function ($media): void {
                    try {
                        $media->delete();
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                });
        }

        CompanySettings::flush();
    }
}
