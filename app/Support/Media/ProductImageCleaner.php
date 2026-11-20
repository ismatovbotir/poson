<?php

namespace App\Support\Media;

use GdImage;
use Throwable;

/**
 * Deterministic GD-only photo work of the AI product wizard (plan 32 §4,
 * PW-1, PW-2, PW-3). Every output is a fresh GD re-encode to JPEG (no
 * metadata, polyglot payloads gone), written where the caller says (the
 * caller picks server-side names under ai-drafts/{uuid}).
 *
 * Decode order (PW-1), before any pixel is decoded:
 *  1. file size ≤ MAX_BYTES;
 *  2. finfo content type ∈ jpeg/png/webp AND getimagesize() type equal to it;
 *  3. 1 ≤ width, height ≤ ImageLimits::MAX_DIMENSION;
 *  4. memory: (width × height + output side²) × BYTES_PER_PIXEL fits the
 *     free memory (memory_limit − usage); the output side is the longest
 *     edge after the downscale (≤ the target edge, never upscaled);
 *  5. animated WebP refused (VP8X animation flag / ANIM chunk);
 *  6. the format's own decoder (never imagecreatefromstring), libjpeg
 *     warnings are errors, any warning or false → "corrupt".
 * EXIF (PW-2): only for JPEG, after 1-6, IFD0 only, errors swallowed, only an
 * Orientation of 1-8 is used; nothing else is read, kept or logged.
 *
 * Peak (M1): the decoded image is scaled down straight onto the white
 * canvas (flattening alpha in the same copy) and destroyed; only the small
 * image is rotated. A JPEG that needs no downscale is used as decoded (no
 * alpha, no flatten copy). So at most the decoded buffer + two output-sized
 * buffers live at once, which the estimate covers.
 *
 * clean(): downscale + flatten onto white → orient → trim a uniform light border (the 4
 * corners agree and are light, L ≥ 200; skipped when more than 60 % would go)
 * → resize so the content fits the square minus the margin (≤ edge, never
 * upscaled) → pad to a white square → JPEG. plain(): orient → flatten →
 * resize ≤ edge → JPEG. Both stored outputs (clean, plain) are capped at
 * ImageLimits::MAX_PRODUCT_BYTES (200 KB): the JPEG quality steps down (85, 75,
 * 65), then the edge drops to 1200 and 1000 at q65 (5 encodes at most). Output goes
 * to a temp file renamed into place only when it fits, so nothing over the cap is
 * ever left at the target (ImageRejected "size" if none fits). vision(): like
 * plain() with the AI edge and megapixel caps. Each intermediate image is destroyed right away.
 */
class ProductImageCleaner
{
    public const MAX_BYTES = 5_242_880;

    /**
     * Memory estimate per pixel: a GD truecolor buffer (≈ 4-5 B/px with row
     * pointers) plus a second buffer of the same size, with headroom.
     */
    public const BYTES_PER_PIXEL = 9;

    private const TYPES = [
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/png' => IMAGETYPE_PNG,
        'image/webp' => IMAGETYPE_WEBP,
    ];

    /**
     * Light border trim: corner luminance at least this.
     */
    private const LIGHT = 200;

    /**
     * Most of the area a trim may remove.
     */
    private const TRIM_MAX_REMOVED = 0.6;

    /**
     * @param  int|null  $memoryLimit  bytes available to PHP (tests); null = ini memory_limit
     */
    public function __construct(private readonly ?int $memoryLimit = null) {}

