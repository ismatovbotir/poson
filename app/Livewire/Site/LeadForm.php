<?php

namespace App\Livewire\Site;

use App\Actions\Analytics\RecordConversion;
use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\SiteLeadData;
use App\Enums\AnalyticsGoal;
use App\Enums\LeadType;
use App\Livewire\Site\Catalog\RollPicker;
use App\Livewire\Site\Concerns\DispatchesAnalyticsGoals;
use App\Livewire\Site\Concerns\GuardsLeadSubmissions;
use App\Models\Product;
use App\Models\RollType;
use App\Rules\PhoneNumber;
use App\Support\Analytics\Attribution;
use App\Support\Catalog\RollPricing;
use App\Support\Leads\LeadLimits;
use App\Support\NumberFormat;
use App\Support\Phone;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Lead form: "Discuss your project" on the home page (a callback lead) and
 * the quote form on a product page (<livewire:site.lead-form :product-id="..." />,
 * a quote lead for that product). Validates, applies the spam checks
 * (honeypot, minimum fill time) and rate limits, then hands the lead to
 * SubmitSiteLead.
 *
 * The product id is locked and checked against the published catalog on
 * mount and again on submit; its SKU is read from the database.
 *
 * The session's first-touch attribution (channel, source, UTM tags, landing;
 * see App\Support\Analytics\Attribution) goes with the lead, and a valid
 * lead is recorded as a conversion (RecordConversion) for the analytics.
 * Plan 40: the lead carries the session's Yandex click ids, and the
 * Metrika goal lead_callback / lead_product_quote / lead_product_service is
 * dispatched once a real (non-bot) lead is stored.
 *
 * Label products (plan 08): the request carries the roll type and rolls
 * chosen in the page's RollPicker ("roll-selected"; defaults: the first
 * active roll type and min_qty rolls). On submit the roll type must still be
 * offered and the rolls must be min_qty..RollPicker::MAX_ROLLS, otherwise
 * the request is rejected with a form error (checked after the rate
 * limits). A label product without any active roll type is asked for
 * without a roll.
 *
 * Plan 42: the same form opens in Site\LeadModal. The modal passes the page
 * request's pageUrl, locale and attribution as mount parameters (QM-5,
 * QM-6), so a form mounted during a Livewire update never reads the
 * update URL or writes the session; inline forms keep url()->current() and
 * the session attribution. idPrefix keeps the input ids unique when the
 * inline form and the modal form are on one page, and an initial roll from
 * the page's RollPicker is used only when it is valid for the product (QM-4).
 * A form that was sent ignores further submits (QM-3).
 */
class LeadForm extends Component
{
    use DispatchesAnalyticsGoals, GuardsLeadSubmissions;

    /**
     * Valid submissions allowed per IP within RATE_LIMIT_SECONDS.
     */
    public const RATE_LIMIT = LeadLimits::IP_LIMIT;

    public const RATE_LIMIT_SECONDS = LeadLimits::IP_SECONDS;

    /**
     * Valid submissions allowed per phone number within PHONE_LIMIT_SECONDS.
     */
    public const PHONE_LIMIT = LeadLimits::PHONE_LIMIT;

    public const PHONE_LIMIT_SECONDS = LeadLimits::PHONE_SECONDS;

    /**
     * Forms submitted faster than this after rendering are treated as bots.
     */
    public const MIN_FILL_SECONDS = 2;

    public const UTM_MAX_LENGTH = Attribution::UTM_MAX_LENGTH;

    /**
     * @var list<string>
     */
    public const UTM_KEYS = Attribution::UTM_KEYS;

    public string $name = '';

    public string $phone = '';

    public string $company = '';

    public string $message = '';

    /**
     * Honeypot: hidden from people, bots fill it in. The name avoids browser autofill.
     */
    public string $fax_ext = '';

    public bool $sent = false;

    #[Locked]
    public string $locale = '';

    /**
     * Product of a quote request (product page); null for a callback lead.
     */
    #[Locked]
    public ?int $productId = null;

    /**
     * "Name (SKU)" of that product, shown above the fields.
     */
    #[Locked]
    public ?string $productLabel = null;

    /**
     * The product is a service product: the form reads "Request this service" (plan 36).
     */
    #[Locked]
    public bool $isServiceRequest = false;

    #[Locked]
    public ?string $pageUrl = null;

    /**
     * Prefix of the input ids ("lead" inline, another one in the modal).
     */
    #[Locked]
    public string $idPrefix = 'lead';

    /**
     * Rendered inside Site\LeadModal: no card frame, the success block has a Close button.
     */
    #[Locked]
    public bool $inModal = false;

