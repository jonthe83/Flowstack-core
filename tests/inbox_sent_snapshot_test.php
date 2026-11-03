<?php

declare(strict_types=1);

/**
 * Manuellt svar och systemmejl fryser mottagare, ämne, text och tid i snapshot_json.
 * En kopia som redan finns skrivs inte om. k:- och h:-rader från send_mark är dedupe, inte utskick.
 * Påhittade adresser. Inget riktigt utskick.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-sent-snap-' . bin2hex(random_bytes(4));
$private = $tmp . '/private';
$public = $tmp . '/public';
mkdir($private . '/secrets', 0775, true);
mkdir($public, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $private;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
file_put_contents($private . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $private . '/app.sqlite',
        'json_path' => $private . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
    'incoming_webhook_token' => 'not-the-inbox-token',
], true) . ";\n");
file_put_contents($private . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-sent-snap']));
require dirname(__DIR__) . '/src/bootstrap.php';

function sent_snap_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_JEAB_INKORG_LLM'] = static fn (): string => json_encode([
    'type' => 'Information',
    'action' => 'arkivera',
    'priority' => 4,
    'intent' => 'info',
    'offer' => '',
    'reason' => 'test',
], JSON_UNESCAPED_UNICODE);

$graphCalls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null) use (&$graphCalls): array {
    $graphCalls[] = [$method, $url, $body];
    if ($method === 'POST' && (str_ends_with($url, '/reply') || str_ends_with($url, '/sendMail'))) {
        return ['code' => 202, 'body' => []];
    }

    return ['code' => 200, 'body' => []];
};
jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);

$ingested = jeab_inkorg_ingest([
    'message_id' => 'snap-in@example.test',
    'from' => 'kund.snap@example.test',
    'from_name' => 'Kund Snap',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Fråga om laddbox',
    'body_text' => 'Hej, jag vill veta mer.',
    'source' => 'graph-jarfalla',
    'company' => 'jarfalla',
    'graph_id' => 'graph-snap-1',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'test' => true,
]);
sent_snap_ok(!empty($ingested['ok']) && trim((string) ($ingested['thread_id'] ?? $ingested['id'] ?? '')) !== '', 'ärendet kan tas in');
$tid = trim((string) ($ingested['thread_id'] ?? $ingested['id'] ?? ''));

$sent = jeab_inkorg_send_in_thread($tid, [
    'body' => 'Vi återkommer imorgon.',
    'send_key' => 'ab12cd34',
    'status_after' => 'open',
], 'Test');
sent_snap_ok(($sent['state'] ?? '') === 'sent' && ($sent['to'] ?? '') === 'kund.snap@example.test', 'manuellt svar skickas som förut');
$replyCalls = 0;
foreach ($graphCalls as $call) {
    if (($call[0] ?? '') === 'POST' && str_contains((string) ($call[1] ?? ''), '/reply')) {
        $replyCalls++;
    }
}
sent_snap_ok($replyCalls === 1, 'ett Graph-svar, ingen extra sändning');

$pdo = jeab_store()->pdo();
$svarStmt = $pdo->prepare('SELECT snapshot_json FROM inbox_sent WHERE thread_id = ? AND kind = ?');
$svarStmt->execute([$tid, 'svar']);
$svarRows = $svarStmt->fetchAll(PDO::FETCH_COLUMN);
sent_snap_ok(count($svarRows) === 1, 'manuellt svar skriver en svar-rad');
$svarSnap = jeab_snapshots_decode((string) $svarRows[0]);
sent_snap_ok(is_array($svarSnap), 'svar-raden har en kopia som auditen kan läsa');
sent_snap_ok(($svarSnap['recipient'] ?? '') === 'kund.snap@example.test', 'kopian har mottagaren');
sent_snap_ok(($svarSnap['subject'] ?? '') === 'Fråga om laddbox', 'kopian har ämnet');
sent_snap_ok(($svarSnap['body'] ?? '') === 'Vi återkommer imorgon.', 'kopian har texten');
sent_snap_ok(($svarSnap['at'] ?? '') !== '' && ($svarSnap['email'] ?? '') === 'kund.snap@example.test', 'kopian har tidpunkt och samma mottagare som e-post');
sent_snap_ok(jeab_snapshots_has_local(['snapshot_json' => (string) $svarRows[0]], 'mail'), 'auditen räknar svar-raden som fryst');

$frozenJson = (string) $svarRows[0];
jeab_inbox_sent_record($tid, 'manual', 'hook', 'svar', [
    'idem' => 'k:ab12cd34',
    'from' => 'kontakt@jarfalla.example.test',
    'snapshot' => jeab_inbox_sent_mail_snapshot('annan.snap@example.test', 'Annat ämne', 'Annan text', '2001-01-01T00:00:00+00:00'),
]);
$svarStmt->execute([$tid, 'svar']);
$afterFreeze = $svarStmt->fetchAll(PDO::FETCH_COLUMN);
sent_snap_ok(count($afterFreeze) === 1 && (string) $afterFreeze[0] === $frozenJson, 'befintlig kopia skrivs inte om');

$markStmt = $pdo->prepare('SELECT kind, snapshot_json FROM inbox_sent WHERE thread_id = ? AND kind LIKE ?');
$markStmt->execute([$tid, 'k:%']);
$marks = $markStmt->fetchAll(PDO::FETCH_ASSOC);
sent_snap_ok(count($marks) === 1 && ($marks[0]['kind'] ?? '') === 'k:ab12cd34', 'send_mark skriver k:-raden');
sent_snap_ok(trim((string) ($marks[0]['snapshot_json'] ?? '')) === '', 'k:-raden har ingen kopia');
sent_snap_ok(jeab_snapshots_mail_is_send(['kind' => 'guard']) === false, 'guard räknas inte som utskick');
sent_snap_ok(jeab_snapshots_mail_is_send(['kind' => 'guard:dup']) === false, 'guard:dup räknas inte som utskick');
sent_snap_ok(jeab_snapshots_mail_is_send(['kind' => 'k:ab12cd34']) === false, 'k:-raden räknas inte som utskick');
sent_snap_ok(jeab_snapshots_mail_is_send(['kind' => 'h:' . str_repeat('a', 30)]) === false, 'h:-raden räknas inte som utskick');
sent_snap_ok(jeab_snapshots_mail_is_send(['kind' => 'svar']) === true && jeab_snapshots_mail_is_send(['kind' => 'system']) === true, 'svar och system räknas som utskick');
sent_snap_ok(jeab_snapshots_mail_is_send(['kind' => 'kvittens']) === true, 'kvittens är fortfarande ett utskick');

$pdo->prepare(
    'INSERT INTO inbox_sent (created_at, thread_id, via, actor, kind, outcome, channel, body_hash, from_addr, snapshot_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([date('c'), $tid, 'manual', 'hook', 'h:' . str_repeat('b', 30), 'sent', '', '', '', '']);
$pdo->prepare(
    'INSERT INTO inbox_sent (created_at, thread_id, via, actor, kind, outcome, channel, body_hash, from_addr, snapshot_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([date('c'), 'thr-sent-snap-missing', 'manual', 'hook', 'quote', 'sent', 'email', str_repeat('c', 64), '', '']);

$audit = jeab_snapshots_audit();
$missingKinds = [];
foreach ((array) ($audit['missing_rows'] ?? []) as $row) {
    if (is_array($row) && ($row['type'] ?? '') === 'mail') {
        $missingKinds[] = (string) ($row['kind'] ?? '');
    }
}
sent_snap_ok(in_array('quote', $missingKinds, true), 'ett riktigt utskick utan kopia saknas fortfarande');
sent_snap_ok(!in_array('svar', $missingKinds, true), 'svar-raden med kopia saknas inte');
foreach ($missingKinds as $kind) {
    sent_snap_ok(!str_starts_with($kind, 'k:') && !str_starts_with($kind, 'h:') && !str_starts_with($kind, 'guard'), 'dedupe och broms saknas inte i auditen: ' . $kind);
}

$systemSends = 0;
$GLOBALS['JEAB_GRAPH_SEND'] = static function () use (&$systemSends): array {
    $systemSends++;

    return [
        'ok' => true,
        'from' => 'kontakt@jarfalla.example.test',
        'graph_id' => 'g-sys',
        'message_id' => '<sys-snap@jeab>',
        'conversation_id' => 'conv-sys-snap',
        'ref' => 'ref-sys-snap',
    ];
};
$system = jeab_outbound_system_mail([
    'company' => 'jarfalla',
    'to' => 'system.kund@example.test',
    'subject' => 'Bokad tid',
    'thread_id' => 'thr-system-snap',
], 'Hej, tiden är bokad.', []);
sent_snap_ok(!empty($system['ok']) && $systemSends === 1, 'systemmejl skickas en gång');
$systemStmt = $pdo->prepare('SELECT snapshot_json FROM inbox_sent WHERE thread_id = ? AND kind = ?');
$systemStmt->execute(['thr-system-snap', 'system']);
$systemRows = $systemStmt->fetchAll(PDO::FETCH_COLUMN);
sent_snap_ok(count($systemRows) === 1, 'systemmejl skriver en system-rad');
$systemSnap = jeab_snapshots_decode((string) $systemRows[0]);
sent_snap_ok(
    is_array($systemSnap)
    && ($systemSnap['recipient'] ?? '') === 'system.kund@example.test'
    && ($systemSnap['subject'] ?? '') === 'Bokad tid'
    && ($systemSnap['body'] ?? '') === 'Hej, tiden är bokad.'
    && ($systemSnap['at'] ?? '') !== '',
    'system-raden fryser mottagare, ämne, text och tid'
);
$systemFrozen = (string) $systemRows[0];
jeab_inbox_sent_record('thr-system-snap', 'auto', 'system', 'system', [
    'idem' => 'system|thr-system-snap|ref-sys-snap',
    'snapshot' => jeab_inbox_sent_mail_snapshot('annan.snap@example.test', 'Annat ämne', 'Annan text', '2001-01-01T00:00:00+00:00'),
]);
$systemStmt->execute(['thr-system-snap', 'system']);
$systemAfter = $systemStmt->fetchAll(PDO::FETCH_COLUMN);
sent_snap_ok(count($systemAfter) === 1 && (string) $systemAfter[0] === $systemFrozen, 'systemkopian skrivs inte om');

echo "OK  inbox sent snapshot\n";
