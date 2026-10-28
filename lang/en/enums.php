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
        'license' => 'license',
        'hour' => 'hour',
    ],

    'product_pricing_unit' => [
        'unit' => 'Per unit',
        'label' => 'Per label',
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

    'lead_status' => [
        'new' => 'New',
        'in_progress' => 'In progress',
        'qualified' => 'Qualified',
        'converted' => 'Converted',
        'lost' => 'Lost',
    ],

    'lead_lost_reason' => [
        'no_response' => 'No response',
        'not_relevant' => 'Not relevant',
        'bought_elsewhere' => 'Bought elsewhere',
        'out_of_stock' => 'Out of stock',
        'budget' => 'Over budget',
        'duplicate' => 'Duplicate',
        'spam' => 'Spam',
        'other' => 'Other',
    ],

    'customer_type' => [
        'company' => 'Company',
        'individual' => 'Individual',
    ],

    'activity_type' => [
        'created' => 'Created',
        'note' => 'Note',
        'call' => 'Call',
        'status_change' => 'Status change',
        'assignment' => 'Assignment',
        'notification' => 'Notification',
        'conversion' => 'Conversion',
        'price_level_change' => 'Price level change',
        'quote' => 'Quote',
    ],

    'quote_status' => [
        'draft' => 'Draft',
        'sent' => 'Sent',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
    ],

    'page_template' => [
        'default' => 'Standard',
        'contacts' => 'Contacts',
        'legal' => 'Legal document',
    ],

    'news_kind' => [
        'news' => 'News',
        'article' => 'Article',
    ],

    'news_status' => [
        'draft' => 'Draft',
        'published' => 'Published',
    ],

    'news_state' => [
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'published' => 'Published',
    ],

    'banner_placement' => [
        'home_hero' => 'Home slider',
        'category' => 'Category',
    ],

    'banner_state' => [
        'active' => 'Shown',
        'scheduled' => 'Scheduled',
        'expired' => 'Ended',
        'inactive' => 'Off',
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

    'phone_label' => [
        'orders' => 'Orders and advice',
        'service' => 'Service center',
        'accounting' => 'Accounting',
        'office' => 'Office',
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
        'contacts' => 'Contacts',
        'legal' => 'Legal details',
        'social' => 'Social',
        'hero' => 'Home hero',
        'seo' => 'SEO',
        'tracking' => 'Analytics',
        'ai_bots' => 'AI bots',
        'faq' => 'FAQ',
        'integrations' => 'Integrations',
        'api_tokens' => 'API tokens',
        'price_sync' => 'Price sync',
    ],

    'audit_action' => [
        'updated' => 'Updated',
        'cleared' => 'Cleared',
        'uploaded' => 'Uploaded',
        'removed' => 'Removed',
        'generated' => 'Generated',
        'created' => 'Created',
        'revoked' => 'Revoked',
        'unlinked' => 'Unlinked',
    ],

    'price_sync_status' => [
        'success' => 'Applied',
        'partial' => 'Partly applied',
        'rejected' => 'Rejected',
    ],

    'payme_mode' => [
        'test' => 'Test',
        'prod' => 'Live',
    ],

    'integration_source' => [
        'env' => '.env file',
        'db' => 'Admin panel',
        'default' => 'Default',
        'none' => 'Not set',
    ],

];
