<?php

declare(strict_types=1);

/**
 * Bekräftelsemejl går inte när bokningen sparas. Tickern skickar det
 * en gång när bokningen varit orörd minst två timmar (senast ändrad).
 * En ändring innan mejlet gått startar om klockan. En ändring efteråt
 * ger inget nytt mejl. Inget «senaste 4 timmarna»-fönster.
 * SMS förblir påminnelsen «kommer imorgon» inom 18 timmar.
 * Inget riktigt mejl eller SMS lämnar maskinen.
 */

$tmp = sys_get_temp_dir() . '/jeab-cal-notify-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
file_put_contents($tmp . '/config.php', <<<'PHP'
<?php
return [
    'timezone' => 'Europe/Stockholm',
    'outgoing_webhook_url' => '',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => __DIR__ . '/app.sqlite',
        'json_path' => __DIR__ . '/store.json',
    ],
];
PHP);

require dirname(__DIR__) . '/src/bootstrap.php';

function fm_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

date_default_timezone_set('Europe/Stockholm');
$tz = new DateTimeZone('Europe/Stockholm');
$now = new DateTimeImmutable('now', $tz);
$nowSql = $now->format('Y-m-d H:i:s');

$captured = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$captured): array {
    $captured[] = $opts;
    return ['ok' => true, 'path' => 'helpscout', 'error' => null, 'id' => 'stub'];
};

$src = (string) file_get_contents(dirname(__DIR__) . '/src/sms.php');
fm_assert(!function_exists('jeab_booking_jobs_skip_sms'), 'ren sol har ingen skip-funktion');
fm_assert(!str_contains($src, 'jeab_booking_jobs_skip_sms'), 'sms.php hoppar inte över sol');
$code = preg_replace('!/\*.*?\*/!s', '', $src) ?? $src;
$code = preg_replace('/^\s*\/\/.*$/m', '', $code) ?? $code;
fm_assert(!preg_match('/(?<![A-Za-z0-9_])mail\s*\(/', $code), 'sms.php anropar inte PHP mail()');
fm_assert(str_contains($src, 'kommer imorgon'), 'SMS är dokumenterat som imorgon-påminnelse');
$mailSrc = (string) file_get_contents(dirname(__DIR__) . '/src/system_mail.php');
fm_assert(!str_contains(strtolower($mailSrc), 'nylas'), 'systemmejlet nämner inte Nylas');
fm_assert(str_contains($mailSrc, 'function jeab_system_mail_hs_route'), 'personlig From kan fortfarande hoppa över Help Scout');

$sameMinute = ['starts_at' => $nowSql];
$fiveMin = ['starts_at' => $now->modify('-5 minutes')->format('Y-m-d H:i:s')];
$twoHours = ['starts_at' => $now->modify('-2 hours')->format('Y-m-d H:i:s')];
fm_assert(jeab_sms_starts_at_passed($sameMinute, $nowSql), 'SMS ser samma minut som passerad');
fm_assert(!jeab_booking_mail_start_clearly_past($sameMinute, $nowSql), 'mejl släpper samma minut som tickern');
fm_assert(jeab_sms_starts_at_passed($fiveMin, $nowSql), 'SMS hoppar över fem minuter efter start');
fm_assert(!jeab_booking_mail_start_clearly_past($fiveMin, $nowSql), 'mejl har femton minuters respit');
fm_assert(jeab_booking_mail_start_clearly_past($twoHours, $nowSql), 'mejl hoppar över en start två timmar sedan');

$store = jeab_store(true);
$slot = $now->modify('+2 hours');
$minute = (int) $slot->format('i');
$slot = $slot->setTime((int) $slot->format('G'), $minute < 30 ? 0 : 30, 0);
if ($slot <= $now) {
    $slot = $slot->modify('+30 minutes');
}

$created = jeab_create_booking(
    $store,
    'Sam Testström',
    ['Sam W'],
    $slot->format('Y-m-d H:i:s'),
    $slot->modify('+1 hour')->format('Y-m-d H:i:s'),
    'laddbox',
    [
        'company' => 'malmo',
        'assignees' => ['Sam W'],
        'email' => 'kund@kund.se',
        'phone' => '',
    ]
);

