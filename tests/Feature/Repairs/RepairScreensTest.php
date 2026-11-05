<?php

use App\Enums\ActivityType;
use App\Enums\RepairStatus;
use App\Enums\Role;
use App\Livewire\Admin\Repairs\Form;
use App\Livewire\Admin\Repairs\Index;
use App\Livewire\Admin\Repairs\Show;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Repair;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * A service engineer who may view and work on repairs but has lost repairs.costs.
 */
function repairsEngineerWithoutCosts(): User
{
    $user = staffUser(Role::ServiceEngineer);
    $user->givePermissionTo('repairs.viewAny');
    SpatieRole::findByName(Role::ServiceEngineer->value, 'web')->revokePermissionTo('repairs.costs');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

describe('routes per role', function () {
    it('opens the list for Admin, Sales and Engineer and forbids the content manager', function (Role $role, int $status) {
        $this->actingAs(staffUser($role))->get(route('admin.repairs.index'))->assertStatus($status);
    })->with([
        'admin' => [Role::Admin, 200],
        'sales manager' => [Role::SalesManager, 200],
        'service engineer' => [Role::ServiceEngineer, 200],
        'content manager' => [Role::ContentManager, 403],
    ]);

    it('opens the intake form for Admin and Engineer only', function (Role $role, int $status) {
        $this->actingAs(staffUser($role))->get(route('admin.repairs.create'))->assertStatus($status);
    })->with([
        'admin' => [Role::Admin, 200],
        'service engineer' => [Role::ServiceEngineer, 200],
        'sales manager' => [Role::SalesManager, 403],
        'content manager' => [Role::ContentManager, 403],
    ]);

    it('opens a repair page and its edit form per role', function (Role $role, int $show, int $edit) {
        $user = staffUser($role);
        $repair = Repair::factory()->create();

        $this->actingAs($user)->get(route('admin.repairs.show', $repair))->assertStatus($show);
        $this->actingAs($user)->get(route('admin.repairs.edit', $repair))->assertStatus($edit);
    })->with([
        'admin' => [Role::Admin, 200, 200],
        'sales manager' => [Role::SalesManager, 200, 403],
        'service engineer' => [Role::ServiceEngineer, 200, 200],
        'content manager' => [Role::ContentManager, 403, 403],
    ]);

    it('keeps a colleague’s repair closed to an engineer, on the page and the form', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $colleague = User::factory()->withRole(Role::ServiceEngineer)->create();
        $theirs = Repair::factory()->assignedTo($colleague)->create();

        $this->actingAs($engineer)->get(route('admin.repairs.show', $theirs))->assertForbidden();
        $this->actingAs($engineer)->get(route('admin.repairs.edit', $theirs))->assertForbidden();

        Livewire::actingAs($engineer)->test(Show::class, ['repair' => $theirs])->assertForbidden();
    });

    it('stops a component whose repair was reassigned meanwhile', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $repair = Repair::factory()->assignedTo($engineer)->create();

        $component = Livewire::actingAs($engineer)->test(Show::class, ['repair' => $repair]);
        $repair->update(['assigned_to_id' => User::factory()->withRole(Role::ServiceEngineer)->create()->id]);

        $component->call('addActivity')->assertForbidden();
    });
});

