<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/normalize.php';
function jeab_solkoll_config_path(): string
{
    return jeab_data_file('solkoll.json');
}

function jeab_solkoll_secret_path(): string
{
    return jeab_data_file('secrets/solkoll.json');
}

function jeab_solkoll_cache_path(): string
{
    return jeab_data_file('solkoll-cache.json');
}

/**
 * @return array<string,mixed>
 */
function jeab_solkoll_read_json_file(string $path): array
{
    if (function_exists('jeab_cutover_solkoll_read')) {
        $sql = jeab_cutover_solkoll_read($path);
        if (is_array($sql)) {
            return $sql;
        }
    }
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $raw = json_decode((string) file_get_contents($path), true);
    return is_array($raw) ? $raw : [];
}

function jeab_solkoll_config(): array
{
    $file = jeab_solkoll_read_json_file(jeab_solkoll_config_path());
    $secret = jeab_solkoll_read_json_file(jeab_solkoll_secret_path());
    $url = trim((string) (getenv('SOLKOLL_API_URL') ?: ''));
    if ($url === '') {
        $url = trim((string) ($secret['api_url'] ?? $file['api_url'] ?? ''));
    }
    $key = trim((string) (getenv('SOLKOLL_API_KEY') ?: ''));
    if ($key === '') {
        $key = trim((string) ($secret['api_key'] ?? $file['api_key'] ?? ''));
    }
    return [
        'api_url' => rtrim($url, '/'),
        'api_key' => $key,
    ];
}

function jeab_solkoll_ready(): bool
{
    if (isset($GLOBALS['_JEAB_SOLKOLL_HTTP']) && is_callable($GLOBALS['_JEAB_SOLKOLL_HTTP'])) {
        return true;
    }
    $cfg = jeab_solkoll_config();
    return $cfg['api_url'] !== '' && $cfg['api_key'] !== '';
}

function jeab_solkoll_norm(string $raw): string
{
    $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
    if ($raw === '') {
        return '';
    }
    return function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
}

function jeab_solkoll_name_parts(string $name): array
{
    $name = jeab_solkoll_norm($name);
    if ($name === '') {
        return ['', ''];
    }
    $parts = preg_split('/\s+/u', $name) ?: [];
    $last = (string) array_pop($parts);
    return [trim(implode(' ', $parts)), $last];
}

function jeab_solkoll_facility_name(array $facility): string
{
    $named = trim((string) ($facility['Name'] ?? ''));
    if ($named !== '') {
        return $named;
    }
    return trim((string) ($facility['FirstName'] ?? '') . ' ' . (string) ($facility['LastName'] ?? ''));
}

function jeab_solkoll_names_match(string $orderName, array $facility): bool
{
    $order = jeab_solkoll_norm($orderName);
    $facilityName = jeab_solkoll_norm(jeab_solkoll_facility_name($facility));
    if ($order === '' || $facilityName === '') {
        return false;
    }
    if ($order === $facilityName) {
        return true;
    }
    [$orderFirst, $orderLast] = jeab_solkoll_name_parts($order);
    $facFirst = jeab_solkoll_norm((string) ($facility['FirstName'] ?? ''));
    $facLast = jeab_solkoll_norm((string) ($facility['LastName'] ?? ''));
    if ($facFirst === '' && $facLast === '') {
        [$facFirst, $facLast] = jeab_solkoll_name_parts($facilityName);
    }
    if ($orderLast === '' || $facLast === '' || $orderLast !== $facLast) {
        return false;
    }
    if ($orderFirst === '' || $facFirst === '') {
        return false;
    }
    if ($orderFirst === $facFirst) {
        return true;
    }
    $orderTok = explode(' ', $orderFirst)[0] ?? '';
    $facTok = explode(' ', $facFirst)[0] ?? '';
    return $orderTok !== '' && $orderTok === $facTok;
}

function jeab_solkoll_status_key(string $raw): string
{
    $raw = strtoupper(trim($raw));
    return match ($raw) {
        'NEW' => 'ny',
        'IN_PROGRESS' => 'paborjad',
        'COMPLETED' => 'slutford',
        default => '',
    };
}

function jeab_solkoll_status_label(string $key): string
{
    return match ($key) {
        'ny' => 'Ny',
        'paborjad' => 'Påbörjad',
        'slutford' => 'Slutförd',
        default => '',
    };
}

/** Solkoll svarade 404: anläggningen finns inte (eller är raderad). */
final class JeabSolkollNotFound extends RuntimeException
{
}

function jeab_solkoll_product_type_bucket(string $type): string
{
    $type = strtoupper(trim($type));
    return match ($type) {
        'INVERTER' => 'Inverters',
        'BATTERY' => 'Batteries',
        'MOUNTING_SYSTEM' => 'MountingSystems',
        'SOLAR_PANEL' => 'Panels',
        'OPTIMIZER' => 'Optimizers',
        'WALLBOX' => 'Wallboxes',
        default => 'Accessories',
    };
}

/** @return list<string> */
function jeab_solkoll_buckets(): array
{
    return ['Accessories', 'Batteries', 'Inverters', 'MountingSystems', 'Optimizers', 'Panels', 'Wallboxes'];
}

function jeab_solkoll_bucket_label(string $bucket): string
{
    return match ($bucket) {
        'Panels' => 'Paneler',
        'MountingSystems' => 'Montage',
        'Inverters' => 'Växelriktare',
        'Batteries' => 'Batteri',
        default => $bucket,
    };
}

function jeab_solkoll_facility_url(string $facilityId): string
{
    $facilityId = trim($facilityId);
    if ($facilityId === '' || preg_match('/^[A-Za-z0-9-]{8,64}$/', $facilityId) !== 1) {
        return '';
    }
    return 'https://app.solkoll.se/sv/facilities/' . rawurlencode($facilityId);
}

/**
 * Vikt text för jämförelse: gemener, åäö→ao, enheter skilda från siffror.
 */
function jeab_solkoll_fold(string $raw): string
{
    $raw = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    $raw = strtr($raw, ['å' => 'a', 'ä' => 'a', 'ö' => 'o', 'é' => 'e', 'ü' => 'u']);
    $raw = (string) preg_replace('/(\d),(\d)/', '$1.$2', $raw);
    $raw = (string) preg_replace('/[^a-z0-9.]+/', ' ', $raw);
    $raw = (string) preg_replace('/(?<![a-z0-9])(\d+(?:\.\d+)?)(kwh|kw|wp|w)(?![a-z0-9])/', '$1 $2', $raw);
    $raw = (string) preg_replace('/(?<!\d)\.|\.(?!\d)/', ' ', $raw);
    return trim((string) preg_replace('/\s+/', ' ', $raw));
}

/** @return list<string> */
function jeab_solkoll_fold_tokens(string $raw): array
{
    $folded = jeab_solkoll_fold($raw);
    return $folded === '' ? [] : array_values(array_unique(explode(' ', $folded)));
}

/** Varumärkesfamilj (sigen, aiko, longi …) eller tom sträng. */
function jeab_solkoll_brand(string $raw): string
{
    $folded = ' ' . jeab_solkoll_fold($raw) . ' ';
    if (preg_match('/ sigen[a-z]* /', $folded) === 1) {
        return 'sigen';
    }
    foreach (['aiko', 'longi', 'trina', 'jinko', 'dmegc', 'solis', 'sungrow', 'huawei', 'fronius', 'growatt', 'solaredge', 'enphase', 'mafi', 'nordmount', 'esdec', 'easee', 'tigo', 'eastron', 'chint', 'zaptec'] as $brand) {
        if (str_contains($folded, ' ' . $brand . ' ')) {
            return $brand;
        }
    }
    return '';
}

/** Modellord som skiljer produkter åt (ec, tp2, mce60db …). Varumärken, enheter och rena tal räknas inte. */
function jeab_solkoll_model_tokens(string $raw): array
{
    static $skip = [
        'sigen' => 1, 'sigenergy' => 1, 'sigenstor' => 1, 'sigenenergy' => 1, 'aiko' => 1, 'longi' => 1, 'trina' => 1,
        'solis' => 1, 'mafi' => 1, 'nordmount' => 1, 'esdec' => 1, 'kw' => 1, 'kwh' => 1, 'w' => 1, 'wp' => 1,
        'inverter' => 1, 'vaxelriktare' => 1, 'batteri' => 1, 'battery' => 1, 'solar' => 1, 'panel' => 1,
        'solpanel' => 1, 'modul' => 1, 'the' => 1, 'och' => 1, 'med' => 1, 'for' => 1, 'dual' => 1, 'glass' => 1,
        'black' => 1, 'frame' => 1, 'bi' => 1, 'facial' => 1, 'st' => 1, 'a' => 1, 'x' => 1,
    ];
    $out = [];
    foreach (jeab_solkoll_fold_tokens($raw) as $tok) {
        if (isset($skip[$tok]) || preg_match('/^\d+(?:\.\d+)?$/', $tok) === 1) {
            continue;
        }
        $out[] = $tok;
    }
    return $out;
}

function jeab_solkoll_watt(string $raw): int
{
    $f = jeab_solkoll_fold($raw);
    if (preg_match('/(?<![\d.])(\d{3})\s?wp?(?![a-z0-9])/', $f, $m) === 1) {
        $w = (int) $m[1];
        return ($w >= 200 && $w <= 900) ? $w : 0;
    }
    return jeab_solkoll_longi_watt($raw);
}

/**
 * Longi-modellkod: «LR7-54HVB-475M», «LR5-54HTH-430M» -> talet efter sista bindestrecket före M.
 * Bara Longi-koder (LRx-…); «tal före W» går alltid först.
 */
