<?php

declare(strict_types=1);

/** Nordsolen: gata, postnummer, ort och mobil. Partneradress får ingen offert. Help Scout-dubblett länkas, raderas inte. */

$tmp = sys_get_temp_dir() . '/jeab-nordsolen-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ns(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['_SMS'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    $n = count($GLOBALS['_SENT']);
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g-ns-' . $n, 'message_id' => 'sent-ns-' . $n . '@local'];
};
$GLOBALS['JEAB_SMS_SEND'] = static function (string $to, string $text, ?string $company = null, ?string $from = null): array {
    $GLOBALS['_SMS'][] = ['to' => $to, 'text' => $text];
    return ['ok' => true];
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);

$enDash = "\u{2013}";
$fixture = implode("\n", [
    'För- och efternamn: Nora Exempel',
    'E-post: nora.exempel@exempel.se',
    'Mobilnummer: +46 (0)70-123 45 67',
    'Gatuadress: Solvägen 12',
    'Postnummer: 17731',
    'Fastighetstyp: Villa',
    'Vill ha batteri.',
]);
$subject = 'Ny lead: Nora Exempel · Villa i Järfälla';
$parsed = jeab_lead_customer([
    'from' => 'partner@nordsolen.se',
    'from_name' => 'Nordsolen',
    'subject' => $subject,
    'body' => $fixture,
]);
ns($parsed['name'] === 'Nora Exempel', 'namnet läses');
ns($parsed['email'] === 'nora.exempel@exempel.se', 'kundmejlet läses');
ns($parsed['email'] !== 'partner@nordsolen.se', 'partneradressen är inte kunden');
$phone = jeab_normalize_reminder_phone($parsed['phone']);
ns($phone === '+46701234567', 'mobilen +46 (0) blir +46701234567');
ns(str_contains($parsed['address'], 'Solvägen 12'), 'gatan läses');
ns(str_contains($parsed['address'], '17731') || str_contains($parsed['address'], '177 31'), 'postnumret läses');
ns(str_contains($parsed['address'], 'Järfälla'), 'orten läses');

$hidden = jeab_lead_customer([
    'from' => 'hanna@nordsolen.se',
    'subject' => $subject,
    'body' => str_replace('+46 (0)70-123 45 67', '070' . $enDash . '123 45 67', $fixture),
]);
ns(jeab_normalize_reminder_phone($hidden['phone']) === '+46701234567', 'mobil med tankstreck läses från hanna@nordsolen.se');
ns(str_contains($hidden['address'], 'Solvägen 12') && str_contains($hidden['address'], 'Järfälla'), 'hanna-mejlet har gata och ort');

$slash = jeab_lead_customer([
    'from' => 'hej@nordsolen.se',
    'subject' => 'Ny lead: Slash Exempel',
    'body' => "För- och efternamn: Slash Exempel\nMobilnummer: +46/070-123 45 67\n",
]);
ns(jeab_normalize_reminder_phone($slash['phone']) === '+46701234567', '+46/0 med snedstreck är ett mobilnummer');

$ortOnly = jeab_lead_customer([
    'from' => 'partner@nordsolen.se',
    'subject' => 'Ny lead: Sara Ort · Villa i Täby',
    'body' => "För- och efternamn: Sara Ort\nE-post: sara.ort@exempel.se\nGatuadress: Björkvägen 3\n",
]);
ns(str_contains($ortOnly['address'], 'Björkvägen 3') && str_contains($ortOnly['address'], 'Täby'), 'orten tas från Villa i ämnet');

$zipOnly = jeab_lead_customer([
    'from' => 'partner@nordsolen.se',
    'subject' => 'Ny lead: Pia Post',
    'body' => "För- och efternamn: Pia Post\nE-post: pia.post@exempel.se\nGatuadress: Ekvägen 4\nPostnummer: 17731\n",
]);
ns(str_contains($zipOnly['address'], 'Ekvägen 4') && str_contains($zipOnly['address'], 'Järfälla'), 'orten tas från postnumret');

foreach (['070-123 45 67', '070 123 45 67', '+46 70 123 45 67', '+46701234567', '0046 70-123 45 67'] as $rawPhone) {
    ns(jeab_normalize_reminder_phone($rawPhone) === '+46701234567', 'giltigt mobilnummer ' . $rawPhone);
}
$landline = false;
try {
    jeab_normalize_reminder_phone('08-123 45 67');
} catch (InvalidArgumentException) {
    $landline = true;
}
ns($landline, 'riktnummer 08 är inte ett mobilnummer');

