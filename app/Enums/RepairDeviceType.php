<?php

namespace App\Enums;

/**
 * What kind of device was brought in. Named apart from the analytics
 * DeviceType (visitor devices).
 */
enum RepairDeviceType: string
{
    case Scanner = 'scanner';
    case LabelPrinter = 'label_printer';
    case ReceiptPrinter = 'receipt_printer';
    case MobileComputer = 'mobile_computer';
    case Rfid = 'rfid';
    case Pos = 'pos';
    case Other = 'other';

    public function label(): string
    {
        return __("enums.repair_device_type.{$this->value}");
    }
}
