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
        'checkout' => 'Buyurtmani rasmiylashtirish',
    ],

    'lead_status' => [
        'new' => 'Yangi',
        'in_progress' => 'Jarayonda',
        'qualified' => 'Saralangan',
        'converted' => 'Mijozga aylangan',
        'lost' => 'Yoʻqotilgan',
    ],

    'lead_lost_reason' => [
        'no_response' => 'Javob bermadi',
        'not_relevant' => 'Dolzarb emas',
        'bought_elsewhere' => 'Boshqa joydan xarid qildi',
        'out_of_stock' => 'Mahsulot mavjud emas',
        'budget' => 'Byudjet yetmadi',
        'duplicate' => 'Takroriy murojaat',
        'spam' => 'Spam',
        'other' => 'Boshqa',
    ],

    'customer_type' => [
        'company' => 'Yuridik shaxs',
        'individual' => 'Jismoniy shaxs',
    ],

    'activity_type' => [
        'created' => 'Yaratildi',
        'note' => 'Izoh',
        'call' => 'Qoʻngʻiroq',
        'status_change' => 'Holat oʻzgarishi',
        'assignment' => 'Biriktirish',
        'notification' => 'Bildirishnoma',
        'conversion' => 'Mijozga aylantirish',
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

    'phone_label' => [
        'orders' => 'Savdo boʻlimi',
        'service' => 'Servis markazi',
        'accounting' => 'Buxgalteriya',
        'office' => 'Ofis',
    ],

    'social_network' => [
        'telegram' => 'Telegram',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
        'youtube' => 'YouTube',
        'linkedin' => 'LinkedIn',
        'tiktok' => 'TikTok',
        'whatsapp' => 'WhatsApp',
    ],

    'settings_area' => [
        'contacts' => 'Kontaktlar',
        'legal' => 'Rekvizitlar',
        'social' => 'Ijtimoiy tarmoqlar',
        'hero' => 'Bosh ekran',
        'seo' => 'SEO',
        'tracking' => 'Analitika',
        'ai_bots' => 'SI botlar',
        'faq' => 'Koʻp beriladigan savollar',
        'integrations' => 'Integratsiyalar',
    ],

    'audit_action' => [
        'updated' => 'Oʻzgartirildi',
        'cleared' => 'Tozalandi',
        'uploaded' => 'Yuklandi',
        'removed' => 'Oʻchirildi',
        'generated' => 'Yaratildi',
    ],

    'payme_mode' => [
        'test' => 'Sinov rejimi',
        'prod' => 'Ishchi rejim',
    ],

    'integration_source' => [
        'env' => '.env fayli',
        'db' => 'Admin panel',
        'default' => 'Standart',
        'none' => 'Kiritilmagan',
    ],

];
