<?php

use App\Enums\LeadType;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;

/**
 * Leads per kind: the user's own, a colleague's and unassigned, sales and service.
 *
 * @return array<string, Lead>
 */
function leadMatrix(User $user): array
{
    $colleague = User::factory()->withRole($user->staffRole() ?? Role::SalesManager)->create();

    return [
        'sales_own' => Lead::factory()->quote()->assignedTo($user)->create(),
        'sales_other' => Lead::factory()->quote()->assignedTo($colleague)->create(),
        'sales_free' => Lead::factory()->create(),
        'service_own' => Lead::factory()->serviceRequest()->assignedTo($user)->create(),
        'service_other' => Lead::factory()->serviceRequest()->assignedTo($colleague)->create(),
        'service_free' => Lead::factory()->serviceRequest()->create(),
    ];
}

it('scopes visible and editable leads per role like the policy', function (Role $role, array $visible, array $editable) {
    $user = staffUser($role);
    $leads = leadMatrix($user);

    $visibleIds = Lead::query()->visibleTo($user)->pluck('id')->all();
    $editableIds = Lead::query()->editableBy($user)->pluck('id')->all();

    foreach ($leads as $key => $lead) {
        $shouldSee = in_array($key, $visible, true);
        $shouldEdit = in_array($key, $editable, true);

        expect(in_array($lead->id, $visibleIds, true))->toBe($shouldSee, "{$role->value} list: {$key}")
            ->and($user->can('view', $lead))->toBe($shouldSee, "{$role->value} view: {$key}")
            ->and(in_array($lead->id, $editableIds, true))->toBe($shouldEdit, "{$role->value} editable list: {$key}")
            ->and($user->can('update', $lead))->toBe($shouldEdit, "{$role->value} update: {$key}");
    }
})->with([
    'admin' => [Role::Admin,
        ['sales_own', 'sales_other', 'sales_free', 'service_own', 'service_other', 'service_free'],
        ['sales_own', 'sales_other', 'sales_free', 'service_own', 'service_other', 'service_free']],
    'sales manager' => [Role::SalesManager,
        ['sales_own', 'sales_free', 'service_own', 'service_other', 'service_free'],
        ['sales_own', 'sales_free']],
    'service engineer' => [Role::ServiceEngineer,
        ['service_own', 'service_free'],
        ['service_own', 'service_free']],
    'content manager' => [Role::ContentManager, [], []],
]);

it('lets only the admin delete and assign freely', function (Role $role, bool $delete) {
    $user = staffUser($role);
    $lead = Lead::factory()->create();

    expect($user->can('delete', $lead))->toBe($delete);
})->with([
    'admin' => [Role::Admin, true],
    'sales manager' => [Role::SalesManager, false],
    'service engineer' => [Role::ServiceEngineer, false],
]);

it('lets a sales manager reassign sales leads but not service requests', function () {
    $sales = staffUser(Role::SalesManager);

    expect($sales->can('assign', Lead::factory()->quote()->assignedTo($sales)->create()))->toBeTrue()
        ->and($sales->can('assign', Lead::factory()->create()))->toBeTrue()
        ->and($sales->can('assign', Lead::factory()->serviceRequest()->create()))->toBeFalse()
        ->and($sales->can('assign', Lead::factory()->quote()->assignedTo(User::factory()->create())->create()))->toBeFalse();

    $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();

    expect($engineer->can('assign', Lead::factory()->serviceRequest()->create()))->toBeFalse()
        ->and($engineer->can('take', Lead::factory()->serviceRequest()->create()))->toBeTrue()
        ->and($engineer->can('take', Lead::factory()->create()))->toBeFalse();
});

it('limits the lead types a user may create', function (Role $role, bool $sales, bool $service) {
    $user = staffUser($role);

    expect($user->can('create', [Lead::class, LeadType::Quote]))->toBe($sales)
        ->and($user->can('create', [Lead::class, LeadType::ServiceRequest]))->toBe($service);
})->with([
    'admin' => [Role::Admin, true, true],
    'sales manager' => [Role::SalesManager, true, false],
    'service engineer' => [Role::ServiceEngineer, false, true],
    'content manager' => [Role::ContentManager, false, false],
]);

it('lets every CRM role view customers but only own ones be edited and the price level by admin', function () {
    $sales = staffUser(Role::SalesManager);
    $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
    $content = User::factory()->withRole(Role::ContentManager)->create();
    $admin = User::factory()->withRole(Role::Admin)->create();

    $own = Customer::factory()->managedBy($sales)->create();
    $free = Customer::factory()->create();
    $other = Customer::factory()->managedBy($admin)->create();

    expect($sales->can('viewAny', Customer::class))->toBeTrue()
        ->and($sales->can('update', $own))->toBeTrue()
        ->and($sales->can('update', $free))->toBeTrue()
        ->and($sales->can('update', $other))->toBeFalse()
        ->and($sales->can('changePriceLevel', Customer::class))->toBeFalse()
        ->and($sales->can('delete', $own))->toBeFalse()
        ->and($engineer->can('view', $own))->toBeTrue()
        ->and($engineer->can('update', $free))->toBeFalse()
        ->and($content->can('viewAny', Customer::class))->toBeFalse()
        ->and($admin->can('changePriceLevel', Customer::class))->toBeTrue()
        ->and(Customer::query()->editableBy($sales)->pluck('id')->all())->toEqualCanonicalizing([$own->id, $free->id])
        ->and(Customer::query()->visibleTo($content)->count())->toBe(0);
});
