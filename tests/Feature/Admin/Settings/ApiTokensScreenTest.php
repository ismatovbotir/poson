<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Settings\ApiTokens;
use App\Models\ApiToken;
use App\Models\SettingsAudit;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

it('is for admins only', function (Role $role, int $status) {
    $this->actingAs(staffUser($role))->get(route('admin.settings.api-tokens'))->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'content manager' => [Role::ContentManager, 403],
    'sales manager' => [Role::SalesManager, 403],
    'service engineer' => [Role::ServiceEngineer, 403],
]);

it('forbids every Livewire write for other roles', function (Role $role) {
    $token = ApiToken::factory()->create();
    $this->actingAs(staffUser($role));
    confirmAdminPassword();

    Livewire::test(ApiTokens::class)->assertForbidden();

    expect(ApiToken::query()->count())->toBe(1)->and($token->fresh()->revoked_at)->toBeNull();
})->with(['content manager' => [Role::ContentManager], 'sales manager' => [Role::SalesManager], 'service engineer' => [Role::ServiceEngineer]]);

it('lists tokens masked, never with the hash', function () {
    $token = ApiToken::factory()->withPlainText($plain = fakeApiTokenValue())->create(['name' => '1C office', 'last_used_ip' => '10.0.0.5', 'last_used_at' => now()]);

    $html = $this->actingAs(staffUser())->get(route('admin.settings.api-tokens'))->assertOk()->getContent();

    expect($html)->toContain('1C office')
        ->toContain($token->maskedToken())
        ->toContain('10.0.0.5')
        ->toContain(route('api.v1.prices', absolute: false))
        ->not->toContain($token->token_hash)
        ->not->toContain(substr($plain, 12));
});

it('needs a recent password confirmation to create a token', function () {
    $this->actingAs(staffUser());

    Livewire::test(ApiTokens::class)
        ->set('name', '1C')
        ->call('create')
        ->assertRedirect(route('admin.confirm-password'));

    expect(ApiToken::query()->count())->toBe(0);
});

it('creates a token, shows it once and stores only its hash', function () {
    $this->actingAs($admin = staffUser());
    confirmAdminPassword();

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });

    $component = Livewire::test(ApiTokens::class)
        ->set('name', '  1C main office ')
        ->set('expiresInDays', '90')
        ->call('create')
        ->assertHasNoErrors()
        ->assertDispatched('api-token-created');

    $token = ApiToken::query()->sole();
    preg_match('/data-plain-token>(pos_[A-Za-z0-9]{48})</', $component->html(), $matches);
    $plain = $matches[1] ?? null;

    expect($plain)->not->toBeNull()
        ->and($token->name)->toBe('1C main office')
        ->and($token->token_hash)->toBe(hash('sha256', $plain))
        ->and($token->token_prefix)->toBe(substr($plain, 0, 12))
        ->and($token->abilities)->toBe(['prices.write'])
        ->and($token->created_by)->toBe($admin->id)
        ->and($token->expires_at->isSameDay(now()->addDays(90)))->toBeTrue()
        // Not in any column, the snapshot, or the logs.
        ->and(json_encode(ApiToken::query()->first()->getAttributes()))->not->toContain($plain)
        ->and(json_encode($component->snapshot))->not->toContain($plain)
        ->and(implode("\n", $logged))->not->toContain($plain);

    // The next request doesn't show it again.
    $component->call('$refresh')->assertDontSee($plain);

    expect(SettingsAudit::query()->sole())
        ->area->toBe(SettingsArea::ApiTokens)
        ->key->toBe('api_tokens.'.$token->id)
        ->action->toBe(AuditAction::Created)
        ->user_id->toBe($admin->id);

    // The plain token works on the API.
    postPrices(['items' => [priceItem('A')]], $plain)->assertOk();
});

it('creates a token without expiry and validates the name', function () {
    $this->actingAs(staffUser());
    confirmAdminPassword();

    Livewire::test(ApiTokens::class)->set('name', '')->call('create')->assertHasErrors('name');
    Livewire::test(ApiTokens::class)->set('name', str_repeat('a', 101))->call('create')->assertHasErrors('name');
    Livewire::test(ApiTokens::class)->set('name', 'X')->set('expiresInDays', '7')->call('create')->assertHasErrors('expires_in_days');

    Livewire::test(ApiTokens::class)->set('name', 'Forever')->set('expiresInDays', '')->call('create')->assertHasNoErrors();

    expect(ApiToken::query()->sole()->expires_at)->toBeNull();
});

it('revokes a token with a password confirmation and an audit row', function () {
    $this->actingAs(staffUser());
    $token = ApiToken::factory()->withPlainText($plain = fakeApiTokenValue())->create(['name' => '1C']);

    Livewire::test(ApiTokens::class)->call('revoke', $token->id)->assertRedirect(route('admin.confirm-password'));
    expect($token->fresh()->revoked_at)->toBeNull();

    confirmAdminPassword();
    Livewire::test(ApiTokens::class)->call('revoke', $token->id)->assertDispatched('toast');

    expect($token->fresh()->revoked_at)->not->toBeNull()
        ->and(SettingsAudit::query()->where('action', AuditAction::Revoked->value)->count())->toBe(1);

    postPrices(['items' => [priceItem('A')]], $plain)->assertUnauthorized();

    // Revoking twice changes nothing.
    Livewire::test(ApiTokens::class)->call('revoke', $token->id)->assertDispatched('toast', type: 'info');
    expect(SettingsAudit::query()->where('action', AuditAction::Revoked->value)->count())->toBe(1);
});

it('shows the menu item to admins only', function () {
    $this->actingAs(staffUser())->get(route('admin.dashboard'))->assertSee(route('admin.settings.api-tokens'), false);
    $this->actingAs(staffUser(Role::ContentManager))->get(route('admin.dashboard'))->assertDontSee(route('admin.settings.api-tokens'), false);
});
