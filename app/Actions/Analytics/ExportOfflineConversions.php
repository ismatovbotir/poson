<?php

namespace App\Actions\Analytics;

use App\Enums\AnalyticsGoal;
use App\Enums\AuditAction;
use App\Enums\PaymentStatus;
use App\Enums\QuoteStatus;
use App\Enums\SettingsArea;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Analytics\AdClickIds;
use App\Support\Analytics\TrafficRecorder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Offline conversions for Yandex Metrika (plan 40 §3, YD-10–YD-12, YD-16):
 * a UTF-8 CSV "ClientId|Yclid,Target,DateTime,Price,Currency".
 *
 *  - order_paid: not TEST, payment_status paid (refunded excluded),
 *    paid_at in the range; Price = the order total;
 *  - quote_accepted (owner 2026-11-24: included, at any price level):
 *    current status accepted, accepted_at in the range, with a lead; the
 *    ids come from the lead; Price = the quote total;
 *  - DateTime = Unix seconds; Price in soʻm via Money::plainSums(); UZS.
 *
 * Dedupe: the ClientId file holds rows with a ClientID; the Yclid file only
 * rows WITHOUT a ClientID. Order and quote rows are NOT deduplicated against
 * each other: an order converted from a quote (ConvertQuoteToOrder) carries
 * no click ids today, so it never appears twice. Revisit if that changes. Every cell is re-validated and a failing row is
 * skipped; no name, phone, number, ULID, INN, SKU or UTM ever goes out.
 * Range: dates (Y-m-d, Asia/Tashkent), from <= to <= today, from at most
 * max_days_back days back. Admin only (analytics.export) and only while
 * the ad click id switch is on. Each export is audited (type, range, row
 * count; never ids or amounts) and remembered as the last export end.
 */
class ExportOfflineConversions
{
    public const ID_CLIENT = 'client_id';

    public const ID_YCLID = 'yclid';

    public const LAST_EXPORT_KEY = 'analytics.offline_last_export_until';

    public const CURRENCY = 'UZS';

    /**
     * @param  array<string, mixed>  $input  from, to (Y-m-d), id_type, goals (list of goal values)
     * @return array{csv: string, rows: int, filename: string}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(array $input, User $user, ?string $ip): array
    {
        if (! $user->can('analytics.export') || ! AdClickIds::enabled()) {
            throw new AuthorizationException;
        }

        [$from, $to, $idType, $goals] = $this->validated($input);

        $rows = [];

        if (in_array(AnalyticsGoal::OrderPaid, $goals, true)) {
            $rows = [...$rows, ...$this->orderRows($from, $to, $idType)];
        }

        if (in_array(AnalyticsGoal::QuoteAccepted, $goals, true)) {
            $rows = [...$rows, ...$this->quoteRows($from, $to, $idType)];
        }

        $rows = array_values(array_filter($rows, fn (array $row): bool => self::validRow($row, $idType)));
        usort($rows, fn (array $a, array $b): int => $a[2] <=> $b[2]);

        $csv = $this->csv([$idType === self::ID_CLIENT ? 'ClientId' : 'Yclid', 'Target', 'DateTime', 'Price', 'Currency'], $rows);

        $range = $from->toDateString().'_'.$to->toDateString();

        Setting::query()->updateOrCreate(
            ['key' => self::LAST_EXPORT_KEY],
            ['group' => SettingsArea::Analytics->group(), 'value' => $to->toDateString()],
        );

        SettingsAudit::record(SettingsArea::Analytics, 'offline_export.'.$idType.'.'.$range.'.rows_'.count($rows), AuditAction::Exported, $user, $ip);

        Log::info('Offline conversions exported', [
            'user_id' => $user->id,
            'id_type' => $idType,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'goals' => array_map(fn (AnalyticsGoal $goal): string => $goal->value, $goals),
            'rows' => count($rows),
        ]);

        return [
            'csv' => $csv,
            'rows' => count($rows),
            'filename' => 'offline-conversions.csv',
        ];
    }

    /**
     * Goals the CSV may carry (config analytics.offline_conversions.goals, offline goals only).
     *
     * @return list<AnalyticsGoal>
     */
    public static function allowedGoals(): array
    {
        $configured = (array) config('analytics.offline_conversions.goals', []);

        return array_values(array_filter(AnalyticsGoal::offline(), fn (AnalyticsGoal $goal): bool => in_array($goal->value, $configured, true)));
    }

    public static function maxDaysBack(): int
    {
        return max(1, (int) config('analytics.offline_conversions.max_days_back', 21));
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(TrafficRecorder::today(), TrafficRecorder::timezone());
    }

    /**
     * Default range start: the day after the last export, within the window.
     */
    public static function defaultFrom(): string
    {
        $today = self::today();
        $earliest = $today->subDays(self::maxDaysBack());
        $last = Setting::query()->where('key', self::LAST_EXPORT_KEY)->value('value');
        $from = is_string($last) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $last) === 1
            ? CarbonImmutable::parse($last, TrafficRecorder::timezone())->addDay()
            : $earliest;

