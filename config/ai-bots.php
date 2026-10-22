<?php

/*
|--------------------------------------------------------------------------
| Crawler registry (plan 04 S13)
|--------------------------------------------------------------------------
|
| Read through App\Support\Analytics\BotRegistry (never directly). Order
| matters: a User-Agent is matched against the entries top to bottom and the
| first hit wins, so specific tokens come before the generic "other" entry.
|
| Keys per bot:
|   name          Display name (product name, not translated)
|   vendor        Company behind the crawler
|   purpose       App\Enums\BotPurpose value
|   user_agent    Regex matched against the User-Agent; null = robots.txt-only
|                 token that never appears in a User-Agent
|   robots_token  User-agent line for robots.txt (null = not addressable)
|   ip_ranges     Published IP-range JSON URLs (CIDR "prefixes"), refreshed
|                 weekly into storage/app/bot-ranges.json
|   rdns          Domains the reverse DNS of a genuine IP ends with (checked
|                 with a forward confirmation); used when no ranges are loaded
|
| Every URL and domain below was checked on 2026-10-21 (HTTP 200 with a
| "prefixes" list, or a real crawler IP whose PTR record ends with the
| domain). Entries with neither are identified by User-Agent only.
|
*/

return [

    // OpenAI: https://platform.openai.com/docs/bots
    'gptbot' => [
        'name' => 'GPTBot',
        'vendor' => 'OpenAI',
        'purpose' => 'training',
        'user_agent' => '~GPTBot~i',
        'robots_token' => 'GPTBot',
        'ip_ranges' => ['https://openai.com/gptbot.json'],
        'rdns' => [],
    ],
    'oai-searchbot' => [
        'name' => 'OAI-SearchBot',
        'vendor' => 'OpenAI',
        'purpose' => 'ai_search',
        'user_agent' => '~OAI-SearchBot~i',
        'robots_token' => 'OAI-SearchBot',
        'ip_ranges' => ['https://openai.com/searchbot.json'],
        'rdns' => [],
    ],
    'chatgpt-user' => [
        'name' => 'ChatGPT-User',
        'vendor' => 'OpenAI',
        'purpose' => 'ai_user',
        'user_agent' => '~ChatGPT-User~i',
        'robots_token' => 'ChatGPT-User',
        'ip_ranges' => ['https://openai.com/chatgpt-user.json'],
        'rdns' => [],
    ],

    // Anthropic: one published list for all three bots (linked from the Claude help centre).
    'claudebot' => [
        'name' => 'ClaudeBot',
        'vendor' => 'Anthropic',
        'purpose' => 'training',
        'user_agent' => '~ClaudeBot~i',
        'robots_token' => 'ClaudeBot',
        'ip_ranges' => ['https://claude.com/crawling/bots.json'],
        'rdns' => [],
    ],
    'claude-searchbot' => [
        'name' => 'Claude-SearchBot',
        'vendor' => 'Anthropic',
        'purpose' => 'ai_search',
        'user_agent' => '~Claude-SearchBot~i',
        'robots_token' => 'Claude-SearchBot',
        'ip_ranges' => ['https://claude.com/crawling/bots.json'],
        'rdns' => [],
    ],
    'claude-user' => [
        'name' => 'Claude-User',
        'vendor' => 'Anthropic',
        'purpose' => 'ai_user',
        'user_agent' => '~Claude-User~i',
        'robots_token' => 'Claude-User',
        'ip_ranges' => ['https://claude.com/crawling/bots.json'],
        'rdns' => [],
    ],

    // Perplexity: https://docs.perplexity.ai/guides/bots
    'perplexitybot' => [
        'name' => 'PerplexityBot',
        'vendor' => 'Perplexity',
        'purpose' => 'ai_search',
        'user_agent' => '~PerplexityBot~i',
        'robots_token' => 'PerplexityBot',
        'ip_ranges' => ['https://www.perplexity.ai/perplexitybot.json'],
        'rdns' => [],
    ],
    'perplexity-user' => [
        'name' => 'Perplexity-User',
        'vendor' => 'Perplexity',
        'purpose' => 'ai_user',
        'user_agent' => '~Perplexity-User~i',
        'robots_token' => 'Perplexity-User',
        'ip_ranges' => ['https://www.perplexity.ai/perplexity-user.json'],
        'rdns' => [],
    ],

    // Search engines. Google moved its lists to /static/crawling/ipranges/ (the old
    // /search/apis/ipranges/googlebot.json URL redirects to common-crawlers.json).
    'googlebot' => [
        'name' => 'Googlebot',
        'vendor' => 'Google',
        'purpose' => 'search',
        'user_agent' => '~Googlebot~i',
        'robots_token' => 'Googlebot',
        'ip_ranges' => ['https://developers.google.com/static/crawling/ipranges/common-crawlers.json'],
        'rdns' => ['googlebot.com', 'google.com', 'googleusercontent.com'],
    ],
    'bingbot' => [
        'name' => 'Bingbot',
        'vendor' => 'Microsoft',
        'purpose' => 'search',
        'user_agent' => '~bingbot~i',
        'robots_token' => 'Bingbot',
        'ip_ranges' => ['https://www.bing.com/toolbox/bingbot.json'],
        'rdns' => ['search.msn.com'],
    ],
    'yandexbot' => [
        'name' => 'YandexBot',
        'vendor' => 'Yandex',
        'purpose' => 'search',
        'user_agent' => '~Yandex(?:Bot|MobileBot|Images|Video|Accessibility)~i',
        'robots_token' => 'Yandex',
        'ip_ranges' => [],
        // Yandex publishes no IP list; verification is by reverse DNS (yandex.ru / .net / .com).
        'rdns' => ['yandex.ru', 'yandex.net', 'yandex.com'],
    ],
    'applebot' => [
        'name' => 'Applebot',
        'vendor' => 'Apple',
        'purpose' => 'search',
        'user_agent' => '~Applebot~i',
        'robots_token' => 'Applebot',
        'ip_ranges' => ['https://search.developer.apple.com/applebot.json'],
        'rdns' => ['applebot.apple.com'],
    ],

    // Other AI crawlers.
    'bytespider' => [
        'name' => 'Bytespider',
        'vendor' => 'ByteDance',
        'purpose' => 'training',
        'user_agent' => '~Bytespider~i',
        'robots_token' => 'Bytespider',
        // ByteDance publishes no IP list or rDNS domain: User-Agent only.
        'ip_ranges' => [],
        'rdns' => [],
    ],
    'ccbot' => [
        'name' => 'CCBot',
        'vendor' => 'Common Crawl',
        'purpose' => 'training',
        'user_agent' => '~CCBot~i',
        'robots_token' => 'CCBot',
        'ip_ranges' => ['https://index.commoncrawl.org/ccbot.json'],
        'rdns' => [],
    ],
    'meta-externalagent' => [
        'name' => 'Meta-ExternalAgent',
        'vendor' => 'Meta',
        'purpose' => 'training',
        'user_agent' => '~meta-externalagent~i',
        'robots_token' => 'meta-externalagent',
        // Meta only points to its ASN (AS32934) in whois, no JSON list: User-Agent only.
        'ip_ranges' => [],
        'rdns' => [],
    ],
    'amazonbot' => [
        'name' => 'Amazonbot',
        'vendor' => 'Amazon',
        'purpose' => 'ai_search',
        'user_agent' => '~Amazonbot~i',
        'robots_token' => 'Amazonbot',
        // Amazon lists IPs on an HTML page (developer.amazon.com/amazonbot/ip-addresses/),
        // not JSON, and those IPs resolve to generic EC2 names: User-Agent only.
        'ip_ranges' => [],
        'rdns' => [],
    ],
    'duckassistbot' => [
        'name' => 'DuckAssistBot',
        'vendor' => 'DuckDuckGo',
        'purpose' => 'ai_user',
        'user_agent' => '~DuckAssistBot~i',
        'robots_token' => 'DuckAssistBot',
        'ip_ranges' => ['https://duckduckgo.com/duckassistbot.json'],
        'rdns' => [],
    ],

    // robots.txt-only tokens: they control AI use of content fetched by the
    // regular crawler and never appear in a User-Agent.
    'google-extended' => [
        'name' => 'Google-Extended',
        'vendor' => 'Google',
        'purpose' => 'training',
        'user_agent' => null,
        'robots_token' => 'Google-Extended',
        'ip_ranges' => [],
        'rdns' => [],
    ],
    'applebot-extended' => [
        'name' => 'Applebot-Extended',
        'vendor' => 'Apple',
        'purpose' => 'training',
        'user_agent' => null,
        'robots_token' => 'Applebot-Extended',
        'ip_ranges' => [],
        'rdns' => [],
    ],

    // Anything else that calls itself a bot ("CUBOT" phones excluded). Must stay last.
    'other' => [
        'name' => 'Other bots',
        'vendor' => '',
        'purpose' => 'other',
        'user_agent' => '~(?<!cu)bot\b|crawl|spider|slurp|facebookexternalhit~i',
        'robots_token' => null,
        'ip_ranges' => [],
        'rdns' => [],
    ],

];
