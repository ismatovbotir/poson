<?php

use App\Actions\Customers\DeleteCustomer;
use App\Actions\Repairs\AddRepairActivity;
use App\Actions\Repairs\ApproveRepairEstimate;
use App\Actions\Repairs\AssignRepair;
use App\Actions\Repairs\ChangeRepairStatus;
use App\Actions\Repairs\CreateRepair;
use App\Actions\Repairs\UpdateRepair;
use App\Data\Repairs\RepairData;
use App\Enums\ActivityType;
use App\Enums\RepairCoverage;
use App\Enums\RepairDeviceType;
use App\Enums\RepairStatus;
use App\Enums\Role;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @return array<string, array<int, string>> the errors the callback throws, [] when none
 */
function repairsErrorsOf(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

function repairsData(array $overrides = []): RepairData
{
    return new RepairData(...array_merge([
        'contactName' => 'Ali Valiyev',
        'contactPhone' => '90 123 45 67',
        'deviceType' => RepairDeviceType::Scanner,
        'intakeCondition' => 'Scratched housing',
        'problem' => 'No reads',
    ], $overrides));
}

describe('RepairStatus flow', function () {
    it('follows the owner flow', function () {
        $paid = RepairCoverage::Paid;

        expect(RepairStatus::Received->canTransitionTo(RepairStatus::Diagnosing, $paid))->toBeTrue()
            ->and(RepairStatus::Diagnosing->canTransitionTo(RepairStatus::AwaitingApproval, $paid))->toBeTrue()
            ->and(RepairStatus::AwaitingApproval->canTransitionTo(RepairStatus::InRepair, $paid))->toBeTrue()
            ->and(RepairStatus::InRepair->canTransitionTo(RepairStatus::AwaitingParts, $paid))->toBeTrue()
            ->and(RepairStatus::AwaitingParts->canTransitionTo(RepairStatus::InRepair, $paid))->toBeTrue()
            ->and(RepairStatus::InRepair->canTransitionTo(RepairStatus::Ready, $paid))->toBeTrue()
            ->and(RepairStatus::Ready->canTransitionTo(RepairStatus::Delivered, $paid))->toBeTrue()
            ->and(RepairStatus::Ready->canTransitionTo(RepairStatus::InRepair, $paid))->toBeTrue()
            ->and(RepairStatus::Received->canTransitionTo(RepairStatus::Ready, $paid))->toBeFalse()
            ->and(RepairStatus::Delivered->canTransitionTo(RepairStatus::InRepair, $paid))->toBeFalse()
            ->and(RepairStatus::Cancelled->canTransitionTo(RepairStatus::Received, $paid))->toBeFalse();
    });

    it('lets only warranty repairs skip from diagnosing to in repair', function () {
        expect(RepairStatus::Diagnosing->canTransitionTo(RepairStatus::InRepair, RepairCoverage::Warranty))->toBeTrue()
            ->and(RepairStatus::Diagnosing->canTransitionTo(RepairStatus::InRepair, RepairCoverage::Paid))->toBeFalse();
    });

    it('cancels from any open status except ready', function () {
        foreach (RepairStatus::open() as $status) {
            expect($status->canTransitionTo(RepairStatus::Cancelled, RepairCoverage::Paid))->toBe($status !== RepairStatus::Ready, $status->value);
        }
    });
});

describe('CreateRepair', function () {
    it('creates a numbered, received repair with a normalized phone and a created activity', function () {
        $engineer = staffUser(Role::ServiceEngineer);

        $repair = app(CreateRepair::class)->handle(repairsData(['assigneeId' => $engineer->id]), $engineer);
        $second = app(CreateRepair::class)->handle(repairsData(), $engineer);

        expect($repair->number)->toBe(sprintf('R-%d-0001', now('Asia/Tashkent')->year))
            ->and($second->number)->toEndWith('-0002')
            ->and($repair->status)->toBe(RepairStatus::Received)
            ->and($repair->contact_phone)->toBe('+998901234567')
            ->and($repair->assigned_to_id)->toBe($engineer->id)
            ->and($repair->created_by_id)->toBe($engineer->id)
            ->and($repair->received_at)->not->toBeNull()
            ->and($repair->activities()->where('type', ActivityType::Created)->count())->toBe(1)
            ->and($second->assigned_to_id)->toBeNull();
    });

    it('ignores amounts without repairs.costs and stores them with it', function () {
        $admin = staffUser();
        $content = staffUser(Role::ContentManager);

        $without = app(CreateRepair::class)->handle(repairsData(['costsIncluded' => true, 'estimateAmount' => 5_000_000]), $content);
        $with = app(CreateRepair::class)->handle(repairsData(['costsIncluded' => true, 'estimateAmount' => 5_000_000]), $admin);
        $flagOff = app(CreateRepair::class)->handle(repairsData(['estimateAmount' => 5_000_000]), $admin);

        expect($without->estimate_amount)->toBeNull()
            ->and($with->estimate_amount)->toBe(5_000_000)
            ->and($flagOff->estimate_amount)->toBeNull();
    });

    it('caps the amount and rejects negatives', function () {
        $admin = staffUser();

        foreach ([Repair::AMOUNT_MAX_SUMS * 100 + 1, -1] as $bad) {
            expect(repairsErrorsOf(fn () => app(CreateRepair::class)->handle(repairsData(['costsIncluded' => true, 'finalAmount' => $bad]), $admin)))->toHaveKey('final_amount');
        }

        expect(Repair::query()->count())->toBe(0);
    });

    it('refuses a contact of another customer and a contact without a customer', function () {
        $admin = staffUser();
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $contact = Contact::factory()->for($other)->create();

        expect(repairsErrorsOf(fn () => app(CreateRepair::class)->handle(repairsData(['customerId' => $customer->id, 'contactId' => $contact->id]), $admin)))->toHaveKey('contact_id')
            ->and(repairsErrorsOf(fn () => app(CreateRepair::class)->handle(repairsData(['contactId' => $contact->id]), $admin)))->toHaveKey('contact_id');

        $ok = app(CreateRepair::class)->handle(repairsData(['customerId' => $other->id, 'contactId' => $contact->id]), $admin);

        expect($ok->customer_id)->toBe($other->id)->and($ok->contact_id)->toBe($contact->id);
    });

    it('refuses a bad phone and over-long text', function () {
        $admin = staffUser();

        expect(repairsErrorsOf(fn () => app(CreateRepair::class)->handle(repairsData(['contactPhone' => '12']), $admin)))->toHaveKey('contact_phone')
            ->and(repairsErrorsOf(fn () => app(CreateRepair::class)->handle(repairsData(['problem' => str_repeat('a', Repair::TEXT_MAX + 1)]), $admin)))->toHaveKey('problem')
            ->and(repairsErrorsOf(fn () => app(CreateRepair::class)->handle(repairsData(['serialNumber' => str_repeat('1', 65)]), $admin)))->toHaveKey('serial_number');
    });

    it('needs a warranty reference and records a coverage activity for a warranty repair', function () {
        $admin = staffUser();

        expect(repairsErrorsOf(fn () => app(CreateRepair::class)->handle(repairsData(['coverage' => RepairCoverage::Warranty]), $admin)))->toHaveKey('warranty_reference');

        $repair = app(CreateRepair::class)->handle(repairsData(['coverage' => RepairCoverage::Warranty, 'warrantyReference' => 'Invoice 9']), $admin);
        $row = $repair->activities()->where('type', ActivityType::Repair)->first();

        expect($repair->warranty_reference)->toBe('Invoice 9')
            ->and($row->meta['event'])->toBe('coverage_changed')
            ->and($row->body)->toBe('Invoice 9');
    });

    it('lets an engineer assign only themselves', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $colleague = User::factory()->withRole(Role::ServiceEngineer)->create();
        $admin = User::factory()->withRole(Role::Admin)->create();

        expect(repairsErrorsOf(fn () => app(CreateRepair::class)->handle(repairsData(['assigneeId' => $colleague->id]), $engineer)))->toHaveKey('assigned_to_id');

        $byAdmin = app(CreateRepair::class)->handle(repairsData(['assigneeId' => $colleague->id]), $admin);

        expect($byAdmin->assigned_to_id)->toBe($colleague->id);
    });
});

