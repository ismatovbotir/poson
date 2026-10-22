<?php

use App\Actions\Customers\ConvertLeadToCustomer;
use App\Actions\Customers\DeleteCustomer;
use App\Actions\Customers\FindCustomerMatches;
use App\Actions\Customers\SaveCustomer;
use App\Actions\Leads\ChangeLeadStatus;
use App\Data\Customers\ContactData;
use App\Data\Customers\CustomerData;
use App\Data\Customers\CustomerMatch;
use App\Enums\ActivityType;
use App\Enums\CustomerType;
use App\Enums\LeadStatus;
use App\Enums\PriceLevel;
use App\Enums\Role;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 */
function customerData(array $overrides = []): CustomerData
{
    return new CustomerData(...array_merge(['type' => CustomerType::Company, 'name' => 'Mega Savdo MChJ'], $overrides));
}

/**
 * The validation errors an action throws (empty when it passes).
 *
 * @return array<string, list<string>>
 */
function actionErrors(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

describe('SaveCustomer', function () {
    it('creates a customer with normalized phones, one primary contact and a created activity', function () {
        $sales = staffUser(Role::SalesManager);

        $customer = app(SaveCustomer::class)->handle(null, customerData([
            'inn' => '305123456',
            'phone' => '90 123 45 67',
            'email' => 'Info@Mega.UZ',
            'managerId' => $sales->id,
            'contacts' => [
                new ContactData('Bekzod', '93 555 44 33'),
                new ContactData('Ilhom', '+998 97 700 00 01', isPrimary: true),
                new ContactData('Gulnora', isPrimary: true),
            ],
        ]), $sales);

        $contacts = $customer->contacts()->orderBy('id')->get();

        expect($customer->phone)->toBe('+998901234567')
            ->and($customer->email)->toBe('info@mega.uz')
            ->and($customer->price_level)->toBe(PriceLevel::Retail)
            ->and($customer->manager_id)->toBe($sales->id)
            ->and($contacts->pluck('phone')->all())->toBe(['+998935554433', '+998977000001', null])
            ->and($contacts->where('is_primary', true)->pluck('name')->all())->toBe(['Ilhom'])
            ->and($customer->activities()->sole()->type)->toBe(ActivityType::Created);
    });

    it('makes the first contact primary when none is flagged', function () {
        $customer = app(SaveCustomer::class)->handle(null, customerData(['contacts' => [new ContactData('A'), new ContactData('B')]]), staffUser());

        expect($customer->contacts()->where('is_primary', true)->pluck('name')->all())->toBe(['A']);
    });

    it('validates the INN format and uniqueness', function () {
        $admin = staffUser();
        $existing = Customer::factory()->create(['inn' => '305123456']);

        expect(actionErrors(fn () => app(SaveCustomer::class)->handle(null, customerData(['inn' => '12345']), $admin)))->toHaveKey('inn')
            ->and(actionErrors(fn () => app(SaveCustomer::class)->handle(null, customerData(['inn' => '30512345a']), $admin)))->toHaveKey('inn')
            ->and(actionErrors(fn () => app(SaveCustomer::class)->handle(null, customerData(['inn' => '305123456']), $admin)))->toBe(['inn' => [__('crm.customers.errors.inn_taken')]])
            ->and(actionErrors(fn () => app(SaveCustomer::class)->handle($existing, customerData(['inn' => '305123456']), $admin)))->toBe([])
            ->and(actionErrors(fn () => app(SaveCustomer::class)->handle(null, customerData(['phone' => '12345']), $admin)))->toHaveKey('phone');
    });

    it('lets only the admin change the price level', function () {
        $sales = staffUser(Role::SalesManager);
        $admin = User::factory()->withRole(Role::Admin)->create();
        $customer = Customer::factory()->managedBy($sales)->create();

        expect(actionErrors(fn () => app(SaveCustomer::class)->handle($customer, customerData(['priceLevel' => PriceLevel::Dealer, 'managerId' => $sales->id]), $sales)))->toHaveKey('price_level')
            ->and($customer->fresh()->price_level)->toBe(PriceLevel::Retail);

        app(SaveCustomer::class)->handle($customer, customerData(['priceLevel' => PriceLevel::Wholesale, 'managerId' => $sales->id]), $admin);

        expect($customer->fresh()->price_level)->toBe(PriceLevel::Wholesale);

        // Saving without a level keeps it.
        app(SaveCustomer::class)->handle($customer, customerData(['managerId' => $sales->id]), $sales);

        expect($customer->fresh()->price_level)->toBe(PriceLevel::Wholesale);
    });

    it('lets a sales manager hand an own customer to a sales colleague and logs it, never to a non-sales user', function () {
        $sales = staffUser(Role::SalesManager);
        $colleague = User::factory()->withRole(Role::SalesManager)->create();
        $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
        $customer = Customer::factory()->managedBy($sales)->create();

        expect(app(SaveCustomer::class)->managerCandidates($sales)->pluck('id')->all())->toEqualCanonicalizing([$sales->id, $colleague->id])
            ->and(actionErrors(fn () => app(SaveCustomer::class)->handle($customer, customerData(['managerId' => $engineer->id]), $sales)))->toHaveKey('manager_id');

        app(SaveCustomer::class)->handle($customer, customerData(['managerId' => $colleague->id]), $sales);

        expect($customer->fresh()->manager_id)->toBe($colleague->id)
            ->and($sales->can('update', $customer->fresh()))->toBeFalse()
            ->and($customer->activities()->where('type', ActivityType::Assignment)->sole()->meta)->toMatchArray([
                'from_id' => $sales->id, 'to_id' => $colleague->id, 'to_name' => $colleague->name,
            ]);
    });

    it('lets a sales manager leave an unassigned customer unassigned', function () {
        $sales = staffUser(Role::SalesManager);
        $customer = Customer::factory()->create();

        app(SaveCustomer::class)->handle($customer, customerData(['name' => 'Renamed']), $sales);

        expect($customer->fresh())->name->toBe('Renamed')->manager_id->toBeNull()
            ->and($customer->activities()->count())->toBe(0);
    });

    it('logs price level changes in the history', function () {
        $admin = staffUser();
        $customer = Customer::factory()->create();

        app(SaveCustomer::class)->handle($customer, customerData(['priceLevel' => PriceLevel::Dealer]), $admin);

        expect($customer->activities()->where('type', ActivityType::PriceLevelChange)->sole()->meta)->toBe(['from' => 'retail', 'to' => 'dealer']);
    });

    it('turns a duplicate INN from a concurrent save into the inn_taken error', function () {
        $admin = staffUser();
        $customer = Customer::factory()->create(['inn' => '305123456']);

        // Simulate the race: the uniqueness check passes, then the insert hits the unique index.
        Customer::saving(function (Customer $saving) use ($customer): void {
            if ($saving->inn === '305123457') {
                $saving->inn = $customer->inn;
            }
        });

        expect(actionErrors(fn () => app(SaveCustomer::class)->handle(null, customerData(['inn' => '305123457']), $admin)))
            ->toBe(['inn' => [__('crm.customers.errors.inn_taken')]])
            ->and(Customer::query()->count())->toBe(1);
    });

    it('keeps, updates and deletes contacts and refuses another customer’s contact', function () {
        $admin = staffUser();
        $customer = Customer::factory()->create();
        $kept = Contact::factory()->for($customer)->create(['name' => 'Old']);
        $dropped = Contact::factory()->for($customer)->create();
        $foreign = Contact::factory()->create();

        expect(actionErrors(fn () => app(SaveCustomer::class)->handle($customer, customerData(['contacts' => [new ContactData('X', id: $foreign->id)]]), $admin)))->toHaveKey('contacts');

        app(SaveCustomer::class)->handle($customer, customerData(['contacts' => [new ContactData('New name', id: $kept->id), new ContactData('Fresh')]]), $admin);

        expect($customer->contacts()->orderBy('id')->pluck('name')->all())->toBe(['New name', 'Fresh'])
            ->and(Contact::query()->find($dropped->id))->toBeNull()
            ->and($foreign->fresh()->customer_id)->not->toBe($customer->id);
    });
});

describe('DeleteCustomer', function () {
    it('is blocked while the customer has leads', function () {
        $customer = Customer::factory()->create();
        Lead::factory()->forCustomer($customer)->create();

        expect(actionErrors(fn () => app(DeleteCustomer::class)->handle($customer)))->toHaveKey('customer')
            ->and($customer->fresh())->not->toBeNull();
    });

    it('deletes the customer with contacts and activities', function () {
        $customer = Customer::factory()->create();
        Contact::factory()->for($customer)->create();
        $customer->activities()->create(['type' => ActivityType::Note, 'body' => 'x', 'created_at' => now()]);

        app(DeleteCustomer::class)->handle($customer);

        expect(Customer::query()->count())->toBe(0)
            ->and(Contact::query()->count())->toBe(0)
            ->and($customer->activities()->count())->toBe(0);
    });
});

describe('FindCustomerMatches', function () {
    it('returns the INN match first, then phone, then contact phone, each once', function () {
        $byInn = Customer::factory()->create(['inn' => '305123456', 'phone' => '+998977777777']);
        $byPhone = Customer::factory()->create(['phone' => '+998901234567']);
        $byContact = Customer::factory()->create();
        Contact::factory()->for($byContact)->create(['phone' => '+998901234567']);
        Contact::factory()->for($byPhone)->create(['phone' => '+998901234567']);

        $matches = app(FindCustomerMatches::class)->handle('90 123 45 67', '305123456');

        expect(array_map(fn (CustomerMatch $match): array => [$match->customer->id, $match->reason], $matches))->toBe([
            [$byInn->id, CustomerMatch::INN],
            [$byPhone->id, CustomerMatch::PHONE],
            [$byContact->id, CustomerMatch::CONTACT_PHONE],
        ])
            ->and(app(FindCustomerMatches::class)->handle(null, '12'))->toBe([]);
    });
});

describe('ConvertLeadToCustomer', function () {
    it('creates a new customer from the lead with its person as primary contact and the manager', function () {
        $sales = staffUser(Role::SalesManager);
        $lead = Lead::factory()->assignedTo($sales)->create(['name' => 'Bekzod', 'company' => 'Mega Savdo', 'phone' => '+998901234567', 'inn' => '305123456', 'email' => 'b@mega.uz']);

        $customer = app(ConvertLeadToCustomer::class)->handle($lead, null, $sales);
        $lead->refresh();

        expect($customer->name)->toBe('Mega Savdo')
            ->and($customer->type)->toBe(CustomerType::Company)
            ->and($customer->inn)->toBe('305123456')
            ->and($customer->manager_id)->toBe($sales->id)
            ->and($customer->contacts()->sole())->name->toBe('Bekzod')->phone->toBe('+998901234567')->is_primary->toBeTrue()
            ->and($lead->status)->toBe(LeadStatus::Converted)
            ->and($lead->customer_id)->toBe($customer->id)
            ->and($lead->closed_at)->not->toBeNull()
            ->and($lead->activities()->where('type', ActivityType::Conversion)->sole()->meta)->toMatchArray(['customer_id' => $customer->id, 'created' => true])
            ->and($customer->activities()->where('type', ActivityType::Conversion)->sole()->meta)->toBe(['lead_id' => $lead->id, 'created' => true]);
    });

    it('links an existing customer found by INN or phone and gives it the lead manager when it has none', function (string $by) {
        $sales = staffUser(Role::SalesManager);
        $customer = Customer::factory()->create($by === 'inn' ? ['inn' => '305123456'] : ['phone' => '+998901234567']);
        $lead = Lead::factory()->assignedTo($sales)->create(['phone' => '+998901234567', 'inn' => $by === 'inn' ? '305123456' : null]);

        $match = app(FindCustomerMatches::class)->handle($lead->phone, $lead->inn)[0];
        $result = app(ConvertLeadToCustomer::class)->handle($lead, $match->customer, $sales);

        expect($match->reason)->toBe($by)
            ->and($result->id)->toBe($customer->id)
            ->and(Customer::query()->count())->toBe(1)
            ->and($customer->fresh()->manager_id)->toBe($sales->id)
            ->and($customer->activities()->where('type', ActivityType::Assignment)->sole()->meta)->toMatchArray(['action' => 'auto', 'to_id' => $sales->id])
            ->and($lead->fresh())->status->toBe(LeadStatus::Converted)->customer_id->toBe($customer->id);
    })->with(['inn', 'phone']);

    it('adds the lead person as a contact of an existing customer when the phone is new, even without edit rights', function () {
        $sales = staffUser(Role::SalesManager);
        $owner = User::factory()->withRole(Role::SalesManager)->create();
        $customer = Customer::factory()->managedBy($owner)->create(['phone' => '+998977777777']);
        $lead = Lead::factory()->assignedTo($sales)->create(['name' => 'Bekzod', 'phone' => '+998901234567', 'email' => 'B@Mega.uz']);

        expect($sales->can('update', $customer))->toBeFalse();

        app(ConvertLeadToCustomer::class)->handle($lead, $customer, $sales);

        $contact = $customer->contacts()->sole();

        expect($contact)->name->toBe('Bekzod')->phone->toBe('+998901234567')->email->toBe('b@mega.uz')->is_primary->toBeTrue()
            ->and($customer->activities()->where('type', ActivityType::Conversion)->sole()->meta)->toMatchArray(['contact_id' => $contact->id, 'contact_name' => 'Bekzod']);

        // A known phone (the customer's own or a contact's) adds nothing; a second new one is not primary.
        app(ConvertLeadToCustomer::class)->handle(Lead::factory()->assignedTo($sales)->create(['phone' => '+998977777777']), $customer, $sales);
        app(ConvertLeadToCustomer::class)->handle(Lead::factory()->assignedTo($sales)->create(['phone' => '+998901234567']), $customer, $sales);
        app(ConvertLeadToCustomer::class)->handle(Lead::factory()->assignedTo($sales)->create(['name' => 'Ilhom', 'phone' => '+998935554433']), $customer, $sales);

        expect($customer->contacts()->orderBy('id')->get()->map(fn (Contact $c): array => [$c->name, $c->is_primary])->all())
            ->toBe([['Bekzod', true], ['Ilhom', false]]);
    });

    it('makes the converting sales user the manager of a new customer from an unassigned lead', function () {
        $sales = staffUser(Role::SalesManager);
        $admin = User::factory()->withRole(Role::Admin)->create();

        $mine = app(ConvertLeadToCustomer::class)->handle(Lead::factory()->create(['company' => 'A']), null, $sales);
        $assigned = app(ConvertLeadToCustomer::class)->handle(Lead::factory()->assignedTo($sales)->create(['company' => 'B']), null, $admin);

        expect($mine->manager_id)->toBe($sales->id)
            ->and($assigned->manager_id)->toBe($sales->id);
    });

    it('lets a service engineer convert a service request into an existing customer', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $customer = Customer::factory()->create(['phone' => '+998901234567']);
        $lead = Lead::factory()->serviceRequest()->assignedTo($engineer)->create(['phone' => '+998901234567']);

        expect($engineer->can('convert', $lead))->toBeTrue()
            ->and($engineer->can('create', Customer::class))->toBeFalse();

        app(ConvertLeadToCustomer::class)->handle($lead, $customer, $engineer);

        expect($lead->fresh())->status->toBe(LeadStatus::Converted)->customer_id->toBe($customer->id)
            ->and($customer->fresh()->manager_id)->toBeNull();
    });

    it('keeps the manager of a linked customer', function () {
        $sales = staffUser(Role::SalesManager);
        $owner = User::factory()->withRole(Role::SalesManager)->create();
        $customer = Customer::factory()->managedBy($owner)->create();
        $lead = Lead::factory()->assignedTo($sales)->create();

        app(ConvertLeadToCustomer::class)->handle($lead, $customer, $sales);

        expect($customer->fresh()->manager_id)->toBe($owner->id);
    });

    it('refuses a closed lead and an INN that is taken', function () {
        $admin = staffUser();
        Customer::factory()->create(['inn' => '305123456']);

        expect(actionErrors(fn () => app(ConvertLeadToCustomer::class)->handle(Lead::factory()->lost()->create(), null, $admin)))->toHaveKey('status');

        $lead = Lead::factory()->create(['inn' => '305123456']);

        expect(actionErrors(fn () => app(ConvertLeadToCustomer::class)->handle($lead, null, $admin)))->toHaveKey('inn')
            ->and($lead->fresh()->status)->toBe(LeadStatus::New)
            ->and(Customer::query()->count())->toBe(1);
    });

    it('is the only way to set converted', function () {
        $admin = staffUser();
        $lead = Lead::factory()->create();

        expect(actionErrors(fn () => app(ChangeLeadStatus::class)->handle($lead, LeadStatus::Converted, $admin)))->toHaveKey('status')
            ->and($lead->fresh()->status)->toBe(LeadStatus::New);

        app(ConvertLeadToCustomer::class)->handle($lead, null, $admin);

        expect($lead->fresh()->status)->toBe(LeadStatus::Converted);
    });
});
