<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Sigen-val i batteri-kalkylen: typ, växelriktarstorlek, antal batterier.
 * Inköpspriserna ligger i config/sigen_priser.php. Kundpriset räknas med
 * den befintliga Kalkyl-formeln (jeab_offert_calc): priserna skrivs in i
 * c27, c4, tp2_unit/not_tp2_unit och sigen_surcharge. Fakturaraderna delar
 * samma Batteri/Schablon-summor som formeln redan ger.
 */

function jeab_sigen_prices_file(): string
{
    return JEAB_ROOT . '/config/sigen_priser.php';
}

/** @return array<string,mixed> */
function jeab_sigen_price_config(): array
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }
    $raw = require jeab_sigen_prices_file();
    $loaded = is_array($raw) ? $raw : [];
    return $loaded;
}

/** @return array<string,array<string,mixed>> */
function jeab_sigen_catalog_base(): array
{
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }
    $catalog = [];
    foreach (jeab_sigen_price_config() as $type => $row) {
        if (!is_string($type) || !is_array($row)) {
            continue;
        }
        $key = strtolower(trim($type));
        if ($key === '' || !is_array($row['sizes'] ?? null)) {
            continue;
        }
        $catalog[$key] = $row;
    }
    return $catalog;
}

/**
 * Redigerbara inköpspriserna i Kalkylens detaljpanel.
 * Samma nyckel för priser som delas (BAT 10.0 för TP2 och EC, bottenplatta för NEO och EC).
 *
 * @return array<string,array{label:string,group:string,type:string,role:string,kw?:string,also?:list<string>,default:int}>
 */
function jeab_sigen_price_fields(): array
{
    $base = jeab_sigen_catalog_base();
    $kwLabel = static function (string $kw): string {
        return rtrim(rtrim($kw, '0'), '.');
    };
    $size = static function (array $base, string $type, string $kw) use ($kwLabel): array {
        $purchase = (int) ($base[$type]['sizes'][$kw]['purchase'] ?? 0);
        $name = strtoupper($type === 'tp2' ? 'TP2' : ($type === 'neo' ? 'NEO' : 'EC'));
        return [
            'label' => 'Växelriktare ' . $name . ' ' . $kwLabel($kw) . ' kW',
            'group' => 'Växelriktare',
            'type' => $type,
            'role' => 'size',
            'kw' => $kw,
            'default' => $purchase,
        ];
    };
    $fields = [];
    foreach (['8.0', '10.0', '12.0', '15.0'] as $kw) {
        $fields['sigen_inv_neo_' . str_replace('.', '_', $kw)] = $size($base, 'neo', $kw);
    }
    foreach (['12.0', '15.0', '17.0', '20.0'] as $kw) {
        $fields['sigen_inv_ec_' . str_replace('.', '_', $kw)] = $size($base, 'ec', $kw);
    }
    $fields['sigen_inv_tp2_12_0'] = $size($base, 'tp2', '12.0');
    $fields['sigen_bat_neo'] = [
        'label' => 'Batteri Neo BAT 8.0 / st',
        'group' => 'Batterier',
        'type' => 'neo',
        'role' => 'battery',
        'default' => (int) ($base['neo']['battery']['purchase'] ?? 0),
    ];
    $fields['sigen_bat_10'] = [
        'label' => 'Batteri BAT 10.0 / st',
        'group' => 'Batterier',
        'type' => 'tp2',
        'also' => ['ec'],
        'role' => 'battery',
        'default' => (int) ($base['tp2']['battery']['purchase'] ?? 0),
    ];
    $fields['sigen_plate'] = [
        'label' => 'Bottenplatta NEO/EC',
        'group' => 'Bottenplatta och tillägg',
        'type' => 'neo',
        'also' => ['ec'],
        'role' => 'plate',
        'default' => (int) ($base['neo']['plate']['purchase'] ?? 0),
    ];
    $fields['sigen_plate_tp2'] = [
        'label' => 'Bottenplatta TP2',
        'group' => 'Bottenplatta och tillägg',
        'type' => 'tp2',
        'role' => 'plate',
        'default' => (int) ($base['tp2']['plate']['purchase'] ?? 0),
    ];
    $fields['sigen_extra_tp2'] = [
        'label' => 'TP2 tillägg',
        'group' => 'Bottenplatta och tillägg',
        'type' => 'tp2',
        'role' => 'surcharge',
        'default' => (int) ($base['tp2']['surcharge'] ?? 0),
    ];
    return $fields;
}

