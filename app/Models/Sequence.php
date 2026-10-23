<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A document number counter (plan 06), e.g. key "quote-2026". next() is
 * concurrency-safe: the row is created with insertOrIgnore (a racing first
 * use is a no-op on the unique key) and then incremented under a row lock.
 *
 * @property int $id
 * @property string $key
 * @property int $value
 */
#[Fillable(['key', 'value'])]
class Sequence extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
        ];
    }

    /**
     * The next value of the counter (1 for a new key).
     */
    public static function next(string $key): int
    {
        return DB::transaction(function () use ($key): int {
            static::query()->insertOrIgnore(['key' => $key, 'value' => 0, 'created_at' => now(), 'updated_at' => now()]);

            $row = static::query()->where('key', $key)->lockForUpdate()->firstOrFail();

            $row->value++;
            $row->save();

            return $row->value;
        });
    }
}
