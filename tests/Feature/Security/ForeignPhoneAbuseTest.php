<?php

use App\Actions\Customers\SaveCustomer;
use App\Actions\Orders\PlaceOrder;
use App\Data\Customers\CustomerData;
use App\Enums\CustomerType;
use App\Enums\LeadSource;
use App\Enums\Role;
use App\Livewire\Admin\Customers\Form as CustomerFormScreen;
use App\Livewire\Site\Cart\Page as CartPage;
use App\Livewire\Site\Checkout\Page as CheckoutPage;
use App\Livewire\Site\LeadForm;
use App\Models\Cart;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Order;
use App\Support\Cart\CartToken;
use App\Support\Monitoring\MonitoringAlerts;
use App\Support\Monitoring\Redactor;
use App\Support\Phone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\McpExclusionScanner;

/**
 * Plan 14 v2 B1 abuse cases (§19-§21), in-process, fakes only.
 *
 * Already covered elsewhere (not repeated here):
 * - case 8 (PlaceOrder: foreign phone + region / address / Kokand flag): Phones\ForeignPhonesTest "keeps checkout delivery UZ-only";
 *   only the tampered-component angle (unknown delivery type, cap untouched) is added below.
 * - case 11 (assign / ban on another manager's thread): Telegram\TelegramUserPolicyTest ("sales: other manager"); there is no Telegram Livewire screen in B1.
 * - case 15 (drop migration throws with data, permission migration twice keeps manual edits): Telegram\TelegramFoundationTest.
 * - case 10, admin form create path: Telegram\TelegramFoundationTest "ignores a tampered source".
 */
beforeEach(function () {
    Queue::fake();
    RateLimiter::clear('site-foreign-phone');
    Cache::forget('site-foreign-phone:alert');
    config(['site.cart_enabled' => true, 'site.foreign_phone_limit_per_hour' => 30]);
    URL::defaults(['locale' => 'en']);
    app()->setLocale('en');
});

/**
 * Submit one public form (lead form, cart quote or checkout) with a phone,
 * like a visitor would (the fill-time check passes). Returns the component after the call.
 */
function submitPublic(string $entry, string $phone, string $honeypot = ''): Testable
{
    if ($entry !== 'lead') {
        orderCart();
    }

    $component = Livewire::test(match ($entry) {
        'lead' => LeadForm::class,
        'cart' => CartPage::class,
        'checkout' => CheckoutPage::class,
    });

    $component->set('name', 'John Smith')->set('phone', $phone);

    if ($honeypot !== '') {
        $component->set('fax_ext', $honeypot);
    }

    test()->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();

    return $component->call(match ($entry) {
        'lead' => 'submit',
        'cart' => 'requestQuote',
        'checkout' => 'placeOrder',
    });
}

function resetIpLimiters(): void
{
    $ip = sha1('127.0.0.1');

    foreach (["site-lead:{$ip}", "checkout-ip:{$ip}"] as $key) {
        RateLimiter::clear($key);
    }
}

function publicRecords(): int
{
    return Lead::query()->count() + Order::query()->count();
}

// ---------------------------------------------------------------- 1

it('does not let honeypot-filled foreign submissions use the cap, then accepts a real one', function (string $entry) {
    config(['site.foreign_phone_limit_per_hour' => 1]);

    foreach (range(1, 8) as $i) {
        resetIpLimiters();
        submitPublic($entry, '+7 916 000 10 0'.$i, honeypot: 'spam');
    }

    expect(RateLimiter::attempts('site-foreign-phone'))->toBe(0)
        ->and(publicRecords())->toBe(0);

    resetIpLimiters();
    $real = submitPublic($entry, '+7 916 000 20 01');

    expect($real->errors()->has('form'))->toBeFalse()
        ->and(publicRecords())->toBe(1)
        ->and(RateLimiter::attempts('site-foreign-phone'))->toBe(1);
})->with(['lead', 'cart', 'checkout']);

// ---------------------------------------------------------------- 2

it('does not count a foreign submission refused by the per-IP limit', function (string $entry, string $ipKey) {
    foreach (range(1, 12) as $i) {
        RateLimiter::hit($ipKey.sha1('127.0.0.1'), 600);
    }

    $component = submitPublic($entry, '+7 916 000 30 01');

    expect($component->errors()->has('form'))->toBeTrue()
        ->and(RateLimiter::attempts('site-foreign-phone'))->toBe(0)
        ->and(publicRecords())->toBe(0);
})->with([
    'lead form' => ['lead', 'site-lead:'],
    'cart quote (shares the lead budget)' => ['cart', 'site-lead:'],
    'checkout' => ['checkout', 'checkout-ip:'],
]);

