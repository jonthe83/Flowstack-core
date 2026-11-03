<?php

declare(strict_types=1);

/** Inkorg: Help Scout-kopia och Graph-mejl av samma kontaktformulär blir ett ärende; samma-kund-länk. */

$tmp = sys_get_temp_dir() . '/jeab-dedup-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export(['timezone' => 'Europe/Stockholm', 'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json']], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function dk(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$form = fn(string $id, string $rt, string $t) => ['id' => $id, 'internetMessageId' => "<{$id}@wix>", 'conversationId' => 'C-' . $id,
    'from' => ['emailAddress' => ['address' => 'no-reply@crm.wix.com', 'name' => 'Formulär JEAB']], 'subject' => 'JEAB Kontakt Formulär',
    'receivedDateTime' => $t, 'body' => ['content' => 'A site visitor just submitted your form'],
    'internetMessageHeaders' => [['name' => 'Reply-To', 'value' => "Kund <{$rt}>"]]];
$msgs = ['F1' => $form('F1', 'kund1@ex.se', '2026-10-13T08:00:30Z'), 'F2' => $form('F2', 'kund2@ex.se', '2026-10-13T09:00:00Z'), 'F3' => $form('F3', 'kund3@ex.se', '2026-10-13T10:00:00Z')];
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url) use ($msgs) {
    if (preg_match('#/messages/([A-Z0-9]+)\?#', $url, $m)) {
        return isset($msgs[$m[1]]) ? ['code' => 200, 'body' => $msgs[$m[1]]] : ['code' => 404, 'body' => []];
    }
    return ['code' => 500, 'body' => []];
};
jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);
$hs = fn(string $cid, string $email, string $t) => jeab_inkorg_ingest(['message_id' => "hs-{$cid}-1@helpscout.net", 'from' => $email, 'subject' => 'JEAB Kontakt Formulär',
    'received_at' => $t, 'body_html' => 'A site visitor', 'source' => 'helpscout-live', 'thread_key' => 'hs-' . $cid, 'hs_conversation_id' => $cid, 'hs_thread_type' => 'customer']);

// 1) Help Scout först, sedan Graph: Reply-To matchar kunden → ingen ny kopia.
$hs('1', 'kund1@ex.se', '2026-10-13T08:00:00Z');
$r = jeab_graph_ingest_id('jarfalla', 'F1');
dk(!empty($r['duplicate']) && ($r['thread_id'] ?? '') === 'hs-1', 'formulär via Graph efter HS-kopia blir inte nytt ärende (Reply-To)');

// 2) Graph först, sedan Help Scout: Graph-ärendet flyttas till papperskorgen som dubblett.
$g = jeab_graph_ingest_id('jarfalla', 'F2');
dk(empty($g['duplicate']), 'Graph-mejl utan HS-tvilling blir ärende');
$hs('2', 'kund2@ex.se', '2026-10-13T09:01:00Z');
$merged = jeab_inkorg_hs_absorb_graph('hs-2');
$meta = jeab_inkorg_threads_meta()[$g['thread_id']] ?? [];
dk($merged === [$g['thread_id']] && ($meta['duplicate_of'] ?? '') === 'hs-2' && ($meta['deleted_by'] ?? '') === 'dubblett-hs', 'Graph-kopian i papperskorgen, pekar på HS-ärendet');
dk(jeab_inkorg_get((string) $g['id']) !== null, 'inget raderas');
dk((jeab_graph_state()['conv']['C-F2'] ?? '') === 'hs-2', 'fortsatta Graph-svar trådas till HS-ärendet');

// 3) Annan kund, samma rubrik: ingen sammanslagning.
$g3 = jeab_graph_ingest_id('jarfalla', 'F3');
$hs('3', 'annan@ex.se', '2026-10-13T10:00:00Z');
dk(jeab_inkorg_hs_absorb_graph('hs-3') === [] && empty(jeab_inkorg_threads_meta()[$g3['thread_id']]['deleted_at']), 'olika kunder slås inte ihop');

// 4) Befintlig data: skanning hittar paret, är idempotent.
$scan = jeab_inkorg_dedup_scan(false);
dk(count($scan['pairs']) === 1 && $scan['pairs'][0]['hs'] === 'hs-2', 'skanning hittar befintligt par');

// 5) Samma kund-länk åt båda håll.
jeab_inkorg_link_same_customer('hs-1', 'hs-3');
$ts = array_column(jeab_inkorg_threads(), null, 'id');
dk($ts['hs-1']['same_customer'] === ['hs-3'] && $ts['hs-3']['same_customer'] === ['hs-1'], 'samma kund länkas åt båda håll');
echo "ALLA OK\n";
