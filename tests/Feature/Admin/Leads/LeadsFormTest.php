<?php

use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Enums\Role;
use App\Livewire\Admin\Leads\Form;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\User;
use Livewire\Livewire;

it('opens the create screen for the CRM roles only', function (Role $role, int $status) {
    $this->actingAs(staffUser($role))->get(route('admin.leads.create'))->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'sales manager' => [Role::SalesManager, 200],
    'service engineer' => [Role::ServiceEngineer, 200],
    'content manager' => [Role::ContentManager, 403],
]);

it('opens the edit screen only for leads the user may change', function (Role $role, string $lead, int $status) {
    $user = staffUser($role);
    $colleague = User::factory()->withRole(Role::SalesManager)->create();

    $record = match ($lead) {
        'own sales' => Lead::factory()->assignedTo($user)->create(),
        'other sales' => Lead::factory()->assignedTo($colleague)->create(),
        'service' => Lead::factory()->serviceRequest()->create(),
    };

    $this->actingAs($user)->get(route('admin.leads.edit', $record))->assertStatus($status);
})->with([
    'admin: other sales' => [Role::Admin, 'other sales', 200],
    'sales: own' => [Role::SalesManager, 'own sales', 200],
    'sales: other' => [Role::SalesManager, 'other sales', 403],
    'sales: service' => [Role::SalesManager, 'service', 403],
    'engineer: service' => [Role::ServiceEngineer, 'service', 200],
    'engineer: other sales' => [Role::ServiceEngineer, 'other sales', 403],
]);

it('creates a phone lead with items and the user as manager', function () {
    $sales = staffUser(Role::SalesManager);
    $product = Product::factory()->create(['sku' => 'ZB-ZD421', 'name' => ['uz' => 'Zebra ZD421']]);

    Livewire::actingAs($sales)->test(Form::class)
        ->assertSet('form.manager_id', (string) $sales->id)
        ->set('form.type', 'quote')
        ->set('form.source', 'telegram')
        ->set('form.name', 'Bekzod')
        ->set('form.phone', '90 123 45 67')
        ->set('form.company', 'Mega Savdo')
        ->set('form.estimated_amount', '1 250 000')
        ->set('productSearch', 'zd421')
        ->assertSee('ZB-ZD421')
        ->call('addProduct', $product->id)
        ->set('form.items.0.qty', 3)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.leads.show', Lead::query()->sole()));

    $lead = Lead::query()->with('items')->sole();

    expect($lead->source)->toBe(LeadSource::Telegram)
        ->and($lead->type)->toBe(LeadType::Quote)
        ->and($lead->phone)->toBe('+998901234567')
        ->and($lead->manager_id)->toBe($sales->id)
        ->and($lead->created_by_id)->toBe($sales->id)
        ->and($lead->estimated_amount)->toBe(125_000_000)
        ->and($lead->items->sole()->sku)->toBe('ZB-ZD421')
        ->and($lead->items->sole()->qty)->toBe(3);
});

it('validates the required fields and the phone', function () {
    Livewire::actingAs(staffUser())->test(Form::class)
        ->set('form.phone', '12345')
        ->set('form.inn', '12')
        ->set('form.estimated_amount', '12abc')
        ->call('save')
        ->assertHasErrors(['form.name' => 'required', 'form.phone', 'form.inn', 'form.estimated_amount']);

    expect(Lead::query()->count())->toBe(0);
});

it('offers only the types the user works with', function (Role $role, array $types) {
    Livewire::actingAs(staffUser($role))->test(Form::class)
        ->assertSet('typeOptions', array_map(fn (string $type): LeadType => LeadType::from($type), $types));
})->with([
    'admin' => [Role::Admin, ['callback', 'quote', 'service_request']],
    'sales manager' => [Role::SalesManager, ['callback', 'quote']],
    'service engineer' => [Role::ServiceEngineer, ['service_request']],
]);

it('forbids a service engineer to save a sales lead', function () {
    Livewire::actingAs(staffUser(Role::ServiceEngineer))->test(Form::class)
        ->assertSet('form.type', 'service_request')
        ->set('form.type', 'quote')
        ->set('form.name', 'A')
        ->set('form.phone', '+998901234567')
        ->call('save')
        ->assertForbidden();

    expect(Lead::query()->count())->toBe(0);
});

it('offers managers per permission and refuses a manager outside the list', function () {
    $sales = staffUser(Role::SalesManager);
    $colleague = User::factory()->withRole(Role::SalesManager)->create();
    $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();

    $component = Livewire::actingAs($sales)->test(Form::class);

    expect(array_keys($component->get('managerOptions')))->toEqualCanonicalizing([$sales->id, $colleague->id]);

    $component->set('form.name', 'A')
        ->set('form.phone', '+998901234567')
        ->set('form.manager_id', (string) $engineer->id)
        ->call('save')
        ->assertHasErrors(['form.manager_id']);

    $component->set('form.manager_id', (string) $colleague->id)->call('save')->assertHasNoErrors();

    expect(Lead::query()->sole()->manager_id)->toBe($colleague->id);

    $engineerForm = Livewire::actingAs($engineer)->test(Form::class);

    expect(array_keys($engineerForm->get('managerOptions')))->toBe([$engineer->id]);
});

