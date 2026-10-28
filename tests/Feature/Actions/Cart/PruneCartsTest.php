<?php

use App\Actions\Cart\PruneCarts;
use App\Models\Cart;
use App\Models\CartItem;
use Illuminate\Support\Facades\Schedule;

it('deletes empty carts after a day, converted carts a day after the order and the rest after 30 days', function () {
    $emptyOld = Cart::factory()->create(['updated_at' => now()->subDays(2)]);
    $emptyFresh = Cart::factory()->create(['updated_at' => now()->subHours(6)]);
    $convertedOld = Cart::factory()->create(['converted_at' => now()->subDays(2)]);
    $convertedFresh = Cart::factory()->create(['converted_at' => now()->subHours(6)]);
    $activeOld = Cart::factory()->create();
    $activeFresh = Cart::factory()->create();
    CartItem::factory()->for($activeOld)->create();
    CartItem::factory()->for($activeFresh)->create();
    CartItem::factory()->for($convertedOld)->create();
    CartItem::factory()->for($convertedFresh)->create();
    // Lines touch their cart: set the ages afterwards.
    $activeOld->forceFill(['updated_at' => now()->subDays(31)])->saveQuietly();
    $activeFresh->forceFill(['updated_at' => now()->subDays(29)])->saveQuietly();

    $this->artisan('carts:prune')->expectsOutputToContain('3 cart(s) deleted.')->assertSuccessful();

    expect(Cart::query()->pluck('id')->sort()->values()->all())->toBe(collect([$emptyFresh->id, $convertedFresh->id, $activeFresh->id])->sort()->values()->all())
        ->and(CartItem::query()->count())->toBe(2);
});

it('deletes an empty converted cart and keeps a recently emptied old cart', function () {
    $emptyConverted = Cart::factory()->create(['converted_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
    $recentlyEmptied = Cart::factory()->create(['created_at' => now()->subDays(40), 'updated_at' => now()->subHours(2)]);

    app(PruneCarts::class)->handle();

    expect(Cart::query()->find($emptyConverted->id))->toBeNull()
        ->and(Cart::query()->find($recentlyEmptied->id))->not->toBeNull();
});

it('is scheduled daily', function () {
    $event = collect(Schedule::events())->first(fn ($event) => str_contains((string) $event->command, 'carts:prune'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('10 2 * * *');
});
