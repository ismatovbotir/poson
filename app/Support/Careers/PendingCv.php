<?php

namespace App\Support\Careers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The encrypted pending copy of a CV (plan 31, CR-5): Crypt::encryptString
 * of the bytes at careers/pending/{ulid}.bin on the private local disk, the
 * path generated here (never from input), never routed, served or used as a
 * download source. It lives until the forward succeeds, fails or expires
 * (≤ careers.pending_cv_hours), or the application is erased.
 */
final class PendingCv
{
    public const PATH_PATTERN = '#^careers/pending/[0-9A-HJKMNP-TV-Z]{26}\.bin$#D';

    /**
     * Encrypt and write the bytes; the new relative path.
     *
     * @throws RuntimeException when the file cannot be written
     */
    public static function write(#[\SensitiveParameter] string $bytes): string
    {
        $path = CareersCaps::PENDING_DIR.'/'.Str::ulid()->toBase32().'.bin';

        if (! self::disk()->put($path, Crypt::encryptString(base64_encode($bytes)))) {
            throw new RuntimeException('Pending CV could not be written');
        }

        return $path;
    }

    /**
     * Decrypted bytes, or null (missing file, foreign path, APP_KEY rotated, tampered).
     */
    public static function read(?string $path): ?string
    {
        if (! self::isOwnPath($path)) {
            return null;
        }

        try {
            $contents = self::disk()->get($path);

            if (! is_string($contents)) {
                return null;
            }

            $bytes = base64_decode(Crypt::decryptString($contents), true);

            return is_string($bytes) ? $bytes : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Delete the file; false when it could not be deleted (a missing file counts as deleted).
     */
    public static function delete(?string $path): bool
    {
        if ($path === null || $path === '') {
            return true;
        }

        if (! self::isOwnPath($path)) {
            return false;
        }

        try {
            return ! self::disk()->exists($path) || self::disk()->delete($path);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public static function isOwnPath(?string $path): bool
    {
        return is_string($path) && preg_match(self::PATH_PATTERN, $path) === 1;
    }

    /**
     * @return Filesystem
     */
    public static function disk()
    {
        return Storage::disk(CareersCaps::DISK);
    }
}
