<?php

namespace App\Support\Telegram\Bot;

use App\Support\Chat\Adapters\TelegramAdapter;
use App\Support\Chat\ChatbotSwitch;

/**
 * Keyboards of the customer bot (plan 14 v2 §5, §7) and the callback data
 * allow-list (condition 15). Jobs carry only a keyboard name (one of the
 * constants); markup() renders it in the user's language inside the job.
 *
 * Callback data is accepted only when it matches CALLBACK_PATTERN exactly;
 * anything else is answered (spinner stopped) and otherwise ignored.
 */
final class BotKeyboards
{
    public const LANGUAGE = 'language';

    public const CONTACT = 'contact';

    public const SKIP_COMPANY = 'skip_company';

    public const SKIP_INN = 'skip_inn';

    public const NAME_CONFIRM = 'name_confirm';

    public const MENU = 'menu';

    public const REMOVE = 'remove';

    public const ALL = [self::LANGUAGE, self::CONTACT, self::SKIP_COMPANY, self::SKIP_INN, self::NAME_CONFIRM, self::MENU, self::REMOVE];

    public const CALLBACK_PATTERN = '/^(?:lang:(?:uz|ru|en)|skip:(?:company|inn)|name:(?:yes|change)|'.TelegramAdapter::CALLBACK_BODY.')$/D';

    /**
     * Main menu buttons (reply keyboard) => telegram_bot.php key of the label.
     */
    public const MENU_BUTTONS = [
        'contact_manager' => 'telegram_bot.menu.contact_manager',
        'catalog' => 'telegram_bot.menu.catalog',
        'language' => 'telegram_bot.menu.language',
    ];

    /**
     * Extra main menu buttons shown only while the chat bot is on for
     * Telegram (plan 28): "FAQ" and "Order status". With the bot off they are
     * neither shown nor recognized, so a typed "FAQ" is an ordinary message.
     */
    public const CHAT_MENU_BUTTONS = [
        'faq' => 'telegram_bot.menu.faq',
        'order_status' => 'telegram_bot.menu.order_status',
    ];

    /**
     * Whether callback data is on the allow-list.
     */
    public static function isAllowedCallback(?string $data): bool
    {
        return $data !== null && preg_match(self::CALLBACK_PATTERN, $data) === 1;
    }

    /**
     * Which main menu button a text is ("contact_manager", "catalog", "language"),
     * in any bot language (the keyboard may still show an earlier language); null otherwise.
     */
    public static function menuButton(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        // The base labels first: no settings read for them.
        foreach (self::MENU_BUTTONS as $button => $key) {
            if (self::matchesLabel($text, $key)) {
                return $button;
            }
        }

        // FAQ / Order status count only while the chat bot is on (read only when the text is one of them).
        foreach (self::CHAT_MENU_BUTTONS as $button => $key) {
            if (self::matchesLabel($text, $key) && ChatbotSwitch::isEnabled()) {
                return $button;
            }
        }

        return null;
    }

    private static function matchesLabel(string $text, string $key): bool
    {
        foreach (config('app.supported_locales') as $locale) {
            if ($text === __($key, [], $locale)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Telegram reply_markup for a keyboard name; null for an unknown one.
     *
     * @return array<string, mixed>|null
     */
    public static function markup(?string $keyboard, string $locale): ?array
    {
        return match ($keyboard) {
            self::LANGUAGE => self::inline(array_map(
                fn (string $code): array => [['text' => __("telegram_bot.language.buttons.{$code}", [], $locale), 'callback_data' => "lang:{$code}"]],
                config('app.supported_locales'),
            )),
            self::CONTACT => [
                'keyboard' => [[['text' => __('telegram_bot.consent.share_button', [], $locale), 'request_contact' => true]]],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ],
            self::SKIP_COMPANY => self::inline([[['text' => __('telegram_bot.common.skip', [], $locale), 'callback_data' => 'skip:company']]]),
            self::SKIP_INN => self::inline([[['text' => __('telegram_bot.common.skip', [], $locale), 'callback_data' => 'skip:inn']]]),
            self::NAME_CONFIRM => self::inline([[
                ['text' => __('telegram_bot.name.confirm_yes', [], $locale), 'callback_data' => 'name:yes'],
                ['text' => __('telegram_bot.name.change', [], $locale), 'callback_data' => 'name:change'],
            ]]),
            self::MENU => [
                'keyboard' => self::menuRows($locale),
                'resize_keyboard' => true,
                'is_persistent' => true,
            ],
            self::REMOVE => ['remove_keyboard' => true],
            default => null,
        };
    }

    /**
     * Main menu rows; the chat bot adds a "FAQ / Order status" row while it is on.
     *
     * @return list<list<array{text: string}>>
     */
    private static function menuRows(string $locale): array
    {
        $rows = [[['text' => __(self::MENU_BUTTONS['contact_manager'], [], $locale)]]];

        if (ChatbotSwitch::isEnabled()) {
            $rows[] = [['text' => __(self::CHAT_MENU_BUTTONS['faq'], [], $locale)], ['text' => __(self::CHAT_MENU_BUTTONS['order_status'], [], $locale)]];
        }

        $rows[] = [['text' => __(self::MENU_BUTTONS['catalog'], [], $locale)], ['text' => __(self::MENU_BUTTONS['language'], [], $locale)]];

        return $rows;
    }

    /**
     * @param  list<list<array{text: string, callback_data: string}>>  $rows
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private static function inline(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }
}
