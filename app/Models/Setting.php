<?php

namespace App\Models;

use App\Support\CompanySettings;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Key/value override for the defaults in config/company.php.
 *
 * Saving or deleting a model flushes the CompanySettings cache once the
 * surrounding transaction commits (immediately when there is none).
 * Mass writes such as Setting::query()->update() or ->delete() bypass model
 * events and therefore do NOT flush the cache: call CompanySettings::flush()
 * yourself after them.
 *
 * @property string $key
 * @property mixed $value
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(fn () => DB::afterCommit(fn () => CompanySettings::flush()));
        static::deleted(fn () => DB::afterCommit(fn () => CompanySettings::flush()));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
