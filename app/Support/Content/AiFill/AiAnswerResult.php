<?php

namespace App\Support\Content\AiFill;

/**
 * Outcome of parsing a pasted AI answer (plan 29). Messages are lang keys
 * with params and never repeat any pasted text.
 */
final readonly class AiAnswerResult
{
    /**
     * @param  array<string, array<string, string>>  $values  field key → locale → cleaned text (only valid, non-empty values)
     * @param  list<array{key: ?string, locale: ?string, message: string, params: array<string, int|string>}>  $errors  "key" null = the answer as a whole
     * @param  list<array{key: ?string, locale: ?string, message: string, params: array<string, int|string>}>  $warnings
     */
    public function __construct(
        public array $values = [],
        public array $errors = [],
        public array $warnings = [],
    ) {}
}
