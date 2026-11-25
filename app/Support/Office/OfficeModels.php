<?php

namespace App\Support\Office;

use App\Services\Ai\AnthropicChatModel;

/**
 * The model ids a Virtual office agent may use (plan 41, VO-10): exactly
 * AnthropicChatModel::MODELS, exposed here so admin screens never depend on
 * the AI service classes (arch tests).
 */
final class OfficeModels
{
    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        return AnthropicChatModel::MODELS;
    }
}
