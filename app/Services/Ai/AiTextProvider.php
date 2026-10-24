<?php

namespace App\Services\Ai;

use App\Enums\AiProvider;
use App\Support\Secrets;

/**
 * The text provider chosen in Settings → Integrations (ai.text_provider,
 * plan 10): Anthropic (default) or OpenRouter, with its model and key
 * status. New requests store the provider and model on their ai_requests
 * row; ProcessAiRequest runs them on those, so switching the provider never
 * affects requests already queued.
 */
final class AiTextProvider
{
    public static function current(): AiProvider
    {
        $provider = AiProvider::tryFrom((string) config('ai-assist.text_provider'));

        return $provider !== null && in_array($provider, AiProvider::textProviders(), true) ? $provider : AiProvider::Anthropic;
    }

    /**
     * Model of new text requests for the provider (default: the current one).
     */
    public static function model(?AiProvider $provider = null): string
    {
        return match ($provider ?? self::current()) {
            AiProvider::OpenRouter => OpenRouterTextAssistant::model(),
            default => ClaudeTextAssistant::model(),
        };
    }

    /**
     * The provider's API key is set (from .env or admin).
     */
    public static function isConfigured(?AiProvider $provider = null): bool
    {
        return Secrets::isConfigured(($provider ?? self::current())->keyField());
    }
}
