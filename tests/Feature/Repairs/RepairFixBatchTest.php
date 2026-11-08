<?php

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
use App\Livewire\Admin\Repairs\Form;
use App\Livewire\Admin\Repairs\Show;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Repair;
use App\Models\User;
use App\Support\Repairs\RepairAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

function repairsFixErrors(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

function repairsFixData(array $overrides = []): RepairData
{
    return new RepairData(...array_merge([
        'contactName' => 'Ali Valiyev',
        'contactPhone' => '90 123 45 67',
        'deviceType' => RepairDeviceType::Scanner,
        'intakeCondition' => 'Scratched housing',
        'problem' => 'No reads',
    ], $overrides));
}

describe('1 coverage round trip', function () {
    it('refuses a coverage change once work started, except for Admin', function (RepairStatus $status) {
        $engineer = staffUser(Role::ServiceEngineer);
        $repair = Repair::factory()->assignedTo($engineer)->approved()->status($status)->create();

        $errors = repairsFixErrors(fn () => app(UpdateRepair::class)->handle($repair, repairsFixData(['coverage' => RepairCoverage::Warranty, 'warrantyReference' => 'Inv 1']), $engineer));

        expect($errors)->toHaveKey('coverage')->and($repair->fresh()->coverage)->toBe(RepairCoverage::Paid);

        app(UpdateRepair::class)->handle($repair, repairsFixData(['coverage' => RepairCoverage::Warranty, 'warrantyReference' => 'Inv 1']), User::factory()->withRole(Role::Admin)->create());

        expect($repair->fresh()->coverage)->toBe(RepairCoverage::Warranty);
    })->with([RepairStatus::InRepair, RepairStatus::AwaitingParts, RepairStatus::Ready]);

    it('clears the approval on any coverage change with an activity row, and needs a reference for warranty', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $repair = Repair::factory()->assignedTo($engineer)->approved()->status(RepairStatus::AwaitingApproval)->create();

        expect(repairsFixErrors(fn () => app(UpdateRepair::class)->handle($repair, repairsFixData(['coverage' => RepairCoverage::Warranty]), $engineer)))->toHaveKey('warranty_reference');

        app(UpdateRepair::class)->handle($repair, repairsFixData(['coverage' => RepairCoverage::Warranty, 'warrantyReference' => 'Inv 1']), $engineer);
        $row = $repair->activities()->where('type', ActivityType::Repair)->first();

        expect($repair->fresh()->estimate_approved_at)->toBeNull()
            ->and($repair->fresh()->estimate_approved_amount)->toBeNull()
            ->and($row->meta['approval_cleared'])->toBeTrue();
    });

    it('re-enters the estimate guards when an Admin sends a repair back to paid mid-repair', function () {
        $admin = staffUser();
        $repair = Repair::factory()->warranty()->status(RepairStatus::InRepair)->create(['estimate_amount' => 5_000_000]);

        app(UpdateRepair::class)->handle($repair, repairsFixData(['coverage' => RepairCoverage::Paid]), $admin);

        expect($repair->fresh()->status)->toBe(RepairStatus::AwaitingApproval)
            ->and($repair->fresh()->estimate_approved_at)->toBeNull()
            ->and(repairsFixErrors(fn () => app(ChangeRepairStatus::class)->handle($repair->fresh(), RepairStatus::InRepair, $admin)))->toHaveKey('status');
    });
});

