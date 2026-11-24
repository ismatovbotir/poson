<?php

namespace App\Support\Site;

/**
 * One group of robots.txt rules: the user agents it applies to, then its
 * Allow and Disallow paths. Plan 40: a Yandex group may also carry
 * Clean-param (query keys Yandex drops when indexing; Yandex only).
 */
final readonly class RobotsGroup
{
    /**
     * @param  list<string>  $userAgents
     * @param  list<string>  $disallow
     * @param  list<string>  $allow
     * @param  list<string>  $cleanParams
     */
    public function __construct(
        public array $userAgents,
        public array $disallow = [],
        public array $allow = [],
        public array $cleanParams = [],
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

        if ($this->cleanParams !== []) {
            $lines[] = 'Clean-param: '.implode('&', $this->cleanParams);
        }

        return implode("\n", $lines);
    }
}
