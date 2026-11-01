<?php

use App\Actions\Orders\PlaceOrder;
use App\Actions\Settings\UpdateUzqrImage;
use App\Data\Orders\CheckoutData;
use App\Data\Orders\ManualPaymentData;
use App\Enums\DeliveryType;
use App\Enums\Region;
use App\Enums\Role;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Cart\CartPricer;
use App\Support\Cart\CartToken;
use App\Support\CompanySettings;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Orders\CheckoutSettings;
use App\Support\Orders\DeliveryQuote;
use App\Support\Secrets;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoProductsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the application and refresh the database.
| Unit tests boot the application too (for config, translations and the
| container) but never touch the database.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Brand rule (CLAUDE.md): no discount, sale or low-price wording anywhere.
 * The patterns live in config/brand.php (shared with the BrandSafeText rule).
 *
 * @return list<string>
 */
function bannedWordingPatterns(): array
{
    return config('brand.banned_patterns');
}

/**
 * Assert the HTML contains none of the banned wording (case-insensitive).
 */
function expectNoBannedWording(string $html): void
{
    $html = mb_strtolower($html);

    foreach (bannedWordingPatterns() as $pattern) {
        expect($html)->not->toMatch($pattern);
    }
}

/**
 * A staff user with the real role permissions (seeds RolesAndPermissionsSeeder).
 */
function staffUser(Role $role = Role::Admin): User
{
    test()->seed(RolesAndPermissionsSeeder::class);

    return User::factory()->withRole($role)->create();
}

/**
 * Roles that may only view the catalog (no writes).
 *
 * @return array<string, array{0: Role}>
 */
function catalogViewOnlyRoles(): array
{
    return [
        'sales manager' => [Role::SalesManager],
        'service engineer' => [Role::ServiceEngineer],
    ];
}

/**
 * Roles that may write categories, attributes and brands.
 *
 * @return array<string, array{0: Role}>
 */
function catalogWriterRoles(): array
{
    return [
        'admin' => [Role::Admin],
        'content manager' => [Role::ContentManager],
    ];
}

/**
 * JSON-LD blocks of a page, decoded.
 *
 * @return list<array<string, mixed>>
 */
function jsonLdBlocks(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    return array_map(fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
}

/**
 * Count the database queries a callback runs.
 */
function countQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/**
 * Re-create the integration resolver as a fresh process would: every
 * integration config path is reset to the given ".env" values (null when
 * not given), then Secrets captures them and re-applies.
 *
 * @param  array<string, mixed>  $env  config path => value, e.g. ['services.telegram.bot_token' => 'env-token']
 */
function freshSecrets(array $env = []): void
{
    foreach (IntegrationRegistry::fields() as $field) {
        foreach ($field->configPaths as $path) {
            config([$path => $env[$path] ?? null]);
        }
    }

    app()->forgetInstance(Secrets::class);
    Secrets::apply();
}

/**
 * Mark the admin password as confirmed (Settings → Integrations), optionally in the past.
 */
function confirmAdminPassword(int $secondsAgo = 0): void
{
    session()->put(PasswordConfirmation::SESSION_KEY, now()->subSeconds($secondsAgo)->unix());
}

/**
 * Fake price API token (plan 09; never a real one): "pos_" + 48 characters.
 */
function fakeApiTokenValue(string $seed = 'a1B2'): string
{
    return 'pos_'.substr(str_repeat($seed, 48), 0, 48);
}

/**
 * POST a raw body to /api/v1/prices.
 *
 * @param  array<string, mixed>|string  $body  An array is JSON-encoded
 * @param  array<string, string>  $server  Extra server variables (e.g. CONTENT_TYPE)
 */
function postPrices(array|string $body, ?string $token = null, array $server = []): TestResponse
{
    $content = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);

    return test()->call('POST', '/api/v1/prices', [], [], [], array_filter([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => $token !== null ? 'Bearer '.$token : null,
        ...$server,
    ], fn ($value) => $value !== null), $content);
}

/**
 * One price item in whole soʻm.
 *
 * @return array{code: mixed, retail: mixed, dealer: mixed, wholesale: mixed}
 */
function priceItem(mixed $code, mixed $retail = 1000, mixed $dealer = null, mixed $wholesale = null): array
{
    return ['code' => $code, 'retail' => $retail, 'dealer' => $dealer, 'wholesale' => $wholesale];
}

/*
|--------------------------------------------------------------------------
| Launch (plan 15)
|--------------------------------------------------------------------------
*/

