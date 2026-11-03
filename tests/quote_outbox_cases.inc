<?php

declare(strict_types=1);

/**
 * Gemensamma kontroller för offert-utboxen. Anropas efter bootstrap.
 * Påhittade namn. Inget skickas.
 */

if (!function_exists('qo')) {
    fwrite(STDERR, "FAIL qo saknas\n");
    exit(1);
}

$GLOBALS['_SENT'] = [];
$GLOBALS['_SMS'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $mail): array {
    $GLOBALS['_SENT'][] = $mail;

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['_SENT'][] = $opts;

    return ['ok' => true, 'path' => 'stub', 'id' => 'm1', 'from' => 'saljare3@jarfalla.example.test'];
};
$GLOBALS['JEAB_SMS_SEND'] = static function (): array {
    $GLOBALS['_SMS'][] = 1;

    return ['ok' => true, 'queued' => 1];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (): array {
    return ['code' => 200, 'body' => ['value' => [], 'id' => 'sent-folder']];
};

qo(jeab_quote_queue_backend() === 'sql', 'utboxen ligger i SQL när PDO finns');
qo(jeab_gp_owner_email('sol', 'malmo') === '', 'sol i Malmö äger ingen brevlåda');
qo(jeab_quote_sender_email('batteri', 'jarfalla', '') === 'saljare3@jarfalla.example.test', 'tom ägare för batteri blir Roberto');
qo(jeab_quote_sender_email('laddbox', 'jarfalla', '') === 'saljare4@jarfalla.example.test', 'tom ägare för laddbox blir Elin');
qo(jeab_quote_sender_email('sol', 'jarfalla', '') === 'demo@example.test', 'tom ägare för sol blir Sam L');
qo(jeab_quote_sender_email('sol', 'malmo', '') === 'saljare.syd@malmo.example.test', 'tom ägare i Malmö blir Kim');
qo(jeab_quote_sender_email('batteri', 'jarfalla', 'saljare4@jarfalla.example.test') === 'saljare4@jarfalla.example.test', 'satt ägare behålls');
qo(jeab_quote_sender_email('', 'jarfalla', '') === '', 'okänd produkt får ingen avsändare');
qo(jeab_quote_sender_email('annat', 'jarfalla', '') === '', 'en tom produktnyckel ger ingen avsändare');
qo(jeab_quote_outbox_reason('hopp fejk.kund@gmail.com 0701234567') === 'hopp', 'loggen tappar adress och telefon');

$pdo = jeab_data_pdo();
qo($pdo instanceof PDO, 'utboxen har en PDO');
$now = jeab_now();
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at)
     VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?)'
)->execute(['th-uniq|batteri', 'th-uniq', 'batteri', 'pending', '', time(), $now]);
$uniqueThrew = false;
try {
    $pdo->prepare(
        'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at)
         VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?)'
    )->execute(['th-uniq|batteri', 'th-uniq', 'batteri', 'pending', '', time(), $now]);
} catch (Throwable) {
    $uniqueThrew = true;
}
qo($uniqueThrew, 'samma tråd och produkt kan inte läggas in två gånger');

unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
jeab_inbox_quote_queue_save([
    'th-once' => ['at' => time(), 'product' => 'laddbox', 'idem' => 'th-once|laddbox'],
]);
jeab_inbox_quote_queue_save([
    'th-once' => ['at' => time(), 'product' => 'laddbox', 'idem' => 'th-once|laddbox'],
]);
$once = (int) $pdo->query("SELECT COUNT(*) FROM quote_outbox WHERE thread_id = 'th-once'")->fetchColumn();
qo($once === 1, 'en andra sparning av samma nyckel skriver över');

$pdo->exec("DELETE FROM quote_outbox WHERE status IN ('pending', 'retry', 'sending')");
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$leaseIdem = 'th-lease|batteri';
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at)
     VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?)'
)->execute([$leaseIdem, 'th-lease', 'batteri', 'pending', '', time(), $now]);
$beforeClaim = time();
qo(jeab_quote_outbox_claim($leaseIdem), 'första processen tar leaset');
$afterClaim = time();
$leaseRow = $pdo->prepare('SELECT status, next_attempt_at FROM quote_outbox WHERE idem_key = ?');
$leaseRow->execute([$leaseIdem]);
$leaseGot = $leaseRow->fetch(PDO::FETCH_ASSOC);
$leaseNext = is_array($leaseGot) ? (int) ($leaseGot['next_attempt_at'] ?? 0) : 0;
qo(is_array($leaseGot) && ($leaseGot['status'] ?? '') === 'sending', 'leaset sätter sending');
qo($leaseNext >= $beforeClaim + 600 && $leaseNext <= $afterClaim + 600, 'leaset varar 600 sekunder');
qo(!jeab_quote_outbox_claim($leaseIdem), 'en andra process tar inte ett giltigt lease');
$pdo->prepare('UPDATE quote_outbox SET next_attempt_at = ? WHERE idem_key = ?')->execute([time() - 5, $leaseIdem]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
qo(jeab_quote_outbox_claim($leaseIdem), 'ett utgånget lease kan tas om');
$GLOBALS['_JEAB_SAPI'] = 'cli';
unset($GLOBALS['_JEAB_TICK']);
$heldSends = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$heldSends): array {
    $heldSends++;

    return ['sent' => 1, 'action' => 'quoted'];
};
$heldDrain = jeab_inbox_queue_quote_drain(5);
qo($heldSends === 0 && (int) ($heldDrain['ran'] ?? 1) === 0 && (int) ($heldDrain['sent'] ?? 1) === 0, 'ett giltigt lease skickas inte av en annan tömning');
$pdo->prepare('UPDATE quote_outbox SET next_attempt_at = ? WHERE idem_key = ?')->execute([time() - 5, $leaseIdem]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$liveSends = 0;
$secondClaim = true;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$liveSends, &$secondClaim, $leaseIdem): array {
    $liveSends++;
    $secondClaim = jeab_quote_outbox_claim($leaseIdem);

    return ['sent' => 1, 'action' => 'quoted'];
};
$liveDrain = jeab_inbox_queue_quote_drain(5);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
qo($liveSends === 1 && $secondClaim === false && (int) ($liveDrain['sent'] ?? 0) === 1, 'bara den som tog leaset skickar');
qo(jeab_quote_outbox_state('th-lease', 'batteri') === 'sent', 'bekräftat utskick avslutar raden');

unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_OUTBOX_LOG']);
$throwTid = 'th-throw';
jeab_inbox_quote_queue_save([
    $throwTid => ['at' => time(), 'product' => 'batteri', 'idem' => $throwTid . '|batteri'],
]);
$GLOBALS['_JEAB_SAPI'] = 'cli';
unset($GLOBALS['_JEAB_TICK']);
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function (): void {
    throw new RuntimeException('boom');
};
$threwDrain = jeab_inbox_queue_quote_drain();
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$afterThrow = jeab_inbox_quote_queue_load();
$throwEvents = jeab_quote_outbox_events($throwTid);
$throwLogged = false;
foreach ($throwEvents as $event) {
    if (($event['status'] ?? '') === 'retry' && ($event['reason'] ?? '') === 'throw') {
        $throwLogged = true;
    }
}
qo((int) ($threwDrain['ran'] ?? 0) === 1 && (int) ($threwDrain['sent'] ?? 1) === 0, 'ett kast räknas inte som skickat');
qo(isset($afterThrow[$throwTid]) && (int) ($afterThrow[$throwTid]['attempts'] ?? 0) === 1, 'raden ligger kvar med ett försök');
qo((int) ($afterThrow[$throwTid]['next_attempt_at'] ?? 0) > time(), 'ett kast väntar innan nästa försök');
qo($throwLogged, 'kastet loggas utan kunddata');

$releaseDue = static function (string $tid) use (&$pdo): void {
    $pdo->prepare(
        "UPDATE quote_outbox SET next_attempt_at = 0 WHERE thread_id = ? AND status IN ('pending', 'retry', 'sending')"
    )->execute([$tid]);
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
};
$releaseDue($throwTid);
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function (): array {
    return ['action' => 'hold', 'why' => 'needs_review', 'sent' => 0];
};
$reviewDrain = jeab_inbox_queue_quote_drain();
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$afterReview = jeab_inbox_quote_queue_load();
qo((int) ($reviewDrain['sent'] ?? 1) === 0 && isset($afterReview[$throwTid]) && (int) ($afterReview[$throwTid]['attempts'] ?? 0) === 2, 'needs_review lämnar raden');

$releaseDue($throwTid);
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function (): array {
    return ['action' => 'hold', 'why' => 'redan skickad', 'already_sent' => true, 'sent' => 0];
};
$skipDrain = jeab_inbox_queue_quote_drain();
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$skipEvents = jeab_quote_outbox_events($throwTid);
$skipLogged = false;
foreach ($skipEvents as $event) {
    if (($event['status'] ?? '') === 'skipped' && str_contains((string) ($event['reason'] ?? ''), 'redan skickad')) {
        $skipLogged = true;
    }
}
qo((int) ($skipDrain['ran'] ?? 0) === 1 && (int) ($skipDrain['sent'] ?? 1) === 0 && !isset(jeab_inbox_quote_queue_load()[$throwTid]), 'ett slutligt hopp lämnar kön');
qo($skipLogged && jeab_quote_outbox_state($throwTid, 'batteri') === 'skipped', 'hoppet loggas och raden är avslutad');

$hookTid = 'th-idem';
jeab_inkorg_thread_write($hookTid, static function (array $t): array {
    $t['status'] = 'open';
    $t['tags'] = ['batteri'];
    $t['updated_at'] = jeab_now();

    return $t;
});
qo((string) (jeab_inkorg_thread_meta($hookTid)['status'] ?? '') === 'open', 'ärendet finns');
jeab_inkorg_relabel_offer_idem_remember($hookTid, 'batteri', true);
qo(isset(jeab_inkorg_relabel_offer_idem_map($hookTid)[$hookTid . '|batteri']), 'nyckeln kan sättas efter utskick');
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
jeab_inbox_quote_queue_save([
    $hookTid => ['at' => time(), 'product' => 'batteri', 'idem' => $hookTid . '|batteri'],
]);
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function (): void {
    throw new RuntimeException('boom');
};
jeab_inbox_queue_quote_drain(1);
qo(!isset(jeab_inkorg_relabel_offer_idem_map($hookTid)[$hookTid . '|batteri']), 'nyckeln tas bort när utskicket misslyckas');
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
qo(isset(jeab_inbox_quote_queue_load()[$hookTid]), 'tråden ligger kvar i kön');
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND']);
qo(jeab_inkorg_relabel_offer_idem_seen($hookTid, 'batteri'), 'redan köad läser utboxen');

$claimSrc = (string) file_get_contents(dirname(__DIR__) . '/src/quote_outbox.php');
$claimFn = substr($claimSrc, (int) strpos($claimSrc, 'function jeab_quote_outbox_claim'));
$claimFn = substr($claimFn, 0, (int) strpos($claimFn, 'function jeab_quote_outbox_skip_max_attempts'));
qo(str_contains($claimFn, 'attempts = attempts + 1') && str_contains($claimFn, "status = 'sending'"), 'claim ökar attempts i samma UPDATE som leaset');

$pdo->exec("DELETE FROM quote_outbox WHERE status IN ('pending', 'retry', 'sending')");
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_OUTBOX_LOG']);
$bumpIdem = 'th-bump|batteri';
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at)
     VALUES (?, ?, ?, ?, 3, 0, ?, ?, ?)'
)->execute([$bumpIdem, 'th-bump', 'batteri', 'pending', '', time(), $now]);
qo(jeab_quote_outbox_claim($bumpIdem), 'ett försök under åtta tar leaset');
$bumpAttempts = (int) $pdo->query("SELECT attempts FROM quote_outbox WHERE idem_key = 'th-bump|batteri'")->fetchColumn();
qo($bumpAttempts === 4, 'leaset räknar ett försök');
$pdo->prepare('UPDATE quote_outbox SET next_attempt_at = 0, status = ? WHERE idem_key = ?')->execute(['retry', $bumpIdem]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function (): array {
    return ['action' => 'hold', 'why' => 'needs_review', 'sent' => 0];
};
$bumpDrain = jeab_inbox_queue_quote_drain(5);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$bumpAfter = (int) $pdo->query("SELECT attempts FROM quote_outbox WHERE idem_key = 'th-bump|batteri'")->fetchColumn();
qo((int) ($bumpDrain['ran'] ?? 0) === 1 && $bumpAfter === 5, 'efter utskick räknas försöket inte en gång till');

$capIdem = 'th-cap|laddbox';
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at)
     VALUES (?, ?, ?, ?, 8, 0, ?, ?, ?)'
)->execute([$capIdem, 'th-cap', 'laddbox', 'pending', '', time(), $now]);
$capSends = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$capSends): array {
    $capSends++;

    return ['sent' => 1, 'action' => 'quoted'];
};
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_OUTBOX_LOG']);
qo(!jeab_quote_outbox_claim($capIdem), 'åttonde försöket tas inte för utskick');
$capDrain = jeab_inbox_queue_quote_drain(5);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$capRow = $pdo->query("SELECT status, attempts, reason FROM quote_outbox WHERE idem_key = 'th-cap|laddbox'")->fetch(PDO::FETCH_ASSOC);
$capLogged = false;
foreach (jeab_quote_outbox_events('th-cap') as $event) {
    if (($event['status'] ?? '') === 'skipped' && ($event['reason'] ?? '') === 'max_attempts') {
        $capLogged = true;
    }
}
qo($capSends === 0 && (int) ($capDrain['ran'] ?? 1) === 0 && (int) ($capDrain['sent'] ?? 1) === 0, 'en full rad skickas inte');
qo(is_array($capRow) && ($capRow['status'] ?? '') === 'skipped' && (int) ($capRow['attempts'] ?? 0) === 8 && ($capRow['reason'] ?? '') === 'max_attempts', 'för många försök markeras som hoppade');
qo($capLogged, 'hoppet loggas som max_attempts');

