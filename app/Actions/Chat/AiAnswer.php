<?php

namespace App\Actions\Chat;

use App\Enums\ChatAiStatus;
use App\Services\Ai\AiResult;

/**
 * The outcome of one AI call after the output filter (plan 30): the status,
 * the filtered text (only for answered / truncated), the raw usage (null
 * when no call returned), a short machine reason (blocked reason or error
 * class, never text), the prompt hash and the ids of the product
 * candidates the model was given.
 */
final readonly class AiAnswer
{
    public function __construct(
        public ChatAiStatus $status,
        public string $text,
        public ?AiResult $result,
        public ?string $reason,
        public string $promptHash,
        /** @var list<int> */
        public array $productIds = [],
        /** @var list<int> The [Sn] numbers of <site> entries the answer cited (K-5, memory only) */
        public array $cited = [],
    ) {}
}
