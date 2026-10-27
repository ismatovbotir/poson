<?php

namespace App\Models;

use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Bearer token of the machine API (plan 09; 1C pushes prices with it).
 *
 * The plain token is PREFIX + PLAIN_LENGTH random characters, shown once by
 * App\Actions\Api\CreateApiToken; only its sha256 (token_hash) and the first
 * DISPLAY_PREFIX_LENGTH characters (token_prefix, masked display) are kept.
 * Checked by App\Http\Middleware\AuthenticateApiToken. Revoking keeps the
 * row (the sync log refers to it).
 *
 * @property int $id
 * @property string $name
 * @property string $token_hash
 * @property string $token_prefix
 * @property list<string> $abilities
 * @property int|null $created_by
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['name', 'token_hash', 'token_prefix', 'abilities', 'created_by', 'expires_at'])]
#[Hidden(['token_hash'])]
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    public const PREFIX = 'pos_';

    public const PLAIN_LENGTH = 48;

    public const DISPLAY_PREFIX_LENGTH = 12;

    /**
     * Abilities a token can hold.
     */
    public const ABILITY_PRICES_WRITE = 'prices.write';

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
            'abilities' => 'array',
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
     * Whether a string has the shape of a token (checked before any lookup).
     */
    public static function looksValid(#[\SensitiveParameter] string $plain): bool
    {
        return (bool) preg_match('/^'.preg_quote(self::PREFIX, '/').'[A-Za-z0-9]{'.self::PLAIN_LENGTH.'}$/', $plain);
    }

    public static function findByPlainText(#[\SensitiveParameter] string $plain): ?self
    {
        return static::query()->where('token_hash', static::hash($plain))->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