fm_assert((string) ($created['company'] ?? '') === 'malmo', 'Sam W sparas som malmo');
fm_assert($captured === [], 'skapandet skickar inget bekräftelsemejl');
fm_assert(!isset($GLOBALS['_JEAB_BOOKING_NOTIFY']), 'skapandet anropar inte kundnotifiering');
fm_assert(
    jeab_system_mail_address('malmo') === 'kontakt@malmo.example.test',
    'Malmö From är kontakt@malmo.example.test'
);
fm_assert(jeab_booking_saved_flash('Bokad.') === 'Bokad.', 'flash lovar inget mejl när inget skickats');
fm_assert(!str_contains(jeab_booking_saved_flash('Bokad.'), 'Bekräftelsemejl'), 'flash säger inte att mejlet redan gått');
fm_assert(!str_contains(jeab_booking_saved_flash('Bokad.'), 'SMS'), 'flash låtsas inte att SMS är bekräftelse');
$indexSrc = (string) file_get_contents(dirname(__DIR__) . '/index.php');
fm_assert(str_contains($indexSrc, "'Bokad.'"), 'sidan kan visa «Bokad.»');
fm_assert(str_contains($indexSrc, "'Uppdaterad.'"), 'sidan kan visa «Uppdaterad.»');
$sent = jeab_sms_sent_row((int) $created['id'], (string) $created['starts_at']);
fm_assert(empty($sent['email']) && empty($sent['sms']), 'sparandet markerar inget som skickat');

$changed = jeab_update_booking($store, (int) $created['id'], [
    'customer' => (string) $created['customer_name'],
    'email' => 'ny-kund@kund.se',
    'phone' => '',
    'assignees' => ['Sam W'],
    'company' => 'malmo',
    'starts_at' => (string) $created['starts_at'],
    'ends_at' => (string) $created['ends_at'],
    'job_type' => 'laddbox',
]);
fm_assert($captured === [], 'ändring av e-post skickar inget bekräftelsemejl');
fm_assert((string) ($changed['email'] ?? '') === 'ny-kund@kund.se', 'ändringen sparades');
fm_assert(jeab_booking_saved_flash('Uppdaterad.') === 'Uppdaterad.', 'ändringsflash lovar inget mejl');

foreach ([
    'src/bookings.php',
    'src/ordrar.php',
    'hook.php',
    'index.php',
] as $rel) {
    $text = (string) file_get_contents(dirname(__DIR__) . '/' . $rel);
    fm_assert(!str_contains($text, 'jeab_booking_notify_customer'), $rel . ' anropar inte kundmejl vid sparande');
}

$ageNow = '2026-09-27 12:00:00';
$freshCreate = ['created_at' => '2026-09-27 11:59:00', 'updated_at' => '2026-09-27 11:59:00'];
$exactCreate = ['created_at' => '2026-09-27 10:00:00', 'updated_at' => '2026-09-27 10:00:00'];
$justUnder = ['created_at' => '2026-09-27 10:00:01', 'updated_at' => '2026-09-27 10:00:01'];
$exactEdit = ['created_at' => '2026-09-26 08:00:00', 'updated_at' => '2026-09-27 10:00:00'];
$youngEdit = ['created_at' => '2026-09-26 08:00:00', 'updated_at' => '2026-09-27 10:00:01'];
fm_assert(jeab_booking_mail_min_age_seconds() === 7200, 'fördröjningen är exakt två timmar');
fm_assert(jeab_booking_mail_due_from() === '1970-01-01 00:00:00', 'mejlfönstret är inte senaste 4 timmarna');
fm_assert(
    jeab_booking_mail_last_changed($exactEdit) === '2026-09-27 10:00:00',
    'senast ändrad är updated_at när den är senare'
);
fm_assert(
    jeab_booking_mail_last_changed(['created_at' => '2026-09-27 10:00:00', 'updated_at' => '']) === '2026-09-27 10:00:00',
    'saknad updated_at faller tillbaka på created_at'
);
fm_assert(!jeab_booking_mail_age_ready($freshCreate, $ageNow), 'en minut gammal skapning är inte redo');
fm_assert(jeab_booking_mail_age_ready($exactCreate, $ageNow), 'exakt två timmar efter created_at är redo');
fm_assert(!jeab_booking_mail_age_ready($justUnder, $ageNow), 'en sekund under två timmar är inte redo');
fm_assert(jeab_booking_mail_age_ready($exactEdit, $ageNow), 'ändring använder updated_at och är redo vid exakt två timmar');
fm_assert(!jeab_booking_mail_age_ready($youngEdit, $ageNow), 'en färsk ändring väntar även om created_at är gammalt');
fm_assert(!jeab_booking_mail_age_ready(['created_at' => '', 'updated_at' => ''], $ageNow), 'tom stämpel skickar inte');
fm_assert(!function_exists('jeab_booking_mail_lookback_seconds'), 'det gamla lookback-fönstret är borta');
fm_assert(!str_contains($src, "modify('-4 hours')"), 'tick har inget 4-timmarsfönster');
$youngNotify = jeab_booking_notify_customer([
    'id' => (int) $created['id'],
    'status' => 'booked',
    'email' => 'kund@kund.se',
    'starts_at' => (string) $created['starts_at'],
    'ends_at' => (string) $created['ends_at'],
    'created_at' => (string) $created['created_at'],
    'updated_at' => (string) $created['updated_at'],
    'job_type' => 'laddbox',
    'company' => 'malmo',
    'customer_name' => 'Sam Testström',
]);
fm_assert(($youngNotify['email']['reason'] ?? '') === 'wait', 'notifiering av färsk bokning väntar');
fm_assert($captured === [], 'notifiering av färsk bokning skickar inget mejl');
fm_assert(jeab_booking_saved_flash('Bokad.') === 'Bokad.', 'väntande mejl ger ingen skickat-flash');

