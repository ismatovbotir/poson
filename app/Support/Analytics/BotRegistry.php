<?php

namespace App\Support\Analytics;

use App\Enums\BotPurpose;

/**
 * Known crawlers from config/ai-bots.php, in match order (plan 04 S13).
 *
 * Stable API (also used by robots.txt and /llms.txt):
 *   all()            every definition, robots-only tokens included
 *   find($key)       one definition or null
 *   match($ua)       the crawler a User-Agent belongs to, or null for a human
 *   isBot($ua)       shorthand for match() !== null
 *   robotsTokens()   definitions that have a robots.txt token (not "other")
 */
final class BotRegistry
{
    /**
     * @var array<string, BotDefinition>|null
     */
    private static ?array $definitions = null;

    /**
     * Combined regex of every User-Agent pattern (fast "is this a bot at all" check).
     */
    private static ?string $anyPattern = null;

    /**
     * @var array<string, BotDefinition|false>
     */
    private static array $matchCache = [];

    /**
     * @return array<string, BotDefinition>
     */
    public static function all(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        $definitions = [];

        foreach ((array) config('ai-bots', []) as $key => $bot) {
            $definitions[$key] = new BotDefinition(
                key: (string) $key,
                name: (string) $bot['name'],
                vendor: (string) ($bot['vendor'] ?? ''),
                purpose: BotPurpose::from((string) $bot['purpose']),
                userAgentPattern: $bot['user_agent'] ?? null,
                robotsToken: $bot['robots_token'] ?? null,
                ipRangeUrls: array_values((array) ($bot['ip_ranges'] ?? [])),
                rdnsDomains: array_values((array) ($bot['rdns'] ?? [])),
            );
        }

        return self::$definitions = $definitions;
    }

    public static function find(?string $key): ?BotDefinition
    {
        return $key === null ? null : (self::all()[$key] ?? null);
    }

    /**
     * The crawler a User-Agent claims to be (first matching entry), or null.
     * Claims are not trusted here: VerifyBotHits checks them later.
     */
    public static function match(?string $userAgent): ?BotDefinition
    {
        $userAgent = trim((string) $userAgent);

        if ($userAgent === '') {
            return null;
        }

        if (array_key_exists($userAgent, self::$matchCache)) {
            return self::$matchCache[$userAgent] ?: null;
        }

        $match = null;

        if (preg_match(self::anyPattern(), $userAgent) === 1) {
            foreach (self::all() as $bot) {
                if ($bot->matches($userAgent)) {
                    $match = $bot;

                    break;
                }
            }
        }

        // A small per-process cache: crawlers repeat the same User-Agent.
        if (count(self::$matchCache) > 500) {
            self::$matchCache = [];
        }

        self::$matchCache[$userAgent] = $match ?? false;

        return $match;
    }

    public static function isBot(?string $userAgent): bool
    {
        return self::match($userAgent) !== null;
    }

    /**
     * Crawlers that can be addressed in robots.txt, in registry order.
     *
     * @return array<string, BotDefinition>
     */
    public static function robotsTokens(): array
    {
        return array_filter(self::all(), fn (BotDefinition $bot): bool => $bot->robotsToken !== null);
    }

    /**
     * Crawlers that appear in traffic (robots-only tokens excluded), in registry order.
     *
     * @return array<string, BotDefinition>
     */
    public static function trackable(): array
    {
        return array_filter(self::all(), fn (BotDefinition $bot): bool => ! $bot->isRobotsOnly());
    }

    /**
     * Forget the parsed config (tests that change config('ai-bots')).
     */
    public static function flush(): void
    {
        self::$definitions = null;
        self::$anyPattern = null;
        self::$matchCache = [];
    }

    private static function anyPattern(): string
    {
        if (self::$anyPattern !== null) {
            return self::$anyPattern;
        }

        $parts = [];

        foreach (self::all() as $bot) {
            if ($bot->userAgentPattern === null) {
                continue;
            }

            // "~body~flags" → "(?flags:body)"
            $delimiter = $bot->userAgentPattern[0];
            $end = strrpos($bot->userAgentPattern, $delimiter);
            $body = substr($bot->userAgentPattern, 1, $end - 1);
            $flags = substr($bot->userAgentPattern, $end + 1);
            $parts[] = $flags !== '' ? "(?{$flags}:{$body})" : "(?:{$body})";
        }

        return self::$anyPattern = $parts === [] ? '~(?!)~' : '~'.implode('|', $parts).'~';
    }
}
