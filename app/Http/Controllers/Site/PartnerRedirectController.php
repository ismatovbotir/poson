<?php

namespace App\Http\Controllers\Site;

use App\Actions\Analytics\RecordPartnerClick;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Try" button (ProductCtas): /{locale}/go/{product:slug} records a partner
 * click and redirects (302) to the partner / referral link of a software
 * product: its brand's website, exactly as entered in admin (query
 * parameters kept). 404 unless the product is on the site, is software and
 * its active brand has a valid https website (Product::partnerUrl()). The route is throttled, disallowed in robots.txt and
 * answers with X-Robots-Tag: noindex, nofollow; the redirect never fails
 * because of analytics (RecordPartnerClick swallows errors).
 */
class PartnerRedirectController extends Controller
{
    public function __invoke(Request $request, Product $product, RecordPartnerClick $recordPartnerClick): RedirectResponse
    {
        $url = $product->loadMissing('brand')->partnerUrl();

        abort_unless($url !== null && $product->isVisible(), 404);

        $recordPartnerClick->handle($request, $product);

        return redirect()->away($url, 302, [
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ]);
    }
}
