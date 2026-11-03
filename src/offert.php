<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/snapshots.php';
function jeab_offert_money(float $value): string
{
    return jeab_offert_int($value);
}

function jeab_offert_int(float $value): string
{
    return number_format((int) round($value), 0, ',', ' ');
}

function jeab_offert_hours(float $value): string
{
    return number_format($value, 1, ',', ' ');
}

function jeab_offert_hours_cell(float $value): string
{
    $n = round($value, 2);
    if (abs($n - round($n)) < 0.001) {
        return number_format($n, 0, ',', ' ');
    }
    if (abs(($n * 10.0) - round($n * 10.0)) < 0.001) {
        return number_format($n, 1, ',', ' ');
    }
    return number_format($n, 2, ',', ' ');
}

function jeab_offert_ev_hours_line(float $low, float $high): string
{
    return 'Estimerad tid låg ' . jeab_offert_hours($low) . ' h · hög ' . jeab_offert_hours($high) . ' h';
}

/** @param array<string,mixed> $calc */
function jeab_offert_margins_label(array $calc): string
{
    $sol = number_format((float) $calc['c21'] * 100.0, 1, ',', ' ');
    $bat = number_format((float) $calc['c10'] * 100.0, 1, ',', ' ');
    $out = 'Marginal sol ' . $sol . ' % · batteri ' . $bat . ' %';
    if (!empty($calc['laddare'])) {
        $box = number_format((float) ($calc['laddare_margin'] ?? 0.0) * 100.0, 1, ',', ' ');
        $out .= ' · laddbox ' . $box . ' %';
    }
    return $out;
}

function jeab_offert_defaults(): array
{
    return [
        'b3' => 2.0,
        'b14' => 12.0,
        'b15' => 1.0,
        'c23' => null,
        'g27' => 70000.0,
        'c4' => 3000.0,
        'b5' => 24.0,
        'c13' => 2000.0,
        'b17' => 16.0,
        'c27' => 26362.0,
        'c28' => 1558.0,
        'tp2_unit' => 9580.0,
        'not_tp2_unit' => 22700.0,
        'laddare' => 0.0,
        'laddare_unit' => 6793.0,
        'laddare_hours' => 8.0,
        'hourly' => 750.0,
        'sokande' => 2.0,
        'sigen_surcharge' => 0.0,
        'sol_profit_base' => 54000.0,
        'sol_profit_per_panel' => 500.0,
        'sol_profit_from' => 10.0,
        'sol_profit_laddbox' => 3000.0,
        'sol_profit_tp2' => 1500.0,
    ];
}

/**
 * Vinstpåslag på solkalkylens föreslagna kundpris.
 * 54 000 + 500 kr per panel över 10, plus laddbox och TP2 när de ingår.
 * Beloppen är standardvärden och kan skrivas över i offert-standard.
 *
 * @param array<string,mixed>|null $rates
 */
function jeab_offert_sol_profit(float $panels, bool $laddbox, bool $tp2, ?array $rates = null): float
{
    $d = jeab_offert_defaults();
    if ($rates !== null) {
        foreach (['sol_profit_base', 'sol_profit_per_panel', 'sol_profit_from', 'sol_profit_laddbox', 'sol_profit_tp2'] as $key) {
            if (array_key_exists($key, $rates) && $rates[$key] !== null && $rates[$key] !== '') {
                $d[$key] = (float) $rates[$key];
            }
        }
    }
    $extra = max(0.0, $panels - (float) $d['sol_profit_from']);
    return (float) $d['sol_profit_base']
        + (float) $d['sol_profit_per_panel'] * $extra
        + ($laddbox ? (float) $d['sol_profit_laddbox'] : 0.0)
        + ($tp2 ? (float) $d['sol_profit_tp2'] : 0.0);
}

/**
 * Kundpris (att betala) som ger vinsten i den vanliga kalkylen.
 * Vinst = (total − hårdvara inkl moms) × 0,8. Grön teknik takar vid cap.
 */
function jeab_offert_sol_price_for_profit(float $profit, float $solarCost, float $batteryCost, float $hardwareIncl, float $gtCap): float
{
    if ($solarCost <= 0.0) {
        return $hardwareIncl + $profit;
    }
    $ratio = $batteryCost / $solarCost;
    $denom = 0.8545 + (0.515 * $ratio);
    $gtFactor = 0.1455 + (0.485 * $ratio);
    $uncapK = $denom != 0.0 ? $gtFactor / $denom : 0.0;
    $need = ($profit / 0.8) + $hardwareIncl;
    $uncapped = $need / (1.0 + $uncapK);
    if ($uncapped * $uncapK <= $gtCap) {
        return $uncapped;
    }
    return $need - $gtCap;
}

function jeab_offert_sigen_price_defaults(): array
{
    if (!function_exists('jeab_sigen_price_fields')) {
        return [];
    }
    $out = [];
    foreach (jeab_sigen_price_fields() as $key => $spec) {
        $out[$key] = (float) ($spec['default'] ?? 0);
    }
    return $out;
}

/**
 * Kundpris efter grön teknik för SigenStor NEO, en rad per kWh.
 * Samma belopp som batteriofferten. De dubblas inte.
 *
 * @return array<string,int>
 */
function jeab_offert_neo_kundpris_ladder(): array
{
    if (!function_exists('jeab_offer_batteri_neo_price_rows')) {
        return [];
    }
    $out = [];
    foreach (jeab_offer_batteri_neo_price_rows() as $row) {
        $row = str_replace("\u{00a0}", ' ', (string) $row);
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*kWh\s+([\d\s]+)\s*kr/u', $row, $match) !== 1) {
            continue;
        }
        $kwh = round(jeab_offert_num($match[1]), 1);
        $kr = (int) round(jeab_offert_num($match[2]));
        if ($kwh <= 0.0 || $kr <= 0) {
            continue;
        }
        $out[number_format($kwh, 1, '.', '')] = $kr;
    }
    return $out;
}

/** Kundpris för så många NEO-moduler à 7,5 kWh. Null när trappan saknas. */
function jeab_offert_neo_kundpris(float $modules): ?int
{
    $ladder = jeab_offert_neo_kundpris_ladder();
    if ($ladder === []) {
        return null;
    }
    if ($modules <= 0.0) {
        return 0;
    }
    $kwh = round($modules * 7.5, 1);
    $keys = array_map(static fn (string $key): float => (float) $key, array_keys($ladder));
    sort($keys, SORT_NUMERIC);
    $pick = null;
    foreach ($keys as $key) {
        if (abs($key - $kwh) < 0.051) {
            $pick = $key;
            break;
        }
        if ($key <= $kwh + 0.001) {
            $pick = $key;
        }
    }
    if ($pick === null) {
        $pick = $keys[0];
    }
    $price = $ladder[number_format((float) $pick, 1, '.', '')] ?? null;
    return $price === null ? null : (int) $price;
}

function jeab_offert_num(mixed $value, float $fallback = 0.0): float
{
    if ($value === null || $value === '') {
        return $fallback;
    }
    if (is_string($value)) {
        $value = str_replace(["\u{00a0}", ' ', ','], ['', '', '.'], $value);
    }
    return is_numeric($value) ? (float) $value : $fallback;
}