it('warns live about a recent lead with the same phone and the matched customer', function () {
    $sales = staffUser(Role::SalesManager);
    $mine = Lead::factory()->assignedTo($sales)->create(['phone' => '+998901234567']);
    Lead::factory()->assignedTo(User::factory()->withRole(Role::SalesManager)->create())->create(['phone' => '+998935554433']);
    Customer::factory()->create(['phone' => '+998901234567', 'name' => 'Mega Savdo MChJ']);

    Livewire::actingAs($sales)->test(Form::class)
        ->assertDontSeeHtml('data-duplicate-warning')
        ->set('form.phone', '90 123 45 67')
        ->assertSeeHtml('data-duplicate-warning')
        ->assertSee($mine->reference())
        ->assertSee(__('crm.leads.customer_match', ['name' => 'Mega Savdo MChJ']))
        ->set('form.phone', '93 555 44 33')
        ->assertSee(__('crm.leads.duplicate_hidden'))
        ->assertDontSee($mine->reference());
});

it('flags hidden duplicates correctly', function (int $visibleCount, bool $hiddenExists, bool $expected) {
    $sales = staffUser(Role::SalesManager);
    Lead::factory()->count($visibleCount)->assignedTo($sales)->create(['phone' => '+998901234567']);

    if ($hiddenExists) {
        Lead::factory()->assignedTo(User::factory()->withRole(Role::SalesManager)->create())->create(['phone' => '+998901234567']);
    }

    $duplicates = Livewire::actingAs($sales)->test(Form::class)
        ->set('form.phone', '+998901234567')
        ->get('duplicates');

    expect($duplicates['hidden'])->toBe($expected)
        ->and($duplicates['visible'])->toHaveCount(min($visibleCount, Form::DUPLICATES_SHOWN));
})->with([
    'more than five visible, nothing hidden' => [7, false, false],
    'more than five visible plus another manager lead' => [7, true, true],
    'only another manager lead' => [0, true, true],
]);

it('rejects tampered items', function (array $item, string $error) {
    $sales = staffUser(Role::SalesManager);

    Livewire::actingAs($sales)->test(Form::class)
        ->set('form.name', 'A')
        ->set('form.phone', '+998901234567')
        ->set('form.items', [$item])
        ->call('save')
        ->assertHasErrors([$error]);

    expect(Lead::query()->count())->toBe(0);
})->with([
    'unknown key' => [['id' => null, 'product_id' => 1, 'qty' => 1, 'note' => '', 'price' => 1], 'form.items.0'],
    'neither id nor product' => [['id' => null, 'product_id' => null, 'qty' => 1, 'note' => ''], 'form.items.0.product_id'],
    'non-integer product' => [['id' => null, 'product_id' => 'x', 'qty' => 1, 'note' => ''], 'form.items.0.product_id'],
    'missing product' => [['id' => null, 'product_id' => 999999, 'qty' => 1, 'note' => ''], 'form.items'],
    'another lead item' => [['id' => 999999, 'product_id' => null, 'qty' => 1, 'note' => ''], 'form.items'],
]);

it('refuses the checkout type by hand', function () {
    Livewire::actingAs(staffUser())->test(Form::class)
        ->set('form.type', 'checkout')
        ->set('form.name', 'A')
        ->set('form.phone', '+998901234567')
        ->call('save')
        ->assertHasErrors(['form.type']);

    expect(Lead::query()->count())->toBe(0);
});

it('forbids a sales manager to turn their own lead into a service request', function () {
    $sales = staffUser(Role::SalesManager);
    $lead = Lead::factory()->manual()->assignedTo($sales)->create();

    Livewire::actingAs($sales)->test(Form::class, ['lead' => $lead])
        ->set('form.type', 'service_request')
        ->call('save')
        ->assertForbidden();

    expect($lead->fresh()->type)->toBe(LeadType::Callback);
});

it('updates a lead and keeps the manager', function () {
    $sales = staffUser(Role::SalesManager);
    $lead = Lead::factory()->manual()->assignedTo($sales)->create(['name' => 'Old']);

    Livewire::actingAs($sales)->test(Form::class, ['lead' => $lead])
        ->assertSet('form.name', 'Old')
        ->set('form.name', 'New name')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.leads.show', $lead));

    expect($lead->fresh())->name->toBe('New name')->manager_id->toBe($sales->id);
});
