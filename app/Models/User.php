<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\ApiTokenType;
use App\Enums\Role;
use App\Observers\UserObserver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * mcp_access_granted_at / mcp_access_granted_by (plan 13): the single MCP
 * holder. Not fillable: set only by App\Actions\Mcp\GrantMcpAccess and
 * cleared by RevokeMcpAccess.
 */
#[Fillable(['name', 'email', 'password', 'locale'])]
#[Hidden(['password', 'remember_token'])]
#[ObservedBy([UserObserver::class])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'mcp_access_granted_at' => 'datetime',
        ];
    }

    /**
     * Has the Admin role itself (not through Gate::before): MCP eligibility (C7).
     */
    public function isActiveAdmin(): bool
    {
        return (bool) $this->is_active && $this->hasRole(Role::Admin);
    }

    /**
     * Holds MCP access (plan 13).
     */
    public function hasMcpAccess(): bool
    {
        return $this->mcp_access_granted_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function mcpAccessGrantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mcp_access_granted_by');
    }

    /**
     * The user's MCP tokens (api_tokens of type mcp).
     *
     * @return HasMany<ApiToken, $this>
     */
    public function mcpTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class)->where('type', ApiTokenType::Mcp);
    }

    /**
     * Active user with at least one of the staff roles (admin panel access).
     */
    public function isStaff(): bool
    {
        return $this->is_active && $this->hasAnyRole(Role::values());
    }

    /**
     * The user's first staff role (users normally have exactly one).
     */
    public function staffRole(): ?Role
    {
        foreach (Role::cases() as $role) {
            if ($this->hasRole($role)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Initials for the avatar ("Aziz Karimov" → "AK").
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim($this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(
            fn (string $word): string => mb_substr($word, 0, 1),
            array_slice($words, 0, 2),
        ))) ?: '?';
    }
}
