<?php

use App\Actions\Settings\UpdateCompanySettings;
use App\Enums\BotPurpose;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Support\Analytics\BotRegistry;
use App\Support\CompanySettings;
use App\Support\Site\AiBotRules;

/**
 * The base "*" group, unchanged by the AI bot settings.
 */
function baseRobotsGroup(): string
{
    return implode("\n", [
        'User-agent: *',
        'Allow: /livewire-*/livewire.js',
        'Allow: /livewire-*/js/',
        'Disallow: /admin',
        'Disallow: /livewire',
        'Disallow: /*/search',
        'Disallow: /*?*sort=',
        'Disallow: /*?*f[',
        'Disallow: /*?*f%5B',
    ]);
}

function saveAiBots(array $map): void
{
    app(UpdateCompanySettings::class)->handle(SettingsArea::AiBots, [AiBotRules::SETTING_KEY => $map], null, null);
    CompanySettings::flush();
}

it('offers every AI crawler with a robots token and keeps search engines out of the editable list', function () {
    $editable = AiBotRules::editable();

    expect(array_keys($editable))->toBe([
        'gptbot', 'oai-searchbot', 'chatgpt-user',
        'claudebot', 'claude-searchbot', 'claude-user',
        'perplexitybot', 'perplexity-user',
        'bytespider', 'ccbot', 'meta-externalagent', 'amazonbot', 'duckassistbot',
        'google-extended', 'applebot-extended',
    ])
        ->and(array_keys(AiBotRules::searchEngines()))->toBe(['googlebot', 'bingbot', 'yandexbot', 'applebot']);

    foreach ($editable as $bot) {
        expect($bot->purpose->isAi())->toBeTrue()->and($bot->robotsToken)->not->toBeNull();
    }
});

it('allows every bot by default and builds the two presets', function () {
    expect(app(CompanySettings::class)->aiBots())->toBe(AiBotRules::preset(AiBotRules::PRESET_ALLOW_ALL))
        ->and(array_unique(AiBotRules::preset(AiBotRules::PRESET_ALLOW_ALL)))->toBe(['gptbot' => 'allow']);

    $searchOnly = AiBotRules::preset(AiBotRules::PRESET_SEARCH_ONLY);

    foreach (AiBotRules::editable() as $key => $bot) {
        expect($searchOnly[$key])->toBe($bot->purpose === BotPurpose::Training ? 'deny' : 'allow');
    }

    expect(AiBotRules::presetOf($searchOnly))->toBe(AiBotRules::PRESET_SEARCH_ONLY)
        ->and(AiBotRules::presetOf([]))->toBe(AiBotRules::PRESET_ALLOW_ALL)
        ->and(AiBotRules::presetOf(['gptbot' => 'deny']))->toBeNull();
});

it('renders robots.txt for the "allow all" preset without any bot group', function () {
    saveAiBots(AiBotRules::preset(AiBotRules::PRESET_ALLOW_ALL));

    expect($this->get('/robots.txt')->assertOk()->getContent())->toBe(
        baseRobotsGroup()."\n\nSitemap: ".url('/sitemap.xml')."\n",
    );
});

it('renders robots.txt for the "search only, no training" preset', function () {
    saveAiBots(AiBotRules::preset(AiBotRules::PRESET_SEARCH_ONLY));

    expect($this->get('/robots.txt')->assertOk()->getContent())->toBe(implode("\n\n", [
        baseRobotsGroup(),
        "User-agent: GPTBot\nDisallow: /",
        "User-agent: ClaudeBot\nDisallow: /",
        "User-agent: Bytespider\nDisallow: /",
        "User-agent: CCBot\nDisallow: /",
        "User-agent: meta-externalagent\nDisallow: /",
        "User-agent: Google-Extended\nDisallow: /",
        "User-agent: Applebot-Extended\nDisallow: /",
        'Sitemap: '.url('/sitemap.xml'),
    ])."\n");
});

it('denies only the chosen bots and never a search engine', function () {
    Setting::query()->create([
        'key' => AiBotRules::SETTING_KEY,
        'group' => 'seo',
        'value' => ['perplexitybot' => 'deny', 'googlebot' => 'deny', 'bingbot' => 'deny', 'unknown-bot' => 'deny', 'gptbot' => 'maybe'],
    ]);

    $robots = $this->get('/robots.txt')->assertOk()->getContent();

    expect($robots)->toContain("User-agent: PerplexityBot\nDisallow: /")
        ->and(substr_count($robots, 'User-agent:'))->toBe(2)
        ->and($robots)->not->toContain('Googlebot')
        ->not->toContain('Bingbot')
        ->not->toContain('GPTBot')
        ->not->toContain('unknown-bot');
});

it('keeps the robots tokens from the bot registry', function () {
    foreach (AiBotRules::editable() as $key => $bot) {
        expect(BotRegistry::robotsTokens())->toHaveKey($key);
    }
});
