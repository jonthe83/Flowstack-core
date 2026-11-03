<?php

declare(strict_types=1);

/** Service-taket när status är Help Scouts active, och inbox.threads filter=service. */

$tmp = sys_get_temp_dir() . '/jeab-svc-cap-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'people' => [
        ['id' => 'SamL', 'email' => 'sam.exempel@personal.test', 'name' => 'Sam L', 'company' => 'jarfalla'],
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function sc_ok(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function sc_import(string $n, string $company, string $status, string $received, string $type = 'Support'): string
{
    $tid = 'hsi-' . substr(hash('sha256', 'svc-live-' . $n), 0, 16);
    $conv = [
        'id' => $tid,
        'company' => $company,
        'status' => $status,
        'import_source' => 'helpscout_import',
        'messages' => [[
            'id' => substr(hash('sha256', 'sm-' . $n), 0, 16),
            'from' => 'kund.' . $n . '@exempel.test',
            'from_name' => 'Kund',
            'to' => $company === 'malmo' ? 'kontakt@malmo.example.test' : 'kontakt@jarfalla.example.test',
            'subject' => 'Arende ' . $n,
            'body' => 'Hej',
            'received_at' => $received,
            'source' => 'helpscout_import',
            'company' => $company,
            'labels' => ['type' => $type, 'action' => 'arkivera', 'priority' => 4],
            'internal_note' => false,
            'attachments' => [],
        ]],
        'meta' => [
            'status' => $status,
            'tags' => [],
            'assignee' => '',
            'read' => [],
            'import_source' => 'helpscout_import',
            'updated_at' => '2026-10-19T01:00:00+00:00',
            'created_at' => '2026-10-19T01:00:00+00:00',
        ],
    ];
    sc_ok(jeab_hs_import_save_conv($conv) && jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'import ' . $n . ' skrivs');

    return $tid;
}

for ($i = 0; $i < 30; $i++) {
    $day = (new DateTimeImmutable('2024-02-01T10:00:00+00:00'))->modify('+' . $i . ' days')->format('c');
    sc_import(sprintf('jf-active-%02d', $i), 'jarfalla', 'active', $day);
}
for ($i = 0; $i < 8; $i++) {
    $day = (new DateTimeImmutable('2024-03-01T10:00:00+00:00'))->modify('+' . $i . ' days')->format('c');
    sc_import(sprintf('mo-active-%02d', $i), 'malmo', 'active', $day);
}
sc_import('jf-closed', 'jarfalla', 'closed', '2026-01-01T10:00:00+00:00');
sc_import('jf-empty', 'jarfalla', '', '2023-01-01T10:00:00+00:00');

$native = [];
foreach (['support-1', 'support-2', 'support-3', 'lead-1'] as $i => $name) {
    $in = jeab_inkorg_ingest([
        'message_id' => '<' . $name . '@exempel.test>',
        'from' => $name . '@exempel.test',
        'from_name' => 'Kund',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Eget ' . $name,
        'body_text' => 'Hej',
        'source' => 'test-unit',
        'company' => 'jarfalla',
        'preset_labels' => ['type' => $name === 'lead-1' ? 'Ny lead' : 'Support'],
    ]);
    sc_ok(!empty($in['ok']), 'eget ärende ' . $name);
    $native[$name] = (string) $in['thread_id'];
}

$views = array_column(jeab_inkorg_views(), null, 'id');
$svc = (array) $views['v-servicekund']['filter'] + ['company' => 'jarfalla'];
$threads = jeab_inkorg_threads();
$total = jeab_inkorg_view_total($threads, $svc);
$list = jeab_inkorg_view_list($threads, $svc, 1, 500);
sc_ok($total === (int) $list['total'], 'Service-tal ' . $total . ' är listans total');
sc_ok($total <= 20 + 3, 'Service är högst 20 osäkra plus de egna öppna (' . $total . ')');
$ids = [];
foreach ($list['rows'] as $row) {
    if (is_array($row)) {
        $ids[] = (string) ($row['id'] ?? '');
    }
}
sc_ok(in_array($native['support-1'], $ids, true) && in_array($native['support-2'], $ids, true) && in_array($native['support-3'], $ids, true), 'egna öppna Support ligger i Service');
sc_ok(!in_array($native['lead-1'], $ids, true), 'Ny lead ligger inte i Service');
$closedId = 'hsi-' . substr(hash('sha256', 'svc-live-jf-closed'), 0, 16);
sc_ok(!in_array($closedId, $ids, true), 'stängd import ligger inte i Service');

$malmo = (array) $views['v-servicekund']['filter'] + ['company' => 'malmo'];
$malmoTotal = jeab_inkorg_view_total($threads, $malmo);
$malmoList = jeab_inkorg_view_list($threads, $malmo, 1, 500);
sc_ok($malmoTotal === (int) $malmoList['total'] && $malmoTotal <= 20, 'Malmö Service är högst 20 och talet är listan (' . $malmoTotal . ')');

// Help Scout-arkivet öppnar sqlite utan appens schema. Lagret återanvänder
// den anslutningen, så reminders måste finnas innan inbox.threads läser den.
$schemaStore = jeab_store();
if ($schemaStore instanceof JeabPdoStore) {
    $schemaPdo = $schemaStore->pdo();
    $remindersReady = false;
    try {
        $schemaPdo->query('SELECT 1 FROM reminders LIMIT 1');
        $remindersReady = true;
    } catch (Throwable) {
        $remindersReady = false;
    }
    if (!$remindersReady && function_exists('jeab_migrate_sqlite')) {
        jeab_migrate_sqlite($schemaPdo);
    }
}

$all = jeab_inkorg_hook(['action' => 'inbox.threads', 'mailbox' => 'jarfalla', 'status' => 'all', 'limit' => 50]);
$service = jeab_inkorg_hook(['action' => 'inbox.threads', 'mailbox' => 'jarfalla', 'status' => 'all', 'limit' => 50, 'filter' => 'service']);
$allIds = array_column((array) ($all['threads'] ?? []), 'thread_id');
$serviceIds = array_column((array) ($service['threads'] ?? []), 'thread_id');
sc_ok(($all['ok'] ?? false) === true && ($service['ok'] ?? false) === true && (int) $service['total'] < (int) $all['total'], 'filter service är färre än ofiltrerat');
sc_ok(in_array($native['support-1'], $serviceIds, true) && !in_array($native['lead-1'], $serviceIds, true), 'filter service behåller Support och släpper Ny lead');
sc_ok(in_array($native['lead-1'], $allIds, true), 'ofiltrerat har Ny lead');
$bad = jeab_inkorg_hook(['action' => 'inbox.threads', 'mailbox' => 'jarfalla', 'filter' => 'allting']);
sc_ok(($bad['ok'] ?? true) === false, 'okänt filter avvisas');
$json = (string) json_encode($service, JSON_UNESCAPED_UNICODE);
sc_ok(!str_contains($json, '@') && !str_contains($json, 'exempel.test'), 'listsvaret har ingen adress');

echo "inkorg service cap: ok\n";
