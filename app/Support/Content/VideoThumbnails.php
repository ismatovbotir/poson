<?php

namespace App\Support\Content;

use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Local cache of YouTube preview images (plan 19, YT-S3/S4): the public site
 * never links to i.ytimg.com. Downloaded bytes are checked with
 * getimagesizefromstring (JPEG/WebP, at most 1920x1080) before GD decodes them,
 * cropped to 16:9, scaled to at most 1280 px wide and re-encoded as JPEG
 * (drops metadata and polyglot payloads). The file name derives from the
 * validated id plus a short hash, so ids differing only in case never share a
 * file on case-insensitive file systems.
 */
final class VideoThumbnails
{
    public const DISK = 'public';

    public const MAX_WIDTH = 1280;

    public const MAX_SOURCE_WIDTH = 1920;

    public const MAX_SOURCE_HEIGHT = 1080;

    public static function path(string $id): string
    {
        YoutubeVideo::assertId($id);

        return 'videos/'.$id.'-'.substr(sha1($id), 0, 8).'.jpg';
    }

    public static function exists(string $id): bool
    {
        return Storage::disk(self::DISK)->exists(self::path($id));
    }

    public static function url(string $id): string
    {
        return Storage::disk(self::DISK)->url(self::path($id));
    }

    /**
     * Validate, crop, re-encode and store the image. Returns false (nothing
     * stored) for anything that is not a plain, in-bounds JPEG/WebP.
     */
    public static function store(string $id, string $bytes): bool
    {
        $path = self::path($id);

        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            return false;
        }

        // A truncated JPEG would decode with warnings suppressed (gd.jpeg_ignore_warning): require the EOI marker.
        if ($info[2] === IMAGETYPE_JPEG && ! str_ends_with(rtrim($bytes, "\0\n\r "), "\xFF\xD9")) {
            return false;
        }

        [$width, $height] = $info;

        if ($width < 16 || $height < 9 || $width > self::MAX_SOURCE_WIDTH || $height > self::MAX_SOURCE_HEIGHT) {
            return false;
        }

        try {
            $source = @imagecreatefromstring($bytes);

            if ($source === false) {
                return false;
            }

            $cropHeight = (int) min($height, floor($width * 9 / 16));
            $cropY = (int) floor(($height - $cropHeight) / 2);
            $targetWidth = min($width, self::MAX_WIDTH);
            $targetHeight = max(1, (int) round($targetWidth * $cropHeight / $width));

            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            imagecopyresampled($canvas, $source, 0, 0, 0, $cropY, $targetWidth, $targetHeight, $width, $cropHeight);

            ob_start();
            $encoded = imagejpeg($canvas, null, 82);
            $jpeg = (string) ob_get_clean();
        } catch (Throwable) {
            return false;
        }

        if (! $encoded || $jpeg === '') {
            return false;
        }

        $disk = Storage::disk(self::DISK);
        $temp = 'videos/.tmp-'.Str::random(16);

        if (! $disk->put($temp, $jpeg)) {
            return false;
        }

        // Atomic swap: readers never see a half-written file.
        $disk->move($temp, $path) || $disk->delete($temp);

        return $disk->exists($path);
    }

    /**
     * Delete the cached image when no video row uses the id any more.
     */
    public static function forgetIfUnused(string $id): void
    {
        if (! YoutubeVideo::isId($id) || Video::query()->where('youtube_id', $id)->exists()) {
            return;
        }

        Storage::disk(self::DISK)->delete(self::path($id));
    }
}
