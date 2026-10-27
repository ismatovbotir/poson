<?php

use App\Actions\Api\SyncPrices;
use App\Enums\PriceSyncStatus;
use App\Events\PriceSyncCompleted;
use App\Models\ApiToken;
use App\Models\PriceSyncLog;
use App\Models\Product;
use App\Support\Site\Sitemap;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    $this->plain = fakeApiTokenValue();
    $this->token = ApiToken::factory()->withPlainText($this->plain)->create(['name' => '1C office']);
});

function syncPrices(array $items): array
{
    return postPrices(['items' => $items], test()->plain)->assertOk()->json();
}

it('handles a mixed batch: updated, unchanged, unknown and invalid', function () {
    $changed = Product::factory()->create(['sku' => 'ZD421', 'price' => 100_00, 'price_dealer' => null, 'price_wholesale' => null]);
    $same = Product::factory()->create(['sku' => 'DS2208', 'price' => 500_00, 'price_dealer' => 450_00, 'price_wholesale' => null]);

    $body = syncPrices([
        priceItem('ZD421', 1_250_000, 1_200_000, 1_150_000),
        priceItem('DS2208', 500, 450, null),
        priceItem('NOPE-1', 10),
        priceItem('BAD', 0),
    ]);

    expect($body)->toMatchArray([
        'received' => 4, 'updated' => 1, 'unchanged' => 1, 'unknown' => 1, 'invalid' => 1,
    ])->and($body['items'])->toBe([
        ['code' => 'ZD421', 'status' => 'updated'],
        ['code' => 'DS2208', 'status' => 'unchanged'],
        ['code' => 'NOPE-1', 'status' => 'unknown'],
        ['code' => 'BAD', 'status' => 'invalid', 'errors' => ['retail_out_of_range']],
    ])->and($body['sync_id'])->toBe(PriceSyncLog::query()->sole()->id);

    expect($changed->fresh())
        ->price->toBe(1_250_000_00)
        ->price_dealer->toBe(1_200_000_00)
        ->price_wholesale->toBe(1_150_000_00)
        ->price_synced_at->not->toBeNull();

    // Unchanged but matched: the API owns its prices now too.
    expect($same->fresh()->price_synced_at)->not->toBeNull();
});

it('converts whole soʻm to tiyin and treats null or 0 Dealer / Wholesale as no price', function () {
    $product = Product::factory()->create(['sku' => 'TSC-1', 'price' => 1_00, 'price_dealer' => 1_00, 'price_wholesale' => 1_00]);

    syncPrices([priceItem('TSC-1', 99_999, 0, null)]);

    expect($product->fresh())
        ->price->toBe(9_999_900)
        ->price_dealer->toBeNull()
        ->price_wholesale->toBeNull();
});

it('validates each item on its own', function (mixed $item, array $errors) {
    Product::factory()->create(['sku' => 'OK-1']);

    $body = syncPrices([priceItem('OK-1', 10), $item]);

    expect($body['items'][0]['status'])->toBe('updated')
        ->and($body['items'][1]['status'])->toBe('invalid')
        ->and($body['items'][1]['errors'])->toBe($errors)
        ->and($body['invalid'])->toBe(1);
})->with([
    '0 retail' => [priceItem('X', 0), ['retail_out_of_range']],
    'negative retail' => [priceItem('X', -5), ['retail_out_of_range']],
    'null retail' => [priceItem('X', null), ['retail_required']],
    'float retail' => [priceItem('X', 10.5), ['retail_not_integer']],
    'string retail' => [priceItem('X', '100'), ['retail_not_integer']],
    'bool retail' => [priceItem('X', true), ['retail_not_integer']],
    'retail above 10^15' => [priceItem('X', SyncPrices::MAX_SOM + 1), ['retail_out_of_range']],
    'negative dealer' => [priceItem('X', 10, -1), ['dealer_out_of_range']],
    'string wholesale' => [priceItem('X', 10, null, '5'), ['wholesale_not_integer']],
    'float dealer' => [priceItem('X', 10, 1.5), ['dealer_not_integer']],
    'missing retail key' => [['code' => 'X', 'dealer' => null, 'wholesale' => null], ['retail_required']],
    'missing dealer key' => [['code' => 'X', 'retail' => 10, 'wholesale' => null], ['dealer_required']],
    'missing wholesale key' => [['code' => 'X', 'retail' => 10, 'dealer' => null], ['wholesale_required']],
    'missing code' => [['retail' => 10, 'dealer' => null, 'wholesale' => null], ['code_required']],
    'blank code' => [priceItem('   ', 10), ['code_required']],
    'numeric code' => [priceItem(12345, 10), ['code_not_string']],
    'long code' => [priceItem(str_repeat('A', 65), 10), ['code_too_long']],
    'not an object' => [['X', 10], ['item_not_object']],
    'empty array' => [[], ['item_not_object']],
    'scalar' => ['X', ['item_not_object']],
]);

