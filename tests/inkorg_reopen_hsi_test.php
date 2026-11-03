<?php

declare(strict_types=1);

/** inbox.reopen: assignee, typ och hsi-arkiv. Påhittade namn. Inget skickas. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-reopen-hsi-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp . '/fellogg';
mkdir($GLOBALS['_JEAB_FELLOGG_DIR'], 0775, true);
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ro(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function ro_public(array $row): string
{
    $json = json_encode($row, JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : '';
}

function ro_archive(string $tid, string $company, string $email, string $name, string $body, bool $withConv): void
{
    $messageId = substr(hash('sha256', 'msg-' . $tid), 0, 16);
    $conv = [
        'id' => $tid,
        'company' => $company,
        'status' => 'closed',
        'import_source' => 'helpscout_import',
        'contact_id' => 'kt-exempel',
        'messages' => [[
            'id' => $messageId,
            'from' => $email,
            'from_name' => $name,
            'subject' => 'Exempelärende',
            'body' => $body,
            'received_at' => '2026-01-02T10:00:00+00:00',
            'source' => 'helpscout_import',
            'labels' => ['type' => 'Information', 'action' => 'arkivera', 'priority' => 4],
        ]],
        'meta' => ['status' => 'closed', 'import_source' => 'helpscout_import', 'contact_id' => 'kt-exempel'],
    ];
    $row = [
        'id' => $tid,
        'company' => $company,
        'status' => 'closed',
        'from' => $email,
        'from_name' => $name,
        'subject' => 'Exempelärende',
        'message_ids' => [$messageId],
        'contact_id' => 'kt-exempel',
        'import_source' => 'helpscout_import',
        'labels' => ['type' => 'Information'],
    ];
    if ($withConv) {
        ro(jeab_hs_import_save_conv($conv), 'arkivkonversationen sparas');
    }
    ro(jeab_hs_import_index_append($withConv ? jeab_hs_import_index_row($conv) : $row), 'arkivindexet sparas');
    unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);
}

$email = 'ada.exempel@example.test';
$body = 'Påhittad historikrad utan person.';
$hsi = 'hsi-' . substr(hash('sha256', 'reopen-arkiv'), 0, 16);
ro_archive($hsi, 'jarfalla', $email, 'Ada Exempel', $body, true);

$missing = jeab_inkorg_hook(['action' => 'inbox.reopen', 'thread_id' => 'hsi-' . str_repeat('a', 16)]);
ro(($missing['ok'] ?? true) === false && ($missing['error'] ?? '') === 'not_found', 'okänt hsi-id är not_found');
ro(!str_contains(ro_public($missing), '@'), 'not_found har ingen adress');

$badPerson = jeab_inkorg_hook(['action' => 'inbox.reopen', 'thread_id' => $hsi, 'assignee' => 'Zlatan', 'type' => 'service']);
ro(($badPerson['ok'] ?? true) === false && ($badPerson['error'] ?? '') === 'okänd person', 'okänd person nekas');

$badType = jeab_inkorg_hook(['action' => 'inbox.reopen', 'thread_id' => $hsi, 'assignee' => 'Roberto', 'type' => 'faktura']);
ro(($badType['ok'] ?? true) === false && ($badType['error'] ?? '') === 'okänd typ', 'okänd typ nekas');

$dry = jeab_inkorg_hook(['action' => 'inbox.reopen', 'thread_id' => $hsi, 'assignee' => 'roberto', 'type' => 'service']);
$dryJson = ro_public($dry);
ro(($dry['dry'] ?? 0) === 1 && ($dry['action'] ?? '') === 'create' && ($dry['would_status'] ?? '') === 'open', 'dry visar ett nytt öppet ärende');
ro(($dry['assignee'] ?? '') === 'Roberto' && ($dry['type'] ?? '') === 'Support' && ($dry['would_send'] ?? true) === false, 'dry sätter Roberto och Support och skickar inget');
ro((int) ($dry['message_refs'] ?? 0) === 1 && !str_contains($dryJson, '@') && !str_contains($dryJson, 'Påhittad'), 'dry refererar meddelandet utan kundtext');
ro(jeab_inkorg_reopen_linked($hsi) === '', 'dry skriver inget ärende');

$held = jeab_inkorg_hook(['action' => 'inbox.reopen', 'thread_id' => $hsi, 'assignee' => 'Roberto', 'type' => 'service', 'dry' => 0]);
ro(($held['ok'] ?? true) === false && ($held['error'] ?? '') === 'dry=0 kräver confirm=SAM' && jeab_inkorg_reopen_linked($hsi) === '', 'dry=0 utan confirm skriver inget');

$opened = jeab_inkorg_hook([
    'action' => 'inbox.reopen',
    'thread_id' => $hsi,
    'assignee' => 'Roberto',
    'type' => 'service',
    'dry' => '0',
    'confirm' => 'SAM',
]);
$live = (string) ($opened['live_thread_id'] ?? '');
$liveThread = $live !== '' ? jeab_auto_thread_by_id($live) : null;
ro(($opened['ok'] ?? false) === true && ($opened['status'] ?? '') === 'open' && ($opened['would_send'] ?? true) === false, 'confirm skapar ett öppet ärende');
ro(is_array($liveThread) && (string) ($liveThread['status'] ?? '') === 'open' && (string) ($liveThread['assignee'] ?? '') === 'Roberto', 'tilldelning och status sätts tillsammans');
ro((string) ($liveThread['labels']['type'] ?? '') === 'Support' && (string) ($liveThread['hsi_id'] ?? jeab_inkorg_thread_meta($live)['hsi_id'] ?? '') === $hsi, 'typen är Support och historiktråden är kopplad');
$liveMeta = jeab_inkorg_thread_meta($live);
ro((string) ($liveMeta['hsi_id'] ?? '') === $hsi && (int) count((array) ($liveMeta['message_refs'] ?? [])) === 1 && !empty($liveMeta['no_auto']), 'meddelande-id och no_auto ligger på ärendet');
$liveBody = (string) ($liveThread['first']['body'] ?? '');
$liveSubject = (string) ($liveThread['first']['subject'] ?? '');
ro($liveSubject === 'Service' && !str_contains($liveBody, 'Påhittad') && !str_contains($liveBody, '@'), 'brödtexten refererar utan att kopiera historiken');
ro((string) ($liveMeta['contact_id'] ?? '') === 'kt-exempel', 'kunden kopplas via kontakt-id');
$service = ['types' => jeab_inkorg_seed_service_types(), 'status' => ['open']];
ro(jeab_inkorg_thread_matches($liveThread, $service), 'servicevyn träffar ärendet');
$archive = jeab_hs_import_read_conv($hsi);
ro(is_array($archive) && (string) ($archive['status'] ?? '') === 'closed' && (string) ($archive['meta']['reopened_live'] ?? '') === $live, 'historiken förblir arkiv och pekar på det nya ärendet');
$auto = jeab_auto_on_message((string) ($liveThread['first']['id'] ?? ''));
ro(($auto['why'] ?? '') === 'ingen automatik', 'automatiken skickar inget');

$again = jeab_inkorg_hook([
    'action' => 'inbox.reopen',
    'thread_id' => $hsi,
    'assignee' => 'Roberto',
    'type' => 'Support',
    'dry' => 0,
    'confirm' => 'SAM',
]);
$n = 0;
foreach (jeab_inkorg_messages() as $message) {
    if (is_array($message) && (string) ($message['thread_id'] ?? '') === $live) {
        $n++;
    }
}
ro(($again['live_thread_id'] ?? '') === $live && $n === 1, 'samma historiktråd skapar inte ett andra ärende');

$indexOnly = 'hsi-' . substr(hash('sha256', 'reopen-index'), 0, 16);
ro_archive($indexOnly, 'jarfalla', 'bo.exempel@example.test', 'Bo Exempel', 'Annan rad.', false);
ro(jeab_hs_import_read_conv($indexOnly) === null, 'indexraden har ingen konversation');
$fromIndex = jeab_inkorg_hook([
    'action' => 'inbox.reopen',
    'thread_id' => $indexOnly,
    'assignee' => 'Elin',
    'type' => 'Bokning/ombokning',
    'dry' => 0,
    'confirm' => 'SAM',
]);
$indexLive = (string) ($fromIndex['live_thread_id'] ?? '');
$indexMeta = $indexLive !== '' ? jeab_inkorg_thread_meta($indexLive) : [];
ro(($fromIndex['ok'] ?? false) === true && (string) ($indexMeta['assignee'] ?? '') === 'Elin' && (string) ($indexMeta['status'] ?? '') === 'open', 'en ren indexrad blir också ett öppet ärende');
ro(!str_contains(ro_public($fromIndex), 'bo.exempel'), 'indexsvaret har ingen adress');

$malmo = 'hsi-' . substr(hash('sha256', 'reopen-malmo'), 0, 16);
ro_archive($malmo, 'malmo', 'kim.exempel@example.test', 'Kim Exempel', 'Malmö-rad.', true);
$blocked = jeab_inkorg_hook([
    'action' => 'inbox.reopen',
    'thread_id' => $malmo,
    'assignee' => 'Kim',
    'type' => 'service',
    'dry' => 0,
    'confirm' => 'SAM',
]);
ro(($blocked['status'] ?? 0) === 403 && jeab_inkorg_reopen_linked($malmo) === '', 'Malmö blir 403 och skrivs inte');

$lead = jeab_inkorg_ingest([
    'message_id' => 'live-reopen@hooks.test',
    'from' => 'noa.exempel@example.test',
    'from_name' => 'Noa Exempel',
    'to' => 'info@jarfalla.example.test',
    'subject' => 'Fråga',
    'body_text' => "Namn: Noa Exempel\nE-post: noa.exempel@example.test\nHej.\n",
    'source' => 'graph-jarfalla',
    'thread_key' => 'reopen-live',
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
        'intent' => 'kop', 'offer' => '', 'product' => '', 'question' => '',
    ],
]);
$liveTid = (string) ($lead['thread_id'] ?? '');
jeab_inkorg_mark_hook_closed($liveTid, 'close');
$liveDry = jeab_inkorg_hook(['action' => 'inbox.reopen', 'thread_id' => $liveTid, 'assignee' => 'Roberto', 'type' => 'service']);
ro(($liveDry['dry'] ?? 0) === 1 && (string) (jeab_inkorg_thread_meta($liveTid)['status'] ?? '') === 'closed' && (string) (jeab_inkorg_thread_meta($liveTid)['assignee'] ?? '') === '', 'torr återöppning av ett levande ärende skriver inte tilldelningen');
$liveNo = jeab_inkorg_hook(['action' => 'inbox.reopen', 'thread_id' => $liveTid, 'assignee' => 'Roberto', 'type' => 'service', 'dry' => 0]);
ro(($liveNo['error'] ?? '') === 'dry=0 kräver confirm=SAM' && (string) (jeab_inkorg_thread_meta($liveTid)['status'] ?? '') === 'closed', 'levande ärende kräver confirm');
$liveYes = jeab_inkorg_hook([
    'action' => 'inbox.reopen',
    'thread_id' => $liveTid,
    'assignee' => 'Roberto',
    'type' => 'service',
    'dry' => 0,
    'confirm' => 'SAM',
]);
$liveAfter = jeab_auto_thread_by_id($liveTid);
ro(($liveYes['status'] ?? '') === 'open' && (string) ($liveAfter['assignee'] ?? '') === 'Roberto' && (string) ($liveAfter['labels']['type'] ?? '') === 'Support', 'levande ärende får status, person och typ i samma skrivning');
ro((string) (jeab_inkorg_thread_meta($liveTid)['hook_closed'] ?? '') === '', 'hook-stängningen tas bort');

$GLOBALS['_JEAB_REOPEN_FAIL'] = new RuntimeException('sql_trasig_utan_kund');
$failId = 'hsi-' . substr(hash('sha256', 'reopen-fel'), 0, 16);
ro_archive($failId, 'jarfalla', $email, 'Ada Exempel', $body, true);
$failed = jeab_inkorg_hook([
    'action' => 'inbox.reopen',
    'thread_id' => $failId,
    'assignee' => 'Roberto',
    'type' => 'service',
    'dry' => 0,
    'confirm' => 'SAM',
]);
unset($GLOBALS['_JEAB_REOPEN_FAIL']);
$log = (string) @file_get_contents($GLOBALS['_JEAB_FELLOGG_DIR'] . '/app-errors.jsonl');
ro(($failed['error'] ?? '') === 'reopen_failed' && ($failed['reason'] ?? '') === 'RuntimeException', 'felet är reopen_failed');
ro(str_contains($log, 'sql_trasig_utan_kund') && !str_contains($log, 'Något gick fel') && !str_contains($log, 'ada.exempel'), 'felloggen har orsaken och ingen kundtext');
ro(jeab_inkorg_reopen_linked($failId) === '', 'ett loggat fel skriver inget ärende');

echo "reopen hsi ok\n";
