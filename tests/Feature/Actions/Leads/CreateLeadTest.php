<?php

use App\Actions\Leads\CreateLead;
use App\Data\Leads\LeadData;
use App\Data\Leads\LeadItemData;
use App\Enums\ActivityType;
use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Enums\Role;
use App\Events\LeadCreated;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 */
function leadData(array $overrides = []): LeadData
{
    return new LeadData(...array_merge([
        'source' => LeadSource::Phone,
        'type' => LeadType::Callback,
        'name' => 'Aziz',
        'phone' => '90 123 45 67',
        'locale' => 'uz',
    ], $overrides));
}

function createLead(array $overrides = []): Lead
{
    return app(CreateLead::class)->handle(leadData($overrides));
}

it('normalizes the phone and rejects a non-Uzbek number', function () {
    expect(createLead()->phone)->toBe('+998901234567');

    createLead(['phone' => '12345']);
})->throws(ValidationException::class);

it('flags a lead with the same phone within 30 days as a duplicate of the original', function () {
    $original = createLead();
    $this->travel(10)->days();
    $second = createLead(['phone' => '+998 90 123-45-67']);
    $this->travel(10)->days();
    $third = createLead(['phone' => '998901234567']);

    expect($original->duplicate_of_id)->toBeNull()
        ->and($second->duplicate_of_id)->toBe($original->id)
        ->and($third->duplicate_of_id)->toBe($original->id);
});

it('does not flag a lead after the 30-day window or with another phone', function () {
    createLead();
    expect(createLead(['phone' => '+998901234568'])->duplicate_of_id)->toBeNull();

    $this->travel(Lead::DUPLICATE_WINDOW_DAYS + 1)->days();

    expect(createLead()->duplicate_of_id)->toBeNull();
});

it('matches a customer by its phone, then by a contact phone', function () {
    $customer = Customer::factory()->create(['phone' => '+998901234567']);
    $other = Customer::factory()->create();
    Contact::factory()->for($other)->create(['phone' => '+998935554433']);

    expect(createLead()->customer_id)->toBe($customer->id)
        ->and(createLead(['phone' => '93 555 44 33'])->customer_id)->toBe($other->id)
        ->and(createLead(['phone' => '+998977777777'])->customer_id)->toBeNull();
});

it('assigns a sales lead to the matched customer manager', function () {
    $manager = staffUser(Role::SalesManager);
    Customer::factory()->managedBy($manager)->create(['phone' => '+998901234567']);

    $lead = createLead(['type' => LeadType::Quote]);

    expect($lead->manager_id)->toBe($manager->id)
        ->and($lead->activities()->sole()->meta)->toMatchArray(['auto_assigned' => true, 'manager_id' => $manager->id]);
});

it('keeps the staff creator’s choice instead of auto-assigning', function () {
    $owner = staffUser(Role::SalesManager);
    $creator = User::factory()->withRole(Role::SalesManager)->create();
    Customer::factory()->managedBy($owner)->create(['phone' => '+998901234567']);

    expect(createLead(['type' => LeadType::Quote, 'createdById' => $creator->id])->manager_id)->toBeNull()
        ->and(createLead(['type' => LeadType::Quote, 'createdById' => $creator->id, 'managerId' => $creator->id])->manager_id)->toBe($creator->id)
        ->and(createLead(['type' => LeadType::Quote])->manager_id)->toBe($owner->id);
});

it('does not auto-assign service requests, inactive managers or people outside sales', function () {
    $manager = staffUser(Role::SalesManager);
    Customer::factory()->managedBy($manager)->create(['phone' => '+998901234567']);

    expect(createLead(['type' => LeadType::ServiceRequest])->manager_id)->toBeNull();

    $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
    Customer::factory()->managedBy($engineer)->create(['phone' => '+998901110000']);
    expect(createLead(['phone' => '+998901110000'])->manager_id)->toBeNull();

    $inactive = User::factory()->inactive()->withRole(Role::SalesManager)->create();
    Customer::factory()->managedBy($inactive)->create(['phone' => '+998901110001']);
    expect(createLead(['phone' => '+998901110001'])->manager_id)->toBeNull();
});

it('keeps a chosen manager and refuses one who cannot work with the type', function () {
    $sales = staffUser(Role::SalesManager);
    $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
    Customer::factory()->managedBy(User::factory()->withRole(Role::SalesManager)->create())->create(['phone' => '+998901234567']);

    expect(createLead(['managerId' => $sales->id])->manager_id)->toBe($sales->id);

    expect(fn () => createLead(['managerId' => $engineer->id]))->toThrow(ValidationException::class);
});

it('snapshots the SKU and the name in the lead locale', function () {
    $product = Product::factory()->create(['sku' => 'ZB-ZD421', 'name' => ['uz' => 'Printer', 'ru' => 'Принтер', 'en' => 'Printer EN']]);

    $lead = createLead(['locale' => 'ru', 'items' => [new LeadItemData($product->id, 3, 'для склада')]]);

    $product->update(['sku' => 'CHANGED', 'name' => ['uz' => 'Yangi', 'ru' => 'Новый']]);
    $item = $lead->items()->sole();

    expect($item->sku)->toBe('ZB-ZD421')
        ->and($item->product_name)->toBe('Принтер')
        ->and($item->qty)->toBe(3)
        ->and($item->note)->toBe('для склада')
        ->and($item->product_id)->toBe($product->id);
});

it('rolls back everything when a product is missing', function () {
    expect(fn () => createLead(['items' => [new LeadItemData(999_999)]]))->toThrow(ValidationException::class);

    expect(Lead::query()->count())->toBe(0);
});

it('writes a created activity by the staff user', function () {
    $user = staffUser(Role::SalesManager);

    $lead = createLead(['createdById' => $user->id]);
    $activity = $lead->activities()->sole();

    expect($activity->type)->toBe(ActivityType::Created)
        ->and($activity->user_id)->toBe($user->id)
        ->and($activity->subject_type)->toBe('lead')
        ->and($activity->meta)->toMatchArray(['source' => 'phone', 'type' => 'callback']);
});

it('dispatches LeadCreated only after the transaction commits', function () {
    $levels = [];
    Event::listen(LeadCreated::class, function (LeadCreated $event) use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });

    $outside = DB::transactionLevel();
    $lead = createLead();

    expect($levels)->toBe([$outside]);

    // A surrounding transaction that rolls back: no event at all.
    try {
        DB::transaction(function (): void {
            createLead(['phone' => '+998901110000']);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($levels)->toHaveCount(1)
        ->and(Lead::query()->pluck('id')->all())->toBe([$lead->id]);
});
