<?php

namespace App\Enums;

/**
 * Staff roles (spatie/laravel-permission role names). Only users with one of
 * these roles can open the admin panel.
 */
enum Role: string
{
    case Admin = 'admin';
    case ContentManager = 'content_manager';
    case SalesManager = 'sales_manager';
    case ServiceEngineer = 'service_engineer';

    public function label(): string
    {
        return __("enums.role.{$this->value}");
    }

    /**
     * Badge color for <x-admin.badge> (admin kit role colors).
     */
    public function color(): string
    {
        return match ($this) {
            self::Admin => 'ink',
            self::ContentManager => 'brand',
            self::SalesManager => 'sky',
            self::ServiceEngineer => 'violet',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
