<?php

declare(strict_types=1);

/** Manuell send_quote skriver sent-logg och quote_sent innan svar, även vid omförsök. inkorg.body läser stängda och HS-import. Inget riktigt mejl. */

$tmp = sys_get_temp_dir() . '/jeab-send-log-body-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function sl(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    $n = count($GLOBALS['_SENT']);

    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g-log-' . $n, 'message_id' => 'sent-log-' . $n . '@local'];
};
$GLOBALS['JEAB_SMS_SEND'] = static function (): array {
    return ['ok' => true];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url): array {
    unset($url);
    if ($method === 'GET') {
        return ['code' => 200, 'body' => ['value' => [], 'id' => 'sent']];
    }

    return ['code' => 404, 'body' => []];
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 log\n")]);

$pnr = '198001011234';
$shortPnr = '800101-1234';
$ing = jeab_inkorg_ingest([
    'message_id' => 'log-quote@exempel.se',
    'from' => 'nora.logg@exempel.se',
    'from_name' => 'Nora Logg',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert batteri ' . $shortPnr,
    'body_text' => "Namn: Nora Logg\nE-post: nora.logg@exempel.se\nTelefon: 070-123 45 67\nAdress: Solvägen 12, 177 31 Järfälla\nProdukt: Batteri\nPersonnummer {$pnr}\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'thread_key' => '2a74feb129f99fe3',
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
        'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
    ],
]);
sl(!empty($ing['ok']), 'leadet tas in');
$tid = (string) ($ing['thread_id'] ?? '');
sl($tid === '2a74feb129f99fe3', 'tråd-id behålls');

