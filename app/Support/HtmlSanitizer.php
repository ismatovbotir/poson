<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonyHtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Cleans rich text from the admin editor before it is saved (plan 03 D12).
 *
 * Allow-list: p, h2, h3, ul, ol, li, strong, em, br, a (https/mailto only,
 * rel="nofollow" forced) and basic table tags. Everything else is dropped,
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

        return trim(static::sanitizer()->sanitize($html));
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
            ->allowRelativeLinks(false)
            ->forceAttribute('a', 'rel', 'nofollow')
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