/** Longi-modellkoden normaliserad («lr7-54hvd-475m»), tom om raden saknar en. */
function jeab_solkoll_longi_code(string $raw): string
{
    $low = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    if (preg_match('/(?<![a-z0-9])(lr\d{1,2}(?:[- ][a-z0-9]+)*[- ]\d{3}m)(?![a-z0-9])/', $low, $m) === 1) {
        return (string) preg_replace('/[- ]+/', '-', $m[1]);
    }
    return '';
}

function jeab_solkoll_longi_watt(string $raw): int
{
    $low = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    if (preg_match('/(?<![a-z0-9])lr\d{1,2}[- ][a-z0-9]+(?:[- ][a-z0-9]+)*[- ](\d{3})m(?![a-z0-9])/', $low, $m) === 1) {
        $w = (int) $m[1];
        return ($w >= 200 && $w <= 900) ? $w : 0;
    }
    return 0;
}

function jeab_solkoll_kw(string $raw): float
{
    if (preg_match('/(?<![\d.])(\d+(?:\.\d+)?)\s?kw(?![a-z0-9])/', jeab_solkoll_fold($raw), $m) === 1) {
        return (float) $m[1];
    }
    return 0.0;
}

function jeab_solkoll_kwh(string $raw): float
{
    if (preg_match('/(?<![\d.])(\d+(?:\.\d+)?)\s?kwh(?![a-z0-9])/', jeab_solkoll_fold($raw), $m) === 1) {
        return (float) $m[1];
    }
    return 0.0;
}

/**
 * Vilken Solkoll-kategori en orderrad hör till. Tom sträng = monteringsmaterial m.m. som inte hör hemma i Solkoll.
 */
function jeab_solkoll_line_bucket(string $text): string
{
    $f = ' ' . jeab_solkoll_fold($text) . ' ';
    if (preg_match('/ (elbilsladdare|laddbox|laddare|wallbox|ev) /', $f) === 1) {
        return '';
    }
    if (jeab_solkoll_mount_type($text) !== '') {
        return 'MountingSystems';
    }
    if (jeab_solkoll_kwh($text) > 0 || preg_match('/ (batteri|battery|bat) /', $f) === 1) {
        return 'Batteries';
    }
    if (preg_match('/ (inverter|vaxelriktare|hybrid) /', $f) === 1 || jeab_solkoll_kw($text) > 0) {
        return 'Inverters';
    }
    $panelBrand = in_array(jeab_solkoll_brand($text), ['aiko', 'longi', 'trina', 'jinko', 'dmegc'], true);
    if (jeab_solkoll_watt($text) > 0 || preg_match('/ (solpanel|solpaneler|panel|paneler|solcellsmodul) /', $f) === 1
        || ($panelBrand && preg_match('/ \d+ wp? /', $f) === 1)) {
        return 'Panels';
    }
    return '';
}

/**
 * Takfästets typ ur en orderrad. «Klämma» (mitt-/ändklämma) är inget takfäste.
 * Krok utan annan uppgift = betongpannor (MAFI Concrete Tile). Plåtskruv = plåttak av okänd sort.
 */
function jeab_solkoll_mount_type(string $text): string
{
    $f = ' ' . jeab_solkoll_fold($text) . ' ';
    $rules = [
        'fals' => '/ (falsklamma|falsklammor|falsad|falsat|klickplat) /',
        'trp' => '/ (trp|trapets|trapetsplat) /',
        'plegel' => '/ plegel /',
        'concrete' => '/ (concrete tile|betongpanna|betongpannor|betongtak) /',
        'rooftile' => '/ (roof tile|lertegel|tegelpanna|tegelpannor|tegeltak) /',
        'papp' => '/ papptak /',
        'hook' => '/ (krok|krokar|takkrok|takkrokar|hook|hooks|laktfaste|laktfasten) /',
        'steel' => '/ (steel roof|platskruv|plattak) /',
    ];
    foreach ($rules as $type => $re) {
        if (preg_match($re, $f) === 1) {
            return $type;
        }
    }
    return '';
}

/**
 * @param list<array<string,mixed>> $catalog
 * @return list<array<string,mixed>>
 */
function jeab_solkoll_catalog_of(array $catalog, string $type): array
{
    $out = [];
    foreach ($catalog as $p) {
        if (is_array($p) && strtoupper(trim((string) ($p['ProductType'] ?? ''))) === $type && trim((string) ($p['ProductId'] ?? '')) !== '') {
            $out[] = $p;
        }
    }
    return $out;
}

function jeab_solkoll_pname(array $p): string
{
    return trim((string) ($p['ProductName'] ?? $p['Name'] ?? ''));
}

/**
 * Bästa entydiga träff. Lika poäng mellan olika produkter = ingen träff (vi gissar inte).
 * @param list<array{0:array<string,mixed>,1:int}> $scored
 * @return array{product:?array,reason:string}
 */
function jeab_solkoll_pick_scored(array $scored, string $none): array
{
    $best = null;
    $bestScore = 0;
    $tie = false;
    foreach ($scored as [$p, $score]) {
        if ($score <= 0) {
            continue;
        }
        if ($score > $bestScore) {
            $best = $p;
            $bestScore = $score;
            $tie = false;
        } elseif ($score === $bestScore) {
            $tie = true;
        }
    }
    if ($best === null) {
        return ['product' => null, 'reason' => $none];
    }
    if ($tie) {
        return ['product' => null, 'reason' => 'flera möjliga produkter i Solkoll, välj själv'];
    }
    return ['product' => $best, 'reason' => ''];
}

/**
 * Matcha en orderrad mot Solkolls favoritprodukter på märke, modell och effekt.
 * @param list<array<string,mixed>> $catalog
 * @return array{product:?array,reason:string}
 */
function jeab_solkoll_match_line(string $bucket, string $text, array $catalog): array
{
    $brand = jeab_solkoll_brand($text);
    if ($bucket === 'MountingSystems') {
        $type = jeab_solkoll_mount_type($text);
        if ($type === 'steel') {
            return ['product' => null, 'reason' => 'plåttak, men inte vilken sort (TRP, Plegel eller falsklämma)'];
        }
        $mountBrand = in_array($brand, ['nordmount', 'esdec'], true) ? $brand : 'mafi';
        $want = match ($type) {
            'fals' => '/ (falsklamma|falsad) /',
            'trp' => '/ trp /',
            'plegel' => '/ plegel /',
            'concrete', 'hook' => '/ concrete tile /',
            'rooftile' => '/ roof tile /',
            'papp' => '/ papptak /',
            default => '',
        };
        if ($want === '') {
            return ['product' => null, 'reason' => 'okänd taktyp'];
        }
        $hits = [];
        foreach (jeab_solkoll_catalog_of($catalog, 'MOUNTING_SYSTEM') as $p) {
            $name = ' ' . jeab_solkoll_fold(jeab_solkoll_pname($p)) . ' ';
            if (str_contains($name, ' ' . $mountBrand . ' ') && preg_match($want, $name) === 1) {
                $hits[] = $p;
            }
        }
        if (count($hits) === 1) {
            return ['product' => $hits[0], 'reason' => ''];
        }
        return ['product' => null, 'reason' => $hits === [] ? 'inget sådant takfäste bland favoriterna i Solkoll' : 'flera möjliga takfästen i Solkoll, välj själv'];
    }
    $type = match ($bucket) {
        'Panels' => 'SOLAR_PANEL',
        'Inverters' => 'INVERTER',
        'Batteries' => 'BATTERY',
        default => '',
    };
    if ($type === '') {
        return ['product' => null, 'reason' => 'ingen Solkoll-kategori'];
    }
    if ($brand === '') {
        return ['product' => null, 'reason' => 'märke saknas i raden'];
    }
    $pool = [];
    foreach (jeab_solkoll_catalog_of($catalog, $type) as $p) {
        if (jeab_solkoll_brand(jeab_solkoll_pname($p)) === $brand) {
            $pool[] = $p;
        }
    }
    if ($pool === []) {
        return ['product' => null, 'reason' => 'ingen ' . strtolower(jeab_solkoll_bucket_label($bucket)) . ' från ' . strtoupper($brand) . ' bland favoriterna i Solkoll'];
    }
    if ($bucket === 'Panels') {
        // En Longi-modellkod i raden måste finnas exakt bland favoriterna; HVD är inte HVB.
        $lineCode = jeab_solkoll_longi_code($text);
        if ($lineCode !== '') {
            $pool = array_values(array_filter($pool, static fn(array $p): bool => jeab_solkoll_longi_code(jeab_solkoll_pname($p)) === $lineCode));
            if ($pool === []) {
                return ['product' => null, 'reason' => 'modellen ' . strtoupper($lineCode) . ' finns inte bland favoriterna i Solkoll'];
            }
        }
    }
    $lineModel = jeab_solkoll_model_tokens($text);
    $scored = [];
    foreach ($pool as $p) {
        $name = jeab_solkoll_pname($p);
        $overlap = count(array_intersect($lineModel, jeab_solkoll_model_tokens($name)));
        $score = $overlap;
        if ($bucket === 'Panels') {
            $lw = jeab_solkoll_watt($text);
            $pw = jeab_solkoll_watt($name);
            if ($lw > 0 && $pw > 0) {
                if ($lw !== $pw) {
                    continue;
                }
                $score += 2;
            }
        }
        $scored[] = [$p, $score];
    }
    if ($bucket === 'Batteries' && count($pool) === 1 && ($scored[0][1] ?? 0) === 0) {
        // Batterier har sällan modellnamn i raden; ett enda batteri från märket räcker.
        return ['product' => $pool[0], 'reason' => ''];
    }
    return jeab_solkoll_pick_scored($scored, 'ingen produkt med samma modell/effekt bland favoriterna i Solkoll');
}

