<?php

namespace App\Data\Orders;

/**
 * What the buyer typed on the checkout page (raw strings; PlaceOrder parses
 * the enums with tryFrom() and validates every field, security condition 7).
 * fingerprint is the CartPricer + delivery fingerprint the page was
 * rendered with (condition 6). attribution / trafficChannel: the session's
 * sanitized first-touch analytics (no PII).
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
    ) {}
}
