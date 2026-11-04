<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Enums\TelegramUserState;
use App\Support\Search;
use Database\Factories\TelegramUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A private chat with the Telegram bot (plan 14 v2): the registration data,
 * the optional CRM link and the inbox state. Never exposed to MCP (TG-36).
 *
 * Mass assignment covers the bot-profile, registration and inbox fields only.
 * The CRM link (customer_id, contact_id, link_verified_*), assignment and the
 * ban fields are assigned explicitly by actions.
 *
 * @property int $id
 * @property int $telegram_id
 * @property string|null $username
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $locale
 * @property TelegramUserState $state
 * @property string|null $phone
 * @property string|null $name
 * @property string|null $declared_company
 * @property string|null $declared_inn
 * @property Carbon|null $consent_at
 * @property Carbon|null $registered_at
 * @property int|null $customer_id
 * @property int|null $contact_id
 * @property Carbon|null $link_verified_at
 * @property int|null $link_verified_by_id
 * @property bool $account_changed
 * @property ConversationStatus $conversation_status
 * @property int|null $manager_id
 * @property int $unread_count
 * @property Carbon|null $last_message_at
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $last_alert_at
 * @property Carbon|null $closed_at
 * @property int|null $forward_header_message_id
 * @property Carbon|null $forward_header_at
 * @property int|null $forward_header_chat_id
 * @property Carbon|null $blocked_at
 * @property Carbon|null $banned_at
 * @property int|null $banned_by_id
 */
#[Fillable([
    'telegram_id', 'username', 'first_name', 'last_name', 'locale', 'state',
    'phone', 'name', 'declared_company', 'declared_inn', 'consent_at', 'registered_at',
    'conversation_status', 'unread_count', 'last_message_at', 'last_inbound_at', 'last_alert_at', 'closed_at',
    'forward_header_message_id', 'forward_header_at', 'forward_header_chat_id', 'blocked_at',
])]
class TelegramUser extends Model
{
    /** @use HasFactory<TelegramUserFactory> */
    use HasFactory, Prunable;

    /**
     * Unregistered visitors are deleted after this many days.
     */
    public const KEEP_UNREGISTERED_DAYS = 30;

    /**
     * Threads without a lead-linked message are deleted after this many months of silence.
     */
    public const KEEP_THREAD_MONTHS = 12;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telegram_id' => 'integer',
            'state' => TelegramUserState::class,
            'conversation_status' => ConversationStatus::class,
            'account_changed' => 'boolean',
            'unread_count' => 'integer',
            'consent_at' => 'datetime',
            'registered_at' => 'datetime',
            'link_verified_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_alert_at' => 'datetime',
            'closed_at' => 'datetime',
            'forward_header_at' => 'datetime',
            'forward_header_chat_id' => 'integer',
            'blocked_at' => 'datetime',
            'banned_at' => 'datetime',
        ];
    }

    /**
     * One hook for prune and every other delete (T21-20): the thread's
     * activities (a morph relation has no foreign key) go with it. Messages
     * cascade in the database.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $user): void {
            $user->activities()->delete();
        });
    }

    /**
     * Rows to delete: unregistered visitors 30 days after their last message
     * (or creation), and threads with no lead-linked message after 12 months
     * of silence. Messages cascade. Banned rows are never pruned (the ban
     * survives; so does the whole thread, its messages included).
     * Prune makes no Telegram call (TG-27 amended: copies in the staff chat cannot be removed
     * by retention). The activities go in the `deleting` hook, which prune and delete share.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $withoutLeadMessage = fn (Builder $query) => $query->whereDoesntHave('messages', fn (Builder $messages) => $messages->whereNotNull('lead_id'));

        return static::query()
            ->whereNull('banned_at')
            ->where(function (Builder $query) use ($withoutLeadMessage): void {
                $query->where(fn (Builder $unregistered) => $unregistered
                    ->whereNull('registered_at')
                    ->whereRaw('coalesce(last_message_at, created_at) < ?', [now()->subDays(self::KEEP_UNREGISTERED_DAYS)])
                    ->tap($withoutLeadMessage))
                    ->orWhere(fn (Builder $silent) => $silent
                        ->where(fn (Builder $when) => $when
                            ->where('last_message_at', '<', now()->subMonths(self::KEEP_THREAD_MONTHS))
                            ->orWhere(fn (Builder $never) => $never->whereNull('last_message_at')->where('created_at', '<', now()->subMonths(self::KEEP_THREAD_MONTHS))))
                        ->tap($withoutLeadMessage));
            });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function bannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function linkVerifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'link_verified_by_id');
    }

    /**
     * @return HasMany<TelegramMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class);
    }

    /**
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function isRegistered(): bool
    {
        return $this->registered_at !== null;
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    /**
     * Whether the user may see this conversation (matches scopeVisibleTo).
     * Without telegram.inbox-all only registered conversations (T21-11).
     */
    public function isVisibleTo(User $user): bool
    {
        if (! $user->can('telegram.inbox')) {
            return false;
        }

        if ($user->can('telegram.inbox-all')) {
            return true;
        }

        return $this->isRegistered() && ($this->manager_id === null || $this->manager_id === $user->id);
    }

    /**
     * Conversations the user may see: telegram.inbox-all every one, telegram.inbox
     * those registered and assigned to the user or unassigned, otherwise none.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->can('telegram.inbox')) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($user->can('telegram.inbox-all')) {
            return;
        }

        $query->whereNotNull('telegram_users.registered_at')
            ->where(fn (Builder $inner) => $inner->whereNull('telegram_users.manager_id')->orWhere('telegram_users.manager_id', $user->id));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeRegistered(Builder $query): void
    {
        $query->whereNotNull('telegram_users.registered_at');
    }

    /**
     * Admin search: name, username, Telegram first / last name, company or phone digits (at least 3).
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $pattern = Search::likePattern($term);
        $digits = (string) preg_replace('/\D+/', '', $term);

        $query->where(function (Builder $inner) use ($pattern, $digits): void {
            $inner->whereRaw("lower(telegram_users.name) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(telegram_users.username) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(telegram_users.first_name) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(telegram_users.last_name) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(telegram_users.declared_company) like ? escape '!'", [$pattern]);

            if (strlen($digits) >= 3) {
                $inner->orWhere('telegram_users.phone', 'like', '%'.$digits.'%');
            }
        });
    }
}
