<?php

namespace App\Support\Admin;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * The one temporary password generator (P24-8): the console reset now,
 * provision, reset and reactivate later. Owner decision: valid for 24 hours,
 * passed in person or by phone, changed at first use. No symbols, because it
 * has to be read out over the phone.
 */
class TempPassword
{
    public const LENGTH = 16;

    public const VALID_HOURS = 24;

    public function generate(): string
    {
        return Str::password(self::LENGTH, symbols: false);
    }

    public function expiresAt(): CarbonInterface
    {
        return now()->addHours(self::VALID_HOURS);
    }
}