/**
 * Sigen-priserna från config/sigen_priser.php via jeab_sigen_apply_prices.
 * Paneler och batteriantal lämnas som de står i kalkylen. Växelriktare,
 * batteripris, bottenplatta och TP2-tillägg kommer från valet.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_offert_with_sigen(array $input): array
{
    if (!function_exists('jeab_sigen_resolve')) {
        return $input;
    }
    $type = strtolower(trim((string) ($input['sigen_typ'] ?? '')));
    if ($type === '') {
        return $input;
    }
    $panels = array_key_exists('b14', $input) ? $input['b14'] : null;
    $qty = max(0, (int) round(jeab_offert_num($input['b3'] ?? 0)));
    $choice = jeab_sigen_resolve($type, (string) ($input['sigen_kw'] ?? ''), $qty);
    if ($choice === null) {
        return $input;
    }
    if (function_exists('jeab_sigen_choice_with_input_prices')) {
        $choice = jeab_sigen_choice_with_input_prices($choice, $input);
    }
    $input = jeab_sigen_apply_prices($input, $choice);
    if ($panels !== null) {
        $input['b14'] = jeab_offert_num($panels);
    }
    $input['sigen_typ'] = $choice['type'];
    $input['sigen_kw'] = $choice['kw'];
    return $input;
}

/**
 * Indata till Kalkyl-vyn. Sparat Sigen-val vinner. Annars TP2 12 kW,
 * samma utgångsläge som kryssrutan «12 kW TP2» hade.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function jeab_offert_kalkyl_input(array $row, string $kind): array
{
    $input = jeab_offert_input_from_purchase($row, $kind);
    if (trim((string) ($input['sigen_typ'] ?? '')) === '') {
        $input['sigen_typ'] = 'tp2';
        $input['sigen_kw'] = '12.0';
    }
    return $input;
}

/** @param array<string,mixed> $input */
function jeab_offert_calc(array $input): array
{
    $input = jeab_offert_with_sigen($input);
    $sigenType = strtolower(trim((string) ($input['sigen_typ'] ?? '')));
    $sigenKw = trim((string) ($input['sigen_kw'] ?? ''));
    $g27Explicit = array_key_exists('g27', $input) && $input['g27'] !== null && $input['g27'] !== '';
    $d = jeab_offert_defaults();
    foreach ($d as $key => $fallback) {
        if ($key === 'c23') {
            continue;
        }
        if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
            $d[$key] = jeab_offert_num($input[$key], (float) $fallback);
        }
    }

    $b3 = $d['b3'];
    $b14 = $d['b14'];
    $b15 = $d['b15'] ? 1.0 : 0.0;
    $b16 = $b15 === 1.0 ? 0.0 : 1.0;
    $g27 = $d['g27'];
    if (!$g27Explicit && $sigenType === 'neo') {
        $neoPrice = jeab_offert_neo_kundpris($b3);
        if ($neoPrice !== null) {
            $g27 = (float) $neoPrice;
        }
    }
    $c4 = $d['c4'];
    $b5 = $d['b5'];
    $c13 = $d['c13'];
    $b17 = $d['b17'];
    $c27 = $d['c27'];
    $c28 = $d['c28'];
    $tp2 = $d['tp2_unit'];
    $notTp2 = $d['not_tp2_unit'];
    $laddare = $d['laddare'] ? 1.0 : 0.0;
    $laddareUnit = $d['laddare_unit'];
    $laddareHours = $d['laddare_hours'];
    $hourly = $d['hourly'];
    $sigenSurcharge = $d['sigen_surcharge'];
    $sokande = max(1, (int) round($d['sokande']));
    if (!class_exists('FortnoxAPI')) {
        require_once __DIR__ . '/fortnox.php';
    }
    $gtCap = $sokande * (float) FortnoxAPI::greenTechPersonCap();
    $laddareHw = $laddare * $laddareUnit;
    $laddareLabor = $laddare * $laddareHours * $hourly;

    $c3 = ($b3 * $c27) + 1543.0 + 1179.0;
    $c5 = $b5 * $hourly;
    $c6 = ($c3 + $c4 + $c5) * 1.25;
    $cL = ($laddareHw + $laddareLabor) * 1.25;

    $c14 = $b14 * $c28;
    $c15 = $b15 * $tp2;
    $c16 = $b16 * $notTp2;
    $c17 = $b17 * $hourly;
    $c18 = ($c13 + $c14 + $c15 + $c16 + $c17) * 1.25;
    $al21 = ($c13 + $c3 + $c14 + $c4 + $c15 + $c16 + $laddareHw) * 1.25;

    // Föreslaget kundpris är det pris som ger vinstpåslaget efter moms och grön teknik.
    $solProfit = jeab_offert_sol_profit($b14, $laddare === 1.0, $b15 === 1.0, $d);
    $c24 = jeab_offert_sol_price_for_profit($solProfit, $c18, $c6 + $cL, $al21, $gtCap);
    $c23 = array_key_exists('c23', $input) && $input['c23'] !== null && $input['c23'] !== ''
        ? jeab_offert_num($input['c23'], $c24)
        : $c24;

    $al1 = $c18;
    $al2 = $c6 + $cL;
    $al3 = $c23;

    $batteryOnly = $b14 <= 0.0 || $al1 <= 0.0;
    if ($al1 > 0.0) {
        $al5 = $al3 / (0.8545 + (0.515 * $al2 / $al1));
        $al11 = ($al2 * $al5) / $al1;
    } else {
        $al5 = 0.0;
        $al11 = $g27;
    }

    $al6 = 0.03 * $al5;
    $al7 = 0.97 * $al5;
    $al8 = 0.1455 * $al5;
    $al9 = 0.8545 * $al5;
    $al12 = 0.03 * $al11;
    $al13 = 0.97 * $al11;
    $al14 = 0.485 * $al11;
    $al15 = $al11 - $al14;
    $al17 = $al8 + $al14;
    $c9 = $al11;
    if ($al2 > 0.0) {
        $batteryList = $c6 * $al11 / $al2;
        $chargerList = $cL * $al11 / $al2;
    } else {
        $batteryList = $al11;
        $chargerList = 0.0;
    }
    $c10 = $batteryList != 0.0 ? ($batteryList - $c6) / $batteryList : 0.0;
    $laddareMargin = $chargerList != 0.0 ? ($chargerList - $cL) / $chargerList : 0.0;
    $c20 = $al5;
    $c21 = $c20 != 0.0 ? ($c20 - $c18) / $c20 : 0.0;
    $al18 = $c21;
    $al18kr = $al5 - $c18;
    $al19 = $c10;

    $h3 = 0.485 * $c9;
    $h4 = 0.1455 * $c20;
    $h5 = $h3 + $h4;
    $i5 = min($h5, $gtCap);
    $i6 = $h5 - $i5;
    $i10 = $c9 * 0.97;
    $i11 = $c20 * 0.97;
    $i19 = ($c9 * 0.03) + ($c20 * 0.03);
    $i12 = $i19 + $i6;
    $i16 = $i5;
    $i18 = $i10 + $i12 + $i11 - $i16;
    $i17 = $c23 - $i18;
    $i13 = $i17;
    $i14 = $i10 + $i11 + $i12 - $i16 + $i13;
    $i15 = $i10 + $i11 + $i12 + $i13;

    $g21 = $i15 - $al21;
    $al20 = ($i15 - $al21) * 0.8;

    $h31 = $g27 / 0.515;
    $h29Base = $h31 * 0.03;
    $h28 = $h31 - $h29Base;
    $h30Theo = $h28 * 0.5;
    $h30 = min($h30Theo, $gtCap);
    $h29 = $h29Base + ($h30Theo - $h30);
    $h33 = $g27;
    $g27Cost = ($b15 ? $tp2 : $notTp2) + ($c27 * $b3) + $c4 + $laddareHw + $sigenSurcharge;
    $g27Vinst = ($h31 / 1.25) - $g27Cost;

    return [
        'b3' => $b3,
        'b14' => $b14,
        'b15' => $b15,
        'b16' => $b16,
        'laddare' => $laddare,
        'laddare_unit' => $laddareUnit,
        'laddare_hours' => $laddareHours,
        'laddare_cost' => $cL,
        'laddare_margin' => $laddareMargin,
        'c3' => $c3,
        'c4' => $c4,
        'c5' => $c5,
        'c6' => $c6,
        'c9' => $c9,
        'c10' => $c10,
        'c13' => $c13,
        'c14' => $c14,
        'c15' => $c15,
        'c16' => $c16,
        'c17' => $c17,
        'c18' => $c18,
        'c20' => $c20,
        'c21' => $c21,
        'c23' => $c23,
        'c24' => $c24,
        'sol_profit' => $solProfit,
        'sol_profit_base' => (float) $d['sol_profit_base'],
        'sol_profit_per_panel' => (float) $d['sol_profit_per_panel'],
        'sol_profit_from' => (float) $d['sol_profit_from'],
        'sol_profit_laddbox' => (float) $d['sol_profit_laddbox'],
        'sol_profit_tp2' => (float) $d['sol_profit_tp2'],
        'c27' => $c27,
        'c28' => $c28,
        'g27' => $g27,
        'al1' => $al1,
        'al2' => $al2,
        'al3' => $al3,
        'al5' => $al5,
        'al6' => $al6,
        'al7' => $al7,
        'al8' => $al8,
        'al9' => $al9,
        'al11' => $al11,
        'al12' => $al12,
        'al13' => $al13,
        'al14' => $al14,
        'al15' => $al15,
        'al17' => $al17,
        'al18' => $al18,
        'al18kr' => $al18kr,
        'al19' => $al19,
        'al20' => $al20,
        'al21' => $al21,
        'g21' => $g21,
        'h3' => $h3,
        'h4' => $h4,
        'h5' => $h5,
        'i5' => $i5,
        'i6' => $i6,
        'i10' => $i10,
        'i11' => $i11,
        'i12' => $i12,
        'i13' => $i13,
        'i14' => $i14,
        'i15' => $i15,
        'i16' => $i16,
        'i17' => $i17,
        'i18' => $i18,
        'i19' => $i19,
        'h28' => $h28,
        'h29' => $h29,
        'h30' => $h30,
        'h31' => $h31,
        'h33' => $h33,
        'g27_cost' => $g27Cost,
        'g27_vinst' => $g27Vinst,
        'sigen_surcharge' => $sigenSurcharge,
        'tp2_unit' => $tp2,
        'not_tp2_unit' => $notTp2,
        'sokande' => (float) $sokande,
        'gt_cap' => $gtCap,
        'battery_only' => $batteryOnly,
        'sigen_typ' => $sigenType,
        'sigen_kw' => $sigenKw,
    ];
}

