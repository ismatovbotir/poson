<?php

use App\Enums\Role;
use App\Models\McpCall;

beforeEach(function () {
    mcpTelegramConfigured();
});

it('returns who Claude acts as, without any credential or IP (C24)', function () {
    [$admin, $token] = mcpHolder($plain = fakeMcpTokenValue(), ['name' => 'MacBook']);

    $response = mcpCallTool($plain, 'get_my_access')->assertOk();
    $content = $response->json('result.structuredContent');

    expect($content['user'])->toBe(['name' => $admin->name, 'role' => Role::Admin->value])
        ->and($content['auth_type'])->toBe('token')
        ->and($content['token']['label'])->toBe('MacBook')
        ->and($content['token']['expires_at'])->toBeNull()
        ->and($content['token']['idle_revoke_at'])->toEndWith('+05:00')
        ->and($content['tool_groups'])->toBe(['account', 'stats', 'content', 'catalog'])
        ->and(array_keys($content['token']))->toBe(['label', 'created_at', 'last_used_at', 'idle_revoke_at', 'expires_at']);

    $raw = $response->getContent();
    expect($raw)->not->toContain($plain)
        ->not->toContain($token->token_prefix)
        ->not->toContain($token->token_hash)
        ->not->toContain('127.0.0.1')
        ->not->toContain($admin->email);

    expect(McpCall::query()->sole())->tool->toBe('get_my_access')->api_token_id->toBe($token->id)->user_id->toBe($admin->id);
});

it('caps untrusted text in the output (C23)', function () {
    [$admin] = mcpHolder($plain = fakeMcpTokenValue(), ['name' => "Lap\u{202E}top\u{0007}"]);
    $admin->forceFill(['name' => str_repeat('A', 300)])->saveQuietly();

    $content = mcpCallTool($plain, 'get_my_access')->assertOk()->json('result.structuredContent');

    expect(mb_strlen($content['user']['name']))->toBeLessThanOrEqual(100)
        ->and($content['token']['label'])->toBe('Lap top');
});
