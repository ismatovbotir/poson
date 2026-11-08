<?php

use App\Actions\Repairs\AssignRepair;
use App\Enums\ActivityType;
use App\Enums\Role;
use App\Livewire\Admin\Repairs\Form;
use App\Livewire\Admin\Repairs\Index;
use App\Livewire\Admin\Repairs\Show;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Abuse cases for Plan 24 repairs (security review items 18, 19, 22, 23, 29, 30, 32).
 *
 * Item 31 (lost update on a stale edit form) is covered by
 * RepairScreensTest "stale edit form"; item 35 (deactivated engineer) is
 * skipped: A3 is not built.
 */
function abuseEngineer(): User
{
    return staffUser(Role::ServiceEngineer);
}

/**
 * @return array<string, mixed>
 */
function abuseSnapshot(Repair $repair): array
{
    return $repair->fresh()->getAttributes() + ['activities' => $repair->activities()->count()];
}

/**
 * Valid intake input for the create form.
 */
function abuseFillIntake(mixed $component): mixed
{
    return $component
        ->set('form.contact_name', 'Ali Valiyev')
        ->set('form.contact_phone', '90 123 45 67')
        ->set('form.problem', 'No reads')
        ->set('form.intake_condition', 'Scratched');
}

describe('18 a replayed snapshot on a colleague repair', function () {
    it('answers 403 and leaves the row unchanged', function (string $method, array $state, ?string $on) {
        $engineer = abuseEngineer();
        $colleague = abuseEngineer();
        $repair = Repair::factory()->assignedTo($engineer)->estimated()->create();

        $component = $on === 'form'
            ? Livewire::actingAs($engineer)->test(Form::class, ['repair' => $repair])->set('form.problem', 'Hijacked')
            : Livewire::actingAs($engineer)->test(Show::class, ['repair' => $repair]);

        foreach ($state as $property => $value) {
            $component->set($property, $value);
        }

        // The repair moves to a colleague after the snapshot was taken.
        $repair->forceFill(['assigned_to_id' => $colleague->id])->save();
        $before = abuseSnapshot($repair);

        $arguments = $method === 'changeStatus' ? ['diagnosing'] : [];
        $component->call($method, ...$arguments)->assertForbidden();

        expect(abuseSnapshot($repair))->toBe($before);
    })->with([
        'changeStatus' => ['changeStatus', [], null],
        'cancelRepair' => ['cancelRepair', ['cancelNote' => 'Customer left'], null],
        'addActivity' => ['addActivity', ['activityBody' => 'Injected note'], null],
        'approveEstimate' => ['approveEstimate', ['approveNote' => 'Phone approval'], null],
        'assign' => ['assign', ['assignTo' => 'self'], null],
        'save' => ['save', [], 'form'],
    ]);

    it('refuses to open the colleague repair at all', function () {
        $engineer = abuseEngineer();
        $repair = Repair::factory()->assignedTo(abuseEngineer())->create();

        Livewire::actingAs($engineer)->test(Show::class, ['repair' => $repair])->assertForbidden();
        Livewire::actingAs($engineer)->test(Form::class, ['repair' => $repair])->assertForbidden();
    });
});

describe('19 assigning', function () {
    it('forbids an engineer to assign a colleague', function () {
        $engineer = abuseEngineer();
        $colleague = abuseEngineer();
        $repair = Repair::factory()->assignedTo($engineer)->create();

        Livewire::actingAs($engineer)->test(Show::class, ['repair' => $repair])
            ->set('assignTo', (string) $colleague->id)->call('assign')->assertForbidden();

        expect($repair->fresh()->assigned_to_id)->toBe($engineer->id);
    });

    it('ignores a tampered form.assigned_to_id on edit', function () {
        $engineer = abuseEngineer();
        $colleague = abuseEngineer();
        $repair = Repair::factory()->assignedTo($engineer)->create();

        Livewire::actingAs($engineer)->test(Form::class, ['repair' => $repair])
            ->set('form.assigned_to_id', (string) $colleague->id)
            ->set('form.problem', 'Edited')
            ->call('save');

        expect($repair->fresh()->assigned_to_id)->toBe($engineer->id);
    });

    it('refuses a tampered assignee on create for an engineer', function () {
        $engineer = abuseEngineer();
        $colleague = abuseEngineer();

        abuseFillIntake(Livewire::actingAs($engineer)->test(Form::class))
            ->set('form.assigned_to_id', (string) $colleague->id)->call('save')->assertHasErrors(['form.assigned_to_id']);

        expect(Repair::query()->count())->toBe(0);
    });
});