$customer = $store->insertCustomer('Samma minut', jeab_now());
$boundaryEnd = $now->modify('+1 hour')->format('Y-m-d H:i:s');
$boundaryCreated = $now->modify('-3 hours')->format('Y-m-d H:i:s');
$boundary = $store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Samma minut',
    'email' => 'grans@kund.se',
    'phone' => '+46700000001',
    'assignees' => ['Sam W'],
    'company' => 'malmo',
    'job_type' => 'laddbox',
    'starts_at' => $nowSql,
    'ends_at' => $boundaryEnd,
    'status' => 'booked',
    'created_at' => $boundaryCreated,
    'updated_at' => $boundaryCreated,
]);

$pastCustomer = $store->insertCustomer('Klart förbi', jeab_now());
$pastStart = $now->modify('-2 hours')->format('Y-m-d H:i:s');
$pastEnd = $now->modify('-1 hour')->format('Y-m-d H:i:s');
$pastCreated = $now->modify('-90 minutes')->format('Y-m-d H:i:s');
$store->insertBooking([
    'customer_id' => $pastCustomer['id'],
    'customer_name' => 'Klart förbi',
    'email' => 'forbi@kund.se',
    'phone' => '+46700000002',
    'assignees' => ['Sam W'],
    'company' => 'malmo',
    'job_type' => 'batteri',
    'starts_at' => $pastStart,
    'ends_at' => $pastEnd,
    'status' => 'booked',
    'created_at' => $pastCreated,
    'updated_at' => $pastCreated,
]);

$solCustomer = $store->insertCustomer('Ren sol', jeab_now());
$solStart = $slot->modify('+1 day');
if ($solStart <= $now->modify('+18 hours') && $solStart > $now) {
    $solStart = $solStart;
}
if ($solStart >= $now->modify('+18 hours') || $solStart <= $now) {
    $solStart = $now->modify('+8 hours')->setTime((int) $now->modify('+8 hours')->format('G'), 0, 0);
    if ($solStart <= $now || $solStart >= $now->modify('+18 hours')) {
        $solStart = $now->modify('+6 hours');
        $solStart = $solStart->setTime((int) $solStart->format('G'), 0, 0);
    }
}
$sol = $store->insertBooking([
    'customer_id' => $solCustomer['id'],
    'customer_name' => 'Ren sol',
    'email' => '',
    'phone' => '+46700000003',
    'assignees' => ['Abol'],
    'company' => 'malmo',
    'job_type' => 'sol',
    'starts_at' => $solStart->format('Y-m-d H:i:s'),
    'ends_at' => $solStart->modify('+1 hour')->format('Y-m-d H:i:s'),
    'status' => 'booked',
    'created_at' => $now->modify('-1 hour')->format('Y-m-d H:i:s'),
    'updated_at' => $now->modify('-1 hour')->format('Y-m-d H:i:s'),
]);

