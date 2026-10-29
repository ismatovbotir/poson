<?php

namespace App\Livewire\Forms\Admin\Settings;

use App\Enums\Region;
use App\Rules\BrandSafeText;
use App\Support\Money;
use App\Support\Orders\CheckoutSettings;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;

/**
 * Settings → Checkout (plan 11 v2 §2): courier fee per region (whole soʻm,
 * empty = billed separately), the order caps, the manual price floor, the
 * delivery line's fiscal codes, the IKPU rule for Payme / Click and the
 * static UzQR recipient + code. Money is typed in whole soʻm and stored as
 * whole soʻm integers (CheckoutSettings converts to tiyin).
 */
class CheckoutForm extends Form
{
    /**
     * Region value => fee in whole soʻm ('' = billed separately).
     *
     * @var array<string, string>
     */
    public array $region_fees = [];

    public string $max_total = '';

    public string $max_online_total = '';

    public string $max_delivery_charge = '';

    public string $max_manual_discount_percent = '0';

    public string $delivery_ikpu = '';

    public string $delivery_package_code = '';

    public bool $require_ikpu_online = true;

    public string $uzqr_recipient = '';

    /** @var TemporaryUploadedFile|null */
    public $uzqr = null;

    public bool $remove_uzqr = false;

    public function fillFrom(CheckoutSettings $settings): void
    {
        $fees = $settings->regionFeesInSom();

        foreach (Region::cases() as $region) {
            $this->region_fees[$region->value] = isset($fees[$region->value]) ? number_format($fees[$region->value], 0, '', ' ') : '';
        }

        $this->max_total = Money::tiyinToSums($settings->maxTotal());
        $this->max_online_total = Money::tiyinToSums($settings->maxOnlineTotal());
        $this->max_delivery_charge = Money::tiyinToSums($settings->maxDeliveryCharge());
        $this->max_manual_discount_percent = (string) $settings->maxManualDiscountPercent();
        $this->delivery_ikpu = (string) $settings->deliveryIkpu();
        $this->delivery_package_code = (string) $settings->deliveryPackageCode();
        $this->require_ikpu_online = $settings->requireIkpuOnline();
        $this->uzqr_recipient = $settings->uzqrRecipient();
        $this->uzqr = null;
        $this->remove_uzqr = false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $som = ['string', 'max:20', 'regex:/^[\d\s\x{00A0}]+$/u'];

        return [
            'region_fees' => ['array'],
            'region_fees.*' => ['nullable', ...$som],
            'max_total' => ['required', ...$som],
            'max_online_total' => ['required', ...$som],
            'max_delivery_charge' => ['required', ...$som],
            'max_manual_discount_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'delivery_ikpu' => ['nullable', 'regex:'.CheckoutSettings::IKPU],
            'delivery_package_code' => ['nullable', 'regex:'.CheckoutSettings::PACKAGE_CODE],
            'require_ikpu_online' => ['boolean'],
            'uzqr_recipient' => ['nullable', 'string', 'max:160', new BrandSafeText],
            'uzqr' => ['nullable', 'file', 'max:1024', 'mimes:png,jpg,jpeg,webp'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            'max_total' => __('checkout.settings.max_total'),
            'max_online_total' => __('checkout.settings.max_online_total'),
            'max_delivery_charge' => __('checkout.settings.max_delivery_charge'),
            'max_manual_discount_percent' => __('checkout.settings.max_manual_discount_percent'),
            'delivery_ikpu' => __('checkout.settings.delivery_ikpu'),
            'delivery_package_code' => __('checkout.settings.delivery_package_code'),
            'uzqr_recipient' => __('checkout.settings.uzqr_recipient'),
            'uzqr' => __('checkout.settings.uzqr'),
        ];

        foreach (Region::cases() as $region) {
            $attributes['region_fees.'.$region->value] = $region->label();
        }

        return $attributes;
    }

    /**
     * The "checkout" settings value (whole soʻm integers), after validate().
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $som = fn (string $value): ?int => ($tiyin = Money::sumsToTiyin($value)) !== null ? min(intdiv($tiyin, 100), CheckoutSettings::MAX_SOM) : null;
        $fees = [];

        foreach (Region::cases() as $region) {
            $fee = $som((string) ($this->region_fees[$region->value] ?? ''));

            if ($fee !== null) {
                $fees[$region->value] = $fee;
            }
        }

        $maxTotal = (int) $som($this->max_total);

        return [
            'region_fees' => $fees,
            'max_total' => $maxTotal,
            'max_online_total' => min((int) $som($this->max_online_total), $maxTotal),
            'max_delivery_charge' => (int) $som($this->max_delivery_charge),
            'max_manual_discount_percent' => (int) $this->max_manual_discount_percent,
            'delivery_ikpu' => trim($this->delivery_ikpu),
            'delivery_package_code' => trim($this->delivery_package_code),
            'require_ikpu_online' => $this->require_ikpu_online,
            'uzqr_recipient' => trim($this->uzqr_recipient),
        ];
    }
}
