<?php

use App\Enums\PriceLevel;
use App\Enums\QuoteStatus;
use App\Enums\Role;
use App\Livewire\Admin\Customers\Show as CustomerShow;
use App\Livewire\Admin\Leads\Show as LeadShow;
use App\Livewire\Admin\Quotes\Form;
use App\Livewire\Admin\Quotes\Index;
use App\Livewire\Admin\Quotes\Show;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use Livewire\Livewire;

it('opens the quote screens per role', function (Role $role, int $index, int $create) {
    $user = staffUser($role);

    $this->actingAs($user)->get(route('admin.quotes.index'))->assertStatus($index);
    $this->actingAs($user)->get(route('admin.quotes.create'))->assertStatus($create);
})->with([
    'admin' => [Role::Admin, 200, 200],
    'sales manager' => [Role::SalesManager, 200, 200],
    'service engineer' => [Role::ServiceEngineer, 403, 403],
    'content manager' => [Role::ContentManager, 403, 403],
]);

it('shows a quote to its manager, the customer manager and the admin only', function (string $who, int $status) {
    $sales = staffUser(Role::SalesManager);
    $colleague = User::factory()->withRole(Role::SalesManager)->create();
    $quote = Quote::factory()->managedBy($colleague)->for(Customer::factory()->managedBy($colleague))->create();

    $user = match ($who) {
        'admin' => User::factory()->withRole(Role::Admin)->create(),
        'quote manager' => $colleague,
        'customer manager' => tap($sales, fn () => $quote->customer->update(['manager_id' => $sales->id])),
        'other sales manager' => $sales,
        'service engineer' => User::factory()->withRole(Role::ServiceEngineer)->create(),
    };

    $this->actingAs($user)->get(route('admin.quotes.show', $quote))->assertStatus($status);
    $this->actingAs($user)->get(route('admin.quotes.pdf', $quote))->assertStatus($status);
})->with([
    'admin' => ['admin', 200],
    'quote manager' => ['quote manager', 200],
    'customer manager' => ['customer manager', 200],
    'other sales manager' => ['other sales manager', 403],
    'service engineer' => ['service engineer', 403],
]);

it('lists only visible quotes and searches by number or customer', function () {
    $sales = staffUser(Role::SalesManager);
    $mine = Quote::factory()->managedBy($sales)->for(Customer::factory()->state(['name' => 'Alpha Trade']))->create(['number' => 'Q-2026-0101']);
    $theirs = Quote::factory()->for(Customer::factory()->managedBy($sales)->state(['name' => 'Beta Savdo']))->create(['number' => 'Q-2026-0102']);
    $hidden = Quote::factory()->managedBy(User::factory()->withRole(Role::SalesManager)->create())->create(['number' => 'Q-2026-0103']);

    Livewire::actingAs($sales)->test(Index::class)
        ->assertSee($mine->number)
        ->assertSee($theirs->number)
        ->assertDontSee($hidden->number)
        ->set('search', 'beta')
        ->assertSee($theirs->number)
        ->assertDontSee($mine->number)
        ->set('search', '0101')
        ->assertSee($mine->number);
});

it('filters by status, manager, customer and date', function () {
    $admin = staffUser();
    $sales = User::factory()->withRole(Role::SalesManager)->create();
    $a = Quote::factory()->managedBy($sales)->sent()->create(['created_at' => now()->subDays(10)]);
    $b = Quote::factory()->managedBy($admin)->create();

    $see = function (array $set) use ($admin, $a, $b): array {
        $component = Livewire::actingAs($admin)->test(Index::class);

        foreach ($set as $property => $value) {
            $component->set($property, $value);
        }

        return array_values(array_filter([$a->number, $b->number], fn (string $number): bool => str_contains($component->html(), $number)));
    };

    expect($see(['status' => 'sent']))->toBe([$a->number])
        ->and($see(['manager' => 'me']))->toBe([$b->number])
        ->and($see(['manager' => (string) $sales->id]))->toBe([$a->number])
        ->and($see(['customer' => (string) $b->customer_id]))->toBe([$b->number])
        ->and($see(['from' => now()->subDays(2)->format('Y-m-d')]))->toBe([$b->number]);
});

