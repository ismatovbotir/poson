<?php

namespace App\Models;

use App\Casts\EncryptedText;
use App\Enums\AgentRole;
use App\Enums\AnalysisStatus;
use Database\Factories\BusinessChatAnalysisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Virtual office analysis of a client chat (plan 41 §6). summary,
 * next_step and draft_reply are encrypted at rest (VO-5) and validated
 * before they are stored (VO-8); a blocked draft is never stored
 * (draft_blocked). lead_fields: the validated plain-text lead hints
 * (intent, products, device, urgency), never money or a phone.
 *
 * @property int $id
 * @property int $business_chat_id
 * @property AnalysisStatus $status
 * @property string $trigger
 * @property int|null $requested_by_id
 * @property int|null $up_to_message_id
 * @property string|null $input_hash
 * @property AgentRole|null $role
 * @property string|null $topic
 * @property int|null $confidence
 * @property string|null $language
 * @property string|null $summary
 * @property string|null $next_step
 * @property string|null $draft_reply
 * @property bool $draft_blocked
 * @property array<string, mixed>|null $lead_fields
 * @property string|null $lead_action
 * @property int|null $lead_id
 * @property int|null $router_version_id
 * @property int|null $agent_version_id
 * @property array<string, string>|null $models
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable(['status', 'trigger', 'requested_by_id', 'up_to_message_id'])]
class BusinessChatAnalysis extends Model
{
    /** @use HasFactory<BusinessChatAnalysisFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const TRIGGER_DEBOUNCE = 'debounce';

    public const TRIGGER_MANUAL = 'manual';

    public const LEAD_CREATED = 'created';

    public const LEAD_UPDATED = 'updated';

    public const LEAD_NEEDS_PHONE = 'needs_phone';

    public const LEAD_SUGGESTED = 'suggested';

    public const LEAD_NONE = 'none';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AnalysisStatus::class,
            'role' => AgentRole::class,
            'confidence' => 'integer',
            'summary' => EncryptedText::class,
            'next_step' => EncryptedText::class,
            'draft_reply' => EncryptedText::class,
            'draft_blocked' => 'boolean',
            'lead_fields' => 'array',
            'models' => 'array',
            'up_to_message_id' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'created_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BusinessChat, $this>
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(BusinessChat::class, 'business_chat_id');
    }

    /**
     * @return BelongsTo<AgentProfileVersion, $this>
     */
    public function agentVersion(): BelongsTo
    {
        return $this->belongsTo(AgentProfileVersion::class, 'agent_version_id');
    }

    /**
     * @return BelongsTo<AgentProfileVersion, $this>
     */
    public function routerVersion(): BelongsTo
    {
        return $this->belongsTo(AgentProfileVersion::class, 'router_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }
}