describe('22 amount inputs', function () {
    it('rejects malformed amounts as validation errors and stores nothing', function (string $field, string $value) {
        $admin = staffUser();

        abuseFillIntake(Livewire::actingAs($admin)->test(Form::class))
            ->set("form.{$field}", $value)
            ->call('save')
            ->assertHasErrors(["form.{$field}"])
            ->assertOk();

        expect(Repair::query()->count())->toBe(0);
    })->with(function () {
        foreach (['estimate_amount', 'final_amount'] as $field) {
            foreach (['1e9' => '1e9', 'decimal' => '1.5', 'negative' => '-1', 'long' => '1234567890123456', 'hex' => '0x10', 'above max' => '10000000001'] as $label => $value) {
                yield "{$field} {$label}" => [$field, $value];
            }
        }
    });

    it('trims a leading-space amount and stores it as 100 sums', function () {
        $admin = staffUser();

        abuseFillIntake(Livewire::actingAs($admin)->test(Form::class))->set('form.estimate_amount', ' 100')->call('save')->assertHasNoErrors();

        expect(Repair::query()->firstOrFail()->estimate_amount)->toBe(10_000);
    });

    it('rejects a bad amount on edit and keeps the row', function () {
        $admin = staffUser();
        $repair = Repair::factory()->estimated(10_000_000)->create();

        Livewire::actingAs($admin)->test(Form::class, ['repair' => $repair])
            ->set('form.estimate_amount', '1e9')->call('save')->assertHasErrors(['form.estimate_amount']);

        expect($repair->fresh()->estimate_amount)->toBe(10_000_000);
    });
});

describe('23 locked properties', function () {
    it('refuses to tamper with approvalVersion', function () {
        $admin = staffUser();
        $repair = Repair::factory()->estimated()->create(['status' => 'awaiting_approval']);
        $before = abuseSnapshot($repair);

        $component = Livewire::actingAs($admin)->test(Show::class, ['repair' => $repair]);

        expect(fn () => $component->set('approvalVersion', 'forged'))->toThrow('Cannot update locked property');
        expect(abuseSnapshot($repair))->toBe($before);
    });

    it('refuses to tamper with the edit form version and repair', function () {
        $admin = staffUser();
        $repair = Repair::factory()->create();
        $other = Repair::factory()->create();
        $before = abuseSnapshot($repair);

        $component = Livewire::actingAs($admin)->test(Form::class, ['repair' => $repair]);

        expect(fn () => $component->set('version', 'forged'))->toThrow('Cannot update locked property');
        expect(fn () => $component->set('repair', $other->id))->toThrow('Cannot update locked property');
        expect(abuseSnapshot($repair))->toBe($before);
    });
});

