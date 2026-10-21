<?php

use App\Enums\ProductType;
use App\Enums\Role;
use App\Models\Product;
use App\Models\User;

it('applies the manage-services record rule for the service engineer', function (ProductType $type, bool $allowed) {
    $user = staffUser(Role::ServiceEngineer);
    $product = Product::factory()->create(['type' => $type]);

    expect($user->can('view', $product))->toBeTrue()
        ->and($user->can('update', $product))->toBe($allowed)
        ->and($user->can('delete', $product))->toBe($allowed)
        ->and($user->can('restore', $product))->toBe($allowed)
        ->and($user->can('create', [Product::class, $type]))->toBe($allowed)
        ->and($user->can('useType', [Product::class, $type]))->toBe($allowed)
        ->and($user->can('forceDelete', $product))->toBeFalse();
})->with([
    'hardware' => [ProductType::Hardware, false],
    'consumable' => [ProductType::Consumable, false],
    'software' => [ProductType::Software, true],
    'service' => [ProductType::Service, true],
]);

it('gives each role its product writes', function (Role $role, bool $write, bool $forceDelete) {
    $user = staffUser($role);
    $hardware = Product::factory()->create();

    expect($user->can('viewAny', Product::class))->toBeTrue()
        ->and($user->can('create', [Product::class, ProductType::Hardware]))->toBe($write)
        ->and($user->can('update', $hardware))->toBe($write)
        ->and($user->can('delete', $hardware))->toBe($write)
        ->and($user->can('useType', [Product::class, ProductType::Consumable]))->toBe($write)
        ->and($user->can('forceDelete', $hardware))->toBe($forceDelete);
})->with([
    'admin' => [Role::Admin, true, true],
    'content manager' => [Role::ContentManager, true, false],
    'sales manager' => [Role::SalesManager, false, false],
    'service engineer' => [Role::ServiceEngineer, false, false],
]);

it('denies everything to users without a staff role', function () {
    $user = User::factory()->create();
    $product = Product::factory()->service()->create();

    expect($user->can('viewAny', Product::class))->toBeFalse()
        ->and($user->can('view', $product))->toBeFalse()
        ->and($user->can('update', $product))->toBeFalse();
});

it('keeps guests and non-staff users out of the product routes', function () {
    $product = Product::factory()->create();
    $urls = [route('admin.products.index'), route('admin.products.create'), route('admin.products.edit', $product)];

    foreach ($urls as $url) {
        $this->get($url)->assertRedirect(route('admin.login'));
    }

    $user = User::factory()->create();

    foreach ($urls as $url) {
        $this->actingAs($user)->get($url)->assertRedirect(route('admin.login'));
    }
});
