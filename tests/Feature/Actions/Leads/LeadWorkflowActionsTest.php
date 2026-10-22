<?php

use App\Actions\Leads\AddLeadActivity;
use App\Actions\Leads\AssignLead;
use App\Actions\Leads\ChangeLeadStatus;
use App\Actions\Leads\DeleteLead;
use App\Actions\Leads\UpdateLead;
use App\Data\Leads\LeadData;
use App\Data\Leads\LeadItemData;
use App\Enums\ActivityType;
use App\Enums\LeadLostReason;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\LeadType;
use App\Enums\Role;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Validation\ValidationException;

describe('ChangeLeadStatus', function () {
    it('allows open → any status and closed → in progress only', function () {
        foreach (LeadStatus::open() as $from) {
            foreach (LeadStatus::cases() as $to) {
                expect($from->canTransitionTo($to))->toBe($from !== $to);
            }
        }

        foreach (LeadStatus::closed() as $from) {
            foreach (LeadStatus::cases() as $to) {
                expect($from->canTransitionTo($to))->toBe($to === LeadStatus::InProgress);
            }
        }
    });

    it('sets the status times and logs the change', function () {
        $user = staffUser(Role::SalesManager);
        $lead = Lead::factory()->create();

        $this->travel(1)->hours();
        app(ChangeLeadStatus::class)->markConverted($lead, $user);

        expect($lead->fresh())
            ->status->toBe(LeadStatus::Converted)
            ->status_changed_at->toEqual(now()->startOfSecond())
            ->closed_at->toEqual(now()->startOfSecond());

        $activity = $lead->activities()->sole();

        expect($activity->type)->toBe(ActivityType::StatusChange)
            ->and($activity->user_id)->toBe($user->id)
            ->and($activity->meta)->toBe(['from' => 'new', 'to' => 'converted']);
    });

    it('refuses converted by hand', function () {
        $lead = Lead::factory()->create();

        expect(fn () => app(ChangeLeadStatus::class)->handle($lead, LeadStatus::Converted, staffUser()))
            ->toThrow(ValidationException::class, __('crm.leads.errors.convert_only'))
            ->and($lead->fresh()->status)->toBe(LeadStatus::New);
    });

    it('requires a reason to mark a lead lost', function () {
        $lead = Lead::factory()->create();

        expect(fn () => app(ChangeLeadStatus::class)->handle($lead, LeadStatus::Lost, staffUser()))
            ->toThrow(ValidationException::class, __('crm.leads.errors.lost_reason_required'))
            ->and($lead->fresh()->status)->toBe(LeadStatus::New);
    });

    it('requires a note when the reason is other', function () {
        $user = staffUser();
        $lead = Lead::factory()->create();

        expect(fn () => app(ChangeLeadStatus::class)->handle($lead, LeadStatus::Lost, $user, LeadLostReason::Other))
            ->toThrow(ValidationException::class, __('crm.leads.errors.lost_note_required'));

        app(ChangeLeadStatus::class)->handle($lead, LeadStatus::Lost, $user, LeadLostReason::Other, 'Went silent after the demo');

        expect($lead->fresh())
            ->status->toBe(LeadStatus::Lost)
            ->lost_reason->toBe(LeadLostReason::Other)
            ->lost_note->toBe('Went silent after the demo')
            ->closed_at->not->toBeNull()
            ->and($lead->activities()->sole()->body)->toBe('Went silent after the demo');
    });

    it('logs a reopen and clears the closed data', function () {
        $user = staffUser();
        $lead = Lead::factory()->lost(LeadLostReason::Budget)->create();

        expect(fn () => app(ChangeLeadStatus::class)->handle($lead, LeadStatus::Qualified, $user))->toThrow(ValidationException::class);

        app(ChangeLeadStatus::class)->handle($lead, LeadStatus::InProgress, $user);

        expect($lead->fresh())
            ->status->toBe(LeadStatus::InProgress)
            ->closed_at->toBeNull()
            ->lost_reason->toBeNull()
            ->and($lead->activities()->sole()->meta)->toBe(['from' => 'lost', 'to' => 'in_progress', 'reopened' => true]);
    });
});

