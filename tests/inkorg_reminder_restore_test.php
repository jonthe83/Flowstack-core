<?php

declare(strict_types=1);

/** Påminnelse: läs loggen och rulla tillbaka följning. Test och självtest skriver inte i påminnelsen. */

$tmp = sys_get_temp_dir() . '/jeab-rem-restore-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function rr(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $opts): array {
    $GLOBALS['_SENT'][] = $opts;
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test'];
};

$closed = jeab_create_reminder(jeab_store(), 'Nora Berg – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Nora Berg',
    'email' => 'nora.berg.restore@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'stängd från början',
]);
jeab_toggle_reminder(jeab_store(), (int) $closed['id'], true);
$before = jeab_get_reminder(jeab_store(), (int) $closed['id']);
rr(is_array($before) && !empty($before['completed_at']) && (string) ($before['person'] ?? '') === 'Roberto', 'utgångsläget är stängt hos Roberto');

$since = date('c', time() - 3600);
$ingested = jeab_inkorg_ingest([
    'message_id' => 'restore-1@example.test',
    'from' => 'nora.berg.restore@gmail.com',
    'subject' => 'Re: offert',
    'body' => 'Kan ni ringa ikväll?',
    'thread_key' => 'thread-restore-1',
    'preset_labels' => ['question' => 'Kan ni ringa ikväll?', 'intent' => 'kop', 'type' => 'Ny lead'],
]);
$msg = jeab_inkorg_get((string) ($ingested['id'] ?? ''));
rr(is_array($msg), 'ärendet finns innan följningen');
$thread = ['id' => 'thread-restore-1', 'status' => 'open', 'messages' => [$msg]];
$handled = jeab_follow_handle($thread, $msg, $before, true);
$opened = jeab_get_reminder(jeab_store(), (int) $closed['id']);
rr(($handled['reopened'] ?? 0) === 1 && is_array($opened) && empty($opened['completed_at']) && str_contains((string) ($opened['note'] ?? ''), 'Kunden svarade'), 'följningen öppnar och lägger raden');
jeab_store()->saveReminder((int) $closed['id'], ['person' => 'Elin', 'updated_at' => jeab_now()]);

$got = jeab_inkorg_hook(['action' => 'inbox.reminder_get', 'id' => (int) $closed['id']]);
$log = implode("\n", (array) ($got['log'] ?? []));
rr(!empty($got['ok']) && ($got['status'] ?? '') === 'open' && ($got['owner'] ?? '') === 'Elin' && str_contains($log, 'Kunden svarade'), 'reminder_get visar status, ägare och loggens slut');

$dry = jeab_inkorg_hook(['action' => 'inbox.reminder_restore', 'id' => (int) $closed['id'], 'since' => $since]);
$still = jeab_get_reminder(jeab_store(), (int) $closed['id']);
rr(!empty($dry['dry']) && ($dry['status'] ?? '') === 'closed' && ($dry['owner'] ?? '') === 'Roberto' && ($dry['would_send'] ?? null) === false && is_array($still) && empty($still['completed_at']) && (string) ($still['person'] ?? '') === 'Elin', 'torrkörning visar stängd hos Roberto och skriver inget');

$back = jeab_inkorg_hook(['action' => 'inbox.reminder_restore', 'id' => (int) $closed['id'], 'since' => $since, 'dry' => 0]);
$restored = jeab_get_reminder(jeab_store(), (int) $closed['id']);
rr(
    ($back['status'] ?? '') === 'closed'
    && ($back['owner'] ?? '') === 'Roberto'
    && is_array($restored)
    && !empty($restored['completed_at'])
    && (string) ($restored['person'] ?? '') === 'Roberto'
    && !str_contains((string) ($restored['note'] ?? ''), 'Kunden svarade')
    && str_contains((string) ($restored['note'] ?? ''), 'stängd från början')
    && count($GLOBALS['_SENT']) === 0,
    'återställning stänger, lägger tillbaka Roberto och tar bort följningsraden'
);

$open = jeab_create_reminder(jeab_store(), 'Otto Berg – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Otto Berg',
    'email' => 'otto.berg.restore@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'redan öppen',
]);
$openBefore = jeab_get_reminder(jeab_store(), (int) $open['id']);
$ingestedOpen = jeab_inkorg_ingest([
    'message_id' => 'restore-2@example.test',
    'from' => 'otto.berg.restore@gmail.com',
    'subject' => 'Re: batteri',
    'body' => 'Kan ni ringa?',
    'thread_key' => 'thread-restore-2',
    'preset_labels' => ['question' => 'Kan ni ringa?', 'type' => 'Ny lead'],
]);
$testLine = jeab_inkorg_get((string) ($ingestedOpen['id'] ?? ''));
rr(is_array($testLine), 'det öppna ärendet finns');
jeab_follow_handle(['id' => 'thread-restore-2', 'status' => 'open', 'messages' => [$testLine]], $testLine, is_array($openBefore) ? $openBefore : [], true);
$openNow = jeab_get_reminder(jeab_store(), (int) $open['id']);
rr(is_array($openNow) && empty($openNow['completed_at']) && str_contains((string) ($openNow['note'] ?? ''), 'Kunden svarade'), 'öppen påminnelse får testraden');
$openBack = jeab_inkorg_hook(['action' => 'inbox.reminder_restore', 'id' => (int) $open['id'], 'since' => $since, 'dry' => 0]);
$openFresh = jeab_get_reminder(jeab_store(), (int) $open['id']);
rr(
    ($openBack['status'] ?? '') === 'open'
    && ($openBack['owner'] ?? '') === 'Roberto'
    && is_array($openFresh)
    && empty($openFresh['completed_at'])
    && !str_contains((string) ($openFresh['note'] ?? ''), 'Kunden svarade'),
    'statusen som gällde före raden kommer tillbaka'
);

$quiet = jeab_create_reminder(jeab_store(), 'Tyra Test – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Tyra Test',
    'email' => 'tyra.test.quiet@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Elin',
    'note' => 'ska vara orörd',
]);
jeab_toggle_reminder(jeab_store(), (int) $quiet['id'], true);
$quietBefore = jeab_get_reminder(jeab_store(), (int) $quiet['id']);
$GLOBALS['_JEAB_AUTO_SELFTEST'] = true;
$ingestedQuiet = jeab_inkorg_ingest([
    'message_id' => 'restore-3@example.test',
    'from' => 'demo.person@example.invalid',
    'subject' => 'självtest',
    'body' => 'Kan ni ringa?',
    'thread_key' => 'autotest-quiet',
    'preset_labels' => ['question' => 'Kan ni ringa?', 'intent' => 'kop', 'type' => 'Ny lead'],
]);
$quietMsg = jeab_inkorg_get((string) ($ingestedQuiet['id'] ?? ''));
rr(is_array($quietMsg), 'självtestärendet finns');
jeab_follow_handle(['id' => 'autotest-quiet', 'status' => 'open', 'messages' => [$quietMsg]], $quietMsg, is_array($quietBefore) ? $quietBefore : [], true);
unset($GLOBALS['_JEAB_AUTO_SELFTEST']);
$quietAfter = jeab_get_reminder(jeab_store(), (int) $quiet['id']);
rr(
    is_array($quietAfter)
    && !empty($quietAfter['completed_at'])
    && (string) ($quietAfter['person'] ?? '') === 'Elin'
    && !str_contains((string) ($quietAfter['note'] ?? ''), 'Kunden svarade')
    && count($GLOBALS['_SENT']) === 0,
    'självtest öppnar inte påminnelsen och lägger ingen rad'
);

echo "OK\n";