/** @param array<string,array<string,mixed>> $catalog */
function jeab_sigen_overlay_saved_prices(array $catalog, array $saved): array
{
    foreach (jeab_sigen_price_fields() as $key => $spec) {
        if (!array_key_exists($key, $saved) || $saved[$key] === '' || $saved[$key] === null) {
            continue;
        }
        $price = (int) round(jeab_offert_num($saved[$key]));
        $types = array_merge([$spec['type']], is_array($spec['also'] ?? null) ? $spec['also'] : []);
        foreach ($types as $type) {
            if (!isset($catalog[$type]) || !is_array($catalog[$type])) {
                continue;
            }
            if ($spec['role'] === 'size') {
                $kw = (string) ($spec['kw'] ?? '');
                if (isset($catalog[$type]['sizes'][$kw]) && is_array($catalog[$type]['sizes'][$kw])) {
                    $catalog[$type]['sizes'][$kw]['purchase'] = $price;
                }
            } elseif ($spec['role'] === 'battery' && is_array($catalog[$type]['battery'] ?? null)) {
                $catalog[$type]['battery']['purchase'] = $price;
            } elseif ($spec['role'] === 'plate' && is_array($catalog[$type]['plate'] ?? null)) {
                $catalog[$type]['plate']['purchase'] = $price;
            } elseif ($spec['role'] === 'surcharge') {
                $catalog[$type]['surcharge'] = $price;
            }
        }
    }
    return $catalog;
}

/** @return array<string,array<string,mixed>> */
function jeab_sigen_catalog(): array
{
    $catalog = jeab_sigen_catalog_base();
    if (!function_exists('jeab_offert_load_standard')) {
        return $catalog;
    }
    $loaded = jeab_offert_load_standard();
    $saved = is_array($loaded['offert'] ?? null) ? $loaded['offert'] : [];
    return jeab_sigen_overlay_saved_prices($catalog, $saved);
}

/**
 * Priser som skickas med i anropet (detaljpanelens fält) vinner över listan.
 *
 * @param array<string,mixed> $choice
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_sigen_choice_with_input_prices(array $choice, array $input): array
{
    foreach (jeab_sigen_price_fields() as $key => $spec) {
        if (!array_key_exists($key, $input) || $input[$key] === '' || $input[$key] === null) {
            continue;
        }
        $types = array_merge([$spec['type']], is_array($spec['also'] ?? null) ? $spec['also'] : []);
        if (!in_array($choice['type'], $types, true)) {
            continue;
        }
        $price = (int) round(jeab_offert_num($input[$key]));
        if ($spec['role'] === 'size' && (string) ($spec['kw'] ?? '') === (string) $choice['kw']) {
            $choice['inverter_unit'] = $price;
        } elseif ($spec['role'] === 'battery') {
            $choice['battery_unit'] = $price;
        } elseif ($spec['role'] === 'plate') {
            $choice['plate_unit'] = $price;
        } elseif ($spec['role'] === 'surcharge') {
            $choice['surcharge'] = $price;
        }
    }
    return $choice;
}

/** @return list<string> */
function jeab_sigen_types(): array
{
    return array_keys(jeab_sigen_catalog());
}

/** @return list<string> */
function jeab_sigen_sizes(string $type): array
{
    $row = jeab_sigen_catalog()[strtolower(trim($type))] ?? null;
    if (!is_array($row) || !is_array($row['sizes'] ?? null)) {
        return [];
    }
    return array_keys($row['sizes']);
}

function jeab_sigen_kw_key(string $raw): string
{
    $raw = str_replace(',', '.', trim($raw));
    if ($raw === '' || !is_numeric($raw)) {
        return '';
    }
    return number_format((float) $raw, 1, '.', '');
}

/**
 * @return array{type:string,kw:string,qty:int,inverter_unit:int,inverter_sku:string,inverter_title:string,battery_unit:?int,battery_sku:string,battery_title:string,plate_unit:int,plate_sku:string,plate_title:string,surcharge:int}|null
 */
