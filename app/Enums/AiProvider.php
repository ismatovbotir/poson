<?php

namespace App\Enums;

/**
 * External AI service of a request (ai_requests.provider). Anthropic (Claude)
 * or OpenRouter writes texts (Settings → Integrations → ai.text_provider, plan
 * 10); OpenAI and remove.bg arrive with the phase 2 image features.
 */
enum AiProvider: string
{
    case Anthropic = 'anthropic';
    case OpenRouter = 'openrouter';
    case OpenAi = 'openai';
    case RemoveBg = 'removebg';

    public function label(): string
    {
        return __("enums.ai_provider.{$this->value}");
    }

    /**
     * Integration field holding the provider's API key (IntegrationRegistry).
     */
    public function keyField(): string
    {
        return match ($this) {
            self::Anthropic => 'anthropic.api_key',
            self::OpenRouter => 'openrouter.api_key',
            self::OpenAi => 'openai.api_key',
            self::RemoveBg => 'removebg.api_key',
        };
    }

    /**
     * Providers that can write texts (the ai.text_provider options).
     *
     * @return list<self>
     */
    public static function textProviders(): array
    {
        return [self::Anthropic, self::OpenRouter];
    }
}
