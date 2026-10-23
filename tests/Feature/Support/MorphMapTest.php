<?php

use App\Enums\Role;
use App\Models\Activity;
use App\Models\Category;
use App\Models\Customer;
use App\Models\FaqItem;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Quote;
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

it('stores short morph aliases for CRM activity subjects', function () {
    $lead = Lead::factory()->create();
    $customer = Customer::factory()->create();

    expect(Activity::factory()->subject($lead)->create()->subject_type)->toBe('lead')
        ->and(Activity::factory()->subject($customer)->create()->fresh()->subject)->toBeInstanceOf(Customer::class)
        ->and($lead->getMorphClass())->toBe('lead')
        ->and($customer->getMorphClass())->toBe('customer');
});

it('stores the short alias for quote activities', function () {
    $quote = Quote::factory()->create();

    expect(Activity::factory()->subject($quote)->create()->subject_type)->toBe('quote')
        ->and($quote->getMorphClass())->toBe('quote');
});

it('refuses a morph type for a model missing from the map', function () {
    expect(fn () => (new SettingsAudit)->getMorphClass())->toThrow(ClassMorphViolationException::class);
});
