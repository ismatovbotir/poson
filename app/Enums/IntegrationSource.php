<?php

namespace App\Enums;

/**
 * Where the effective value of an integration field comes from
 * (App\Support\Secrets::source()): a non-empty .env value (locks the field),
 * the value saved in admin, the registry default, or nothing at all.
 */
enum IntegrationSource: string
{
    case Env = 'env';
    case Db = 'db';
    case Default = 'default';
    case None = 'none';

    public function label(): string
    {
        return __("enums.integration_source.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::Env => 'violet',
            self::Db => 'brand',
            self::Default => 'slate',
            self::None => 'muted',
        };
    }
}
