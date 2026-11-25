<?php

namespace App\Models;

use Database\Factories\AgentProfileVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An immutable version of an agent's teaching (plan 41 §5). Created only by
 * SaveAgentProfileVersion; never updated (an update throws).
 *
 * @property int $id
 * @property int $agent_profile_id
 * @property int $version
 * @property string $instructions
 * @property string|null $knowledge
 * @property string|null $rules
 * @property list<array{client: string, reply: string, note: string}>|null $examples
 * @property string $model
 * @property int $max_tokens
 * @property string|null $change_note
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 */
#[Fillable(['version', 'instructions', 'knowledge', 'rules', 'examples', 'model', 'max_tokens', 'change_note', 'created_by_id'])]
class AgentProfileVersion extends Model
{
    /** @use HasFactory<AgentProfileVersionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'examples' => 'array',
            'max_tokens' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Agent profile versions are immutable.');
        });
    }

    /**
     * @return BelongsTo<AgentProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'agent_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
