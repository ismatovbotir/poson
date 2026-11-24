<?php

namespace App\Support\Analytics;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Quote;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Admin → Analytics → Campaigns (plan 40 §4, YD-13): leads, orders and
 * accepted quotes of the last N days grouped by (utm_source, utm_medium,
 * utm_campaign), lowercased, "(none)" for a missing tag.
 *
 *  - leads: created in the range; also how many came with a Direct click (yclid);
 *  - orders: created in the range, not TEST, not cancelled: count + placed
 *    value; paid value = those with payment_status paid (refunded excluded);
 *  - quotes: current status accepted, accepted_at in the range, grouped by
 *    the UTM tags of their lead (quotes.lead_id); a quote without a lead
 *    (made by hand) counts in the "(none)" group.
 *
 * Direct only: (utm_source yandex and utm_medium cpc) or a stored yclid.
 * Top MAX_GROUPS groups by activity, the rest is one "other" row. Only the
 * needed columns are read; grouping in PHP; cached like TrafficReport.
 * Money stays in tiyin (the view formats it with Money).
 */
class CampaignReport
{
    public const MAX_GROUPS = 100;

    public const NONE = '(none)';

    public const LABEL_MAX = 80;

    public const OTHER = '__other__';

    public readonly CarbonImmutable $from;

    public readonly CarbonImmutable $to;

    public function __construct(public readonly int $days, public readonly bool $directOnly = false)
    {
        $today = CarbonImmutable::parse(TrafficRecorder::today(), TrafficRecorder::timezone());

        $this->from = $today->subDays($days - 1)->startOfDay();
        $this->to = $today->endOfDay();
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function build(): array
    {
        return $this->cached(fn (): array => $this->compute());
    }

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    protected function compute(): array
    {
        $groups = [];
        $range = [$this->from->utc(), $this->to->utc()];

        $leads = Lead::query()->toBase()
            ->whereBetween('created_at', $range)
            ->get(['utm', 'yclid']);

        foreach ($leads as $lead) {
            $utm = $this->decode($lead->utm);

            if (! $this->included($utm, $lead->yclid)) {
                continue;
            }

            $row = &$this->group($groups, $utm);
            $row['leads']++;
            $row['leads_with_click'] += $lead->yclid !== null ? 1 : 0;
            unset($row);
        }

        $orders = Order::query()->toBase()
            ->whereBetween('created_at', $range)
            ->where('is_test', false)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->get(['attribution', 'yclid', 'total', 'payment_status']);

        foreach ($orders as $order) {
            $utm = Attribution::utm(Attribution::sanitize($this->decode($order->attribution)));

            if (! $this->included($utm, $order->yclid)) {
                continue;
            }

            $row = &$this->group($groups, $utm);
            $row['orders']++;
            $row['orders_value'] += (int) $order->total;
            $row['paid_value'] += $order->payment_status === PaymentStatus::Paid->value ? (int) $order->total : 0;
            unset($row);
        }

        $quotes = Quote::query()->toBase()
            ->leftJoin('leads', 'leads.id', '=', 'quotes.lead_id')
            ->where('quotes.status', QuoteStatus::Accepted->value)
            ->whereBetween('quotes.accepted_at', $range)
            ->get(['quotes.total', 'leads.utm', 'leads.yclid']);

        foreach ($quotes as $quote) {
            $utm = $this->decode($quote->utm);

            if (! $this->included($utm, $quote->yclid)) {
                continue;
            }

            $row = &$this->group($groups, $utm);
            $row['quotes']++;
            $row['quotes_value'] += (int) $quote->total;
            unset($row);
        }

        $rows = array_values($groups);
        usort($rows, fn (array $a, array $b): int => [$b['leads'] + $b['orders'] + $b['quotes'], $b['orders_value']] <=> [$a['leads'] + $a['orders'] + $a['quotes'], $a['orders_value']]);

        if (count($rows) > self::MAX_GROUPS) {
            $other = self::emptyRow(self::OTHER, self::OTHER, self::OTHER);

            foreach (array_slice($rows, self::MAX_GROUPS) as $row) {
                $other = self::add($other, $row);
            }

            $rows = [...array_slice($rows, 0, self::MAX_GROUPS), $other];
        }

        $totals = array_reduce($rows, fn (array $carry, array $row): array => self::add($carry, $row), self::emptyRow('', '', ''));

        return [
            'rows' => $rows,
            'totals' => array_intersect_key($totals, array_flip(self::METRICS)),
        ];
    }

    /**
     * @var list<string>
     */
    public const METRICS = ['leads', 'leads_with_click', 'orders', 'orders_value', 'paid_value', 'quotes', 'quotes_value'];

    /**
     * A Direct click: utm yandex + cpc, or a stored yclid.
     *
     * @param  array<string, string>  $utm
     */
    protected function included(array $utm, mixed $yclid): bool
    {
        if (! $this->directOnly) {
            return true;
        }

        return $yclid !== null
            || (Str::lower($utm['utm_source'] ?? '') === 'yandex' && Str::lower($utm['utm_medium'] ?? '') === 'cpc');
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array<string, string>  $utm
     * @return array<string, mixed>
     */
    protected function &group(array &$groups, array $utm): array
    {
        $source = self::label($utm['utm_source'] ?? null);
        $medium = self::label($utm['utm_medium'] ?? null);
        $campaign = self::label($utm['utm_campaign'] ?? null);
        $key = $source."\n".$medium."\n".$campaign;

        $groups[$key] ??= self::emptyRow($source, $medium, $campaign);

        return $groups[$key];
    }

    /**
     * Lowercased, single-line, cut to LABEL_MAX; "(none)" when missing.
     */
    public static function label(mixed $value): string
    {
        $value = is_string($value) ? Str::lower(trim((string) preg_replace('/\p{Cc}+/u', '', $value))) : '';

        return $value === '' ? self::NONE : mb_substr($value, 0, self::LABEL_MAX);
    }

    /**
     * @return array<string, string>
     */
    protected function decode(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : null;

        return is_array($value) ? Attribution::sanitizeUtm($value) : [];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function emptyRow(string $source, string $medium, string $campaign): array
    {
        return ['source' => $source, 'medium' => $medium, 'campaign' => $campaign, ...array_fill_keys(self::METRICS, 0)];
    }

    /**
     * @param  array<string, mixed>  $carry
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected static function add(array $carry, array $row): array
    {
        foreach (self::METRICS as $metric) {
            $carry[$metric] += $row[$metric];
        }

        return $carry;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function cached(Closure $callback): mixed
    {
        $seconds = (int) config('analytics.report_cache_seconds', 300);

        if ($seconds <= 0) {
            return $callback();
        }

        return Cache::remember('analytics:campaigns:'.$this->days.':'.(int) $this->directOnly.':'.TrafficRecorder::today(), $seconds, $callback);
    }
}
