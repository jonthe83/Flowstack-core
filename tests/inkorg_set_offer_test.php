<?php

declare(strict_types=1);

/** inbox.set_offer sätter produkt manuellt. dry ändrar inget. Omklassningen rör inte markeringen. */

$tmp = sys_get_temp_dir() . '/jeab-set-offer-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function so(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$store = jeab_store();
so($store instanceof JeabPdoStore, 'sqlite är uppe');
$preset = ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 40, 'intent' => 'information'];
$calls = 0;
$graphs = 0;
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function () use (&$calls): array {
    $calls++;
    return ['ok' => true, 'path' => 'test', 'id' => 'x'];
};
$GLOBALS['JEAB_GRAPH_SEND'] = static function () use (&$graphs): array {
    $graphs++;
    return ['ok' => true];
};

$take = static function (string $id, string $from, string $body, array $extra = []) use ($preset): array {
    return jeab_inkorg_ingest($extra + [
        'message_id' => $id,
        'from' => $from,
        'from_name' => 'Kund',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Förfrågan',
        'body_text' => $body,
        'received_at' => date('c'),
        'source' => 'test-set-offer',
        'preset_labels' => $preset,
    ]);
};
$manual = $take('manual@block.test', 'manual@brevlada.se', 'Vi vill ha solceller med batteri. Kan vi få pris?');
$open = $take('open@block.test', 'open@brevlada.se', 'Vi vill ha solceller med batteri. Kan vi få pris?');
$malmo = $take('malmo@block.test', 'malmo@brevlada.se', 'Kan jag få pris på ett batteri?', ['source' => 'graph-malmo', 'to' => 'kontakt@malmo.example.test']);
so(!empty($manual['ok']) && !empty($open['ok']) && !empty($malmo['ok']), 'tre ärenden intagna');
$tid = (string) $manual['thread_id'];
$mid = (string) $manual['id'];
$beforeLabels = jeab_inkorg_get($mid)['labels'] ?? [];
$auditBefore = (int) $store->pdo()->query('SELECT COUNT(*) FROM inbox_reclassify_audit')->fetchColumn();
$remindersBefore = count($store->listReminders(true));

$dry = jeab_inkorg_hook([
    'action' => 'inbox.set_offer',
    'thread_id' => $tid,
    'offer' => 'batteri',
    'reason' => 'fel produkt',
]);
$afterDry = jeab_inkorg_get($mid);
so(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1, 'dry är standard');
so(($dry['would_quote'] ?? true) === false && ($dry['would_send'] ?? true) === false && ($dry['would_remind'] ?? true) === false, 'dry-svaret skickar inte');
so(($dry['before']['offer'] ?? 'x') === (string) ($beforeLabels['offer'] ?? '') && ($dry['after']['offer'] ?? '') === 'batteri' && ($dry['after']['product'] ?? '') === 'batteri' && ($dry['after']['primary_product'] ?? '') === 'Batteri', 'dry visar före och efter');
so((string) ($afterDry['labels']['type'] ?? '') === (string) ($beforeLabels['type'] ?? '') && (string) ($afterDry['labels']['offer'] ?? '') === (string) ($beforeLabels['offer'] ?? '') && empty($afterDry['labels']['offer_manual']), 'dry ändrar inget');
so((int) $store->pdo()->query('SELECT COUNT(*) FROM inbox_reclassify_audit')->fetchColumn() === $auditBefore, 'dry skriver ingen audit');
so(str_contains(json_encode($dry, JSON_UNESCAPED_UNICODE) ?: '', '@') === false, 'dry-svaret har ingen e-post');

$bad = jeab_inkorg_hook(['action' => 'inbox.set_offer', 'thread_id' => $tid, 'offer' => 'tak', 'reason' => 'nej']);
so(($bad['ok'] ?? true) === false && empty(jeab_inkorg_get($mid)['labels']['offer_manual']), 'ogiltig produkt skrivs inte');