ns(jeab_lead_bought_partner('partner@nordsolen.se') && jeab_lead_bought_partner('lead@offerta.se') && jeab_lead_bought_partner('hej@hemsol.se'), 'köpta källor räknas som partner');
ns(!jeab_lead_bought_partner('nora.exempel@exempel.se') && !jeab_lead_bought_partner('kund@gmail.com'), 'kundens egen adress är inte partner');

$box = jeab_graph_mailboxes()['jarfalla'];
$labels = ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'batteri', 'lead' => 'kopt', 'product' => 'batteri', 'confidence' => 100, 'question' => ''];
$take = static function (string $id, string $from, string $subj, string $body) use ($box, $labels): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@lead.test',
        'from' => $from,
        'from_name' => 'Lead',
        'to' => $box,
        'subject' => $subj,
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'thread_key' => 'ns-' . $id,
        'graph_mailbox' => $box,
        'company' => 'jarfalla',
        'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass header.from=' . substr(strrchr($from, '@') ?: '', 1)],
        'preset_labels' => $labels,
    ]);
};

$before = count($GLOBALS['_SENT']);
$in = $take('nora', 'partner@nordsolen.se', $subject, $fixture);
ns(!empty($in['ok']), 'nordsolen-lead intaget');
$auto = jeab_auto_on_message((string) $in['id']);
$sent = $GLOBALS['_SENT'][$before] ?? [];
ns(($auto['action'] ?? '') === 'quoted' && ($sent['to'] ?? '') === 'nora.exempel@exempel.se', 'offerten går till kunden');
ns(!str_contains(mb_strtolower((string) ($sent['to'] ?? '')), 'nordsolen.se'), 'offerten går inte till nordsolen');
$noraTid = (string) ($in['thread_id'] ?? '');
$noraBefore = 0;
foreach (jeab_inkorg_threads() as $thread) {
    if ((string) ($thread['id'] ?? '') === $noraTid) {
        $noraBefore = count((array) ($thread['messages'] ?? []));
    }
}

$remindersFor = static function (string $tid): array {
    $rows = [];
    foreach (jeab_list_reminders(jeab_store(), true) as $row) {
        $note = (string) ($row['note'] ?? '');
        if (str_contains($note, $tid) && str_contains($note, 'ring kunden och ta mejladressen')) {
            $rows[] = $row;
        }
    }
    return $rows;
};

$before = count($GLOBALS['_SENT']);
$missingBody = "För- och efternamn: Utan Mejl\nMobilnummer: 070-222 33 44\nGatuadress: Solvägen 1\nPostnummer: 17731\nVill ha batteri.\n";
$missing = $take('utan', 'partner@nordsolen.se', 'Ny lead: Utan Mejl · Villa i Järfälla', $missingBody);
$missingRes = jeab_auto_on_message((string) $missing['id']);
$missingTid = (string) ($missing['thread_id'] ?? '');
ns(count($GLOBALS['_SENT']) === $before && ($missingRes['action'] ?? '') !== 'quoted', 'saknad kundmejl skickar ingen offert');
ns($remindersFor($missingTid) !== [], 'saknad kundmejl påminner om att ringa');

$before = count($GLOBALS['_SENT']);
$partnerBody = "För- och efternamn: Partner Kund\nE-post: lead@offerta.se\nMobilnummer: 070-333 44 55\nGatuadress: Partnergatan 2\nPostnummer: 17731\nVill ha batteri.\n";
$partner = $take('offerta', 'lead@offerta.se', 'Ny lead: Partner Kund · Villa i Järfälla', $partnerBody);
$partnerRes = jeab_auto_on_message((string) $partner['id']);
$partnerTid = (string) ($partner['thread_id'] ?? '');
$partnerTos = [];
foreach (array_slice($GLOBALS['_SENT'], $before) as $row) {
    $partnerTos[] = mb_strtolower((string) ($row['to'] ?? ''));
}
ns(($partnerRes['action'] ?? '') !== 'quoted' && !in_array('lead@offerta.se', $partnerTos, true), 'partneradressen får ingen offert');
ns($remindersFor($partnerTid) !== [], 'partneradress följer regeln att ringa kunden');

