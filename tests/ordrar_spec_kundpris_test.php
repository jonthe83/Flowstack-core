<?php

declare(strict_types=1);

function kp_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function kp_extract(string $src, string $name): string
{
    $needle = 'function ' . $name . '(';
    $start = strpos($src, $needle);
    if ($start === false) {
        kp_fail("saknar {$name}");
    }
    $brace = strpos($src, '{', $start);
    if ($brace === false) {
        kp_fail("saknar kropp {$name}");
    }
    $depth = 0;
    $len = strlen($src);
    for ($i = $brace; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    kp_fail("okapad {$name}");
}

$root = dirname(__DIR__);
$ordrar = (string) file_get_contents($root . '/src/ordrar.php');
$purchase = (string) file_get_contents($root . '/src/purchase.php');
$page = (string) file_get_contents($root . '/src/pages/ordrar.php');

eval(kp_extract($purchase, 'jeab_purchase_amount'));
eval(kp_extract($ordrar, 'jeab_ordrar_resolve_kundpris'));

$kept = jeab_ordrar_resolve_kundpris(null, 90000);
if ($kept !== 90000) {
    kp_fail('tomt kundpris ska behålla 90000');
}
$keptSpace = jeab_ordrar_resolve_kundpris("90\u{202F}000", 1);
if ($keptSpace !== 90000) {
    kp_fail('smalt mellanslag ska tolkas som 90000');
}
$cleared = jeab_ordrar_resolve_kundpris('', 90000);
if ($cleared !== 90000) {
    kp_fail('tom sträng ska behålla kundpris');
}
$updated = jeab_ordrar_resolve_kundpris('95 000', 90000);
if ($updated !== 95000) {
    kp_fail('nytt belopp ska skriva över');
}

$specFn = kp_extract($ordrar, 'jeab_ordrar_spec_html');
if (str_contains($specFn, 'Kundpris') || str_contains($specFn, 'name="kundpris"')) {
    kp_fail('Följdfrågor ska inte ha Kundpris-fält');
}
foreach (['Växelriktare', 'kWh', 'Anläggnings-id', 'Nätbolag', 'Föranmälan'] as $label) {
    if (!str_contains($specFn, $label)) {
        kp_fail("Följdfrågor saknar {$label}");
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function jeab_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="t">';
}
function jeab_ordrar_ui_kind(mixed $raw): string
{
    return 'sol';
}
function jeab_ordrar_url(array $extra = []): string
{
    return '/index.php?p=ordrar&stop=spec';
}
function jeab_ordrar_fortnox_missing(array $row): array
{
    return [];
}
function jeab_ordrar_fortnox_price(array $row): int
{
    return (int) ($row['kundpris'] ?? 0);
}
function jeab_ordrar_kr(int $amount): string
{
    return number_format($amount, 0, ',', ' ');
}
function jeab_ordrar_foranmalan_status(array $row): string
{
    return 'A';
}
function jeab_ordrar_foranmalan_choices(): array
{
    return ['A' => 'Automatiskt'];
}
function jeab_ordrar_natbolag(array $row): string
{
    return 'Ellevio';
}
function jeab_ordrar_natbolag_options(): array
{
    return ['Ellevio', 'E.ON', 'Vattenfall', 'SEOM', 'Kraftringen', 'Öresundskraft'];
}

eval(kp_extract($ordrar, 'jeab_ordrar_anlaggnings_id_digits'));
eval(kp_extract($ordrar, 'jeab_ordrar_kwh_amount'));
eval($specFn);
$html = jeab_ordrar_spec_html([
    'kind' => 'sol',
    'huvudsokande_address' => 'LILLÄNGSGATAN 15',
    'kundpris' => 90000,
    'kwh' => '10',
    'anlaggnings_id' => '735999',
    'huvudsokande' => 'Jon Mauritz Helin',
], 'jarfalla', 34);
if (str_contains($html, 'Kundpris') || str_contains($html, 'name="kundpris"')) {
    kp_fail('renderad Följdfrågor har fortfarande Kundpris');
}
foreach (['Växelriktare', 'name="kwh"', 'Anläggnings-id', 'name="natbolag"', 'LILLÄNGSGATAN 15'] as $bit) {
    if (!str_contains($html, $bit)) {
        kp_fail("renderad Följdfrågor saknar {$bit}");
    }
}
if (str_contains($html, 'Fortnox-order skapas')) {
    kp_fail('Följdfrågor ska inte längre skapa Fortnox-order');
}
if (!str_contains($html, 'Föranmälan')) {
    kp_fail('Följdfrågor ska behålla föranmälan');
}

$bekraftaStart = strpos($page, "if (\$view === 'bekrafta')");
$bekraftaEnd = strpos($page, '<?php elseif', $bekraftaStart === false ? 0 : $bekraftaStart);
if ($bekraftaStart === false || $bekraftaEnd === false) {
    kp_fail('hittar inte Bekräfta-vyn');
}
$bekrafta = substr($page, $bekraftaStart, $bekraftaEnd - $bekraftaStart);
if (!str_contains($bekrafta, '<span>Pris</span>') || !str_contains($bekrafta, 'name="pris"')) {
    kp_fail('Bekräfta ska behålla priset kunden betalar');
}
if (str_contains($bekrafta, 'name="kundpris"')) {
    kp_fail('Bekräfta ska spara pris via name=pris');
}

$GLOBALS['kp_rows'] = [
    34 => [
        'reminder_id' => 34,
        'kind' => 'sol',
        'pris' => 90000,
        'kundpris' => 90000,
        'steps' => ['fatt_alla_uppgifter' => '2026-09-01'],
    ],
];

function jeab_ordrar_require(int $id): array
{
    return $GLOBALS['kp_rows'][$id] ?? kp_fail('okänd order');
}
function jeab_purchase_normalize_kind(mixed $raw): ?string
{
    $k = trim((string) $raw);
    return in_array($k, ['laddbox', 'batteri', 'sol'], true) ? $k : null;
}
function jeab_ordrar_apply_confirm_fields(array $row, array $input): array
{
    return $row;
}
function jeab_ordrar_stamp_today(array $row, string $key): array
{
    return $row;
}
function jeab_now(): string
{
    return '2026-09-23 12:00:00';
}
function jeab_purchase_put(array $row): array
{
    return $row;
}
function jeab_ordrar_refresh_linked_booking_notes(array $row): void
{
}
function jeab_ordrar_sync_lm_contact(array $order): bool
{
    return false;
}
function jeab_ordrar_apply_foranmalan_status(array $row, array $input): array
{
    return $row;
}
function jeab_ordrar_wants_advance(array $input): bool
{
    return false;
}
function jeab_ordrar_fortnox_on_next(array $row): array
{
    return $row;
}

function jeab_ordrar_kalkyl_from_input(array $input): ?array
{
    if (!array_key_exists('kalkyl_present', $input)) {
        return null;
    }
    return [
        'b14' => (int) ($input['b14'] ?? 0),
        'b3' => (int) ($input['b3'] ?? 0),
        'sokande' => max(1, (int) ($input['sokande'] ?? 1)),
        'b15' => !empty($input['b15']) ? 1 : 0,
        'laddare' => !empty($input['laddare']) ? 1 : 0,
    ];
}

eval(kp_extract($ordrar, 'jeab_ordrar_save_questions'));
eval(kp_extract($ordrar, 'jeab_ordrar_save_batterisol'));

$same = jeab_ordrar_save_questions([
    'id' => 34,
    'kind' => 'sol',
    'pris' => '90 000',
]);
if ((int) $same['kundpris'] !== 90000 || (int) $same['pris'] !== 90000) {
    kp_fail('oförändrat pris på Bekräfta ska lämna kundpris');
}

$GLOBALS['kp_rows'][34]['pris'] = 90000;
$GLOBALS['kp_rows'][34]['kundpris'] = 90000;
$edited = jeab_ordrar_save_questions([
    'id' => 34,
    'kind' => 'sol',
    'pris' => '95 000',
]);
if ((int) $edited['pris'] !== 95000 || (int) $edited['kundpris'] !== 95000) {
    kp_fail('ändrat pris på Bekräfta ska flyta vidare till kundpris');
}

$GLOBALS['kp_rows'][34]['pris'] = 90000;
$GLOBALS['kp_rows'][34]['kundpris'] = 80000;
$diverged = jeab_ordrar_save_questions([
    'id' => 34,
    'kind' => 'sol',
    'pris' => '90 000',
]);
if ((int) $diverged['kundpris'] !== 80000) {
    kp_fail('samma pris ska inte skriva över ett annat kundpris');
}

$GLOBALS['kp_rows'][34]['pris'] = 0;
$GLOBALS['kp_rows'][34]['kundpris'] = 0;
$seed = jeab_ordrar_save_questions([
    'id' => 34,
    'kind' => 'batteri',
    'pris' => "90\u{202F}000",
]);
if ((int) $seed['kundpris'] !== 90000) {
    kp_fail('första priset på Bekräfta ska bli kundpris');
}

$GLOBALS['kp_rows'][34] = [
    'reminder_id' => 34,
    'kind' => 'laddbox',
    'pris' => 10000,
    'kundpris' => 0,
    'steps' => ['fatt_alla_uppgifter' => '2026-09-01'],
];
$box = jeab_ordrar_save_questions([
    'id' => 34,
    'kind' => 'laddbox',
    'pris' => '12000',
]);
if ((int) $box['kundpris'] !== 0 || (int) $box['pris'] !== 12000) {
    kp_fail('laddbox ska inte skriva kundpris');
}

$GLOBALS['kp_rows'][34] = [
    'reminder_id' => 34,
    'kind' => 'sol',
    'pris' => 90000,
    'kundpris' => 90000,
    'steps' => ['fatt_alla_uppgifter' => '2026-09-01'],
];
$withKalkyl = jeab_ordrar_save_questions([
    'id' => 34,
    'kind' => 'sol',
    'pris' => '90 000',
    'kalkyl_present' => '1',
    'b14' => '18',
    'b3' => '3',
    'sokande' => '2',
    'b15' => '1',
    'laddare' => '1',
]);
$storedKalkyl = is_array($withKalkyl['kalkyl'] ?? null) ? $withKalkyl['kalkyl'] : [];
if (($storedKalkyl['b14'] ?? 0) !== 18 || ($storedKalkyl['b3'] ?? 0) !== 3 || ($storedKalkyl['sokande'] ?? 0) !== 2 || ($storedKalkyl['b15'] ?? 0) !== 1 || ($storedKalkyl['laddare'] ?? 0) !== 1) {
    kp_fail('Bekräfta ska spara kalkylens paneler, moduler, sökande, TP2 och laddare');
}
if ((int) $withKalkyl['kundpris'] !== 90000) {
    kp_fail('oförändrat pris med kalkyl ska lämna kundpris');
}

$GLOBALS['kp_rows'][34] = [
    'reminder_id' => 34,
    'kind' => 'sol',
    'pris' => 90000,
    'kundpris' => 90000,
    'vaxelriktare' => 'Sigenergy 12 kW TP2',
    'kwh' => '10',
    'anlaggnings_id' => '735999',
    'natbolag' => 'Ellevio',
    'fortnox_order' => '',
];
$saved = jeab_ordrar_save_batterisol([
    'id' => 34,
    'vaxelriktare' => 'Sigenergy 12 kW TP2',
    'kwh' => '10',
    'anlaggnings_id' => '735999',
    'natbolag' => 'Ellevio',
    'foranmalan_status' => 'A',
]);
if ((int) $saved['kundpris'] !== 90000) {
    kp_fail('Följdfrågor utan kundpris-fält ska behålla 90000');
}

$savedEmpty = jeab_ordrar_save_batterisol([
    'id' => 34,
    'kundpris' => '',
    'kwh' => '11',
]);
if ((int) $savedEmpty['kundpris'] !== 90000 || (string) $savedEmpty['kwh'] !== '11') {
    kp_fail('tomt postat kundpris ska behålla priset');
}
$spacedAid = jeab_ordrar_save_batterisol([
    'id' => 34,
    'anlaggnings_id' => '735 999 114 009 241 688',
    'kwh' => '9',
]);
if (($spacedAid['anlaggnings_id'] ?? '') !== '735999114009241688') {
    kp_fail('anläggnings-id ska sparas utan mellanslag');
}

fwrite(STDOUT, "ok\n");