        return ($from->lt($earliest) ? $earliest : ($from->gt($today) ? $today : $from))->toDateString();
    }

    public static function lastExportUntil(): ?string
    {
        $last = Setting::query()->where('key', self::LAST_EXPORT_KEY)->value('value');

        return is_string($last) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $last) === 1 ? $last : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string, 3: list<AnalyticsGoal>}
     *
     * @throws ValidationException
     */
    protected function validated(array $input): array
    {
        $today = self::today()->toDateString();
        $earliest = self::today()->subDays(self::maxDaysBack())->toDateString();
        $goalValues = array_map(fn (AnalyticsGoal $goal): string => $goal->value, self::allowedGoals());

        $data = Validator::make($input, [
            'from' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:'.$earliest, 'before_or_equal:to'],
            'to' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'id_type' => ['required', 'string', Rule::in([self::ID_CLIENT, self::ID_YCLID])],
            'goals' => ['required', 'array', 'min:1'],
            'goals.*' => ['required', 'string', 'distinct', Rule::in($goalValues)],
        ], [], [
            'from' => __('analytics.campaigns.export.from'),
            'to' => __('analytics.campaigns.export.to'),
            'id_type' => __('analytics.campaigns.export.id_type'),
            'goals' => __('analytics.campaigns.export.goals'),
        ])->validate();

        $timezone = TrafficRecorder::timezone();

        return [
            CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $timezone)->startOfDay(),
            CarbonImmutable::createFromFormat('Y-m-d', $data['to'], $timezone)->endOfDay(),
            $data['id_type'],
            array_map(fn (string $goal): AnalyticsGoal => AnalyticsGoal::from($goal), array_values($data['goals'])),
        ];
    }

    /**
     * @return list<array{0: string|null, 1: string, 2: int, 3: string|null, 4: string}>
     */
    protected function orderRows(CarbonImmutable $from, CarbonImmutable $to, string $idType): array
    {
        return Order::query()->toBase()
            ->where('is_test', false)
            ->where('payment_status', PaymentStatus::Paid->value)
            ->whereBetween('paid_at', [$from->utc(), $to->utc()])
            ->tap(fn ($query) => $this->scopeIds($query, $idType, 'yclid', 'ym_client_id'))
            ->get(['yclid', 'ym_client_id', 'paid_at', 'total'])
            ->map(fn (object $order): array => $this->row($idType, $order->yclid, $order->ym_client_id, AnalyticsGoal::OrderPaid, $order->paid_at, $order->total))
            ->all();
    }

    /**
     * @return list<array{0: string|null, 1: string, 2: int, 3: string|null, 4: string}>
     */
    protected function quoteRows(CarbonImmutable $from, CarbonImmutable $to, string $idType): array
    {
        return Quote::query()->toBase()
            ->join('leads', 'leads.id', '=', 'quotes.lead_id')
            ->where('quotes.status', QuoteStatus::Accepted->value)
            ->whereBetween('quotes.accepted_at', [$from->utc(), $to->utc()])
            ->tap(fn ($query) => $this->scopeIds($query, $idType, 'leads.yclid', 'leads.ym_client_id'))
            ->get(['leads.yclid', 'leads.ym_client_id', 'quotes.accepted_at', 'quotes.total'])
            ->map(fn (object $quote): array => $this->row($idType, $quote->yclid, $quote->ym_client_id, AnalyticsGoal::QuoteAccepted, $quote->accepted_at, $quote->total))
            ->all();
    }

    /**
     * ClientId file: rows with a ClientID; Yclid file: a yclid and NO ClientID (dedupe).
     */
    protected function scopeIds(mixed $query, string $idType, string $yclidColumn, string $clientColumn): void
    {
        if ($idType === self::ID_CLIENT) {
            $query->whereNotNull($clientColumn);

            return;
        }

        $query->whereNotNull($yclidColumn)->whereNull($clientColumn);
    }

    /**
     * @return array{0: string|null, 1: string, 2: int, 3: string|null, 4: string}
     */
    protected function row(string $idType, mixed $yclid, mixed $clientId, AnalyticsGoal $goal, mixed $at, mixed $total): array
    {
        $id = $idType === self::ID_CLIENT ? AdClickIds::cleanClientId($clientId) : AdClickIds::cleanYclid($yclid);
        $timestamp = is_string($at) && $at !== '' ? CarbonImmutable::parse($at, 'UTC')->getTimestamp() : 0;
        $price = is_numeric($total) ? Money::plainSums((int) $total) : null;

        return [$id, $goal->value, $timestamp, $price, self::CURRENCY];
    }

    /**
     * YD-11: digits-only id, a known offline Target, a positive Unix time,
     * a plain number price and UZS; anything else is skipped.
     *
     * @param  array<int, mixed>  $row
     */
    public static function validRow(array $row, string $idType): bool
    {
        $id = $idType === self::ID_CLIENT ? AdClickIds::cleanClientId($row[0] ?? null) : AdClickIds::cleanYclid($row[0] ?? null);
        $goal = is_string($row[1] ?? null) ? AnalyticsGoal::tryFrom($row[1]) : null;

        return $id !== null
            && $goal !== null && $goal->isOffline()
            && is_int($row[2] ?? null) && $row[2] > 0
            && is_string($row[3] ?? null) && preg_match('/^[0-9]{1,17}(\.[0-9]{2})?$/D', $row[3]) === 1
            && ($row[4] ?? null) === self::CURRENCY;
    }

    /**
     * @param  list<string>  $header
     * @param  list<array<int, mixed>>  $rows
     */
    protected function csv(array $header, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, $header, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map('strval', $row), ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
