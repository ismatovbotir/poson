<?php

namespace App\Ai\Agents;

use App\Services\Ai\SystemPrompt;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * laravel/ai agent that answers with one text per locale ({uz, ru, en},
 * structured output). Instructions = the shared SystemPrompt. Only
 * ClaudeTextAssistant uses these agents.
 */
abstract class LocalizedTextAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return SystemPrompt::text();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $fields = [];

        foreach (config('app.supported_locales') as $locale) {
            $fields[$locale] = $schema->string()->required();
        }

        return $fields;
    }

    public function maxTokens(): int
    {
        return (int) config('ai-assist.max_output_tokens', 4096);
    }
}
