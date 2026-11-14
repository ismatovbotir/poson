<?php

namespace Tests\Support;

use App\Services\Ai\AiFailed;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResult;
use App\Services\Ai\ChatModel;
use RuntimeException;
use Throwable;

/**
 * The chat model of every test (plan 30 AI-25): queued results or
 * exceptions, every request recorded, never a network call. Bound globally
 * in Tests\TestCase; a test that expects no call can assert calls() === 0.
 * With nothing queued, a call fails like an API error (AiFailed), so an
 * unexpected call degrades to the rule reply instead of passing silently.
 */
final class FakeChatModel implements ChatModel
{
    /** @var list<AiRequest> */
    public array $requests = [];

    /**
     * @param  list<AiResult|Throwable|string>  $responses  A string is a plain end_turn answer
     */
    public function __construct(private array $responses = []) {}

    public static function bind(AiResult|Throwable|string ...$responses): self
    {
        $fake = new self(array_values($responses));
        app()->instance(ChatModel::class, $fake);

        return $fake;
    }

    public function push(AiResult|Throwable|string $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    public function complete(AiRequest $request): AiResult
    {
        $this->requests[] = $request;
        $next = array_shift($this->responses) ?? new AiFailed('FakeChatModelEmpty');

        if ($next instanceof Throwable) {
            throw $next;
        }

        return is_string($next) ? self::answer($next) : $next;
    }

    public function calls(): int
    {
        return count($this->requests);
    }

    public function last(): AiRequest
    {
        return end($this->requests) ?: throw new RuntimeException('The fake chat model was not called.');
    }

    public static function answer(string $text, string $stopReason = AiResult::END_TURN): AiResult
    {
        return new AiResult($text, $stopReason, inputTokens: 1500, outputTokens: 60, cacheReadTokens: 1000, cacheWriteTokens: 0, model: 'claude-haiku-5-5', latencyMs: 420);
    }
}
