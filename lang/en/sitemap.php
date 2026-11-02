<?php

return [
    'title' => 'Sitemap',
    'subtitle' => 'The sitemap for search engines (sitemap.xml): status, refresh and preview',
    'refresh' => 'Refresh sitemap',
    'open_xml' => 'Open sitemap.xml',
    'refreshed' => 'Sitemap refreshed',
    'refreshed_body' => 'URLs: :count',
    'rate_limited' => 'Too many attempts: you can refresh up to 6 times a minute. Try again shortly.',
    'busy' => 'The sitemap is being built by another process right now. Try again in a few seconds.',

    'status_title' => 'Status',
    'status_hint' => 'The cached document: kept for a week, or rebuilt when a catalog item, page or news post is saved.',
    'not_generated' => 'The sitemap has not been generated yet',
    'not_generated_body' => 'It is built on the first request or with the "Refresh sitemap" button.',
    'generated_at' => 'Generated',
    'expires_at' => 'Expires',
    'counts' => [
        'total' => 'Total',
        'products' => 'Products',
        'categories' => 'Categories',
        'brands' => 'Brands',
        'pages' => 'Pages',
        'news' => 'News',
        'articles' => 'Articles',
        'other' => 'Other',
    ],

    'preview_title' => 'Preview',
    'tab_urls' => 'URLs',
    'tab_xml' => 'XML',
    'search_placeholder' => 'Search by URL',
    'col_loc' => 'URL',
    'col_lastmod' => 'Last modified',
    'col_locale' => 'Language',
    'raw_truncated' => 'Only the beginning is shown (at most :lines lines). Open sitemap.xml for the whole document.',
];