// ---------------------------------------------------------------- 3

it('shares one exhausted cap across lead form, cart quote and checkout, and keeps UZ numbers working', function (string $exhaustedBy, string $other) {
    config(['site.foreign_phone_limit_per_hour' => 1]);

    submitPublic($exhaustedBy, '+7 916 000 40 01');
    expect(publicRecords())->toBe(1);

    resetIpLimiters();
    $refused = submitPublic($other, '+7 916 000 40 02');

    expect($refused->errors()->get('form'))->toBe([__('validation.foreign_phone_limit')])
        ->and(__('validation.foreign_phone_limit'))->not->toBe('validation.foreign_phone_limit')
        ->and(publicRecords())->toBe(1);

    resetIpLimiters();
    $uzbek = submitPublic($other, '90 123 45 67');

    expect($uzbek->errors()->has('form'))->toBeFalse()
        ->and(publicRecords())->toBe(2);
})->with([
    'lead blocks cart' => ['lead', 'cart'],
    'lead blocks checkout' => ['lead', 'checkout'],
    'cart blocks lead' => ['cart', 'lead'],
    'checkout blocks cart' => ['checkout', 'cart'],
]);

// ---------------------------------------------------------------- 4

it('sends exactly one management alert however many over-cap requests arrive', function () {
    config(['site.foreign_phone_limit_per_hour' => 1]);
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('M', 35), 'services.telegram.chats.management' => '-1009999999999']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    submitPublic('lead', '+7 916 000 50 01');

    foreach (['lead', 'cart', 'checkout', 'lead', 'cart', 'checkout'] as $i => $entry) {
        resetIpLimiters();
        submitPublic($entry, '+7 916 000 50 1'.$i);
    }

    expect(publicRecords())->toBe(1)
        ->and(mcpAlertTexts())->toHaveCount(1);
});

// ---------------------------------------------------------------- 5

dataset('hostile phones', [
    'Arabic-Indic digits' => ["+\u{0669}\u{0669}\u{0668} 90 123 45 67"],
    'Arabic-Indic digits, no plus' => ["\u{0669}\u{0669}\u{0668}901234567"],
    'full-width plus' => ["\u{FF0B}7 916 123 45 67"],
    'double plus' => ['++7 916 123 45 67'],
    'zero zero zero' => ['+0079 916 123 45 67'],
    'non-geographic +888' => ['+888 1234 5678'],
    '+800 freephone' => ['+800 1234 5678'],
    '5000 chars' => [fn () => '+7'.str_repeat('9', 4998)],
]);

it('rejects hostile phone values on the lead form, cart quote and checkout', function (string|Closure $phone, string $entry) {
    $phone = $phone instanceof Closure ? $phone() : $phone;

    $component = submitPublic($entry, $phone);

    expect($component->errors()->has('phone'))->toBeTrue("{$entry} accepted a hostile phone")
        ->and(publicRecords())->toBe(0)
        ->and(RateLimiter::attempts('site-foreign-phone'))->toBe(0);
})->with('hostile phones')->with(['lead', 'cart', 'checkout']);

// ---------------------------------------------------------------- 6

it('rejects "+90 123 45 67" with the country-code hint and never stores it as +90', function (string $entry) {
    $component = submitPublic($entry, '+90 123 45 67');

    $message = $component->errors()->first('phone');

    expect(Phone::normalizeAny('+90 123 45 67'))->toBeNull()
        ->and($message)->toContain('country code')
        ->and(publicRecords())->toBe(0)
        ->and(Lead::query()->where('phone', 'like', '+90%')->exists())->toBeFalse()
        ->and(Order::query()->where('phone', 'like', '+90%')->exists())->toBeFalse();
})->with(['lead', 'cart', 'checkout']);

// ---------------------------------------------------------------- 7

it('rejects a foreign phone sent straight to PlaceOrder when the kill switch is off', function () {
    config(['site.foreign_phones_enabled' => false]);
    $cart = orderCart();

    try {
        app(PlaceOrder::class)->handle($cart->token, checkoutData($cart, ['phone' => '+7 916 123 45 67']));
        $this->fail('PlaceOrder accepted a foreign phone with the kill switch off');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('phone');
    }

    expect(Order::query()->count())->toBe(0);

    $order = app(PlaceOrder::class)->handle($cart->token, checkoutData($cart, ['phone' => '90 123 45 67']));
    expect($order->phone)->toBe('+998901234567');
});

