<?php

namespace App\Actions\Leads;

use App\Data\Leads\LeadData;
use App\Data\Leads\LeadItemData;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadType;
use Illuminate\Support\Facades\Log;

/**
 * Adapter from the public site form (Site\LeadForm) to CreateLead (plan 05 C2).
 *
 * A quote request from a product page carries the product as the lead's
 * item and is always a quote, for service products too (decision
 * 2026-10-22: service-product enquiries go to sales as quotes).
 *
 * Logs only non-personal data (plan 05 C8): lead id, type, source, locale,
 * channel and product SKU; never the name, phone, company, message,
 * page URL or query-string data.
 */
class SubmitSiteLead
{
    public function __construct(protected CreateLead $createLead) {}

    /**
     * @return string Lead reference ("L-152")
     */
    public function handle(SiteLeadData $data): string
    {
        $type = $data->productId !== null ? LeadType::Quote : $data->type;

        $lead = $this->createLead->handle(new LeadData(
            source: $data->source,
            type: $type,
            name: $data->name,
            phone: $data->phone,
            locale: $data->locale,
            company: $data->company,
            message: $data->message,
            items: $data->productId !== null ? [new LeadItemData($data->productId)] : [],
            utm: $data->utm,
            pageUrl: $data->pageUrl,
            trafficChannel: $data->trafficChannel,
            trafficSource: $data->trafficSource,
            landingPath: $data->landingPath,
        ));

        Log::info('Site lead received', array_filter([
            'lead_id' => $lead->id,
            'locale' => $lead->locale,
            'source' => $lead->source->value,
            'type' => $lead->type->value,
            'channel' => $lead->traffic_channel?->value,
            'product_sku' => $data->productSku,
        ], fn (int|string|null $value): bool => $value !== null));

        return $lead->reference();
    }
}
