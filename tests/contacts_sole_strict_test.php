<?php

declare(strict_types=1);

/**
 * CONTACTS_STRICT och kontakter.gap_fill.
 * Påhittade rader. Inga utskick. Inget personnummer i fellogg eller hook-svar.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-contacts-strict-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
session_save_path($tmp);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
unset($GLOBALS['_JEAB_CONTACTS_STRICT']);
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=0');
putenv('CONTACTS_STRICT');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';
require_once dirname(__DIR__) . '/src/fortnox_order_link.php';
require_once dirname(__DIR__) . '/src/bokmalen_ellevio_fullmakt_webhook.php';
jeab_ordrar_external_live(false);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = [];

function st(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function st_log(): string
{
    $path = jeab_app_errors_path();

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/** @param array<string, mixed> $row */
function st_save_contact(array $row): void
{
    $id = trim((string) ($row['id'] ?? ''));
    $store = jeab_contact_store();
    if (!$store instanceof JeabPdoContactStore || $id === '') {
        fwrite(STDERR, "FAIL kontaktlagret är inte SQL\n");
        exit(1);
    }
    $store->upsert($id, $row);
    if (function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget($id);
    }
}

function st_blank_contact(string $id): void
{
    jeab_kontakter_mutate(static function (array &$contacts) use ($id): void {
        if (!isset($contacts[$id]) || !is_array($contacts[$id])) {
            return;
        }
        $contacts[$id]['email'] = '';
        $contacts[$id]['phone'] = '';
        $contacts[$id]['street'] = '';
        $contacts[$id]['address'] = '';
        $contacts[$id]['city'] = '';
        $contacts[$id]['postal_code'] = '';
        $contacts[$id]['fastighetsbeteckning'] = '';
        $owners = is_array($contacts[$id]['owners'] ?? null) ? $contacts[$id]['owners'] : [];
        if (isset($owners[0]) && is_array($owners[0])) {
            $owners[0]['email'] = '';
            $owners[0]['phone'] = '';
            $owners[0]['address'] = '';
            $owners[0]['personnummer'] = '';
            $contacts[$id]['owners'] = $owners;
        }
    });
    if (function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget($id);
    }
}

