<?php

namespace App\Models;

use App\Enums\KeywordMatch;
use Database\Factories\ChatRuleKeywordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A keyword of a chat rule. "phrase" is what the admin typed, "normalized"
 * its ChatText::normalize() form, the only one used for matching. A null
 * locale matches input in any language.
 *
 * @property int $id
 * @property int $chat_rule_id
 * @property string|null $locale
 * @property string $phrase
 * @property string $normalized
 * @property KeywordMatch $match_type
 * @property int $weight
 */
#[Fillable(['chat_rule_id', 'locale', 'phrase', 'normalized', 'match_type', 'weight'])]
class ChatRuleKeyword extends Model
{
    /** @use HasFactory<ChatRuleKeywordFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_type' => KeywordMatch::class,
            'weight' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ChatRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ChatRule::class, 'chat_rule_id');
    }
}
