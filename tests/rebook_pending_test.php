<?php

declare(strict_types=1);

/** Ombokning som nekas eller misslyckas ska köras igen, utan dubbel flytt eller dubbelt mejl. */

$tmp = sys_get_temp_dir() . '/jeab-rebook-pending-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp . '/fel';
mkdir($GLOBALS['_JEAB_FELLOGG_DIR'], 0775, true);
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/tick.php';
jeab_ordrar_external_live(false);

function rp(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SEND_OK'] = 0;
$GLOBALS['_SEND_FAILS'] = 0;
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    unset($a);
    if ($GLOBALS['_SEND_FAILS'] > 0) {
        $GLOBALS['_SEND_FAILS']--;
        throw new RuntimeException('1203 max_user_connections till demo@example.test');
    }
    $GLOBALS['_SEND_OK']++;
    return ['ok' => true];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []): array {
    unset($method, $url, $body, $headers);
    return ['code' => 200, 'body' => ['value' => []]];
};
jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);
@mkdir(jeab_inkorg_dir(), 0775, true);
file_put_contents(jeab_inkorg_dir() . '/settings.json', json_encode(['helpscout_companies' => []]));
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test']));
jeab_rebook_settings_save(['rebook_enabled' => 1, 'rebook_only_from' => 'demo@example.test', 'rebook_prompt' => '']);

$store = jeab_store();
rp($store instanceof JeabPdoStore, 'dedupe-nyckeln ligger i SQL-lagret');
$tz = new DateTimeZone('Europe/Stockholm');
$day = (new DateTimeImmutable('now', $tz))->setTime(0, 0)->modify('+20 days');
while (!jeab_ordrar_is_workday($day)) {
    $day = $day->modify('+1 day');
}
$next = $day->modify('+1 day');
while (!jeab_ordrar_is_workday($next)) {
    $next = $next->modify('+1 day');
}
$later = $next->modify('+1 day');
while (!jeab_ordrar_is_workday($later)) {
    $later = $later->modify('+1 day');
}
$booking = jeab_create_booking($store, 'Test Testsson', ['Kim'], $day->format('Y-m-d') . ' 07:00:00', $day->format('Y-m-d') . ' 11:00:00', 'laddbox', [
    'email' => 'demo@example.test',
    'address' => 'Testvägen 12, Järfälla',
    'company' => 'malmo',
]);
$start = (string) $booking['starts_at'];
$GLOBALS['_SEND_OK'] = 0;
$mail = static function (string $id, string $text): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@rebook.test',
        'from' => 'demo@example.test',
        'from_name' => 'Sam',
        'to' => 'kontakt@malmo.example.test',
        'subject' => 'SV: Installation',
        'body_text' => $text,
        'source' => 'graph-malmo',
        'graph_mailbox' => 'kontakt@malmo.example.test',
        'thread_key' => 'rb-' . $id,
    ]);
};

$GLOBALS['_JEAB_REBOOK_LLM'] = static fn(): string => json_encode([
    'reschedule' => true,
    'confidence' => 95,
    'proposals' => [['date' => $next->format('Y-m-d'), 'time' => '07:00', 'part' => '']],
    'reason' => 'kunden vill flytta',
]);
$first = $mail('nekad', 'Tiden passar inte, kan ni dagen efter kl 7?');
$firstId = (string) $first['id'];
$GLOBALS['_JEAB_REBOOK_FAIL_ONCE'] = 'Databasen är upptagen. Försök igen om en stund. demo@example.test';
$denied = jeab_rebook_on_message($firstId);
rp(($denied['action'] ?? '') === 'error' && !jeab_rebook_seen($firstId), 'nekad ombokning markeras inte som behandlad');
rp(isset(jeab_rebook_pending_read()[$firstId]), 'nekad ombokning ligger kvar till nästa tick');
$queued = jeab_store()->pdo()->query('SELECT msg_id, why FROM inbox_rebook_pending')->fetchAll();
$queuedJson = (string) json_encode($queued);
rp(count($queued) === 1 && str_contains($queuedJson, '[mejl]') && !str_contains($queuedJson, '@') && !is_file(jeab_inkorg_dir() . '/rebook-pending.json'), 'kön ligger i SQL och inte i JSON');
rp(jeab_get_booking($store, (int) $booking['id'])['starts_at'] === $start, 'nekad ombokning flyttar inte tiden');
$log = (string) file_get_contents($GLOBALS['_JEAB_FELLOGG_DIR'] . '/app-errors.jsonl');
rp(str_contains($log, 'Ombokning:') && str_contains($log, '[mejl]') && !str_contains($log, 'demo@example.test'), 'felloggen maskerar mejladressen');

$moved = jeab_tick_inkorg_live(microtime(true) + 5);
rp(($moved['ok'] ?? false) === true, 'nästa tick kör den väntande ombokningen');
$after = jeab_get_booking($store, (int) $booking['id']);
rp((string) $after['starts_at'] !== $start && jeab_rebook_seen($firstId) && !isset(jeab_rebook_pending_read()[$firstId]), 'lyckad flytt markeras först efteråt');
rp($GLOBALS['_SEND_OK'] === 1, 'bekräftelsen skickas en gång');
$tid = (string) ($denied['thread'] ?? $first['thread_id'] ?? '');
$moveKey = jeab_rebook_step_key($tid, $firstId, 'move', (int) $booking['id'] . "\n" . $after['starts_at'] . "\n" . $after['ends_at']);
rp(jeab_inkorg_send_seen($tid, $moveKey), 'dedupe-nyckeln för flytten sätts efter lyckad flytt');
$again = jeab_rebook_on_message($firstId);
rp(($again['why'] ?? '') === 'redan prövat' && $GLOBALS['_SEND_OK'] === 1 && jeab_get_booking($store, (int) $booking['id'])['starts_at'] === $after['starts_at'], 'samma mejl flyttar inte och skickar inte igen');