describe('UpdateRepair', function () {
    it('keeps the stored amounts when the form carries none', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $repair = Repair::factory()->assignedTo($engineer)->estimated(7_000_000)->create();

        app(UpdateRepair::class)->handle($repair, repairsData(['problem' => 'Changed']), $engineer);

        expect($repair->fresh()->estimate_amount)->toBe(7_000_000)->and($repair->fresh()->problem)->toBe('Changed');
    });

    it('clears the approval when the estimate is raised or removed, with an activity row', function (?int $new) {
        $engineer = staffUser(Role::ServiceEngineer);
        $repair = Repair::factory()->assignedTo($engineer)->approved()->create(['estimate_amount' => 10_000_000]);

        app(UpdateRepair::class)->handle($repair, repairsData(['costsIncluded' => true, 'estimateAmount' => $new]), $engineer);

        $row = $repair->activities()->where('type', ActivityType::Repair)->first();

        expect($repair->fresh()->estimate_approved_at)->toBeNull()
            ->and($row->meta['event'])->toBe('estimate_changed')
            ->and($row->meta['approval_cleared'])->toBeTrue()
            ->and(json_encode($row->meta))->not->toContain((string) $new === '' ? 'x' : (string) $new);
    })->with([
        'raised' => [12_000_000],
        'removed' => [null],
    ]);

    it('keeps the approval when the estimate is lowered or unchanged', function (int $new) {
        $engineer = staffUser(Role::ServiceEngineer);
        $repair = Repair::factory()->assignedTo($engineer)->approved()->create(['estimate_amount' => 10_000_000]);

        app(UpdateRepair::class)->handle($repair, repairsData(['costsIncluded' => true, 'estimateAmount' => $new]), $engineer);

        expect($repair->fresh()->estimate_approved_at)->not->toBeNull();
    })->with([
        'lowered' => [8_000_000],
        'unchanged' => [10_000_000],
    ]);

    it('never lets the form set the approval', function () {
        $admin = staffUser();
        $repair = Repair::factory()->estimated()->create();

        app(UpdateRepair::class)->handle($repair, repairsData(['costsIncluded' => true, 'estimateAmount' => 15_000_000]), $admin);

        expect($repair->fresh()->estimate_approved_at)->toBeNull();
    });

    it('needs a reference and writes an activity row when moving to warranty', function () {
        $admin = staffUser();
        $repair = Repair::factory()->create();

        expect(repairsErrorsOf(fn () => app(UpdateRepair::class)->handle($repair, repairsData(['coverage' => RepairCoverage::Warranty]), $admin)))->toHaveKey('warranty_reference')
            ->and($repair->fresh()->coverage)->toBe(RepairCoverage::Paid)
            ->and($repair->activities()->count())->toBe(0);

        app(UpdateRepair::class)->handle($repair, repairsData(['coverage' => RepairCoverage::Warranty, 'warrantyReference' => 'Contract 5']), $admin);
        $row = $repair->activities()->where('type', ActivityType::Repair)->first();

        expect($repair->fresh()->coverage)->toBe(RepairCoverage::Warranty)
            ->and($repair->fresh()->warranty_reference)->toBe('Contract 5')
            ->and($row->meta)->toMatchArray(['event' => 'coverage_changed', 'from' => 'paid', 'to' => 'warranty'])
            ->and($row->body)->toBe('Contract 5');
    });

    it('refuses to edit a closed repair', function () {
        $admin = staffUser();
        $repair = Repair::factory()->status(RepairStatus::Delivered)->create();

        expect(repairsErrorsOf(fn () => app(UpdateRepair::class)->handle($repair, repairsData(), $admin)))->toHaveKey('status');
    });
});