$later = $now->modify('+3 days')->setTime(9, 0, 0);
$laterEnd = $later->modify('+1 hour')->format('Y-m-d H:i:s');
$laterStart = $later->format('Y-m-d H:i:s');
$ageCustomer = $store->insertCustomer('Ålder', jeab_now());
$insertAged = static function (JeabStore $store, array $customer, string $name, string $email, string $createdAt, string $updatedAt) use ($laterStart, $laterEnd): void {
    $store->insertBooking([
        'customer_id' => $customer['id'],
        'customer_name' => $name,
        'email' => $email,
        'phone' => '',
        'assignees' => ['Sam W'],
        'company' => 'malmo',
        'job_type' => 'laddbox',
        'starts_at' => $laterStart,
        'ends_at' => $laterEnd,
        'status' => 'booked',
        'created_at' => $createdAt,
        'updated_at' => $updatedAt,
    ]);
};
$insertAged($store, $ageCustomer, 'Mogen skapad', 'mogen-skapad@kund.se', $now->modify('-5 hours')->format('Y-m-d H:i:s'), $now->modify('-5 hours')->format('Y-m-d H:i:s'));
$insertAged($store, $ageCustomer, 'For gammal', 'for-gammal@kund.se', $now->modify('-8 hours')->format('Y-m-d H:i:s'), $now->modify('-8 hours')->format('Y-m-d H:i:s'));
$insertAged($store, $ageCustomer, 'Mogen andrad', 'mogen-andrad@kund.se', $now->modify('-2 days')->format('Y-m-d H:i:s'), $now->modify('-3 hours')->format('Y-m-d H:i:s'));
$insertAged($store, $ageCustomer, 'Farsk andrad', 'farsk-andrad@kund.se', $now->modify('-2 days')->format('Y-m-d H:i:s'), $now->modify('-20 minutes')->format('Y-m-d H:i:s'));
$exactTwo = $now->modify('-2 hours')->format('Y-m-d H:i:s');
$insertAged($store, $ageCustomer, 'Exakt tva', 'exakt-tva@kund.se', $exactTwo, $exactTwo);
$justYoung = $now->modify('-2 hours')->modify('+2 minutes')->format('Y-m-d H:i:s');
$insertAged($store, $ageCustomer, 'Knapp tva', 'knapp-tva@kund.se', $justYoung, $justYoung);
$oldPast = $store->insertBooking([
    'customer_id' => $ageCustomer['id'],
    'customer_name' => 'Gammal start',
    'email' => 'gammal-start@kund.se',
    'phone' => '',
    'assignees' => ['Sam W'],
    'company' => 'malmo',
    'job_type' => 'laddbox',
    'starts_at' => $now->modify('-2 hours')->format('Y-m-d H:i:s'),
    'ends_at' => $now->modify('-1 hour')->format('Y-m-d H:i:s'),
    'status' => 'booked',
    'created_at' => $now->modify('-8 hours')->format('Y-m-d H:i:s'),
    'updated_at' => $now->modify('-8 hours')->format('Y-m-d H:i:s'),
]);

