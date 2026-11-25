<?php

namespace App\Services\Telegram;

use App\Support\Site\CanonicalHost;
use InvalidArgumentException;

/**
 * The only way to build a Telegram web_app button (plan 20 M5, MA-20,
 * MA-29): a closed type with a private constructor. Its URL must be the
 * Mini App on the canonical https APP_URL (APP_URL + "/tma", optionally
 * followed by "/…" or "?…"), otherwise make() throws; there is no request
 * host fallback. TelegramClient refuses web_app markup from anywhere else.
 */
final class WebAppButton
{
    private const MAX_TEXT = 64;

    private function __construct(
        public readonly string $text,
        public readonly string $url,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function make(string $text, string $url): self
    {
        $text = trim($text);

        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            throw new InvalidArgumentException('Invalid web_app button text.');
        }

        if (! self::isAllowedUrl($url)) {
            throw new InvalidArgumentException('A web_app button must open the Mini App on the canonical https APP_URL.');
        }

        return new self($text, $url);
    }

    /**
     * The Mini App entry (route tma.bootstrap on APP_URL, never the request
     * host); null while APP_URL is not https or the URL is otherwise refused.
     */
    public static function miniApp(string $text): ?self
    {
        try {
            return self::make($text, CanonicalHost::using(fn (): string => route('tma.bootstrap')));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Whether a URL is the Mini App on the canonical https APP_URL.
     */
    public static function isAllowedUrl(string $url): bool
    {
        $root = rtrim((string) config('app.url'), '/');
        $rootParts = parse_url($root);

        if (! is_array($rootParts) || strtolower($rootParts['scheme'] ?? '') !== 'https' || ($rootParts['host'] ?? '') === '') {
            return false;
        }

        if (strlen($url) > 512 || preg_match('/[\s\x00-\x1F\x7F\\\\]/', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || strcasecmp($parts['host'] ?? '', $rootParts['host']) !== 0
            || ($parts['port'] ?? null) !== ($rootParts['port'] ?? null)) {
            return false;
        }

        $base = $root.'/tma';

        if ($url !== $base && ! str_starts_with($url, $base.'/') && ! str_starts_with($url, $base.'?')) {
            return false;
        }

        return ! str_contains((string) ($parts['path'] ?? ''), '..');
    }

    /**
     * An inline keyboard button.
     *
     * @return array{text: string, web_app: array{url: string}}
     */
    public function inline(): array
    {
        return ['text' => $this->text, 'web_app' => ['url' => $this->url]];
    }

    /**
     * A chat menu button (setChatMenuButton).
     *
     * @return array{type: string, text: string, web_app: array{url: string}}
     */
    public function menuButton(): array
    {
        return ['type' => 'web_app', 'text' => $this->text, 'web_app' => ['url' => $this->url]];
    }
}