/**
 * Orderrader → Solkoll-komponenter (paneler, montage, växelriktare, batteri).
 * Rader som hör till en kategori men saknar träff hamnar i unmatched, så de syns i UI.
 *
 * @param list<array<string,mixed>> $lines
 * @param list<array<string,mixed>> $catalog
 * @return array{items:array<string,list<array<string,mixed>>>,matched:list<array<string,mixed>>,unmatched:list<array{bucket:string,what:string,title:string,reason:string}>}
 */
function jeab_solkoll_map_lines(array $lines, array $catalog): array
{
    $items = ['Panels' => [], 'MountingSystems' => [], 'Inverters' => [], 'Batteries' => []];
    $matched = [];
    $unmatched = [];
    $seenBucket = ['Panels' => false, 'MountingSystems' => false, 'Inverters' => false, 'Batteries' => false];
    $mountPicked = null;
    $mountRank = 99;
    $mountMiss = [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $title = trim((string) ($line['title'] ?? ''));
        $sku = trim((string) ($line['sku'] ?? ''));
        $qty = (int) ($line['qty'] ?? 0);
        if ($title === '' && $sku === '') {
            continue;
        }
        $text = trim($title . ' ' . $sku);
        $bucket = jeab_solkoll_line_bucket($title !== '' ? $title : $sku);
        if ($bucket === '' && $sku !== '' && $title !== '') {
            $bucket = jeab_solkoll_line_bucket($text);
        }
        if ($bucket === '') {
            continue;
        }
        $seenBucket[$bucket] = true;
        if ($qty <= 0 && $bucket !== 'MountingSystems') {
            // 0 eller tomt antal beställs inte och ska inte in i Solkoll.
            continue;
        }
        $hit = jeab_solkoll_match_line($bucket, $text, $catalog);
        $product = $hit['product'];
        if (!is_array($product)) {
            if ($bucket === 'MountingSystems') {
                $mountMiss[] = ['bucket' => $bucket, 'what' => jeab_solkoll_bucket_label($bucket), 'title' => $title !== '' ? $title : $sku, 'reason' => $hit['reason']];
                continue;
            }
            $unmatched[] = ['bucket' => $bucket, 'what' => jeab_solkoll_bucket_label($bucket), 'title' => $title !== '' ? $title : $sku, 'reason' => $hit['reason']];
            continue;
        }
        $pid = (string) $product['ProductId'];
        $pname = jeab_solkoll_pname($product);
        if ($bucket === 'MountingSystems') {
            // Uttryckligt takfäste slår krok-antagandet.
            $rank = jeab_solkoll_mount_type($text) === 'hook' ? 2 : 1;
            if ($rank < $mountRank) {
                $mountRank = $rank;
                $mountPicked = ['Id' => $pid, 'Name' => $pname];
            }
            continue;
        }
        $key = $pid;
        $existing = null;
        foreach ($items[$bucket] as $i => $item) {
            if ($item['Id'] === $key) {
                $existing = $i;
                break;
            }
        }
        if ($bucket === 'Panels') {
            // Storlek (W) ur orderradens beskrivning («Aiko 535W MCE60Db» -> 535), annars ur produktnamnet.
            $w = jeab_solkoll_watt($title) ?: jeab_solkoll_watt($text) ?: jeab_solkoll_watt($pname);
            if ($existing !== null) {
                $items[$bucket][$existing]['Amount'] += $qty;
            } else {
                $item = ['Id' => $pid, 'Name' => $pname, 'Amount' => $qty, 'Layout' => 'Portrait'];
                if ($w > 0) {
                    $item['Size'] = $w;
                }
                $items[$bucket][] = $item;
            }
        } elseif ($bucket === 'Inverters') {
            $kw = jeab_solkoll_kw($text);
            if ($existing !== null) {
                $items[$bucket][$existing]['Amount'] = (int) ($items[$bucket][$existing]['Amount'] ?? 1) + $qty;
            } else {
                $item = ['Id' => $pid, 'Name' => $pname];
                if ($kw > 0) {
                    $item['Size'] = $kw;
                }
                if ($qty > 1) {
                    $item['Amount'] = $qty;
                }
                $items[$bucket][] = $item;
            }
        } else {
            $kwh = jeab_solkoll_kwh($text) * max(1, $qty);
            if ($existing !== null) {
                $items[$bucket][$existing]['Size'] = (float) ($items[$bucket][$existing]['Size'] ?? 0) + $kwh;
            } else {
                $item = ['Id' => $pid, 'Name' => $pname];
                if ($kwh > 0) {
                    $item['Size'] = $kwh;
                }
                $items[$bucket][] = $item;
            }
        }
        $matched[] = ['bucket' => $bucket, 'title' => $title, 'product' => $pname];
    }
    if ($mountPicked !== null) {
        $items['MountingSystems'][] = $mountPicked;
        $matched[] = ['bucket' => 'MountingSystems', 'title' => '', 'product' => $mountPicked['Name']];
    } else {
        foreach ($mountMiss as $miss) {
            $unmatched[] = $miss;
        }
    }
    foreach (['Panels' => 'Ingen panelrad i orderraderna', 'MountingSystems' => 'Ingen rad som visar takfästet (krok, plåttak …)', 'Inverters' => 'Ingen växelriktare i orderraderna'] as $bucket => $why) {
        if (!$seenBucket[$bucket] && $items[$bucket] === []) {
            $unmatched[] = ['bucket' => $bucket, 'what' => jeab_solkoll_bucket_label($bucket), 'title' => '', 'reason' => $why];
        }
    }
    foreach ($items as $bucket => $list) {
        foreach ($list as $i => $item) {
            if (isset($item['Size']) && is_float($item['Size']) && floor($item['Size']) === $item['Size']) {
                $items[$bucket][$i]['Size'] = (int) $item['Size'];
            }
        }
    }
    return ['items' => $items, 'matched' => $matched, 'unmatched' => $unmatched];
}

function jeab_solkoll_split_person(string $name): array
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    if ($name === '') {
        return ['', ''];
    }
    $parts = preg_split('/\s+/u', $name) ?: [];
    $last = (string) array_pop($parts);
    return [trim(implode(' ', $parts)), $last];
}

function jeab_solkoll_error_message(mixed $decoded, int $code): string
{
    $msg = '';
    if (is_array($decoded)) {
        $err = $decoded['error'] ?? null;
        if (is_array($err)) {
            $msg = trim((string) ($err['message'] ?? $err['code'] ?? ''));
        } elseif (is_string($err)) {
            $msg = trim($err);
        }
        if ($msg === '' && is_string($decoded['message'] ?? null)) {
            $msg = trim($decoded['message']);
        }
    }
    return 'Solkoll: ' . ($msg !== '' ? $msg : ('HTTP ' . $code));
}

function jeab_solkoll_http(string $method, string $path, ?array $body = null): array
{
    if (isset($GLOBALS['_JEAB_SOLKOLL_HTTP']) && is_callable($GLOBALS['_JEAB_SOLKOLL_HTTP'])) {
        return (array) ($GLOBALS['_JEAB_SOLKOLL_HTTP'])($method, $path, $body);
    }
    $cfg = jeab_solkoll_config();
    if ($cfg['api_url'] === '' || $cfg['api_key'] === '') {
        throw new RuntimeException('Solkoll saknar API-nyckel.');
    }
    $url = $cfg['api_url'] . '/' . ltrim($path, '/');
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $cfg['api_key'],
        'x-api-key: ' . $cfg['api_key'],
    ];
    $payload = null;
    if ($body !== null) {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }
    $code = 0;
    if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('solkoll', $url)) {
        return [];
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);
        if (function_exists('jeab_pdo_release_if_idle')) {
            jeab_pdo_release_if_idle();
        }
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $raw === false ? curl_error($ch) : '';
        curl_close($ch);
        if ($err !== '') {
            throw new RuntimeException('Solkoll svarade inte.');
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers),
                'content' => $payload ?? '',
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('solkoll', $url)) {
            return [];
        }
        $raw = @file_get_contents($url, false, $ctx);
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
                $code = (int) $m[1];
            }
        }
        if ($raw === false && $code === 0) {
            throw new RuntimeException('Solkoll svarade inte.');
        }
    }
    $decoded = json_decode(is_string($raw) ? $raw : '', true);
    if ($code === 404) {
        throw new JeabSolkollNotFound(jeab_solkoll_error_message($decoded, $code));
    }
    if ($code >= 400 || $code === 0) {
        throw new RuntimeException(jeab_solkoll_error_message($decoded, $code));
    }
    return is_array($decoded) ? $decoded : [];
}

function jeab_solkoll_facilities_cached(): array
{
    if (isset($GLOBALS['_JEAB_SOLKOLL_FACILITIES']) && is_array($GLOBALS['_JEAB_SOLKOLL_FACILITIES'])) {
        return $GLOBALS['_JEAB_SOLKOLL_FACILITIES'];
    }
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }
    $cached = jeab_solkoll_list_facilities('');
    return $cached;
}

function jeab_solkoll_list_facilities(string $query = ''): array
{
    if (isset($GLOBALS['_JEAB_SOLKOLL_FACILITIES']) && is_array($GLOBALS['_JEAB_SOLKOLL_FACILITIES'])) {
        $all = $GLOBALS['_JEAB_SOLKOLL_FACILITIES'];
        if ($query === '') {
            return $all;
        }
        $q = jeab_solkoll_fold($query);
        $out = [];
        foreach ($all as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hay = jeab_solkoll_fold(jeab_solkoll_facility_name($row) . ' ' . (string) ($row['FirstName'] ?? '') . ' ' . (string) ($row['LastName'] ?? '') . ' ' . (string) ($row['Address'] ?? ''));
            if (jeab_solkoll_names_match($query, $row) || ($q !== '' && str_contains($hay, $q))) {
                $out[] = $row;
            }
        }
        return $out;
    }
    $items = [];
    $cursor = '';
    for ($i = 0; $i < 8; $i++) {
        $path = 'facilities';
        $q = [];
        if (trim($query) !== '') {
            $q[] = 'query=' . rawurlencode(trim($query));
        }
        if ($cursor !== '') {
            $q[] = 'cursor=' . rawurlencode($cursor);
        }
        if ($q !== []) {
            $path .= '?' . implode('&', $q);
        }
        $page = jeab_solkoll_http('GET', $path);
        foreach (is_array($page['Items'] ?? null) ? $page['Items'] : [] as $row) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }
        $cursor = trim((string) ($page['NextCursor'] ?? ''));
        if ($cursor === '') {
            break;
        }
    }
    return $items;
}

