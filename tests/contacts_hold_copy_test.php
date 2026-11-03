<?php

declare(strict_types=1);

/** Hold på: länkade trådar får ingen kundkopia från HS-återöppning, Omtago eller lead-backfill. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-hold-copy-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp . '/fellogg';
mkdir($GLOBALS['_JEAB_FELLOGG_DIR'], 0775, true);
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
unset($_ENV['XAI_API_KEY'], $_SERVER['XAI_API_KEY']);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function hc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function hc_hold(bool $on): void
{
    $GLOBALS['_JEAB_CONTACTS_SOLE'] = $on;
    $GLOBALS['_JEAB_CONTACTS_STRICT'] = $on;
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $args): array {
    $GLOBALS['_SENT'][] = $args;

    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['_JEAB_KLASSNING_NO_SEND'] = 1;

hc_hold(false);
hc(!jeab_contacts_inbox_hold(), 'hold är av');
hc_hold(true);
hc(jeab_contacts_inbox_hold(), 'hold är på');

function hc_archive(string $tid, string $email, string $name, string $contactId): void
{
    $messageId = substr(hash('sha256', 'msg-' . $tid), 0, 16);
    $conv = [
        'id' => $tid,
        'company' => 'jarfalla',
        'status' => 'closed',
        'import_source' => 'helpscout_import',
        'contact_id' => $contactId,
        'messages' => [[
            'id' => $messageId,
            'from' => $email,
            'from_name' => $name,
            'subject' => 'Exempelärende',
            'body' => 'Historik utan person.',
            'received_at' => '2026-01-02T10:00:00+00:00',
            'source' => 'helpscout_import',
            'labels' => ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4],
        ]],
        'meta' => ['status' => 'closed', 'import_source' => 'helpscout_import', 'contact_id' => $contactId],
    ];
    hc(jeab_hs_import_save_conv($conv), 'arkivkonversationen sparas');
    hc(jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'arkivindexet sparas');
    unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);
}

function hc_reopen(string $tid): string
{
    $opened = jeab_inkorg_hook([
        'action' => 'inbox.reopen',
        'thread_id' => $tid,
        'assignee' => 'Roberto',
        'type' => 'service',
        'dry' => '0',
        'confirm' => 'SAM',
    ]);
    hc(($opened['ok'] ?? false) === true, 'historiken öppnas, fick ' . (string) ($opened['error'] ?? $opened['reason'] ?? ''));

    return (string) ($opened['live_thread_id'] ?? '');
}

hc_hold(true);
$heldArchive = 'hsi-' . substr(hash('sha256', 'hold-hsi'), 0, 16);
hc_archive($heldArchive, 'ada.hold@example.test', 'Ada Hold', 'kt-hold');
$heldLive = hc_reopen($heldArchive);
$heldMeta = jeab_inkorg_thread_meta($heldLive);
hc((string) ($heldMeta['contact_id'] ?? '') === 'kt-hold', 'HS-import sätter contact_id');
hc(trim((string) ($heldMeta['customer_email'] ?? '')) === '' && trim((string) ($heldMeta['customer_name'] ?? '')) === '', 'HS-import skriver ingen kopia när hold är på');

hc_hold(false);
$openArchive = 'hsi-' . substr(hash('sha256', 'open-hsi'), 0, 16);
hc_archive($openArchive, 'ada.open@example.test', 'Ada Open', 'kt-open');
$openLive = hc_reopen($openArchive);
$openMeta = jeab_inkorg_thread_meta($openLive);
hc((string) ($openMeta['contact_id'] ?? '') === 'kt-open', 'HS-import sätter contact_id utan hold');
hc((string) ($openMeta['customer_email'] ?? '') === 'ada.open@example.test' && (string) ($openMeta['customer_name'] ?? '') === 'Ada Open', 'HS-import skriver kopian när hold är av');

hc_hold(true);
jeab_inkorg_thread_write('omt-hold', static function (array $t): array {
    $t['status'] = 'open';
    $t['company'] = 'jarfalla';
    $t['brand'] = 'jarfalla';
    $t['contact_id'] = 'kt-omt';
    $t['customer_email'] = '';
    $t['customer_name'] = '';
    $t['created_at'] = date('c');

    return $t;
});
jeab_omtago_follow('', 'omt-hold', [
    'email' => 'nora.hold@example.test',
    'name' => 'Nora Hold',
    'phone' => '0700000091',
]);
$omtHeld = jeab_inkorg_thread_meta('omt-hold');
hc((string) ($omtHeld['lead_source'] ?? '') === 'omtago', 'Omtago sätter källan');
hc((string) ($omtHeld['contact_id'] ?? '') === 'kt-omt' && trim((string) ($omtHeld['customer_email'] ?? '')) === '' && trim((string) ($omtHeld['customer_name'] ?? '')) === '', 'Omtago skriver ingen kopia på länkad tråd när hold är på');

hc_hold(false);
jeab_inkorg_thread_write('omt-open', static function (array $t): array {
    $t['status'] = 'open';
    $t['company'] = 'jarfalla';
    $t['brand'] = 'jarfalla';
    $t['contact_id'] = 'kt-omt-open';
    $t['customer_email'] = '';
    $t['customer_name'] = '';
    $t['created_at'] = date('c');

    return $t;
});
jeab_omtago_follow('', 'omt-open', [
    'email' => 'nora.open@example.test',
    'name' => 'Nora Open',
    'phone' => '0700000092',
]);
$omtOpen = jeab_inkorg_thread_meta('omt-open');
hc((string) ($omtOpen['customer_email'] ?? '') === 'nora.open@example.test' && (string) ($omtOpen['customer_name'] ?? '') === 'Nora Open', 'Omtago skriver kopian när hold är av');

$leadSrc = file_get_contents(dirname(__DIR__) . '/src/lead_source.php');
hc(is_string($leadSrc) && str_contains($leadSrc, "require_once __DIR__ . '/contacts_strict.php';"), 'lead_source läser in contacts_strict');
hc(is_string($leadSrc) && str_contains($leadSrc, 'jeab_contacts_inbox_strip_flags_on()'), 'lead-backfill använder samma hold som övriga vägar');
hc(is_string($leadSrc) && !str_contains($leadSrc, "function_exists('jeab_contacts_inbox_hold')"), 'lead-backfill släpper inte igenom om funktionen saknas');
hc(function_exists('jeab_contacts_inbox_strip_flags_on'), 'hold-funktionen finns efter inläsning');

hc_hold(false);
$box = function_exists('jeab_graph_mailboxes') ? (string) (jeab_graph_mailboxes()['jarfalla'] ?? 'kontakt@jarfalla.example.test') : 'kontakt@jarfalla.example.test';
$ingested = jeab_inkorg_ingest([
    'message_id' => 'hold-backfill@lead.test',
    'from' => 'hej@nordsolen.se',
    'from_name' => 'Nordsolen',
    'to' => $box,
    'subject' => 'Ny lead',
    'body_text' => "Kund: Nora Backfill · nora.backfill@example.test · 070-000 00 93\n",
    'source' => 'graph',
    'thread_key' => 'hold-backfill',
    'company' => 'jarfalla',
    'no_auto' => true,
    'preset_labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'confidence' => 100],
]);
hc(!empty($ingested['ok']), 'backfill-tråden tas emot');
$backfillId = (string) ($ingested['thread_id'] ?? 'hold-backfill');
jeab_inkorg_thread_write($backfillId, static function (array $t): array {
    $t['contact_id'] = 'kt-backfill';
    $t['lead_source'] = 'nordsolen';
    $t['customer_email'] = '';
    $t['customer_name'] = '';
    $t['customer_phone'] = '';
    $t['customer_source'] = '';

    return $t;
});
hc_hold(true);
$sentBefore = count($GLOBALS['_SENT']);
$backfillHeld = jeab_lead_recipients_hook(['dry' => 0]);
$backfillMeta = jeab_inkorg_thread_meta($backfillId);
hc(!empty($backfillHeld['ok']) && (int) ($backfillHeld['dry'] ?? 1) === 0, 'lead-backfill körs');
hc((string) ($backfillMeta['contact_id'] ?? '') === 'kt-backfill' && (string) ($backfillMeta['lead_source'] ?? '') === 'nordsolen', 'lead-backfill behåller länk och källa');
hc(trim((string) ($backfillMeta['customer_email'] ?? '')) === '' && trim((string) ($backfillMeta['customer_name'] ?? '')) === '', 'lead-backfill skriver ingen kopia när hold är på');
hc(count($GLOBALS['_SENT']) === $sentBefore, 'lead-backfill skickar inget');

hc_hold(false);
$backfillOpen = jeab_lead_recipients_hook(['dry' => 0]);
$backfillOpenMeta = jeab_inkorg_thread_meta($backfillId);
hc(!empty($backfillOpen['ok']) && (string) ($backfillOpenMeta['customer_email'] ?? '') === 'nora.backfill@example.test', 'lead-backfill skriver kopian när hold är av');
hc(count($GLOBALS['_SENT']) === $sentBefore, 'lead-backfill med hold av skickar inget');

$made = [];
for ($i = 1; $i <= 3; $i++) {
    $row = jeab_kontakt_find_or_create([
        'name' => 'Index ' . $i,
        'email' => 'index' . $i . '@example.test',
        'company' => 'jarfalla',
    ], false);
    hc(is_array($row) && trim((string) ($row['id'] ?? '')) !== '', 'indexkontakt ' . $i);
    $tid = 'idx-' . $i;
    $made[] = [(string) $row['id'], 'index' . $i . '@example.test', $tid];
    $taken = jeab_inkorg_ingest([
        'message_id' => 'idx-' . $i . '@lead.test',
        'from' => 'hej@nordsolen.se',
        'from_name' => 'Nordsolen',
        'to' => $box,
        'subject' => 'Index ' . $i,
        'body_text' => 'Indexrad ' . $i,
        'source' => 'graph',
        'thread_key' => $tid,
        'company' => 'jarfalla',
        'no_auto' => true,
    ]);
    hc(!empty($taken['ok']), 'indextråd ' . $i . ' tas emot');
    $storedId = (string) ($taken['thread_id'] ?? $tid);
    $made[$i - 1][2] = $storedId;
    jeab_inkorg_thread_write($storedId, static function (array $t) use ($row): array {
        $t['contact_id'] = (string) $row['id'];
        $t['customer_email'] = '';
        $t['customer_name'] = '';

        return $t;
    });
}
$queriesBefore = (int) ($GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] ?? 0);
$indexed = jeab_name_address_index();
$queries = (int) ($GLOBALS['_JEAB_CONTACTS_SOLE_ID_QUERIES'] ?? 0) - $queriesBefore;
$byEmail = (array) ($indexed['threads_by_email'] ?? []);
$indexedAll = true;
foreach ($made as [$id, $email, $tid]) {
    $hits = array_map('strval', (array) ($byEmail[$email] ?? []));
    if (!in_array($tid, $hits, true)) {
        $indexedAll = false;
    }
}
hc($queries <= 2, 'namnindex hämtar kontakterna i en batch, fick ' . $queries . ' frågor');
hc($indexedAll, 'namnindex hittar tråden via kontaktens mejl');

echo "contacts_hold_copy_test klar\n";