/**
 * The catalog base data plus the demo products, seeded as on local
 * (demo seeders don't run in the testing environment, DemoSeeding).
 */
function seedDemoCatalog(): void
{
    $environment = app()->environment();
    app()['env'] = 'local';

    try {
        test()->seed([CatalogSeeder::class, DemoProductsSeeder::class]);
    } finally {
        app()['env'] = $environment;
    }
}

/**
 * Open the site for search engines and AI crawlers (Settings → Indexing; closed by default).
 */
function openSearchIndexing(bool $open = true): void
{
    Setting::query()->updateOrCreate(['key' => CompanySettings::INDEXING_KEY], ['group' => 'seo', 'value' => $open]);
    CompanySettings::flush();
}

/*
|--------------------------------------------------------------------------
| Orders (plan 11b)
|--------------------------------------------------------------------------
*/

/**
 * A cart (with its token issued for this test) holding the given products, qty 1 each by default.
 *
 * @param  list<Product>|Product  $products
 */
function orderCart(array|Product $products = [], int $qty = 1): Cart
{
    config(['site.cart_enabled' => true]);
    $products = $products instanceof Product ? [$products] : $products;
    $products = $products === [] ? [Product::factory()->withIkpu()->create(['price' => 1_000_000_00])] : $products;

    $cart = Cart::factory()->create();

    foreach ($products as $product) {
        CartItem::factory()->for($cart)->create(['product_id' => $product->id, 'qty' => $qty]);
    }

    app(CartToken::class)->issue($cart->token);

    return $cart;
}

/**
 * Checkout input for a cart, with the fingerprint the page would show.
 *
 * @param  array<string, mixed>  $overrides  CheckoutData constructor arguments
 */
function checkoutData(Cart $cart, array $overrides = []): CheckoutData
{
    $values = array_merge([
        'name' => 'Aziz Karimov',
        'phone' => '+998 90 123 45 67',
        'buyerType' => 'individual',
        'companyName' => '',
        'tin' => '',
        'deliveryType' => 'pickup',
        'region' => '',
        'address' => '',
        'kokandConfirmed' => false,
        'comment' => '',
        'paymentMethod' => 'on_receipt',
        'locale' => 'uz',
        'fingerprint' => null,
    ], $overrides);

    if ($values['fingerprint'] === null) {
        $settings = app(CheckoutSettings::class);
        $delivery = DeliveryQuote::for(DeliveryType::from($values['deliveryType']), Region::tryFrom($values['region']), $settings);
        $values['fingerprint'] = PlaceOrder::fingerprint(app(CartPricer::class)->price($cart->fresh()), $delivery);
    }

    return new CheckoutData(...$values);
}

/**
 * Settings → Checkout values (whole soʻm), merged over the defaults.
 *
 * @param  array<string, mixed>  $values
 */
function checkoutSettings(array $values): void
{
    Setting::query()->updateOrCreate(['key' => 'checkout'], ['group' => 'checkout', 'value' => array_merge((array) config('checkout.defaults'), $values)]);
    CompanySettings::flush();
}

/**
 * Complete seller requisites (bank transfer needs them).
 */
function sellerRequisites(): void
{
    Setting::query()->updateOrCreate(['key' => 'legal'], ['group' => 'company', 'value' => [
        'legal_name' => ['uz' => 'Poson MChJ', 'ru' => 'ООО Poson', 'en' => 'Poson LLC'],
        'inn' => '305123456',
        'vat_code' => '',
        'bank' => 'Kapitalbank',
        'mfo' => '01088',
        'account' => '20208000900123456001',
        'oked' => '',
        'director' => 'Karimov A.',
        'legal_address' => ['uz' => 'Qoʻqon sh.', 'ru' => '', 'en' => ''],
    ]]);
    CompanySettings::flush();
}

/**
 * Upload a static UzQR code (fake PNG) through the real action.
 */
function uzqrUploaded(?User $by = null): void
{
    Storage::fake('local');
    app(UpdateUzqrImage::class)->handle(UploadedFile::fake()->image('qr.png', 300, 300), false, $by, null);
}

/**
 * Manual payment input (amount in tiyin).
 */
function manualPayment(string $method, ?int $amount, ?string $reference = 'PP-000123', ?string $paidOn = null, ?string $channel = null): ManualPaymentData
{
    return new ManualPaymentData($method, $amount, $paidOn ?? now(Lead::TIMEZONE)->toDateString(), $reference, $channel);
}
