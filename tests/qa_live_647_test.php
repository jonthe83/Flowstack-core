<?php

declare(strict_types=1);

/**
 * Live-QA efter #647. Påhittad data. Inget raderas.
 * Sparad påminnelse syns, Vunnen ligger under Visa mer, personsiffror följer listan,
 * kalenderloggen behåller dagens rad, åäö söks, Hitta-Solceller i Järfälla är Sam L.
 */

$tmp = sys_get_temp_dir() . '/jeab-qa-647-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
require dirname(__DIR__) . '/src/address_lookup.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'demo@example.test';

function qa(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$today = (new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
$email = 'inkorg+test-qa@example.test';

$_GET = ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today', 'tag' => 'none', 'who' => 'Roberto'];
$saved = jeab_reminder_create_action($store, [
    'title' => 'QA-gatan 1',
    'customer' => 'QA Kund',
    'email' => $email,
    'who' => 'Sam L',
    'company' => 'jarfalla',
    'due_at' => $today,
    'tags' => ['admin'],
], false);
$savedId = (int) ($saved['id'] ?? 0);
qa($savedId > 0, 'sparad rad har id');
qa(($_GET['open'] ?? '') === (string) $savedId, 'sparad rad öppnas');
qa(($_GET['who'] ?? '') === 'Sam L', 'filtret följer ansvarig');
qa(($_GET['tag'] ?? '') === 'admin', 'taggen blir #admin, inte Alla taggar');
qa(($_GET['v'] ?? '') === 'today', 'raden landar i Idag');

$listed = jeab_list_reminders($store, true, 'jarfalla', null);
$ids = array_map(static fn (array $row): int => (int) $row['id'], $listed);
qa(in_array($savedId, $ids, true), 'id>0 tar inte bort den nya raden');
$scoped = jeab_filter_reminders_scope($listed, 'jarfalla', jeab_reminder_who_filter());
$visible = jeab_filter_reminders($scoped, jeab_reminder_view(), jeab_reminder_tag_filter(), '');
$visibleIds = array_map(static fn (array $row): int => (int) $row['id'], $visible);
qa(in_array($savedId, $visibleIds, true), 'sparad rad syns i listan efter omladdning');
$found = jeab_filter_reminders($listed, 'all', ['mode' => 'all', 'tag' => null], $email);
qa(array_map(static fn (array $row): int => (int) $row['id'], $found) === [$savedId], 'sök på testadressen hittar raden');
qa(jeab_test_lead_email_reason($email) !== '', 'adressen är en testadress');
qa(in_array($savedId, array_map(static fn (array $row): int => (int) $row['id'], jeab_list_reminders($store, true)), true), 'testvakten gömmer inte påminnelsen');

$return = jeab_reminder_saved_query($saved);
qa((int) ($return['open'] ?? 0) === $savedId && ($return['tag'] ?? '') === 'admin' && ($return['who'] ?? '') === 'Sam L', 'returadressen öppnar samma rad');

$row245 = [
    'id' => 245,
    'title' => 'Vunnen test',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'due_at' => $today . ' 09:00:00',
    'created_at' => $today . ' 08:00:00',
];
$html = jeab_reminder_row($row245, true);
$head = strpos($html, 'item-tags-head');
$panel = strpos($html, 'item-more-panel');
$vunnen = strpos($html, 'data-js="vunnen"');
qa($head !== false && $panel !== false && $vunnen !== false && $vunnen > $panel, 'Vunnen ligger under Visa mer');
qa(!str_contains(substr($html, $head, $panel - $head), '#vunnen'), 'kortets rad har ingen Vunnen-knapp');
qa(str_contains($html, 'data-js="vunnen"') && str_contains($html, 'tag-chip is-primary is-on'), 'Vunnen-knappen är märkt vunnen och fortfarande primär');
$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/paminnelser.css');
qa(str_contains($css, '.item-more-panel .tag-chip.is-primary') && str_contains($css, 'var(--orchid-accent)'), 'primär Vunnen har egen färg under Visa mer');
$narrow = strpos($css, '@media (max-width: 375px)');
qa($narrow !== false && str_contains(substr($css, $narrow, 700), 'flex-wrap: wrap') && str_contains(substr($css, $narrow, 700), 'max-width: 100%'), 'kortets fot får plats vid 375px');

$openJl = ['id' => 1, 'title' => 'A', 'company' => 'jarfalla', 'person' => 'SamL', 'assignee' => 'Sam L', 'assignees' => ['Sam L'], 'due_at' => $today . ' 09:00:00'];
$doneJl = ['id' => 2, 'title' => 'B', 'company' => 'jarfalla', 'person' => 'SamL', 'assignee' => 'Sam L', 'assignees' => ['Sam L'], 'due_at' => $today . ' 09:00:00', 'completed_at' => $today . ' 12:00:00'];
$nimanPerson = ['id' => 3, 'title' => 'C', 'company' => 'jarfalla', 'person' => 'Niman', 'assignee' => '', 'assignees' => [], 'due_at' => $today . ' 09:00:00'];
$nimanName = ['id' => 4, 'title' => 'D', 'company' => 'jarfalla', 'person' => '', 'assignee' => 'Niman', 'assignees' => ['Niman'], 'due_at' => $today . ' 09:00:00'];
$extraW = ['id' => 5, 'title' => 'E', 'company' => 'jarfalla', 'person' => 'SamW', 'assignee' => 'Sam W', 'assignees' => ['Sam W'], 'due_at' => $today . ' 09:00:00'];
$companyRows = [$openJl, $doneJl, $nimanPerson, $nimanName, $extraW];
$options = jeab_reminder_who_options('jarfalla', $companyRows);
qa(in_array('Niman', $options, true) && in_array('Sam L', $options, true) && !in_array('Sam W', $options, true) && !in_array('Kim', $options, true), 'Järfälla visar bara Järfälla');
$malmo = jeab_reminder_who_options('malmo', []);
qa(in_array('Sam W', $malmo, true) && in_array('Kim', $malmo, true) && !in_array('Niman', $malmo, true), 'Malmö visar Sam W och Kim, inte Järfälla');
$tagAll = ['mode' => 'all', 'tag' => null];
$countsAll = jeab_reminder_who_counts($companyRows, 'jarfalla', 'all', $tagAll, '');
$countsDone = jeab_reminder_who_counts($companyRows, 'jarfalla', 'done', $tagAll, '');
qa($countsAll['Niman'] === 2, 'Niman räknas med exakt person-id och namn');
qa(!array_key_exists('Sam W', $countsAll) && !array_key_exists('Kim', $countsAll) && $countsAll['all'] === 5, 'andra kontoret är inte ett chip, men raden räknas i Alla');
qa($countsAll['Sam L'] === 2 && $countsDone['Sam L'] === 1, 'Sam L: öppna och klara');
qa($countsAll['all'] === 5 && $countsAll['all'] >= $countsDone['all'], 'Alla är minst lika med Klara');
foreach ($options as $name) {
    qa($countsAll[$name] <= $countsAll['all'], $name . ' är inte fler än Alla');
}
$listJl = jeab_filter_reminders(jeab_filter_reminders_scope($companyRows, 'jarfalla', 'Sam L'), 'all', $tagAll, '');
qa(count($listJl) === $countsAll['Sam L'], 'personens siffra är listans längd');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_LOG_PDO'] = $pdo;
unset($GLOBALS['_JEAB_CALENDAR_LOG_PATH']);
jeab_calendar_log_write([
    'entries' => [[
        'id' => 1,
        'type' => 'booked',
        'at' => '2026-10-21 16:23:00',
        'booking_id' => 1,
        'starts_at' => '2026-10-21 15:00:00',
        'title' => 'Gammal',
    ]],
    'backfilled' => true,
]);
jeab_calendar_log_append('booked', [
    'id' => 9,
    'starts_at' => '2026-10-22 09:00:00',
    'ends_at' => '2026-10-22 10:00:00',
    'customer_name' => 'Dagens kund',
], '2026-10-22 11:43:00', 'web');
$ats = array_map(static fn (array $entry): string => (string) ($entry['at'] ?? ''), jeab_calendar_log_read()['entries']);
qa(in_array('2026-10-22 11:43:00', $ats, true), 'dagens händelse skrivs och läses');
jeab_calendar_log_write([
    'entries' => [[
        'id' => 1,
        'type' => 'booked',
        'at' => '2026-10-21 16:23:00',
        'booking_id' => 1,
        'starts_at' => '2026-10-21 15:00:00',
        'title' => 'Gammal',
    ]],
    'backfilled' => true,
]);
$after = jeab_calendar_log_read()['entries'];
$afterAts = array_map(static fn (array $entry): string => (string) ($entry['at'] ?? ''), $after);
qa(in_array('2026-10-22 11:43:00', $afterAts, true) && in_array('2026-10-21 16:23:00', $afterAts, true), 'en gammal omskrivning tar inte bort dagens rad');
$todayId = 0;
foreach ($after as $entry) {
    if ((string) ($entry['at'] ?? '') === '2026-10-22 11:43:00') {
        $todayId = (int) $entry['id'];
    }
}
jeab_calendar_log_rows_drop_ids([$todayId]);
$left = array_map(static fn (array $entry): string => (string) ($entry['at'] ?? ''), jeab_calendar_log_read()['entries']);
qa(!in_array('2026-10-22 11:43:00', $left, true) && in_array('2026-10-21 16:23:00', $left, true), 'städning tar bara den utpekade raden');

$street = ['id' => 8, 'title' => 'Riksdalervägen 3', 'company' => 'jarfalla', 'customer_name' => 'Åsa'];
$plain = ['id' => 9, 'title' => 'Raksdalervagen 3', 'company' => 'jarfalla', 'customer_name' => 'Ada'];
$oak = ['id' => 10, 'title' => 'Åkergatan 1', 'company' => 'jarfalla', 'customer_name' => 'Bo'];
$searchRows = [$street, $plain, $oak];
$hitA = array_map(static fn (array $row): int => (int) $row['id'], jeab_filter_reminders($searchRows, 'all', $tagAll, 'ä'));
$hitO = array_map(static fn (array $row): int => (int) $row['id'], jeab_filter_reminders($searchRows, 'all', $tagAll, 'å'));
$hitDot = array_map(static fn (array $row): int => (int) $row['id'], jeab_filter_reminders($searchRows, 'all', $tagAll, 'ö'));
qa($hitA === [8], 'sök ä träffar ä och viker inte till a');
qa($hitO === [8, 10], 'sök å träffar å');
qa($hitDot === [], 'sök ö träffar inte ä eller å');
qa(str_contains(jeab_reminders_url(['q' => 'Riksdalervägen']), '%C3%A4'), 'åäö finns kvar i sökadressen');
$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
qa(str_contains($js, 'compositionend') && str_contains($js, 'document.activeElement !== searchInput'), 'sökrutan skriver inte över åäö medan man skriver');

$hittaBody = "Namn: Test Hitta\nE-post: kund.hitta@exempel.test\nTelefon: 070-100 00 09\nAdress: Riksdalervägen3 3\nPostnummer: 177 57\nOrt: Järfälla\nVi hjälper kunder i Malmö och Skåne.\n";
$hittaMsg = [
    'from' => 'leads@hitta-solceller.se',
    'from_name' => 'Hitta-Solceller',
    'subject' => 'Nytt lead solceller',
    'body' => $hittaBody,
    'body_text' => $hittaBody,
    'labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => 'sol', 'product' => 'sol'],
];
$hitta = [
    'id' => 'qa-hitta-jarfalla',
    'status' => 'open',
    'assignee' => '',
    'deleted' => false,
    'tags' => [],
    'brand' => 'jarfalla',
    'messages' => [$hittaMsg],
    'first' => $hittaMsg,
];
$place = jeab_inkorg_lead_place_text($hitta, $hittaMsg);
qa(!str_contains(mb_strtolower($place), 'malmö') && !str_contains(mb_strtolower($place), 'skåne'), 'köpt lead tar orten från kundens adress');
qa(str_contains($place, 'Riksdalervägen 3'), 'klistrat gatunummer blir ett nummer');
$made = jeab_lead_process($hitta, true);
$leadRem = jeab_get_reminder($store, (int) ($made['reminder'] ?? 0));
qa(is_array($leadRem), 'påminnelsen för det köpta leadet finns kvar');
qa((string) ($leadRem['person'] ?? '') === 'SamL', 'Järfälla sol från Hitta-Solceller är Sam L');
qa(!in_array('Sam W', jeab_assignees_from_row($leadRem), true) && !in_array('Niman', jeab_assignees_from_row($leadRem), true), 'varken Sam W eller Niman får raden');
qa((string) ($leadRem['company'] ?? '') === 'jarfalla' && trim((string) ($leadRem['completed_at'] ?? '')) === '', 'raden stannar öppen i Järfälla');
qa(str_contains((string) ($leadRem['address'] ?? ''), 'Riksdalervägen 3') && !str_contains((string) ($leadRem['address'] ?? ''), 'vägen3'), 'adressen sparas utan dubbelt nummer');
qa(jeab_rot_compose_address(['gata' => 'Riksdalervägen3', 'nummer' => '3']) === 'Riksdalervägen 3', 'sammansatt adress dubblerar inte numret');
qa(jeab_address_parts('Riksdalervägen3 3, Järfälla')['street'] === 'Riksdalervägen 3', 'visningen lagar redan lagrad adress');

echo "ALLA OK\n";
