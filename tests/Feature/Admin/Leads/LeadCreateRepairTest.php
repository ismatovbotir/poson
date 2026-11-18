<?php

use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Livewire\Admin\Leads\Form;
use App\Livewire\Admin\Leads\Show;
use App\Models\Lead;
use App\Models\Repair;
use App\Models\User;
use Livewire\Livewire;

/**
 * Plan 36 I3: LeadPolicy::createRepair, the Show page button, device block and repair list.
 */
function repairLeadFor(?User $manager, string $state = 'open'): Lead
{
    $factory = Lead::factory()->serviceRequest();

    if ($manager !== null) {
        $factory = $factory->assignedTo($manager);
    }

    return match ($state) {
        'lost' => $factory->lost()->create(),
        'converted' => $factory->converted()->create(),
        default => $factory->create(),
    };
}

it('decides createRepair per role, ownership and status', function (Role $role, string $owner, string $state, bool $expected) {
    $user = staffUser($role);
    $colleague = User::factory()->withRole($role)->create();
    $manager = match ($owner) {
        'own' => $user,
        'colleague' => $colleague,
        default => null,
    };

    $lead = repairLeadFor($manager, $state);

    if ($role === Role::Admin) {
        // Gate::before passes the Admin through the policy; the lost / type rule is enforced by acceptsRepair().
        expect($user->can('createRepair', $lead))->toBeTrue()->and($lead->acceptsRepair())->toBe($state !== 'lost');

        $html = Livewire::actingAs($user)->test(Show::class, ['lead' => $lead]);
        $expected ? $html->assertSeeHtml('data-action="create-repair"') : $html->assertDontSeeHtml('data-action="create-repair"');

        return;
    }

    expect($user->can('createRepair', $lead))->toBe($expected);
})->with(function () {
    $rows = [];

    foreach ([
        [Role::Admin, ['own', 'colleague', 'free'], true],
        [Role::ServiceEngineer, ['own', 'free'], true],
        [Role::ServiceEngineer, ['colleague'], false],
        [Role::SalesManager, ['own', 'colleague', 'free'], false],
        [Role::ContentManager, ['own', 'colleague', 'free'], false],
    ] as [$role, $owners, $allowed]) {
        foreach ($owners as $owner) {
            foreach (['open' => true, 'converted' => true, 'lost' => false] as $state => $statusOk) {
                $rows["{$role->value} {$owner} {$state}"] = [$role, $owner, $state, $allowed && $statusOk];
            }
        }
    }

    return $rows;
});

it('refuses createRepair on a sales-type lead for everyone', function () {
    $admin = staffUser();
    $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();

    foreach ([Lead::factory()->quote()->create(), Lead::factory()->create(), Lead::factory()->checkout()->create()] as $lead) {
        expect($lead->acceptsRepair())->toBeFalse()
            ->and($engineer->can('createRepair', $lead))->toBeFalse();
    }
});

it('shows the create repair button and the device block to an engineer on an open service request', function () {
    $engineer = staffUser(Role::ServiceEngineer);
    $lead = Lead::factory()->serviceRequest()->assignedTo($engineer)->create([
        'device_brand' => 'Zebra', 'device_model' => 'ZD421', 'serial_number' => 'SN-001', 'preferred_contact' => 'telegram',
    ]);

    Livewire::actingAs($engineer)->test(Show::class, ['lead' => $lead])
        ->assertSeeHtml('data-action="create-repair"')
        ->assertSeeHtml(route('admin.repairs.create', ['lead' => $lead->id]))
        ->assertSeeHtml('data-lead-device')
        ->assertSee('Zebra')->assertSee('ZD421')->assertSee('SN-001')->assertSee(__('enums.preferred_contact.telegram'));
});

it('hides the button for a sales manager, on a lost lead and on a sales lead', function () {
    $sales = staffUser(Role::SalesManager);
    $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();

    Livewire::actingAs($sales)->test(Show::class, ['lead' => repairLeadFor(null)])->assertDontSeeHtml('data-action="create-repair"');
    Livewire::actingAs($engineer)->test(Show::class, ['lead' => repairLeadFor($engineer, 'lost')])->assertDontSeeHtml('data-action="create-repair"');
    Livewire::actingAs($engineer)->test(Show::class, ['lead' => Lead::factory()->quote()->create()])->assertForbidden();
    Livewire::actingAs(staffUser())->test(Show::class, ['lead' => Lead::factory()->quote()->create()])
        ->assertDontSeeHtml('data-action="create-repair"')->assertDontSeeHtml('data-lead-device');
});

it('lists only the linked repairs the user may view, escaped', function () {
    $engineer = staffUser(Role::ServiceEngineer);
    $other = User::factory()->withRole(Role::ServiceEngineer)->create();
    $lead = repairLeadFor($engineer);
    $mine = Repair::factory()->assignedTo($engineer)->create(['lead_id' => $lead->id]);
    $theirs = Repair::factory()->assignedTo($other)->create(['lead_id' => $lead->id]);
    $unrelated = Repair::factory()->assignedTo($engineer)->create();

    Livewire::actingAs($engineer)->test(Show::class, ['lead' => $lead])
        ->assertSee($mine->number)->assertDontSee($theirs->number)->assertDontSee($unrelated->number);

    Livewire::actingAs(staffUser())->test(Show::class, ['lead' => $lead])
        ->assertSee($mine->number)->assertSee($theirs->number);
});

it('keeps lead status Converted off the lost lead and never lets sales link a customer after a repair conversion', function () {
    $sales = staffUser(Role::SalesManager);
    $lead = Lead::factory()->quote()->converted()->create();

    expect($sales->can('convert', $lead))->toBeFalse();
});

it('edits the device fields on a service request and drops them for another type', function () {
    $admin = staffUser();
    $lead = Lead::factory()->serviceRequest()->manual()->create();

    Livewire::actingAs($admin)->test(Form::class, ['lead' => $lead])
        ->assertSeeHtml('data-device-fields')
        ->set('form.device_brand', 'Zebra')->set('form.device_model', 'ZD421')
        ->set('form.serial_number', 'AB-12/3.4')->set('form.preferred_contact', 'telegram')
        ->call('save')->assertHasNoErrors();

    expect($lead->fresh())->device_brand->toBe('Zebra')->device_model->toBe('ZD421')->serial_number->toBe('AB-12/3.4')
        ->and($lead->fresh()->preferred_contact->value)->toBe('telegram');

    Livewire::actingAs($admin)->test(Form::class, ['lead' => $lead->fresh()])
        ->set('form.serial_number', "bad\nserial!")->call('save')->assertHasErrors(['form.serial_number'])
        ->set('form.serial_number', 'OK1')->set('form.preferred_contact', 'fax')->call('save')->assertHasErrors(['form.preferred_contact'])
        ->set('form.preferred_contact', '')->set('form.device_brand', str_repeat('a', 81))->call('save')->assertHasErrors(['form.device_brand'])
        ->set('form.device_brand', 'Zebra')->set('form.type', 'callback')->assertDontSeeHtml('data-device-fields')
        ->call('save')->assertHasNoErrors();

    expect($lead->fresh()->device_brand)->toBeNull()->and($lead->fresh()->status)->toBe(LeadStatus::New);
});

it('keeps Gate::before true for an Admin on an arbitrary ability', function () {
    expect(staffUser()->can('some.arbitrary-ability'))->toBeTrue();
});
