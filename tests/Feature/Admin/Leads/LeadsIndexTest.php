<?php

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Livewire\Admin\Leads\Index;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\User;
use Livewire\Livewire;

it('opens the list for the CRM roles and forbids the content manager', function (Role $role, int $status) {
    $this->actingAs(staffUser($role))->get(route('admin.leads.index'))->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'sales manager' => [Role::SalesManager, 200],
    'service engineer' => [Role::ServiceEngineer, 200],
    'content manager' => [Role::ContentManager, 403],
]);

it('lists only the leads the user may see', function () {
    $sales = staffUser(Role::SalesManager);
    $own = Lead::factory()->quote()->assignedTo($sales)->create(['company' => 'Own Co']);
    $free = Lead::factory()->create(['company' => 'Free Co']);
    $other = Lead::factory()->assignedTo(User::factory()->withRole(Role::SalesManager)->create())->create(['company' => 'Other Co']);
    $service = Lead::factory()->serviceRequest()->create(['company' => 'Service Co']);

    Livewire::actingAs($sales)->test(Index::class)
        ->assertSee('Own Co')
        ->assertSee('Free Co')
        ->assertSee('Service Co')
        ->assertDontSee('Other Co');

    $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();

    Livewire::actingAs($engineer)->test(Index::class)
        ->assertSee('Service Co')
        ->assertDontSee('Own Co')
        ->assertDontSee('Free Co');
});

it('splits open and closed leads into tabs', function () {
    $admin = staffUser();
    Lead::factory()->create(['company' => 'Open Co']);
    Lead::factory()->lost()->create(['company' => 'Lost Co']);

    Livewire::actingAs($admin)->test(Index::class)
        ->assertSee('Open Co')
        ->assertDontSee('Lost Co')
        ->call('setTab', 'closed')
        ->assertSee('Lost Co')
        ->assertDontSee('Open Co')
        ->call('setTab', 'all')
        ->assertSee('Lost Co')
        ->assertSee('Open Co')
        ->assertSet('tabCounts', ['open' => 1, 'all' => 2, 'closed' => 1]);
});

it('finds a lead by its L- reference and by phone digits', function () {
    $admin = staffUser();
    $target = Lead::factory()->create(['company' => 'Target Co', 'phone' => '+998901234567']);
    Lead::factory()->create(['company' => 'Noise Co', 'phone' => '+998935554433']);

    foreach (['L-'.$target->id, 'l'.$target->id, '#'.$target->id, '90 123 45', '1234567', 'target'] as $term) {
        Livewire::actingAs($admin)->test(Index::class)
            ->set('search', $term)
            ->assertSee('Target Co')
            ->assertDontSee('Noise Co');
    }
});

it('finds a lead by its exact 9-digit INN', function () {
    $admin = staffUser();
    Lead::factory()->create(['company' => 'Inn Co', 'inn' => '305123456']);
    Lead::factory()->create(['company' => 'Other Co', 'inn' => '305123457']);

    Livewire::actingAs($admin)->test(Index::class)
        ->set('search', '305123456')
        ->assertSee('Inn Co')
        ->assertDontSee('Other Co');
});

it('counts the date filter in Tashkent days', function () {
    $admin = staffUser();
    // 23:30 in Tashkent on 10 Oct = 18:30 UTC the same day; 00:30 on 11 Oct = 19:30 UTC on 10 Oct.
    Lead::factory()->create(['company' => 'Late Co', 'created_at' => '2026-10-10 18:30:00']);
    Lead::factory()->create(['company' => 'Next Co', 'created_at' => '2026-10-10 19:30:00']);

    Livewire::actingAs($admin)->test(Index::class)
        ->set('tab', 'all')
        ->set('to', '2026-10-10')
        ->assertSee('Late Co')
        ->assertDontSee('Next Co')
        ->set('to', '')
        ->set('from', '2026-10-11')
        ->assertSee('Next Co')
        ->assertDontSee('Late Co');
});

it('does not reveal an invisible lead through the reference search', function () {
    $sales = staffUser(Role::SalesManager);
    $hidden = Lead::factory()->assignedTo(User::factory()->withRole(Role::SalesManager)->create())->create(['company' => 'Hidden Co']);

    Livewire::actingAs($sales)->test(Index::class)
        ->set('search', 'L-'.$hidden->id)
        ->assertDontSee('Hidden Co')
        ->assertSee(__('crm.leads.no_results'));
});