function jeab_sigen_resolve(string $type, string $kw, int $qty): ?array
{
    $type = strtolower(trim($type));
    $row = jeab_sigen_catalog()[$type] ?? null;
    if (!is_array($row)) {
        return null;
    }
    $key = jeab_sigen_kw_key($kw);
    $size = is_array($row['sizes'][$key] ?? null) ? $row['sizes'][$key] : null;
    if ($size === null) {
        return null;
    }
    $battery = is_array($row['battery'] ?? null) ? $row['battery'] : [];
    $plate = is_array($row['plate'] ?? null) ? $row['plate'] : [];
    $batteryPurchase = $battery['purchase'] ?? null;
    return [
        'type' => $type,
        'kw' => $key,
        'qty' => max(0, $qty),
        'inverter_unit' => (int) ($size['purchase'] ?? 0),
        'inverter_sku' => trim((string) ($size['sku'] ?? '')),
        'inverter_title' => trim((string) ($size['title'] ?? '')),
        'battery_unit' => $batteryPurchase === null || $batteryPurchase === '' ? null : (int) $batteryPurchase,
        'battery_sku' => trim((string) ($battery['sku'] ?? '')),
        'battery_title' => trim((string) ($battery['title'] ?? '')),
        'plate_unit' => (int) ($plate['purchase'] ?? 0),
        'plate_sku' => trim((string) ($plate['sku'] ?? '')),
        'plate_title' => trim((string) ($plate['title'] ?? 'Bottenplatta')),
        'surcharge' => max(0, (int) ($row['surcharge'] ?? 0)),
        'surcharge_sku' => trim((string) ($row['surcharge_sku'] ?? '')),
        'surcharge_title' => trim((string) ($row['surcharge_title'] ?? 'TP2 tillägg material och arbete')),
    ];
}

/** @param array<string,mixed> $purchase */
function jeab_sigen_frozen(array $purchase): bool
{
    if (!empty($purchase['allt_klart'])) {
        return true;
    }
    return trim((string) ($purchase['closed_at'] ?? '')) !== '';
}

/**
 * Sparat val på en öppen order. Stängda ordrar och ordrar utan typ ger null
 * så att gamla kalkyler inte räknas om.
 *
 * @param array<string,mixed> $purchase
 * @return array{type:string,kw:string,qty:int,inverter_unit:int,inverter_sku:string,inverter_title:string,battery_unit:?int,battery_sku:string,battery_title:string,plate_unit:int,plate_sku:string,plate_title:string,surcharge:int}|null
 */
function jeab_sigen_choice(array $purchase): ?array
{
    if (jeab_sigen_frozen($purchase)) {
        return null;
    }
    $kalkyl = is_array($purchase['kalkyl'] ?? null) ? $purchase['kalkyl'] : [];
    $type = strtolower(trim((string) ($kalkyl['sigen_typ'] ?? '')));
    if ($type === '') {
        return null;
    }
    $qty = (int) round(jeab_offert_num($kalkyl['b3'] ?? 0));
    return jeab_sigen_resolve($type, (string) ($kalkyl['sigen_kw'] ?? ''), $qty);
}

/**
 * Skriv inköpspriserna i Kalkylens befintliga fält.
 *
 * @param array<string,mixed> $input
 * @param array{type:string,kw:string,qty:int,inverter_unit:int,battery_unit:?int,plate_unit:int,surcharge:int} $choice
 * @return array<string,mixed>
 */
function jeab_sigen_apply_prices(array $input, array $choice): array
{
    $input['b14'] = 0.0;
    $input['b3'] = (float) $choice['qty'];
    $input['b15'] = $choice['type'] === 'tp2' ? 1.0 : 0.0;
    $input['c27'] = (float) ($choice['battery_unit'] ?? 0);
    $input['c4'] = (float) $choice['plate_unit'];
    $input['sigen_surcharge'] = (float) $choice['surcharge'];
    if ($choice['type'] === 'tp2') {
        $input['tp2_unit'] = (float) $choice['inverter_unit'];
    } else {
        $input['not_tp2_unit'] = (float) $choice['inverter_unit'];
    }
    return $input;
}

