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
     * The hard cap of AI retrieval candidates (AI-B4).
     */
    public const MAX_ANY_RESULTS = 6;

    /**
     * The only product columns AI retrieval reads (L5): never price_dealer,
     * price_wholesale, stock or import fields.
     */
    public const ANY_COLUMNS = [
        'products.id', 'products.category_id', 'products.brand_id', 'products.type', 'products.sku',
        'products.model', 'products.name', 'products.slug', 'products.price', 'products.pricing_unit',
        'products.unit', 'products.sale_mode', 'products.is_active', 'products.is_featured', 'products.sort',
        'products.deleted_at',
    ];

    /**
     * Relations AI retrieval loads. The documents are only id, type and
     * locale: their availability is printed, never a title, file name or URL (K-13).
     *
     * @var list<string>
     */
    public const ANY_RELATIONS = ['brand', 'category', 'keySpecValues.attribute', 'keySpecValues.option', 'documents:id,product_id,type,locale'];

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
     * Products matching ANY of the words (AI retrieval, plan 30 §6, AI-B4):
     * published products only (visible()), each word an escaped, bound LIKE
     * pattern (SearchText::normalizedPattern) on search_text, ordered by the
     * number of matched words (a CASE sum over the same bound patterns),
     * then featured, sort and id. At most MAX_WORDS words of at least 3
     * characters (2 with a digit), MAX_ANY_RESULTS results, one query with
     * the brand, the category and the key spec values eager-loaded. No price
     * level, stock or cost column is selected (ANY_COLUMNS). Empty without a

     * usable word (no database query then).
     *
     * @param  list<string>  $words  Already normalized (SearchProducts::words) and stopword-free
     * @return Collection<int, Product>
     */
    public function anyQuery(array $words, int $limit = self::MAX_ANY_RESULTS): Collection
    {
        $words = array_slice(array_values(array_unique(array_filter(
            array_map(fn (mixed $word): string => is_string($word) ? SearchText::normalize(mb_substr($word, 0, self::MAX_LENGTH)) : '', $words),
            fn (string $word): bool => $word !== '' && ! str_contains($word, ' ') && mb_strlen($word) >= (preg_match('/\d/', $word) === 1 ? 2 : 3),
        ))), 0, self::MAX_WORDS);

        if ($words === []) {
            return new Collection;
        }

        $patterns = array_map(fn (string $word): string => SearchText::normalizedPattern($word), $words);
        $score = implode(' + ', array_fill(0, count($patterns), "(case when search_text like ? escape '!' then 1 else 0 end)"));

        return Product::query()
            ->select(self::ANY_COLUMNS)
            ->visible()
            ->where(function (Builder $query) use ($patterns): void {

                foreach ($patterns as $pattern) {
                    $query->orWhereRaw("search_text like ? escape '!'", [$pattern]);
                }
            })
            ->orderByRaw("({$score}) desc", $patterns)
            ->orderByDesc('products.is_featured')
            ->orderBy('products.sort')
            ->orderBy('products.id')
            ->with(self::ANY_RELATIONS)
            ->limit(max(1, min($limit, self::MAX_ANY_RESULTS)))
            ->get();
    }

    /**
     * Published products by id (the service and software pages the AI found,
     * plan 33 K-13): the same columns, scope and relations as anyQuery(), so a
     * hidden product is never returned.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    public function visibleByIds(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return Product::query()
            ->select(self::ANY_COLUMNS)
            ->visible()
            ->whereIn('products.id', array_slice(array_values(array_unique($ids)), 0, self::MAX_ANY_RESULTS))
            ->orderBy('products.id')
            ->with(self::ANY_RELATIONS)
            ->get();
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
