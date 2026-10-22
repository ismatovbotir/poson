<?php

namespace App\Enums;

/**
 * What a crawler fetches pages for (config/ai-bots.php). Used by the AI
 * visibility screen and by the per-bot robots.txt presets ("search only,
 * no training" denies Training).
 */
enum BotPurpose: string
{
    /** Collects pages to train AI models (GPTBot, ClaudeBot, CCBot…). */
    case Training = 'training';

    /** Builds the index of an AI search / answer engine (OAI-SearchBot, PerplexityBot…). */
    case AiSearch = 'ai_search';

    /** Fetches a page live because a person asked an AI assistant (ChatGPT-User, Claude-User…). */
    case AiUser = 'ai_user';

    /** Classic search engine crawler (Googlebot, Bingbot, YandexBot, Applebot). */
    case Search = 'search';

    /** Any other crawler (generic bot|crawl|spider match). */
    case Other = 'other';

    public function label(): string
    {
        return __("analytics.enums.bot_purpose.{$this->value}");
    }

    /**
     * Badge color (x-admin.badge).
     */
    public function color(): string
    {
        return match ($this) {
            self::Training => 'amber',
            self::AiSearch => 'violet',
            self::AiUser => 'brand',
            self::Search => 'sky',
            self::Other => 'muted',
        };
    }

    public function isAi(): bool
    {
        return in_array($this, [self::Training, self::AiSearch, self::AiUser], true);
    }
}