/**
 * Alla favoritprodukter (ett anrop, cachat per request). Sök lokalt i stället för en query per rad.
 * @return list<array<string,mixed>>
 */
function jeab_solkoll_catalog(): array
{
    if (isset($GLOBALS['_JEAB_SOLKOLL_PRODUCTS']) && is_array($GLOBALS['_JEAB_SOLKOLL_PRODUCTS'])) {
        return array_values($GLOBALS['_JEAB_SOLKOLL_PRODUCTS']);
    }
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $page = jeab_solkoll_http('GET', 'products');
    $items = [];
    foreach (is_array($page['Items'] ?? null) ? $page['Items'] : [] as $row) {
        if (is_array($row)) {
            $items[] = $row;
        }
    }
    $cache = $items;
    return $items;
}

/** @return list<array<string,mixed>> */
function jeab_solkoll_list_products(string $query = ''): array
{
    $all = jeab_solkoll_catalog();
    if (trim($query) === '') {
        return $all;
    }
    $want = jeab_solkoll_fold_tokens($query);
    $out = [];
    foreach ($all as $row) {
        $have = jeab_solkoll_fold_tokens(jeab_solkoll_pname($row));
        if (array_intersect($want, $have) !== []) {
            $out[] = $row;
        }
    }
    return $out;
}

/** Hämtar en anläggning. null om Solkoll säger 404 (borttagen). Andra fel kastas. */
function jeab_solkoll_get_facility(string $id): ?array
{
    $id = trim($id);
    if ($id === '') {
        return null;
    }
    if (isset($GLOBALS['_JEAB_SOLKOLL_FACILITIES']) && is_array($GLOBALS['_JEAB_SOLKOLL_FACILITIES']) && !isset($GLOBALS['_JEAB_SOLKOLL_HTTP'])) {
        foreach ($GLOBALS['_JEAB_SOLKOLL_FACILITIES'] as $facility) {
            if (is_array($facility) && (string) ($facility['FacilityId'] ?? '') === $id) {
                return $facility;
            }
        }
        return null;
    }
    try {
        $got = jeab_solkoll_http('GET', 'facilities/' . rawurlencode($id));
    } catch (JeabSolkollNotFound) {
        return null;
    }
    $facility = is_array($got['Item'] ?? null) ? $got['Item'] : $got;
    return trim((string) ($facility['FacilityId'] ?? '')) === $id ? $facility : null;
}

function jeab_solkoll_find_by_name(string $name): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    try {
        $candidates = jeab_solkoll_list_facilities($name);
    } catch (Throwable) {
        return null;
    }
    foreach ($candidates as $row) {
        if (is_array($row) && jeab_solkoll_names_match($name, $row)) {
            return $row;
        }
    }
    return null;
}

/**
 * Hela anläggningslistan är tung. På en GET-sidvisning hämtas den bara när
 * användaren klickat Uppdatera på en öppen order som visar Solkoll.
 * CLI och sparning/synk (inte GET) får fortfarande läsa.
 */
function jeab_solkoll_request_wants_catalog(array $row): bool
{
    if (!jeab_solkoll_applies($row)) {
        return false;
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return true;
    }
    if ((string) ($_GET['refresh'] ?? '') !== '1') {
        return false;
    }
    $openId = (int) ($_GET['id'] ?? $_GET['open'] ?? 0);
    if ($openId <= 0) {
        return false;
    }
    $rowId = (int) ($row['reminder_id'] ?? 0);
    if ($rowId <= 0) {
        $rowId = (int) ($row['id'] ?? 0);
    }
    return $rowId <= 0 || $rowId === $openId;
}

/** Läsvägen (status på sidor/tavlan): sök i den cachade listan, inga anrop per order. */
function jeab_solkoll_find_by_id(string $id): ?array
{
    $id = trim($id);
    if ($id === '') {
        return null;
    }
    try {
        foreach (jeab_solkoll_facilities_cached() as $facility) {
            if (is_array($facility) && (string) ($facility['FacilityId'] ?? '') === $id) {
                return $facility;
            }
        }
    } catch (Throwable) {
        return null;
    }
    return null;
}

/**
 * Gata + nummer (+ bokstav), vikt. «Gymnastikvägen 29, Järfälla» och «Gymnastikvägen 29 Järfälla» ger samma nyckel.
 * Tom när adressen saknar husnummer (då jämför vi inte på adress).
 */
function jeab_solkoll_street_key(string $address): string
{
    $f = jeab_norm_fold($address);
    $f = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $f));
    $f = trim((string) preg_replace('/\s+/', ' ', $f));
    if (preg_match('/^(.*?[a-z].*?\d+)(?:\s?([a-z]))?(?=\s|$)/', $f, $m) !== 1) {
        return '';
    }
    $key = trim($m[1]) . (isset($m[2]) && $m[2] !== '' ? $m[2] : '');
    return trim((string) preg_replace('/\s+/', ' ', $key));
}

/** @return array{street:string,zip:string,city:string} */
function jeab_solkoll_order_address(array $row): array
{
    $street = '';
    $zip = '';
    $city = '';
    if (function_exists('jeab_ordrar_status_address_lines')) {
        $lines = jeab_ordrar_status_address_lines($row);
        $street = trim((string) ($lines['street'] ?? ''));
        $zip = trim((string) ($lines['postnummer'] ?? ''));
        $city = trim((string) ($lines['place'] ?? $lines['ort'] ?? ''));
    }
    if ($street === '') {
        $raw = trim((string) ($row['huvudsokande_address'] ?? $row['address'] ?? ''));
        $parts = array_map('trim', explode(',', $raw, 2));
        $street = $parts[0] ?? '';
        if ($city === '' && isset($parts[1])) {
            $city = trim((string) preg_replace('/^\d{3}\s?\d{2}\s*/', '', $parts[1]));
        }
    }
    return ['street' => $street, 'zip' => $zip, 'city' => $city];
}

/** Gatan fram till husnumret i originalform, för Solkolls sökning. */
function jeab_solkoll_street_query(string $street): string
{
    if (preg_match('/^(.*?\d+)/u', trim($street), $m) === 1) {
        return trim($m[1]);
    }
    return trim($street);
}

function jeab_solkoll_data_score(array $facility): int
{
    $n = 0;
    foreach (jeab_solkoll_buckets() as $bucket) {
        $n += count(is_array($facility['Components'][$bucket] ?? null) ? $facility['Components'][$bucket] : []);
    }
    foreach (is_array($facility['Roofs'] ?? null) ? $facility['Roofs'] : [] as $roof) {
        foreach (jeab_solkoll_buckets() as $bucket) {
            $n += count(is_array($roof[$bucket] ?? null) ? $roof[$bucket] : []);
        }
    }
    return (int) ($facility['TodosCompleted'] ?? 0) * 100 + (int) ($facility['DocumentCount'] ?? 0) * 10 + $n;
}

/**
 * Välj orderns anläggning ur en lista: samma normaliserade adress, annars samma namn utan krockande adress.
 * Flera träffar: den med mest data (klara steg, dokument, produkter), sedan den äldsta.
 *
 * @param list<array<string,mixed>> $facilities
 * @return array{facility:?array,count:int}
 */
function jeab_solkoll_pick_existing(array $facilities, array $row): array
{
    $addr = jeab_solkoll_order_address($row);
    $key = jeab_solkoll_street_key($addr['street']);
    $name = function_exists('jeab_ordrar_name') ? jeab_ordrar_name($row) : trim((string) ($row['name'] ?? ''));
    $byAddress = [];
    $byName = [];
    foreach ($facilities as $f) {
        if (!is_array($f) || trim((string) ($f['FacilityId'] ?? '')) === '') {
            continue;
        }
        $fid = (string) $f['FacilityId'];
        $fKey = jeab_solkoll_street_key((string) ($f['Address'] ?? ''));
        if ($key !== '' && $fKey === $key) {
            $byAddress[$fid] = $f;
            continue;
        }
        if ($name !== '' && jeab_solkoll_names_match($name, $f) && ($fKey === '' || $key === '')) {
            $byName[$fid] = $f;
        }
    }
    $cands = $byAddress !== [] ? $byAddress : $byName;
    if ($cands === []) {
        return ['facility' => null, 'count' => 0];
    }
    $list = array_values($cands);
    usort($list, static function (array $a, array $b): int {
        $s = jeab_solkoll_data_score($b) <=> jeab_solkoll_data_score($a);
        return $s !== 0 ? $s : ((int) ($a['CreatedAt'] ?? 0) <=> (int) ($b['CreatedAt'] ?? 0));
    });
    return ['facility' => $list[0], 'count' => count($list)];
}

/**
 * Skrivvägen: färsk sökning i Solkoll på gatuadress och namn.
 * $strict: kasta vid fel (ett misslyckat uppslag får aldrig leda till en ny anläggning).
 *
 * @return array{facility:?array,count:int}
 */
