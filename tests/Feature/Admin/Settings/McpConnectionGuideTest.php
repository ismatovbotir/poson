<?php

use App\Livewire\Admin\Settings\McpAccess;
use App\Models\ApiToken;
use App\Support\Mcp\ConnectionGuide;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

beforeEach(function () {
    mcpTelegramConfigured();
});

it('shows the placeholder and never a full token on the page without a reveal (M-2)', function () {
    [$ceo] = mcpHolder($plain = fakeMcpTokenValue());
    $this->actingAs($ceo);

    $component = Livewire::test(McpAccess::class)->assertSee('PASTE_TOKEN_HERE')->assertSee('data-mcp-guide', false);

    expect($component->html())->not->toContain($plain)->not->toMatch('/posm_[A-Za-z0-9]{48}/')
        ->and(json_encode($component->snapshot))->not->toContain($plain);

    $this->get(route('admin.settings.mcp'))->assertOk()->assertDontSee($plain);
});

it('builds the config with the token in env only and the pinned mcp-remote (M-2, M-5)', function () {
    $entry = json_decode(ConnectionGuide::config('https://poson.test/mcp/admin', $plain = fakeMcpTokenValue()), true)['mcpServers']['poson'];
    $placeholder = json_decode(ConnectionGuide::config('https://poson.test/mcp/admin'), true)['mcpServers']['poson'];
    $merge = json_decode(ConnectionGuide::mergeExample('https://poson.test/mcp/admin'), true)['mcpServers'];

    expect($entry['env']['POSON_MCP_AUTH'])->toBe('Bearer '.$plain)
        ->and($entry['args'])->toContain('mcp-remote@0.1.16')->toContain('Authorization:${POSON_MCP_AUTH}')
        ->and(implode(' ', $entry['args']))->not->toContain($plain)
        ->and($placeholder['env']['POSON_MCP_AUTH'])->toBe('Bearer PASTE_TOKEN_HERE')
        ->and($merge)->toHaveKeys(['existing-server', 'poson'])
        ->and($merge['poson']['env']['POSON_MCP_AUTH'])->toBe('Bearer PASTE_TOKEN_HERE')
        ->and(McpAccess::MCP_REMOTE_VERSION)->toBe('0.1.16')
        ->and(ConnectionGuide::terminalTest('https://x.test'))->toContain('"protocolVersion":"'.McpAccess::MCP_PROTOCOL_VERSION.'"');
});

it('puts the real token into the single guide in the show-once window only', function () {
    [$ceo] = mcpHolder();
    ApiToken::query()->delete();
    $this->actingAs($ceo);
    confirmAdminPassword();

    $component = Livewire::test(McpAccess::class)->set('label', 'MacBook')->call('generate');
    $html = $component->html();
    preg_match('/data-plain-token\s*>(posm_[A-Za-z0-9]{48})</', $html, $matches);
    $plain = $matches[1];

    preg_match_all('/data-guide-snippet="desktop"[^>]*>(.*?)<\/pre>/s', $html, $guide);
    $config = json_decode(html_entity_decode($guide[1][0], ENT_QUOTES), true);

    expect($guide[1])->toHaveCount(1)
        ->and($config['mcpServers']['poson']['env']['POSON_MCP_AUTH'])->toBe('Bearer '.$plain)
        ->and(substr_count(html_entity_decode($html, ENT_QUOTES), 'Bearer '.$plain))->toBe(1); // only in the config <pre>; the copy button reads the element text

    $component->call('$refresh')->assertDontSee($plain);
    expect(json_encode($component->snapshot))->not->toContain($plain);
});

it('has the guide steps, the failure table and the warnings in every locale', function (string $locale) {
    $guide = (require lang_path("{$locale}/mcp.php"))['guide'];

    foreach (['node', 'open', 'paste', 'restart', 'protect', 'check', 'verify'] as $step) {
        expect($guide['steps'][$step]['title'])->toBeString()->not->toBeEmpty();
    }

    $codes = implode(' ', array_column($guide['trouble']['rows'], 0));
    expect($codes)->toContain('401')->toContain('403')->toContain('429')
        ->and($guide['connector'])->toContain('Anthropic')->toContain('Add custom connector')
        ->and($guide['logs']['warning'])->not->toBeEmpty();

    app()->setLocale($locale);
    [$ceo] = mcpHolder();
    $this->actingAs($ceo);

    Livewire::test(McpAccess::class)
        ->assertSee($guide['steps']['node']['title'])
        ->assertSee($guide['steps']['verify']['title'])
        ->assertSee($guide['connector'])
        ->assertDontSee('Request headers');
})->with(['uz', 'ru', 'en']);

