<?php

namespace App\Models;

use App\Enums\ChatAuthor;
use Database\Factories\WebChatMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message of a website chat (plan 28 C5 §19.2). Visitor text is stored
 * redacted; bot rows keep the server-stored buttons (WebAdapter). Plain
 * text everywhere: the widget and the admin escape it on output.
 *
 * @property int $id
 * @property int $web_chat_id
 * @property ChatAuthor $author
 * @property string $text
 * @property list<array{type: string, label?: string, id?: int, url?: string}>|null $buttons
 * @property int|null $chat_rule_id
 * @property int|null $user_id
 * @property int|null $lead_id
 * @property int|null $chat_ai_turn_id
 * @property Carbon|null $created_at
 */
#[Fillable(['author', 'text', 'buttons', 'chat_rule_id', 'user_id', 'lead_id', 'chat_ai_turn_id'])]
class WebChatMessage extends Model
{
    /** @use HasFactory<WebChatMessageFactory> */
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
            'author' => ChatAuthor::class,
            'buttons' => 'array',
        ];
    }

    /**
     * @return BelongsTo<WebChat, $this>
     */
    public function webChat(): BelongsTo
    {
        return $this->belongsTo(WebChat::class);
    }

    /**
     * @return BelongsTo<ChatRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ChatRule::class, 'chat_rule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
