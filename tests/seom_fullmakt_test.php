<?php

declare(strict_types=1);

/**
 * SEOM-blankett: fält från ordern, PDF, mejl till Sam.
 * Kundutskick kräver SEOM_CUSTOMER_SEND och ett eget anrop.
 * Personnummer får inte hamna i logg eller hook.
 */

$tmp = sys_get_temp_dir() . '/jeab-seom-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempdir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('SEOM_CUSTOMER_SEND');
unset($_ENV['SEOM_CUSTOMER_SEND'], $GLOBALS['_JEAB_SEOM_CUSTOMER_SEND']);
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
$GLOBALS['SEOM_MAILS'] = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['SEOM_MAILS'][] = $opts;
    return ['ok' => true, 'path' => 'helpscout', 'error' => null, 'id' => 'hs-seom'];
};

require dirname(__DIR__) . '/src/bootstrap.php';

function seom_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function seom_row(array $extra = []): array
{
    static $seq = 8800;
    $seq++;
    $row = array_merge([
        'reminder_id' => $seq,
        'kind' => 'sol',
        'company' => 'jarfalla',
        'natbolag' => 'SEOM',
        'huvudsokande' => 'Ada Adolfsson',
        'name' => 'Ada Adolfsson',
        'personnummer' => '19700101-0101',
        'phone' => '0701234567',
        'email' => 'ada@example.com',
        'huvudsokande_address' => 'Flottarestigen 6, 192 51 Sollentuna',
        'fastighetsbeteckning' => 'Sollentuna Lertavlan 4',
        'lgh' => '1102',
        'matarnummer' => 'M-44',
        'anlaggnings_id' => '735999114010135815',
        'fortnox_order' => 'OFF-77',
        'install_date' => '2026-11-02',
        'kwh' => '10',
        'foranmalan_status' => 'A',
    ], $extra);
    return $row;
}

function seom_pdf_has(string $pdf, string $utf8): bool
{
    $latin = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $utf8);
    if ($latin !== false && str_contains($pdf, $latin)) {
        return true;
    }
    return str_contains($pdf, $utf8);
}

$pnr = '19700101-0101';
seom_assert(in_array('SEOM', jeab_ordrar_natbolag_options(), true), 'SEOM är ett nätbolag');
seom_assert(jeab_seom_customer_send_enabled() === false, 'SEOM_CUSTOMER_SEND är av som standard');

$fields = jeab_seom_fullmakt_fields(seom_row(['reminder_id' => 42]));
seom_assert($fields['referensnummer'] === 'JEAB-42', 'referensnummer från ordern');
seom_assert($fields['offertnummer'] === 'OFF-77', 'offertnummer från ordern');
seom_assert($fields['produktion_typ_a'] === true, 'Produktion typ A är ikryssad för sol');
seom_assert($fields['adress'] === 'Flottarestigen 6', 'anläggningsadress');
seom_assert($fields['lgh'] === '1102', 'lgh nr');
seom_assert($fields['anlaggnings_id'] === '735999114010135815', 'anläggnings-id');
seom_assert($fields['postnummer'] === '192 51', 'postnummer');
seom_assert($fields['ort'] === 'Sollentuna', 'ort');
seom_assert($fields['matarnummer'] === 'M-44', 'mätarnummer');
seom_assert($fields['fastighetsbeteckning'] === 'Sollentuna Lertavlan 4', 'fastighetsbeteckning');
seom_assert(($fields['kund']['namn'] ?? '') === 'Ada Adolfsson', 'kundens namn');
seom_assert(($fields['kund']['personnummer'] ?? '') === $pnr, 'kundens personnummer på blanketten');
seom_assert(($fields['kund']['adress'] ?? '') === 'Flottarestigen 6', 'kundens utdelningsadress');
seom_assert(($fields['kund']['telefon'] ?? '') === '0701234567', 'kundens telefon');
seom_assert(($fields['kund']['mejl'] ?? '') === 'ada@example.com', 'kundens mejl');
seom_assert(($fields['fastighetsagare']['namn'] ?? '') === 'Ada Adolfsson', 'fastighetsägare är kunden');
seom_assert($fields['ager_fastighet'] === 'ja', 'äger fastighet ja');
seom_assert($fields['kundanlaggning'] === 'Enbostadshus', 'enbostadshus');
seom_assert($fields['servissakring'] === '25 A', 'servissäkring 25 A');
seom_assert($fields['trefas'] === '1', '3-fas abonnemang befintlig 1');
seom_assert($fields['fardig_datum'] === '2026-11-02', 'beräknad färdig anläggning');
$inst = $fields['installator'];
seom_assert(
    ($inst['foretag'] ?? '') === 'Demo Energi Järfälla'
    && ($inst['adress'] ?? '') === 'Dimvägen 38'
    && ($inst['postnummer'] ?? '') === '17770'
    && ($inst['ort'] ?? '') === 'Järfälla'
    && ($inst['telefon'] ?? '') === '08-0000002'
    && ($inst['orgnr'] ?? '') === '559999-0000'
    && ($inst['handlaggare'] ?? '') === 'Sam Exempel',
    'installatören är Demo Energi Järfälla / Sam'
);
$battery = jeab_seom_fullmakt_fields(seom_row(['kind' => 'batteri']));
seom_assert($battery['produktion_typ_a'] === true, 'Produktion typ A är ikryssad för batteri');

