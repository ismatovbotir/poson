<?php

/*
 * Catalog display strings. units: display form of attributes.unit values
 * (Attribute::unitLabel()); units not listed here are shown as stored.
 */
return [

    'units' => [
        'pcs' => 'pcs',
        'months' => 'months',
        'hours' => 'hours',
        'days' => 'days',
        'years' => 'years',
        'inch' => 'inch',
        'mm' => 'mm',
        'm' => 'm',
        'kg' => 'kg',
        'g' => 'g',
        'GB' => 'GB',
        'mAh' => 'mAh',
        'mm/s' => 'mm/s',
    ],

    'unit_counts' => [
        'months' => ':count month|:count months',
        'hours' => ':count hour|:count hours',
        'days' => ':count day|:count days',
        'years' => ':count year|:count years',
        'inch' => ':count inch|:count inches',
    ],

    // Generated label diagram, the fallback image of label products (App\Support\Catalog\LabelDiagram).
    'label_diagram' => [
        'title' => 'Label size :size mm',
        'mm' => 'mm',
        'caption' => ':count pcs · core :core mm',
    ],

    // Plan 08: label rolls (RollType::describe(), RollPricing::describe()).
    'roll' => [
        'name' => ':labels/roll, core :core mm',
        'labels' => ':count label|:count labels',
        'size' => ':size mm',
        'rolls' => ':count roll|:count rolls',
        'per_label' => ':price / label',
        'errors' => [
            'not_label_product' => 'This product is not priced per label.',
            'unavailable' => 'This roll type is not available for the product.',
            'required' => 'Choose a roll type.',
            'rolls' => 'Invalid number of rolls.',
            'whole_som' => 'The price per label must be in whole UZS.',
            'too_high' => 'The price is too high.',
        ],
    ],

    'document_link' => 'Link',

];
