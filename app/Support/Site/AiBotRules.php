<?php

namespace App\Support\Site;

use App\Enums\BotPurpose;
use App\Support\Analytics\BotDefinition;
use App\Support\Analytics\BotRegistry;

/**
 * Per-crawler robots.txt policy (plan 04 §8, T12), stored in the setting
 * robots.ai_bots as bot key => "allow" | "deny".
 *
 * Only AI crawlers with a robots.txt token are editable (purpose training,
 * ai_search or ai_user); search engines (Googlebot, Bingbot, Yandex,
 * Applebot) are always allowed. A bot missing from the stored map, or with
 * an unknown value, is allowed: the default policy is "allow all"
 * (decision 2026-10-21).
 */
final class AiBotRules
{
    public const SETTING_KEY = 'robots.ai_bots';

    public const ALLOW = 'allow';

    public const DENY = 'deny';

    public const PRESET_ALLOW_ALL = 'allow_all';

    /**
     * "Search only, no training": deny training crawlers, allow AI search and AI user fetches.
     */
    public const PRESET_SEARCH_ONLY = 'search_only';

    public const PRESETS = [self::PRESET_ALLOW_ALL, self::PRESET_SEARCH_ONLY];

    /**
     * AI crawlers the admin can allow or deny, in registry order.
     *
     * @return array<string, BotDefinition>
     */
    public static function editable(): array
    {
        return array_filter(BotRegistry::robotsTokens(), fn (BotDefinition $bot): bool => $bot->purpose->isAi());
    }

    /**
     * Search engine crawlers: always allowed, shown read-only.
     *
     * @return array<string, BotDefinition>
     */
    public static function searchEngines(): array
    {
        return array_filter(BotRegistry::robotsTokens(), fn (BotDefinition $bot): bool => $bot->purpose === BotPurpose::Search);
    }

    /**
     * The full map of a preset.
     *
     * @return array<string, string>
     */
    public static function preset(string $preset): array
    {
        return array_map(
            fn (BotDefinition $bot): string => $preset === self::PRESET_SEARCH_ONLY && $bot->purpose === BotPurpose::Training ? self::DENY : self::ALLOW,
            self::editable(),
        );
    }

    /**
     * A stored value as a full map over the editable bots (unknown keys
     * dropped, missing or invalid values allowed).
     *
     * @return array<string, string>
     */
    public static function normalize(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];

        return array_map(
            fn (BotDefinition $bot): string => ($stored[$bot->key] ?? null) === self::DENY ? self::DENY : self::ALLOW,
            self::editable(),
        );
    }

    /**
     * The preset a map equals, or null for a custom choice.
     *
     * @param  array<string, string>  $map
     */
    public static function presetOf(array $map): ?string
    {
        $map = self::normalize($map);

        foreach (self::PRESETS as $preset) {
            if (self::preset($preset) === $map) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * One "Disallow: /" group per denied bot, in registry order.
     *
     * @param  array<string, string>  $map
     * @return list<RobotsGroup>
     */
    public static function deniedGroups(array $map): array
    {
        $groups = [];

        foreach (self::editable() as $key => $bot) {
            if (($map[$key] ?? self::ALLOW) === self::DENY) {
                $groups[] = new RobotsGroup([(string) $bot->robotsToken], disallow: ['/']);
            }
        }

        return $groups;
    }
}
