<?php

declare(strict_types=1);

/**
 * Beställ skapar eller kopplar Solkoll för sol. Hook ordrar.solkoll_ny är torr
 * utan confirm=1. Batteri rörs inte. Inget personnummer i order, svar eller logg.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-solkoll-bestall-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempdir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', '<?php return ' . var_export([
    'db' => [
        'driver' => 'json',
        'json_path' => $tmp . '/store.json',
        'sqlite_path' => $tmp . '/app.sqlite',
    ],
], true) . ';');
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
putenv('JEAB_DISABLE_SQLITE=1');
putenv('SOLKOLL_API_KEY');
putenv('SOLKOLL_API_URL');
unset($_ENV['SOLKOLL_API_KEY'], $_ENV['SOLKOLL_API_URL']);
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
$GLOBALS['_JEAB_SOLKOLL_PRODUCTS'] = [];

require dirname(__DIR__) . '/src/bootstrap.php';

$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);

$secretPath = jeab_bokmalen_ordrar_webhook_secret_path();
$secretDir = dirname($secretPath);
if (!is_dir($secretDir)) {
    mkdir($secretDir, 0775, true);
}
file_put_contents($secretPath, json_encode([
    'url' => 'https://bokmalen.example.invalid/hooks/jeab-ordrar',
    'key' => 'solkoll-bestall-key',
], JSON_UNESCAPED_SLASHES));

/** @var array{creates:int,facilities:array<string,array<string,mixed>>} $solState */
$solState = ['creates' => 0, 'facilities' => []];

$GLOBALS['_JEAB_SOLKOLL_HTTP'] = static function (string $method, string $path, ?array $body = null) use (&$solState): array {
    $method = strtoupper($method);
    $route = trim((string) parse_url('/' . ltrim($path, '/'), PHP_URL_PATH), '/');
    if ($method === 'GET' && $route === 'products') {
        return ['Items' => []];
    }
    if ($method === 'GET' && $route === 'facilities') {
        return ['Items' => array_values($solState['facilities'])];
    }
    if ($method === 'POST' && $route === 'facilities') {
        $solState['creates']++;
        $id = 'fac-' . $solState['creates'];
        $item = is_array($body) ? $body : [];
        $item['FacilityId'] = $id;
        $item['FacilityStatus'] = (string) ($item['FacilityStatus'] ?? 'NEW');
        $solState['facilities'][$id] = $item;
        return ['Item' => $item];
    }
    if (str_starts_with($route, 'facilities/')) {
        $id = rawurldecode(substr($route, strlen('facilities/')));
        if (!isset($solState['facilities'][$id])) {
            throw new JeabSolkollNotFound('saknas');
        }
        if ($method === 'PATCH' && is_array($body)) {
            $solState['facilities'][$id] = array_merge($solState['facilities'][$id], $body);
            $solState['facilities'][$id]['FacilityId'] = $id;
        }
        return ['Item' => $solState['facilities'][$id]];
    }
    return ['Items' => []];
};

function sb_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sb_leak(string $hay, string $label): void
{
    $bad = preg_match('/\d{12}|(?<!\d)\d{10}(?!\d)|\d{8}\s*[-+]\s*\d{4}|\d{6}\s*[-+]\s*\d{4}/', $hay) === 1;
    sb_assert(!$bad, $label . ' saknar personnummer');
}

/** @param array<string,mixed> $extra */
function sb_put(array $extra): array
{
    static $seq = 4400;
    $seq++;
    $row = array_merge([
        'reminder_id' => $seq,
        'kind' => 'sol',
        'company' => 'jarfalla',
        'natbolag' => 'SEOM',
        'huvudsokande' => 'Test Kund',
        'name' => 'Test Kund',
        'phone' => '0700000000',
        'email' => 'kund@example.com',
        'huvudsokande_address' => 'Exempelvägen ' . $seq . ', 192 66 Sollentuna',
        'inkop_lines' => [
            ['sku' => 'SOL-1', 'title' => 'Panel', 'qty' => 1, 'shop' => 'aprilice'],
        ],
        'steps' => [],
    ], $extra);
    return jeab_purchase_put($row);
}

$log = $tmp . '/php-error.log';
ini_set('log_errors', '1');
ini_set('error_log', $log);

