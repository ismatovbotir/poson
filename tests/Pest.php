<?php

use App\Enums\Role;
use App\Models\IntegrationSecret;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\TextAssistant;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Secrets;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
 * Bind a fake TextAssistant and return it.
 */
function fakeTextAssistant(?FakeTextAssistant $fake = null): FakeTextAssistant
{
    $fake ??= new FakeTextAssistant;
    app()->instance(TextAssistant::class, $fake);

    return $fake;
}
