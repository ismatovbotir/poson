<?php

namespace App\Models;

use App\Casts\EncryptedText;
use App\Enums\BusinessAuthor;
use App\Enums\TelegramMessageKind;
use Database\Factories\BusinessMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stored message of a client business chat (plan 41 §2). text is
 * encrypted at rest (EncryptedText, VO-5); media is safe metadata only
 * (never a file_id). A Telegram delete nulls text and media. Never logged,
 * never in a job payload, never in MCP.
 *
 * @property int $id
 * @property int $business_chat_id
 * @property int $telegram_message_id
 * @property BusinessAuthor $author
 * @property TelegramMessageKind $kind
 * @property string|null $text
 * @property array<string, int|string>|null $media
 * @property string|null $media_group_id
 * @property int|null $reply_to_message_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 */
#[Fillable(['telegram_message_id', 'author', 'kind', 'text', 'media', 'media_group_id', 'reply_to_message_id', 'sent_at', 'edited_at', 'deleted_at'])]
class BusinessMessage extends Model
{
    /** @use HasFactory<BusinessMessageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telegram_message_id' => 'integer',
            'author' => BusinessAuthor::class,
            'kind' => TelegramMessageKind::class,
            'text' => EncryptedText::class,
            'media' => 'array',
            'reply_to_message_id' => 'integer',
            'sent_at' => 'datetime',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BusinessChat, $this>
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(BusinessChat::class, 'business_chat_id');
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
