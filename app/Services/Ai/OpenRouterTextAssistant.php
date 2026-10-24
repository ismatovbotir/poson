<?php

namespace App\Services\Ai;

use App\Enums\AiErrorCode;
use App\Enums\AiProvider;
use App\Support\CompanySettings;
use App\Support\Secrets;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * TextAssistant on OpenRouter (plan 10): POST /api/v1/chat/completions with
 * Laravel's Http client (not laravel/ai's OpenRouterProvider, which drops
 * usage.cost and forces a strict json_schema). Same system prompt, user
 * prompt and output checks as Claude (SystemPrompt, TextPrompts); BrandGuard
 * and the sanitizer run afterwards in ProcessAiRequest.
 *
 * Request: a non-strict json_schema response_format plus a "JSON only" line
 * (many models ignore the schema), usage.include for the exact cost, the
 * HTTP-Referer / X-Title attribution headers, and provider.data_collection
 * "deny" when prompts may not be logged (Settings → Integrations). No
 * require_parameters, no Http retries.
 *
 * Errors become safe AiErrorCodes: 401 invalid_key, 403 refused, 402
 * no_credits, 404 or 400 "model not found / no endpoints" model_unavailable,
 * 408 or a cURL timeout timeout, 429 rate_limited, other 4xx bad_request,
 * 5xx / an error on a 200 / a malformed answer (no choices list, non-string
 * content) provider_error, finish_reason "length" or unreadable JSON
 * invalid_output. Usage from the answer is clamped and kept on failures. Only the HTTP status and OpenRouter's error code are
 * ever reported, never the body or the key.
 */
final class OpenRouterTextAssistant implements TextAssistant
{
    public const URL = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * OpenRouter model ID: "provider/model", optional ":variant" (e.g. ":free").
     */
    public const MODEL_PATTERN = '/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._:-]*$/D';

    public const MODEL_MAX = 80;

    public const DEFAULT_MODEL = 'nvidia/nemotron-3.5-super-vl:free';

    public const CONNECT_TIMEOUT = 10;

    public function generate(TextTask $task, string $model): TextResult
    {
        return $this->run(TextPrompts::generate($task), $task, $model);
    }

    public function translate(TextTask $task, string $model): TextResult
    {
        return $this->run(TextPrompts::translate($task), $task, $model);
    }

    /**
     * Model of new requests: the configured value (.env or admin), else the
     * first suggestion.
     *
     * @throws AiException not_configured when the configured value is not a
     *                     valid ID (reported by key name; never silently replaced
     *                     by the default free model)
     */
    public static function model(): string
    {
        $configured = config('ai-assist.openrouter_model');

        if (! is_string($configured) || trim($configured) === '') {
            $first = config('ai-assist.models.openrouter')[0] ?? null;

            return is_string($first) && self::isValidModel($first) ? $first : self::DEFAULT_MODEL;
        }

        $model = strtolower(trim($configured));

        if (! self::isValidModel($model)) {
            self::reportInvalidModel();

            throw AiException::for(AiErrorCode::NotConfigured);
        }

        return $model;
    }

    /**
     * model(), or null when the configured value is invalid (for display).
     */
    public static function modelOrNull(): ?string
    {
        try {
            return self::model();
        } catch (AiException) {
            return null;
        }
    }

    public static function isValidModel(string $model): bool
    {
        return strlen($model) <= self::MODEL_MAX && preg_match(self::MODEL_PATTERN, $model) === 1;
    }

    /**
     * Prompts may go to providers that log or train on them (default yes).
     */
    public static function allowsDataCollection(): bool
    {
        return config('ai-assist.openrouter_allow_data_collection') !== false;
    }

