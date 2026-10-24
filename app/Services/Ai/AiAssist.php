<?php

namespace App\Services\Ai;

use App\Enums\AiErrorCode;
use App\Models\AiRequest;
use App\Models\User;

/**
 * Whether the admin AI assist can be used (plan 02): switched on in
 * Settings → Integrations (off by default), the key of the current text
 * provider (Anthropic or OpenRouter, plan 10) set, and the user allowed
 * (ai.use). The AI buttons are hidden otherwise.
 */
final class AiAssist
{
    public static function isEnabled(): bool
    {
        return config('ai-assist.enabled') === true;
    }

    public static function isConfigured(): bool
    {
        return AiTextProvider::isConfigured();
    }

    /**
     * Show the AI buttons and the assistant panel to this user.
     */
    public static function availableFor(?User $user): bool
    {
        return $user !== null && self::isEnabled() && self::isConfigured() && $user->can('create', AiRequest::class);
    }

    /**
     * @throws AiException disabled / not_configured
     */
    public static function ensureUsable(): void
    {
        if (! self::isEnabled()) {
            throw AiException::for(AiErrorCode::Disabled);
        }

        if (! self::isConfigured()) {
            throw AiException::for(AiErrorCode::NotConfigured);
        }
    }
}
