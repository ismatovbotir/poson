<?php

namespace App\Actions\Chat;

use App\Models\Product;
use App\Support\Catalog\ProductCards;
use App\Support\Chat\AiPriceCandidate;
use App\Support\Chat\AiPrompt;
use App\Support\Search as SearchText;
use Illuminate\Support\Facades\App;

/**
 * Product candidates for one AI question (plan 30 §6, AI-8, AI-B4, AI-B5).
 *
 *  - Words of the (redacted) question: SearchProducts::term(), normalized,
 *    stopwords of every locale (config chatbot.ai.stopwords) dropped, words
 *    under 3 characters dropped unless they hold a digit (then 2), at most
 *    5. A question of stopwords only runs no query.
 *  - SearchProducts::anyQuery(): published products only, any word,
 *    best match first, at most chatbot.ai.products.limit (6), one query with
 *    the brand, category and key specs eager-loaded.
 *  - One <products> line per product: SKU | name | brand · category | up to
 *    4 key specs | Retail price (AnswerProductSearch::price(), or "price on
 *    request") | product URL. Every field goes through AiPrompt::field
 *    (control / format / bidi characters removed, "<" ">" replaced, so no
 *    "</products>" can be injected) with its cap: name 120, brand · category
 *    60, spec 60, line 400; the whole block stops at 2,500 characters.
 *    Never price_dealer, price_wholesale, cost or stock.
 */
class FindAiProducts
{
    public function __construct(private readonly SearchProducts $search) {}

    /**
     * @return list<AiProductCandidate>
     */
    public function handle(string $text, string $locale): array
    {
        $words = self::words($text);

        if ($words === []) {
            return [];
        }

        $products = $this->search->anyQuery($words, (int) config('chatbot.ai.products.limit', SearchProducts::MAX_ANY_RESULTS));
        $previous = App::getLocale();
        $candidates = [];
        $length = 0;

        try {
            App::setLocale($locale);

            foreach ($products as $product) {
                $candidate = $this->candidate($product, $locale);
                $length += mb_strlen($candidate->line) + 1;

                if ($length > (int) config('chatbot.ai.products.block_max', 2500)) {
                    break;
                }

                $candidates[] = $candidate;
            }
        } finally {
            App::setLocale($previous);
        }

        return $candidates;
    }

    /**
     * The search words of a question (see the class comment).
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $stopwords = [];

        foreach ((array) config('chatbot.ai.stopwords', []) as $list) {
            foreach ((array) $list as $word) {
                $stopwords[SearchText::normalize((string) $word)] = true;
            }
        }

        $words = [];

        foreach (explode(' ', SearchText::normalize(SearchProducts::term($text))) as $word) {
            $word = trim($word, ".,!?;:()[]{}\"'«»");

            if ($word === '' || isset($stopwords[$word]) || mb_strlen($word) < (preg_match('/\d/', $word) === 1 ? 2 : 3)) {
                continue;
            }

            $words[$word] = true;
        }

        return array_slice(array_keys($words), 0, SearchProducts::MAX_WORDS);
    }

    private function candidate(Product $product, string $locale): AiProductCandidate
    {
        $name = AiPrompt::field((string) $product->getTranslation('name', $locale), (int) config('chatbot.ai.products.name_max', 120));
        $meta = AiPrompt::field(implode(' · ', array_filter([
            (string) $product->publicBrand()?->name,
            (string) $product->category?->getTranslation('name', $locale),
        ])), (int) config('chatbot.ai.products.meta_max', 60));

        $specs = [];

        foreach (array_slice(ProductCards::keySpecs($product), 0, (int) config('chatbot.ai.products.specs', 4)) as $spec) {
            $specs[] = AiPrompt::field($spec['label'].': '.$spec['value'], (int) config('chatbot.ai.products.spec_max', 60));
        }

        $line = AiPrompt::field(implode(' | ', array_filter([
            AiPrompt::field((string) $product->sku, 60),
            $name,
            $meta,
            implode('; ', $specs),
            AiPrompt::field(AnswerProductSearch::price($product, $locale), 60),
            route('site.product', ['locale' => $locale, 'product' => $product->slug]),
        ], fn (string $field): bool => $field !== '')), (int) config('chatbot.ai.products.line_max', 400));

        return new AiProductCandidate(
            $product->id,
            $name,
            str_replace("\n", ' ', $line),
            AiPriceCandidate::product($product->price, $product->isPricedPerLabel(), [(string) $product->sku, (string) $product->model], [$name]),
        );
    }
}
