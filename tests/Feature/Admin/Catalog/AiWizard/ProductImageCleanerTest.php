<?php

use App\Support\Media\ImageRejected;
use App\Support\Media\ProductImageCleaner;
use Tests\Support\ImageFixtures;

/**
 * Plan 32 §4, §9, PW-1, PW-2, PW-3: the deterministic GD cleaner. Hostile
 * files are rejected before any pixel is decoded or neutralised by the
 * re-encode, with a fixed reason key and never a 500. Fixtures are built by
 * Tests\Support\ImageFixtures in a temp folder removed after each test.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/ai-cleaner-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0777, true);
    $this->cleaner = new ProductImageCleaner;
});

afterEach(function () {
    foreach (glob($this->dir.'/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($this->dir);
});

function rejectionReason(callable $run): ?string
{
    try {
        $run();
    } catch (ImageRejected $rejected) {
        return $rejected->reason;
    }

    return null;
}

describe('clean()', function () {
    it('trims a uniform white border and pads to a white square', function () {
        $source = ImageFixtures::bordered($this->dir.'/in.jpg', 800, 600, 480);

        $this->cleaner->clean($source, $this->dir.'/out.jpg');
        [$width, $height] = getimagesize($this->dir.'/out.jpg');

        expect($width)->toBe($height)
            // The 480 px object plus the 6 % margin each side, not the original 800 px.
            ->and($width)->toBeBetween(520, 600)
            ->and(ImageFixtures::isDark($this->dir.'/out.jpg', intdiv($width, 2), intdiv($height, 2)))->toBeTrue()
            ->and(array_sum(ImageFixtures::pixel($this->dir.'/out.jpg', 1, 1)))->toBeGreaterThan(740);
    });

    it('flattens a transparent PNG onto white', function () {
        $source = ImageFixtures::transparentPng($this->dir.'/in.png');

        $this->cleaner->clean($source, $this->dir.'/out.jpg');
        [$width, $height] = getimagesize($this->dir.'/out.jpg');
        [$r, $g, $b] = ImageFixtures::pixel($this->dir.'/out.jpg', intdiv($width, 2), intdiv($height, 2));

        expect($width)->toBe($height)
            ->and($r)->toBeGreaterThan(180)->and($g)->toBeLessThan(60)
            ->and(array_sum(ImageFixtures::pixel($this->dir.'/out.jpg', 1, 1)))->toBeGreaterThan(740);
    });

    it('skips the trim when it would remove more than 60 % of the picture', function () {
        $source = ImageFixtures::bordered($this->dir.'/in.jpg', 800, 600, 200);

        $this->cleaner->clean($source, $this->dir.'/out.jpg');

        expect(getimagesize($this->dir.'/out.jpg')[0])->toBeGreaterThan(800);
    });

    it('leaves a photo without a uniform light border untouched (only padded)', function () {
        $source = ImageFixtures::bordered($this->dir.'/in.jpg', 800, 400, 100, [30, 30, 40]);

        $this->cleaner->clean($source, $this->dir.'/out.jpg');
        [$width, $height] = getimagesize($this->dir.'/out.jpg');
        $row = intdiv($height, 2);
        $dark = 0;

        for ($x = 0; $x < $width; $x++) {
            $dark += ImageFixtures::isDark($this->dir.'/out.jpg', $x, $row) ? 1 : 0;
        }

        expect($width)->toBe($height)
            // The whole 800 px dark photo is kept (not cropped to the 100 px square).
            ->and($dark)->toBeGreaterThan(780)->toBeLessThan(820);
    });

    it('never upscales a small photo and caps a big one at the configured edge', function () {
        config(['chatbot.ai.product.clean.edge' => 400]);

        $this->cleaner->clean(ImageFixtures::bordered($this->dir.'/small.jpg', 100, 100, 50, [20, 20, 20]), $this->dir.'/small-out.jpg');
        $this->cleaner->clean(ImageFixtures::bordered($this->dir.'/big.jpg', 1200, 1200, 1100, [20, 20, 20]), $this->dir.'/big-out.jpg');

        expect(getimagesize($this->dir.'/small-out.jpg')[0])->toBeLessThanOrEqual(120)
            ->and(getimagesize($this->dir.'/big-out.jpg')[0])->toBe(400);
    });

    it('removes EXIF and GPS data from the output', function () {
        $source = ImageFixtures::withExif($this->dir.'/in.jpg', 1);

        expect(function_exists('exif_read_data'))->toBeTrue()
            ->and(file_get_contents($source))->toContain('SECRET-GPS-MARKER')
            ->and(@exif_read_data($source, 'ANY_TAG', true))->toHaveKey('GPS');

        foreach (['clean', 'plain', 'vision'] as $method) {
            $this->cleaner->{$method}($source, $this->dir."/{$method}.jpg");
            $bytes = (string) file_get_contents($this->dir."/{$method}.jpg");
            $exif = @exif_read_data($this->dir."/{$method}.jpg", 'ANY_TAG', true);

            expect($bytes)->not->toContain('SECRET-GPS-MARKER')->not->toContain('Exif')
                ->and(is_array($exif) ? array_key_exists('GPS', $exif) : false)->toBeFalse();
        }
    });

    it('applies only a valid EXIF orientation', function (int $orientation, bool $rotated) {
        $source = ImageFixtures::withExif($this->dir.'/in.jpg', $orientation, 200, 100);

        $this->cleaner->plain($source, $this->dir.'/out.jpg');
        [$width, $height] = getimagesize($this->dir.'/out.jpg');

        expect([$width, $height])->toBe($rotated ? [100, 200] : [200, 100]);
    })->with([
        'upright' => [1, false],
        'rotate 90' => [6, true],
        'rotate 270' => [8, true],
        'rotate 180' => [3, false],
        'out of range value' => [99, false],
    ]);

    it('neutralises a JPEG/PHP/HTML polyglot by re-encoding', function () {
        $source = ImageFixtures::polyglot($this->dir.'/in.jpg');

        expect(file_get_contents($source))->toContain('<?php');

        foreach (['clean', 'plain', 'vision'] as $method) {
            $this->cleaner->{$method}($source, $this->dir."/{$method}.jpg");

            expect(file_get_contents($this->dir."/{$method}.jpg"))->not->toContain('<?php')->not->toContain('<script')
                ->and(getimagesize($this->dir."/{$method}.jpg")[2])->toBe(IMAGETYPE_JPEG);
        }
    });
});

describe('rejections (PW-1)', function () {
    it('rejects a hostile or unsupported file with a fixed reason and writes nothing', function (string $fixture, string $reason) {
        $source = match ($fixture) {
            'wide' => ImageFixtures::jpeg(ImageFixtures::canvas(4001, 10), $this->dir.'/in.jpg'),
            'tall' => ImageFixtures::jpeg(ImageFixtures::canvas(10, 4001), $this->dir.'/in.jpg'),
            'forged header' => ImageFixtures::forgedPng($this->dir.'/in.png'),
            'gif named jpg' => ImageFixtures::gif($this->dir.'/in.jpg'),
            'bmp' => ImageFixtures::bmp($this->dir.'/in.bmp'),
            'animated webp' => ImageFixtures::animatedWebp($this->dir.'/in.webp'),
            'truncated jpeg' => ImageFixtures::truncatedJpeg($this->dir.'/in.jpg'),
            'html' => tap($this->dir.'/in.jpg', fn (string $path) => file_put_contents($path, '<html><script>alert(1)</script></html>')),
            'svg' => tap($this->dir.'/in.svg', fn (string $path) => file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')),
            'empty' => tap($this->dir.'/in.jpg', fn (string $path) => file_put_contents($path, '')),
            'missing' => $this->dir.'/missing.jpg',
        };

        foreach (['clean', 'plain', 'vision'] as $method) {
            expect(rejectionReason(fn () => $this->cleaner->{$method}($source, $this->dir."/{$method}.jpg")))->toBe($reason)
                ->and(is_file($this->dir."/{$method}.jpg"))->toBeFalse();
        }
    })->with([
        'wider than 4000 px' => ['wide', 'dimensions'],
        'taller than 4000 px' => ['tall', 'dimensions'],
        'forged IHDR (decompression bomb)' => ['forged header', 'dimensions'],
        'GIF with a jpg name' => ['gif named jpg', 'type'],
        'BMP' => ['bmp', 'type'],
        'animated WebP' => ['animated webp', 'animated'],
        'truncated JPEG' => ['truncated jpeg', 'corrupt'],
        'HTML with a jpg name' => ['html', 'type'],
        'SVG' => ['svg', 'type'],
        'empty file' => ['empty', 'size'],
        'missing file' => ['missing', 'corrupt'],
    ]);

    it('rejects a file over 5 MB before looking at its content', function () {
        file_put_contents($this->dir.'/big.jpg', str_repeat('A', ProductImageCleaner::MAX_BYTES + 1));

        expect(rejectionReason(fn () => $this->cleaner->clean($this->dir.'/big.jpg', $this->dir.'/out.jpg')))->toBe('size');
    });

    it('rejects an image whose decode would not fit the free memory, before decoding it', function () {
        $source = ImageFixtures::jpeg(ImageFixtures::canvas(1500, 1500), $this->dir.'/in.jpg');
        $headroom = memory_get_usage(true) + 20 * 1024 * 1024;

        expect(rejectionReason(fn () => (new ProductImageCleaner($headroom))->clean($source, $this->dir.'/out.jpg')))->toBe('memory')
            ->and(is_file($this->dir.'/out.jpg'))->toBeFalse();

        $small = ImageFixtures::bordered($this->dir.'/small.jpg', 500, 500, 100);
        (new ProductImageCleaner($headroom))->clean($small, $this->dir.'/small-out.jpg');

        expect(is_file($this->dir.'/small-out.jpg'))->toBeTrue();
    });

    it('exposes only fixed message keys', function () {
        expect((new ImageRejected('anything'))->messageKey())->toBe('admin.ai_wizard.image_errors.corrupt');

        foreach (ImageRejected::REASONS as $reason) {
            $key = (new ImageRejected($reason))->messageKey();

            foreach (['uz', 'ru', 'en'] as $locale) {
                expect(trans($key, [], $locale))->not->toBe($key);
            }
        }
    });
});

describe('vision() and preview()', function () {
    it('caps the vision copy at the AI edge and megapixels', function () {
        $source = ImageFixtures::bordered($this->dir.'/in.jpg', 3000, 2000, 500, [200, 200, 200]);

        $this->cleaner->vision($source, $this->dir.'/vision.jpg');
        [$width, $height] = getimagesize($this->dir.'/vision.jpg');

        expect(max($width, $height))->toBeLessThanOrEqual(1568)
            ->and($width * $height)->toBeLessThanOrEqual(1_150_000);
    });

    it('writes a small JPEG preview file without EXIF, or false for a bad file', function () {
        $source = ImageFixtures::bordered($this->dir.'/in.jpg', 1200, 900, 300);
        $exifSource = ImageFixtures::withExif($this->dir.'/exif.jpg', 1);

        $written = $this->cleaner->previewFile($source, $this->dir.'/preview.jpg');
        [$width, $height, $type] = getimagesize($this->dir.'/preview.jpg');
        $exifWritten = $this->cleaner->previewFile($exifSource, $this->dir.'/preview-exif.jpg');
        $bytes = (string) file_get_contents($this->dir.'/preview-exif.jpg');

        expect($written)->toBeTrue()
            ->and($type)->toBe(IMAGETYPE_JPEG)
            ->and(max($width, $height))->toBeLessThanOrEqual(400)
            ->and($exifWritten)->toBeTrue()
            ->and($bytes)->not->toContain('Exif')->not->toContain('SECRET-GPS-MARKER')
            ->and($this->cleaner->previewFile($this->dir.'/missing.jpg', $this->dir.'/missing-preview.jpg'))->toBeFalse()
            ->and(file_exists($this->dir.'/missing-preview.jpg'))->toBeFalse();
    });
});
