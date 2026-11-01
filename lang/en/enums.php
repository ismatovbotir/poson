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
        'order' => 'Order',
        'payment' => 'Payment',
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
        'checkout' => 'Checkout',
        'indexing' => 'Site indexing',
        'mcp' => 'MCP access',
        'catalog' => 'Catalog',
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
        'opened' => 'Opened',
        'closed' => 'Closed',
        'granted' => 'Granted',
        'transferred' => 'Transferred',
        'published' => 'Published',
        'unpublished' => 'Returned to drafts',
    ],

    'price_sync_status' => [
        'success' => 'Applied',
        'partial' => 'Partly applied',
        'rejected' => 'Rejected',
    ],

    'uzqr_transaction_status' => [
        'creating' => 'Creating',
        'pending' => 'Waiting for payment',
        'paid' => 'Paid',
        'failed' => 'Closed',
        'expired' => 'Expired',
        'create_failed' => 'Not created',
        'released' => 'Hold released',
    ],

    'uzqr_settlement' => [
        'settled' => 'Applied',
        'unmatched' => 'Not applied',
    ],

    'payment_mode' => [
        'test' => 'Test',
        'prod' => 'Live',
    ],

    'integration_source' => [
        'env' => '.env file',
        'db' => 'Admin panel',
        'default' => 'Default',
        'none' => 'Not set',
    ],

    'order_status' => [
        'new' => 'New',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],

    'payment_status' => [
        'unpaid' => 'Unpaid',
        'pending' => 'Payment in progress',
        'paid' => 'Paid',
        'refunded' => 'Refunded',
    ],

    'payment_method' => [
        'payme' => 'Payme',
        'click' => 'Click',
        'uzqr_static' => 'UzQR (static code)',
        'on_receipt' => 'On receipt',
        'bank_transfer' => 'Bank transfer',
        'uzqr_dynamic' => 'Pay with UzQR',
    ],

    'receipt_channel' => [
        'cash' => 'Cash',
        'card' => 'Card',
        'uzqr' => 'UzQR',
    ],

    'order_source' => [
        'site' => 'Site',
        'quote' => 'Quote',
        'manual' => 'Manual',
    ],

    'buyer_type' => [
        'individual' => 'Individual',
        'company' => 'Company',
        'sole_proprietor' => 'Sole proprietor (YaTT)',
    ],

    'delivery_type' => [
        'pickup' => 'Pickup',
        'courier_kokand' => 'Courier in Kokand',
        'courier_region' => 'Courier to a region',
    ],

    'delivery_pricing' => [
        'fixed' => 'In the total',
        'separate' => 'Billed separately',
    ],

    'region' => [
        'tashkent_city' => 'Tashkent city',
        'tashkent' => 'Tashkent region',
        'andijan' => 'Andijan region',
        'bukhara' => 'Bukhara region',
        'fergana' => 'Fergana region',
        'jizzakh' => 'Jizzakh region',
        'kashkadarya' => 'Kashkadarya region',
        'khorezm' => 'Khorezm region',
        'namangan' => 'Namangan region',
        'navoi' => 'Navoi region',
        'samarkand' => 'Samarkand region',
        'surkhandarya' => 'Surkhandarya region',
        'syrdarya' => 'Syrdarya region',
        'karakalpakstan' => 'Republic of Karakalpakstan',
    ],

    'order_payment_purpose' => [
        'goods' => 'Goods',
        'delivery' => 'Delivery',
    ],

    'order_payment_status' => [
        'pending' => 'Awaiting payment',
        'paid' => 'Paid',
        'refunded' => 'Refunded',
        'cancelled' => 'Cancelled',
    ],

    'api_token_type' => [
        'price_api' => 'Price API',
        'mcp' => 'MCP',
    ],

    'mcp_call_status' => [
        'ok' => 'OK',
        'invalid' => 'Invalid',
        'denied' => 'Denied',
        'rate_limited' => 'Rate limited',
        'error' => 'Error',
    ],

    'mcp_revoke_reason' => [
        'admin' => 'Revoked by an Admin',
        'revoke_all' => 'All MCP access revoked',
        'regenerated' => 'Regenerated',
        'transferred' => 'Access transferred',
        'deactivated' => 'User deactivated',
        'password_changed' => 'Password changed',
        'role_lost' => 'Admin role removed',
        'idle' => 'Unused too long',
        'never_used' => 'Never used within 7 days',
    ],

    'mcp_deny_reason' => [
        'revoked_token' => 'Revoked token used',
        'ip_not_allowed' => 'IP address not allowed',
        'token' => 'No valid MCP token',
        'token_user' => 'Token of another user',
        'inactive' => 'User deactivated',
        'not_admin' => 'Not an Admin',
        'no_access' => 'No MCP access',
        'not_single_holder' => 'More than one holder',
    ],

    'mcp_auth_type' => [
        'token' => 'Token',
    ],

];
