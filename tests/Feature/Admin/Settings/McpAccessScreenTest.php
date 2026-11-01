<?php

use App\Enums\ApiTokenType;
use App\Enums\AuditAction;
use App\Enums\McpCallStatus;
use App\Enums\McpRevokeReason;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Settings\McpAccess;
use App\Models\ApiToken;
use App\Models\McpCall;
use App\Models\SettingsAudit;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    mcpTelegramConfigured();
});

/**
 * Decoded text of one copy block of the show-once modal.
 */
function snippet(string $html, string $key): string
{
    preg_match('/data-snippet="'.$key.'"[^>]*>(.*?)<\/pre>/s', $html, $matches);

    return html_entity_decode($matches[1] ?? '', ENT_QUOTES);
}

it('is for admins only', function (Role $role, int $status) {
    $this->actingAs(staffUser($role))->get(route('admin.settings.mcp'))->assertStatus($status);
})->with([
    'admin' => [Role::Admin, 200],
    'content manager' => [Role::ContentManager, 403],
    'sales manager' => [Role::SalesManager, 403],
    'service engineer' => [Role::ServiceEngineer, 403],
]);

it('forbids every Livewire action for other roles (C28)', function (Role $role) {
    [$ceo, $token] = mcpHolder();
    $this->actingAs(staffUser($role));
    confirmAdminPassword();

    Livewire::test(McpAccess::class)->assertForbidden();

    expect($token->fresh()->revoked_at)->toBeNull()->and($ceo->fresh()->mcp_access_granted_at)->not->toBeNull();
})->with(['content manager' => [Role::ContentManager], 'sales manager' => [Role::SalesManager], 'service engineer' => [Role::ServiceEngineer]]);

it('shows the sidebar entry to admins only', function () {
    $this->actingAs(staffUser(Role::Admin))->get(route('admin.settings.company'))->assertSee(route('admin.settings.mcp'), false);
    $this->actingAs(staffUser(Role::ContentManager))->get(route('admin.dashboard'))->assertDontSee(route('admin.settings.mcp'), false);
});

it('needs a recent password confirmation for every write (C28)', function (string $method, array $params) {
    [$ceo, $token] = mcpHolder();
    $this->actingAs($ceo);

    Livewire::test(McpAccess::class)
        ->set('label', 'X')
        ->call($method, ...array_map(fn ($param) => $param === 'TOKEN' ? $token->id : $param, $params))
        ->assertRedirect(route('admin.confirm-password'));

    expect($token->fresh()->revoked_at)->toBeNull()
        ->and(ApiToken::query()->count())->toBe(1)
        ->and($ceo->fresh()->mcp_access_granted_at)->not->toBeNull();
})->with([
    'generate' => ['generate', []],
    'regenerate' => ['regenerate', ['TOKEN']],
    'revoke token' => ['revokeToken', ['TOKEN']],
    'revoke access' => ['revokeAccess', []],
    'revoke all' => ['revokeAll', []],
    'transfer' => ['transfer', []],
]);

it('grants access to an Admin and refuses a second holder without transfer', function () {
    $this->actingAs($ceo = staffUser(Role::Admin));
    confirmAdminPassword();
    $other = User::factory()->withRole(Role::Admin)->create();

    Livewire::test(McpAccess::class)->set('grantUserId', $ceo->id)->call('grant')->assertHasNoErrors();
    expect($ceo->fresh()->mcp_access_granted_at)->not->toBeNull();

    Livewire::test(McpAccess::class)->set('grantUserId', $other->id)->call('grant')->assertHasErrors('user');
    expect($other->fresh()->mcp_access_granted_at)->toBeNull();

    Livewire::test(McpAccess::class)->set('grantUserId', $other->id)->call('transfer')->assertHasNoErrors();
    expect($other->fresh()->mcp_access_granted_at)->not->toBeNull()->and($ceo->fresh()->mcp_access_granted_at)->toBeNull();
});

