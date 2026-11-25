<?php

namespace App\Support\Telegram\MiniApp;

use App\Support\Site\CanonicalHost;
use DOMDocument;
use DOMElement;

/**
 * Links that leave the Mini App (plan 20 §2 A5, MA-22). Telegram's openLink()
 * opens them in the customer's own browser; tma.js only reads the
 * data-open-link attribute, every target is built and checked here:
 *
 *  - own(): a route on the canonical https APP_URL (documents, the partner
 *    "Try" redirect, the invoice PDF); null while APP_URL is not https;
 *  - video(): an https watch URL on an allowed video host
 *    (telegram.mini_app.video_hosts);
 *  - external(): any https URL without credentials (links inside a product
 *    description);
 *  - description(): every <a> of sanitized product HTML becomes an openLink
 *    (site-relative links resolved on APP_URL); other schemes lose the href.
 */
final class TmaLinks
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function own(string $route, array $parameters = []): ?string
    {
        $url = CanonicalHost::using(fn (): string => route($route, $parameters));

        return self::isOwn($url) ? $url : null;
    }

    public static function isOwn(string $url): bool
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return self::external($url) !== null && is_string($host) && strcasecmp((string) parse_url($url, PHP_URL_HOST), $host) === 0;
    }

    public static function video(string $url): ?string
    {
        $url = self::external($url);
        $hosts = array_map('strtolower', (array) config('telegram.mini_app.video_hosts', []));

        return $url !== null && in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), $hosts, true) ? $url : null;
    }

    public static function external(string $url): ?string
    {
        if (strlen($url) > 2000 || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }

    /**
     * Sanitized description HTML with every link turned into an openLink target.
     */
    public static function description(string $html): string
    {
        if (trim($html) === '' || ! str_contains($html, '<a')) {
            return $html;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (iterator_to_array($document->getElementsByTagName('a')) as $link) {
            /** @var DOMElement $link */
            $target = self::resolve(trim($link->getAttribute('href')));

            foreach (['href', 'target', 'rel', 'data-open-link'] as $attribute) {
                $link->removeAttribute($attribute);
            }

            if ($target !== null) {
                $link->setAttribute('href', $target);
                $link->setAttribute('data-open-link', $target);
                $link->setAttribute('target', '_blank');
                $link->setAttribute('rel', 'noopener noreferrer nofollow');
            }
        }

        $root = $document->documentElement;
        $out = '';

        foreach ($root?->childNodes ?? [] as $child) {
            $out .= $document->saveHTML($child);
        }

        return $out;
    }

    private static function resolve(string $href): ?string
    {
        if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
            return self::external(rtrim((string) config('app.url'), '/').$href);
        }

        return self::external($href);
    }
}
