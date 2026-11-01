<?php

use App\Actions\Mcp\GenerateMcpToken;
use App\Actions\Mcp\GrantMcpAccess;
use App\Actions\Mcp\RevokeAllMcpAccess;
use App\Actions\Mcp\RevokeMcpAccess;
use App\Enums\ApiTokenType;
use App\Enums\AuditAction;
use App\Enums\McpRevokeReason;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Models\ApiToken;
use App\Models\SettingsAudit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    mcpTelegramConfigured();
});

it('grants access to one active Admin only (single holder)', function () {
    $ceo = staffUser(Role::Admin);
    $other = User::factory()->withRole(Role::Admin)->create();

    expect(app(GrantMcpAccess::class)->handle($ceo, $ceo, null))->toBeTrue()
        ->and($ceo->fresh()->mcp_access_granted_at)->not->toBeNull()
        ->and($ceo->fresh()->mcp_access_granted_by)->toBe($ceo->id);

    expect(fn () => app(GrantMcpAccess::class)->handle($other, $ceo, null))->toThrow(ValidationException::class);
    expect($other->fresh()->mcp_access_granted_at)->toBeNull();

    expect(SettingsAudit::query()->sole())->area->toBe(SettingsArea::Mcp)->action->toBe(AuditAction::Granted);
});

it('refuses non-Admin or inactive targets', function (Closure $make) {
    $admin = staffUser(Role::Admin);

    expect(fn () => app(GrantMcpAccess::class)->handle($make(), $admin, null))->toThrow(ValidationException::class);
})->with([
    'content manager' => [fn () => User::factory()->withRole(Role::ContentManager)->create()],
    'inactive admin' => [fn () => User::factory()->withRole(Role::Admin)->create(['is_active' => false])],
]);

it('transfers access and the old holder\'s tokens stop working at once', function () {
    [$old, $token] = mcpHolder($plain = fakeMcpTokenValue());
    mcpRequest($plain)->assertOk();

    $new = User::factory()->withRole(Role::Admin)->create();
    app(GrantMcpAccess::class)->handle($new, $old, null, transfer: true);

    expect($old->fresh()->mcp_access_granted_at)->toBeNull()
        ->and($new->fresh()->mcp_access_granted_at)->not->toBeNull()
        ->and($token->fresh()->revoked_reason)->toBe(McpRevokeReason::Transferred);

    mcpRequest($plain)->assertUnauthorized();
});

it('generates posm_ tokens: hash only, no expiry, max 3, same label regenerates', function () {
    [$ceo] = mcpHolder();
    ApiToken::query()->delete();
    $generate = app(GenerateMcpToken::class);

    [$a, $plainA] = $generate->handle($ceo, ['label' => 'Laptop', 'idle_revoke_days' => '30', 'allowed_ips' => "10.0.0.0/8\n2001:db8::1"], $ceo, null);

    expect($plainA)->toMatch('/^posm_[A-Za-z0-9]{48}$/')
        ->and($a->type)->toBe(ApiTokenType::Mcp)
        ->and($a->token_hash)->toBe(hash('sha256', $plainA))
        ->and($a->token_prefix)->toBe(substr($plainA, 0, 12))
        ->and($a->expires_at)->toBeNull()
        ->and($a->idle_revoke_days)->toBe(30)
        ->and($a->allowed_ips)->toBe(['10.0.0.0/8', '2001:db8::1'])
        ->and(json_encode($a->fresh()->getAttributes()))->not->toContain($plainA);

    $generate->handle($ceo, ['label' => 'Desktop'], $ceo, null);
    $generate->handle($ceo, ['label' => 'Claude Code'], $ceo, null);

    expect(fn () => $generate->handle($ceo, ['label' => 'Fourth'], $ceo, null))->toThrow(ValidationException::class);

    [$b] = $generate->handle($ceo, ['label' => 'laptop'], $ceo, null);

    expect($a->fresh()->revoked_reason)->toBe(McpRevokeReason::Regenerated)
        ->and($b->isRevoked())->toBeFalse()
        ->and(ApiToken::query()->ofType(ApiTokenType::Mcp)->active()->count())->toBe(3);
});

it('validates labels, idle days and IP ranges', function (array $data) {
    [$ceo] = mcpHolder();

    expect(fn () => app(GenerateMcpToken::class)->handle($ceo, $data, $ceo, null))->toThrow(ValidationException::class);
})->with([
    'no label' => [['label' => ' ']],
    'idle too short' => [['label' => 'X', 'idle_revoke_days' => 13]],
    'idle too long' => [['label' => 'X', 'idle_revoke_days' => 181]],
    'bad ip' => [['label' => 'X', 'allowed_ips' => '10.0.0.300']],
    'bad prefix' => [['label' => 'X', 'allowed_ips' => '10.0.0.0/33']],
    'too many ips' => [['label' => 'X', 'allowed_ips' => implode("\n", array_map(fn ($i) => '10.0.0.'.$i, range(1, 11)))]],
]);

it('refuses to generate for a non-holder', function () {
    $admin = staffUser(Role::Admin);

    expect(fn () => app(GenerateMcpToken::class)->handle($admin, ['label' => 'X'], $admin, null))->toThrow(ValidationException::class);
});

it('refuses to generate in production while the management chat is not configured (C26)', function () {
    [$ceo] = mcpHolder();
    freshSecrets(['services.telegram.bot_token' => '123456789:'.str_repeat('M', 35)]);
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => app(GenerateMcpToken::class)->handle($ceo, ['label' => 'X'], $ceo, null))
            ->toThrow(ValidationException::class, __('mcp.errors.management_chat_missing'));
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('revoke all removes every MCP token but leaves price API tokens alone', function () {
    [$ceo, $token] = mcpHolder();
    $price = ApiToken::factory()->create();

    expect(app(RevokeAllMcpAccess::class)->handle($ceo, null))->toBe(1)
        ->and($token->fresh()->revoked_reason)->toBe(McpRevokeReason::RevokeAll)
        ->and($ceo->fresh()->mcp_access_granted_at)->toBeNull()
        ->and($price->fresh()->revoked_at)->toBeNull();
});

it('alerts the management chat on grant, generate and revoke without the token', function () {
    $ceo = staffUser(Role::Admin);
    app(GrantMcpAccess::class)->handle($ceo, $ceo, null);
    [, $plain] = app(GenerateMcpToken::class)->handle($ceo->fresh(), ['label' => 'Laptop'], $ceo, null);
    app(RevokeMcpAccess::class)->handle($ceo, McpRevokeReason::Admin, $ceo);

    $texts = mcpAlertTexts();

    expect($texts)->toHaveCount(3)
        ->and($texts[0])->toContain(__('telegram.mcp.events.granted', [], 'uz'))
        ->and($texts[1])->toContain(__('telegram.mcp.events.token_generated', [], 'uz'))->toContain('Laptop')
        ->and($texts[2])->toContain(__('telegram.mcp.events.access_revoked', [], 'uz'))
        ->and(implode("\n", $texts))->not->toContain($plain);
});
