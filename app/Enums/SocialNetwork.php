<?php

namespace App\Enums;

/**
 * Social networks the company can link to. A link must be https and point
 * to one of the network's own hosts (no look-alike or redirect domains).
 */
enum SocialNetwork: string
{
    case Telegram = 'telegram';
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case Youtube = 'youtube';
    case Linkedin = 'linkedin';
    case Tiktok = 'tiktok';
    case Whatsapp = 'whatsapp';

    /**
     * Allowed characters in the URL path.
     */
    public const PATH_PATTERN = '#^/[A-Za-z0-9._~\-/+@%]*$#';

    /**
     * Allowed characters in the query string.
     */
    public const QUERY_PATTERN = '#^[A-Za-z0-9._~\-+%=&]*$#';

    public function label(): string
    {
        return __("enums.social_network.{$this->value}");
    }

    /**
     * Allowed hosts (lowercase, exact match).
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        return match ($this) {
            self::Telegram => ['t.me', 'telegram.me'],
            self::Instagram => ['instagram.com', 'www.instagram.com'],
            self::Facebook => ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.com'],
            self::Youtube => ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'],
            self::Linkedin => ['linkedin.com', 'www.linkedin.com'],
            self::Tiktok => ['tiktok.com', 'www.tiktok.com'],
            self::Whatsapp => ['wa.me'],
        };
    }

    /**
     * Example for the admin placeholder.
     */
    public function example(): string
    {
        return 'https://'.$this->hosts()[0].'/poson';
    }

    /**
     * Whether the URL is https on one of this network's hosts, without
     * credentials, a custom port or fragment, and with a plain path.
     */
    public function allows(mixed $url): bool
    {
        if (! is_string($url) || strlen($url) > 255 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            return false;
        }

        $path = $parts['path'] ?? '';

        // Plain path characters only; a query (share links such as ?igsh=…) may carry key=value pairs.
        if (trim($path, '/') === '' || preg_match(self::PATH_PATTERN, $path) !== 1) {
            return false;
        }

        if (isset($parts['query']) && preg_match(self::QUERY_PATTERN, $parts['query']) !== 1) {
            return false;
        }

        return in_array(strtolower($parts['host'] ?? ''), $this->hosts(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
