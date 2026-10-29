<?php

/*
|--------------------------------------------------------------------------
| Checkout and orders (plan 11 v2)
|--------------------------------------------------------------------------
|
| "defaults" are the Settings → Checkout values until an Admin saves them
| (settings key "checkout", App\Support\Orders\CheckoutSettings). Money in
| whole soʻm here; the code works in tiyin.
|
*/

return [

    'defaults' => [
        // Region value (App\Enums\Region) => courier fee in whole soʻm. A region
        // without a fee: delivery is confirmed by the manager and billed separately.
        'region_fees' => [],
        // Highest order total (any method), whole soʻm.
        'max_total' => 1_000_000_000,
        // Highest order total payable online (Payme / Click), whole soʻm.
        'max_online_total' => 50_000_000,
        // Highest separately billed delivery charge, whole soʻm.
        'max_delivery_charge' => 5_000_000,
        // How far below the customer's level price (or Dealer) a non-Admin may
        // set a manual order line price, percent (0 = not at all).
        'max_manual_discount_percent' => 0,
        // Fiscal codes of the delivery line (Payme / Click receipts).
        'delivery_ikpu' => '',
        'delivery_package_code' => '',
        // Hide Payme / Click unless every line (and a fixed delivery) has an IKPU.
        'require_ikpu_online' => true,
        // Recipient name shown next to the static UzQR code.
        'uzqr_recipient' => '',
    ],

    // Days a customer's /o/{ulid} link stays valid after placing / renewing.
    'public_link_days' => 90,

    // Most "Renew link" actions per order and Tashkent day.
    'link_renewals_per_day' => 10,

    // Working days an invoice is valid.
    'invoice_valid_working_days' => 5,

    // Provider => class implementing App\Support\Orders\Contracts\PaymentHold,
    // asked by Order::hasActiveTransaction(). A provider is never offered
    // without its hold class (fail closed): 11c adds 'payme', 11d 'click',
    // 11e 'uzqr' (dynamic UzQR).
    'payment_holds' => [],

    // Active online payment holds (plans 11c/11d): Payme state 1 < 12 h, Click prepared < 60 min.
    'holds' => [
        'payme_hours' => 12,
        'click_minutes' => 60,
    ],

    // Checkout spam limits: per IP (attempts per 10 minutes) and per phone (per day).
    'limits' => [
        'per_ip' => 5,
        'per_ip_minutes' => 10,
        'per_phone' => 10,
    ],

];
