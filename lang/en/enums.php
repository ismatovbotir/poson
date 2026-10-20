<?php

return [

    'attribute_type' => [
        'text' => 'Text',
        'number' => 'Number',
        'boolean' => 'Yes / no',
        'select' => 'List (one value)',
        'multiselect' => 'List (several values)',
    ],

    'document_type' => [
        'datasheet' => 'Datasheet',
        'manual' => 'Manual',
        'driver' => 'Driver',
        'firmware' => 'Firmware',
        'certificate' => 'Certificate',
    ],

    'price_level' => [
        'retail' => 'Retail',
        'dealer' => 'Dealer',
        'wholesale' => 'Wholesale',
    ],

    'product_type' => [
        'hardware' => 'Hardware',
        'consumable' => 'Consumable',
        'software' => 'Software',
        'service' => 'Service',
    ],

    'product_unit' => [
        'piece' => 'pcs',
        'roll' => 'roll',
        'box' => 'box',
        'pack' => 'pack',
        'license' => 'licence',
        'hour' => 'hour',
    ],

    'relation_type' => [
        'accessory' => 'Accessory',
        'consumable' => 'Consumable',
        'alternative' => 'Alternative',
        'software' => 'Software',
    ],

    'lead_source' => [
        'site' => 'Website',
        'telegram' => 'Telegram',
        'phone' => 'Phone',
        'manual' => 'Manual',
    ],

    'lead_type' => [
        'callback' => 'Callback',
        'quote' => 'Quote request',
        'service_request' => 'Service request',
        'checkout' => 'Checkout',
    ],

    'product_badge' => [
        'hit' => 'Bestseller',
        'new' => 'New',
    ],

    'role' => [
        'admin' => 'Administrator',
        'content_manager' => 'Content manager',
        'sales_manager' => 'Sales manager',
        'service_engineer' => 'Service engineer',
    ],

    'sale_mode' => [
        'cart' => 'Cart',
        'quote' => 'Quote request',
        'both' => 'Cart and quote request',
    ],

    'stock_status' => [
        'in_stock' => 'In stock',
        'on_order' => 'On order',
        'out_of_stock' => 'Out of stock',
    ],

];
