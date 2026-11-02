<?php

namespace App\Support\Content;

use InvalidArgumentException;

/**
 * Pure YouTube link handling (plan 19, YT-S1). Only the 11-character video id
 * is ever stored or used: every URL is rebuilt from a validated id, a pasted
 * link is never requested or echoed.
 */
final class YoutubeVideo
{
    public const ID_PATTERN = '/\A[A-Za-z0-9_-]{11}\z/';

    public const MAX_INPUT = 500;

    /**
     * Hosts a pasted link may use (lower case, exact match).
     *
     * @var list<string>
     */
    private const HOSTS = [
        'youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com',
        'youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
    ];

    public static function isId(mixed $value): bool
    {
        return is_string($value) && preg_match(self::ID_PATTERN, $value) === 1;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertId(string $id): string
    {
        if (! self::isId($id)) {
            throw new InvalidArgumentException('Invalid YouTube video id.');
        }

        return $id;
    }

    /**
     * The video id of a pasted link or bare id, or null when it is not a single
     * YouTube video (playlists, channels, other hosts, malformed input).
     */
    public static function parse(mixed $input): ?string
    {
        if (! is_string($input)) {
            return null;
        }

        $input = trim($input);

        if ($input === '' || strlen($input) > self::MAX_INPUT) {
            return null;
        }

        if (self::isId($input)) {
            return $input;
        }

        // No whitespace, control characters, backslashes or non-ASCII anywhere.
        if (preg_match('/[\x00-\x20\x7f\\\\]|[^\x00-\x7f]/', $input) === 1) {
            return null;
        }

        if (str_starts_with($input, '//')) {
            return null;
        }

        if (preg_match('#\A[A-Za-z][A-Za-z0-9+.-]*:#', $input) === 1) {
            if (preg_match('#\Ahttps?://#i', $input) !== 1) {
                return null;
            }
        } else {
            $input = 'https://'.$input;
        }

        $parts = parse_url($input);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $host = $parts['host'];

        if (str_contains($host, '%') || str_ends_with($host, '.') || preg_match('/\A[A-Za-z0-9.-]+\z/', $host) !== 1) {
            return null;
        }

        $host = strtolower($host);

        if (! in_array($host, self::HOSTS, true)) {
            return null;
        }

        $path = $parts['path'] ?? '';
        $segments = array_values(array_filter(explode('/', $path), fn (string $s): bool => $s !== ''));

        $candidate = null;

        if ($host === 'youtu.be') {
            if (count($segments) !== 1) {
                return null;
            }

            $candidate = $segments[0];
        } elseif ($segments === ['watch']) {
            parse_str($parts['query'] ?? '', $query);
            $candidate = $query['v'] ?? null;
        } elseif (count($segments) === 2 && in_array($segments[0], ['shorts', 'embed', 'live', 'v'], true)) {
            $candidate = $segments[1];
        }

        return self::isId($candidate) ? $candidate : null;
    }

    public static function watchUrl(string $id): string
    {
        return 'https://www.youtube.com/watch?v='.self::assertId($id);
    }

    public static function embedUrl(string $id): string
    {
        return 'https://www.youtube-nocookie.com/embed/'.self::assertId($id).'?autoplay=1&rel=0';
    }

    public static function seoEmbedUrl(string $id): string
    {
        return 'https://www.youtube.com/embed/'.self::assertId($id);
    }
}
