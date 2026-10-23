<?php

namespace App\Services\Ai;

/**
 * Output of TextAssistant: one text per locale plus usage.
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
    ) {}

    /**
     * Cost of the request in micro-USD (config ai-assist.rates).
     */
    public function costMicroUsd(): int
    {
        return AiCost::microUsd($this->model, $this->inputTokens, $this->outputTokens);
    }
}
