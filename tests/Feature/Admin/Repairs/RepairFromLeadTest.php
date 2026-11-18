<?php

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Livewire\Admin\Repairs\Form;
use App\Models\Activity;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Product;
use App\Models\Repair;
use App\Models\User;
use App\Support\Catalog\CategoryTree;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Plan 36 I3: the repair form prefilled from a service request (?lead=) and
 * CreateRepair converting / linking the lead.
 */
function fromLead(array $attributes = [], ?User $manager = null, ?Product $product = null): Lead
{
    $factory = Lead::factory()->serviceRequest();

    if ($manager !== null) {
        $factory = $factory->assignedTo($manager);
    }

    $lead = $factory->create($attributes + [
        'name' => 'Ali Valiyev', 'phone' => '+998901234567', 'company' => 'Market LLC', 'message' => 'Does not print',
        'device_brand' => 'Zebra', 'device_model' => 'ZD421', 'serial_number' => 'SN-7/1', 'locale' => 'ru',
    ]);

    if ($product !== null) {
        LeadItem::factory()->for($lead)->forProduct($product)->create();
    }

    return $lead;
}

function scannerProduct(): Product
{
    $category = Category::factory()->create(['slug' => 'scanners']);
    CategoryTree::flush();

    return Product::factory()->create(['category_id' => $category->id]);
}

function leadForm(User $user, Lead $lead)
{
    return Livewire::withQueryParams(['lead' => $lead->id])->actingAs($user)->test(Form::class);
}

function activityCount(Lead $lead, ActivityType $type): int
{
    return Activity::query()->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->id)->where('type', $type)->count();
}

it('prefills the repair form from the lead for every allowed role', function (Role $role, string $owner) {
    $user = staffUser($role);
    $lead = fromLead(manager: $owner === 'own' ? $user : null, product: scannerProduct());

    leadForm($user, $lead)
        ->assertSet('form.lead_id', (string) $lead->id)
        ->assertSet('form.contact_name', 'Ali Valiyev')
        ->assertSet('form.contact_phone', '+998 (90) 123-45-67')
        ->assertSet('form.company', 'Market LLC')
        ->assertSet('form.device_brand', 'Zebra')
        ->assertSet('form.device_model', 'ZD421')
        ->assertSet('form.serial_number', 'SN-7/1')
        ->assertSet('form.problem', 'Does not print')
        ->assertSet('form.locale', 'ru')
        ->assertSet('form.device_type', 'scanner')
        ->assertSet('form.intake_condition', '');
})->with([
    'admin own' => [Role::Admin, 'own'],
    'admin free' => [Role::Admin, 'free'],
    'engineer own' => [Role::ServiceEngineer, 'own'],
    'engineer free' => [Role::ServiceEngineer, 'free'],
]);

it('leaves the device type empty without a mappable product', function () {
    $admin = staffUser();

    leadForm($admin, fromLead())->assertSet('form.device_type', '');
    leadForm($admin, fromLead(product: Product::factory()->service()->create()))->assertSet('form.device_type', '');
});

it('creates the repair, converts an open lead and logs activities on both sides', function () {
    $engineer = staffUser(Role::ServiceEngineer);
    $lead = fromLead(manager: $engineer, product: scannerProduct());

    leadForm($engineer, $lead)->set('form.intake_condition', 'Scratches')->call('save')->assertHasNoErrors();

    $repair = Repair::query()->firstOrFail();
    $lead->refresh();

    expect($repair->lead_id)->toBe($lead->id)
        ->and($repair->device_type->value)->toBe('scanner')
        ->and($lead->status)->toBe(LeadStatus::Converted)
        ->and($lead->closed_at)->not->toBeNull()
        ->and(activityCount($lead, ActivityType::StatusChange))->toBe(1);

    $activity = Activity::query()->where('subject_id', $lead->id)->where('subject_type', $lead->getMorphClass())->where('type', ActivityType::Repair)->sole();

    expect($activity->meta)->toBe(['event' => 'repair_created', 'repair_id' => $repair->id, 'number' => $repair->number])
        ->and($repair->activities()->where('type', ActivityType::Created)->count())->toBe(1);
});

