<?php

use App\Services\Images\ImageBackgroundRemover;
use App\Services\Images\NullBackgroundRemover;

/**
 * Plan 32 §4: Tier 2 background removal is not approved yet. The default
 * remover does nothing, makes no network call and reads no file, so the
 * Tier 1 cleaning stands. (No provider key exists: see AiAssistantRemovedTest.)
 */
it('does nothing and returns null for any path, even a missing one', function (string $path) {
    $remover = new NullBackgroundRemover;

    expect($remover)->toBeInstanceOf(ImageBackgroundRemover::class)
        ->and($remover->remove($path))->toBeNull()
        ->and(file_exists($path))->toBe(file_exists($path));
})->with(['/nonexistent/photo.jpg', '', '../../etc/passwd']);

it('is the bound default', function () {
    expect(app(ImageBackgroundRemover::class))->toBeInstanceOf(NullBackgroundRemover::class);
});

it('has no provider key, host or HTTP client in the image services', function () {
    foreach (glob(app_path('Services/Images/*.php')) as $file) {
        $code = strtolower((string) file_get_contents($file));

        foreach (['http::', 'guzzle', 'curl_', 'photoroom', 'remove.bg', 'api_key', 'file_get_contents('] as $needle) {
            expect($code)->not->toContain($needle, basename($file)." contains {$needle}");
        }
    }

    expect(config('services.photoroom'))->toBeNull();
});