$kimSubject = 'Ny lead: Kim Kulle · Villa i Uppsala';
$kimBody = "För- och efternamn: Kim Kulle\nE-post: kim.kulle@exempel.se\nMobilnummer: 070-444 55 66\nGatuadress: Kullvägen 5\nVill ha batteri.\n";
$kim = $take('kim', 'partner@nordsolen.se', $kimSubject, $kimBody);
ns(!empty($kim['ok']), 'andra leadet intaget');
$kimTid = (string) ($kim['thread_id'] ?? '');
jeab_auto_on_message((string) $kim['id']);

$threads = [
    9001 => [[
        'id' => 1,
        'type' => 'customer',
        'createdAt' => '2024-05-01T10:00:00Z',
        'body' => '<p>Kopia av Nora.</p>',
        'createdBy' => ['first' => 'Nora', 'last' => 'Exempel', 'email' => 'partner@nordsolen.se'],
        'customer' => ['id' => 91, 'email' => 'nora.exempel@exempel.se', 'first' => 'Nora', 'last' => 'Exempel'],
    ]],
    9002 => [[
        'id' => 2,
        'type' => 'customer',
        'createdAt' => '2024-05-02T10:00:00Z',
        'body' => '<p>Hej, en helt annan fråga.</p>',
        'createdBy' => ['first' => 'Olga', 'last' => 'Okänd', 'email' => 'olga.okand@post.se'],
        'customer' => ['id' => 92, 'email' => 'olga.okand@post.se', 'first' => 'Olga', 'last' => 'Okänd'],
    ]],
    9003 => [[
        'id' => 3,
        'type' => 'customer',
        'createdAt' => '2024-05-03T10:00:00Z',
        'body' => '<p>För- och efternamn: Kim Kulle</p>',
        'createdBy' => ['first' => 'Kim', 'last' => 'Kulle', 'email' => 'annan.person@post.se'],
        'customer' => ['id' => 93, 'email' => 'annan.person@post.se', 'first' => 'Kim', 'last' => 'Kulle'],
    ]],
];
$customers = [
    91 => ['id' => 91, 'firstName' => 'Nora', 'lastName' => 'Exempel', 'email' => 'nora.exempel@exempel.se'],
    92 => ['id' => 92, 'firstName' => 'Olga', 'lastName' => 'Okänd', 'email' => 'olga.okand@post.se'],
    93 => ['id' => 93, 'firstName' => 'Kim', 'lastName' => 'Kulle', 'email' => 'annan.person@post.se'],
];
$GLOBALS['_JEAB_HS_IMPORT_SLEEP'] = static function (): void {
};
$GLOBALS['_JEAB_HS_IMPORT_NEXT_AT'] = 0;
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function (string $method, string $url) use ($threads, $customers): array {
    if ($method !== 'GET') {
        return ['code' => 405, 'headers' => [], 'body' => ''];
    }
    $path = (string) parse_url($url, PHP_URL_PATH);
    $q = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    if (preg_match('#/conversations/(\d+)/threads$#', $path, $m) === 1) {
        $rows = (int) ($q['page'] ?? 1) === 1 ? ($threads[(int) $m[1]] ?? []) : [];
        return ['code' => 200, 'headers' => [], 'body' => json_encode(['_embedded' => ['threads' => $rows], 'page' => ['totalPages' => 1]])];
    }
    if (preg_match('#/customers/(\d+)$#', $path, $m) === 1) {
        $row = $customers[(int) $m[1]] ?? null;
        $body = $row === null ? '{}' : json_encode($row);
        return ['code' => $row === null ? 404 : 200, 'headers' => [], 'body' => (string) $body];
    }
    return ['code' => 404, 'headers' => [], 'body' => ''];
};

