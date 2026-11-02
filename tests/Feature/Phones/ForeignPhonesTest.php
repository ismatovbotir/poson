<?php

use App\Actions\Customers\SaveCustomer;
use App\Actions\Leads\CreateLead;
use App\Actions\Orders\PlaceOrder;
use App\Data\Customers\CustomerData;
use App\Data\Leads\LeadData;
use App\Enums\CustomerType;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Livewire\Site\Cart\Page as CartPage;
use App\Livewire\Site\Checkout\Page as CheckoutPage;
use App\Livewire\Site\LeadForm;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Order;
use App\Rules\PhoneNumber;
use App\Support\Telegram\LeadMessage;
use App\Support\Telegram\OrderMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Plan 14 v2 §20 (TG-40..48): phones of any country everywhere.
 */
beforeEach(function () {
    Queue::fake();
    RateLimiter::clear('site-foreign-phone');
    Cache::forget('site-foreign-phone:alert');
    config(['site.cart_enabled' => true]);
    URL::defaults(['locale' => 'en']);
});

function siteLeadWith(string $phone): Testable
{
    $component = Livewire::test(LeadForm::class)->set('name', 'John')->set('phone', $phone);
    test()->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();

    return $component;
}

it('validates with PhoneNumber: UZ forms, foreign numbers, reserved codes, ambiguous input', function (mixed $value, bool $passes) {
    expect(Validator::make(['phone' => $value], ['phone' => [new PhoneNumber]])->passes())->toBe($passes);
})->with([
    ['90 123 45 67', true],
    ['+7 916 123-45-67', true],
    ['+44 20 7946 0958', true],
    ['8 916 1234567', false],
    [12345, false],
    ['+800 1234 5678', false],
    ['+808 1234 5678', false],
    ['+870 1234 5678', false],
    ['+878 1234 5678', false],
    ['+881 1234 5678', false],
    ['+882 1234 5678', false],
    ['+883 1234 5678', false],
    ['+888 1234 5678', false],
    ['+979 1234 5678', false],
    ['+991 1234 5678', false],
]);

it('asks for the country code in every locale', function (string $locale) {
    app()->setLocale($locale);

    $message = Validator::make(['phone' => '8 916 1234567'], ['phone' => [new PhoneNumber]])->errors()->first('phone');

    expect($message)->not->toBe('validation.phone_any')->toContain('+7');
})->with(['uz', 'ru', 'en']);

it('accepts a foreign number on the lead form and matches an existing CRM contact', function () {
    $customer = Customer::factory()->create();
    Contact::factory()->for($customer)->create(['phone' => '+79161234567']);

    siteLeadWith('+7 916 123-45-67')->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    $lead = Lead::query()->sole();

    expect($lead->phone)->toBe('+79161234567')
        ->and($lead->customer_id)->toBe($customer->id);
});

it('keeps accepting Uzbek numbers and ignores UZ numbers in the foreign cap', function () {
    siteLeadWith('90 123 45 67')->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    expect(Lead::query()->sole()->phone)->toBe('+998901234567')
        ->and(RateLimiter::attempts('site-foreign-phone'))->toBe(0);
});

it('accepts a foreign number in the cart quote', function () {
    orderCart();
    $component = Livewire::test(CartPage::class)->set('name', 'John')->set('phone', '+44 20 7946 0958');
    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $component->call('requestQuote')->assertHasNoErrors()->assertSet('sent', true);

    expect(Lead::query()->sole()->phone)->toBe('+442079460958');
});

it('places a checkout order with a foreign phone and matches the CRM contact', function () {
    app()->setLocale('en');
    $customer = Customer::factory()->create();
    Contact::factory()->for($customer)->create(['phone' => '+79161234567']);
    orderCart();

    $component = Livewire::test(CheckoutPage::class)->set('name', 'John Smith')->set('phone', '+7 916 123 45 67');
    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $component->call('placeOrder')->assertHasNoErrors();

    $order = Order::query()->sole();

    expect($order->phone)->toBe('+79161234567')
        ->and(Contact::query()->matchingPhone($order->phone)->first()?->customer_id)->toBe($customer->id);
});

