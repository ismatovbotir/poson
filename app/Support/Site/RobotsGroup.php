<?php

namespace App\Support\Site;

/**
 * One group of robots.txt rules: the user agents it applies to, then its
 * Allow and Disallow paths.
 */
final readonly class RobotsGroup
{
    /**
     * @param  list<string>  $userAgents
     * @param  list<string>  $disallow
     * @param  list<string>  $allow
     */
    public function __construct(
        public array $userAgents,
        public array $disallow = [],
        public array $allow = [],
    ) {}

    public function render(): string
    {
        $lines = array_map(fn (string $agent): string => "User-agent: {$agent}", $this->userAgents);

        foreach ($this->allow as $path) {
            $lines[] = "Allow: {$path}";
        }

        foreach ($this->disallow as $path) {
            $lines[] = "Disallow: {$path}";
        }

        return implode("\n", $lines);
    }
}
