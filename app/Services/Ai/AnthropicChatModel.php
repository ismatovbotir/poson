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
 * Plan 32 overrides (PW-5), checked BEFORE the key and before any client
 * exists (LogicException, no request): the model must be in MODELS (a class
 * constant, so a config typo cannot widen it), effort in EFFORTS, at most
 * MAX_IMAGES images, each image/jpeg whose realpath lies inside
 * storage/app/private/ai-drafts/ (no traversal, no symlink out), at most
 * IMAGE_MAX_BYTES. The timeout is clamped to MIN_TIMEOUT..MAX_TIMEOUT (below
 * every job timeout), max_tokens to 1..MAX_TOKENS. Images go base64 on the
 * last user message, before its text; outputSchema becomes
 * output_config.format (json_schema). One client per timeout. With no
 * overrides the request is exactly the chat bot's (adapter test).
 *
 * $middleware is for tests only (canned PSR-7 responses through the SDK
 * middleware option): in the test environment a model without it refuses
 * to build a client at all, so no test can ever reach the network (AI-25).
 */
final class AnthropicChatModel implements ChatModel
{
    public const BASE_URL = 'https://api.anthropic.com';

    /**
     * The only models any request may name.
     */
    public const MODELS = ['claude-haiku-5-5', 'claude-sonnet-5-5'];

    public const EFFORTS = ['low', 'medium'];

    public const MAX_IMAGES = 6;

    public const IMAGE_MAX_BYTES = 5_242_880;

    public const MIN_TIMEOUT = 1.0;

    /**
     * Below the shortest wizard job timeout that may use it (write job: 150 s).
     */
    public const MAX_TIMEOUT = 140.0;

    public const MAX_TOKENS = 8000;

    /**
     * Folder (under storage/app/private) that attached images must live in.
     */
    public const IMAGE_ROOT = 'ai-drafts';

    /** @var array<string, Client> */
    private array $clients = [];

    /**
     * @param  list<callable>  $middleware
     */
    public function __construct(private readonly array $middleware = []) {}

    public function complete(AiRequest $request): AiResult
    {
        $model = $this->model($request);
        $effort = $this->effort($request);
        $images = $this->images($request);
        $timeout = $request->timeout === null ? null : max(self::MIN_TIMEOUT, min(self::MAX_TIMEOUT, $request->timeout));
        $maxTokens = max(1, min(self::MAX_TOKENS, $request->maxTokens));

        $key = trim((string) config('services.anthropic.key'));

        if ($key === '') {
            throw new AiUnavailable;
        }

        if ($this->middleware === [] && app()->runningUnitTests()) {
            throw new LogicException('AnthropicChatModel must not reach the network in tests: bind a fake ChatModel.');
        }

        $started = hrtime(true);

        try {
            $outputConfig = ['effort' => $effort];

            if ($request->outputSchema !== null) {
                $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $request->outputSchema];
            }

            $message = $this->client($key, $timeout)->messages->create(
                maxTokens: $maxTokens,
                messages: $this->messages($request, $images),
                model: $model,
                outputConfig: $outputConfig,
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

    private function model(AiRequest $request): string
    {
        $model = $request->model ?? (string) config('chatbot.ai.model');

        if (! in_array($model, self::MODELS, true)) {
            throw new LogicException('AI model not allowed.');
        }

        return $model;
    }

    private function effort(AiRequest $request): string
    {
        $effort = $request->effort ?? 'low';

        if (! in_array($effort, self::EFFORTS, true)) {
            throw new LogicException('AI effort not allowed.');
        }

        return $effort;
    }

    /**
     * The checked image files (real paths) in request order.
     *
     * @return list<string>
     */
    private function images(AiRequest $request): array
    {
        if ($request->images === []) {
            return [];
        }

        if (count($request->images) > self::MAX_IMAGES) {
            throw new LogicException('Too many AI images.');
        }

        $root = realpath(storage_path('app/private/'.self::IMAGE_ROOT));

        if ($root === false) {
            throw new LogicException('AI image folder missing.');
        }

        $paths = [];

        foreach ($request->images as $image) {
            if (! is_array($image) || ($image['mediaType'] ?? null) !== 'image/jpeg' || ! is_string($image['path'] ?? null)) {
                throw new LogicException('AI image not allowed.');
            }

            $path = realpath($image['path']);

            if ($path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path) || filesize($path) > self::IMAGE_MAX_BYTES) {
                throw new LogicException('AI image not allowed.');
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * The messages, the images (base64) put before the text of the last user message.
     *
     * @param  list<string>  $images
     * @return list<array<string, mixed>>
     */
    private function messages(AiRequest $request, array $images): array
    {
        $messages = $request->messages;

        if ($images === []) {
            return $messages;
        }

        $last = array_key_last($messages);

        if ($last === null || $messages[$last]['role'] !== 'user') {
            throw new LogicException('AI images need a last user message.');
        }

        $content = [];

        foreach ($images as $path) {
            $bytes = file_get_contents($path);

            if ($bytes === false || ! str_starts_with($bytes, "\xFF\xD8\xFF")) {
                throw new LogicException('AI image not allowed.');
            }

            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($bytes)]];
        }

        $content[] = ['type' => 'text', 'text' => $messages[$last]['content']];
        $messages[$last]['content'] = $content;

        return $messages;
    }

    private function client(#[\SensitiveParameter] string $key, ?float $override = null): Client
    {
        $timeout = $override ?? (float) config('chatbot.ai.timeout', 12);

        return $this->clients[(string) $timeout] ??= new Client(
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
