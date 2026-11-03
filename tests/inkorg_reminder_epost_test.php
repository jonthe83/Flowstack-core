<?php

declare(strict_types=1);

/** Inkorgspåminnelse: e-post, adress, MEAB-sol som stängd historik. Påhittade kunder. */

$tmp = sys_get_temp_dir() . '/jeab-reminder-epost-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function re(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (): array {
    $GLOBALS['_SENT'][] = true;
    return ['ok' => true];
};

function re_thread(string $id, string $brand, string $offer, string $body, string $fromName, string $fromEmail, string $subject = 'Förfrågan'): array
{
    $msg = [
        'from' => $fromEmail,
        'from_name' => $fromName,
        'subject' => $subject,
        'body' => $body,
        'body_text' => $body,
        'labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => $offer, 'product' => $offer],
    ];
    return [
        'id' => $id,
        'status' => 'open',
        'assignee' => '',
        'deleted' => false,
        'tags' => [],
        'brand' => $brand,
        'messages' => [$msg],
        'first' => $msg,
    ];
}

$jarfalla = re_thread(
    'rem-jarfalla',
    'jarfalla',
    'laddbox',
    "Namn: Kund Jarfalla\nE-post: kund@exempel.test\nTelefon: 070-100 00 01\nAdress: Exempelgatan 16, 177 30 järfälla, Sverige\n",
    'Kund Jarfalla',
    'kund@exempel.test'
);
$made = jeab_lead_process($jarfalla, true);
$rem = jeab_get_reminder(jeab_store(), (int) ($made['reminder'] ?? 0));
re(is_array($rem), 'inkorgen skapar en påminnelse');
$note = (string) ($rem['note'] ?? '');
re((string) ($rem['email'] ?? '') === 'kund@exempel.test', 'E-post är kundens adress');
re(!str_contains($note, 'mail:') && !preg_match('/^\s*(?:Tel|E-post|Adress)\s*:/miu', $note), 'anteckningen har inga tomma etiketter och ingen mail-rad');
re((string) ($rem['address'] ?? '') === 'Exempelgatan 16, Järfälla', 'adressen visas som gata och ort');
re(!str_contains((string) ($rem['address'] ?? ''), 'Sverige'), 'Sverige visas inte');
re((string) ($rem['postal'] ?? '') === '17730' && (string) ($rem['ort'] ?? '') === 'Järfälla', 'postnummer och ort ligger i egna fält');
re((string) ($rem['thread_id'] ?? '') === 'rem-jarfalla', 'ärendet ligger på påminnelsen');
re(count($GLOBALS['_SENT']) === 0, 'inget skickas till kunden');

$uppsala = re_thread(
    'rem-uppsala',
    'malmo',
    'sol',
    "Namn: Kund Uppsala\nE-post: sol.uppsala@exempel.test\nTelefon: 070-100 00 02\nAdress: Exempelgatan 2, 753 21 uppsala, Sverige\n",
    'Kund Uppsala',
    'sol.uppsala@exempel.test',
    'Solceller'
);
$up = jeab_lead_process($uppsala, true);
$upRem = jeab_get_reminder(jeab_store(), (int) ($up['reminder'] ?? 0));
re(is_array($upRem) && (string) ($upRem['company'] ?? '') === 'malmo', 'Malmö-sol får bolaget malmo');
re((string) ($upRem['person'] ?? '') === 'SamW' && in_array('Sam W', jeab_assignees_from_row($upRem), true), 'Malmö-sol tilldelas Sam W');
re(trim((string) ($upRem['completed_at'] ?? '')) !== '', 'Malmö-sol skapas stängd');
re(!in_array('Sam L', jeab_assignees_from_row($upRem), true), 'Malmö-sol blir inte Sam L');
$openIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), jeab_list_reminders(jeab_store(), false));
re(!in_array((int) $upRem['id'], $openIds, true), 'den stängda solen ligger inte bland öppna');

