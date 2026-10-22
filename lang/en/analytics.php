<?php

return [

    'menu' => [
        'traffic' => 'Analytics',
        'ai' => 'AI visibility',
    ],

    'enums' => [
        'traffic_channel' => [
            'direct' => 'Direct',
            'search' => 'Search engines',
            'ai' => 'AI assistants',
            'social' => 'Social',
            'referral' => 'Other websites',
            'campaign' => 'Campaign (UTM)',
        ],
        'device_type' => [
            'desktop' => 'Desktop',
            'mobile' => 'Mobile',
            'tablet' => 'Tablet',
        ],
        'visit_type' => [
            'page_view' => 'Page view',
            'lead' => 'Lead',
        ],
        'bot_purpose' => [
            'training' => 'AI training',
            'ai_search' => 'AI search',
            'ai_user' => 'AI on user request',
            'search' => 'Search engine',
            'other' => 'Other',
        ],
        'bot_verification' => [
            'ip_ranges' => 'Published IP ranges',
            'rdns' => 'Reverse DNS',
            'ua_only' => 'User-Agent only',
        ],
    ],

    'bots' => [
        'other' => 'Other bots',
    ],

    'locales' => [
        'uz' => 'Uzbek',
        'ru' => 'Russian',
        'en' => 'English',
    ],

    'common' => [
        'range' => 'Period',
        'days' => ':count days',
        'last_days' => 'Last :count days',
        'no_data' => 'No data for this period yet.',
        'page' => 'Page',
        'visitors' => 'Visitors',
        'visitors_short' => 'visitors',
        'leads' => 'Leads',
        'conversion' => 'Conversion',
        'channel' => 'Channel',
        'source' => 'Source',
        'views' => 'Views',
        'views_short' => 'views',
    ],

    'traffic' => [
        'title' => 'Site traffic',
        'subtitle' => 'Our own count of visitors, sources and leads. No cookies; staff visits are not counted.',
        'external' => 'Detailed reports:',
        'open_ga4' => 'Open in GA4',
        'open_metrika' => 'Open in Metrika',
        'kpis' => 'Key figures',
        'kpi' => [
            'visitors' => 'Visitors',
            'page_views' => 'Page views',
            'leads' => 'Leads',
            'conversion' => 'Conversion',
        ],
        'empty' => 'No visits recorded for this period yet. New visits show up here within 15 minutes.',
        'chart_title' => 'Visitors per day',
        'chart_label' => 'Bar chart of visitors per day',
        'chart_tooltip' => 'visitors: :visitors, leads: :leads',
        'legend_visitors' => 'Visitors',
        'legend_leads' => 'Day with leads',
        'channels' => 'Channels',
        'sources' => 'Sources',
        'sources_hint' => 'Where visitors came from: a website, a search engine, an AI assistant or a UTM campaign.',
        'devices' => 'Devices',
        'locales' => 'Site languages',
        'top_pages' => 'Top pages',
        'top_products' => 'Top products',
        'privacy_note' => 'Visitors are counted per day with an anonymous hash; IP addresses of people are not stored. Raw visits are kept for 30 days, daily totals for 25 months.',
    ],

    'ai' => [
        'title' => 'AI visibility',
        'subtitle' => 'How AI and search crawlers read the site, and how many people come from AI assistants.',
        'comparison' => 'Visitors by type of source',
        'groups' => [
            'ai' => 'From AI assistants',
            'search' => 'From search engines',
            'other' => 'Other sources',
        ],
        'leads_conversion' => 'leads: :leads · conversion :conversion',
        'crawlers' => 'Crawlers',
        'crawlers_hint' => 'Requests by User-Agent. Verified = the IP belongs to the vendor; spoofed = a fake User-Agent. Checked every night.',
        'no_bots' => 'No crawler visits recorded for this period yet.',
        'columns' => [
            'bot' => 'Crawler',
            'purpose' => 'Purpose',
            'hits' => 'Requests',
            'ips' => 'IPs',
            'per_day' => 'Per day',
            'verification' => 'Verification',
            'errors' => '4xx / 5xx',
            'last_seen' => 'Last seen',
        ],
        'sparkline_label' => 'Requests per day by :bot',
        'verified' => ':count verified',
        'unverified' => ':count unverified',
        'spoofed' => ':count spoofed',
        'errors_hint' => 'Last seen is shown in Tashkent time. Today’s numbers refresh every 15 minutes.',
        'filter_bot' => 'Crawler',
        'all_bots' => 'All crawlers',
        'showing_bot' => 'Pages and errors for :bot',
        'showing_all' => 'Pages and errors for all crawlers',
        'top_pages' => 'Most crawled pages',
        'top_products' => 'Most crawled products',
        'hits_short' => 'requests',
        'not_found' => 'Not found (4xx)',
        'not_found_hint' => 'Pages crawlers asked for that returned 404 or another client error: fix links or add redirects.',
        'ai_sources' => 'AI assistants that send visitors',
        'ai_sources_hint' => 'People who clicked a link to the site in an AI answer.',
        'leads_count' => 'leads: :count',
    ],

];
