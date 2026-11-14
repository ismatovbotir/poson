<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\Region;
use App\Enums\VacancyDepartment;
use App\Models\Concerns\StoresTranslations;
use App\Support\Careers\VacancyDirectory;
use App\Support\Site\IndexNow;
use App\Support\Site\Sitemap;
use Database\Factories\VacancyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Translatable\HasTranslations;

/**
 * A job vacancy (plan 31) at /{locale}/careers/{slug}. Title, summary and
 * description are plain text per locale (escaped on output); requirements and
 * offer are lists per locale ({"uz": [...], "ru": [...], "en": [...]}).
 * Every locale has its own slug (slug_uz, slug_ru, slug_en; unique across all
 * three columns, checked by SaveVacancy). A vacancy is published only when
 * complete in all three locales (isComplete(), no fallback to uz) and public
 * while is_published and valid_through (Tashkent date) has not passed.
 *
 * Written only through App\Actions\Careers\*. Saving or deleting flushes the
 * VacancyDirectory (nav) and the sitemap after the commit.
 *
 * @property int $id
 * @property VacancyDepartment $department
 * @property Region $location
 * @property EmploymentType $employment_type
 * @property string $slug_uz
 * @property string|null $slug_ru
 * @property string|null $slug_en
 * @property bool $is_published
 * @property Carbon|null $published_at
 * @property Carbon|null $valid_through
 * @property int $sort
 * @property array<string, list<string>>|null $requirements
 * @property array<string, list<string>>|null $offer
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['department', 'location', 'employment_type', 'title', 'summary', 'description', 'requirements', 'offer', 'meta_title', 'meta_description', 'slug_uz', 'slug_ru', 'slug_en', 'is_published', 'published_at', 'valid_through', 'sort'])]
class Vacancy extends Model
{
    /** @use HasFactory<VacancyFactory> */
    use HasFactory, HasTranslations, StoresTranslations;

    /**
     * Time zone of valid_through.
     */
    public const TIMEZONE = 'Asia/Tashkent';

    public const TITLE_MAX = 150;

    public const SUMMARY_MAX = 300;

    public const DESCRIPTION_MAX = 8000;

    public const LIST_MAX_ITEMS = 20;

    public const LIST_ITEM_MAX = 250;

    public const SLUG_MAX = 120;

    /**
     * @var list<string>
     */
    public array $translatable = ['title', 'summary', 'description', 'meta_title', 'meta_description'];

    protected static function booted(): void
    {
        $flush = function (Vacancy $vacancy): void {
            IndexNow::record($vacancy);
            VacancyDirectory::flush();
            DB::afterCommit(function (): void {
                VacancyDirectory::flush();
                Sitemap::flush();
            });
        };

        static::saved($flush);
        static::deleted($flush);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'department' => VacancyDepartment::class,
            'location' => Region::class,
            'employment_type' => EmploymentType::class,
            'requirements' => 'array',
            'offer' => 'array',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'valid_through' => 'date',
            'sort' => 'integer',
        ];
    }

    /**
     * @return HasMany<JobApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class);
    }

    /**
     * Published and not past valid_through (end of that day, Tashkent).
     *
     * @param  Builder<Vacancy>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('is_published', true)
            ->where(function (Builder $query): void {
                $query->whereNull('valid_through')
                    ->orWhere('valid_through', '>=', Carbon::now(self::TIMEZONE)->toDateString());
            });
    }

    /**
     * Admin / public order: sort, then id.
     *
     * @param  Builder<Vacancy>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort')->orderBy('id');
    }

    /**
     * Whether the vacancy is public now (the instance twin of published()).
     */
    public function isPublic(): bool
    {
        return $this->is_published
            && ($this->valid_through === null || $this->valid_through->toDateString() >= Carbon::now(self::TIMEZONE)->toDateString());
    }

    /**
     * The slug in a locale (null when that locale has none).
     */
    public function slugFor(string $locale): ?string
    {
        if (! in_array($locale, config('app.supported_locales'), true)) {
            return null;
        }

        $slug = $this->getAttribute("slug_{$locale}");

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /**
     * Public URL in a locale (default: the current one), built from the model with route().
     */
    public function url(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return route('site.careers.show', ['locale' => $locale, 'slug' => $this->slugFor($locale) ?? $this->slug_uz]);
    }

    /**
     * Whether every locale has a title, slug, summary and description (needed to publish).
     */
    public function isComplete(): bool
    {
        foreach (config('app.supported_locales') as $locale) {
            if ($this->slugFor($locale) === null) {
                return false;
            }

            foreach (['title', 'summary', 'description'] as $field) {
                if (trim((string) $this->getTranslation($field, $locale, false)) === '') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * A list field (requirements / offer) in a locale.
     *
     * @return list<string>
     */
    public function listFor(string $field, string $locale): array
    {
        $items = ((array) $this->getAttribute($field))[$locale] ?? [];

        return array_values(array_filter((array) $items, fn ($item): bool => is_string($item) && $item !== ''));
    }
}
