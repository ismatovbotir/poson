<?php

namespace App\Actions\Chat;

use App\Models\Product;
use App\Support\Search as SearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Product search shared by the site search page (Site\Search) and the chat
 * bot (plan 28 C3, CB-14). Published products only (Product::visible()):
 * search_text (SKU, model, names in every locale, brand) must contain every
 * word of the query, at most MAX_WORDS words of a query cut to MAX_LENGTH
 * characters. Each word is an escaped LIKE pattern
 * (SearchText::normalizedPattern), so "%", "_" and "!" are literal.
 *
 * The raw text is searched as typed (never the chat bot's transliterated
 * form): search_text keeps Cyrillic names, so "сканер" must stay Cyrillic.
 * The chat caller applies its own rate limits before handle() (a leading
 * wildcard LIKE is a full scan) and builds the reply with Retail prices only.
 */
class SearchProducts
{
    public const MAX_LENGTH = 100;

    public const MAX_WORDS = 5;

    /**
     * The hard cap of chat results, whatever the caller asks for.
     */
    public const MAX_RESULTS = 8;

    /**
     * Up to $limit (1 to MAX_RESULTS) published products, featured first;
     * empty without a query (no database query then).
     *
     * @return Collection<int, Product>
     */
    public function handle(string $raw, int $limit): Collection
    {
        $query = $this->query(self::term($raw));

        if ($query === null) {
            return new Collection;
        }

        return $query
            ->with('brand')
            ->limit(max(1, min($limit, self::MAX_RESULTS)))
            ->get();
    }

    /**
     * The ordered search query for a prepared term (see term()), or null when
     * the term has no words. The site page paginates it.
     *
     * @return Builder<Product>|null
     */
    public function query(string $term): ?Builder
    {
        $words = self::words($term);

        if ($words === []) {
            return null;
        }

        return Product::query()
            ->visible()
            ->where(function (Builder $query) use ($words): void {
                foreach ($words as $word) {
                    $query->whereRaw("search_text like ? escape '!'", [SearchText::normalizedPattern($word)]);
                }
            })
            ->orderByDesc('products.is_featured')
            ->orderBy('products.sort')
            ->orderBy('products.id');
    }

    /**
     * The query cut to MAX_LENGTH before any other work (invalid UTF-8
     * scrubbed), whitespace collapsed.
     */
    public static function term(mixed $raw): string
    {
        if (! is_string($raw)) {
            return '';
        }

        $query = mb_substr(mb_scrub($raw, 'UTF-8'), 0, self::MAX_LENGTH * 2);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $query)), 0, self::MAX_LENGTH);
    }

    /**
     * Distinct normalized words of a term, at most MAX_WORDS.
     *
     * @return list<string>
     */
    public static function words(string $term): array
    {
        $words = array_filter(
            explode(' ', SearchText::normalize($term)),
            fn (string $word): bool => $word !== '',
        );

        return array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);
    }
}
