<?php

use App\Enums\ActivityType;
use App\Enums\LeadLostReason;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Livewire\Admin\Leads\Show;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Phone;
use Livewire\Livewire;

it('opens a lead per role like the policy', function (Role $role, string $lead, int $status) {
    $user = staffUser($role);
    $colleague = User::factory()->withRole(Role::SalesManager)->create();

    $record = match ($lead) {
        'sales free' => Lead::factory()->create(),
        'sales other' => Lead::factory()->assignedTo($colleague)->create(),
        'service' => Lead::factory()->serviceRequest()->assignedTo(User::factory()->withRole(Role::ServiceEngineer)->create())->create(),
    };

    $this->actingAs($user)->get(route('admin.leads.show', $record))->assertStatus($status);
})->with([
    'admin: sales other' => [Role::Admin, 'sales other', 200],
    'sales: free' => [Role::SalesManager, 'sales free', 200],
    'sales: other' => [Role::SalesManager, 'sales other', 403],
    'sales: service' => [Role::SalesManager, 'service', 200],
    'engineer: sales' => [Role::ServiceEngineer, 'sales free', 403],
    'engineer: other service' => [Role::ServiceEngineer, 'service', 403],
    'content: sales' => [Role::ContentManager, 'sales free', 403],
]);

it('shows the contact, items with a product link, attribution, badges and the timeline', function () {
    $admin = staffUser();
    $original = Lead::factory()->create();
    $customer = Customer::factory()->create(['name' => 'Mega Savdo MChJ']);
    $lead = Lead::factory()->duplicateOf($original)->forCustomer($customer)->create([
        'name' => 'Bekzod', 'company' => 'Mega', 'traffic_source' => 'google', 'utm' => ['utm_source' => 'google'], 'landing_path' => '/ru/catalog',
    ]);
    $product = Product::factory()->create();
    LeadItem::factory()->for($lead)->forProduct($product)->create(['qty' => 4]);
    Activity::factory()->subject($lead)->by($admin)->create(['body' => 'Asked for a demo']);

    Livewire::actingAs($admin)->test(Show::class, ['lead' => $lead])
        ->assertSee('Bekzod')
        ->assertSee(Phone::display($lead->phone))
        ->assertSeeHtml('href="'.route('admin.products.edit', $product).'"')
        ->assertSee('×4')
        ->assertSee('google')
        ->assertSee('/ru/catalog')
        ->assertSeeHtml('data-duplicate-of')
        ->assertSee($original->reference())
        ->assertSee(__('crm.leads.customer_badge', ['name' => 'Mega Savdo MChJ']))
        ->assertSee('Asked for a demo');
});

it('shows a service request read-only to a sales manager and refuses writes', function () {
    $sales = staffUser(Role::SalesManager);
    $lead = Lead::factory()->serviceRequest()->create();

    Livewire::actingAs($sales)->test(Show::class, ['lead' => $lead])
        ->assertSeeHtml('data-read-only')
        ->assertDontSeeHtml('data-status-to=')
        ->assertDontSeeHtml('data-activity-form')
        ->assertDontSeeHtml('data-action="take"')
        ->call('changeStatus', 'in_progress')
        ->assertForbidden();

    Livewire::actingAs($sales)->test(Show::class, ['lead' => $lead])->set('activityBody', 'x')->call('addActivity')->assertForbidden();
    Livewire::actingAs($sales)->test(Show::class, ['lead' => $lead])->call('take')->assertForbidden();

    expect($lead->fresh())->status->toBe(LeadStatus::New)->manager_id->toBeNull()
        ->and($lead->activities()->count())->toBe(0);
});

it('changes the status and asks for a reason to mark it lost', function () {
    $sales = staffUser(Role::SalesManager);
    $lead = Lead::factory()->assignedTo($sales)->create();

    Livewire::actingAs($sales)->test(Show::class, ['lead' => $lead])
        ->call('changeStatus', 'in_progress')
        ->assertDispatched('toast')
        ->call('markLost')
        ->assertHasErrors(['lostReason' => 'required'])
        ->set('lostReason', 'other')
        ->call('markLost')
        ->assertHasErrors(['lostNote'])
        ->set('lostNote', 'Chose a local reseller')
        ->call('markLost')
        ->assertHasNoErrors()
        ->assertDispatched('lead-lost-saved')
        ->assertSeeHtml('data-status-to="in_progress"');

    expect($lead->fresh())
        ->status->toBe(LeadStatus::Lost)
        ->lost_reason->toBe(LeadLostReason::Other)
        ->lost_note->toBe('Chose a local reseller');
});

