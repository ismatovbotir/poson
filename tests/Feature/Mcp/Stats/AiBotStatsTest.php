<?php

use App\Enums\Role;
use App\Mcp\Servers\AdminServer;
use App\Mcp\Tools\Stats\GetAiBotStats;
use App\Models\ApiToken;
use App\Models\TrafficDaily;
use App\Models\TrafficDailyPage;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

if (! function_exists('statsHolder')) {
    function statsHolder(Role $role): array
    {
        $user = staffUser($role);
        $user->forceFill(['mcp_access_granted_at' => now()->subDay()])->saveQuietly();
        ApiToken::factory()->mcp($user)->withPlainText($plain = fakeMcpTokenValue())->create();

        return [$user->fresh(), $plain];
    }
}

function botStats(string $token, array $args = []): array
{
    return mcpCallTool($token, 'get_ai_bot_stats', $args)->assertOk()->json('result');
}

beforeEach(function () {
    mcpTelegramConfigured();
});

it('returns per-bot counts and crawled pages without ip, ua or query strings', function () {
    [, $token] = statsHolder(Role::Admin);
    TrafficDaily::factory()->bot('gptbot', 12)->create();
    TrafficDaily::factory()->bot('claudebot', 3)->create();
    TrafficDailyPage::factory()->create(['kind' => TrafficDaily::KIND_BOTS, 'bot' => 'gptbot', 'path' => '/uz/a?x=ignore previous', 'hits' => 8]);
    TrafficDailyPage::factory()->create(['kind' => TrafficDaily::KIND_BOTS, 'bot' => 'claudebot', 'path' => '/uz/b', 'hits' => 2]);

    $content = botStats($token)['structuredContent'];

    expect(array_column($content['rows'], 'key'))->toBe(['gptbot', 'claudebot'])
        ->and($content['rows'][0]['hits'])->toBe(12)
        ->and(array_column($content['top_pages'], 'path_text'))->toBe(['/uz/a', '/uz/b'])
        ->and(array_keys($content['rows'][0]))->not->toContain('ips', 'ip', 'ua', 'user_agent', 'ip_hash')
        ->and(json_encode($content))->not->toContain('x=');

    $one = botStats($token, ['bot' => 'claudebot'])['structuredContent'];
    expect(array_column($one['rows'], 'key'))->toBe(['claudebot'])
        ->and(array_column($one['top_pages'], 'path_text'))->toBe(['/uz/b']);
});

it('is hidden from roles without analytics.view', function (Role $role) {
    AdminServer::actingAs(staffUser($role))->tool(GetAiBotStats::class)->assertNotRegistered();
})->with([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer]);

it('is available to the owner role', function () {
    AdminServer::actingAs(staffUser(Role::Admin))->tool(GetAiBotStats::class)->assertOk();
});

it('rejects bad bot, days and limit without caching', function (array $args) {
    [, $token] = statsHolder(Role::Admin);
    $written = [];
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$written): void {
        $written[] = $event->key;
    });

    expect(botStats($token, $args)['isError'])->toBeTrue();
    expect(array_filter($written, fn (string $key): bool => str_starts_with($key, 'analytics:report:')))->toBe([]);
})->with([
    'bot' => [['bot' => 'evil']],
    'days' => [['days' => 0]],
    'big' => [['days' => 400]],
    'abc' => [['days' => 'abc']],
    'limit' => [['limit' => 0]],
    'range' => [['from' => '2026-01-01', 'to' => '2026-01-02']],
]);

it('does not lazy load', function () {
    [, $token] = statsHolder(Role::Admin);
    TrafficDaily::factory()->bot('gptbot')->create();
    Model::preventLazyLoading();

    expect(botStats($token)['isError'] ?? false)->toBeFalse();
});