$apply = jeab_inkorg_hook([
    'action' => 'inbox.set_offer',
    'thread_id' => $tid,
    'offer' => 'batteri',
    'reason' => 'fel produkt',
    'dry' => 0,
]);
$saved = jeab_inkorg_get($mid);
$labels = (array) ($saved['labels'] ?? []);
$override = $store->pdo()->query('SELECT offer, primary_product, type, reason, manual, "by" AS actor FROM inbox_thread_override WHERE thread_id = ' . $store->pdo()->quote($tid))->fetch(PDO::FETCH_ASSOC);
so(($apply['ok'] ?? false) === true && ($apply['dry'] ?? 1) === 0 && ($apply['audited'] ?? 0) === 1, 'dry=0 skriver och loggar');
so(($apply['would_quote'] ?? true) === false && ($apply['would_send'] ?? true) === false && ($apply['would_remind'] ?? true) === false, 'dry=0 skickar inte');
so(is_array($override) && (string) ($override['offer'] ?? '') === 'batteri' && (string) ($override['primary_product'] ?? '') === 'Batteri' && (string) ($override['type'] ?? '') === 'Ny lead' && (string) ($override['reason'] ?? '') === 'fel produkt' && (int) ($override['manual'] ?? 0) === 1 && (string) ($override['actor'] ?? '') === 'inbox.set_offer', 'dry=0 skriver SQL');
so((string) ($labels['type'] ?? '') === (string) ($beforeLabels['type'] ?? '') && (string) ($labels['offer'] ?? '') === (string) ($beforeLabels['offer'] ?? '') && empty($labels['offer_manual']), 'mejlets JSON är oförändrat');
$audit = $store->pdo()->query('SELECT offer, new_type, dry FROM inbox_reclassify_audit ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
so(is_array($audit) && (string) ($audit['offer'] ?? '') === 'batteri' && (string) ($audit['new_type'] ?? '') === 'Ny lead' && (int) ($audit['dry'] ?? 1) === 0, 'audit har batteri');

$re = jeab_inkorg_hook(['action' => 'inbox.reclassify_leads', 'dry' => 0, 'days' => 14]);
$kept = (array) (jeab_inkorg_get($mid)['labels'] ?? []);
so((string) ($kept['type'] ?? '') === (string) ($beforeLabels['type'] ?? '') && (string) ($kept['offer'] ?? '') === (string) ($beforeLabels['offer'] ?? '') && jeab_inkorg_thread_override_get($tid)['offer'] === 'batteri', 'omklassningen lämnar den manuella produkten');
$openSaved = null;
foreach (jeab_inkorg_messages() as $message) {
    if ((string) ($message['message_id'] ?? '') === 'open@block.test') {
        $openSaved = $message;
        break;
    }
}
so(is_array($openSaved) && (string) ($openSaved['labels']['offer'] ?? '') === 'sol' && (string) ($openSaved['labels']['type'] ?? '') === 'Ny lead' && empty($openSaved['labels']['offer_manual']), 'omärkt sol är fortfarande sol');
$seenManual = false;
foreach ((array) ($re['rows'] ?? []) as $row) {
    if ((string) ($row['thread_id'] ?? '') === $tid) {
        $seenManual = true;
    }
}
so($seenManual === false, 'manuell tråd finns inte i omklassningen');

$charger = jeab_inkorg_hook([
    'action' => 'inbox.set_offer',
    'thread_id' => (string) $malmo['thread_id'],
    'offer' => 'elbilsladdare',
    'reason' => 'laddbox',
    'dry' => 0,
]);
$malmoRow = null;
foreach (jeab_inkorg_threads() as $thread) {
    if ((string) ($thread['id'] ?? '') === (string) $malmo['thread_id']) {
        $malmoRow = $thread;
        break;
    }
}
so(($charger['ok'] ?? false) === true && (string) ($charger['offer'] ?? '') === 'laddbox' && (string) ($charger['product'] ?? '') === 'elbilsladdare' && (string) ($malmoRow['labels']['product'] ?? '') === 'elbilsladdare' && (string) ($malmoRow['case_class']['primary_product'] ?? '') === 'Elbilsladdare', 'elbilsladdare blir laddbox');
so((string) ($charger['after']['assignee'] ?? '') === 'Kim' && (string) ($malmoRow['assignee'] ?? '') === 'Kim', 'Malmö med elbilsladdare tilldelas Kim');

$hsi = 'hsi-083280df66ad2e79';
$hsiMsg = substr(hash('sha256', 'set-offer-arkiv'), 0, 16);
$hsiConv = [
    'id' => $hsi,
    'company' => 'malmo',
    'status' => 'closed',
    'import_source' => 'helpscout_import',
    'messages' => [[
        'id' => $hsiMsg,
        'thread_id' => $hsi,
        'from' => 'arkiv@brevlada.se',
        'from_name' => 'Arkiv',
        'subject' => 'Förfrågan',
        'body' => 'Vi vill ha solceller med batteri. Kan vi få pris?',
        'received_at' => date('c'),
        'source' => 'helpscout_import',
        'labels' => ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'intent' => 'information'],
    ]],
    'meta' => ['status' => 'closed', 'import_source' => 'helpscout_import'],
];
so(jeab_hs_import_save_conv($hsiConv) && jeab_hs_import_index_append(jeab_hs_import_index_row($hsiConv)), 'arkivtråd sparas');
$hsiSnap = static function () use ($hsi): string {
    $conv = jeab_hs_import_read_conv($hsi);
    $row = jeab_hs_import_index()['by_id'][$hsi] ?? null;
    return hash('sha256', (string) json_encode([$conv, $row]));
};
$hsiFiles = $hsiSnap();
$hsiDry = jeab_inkorg_hook(['action' => 'inbox.set_offer', 'thread_id' => $hsi, 'offer' => 'batteri', 'reason' => 'arkiv']);
so(($hsiDry['ok'] ?? false) === true && ($hsiDry['dry'] ?? 0) === 1 && ($hsiDry['thread_id'] ?? '') === $hsi && ($hsiDry['error'] ?? '') === '', 'arkivtråd hittas');
so(($hsiDry['would_quote'] ?? true) === false && ($hsiDry['would_send'] ?? true) === false && ($hsiDry['would_remind'] ?? true) === false, 'arkivets dry skickar inte');
so(($hsiDry['after']['offer'] ?? '') === 'batteri' && $hsiSnap() === $hsiFiles, 'arkivets dry ändrar inget');
$hsiApply = jeab_inkorg_hook(['action' => 'inbox.set_offer', 'thread_id' => $hsi, 'offer' => 'batteri', 'reason' => 'arkiv', 'dry' => 0]);
$hsiSql = $store->pdo()->query('SELECT offer, primary_product, assignee, type, manual FROM inbox_thread_override WHERE thread_id = ' . $store->pdo()->quote($hsi))->fetch(PDO::FETCH_ASSOC);
so(($hsiApply['ok'] ?? false) === true && ($hsiApply['audited'] ?? 0) === 1 && is_array($hsiSql) && (string) ($hsiSql['offer'] ?? '') === 'batteri' && (string) ($hsiSql['primary_product'] ?? '') === 'Batteri' && (string) ($hsiSql['assignee'] ?? '') === 'Kim' && (string) ($hsiSql['type'] ?? '') === 'Ny lead' && (int) ($hsiSql['manual'] ?? 0) === 1, 'arkivets dry=0 skriver SQL');
so($hsiSnap() === $hsiFiles && !is_file((string) jeab_hs_import_conv_path($hsi)) && !is_file(jeab_hs_import_root() . '/index.jsonl'), 'arkivraderna är oförändrade');
$hsiRe = jeab_inkorg_hook(['action' => 'inbox.reclassify_leads', 'dry' => 0, 'days' => 14]);
$hsiListed = false;
foreach ((array) ($hsiRe['rows'] ?? []) as $row) {
    if ((string) ($row['thread_id'] ?? '') === $hsi) {
        $hsiListed = true;
    }
}
$hsiView = null;
foreach (jeab_hs_import_list_threads() as $thread) {
    if ((string) ($thread['id'] ?? '') === $hsi) {
        $hsiView = $thread;
        break;
    }
}
$hsiMarks = is_array($hsiView) ? jeab_inkorg_list_marks($hsiView) : [];
so($hsiListed === false && jeab_inkorg_thread_override_get($hsi)['offer'] === 'batteri', 'omklassningen hoppar över arkivtråden');
so(is_array($hsiView) && (string) ($hsiView['assignee'] ?? '') === 'Kim' && in_array('batteri', $hsiMarks, true) && in_array('Lead', $hsiMarks, true) && (string) ($hsiView['labels']['product'] ?? '') === 'batteri', 'Historik visar batteri och Kim');
so(str_contains(json_encode($hsiDry, JSON_UNESCAPED_UNICODE) ?: '', '@') === false && str_contains(json_encode($hsiApply, JSON_UNESCAPED_UNICODE) ?: '', '@') === false, 'arkivsvaret har ingen e-post');

so($calls === 0 && $graphs === 0, 'inga mejl skickas');
so(count($store->listReminders(true)) === $remindersBefore, 'ingen påminnelse skapas');
so(str_contains(implode("\n", jeab_auto_rule_lines()), 'inbox.set_offer'), 'reglerna nämner hooken');

echo "inkorg set_offer: ok\n";
