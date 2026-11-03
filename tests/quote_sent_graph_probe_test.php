<?php

declare(strict_types=1);

/**
 * Brevlådesökningen: 200 med bara @odata.nextLink, sedan 200 med error-objekt.
 * Graph håller inte. En Help Scout-träff håller med redan skickad. Inget skickas.
 * inbox.quote_sent_graph_probe läser samma sidor utan ämne, adress eller token.
 */

$tmp = sys_get_temp_dir() . '/jeab-graph-probe-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function gp(bool $ok, string $m): void
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

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g-probe', 'conversation_id' => 'CV', 'message_id' => 'sent-probe@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn(): array => ['code' => 404, 'body' => []];
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 probe\n")]);
require __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();

$rules = implode("\n", jeab_auto_rule_lines());
gp(str_contains($rules, 'inbox.quote_sent_graph_probe') && str_contains($rules, 'grok-4.7'), 'regeln nämner sonden och modellen');
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg.php');
gp(str_contains($hookSrc, "case 'inbox.quote_sent_graph_probe':"), 'hooken ligger bredvid quote_sent_diag');

$person = mb_strtolower((string) jeab_gp_owner_email('batteri', 'jarfalla'));
gp($person !== '' && !str_contains($person, ' '), 'batteri har en personbrevlåda');

$ingest = static function (string $id, string $email, string $phone, string $street): string {
    $in = jeab_inkorg_ingest([
        'message_id' => $id . '@probe.test',
        'from' => 'info@wix-forms.com',
        'from_name' => 'Formulär JEAB',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Batteri',
        'body_text' => "A site visitor just submitted your form Batteri lead\n\nNamn:\n\nNamn {$id}\n\nAdress:\n\nAdress {$street}\n\nTelefon:\n\nTelefon {$phone}\n\nE-post:\n\nE-post {$email}\n",
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'graph_id' => 'graph-' . $id,
        'thread_key' => 'probe-' . $id,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
        ],
        'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass'],
    ]);
    gp(!empty($in['ok']) && ($in['thread_id'] ?? '') !== '', 'ärendet ' . $id . ' tas in');

    return (string) $in['thread_id'];
};

$hs = static function (string $email): void {
    $GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function (string $method, string $url) use ($email): array {
        $decoded = rawurldecode($url);
        if (str_contains($decoded, '/threads')) {
            return ['code' => 200, 'body' => json_encode(['_embedded' => ['threads' => [
                ['id' => 1, 'type' => 'customer', 'createdAt' => '2026-10-01T08:00:00Z', 'body' => 'SECRET-HS-BODY'],
                ['id' => 2, 'type' => 'reply', 'createdAt' => '2026-10-20T08:00:00Z', 'body' => 'SECRET-HS-REPLY Hemlig offert'],
            ]]], JSON_UNESCAPED_UNICODE)];
        }
        if (str_contains($decoded, 'email:' . $email)) {
            return ['code' => 200, 'body' => json_encode([
                '_embedded' => ['conversations' => [['id' => 55, 'subject' => 'SECRET-HS-SUBJECT']]],
                'page' => ['totalPages' => 1],
            ])];
        }

        return ['code' => 200, 'body' => json_encode(['_embedded' => ['conversations' => []], 'page' => ['totalPages' => 1]])];
    };
    $GLOBALS['_JEAB_HS_IMPORT_SLEEP'] = static function (): void {
    };
};

