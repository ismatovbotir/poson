<?php

use App\Enums\Role;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Lead;
use App\Models\Product;
use App\Support\Dashboard\BusinessMetrics;

it('counts only the leads the user can see', function () {
    $sales = staffUser(Role::SalesManager);
    $other = staffUser(Role::SalesManager);

    Lead::factory()->create(['manager_id' => $sales->id]);
    Lead::factory()->create(['manager_id' => $other->id]);
    Lead::factory()->create(['manager_id' => null]);

    $counts = (new BusinessMetrics($sales))->leadCounts();

    expect($counts)->toHaveKeys(['leads_new', 'leads_today', 'leads_unassigned', 'leads_mine'])
        ->and($counts['leads_new'])->toBe(2)
        ->and($counts['leads_unassigned'])->toBe(1)
        ->and($counts['leads_mine'])->toBe(1)
        ->and((new BusinessMetrics(staffUser(Role::Admin)))->leadCounts()['leads_new'])->toBe(3);
});

it('returns no lead counts without the permission', function () {
    expect((new BusinessMetrics(staffUser(Role::ContentManager)))->leadCounts())->toBe([]);
});

it('counts catalog items per permission', function () {
    Brand::factory()->count(2)->create();
    Category::factory()->create();
    Product::factory()->create(['is_active' => false]);

    $counts = (new BusinessMetrics(staffUser(Role::Admin)))->catalogCounts();

    expect($counts['brands'])->toBe(Brand::query()->count())
        ->and($counts['products_drafts'])->toBe(1)
        ->and($counts)->toHaveKeys(['categories', 'attributes', 'products_active', 'products_incomplete']);
});

it('counts uzqr unmatched as 0 without payment access', function () {
    expect((new BusinessMetrics(staffUser(Role::ContentManager)))->uzqrUnmatched())->toBe(0)
        ->and((new BusinessMetrics(staffUser(Role::Admin)))->uzqrUnmatched())->toBe(0);
});
