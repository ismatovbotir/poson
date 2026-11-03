<?php

namespace App\Support\Telegram\Bot;

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

    public const CALLBACK_PATTERN = '/^(?:lang:(?:uz|ru|en)|skip:(?:company|inn)|name:(?:yes|change))$/D';

    /**
     * Main menu buttons (reply keyboard) => telegram_bot.php key of the label.
     */
    public const MENU_BUTTONS = [
        'contact_manager' => 'telegram_bot.menu.contact_manager',
        'catalog' => 'telegram_bot.menu.catalog',
        'language' => 'telegram_bot.menu.language',
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

        foreach (self::MENU_BUTTONS as $button => $key) {
            foreach (config('app.supported_locales') as $locale) {
                if ($text === __($key, [], $locale)) {
                    return $button;
                }
            }
        }

        return null;
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
                'keyboard' => [
                    [['text' => __(self::MENU_BUTTONS['contact_manager'], [], $locale)]],
                    [['text' => __(self::MENU_BUTTONS['catalog'], [], $locale)], ['text' => __(self::MENU_BUTTONS['language'], [], $locale)]],
                ],
                'resize_keyboard' => true,
                'is_persistent' => true,
            ],
            self::REMOVE => ['remove_keyboard' => true],
            default => null,
        };
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
