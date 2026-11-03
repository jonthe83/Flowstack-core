<?php

declare(strict_types=1);

/**
 * Vunnen påminnelse kör Lantmäteriet-uppslaget direkt.
 * Personnummer finns på huvudsökandens ägarkort. Hooken flyttar gamla övre fältet dit.
 * Inget Fortnox-anrop. Inget personnummer i hook-svar eller fellogg.
 */

$tmp = sys_get_temp_dir() . '/jeab-pnr-owner-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
$_GET['co'] = 'jarfalla';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function po_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function po_digits(): string
{
    return '198001011234';
}

function po_norm(): string
{
    return '19800101-1234';
}

$fortnoxWrites = 0;
$GLOBALS['JEAB_FORTNOX_WRITES'] = 0;
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function () use (&$fortnoxWrites): array {
    $fortnoxWrites++;
    $GLOBALS['JEAB_FORTNOX_WRITES'] = $fortnoxWrites;
    return [];
};
$GLOBALS['_JEAB_FORTNOX_CREATE'] = static function (array $row) use (&$fortnoxWrites): array {
    $fortnoxWrites++;
    $GLOBALS['JEAB_FORTNOX_WRITES'] = $fortnoxWrites;
    return $row;
};

$rotDir = $tmp . '/rot';
@mkdir($rotDir, 0775, true);
$digits = po_digits();
file_put_contents($rotDir . '/data_scraping.php', <<<PHP
<?php
class LantmaterietAPI {
    public \$data = [];
    public function search(\$addressText, \$uuid = '') {
        \$GLOBALS['PO_LM_CALLS'][] = (string) \$addressText;
        if (stripos((string) \$addressText, 'Felgatan') !== false) {
            throw new RuntimeException('träff {$digits}');
        }
        if (stripos((string) \$addressText, 'Exempelvägen') === false) {
            return false;
        }
        \$this->data = [
            'fastighetsbeteckning' => 'Testby 1:2',
            'matchedAddress' => 'Exempelvägen 1, 176 74 Järfälla',
            'postnummer' => '17674',
            'postort' => 'Järfälla',
            'owners' => [
                ['name' => 'Ada Exempel', 'personnummer' => '{$digits}', 'address' => 'Exempelvägen 1, 176 74 Järfälla', 'andel' => '1/1'],
            ],
        ];
        return true;
    }
}
PHP);
$GLOBALS['_JEAB_ROT_DATA_SCRAPING_CANDIDATES'] = [$rotDir . '/data_scraping.php'];
$GLOBALS['PO_LM_CALLS'] = [];
jeab_ordrar_external_live(true);

$path = $tmp . '/store.json';
$store = new JeabJsonStore($path);
$GLOBALS['_JEAB_ACTIVE_STORE'] = $store;

$lead = jeab_create_reminder($store, 'Ada Exempel', '2026-10-20 00:00:00', null, null, [
    'customer' => 'Ada Exempel',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Exempelvägen 1, Järfälla',
]);
$leadId = (int) ($lead['id'] ?? 0);
po_assert($leadId > 0, 'påminnelsen skapades');
$created = jeab_purchase_start($store, $leadId, '');
po_assert(is_array($created) && (int) ($created['reminder_id'] ?? 0) === $leadId, 'ordern skapas även om uppslaget tar tid');
po_assert($GLOBALS['PO_LM_CALLS'] !== [], 'ordrar.lm_lookup körs när ordern skapas');
po_assert(($created['fastighetsbeteckning'] ?? '') === 'TESTBY 1:2', 'fastighetsbeteckning fylls från uppslaget');
po_assert(($created['postnummer'] ?? '') === '17674', 'postnummer fylls från uppslaget');
$owners = jeab_ordrar_people_list($created['owners'] ?? [], 4);
po_assert(($owners[0]['name'] ?? '') === 'Ada Exempel', 'ägaren fylls från uppslaget');
po_assert(jeab_ordrar_canonical_personnummer($created) === po_norm(), 'huvudsökandens personnummer är källan');
po_assert(($created['personnummer'] ?? '') === po_norm(), 'Fortnox-fältet speglar samma personnummer');
po_assert(!in_array('personnummer', jeab_ordrar_fortnox_missing($created), true), 'Fortnox ser huvudsökandens personnummer');
po_assert($fortnoxWrites === 0 && (int) $GLOBALS['JEAB_FORTNOX_WRITES'] === 0, 'uppslaget skriver inte till Fortnox');

