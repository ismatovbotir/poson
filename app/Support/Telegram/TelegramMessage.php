<?php

namespace App\Support\Telegram;

/**
 * A rendered Telegram alert: HTML for parse_mode=HTML (every value already
 * escaped with e()) and optional URL buttons (https admin links only).
 */
final readonly class TelegramMessage
{
    /**
     * @param  list<array{text: string, url: string}>  $buttons
     */
    public function __construct(
        public string $html,
        public array $buttons = [],
    ) {}

    /**
     * "Open in admin" for a relative admin path, only when APP_URL is https
     * (the button URL is never built from the request host).
     *
     * @return list<array{text: string, url: string}>
     */
    public static function adminButton(string $relativePath, string $text): array
    {
        $url = rtrim((string) config('app.url'), '/').$relativePath;

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return [];
        }

        return [['text' => $text, 'url' => $url]];
    }
}