describe('index', function () {
    it('lists only the repairs the user may see', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $colleague = User::factory()->withRole(Role::ServiceEngineer)->create();
        $own = Repair::factory()->assignedTo($engineer)->create(['company' => 'Own Co']);
        $free = Repair::factory()->create(['company' => 'Free Co']);
        $other = Repair::factory()->assignedTo($colleague)->create(['company' => 'Other Co']);

        Livewire::actingAs($engineer)->test(Index::class)
            ->assertSee('Own Co')->assertSee('Free Co')->assertDontSee('Other Co');

        Livewire::actingAs(staffUser(Role::SalesManager))->test(Index::class)
            ->assertSee('Own Co')->assertSee('Free Co')->assertSee('Other Co')
            ->assertDontSee('data-take');
    });

    it('searches by number, phone digits and serial number inside the visible scope', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $colleague = User::factory()->withRole(Role::ServiceEngineer)->create();
        Repair::factory()->create(['number' => 'R-2026-0007', 'contact_phone' => '+998901112233', 'serial_number' => 'ZT-0042', 'company' => 'Alpha']);
        Repair::factory()->create(['number' => 'R-2026-0008', 'contact_phone' => '+998935556677', 'serial_number' => 'XX-9', 'company' => 'Beta']);
        Repair::factory()->assignedTo($colleague)->create(['serial_number' => 'ZT-0043', 'company' => 'Hidden']);

        $list = Livewire::actingAs($engineer)->test(Index::class);

        $list->set('search', 'R-2026-0007')->assertSee('Alpha')->assertDontSee('Beta')
            ->set('search', '90 111')->assertSee('Alpha')->assertDontSee('Beta')
            ->set('search', 'zt-004')->assertSee('Alpha')->assertDontSee('Hidden')
            ->set('search', '%')->assertDontSee('Alpha')->assertDontSee('Beta');
    });

    it('filters by status, coverage and engineer and keeps closed repairs in their tab', function () {
        $admin = staffUser();
        $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
        Repair::factory()->create(['company' => 'Received Co']);
        Repair::factory()->warranty()->status(RepairStatus::Diagnosing)->create(['company' => 'Warranty Co']);
        Repair::factory()->assignedTo($engineer)->create(['company' => 'Mine Co']);
        Repair::factory()->status(RepairStatus::Delivered)->create(['company' => 'Done Co']);

        Livewire::actingAs($admin)->test(Index::class)
            ->assertSee('Received Co')->assertDontSee('Done Co')
            ->set('coverage', 'warranty')->assertSee('Warranty Co')->assertDontSee('Received Co')
            ->set('coverage', '')->set('engineer', (string) $engineer->id)->assertSee('Mine Co')->assertDontSee('Received Co')
            ->set('engineer', 'none')->assertSee('Received Co')->assertDontSee('Mine Co')
            ->set('engineer', '')->set('status', 'delivered')->assertSee('Done Co')->assertDontSee('Received Co')
            ->call('clearFilters')->call('setTab', 'closed')->assertSee('Done Co')->assertDontSee('Received Co');
    });

    it('lets an engineer take an unassigned repair from the list but not a taken one', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $free = Repair::factory()->create();
        $taken = Repair::factory()->assignedTo(User::factory()->withRole(Role::ServiceEngineer)->create())->create();

        Livewire::actingAs($engineer)->test(Index::class)->call('take', $free->id);
        expect($free->fresh()->assigned_to_id)->toBe($engineer->id);

        Livewire::actingAs($engineer)->test(Index::class)->call('take', $taken->id)->assertForbidden();
        Livewire::actingAs(staffUser(Role::SalesManager))->test(Index::class)->call('take', $free->id)->assertForbidden();
    });

    it('runs the same number of queries for 3 and 15 repairs and never lazy loads', function () {
        $admin = staffUser();
        $engineers = User::factory()->count(4)->withRole(Role::ServiceEngineer)->create();

        Model::preventLazyLoading();

        $render = fn () => countQueries(fn () => Livewire::actingAs($admin)->test(Index::class)->html());
        $render(); // warm-up: permission and role caches

        Repair::factory()->count(3)->sequence(fn ($sequence) => ['assigned_to_id' => $engineers[$sequence->index % 4]->id])->create();
        $few = $render();

        Repair::factory()->count(12)->sequence(fn ($sequence) => ['assigned_to_id' => $engineers[$sequence->index % 4]->id])->create();
        $many = $render();

        Model::preventLazyLoading(false);

        expect($many)->toBe($few);
    });

    it('never puts an amount on the list', function () {
        $repair = Repair::factory()->estimated(98_765_400)->create();

        Livewire::actingAs(staffUser())->test(Index::class)->assertSee($repair->number)->assertDontSee(Money::format(98_765_400));
    });
});