$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
po_assert(str_contains($js, 'Hämtar fastighet och ägare från Lantmäteriet…'), 'snurran säger vad som hämtas');

$html = jeab_ordrar_v3_html($created, 'jarfalla', $leadId, $store, null, null, $lead);
po_assert(!str_contains($html, 'name="objekt_personnummer"'), 'v3-objekt har inget extra Personnr');
po_assert(str_contains($html, 'Personnummer:'), 'personnummer står i ägarkortet');
po_assert(substr_count($html, 'name="objekt_personnummer"') === 0, 'Bekräfta läser inte ett andra personnummerfält');

$onlyOwner = $created;
$onlyOwner['personnummer'] = '';
po_assert(jeab_ordrar_canonical_personnummer($onlyOwner) === po_norm(), 'ägarkortet räcker när det gamla fältet är tomt');
po_assert(!in_array('personnummer', jeab_ordrar_fortnox_missing($onlyOwner), true), 'Fortnox läser ägarkortet');

$failLead = jeab_create_reminder($store, 'Bo Exempel', '2026-10-20 00:00:00', null, null, [
    'customer' => 'Bo Exempel',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Felgatan 2, Järfälla',
]);
$failId = (int) ($failLead['id'] ?? 0);
$failed = jeab_purchase_start($store, $failId, '');
po_assert(is_array($failed) && (int) ($failed['reminder_id'] ?? 0) === $failId, 'fel i uppslaget blockerar inte ordern');
$note = (string) ($failed['lm_lookup_error'] ?? '');
po_assert(str_contains($note, 'Fyll i fastighet, ägare och personnummer för hand'), 'feltexten ber om manuell ifyllnad');
po_assert(!str_contains($note, po_digits()) && !str_contains($note, po_norm()), 'feltexten innehåller inte personnummer');
$log = is_file($tmp . '/app-errors.jsonl') ? (string) file_get_contents($tmp . '/app-errors.jsonl') : '';
po_assert($log === '' || (!str_contains($log, po_digits()) && !str_contains($log, po_norm())), 'felloggen innehåller inte personnummer');
$failHtml = jeab_ordrar_v3_html($failed, 'jarfalla', $failId, $store);
po_assert(str_contains($failHtml, 'data-v3-lm') && str_contains($failHtml, 'för hand'), 'vyn visar att manuell ifyllnad behövs');
po_assert(!str_contains($failHtml, po_digits()), 'vyn visar inte personnumret i felrutan');

$moveId = 880441;
$keepId = 880442;
$emptyId = 880443;
jeab_purchase_put([
    'reminder_id' => $moveId,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'name' => 'Caj Exempel',
    'huvudsokande' => 'Caj Exempel',
    'personnummer' => po_norm(),
    'owners' => [['name' => 'Caj Exempel', 'personnummer' => '', 'address' => 'Exempelvägen 1']],
    'steps' => [],
]);
jeab_purchase_put([
    'reminder_id' => $keepId,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'name' => 'Disa Exempel',
    'huvudsokande' => 'Disa Exempel',
    'personnummer' => po_norm(),
    'owners' => [['name' => 'Disa Exempel', 'personnummer' => po_norm(), 'address' => 'Exempelvägen 1']],
    'steps' => [],
]);
jeab_purchase_put([
    'reminder_id' => $emptyId,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'name' => 'Eve Exempel',
    'huvudsokande' => 'Eve Exempel',
    'personnummer' => '',
    'owners' => [['name' => 'Eve Exempel', 'personnummer' => '', 'address' => 'Exempelvägen 1']],
    'steps' => [],
]);

$dry = jeab_ordrar_pnr_owner_backfill($store, ['dry' => 1]);
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
po_assert(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1, 'dry=1 är standard');
po_assert(($dry['order_ids'] ?? []) === [$moveId], 'dry=1 listar bara ordern där ägarkortet saknar personnummer');
po_assert(is_string($dryJson) && !str_contains($dryJson, po_digits()) && !str_contains($dryJson, po_norm()), 'dry=1-svaret innehåller inte personnummer');
po_assert((jeab_purchase_get($moveId)['owners'][0]['personnummer'] ?? 'x') === '', 'dry=1 skriver inget');

$denied = jeab_ordrar_pnr_owner_backfill($store, ['dry' => 0]);
po_assert(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 422, 'dry=0 utan confirm nekas');
po_assert((jeab_purchase_get($moveId)['owners'][0]['personnummer'] ?? 'x') === '', 'nekad körning skriver inget');

