<?php

use App\Enums\AiErrorCode;
use App\Enums\AiFeature;
use App\Models\IntegrationSecret;
use App\Services\Ai\AiException;
use App\Services\Ai\OpenRouterTextAssistant;
use App\Services\Ai\TextResult;
use App\Services\Ai\TextTask;
use App\Support\Ai\AiContext;
use App\Support\Secrets;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

const OR_FREE_MODEL = 'nvidia/nemotron-3.5-super-vl:free';

const OR_PAID_MODEL = 'qwen/qwen3.8-flash';

function orTask(AiFeature $feature = AiFeature::TextGenerate, ?string $from = null, string $source = ''): TextTask
{
    return new TextTask($feature, 'product.name', 'Write the catalog name.', new AiContext(name: 'Zebra ZD421', brand: 'Zebra'), $from, $source);
}

/**
 * A chat completion answer.
 *
 * @param  array<string, mixed>  $usage
 * @return array<string, mixed>
 */
function orCompletion(string $content, array $usage = ['prompt_tokens' => 1500, 'completion_tokens' => 300], string $finish = 'stop'): array
{
    return [
        'id' => 'gen-test',
        'model' => OR_PAID_MODEL,
        'choices' => [['index' => 0, 'finish_reason' => $finish, 'message' => ['role' => 'assistant', 'content' => $content]]],
        'usage' => $usage,
    ];
}

function orTexts(): string
{
    return json_encode(['uz' => 'Yorliq printeri Zebra ZD421', 'ru' => 'Принтер этикеток Zebra ZD421', 'en' => 'Label printer Zebra ZD421'], JSON_UNESCAPED_UNICODE);
}

function orRun(string $model = OR_PAID_MODEL, ?TextTask $task = null): TextResult
{
    return app(OpenRouterTextAssistant::class)->generate($task ?? orTask(), $model);
}

function orError(callable $callback): AiException
{
    try {
        $callback();
    } catch (AiException $exception) {
        return $exception;
    }

    test()->fail('Expected an AiException.');
}

beforeEach(function () {
    Http::preventStrayRequests();
    enableOpenRouter();
});

it('writes uz, ru and en and stores the exact usage cost', function () {
    Http::fake(['openrouter.ai/*' => Http::response(orCompletion(orTexts(), ['prompt_tokens' => 1500, 'completion_tokens' => 300, 'cost' => 0.000366]))]);

    $result = orRun();

    expect($result->texts['ru'])->toBe('Принтер этикеток Zebra ZD421')
        ->and($result->model)->toBe(OR_PAID_MODEL)
        ->and($result->inputTokens)->toBe(1500)
        ->and($result->outputTokens)->toBe(300)
        ->and($result->costMicroUsd)->toBe(366)
        ->and($result->costMicroUsd())->toBe(366);
});

it('costs a free model without usage.cost at 0', function () {
    Http::fake(['openrouter.ai/*' => Http::response(orCompletion(orTexts()))]);

    $result = orRun(OR_FREE_MODEL);

    expect($result->costMicroUsd)->toBeNull()
        ->and($result->costMicroUsd())->toBe(0);
});

it('costs a paid model without usage.cost at its configured rate', function () {
    Http::fake(['openrouter.ai/*' => Http::response(orCompletion(orTexts()))]);

    // qwen/qwen3.8-flash: 0.15 / 0.47 USD per million tokens (the ID contains a dot).
    expect(orRun()->costMicroUsd())->toBe((int) round(1500 * 0.15 + 300 * 0.47));
});

it('never stores less than the token-based cost for a paid model', function () {
    // Reported 1 micro-USD, tokens at the qwen rate cost 366.
    Http::fake(['openrouter.ai/*' => Http::response(orCompletion(orTexts(), ['prompt_tokens' => 1500, 'completion_tokens' => 300, 'cost' => 0.000001]))]);

    expect(orRun()->costMicroUsd())->toBe(366);
});