// ---------------------------------------------------------------- 8 (extra angle)

it('refuses a foreign phone with a tampered region or Kokand flag, and leaves the cap alone', function (array $tampered) {
    $cart = orderCart();

    expect(fn () => app(PlaceOrder::class)->handle($cart->token, checkoutData($cart, array_merge(['phone' => '+44 20 7946 0958'], $tampered))))
        ->toThrow(ValidationException::class);

    expect(Order::query()->count())->toBe(0);
})->with([
    'foreign region on a region courier' => [['deliveryType' => 'courier_region', 'region' => 'london', 'address' => 'Baker St 221']],
    'kokand without confirmation' => [['deliveryType' => 'courier_kokand', 'address' => 'Kokand 1', 'kokandConfirmed' => false]],
]);

it('refuses tampered checkout properties through the component and does not spend the cap', function () {
    orderCart();
    $component = Livewire::test(CheckoutPage::class)->set('name', 'John Smith')->set('phone', '+44 20 7946 0958')
        ->set('deliveryType', 'international_courier')->set('region', 'london')->set('kokandConfirmed', true);
    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $component->call('placeOrder');

    expect(Order::query()->count())->toBe(0)
        ->and(RateLimiter::attempts('site-foreign-phone'))->toBe(0);
});

// ---------------------------------------------------------------- 9

it('matches an existing contact for a foreign site lead without touching customers.source', function (?LeadSource $source) {
    $customer = Customer::factory()->create();
    $customer->forceFill(['source' => $source])->save();
    Contact::factory()->for($customer)->create(['phone' => '+79161234567']);

    submitPublic('lead', '+7 916 123-45-67');

    $lead = Lead::query()->sole();

    expect($lead->customer_id)->toBe($customer->id)
        ->and($customer->fresh()->source)->toBe($source);
})->with([null, LeadSource::Telegram]);

// ---------------------------------------------------------------- 10

it('ignores a tampered source on an existing customer in the admin form', function () {
    $sales = staffUser(Role::SalesManager);
    $customer = Customer::factory()->create(['manager_id' => $sales->id]);
    $customer->forceFill(['source' => LeadSource::Telegram])->save();

    Livewire::actingAs($sales)->test(CustomerFormScreen::class, ['customer' => $customer])
        ->set('form.source', 'manual')
        ->set('form.name', 'Renamed LLC')
        ->call('save')
        ->assertHasNoErrors();

    expect($customer->fresh()->name)->toBe('Renamed LLC')
        ->and($customer->fresh()->source)->toBe(LeadSource::Telegram);
});

it('has no source input in SaveCustomer', function () {
    $admin = staffUser();

    expect(fn () => new CustomerData(type: CustomerType::Company, name: 'X', source: 'telegram'))->toThrow(Error::class);

    $customer = app(SaveCustomer::class)->handle(null, new CustomerData(type: CustomerType::Company, name: 'ACME'), $admin);
    expect($customer->fresh()->source)->toBeNull();

    $customer->forceFill(['source' => LeadSource::Telegram])->save();
    app(SaveCustomer::class)->handle($customer, new CustomerData(type: CustomerType::Company, name: 'ACME 2'), $admin);

    expect($customer->fresh()->source)->toBe(LeadSource::Telegram);
});

// ---------------------------------------------------------------- 12

/**
 * The shared scanner of McpTelegramExclusionTest, applied to a fixture string (no drift possible).
 *
 * @return list<string> labels of the needles that match
 */
function mcpScanLabels(string $code): array
{
    return McpExclusionScanner::labels($code, 'app/Mcp/Tools/Fixture.php');
}

it('flags a hypothetical MCP tool that reads Telegram, customer or lead message data', function (string $code, string $label) {
    expect(mcpScanLabels($code))->toContain($label);
})->with([
    'TelegramUser table' => ['<?php DB::table("telegram_users")->get();', 'telegram_'],
    'contacts table' => ['<?php DB::table("contacts")->pluck("phone");', 'contacts'],
    'customers table' => ['<?php Customer::query(); DB::table("customers")->get();', 'customers'],
    'lead message column' => ['<?php Lead::query()->value("leads.message");', 'leads.message'],
    'message property' => ['<?php return $lead->message;', 'message key/property'],
    'message key' => ['<?php return ["message" => $row];', 'message key/property'],
    'raw table access' => ['<?php return DB::table("orders")->count();', 'CRM table name'],
    'qualified column' => ['<?php return Order::query()->where("orders.status", 1);', 'CRM table name'],
    'lead customer relation' => ['<?php return $order->customer;', 'customer/contact relation'],
]);