it('allows a second repair on a converted lead without touching its status', function () {
    $admin = staffUser();
    $lead = fromLead();

    foreach ([1, 2] as $n) {
        leadForm($admin, $lead)->set('form.device_type', 'pos')->set('form.intake_condition', "Unit {$n}")->call('save')->assertHasNoErrors();
    }

    expect(Repair::query()->where('lead_id', $lead->id)->count())->toBe(2)
        ->and($lead->fresh()->status)->toBe(LeadStatus::Converted)
        ->and(activityCount($lead, ActivityType::StatusChange))->toBe(1)
        ->and(activityCount($lead, ActivityType::Repair))->toBe(2);
});

it('prefills a customer only when the user may view it', function () {
    $customer = Customer::factory()->create();
    $admin = staffUser();
    $lead = fromLead(['customer_id' => $customer->id]);

    leadForm($admin, $lead)->assertSet('form.customer_id', (string) $customer->id);

    $engineer = staffUser(Role::ServiceEngineer);
    $engineerLead = fromLead(['customer_id' => $customer->id], $engineer);
    leadForm($engineer, $engineerLead)->assertSet('form.customer_id', (string) $customer->id);

    Spatie\Permission\Models\Role::findByName(Role::ServiceEngineer->value)->revokePermissionTo('customers.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    leadForm($engineer->fresh(), $engineerLead)->assertSet('form.customer_id', '')->assertSet('form.lead_id', (string) $engineerLead->id);
});

it('prefills nothing for an engineer on a colleague lead and keeps its data out of the snapshot', function () {
    $engineer = staffUser(Role::ServiceEngineer);
    $colleague = User::factory()->withRole(Role::ServiceEngineer)->create();
    $lead = fromLead(['name' => 'Secret Person', 'phone' => '+998907654321'], $colleague);

    $component = leadForm($engineer, $lead)->assertSet('form.lead_id', '')->assertSet('form.contact_name', '');
    $json = json_encode($component->snapshot);

    expect($json)->not->toContain('Secret Person')->not->toContain('7654321')->not->toContain('Does not print')->not->toContain('SN-7');
});

it('ignores ?lead= on a lost, sales-type or missing lead', function () {
    $admin = staffUser();

    leadForm($admin, Lead::factory()->serviceRequest()->lost()->create(['name' => 'Lost One']))->assertSet('form.lead_id', '')->assertSet('form.contact_name', '');
    leadForm($admin, Lead::factory()->quote()->create(['name' => 'Sales One']))->assertSet('form.lead_id', '')->assertSet('form.contact_name', '');

    Livewire::withQueryParams(['lead' => 999999])->actingAs($admin)->test(Form::class)->assertSet('form.lead_id', '');
});

it('refuses a lead lost between mount and save', function () {
    $engineer = staffUser(Role::ServiceEngineer);
    $lead = fromLead(manager: $engineer);

    $component = leadForm($engineer, $lead)->set('form.device_type', 'pos')->set('form.intake_condition', 'Ok');
    $lead->forceFill(['status' => LeadStatus::Lost])->save();

    $component->call('save')->assertForbidden();
    expect(Repair::query()->count())->toBe(0);
});

it('refuses a lead reassigned to a colleague between mount and save', function () {
    $engineer = staffUser(Role::ServiceEngineer);
    $colleague = User::factory()->withRole(Role::ServiceEngineer)->create();
    $lead = fromLead(manager: $engineer);

    $component = leadForm($engineer, $lead)->set('form.device_type', 'pos')->set('form.intake_condition', 'Ok');
    $lead->forceFill(['manager_id' => $colleague->id])->save();

    $component->call('save')->assertForbidden();
    expect(Repair::query()->count())->toBe(0)->and($lead->fresh()->status)->toBe(LeadStatus::New);
});
