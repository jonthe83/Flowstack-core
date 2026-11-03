<?php

declare(strict_types=1);

/**
 * PR D: kundfält läses från kontakten, contact_id krävs när CONTACTS_STRICT är på.
 * Påhittade rader. Inget utskick. Inget personnummer i svaret.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-sole-link-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
unset($GLOBALS['_JEAB_CONTACTS_STRICT'], $GLOBALS['_JEAB_CONTACTS_SOLE']);
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE');
putenv('CONTACTS_STRICT');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'tok-sole-link',
    'outgoing_webhook_url' => '',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);

function lk(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
lk($store instanceof JeabPdoStore, 'sqlite-lagret finns');
lk(jeab_contacts_sole() === true, 'CONTACTS_SOLE är på');
lk(jeab_contacts_strict() === false, 'CONTACTS_STRICT är av som standard');

$ada = jeab_kontakt_find_or_create([
    'name' => 'Ada Link',
    'email' => 'ada.link@example.test',
    'phone' => '0701000301',
    'address' => 'Linkgatan 4, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false, false);
lk(is_array($ada) && trim((string) ($ada['id'] ?? '')) !== '', 'Ada finns');
$adaId = (string) $ada['id'];

$made = jeab_create_reminder($store, 'Ada Link – Batteri', '2026-11-02 09:00:00', 'Ring.', null, [
    'customer' => 'Trad Kopia',
    'email' => 'kopia.link@example.test',
    'phone' => '0701000399',
    'address' => 'Annan gata 1',
    'contact_id' => $adaId,
    'company' => 'jarfalla',
]);
$madeId = (int) ($made['id'] ?? 0);
lk($madeId > 0 && (string) ($made['contact_id'] ?? '') === $adaId, 'påminnelsen pekar på Ada');
lk((string) ($made['customer_name'] ?? '') === 'Trad Kopia' && trim((string) ($made['ort'] ?? '')) === '' && trim((string) ($made['postal'] ?? '')) === '', 'kontakten skrivs inte tillbaka som ort eller postnummer');
$shown = jeab_reminder_shown_customer($made);
lk($shown['name'] === 'Ada Link' && $shown['email'] === 'ada.link@example.test' && $shown['postal'] === '11122' && $shown['city'] === 'Teststad', 'visningen läser kontakten');
lk(!str_contains($shown['name'], 'Trad') && !str_contains($shown['city'], 'Fel'), 'trådkopian visas inte');

$open = jeab_create_reminder($store, 'Utan kontakt', '2026-11-02 10:00:00', null, null, [
    'customer' => 'Bara Rubrik',
    'company' => 'jarfalla',
]);
lk((int) ($open['id'] ?? 0) > 0 && trim((string) ($open['contact_id'] ?? '')) === '', 'utan flaggan får en påminnelse sakna kontakt');
$store->deleteReminders([(int) $open['id']]);

$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;
lk(jeab_contacts_strict() === true, 'flaggan kan slås på');
$refused = null;
$before = count(jeab_list_reminders($store, true));
try {
    jeab_create_reminder($store, 'Saknar kontakt', '2026-11-02 11:00:00', null, null, [
        'customer' => 'Bara Rubrik',
        'company' => 'jarfalla',
    ]);
} catch (InvalidArgumentException $e) {
    $refused = $e->getMessage();
}
lk($refused === 'Påminnelsen saknar kontakt.' && count(jeab_list_reminders($store, true)) === $before, 'strict vägrar påminnelse utan kontakt');

$kept = jeab_create_reminder($store, 'Ada Link – Sol', '2026-11-03 09:00:00', null, null, [
    'contact_id' => $adaId,
    'company' => 'jarfalla',
]);
lk((int) ($kept['id'] ?? 0) > 0 && (string) ($kept['contact_id'] ?? '') === $adaId, 'strict sparar länkad påminnelse');

$clearMsg = null;
try {
    jeab_kontakt_clear_reminder($store, (int) $kept['id']);
} catch (InvalidArgumentException $e) {
    $clearMsg = $e->getMessage();
}
lk($clearMsg === 'Påminnelsen saknar kontakt.' && (string) ($store->getReminder((int) $kept['id'])['contact_id'] ?? '') === $adaId, 'strict tar inte bort kontaktlänken');

$bookMsg = null;
try {
    jeab_create_booking($store, 'Bara Namn', ['Elin'], '2026-11-02 09:00:00', '2026-11-02 10:00:00', 'laddbox', [
        'company' => 'jarfalla',
    ], false);
} catch (InvalidArgumentException $e) {
    $bookMsg = $e->getMessage();
}
lk($bookMsg === 'Bokningen saknar kontakt.', 'strict vägrar bokning utan kontakt');
$logRaw = function_exists('jeab_calendar_log_read') ? json_encode(jeab_calendar_log_read()) : '';
lk(is_string($logRaw) && !str_contains($logRaw, 'Bara Namn'), 'nekad bokning skrivs inte i kalenderloggen');

$book = jeab_create_booking($store, 'Ada Link', ['Elin'], '2026-11-02 13:00:00', '2026-11-02 14:00:00', 'laddbox', [
    'company' => 'jarfalla',
    'contact_id' => $adaId,
    'email' => 'kopia.bok@example.test',
    'address' => 'Felgatan 2',
], false);
lk((int) ($book['id'] ?? 0) > 0 && (string) ($book['contact_id'] ?? '') === $adaId, 'strict sparar bokning med kontakt');
$bookShown = function_exists('jeab_contacts_sole_paint_booking') ? jeab_contacts_sole_paint_booking($book) : $book;
lk((string) ($bookShown['email'] ?? '') === 'ada.link@example.test', 'bokningen läser e-post från kontakten');

$orderMsg = null;
try {
    jeab_purchase_put([
        'reminder_id' => (int) $kept['id'],
        'kind' => 'sol',
        'name' => 'Trad Kopia',
        'email' => 'kopia.order@example.test',
        'company' => 'jarfalla',
    ]);
} catch (InvalidArgumentException $e) {
    $orderMsg = $e->getMessage();
}
lk($orderMsg === 'Ordern saknar kontakt.', 'strict vägrar order utan kontakt');
$order = jeab_purchase_put([
    'reminder_id' => (int) $kept['id'],
    'kind' => 'sol',
    'contact_id' => $adaId,
    'name' => 'Trad Kopia',
    'email' => 'kopia.order@example.test',
    'address' => 'Felgatan 9',
    'company' => 'jarfalla',
]);
lk((string) ($order['contact_id'] ?? '') === $adaId && trim((string) ($order['email'] ?? '')) === '' && trim((string) ($order['name'] ?? '')) === '', 'ordern lagrar inte namn eller e-post');
$orderShown = jeab_purchase_shown($order);
lk((string) ($orderShown['name'] ?? $orderShown['customer_name'] ?? '') === 'Ada Link' && (string) ($orderShown['email'] ?? '') === 'ada.link@example.test', 'ordern läser kunden från kontakten');

$lone = jeab_inkorg_thread_write('thr-lone', static function (array $row): array {
    $row['status'] = 'open';
    $row['company'] = 'jarfalla';

    return $row;
});
lk((string) ($lone['status'] ?? '') === 'open' && trim((string) ($lone['contact_id'] ?? '')) === '', 'inkorg utan påminnelse får sakna kontakt');

$threadReminder = $store->insertReminder([
    'title' => 'Tråd',
    'company' => 'jarfalla',
    'thread_id' => 'thr-needs',
    'due_at' => '2026-11-04 09:00:00',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
lk(jeab_kontakt_stamp_reminder($store, (int) ($threadReminder['id'] ?? 0), $adaId), 'trådens påminnelse får kontakt');
$inboxMsg = null;
try {
    jeab_inkorg_thread_write('thr-needs', static function (array $row): array {
        $row['status'] = 'open';
        $row['company'] = 'jarfalla';
        $row['contact_id'] = '';

        return $row;
    });
} catch (InvalidArgumentException $e) {
    $inboxMsg = $e->getMessage();
}
lk($inboxMsg === null, 'inkorg med påminnelse tar påminnelsens kontakt');
lk((string) (jeab_inkorg_thread_meta('thr-needs')['contact_id'] ?? '') === $adaId, 'ärendet fick påminnelsens contact_id');
$bareRem = $store->insertReminder([
    'title' => 'Tråd utan kontakt',
    'company' => 'jarfalla',
    'thread_id' => 'thr-bare',
    'due_at' => '2026-11-04 09:00:00',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$bareMsg = null;
try {
    jeab_inkorg_thread_write('thr-bare', static function (array $row): array {
        $row['status'] = 'open';
        $row['company'] = 'jarfalla';

        return $row;
    });
} catch (InvalidArgumentException $e) {
    $bareMsg = $e->getMessage();
}
lk($bareMsg === 'Ärendet har en påminnelse men saknar kontakt.', 'inkorg med påminnelse utan kontakt och utan e-post stoppas fortfarande');
jeab_kontakt_stamp_reminder($store, (int) ($bareRem['id'] ?? 0), $adaId);
jeab_inkorg_thread_write('thr-bare', static function (array $row) use ($adaId): array {
    $row['contact_id'] = $adaId;

    return $row;
});
$linkedThread = jeab_inkorg_thread_write('thr-needs', static function (array $row) use ($adaId): array {
    $row['status'] = 'open';
    $row['company'] = 'jarfalla';
    $row['contact_id'] = $adaId;

    return $row;
});
lk((string) ($linkedThread['contact_id'] ?? '') === $adaId, 'inkorg med kontakt och påminnelse sparas');

$labels = jeab_contacts_inbox_apply_labels([
    [
        'id' => 'thr-needs',
        'contact_id' => $adaId,
        'customer_name' => 'Trad Kopia',
        'customer_email' => 'kopia.link@example.test',
        'customer_address' => 'Felgatan 1',
        'ort' => 'Felort',
        'postnr' => '222 33',
    ],
]);
lk((string) ($labels[0]['customer_name'] ?? '') === 'Ada Link' && (string) ($labels[0]['ort'] ?? '') === 'Teststad' && (string) ($labels[0]['postnr'] ?? '') === '111 22', 'inkorgslistan läser namn, ort och postnr från kontakten');
$spamLabels = jeab_contacts_inbox_apply_labels([
    [
        'id' => 'thr-spam',
        'contact_id' => '',
        'customer_name' => 'Spam Person',
        'customer_email' => 'ada.link@example.test',
        'ort' => 'Spamort',
        'postnr' => '999 99',
    ],
]);
lk((string) ($spamLabels[0]['customer_name'] ?? '') === 'Spam Person' && (string) ($spamLabels[0]['ort'] ?? '') === 'Spamort' && trim((string) ($spamLabels[0]['contact_id'] ?? '')) === '', 'inkorg utan kontakt behåller sina kundfält');

$places = jeab_inkorg_fast_list_places([
    [
        'id' => 'thr-needs',
        'contact_id' => $adaId,
        'customer_address' => 'Felgatan 1',
        'customer_city' => 'Felort',
    ],
]);
lk((string) ($places[0]['_list_street'] ?? '') === 'Linkgatan 4' && (string) ($places[0]['_list_city'] ?? '') === 'Teststad' && (string) ($places[0]['postnr'] ?? '') === '111 22', 'listans plats kommer från kontakten');

$bo = jeab_kontakt_find_or_create([
    'name' => 'Bo Ensam',
    'email' => 'bo.ensam@example.test',
    'phone' => '0701000302',
    'address' => 'Ensamgatan 8, 333 44 Testby',
    'city' => 'Testby',
    'postal_code' => '33344',
], false, false);
$boId = is_array($bo) ? (string) ($bo['id'] ?? '') : '';
lk($boId !== '' && $boId !== $adaId, 'en annan person blir en egen kontakt');
$again = jeab_kontakt_find_or_create([
    'name' => 'Bo Ensam',
    'email' => 'bo.ensam@example.test',
    'phone' => '0701000302',
    'address' => 'Ensamgatan 8, 333 44 Testby',
    'city' => 'Testby',
    'postal_code' => '33344',
], false, false);
lk(is_array($again) && (string) ($again['id'] ?? '') === $boId, 'samma person återanvänds utan hopslagning');
$other = jeab_kontakt_find_or_create([
    'name' => 'Cia Annan',
    'email' => 'cia.annan@example.test',
    'phone' => '0701000303',
    'address' => 'Ensamgatan 8, 333 44 Testby',
    'city' => 'Testby',
    'postal_code' => '33344',
], false, false);
lk(is_array($other) && (string) ($other['id'] ?? '') !== $boId && (string) ($other['id'] ?? '') !== '', 'samma adress med annat namn slås inte ihop');

$audit = jeab_contacts_sole_audit($store);
$auditJson = json_encode($audit, JSON_UNESCAPED_UNICODE) ?: '';
lk(!empty($audit['ok']) && ($audit['action'] ?? '') === 'contacts.sole_audit' && ($audit['read_only'] ?? false) === true, 'sole_audit är grön och skrivskyddad');
lk(!str_contains($auditJson, '198501011234') && !str_contains(strtolower($auditJson), 'personnummer') && !preg_match('/\d{12}/', $auditJson), 'auditsvaret har inget personnummer');
$errors = is_array($audit['errors'] ?? null) ? $audit['errors'] : [];
lk((int) ($errors['total'] ?? -1) === 0, 'länkade rader ger inga tomma contact_id');

echo "OK  klart\n";
