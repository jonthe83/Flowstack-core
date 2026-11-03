<?php

declare(strict_types=1);

/**
 * Bygger data/.private/app.sqlite med påhittad data.
 * Raderar bara sqlite-filen, aldrig secrets.
 */

putenv('JEAB_DEMO=1');
$_ENV['JEAB_DEMO'] = '1';

$root = dirname(__DIR__);
$data = $root . '/data/.private';
if (!is_dir($data) && !mkdir($data, 0775, true) && !is_dir($data)) {
    fwrite(STDERR, "kan inte skapa datakatalog\n");
    exit(1);
}
foreach ([
    'app.sqlite',
    'app.sqlite-wal',
    'app.sqlite-shm',
    'store.json',
    'demo-send.log',
    'calendar-log.json',
    'kontakter-dedupe.log',
    'inkorg/threads.json',
    'inkorg/messages.json',
    'inkorg/contacts.json',
    'notiser/mallar.json',
] as $name) {
    $path = $data . '/' . $name;
    if (is_file($path)) {
        unlink($path);
    }
}

require $root . '/src/bootstrap.php';
require_once $root . '/src/inkorg_contact_create.php';

if (!jeab_demo_mode()) {
    fwrite(STDERR, "demo är av\n");
    exit(1);
}

function demo_seed_step(string $label, callable $fn): void
{
    try {
        $fn();
        echo "OK  {$label}\n";
    } catch (Throwable $e) {
        echo "FAIL {$label}: " . $e->getMessage() . "\n";
    }
}

$store = jeab_store();
$tomorrow = (new DateTimeImmutable('tomorrow 10:00'))->format('Y-m-d H:i:s');
$due = (new DateTimeImmutable('today 09:00'))->format('Y-m-d H:i:s');

demo_seed_step('kontakt nora', static function (): void {
    $row = jeab_kontakt_create_unmerged([
        'name' => 'Nora Exempel',
        'email' => 'nora.exempel@example.test',
        'phone' => '0700000001',
        'street' => 'Exempelgatan 1',
        'postal_code' => '17700',
        'city' => 'Järfälla',
        'company' => 'jarfalla',
        'source' => 'demo',
    ]);
    if (!is_array($row)) {
        throw new RuntimeException('kontakt skapades inte');
    }
});

demo_seed_step('kontakt lars', static function (): void {
    $row = jeab_kontakt_create_unmerged([
        'name' => 'Lars Provkund',
        'email' => 'lars.provkund@example.test',
        'phone' => '0700000002',
        'street' => 'Provgatan 4',
        'postal_code' => '21100',
        'city' => 'Malmö',
        'company' => 'malmo',
        'source' => 'demo',
    ]);
    if (!is_array($row)) {
        throw new RuntimeException('kontakt skapades inte');
    }
});

$reminderId = 0;
demo_seed_step('påminnelse', static function () use ($store, $due, &$reminderId): void {
    $row = jeab_create_reminder($store, 'Nora Exempel – Batteri', $due, 'Vill ha batteri. Påhittad demo.', null, [
        'customer' => 'Nora Exempel',
        'company' => 'jarfalla',
        'email' => 'nora.exempel@example.test',
        'phone' => '0700000001',
        'address' => 'Exempelgatan 1, 177 00 Järfälla',
        'postal' => '17700',
        'ort' => 'Järfälla',
        'assignees' => ['Demo Säljare'],
        'lookup' => false,
    ]);
    $reminderId = (int) ($row['id'] ?? 0);
    if ($reminderId <= 0) {
        throw new RuntimeException('påminnelse saknar id');
    }
});

demo_seed_step('påminnelse syd', static function () use ($store): void {
    jeab_create_reminder($store, 'Lars Provkund – Sol', null, 'Tak mot söder. Påhittad demo.', null, [
        'customer' => 'Lars Provkund',
        'company' => 'malmo',
        'email' => 'lars.provkund@example.test',
        'phone' => '0700000002',
        'address' => 'Provgatan 4, 211 00 Malmö',
        'postal' => '21100',
        'ort' => 'Malmö',
        'assignees' => ['Demo Syd'],
        'lookup' => false,
    ]);
});

demo_seed_step('bokning', static function () use ($store, $tomorrow, $reminderId): void {
    jeab_create_booking($store, 'Nora Exempel', ['Demo Säljare'], $tomorrow, null, 'batteri', [
        'company' => 'jarfalla',
        'address' => 'Exempelgatan 1, 177 00 Järfälla',
        'phone' => '0700000001',
        'email' => 'nora.exempel@example.test',
        'reminder_id' => $reminderId,
        'lookup' => false,
    ], false);
});

