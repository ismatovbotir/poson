<?php

namespace App\Services\Telegram;

use App\Support\Secrets;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one Telegram Bot API client (plan 05c): lead alerts and the
 * Integrations connection test. Plain Http, TIMEOUT seconds, no retries
 * (queued callers retry). The bot token comes from the Secrets resolver;
 * every failure is a TelegramException without the URL.
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
     * A copy with another timeout (the admin connection test uses 5 s).
     */
    public function withTimeout(int $seconds): static
    {
        $client = clone $this;
        $client->timeout = $seconds;

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
     * Send an HTML message (parse_mode HTML; escape every user value with e()).
     *
     * @param  list<array{text: string, url: string}>  $buttons  URL buttons, one per row
     * @return array<string, mixed> The sent message
     *
     * @throws TelegramException
     */
    public function sendMessage(string $chatId, string $html, array $buttons = []): array
    {
        $payload = [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];

        if ($buttons !== []) {
            $payload['reply_markup'] = [
                'inline_keyboard' => array_map(fn (array $button): array => [['text' => $button['text'], 'url' => $button['url']]], $buttons),
            ];
        }

        return $this->call('sendMessage', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws TelegramException
     */
    protected function call(string $method, array $payload, bool $get = false): array
    {
        $token = $this->token();

        if ($token === null) {
            throw new TelegramException(401);
        }

        try {
            $request = Http::timeout($this->timeout)->connectTimeout($this->timeout)->acceptJson();
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

        $result = $response->json('result');

        return is_array($result) ? $result : [];
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