it('keeps the code in the response with the item errors', function () {
    $body = syncPrices([priceItem(' ab-1 ', 'x', -1, 1.5)]);

    expect($body['items'][0])->toBe([
        'code' => 'AB-1',
        'status' => 'invalid',
        'errors' => ['retail_not_integer', 'dealer_out_of_range', 'wholesale_not_integer'],
    ]);
});

it('rejects JSON numbers written as floats, even whole ones', function (string $number) {
    $body = postPrices('{"items":[{"code":"X","retail":'.$number.',"dealer":null,"wholesale":null}]}', $this->plain)->assertOk()->json();

    expect($body['items'][0]['errors'])->toBe(['retail_not_integer']);
})->with(['10.0', '1e3', '1.5E2']);

it('rejects a big integer that does not fit as not an integer', function () {
    $body = postPrices('{"items":[{"code":"X","retail":123456789012345678901234567890,"dealer":null,"wholesale":null}]}', $this->plain)->assertOk()->json();

    expect($body['items'][0]['errors'])->toBe(['retail_not_integer']);
});

it('marks every occurrence of a duplicate code invalid', function () {
    $product = Product::factory()->create(['sku' => 'DUP', 'price' => 1_00]);

    $body = syncPrices([priceItem('DUP', 10), priceItem(' dup', 20), priceItem('OTHER', 5)]);

    expect($body['items'][0])->toBe(['code' => 'DUP', 'status' => 'invalid', 'errors' => ['duplicate_code']])
        ->and($body['items'][1])->toBe(['code' => 'DUP', 'status' => 'invalid', 'errors' => ['duplicate_code']])
        ->and($body['items'][2]['status'])->toBe('unknown')
        ->and($product->fresh()->price)->toBe(1_00)
        ->and($product->fresh()->price_synced_at)->toBeNull();
});

