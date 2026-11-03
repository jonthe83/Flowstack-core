<?php

declare(strict_types=1);

/**
 * N-50: saknad adress/produkt syns vid Vunnen innan klick.
 * Adressen läses från Kontakter. Påhittade testdata. Inga personnummer.
 */

$tmp = sys_get_temp_dir() . '/jeab-vunnen-need-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function vn(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function vn_vunnen_form(string $html): string
{
    $start = strpos($html, 'data-js="vunnen"');
    vn($start !== false, 'Vunnen-formuläret finns');
    $formStart = strrpos(substr($html, 0, $start), '<form');
    vn($formStart !== false, 'Vunnen-formuläret börjar');
    $end = strpos($html, '</form>', $start);
    vn($end !== false, 'Vunnen-formuläret slutar');

    return substr($html, $formStart, $end + 7 - $formStart);
}

vn(jeab_ordrar_vunnen_gap_list([]) === '', 'tom lucklista');
vn(jeab_ordrar_vunnen_gap_list(['adress']) === 'adress', 'en lucka');
vn(jeab_ordrar_vunnen_gap_list(['adress', 'produkt']) === 'adress och produkt', 'två luckor');
vn(
    jeab_ordrar_vunnen_gap_message(['adress', 'produkt']) === 'Ordern skapades inte. Saknar adress och produkt.',
    'klick-meddelandet är oförändrat'
);

$bare = [
    'id' => 50,
    'title' => 'Hej',
    'company' => 'jarfalla',
    'customer_name' => 'QA Tom',
    'email' => 'tom.need@example.test',
    'tags' => [],
];
vn(jeab_ordrar_vunnen_need_label($bare) === 'Saknar adress.', 'utan adress varnas före klick');
vn(jeab_ordrar_vunnen_need_label($bare, true) === '', 'redan vunnen visar ingen lucka');
$bareForm = vn_vunnen_form(jeab_reminder_row($bare, true));
vn(
    !str_contains($bareForm, 'disabled')
        && str_contains($bareForm, 'role="alert"')
        && str_contains($bareForm, 'data-vunnen-need="Ordern skapades inte. Saknar adress."')
        && str_contains($bareForm, 'Ordern skapades inte. Saknar adress.'),
    'Vunnen går att klicka och visar vägran på kortet'
);
vn(!str_contains($bareForm, 'tom.need@example.test') && !str_contains($bareForm, 'QA Tom'), 'lucktexten har ingen kunddata');

$store = jeab_store();
$contact = jeab_kontakt_find_or_create([
    'name' => 'Kim Vunnen',
    'email' => 'kim.vunnen.need@example.test',
    'phone' => '0701000191',
    'address' => 'Vunnenbehovsgatan 4, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
vn(is_array($contact), 'testkontakten finns');

$linked = $store->insertReminder([
    'title' => 'Batteri',
    'company' => 'jarfalla',
    'tags' => [],
    'note' => 'batteri',
]);
$linked = $store->saveReminder((int) $linked['id'], ['contact_id' => (string) $contact['id']]);
$source = jeab_ordrar_vunnen_source($linked);
vn(str_contains((string) ($source['address'] ?? ''), 'Vunnenbehovsgatan'), 'adressen läses från kontakten');
vn(jeab_ordrar_vunnen_need_label($linked) === '', 'kontaktens adress och produkten räcker');
$readyForm = vn_vunnen_form(jeab_reminder_row($linked, true));
vn(!str_contains($readyForm, 'disabled') && !str_contains($readyForm, 'Saknar '), 'redo Vunnen går att klicka');

$emptyContact = jeab_kontakt_find_or_create([
    'name' => 'Kim Tom',
    'email' => 'kim.tom.need@example.test',
    'phone' => '0701000192',
], false);
vn(is_array($emptyContact), 'kontakt utan adress finns');
$copy = $store->insertReminder([
    'title' => 'Batteri',
    'company' => 'jarfalla',
    'address' => 'Kopiegatan 9',
    'tags' => [],
    'note' => 'batteri',
]);
$copy = $store->saveReminder((int) $copy['id'], ['contact_id' => (string) $emptyContact['id']]);
$copyGaps = jeab_ordrar_vunnen_gaps(jeab_ordrar_vunnen_source($copy), $copy);
$copyNeed = jeab_ordrar_vunnen_need_label($copy);
vn(
    $copyNeed === ($copyGaps === [] ? '' : 'Saknar ' . jeab_ordrar_vunnen_gap_list($copyGaps) . '.'),
    'knappen visar samma luckor som klicket'
);

$on = [
    'id' => 51,
    'title' => 'Hej',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
];
$onForm = vn_vunnen_form(jeab_reminder_row($on, true));
vn(str_contains($onForm, 'is-on') && !str_contains($onForm, 'disabled'), 'påslagen Vunnen går att ta bort');

echo "OK  vunnen-need\n";