$GLOBALS['_JEAB_REBOOK_LLM'] = static fn(): string => json_encode([
    'reschedule' => true,
    'confidence' => 95,
    'proposals' => [['date' => $later->format('Y-m-d'), 'time' => '07:00', 'part' => '']],
    'reason' => 'kunden vill flytta igen',
]);
$second = $mail('mejl', 'Kan vi ta dagen därpå i stället?');
$secondId = (string) $second['id'];
$GLOBALS['_SEND_FAILS'] = 1;
$sentFail = jeab_rebook_on_message($secondId);
$mid = jeab_get_booking($store, (int) $booking['id']);
rp(($sentFail['action'] ?? '') === 'error' && !jeab_rebook_seen($secondId), 'misslyckat utskick markeras inte som behandlat');
rp((string) $mid['starts_at'] !== (string) $after['starts_at'], 'kalendern hann flyttas innan utskicket föll');
rp($GLOBALS['_SEND_OK'] === 1, 'ett kast före svaret skickar inget mejl');
$retry = jeab_rebook_on_message($secondId);
$kept = jeab_get_booking($store, (int) $booking['id']);
rp(($retry['action'] ?? '') === 'moved' && ($retry['calendar'] ?? '') === 'kept', 'nästa försök flyttar inte kalendern igen');
rp((string) $kept['starts_at'] === (string) $mid['starts_at'] && $GLOBALS['_SEND_OK'] === 2, 'bekräftelsen går ut en gång och tiden står kvar');
$third = jeab_rebook_on_message($secondId);
rp(($third['why'] ?? '') === 'redan prövat' && $GLOBALS['_SEND_OK'] === 2, 'tredje tickern skickar inte ett till mejl');

$GLOBALS['_JEAB_REBOOK_LLM'] = static fn(): string => 'inte json';
$silent = $mail('grok', 'Hallå?');
$silentId = (string) $silent['id'];
$noGrok = jeab_rebook_on_message($silentId);
rp(($noGrok['why'] ?? '') === 'Grok svarade inte' && !jeab_rebook_seen($silentId) && isset(jeab_rebook_pending_read()[$silentId]), 'uteblivet Grok-svar prövas igen');
$GLOBALS['_JEAB_REBOOK_LLM'] = static fn(): string => json_encode(['reschedule' => false, 'confidence' => 90, 'proposals' => [], 'reason' => 'inte flytt']);
$grokDone = jeab_rebook_pending_drain(20);
rp($grokDone >= 1 && jeab_rebook_seen($silentId) && !isset(jeab_rebook_pending_read()[$silentId]), 'nästa tick avslutar ett svar som inte är en ombokning');

$oldId = 'old-before-noon';
$droppedId = (string) $mail('tappad', 'Kan ni flytta tiden?')['id'];
jeab_rebook_log($oldId, ['action' => 'error', 'why' => 'Databasen är upptagen.', 'thread' => 't-old', 'at' => '2026-10-21T11:00:00+02:00']);
jeab_rebook_log($droppedId, ['action' => 'error', 'why' => 'Databasen är upptagen. Försök igen om en stund. demo@example.test', 'thread' => 't-drop', 'at' => '2026-10-21T12:30:00+02:00']);
$dry = jeab_inkorg_hook(['action' => 'rebook.pending']);
$dryJson = (string) json_encode($dry, JSON_UNESCAPED_UNICODE);
$ids = array_column((array) ($dry['items'] ?? []), 'id');
rp(($dry['dry'] ?? 0) === 1 && in_array($droppedId, $ids, true) && !in_array($oldId, $ids, true), 'dry=1 listar tappade ombokningar sedan 2026-10-21 12:00');
rp(!str_contains($dryJson, '@') && !str_contains($dryJson, 'sam'), 'listan innehåller ingen mejladress');
$blocked = jeab_inkorg_hook(['action' => 'rebook.pending', 'dry' => 0]);
rp(($blocked['ok'] ?? true) === false && ($blocked['ran'] ?? 1) === 0 && jeab_rebook_seen($droppedId), 'dry=0 utan confirm rör inte kön');
$ran = jeab_inkorg_hook(['action' => 'rebook.pending', 'dry' => 0, 'confirm' => 'SAM']);
$ranJson = (string) json_encode($ran, JSON_UNESCAPED_UNICODE);
rp(($ran['ok'] ?? false) === true && ($ran['ran'] ?? 0) >= 1 && !str_contains($ranJson, '@'), 'confirm=SAM kör om utan adresser');
rp(!jeab_rebook_seen($droppedId) || (($ran['results'][0]['action'] ?? '') !== 'error'), 'omkörningen lämnar inte kvar felet som behandlat');
$seenAfter = jeab_rebook_done()[$droppedId] ?? null;
rp(is_array($seenAfter) && (string) ($seenAfter['action'] ?? '') !== 'error', 'en klar omkörning markeras med utfallet');
rp(jeab_rebook_seen($oldId), 'en träff före 12:00 ligger kvar');

echo "rebook pending: ok\n";
