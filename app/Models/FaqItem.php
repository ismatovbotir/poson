<?php

namespace App\Models;

use App\Models\Concerns\StoresTranslations;
use Database\Factories\FaqItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Translatable\HasTranslations;

/**
 * FAQ item (plan 04 §2, T12): a question and a plain-text answer per locale
 * (uz required, ru/en fall back to uz). The owner (faqable) is a Category or
 * a Product; null = the general FAQ (home page, Settings → Company → FAQ).
 *
 * Written only through App\Actions\Faq\*; answers are rendered escaped with
 * line breaks only (never as HTML).
 *
 * @property int $id
 * @property string|null $faqable_type
 * @property int|null $faqable_id
 * @property int $sort
 * @property bool $is_active
 */
#[Fillable(['faqable_type', 'faqable_id', 'question', 'answer', 'sort', 'is_active'])]
class FaqItem extends Model
{
    /** @use HasFactory<FaqItemFactory> */
    use HasFactory, HasTranslations, StoresTranslations;

    /**
     * Longest question and answer (characters, per locale).
     */
    public const QUESTION_MAX = 255;

    public const ANSWER_MAX = 2000;

    /**
     * @var list<string>
     */
    public array $translatable = ['question', 'answer'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function faqable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<FaqItem>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Items of one owner (null = the general FAQ).
     *
     * @param  Builder<FaqItem>  $query
     */
    public function scopeOwnedBy(Builder $query, ?Model $owner): void
    {
        if ($owner === null) {
            $query->whereNull('faqable_type')->whereNull('faqable_id');

            return;
        }

        $query->where('faqable_type', $owner->getMorphClass())->where('faqable_id', $owner->getKey());
    }

    /**
     * @param  Builder<FaqItem>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }

    public function isGeneral(): bool
    {
        return $this->faqable_type === null;
    }
}