it('lists quotes without N+1 queries', function () {
    $admin = staffUser();
    $render = fn () => Livewire::actingAs($admin)->test(Index::class);

    Quote::factory()->managedBy($admin)->count(3)->create();
    $render();
    $few = countQueries($render);

    Quote::factory()->managedBy(User::factory()->withRole(Role::SalesManager)->create())->count(12)->create();
    $render();

    expect(countQueries($render))->toBe($few);
});

it('creates a quote from a lead with the customer level prices, a live VAT total and a free line', function () {
    $sales = staffUser(Role::SalesManager);
    $customer = Customer::factory()->managedBy($sales)->priceLevel(PriceLevel::Wholesale)->create();
    $lead = Lead::factory()->assignedTo($sales)->forCustomer($customer)->create();
    $product = Product::factory()->create(['sku' => 'ZB-ZD421', 'price' => 1_000_000_00, 'price_wholesale' => 900_000_00]);
    LeadItem::factory()->for($lead)->forProduct($product)->create(['qty' => 2]);

    $component = Livewire::actingAs($sales)->withQueryParams(['lead' => $lead->id])->test(Form::class)
        ->assertSet('form.customer_id', (string) $customer->id)
        ->assertSet('form.lead_id', (string) $lead->id)
        ->assertSet('form.items.0.price', '900 000')
        ->assertSee(str_replace(' ', "\u{00A0}", '1 800 000'))
        ->call('addLine')
        ->set('form.items.1.name', 'Oʻrnatish')
        ->set('form.items.1.price', '200 000')
        ->assertSee(str_replace(' ', "\u{00A0}", '2 000 000'))
        ->assertSee(str_replace(' ', "\u{00A0}", '214 285,71'))
        ->call('save')
        ->assertHasNoErrors();

    $quote = Quote::query()->with('items')->sole();

    expect($quote->customer_id)->toBe($customer->id)
        ->and($quote->lead_id)->toBe($lead->id)
        ->and($quote->manager_id)->toBe($sales->id)
        ->and($quote->total)->toBe(2_000_000_00)
        ->and($quote->vat_amount)->toBe(21_428_571)
        ->and($quote->items->pluck('sku')->all())->toBe(['ZB-ZD421', null]);
    $component->assertRedirect(route('admin.quotes.show', $quote));
});

it('shows the level price in the product picker', function () {
    $admin = staffUser();
    $customer = Customer::factory()->priceLevel(PriceLevel::Dealer)->create();
    Product::factory()->create(['sku' => 'PICK-1', 'price' => 500_00, 'price_dealer' => 450_00]);

    Livewire::actingAs($admin)->withQueryParams(['customer' => $customer->id])->test(Form::class)
        ->set('productSearch', 'pick-1')
        ->assertSeeHtml('data-level-price="45000"');
});

it('rejects tampered lines', function () {
    $admin = staffUser();
    $customer = Customer::factory()->create();

    Livewire::actingAs($admin)->withQueryParams(['customer' => $customer->id])->test(Form::class)
        ->set('form.items', [['id' => null, 'product_id' => null, 'name' => '', 'qty' => 1, 'price' => '1', 'discount' => 5]])
        ->call('save')
        ->assertHasErrors(['form.items.0']);

    expect(Quote::query()->count())->toBe(0);
});

it('marks a draft sent when the link is copied or the PDF downloaded', function () {
    $admin = staffUser();
    $quote = Quote::factory()->managedBy($admin)->has(QuoteItem::factory(), 'items')->create();

    $url = Livewire::actingAs($admin)->test(Show::class, ['quote' => $quote])->call('copyLink')->effects['returns'][0] ?? null;

    expect($quote->fresh()->status)->toBe(QuoteStatus::Sent)
        ->and($url)->toBe(route('quotes.public', $quote->ulid));

    $draft = Quote::factory()->managedBy($admin)->has(QuoteItem::factory(), 'items')->create();

    $this->actingAs($admin)->get(route('admin.quotes.pdf', $draft))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($draft->fresh()->status)->toBe(QuoteStatus::Sent)
        ->and($draft->activities()->latest('id')->first()->meta)->toMatchArray(['action' => 'sent', 'via' => 'pdf']);
});