$pdo->exec("DELETE FROM quote_outbox WHERE status IN ('pending', 'retry', 'sending')");
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_OUTBOX_AFTER_BEFORE']);
$doneIdem = 'th-done|batteri';
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at)
     VALUES (?, ?, ?, ?, 1, 0, ?, ?, ?)'
)->execute([$doneIdem, 'th-done', 'batteri', 'sent', 'sent', time(), $now]);
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at)
     VALUES (?, ?, ?, ?, 8, 0, ?, ?, ?)'
)->execute(['th-skip|laddbox', 'th-skip', 'laddbox', 'skipped', 'max_attempts', time(), $now]);
jeab_inbox_quote_queue_save([
    'th-keep' => ['at' => time(), 'product' => 'batteri', 'idem' => 'th-keep|batteri'],
]);
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at)
     VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?)'
)->execute(['th-old|sol', 'th-old', 'sol', 'pending', '', time(), $now]);
$GLOBALS['_JEAB_QUOTE_OUTBOX_AFTER_BEFORE'] = static function (PDO $pdo) use ($now): void {
    $pdo->prepare(
        'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at)
         VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?)'
    )->execute(['th-fresh|laddbox', 'th-fresh', 'laddbox', 'pending', '', time(), $now]);
};
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
jeab_inbox_quote_queue_save([
    'th-keep' => ['at' => time(), 'product' => 'batteri', 'idem' => 'th-keep|batteri'],
    'th-done' => ['at' => time(), 'product' => 'batteri', 'status' => 'pending', 'idem' => $doneIdem],
    'th-skip' => ['at' => time(), 'product' => 'laddbox', 'status' => 'pending', 'idem' => 'th-skip|laddbox'],
]);
unset($GLOBALS['_JEAB_QUOTE_OUTBOX_AFTER_BEFORE'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
qo(jeab_quote_outbox_state('th-keep', 'batteri') === 'pending', 'den sparade raden ligger kvar');
qo(jeab_quote_outbox_state('th-old', 'sol') === 'absent', 'en rad i före-bilden som saknas i bilden tas bort');
qo(jeab_quote_outbox_state('th-fresh', 'laddbox') === 'pending', 'en rad som kommer till efter före-bilden tas inte bort');
qo(jeab_quote_outbox_state('th-done', 'batteri') === 'sent', 'en skickad rad väcks inte av sparningen');
qo(jeab_quote_outbox_state('th-skip', 'laddbox') === 'skipped', 'en hoppad rad väcks inte av sparningen');

jeab_inbox_quote_queue_update(static function (array $all) use ($pdo, $now, $doneIdem): array {
    $pdo->prepare(
        'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at)
         VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?)'
    )->execute(['th-live|sol', 'th-live', 'sol', 'pending', '', time(), $now]);
    $all['th-done'] = ['at' => time(), 'product' => 'batteri', 'status' => 'pending', 'idem' => $doneIdem];

    return $all;
});
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
qo(jeab_quote_outbox_state('th-live', 'sol') === 'pending', 'en köning inne i låset överlever sparningen');
qo(jeab_quote_outbox_state('th-done', 'batteri') === 'sent', 'samma låsta sparning väcker inte en avslutad rad');
qo(jeab_quote_outbox_state('th-fresh', 'laddbox') === 'pending', 'den nya raden finns kvar efter nästa sparning');

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$hookAt = strpos($hookSrc, "inbox.quote_queue_legacy_count");
$hookWindow = $hookAt === false ? '' : substr($hookSrc, $hookAt, 500);
qo($hookAt !== false && str_contains($hookWindow, 'jeab_inkorg_hook_authorized'), 'legacy-räknaren använder samma inkorgsauth');
$countSrc = (string) file_get_contents(dirname(__DIR__) . '/src/quote_outbox.php');
$countFn = substr($countSrc, (int) strpos($countSrc, 'function jeab_inbox_quote_queue_legacy_count'));
$countFn = substr($countFn, 0, (int) strpos($countFn, 'function jeab_quote_product_sender_email'));
qo(!preg_match('/\b(file_put_contents|fwrite|unlink|INSERT|UPDATE|DELETE)\b/', $countFn), 'legacy-räknaren skriver inte');

$threadJson = json_encode(['company' => 'malmo', 'name' => 'Nora Testsson', 'email' => 'nora.testsson@example.com'], JSON_UNESCAPED_UNICODE);
$pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json) VALUES (?, ?, ?, ?)')->execute(['th-leg-t', 'open', $now, $threadJson]);
$queueFile = jeab_data_dir() . '/quote-queue.json';
$queueBody = json_encode([
    'th-leg-j' => ['at' => 1, 'company' => 'jarfalla', 'email' => 'anna.exempel@example.com', 'name' => 'Anna Exempel'],
    'th-leg-t' => ['at' => 2, 'product' => 'sol', 'name' => 'Nora Testsson'],
    'bad.kund@example.com' => ['at' => 3, 'company' => 'jarfalla'],
], JSON_UNESCAPED_UNICODE);
file_put_contents($queueFile, $queueBody);
require_once dirname(__DIR__) . '/src/migrate/quote_queue.php';
jeab_migrate_ensure($pdo, jeab_migrate_quote_queue_module());
$cutBody = json_encode([
    'th-leg-c' => ['at' => 4, 'company' => 'Malmö', 'email' => 'kund.meab@example.com'],
    'secret.kund@example.com' => ['at' => 5, 'company' => 'jarfalla', 'name' => 'Hemlig Kund'],
], JSON_UNESCAPED_UNICODE);
$pdo->prepare('INSERT INTO quote_queue (doc_key, payload, updated_at) VALUES (?, ?, ?)')->execute(['quote_queue', $cutBody, $now]);
$beforeFile = (string) file_get_contents($queueFile);
$beforeDoc = (string) $pdo->query("SELECT payload FROM quote_queue WHERE doc_key = 'quote_queue'")->fetchColumn();
$beforeOutbox = (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn();
$legacy = jeab_inkorg_hook(['action' => 'inbox.quote_queue_legacy_count']);
$again = jeab_inbox_quote_queue_legacy_count();
$encoded = json_encode($legacy, JSON_UNESCAPED_UNICODE);
qo(($legacy['ok'] ?? false) === true && ($legacy['read_only'] ?? false) === true && (int) ($legacy['sent'] ?? 1) === 0 && ($legacy['would_send'] ?? true) === false, 'legacy-räknaren är bara läsning');
qo((int) ($legacy['json']['total'] ?? 0) === 2 && (int) ($legacy['json']['companies']['jarfalla'] ?? 0) === 1 && (int) ($legacy['json']['companies']['malmo'] ?? 0) === 1, 'json-filen räknas per bolag');
qo((int) ($legacy['cutover']['total'] ?? 0) === 1 && (int) ($legacy['cutover']['companies']['malmo'] ?? 0) === 1, 'cutover-dokumentet räknas per bolag');
qo((int) ($legacy['total'] ?? 0) === 3 && (int) ($legacy['companies']['jarfalla'] ?? 0) === 1 && (int) ($legacy['companies']['malmo'] ?? 0) === 2 && (int) ($legacy['companies']['other'] ?? 1) === 0, 'totalen är båda lagren');
qo($again === $legacy, 'en andra läsning ger samma antal');
qo((string) file_get_contents($queueFile) === $beforeFile && (string) $pdo->query("SELECT payload FROM quote_queue WHERE doc_key = 'quote_queue'")->fetchColumn() === $beforeDoc && (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn() === $beforeOutbox, 'räknaren lämnar fil och SQL orörda');
$hidden = ['th-leg-j', 'th-leg-t', 'th-leg-c', 'anna.exempel@example.com', 'Nora Testsson', 'kund.meab@example.com', 'secret.kund@example.com', 'Hemlig Kund', 'bad.kund@example.com'];
$leaked = false;
foreach ($hidden as $needle) {
    if (is_string($encoded) && str_contains($encoded, $needle)) {
        $leaked = true;
    }
}
qo(!$leaked, 'svaret har inga tråd-id och ingen kundtext');

$qoDriver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
qo(jeab_settings_put($pdo, $qoDriver, 'inkorg_storage', 'sql'), 'inkorgen ligger i SQL för produktspärren');
$qoStore = jeab_inkorg_pdo_store();
qo($qoStore instanceof JeabPdoInkorgStore, 'inkorgsbutiken är öppen');
$qoForget = static function (): void {
    unset(
        $GLOBALS['_JEAB_INKORG_META'],
        $GLOBALS['_JEAB_INKORG_META_FP'],
        $GLOBALS['_JEAB_INKORG_THREADS'],
        $GLOBALS['_JEAB_INKORG_THREADS_FP'],
        $GLOBALS['_JEAB_INKORG_LIGHT']
    );
};
$qoPut = static function (string $tid, string $offer, string $email, string $name, string $note = '') use ($qoStore, $qoForget): array {
    $body = "Namn: {$name}\nE-post: {$email}\nTelefon: 0701112288\n";
    $labels = ['type' => 'Ny lead', 'confidence' => 90, 'source' => 'rule'];
    if ($offer !== '') {
        $body .= "Produkt: {$offer}\n";
        $labels['offer'] = $offer;
        $labels['product'] = $offer;
    }
    $qoStore->upsertMessage($tid . '-m', [
        'id' => $tid . '-m',
        'thread_id' => $tid,
        'from' => $email,
        'from_name' => $name,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Offert',
        'body' => $body,
        'received_at' => date('c'),
        'source' => 'graph-jarfalla',
        'labels' => $labels,
    ]);
    $meta = [
        'status' => 'open',
        'tags' => $offer === '' ? [] : [$offer],
        'company' => 'jarfalla',
        'customer_email' => $email,
        'customer_name' => $name,
        'updated_at' => date('c'),
    ];
    if ($note !== '') {
        $meta['drafts'] = [['kind' => 'note', 'body' => $note, 'created_at' => date('c')]];
    }
    $qoStore->upsertThread($tid, $meta);
    $qoForget();
    $thread = jeab_auto_thread_by_id($tid);

    return is_array($thread) ? $thread : [];
};
$qoRow = static function (string $idem) use (&$pdo): ?array {
    $stmt = $pdo->prepare('SELECT status, attempts, product, next_attempt_at FROM quote_outbox WHERE idem_key = ?');
    $stmt->execute([$idem]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
};
$qoManual = static function (string $idem) use (&$pdo): int {
    $stmt = $pdo->prepare('SELECT manual_send FROM quote_outbox WHERE idem_key = ?');
    $stmt->execute([$idem]);

    return (int) $stmt->fetchColumn();
};
$qoFlags = static function () use (&$pdo): array {
    $flags = [];
    foreach ($pdo->query('SELECT idem_key, manual_send FROM quote_outbox')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $flags[(string) ($row['idem_key'] ?? '')] = (int) ($row['manual_send'] ?? 0);
    }

    return $flags;
};

$notThread = $qoPut('pr2-not', 'batteri', 'fejk.not.pr2@exempel.test', 'Fejk Not', 'Offert skickades inte automatiskt: klassningen misslyckades.');
qo($notThread !== [] && jeab_inkorg_quote_sent_in_thread($notThread, 'batteri') === false, 'skickades inte räknas inte i ärendet');
qo(jeab_inkorg_quote_sent_in_thread($notThread, '') === false, 'skickades inte räknas inte när produkten saknas');
$otherNote = $qoPut('pr2-other', 'batteri', 'fejk.other.pr2@exempel.test', 'Fejk Other', 'Offert (Elbilsladdare) skickad');
qo(jeab_inkorg_quote_sent_in_thread($otherNote, 'batteri') === false, 'en annan produkt räknas inte i ärendet');
qo(jeab_inkorg_quote_sent_in_thread($otherNote, 'laddbox') === true, 'laddbox-anteckningen räknas för laddbox');
$sameNote = $qoPut('pr2-same', 'batteri', 'fejk.same.pr2@exempel.test', 'Fejk Same', 'Offert (Batteri) skickad');
qo(jeab_inkorg_quote_sent_in_thread($sameNote, 'batteri') === true, 'samma produkt räknas i ärendet');
qo(jeab_auto_send_quote_already_out('pr2-same', $sameNote) === true, 'samma produkt stoppar utskicksspärren');
qo(jeab_auto_send_quote_already_out('pr2-other', $otherNote) === false, 'en annan produkt stoppar inte utskicksspärren');

$cacheThread = $qoPut('pr2-cache', 'batteri', 'fejk.cache.pr2@exempel.test', 'Fejk Cache');
jeab_auto_quote_sent_remember('pr2-cache', 'note', ['hit' => true, 'date' => '2026-10-01'], 'batteri', true);
$qoForget();
$cacheThread = jeab_auto_thread_by_id('pr2-cache') ?? [];
qo($cacheThread !== [] && jeab_quote_chain_thread_quoted('pr2-cache', 'batteri') === false, 'cachad diagnos räknas inte i kedjan');
qo(jeab_auto_send_quote_already_out('pr2-cache', $cacheThread) === false, 'cachad diagnos stoppar inte utskicksspärren');
qo(jeab_inkorg_relabel_offer_reminder_quoted([
    'title' => 'Fejk Cache – Batteri',
    'note' => '',
    'quote_sent' => ['cached' => true, 'source' => 'anteckning', 'date' => '2026-10-01', 'at' => date('c'), 'product' => 'batteri'],
], 'batteri') === false, 'cachad diagnos räknas inte på påminnelsen');
qo(jeab_inkorg_relabel_offer_reminder_quoted([
    'title' => 'Fejk Cache – Batteri',
    'note' => '',
    'quote_sent' => ['source' => 'anteckning', 'date' => '2026-10-01', 'at' => date('c'), 'product' => 'batteri'],
], 'batteri') === true, 'en riktig markör på samma produkt räknas på påminnelsen');
qo(jeab_inkorg_relabel_offer_reminder_quoted([
    'title' => 'Fejk Cache – Elbilsladdare',
    'note' => 'Offert (Elbilsladdare) skickad',
], 'batteri') === false, 'påminnelse för en annan produkt stoppar inte');
qo(jeab_inkorg_relabel_offer_reminder_quoted([
    'title' => 'Fejk Cache – Batteri',
    'note' => 'Offert (Batteri) skickad',
], 'batteri') === true, 'påminnelse för samma produkt stoppar');

$remSame = $qoPut('pr2-rem', 'batteri', 'fejk.rem.pr2@exempel.test', 'Fejk Rem');
jeab_create_reminder(jeab_store(), 'Fejk Rem – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Fejk Rem',
    'email' => 'fejk.rem.pr2@exempel.test',
    'phone' => '0701112205',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'Offert (Batteri) skickad',
    'thread_id' => 'pr2-rem-old',
]);
$remMsg = jeab_auto_quote_lead_message($remSame);
qo(jeab_follow_prior_quote($remSame, ['name' => 'Fejk Rem', 'email' => 'fejk.rem.pr2@exempel.test', 'phone' => '0701112205'], true) === true, 'uppföljningen stoppar samma produkt');
qo(jeab_inkorg_relabel_offer_already($remSame, $remMsg) === true, 'relabel stoppar samma produkt');
$remOther = $qoPut('pr2-rem2', 'batteri', 'fejk.rem2.pr2@exempel.test', 'Fejk Rem2');
jeab_create_reminder(jeab_store(), 'Fejk Rem2 – Elbilsladdare', jeab_today_start(), null, null, [
    'customer' => 'Fejk Rem2',
    'email' => 'fejk.rem2.pr2@exempel.test',
    'phone' => '0701112206',
    'company' => 'jarfalla',
    'who' => 'Elin',
    'note' => 'Offert (Elbilsladdare) skickad',
    'thread_id' => 'pr2-rem2-old',
]);
$remOtherMsg = jeab_auto_quote_lead_message($remOther);
qo(jeab_follow_prior_quote($remOther, ['name' => 'Fejk Rem2', 'email' => 'fejk.rem2.pr2@exempel.test', 'phone' => '0701112206'], true) === false, 'uppföljningen stoppar inte en annan produkt');
qo(jeab_inkorg_relabel_offer_already($remOther, $remOtherMsg) === false, 'relabel stoppar inte en annan produkt');

jeab_auto_quote_sent_remember('pr2-cache', 'note', ['hit' => true, 'date' => '2026-10-02'], 'batteri', false);
$qoForget();
qo(jeab_quote_chain_thread_quoted('pr2-cache', 'batteri') === true, 'samma produkt stoppar kedjan');
qo(jeab_quote_chain_thread_quoted('pr2-cache', 'laddbox') === false, 'en annan produkt stoppar inte kedjan');

$real = $qoPut('pr2-real', 'batteri', 'fejk.real.pr2@exempel.test', 'Fejk Real');
$realIdem = 'pr2-real|offert|batteri';
jeab_quote_send_commit('pr2-real', 'saljare3@jarfalla.example.test', 'manual', 'test', 'batteri', $realIdem);
$sentAfter = (int) $pdo->query('SELECT COUNT(*) FROM inbox_sent')->fetchColumn();
jeab_quote_send_commit('pr2-real', 'saljare3@jarfalla.example.test', 'manual', 'test', 'batteri', $realIdem);
qo((int) $pdo->query('SELECT COUNT(*) FROM inbox_sent')->fetchColumn() === $sentAfter, 'samma utskick skrivs inte en gång till');
$qoForget();
$real = jeab_auto_thread_by_id('pr2-real') ?? [];
qo(jeab_quote_chain_thread_quoted('pr2-real', 'batteri') === true && jeab_auto_send_quote_already_out('pr2-real', $real) === true, 'ett riktigt utskick stoppar samma produkt');
qo(jeab_quote_chain_thread_quoted('pr2-real', 'laddbox') === false, 'ett riktigt utskick stoppar inte en annan produkt');
$sentBefore = $GLOBALS['_SENT'];
$realWhy = jeab_auto_quote_already_sent($real, 'saljare3@jarfalla.example.test', 'fejk.real.pr2@exempel.test', 'batteri');
qo($realWhy !== '' && $GLOBALS['_SENT'] === $sentBefore, 'nästa utskick stoppas utan att något skickas');

$unknown = $qoPut('pr2-unk', '', 'fejk.unk.pr2@exempel.test', 'Fejk Unk');
jeab_quote_send_commit('pr2-unk', 'saljare3@jarfalla.example.test', 'manual', 'test', '', 'pr2-unk|offert|');
$qoForget();
$unknown = jeab_auto_thread_by_id('pr2-unk') ?? [];
qo(jeab_quote_chain_thread_quoted('pr2-unk', '') === true && jeab_quote_chain_thread_quoted('pr2-unk', 'batteri') === true, 'en okänd produkt stoppar ett andra utskick');
qo(jeab_auto_send_quote_already_out('pr2-unk', $unknown) === true, 'utskicksspärren håller när produkten saknas');

$onceThread = $qoPut('pr2-once', 'batteri', 'fejk.once.pr2@exempel.test', 'Fejk Once');
$onceKeys = jeab_offer_idem_keys('pr2-once', 'fejk.once.pr2@exempel.test', 'batteri', 0);
jeab_send_once_remember('offert', $onceKeys, 600, ['ok' => true, 'already_sent' => true]);
qo(jeab_send_once_peek('offert', $onceKeys, 600) !== null, 'send_once har samma nyckel');
$guardThread = $qoPut('pr2-guard', 'batteri', 'fejk.guard.pr2@exempel.test', 'Fejk Guard');
$guardOther = $qoPut('pr2-guard2', 'batteri', 'fejk.guard2.pr2@exempel.test', 'Fejk Guard2');
unset($onceThread, $guardThread, $guardOther);
jeab_send_guard_ensure($pdo);
$guardNow = jeab_quote_outbox_now();
$guardIns = $pdo->prepare('INSERT INTO send_queue (channel, template, rcpt_hash, body_hash, not_before, created_at, job, status, attempts) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)');
$guardIns->execute(['email', 'offert-batteri', hash('sha256', 'guard-a'), hash('sha256', 'guard-b'), $guardNow, $guardNow, base64_encode(serialize(['thread_id' => 'pr2-guard'])), 'waiting']);
$guardIns->execute(['email', 'offert-laddbox', hash('sha256', 'guard-c'), hash('sha256', 'guard-d'), $guardNow, $guardNow, base64_encode(serialize(['thread_id' => 'pr2-guard2'])), 'waiting']);
qo(jeab_quote_offer_already_out('pr2-once', 'batteri') === true, 'send_once för samma produkt stoppar');
qo(jeab_quote_offer_already_out('pr2-once', 'laddbox') === false, 'send_once för en annan produkt stoppar inte');
qo(jeab_quote_offer_already_out('pr2-guard', 'batteri') === false, 'send-guard waiting stoppar inte');
qo(jeab_quote_offer_already_out('pr2-guard', 'laddbox') === false, 'send-guard waiting för en annan produkt stoppar inte');
qo(jeab_quote_offer_already_out('pr2-guard2', 'laddbox') === false, 'send-guard waiting för laddbox stoppar inte');
qo(jeab_quote_offer_already_out('pr2-guard2', 'batteri') === false, 'send-guard waiting för batteri stoppar inte');
qo(jeab_quote_offer_already_out('pr2-real', 'batteri') === true, 'sent-loggen stoppar samma produkt');
qo(jeab_quote_offer_already_out('pr2-real', 'laddbox') === false, 'sent-loggen stoppar inte en annan produkt');
qo(jeab_quote_offer_already_out('pr2-unk', '') === true && jeab_quote_offer_already_out('pr2-unk', 'batteri') === true, 'en okänd produkt stoppar ett andra utskick i spärren');

$staleTid = 'pr2-stale';
$stale = $qoPut($staleTid, 'batteri', 'fejk.stale.pr2@exempel.test', 'Fejk Stale');
qo($stale !== [], 'tråden med gammal nyckel finns');
jeab_inkorg_thread_write($staleTid, static function (array $t) use ($staleTid): array {
    $t['relabel_offer_idem'] = [$staleTid . '|batteri' => ['at' => time(), 'offer' => 'batteri']];

    return $t;
});
$qoForget();
$stale = jeab_auto_thread_by_id($staleTid) ?? [];
$staleMsg = jeab_quote_chain_thread_message($stale);
$staleMap = jeab_inkorg_relabel_offer_idem_map($staleTid);
qo(
    isset($staleMap[$staleTid . '|batteri']) && empty($staleMap[$staleTid . '|batteri']['sent']),
    'gamla nyckeln ligger kvar utan sent'
);
qo(jeab_inkorg_relabel_offer_idem_seen($staleTid, 'batteri') === false, 'redan köad kräver en kö-rad');
qo(jeab_inkorg_relabel_offer_already($stale, $staleMsg) === false, 'gammal idempotensnyckel utan utskick är inte redan skickad');
qo(jeab_quote_offer_already_out($staleTid, 'batteri') === false, 'gammal idempotensnyckel stoppar inte utskicket');
$staleDry = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $staleTid, 'product' => 'batteri']);
qo(($staleDry['already_out'] ?? true) === false && !in_array('already_out', (array) ($staleDry['blockers'] ?? []), true), 'dry ser inte den gamla nyckeln som utskick');
jeab_inkorg_relabel_offer_idem_remember($staleTid, 'batteri', true);
$qoForget();
$stale = jeab_auto_thread_by_id($staleTid) ?? [];
$staleMsg = jeab_quote_chain_thread_message($stale);
qo(jeab_inkorg_relabel_offer_idem_after_send($staleTid, 'batteri') === true, 'nyckeln efter utskick är märkt');
qo(jeab_inkorg_relabel_offer_already($stale, $staleMsg) === true, 'nyckeln efter ett lyckat utskick är redan skickad');
qo(jeab_inkorg_relabel_offer_idem_seen($staleTid, 'batteri') === false, 'märkt nyckel utan kö-rad är inte redan köad');
qo(jeab_quote_offer_already_out($staleTid, 'batteri') === true, 'nyckeln efter utskick stoppar samma produkt');
qo(jeab_quote_offer_already_out($staleTid, 'laddbox') === false, 'nyckeln efter utskick stoppar inte en annan produkt');

