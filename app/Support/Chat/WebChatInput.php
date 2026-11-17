<?php

namespace App\Support\Chat;

/**
 * Visitor text of the website chat as it reaches the engine (CB-1, CB-19):
 * strings only (anything else is refused), invalid UTF-8 scrubbed, control
 * and format characters (RTL overrides, zero-width ...) removed except a
 * line break, runs of blank lines collapsed, cut to chatbot.web.message_max
 * characters. Never stored as is: what is stored is ChatRedactor::clean().
 */
final class WebChatInput
{
    /**
     * The cleaned text, or null for a non-string or a message with nothing left.
     */
    public static function clean(mixed $text): ?string
    {
        if (! is_string($text) || $text === '') {
            return null;
        }

        $max = max(1, (int) config('chatbot.web.message_max', 1000));
        $text = mb_strcut(mb_scrub($text, 'UTF-8'), 0, $max * 4, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[^\P{C}\n]+/u', '', $text);
        $text = (string) preg_replace('/[\p{Zl}\p{Zp}]+/u', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim(mb_substr($text, 0, $max));

        return $text === '' ? null : $text;
    }
}
