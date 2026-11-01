<?php

use App\Actions\Mcp\GenerateMcpToken;
use App\Models\SettingsAudit;
use Illuminate\Support\Facades\Process;

/*
| Plan 13 C16: no real-looking token in any tracked file (tests build fake
| values at runtime, docs use "posm_…").
*/

it('has no token-shaped strings in tracked files (C16)', function () {
    $result = Process::path(base_path())->run(['git', 'grep', '-nE', 'pos(m)?_[A-Za-z0-9]{48}', '--', '.', ':!composer.lock', ':!package-lock.json']);

    // git grep exits 1 when nothing matches.
    expect($result->exitCode())->toBe(1, 'Token-shaped value found: '.$result->output());
})->skip(fn () => ! is_dir(base_path('.git')), 'not a git checkout');

it('never stores a token value in settings_audits', function () {
    mcpTelegramConfigured();
    [$admin] = mcpHolder();
    $this->actingAs($admin);

    [$token, $plain] = app(GenerateMcpToken::class)->handle($admin, ['label' => 'Laptop'], $admin, '127.0.0.1');

    expect(json_encode(SettingsAudit::query()->get()->toArray()))->not->toContain($plain)->not->toContain($token->token_hash)
        ->and(implode("\n", mcpAlertTexts()))->not->toContain($plain)->not->toContain($token->token_prefix);
});
