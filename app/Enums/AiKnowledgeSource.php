<?php

namespace App\Enums;

/**
 * Where a chunk of the AI website knowledge comes from (plan 33 §4). Services
 * and software are products with the matching type. Faq is a category or
 * page FAQ item (plan 33 A2-3): retrieved like the others but never a link
 * or a button of its own (linkable()).
 */
enum AiKnowledgeSource: string
{
    case Page = 'page';
    case News = 'news';
    case Article = 'article';
    case Brand = 'brand';
    case Service = 'service';
    case Software = 'software';
    case Faq = 'faq';

    public function label(): string
    {
        return __("enums.ai_knowledge_source.{$this->value}");
    }

    /**
     * Retrieval order weight (plan 33 §3): pages and services first, news last.
     */
    public function priority(): int
    {
        return match ($this) {
            self::Page, self::Service, self::Software, self::Faq => 3,
            self::Article, self::Brand => 2,
            self::News => 1,
        };
    }

    /**
     * Whether a cited hit of this source gets an "Open: …" button and its URL
     * may be repeated (K-5, K-6): every source but a FAQ item (A2-S11).
     */
    public function linkable(): bool
    {
        return match ($this) {
            self::Faq => false,
            self::Page, self::News, self::Article, self::Brand, self::Service, self::Software => true,
        };
    }

    /**
     * The product type of a service or software source.
     */
    public function productType(): ?ProductType
    {
        return match ($this) {
            self::Service => ProductType::Service,
            self::Software => ProductType::Software,
            default => null,
        };
    }

    /**
     * The news kind of a news or article source.
     */
    public function newsKind(): ?NewsKind
    {
        return match ($this) {
            self::News => NewsKind::News,
            self::Article => NewsKind::Article,
            default => null,
        };
    }
}