    /**
     * Roll type of a label product (plan 08); set by the RollPicker, checked on submit.
     * Untyped like RollPicker's: a tampered value ("abc", an array) is read
     * as "none" (rollTypeIdValue()) instead of failing with a TypeError.
     */
    public mixed $rollTypeId = null;

    /**
     * Rolls of a label product (plan 08); set by the RollPicker, checked on
     * submit. Untyped for the same reason (rollsValue()).
     */
    public mixed $rolls = null;

    #[Locked]
    public bool $sellsRolls = false;

    /**
     * UTM tags of the session's first touch.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $utm = [];

    /**
     * First-touch attribution of the session (channel, source, UTM tags, landing).
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $attribution = [];

    /**
     * Unix time the form was rendered (minimum fill-time check).
     */
    #[Locked]
    public int $renderedAt = 0;

    /**
     * @param  string|null  $pageUrl  page URL from the server-rendered layout (modal); null: this request's URL
     * @param  mixed  $rollTypeId  initial roll type (modal: the last RollPicker choice), used only when valid
     * @param  mixed  $rolls  initial rolls, used only with a valid roll type and within min_qty..MAX_ROLLS
     * @param  string|null  $locale  locale of the page request (modal); null: the current locale
     * @param  array<string, string>|null  $attribution  attribution captured on the page request (modal); null: read the session
     */
    public function mount(
        ?int $productId = null,
        ?string $pageUrl = null,
        string $idPrefix = 'lead',
        bool $inModal = false,
        mixed $rollTypeId = null,
        mixed $rolls = null,
        ?string $locale = null,
        ?array $attribution = null,
    ): void {
        $product = $productId !== null ? $this->publishedProduct($productId) : null;

        $this->productId = $product?->id;
        $this->productLabel = $product !== null ? $product->name.' ('.$product->sku.')' : null;
        $this->isServiceRequest = $product?->enquiryLeadType() === LeadType::ServiceRequest;
        $this->rollTypeId = null;
        $this->rolls = null;

        if ($product?->isPricedPerLabel()) {
            $this->sellsRolls = true;
            [$this->rollTypeId, $this->rolls] = $this->initialRoll($product, $rollTypeId, $rolls)
                ?? [$product->activeRollTypes()->value('roll_types.id'), self::minRolls($product)];
        }

        $this->idPrefix = preg_match('/^[a-z][a-z0-9-]{0,30}$/', $idPrefix) === 1 ? $idPrefix : 'lead';
        $this->inModal = $inModal;
        $this->locale = $locale !== null && in_array($locale, config('app.supported_locales', []), true) ? $locale : app()->getLocale();
        $this->pageUrl = $pageUrl ?? url()->current();
        $this->attribution = $attribution !== null ? Attribution::withoutClickIds(Attribution::sanitize($attribution)) : $this->captureAttribution();
        $this->utm = Attribution::utm($this->attribution);
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
     * The page's RollPicker changed the roll (validated again on submit).
     */
    #[On('roll-selected')]
    public function selectRoll(mixed $productId, mixed $rollTypeId, mixed $rolls): void
    {
        if ($this->sellsRolls && self::intOrNull($productId) === $this->productId) {
            $this->rollTypeId = self::intOrNull($rollTypeId);
            $this->rolls = self::intOrNull($rolls);
        }
    }

    public function submit(SubmitSiteLead $submitSiteLead, RecordConversion $recordConversion): void
    {
        // QM-3: a sent form stays sent (no second lead or goal from a double submit); the rate limits stay the real control.
        if ($this->sent) {
            return;
        }

        $validated = $this->validate();
        $phone = Phone::normalizeAny($validated['phone']);

        // A quote for a product that left the site in the meantime: say so instead of sending a quote without it.
        $product = $this->productId !== null ? $this->publishedProduct($this->productId) : null;

        if ($this->productId !== null && $product === null) {
            $this->addError('form', __('site.lead_form.product_unavailable'));

            return;
        }

        if ($this->leadLimitsExceeded($phone)) {
            return;
        }

        // Plan 08: a label product is asked for in an offered roll type and min_qty..MAX_ROLLS rolls;
        // without any offered roll type (all hidden since) the request goes without a roll.
        $sendsRoll = $product !== null && $product->isPricedPerLabel() && $product->activeRollTypes()->exists();

        if ($sendsRoll && ($error = $this->rollError($product)) !== null) {
            $this->addError('form', $error);

            return;
        }

        if ($this->foreignPhoneCapExceeded($phone)) {
            return;
        }

        // Bots get the same success screen, but nothing is submitted.
        if (! $this->looksLikeBot()) {
            $clickIds = $this->captureAdClickIds();
            $leadType = $product?->enquiryLeadType() ?? LeadType::Callback;

            $submitSiteLead->handle(new SiteLeadData(
                name: trim($validated['name']),
                phone: $phone,
                company: filled($validated['company']) ? trim($validated['company']) : null,
                message: filled($validated['message']) ? trim($validated['message']) : null,
                locale: $this->locale,
                type: $leadType,
                utm: $this->utm,
                pageUrl: $this->pageUrl,
                productId: $product?->id,
                productSku: $product?->sku,
                trafficChannel: Attribution::channel($this->attribution),
                trafficSource: $this->attribution['source'] ?? null,
                landingPath: $this->attribution['landing'] ?? null,
                rollTypeId: $sendsRoll ? self::intOrNull($this->rollTypeId) : null,
                rolls: $sendsRoll ? self::intOrNull($this->rolls) : null,
                yclid: $clickIds['yclid'],
                ymClientId: $clickIds['ym_client_id'],
            ));

            $recordConversion->handle(request(), $this->attribution, $this->pageUrl, $this->locale, $product?->id);

            $this->reachGoal(match (true) {
                $product === null => AnalyticsGoal::LeadCallback,
                $leadType === LeadType::ServiceRequest => AnalyticsGoal::LeadProductService,
                default => AnalyticsGoal::LeadProductQuote,
            });
        }

        $this->reset('name', 'phone', 'company', 'message', 'fax_ext');
        $this->sent = true;
    }

    public function render(): View
    {
        $rollTypeId = self::intOrNull($this->rollTypeId);
        $rolls = self::intOrNull($this->rolls);
        $rollType = $this->sellsRolls && $rollTypeId !== null ? RollType::query()->active()->find($rollTypeId) : null;

        return view('livewire.site.lead-form', [
            'rollSummary' => $rollType !== null && $rolls !== null && $rolls >= 1 && $rolls <= RollPicker::MAX_ROLLS
                ? RollPricing::describe(null, $rollType->labels_per_roll, $rollType->core_diameter_mm, $rolls)
                : null,
        ]);
    }

    /**
     * Why the chosen roll can't be requested, or null when it can.
     */
    protected function rollError(Product $product): ?string
    {
        $rollTypeId = self::intOrNull($this->rollTypeId);
        $rollType = $rollTypeId !== null ? RollType::query()->find($rollTypeId) : null;

        if (! $rollType instanceof RollType || ! RollPricing::isAvailable($product, $rollType)) {
            return __('catalog.roll.errors.required');
        }

        $min = self::minRolls($product);

        $rolls = self::intOrNull($this->rolls);

        if ($rolls === null || $rolls < $min || $rolls > RollPicker::MAX_ROLLS) {
            return __('site.roll_picker.rolls_invalid', ['min' => NumberFormat::format($min), 'max' => NumberFormat::format(RollPicker::MAX_ROLLS)]);
        }

        return null;
    }

    /**
     * QM-4: an initial roll [roll type id, rolls] when the roll type is offered
     * for this product and the rolls are within min_qty..MAX_ROLLS, else null (defaults apply).
     *
     * @return array{0: int, 1: int}|null
     */
    protected function initialRoll(Product $product, mixed $rollTypeId, mixed $rolls): ?array
    {
        $rollTypeId = self::intOrNull($rollTypeId);
        $rolls = self::intOrNull($rolls);

        if ($rollTypeId === null || $rolls === null || $rolls < self::minRolls($product) || $rolls > RollPicker::MAX_ROLLS) {
            return null;
        }

        $rollType = RollType::query()->find($rollTypeId);

        return $rollType instanceof RollType && RollPricing::isAvailable($product, $rollType) ? [$rollType->id, $rolls] : null;
    }

    /**
     * The fewest rolls of a label product that can be asked for.
     */
    private static function minRolls(Product $product): int
    {
        return max(1, min(RollPicker::MAX_ROLLS, $product->min_qty));
    }

    /**
     * The product when it is shown on the site, else null.
     */
    protected function publishedProduct(int $productId): ?Product
    {
        // type is needed by enquiryLeadType()
        $product = Product::query()->visible()->whereKey($productId)->first(['id', 'sku', 'type', 'name', 'category_id', 'is_active', 'pricing_unit', 'min_qty']);

        return $product instanceof Product ? $product : null;
    }

    /**
     * Keep whitelisted UTM keys with non-empty string values (see Attribution::sanitizeUtm()).
     *
     * @return array<string, string>
     */
    public static function sanitizeUtm(mixed $input): array
    {
        return Attribution::sanitizeUtm($input);
    }

    /**
     * An integer (also from a numeric string), else null.
     */
    private static function intOrNull(mixed $value): ?int
    {
        $int = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        return $int === false ? null : $int;
    }
}
