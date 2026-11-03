<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { /* jeab-deny-direct */
    $jeabScript = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $jeabSelf = realpath(__FILE__);
    if ($jeabScript !== false && $jeabSelf !== false && $jeabScript === $jeabSelf) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        exit;
    }
}

/**
 * Inköpspris exkl. moms (SEK) för Neo-batteriet.
 * Verksamheten kallar det «7,5 kWh». Artikeln är SigenStor Neo BAT 8.0.
 */
const JEAB_SIGEN_NEO_BAT_8_0_EX_VAT = 19034;

/**
 * Aprilice-inköp exkl. moms, april 2026.
 * sku är bara Aprilice leverantörsartikel (supplier_article) för inköp.
 * Det är inte ett Fortnox-nummer. Orderrader använder bolagets batteriartikel
 * från fortnox_config, samma artikel som andra batteriordrar.
 *
 * @return array<string,array<string,mixed>>
 */
return [
    'neo' => [
        'label' => 'NEO',
        'sizes' => [
            '8.0' => ['title' => 'SigenStor Neo EC 8.0 TP', 'purchase' => 15972, 'sku' => ''],
            '10.0' => ['title' => 'SigenStor Neo EC 10.0 TP', 'purchase' => 17424, 'sku' => ''],
            '12.0' => ['title' => 'SigenStor Neo EC 12.0 TP', 'purchase' => 18876, 'sku' => ''],
            '15.0' => ['title' => 'SigenStor Neo EC 15.0 TP', 'purchase' => 21780, 'sku' => ''],
        ],
        'battery' => [
            'title' => 'SigenStor Neo BAT 8.0',
            'purchase' => JEAB_SIGEN_NEO_BAT_8_0_EX_VAT,
            'sku' => '',
        ],
        'plate' => ['title' => 'Bottenplatta', 'purchase' => 1300, 'sku' => '11170033'],
        'surcharge' => 0,
    ],
    'tp2' => [
        'label' => 'TP2',
        'sizes' => [
            '12.0' => ['title' => 'Sigen Hybrid Inverter 12.0 kW TP2', 'purchase' => 12403, 'sku' => '11010186'],
        ],
        'battery' => [
            'title' => 'SigenStor BAT 10.0 (9 kWh)',
            'purchase' => 27321,
            'sku' => '11130012',
        ],
        'plate' => ['title' => 'Sigen bottenplatta TP2', 'purchase' => 3000, 'sku' => ''],
        'surcharge' => 1500,
        'surcharge_title' => 'TP2 tillägg material och arbete',
    ],
    'ec' => [
        'label' => 'EC',
        'sizes' => [
            '12.0' => ['title' => 'SigenStor EC 12.0 kW TP', 'purchase' => 22560, 'sku' => ''],
            '15.0' => ['title' => 'SigenStor EC 15.0 kW TP', 'purchase' => 25271, 'sku' => ''],
            '17.0' => ['title' => 'SigenStor EC 17.0 kW TP', 'purchase' => 26792, 'sku' => ''],
            '20.0' => ['title' => 'SigenStor EC 20.0 kW TP', 'purchase' => 28207, 'sku' => '11010017'],
        ],
        'battery' => [
            'title' => 'SigenStor BAT 10.0 (9 kWh)',
            'purchase' => 27321,
            'sku' => '11130012',
        ],
        'plate' => ['title' => 'Bottenplatta', 'purchase' => 1300, 'sku' => '11170033'],
        'surcharge' => 0,
    ],
];