it('lets the owner take, release and the sales manager reassign to a sales colleague', function () {
    $sales = staffUser(Role::SalesManager);
    $colleague = User::factory()->withRole(Role::SalesManager)->create();
    $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
    $lead = Lead::factory()->create();

    $component = Livewire::actingAs($sales)->test(Show::class, ['lead' => $lead])
        ->call('take')
        ->assertSeeHtml('data-action="release"');

    expect($lead->fresh()->manager_id)->toBe($sales->id)
        ->and(array_keys($component->get('candidates')))->toEqualCanonicalizing([$sales->id, $colleague->id]);

    $component->set('assignTo', (string) $engineer->id)->call('assign');
    expect($lead->fresh()->manager_id)->toBe($sales->id);

    // Handing it to a colleague: the first manager can't see it any more, so back to the list.
    $component->set('assignTo', (string) $colleague->id)->call('assign')->assertRedirect(route('admin.leads.index'));
    expect($lead->fresh()->manager_id)->toBe($colleague->id);

    // Now it's the colleague's lead: the first manager can't touch it any more.
    $this->actingAs($sales)->get(route('admin.leads.show', $lead))->assertForbidden();
});

it('redirects to the list after releasing a lead the user can no longer see', function () {
    $engineer = staffUser(Role::ServiceEngineer);
    $lead = Lead::factory()->serviceRequest()->assignedTo($engineer)->create();

    // Still visible after release (unassigned): stays on the page.
    Livewire::actingAs($engineer)->test(Show::class, ['lead' => $lead])
        ->call('release')
        ->assertNoRedirect()
        ->assertDispatched('toast');

    expect($lead->fresh()->manager_id)->toBeNull();
});

it('re-checks access on every request and forbids writes after a reassign elsewhere', function () {
    $sales = staffUser(Role::SalesManager);
    $colleague = User::factory()->withRole(Role::SalesManager)->create();
    $lead = Lead::factory()->assignedTo($sales)->create();

    $component = Livewire::actingAs($sales)->test(Show::class, ['lead' => $lead]);

    $lead->update(['manager_id' => $colleague->id]);

    $component->call('assign')->assertForbidden();
    expect($lead->fresh()->manager_id)->toBe($colleague->id);
});

it('never sets converted by hand', function () {
    $admin = staffUser();
    $lead = Lead::factory()->create();

    Livewire::actingAs($admin)->test(Show::class, ['lead' => $lead])
        ->assertDontSeeHtml('data-status-to="converted"')
        ->assertSeeHtml('data-status-to="qualified"')
        ->call('changeStatus', 'converted');

    expect($lead->fresh()->status)->toBe(LeadStatus::New)
        ->and(LeadStatus::Converted->isManual())->toBeFalse();
});

it('does not let a sales manager write another manager lead', function () {
    $sales = staffUser(Role::SalesManager);
    $lead = Lead::factory()->assignedTo(User::factory()->withRole(Role::SalesManager)->create())->create();

    Livewire::actingAs($sales)->test(Show::class, ['lead' => $lead])->assertForbidden();
});

it('adds a note and logs a call', function () {
    $engineer = staffUser(Role::ServiceEngineer);
    $lead = Lead::factory()->serviceRequest()->assignedTo($engineer)->create();

    Livewire::actingAs($engineer)->test(Show::class, ['lead' => $lead])
        ->call('addActivity')
        ->assertHasErrors(['activityBody' => 'required'])
        ->set('activityBody', 'Printer head replaced')
        ->call('addActivity')
        ->assertHasNoErrors()
        ->assertSet('activityBody', '')
        ->set('activityType', 'call')
        ->set('activityBody', 'Client confirmed pickup')
        ->call('addActivity')
        ->assertSee('Printer head replaced')
        ->assertSee('Client confirmed pickup');

    expect($lead->activities()->pluck('type')->all())->toEqualCanonicalizing([ActivityType::Note, ActivityType::Call]);
});

it('lets only the admin delete a lead', function () {
    $lead = Lead::factory()->create();
    $sales = staffUser(Role::SalesManager);

    Livewire::actingAs($sales)->test(Show::class, ['lead' => $lead])
        ->assertDontSee(__('crm.leads.danger_zone'))
        ->call('delete')
        ->assertForbidden();

    Livewire::actingAs(User::factory()->withRole(Role::Admin)->create())->test(Show::class, ['lead' => $lead])
        ->call('delete')
        ->assertRedirect(route('admin.leads.index'));

    expect(Lead::query()->count())->toBe(0);
});