/**
 * Inköpsrader: växelriktare, batteri × antal, bottenplatta, TP2-tillägg.
 *
 * @param array{type:string,kw:string,qty:int,inverter_unit:int,inverter_sku:string,inverter_title:string,battery_unit:?int,battery_sku:string,battery_title:string,plate_unit:int,plate_sku:string,plate_title:string,surcharge:int} $choice
 * @return list<array{role:string,sku:string,title:string,qty:int,purchase:?int,shop:string}>
 */
function jeab_sigen_lines(array $choice): array
{
    $lines = [
        [
            'role' => 'inverter',
            'sku' => $choice['inverter_sku'],
            'title' => $choice['inverter_title'],
            'qty' => 1,
            'purchase' => $choice['inverter_unit'],
        ],
        [
            'role' => 'battery',
            'sku' => $choice['battery_sku'],
            'title' => $choice['battery_title'],
            'qty' => $choice['qty'],
            'purchase' => $choice['battery_unit'],
        ],
        [
            'role' => 'plate',
            'sku' => $choice['plate_sku'],
            'title' => $choice['plate_title'],
            'qty' => 1,
            'purchase' => $choice['plate_unit'],
        ],
    ];
    if ($choice['surcharge'] > 0) {
        $lines[] = [
            'role' => 'surcharge',
            'sku' => (string) ($choice['surcharge_sku'] ?? ''),
            'title' => trim((string) ($choice['surcharge_title'] ?? '')) !== ''
                ? (string) $choice['surcharge_title']
                : 'TP2 tillägg material och arbete',
            'qty' => 1,
            'purchase' => $choice['surcharge'],
        ];
    }
    foreach ($lines as $i => $line) {
        $lines[$i]['shop'] = 'aprilice';
    }
    return $lines;
}

function jeab_sigen_summary_label(array $purchase): string
{
    $kalkyl = is_array($purchase['kalkyl'] ?? null) ? $purchase['kalkyl'] : [];
    $type = strtolower(trim((string) ($kalkyl['sigen_typ'] ?? '')));
    if ($type === '') {
        return '';
    }
    $qty = (int) round(jeab_offert_num($kalkyl['b3'] ?? 0));
    $choice = jeab_sigen_resolve($type, (string) ($kalkyl['sigen_kw'] ?? ''), $qty);
    if ($choice === null) {
        return '';
    }
    $label = (string) (jeab_sigen_catalog()[$choice['type']]['label'] ?? $choice['type']);
    $modules = $choice['qty'] === 1 ? '1 batterimodul' : $choice['qty'] . ' batterimoduler';
    return $label . ' ' . $choice['kw'] . ' kW · ' . $modules;
}

function jeab_sigen_included_text(array $choice): string
{
    $parts = [];
    foreach (jeab_sigen_lines($choice) as $line) {
        $bit = (string) $line['title'];
        if ($line['role'] === 'battery') {
            $bit .= ' × ' . (int) $line['qty'];
        }
        if ($line['purchase'] === null) {
            $bit .= ' (inköpspris saknas)';
        } else {
            $bit .= ' ' . number_format((int) $line['purchase'], 0, ',', ' ') . ' kr';
        }
        $parts[] = $bit;
    }
    return 'Ingår: ' . implode(', ', $parts) . '.';
}

function jeab_sigen_line_purchase_sum(array $line): ?int
{
    if (!isset($line['purchase']) || $line['purchase'] === null || $line['purchase'] === '') {
        return null;
    }
    return (int) $line['purchase'] * max(0, (int) ($line['qty'] ?? 0));
}

/**
 * Artikelnummer som saknas. Kända nummer från repots orderrader lämnas ute.
 *
 * @return list<string>
 */
