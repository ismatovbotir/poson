<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