$email = 'probe.kund.sq@gmail.com';
$secretSubject = 'Hemlig offert SOL';
$urls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []) use (&$urls, $person, $email, $secretSubject): array {
    $urls[] = [rawurldecode($url), $headers];
    $decoded = rawurldecode($url);
    if (str_contains($url, 'probe-page-2')) {
        return ['code' => 200, 'body' => [
            'error' => ['code' => 'ErrorInternalServerError', 'message' => 'Bearer secret-token ' . $secretSubject . ' ' . $email],
        ]];
    }
    if (str_contains($decoded, '/messages') && !str_contains($decoded, '/mailFolders/') && str_contains($decoded, '$search=')) {
        return ['code' => 200, 'body' => [
            '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users/' . $person . '/messages?probe-page-2&$search="to:' . $email . '"',
        ]];
    }
    if (str_contains($decoded, '/mailFolders/sentitems') && !str_contains($decoded, '/messages')) {
        return ['code' => 200, 'body' => ['id' => 'sent-folder-probe']];
    }
    if (str_contains($decoded, '/mailFolders/sentitems/messages')) {
        return ['code' => 200, 'body' => ['value' => [], '@odata.count' => 0]];
    }

    return ['code' => 404, 'body' => ['error' => ['message' => 'SECRET-OTHER']]];
};
$hs($email);
$tid = $ingest('mailbox', $email, '0701234501', 'Decembervägen 10, 17760 Järfälla');
$thread = jeab_auto_thread_by_id($tid);
gp(is_array($thread), 'tråden finns');
$sources = jeab_auto_quote_sent_sources($thread, $person, $email, true, false, 'batteri');
$sawMailbox = false;
$sawNext = false;
$sawScan = false;
$sawSentSearch = false;
foreach ($urls as [$url, $headers]) {
    if (str_contains($url, '/messages') && !str_contains($url, '/mailFolders/') && str_contains($url, '$search=')) {
        $sawMailbox = true;
        gp(in_array('ConsistencyLevel: eventual', $headers, true), 'brevlådesökningen ber om ConsistencyLevel');
    }
    if (str_contains($url, 'probe-page-2')) {
        $sawNext = true;
    }
    if (str_contains($url, 'sentDateTime ge')) {
        $sawScan = true;
    }
    if (str_contains($url, '/mailFolders/sentitems/messages') && str_contains($url, '$search=')) {
        $sawSentSearch = true;
    }
}
gp($sawSentSearch && $sawMailbox && $sawNext && $sawScan, 'Skickat-sökning, nextLink och datumskanning körs');
gp(
    ($sources['graph_search']['hit'] ?? true) === false
    && ($sources['graph_search']['why'] ?? 'x') === ''
    && ($sources['graph_scan']['why'] ?? 'x') === ''
    && !str_contains((string) ($sources['graph_search']['why'] ?? ''), 'graph 200')
    && !str_contains((string) ($sources['graph_scan']['why'] ?? ''), 'graph 200'),
    'Graph håller inte efter den oväntade 200'
);
gp(!empty($sources['helpscout']['hit']) && str_contains((string) ($sources['helpscout']['why'] ?? ''), 'redan skickad'), 'Help Scout-träffen finns kvar');
$before = count($GLOBALS['_SENT']);
$held = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $tid, 'dry' => 1]);
gp(
    ($held['action'] ?? '') === 'hold'
    && str_contains((string) ($held['why'] ?? ''), 'redan skickad')
    && str_contains((string) ($held['why'] ?? ''), 'Help Scout')
    && !str_contains((string) ($held['why'] ?? ''), 'graph 200')
    && !str_contains(json_encode($held), 'SECRET')
    && count($GLOBALS['_SENT']) === $before,
    'Help Scout-träffen håller och inget skickas'
);

