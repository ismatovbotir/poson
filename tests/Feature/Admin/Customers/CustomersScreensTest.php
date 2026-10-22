<?php

use App\Enums\LeadStatus;
use App\Enums\PriceLevel;
use App\Enums\Role;
use App\Livewire\Admin\Customers\Form;
use App\Livewire\Admin\Customers\Index;
use App\Livewire\Admin\Customers\Show;
use App\Livewire\Admin\Leads\Show as LeadShow;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\User;
use Livewire\Livewire;

it('opens the customer screens per role', function (Role $role, int $index, int $create, int $show) {
    $user = staffUser($role);
    $customer = Customer::factory()->create();

    $this->actingAs($user)->get(route('admin.customers.index'))->assertStatus($index);
    $this->actingAs($user)->get(route('admin.customers.create'))->assertStatus($create);
    $this->actingAs($user)->get(route('admin.customers.show', $customer))->assertStatus($show);
})->with([
    'admin' => [Role::Admin, 200, 200, 200],
    'sales manager' => [Role::SalesManager, 200, 200, 200],
    'service engineer' => [Role::ServiceEngineer, 200, 403, 200],
    'content manager' => [Role::ContentManager, 403, 403, 403],
]);

it('opens the edit screen for own and unassigned customers only', function (Role $role, string $owner, int $status) {
    $user = staffUser($role);
    $customer = match ($owner) {
        'own' => Customer::factory()->managedBy($user)->create(),
        'free' => Customer::factory()->create(),
        'other' => Customer::factory()->managedBy(User::factory()->withRole(Role::SalesManager)->create())->create(),
    };

    $this->actingAs($user)->get(route('admin.customers.edit', $customer))->assertStatus($status);
})->with([
    'sales: own' => [Role::SalesManager, 'own', 200],
    'sales: free' => [Role::SalesManager, 'free', 200],
    'sales: other' => [Role::SalesManager, 'other', 403],
    'engineer: free' => [Role::ServiceEngineer, 'free', 403],
    'admin: other' => [Role::Admin, 'other', 200],
]);

it('searches by name, INN and customer or contact phone, and filters', function () {
    $admin = staffUser();
    $sales = User::factory()->withRole(Role::SalesManager)->create();
    Customer::factory()->managedBy($sales)->priceLevel(PriceLevel::Dealer)->create(['name' => 'Alpha Trade', 'inn' => '305123456', 'phone' => '+998901234567']);
    $beta = Customer::factory()->individual()->create(['name' => 'Beta Person', 'phone' => null]);
    Contact::factory()->for($beta)->create(['phone' => '+998935554433']);

    $see = function (array $set) use ($admin): array {
        $component = Livewire::actingAs($admin)->test(Index::class);

        foreach ($set as $property => $value) {
            $component->set($property, $value);
        }

        return array_values(array_filter(['Alpha Trade', 'Beta Person'], fn (string $name): bool => str_contains($component->html(), $name)));
    };

    expect($see([]))->toBe(['Alpha Trade', 'Beta Person'])
        ->and($see(['search' => 'alpha']))->toBe(['Alpha Trade'])
        ->and($see(['search' => '305123456']))->toBe(['Alpha Trade'])
        ->and($see(['search' => '90 123']))->toBe(['Alpha Trade'])
        ->and($see(['search' => '555 44 33']))->toBe(['Beta Person'])
        ->and($see(['manager' => (string) $sales->id]))->toBe(['Alpha Trade'])
        ->and($see(['manager' => 'none']))->toBe(['Beta Person'])
        ->and($see(['type' => 'individual']))->toBe(['Beta Person'])
        ->and($see(['priceLevel' => 'dealer']))->toBe(['Alpha Trade']);
});

it('lists customers without N+1 queries', function () {
    $admin = staffUser();
    $render = fn () => Livewire::actingAs($admin)->test(Index::class);

    Customer::factory()->managedBy($admin)->count(3)->has(Contact::factory()->primary())->create();
    $render();
    $few = countQueries($render);

    Customer::factory()->managedBy(User::factory()->withRole(Role::SalesManager)->create())->count(10)->has(Contact::factory()->primary())->create();
    $render();

    expect(countQueries($render))->toBe($few);
});

it('creates a customer with contacts as a sales manager, without the price level field', function () {
    $sales = staffUser(Role::SalesManager);

    Livewire::actingAs($sales)->test(Form::class)
        ->assertSet('form.manager_id', (string) $sales->id)
        ->assertSeeHtml('data-price-level-readonly')
        ->set('form.name', 'Mega Savdo')
        ->set('form.inn', '305123456')
        ->set('form.phone', '90 123 45 67')
        ->set('form.contacts.0.name', 'Bekzod')
        ->set('form.contacts.0.phone', '93 555 44 33')
        ->call('addContact')
        ->set('form.contacts.1.name', 'Ilhom')
        ->set('form.primary', 1)
        ->set('form.price_level', 'wholesale')
        ->call('save')
        ->assertHasNoErrors();

    $customer = Customer::query()->sole();

    expect($customer->price_level)->toBe(PriceLevel::Retail)
        ->and($customer->manager_id)->toBe($sales->id)
        ->and($customer->contacts()->where('is_primary', true)->value('name'))->toBe('Ilhom');
});

