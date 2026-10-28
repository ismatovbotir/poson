<?php

namespace App\Livewire\Site;

use App\Actions\Analytics\RecordConversion;
use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadType;
use App\Livewire\Site\Catalog\RollPicker;
use App\Models\Product;
use App\Models\RollType;
use App\Rules\UzPhone;
use App\Support\Analytics\Attribution;
use App\Support\Catalog\RollPricing;
use App\Support\NumberFormat;
use App\Support\Phone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
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
 *
 * Label products (plan 08): the request carries the roll type and rolls
 * chosen in the page's RollPicker ("roll-selected"; defaults: the first
 * active roll type and min_qty rolls). On submit the roll type must still be
 * offered and the rolls must be min_qty..RollPicker::MAX_ROLLS, otherwise
 * the request is rejected with a form error (checked after the rate
 * limits). A label product without any active roll type is asked for
 * without a roll.
 */
class LeadForm extends Component
{
    /**
     * Valid submissions allowed per IP within RATE_LIMIT_SECONDS.
     */
    public const RATE_LIMIT = 5;

    public const RATE_LIMIT_SECONDS = 600;

    /**
     * Valid submissions allowed per phone number within PHONE_LIMIT_SECONDS.
     */
    public const PHONE_LIMIT = 3;

    public const PHONE_LIMIT_SECONDS = 3600;

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

    #[Locked]
    public ?string $pageUrl = null;

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

    public function mount(?int $productId = null): void
    {
        $product = $productId !== null ? $this->publishedProduct($productId) : null;

        $this->productId = $product?->id;
        $this->productLabel = $product !== null ? $product->name.' ('.$product->sku.')' : null;

        if ($product?->isPricedPerLabel()) {
            $this->sellsRolls = true;
            $this->rollTypeId = $product->activeRollTypes()->value('roll_types.id');
            $this->rolls = max(1, min(RollPicker::MAX_ROLLS, $product->min_qty));
        }
        $this->locale = app()->getLocale();
        $this->pageUrl = url()->current();
        $this->attribution = $this->captureAttribution();
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
        $validated = $this->validate();
        $phone = Phone::normalize($validated['phone']);

        // A quote for a product that left the site in the meantime: say so instead of sending a quote without it.
        $product = $this->productId !== null ? $this->publishedProduct($this->productId) : null;

        if ($this->productId !== null && $product === null) {
            $this->addError('form', __('site.lead_form.product_unavailable'));

            return;
        }

        // Count first, then compare: concurrent requests can't slip past the limit.
        if (
            $this->limitExceeded('site-lead:'.sha1((string) request()->ip()), self::RATE_LIMIT, self::RATE_LIMIT_SECONDS)
            || $this->limitExceeded('site-lead-phone:'.sha1($phone), self::PHONE_LIMIT, self::PHONE_LIMIT_SECONDS)
        ) {
            return;
        }

        // Plan 08: a label product is asked for in an offered roll type and min_qty..MAX_ROLLS rolls;
        // without any offered roll type (all hidden since) the request goes without a roll.
        $sendsRoll = $product !== null && $product->isPricedPerLabel() && $product->activeRollTypes()->exists();

        if ($sendsRoll && ($error = $this->rollError($product)) !== null) {
            $this->addError('form', $error);

            return;
        }

        // Bots get the same success screen, but nothing is submitted.
        if (! $this->looksLikeBot()) {
            $submitSiteLead->handle(new SiteLeadData(
                name: trim($validated['name']),
                phone: $phone,
                company: filled($validated['company']) ? trim($validated['company']) : null,
                message: filled($validated['message']) ? trim($validated['message']) : null,
                locale: $this->locale,
                type: $product !== null ? LeadType::Quote : LeadType::Callback,
                utm: $this->utm,
                pageUrl: $this->pageUrl,
                productId: $product?->id,
                productSku: $product?->sku,
                trafficChannel: Attribution::channel($this->attribution),
                trafficSource: $this->attribution['source'] ?? null,
                landingPath: $this->attribution['landing'] ?? null,
                rollTypeId: $sendsRoll ? self::intOrNull($this->rollTypeId) : null,
                rolls: $sendsRoll ? self::intOrNull($this->rolls) : null,
            ));

            $recordConversion->handle(request(), $this->attribution, $this->pageUrl, $this->locale, $product?->id);
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

        $min = max(1, min(RollPicker::MAX_ROLLS, $product->min_qty));

        $rolls = self::intOrNull($this->rolls);

        if ($rolls === null || $rolls < $min || $rolls > RollPicker::MAX_ROLLS) {
            return __('site.roll_picker.rolls_invalid', ['min' => NumberFormat::format($min), 'max' => NumberFormat::format(RollPicker::MAX_ROLLS)]);
        }

        return null;
    }

    /**
     * Register a hit and report (as a form error) whether the limit is now exceeded.
     */
    protected function limitExceeded(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        if (RateLimiter::hit($key, $decaySeconds) <= $maxAttempts) {
            return false;
        }

        $this->addError('form', __('site.lead_form.error_rate_limited', [
            'seconds' => RateLimiter::availableIn($key),
        ]));

        return true;
    }

    /**
     * The product when it is shown on the site, else null.
     */
    protected function publishedProduct(int $productId): ?Product
    {
        $product = Product::query()->visible()->whereKey($productId)->first(['id', 'sku', 'name', 'category_id', 'is_active', 'pricing_unit', 'min_qty']);

        return $product instanceof Product ? $product : null;
    }

    protected function looksLikeBot(): bool
    {
        return $this->fax_ext !== ''
            || now()->getTimestamp() - $this->renderedAt < self::MIN_FILL_SECONDS;
    }

    /**
     * The session's first-touch attribution; empty if analytics fails (the form must always work).
     *
     * @return array<string, string>
     */
    protected function captureAttribution(): array
    {
        try {
            return app(Attribution::class)->current(request());
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }
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
