<?php

namespace App\Support\Chat;

/**
 * Result of scoring a message against the rules: the winning rule and the
 * keywords that scored (for the C2 test console).
 */
final readonly class ChatMatch
{
    /**
     * @param  list<array{keyword: string, type: string, weight: int}>  $keywords  Keywords of the winning rule that matched
     */
    public function __construct(
        public int $ruleId,
        public int $score,
        public array $keywords = [],
    ) {}
}