function jeab_sigen_missing_articles(): array
{
    $missing = [];
    foreach (jeab_sigen_catalog() as $type => $row) {
        $label = (string) ($row['label'] ?? $type);
        foreach (is_array($row['sizes'] ?? null) ? $row['sizes'] : [] as $kw => $size) {
            if (!is_array($size) || trim((string) ($size['sku'] ?? '')) !== '') {
                continue;
            }
            $missing[] = $label . ' växelriktare ' . $kw . ' kW (' . trim((string) ($size['title'] ?? '')) . ')';
        }
        $battery = is_array($row['battery'] ?? null) ? $row['battery'] : [];
        if (trim((string) ($battery['sku'] ?? '')) === '') {
            $missing[] = $label . ' batteri (' . trim((string) ($battery['title'] ?? '')) . ')';
        }
        $plate = is_array($row['plate'] ?? null) ? $row['plate'] : [];
        if (trim((string) ($plate['sku'] ?? '')) === '') {
            $missing[] = $label . ' bottenplatta ' . (int) ($plate['purchase'] ?? 0) . ' kr';
        }
        if ((int) ($row['surcharge'] ?? 0) > 0 && trim((string) ($row['surcharge_sku'] ?? '')) === '') {
            $missing[] = $label . ' tillägg material och arbete';
        }
    }
    return $missing;
}

/**
 * Dela en redan uträknad Kalkyl-summa på inköpsvikter. Sista raden tar resten
 * så att summan blir exakt densamma.
 *
 * @param array<string,int> $weights
 * @return array<string,int>
 */
function jeab_sigen_portions(int $total, array $weights): array
{
    if ($weights === []) {
        return [];
    }
    $keys = array_keys($weights);
    $sum = 0;
    foreach ($weights as $weight) {
        $sum += max(0, (int) $weight);
    }
    $out = [];
    $used = 0;
    $last = $keys[count($keys) - 1];
    foreach ($keys as $key) {
        if ($key === $last) {
            $out[$key] = $total - $used;
            continue;
        }
        $weight = max(0, (int) $weights[$key]);
        $share = $sum > 0 ? intdiv($total * $weight, $sum) : 0;
        $out[$key] = $share;
        $used += $share;
    }
    return $out;
}

/**
 * Fortnox-rader: växelriktare och batteri delar Kalkylens grön-teknik-underlag.
 * Schablonen är en egen rad, samma krona som innan Sigen-raderna (3 % av bruttot
 * efter avrundning, raden utan husarbete). Bottenplatta och tillägg stannar i
 * inköpet och läggs inte in på fakturan i stället för den raden.
 * Varje rad använder bolagets batteriartikel (nyckeln battery). Aldrig material
 * och aldrig sku. Bolaget väljs av Fortnox-kontot som anroparen redan har,
 * så samma nyckel blir Järfällas eller Malmös batteriartikel.
 *
 * @param list<array<string,mixed>> $baseRows
 * @param array{type:string,qty:int,inverter_sku:string,inverter_title:string,inverter_unit:int,battery_unit:?int,battery_sku:string,battery_title:string,plate_unit:int,plate_sku:string,plate_title:string,surcharge:int,kw?:string} $choice
 * @return list<array{article:string,desc:string,price:int,hw:?string,hours:int,qty:int}>
 */
function jeab_sigen_fortnox_rows(array $baseRows, array $choice, ?string $company = null): array
{
    unset($company);
    $gt = 0;
    $other = 0;
    foreach ($baseRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $price = (int) ($row['price'] ?? 0);
        $hw = $row['hw'] ?? null;
        if (is_string($hw) && $hw !== '') {
            $gt += $price;
        } else {
            $other += $price;
        }
    }
    $lines = jeab_sigen_lines($choice);
    $gtWeights = [];
    foreach ($lines as $line) {
        $weight = jeab_sigen_line_purchase_sum($line) ?? 0;
        if ($line['role'] === 'inverter' || $line['role'] === 'battery') {
            $gtWeights[$line['role']] = $weight;
        }
    }
    $gtParts = jeab_sigen_portions($gt, $gtWeights);
    $out = [];
    foreach ($lines as $line) {
        $qty = (int) $line['qty'];
        if ($qty < 1) {
            continue;
        }
        $role = (string) $line['role'];
        if ($role !== 'inverter' && $role !== 'battery') {
            continue;
        }
        $price = (int) ($gtParts[$role] ?? 0);
        if ($price <= 0) {
            continue;
        }
        $out[] = [
            'article' => 'battery',
            'desc' => (string) $line['title'],
            'price' => $price,
            'hw' => 'STORAGESELFPRODUCEDELECTRICITY',
            'hours' => 24,
            'qty' => $qty,
        ];
    }
    $out[] = [
        'article' => 'battery',
        'desc' => 'Schablon',
        'price' => $other,
        'hw' => null,
        'hours' => 0,
        'qty' => 1,
    ];
    return $out;
}