it('validates the INN and tampered contacts in the form', function () {
    Customer::factory()->create(['inn' => '305123456']);

    Livewire::actingAs(staffUser())->test(Form::class)
        ->set('form.name', 'X')
        ->set('form.inn', '1234')
        ->set('form.contacts', [['id' => null, 'name' => '', 'evil' => 1]])
        ->call('save')
        ->assertHasErrors(['form.inn' => 'digits', 'form.contacts.0']);

    Livewire::actingAs(staffUser())->test(Form::class)
        ->set('form.name', 'X')
        ->set('form.inn', '305123456')
        ->set('form.contacts', [])
        ->call('save')
        ->assertHasErrors(['form.inn']);

    expect(Customer::query()->count())->toBe(1);
});

it('rejects a tampered manager in the form', function () {
    $sales = staffUser(Role::SalesManager);
    $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
    $customer = Customer::factory()->managedBy($sales)->create();

    Livewire::actingAs($sales)->test(Form::class, ['customer' => $customer])
        ->assertSet('form.price_level', '')
        ->set('form.manager_id', (string) $engineer->id)
        ->call('save')
        ->assertHasErrors(['form.manager_id']);

    expect($customer->fresh()->manager_id)->toBe($sales->id);
});

it('keeps a sales manager’s unassigned customer unassigned through the form', function () {
    $sales = staffUser(Role::SalesManager);
    $customer = Customer::factory()->create(['name' => 'Free']);

    Livewire::actingAs($sales)->test(Form::class, ['customer' => $customer])
        ->assertSet('form.manager_id', '')
        ->set('form.name', 'Free Co')
        ->call('save')
        ->assertHasNoErrors();

    expect($customer->fresh())->name->toBe('Free Co')->manager_id->toBeNull();
});

it('maps a duplicate INN in the convert modal to the INN field', function () {
    $sales = staffUser(Role::SalesManager);
    Customer::factory()->create(['inn' => '305123456', 'phone' => '+998977777777']);
    $lead = Lead::factory()->assignedTo($sales)->create(['phone' => '+998901234567']);

    Livewire::actingAs($sales)->test(LeadShow::class, ['lead' => $lead])
        ->call('openConvert')
        ->set('convertTarget', 'new')
        ->set('convertInn', '305123456')
        ->call('convert')
        ->assertHasErrors(['convertInn']);

    expect($lead->fresh()->status)->toBe(LeadStatus::New);
});

