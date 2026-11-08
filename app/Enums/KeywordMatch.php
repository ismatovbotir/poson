<?php

namespace App\Enums;

/**
 * How a chat rule keyword is compared with the normalized input (plan 28 §2):
 * word / phrase = whole words in sequence, prefix = a word that starts with
 * it (covers stems), exact = the whole message.
 */
enum KeywordMatch: string
{
    case Word = 'word';
    case Phrase = 'phrase';
    case Prefix = 'prefix';
    case Exact = 'exact';

    public function label(): string
    {
        return __("enums.keyword_match.{$this->value}");
    }
}