it('ignores a hostile cost and clamps hostile token counts', function (array $usage, int $input, int $output, int $cost) {
    Http::fake(['openrouter.ai/*' => Http::response(orCompletion(orTexts(), $usage))]);

    $result = orRun(OR_FREE_MODEL);

    expect($result->inputTokens)->toBe($input)
        ->and($result->outputTokens)->toBe($output)
        ->and($result->costMicroUsd())->toBe($cost);
})->with([
    'huge cost' => [['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 1e9], 10, 5, 0],
    'negative cost' => [['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => -3], 10, 5, 0],
    'string cost' => [['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 'NaN'], 10, 5, 0],
    'huge tokens' => [['prompt_tokens' => '99999999999999', 'completion_tokens' => 1e30], 4294967295, 0, 0],
    'negative tokens' => [['prompt_tokens' => -50, 'completion_tokens' => '-7'], 0, 0, 0],
    'array tokens' => [['prompt_tokens' => [1], 'completion_tokens' => 1.5], 0, 0, 0],
]);

it('treats a malformed answer as provider_error and keeps the billed usage', function (mixed $choices) {
    Exceptions::fake();
    Http::fake(['openrouter.ai/*' => Http::response(['choices' => $choices, 'usage' => ['prompt_tokens' => 700, 'completion_tokens' => 50, 'cost' => 0.0001]])]);

    $exception = orError(fn () => orRun());

    expect($exception->errorCode)->toBe(AiErrorCode::ProviderError)
        ->and($exception->inputTokens)->toBe(700)
        ->and($exception->costMicroUsd('x'))->toBe(max(100, (int) round(700 * 0.15 + 50 * 0.47)));
})->with([
    'choices is a string' => ['nope'],
    'choices is an object' => [['first' => ['message' => ['content' => '{}']]]],
    'content is an array' => [[['finish_reason' => 'stop', 'message' => ['content' => [['type' => 'text', 'text' => '{}']]]]]],
    'content is missing' => [[['finish_reason' => 'stop', 'message' => []]]],
]);

it('reads JSON wrapped in code fences and surrounding text', function () {
    Http::fake(['openrouter.ai/*' => Http::response(orCompletion("Here you go:\n```json\n".orTexts()."\n```"))]);

    expect(orRun()->texts['uz'])->toBe('Yorliq printeri Zebra ZD421');
});

it('keeps the source text unchanged when translating', function () {
    Http::fake(['openrouter.ai/*' => Http::response(orCompletion(json_encode(['uz' => 'Yorliq printeri', 'ru' => 'изменено', 'en' => 'Label printer'], JSON_UNESCAPED_UNICODE)))]);

    $result = app(OpenRouterTextAssistant::class)->translate(orTask(AiFeature::TextTranslate, 'ru', 'Принтер этикеток'), OR_PAID_MODEL);

    expect($result->texts['ru'])->toBe('Принтер этикеток');
    Http::assertSent(fn (Request $request): bool => str_contains($request->data()['messages'][1]['content'], "<source lang=\"ru\">\nПринтер этикеток\n</source>"));
});

it('rejects invalid JSON or a missing locale as invalid output and keeps the billed usage', function (string $content) {
    Http::fake(['openrouter.ai/*' => Http::response(orCompletion($content, ['prompt_tokens' => 900, 'completion_tokens' => 100, 'cost' => 0.0002]))]);

    $exception = orError(fn () => orRun());

    expect($exception->errorCode)->toBe(AiErrorCode::InvalidOutput)
        ->and($exception->errorCode->isRetryable())->toBeTrue()
        ->and($exception->inputTokens)->toBe(900)
        ->and($exception->costMicroUsd('x'))->toBe(200);
})->with([
    'not JSON' => ['Sorry, I cannot help with that.'],
    'broken JSON' => ['{"uz": "Yorliq", "ru": '],
    'missing locale' => [json_encode(['uz' => 'Yorliq printeri', 'ru' => '', 'en' => 'Label printer'])],
]);

it('treats a cut-off answer as invalid output', function () {
    Http::fake(['openrouter.ai/*' => Http::response(orCompletion(orTexts(), finish: 'length'))]);

    expect(orError(fn () => orRun())->errorCode)->toBe(AiErrorCode::InvalidOutput);
});

it('maps HTTP failures to safe codes and never reports the body', function (int $status, array $body, AiErrorCode $code, bool $retryable) {
    Exceptions::fake();
    Http::fake(['openrouter.ai/*' => Http::response(['error' => ['code' => $status, 'message' => 'Bad request '.fakeOpenRouterKey(), ...$body]], $status)]);

    $exception = orError(fn () => orRun());

    expect($exception->errorCode)->toBe($code)
        ->and($exception->errorCode->isRetryable())->toBe($retryable)
        ->and($exception->getMessage())->not->toContain('Bad request');

    Exceptions::assertNotReported(fn (Throwable $e): bool => str_contains($e->getMessage(), 'Bad request') || str_contains($e->getMessage(), fakeOpenRouterKey()));
})->with([
    '401' => [401, [], AiErrorCode::InvalidKey, false],
    '403 moderation' => [403, ['message' => 'Input flagged by moderation'], AiErrorCode::Refused, false],
    '402' => [402, [], AiErrorCode::NoCredits, false],
    '404' => [404, [], AiErrorCode::ModelUnavailable, false],
    '400 no endpoints' => [400, ['message' => 'No endpoints found for nvidia/x:free.'], AiErrorCode::ModelUnavailable, false],
    '400 other' => [400, ['message' => 'Invalid parameter'], AiErrorCode::BadRequest, false],
    '422' => [422, [], AiErrorCode::BadRequest, false],
    '408' => [408, [], AiErrorCode::Timeout, true],
    '429' => [429, [], AiErrorCode::RateLimited, true],
    '500' => [500, [], AiErrorCode::ProviderError, true],
    '502' => [502, [], AiErrorCode::ProviderError, true],
]);

it('reports a provider error by HTTP status and code only', function () {
    Exceptions::fake();
    Http::fake(['openrouter.ai/*' => Http::response(['error' => ['code' => 500, 'message' => 'Upstream said '.fakeOpenRouterKey()]], 500)]);

    orError(fn () => orRun());

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'OpenRouter rejected an AI request: HTTP 500, 500.');
});

it('maps a timeout to timeout and other connection failures to provider_error', function (string $message, AiErrorCode $code) {
    Http::fake(['openrouter.ai/*' => Http::failedConnection($message)]);

    expect(orError(fn () => orRun())->errorCode)->toBe($code);
})->with([
    'timeout' => ['cURL error 28: Operation timed out after 45001 milliseconds', AiErrorCode::Timeout],
    'dns' => ['cURL error 6: Could not resolve host', AiErrorCode::ProviderError],
]);

it('treats an error on a 200 or an empty choice list as provider_error', function (array $body) {
    Exceptions::fake();
    Http::fake(['openrouter.ai/*' => Http::response($body)]);

    expect(orError(fn () => orRun())->errorCode)->toBe(AiErrorCode::ProviderError);
})->with([
    'error key' => [['error' => ['code' => 502, 'message' => 'Provider returned error']]],
    'choice error' => [['choices' => [['error' => ['code' => 502, 'message' => 'x'], 'finish_reason' => 'error', 'message' => ['content' => '']]]]],
    'no choices' => [['choices' => []]],
]);

it('sends the key, attribution headers, model, schema and usage flag', function () {
    config(['app.url' => 'https://poson.uz']);
    Http::fake(['openrouter.ai/*' => Http::response(orCompletion(orTexts()))]);

    orRun(OR_FREE_MODEL);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        expect($request->url())->toBe('https://openrouter.ai/api/v1/chat/completions')
            ->and($request->method())->toBe('POST')
            ->and($request->header('Authorization'))->toBe(['Bearer '.fakeOpenRouterKey()])
            ->and($request->header('HTTP-Referer'))->toBe(['https://poson.uz'])
            ->and($request->header('X-Title'))->toBe(['Poson'])
            ->and($body['model'])->toBe(OR_FREE_MODEL)
            ->and($body['max_tokens'])->toBe(8192)
            ->and($body['usage'])->toBe(['include' => true])
            ->and($body['response_format']['type'])->toBe('json_schema')
            ->and($body['response_format']['json_schema']['strict'])->toBeFalse()
            ->and($body['response_format']['json_schema']['schema']['required'])->toBe(['uz', 'ru', 'en'])
            ->and($body['messages'][0]['role'])->toBe('system')
            ->and($body['messages'][0]['content'])->toContain('Brand rules', 'U+02BB')
            ->and($body['messages'][1]['content'])->toStartWith('Write the catalog name.')
            ->and($body['messages'][1]['content'])->toContain('Reply only with a JSON object')
            ->and($body)->not->toHaveKey('provider')
            ->and($body)->not->toHaveKey('require_parameters');

        return true;
    });
});

it('asks OpenRouter to deny data collection when logging providers are not allowed', function () {
    enableOpenRouter(['ai.openrouter_allow_data_collection' => false]);
    Http::fake(['openrouter.ai/*' => Http::response(orCompletion(orTexts()))]);

    orRun();

    Http::assertSent(fn (Request $request): bool => ($request->data()['provider'] ?? null) === ['data_collection' => 'deny']);
});

it('refuses an invalid configured model instead of falling back to the free one', function () {
    Exceptions::fake();
    // A .env value overlaid after the admin settings (enableOpenRouter resets the env overlay).
    freshSecrets(['ai-assist.openrouter_model' => 'not a model']);

    $exception = orError(fn () => OpenRouterTextAssistant::model());

    expect($exception->errorCode)->toBe(AiErrorCode::NotConfigured);
    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), '[ai.openrouter_model] has an invalid value') && ! str_contains($e->getMessage(), 'not a model'));
});

it('accepts a configured model ID in any case', function () {
    config(['ai-assist.openrouter_model' => ' OpenAI/GPT-6-Luna ']);

    expect(OpenRouterTextAssistant::model())->toBe('openai/gpt-6-luna');
});

it('refuses a configured model ID longer than 80 characters', function () {
    Exceptions::fake();
    config(['ai-assist.openrouter_model' => 'vendor/'.str_repeat('a', 80)]);

    expect(orError(fn () => OpenRouterTextAssistant::model())->errorCode)->toBe(AiErrorCode::NotConfigured);
});

it('does not let a trailing newline pass the model pattern', function () {
    expect(OpenRouterTextAssistant::isValidModel("openai/gpt-6-luna\n"))->toBeFalse()
        ->and(OpenRouterTextAssistant::isValidModel('openai/gpt-6-luna'))->toBeTrue();
});

it('refuses without a key and sends nothing', function () {
    IntegrationSecret::query()->where('key', 'openrouter.api_key')->delete();
    Secrets::flush();
    Http::fake();

    expect(orError(fn () => orRun())->errorCode)->toBe(AiErrorCode::NotConfigured);
    Http::assertNothingSent();
});
