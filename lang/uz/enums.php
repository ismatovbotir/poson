<?php

return [

    'attribute_type' => [
        'text' => 'Matn',
        'number' => 'Raqam',
        'boolean' => 'Ha / yoʻq',
        'select' => 'Roʻyxat (bitta qiymat)',
        'multiselect' => 'Roʻyxat (bir nechta qiymat)',
    ],

    'document_type' => [
        'datasheet' => 'Texnik maʼlumotnoma',
        'manual' => 'Qoʻllanma',
        'driver' => 'Drayver',
        'firmware' => 'Proshivka',
        'certificate' => 'Sertifikat',
    ],

    'price_level' => [
        'retail' => 'Chakana',
        'dealer' => 'Diler',
        'wholesale' => 'Ulgurji',
    ],

    'product_type' => [
        'hardware' => 'Uskuna',
        'consumable' => 'Sarf material',
        'software' => 'Dasturiy taʼminot',
        'service' => 'Xizmat',
    ],

    'product_unit' => [
        'piece' => 'dona',
        'roll' => 'rulon',
        'box' => 'quti',
        'pack' => 'qadoq',
        'license' => 'litsenziya',
        'hour' => 'soat',
    ],

    'relation_type' => [
        'accessory' => 'Aksessuar',
        'consumable' => 'Sarf material',
        'alternative' => 'Muqobil',
        'software' => 'Dasturiy taʼminot',
    ],

    'lead_source' => [
        'site' => 'Sayt',
        'telegram' => 'Telegram',
        'phone' => 'Telefon',
        'manual' => 'Qoʻlda',
    ],

    'lead_type' => [
        'callback' => 'Qayta qoʻngʻiroq',
        'quote' => 'Tijorat taklifi soʻrovi',
        'service_request' => 'Servis soʻrovi',
        'checkout' => 'Buyurtma rasmiylashtirish',
    ],

    'product_badge' => [
        'hit' => 'Xit',
        'new' => 'Yangi',
    ],

    'role' => [
        'admin' => 'Administrator',
        'content_manager' => 'Kontent menejer',
        'sales_manager' => 'Sotuv menejeri',
        'service_engineer' => 'Servis muhandisi',
    ],

    'sale_mode' => [
        'cart' => 'Savat',
        'quote' => 'Tijorat taklifi soʻrovi',
        'both' => 'Savat va tijorat taklifi soʻrovi',
    ],

    'stock_status' => [
        'in_stock' => 'Mavjud',
        'on_order' => 'Buyurtma asosida',
        'out_of_stock' => 'Mavjud emas',
    ],

];
