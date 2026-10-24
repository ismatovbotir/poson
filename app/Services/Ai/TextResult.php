<?php

namespace App\Services\Ai;

/**
 * Output of TextAssistant: one text per locale plus usage. $costMicroUsd is
 * the exact cost reported by the provider (OpenRouter usage.cost), null when
 * the provider doesn't report it (the cost is then computed from tokens).
 */
final readonly class TextResult
{
    /**
     * @param  array<string, string>  $texts  locale => text
     */
    public function __construct(
        public array $texts,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?int $costMicroUsd = null,
    ) {}

    /**
     * Cost of the request in micro-USD: the provider's own figure when it
     * reported one, else from tokens (AiCost).
     */
    public function costMicroUsd(): int
    {
        return $this->costMicroUsd ?? AiCost::microUsd($this->model, $this->inputTokens, $this->outputTokens);
    }
}
