<?php

namespace App\Http\Controllers\Tma;

use App\Actions\Cart\AddToCart;
use App\Actions\Cart\ConvertCartToLead;
use App\Actions\Cart\RemoveCartLine;
use App\Actions\Cart\UpdateCartLine;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadSource;
use App\Models\Cart;
use App\Models\Product;
use App\Support\Cart\CartPricer;
use App\Support\Leads\LeadLimits;
use App\Support\Telegram\MiniApp\TmaCart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The Mini App cart (plan 20 §5, M3; MA-10, MA-21). 404 while
 * site.cart_enabled is off. The cart is always the signed-in user's
 * (TmaCart), never named by the request; the browser sends only a product
 * id, a roll type id, a quantity and a line key, and every rule (visible,
 * sale mode, Retail price, stock, roll type, min_qty, caps) is AddToCart's /
 * UpdateCartLine's. Prices are never read from the request (CartPricer).
 * A line key of another cart simply isn't found in this one.
 *
 * "Request a quote for this cart" (quote()): the verified registration
 * phone only (+888 / stale → the phone screen), per-user and per-phone
 * budgets (shared "site-lead-phone:", MA-12), the public phone rules and the
 * foreign-phone cap (TG-43 / TG-44, counted only for a written lead), then ConvertCartToLead with
 * source Telegram. The cart is kept.
 */
class CartController extends TmaController
{
    /**
     * Cart quote requests per user per 10 minutes.
     */
    public const QUOTE_PER_USER = 5;

    public function show(Request $request, CartPricer $pricer): Response
    {
        $this->ensureCart();
        $cart = TmaCart::current($this->user($request));
        $priced = $cart instanceof Cart ? $pricer->price($cart) : null;

        return $this->page($request, 'tma.cart', [
            'title' => __('tma.cart.title'),
            'back' => route('tma.home'),
            'priced' => $priced,
            'cartCount' => $priced?->count() ?? 0,
            'canCheckout' => $priced !== null && ! $priced->isEmpty() && ! $priced->hasInvalidLines(),
        ]);
    }

    public function add(Request $request, AddToCart $addToCart): RedirectResponse
    {
        $this->ensureCart();
        $user = $this->user($request);
        $productId = filter_var($request->input('product_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $rollTypeId = filter_var($request->input('roll_type_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $qty = filter_var($request->input('qty'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => Cart::MAX_QTY]]);
        $slug = $productId !== false ? Product::query()->visible()->whereKey($productId)->value('products.slug') : null;

        if ($slug === null) {
            abort(404);
        }

        $back = route('tma.product', ['product' => $slug]);

        if ($qty === false) {
            return redirect($back)->withErrors(['cart' => __('tma.cart.errors.quantity')]);
        }

        try {
            $cart = TmaCart::for($user, app()->getLocale());
            $addToCart->handle($cart->token, $productId, $rollTypeId !== false ? $rollTypeId : null, $qty, app()->getLocale(), $user->id);
        } catch (ValidationException $exception) {
            return redirect($back)->withErrors($exception->errors());
        }

        return redirect($back)->with('tma_status', 'added');
    }

    public function update(Request $request, string $lineKey, UpdateCartLine $updateLine): RedirectResponse
    {
        $this->ensureCart();
        $cart = TmaCart::current($this->user($request));
        $qty = filter_var($request->input('qty'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => Cart::MAX_QTY]]);

        if (! $cart instanceof Cart) {
            return redirect()->route('tma.cart');
        }

        if ($qty === false) {
            return redirect()->route('tma.cart')->withErrors(['cart' => __('tma.cart.errors.quantity')]);
        }

        try {
            $updateLine->handle($cart, $lineKey, $qty);
        } catch (ValidationException $exception) {
            return redirect()->route('tma.cart')->withErrors($exception->errors());
        }

        return redirect()->route('tma.cart');
    }

    public function remove(Request $request, string $lineKey, RemoveCartLine $removeLine): RedirectResponse
    {
        $this->ensureCart();
        $cart = TmaCart::current($this->user($request));

        if ($cart instanceof Cart) {
            try {
                $removeLine->handle($cart, $lineKey);
            } catch (ValidationException $exception) {
                return redirect()->route('tma.cart')->withErrors($exception->errors());
            }
        }

        return redirect()->route('tma.cart');
    }

    public function quote(Request $request, ConvertCartToLead $convertCart): RedirectResponse
    {
        $this->ensureCart();
        $user = $this->user($request);
        $phone = $this->verifiedPhone($user);

        if ($phone === null) {
            return $this->phoneScreen();
        }

        $cart = TmaCart::current($user);
        $comment = $request->input('comment');
        $comment = is_string($comment) ? trim($comment) : '';

        if (! $cart instanceof Cart || mb_strlen($comment) > 500) {
            return redirect()->route('tma.cart')->withErrors(['form' => __('tma.request.errors.invalid')]);
        }

        if ($this->overBudget('tma-lead:'.$user->id, self::QUOTE_PER_USER, 600) || LeadLimits::phoneExceeded($phone)) {
            return redirect()->route('tma.cart')->withErrors(['form' => __('tma.request.errors.rate_limited')]);
        }

        if (($refusal = $this->phoneRefusal($phone)) !== null) {
            return redirect()->route('tma.cart')->withErrors(['form' => $refusal]);
        }

        try {
            $convertCart->handle($cart, new SiteLeadData(
                name: $this->requestName($user),
                phone: $phone,
                company: filled($user->declared_company) ? mb_substr((string) $user->declared_company, 0, 160) : null,
                message: $comment !== '' ? $comment : null,
                locale: app()->getLocale(),
                source: LeadSource::Telegram,
            ));
        } catch (ValidationException $exception) {
            return redirect()->route('tma.cart')->withErrors($exception->errors());
        }

        $this->recordPhone($phone);

        return redirect()->route('tma.cart')->with('tma_status', 'quoted');
    }
}
