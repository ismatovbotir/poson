<?php

namespace App\Support\Site;

/**
 * /robots.txt (plan 03 D16 base rules; plan 04 adds per-bot groups).
 *
 * The "*" group keeps crawlers out of the admin, Livewire endpoints, search
 * results and filtered or sorted listings. Filter URLs carry brackets
 * ("f[code][0]=12"), which some clients percent-encode, so both forms are
 * listed. Plan 04 adds groups with withGroup() (e.g. from a service
 * provider or the settings) without touching the base rules.
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
        return [new RobotsGroup(['*'], self::DISALLOW, self::ALLOW), ...$this->extraGroups];
    }

    /**
     * @return list<string>
     */
    public function sitemaps(): array
    {
        return [CanonicalHost::using(fn (): string => route('sitemap'))];
    }

    public function render(): string
    {
        $blocks = array_map(fn (RobotsGroup $group): string => $group->render(), $this->groups());
        $sitemaps = array_map(fn (string $url): string => "Sitemap: {$url}", $this->sitemaps());

        return implode("\n\n", [...$blocks, implode("\n", $sitemaps)])."\n";
    }
}