describe('2 money guards', function () {
    it('needs estimate, approval and final amount for ready and delivered on a paid repair', function (RepairStatus $from, RepairStatus $to) {
        $admin = staffUser();
        $base = ['estimate_amount' => 5_000_000, 'estimate_approved_at' => now(), 'estimate_approved_amount' => 5_000_000, 'final_amount' => 4_000_000];

        foreach ([['estimate_amount' => null], ['estimate_approved_at' => null], ['estimate_approved_amount' => null], ['final_amount' => null], ['final_amount' => 6_000_000]] as $break) {
            $repair = Repair::factory()->status($from)->create(array_merge($base, $break));

            expect(repairsFixErrors(fn () => app(ChangeRepairStatus::class)->handle($repair, $to, $admin)))->toHaveKey('status');
        }

        $ok = Repair::factory()->status($from)->create($base);
        app(ChangeRepairStatus::class)->handle($ok, $to, $admin);

        expect($ok->fresh()->status)->toBe($to);
    })->with([
        'ready' => [RepairStatus::InRepair, RepairStatus::Ready],
        'delivered' => [RepairStatus::Ready, RepairStatus::Delivered],
    ]);

    it('sends the repair back to awaiting approval when the estimate is raised or cleared mid-repair', function (RepairStatus $status, ?int $new) {
        $engineer = staffUser(Role::ServiceEngineer);
        $repair = Repair::factory()->assignedTo($engineer)->approved()->status($status)->create(['estimate_amount' => 10_000_000, 'estimate_approved_amount' => 10_000_000]);

        app(UpdateRepair::class)->handle($repair, repairsFixData(['costsIncluded' => true, 'estimateAmount' => $new]), $engineer);
        $auto = $repair->activities()->where('type', ActivityType::StatusChange)->first();

        expect($repair->fresh()->status)->toBe(RepairStatus::AwaitingApproval)
            ->and($repair->fresh()->estimate_approved_at)->toBeNull()
            ->and($auto->meta)->toMatchArray(['from' => $status->value, 'to' => 'awaiting_approval', 'auto' => true]);
    })->with([
        [RepairStatus::InRepair, 12_000_000],
        [RepairStatus::AwaitingParts, null],
    ]);

    it('lowering mid-repair keeps the status and approval and follows the approved amount', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $repair = Repair::factory()->assignedTo($engineer)->approved()->status(RepairStatus::InRepair)->create(['estimate_amount' => 10_000_000, 'estimate_approved_amount' => 10_000_000]);

        app(UpdateRepair::class)->handle($repair, repairsFixData(['costsIncluded' => true, 'estimateAmount' => 8_000_000]), $engineer);

        expect($repair->fresh()->status)->toBe(RepairStatus::InRepair)
            ->and($repair->fresh()->estimate_approved_amount)->toBe(8_000_000);
    });

    it('refuses raising or clearing the estimate at ready and clearing or exceeding the final amount', function () {
        $admin = staffUser();
        $repair = Repair::factory()->approved()->status(RepairStatus::Ready)->create(['estimate_amount' => 10_000_000, 'estimate_approved_amount' => 10_000_000, 'final_amount' => 9_000_000]);

        expect(repairsFixErrors(fn () => app(UpdateRepair::class)->handle($repair, repairsFixData(['costsIncluded' => true, 'estimateAmount' => 11_000_000, 'finalAmount' => 9_000_000]), $admin)))->toHaveKey('estimate_amount')
            ->and(repairsFixErrors(fn () => app(UpdateRepair::class)->handle($repair, repairsFixData(['costsIncluded' => true, 'estimateAmount' => null, 'finalAmount' => 9_000_000]), $admin)))->toHaveKey('estimate_amount')
            ->and(repairsFixErrors(fn () => app(UpdateRepair::class)->handle($repair, repairsFixData(['costsIncluded' => true, 'estimateAmount' => 10_000_000, 'finalAmount' => null]), $admin)))->toHaveKey('final_amount')
            ->and(repairsFixErrors(fn () => app(UpdateRepair::class)->handle($repair, repairsFixData(['costsIncluded' => true, 'estimateAmount' => 10_000_000, 'finalAmount' => 10_000_001]), $admin)))->toHaveKey('final_amount')
            ->and($repair->fresh()->final_amount)->toBe(9_000_000);
    });
});

