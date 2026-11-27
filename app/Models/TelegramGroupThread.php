<?php

namespace App\Models;

use App\Casts\EncryptedText;
use App\Enums\AgentRole;
use App\Enums\DraftHold;
use Database\Factories\TelegramGroupThreadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One conversation thread found in a tracked group (plan 44 §2): per client
 * member, or the whole group when it is linked to a customer. summary,
 * next_step and draft are encrypted at rest (OG-11).
 *
 * @property int $id
 * @property int $telegram_group_id
 * @property int|null $client_member_id
 * @property AgentRole|null $role
 * @property string|null $topic
 * @property string|null $summary
 * @property string|null $next_step
 * @property string|null $draft
 * @property bool $draft_blocked
 * @property bool $needs_human
 * @property string|null $escalation
 * @property DraftHold|null $draft_hold
 * @property int|null $agent_version_id
 * @property list<int>|null $member_ids
 * @property int|null $lead_id
 * @property int|null $customer_id
 * @property int|null $last_analysis_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['client_member_id', 'role', 'topic', 'summary', 'next_step', 'draft', 'draft_blocked', 'last_analysis_id'])]
class TelegramGroupThread extends Model
{
    /** @use HasFactory<TelegramGroupThreadFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AgentRole::class,
            'summary' => EncryptedText::class,
            'next_step' => EncryptedText::class,
            'draft' => EncryptedText::class,
            'draft_blocked' => 'boolean',
            'needs_human' => 'boolean',
            'draft_hold' => DraftHold::class,
            'member_ids' => 'array',
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
    public function clientMember(): BelongsTo
    {
        return $this->belongsTo(TelegramGroupMember::class, 'client_member_id');
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
     * @return BelongsTo<TelegramGroupAnalysis, $this>
     */
    public function lastAnalysis(): BelongsTo
    {
        return $this->belongsTo(TelegramGroupAnalysis::class, 'last_analysis_id');
    }
}
