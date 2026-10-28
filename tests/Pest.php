<?php

use App\Enums\Role;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