$pdf = jeab_seom_fullmakt_pdf($fields);
seom_assert(str_starts_with($pdf, '%PDF'), 'PDF-huvud');
seom_assert(preg_match_all('#/Type /Page(?!s)#', $pdf) === 2, 'två sidor');
foreach ([
    'Sollentuna Elnät AB',
    'Box 972',
    '191 29 Sollentuna',
    '08-000 00 07',
    'FÖRANMÄLAN ELINSTALLATION',
    'INSTALLATIONSMEDGIVANDE',
    'FÄRDIGANMÄLAN AV ELINSTALLATION',
    'Produktion typ A',
    'Enbostadshus',
    '735999114010135815',
    '559999-0000',
    '08-0000002',
    'Dimvägen 38',
    '17770',
    'Sam Exempel',
    'Demo Energi Järfälla',
    'Flottarestigen 6',
    'OFF-77',
    'JEAB-42',
] as $needle) {
    seom_assert(seom_pdf_has($pdf, $needle), 'PDF innehåller ' . $needle);
}

$GLOBALS['SEOM_MAILS'] = [];
$blocked = false;
try {
    jeab_seom_fullmakt_send_customer(seom_row([
        'seom_sam_sent_at' => '2026-01-01 08:00:00',
    ]));
} catch (InvalidArgumentException $e) {
    $blocked = str_contains($e->getMessage(), 'SEOM_CUSTOMER_SEND');
}
seom_assert($blocked, 'kundutskick stoppas när flaggan är av');
seom_assert($GLOBALS['SEOM_MAILS'] === [], 'avstängt kundutskick anropar inte mejlet');

$missingMail = count($GLOBALS['SEOM_MAILS']);
$missingThrew = false;
try {
    jeab_seom_fullmakt_start(seom_row(['anlaggnings_id' => '']));
} catch (InvalidArgumentException $e) {
    $missingThrew = str_contains($e->getMessage(), 'Anläggnings-id krävs');
}
seom_assert($missingThrew, 'saknat anläggnings-id stoppar blanketten');
seom_assert(count($GLOBALS['SEOM_MAILS']) === $missingMail, 'saknat id skickar inget mejl');

$stored = jeab_purchase_put(seom_row());
$GLOBALS['SEOM_MAILS'] = [];
$GLOBALS['_JEAB_SEOM_HOOKS'] = [];
$started = jeab_ordrar_foranmalan_nasta(null, $stored);
seom_assert(count($GLOBALS['SEOM_MAILS']) === 1, 'ett mejl till Sam');
seom_assert(($GLOBALS['SEOM_MAILS'][0]['to'] ?? '') === 'demo@example.test', 'mottagaren är Sam');
seom_assert(($GLOBALS['SEOM_MAILS'][0]['to'] ?? '') !== 'ada@example.com', 'kunden får inte mejlet vid starten');
$att = $GLOBALS['SEOM_MAILS'][0]['attachments'][0]['content'] ?? '';
seom_assert(is_string($att) && str_starts_with($att, '%PDF'), 'mejlet har PDF-bilaga');
seom_assert(jeab_seom_fullmakt_status($started) === 'sam_skickad', 'status sam_skickad');
seom_assert(trim((string) ($started['seom_sam_sent_at'] ?? '')) !== '', 'utsändning stämplas');
$hooks = $GLOBALS['_JEAB_SEOM_HOOKS'] ?? [];
seom_assert(is_array($hooks) && count($hooks) === 1 && ($hooks[0]['event'] ?? '') === 'seom.fullmakt_status', 'hook seom.fullmakt_status');
seom_assert(($hooks[0]['status'] ?? '') === 'sam_skickad', 'hooken bär status');
$hookJson = json_encode($hooks, JSON_UNESCAPED_UNICODE);
seom_assert(is_string($hookJson) && !str_contains($hookJson, $pnr) && !str_contains($hookJson, 'personnummer'), 'hooken saknar personnummer');

$again = jeab_ordrar_foranmalan_nasta(null, $started);
seom_assert(count($GLOBALS['SEOM_MAILS']) === 1, 'Nästa en gång till mejlar inte om');

$GLOBALS['_JEAB_SEOM_CUSTOMER_SEND'] = true;
$GLOBALS['SEOM_MAILS'] = [];
$still = jeab_seom_fullmakt_start($started);
seom_assert(count($GLOBALS['SEOM_MAILS']) === 0, 'flaggan slår inte på automatiskt kundutskick');
unset($GLOBALS['_JEAB_SEOM_CUSTOMER_SEND']);