it('is idempotent', function () {
    $product = Product::factory()->create(['sku' => 'IDEM']);
    $items = [priceItem('IDEM', 777, 700, 650)];

    expect(syncPrices($items)['updated'])->toBe(1);

    $updatedAt = $product->fresh()->updated_at;
    $this->travel(5)->minutes();
    $second = syncPrices($items);

    expect($second)->toMatchArray(['updated' => 0, 'unchanged' => 1])
        ->and($product->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($product->fresh()->price_synced_at->gt($updatedAt))->toBeTrue();
});

it('matches soft-deleted products and keeps them in the trash', function () {
    $product = Product::factory()->create(['sku' => 'TRASHED', 'price' => 1_00]);
    $product->delete();

    expect(syncPrices([priceItem('TRASHED', 50)])['updated'])->toBe(1);

    $fresh = Product::withTrashed()->find($product->id);
    expect($fresh->price)->toBe(50_00)->and($fresh->trashed())->toBeTrue();
});

it('touches nothing but the prices', function () {
    $product = Product::factory()->priceOnRequest()->create(['sku' => 'ONLY', 'is_active' => false]);
    $before = $product->fresh()->only(['name', 'sale_mode', 'stock_status', 'is_active', 'slug', 'search_text', 'min_qty']);

    syncPrices([priceItem('ONLY', 100)]);

    expect($product->fresh()->only(array_keys($before)))->toEqual($before);
});

it('writes the log row with counts, unknown codes and the price history', function () {
    $product = Product::factory()->create(['sku' => 'HIST', 'price' => 10_00, 'price_dealer' => null, 'price_wholesale' => 9_00]);

    syncPrices([priceItem('HIST', 12, 11, null), priceItem('MISSING', 1), priceItem('MISSING-2', 1), priceItem('X', 0)]);

    $log = PriceSyncLog::query()->sole();

    expect($log)
        ->api_token_id->toBe($this->token->id)
        ->token_name->toBe('1C office')
        ->ip->toBe('127.0.0.1')
        ->status->toBe(PriceSyncStatus::Partial)
        ->received->toBe(4)
        ->updated->toBe(1)
        ->unknown->toBe(2)
        ->invalid->toBe(1)
        ->unknown_codes->toBe(['MISSING', 'MISSING-2'])
        ->error->toBeNull()
        ->duration_ms->toBeInt()
        ->and($log->results[0])->toBe([
            'product_id' => $product->id,
            'code' => 'HIST',
            'status' => 'updated',
            'old' => ['retail' => 10_00, 'dealer' => null, 'wholesale' => 9_00],
            'new' => ['retail' => 12_00, 'dealer' => 11_00, 'wholesale' => null],
        ])
        ->and($log->created_at->equalTo($product->fresh()->price_synced_at))->toBeTrue()
        ->and($product->fresh()->price_sync_log_id)->toBe($log->id)
        ->and($product->fresh()->priceSyncLog->is($log))->toBeTrue();
});

it('logs a clean batch as success', function () {
    Product::factory()->create(['sku' => 'CLEAN']);

    syncPrices([priceItem('CLEAN', 5)]);

    expect(PriceSyncLog::query()->sole()->status)->toBe(PriceSyncStatus::Success);
});

it('flushes the sitemap once when something changed, and not otherwise', function () {
    Product::factory()->create(['sku' => 'S1', 'price' => 1_00]);
    Product::factory()->create(['sku' => 'S2', 'price' => 1_00]);

    $flushes = 0;
    Event::listen(ForgettingKey::class, function (ForgettingKey $event) use (&$flushes): void {
        if ($event->key === Sitemap::CACHE_KEY) {
            $flushes++;
        }
    });

    syncPrices([priceItem('S1', 2), priceItem('S2', 3)]);
    expect($flushes)->toBe(1);

    syncPrices([priceItem('S1', 2), priceItem('S2', 3)]);
    expect($flushes)->toBe(1);
});

it('dispatches PriceSyncCompleted after the commit', function () {
    Event::fake([PriceSyncCompleted::class]);

    syncPrices([priceItem('ANY', 1)]);

    Event::assertDispatched(PriceSyncCompleted::class, fn (PriceSyncCompleted $event) => $event->log->unknown === 1);
});

it('applies 100 items with about one query per changed item', function () {
    Event::fake([PriceSyncCompleted::class]);

    foreach (range(1, 100) as $i) {
        Product::factory()->create(['sku' => "Q-{$i}", 'price' => 1_00]);
    }

    $items = array_map(fn (int $i) => priceItem("Q-{$i}", 1000 + $i, 900, null), range(1, 100));

    $queries = countQueries(fn () => app(SyncPrices::class)->handle(json_decode(json_encode(['items' => $items])), $this->token, '127.0.0.1'));

    expect($queries)->toBeLessThanOrEqual(105)
        ->and(Product::query()->where('price', '>', 100_000)->count())->toBe(100);
});

it('prunes log rows after 180 days', function () {
    $old = PriceSyncLog::factory()->create(['created_at' => now()->subDays(181)]);
    $recent = PriceSyncLog::factory()->create(['created_at' => now()->subDays(179)]);

    $this->artisan('model:prune', ['--model' => [PriceSyncLog::class]])->assertSuccessful();

    expect(PriceSyncLog::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(PriceSyncLog::query()->find($old->id))->toBeNull();
});

it('keeps the log when the token is deleted', function () {
    syncPrices([priceItem('ANY', 1)]);

    $this->token->delete();

    expect(PriceSyncLog::query()->sole())->api_token_id->toBeNull()->token_name->toBe('1C office');
});

it('accepts an empty object as an item only as item_not_object, never a JSON array', function () {
    $body = postPrices('{"items":[{}, []]}', $this->plain)->assertOk()->json();

    expect($body['items'][0]['errors'])->toBe(['code_required', 'retail_required', 'dealer_required', 'wholesale_required'])
        ->and($body['items'][1]['errors'])->toBe(['item_not_object']);
});

it('points the product at the latest matching batch and survives pruning', function () {
    $product = Product::factory()->create(['sku' => 'LINK']);

    syncPrices([priceItem('LINK', 10)]);
    syncPrices([priceItem('LINK', 10)]);
    $latest = PriceSyncLog::query()->latest('id')->first();

    expect($product->fresh()->price_sync_log_id)->toBe($latest->id);

    PriceSyncLog::query()->update(['created_at' => now()->subDays(200)]);
    $this->artisan('model:prune', ['--model' => [PriceSyncLog::class]])->assertSuccessful();

    expect($product->fresh())->price_sync_log_id->toBeNull()->price_synced_at->not->toBeNull();
});

it('rolls everything back on an unexpected failure and logs a server_error batch', function () {
    Exceptions::fake();
    $product = Product::factory()->create(['sku' => 'BOOM', 'price' => 1_00]);

    // The success log row fails to insert after the product update ran.
    PriceSyncLog::creating(function (PriceSyncLog $log): void {
        if ($log->status !== PriceSyncStatus::Rejected) {
            throw new RuntimeException('disk full');
        }
    });

    $response = postPrices(['items' => [priceItem('BOOM', 50), priceItem('OTHER', 5)]], $this->plain)
        ->assertStatus(500)
        ->assertJsonPath('error', 'server_error');

    $log = PriceSyncLog::query()->sole();

    expect($log)
        ->id->toBe($response->json('sync_id'))
        ->status->toBe(PriceSyncStatus::Rejected)
        ->error->toBe('server_error')
        ->received->toBe(2)
        ->and($product->fresh())->price->toBe(1_00)->price_synced_at->toBeNull();

    Exceptions::assertReported(RuntimeException::class);
});

it('alerts on a server_error batch', function () {
    Event::fake([PriceSyncCompleted::class]);
    Exceptions::fake();
    PriceSyncLog::creating(function (PriceSyncLog $log): void {
        if ($log->status !== PriceSyncStatus::Rejected) {
            throw new RuntimeException('boom');
        }
    });

    postPrices(['items' => [priceItem('ANY', 1)]], $this->plain)->assertStatus(500);

    Event::assertDispatched(PriceSyncCompleted::class, fn (PriceSyncCompleted $event) => $event->log->error === 'server_error' && $event->log->hasIssues());
});

it('locks the matched rows in SKU order inside a retried transaction', function () {
    Product::factory()->create(['sku' => 'B-2']);
    Product::factory()->create(['sku' => 'A-1']);

    DB::enableQueryLog();
    syncPrices([priceItem('B-2', 1), priceItem('A-1', 1)]);
    $select = collect(DB::getQueryLog())->first(fn (array $query) => str_contains($query['query'], 'from "products"') && str_contains($query['query'], '"sku" in'));
    DB::disableQueryLog();

    expect($select['bindings'])->toBe(['A-1', 'B-2'])
        ->and($select['query'])->toContain('order by "sku"');
});