/** Samma +/- och kryssrutor som kalkyl-steget (Offert). */
function jeab_offert_daily_controls_html(array $calc): string
{
    $check = '<svg viewBox="0 0 20 20" width="24" height="24" fill="none"><circle cx="10" cy="10" r="9" fill="currentColor"/><path d="M6.2 10.2 8.7 12.7 13.8 7.4" stroke="#0b0c0e" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    ob_start();
    ?>
    <label class="offert-field">
        <span>Paneler</span>
        <span class="offert-step">
            <button type="button" data-js="offert-step" data-for="b14" data-delta="-1" aria-label="Minska paneler">−</button>
            <input type="text" inputmode="numeric" name="b14" data-in="b14" value="<?= e(jeab_offert_int((float) ($calc['b14'] ?? 0))) ?>">
            <button type="button" data-js="offert-step" data-for="b14" data-delta="1" aria-label="Öka paneler">+</button>
        </span>
    </label>
    <label class="offert-field offert-mini">
        <span>Batterimoduler</span>
        <span class="offert-step">
            <button type="button" data-js="offert-step" data-for="b3" data-delta="-1" aria-label="Minska batterimoduler">−</button>
            <input type="text" inputmode="numeric" name="b3" data-in="b3" value="<?= e(jeab_offert_int((float) ($calc['b3'] ?? 0))) ?>">
            <button type="button" data-js="offert-step" data-for="b3" data-delta="1" aria-label="Öka batterimoduler">+</button>
        </span>
    </label>
    <label class="offert-field offert-mini">
        <span>Antal sökande</span>
        <span class="offert-step">
            <button type="button" data-js="offert-step" data-for="sokande" data-delta="-1" aria-label="Minska sökande">−</button>
            <input type="text" inputmode="numeric" name="sokande" data-in="sokande" value="<?= e(jeab_offert_int((float) ($calc['sokande'] ?? 1))) ?>">
            <button type="button" data-js="offert-step" data-for="sokande" data-delta="1" aria-label="Öka sökande">+</button>
        </span>
    </label>
    <?php
    $sigenType = strtolower(trim((string) ($calc['sigen_typ'] ?? '')));
    $sigenKw = function_exists('jeab_sigen_kw_key') ? jeab_sigen_kw_key((string) ($calc['sigen_kw'] ?? '')) : '';
    if (!function_exists('jeab_sigen_sizes') || !in_array($sigenKw, jeab_sigen_sizes($sigenType), true)) {
        $sigenType = 'tp2';
        $sigenKw = '12.0';
    }
    $sigenCatalog = function_exists('jeab_sigen_client_catalog') ? jeab_sigen_client_catalog() : [];
    $sigenJson = json_encode($sigenCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sigenShowSize = count(jeab_sigen_sizes($sigenType)) > 1;
    ?>
    <div class="offert-sigen" data-sigen="<?= e($sigenJson !== false ? $sigenJson : '{}') ?>">
        <div class="offert-sigen-pick">
            <div class="offert-field">
                <span id="offert-sigen-typ">System</span>
                <span class="offert-seg" role="radiogroup" aria-labelledby="offert-sigen-typ">
                    <?php foreach ($sigenCatalog as $type => $row): ?>
                        <?php $on = $sigenType === $type; ?>
                        <label class="offert-seg-opt<?= $on ? ' is-on' : '' ?>">
                            <input type="radio" name="sigen_typ" value="<?= e($type) ?>"<?= $on ? ' checked' : '' ?>>
                            <span><?= e((string) ($row['label'] ?? $type)) ?></span>
                        </label>
                    <?php endforeach; ?>
                </span>
            </div>
            <div class="offert-field offert-sigen-size" data-sigen-sizes<?= $sigenShowSize ? '' : ' hidden' ?>>
                <span id="offert-sigen-kw">Storlek</span>
                <span class="offert-seg" role="radiogroup" aria-labelledby="offert-sigen-kw">
                    <?php foreach (is_array($sigenCatalog[$sigenType]['sizes'] ?? null) ? $sigenCatalog[$sigenType]['sizes'] : [] as $kw => $size): ?>
                        <?php $on = $sigenKw === (string) $kw; ?>
                        <label class="offert-seg-opt<?= $on ? ' is-on' : '' ?>">
                            <input type="radio" name="sigen_kw" value="<?= e((string) $kw) ?>"<?= $on ? ' checked' : '' ?>>
                            <span><?= e(rtrim(rtrim((string) $kw, '0'), '.')) ?> kW</span>
                        </label>
                    <?php endforeach; ?>
                </span>
            </div>
        </div>
    </div>
    <input type="hidden" name="b15" data-in="b15" value="<?= $sigenType === 'tp2' ? '1' : '0' ?>">
    <input type="hidden" data-in="sol_profit_base" value="<?= e(jeab_offert_int((float) ($calc['sol_profit_base'] ?? 54000))) ?>">
    <input type="hidden" data-in="sol_profit_per_panel" value="<?= e(jeab_offert_int((float) ($calc['sol_profit_per_panel'] ?? 500))) ?>">
    <input type="hidden" data-in="sol_profit_from" value="<?= e(jeab_offert_int((float) ($calc['sol_profit_from'] ?? 10))) ?>">
    <input type="hidden" data-in="sol_profit_laddbox" value="<?= e(jeab_offert_int((float) ($calc['sol_profit_laddbox'] ?? 3000))) ?>">
    <input type="hidden" data-in="sol_profit_tp2" value="<?= e(jeab_offert_int((float) ($calc['sol_profit_tp2'] ?? 1500))) ?>">
    <label class="offert-check<?= !empty($calc['laddare']) ? ' is-on' : '' ?>">
        <input type="checkbox" name="laddare" data-in="laddare" value="1"<?= !empty($calc['laddare']) ? ' checked' : '' ?>>
        <span class="offert-check-ui" aria-hidden="true"><?= $check ?></span>
        <span class="offert-check-label">Elbilsladdare</span>
    </label>
    <?php
    return (string) ob_get_clean();
}

/** Inköpspriserna i «Ändra och visa detaljer». Ett fält per pris. */
function jeab_offert_sigen_price_fields_html(array $values): string
{
    if (!function_exists('jeab_sigen_price_fields')) {
        return '';
    }
    $html = '';
    $group = '';
    foreach (jeab_sigen_price_fields() as $key => $spec) {
        if (($spec['group'] ?? '') !== $group) {
            $group = (string) $spec['group'];
            $html .= '<p class="offert-price-group">' . e($group) . '</p>';
        }
        $value = jeab_offert_int((float) ($values[$key] ?? $spec['default'] ?? 0));
        $html .= '<label class="offert-field"><span>' . e((string) $spec['label']) . '</span>'
            . '<input type="text" inputmode="numeric" data-in="' . e($key) . '" value="' . e($value) . '"></label>';
    }
    return $html;
}

function jeab_offert_ev_defaults(): array
{
    return [
        'c4' => 120.0,
        'c5' => 120.0,
        'c6' => 33.0,
        'c7' => 66.0,
        'c8' => 0.0,
        'c9' => 1.0,
        'c10' => 0.0,
        'c11' => 0.0,
        'c12' => 0.0,
        'd4' => 80.0,
        'd5' => 180.0,
        'd6' => 580.0,
        'd7' => 7800.0,
        'd8' => 1000.0,
        'd9' => 10000.0,
        'hourly_extra' => 600.0,
        'hour_low' => 750.0,
        'hour_high' => 1000.0,
        'mat_base' => 5000.0,
        'mat_per_low' => 200.0,
        'mat_per_high' => 500.0,
        'c21' => 3.0,
        'd21' => 5.0,
    ];
}

function jeab_offert_defaults_file(): string
{
    $override = getenv('JEAB_OFFERT_DEFAULTS_FILE');
    if (is_string($override) && $override !== '') {
        return $override;
    }
    return jeab_data_file('offert-defaults.json');
}

/**
 * Batterival som finns i katalogen. Saknad eller okänd storlek ger null.
 *
 * @return array{sigen_typ:string,sigen_kw:string}|null
 */
function jeab_offert_battery_choice(mixed $type, mixed $kw): ?array
{
    if (!function_exists('jeab_sigen_kw_key') || !function_exists('jeab_sigen_catalog_base')) {
        return null;
    }
    $type = strtolower(trim((string) $type));
    $key = jeab_sigen_kw_key((string) $kw);
    if ($type === '' || $key === '') {
        return null;
    }
    $row = jeab_sigen_catalog_base()[$type] ?? null;
    $sizes = is_array($row) && is_array($row['sizes'] ?? null) ? array_keys($row['sizes']) : [];
    if (!in_array($key, $sizes, true)) {
        return null;
    }
    return ['sigen_typ' => $type, 'sigen_kw' => $key];
}

/** @return array{offert: array<string,float>, ev: array<string,float>, sigen: array{sigen_typ:string,sigen_kw:string}|null} */
function jeab_offert_load_standard(?JeabStore $store = null): array
{
    $offert = jeab_offert_defaults() + jeab_offert_sigen_price_defaults();
    unset($offert['c23']);
    $ev = jeab_offert_ev_defaults();
    $raw = '';
    if ($store) {
        $raw = (string) (jeab_setting($store, 'offert_defaults', '') ?? '');
    }
    if ($raw === '') {
        $file = jeab_offert_defaults_file();
        if (is_file($file)) {
            $raw = (string) file_get_contents($file);
        }
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['offert' => $offert, 'ev' => $ev, 'sigen' => null];
    }
    $savedOffert = isset($data['offert']) && is_array($data['offert']) ? $data['offert'] : $data;
    foreach ($offert as $key => $fallback) {
        if (array_key_exists($key, $savedOffert) && $savedOffert[$key] !== null && $savedOffert[$key] !== '') {
            $offert[$key] = jeab_offert_num($savedOffert[$key], (float) $fallback);
        }
    }
    $savedEv = isset($data['ev']) && is_array($data['ev']) ? $data['ev'] : [];
    foreach ($ev as $key => $fallback) {
        if (array_key_exists($key, $savedEv) && $savedEv[$key] !== null && $savedEv[$key] !== '') {
            $ev[$key] = jeab_offert_num($savedEv[$key], (float) $fallback);
        }
    }
    $savedSigen = isset($data['sigen']) && is_array($data['sigen']) ? $data['sigen'] : [];
    $sigen = jeab_offert_battery_choice($savedSigen['sigen_typ'] ?? '', $savedSigen['sigen_kw'] ?? '');
    return ['offert' => $offert, 'ev' => $ev, 'sigen' => $sigen];
}

/** @return array{sigen_typ:string,sigen_kw:string}|null */
function jeab_offert_saved_battery(?JeabStore $store = null): ?array
{
    $loaded = jeab_offert_load_standard($store);
    $sigen = $loaded['sigen'] ?? null;
    return is_array($sigen) ? $sigen : null;
}

/** @return array<string,float> */
function jeab_offert_standard_map(?JeabStore $store = null): array
{
    $loaded = jeab_offert_load_standard($store);
    $map = $loaded['offert'];
    foreach ($loaded['ev'] as $key => $value) {
        $map['ev_' . $key] = $value;
    }
    return $map;
}

/** @param array<string,mixed> $input */
function jeab_offert_save_standard(JeabStore $store, array $input): array
{
    $loaded = jeab_offert_load_standard($store);
    $offert = $loaded['offert'];
    $ev = $loaded['ev'];
    $sigen = is_array($loaded['sigen'] ?? null) ? $loaded['sigen'] : null;
    $postedBattery = jeab_offert_battery_choice($input['sigen_typ'] ?? '', $input['sigen_kw'] ?? '');
    if ($postedBattery !== null) {
        $sigen = $postedBattery;
    }
    // b15 följer systemvalet i kalkylen. När batteriet sparas lämnas den
    // lagrade flaggan, så priserna inte skrivs om av förvalet.
    if (array_key_exists('b15', $input) && $postedBattery === null) {
        $offert['b15'] = jeab_offert_num($input['b15'], 0.0) ? 1.0 : 0.0;
    }
    if (array_key_exists('laddare', $input)) {
        $offert['laddare'] = jeab_offert_num($input['laddare'], 0.0) ? 1.0 : 0.0;
    }
    foreach ($offert as $key => $fallback) {
        if ($key === 'b15' || $key === 'laddare') {
            continue;
        }
        if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
            $offert[$key] = jeab_offert_num($input[$key], (float) $fallback);
        }
    }
    foreach ($ev as $key => $fallback) {
        $posted = $input['ev_' . $key] ?? null;
        if ($posted !== null && $posted !== '') {
            $ev[$key] = jeab_offert_num($posted, (float) $fallback);
        }
    }
    $payloadData = ['offert' => $offert, 'ev' => $ev];
    if ($sigen !== null) {
        $payloadData['sigen'] = $sigen;
    }
    $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        throw new RuntimeException('Kunde inte spara standard.');
    }
    jeab_set_setting($store, 'offert_defaults', $payload);
    $file = jeab_offert_defaults_file();
    $dir = dirname($file);
    if (is_dir($dir) && is_writable($dir) && file_put_contents($file, $payload) === false) {
        throw new RuntimeException('Kunde inte spara standard.');
    }
    return ['offert' => $offert, 'ev' => $ev, 'sigen' => $sigen];
}

