<?php

use App\Enums\NewsKind;
use App\Models\NewsPost;
use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoNewsSeeder;

it('does nothing outside local and staging', function () {
    $this->seed(DemoNewsSeeder::class);

    expect(NewsPost::query()->count())->toBe(0);
});

it('seeds three demo posts once on local: two visible, one scheduled', function () {
    app()['env'] = 'local';
    Product::factory()->count(4)->create();

    $this->seed(DemoNewsSeeder::class);
    $this->seed(DemoNewsSeeder::class);

    $posts = NewsPost::query()->get();

    expect($posts)->toHaveCount(3)
        ->and($posts->map(fn (NewsPost $post): string => $post->state()->value)->sort()->values()->all())->toBe(['published', 'published', 'scheduled'])
        ->and(NewsPost::query()->visibleIn('uz')->count())->toBe(2)
        ->and(NewsPost::query()->visibleIn('en')->count())->toBe(1)
        ->and(NewsPost::query()->whereHas('products')->count())->toBeGreaterThan(0);

    foreach ($posts as $post) {
        foreach ($post->locales as $locale) {
            expectNoBannedWording($post->getTranslation('title', $locale).' '.$post->getTranslation('body', $locale));
        }
    }
});

it('sets the translated locales when run from DatabaseSeeder without model events', function () {
    app()['env'] = 'local';

    $this->seed(DatabaseSeeder::class);

    // DatabaseSeeder also seeds the knowledge articles (drafts, kind article): count news only.
    expect(NewsPost::query()->ofKind(NewsKind::News)->count())->toBe(3)
        ->and(NewsPost::query()->visibleIn('ru')->count())->toBe(2);
});
