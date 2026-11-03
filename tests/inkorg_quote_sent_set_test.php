<?php

declare(strict_types=1);

/**
 * deep:1 och att öppna ett ärende ska slå upp skickad offert en gång per request,
 * nycklat på e-post. Inte en gång per tråd och inte genom att läsa alla brev.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-qset-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
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

function qset(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = jeab_pdo_connect_sqlite();
qset(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
qset($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');

$fat = str_repeat('Långt kundbrev. ', 60);
$emails = [];
$sent = [];
for ($i = 1; $i <= 12; $i++) {
    $email = 'kund' . $i . '@exempel.test';
    $emails[] = $email;
    $id = sprintf('qs%02d', $i);
    $store->upsertMessage($id . 'in', [
        'id' => $id . 'in',
        'thread_id' => $id,
        'from' => $email,
        'from_name' => 'Kund ' . $i,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Fråga ' . $i,
        'body' => $fat . ' Jag vill ha batteri.',
        'received_at' => date('c', time() - 86400),
        'source' => 'graph-jarfalla',
        'labels' => ['type' => 'Ny lead', 'offer' => 'batteri', 'product' => 'batteri', 'confidence' => 90, 'source' => 'rule'],
    ]);
    $store->upsertThread($id, [
        'status' => 'open',
        'tags' => ['batteri'],
        'assignee' => '',
        'read' => [],
        'company' => 'jarfalla',
        'customer_email' => $email,
        'customer_name' => 'Kund ' . $i,
        'updated_at' => date('c', time() - 86400),
    ]);
    if ($i % 2 === 0) {
        $sent[$email] = true;
        $store->upsertMessage($id . 'out', [
            'id' => $id . 'out',
            'thread_id' => $id . 'q',
            'from' => 'kontakt@jarfalla.example.test',
            'from_name' => 'JEAB',
            'to' => $email,
            'subject' => 'Offert batteri',
            'body' => "Hej.\n[Bilaga: Offert.pdf]\n",
            'received_at' => date('c', time() - 3600),
            'source' => 'outbound-graph',
            'labels' => ['type' => 'Information'],
        ]);
        $store->upsertThread($id . 'q', [
            'status' => 'closed',
            'tags' => [],
            'assignee' => '',
            'read' => [],
            'company' => 'jarfalla',
            'customer_email' => $email,
            'customer_name' => 'Kund ' . $i,
            'updated_at' => date('c', time() - 3600),
        ]);
    }
}

$hsEmail = 'kund2@exempel.test';
$importId = 'hsi-' . substr(hash('sha256', 'qset-hs'), 0, 16);
qset(jeab_hs_import_save_conv([
    'id' => $importId,
    'messages' => [[
        'from' => $hsEmail,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Fråga',
        'body' => 'Hej',
        'received_at' => date('c'),
        'hs_thread_type' => 'reply',
    ]],
]), 'Help Scout-kopia sparas');
qset(jeab_hs_import_index_append([
    'id' => $importId,
    'from' => $hsEmail,
    'snippet' => 'Hej',
    'subject' => 'Fråga',
    'message_ids' => [],
]), 'Help Scout-indexraden skrivs');

$GLOBALS['_JEAB_QUOTE_INBOX_SQL'] = 0;
$GLOBALS['_JEAB_QUOTE_HS_SQL'] = 0;
$GLOBALS['_JEAB_INKORG_MESSAGES_ALL'] = 0;
$GLOBALS['_JEAB_HS_INDEX_FULL'] = 0;
jeab_auto_quote_sent_prime($emails);
qset((int) $GLOBALS['_JEAB_QUOTE_INBOX_SQL'] === 1, 'inkorgen slås upp en gång för alla adresser');
qset((int) $GLOBALS['_JEAB_QUOTE_HS_SQL'] === 1, 'Help Scout slås upp en gång för alla adresser');
qset((int) $GLOBALS['_JEAB_INKORG_MESSAGES_ALL'] === 0 && (int) $GLOBALS['_JEAB_HS_INDEX_FULL'] === 0, 'mängden läser inte alla brev eller hela arkivet');

foreach ($emails as $email) {
    $inbox = jeab_auto_quote_sent_inbox($email);
    $want = isset($sent[$email]);
    qset(!empty($inbox['hit']) === $want, $email . ' ' . ($want ? 'har' : 'saknar') . ' utgående offert');
    $local = jeab_auto_quote_sent_hs_local($email);
    qset(!empty($local['hit']) === ($email === $hsEmail), $email . ' Help Scout');
}
qset((int) $GLOBALS['_JEAB_QUOTE_INBOX_SQL'] === 1 && (int) $GLOBALS['_JEAB_QUOTE_HS_SQL'] === 1, 'uppslagen per tråd använder mängden');

$thread = [
    'id' => 'qs01',
    'company' => 'jarfalla',
    'customer_email' => 'kund1@exempel.test',
    'messages' => [[
        'from' => 'kund1@exempel.test',
        'from_name' => 'Kund 1',
        'body' => 'Jag vill ha batteri.',
        'source' => 'graph-jarfalla',
        'labels' => ['type' => 'Ny lead', 'offer' => 'batteri'],
    ]],
    'first' => [
        'from' => 'kund1@exempel.test',
        'body' => 'Jag vill ha batteri.',
        'source' => 'graph-jarfalla',
        'labels' => ['type' => 'Ny lead', 'offer' => 'batteri'],
    ],
    'drafts' => [],
];
qset(jeab_inkorg_quote_sent_elsewhere($thread) === false, 'öppning utan utgående offert är en träff i mängden');
$thread['customer_email'] = 'kund2@exempel.test';
$thread['messages'][0]['from'] = 'kund2@exempel.test';
$thread['first']['from'] = 'kund2@exempel.test';
qset(jeab_inkorg_quote_sent_elsewhere($thread) === true, 'öppning med utgående offert läser mängden');
qset((int) $GLOBALS['_JEAB_QUOTE_INBOX_SQL'] === 1 && (int) $GLOBALS['_JEAB_INKORG_MESSAGES_ALL'] === 0, 'öppningen gör ingen ny hel läsning');

$list = jeab_inkorg_threads_list_hook([
    'action' => 'inbox.threads',
    'mailbox' => 'jarfalla',
    'status' => 'open',
    'limit' => 20,
    'deep' => 1,
]);
qset(($list['ok'] ?? false) === true && (int) ($list['deep'] ?? 0) === 1, 'deep-listan svarar');
$byId = [];
foreach ((array) ($list['threads'] ?? []) as $row) {
    if (is_array($row)) {
        $byId[(string) ($row['thread_id'] ?? '')] = (string) ($row['quote_sent'] ?? '');
    }
}
qset(($byId['qs01'] ?? '') !== 'yes', 'deep utan utgående offert är inte yes (' . ($byId['qs01'] ?? '') . ')');
qset(($byId['qs02'] ?? '') === 'yes', 'deep med offert i ett annat ärende är yes');
qset((int) $GLOBALS['_JEAB_QUOTE_INBOX_SQL'] === 1 && (int) $GLOBALS['_JEAB_QUOTE_HS_SQL'] === 1, 'deep-listan gör inte en fråga per tråd');
qset((int) $GLOBALS['_JEAB_HS_INDEX_FULL'] === 0, 'deep-listan läser inte hela Help Scout-indexet');

echo "inkorg quote sent set: ok\n";
