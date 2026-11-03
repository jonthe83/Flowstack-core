<?php

declare(strict_types=1);

/**
 * Köad offert (5-minutersbromsen) är inte sent.
 * Replay skriver sent_log och send_once. Borttaget jobb blir retry utan dubblett.
 * JSON-abort markerar reason och ger prev sending. Påhittade adresser. Inget nät.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-quote-guard-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/secrets/m365.json', json_encode([
    'tenant_id' => 'fejk-tenant',
    'client_id' => 'fejk-client',
    'client_secret' => 'fejk-secret',
], JSON_UNESCAPED_UNICODE));
require dirname(__DIR__) . '/src/bootstrap.php';

function qg(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "OK  {$message}\n";
}

$GLOBALS['_JEAB_SEND_GUARD_DRAIN'] = static function (): void {
};
$GLOBALS['_JEAB_GP_FLAGS'] = ['graph_person_mail' => true];
$GLOBALS['_QG_HTTP'] = 0;
$GLOBALS['_QG_SEND_HTTP'] = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url): array {
    $GLOBALS['_QG_HTTP']++;
    $target = strtolower($method . ' ' . $url);
    if (str_contains($target, 'sendmail') || str_contains($target, '/reply') || str_contains($target, '/send')) {
        $GLOBALS['_QG_SEND_HTTP']++;
    }

    return ['code' => 200, 'body' => ['value' => []]];
};
$GLOBALS['_QG_CALLS'] = 0;

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 guard\n")]);
require __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();

function qg_offert(string $tid): int
{
    $pdo = jeab_data_pdo();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM inbox_sent WHERE thread_id = ? AND kind = ?');
    $stmt->execute([$tid, 'offert']);

    return (int) $stmt->fetchColumn();
}

function qg_row(string $tid): array
{
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);

    return jeab_quote_outbox_lookup($tid, 'batteri');
}

function qg_job(int $id): array
{
    $pdo = jeab_send_guard_pdo();
    $stmt = $pdo->prepare('SELECT id, status, job FROM send_queue WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return [];
    }
    $raw = base64_decode((string) ($row['job'] ?? ''), true);
    $job = is_string($raw) ? unserialize($raw, ['allowed_classes' => false]) : false;

    return [
        'id' => (int) ($row['id'] ?? 0),
        'status' => (string) ($row['status'] ?? ''),
        'job' => is_array($job) ? $job : [],
    ];
}

function qg_guard(array $job): array
{
    $args = is_array($job['args'] ?? null) ? $job['args'] : [];
    $opts = is_array($args['opts'] ?? null) ? $args['opts'] : [];
    $guard = $opts['quote_guard'] ?? null;

    return is_array($guard) ? $guard : [];
}

function qg_thread(string $slug, string $email, string $phone, string $name, string $street): string
{
    $in = jeab_inkorg_ingest([
        'message_id' => $slug . '@qg.test',
        'from' => 'info@wix-forms.com',
        'from_name' => 'Formulär',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Offert ' . $name,
        'body_text' => "Namn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nAdress: {$street}, 176 70 Järfälla\nProdukt: Batteri\n",
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'thread_key' => $slug,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
        ],
    ]);
    $tid = (string) ($in['thread_id'] ?? '');
    qg($tid !== '', $slug . ' fick en tråd');

    return $tid;
}

function qg_arm(string $tid): string
{
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
    if (jeab_quote_queue_backend() === 'sql') {
        $pdo = jeab_quote_outbox_pdo();
        qg($pdo instanceof PDO, 'utbox-pdo');
        $pdo->prepare('DELETE FROM quote_outbox WHERE thread_id = ?')->execute([$tid]);
    } else {
        $all = function_exists('jeab_inbox_quote_queue_load') ? jeab_inbox_quote_queue_load() : [];
        unset($all[$tid]);
        jeab_inbox_quote_queue_save($all);
        unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
    }
    $idem = jeab_quote_outbox_enqueue_one($tid, 'batteri');
    qg($idem !== '', 'utbox-rad för ' . $tid);

    return $idem;
}

function qg_prime(int $now): void
{
    $GLOBALS['_JEAB_SEND_GUARD_NOW'] = $now;
    $body = 'prime-' . $now;
    $decision = send_guard_check('email', 'broms.prime.' . $now . '@gmail.com', 'email', 'auto', 'auto', 'system', hash('sha256', $body), [
        'body' => $body,
        'handler' => 'system_mail',
        'job' => ['opts' => ['to' => 'broms.prime.' . $now . '@gmail.com', 'subject' => 'Prime', 'text' => $body]],
    ]);
    qg(!empty($decision['send']) && empty($decision['queued']), 'bromsen släpper första utskicket ' . $now);
}

function qg_backend(bool $json): void
{
    if ($json) {
        $GLOBALS['_JEAB_QUOTE_QUEUE_FORCE'] = 'json';
    } else {
        unset($GLOBALS['_JEAB_QUOTE_QUEUE_FORCE']);
    }
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
}

function qg_abort_json(): void
{
    qg_backend(true);
    $idem = 'json-abort|batteri';
    jeab_inbox_quote_queue_save([
        'json-abort' => [
            'at' => time(),
            'product' => 'batteri',
            'idem' => $idem,
            'status' => 'sending',
            'attempts' => 2,
            'next_attempt_at' => time() + 600,
            'reason' => '',
            'manual_send' => 0,
        ],
    ]);
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
    jeab_quote_outbox_inflight_add($idem, 'json-abort', 'batteri');
    jeab_quote_outbox_inflight_abort();
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
    $row = jeab_quote_outbox_idem_row($idem);
    qg(is_array($row), 'json-abort har en rad');
    qg((string) ($row['status'] ?? '') === 'retry', 'json-abort är retry');
    qg((string) ($row['reason'] ?? '') === 'aborted', 'json-abort skrev reason aborted');
    qg((int) ($row['attempts'] ?? 0) === 2, 'json-abort räknar inte upp försöken');
    jeab_quote_outbox_mark($idem, 'retry', 2, 0, 'aborted');
    $claimed = jeab_quote_outbox_claim($idem);
    qg($claimed === true, 'json-claim av aborted går igenom');
    qg(jeab_quote_outbox_claim_prev() === 'sending', 'json-claim av aborted ger prev sending');
    qg_backend(false);
}

function qg_case(string $label, bool $json, int $t0): void
{
    qg_backend($json);
    unset($GLOBALS['JEAB_GRAPH_SEND']);
    qg_prime($t0);
    $GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 1;
    // Telefon, namn och gata måste skilja sig mellan sql- och json-fallet.
    // Samma telefon kopplas till kontakten från det första fallet och offerten
    // stängs som "nytt formulär" i stället för att köas.
    $phoneA = $json ? '070-333 00 33' : '070-111 00 11';
    $phoneB = $json ? '070-444 00 44' : '070-222 00 22';
    $streetA = $json ? 'Celsiugatan 12' : 'Alfagatan 4';
    $streetB = $json ? 'Deltagatan 8' : 'Betagatan 9';
    $tid = qg_thread($label . '-a', 'fejk.' . $label . '.a@gmail.com', $phoneA, 'Fejk Alfa ' . $label, $streetA);
    $idem = qg_arm($tid);
    $first = jeab_inbox_queue_quote_drain(5);
    $row = qg_row($tid);
    qg((int) ($first['ran'] ?? 0) === 1 && (int) ($first['sent'] ?? 0) === 0, $label . ' drain köade utan sent (' . json_encode($first) . ' ' . json_encode($row) . ')');
    qg((string) ($row['status'] ?? '') === 'waiting', $label . ' utbox är waiting ' . json_encode($row) . ' events=' . json_encode(jeab_quote_outbox_events($tid)));
    qg((string) ($row['reason'] ?? '') === 'send_guard_queued', $label . ' reason är send_guard_queued');
    $jobId = jeab_quote_outbox_guard_job($idem);
    qg($jobId > 0, $label . ' guard_job är sparat');
    qg(qg_offert($tid) === 0, $label . ' ingen sent_log');
    $packed = qg_job($jobId);
    $guard = qg_guard($packed['job']);
    qg((string) ($guard['thread_id'] ?? '') === $tid, $label . ' jobbet har tråd');
    qg((string) ($guard['product'] ?? '') === 'batteri', $label . ' jobbet har produkt');
    qg((string) ($guard['quote_idem'] ?? '') === $idem, $label . ' jobbet har quote_idem');
    $keys = is_array($guard['idem_keys'] ?? null) ? $guard['idem_keys'] : [];
    qg($keys !== [] && jeab_send_once_peek('offert', $keys, 600) === null, $label . ' ingen send_once');
    $dueIds = array_map(static fn (array $due): string => (string) ($due['idem'] ?? ''), jeab_quote_outbox_due(20));
    qg(!in_array($idem, $dueIds, true), $label . ' due tar inte waiting');
    qg(jeab_quote_outbox_claim($idem) === false, $label . ' claim tar inte waiting');
    qg(jeab_quote_send_guard_blocks($tid, 'batteri') === true, $label . ' waiting räknas som på väg');
    qg(jeab_quote_send_guard_blocks($tid, 'laddbox') === false, $label . ' annan produkt blockeras inte');
    $second = jeab_inbox_queue_quote_drain(5);
    qg((int) ($second['ran'] ?? 0) === 0 && (int) ($second['sent'] ?? 0) === 0, $label . ' ny drain skickar inte waiting');
    qg((int) $GLOBALS['_QG_SEND_HTTP'] === 0 && (int) $GLOBALS['_QG_CALLS'] === 0, $label . ' inget utskick mot graph');

    $GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 301;
    $GLOBALS['JEAB_GRAPH_SEND'] = static function (array $args): array {
        unset($args);
        $GLOBALS['_QG_CALLS']++;

        return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
    };
    $replay = send_guard_drain();
    qg(!empty($replay['ran']) && (int) ($replay['sent'] ?? 0) === 1, $label . ' replay skickade jobbet ' . json_encode($replay));
    qg((int) $GLOBALS['_QG_CALLS'] === 1, $label . ' exakt ett mock-anrop');
    qg(qg_offert($tid) === 1, $label . ' exakt en sent_log n=' . qg_offert($tid) . ' calls=' . $GLOBALS['_QG_CALLS']);
    $sentRow = qg_row($tid);
    qg((string) ($sentRow['status'] ?? '') === 'sent', $label . ' utbox är sent');
    qg(jeab_send_once_peek('offert', $keys, 600) !== null, $label . ' send_once efter replay');
    qg((int) $GLOBALS['_QG_SEND_HTTP'] === 0, $label . ' replay träffade inte sendMail');

    jeab_quote_outbox_mark($idem, 'retry', (int) ($sentRow['attempts'] ?? 0), 0, 'send_guard_dropped');
    $again = jeab_inbox_queue_quote_drain(5);
    qg((int) $GLOBALS['_QG_CALLS'] === 1, $label . ' already_out skickar inte igen ' . json_encode($again));
    qg(qg_offert($tid) === 1, $label . ' still en sent_log');
    $reasons = array_column(jeab_quote_outbox_events($tid), 'reason');
    qg(in_array('already_out', $reasons, true), $label . ' retry gick via already_out');

    unset($GLOBALS['JEAB_GRAPH_SEND']);
    $GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 302;
    $tid2 = qg_thread($label . '-b', 'fejk.' . $label . '.b@gmail.com', $phoneB, 'Fejk Beta ' . $label, $streetB);
    $idem2 = qg_arm($tid2);
    $queued = jeab_inbox_queue_quote_drain(5);
    $wait = qg_row($tid2);
    qg((int) ($queued['sent'] ?? 0) === 0 && (string) ($wait['status'] ?? '') === 'waiting', $label . ' andra offerten köas ' . json_encode($wait));
    qg(qg_offert($tid2) === 0 && (int) $GLOBALS['_QG_CALLS'] === 1, $label . ' andra offerten skickades inte');
    $dropId = jeab_quote_outbox_guard_job($idem2);
    qg(jeab_send_guard_drop_job($dropId) === true, $label . ' jobbet tas bort');
    $dropped = qg_row($tid2);
    qg((string) ($dropped['status'] ?? '') === 'retry' && (string) ($dropped['reason'] ?? '') === 'send_guard_dropped', $label . ' borttaget jobb blir retry');
    qg(qg_offert($tid2) === 0 && (int) $GLOBALS['_QG_CALLS'] === 1, $label . ' borttaget jobb skickade inget');

    $GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 603;
    $GLOBALS['JEAB_GRAPH_SEND'] = static function (array $args): array {
        unset($args);
        $GLOBALS['_QG_CALLS']++;

        return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g2', 'conversation_id' => 'c2', 'message_id' => 'm2@x'];
    };
    $retry = jeab_inbox_queue_quote_drain(5);
    qg((int) ($retry['sent'] ?? 0) === 1 && (int) $GLOBALS['_QG_CALLS'] === 2, $label . ' retry skickar en gång ' . json_encode($retry));
    qg(qg_offert($tid2) === 1, $label . ' retry skrev en sent_log');
    $third = jeab_inbox_queue_quote_drain(5);
    qg((int) ($third['sent'] ?? 0) === 0 && (int) $GLOBALS['_QG_CALLS'] === 2 && qg_offert($tid2) === 1, $label . ' andra drain skickar inte igen');
    // send_guard.replay_one: dry visar, confirm skickar en gång, andra anrop 0.
    $mock = $GLOBALS['JEAB_GRAPH_SEND'];
    unset($GLOBALS['JEAB_GRAPH_SEND']);
    qg_prime($t0 + 1000);
    $GLOBALS['_JEAB_SEND_GUARD_NOW'] = $t0 + 1001;
    $phoneC = $json ? '070-666 00 66' : '070-555 00 55';
    $streetC = $json ? 'Fjärdegatan 3' : 'Epsilongatan 5';
    $tid3 = qg_thread($label . '-c', 'fejk.' . $label . '.c@gmail.com', $phoneC, 'Fejk Gamma ' . $label, $streetC);
    $idem3 = qg_arm($tid3);
    jeab_inbox_queue_quote_drain(5);
    $r3 = qg_row($tid3); qg((string) ($r3['status'] ?? '') === 'waiting', $label . ' tredje offerten väntar ' . json_encode($r3) . ' ' . json_encode(array_column(jeab_quote_outbox_events($tid3), 'reason')));
    $job3 = jeab_quote_outbox_guard_job($idem3);
    $GLOBALS['JEAB_GRAPH_SEND'] = $mock;
    $calls = (int) $GLOBALS['_QG_CALLS'];
    $dry = jeab_send_guard_replay_one_hook(['id' => $job3]);
    qg(!empty($dry['dry']) && (int) $dry['sent'] === 0 && (string) ($dry['job']['status'] ?? '') === 'waiting', $label . ' replay_one dry visar');
    $noConfirm = jeab_send_guard_replay_one_hook(['id' => $job3, 'dry' => 0]);
    qg(!empty($noConfirm['dry']) && (int) $noConfirm['sent'] === 0, $label . ' replay_one utan confirm skickar inte');
    qg((int) $GLOBALS['_QG_CALLS'] === $calls && qg_offert($tid3) === 0, $label . ' dry gav inget mejl');
    $one = jeab_send_guard_replay_one_hook(['id' => $job3, 'dry' => 0, 'confirm' => 'send']);
    qg(!empty($one['ok']) && (int) $one['sent'] === 1, $label . ' replay_one skickar ' . json_encode($one));
    qg((int) $GLOBALS['_QG_CALLS'] === $calls + 1 && qg_offert($tid3) === 1, $label . ' replay_one exakt ett mejl och en sent_log');
    qg((string) (qg_row($tid3)['status'] ?? '') === 'sent', $label . ' replay_one utbox sent');
    $two = jeab_send_guard_replay_one_hook(['id' => $job3, 'dry' => 0, 'confirm' => 'send']);
    qg(empty($two['ok']) && (int) $two['sent'] === 0 && (string) ($two['error'] ?? '') === 'redan skickad', $label . ' replay_one andra anrop nekas');
    $drainAfter = send_guard_drain();
    qg((int) ($drainAfter['sent'] ?? 0) === 0 && (int) $GLOBALS['_QG_CALLS'] === $calls + 1 && qg_offert($tid3) === 1, $label . ' drain efter replay_one skickar 0');
    $pdo = jeab_send_guard_pdo();
    $pdo->prepare("UPDATE send_queue SET status = 'sending', claim_token = 'annan' WHERE id = ?")->execute([$job3]);
    $busy = jeab_send_guard_replay_one_hook(['id' => $job3, 'dry' => 0, 'confirm' => 'send']);
    qg(empty($busy['ok']) && (int) $busy['sent'] === 0 && (string) ($busy['error'] ?? '') === 'inte waiting', $label . ' replay_one nekar sending (parallell claim)');
    qg((int) $GLOBALS['_QG_CALLS'] === $calls + 1, $label . ' parallell gav inget mejl');
    $pdo->prepare("UPDATE send_queue SET status = 'sent', claim_token = NULL WHERE id = ?")->execute([$job3]);
    unset($GLOBALS['JEAB_GRAPH_SEND']);
    $GLOBALS['_QG_CALLS'] = 0;
    qg_backend(false);
}

qg_abort_json();
$t0 = strtotime('2026-06-01 12:00:00');
qg($t0 !== false, 'klockan går att tolka');
qg_case('sql', false, $t0);
qg_case('json', true, $t0 + 10000);

echo "OK  quote_outbox_send_guard\n";