it('lets the admin change the price level and the manager', function () {
    $admin = staffUser();
    $sales = User::factory()->withRole(Role::SalesManager)->create();
    $customer = Customer::factory()->create();

    Livewire::actingAs($admin)->test(Form::class, ['customer' => $customer])
        ->set('form.price_level', 'dealer')
        ->set('form.manager_id', (string) $sales->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.customers.show', $customer));

    expect($customer->fresh())->price_level->toBe(PriceLevel::Dealer)->manager_id->toBe($sales->id);
});

it('forbids a sales manager to save another manager’s customer and an engineer to save any', function () {
    $sales = staffUser(Role::SalesManager);
    $other = Customer::factory()->managedBy(User::factory()->withRole(Role::SalesManager)->create())->create();

    Livewire::actingAs($sales)->test(Form::class, ['customer' => $other])->assertForbidden();
    Livewire::actingAs(User::factory()->withRole(Role::ServiceEngineer)->create())->test(Form::class, ['customer' => Customer::factory()->create()])->assertForbidden();
});

it('shows requisites, contacts, visible leads, the hidden count and later tabs', function () {
    $sales = staffUser(Role::SalesManager);
    $customer = Customer::factory()->priceLevel(PriceLevel::Wholesale)->create(['inn' => '305123456']);
    Contact::factory()->for($customer)->primary()->create(['name' => 'Bekzod']);
    $mine = Lead::factory()->forCustomer($customer)->assignedTo($sales)->create();
    Lead::factory()->forCustomer($customer)->assignedTo(User::factory()->withRole(Role::SalesManager)->create())->count(2)->create();

    Livewire::actingAs($sales)->test(Show::class, ['customer' => $customer])
        ->assertSee('305123456')
        ->assertSee('Bekzod')
        ->assertSeeHtml('data-price-level="wholesale"')
        ->assertSee($mine->reference())
        ->assertSee(trans_choice('crm.customers.hidden_leads', 2, ['count' => 2]))
        ->set('tab', 'quotes')
        ->assertSeeHtml('data-later="quotes"')
        ->set('tab', 'history')
        ->assertSee(__('crm.customers.no_history'));
});

it('shows a customer without N+1 queries', function () {
    $admin = staffUser();
    $customer = Customer::factory()->create();
    $render = fn () => Livewire::actingAs($admin)->test(Show::class, ['customer' => $customer]);

    Lead::factory()->forCustomer($customer)->assignedTo($admin)->count(2)->has(LeadItem::factory(), 'items')->create();
    Contact::factory()->for($customer)->count(2)->create();
    $render();
    $few = countQueries($render);

    Lead::factory()->forCustomer($customer)->assignedTo(User::factory()->withRole(Role::SalesManager)->create())->count(8)->has(LeadItem::factory(), 'items')->create();
    Contact::factory()->for($customer)->count(5)->create();
    $render();

    expect(countQueries($render))->toBe($few);
});

it('lets only the admin delete, and not while leads exist', function () {
    $customer = Customer::factory()->create();
    Lead::factory()->forCustomer($customer)->create();

    Livewire::actingAs(staffUser(Role::SalesManager))->test(Show::class, ['customer' => $customer])->call('delete')->assertForbidden();

    $admin = User::factory()->withRole(Role::Admin)->create();

    Livewire::actingAs($admin)->test(Show::class, ['customer' => $customer])
        ->call('delete')
        ->assertDispatched('toast', type: 'error')
        ->assertNoRedirect();

    $free = Customer::factory()->create();

    Livewire::actingAs($admin)->test(Show::class, ['customer' => $free])
        ->call('delete')
        ->assertRedirect(route('admin.customers.index'));

    expect(Customer::query()->pluck('id')->all())->toBe([$customer->id]);
});

it('converts a lead from the lead page: preselects an INN match, picks among several, or creates new', function () {
    $sales = staffUser(Role::SalesManager);
    $byInn = Customer::factory()->create(['inn' => '305123456']);
    $byPhone = Customer::factory()->create(['phone' => '+998901234567']);
    $lead = Lead::factory()->assignedTo($sales)->create(['inn' => '305123456', 'phone' => '+998901234567']);

    Livewire::actingAs($sales)->test(LeadShow::class, ['lead' => $lead])
        ->assertSeeHtml('data-action="convert"')
        ->assertSet('convertOpen', false)
        ->assertSet('customerMatches', [])
        ->call('openConvert')
        ->assertDispatched('lead-convert-open')
        ->assertSet('convertOpen', true)
        ->assertSet('convertTarget', (string) $byInn->id)
        ->assertSeeHtml('data-match="'.$byPhone->id.'"')
        ->set('convertTarget', (string) $byPhone->id)
        ->call('convert')
        ->assertHasNoErrors()
        ->assertDispatched('lead-convert-saved');

    expect($lead->fresh())->status->toBe(LeadStatus::Converted)->customer_id->toBe($byPhone->id);

    // Several phone matches, no INN: the manager has to pick.
    $second = Customer::factory()->create();
    Contact::factory()->for($second)->create(['phone' => '+998935554433']);
    Customer::factory()->create(['phone' => '+998935554433']);
    $other = Lead::factory()->assignedTo($sales)->create(['phone' => '+998935554433', 'company' => 'Fresh Co']);

    $component = Livewire::actingAs($sales)->test(LeadShow::class, ['lead' => $other])
        ->call('openConvert')
        ->assertSet('convertTarget', '')
        ->call('convert')
        ->assertHasErrors(['convertTarget']);

    $component->set('convertTarget', 'new')->set('convertName', 'Fresh Co MChJ')->call('convert')->assertHasNoErrors();

    expect($other->fresh()->customer->name)->toBe('Fresh Co MChJ');
});

it('does not offer conversion on a read-only or closed lead, nor new customers to an engineer', function () {
    $sales = staffUser(Role::SalesManager);

    Livewire::actingAs($sales)->test(LeadShow::class, ['lead' => Lead::factory()->serviceRequest()->create()])
        ->assertDontSeeHtml('data-action="convert"')
        ->call('openConvert')
        ->assertForbidden();

    Livewire::actingAs($sales)->test(LeadShow::class, ['lead' => Lead::factory()->serviceRequest()->create()])
        ->set('convertTarget', 'new')
        ->call('convert')
        ->assertForbidden();

    Livewire::actingAs($sales)->test(LeadShow::class, ['lead' => Lead::factory()->lost()->assignedTo($sales)->create()])
        ->assertDontSeeHtml('data-action="convert"')
        ->set('convertTarget', 'new')
        ->call('convert')
        ->assertForbidden();

    $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
    $lead = Lead::factory()->serviceRequest()->assignedTo($engineer)->create();

    Livewire::actingAs($engineer)->test(LeadShow::class, ['lead' => $lead])
        ->call('openConvert')
        ->assertDontSeeHtml('data-match="new"')
        ->set('convertTarget', 'new')
        ->call('convert')
        ->assertForbidden();

    expect(Customer::query()->count())->toBe(0);
});