$statNext = time() + 80;
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at)
     VALUES (?, ?, ?, ?, 3, ?, ?, ?, ?)'
)->execute(['pr2-stat|batteri', 'pr2-stat', 'batteri', 'pending', $statNext, 'väntar fejk.status.pr2@exempel.test 0701234567', time(), jeab_quote_outbox_now()]);
$statBefore = (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn();
$stat = jeab_inkorg_hook(['action' => 'inbox.outbox_status', 'thread_id' => 'pr2-stat', 'product' => 'batteri']);
$statBlob = json_encode($stat, JSON_UNESCAPED_UNICODE);
qo(
    ($stat['ok'] ?? false) === true
    && ($stat['read_only'] ?? false) === true
    && (int) ($stat['dry'] ?? 0) === 1
    && (int) ($stat['sent'] ?? 1) === 0
    && ($stat['would_send'] ?? true) === false
    && (int) ($stat['counts']['pending'] ?? 0) >= 1
    && array_key_exists('retry', (array) ($stat['counts'] ?? []))
    && array_key_exists('sending', (array) ($stat['counts'] ?? []))
    && array_key_exists('sent', (array) ($stat['counts'] ?? []))
    && array_key_exists('skipped', (array) ($stat['counts'] ?? []))
    && ($stat['state'] ?? '') === 'pending'
    && (int) ($stat['attempts'] ?? 0) === 3
    && (int) ($stat['next_attempt_at'] ?? 0) === $statNext
    && ($stat['reason'] ?? '') === 'väntar',
    'outbox_status räknar och visar trådens rad'
);
qo(
    is_string($statBlob)
    && !str_contains($statBlob, 'fejk.status.pr2@exempel.test')
    && !str_contains($statBlob, '0701234567'),
    'outbox_status har ingen adress eller telefon'
);
$statAgain = jeab_inkorg_hook(['action' => 'inbox.outbox_status', 'thread_id' => 'pr2-stat', 'product' => 'batteri']);
qo($statAgain == $stat && (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn() === $statBefore, 'outbox_status skriver inget');
$statBad = jeab_inkorg_hook(['action' => 'inbox.outbox_status', 'thread_id' => 'fejk.status.pr2@exempel.test']);
$statBadBlob = json_encode($statBad, JSON_UNESCAPED_UNICODE);
qo(($statBad['ok'] ?? true) === false && is_string($statBadBlob) && !str_contains($statBadBlob, '@'), 'outbox_status ekar inte en adress som tråd-id');
$statBare = jeab_inkorg_hook(['action' => 'inbox.outbox_status']);
qo(($statBare['ok'] ?? false) === true && !array_key_exists('thread_id', $statBare) && (int) ($statBare['counts']['pending'] ?? 0) >= 1, 'outbox_status utan tråd är bara antal');

putenv('OFFER_SEND_ONE_ENABLED');
unset($_ENV['OFFER_SEND_ONE_ENABLED'], $GLOBALS['_JEAB_OFFER_SEND_ONE_ENABLED'], $GLOBALS['_JEAB_QUOTE_DRAIN_SEND']);
qo(jeab_offer_send_one_enabled() === false, 'flaggan är av som standard');
$one = $qoPut('pr2-one', 'batteri', 'fejk.one.pr2@exempel.test', 'Fejk One');
qo($one !== [], 'tråden för ett utskick finns');
$oneBefore = (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn();
$oneDry = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => 'pr2-one']);
$oneBlob = json_encode($oneDry, JSON_UNESCAPED_UNICODE);
qo(
    ($oneDry['ok'] ?? false) === true
    && (int) ($oneDry['dry'] ?? 0) === 1
    && ($oneDry['read_only'] ?? false) === true
    && (int) ($oneDry['sent'] ?? 1) === 0
    && ($oneDry['would_send'] ?? false) === true
    && ($oneDry['already_out'] ?? true) === false
    && ($oneDry['blockers'] ?? null) === []
    && ($oneDry['template'] ?? '') === 'offert-batteri-jarfalla'
    && ($oneDry['sender'] ?? '') === 'saljare3@jarfalla.example.test'
    && ($oneDry['seller'] ?? '') !== ''
    && !str_contains((string) ($oneDry['seller'] ?? ''), '@')
    && ($oneDry['outbox'] ?? '') === 'absent'
    && ($oneDry['product'] ?? '') === 'batteri'
    && (int) ($oneDry['locked'] ?? 1) === 0,
    'dry visar mall, säljare, avsändare och utbox'
);
qo(
    is_string($oneBlob)
    && !str_contains($oneBlob, 'fejk.one.pr2@exempel.test')
    && !str_contains($oneBlob, 'Fejk One')
    && !str_contains($oneBlob, '0701112288'),
    'dry har ingen kundtext'
);
qo((int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn() === $oneBefore, 'dry skriver inte');
$oneQuiet = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => 'pr2-one', 'dry' => 0, 'confirm' => 1]);
qo(
    ($oneQuiet['read_only'] ?? false) === true
    && (int) ($oneQuiet['sent'] ?? 1) === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn() === $oneBefore,
    'dry=0 utan confirm=send skriver inte'
);
$oneLocked = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => 'pr2-one', 'dry' => '0', 'confirm' => 'send']);
qo(
    ($oneLocked['ok'] ?? false) === true
    && (int) ($oneLocked['queued'] ?? 0) === 1
    && ($oneLocked['error'] ?? '') !== 'locked'
    && (int) ($oneLocked['locked'] ?? 1) === 0
    && (int) ($oneLocked['sent'] ?? 1) === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn() === $oneBefore + 1,
    'avstängd flagga köar ändå'
);
$oneBefore = (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn();
$offerFlagHome = sys_get_temp_dir() . '/jeab-offer-flag-' . bin2hex(random_bytes(4));
$offerFlagRoot = $offerFlagHome . '/public_html';
mkdir($offerFlagRoot, 0700, true);
$offerFlagPrevRoot = $GLOBALS['_JEAB_APP_ROOT'] ?? null;
$offerFlagPrevEnv = getenv('JEAB_APP_ROOT');
$GLOBALS['_JEAB_APP_ROOT'] = $offerFlagRoot;
putenv('JEAB_APP_ROOT=' . $offerFlagRoot);
$offerFlagSecrets = dirname(jeab_inkorg_token_path());
if (!is_dir($offerFlagSecrets)) {
    mkdir($offerFlagSecrets, 0700, true);
}
$offerFlagTokenPath = $offerFlagSecrets . '/inkorg-ingest.json';
$offerFlagTokenPrev = is_file($offerFlagTokenPath) ? (string) file_get_contents($offerFlagTokenPath) : null;
file_put_contents($offerFlagTokenPath, json_encode(['key' => 'tok-offer-flag']));
$offerFlagServer = [
    'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null,
    'HTTP_AUTHORIZATION' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'HTTP_X_WEBHOOK_TOKEN' => $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null,
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-offer-flag';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';
$offerFlagDrySet = jeab_flags_set_dispatch(['name' => 'OFFER_SEND_ONE_ENABLED', 'value' => 1]);
qo(
    !empty($offerFlagDrySet['ok'])
    && ($offerFlagDrySet['dry'] ?? false) === true
    && ($offerFlagDrySet['written'] ?? true) === false
    && ($offerFlagDrySet['old'] ?? 1) === 0
    && ($offerFlagDrySet['new'] ?? 0) === 1
    && !is_file($offerFlagHome . '/privat/flags.json')
    && jeab_offer_send_one_enabled() === false,
    'flags.set utan dry skriver inte och lämnar spärren'
);
$offerFlagSet = jeab_flags_set_dispatch(['name' => 'OFFER_SEND_ONE_ENABLED', 'value' => 1, 'dry' => 0]);
$offerFlagOn = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => 'pr2-one']);
qo(
    !empty($offerFlagSet['ok'])
    && ($offerFlagSet['written'] ?? false) === true
    && ($offerFlagSet['source'] ?? '') === 'file'
    && jeab_flag_resolve('OFFER_SEND_ONE_ENABLED')['source'] === 'file'
    && jeab_offer_send_one_enabled() === true
    && ($offerFlagOn['ok'] ?? false) === true
    && (int) ($offerFlagOn['dry'] ?? 0) === 1
    && (int) ($offerFlagOn['locked'] ?? 1) === 0
    && (int) ($offerFlagOn['sent'] ?? 1) === 0
    && ($offerFlagOn['read_only'] ?? false) === true
    && (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn() === $oneBefore,
    'flaggan på via flags.set låser upp och dry är kvar'
);
putenv('OFFER_SEND_ONE_ENABLED=0');
$_ENV['OFFER_SEND_ONE_ENABLED'] = '0';
qo(jeab_offer_send_one_enabled() === false && jeab_flag_resolve('OFFER_SEND_ONE_ENABLED')['source'] === 'env', 'env 0 vinner över filen');
putenv('OFFER_SEND_ONE_ENABLED');
unset($_ENV['OFFER_SEND_ONE_ENABLED']);
qo(jeab_offer_send_one_enabled() === true && jeab_flag_resolve('OFFER_SEND_ONE_ENABLED')['source'] === 'file', 'utan env gäller filen');
if (is_string($offerFlagPrevRoot) && $offerFlagPrevRoot !== '') {
    $GLOBALS['_JEAB_APP_ROOT'] = $offerFlagPrevRoot;
} else {
    unset($GLOBALS['_JEAB_APP_ROOT']);
}
if ($offerFlagPrevEnv === false || $offerFlagPrevEnv === '') {
    putenv('JEAB_APP_ROOT');
} else {
    putenv('JEAB_APP_ROOT=' . $offerFlagPrevEnv);
}
foreach ($offerFlagServer as $offerFlagKey => $offerFlagValue) {
    if ($offerFlagValue === null) {
        unset($_SERVER[$offerFlagKey]);
    } else {
        $_SERVER[$offerFlagKey] = $offerFlagValue;
    }
}
if ($offerFlagTokenPrev === null) {
    @unlink($offerFlagTokenPath);
} else {
    file_put_contents($offerFlagTokenPath, $offerFlagTokenPrev);
}
qo(jeab_offer_send_one_enabled() === false, 'flaggan är av igen när filen inte läses');
$oneBad = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => 'fejk.one.pr2@exempel.test', 'dry' => 0, 'confirm' => 'send']);
$oneBadBlob = json_encode($oneBad, JSON_UNESCAPED_UNICODE);
qo(($oneBad['ok'] ?? true) === false && is_string($oneBadBlob) && !str_contains($oneBadBlob, 'fejk.one.pr2@exempel.test'), 'offer_send_one ekar inte en adress som tråd-id');

$qoStore->upsertMessage('pr2-solm-m', [
    'id' => 'pr2-solm-m',
    'thread_id' => 'pr2-solm',
    'from' => 'fejk.solm.pr2@exempel.test',
    'from_name' => 'Fejk Solm',
    'to' => 'kontakt@malmo.example.test',
    'subject' => 'Offert',
    'body' => "Namn: Fejk Solm\nE-post: fejk.solm.pr2@exempel.test\nTelefon: 0701112288\nProdukt: sol\n",
    'received_at' => date('c'),
    'source' => 'graph-malmo',
    'graph_mailbox' => 'kontakt@malmo.example.test',
    'labels' => ['type' => 'Ny lead', 'offer' => 'sol', 'product' => 'sol', 'confidence' => 90, 'source' => 'rule'],
]);
$qoStore->upsertThread('pr2-solm', [
    'status' => 'open',
    'tags' => ['sol'],
    'company' => 'malmo',
    'customer_email' => 'fejk.solm.pr2@exempel.test',
    'customer_name' => 'Fejk Solm',
    'updated_at' => date('c'),
]);
$qoForget();
$solDry = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => 'pr2-solm', 'product' => 'sol']);
qo(
    ($solDry['sender'] ?? '') === 'saljare.syd@malmo.example.test'
    && ($solDry['seller'] ?? '') !== ''
    && !str_contains((string) ($solDry['seller'] ?? ''), '@')
    && in_array('Malmö/Skåne', (array) ($solDry['blockers'] ?? []), true)
    && ($solDry['would_send'] ?? true) === false,
    'tom ägare för sol i Malmö blir Kim och regionen stoppar'
);

$GLOBALS['_JEAB_OFFER_SEND_ONE_ENABLED'] = true;
$GLOBALS['_JEAB_OFFER_SEND_ONE_SYNC'] = true;
$GLOBALS['_JEAB_TICK'] = 'outer';
$tickDuring = null;
$drainCalls = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function (string $tid, string $product) use (&$tickDuring, &$drainCalls): array {
    $tickDuring = $GLOBALS['_JEAB_TICK'] ?? null;
    $drainCalls++;

    return ['ok' => true, 'sent' => 1, 'action' => 'quoted', 'thread_id' => $tid, 'product' => $product];
};
$oneSent = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => 'pr2-one', 'dry' => 0, 'confirm' => 'send']);
$oneRow = $qoRow('pr2-one|batteri');
qo(
    ($oneSent['ok'] ?? false) === true
    && (int) ($oneSent['sent'] ?? 0) === 1
    && ($oneSent['read_only'] ?? true) === false
    && $tickDuring === true
    && ($GLOBALS['_JEAB_TICK'] ?? null) === 'outer'
    && $drainCalls === 1
    && is_array($oneRow)
    && ($oneRow['status'] ?? '') === 'sent'
    && ($oneSent['outbox'] ?? '') === 'sent'
    && jeab_inkorg_relabel_offer_idem_after_send('pr2-one', 'batteri') === true,
    'bekräftat utskick går genom utboxen och återställer tick'
);
$oneAgain = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => 'pr2-one', 'dry' => 0, 'confirm' => 'send', 'product' => 'batteri']);
qo(
    ($oneAgain['ok'] ?? true) === false
    && ($oneAgain['error'] ?? '') === 'already_out'
    && (int) ($oneAgain['sent'] ?? 1) === 0
    && ($oneAgain['already_out'] ?? false) === true
    && $drainCalls === 1,
    'samma produkt skickas inte en gång till'
);
$oneOther = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => 'pr2-one', 'product' => 'laddbox']);
qo(
    ($oneOther['already_out'] ?? true) === false
    && ($oneOther['sender'] ?? '') === 'saljare4@jarfalla.example.test'
    && ($oneOther['product'] ?? '') === 'laddbox'
    && ($oneOther['outbox'] ?? '') === 'absent',
    'en annan produkt är inte redan skickad'
);
$realDry = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => 'pr2-real', 'product' => 'batteri']);
qo(($realDry['already_out'] ?? false) === true && ($realDry['would_send'] ?? true) === false, 'dry på sent-loggen är already-out');
$unkDry = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => 'pr2-unk']);
qo(
    ($unkDry['already_out'] ?? false) === true
    && in_array('product', (array) ($unkDry['blockers'] ?? []), true)
    && ($unkDry['would_send'] ?? true) === false,
    'okänd produkt släpper inget andra utskick'
);

