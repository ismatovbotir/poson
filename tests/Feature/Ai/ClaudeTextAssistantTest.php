<?php

use App\Ai\Agents\FieldWriter;
use App\Ai\Agents\Translator;
use App\Enums\AiErrorCode;
use App\Enums\AiFeature;
use App\Models\IntegrationSecret;
use App\Services\Ai\AiException;
use App\Services\Ai\ClaudeTextAssistant;
use App\Services\Ai\TextTask;
use App\Support\Ai\AiContext;
use App\Support\Secrets;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;

function nameTask(AiFeature $feature = AiFeature::TextGenerate, ?string $from = null, string $source = ''): TextTask
{
    return new TextTask($feature, 'product.meta_title', 'Write the catalog name.', new AiContext(name: 'Zebra ZD421', brand: 'Zebra', specs: '- Resolution: 203 dpi'), $from, $source);
}

/**
 * A Messages API answer with native structured output.
 *
 * @param  array<string, string>  $texts
 * @return array<string, mixed>
 */
function anthropicMessage(array $texts, int $input = 1200, int $output = 400): array
{
    return [
        'id' => 'msg_test',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-opus-5-5',
        'content' => [['type' => 'text', 'text' => json_encode($texts, JSON_UNESCAPED_UNICODE)]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => $input, 'output_tokens' => $output],
    ];
}

function assistantError(callable $callback): AiErrorCode
{
    try {
        $callback();
    } catch (AiException $exception) {
        return $exception->errorCode;
    }

    test()->fail('Expected an AiException.');
}

beforeEach(fn () => enableAiAssist());

it('writes the key from the Secrets resolver into the laravel/ai provider config', function () {
    expect(config('ai.providers.anthropic.key'))->toBe(fakeAnthropicKey())
        ->and(config('ai.default'))->toBe('anthropic');
});

it('generates uz, ru and en with structured output through laravel/ai', function () {
    FieldWriter::fake([['uz' => 'Yorliq printeri', 'ru' => 'Принтер этикеток', 'en' => 'Label printer']])->preventStrayPrompts();
    Translator::fake()->preventStrayPrompts();

    $result = app(ClaudeTextAssistant::class)->generate(nameTask(), 'claude-opus-5-5');

    expect($result->texts)->toBe(['uz' => 'Yorliq printeri', 'ru' => 'Принтер этикеток', 'en' => 'Label printer'])
        ->and($result->model)->toBe('claude-opus-5-5');

    FieldWriter::assertPrompted(fn (AgentPrompt $prompt): bool => str_starts_with($prompt->prompt, 'Write the catalog name.')
        && str_contains($prompt->prompt, "<context>\nName: Zebra ZD421")
        && str_contains($prompt->prompt, '- Resolution: 203 dpi')
        && $prompt->model === 'claude-opus-5-5'
        && $prompt->provider->name() === 'anthropic');
    FieldWriter::assertPromptedTimes(1);
    Translator::assertNeverPrompted();
});

it('translates and keeps the source text unchanged', function () {
    Translator::fake([['uz' => 'Yorliq printeri', 'ru' => 'изменено', 'en' => 'Label printer']])->preventStrayPrompts();
    FieldWriter::fake()->preventStrayPrompts();

    $result = app(ClaudeTextAssistant::class)->translate(nameTask(AiFeature::TextTranslate, 'ru', 'Принтер этикеток'), 'claude-opus-5-5');

    expect($result->texts['ru'])->toBe('Принтер этикеток')
        ->and($result->texts['en'])->toBe('Label printer');

    Translator::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, "<source lang=\"ru\">\nПринтер этикеток\n</source>"));
    FieldWriter::assertNeverPrompted();
});

it('puts the brand rule and the Uzbek marks in the system prompt', function () {
    $instructions = (string) (new FieldWriter)->instructions();

    expect($instructions)->toContain('never on price', 'U+02BB', 'U+02BC', '<context>', 'Kokand', 'only 1C', 'yorliq', 'murojaat', 'toifa', 'SI (sunʼiy intellekt)');
});

