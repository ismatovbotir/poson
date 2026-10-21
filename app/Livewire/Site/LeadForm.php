<?php

namespace App\Livewire\Site;

use App\Actions\Leads\SubmitSiteLead;
use App\Data\Leads\SiteLeadData;
use App\Enums\LeadType;
use App\Models\Product;
use App\Rules\UzPhone;
use App\Support\Phone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
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

    public const UTM_MAX_LENGTH = 255;

    /**
     * @var list<string>
     */
    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

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
     * @var array<string, string>
     */
    #[Locked]
    public array $utm = [];

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
        $this->locale = app()->getLocale();
        $this->pageUrl = url()->current();
        $this->utm = $this->captureUtm();
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

    public function submit(SubmitSiteLead $submitSiteLead): void
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
            ));
        }

        $this->reset('name', 'phone', 'company', 'message', 'fax_ext');
        $this->sent = true;
    }

    public function render(): View
    {
        return view('livewire.site.lead-form');
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
        $product = Product::query()->visible()->whereKey($productId)->first(['id', 'sku', 'name', 'category_id', 'is_active']);

        return $product instanceof Product ? $product : null;
    }

    protected function looksLikeBot(): bool
    {
        return $this->fax_ext !== ''
            || now()->getTimestamp() - $this->renderedAt < self::MIN_FILL_SECONDS;
    }

    /**
     * UTM tags from the landing URL (remembered in the session), else from the session.
     *
     * @return array<string, string>
     */
    protected function captureUtm(): array
    {
        $fromQuery = static::sanitizeUtm(request()->query());

        if ($fromQuery !== []) {
            session()->put('utm', $fromQuery);

            return $fromQuery;
        }

        return static::sanitizeUtm(session('utm', []));
    }

    /**
     * Keep whitelisted keys with non-empty string values; strip control
     * characters and cut each value to UTM_MAX_LENGTH characters.
     *
     * @return array<string, string>
     */
    public static function sanitizeUtm(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $utm = [];

        foreach (self::UTM_KEYS as $key) {
            $value = $input[$key] ?? null;

            if (! is_string($value)) {
                continue;
            }

            $value = trim((string) preg_replace('/\p{Cc}+/u', '', $value));

            if ($value !== '') {
                $utm[$key] = mb_substr($value, 0, self::UTM_MAX_LENGTH);
            }
        }

        return $utm;
    }
}
