---
name: testing-conventions
description: Pest testing conventions for Poson. Covers structure, factories/states, Livewire component tests, authorization datasets per role, webhook tests for Payme/Click, Http::fake for Telegram, and the commands to run. Use when writing or running tests.
---

# Testing (Pest)

- Install if missing: `composer require pestphp/pest pestphp/pest-plugin-laravel --dev -W` then `./vendor/bin/pest --init`. Ask the user first.
- Test DB: MySQL `poson_test` (set in `phpunit.xml`), or SQLite in memory if JSON queries aren't involved. Prefer MySQL because translatable JSON queries differ between the two.
- Every test file uses `RefreshDatabase` (set globally in `tests/Pest.php`).

## Layout

```
tests/Feature/Admin/<Module>/<Screen>Test.php
tests/Feature/Site/...
tests/Feature/Webhooks/PaymeTest.php, ClickTest.php
tests/Feature/Actions/<Module>/...
tests/Unit/Support/MoneyTest.php
```

## Patterns

```php
use function Pest\Livewire\livewire;   // or Livewire::test()

it('creates a product', function () {
    $user = User::factory()->withRole(Role::ContentManager)->create();

    livewire(Admin\Products\Form::class)
        ->actingAs($user)
        ->set('form.name.uz', 'Skaner')
        ->set('form.sku', 'ZB-DS2208')
        ->call('save')
        ->assertHasNoErrors();

    expect(Product::where('sku', 'ZB-DS2208')->exists())->toBeTrue();
});

it('forbids roles without access', function (Role $role) { ... })
    ->with([Role::SalesManager, Role::ServiceEngineer]);
```

- Factories have meaningful states: `Product::factory()->consumable()->quoteOnly()->priceOnRequest()`.
- Use `Http::fake()` for all external calls, and `Notification::fake()` / `Event::fake()` where relevant.
- Webhooks: build signed payloads with helpers (`paymeRequest($method, $params)`, `clickSign(...)`).

## Run

- `php artisan test --compact` runs everything
- `php artisan test --compact --filter=Payme` runs one area
- Run `vendor/bin/pint --dirty` before finishing
