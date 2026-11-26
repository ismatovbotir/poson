<?php

namespace App\Actions\Telegram\MiniApp;

use App\Models\Category;
use App\Support\Catalog\CatalogQuery;
use App\Support\Catalog\CategoryFacetCache;
use App\Support\Catalog\CategoryTree;
use App\Support\Site\HomeCache;
use Illuminate\Support\Facades\Log;

/**
 * Fill the Mini App facet cache (plan 45 B): the unfiltered facets of every
 * visible category in every supported locale. Hidden categories are
 * skipped. Runs under the lock it shares with the admin "Rebuild" button
 * (MA-52), so two warms never run at once; the scheduled warm and the
 * button only differ in $bump (the button first makes every stored entry
 * unreachable). Writes tma:catalog:warmed = {at, entries, ms}.
 *
 * No HTTP or Telegram call. Logs the entry count and duration only (info;
 * a warning above SLOW_MS).
 */
class WarmTmaCatalog
{
    public const SLOW_MS = 30_000;

    /**
     * @return array{at: string, entries: int, ms: int}|null Null when another warm holds the lock
     */
    public function handle(bool $bump = false): ?array
    {
        $result = CategoryFacetCache::lock()->get(function () use ($bump): array {
            if ($bump) {
                HomeCache::bump();
            }

            return $this->warm();
        });

        return is_array($result) ? $result : null;
    }

    /**
     * @return array{at: string, entries: int, ms: int}
     */
    protected function warm(): array
    {
        $started = hrtime(true);
        $entries = 0;
        $previous = app()->getLocale();
        $categories = Category::query()->whereIn('id', CategoryTree::visibleIds())->get();

        try {
            foreach ((array) config('app.supported_locales', []) as $locale) {
                app()->setLocale($locale);

                foreach ($categories as $category) {
                    CategoryFacetCache::refresh($category, $locale, fn (): array => (new CatalogQuery(category: $category))->facets());
                    $entries++;
                }
            }
        } finally {
            app()->setLocale($previous);
        }

        $warmed = [
            'at' => now()->toIso8601String(),
            'entries' => $entries,
            'ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ];

        CategoryFacetCache::markWarmed($warmed);

        Log::info('tma.catalog_warm', ['entries' => $warmed['entries'], 'ms' => $warmed['ms']]);

        if ($warmed['ms'] > self::SLOW_MS) {
            Log::warning('tma.catalog_warm_slow', ['entries' => $warmed['entries'], 'ms' => $warmed['ms']]);
        }

        return $warmed;
    }
}
