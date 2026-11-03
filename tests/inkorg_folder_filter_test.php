<?php

declare(strict_types=1);

/**
 * Mappfiltret får inte slå upp kontakter och alla ordrar per rad.
 * 5000 informationsrader och 1000 ordrar ska vara under en sekund.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-folder-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require $root . '/src/bootstrap.php';
require_once $root . '/src/kontakt_store.php';

function ff(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla'];

$pdo = jeab_pdo_connect_sqlite();
ff(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan för inkorgen');
ff(jeab_settings_put($pdo, 'sqlite', 'kontakter_storage', 'sql'), 'sql-flaggan för kontakter');
unset($GLOBALS['_JEAB_KLASSNING_SQL']);
ff(jeab_kontakter_use_sql(), 'kontakterna är SQL så förhämtningen gör en fråga');

$purchases = [];
for ($i = 0; $i < 1000; $i++) {
    $purchases[(string) $i] = [
        'reminder_id' => $i + 1,
        'email' => 'order' . $i . '@orderkund.se',
        'install_date' => '',
    ];
}
$purchases['0']['email'] = 'kund0@brevlada.se';
$purchases['0']['install_date'] = '2024-05-01';
$purchases['1']['email'] = 'kund1@brevlada.se';
jeab_purchase_cache_replace($purchases);

$rows = [];
for ($i = 0; $i < 5000; $i++) {
    $email = 'kund' . $i . '@brevlada.se';
    $rows[] = [
        'id' => 't' . $i,
        'status' => 'open',
        'company' => 'jarfalla',
        'labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => 90],
        'tags' => [],
        'updated_at' => '2024-06-01T10:00:00+00:00',
        'last' => [
            'id' => 'm' . $i,
            'from' => $email,
            'from_name' => 'Kund Ett',
            'subject' => 'Hej',
            'received_at' => '2024-06-01T10:00:00+00:00',
            'labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => 90],
        ],
    ];
}
$rows[] = [
    'id' => 'news',
    'status' => 'open',
    'company' => 'jarfalla',
    'labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => 90],
    'tags' => [],
    'updated_at' => '2024-06-02T10:00:00+00:00',
    'last' => [
        'id' => 'mnews',
        'from' => 'info@nyhetsbrev.se',
        'from_name' => 'Nyhetsbrev',
        'subject' => 'Veckobrev',
        'received_at' => '2024-06-02T10:00:00+00:00',
        'labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => 90],
    ],
];

unset(
    $GLOBALS['_JEAB_PURCHASE_KIND_MAP'],
    $GLOBALS['_JEAB_KLASSNING_CONTACT_SQL'],
    $GLOBALS['_JEAB_KLASSNING_KNOWN'],
    $GLOBALS['_JEAB_TIMING']
);
$GLOBALS['_JEAB_CONTACT_EMAIL_FINDS'] = 0;
$GLOBALS['_JEAB_PURCHASE_KIND_BUILDS'] = 0;
$GLOBALS['_JEAB_INKORG_PART'] = '';
unset($GLOBALS['_JEAB_KLASSNING_MEMO'], $GLOBALS['_JEAB_KLASSNING_FACE']);
$t0 = microtime(true);
$entries = jeab_inkorg_view_entries($rows, ['folder' => 'inkorg', 'company' => 'jarfalla']);
$elapsed = microtime(true) - $t0;
ff($elapsed < 1, '5000 rader och 1000 ordrar filtreras på ' . round($elapsed, 3) . ' s');
ff((int) $GLOBALS['_JEAB_CONTACT_EMAIL_FINDS'] === 1, 'kontakterna slås upp en gång, fick ' . (int) $GLOBALS['_JEAB_CONTACT_EMAIL_FINDS']);
ff((int) $GLOBALS['_JEAB_PURCHASE_KIND_BUILDS'] === 1, 'orderkartan byggs en gång, fick ' . (int) $GLOBALS['_JEAB_PURCHASE_KIND_BUILDS']);
$span = (float) (($GLOBALS['_JEAB_TIMING']['folder_filter'] ?? -1));
ff($span >= 0 && $span < 1000, 'folder_filter finns i Server-Timing, ' . $span . ' ms');
$ids = [];
foreach ($entries as $entry) {
    $ids[(string) ($entry['id'] ?? '')] = true;
}
ff(isset($ids['t0']) && isset($ids['t1']), 'kända kunder stannar i Inkorgen');
ff(!isset($ids['news']), 'nyhetsbrevet lämnar Inkorgen');
ff(!jeab_inkorg_folder_thread($rows[0], 'information'), 'installationskunden är inte Information');
ff(jeab_inkorg_folder_thread($rows[5000], 'information'), 'nyhetsbrevet är Information');
ff(jeab_inkorg_purchase_customer_kind('kund0@brevlada.se') === 'support_installation', 'installationen vinner');
ff(jeab_inkorg_purchase_customer_kind('kund1@brevlada.se') === 'support_order', 'order utan datum är order');
ff((int) $GLOBALS['_JEAB_PURCHASE_KIND_BUILDS'] === 1, 'fler uppslag läser samma karta');

$store = jeab_inkorg_pdo_store();
ff($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');
$store->upsertMessage('h1m', [
    'id' => 'h1m',
    'thread_id' => 'h1',
    'from' => 'kund0@brevlada.se',
    'from_name' => 'Kund Ett',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Krok',
    'body' => 'Kort',
    'received_at' => '2024-08-01T10:00:00+00:00',
    'source' => 'graph-jarfalla',
    'labels' => ['type' => 'Support'],
]);
$store->upsertThread('h1', [
    'status' => 'open',
    'tags' => [],
    'assignee' => '',
    'company' => 'jarfalla',
    'updated_at' => '2024-08-01T10:00:00+00:00',
]);
$messagesBefore = (int) $pdo->query('SELECT COUNT(*) FROM inkorg_messages')->fetchColumn();
$threadsBefore = (int) $pdo->query('SELECT COUNT(*) FROM inkorg_threads')->fetchColumn();
jeab_settings_put($pdo, 'sqlite', 'folder_filter_sentinel', 'orörd');
$store->clearListStamp();
$store->countCachePut('keep-me', ['counts' => ['v-inkorg' => 3], 'at' => time()]);

$_SERVER['REQUEST_METHOD'] = 'POST';
unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_WEBHOOK_TOKEN']);
ff(!jeab_inkorg_list_rebuild_authorized(), 'utan token nekas ombyggnaden');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-list-rebuild']));
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-list-rebuild';
ff(jeab_inkorg_list_rebuild_authorized(), 'inkorgstoken får bygga om listan');

$agent = 'folder-scoped-token';
$GLOBALS['_JEAB_HOOK_SCOPED_TOKENS'] = [
    hash('sha256', $agent) => ['name' => 'folder', 'scopes' => ['inkorg.list_rebuild']],
    hash('sha256', 'folder-other') => ['name' => 'other', 'scopes' => ['inbox.status']],
];
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $agent;
ff(jeab_inkorg_list_rebuild_authorized(), 'scoped token för inkorg.list_rebuild godkänns');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer folder-other';
ff(!jeab_inkorg_list_rebuild_authorized(), 'annan scope nekas');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-list-rebuild';

$built = jeab_inkorg_list_rebuild_hook();
ff(($built['ok'] ?? false) === true && (int) ($built['locked'] ?? 1) === 0, 'kroken bygger om');
ff((string) ($built['stamp'] ?? '') === $store->listFingerprint(), 'kroken sätter stämpeln');
ff((int) $pdo->query('SELECT COUNT(*) FROM inkorg_messages')->fetchColumn() === $messagesBefore, 'kroken skriver inte mejl');
ff((int) $pdo->query('SELECT COUNT(*) FROM inkorg_threads')->fetchColumn() === $threadsBefore, 'kroken skriver inte trådar');
ff(jeab_settings_value($pdo, 'sqlite', 'folder_filter_sentinel') === 'orörd', 'kroken lämnar övriga inställningar');
ff($store->countCacheGet('keep-me', 60) !== null, 'kroken behåller räknarcachen');
ff((int) $pdo->query('SELECT COUNT(*) FROM inkorg_list')->fetchColumn() >= 1, 'kroken skriver listan');

$fast = (string) file_get_contents($root . '/src/inkorg_fast.php');
ff(str_contains($fast, 'ignore_user_abort(true)') && str_contains($fast, 'set_time_limit(300)'), 'ombyggnaden får 300 s och fortsätter om klienten släpper');
$hook = (string) file_get_contents($root . '/hook.php');
$rename = strpos($hook, "substr(\$jeabHookAction, 7)");
$action = strpos($hook, "\$jeabHookAction === 'inkorg.list_rebuild'");
ff($action !== false && $rename !== false && $action < $rename, 'inkorg.list_rebuild hanteras innan namnet skrivs om');

echo "OK  klart\n";
