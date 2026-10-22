<?php

use App\Enums\LeadStatus;
use App\Enums\LeadType;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Database\Seeders\DemoLeadsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

it('does nothing outside local and staging', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(DemoLeadsSeeder::class);

    expect(Lead::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0);
});

it('seeds demo leads across types, statuses and managers once on local', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()['env'] = 'local';

    $this->seed(DemoLeadsSeeder::class);
    $this->seed(DemoLeadsSeeder::class);

    $leads = Lead::query()->get();

    expect($leads)->toHaveCount(15)
        ->and($leads->pluck('type')->unique()->count())->toBe(count(LeadType::cases()))
        ->and($leads->pluck('status')->unique()->count())->toBe(count(LeadStatus::cases()))
        ->and($leads->whereNotNull('duplicate_of_id'))->not->toBeEmpty()
        ->and($leads->whereNull('manager_id'))->not->toBeEmpty()
        ->and(Customer::query()->count())->toBeGreaterThanOrEqual(3)
        ->and(User::query()->where('email', 'like', '%.demo@poson.test')->get()->every->isStaff())->toBeTrue();
});
