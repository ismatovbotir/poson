<?php

namespace App\Data\Leads;

use App\Enums\LeadSource;
use App\Enums\LeadType;
use App\Enums\TrafficChannel;

/**
 * A lead submitted from the public site. The phone is already normalized to
 * +998XXXXXXXXX. A quote request from a product page carries the product
 * (id and SKU re-read from the database, never taken from the browser).
 * trafficChannel / trafficSource / landingPath are the session's first-touch
 * attribution (plan 04 4c), for the CRM's lead source reports.
 * A label product (plan 08) also carries the chosen roll type and rolls.
 * A cart quote request (plan 11a, type checkout) carries several items
 * instead of one product (ConvertCartToLead).
 */
final readonly class SiteLeadData
{
    /**
     * @param  array<string, string>  $utm  utm_source, utm_medium, utm_campaign, utm_term, utm_content
     * @param  list<LeadItemData>  $items  Items of a cart quote request (used when productId is null)
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
        public ?TrafficChannel $trafficChannel = null,
        public ?string $trafficSource = null,
        public ?string $landingPath = null,
        public ?int $rollTypeId = null,
        public ?int $rolls = null,
        public array $items = [],
    ) {}
}