describe('29 search', function () {
    it('treats LIKE wildcards literally and never widens the engineer scope', function (string $term) {
        $engineer = abuseEngineer();
        Repair::factory()->assignedTo($engineer)->create(['serial_number' => 'OWNSERIAL1']);
        Repair::factory()->assignedTo(abuseEngineer())->create(['serial_number' => 'FOREIGNSERIAL']);

        Livewire::actingAs($engineer)->test(Index::class)->set('tab', 'all')->set('search', $term)
            ->assertDontSee('OWNSERIAL1')->assertDontSee('FOREIGNSERIAL');
    })->with(['percent' => '%', 'underscore' => '_', 'bang' => '!', 'mix' => '%_!']);

    it('finds a literal wildcard character in a serial', function (string $serial, string $term) {
        $engineer = abuseEngineer();
        Repair::factory()->assignedTo($engineer)->create(['serial_number' => $serial]);
        Repair::factory()->assignedTo($engineer)->create(['serial_number' => 'PLAINONE']);

        Livewire::actingAs($engineer)->test(Index::class)->set('tab', 'all')->set('search', $term)
            ->assertSee($serial)->assertDontSee('PLAINONE');
    })->with([['AB%CD', 'b%c'], ['AB_CD', 'b_c'], ['AB!CD', 'b!c']]);

    it('truncates a very long search without an error', function () {
        $component = Livewire::actingAs(abuseEngineer())->test(Index::class)->set('search', str_repeat('%', 150));

        $component->assertHasNoErrors()->assertOk();
        expect(mb_strlen($component->get('search')))->toBe(Index::SEARCH_MAX);
    });
});

describe('30 stored XSS', function () {
    it('shows hostile text escaped on the list, the page and the timeline', function () {
        $admin = staffUser();
        $script = '<script>alert("xss")</script>';
        $image = '<img src=x onerror=alert(1)>';

        $repair = Repair::factory()->estimated()->create([
            'serial_number' => $script,
            'problem' => $image,
            'diagnosis' => $script,
            'work_done' => $image,
            'parts_used' => $script,
            'accessories' => $image,
            'intake_condition' => $image,
            'warranty_reference' => $script,
            'company' => $image,
            'contact_name' => $script,
            'device_brand' => $image,
            'device_model' => $script,
        ]);
        $repair->activities()->create(['user_id' => $admin->id, 'type' => ActivityType::Note, 'body' => $script.$image, 'created_at' => now()]);
        $repair->activities()->create(['user_id' => $admin->id, 'type' => ActivityType::Repair, 'body' => $script, 'meta' => ['event' => 'warranty_reference_changed'], 'created_at' => now()]);

        foreach ([route('admin.repairs.index').'?tab=all', route('admin.repairs.show', $repair), route('admin.repairs.edit', $repair)] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            expect($html)->not->toContain($script)->not->toContain($image);
        }

        $html = Livewire::actingAs($admin)->test(Show::class, ['repair' => $repair])->html();
        expect($html)->not->toContain($script)->not->toContain($image)->toContain('&lt;script&gt;');
    });

    it('stores a hostile note as text and renders it escaped', function () {
        $admin = staffUser();
        $repair = Repair::factory()->create();

        Livewire::actingAs($admin)->test(Show::class, ['repair' => $repair])
            ->set('activityBody', '<script>alert(2)</script>')->call('addActivity')->assertHasNoErrors()
            ->assertDontSeeHtml('<script>alert(2)</script>')->assertSeeHtml('&lt;script&gt;alert(2)&lt;/script&gt;');
    });
});

describe('32 two engineers take the same repair (sequential; a real race needs MySQL threads)', function () {
    it('refuses the second take through the action', function () {
        $first = abuseEngineer();
        $second = abuseEngineer();
        $repair = Repair::factory()->create();
        $stale = Repair::query()->find($repair->id);

        app(AssignRepair::class)->take($repair, $first);

        expect(fn () => app(AssignRepair::class)->take($stale, $second))->toThrow(ValidationException::class);
        expect($repair->fresh()->assigned_to_id)->toBe($first->id);
    });

    it('refuses the second take on the list after the first', function () {
        $first = abuseEngineer();
        $second = abuseEngineer();
        $repair = Repair::factory()->create();

        $secondList = Livewire::actingAs($second)->test(Index::class);
        Livewire::actingAs($first)->test(Index::class)->call('take', $repair->id);

        // Livewire::actingAs switches the global guard: sign the second engineer back in.
        $this->actingAs($second);
        $secondList->call('take', $repair->id)->assertNotFound();
        expect($repair->fresh()->assigned_to_id)->toBe($first->id);
    });
});
