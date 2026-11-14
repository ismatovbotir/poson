<?php

use App\Services\Ai\AiFailed;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResult;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\AnthropicChatModel;
use App\Services\Ai\ChatModel;
use App\Support\Chat\ChatRedactor;
use App\Support\Monitoring\Redactor;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\Support\FakeChatModel;

/**
 * Plan 30 AI-1, AI-3, AI-5, AI-25: the Anthropic adapter, only through the
 * SDK middleware option with canned PSR-7 responses (never the network).
 * Fake keys only.
 */
const AI_FAKE_KEY = 'sk-ant-test-FAKEKEY-0123456789abcdefghij';

/**
 * @param  callable(RequestInterface): Response  $respond
 * @param  array<string, mixed>|null  $seen  The request body, headers and options the middleware saw
 */
function aiModel(callable $respond, ?array &$seen = null): AnthropicChatModel
{
    return new AnthropicChatModel([function (RequestInterface $request, callable $next, $options = null) use ($respond, &$seen): Response {
        $seen = [
            'url' => (string) $request->getUri(),
            'body' => json_decode((string) $request->getBody(), true),
            'timeout' => $options?->timeout,
            'max_retries' => $options?->maxRetries,
            'transporter' => $options?->transporter,
        ];

        return $respond($request);
    }]);
}

