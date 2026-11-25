<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use App\Enums\BusinessChatKind;
use Database\Factories\BusinessChatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * One private chat of a Telegram Business connection (plan 41 §2, §4).
 *
 * Visibility (VO-9, §7), the same rule in isVisibleTo() and scopeVisibleTo():
 * only client chats are ever listed; office.chats-all (Admin) sees every
 * client chat, office.chats only the client chats assigned to the user.
 * Unassigned, unmarked, employee and ignored chats are Admin only (the
 * Unmarked screen, office.mark). Never exposed to MCP (VO-17).
 *
 * Mass assignment covers the counters and profile stub fields only; kind,
 * assignment, CRM links and the mark are set explicitly by actions.
 *
 * @property int $id
 * @property int $business_connection_id
 * @property int $telegram_chat_id
 * @property BusinessChatKind $kind
 * @property string|null $first_name
 * @property string|null $username
 * @property string|null $phone
 * @property int|null $assignee_id
 * @property int|null $staff_user_id
 * @property int|null $telegram_user_id
 * @property int|null $customer_id
 * @property int|null $lead_id
 * @property int $unread_count
 * @property Carbon|null $first_inbound_at
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $last_owner_at
 * @property Carbon|null $last_message_at
 * @property Carbon|null $marked_at
 * @property int|null $marked_by_id
 * @property Carbon|null $analysis_due_at
 * @property int|null $last_analysed_message_id
 * @property int $analyses_today
 * @property Carbon|null $analyses_day
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['first_name', 'username', 'unread_count', 'first_inbound_at', 'last_inbound_at', 'last_owner_at', 'last_message_at', 'analysis_due_at'])]
class BusinessChat extends Model
{
    /** @use HasFactory<BusinessChatFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telegram_chat_id' => 'integer',
            'kind' => BusinessChatKind::class,
            'unread_count' => 'integer',
            'first_inbound_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_owner_at' => 'datetime',
            'last_message_at' => 'datetime',
            'marked_at' => 'datetime',
            'analysis_due_at' => 'datetime',
            'last_analysed_message_id' => 'integer',
            'analyses_today' => 'integer',
            'analyses_day' => 'date',
        ];
    }

    /**
     * Activities (marks, assignments) go with the chat: a morph relation has no foreign key.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $chat): void {
            $chat->activities()->delete();
        });
    }

    /**
     * @return BelongsTo<BusinessConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(BusinessConnection::class, 'business_connection_id');
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
    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by_id');
    }

    /**
     * @return BelongsTo<TelegramUser, $this>
     */
    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return HasMany<BusinessMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(BusinessMessage::class);
    }

    /**
     * @return HasMany<BusinessChatAnalysis, $this>
     */
    public function analyses(): HasMany
    {
        return $this->hasMany(BusinessChatAnalysis::class);
    }

    /**
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function isClient(): bool
    {
        return $this->kind === BusinessChatKind::Client;
    }

    /**
     * Whether the user may open this chat (matches scopeVisibleTo).
     */
    public function isVisibleTo(User $user): bool
    {
        if (! $user->can('office.chats') || ! $this->isClient()) {
            return false;
        }

        if ($user->can('office.chats-all')) {
            return true;
        }

        return $this->assignee_id !== null && $this->assignee_id === $user->id;
    }

    /**
     * Client chats the user may see: office.chats-all every one, office.chats those assigned to the user.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->can('office.chats')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('business_chats.kind', BusinessChatKind::Client->value);

        if (! $user->can('office.chats-all')) {
            $query->where('business_chats.assignee_id', $user->id);
        }
    }

    /**
     * An analysis of this chat is queued or running.
     */
    public function hasPendingAnalysis(): bool
    {
        return $this->analyses()->whereIn('status', AnalysisStatus::pendingValues())->exists();
    }

    /**
     * Display name of the counterpart (first name, else @username, else "#id").
     */
    public function displayName(): string
    {
        if (filled($this->first_name)) {
            return (string) $this->first_name;
        }

        return filled($this->username) ? '@'.$this->username : '#'.$this->id;
    }
}