it('changes the status, duplicates and deletes from the quote page', function () {
    $sales = staffUser(Role::SalesManager);
    $quote = Quote::factory()->managedBy($sales)->create();

    Livewire::actingAs($sales)->test(Show::class, ['quote' => $quote])
        ->assertSeeHtml('data-status-to="sent"')
        ->call('markSent')
        ->call('changeStatus', 'accepted')
        ->assertDispatched('toast');

    expect($quote->fresh()->status)->toBe(QuoteStatus::Accepted);

    Livewire::actingAs($sales)->test(Show::class, ['quote' => $quote->fresh()])
        ->assertDontSeeHtml('data-action="delete"')
        ->call('delete')
        ->assertForbidden();

    $draft = Quote::factory()->managedBy($sales)->create();

    Livewire::actingAs($sales)->test(Show::class, ['quote' => $draft])->call('duplicate')->assertRedirect();
    Livewire::actingAs($sales)->test(Show::class, ['quote' => $draft])->call('delete')->assertRedirect(route('admin.quotes.index'));

    expect(Quote::query()->find($draft->id))->toBeNull()
        ->and(Quote::query()->count())->toBe(2);
});

it('forbids another sales manager to change a quote', function () {
    staffUser(Role::SalesManager);
    $quote = Quote::factory()->managedBy(User::factory()->withRole(Role::SalesManager)->create())->create();
    $intruder = User::factory()->withRole(Role::SalesManager)->create();

    Livewire::actingAs($intruder)->test(Show::class, ['quote' => $quote])->assertForbidden();
    Livewire::actingAs($intruder)->test(Form::class, ['quote' => $quote])->assertForbidden();
});

it('shows the quote page without N+1 queries', function () {
    $admin = staffUser();
    $quote = Quote::factory()->managedBy($admin)->create();
    $render = fn () => Livewire::actingAs($admin)->test(Show::class, ['quote' => $quote]);

    QuoteItem::factory()->for($quote)->forProduct(Product::factory()->create())->count(2)->create();
    $render();
    $few = countQueries($render);

    QuoteItem::factory()->for($quote)->forProduct(Product::factory()->create())->count(8)->create();
    $render();

    expect(countQueries($render))->toBe($few);
});

it('offers "create quote" on a lead with a customer and "convert first" otherwise', function () {
    $sales = staffUser(Role::SalesManager);
    $withCustomer = Lead::factory()->assignedTo($sales)->forCustomer(Customer::factory()->create())->create();
    $without = Lead::factory()->assignedTo($sales)->create();

    Livewire::actingAs($sales)->test(LeadShow::class, ['lead' => $withCustomer])
        ->assertSeeHtml('href="'.route('admin.quotes.create', ['lead' => $withCustomer->id]).'"');

    Livewire::actingAs($sales)->test(LeadShow::class, ['lead' => $without])
        ->assertSeeHtml('data-convert-first')
        ->assertDontSeeHtml('data-action="create-quote"');
});

it('lists the visible quotes on the customer page', function () {
    $sales = staffUser(Role::SalesManager);
    $customer = Customer::factory()->managedBy($sales)->create();
    $quote = Quote::factory()->for($customer)->create();

    Livewire::actingAs($sales)->test(CustomerShow::class, ['customer' => $customer])
        ->set('tab', 'quotes')
        ->assertSee($quote->number)
        ->assertSeeHtml('href="'.route('admin.quotes.create', ['customer' => $customer->id]).'"');
});

it('uses the level price when a product line price is left blank', function () {
    $admin = staffUser();
    $customer = Customer::factory()->priceLevel(PriceLevel::Dealer)->create();
    $product = Product::factory()->create(['price' => 500_00, 'price_dealer' => 450_00]);

    Livewire::actingAs($admin)->withQueryParams(['customer' => $customer->id])->test(Form::class)
        ->call('addProduct', $product->id)
        ->set('form.items.0.price', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(Quote::query()->sole()->items()->sole()->price)->toBe(450_00);
});

it('gives a sales manager 403 on another manager’s quote PDF', function () {
    $sales = staffUser(Role::SalesManager);
    $quote = Quote::factory()->sent()->managedBy(User::factory()->withRole(Role::SalesManager)->create())->create();

    $this->actingAs($sales)->get(route('admin.quotes.pdf', $quote))->assertForbidden();

    expect($quote->activities()->count())->toBe(0);
});
