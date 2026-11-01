<?php

namespace App\Support\Analytics;

use App\Enums\TrafficChannel;
use App\Models\Category;
use App\Models\Product;
use App\Models\TrafficDaily;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Read model of the Analytics and AI visibility screens (plan 04 T11).
 * Reads only the daily aggregates (today's rows are rebuilt every 15
 * minutes by the schedule). Every list is one grouped query; products are
 * loaded in one query. Each result is cached for
 * config('analytics.report_cache_seconds') per method, arguments, range and
 * today's date.
 */
class TrafficReport
{
    /**
     * Day ranges offered by the screens.
     *
     * @var list<int>
     */
    public const RANGES = [7, 30, 90];

    public readonly string $from;

    public readonly string $to;

    public function __construct(public readonly int $days)
    {
        $today = CarbonImmutable::parse(TrafficRecorder::today(), TrafficRecorder::timezone());

        $this->to = $today->toDateString();
        $this->from = $today->subDays($days - 1)->toDateString();
    }

    /**
     * Every date of the range, oldest first.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];
        $date = CarbonImmutable::parse($this->from);

        for ($i = 0; $i < $this->days; $i++) {
            $dates[] = $date->addDays($i)->toDateString();
        }

        return $dates;
    }

    /**
     * @return array{visitors: int, page_views: int, entries: int, leads: int, partner_clicks: int, conversion: float|null}
     */
    public function totals(): array
    {
        return $this->cached('totals', [], function () {
            $row = $this->daily(TrafficDaily::KIND_VISITS, TrafficDaily::DIM_TOTAL)
                ->selectRaw('COALESCE(SUM(visitors), 0) AS visitors, COALESCE(SUM(page_views), 0) AS page_views, COALESCE(SUM(entries), 0) AS entries, COALESCE(SUM(leads), 0) AS leads, COALESCE(SUM(partner_clicks), 0) AS partner_clicks')
                ->first();

            $visitors = (int) ($row->visitors ?? 0);
            $leads = (int) ($row->leads ?? 0);

            return [
                'visitors' => $visitors,
                'page_views' => (int) ($row->page_views ?? 0),
                'entries' => (int) ($row->entries ?? 0),
                'leads' => $leads,
                'partner_clicks' => (int) ($row->partner_clicks ?? 0),
                'conversion' => self::conversion($leads, $visitors),
            ];
        });
    }

    /**
     * Per day, zero-filled.
     *
     * @return list<array{date: string, visitors: int, page_views: int, leads: int}>
     */
    public function series(): array
    {
        return $this->cached('series', [], function () {
            $rows = $this->daily(TrafficDaily::KIND_VISITS, TrafficDaily::DIM_TOTAL)
                ->get(['date', 'visitors', 'page_views', 'leads'])
                ->keyBy(fn (object $row): string => substr((string) $row->date, 0, 10));

            return array_map(fn (string $date): array => [
                'date' => $date,
                'visitors' => (int) ($rows[$date]->visitors ?? 0),
                'page_views' => (int) ($rows[$date]->page_views ?? 0),
                'leads' => (int) ($rows[$date]->leads ?? 0),
            ], $this->dates());
        });
    }

    /**
     * Channels by visitors, with entries, leads and conversion.
     *
     * @return list<array{channel: TrafficChannel, visitors: int, entries: int, leads: int, conversion: float|null}>
     */
    public function channels(): array
    {
        return $this->cached('channels', [], function () {
            return $this->daily(TrafficDaily::KIND_VISITS, TrafficDaily::DIM_CHANNEL)
                ->select('value')
                ->selectRaw('SUM(visitors) AS visitors, SUM(entries) AS entries, SUM(leads) AS leads')
                ->groupBy('value')
                ->orderByDesc('visitors')
                ->get()
                ->filter(fn (object $row): bool => TrafficChannel::tryFrom((string) $row->value) !== null)
                ->map(fn (object $row): array => [
                    'channel' => TrafficChannel::from((string) $row->value),
                    'visitors' => (int) $row->visitors,
                    'entries' => (int) $row->entries,
                    'leads' => (int) $row->leads,
                    'conversion' => self::conversion((int) $row->leads, (int) $row->visitors),
                ])
                ->values()
                ->all();
        });
    }

