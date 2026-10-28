<?php

/*
| Cart and checkout (plan 11). Brand rule (CLAUDE.md "Brand positioning"):
| service wording only; prices are shown plainly.
*/

return [

    'cart' => [
        'title' => 'Cart',
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

];
