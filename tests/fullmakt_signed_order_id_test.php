<?php

declare(strict_types=1);

/**
 * fullmakt.signed bär alltid samma order_id som fullmakt.start.
 * Starten sparar order_id på fullmakten. Saknas det (äldre dokument) slås
 * ordern upp via token eller orderns egen fullmaktslänk. Går det inte alls
 * loggas ett appfel, aldrig ett tyst tomt order_id.
 * Signeringssidan laddas utan JEAB-appen; vid signeringen laddas appens
 * orderlager (MySQL live) så uppslaget inte läser en gammal purchases.json.
 */

$tmp = sys_get_temp_dir() . '/jeab-fullmakt-signed-order-' . bin2hex(random_bytes(4));
if (!mkdir($tmp . '/fullmakt', 0775, true) && !is_dir($tmp . '/fullmakt')) {
    fwrite(STDERR, "FAIL kunde inte skapa tempdir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('FULLMAKT_DATA=' . $tmp . '/fullmakt');
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
putenv('JEAB_DISABLE_SQLITE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    fwrite(STDERR, "FAIL inget mejl får skickas\n");
    exit(1);
};

require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ellevio_fullmakt_load();

function fso_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @return list<array<string,mixed>> */
function fso_of_type(string $type): array
{
    $out = [];
    foreach ((array) ($GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? []) as $row) {
        if (is_array($row) && ($row['type'] ?? '') === $type) {
            $out[] = $row;
        }
    }
    return $out;
}

function fso_log(): string
{
    $path = jeab_app_errors_path();
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function fso_put(int $id, array $extra = []): array
{
    return jeab_purchase_put(array_merge([
        'reminder_id' => $id,
        'kind' => 'sol',
        'company' => 'jarfalla',
        'natbolag' => 'Ellevio',
        'huvudsokande' => 'Johan Edin',
        'name' => 'Johan Edin',
        'personnummer' => '19800101-1234',
        'phone' => '0701234567',
        'email' => 'johan@example.com',
        'huvudsokande_address' => 'Gnejsvägen 15A, 183 30 Täby',
        'anlaggnings_id' => '',
        'kwh' => '10',
    ], $extra));
}

function fso_drop(int $id): void
{
    $all = jeab_purchase_all();
    unset($all[(string) $id]);
    jeab_purchase_save_all($all);
}

function fso_sign(string $token): array
{
    $doc = fullmakt_load_doc($token);
    return fullmakt_mark_signed($token, is_array($doc) ? $doc : [], '%PDF-1.4 signerad', [
        'stamp_method' => 'ellevio',
        'anlaggnings_id' => '735999114010135815',
    ]);
}

$secretPath = jeab_bokmalen_ellevio_fullmakt_webhook_secret_path();
@mkdir(dirname($secretPath), 0775, true);
file_put_contents($secretPath, json_encode(['url' => 'https://bokmalen.example.invalid/hooks/f', 'key' => 'fso-test-key'], JSON_UNESCAPED_SLASHES));

$base = [
    'namn' => 'Johan Edin',
    'personnummer' => '19800101-1234',
    'telefon' => '0701234567',
    'epost' => 'johan@example.com',
    'adress' => 'Gnejsvägen 15A',
    'postnummer' => '183 30',
    'ort' => 'Täby',
    'typ' => 'privatperson',
    'stamp_method' => 'ellevio',
];

try {
    // 1. Start sparar order_id; signed bär det även när ordern inte hittas via token.
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
    $row = fso_put(69);
    $started = jeab_ellevio_fullmakt_start($row);
    $token = trim((string) ($started['fullmakt_token'] ?? ''));
    fso_assert($token !== '', 'starten skapar en väntande fullmakt');
    $starts = fso_of_type('fullmakt.start');
    fso_assert(count($starts) === 1 && ($starts[0]['order_id'] ?? '') === '69', 'fullmakt.start har order_id 69');
    fso_assert((string) (fullmakt_load_doc($token)['order_id'] ?? '') === '69', 'fullmakten sparar order_id vid start');

    fso_drop(69); // som live: uppslaget via token ser inte ordern
    $before = fso_log();
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
    $signed = fso_sign($token);
    fso_assert(($signed['status'] ?? '') === 'signed', 'fullmakten signeras');
    fso_assert((string) ($signed['order_id'] ?? '') === '69', 'signerade posten behåller order_id');
    $posts = fso_of_type('fullmakt.signed');
    fso_assert(count($posts) === 1, 'en fullmakt.signed postas');
    fso_assert(($posts[0]['order_id'] ?? '') === ($starts[0]['order_id'] ?? 'x'), 'fullmakt.signed har samma order_id som fullmakt.start');
    fso_assert(($posts[0]['token'] ?? '') === $token, 'fullmakt.signed har samma token som starten');
    fso_assert(!str_contains(substr(fso_log(), strlen($before)), 'order_id saknas'), 'inget appfel när order_id finns');

    // 2. Äldre fullmakt utan order_id: token-uppslaget fyller i det.
    $legacy = fullmakt_create_doc($base);
    $legacyToken = (string) $legacy['token'];
    fso_assert(!array_key_exists('order_id', $legacy), 'äldre dokument saknar order_id');
    fso_put(70, ['fullmakt_token' => $legacyToken]);
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
    fso_sign($legacyToken);
    $posts = fso_of_type('fullmakt.signed');
    fso_assert(count($posts) === 1 && ($posts[0]['order_id'] ?? '') === '70', 'token-uppslaget fyller order_id när posten saknar det');
    fso_assert(!empty(jeab_purchase_get(70)['fullmakt_signed']), 'ordern hittad via token markeras signerad');

    // 3. Orderns egen länk räcker när fullmakt_token saknas på ordern.
    $linked = fullmakt_create_doc($base);
    $linkedToken = (string) $linked['token'];
    fso_put(71, ['fullmakt_token' => '', 'fullmakt_url' => 'https://flowstack.example.test/fullmakt/s/' . $linkedToken]);
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
    fso_sign($linkedToken);
    $posts = fso_of_type('fullmakt.signed');
    fso_assert(count($posts) === 1 && ($posts[0]['order_id'] ?? '') === '71', 'orderns fullmaktslänk ger order_id');

    // 4. Omstart av en äldre väntande fullmakt skriver in order_id.
    $old = fullmakt_create_doc($base);
    $oldToken = (string) $old['token'];
    $oldRow = fso_put(72, ['fullmakt_token' => $oldToken, 'fullmakt_channel' => 'bokmalen']);
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
    jeab_ellevio_fullmakt_resend($oldRow);
    fso_assert((string) (fullmakt_load_doc($oldToken)['order_id'] ?? '') === '72', 'omstart sparar order_id på äldre väntande fullmakt');
    fso_assert(($GLOBALS['_JEAB_BUG_WEBHOOKS'] ? fso_of_type('fullmakt.start')[0]['token'] ?? '' : '') === $oldToken, 'omstarten återanvänder samma token');

    // 5. Signerad post rörs inte av attach.
    $signedDoc = fullmakt_load_doc($token);
    fso_assert(jeab_ellevio_fullmakt_attach_order_id($signedDoc, ['reminder_id' => 999]) === $signedDoc, 'signerad fullmakt skrivs inte om');

    // 6. Går det inte att hitta: appfel, aldrig tyst.
    $orphan = fullmakt_create_doc($base);
    $orphanToken = (string) $orphan['token'];
    $before = fso_log();
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
    fso_sign($orphanToken);
    $posts = fso_of_type('fullmakt.signed');
    $added = substr(fso_log(), strlen($before));
    fso_assert(count($posts) === 1 && ($posts[0]['order_id'] ?? 'x') === '', 'utan order skickas signed med token men tomt order_id');
    fso_assert(str_contains($added, 'order_id saknas') && str_contains($added, 'fullmakt.signed'), 'tomt order_id loggas som appfel');
    fso_assert(!str_contains($added, 'fso-test-key') && !str_contains($added, $orphanToken), 'appfelet läcker varken nyckel eller token');

    // 7. Doc-id pekar på en order som redan har en annan fullmakt: ordern rörs inte.
    $other = fullmakt_create_doc($base + ['order_id' => '73']);
    fso_put(73, ['fullmakt_token' => 'annan-token']);
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
    fso_sign((string) $other['token']);
    $posts = fso_of_type('fullmakt.signed');
    fso_assert(($posts[0]['order_id'] ?? '') === '73', 'sparat order_id skickas ändå');
    fso_assert(empty(jeab_purchase_get(73)['fullmakt_signed']), 'order med annan fullmakt markeras inte signerad');

    // 8. Signeringssidans kontext (utan src/bootstrap.php) laddar appens orderlager.
    $child = $tmp . '/child.php';
    $root = var_export(dirname(__DIR__), true);
    $data = var_export($tmp, true);
    file_put_contents($child, <<<PHP
<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
\$GLOBALS['_JEAB_DATA_DIR'] = {$data};
require {$root} . '/src/shield.php';
require {$root} . '/fullmakt/lib/bootstrap.php';
require {$root} . '/fullmakt/lib/store.php';
echo (function_exists('jeab_sql_store_pdo') ? 'pre-db' : 'no-db'), "\\n";
fullmakt_load_bokmalen_ellevio_webhook();
echo (function_exists('jeab_config') ? 'config' : 'no-config'), "\\n";
echo (function_exists('jeab_sql_store_pdo') ? 'db' : 'no-db'), "\\n";
echo (function_exists('jeab_bokmalen_ellevio_fullmakt_notify') ? 'notify' : 'no-notify'), "\\n";
echo (function_exists('jeab_purchase_all') ? 'purchase' : 'no-purchase'), "\\n";
PHP);
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child) . ' 2>&1');
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $out))));
    fso_assert(($lines[0] ?? '') === 'no-db', 'signeringssidan startar utan appens orderlager');
    fso_assert(array_slice($lines, 1) === ['config', 'db', 'notify', 'purchase'], 'signeringen laddar appens orderlager, utan varningar: ' . trim((string) $out));
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmp);
}

echo "fullmakt signed order_id: ok\n";
