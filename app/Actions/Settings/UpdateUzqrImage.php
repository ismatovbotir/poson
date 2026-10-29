<?php

namespace App\Actions\Settings;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Events\UzqrImageChanged;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\CompanySettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Upload, replace or remove the company's static UzQR payment code
 * (Settings → Checkout, settings.checkout; security condition 24):
 *
 * - accepted: PNG, JPEG or WebP by content (finfo) and extension, ≤ 1 MB,
 *   100..2000 px per side; never SVG, never animated (APNG / animated WebP);
 *   a renamed script fails the image checks;
 * - re-encoded server-side to a fresh PNG with GD (metadata and any
 *   appended data are dropped), stored under a generated name on the
 *   private originals disk; shown only as a data URI (/o, invoice, admin);
 * - the previous code is kept (moved to the "uzqr_history" collection, never
 *   shown) for the audit;
 * - every change is audited (who / when / IP, never the image) and alerted
 *   to the finance and sales chats (UzqrImageChanged).
 */
class UpdateUzqrImage
{
    public const MAX_BYTES = 1_048_576;

    public const MIN_SIDE = 100;

    public const MAX_SIDE = 2000;

    public const MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /**
     * @throws ValidationException
     */
    public function handle(?UploadedFile $file, bool $remove, ?User $user, ?string $ip): bool
    {
        if ($file === null && ! $remove) {
            return false;
        }

        $png = $file !== null ? $this->reencode($file) : null;

        try {
            $action = DB::transaction(function () use ($png, $user, $ip): ?string {
                $setting = Setting::query()->firstOrCreate(['key' => Setting::UZQR], ['group' => SettingsArea::Checkout->group(), 'value' => null]);
                $current = $setting->media()->where('collection_name', Setting::UZQR)->get();

                if ($png === null && $current->isEmpty()) {
                    return null;
                }

                // Keep the old code for the audit, out of the shown collection.
                foreach ($current as $media) {
                    $media->forceFill(['collection_name' => Setting::UZQR_HISTORY])->save();
                }

                if ($png !== null) {
                    $setting->addMedia($png)
                        ->usingName(Setting::UZQR)
                        ->usingFileName(Str::uuid().'.png')
                        ->toMediaCollection(Setting::UZQR);
                }

                SettingsAudit::record(SettingsArea::Checkout, Setting::UZQR, $png !== null ? AuditAction::Uploaded : AuditAction::Removed, $user, $ip);

                return $png === null ? 'removed' : ($current->isEmpty() ? 'uploaded' : 'replaced');
            });
        } finally {
            if ($png !== null && is_file($png)) {
                @unlink($png);
            }
        }

        CompanySettings::flush();

        if ($action === null) {
            return false;
        }

        UzqrImageChanged::dispatch($action, $user);

        return true;
    }

    /**
     * Check the upload and write a re-encoded PNG to a temporary file.
     *
     * @throws ValidationException
     */
    public function reencode(UploadedFile $file): string
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['uzqr' => $message]);
        $path = $file->getRealPath();
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($path === false || ! is_file($path) || $file->getSize() === false || $file->getSize() > self::MAX_BYTES) {
            $fail(__('validation.max.file', ['attribute' => __('checkout.settings.uzqr'), 'max' => 1024]));
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! in_array($mime, self::MIMES, true) || ! in_array($extension, self::EXTENSIONS, true)) {
            $fail(__('validation.mimes', ['attribute' => __('checkout.settings.uzqr'), 'values' => 'png, jpg, webp']));
        }

        $contents = (string) file_get_contents($path);

        if ($this->isAnimated($mime, $contents)) {
            $fail(__('checkout.settings.errors.animated'));
        }

        $size = @getimagesizefromstring($contents);

        if ($size === false || $size[0] < self::MIN_SIDE || $size[1] < self::MIN_SIDE || $size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE) {
            $fail(__('validation.dimensions', ['attribute' => __('checkout.settings.uzqr')]));
        }

        try {
            $image = @imagecreatefromstring($contents);
        } catch (Throwable) {
            $image = false;
        }

        if ($image === false) {
            $fail(__('admin.image_upload.rejected'));
        }

        imagesavealpha($image, true);
        $target = tempnam(sys_get_temp_dir(), 'uzqr');

        if ($target === false || ! imagepng($image, $target, 6)) {
            imagedestroy($image);
            $fail(__('admin.image_upload.rejected'));
        }

        imagedestroy($image);

        return $target;
    }

    /**
     * APNG (acTL chunk before the image data) or animated WebP (ANIM chunk).
     */
    private function isAnimated(string $mime, string $contents): bool
    {
        if ($mime === 'image/png') {
            $idat = strpos($contents, 'IDAT');
            $actl = strpos($contents, 'acTL');

            return $actl !== false && ($idat === false || $actl < $idat);
        }

        if ($mime === 'image/webp') {
            return str_contains(substr($contents, 0, 64), 'ANIM') || (strlen($contents) > 20 && (ord($contents[20]) & 0x02) === 0x02 && substr($contents, 12, 4) === 'VP8X');
        }

        return false;
    }
}