describe('show', function () {
    it('shows the costs to roles with repairs.costs and keeps amounts out of the Livewire state', function (Role $role) {
        $repair = Repair::factory()->estimated(31_415_900)->create(['final_amount' => 27_182_800]);

        $component = Livewire::actingAs(staffUser($role))->test(Show::class, ['repair' => $repair]);

        $component->assertSee(Money::format(31_415_900))->assertSee(Money::format(27_182_800));
        expect(json_encode($component->snapshot))->not->toContain('31415900')->not->toContain('27182800')->not->toContain('314 159');
    })->with([
        'admin' => [Role::Admin],
        'sales manager' => [Role::SalesManager],
        'service engineer' => [Role::ServiceEngineer],
    ]);

    it('hides the costs without repairs.costs, from the page and from the state', function () {
        $user = repairsEngineerWithoutCosts();
        $repair = Repair::factory()->estimated(31_415_900)->create(['final_amount' => 27_182_800]);

        $component = Livewire::actingAs($user)->test(Show::class, ['repair' => $repair]);

        $component->assertDontSee(Money::format(31_415_900))->assertDontSee(Money::format(27_182_800))->assertDontSeeHtml('data-costs');
        expect(json_encode($component->snapshot))->not->toContain('31415900')->not->toContain('27182800');

        $this->actingAs($user)->get(route('admin.repairs.show', $repair))->assertOk()->assertDontSee(Money::format(31_415_900))->assertDontSee(Money::format(27_182_800));
    });

    it('makes the sales manager read-only', function () {
        $repair = Repair::factory()->create();

        Livewire::actingAs(staffUser(Role::SalesManager))->test(Show::class, ['repair' => $repair])
            ->assertSeeHtml('data-read-only')->assertDontSeeHtml('data-activity-form')
            ->call('changeStatus', 'diagnosing')->assertForbidden();
    });

    it('moves a repair through the flow from the page and shows the guard message', function () {
        $admin = staffUser();
        $repair = Repair::factory()->create();

        Livewire::actingAs($admin)->test(Show::class, ['repair' => $repair])
            ->assertSeeHtml('data-status-to="diagnosing"')->assertDontSeeHtml('data-status-to="ready"')
            ->call('changeStatus', 'diagnosing');
        expect($repair->fresh()->status)->toBe(RepairStatus::Diagnosing);

        Livewire::actingAs($admin)->test(Show::class, ['repair' => $repair])
            ->call('changeStatus', 'awaiting_approval')
            ->assertDispatched('toast', fn (string $name, array $params): bool => ($params['type'] ?? null) === 'error');
        expect($repair->fresh()->status)->toBe(RepairStatus::Diagnosing);
    });

    it('cancels through the modal only with a note', function () {
        $admin = staffUser();
        $repair = Repair::factory()->create();

        Livewire::actingAs($admin)->test(Show::class, ['repair' => $repair])
            ->call('changeStatus', 'cancelled')
            ->call('cancelRepair')->assertHasErrors(['cancelNote'])
            ->set('cancelNote', 'Customer declined the estimate')->call('cancelRepair')->assertHasNoErrors();

        expect($repair->fresh()->status)->toBe(RepairStatus::Cancelled);
    });

    it('records the customer approval and hides the button afterwards', function () {
        $admin = staffUser();
        $repair = Repair::factory()->estimated(5_000_000)->status(RepairStatus::AwaitingApproval)->create();

        Livewire::actingAs($admin)->test(Show::class, ['repair' => $repair])
            ->assertSeeHtml('data-action="approve-estimate"')
            ->call('approveEstimate')->assertHasErrors(['approveNote'])
            ->set('approveNote', 'Paper estimate 3')->call('approveEstimate')->assertHasNoErrors();

        expect($repair->fresh()->estimate_approved_at)->not->toBeNull();

        Livewire::actingAs($admin)->test(Show::class, ['repair' => $repair->fresh()])->assertDontSeeHtml('data-action="approve-estimate"');
    });

    it('lets an engineer take and release, and the admin assign', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $admin = User::factory()->withRole(Role::Admin)->create();
        $repair = Repair::factory()->create();

        Livewire::actingAs($engineer)->test(Show::class, ['repair' => $repair])->assertDontSeeHtml('data-assign')->call('take');
        expect($repair->fresh()->assigned_to_id)->toBe($engineer->id);

        Livewire::actingAs($engineer)->test(Show::class, ['repair' => $repair])->call('release');
        expect($repair->fresh()->assigned_to_id)->toBeNull();

        Livewire::actingAs($engineer)->test(Show::class, ['repair' => $repair])->set('assignTo', (string) $engineer->id)->call('assign')->assertForbidden();

        Livewire::actingAs($admin)->test(Show::class, ['repair' => $repair])->assertSeeHtml('data-assign')->set('assignTo', (string) $engineer->id)->call('assign');
        expect($repair->fresh()->assigned_to_id)->toBe($engineer->id);
    });

    it('shows the timeline without amounts', function () {
        $admin = staffUser();
        $repair = Repair::factory()->estimated(31_415_900)->create();

        Livewire::actingAs($admin)->test(Show::class, ['repair' => $repair])->set('activityBody', 'Customer called')->call('addActivity')->assertHasNoErrors();
        $repair->activities()->create(['type' => ActivityType::Repair, 'meta' => ['event' => 'estimate_changed'], 'created_at' => now()]);

        Livewire::actingAs($admin)->test(Show::class, ['repair' => $repair])->assertSee('Customer called')->assertSee(__('repairs.timeline.estimate_changed'));
    });

    it('does not run N+1 on the page', function () {
        $admin = staffUser();
        $repair = Repair::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        foreach (range(1, 12) as $i) {
            $repair->activities()->create(['user_id' => User::factory()->create()->id, 'type' => ActivityType::Note, 'body' => "n{$i}", 'created_at' => now()]);
        }

        Model::preventLazyLoading();
        $queries = countQueries(fn () => Livewire::actingAs($admin)->test(Show::class, ['repair' => $repair])->html());
        Model::preventLazyLoading(false);

        expect($queries)->toBeLessThan(40);
    });
});

