<?php

namespace App\Services\Ai;

/**
 * One request to the chat model (plan 30 §5): system blocks, stable first
 * (a block with cache = true ends a prompt cache breakpoint), the alternating
 * user/assistant messages (always ending with the user), and the output
 * token limit. Never carries a person's identity (AI-6).
 *
 * Plan 32 (PW-5) optional overrides, all null/empty for the chat bot (its
 * request stays byte-identical): model (AnthropicChatModel::MODELS only),
 * effort (low/medium), timeout in seconds (clamped by the adapter), images
 * (JPEG files under storage/app/private/ai-drafts, attached base64 to the
 * last user message) and an output JSON schema (output_config.format).
 */
final readonly class AiRequest
{
    /**
     * @param  list<array{text: string, cache: bool}>  $system
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @param  list<array{mediaType: string, path: string}>  $images
     * @param  array<string, mixed>|null  $outputSchema
     */
    public function __construct(
        public array $system,
        public array $messages,
        public int $maxTokens,
        public ?string $model = null,
        public ?string $effort = null,
        public ?float $timeout = null,
        public array $images = [],
        public ?array $outputSchema = null,
    ) {}

    /**
     * Rough input tokens of one attached image (vision copies are at most ~1.15 MP).
     */
    public const IMAGE_TOKENS = 1600;

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

        return (int) ceil($chars / 3) + count($this->images) * self::IMAGE_TOKENS;
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

        return (int) ceil($fresh / 3) + (int) ceil($cached / 30) + count($this->images) * self::IMAGE_TOKENS + $this->maxTokens;
    }

    /**
     * Every text of the request in one string (tests scan it for personal data).
     * Images are not text: they are left out.
     */
    public function allText(): string
    {
        return implode("\n", [...array_column($this->system, 'text'), ...array_column($this->messages, 'content')]);
    }
}
