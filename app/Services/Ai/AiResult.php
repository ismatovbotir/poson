<?php

namespace App\Services\Ai;

/**
 * What the chat model answered (plan 30 §5): the plain text of its text
 * blocks, the stop reason as the API names it (end_turn, max_tokens,
 * refusal ...), token usage and the time the call took.
 */
final readonly class AiResult
{
    public const END_TURN = 'end_turn';

    public const MAX_TOKENS = 'max_tokens';

    public const REFUSAL = 'refusal';

    public function __construct(
        public string $text,
        public ?string $stopReason,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
        public string $model = '',
        public int $latencyMs = 0,
    ) {}

    public function isRefusal(): bool
    {
        return $this->stopReason === self::REFUSAL;
    }

    public function isTruncated(): bool
    {
        return $this->stopReason === self::MAX_TOKENS;
    }

    /**
     * Tokens counted against the daily cap: input, output and cache writes
     * in full, cache reads at a tenth (AI-18).
     */
    public function billedTokens(): int
    {
        return $this->inputTokens + $this->outputTokens + $this->cacheWriteTokens + (int) ceil($this->cacheReadTokens / 10);
    }
}
