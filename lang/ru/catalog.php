<?php

/*
 * Catalog display strings. units: display form of attributes.unit values
 * (Attribute::unitLabel()); units not listed here are shown as stored.
 */
return [

    'units' => [
        'pcs' => 'шт.',
        'months' => 'мес.',
        'hours' => 'ч',
        'days' => 'дн.',
        'years' => 'лет',
        'inch' => 'дюйм',
        'mm' => 'мм',
        'm' => 'м',
        'kg' => 'кг',
        'g' => 'г',
        'GB' => 'ГБ',
        'mAh' => 'мА·ч',
        'mm/s' => 'мм/с',
    ],

    'unit_counts' => [
        'months' => ':count месяц|:count месяца|:count месяцев',
        'hours' => ':count час|:count часа|:count часов',
        'days' => ':count день|:count дня|:count дней',
        'years' => ':count год|:count года|:count лет',
        'inch' => ':count дюйм|:count дюйма|:count дюймов',
    ],

    // Generated label diagram, the fallback image of label products (App\Support\Catalog\LabelDiagram).
    'label_diagram' => [
        'title' => 'Размер этикетки :size мм',
        'mm' => 'мм',
        'caption' => ':count шт. · втулка :core мм',
    ],

    // Plan 08: label rolls (RollType::describe(), RollPricing::describe()).
    'roll' => [
        'name' => ':labels/рулон, втулка :core мм',
        'labels' => ':count этикетка|:count этикетки|:count этикеток',
        'size' => ':size мм',
        'rolls' => ':count рулон|:count рулона|:count рулонов',
        'per_label' => ':price / этикетка',
        'errors' => [
            'not_label_product' => 'Цена этого товара указана не за этикетку.',
            'unavailable' => 'Этот тип рулона недоступен для товара.',
            'required' => 'Выберите тип рулона.',
            'rolls' => 'Неверное количество рулонов.',
            'whole_som' => 'Цена за этикетку должна быть в целых сумах.',
            'too_high' => 'Слишком большая цена.',
        ],
    ],

    'document_link' => 'Ссылка',

];