it('keeps checkout delivery UZ-only for a foreign phone (TG-48)', function (array $overrides) {
    $cart = orderCart();

    expect(fn () => app(PlaceOrder::class)->handle($cart->token, checkoutData($cart, array_merge(['phone' => '+7 916 123 45 67'], $overrides))))
        ->toThrow(ValidationException::class);

    expect(Order::query()->count())->toBe(0);
})->with([
    'courier region without region' => [['deliveryType' => 'courier_region', 'region' => '', 'address' => 'Somewhere 1', 'paymentMethod' => 'on_receipt']],
    'invalid region' => [['deliveryType' => 'courier_region', 'region' => 'moscow', 'address' => 'Somewhere 1', 'paymentMethod' => 'on_receipt']],
    'courier without address' => [['deliveryType' => 'courier_kokand', 'address' => '', 'kokandConfirmed' => true]],
    'kokand not confirmed' => [['deliveryType' => 'courier_kokand', 'address' => 'Kokand 1', 'kokandConfirmed' => false]],
]);

it('refuses a non-UZ number after the global hourly cap, with one alert, and still takes UZ numbers', function () {
    config(['site.foreign_phone_limit_per_hour' => 2]);
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('M', 35), 'services.telegram.chats.management' => '-1009999999999']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    siteLeadWith('+7 916 000 00 01')->call('submit')->assertHasNoErrors()->assertSet('sent', true);
    siteLeadWith('+7 916 000 00 02')->call('submit')->assertHasNoErrors()->assertSet('sent', true);
    siteLeadWith('+7 916 000 00 03')->call('submit')->assertHasErrors(['form'])->assertSet('sent', false);
    siteLeadWith('+7 916 000 00 04')->call('submit')->assertHasErrors(['form']);

    expect(Lead::query()->count())->toBe(2)
        ->and(mcpAlertTexts())->toHaveCount(1);

    siteLeadWith('90 123 45 67')->call('submit')->assertHasNoErrors()->assertSet('sent', true);
    expect(Lead::query()->count())->toBe(3);
});

it('shares the foreign cap between the lead form and checkout', function () {
    config(['site.foreign_phone_limit_per_hour' => 1]);
    app()->setLocale('en');

    siteLeadWith('+7 916 000 00 01')->call('submit')->assertHasNoErrors();
    orderCart();
    $checkout = Livewire::test(CheckoutPage::class)->set('name', 'John Smith')->set('phone', '+7 916 000 00 02');
    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $checkout->call('placeOrder')->assertHasErrors(['form']);

    expect(Order::query()->count())->toBe(0);
});

it('turns foreign phones off on public entry points only (kill switch)', function () {
    config(['site.foreign_phones_enabled' => false]);
    app()->setLocale('en');

    siteLeadWith('+7 916 123 45 67')->call('submit')->assertHasErrors(['phone'])->assertSet('sent', false);

    orderCart();
    $checkout = Livewire::test(CheckoutPage::class)->set('name', 'John Smith')->set('phone', '+7 916 123 45 67');
    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $checkout->call('placeOrder')->assertHasErrors(['phone']);

    siteLeadWith('90 123 45 67')->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    // The admin CRM is unaffected.
    $lead = app(CreateLead::class)->handle(new LeadData(source: LeadSource::Manual, type: LeadType::Callback, name: 'Admin entry', phone: '+7 916 123 45 67', locale: 'uz'));
    expect($lead->phone)->toBe('+79161234567');

    config(['site.foreign_phones_enabled' => true]);
    siteLeadWith('+44 20 7946 0958')->call('submit')->assertHasNoErrors()->assertSet('sent', true);
});

it('stores foreign numbers in the admin customer action and rejects ambiguous ones', function () {
    $admin = staffUser();
    $customer = app(SaveCustomer::class)->handle(null, new CustomerData(type: CustomerType::Company, name: 'ACME', phone: '+44 20 7946 0958'), $admin);

    expect($customer->phone)->toBe('+442079460958')
        ->and(Customer::query()->matchingPhone('0044 20 7946 0958')->count())->toBe(1);

    expect(fn () => app(SaveCustomer::class)->handle(null, new CustomerData(type: CustomerType::Company, name: 'Bad', phone: '8 916 1234567'), $admin))
        ->toThrow(ValidationException::class);
});

it('marks non-UZ numbers in Telegram lead and order alerts only', function () {
    $foreign = Lead::factory()->create(['phone' => '+79161234567']);
    $uzbek = Lead::factory()->create(['phone' => '+998901234567']);
    $marker = __('telegram.lead.foreign_phone', [], 'en');

    expect(LeadMessage::for($foreign, 'en')->html)->toContain($marker)
        ->and(LeadMessage::for($uzbek, 'en')->html)->not->toContain($marker);

    $foreignOrder = Order::factory()->create(['phone' => '+79161234567']);
    $uzbekOrder = Order::factory()->create(['phone' => '+998901234567']);

    expect(OrderMessage::placed($foreignOrder, 'en')->html)->toContain(__('telegram.order.foreign_phone', [], 'en'))
        ->and(OrderMessage::placed($uzbekOrder, 'en')->html)->not->toContain(__('telegram.order.foreign_phone', [], 'en'));
});

