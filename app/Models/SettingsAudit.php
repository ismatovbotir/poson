<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use Database\Factories\SettingsAuditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One settings change (plan 04 S6): who, when, area, key, action and IP.
 * Never stores the old or new value, so it is safe for secrets too.
 * Append-only: there is no updated_at. The area is read with tryFrom():
 * a legacy area value no longer in SettingsArea (e.g. "ai_prompts") reads
 * as null instead of throwing.
 *
 * @property int $id
 * @property int|null $user_id
 * @property SettingsArea|null $area
 * @property string $key
 * @property AuditAction $action
 * @property string|null $ip
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'area', 'key', 'action', 'ip'])]
class SettingsAudit extends Model
{
    /** @use HasFactory<SettingsAuditFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * Tolerant enum cast: unknown (legacy) values read as null; writes
     * accept a SettingsArea only.
     *
     * @return Attribute<SettingsArea|null, SettingsArea>
     */
    protected function area(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?SettingsArea => $value === null ? null : SettingsArea::tryFrom($value),
            set: fn (SettingsArea $value): string => $value->value,
        );
    }

    /**
     * Record a change. The value itself is never passed in.
     */
    public static function record(SettingsArea $area, string $key, AuditAction $action, ?User $user, ?string $ip): self
    {
        return static::query()->create([
            'user_id' => $user?->id,
            'area' => $area,
            'key' => $key,
            'action' => $action,
            'ip' => $ip !== null ? mb_substr($ip, 0, 45) : null,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