it('refuses to grant a non-Admin through a tampered request', function () {
    $this->actingAs(staffUser(Role::Admin));
    confirmAdminPassword();
    $manager = User::factory()->withRole(Role::SalesManager)->create();

    Livewire::test(McpAccess::class)->set('grantUserId', $manager->id)->call('grant')->assertHasErrors('user');

    expect($manager->fresh()->mcp_access_granted_at)->toBeNull();
});

it('generates a token, shows it once with client setup, and never keeps it (C4)', function () {
    [$ceo] = mcpHolder();
    ApiToken::query()->delete();
    $this->actingAs($ceo);
    confirmAdminPassword();

    $component = Livewire::test(McpAccess::class)
        ->set('label', 'MacBook')
        ->set('idleRevokeDays', '60')
        ->call('generate')
        ->assertHasNoErrors()
        ->assertDispatched('mcp-token-generated');

    preg_match('/data-plain-token\s*>(posm_[A-Za-z0-9]{48})</', $component->html(), $matches);
    $plain = $matches[1] ?? null;
    $token = ApiToken::query()->sole();

    expect($plain)->not->toBeNull()
        ->and($token->token_hash)->toBe(hash('sha256', $plain))
        ->and($token->idle_revoke_days)->toBe(60)
        ->and($component->html())->toContain('--scope user')->toContain('mcp-remote@'.McpAccess::MCP_REMOTE_VERSION)
        // C15: the Claude Code command reads the token with `read -rs`, it never contains it.
        ->and(snippet($component->html(), 'code'))->toContain('read -rs POSON_MCP_TOKEN')->toContain('$POSON_MCP_TOKEN')->not->toContain($plain)->not->toContain('posm_')
        ->and(json_encode($component->snapshot))->not->toContain($plain)
        ->and(json_encode($token->fresh()->getAttributes()))->not->toContain($plain)
        ->and(json_encode(SettingsAudit::query()->get()->toArray()))->not->toContain($plain);

    $component->call('$refresh')->assertDontSee($plain);

    expect(SettingsAudit::query()->where('action', AuditAction::Generated)->sole())
        ->area->toBe(SettingsArea::Mcp)->key->toBe('mcp_tokens.'.$token->id);

    // The plain token works on MCP.
    mcpRequest($plain)->assertOk();
});

it('shows validation errors on the generate form', function () {
    [$ceo] = mcpHolder();
    $this->actingAs($ceo);
    confirmAdminPassword();

    Livewire::test(McpAccess::class)->set('label', 'X')->set('allowedIps', "10.0.0.1\nnope")->call('generate')->assertHasErrors('allowed_ips');
    Livewire::test(McpAccess::class)->set('label', 'X')->set('idleRevokeDays', '5')->call('generate')->assertHasErrors('idle_revoke_days');
});

it('regenerates and revokes a token, and the old value stops working', function () {
    [$ceo, $token] = mcpHolder($plain = fakeMcpTokenValue());
    $this->actingAs($ceo);
    confirmAdminPassword();

    Livewire::test(McpAccess::class)->call('regenerate', $token->id)->assertSee('data-plain-token', false);

    expect($token->fresh()->revoked_reason)->toBe(McpRevokeReason::Regenerated);
    mcpRequest($plain)->assertUnauthorized();

    $new = ApiToken::query()->ofType(ApiTokenType::Mcp)->active()->sole();
    Livewire::test(McpAccess::class)->call('revokeToken', $new->id);

    expect($new->fresh()->revoked_reason)->toBe(McpRevokeReason::Admin);
});

it('refuses to act on price API tokens (C5)', function () {
    [$ceo] = mcpHolder();
    $price = ApiToken::factory()->create();
    $this->actingAs($ceo);
    confirmAdminPassword();

    Livewire::test(McpAccess::class)->call('revokeToken', $price->id)->assertNotFound();

    expect($price->fresh()->revoked_at)->toBeNull();
});