function jeab_offert_parse_kwh_modules(mixed $raw): ?float
{
    $text = trim((string) $raw);
    if ($text === '' || !preg_match('/(\d+(?:[.,]\d+)?)/u', $text, $match)) {
        return null;
    }
    $n = jeab_offert_num($match[1]);
    if ($n <= 0) {
        return null;
    }
    if ($n >= 5) {
        return max(1.0, round($n / 8.0));
    }
    return max(1.0, round($n));
}

/** @param array<string,mixed> $row */
function jeab_offert_inkop_counts(array $row): array
{
    $panels = 0;
    $modules = 0;
    $lines = is_array($row['inkop_lines'] ?? null) ? $row['inkop_lines'] : [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $qty = (int) ($line['qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $hay = trim((string) ($line['title'] ?? '') . ' ' . (string) ($line['sku'] ?? ''));
        if ($hay === '') {
            continue;
        }
        $isPanel = (bool) preg_match('/panel|solpanel|solcell/iu', $hay);
        $isBat = (bool) preg_match('/modul|batteri|battery|sigen/iu', $hay);
        if ($isPanel && !$isBat) {
            $panels += $qty;
        } elseif ($isBat && !$isPanel) {
            $modules += $qty;
        }
    }
    return ['b14' => $panels, 'b3' => $modules];
}

/**
 * Kundblock för offert och PDF. Flaggan av är radens egna fält.
 * Kontaktens tomma fält målar inte över. Skatten (antal sökande) räknas inte här.
 *
 * @param array<string, mixed> $row
 * @return array{name:string,email:string,phone:string,street:string,postal:string,city:string,display:string}
 */
function jeab_offert_kundblock(array $row): array
{
    $name = trim((string) ($row['name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($row['customer_name'] ?? ''));
    }
    if ($name === '') {
        $name = trim((string) ($row['huvudsokande'] ?? ''));
    }
    $block = [
        'name' => $name,
        'email' => trim((string) ($row['email'] ?? '')),
        'phone' => trim((string) ($row['phone'] ?? '')),
        'street' => trim((string) ($row['gata'] ?? $row['street'] ?? '')),
        'postal' => trim((string) ($row['postnummer'] ?? $row['postal'] ?? $row['postnr'] ?? '')),
        'city' => trim((string) ($row['ort'] ?? $row['city'] ?? '')),
        'display' => trim((string) ($row['address'] ?? $row['huvudsokande_address'] ?? '')),
    ];
    if (function_exists('jeab_snapshots_should_freeze') && jeab_snapshots_should_freeze($row, 'offert')) {
        $frozen = jeab_snapshots_frozen_party($row, 'offert');
        $block['name'] = trim((string) ($frozen['name'] ?? ''));
        $block['email'] = trim((string) ($frozen['email'] ?? ''));
        $block['phone'] = trim((string) ($frozen['phone'] ?? ''));
        $block['display'] = trim((string) ($frozen['address'] ?? ''));
        if ($block['display'] !== '') {
            $block['street'] = $block['display'];
        }

        return $block;
    }
    if (!function_exists('jeab_contacts_sole') || !jeab_contacts_sole() || !function_exists('jeab_contacts_sole_recipient') || !function_exists('jeab_contacts_sole_recipient_contact')) {
        return $block;
    }
    if (trim((string) ($row['contact_id'] ?? '')) === '') {
        return $block;
    }
    $contact = jeab_contacts_sole_recipient_contact($row);
    if (!is_array($contact) || !function_exists('jeab_contacts_sole_view_from_contact')) {
        return $block;
    }
    $view = jeab_contacts_sole_view_from_contact($contact);
    $rec = jeab_contacts_sole_recipient($row);
    $strict = function_exists('jeab_contacts_strict') && jeab_contacts_strict();
    foreach (['name', 'email', 'phone', 'street', 'postal', 'city', 'display'] as $key) {
        if ($strict || trim((string) ($view[$key] ?? '')) !== '') {
            $block[$key] = (string) ($rec[$key] ?? '');
        }
    }

    return $block;
}

/** Tom sträng när offerten får gå. Annars felet, och en rad i fellogg. */
function jeab_offert_outgoing_block(array $row): string
{
    if (!function_exists('jeab_contacts_strict_block')) {
        return '';
    }

    return jeab_contacts_strict_block($row, 'offert');
}

/** @param array<string,mixed> $row */
function jeab_offert_sokande_from_purchase(array $row): int
{
    $owners = function_exists('jeab_ordrar_people_list')
        ? jeab_ordrar_people_list($row['owners'] ?? [])
        : [];
    if ($owners !== [] && function_exists('jeab_ordrar_applicant_people')) {
        return max(1, count(jeab_ordrar_applicant_people($row)));
    }
    $med = function_exists('jeab_ordrar_people_list')
        ? jeab_ordrar_people_list($row['medsokande'] ?? [])
        : [];
    if ($med !== []) {
        return max(1, 1 + count($med));
    }
    $loaded = jeab_offert_load_standard();
    return max(1, (int) round((float) ($loaded['offert']['sokande'] ?? 2)));
}

/** @param array<string,mixed> $row */
function jeab_offert_input_from_purchase(array $row, string $kind = ''): array
{
    $loaded = jeab_offert_load_standard();
    $input = $loaded['offert'];
    $price = (int) ($row['kundpris'] ?? $row['pris'] ?? $row['price'] ?? 0);
    if ($price <= 0 && isset($row['price'])) {
        $price = (int) $row['price'];
    }
    if ($kind === 'sol' && $price > 0) {
        $input['c23'] = (float) $price;
    }
    if ($kind === 'batteri' && $price > 0) {
        $input['g27'] = (float) $price;
    }
    $input['sokande'] = (float) jeab_offert_sokande_from_purchase($row);

    $vaxel = trim((string) ($row['vaxelriktare'] ?? ''));
    $fold = function_exists('mb_strtolower') ? mb_strtolower($vaxel, 'UTF-8') : strtolower($vaxel);
    if (preg_match('/ej\s*tp2|inte\s*tp2|utan\s*tp2|20\s*kw/u', $fold)) {
        $input['b15'] = 0.0;
    } elseif ($vaxel === '' || preg_match('/tp2/u', $fold)) {
        $input['b15'] = 1.0;
    }

    $fromKwh = jeab_offert_parse_kwh_modules($row['kwh'] ?? '');
    $counts = jeab_offert_inkop_counts($row);
    if ($fromKwh !== null) {
        $input['b3'] = $fromKwh;
    } elseif ($counts['b3'] > 0) {
        $input['b3'] = (float) $counts['b3'];
    }
    if ($counts['b14'] > 0) {
        $input['b14'] = (float) $counts['b14'];
    }
    $stored = is_array($row['kalkyl'] ?? null) ? $row['kalkyl'] : [];
    foreach (['b14', 'b3', 'sokande', 'b15', 'laddare'] as $key) {
        if (!array_key_exists($key, $stored) || $stored[$key] === '' || $stored[$key] === null) {
            continue;
        }
        $input[$key] = $key === 'sokande'
            ? (float) max(1, (int) round(jeab_offert_num($stored[$key])))
            : jeab_offert_num($stored[$key]);
    }
    foreach (['sigen_typ', 'sigen_kw'] as $key) {
        $value = trim((string) ($stored[$key] ?? ''));
        if ($value !== '') {
            $input[$key] = $value;
        }
    }
    if ($kind === 'batteri' && function_exists('jeab_sigen_choice')) {
        $choice = jeab_sigen_choice($row);
        if ($choice !== null) {
            $input = jeab_sigen_apply_prices($input, $choice);
            $input['sigen_typ'] = $choice['type'];
            $input['sigen_kw'] = $choice['kw'];
        }
    }
    return $input;
}

/**
 * Det Kalkyl visar som det kunden betalar, i hela kronor.
 * Sol: Att betala (i14). Batteri: Summa minus Grön teknik (samma som kundpris när taket inte binder).
 *
 * @param array<string,mixed> $calc
 */
function jeab_offert_kalkyl_att_betala(array $calc, string $kind): int
{
    if ($kind === 'batteri') {
        return (int) round((float) ($calc['h31'] ?? 0) - (float) ($calc['h30'] ?? 0));
    }
    return (int) round((float) ($calc['i14'] ?? 0));
}

/**
 * Möjlig grön teknik på logiska orderrader. Samma floor(underlag × sats) som Fortnox-ansökan.
 *
 * @param list<array<string,mixed>> $rows
 */
function jeab_offert_logical_gt_deduction(array $rows, int $people = 1): int
{
    if (!class_exists('FortnoxAPI')) {
        require_once __DIR__ . '/fortnox.php';
    }
    $bases = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $hw = $row['hw'] ?? null;
        if (!is_string($hw) || $hw === '' || !isset(FortnoxAPI::greenTechRates()[$hw])) {
            continue;
        }
        $bases[$hw] = ($bases[$hw] ?? 0.0) + (float) ($row['price'] ?? 0);
    }
    return FortnoxAPI::greenTechPossible($bases, $people);
}

/**
 * Schablonradens kronor. Batteri: avrundat brutto minus avrundat underlag,
 * alltså 3 % av h31. Sol: totalen minus batteri och solpaneler.
 *
 * @param array<string,mixed> $calc
 */
function jeab_offert_schablon_kronor(array $calc, string $kind): int
{
    if ($kind === 'sol') {
        return (int) round((float) ($calc['i15'] ?? 0))
            - (int) round((float) ($calc['i10'] ?? 0))
            - (int) round((float) ($calc['i11'] ?? 0));
    }

    return (int) round((float) ($calc['h31'] ?? 0)) - (int) round((float) ($calc['h28'] ?? 0));
}

/**
 * Att betala på raderna när ansökan är hela möjliga skattereduktionen.
 *
 * @param list<array<string,mixed>> $rows
 */
function jeab_offert_rows_att_betala(array $rows, int $people = 1): int
{
    $sum = 0;
    foreach ($rows as $row) {
        if (is_array($row)) {
            $sum += (int) ($row['price'] ?? 0);
        }
    }
    return $sum - jeab_offert_logical_gt_deduction($rows, $people);
}

/**
 * Öresresten mellan avrundade rader och Kalkylens Att betala läggs på schablonraden.
 * Bara en eller två kronor. Ett tak som flyttar tusenlappar lämnas åt formeln.
 *
 * @param list<array{article:string,desc:string,price:int,hw:?string,hours:int}> $rows
 * @return list<array{article:string,desc:string,price:int,hw:?string,hours:int}>
 */
function jeab_offert_align_rows_to_att_betala(array $rows, int $target, int $people = 1): array
{
    $delta = jeab_offert_rows_att_betala($rows, $people) - $target;
    if ($delta === 0 || abs($delta) > 2) {
        return $rows;
    }
    for ($i = count($rows) - 1; $i >= 0; $i--) {
        $hw = $rows[$i]['hw'] ?? null;
        if (is_string($hw) && $hw !== '') {
            continue;
        }
        $rows[$i]['price'] = (int) $rows[$i]['price'] - $delta;
        return $rows;
    }
    return $rows;
}

/**
 * Logical Fortnox rows for a JEAB köp. Prices are before grön-teknik.
 *
 * @param array<string,mixed> $purchase
 * @return list<array{article:string,desc:string,price:int,hw:?string,hours:int}>
 */
function jeab_offert_fortnox_kop_rows(string $kind, int $price, string $installWhat = '', array $purchase = []): array
{
    if ($price <= 0) {
        throw new InvalidArgumentException('Priset saknas.');
    }
    if ($kind === 'laddbox') {
        $travel = 45;
        // Package line is (kundpris - framkörning) * 2 for grön teknik. Must stay > 0.
        if ($price <= $travel) {
            throw new InvalidArgumentException('Laddbox-pris måste vara över ' . $travel . ' kr (efter grön teknik), annars blir Fortnox-raden ≤ 0 och skattereduktionen failar.');
        }
        $desc = trim($installWhat);
        return [
            [
                'article' => 'charging',
                'desc' => $desc !== '' ? $desc : 'Laddbox',
                'price' => ($price - $travel) * 2,
                'hw' => 'CHARGINGSTATIONELECTRICVEHICLE',
                'hours' => 4,
            ],
            [
                'article' => '',
                'desc' => 'Framkörning',
                'price' => $travel,
                'hw' => null,
                'hours' => 0,
            ],
        ];
    }

    $calc = jeab_offert_calc(jeab_offert_input_from_purchase($purchase + ['kundpris' => $price], $kind));
    if ($kind === 'sol') {
        $batteri = (int) round((float) $calc['i10']);
        $sol = (int) round((float) $calc['i11']);
        $total = (int) round((float) $calc['i15']);
        $rows = [
            [
                'article' => 'battery',
                'desc' => 'Batteri',
                'price' => $batteri,
                'hw' => 'STORAGESELFPRODUCEDELECTRICITY',
                'hours' => 24,
            ],
            [
                'article' => 'solar',
                'desc' => 'Solpaneler',
                'price' => $sol,
                'hw' => 'SOLARCELLS',
                'hours' => 16,
            ],
            [
                'article' => 'material',
                'desc' => 'Schablon',
                'price' => jeab_offert_schablon_kronor($calc, 'sol'),
                'hw' => null,
                'hours' => 0,
            ],
        ];
        $people = max(1, (int) round((float) ($calc['sokande'] ?? 1)));
        return jeab_offert_align_rows_to_att_betala($rows, jeab_offert_kalkyl_att_betala($calc, 'sol'), $people);
    }
    if ($kind === 'batteri') {
        $utan = (int) round((float) $calc['h28']);
        $summa = (int) round((float) $calc['h31']);
        // Schablon = summa − batteri. När person-taket flyttar överskott till h29
        // (h28+h29 > h31) ska raderna fortfarande summera till h31, annars
        // stämmer inte Att betala mot Kalkyl när avdraget är taket × sökande.
        $schablon = jeab_offert_schablon_kronor($calc, 'batteri');
        $rows = [
            [
                'article' => 'battery',
                'desc' => 'Batteri',
                'price' => $utan,
                'hw' => 'STORAGESELFPRODUCEDELECTRICITY',
                'hours' => 24,
            ],
            [
                'article' => 'material',
                'desc' => 'Schablon',
                'price' => $schablon,
                'hw' => null,
                'hours' => 0,
            ],
        ];
        $people = max(1, (int) round((float) ($calc['sokande'] ?? 1)));
        $rows = jeab_offert_align_rows_to_att_betala($rows, jeab_offert_kalkyl_att_betala($calc, 'batteri'), $people);
        if (function_exists('jeab_sigen_choice') && function_exists('jeab_sigen_fortnox_rows')) {
            $choice = jeab_sigen_choice($purchase);
            if ($choice !== null) {
                return jeab_sigen_fortnox_rows($rows, $choice, isset($purchase['company']) ? (string) $purchase['company'] : null);
            }
        }
        return $rows;
    }
    throw new InvalidArgumentException('Välj Laddbox, Batteri eller Solceller.');
}

function jeab_offert_rows_match_payable(array $rows, int $payable, int $people): bool
{
    foreach ($rows as $row) {
        if (!is_array($row) || (int) ($row['price'] ?? 0) < 0) {
            return false;
        }
    }
    return jeab_offert_rows_att_betala($rows, $people) === $payable;
}

function jeab_offert_payable_impossible(int $payable): InvalidArgumentException
{
    $shown = number_format($payable, 0, ',', ' ');
    return new InvalidArgumentException('Att betala ' . $shown . ' kr går inte att fakturera. En rad skulle bli negativ.');
}

/**
 * Fakturarader för det kunden skriver i Att betala. Beloppet är det som
 * betalas efter grön teknik, även när taket 50 000 kr per sökande binder.
 * Bruttot är det kalkylen behöver för att landa där. Ingen rad är negativ.
 * När den vanliga kalkylen redan gör det är raderna desamma som idag.
 *
 * @param array<string,mixed> $purchase
 * @return list<array{article:string,desc:string,price:int,hw:?string,hours:int,qty?:int}>
 */
function jeab_offert_rows_for_payable(string $kind, int $payable, string $installWhat = '', array $purchase = []): array
{
    if ($payable <= 0) {
        throw jeab_offert_payable_impossible($payable);
    }
    if (!class_exists('FortnoxAPI')) {
        require_once __DIR__ . '/fortnox.php';
    }
    $kind = function_exists('jeab_purchase_normalize_kind')
        ? (string) (jeab_purchase_normalize_kind($kind) ?? $kind)
        : $kind;
    $purchase['kundpris'] = $payable;
    $purchase['pris'] = $payable;
    $purchase['price'] = $payable;
    if ($kind === 'laddbox' && $payable <= 45) {
        throw jeab_offert_payable_impossible($payable);
    }
    $input = jeab_offert_input_from_purchase($purchase, $kind);
    $people = max(1, (int) round((float) ($input['sokande'] ?? 1)));
    try {
        $current = jeab_offert_fortnox_kop_rows($kind, $payable, $installWhat, $purchase);
    } catch (InvalidArgumentException $e) {
        throw jeab_offert_payable_impossible($payable);
    }
    if (jeab_offert_rows_match_payable($current, $payable, $people)) {
        return $current;
    }
    $rows = match ($kind) {
        'laddbox' => jeab_offert_payable_laddbox($payable, $installWhat, $people),
        'batteri' => jeab_offert_payable_batteri($payable, $people, $purchase),
        'sol' => jeab_offert_payable_sol($current, $payable, $people),
        default => throw new InvalidArgumentException('Välj Laddbox, Batteri eller Solceller.'),
    };
    return jeab_offert_force_payable($rows, $payable, $people);
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function jeab_offert_force_payable(array $rows, int $payable, int $people): array
{
    foreach ($rows as $row) {
        if (!is_array($row) || (int) ($row['price'] ?? 0) < 0) {
            throw jeab_offert_payable_impossible($payable);
        }
    }
    $delta = jeab_offert_rows_att_betala($rows, $people) - $payable;
    if ($delta === 0) {
        return $rows;
    }
    for ($i = count($rows) - 1; $i >= 0; $i--) {
        $hw = $rows[$i]['hw'] ?? null;
        if (is_string($hw) && $hw !== '') {
            continue;
        }
        $next = (int) $rows[$i]['price'] - $delta;
        if ($next < 0) {
            continue;
        }
        $rows[$i]['price'] = $next;
        if (jeab_offert_rows_att_betala($rows, $people) === $payable) {
            return $rows;
        }
        $rows[$i]['price'] = $next + $delta;
    }
    throw jeab_offert_payable_impossible($payable);
}

/** @return list<array{article:string,desc:string,price:int,hw:?string,hours:int}> */
function jeab_offert_payable_laddbox(int $payable, string $installWhat, int $people): array
{
    $travel = 45;
    $cap = $people * FortnoxAPI::greenTechPersonCap();
    $charge = $payable - $travel + $cap;
    $green = min((int) floor($charge * 0.5), $cap);
    if ($charge <= 0 || $green < $cap || $charge + $travel - $green !== $payable) {
        throw jeab_offert_payable_impossible($payable);
    }
    $desc = trim($installWhat);
    return [
        [
            'article' => 'charging',
            'desc' => $desc !== '' ? $desc : 'Laddbox',
            'price' => $charge,
            'hw' => 'CHARGINGSTATIONELECTRICVEHICLE',
            'hours' => 4,
        ],
        [
            'article' => '',
            'desc' => 'Framkörning',
            'price' => $travel,
            'hw' => null,
            'hours' => 0,
        ],
    ];
}

/**
 * @param array<string,mixed> $purchase
 * @return list<array<string,mixed>>
 */
function jeab_offert_payable_batteri(int $payable, int $people, array $purchase): array
{
    $cap = $people * FortnoxAPI::greenTechPersonCap();
    $gross = $payable + $cap;
    $battery = (int) round($gross - ($gross * 0.03));
    if ($battery < 0) {
        $battery = 0;
    }
    if ($battery > $gross) {
        $battery = $gross;
    }
    $schablon = $gross - $battery;
    $green = min((int) floor($battery * 0.5), $cap);
    if ($green < $cap || $schablon < 0) {
        $found = null;
        for ($candidate = $gross; $candidate >= 0; $candidate--) {
            $candidateGreen = min((int) floor($candidate * 0.5), $cap);
            $candidateSchablon = $payable + $candidateGreen - $candidate;
            if ($candidateSchablon >= 0) {
                $found = [$candidate, $candidateSchablon];
                break;
            }
        }
        if ($found === null) {
            throw jeab_offert_payable_impossible($payable);
        }
        [$battery, $schablon] = $found;
    }
    $rows = [
        [
            'article' => 'battery',
            'desc' => 'Batteri',
            'price' => $battery,
            'hw' => 'STORAGESELFPRODUCEDELECTRICITY',
            'hours' => 24,
        ],
        [
            'article' => 'material',
            'desc' => 'Schablon',
            'price' => $schablon,
            'hw' => null,
            'hours' => 0,
        ],
    ];
    if (function_exists('jeab_sigen_choice') && function_exists('jeab_sigen_fortnox_rows')) {
        $choice = jeab_sigen_choice($purchase);
        if ($choice !== null) {
            return jeab_sigen_fortnox_rows($rows, $choice, isset($purchase['company']) ? (string) $purchase['company'] : null);
        }
    }
    return $rows;
}

/**
 * @param list<array<string,mixed>> $current
 * @return list<array<string,mixed>>
 */
function jeab_offert_payable_sol(array $current, int $payable, int $people): array
{
    $batteryWeight = 0;
    $solarWeight = 0;
    foreach ($current as $row) {
        if (!is_array($row)) {
            continue;
        }
        $price = max(0, (int) ($row['price'] ?? 0));
        $desc = (string) ($row['desc'] ?? '');
        if ($desc === 'Batteri') {
            $batteryWeight = $price;
        } elseif ($desc === 'Solpaneler') {
            $solarWeight = $price;
        }
    }
    $best = null;
    for ($scale = 10000; $scale >= 0; $scale--) {
        $battery = (int) round($batteryWeight * $scale / 10000);
        $solar = (int) round($solarWeight * $scale / 10000);
        $probe = $current;
        foreach ($probe as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $desc = (string) ($row['desc'] ?? '');
            if ($desc === 'Batteri') {
                $probe[$i]['price'] = $battery;
            } elseif ($desc === 'Solpaneler') {
                $probe[$i]['price'] = $solar;
            } elseif ($desc === 'Schablon') {
                $probe[$i]['price'] = 0;
            }
        }
        $green = jeab_offert_logical_gt_deduction($probe, $people);
        $schablon = $payable + $green - $battery - $solar;
        if ($schablon >= 0) {
            $best = [$battery, $solar, $schablon];
            break;
        }
    }
    if ($best === null) {
        throw jeab_offert_payable_impossible($payable);
    }
    [$battery, $solar, $schablon] = $best;
    foreach ($current as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $desc = (string) ($row['desc'] ?? '');
        if ($desc === 'Batteri') {
            $current[$i]['price'] = $battery;
        } elseif ($desc === 'Solpaneler') {
            $current[$i]['price'] = $solar;
        } elseif ($desc === 'Schablon') {
            $current[$i]['price'] = $schablon;
        }
    }
    return $current;
}

/** @param array<string,mixed> $input */
function jeab_offert_ev_calc(array $input): array
{
    $d = jeab_offert_ev_defaults();
    foreach ($d as $key => $fallback) {
        if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
            $d[$key] = jeab_offert_num($input[$key], (float) $fallback);
        }
    }

    $c4 = $d['c4'];
    $c5 = $d['c5'];
    $c6 = $d['c6'];
    $c7 = $d['c7'];
    $c8 = $d['c8'];
    $c9 = $d['c9'];
    $c10 = $d['c10'];
    $c11 = $d['c11'];
    $c12 = $d['c12'];
    $d4 = $d['d4'];
    $d5 = $d['d5'];
    $d6 = $d['d6'];
    $d7 = $d['d7'];
    $d8 = $d['d8'];
    $d9 = $d['d9'];
    $hourlyExtra = $d['hourly_extra'];
    $hourLow = $d['hour_low'];
    $hourHigh = $d['hour_high'];
    $matBase = $d['mat_base'];
    $matPerLow = $d['mat_per_low'];
    $matPerHigh = $d['mat_per_high'];
    $c21 = $d['c21'];
    $d21 = $d['d21'];

    $e4 = $c4 * $d4;
    $e5 = $c5 * $d5;
    $e6 = $c6 * $d6;
    $e7 = $c7 * $d7;
    $e8 = $c8 * $d8;
    $e9 = $c9 * $d9;
    $e10 = $c10;
    $e11 = $c11 * $hourlyExtra;
    $e12 = $c12;
    $e13 = $e4 + $e5 + $e6 + $e7 + $e8 + $e9 + $e10 + $e11 + $e12;
    $f4 = $e4 * 1.25;
    $f5 = $e5 * 1.25;
    $f6 = $e6 * 1.25;
    $f7 = $e7 * 1.25;
    $f8 = $e8 * 1.25;
    $f9 = $e9 * 1.25;
    $f10 = $e10 * 1.25;
    $f11 = $e11 * 1.25;
    $f12 = $e12 * 1.25;
    $f13 = $f4 + $f5 + $f6 + $f7 + $f8 + $f9 + $f10 + $f11 + $f12;

    $c16 = $matBase + ($c7 * $matPerLow);
    $d16 = $matBase + ($c7 * $matPerHigh);
    $e16 = $c16;
    $f16 = $d16;

    $c17 = 2.0 + (($c4 + $c5) * 0.12);
    $d17 = 4.0 + (($c4 + $c5) * 0.14);
    $e17 = $c17 * $hourLow;
    $f17 = $d17 * $hourHigh;

    $c18 = $c6 * 1.0;
    $d18 = $c6 * 2.0;
    $e18 = $c18 * $hourLow;
    $f18 = $d18 * $hourHigh;

    $c19 = $c7 * 2.0;
    $d19 = $c7 * 3.0;
    $e19 = $c19 * $hourLow;
    $f19 = $d19 * $hourHigh;

    $c20 = 4.0 + ($c7 * 0.3);
    $d20 = 4.0 + ($c7 * 0.65);
    $e20 = $c20 * $hourLow;
    $f20 = $d20 * $hourHigh;

    $e21 = $c21 * $hourLow;
    $f21 = $d21 * $hourHigh;

    $e23 = $e16 + $e17 + $e18 + $e19 + $e20 + $e21;
    $f23 = $f16 + $f17 + $f18 + $f19 + $f20 + $f21;
    $hoursLow = $c17 + $c18 + $c19 + $c20 + $c21;
    $hoursHigh = $d17 + $d18 + $d19 + $d20 + $d21;
    $hoursSjalv = $hoursLow + $c11;
    $hoursList = $hoursHigh + $c11;
    $hoursRek = (($hoursLow + $hoursHigh) / 2.0) + $c11;
    $hardwareEx = $e4 + $e5 + $e6 + $e7 + $e8 + $e9 + $e10 + $e12;
    $hardwareInkl = $hardwareEx * 1.25;

    // Övrigt elmaterial: lågt→självkostnad, högt→list, medel→rekommenderat (inkl moms).
    $matLowInkl = $c16 * 1.25;
    $matHighInkl = $d16 * 1.25;
    $matRekInkl = ($matLowInkl + $matHighInkl) / 2.0;
    $laborLow = $e23 - $e16;
    $laborHigh = $f23 - $f16;
    $laborRek = ($laborLow + $laborHigh) / 2.0;
    $rekInkl = $f13 + $laborRek + $matRekInkl;
    $sjalvInkl = $f13 + $laborLow + $matLowInkl;
    $listInkl = $f13 + $laborHigh + $matHighInkl;
    $rekEx = $rekInkl * 0.8;
    $sjalvEx = $sjalvInkl * 0.8;
    $listEx = $listInkl * 0.8;
    // Inköp per kort = hårdvara inkl + övrigt elmaterial (lågt/medel/högt) inkl moms.
    $inkopSjalv = $hardwareInkl + $matLowInkl;
    $inkopRek = $hardwareInkl + $matRekInkl;
    $inkopList = $hardwareInkl + $matHighInkl;
    // Vinst ex moms = (kundpris inkl − inköp inkl) × 0,8
    $vinstRek = ($rekInkl - $inkopRek) * 0.8;
    $vinstSjalv = ($sjalvInkl - $inkopSjalv) * 0.8;
    $vinstList = ($listInkl - $inkopList) * 0.8;

    return [
        'c4' => $c4,
        'c5' => $c5,
        'c6' => $c6,
        'c7' => $c7,
        'c8' => $c8,
        'c9' => $c9,
        'c10' => $c10,
        'c11' => $c11,
        'c12' => $c12,
        'd4' => $d4,
        'd5' => $d5,
        'd6' => $d6,
        'd7' => $d7,
        'd8' => $d8,
        'd9' => $d9,
        'hourly_extra' => $hourlyExtra,
        'hour_low' => $hourLow,
        'hour_high' => $hourHigh,
        'mat_base' => $matBase,
        'mat_per_low' => $matPerLow,
        'mat_per_high' => $matPerHigh,
        'c16' => $c16,
        'd16' => $d16,
        'c17' => $c17,
        'd17' => $d17,
        'c18' => $c18,
        'd18' => $d18,
        'c19' => $c19,
        'd19' => $d19,
        'c20' => $c20,
        'd20' => $d20,
        'c21' => $c21,
        'd21' => $d21,
        'e4' => $e4,
        'e5' => $e5,
        'e6' => $e6,
        'e7' => $e7,
        'e8' => $e8,
        'e9' => $e9,
        'e10' => $e10,
        'e11' => $e11,
        'e12' => $e12,
        'e13' => $e13,
        'f13' => $f13,
        'e16' => $e16,
        'f16' => $f16,
        'e17' => $e17,
        'f17' => $f17,
        'e18' => $e18,
        'f18' => $f18,
        'e19' => $e19,
        'f19' => $f19,
        'e20' => $e20,
        'f20' => $f20,
        'e21' => $e21,
        'f21' => $f21,
        'e23' => $e23,
        'f23' => $f23,
        'hours_low' => $hoursLow,
        'hours_high' => $hoursHigh,
        'hours_rek' => $hoursRek,
        'hours_sjalv' => $hoursSjalv,
        'hours_list' => $hoursList,
        'hours' => jeab_offert_ev_hours_line($hoursSjalv, $hoursList),
        'hardware_ex' => $hardwareEx,
        'hardware_inkl' => $hardwareInkl,
        'inkop_sjalv' => $inkopSjalv,
        'inkop_rek' => $inkopRek,
        'inkop_list' => $inkopList,
        'rek_inkl' => $rekInkl,
        'sjalv_inkl' => $sjalvInkl,
        'list_inkl' => $listInkl,
        'rek_ex' => $rekEx,
        'sjalv_ex' => $sjalvEx,
        'list_ex' => $listEx,
        'labor_rek' => $laborRek,
        'vinst_rek' => $vinstRek,
        'vinst_sjalv' => $vinstSjalv,
        'vinst_list' => $vinstList,
    ];
}
