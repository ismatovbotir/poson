<?php

use App\Models\Category;
use App\Models\Product;
use App\Support\Catalog\CategoryFacetCache;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

/*
| Plan 45 B / MA-53: tma:warm-catalog fills the Mini App facet cache and is
| scheduled before the queue worker.
*/

it('fills every visible category in every locale and skips hidden ones', function () {
    $visible = Category::factory()->create();
    $child = Category::factory()->child($visible)->create();
    $hidden = Category::factory()->inactive()->create();
    $underHidden = Category::factory()->child($hidden)->create();
    Product::factory()->inCategory($child)->create();

    $this->artisan('tma:warm-catalog')->assertSuccessful();

    foreach (['uz', 'ru', 'en'] as $locale) {
        expect(Cache::get(CategoryFacetCache::key($visible->id, $locale)))->toBeArray()
            ->and(Cache::get(CategoryFacetCache::key($child->id, $locale)))->toBeArray()
            ->and(Cache::has(CategoryFacetCache::key($hidden->id, $locale)))->toBeFalse()
            ->and(Cache::has(CategoryFacetCache::key($underHidden->id, $locale)))->toBeFalse();
    }

    expect(CategoryFacetCache::warmedAt())->toMatchArray(['entries' => 6])
        ->and(CategoryFacetCache::warmedAt()['ms'])->toBeInt()
        ->and(app()->getLocale())->toBe('uz');
});

it('builds the facets in the locale of each entry', function () {
    $category = Category::factory()->create();
    Product::factory()->inCategory($category)->create();

    $this->artisan('tma:warm-catalog')->assertSuccessful();

    $labels = fn (string $locale): string => Cache::get(CategoryFacetCache::key($category->id, $locale))['stock'][0]['label'];

    expect($labels('uz'))->toBe(__('site.stock.in_stock', [], 'uz'))
        ->and($labels('ru'))->toBe(__('site.stock.in_stock', [], 'ru'))
        ->and($labels('en'))->toBe(__('site.stock.in_stock', [], 'en'));
});

it('skips while the shared lock is held', function () {
    Category::factory()->create();
    $lock = CategoryFacetCache::lock();
    expect($lock->get())->toBeTrue();

    $this->artisan('tma:warm-catalog')->assertSuccessful()->expectsOutputToContain('skipped');

    expect(CategoryFacetCache::warmedAt())->toBeNull();

    $lock->release();
});

it('has an English description and help', function () {
    $command = collect(app(Kernel::class)->all())->get('tma:warm-catalog');

    expect($command->getDescription())->not->toBe('')
        ->and($command->getHelp())->toContain('Scheduled every 6 hours');
});

it('schedules the warm every 6 hours before the queue worker, which stays last (MA-53)', function () {
    $events = collect(app(Schedule::class)->events())->values();
    $warm = $events->search(fn (Event $event): bool => str_contains((string) $event->command, 'tma:warm-catalog'));
    $worker = $events->search(fn (Event $event): bool => $event->description === 'queue-worker');

    expect($warm)->toBeInt()
        ->and($events[$warm]->expression)->toBe('0 */6 * * *')
        ->and($events[$warm]->withoutOverlapping)->toBeTrue()
        ->and($events[$warm]->expiresAt)->toBe(10)
        ->and($events[$warm]->onOneServer)->toBeTrue()
        ->and($worker)->toBe($events->count() - 1)
        ->and($warm)->toBeLessThan($worker);
});

it('keeps the queue worker the last Schedule:: call and the storeOutput loop the last statement', function () {
    $source = file_get_contents(base_path('routes/console.php'));
    $calls = preg_match_all('/^Schedule::(command|job|call)\(/m', $source, $matches, PREG_OFFSET_CAPTURE);
    $lastCall = $matches[0][$calls - 1][1];
    $statements = trim(preg_replace('#/\*.*?\*/#s', '', $source));

    expect(substr($source, $lastCall, 40))->toContain("Schedule::command('queue:work'")
        ->and(strpos($source, "Schedule::command('tma:warm-catalog')"))->toBeLessThan($lastCall)
        ->and($statements)->toEndWith("foreach (Schedule::events() as \$scheduledEvent) {\n    if (! \$scheduledEvent instanceof CallbackEvent && in_array(\$scheduledEvent->output, ['/dev/null', 'NUL'], true)) {\n        \$scheduledEvent->storeOutput();\n    }\n}");
});