/**
 * Fyll inköpslistan när den är tom, eller när Sigen-valet ändrats och listan
 * själv kom från valet. En lista som någon redan sparat lämnas.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function jeab_sigen_apply_inkop(array $row): array
{
    if (jeab_sigen_frozen($row)) {
        return $row;
    }
    $choice = jeab_sigen_choice($row);
    if ($choice === null) {
        return $row;
    }
    $key = $choice['type'] . '|' . $choice['kw'] . '|' . $choice['qty'];
    $source = (string) ($row['inkop_source'] ?? '');
    $prev = (string) ($row['inkop_sigen_key'] ?? '');
    $has = false;
    foreach (is_array($row['inkop_lines'] ?? null) ? $row['inkop_lines'] : [] as $line) {
        if (!is_array($line)) {
            continue;
        }
        if (trim((string) ($line['sku'] ?? '')) !== '' || trim((string) ($line['title'] ?? '')) !== '') {
            $has = true;
            break;
        }
    }
    if ($has && !($source === 'sigen' && $prev !== $key)) {
        return $row;
    }
    $lines = [];
    foreach (jeab_sigen_lines($choice) as $line) {
        $lines[] = [
            'sku' => $line['sku'],
            'title' => $line['title'],
            'qty' => $line['qty'],
            'shop' => 'aprilice',
        ];
    }
    $row['inkop_lines'] = $lines;
    $row['inkop_source'] = 'sigen';
    $row['inkop_sigen_key'] = $key;
    return $row;
}

jeab_sigen_catalog();

/** Katalog till kalkyl-steget. priceKey pekar på detaljfältet som får ändra priset. */
function jeab_sigen_client_catalog(): array
{
    $keys = [];
    foreach (jeab_sigen_price_fields() as $key => $spec) {
        $types = array_merge([$spec['type']], is_array($spec['also'] ?? null) ? $spec['also'] : []);
        foreach ($types as $type) {
            if ($spec['role'] === 'size') {
                $keys[$type . '|size|' . (string) ($spec['kw'] ?? '')] = $key;
            } else {
                $keys[$type . '|' . $spec['role']] = $key;
            }
        }
    }
    $out = [];
    foreach (jeab_sigen_catalog() as $type => $row) {
        $sizes = [];
        foreach (is_array($row['sizes'] ?? null) ? $row['sizes'] : [] as $kw => $size) {
            if (!is_array($size)) {
                continue;
            }
            $sizes[(string) $kw] = [
                'title' => (string) ($size['title'] ?? ''),
                'purchase' => (int) ($size['purchase'] ?? 0),
                'sku' => (string) ($size['sku'] ?? ''),
                'priceKey' => (string) ($keys[$type . '|size|' . (string) $kw] ?? ''),
            ];
        }
        $battery = is_array($row['battery'] ?? null) ? $row['battery'] : [];
        $plate = is_array($row['plate'] ?? null) ? $row['plate'] : [];
        $batteryPurchase = $battery['purchase'] ?? null;
        $out[$type] = [
            'label' => (string) ($row['label'] ?? $type),
            'sizes' => $sizes,
            'battery' => [
                'title' => (string) ($battery['title'] ?? ''),
                'purchase' => $batteryPurchase === null || $batteryPurchase === '' ? null : (int) $batteryPurchase,
                'sku' => (string) ($battery['sku'] ?? ''),
                'priceKey' => (string) ($keys[$type . '|battery'] ?? ''),
            ],
            'plate' => [
                'title' => (string) ($plate['title'] ?? 'Bottenplatta'),
                'purchase' => (int) ($plate['purchase'] ?? 0),
                'sku' => (string) ($plate['sku'] ?? ''),
                'priceKey' => (string) ($keys[$type . '|plate'] ?? ''),
            ],
            'surcharge' => (int) ($row['surcharge'] ?? 0),
            'surchargeKey' => (string) ($keys[$type . '|surcharge'] ?? ''),
        ];
    }
    return $out;
}
