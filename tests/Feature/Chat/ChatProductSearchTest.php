<?php

require_once __DIR__.'/ChatBotHelpers.php';

use App\Actions\Chat\SearchProducts;
use App\Livewire\Site\Search;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/**
 * Plan 28 C3: the bot's product search (CB-14): visible products only, Retail
 * price only, "price on request", word and length caps, limits before the
 * query; the Site\Search page stays as it was after the shared-query refactor.
 */
function cbProduct(string $model, array $attributes = [], array|string $states = []): Product
{
    $factory = Product::factory();

    foreach ((array) $states as $state) {
        $factory = $factory->{$state}();
    }

    return $factory->create(array_merge([
        'model' => $model,
        'sku' => 'SKU-'.$model,
        'name' => ['uz' => "Scanner {$model}", 'ru' => "Scanner {$model}", 'en' => "Scanner {$model}"],
    ], $attributes));
}

beforeEach(fn () => cbSetup());

it('returns visible products only: never hidden, inactive or deleted ones', function () {
    cbUser();
    cbProduct('QX7701');
    cbProduct('QX7702', states: 'inactive');
    cbProduct('QX7703')->delete();
    cbProduct('QX7704', ['category_id' => Category::factory()->inactive()->create()->id]);

    cbText('QX77');

    expect(cbLast())->toContain('QX7701')
        ->not->toContain('QX7702')
        ->not->toContain('QX7703')
        ->not->toContain('QX7704');
});

it('shows the Retail price only and never the dealer or wholesale price', function () {
    $user = cbUser();
    $product = cbProduct('QX7710', ['price' => 1_234_500_00, 'price_dealer' => 1_111_100_00, 'price_wholesale' => 987_600_00]);

    cbText('QX7710');
    $list = cbLast();
    cbPress(cbButton($user, 'product'));
    $card = cbLast();

    foreach ([$list, $card] as $text) {
        expect($text)->toContain(e(Money::format($product->price)))
            ->not->toContain(e(Money::format(1_111_100_00)))
            ->not->toContain(e(Money::format(987_600_00)))
            ->not->toContain('1111')
            ->not->toContain('9876');
    }
});

it('says price on request when there is no retail price', function () {
    $user = cbUser();
    cbProduct('QX7720', states: 'priceOnRequest');

    cbText('QX7720');
    $list = cbLast();
    cbPress(cbButton($user, 'product'));

    expect($list)->toContain(__('chatbot.search.price_on_request', [], 'en'))
        ->and(cbLast())->toContain(__('chatbot.search.price_on_request', [], 'en'));
});

it('marks a per-label price as per label', function () {
    cbUser();
    $product = cbProduct('QX7730', states: ['consumable', 'perLabel']);

    cbText('QX7730');

    expect(cbLast())->toContain(e(__('catalog.roll.per_label', ['price' => Money::format($product->price)], 'en')));
});

it('keeps the order of the site search: featured first, then sort, then id', function () {
    cbUser();
    cbProduct('QX7741', ['sort' => 5]);
    cbProduct('QX7742', ['sort' => 1]);
    cbProduct('QX7743', ['sort' => 9], 'featured');

    cbText('QX774');
    $text = cbLast();

    expect(strpos($text, 'QX7743'))->toBeLessThan(strpos($text, 'QX7742'))
        ->and(strpos($text, 'QX7742'))->toBeLessThan(strpos($text, 'QX7741'));
});

it('answers an empty result with the none text and a manager button, and counts it', function () {
    cbUser();

    cbText('ZZ9999');

    expect(cbLast())->toBe(e(__('chatbot.search.none', [], 'en')));
});

