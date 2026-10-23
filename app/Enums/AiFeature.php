<?php

namespace App\Enums;

/**
 * What an AI request does (ai_requests.feature, plan 02). MVP: text only.
 * Phase 2 adds image alt / caption, background removal and image
 * generation (each with its own quota kind, see isText()).
 */
enum AiFeature: string
{
    case TextGenerate = 'text_generate';
    case TextTranslate = 'text_translate';

    public function label(): string
    {
        return __("enums.ai_feature.{$this->value}");
    }

    /**
     * Counts towards the daily text quota.
     */
    public function isText(): bool
    {
        return true;
    }

    /**
     * @return list<self>
     */
    public static function text(): array
    {
        return array_values(array_filter(self::cases(), fn (self $feature): bool => $feature->isText()));
    }
}
