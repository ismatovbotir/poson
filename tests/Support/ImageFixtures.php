<?php

namespace Tests\Support;

use GdImage;

/**
 * Small GD-built images for the AI product wizard tests (plan 32 §9): bordered
 * product photos, transparent PNGs, EXIF with a GPS block, hostile files
 * (polyglot, forged header, wrong type, truncated, animated WebP). Everything
 * is written into a directory the test owns; nothing is downloaded.
 */
final class ImageFixtures
{
    public static function canvas(int $width, int $height, int $r = 255, int $g = 255, int $b = 255): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $r, $g, $b));

        return $image;
    }

    /**
     * A JPEG: white (or $background) canvas with a dark rectangle of $inner x $inner in the middle.
     *
     * @param  array{0: int, 1: int, 2: int}  $background
     */
    public static function bordered(string $path, int $width = 800, int $height = 600, int $inner = 200, array $background = [255, 255, 255]): string
    {
        $image = self::canvas($width, $height, ...$background);
        $dark = (int) imagecolorallocate($image, 20, 20, 20);
        $x = intdiv($width - $inner, 2);
        $y = intdiv($height - $inner, 2);
        imagefilledrectangle($image, $x, $y, $x + $inner - 1, $y + $inner - 1, $dark);

        return self::jpeg($image, $path);
    }

    public static function jpeg(GdImage $image, string $path): string
    {
        self::ensureDirectory($path);
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        return $path;
    }

    /**
     * A PNG with a fully transparent background and a red disc.
     */
    public static function transparentPng(string $path, int $size = 120): string
    {
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);
        imagefilledellipse($image, intdiv($size, 2), intdiv($size, 2), intdiv($size, 2), intdiv($size, 2), (int) imagecolorallocate($image, 220, 0, 0));
        self::ensureDirectory($path);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * A JPEG with an APP1 EXIF block: Orientation and a GPS IFD with a latitude reference.
     */
    public static function withExif(string $path, int $orientation = 1, int $width = 200, int $height = 100): string
    {
        $base = self::bordered($path, $width, $height, 40);
        $bytes = (string) file_get_contents($base);
        $gps = pack('n', 1).pack('nnN', 0x0001, 2, 2)."N\0\0\0".pack('N', 0);
        $ifd0 = pack('n', 2)
            .pack('nnN', 0x0112, 3, 1).pack('nn', $orientation, 0)
            .pack('nnNN', 0x8825, 4, 1, 8 + 2 + 2 * 12 + 4);
        $tiff = 'MM'.pack('n', 42).pack('N', 8).$ifd0.pack('N', 0).$gps;
        $app1 = "Exif\0\0".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;
        $comment = "\xFF\xFE".pack('n', 2 + strlen('SECRET-GPS-MARKER')).'SECRET-GPS-MARKER';

        file_put_contents($path, substr($bytes, 0, 2).$segment.$comment.substr($bytes, 2));

        return $path;
    }

    /**
     * A valid JPEG with a PHP/HTML payload appended after the image data.
     */
    public static function polyglot(string $path): string
    {
        self::bordered($path, 300, 200, 80);
        file_put_contents($path, "\n<?php system(\$_GET['c']); ?><script>alert(1)</script>", FILE_APPEND);

        return $path;
    }

    /**
     * A real 1x1 PNG whose IHDR claims $width x $height (a decompression-bomb header).
     */
    public static function forgedPng(string $path, int $width = 40000, int $height = 40000): string
    {
        $image = self::canvas(1, 1);
        self::ensureDirectory($path);
        imagepng($image, $path);
        imagedestroy($image);
        $bytes = (string) file_get_contents($path);
        $ihdr = pack('NN', $width, $height).substr($bytes, 24, 5);
        $forged = substr($bytes, 0, 16).$ihdr.pack('N', crc32('IHDR'.$ihdr)).substr($bytes, 33);
        file_put_contents($path, $forged);

        return $path;
    }

    public static function gif(string $path): string
    {
        $image = self::canvas(40, 40);
        self::ensureDirectory($path);
        imagegif($image, $path);
        imagedestroy($image);

        return $path;
    }

    public static function bmp(string $path): string
    {
        $image = self::canvas(40, 40);
        self::ensureDirectory($path);
        imagebmp($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * A truncated JPEG (the second half is missing).
     */
    public static function truncatedJpeg(string $path): string
    {
        $image = self::canvas(400, 400, 90, 140, 200);
        imagefilledellipse($image, 200, 200, 300, 300, (int) imagecolorallocate($image, 250, 30, 30));
        imageinterlace($image, false);
        self::jpeg($image, $path);
        $bytes = (string) file_get_contents($path);
        file_put_contents($path, substr($bytes, 0, (int) (strlen($bytes) * 0.4)));

        return $path;
    }

    /**
     * A WebP container whose VP8X header sets the animation flag.
     */
    public static function animatedWebp(string $path): string
    {
        $vp8x = 'VP8X'.pack('V', 10)."\x02\0\0\0".substr(pack('V', 39), 0, 3).substr(pack('V', 39), 0, 3);
        $anim = 'ANIM'.pack('V', 6)."\0\0\0\0\0\0";
        $body = 'WEBP'.$vp8x.$anim;
        self::ensureDirectory($path);
        file_put_contents($path, 'RIFF'.pack('V', strlen($body)).$body);

        return $path;
    }

    /**
     * Brightness (0-255) of a pixel of a decoded image file.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public static function pixel(string $path, int $x, int $y): array
    {
        $image = imagecreatefromjpeg($path);
        $color = imagecolorat($image, $x, $y);
        imagedestroy($image);

        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
    }

    public static function isDark(string $path, int $x, int $y): bool
    {
        return array_sum(self::pixel($path, $x, $y)) < 300;
    }

    private static function ensureDirectory(string $path): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
    }
}
