<?php

namespace App\Models;

use App\Enums\ChatChannel;
use Database\Factories\ChatUnmatchedFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A phrase the bot did not understand (plan 28, CB-10). "normalized" and
 * "sample" are stored already redacted by RecordUnmatched and never carry a
 * chat or person. Written only by RecordUnmatched; never exposed to MCP (CB-26).
 *
 * @property int $id
 * @property ChatChannel $channel
 * @property string $locale
 * @property string $normalized
 * @property string $sample
 * @property int $count
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_rule_id
 */
#[Fillable(['channel', 'locale', 'normalized', 'sample', 'count', 'first_seen_at', 'last_seen_at', 'resolved_at', 'resolved_rule_id'])]
class ChatUnmatched extends Model
{
    /** @use HasFactory<ChatUnmatchedFactory> */
    use HasFactory;

    protected $table = 'chat_unmatched';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ChatChannel::class,
            'count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ChatRule, $this>
     */
    public function resolvedRule(): BelongsTo
    {
        return $this->belongsTo(ChatRule::class, 'resolved_rule_id');
    }
}
