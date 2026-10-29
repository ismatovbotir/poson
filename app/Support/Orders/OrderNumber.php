<?php

namespace App\Support\Orders;

use App\Models\Lead;
use App\Models\Sequence;

/**
 * Order numbers from the sequences table (never max(id) + 1): "O-2026-0001"
 * for real orders (sequence order-YYYY), "T-2026-0001" for TEST orders
 * (order-test-YYYY), by the Tashkent year.
 */
final class OrderNumber
{
    public static function next(bool $isTest = false): string
    {
        $year = now(Lead::TIMEZONE)->year;
        $value = Sequence::next(($isTest ? 'order-test-' : 'order-').$year);

        return sprintf('%s-%d-%04d', $isTest ? 'T' : 'O', $year, $value);
    }
}
