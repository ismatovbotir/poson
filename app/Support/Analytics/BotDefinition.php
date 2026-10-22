<?php

namespace App\Support\Analytics;

use App\Enums\BotPurpose;
use App\Enums\BotVerification;

/**
 * One crawler from config/ai-bots.php (see BotRegistry).
 */
final readonly class BotDefinition
{
    /**
     * Key of the catch-all entry for unknown crawlers.
     */
    public const OTHER = 'other';

    /**
     * @param  string  $key  Stable id stored in bot_hits.bot and traffic_daily
     * @param  string|null  $userAgentPattern  Full regex (with delimiters); null for robots.txt-only tokens
     * @param  string|null  $robotsToken  User-agent token for robots.txt; null when not addressable
     * @param  list<string>  $ipRangeUrls  Published IP-range JSON URLs
     * @param  list<string>  $rdnsDomains  Reverse-DNS domains of genuine crawler IPs
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $vendor,
        public BotPurpose $purpose,
        public ?string $userAgentPattern,
        public ?string $robotsToken,
        public array $ipRangeUrls = [],
        public array $rdnsDomains = [],
    ) {}

    /**
     * Primary verification method: published IP ranges, else reverse DNS,
     * else the User-Agent alone.
     */
    public function verification(): BotVerification
    {
        return match (true) {
            $this->ipRangeUrls !== [] => BotVerification::IpRanges,
            $this->rdnsDomains !== [] => BotVerification::ReverseDns,
            default => BotVerification::UserAgentOnly,
        };
    }

    public function isVerifiable(): bool
    {
        return $this->verification() !== BotVerification::UserAgentOnly;
    }

    /**
     * Only a robots.txt token (Google-Extended, Applebot-Extended): never seen in traffic.
     */
    public function isRobotsOnly(): bool
    {
        return $this->userAgentPattern === null;
    }

    public function isOther(): bool
    {
        return $this->key === self::OTHER;
    }

    public function matches(string $userAgent): bool
    {
        return $this->userAgentPattern !== null && preg_match($this->userAgentPattern, $userAgent) === 1;
    }

    /**
     * Name for screens: the product name, or the translated "Other bots".
     */
    public function label(): string
    {
        return $this->isOther() ? __('analytics.bots.other') : $this->name;
    }
}