it('edits a token\'s idle days and IP allow-list', function () {
    [$ceo, $token] = mcpHolder();
    $this->actingAs($ceo);
    confirmAdminPassword();

    Livewire::test(McpAccess::class)
        ->call('editToken', $token->id)
        ->set('editIdleRevokeDays', '90')
        ->set('editAllowedIps', '203.0.113.0/24')
        ->call('saveTokenSettings')
        ->assertHasNoErrors();

    expect($token->fresh())->idle_revoke_days->toBe(90)->allowed_ips->toBe(['203.0.113.0/24']);
});

it('revokes all MCP access from the header action', function () {
    [$ceo, $token] = mcpHolder();
    $price = ApiToken::factory()->create();
    $this->actingAs($ceo);
    confirmAdminPassword();

    Livewire::test(McpAccess::class)->call('revokeAll');

    expect($token->fresh()->revoked_reason)->toBe(McpRevokeReason::RevokeAll)
        ->and($price->fresh()->revoked_at)->toBeNull();
});

it('warns when the management chat is not configured (C26)', function () {
    freshSecrets([]);

    $this->actingAs(staffUser(Role::Admin))->get(route('admin.settings.mcp'))->assertSee('data-management-missing', false);
});

it('lists calls with filters and shows the before snapshot (C22)', function () {
    [$ceo, $token] = mcpHolder();
    McpCall::factory()->for($ceo)->create(['api_token_id' => $token->id, 'tool' => 'get_my_access']);
    $write = McpCall::factory()->for($ceo)->write(['title' => ['uz' => 'Eski sarlavha']])->create();
    $this->actingAs($ceo);

    Livewire::test(McpAccess::class)
        ->set('tab', 'calls')
        ->assertSee('get_my_access')
        ->set('writesOnly', true)
        ->assertDontSee('data-call="'.($write->id - 1).'"', false)
        ->call('showBefore', $write->id)
        ->assertSee('Eski sarlavha');
});

it('lists the API tokens screen without MCP tokens (C5)', function () {
    [$ceo, $token] = mcpHolder();

    $this->actingAs($ceo)->get(route('admin.settings.api-tokens'))->assertOk()->assertDontSee($token->maskedToken());
});

it('lists an older active token even with many revoked ones, and it stays actionable', function () {
    [$ceo, $active] = mcpHolder(null, ['name' => 'Old laptop', 'created_at' => now()->subDays(30)]);
    ApiToken::factory()->mcp($ceo)->revoked()->count(25)->create();
    $this->actingAs($ceo);
    confirmAdminPassword();

    Livewire::test(McpAccess::class)
        ->assertSee('data-token="'.$active->id.'"', false)
        ->assertSee('Old laptop')
        ->call('editToken', $active->id)
        ->assertSet('editingTokenId', $active->id)
        ->call('revokeToken', $active->id);

    expect($active->fresh()->revoked_reason)->toBe(McpRevokeReason::Admin);
});

it('refuses client-side changes to locked ids', function (string $property) {
    [$ceo, $token] = mcpHolder();
    $this->actingAs($ceo);

    Livewire::test(McpAccess::class)->set($property, $token->id);
})->with(['editingTokenId', 'viewingCallId'])->throws(CannotUpdateLockedPropertyException::class);

it('loads the call log with a fixed number of queries', function () {
    [$ceo, $token] = mcpHolder();
    $this->actingAs($ceo);
    McpCall::factory()->for($ceo)->count(26)->create(['api_token_id' => $token->id]);

    // Warm-up: the first render also loads the permission cache.
    Livewire::test(McpAccess::class)->set('tab', 'calls');
    $few = countQueries(fn () => Livewire::test(McpAccess::class)->set('tab', 'calls'));

    McpCall::factory()->for($ceo)->count(40)->create(['api_token_id' => $token->id]);
    // Denied rows from other users and without a token: still one query per relation.
    McpCall::factory()->count(5)->status(McpCallStatus::Denied)->create(['arguments' => ['reason' => 'not_admin']]);

    $many = countQueries(fn () => Livewire::test(McpAccess::class)->set('tab', 'calls')->assertSee(__('enums.mcp_deny_reason.not_admin')));

    expect($many)->toBe($few);
});
