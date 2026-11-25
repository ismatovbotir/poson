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
 * (messages, callbacks, copies, webhook setup). Plain Http,
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

    /**
     * sendDocument / downloadFile (plan 31): total and connection timeouts.
     */
    public const DOCUMENT_TIMEOUT = 30;

    public const DOCUMENT_CONNECT_TIMEOUT = 5;

    /**
     * A Telegram file_path: relative segments of safe characters (CR-7).
     */
    public const FILE_PATH_PATTERN = '#^[A-Za-z0-9_-]+(/[A-Za-z0-9_.-]+)*$#D';

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
     * Show "typing…" in a private chat for a few seconds (plan 30, AI-16).
     * Callers treat it as best effort and use a short timeout.
     *
     * @throws TelegramException
     */
    public function sendChatAction(string $chatId, string $action = 'typing'): void
    {
        $this->callRaw('sendChatAction', ['chat_id' => $chatId, 'action' => $action]);
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
     * Delete a message. Telegram allows it only within 48 hours of sending.
     *
     * @throws TelegramException
     */
    public function deleteMessage(string $chatId, int $messageId): void
    {
        $this->callRaw('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
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
     * A Telegram Business connection (plan 41 §3): the only business call
     * the app makes in phase 1 (read-only, VO-2). Returns BusinessConnection.
     *
     * @return array<string, mixed>
     *
     * @throws TelegramException
     */
    public function getBusinessConnection(string $connectionId): array
    {
        return $this->call('getBusinessConnection', ['business_connection_id' => $connectionId]);
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
     * Send a file as a document (plan 31, CR-7): multipart upload, the
     * caption in HTML (every user value already escaped with e()), URL
     * buttons one per row. Connection timeout DOCUMENT_CONNECT_TIMEOUT,
     * total DOCUMENT_TIMEOUT. The bytes never reach a log or an exception.
     *
     * @param  list<array{text: string, url: string}>  $buttons
     * @return array<string, mixed> The sent message (document.file_id, document.file_unique_id, message_id)
     *
     * @throws TelegramException
     */
    public function sendDocument(string $chatId, #[\SensitiveParameter] string $bytes, string $filename, string $caption, array $buttons = []): array
    {
        $fields = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];

        if ($buttons !== []) {
            $fields['reply_markup'] = (string) json_encode([
                'inline_keyboard' => array_map(fn (array $button): array => [['text' => $button['text'], 'url' => $button['url']]], $buttons),
            ]);
        }

        $token = $this->requireToken();

        $response = $this->perform(fn (): Response => Http::timeout(self::DOCUMENT_TIMEOUT)
            ->connectTimeout(self::DOCUMENT_CONNECT_TIMEOUT)
            ->acceptJson()
            ->attach('document', $bytes, $filename, ['Content-Type' => 'application/pdf'])
            ->post($this->url($token, 'sendDocument'), $fields));

        $result = $this->result($response);

        return is_array($result) ? $result : [];
    }

    /**
     * getFile (plan 31, CR-7): file_id → file_path, file_unique_id, file_size.
     * A file_path that does not match FILE_PATH_PATTERN (≤ 256 chars, no
     * "..", "//" or scheme) is refused as a 400.
     *
     * @return array{file_path: string, file_unique_id: string, file_size: int|null}
     *
     * @throws TelegramException
     */
    public function getFile(string $fileId): array
    {
        $result = $this->call('getFile', ['file_id' => $fileId]);
        $path = $result['file_path'] ?? null;

        if (! is_string($path) || ! self::isSafeFilePath($path)) {
            throw new TelegramException(400);
        }

        return [
            'file_path' => $path,
            'file_unique_id' => is_string($result['file_unique_id'] ?? null) ? $result['file_unique_id'] : '',
            'file_size' => is_numeric($result['file_size'] ?? null) ? (int) $result['file_size'] : null,
        ];
    }

    /**
     * Download a file from Telegram's file endpoint (plan 31, CR-7), never
     * returned to a browser as a URL. Only self::API, no redirects; refused
     * (413) when Content-Length or the streamed body exceeds $maxBytes. An
     * unsafe path is refused (400) before any HTTP request.
     *
     * @throws TelegramException
     */
    public function downloadFile(string $filePath, int $maxBytes): string
    {
        if (! self::isSafeFilePath($filePath)) {
            throw new TelegramException(400);
        }

        $token = $this->requireToken();

        $response = $this->perform(fn (): Response => Http::timeout(self::DOCUMENT_TIMEOUT)
            ->connectTimeout(self::DOCUMENT_CONNECT_TIMEOUT)
            ->withOptions(['allow_redirects' => false, 'stream' => true])
            ->get(self::API.'/file/bot'.$token.'/'.$filePath));

        if (! $response->successful()) {
            throw new TelegramException($response->status());
        }

        $length = $response->header('Content-Length');

        if (is_numeric($length) && (int) $length > $maxBytes) {
            throw new TelegramException(413);
        }

        try {
            $body = $response->toPsrResponse()->getBody();
            $bytes = '';

            while (! $body->eof() && strlen($bytes) <= $maxBytes) {
                $bytes .= $body->read(min(65536, $maxBytes + 1 - strlen($bytes)));
            }
        } catch (Throwable) {
            throw new TelegramException(0);
        }

        if (strlen($bytes) > $maxBytes) {
            throw new TelegramException(413);
        }

        return $bytes;
    }

    /**
     * Whether a Telegram file_path is a plain relative path (CR-7).
     */
    public static function isSafeFilePath(string $path): bool
    {
        return strlen($path) <= 256
            && preg_match(self::FILE_PATH_PATTERN, $path) === 1
            && ! str_contains($path, '..');
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
        $token = $this->requireToken();

        $response = $this->perform(function () use ($token, $method, $payload, $get): Response {
            $request = Http::timeout($this->timeout)->connectTimeout($this->connectTimeout ?? $this->timeout)->acceptJson();

            return $get ? $request->get($this->url($token, $method)) : $request->asJson()->post($this->url($token, $method), $payload);
        });

        return $this->result($response);
    }

    /**
     * Run one HTTP request; every client failure becomes a TelegramException
     * without the URL (and so without the token).
     *
     * @param  \Closure(): Response  $send
     *
     * @throws TelegramException
     */
    protected function perform(\Closure $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException $exception) {
            $message = strtolower($exception->getMessage());

            throw new TelegramException(0, timedOut: str_contains($message, 'timed out') || str_contains($message, 'curl error 28'));
        } catch (Throwable) {
            // Any other client error message may contain the URL (and so the token).
            throw new TelegramException(0);
        }
    }

    /**
     * "result" of a Bot API response, or a TelegramException.
     *
     * @throws TelegramException
     */
    protected function result(Response $response): mixed
    {
        if (! $response->successful() || $response->json('ok') !== true) {
            throw new TelegramException($response->status(), $this->retryAfter($response));
        }

        return $response->json('result');
    }

    /**
     * @throws TelegramException
     */
    protected function requireToken(): string
    {
        $token = $this->token();

        if ($token === null) {
            throw new TelegramException(401);
        }

        return $token;
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
