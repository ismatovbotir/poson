<?php

use App\Enums\Role;
use App\Livewire\Admin\Settings\Integrations;
use App\Support\Secrets;
use App\Support\Site\IndexNow;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function indexNowCard(?Role $role = null)
{
    return Livewire::actingAs(staffUser($role ?? Role::Admin))->test(Integrations::class);
}

beforeEach(function () {
    Http::preventStrayRequests();
});

it('generates a key, audits it and never renders it', function () {
    confirmAdminPassword();

    $component = indexNowCard()->call('generateIndexNowKey')->assertDispatched('toast', type: 'success');
    $key = Secrets::get('indexnow.key');

    expect($key)->toMatch('/^[a-f0-9]{32}$/')
        ->and($component->html().json_encode($component->snapshot).json_encode($component->effects))->not->toContain($key);
});

it('refuses the owner-only actions to other roles', function (Role $role, string $method, array $params) {
    confirmAdminPassword();
    $component = indexNowCard();

    $this->actingAs(staffUser($role));

    $component->call($method, ...$params)->assertForbidden();
})->with([
    'content' => [Role::ContentManager, 'generateIndexNowKey', []],
    'sales' => [Role::SalesManager, 'generateIndexNowKey', []],
    'engineer' => [Role::ServiceEngineer, 'test', ['indexnow']],
]);

it('tests without any request while the gate is off', function () {
    confirmAdminPassword();

    indexNowCard()->call('test', 'indexnow')->assertSet('testResults.indexnow_key.ok', false);

    Http::assertNothingSent();
});

it('shows the card with the group and the log without the key', function () {
    confirmAdminPassword();
    IndexNow::log(3, 'ok', 200);

    indexNowCard()->assertSeeHtml('data-indexnow')->assertSee('200');
});

it('schedules indexnow:flush every five minutes without overlap', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'indexnow:flush'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->runInBackground)->toBeFalse();
});
