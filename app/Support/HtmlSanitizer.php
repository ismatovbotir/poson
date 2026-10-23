<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonyHtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Cleans rich text from the admin editor before it is saved (plan 03 D12).
 *
 * Allow-list: p, h2, h3, ul, ol, li, strong, em, br, a (https, mailto and
 * site-relative "/..." links) and basic table tags. rel="nofollow" is added
 * to external links only (plan 07 K6): links to the site itself (a "/path"
 * or https on the APP_URL host or its www twin) and mailto stay followed.
 * Protocol-relative "//host" links lose their href (they would leave the
 * site on whatever scheme the page uses). Everything else is dropped,
 * including script, style, event handler attributes and javascript: URLs
 * (formatting tags such as <b> or <span> are unwrapped: their text stays).
 */
class HtmlSanitizer
{
    /**
     * Allowed element → allowed attributes.
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED = [
        'p' => [],
        'h2' => [],
        'h3' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'strong' => [],
        'em' => [],
        'br' => [],
        'a' => ['href'],
        'table' => [],
        'thead' => [],
        'tbody' => [],
        'tr' => [],
        'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
    ];

    /**
     * Not allowed, but their text is kept (the tag itself is removed), so
     * pasted <b>, <span> or <h1> content isn't lost. script, style and other
     * unknown elements are dropped together with their content.
     *
     * @var list<string>
     */
    public const UNWRAPPED = [
        'b', 'i', 'u', 's', 'span', 'div', 'font', 'small', 'sub', 'sup', 'mark', 'code', 'pre',
        'h1', 'h4', 'h5', 'h6', 'blockquote', 'section', 'article', 'header', 'footer', 'main',
        'figure', 'figcaption', 'caption', 'label', 'center',
    ];

    private static ?SymfonyHtmlSanitizer $sanitizer = null;

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return trim(static::filterLinks(static::sanitizer()->sanitize($html)));
    }

    /**
     * Whether an href is protocol-relative ("//host", also "\\host" or
     * "/\\host" and with tabs or line breaks in between, which browsers
     * read the same way).
     */
    public static function isProtocolRelative(string $href): bool
    {
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);
        $href = ltrim(str_replace(["\t", "\n", "\r"], '', $href), "\x00..\x20");

        return (bool) preg_match('#^[/\\\\]{2}#', $href);
    }

    /**
     * Whether a (sanitized) href leaves the site: anything but "/path",
     * mailto: and https links on the APP_URL host or its www twin.
     */
    public static function isExternal(string $href): bool
    {
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);

        if (static::isProtocolRelative($href)) {
            return true;
        }

        if (str_starts_with($href, '/')) {
            return false;
        }

        if (str_starts_with(strtolower($href), 'mailto:')) {
            return false;
        }

        $host = strtolower((string) parse_url($href, PHP_URL_HOST));

        return $host === '' || ! in_array($host, static::siteHosts(), true);
    }

    /**
     * The APP_URL host and its "www." twin.
     *
     * @return list<string>
     */
    public static function siteHosts(): array
    {
        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($host === '') {
            return [];
        }

        return [$host, str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host];
    }

    /**
     * Every link of the sanitizer output (it only ever has <a> or
     * <a href="...">, href being the single allowed attribute): drop a
     * protocol-relative href, add rel="nofollow" to external links.
     */
    private static function filterLinks(string $html): string
    {
        return (string) preg_replace_callback(
            '/<a href="([^"]*)">/',
            fn (array $match): string => match (true) {
                static::isProtocolRelative($match[1]) => '<a>',
                static::isExternal($match[1]) => '<a href="'.$match[1].'" rel="nofollow">',
                default => $match[0],
            },
            $html,
        );
    }

    /**
     * Clean HTML, or null when nothing visible is left (e.g. "<p></p>").
     */
    public static function cleanOrNull(?string $html): ?string
    {
        $clean = static::clean($html);

        return trim(strip_tags($clean)) === '' ? null : $clean;
    }

    private static function sanitizer(): SymfonyHtmlSanitizer
    {
        if (static::$sanitizer !== null) {
            return static::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['https', 'mailto'])
            ->allowRelativeLinks(true)
            ->withMaxInputLength(100_000);

        foreach (self::ALLOWED as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }

        foreach (self::UNWRAPPED as $element) {
            $config = $config->blockElement($element);
        }

        return static::$sanitizer = new SymfonyHtmlSanitizer($config);
    }
}
