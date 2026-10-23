<?php

namespace App\Enums;

/**
 * External AI service of a request (ai_requests.provider). Anthropic (Claude)
 * writes texts; OpenAI and remove.bg arrive with the phase 2 image features.
 */
enum AiProvider: string
{
    case Anthropic = 'anthropic';
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
            self::OpenAi => 'openai.api_key',
            self::RemoveBg => 'removebg.api_key',
        };
    }
}
