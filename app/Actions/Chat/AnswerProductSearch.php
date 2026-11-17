<?php

namespace App\Actions\Chat;

use App\Enums\ChatChannel;
use App\Enums\ChatMetric;
use App\Models\Product;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\ChatSettings;
use App\Support\Chat\ChatSubject;
use App\Support\Money;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Product search for the chat bot (plan 28 C3, CB-14; widened to ChatSubject
 * in C5, WC-1): the engine's SEARCH marker (model-like text, or a "product
 * search" rule) runs here, for a Telegram customer or a website chat.
 *
 *  - The raw message is searched (SearchProducts, the site's query: visible
 *    products only, escaped LIKE, at most 5 words of 100 characters).
 *  - Limits before the query: per customer chatbot.search.per_minute_user
 *    (10), over all chats per_minute_global (120); over them the bot asks the
 *    customer to talk to a manager. A message without words runs no query.
 *    Keys "chat-search:user:{chatLimiterKey}": Telegram's bare id keeps its
 *    keys as before, a web chat is "web:{id}". The web has its own global
 *    budget (chat-search:global:web, per_minute_global_web), so website
 *    traffic never uses up Telegram's. Stats go to the subject's channel.
 *  - Web (WC-16): the stored text is the header only (no names, no prices);
 *    the product buttons carry the ids and the widget builds the cards live.
 *  - At most min(products shown setting, 8) results: name and the Retail
 *    price (Money) or "price on request". Dealer and Wholesale prices are
 *    never read; no images. Each result is a server-stored button
 *    (bot_dialog), which opens the product: a route() link to its page and a
 *    "Request a quote" button that starts the quote dialog.
 */
class AnswerProductSearch
{
    public function __construct(
        private readonly SearchProducts $search,
        private readonly RecordChatStat $stats,
    ) {}

    public function handle(ChatSubject $user, ChatContext $context, ChatReply $marker): ChatReply
    {
        $locale = $context->locale;
        $channel = $user->chatChannel();
        $term = SearchProducts::term($context->text);

        if (SearchProducts::words($term) === [] || $this->isKeywordRule($marker)) {
            $this->stats->handle($channel, ChatMetric::Hit, $marker->ruleId ?? 0);

            return new ChatReply($marker->text, $marker->buttons, $marker->ruleId, ChatReply::DONE);
        }

        if (! $this->allow($user)) {
            return new ChatReply(__('chatbot.search.limited', [], $locale), [$this->handoff($locale)], $marker->ruleId, ChatReply::DONE);
        }

        $limit = min(SearchProducts::MAX_RESULTS, max(1, (int) config('chatbot.search.max_results', 8)), ChatSettings::all()['products_shown']);
        $products = $this->search->handle($term, $limit);

        if ($products->isEmpty()) {
            $this->stats->handle($channel, ChatMetric::SearchEmpty, $marker->ruleId ?? 0);

            return $marker->ruleId !== null
                ? new ChatReply($marker->text, $marker->buttons, $marker->ruleId, ChatReply::DONE)
                : new ChatReply(__('chatbot.search.none', [], $locale), [$this->handoff($locale), $this->menu($locale)], null, ChatReply::DONE);
        }

        $this->stats->handle($channel, ChatMetric::Hit, $marker->ruleId ?? 0);

        $lines = [__('chatbot.search.found', [], $locale)];
        $buttons = [];

        foreach ($products->values() as $position => $product) {
            $name = $this->name($product, $locale);

            if ($channel === ChatChannel::Telegram) {
                $lines[] = __('chatbot.search.line', ['n' => $position + 1, 'name' => $name, 'price' => self::price($product, $locale)], $locale);
            }

            $buttons[] = ['type' => 'product', 'label' => ($position + 1).'. '.$name, 'id' => $product->id];
        }

        return new ChatReply(implode("\n", $lines), [...$buttons, $this->handoff($locale), $this->menu($locale)], $marker->ruleId, ChatReply::DONE);
    }

    /**
     * The marker came from a product_search rule hit by its keywords: the phrase is not a query,
     * the customer gets the search prompt (no limiter hit, no empty-search stat).
     */
    private function isKeywordRule(ChatReply $marker): bool
    {
        return $marker->ruleId !== null;
    }

    /**
     * A product button of the last result list: the product again only while it is published.
     */
    public function product(int $productId, string $locale): ChatReply
    {
        $product = Product::query()->visible()->with('brand')->whereKey($productId)->first();

        if (! $product instanceof Product) {
            return new ChatReply(__('chatbot.search.unavailable', [], $locale), [$this->handoff($locale), $this->menu($locale)], null, ChatReply::DONE);
        }

        $lines = [$this->name($product, $locale)];
        $brand = $product->publicBrand()?->name;

        if (is_string($brand) && $brand !== '') {
            $lines[] = __('chatbot.search.brand', ['brand' => $brand], $locale);
        }

        $lines[] = __('chatbot.search.price', ['price' => self::price($product, $locale)], $locale);

        return new ChatReply(implode("\n", $lines), [
            ['type' => 'url', 'label' => __('chatbot.search.open', [], $locale), 'url' => route('site.product', ['locale' => $locale, 'product' => $product->slug])],
            ['type' => 'quote', 'label' => __('chatbot.search.quote', [], $locale), 'id' => $product->id],
            $this->handoff($locale),
            $this->menu($locale),
        ], null, ChatReply::DONE);
    }

    /**
     * The Retail price (the only public one) or "price on request".
     */
    public static function price(Product $product, string $locale): string
    {
        if ($product->price === null) {
            return __('chatbot.search.price_on_request', [], $locale);
        }

        $price = Money::format($product->price);

        return $product->isPricedPerLabel() ? __('catalog.roll.per_label', ['price' => $price], $locale) : $price;
    }

    private function name(Product $product, string $locale): string
    {
        return trim((string) $product->getTranslation('name', $locale));
    }

    /**
     * Atomic hits, per customer first; each channel has its own global budget (WC-1).
     */
    private function allow(ChatSubject $user): bool
    {
        if (RateLimiter::hit('chat-search:user:'.$user->chatLimiterKey(), 60) > (int) config('chatbot.search.per_minute_user', 10)) {
            return false;
        }

        if ($user->chatChannel() === ChatChannel::Web) {
            return RateLimiter::hit('chat-search:global:web', 60) <= (int) config('chatbot.search.per_minute_global_web', 60);
        }

        return RateLimiter::hit('chat-search:global', 60) <= (int) config('chatbot.search.per_minute_global', 120);
    }

    /**
     * @return array{type: string, label: string}
     */
    private function handoff(string $locale): array
    {
        return ['type' => 'handoff', 'label' => __('chatbot.buttons.handoff', [], $locale)];
    }

    /**
     * @return array{type: string, label: string}
     */
    private function menu(string $locale): array
    {
        return ['type' => 'menu', 'label' => __('chatbot.buttons.menu', [], $locale)];
    }
}