    /**
     * Run the PW-1 checks 1-5 without decoding.
     *
     * @return array{mime: string, width: int, height: int}
     *
     * @throws ImageRejected
     */
    public function inspect(string $path, int $outputEdge = 1600): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new ImageRejected('corrupt');
        }

        $size = filesize($path);

        if ($size === false || $size === 0 || $size > self::MAX_BYTES) {
            throw new ImageRejected('size');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! isset(self::TYPES[$mime])) {
            throw new ImageRejected('type');
        }

        $info = @getimagesize($path);

        if ($info === false || ($info[2] ?? null) !== self::TYPES[$mime]) {
            throw new ImageRejected('type');
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];

        if ($width < 1 || $height < 1 || $width > ImageLimits::MAX_DIMENSION || $height > ImageLimits::MAX_DIMENSION) {
            throw new ImageRejected('dimensions');
        }

        $side = min(max(1, $outputEdge), max($width, $height));

        if (($width * $height + $side * $side) * self::BYTES_PER_PIXEL > $this->freeMemory()) {
            throw new ImageRejected('memory');
        }

        if ($mime === 'image/webp' && $this->isAnimatedWebp($path)) {
            throw new ImageRejected('animated');
        }

        return ['mime' => $mime, 'width' => $width, 'height' => $height];
    }

    /**
     * The cleaned product photo (white square, light border trimmed).
     *
     * @throws ImageRejected
     */
    public function clean(string $source, string $target): void
    {
        $edge = (int) config('chatbot.ai.product.clean.edge', 1600);
        $margin = max(0.0, min(0.2, (float) config('chatbot.ai.product.clean.margin', 0.06)));
        $image = $this->load($source, $edge);

        $image = $this->trim($image, (float) config('chatbot.ai.product.clean.trim', 0.08));

        $inner = max(1, (int) floor($edge * (1 - 2 * $margin)));
        $image = $this->fit($image, $inner, null);

        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($edge, (int) ceil(max($width, $height) / (1 - 2 * $margin)));
        $canvas = $this->canvas($side, $side);
        imagecopy($canvas, $image, intdiv($side - $width, 2), intdiv($side - $height, 2), 0, 0, $width, $height);
        imagedestroy($image);

        $this->writeCapped($canvas, $target);
    }

    /**
     * The photo as it is (oriented, alpha on white, ≤ edge), re-encoded.
     *
     * @throws ImageRejected
     */
    public function plain(string $source, string $target, ?int $edge = null): void
    {
        $edge ??= (int) config('chatbot.ai.product.clean.edge', 1600);
        $image = $this->fit($this->load($source, $edge), $edge, null);

        $this->writeCapped($image, $target);
    }

    /**
     * The copy sent to the vision stage (≤ ai_edge, ≤ ai_megapixels).
     *
     * @throws ImageRejected
     */
    public function vision(string $source, string $target): void
    {
        $edge = (int) config('chatbot.ai.product.ai_edge', 1568);
        $pixels = (int) round((float) config('chatbot.ai.product.ai_megapixels', 1.15) * 1_000_000);
        $image = $this->fit($this->load($source, $edge, $pixels), $edge, $pixels);

        $this->write($image, $target, (int) config('chatbot.ai.product.ai_quality', 85));
    }

    /**
     * A small 400 px JPEG of one of our own derived files, written to a file of the draft folder
     * (N1: the AI wizard keeps tile previews on the private disk, never in the
     * cache store). False when the source cannot be read.
     */
    public function previewFile(string $source, string $target, int $edge = 400): bool
    {
        try {
            $this->write($this->fit($this->load($source, $edge), $edge, null), $target, 70);
        } catch (ImageRejected) {
            @unlink($target);

            return false;
        }

        return true;
    }

    /**
     * Checked, decoded, scaled down to the edge (and pixel cap) straight onto
     * a white canvas (flattened), then oriented: truecolor, white background.
     *
     * @throws ImageRejected
     */
    private function load(string $path, int $edge, ?int $pixels = null): GdImage
    {
        $meta = $this->inspect($path, $edge);
        $image = $this->decode($path, $meta['mime']);
        $orientation = $meta['mime'] === 'image/jpeg' ? $this->orientation($path) : 1;
        $width = imagesx($image);
        $height = imagesy($image);
        [$newWidth, $newHeight] = $this->fitted($width, $height, $edge, $pixels);

        if ($meta['mime'] !== 'image/jpeg' || $newWidth !== $width || $newHeight !== $height) {
            try {
                $flat = $this->canvas($newWidth, $newHeight);
            } catch (ImageRejected $rejected) {
                imagedestroy($image);

                throw $rejected;
            }

            imagealphablending($flat, true);

            if ($newWidth === $width && $newHeight === $height) {
                imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);
            } else {
                imagecopyresampled($flat, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            }

            imagedestroy($image);
            $image = $flat;
        }

        return $this->orient($image, $orientation);
    }

    /**
     * @throws ImageRejected
     */
    private function decode(string $path, string $mime): GdImage
    {
        $warned = false;
        $previous = ini_set('gd.jpeg_ignore_warning', '0');
        set_error_handler(function () use (&$warned): bool {
            $warned = true;

            return true;
        });

        try {
            $image = match ($mime) {
                'image/jpeg' => imagecreatefromjpeg($path),
                'image/png' => imagecreatefrompng($path),
                'image/webp' => imagecreatefromwebp($path),
            };
        } catch (Throwable) {
            $image = false;
        } finally {
            restore_error_handler();

            if ($previous !== false) {
                ini_set('gd.jpeg_ignore_warning', $previous);
            }
        }

        if (! $image instanceof GdImage) {
            throw new ImageRejected('corrupt');
        }

        if ($warned) {
            imagedestroy($image);

            throw new ImageRejected('corrupt');
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    /**
     * EXIF Orientation (1-8) of a JPEG, IFD0 only; 1 when absent or unreadable.
     */
    private function orientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        try {
            $exif = @exif_read_data($path, 'IFD0');
        } catch (Throwable) {
            return 1;
        }

        $value = is_array($exif) ? ($exif['Orientation'] ?? null) : null;

        return is_int($value) && $value >= 1 && $value <= 8 ? $value : 1;
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        if ($orientation === 1) {
            return $image;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            6, 7 => 270,
            5, 8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        imagedestroy($image);

        if (! $rotated instanceof GdImage) {
            throw new ImageRejected('corrupt');
        }

        return $rotated;
    }

    /**
     * Crop a uniform light border (corner colour) with the given tolerance.
     */
    private function trim(GdImage $image, float $tolerance): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $corners = [
            imagecolorat($image, 0, 0),
            imagecolorat($image, $width - 1, 0),
            imagecolorat($image, 0, $height - 1),
            imagecolorat($image, $width - 1, $height - 1),
        ];
        $rgb = array_map(fn (int $color): array => [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF], $corners);
        $limit = max(1.0, $tolerance * 441.7);

        foreach ($rgb as $color) {
            if ($this->distance($color, $rgb[0]) > $limit || (0.299 * $color[0] + 0.587 * $color[1] + 0.114 * $color[2]) < self::LIGHT) {
                return $image;
            }
        }

        $step = max(1, intdiv(max($width, $height), 400));
        [$left, $top, $right, $bottom] = [$width, $height, -1, -1];

        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                $color = imagecolorat($image, $x, $y);

                if ($this->distance([($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF], $rgb[0]) > $limit) {
                    $left = min($left, $x);
                    $right = max($right, $x);
                    $top = min($top, $y);
                    $bottom = max($bottom, $y);
                }
            }
        }

        if ($right < 0) {
            return $image;
        }

        $left = max(0, $left - $step);
        $top = max(0, $top - $step);
        $right = min($width - 1, $right + $step);
        $bottom = min($height - 1, $bottom + $step);
        $box = ['x' => $left, 'y' => $top, 'width' => $right - $left + 1, 'height' => $bottom - $top + 1];

        if ($box['width'] * $box['height'] < (1 - self::TRIM_MAX_REMOVED) * $width * $height || ($box['width'] === $width && $box['height'] === $height)) {
            return $image;
        }

        $cropped = imagecrop($image, $box);

        if (! $cropped instanceof GdImage) {
            return $image;
        }

        imagedestroy($image);

        return $cropped;
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $a
     * @param  array{0: int, 1: int, 2: int}  $b
     */
    private function distance(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }

    /**
     * Scale down (never up) to the edge and the pixel cap.
     */
    private function fit(GdImage $image, int $edge, ?int $pixels): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        [$newWidth, $newHeight] = $this->fitted($width, $height, $edge, $pixels);

        if ($newWidth === $width && $newHeight === $height) {
            return $image;
        }

        $resized = $this->canvas($newWidth, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }

    /**
     * Size after scaling down (never up) to the edge and the pixel cap.
     *
     * @return array{0: int, 1: int}
     */
    private function fitted(int $width, int $height, int $edge, ?int $pixels): array
    {
        $scale = min(1.0, max(1, $edge) / max($width, $height));

        if ($pixels !== null && $width * $height * $scale * $scale > $pixels) {
            $scale = min($scale, sqrt($pixels / ($width * $height)));
        }

        if ($scale >= 1.0) {
            return [$width, $height];
        }

        return [max(1, (int) floor($width * $scale)), max(1, (int) floor($height * $scale))];
    }

    private function canvas(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);

        if (! $canvas instanceof GdImage) {
            throw new ImageRejected('memory');
        }

        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));

        return $canvas;
    }

    /**
     * Encode under ImageLimits::MAX_PRODUCT_BYTES with a fixed list of at most
     * 5 attempts: q85, q75, q65 at the current edge, then q65 at 1200 and 1000
     * (only edges below the current one). Smaller edges are resampled from the
     * one decoded copy; the memory budget is checked before each new edge. The
     * target is written only by a rename of a passing temp file.
     *
     * @throws ImageRejected
     */
    private function writeCapped(GdImage $image, string $target): void
    {
        $current = max(imagesx($image), imagesy($image));
        $attempts = [[$current, 85], [$current, 75], [$current, 65]];

        foreach ([1200, 1000] as $edge) {
            if ($edge < $current) {
                $attempts[] = [$edge, 65];
            }
        }

        $temp = $target.'.tmp';
        $scaled = null;
        $scaledEdge = null;

        try {
            foreach ($attempts as [$edge, $step]) {
                $source = $image;

                if ($edge < $current) {
                    if ($scaledEdge !== $edge) {
                        $scaled = null;

                        if ($edge * $edge * self::BYTES_PER_PIXEL > $this->freeMemory()) {
                            throw new ImageRejected('memory');
                        }

                        $scaled = $this->resampled($image, $edge);
                        $scaledEdge = $edge;
                    }

                    $source = $scaled;
                }

                $this->encode($source, $temp, $step);

                if ((int) filesize($temp) <= ImageLimits::MAX_PRODUCT_BYTES) {
                    if (! rename($temp, $target)) {
                        throw new ImageRejected('corrupt');
                    }

                    return;
                }
            }

            throw new ImageRejected('size');
        } catch (Throwable $failed) {
            // The target is only ever written by a successful rename, so only the temp file needs removing.
            @unlink($temp);

            throw $failed;
        } finally {
            imagedestroy($image);
        }
    }

    private function resampled(GdImage $image, int $edge): GdImage
    {
        [$width, $height] = $this->fitted(imagesx($image), imagesy($image), $edge, null);
        $copy = $this->canvas($width, $height);
        imagecopyresampled($copy, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $copy;
    }

    private function encode(GdImage $image, string $target, int $quality): void
    {
        $directory = dirname($target);

        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        imageinterlace($image, true);

        if (! imagejpeg($image, $target, max(1, min(100, $quality)))) {
            throw new ImageRejected('corrupt');
        }

        clearstatcache(true, $target);
    }

    private function write(GdImage $image, string $target, int $quality): void
    {
        $directory = dirname($target);

        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        imageinterlace($image, true);
        $written = imagejpeg($image, $target, max(1, min(100, $quality)));
        imagedestroy($image);

        if (! $written) {
            throw new ImageRejected('corrupt');
        }
    }

    private function isAnimatedWebp(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return true;
        }

        $head = (string) fread($handle, 64 * 1024);
        fclose($handle);

        if (substr($head, 12, 4) === 'VP8X' && strlen($head) > 20 && (ord($head[20]) & 0x02) !== 0) {
            return true;
        }

        return str_contains($head, 'ANIM') || str_contains($head, 'ANMF');
    }

    private function freeMemory(): int
    {
        $limit = $this->memoryLimit ?? $this->iniBytes((string) ini_get('memory_limit'));

        if ($limit < 0) {
            $limit = 512 * 1024 * 1024;
        }

        return $limit - memory_get_usage(true);
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