it('lets a clean MCP tool file through the scan', function () {
    expect(mcpScanLabels('<?php return Product::query()->where("sku", $sku)->first();'))->toBe([]);
});

it('also fails the whole-file scan for a temp tool file, and cleans it up', function () {
    $dir = base_path('storage/framework/testing');
    is_dir($dir) || mkdir($dir, 0775, true);
    $file = tempnam($dir, 'mcp-tool-');

    try {
        file_put_contents($file, '<?php use App\Models\TelegramUser; class T { function h() { return TelegramUser::query()->get()->pluck("message"); } }');

        expect(mcpScanLabels((string) file_get_contents($file)))->toContain('message key/property');
    } finally {
        @unlink($file);
    }

    expect(file_exists($file))->toBeFalse();
});

// ---------------------------------------------------------------- 13

it('leaves no phone digits in redacted text or monitoring alerts', function (string $phone, array $fragments) {
    $out = Redactor::clean("Order failed for {$phone} and retry");

    foreach ($fragments as $fragment) {
        expect($out)->not->toContain($fragment);
    }
})->with([
    'ru' => ['+7 916 123-45-67', ['916', '123-45', '45-67', '1234567']],
    'uk' => ['+44 20 7946 0958', ['7946', '0958', '20 79']],
]);

it('leaves no phone digits of a 00-prefixed number in redacted text', function () {
    expect(Redactor::clean('Order failed for 00 7 916 1234567 and retry'))->not->toContain('916')->not->toContain('1234567');
});

it('leaves no phone digits in a sent monitoring alert', function (string $phone, array $fragments) {
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('M', 35), 'services.telegram.chats.management' => '-1009999999999']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    MonitoringAlerts::problem('task_failed', ['task' => 'orders:notify', 'error' => "Failed for {$phone}"]);

    $texts = mcpAlertTexts();

    expect($texts)->toHaveCount(1);

    foreach ($fragments as $fragment) {
        expect($texts[0])->not->toContain($fragment);
    }
})->with([
    'ru' => ['+7 916 123-45-67', ['916', '123-45', '1234567']],
    'uk' => ['+44 20 7946 0958', ['7946', '0958']],
]);

it('leaves no phone digits of a 00-prefixed number in a sent monitoring alert', function () {
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('M', 35), 'services.telegram.chats.management' => '-1009999999999']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    MonitoringAlerts::problem('task_failed', ['task' => 'orders:notify', 'error' => 'Failed for 00 7 916 1234567']);

    $texts = mcpAlertTexts();

    expect($texts)->toHaveCount(1)
        ->and($texts[0])->not->toContain('916')->not->toContain('1234567');
});

// ---------------------------------------------------------------- 14

it('prints no phone digits from bad rows in phones:check-normalized, in any table', function () {
    $customer = Customer::factory()->create();
    $lead = Lead::factory()->create();
    $order = Order::factory()->create();

    DB::table('customers')->where('id', $customer->id)->update(['phone' => '8 916 123-45-67']);
    DB::table('leads')->where('id', $lead->id)->update(['phone' => '00 7 916 5554433']);
    DB::table('orders')->where('id', $order->id)->update(['phone' => '+44 20 7946 0958 ext 9']);

    $this->artisan('phones:check-normalized')
        ->doesntExpectOutputToContain('916')
        ->doesntExpectOutputToContain('7946')
        ->doesntExpectOutputToContain('5554433')
        ->doesntExpectOutputToContain('123-45')
        ->assertFailed();
});

it('does not count a cart without quotable lines toward the foreign-phone cap', function () {
    config(['site.foreign_phone_limit_per_hour' => 5]);
    Cart::factory()->create();
    $cart = Cart::factory()->create();
    app(CartToken::class)->issue($cart->token);

    $component = Livewire::test(CartPage::class)->set('name', 'John')->set('phone', '+7 916 123 45 67');
    $this->travel(LeadForm::MIN_FILL_SECONDS + 1)->seconds();
    $component->call('requestQuote')->assertHasErrors(['form']);

    expect(RateLimiter::attempts('site-foreign-phone'))->toBe(0)
        ->and(Lead::query()->count())->toBe(0);
});