describe('4 silent edits are logged without amounts', function () {
    it('logs final_changed and warranty_reference_changed', function () {
        $admin = staffUser();
        $repair = Repair::factory()->warranty('Old ref')->create();

        app(UpdateRepair::class)->handle($repair, repairsFixData(['coverage' => RepairCoverage::Warranty, 'warrantyReference' => 'New ref', 'costsIncluded' => true, 'finalAmount' => 7_777_700]), $admin);

        $events = $repair->activities()->where('type', ActivityType::Repair)->get();

        expect($events->pluck('meta.event')->all())->toContain('final_changed', 'warranty_reference_changed')
            ->and($events->firstWhere('meta.event', 'warranty_reference_changed')->body)->toBe('New ref')
            ->and(json_encode($events->pluck('meta')->all()))->not->toContain('7777700');
    });
});

describe('5 lead link', function () {
    it('is written on create only, validated, and never nulled on edit', function () {
        $admin = staffUser();
        $service = Lead::factory()->serviceRequest()->create();
        $sales = Lead::factory()->quote()->create();

        expect(repairsFixErrors(fn () => app(CreateRepair::class)->handle(repairsFixData(['leadId' => $sales->id]), $admin)))->toHaveKey('lead_id')
            ->and(repairsFixErrors(fn () => app(CreateRepair::class)->handle(repairsFixData(['leadId' => 999999]), $admin)))->toHaveKey('lead_id');

        $repair = app(CreateRepair::class)->handle(repairsFixData(['leadId' => $service->id]), $admin);
        app(UpdateRepair::class)->handle($repair, repairsFixData(['problem' => 'Edited', 'leadId' => $sales->id]), $admin);

        expect($repair->fresh()->lead_id)->toBe($service->id);
    });

    it('refuses a lead the actor may not view', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $theirs = Lead::factory()->serviceRequest()->assignedTo(User::factory()->withRole(Role::ServiceEngineer)->create())->create();

        expect(repairsFixErrors(fn () => app(CreateRepair::class)->handle(repairsFixData(['leadId' => $theirs->id]), $engineer)))->toHaveKey('lead_id');
    });
});

describe('6 and 7 status changes', function () {
    it('clears the approval when going back to diagnosing', function () {
        $admin = staffUser();
        $repair = Repair::factory()->approved()->status(RepairStatus::AwaitingApproval)->create();

        app(ChangeRepairStatus::class)->handle($repair, RepairStatus::Diagnosing, $admin);

        expect($repair->fresh()->status)->toBe(RepairStatus::Diagnosing)
            ->and($repair->fresh()->estimate_approved_at)->toBeNull()
            ->and($repair->fresh()->estimate_approved_amount)->toBeNull();
    });

    it('cancels a ready repair only with a note', function () {
        $admin = staffUser();
        $repair = Repair::factory()->status(RepairStatus::Ready)->create();

        expect(repairsFixErrors(fn () => app(ChangeRepairStatus::class)->handle($repair, RepairStatus::Cancelled, $admin)))->toHaveKey('note');

        app(ChangeRepairStatus::class)->handle($repair, RepairStatus::Cancelled, $admin, 'Customer collected nothing');

        expect($repair->fresh()->status)->toBe(RepairStatus::Cancelled);
    });

    it('needs a written diagnosis for awaiting approval and for the warranty shortcut', function () {
        $admin = staffUser();
        $paid = Repair::factory()->estimated()->status(RepairStatus::Diagnosing)->create();
        $warranty = Repair::factory()->warranty()->status(RepairStatus::Diagnosing)->create(['diagnosis' => '   ']);

        expect(repairsFixErrors(fn () => app(ChangeRepairStatus::class)->handle($paid, RepairStatus::AwaitingApproval, $admin)))->toHaveKey('status')
            ->and(repairsFixErrors(fn () => app(ChangeRepairStatus::class)->handle($warranty, RepairStatus::InRepair, $admin)))->toHaveKey('status');

        $paid->update(['diagnosis' => 'Sensor dead']);
        $warranty->update(['diagnosis' => 'Sensor dead']);
        app(ChangeRepairStatus::class)->handle($paid, RepairStatus::AwaitingApproval, $admin);
        app(ChangeRepairStatus::class)->handle($warranty, RepairStatus::InRepair, $admin);

        expect($paid->fresh()->status)->toBe(RepairStatus::AwaitingApproval)->and($warranty->fresh()->status)->toBe(RepairStatus::InRepair);
    });
});

