<?php

declare(strict_types=1);

/**
 * mail_queue (H1): kö, 5 per körning, offert först, stopp över 10,
 * 10-minutersreserv, 30-dagars dubblettspärr, avsändare från Användare,
 * kill switch, gate-avlyssning och att Help Scout aldrig används.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-mail-queue-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('MAIL_QUEUE_ENABLED');
putenv('MAIL_QUEUE_PAUSED');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'x',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require $root . '/src/bootstrap.php';
require_once $root . '/src/mail_queue.php';

$fails = 0;
function mq_ok(bool $c, string $m): void
{
    global $fails;
    if ($c) {
        echo "ok {$m}\n";
    } else {
        $fails++;
        fwrite(STDERR, "FAIL {$m}\n");
    }
}

$pdo = new PDO('sqlite:' . $tmp . '/mq.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_MAIL_QUEUE_PDO'] = $pdo;
jeab_mail_queue_ensure($pdo);
$now = strtotime('2026-11-03 10:00:00');
$GLOBALS['_JEAB_MAIL_QUEUE_NOW'] = $now;
$sent = [];
$GLOBALS['_JEAB_MAIL_QUEUE_SEND'] = function (array $job) use (&$sent): array {
    $sent[] = $job;
    return ['ok' => true];
};
$GLOBALS['_JEAB_MAIL_QUEUE_NOTE'] = function (string $tid, string $t) use (&$notes): void {
    $notes[] = [$tid, $t];
};
$notes = [];

$reset = static function () use ($pdo, &$sent, &$notes): void {
    $pdo->exec('DELETE FROM mail_queue');
    $pdo->exec('DELETE FROM mail_queue_state');
    $sent = [];
    $notes = [];
};
$offer = static fn (string $to, string $product = 'sol', string $company = 'jarfalla', string $tid = 't1'): array => [
    'handler' => 'outbound',
    'args' => ['company' => $company, 'to' => $to, 'subject' => 'Offert', 'body' => 'x', 'opts' => [
        'person_email' => 'fel@jarfalla.example.test',
        'quote_guard' => ['kind' => 'offert', 'offer' => $product, 'product' => $product, 'thread_id' => $tid],
    ]],
];
$reply = static fn (string $to): array => ['handler' => 'graph_reply', 'args' => ['company' => 'jarfalla', 'to' => $to, 'text' => 'hej', 'opts' => []]];

// Flagga av: ingen avlyssning.
$GLOBALS['MAIL_QUEUE_ENABLED'] = '0';
mq_ok(!jeab_mail_queue_should_intercept('email', ['handler' => 'graph_reply', 'template' => 'svar', 'job' => []]), 'av som standard');
$GLOBALS['MAIL_QUEUE_ENABLED'] = '1';
mq_ok(jeab_mail_queue_classify(['handler' => 'graph_reply', 'template' => 'svar']) === 'inkorg_svar', 'typ inkorg_svar');
mq_ok(jeab_mail_queue_classify(['handler' => 'system_mail', 'template' => 'booking_notice']) === 'motesbekraftelse', 'typ mötesbekräftelse');
mq_ok(jeab_mail_queue_classify(['handler' => 'outbound', 'template' => 'email', 'job' => $offer('a@b.se')['args']]) === 'offert', 'typ offert');
mq_ok(!jeab_mail_queue_should_intercept('email', ['handler' => 'email_hs', 'template' => 'svar']), 'Help Scout köas inte');
mq_ok(!jeab_mail_queue_should_intercept('sms', ['handler' => 'sms', 'template' => 'booking_notice']), 'sms köas inte');

// Gate-avlyssning returnerar queued och lägger raden i kön.
$reset();
if (function_exists('jeab_send_guard_gate')) {
    $gate = jeab_send_guard_gate('email', 'kund@example.se', 'hej', ['handler' => 'graph_reply', 'template' => 'svar', 'job' => $reply('kund@example.se')['args']]);
    mq_ok(($gate['action'] ?? '') === 'stop' && !empty($gate['result']['queued']), 'gate köar Inkorg-svar');
    mq_ok(jeab_mail_queue_waiting($pdo) === 1, 'raden väntar');
}

// 5 per körning, offerter först.
$reset();
for ($i = 0; $i < 4; $i++) {
    jeab_mail_queue_enqueue('inkorg_svar', "s{$i}@example.se", $reply("s{$i}@example.se"));
}
for ($i = 0; $i < 3; $i++) {
    jeab_mail_queue_enqueue('offert', "o{$i}@example.se", $offer("o{$i}@example.se"));
}
$r = jeab_mail_queue_drain(5, 'tick');
mq_ok($r['sent'] === 5, '5 per körning');
mq_ok(count($sent) === 5 && $sent[0]['handler'] === 'outbound' && $sent[1]['handler'] === 'outbound' && $sent[2]['handler'] === 'outbound' && $sent[3]['handler'] === 'graph_reply', 'offerter först');
mq_ok(jeab_mail_queue_waiting($pdo) === 2, '2 kvar');
$r = jeab_mail_queue_drain(50, 'tick');
mq_ok($r['sent'] === 2, 'tak 5 även med större limit, resten skickas');

// Avsändare från Användare (sol/stockholm => SamL).
$first = $sent[0]['args']['opts'] ?? [];
mq_ok(($first['person_email'] ?? '') === 'demo@example.test', 'offertens avsändare från Användare: ' . ($first['person_email'] ?? ''));
mq_ok(($first['quote_guard']['from'] ?? '') === 'demo@example.test', 'quote_guard.from följer');
$row = $pdo->query("SELECT sender FROM mail_queue WHERE type='offert' AND status='sent' LIMIT 1")->fetchColumn();
mq_ok($row === 'demo@example.test', 'loggad avsändare');
$reset();
jeab_mail_queue_enqueue('offert', 'x@example.se', $offer('x@example.se', 'batteri'));
jeab_mail_queue_drain(5);
mq_ok(($sent[0]['args']['opts']['person_email'] ?? '') === 'saljare3@jarfalla.example.test', 'batteri/stockholm => Roberto');
// Ingen avsändare (sol/skåne har ingen sender i seeden) => failed med logg.
$reset();
jeab_mail_queue_enqueue('offert', 'y@example.se', $offer('y@example.se', 'sol', 'malmo'));
$r = jeab_mail_queue_drain(5);
$log = (string) $pdo->query("SELECT log FROM mail_queue LIMIT 1")->fetchColumn();
mq_ok($r['failed'] === 1 && $sent === [] && str_contains($log, 'ingen offert-avsändare'), 'saknad avsändare skickar inte');

// Help Scout av i system_mail-jobb.
$reset();
jeab_mail_queue_enqueue('motesbekraftelse', 'm@example.se', ['handler' => 'system_mail', 'args' => ['opts' => ['to' => 'm@example.se', 'subject' => 'Möte', 'from_email' => 'kontakt@jarfalla.example.test']]]);
jeab_mail_queue_drain(5);
mq_ok(!empty($sent[0]['args']['opts']['skip_helpscout']), 'skip_helpscout satt');
mq_ok(($sent[0]['args']['opts']['from_email'] ?? '') === 'kontakt@jarfalla.example.test', 'mötesbekräftelse behåller mallens avsändare');

// Stopp över 10.
$reset();
for ($i = 0; $i < 11; $i++) {
    jeab_mail_queue_enqueue('inkorg_svar', "q{$i}@example.se", $reply("q{$i}@example.se"));
}
$r = jeab_mail_queue_drain(5);
mq_ok($r['sent'] === 0 && str_contains((string) $r['skipped'], 'över 10'), 'stopp över 10');
$banner = jeab_mail_queue_banner_html();
mq_ok(str_contains($banner, 'stoppad') && str_contains($banner, '11'), 'banner vid stopp');
$GLOBALS['_JEAB_MAIL_QUEUE_NOW'] = $now + 11 * 60;
mq_ok(str_contains(jeab_mail_queue_banner_html(), 'väntat 11 minuter'), 'banner äldsta > 10 min');
$GLOBALS['_JEAB_MAIL_QUEUE_NOW'] = $now;

// 10 väntande är OK.
$pdo->exec("DELETE FROM mail_queue WHERE id = (SELECT MAX(id) FROM mail_queue)");
$r = jeab_mail_queue_drain(5);
mq_ok($r['sent'] === 5, '10 väntande släpps');

// Kill switch.
$reset();
jeab_mail_queue_enqueue('inkorg_svar', 'k@example.se', $reply('k@example.se'));
jeab_mail_queue_set_paused(true);
$r = jeab_mail_queue_drain(5);
mq_ok($r['skipped'] === 'paused' && $sent === [], 'kill switch pausar');
jeab_mail_queue_set_paused(false);
mq_ok(jeab_mail_queue_drain(5)['sent'] === 1, 'kill switch av släpper');

// 10-minutersreserv.
$reset();
jeab_mail_queue_enqueue('inkorg_svar', 'f1@example.se', $reply('f1@example.se'));
$r = jeab_mail_queue_page_fallback();
mq_ok(($r['sent'] ?? 0) === 1, 'reserv släpper när inget skickats');
jeab_mail_queue_enqueue('inkorg_svar', 'f2@example.se', $reply('f2@example.se'));
$GLOBALS['_JEAB_MAIL_QUEUE_NOW'] = $now + 5 * 60;
$r = jeab_mail_queue_page_fallback();
mq_ok(($r['skipped'] ?? '') === 'nyligen', 'reserv väntar 10 min');
$GLOBALS['_JEAB_MAIL_QUEUE_NOW'] = $now + 11 * 60;
$r = jeab_mail_queue_page_fallback();
mq_ok(($r['sent'] ?? 0) === 1, 'reserv igen efter 10 min');
// Tick nyligen skickat => ingen reserv.
jeab_mail_queue_enqueue('inkorg_svar', 'f3@example.se', $reply('f3@example.se'));
jeab_mail_queue_state_set($pdo, 'last_fallback_at', '2000-01-01 00:00:00');
jeab_mail_queue_state_set($pdo, 'last_sent_at', jeab_mail_queue_stamp());
mq_ok((jeab_mail_queue_page_fallback()['skipped'] ?? '') === 'nyligen', 'ingen reserv när tick nyss skickat');
$GLOBALS['_JEAB_MAIL_QUEUE_NOW'] = $now;

// 30 dagars dubblettspärr, bara offert.
$reset();
jeab_mail_queue_enqueue('offert', 'Dup@Example.se', $offer('Dup@Example.se', 'sol', 'jarfalla', 'tA'));
jeab_mail_queue_drain(5);
$GLOBALS['_JEAB_MAIL_QUEUE_NOW'] = $now + 20 * 86400;
$e = jeab_mail_queue_enqueue('offert', 'dup@example.se', $offer('dup@example.se', 'sol', 'jarfalla', 'tB'));
mq_ok(!$e['ok'] && $e['status'] === 'dubblett_stoppad', 'offert inom 30 dagar stoppas');
mq_ok(($notes[0][0] ?? '') === 'tB' && str_contains((string) ($notes[0][1] ?? ''), 'dubblett stoppad'), 'loggas i ärendet');
$rows = jeab_mail_queue_thread_rows('tB');
mq_ok(($rows[0]['status'] ?? '') === 'dubblett stoppad', 'syns i ärendets utskickslogg');
$gr = jeab_mail_queue_gate_result($e);
mq_ok($gr['ok'] === false && $gr['error'] === 'dubblett stoppad', 'gate-svar dubblett');
mq_ok(jeab_mail_queue_enqueue('motesbekraftelse', 'dup@example.se', ['handler' => 'system_mail', 'args' => ['opts' => []]])['ok'], 'mötesbekräftelse spärras inte');
mq_ok(jeab_mail_queue_enqueue('inkorg_svar', 'dup@example.se', $reply('dup@example.se'))['ok'], 'Inkorg-svar spärras inte');
// Två köade offerter till samma kund: den andra stoppas vid drain.
$GLOBALS['_JEAB_MAIL_QUEUE_NOW'] = $now;
$reset();
jeab_mail_queue_enqueue('offert', 'two@example.se', $offer('two@example.se'));
jeab_mail_queue_enqueue('offert', 'two@example.se', $offer('two@example.se'));
$r = jeab_mail_queue_drain(5);
mq_ok($r['sent'] === 1 && $r['dubbletter'] === 1, 'andra köade offerten stoppas vid drain');
$GLOBALS['_JEAB_MAIL_QUEUE_NOW'] = $now + 31 * 86400;
$e31 = jeab_mail_queue_enqueue('offert', 'two@example.se', $offer('two@example.se'));
mq_ok($e31['ok'], 'efter 30 dagar går det');
$GLOBALS['_JEAB_MAIL_QUEUE_NOW'] = $now;

// Läsning.
$l = jeab_mail_queue_list(['status' => 'waiting']);
mq_ok($l['ok'] && $l['read_only'] && count($l['rows']) === 1 && $l['rows'][0]['recipient'] === 'two@example.se', 'list visar väntande');
mq_ok(count(jeab_mail_queue_list(['type' => 'offert'])['rows']) === 3, 'list filtrerar typ');

if ($fails > 0) {
    fwrite(STDERR, "{$fails} fel\n");
    exit(1);
}
echo "PASS mail_queue\n";
