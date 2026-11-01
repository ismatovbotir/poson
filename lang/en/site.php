<?php

return [

    'meta' => [
        'site_name' => 'Poson',
        'tagline' => 'Auto-ID equipment: selection, setup and service',
        'description' => 'Barcode scanners, label printers, mobile computers, RFID and POS equipment: expert selection, setup, integration and service at our own service center.',
    ],

    'locale' => [
        'switch_label' => 'Language',
        'uz' => 'Oʻzbekcha',
        'ru' => 'Русский',
        'en' => 'English',
    ],

    'a11y' => [
        'skip_to_content' => 'Skip to content',
    ],

    'topbar' => [
        'telegram' => 'Telegram',
        'call_us' => 'Call us',
    ],

    'header' => [
        'catalog' => 'Catalog',
        'show_all' => 'Show all',
        'search_placeholder' => 'Scanner, label printer, mobile computer, SKU…',
        'search_button' => 'Search',
        'menu_open' => 'Open menu',
        'menu_close' => 'Close menu',
        'cart' => 'Cart',
        'logo_alt' => 'Poson — home page',
        'search_label' => 'Search the catalog',
        'request_quote' => 'Request a quote',
        'menu_title' => 'Menu',
    ],

    'nav' => [
        'news' => 'News',
        'articles' => 'Articles',
        'brands' => 'Brands',
        'services' => 'Services',
        'aria_label' => 'Main menu',
    ],

    'home' => [
        'news' => [
            'title' => 'Latest news',
            'all' => 'All news',
        ],
        'hero' => [
            'eyebrow' => 'Warehouse · Retail · Manufacturing',
            'title_before' => 'Scanners, printers and mobile computers, ',
            'title_highlight' => 'set up and serviced',
            'title_after' => '',
            'text' => 'We select the right hardware for your task, connect it to 1C and set up the POS and inventory software we supply. Official warranty and our own service center.',
            'cta_catalog' => 'Browse the catalog',
            'cta_quote' => 'Request a quote',
            'badge_warranty_title' => 'Official warranty',
            'badge_warranty_text' => 'Serviced at our own service center',
            'badge_delivery_title' => 'Reliable delivery',
            'badge_delivery_text' => 'Careful packing and delivery',
            'scanner_alt' => 'Handheld 2D barcode scanner reading a label',
        ],

        'categories' => [
            'title' => 'Shop by category',
            'subtitle' => 'Hardware and consumables for scanning, printing and inventory control',
            'all' => 'All categories',
        ],

        'popular' => [
            'title' => 'Popular products',
            'subtitle' => 'Equipment our customers choose most often',
            'all' => 'All products',
        ],

        'b2b' => [
            'title' => 'For business',
            'text' => 'We run automation projects for stores, warehouses and pharmacies, from hardware selection to go-live.',
            'cta' => 'Discuss a partnership',
            'points' => [
                'A personal manager and hardware selected for your task',
                'Contract, invoice and payment by bank transfer',
                'Setup, integration and service under contract',
                'Test samples, pilot deliveries and service under an SLA',
            ],
        ],

        'why' => [
            'title' => 'Why Poson',
            'items' => [
                'warranty' => [
                    'title' => 'Official warranty',
                    'text' => 'Equipment comes with an official warranty, and warranty repairs are done at our own service center.',
                ],
                'delivery' => [
                    'title' => 'Reliable delivery',
                    'text' => 'We pack equipment carefully and deliver it to the agreed address.',
                ],
                'setup' => [
                    'title' => 'Setup and integration',
                    'text' => 'We connect devices to 1C, set up POS and inventory software and train your staff to use them.',
                ],
                'support' => [
                    'title' => 'Fast support',
                    'text' => 'Quick answers to your questions by phone and Telegram.',
                ],
            ],
        ],

        'services' => [
            'title' => 'Services',
            'subtitle' => 'Beyond supplying hardware, we get it running and keep it running',
            'more' => 'Learn more',
            'items' => [
                'warehouse' => [
                    'title' => 'Warehouse automation',
                    'text' => 'We connect mobile computers, scanners and label printers to 1C to speed up receiving, stocktaking and picking.',
                ],
                'marking' => [
                    'title' => 'DataMatrix codes',
                    'text' => 'Selection and setup of scanners and label printers that read and print DataMatrix codes, with printing from 1C.',
                ],
                'repair' => [
                    'title' => 'Repair and service',
                    'text' => 'Diagnostics, warranty and post-warranty repair, and preventive maintenance at our own service center.',
                ],
            ],
        ],

        'brands' => [
            'title' => 'Brands',
        ],

        'lead' => [
            'title' => 'Discuss your project with us',
            'text' => 'Tell us about your task, and a specialist will contact you with a suitable solution.',
        ],
    ],

    // Plan 08: roll picker of label products (Site\Catalog\RollPicker).
    'roll_picker' => [
        'title' => 'Roll type',
        'rolls' => 'Number of rolls',
        'decrease' => 'Fewer rolls',
        'increase' => 'More rolls',
        'min_hint' => 'Minimum :count roll|Minimum :count rolls',
        'per_roll' => 'Per roll',
        'total' => 'Total',
        'on_request' => 'The price of the rolls is given in a quote.',
        'none' => 'Ask us about the available rolls in a quote.',
        'rolls_invalid' => 'Choose from :min to :max rolls.',
        'unit_label' => 'label',
    ],

    'lead_form' => [
        'product_unavailable' => 'This product is no longer available. Call us or send a request without it, and we will suggest an alternative.',
        'product' => 'Product',
        'name' => 'Your name',
        'name_placeholder' => 'e.g. Aziz',
        'phone' => 'Phone',
        'phone_placeholder' => '+998 __ ___ __ __',
        'company' => 'Company',
        'company_placeholder' => 'Company name (optional)',
        'message' => 'Message',
        'message_placeholder' => 'What equipment or service do you need?',
        'submit' => 'Send',
        'sending' => 'Sending…',
        'consent' => 'By submitting this form, I agree to the processing of my personal data in accordance with the :privacy',
        'consent_privacy' => 'privacy policy',
        'success_title' => 'Request received',
        'success_text' => 'A specialist will contact you shortly.',
        'error_rate_limited' => 'Too many attempts. Please try again in :seconds seconds.',
        'attributes' => [
            'name' => 'name',
            'phone' => 'phone',
            'company' => 'company',
            'message' => 'message',
        ],
        'roll' => 'Roll',
    ],

    'cta' => [
        'try' => 'Try',
        'contact' => 'Contact us',
    ],

    'card' => [
        'add_to_cart' => 'Add to cart',
        'request_quote' => 'Request a quote',
        'price_on_request' => 'Price on request',
        'products_count' => ':count product|:count products',
        'view' => 'View',
    ],

    'stock' => [
        'in_stock' => 'In stock',
        'on_order' => 'On order',
        'out_of_stock' => 'Out of stock',
    ],

    'badges' => [
        'hit' => 'Bestseller',
        'new' => 'New',
    ],

    'currency' => 'UZS',

    'breadcrumbs' => [
        'home' => 'Home',
        'aria_label' => 'Breadcrumb',
    ],

    'pagination' => [
        'label' => 'Pagination',
        'previous' => 'Previous page',
        'next' => 'Next page',
        'page' => 'Page :page',
    ],

    'catalog' => [
        'title' => 'Catalog',
        'results' => 'Products',
        'subcategories' => 'Subcategories',
        'found' => ':count product found|:count products found',
        'show_results' => 'Show :count product|Show :count products',
        'filters' => 'Filters',
        'close_filters' => 'Close filters',
        'price' => 'Price, :currency',
        'price_min' => 'Minimum price',
        'price_max' => 'Maximum price',
        'min' => 'minimum',
        'max' => 'maximum',
        'brand' => 'Brand',
        'stock' => 'Availability',
        'reset' => 'Reset',
        'active_filters' => 'Active filters',
        'remove_filter' => 'Remove filter',
        'chip_price_min' => 'from :amount',
        'chip_price_max' => 'up to :amount',
        'sort' => [
            'label' => 'Sort by',
            'popular' => 'Popular first',
            'price_asc' => 'Price ↑',
            'price_desc' => 'Price ↓',
            'new' => 'Newest first',
        ],
        'empty_title' => 'No products found',
        'empty_text' => 'There are no products in this section yet. Send us a request and we will select equipment for your task.',
        'empty_filtered' => 'Nothing matches the selected filters. Change or reset them.',
        'seo' => [
            'title' => 'Auto-ID Equipment Catalog — Poson',
            'description' => 'Barcode scanners, label printers, mobile computers, RFID and POS equipment, consumables and services: expert selection, setup, official warranty and service.',
            'category_description' => ':name at Poson: expert selection, setup, official warranty and service.',
        ],
    ],

    'product' => [
        'related_news' => 'News about this product',
        'sku' => 'SKU',
        'brand' => 'Brand',
        'model' => 'Model',
        'category' => 'Category',
        'yes' => 'Yes',
        'no' => 'No',
        'gallery' => 'Product photos',
        'image' => 'Photo :number',
        'key_specs' => 'Key specifications',
        'price_on_request_note' => 'The price depends on the configuration: we will prepare a quote for you.',
        'tabs' => [
            'label' => 'Product information',
            'description' => 'Description',
            'specs' => 'Specifications',
            'documents' => 'Documents',
            'delivery' => 'Delivery & warranty',
        ],
        'no_description' => 'A description is coming soon. Ask a specialist and they will tell you more about this product.',
        'documents_empty' => 'No documents have been added for this product yet.',
        'download' => 'Download',
        'open_link' => 'Open',
        'new_tab' => 'opens in a new tab',
        'checksum' => 'SHA-256',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'delivery' => [
            'shipping' => 'Delivery within Kokand is free. Courier delivery across Uzbekistan, or pickup from our Kokand office.',
            'payment' => 'Payment in cash, by card, through Click or Payme, or by bank transfer for companies.',
            'setup' => 'We set up the equipment and connect it to your software.',
            'warranty' => 'Official warranty: :months',
            'warranty_on_request' => 'Warranty terms are confirmed in your quote.',
            'service' => 'Warranty and post-warranty service at our own service center.',
        ],
        'months' => ':count month|:count months',
        'software' => [
            'title' => 'About the program',
        ],
        'related' => [
            'accessory' => 'Accessories',
            'consumable' => 'Consumables',
            'alternative' => 'Alternatives',
        ],
        'quote' => [
            'title' => 'Request a quote',
            'text' => 'Leave your contacts: a specialist will confirm availability and delivery time, and suggest setup and integration for your task.',
        ],
    ],

    'search' => [
        'title' => 'Search',
        'results_for' => 'Results for “:query”',
        'empty_query' => 'Enter a product name, model or SKU.',
        'nothing' => 'Nothing found for “:query”.',
        'hint' => 'Check the spelling or describe your task, and a specialist will select the equipment.',
        'seo_description' => 'Search the Poson catalog of Auto-ID equipment, consumables and services.',
    ],

    'footer' => [
        'about' => 'About us',
        'catalog' => 'Catalog',
        'customers' => 'For customers',
        'contacts' => 'Contacts',
        'delivery_payment' => 'Delivery & payment',
        'warranty_service' => 'Warranty & service',
        'b2b' => 'For business',
        'rights' => '© :year Poson. All rights reserved.',
        'privacy' => 'Privacy policy',
        'offer' => 'Public offer',
        'description' => 'Auto-ID equipment and consumables: expert selection, setup, integration and service at our own service center.',
    ],

    'seo' => [
        'home' => [
            'title' => 'Poson — Barcode Scanners, Label Printers, Mobile Computers',
            'description' => 'Barcode scanners, label printers, mobile computers, RFID and POS equipment across Uzbekistan. Selection, 1C integration, official warranty and service.',
        ],
    ],

    'contacts' => [
        'map' => 'Show on map',
        'social' => 'Social networks',
        'phones' => 'Phones',
        'hours' => 'Working hours',
    ],

    'pages' => [
        'updated' => 'Updated: :date',
        'contacts' => [
            'title' => 'How to reach us',
            'map_notice' => 'The map loads from Yandex Maps only when you open it.',
            'show_map' => 'Show map',
            'map_title' => 'Poson office on the map',
            'form_title' => 'Write to us',
            'form_text' => 'Describe your task: we will call back and help with the selection, setup and service.',
            'requisites' => 'Company details',
            'legal_name' => 'Legal name',
            'inn' => 'Tax ID (INN)',
            'legal_address' => 'Legal address',
        ],
    ],

    'brands' => [
        'title' => 'Brands',
        'subtitle' => 'Makers of the equipment, consumables and software we supply, set up and service',
        'seo_title' => 'Brands — Poson',
        'seo_description' => 'Brands of barcode scanners, label printers, mobile computers, RFID and POS equipment: expert selection, setup, warranty and service at our own service center.',
        'products_count' => ':count product|:count products',
        'empty' => 'No brands to show yet.',
        'show' => [
            'seo_title' => ':name — selection, setup and service | Poson',
            'seo_description' => ':name at Poson: equipment selection, setup and integration, warranty and service at our own service center.',
            'products' => 'Products',
            'categories' => 'Filter by section',
            'all_categories' => 'All',
            'website' => 'Official website',
            'empty' => 'There are no products of this brand on the site yet.',
        ],
    ],

    'news' => [
        'title' => 'News',
        'subtitle' => 'New equipment, service and company news from Poson',
        'seo_title' => 'News — Poson',
        'seo_description' => 'Poson news: new barcode scanners, printers and mobile computers, setup and service updates, company events.',
        'empty' => 'There is no news in this language yet.',
        'rss' => 'RSS feed',
        'rss_title' => 'Poson news',
        'related_products' => 'Products in this post',
        'related_categories' => 'Catalog sections',
        'back' => 'All news',
    ],

    'articles' => [
        'title' => 'Articles',
        'subtitle' => 'Buying guides and how-tos for scanners, printers, mobile computers and labels',
        'seo_title' => 'Articles and guides — Poson',
        'seo_description' => 'Poson guides: how to choose a barcode scanner, label printer or mobile computer, pick labels and ribbons, and set up equipment for your business.',
        'empty' => 'There are no articles in this language yet.',
        'related_products' => 'Products in this article',
        'related_categories' => 'Catalog sections',
        'back' => 'All articles',
        'useful' => 'Useful articles',
        'all_categories' => 'All topics',
        'filter_label' => 'Article topics',
    ],

    'banners' => [
        'label' => 'Banners',
        'slide' => ':current of :total',
        'prev' => 'Previous banner',
        'next' => 'Next banner',
        'go_to' => 'Show banner :number',
        'more' => 'Learn more',
        'new_tab' => '(opens in a new tab)',
    ],

    'faq' => [
        'title' => 'Frequently asked questions',
        'subtitle' => 'Delivery, warranty, setup and service',
    ],

];