$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['SEOM_MAILS'][] = $opts;
    return ['ok' => false, 'path' => '', 'error' => 'avvisad ' . '19700101-0101', 'id' => ''];
};
$GLOBALS['SEOM_MAILS'] = [];
$logged = false;
try {
    jeab_seom_fullmakt_start(seom_row(['anlaggnings_id' => '735999114010135815']), true);
} catch (InvalidArgumentException $e) {
    $logged = !str_contains($e->getMessage(), $pnr);
}
seom_assert($logged, 'feltexten till användaren saknar personnummer');
$log = (string) @file_get_contents($tmp . '/app-errors.jsonl');
seom_assert($log !== '' && !str_contains($log, $pnr), 'felloggen saknar personnummer');

$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['SEOM_MAILS'][] = $opts;
    return ['ok' => true, 'path' => 'graph', 'error' => null, 'id' => 'g-seom'];
};
$signedRow = jeab_purchase_put(seom_row([
    'seom_sam_sent_at' => '2026-01-01 08:00:00',
    'seom_fullmakt_status' => 'sam_skickad',
]));
$GLOBALS['SEOM_MAILS'] = [];
$GLOBALS['_JEAB_SEOM_HOOKS'] = [];
$signed = jeab_seom_fullmakt_mark_customer_signed($signedRow);
seom_assert(jeab_seom_fullmakt_status($signed) === 'kund_signerad', 'kundens signering sparas');
$signedHooks = $GLOBALS['_JEAB_SEOM_HOOKS'] ?? [];
$events = array_map(static fn ($h) => (string) ($h['event'] ?? ''), is_array($signedHooks) ? $signedHooks : []);
seom_assert(in_array('seom.customer_signed', $events, true), 'hook seom.customer_signed');
seom_assert(in_array('seom.fullmakt_status', $events, true), 'signering uppdaterar seom.fullmakt_status');
$signedJson = json_encode($signedHooks, JSON_UNESCAPED_UNICODE);
seom_assert(is_string($signedJson) && !str_contains($signedJson, $pnr), 'signerings-hooken saknar personnummer');
seom_assert($GLOBALS['SEOM_MAILS'] === [], 'signering skickar inget mejl');

$GLOBALS['_JEAB_SEOM_CUSTOMER_SEND'] = true;
$GLOBALS['SEOM_MAILS'] = [];
$GLOBALS['_JEAB_SEOM_HOOKS'] = [];
$toCustomer = jeab_seom_fullmakt_send_customer($signedRow);
seom_assert(count($GLOBALS['SEOM_MAILS']) === 1 && ($GLOBALS['SEOM_MAILS'][0]['to'] ?? '') === 'ada@example.com', 'manuell knapp mejlar kunden när flaggan är på');
seom_assert(jeab_seom_fullmakt_status($toCustomer) === 'kund_skickad', 'status efter kundutskick');
$customerHook = json_encode($GLOBALS['_JEAB_SEOM_HOOKS'] ?? [], JSON_UNESCAPED_UNICODE);
seom_assert(is_string($customerHook) && !str_contains($customerHook, $pnr), 'kundutskickets hook saknar personnummer');
unset($GLOBALS['_JEAB_SEOM_CUSTOMER_SEND']);

$denied = jeab_seom_fullmakt_status_dispatch(['order_id' => (int) $signed['reminder_id']]);
seom_assert(($denied['status'] ?? 0) === 401 && empty($denied['ok']), 'status-hooken kräver token');
$deniedSign = jeab_seom_customer_signed_dispatch(['order_id' => (int) $signed['reminder_id']]);
seom_assert(($deniedSign['status'] ?? 0) === 401, 'signerings-hooken kräver token');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/seom_fullmakt.php');
$pdfSrc = (string) file_get_contents(dirname(__DIR__) . '/src/seom_pdf.php');
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
seom_assert(str_contains($src, 'jeab_system_mail_send'), 'utskick går via systemmejlet');
seom_assert(!preg_match('/\bmail\s*\(/', $src), 'systemmejlets PHP-mail används inte');
seom_assert(!str_contains($src, 'foranmalan.nu') && !str_contains($pdfSrc, 'foranmalan.nu'), 'ingen portal-automation');
seom_assert(str_contains($hookSrc, "'seom.fullmakt_status'") && str_contains($hookSrc, "'seom.customer_signed'"), 'hook.php tar båda punkterna');

unset($GLOBALS['_JEAB_SEOM_CUSTOMER_SEND']);
$panel = jeab_seom_fullmakt_panel_html($signedRow, '/index.php');
seom_assert(str_contains($panel, 'disabled') && str_contains($panel, 'SEOM_CUSTOMER_SEND'), 'knappen till kunden är avstängd');
$GLOBALS['_JEAB_SEOM_CUSTOMER_SEND'] = true;
$panelOn = jeab_seom_fullmakt_panel_html($signedRow, '/index.php');
seom_assert(str_contains($panelOn, 'Skicka till kunden') && !str_contains($panelOn, 'disabled'), 'påslagen flagga gör knappen klickbar');

echo "seom fullmakt: ok\n";
