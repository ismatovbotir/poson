<?php

require_once __DIR__.'/ChatAbuseKit.php';

use App\Models\Category;
use App\Models\Product;
use App\Models\TelegramUser;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Plan 28 C3 security abuse cases 12-15 (section 17): the bot's product
 * search shows only public products and Retail prices, survives hostile
 * input and is rate limited before the query.
 */
beforeEach(fn () => labSetup());

/**
 * Product ids of the stored buttons of the customer's current answer.
 *
 * @return list<int>
 */
function labStoredProductIds(): array
{
    $dialog = TelegramUser::query()->where('telegram_id', LAB_USER)->firstOrFail()->bot_dialog;

    return collect($dialog['buttons'] ?? [])->where('t', 'product')->pluck('id')->values()->all();
}

// Abuse case 12
it('never lists an inactive, hidden-category or deleted product', function () {
    labUser();
    $visible = labProduct('ZXQ100-A');
    labProduct('ZXQ100-B', ['is_active' => false]);
    labProduct('ZXQ100-C', ['category_id' => Category::factory()->inactive()->create()->id]);
    labProduct('ZXQ100-D')->delete();

    $reply = labTalk('ZXQ100');

    expect(labText($reply))->toContain('Scanner ZXQ100-A')
        ->not->toContain('ZXQ100-B')->not->toContain('ZXQ100-C')->not->toContain('ZXQ100-D')
        ->and(labStoredProductIds())->toBe([$visible->id]);
});

// Abuse case 12
it('answers a stale product button as unavailable once the product is hidden', function (string $change) {
    labUser();
    $product = labProduct('ZXQ200-A');
    labTalk('ZXQ200');
    $button = labStoredButton('product');

    match ($change) {
        'inactive' => $product->update(['is_active' => false]),
        'deleted' => $product->delete(),
        'hidden category' => $product->category->update(['is_active' => false]),
    };

    $reply = labPress($button);

    expect(labText($reply))->toBe(labLang('chatbot.search.unavailable'))
        ->and(labText($reply))->not->toContain('ZXQ200');
})->with(['inactive', 'deleted', 'hidden category']);

// Abuse case 12
it('ignores a tampered product button that points at a hidden product', function () {
    $user = labUser();
    labProduct('ZXQ300-A');
    $hidden = labProduct('ZXQ300-B', ['is_active' => false]);
    labTalk('ZXQ300');

    $dialog = $user->fresh()->bot_dialog;
    $dialog['buttons'][0] = ['t' => 'product', 'id' => $hidden->id];
    $user->forceFill(['bot_dialog' => $dialog])->save();

    $reply = labPress('chat:b:'.$dialog['gen'].':0');

    expect(labText($reply))->toBe(labLang('chatbot.search.unavailable'));
});

// Abuse case 13
it('never shows dealer or wholesale prices in the result list or the product card', function () {
    labUser();
    labProduct('ZXQ400-A');

    $list = labTalk('ZXQ400');
    $card = labPress(labStoredButton('product'));

    foreach ([$list, $card] as $reply) {
        $digits = preg_replace('/\D/', '', labText($reply));

        expect($digits)->toContain('1250000')->not->toContain('7123456')->not->toContain('6234567')
            ->and(labText($reply))->not->toContain('7 123')->not->toContain('6 234');
    }

    expect(collect(telegramApiCalls())->pluck('method')->all())->not->toContain('sendPhoto');
});

// Abuse case 13
it('says price on request for a product without a Retail price and still hides the other levels', function () {
    labUser();
    labProduct('ZXQ500-A', ['price' => null]);

    $list = labTalk('ZXQ500');
    $card = labPress(labStoredButton('product'));

    foreach ([$list, $card] as $reply) {
        expect(labText($reply))->toContain(labLang('chatbot.search.price_on_request'))
            ->and(preg_replace('/\D/', '', labText($reply)))->not->toContain('7123456')->not->toContain('6234567');
    }
});

