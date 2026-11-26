<?php

namespace App\Models;

use App\Enums\GroupStatus;
use Database\Factories\TelegramGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Telegram group or supergroup the bot is in (plan 44 §2). An untracked row
 * is a stub: title, type, bot state and counters only (OG-2). Status,
 * assignee, customer and tracking audit are set by actions, never mass
 * assigned. Never in MCP.
 *
 * @property int $id
 * @property int $telegram_chat_id
 * @property string $type
 * @property string|null $title
 * @property string|null $username
 * @property GroupStatus $status
 * @property string|null $bot_status
 * @property bool $bot_is_admin
 * @property int|null $assignee_id
 * @property int|null $customer_id
 * @property int|null $tracked_by_id
 * @property Carbon|null $tracked_at
 * @property int $seen_count
 * @property Carbon|null $last_message_at
 * @property Carbon|null $analysis_due_at
 * @property int $analyses_today
 * @property Carbon|null $analyses_day
 * @property int|null $last_analysed_message_id
 */
#[Fillable(['type', 'title', 'username', 'bot_status', 'bot_is_admin', 'seen_count', 'last_message_at', 'analysis_due_at'])]
class TelegramGroup extends Model
{
    /** @use HasFactory<TelegramGroupFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telegram_chat_id' => 'integer',
            'status' => GroupStatus::class,
            'bot_is_admin' => 'boolean',
            'tracked_at' => 'datetime',
            'seen_count' => 'integer',
            'last_message_at' => 'datetime',
            'analysis_due_at' => 'datetime',
            'analyses_today' => 'integer',
            'analyses_day' => 'date',
            'last_analysed_message_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function trackedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tracked_by_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<TelegramGroupMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(TelegramGroupMember::class);
    }

    /**
     * @return HasMany<TelegramGroupMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TelegramGroupMessage::class);
    }

    /**
     * @return HasMany<TelegramGroupAnalysis, $this>
     */
    public function analyses(): HasMany
    {
        return $this->hasMany(TelegramGroupAnalysis::class);
    }

    /**
     * @return HasMany<TelegramGroupThread, $this>
     */
    public function threads(): HasMany
    {
        return $this->hasMany(TelegramGroupThread::class);
    }

    public function isTracked(): bool
    {
        return $this->status === GroupStatus::Tracked;
    }

    /**
     * Whether the user may open this group (matches scopeVisibleTo, OG-7): Admin (office.mark or
     * office.chats-all) every group, untracked and ignored ones too; anyone else with office.chats
     * only a tracked group assigned to them.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($user->can('office.mark') || $user->can('office.chats-all')) {
            return true;
        }

        return $user->can('office.chats') && $this->isTracked() && $this->assignee_id !== null && $this->assignee_id === $user->id;
    }

    /**
     * The groups the user may see (same rule as isVisibleTo()).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('office.mark') || $user->can('office.chats-all')) {
            return;
        }

        if (! $user->can('office.chats')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('telegram_groups.status', GroupStatus::Tracked->value)->where('telegram_groups.assignee_id', $user->id);
    }
}
