<?php

declare(strict_types=1);

/** Spärr: 1 auto/5 min, kö, nödstopp, och booking_notice bara via sms-cron. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-send-guard-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
mkdir($tmp . '/public', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function sg_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sg_ok(bool $ok, string $message): void
{
    if (!$ok) {
        sg_fail($message);
    }
    echo "OK  {$message}\n";
}

$GLOBALS['_JEAB_SEND_GUARD_DRAIN'] = static function (): void {
    throw new RuntimeException('avbetningen föll');
};
try {
    jeab_send_guard_finish();
    sg_ok(true, 'sidan sväljer fel i avbetningen');
} catch (Throwable) {
    sg_fail('avbetningen fick sidan att falla');
}
unset($GLOBALS['_JEAB_SEND_GUARD_DRAIN']);

jeab_send_guard_use_app_timezone();
$t0 = strtotime('2026-06-01 12:00:00');
sg_ok($t0 !== false, 'klockan går att tolka');
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0;
$seen = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$seen): array {
    $seen[] = (string) ($opts['guard_template'] ?? '');
    return ['ok' => true, 'path' => 'stub', 'id' => 'stub'];
};
$GLOBALS['JEAB_SMS_SEND'] = static function (): array {
    return ['ok' => true];
};

$store = jeab_store();
sg_ok($store instanceof JeabPdoStore, 'sqlite är öppen');

$email = static function (string $template, string $body) : array {
    return send_guard_check('email', 'kund@exempel.se', $template, 'auto', 'auto', 'system', hash('sha256', $body), [
        'body' => $body,
        'handler' => 'system_mail',
        'job' => ['opts' => [
            'to' => 'kund@exempel.se',
            'subject' => 'Test',
            'text' => $body,
            'guard_template' => $template,
        ]],
    ]);
};
$sms = static function (string $body, string $template = 'sms'): array {
    return send_guard_check('sms', '0707654321', $template, 'auto', 'auto', 'system', hash('sha256', $body), [
        'body' => $body,
        'handler' => 'sms',
        'job' => ['to' => '0707654321', 'text' => $body, 'company' => 'jarfalla', 'from' => null],
    ]);
};

$first = $email('offert', 'offert-a');
sg_ok(!empty($first['send']) && empty($first['queued']), 'första mejlet går direkt');
$smsNow = $sms('sms-a');
sg_ok(!empty($smsNow['send']), 'sms har egen räknare');

$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 1;
$booking = $email('booking_notice', 'bokning-b');
sg_ok(!empty($booking['queued']) && empty($booking['send']), 'bokningsnotis köas inom fem minuter');
$smsQueued = $sms('sms-b');
sg_ok(!empty($smsQueued['queued']), 'andra sms:et köas i sin egen kö');

$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 2;
$again = $email('offert', 'offert-c');
sg_ok(!empty($again['queued']), 'nästa mejl köas, FIFO');

$manualHash = hash('sha256', 'manuellt');
$manual = send_guard_check('email', 'kund@example.test', 'svar', 'manual', 'manual', 'hook', $manualHash, ['body' => 'manuellt']);
sg_ok(!empty($manual['send']), 'manuellt Skicka räknas inte');
jeab_send_guard_commit_manual($manual);
$manualAgain = send_guard_check('email', 'kund@example.test', 'svar', 'manual', 'manual', 'hook', $manualHash, ['body' => 'manuellt']);
sg_ok(($manualAgain['reason'] ?? '') === 'duplicate' && empty($manualAgain['send']), 'samma manuella meddelande stoppas i tio minuter');

$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 301;
$drain = send_guard_drain();
sg_ok(!empty($drain['ran']) && (int) ($drain['email'] ?? 0) === 1, 'vanlig avbetning tar äldsta mejlet som inte är bokning');
sg_ok((int) ($drain['skipped_booking'] ?? 0) >= 1, 'bokningsnotisen hoppas över');
sg_ok($seen === ['offert'], 'avbetningen skickade offerten');
$againNow = send_guard_drain();
sg_ok(empty($againNow['ran']), 'två avbetningar samma minut ger en');

$planned = $store->pdo()->query("SELECT template FROM send_queue WHERE status = 'planned' AND channel = 'email'")->fetchAll(PDO::FETCH_COLUMN);
sg_ok($planned === ['booking_notice'], 'bokningsnotisen väntar på sms-cron');
$paceMail = $store->pdo()->query("SELECT template FROM send_queue WHERE status = 'waiting' AND channel = 'email'")->fetchAll(PDO::FETCH_COLUMN);
sg_ok($paceMail === [], 'bokningsnotisen räknas inte i takt-kön');

$cronSrc = (string) file_get_contents(dirname(__DIR__) . '/sms-cron.php');
$tickSrc = (string) file_get_contents(dirname(__DIR__) . '/src/sms.php');
sg_ok(str_contains($cronSrc, "send_guard_drain(['exclude_booking' => false])"), 'sms-cron anropar avbetning med exclude_booking false');
sg_ok(str_contains($tickSrc, "send_guard_drain(['exclude_booking' => false])"), 'jeab_sms_tick anropar samma avbetning');
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 662;
$cronDrain = send_guard_drain(['exclude_booking' => false]);
sg_ok(!empty($cronDrain['ran']), 'sms-cron-läget betar av kön');
sg_ok(in_array('booking_notice', $seen, true), 'sms-cron-läget släpper bokningsnotisen');
$left = (int) $store->pdo()->query("SELECT COUNT(*) FROM send_queue WHERE status = 'waiting' AND template = 'booking_notice'")->fetchColumn();
sg_ok($left === 0, 'bokningsnotisen är avbetad');
$cronAgain = send_guard_drain(['exclude_booking' => false]);
sg_ok(empty($cronAgain['ran']), 'samma minutlås gäller även sms-cron');

$store->pdo()->exec("DELETE FROM send_queue WHERE channel = 'sms' AND status = 'waiting'");
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 4000;
$fresh = $sms('sms-ny');
sg_ok(!empty($fresh['send']), 'nytt sms går när fem minuter har gått');
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 4001;
for ($i = 1; $i <= 20; $i++) {
    $queued = $sms('sms-ko-' . $i);
    if (empty($queued['queued'])) {
        sg_fail('sms ' . $i . ' köades inte');
    }
}
$state = jeab_send_guard_state($store->pdo());
sg_ok((int) $state['killed'] === 0 && jeab_send_guard_waiting($store->pdo(), 'sms') === 20, 'tjugo i sms-kön stoppar inte');
$killed = $sms('sms-ko-21');
sg_ok(!empty($killed['queued']), 'det tjugoförsta köas');
$state = jeab_send_guard_state($store->pdo());
sg_ok((int) $state['killed'] === 1 && (int) ($GLOBALS['_JEAB_SEND_GUARD_ALARMS'] ?? 0) === 1, 'fler än tjugo löser nödstopp och ett larm');
$blocked = $sms('sms-ko-22');
sg_ok(($blocked['reason'] ?? '') === 'kill' && empty($blocked['queued']), 'nästa automatiska utskick stoppas');
$emailBlocked = $email('offert', 'efter-stopp');
sg_ok(($emailBlocked['reason'] ?? '') === 'kill', 'nödstoppet gäller även mejl');

$status = jeab_send_guard_status_hook([]);
$encoded = json_encode($status);
sg_ok(is_string($encoded) && !str_contains($encoded, '@') && !empty($status['killed']) && (int) $status['sms_queue'] === 21, 'status är läsbar utan adress');
$bad = jeab_send_guard_reset_hook(['confirm' => 'nej', 'queue' => 'release']);
sg_ok(empty($bad['ok']) && (int) jeab_send_guard_state($store->pdo())['killed'] === 1, 'fel bekräftelse ändrar inget');
$released = jeab_send_guard_reset_hook(['confirm' => 'SAM', 'queue' => 'släpp']);
sg_ok(!empty($released['ok']) && empty($released['killed']) && jeab_send_guard_waiting($store->pdo(), 'sms') === 21, 'släpp behåller kön');
$store->pdo()->exec('UPDATE send_guard_state SET killed = 1 WHERE id = 1');
$discarded = jeab_send_guard_reset_hook(['confirm' => 'SAM', 'choice' => 'kasta']);
sg_ok(!empty($discarded['ok']) && jeab_send_guard_waiting($store->pdo(), 'sms') === 0 && jeab_send_guard_waiting($store->pdo(), 'email') === 0, 'kasta tömmer kön');

$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 12000;
$anchor = $email('offert', 'ankare-takt');
sg_ok(!empty($anchor['send']), 'ankare för planerad väntan går direkt');
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 12001;
$bookingSeen = count(array_filter($seen, static fn(string $template): bool => $template === 'booking_notice'));
for ($i = 1; $i <= 21; $i++) {
    $plannedMail = $email('booking_notice', 'planerad-' . $i);
    if (empty($plannedMail['queued'])) {
        sg_fail('planerad bokningsnotis ' . $i . ' köades inte');
    }
}
sg_ok((int) jeab_send_guard_state($store->pdo())['killed'] === 0, 'tjugoen bokningsnotiser mot sms-cron stoppar inte');
sg_ok(jeab_send_guard_waiting($store->pdo(), 'email') === 0, 'planerad väntan ligger utanför takt-kön');
sg_ok(jeab_send_guard_planned($store->pdo(), 'email') === 21, 'tjugoen bokningsnotiser väntar på sms-cron');
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 12120;
$pageDrain = send_guard_drain();
sg_ok(!empty($pageDrain['ran']) && (int) ($pageDrain['skipped_booking'] ?? 0) >= 21, 'vanlig avbetning hoppar över planerad väntan');
sg_ok((int) jeab_send_guard_state($store->pdo())['killed'] === 0 && jeab_send_guard_planned($store->pdo(), 'email') === 21, 'sidan släpper inte in bokningsnotiserna i takt-kön');
sg_ok(count(array_filter($seen, static fn(string $template): bool => $template === 'booking_notice')) === $bookingSeen, 'sidan skickar ingen planerad bokningsnotis');
$GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 12200;
$releaseDrain = send_guard_drain(['exclude_booking' => false]);
sg_ok(!empty($releaseDrain['ran']) && ($releaseDrain['reason'] ?? '') === 'killed', 'sms-cron lägger bokningsnotiserna i takt-kön');
sg_ok((int) jeab_send_guard_state($store->pdo())['killed'] === 1, 'fler än tjugo i takt-kön är nödstopp');
sg_ok(jeab_send_guard_waiting($store->pdo(), 'email') === 21 && jeab_send_guard_planned($store->pdo(), 'email') === 0, 'släppta bokningsnotiser räknas i takt-kön');
sg_ok((int) ($releaseDrain['sent'] ?? 0) === 0, 'nödstoppet skickar inte takt-kön');
sg_ok(count(array_filter($seen, static fn(string $template): bool => $template === 'booking_notice')) === $bookingSeen, 'ingen bokningsnotis gick ut när takt-kön slog i taket');
sg_ok((int) ($GLOBALS['_JEAB_SEND_GUARD_ALARMS'] ?? 0) === 2, 'takt-kön ger ett nytt larm');

$GLOBALS['_JEAB_SEND_GUARD_PDO'] = null;
$closed = send_guard_check('email', 'kund@exempel.se', 'offert', 'auto', 'auto', 'system', hash('sha256', 'stangd'));
$openManual = send_guard_check('email', 'kund@example.test', 'svar', 'manual', 'manual', 'hook', hash('sha256', 'oppen'));
sg_ok(($closed['reason'] ?? '') === 'sql' && empty($closed['send']), 'auto stänger när databasen saknas');
sg_ok(!empty($openManual['send']), 'manuellt Skicka går även utan databas');

echo "OK  spärren\n";
