<?php

namespace Tests\Support;

use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResult;
use App\Services\Ai\ChatModel;
use Closure;

/**
 * A chat model that runs a callback in the middle of the "call" (a cancel, a
 * permission change or a competing request while the model is thinking) and
 * then answers like FakeChatModel. Never a network call (plan 30 AI-25).
 */
final class CallbackChatModel implements ChatModel
{
    /** @var list<AiRequest> */
    public array $requests = [];

    /**
     * @param  Closure(AiRequest): void  $during
     */
    public function __construct(private readonly Closure $during, private readonly string $answer) {}

    public function complete(AiRequest $request): AiResult
    {
        $this->requests[] = $request;
        ($this->during)($request);

        return FakeChatModel::answer($this->answer);
    }
}