$throwTid = 'pr2-throw';
$throwThread = $qoPut($throwTid, 'batteri', 'fejk.throw.pr2@exempel.test', 'Fejk Throw');
qo($throwThread !== [], 'tråden för kastet finns');
$tickDuring = null;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$tickDuring): array {
    $tickDuring = $GLOBALS['_JEAB_TICK'] ?? null;
    throw new RuntimeException('fejk kast med fejk.throw.pr2@exempel.test');
};
$thrown = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $throwTid, 'dry' => '0', 'confirm' => 'send']);
$throwRow = $qoRow($throwTid . '|batteri');
$throwBlob = json_encode($thrown, JSON_UNESCAPED_UNICODE);
qo(
    ($thrown['ok'] ?? true) === false
    && (int) ($thrown['sent'] ?? 1) === 0
    && $tickDuring === true
    && ($GLOBALS['_JEAB_TICK'] ?? null) === 'outer'
    && is_array($throwRow)
    && ($throwRow['status'] ?? '') === 'retry'
    && is_string($throwBlob)
    && !str_contains($throwBlob, 'fejk.throw.pr2@exempel.test'),
    'ett kast återställer tick och lämnar raden i retry'
);
$misTid = 'pr2-mis';
$misThread = $qoPut($misTid, 'batteri', 'fejk.mis.pr2@exempel.test', 'Fejk Mis');
qo($misThread !== [], 'tråden för fel produkt finns');
$misBefore = (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn();
$misCalls = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$misCalls): array {
    $misCalls++;

    return ['ok' => true, 'sent' => 1, 'action' => 'quoted'];
};
$misDry = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $misTid, 'product' => 'laddbox']);
qo(
    ($misDry['ok'] ?? false) === true
    && ($misDry['error'] ?? '') !== 'product_mismatch'
    && !in_array('product_mismatch', (array) ($misDry['blockers'] ?? []), true)
    && ($misDry['would_send'] ?? false) === true
    && (int) ($misDry['sent'] ?? 1) === 0
    && (int) ($misDry['dry'] ?? 0) === 1
    && (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn() === $misBefore
    && jeab_quote_outbox_state($misTid, 'laddbox') === 'absent'
    && $misCalls === 0,
    'dry med annan produkt är ingen mismatch och skriver inte'
);
$misLive = jeab_inkorg_hook([
    'action' => 'inbox.offer_send_one',
    'thread_id' => $misTid,
    'product' => 'laddbox',
    'dry' => 0,
    'confirm' => 'send',
]);
qo(
    ($misLive['ok'] ?? false) === true
    && ($misLive['error'] ?? '') !== 'product_mismatch'
    && !in_array('product_mismatch', (array) ($misLive['blockers'] ?? []), true)
    && (int) ($misLive['sent'] ?? 0) === 1
    && (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn() === $misBefore + 1
    && jeab_quote_outbox_state($misTid, 'laddbox') === 'sent'
    && $misCalls === 1,
    'live med annan produkt skickas'
);
$plainOfferTid = 'pr2-offer-plain';
$plainOffer = $qoPut($plainOfferTid, '', 'fejk.plainoffer.pr2@exempel.test', 'Fejk Plain');
qo($plainOffer !== [] && jeab_quote_outbox_classified_offer($plainOfferTid) === '', 'tråden för offer_send_one utan produkt är oklassad');
$plainOfferBefore = (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn();
$plainOfferCalls = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$plainOfferCalls): array {
    $plainOfferCalls++;

    return ['ok' => true, 'sent' => 1, 'action' => 'quoted'];
};
$plainOfferDry = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $plainOfferTid]);
qo(
    ($plainOfferDry['error'] ?? '') !== 'product_unclassified'
    && !in_array('product_unclassified', (array) ($plainOfferDry['blockers'] ?? []), true)
    && in_array('product', (array) ($plainOfferDry['blockers'] ?? []), true)
    && ($plainOfferDry['would_send'] ?? true) === false
    && (int) ($plainOfferDry['sent'] ?? 1) === 0
    && (int) ($plainOfferDry['dry'] ?? 0) === 1
    && (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn() === $plainOfferBefore
    && $plainOfferCalls === 0,
    'dry utan produkt visar product och skriver inte'
);
$plainOfferLive = jeab_inkorg_hook([
    'action' => 'inbox.offer_send_one',
    'thread_id' => $plainOfferTid,
    'dry' => 0,
    'confirm' => 'send',
]);
qo(
    ($plainOfferLive['ok'] ?? true) === false
    && ($plainOfferLive['error'] ?? '') === 'product'
    && in_array('product', (array) ($plainOfferLive['blockers'] ?? []), true)
    && (int) ($plainOfferLive['sent'] ?? 1) === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn() === $plainOfferBefore
    && jeab_quote_outbox_state($plainOfferTid, '') === 'absent'
    && $plainOfferCalls === 0,
    'live utan produkt köas inte'
);

$drainTid = 'pr2-drain-mis';
$drainThread = $qoPut($drainTid, 'batteri', 'fejk.drainmis.pr2@exempel.test', 'Fejk Drain');
qo($drainThread !== [], 'tråden för tömd fel produkt finns');
$pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . (time() + 86400) . " WHERE status IN ('pending', 'retry', 'sending')");
$drainIdem = $drainTid . '|laddbox';
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at)
     VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?)'
)->execute([$drainIdem, $drainTid, 'laddbox', 'pending', '', time(), jeab_quote_outbox_now()]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$drainCalls = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$drainCalls): array {
    $drainCalls++;

    return ['ok' => true, 'sent' => 1, 'action' => 'quoted'];
};
$GLOBALS['_JEAB_SAPI'] = 'cli';
unset($GLOBALS['_JEAB_TICK']);
$drainRes = jeab_inbox_queue_quote_drain(5);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$drainRow = $qoRow($drainIdem);
$drainReasonStmt = $pdo->prepare('SELECT reason FROM quote_outbox WHERE idem_key = ?');
$drainReasonStmt->execute([$drainIdem]);
$drainReason = (string) $drainReasonStmt->fetchColumn();
qo(
    $drainCalls === 1
    && (int) ($drainRes['sent'] ?? 0) === 1
    && is_array($drainRow)
    && ($drainRow['status'] ?? '') === 'sent'
    && $drainReason !== 'product_mismatch',
    'en tömd rad med annan produkt skickas'
);

require_once __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 outbox\n")]);
$pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . (time() + 86400) . " WHERE status IN ('pending', 'retry', 'sending')");
$liveTid = 'pr2-live-match';
$liveMail = 'kund.live.pr2@gmail.com';
$liveThread = $qoPut($liveTid, 'batteri', $liveMail, 'Nora Live');
$qoStore->upsertMessage($liveTid . '-m', [
    'id' => $liveTid . '-m',
    'thread_id' => $liveTid,
    'from' => $liveMail,
    'from_name' => 'Nora Live',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert',
    'body' => "Namn: Nora Live\nE-post: {$liveMail}\nTelefon: 0709990001\nProdukt: batteri\n",
    'received_at' => date('c'),
    'source' => 'graph-jarfalla',
    'labels' => ['type' => 'Ny lead', 'confidence' => 90, 'source' => 'rule', 'offer' => 'batteri', 'product' => 'batteri'],
]);
$qoForget();
$liveThread = jeab_auto_thread_by_id($liveTid) ?? [];
qo($liveThread !== [] && jeab_quote_outbox_classified_offer($liveTid) === 'batteri', 'den matchande tråden är klassad batteri');
$liveIdem = $liveTid . '|batteri';
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at)
     VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?)'
)->execute([$liveIdem, $liveTid, 'batteri', 'pending', '', time(), jeab_quote_outbox_now()]);
jeab_auto_sent_check_cache_put($liveTid, 'saljare3@jarfalla.example.test', [], $liveMail, [
    'graph_search' => ['status' => 'ok', 'scanned' => 0, 'hit' => false, 'date' => '', 'why' => ''],
    'graph_scan' => ['status' => 'ok', 'scanned' => 0, 'hit' => false, 'date' => '', 'why' => ''],
]);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_TICK']);
$GLOBALS['_SENT'] = [];
$GLOBALS['_JEAB_SAPI'] = 'cli';
$liveDrain = jeab_inbox_queue_quote_drain(5);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$liveRow = $qoRow($liveIdem);
$liveSubjects = [];
foreach ($GLOBALS['_SENT'] as $mail) {
    if (is_array($mail) && trim((string) ($mail['subject'] ?? '')) !== '') {
        $liveSubjects[] = (string) $mail['subject'];
    }
}
qo(
    (int) ($liveDrain['sent'] ?? 0) === 1
    && (int) ($liveDrain['ran'] ?? 0) === 1
    && is_array($liveRow)
    && ($liveRow['status'] ?? '') === 'sent'
    && $liveSubjects === ['Offert batteri'],
    'matchande produkt går genom send_quote en gång som batteri'
);
$hookTid = 'pr2-hook-live';
$hookMail = 'kund.hook.pr2@gmail.com';
$hookThread = $qoPut($hookTid, 'batteri', $hookMail, 'Nora Hook');
$qoStore->upsertMessage($hookTid . '-m', [
    'id' => $hookTid . '-m',
    'thread_id' => $hookTid,
    'from' => $hookMail,
    'from_name' => 'Nora Hook',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert',
    'body' => "Namn: Nora Hook\nE-post: {$hookMail}\nTelefon: 0709990003\nProdukt: batteri\n",
    'received_at' => date('c'),
    'source' => 'graph-jarfalla',
    'labels' => ['type' => 'Ny lead', 'confidence' => 90, 'source' => 'rule', 'offer' => 'batteri', 'product' => 'batteri'],
]);
$qoForget();
qo(jeab_quote_outbox_classified_offer($hookTid) === 'batteri', 'tråden för offer_send_one är klassad batteri');
jeab_auto_sent_check_cache_put($hookTid, 'saljare3@jarfalla.example.test', [], $hookMail, [
    'graph_search' => ['status' => 'ok', 'scanned' => 0, 'hit' => false, 'date' => '', 'why' => ''],
    'graph_scan' => ['status' => 'ok', 'scanned' => 0, 'hit' => false, 'date' => '', 'why' => ''],
]);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_TICK']);
$GLOBALS['_SENT'] = [];
$GLOBALS['_JEAB_SAPI'] = 'cli';
$GLOBALS['_JEAB_OFFER_SEND_ONE_ENABLED'] = true;
$hookBefore = (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn();
$hookSent = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $hookTid, 'dry' => 0, 'confirm' => 'send']);
$hookSubjects = [];
foreach ($GLOBALS['_SENT'] as $mail) {
    if (is_array($mail) && trim((string) ($mail['subject'] ?? '')) !== '') {
        $hookSubjects[] = (string) $mail['subject'];
    }
}
qo(
    ($hookSent['ok'] ?? false) === true
    && (int) ($hookSent['sent'] ?? 0) === 1
    && $hookSubjects === ['Offert batteri']
    && (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn() === $hookBefore + 1
    && jeab_quote_outbox_state($hookTid, 'batteri') === 'sent',
    'offer_send_one skickar Offert batteri en gång utan stub'
);
$hookAgain = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $hookTid, 'dry' => 0, 'confirm' => 'send', 'product' => 'batteri']);
$hookAgainSubjects = [];
foreach ($GLOBALS['_SENT'] as $mail) {
    if (is_array($mail) && trim((string) ($mail['subject'] ?? '')) !== '') {
        $hookAgainSubjects[] = (string) $mail['subject'];
    }
}
qo(
    ($hookAgain['ok'] ?? true) === false
    && ($hookAgain['error'] ?? '') === 'already_out'
    && (int) ($hookAgain['sent'] ?? 1) === 0
    && ($hookAgain['already_out'] ?? false) === true
    && $hookAgainSubjects === ['Offert batteri']
    && (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn() === $hookBefore + 1,
    'samma offer_send_one en gång till är already_out och skickar inte'
);
$plainTid = 'pr2-plain';
$plainMail = 'kund.plain.pr2@gmail.com';
$plainThread = $qoPut($plainTid, '', $plainMail, 'Nora Plain');
$qoStore->upsertMessage($plainTid . '-m', [
    'id' => $plainTid . '-m',
    'thread_id' => $plainTid,
    'from' => $plainMail,
    'from_name' => 'Nora Plain',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Hej',
    'body' => "Namn: Nora Plain\nE-post: {$plainMail}\nTelefon: 0709990002\n",
    'received_at' => date('c'),
    'source' => 'graph-jarfalla',
    'labels' => ['type' => 'Ny lead', 'confidence' => 90, 'source' => 'rule'],
]);
$qoForget();
$plainThread = jeab_auto_thread_by_id($plainTid) ?? [];
qo($plainThread !== [] && jeab_quote_outbox_classified_offer($plainTid) === '', 'tråden utan produkt är oklassad');
$plainIdem = $plainTid . '|batteri';
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at)
     VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?)'
)->execute([$plainIdem, $plainTid, 'batteri', 'pending', '', time(), jeab_quote_outbox_now()]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$GLOBALS['_SENT'] = [];
$plainDrain = jeab_inbox_queue_quote_drain(5);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$pdo = jeab_data_pdo();
$plainRow = $qoRow($plainIdem);
$plainReasonStmt = $pdo->prepare('SELECT reason FROM quote_outbox WHERE idem_key = ?');
$plainReasonStmt->execute([$plainIdem]);
$plainReason = (string) $plainReasonStmt->fetchColumn();
qo(
    (int) ($plainDrain['sent'] ?? 0) === 1
    && is_array($plainRow)
    && ($plainRow['status'] ?? '') === 'sent'
    && $plainReason !== 'product_unclassified',
    'en oklassad tråd med köad produkt skickas'
);

$jsonForce = $GLOBALS['_JEAB_QUOTE_QUEUE_FORCE'] ?? null;
$GLOBALS['_JEAB_QUOTE_QUEUE_FORCE'] = 'json';
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
jeab_inbox_quote_queue_save([
    'pr2-json-send' => [
        'at' => 111,
        'product' => 'batteri',
        'idem' => 'pr2-json-send|batteri',
        'status' => 'sending',
        'attempts' => 2,
        'next_attempt_at' => 999,
    ],
    'pr2-json-sent' => [
        'at' => 222,
        'product' => 'sol',
        'idem' => 'pr2-json-sent|sol',
        'status' => 'sent',
        'attempts' => 1,
        'next_attempt_at' => 0,
    ],
]);
$sendBack = jeab_quote_outbox_enqueue_one('pr2-json-send', 'laddbox');
$sentBack = jeab_quote_outbox_enqueue_one('pr2-json-sent', 'batteri');
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$jsonMap = jeab_inbox_quote_queue_load();
$jsonSend = is_array($jsonMap['pr2-json-send'] ?? null) ? $jsonMap['pr2-json-send'] : [];
$jsonSent = is_array($jsonMap['pr2-json-sent'] ?? null) ? $jsonMap['pr2-json-sent'] : [];
qo(
    $sendBack === 'pr2-json-send|batteri'
    && ($jsonSend['status'] ?? '') === 'sending'
    && ($jsonSend['product'] ?? '') === 'batteri'
    && (int) ($jsonSend['attempts'] ?? 0) === 2
    && (int) ($jsonSend['at'] ?? 0) === 111,
    'json enqueue skriver inte över sending'
);
qo(
    $sentBack === 'pr2-json-sent|sol'
    && ($jsonSent['status'] ?? '') === 'sent'
    && ($jsonSent['product'] ?? '') === 'sol'
    && (int) ($jsonSent['attempts'] ?? 0) === 1,
    'json enqueue skriver inte över sent'
);
if ($jsonForce === null) {
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_FORCE']);
} else {
    $GLOBALS['_JEAB_QUOTE_QUEUE_FORCE'] = $jsonForce;
}
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'grok_decides' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
qo(jeab_inkorg_case_decides() === true, 'Grok-brytaren är på för case-hållet');
$qoConf = static function (float $seller): array {
    return [
        'is_lead_seller' => $seller,
        'primary_product' => 0.95,
        'kind' => 0.95,
        'type' => 0.95,
        'intent' => 0.95,
        'customer_email' => 0.95,
        'label' => 0.95,
        'seller_name' => 0.95,
        'customer_name' => 0.95,
    ];
};
$qoCase = static function (string $tid, string $email, string $name, array $case, string $phone = '0709990014') use ($qoStore, $qoForget): void {
    $qoStore->upsertMessage($tid . '-m', [
        'id' => $tid . '-m',
        'thread_id' => $tid,
        'from' => $email,
        'from_name' => $name,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Offert',
        'body' => "Namn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nProdukt: batteri\n",
        'received_at' => date('c'),
        'source' => 'graph-jarfalla',
        'labels' => [
            'type' => 'Ny lead',
            'confidence' => 90,
            'source' => 'rule',
            'offer' => 'batteri',
            'product' => 'batteri',
            'case_class' => $case,
        ],
    ]);
    $qoForget();
};
$grokTid = 'pr2-grok-drain';
$grokMail = 'nora.grok.pr2@exempel.test';
$grokName = 'Nora Grok';
$grokThread = $qoPut($grokTid, 'batteri', $grokMail, $grokName);
$grokCase = [
    'is_lead_seller' => true,
    'primary_product' => 'Batteri',
    'kind' => 'kopintresse',
    'type' => 'Ny lead',
    'intent' => 'kopintresse',
    'customer_email' => $grokMail,
    'customer_name' => $grokName,
    'confidence' => $qoConf(0.5),
];
$qoCase($grokTid, $grokMail, $grokName, $grokCase);
qo($grokThread !== [] && jeab_quote_outbox_classified_offer($grokTid) === 'batteri', 'tråden med osäker säljare är klassad batteri');
qo(jeab_inkorg_case_hold_why($grokCase) === 'Grok är osäker på leadsäljare', 'sparad case håller på leadsäljare');
qo(jeab_quote_outbox_case_hold($grokTid) === '', 'förhandsvisningen släpper Grok-osäkerhet för ett manuellt utskick');
qo(jeab_quote_drain_class(['action' => 'hold', 'why' => 'Grok är osäker på leadsäljare', 'sent' => 0], 1) === 'retry', 'Grok-osäkerhet är inte ett slutligt hopp');
$case65 = $grokCase;
$case65['priority'] = 2;
$case65['confidence'] = $qoConf(0.65);
$labels65 = jeab_inkorg_case_apply_labels([
    'type' => 'Ny lead',
    'confidence' => 90,
    'source' => 'llm',
    'offer' => 'batteri',
    'product' => 'batteri',
    'reason' => 'klassad',
], $case65);
$fin65 = jeab_inkorg_finish_labels($labels65, [
    'from' => 'nora.granskas.pr2@exempel.test',
    'subject' => 'Offert',
    'body' => "Namn: Nora\nE-post: nora.granskas.pr2@exempel.test\nProdukt: batteri\n",
]);
qo(
    jeab_inkorg_case_hold_why($case65) === 'Grok är osäker på leadsäljare'
    && (int) ($labels65['confidence'] ?? 0) === (jeab_inkorg_review_min_confidence() - 1)
    && ($fin65['reason'] ?? '') === 'Behöver granskas'
    && !empty($fin65['needs_review'])
    && jeab_inkorg_message_needs_review(['labels' => $fin65]) === true,
    'säkerhet 0,65 hålls och blir Behöver granskas'
);
$case75 = $grokCase;
$case75['priority'] = 2;
$case75['confidence'] = $qoConf(0.75);
$labels75 = jeab_inkorg_case_apply_labels([
    'type' => 'Ny lead',
    'confidence' => 90,
    'source' => 'llm',
    'offer' => 'batteri',
    'product' => 'batteri',
    'reason' => 'klassad',
], $case75);
$fin75 = jeab_inkorg_finish_labels($labels75, [
    'from' => 'nora.saker.pr2@exempel.test',
    'subject' => 'Offert',
    'body' => "Namn: Nora\nE-post: nora.saker.pr2@exempel.test\nProdukt: batteri\n",
]);
qo(
    jeab_inkorg_case_hold_why($case75) === ''
    && (int) ($labels75['confidence'] ?? 0) === 90
    && ($fin75['reason'] ?? '') !== 'Behöver granskas'
    && empty($fin75['needs_review'])
    && jeab_inkorg_message_needs_review(['labels' => $fin75]) === false,
    'säkerhet 0,75 håller inte och behöver inte granskas'
);
$pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . (time() + 86400) . " WHERE status IN ('pending', 'retry', 'sending')");
$grokIdem = $grokTid . '|batteri';
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at)
     VALUES (?, ?, ?, ?, 1, 0, ?, ?, ?)'
)->execute([$grokIdem, $grokTid, 'batteri', 'retry', 'Grok är osäker på leadsäljare', time(), jeab_quote_outbox_now()]);
jeab_auto_sent_check_cache_put($grokTid, 'saljare3@jarfalla.example.test', [], $grokMail, [
    'graph_search' => ['status' => 'ok', 'scanned' => 0, 'hit' => false, 'date' => '', 'why' => ''],
    'graph_scan' => ['status' => 'ok', 'scanned' => 0, 'hit' => false, 'date' => '', 'why' => ''],
]);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX']);
$GLOBALS['_SENT'] = [];
$GLOBALS['_JEAB_SAPI'] = 'cli';
$grokDrain = jeab_inbox_queue_quote_drain(5);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$grokRow = $qoRow($grokIdem);
$grokSubjects = [];
$grokFrom = [];
foreach ($GLOBALS['_SENT'] as $mail) {
    if (!is_array($mail)) {
        continue;
    }
    $subject = trim((string) ($mail['subject'] ?? ''));
    if ($subject === '') {
        continue;
    }
    $grokSubjects[] = $subject;
    $opts = is_array($mail['opts'] ?? null) ? $mail['opts'] : [];
    $grokFrom[] = (string) ($opts['person_email'] ?? $mail['from'] ?? $mail['from_email'] ?? '');
}
$pdo = jeab_data_pdo();
$grokRow = $qoRow($grokIdem);
$grokReasonStmt = $pdo->prepare('SELECT reason FROM quote_outbox WHERE idem_key = ?');
$grokReasonStmt->execute([$grokIdem]);
$grokReason = (string) $grokReasonStmt->fetchColumn();
qo(
    (int) ($grokDrain['sent'] ?? 0) === 1
    && (int) ($grokDrain['ran'] ?? 0) === 1
    && $GLOBALS['_SENT'] !== []
    && is_array($grokRow)
    && ($grokRow['status'] ?? '') === 'sent'
    && $grokReason === 'sent'
    && $qoManual($grokIdem) === 0,
    'en retry utan manual_send med osäker leadsäljare skickas'
);
$GLOBALS['_SENT'] = [];
$grokAgain = jeab_inbox_queue_quote_drain(5);
qo(
    $GLOBALS['_SENT'] === []
    && (int) ($grokAgain['sent'] ?? 1) === 0
    && (int) ($grokAgain['ran'] ?? 1) === 0
    && jeab_quote_outbox_state($grokTid, 'batteri') === 'sent',
    'en skickad rad töms inte igen'
);