function jeab_solkoll_find_existing(array $row, bool $strict = true): array
{
    $addr = jeab_solkoll_order_address($row);
    $name = function_exists('jeab_ordrar_name') ? jeab_ordrar_name($row) : trim((string) ($row['name'] ?? ''));
    $seen = [];
    try {
        if (jeab_solkoll_street_key($addr['street']) !== '') {
            foreach (jeab_solkoll_list_facilities(jeab_solkoll_street_query($addr['street'])) as $f) {
                if (is_array($f) && trim((string) ($f['FacilityId'] ?? '')) !== '') {
                    $seen[(string) $f['FacilityId']] = $f;
                }
            }
        }
        $picked = jeab_solkoll_pick_existing(array_values($seen), $row);
        if ($picked['facility'] === null && $name !== '') {
            foreach (jeab_solkoll_list_facilities($name) as $f) {
                if (is_array($f) && trim((string) ($f['FacilityId'] ?? '')) !== '') {
                    $seen[(string) $f['FacilityId']] = $f;
                }
            }
            $picked = jeab_solkoll_pick_existing(array_values($seen), $row);
        }
    } catch (Throwable $e) {
        if ($strict) {
            throw new RuntimeException('Kunde inte söka i Solkoll: ' . $e->getMessage(), 0, $e);
        }
        return ['facility' => null, 'count' => 0];
    }
    return $picked;
}

function jeab_solkoll_order_lines(array $row): array
{
    $lines = [];
    foreach (is_array($row['inkop_lines'] ?? null) ? $row['inkop_lines'] : [] as $line) {
        if (!is_array($line)) {
            continue;
        }
        $title = trim((string) ($line['title'] ?? $line['artikelnamn'] ?? ''));
        $sku = trim((string) ($line['sku'] ?? $line['artikelnummer'] ?? ''));
        if ($title === '' && $sku === '') {
            continue;
        }
        $lines[] = [
            'title' => $title,
            'sku' => $sku,
            'qty' => (int) ($line['qty'] ?? $line['antal'] ?? 0),
        ];
    }
    return $lines;
}

/** @return array<string,mixed> */
function jeab_solkoll_clean_item(mixed $item): array
{
    $item = is_array($item) ? $item : [];
    $out = ['Id' => (string) ($item['Id'] ?? ''), 'Name' => (string) ($item['Name'] ?? '')];
    foreach (['Amount', 'Size'] as $num) {
        if (isset($item[$num]) && is_numeric($item[$num])) {
            $out[$num] = $item[$num] + 0;
        }
    }
    if (isset($item['Layout']) && is_string($item['Layout'])) {
        $out['Layout'] = $item['Layout'];
    }
    return $out;
}

/** @return array<string,mixed> */
function jeab_solkoll_clean_roof(mixed $roof): array
{
    $roof = is_array($roof) ? $roof : [];
    $out = [];
    foreach (['Name', 'Type', 'Material'] as $k) {
        if (isset($roof[$k]) && is_string($roof[$k])) {
            $out[$k] = $roof[$k];
        }
    }
    foreach (['Azimuth', 'Tilt', 'Width', 'Length', 'ScaffoldingHeight'] as $k) {
        if (isset($roof[$k]) && is_numeric($roof[$k])) {
            $out[$k] = $roof[$k] + 0;
        }
    }
    foreach (jeab_solkoll_buckets() as $bucket) {
        if (isset($roof[$bucket]) && is_array($roof[$bucket])) {
            $out[$bucket] = array_map('jeab_solkoll_clean_item', array_values($roof[$bucket]));
        }
    }
    if (isset($roof['RoofTypes']) && is_array($roof['RoofTypes'])) {
        $out['RoofTypes'] = array_values($roof['RoofTypes']);
    }
    return $out;
}

/**
 * Lägg orderns produkter på en befintlig anläggning.
 * Tom kategori fylls. Paneler rättas alltid efter ordern (antal, W, stående) om inget är tvetydigt.
 * Övriga kategorier: annan produkt i Solkoll skrivs inte över, det rapporteras.
 * Paneler och montage ligger på taket (som i Solkolls UI), växelriktare och batteri under Components.
 *
 * @param array<string,list<array<string,mixed>>> $want
 * @return array{patch:array<string,mixed>,added:list<string>,differs:list<string>,fixed:list<string>}
 */
function jeab_solkoll_merge(array $facility, array $want): array
{
    $components = [];
    foreach (jeab_solkoll_buckets() as $bucket) {
        $components[$bucket] = array_map('jeab_solkoll_clean_item', array_values(is_array($facility['Components'][$bucket] ?? null) ? $facility['Components'][$bucket] : []));
    }
    $roofs = array_map('jeab_solkoll_clean_roof', array_values(is_array($facility['Roofs'] ?? null) ? $facility['Roofs'] : []));
    $roofsChanged = false;
    $componentsChanged = false;
    $added = [];
    $differs = [];
    $fixed = [];
    $panelsDone = false;
    $wantPanels = array_values($want['Panels'] ?? []);
    $havePanels = jeab_solkoll_has_items($components['Panels']);
    foreach ($roofs as $roof) {
        $havePanels = $havePanels || jeab_solkoll_has_items($roof['Panels'] ?? []);
    }
    if ($wantPanels === [] && $havePanels) {
        $differs[] = 'Paneler: ingen orderrad som går att koppla till en Solkoll-panel med W, rättas inte automatiskt.';
    }
    if ($wantPanels !== [] && $havePanels) {
        // Paneler följer ordern: antal, W och stående rättas (gäller bara paneler).
        $panelsDone = true;
        $enf = jeab_solkoll_enforce_panels($components, $roofs, $wantPanels);
        $components = $enf['components'];
        $roofs = $enf['roofs'];
        $componentsChanged = $componentsChanged || $enf['components_changed'];
        $roofsChanged = $roofsChanged || $enf['roofs_changed'];
        $fixed = $enf['fixed'];
        foreach ($enf['differs'] as $d) {
            $differs[] = $d;
        }
    }
    foreach (['Panels', 'MountingSystems', 'Inverters', 'Batteries'] as $bucket) {
        $wanted = $want[$bucket] ?? [];
        if ($wanted === [] || ($bucket === 'Panels' && $panelsDone)) {
            continue;
        }
        $have = $components[$bucket];
        foreach ($roofs as $roof) {
            foreach ($roof[$bucket] ?? [] as $item) {
                $have[] = $item;
            }
        }
        $have = array_values(array_filter($have, static fn(array $i): bool => trim((string) $i['Id']) !== ''));
        $label = jeab_solkoll_bucket_label($bucket);
        if ($have === []) {
            if (in_array($bucket, ['Panels', 'MountingSystems'], true)) {
                if ($roofs === []) {
                    $roofs[] = ['Name' => 'Tak 1'];
                }
                $roofs[0][$bucket] = array_values($wanted);
                $roofsChanged = true;
            } else {
                $components[$bucket] = array_values($wanted);
                $componentsChanged = true;
            }
            foreach ($wanted as $item) {
                $added[] = $label . ': ' . jeab_solkoll_item_text($item, $bucket);
            }
            continue;
        }
        foreach ($wanted as $item) {
            $same = null;
            foreach ($have as $h) {
                if ($h['Id'] === $item['Id']) {
                    $same = $h;
                    break;
                }
            }
            if ($same === null) {
                $names = implode(', ', array_map(static fn(array $h): string => (string) $h['Name'], $have));
                $differs[] = $label . ': Solkoll har ' . $names . ', ordern säger ' . $item['Name'] . '.';
                continue;
            }
            if ($bucket === 'Panels' && isset($item['Amount'])) {
                // Taket räknas först; Components används bara om taket saknar raden (annars dubbelräkning).
                $haveAmount = 0;
                foreach ($roofs as $roof) {
                    foreach ($roof[$bucket] ?? [] as $h) {
                        if ($h['Id'] === $item['Id']) {
                            $haveAmount += (int) ($h['Amount'] ?? 0);
                        }
                    }
                }
                if ($haveAmount === 0) {
                    foreach ($components[$bucket] as $h) {
                        if ($h['Id'] === $item['Id']) {
                            $haveAmount += (int) ($h['Amount'] ?? 0);
                        }
                    }
                }
                if ($haveAmount !== (int) $item['Amount']) {
                    $differs[] = $label . ': Solkoll ' . $haveAmount . ' st, ordern ' . (int) $item['Amount'] . ' st.';
                }
            }
        }
    }
    $patch = [];
    if ($componentsChanged) {
        $patch['Components'] = $components;
    }
    if ($roofsChanged) {
        $patch['Roofs'] = $roofs;
    }
    return ['patch' => $patch, 'added' => $added, 'differs' => $differs, 'fixed' => $fixed];
}