describe('ChangeRepairStatus', function () {
    it('blocks a paid repair from entering in repair without an estimate and approval', function () {
        $admin = staffUser();
        $repair = Repair::factory()->status(RepairStatus::AwaitingApproval)->create();

        expect(repairsErrorsOf(fn () => app(ChangeRepairStatus::class)->handle($repair, RepairStatus::InRepair, $admin)))->toHaveKey('status');

        $repair->update(['estimate_amount' => 5_000_000]);
        expect(repairsErrorsOf(fn () => app(ChangeRepairStatus::class)->handle($repair, RepairStatus::InRepair, $admin)))->toHaveKey('status');

        $repair->update(['estimate_approved_at' => now()]);
        app(ChangeRepairStatus::class)->handle($repair, RepairStatus::InRepair, $admin);

        expect($repair->fresh()->status)->toBe(RepairStatus::InRepair);
    });

    it('lets a warranty repair skip the estimate', function () {
        $admin = staffUser();
        $repair = Repair::factory()->warranty()->status(RepairStatus::Diagnosing)->create();

        app(ChangeRepairStatus::class)->handle($repair, RepairStatus::InRepair, $admin);

        expect($repair->fresh()->status)->toBe(RepairStatus::InRepair);
    });

    it('needs an estimate to ask for approval on a paid repair', function () {
        $admin = staffUser();
        $repair = Repair::factory()->status(RepairStatus::Diagnosing)->create();

        expect(repairsErrorsOf(fn () => app(ChangeRepairStatus::class)->handle($repair, RepairStatus::AwaitingApproval, $admin)))->toHaveKey('status');
    });

    it('needs a final amount before a paid repair is ready, but not a warranty one', function () {
        $admin = staffUser();
        $paid = Repair::factory()->approved()->status(RepairStatus::InRepair)->create();
        $warranty = Repair::factory()->warranty()->status(RepairStatus::InRepair)->create();

        expect(repairsErrorsOf(fn () => app(ChangeRepairStatus::class)->handle($paid, RepairStatus::Ready, $admin)))->toHaveKey('status');

        $paid->update(['final_amount' => 9_000_000]);
        app(ChangeRepairStatus::class)->handle($paid, RepairStatus::Ready, $admin);
        app(ChangeRepairStatus::class)->handle($warranty, RepairStatus::Ready, $admin);

        expect($paid->fresh()->status)->toBe(RepairStatus::Ready)->and($warranty->fresh()->status)->toBe(RepairStatus::Ready);
    });

    it('needs a note to cancel, closes the repair and logs the change', function () {
        $admin = staffUser();
        $repair = Repair::factory()->create();

        expect(repairsErrorsOf(fn () => app(ChangeRepairStatus::class)->handle($repair, RepairStatus::Cancelled, $admin, '  ')))->toHaveKey('note');

        app(ChangeRepairStatus::class)->handle($repair, RepairStatus::Cancelled, $admin, 'Customer declined');
        $row = $repair->activities()->where('type', ActivityType::StatusChange)->first();

        expect($repair->fresh()->status)->toBe(RepairStatus::Cancelled)
            ->and($repair->fresh()->closed_at)->not->toBeNull()
            ->and($row->body)->toBe('Customer declined')
            ->and($row->meta)->toMatchArray(['from' => 'received', 'to' => 'cancelled']);
    });

    it('refuses moves off the flow', function () {
        $admin = staffUser();
        $repair = Repair::factory()->create();

        expect(repairsErrorsOf(fn () => app(ChangeRepairStatus::class)->handle($repair, RepairStatus::Delivered, $admin)))->toHaveKey('status');
    });
});

