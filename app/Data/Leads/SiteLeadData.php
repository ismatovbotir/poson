<?php

namespace App\Data\Leads;

use App\Enums\LeadSource;
use App\Enums\LeadType;

/**
 * A lead submitted from the public site. The phone is already normalized to
 * +998XXXXXXXXX. A quote request from a product page carries the product
 * (id and SKU re-read from the database, never taken from the browser).
 */
final readonly class SiteLeadData
{
    /**
     * @param  array<string, string>  $utm  utm_source, utm_medium, utm_campaign, utm_term, utm_content
     */
    public function __construct(
        public string $name,
        public string $phone,
        public ?string $company,
        public ?string $message,
        public string $locale,
        public LeadSource $source = LeadSource::Site,
        public LeadType $type = LeadType::Callback,
        public array $utm = [],
        public ?string $pageUrl = null,
        public ?int $productId = null,
        public ?string $productSku = null,
    ) {}
}
