<?php

namespace App\Models;

use App\Enums\ApiTokenType;
use App\Enums\McpRevokeReason;
use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Bearer token of the machine API (plan 09; 1C pushes prices with it).
 *
 * The plain token is PREFIX + PLAIN_LENGTH random characters, shown once by
 * App\Actions\Api\CreateApiToken; only its sha256 (token_hash) and the first
 * DISPLAY_PREFIX_LENGTH characters (token_prefix, masked display) are kept.
 * Checked by App\Http\Middleware\AuthenticateApiToken. Revoking keeps the
 * row (the sync log refers to it).
 *
 * Plan 13 (MCP, C3–C5): the same table holds the CEO's MCP tokens
 * (type mcp, prefix "posm_", user_id = the holder, abilities ["mcp"], no
 * expiry; name is the device label). Every lookup is by type: a token of
 * one type never authenticates the other's routes.
 *
 * @property int $id
 * @property ApiTokenType $type
 * @property int|null $user_id
 * @property string $name
 * @property string $token_hash
 * @property string $token_prefix
 * @property list<string> $abilities
 * @property int|null $created_by
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property string|null $last_used_ua
 * @property list<string>|null $allowed_ips
 * @property int|null $idle_revoke_days
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property McpRevokeReason|null $revoked_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['type', 'user_id', 'name', 'token_hash', 'token_prefix', 'abilities', 'allowed_ips', 'idle_revoke_days', 'created_by', 'expires_at'])]
#[Hidden(['token_hash'])]
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    public const PREFIX = 'pos_';

    public const PLAIN_LENGTH = 48;

    public const DISPLAY_PREFIX_LENGTH = 12;

    /**
     * MCP idle auto-revoke (C12, decision 2026-11-01): default and allowed range in days.
     */
    public const DEFAULT_IDLE_REVOKE_DAYS = 45;

    public const MIN_IDLE_REVOKE_DAYS = 14;

    public const MAX_IDLE_REVOKE_DAYS = 180;

    /**
     * An MCP token never used within this many days of generation is revoked (C12).
     */
    public const NEVER_USED_REVOKE_DAYS = 7;

    /**
     * MCP tokens older than this get a "Rotate" badge and a monthly reminder (C13).
     */
    public const ROTATE_AFTER_DAYS = 180;

    /**
     * Abilities a token can hold.
     */
    public const ABILITY_PRICES_WRITE = 'prices.write';

    /**
     * The only ability of an MCP token (plan 13).
     */
    public const ABILITY_MCP = 'mcp';

    /**
     * @var list<string>
     */
    public const ABILITIES = [self::ABILITY_PRICES_WRITE];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ApiTokenType::class,
            'abilities' => 'array',
            'allowed_ips' => 'array',
            'idle_revoke_days' => 'integer',
            'revoked_reason' => McpRevokeReason::class,
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * sha256 of a plain token (the stored form).
     */
    public static function hash(#[\SensitiveParameter] string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Whether a string has the shape of a token of the type (checked before any lookup).
     */
    public static function looksValid(#[\SensitiveParameter] string $plain, ApiTokenType $type = ApiTokenType::PriceApi): bool
    {
        return (bool) preg_match('/^'.preg_quote($type->prefix(), '/').'[A-Za-z0-9]{'.self::PLAIN_LENGTH.'}$/', $plain);
    }

    /**
     * The row of a plain token, only among tokens of the given type (C5).
     */
    public static function findByPlainText(#[\SensitiveParameter] string $plain, ApiTokenType $type = ApiTokenType::PriceApi): ?self
    {
        return static::query()->where('type', $type)->where('token_hash', static::hash($plain))->first();
    }

    /**
     * A new random plain token of the type (shown once, never stored).
     */
    public static function generatePlainText(ApiTokenType $type): string
    {
        return $type->prefix().Str::random(self::PLAIN_LENGTH);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOfType(Builder $query, ApiTokenType $type): void
    {
        $query->where('type', $type);
    }

    /**
     * Not revoked (MCP tokens never expire).
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The MCP holder the token acts as (MCP tokens only).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<McpCall, $this>
     */
    public function mcpCalls(): HasMany
    {
        return $this->hasMany(McpCall::class);
    }

    /**
     * @return HasMany<PriceSyncLog, $this>
     */
    public function priceSyncLogs(): HasMany
    {
        return $this->hasMany(PriceSyncLog::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function hasAbility(string $ability): bool
    {
        return in_array($ability, (array) $this->abilities, true);
    }

    /**
     * Days without use after which an MCP token is revoked (C12).
     */
    public function idleRevokeDays(): int
    {
        return $this->idle_revoke_days ?? self::DEFAULT_IDLE_REVOKE_DAYS;
    }

    /**
     * When the idle auto-revoke will hit if the token stays unused (C12):
     * 7 days after generation while never used, otherwise last use + idle days.
     */
    public function idleRevokeAt(): Carbon
    {
        return $this->last_used_at === null
            ? $this->created_at->copy()->addDays(self::NEVER_USED_REVOKE_DAYS)
            : $this->last_used_at->copy()->addDays($this->idleRevokeDays());
    }

    /**
     * Older than ROTATE_AFTER_DAYS: the screen shows "Rotate" (C13).
     */
    public function needsRotation(): bool
    {
        return $this->created_at !== null && $this->created_at->lte(now()->subDays(self::ROTATE_AFTER_DAYS));
    }

    /**
     * "pos_AbCd1234••••" for lists (never the full token).
     */
    public function maskedToken(): string
    {
        return $this->token_prefix.'••••';
    }

    /**
     * active / expired / revoked (lang key api.tokens.status.*, badge color).
     */
    public function state(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->isExpired() => 'expired',
            default => 'active',
        };
    }
}
