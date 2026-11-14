<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;
use Anthropic\RequestOptions;
use GuzzleHttp\Client as GuzzleClient;
use LogicException;
use Throwable;

/**
 * ChatModel on the Anthropic Messages API (plan 30 §4, AI-1, AI-3, AI-5).
 * The only class that uses the Anthropic SDK (arch test, AI-4).
 *
 *  - The key comes from config('services.anthropic.key') only (.env), the
 *    base URL is the fixed config constant: the SDK never falls back to its
 *    own environment variables. An empty key throws AiUnavailable before
 *    any client exists.
 *  - The client is built lazily per call site with maxRetries 0, a total
 *    timeout of chatbot.ai.timeout (12 s) and a connect timeout of
 *    chatbot.ai.connect_timeout (3 s), set on the SDK options and on the
 *    Guzzle transporter (the SDK has no connect-timeout option of its own),
 *    redirects off and OpenTelemetry off.
 *  - Request: model id from config (exact), max_tokens, the system blocks
 *    (cache_control "ephemeral" on the blocks flagged cache), the messages,
 *    output_config effort "low". No temperature, top_p, tools, metadata or
 *    assistant prefill.
 *  - Every Throwable becomes AiFailed(class, status) without "previous".
 *
 * $middleware is for tests only (canned PSR-7 responses through the SDK
 * middleware option): in the test environment a model without it refuses
 * to build a client at all, so no test can ever reach the network (AI-25).
 */
final class AnthropicChatModel implements ChatModel
{
    public const BASE_URL = 'https://api.anthropic.com';

    private ?Client $client = null;

    /**
     * @param  list<callable>  $middleware
     */
    public function __construct(private readonly array $middleware = []) {}

    public function complete(AiRequest $request): AiResult
    {
        $key = trim((string) config('services.anthropic.key'));

        if ($key === '') {
            throw new AiUnavailable;
        }

        if ($this->middleware === [] && app()->runningUnitTests()) {
            throw new LogicException('AnthropicChatModel must not reach the network in tests: bind a fake ChatModel.');
        }

        $started = hrtime(true);

        try {
            $message = $this->client($key)->messages->create(
                maxTokens: $request->maxTokens,
                messages: $request->messages,
                model: (string) config('chatbot.ai.model'),
                outputConfig: ['effort' => 'low'],
                system: $this->system($request),
            );

            $text = '';

            foreach ($message->content as $block) {
                if ($block instanceof TextBlock) {
                    $text .= $block->text;
                }
            }

            return new AiResult(
                text: $text,
                stopReason: $message->stopReason,
                inputTokens: (int) $message->usage->inputTokens,
                outputTokens: (int) $message->usage->outputTokens,
                cacheReadTokens: (int) ($message->usage->cacheReadInputTokens ?? 0),
                cacheWriteTokens: (int) ($message->usage->cacheCreationInputTokens ?? 0),
                model: mb_substr((string) $message->model, 0, 40),
                latencyMs: (int) round((hrtime(true) - $started) / 1_000_000),
            );
        } catch (Throwable $error) {
            throw AiFailed::from($error);
        }
    }

    /**
     * @return list<array{type: 'text', text: string, cache_control?: array{type: 'ephemeral'}}>
     */
    private function system(AiRequest $request): array
    {
        $blocks = [];

        foreach ($request->system as $block) {
            $item = ['type' => 'text', 'text' => $block['text']];

            if ($block['cache']) {
                $item['cache_control'] = ['type' => 'ephemeral'];
            }

            $blocks[] = $item;
        }

        return $blocks;
    }

    private function client(#[\SensitiveParameter] string $key): Client
    {
        $timeout = (float) config('chatbot.ai.timeout', 12);

        return $this->client ??= new Client(
            apiKey: $key,
            baseUrl: (string) config('services.anthropic.base_url', self::BASE_URL),
            requestOptions: RequestOptions::with(
                timeout: $timeout,
                maxRetries: 0,
                middleware: $this->middleware === [] ? null : $this->middleware,
                transporter: new GuzzleClient([
                    'timeout' => $timeout,
                    'connect_timeout' => (float) config('chatbot.ai.connect_timeout', 3),
                    'allow_redirects' => false,
                    'http_errors' => false,
                ]),
            ),
            openTelemetry: false,
        );
    }
}