it('translates the whole guide tree (no string left identical to English)', function (string $locale) {
    $en = Arr::dot((require lang_path('en/mcp.php'))['guide']);
    $other = Arr::dot((require lang_path("{$locale}/mcp.php"))['guide']);
    $allowed = ['macOS', 'Windows', '401', '429', 'Node.js'];

    expect(array_keys($other))->toBe(array_keys($en));

    $untranslated = [];
    foreach ($en as $key => $value) {
        if (! in_array($value, $allowed, true) && $other[$key] === $value) {
            $untranslated[] = $key;
        }
    }

    expect($untranslated)->toBe([]);
})->with(['uz', 'ru']);

it('never contains the forbidden advice (M-5) in the rendered guide of any locale', function (string $locale) {
    app()->setLocale($locale);
    $endpoint = 'https://poson.test/mcp/admin';
    $html = Blade::render('<x-admin.mcp-setup-guide :endpoint="$endpoint" />', ['endpoint' => $endpoint])
        .Blade::render('<x-admin.mcp-setup-guide :endpoint="$endpoint" :token="$token" />', ['endpoint' => $endpoint, 'token' => fakeMcpTokenValue()]);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES);

    $patterns = [
        '/\bsudo\b/i', '/--debug\b/', '/--allow-http\b/', '/@latest\b/', '/\bcurl\s+-v\b/', '/--trace\b/',
        '/NODE_TLS_REJECT_UNAUTHORIZED/', '/\.mcp\.json/', '/--scope\s+project\b/', '/\.bak\b/', '/\|\s*bash\b/',
        '/\scurl\b[^\n]*\s-k\s/', '/mcp-remote@(?!0\.1\.16\b)/',
        '/(online|онлайн|onlayn)/iu',
    ];
    foreach ($patterns as $pattern) {
        expect($text)->not->toMatch($pattern);
    }

    // Warnings that tell the reader NOT to turn protection off are fine; strip them, then nothing may advise it.
    $advice = str_replace(['Do not turn any protection off.', 'Не отключайте никакую защиту.', 'Hech qanday himoyani oʻchirmang.'], '', $text);
    expect($advice)->not->toMatch('/\b(turn off|disable)\b/i')->not->toContain('отключ')->not->toMatch('/oʻchir(?!ing|mang)/u');
})->with(['uz', 'ru', 'en']);

it('keeps the Uzbek apostrophes correct in the MCP copy', function () {
    $uz = file_get_contents(lang_path('uz/mcp.php'));

    expect($uz)->not->toContain("\u{2019}")->not->toContain("\u{2018}")->not->toMatch("/\\p{L}'\\p{L}/u");
});

it('does not offer the Windows log search as a command that prints matching lines', function () {
    foreach (['uz', 'ru', 'en'] as $locale) {
        $guide = (require lang_path("{$locale}/mcp.php"))['guide'];

        expect($guide['logs']['path_win'])->not->toContain('Select-String')->not->toContain('findstr');
    }
});

it('offers the read-only connection test with the protocol version the server accepts (M-1)', function () {
    [$ceo] = mcpHolder($plain = fakeMcpTokenValue());
    $this->actingAs($ceo);

    $html = Livewire::test(McpAccess::class)->html();
    expect($html)->toContain('read -rs T;')->toContain('&quot;protocolVersion&quot;:&quot;'.McpAccess::MCP_PROTOCOL_VERSION.'&quot;')
        ->and($html)->not->toContain($plain);

    $response = mcpRequest($plain, 'initialize', [
        'protocolVersion' => McpAccess::MCP_PROTOCOL_VERSION,
        'capabilities' => (object) [],
        'clientInfo' => ['name' => 'poson-check', 'version' => '1'],
    ])->assertOk();

    expect($response->json('result.protocolVersion'))->toBe(McpAccess::MCP_PROTOCOL_VERSION)
        ->and($response->json('error'))->toBeNull();
});
