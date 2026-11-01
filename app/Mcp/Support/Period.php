<?php

namespace App\Mcp\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Reporting period of an MCP stats tool (plan 13 M2-S1): either `days`
 * (1..366, default 30, ending today) or an explicit `from`/`to` (Y-m-d,
 * inclusive, at most 366 days). Days are Asia/Tashkent days; the UTC
 * boundaries are for queries. Invalid input is an error, never clamped, and
 * the messages never echo the value.
 */
final class Period
{
    public const TIMEZONE = 'Asia/Tashkent';

    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 366;

    private function __construct(
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
    ) {}

    /**
     * Rules to merge into a tool's rules().
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'days' => ['nullable', 'integer', 'between:1,'.self::MAX_DAYS, 'prohibits:from,to'],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d', 'before_or_equal:to'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'before_or_equal:'.self::today()->toDateString()],
        ];
    }

    /**
     * Builds the period from already validated arguments.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException when the range is longer than MAX_DAYS
     */
    public static function make(array $input): self
    {
        $from = $input['from'] ?? null;
        $to = $input['to'] ?? null;

        if (is_string($from) && is_string($to)) {
            $period = new self(
                CarbonImmutable::createFromFormat('!Y-m-d', $from, self::TIMEZONE),
                CarbonImmutable::createFromFormat('!Y-m-d', $to, self::TIMEZONE),
            );

            if ($period->days() > self::MAX_DAYS) {
                throw ValidationException::withMessages(['to' => 'The date range must not be longer than '.self::MAX_DAYS.' days.']);
            }

            return $period;
        }

        $days = is_numeric($input['days'] ?? null) ? (int) $input['days'] : self::DEFAULT_DAYS;
        $days = max(1, min(self::MAX_DAYS, $days));
        $today = self::today();

        return new self($today->subDays($days - 1), $today);
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    /**
     * First day (Tashkent midnight) as UTC, inclusive.
     */
    public function fromUtc(): CarbonImmutable
    {
        return $this->from->utc();
    }

    /**
     * Midnight after the last day as UTC, exclusive: use `< toUtc()`.
     */
    public function toUtc(): CarbonImmutable
    {
        return $this->to->addDay()->utc();
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function label(): string
    {
        return $this->fromDate().' – '.$this->toDate();
    }

    /**
     * The period of the same length right before this one (for compare).
     */
    public function previous(): self
    {
        return new self($this->from->subDays($this->days()), $this->from->subDay());
    }

    /**
     * SQL for the Tashkent day / Monday-week / month bucket of a UTC column.
     * `$column` must be an internal constant, never input. Keys: day and week
     * `Y-m-d`, month `Y-m`.
     */
    public static function sqlBucket(string $column, string $grain, string $driver): string
    {
        $hours = (int) (now(self::TIMEZONE)->getOffset() / 3600);
        $sqlite = $driver === 'sqlite';
        $local = $sqlite ? "datetime({$column}, '+{$hours} hours')" : "DATE_ADD({$column}, INTERVAL {$hours} HOUR)";

        return match (true) {
            $grain === 'day' && $sqlite => "date({$local})",
            $grain === 'day' => "DATE({$local})",
            $grain === 'month' && $sqlite => "strftime('%Y-%m', {$local})",
            $grain === 'month' => "DATE_FORMAT({$local}, '%Y-%m')",
            $sqlite => "date({$local}, '-' || ((CAST(strftime('%w', {$local}) AS INTEGER) + 6) % 7) || ' days')",
            default => "DATE_SUB(DATE({$local}), INTERVAL WEEKDAY({$local}) DAY)",
        };
    }
}