describe('8 to 14 hardening', function () {
    it('locks the repair property of the show page', function () {
        $admin = staffUser();
        $repair = Repair::factory()->create();
        $other = Repair::factory()->create();

        expect(fn () => Livewire::actingAs($admin)->test(Show::class, ['repair' => $repair])->set('repair', $other->id))->toThrow('Cannot update locked property');
    });

    it('does not read a contact of a customer the user may not see', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $customer = Customer::factory()->create();
        $contact = Contact::factory()->for($customer)->create(['name' => 'Secret Person', 'phone' => '+998933334455']);
        SpatieRole::findByName(Role::ServiceEngineer->value, 'web')->revokePermissionTo(['customers.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Livewire::actingAs($engineer->fresh())->test(Form::class)
            ->set('form.customer_id', (string) $customer->id)
            ->set('form.contact_id', (string) $contact->id)
            ->assertSet('form.contact_name', '');
    });

    it('re-authorizes create on every request of the intake form', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $component = Livewire::actingAs($engineer)->test(Form::class);

        SpatieRole::findByName(Role::ServiceEngineer->value, 'web')->revokePermissionTo('repairs.create');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $component->set('form.contact_name', 'x')->assertForbidden();
    });

    it('never gives edit access wider than view access', function () {
        $user = User::factory()->create();
        $user->givePermissionTo('repairs.update');

        expect(RepairAccess::viewLevel($user))->toBe(RepairAccess::NONE)
            ->and(RepairAccess::editLevel($user))->toBe(RepairAccess::NONE)
            ->and(Repair::query()->editableBy($user)->count())->toBe(0);

        $engineer = staffUser(Role::ServiceEngineer);
        expect(RepairAccess::editLevel($engineer))->toBe(RepairAccess::OWN);
    });

    it('logs an assign only when a row changed and keeps handover ids only', function () {
        $admin = staffUser();
        $a = User::factory()->withRole(Role::ServiceEngineer)->create();
        $b = User::factory()->withRole(Role::ServiceEngineer)->create();
        $repair = Repair::factory()->assignedTo($a)->create();

        app(AssignRepair::class)->assign($repair, $a->id, $admin);
        expect($repair->activities()->count())->toBe(0);

        app(AssignRepair::class)->assign($repair, $b->id, $admin);
        expect($repair->activities()->count())->toBe(1);

        expect(app(AssignRepair::class)->handOver($repair->fresh(), $b, $b, $admin))->toBeFalse();
        app(AssignRepair::class)->handOver($repair->fresh(), $b, $a, $admin);
        $meta = $repair->activities()->where('meta->action', 'handover')->firstOrFail()->meta;

        expect($meta)->toMatchArray(['from_id' => $b->id, 'to_id' => $a->id])
            ->and($meta)->not->toHaveKeys(['from_name', 'to_name']);
    });

    it('allows notes on a closed repair only for Admin or the assigned engineer and no other change', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $colleague = User::factory()->withRole(Role::ServiceEngineer)->create();
        $mine = Repair::factory()->assignedTo($engineer)->status(RepairStatus::Delivered)->create();
        $free = Repair::factory()->status(RepairStatus::Delivered)->create();
        $open = Repair::factory()->create();

        expect($engineer->can('note', $mine))->toBeTrue()
            ->and($engineer->can('update', $mine))->toBeFalse()
            ->and($engineer->can('note', $free))->toBeFalse()
            ->and($engineer->can('update', $free))->toBeFalse()
            ->and($colleague->can('note', $mine))->toBeFalse()
            ->and($engineer->can('note', $open))->toBeTrue()
            ->and(staffUser(Role::SalesManager)->can('note', $mine))->toBeFalse();

        Livewire::actingAs($engineer)->test(Show::class, ['repair' => $mine])->set('activityBody', 'Warranty claim')->call('addActivity')->assertHasNoErrors();
        Livewire::actingAs($engineer)->test(Show::class, ['repair' => $free])->set('activityBody', 'x')->call('addActivity')->assertForbidden();
    });

    it('shows a message when the repair was closed meanwhile and carries the password hints', function () {
        $admin = staffUser();
        $repair = Repair::factory()->create();

        $component = Livewire::actingAs($admin)->test(Form::class, ['repair' => $repair]);
        $repair->forceFill(['status' => RepairStatus::Cancelled])->save();

        $component->call('save')->assertHasErrors(['form.status']);
        expect($component->html())->toContain(__('repairs.errors.closed'))
            ->and(substr_count($component->html(), __('repairs.hints.no_passwords')))->toBeGreaterThanOrEqual(4);
    });
});

