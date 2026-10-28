<?php

namespace App\Actions\Analytics;

use App\Enums\VisitType;
use App\Models\TrafficDaily;
use App\Support\Analytics\BotRegistry;
use App\Support\Analytics\TrafficRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Roll the raw rows of one day (analytics timezone) up into traffic_daily
 * and traffic_daily_pages (plan 04 4c). Idempotent: the day's aggregate rows
 * are replaced in one transaction (delete + upsert on the unique
 * dimensions), so it can run every 15 minutes for today and again nightly
 * for yesterday (after VerifyBotHits) with the same result for the same raw
 * data. Only the top N pages per day / kind / bot are kept. Partner clicks
 * (VisitType::PartnerClick) are counted in traffic_daily.partner_clicks and,
 * per /{locale}/go/{slug} path with its product, in traffic_daily_pages
 * (kind partner); they are neither page views nor leads. "Add to cart"
 * rows (VisitType::AddToCart, plan 11a) are counted in
 * traffic_daily.add_to_carts.
 *
 * One run per date at a time (cache lock; waits up to
 * config analytics.aggregate_lock_wait seconds, otherwise skips: the other
 * run produces the same rows); the transaction is retried on deadlock.
 */
class AggregateTraffic
{
    public const LOCK_SECONDS = 120;

    /**
     * @return array{daily: int, pages: int, skipped: bool}
     */
    public function handle(string $date): array
    {
        $date = CarbonImmutable::parse($date, TrafficRecorder::timezone())->toDateString();

        try {
            return Cache::lock("analytics:aggregate:{$date}", self::LOCK_SECONDS)
                ->block((int) config('analytics.aggregate_lock_wait', 5), fn (): array => $this->aggregate($date));
        } catch (LockTimeoutException) {
            return ['daily' => 0, 'pages' => 0, 'skipped' => true];
        }
    }