$before = $solState['creates'];
$draft = sb_put(['name' => 'Utkast Kund', 'huvudsokande' => 'Utkast Kund']);
$drafted = jeab_ordrar_save_bestall([
    'id' => (int) $draft['reminder_id'],
    'utkast' => '1',
]);
sb_assert(!jeab_ordrar_bestalld($drafted), 'utkast är inte beställt');
sb_assert(trim((string) ($drafted['solkoll_facility_id'] ?? '')) === '', 'utkast skapar ingen anläggning');
sb_assert($solState['creates'] === $before, 'utkast anropar inte Solkoll');

$fresh = sb_put([
    'name' => 'Ny Kund',
    'huvudsokande' => 'Ny Kund',
    'solkoll_status' => 'ny',
]);
sb_assert(jeab_solkoll_still_ny($fresh) === false, 'obeställd sol räknas inte');
$placed = jeab_ordrar_save_bestall([
    'id' => (int) $fresh['reminder_id'],
    'intent' => 'bestall',
]);
sb_assert(jeab_ordrar_bestalld($placed), 'Beställ stämplar ordern');
sb_assert($solState['creates'] === $before + 1, 'Beställ skapar en anläggning');
sb_assert(trim((string) ($placed['solkoll_facility_id'] ?? '')) !== '', 'Beställ sparar anläggnings-id');
sb_assert(str_starts_with((string) ($placed['solkoll_note'] ?? ''), 'Skapad'), 'Beställ noterar skapad');
sb_assert((string) ($placed['solkoll_status'] ?? '') === 'ny', 'ny anläggning behåller API-status ny');
$again = jeab_ordrar_save_bestall([
    'id' => (int) $fresh['reminder_id'],
    'intent' => 'bestall',
]);
sb_assert($solState['creates'] === $before + 1, 'andra Beställ skapar ingen ny anläggning');
sb_assert(str_starts_with((string) ($again['solkoll_note'] ?? ''), 'Kopplad'), 'andra Beställ kopplar samma anläggning');
sb_assert((string) ($again['solkoll_facility_id'] ?? '') === (string) ($placed['solkoll_facility_id'] ?? ''), 'anläggnings-id är stabilt');

$batBefore = $solState['creates'];
$bat = sb_put([
    'kind' => 'batteri',
    'name' => 'Batteri Kund',
    'huvudsokande' => 'Batteri Kund',
    'solkoll_status' => 'ny',
]);
$batPlaced = jeab_ordrar_save_bestall([
    'id' => (int) $bat['reminder_id'],
    'intent' => 'bestall',
]);
sb_assert(jeab_ordrar_bestalld($batPlaced), 'batteri blir beställt');
sb_assert($solState['creates'] === $batBefore, 'batteri skapar ingen Solkoll-anläggning');
sb_assert(jeab_solkoll_still_ny($batPlaced) === false, 'batteri är inte en Ny-solorder');

$linkBefore = $solState['creates'];
$link = sb_put([
    'name' => 'Finns Kund',
    'huvudsokande' => 'Finns Kund',
    'huvudsokande_address' => 'Lertavlan 4, 192 66 Sollentuna',
    'solkoll_status' => 'ny',
]);
$solState['facilities']['fac-live'] = [
    'FacilityId' => 'fac-live',
    'FacilityStatus' => 'IN_PROGRESS',
    'Name' => 'Finns Kund',
    'Address' => 'Lertavlan 4',
    'ZipCode' => '19266',
    'City' => 'Sollentuna',
];
$linked = jeab_ordrar_save_bestall([
    'id' => (int) $link['reminder_id'],
    'intent' => 'bestall',
]);
sb_assert($solState['creates'] === $linkBefore, 'befintlig anläggning skapas inte om');
sb_assert((string) ($linked['solkoll_facility_id'] ?? '') === 'fac-live', 'Beställ kopplar anläggningen på adressen');
sb_assert((string) ($linked['solkoll_status'] ?? '') === 'paborjad', 'koppling skriver API-status påbörjad');
sb_assert(str_starts_with((string) ($linked['solkoll_note'] ?? ''), 'Kopplad'), 'koppling noteras');
$doc = jeab_solkoll_status_for_row($linked);
sb_assert((string) ($doc['key'] ?? '') === 'paborjad', 'översikten lämnar Ny när Solkoll är påbörjad');
sb_assert(jeab_solkoll_still_ny($linked) === false, 'påbörjad order tas inte med i hooken');