describe('ApproveRepairEstimate', function () {
    it('sets the approval on the server with an activity row and no amount', function () {
        $admin = staffUser();
        $repair = Repair::factory()->estimated(4_200_000)->status(RepairStatus::AwaitingApproval)->create();

        app(ApproveRepairEstimate::class)->handle($repair, $admin);
        $row = $repair->activities()->where('type', ActivityType::Repair)->first();

        expect($repair->fresh()->estimate_approved_at)->not->toBeNull()
            ->and($row->meta['event'])->toBe('estimate_approved')
            ->and(json_encode($row->meta))->not->toContain('4200000');

        expect(repairsErrorsOf(fn () => app(ApproveRepairEstimate::class)->handle($repair, $admin)))->toHaveKey('estimate');
    });

    it('needs an estimate and a paid, open repair', function () {
        $admin = staffUser();

        expect(repairsErrorsOf(fn () => app(ApproveRepairEstimate::class)->handle(Repair::factory()->create(), $admin)))->toHaveKey('estimate')
            ->and(repairsErrorsOf(fn () => app(ApproveRepairEstimate::class)->handle(Repair::factory()->warranty()->estimated()->create(), $admin)))->toHaveKey('estimate')
            ->and(repairsErrorsOf(fn () => app(ApproveRepairEstimate::class)->handle(Repair::factory()->estimated()->status(RepairStatus::Cancelled)->create(), $admin)))->toHaveKey('estimate');
    });
});

