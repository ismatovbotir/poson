<?php

namespace App\Services\Ai;

/**
 * Request cost from token usage. Rates are USD per million tokens, which is
 * the same number as micro-USD per token (config ai-assist.rates).
 *
 * Order (plan 10): the model's configured rate, else 0 for an OpenRouter
 * ":free" model, else the fallback (Opus) rate. The exact provider cost
 * (OpenRouter usage.cost) is used before this where an answer reports it
 * (TextResult / AiException).
 */
final class AiCost
{
    public static function microUsd(string $model, int $inputTokens, int $outputTokens): int
    {
        $rates = self::rates($model);

        return (int) round(max(0, $inputTokens) * (float) ($rates['input'] ?? 0) + max(0, $outputTokens) * (float) ($rates['output'] ?? 0));
    }

    /**
     * Rates of a model. Looked up as an array key, not with config() dot
     * notation: OpenRouter IDs contain dots ("qwen/qwen3.8-flash").
     *
     * @return array{input?: int|float, output?: int|float}
     */
    public static function rates(string $model): array
    {
        $rates = (array) config('ai-assist.rates', []);

        if (isset($rates[$model]) && is_array($rates[$model])) {
            return $rates[$model];
        }

        if (self::isFree($model)) {
            return ['input' => 0, 'output' => 0];
        }

        return is_array($rates['fallback'] ?? null) ? $rates['fallback'] : ['input' => 0, 'output' => 0];
    }

    /**
     * An OpenRouter free model variant ("…:free").
     */
    public static function isFree(?string $model): bool
    {
        return is_string($model) && str_ends_with($model, ':free');
    }

    /**
     * Highest cost one request may plausibly report, in USD.
     */
    public const MAX_REPORTED_USD = 100;

    /**
     * Micro-USD from a provider's cost in US dollars (OpenRouter usage.cost);
     * null when it is missing, not a finite number or outside 0..100 USD
     * (the token-based cost applies then).
     */
    public static function fromUsd(mixed $usd): ?int
    {
        if (! is_int($usd) && ! is_float($usd) && ! (is_string($usd) && is_numeric($usd))) {
            return null;
        }

        $usd = (float) $usd;

        if (! is_finite($usd) || $usd < 0 || $usd > self::MAX_REPORTED_USD) {
            return null;
        }

        return (int) round($usd * 1_000_000);
    }

    /**
     * Cost to store for an answer that reported $reported micro-USD: null
     * without a usable report (token pricing applies), the report itself for
     * a ":free" model, else the higher of the report and the token-based
     * cost (an under-reported cost never lowers the budget count).
     */
    public static function billed(string $model, ?int $reported, int $inputTokens, int $outputTokens): ?int
    {
        if ($reported === null || self::isFree($model)) {
            return $reported;
        }

        return max($reported, self::microUsd($model, $inputTokens, $outputTokens));
    }

    /**
     * A token count from a provider answer: an int or numeric string,
     * clamped to 0..4294967295 (the ai_requests column range); else 0.
     */
    public static function tokens(mixed $value): int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d{1,20}$/', $value) === 1)) {
            return 0;
        }

        return (int) max(0, min(4294967295, (float) $value));
    }
}