$store = jeab_boot();
$manual = sb_put([
    'name' => 'Manuell Kund',
    'huvudsokande' => 'Manuell Kund',
    'bestallning_status' => 'C',
]);
$manualBefore = $solState['creates'];
$stamped = jeab_ordrar_save_bestall_steg($store, [
    'id' => (int) $manual['reminder_id'],
    'intent' => 'next',
    'bestallning_status' => 'C',
]);
sb_assert(jeab_ordrar_bestalld($stamped), 'manuell beställning stämplas');
sb_assert($solState['creates'] === $manualBefore + 1, 'manuell beställning skapar Solkoll');

$dryRow = sb_put([
    'name' => 'Hook Kund',
    'huvudsokande' => 'Hook Kund',
    'steps' => ['bestalld' => '2026-10-26'],
    'solkoll_status' => 'ny',
]);
$dry = jeab_solkoll_ny_run([]);
sb_assert(($dry['dry'] ?? false) === true && (int) ($dry['written'] ?? 1) === 0, 'hook utan confirm är torr');
$dryIds = array_map('intval', is_array($dry['order_ids'] ?? null) ? $dry['order_ids'] : []);
sb_assert(in_array((int) $dryRow['reminder_id'], $dryIds, true), 'torr hook listar beställd Ny-sol');
sb_assert(!in_array((int) $bat['reminder_id'], $dryIds, true), 'torr hook hoppar över batteri');
sb_assert(!in_array((int) $linked['reminder_id'], $dryIds, true), 'torr hook hoppar över påbörjad');
$need = jeab_solkoll_ny_run(['dry' => 0]);
sb_assert((int) ($need['status'] ?? 0) === 422, 'dry=0 utan confirm stoppas');
$held = jeab_solkoll_ny_run(['dry' => 1, 'confirm' => 1]);
sb_assert(($held['dry'] ?? false) === true && (int) ($held['written'] ?? 1) === 0, 'dry=1 skriver inte ens med confirm');
$createsAtHook = $solState['creates'];
$wet = jeab_solkoll_ny_run(['confirm' => 1]);
sb_assert(($wet['dry'] ?? true) === false && (int) ($wet['written'] ?? 0) >= 1, 'confirm=1 skriver');
$did = '';
foreach (is_array($wet['orders'] ?? null) ? $wet['orders'] : [] as $item) {
    if (is_array($item) && (int) ($item['id'] ?? 0) === (int) $dryRow['reminder_id']) {
        $did = (string) ($item['did'] ?? '');
    }
}
sb_assert($did === 'create', 'hook skapar anläggning för Ny-order');
sb_assert($solState['creates'] === $createsAtHook + 1, 'hook skapar en anläggning');
$wetAgain = jeab_solkoll_ny_run(['confirm' => 1]);
$didAgain = '';
foreach (is_array($wetAgain['orders'] ?? null) ? $wetAgain['orders'] : [] as $item) {
    if (is_array($item) && (int) ($item['id'] ?? 0) === (int) $dryRow['reminder_id']) {
        $didAgain = (string) ($item['did'] ?? '');
    }
}
sb_assert($didAgain === 'link', 'hook kopplar om samma order');
sb_assert($solState['creates'] === $createsAtHook + 1, 'hook skapar inte en andra anläggning');
sb_leak(json_encode($wet, JSON_UNESCAPED_UNICODE) ?: '', 'hook-svaret');
sb_leak(json_encode($dry, JSON_UNESCAPED_UNICODE) ?: '', 'torr hook');

unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
$denied = jeab_solkoll_ny_dispatch([]);
sb_assert((int) ($denied['status'] ?? 0) === 401 && ($denied['error'] ?? '') === 'Fel token.', 'dispatch utan token är 401');

$skip = sb_put([
    'name' => 'Avstangd Kund',
    'huvudsokande' => 'Avstangd Kund',
    'steps' => ['bestalld' => '2026-10-26'],
    'solkoll_status' => 'ny',
]);
$mock = $GLOBALS['_JEAB_SOLKOLL_HTTP'];
unset($GLOBALS['_JEAB_SOLKOLL_HTTP']);
jeab_ordrar_external_live(false);
$skipped = jeab_ordrar_solkoll_after_placed(jeab_purchase_get((int) $skip['reminder_id']) ?: $skip);
sb_assert(trim((string) ($skipped['solkoll_facility_id'] ?? '')) === '', 'avstängt externläge skapar inget');
jeab_ordrar_external_live(true);
$GLOBALS['_JEAB_SOLKOLL_HTTP'] = $mock;

if (is_file($log)) {
    sb_leak((string) file_get_contents($log), 'felloggen');
}

echo "ordrar solkoll bestall: ok\n";