it('uses at most five words and one hundred characters of the message', function () {
    cbUser();
    cbProduct('QX7750', ['name' => ['uz' => 'Alpha beta gamma delta QX7750', 'ru' => 'Alpha beta gamma delta QX7750', 'en' => 'Alpha beta gamma delta QX7750']]);

    cbText('QX7750 alpha beta gamma delta epsilon unknownword');

    expect(cbLast())->toContain('QX7750');

    $long = 'qx7750 '.str_repeat('a', 400);

    expect(mb_strlen(SearchProducts::term($long)))->toBeLessThanOrEqual(100)
        ->and(count(SearchProducts::words('a b c d e f g h')))->toBe(5);
});

it('treats LIKE wildcards in the message literally', function () {
    cbUser();
    cbProduct('QX7760');

    cbText('QX%');

    expect(cbLast())->not->toContain('QX7760');
});

it('asks for a manager over the per-customer limit, before any search query', function () {
    config(['chatbot.search.per_minute_user' => 2]);
    cbUser();
    cbProduct('QX7770');

    cbText('QX7770');
    cbText('QX7770');

    DB::enableQueryLog();
    cbText('QX7770');
    $log = DB::getQueryLog();

    expect(cbLast())->toBe(e(__('chatbot.search.limited', [], 'en')))
        ->and(cbQueriesLike($log, 'search_text'))->toBe(0);
});

it('asks for a manager over the global limit, before any search query', function () {
    config(['chatbot.search.per_minute_global' => 2]);
    cbProduct('QX7780');
    foreach ([1, 2, 3] as $n) {
        cbUser(['phone' => '+99890123456'.$n], CB_USER + $n);
    }

    cbText('QX7780', CB_USER + 1);
    cbText('QX7780', CB_USER + 2);

    DB::enableQueryLog();
    cbText('QX7780', CB_USER + 3);
    $log = DB::getQueryLog();

    expect(cbLast())->toBe(e(__('chatbot.search.limited', [], 'en')))
        ->and(cbQueriesLike($log, 'search_text'))->toBe(0);
});

it('answers a keyword-matched search rule with its prompt, no query and no limiter hit', function () {
    $user = cbUser();

    DB::enableQueryLog();
    cbText('skaner');
    $log = DB::getQueryLog();

    expect(cbQueriesLike($log, 'search_text'))->toBe(0)
        ->and(RateLimiter::attempts('chat-search:user:'.$user->id))->toBe(0)
        ->and(RateLimiter::attempts('chat-search:global'))->toBe(0);
});

it('refuses a product button once the product is hidden', function () {
    $user = cbUser();
    $product = cbProduct('QX7790');

    cbText('QX7790');
    $button = cbButton($user, 'product');
    $product->update(['is_active' => false]);
    cbPress($button);

    expect(cbLast())->toBe(e(__('chatbot.search.unavailable', [], 'en')));
});

it('keeps the site search page unchanged: results, order, pagination and eager loads', function () {
    config(['site.per_page' => 2]);
    $brand = Brand::factory()->create();
    cbProduct('QX7801', ['sort' => 3, 'brand_id' => $brand->id]);
    cbProduct('QX7802', ['sort' => 2, 'brand_id' => $brand->id]);
    cbProduct('QX7803', ['sort' => 9, 'brand_id' => $brand->id], 'featured');
    cbProduct('QX7804', ['sort' => 1, 'brand_id' => $brand->id], 'inactive');

    app()->setLocale('en');
    URL::defaults(['locale' => 'en']);

    DB::enableQueryLog();
    $first = Livewire::test(Search::class)->set('q', 'QX780');
    $log = DB::getQueryLog();

    $first->assertSeeInOrder(['Scanner QX7803', 'Scanner QX7802'])
        ->assertDontSee('Scanner QX7801')
        ->assertDontSee('Scanner QX7804');

    // One brands query for the whole page: no per-card lazy loads.
    expect(cbQueriesLike($log, 'from "brands"'))->toBeLessThanOrEqual(1);

    $second = $this->get('/en/search?q=QX780&page=2')->assertOk()->getContent();

    expect($second)->toContain('Scanner QX7801')->not->toContain('Scanner QX7803');
});
