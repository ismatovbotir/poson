<?php

use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Livewire\Admin\Dashboard;
use App\Models\Lead;
use App\Models\User;
use App\Support\Admin\Menu;
use Livewire\Livewire;

it('counts new visible leads in the menu badge', function () {
    $sales = staffUser(Role::SalesManager);
    Lead::factory()->count(2)->create();
    Lead::factory()->assignedTo($sales)->create();
    Lead::factory()->assignedTo($sales)->status(LeadStatus::InProgress)->create();
    Lead::factory()->assignedTo(User::factory()->withRole(Role::SalesManager)->create())->create();
    Lead::factory()->serviceRequest()->create();

    $engineer = User::factory()->withRole(Role::ServiceEngineer)->create();
    $content = User::factory()->withRole(Role::ContentManager)->create();

    // Sales manager: 2 free + 1 own sales leads, plus the service request (read-only view).
    expect(Menu::badges($sales))->toBe(['leads' => 4])
        ->and(Menu::badges($engineer))->toBe(['leads' => 1])
        ->and(Menu::badges($content))->toBe([]);

    $html = $this->actingAs($sales)->get('/admin')->assertOk()->getContent();

    preg_match('/<li data-menu-item="leads">(.*?)<\/li>/s', $html, $item);

    expect($item[1])->toContain('data-menu-badge>4')
        ->and($item[1])->toContain('href="'.route('admin.leads.index').'"')
        ->and($html)->toContain('data-tab-badge>4');
});

it('hides the badge when there is nothing new', function () {
    $html = $this->actingAs(staffUser(Role::SalesManager))->get('/admin')->assertOk()->getContent();

    expect($html)->not->toContain('data-menu-badge')->not->toContain('data-tab-badge');
});

it('links the leads and service request items while customers stay soon', function () {
    $html = $this->actingAs(staffUser(Role::ServiceEngineer))->get('/admin')->assertOk()->getContent();

    preg_match('/<li data-menu-item="service-requests">(.*?)<\/li>/s', $html, $service);
    preg_match('/<li data-menu-item="customers">(.*?)<\/li>/s', $html, $customers);

    expect($service[1])->toContain('href="'.route('admin.service-requests.index').'"')
        ->and($customers[1])->toContain('data-soon');
});

it('shows the lead cards and the latest leads from what the user may see', function () {
    $sales = staffUser(Role::SalesManager);
    Lead::factory()->create(['company' => 'Free Co']);
    Lead::factory()->assignedTo($sales)->status(LeadStatus::InProgress)->create(['company' => 'Mine Co', 'created_at' => now()->subDays(3)]);
    Lead::factory()->assignedTo(User::factory()->withRole(Role::SalesManager)->create())->create(['company' => 'Other Co']);

    $component = Livewire::actingAs($sales)->test(Dashboard::class)
        ->assertSee('Free Co')
        ->assertSee('Mine Co')
        ->assertDontSee('Other Co');

    expect(collect($component->get('leadCards'))->pluck('count', 'key')->all())->toBe([
        'leads_new' => 1,
        'leads_today' => 1,
        'leads_unassigned' => 1,
        'leads_mine' => 1,
    ]);
});

it('shows no lead cards to the content manager', function () {
    Livewire::actingAs(staffUser(Role::ContentManager))->test(Dashboard::class)
        ->assertDontSeeHtml('data-lead-cards')
        ->assertDontSeeHtml('data-recent-leads');
});
