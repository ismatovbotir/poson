<?php

namespace App\Actions\Telegram;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Secrets;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * Registers / removes the Telegram webhook (plan 14 v2 D2, H1 and
 * condition 1).
 *
 * The URL is ALWAYS config('app.url') + the fixed PATH, https only, never
 * built from the current request (H1: a spoofed Host header must not move
 * the webhook). Refuses without a bot token or webhook secret. setWebhook
 * sends the secret_token, allowed_updates (message, callback_query,
 * my_chat_member), max_connections 10 and drop_pending_updates.
 *
 * set() / delete() check telegram.bot themselves for a user (null = the
 * console as system actor). The explicit set() drops pending updates by
 * default (a first registration must not replay an old backlog); secret
 * rotation re-registers WITHOUT dropping them (L3).
 *
 * Every successful set / delete is audited (area telegram_bot, key
 * telegram.webhook, actor + IP, never a value). Telegram failures become a
 * TelegramBotException with the HTTP code only (condition 18).
 */
class ConfigureTelegramWebhook
{
    /**
     * Path of the api.telegram.webhook route (guarded by a test).
     */
    public const PATH = '/api/telegram/webhook';

    public const AUDIT_KEY = 'telegram.webhook';

    public const ALLOWED_UPDATES = ['message', 'callback_query', 'my_chat_member'];

    public const MAX_CONNECTIONS = 10;

    /**
     * After a new secret: refused because something is missing (token, https).
     */
    public const SKIPPED = 'skipped';

    public const SET = 'set';

    public const FAILED = 'failed';

    public function __construct(protected TelegramClient $client) {}

    /**
     * The webhook URL Telegram must call, or null when APP_URL is not https.
     */
    public static function expectedUrl(): ?string
    {
        $base = rtrim((string) config('app.url'), '/');
        $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
        $host = (string) parse_url($base, PHP_URL_HOST);

        return $scheme === 'https' && $host !== '' ? $base.self::PATH : null;
    }

    /**
     * @throws AuthorizationException
     * @throws TelegramBotException not_https | no_token | no_secret | telegram_error
     */
    public function set(?User $user, ?string $ip, bool $dropPendingUpdates = true): void
    {
        $this->authorize($user);
        $this->register($user, $ip, $dropPendingUpdates);
    }

    /**
     * @throws TelegramBotException not_https | no_token | no_secret | telegram_error
     */
    protected function register(?User $user, ?string $ip, bool $dropPendingUpdates): void
    {
        $url = self::expectedUrl();

        if ($url === null) {
            throw new TelegramBotException('not_https');
        }

        if (! $this->client->isConfigured()) {
            throw new TelegramBotException('no_token');
        }

        $secret = Secrets::get('telegram.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new TelegramBotException('no_secret');
        }

        try {
            $this->client->setWebhook($url, $secret, self::ALLOWED_UPDATES, self::MAX_CONNECTIONS, $dropPendingUpdates);
        } catch (TelegramException $exception) {
            throw new TelegramBotException('telegram_error', apiCode: $exception->code());
        }

        SettingsAudit::record(SettingsArea::TelegramBot, self::AUDIT_KEY, AuditAction::WebhookSet, $user, $ip);
    }

    /**
     * A user needs telegram.bot; null is the console (system actor).
     *
     * @throws AuthorizationException
     */
    protected function authorize(?User $user): void
    {
        if ($user !== null && ! $user->can('telegram.bot')) {
            throw new AuthorizationException;
        }
    }

    /**
     * @throws AuthorizationException
     * @throws TelegramBotException no_token | telegram_error
     */
    public function delete(?User $user, ?string $ip): void
    {
        $this->authorize($user);

        if (! $this->client->isConfigured()) {
            throw new TelegramBotException('no_token');
        }

        try {
            $this->client->deleteWebhook();
        } catch (TelegramException $exception) {
            throw new TelegramBotException('telegram_error', apiCode: $exception->code());
        }

        SettingsAudit::record(SettingsArea::TelegramBot, self::AUDIT_KEY, AuditAction::WebhookDeleted, $user, $ip);
    }

    /**
     * Called after GenerateWebhookSecret has committed (condition 1; the
     * caller is authorized by settings.integrations). The old webhook stops
     * validating, so it is registered again with the new secret, but ONLY
     * when Telegram's current URL is exactly our expected URL (M1: never
     * take over a webhook that points elsewhere) and without dropping
     * pending updates (L3). Never throws: SKIPPED when the token, an https
     * APP_URL or a matching webhook is missing, FAILED when Telegram could
     * not be asked or refused (the screen shows a banner).
     *
     * @return self::SKIPPED|self::SET|self::FAILED
     */
    public function afterSecretGenerated(?User $user, ?string $ip): string
    {
        $expected = self::expectedUrl();

        if ($expected === null || ! $this->client->isConfigured()) {
            return self::SKIPPED;
        }

        try {
            $current = $this->client->getWebhookInfo()['url'] ?? null;

            if (! is_string($current) || ! hash_equals($expected, $current)) {
                return self::SKIPPED;
            }

            $this->register($user, $ip, dropPendingUpdates: false);

            return self::SET;
        } catch (TelegramException|TelegramBotException) {
            return self::FAILED;
        } catch (Throwable $exception) {
            report($exception);

            return self::FAILED;
        }
    }
}
