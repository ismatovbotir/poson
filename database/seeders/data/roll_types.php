<?php

/*
 * Label roll types (plan 08), seeded by CatalogSeeder (idempotent: matched
 * by the labels / core pair; an existing row keeps its admin changes).
 * core: core diameter in mm; labels: labels per roll.
 */
return [
    ['core' => '12.7', 'labels' => 250],
    ['core' => '12.7', 'labels' => 500],
    ['core' => '25', 'labels' => 500],
    ['core' => '25', 'labels' => 1000],
    ['core' => '40', 'labels' => 500],
    ['core' => '40', 'labels' => 1000],
    ['core' => '40', 'labels' => 2000],
    ['core' => '76', 'labels' => 2000],
    ['core' => '76', 'labels' => 3000],
    ['core' => '76', 'labels' => 5000],
];
