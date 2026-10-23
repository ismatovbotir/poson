<?php

use App\Actions\Customers\DeleteCustomer;
use App\Actions\Quotes\ChangeQuoteStatus;
use App\Actions\Quotes\CreateQuote;
use App\Actions\Quotes\DeleteQuote;
use App\Actions\Quotes\DuplicateQuote;
use App\Actions\Quotes\SaveQuoteItems;
use App\Actions\Quotes\UpdateQuote;
use App\Data\Quotes\QuoteData;
use App\Data\Quotes\QuoteItemData;
use App\Enums\ActivityType;
use App\Enums\PriceLevel;
use App\Enums\ProductUnit;
use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Sequence;
use App\Models\User;
use App\Support\Quotes\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 */
/**
 * Validation errors an action throws (empty when it passes).
 *
 * @return array<string, list<string>>
 */
function actionErrorsOf(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

function newQuote(?Customer $customer = null, array $overrides = [], ?User $actor = null): Quote
{
    $customer ??= Customer::factory()->create();

    return app(CreateQuote::class)->handle(new QuoteData(...array_merge(['customerId' => $customer->id, 'items' => []], $overrides)), $actor ?? staffUser());
}

describe('numbering', function () {
    it('numbers quotes Q-YYYY-NNNN sequentially within a year and restarts each year', function () {
        $this->travelTo(CarbonImmutable::parse('2026-12-31 10:00', 'Asia/Tashkent'));
        $customer = Customer::factory()->create();
        $actor = staffUser();

        $numbers = collect(range(1, 3))->map(fn () => newQuote($customer, actor: $actor)->number)->all();

        expect($numbers)->toBe(['Q-2026-0001', 'Q-2026-0002', 'Q-2026-0003']);

        $this->travelTo(CarbonImmutable::parse('2027-01-01 09:00', 'Asia/Tashkent'));

        expect(newQuote($customer, actor: $actor)->number)->toBe('Q-2027-0001')
            ->and(Sequence::query()->pluck('value', 'key')->all())->toBe(['quote-2026' => 3, 'quote-2027' => 1]);
    });

    it('takes the next value under a row lock and never repeats, also inside outer transactions', function () {
        $values = [];

        foreach (range(1, 25) as $i) {
            $values[] = $i % 2 === 0
                ? DB::transaction(fn () => Sequence::next('test-key'))
                : Sequence::next('test-key');
        }

        expect($values)->toBe(range(1, 25));

        // A rolled-back transaction gives its number back (no gaps from failed saves).
        try {
            DB::transaction(function (): void {
                Sequence::next('test-key');

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        expect(Sequence::next('test-key'))->toBe(26);
    });

    it('survives a first use racing another first use', function () {
        // The row appears between the lookup and the insert: the unique key rejects the second insert.
        Sequence::query()->create(['key' => 'race', 'value' => 4]);

        expect(Sequence::next('race'))->toBe(5);
    });
});

it('prefills prices from the customer level with a fallback to Retail', function (PriceLevel $level, int $expected) {
    $customer = Customer::factory()->priceLevel($level)->create();
    $withAll = Product::factory()->create(['price' => 100_000_00, 'price_dealer' => 90_000_00, 'price_wholesale' => 80_000_00]);
    $retailOnly = Product::factory()->create(['price' => 50_000_00, 'price_dealer' => null, 'price_wholesale' => null]);

    $quote = newQuote($customer, ['items' => [new QuoteItemData(productId: $withAll->id, qty: 2), new QuoteItemData(productId: $retailOnly->id)]]);
    $items = $quote->items()->get();

    expect($quote->price_level)->toBe($level)
        ->and($items[0]->price)->toBe($expected)
        ->and($items[0]->total)->toBe($expected * 2)
        ->and($items[1]->price)->toBe(50_000_00)
        ->and($quote->total)->toBe($expected * 2 + 50_000_00);
})->with([
    'retail' => [PriceLevel::Retail, 100_000_00],
    'dealer' => [PriceLevel::Dealer, 90_000_00],
    'wholesale' => [PriceLevel::Wholesale, 80_000_00],
]);

it('computes the included VAT in integer tiyin', function (int $total, int $vat) {
    expect(Quote::vatOf($total))->toBe($vat);
})->with([
    'exact' => [112_00, 12_00],
    'rounds half up' => [1_00, 11],
    'rounds down' => [10, 1],
    'big amount' => [1_452_000_000, 155_571_429],
    'zero' => [0, 0],
]);

it('stores subtotal, total and VAT from the lines, with an edited price', function () {
    $product = Product::factory()->create(['price' => 1_000_000_00]);

    $quote = newQuote(overrides: ['items' => [
        new QuoteItemData(productId: $product->id, qty: 3, price: 950_000_00),
        new QuoteItemData(name: 'Oʻrnatish va sozlash', qty: 1, price: 450_000_00),
    ]]);

    expect($quote->subtotal)->toBe(3_300_000_00)
        ->and($quote->total)->toBe(3_300_000_00)
        ->and($quote->vat_amount)->toBe(Quote::vatOf(3_300_000_00))
        ->and($quote->vat_amount)->toBe(35_357_143);
});

it('is valid for 5 working days by default, across a weekend', function () {
    // Thursday 22 Oct 2026 → Fri, Mon, Tue, Wed, Thu = 29 Oct.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 15:00', 'Asia/Tashkent'));

    expect(newQuote()->valid_until->toDateString())->toBe('2026-10-29')
        ->and(WorkingDays::add(CarbonImmutable::parse('2026-10-24'), 5)->toDateString())->toBe('2026-10-30')
        ->and(WorkingDays::add(CarbonImmutable::parse('2026-10-23'), 1)->toDateString())->toBe('2026-10-26');
});

it('refuses a validity date in the past', function () {
    newQuote(overrides: ['validUntil' => now()->subDay()->toDateString()]);
})->throws(ValidationException::class);

it('snapshots name, SKU and unit in the quote locale', function () {
    $product = Product::factory()->create(['sku' => 'ZB-ZD421', 'unit' => ProductUnit::Piece, 'name' => ['uz' => 'Printer', 'ru' => 'Принтер']]);
    $customer = Customer::factory()->create(['locale' => 'ru']);

    $quote = newQuote($customer, ['items' => [new QuoteItemData(productId: $product->id)]]);
    $product->update(['sku' => 'CHANGED', 'name' => ['uz' => 'Boshqa', 'ru' => 'Другое'], 'price' => 1]);
    $item = $quote->items()->sole();

    expect($quote->locale)->toBe('ru')
        ->and($item->name)->toBe('Принтер')
        ->and($item->sku)->toBe('ZB-ZD421')
        ->and($item->unit)->toBe(ProductUnit::Piece);
});

it('copies the lead items at level prices when created from a lead', function () {
    $customer = Customer::factory()->priceLevel(PriceLevel::Dealer)->create();
    $lead = Lead::factory()->forCustomer($customer)->create();
    $product = Product::factory()->create(['price' => 200_00, 'price_dealer' => 180_00]);
    LeadItem::factory()->for($lead)->forProduct($product)->create(['qty' => 4]);
    LeadItem::factory()->for($lead)->create(['product_name' => 'Maxsus etiketka', 'qty' => 1000]);

    $quote = newQuote($customer, ['leadId' => $lead->id, 'items' => null]);
    $items = $quote->items()->get();

    expect($quote->lead_id)->toBe($lead->id)
        ->and($items->map(fn ($item) => [$item->name, $item->qty, $item->price])->all())->toBe([
            [$product->getTranslation('name', 'uz'), 4, 180_00],
            ['Maxsus etiketka', 1000, 0],
        ]);
});

it('refuses a lead of another customer', function () {
    newQuote(overrides: ['leadId' => Lead::factory()->forCustomer(Customer::factory()->create())->create()->id]);
})->throws(ValidationException::class);

it('logs status changes on the quote, the customer and the lead', function () {
    $actor = staffUser(Role::SalesManager);
    $customer = Customer::factory()->managedBy($actor)->create();
    $lead = Lead::factory()->forCustomer($customer)->create();
    $quote = newQuote($customer, ['leadId' => $lead->id, 'items' => []], $actor);

    app(ChangeQuoteStatus::class)->markSent($quote, $actor, 'link');
    app(ChangeQuoteStatus::class)->markSent($quote, $actor, 'pdf');
    app(ChangeQuoteStatus::class)->handle($quote, QuoteStatus::Accepted, $actor);

    $actions = fn ($subject) => $subject->activities()->where('type', ActivityType::Quote)->orderBy('id')->get()->map(fn ($a) => $a->meta['action'].(isset($a->meta['via']) ? ':'.$a->meta['via'] : ''))->all();

    expect($quote->fresh())->status->toBe(QuoteStatus::Accepted)->sent_at->not->toBeNull()->accepted_at->not->toBeNull()
        ->and($actions($quote))->toBe(['created', 'sent:link', 'accepted'])
        ->and($actions($customer))->toBe(['created', 'sent:link', 'accepted'])
        ->and($actions($lead))->toBe(['created', 'sent:link', 'accepted'])
        ->and(fn () => app(ChangeQuoteStatus::class)->handle($quote, QuoteStatus::Rejected, $actor))->toThrow(ValidationException::class);
});

it('edits only draft and sent quotes', function () {
    $quote = newQuote();
    $actor = User::factory()->withRole(Role::Admin)->create();

    app(UpdateQuote::class)->handle($quote, new QuoteData(customerId: $quote->customer_id, items: [new QuoteItemData(name: 'Xizmat', price: 10_00)], validUntil: $quote->valid_until->toDateString(), notes: 'Shartlar'));

    expect($quote->fresh())->total->toBe(10_00)->notes->toBe('Shartlar');

    app(ChangeQuoteStatus::class)->handle($quote, QuoteStatus::Rejected, $actor);

    expect(fn () => app(UpdateQuote::class)->handle($quote->fresh(), new QuoteData(customerId: $quote->customer_id, items: [], validUntil: $quote->valid_until->toDateString())))
        ->toThrow(ValidationException::class);
});

it('duplicates a quote as a new draft re-priced from the customer’s current level', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create(['price' => 100_00, 'price_dealer' => 80_00]);
    $quote = newQuote($customer, ['items' => [new QuoteItemData(productId: $product->id, qty: 2, price: 95_00), new QuoteItemData(name: 'Xizmat', price: 5_00)], 'notes' => 'N']);
    app(ChangeQuoteStatus::class)->handle($quote, QuoteStatus::Expired, staffUser());
    $customer->update(['price_level' => PriceLevel::Dealer]);

    $copy = app(DuplicateQuote::class)->handle($quote->fresh(), staffUser());

    expect($copy->id)->not->toBe($quote->id)
        ->and($copy->number)->not->toBe($quote->number)
        ->and($copy->status)->toBe(QuoteStatus::Draft)
        ->and($copy->price_level)->toBe(PriceLevel::Dealer)
        ->and($copy->notes)->toBe('N')
        ->and($copy->items()->get()->map(fn ($i) => [$i->product_id, $i->qty, $i->price])->all())->toBe([[$product->id, 2, 80_00], [null, 1, 5_00]])
        ->and($copy->activities()->sole()->meta)->toMatchArray(['action' => 'created', 'from_number' => $quote->number]);
});

it('keeps a line whose product is in the trash as free text when duplicating', function () {
    $product = Product::factory()->create(['price' => 100_00, 'sku' => 'GONE-1']);
    $quote = newQuote(overrides: ['items' => [new QuoteItemData(productId: $product->id, qty: 3, price: 99_00)]]);
    $product->delete();

    $copy = app(DuplicateQuote::class)->handle($quote, staffUser());
    $line = $copy->items()->sole();

    expect($line->product_id)->toBeNull()
        ->and($line->name)->toBe($quote->items()->sole()->name)
        ->and($line->price)->toBe(99_00)
        ->and($line->qty)->toBe(3);
});

it('refuses prices, quantities and totals beyond the limits instead of clamping', function () {
    $tooHighPrice = fn () => newQuote(overrides: ['items' => [new QuoteItemData(name: 'X', price: SaveQuoteItems::MAX_PRICE + 1)]]);
    $tooManyUnits = fn () => newQuote(overrides: ['items' => [new QuoteItemData(name: 'X', qty: SaveQuoteItems::MAX_QTY + 1, price: 1)]]);
    $tooBigTotal = fn () => newQuote(overrides: ['items' => array_fill(0, 11, new QuoteItemData(name: 'X', qty: SaveQuoteItems::MAX_QTY, price: SaveQuoteItems::MAX_PRICE))]);

    expect(actionErrorsOf($tooHighPrice))->toHaveKey('items.0.price')
        ->and(actionErrorsOf($tooManyUnits))->toHaveKey('items.0.qty')
        ->and(actionErrorsOf($tooBigTotal))->toBe(['items' => [__('crm.quotes.errors.total_too_high')]])
        ->and(Quote::query()->count())->toBe(0)
        ->and(SaveQuoteItems::MAX_TOTAL * 24 * 2)->toBeLessThan(PHP_INT_MAX);

    // Exactly at the limits is fine.
    $max = newQuote(overrides: ['items' => [new QuoteItemData(name: 'X', qty: SaveQuoteItems::MAX_QTY, price: SaveQuoteItems::MAX_PRICE)]]);

    expect($max->total)->toBe(SaveQuoteItems::MAX_TOTAL)
        ->and($max->vat_amount)->toBe(Quote::vatOf(SaveQuoteItems::MAX_TOTAL));
});

it('logs an "updated" entry with the old and new total when a sent quote is edited', function () {
    $actor = staffUser();
    $quote = newQuote(overrides: ['items' => [new QuoteItemData(name: 'X', price: 10_00)]], actor: $actor);
    $data = fn (int $price) => new QuoteData(customerId: $quote->customer_id, items: [new QuoteItemData(name: 'X', price: $price)], validUntil: $quote->valid_until->toDateString());

    app(UpdateQuote::class)->handle($quote, $data(11_00), $actor);
    expect($quote->activities()->where('meta->action', 'updated')->count())->toBe(0);

    app(ChangeQuoteStatus::class)->markSent($quote, $actor, 'manual');
    app(UpdateQuote::class)->handle($quote->fresh(), $data(12_00), $actor);

    expect($quote->activities()->where('meta->action', 'updated')->sole()->meta)->toMatchArray(['old_total' => 11_00, 'new_total' => 12_00]);
});

it('expires sent quotes past their validity every night, with a history entry', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 00:40', 'Asia/Tashkent'));
    $customer = Customer::factory()->create();
    $past = Quote::factory()->sent()->for($customer)->create(['valid_until' => '2026-11-09']);
    $today = Quote::factory()->sent()->create(['valid_until' => '2026-11-10']);
    $draft = Quote::factory()->create(['valid_until' => '2026-11-01']);
    $accepted = Quote::factory()->status(QuoteStatus::Accepted)->create(['valid_until' => '2026-11-01']);

    $this->artisan('quotes:expire')->assertSuccessful();

    expect($past->fresh()->status)->toBe(QuoteStatus::Expired)
        ->and($today->fresh()->status)->toBe(QuoteStatus::Sent)
        ->and($draft->fresh()->status)->toBe(QuoteStatus::Draft)
        ->and($accepted->fresh()->status)->toBe(QuoteStatus::Accepted)
        ->and($past->activities()->sole()->meta)->toMatchArray(['action' => 'expired'])
        ->and($past->activities()->sole()->user_id)->toBeNull()
        ->and($customer->activities()->where('meta->action', 'expired')->count())->toBe(1);

    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'quotes:expire'));

    expect($event)->not->toBeNull()
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('deletes drafts only and keeps a note on the customer', function () {
    $actor = staffUser();
    $draft = newQuote(actor: $actor);
    $sent = newQuote(actor: $actor);
    app(ChangeQuoteStatus::class)->markSent($sent, $actor, 'manual');

    expect(fn () => app(DeleteQuote::class)->handle($sent->fresh(), $actor))->toThrow(ValidationException::class);

    app(DeleteQuote::class)->handle($draft, $actor);

    expect(Quote::query()->find($draft->id))->toBeNull()
        ->and($draft->customer->activities()->where('type', ActivityType::Quote)->pluck('meta')->map(fn ($m) => $m['action'])->all())->toBe(['created', 'deleted']);
});

it('blocks deleting a customer with quotes', function () {
    $quote = newQuote();

    expect(fn () => app(DeleteCustomer::class)->handle($quote->customer))->toThrow(ValidationException::class)
        ->and(Customer::query()->find($quote->customer_id))->not->toBeNull();
});
