<?php

namespace App\Enums;

/**
 * What chat_stats_daily counts. Counts only, never text.
 */
enum ChatMetric: string
{
    case Hit = 'hit';
    case Unmatched = 'unmatched';
    case Handoff = 'handoff';
    case Lead = 'lead';
    case LookupOk = 'lookup_ok';
    case LookupFail = 'lookup_fail';
    case SearchEmpty = 'search_empty';

    public function label(): string
    {
        return __("enums.chat_metric.{$this->value}");
    }
}