$manTid = 'pr2-grok-manual';
$manMail = 'nora.manual.pr2@exempel.test';
$manName = 'Nora Manual';
$qoPut($manTid, 'batteri', $manMail, $manName);
$manCase = $grokCase;
$manCase['customer_email'] = $manMail;
$manCase['customer_name'] = $manName;
$qoCase($manTid, $manMail, $manName, $manCase, '0709990015');
$pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . (time() + 86400) . " WHERE status IN ('pending', 'retry', 'sending')");
unset($GLOBALS['_JEAB_OFFER_SEND_ONE_SYNC'], $GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX']);
$GLOBALS['_JEAB_OFFER_SEND_ONE_ENABLED'] = true;
$GLOBALS['_JEAB_SAPI'] = 'cli';
$manLive = jeab_inkorg_hook([
    'action' => 'inbox.offer_send_one',
    'thread_id' => $manTid,
    'product' => 'batteri',
    'dry' => 0,
    'confirm' => 'send',
    'manual_send' => 0,
]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$manIdem = $manTid . '|batteri';
$manEvent = $pdo->prepare("SELECT COUNT(*) FROM quote_outbox_event WHERE thread_id = ? AND product = 'batteri' AND reason = 'offer_send_one'");
$manEvent->execute([$manTid]);
qo(
    ($manLive['ok'] ?? false) === true
    && (int) ($manLive['queued'] ?? 0) === 1
    && (int) ($manLive['sent'] ?? 1) === 0
    && ($manLive['blockers'] ?? null) === []
    && $qoManual($manIdem) === 1
    && (int) $manEvent->fetchColumn() >= 1,
    'offer_send_one köar manual_send även när indata säger 0'
);
jeab_auto_sent_check_cache_put($manTid, 'saljare3@jarfalla.example.test', [], $manMail, [
    'graph_search' => ['status' => 'ok', 'scanned' => 0, 'hit' => false, 'date' => '', 'why' => ''],
    'graph_scan' => ['status' => 'ok', 'scanned' => 0, 'hit' => false, 'date' => '', 'why' => ''],
]);
$GLOBALS['_SENT'] = [];
$manDrain = jeab_inbox_queue_quote_drain(5);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$manRow = $qoRow($manIdem);
$manSubjects = [];
$manFrom = [];
foreach ($GLOBALS['_SENT'] as $mail) {
    if (!is_array($mail)) {
        continue;
    }
    $subject = trim((string) ($mail['subject'] ?? ''));
    if ($subject === '') {
        continue;
    }
    $manSubjects[] = $subject;
    $opts = is_array($mail['opts'] ?? null) ? $mail['opts'] : [];
    $manFrom[] = (string) ($opts['person_email'] ?? $mail['from'] ?? $mail['from_email'] ?? '');
}
qo(
    (int) ($manDrain['sent'] ?? 0) === 1
    && (int) ($manDrain['ran'] ?? 0) === 1
    && is_array($manRow)
    && ($manRow['status'] ?? '') === 'sent'
    && $manSubjects === ['Offert batteri']
    && $manFrom === ['saljare3@jarfalla.example.test'],
    'en köad manual_send med osäker leadsäljare töms en gång från Roberto'
);
$GLOBALS['_SENT'] = [];
$manAgain = jeab_inbox_queue_quote_drain(5);
qo(
    $GLOBALS['_SENT'] === []
    && (int) ($manAgain['sent'] ?? 1) === 0
    && (int) ($manAgain['ran'] ?? 1) === 0
    && jeab_quote_outbox_state($manTid, 'batteri') === 'sent',
    'samma manuella rad töms inte en gång till'
);

$qTid = 'pr2-queue-ignore';
$qoPut($qTid, 'batteri', 'nora.queue.pr2@exempel.test', 'Nora Queue');
$qHook = jeab_inkorg_hook([
    'action' => 'inbox.queue_quote',
    'thread_id' => $qTid,
    'confirm' => 1,
    'manual_send' => 1,
]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$qMap = jeab_inbox_quote_queue_load();
$qRow = is_array($qMap[$qTid] ?? null) ? $qMap[$qTid] : [];
$qIdem = jeab_quote_outbox_idem($qTid, (string) ($qRow['product'] ?? ''), (string) ($qRow['idem'] ?? ''));
qo(
    ($qHook['ok'] ?? false) === true
    && $qIdem !== ''
    && $qoManual($qIdem) === 0
    && (int) ($qRow['manual_send'] ?? 0) === 0,
    'manual_send i queue_quote lämnar raden på 0'
);
$pdo->prepare("UPDATE quote_outbox SET next_attempt_at = ? WHERE idem_key = ?")->execute([time() + 86400, $qIdem]);

$openTid = 'pr2-grok-open';
$openMail = 'nora.open.pr2@exempel.test';
$openName = 'Nora Open';
$qoPut($openTid, 'batteri', $openMail, $openName);
$qoCase($openTid, $openMail, $openName, [
    'is_lead_seller' => true,
    'primary_product' => 'Batteri',
    'kind' => 'kopintresse',
    'type' => 'Ny lead',
    'intent' => 'kopintresse',
    'customer_email' => $openMail,
    'customer_name' => $openName,
    'confidence' => $qoConf(0.5),
]);
unset($GLOBALS['_JEAB_OFFER_SEND_ONE_SYNC'], $GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX']);
$GLOBALS['_JEAB_OFFER_SEND_ONE_ENABLED'] = true;
$openDry = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $openTid, 'product' => 'batteri']);
$openLive = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $openTid, 'product' => 'batteri', 'dry' => 0, 'confirm' => 'send']);
qo(
    ($openDry['blockers'] ?? null) === []
    && ($openLive['blockers'] ?? null) === []
    && ($openDry['blockers'] ?? null) === ($openLive['blockers'] ?? null)
    && !str_contains((string) json_encode($openDry), 'Grok är osäker')
    && !str_contains((string) json_encode($openLive), 'Grok är osäker'),
    'dry och live har samma tomma spärrlista när Grok är osäker på leadsäljare'
);

$missTid = 'pr2-grok-miss';
$missMail = 'nora.miss.pr2@exempel.test';
$missName = 'Nora Miss';
$qoPut($missTid, 'batteri', $missMail, $missName);
$missCase = [
    'is_lead_seller' => true,
    'primary_product' => 'Batteri',
    'kind' => 'kopintresse',
    'type' => 'Ny lead',
    'intent' => 'kopintresse',
    'customer_email' => '',
    'customer_name' => $missName,
    'confidence' => $qoConf(0.95),
];
$qoCase($missTid, $missMail, $missName, $missCase);
qo(jeab_inkorg_case_hold_why($missCase) === 'kundens e-post saknas i leaden', 'saknad kundadress är kvar som case-håll');
qo(jeab_quote_outbox_case_hold($missTid) === 'kundens e-post saknas i leaden', 'utboxen släpper inte saknad kundadress');
$missBefore = (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn();
$missDry = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $missTid, 'product' => 'batteri']);
$missLive = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $missTid, 'product' => 'batteri', 'dry' => 0, 'confirm' => 'send']);
qo(
    ($missDry['blockers'] ?? null) === []
    && ($missLive['blockers'] ?? null) === []
    && ($missDry['would_send'] ?? false) === true
    && (int) ($missLive['queued'] ?? 0) === 1
    && (int) ($missLive['sent'] ?? 1) === 0
    && (int) $pdo->query('SELECT COUNT(*) FROM quote_outbox')->fetchColumn() === $missBefore + 1
    && jeab_quote_outbox_state($missTid, 'batteri') === 'pending',
    'saknad adress i leaden stoppar inte offer_send_one när tråden har mejl'
);

$asyncTid = 'pr2-async';
$asyncMail = 'nora.async.pr2@exempel.test';
$qoPut($asyncTid, 'batteri', $asyncMail, 'Nora Async');
qo(jeab_quote_outbox_classified_offer($asyncTid) === 'batteri', 'tråden för köad live är klassad batteri');
$pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . (time() + 86400) . " WHERE status IN ('pending', 'retry', 'sending')");
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_OFFER_SEND_ONE_SYNC'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX']);
$GLOBALS['_JEAB_OFFER_SEND_ONE_ENABLED'] = true;
$GLOBALS['_JEAB_SAPI'] = 'cli';
$asyncCalls = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$asyncCalls): array {
    $asyncCalls++;

    return ['ok' => true, 'sent' => 1, 'action' => 'quoted'];
};
$asyncLive = jeab_inkorg_hook([
    'action' => 'inbox.offer_send_one',
    'thread_id' => $asyncTid,
    'dry' => 0,
    'confirm' => 'send',
    'manual_send' => 1,
]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$asyncRow = $qoRow($asyncTid . '|batteri');
$asyncEvent = $pdo->prepare("SELECT COUNT(*) FROM quote_outbox_event WHERE thread_id = ? AND reason = 'offer_send_one'");
$asyncEvent->execute([$asyncTid]);
qo(
    ($asyncLive['ok'] ?? false) === true
    && (int) ($asyncLive['queued'] ?? 0) === 1
    && (int) ($asyncLive['sent'] ?? 1) === 0
    && ($asyncLive['outbox'] ?? '') === 'pending'
    && ($asyncLive['reason'] ?? '') === 'queued'
    && $asyncCalls === 0
    && is_array($asyncRow)
    && ($asyncRow['status'] ?? '') === 'pending'
    && (int) ($asyncRow['attempts'] ?? 1) === 0
    && (int) ($asyncRow['next_attempt_at'] ?? 1) === 0
    && $qoManual($asyncTid . '|batteri') === 1
    && (int) $asyncEvent->fetchColumn() >= 1,
    'live utan SYNC köar och anropar inte utskicket'
);
$asyncDrain = jeab_inbox_queue_quote_drain(5);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$asyncAfter = $qoRow($asyncTid . '|batteri');
$asyncDrainAgain = jeab_inbox_queue_quote_drain(5);
qo(
    $asyncCalls === 1
    && (int) ($asyncDrain['sent'] ?? 0) === 1
    && is_array($asyncAfter)
    && ($asyncAfter['status'] ?? '') === 'sent'
    && (int) ($asyncDrainAgain['sent'] ?? 1) === 0
    && (int) ($asyncDrainAgain['ran'] ?? 1) === 0,
    'den köade raden töms exakt en gång'
);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);