$lund = re_thread(
    'rem-lund',
    'jarfalla',
    'sol',
    "Namn: Kund Lund\nE-post: sol.lund@exempel.test\nTelefon: 070-100 00 03\nAdress: Exempelgatan 4, 222 23 lund, Sverige\n",
    'Kund Lund',
    'sol.lund@exempel.test',
    'Solceller'
);
$lu = jeab_lead_process($lund, true);
$luRem = jeab_get_reminder(jeab_store(), (int) ($lu['reminder'] ?? 0));
re(is_array($luRem) && (string) ($luRem['person'] ?? '') === 'SamW' && trim((string) ($luRem['completed_at'] ?? '')) !== '', 'Skåne-postnummer och sol stängs på Sam W');
re((string) ($luRem['address'] ?? '') === 'Exempelgatan 4, Lund' && (string) ($luRem['postal'] ?? '') === '22223' && (string) ($luRem['ort'] ?? '') === 'Lund', 'Lund skrivs med versal och utan Sverige');
re((string) ($luRem['company'] ?? '') === 'malmo', 'Skåne-sol får bolaget malmo');

$localSol = re_thread(
    'rem-jarfalla-sol',
    'jarfalla',
    'sol',
    "Namn: Kund Vag\nE-post: sol.jarfalla@exempel.test\nAdress: Malmövägen 1, 177 31 Järfälla\n",
    'Kund Vag',
    'sol.jarfalla@exempel.test',
    'Solceller'
);
$local = jeab_lead_process($localSol, true);
$localRem = jeab_get_reminder(jeab_store(), (int) ($local['reminder'] ?? 0));
re(is_array($localRem) && (string) ($localRem['person'] ?? '') === 'SamL' && trim((string) ($localRem['completed_at'] ?? '')) === '', 'Malmövägen i Järfälla förblir en öppen sol till Sam L');

$due = '2026-11-02T08:00:00+01:00';
$openSol = jeab_create_reminder(jeab_store(), 'Kund Oppet – Solceller med batteri', $due, null, null, [
    'customer' => 'Kund Oppet',
    'company' => 'jarfalla',
    'who' => 'Elin',
    'address' => 'Exempelgatan 9, 211 42 Malmö',
    'note' => 'Ring inte.',
]);
$openId = (int) $openSol['id'];
$batteri = jeab_create_reminder(jeab_store(), 'Kund Batteri – Batteri', $due, null, null, [
    'customer' => 'Kund Batteri',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'address' => 'Exempelgatan 8, 211 20 Malmö',
]);
$bookStore = jeab_store();
$bookCustomer = jeab_find_or_create_customer($bookStore, 'Kund Bokad');
$bookRow = $bookStore->insertBooking([
    'customer_id' => $bookCustomer['id'],
    'customer_name' => 'Kund Bokad',
    'email' => 'bokad.sol@exempel.test',
    'address' => 'Exempelgatan 7, Malmö',
    'assignees' => ['Kim'],
    'company' => 'malmo',
    'job_type' => 'sol',
    'starts_at' => '2026-11-02 09:00:00',
    'ends_at' => '2026-11-02 10:00:00',
    'status' => 'booked',
    'created_at' => '2026-11-01 08:00:00',
    'updated_at' => '2026-11-01 08:00:00',
]);
$booked = jeab_create_reminder(jeab_store(), 'Kund Bokad – Solceller med batteri', $due, null, (int) $bookRow['id'], [
    'customer' => 'Kund Bokad',
    'company' => 'malmo',
    'who' => 'Sam L',
    'address' => 'Exempelgatan 7, 211 20 Malmö',
]);
$won = jeab_create_reminder(jeab_store(), 'Kund Vunnen – Solceller med batteri', $due, null, null, [
    'customer' => 'Kund Vunnen',
    'company' => 'malmo',
    'who' => 'Sam L',
    'address' => 'Exempelgatan 6, 211 20 Malmö',
    'tags' => ['vunnen'],
]);
$drySol = jeab_inkorg_hook(['action' => 'inbox.malmo_sol_history']);
$dryJson = (string) json_encode($drySol, JSON_UNESCAPED_UNICODE);
re(($drySol['dry'] ?? 0) === 1 && ($drySol['count'] ?? 0) === 1 && ($drySol['ids'] ?? []) === [$openId], 'torrkörningen listar den öppna MEAB-solen');
re(!str_contains($dryJson, '@') && !preg_match('/\d{6}-?\d{4}/', $dryJson) && !str_contains($dryJson, 'Exempelgatan'), 'torrkörningen har ingen kunddata');
$still = jeab_get_reminder(jeab_store(), $openId);
re(trim((string) ($still['completed_at'] ?? '')) === '' && (string) ($still['due_at'] ?? '') === $due, 'torrkörningen skriver inte');
$wetSol = jeab_inkorg_hook(['action' => 'inkorg.malmo_sol_history', 'dry' => 0]);
$closed = jeab_get_reminder(jeab_store(), $openId);
re(($wetSol['ids'] ?? []) === [$openId] && trim((string) ($closed['completed_at'] ?? '')) !== '', 'dry=0 stänger MEAB-solen');
re((string) ($closed['person'] ?? '') === 'SamW' && (string) ($closed['company'] ?? '') === 'malmo' && (string) ($closed['due_at'] ?? '') === $due, 'Sam W sätts och tiden rörs inte');
$bat = jeab_get_reminder(jeab_store(), (int) $batteri['id']);
$book = jeab_get_reminder(jeab_store(), (int) $booked['id']);
$wonRow = jeab_get_reminder(jeab_store(), (int) $won['id']);
re(trim((string) ($bat['completed_at'] ?? '')) === '' && (string) ($bat['person'] ?? '') !== 'SamW', 'batteri stängs inte av solkroken');
re(trim((string) ($book['completed_at'] ?? '')) === '' && (string) ($book['due_at'] ?? '') === $due, 'bokad tid rörs inte');
re(trim((string) ($wonRow['completed_at'] ?? '')) === '' && (string) ($wonRow['due_at'] ?? '') === $due, 'en vunnen order rörs inte');

