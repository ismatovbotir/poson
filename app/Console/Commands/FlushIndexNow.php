<?php

namespace App\Console\Commands;

use App\Actions\Seo\RefreshSitemap;
use App\Jobs\SubmitIndexNow;
use App\Support\Site\IndexNow;
use App\Support\Site\Sitemap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Plan 18: compares the sitemap with the snapshot of the last run (added and
 * removed URLs), adds the URLs of changed records, and queues one
 * SubmitIndexNow batch (≤ 10,000 URLs, the rest waits in the backlog). The
 * first run after enabling only stores the snapshot. Output: counts only.
 */
#[Signature('indexnow:flush')]
#[Description('Notify IndexNow (Bing, Yandex) about added, changed and removed public URLs')]
class FlushIndexNow extends Command
{
    public function handle(Sitemap $sitemap, RefreshSitemap $refresh): int
    {
        if (! IndexNow::isActive()) {
            IndexNow::forgetState();
            $this->line('IndexNow is not active.');

            return self::SUCCESS;
        }

        $entries = $sitemap->urls();

        if ($entries === null) {
            $refresh->handle();
            $entries = $sitemap->urls();
        }

        if ($entries === null) {
            $this->line('Sitemap is being built; retrying next run.');

            return self::SUCCESS;
        }

        $current = array_values(array_unique(array_column($entries, 'loc')));
        sort($current);

        $snapshot = Cache::get(IndexNow::SNAPSHOT_KEY);

        if (! is_array($snapshot)) {
            IndexNow::drain();
            Cache::forever(IndexNow::SNAPSHOT_KEY, $current);
            IndexNow::log(count($current), 'seeded', 'seeded');
            $this->line('Snapshot stored: '.count($current).' URLs.');

            return self::SUCCESS;
        }

        $currentSet = array_fill_keys($current, true);
        $added = array_values(array_diff($current, $snapshot));
        $removed = array_values(array_diff($snapshot, $current));
        $changed = IndexNow::changedUrls(IndexNow::drain(), $currentSet);

        $urls = array_values(array_unique([...IndexNow::backlog(), ...$removed, ...$added, ...$changed]));

        if ($urls === []) {
            Cache::forever(IndexNow::SNAPSHOT_KEY, $current);
            $this->line('Nothing to submit.');

            return self::SUCCESS;
        }

        if (! Cache::add(IndexNow::INFLIGHT_KEY, true, IndexNow::INFLIGHT_SECONDS)) {
            Cache::forever(IndexNow::SNAPSHOT_KEY, $current);
            IndexNow::storeBacklog($urls);
            $this->line('A submission is still running; '.count($urls).' URLs carried over.');

            return self::SUCCESS;
        }

        $batch = array_slice($urls, 0, IndexNow::MAX_URLS);

        try {
            SubmitIndexNow::dispatch($batch);
        } catch (Throwable $e) {
            Cache::forget(IndexNow::INFLIGHT_KEY);
            IndexNow::storeBacklog($urls);

            throw $e;
        }

        Cache::forever(IndexNow::SNAPSHOT_KEY, $current);
        IndexNow::storeBacklog(array_slice($urls, IndexNow::MAX_URLS));
        $this->line('Queued '.count($batch).' URLs.');

        return self::SUCCESS;
    }
}
