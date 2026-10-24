<?php

namespace App\Services\Ai;

use App\Enums\AiErrorCode;
use RuntimeException;

/**
 * A failed or refused AI request with a safe code. The message is the code
 * only: provider errors are never copied into it (they may echo keys,
 * headers or prompts). When the provider answered but the answer was
 * unusable, the usage it billed travels along (tokens are stored and
 * costed on the failed row; $billedMicroUsd is the provider's exact cost
 * when it reported one).
 */
final class AiException extends RuntimeException
{
    public function __construct(
        public readonly AiErrorCode $errorCode,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly ?string $model = null,
        public readonly ?int $billedMicroUsd = null,
    ) {
        parent::__construct('AI request failed: '.$errorCode->value);
    }

    public static function for(AiErrorCode $code, int $inputTokens = 0, int $outputTokens = 0, ?string $model = null, ?int $billedMicroUsd = null): self
    {
        return new self($code, $inputTokens, $outputTokens, $model, $billedMicroUsd);
    }

    /**
     * Cost of the billed usage in micro-USD (0 without usage).
     */
    public function costMicroUsd(string $fallbackModel): int
    {
        if ($this->billedMicroUsd !== null) {
            return max(0, $this->billedMicroUsd);
        }

        return AiCost::microUsd($this->model ?? $fallbackModel, $this->inputTokens, $this->outputTokens);
    }
}