function aiRequest(): AiRequest
{
    return new AiRequest(
        system: [
            ['text' => 'Brand block', 'cache' => true],
            ['text' => '<knowledge>Facts</knowledge>', 'cache' => true],
            ['text' => '<context>Now</context>', 'cache' => false],
        ],
        messages: [['role' => 'user', 'content' => "<customer_message>\nQaysi printer?\n</customer_message>"]],
        maxTokens: 400,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function aiMessageResponse(array $overrides = []): Response
{
    return new Response(200, ['content-type' => 'application/json'], json_encode(array_merge([
        'id' => 'msg_test',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-haiku-5-5',
        'content' => [['type' => 'text', 'text' => 'Poson printer tanlashda yordam beradi.']],
        'stop_reason' => 'end_turn',
        'stop_sequence' => null,
        'usage' => ['input_tokens' => 1200, 'output_tokens' => 45, 'cache_read_input_tokens' => 3000, 'cache_creation_input_tokens' => 10],
    ], $overrides)));
}

function aiErrorResponse(int $status, string $type): Response
{
    // The body echoes the key, as a hostile or broken upstream might.
    return new Response($status, ['content-type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => 'invalid x-api-key '.AI_FAKE_KEY]]));
}

beforeEach(function () {
    config(['services.anthropic.key' => AI_FAKE_KEY]);
});

it('keeps the real key out of tests and binds the fake model', function () {
    config(['services.anthropic.key' => env('ANTHROPIC_API_KEY')]);

    expect((string) env('ANTHROPIC_API_KEY'))->toBe('')
        ->and(app(ChatModel::class))->toBeInstanceOf(FakeChatModel::class);
});

it('refuses to build a client without test middleware, even with a key', function () {
    expect(fn () => (new AnthropicChatModel)->complete(aiRequest()))->toThrow(LogicException::class);
});

it('throws AiUnavailable without any request when the key is empty', function () {
    config(['services.anthropic.key' => '']);
    $called = false;

    expect(fn () => aiModel(function () use (&$called): Response {
        $called = true;

        return aiMessageResponse();
    })->complete(aiRequest()))->toThrow(AiUnavailable::class)
        ->and($called)->toBeFalse();
});

it('maps a 200 answer with usage', function () {
    $result = aiModel(fn () => aiMessageResponse())->complete(aiRequest());

    expect($result)->toBeInstanceOf(AiResult::class)
        ->and($result->text)->toBe('Poson printer tanlashda yordam beradi.')
        ->and($result->stopReason)->toBe('end_turn')
        ->and($result->inputTokens)->toBe(1200)
        ->and($result->outputTokens)->toBe(45)
        ->and($result->cacheReadTokens)->toBe(3000)
        ->and($result->cacheWriteTokens)->toBe(10)
        ->and($result->model)->toBe('claude-haiku-5-5')
        ->and($result->billedTokens())->toBe(1200 + 45 + 10 + 300);
});

it('sends the exact payload: model, cache breakpoints on the first two blocks, no sampling params, no prefill', function () {
    aiModel(fn () => aiMessageResponse(), $seen)->complete(aiRequest());

    $body = $seen['body'];

    expect($seen['url'])->toBe('https://api.anthropic.com/v1/messages')
        ->and($body['model'])->toBe('claude-haiku-5-5')
        ->and($body['max_tokens'])->toBe(400)
        ->and($body['output_config'])->toBe(['effort' => 'low'])
        ->and($body['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['system'][1]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['system'][2])->not->toHaveKey('cache_control')
        ->and($body)->not->toHaveKeys(['temperature', 'top_p', 'top_k', 'tools', 'metadata', 'thinking'])
        ->and(end($body['messages'])['role'])->toBe('user')
        ->and(collect($body['messages'])->where('role', 'assistant'))->toBeEmpty();
});

it('passes the timeouts and no retries to the transport', function () {
    aiModel(fn () => aiMessageResponse(), $seen)->complete(aiRequest());

    expect($seen['timeout'])->toBe(12.0)
        ->and($seen['max_retries'])->toBe(0)
        ->and($seen['transporter'])->toBeInstanceOf(Client::class)
        ->and(@$seen['transporter']->getConfig('timeout'))->toEqual(12)
        ->and(@$seen['transporter']->getConfig('connect_timeout'))->toEqual(3)
        ->and(@$seen['transporter']->getConfig('allow_redirects'))->toBeFalse();
});

it('maps refusal and max_tokens stop reasons', function (string $stopReason, string $method) {
    $result = aiModel(fn () => aiMessageResponse(['stop_reason' => $stopReason]))->complete(aiRequest());

    expect($result->stopReason)->toBe($stopReason)
        ->and($result->{$method}())->toBeTrue();
})->with([
    'refusal' => ['refusal', 'isRefusal'],
    'max tokens' => ['max_tokens', 'isTruncated'],
]);

it('maps HTTP errors to AiFailed with class and status only, never the key', function (int $status, string $type, string $class) {
    try {
        aiModel(fn () => aiErrorResponse($status, $type))->complete(aiRequest());
        $this->fail('No exception.');
    } catch (AiFailed $failure) {
        expect($failure->status)->toBe($status)
            ->and($failure->errorClass)->toBe($class)
            ->and($failure->getPrevious())->toBeNull()
            ->and($failure->getMessage())->not->toContain(AI_FAKE_KEY)
            ->and((string) $failure)->not->toContain(AI_FAKE_KEY)
            ->and(Redactor::clean((string) $failure, 5000, false))->not->toContain('sk-ant');
    }
})->with([
    '401' => [401, 'authentication_error', 'AuthenticationException'],
    '429' => [429, 'rate_limit_error', 'RateLimitException'],
    '500' => [500, 'api_error', 'InternalServerException'],
]);

it('maps a timeout to AiFailed without a status', function () {
    try {
        aiModel(fn (RequestInterface $request) => throw new ConnectException('Operation timed out after 12001 milliseconds', $request))->complete(aiRequest());
        $this->fail('No exception.');
    } catch (AiFailed $failure) {
        expect($failure->status)->toBeNull()
            ->and($failure->getPrevious())->toBeNull()
            ->and($failure->errorClass)->toContain('Exception');
    }
});

it('masks Anthropic keys in both redactors', function () {
    expect(Redactor::clean('failed with sk-ant-api03-abcDEF_123-xyz'))->not->toContain('sk-ant')
        ->and(ChatRedactor::clean('kalit sk-ant-api03-abcDEF_123 bor'))->not->toContain('sk-ant');
});
