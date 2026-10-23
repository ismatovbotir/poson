<?php

namespace App\Services\Ai;

/**
 * Request cost from token usage. Rates are USD per million tokens, which is
 * the same number as micro-USD per token (config ai-assist.rates).
 */
final class AiCost
{
    public static function microUsd(string $model, int $inputTokens, int $outputTokens): int
    {
        $rates = config("ai-assist.rates.{$model}") ?? config('ai-assist.rates.fallback', ['input' => 0, 'output' => 0]);

        return (int) round(max(0, $inputTokens) * (float) ($rates['input'] ?? 0) + max(0, $outputTokens) * (float) ($rates['output'] ?? 0));
    }
}