$urls = [];
$probe = jeab_inkorg_hook(['action' => 'inbox.quote_sent_graph_probe', 'thread_id' => $tid]);
$probeJson = json_encode($probe, JSON_UNESCAPED_UNICODE);
gp(($probe['ok'] ?? false) === true && ($probe['read_only'] ?? false) === true && (int) ($probe['sent'] ?? 1) === 0 && ($probe['would_send'] ?? true) === false, 'sonden är bara läsning');
gp(($probe['person_hash'] ?? '') === hash('sha256', $person) && ($probe['rcpt_hash'] ?? '') === hash('sha256', $email), 'adresserna är hashade');
gp(
    !str_contains((string) $probeJson, $email)
    && !str_contains((string) $probeJson, $person)
    && !str_contains((string) $probeJson, $secretSubject)
    && !str_contains((string) $probeJson, 'Bearer')
    && !str_contains((string) $probeJson, 'secret-token')
    && !str_contains((string) $probeJson, 'sent-folder-probe')
    && !str_contains((string) $probeJson, 'SECRET'),
    'sonden tar inte med ämne, adress eller token'
);
$byRequest = [];
foreach ((array) ($probe['pages'] ?? []) as $page) {
    if (!is_array($page)) {
        continue;
    }
    $byRequest[(string) ($page['request'] ?? '')][] = $page;
}
gp(isset($byRequest['sentitems_to'], $byRequest['sentitems_cc'], $byRequest['mailbox_to'], $byRequest['mailbox_cc']), 'sonden kör samma fyra sökningar');
$box = $byRequest['mailbox_to'] ?? [];
gp(
    count($box) >= 2
    && (int) ($box[0]['page'] ?? 0) === 1
    && (int) ($box[0]['http'] ?? 0) === 200
    && ($box[0]['value_present'] ?? true) === false
    && ($box[0]['value_type'] ?? 'x') === ''
    && array_key_exists('value_length', $box[0]) && $box[0]['value_length'] === null
    && ($box[0]['has_nextLink'] ?? false) === true
    && in_array('@odata.nextLink', (array) ($box[0]['keys'] ?? []), true)
    && ($box[0]['error_code'] ?? 'x') === ''
    && ($box[0]['json_error'] ?? 'x') === ''
    && (int) ($box[1]['page'] ?? 0) === 2
    && (int) ($box[1]['http'] ?? 0) === 200
    && ($box[1]['value_present'] ?? true) === false
    && ($box[1]['error_code'] ?? '') === 'ErrorInternalServerError'
    && ($box[1]['has_nextLink'] ?? true) === false,
    'sonden visar nextLink-sidan och error-sidan'
);
$sentPage = $byRequest['sentitems_to'][0] ?? [];
gp(
    ($sentPage['value_present'] ?? false) === true
    && ($sentPage['value_type'] ?? '') === 'array'
    && (int) ($sentPage['value_length'] ?? -1) === 0
    && ($sentPage['odata_count'] ?? null) === 0,
    'sonden visar value och @odata.count'
);
gp(count($GLOBALS['_SENT']) === $before, 'sonden skickar inget');
$missing = jeab_inkorg_hook(['action' => 'inbox.quote_sent_graph_probe', 'thread_id' => 'saknas-helt']);
gp(($missing['ok'] ?? true) === false && ($missing['error'] ?? '') === 'not_found' && (int) ($missing['sent'] ?? 1) === 0 && ($missing['read_only'] ?? false) === true, 'okänd tråd läser inte Graph');

$emailErr = 'probe.fel.sq@gmail.com';
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use ($emailErr): array {
    $decoded = rawurldecode($url);
    if (str_contains($decoded, '/mailFolders/sentitems') && !str_contains($decoded, '/messages')) {
        return ['code' => 200, 'body' => ['id' => 'sent-folder-err']];
    }
    if (str_contains($decoded, '$search=')) {
        return ['code' => 200, 'body' => ['error' => ['code' => 'ErrorInternalServerError', 'message' => 'SECRET-GRAPH ' . $emailErr]]];
    }
    if (str_contains($decoded, '/mailFolders/sentitems/messages')) {
        return ['code' => 200, 'body' => ['value' => []]];
    }

    return ['code' => 404, 'body' => []];
};
$hs($emailErr);
$tidErr = $ingest('graph-fel', $emailErr, '0701234502', 'Mälarvägen 4, 17740 Järfälla');
$errThread = jeab_auto_thread_by_id($tidErr);
$errSources = jeab_auto_quote_sent_sources(is_array($errThread) ? $errThread : [], $person, $emailErr, true, false, 'batteri');
gp(
    str_contains((string) ($errSources['graph_search']['why'] ?? ''), 'kunde inte läsa Skickat (graph 200)')
    && !empty($errSources['helpscout']['hit']),
    'Graph-läsfel stoppar inte Help Scout live'
);
$before = count($GLOBALS['_SENT']);
$errHeld = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $tidErr, 'dry' => 1]);
gp(
    ($errHeld['action'] ?? '') === 'hold'
    && str_contains((string) ($errHeld['why'] ?? ''), 'redan skickad')
    && str_contains((string) ($errHeld['why'] ?? ''), 'Help Scout')
    && !str_contains((string) ($errHeld['why'] ?? ''), 'graph 200')
    && !str_contains(json_encode($errHeld), 'SECRET')
    && count($GLOBALS['_SENT']) === $before,
    'Help Scout vinner över Graph-läsfelet och inget skickas'
);

echo "OK\n";
