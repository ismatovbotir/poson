<?php

/*
| Cart and checkout (plan 11). Brand rule (CLAUDE.md "Brand positioning"):
| service wording only; prices are shown plainly.
*/

return [

    'cart' => [
        'title' => 'Cart',
        'checkout' => 'Checkout',
        'seo_description' => 'Products in your Poson cart.',
        'lines' => ':count product|:count products',
        'quantity' => 'Quantity',
        'decrease' => 'Decrease quantity',
        'increase' => 'Increase quantity',
        'min_hint' => 'Minimum :count :unit',
        'added' => 'Added to cart',
        'go_to_cart' => 'Go to cart',
        'empty_title' => 'Your cart is empty',
        'empty_text' => 'Choose equipment in the catalog: our specialists will help you set it up and get it running.',
        'to_catalog' => 'Go to the catalog',
        'unavailable' => 'No longer available',
        'unavailable_product' => 'Product no longer available',
        'unavailable_hint' => 'Remove this product from the cart, or request a quote for it.',
        'on_order_note' => 'Lead time is confirmed by the manager',
        'per_roll' => ':price / roll',
        'per_unit' => ':price / :unit',
        'remove' => 'Remove',
        'summary' => 'Summary',
        'total' => 'Total',
        'vat_included' => 'Prices include VAT.',
        'remove_unavailable' => 'Remove the products that are no longer available.',
        'benefits' => [
            'warranty' => 'Official warranty and our own service centre',
            'setup' => 'Equipment setup and integration',
            'delivery' => 'Reliable stock and delivery',
        ],
        'quote' => [
            'button' => 'Request a quote for this cart',
            'title' => 'A quote for your cart',
            'text' => 'Leave your contacts: a manager will prepare a quote for the products in your cart and confirm availability and delivery times.',
            'success_text' => 'A manager will contact you shortly about the products in your cart.',
            'message_placeholder' => 'Comment: delivery address, timing, extra requirements',
            'submit' => 'Request a quote',
        ],
        'errors' => [
            'disabled' => 'The cart is not available right now. Request a quote and we will help.',
            'unavailable' => 'This product can’t be added to the cart. Request a quote and we will help.',
            'quantity' => 'Choose a quantity from :min to :max.',
            'too_many_lines' => 'The cart holds up to :max products. Request a quote for a larger order.',
            'line_missing' => 'This product is no longer in your cart.',
            'closed' => 'An order has already been placed from this cart.',
            'rate_limited' => 'Too many attempts. Please try again in :seconds seconds.',
            'nothing_to_quote' => 'Your cart has no products to request a quote for.',
        ],
    ],

    'title' => 'Checkout',
    'seo_description' => 'Place your order with Poson.',
    'back_to_cart' => 'Back to the cart',
    'place_order' => 'Place order',

    'sections' => [
        'buyer' => 'Buyer',
        'delivery' => 'Delivery',
        'payment' => 'Payment',
    ],

    'fields' => [
        'buyer_type' => 'Buyer',
        'name' => 'Your name',
        'phone' => 'Phone',
        'company_name' => 'Company name',
        'tin' => 'Company STIR',
        'tin_or_pinfl' => 'STIR or PINFL',
        'delivery_type' => 'Delivery',
        'region' => 'Region',
        'region_placeholder' => 'Choose a region',
        'address' => 'Delivery address',
        'kokand_confirmed' => 'The address is in Kokand city',
        'comment' => 'Comment',
        'comment_placeholder' => 'Convenient time, entrance, extra requirements',
        'payment_method' => 'Payment method',
    ],

    'hints' => [
        'stir' => '9 digits, starts with 2 or 3.',
        'stir_or_pinfl' => 'STIR: 9 digits starting with 4–6. PINFL: 14 digits.',
    ],

    'delivery' => [
        'hints' => [
            'pickup' => 'From our office and service centre in Kokand.',
            'courier_kokand' => 'Delivery in Kokand: 0 soʻm. The manager confirms the address by phone.',
            'courier_region' => 'To your region; the price per region is shown below.',
        ],
        'by_manager' => 'confirmed by the manager',
        'kokand_line' => 'Delivery in Kokand',
    ],

    'payment' => [
        'none' => 'No payment method fits this order. Request a quote from the cart and we will help.',
        'hints' => [
            'payme' => 'Online with a card in Payme.',
            'click' => 'Online with a card in Click.',
            'uzqr_static' => 'Scan our UzQR code in your bank app on the order page.',
            'on_receipt' => 'Cash, card or UzQR when you receive the order.',
            'bank_transfer' => 'Invoice for companies and sole proprietors.',
            'uzqr_dynamic' => 'UzQR.',
        ],
    ],

    'summary' => [
        'goods' => 'Goods',
        'delivery' => 'Delivery',
        'vat' => 'Prices include VAT :percent%.',
    ],

    'errors' => [
        'empty' => 'Your cart is empty.',
        'invalid_lines' => 'Some products are no longer available. Remove them in the cart.',
        'changed' => 'Prices or availability were updated, please review your order.',
        'total_too_high' => 'This order is larger than we accept online. Request a quote from the cart.',
        'payment_unavailable' => 'This payment method is not available for this order.',
        'kokand_confirm' => 'Confirm that the address is in Kokand city.',
        'try_again' => 'Please check the form and try again.',
        'rate_limited' => 'Too many attempts. Please try again in :seconds seconds.',
    ],

    'settings' => [
        'title' => 'Checkout',
        'subtitle' => 'Delivery prices, order limits, fiscal codes and the UzQR code.',
        'regions_title' => 'Courier to the regions',
        'regions_hint' => 'Price per region in whole soʻm. Empty: confirmed by the manager and billed separately.',
        'by_manager' => 'billed separately',
        'kokand_note' => 'Pickup and courier in Kokand: 0 soʻm.',
        'limits_title' => 'Order limits',
        'max_total' => 'Largest order total, soʻm',
        'max_online_total' => 'Largest online payment (Payme / Click), soʻm',
        'max_delivery_charge' => 'Largest delivery charge, soʻm',
        'max_manual_discount_percent' => 'Manual price floor, %',
        'fiscal_title' => 'Fiscal codes',
        'fiscal_hint' => 'Payme and Click receipts need an IKPU on every line.',
        'delivery_ikpu' => 'Delivery IKPU',
        'delivery_package_code' => 'Delivery package code',
        'require_ikpu_online' => 'Hide Payme / Click when a line has no IKPU',
        'uzqr_title' => 'Static UzQR',
        'uzqr_hint' => 'The company’s UzQR code. Customers pay the exact amount; staff confirm against the bank statement. Every change alerts the finance and sales chats.',
        'uzqr_recipient' => 'Recipient name',
        'uzqr' => 'UzQR code image',
        'hints' => [
            'max_total' => 'Orders above this total can’t be placed.',
            'max_online_total' => 'Above it Payme and Click are not offered.',
            'max_delivery_charge' => 'Upper limit of a separately billed delivery.',
            'max_manual_discount_percent' => 'How far below the customer’s level price a sales manager may set a price on a manual order. 0: never (only an Admin).',
            'ikpu' => '17 digits from tasnif.soliq.uz.',
            'package_code' => 'Package (unit) code from tasnif.soliq.uz.',
            'require_ikpu_online' => 'Recommended: receipts without an IKPU are rejected by the tax service.',
            'uzqr_recipient' => 'Shown next to the code, e.g. the company’s legal name.',
            'uzqr' => 'PNG, JPG or WebP up to 1 MB, 100–2000 px. Re-encoded to PNG; the previous code is kept for the audit.',
        ],
        'errors' => [
            'animated' => 'Animated images are not accepted.',
        ],
    ],

];
