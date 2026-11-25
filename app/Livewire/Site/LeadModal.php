<?php

namespace App\Livewire\Site;

use App\Enums\LeadType;
use App\Models\Product;
use App\Support\Analytics\Attribution;
use App\Support\Cart\CartPricer;
use App\Support\Cart\CartToken;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * The quote request modal (plan 42), mounted once in layouts::site on
 * localized pages (not checkout). The page render has only an empty hidden
 * dialog; open() picks the form and mounts it as a child with a fresh key:
 *
 *  - callback: Site\LeadForm without a product (a callback lead);
 *  - product:  Site\LeadForm for a published product (quote, or a service
 *              request for a service product), with the RollPicker's last
 *              roll as the initial choice (re-checked by the form, QM-4);
 *              a hidden or missing product gives the callback form plus the
 *              "product unavailable" notice, identical for both (QM-2);
 *  - cart:     Site\Cart\QuoteForm while the cart is on (QM-8), else callback.
 *
 * pageUrl (url()->current() from the layout, never the client), the locale
 * and the session's stored first-touch attribution (read only) are captured
 * on the page request and locked (QM-5, QM-6), then passed to the child, so a form
 * mounted during /livewire/update never reads that URL or writes the
 * session. Opening is not a goal; only the child's success branch sends
 * poson-goal (QM-11). Every trigger is a real link (resources/js/lead-modal.js).
 */
class LeadModal extends Component
{
    public const KIND_CALLBACK = 'callback';

    public const KIND_PRODUCT = 'product';

    public const KIND_CART = 'cart';

    /**
     * @var list<string>
     */
    public const KINDS = [self::KIND_CALLBACK, self::KIND_PRODUCT, self::KIND_CART];

    #[Locked]
    public ?string $pageUrl = null;

    #[Locked]
    public string $locale = '';

    /**
     * First-touch attribution of the page request, without click ids.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $attribution = [];

    /**
     * The open form: callback, product or cart; null while closed (no child mounted).
     */
    #[Locked]
    public ?string $kind = null;

    #[Locked]
    public ?int $productId = null;

    #[Locked]
    public bool $productUnavailable = false;

    #[Locked]
    public bool $isServiceRequest = false;

    #[Locked]
    public ?int $rollTypeId = null;

    #[Locked]
    public ?int $rolls = null;

    /**
     * The cart has nothing to quote: a notice instead of the cart form.
     */
    #[Locked]
    public bool $cartEmpty = false;

    /**
     * Key of the child form, new on every open (a fresh form and fill timer).
     */
    #[Locked]
    public string $formKey = '';

    public function mount(?string $pageUrl = null): void
    {
        $this->pageUrl = $pageUrl ?? url()->current();
        $this->locale = app()->getLocale();
        $this->attribution = $this->captureAttribution();
    }

    /**
     * Open the modal with a form (QM-1: every argument is untrusted; an
     * unknown kind or a malformed id falls back to the callback form).
     */
    public function open(mixed $kind = null, mixed $productId = null, mixed $rollTypeId = null, mixed $rolls = null): void
    {
        $kind = is_string($kind) && in_array($kind, self::KINDS, true) ? $kind : self::KIND_CALLBACK;

        $this->productId = null;
        $this->productUnavailable = false;
        $this->isServiceRequest = false;
        $this->rollTypeId = null;
        $this->rolls = null;
        $this->cartEmpty = false;

        if ($kind === self::KIND_PRODUCT) {
            $product = $this->visibleProduct(self::positiveIntOrNull($productId));

            if ($product === null) {
                $kind = self::KIND_CALLBACK;
                $this->productUnavailable = true;
            } else {
                $this->productId = $product->id;
                $this->isServiceRequest = $product->enquiryLeadType() === LeadType::ServiceRequest;
                $this->rollTypeId = self::positiveIntOrNull($rollTypeId);
                $this->rolls = self::positiveIntOrNull($rolls);
            }
        }

        if ($kind === self::KIND_CART) {
            if (! config('site.cart_enabled')) {
                $kind = self::KIND_CALLBACK;
            } else {
                $this->cartEmpty = ! $this->cartHasQuotableLines();
            }
        }

        $this->kind = $kind;
        $this->formKey = Str::random(16);
    }

    /**
     * Close the modal: the child form is unmounted.
     */
    public function close(): void
    {
        $this->kind = null;
        $this->productId = null;
        $this->productUnavailable = false;
        $this->isServiceRequest = false;
        $this->rollTypeId = null;
        $this->rolls = null;
        $this->cartEmpty = false;
    }

    public function render(): View
    {
        return view('livewire.site.lead-modal', [
            'title' => match (true) {
                $this->kind === self::KIND_CART => __('site.lead_modal.title_cart'),
                $this->kind === self::KIND_PRODUCT && $this->isServiceRequest => __('site.lead_modal.title_service'),
                $this->kind === self::KIND_PRODUCT => __('site.lead_modal.title_product'),
                default => __('site.lead_modal.title_callback'),
            },
        ]);
    }

    /**
     * A product shown on the site (visible(): active, in an active category, not deleted), else null.
     */
    protected function visibleProduct(?int $productId): ?Product
    {
        if ($productId === null) {
            return null;
        }

        $product = Product::query()->visible()->whereKey($productId)->first(['id', 'type', 'category_id', 'is_active']);

        return $product instanceof Product ? $product : null;
    }

    protected function cartHasQuotableLines(): bool
    {
        $cart = app(CartToken::class)->cart();

        return $cart !== null && app(CartPricer::class)->price($cart)->quotableLines() !== [];
    }

    /**
     * The session's stored first-touch attribution without click ids, read
     * only (plan 42 L1): CaptureAttribution already stored a tracked human's
     * first touch, so bots, staff and untracked requests get [] and nothing
     * is written to the session. Empty if analytics fails.
     *
     * @return array<string, string>
     */
    protected function captureAttribution(): array
    {
        try {
            return Attribution::withoutClickIds(app(Attribution::class)->stored(request()) ?? []);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * A positive integer: an int, or a string of digits only ("12"); arrays,
     * floats, "1e3", " 1", "-1" and 0 are null.
     */
    private static function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
