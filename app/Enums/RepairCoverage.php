<?php

namespace App\Enums;

/**
 * Who pays for a repair. Warranty is a manual flag with a free-text
 * reference (owner decision: no link to a sale); a warranty repair needs
 * neither an estimate nor a final amount.
 */
enum RepairCoverage: string
{
    case Paid = 'paid';
    case Warranty = 'warranty';

    public function label(): string
    {
        return __("enums.repair_coverage.{$this->value}");
    }

    public function color(): string
    {
        return match ($this) {
            self::Paid => 'outline',
            self::Warranty => 'violet',
        };
    }
}
