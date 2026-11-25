<?php

namespace App\Models;

use Database\Factories\BusinessConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Telegram Business connection of the customer bot (plan 41 §2). Written
 * only by UpsertBusinessConnection (webhook and FetchBusinessConnection).
 * is_allowed: the owner is on the office.owner_telegram_ids allow-list
 * (VO-1, recomputed when the list changes). Never exposed to MCP (VO-17).
 *
 * @property int $id
 * @property string $connection_id
 * @property int $owner_telegram_id
 * @property int|null $user_chat_id
 * @property bool $is_enabled
 * @property bool $is_allowed
 * @property bool $can_reply
 * @property array<string, bool>|null $rights
 * @property Carbon|null $connected_at
 * @property Carbon|null $disabled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['connection_id', 'owner_telegram_id', 'user_chat_id', 'is_enabled', 'is_allowed', 'can_reply', 'rights', 'connected_at', 'disabled_at'])]
class BusinessConnection extends Model
{
    /** @use HasFactory<BusinessConnectionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_telegram_id' => 'integer',
            'user_chat_id' => 'integer',
            'is_enabled' => 'boolean',
            'is_allowed' => 'boolean',
            'can_reply' => 'boolean',
            'rights' => 'array',
            'connected_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BusinessChat, $this>
     */
    public function chats(): HasMany
    {
        return $this->hasMany(BusinessChat::class);
    }

    /**
     * Messages of this connection are processed (allowed owner, enabled).
     */
    public function isActive(): bool
    {
        return $this->is_allowed && $this->is_enabled;
    }
}