describe('AssignLead', function () {
    it('lets the first user take an unassigned lead', function () {
        $first = staffUser(Role::SalesManager);
        $second = User::factory()->withRole(Role::SalesManager)->create();
        $lead = Lead::factory()->create();

        app(AssignLead::class)->take($lead, $first);

        expect($lead->fresh()->manager_id)->toBe($first->id)
            ->and(fn () => app(AssignLead::class)->take(Lead::query()->find($lead->id), $second))->toThrow(ValidationException::class)
            ->and($lead->fresh()->manager_id)->toBe($first->id)
            ->and($lead->activities()->sole()->meta)->toMatchArray(['action' => 'take', 'to_id' => $first->id, 'to_name' => $first->name]);
    });

    it('releases only the user’s own lead', function () {
        $owner = staffUser(Role::SalesManager);
        $other = User::factory()->withRole(Role::SalesManager)->create();
        $lead = Lead::factory()->assignedTo($owner)->create();

        expect(fn () => app(AssignLead::class)->release($lead, $other))->toThrow(ValidationException::class);

        app(AssignLead::class)->release($lead, $owner);

        expect($lead->fresh()->manager_id)->toBeNull();
    });

    it('offers sales users for reassigning a sales lead, nobody for a service request', function () {
        $sales = staffUser(Role::SalesManager);
        $colleague = User::factory()->withRole(Role::SalesManager)->create(['name' => 'Bek']);
        $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
        User::factory()->inactive()->withRole(Role::SalesManager)->create();

        $candidates = app(AssignLead::class)->candidates(Lead::factory()->quote()->assignedTo($sales)->make(), $sales);

        expect($candidates->pluck('id')->all())->toEqualCanonicalizing([$sales->id, $colleague->id])
            ->and(app(AssignLead::class)->candidates(Lead::factory()->serviceRequest()->make(), $sales))->toBeEmpty()
            ->and(app(AssignLead::class)->candidates(Lead::factory()->serviceRequest()->make(), $engineer))->toBeEmpty();
    });

    it('offers the admin everyone who works with the type', function () {
        $admin = staffUser(Role::Admin);
        $sales = User::factory()->withRole(Role::SalesManager)->create();
        $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();

        expect(app(AssignLead::class)->candidates(Lead::factory()->make(), $admin)->pluck('id')->all())->toEqualCanonicalizing([$admin->id, $sales->id])
            ->and(app(AssignLead::class)->candidates(Lead::factory()->serviceRequest()->make(), $admin)->pluck('id')->all())->toEqualCanonicalizing([$admin->id, $engineer->id]);
    });

    it('assigns a candidate, logs from and to, and refuses others', function () {
        $sales = staffUser(Role::SalesManager);
        $colleague = User::factory()->withRole(Role::SalesManager)->create();
        $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
        $lead = Lead::factory()->assignedTo($sales)->create();

        expect(fn () => app(AssignLead::class)->assign($lead, $engineer->id, $sales))->toThrow(ValidationException::class);

        app(AssignLead::class)->assign($lead, $colleague->id, $sales);

        expect($lead->fresh()->manager_id)->toBe($colleague->id)
            ->and($lead->activities()->sole()->meta)->toMatchArray([
                'action' => 'assign',
                'from_id' => $sales->id,
                'to_id' => $colleague->id,
                'to_name' => $colleague->name,
            ]);
    });
});

describe('AddLeadActivity', function () {
    it('adds notes and calls only', function () {
        $user = staffUser(Role::SalesManager);
        $lead = Lead::factory()->create();

        $call = app(AddLeadActivity::class)->handle($lead, ActivityType::Call, ' Called, wants a demo ', $user);

        expect($call->type)->toBe(ActivityType::Call)
            ->and($call->body)->toBe('Called, wants a demo')
            ->and($call->user_id)->toBe($user->id)
            ->and(fn () => app(AddLeadActivity::class)->handle($lead, ActivityType::StatusChange, 'x', $user))->toThrow(ValidationException::class)
            ->and(fn () => app(AddLeadActivity::class)->handle($lead, ActivityType::Note, '   ', $user))->toThrow(ValidationException::class);
    });
});

