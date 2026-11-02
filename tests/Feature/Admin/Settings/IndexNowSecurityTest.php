<?php

use App\Actions\Settings\SaveIntegrationSettings;
use App\Actions\Settings\SetSecret;
use App\Enums\Role;
use App\Livewire\Admin\Settings\Integrations;
use App\Support\Secrets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

const IN_ADMIN_KEY = '0123456789abcdef0123456789abcdef';

function activeIndexNowCard(): void
{
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.url' => 'https://poson.example.uz']);
    openSearchIndexing();
    app(SetSecret::class)->handle('indexnow.key', IN_ADMIN_KEY, null, null);
    app(SaveIntegrationSettings::class)->handle(['indexnow.enabled' => true], null, null);
}

beforeEach(function () {
    Http::preventStrayRequests();
});

afterEach(function () {
    Request::setTrustedHosts([]);
});

it('forbids every non-admin role to generate, save and test', function (Role $role, string $method, array $params) {
    confirmAdminPassword();
    $component = Livewire::actingAs(staffUser(Role::Admin))->test(Integrations::class);
    $before = Secrets::get('indexnow.key');

    $this->actingAs(staffUser($role));
    $component->call($method, ...$params)->assertForbidden();

    expect(Secrets::get('indexnow.key'))->toBe($before);
    Http::assertNothingSent();
})->with(function () {
    foreach ([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer] as $role) {
        yield $role->value.' generate' => [$role, 'generateIndexNowKey', []];
        yield $role->value.' save' => [$role, 'save', ['indexnow']];
        yield $role->value.' test' => [$role, 'test', ['indexnow']];
    }
});

it('requires a recent password confirmation for generate, save and test', function (?int $secondsAgo, string $method, array $params) {
    activeIndexNowCard();
    if ($secondsAgo !== null) {
        confirmAdminPassword($secondsAgo);
    }

    Livewire::actingAs(staffUser())->test(Integrations::class)->call($method, ...$params)->assertRedirect();

    expect(Secrets::get('indexnow.key'))->toBe(IN_ADMIN_KEY);
    Http::assertNothingSent();
})->with(function () {
    foreach (['never confirmed' => null, 'stale' => 86400] as $label => $ago) {
        yield $label.' generate' => [$ago, 'generateIndexNowKey', []];
        yield $label.' save' => [$ago, 'save', ['indexnow']];
        yield $label.' test' => [$ago, 'test', ['indexnow']];
    }
});

it('sends no request from the Test button while the gate is off', function () {
    confirmAdminPassword();

    Livewire::actingAs(staffUser())->test(Integrations::class)
        ->call('test', 'indexnow')
        ->assertSet('testResults.indexnow_key.ok', false);

    Http::assertNothingSent();
});

it('never renders the key, before or after generating a new one', function () {
    activeIndexNowCard();
    confirmAdminPassword();

    $component = Livewire::actingAs(staffUser())->test(Integrations::class);
    $leaks = fn () => $component->html().json_encode($component->snapshot).json_encode($component->effects);

    expect($leaks())->not->toContain(IN_ADMIN_KEY)->not->toContain(IN_ADMIN_KEY.'.txt');

    $component->call('generateIndexNowKey');
    $newKey = Secrets::get('indexnow.key');

    expect($newKey)->not->toBe(IN_ADMIN_KEY)->toMatch('/^[a-f0-9]{32}$/')
        ->and($leaks())->not->toContain($newKey)->not->toContain(IN_ADMIN_KEY)->not->toContain(substr($newKey, 4, 24));
});
