<?php

namespace App\Actions\Chat;

use App\Models\Product;
use App\Support\Catalog\ProductCards;
use App\Support\Chat\AiPriceCandidate;
use App\Support\Chat\AiPrompt;
use App\Support\Search as SearchText;
use App\Support\Site\CanonicalHost;
use Illuminate\Database\Eloquent\Collection;
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
     * @param  list<int>  $extraIds  Service and software products of the website hits (FindAiKnowledge::productIds), joined after the search results within the same cap
     * @return list<AiProductCandidate>
     */
    public function handle(string $text, string $locale, array $extraIds = []): array
    {
        $words = self::words($text);
        $limit = (int) config('chatbot.ai.products.limit', SearchProducts::MAX_ANY_RESULTS);
        $products = $words === [] ? new Collection : $this->search->anyQuery($words, $limit);

        if ($extraIds !== []) {
            $known = $products->pluck('id')->all();
            $extra = $this->search->visibleByIds(array_values(array_diff($extraIds, $known)));
            $products = $products->concat($extra)->take($limit)->values();
        }

        if ($products->isEmpty()) {
            return [];
        }

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

    /**
     * "Docs: Datasheet, Driver": which document types exist (any locale), never a title, file name or URL (K-13).
     */
    private function documents(Product $product): string
    {
        $types = [];

        foreach ($product->getRelationValue('documents') ?? [] as $document) {
            $types[$document->type->value] = $document->type->label();
        }

        return $types === [] ? '' : AiPrompt::field(__('chatbot.knowledge.docs').': '.implode(', ', $types), 80);
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

        $url = CanonicalHost::using(fn (): string => route('site.product', ['locale' => $locale, 'product' => $product->slug]));
        $line = AiPrompt::field(implode(' | ', array_filter([
            AiPrompt::field((string) $product->sku, 60),
            $name,
            $meta,
            implode('; ', $specs),
            AiPrompt::field(AnswerProductSearch::price($product, $locale), 60),
            $this->documents($product),
            $url,
        ], fn (string $field): bool => $field !== '')), (int) config('chatbot.ai.products.line_max', 400));

        return new AiProductCandidate(
            $product->id,
            $name,
            str_replace("\n", ' ', $line),
            AiPriceCandidate::product($product->price, $product->isPricedPerLabel(), [(string) $product->sku, (string) $product->model], [$name]),
            $url,
        );
    }
}
