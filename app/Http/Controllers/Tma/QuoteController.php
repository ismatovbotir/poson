<?php

namespace App\Http\Controllers\Tma;

use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadSource;
use App\Models\Product;
use App\Support\Leads\LeadLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /tma/{locale}/p/{product:slug}/quote (plan 20 §6.3, M4; MA-9, MA-12,
 * MA-19): the one-tap "Request a quote" / "Contact us" / "Request service"
 * of a product shown on the site (re-checked here, else 404). Name and
 * phone come from the bot registration only (verified phone, else the phone
 * screen); the browser sends only an optional comment (≤ 500 characters).
 * The lead type is the product's (Product::enquiryLeadType(): a service
 * product → service_request → the service chat), never from the request.
 * Budgets, counted before anything is written: per user and the shared
 * per-phone "site-lead-phone:" budget, then (last) the site's public phone
 * rules and the foreign-phone cap (TG-43 / TG-44), counted only for a
 * written lead. LeadCreated notifies the team.
 */
class QuoteController extends TmaController
{
    /**
     * One-tap requests per user per 10 minutes.
     */
    public const PER_USER = 5;

    public function __invoke(Request $request, Product $product, SubmitSiteLead $submitSiteLead): RedirectResponse
    {
        abort_unless($product->isVisible(), 404);

        $user = $this->user($request);
        $phone = $this->verifiedPhone($user);

        if ($phone === null) {
            return $this->phoneScreen();
        }

        $back = route('tma.product', ['product' => $product->slug]);
        $comment = $request->input('comment');
        $comment = is_string($comment) ? trim($comment) : '';

        if (mb_strlen($comment) > 500) {
            return redirect($back)->withErrors(['request' => __('tma.request.errors.too_long')]);
        }

        if ($this->overBudget('tma-lead:'.$user->id, self::PER_USER, 600) || LeadLimits::phoneExceeded($phone)) {
            return redirect($back)->withErrors(['request' => __('tma.request.errors.rate_limited')]);
        }

        if (($refusal = $this->phoneRefusal($phone)) !== null) {
            return redirect($back)->withErrors(['request' => $refusal]);
        }

        $submitSiteLead->handle(new SiteLeadData(
            name: $this->requestName($user),
            phone: $phone,
            company: filled($user->declared_company) ? mb_substr((string) $user->declared_company, 0, 160) : null,
            message: $comment !== '' ? $comment : null,
            locale: app()->getLocale(),
            source: LeadSource::Telegram,
            type: $product->enquiryLeadType(),
            productId: $product->id,
            productSku: $product->sku,
        ));

        $this->recordPhone($phone);

        return redirect($back)->with('tma_status', 'requested');
    }
}
