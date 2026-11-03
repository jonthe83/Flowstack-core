<?php

declare(strict_types=1);

/**
 * Solceller: kalendermejl till kund är av när nyckeln saknas.
 * Batteri och Elbilsladdare skickas. Inget riktigt mejl lämnar maskinen.
 */

$tmp = sys_get_temp_dir() . '/jeab-sol-cal-mail-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function sc(bool $ok, string $message): void
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
$aged = $now->modify('-3 hours')->format('Y-m-d H:i:s');
$future = $now->modify('+10 days')->setTime(9, 0, 0);
$start = $future->format('Y-m-d H:i:s');
$end = $future->modify('+4 hours')->format('Y-m-d H:i:s');

$captured = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$captured): array {
    $captured[] = $opts;
    return ['ok' => true, 'path' => 'graph', 'error' => null, 'id' => 'stub'];
};
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $opts) use (&$captured): array {
    $captured[] = $opts + ['via' => 'graph-send'];
    return ['ok' => true, 'via' => 'test'];
};

sc(jeab_auto_settings()['sol_calendar_mail_off'] === true, 'saknad nyckel är på');
$regler = (string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg_regler.php');
sc(substr_count($regler, 'name="sol_calendar_mail_off"') === 1, 'kryssrutan finns på Regler');
sc(str_contains($regler, 'Solceller: stäng av kalendermejl till kund'), 'kryssrutan har rätt text');
$seed = jeab_auto_quote_seed();
sc(isset($seed['sol_jarfalla']) && trim((string) $seed['sol_jarfalla']) !== '', 'solmallen finns kvar');

$base = [
    'status' => 'booked',
    'customer_name' => 'Test Kund',
    'starts_at' => $start,
    'ends_at' => $end,
    'created_at' => $aged,
    'updated_at' => $aged,
];

$cases = [
    ['id' => 1, 'company' => 'jarfalla', 'job_type' => 'sol', 'assignee' => 'Roberto', 'email' => 'sol-saljare3@kund.se'],
    ['id' => 2, 'company' => 'jarfalla', 'job_type' => 'sol', 'assignee' => 'Sam L', 'email' => 'sol-jl@kund.se'],
    ['id' => 3, 'company' => 'malmo', 'job_type' => 'sol', 'assignee' => 'Sam W', 'email' => 'sol-jw@kund.se'],
    ['id' => 4, 'company' => 'jarfalla', 'job_type' => 'sol,batteri', 'assignee' => 'Sam L', 'email' => 'sol-batteri@kund.se'],
    ['id' => 5, 'company' => 'malmo', 'job_type' => 'batteri', 'product' => 'solceller', 'assignee' => 'Sam W', 'email' => 'produkt-sol@kund.se'],
    ['id' => 6, 'company' => 'jarfalla', 'job_type' => 'batteri', 'assignee' => 'Roberto', 'email' => 'batteri@kund.se'],
    ['id' => 7, 'company' => 'jarfalla', 'job_type' => 'laddbox', 'assignee' => 'Elin', 'email' => 'laddbox@kund.se'],
    ['id' => 8, 'company' => 'malmo', 'job_type' => 'batteri', 'assignee' => 'Kim', 'email' => 'meab-batteri@kund.se'],
    ['id' => 9, 'company' => 'malmo', 'job_type' => 'laddbox', 'assignee' => 'Kim', 'email' => 'meab-ladd@kund.se'],
];

foreach ($cases as $case) {
    $row = $base + $case;
    $notify = jeab_booking_notify_customer($row);
    $reason = (string) ($notify['email']['reason'] ?? '');
    $sol = in_array($case['email'], [
        'sol-saljare3@kund.se',
        'sol-jl@kund.se',
        'sol-jw@kund.se',
        'sol-batteri@kund.se',
        'produkt-sol@kund.se',
    ], true);
    if ($sol) {
        sc($reason === 'skip' && empty($notify['email']['sent']), $case['email'] . ' hoppas över');
    } else {
        sc($reason === 'sent' && !empty($notify['email']['sent']), $case['email'] . ' skickas');
    }
}

$sentTo = array_column($captured, 'to');
sc(!in_array('sol-saljare3@kund.se', $sentTo, true), 'Roberto i Järfälla får inget solmejl');
sc(!in_array('sol-jl@kund.se', $sentTo, true), 'Sam L i Järfälla får inget solmejl');
sc(!in_array('sol-jw@kund.se', $sentTo, true), 'Sam W i Malmö får inget solmejl');
sc(in_array('batteri@kund.se', $sentTo, true), 'batteri skickas');
sc(in_array('laddbox@kund.se', $sentTo, true), 'elbilsladdare skickas');
sc(in_array('meab-batteri@kund.se', $sentTo, true) && in_array('meab-ladd@kund.se', $sentTo, true), 'Malmö batteri och elbilsladdare skickas');

$beforeTick = count($captured);
$store = jeab_store();
$customer = $store->insertCustomer('Sol Tick', jeab_now());
$store->insertBooking([
    'customer_id' => $customer['id'],
    'customer_name' => 'Sol Tick',
    'email' => 'tick-sol@kund.se',
    'phone' => '',
    'assignees' => ['Sam L'],
    'company' => 'jarfalla',
    'job_type' => 'sol',
    'starts_at' => $start,
    'ends_at' => $end,
    'status' => 'booked',
    'created_at' => $aged,
    'updated_at' => $aged,
]);
$batCustomer = $store->insertCustomer('Batteri Tick', jeab_now());
$store->insertBooking([
    'customer_id' => $batCustomer['id'],
    'customer_name' => 'Batteri Tick',
    'email' => 'tick-batteri@kund.se',
    'phone' => '',
    'assignees' => ['Roberto'],
    'company' => 'jarfalla',
    'job_type' => 'batteri',
    'starts_at' => $future->modify('+1 day')->format('Y-m-d H:i:s'),
    'ends_at' => $future->modify('+1 day')->modify('+4 hours')->format('Y-m-d H:i:s'),
    'status' => 'booked',
    'created_at' => $aged,
    'updated_at' => $aged,
]);
$tick = jeab_sms_tick($store);
$tickTo = array_column(array_slice($captured, $beforeTick), 'to');
sc(!in_array('tick-sol@kund.se', $tickTo, true), 'tickern mejlar inte sol');
sc(in_array('tick-batteri@kund.se', $tickTo, true), 'tickern mejlar batteri');
sc(($tick['sent_email'] ?? 0) === 1, 'tickern räknar bara batteribekräftelsen');

$thread = ['id' => 't-sol', 'company' => 'jarfalla', 'drafts' => []];
$msg = ['id' => 'm-sol', 'from' => 'sol-jl@kund.se', 'subject' => 'Flytta', 'graph_id' => '', 'graph_mailbox' => ''];
$reply = jeab_rebook_reply($thread, $msg, $base + ['job_type' => 'sol', 'company' => 'jarfalla', 'email' => 'sol-jl@kund.se', 'assignee' => 'Sam L'], 'Ny tid.', 'closed');
sc(!empty($reply['skipped']) && ($reply['reason'] ?? '') === 'sol_calendar_mail_off', 'ombokningsmejl för sol skickas inte');
$laddReply = jeab_rebook_reply($thread, $msg, $base + ['job_type' => 'laddbox', 'company' => 'jarfalla', 'email' => 'laddbox@kund.se', 'assignee' => 'Elin'], 'Ny tid.', 'closed');
sc(empty($laddReply['skipped']) && ($laddReply['to'] ?? '') === 'laddbox@kund.se', 'ombokningsmejl för elbilsladdare skickas');

$tplBefore = jeab_auto_settings()['templates'];
jeab_auto_settings_save([
    'auto_trash_spam' => 1,
    'sol_calendar_mail_off' => 0,
    'tpl' => $tplBefore,
]);
sc(jeab_auto_settings()['sol_calendar_mail_off'] === false, 'avbockad spärr sparas som av');
sc(jeab_auto_settings()['templates'] === $tplBefore, 'mallarna står still när spärren sparas');
$open = jeab_booking_notify_customer($base + [
    'id' => 20,
    'company' => 'jarfalla',
    'job_type' => 'sol',
    'assignee' => 'Sam L',
    'email' => 'sol-oppen@kund.se',
]);
sc(($open['email']['reason'] ?? '') === 'sent', 'avbockad spärr släpper solbekräftelsen');

$path = jeab_inkorg_dir() . '/settings.json';
$stored = json_decode((string) file_get_contents($path), true);
unset($stored['sol_calendar_mail_off']);
file_put_contents($path, json_encode($stored));
sc(jeab_auto_settings()['sol_calendar_mail_off'] === true, 'borttagen nyckel är på igen');

echo "sol calendar mail: ok\n";