$beforeTick = count($captured);
$tick = jeab_sms_tick($store);
fm_assert(($tick['sent_email'] ?? null) === 5, 'tick mejlar allt som varit orört minst två timmar, även äldre än sju timmar');
fm_assert(count($captured) === $beforeTick + 5, 'fem bekräftelsemejl från tickern');
$tickMails = array_slice($captured, $beforeTick);
$tickTo = array_column($tickMails, 'to');
fm_assert(in_array('grans@kund.se', $tickTo, true), 'gränsmejlet går till rätt kund');
$gransMail = null;
foreach ($tickMails as $mail) {
    if (($mail['to'] ?? '') === 'grans@kund.se') {
        $gransMail = $mail;
        break;
    }
}
fm_assert(is_array($gransMail) && ($gransMail['company'] ?? '') === 'malmo', 'gränsmejlet är malmo');
fm_assert(($gransMail['from_email'] ?? '') === 'kontakt@malmo.example.test', 'Malmö-bekräftelse har From kontakt@malmo.example.test');
fm_assert(($tick['sent'] ?? null) === 0, 'inget SMS skickades utan nyckel');
$smsErrors = array_values(array_filter(
    $tick['errors'] ?? [],
    static fn(array $row): bool => ($row['channel'] ?? '') === 'sms'
));
fm_assert($smsErrors === [], 'Malmö-solceller skickar inget SMS');
fm_assert((int) ($sol['id'] ?? 0) > 0, 'solbokningen finns kvar i tickern');
$boundarySms = array_values(array_filter(
    $smsErrors,
    static fn(array $row): bool => (int) ($row['id'] ?? 0) === (int) $boundary['id']
));
fm_assert($boundarySms === [], 'SMS skickas inte för en start som redan är nu');
$recipients = array_column($captured, 'to');
fm_assert(!in_array('forbi@kund.se', $recipients, true), 'klart passerad start får inget tick-mejl');
fm_assert(in_array('mogen-skapad@kund.se', $recipients, true), 'skapning fem timmar sedan mejlas');
fm_assert(in_array('for-gammal@kund.se', $recipients, true), 'skapning åtta timmar sedan mejlas ändå');
fm_assert(in_array('exakt-tva@kund.se', $recipients, true), 'exakt två timmar orörd mejlas');
fm_assert(!in_array('knapp-tva@kund.se', $recipients, true), 'två minuter under två timmar mejlas inte');
fm_assert(in_array('mogen-andrad@kund.se', $recipients, true), 'ändring tre timmar sedan mejlas');
fm_assert(!in_array('farsk-andrad@kund.se', $recipients, true), 'ändring tjugo minuter sedan startar om klockan');
fm_assert(!in_array('ny-kund@kund.se', $recipients, true), 'ändring i samma minut mejlas inte');
fm_assert(!in_array('gammal-start@kund.se', $recipients, true), 'klart passerad start mejlas inte bara för att den är gammal');
fm_assert((int) $oldPast['id'] > 0, 'passerade starten sparades');

$again = jeab_sms_tick($store);
fm_assert(($again['sent_email'] ?? null) === 0, 'andra tickern skickar inget när mejlet redan gått');
fm_assert(count($captured) === $beforeTick + 5, 'ingen dubblett från tickern');

$aged = null;
foreach ($store->listAllBookings() as $row) {
    if (($row['email'] ?? '') === 'for-gammal@kund.se') {
        $aged = $row;
        break;
    }
}
fm_assert(is_array($aged), 'åtta-timmarsbokningen finns');
$sentRow = jeab_sms_sent_store()[(string) $aged['id']] ?? null;
fm_assert(is_array($sentRow) && !empty($sentRow['email']), 'skickat-flaggan ligger i sms-sent.json');
fm_assert(
    (string) ($sentRow['email_version'] ?? '') === (string) $aged['updated_at'],
    'email_version är senast ändrad vid utskicket'
);
$store->saveBooking((int) $aged['id'], [
    'updated_at' => $now->modify('-3 hours')->format('Y-m-d H:i:s'),
    'email' => 'for-gammal-andrad@kund.se',
]);
$afterSentEdit = jeab_sms_tick($store);
fm_assert(($afterSentEdit['sent_email'] ?? null) === 0, 'ändring efter skickat ger inget nytt mejl');
fm_assert(!in_array('for-gammal-andrad@kund.se', array_column($captured, 'to'), true), 'mottagaren byts inte ut i efterhand');

jeab_sms_record_sent((int) $boundary['id'], [
    'starts_at' => $now->modify('+1 day')->format('Y-m-d H:i:s'),
    'sms' => true,
    'sms_at' => $nowSql,
    'email' => false,
], $boundary, 'sms');
$kept = jeab_sms_sent_store()[(string) $boundary['id']] ?? null;
fm_assert(is_array($kept) && !empty($kept['email']), 'SMS-sparning efter flyttad tid nollar inte mejlflaggan');
fm_assert(!empty($kept['sms']), 'SMS-flaggan skrivs fortfarande');
$afterSmsRewrite = jeab_sms_tick($store);
fm_assert(($afterSmsRewrite['sent_email'] ?? null) === 0, 'tickern dubblar inte efter SMS-omskrivning');
fm_assert(count($captured) === $beforeTick + 5, 'fortfarande fem bekräftelsemejl');

echo "calendar notify: ok\n";
