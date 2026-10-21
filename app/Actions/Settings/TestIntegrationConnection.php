<?php

namespace App\Actions\Settings;

use App\Models\User;
use App\Support\Integrations\ConnectionTestResult;
use App\Support\Secrets;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Throwable;

/**
 * "Test connection" in Settings → Integrations, always with the saved
 * effective value (.env or admin), never with what is typed in the form.
 *
 * Targets: telegram (getMe), telegram_chat (a test message to the sales /
 * service / finance chat), anthropic and openai (GET /v1/models), removebg
 * (GET /v1.0/account, shows the credits). Payme and Click have no test
 * endpoint: the screen shows their webhook URLs instead.
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

    public const TARGETS = ['telegram', 'telegram_chat', 'anthropic', 'openai', 'removebg'];

    public const CHATS = ['sales', 'service', 'finance'];

    /**
     * Anthropic API version header for GET /v1/models.
     */
    public const ANTHROPIC_VERSION = '2023-06-01';

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
            'anthropic' => $this->anthropic(),
            'openai' => $this->openai(),
            'removebg' => $this->removebg(),
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
        $token = Secrets::get('telegram.bot_token');

        if (! is_string($token) || $token === '') {
            return ConnectionTestResult::failure('not_configured');
        }

        return $this->call(fn (): Response => $this->http()->get($this->telegramUrl($token, 'getMe')), function (Response $response): ConnectionTestResult {
            $username = $response->json('result.username');

            return $response->json('ok') === true && is_string($username)
                ? ConnectionTestResult::success('telegram_ok', ['bot' => '@'.$username])
                : ConnectionTestResult::failure('unexpected_response');
        });
    }

    protected function telegramChat(string $chat): ConnectionTestResult
    {
        $token = Secrets::get('telegram.bot_token');
        $chatId = Secrets::get('telegram.chats.'.$chat);

        if (! is_string($token) || $token === '') {
            return ConnectionTestResult::failure('not_configured');
        }

        if (! is_string($chatId) || $chatId === '') {
            return ConnectionTestResult::failure('chat_not_configured');
        }

        // Team language for Telegram messages is Russian (telegram-notifications skill).
        $text = __('admin.settings.integrations.test.telegram_message', ['chat' => __('admin.settings.integrations.fields.telegram_chats_'.$chat, [], 'ru')], 'ru');

        return $this->call(
            fn (): Response => $this->http()->asJson()->post($this->telegramUrl($token, 'sendMessage'), ['chat_id' => $chatId, 'text' => $text]),
            fn (Response $response): ConnectionTestResult => $response->json('ok') === true
                ? ConnectionTestResult::success('telegram_message_sent')
                : ConnectionTestResult::failure('unexpected_response'),
            function (Response $response): ?ConnectionTestResult {
                // Telegram: 400 "chat not found", 403 "bot was kicked / blocked".
                return match ($response->status()) {
                    400 => ConnectionTestResult::failure('chat_not_found'),
                    403 => ConnectionTestResult::failure('chat_forbidden'),
                    default => null,
                };
            },
        );
    }

    protected function anthropic(): ConnectionTestResult
    {
        $key = Secrets::get('anthropic.api_key');

        if (! is_string($key) || $key === '') {
            return ConnectionTestResult::failure('not_configured');
        }

        return $this->call(
            fn (): Response => $this->http()->withHeaders(['x-api-key' => $key, 'anthropic-version' => self::ANTHROPIC_VERSION])->get('https://api.anthropic.com/v1/models'),
            fn (Response $response): ConnectionTestResult => $this->modelsResult($response),
        );
    }

    protected function openai(): ConnectionTestResult
    {
        $key = Secrets::get('openai.api_key');

        if (! is_string($key) || $key === '') {
            return ConnectionTestResult::failure('not_configured');
        }

        return $this->call(
            fn (): Response => $this->http()->withToken($key)->get('https://api.openai.com/v1/models'),
            fn (Response $response): ConnectionTestResult => $this->modelsResult($response),
        );
    }

    protected function removebg(): ConnectionTestResult
    {
        $key = Secrets::get('removebg.api_key');

        if (! is_string($key) || $key === '') {
            return ConnectionTestResult::failure('not_configured');
        }

        return $this->call(
            fn (): Response => $this->http()->withHeaders(['X-Api-Key' => $key])->get('https://api.remove.bg/v1.0/account'),
            function (Response $response): ConnectionTestResult {
                $credits = $response->json('data.attributes.credits.total');
                $freeCalls = $response->json('data.attributes.api.free_calls');

                return is_numeric($credits)
                    ? ConnectionTestResult::success('removebg_ok', ['credits' => (string) $credits, 'free_calls' => is_numeric($freeCalls) ? (int) $freeCalls : 0])
                    : ConnectionTestResult::failure('unexpected_response');
            },
        );
    }

    protected function modelsResult(Response $response): ConnectionTestResult
    {
        $models = $response->json('data');

        return is_array($models)
            ? ConnectionTestResult::success('models_ok', ['count' => count($models)])
            : ConnectionTestResult::failure('unexpected_response');
    }

    protected function http(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT)->connectTimeout(self::TIMEOUT)->acceptJson();
    }

    protected function telegramUrl(#[\SensitiveParameter] string $token, string $method): string
    {
        return 'https://api.telegram.org/bot'.$token.'/'.$method;
    }

    /**
     * Send the request and map the outcome to a safe result.
     *
     * @param  callable(): Response  $send
     * @param  callable(Response): ConnectionTestResult  $onSuccess
     * @param  (callable(Response): ?ConnectionTestResult)|null  $onError  provider-specific error codes
     */
    protected function call(callable $send, callable $onSuccess, ?callable $onError = null): ConnectionTestResult
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            $timedOut = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');

            return $this->failed($timedOut ? 'timeout' : 'connection_failed', $e, $timedOut ? ['seconds' => self::TIMEOUT] : []);
        } catch (Throwable $e) {
            return $this->failed('connection_failed', $e);
        }

        if ($response->successful()) {
            return $onSuccess($response);
        }

        if ($onError !== null && ($result = $onError($response)) !== null) {
            return $result;
        }

        return ConnectionTestResult::failure(match (true) {
            $response->status() === 401 => 'unauthorized',
            $response->status() === 403 => 'forbidden',
            $response->status() === 404 => 'unauthorized',
            $response->status() === 402 => 'payment_required',
            $response->status() === 429 => 'rate_limited',
            $response->serverError() => 'server_error',
            default => 'unexpected_response',
        });
    }

    /**
     * Log the failure without the exception message (it may contain the URL).
     */
    /**
     * @param  array<string, string|int>  $details
     */
    protected function failed(string $code, Throwable $e, array $details = []): ConnectionTestResult
    {
        Log::warning('Integration connection test failed', ['code' => $code, 'exception' => $e::class]);

        return ConnectionTestResult::failure($code, $details);
    }
}
