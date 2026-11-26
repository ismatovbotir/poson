<?php

namespace App\Models;

use App\Casts\EncryptedText;
use App\Enums\TelegramMessageKind;
use Database\Factories\TelegramGroupMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stored message of a tracked group (plan 44, OG-11). text is encrypted
 * at rest (EncryptedText); media is safe metadata only, never a file_id.
 * Never logged, never in a job payload, never in MCP.
 *
 * @property int $id
 * @property int $telegram_group_id
 * @property int $member_id
 * @property int $telegram_message_id
 * @property TelegramMessageKind $kind
 * @property string|null $text
 * @property array<string, int|string>|null $media
 * @property string|null $media_group_id
 * @property int|null $reply_to_message_id
 * @property Carbon|null $sent_at
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 */
#[Fillable(['telegram_message_id', 'kind', 'text', 'media', 'media_group_id', 'reply_to_message_id', 'sent_at', 'edited_at'])]
class TelegramGroupMessage extends Model
{
    /** @use HasFactory<TelegramGroupMessageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'telegram_message_id' => 'integer',
            'kind' => TelegramMessageKind::class,
            'text' => EncryptedText::class,
            'media' => 'array',
            'reply_to_message_id' => 'integer',
            'sent_at' => 'datetime',
            'edited_at' => 'datetime',
            'created_at' => 'datetime',
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
     * @return BelongsTo<TelegramGroupMember, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(TelegramGroupMember::class, 'member_id');
    }
}
