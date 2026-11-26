<?php

namespace App\Models;

use App\Enums\GroupMemberRole;
use Database\Factories\TelegramGroupMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A participant of a tracked group (plan 44 §2). The role and the staff,
 * customer and telegram-user links are set by staff actions only (OG-7),
 * so they are not mass assignable.
 *
 * @property int $id
 * @property int $telegram_group_id
 * @property int $telegram_id
 * @property GroupMemberRole $role
 * @property bool $is_bot
 * @property string|null $first_name
 * @property string|null $username
 * @property string|null $phone
 * @property string|null $member_status
 * @property int|null $telegram_user_id
 * @property int|null $staff_user_id
 * @property int|null $customer_id
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property int $message_count
 * @property int|null $role_set_by_id
 * @property Carbon|null $role_set_at
 */
#[Fillable(['first_name', 'username', 'member_status', 'is_bot', 'first_seen_at', 'last_seen_at', 'message_count'])]
class TelegramGroupMember extends Model
{
    /** @use HasFactory<TelegramGroupMemberFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telegram_id' => 'integer',
            'role' => GroupMemberRole::class,
            'is_bot' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'message_count' => 'integer',
            'role_set_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TelegramGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class, 'telegram_group_id');
    }

    /**
     * @return BelongsTo<TelegramUser, $this>
     */
    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function roleSetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'role_set_by_id');
    }

    /**
     * @return HasMany<TelegramGroupMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TelegramGroupMessage::class, 'member_id');
    }
}