// Abuse case 13
it('links the product card to its public page in the customers locale only', function () {
    labUser();
    $product = labProduct('ZXQ600-A');
    labTalk('ZXQ600');

    $card = labPress(labStoredButton('product'));

    expect(json_encode($card['reply_markup']))->toContain(str_replace('/', '\/', route('site.product', ['locale' => 'en', 'product' => $product->slug])));
});

// Abuse case 14
it('survives hostile input without an error and returns at most 8 products', function (string $text) {
    labUser();

    foreach (range(1, 12) as $n) {
        labProduct('ZXQ700-'.$n);
    }

    labProduct('QQQ900-X');

    $reply = labTalk($text);

    expect($reply)->not->toBeNull()
        ->and(count(labStoredProductIds()))->toBeLessThanOrEqual(8)
        ->and(substr_count(labText($reply), "\n"))->toBeLessThanOrEqual(8)
        ->and(labText($reply))->not->toContain('QQQ900');
})->with([
    'wildcards' => 'ZXQ700%_!',
    'only wildcards with a model' => '100%_!',
    'cyrillic with a model' => 'Сканер ZXQ700',
    'ten kilobytes' => 'ZXQ700 '.str_repeat('x', 10240),
    'invalid utf-8' => "ZXQ700\xFF\xFE",
    'twenty words' => 'ZXQ700 a1b a2b a3b a4b a5b a6b a7b a8b a9b b1c b2c b3c b4c b5c b6c b7c b8c b9c',
]);

// Abuse case 14
it('treats a percent sign in the query as a literal character, not a wildcard', function () {
    labUser();
    labProduct('ABC123');

    $reply = labTalk('A%3');

    expect(labText($reply))->not->toContain('ABC123')
        ->and(labStoredProductIds())->toBe([]);
});

// Abuse case 14
it('shows no more than the configured number of products and never more than 8', function () {
    labUser();

    foreach (range(1, 12) as $n) {
        labProduct('ZXQ800-'.$n);
    }

    labTalk('ZXQ800');

    expect(count(labStoredProductIds()))->toBeLessThanOrEqual(8)->toBeGreaterThan(0);
});

// Abuse case 15
it('limits a customer to ten searches a minute and makes no products query for the eleventh', function () {
    labUser();
    labProduct('ZXQ900-A');

    foreach (range(1, 10) as $attempt) {
        expect(labText(labTalk('ZXQ900')))->toContain('ZXQ900-A');
    }

    [$reply, $counts] = labQueries(fn () => labTalk('ZXQ900'), 'products');

    expect(labText($reply))->toBe(labLang('chatbot.search.limited'))
        ->and($counts['products'])->toBe(0);
});

// Abuse case 15
it('limits all customers together to 120 searches a minute before the query', function () {
    labUser();
    labProduct('ZXQ910-A');

    foreach (range(1, 120) as $hit) {
        RateLimiter::hit('chat-search:global', 60);
    }

    [$reply, $counts] = labQueries(fn () => labTalk('ZXQ910'), 'products');

    expect(labText($reply))->toBe(labLang('chatbot.search.limited'))
        ->and($counts['products'])->toBe(0);
});

// Abuse case 15
it('answers a keyword-matched product search rule with its prompt, no query and no limiter hit', function () {
    $user = labUser();
    labProduct('ZXQ920-A');

    [$reply, $counts] = labQueries(fn () => labTalk('barcode scanner'), 'products');

    expect($counts['products'])->toBe(0)
        ->and(labText($reply))->not->toContain('ZXQ920')
        ->and(RateLimiter::attempts('chat-search:user:'.$user->id))->toBe(0)
        ->and(RateLimiter::attempts('chat-search:global'))->toBe(0);
});

// Abuse case 15
it('hits no limiter and runs no query for a message without any searchable word', function () {
    $user = labUser();

    [, $counts] = labQueries(fn () => labTalk('...'), 'products');

    expect($counts['products'])->toBe(0)
        ->and(RateLimiter::attempts('chat-search:user:'.$user->id))->toBe(0);
});