it('filters by status, type, source, manager, dates and duplicates', function () {
    $admin = staffUser();
    $sales = User::factory()->withRole(Role::SalesManager)->create();
    $original = Lead::factory()->create(['company' => 'Alpha', 'created_at' => now()->subDays(5)]);
    Lead::factory()->duplicateOf($original)->create(['company' => 'Bravo']);
    Lead::factory()->serviceRequest()->manual(LeadSource::Phone)->assignedTo($sales)->status(LeadStatus::InProgress)->create(['company' => 'Charlie']);
    Lead::factory()->assignedTo($admin)->create(['company' => 'Delta']);

    $see = fn (array $filters): array => collect(['Alpha', 'Bravo', 'Charlie', 'Delta'])
        ->filter(function (string $company) use ($admin, $filters): bool {
            $component = Livewire::actingAs($admin)->test(Index::class);

            foreach ($filters as $filter => $value) {
                $component->set($filter, $value);
            }

            return str_contains($component->html(), $company);
        })
        ->values()
        ->all();

    expect($see(['status' => 'in_progress']))->toBe(['Charlie'])
        ->and($see(['type' => 'service_request']))->toBe(['Charlie'])
        ->and($see(['source' => 'phone']))->toBe(['Charlie'])
        ->and($see(['manager' => 'none']))->toBe(['Alpha', 'Bravo'])
        ->and($see(['manager' => 'me']))->toBe(['Delta'])
        ->and($see(['manager' => (string) $sales->id]))->toBe(['Charlie'])
        ->and($see(['duplicates' => true]))->toBe(['Bravo'])
        ->and($see(['to' => now()->subDays(3)->format('Y-m-d')]))->toBe(['Alpha'])
        ->and($see(['from' => now()->subDays(3)->format('Y-m-d')]))->toBe(['Bravo', 'Charlie', 'Delta']);
});

it('ignores sort keys outside the whitelist', function () {
    Lead::factory()->create(['company' => 'Alpha']);

    Livewire::actingAs(staffUser())->test(Index::class)
        ->set('sort', 'phone; drop table leads')
        ->assertOk()
        ->assertSee('Alpha');
});

it('shows a take button on unassigned leads and takes them', function () {
    $sales = staffUser(Role::SalesManager);
    $free = Lead::factory()->create();
    $own = Lead::factory()->assignedTo($sales)->create();

    Livewire::actingAs($sales)->test(Index::class)
        ->assertSeeHtml('data-take="'.$free->id.'"')
        ->assertDontSeeHtml('data-take="'.$own->id.'"')
        ->call('take', $free->id)
        ->assertDispatched('toast');

    expect($free->fresh()->manager_id)->toBe($sales->id);
});

it('forbids taking a lead the user cannot work with', function () {
    $sales = staffUser(Role::SalesManager);
    $service = Lead::factory()->serviceRequest()->create();

    Livewire::actingAs($sales)->test(Index::class)
        ->call('take', $service->id)
        ->assertForbidden();

    expect($service->fresh()->manager_id)->toBeNull();
});

it('lists leads without N+1 queries', function () {
    $admin = staffUser();
    $render = fn () => Livewire::actingAs($admin)->test(Index::class);

    Lead::factory()->assignedTo($admin)->count(3)->has(LeadItem::factory()->count(2), 'items')->create();
    $render(); // warm the permission cache
    $few = countQueries($render);

    Lead::factory()->assignedTo(User::factory()->withRole(Role::SalesManager)->create())->count(10)->has(LeadItem::factory()->count(2), 'items')->create();
    Lead::factory()->count(5)->create();
    $render();
    $many = countQueries($render);

    expect($many)->toBe($few);
});

it('redirects the service requests menu entry to the filtered list', function (Role $role, int $status) {
    $response = $this->actingAs(staffUser($role))->get(route('admin.service-requests.index'));

    $status === 302
        ? $response->assertRedirect(route('admin.leads.index', ['type' => 'service_request']))
        : $response->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 302],
    'sales manager' => [Role::SalesManager, 302],
    'service engineer' => [Role::ServiceEngineer, 302],
    'content manager' => [Role::ContentManager, 403],
]);