it('refuses without a key and never prompts', function () {
    IntegrationSecret::query()->delete();
    Secrets::flush();
    FieldWriter::fake()->preventStrayPrompts();

    expect(assistantError(fn () => app(ClaudeTextAssistant::class)->generate(nameTask(), 'claude-opus-5-5')))->toBe(AiErrorCode::NotConfigured);
    FieldWriter::assertNeverPrompted();
});

it('maps provider failures to safe codes', function (Closure $throw, AiErrorCode $code) {
    FieldWriter::fake(fn () => $throw());

    expect(assistantError(fn () => app(ClaudeTextAssistant::class)->generate(nameTask(), 'claude-opus-5-5')))->toBe($code);
})->with([
    'timeout' => [fn () => throw ProviderConnectionException::forProvider('anthropic', 0, new ConnectionException('cURL error 28: Operation timed out after 45001 ms')), AiErrorCode::Timeout],
    'connection' => [fn () => throw ProviderConnectionException::forProvider('anthropic', 0, new ConnectionException('cURL error 6: Could not resolve host')), AiErrorCode::ProviderError],
    'rate limit' => [fn () => throw RateLimitedException::forProvider('anthropic'), AiErrorCode::RateLimited],
    'anything else' => [fn () => throw new RuntimeException('boom'), AiErrorCode::ProviderError],
]);

it('rejects an answer with a missing locale and keeps its billed usage', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage(['uz' => 'Yorliq printeri', 'ru' => '', 'en' => 'Label printer'], 900, 300))]);

    try {
        app(ClaudeTextAssistant::class)->generate(nameTask(), 'claude-opus-5-5');
        test()->fail('Expected an AiException.');
    } catch (AiException $exception) {
        expect($exception->errorCode)->toBe(AiErrorCode::InvalidOutput)
            ->and($exception->inputTokens)->toBe(900)
            ->and($exception->outputTokens)->toBe(300)
            ->and($exception->costMicroUsd('x'))->toBe(900 * 4 + 300 * 20);
    }
});

it('sends a plain structured-output request to the Messages API', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicMessage(['uz' => 'Yorliq printeri', 'ru' => 'Принтер этикеток', 'en' => 'Label printer']))]);

    $result = app(ClaudeTextAssistant::class)->generate(nameTask(), 'claude-opus-5-5');

    expect($result->texts['ru'])->toBe('Принтер этикеток')
        ->and($result->inputTokens)->toBe(1200)
        ->and($result->outputTokens)->toBe(400);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        expect($request->url())->toBe('https://api.anthropic.com/v1/messages')
            ->and($request->header('x-api-key'))->toBe([fakeAnthropicKey()])
            ->and($request->hasHeader('anthropic-beta'))->toBeFalse()
            ->and($body['model'])->toBe('claude-opus-5-5')
            ->and($body['max_tokens'])->toBe(8192)
            ->and($body['output_config']['format']['type'])->toBe('json_schema')
            ->and(array_keys($body['output_config']['format']['schema']['properties']))->toBe(['uz', 'ru', 'en'])
            ->and($body['messages'])->toHaveCount(1)
            ->and($body['messages'][0]['role'])->toBe('user')
            ->and($body['system'])->toContain('Brand rules');

        foreach (['temperature', 'top_p', 'top_k', 'thinking', 'tool_choice', 'tools'] as $key) {
            expect($body)->not->toHaveKey($key);
        }

        return true;
    });
});

it('reports a rejected request by HTTP status and error type only', function () {
    Exceptions::fake();
    Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Bad prompt '.fakeAnthropicKey()]], 400)]);

    expect(assistantError(fn () => app(ClaudeTextAssistant::class)->generate(nameTask(), 'claude-opus-5-5')))->toBe(AiErrorCode::ProviderError);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Anthropic rejected an AI request: HTTP 400, invalid_request_error.');
    Exceptions::assertNotReported(fn (Throwable $e): bool => str_contains($e->getMessage(), 'Bad prompt'));
});