$conv = static function (int $id, string $status, int $customerId, string $email, string $first, string $last, string $subj): array {
    return [
        'id' => $id,
        'status' => $status,
        'subject' => $subj,
        'primaryCustomer' => ['id' => $customerId, 'email' => $email, 'first' => $first, 'last' => $last],
    ];
};
$cp = ['customers' => []];
$tidNora = jeab_hs_import_thread_key('9001');
$firstImport = jeab_hs_import_one($conv(9001, 'active', 91, 'nora.exempel@exempel.se', 'Nora', 'Exempel', 'Kopierad tråd'), 'jarfalla', 111, false, $cp);
ns(($firstImport['conversations'] ?? 0) === 1, 'Help Scout-kopian importeras');
$stored = jeab_hs_import_read_conv($tidNora);
ns(is_array($stored), 'kopian finns lagrad');
ns((string) ($stored['meta']['duplicate_of'] ?? '') === $noraTid, 'kopian pekar på originalet');
ns((string) ($stored['status'] ?? '') === 'closed' && (string) ($stored['meta']['status'] ?? '') === 'closed', 'kopian är stängd');
$notes = [];
$ids = [];
foreach ((array) ($stored['messages'] ?? []) as $message) {
    if (!is_array($message)) {
        continue;
    }
    $ids[] = (string) ($message['id'] ?? '');
    $body = trim((string) ($message['body'] ?? ''));
    if (str_starts_with($body, 'Dubblett av ')) {
        $notes[] = $body;
    }
}
$caption = 'Dubblett av ' . $subject;
ns($notes === [$caption], 'anteckningen namnger originalet');
$view = jeab_hs_import_thread_from_conv_stored($stored);
ns(is_array($view) && (string) ($view['duplicate_of'] ?? '') === $noraTid, 'listan visar länken');
ns(jeab_inkorg_thread_status($view) === 'closed' && jeab_inkorg_in_historik($view) && !jeab_inkorg_folder_thread($view, 'inkorg'), 'länkad kopia ligger i historiken');
ns(empty($stored['meta']['deleted_at']) && empty($view['deleted']), 'kopian raderas inte');

$again = jeab_hs_import_one($conv(9001, 'active', 91, 'nora.exempel@exempel.se', 'Nora', 'Exempel', 'Kopierad tråd'), 'jarfalla', 111, false, $cp);
unset($again);
$storedAgain = jeab_hs_import_read_conv($tidNora);
$notesAgain = [];
$idsAgain = [];
foreach ((array) ($storedAgain['messages'] ?? []) as $message) {
    if (!is_array($message)) {
        continue;
    }
    $idsAgain[] = (string) ($message['id'] ?? '');
    $body = trim((string) ($message['body'] ?? ''));
    if (str_starts_with($body, 'Dubblett av ')) {
        $notesAgain[] = $body;
    }
}
ns($notesAgain === [$caption] && (string) ($storedAgain['status'] ?? '') === 'closed', 'ny import lägger inte en andra anteckning och öppnar inte kopian');
foreach ($ids as $id) {
    ns(in_array($id, $idsAgain, true), 'befintligt meddelande ' . $id . ' finns kvar');
}
$noraAfter = 0;
$noraDeleted = false;
foreach (jeab_inkorg_threads() as $thread) {
    if ((string) ($thread['id'] ?? '') === $noraTid) {
        $noraAfter = count((array) ($thread['messages'] ?? []));
        $noraDeleted = !empty($thread['deleted']);
    }
}
ns($noraAfter === $noraBefore && $noraAfter > 0 && !$noraDeleted, 'originalet är orört');

$cpKim = ['customers' => []];
jeab_hs_import_one($conv(9003, 'active', 93, 'annan.person@post.se', 'Kim', 'Kulle', $kimSubject), 'jarfalla', 111, false, $cpKim);
$kimCopy = jeab_hs_import_read_conv(jeab_hs_import_thread_key('9003'));
ns(is_array($kimCopy) && (string) ($kimCopy['meta']['duplicate_of'] ?? '') === $kimTid, 'samma ämne och namn länkas utan samma mejl');

$cpOther = ['customers' => []];
jeab_hs_import_one($conv(9002, 'active', 92, 'olga.okand@post.se', 'Olga', 'Okänd', 'Hej'), 'jarfalla', 111, false, $cpOther);
$other = jeab_hs_import_read_conv(jeab_hs_import_thread_key('9002'));
$otherNotes = 0;
foreach ((array) ($other['messages'] ?? []) as $message) {
    if (is_array($message) && str_starts_with(trim((string) ($message['body'] ?? '')), 'Dubblett av ')) {
        $otherNotes++;
    }
}
ns(is_array($other) && trim((string) ($other['meta']['duplicate_of'] ?? '')) === '' && $otherNotes === 0 && (string) ($other['status'] ?? '') === 'open', 'en annan tråd länkas inte');

echo "nordsolen_lead_test klar\n";