$box = 'kontakt@jarfalla.example.test';
$ing = jeab_inkorg_ingest([
    'message_id' => 'fix-1@exempel.test',
    'from' => 'ratt.kund@exempel.test',
    'from_name' => 'Kund',
    'to' => $box,
    'subject' => 'Solceller',
    'body_text' => "Namn: Kund Fix\nE-post: ratt.kund@exempel.test\nTelefon: 070-100 00 09\nAdress: Exempelgatan 16, 211 22 malmö, Sverige\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => $box,
    'thread_key' => 'fix-thread',
    'received_at' => '2026-10-01T08:00:00+02:00',
    'preset_labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'sol', 'source' => 'manual'],
]);
$fixTid = (string) ($ing['thread_id'] ?? 'fix-thread');
$th = jeab_auto_thread_by_id($fixTid);
$url = 'https://flowstack.example.test/index.php?p=inkorg&id=' . rawurlencode($fixTid);
$broken = jeab_create_reminder(jeab_store(), 'Kund Fix – Batteri', $due, null, null, [
    'customer' => 'Kund Fix',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'email' => 'mail: ' . $url,
    'note' => 'E-post: mail: ' . $url,
]);
$oneLine = jeab_create_reminder(jeab_store(), 'Kund Rad – Batteri', $due, null, null, [
    'customer' => 'Kund Rad',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'Tel: E-post: Adress: Exempelgatan 16, 211 22 malmö, Sverige mail: ' . $url,
]);
$noMail = jeab_create_reminder(jeab_store(), 'Kund Tom – Batteri', $due, null, null, [
    'customer' => 'Kund Tom',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'E-post: mail: https://flowstack.example.test/index.php?p=inkorg&id=saknas-helt',
]);
$bookNote = jeab_create_reminder(jeab_store(), 'Kund Tid – Batteri', $due, null, (int) $bookRow['id'], [
    'customer' => 'Kund Tid',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'E-post: mail: ' . $url,
]);
$dryFix = jeab_inkorg_hook(['action' => 'inbox.reminder_epost']);
$dryFixJson = (string) json_encode($dryFix, JSON_UNESCAPED_UNICODE);
$wantIds = [(int) $broken['id'], (int) $oneLine['id'], (int) $noMail['id']];
sort($wantIds);
re(($dryFix['dry'] ?? 0) === 1 && ($dryFix['ids'] ?? []) === $wantIds, 'torrkörningen listar de trasiga anteckningarna');
re(!in_array((int) $bookNote['id'], (array) ($dryFix['ids'] ?? []), true), 'bokad tid listas inte');
re(!str_contains($dryFixJson, '@') && !preg_match('/\d{6}-?\d{4}/', $dryFixJson), 'listan har ingen e-post eller personnummer');
$beforeNote = (string) jeab_get_reminder(jeab_store(), (int) $broken['id'])['note'];
re($beforeNote === 'E-post: mail: ' . $url, 'torrkörningen lämnar texten');