it('marks non-UZ numbers on the admin lead, order and customer screens', function () {
    $admin = staffUser();
    $lead = Lead::factory()->create(['phone' => '+79161234567']);
    $order = Order::factory()->create(['phone' => '+79161234567']);
    $customer = Customer::factory()->create(['phone' => '+79161234567']);
    $uzLead = Lead::factory()->create(['phone' => '+998901234567']);
    $marker = __('admin.common.foreign_phone');

    foreach ([route('admin.leads.show', $lead), route('admin.orders.show', $order), route('admin.customers.show', $customer)] as $url) {
        $this->actingAs($admin)->get($url)->assertOk()->assertSee($marker);
    }

    $this->actingAs($admin)->get(route('admin.leads.show', $uzLead))->assertOk()->assertDontSee($marker);
});

it('has the same foreign-phone texts in every locale', function (string $locale) {
    foreach (['telegram.lead.foreign_phone', 'telegram.order.foreign_phone', 'admin.common.foreign_phone', 'validation.phone_any', 'validation.foreign_phone_limit', 'site.lead_form.phone_hint', 'telegram.monitoring.events.foreign_phone_cap', 'telegram.monitoring.labels.limit'] as $key) {
        expect(__($key, [], $locale))->not->toBe($key);
    }
})->with(['uz', 'ru', 'en']);

it('checks stored phones read-only with phones:check-normalized', function () {
    Customer::factory()->create(['phone' => '+998901234567']);
    Customer::factory()->create(['phone' => '+79161234567']);

    $this->artisan('phones:check-normalized')->assertSuccessful();

    $dirty = Customer::factory()->create();
    DB::table('customers')->where('id', $dirty->id)->update(['phone' => '90 123 45 67']);

    $this->artisan('phones:check-normalized')
        ->expectsOutputToContain('ids: '.$dirty->id)
        ->doesntExpectOutputToContain('90 123 45 67')
        ->assertFailed();

    expect(DB::table('customers')->where('id', $dirty->id)->value('phone'))->toBe('90 123 45 67');
});

it('does not spend the foreign cap on bots (honeypot) before a real submission', function () {
    config(['site.foreign_phone_limit_per_hour' => 2]);

    foreach (range(1, 4) as $i) {
        siteLeadWith('+7 916 000 10 0'.$i)->set('fax_ext', 'spam')->call('submit');
    }

    expect(RateLimiter::attempts('site-foreign-phone'))->toBe(0);

    siteLeadWith('+7 916 000 20 01')->call('submit')->assertHasNoErrors()->assertSet('sent', true);

    expect(Lead::query()->count())->toBe(1)
        ->and(RateLimiter::attempts('site-foreign-phone'))->toBe(1);
});

it('does not spend the foreign cap when the IP or phone limiter refuses first', function () {
    for ($i = 0; $i < LeadForm::RATE_LIMIT + 1; $i++) {
        RateLimiter::hit('site-lead:'.sha1('127.0.0.1'), LeadForm::RATE_LIMIT_SECONDS);
    }

    siteLeadWith('+7 916 000 30 01')->call('submit')->assertHasErrors(['form'])->assertSet('sent', false);

    expect(RateLimiter::attempts('site-foreign-phone'))->toBe(0);
});

it('counts a foreign checkout only after the order was placed', function () {
    app()->setLocale('en');
    orderCart();

    $checkout = Livewire::test(CheckoutPage::class)->set('name', 'John Smith')->set('phone', '+7 916 123 45 67')
        ->set('deliveryType', 'courier_region')->set('region', '')->set('address', 'Somewhere 1');
    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $checkout->call('placeOrder');

    expect(Order::query()->count())->toBe(0)
        ->and(RateLimiter::attempts('site-foreign-phone'))->toBe(0);

    $checkout->set('deliveryType', 'pickup')->call('placeOrder')->assertHasNoErrors();

    expect(Order::query()->count())->toBe(1)
        ->and(RateLimiter::attempts('site-foreign-phone'))->toBe(1);
});