    /**
     * @return array{daily: int, pages: int, skipped: bool}
     */
    protected function aggregate(string $date): array
    {
        [$from, $to] = TrafficRecorder::dayBounds($date);
        $now = now();

        $daily = [...$this->visitRows($date, $from, $to), ...$this->botRows($date, $from, $to)];
        $pages = [...$this->visitPages($date, $from, $to), ...$this->partnerPages($date, $from, $to), ...$this->botPages($date, $from, $to)];

        foreach ($daily as $index => $row) {
            $daily[$index]['updated_at'] = $now;
        }

        foreach ($pages as $index => $row) {
            $pages[$index]['updated_at'] = $now;
        }

        DB::transaction(function () use ($date, $daily, $pages): void {
            DB::table('traffic_daily')->where('date', $date)->delete();
            DB::table('traffic_daily_pages')->where('date', $date)->delete();

            foreach (array_chunk($daily, 200) as $chunk) {
                DB::table('traffic_daily')->upsert(
                    $chunk,
                    ['date', 'kind', 'dimension', 'channel', 'value'],
                    ['visitors', 'page_views', 'entries', 'leads', 'partner_clicks', 'add_to_carts', 'hits', 'verified_hits', 'spoofed_hits', 'errors_4xx', 'errors_5xx', 'last_seen_at', 'updated_at'],
                );
            }

            foreach (array_chunk($pages, 200) as $chunk) {
                DB::table('traffic_daily_pages')->upsert(
                    $chunk,
                    ['date', 'kind', 'bot', 'path', 'status'],
                    ['route_name', 'product_id', 'category_id', 'hits', 'visitors', 'updated_at'],
                );
            }
        }, 3);

        return ['daily' => count($daily), 'pages' => count($pages), 'skipped' => false];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function visitRows(string $date, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dimensions = [
            TrafficDaily::DIM_TOTAL => [],
            TrafficDaily::DIM_CHANNEL => ['channel'],
            TrafficDaily::DIM_SOURCE => ['channel', 'source'],
            TrafficDaily::DIM_DEVICE => ['device'],
            TrafficDaily::DIM_LOCALE => ['locale'],
        ];

        $rows = [];

        foreach ($dimensions as $dimension => $columns) {
            $query = $this->visits($from, $to)->selectRaw(implode(', ', [
                'COUNT(DISTINCT visitor_hash) AS visitors',
                "SUM(CASE WHEN type = 'page_view' THEN 1 ELSE 0 END) AS page_views",
                "SUM(CASE WHEN type = 'page_view' AND is_entry = 1 THEN 1 ELSE 0 END) AS entries",
                "SUM(CASE WHEN type = 'lead' THEN 1 ELSE 0 END) AS leads",
                "SUM(CASE WHEN type = 'partner_click' THEN 1 ELSE 0 END) AS partner_clicks",
                "SUM(CASE WHEN type = 'add_to_cart' THEN 1 ELSE 0 END) AS add_to_carts",
                "SUM(CASE WHEN type = 'page_view' AND status >= 400 AND status < 500 THEN 1 ELSE 0 END) AS errors_4xx",
                "SUM(CASE WHEN type = 'page_view' AND status >= 500 THEN 1 ELSE 0 END) AS errors_5xx",
                'MAX(occurred_at) AS last_seen_at',
            ]));

            foreach ($columns as $column) {
                $query->addSelect($column)->groupBy($column)->whereNotNull($column);
            }

            foreach ($query->get() as $result) {
                if ((int) $result->visitors === 0) {
                    continue;
                }

                $rows[] = [
                    'date' => $date,
                    'kind' => TrafficDaily::KIND_VISITS,
                    'dimension' => $dimension,
                    'channel' => $dimension === TrafficDaily::DIM_SOURCE ? (string) $result->channel : '',
                    'value' => match ($dimension) {
                        TrafficDaily::DIM_TOTAL => '',
                        TrafficDaily::DIM_SOURCE => mb_substr((string) $result->source, 0, 100),
                        default => mb_substr((string) $result->{$columns[0]}, 0, 100),
                    },
                    'visitors' => (int) $result->visitors,
                    'page_views' => (int) $result->page_views,
                    'entries' => (int) $result->entries,
                    'leads' => (int) $result->leads,
                    'partner_clicks' => (int) $result->partner_clicks,
                    'add_to_carts' => (int) $result->add_to_carts,
                    'hits' => 0,
                    'verified_hits' => 0,
                    'spoofed_hits' => 0,
                    'errors_4xx' => (int) $result->errors_4xx,
                    'errors_5xx' => (int) $result->errors_5xx,
                    'last_seen_at' => $result->last_seen_at,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function botRows(string $date, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $results = $this->botHits($from, $to)
            ->select('bot')
            ->selectRaw(implode(', ', [
                'COUNT(*) AS hits',
                'COUNT(DISTINCT ip) AS visitors',
                'SUM(CASE WHEN verified = 1 THEN 1 ELSE 0 END) AS verified_hits',
                'SUM(CASE WHEN verified = 0 THEN 1 ELSE 0 END) AS spoofed_hits',
                'SUM(CASE WHEN status >= 400 AND status < 500 THEN 1 ELSE 0 END) AS errors_4xx',
                'SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END) AS errors_5xx',
                'MAX(occurred_at) AS last_seen_at',
            ]))
            ->groupBy('bot')
            ->get();

        $rows = [];

        foreach ($results as $result) {
            $rows[] = [
                'date' => $date,
                'kind' => TrafficDaily::KIND_BOTS,
                'dimension' => TrafficDaily::DIM_BOT,
                'channel' => '',
                'value' => (string) $result->bot,
                'visitors' => (int) $result->visitors,
                'page_views' => 0,
                'entries' => 0,
                'leads' => 0,
                'partner_clicks' => 0,
                'add_to_carts' => 0,
                'hits' => (int) $result->hits,
                'verified_hits' => (int) $result->verified_hits,
                'spoofed_hits' => (int) $result->spoofed_hits,
                'errors_4xx' => (int) $result->errors_4xx,
                'errors_5xx' => (int) $result->errors_5xx,
                'last_seen_at' => $result->last_seen_at,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function visitPages(string $date, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $results = $this->visits($from, $to)
            ->where('type', VisitType::PageView->value)
            ->select('path', 'status')
            ->selectRaw('COUNT(*) AS hits, COUNT(DISTINCT visitor_hash) AS visitors, MAX(route_name) AS route_name, MAX(product_id) AS product_id, MAX(category_id) AS category_id')
            ->groupBy('path', 'status')
            ->orderByDesc('hits')
            ->orderBy('path')
            ->limit($this->topPages())
            ->get();

        return $this->pageRows($date, TrafficDaily::KIND_VISITS, '', $results->all());
    }

    /**
     * Partner clicks per redirect path (one per product and locale).
     *
     * @return list<array<string, mixed>>
     */
    protected function partnerPages(string $date, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $results = $this->visits($from, $to)
            ->where('type', VisitType::PartnerClick->value)
            ->select('path', 'status')
            ->selectRaw('COUNT(*) AS hits, COUNT(DISTINCT visitor_hash) AS visitors, MAX(route_name) AS route_name, MAX(product_id) AS product_id, MAX(category_id) AS category_id')
            ->groupBy('path', 'status')
            ->orderByDesc('hits')
            ->orderBy('path')
            ->limit($this->topPages())
            ->get();

        return $this->pageRows($date, TrafficDaily::KIND_PARTNER, '', $results->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function botPages(string $date, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        $bots = $this->botHits($from, $to)->distinct()->pluck('bot');

        foreach ($bots as $bot) {
            $results = $this->botHits($from, $to)
                ->where('bot', $bot)
                ->select('path', 'status')
                ->selectRaw('COUNT(*) AS hits, COUNT(DISTINCT ip) AS visitors, MAX(route_name) AS route_name, MAX(product_id) AS product_id, MAX(category_id) AS category_id')
                ->groupBy('path', 'status')
                ->orderByDesc('hits')
                ->orderBy('path')
                ->limit($this->topPages())
                ->get();

            array_push($rows, ...$this->pageRows($date, TrafficDaily::KIND_BOTS, (string) $bot, $results->all()));
        }

        return $rows;
    }

    /**
     * @param  list<object>  $results
     * @return list<array<string, mixed>>
     */
    protected function pageRows(string $date, string $kind, string $bot, array $results): array
    {
        $rows = [];

        foreach ($results as $result) {
            $rows[] = [
                'date' => $date,
                'kind' => $kind,
                'bot' => mb_substr($bot, 0, 40),
                'path' => (string) $result->path,
                'status' => (int) $result->status,
                'route_name' => $result->route_name,
                'product_id' => $result->product_id !== null ? (int) $result->product_id : null,
                'category_id' => $result->category_id !== null ? (int) $result->category_id : null,
                'hits' => (int) $result->hits,
                'visitors' => (int) $result->visitors,
            ];
        }

        return $rows;
    }

    protected function visits(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('site_visits')->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to);
    }

    protected function botHits(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('bot_hits')->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)
            ->whereIn('bot', array_keys(BotRegistry::all()));
    }

    protected function topPages(): int
    {
        return max(1, (int) config('analytics.top_pages', 500));
    }
}
