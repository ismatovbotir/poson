<?php

namespace App\Models;

use Database\Factories\TelegramUpdateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Webhook idempotency ledger (plan 14 v2, D5): the Telegram update_id is
 * inserted first, a duplicate insert means the update was already handled.
 *
 * @property int $update_id
 * @property Carbon|null $created_at
 */
#[Fillable(['update_id'])]
class TelegramUpdate extends Model
{
    /** @use HasFactory<TelegramUpdateFactory> */
    use HasFactory, MassPrunable;

    public const KEEP_DAYS = 3;

    protected $primaryKey = 'update_id';

    public $incrementing = false;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'update_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Rows older than KEEP_DAYS days.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
