<?php

namespace App\Livewire\Site\Cart;

use App\Actions\Analytics\RecordConversion;
use App\Actions\Cart\ConvertCartToLead;
use App\Actions\Cart\RemoveCartLine;
use App\Actions\Cart\UpdateCartLine;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadType;
use App\Livewire\Site\Cart\Concerns\AddsToCart;
use App\Livewire\Site\Concerns\GuardsLeadSubmissions;
use App\Models\Cart;
use App\Rules\UzPhone;
use App\Support\Analytics\Attribution;
use App\Support\Cart\CartLineKey;
use App\Support\Cart\CartPricer;
use App\Support\Cart\CartToken;
use App\Support\Phone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The cart page /{locale}/cart (plan 11a; 404 while config
 * site.cart_enabled is off, noindex, disallowed in robots.txt).
 *
 * Rendered from CartPricer on every request (Retail prices re-read from
 * the products). Security condition 7: no cart id, prices, totals or
 * validity flags live in public properties; lines are named only by their
 * key ("p12" / "p12-r3"), parsed with CartLineKey::PATTERN in every
 * method. Lines that can no longer be bought are flagged "No longer
 * available" (left out of the total) and can only be removed; on-order
 * products note that the manager confirms the lead time.
 *
 * "Request a quote for this cart": an inline lead form (same fields, spam
 * checks and rate limits as Site\LeadForm) creating a checkout lead with
 * the cart's lines (ConvertCartToLead), recorded as a conversion.
 * "Checkout" leads to /{locale}/checkout (plan 11b) while every line can be bought.
 */
class Page extends Component
{
    use AddsToCart;
    use GuardsLeadSubmissions;

    public string $name = '';

    public string $phone = '';

    public string $company = '';

    public string $message = '';

    /**
     * Honeypot (see Site\LeadForm).
     */
    public string $fax_ext = '';

    public bool $sent = false;

    #[Locked]
    public string $locale = '';

    #[Locked]
    public ?string $pageUrl = null;

    /**
     * @var array<string, string>
     */
    #[Locked]
    public array $attribution = [];

    #[Locked]
    public int $renderedAt = 0;

    public function boot(): void
    {
        abort_unless(config('site.cart_enabled'), 404);
    }

    public function mount(): void
    {
        $this->locale = app()->getLocale();
        $this->pageUrl = url()->current();
        $this->attribution = $this->captureAttribution();
        $this->renderedAt = now()->getTimestamp();
    }

    public function increment(mixed $lineKey): void
    {
        $qty = $this->currentQty($lineKey);

        if ($qty !== null) {
            $this->changeQty($lineKey, $qty + 1);
        }
    }

    public function decrement(mixed $lineKey): void
    {
        $qty = $this->currentQty($lineKey);

        if ($qty !== null) {
            $this->changeQty($lineKey, $qty - 1);
        }
    }

    /**
     * A typed quantity; empty or not a number leaves the line as it is.
     */
    public function updateQty(mixed $lineKey, mixed $qty): void
    {
        $qty = is_float($qty) ? (int) $qty : static::cartIntOrNull($qty);

        if ($qty === null || $this->currentQty($lineKey) === null) {
            return;
        }

        $this->changeQty($lineKey, max(1, min(Cart::MAX_QTY, $qty)));
    }

    public function remove(mixed $lineKey, RemoveCartLine $removeLine): void
    {
        $cart = $this->cart();

        if ($cart === null || ! CartLineKey::isValid($lineKey)) {
            return;
        }

        try {
            $removeLine->handle($cart, $lineKey);
        } catch (ValidationException $exception) {
            $this->addError('cart', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        app(CartToken::class)->refresh();
        $this->dispatch('cart-updated');
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', new UzPhone],
            'company' => ['nullable', 'string', 'max:160'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'name' => __('site.lead_form.attributes.name'),
            'phone' => __('site.lead_form.attributes.phone'),
            'company' => __('site.lead_form.attributes.company'),
            'message' => __('site.lead_form.attributes.message'),
        ];
    }

    /**
     * "Request a quote for this cart".
     */
    public function requestQuote(ConvertCartToLead $convertCart, RecordConversion $recordConversion): void
    {
        $validated = $this->validate();
        $phone = Phone::normalize($validated['phone']);
        $cart = $this->cart();

        if ($cart === null) {
            $this->addError('form', __('checkout.cart.errors.nothing_to_quote'));

            return;
        }

        if ($this->leadLimitsExceeded((string) $phone)) {
            return;
        }

        // Bots get the same success screen, but nothing is submitted.
        if (! $this->looksLikeBot()) {
            try {
                $convertCart->handle($cart, new SiteLeadData(
                    name: trim($validated['name']),
                    phone: (string) $phone,
                    company: filled($validated['company']) ? trim($validated['company']) : null,
                    message: filled($validated['message']) ? trim($validated['message']) : null,
                    locale: $this->locale,
                    type: LeadType::Checkout,
                    utm: Attribution::utm($this->attribution),
                    pageUrl: $this->pageUrl,
                    trafficChannel: Attribution::channel($this->attribution),
                    trafficSource: $this->attribution['source'] ?? null,
                    landingPath: $this->attribution['landing'] ?? null,
                ));
            } catch (ValidationException $exception) {
                $this->addError('form', (string) collect($exception->errors())->flatten()->first());

                return;
            }

            $recordConversion->handle(request(), $this->attribution, $this->pageUrl, $this->locale);
        }

        $this->reset('name', 'phone', 'company', 'message', 'fax_ext');
        $this->sent = true;
    }

    public function render(CartPricer $pricer): View
    {
        $cart = $this->cart();
        $priced = $cart !== null ? $pricer->price($cart) : null;

        return view('livewire.site.cart.page', [
            'cart' => $priced,
            'maxQty' => Cart::MAX_QTY,
            'renderId' => Str::random(8),
            'breadcrumbs' => [
                ['label' => __('site.breadcrumbs.home'), 'url' => route('site.home')],
                ['label' => __('checkout.cart.title'), 'url' => null],
            ],
        ])
            ->title(__('checkout.cart.title').' — '.__('site.meta.site_name'))
            ->layoutData([
                'description' => __('checkout.cart.seo_description'),
                'robots' => 'noindex,nofollow',
            ]);
    }

    private function changeQty(mixed $lineKey, int $qty): void
    {
        $cart = $this->cart();

        if ($cart === null || ! CartLineKey::isValid($lineKey)) {
            return;
        }

        $this->resetErrorBag('cart');

        if (! static::cartAddAllowed()) {
            $this->addError('cart', __('checkout.cart.errors.rate_limited', ['seconds' => RateLimiter::availableIn(static::cartAddLimiterKey())]));

            return;
        }

        try {
            app(UpdateCartLine::class)->handle($cart, $lineKey, $qty);
        } catch (ValidationException $exception) {
            $this->addError('cart', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        app(CartToken::class)->refresh();
        $this->dispatch('cart-updated');
    }

    /**
     * The quantity of a line of the visitor's cart; null for a malformed or unknown key.
     */
    private function currentQty(mixed $lineKey): ?int
    {
        if (! CartLineKey::isValid($lineKey)) {
            return null;
        }

        $qty = $this->cart()?->items()->where('line_key', $lineKey)->value('qty');

        return $qty !== null ? (int) $qty : null;
    }

    private function cart(): ?Cart
    {
        return app(CartToken::class)->cart();
    }
}