describe('AssignRepair', function () {
    it('lets the first engineer take an unassigned repair and logs it', function () {
        $first = staffUser(Role::ServiceEngineer);
        $second = User::factory()->withRole(Role::ServiceEngineer)->create();
        $repair = Repair::factory()->create();

        app(AssignRepair::class)->take($repair, $first);

        expect($repair->fresh()->assigned_to_id)->toBe($first->id)
            ->and(repairsErrorsOf(fn () => app(AssignRepair::class)->take($repair, $second)))->toHaveKey('assigned_to_id')
            ->and($repair->activities()->where('type', ActivityType::Assignment)->count())->toBe(1);
    });

    it('releases only your own repair', function () {
        $mine = staffUser(Role::ServiceEngineer);
        $other = User::factory()->withRole(Role::ServiceEngineer)->create();
        $repair = Repair::factory()->assignedTo($mine)->create();

        expect(repairsErrorsOf(fn () => app(AssignRepair::class)->release($repair, $other)))->toHaveKey('assigned_to_id');

        app(AssignRepair::class)->release($repair, $mine);

        expect($repair->fresh()->assigned_to_id)->toBeNull();
    });

    it('assigns an active repair worker and refuses the rest', function () {
        $admin = staffUser();
        $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
        $sales = User::factory()->withRole(Role::SalesManager)->create();
        $inactive = User::factory()->withRole(Role::ServiceEngineer)->create(['is_active' => false]);
        $repair = Repair::factory()->create();

        foreach ([$sales->id, $inactive->id, 999999] as $bad) {
            expect(repairsErrorsOf(fn () => app(AssignRepair::class)->assign($repair, $bad, $admin)))->toHaveKey('assigned_to_id');
        }

        app(AssignRepair::class)->assign($repair, $engineer->id, $admin);
        expect($repair->fresh()->assigned_to_id)->toBe($engineer->id);

        app(AssignRepair::class)->assign($repair, null, $admin);
        expect($repair->fresh()->assigned_to_id)->toBeNull()
            ->and(app(AssignRepair::class)->candidates()->pluck('id')->all())->toContain($engineer->id, $admin->id)
            ->not->toContain($sales->id, $inactive->id);
    });

    it('exposes the handover hook A3 needs: conditional, open only, active targets', function () {
        $admin = staffUser();
        $leaving = User::factory()->withRole(Role::ServiceEngineer)->create();
        $target = User::factory()->withRole(Role::ServiceEngineer)->create();
        $sales = User::factory()->withRole(Role::SalesManager)->create();
        $open = Repair::factory()->assignedTo($leaving)->create();
        $closed = Repair::factory()->assignedTo($leaving)->status(RepairStatus::Delivered)->create();

        expect(AssignRepair::openFor($leaving)->pluck('id')->all())->toBe([$open->id])
            ->and(app(AssignRepair::class)->handOver($open, $leaving, $sales, $admin))->toBeFalse()
            ->and(app(AssignRepair::class)->handOver($closed, $leaving, null, $admin))->toBeFalse()
            ->and(app(AssignRepair::class)->handOver($open, $leaving, $target, $admin))->toBeTrue()
            ->and($open->fresh()->assigned_to_id)->toBe($target->id)
            ->and(app(AssignRepair::class)->handOver($open, $leaving, null, $admin))->toBeFalse();
    });
});

describe('AddRepairActivity', function () {
    it('adds a trimmed note and refuses an empty or long one', function () {
        $admin = staffUser();
        $repair = Repair::factory()->create();

        app(AddRepairActivity::class)->handle($repair, '  Called the customer  ', $admin);

        expect($repair->activities()->where('type', ActivityType::Note)->first()->body)->toBe('Called the customer')
            ->and(repairsErrorsOf(fn () => app(AddRepairActivity::class)->handle($repair, ' ', $admin)))->toHaveKey('activity_body')
            ->and(repairsErrorsOf(fn () => app(AddRepairActivity::class)->handle($repair, str_repeat('a', AddRepairActivity::BODY_MAX + 1), $admin)))->toHaveKey('activity_body');
    });
});

describe('DeleteCustomer', function () {
    it('is blocked while the customer has repairs, inside its lock', function () {
        $customer = Customer::factory()->create();
        Repair::factory()->forCustomer($customer)->create();

        expect(repairsErrorsOf(fn () => app(DeleteCustomer::class)->handle($customer)))->toHaveKey('customer')
            ->and($customer->fresh())->not->toBeNull()
            ->and(Repair::query()->count())->toBe(1);
    });

    it('still deletes a customer without repairs', function () {
        $customer = Customer::factory()->create();
        Repair::factory()->create();

        app(DeleteCustomer::class)->handle($customer);

        expect(Customer::query()->count())->toBe(0);
    });
});

it('maps repair activities to the repair alias', function () {
    $repair = Repair::factory()->create();
    $repair->activities()->create(['type' => ActivityType::Note, 'body' => 'x', 'created_at' => now()]);

    expect(DB::table('activities')->value('subject_type'))->toBe('repair');
});