demo_seed_step('order', static function () use ($reminderId): void {
    if ($reminderId <= 0 || !function_exists('jeab_purchase_put')) {
        throw new RuntimeException('order saknar påminnelse');
    }
    $pris = function_exists('jeab_offert_neo_kundpris') ? jeab_offert_neo_kundpris(1) : null;
    if (!is_int($pris) || $pris <= 0) {
        throw new RuntimeException('riktigt kundpris saknas');
    }
    jeab_purchase_put([
        'reminder_id' => $reminderId,
        'company' => 'jarfalla',
        'kind' => 'batteri',
        'customer' => 'Nora Exempel',
        'address' => 'Exempelgatan 1, 177 00 Järfälla',
        'pris' => $pris,
        'kundpris' => $pris,
        'kalkyl' => [
            'sigen_typ' => 'neo',
            'sigen_kw' => '8.0',
            'b3' => 1,
        ],
        'install_note' => 'Påhittad demoorder.',
        'fastighetsbeteckning' => 'EXEMPEL 1:1',
        'seller' => 'Demo Säljare',
        'assignee' => 'Demo Säljare',
        'assignees' => ['Demo Säljare'],
        'who' => 'Demo Säljare',
        'ansvarig' => 'Demo Säljare',
        'person' => 'DemoSalj',
        'steps' => [],
    ]);
});

demo_seed_step('inkorg', static function (): void {
    $res = jeab_inkorg_ingest([
        'message_id' => 'demo-nora@example.test',
        'from' => 'nora.exempel@example.test',
        'from_name' => 'Nora Exempel',
        'to' => 'demo.saljare@example.test',
        'subject' => 'Offert batteri',
        'body_text' => "Hej\n\nVi vill ha ett batteri på Exempelgatan 1. Detta är påhittat.\n",
        'source' => 'demo',
        'test' => true,
        'thread_key' => 'demo-nora',
    ]);
    if (empty($res['ok'])) {
        throw new RuntimeException((string) ($res['error'] ?? 'inkorg'));
    }
    $tid = trim((string) ($res['thread_id'] ?? ''));
    if ($tid !== '' && function_exists('jeab_quote_outbox_enqueue_one')) {
        jeab_quote_outbox_enqueue_one($tid, 'batteri', true);
    }
});

demo_seed_step('inkorg 2', static function (): void {
    $res = jeab_inkorg_ingest([
        'message_id' => 'demo-lars@example.test',
        'from' => 'lars.provkund@example.test',
        'from_name' => 'Lars Provkund',
        'to' => 'demo.syd@example.test',
        'subject' => 'Fråga om solceller',
        'body_text' => "Hej\n\nVad kostar solceller ungefär? Påhittad demo.\n",
        'source' => 'demo',
        'test' => true,
        'thread_key' => 'demo-lars',
    ]);
    if (empty($res['ok'])) {
        throw new RuntimeException((string) ($res['error'] ?? 'inkorg'));
    }
});

demo_seed_step('offertkö', static function () use ($data): void {
    $threads = $data . '/inkorg/threads.json';
    $raw = is_file($threads) ? json_decode((string) file_get_contents($threads), true) : null;
    if (is_array($raw) && isset($raw['threads']) && is_array($raw['threads'])) {
        foreach ($raw['threads'] as &$thread) {
            if (!is_array($thread)) {
                continue;
            }
            $thread['status'] = 'open';
            unset($thread['hook_closed']);
        }
        unset($thread);
        file_put_contents($threads, json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    $pdo = new PDO('sqlite:' . $data . '/app.sqlite');
    $now = time();
    $pdo->prepare(
        'INSERT INTO quote_outbox (idem_key, thread_id, product, status, attempts, next_attempt_at, reason, queued_at, updated_at, manual_send, guard_job)
         VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, 1, ?)'
    )->execute([
        'demo-nora:batteri',
        'demo-nora',
        'batteri',
        'waiting',
        $now + 86400 * 365,
        'demo',
        $now,
        $now,
        '',
    ]);
});

demo_seed_step('notis', static function (): void {
    if (!function_exists('jeab_notiser_save_body')) {
        throw new RuntimeException('notiser saknas');
    }
    jeab_notiser_save_body('offert-batteri-jarfalla', "Hej\n\nDet här är en påhittad demo-offert. Inget skickas.\n", 'demo');
});

$db = jeab_config()['db']['sqlite_path'] ?? '';
echo 'sqlite ' . (is_string($db) && is_file($db) ? 'finns' : 'saknas') . "\n";
