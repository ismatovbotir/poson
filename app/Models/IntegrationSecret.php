<?php

namespace App\Models;

use App\Support\Secrets;
use Database\Factories\IntegrationSecretFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One integration secret (plan 04 S2), keyed by its IntegrationRegistry key
 * (e.g. "telegram.bot_token"). "value" is encrypted with APP_KEY and hidden
 * from serialization; "hint" holds the last 4 characters (values of 20+
 * characters only) for the masked display.
 *
 * Read secrets only through App\Support\Secrets; write them only through
 * the SetSecret / ClearSecret / GenerateWebhookSecret actions (they audit).
 * Saving or deleting a model flushes the Secrets cache after commit.
 *
 * @property int $id
 * @property string $key
 * @property string $value
 * @property string|null $hint
 * @property int|null $updated_by
 */
#[Fillable(['key', 'value', 'hint', 'updated_by'])]
#[Hidden(['value'])]
class IntegrationSecret extends Model
{
    /** @use HasFactory<IntegrationSecretFactory> */
    use HasFactory;

    /**
     * Shortest value that gets a "last 4 characters" hint; shorter ones are
     * shown as "••••" only, so the hint never gives away most of the value.
     */
    public const HINT_MIN_LENGTH = 20;

    protected static function booted(): void
    {
        static::saved(fn () => DB::afterCommit(fn () => Secrets::flush()));
        static::deleted(fn () => DB::afterCommit(fn () => Secrets::flush()));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
        ];
    }

    /**
     * Last 4 characters of a value of HINT_MIN_LENGTH+ characters, else null.
     */
    public static function hintFor(#[\SensitiveParameter] string $value): ?string
    {
        return mb_strlen($value) >= self::HINT_MIN_LENGTH ? mb_substr($value, -4) : null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
