<?php

namespace App\Actions\Cart;

use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\LeadItemData;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadType;
use App\Models\Cart;
use App\Support\Cart\CartPricer;
use App\Support\Cart\PricedLine;
use Illuminate\Validation\ValidationException;

/**
 * "Request a quote for this cart" (plan 11 v2 §1.1): turns the cart into a
 * site lead of type checkout through SubmitSiteLead, with one lead item per
 * cart line that is still on the site, label products in their roll type
 * (SyncLeadItems snapshots the roll: roll_type_id, labels per roll, core).
 * Lines whose product or roll type left the site are left out; quote-only,
 * price-less or out-of-stock products are kept (a quote suits them).
 *
 * The caller (Site\Cart\Page) has already applied the lead form's spam
 * checks and rate limits. The cart itself is kept. No prices go to the
 * lead (the manager prices the quote).
 */
class ConvertCartToLead
{
    public function __construct(protected CartPricer $pricer, protected SubmitSiteLead $submitSiteLead) {}

    /**
     * @param  SiteLeadData  $contact  name, phone, company, message, locale and attribution of the request
     * @return string Lead reference ("L-152")
     *
     * @throws ValidationException
     */
    public function handle(Cart $cart, SiteLeadData $contact): string
    {
        $items = array_map(
            fn (PricedLine $line): LeadItemData => new LeadItemData(
                productId: $line->product->id,
                qty: $line->qty,
                rollTypeId: $line->rollTypeId,
            ),
            $this->pricer->price($cart)->quotableLines(),
        );

        if ($items === []) {
            throw ValidationException::withMessages(['form' => __('checkout.cart.errors.nothing_to_quote')]);
        }

        return $this->submitSiteLead->handle(new SiteLeadData(
            name: $contact->name,
            phone: $contact->phone,
            company: $contact->company,
            message: $contact->message,
            locale: $contact->locale,
            source: $contact->source,
            type: LeadType::Checkout,
            utm: $contact->utm,
            pageUrl: $contact->pageUrl,
            trafficChannel: $contact->trafficChannel,
            trafficSource: $contact->trafficSource,
            landingPath: $contact->landingPath,
            items: array_values($items),
        ));
    }
}
