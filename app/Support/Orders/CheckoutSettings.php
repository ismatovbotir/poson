<?php

namespace App\Support\Orders;

use App\Enums\Region;
use App\Models\Setting;
use App\Support\CompanySettings;

/**
 * Settings → Checkout (plan 11 v2 §2, Admin only, audited): the "checkout"
 * settings row over config('checkout.defaults'), read through the cached
 * CompanySettings overrides. Every value is re-validated here, so a bad row
 * can never widen a cap; money comes out in integer tiyin.
 *
 * The static UzQR image is a private media row (Setting::UZQR), never a URL.
 */
class CheckoutSettings
{
    public const KEY = 'checkout';

    public const IKPU = '/^\d{17}$/';

    public const PACKAGE_CODE = '/^\d{1,20}$/';

    /**
     * Upper bound of every money setting, whole soʻm (10 trillion).
     */
    public const MAX_SOM = 10_000_000_000_000;

    public function __construct(protected CompanySettings $company) {}

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        $stored = $this->company->get(self::KEY);

        return array_replace((array) config('checkout.defaults', []), is_array($stored) ? $stored : []);
    }

    /**
     * The fixed courier fee of a region in tiyin, or null (billed separately).
     */
    public function regionFee(Region $region): ?int
    {
        $fees = $this->raw()['region_fees'] ?? [];
        $fee = is_array($fees) ? ($fees[$region->value] ?? null) : null;

        return is_int($fee) && $fee >= 0 && $fee <= self::MAX_SOM ? $fee * 100 : null;
    }

    /**
     * Region value => fee in whole soʻm (only the regions with a fee).
     *
     * @return array<string, int>
     */
    public function regionFeesInSom(): array
    {
        $fees = [];

        foreach (Region::cases() as $region) {
            $fee = $this->regionFee($region);

            if ($fee !== null) {
                $fees[$region->value] = intdiv($fee, 100);
            }
        }

        return $fees;
    }

    public function maxTotal(): int
    {
        return $this->money('max_total');
    }

    public function maxOnlineTotal(): int
    {
        return min($this->money('max_online_total'), $this->maxTotal());
    }

    public function maxDeliveryCharge(): int
    {
        return $this->money('max_delivery_charge');
    }

    public function maxManualDiscountPercent(): int
    {
        $value = $this->raw()['max_manual_discount_percent'] ?? 0;

        return is_int($value) ? max(0, min(100, $value)) : 0;
    }

    public function deliveryIkpu(): ?string
    {
        $value = $this->raw()['delivery_ikpu'] ?? null;

        return is_string($value) && preg_match(self::IKPU, $value) ? $value : null;
    }

    public function deliveryPackageCode(): ?string
    {
        $value = $this->raw()['delivery_package_code'] ?? null;

        return is_string($value) && preg_match(self::PACKAGE_CODE, $value) ? $value : null;
    }

    public function requireIkpuOnline(): bool
    {
        return ($this->raw()['require_ikpu_online'] ?? true) !== false;
    }

    public function uzqrRecipient(): string
    {
        $value = $this->raw()['uzqr_recipient'] ?? '';

        return is_string($value) ? trim(mb_substr($value, 0, 160)) : '';
    }

    /**
     * The current static UzQR code as a PNG data URI (private media), or null.
     */
    public function uzqrDataUri(): ?string
    {
        return Setting::privateImageDataUri(Setting::UZQR);
    }

    /**
     * Static UzQR can be offered: an image is uploaded.
     */
    public function hasUzqr(): bool
    {
        return Setting::query()
            ->where('key', Setting::UZQR)
            ->whereHas('media', fn ($query) => $query->where('collection_name', Setting::UZQR))
            ->exists();
    }

    private function money(string $key): int
    {
        $value = $this->raw()[$key] ?? null;
        $default = config("checkout.defaults.{$key}");

        if (! is_int($value) || $value < 0 || $value > self::MAX_SOM) {
            $value = is_int($default) ? $default : 0;
        }

        return $value * 100;
    }
}