describe('form', function () {
    it('takes a device in as an engineer: numbered, assigned to them, no customer created', function () {
        $engineer = staffUser(Role::ServiceEngineer);

        Livewire::actingAs($engineer)->test(Form::class)
            ->assertSet('form.assigned_to_id', (string) $engineer->id)
            ->set('form.contact_name', 'Walk In')
            ->set('form.contact_phone', '90 555 44 33')
            ->set('form.problem', 'No power')
            ->set('form.intake_condition', 'Clean')
            ->set('form.estimate_amount', '150 000')
            ->call('save')->assertHasNoErrors();

        $repair = Repair::query()->firstOrFail();

        expect($repair->assigned_to_id)->toBe($engineer->id)
            ->and($repair->customer_id)->toBeNull()
            ->and($repair->contact_phone)->toBe('+998905554433')
            ->and($repair->estimate_amount)->toBe(15_000_000)
            ->and(Customer::query()->count())->toBe(0);
    });

    it('validates required fields, the warranty reference and the amount cap', function () {
        Livewire::actingAs(staffUser())->test(Form::class)
            ->call('save')->assertHasErrors(['form.contact_name', 'form.contact_phone', 'form.problem', 'form.intake_condition'])
            ->set('form.coverage', 'warranty')->call('save')->assertHasErrors(['form.warranty_reference'])
            ->set('form.estimate_amount', '99 999 999 999 999')->call('save')->assertHasErrors(['form.estimate_amount'])
            ->set('form.problem', str_repeat('a', 5001))->call('save')->assertHasErrors(['form.problem']);
    });

    it('never holds amounts for a user without repairs.costs and refuses injected ones', function () {
        $user = repairsEngineerWithoutCosts();
        $repair = Repair::factory()->assignedTo($user)->estimated(31_415_900)->create(['final_amount' => 27_182_800]);

        $component = Livewire::actingAs($user)->test(Form::class, ['repair' => $repair])
            ->assertSet('form.estimate_amount', '')->assertSet('form.final_amount', '')
            ->assertDontSeeHtml('data-costs');

        expect(json_encode($component->snapshot))->not->toContain('31415900')->not->toContain('314 159');

        $component->set('form.estimate_amount', '1 000')->call('save')->assertHasErrors(['form.estimate_amount']);
        expect($repair->fresh()->estimate_amount)->toBe(31_415_900);

        $component->set('form.estimate_amount', '')->set('form.problem', 'Edited')->call('save')->assertHasNoErrors();
        expect($repair->fresh()->estimate_amount)->toBe(31_415_900)->and($repair->fresh()->problem)->toBe('Edited');
    });

    it('fills the amounts for a user with repairs.costs and saves them as tiyin', function () {
        $admin = staffUser();
        $repair = Repair::factory()->estimated(31_415_900)->create();

        Livewire::actingAs($admin)->test(Form::class, ['repair' => $repair])
            ->assertSet('form.estimate_amount', '314 159')
            ->set('form.estimate_amount', '400 000')->call('save')->assertHasNoErrors();

        expect($repair->fresh()->estimate_amount)->toBe(40_000_000);
    });

    it('moves to warranty only with a reference and then logs it', function () {
        $admin = staffUser();
        $repair = Repair::factory()->create();

        Livewire::actingAs($admin)->test(Form::class, ['repair' => $repair])
            ->set('form.coverage', 'warranty')->call('save')->assertHasErrors(['form.warranty_reference'])
            ->set('form.warranty_reference', 'Invoice 77')->call('save')->assertHasNoErrors();

        expect($repair->fresh()->coverage->value)->toBe('warranty')
            ->and($repair->activities()->where('type', ActivityType::Repair)->count())->toBe(1);
    });

    it('picks a customer and a contact of that customer only', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $customer = Customer::factory()->create(['name' => 'Acme MChJ', 'phone' => '+998911112233']);
        $contact = Contact::factory()->for($customer)->create(['name' => 'Dilnoza', 'phone' => '+998933334455']);
        $stranger = Contact::factory()->for(Customer::factory()->create())->create();

        Livewire::actingAs($engineer)->test(Form::class)
            ->set('customerSearch', 'Acme')->assertSee('Acme MChJ')
            ->call('pickCustomer', $customer->id)
            ->assertSet('form.contact_name', 'Acme MChJ')
            ->set('form.contact_id', (string) $contact->id)
            ->assertSet('form.contact_name', 'Dilnoza')
            ->set('form.problem', 'p')->set('form.intake_condition', 'c')
            ->set('form.contact_id', (string) $stranger->id)->call('save')->assertHasErrors(['form.contact_id']);
    });

    it('sends a closed repair back to its page and refuses a stale engineer', function () {
        $admin = staffUser();
        $closed = Repair::factory()->status(RepairStatus::Cancelled)->create();

        Livewire::actingAs($admin)->test(Form::class, ['repair' => $closed])->assertRedirect(route('admin.repairs.show', $closed));
    });

    it('lets only the admin pick an engineer on intake', function () {
        $admin = staffUser();
        $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
        $other = User::factory()->withRole(Role::ServiceEngineer)->create();

        expect(Livewire::actingAs($admin)->test(Form::class)->instance()->assigneeOptions)->toHaveKey($engineer->id);

        $self = staffUser(Role::ServiceEngineer);
        Livewire::actingAs($self)->test(Form::class)
            ->set('form.assigned_to_id', (string) $other->id)
            ->set('form.contact_name', 'A')->set('form.contact_phone', '90 123 45 67')->set('form.problem', 'p')->set('form.intake_condition', 'c')
            ->call('save')->assertHasErrors(['form.assigned_to_id']);

        expect(Repair::query()->count())->toBe(0);
    });
});