function jeab_solkoll_has_items(mixed $list): bool
{
    foreach (is_array($list) ? $list : [] as $item) {
        if (is_array($item) && trim((string) ($item['Id'] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

/** «Aiko 535W MCE60Db 26 st × 535 W stående» – för rapport och kort. */
function jeab_solkoll_panel_text(array $item): string
{
    $layout = (string) ($item['Layout'] ?? '');
    $layoutText = strcasecmp($layout, 'Portrait') === 0 ? 'stående' : (strcasecmp($layout, 'Landscape') === 0 ? 'liggande' : 'utan layout');
    $amount = isset($item['Amount']) && is_numeric($item['Amount']) ? (int) $item['Amount'] . ' st' : 'antal saknas';
    $size = isset($item['Size']) && is_numeric($item['Size']) && (float) $item['Size'] > 0 ? (int) $item['Size'] . ' W' : 'utan W';
    return trim(preg_replace('/\s+/', ' ', (string) ($item['Name'] ?? '')) ?? '') . ' ' . $amount . ' × ' . $size . ' ' . $layoutText;
}

/** @param list<array<string,mixed>> $a */
function jeab_solkoll_panels_equal(array $a, array $b): bool
{
    $norm = static function (array $list): array {
        $out = [];
        foreach ($list as $i) {
            if (!is_array($i) || trim((string) ($i['Id'] ?? '')) === '') {
                continue;
            }
            $out[] = [
                (string) $i['Id'],
                (int) ($i['Amount'] ?? 0),
                (int) round((float) ($i['Size'] ?? 0)),
                strtolower((string) ($i['Layout'] ?? '')),
            ];
        }
        return $out;
    };
    return $norm($a) === $norm($b);
}

/**
 * Paneler ska vara exakt som i ordern: produkt, Antal, Storlek (W) och Layout stående (Portrait).
 * Tvetydigt (flera paneltyper i ordern, W saknas i orderraden, paneler på flera tak) rättas inte; det rapporteras.
 *
 * @param array<string,list<array<string,mixed>>> $components
 * @param list<array<string,mixed>> $roofs
 * @param list<array<string,mixed>> $wanted
 * @return array{components:array,roofs:array,components_changed:bool,roofs_changed:bool,fixed:list<string>,differs:list<string>}
 */
function jeab_solkoll_enforce_panels(array $components, array $roofs, array $wanted): array
{
    $out = ['components' => $components, 'roofs' => $roofs, 'components_changed' => false, 'roofs_changed' => false, 'fixed' => [], 'differs' => []];
    if (count($wanted) > 1) {
        $out['differs'][] = 'Paneler: ordern har flera paneltyper (' . implode(', ', array_map(static fn(array $i): string => (string) $i['Name'], $wanted)) . '), rättas inte automatiskt.';
        return $out;
    }
    $item = jeab_solkoll_clean_item($wanted[0]);
    if (!isset($item['Size']) || (float) $item['Size'] <= 0) {
        $out['differs'][] = 'Paneler: orderraden visar inte W (' . $item['Name'] . '), rättas inte automatiskt.';
        return $out;
    }
    $item['Layout'] = 'Portrait';
    $desired = [$item];
    $withPanels = [];
    foreach ($roofs as $i => $roof) {
        if (jeab_solkoll_has_items($roof['Panels'] ?? [])) {
            $withPanels[] = $i;
        }
    }
    if (count($withPanels) > 1) {
        $out['differs'][] = 'Paneler: ligger på ' . count($withPanels) . ' tak i Solkoll, rättas inte automatiskt (ordern: ' . jeab_solkoll_panel_text($item) . ').';
        return $out;
    }
    $before = [];
    if ($withPanels !== []) {
        $target = $withPanels[0];
        $before = $roofs[$target]['Panels'];
    } else {
        if ($roofs === []) {
            $roofs[] = ['Name' => 'Tak 1'];
        }
        $target = 0;
        $before = array_values(array_filter($components['Panels'] ?? [], static fn($i): bool => is_array($i) && trim((string) ($i['Id'] ?? '')) !== ''));
    }
    if (!jeab_solkoll_panels_equal($roofs[$target]['Panels'] ?? [], $desired)) {
        $roofs[$target]['Panels'] = $desired;
        $out['roofs_changed'] = true;
    }
    if (jeab_solkoll_has_items($components['Panels'] ?? []) && !jeab_solkoll_panels_equal($components['Panels'], $desired)) {
        $components['Panels'] = $desired;
        $out['components_changed'] = true;
    }
    if ($out['roofs_changed'] || $out['components_changed']) {
        $was = $before === [] ? 'inga' : implode(', ', array_map('jeab_solkoll_panel_text', $before));
        $out['fixed'][] = 'Paneler: ' . $was . ' → ' . jeab_solkoll_panel_text($item) . '.';
    }
    $out['components'] = $components;
    $out['roofs'] = $roofs;
    return $out;
}

/**
 * Solkoll tömmer Name vid PATCH om inte FirstName och LastName skickas med (sett live 2026-10-08).
 * Skicka därför alltid med anläggningens nuvarande namnfält oförändrade.
 */
function jeab_solkoll_patch_body(array $facility, array $patch): array
{
    foreach (['Name', 'FirstName', 'LastName'] as $key) {
        if (!array_key_exists($key, $patch) && is_string($facility[$key] ?? null)) {
            $patch[$key] = $facility[$key];
        }
    }
    return $patch;
}

/** @return array<string,bool> kategorier som har minst en produkt (Components eller tak) */
function jeab_solkoll_filled_buckets(array $facility): array
{
    $out = [];
    foreach (jeab_solkoll_buckets() as $bucket) {
        $has = !empty(array_filter(is_array($facility['Components'][$bucket] ?? null) ? $facility['Components'][$bucket] : [], static fn($i): bool => is_array($i) && trim((string) ($i['Id'] ?? '')) !== ''));
        foreach (is_array($facility['Roofs'] ?? null) ? $facility['Roofs'] : [] as $roof) {
            foreach (is_array($roof[$bucket] ?? null) ? $roof[$bucket] : [] as $i) {
                if (is_array($i) && trim((string) ($i['Id'] ?? '')) !== '') {
                    $has = true;
                }
            }
        }
        $out[$bucket] = $has;
    }
    return $out;
}

function jeab_solkoll_item_text(array $item, string $bucket): string
{
    $text = (string) ($item['Name'] ?? '');
    $size = isset($item['Size']) && is_numeric($item['Size']) ? (string) ($item['Size'] + 0) : '';
    if ($bucket === 'Panels' && !empty($item['Amount'])) {
        return $text . ' × ' . (int) $item['Amount'];
    }
    if ($bucket === 'Inverters' && $size !== '') {
        return $text . ' (' . str_replace('.', ',', $size) . ' kW)';
    }
    if ($bucket === 'Batteries' && $size !== '') {
        return $text . ' (' . str_replace('.', ',', $size) . ' kWh)';
    }
    return $text;
}

/**
 * @param array<string,list<array<string,mixed>>> $items
 * @return array<string,mixed>
 */
function jeab_solkoll_create_payload(array $row, array $items): array
{
    $name = function_exists('jeab_ordrar_name') ? jeab_ordrar_name($row) : trim((string) ($row['name'] ?? ''));
    [$first, $last] = jeab_solkoll_split_person($name);
    $addr = jeab_solkoll_order_address($row);
    $components = [];
    foreach (jeab_solkoll_buckets() as $bucket) {
        $components[$bucket] = in_array($bucket, ['Inverters', 'Batteries'], true) ? array_values($items[$bucket] ?? []) : [];
    }
    $payload = [
        'Name' => $name,
        'FirstName' => $first !== '' ? $first : $name,
        'LastName' => $last,
        'Address' => $addr['street'] !== '' ? $addr['street'] : null,
        'ZipCode' => $addr['zip'] !== '' ? $addr['zip'] : null,
        'City' => $addr['city'] !== '' ? $addr['city'] : null,
        'Email' => trim((string) ($row['email'] ?? '')) ?: null,
        'Phone' => trim((string) ($row['phone'] ?? '')) ?: null,
        'FacilityStatus' => 'NEW',
        'Components' => $components,
    ];
    $roof = ['Name' => 'Tak 1'];
    foreach (['Panels', 'MountingSystems'] as $bucket) {
        if (($items[$bucket] ?? []) !== []) {
            $roof[$bucket] = array_values($items[$bucket]);
        }
    }
    if (count($roof) > 1) {
        $payload['Roofs'] = [$roof];
    }
    $who = function_exists('jeab_auth_user_email') ? jeab_auth_user_email() : null;
    if (is_string($who) && $who !== '') {
        $payload['ResponsibleUserEmail'] = $who;
    }
    return $payload;
}

/** Solkoll gäller bara solceller. Laddbox och batteri varken visas eller anropas. */
function jeab_solkoll_applies(array $row): bool
{
    if (!function_exists('jeab_ordrar_ui_kind')) {
        return false;
    }
    return jeab_ordrar_ui_kind($row['kind'] ?? '') === 'sol';
}

function jeab_solkoll_facility_for_row(array $row): ?array
{
    if (!jeab_solkoll_applies($row)) {
        return null;
    }
    $id = trim((string) ($row['solkoll_facility_id'] ?? ''));
    if ($id !== '') {
        $found = jeab_solkoll_find_by_id($id);
        if (is_array($found)) {
            return $found;
        }
    }
    try {
        return jeab_solkoll_pick_existing(jeab_solkoll_facilities_cached(), $row)['facility'];
    } catch (Throwable) {
        return null;
    }
}

function jeab_solkoll_apply_facility(array $row, array $facility): array
{
    $row['solkoll_facility_id'] = (string) ($facility['FacilityId'] ?? $row['solkoll_facility_id'] ?? '');
    $status = jeab_solkoll_status_key((string) ($facility['FacilityStatus'] ?? ''));
    if ($status !== '') {
        $row['solkoll_status'] = $status;
    }
    if (isset($facility['TodosCompleted']) || isset($facility['TotalTodos'])) {
        $row['solkoll_todos_done'] = (int) ($facility['TodosCompleted'] ?? 0);
        $row['solkoll_todos_total'] = (int) ($facility['TotalTodos'] ?? 0);
    }
    return $row;
}

/**
 * Skapa eller koppla Solkoll-anläggning och lägg till produkter. Idempotent:
 * 1) sparat facility-id (GET), 2) samma normaliserade adress/namn, 3) först då skapa.
 * Ett misslyckat uppslag skapar aldrig en ny anläggning. Fel hamnar i solkoll_error.
 * Anroparen sparar raden (jeab_solkoll_run_order låser per order).
 */
function jeab_solkoll_sync_order(array $row): array
{
    if (!jeab_solkoll_applies($row)) {
        return $row;
    }
    $name = function_exists('jeab_ordrar_name') ? jeab_ordrar_name($row) : trim((string) ($row['name'] ?? ''));
    if ($name === '') {
        $row['solkoll_error'] = 'Ordern saknar namn.';
        return $row;
    }
    unset($row['solkoll_error']);
    try {
        $facility = null;
        $storedId = trim((string) ($row['solkoll_facility_id'] ?? ''));
        if ($storedId !== '') {
            $facility = jeab_solkoll_get_facility($storedId);
        }
        $count = $facility !== null ? 1 : 0;
        if ($facility === null) {
            $found = jeab_solkoll_find_existing($row, true);
            $facility = $found['facility'];
            $count = $found['count'];
        }
        $catalog = jeab_solkoll_catalog();
        $mapped = jeab_solkoll_map_lines(jeab_solkoll_order_lines($row), $catalog);
        $added = [];
        $differs = [];
        $fixed = [];
        $created = false;
        if ($facility === null) {
            if (!jeab_solkoll_ready()) {
                throw new RuntimeException('Solkoll saknar API-nyckel.');
            }
            $resp = jeab_solkoll_http('POST', 'facilities', jeab_solkoll_create_payload($row, $mapped['items']));
            $facility = is_array($resp['Item'] ?? null) ? $resp['Item'] : $resp;
            $newId = trim((string) ($facility['FacilityId'] ?? ''));
            if ($newId === '') {
                throw new RuntimeException('Solkoll svarade utan anläggnings-id.');
            }
            $row['solkoll_facility_id'] = $newId;
            $created = true;
            $count = 1;
            foreach ($mapped['items'] as $bucket => $list) {
                foreach ($list as $item) {
                    $added[] = jeab_solkoll_bucket_label($bucket) . ': ' . jeab_solkoll_item_text($item, $bucket);
                }
            }
        } else {
            $row['solkoll_facility_id'] = (string) $facility['FacilityId'];
            $merge = jeab_solkoll_merge($facility, $mapped['items']);
            $differs = $merge['differs'];
            if ($merge['patch'] !== []) {
                $resp = jeab_solkoll_http('PATCH', 'facilities/' . rawurlencode((string) $facility['FacilityId']), jeab_solkoll_patch_body($facility, $merge['patch']));
                $updated = is_array($resp['Item'] ?? null) ? $resp['Item'] : $resp;
                if (trim((string) ($updated['FacilityId'] ?? '')) !== '') {
                    $facility = $updated;
                }
                $added = $merge['added'];
                $fixed = $merge['fixed'];
            }
        }
        $row = jeab_solkoll_apply_facility($row, $facility);
        // Det som inte gick att matcha men redan finns i Solkoll (t.ex. takfäste inlagt för hand) är inget att visa.
        $filled = jeab_solkoll_filled_buckets($facility);
        // Paneler visas alltid: de ska följa ordern, så en panelrad som inte går att koppla måste synas.
        $mapped['unmatched'] = array_values(array_filter($mapped['unmatched'], static fn(array $u): bool => ($u['bucket'] ?? '') === 'Panels' ? trim((string) ($u['title'] ?? '')) !== '' || empty($filled['Panels']) : empty($filled[$u['bucket'] ?? ''])));
        $prevMiss = is_array($row['solkoll_unmatched'] ?? null) ? $row['solkoll_unmatched'] : [];
        $row['solkoll_added'] = $added;
        $row['solkoll_products_added'] = count($added);
        $row['solkoll_unmatched'] = $mapped['unmatched'];
        if ($mapped['unmatched'] !== [] && $mapped['unmatched'] !== $prevMiss) {
            $missText = 'Solkoll saknar: ' . implode('; ', array_map(static fn(array $u): string => $u['what'] . ($u['title'] !== '' ? ' «' . $u['title'] . '»' : '') . ' – ' . $u['reason'], $mapped['unmatched']));
            error_log('[solkoll] order ' . (int) ($row['reminder_id'] ?? 0) . ': ' . $missText);
            if (function_exists('jeab_ordrar_activity_append')) {
                $row = jeab_ordrar_activity_append($row, $missText, '', 'solkoll', false);
            }
        }
        $row['solkoll_differs'] = $differs;
        $row['solkoll_fixed'] = $fixed;
        $row['solkoll_duplicates'] = max(0, $count - 1);
        $row['solkoll_synced_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $note = $created ? 'Skapad i Solkoll' : 'Kopplad till Solkoll';
        if ($added !== []) {
            $note .= ' · ' . (count($added) === 1 ? '1 produkt tillagd' : count($added) . ' produkter tillagda');
        }
        if ($fixed !== []) {
            $note .= ' · rättat: ' . implode(' ', $fixed);
        }
        $row['solkoll_note'] = $note;
        if (($created || $added !== [] || $fixed !== []) && function_exists('jeab_ordrar_activity_append')) {
            $row = jeab_ordrar_activity_append($row, $note, '', 'solkoll', false);
        }
    } catch (Throwable $e) {
        $msg = trim($e->getMessage());
        $row['solkoll_error'] = $msg !== '' ? $msg : 'Solkoll svarade inte.';
    }
    return $row;
}

function jeab_solkoll_lock_path(int $orderId): string
{
    $dir = (function_exists('jeab_data_dir') ? jeab_data_dir() : sys_get_temp_dir()) . '/locks';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . '/solkoll-order-' . $orderId . '.lock';
}

/**
 * En Solkoll-körning åt gången per order (flock). Andra klicket väntar, läser om ordern
 * (med det sparade anläggnings-id:t) och skapar därför ingen ny anläggning.
 */
function jeab_solkoll_run_order(int $orderId, float $waitSeconds = 25.0): array
{
    if ($orderId <= 0) {
        throw new InvalidArgumentException('Ordern finns inte.');
    }
    $fh = @fopen(jeab_solkoll_lock_path($orderId), 'c');
    if ($fh === false) {
        throw new InvalidArgumentException('Kunde inte låsa ordern för Solkoll.');
    }
    $until = microtime(true) + max(0.0, $waitSeconds);
    while (!flock($fh, LOCK_EX | LOCK_NB)) {
        if (microtime(true) >= $until) {
            fclose($fh);
            throw new InvalidArgumentException('Solkoll arbetar redan med ordern. Vänta en stund och försök igen.');
        }
        usleep(200000);
    }
    try {
        $row = function_exists('jeab_ordrar_require') ? jeab_ordrar_require($orderId) : (jeab_purchase_get($orderId) ?? []);
        if (!jeab_solkoll_applies($row)) {
            throw new InvalidArgumentException('Solkoll gäller bara solceller.');
        }
        $row = jeab_solkoll_sync_order($row);
        $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $row = jeab_purchase_put($row);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    $err = trim((string) ($row['solkoll_error'] ?? ''));
    if ($err !== '') {
        throw new InvalidArgumentException($err);
    }
    return $row;
}

/** Solkoll-kortet i Orderrader (bara sol). En knapp skapar/kopplar och lägger till produkter. */
function jeab_solkoll_card_html(array $row, string $company, int $openId): string
{
    if (!jeab_solkoll_applies($row)) {
        return '';
    }
    $id = trim((string) ($row['solkoll_facility_id'] ?? ''));
    $foundOnly = false;
    $catalogOk = PHP_SAPI === 'cli' || jeab_solkoll_request_wants_catalog($row);
    if ($id === '' && $catalogOk && (!function_exists('jeab_ordrar_external_live') || jeab_ordrar_external_live())) {
        // Inte kopplad än, men anläggningen kan redan finnas på adressen (läses ur den cachade listan).
        $found = jeab_solkoll_facility_for_row($row);
        if (is_array($found) && trim((string) ($found['FacilityId'] ?? '')) !== '') {
            $id = trim((string) $found['FacilityId']);
            $foundOnly = true;
        }
    }
    $url = jeab_solkoll_facility_url($id);
    $info = jeab_solkoll_status_for_row($row);
    $action = function_exists('jeab_ordrar_url') ? jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'orderrader']) : '';
    $added = array_values(array_filter(array_map('strval', is_array($row['solkoll_added'] ?? null) ? $row['solkoll_added'] : [])));
    $unmatched = is_array($row['solkoll_unmatched'] ?? null) ? $row['solkoll_unmatched'] : [];
    $differs = array_values(array_filter(array_map('strval', is_array($row['solkoll_differs'] ?? null) ? $row['solkoll_differs'] : [])));
    $fixed = array_values(array_filter(array_map('strval', is_array($row['solkoll_fixed'] ?? null) ? $row['solkoll_fixed'] : [])));
    $dupes = (int) ($row['solkoll_duplicates'] ?? 0);
    $err = trim((string) ($row['solkoll_error'] ?? ''));
    ob_start();
    ?>
    <div class="ordrar-card ordrar-solkoll-card" data-js="ordrar-solkoll">
        <h2>Solkoll</h2>
        <?php if ($id !== ''): ?>
            <p class="ordrar-help">
                <?= e($foundOnly ? 'Finns i Solkoll på adressen, inte kopplad än' : ($info['label'] !== '' ? $info['label'] : 'Kopplad')) ?>
                <?php if ($url !== ''): ?>
                    · <a class="ordrar-result-link" href="<?= e($url) ?>" target="_blank" rel="noopener">Öppna i Solkoll</a>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <p class="ordrar-help">Skapar anläggningen i Solkoll, eller kopplar den som redan finns på adressen, och lägger till paneler, montage och växelriktare från orderraderna. Går att köra igen.</p>
        <?php endif; ?>
        <?php if ($added !== []): ?>
            <p class="ordrar-help">Tillagt: <?= e(implode(' · ', $added)) ?></p>
        <?php endif; ?>
        <?php if ($fixed !== []): ?>
            <p class="ordrar-help">Rättat efter ordern: <?= e(implode(' ', $fixed)) ?></p>
        <?php endif; ?>
        <?php if ($unmatched !== []): ?>
            <div class="ordrar-solkoll-miss" data-js="ordrar-solkoll-miss">
                <p class="ordrar-help is-alert">Lades inte till i Solkoll:</p>
                <ul class="ordrar-solkoll-list">
                    <?php foreach ($unmatched as $miss): ?>
                        <?php if (!is_array($miss)) { continue; } ?>
                        <li><?= e(trim((string) ($miss['what'] ?? '')) . (trim((string) ($miss['title'] ?? '')) !== '' ? ' «' . trim((string) $miss['title']) . '»' : '') . ': ' . trim((string) ($miss['reason'] ?? ''))) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ($differs !== []): ?>
            <p class="ordrar-help">Skiljer sig (ändras inte automatiskt): <?= e(implode(' ', $differs)) ?></p>
        <?php endif; ?>
        <?php if ($dupes > 0): ?>
            <p class="ordrar-help is-alert">Solkoll har <?= $dupes + 1 ?> anläggningar på adressen. Kopplad till den med mest data.</p>
        <?php endif; ?>
        <form data-js="ordrar-solkoll-form" method="post" action="<?= e($action) ?>">
            <?= function_exists('jeab_csrf_field') ? jeab_csrf_field() : '' ?>
            <input type="hidden" name="action" value="ordrar.solkoll">
            <input type="hidden" name="id" value="<?= (int) $openId ?>">
            <div class="ordrar-next-row">
                <button class="ordrar-secondary" type="submit" data-js="ordrar-once"><?= $id !== '' ? 'Uppdatera Solkoll' : 'Skapa i Solkoll' ?></button>
            </div>
            <p class="ordrar-help<?= $err !== '' ? ' is-alert' : '' ?>" data-js="ordrar-solkoll-status" role="status"><?= $err !== '' ? e(function_exists('jeab_ordrar_save_fail_text') ? jeab_ordrar_save_fail_text($err) : $err) : '' ?></p>
        </form>
    </div>
    <?php
    return (string) ob_get_clean();
}

function jeab_solkoll_status_for_row(array $row): array
{
    $empty = ['key' => '', 'label' => '', 'note' => '', 'done' => 0, 'total' => 0];
    if (!jeab_solkoll_applies($row)) {
        return $empty;
    }
    $stored = trim((string) ($row['solkoll_status'] ?? ''));
    $key = jeab_solkoll_status_key($stored) ?: $stored;
    if (!in_array($key, ['ny', 'paborjad', 'slutford'], true)) {
        $key = '';
    }
    $note = trim((string) ($row['solkoll_note'] ?? ''));
    $done = max(0, (int) ($row['solkoll_todos_done'] ?? 0));
    $total = max(0, (int) ($row['solkoll_todos_total'] ?? 0));
    $live = !function_exists('jeab_ordrar_external_live') || jeab_ordrar_external_live();
    if ($live && PHP_SAPI !== 'cli' && !jeab_solkoll_request_wants_catalog($row)) {
        $live = false;
    }
    if ($live) {
        $found = jeab_solkoll_facility_for_row($row);
        if (is_array($found)) {
            $apiKey = jeab_solkoll_status_key((string) ($found['FacilityStatus'] ?? ''));
            if ($apiKey !== '') {
                $key = $apiKey;
            }
            if (isset($found['TodosCompleted']) || isset($found['TotalTodos'])) {
                $done = max(0, (int) ($found['TodosCompleted'] ?? 0));
                $total = max(0, (int) ($found['TotalTodos'] ?? 0));
            }
        }
    }
    $label = jeab_solkoll_status_label($key);
    if ($total > 0) {
        $step = $done . '/' . $total;
        $label = $label !== '' ? ($label . ' ' . $step) : $step;
    }
    return [
        'key' => $key,
        'label' => $label,
        'note' => $note,
        'done' => $done,
        'total' => $total,
    ];
}

function jeab_solkoll_icon_svg(string $key): string
{
    if ($key === 'paborjad') {
        return '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8.2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M10 5.6v4.6l3 1.8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
    if ($key === 'slutford') {
        return '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8.2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M6.2 10.2 8.8 12.7 13.8 7.4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
    return '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8.2" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>';
}

function jeab_solkoll_status_html(array $row): string
{
    if (!jeab_solkoll_applies($row)) {
        return '';
    }
    $info = jeab_solkoll_status_for_row($row);
    if ($info['key'] === '' && $info['note'] === '') {
        return '';
    }
    $html = '<span class="ordrar-solkoll">';
    if ($info['key'] !== '') {
        $html .= '<span class="ordrar-solkoll-mark is-' . e($info['key']) . '" title="' . e($info['label']) . '">';
        $html .= jeab_solkoll_icon_svg($info['key']);
        $html .= '<span>' . e($info['label']) . '</span></span>';
    }
    if ($info['note'] !== '') {
        $html .= '<span class="ordrar-solkoll-note">' . e($info['note']) . '</span>';
    }
    $html .= '</span>';
    return $html;
}

/**
 * Beställd solorder vars dokumentation fortfarande är Ny, eller som saknar anläggning.
 * Påbörjad och slutförd hoppas över.
 */
function jeab_solkoll_still_ny(array $row): bool
{
    if (!jeab_solkoll_applies($row)) {
        return false;
    }
    if (!function_exists('jeab_ordrar_bestalld') || !jeab_ordrar_bestalld($row)) {
        return false;
    }
    $stored = trim((string) ($row['solkoll_status'] ?? ''));
    $key = jeab_solkoll_status_key($stored);
    if ($key === '' && in_array($stored, ['ny', 'paborjad', 'slutford'], true)) {
        $key = $stored;
    }
    if ($key === 'paborjad' || $key === 'slutford') {
        return false;
    }
    if ($key === 'ny') {
        return true;
    }
    return trim((string) ($row['solkoll_facility_id'] ?? '')) === '';
}

/**
 * Riktiga anrop bara när en testmock är inkopplad, eller när live-läge och nyckel finns.
 * Webhook-fångst och avstängt externläge (befintliga tester) får inte skapa anläggningar.
 */
function jeab_solkoll_calls_allowed(): bool
{
    if (isset($GLOBALS['_JEAB_SOLKOLL_HTTP']) && is_callable($GLOBALS['_JEAB_SOLKOLL_HTTP'])) {
        return true;
    }
    $capture = getenv('JEAB_BUG_WEBHOOK_CAPTURE');
    if ($capture === false || $capture === '') {
        $capture = (string) ($_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] ?? '');
    }
    if ($capture === '1' || $capture === 'true') {
        return false;
    }
    if (function_exists('jeab_ordrar_external_live') && !jeab_ordrar_external_live()) {
        return false;
    }
    return jeab_solkoll_ready();
}

/**
 * Efter Beställ: skapa eller koppla Solkoll en gång. Misslyckande stoppar inte beställningen.
 */
function jeab_ordrar_solkoll_after_placed(array $row): array
{
    if (!jeab_solkoll_still_ny($row) || !jeab_solkoll_calls_allowed()) {
        return $row;
    }
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0) {
        return $row;
    }
    try {
        return jeab_solkoll_run_order($id);
    } catch (Throwable) {
        $fresh = function_exists('jeab_purchase_get') ? jeab_purchase_get($id) : null;
        return is_array($fresh) ? $fresh : $row;
    }
}

/**
 * dry saknas = torr. confirm=1 ensamt skriver. dry=0 utan confirm = 422. dry=1 skriver aldrig.
 *
 * @return 'dry'|'wet'|'need_confirm'
 */
function jeab_solkoll_ny_mode(array $input): string
{
    $confirm = ($input['confirm'] ?? null) === 1
        || ($input['confirm'] ?? null) === '1'
        || ($input['confirm'] ?? null) === true;
    $hasDry = array_key_exists('dry', $input);
    $dry = function_exists('jeab_ordrar_backfill_wants_dry')
        ? jeab_ordrar_backfill_wants_dry($input)
        : true;
    if ($hasDry && !$dry && !$confirm) {
        return 'need_confirm';
    }
    if ($confirm && (!$hasDry || !$dry)) {
        return 'wet';
    }
    return 'dry';
}

/**
 * Redan beställda solordrar vars Solkoll fortfarande är Ny.
 * Torr körning listar id. confirm=1 skapar eller kopplar, idempotent, högst 40 per anrop.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_solkoll_ny_run(array $input): array
{
    $mode = jeab_solkoll_ny_mode($input);
    if ($mode === 'need_confirm') {
        return [
            'ok' => false,
            'error' => 'Bekräfta med confirm=1.',
            'action' => 'ordrar.solkoll_ny',
            'dry' => true,
            'written' => 0,
            'status' => 422,
        ];
    }
    $ids = [];
    foreach (jeab_purchase_all() as $row) {
        if (!is_array($row) || !jeab_solkoll_still_ny($row)) {
            continue;
        }
        $id = (int) ($row['reminder_id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $cap = 40;
    $slice = array_slice($ids, 0, $cap);
    $left = max(0, count($ids) - count($slice));
    if ($mode === 'dry') {
        return [
            'ok' => true,
            'action' => 'ordrar.solkoll_ny',
            'dry' => true,
            'written' => 0,
            'order_ids' => $slice,
            'left' => $left,
            'status' => 200,
        ];
    }
    $orders = [];
    $written = 0;
    foreach ($slice as $id) {
        try {
            $row = jeab_solkoll_run_order($id);
            $note = (string) ($row['solkoll_note'] ?? '');
            $did = str_starts_with($note, 'Skapad') ? 'create' : (str_starts_with($note, 'Kopplad') ? 'link' : 'error');
            if ($did !== 'error') {
                $written++;
            }
            $orders[] = [
                'id' => $id,
                'did' => $did,
                'facility_id' => trim((string) ($row['solkoll_facility_id'] ?? '')),
            ];
        } catch (Throwable) {
            $orders[] = ['id' => $id, 'did' => 'error'];
        }
    }
    return [
        'ok' => true,
        'action' => 'ordrar.solkoll_ny',
        'dry' => false,
        'written' => $written,
        'orders' => $orders,
        'left' => $left,
        'status' => 200,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_solkoll_ny_dispatch(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'action' => 'ordrar.solkoll_ny', 'status' => 401];
    }
    return jeab_solkoll_ny_run($input);
}
