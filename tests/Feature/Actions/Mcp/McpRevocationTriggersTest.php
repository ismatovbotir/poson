<?php

use App\Enums\McpRevokeReason;
use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\McpCall;

beforeEach(function () {
    mcpTelegramConfigured();
});

it('revokes access when the holder is deactivated or changes the password (C8)', function (array $change, McpRevokeReason $reason) {
    [$ceo, $token] = mcpHolder($plain = fakeMcpTokenValue());

    $ceo->forceFill($change)->save();

    expect($token->fresh()->revoked_reason)->toBe($reason)
        ->and($ceo->fresh()->mcp_access_granted_at)->toBeNull();

    mcpRequest($plain)->assertUnauthorized();
})->with([
    'deactivated' => [['is_active' => false], McpRevokeReason::Deactivated],
    'password changed' => [['password' => 'new-password-123'], McpRevokeReason::PasswordChanged],
]);

it('sweeps users who lost the Admin role or were deactivated by a mass update (C8)', function () {
    [$ceo, $token] = mcpHolder();
    $ceo->syncRoles([Role::SalesManager->value]);

    $this->artisan('mcp:sweep')->assertSuccessful();

    expect($token->fresh()->revoked_reason)->toBe(McpRevokeReason::RoleLost)
        ->and($ceo->fresh()->mcp_access_granted_at)->toBeNull();
});

it('leaves an active Admin holder alone in the sweep', function () {
    [$ceo, $token] = mcpHolder();

    $this->artisan('mcp:sweep')->assertSuccessful();

    expect($token->fresh()->revoked_at)->toBeNull();
});

it('revokes idle and never-used tokens daily and reminds about old ones (C12, C13)', function () {
    [$ceo, $fresh] = mcpHolder(null, ['last_used_at' => now()->subDays(3), 'created_at' => now()->subDays(10)]);
    $neverUsed = ApiToken::factory()->mcp($ceo)->create(['created_at' => now()->subDays(8)]);
    $newUnused = ApiToken::factory()->mcp($ceo)->create(['created_at' => now()->subDays(6)]);
    $idle = ApiToken::factory()->mcp($ceo)->create(['last_used_at' => now()->subDays(46), 'created_at' => now()->subDays(60)]);
    $customIdle = ApiToken::factory()->mcp($ceo)->create(['last_used_at' => now()->subDays(20), 'idle_revoke_days' => 14, 'created_at' => now()->subDays(60)]);
    $longIdle = ApiToken::factory()->mcp($ceo)->create(['last_used_at' => now()->subDays(100), 'idle_revoke_days' => 180, 'created_at' => now()->subDays(200)]);
    $price = ApiToken::factory()->create(['created_at' => now()->subYear()]);

    $this->artisan('mcp:revoke-idle')->assertSuccessful();

    expect($fresh->fresh()->revoked_at)->toBeNull()
        ->and($neverUsed->fresh()->revoked_reason)->toBe(McpRevokeReason::NeverUsed)
        ->and($newUnused->fresh()->revoked_at)->toBeNull()
        ->and($idle->fresh()->revoked_reason)->toBe(McpRevokeReason::Idle)
        ->and($customIdle->fresh()->revoked_reason)->toBe(McpRevokeReason::Idle)
        ->and($longIdle->fresh()->revoked_at)->toBeNull()
        ->and($price->fresh()->revoked_at)->toBeNull();

    $rotations = fn () => collect(mcpAlertTexts())->filter(fn ($text) => str_contains($text, __('telegram.mcp.events.rotation', [], 'uz')))->count();
    expect($rotations())->toBe(1);

    // One reminder per 30 days.
    $this->artisan('mcp:revoke-idle')->assertSuccessful();
    expect($rotations())->toBe(1);
});

it('prunes mcp_calls older than 365 days', function () {
    $old = McpCall::factory()->create(['created_at' => now()->subDays(366)]);
    $recent = McpCall::factory()->create(['created_at' => now()->subDays(300)]);

    $this->artisan('model:prune', ['--model' => [McpCall::class]])->assertSuccessful();

    expect(McpCall::query()->pluck('id')->all())->toBe([$recent->id]);
});