$markNow = time();
$markSince = $markNow - 7200;
$markUntil = $markNow + 120;
$markYes = 'pr2-mark-yes';
$markNo = 'pr2-mark-no';
$markOld = 'pr2-mark-old';
$markSent = 'pr2-mark-sent';
$markAlready = 'pr2-mark-already';
$markOutside = 'pr2-mark-outside';
$markInsert = $pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
     VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?)'
);
$markEvent = $pdo->prepare(
    'INSERT INTO quote_outbox_event (created_at, thread_id, product, status, reason, attempts) VALUES (?, ?, ?, ?, ?, 0)'
);
$markAt = jeab_quote_outbox_now();
$markFar = time() + 86400;
foreach (
    [
        [$markYes, 'pending', $markNow, 0, 'offer_send_one', $markAt],
        [$markNo, 'retry', $markNow, 0, 'retry', $markAt],
        [$markOld, 'pending', $markNow, 0, 'offer_send_one', '2020-01-01 00:00:00'],
        [$markSent, 'sent', $markNow, 0, 'offer_send_one', $markAt],
        [$markAlready, 'pending', $markNow, 1, 'offer_send_one', $markAt],
        [$markOutside, 'pending', $markNow - 86400 * 30, 0, 'offer_send_one', '2020-01-02 00:00:00'],
    ] as $spec
) {
    [$tid, $status, $queuedAt, $flag, $reason, $created] = $spec;
    $markInsert->execute([$tid . '|batteri', $tid, 'batteri', $status, $markFar, '', $queuedAt, $markAt, $flag]);
    $markEvent->execute([$created, $tid, 'batteri', $status, $reason, ]);
}
if (function_exists('jeab_send_guard_pdo') && function_exists('jeab_send_guard_ensure')) {
    $guard = jeab_send_guard_pdo();
    if ($guard instanceof PDO) {
        jeab_send_guard_ensure($guard);
        $guard->prepare(
            'INSERT INTO send_queue (channel, template, rcpt_hash, body_hash, not_before, created_at, job, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'mail',
            'offert-batteri-jarfalla',
            str_repeat('a', 64),
            str_repeat('b', 64),
            $markAt,
            $markAt,
            base64_encode(serialize(['thread_id' => $markNo])),
            'waiting',
        ]);
    }
}
$markSnap = $qoFlags();
$markDry = jeab_inkorg_hook([
    'action' => 'inbox.outbox_manual_mark',
    'since' => $markSince,
    'until' => $markUntil,
    'manual_send' => 1,
]);
$markDryBlob = (string) json_encode($markDry);
$markByResult = [];
foreach ((array) ($markDry['rows'] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }
    $markByResult[(string) ($row['idem'] ?? '')] = (string) ($row['result'] ?? '');
}
qo(
    ($markDry['ok'] ?? false) === true
    && (int) ($markDry['dry'] ?? 0) === 1
    && ($markDry['read_only'] ?? false) === true
    && (int) ($markDry['sent'] ?? 1) === 0
    && $qoFlags() === $markSnap
    && ($markByResult[$markYes . '|batteri'] ?? '') === 'would_mark'
    && ($markByResult[$markNo . '|batteri'] ?? '') === 'no_evidence'
    && ($markByResult[$markOld . '|batteri'] ?? '') === 'no_evidence'
    && ($markByResult[$markAlready . '|batteri'] ?? '') === 'already'
    && !isset($markByResult[$markSent . '|batteri'])
    && !isset($markByResult[$markOutside . '|batteri'])
    && !str_contains($markDryBlob, '@'),
    'dry av outbox_manual_mark skriver inget och kräver bevis'
);
$markRefuse = jeab_inkorg_hook([
    'action' => 'inbox.outbox_manual_mark',
    'since' => $markSince,
    'until' => $markUntil,
    'dry' => 0,
]);
$markSam = jeab_inkorg_hook([
    'action' => 'inbox.outbox_manual_mark',
    'since' => $markSince,
    'until' => $markUntil,
    'dry' => 0,
    'confirm' => 'SAM',
]);
qo(
    ($markRefuse['ok'] ?? true) === false
    && ($markRefuse['error'] ?? '') === 'confirm'
    && ($markSam['ok'] ?? true) === false
    && ($markSam['error'] ?? '') === 'confirm'
    && $qoFlags() === $markSnap,
    'dry=0 utan confirm=mark skriver inget'
);
$markLive = jeab_inkorg_hook([
    'action' => 'inbox.outbox_manual_mark',
    'since' => (string) $markSince,
    'until' => date('Y-m-d H:i:s', $markUntil),
    'dry' => 0,
    'confirm' => 'mark',
    'manual_send' => 1,
]);
$markLiveBlob = (string) json_encode($markLive);
$markLiveResult = [];
foreach ((array) ($markLive['rows'] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }
    $markLiveResult[(string) ($row['idem'] ?? '')] = (string) ($row['result'] ?? '');
}
$markChanged = [];
$markAfter = $qoFlags();
foreach ($markAfter as $idem => $flag) {
    if ($flag === 1 && (int) ($markSnap[$idem] ?? 0) === 0) {
        $markChanged[] = $idem;
    }
}
sort($markChanged);
$markUndoId = (string) ($markLive['undo_id'] ?? '');
$markUndoStmt = $pdo->prepare('SELECT idem_key, manual_send FROM quote_outbox_manual_undo WHERE undo_id = ? AND undone_at IS NULL');
$markUndoStmt->execute([$markUndoId]);
$markUndoPrev = [];
foreach ($markUndoStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (is_array($row)) {
        $markUndoPrev[(string) ($row['idem_key'] ?? '')] = (int) ($row['manual_send'] ?? 0);
    }
}
qo(
    ($markLive['ok'] ?? false) === true
    && (int) ($markLive['dry'] ?? 1) === 0
    && ($markLive['read_only'] ?? true) === false
    && (int) ($markLive['sent'] ?? 1) === 0
    && $markUndoId !== ''
    && ($markLiveResult[$markYes . '|batteri'] ?? '') === 'marked'
    && ($markLiveResult[$markNo . '|batteri'] ?? '') === 'no_evidence'
    && ($markLiveResult[$markOld . '|batteri'] ?? '') === 'no_evidence'
    && $markChanged === [$markYes . '|batteri']
    && ($markUndoPrev[$markYes . '|batteri'] ?? -1) === 0
    && $qoManual($markNo . '|batteri') === 0
    && $qoManual($markAlready . '|batteri') === 1
    && $qoManual($markSent . '|batteri') === 0
    && $qoManual($markOutside . '|batteri') === 0
    && !str_contains($markLiveBlob, '@'),
    'mark sätter bara rader med offer_send_one i fönstret'
);
$markUndoDry = jeab_inkorg_hook([
    'action' => 'inbox.outbox_manual_mark_undo',
    'undo_id' => $markUndoId,
]);
qo(
    ($markUndoDry['ok'] ?? false) === true
    && (int) ($markUndoDry['dry'] ?? 0) === 1
    && (int) ($markUndoDry['would_restore'] ?? 0) === 1
    && $qoManual($markYes . '|batteri') === 1
    && !str_contains((string) json_encode($markUndoDry), '@'),
    'undo-dry återställer inget'
);
$markUndoRefuse = jeab_inkorg_hook([
    'action' => 'inbox.outbox_manual_mark_undo',
    'undo_id' => $markUndoId,
    'dry' => 0,
]);
qo(
    ($markUndoRefuse['ok'] ?? true) === false
    && ($markUndoRefuse['error'] ?? '') === 'confirm'
    && $qoManual($markYes . '|batteri') === 1,
    'undo utan confirm=undo skriver inget'
);
$markUndo = jeab_inkorg_hook([
    'action' => 'inbox.outbox_manual_mark_undo',
    'undo_id' => $markUndoId,
    'dry' => 0,
    'confirm' => 'undo',
]);
$markUndone = $pdo->prepare('SELECT undone_at FROM quote_outbox_manual_undo WHERE undo_id = ? AND idem_key = ?');
$markUndone->execute([$markUndoId, $markYes . '|batteri']);
qo(
    ($markUndo['ok'] ?? false) === true
    && (int) ($markUndo['restored'] ?? 0) === 1
    && $qoManual($markYes . '|batteri') === 0
    && $qoManual($markAlready . '|batteri') === 1
    && trim((string) $markUndone->fetchColumn()) !== ''
    && !str_contains((string) json_encode($markUndo), '@'),
    'undo återställer manual_send'
);
$markUndoAgain = jeab_inkorg_hook([
    'action' => 'inbox.outbox_manual_mark_undo',
    'undo_id' => $markUndoId,
    'dry' => 0,
    'confirm' => 'undo',
]);
qo(
    ($markUndoAgain['ok'] ?? false) === true
    && (int) ($markUndoAgain['restored'] ?? 1) === 0
    && $qoManual($markYes . '|batteri') === 0,
    'samma undo en gång till ändrar inget'
);

$mapRound = jeab_quote_outbox_load_map();
$mapRound['pr2-ms-round'] = [
    'at' => time(),
    'product' => 'batteri',
    'idem' => 'pr2-ms-round|batteri',
    'status' => 'pending',
    'attempts' => 0,
    'next_attempt_at' => time() + 86400,
    'manual_send' => 1,
];
jeab_inbox_quote_queue_save($mapRound);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$mapAgain = jeab_quote_outbox_load_map();
jeab_inbox_quote_queue_save($mapAgain);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$mapThird = jeab_quote_outbox_load_map();
qo(
    (int) ($mapAgain['pr2-ms-round']['manual_send'] ?? 0) === 1
    && (int) ($mapThird['pr2-ms-round']['manual_send'] ?? 0) === 1,
    'load och replace behåller manual_send'
);

$keepSince = 1893456000;
$keepUntil = $keepSince;
$keepAt = date('Y-m-d H:i:s', $keepSince);
$keepFar = time() + 86400;
$keepRows = ['pr2-undo-pending', 'pr2-undo-retry', 'pr2-undo-sending', 'pr2-undo-sent'];
$keepInsert = $pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
     VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, 0)'
);
$keepEvent = $pdo->prepare(
    'INSERT INTO quote_outbox_event (created_at, thread_id, product, status, reason, attempts) VALUES (?, ?, ?, ?, ?, 0)'
);
foreach ($keepRows as $keepTid) {
    $keepInsert->execute([$keepTid . '|batteri', $keepTid, 'batteri', 'pending', $keepFar, '', $keepSince, $keepAt]);
    $keepEvent->execute([$keepAt, $keepTid, 'batteri', 'pending', 'offer_send_one']);
}
$keepMark = jeab_inkorg_hook([
    'action' => 'inbox.outbox_manual_mark',
    'since' => $keepSince,
    'until' => $keepUntil,
    'dry' => 0,
    'confirm' => 'mark',
]);
$keepUndoId = (string) ($keepMark['undo_id'] ?? '');
foreach (
    [
        'pr2-undo-retry' => 'retry',
        'pr2-undo-sending' => 'sending',
        'pr2-undo-sent' => 'sent',
    ] as $keepTid => $keepStatus
) {
    $pdo->prepare('UPDATE quote_outbox SET status = ? WHERE idem_key = ?')->execute([$keepStatus, $keepTid . '|batteri']);
}
$keepUndo = jeab_inkorg_hook([
    'action' => 'inbox.outbox_manual_mark_undo',
    'undo_id' => $keepUndoId,
    'dry' => 0,
    'confirm' => 'undo',
]);
qo(
    ($keepMark['ok'] ?? false) === true
    && $keepUndoId !== ''
    && ($keepUndo['ok'] ?? false) === true
    && $qoManual('pr2-undo-pending|batteri') === 0
    && $qoManual('pr2-undo-retry|batteri') === 0
    && $qoManual('pr2-undo-sending|batteri') === 1
    && $qoManual('pr2-undo-sent|batteri') === 1,
    'undo återställer bara pending och retry'
);

$drainSrc = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_auto.php');
$drainAt = strpos($drainSrc, 'function jeab_inbox_queue_quote_drain');
$drainEnd = strpos($drainSrc, 'function jeab_auto_quote_sent_source_label');
$drainFn = $drainAt !== false && $drainEnd !== false ? substr($drainSrc, $drainAt, $drainEnd - $drainAt) : '';
$drainClaim = strpos($drainFn, 'jeab_quote_outbox_claim($idem)');
$drainNow = strpos($drainFn, 'jeab_quote_outbox_manual_now($idem)');
qo(
    $drainClaim !== false
    && $drainNow !== false
    && $drainNow > $drainClaim
    && !str_contains($drainFn, "\$row['manual_send']"),
    'tömningen läser manual_send efter claim'
);
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . (time() + 86400) . " WHERE status IN ('pending', 'retry', 'sending')");
    $raceTid = 'pr2-undo-race';
    $raceIdem = $raceTid . '|batteri';
    $pdo->prepare(
        'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
         VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, 1)'
    )->execute([$raceIdem, $raceTid, 'batteri', 'pending', '', time(), jeab_quote_outbox_now()]);
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
    $raceDue = jeab_quote_outbox_due(5);
    $raceListed = 0;
    foreach ($raceDue as $raceRow) {
        if ((string) ($raceRow['idem'] ?? '') === $raceIdem) {
            $raceListed = (int) ($raceRow['manual_send'] ?? 0);
        }
    }
    $pdo->exec(
        "CREATE TRIGGER jeab_qo_undo_between AFTER UPDATE OF status ON quote_outbox
         WHEN NEW.status = 'sending' AND NEW.idem_key = " . $pdo->quote($raceIdem) . " AND OLD.manual_send = 1
         BEGIN
           UPDATE quote_outbox SET manual_send = 0 WHERE idem_key = NEW.idem_key;
         END"
    );
    $GLOBALS['_JEAB_SAPI'] = 'cli';
    unset($GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX'], $GLOBALS['_JEAB_QUOTE_DRAIN_AFTER']);
    $GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function (): array {
        $GLOBALS['_JEAB_QUOTE_DRAIN_AFTER'] = empty($GLOBALS['_JEAB_QUOTE_OUTBOX_CTX']) ? 0 : 1;

        return ['ok' => true, 'sent' => 1, 'action' => 'quoted'];
    };
    try {
        $raceDrain = jeab_inbox_queue_quote_drain(5);
    } finally {
        $pdo->exec('DROP TRIGGER IF EXISTS jeab_qo_undo_between');
        unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND']);
    }
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
    qo(
        count($raceDue) === 1
        && $raceListed === 1
        && (int) ($raceDrain['ran'] ?? 0) === 1
        && (int) ($raceDrain['sent'] ?? 0) === 1
        && (int) ($GLOBALS['_JEAB_QUOTE_DRAIN_AFTER'] ?? 1) === 0
        && $qoManual($raceIdem) === 0
        && jeab_quote_outbox_manual_now($raceIdem) === 0,
        'en ånger mellan listan och claim släpper inte manual_send'
    );
}

$pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . (time() + 86400) . " WHERE status IN ('pending', 'retry', 'sending')");
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX'], $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT']);
$GLOBALS['_JEAB_SAPI'] = 'cli';
$holdTid = 'pr2-hold-stale';
$holdIdem = $holdTid . '|batteri';
$holdWhy = 'Grok är osäker på leadsäljare';
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
     VALUES (?, ?, ?, ?, 2, 0, ?, ?, ?, 0)'
)->execute([$holdIdem, $holdTid, 'batteri', 'sending', $holdWhy, time(), jeab_quote_outbox_now()]);
$holdCalls = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$holdCalls, $holdWhy): array {
    $holdCalls++;

    return ['action' => 'hold', 'why' => $holdWhy, 'sent' => 0];
};
$holdDrain = jeab_inbox_queue_quote_drain(5);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$holdRow = $qoRow($holdIdem);
$holdReasonStmt = $pdo->prepare('SELECT reason FROM quote_outbox WHERE idem_key = ?');
$holdReasonStmt->execute([$holdIdem]);
$holdReason = (string) $holdReasonStmt->fetchColumn();
qo(
    $holdCalls === 1
    && (int) ($holdDrain['ran'] ?? 0) === 1
    && (int) ($holdDrain['sent'] ?? 1) === 0
    && is_array($holdRow)
    && ($holdRow['status'] ?? '') === 'retry'
    && ($holdRow['status'] ?? '') !== 'sending'
    && (int) ($holdRow['attempts'] ?? 0) === 3
    && str_contains($holdReason, 'Grok är osäker'),
    'ett håll i tömningen lämnar retry och behåller orsaken'
);

