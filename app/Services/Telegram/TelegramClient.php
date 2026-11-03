<?php

namespace App\Services\Telegram;

use App\Support\Secrets;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one Telegram Bot API client (plan 05c, extended in plan 14 v2 B2):
 * team alerts, the Integrations connection test and the customer bot
 * (messages, callbacks, copies, webhook and commands setup). Plain Http,
 * TIMEOUT seconds, no retries (queued callers retry). The bot token comes
 * from the Secrets resolver only; every failure is a TelegramException
 * without the URL (condition 18).
 *
 * Note: the token is part of the request URL, and Laravel's HTTP client
 * events (RequestSending, ResponseReceived, ConnectionFailed) carry that
 * URL. If request monitoring is ever added (Telescope, Pulse, a Sentry
 * HTTP integration, a custom listener), filter out api.telegram.org/bot*.
 */
class TelegramClient
{
    public const API = 'https://api.telegram.org';

    public const TIMEOUT = 10;

    protected int $timeout = self::TIMEOUT;

    /**
     * Connection timeout; null = the same as $timeout.
     */
    protected ?int $connectTimeout = null;

    /**
     * A copy with another timeout (the admin connection test uses 5 s),
     * optionally with a shorter connection timeout (the bot page uses 3 s).
     */
    public function withTimeout(int $seconds, ?int $connectSeconds = null): static
    {
        $client = clone $this;
        $client->timeout = $seconds;
        $client->connectTimeout = $connectSeconds;

        return $client;
    }

    public function isConfigured(): bool
    {
        return $this->token() !== null;
    }

    /**
     * getMe: the bot's own user (username, id).
     *
     * @return array<string, mixed>
     *
     * @throws TelegramException
     */
    public function getMe(): array
    {
        return $this->call('getMe', [], get: true);
    }