$wetFix = jeab_inkorg_hook(['action' => 'inbox.reminder_notes', 'dry' => 0]);
$fixed = jeab_get_reminder(jeab_store(), (int) $broken['id']);
$fixedLine = jeab_get_reminder(jeab_store(), (int) $oneLine['id']);
$untouched = jeab_get_reminder(jeab_store(), (int) $noMail['id']);
$bookedNote = jeab_get_reminder(jeab_store(), (int) $bookNote['id']);
re((string) ($fixed['email'] ?? '') === 'ratt.kund@exempel.test' && !str_contains((string) ($fixed['note'] ?? ''), 'mail:') && (string) ($fixed['thread_id'] ?? '') === $fixTid, 'dry=0 sätter e-post och ärende');
re((string) ($fixedLine['address'] ?? '') === 'Exempelgatan 16, Malmö' && (string) ($fixedLine['postal'] ?? '') === '21122' && (string) ($fixedLine['ort'] ?? '') === 'Malmö', 'adressen fyller postnummer och ort');
re(!str_contains((string) ($fixedLine['note'] ?? ''), 'Tel:') && !str_contains((string) ($fixedLine['address'] ?? ''), 'Sverige'), 'etiketter och Sverige är borta');
re((string) ($untouched['note'] ?? '') === 'E-post: mail: https://flowstack.example.test/index.php?p=inkorg&id=saknas-helt', 'utan e-post lämnas raden');
$unchangedIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), (array) ($wetFix['unchanged'] ?? []));
re(in_array((int) $noMail['id'], $unchangedIds, true) && (($wetFix['unchanged'][0]['reason'] ?? '') !== '' || $unchangedIds !== []), 'svaret säger att raden lämnades');
re((string) ($bookedNote['note'] ?? '') === 'E-post: mail: ' . $url && (string) ($bookedNote['due_at'] ?? '') === $due, 'bokningen och dess text rörs inte');
re(!str_contains((string) json_encode($wetFix, JSON_UNESCAPED_UNICODE), '@'), 'skrivsvaret har ingen e-post');

$htmlRow = [
    'id' => 9001,
    'title' => 'Kund',
    'customer_name' => 'Kund',
    'email' => 'kund@exempel.test',
    'phone' => '',
    'address' => 'Exempelgatan 16, 211 22 malmö, Sverige',
    'note' => "Hej <script>alert(1)</script>\njavascript:alert(1)\nhttps://exempel.test/sida",
    'thread_id' => 'g-xss',
    'due_at' => $due,
    'completed_at' => null,
    'company' => 'jarfalla',
    'assignee' => 'Roberto',
    'assignees' => ['Roberto'],
];
$html = jeab_reminder_row($htmlRow, false);
re(str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'), 'taggar blir text');
re(!preg_match('/href="javascript:/i', $html), 'javascript blir ingen länk');
// Stängt kort har ingen E-post-rad med mailto. Adressen syns som text.
re(str_contains($html, 'kund@exempel.test') && !str_contains($html, 'href="mailto:'), 'stängda kortet visar e-posten som text');
re(str_contains($html, '>Öppna ärendet<') && str_contains($html, 'p=inkorg'), 'Öppna ärendet finns');
$shutEnd = strpos($html, 'item-head-shut');
$inkorgAt = strpos($html, 'item-inkorg');
$anchorEnd = $shutEnd !== false ? strpos($html, '</a>', $shutEnd) : false;
re($anchorEnd !== false && $inkorgAt !== false && $inkorgAt > $anchorEnd, 'Inkorgsärende ligger utanför radlänken');
re(str_contains($html, 'href="https://exempel.test/sida"'), 'https blir en länk');
re(str_contains($html, 'Exempelgatan 16') && str_contains($html, 'Malmö') && !str_contains($html, 'Sverige'), 'raden visar orten utan Sverige');

echo "inkorg reminder epost ok\n";
