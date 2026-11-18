<?php

use App\Actions\Repairs\RotateRepairStatusToken;
use App\Enums\Role;
use App\Livewire\Admin\Repairs\Show;
use App\Models\Repair;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * Plan 35 I4: status-link card and rotation UI (P35-14).
 */
beforeEach(function () {
    Cache::flush();
    app()->setLocale('en');
});

it('shows the link state on the card', function (string $state, string $text) {
    $repair = match ($state) {
        'none' => Repair::factory()->create(),
        'live' => Repair::factory()->withStatusToken()->create(),
        'expires' => Repair::factory()->withStatusToken()->closedDaysAgo(10)->create(),
        'expired' => Repair::factory()->withStatusToken()->closedDaysAgo(40)->create(),
    };

    Livewire::actingAs(staffUser(Role::Admin, 'en'))
        ->test(Show::class, ['repair' => $repair])
        ->assertSeeHtml('data-status-link-state="'.$state.'"')
        ->assertSee($text);
})->with([
    'no token' => ['none', 'No link yet'],
    'live' => ['live', 'Live'],
    'closed 10 days' => ['expires', 'Expires on'],
    'closed 40 days' => ['expired', 'Expired'],
]);

it('shows the renewal date once rotated', function () {
    $repair = Repair::factory()->withStatusToken()->create(['public_token_rotated_at' => now()]);

    Livewire::actingAs(staffUser(Role::Admin, 'en'))
        ->test(Show::class, ['repair' => $repair])
        ->assertSee('Last renewed');
});

it('hides the button from a sales manager and forbids rotation', function () {
    $repair = Repair::factory()->withStatusToken()->create();
    $old = $repair->public_token;

    Livewire::actingAs(staffUser(Role::SalesManager, 'en'))
        ->test(Show::class, ['repair' => $repair])
        ->assertSee('Status link')
        ->assertDontSeeHtml('data-rotate-link')
        ->call('rotateStatusLink')
        ->assertForbidden();

    expect($repair->fresh()->public_token)->toBe($old);
});

it('rotates the token for the assigned engineer and the admin without leaking it', function (string $who) {
    $engineer = staffUser(Role::ServiceEngineer);
    $user = $who === 'admin' ? staffUser(Role::Admin, 'en') : $engineer;
    $repair = Repair::factory()->withStatusToken()->assignedTo($engineer)->create();
    $old = $repair->public_token;

    $component = Livewire::actingAs($user)
        ->test(Show::class, ['repair' => $repair])
        ->assertSeeHtml('data-rotate-link')
        ->call('rotateStatusLink')
        ->assertHasNoErrors();

    $new = $repair->fresh()->public_token;
    expect($new)->not->toBe($old);

    $this->get('/r/'.$old)->assertNotFound();
    $this->get('/r/'.$new)->assertOk();

    $payload = json_encode($component->snapshot ?? []).$component->html();
    foreach ([$old, $new] as $token) {
        expect($payload)->not->toContain($token)->not->toContain('/r/'.$token);
    }
})->with(['engineer' => 'engineer', 'admin' => 'admin']);

it('never puts the token in the component payload', function () {
    $repair = Repair::factory()->withStatusToken()->create();
    $token = $repair->public_token;

    $component = Livewire::actingAs(staffUser(Role::Admin, 'en'))->test(Show::class, ['repair' => $repair]);

    expect(json_encode($component->snapshot))->not->toContain($token)
        ->and($component->html())->not->toContain($token);
});

it('reports the daily limit on the sixth rotation', function () {
    $repair = Repair::factory()->withStatusToken()->create();

    $component = Livewire::actingAs(staffUser(Role::Admin, 'en'))->test(Show::class, ['repair' => $repair]);

    foreach (range(1, RotateRepairStatusToken::DAILY_LIMIT) as $ignored) {
        $component->call('rotateStatusLink');
    }
    $token = $repair->fresh()->public_token;

    $component->call('rotateStatusLink')
        ->assertDispatched('toast', fn ($event, $params) => ($params['type'] ?? null) === 'error' && str_contains($params['body'], 'at most 5'));

    expect($repair->fresh()->public_token)->toBe($token);
});

it('hides renewal for a closed repair whose link has expired and denies the call', function () {
    $repair = Repair::factory()->withStatusToken()->closedDaysAgo(40)->create();
    $old = $repair->public_token;

    Livewire::actingAs(staffUser(Role::Admin, 'en'))
        ->test(Show::class, ['repair' => $repair])
        ->assertSeeHtml('data-status-link-state="expired"')
        ->assertDontSeeHtml('data-rotate-link')
        ->call('rotateStatusLink')
        ->assertForbidden();

    expect($repair->fresh()->public_token)->toBe($old);
});

it('still offers renewal for a closed repair whose link is within the grace period', function () {
    $repair = Repair::factory()->withStatusToken()->closedDaysAgo(10)->create();

    Livewire::actingAs(staffUser(Role::Admin, 'en'))
        ->test(Show::class, ['repair' => $repair])
        ->assertSeeHtml('data-rotate-link');
});

it('renders with a constant number of queries however many times the link was renewed', function () {
    $user = staffUser(Role::Admin, 'en');
    $a = Repair::factory()->withStatusToken()->create();
    $b = Repair::factory()->withStatusToken()->create(['public_token_rotated_at' => now()]);

    $count = fn (Repair $repair): int => countQueries(fn () => Livewire::actingAs($user)->test(Show::class, ['repair' => $repair]));
    $count($a);

    expect($count($b))->toBe($count($a));
});
