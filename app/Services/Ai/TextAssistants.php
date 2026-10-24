<?php

namespace App\Services\Ai;

use App\Enums\AiErrorCode;
use App\Enums\AiProvider;

/**
 * TextAssistant per provider, resolved from the container (plan 10).
 * AppServiceProvider binds binding(Anthropic) to ClaudeTextAssistant and
 * binding(OpenRouter) to OpenRouterTextAssistant; tests bind fakes under
 * the same keys.
 */
final class TextAssistants
{
    /**
     * Container key of the provider's TextAssistant.
     */
    public static function binding(AiProvider $provider): string
    {
        return 'ai.text-assistant.'.$provider->value;
    }

    /**
     * @throws AiException not_configured for a provider that doesn't write texts
     */
    public static function for(AiProvider $provider): TextAssistant
    {
        if (! in_array($provider, AiProvider::textProviders(), true) || ! app()->bound(self::binding($provider))) {
            throw AiException::for(AiErrorCode::NotConfigured);
        }

        return app(self::binding($provider));
    }
}
