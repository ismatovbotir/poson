<?php

namespace App\Actions\Leads;

use App\Data\Leads\LeadData;
use App\Data\Leads\LeadItemData;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadType;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

/**
 * Adapter from the public site form (Site\LeadForm) to CreateLead (plan 05 C2).
 *
 * A request from a product page carries the product as the lead's item;
 * its type is Product::enquiryLeadType() (service product → service
 * request, else quote; plan 36), unless the caller set ServiceRequest
 * explicitly (the public service request page), which always wins. A label
 * product (plan 08) is asked for in the chosen roll type and rolls. A
 * cart quote request (plan 11a, ConvertCartToLead) keeps its type
 * (checkout) and carries the cart's lines as items.
 *
 * Logs only non-personal data (plan 05 C8): lead id, type, source, locale,
 * channel and product SKU; never the name, phone, company, message,
 * page URL or query-string data.
 *
 * lead() returns the Lead itself (plan 28 C5: the website chat's inline lead
 * form links it to the chat); handle() delegates to it. Neither validates or
 * rate-limits: the caller's GuardsLeadSubmissions does (Site\LeadForm, the
 * chat widget), in its fixed order, before calling.
 */
class SubmitSiteLead
{
    public function __construct(protected CreateLead $createLead) {}

    /**
     * @return string Lead reference ("L-152")
     */
    public function handle(SiteLeadData $data): string
    {
        return $this->lead($data)->reference();
    }

    public function lead(SiteLeadData $data): Lead
    {
        $type = $this->leadType($data);

        $lead = $this->createLead->handle(new LeadData(
            source: $data->source,
            type: $type,
            name: $data->name,
            phone: $data->phone,
            locale: $data->locale,
            company: $data->company,
            message: $data->message,
            items: $data->productId !== null ? [new LeadItemData($data->productId, qty: $data->rolls ?? 1, rollTypeId: $data->rollTypeId)] : $data->items,
            utm: $data->utm,
            pageUrl: $data->pageUrl,
            trafficChannel: $data->trafficChannel,
            trafficSource: $data->trafficSource,
            landingPath: $data->landingPath,
            matchCustomer: $data->matchCustomer,
            deviceBrand: $data->deviceBrand,
            deviceModel: $data->deviceModel,
            serialNumber: $data->serialNumber,
            preferredContact: $data->preferredContact,
        ));

        Log::info('Site lead received', array_filter([
            'lead_id' => $lead->id,
            'locale' => $lead->locale,
            'source' => $lead->source->value,
            'type' => $lead->type->value,
            'channel' => $lead->traffic_channel?->value,
            'product_sku' => $data->productSku,
            'items' => $data->productId === null && $data->items !== [] ? count($data->items) : null,
        ], fn (int|string|null $value): bool => $value !== null));

        return $lead;
    }

    /**
     * Type precedence (plan 36, P36-1): an explicit server-set ServiceRequest
     * always wins; else a product decides (Product::enquiryLeadType(), re-read
     * from the database), but it overrides Callback / Quote only (Checkout keeps its type); else the given type (callback, checkout).
     */
    protected function leadType(SiteLeadData $data): LeadType
    {
        if ($data->type === LeadType::ServiceRequest) {
            return LeadType::ServiceRequest;
        }

        if ($data->productId === null || ! in_array($data->type, [LeadType::Callback, LeadType::Quote], true)) {
            return $data->type;
        }

        $product = Product::query()->find($data->productId, ['id', 'type']);

        return $product instanceof Product ? $product->enquiryLeadType() : LeadType::Quote;
    }
}
