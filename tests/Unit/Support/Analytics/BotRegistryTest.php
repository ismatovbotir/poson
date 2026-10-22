<?php

use App\Enums\BotPurpose;
use App\Enums\BotVerification;
use App\Support\Analytics\BotDefinition;
use App\Support\Analytics\BotRegistry;

it('recognizes crawlers by User-Agent', function (string $userAgent, ?string $key) {
    expect(BotRegistry::match($userAgent)?->key)->toBe($key)
        ->and(BotRegistry::isBot($userAgent))->toBe($key !== null);
})->with([
    'GPTBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot', 'gptbot'],
    'OAI-SearchBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot', 'oai-searchbot'],
    'ChatGPT-User' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot', 'chatgpt-user'],
    'ClaudeBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', 'claudebot'],
    'Claude-SearchBot' => ['Mozilla/5.0 (compatible; Claude-SearchBot/1.0; +https://www.anthropic.com)', 'claude-searchbot'],
    'Claude-User' => ['Mozilla/5.0 (compatible; Claude-User/1.0; +Claude-User@anthropic.com)', 'claude-user'],
    'PerplexityBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)', 'perplexitybot'],
    'Perplexity-User' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)', 'perplexity-user'],
    'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'googlebot'],
    'Googlebot smartphone' => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'googlebot'],
    'Bingbot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0 Safari/537.36', 'bingbot'],
    'YandexBot' => ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)', 'yandexbot'],
    'YandexImages' => ['Mozilla/5.0 (compatible; YandexImages/3.0; +http://yandex.com/bots)', 'yandexbot'],
    'Applebot' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)', 'applebot'],
    'Bytespider' => ['Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)', 'bytespider'],
    'CCBot' => ['CCBot/2.0 (https://commoncrawl.org/faq/)', 'ccbot'],
    'Meta-ExternalAgent' => ['meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)', 'meta-externalagent'],
    'Amazonbot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot) Chrome/119.0 Safari/537.36', 'amazonbot'],
    'DuckAssistBot' => ['DuckAssistBot/1.2; (+http://duckduckgo.com/duckassistbot.html)', 'duckassistbot'],
    'AhrefsBot (other)' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', 'other'],
    'TelegramBot preview (other)' => ['TelegramBot (like TwitterBot)', 'other'],
    'facebookexternalhit (other)' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'other'],
    'generic crawler (other)' => ['SomeCrawler/1.0', 'other'],
    'Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', null],
    'iPhone Safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', null],
    'Cubot phone' => ['Mozilla/5.0 (Linux; Android 10; Cubot X30) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36', null],
    'empty' => ['', null],
]);

it('exposes a stable definition per crawler', function () {
    $all = BotRegistry::all();

    expect($all)->toHaveKeys([
        'gptbot', 'oai-searchbot', 'chatgpt-user', 'claudebot', 'claude-searchbot', 'claude-user',
        'perplexitybot', 'perplexity-user', 'googlebot', 'bingbot', 'yandexbot', 'applebot',
        'bytespider', 'ccbot', 'meta-externalagent', 'amazonbot', 'duckassistbot',
        'google-extended', 'applebot-extended', 'other',
    ])->and(array_key_last($all))->toBe('other');

    foreach ($all as $key => $bot) {
        expect($bot)->toBeInstanceOf(BotDefinition::class)
            ->and($bot->key)->toBe($key)
            ->and($bot->purpose)->toBeInstanceOf(BotPurpose::class)
            ->and($bot->verification())->toBeInstanceOf(BotVerification::class);

        foreach ($bot->ipRangeUrls as $url) {
            expect($url)->toStartWith('https://');
        }
    }

    expect(BotRegistry::find('gptbot'))
        ->name->toBe('GPTBot')->vendor->toBe('OpenAI')->purpose->toBe(BotPurpose::Training)
        ->and(BotRegistry::find('gptbot')->verification())->toBe(BotVerification::IpRanges)
        ->and(BotRegistry::find('yandexbot')->verification())->toBe(BotVerification::ReverseDns)
        ->and(BotRegistry::find('bytespider')->verification())->toBe(BotVerification::UserAgentOnly)
        ->and(BotRegistry::find('chatgpt-user')->purpose)->toBe(BotPurpose::AiUser)
        ->and(BotRegistry::find('oai-searchbot')->purpose)->toBe(BotPurpose::AiSearch)
        ->and(BotRegistry::find('googlebot')->purpose)->toBe(BotPurpose::Search)
        ->and(BotRegistry::find('nope'))->toBeNull();
});

it('lists robots.txt tokens including robots-only ones, but not the generic entry', function () {
    $tokens = BotRegistry::robotsTokens();

    expect($tokens)->toHaveKeys(['google-extended', 'applebot-extended', 'gptbot'])
        ->not->toHaveKey('other')
        ->and($tokens['google-extended']->isRobotsOnly())->toBeTrue()
        ->and($tokens['google-extended']->robotsToken)->toBe('Google-Extended')
        ->and(BotRegistry::trackable())->not->toHaveKey('google-extended')
        ->and(BotRegistry::trackable())->toHaveKey('other');
});
