<?php

namespace App\Models;

use App\Enums\AiKnowledgeSource;
use Database\Factories\AiKnowledgeChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One chunk of public website text for the chat bot AI (plan 33 §3). Written
 * only by the knowledge indexer (App\Actions\Chat\Knowledge). The model has
 * no events and never saves another model (K-7). "url" is display only:
 * links are rebuilt from (source_type, source_id, locale) at reply time (K-5).
 *
 * @property int $id
 * @property AiKnowledgeSource $source_type
 * @property int $source_id
 * @property string $locale
 * @property int $chunk
 * @property string $title
 * @property string $url
 * @property string $text
 * @property string $search_text
 * @property int $priority
 * @property Carbon|null $visible_from
 * @property string $content_hash
 * @property Carbon $indexed_at
 */
#[Fillable(['source_type', 'source_id', 'locale', 'chunk', 'title', 'url', 'text', 'search_text', 'priority', 'visible_from', 'content_hash', 'indexed_at'])]
class AiKnowledgeChunk extends Model
{
    /** @use HasFactory<AiKnowledgeChunkFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => AiKnowledgeSource::class,
            'source_id' => 'integer',
            'chunk' => 'integer',
            'priority' => 'integer',
            'visible_from' => 'datetime',
            'indexed_at' => 'datetime',
        ];
    }

    /**
     * What may be used right now in a locale: published (visible_from passed
     * or none) and, for news, not older than chatbot.ai.knowledge.news_months
     * (K-1: enforced here, not only by the hourly clean-up).
     *
     * @param  Builder<AiKnowledgeChunk>  $query
     */
    public function scopeLive(Builder $query, string $locale): void
    {
        $query->where('locale', $locale)
            ->where(fn (Builder $when) => $when->whereNull('visible_from')->orWhere('visible_from', '<=', now()))
            ->where(fn (Builder $age) => $age->where('source_type', '!=', AiKnowledgeSource::News->value)
                ->orWhere('visible_from', '>=', now()->subMonths((int) config('chatbot.ai.knowledge.news_months', 24))));
    }
}