$send = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $tid, 'dry' => 0]);
if (($send['action'] ?? '') !== 'quoted') {
    fwrite(STDERR, json_encode($send, JSON_UNESCAPED_UNICODE) . "\n");
}
sl(($send['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === 1, 'manuell send_quote skickar en gång');
sl((string) ($send['from'] ?? '') === 'saljare4@jarfalla.example.test', 'svaret har den From som användes');

$rows = jeab_store()->pdo()->query("SELECT kind, from_addr FROM inbox_sent WHERE thread_id = " . jeab_store()->pdo()->quote($tid))->fetchAll(PDO::FETCH_ASSOC);
$offerRows = array_values(array_filter($rows, static fn(array $row): bool => (string) ($row['kind'] ?? '') === 'offert'));
sl(count($offerRows) === 1 && (string) ($offerRows[0]['from_addr'] ?? '') === 'saljare4@jarfalla.example.test', 'sent-loggen har en rad med From');
$fresh = jeab_auto_thread_by_id($tid);
sl(is_array($fresh) && (jeab_inkorg_thread_quote_view($fresh)['quote'] ?? '') === 'yes', 'quote_sent är yes direkt');
sl((string) ($fresh['status'] ?? '') === 'closed', 'ärendet är stängt');

$again = jeab_inkorg_hook(['action' => 'inkorg.send_quote', 'thread_id' => $tid, 'dry' => 0]);
sl(!empty($again['already_sent']) && count($GLOBALS['_SENT']) === 1, 'omförsök skickar inte ett till mejl');
$rows = jeab_store()->pdo()->query("SELECT kind FROM inbox_sent WHERE thread_id = " . jeab_store()->pdo()->quote($tid) . " AND kind = 'offert'")->fetchAll(PDO::FETCH_ASSOC);
sl(count($rows) === 1, 'omförsök lägger ingen andra sent-rad');

jeab_store()->pdo()->exec('DELETE FROM inbox_sent');
$GLOBALS['_JEAB_INBOX_SENT_CACHE'] = null;
jeab_inkorg_thread_write($tid, static function (array $t): array {
    unset($t['quote_sent']);
    $t['drafts'] = array_values(array_filter((array) ($t['drafts'] ?? []), static function ($d): bool {
        return !is_array($d) || !str_contains((string) ($d['body'] ?? ''), 'Offert (');
    }));

    return $t;
});
jeab_inkorg_locked('messages', static function (?array $d) use ($tid): array {
    $d = $d ?? ['messages' => []];
    $d['messages'] = array_values(array_filter((array) ($d['messages'] ?? []), static function ($m) use ($tid): bool {
        if (!is_array($m) || (string) ($m['thread_id'] ?? '') !== $tid) {
            return true;
        }

        return !str_starts_with((string) ($m['source'] ?? ''), 'outbound');
    }));

    return [null, $d];
});
$wiped = jeab_auto_thread_by_id($tid);
sl(is_array($wiped) && (jeab_inkorg_thread_quote_view($wiped)['quote'] ?? '') === 'no', 'rensad markör är no');
$repair = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $tid, 'dry' => 0]);
sl(!empty($repair['already_sent']) && count($GLOBALS['_SENT']) === 1, 'reparation skickar inte om mejlet');
$repaired = jeab_store()->pdo()->query("SELECT from_addr FROM inbox_sent WHERE thread_id = " . jeab_store()->pdo()->quote($tid) . " AND kind = 'offert'")->fetchAll(PDO::FETCH_ASSOC);
sl(count($repaired) === 1 && (string) ($repaired[0]['from_addr'] ?? '') === 'saljare4@jarfalla.example.test', 'saknad rad skrivs tillbaka med From');
$marked = jeab_auto_thread_by_id($tid);
sl(is_array($marked) && (jeab_inkorg_thread_quote_view($marked)['quote'] ?? '') === 'yes', 'quote_sent sätts tillbaka');

$closedBody = jeab_inkorg_hook(['action' => 'inkorg.body', 'id' => $tid]);
$closedText = json_encode($closedBody, JSON_UNESCAPED_UNICODE);
sl(!empty($closedBody['ok']) && (string) ($closedBody['status'] ?? '') === 'closed', 'body läser stängt ärende');
sl(is_string($closedText) && str_contains($closedText, 'nora.logg@exempel.se') && str_contains($closedText, '070-123 45 67'), 'mejl och telefon lämnas');
sl(is_string($closedText) && !str_contains($closedText, $pnr) && !str_contains($closedText, $shortPnr), 'personnummer kommer inte med');

$graph = jeab_inkorg_ingest([
    'message_id' => 'graph-alias@exempel.se',
    'from' => 'g.kund@exempel.se',
    'from_name' => 'G Kund',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Graphtråd ' . $pnr,
    'body_text' => "Hej från Graph. Mejl g.kund@exempel.se och {$pnr}.",
    'source' => 'graph-jarfalla',
    'thread_key' => 'g-8c9744266d43586d',
    'graph_conversation_id' => 'conv-kept',
]);
sl(!empty($graph['ok']) && (string) ($graph['thread_id'] ?? '') === 'g-8c9744266d43586d', 'graph-tråden får sitt id');
jeab_inkorg_thread_write('g-8c9744266d43586d', static function (array $t): array {
    $t['status'] = 'closed';
    $t['closed_at'] = date('c');

    return $t;
});
$gThread = jeab_inkorg_hook(['action' => 'inkorg.thread', 'id' => 'g-8c9744266d43586d']);
$gRead = jeab_inkorg_hook(['action' => 'inkorg.read', 'id' => 'g-8c9744266d43586d']);
sl(!empty($gThread['ok']) && ($gThread['thread_id'] ?? '') === 'g-8c9744266d43586d' && ($gThread['status'] ?? '') === 'closed', 'inkorg.thread hittar g-id');
sl(!empty($gRead['ok']) && ($gRead['error'] ?? '') !== 'not_found', 'inkorg.read hittar g-id');
$gBody = jeab_inkorg_hook(['action' => 'inkorg.body', 'id' => 'g-8c9744266d43586d']);
$gText = json_encode($gBody, JSON_UNESCAPED_UNICODE);
sl(is_string($gText) && str_contains($gText, 'g.kund@exempel.se') && !str_contains($gText, $pnr), 'g-trådens text har mejl men inte personnummer');

$aliasConv = 'conv-alias-lookup-1';
$aliasToken = 'g-' . substr(hash('sha256', $aliasConv), 0, 16);
$alias = jeab_inkorg_ingest([
    'message_id' => 'alias-live@exempel.se',
    'from' => 'alias.kund@exempel.se',
    'subject' => 'Alias',
    'body_text' => 'Aliastexten finns kvar.',
    'source' => 'graph-jarfalla',
    'thread_key' => 'alias-live-1',
    'graph_conversation_id' => $aliasConv,
]);
sl(!empty($alias['ok']), 'aliastråden tas in');
$aliasRead = jeab_inkorg_hook(['action' => 'inbox.read', 'id' => $aliasToken]);
sl(!empty($aliasRead['ok']) && ($aliasRead['thread_id'] ?? '') === 'alias-live-1', 'g-hash av konversationen hittar tråden');

$hsId = jeab_hs_import_thread_key('3486695772');
$conv = [
    'id' => $hsId,
    'company' => 'jarfalla',
    'status' => 'closed',
    'closed_at' => '2026-10-01T10:00:00+02:00',
    'hs_ids' => ['conversation' => '3486695772', 'number' => '3486695772'],
    'meta' => [
        'status' => 'closed',
        'closed_at' => '2026-10-01T10:00:00+02:00',
        'hs_ids' => ['conversation' => '3486695772'],
    ],
    'messages' => [[
        'id' => 'hsmsg3486',
        'from' => 'kund.hs@exempel.se',
        'subject' => 'Importerad tråd',
        'body' => 'Texten i importen. Personnummer ' . $pnr . ' står här. Mobil 070-123 45 67.',
        'received_at' => '2026-10-01T08:00:00+00:00',
        'source' => 'helpscout_import',
    ]],
];
sl(jeab_hs_import_save_conv($conv) && jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'HS-importen lagras');
$hsThread = jeab_inkorg_hook(['action' => 'inkorg.thread', 'id' => 'hs-3486695772']);
$hsRead = jeab_inkorg_hook(['action' => 'inkorg.read', 'id' => 'hs-3486695772']);
$hsBody = jeab_inkorg_hook(['action' => 'inkorg.body', 'id' => 'hs-3486695772']);
sl(!empty($hsThread['ok']) && ($hsThread['thread_id'] ?? '') === $hsId && ($hsThread['status'] ?? '') === 'closed', 'inkorg.thread hittar hs-id');
sl(!empty($hsRead['ok']) && ($hsRead['error'] ?? '') !== 'not_found', 'inkorg.read hittar hs-id');
$hsText = json_encode($hsBody, JSON_UNESCAPED_UNICODE);
sl(!empty($hsBody['ok']) && is_string($hsText) && str_contains($hsText, 'Texten i importen') && str_contains($hsText, 'kund.hs@exempel.se') && str_contains($hsText, '070-123 45 67'), 'importens text, mejl och telefon följer med');
sl(is_string($hsText) && !str_contains($hsText, $pnr), 'importens personnummer kommer inte med');
$missing = jeab_inkorg_hook(['action' => 'inkorg.thread', 'id' => 'hs-0000000001']);
sl(($missing['ok'] ?? true) === false && ($missing['error'] ?? '') === 'not_found', 'okänt id är not_found');

echo "send quote log body ok\n";
