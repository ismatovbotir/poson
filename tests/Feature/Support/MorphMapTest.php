<?php

use App\Enums\Role;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\Product;
use App\Models\SettingsAudit;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Support\Facades\DB;

it('stores short morph aliases for FAQ owners and role assignments', function () {
    $categoryItem = FaqItem::factory()->forCategory()->create();
    $productItem = FaqItem::factory()->forProduct()->create();
    $user = staffUser(Role::ContentManager);

    expect($categoryItem->faqable_type)->toBe('category')
        ->and($productItem->faqable_type)->toBe('product')
        ->and($categoryItem->faqable)->toBeInstanceOf(Category::class)
        ->and($productItem->faqable)->toBeInstanceOf(Product::class)
        ->and(DB::table('model_has_roles')->where('model_id', $user->id)->value('model_type'))->toBe('user')
        ->and($user->fresh()->hasRole(Role::ContentManager))->toBeTrue();
});

it('refuses a morph type for a model missing from the map', function () {
    expect(fn () => (new SettingsAudit)->getMorphClass())->toThrow(ClassMorphViolationException::class);
});
