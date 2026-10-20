<?php

namespace App\Enums;

/**
 * Kind of a product document (datasheet, manual, ...).
 */
enum DocumentType: string
{
    case Datasheet = 'datasheet';
    case Manual = 'manual';
    case Driver = 'driver';
    case Firmware = 'firmware';
    case Certificate = 'certificate';

    public function label(): string
    {
        return __("enums.document_type.{$this->value}");
    }
}
