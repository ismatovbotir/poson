<?php

namespace App\Models;

use App\Enums\AgentRole;
use Database\Factories\AgentProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Virtual office agent (plan 41 §5): one row per AgentRole::profiles()
 * (LaunchSeeder, idempotent). current_version_id is the published version
 * (set by PublishAgentProfileVersion only).
 *
 * @property int $id
 * @property AgentRole $role
 * @property int|null $current_version_id
 * @property bool $use_site_knowledge
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['role', 'use_site_knowledge'])]
class AgentProfile extends Model
{
    /** @use HasFactory<AgentProfileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AgentRole::class,
            'current_version_id' => 'integer',
            'use_site_knowledge' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AgentProfileVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AgentProfileVersion::class);
    }

    /**
     * @return BelongsTo<AgentProfileVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AgentProfileVersion::class, 'current_version_id');
    }

    public static function forRole(AgentRole $role): ?self
    {
        return static::query()->where('role', $role->value)->first();
    }
}
