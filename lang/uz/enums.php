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

    'product_pricing_unit' => [
        'unit' => 'Birlik boʻyicha',
        'label' => 'Yorliq boʻyicha',
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
        'price_level_change' => 'Narx darajasi oʻzgarishi',
        'quote' => 'Tijorat taklifi',
        'order' => 'Buyurtma',
        'payment' => 'Toʻlov',
    ],

    'quote_status' => [
        'draft' => 'Qoralama',
        'sent' => 'Yuborilgan',
        'accepted' => 'Qabul qilingan',
        'rejected' => 'Rad etilgan',
        'expired' => 'Muddati oʻtgan',
    ],

    'page_template' => [
        'default' => 'Oddiy',
        'contacts' => 'Kontaktlar',
        'legal' => 'Yuridik hujjat',
    ],

    'news_kind' => [
        'news' => 'Yangilik',
        'article' => 'Maqola',
    ],

    'news_status' => [
        'draft' => 'Qoralama',
        'published' => 'Eʼlon qilingan',
    ],

    'news_state' => [
        'draft' => 'Qoralama',
        'scheduled' => 'Rejalashtirilgan',
        'published' => 'Eʼlon qilingan',
    ],

    'banner_placement' => [
        'home_hero' => 'Bosh sahifa slayderi',
        'category' => 'Kategoriya',
    ],

    'banner_state' => [
        'active' => 'Koʻrsatilmoqda',
        'scheduled' => 'Rejalashtirilgan',
        'expired' => 'Tugagan',
        'inactive' => 'Oʻchirilgan',
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
        'api_tokens' => 'API tokenlar',
        'price_sync' => 'Narxlar sinxronizatsiyasi',
        'checkout' => 'Buyurtma rasmiylashtirish',
        'indexing' => 'Saytni indekslash',
        'mcp' => 'MCP kirish',
    ],

    'audit_action' => [
        'updated' => 'Oʻzgartirildi',
        'cleared' => 'Tozalandi',
        'uploaded' => 'Yuklandi',
        'removed' => 'Oʻchirildi',
        'generated' => 'Yaratildi',
        'created' => 'Yaratildi',
        'revoked' => 'Bekor qilindi',
        'unlinked' => 'Ajratildi',
        'opened' => 'Ochildi',
        'closed' => 'Yopildi',
        'granted' => 'Berildi',
        'transferred' => 'Oʻtkazildi',
    ],

    'price_sync_status' => [
        'success' => 'Qoʻllandi',
        'partial' => 'Qisman qoʻllandi',
        'rejected' => 'Rad etildi',
    ],

    'uzqr_transaction_status' => [
        'creating' => 'Yaratilmoqda',
        'pending' => 'Toʻlov kutilmoqda',
        'paid' => 'Toʻlangan',
        'failed' => 'Yopilgan',
        'expired' => 'Muddati tugagan',
        'create_failed' => 'Yaratilmadi',
        'released' => 'Band qilish olib tashlandi',
    ],

    'uzqr_settlement' => [
        'settled' => 'Hisoblandi',
        'unmatched' => 'Hisoblanmadi',
    ],

    'payment_mode' => [
        'test' => 'Sinov rejimi',
        'prod' => 'Ishchi rejim',
    ],

    'integration_source' => [
        'env' => '.env fayli',
        'db' => 'Admin panel',
        'default' => 'Standart',
        'none' => 'Kiritilmagan',
    ],

    'order_status' => [
        'new' => 'Yangi',
        'confirmed' => 'Tasdiqlangan',
        'processing' => 'Yigʻilmoqda',
        'shipped' => 'Joʻnatilgan',
        'completed' => 'Yakunlangan',
        'cancelled' => 'Bekor qilingan',
    ],

    'payment_status' => [
        'unpaid' => 'Toʻlanmagan',
        'pending' => 'Toʻlov jarayonida',
        'paid' => 'Toʻlangan',
        'refunded' => 'Qaytarilgan',
    ],

    'payment_method' => [
        'payme' => 'Payme',
        'click' => 'Click',
        'uzqr_static' => 'UzQR (statik kod)',
        'on_receipt' => 'Olganda toʻlash',
        'bank_transfer' => 'Bank oʻtkazmasi',
        'uzqr_dynamic' => 'UzQR orqali toʻlov',
    ],

    'receipt_channel' => [
        'cash' => 'Naqd',
        'card' => 'Karta',
        'uzqr' => 'UzQR',
    ],

    'order_source' => [
        'site' => 'Sayt',
        'quote' => 'Tijorat taklifi',
        'manual' => 'Qoʻlda',
    ],

    'buyer_type' => [
        'individual' => 'Jismoniy shaxs',
        'company' => 'Kompaniya',
        'sole_proprietor' => 'YaTT',
    ],

    'delivery_type' => [
        'pickup' => 'Olib ketish',
        'courier_kokand' => 'Qoʻqon boʻylab kuryer',
        'courier_region' => 'Viloyatga kuryer',
    ],

    'delivery_pricing' => [
        'fixed' => 'Summaga kiritilgan',
        'separate' => 'Alohida toʻlanadi',
    ],

    'region' => [
        'tashkent_city' => 'Toshkent shahri',
        'tashkent' => 'Toshkent viloyati',
        'andijan' => 'Andijon viloyati',
        'bukhara' => 'Buxoro viloyati',
        'fergana' => 'Fargʻona viloyati',
        'jizzakh' => 'Jizzax viloyati',
        'kashkadarya' => 'Qashqadaryo viloyati',
        'khorezm' => 'Xorazm viloyati',
        'namangan' => 'Namangan viloyati',
        'navoi' => 'Navoiy viloyati',
        'samarkand' => 'Samarqand viloyati',
        'surkhandarya' => 'Surxondaryo viloyati',
        'syrdarya' => 'Sirdaryo viloyati',
        'karakalpakstan' => 'Qoraqalpogʻiston Respublikasi',
    ],

    'order_payment_purpose' => [
        'goods' => 'Mahsulotlar',
        'delivery' => 'Yetkazib berish',
    ],

    'order_payment_status' => [
        'pending' => 'Toʻlov kutilmoqda',
        'paid' => 'Toʻlangan',
        'refunded' => 'Qaytarilgan',
        'cancelled' => 'Bekor qilingan',
    ],

    'api_token_type' => [
        'price_api' => 'Narxlar API',
        'mcp' => 'MCP',
    ],

    'mcp_call_status' => [
        'ok' => 'Muvaffaqiyatli',
        'invalid' => 'Notoʻgʻri maʼlumot',
        'denied' => 'Rad etildi',
        'rate_limited' => 'Limit oshdi',
        'error' => 'Xato',
    ],

    'mcp_revoke_reason' => [
        'admin' => 'Administrator bekor qilgan',
        'revoke_all' => 'Barcha MCP kirish bekor qilingan',
        'regenerated' => 'Qayta yaratilgan',
        'transferred' => 'Kirish oʻtkazilgan',
        'deactivated' => 'Foydalanuvchi oʻchirilgan',
        'password_changed' => 'Parol oʻzgartirilgan',
        'role_lost' => 'Admin roli olib tashlangan',
        'idle' => 'Uzoq vaqt ishlatilmagan',
        'never_used' => '7 kun ichida ishlatilmagan',
    ],

    'mcp_deny_reason' => [
        'revoked_token' => 'Bekor qilingan token ishlatildi',
        'ip_not_allowed' => 'IP manzilga ruxsat yoʻq',
        'token' => 'Amaldagi MCP token yoʻq',
        'token_user' => 'Boshqa foydalanuvchining tokeni',
        'inactive' => 'Foydalanuvchi oʻchirilgan',
        'not_admin' => 'Admin emas',
        'no_access' => 'MCP kirish yoʻq',
        'not_single_holder' => 'Bittadan ortiq ega',
    ],

    'mcp_auth_type' => [
        'token' => 'Token',
    ],

];
