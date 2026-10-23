<?php

use App\Enums\AiErrorCode;
use App\Enums\Role;
use App\Livewire\Admin\Ai\Usage;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

it('is for admins only', function (Role $role, int $status) {
    $this->actingAs(staffUser($role))->get(route('admin.ai.usage'))->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'content manager' => [Role::ContentManager, 403],
    'sales manager' => [Role::SalesManager, 403],
    'service engineer' => [Role::ServiceEngineer, 403],
]);

it('links the menu item for admins', function () {
    $this->actingAs(staffUser(Role::Admin))->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(route('admin.ai.usage'), false);
});

it('shows today and month totals per user in Tashkent time', function () {
    $this->actingAs($admin = staffUser(Role::Admin));
    $writer = User::factory()->create(['name' => 'Dilnoza']);

    AiRequest::factory()->count(3)->for($writer)->costing(250_000)->create();
    AiRequest::factory()->for($writer)->rejected()->create();
    AiRequest::factory()->for($writer)->createdAt(now('Asia/Tashkent')->startOfDay()->subHour()->utc())->create();

    $perUser = Livewire::test(Usage::class)->instance()->perUser;
    $row = $perUser->firstWhere('user_id', $writer->id);

    expect($row->today)->toBe(3)
        ->and($row->name)->toBe('Dilnoza');

    if (now('Asia/Tashkent')->day > 1) {
        expect($row->month)->toBe(4);
    }

    $this->get(route('admin.ai.usage'))->assertOk()->assertSee('Dilnoza')->assertSee('3 / 100');
});

it('filters the log and shows prompt and response only on expand', function () {
    $this->actingAs(staffUser(Role::Admin));
    $failed = AiRequest::factory()->failed(AiErrorCode::Timeout)->create(['prompt' => 'Failed hint']);
    $ok = AiRequest::factory()->create(['prompt' => 'Secret-ish hint text']);

    Livewire::test(Usage::class)
        ->assertDontSee('Secret-ish hint text')
        ->set('status', 'failed')
        ->assertSeeHtml('data-request="'.$failed->id.'"')
        ->assertDontSeeHtml('data-request="'.$ok->id.'"')
        ->set('status', '')
        ->call('toggle', $ok->id)
        ->assertSee('Secret-ish hint text')
        ->assertSee('Принтер этикеток')
        ->call('toggle', $ok->id)
        ->assertDontSee('Secret-ish hint text');
});

it('renders without N+1 queries', function () {
    $this->actingAs(staffUser(Role::Admin));
    Model::preventLazyLoading();

    AiRequest::factory()->count(3)->create();
    Livewire::test(Usage::class); // warm-up: permission and settings caches
    $few = countQueries(fn () => Livewire::test(Usage::class));

    AiRequest::factory()->count(20)->create();
    $many = countQueries(fn () => Livewire::test(Usage::class));

    expect($many)->toBe($few);
});
