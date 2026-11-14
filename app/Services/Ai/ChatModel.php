<?php

namespace App\Services\Ai;

/**
 * The language model behind AI answers in the chat bot (plan 30 §4). Chat
 * code depends on this interface only (AI-4); AnthropicChatModel is the one
 * implementation, tests bind a fake. One call = one HTTP request, no
 * retries, no tools, no streaming.
 */
interface ChatModel
{
    /**
     * @throws AiUnavailable when no API key is configured (no request is made)
     * @throws AiFailed on any error (timeout, connection, HTTP status, bad response)
     */
    public function complete(AiRequest $request): AiResult;
}
