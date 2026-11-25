<?php

namespace App\Livewire\Site\Cart;

use App\Actions\Analytics\RecordConversion;
use App\Actions\Cart\ConvertCartToLead;
use App\Data\Leads\SiteLeadData;
use App\Enums\AnalyticsGoal;
use App\Enums\LeadType;
use App\Livewire\Site\Concerns\DispatchesAnalyticsGoals;
use App\Livewire\Site\Concerns\GuardsLeadSubmissions;
use App\Models\Cart;
use App\Rules\PhoneNumber;
use App\Support\Analytics\Attribution;
use App\Support\Cart\CartPricer;
use App\Support\Cart\CartToken;
use App\Support\Phone;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Request a quote for this cart" (plan 11a; extracted from Site\Cart\Page
 * in plan 42 without a change in behaviour): a lead form with the same
 * fields, spam checks and rate limits as Site\LeadForm, creating a checkout
 * lead with the cart's lines (ConvertCartToLead, the cart is kept),
 * recorded as a conversion, goal lead_cart_quote.
 *
 * Inline in the cart page's #cart-quote section and in Site\LeadModal. It
 * takes no cart id or token: the visitor's cart is resolved from the cart
 * cookie at submit time (QM-7). The order of the checks: validation → cart
 * → rate limits → nothing to quote → foreign-phone cap. 404 on every
 * request while config site.cart_enabled is off (QM-8). In the modal the
 * page request's pageUrl, locale and attribution are passed in (QM-5,
 * QM-6); a sent form ignores further submits (QM-3).
 */
class QuoteForm extends Component
{
    use DispatchesAnalyticsGoals;
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

    /**
     * Prefix of the input ids ("cart-quote" inline, another one in the modal).
     */
    #[Locked]
    public string $idPrefix = 'cart-quote';

    /**
     * Rendered inside Site\LeadModal: no card frame, the success block has a Close button.
     */
    #[Locked]
    public bool $inModal = false;

    public function boot(): void
    {
        abort_unless(config('site.cart_enabled'), 404);
    }

    /**
     * @param  string|null  $pageUrl  page URL from the server-rendered layout (modal); null: this request's URL
     * @param  string|null  $locale  locale of the page request (modal); null: the current locale
     * @param  array<string, string>|null  $attribution  attribution captured on the page request (modal); null: read the session
     */
    public function mount(
        ?string $pageUrl = null,
        string $idPrefix = 'cart-quote',
        bool $inModal = false,
        ?string $locale = null,
        ?array $attribution = null,
    ): void {
        $this->idPrefix = preg_match('/^[a-z][a-z0-9-]{0,30}$/', $idPrefix) === 1 ? $idPrefix : 'cart-quote';
        $this->inModal = $inModal;
        $this->locale = $locale !== null && in_array($locale, config('app.supported_locales', []), true) ? $locale : app()->getLocale();
        $this->pageUrl = $pageUrl ?? url()->current();
        $this->attribution = $attribution !== null ? Attribution::withoutClickIds(Attribution::sanitize($attribution)) : $this->captureAttribution();
        $this->renderedAt = now()->getTimestamp();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', new PhoneNumber(public: true)],
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
        // QM-3: a sent form stays sent; the rate limits stay the real control.
        if ($this->sent) {
            return;
        }

        $validated = $this->validate();
        $phone = Phone::normalizeAny($validated['phone']);
        $cart = $this->cart();

        if ($cart === null) {
            $this->addError('form', __('checkout.cart.errors.nothing_to_quote'));

            return;
        }

        if ($this->leadLimitsExceeded((string) $phone)) {
            return;
        }

        // Nothing to quote: refuse before the foreign-phone cap is touched.
        if (app(CartPricer::class)->price($cart)->quotableLines() === []) {
            $this->addError('form', __('checkout.cart.errors.nothing_to_quote'));

            return;
        }

        if ($this->foreignPhoneCapExceeded((string) $phone)) {
            return;
        }

        // Bots get the same success screen, but nothing is submitted.
        if (! $this->looksLikeBot()) {
            $clickIds = $this->captureAdClickIds();

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
                    yclid: $clickIds['yclid'],
                    ymClientId: $clickIds['ym_client_id'],
                ));
            } catch (ValidationException $exception) {
                $this->addError('form', (string) collect($exception->errors())->flatten()->first());

                return;
            }

            $recordConversion->handle(request(), $this->attribution, $this->pageUrl, $this->locale);

            $this->reachGoal(AnalyticsGoal::LeadCartQuote);
        }

        $this->reset('name', 'phone', 'company', 'message', 'fax_ext');
        $this->sent = true;
    }

    public function render(): View
    {
        return view('livewire.site.cart.quote-form');
    }

    private function cart(): ?Cart
    {
        return app(CartToken::class)->cart();
    }
}
