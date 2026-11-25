<?php

namespace App\Data\Orders;

/**
 * What the buyer typed on the checkout page (raw strings; PlaceOrder parses
 * the enums with tryFrom() and validates every field, security condition 7).
 * fingerprint is the CartPricer + delivery fingerprint the page was
 * rendered with (condition 6). attribution / trafficChannel: the session's
 * sanitized first-touch analytics (no PII). yclid / ymClientId: Yandex click
 * ids (plan 40, AdClickIds::capture()), re-validated by PlaceOrder.
 */
final readonly class CheckoutData
{
    /**
     * @param  array<string, string>  $attribution
     */
    public function __construct(
        public string $name,
        public string $phone,
        public string $buyerType,
        public string $companyName,
        public string $tin,
        public string $deliveryType,
        public string $region,
        public string $address,
        public bool $kokandConfirmed,
        public string $comment,
        public string $paymentMethod,
        public string $locale,
        public string $fingerprint,
        public array $attribution = [],
        public ?string $trafficChannel = null,
        public ?string $yclid = null,
        public ?string $ymClientId = null,
    ) {}

    /**
     * A copy with some fields replaced (named constructor arguments). Every
     * other field is carried over as it is, including fields added later.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * A copy with another phone (the Telegram Mini App's registration phone, plan 20).
     */
    public function withPhone(string $phone): self
    {
        return $this->with(['phone' => $phone]);
    }
}