$freshTid = 'pr2-fresh-send';
$freshMail = 'fejk.fresh.pr2@exempel.test';
$freshThread = $qoPut($freshTid, 'batteri', $freshMail, 'Fejk Fresh');
qo($freshThread !== [] && jeab_quote_outbox_classified_offer($freshTid) === 'batteri', 'färskt sending har en klassad tråd');
$freshIdem = $freshTid . '|batteri';
$freshNext = time() + 3600;
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
     VALUES (?, ?, ?, ?, 2, ?, ?, ?, ?, 0)'
)->execute([$freshIdem, $freshTid, 'batteri', 'sending', $freshNext, 'lease', time(), jeab_quote_outbox_now()]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_DRAIN_SEND']);
$freshCalls = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$freshCalls): array {
    $freshCalls++;

    return ['ok' => true, 'sent' => 1, 'action' => 'quoted'];
};
$freshDrain = jeab_inbox_queue_quote_drain(5);
$freshDry = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $freshTid, 'product' => 'batteri']);
$GLOBALS['_JEAB_OFFER_SEND_ONE_ENABLED'] = true;
$GLOBALS['_JEAB_OFFER_SEND_ONE_SYNC'] = true;
$freshLive = jeab_inkorg_hook([
    'action' => 'inbox.offer_send_one',
    'thread_id' => $freshTid,
    'product' => 'batteri',
    'dry' => 0,
    'confirm' => 'send',
]);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_OFFER_SEND_ONE_SYNC'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$freshRow = $qoRow($freshIdem);
qo(
    $freshCalls === 0
    && (int) ($freshDrain['ran'] ?? 1) === 0
    && (int) ($freshDry['dry'] ?? 0) === 1
    && (int) ($freshLive['sent'] ?? 1) === 0
    && is_array($freshRow)
    && ($freshRow['status'] ?? '') === 'sending'
    && (int) ($freshRow['attempts'] ?? 0) === 2
    && (int) ($freshRow['next_attempt_at'] ?? 0) === $freshNext
    && $qoManual($freshIdem) === 0,
    'färskt sending rörs inte av tömning eller offer_send_one'
);

$outTid = 'pr2-stale-out';
$outMail = 'fejk.staleout.pr2@exempel.test';
$qoPut($outTid, 'batteri', $outMail, 'Fejk Stale Out');
jeab_inkorg_relabel_offer_idem_remember($outTid, 'batteri', true);
$qoForget();
$outIdem = $outTid . '|batteri';
$pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . (time() + 86400) . " WHERE status IN ('pending', 'retry', 'sending')");
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
     VALUES (?, ?, ?, ?, 2, 0, ?, ?, ?, 0)'
)->execute([$outIdem, $outTid, 'batteri', 'sending', $holdWhy, time(), jeab_quote_outbox_now()]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_TICK']);
$outCalls = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$outCalls): array {
    $outCalls++;

    return ['ok' => true, 'sent' => 1, 'action' => 'quoted'];
};
$GLOBALS['_JEAB_SAPI'] = 'cli';
$outDrain = jeab_inbox_queue_quote_drain(5);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$outRow = $qoRow($outIdem);
$outReasonStmt = $pdo->prepare('SELECT reason FROM quote_outbox WHERE idem_key = ?');
$outReasonStmt->execute([$outIdem]);
qo(
    $outCalls === 0
    && (int) ($outDrain['sent'] ?? 1) === 0
    && (int) ($outDrain['ran'] ?? 0) === 1
    && is_array($outRow)
    && ($outRow['status'] ?? '') === 'sent'
    && (string) $outReasonStmt->fetchColumn() === 'already_out',
    'utgånget sending som redan gått ut skickas inte'
);

$oneOutTid = 'pr2-stale-one-out';
$oneOutMail = 'fejk.oneout.pr2@exempel.test';
$qoPut($oneOutTid, 'batteri', $oneOutMail, 'Fejk One Out');
jeab_inkorg_relabel_offer_idem_remember($oneOutTid, 'batteri', true);
$qoForget();
$oneOutIdem = $oneOutTid . '|batteri';
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
     VALUES (?, ?, ?, ?, 4, 0, ?, ?, ?, 0)'
)->execute([$oneOutIdem, $oneOutTid, 'batteri', 'sending', $holdWhy, time(), jeab_quote_outbox_now()]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_DRAIN_SEND']);
$oneOutCalls = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$oneOutCalls): array {
    $oneOutCalls++;

    return ['ok' => true, 'sent' => 1, 'action' => 'quoted'];
};
$GLOBALS['_JEAB_OFFER_SEND_ONE_ENABLED'] = true;
$GLOBALS['_JEAB_OFFER_SEND_ONE_SYNC'] = true;
$oneOutLive = jeab_inkorg_hook([
    'action' => 'inbox.offer_send_one',
    'thread_id' => $oneOutTid,
    'product' => 'batteri',
    'dry' => 0,
    'confirm' => 'send',
]);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_OFFER_SEND_ONE_SYNC'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$oneOutRow = $qoRow($oneOutIdem);
$oneOutReasonStmt = $pdo->prepare('SELECT reason FROM quote_outbox WHERE idem_key = ?');
$oneOutReasonStmt->execute([$oneOutIdem]);
qo(
    $oneOutCalls === 0
    && ($oneOutLive['ok'] ?? true) === false
    && ($oneOutLive['error'] ?? '') === 'already_out'
    && (int) ($oneOutLive['sent'] ?? 1) === 0
    && is_array($oneOutRow)
    && ($oneOutRow['status'] ?? '') === 'sent'
    && (string) $oneOutReasonStmt->fetchColumn() === 'already_out',
    'offer_send_one på utgånget sending som redan gått ut skickar inte'
);

$manStaleTid = 'pr2-stale-manual';
$manStaleMail = 'fejk.staleman.pr2@exempel.test';
$qoPut($manStaleTid, 'batteri', $manStaleMail, 'Fejk Stale Man');
$manStaleIdem = $manStaleTid . '|batteri';
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
     VALUES (?, ?, ?, ?, 8, ?, ?, ?, ?, 0)'
)->execute([$manStaleIdem, $manStaleTid, 'batteri', 'sending', time() - 10, $holdWhy, time(), jeab_quote_outbox_now()]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX']);
$manStaleCalls = 0;
$manStaleDuring = null;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$manStaleCalls, &$manStaleDuring, $pdo, $manStaleIdem): array {
    $manStaleCalls++;
    $during = $pdo->prepare('SELECT status, attempts, manual_send FROM quote_outbox WHERE idem_key = ?');
    $during->execute([$manStaleIdem]);
    $got = $during->fetch(PDO::FETCH_ASSOC);
    $manStaleDuring = is_array($got) ? $got : null;

    return ['ok' => true, 'sent' => 1, 'action' => 'quoted'];
};
$GLOBALS['_JEAB_OFFER_SEND_ONE_ENABLED'] = true;
$GLOBALS['_JEAB_OFFER_SEND_ONE_SYNC'] = true;
$GLOBALS['_JEAB_SAPI'] = 'cli';
$manStaleLive = jeab_inkorg_hook([
    'action' => 'inbox.offer_send_one',
    'thread_id' => $manStaleTid,
    'product' => 'batteri',
    'dry' => 0,
    'confirm' => 'send',
]);
$manStaleRow = $qoRow($manStaleIdem);
$manStaleAgain = jeab_inkorg_hook([
    'action' => 'inbox.offer_send_one',
    'thread_id' => $manStaleTid,
    'product' => 'batteri',
    'dry' => 0,
    'confirm' => 'send',
]);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_OFFER_SEND_ONE_SYNC'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
qo(
    $manStaleCalls === 1
    && is_array($manStaleDuring)
    && ($manStaleDuring['status'] ?? '') === 'sending'
    && (int) ($manStaleDuring['attempts'] ?? 0) === 1
    && (int) ($manStaleDuring['manual_send'] ?? 0) === 1
    && ($manStaleLive['ok'] ?? false) === true
    && (int) ($manStaleLive['sent'] ?? 0) === 1
    && is_array($manStaleRow)
    && ($manStaleRow['status'] ?? '') === 'sent'
    && (int) ($manStaleRow['attempts'] ?? 0) === 1
    && $qoManual($manStaleIdem) === 1
    && ($manStaleAgain['ok'] ?? true) === false
    && ($manStaleAgain['error'] ?? '') === 'already_out'
    && (int) ($manStaleAgain['sent'] ?? 1) === 0
    && $manStaleCalls === 1,
    'offer_send_one på utgånget sending nollar försök och skickar en gång'
);

$abortTid = 'pr2-abort-guard';
$abortIdem = $abortTid . '|batteri';
$pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . (time() + 86400) . " WHERE status IN ('pending', 'retry', 'sending')");
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
     VALUES (?, ?, ?, ?, 3, 0, ?, ?, ?, 0)'
)->execute([$abortIdem, $abortTid, 'batteri', 'pending', '', time(), jeab_quote_outbox_now()]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT']);
qo(jeab_quote_outbox_claim($abortIdem), 'vakten tar en claimad rad');
jeab_quote_outbox_inflight_add($abortIdem, $abortTid, 'batteri');
jeab_quote_outbox_inflight_abort();
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$abortRow = $qoRow($abortIdem);
$abortReasonStmt = $pdo->prepare('SELECT reason FROM quote_outbox WHERE idem_key = ?');
$abortReasonStmt->execute([$abortIdem]);
$abortReason = (string) $abortReasonStmt->fetchColumn();
jeab_quote_outbox_inflight_abort();
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$abortAgain = $qoRow($abortIdem);
$abortReasonStmt->execute([$abortIdem]);
qo(
    is_array($abortRow)
    && ($abortRow['status'] ?? '') === 'retry'
    && (int) ($abortRow['attempts'] ?? 0) === 4
    && $abortReason === 'aborted'
    && is_array($abortAgain)
    && ($abortAgain['status'] ?? '') === 'retry'
    && (int) ($abortAgain['attempts'] ?? 0) === 4
    && (string) $abortReasonStmt->fetchColumn() === 'aborted',
    'vakten markerar en övergiven rad som retry utan nytt försök'
);

// Kent-mönster: retry med aborted, 3 försök, next 0. Tick på webben har kvar malmos 5 s.
$pr3Far = time() + 86400;
$pr3Ins = $pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
     VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, 1)'
);
$pr3Reason = $pdo->prepare('SELECT reason, next_attempt_at FROM quote_outbox WHERE idem_key = ?');
$pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . $pr3Far . " WHERE status IN ('pending', 'retry', 'sending')");
$pr3KentIdem = 'pr3-kent-q|batteri';
$pr3Ins->execute([$pr3KentIdem, 'pr3-kent-q', 'batteri', 'retry', 3, 'aborted', time() - 3600, jeab_quote_outbox_now()]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT']);
$pr3HadSapi = array_key_exists('_JEAB_SAPI', $GLOBALS);
$pr3PrevSapi = $GLOBALS['_JEAB_SAPI'] ?? null;
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$GLOBALS['_JEAB_TICK'] = true;
set_time_limit(5);
$pr3KentCalls = 0;
$pr3KentLimit = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$pr3KentCalls, &$pr3KentLimit): array {
    $pr3KentCalls++;
    $pr3KentLimit = (int) ini_get('max_execution_time');

    return ['ok' => true, 'sent' => 1, 'action' => 'quoted'];
};
$pr3KentFirst = jeab_inbox_queue_quote_drain(5);
$pr3KentSecond = jeab_inbox_queue_quote_drain(5);
set_time_limit(0);
unset($GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT']);
if ($pr3HadSapi) {
    $GLOBALS['_JEAB_SAPI'] = $pr3PrevSapi;
} else {
    unset($GLOBALS['_JEAB_SAPI']);
}
$pr3KentRow = $qoRow($pr3KentIdem);
qo(
    $pr3KentCalls === 1
    && $pr3KentLimit >= 120
    && is_array($pr3KentRow)
    && ($pr3KentRow['status'] ?? '') === 'sent'
    && (int) ($pr3KentFirst['sent'] ?? 0) === 1
    && (int) ($pr3KentSecond['sent'] ?? 1) === 0,
    'avbruten retry skickas en gång i nästa drain med egen tidsgräns'
);

// En avbruten rad räknas som utgånget sending, så reclaim_done kollar already_out.
$pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . $pr3Far . " WHERE status IN ('pending', 'retry', 'sending')");
$pr3PrevIdem = 'pr3-prev-q|batteri';
$pr3Ins->execute([$pr3PrevIdem, 'pr3-prev-q', 'batteri', 'retry', 1, 'aborted', time() - 1800, jeab_quote_outbox_now()]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$pr3PrevClaim = jeab_quote_outbox_claim($pr3PrevIdem);
$pr3PrevSeen = jeab_quote_outbox_claim_prev();
jeab_quote_outbox_finalize('pr3-prev-q', 'batteri', $pr3PrevIdem, 'skipped', 'test');
qo($pr3PrevClaim && $pr3PrevSeen === 'sending', 'claim av avbruten rad ger prev sending så already_out kollas');

// Fredrik-mönster: pending med 0 försök bakom en äldre rad som dör mitt i utskicket.
$pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . $pr3Far . " WHERE status IN ('pending', 'retry', 'sending')");
$pr3StuckIdem = 'pr3-stuck-q|batteri';
$pr3FredIdem = 'pr3-fred-q|batteri';
$pr3Ins->execute([$pr3StuckIdem, 'pr3-stuck-q', 'batteri', 'pending', 2, '', time() - 7200, jeab_quote_outbox_now()]);
$pr3Ins->execute([$pr3FredIdem, 'pr3-fred-q', 'batteri', 'pending', 0, '', time() - 60, jeab_quote_outbox_now()]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT']);
qo(jeab_quote_outbox_claim($pr3StuckIdem), 'äldsta raden claimas först');
jeab_quote_outbox_inflight_add($pr3StuckIdem, 'pr3-stuck-q', 'batteri');
jeab_quote_outbox_inflight_abort();
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$pr3FredCalls = [];
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function (string $tid) use (&$pr3FredCalls): array {
    $pr3FredCalls[$tid] = ($pr3FredCalls[$tid] ?? 0) + 1;

    return ['ok' => true, 'sent' => 1, 'action' => 'quoted'];
};
$GLOBALS['_JEAB_SAPI'] = 'cli';
jeab_inbox_queue_quote_drain(1);
jeab_inbox_queue_quote_drain(1);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT']);
if ($pr3HadSapi) {
    $GLOBALS['_JEAB_SAPI'] = $pr3PrevSapi;
} else {
    unset($GLOBALS['_JEAB_SAPI']);
}
$pr3StuckRow = $qoRow($pr3StuckIdem);
$pr3FredRow = $qoRow($pr3FredIdem);
$pr3Reason->execute([$pr3StuckIdem]);
$pr3StuckMeta = $pr3Reason->fetch(PDO::FETCH_ASSOC);
qo(
    is_array($pr3StuckRow)
    && ($pr3StuckRow['status'] ?? '') === 'retry'
    && (int) ($pr3StuckRow['attempts'] ?? 0) === 3
    && is_array($pr3StuckMeta)
    && (string) ($pr3StuckMeta['reason'] ?? '') === 'aborted'
    && (int) ($pr3StuckMeta['next_attempt_at'] ?? 0) > time()
    && is_array($pr3FredRow)
    && ($pr3FredRow['status'] ?? '') === 'sent'
    && (int) ($pr3FredRow['attempts'] ?? 0) === 1
    && ($pr3FredCalls['pr3-fred-q'] ?? 0) === 1
    && ($pr3FredCalls['pr3-stuck-q'] ?? 0) === 0,
    'pending med 0 försök bakom en avbruten rad plockas och skickas en gång'
);

$undoSqlId = 'pr2undosql';
$undoSqlRows = [
    'pr2-undo-sql-p' => 'pending',
    'pr2-undo-sql-g' => 'sending',
    'pr2-undo-sql-t' => 'sent',
];
$undoSqlIns = $pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
     VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, 1)'
);
$undoSqlUndo = $pdo->prepare(
    'INSERT INTO quote_outbox_manual_undo (undo_id, created_at, idem_key, thread_id, manual_send) VALUES (?, ?, ?, ?, 0)'
);
$undoSqlNow = jeab_quote_outbox_now();
$undoSqlFar = time() + 86400;
foreach ($undoSqlRows as $undoSqlTid => $undoSqlStatus) {
    $undoSqlIns->execute([$undoSqlTid . '|batteri', $undoSqlTid, 'batteri', $undoSqlStatus, $undoSqlFar, '', time(), $undoSqlNow]);
    $undoSqlUndo->execute([$undoSqlId, $undoSqlNow, $undoSqlTid . '|batteri', $undoSqlTid]);
}
$undoSql = jeab_inkorg_hook([
    'action' => 'inbox.outbox_manual_mark_undo',
    'undo_id' => $undoSqlId,
    'dry' => 0,
    'confirm' => 'undo',
]);
$undoSqlAt = $pdo->prepare('SELECT undone_at FROM quote_outbox_manual_undo WHERE undo_id = ? AND idem_key = ?');
$undoSqlAt->execute([$undoSqlId, 'pr2-undo-sql-p|batteri']);
$undoSqlPendingAt = $undoSqlAt->fetchColumn();
$undoSqlAt->execute([$undoSqlId, 'pr2-undo-sql-g|batteri']);
$undoSqlSendingAt = $undoSqlAt->fetchColumn();
$undoSqlAt->execute([$undoSqlId, 'pr2-undo-sql-t|batteri']);
$undoSqlSentAt = $undoSqlAt->fetchColumn();
$undoSqlIds = [];
foreach ((array) ($undoSql['rows'] ?? []) as $undoSqlPublic) {
    if (is_array($undoSqlPublic)) {
        $undoSqlIds[] = (string) ($undoSqlPublic['idem'] ?? '');
    }
}
qo(
    ($undoSql['ok'] ?? false) === true
    && (int) ($undoSql['restored'] ?? 0) === 1
    && $undoSqlIds === ['pr2-undo-sql-p|batteri']
    && $qoManual('pr2-undo-sql-p|batteri') === 0
    && $qoManual('pr2-undo-sql-g|batteri') === 1
    && $qoManual('pr2-undo-sql-t|batteri') === 1
    && is_string($undoSqlPendingAt) && trim($undoSqlPendingAt) !== ''
    && $undoSqlSendingAt === null
    && $undoSqlSentAt === null,
    'undo räknar bara rader som ändrades och lämnar sending och sent'
);

