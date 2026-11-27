<?php

namespace App\Models;

use App\Casts\EncryptedText;
use App\Enums\AgentRole;
use App\Enums\DraftFeedbackReason;
use App\Enums\DraftOutcome;
use Database\Factories\OfficeDraftFeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What staff did with an AI draft: used, edited or rejected (plan 46 §2.2,
 * ED-3). note, draft_text and final_text are redacted and encrypted at rest
 * (EncryptedText) and nulled by the retention rules; the row itself stays as
 * a count. Exactly one of chat / group is set (the action enforces it).
 *
 * @property int $id
 * @property int|null $business_chat_id
 * @property int|null $business_chat_analysis_id
 * @property int|null $telegram_group_id
 * @property int|null $telegram_group_thread_id
 * @property int|null $telegram_group_analysis_id
 * @property int|null $agent_version_id
 * @property AgentRole $role
 * @property DraftOutcome $outcome
 * @property DraftFeedbackReason|null $reason
 * @property string|null $note
 * @property string|null $draft_text
 * @property string|null $final_text
 * @property int|null $user_id
 * @property int|null $promoted_version_id
 * @property int|null $promoted_by_id
 * @property Carbon|null $promoted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'business_chat_id', 'business_chat_analysis_id', 'telegram_group_id', 'telegram_group_thread_id', 'telegram_group_analysis_id',
    'agent_version_id', 'role', 'outcome', 'reason', 'note', 'draft_text', 'final_text', 'user_id',
])]
class OfficeDraftFeedback extends Model
{
    /** @use HasFactory<OfficeDraftFeedbackFactory> */
    use HasFactory;

    protected $table = 'office_draft_feedback';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AgentRole::class,
            'outcome' => DraftOutcome::class,
            'reason' => DraftFeedbackReason::class,
            'note' => EncryptedText::class,
            'draft_text' => EncryptedText::class,
            'final_text' => EncryptedText::class,
            'promoted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BusinessChat, $this>
     */
    public function businessChat(): BelongsTo
    {
        return $this->belongsTo(BusinessChat::class);
    }

    /**
     * @return BelongsTo<BusinessChatAnalysis, $this>
     */
    public function businessChatAnalysis(): BelongsTo
    {
        return $this->belongsTo(BusinessChatAnalysis::class);
    }

    /**
     * @return BelongsTo<TelegramGroup, $this>
     */
    public function telegramGroup(): BelongsTo
    {
        return $this->belongsTo(TelegramGroup::class);
    }

    /**
     * @return BelongsTo<TelegramGroupThread, $this>
     */
    public function telegramGroupThread(): BelongsTo
    {
        return $this->belongsTo(TelegramGroupThread::class);
    }

    /**
     * @return BelongsTo<AgentProfileVersion, $this>
     */
    public function agentVersion(): BelongsTo
    {
        return $this->belongsTo(AgentProfileVersion::class, 'agent_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
