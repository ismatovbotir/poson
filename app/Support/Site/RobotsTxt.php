<?php

namespace App\Support\Site;

use App\Support\CompanySettings;

/**
 * /robots.txt (plan 03 D16 base rules; plan 04 adds per-bot groups).
 *
 * The "*" group keeps crawlers out of the admin, Livewire endpoints, search
 * results and filtered or sorted listings. Filter URLs carry brackets
 * ("f[code][0]=12"), which some clients percent-encode, so both forms are
 * listed.
 *
 * Plan 04 T12: each AI crawler denied in Settings → Company → AI bots gets
 * its own "User-agent: <token> / Disallow: /" group after "*"; allowed
 * crawlers simply follow "*" (no empty groups). withGroup() adds further
 * groups without touching the base rules.
 *
 * Plan 15 C: while Settings → Indexing is closed (the default) the file is
 * only "User-agent: * / Disallow: /" (closed()).
 */
class RobotsTxt
{
    /**
     * Paths every crawler is kept out of.
     *
     * @var list<string>
     */
    public const DISALLOW = [
        '/admin',
        '/livewire',
        '/*/search',
        // Partner redirects ("Try" buttons, /{locale}/go/{slug}).
        '/*/go/',
        // Customers' quote links (plan 06).
        '/q/',
        // The cart (plan 11a, /{locale}/cart); "$" keeps /catalog/cartridges etc. crawlable.
        '/*/cart$',
        // Checkout (plan 11b) and customers' order links.
        '/*/checkout$',
        '/o/',
        '/*?*sort=',
        '/*?*f[',
        '/*?*f%5B',
    ];

    /**
     * Livewire's script lives under /livewire-{hash}/: crawlers may fetch it
     * to render pages, while the update endpoints stay disallowed.
     *
     * @var list<string>
     */
    public const ALLOW = [
        '/livewire-*/livewire.js',
        '/livewire-*/js/',
    ];

    /**
     * @var list<RobotsGroup>
     */
    private array $extraGroups = [];

    public function __construct(private ?CompanySettings $settings = null) {}

    /**
     * Add a group after the base "*" group (e.g. rules for one AI crawler).
     */
    public function withGroup(RobotsGroup $group): static
    {
        $this->extraGroups[] = $group;

        return $this;
    }

    /**
     * @return list<RobotsGroup>
     */
    public function groups(): array
    {
        $settings = $this->settings ?? app(CompanySettings::class);

        return [
            new RobotsGroup(['*'], self::DISALLOW, self::ALLOW),
            ...AiBotRules::deniedGroups($settings->aiBots()),
            ...$this->extraGroups,
        ];
    }

    /**
     * @return list<string>
     */
    public function sitemaps(): array
    {
        return [CanonicalHost::using(fn (): string => route('sitemap'))];
    }

    /**
     * While the site is closed for indexing (plan 15 C, the default) every
     * crawler is kept out of everything: no other groups, no Sitemap line.
     */
    public function closed(): string
    {
        return (new RobotsGroup(['*'], ['/']))->render()."\n";
    }

    public function render(): string
    {
        if (! ($this->settings ?? app(CompanySettings::class))->isIndexingOpen()) {
            return $this->closed();
        }

        $blocks = array_map(fn (RobotsGroup $group): string => $group->render(), $this->groups());
        $sitemaps = array_map(fn (string $url): string => "Sitemap: {$url}", $this->sitemaps());

        return implode("\n\n", [...$blocks, implode("\n", $sitemaps)])."\n";
    }
}
