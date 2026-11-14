<?php

namespace App\Services\Ai;

/**
 * One request to the chat model (plan 30 §5): system blocks, stable first
 * (a block with cache = true ends a prompt cache breakpoint), the alternating
 * user/assistant messages (always ending with the user), and the output
 * token limit. Never carries a person's identity (AI-6).
 */
final readonly class AiRequest
{
    /**
     * @param  list<array{text: string, cache: bool}>  $system
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     */
    public function __construct(
        public array $system,
        public array $messages,
        public int $maxTokens,
    ) {}

    /**
     * A rough input size in tokens (3 characters a token, rounded up), for
     * the daily token reservation (AI-18).
     */
    public function estimatedInputTokens(): int
    {
        $chars = 0;

        foreach ($this->system as $block) {
            $chars += mb_strlen($block['text']);
        }

        foreach ($this->messages as $message) {
            $chars += mb_strlen($message['content']);
        }

        return (int) ceil($chars / 3);
    }

    /**
     * The tokens this request is expected to bill against the daily cap (AI-18,
     * plan 30 code review 4): cached system blocks at a tenth (a cache read;
     * the prefix is written in full only once per 5-minute window), the rest
     * in full, plus the whole output limit. 3 characters a token, rounded up.
     */
    public function estimatedBilledTokens(): int
    {
        $cached = 0;
        $fresh = 0;

        foreach ($this->system as $block) {
            if ($block['cache']) {
                $cached += mb_strlen($block['text']);
            } else {
                $fresh += mb_strlen($block['text']);
            }
        }

        foreach ($this->messages as $message) {
            $fresh += mb_strlen($message['content']);
        }

        return (int) ceil($fresh / 3) + (int) ceil($cached / 30) + $this->maxTokens;
    }

    /**
     * Every text of the request in one string (tests scan it for personal data).
     */
    public function allText(): string
    {
        return implode("\n", [...array_column($this->system, 'text'), ...array_column($this->messages, 'content')]);
    }
}