describe('16 wording', function () {
    it('uses the agreed terms', function () {
        expect(trans('enums.repair_device_type.mobile_computer', [], 'uz'))->toBe('Maʼlumot yigʻish terminali (TSD)')
            ->and(trans('enums.repair_device_type.mobile_computer', [], 'ru'))->toBe('Терминал сбора данных (ТСД)')
            ->and(trans('enums.repair_device_type.scanner', [], 'ru'))->toBe('Сканер штрих-кода')
            ->and(trans('repairs.customer.hint', [], 'uz'))->toContain('Mijoz kartasi boʻlmagan mijozni')
            ->and(trans('repairs.hints.final', [], 'ru'))->toBe('В целых сумах. Нужна, чтобы отметить платный ремонт готовым.');
    });
});

describe('17 lock re-checks authorization and version', function () {
    it('refuses every action for an engineer whose repair was reassigned after the page loaded', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $other = User::factory()->withRole(Role::ServiceEngineer)->create();
        $repair = Repair::factory()->assignedTo($engineer)->create();
        $stale = $repair->fresh();

        $repair->forceFill(['assigned_to_id' => $other->id])->save();

        expect(fn () => app(UpdateRepair::class)->handle($stale, repairsFixData(['problem' => 'Hacked']), $engineer))->toThrow(AuthorizationException::class)
            ->and(fn () => app(ChangeRepairStatus::class)->handle($stale, RepairStatus::Diagnosing, $engineer))->toThrow(AuthorizationException::class)
            ->and($repair->fresh()->problem)->not->toBe('Hacked')
            ->and($repair->fresh()->status)->toBe(RepairStatus::Received);
    });

    it('refuses take / release when the actor lost the right meanwhile', function () {
        $engineer = staffUser(Role::ServiceEngineer);
        $repair = Repair::factory()->create();
        $engineer->syncRoles([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        expect(fn () => app(AssignRepair::class)->take($repair, $engineer->fresh()))->toThrow(AuthorizationException::class)
            ->and($repair->fresh()->assigned_to_id)->toBeNull();
    });

    it('refuses an update whose version no longer matches', function () {
        $admin = User::factory()->withRole(Role::Admin)->create();
        $repair = Repair::factory()->create();
        $seen = UpdateRepair::version($repair->fresh());

        app(UpdateRepair::class)->handle($repair, repairsFixData(['problem' => 'First']), $admin, $seen);

        expect(repairsFixErrors(fn () => app(UpdateRepair::class)->handle($repair->fresh(), repairsFixData(['problem' => 'Second']), $admin, $seen)))->toHaveKey('version')
            ->and($repair->fresh()->problem)->toBe('First');
    });
});
