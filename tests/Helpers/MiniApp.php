<?php

use App\Enums\SettingsArea;
use App\Models\Page;
use App\Models\Setting;
use App\Models\TelegramUser;
use App\Support\Telegram\Bot\BotSwitch;
use App\Support\Telegram\MiniApp\BotUsername;
use App\Support\Telegram\MiniApp\InitData;
use App\Support\Telegram\MiniApp\MiniAppSwitch;
use App\Support\Telegram\MiniApp\TmaSession;
use Illuminate\Testing\TestResponse;

/*
| Telegram Mini App test helpers (plan 20). The fixed vector below was signed
| with TG_BOT_TOKEN by an independent implementation of Telegram's algorithm
| (Python hmac), so the PHP verifier is checked against it, not against itself.
*/

if (! function_exists('tmaVector')) {
    define('TMA_VECTOR_AUTH_DATE', 1764000000);

    define('TMA_VECTOR_HASH', 'd2c3abd5a96db8ddc638765733087e2db2ad01280e5a98c12b199cd003a30f67');

    define('TMA_VECTOR', 'query_id=AAHdF6IQAAAAAN0XohDhrOrc&user=%7B%22id%22%3A5550001%2C%22first_name%22%3A%22Ali%22%2C%22last_name%22%3A%22Valiyev%22%2C%22username%22%3A%22ali_v%22%2C%22language_code%22%3A%22ru%22%2C%22allows_write_to_pm%22%3Atrue%2C%22photo_url%22%3A%22https%3A%2F%2Ft.me%2Fi%2Fuserpic%2F320%2Fabc.svg%22%7D&auth_date=1764000000&signature=6fbdaPwn4ZVWsMbgsXXyU1lDdcNWNQ8dqKexrCKk1O5d0QwJk3Tjc8fe3bJ9fUnu0Cdx5aEkFN0jECMBfDcRBQ&start_param=p42&hash=d2c3abd5a96db8ddc638765733087e2db2ad01280e5a98c12b199cd003a30f67');

    /**
     * The fixed real-format vector (Telegram id 5550001, start_param p42).
     */
    function tmaVector(): string
    {
        return TMA_VECTOR;
    }

    /**
     * A freshly signed initData for a Telegram id (auth_date now unless given).
     *
     * @param  array<string, string>  $extra
     */
    function tmaInitData(int $telegramId, array $extra = [], ?int $authDate = null, string $token = TG_BOT_TOKEN): string
    {
        $fields = array_merge([
            'query_id' => 'AAHdF6IQAAAAAN0XohDhrOrc',
            'user' => json_encode(['id' => $telegramId, 'first_name' => 'Ali', 'language_code' => 'uz']),
            'auth_date' => (string) ($authDate ?? now()->getTimestamp()),
            'signature' => 'c2lnbmF0dXJlLWZvci10ZXN0cw',
        ], $extra);
        $fields['hash'] = InitData::sign($fields, $token);

        return implode('&', array_map(fn (string $key, string $value): string => $key.'='.rawurlencode($value), array_keys($fields), $fields));
    }

    /**
     * Bot configured, the bot switch and the Mini App switch on.
     */
    function tmaEnabled(bool $miniApp = true, bool $bot = true): void
    {
        telegramBotConfigured();
        $group = SettingsArea::TelegramBot->group();
        Setting::query()->updateOrCreate(['key' => BotSwitch::KEY], ['group' => $group, 'value' => $bot]);
        Setting::query()->updateOrCreate(['key' => MiniAppSwitch::KEY], ['group' => $group, 'value' => $miniApp]);
    }

    function tmaUser(array $attributes = []): TelegramUser
    {
        return TelegramUser::factory()->registered()->create($attributes);
    }

    /**
     * A signed-in Mini App session for the user (as AuthenticateMiniAppUser leaves it).
     */
    function tmaSignIn(TelegramUser $user, ?int $authAt = null, ?int $seenAt = null): void
    {
        test()->withSession([
            TmaSession::USER => $user->id,
            TmaSession::TELEGRAM_ID => $user->telegram_id,
            TmaSession::AUTH_AT => $authAt ?? now()->getTimestamp(),
            TmaSession::SEEN_AT => $seenAt ?? now()->getTimestamp(),
        ]);
    }

    function tmaAuth(string $initData, ?string $next = null): TestResponse
    {
        return test()->postJson(route('tma.auth'), array_filter(['init_data' => $initData, 'next' => $next]));
    }

    /**
     * Plan 20 M6: every MiniAppChecklist item passes while the Mini App row is still off
     * (bot on, token, https APP_URL, privacy page, bot username; on_receipt is configured).
     */
    function miniAppReady(): void
    {
        tmaEnabled(miniApp: false);
        Page::factory()->create(['slug' => 'privacy', 'is_active' => true, 'needs_review' => false]);
        BotUsername::remember('poson_test_bot');
    }
}
