<?php

/*
 * Catalog display strings. units: display form of attributes.unit values
 * (Attribute::unitLabel()); units not listed here are shown as stored.
 */
return [

    'units' => [
        'pcs' => 'dona',
        'months' => 'oy',
        'hours' => 'soat',
        'days' => 'kun',
        'years' => 'yil',
        'inch' => 'dyuym',
        'mm' => 'mm',
        'm' => 'm',
        'kg' => 'kg',
        'g' => 'g',
        'GB' => 'GB',
        'mAh' => 'mA·soat',
        'mm/s' => 'mm/s',
    ],

    'unit_counts' => [
        'months' => ':count oy',
        'hours' => ':count soat',
        'days' => ':count kun',
        'years' => ':count yil',
        'inch' => ':count dyuym',
    ],

    // Generated label diagram, the fallback image of label products (App\Support\Catalog\LabelDiagram).
    'label_diagram' => [
        'title' => 'Yorliq oʻlchami :size mm',
        'mm' => 'mm',
        'caption' => ':count dona · gilza :core mm',
    ],

    // Plan 08: label rolls (RollType::describe(), RollPricing::describe()).
    'roll' => [
        'name' => ':labels/rulon, gilza :core mm',
        'labels' => ':count yorliq|:count yorliq',
        'size' => ':size mm',
        'rolls' => ':count rulon|:count rulon',
        'per_label' => ':price / yorliq',
        'errors' => [
            'not_label_product' => 'Bu mahsulot yorliq boʻyicha narxlanmagan.',
            'unavailable' => 'Bu rulon turi ushbu mahsulot uchun mavjud emas.',
            'required' => 'Rulon turini tanlang.',
            'rolls' => 'Rulonlar soni notoʻgʻri.',
            'whole_som' => 'Bitta yorliq narxi butun soʻmda boʻlishi kerak.',
            'too_high' => 'Narx juda katta.',
        ],
    ],

    'document_link' => 'Havola',

];