    /**
     * Top sources (host or utm_source) with their channel.
     *
     * @param  list<TrafficChannel>|null  $channels  Only these channels
     * @return list<array{channel: TrafficChannel, source: string, visitors: int, leads: int, conversion: float|null}>
     */
    public function sources(int $limit = 15, ?array $channels = null): array
    {
        return $this->cached('sources', [$limit, $channels], function () use ($limit, $channels) {
            $query = $this->daily(TrafficDaily::KIND_VISITS, TrafficDaily::DIM_SOURCE)
                ->select('channel', 'value')
                ->selectRaw('SUM(visitors) AS visitors, SUM(leads) AS leads')
                ->groupBy('channel', 'value')
                ->orderByDesc('visitors')
                ->orderByDesc('leads')
                ->limit($limit);

            if ($channels !== null) {
                $query->whereIn('channel', array_map(fn (TrafficChannel $channel): string => $channel->value, $channels));
            }

            return $query->get()
                ->filter(fn (object $row): bool => TrafficChannel::tryFrom((string) $row->channel) !== null)
                ->map(fn (object $row): array => [
                    'channel' => TrafficChannel::from((string) $row->channel),
                    'source' => (string) $row->value,
                    'visitors' => (int) $row->visitors,
                    'leads' => (int) $row->leads,
                    'conversion' => self::conversion((int) $row->leads, (int) $row->visitors),
                ])
                ->values()
                ->all();
        });
    }

    /**
     * Visitors per value of a dimension (device, locale) with their share in %.
     *
     * @return list<array{value: string, visitors: int, share: float}>
     */
    public function breakdown(string $dimension): array
    {
        return $this->cached('breakdown', [$dimension], function () use ($dimension) {
            $rows = $this->daily(TrafficDaily::KIND_VISITS, $dimension)
                ->select('value')
                ->selectRaw('SUM(visitors) AS visitors')
                ->groupBy('value')
                ->orderByDesc('visitors')
                ->get();

            $total = max(1, (int) $rows->sum('visitors'));

            return $rows->map(fn (object $row): array => [
                'value' => (string) $row->value,
                'visitors' => (int) $row->visitors,
                'share' => round((int) $row->visitors / $total * 100, 1),
            ])->values()->all();
        });
    }

    /**
     * AI assistants vs search engines vs everything else.
     *
     * @return array<string, array{visitors: int, leads: int, conversion: float|null}> keys ai, search, other
     */
    public function aiVersusSearch(): array
    {
        $groups = ['ai' => ['visitors' => 0, 'leads' => 0], 'search' => ['visitors' => 0, 'leads' => 0], 'other' => ['visitors' => 0, 'leads' => 0]];

        foreach ($this->channels() as $row) {
            $group = match ($row['channel']) {
                TrafficChannel::Ai => 'ai',
                TrafficChannel::Search => 'search',
                default => 'other',
            };

            $groups[$group]['visitors'] += $row['visitors'];
            $groups[$group]['leads'] += $row['leads'];
        }

        return array_map(fn (array $group): array => [
            ...$group,
            'conversion' => self::conversion($group['leads'], $group['visitors']),
        ], $groups);
    }

    /**
     * Most viewed pages (people) or most crawled pages (bots; one bot or all).
     *
     * @return list<array{path: string, status: int, hits: int, visitors: int}>
     */
    public function topPages(string $kind, ?string $bot = null, int $limit = 15, bool $errorsOnly = false): array
    {
        return $this->cached('topPages', [$kind, $bot, $limit, $errorsOnly], function () use ($kind, $bot, $limit, $errorsOnly) {
            $query = $this->pages($kind, $bot)
                ->select('path', 'status')
                ->selectRaw('SUM(hits) AS hits, SUM(visitors) AS visitors')
                ->groupBy('path', 'status')
                ->orderByDesc('hits')
                ->orderBy('path')
                ->limit($limit);

            // The error list is the client errors (404s and other 4xx); server errors are counted per bot.
            $errorsOnly ? $query->whereBetween('status', [400, 499]) : $query->where('status', '<', 400);

            return $query->get()->map(fn (object $row): array => [
                'path' => (string) $row->path,
                'status' => (int) $row->status,
                'hits' => (int) $row->hits,
                'visitors' => (int) $row->visitors,
            ])->all();
        });
    }