    /**
     * Send a message. Team alerts use HTML (escape every user value with e());
     * customer-facing bot copy with user values and staff replies pass
     * parseMode null (plain text, condition 15).
     *
     * @param  list<array{text: string, url: string}>  $buttons  URL buttons, one per row (ignored when $replyMarkup is given)
     * @param  array<string, mixed>|null  $replyMarkup  Inline keyboard, reply keyboard or remove_keyboard markup
     * @return array<string, mixed> The sent message
     *
     * @throws TelegramException
     */
    public function sendMessage(string $chatId, string $html, array $buttons = [], ?array $replyMarkup = null, ?string $parseMode = 'HTML'): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $html,
            'link_preview_options' => ['is_disabled' => true],
        ];

        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        } elseif ($buttons !== []) {
            $payload['reply_markup'] = [
                'inline_keyboard' => array_map(fn (array $button): array => [['text' => $button['text'], 'url' => $button['url']]], $buttons),
            ];
        }

        return $this->call('sendMessage', $payload);
    }

    /**
     * Stop the loading spinner of an inline button (optional short notice).
     *
     * @throws TelegramException
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $this->callRaw('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * Replace (or, with null, remove) the inline keyboard of a sent message.
     *
     * @param  array<string, mixed>|null  $replyMarkup
     *
     * @throws TelegramException
     */
    public function editMessageReplyMarkup(string $chatId, int $messageId, ?array $replyMarkup = null): void
    {
        $this->callRaw('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup ?? ['inline_keyboard' => []],
        ]);
    }

    /**
     * Copy a message (any kind) into another chat without a "forwarded from"
     * header. Always sends an empty reply_markup (no copied buttons). A
     * non-null $caption replaces the original caption as plain text (no
     * parse_mode, no entities: TG-21). The reply target may be gone
     * (allow_sending_without_reply).
     *
     * @return int The new message_id
     *
     * @throws TelegramException
     */
    public function copyMessage(string $chatId, string $fromChatId, int $messageId, ?int $replyToMessageId = null, ?string $caption = null): int
    {
        $payload = [
            'chat_id' => $chatId,
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId,
            'reply_markup' => ['inline_keyboard' => []],
        ];

        if ($caption !== null) {
            $payload['caption'] = $caption;
            $payload['caption_entities'] = [];
        }

        if ($replyToMessageId !== null) {
            $payload['reply_parameters'] = ['message_id' => $replyToMessageId, 'allow_sending_without_reply' => true];
        }

        return (int) ($this->call('copyMessage', $payload)['message_id'] ?? 0);
    }

    /**
     * Register the webhook (plan 14 v2 D2). Callers build the URL from
     * config('app.url') only (ConfigureTelegramWebhook, condition 1).
     *
     * @param  list<string>  $allowedUpdates
     *
     * @throws TelegramException
     */
    public function setWebhook(string $url, #[\SensitiveParameter] string $secretToken, array $allowedUpdates, int $maxConnections, bool $dropPendingUpdates = true): void
    {
        $this->callRaw('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => $allowedUpdates,
            'max_connections' => $maxConnections,
            'drop_pending_updates' => $dropPendingUpdates,
        ]);
    }

    /**
     * @throws TelegramException
     */
    public function deleteWebhook(bool $dropPendingUpdates = false): void
    {
        $this->callRaw('deleteWebhook', ['drop_pending_updates' => $dropPendingUpdates]);
    }

    /**
     * Current webhook state: url, pending_update_count, last_error_date, ….
     *
     * @return array<string, mixed>
     *
     * @throws TelegramException
     */
    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo', [], get: true);
    }

    /**
     * Set the bot's command menu, optionally for one user language.
     *
     * @param  list<array{command: string, description: string}>  $commands
     *
     * @throws TelegramException
     */
    public function setMyCommands(array $commands, ?string $languageCode = null): void
    {
        $payload = ['commands' => $commands];

        if ($languageCode !== null) {
            $payload['language_code'] = $languageCode;
        }

        $this->callRaw('setMyCommands', $payload);
    }

    /**
     * A chat's type / username (used to refuse public forward targets, TG-23).
     *
     * @return array<string, mixed>
     *
     * @throws TelegramException
     */
    public function getChat(string $chatId): array
    {
        return $this->call('getChat', ['chat_id' => $chatId]);
    }

    /**
     * Long polling (development only: telegram:poll). Use withTimeout() above $timeout.
     *
     * @param  list<string>  $allowedUpdates
     * @return list<array<string, mixed>>
     *
     * @throws TelegramException
     */
    public function getUpdates(int $offset, int $timeout, array $allowedUpdates): array
    {
        return array_values(array_filter($this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => $allowedUpdates,
        ]), 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws TelegramException
     */
    protected function call(string $method, array $payload, bool $get = false): array
    {
        $result = $this->callRaw($method, $payload, $get);

        return is_array($result) ? $result : [];
    }

    /**
     * One API call; returns "result" as is (array, true, …).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws TelegramException
     */
    protected function callRaw(string $method, #[\SensitiveParameter] array $payload, bool $get = false): mixed
    {
        $token = $this->token();

        if ($token === null) {
            throw new TelegramException(401);
        }

        try {
            $request = Http::timeout($this->timeout)->connectTimeout($this->connectTimeout ?? $this->timeout)->acceptJson();
            $response = $get ? $request->get($this->url($token, $method)) : $request->asJson()->post($this->url($token, $method), $payload);
        } catch (ConnectionException $exception) {
            $message = strtolower($exception->getMessage());

            throw new TelegramException(0, timedOut: str_contains($message, 'timed out') || str_contains($message, 'curl error 28'));
        } catch (Throwable) {
            // Any other client error message may contain the URL (and so the token).
            throw new TelegramException(0);
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new TelegramException($response->status(), $this->retryAfter($response));
        }

        return $response->json('result');
    }

    protected function token(): ?string
    {
        $token = Secrets::get('telegram.bot_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    protected function url(#[\SensitiveParameter] string $token, string $method): string
    {
        return self::API.'/bot'.$token.'/'.$method;
    }

    private function retryAfter(Response $response): ?int
    {
        $seconds = $response->json('parameters.retry_after') ?? $response->header('Retry-After');

        return is_numeric($seconds) ? max(1, (int) $seconds) : null;
    }
}
