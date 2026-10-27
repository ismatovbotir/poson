<?php

use App\Enums\AiProvider;
use App\Enums\Role;
use App\Models\IntegrationSecret;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\TextAssistants;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeTextAssistant;
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
 * @param  array<string, mixed>  $env  config path => value, e.g. ['services.anthropic.key' => 'env-key']
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
 * Fake Anthropic key used by the AI assist tests (never a real one).
 */
function fakeAnthropicKey(): string
{
    return 'sk-ant-test-SECRETVALUE-0000000000000000000000';
}

/**
 * Switch the admin AI assist on with a fake key saved in Settings →
 * Integrations (and optional limits), then re-apply the resolver.
 *
 * @param  array<string, mixed>  $settings  integration key => value, e.g. ['ai.monthly_budget_usd' => 5]
 */
function enableAiAssist(array $settings = [], bool $withKey = true): void
{
    freshSecrets();
    IntegrationSecret::query()->where('key', 'anthropic.api_key')->delete();
    Setting::query()->where('group', 'integrations')->where('key', 'like', 'ai.%')->delete();

    if ($withKey) {
        IntegrationSecret::factory()->withValue('anthropic.api_key', fakeAnthropicKey())->create();
    }

    foreach (['ai.enabled' => true, ...$settings] as $key => $value) {
        Setting::factory()->create(['key' => $key, 'group' => 'integrations', 'value' => $value]);
    }

    Secrets::flush();
}

/**
 * Fake OpenRouter key used by the AI assist tests (never a real one).
 */
function fakeOpenRouterKey(): string
{
    return 'sk-or-v1-test-SECRETVALUE-00000000000000000000';
}

/**
 * Switch the AI assist on with OpenRouter as the text provider and a fake
 * OpenRouter key (plus optional settings), then re-apply the resolver.
 *
 * @param  array<string, mixed>  $settings  integration key => value
 */
function enableOpenRouter(array $settings = []): void
{
    enableAiAssist(['ai.text_provider' => 'openrouter', ...$settings], withKey: false);

    IntegrationSecret::query()->where('key', 'openrouter.api_key')->delete();
    IntegrationSecret::factory()->withValue('openrouter.api_key', fakeOpenRouterKey())->create();

    Secrets::flush();
}

/**
 * Bind a fake TextAssistant (for every text provider, or only $provider) and return it.
 */
function fakeTextAssistant(?FakeTextAssistant $fake = null, ?AiProvider $provider = null): FakeTextAssistant
{
    $fake ??= new FakeTextAssistant;

    foreach ($provider !== null ? [$provider] : AiProvider::textProviders() as $textProvider) {
        app()->instance(TextAssistants::binding($textProvider), $fake);
    }

    return $fake;
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