    /**
     * Most viewed / crawled products, each loaded once (one query, trashed included).
     *
     * @return list<array{product: Product, hits: int, visitors: int}>
     */
    public function topProducts(string $kind, ?string $bot = null, int $limit = 10): array
    {
        return $this->cached('topProducts', [$kind, $bot, $limit], function () use ($kind, $bot, $limit) {
            $rows = $this->pages($kind, $bot)
                ->whereNotNull('product_id')
                ->where('status', '<', 400)
                ->select('product_id')
                ->selectRaw('SUM(hits) AS hits, SUM(visitors) AS visitors')
                ->groupBy('product_id')
                ->orderByDesc('hits')
                ->limit($limit)
                ->get();

            $products = Product::withTrashed()
                ->whereKey($rows->pluck('product_id')->all())
                ->get(['id', 'sku', 'name', 'slug', 'deleted_at'])
                ->keyBy('id');

            return $rows
                ->filter(fn (object $row): bool => $products->has((int) $row->product_id))
                ->map(fn (object $row): array => [
                    'product' => $products[(int) $row->product_id],
                    'hits' => (int) $row->hits,
                    'visitors' => (int) $row->visitors,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * Most visited categories in the range (most first); limit is capped at 50.
     *
     * @return list<array{category: Category, hits: int, visitors: int}>
     */
    public function topCategories(string $kind, ?string $bot = null, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        return $this->cached('topCategories', [$kind, $bot, $limit], function () use ($kind, $bot, $limit) {
            $rows = $this->pages($kind, $bot)
                ->whereNotNull('category_id')
                ->where('status', '<', 400)
                ->select('category_id')
                ->selectRaw('SUM(hits) AS hits, SUM(visitors) AS visitors')
                ->groupBy('category_id')
                ->orderByDesc('hits')
                ->limit($limit)
                ->get();

            $categories = Category::query()
                ->whereKey($rows->pluck('category_id')->all())
                ->get(['id', 'name', 'slug'])
                ->keyBy('id');

            return $rows
                ->filter(fn (object $row): bool => $categories->has((int) $row->category_id))
                ->map(fn (object $row): array => [
                    'category' => $categories[(int) $row->category_id],
                    'hits' => (int) $row->hits,
                    'visitors' => (int) $row->visitors,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * "Try" clicks to partner signup pages per product in the range (most first).
     *
     * @return list<array{product: Product, hits: int, visitors: int}>
     */
    public function partnerClicks(int $limit = 10): array
    {
        return $this->topProducts(TrafficDaily::KIND_PARTNER, null, $limit);
    }

    /**
     * Crawlers seen in the range, by hits, with verification counts and a
     * zero-filled daily hits series for the sparkline.
     *
     * @return list<array{bot: BotDefinition, hits: int, ips: int, verified: int, spoofed: int, unverified: int, errors_4xx: int, errors_5xx: int, last_seen_at: CarbonImmutable|null, series: list<int>}>
     */
    public function bots(): array
    {
        return $this->cached('bots', [], function () {
            $rows = $this->daily(TrafficDaily::KIND_BOTS, TrafficDaily::DIM_BOT)
                ->get(['date', 'value', 'hits', 'visitors', 'verified_hits', 'spoofed_hits', 'errors_4xx', 'errors_5xx', 'last_seen_at']);

            $dates = array_flip($this->dates());
            $bots = [];

            foreach ($rows as $row) {
                $definition = BotRegistry::find((string) $row->value);

                if ($definition === null) {
                    continue;
                }

                $key = $definition->key;
                $bots[$key] ??= [
                    'bot' => $definition, 'hits' => 0, 'ips' => 0, 'verified' => 0, 'spoofed' => 0, 'unverified' => 0,
                    'errors_4xx' => 0, 'errors_5xx' => 0, 'last_seen_at' => null, 'series' => array_fill(0, $this->days, 0),
                ];

                $bots[$key]['hits'] += (int) $row->hits;
                $bots[$key]['ips'] += (int) $row->visitors;
                $bots[$key]['verified'] += (int) $row->verified_hits;
                $bots[$key]['spoofed'] += (int) $row->spoofed_hits;
                $bots[$key]['errors_4xx'] += (int) $row->errors_4xx;
                $bots[$key]['errors_5xx'] += (int) $row->errors_5xx;

                $index = $dates[substr((string) $row->date, 0, 10)] ?? null;

                if ($index !== null) {
                    $bots[$key]['series'][$index] += (int) $row->hits;
                }

                if ($row->last_seen_at !== null) {
                    $seen = CarbonImmutable::parse((string) $row->last_seen_at, 'UTC');

                    if ($bots[$key]['last_seen_at'] === null || $seen->greaterThan($bots[$key]['last_seen_at'])) {
                        $bots[$key]['last_seen_at'] = $seen;
                    }
                }
            }

            foreach ($bots as $key => $bot) {
                $bots[$key]['unverified'] = max(0, $bot['hits'] - $bot['verified'] - $bot['spoofed']);
            }

            usort($bots, fn (array $a, array $b): int => $b['hits'] <=> $a['hits']);

            return $bots;
        });
    }

    /**
     * Cache a report result per method, arguments, range and today's date.
     *
     * @template T
     *
     * @param  list<mixed>  $arguments
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function cached(string $method, array $arguments, Closure $callback): mixed
    {
        $seconds = (int) config('analytics.report_cache_seconds', 300);

        if ($seconds <= 0) {
            return $callback();
        }

        $key = 'analytics:report:'.$method.':'.$this->days.':'.TrafficRecorder::today().':'.md5(serialize($arguments));

        return Cache::remember($key, $seconds, $callback);
    }

    public static function conversion(int $leads, int $visitors): ?float
    {
        return $visitors > 0 ? round($leads / $visitors * 100, 1) : null;
    }

    protected function daily(string $kind, string $dimension): Builder
    {
        return DB::table('traffic_daily')
            ->where('kind', $kind)
            ->where('dimension', $dimension)
            ->whereBetween('date', [$this->from, $this->to]);
    }

    protected function pages(string $kind, ?string $bot): Builder
    {
        return DB::table('traffic_daily_pages')
            ->where('kind', $kind)
            ->whereBetween('date', [$this->from, $this->to])
            ->when($kind === TrafficDaily::KIND_BOTS && $bot !== null && $bot !== '', fn (Builder $query) => $query->where('bot', $bot));
    }

    /**
     * Bars of a series for a server-rendered SVG chart (heights in % of the max).
     *
     * @param  list<int>  $values
     * @return Collection<int, float>
     */
    public static function heights(array $values): Collection
    {
        $max = max(1, ...($values ?: [0]));

        return collect($values)->map(fn (int $value): float => round($value / $max * 100, 2));
    }

    /**
     * Polyline points ("x,y x,y …") of a sparkline in a width × height box.
     *
     * @param  list<int>  $values
     */
    public static function sparkline(array $values, int $width = 120, int $height = 28): string
    {
        $count = count($values);

        if ($count === 0) {
            return '';
        }

        $max = max(1, ...$values);
        $step = $count > 1 ? $width / ($count - 1) : 0;
        $points = [];

        foreach (array_values($values) as $index => $value) {
            $x = round($index * $step, 2);
            $y = round($height - 1 - ($value / $max) * ($height - 2), 2);
            $points[] = "{$x},{$y}";
        }

        return implode(' ', $points);
    }
}
