<?php

namespace App\Enums;

/**
 * Layout of a static page (plan 07 K5): default (text + optional cover),
 * contacts (company contacts, the click-to-load map and the lead form) and
 * legal ("Updated" date, no SI assist on the body).
 */
enum PageTemplate: string
{
    case Default = 'default';
    case Contacts = 'contacts';
    case Legal = 'legal';

    public function label(): string
    {
        return __("enums.page_template.{$this->value}");
    }

    /**
     * schema.org type of the page's WebPage block (about → AboutPage is decided per slug).
     */
    public function schemaType(): string
    {
        return match ($this) {
            self::Contacts => 'ContactPage',
            default => 'WebPage',
        };
    }

    /**
     * Whether the SI assist may write the body (never for legal texts).
     */
    public function allowsAiBody(): bool
    {
        return $this !== self::Legal;
    }
}