$secretMail = 'kopia.gap@example.test';
$secretPnr = '198501011234';
$store = jeab_store();
st($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');

st(jeab_flag('CONTACTS_STRICT') === false, 'CONTACTS_STRICT är av som standard');
$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
st(str_contains($hookSrc, 'kontakter.gap_fill') && str_contains($hookSrc, 'kontakter.strict_report'), 'hook listar gap_fill och strict_report');

file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-strict-test']));
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$denied = jeab_contacts_sole_dispatch(['action' => 'kontakter.gap_fill', 'dry' => 1]);
st(($denied['status'] ?? 0) === 401 && empty($denied['ok']), 'gap_fill utan token nekas');
$deniedReport = jeab_contacts_sole_dispatch(['action' => 'kontakter.strict_report']);
st(($deniedReport['status'] ?? 0) === 401 && empty($deniedReport['ok']), 'strict_report utan token nekas');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-strict-test';
$noConfirm = jeab_flags_set_dispatch(['action' => 'flags.set', 'name' => 'CONTACTS_STRICT', 'value' => 1, 'dry' => 1]);
st(empty($noConfirm['ok']) && str_contains((string) ($noConfirm['error'] ?? ''), 'confirm=SAM_YES'), 'CONTACTS_STRICT=1 kräver confirm');
$confirmed = jeab_flags_set_dispatch(['action' => 'flags.set', 'name' => 'CONTACTS_STRICT', 'value' => 1, 'dry' => 1, 'confirm' => 'SAM_YES']);
st(!empty($confirmed['ok']) && empty($confirmed['written']), 'bekräftad dry skriver inte flaggan');

$made = jeab_kontakt_find_or_create([
    'name' => 'Gap Person',
    'email' => 'gap.person@example.test',
    'phone' => '0701000201',
    'address' => 'Gapgatan 1, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
st(is_array($made) && trim((string) ($made['id'] ?? '')) !== '', 'Gap Person finns');
$gapId = (string) $made['id'];
st_blank_contact($gapId);
$gap = jeab_kontakt_get($gapId);
st(is_array($gap) && jeab_kontakt_row_email($gap) === '' && jeab_kontakt_row_phone($gap) === '', 'kontakten har tom e-post och telefon');

$inboxMade = jeab_kontakt_find_or_create([
    'name' => 'Inkorg Hit',
    'email' => 'inkorg.hit@example.test',
    'phone' => '0701000202',
    'address' => 'Hitgatan 2, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
st(is_array($inboxMade), 'Inkorg Hit finns');
$inboxId = (string) $inboxMade['id'];

$now = jeab_now();
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
$reminder = $store->insertReminder([
    'title' => 'Lucka',
    'customer_name' => 'Kopia Person',
    'email' => $secretMail,
    'phone' => '0701000299',
    'address' => 'Kopiagatan 9, 222 33 Felort',
    'ort' => 'Felort',
    'postal' => '22233',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 09:00:00',
    'thread_id' => 'thr-strict-gap',
    'created_at' => $now,
    'updated_at' => $now,
]);
st(jeab_kontakt_stamp_reminder($store, (int) $reminder['id'], $gapId), 'påminnelsen länkas');
$loose = $store->insertReminder([
    'title' => 'Olänkad',
    'customer_name' => 'Utan Länk',
    'email' => 'utan.lank@example.test',
    'phone' => '0701000288',
    'address' => 'Lankgatan 3',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 10:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$inboxReminder = $store->insertReminder([
    'title' => 'Inkorg',
    'customer_name' => 'Kopia Inkorg',
    'email' => 'kopia.inkorg@example.test',
    'phone' => '0701000277',
    'address' => 'Annat 1',
    'company' => 'jarfalla',
    'due_at' => '2026-10-24 11:00:00',
    'thread_id' => 'thr-strict-inbox',
    'created_at' => $now,
    'updated_at' => $now,
]);
st(jeab_kontakt_stamp_reminder($store, (int) $inboxReminder['id'], $inboxId), 'inkorgspåminnelsen länkas');

$customer = $store->insertCustomer('Kopia Person', $now);
$start = (new DateTimeImmutable('now'))->modify('+2 hours')->format('Y-m-d H:i:s');
$end = (new DateTimeImmutable('now'))->modify('+4 hours')->format('Y-m-d H:i:s');
$booking = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Kopia Person',
    'email' => $secretMail,
    'phone' => '0701000299',
    'address' => 'Kopiagatan 9',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $start,
    'ends_at' => $end,
    'status' => 'booked',
    'created_at' => $now,
    'updated_at' => $now,
]);
$store->pdo()->prepare('UPDATE bookings SET contact_id = ? WHERE id = ?')->execute([$gapId, (int) $booking['id']]);
$booking = $store->getBooking((int) $booking['id']);
st(is_array($booking) && (string) ($booking['contact_id'] ?? '') === $gapId, 'bokningen är länkad');

jeab_purchase_put([
    'reminder_id' => (int) $reminder['id'],
    'contact_id' => $gapId,
    'kind' => 'laddbox',
    'name' => 'Kopia Person',
    'huvudsokande' => 'Kopia Person',
    'customer_name' => 'Kopia Person',
    'email' => $secretMail,
    'phone' => '0701000299',
    'address' => 'Kopiagatan 9, 222 33 Felort',
    'gata' => 'Kopiagatan 9',
    'ort' => 'Felort',
    'postnummer' => '22233',
    'personnummer' => $secretPnr,
    'fastighetsbeteckning' => 'KOPIA 1:9',
    'natbolag' => 'Ellevio',
    'fullmakt_token' => 'tokstrict1',
    'company' => 'jarfalla',
    'pris' => '15000',
    'owners' => [
        ['name' => 'Gap Person', 'personnummer' => $secretPnr, 'email' => $secretMail, 'phone' => '0701000299'],
        ['name' => 'Extra Agare', 'personnummer' => '', 'email' => 'extra.agare@example.test', 'phone' => '0701000266'],
    ],
    'created_at' => $now,
    'updated_at' => $now,
]);
$order = jeab_purchase_get((int) $reminder['id']);
st(is_array($order) && (string) ($order['contact_id'] ?? '') === $gapId, 'ordern är länkad');
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

$beforeCounts = jeab_contacts_sole_counts($store);
$dry = jeab_contacts_gap_fill(['dry' => 1], $store);
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE) ?: '';
st(!empty($dry['ok']) && !empty($dry['dry']) && empty($dry['written']), 'gap_fill dry skriver inget');
st((int) ($dry['gap_rows'] ?? 0) >= 1, 'dry listar minst en lucka');
st(!str_contains($dryJson, $secretMail) && !str_contains($dryJson, $secretPnr) && !str_contains($dryJson, 'personnummer'), 'dry-svaret har inga värden och inget personnummer');
$sawEmail = false;
foreach (is_array($dry['rows'] ?? null) ? $dry['rows'] : [] as $row) {
    if (is_array($row) && (string) ($row['table'] ?? '') === 'reminders' && (int) ($row['id'] ?? 0) === (int) $reminder['id']) {
        $fields = is_array($row['fields'] ?? null) ? $row['fields'] : [];
        $sawEmail = in_array('email', $fields, true);
        st((string) ($row['contact_id'] ?? '') === $gapId, 'luckan pekar på kontakten');
    }
}
st($sawEmail, 'påminnelsens tomma e-post är en lucka');
$page = jeab_contacts_gap_fill(['dry' => 1, 'limit' => 1], $store);
st((int) ($page['gap_rows'] ?? 0) === 1 && trim((string) ($page['next_after_id'] ?? '')) !== '', 'limit ger en rad och next_after_id');
$badAfter = jeab_contacts_gap_fill(['dry' => 1, 'after_id' => 'nej'], $store);
st(empty($badAfter['ok']), 'ogiltigt after_id stoppas');
$report = jeab_contacts_strict_report($store);
st(!empty($report['ok']) && !empty($report['read_only']) && (int) ($report['contacts_strict'] ?? 1) === 0 && (int) ($report['gaps']['rows'] ?? 0) >= 1, 'strict_report visar luckor och att flaggan är av');
$verify = jeab_contacts_sole_verify($store);
st(isset($verify['gaps']['rows']) && (int) $verify['gaps']['rows'] >= 1, 'sole_verify tar med luckantalet');

$storedReminder = $store->getReminder((int) $reminder['id']);
st(is_array($storedReminder), 'påminnelsen kan läsas');
$GLOBALS['_JEAB_CONTACTS_STRICT'] = false;
$offPaint = jeab_contacts_sole_paint_reminder($storedReminder);
st((string) ($offPaint['email'] ?? '') === $secretMail, 'flaggan av behåller påminnelsens e-post när kontakten är tom');
$offBlock = jeab_offert_kundblock($order);
st((string) ($offBlock['email'] ?? '') === $secretMail, 'flaggan av fyller offertens tomma e-post från kopian');
$offRecipient = jeab_contacts_sole_recipient($order);
st((string) ($offRecipient['email'] ?? '') === $secretMail, 'flaggan av använder orderns e-post när kontakten är tom');

$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;
unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE'], $GLOBALS['_JEAB_CONTACTS_SOLE_ORDER_ONLY_LOG'], $GLOBALS['_JEAB_CONTACTS_STRICT_LOG']);
$onPaint = jeab_contacts_sole_paint_reminder($storedReminder);
st((string) ($onPaint['email'] ?? '') === '', 'strict visar tom e-post');
$shown = jeab_reminder_shown_customer($storedReminder);
st((string) ($shown['email'] ?? '') === '' && (string) ($shown['name'] ?? '') === 'Gap Person', 'påminnelsens visning tar inte kopian');
$again = jeab_contacts_sole_paint_reminder($storedReminder);
st((string) ($again['email'] ?? '') === '', 'andra visningen är också tom');
$log = st_log();
st(str_contains($log, 'Påminnelse ' . (int) $reminder['id'] . ' fält email'), 'tomt fält loggas en gång');
st(substr_count($log, 'Påminnelse ' . (int) $reminder['id'] . ' fält email') === 1, 'samma fält loggas inte igen');
$bookingShown = jeab_gp_shown_booking($booking);
st((string) ($bookingShown['email'] ?? '') === '', 'kalendern visar tom e-post');
st(str_contains(st_log(), 'Bokning ' . (int) $booking['id'] . ' fält email'), 'bokningens lucka loggas');
$looseShown = jeab_contacts_sole_paint_reminder($store->getReminder((int) $loose['id']));
st(!empty($looseShown['contact_link_missing']) && (string) ($looseShown['customer_name'] ?? '') === 'Utan Länk', 'olänkad rad behåller kopian och markeras');

$onBlock = jeab_offert_kundblock($order);
st((string) ($onBlock['email'] ?? '') === '', 'strict offert tar inte orderns e-post');
$quoteWhy = jeab_auto_quote_block(['contact_id' => $gapId, 'email' => $secretMail, 'name' => 'Kopia Person'], $secretMail, true);
st(str_contains($quoteWhy, 'e-post') && str_contains($quoteWhy, 'skickades inte'), 'offerten blockeras');
st(str_contains(st_log(), 'Utgående offert blockerades'), 'offerten skrivs i fellogg');

$smsCalls = 0;
$GLOBALS['JEAB_SMS_SEND'] = static function () use (&$smsCalls): array {
    $smsCalls++;

    return ['ok' => true];
};
$mailCalls = 0;
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function () use (&$mailCalls): array {
    $mailCalls++;

    return ['ok' => true];
};
$smsRun = jeab_sms_tick_run($store);
$smsErrors = json_encode($smsRun['errors'] ?? [], JSON_UNESCAPED_UNICODE) ?: '';
st($smsCalls === 0 && str_contains($smsErrors, 'telefon'), 'SMS skickas inte när telefonen saknas');
st(str_contains(st_log(), 'Utgående sms blockerades'), 'SMS skrivs i fellogg');
$mail = jeab_booking_send_customer_mail($booking);
st($mailCalls === 0 && (string) ($mail['reason'] ?? '') === 'strict' && str_contains((string) ($mail['error'] ?? ''), 'e-post'), 'bokningsmejlet blockeras');
st(str_contains(st_log(), 'Utgående email blockerades'), 'mejlet skrivs i fellogg');

$fortnoxThrew = '';
try {
    jeab_ordrar_push_fortnox($order);
} catch (InvalidArgumentException $e) {
    $fortnoxThrew = $e->getMessage();
}
st(str_contains($fortnoxThrew, 'Fortnox') && str_contains($fortnoxThrew, 'skickades inte'), 'Fortnox-skrivningen blockeras');
st(str_contains(st_log(), 'Utgående fortnox blockerades'), 'Fortnox skrivs i fellogg');
$api = new FortnoxAPI('Jarfalla');
$apiThrew = '';
try {
    $api->createKopOrder($order);
} catch (InvalidArgumentException $e) {
    $apiThrew = $e->getMessage();
}
st($apiThrew !== '', 'createKopOrder med kontakt blockeras');
$export = jeab_fortnox_order_export(['account' => 'jarfalla', 'order_id' => (string) $reminder['id'], 'dry' => 1]);
st(empty($export['ok']) && str_contains((string) ($export['error'] ?? ''), 'skickades inte'), 'order_export blockeras');
$street = jeab_fortnox_invoice_compare_order_street($order);
st($street === '', 'strict jämför inte mot orderns gata');
$soleInput = jeab_fortnox_sole_customer_input($order);
st(trim((string) ($soleInput['email'] ?? '')) === '', 'strict Fortnox-input tar inte orderns e-post');

putenv('JEAB_WEBHOOK_CAPTURE=1');
$GLOBALS['_JEAB_WEBHOOKS'] = [];
$store->setSetting('outgoing_webhook_url', 'https://example.test/hook');
$payload = jeab_reminder_payload($storedReminder);
st(!empty($payload['_strict_blocked']) && !isset($payload['email']), 'webhook-payloaden har inga kundfält');
jeab_fire_webhook($store, 'reminder.updated', $payload);
st(($GLOBALS['_JEAB_WEBHOOKS'] ?? []) === [], 'blockerad webhook skickas inte');
st(str_contains(st_log(), 'Utgående webhook blockerades'), 'webhooken skrivs i fellogg');

$ellevioThrew = '';
try {
    jeab_ellevio_fullmakt_start($order, $store, true);
} catch (InvalidArgumentException $e) {
    $ellevioThrew = $e->getMessage();
}
st(str_contains($ellevioThrew, 'Ellevio') && str_contains($ellevioThrew, 'skickades inte'), 'Ellevio-start blockeras');
st(str_contains(st_log(), 'Utgående ellevio blockerades'), 'Ellevio skrivs i fellogg');
$doc = [
    'token' => 'tokstrict1',
    'stamp_method' => 'ellevio',
    'type' => 'ellevio',
    'anlaggnings_id' => '123456789012345678',
    'namn' => 'Dokumentnamn',
    'personnummer' => $secretPnr,
    'fastighetsbeteckning' => 'DOK 9:9',
    'adress' => 'Dokumentgatan 1',
    'order_id' => (int) $reminder['id'],
];
$signed = jeab_bokmalen_ellevio_fullmakt_payload($doc);
st($signed === null, 'signerad fullmakt skickas inte när kontakten saknar fält');
$reloaded = jeab_purchase_get((int) $reminder['id']) ?: $order;
st(jeab_bokmalen_ellevio_fullmakt_pnr($doc, $reloaded) === '', 'fullmakten tar inte personnummer från dokumentet');
st(jeab_bokmalen_ellevio_fullmakt_fastighet($doc, $reloaded) === '', 'fullmakten tar inte fastighet från dokumentet');

$unlinkedBlock = jeab_contacts_strict_block($store->getReminder((int) $loose['id']), 'sms');
st(str_contains($unlinkedBlock, 'skickades inte'), 'olänkad rad blockerar utskick');
st(str_contains(st_log(), 'saknar kontakt'), 'olänkad rad skrivs i fellogg');

$logAll = st_log();
st(!str_contains($logAll, $secretPnr) && !str_contains(strtolower($logAll), 'personnummer'), 'fellogg har varken siffror eller ordet personnummer');

$GLOBALS['_JEAB_CONTACTS_STRICT'] = false;
$apply = jeab_contacts_gap_fill(['dry' => 0], $store);
$applyJson = json_encode($apply, JSON_UNESCAPED_UNICODE) ?: '';
st(!empty($apply['ok']) && !empty($apply['written']) && trim((string) ($apply['batch_id'] ?? '')) !== '', 'gap_fill skriver och ger batch_id');
st(!str_contains($applyJson, $secretMail) && !str_contains($applyJson, $secretPnr), 'skrivsvaret har inga värden');
st(!empty($apply['links_same']), 'länkarna är kvar');
st($apply['counts']['before'] === $beforeCounts && $apply['counts']['after'] === $beforeCounts, 'antalet rader är oförändrat');
st(str_contains(st_log(), 'kontakter.gap_fill skrev'), 'en rad i fellogg för skrivningen');
$filled = jeab_kontakt_get($gapId);
st(is_array($filled) && jeab_kontakt_row_email($filled) === $secretMail, 'tom e-post fylldes från kopian');
st(trim((string) ($filled['fastighetsbeteckning'] ?? '')) === 'KOPIA 1:9', 'tom fastighet fylldes');
$ownerNames = [];
foreach (is_array($filled['owners'] ?? null) ? $filled['owners'] : [] as $owner) {
    if (is_array($owner)) {
        $ownerNames[] = (string) ($owner['name'] ?? '');
        st(trim((string) ($owner['personnummer'] ?? '')) === '', 'ifyllt ägarfält har inget personnummer');
    }
}
st(in_array('Extra Agare', $ownerNames, true), 'saknad ägare lades till');
$keptName = jeab_kontakt_row_name($filled);
st($keptName === 'Gap Person', 'befintligt namn skrevs inte över');
$stillLinked = (string) ($store->getReminder((int) $reminder['id'])['contact_id'] ?? '');
st($stillLinked === $gapId && (string) ($store->getBooking((int) $booking['id'])['contact_id'] ?? '') === $gapId, 'ingen rad tappade länken');

$undo = jeab_contacts_gap_fill(['undo' => 1, 'batch_id' => (string) $apply['batch_id']], $store);
st(!empty($undo['ok']) && !empty($undo['written']), 'undo återställer batchen');
$restored = jeab_kontakt_get($gapId);
st(is_array($restored) && jeab_kontakt_row_email($restored) === '', 'e-posten är tom igen');

$labels = jeab_contacts_inbox_apply_labels([
    [
        'id' => 'thr-strict-inbox',
        'customer_name' => 'Meddelande Person',
        'customer_email' => 'meddelande.person@example.test',
        'phone' => '0701000211',
    ],
    [
        'id' => 'thr-ingen',
        'customer_name' => 'Ny Intake',
        'customer_email' => 'ny.intake@example.test',
        'phone' => '0701000212',
    ],
    [
        'id' => 'thr-epost',
        'customer_name' => 'Fel Namn',
        'customer_email' => 'inkorg.hit@example.test',
        'phone' => '0701000213',
    ],
]);
st((string) ($labels[0]['customer_name'] ?? '') === 'Meddelande Person' && empty($labels[0]['contact_label']) && trim((string) ($labels[0]['contact_id'] ?? '')) === '', 'inkorgsrad utan contact_id behåller sina kundfält');
st((string) ($labels[1]['customer_name'] ?? '') === 'Ny Intake' && empty($labels[1]['contact_label']), 'utan kontakt behålls meddelandets part');
st((string) ($labels[2]['customer_name'] ?? '') === 'Fel Namn' && trim((string) ($labels[2]['contact_id'] ?? '')) === '', 'e-postträff skriver inte över en rad utan kontakt');

$inboxRem = $store->getReminder((int) $inboxReminder['id']);
$rankHit = jeab_follow_reminder_rank($inboxRem, ['email' => 'inkorg.hit@example.test'], [], 'inkorg.hit@example.test', '');
$rankCopy = jeab_follow_reminder_rank($inboxRem, ['email' => 'kopia.inkorg@example.test'], [], 'kopia.inkorg@example.test', '');
st($rankHit === 3 && $rankCopy < 3, 'uppföljning matchar kontaktens e-post, inte kopian');
$resolved = jeab_follow_resolve_party(['id' => 'thr-strict-inbox'], [
    'name' => 'Meddelande Person',
    'email' => 'meddelande.person@example.test',
    'phone' => '0701000211',
]);
st((string) ($resolved['email'] ?? '') === 'inkorg.hit@example.test' && (string) ($resolved['name'] ?? '') === 'Inkorg Hit', 'uppföljningens part kommer från kontakten');

$fastSrc = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_fast.php');
st(str_contains($fastSrc, 'jeab_contacts_inbox_apply_labels'), 'inkorgslistan anropar batch-uppslag');

$phoneRaw = '070-100 02 99 / 073-100 02 98';
st(jeab_norm_phone($phoneRaw) === '' && jeab_contacts_gap_phone($phoneRaw) === '+46701000299', 'bokningens telefon normaliseras som kontaktens fält');
$phoneMade = jeab_kontakt_find_or_create([
    'name' => 'Telefon Lucka',
    'email' => 'telefon.lucka@example.test',
    'phone' => '',
    'address' => 'Luckgatan 2, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
st(is_array($phoneMade), 'telefonkontakten finns');
$phoneId = (string) $phoneMade['id'];
jeab_kontakter_mutate(static function (array &$contacts) use ($phoneId): void {
    if (!isset($contacts[$phoneId]) || !is_array($contacts[$phoneId])) {
        return;
    }
    $contacts[$phoneId]['owners'] = [
        ['name' => 'Telefon Lucka', 'contact' => true, 'email' => 'telefon.lucka@example.test', 'phone' => '', 'personnummer' => ''],
        ['name' => 'Annan Agare', 'contact' => false, 'email' => '', 'phone' => '0701000300', 'personnummer' => ''],
    ];
});
jeab_contacts_sole_cache_forget($phoneId);
$phoneContact = jeab_kontakt_get($phoneId);
st(is_array($phoneContact) && jeab_kontakt_row_phone($phoneContact) === '', 'kontaktägarens telefon är tom trots numret på den andra ägaren');
$phoneCustomer = $store->insertCustomer('Telefon Lucka', $now);
$phoneStart = (new DateTimeImmutable('now'))->modify('+6 hours')->format('Y-m-d H:i:s');
$phoneEnd = (new DateTimeImmutable('now'))->modify('+7 hours')->format('Y-m-d H:i:s');
$phoneBooking = $store->insertBooking([
    'customer_id' => $phoneCustomer['id'],
    'customer_name' => 'Telefon Lucka',
    'email' => 'telefon.lucka@example.test',
    'phone' => $phoneRaw,
    'address' => 'Luckgatan 2, 111 22 Teststad',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $phoneStart,
    'ends_at' => $phoneEnd,
    'status' => 'booked',
    'created_at' => $now,
    'updated_at' => $now,
]);
$store->pdo()->prepare('UPDATE bookings SET contact_id = ? WHERE id = ?')->execute([$phoneId, (int) $phoneBooking['id']]);
$phoneDry = jeab_contacts_gap_fill(['dry' => 1], $store);
$phoneGap = false;
foreach (is_array($phoneDry['rows'] ?? null) ? $phoneDry['rows'] : [] as $row) {
    if (!is_array($row) || (string) ($row['table'] ?? '') !== 'bookings' || (int) ($row['id'] ?? 0) !== (int) $phoneBooking['id']) {
        continue;
    }
    $fields = is_array($row['fields'] ?? null) ? $row['fields'] : [];
    $phoneGap = in_array('phone', $fields, true);
}
st($phoneGap, 'bokningens telefon är en lucka före skrivning');
$keptPhoneMade = jeab_kontakt_find_or_create([
    'name' => 'Telefon Finns',
    'email' => 'telefon.finns@example.test',
    'phone' => '0701000888',
    'address' => 'Finnsgatan 8, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
st(is_array($keptPhoneMade), 'kontakten med telefon finns');
$keptPhoneId = (string) $keptPhoneMade['id'];
$keptBefore = jeab_kontakt_row_phone(jeab_kontakt_get($keptPhoneId) ?: []);
$keptCustomer = $store->insertCustomer('Telefon Finns', $now);
$keptBooking = $store->insertBooking([
    'customer_id' => $keptCustomer['id'],
    'customer_name' => 'Telefon Finns',
    'email' => 'telefon.finns@example.test',
    'phone' => '073-100 08 88 / 070-100 08 87',
    'address' => 'Finnsgatan 8, 111 22 Teststad',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $phoneStart,
    'ends_at' => $phoneEnd,
    'status' => 'booked',
    'created_at' => $now,
    'updated_at' => $now,
]);
$store->pdo()->prepare('UPDATE bookings SET contact_id = ? WHERE id = ?')->execute([$keptPhoneId, (int) $keptBooking['id']]);
$phoneApply = jeab_contacts_gap_fill(['dry' => 0], $store);
st(!empty($phoneApply['ok']) && !empty($phoneApply['written']), 'telefonluckan skrivs');
$phoneFilled = jeab_kontakt_get($phoneId);
st(is_array($phoneFilled) && jeab_kontakt_row_phone($phoneFilled) === '+46701000299', 'kontaktägarens telefon är samma nummer som luckan läser');
$otherPhone = '';
$contactPhone = '';
foreach (is_array($phoneFilled['owners'] ?? null) ? $phoneFilled['owners'] : [] as $owner) {
    if (!is_array($owner)) {
        continue;
    }
    if (!empty($owner['contact'])) {
        $contactPhone = (string) ($owner['phone'] ?? '');
    } else {
        $otherPhone = (string) ($owner['phone'] ?? '');
    }
}
st($contactPhone === '+46701000299' && jeab_kontakt_phone($otherPhone) === jeab_kontakt_phone('0701000300'), 'numret ligger på kontaktägaren och den andra ägaren är orörd');
st(jeab_kontakt_row_phone(jeab_kontakt_get($keptPhoneId) ?: []) === $keptBefore, 'ifylld telefon skrivs inte över');
$phoneAfter = jeab_contacts_gap_fill(['dry' => 1], $store);
$phoneLeft = 0;
foreach (is_array($phoneAfter['rows'] ?? null) ? $phoneAfter['rows'] : [] as $row) {
    if (!is_array($row)) {
        continue;
    }
    $fields = is_array($row['fields'] ?? null) ? $row['fields'] : [];
    if ((string) ($row['table'] ?? '') === 'bookings' && (int) ($row['id'] ?? 0) === (int) $phoneBooking['id'] && in_array('phone', $fields, true)) {
        $phoneLeft++;
    }
}
st($phoneLeft === 0, 'samma bokning har ingen telefonlucka efter skrivningen');

echo "OK  contacts_sole_strict\n";
