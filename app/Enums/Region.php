<?php

namespace App\Enums;

/**
 * Regions of Uzbekistan for courier delivery (plan 11 v2 §1.2): Tashkent
 * city, Karakalpakstan and the 12 regions. Kokand (Fergana region) has its
 * own delivery type.
 */
enum Region: string
{
    case TashkentCity = 'tashkent_city';
    case Tashkent = 'tashkent';
    case Andijan = 'andijan';
    case Bukhara = 'bukhara';
    case Fergana = 'fergana';
    case Jizzakh = 'jizzakh';
    case Kashkadarya = 'kashkadarya';
    case Khorezm = 'khorezm';
    case Namangan = 'namangan';
    case Navoi = 'navoi';
    case Samarkand = 'samarkand';
    case Surkhandarya = 'surkhandarya';
    case Syrdarya = 'syrdarya';
    case Karakalpakstan = 'karakalpakstan';

    public function label(): string
    {
        return __("enums.region.{$this->value}");
    }
}
