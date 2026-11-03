<?php

declare(strict_types=1);

/** Påhittade formulär. Namn, Hej utan namn, och offert sol+batteri. Inget riktigt kundmejl. */

$tmp = sys_get_temp_dir() . '/jeab-quote-name-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function qn(bool $ok, string $m): void
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
    return ['ok' => true, 'from' => 'demo@example.test', 'graph_id' => 'g', 'conversation_id' => 'C', 'message_id' => 's@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn (): array => ['code' => 200, 'body' => ['value' => []]];

$line = 'Avsändare: Demo Energi Järfälla Namn: Demo Energi Järfälla Namn: Nora Exempel E-post: nora.exempel@exempel.se Telefon: 070-111 22 33 Adress: Testvägen 9 Postnummer: 177 31 Ort: Järfälla Intresse: Sol+Batteri';
$nora = jeab_lead_customer(['from' => 'info@wix-forms.com', 'from_name' => 'Demo Energi Järfälla', 'body' => $line]);
qn(($nora['name'] ?? '') === 'Nora Exempel', 'inline Namn hoppar över bolagsnamnet');
qn(!jeab_auto_company_name('Nora Exempel') && jeab_auto_company_name('Demo Energi Järfälla') && jeab_auto_company_name('Demo Energi Järfälla'), 'bolagsnamn känns igen');
qn(jeab_inkorg_form_quote_offer(['body' => $line]) === 'sol+batteri', 'intresset är sol+batteri');
qn(jeab_lead_quote_owner_name('sol+batteri', 'jarfalla') === 'Sam L', 'säljaren är Sam L');
qn(jeab_inkorg_form_quote_offer(['body' => "Intresse: Solbatteri\n"]) === 'batteri', 'solbatteri förblir batteri');

jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
@mkdir(dirname(jeab_inkorg_token_path()), 0775, true);
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'ink_qn_' . bin2hex(random_bytes(4))]));
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode('%PDF-1.4 sol')]);
jeab_notiser_save_body('offert-sol-jarfalla-eget', "Hej\n\nSol eget.", 'test');
jeab_notiser_save_body('offert-sol-jarfalla-kopt', "Hej\n\nSol köpt.", 'test');
jeab_notiser_set_enabled('offert-sol-jarfalla-eget', false, 'test');
jeab_notiser_set_enabled('offert-sol-jarfalla-kopt', false, 'test');

$box = jeab_graph_mailboxes()['jarfalla'];
$ingest = static function (string $id, string $body, string $fromName) use ($box): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@quote-name.test',
        'from' => 'info@wix-forms.com',
        'from_name' => $fromName,
        'to' => $box,
        'subject' => 'Formulär',
        'date' => '2026-10-20T19:00:00+02:00',
        'received_at' => '2026-10-20T19:00:00+02:00',
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'headers' => ['Authentication-Results' => 'mx.exempel.se; dmarc=pass header.from=wix-forms.com'],
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 90,
            'intent' => 'kop', 'offer' => 'sol', 'product' => 'solceller', 'question' => '',
        ],
    ]);
};

$noname = $ingest('noname', "E-post: tom.namn@exempel.se\nTelefon: 070-222 33 44\nAdress: Exempelvägen 2\nPostnummer: 177 31\nOrt: Järfälla\nIntresse: Sol\n", 'Demo Energi Järfälla');
$dryHello = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $noname['thread_id'], 'dry' => 1]);
qn(($dryHello['ok'] ?? false) === true && ($dryHello['action'] ?? '') !== 'hold', 'saknat namn håller inte offerten');
qn(str_contains((string) ($dryHello['body'] ?? ''), 'Hej') && !str_contains((string) ($dryHello['body'] ?? ''), 'Hej Järfälla'), 'hälsningen är Hej och inte bolagsnamnet');
qn(count($GLOBALS['_SENT']) === 0, 'dry=1 skickar inget');

$named = jeab_inkorg_hook([
    'action' => 'inbox.send_quote',
    'thread_id' => (string) $noname['thread_id'],
    'dry' => 1,
    'name' => 'Nora Exempel',
    'offer' => 'sol+batteri',
]);
qn(($named['ok'] ?? false) === true && ($named['offer'] ?? '') === 'sol+batteri', 'dry visar offerten sol+batteri');
qn(str_contains((string) ($named['body'] ?? ''), 'Hej Nora'), 'name används i hälsningen');
qn(($named['subject'] ?? '') === 'Solceller med batteri' && ($named['sender'] ?? '') === 'demo@example.test', 'mallen är sol och batteri från Sam L');
qn(count($GLOBALS['_SENT']) === 0, 'förhandsvisningen skickar inget');

$before = count(jeab_list_reminders(jeab_store(), true));
$denied = jeab_inkorg_hook([
    'action' => 'inbox.send_quote',
    'thread_id' => (string) $noname['thread_id'],
    'dry' => 0,
    'name' => 'Nora Exempel',
    'offer' => 'sol+batteri',
]);
qn(($denied['ok'] ?? true) === false && count($GLOBALS['_SENT']) === 0 && count(jeab_list_reminders(jeab_store(), true)) === $before, 'dry=0 utan confirm skriver inget');

$sent = jeab_inkorg_hook([
    'action' => 'inbox.send_quote',
    'thread_id' => (string) $noname['thread_id'],
    'dry' => 0,
    'confirm' => 'SAM',
    'name' => 'Nora Exempel',
    'offer' => 'sol+batteri',
]);
qn(($sent['ok'] ?? false) === true && count($GLOBALS['_SENT']) === 1, 'manuell offert går ut även när solnotisen och automatiken är av');
$sentHtml = (string) ($GLOBALS['_SENT'][0]['html'] ?? '');
$sentText = (string) ($GLOBALS['_SENT'][0]['body'] ?? '');
qn(str_contains($sentHtml, 'Hej Nora') || str_contains($sentText, 'Hej Nora'), 'det skickade mejlet hälsar med name');
$fresh = jeab_auto_thread_by_id((string) $noname['thread_id']);
$labels = (array) ((array) ($fresh['first'] ?? [])['labels'] ?? []);
qn((string) ($labels['offer'] ?? '') === 'sol+batteri' && (string) ($labels['product'] ?? '') === 'sol+batteri', 'tråden rättas till sol+batteri');
qn((string) ($fresh['assignee'] ?? '') === 'SamL', 'tråden tilldelas Sam L');
$rem = null;
foreach (jeab_list_reminders(jeab_store(), true) as $row) {
    if (($row['email'] ?? '') === 'tom.namn@exempel.se') {
        $rem = $row;
    }
}
qn(is_array($rem) && (string) ($rem['customer_name'] ?? '') === 'Nora Exempel', 'påminnelsen får name');
qn(in_array('Sam L', (array) ($rem['assignees'] ?? []), true) || (string) ($rem['person'] ?? '') === 'SamL', 'påminnelsen är Sam L');

echo "inkorg-quote-name: ok\n";