    /**
     * Request body of a completion.
     *
     * @return array<string, mixed>
     */
    public static function payload(string $prompt, string $model): array
    {
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => SystemPrompt::text()],
                ['role' => 'user', 'content' => $prompt."\n\n".TextPrompts::JSON_ONLY],
            ],
            'max_tokens' => (int) config('ai-assist.max_output_tokens', 8192),
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'localized_text', 'strict' => false, 'schema' => TextPrompts::schema()],
            ],
            'usage' => ['include' => true],
        ];

        if (! self::allowsDataCollection()) {
            $payload['provider'] = ['data_collection' => 'deny'];
        }

        return $payload;
    }

    /**
     * Attribution headers OpenRouter shows in its dashboard (ASCII only).
     *
     * @return array{HTTP-Referer: string, X-Title: string}
     */
    public static function attributionHeaders(): array
    {
        $title = trim((string) preg_replace('/[^\x20-\x7E]/', '', app(CompanySettings::class)->name('en')));

        return [
            'HTTP-Referer' => (string) config('app.url'),
            'X-Title' => $title !== '' ? $title : 'Poson',
        ];
    }

    private function run(string $prompt, TextTask $task, string $model): TextResult
    {
        $key = Secrets::get(AiProvider::OpenRouter->keyField());

        if (! is_string($key) || $key === '') {
            throw AiException::for(AiErrorCode::NotConfigured);
        }

        try {
            $response = Http::withToken($key)
                ->withHeaders(self::attributionHeaders())
                ->acceptJson()
                ->timeout((int) config('ai-assist.timeout', 45))
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->post(self::URL, self::payload($prompt, $model));
        } catch (ConnectionException $exception) {
            throw AiException::for(ClaudeTextAssistant::isTimeout($exception) ? AiErrorCode::Timeout : AiErrorCode::ProviderError);
        } catch (Throwable) {
            throw AiException::for(AiErrorCode::ProviderError);
        }

        if (! $response->successful()) {
            throw AiException::for(self::codeForResponse($response));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw AiException::for(AiErrorCode::ProviderError);
        }

        // Usage first: an unusable answer is still billed and gets logged with its cost.
        // Hostile or broken values are clamped (tokens) or ignored (cost: token pricing applies).
        $inputTokens = AiCost::tokens(data_get($body, 'usage.prompt_tokens'));
        $outputTokens = AiCost::tokens(data_get($body, 'usage.completion_tokens'));
        $cost = AiCost::billed($model, AiCost::fromUsd(data_get($body, 'usage.cost')), $inputTokens, $outputTokens);
        $fail = fn (AiErrorCode $code): AiException => AiException::for($code, $inputTokens, $outputTokens, $model, $cost);

        if (isset($body['error'])) {
            self::reportRejected($response->status(), data_get($body, 'error.code'));

            throw $fail(AiErrorCode::ProviderError);
        }

        $choices = $body['choices'] ?? null;
        $choice = is_array($choices) && array_is_list($choices) ? ($choices[0] ?? null) : null;

        if (! is_array($choice)) {
            throw $fail(AiErrorCode::ProviderError);
        }

        if (isset($choice['error'])) {
            self::reportRejected($response->status(), data_get($choice, 'error.code'));

            throw $fail(AiErrorCode::ProviderError);
        }

        if (($choice['finish_reason'] ?? null) === 'length') {
            throw $fail(AiErrorCode::InvalidOutput);
        }

        $content = data_get($choice, 'message.content');

        if (! is_string($content)) {
            throw $fail(AiErrorCode::ProviderError);
        }

        $structured = TextPrompts::decodeJson($content);
        $texts = $structured !== null ? TextPrompts::texts($task, $structured) : null;

        if ($texts === null) {
            throw $fail(AiErrorCode::InvalidOutput);
        }

        return new TextResult($texts, $model, $inputTokens, $outputTokens, $cost);
    }

    /**
     * Safe code for a failed HTTP response.
     */
    public static function codeForResponse(Response $response): AiErrorCode
    {
        $status = $response->status();

        $code = match (true) {
            $status === 401 => AiErrorCode::InvalidKey,
            $status === 403 => AiErrorCode::Refused,
            $status === 402 => AiErrorCode::NoCredits,
            $status === 404, $status === 400 && self::isModelUnavailable($response) => AiErrorCode::ModelUnavailable,
            $status === 408 => AiErrorCode::Timeout,
            $status === 429 => AiErrorCode::RateLimited,
            $status >= 400 && $status < 500 => AiErrorCode::BadRequest,
            default => AiErrorCode::ProviderError,
        };

        if (in_array($code, [AiErrorCode::ProviderError, AiErrorCode::BadRequest], true)) {
            self::reportRejected($status, $response->json('error.code'));
        }

        return $code;
    }

    /**
     * 400 for an unknown model or a model without a matching endpoint. The
     * message is only matched here, never stored or logged.
     */
    private static function isModelUnavailable(Response $response): bool
    {
        $code = $response->json('error.code');
        $message = $response->json('error.message');

        return $code === 'model_not_found'
            || (is_string($message) && preg_match('/model_not_found|no endpoints found|not a valid model/i', $message) === 1);
    }

    /**
     * Report the invalid configured model (by key name only), at most once an hour.
     */
    private static function reportInvalidModel(): void
    {
        try {
            if (! Cache::add('ai_openrouter_model_invalid', true, 3600)) {
                return;
            }
        } catch (Throwable) {
            // Report anyway.
        }

        report(new RuntimeException('Integration setting [ai.openrouter_model] has an invalid value (AI_OPENROUTER_MODEL or Settings → Integrations); OpenRouter text requests are refused until it is fixed.'));
    }

    /**
     * Report a rejected request by HTTP status and OpenRouter's error code only.
     */
    private static function reportRejected(int $status, mixed $code): void
    {
        $code = is_int($code) || (is_string($code) && preg_match('/^[A-Za-z0-9_]{1,60}$/', $code) === 1) ? (string) $code : 'unknown';

        report(new RuntimeException('OpenRouter rejected an AI request: HTTP '.$status.', '.$code.'.'));
    }
}