$live = jeab_ordrar_pnr_owner_backfill($store, ['dry' => 0, 'confirm' => 'SAM']);
po_assert(($live['ok'] ?? false) === true && ($live['count'] ?? 0) === 1 && ($live['order_ids'] ?? []) === [$moveId], 'dry=0 med confirm flyttar en order');
$moved = jeab_purchase_get($moveId);
po_assert(is_array($moved) && ($moved['owners'][0]['personnummer'] ?? '') === po_norm(), 'personnumret ligger på huvudsökanden');
po_assert(($moved['personnummer'] ?? '') === po_norm(), 'samma värde ligger kvar för Fortnox');
$kept = jeab_purchase_get($keepId);
po_assert(is_array($kept) && ($kept['owners'][0]['personnummer'] ?? '') === po_norm(), 'ifyllt ägarkort rörs inte');
po_assert(jeab_ordrar_canonical_personnummer((array) $moved) === po_norm(), 'Bekräfta och Fortnox läser ägarkortet efter flytten');
po_assert($fortnoxWrites === 0, 'backfillen skriver inte Fortnox');

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
po_assert(str_contains($hookSrc, "=== 'ordrar.pnr_owner_backfill'"), 'hook.php tar ordrar.pnr_owner_backfill');

$inboxToken = 'ink_' . bin2hex(random_bytes(8));
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => $inboxToken]));
$httpRoot = $tmp . '/http';
$httpEmpty = $httpRoot . '/empty';
mkdir($httpEmpty, 0775, true);
$router = $httpRoot . '/router.php';
$logFile = $httpRoot . '/php.log';
file_put_contents($router, <<<'PHP'
<?php
$data = getenv('JEAB_PO_DATA');
$root = getenv('JEAB_PO_ROOT');
if (!is_string($data) || $data === '' || !is_string($root) || $root === '') {
    http_response_code(500);
    echo 'missing';
    return true;
}
$GLOBALS['_JEAB_DATA_DIR'] = $data;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $data;
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path === '/hook.php') {
    require $root . '/hook.php';
    return true;
}
http_response_code(404);
echo 'no';
return true;
PHP);
$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
po_assert($probe !== false, 'port vald');
$addr = stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr((string) strrchr((string) $addr, ':'), 1);
$cmd = 'JEAB_DISABLE_SQLITE=1 JEAB_PO_DATA=' . escapeshellarg($tmp)
    . ' JEAB_PO_ROOT=' . escapeshellarg(dirname(__DIR__))
    . ' ' . escapeshellarg(PHP_BINARY)
    . ' -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($logFile)
    . ' -S 127.0.0.1:' . $port
    . ' -t ' . escapeshellarg($httpEmpty)
    . ' ' . escapeshellarg($router);
require_once __DIR__ . '/jeab_test_proc_env.php';
jeab_test_router_boot($router);
$proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']], $pipes);
po_assert(is_resource($proc), 'lokal PHP-server startade');
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $eNo, $eStr, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(100000);
}
po_assert($up, 'testservern lyssnar');

$base = 'http://127.0.0.1:' . $port . '/hook.php';
$http = static function (array $payload, bool $auth) use ($base, $inboxToken): array {
    $headers = ['Content-Type: application/json'];
    if ($auth) {
        $headers[] = 'Authorization: Bearer ' . $inboxToken;
    }
    $ch = curl_init($base);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $text = is_string($raw) ? $raw : '';
    $json = json_decode($text, true);
    return ['status' => $status, 'json' => is_array($json) ? $json : [], 'body' => $text];
};

$noAuth = $http(['action' => 'ordrar.pnr_owner_backfill', 'dry' => 1], false);
po_assert($noAuth['status'] === 401, 'hooken kräver token');
$again = $http(['action' => 'ordrar.pnr_owner_backfill'], true);
po_assert($again['status'] === 200 && ($again['json']['dry'] ?? 0) === 1 && ($again['json']['count'] ?? -1) === 0, 'curl dry=1 efter flytten har noll kvar');
po_assert(!str_contains($again['body'], po_digits()) && !str_contains($again['body'], po_norm()), 'curl-svaret innehåller inte personnummer');
$refused = $http(['action' => 'ordrar.pnr_owner_backfill', 'dry' => 0, 'confirm' => 'nej'], true);
po_assert($refused['status'] === 422 && ($refused['json']['ok'] ?? true) === false, 'curl dry=0 utan rätt confirm nekas');
po_assert($fortnoxWrites === 0, 'curl skriver inte Fortnox');

echo "OK  pnr-ägare\n";
