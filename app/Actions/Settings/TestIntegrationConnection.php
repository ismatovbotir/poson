<?php

namespace App\Actions\Settings;

use App\Models\User;
use App\Services\Payments\Uzqr\UzqrClient;
use App\Services\Payments\Uzqr\UzqrConfig;
use App\Services\Payments\Uzqr\UzqrException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use App\Support\Integrations\ConnectionTestResult;
use App\Support\Secrets;
use App\Support\Telegram\TelegramLocale;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Throwable;

/**
 * "Test connection" in Settings → Integrations, always with the saved
 * effective value (.env or admin), never with what is typed in the form.
 *
 * Targets: telegram (getMe), telegram_chat (a test message to the sales /
 * service / finance chat in the telegram.locale language), both through
 * TelegramClient. Payme and Click have no test endpoint: the screen shows
 * their webhook URLs instead. uzqr: invoice/create with empty parameters
 * in the active mode (400 = credentials accepted, 401 / 403 = rejected);
 * success clears the UzQR auth-failed flag (condition 53, 54).
 *
 * Rate limited to MAX_ATTEMPTS per minute per user; TIMEOUT seconds per
 * request, no retries. Every failure becomes a safe code: exceptions are
 * never reported or logged as-is, because their messages can contain the
 * request URL (the Telegram token is part of it). Only the target, the
 * code and the exception class are logged.
 */
class TestIntegrationConnection
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public const TIMEOUT = 5;

    public const TARGETS = ['telegram', 'telegram_chat', 'uzqr'];

    public const CHATS = ['sales', 'service', 'finance'];

    /**
     * @throws InvalidArgumentException for an unknown target or chat
     */
    public function handle(User $user, string $target, ?string $chat = null): ConnectionTestResult
    {
        if (! in_array($target, self::TARGETS, true) || ($target === 'telegram_chat' && ! in_array($chat, self::CHATS, true))) {
            throw new InvalidArgumentException("Unknown connection test [{$target}].");
        }

        $limiterKey = static::throttleKey($user);

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_ATTEMPTS)) {
            return ConnectionTestResult::failure('throttled', ['seconds' => RateLimiter::availableIn($limiterKey)]);
        }

        RateLimiter::hit($limiterKey, self::DECAY_SECONDS);

        $result = match ($target) {
            'telegram' => $this->telegram(),
            'telegram_chat' => $this->telegramChat((string) $chat),
            'uzqr' => $this->uzqr(),
        };

        Log::info('Integration connection test', ['target' => $target, 'chat' => $chat, 'code' => $result->code, 'user_id' => $user->id]);

        return $result;
    }

    public static function throttleKey(User $user): string
    {
        return 'integration-test:'.$user->id;
    }

    protected function telegram(): ConnectionTestResult
    {
        $client = $this->telegramClient();

        if (! $client->isConfigured()) {
            return ConnectionTestResult::failure('not_configured');
        }

        try {
            $username = $client->getMe()['username'] ?? null;
        } catch (TelegramException $exception) {
            return $this->telegramFailure($exception);
        }

        return is_string($username)
            ? ConnectionTestResult::success('telegram_ok', ['bot' => '@'.$username])
            : ConnectionTestResult::failure('unexpected_response');
    }

    protected function telegramChat(string $chat): ConnectionTestResult
    {
        $client = $this->telegramClient();
        $chatId = Secrets::get('telegram.chats.'.$chat);

        if (! $client->isConfigured()) {
            return ConnectionTestResult::failure('not_configured');
        }

        if (! is_string($chatId) || $chatId === '') {
            return ConnectionTestResult::failure('chat_not_configured');
        }

        // Same language as the lead alerts (Settings → Integrations → Telegram, default uz).
        $locale = TelegramLocale::current();
        $text = __('admin.settings.integrations.test.telegram_message', ['chat' => __('admin.settings.integrations.fields.telegram_chats_'.$chat, [], $locale)], $locale);

        try {
            $client->sendMessage($chatId, e($text));
        } catch (TelegramException $exception) {
            // Telegram: 400 "chat not found", 403 "bot was kicked / blocked".
            return match ($exception->status) {
                400 => ConnectionTestResult::failure('chat_not_found'),
                403 => ConnectionTestResult::failure('chat_forbidden'),
                default => $this->telegramFailure($exception),
            };
        }

        return ConnectionTestResult::success('telegram_message_sent');
    }

    protected function uzqr(): ConnectionTestResult
    {
        $config = app(UzqrConfig::class);
        $mode = $config->activeMode();

        if (! $config->callable($mode)) {
            return ConnectionTestResult::failure('not_configured');
        }

        try {
            app(UzqrClient::class)->forMode($mode, true)->ping();
        } catch (UzqrException $exception) {
            return ConnectionTestResult::failure(match ($exception->reason) {
                UzqrException::AUTH_FAILED => 'unauthorized',
                UzqrException::BLOCKED => 'forbidden',
                UzqrException::RATE_LIMITED, UzqrException::BUSY => 'rate_limited',
                UzqrException::SERVER_ERROR => 'server_error',
                UzqrException::TIMEOUT => 'timeout',
                UzqrException::CONNECTION_FAILED => 'connection_failed',
                default => 'unexpected_response',
            }, $exception->reason === UzqrException::TIMEOUT ? ['seconds' => (int) config('uzqr.timeouts.interactive', 5)] : []);
        }

        $config->clearAuthFailed($mode);

        return ConnectionTestResult::success('uzqr_ok', ['mode' => $mode->label()]);
    }

    protected function telegramClient(): TelegramClient
    {
        return app(TelegramClient::class)->withTimeout(self::TIMEOUT);
    }

    protected function telegramFailure(TelegramException $exception): ConnectionTestResult
    {
        if ($exception->status === 0) {
            return $this->failed($exception->timedOut ? 'timeout' : 'connection_failed', $exception, $exception->timedOut ? ['seconds' => self::TIMEOUT] : []);
        }

        return ConnectionTestResult::failure(match (true) {
            in_array($exception->status, [401, 404], true) => 'unauthorized',
            $exception->status === 403 => 'forbidden',
            $exception->status === 429 => 'rate_limited',
            $exception->status >= 500 => 'server_error',
            default => 'unexpected_response',
        });
    }

    /**
     * Log the failure without the exception message (it may contain the URL).
     *
     * @param  array<string, string|int>  $details
     */
    protected function failed(string $code, Throwable $e, array $details = []): ConnectionTestResult
    {
        Log::warning('Integration connection test failed', ['code' => $code, 'exception' => $e::class]);

        return ConnectionTestResult::failure($code, $details);
    }
}