$undoJsonForce = $GLOBALS['_JEAB_QUOTE_QUEUE_FORCE'] ?? null;
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$GLOBALS['_JEAB_QUOTE_QUEUE_FORCE'] = 'json';
$undoJsonPrev = jeab_inbox_quote_queue_load();
$undoJsonP = 'pr2-undo-js-p';
$undoJsonG = 'pr2-undo-js-g';
$undoJsonT = 'pr2-undo-js-t';
jeab_inbox_quote_queue_save([
    $undoJsonP => ['at' => time(), 'product' => 'batteri', 'idem' => $undoJsonP . '|batteri', 'status' => 'pending', 'attempts' => 1, 'next_attempt_at' => 0, 'manual_send' => 1],
    $undoJsonG => ['at' => time(), 'product' => 'batteri', 'idem' => $undoJsonG . '|batteri', 'status' => 'sending', 'attempts' => 2, 'next_attempt_at' => time() + 3600, 'manual_send' => 1],
    $undoJsonT => ['at' => time(), 'product' => 'batteri', 'idem' => $undoJsonT . '|batteri', 'status' => 'sent', 'attempts' => 1, 'next_attempt_at' => 0, 'manual_send' => 1],
]);
$undoJsonId = 'pr2undojson';
$undoJsonUndo = $pdo->prepare(
    'INSERT INTO quote_outbox_manual_undo (undo_id, created_at, idem_key, thread_id, manual_send) VALUES (?, ?, ?, ?, 0)'
);
foreach ([$undoJsonP, $undoJsonG, $undoJsonT] as $undoJsonTid) {
    $undoJsonUndo->execute([$undoJsonId, jeab_quote_outbox_now(), $undoJsonTid . '|batteri', $undoJsonTid]);
}
$undoJson = jeab_inkorg_hook([
    'action' => 'inbox.outbox_manual_mark_undo',
    'undo_id' => $undoJsonId,
    'dry' => 0,
    'confirm' => 'undo',
]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$undoJsonMap = jeab_inbox_quote_queue_load();
$undoJsonAt = $pdo->prepare('SELECT undone_at FROM quote_outbox_manual_undo WHERE undo_id = ? AND idem_key = ?');
$undoJsonAt->execute([$undoJsonId, $undoJsonP . '|batteri']);
$undoJsonPendingAt = $undoJsonAt->fetchColumn();
$undoJsonAt->execute([$undoJsonId, $undoJsonG . '|batteri']);
$undoJsonSendingAt = $undoJsonAt->fetchColumn();
$undoJsonAt->execute([$undoJsonId, $undoJsonT . '|batteri']);
$undoJsonSentAt = $undoJsonAt->fetchColumn();
$undoJsonPending = is_array($undoJsonMap[$undoJsonP] ?? null) ? $undoJsonMap[$undoJsonP] : [];
$undoJsonSending = is_array($undoJsonMap[$undoJsonG] ?? null) ? $undoJsonMap[$undoJsonG] : [];
$undoJsonSent = is_array($undoJsonMap[$undoJsonT] ?? null) ? $undoJsonMap[$undoJsonT] : [];
qo(
    ($undoJson['ok'] ?? false) === true
    && (int) ($undoJson['restored'] ?? 0) === 1
    && (int) ($undoJsonPending['manual_send'] ?? 1) === 0
    && ($undoJsonPending['status'] ?? '') === 'pending'
    && (int) ($undoJsonSending['manual_send'] ?? 0) === 1
    && ($undoJsonSending['status'] ?? '') === 'sending'
    && (int) ($undoJsonSent['manual_send'] ?? 0) === 1
    && ($undoJsonSent['status'] ?? '') === 'sent'
    && is_string($undoJsonPendingAt) && trim($undoJsonPendingAt) !== ''
    && $undoJsonSendingAt === null
    && $undoJsonSentAt === null,
    'json-undo hoppar över sending och sent'
);
jeab_inbox_quote_queue_save(is_array($undoJsonPrev) ? $undoJsonPrev : []);
if ($undoJsonForce === null) {
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_FORCE']);
} else {
    $GLOBALS['_JEAB_QUOTE_QUEUE_FORCE'] = $undoJsonForce;
}
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);

$retryQTid = 'pr2-q-retry';
$retryQMail = 'fejk.qretry.pr2@exempel.test';
$qoPut($retryQTid, 'batteri', $retryQMail, 'Fejk Q Retry');
$retryQIdem = $retryQTid . '|batteri';
$pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . (time() + 86400) . " WHERE status IN ('pending', 'retry', 'sending')");
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
     VALUES (?, ?, ?, ?, 4, 0, ?, ?, ?, 0)'
)->execute([$retryQIdem, $retryQTid, 'batteri', 'retry', 'retry', time(), jeab_quote_outbox_now()]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_OFFER_SEND_ONE_SYNC'], $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX'], $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT']);
$GLOBALS['_JEAB_SAPI'] = 'cli';
$GLOBALS['_JEAB_OFFER_SEND_ONE_ENABLED'] = true;
$retryQ = jeab_inkorg_hook([
    'action' => 'inbox.offer_send_one',
    'thread_id' => $retryQTid,
    'product' => 'batteri',
    'dry' => 0,
    'confirm' => 'send',
]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$retryQRow = $qoRow($retryQIdem);
$retryQCalls = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$retryQCalls): array {
    $retryQCalls++;

    return ['ok' => true, 'sent' => 1, 'action' => 'quoted'];
};
$retryQDrain = jeab_inbox_queue_quote_drain(5);
$retryQDrain2 = jeab_inbox_queue_quote_drain(5);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$retryQAfter = $qoRow($retryQIdem);
qo(
    ($retryQ['ok'] ?? false) === true
    && (int) ($retryQ['queued'] ?? 0) === 1
    && (int) ($retryQ['sent'] ?? 1) === 0
    && ($retryQ['error'] ?? '') === ''
    && is_array($retryQRow)
    && ($retryQRow['status'] ?? '') === 'pending'
    && (int) ($retryQRow['attempts'] ?? 1) === 0
    && $qoManual($retryQIdem) === 1
    && jeab_quote_outbox_manual_now($retryQIdem) === 1
    && $retryQCalls === 1
    && (int) ($retryQDrain['sent'] ?? 0) === 1
    && (int) ($retryQDrain2['sent'] ?? 1) === 0
    && (int) ($retryQDrain2['ran'] ?? 1) === 0
    && is_array($retryQAfter)
    && ($retryQAfter['status'] ?? '') === 'sent'
    && (int) ($retryQAfter['attempts'] ?? 0) === 1,
    'befintlig retry utan manual_send köas och skickas en gång'
);

$skipQTid = 'pr2-q-skip';
$skipQMail = 'fejk.qskip.pr2@exempel.test';
$qoPut($skipQTid, 'batteri', $skipQMail, 'Fejk Q Skip');
$skipQIdem = $skipQTid . '|batteri';
$pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . (time() + 86400) . " WHERE status IN ('pending', 'retry', 'sending')");
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
     VALUES (?, ?, ?, ?, 8, 0, ?, ?, ?, 0)'
)->execute([$skipQIdem, $skipQTid, 'batteri', 'skipped', 'max_attempts', time(), jeab_quote_outbox_now()]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_OFFER_SEND_ONE_SYNC'], $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX'], $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT']);
$GLOBALS['_JEAB_SAPI'] = 'cli';
$GLOBALS['_JEAB_OFFER_SEND_ONE_ENABLED'] = true;
$skipQ = jeab_inkorg_hook([
    'action' => 'inbox.offer_send_one',
    'thread_id' => $skipQTid,
    'product' => 'batteri',
    'dry' => 0,
    'confirm' => 'send',
]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$skipQRow = $qoRow($skipQIdem);
$skipQCalls = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$skipQCalls): array {
    $skipQCalls++;

    return ['ok' => true, 'sent' => 1, 'action' => 'quoted'];
};
$skipQDrain = jeab_inbox_queue_quote_drain(5);
$skipQDrain2 = jeab_inbox_queue_quote_drain(5);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$skipQAfter = $qoRow($skipQIdem);
qo(
    ($skipQ['ok'] ?? false) === true
    && (int) ($skipQ['queued'] ?? 0) === 1
    && (int) ($skipQ['sent'] ?? 1) === 0
    && ($skipQ['error'] ?? '') === ''
    && is_array($skipQRow)
    && ($skipQRow['status'] ?? '') === 'pending'
    && (int) ($skipQRow['attempts'] ?? 1) === 0
    && $qoManual($skipQIdem) === 1
    && $skipQCalls === 1
    && (int) ($skipQDrain['sent'] ?? 0) === 1
    && (int) ($skipQDrain2['sent'] ?? 1) === 0
    && is_array($skipQAfter)
    && ($skipQAfter['status'] ?? '') === 'sent'
    && (int) ($skipQAfter['attempts'] ?? 0) === 1,
    'skipped max_attempts tas tillbaka och skickas en gång'
);

$freshQTid = 'pr2-q-fresh';
$freshQMail = 'fejk.qfresh.pr2@exempel.test';
$qoPut($freshQTid, 'batteri', $freshQMail, 'Fejk Q Fresh');
$freshQIdem = $freshQTid . '|batteri';
$freshQNext = time() + 3600;
$pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . (time() + 86400) . " WHERE status IN ('pending', 'retry', 'sending')");
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
     VALUES (?, ?, ?, ?, 2, ?, ?, ?, ?, 0)'
)->execute([$freshQIdem, $freshQTid, 'batteri', 'sending', $freshQNext, 'lease', time(), jeab_quote_outbox_now()]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_OFFER_SEND_ONE_SYNC'], $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX'], $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT']);
$GLOBALS['_JEAB_SAPI'] = 'cli';
$GLOBALS['_JEAB_OFFER_SEND_ONE_ENABLED'] = true;
$freshQ = jeab_inkorg_hook([
    'action' => 'inbox.offer_send_one',
    'thread_id' => $freshQTid,
    'product' => 'batteri',
    'dry' => 0,
    'confirm' => 'send',
]);
$freshQCalls = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$freshQCalls): array {
    $freshQCalls++;

    return ['ok' => true, 'sent' => 1, 'action' => 'quoted'];
};
$freshQDrain = jeab_inbox_queue_quote_drain(5);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$freshQRow = $qoRow($freshQIdem);
qo(
    ($freshQ['ok'] ?? true) === false
    && ($freshQ['error'] ?? '') === 'sending'
    && (int) ($freshQ['queued'] ?? 1) === 0
    && (int) ($freshQ['sent'] ?? 1) === 0
    && $freshQCalls === 0
    && (int) ($freshQDrain['ran'] ?? 1) === 0
    && is_array($freshQRow)
    && ($freshQRow['status'] ?? '') === 'sending'
    && (int) ($freshQRow['attempts'] ?? 0) === 2
    && (int) ($freshQRow['next_attempt_at'] ?? 0) === $freshQNext
    && $qoManual($freshQIdem) === 0,
    'färskt sending svarar inte queued'
);

$holdMTid = 'pr2-q-hold';
$holdMIdem = $holdMTid . '|batteri';
$holdMWhy = 'Grok är osäker på leadsäljare';
$pdo->exec("UPDATE quote_outbox SET next_attempt_at = " . (time() + 86400) . " WHERE status IN ('pending', 'retry', 'sending')");
$pdo->prepare(
    'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
     VALUES (?, ?, ?, ?, 7, 0, ?, ?, ?, 1)'
)->execute([$holdMIdem, $holdMTid, 'batteri', 'pending', '', time(), jeab_quote_outbox_now()]);
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM'], $GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX'], $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT']);
$GLOBALS['_JEAB_SAPI'] = 'cli';
$holdMCalls = 0;
$GLOBALS['_JEAB_QUOTE_DRAIN_SEND'] = static function () use (&$holdMCalls, $holdMWhy): array {
    $holdMCalls++;

    return ['action' => 'hold', 'why' => $holdMWhy, 'sent' => 0];
};
$holdMDrain = jeab_inbox_queue_quote_drain(5);
unset($GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
$holdMRow = $qoRow($holdMIdem);
$holdMReasonStmt = $pdo->prepare('SELECT reason FROM quote_outbox WHERE idem_key = ?');
$holdMReasonStmt->execute([$holdMIdem]);
$holdMReason = (string) $holdMReasonStmt->fetchColumn();
qo(
    $holdMCalls === 1
    && (int) ($holdMDrain['ran'] ?? 0) === 1
    && (int) ($holdMDrain['sent'] ?? 1) === 0
    && is_array($holdMRow)
    && ($holdMRow['status'] ?? '') === 'retry'
    && ($holdMRow['status'] ?? '') !== 'sending'
    && (int) ($holdMRow['attempts'] ?? 0) === 7
    && $qoManual($holdMIdem) === 1
    && str_contains($holdMReason, 'Grok är osäker'),
    'manuellt håll lämnar retry utan att bränna försök'
);

$qoHasManual = static function (PDO $pdo, string $driver): bool {
    if ($driver === 'mysql') {
        $stmt = $pdo->query('SHOW COLUMNS FROM quote_outbox');
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $col) {
            if (!is_array($col)) {
                continue;
            }
            $field = strtolower((string) ($col['Field'] ?? $col['field'] ?? ''));
            if ($field === 'manual_send') {
                return true;
            }
        }

        return false;
    }
    $stmt = $pdo->query('PRAGMA table_info(quote_outbox)');
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $col) {
        if (is_array($col) && (string) ($col['name'] ?? '') === 'manual_send') {
            return true;
        }
    }

    return false;
};
$hadManualCol = $qoHasManual($pdo, $qoDriver);
$colMissing = false;
$colThrew = false;
$colStillMissing = false;
$colDropFail = '';
try {
    $pdo->exec('ALTER TABLE quote_outbox DROP COLUMN manual_send');
    $colMissing = !$qoHasManual($pdo, $qoDriver);
    if ($colMissing) {
        try {
            jeab_quote_outbox_manual_fetch($pdo, 'pr2-saknad|batteri');
        } catch (Throwable $e) {
            $colThrew = jeab_quote_outbox_manual_column_missing($e);
        }
        $pdo->beginTransaction();
        try {
            jeab_quote_outbox_manual_now('pr2-saknad|batteri');
            $colThrew = false;
        } catch (Throwable $e) {
            $colThrew = $colThrew && jeab_quote_outbox_manual_column_missing($e);
        }
        $colStillMissing = !$qoHasManual($pdo, $qoDriver);
    }
} catch (Throwable $e) {
    $colDropFail = $e->getMessage();
} finally {
    if ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable) {
        }
    }
    try {
        jeab_ensure_quote_outbox($pdo, $qoDriver);
    } catch (Throwable) {
    }
}
$colBack = $qoHasManual($pdo, $qoDriver);
$colRead = -1;
if ($colBack) {
    $colIdem = 'pr2-col-q|batteri';
    $pdo->prepare(
        'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send)
         VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, 1)'
    )->execute([$colIdem, 'pr2-col-q', 'batteri', 'pending', '', time(), jeab_quote_outbox_now()]);
    try {
        $colRead = jeab_quote_outbox_manual_now($colIdem);
    } catch (Throwable) {
        $colRead = -1;
    }
}
$colWhy = '';
if (!$hadManualCol) {
    $colWhy = 'kolumn saknades redan';
} elseif ($colDropFail !== '') {
    $colWhy = 'drop: ' . substr($colDropFail, 0, 120);
} elseif (!$colMissing) {
    $colWhy = 'kolumnen fanns kvar efter drop';
} elseif (!$colThrew) {
    $colWhy = 'läsningen gav inte fel';
} elseif (!$colStillMissing) {
    $colWhy = 'kolumnen lades till inne i transaktionen';
} elseif (!$colBack) {
    $colWhy = 'ensure lade inte till kolumnen';
} elseif ($colRead !== 1) {
    $colWhy = 'manual_send lästes som ' . (string) $colRead;
}
qo(
    $colWhy === '',
    'ensure lägger till manual_send på en tabell utan kolumnen' . ($colWhy !== '' ? ' (' . $colWhy . ')' : '')
);

unset($GLOBALS['_JEAB_OFFER_SEND_ONE_ENABLED'], $GLOBALS['_JEAB_OFFER_SEND_ONE_SYNC'], $GLOBALS['_JEAB_QUOTE_DRAIN_SEND'], $GLOBALS['_JEAB_TICK'], $GLOBALS['_JEAB_QUOTE_OUTBOX_CTX'], $GLOBALS['_JEAB_QUOTE_OUTBOX_INFLIGHT']);

echo "quote outbox ok\n";
