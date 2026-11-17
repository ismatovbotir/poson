<?php

namespace App\Support\Chat;

/**
 * The page a website chat started on (web_chats.first_page, WC-10). The
 * caller passes the page path from Livewire::originalPath() (checksummed by
 * Livewire) or the widget's #[Locked] page prop rendered by the launcher;
 * never Referer, request()->path() (the Livewire update route) or a method
 * argument. Kept: the path only (no scheme, host, query or fragment), with
 * control and format characters removed, at most 255 characters; a path with a "." or ".." segment becomes "/". Order
 * pages (/o/...) and quote links (/q/...), with or without the locale
 * prefix, are never stored. Shown to staff as plain text only.
 */
final class WebChatPage
{
    public const MAX = 255;

    public static function clean(?string $page): ?string
    {
        if ($page === null || $page === '') {
            return null;
        }

        $page = (string) preg_replace('/[\p{C}\s]+/u', '', mb_scrub($page, 'UTF-8'));
        $path = parse_url($page, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }
        $path = '/'.ltrim($path, '/');

        if (in_array('.', explode('/', $path), true) || in_array('..', explode('/', $path), true)) {
            return '/';
        }

        if (preg_match('~^/(?:[a-z]{2}/)?(?:o|q)(?:/|$)~i', $path) === 1 || str_starts_with(strtolower($path), '/livewire')) {
            return null;
        }

        return mb_substr($path, 0, self::MAX);
    }
}
