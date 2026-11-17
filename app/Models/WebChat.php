<?php

namespace App\Models;

use App\Enums\ChatChannel;
use App\Enums\ChatMode;
use App\Enums\ConversationStatus;
use App\Support\Chat\ChatSubject;
use App\Support\Chat\WebChatDialog;
use App\Support\Search;
use Database\Factories\WebChatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A website chat (plan 28 C5 §19.2). The visitor is known only by the
 * cookie token's sha256 (token_hash; the token itself is never stored) and
 * a daily-salted IP hash; both are hidden from serialization. name / phone
 * are what the visitor typed: never verified and never matched to a
 * customer (customer_id is set by staff only, WC-9). Never exposed to MCP.
 *
 * Mass assignment covers the visitor-given and inbox counter fields only.
 * Token, consent, origin, mode, dialog, assignment, links and ban are set
 * explicitly by the actions (forceFill).
 *
 * @property int $id
 * @property string $token_hash
 * @property string $locale
 * @property string|null $name
 * @property string|null $phone
 * @property Carbon|null $consent_at
 * @property string|null $consent_version
 * @property ChatMode $mode
 * @property array<string, mixed>|null $dialog
 * @property ConversationStatus $conversation_status
 * @property int|null $manager_id
 * @property int $unread_count
 * @property Carbon|null $last_message_at
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $last_staff_reply_at
 * @property Carbon|null $last_alert_at
 * @property Carbon|null $handoff_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $ai_context_at
 * @property string|null $ip_hash
 * @property string|null $first_page
 * @property int|null $lead_id
 * @property int|null $customer_id
 * @property Carbon|null $banned_at
 * @property int|null $banned_by_id
 * @property Carbon|null $purged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['locale', 'name', 'phone', 'unread_count', 'last_message_at', 'last_inbound_at', 'last_alert_at'])]
#[Hidden(['token_hash', 'ip_hash'])]
class WebChat extends Model implements ChatSubject
{
    /** @use HasFactory<WebChatFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consent_at' => 'datetime',
            'mode' => ChatMode::class,
            'dialog' => 'array',
            'conversation_status' => ConversationStatus::class,
            'unread_count' => 'integer',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_staff_reply_at' => 'datetime',
            'last_alert_at' => 'datetime',
            'handoff_at' => 'datetime',
            'closed_at' => 'datetime',
            'ai_context_at' => 'datetime',
            'banned_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WebChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WebChatMessage::class);
    }

    /**
     * The AI turns of this chat (Plan 33 B); deleted with the chat (foreign key cascade).
     *
     * @return HasMany<ChatAiTurn, $this>
     */
    public function aiTurns(): HasMany
    {
        return $this->hasMany(ChatAiTurn::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
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
    public function bannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by_id');
    }

    public function chatChannel(): ChatChannel
    {
        return ChatChannel::Web;
    }

    public function chatLimiterKey(): string
    {
        return 'web:'.$this->id;
    }

    /**
     * WC-2: consent given, not banned, the bot answers (no staff) and no lead form is open.
     */
    public function mayRecordPhrases(): bool
    {
        return $this->consent_at !== null
            && ! $this->isBanned()
            && $this->mode === ChatMode::Bot
            && WebChatDialog::form($this->dialog) === null;
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null || $this->conversation_status === ConversationStatus::Closed;
    }

    /**
     * Over: no message for chatbot.web.expire_hours (the cookie then starts a new chat, before any prune runs).
     */
    public function isExpired(): bool
    {
        $last = $this->last_message_at ?? $this->created_at;

        return $last === null || $last->lt(now()->subHours(max(1, (int) config('chatbot.web.expire_hours', 24))));
    }

    /**
     * The visitor waits for staff: handed off, and no staff reply since the
     * hand-off or the visitor's last message.
     */
    public function isWaitingForStaff(): bool
    {
        if ($this->mode !== ChatMode::Manager || $this->handoff_at === null) {
            return false;
        }

        $since = collect([$this->handoff_at, $this->last_inbound_at])->filter()->max();

        return $this->last_staff_reply_at === null || $this->last_staff_reply_at->lt($since);
    }

    /**
     * Start a new AI context (Plan 33 W-9): earlier turns are never sent to
     * the model again. Called at every reset point (end, close, expiry,
     * hand-off, staff reply, ban, menu), also while AI is off.
     */
    public function resetAiContext(): void
    {
        $this->forceFill(['ai_context_at' => now()]);
    }

    /**
     * Whether the user may see this chat (matches scopeVisibleTo, CB-21):
     * telegram.inbox-all every chat; telegram.inbox only handed-off chats that
     * are unassigned or the user's own (a bot-only chat never).
     */
    public function isVisibleTo(User $user): bool
    {
        if (! $user->can('telegram.inbox')) {
            return false;
        }

        if ($user->can('telegram.inbox-all')) {
            return true;
        }

        return $this->handoff_at !== null && ($this->manager_id === null || $this->manager_id === $user->id);
    }

    /**
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

        $query->whereNotNull('web_chats.handoff_at')
            ->where(fn (Builder $inner) => $inner->whereNull('web_chats.manager_id')->orWhere('web_chats.manager_id', $user->id));
    }

    /**
     * Admin search: the visitor's name, phone digits (at least 3) or "W-{id}" (strict, 1 to 10 digits).
     * LIKE wildcards in the term match literally.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        if (preg_match('/^W-(\d{1,10})$/Di', $term, $match) === 1) {
            $query->where('web_chats.id', (int) $match[1]);

            return;
        }

        $pattern = Search::likePattern($term);
        $digits = (string) preg_replace('/\D+/', '', $term);

        $query->where(function (Builder $inner) use ($pattern, $digits): void {
            $inner->whereRaw("lower(web_chats.name) like ? escape '!'", [$pattern]);

            if (strlen($digits) >= 3) {
                $inner->orWhere('web_chats.phone', 'like', '%'.$digits.'%');
            }
        });
    }
}