describe('UpdateLead', function () {
    it('updates the contact data and items, keeping existing snapshots', function () {
        $lead = Lead::factory()->manual()->create(['phone' => '+998901234567']);
        $kept = LeadItem::factory()->for($lead)->create(['product_name' => 'Old name', 'sku' => 'OLD', 'qty' => 1]);
        $dropped = LeadItem::factory()->for($lead)->create();
        $new = Product::factory()->create(['sku' => 'NEW-1']);

        app(UpdateLead::class)->handle($lead, new LeadData(
            source: LeadSource::Telegram,
            type: LeadType::Quote,
            name: 'Aziz',
            phone: '+998901234567',
            locale: 'uz',
            company: '  Market  ',
            estimatedAmount: 150_000_00,
            items: [new LeadItemData(null, 5, 'urgent', $kept->id), new LeadItemData($new->id, 2)],
        ));

        $lead->refresh();
        $items = $lead->items()->get();

        expect($lead->source)->toBe(LeadSource::Telegram)
            ->and($lead->type)->toBe(LeadType::Quote)
            ->and($lead->company)->toBe('Market')
            ->and($lead->estimated_amount)->toBe(150_000_00)
            ->and($items->pluck('sku')->all())->toBe(['OLD', 'NEW-1'])
            ->and($items[0]->product_name)->toBe('Old name')
            ->and($items[0]->qty)->toBe(5)
            ->and($items[0]->note)->toBe('urgent')
            ->and(LeadItem::query()->find($dropped->id))->toBeNull();
    });

    it('keeps the source of a site lead', function () {
        $lead = Lead::factory()->create();

        app(UpdateLead::class)->handle($lead, new LeadData(source: LeadSource::Phone, type: LeadType::Callback, name: 'A', phone: $lead->phone, locale: 'uz'));

        expect($lead->fresh()->source)->toBe(LeadSource::Site);
    });

    it('clears the duplicate flag of later leads that no longer share the phone', function () {
        $lead = Lead::factory()->create(['phone' => '+998901234567']);
        $later = Lead::factory()->duplicateOf($lead)->create();

        app(UpdateLead::class)->handle($lead, new LeadData(source: LeadSource::Site, type: LeadType::Callback, name: 'A', phone: '+998935554433', locale: 'uz'));

        expect($later->fresh()->duplicate_of_id)->toBeNull();
    });

    it('rechecks the duplicate flag on a new phone', function () {
        $original = Lead::factory()->create(['phone' => '+998935554433']);
        $lead = Lead::factory()->create();

        app(UpdateLead::class)->handle($lead, new LeadData(source: LeadSource::Site, type: LeadType::Callback, name: 'A', phone: '93 555 44 33', locale: 'uz'));

        expect($lead->fresh()->duplicate_of_id)->toBe($original->id);
    });

    it('unassigns the lead when the manager cannot work with the new type', function () {
        $sales = staffUser(Role::SalesManager);
        $lead = Lead::factory()->assignedTo($sales)->create();

        app(UpdateLead::class)->handle($lead, new LeadData(source: LeadSource::Site, type: LeadType::ServiceRequest, name: 'A', phone: $lead->phone, locale: 'uz'));

        expect($lead->fresh()->manager_id)->toBeNull();
    });
});

describe('DeleteLead', function () {
    it('deletes the lead with its items and activities only', function () {
        $lead = Lead::factory()->create();
        $other = Lead::factory()->create();
        LeadItem::factory()->for($lead)->create();
        Activity::factory()->subject($lead)->count(2)->create();
        Activity::factory()->subject($other)->create();
        $duplicate = Lead::factory()->duplicateOf($lead)->create();

        app(DeleteLead::class)->handle($lead);

        expect(Lead::query()->find($lead->id))->toBeNull()
            ->and(LeadItem::query()->count())->toBe(0)
            ->and(Activity::query()->pluck('subject_id')->all())->toBe([$other->id])
            ->and($duplicate->fresh()->duplicate_of_id)->toBeNull();
    });
});
