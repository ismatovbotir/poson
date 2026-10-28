<?php

namespace App\Models;

use App\Support\Ai\PromptOverrides;
use Database\Factories\AiPromptOverrideFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin-edited default hint of one AI field (plan 12), Settings → AI
 * prompts. One text per FieldRegistry key for every UI locale; without a
 * row the localized lang default (ai.hints.*) applies. Read through the
 * cached PromptOverrides, which is flushed on every save and delete here,
 * again after the commit or rollback (raw query-builder writes bypass
 * this: call PromptOverrides::forget()).
 *
 * @property int $id
 * @property string $field_key
 * @property string $prompt
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['field_key', 'prompt', 'updated_by'])]
class AiPromptOverride extends Model
{
    /** @use HasFactory<AiPromptOverrideFactory> */
    use HasFactory;

    /**
     * Dates on the AI prompts screens are shown in Tashkent time.
     */
    public const TIMEZONE = 'Asia/Tashkent';

    protected static function booted(): void
    {
        // Now (this process) and again after the commit or rollback: a read
        // inside the transaction would otherwise leave the uncommitted rows
        // (or a rolled-back change) cached for everyone.
        $forget = function (): void {
            PromptOverrides::forget();
            DB::afterCommit(fn () => PromptOverrides::forget());
            DB::afterRollBack(fn () => PromptOverrides::forget());
        };

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'updated_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
