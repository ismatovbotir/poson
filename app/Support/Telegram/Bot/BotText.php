<?php

namespace App\Support\Telegram\Bot;

use App\Models\TelegramUser;
use App\Support\Telegram\TelegramLocale;

/**
 * Customer bot copy (lang/{uz,ru,en}/telegram_bot.php) and input cleaning.
 *
 * Prompts are referred to by key (PROMPTS allow-list) so queued jobs carry
 * no text: render() builds the plain text (no parse_mode, condition 15)
 * inside the job from the user's row (locale, name). Bot texts never
 * contain CRM data (condition 6): the only user value is the name the user
 * gave the bot.
 */
final class BotText
{
    /**
     * Prompt keys (telegram_bot.php) the bot may send.
     */
    public const PROMPTS = [
        'language.prompt', 'language.changed',
        'consent.text',
        'contact.not_own', 'contact.invalid', 'contact.use_button',
        'name.confirm', 'name.prompt', 'name.invalid',
        'company.prompt', 'company.invalid',
        'inn.prompt', 'inn.invalid',
        'welcome',
        'menu.main', 'menu.contact_manager_reply', 'menu.catalog_reply',
        'help',
        'registration.continue',
        'rate_limited',
        'inbox.unsupported',
    ];

    /**
     * Copy-safe caption: control characters except line breaks and format
     * characters (bidi overrides, zero-width) removed, at most $limit UTF-16
     * code units (Telegram's caption limit is 1024 of them), trimmed. Line
     * breaks are kept. Invalid UTF-8 gives an empty string (B4-S4).
     */
    public static function caption(?string $value, int $limit = 1024): string
    {
        if ($value === null || ! mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[^\P{Cc}\n]/u', '', $value);
        $value = (string) preg_replace('/[\p{Cf}\p{Zl}\p{Zp}]/u', '', $value);
        $value = trim($value);

        $units = 0;
        $kept = '';

        foreach (mb_str_split($value) as $char) {
            $units += mb_ord($char) > 0xFFFF ? 2 : 1;

            if ($units > $limit) {
                break;
            }

            $kept .= $char;
        }

        return trim($kept);
    }

    public static function isPrompt(string $key): bool
    {
        return in_array($key, self::PROMPTS, true);
    }

    /**
     * The language the bot talks to this user in: the chosen one, else Uzbek.
     */
    public static function locale(TelegramUser $user): string
    {
        return in_array($user->locale, config('app.supported_locales'), true) ? $user->locale : TelegramLocale::DEFAULT;
    }

    /**
     * Plain text of several prompts, separated by a blank line (one message).
     *
     * @param  list<string>  $prompts
     */
    public static function render(TelegramUser $user, array $prompts): string
    {
        $locale = self::locale($user);
        $parts = [];

        foreach ($prompts as $prompt) {
            if (self::isPrompt($prompt)) {
                $parts[] = __('telegram_bot.'.$prompt, self::params($prompt, $user, $locale), $locale);
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Free text typed into the bot, made safe to store and show: control
     * characters and line breaks become spaces, format characters (bidi
     * overrides, zero-width) are removed, whitespace collapsed, trimmed.
     * Invalid UTF-8 gives an empty string.
     */
    public static function clean(?string $value): string
    {
        if ($value === null || ! mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        $value = (string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]/u', ' ', $value);
        $value = (string) preg_replace('/\p{Cf}/u', '', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @return array<string, string>
     */
    private static function params(string $prompt, TelegramUser $user, string $locale): array
    {
        return match ($prompt) {
            'name.confirm', 'welcome' => ['name' => (string) $user->name],
            'consent.text' => ['privacy_url' => route('site.page', ['locale' => $locale, 'page' => BotChecklist::PRIVACY_SLUG])],
            'menu.catalog_reply' => ['url' => route('site.catalog.index', ['locale' => $locale])],
            default => [],
        };
    }
}
