<?php

namespace App\Services\Ai;

use App\Ai\Agents\FieldWriter;
use App\Ai\Agents\LocalizedTextAgent;
use App\Ai\Agents\Translator;
use App\Enums\AiErrorCode;
use App\Enums\AiProvider;
use App\Support\Secrets;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * TextAssistant on Claude through laravel/ai (agents FieldWriter and
 * Translator, structured output {uz, ru, en}); prompts from TextPrompts.
 * The key comes from the Secrets resolver (IntegrationRegistry writes it to
 * ai.providers.anthropic.key); the model is the one stored on the request
 * row (default: Settings → Integrations, see model()).
 *
 * Every failure becomes an AiException with a safe code; the provider's
 * exception (which may contain request details) is dropped, not chained.
 */
final class ClaudeTextAssistant implements TextAssistant
{
    public function generate(TextTask $task, string $model): TextResult
    {
        return $this->run(new FieldWriter, TextPrompts::generate($task), $task, $model);
    }

    public function translate(TextTask $task, string $model): TextResult
    {
        return $this->run(new Translator, TextPrompts::translate($task), $task, $model);
    }

    /**
     * Model used for text requests (the admin setting or the first allowed one).
     */
    public static function model(): string
    {
        $model = config('ai-assist.text_model');

        return is_string($model) && $model !== '' ? $model : (string) (config('ai-assist.models.text')[0] ?? 'claude-opus-5-5');
    }

    private function run(LocalizedTextAgent $agent, string $prompt, TextTask $task, string $model): TextResult
    {
        if (! Secrets::isConfigured(AiProvider::Anthropic->keyField())) {
            throw AiException::for(AiErrorCode::NotConfigured);
        }

        try {
            $response = $agent->prompt($prompt, provider: AiProvider::Anthropic->value, model: $model, timeout: (int) config('ai-assist.timeout', 45));
        } catch (Throwable $exception) {
            throw AiException::for(self::codeFor($exception));
        }

        // Usage first: an unusable answer is still billed and gets logged with its tokens.
        $inputTokens = $response->usage->inputTokens;
        $outputTokens = $response->usage->outputTokens;

        if (! $response instanceof StructuredAgentResponse) {
            throw AiException::for(AiErrorCode::InvalidOutput, $inputTokens, $outputTokens, $model);
        }

        $texts = TextPrompts::texts($task, (array) $response->structured);

        if ($texts === null) {
            throw AiException::for(AiErrorCode::InvalidOutput, $inputTokens, $outputTokens, $model);
        }

        return new TextResult($texts, $model, $inputTokens, $outputTokens);
    }

    /**
     * Safe code for a provider / transport exception. A rejected request
     * (4xx other than auth / rate limit) is reported with the HTTP status and
     * Anthropic's error.type only, never the message or the body.
     */
    public static function codeFor(Throwable $exception): AiErrorCode
    {
        $code = match (true) {
            $exception instanceof AiException => $exception->errorCode,
            $exception instanceof RateLimitedException => AiErrorCode::RateLimited,
            $exception instanceof ProviderConnectionException, $exception instanceof ConnectionException => self::isTimeout($exception) ? AiErrorCode::Timeout : AiErrorCode::ProviderError,
            $exception instanceof RequestException && in_array($exception->response->status(), [401, 403], true) => AiErrorCode::NotConfigured,
            default => AiErrorCode::ProviderError,
        };

        if ($exception instanceof RequestException && $exception->response->status() >= 400 && $exception->response->status() < 500 && $code === AiErrorCode::ProviderError) {
            $type = $exception->response->json('error.type');
            $type = is_string($type) && preg_match('/^[a-z_]{1,60}$/', $type) === 1 ? $type : 'unknown';

            report(new RuntimeException('Anthropic rejected an AI request: HTTP '.$exception->response->status().', '.$type.'.'));
        }

        return $code;
    }

    /**
     * A transport exception (or one it wraps) is a timeout.
     */
    public static function isTimeout(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (preg_match('/timed out|cURL error 28|timeout/i', $current->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }
}
