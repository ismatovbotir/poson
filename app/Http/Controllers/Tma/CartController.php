<?php

namespace App\Http\Controllers\Tma;

use App\Actions\Cart\AddToCart;
use App\Actions\Cart\ConvertCartToLead;
use App\Actions\Cart\RemoveCartLine;
use App\Actions\Cart\UpdateCartLine;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadSource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Support\Cart\CartLineKey;
use App\Support\Cart\CartPricer;
use App\Support\Leads\LeadLimits;
use App\Support\Telegram\MiniApp\TmaCart;
use App\Support\Telegram\MiniApp\TmaReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
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
 *
 * Plan 45 (MA-42, MA-44, MA-46): add / update / remove answer JSON when the
 * request wants JSON (tma.js), on the same routes and middleware (tma.user,
 * throttle, CSRF): {count, subtotal, subtotalText, qty, lineKey} or 422
 * {message}. The actions stay the only authority on quantities and
 * eligibility. Without JavaScript the redirect goes to the screen the form
 * names in "from" (TmaReturn), built on the server.
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

    public function add(Request $request, AddToCart $addToCart): RedirectResponse|JsonResponse
    {
        $this->ensureSameOrigin($request);
        $this->ensureCart();
        $user = $this->user($request);
        $productId = filter_var($request->input('product_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $rollTypeId = filter_var($request->input('roll_type_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $qty = filter_var($request->input('qty'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => Cart::MAX_QTY]]);
        $json = $request->wantsJson();

        if ($productId === false) {
            abort(404);
        }

        // The slug only builds the redirect; for JSON AddToCart alone refuses a product that isn't on the site.
        $slug = $json ? null : Product::query()->visible()->whereKey($productId)->value('products.slug');

        if (! $json && $slug === null) {
            abort(404);
        }

        $back = $json ? '' : TmaReturn::url($request->input('from'), $productId, $slug, route('tma.product', ['product' => $slug]));

        if ($qty === false) {
            return $this->refused($request, $back, __('tma.cart.errors.quantity'));
        }

        try {
            // The open cart when there is one; TmaCart::for() creates the first one under the user row lock.
            $cart = TmaCart::current($user) ?? TmaCart::for($user, app()->getLocale());
            $item = $addToCart->handle($cart->token, $productId, $rollTypeId !== false ? $rollTypeId : null, $qty, app()->getLocale(), $user->id);
        } catch (ValidationException $exception) {
            return $this->refused($request, $back, $exception);
        }

        return $this->done($request, $back, $item, 'added');
    }

    public function update(Request $request, string $lineKey, UpdateCartLine $updateLine): RedirectResponse|JsonResponse
    {
        $this->ensureSameOrigin($request);
        $this->ensureCart();
        $cart = TmaCart::current($this->user($request));
        $qty = filter_var($request->input('qty'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => Cart::MAX_QTY]]);
        $back = $this->backFromLine($request, $lineKey);

        if (! $cart instanceof Cart) {
            return $request->wantsJson() ? $this->refused($request, $back, __('checkout.cart.errors.line_missing')) : redirect($back);
        }

        if ($qty === false) {
            return $this->refused($request, $back, __('tma.cart.errors.quantity'));
        }

        try {
            $item = $updateLine->handle($cart, $lineKey, $qty);
        } catch (ValidationException $exception) {
            return $this->refused($request, $back, $exception);
        }

        return $this->done($request, $back, $item);
    }

    public function remove(Request $request, string $lineKey, RemoveCartLine $removeLine): RedirectResponse|JsonResponse
    {
        $this->ensureSameOrigin($request);
        $this->ensureCart();
        $cart = TmaCart::current($this->user($request));
        $back = $this->backFromLine($request, $lineKey);

        // tma.js gets told when the line isn't in this cart (a forged or stale key); the form stays idempotent.
        if ($request->wantsJson() && ! ($cart instanceof Cart && $cart->items()->where('line_key', $lineKey)->exists())) {
            return $this->refused($request, $back, __('checkout.cart.errors.line_missing'));
        }

        if ($cart instanceof Cart) {
            try {
                $removeLine->handle($cart, $lineKey);
            } catch (ValidationException $exception) {
                return $this->refused($request, $back, $exception);
            }
        }

        return $this->done($request, $back, null);
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

    /**
     * Plan 45 abuse case 1: a cart write from another site is refused even
     * with a valid token (the CSRF middleware takes a matching token or a
     * same-origin request; this also wants the latter when the browser says).
     * The Mini App page posts from its own origin, inside Telegram Web too.
     *
     * @throws TokenMismatchException
     */
    protected function ensureSameOrigin(Request $request): void
    {
        $origin = $request->headers->get('Origin');
        $site = $request->headers->get('Sec-Fetch-Site');

        // Hosts only: a TLS proxy may hide the scheme; the host is checked by trustHosts.
        $foreign = $origin !== null && strcasecmp((string) parse_url($origin, PHP_URL_HOST), $request->getHost()) !== 0;

        // Fail closed: "Origin: null" (an opaque origin) has no host and is refused too.
        if ($foreign || ($site !== null && ! in_array($site, ['same-origin', 'none'], true))) {
            // Route and the two header values only (length-capped), no user or session data.
            Log::warning('tma.cart.origin_refused', [
                'route' => $request->route()?->getName(),
                'origin' => $origin !== null ? mb_substr($origin, 0, 200) : null,
                'sec_fetch_site' => $site !== null ? mb_substr($site, 0, 40) : null,
            ]);

            throw new TokenMismatchException(__('tma.error.419.title'));
        }
    }

    /**
     * Where a form without JavaScript returns to (MA-46): the screen named by
     * "from" (TmaReturn), else the cart. The product comes from the line key.
     * Its one Product query runs only for a plain form with a valid "from"
     * other than the cart, never for JSON (outside the MA-56 JSON budget).
     */
    protected function backFromLine(Request $request, string $lineKey): string
    {
        $from = $request->input('from');

        if (! TmaReturn::isValid($from) || $from === TmaReturn::CART || $request->wantsJson()) {
            return route('tma.cart');
        }

        $productId = CartLineKey::parse($lineKey)['productId'] ?? 0;
        $slug = $productId > 0 ? Product::query()->visible()->whereKey($productId)->value('products.slug') : null;

        return TmaReturn::url($from, $productId, $slug, route('tma.cart'));
    }

    /**
     * The JSON answer (MA-42, only when wantsJson()) built after the action
     * from the session user's own cart: line count, the live Retail subtotal
     * of the valid lines, and the stored line (its key from the database,
     * never echoed from the request; null and 0 once removed). Without
     * JavaScript: the PRG redirect.
     */
    protected function done(Request $request, string $back, ?CartItem $item, ?string $status = null): RedirectResponse|JsonResponse
    {
        if (! $request->wantsJson()) {
            $redirect = redirect($back);

            return $status !== null ? $redirect->with('tma_status', $status) : $redirect;
        }

        $summary = TmaCart::summary($this->user($request));
        $lineKey = $item instanceof CartItem ? $item->line_key : null;

        return response()->json([
            'count' => $summary->count,
            'subtotal' => $summary->subtotal,
            'subtotalText' => $summary->subtotalText(),
            'qty' => $summary->qty($lineKey),
            'lineKey' => $lineKey !== null && $summary->qty($lineKey) > 0 ? $lineKey : null,
        ]);
    }

    /**
     * A refused cart write: 422 {message} (lang text only) for JSON, else back with the error.
     */
    protected function refused(Request $request, string $back, ValidationException|string $error): RedirectResponse|JsonResponse
    {
        $message = $error instanceof ValidationException ? (string) collect($error->errors())->flatten()->first() : $error;

        if ($request->wantsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return redirect($back)->withErrors(['cart' => $message]);
    }
}
