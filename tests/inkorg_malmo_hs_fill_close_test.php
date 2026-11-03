<?php

declare(strict_types=1);

/** H-INK-7: Kim stänger inte Help Scout när offerten eller påminnelsen inte blev av. */

$tmp = sys_get_temp_dir() . '/jeab-mhs-close-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function mhc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function mhc_thread(array $conversation, array $threads): array
{
    $conversation['_threads'] = $threads;

    return $conversation;
}

function mhc_customer(string $id, string $email, string $body): array
{
    return [
        'id' => $id,
        'type' => 'customer',
        'createdAt' => '2026-10-18T08:00:00Z',
        'body' => $body,
        'createdBy' => ['first' => 'Kund', 'last' => 'Test', 'email' => $email],
        'customer' => ['id' => '9' . $id, 'email' => $email, 'first' => 'Kund', 'last' => 'Test'],
    ];
}

$writes = [];
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function (): array {
    return ['code' => 200, 'body' => '{"_embedded":{"conversations":[],"threads":[]},"page":{"totalPages":1}}'];
};
$GLOBALS['_JEAB_MALMO_HS_HTTP'] = static function (string $method, string $path, array $body) use (&$writes): array {
    $writes[] = [$method, $path, $body];

    return ['code' => 201];
};
file_put_contents(jeab_helpscout_path(), json_encode(['mailbox_id_malmo' => 4242]));
jeab_malmo_hs_settings_save(['malmo_hs_sync' => '1', 'malmo_hs_close' => '1']);

$mailOk = false;
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$mailOk): array {
    unset($opts);
    if (!$mailOk) {
        return ['ok' => false, 'error' => 'stop'];
    }

    return ['ok' => true, 'path' => 'inkorg', 'id' => 'stub-fill', 'from' => 'saljare.syd@malmo.example.test'];
};

$GLOBALS['_JEAB_MALMO_HS_FIXTURES'] = [
    mhc_thread([
        'id' => 701,
        'subject' => '[batteri] Offerten faller',
        'status' => 'active',
        'modifiedAt' => '2026-10-18T08:00:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9701', 'email' => 'fall.kund@brevlada.se', 'first' => 'Fall', 'last' => 'Kund'],
    ], [
        mhc_customer('701', 'fall.kund@brevlada.se', '[batteri] Jag vill köpa ett batteri.'),
    ]),
];
$failed = jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'limit' => 5]);
$patched = false;
foreach ($writes as $write) {
    if (($write[0] ?? '') === 'PATCH') {
        $patched = true;
    }
}
$failedTid = '';
foreach (jeab_inkorg_threads_meta() as $tid => $meta) {
    if (is_array($meta) && (string) ($meta['hs_conversation_id'] ?? '') === '701') {
        $failedTid = (string) $tid;
    }
}
$failedMeta = $failedTid !== '' ? jeab_inkorg_thread_meta($failedTid) : [];
mhc(empty($failed['ok']) && in_array('offert', (array) ($failed['errors'] ?? []), true), 'misslyckad offert syns på planen');
mhc(!$patched, 'Help Scout stängs inte när offerten inte gick ut');
mhc($failedTid !== '' && (string) ($failedMeta['status'] ?? '') !== 'closed', 'ärendet ligger kvar öppet');

$mailOk = true;
$writes = [];
$GLOBALS['_JEAB_MALMO_HS_FIXTURES'] = [
    mhc_thread([
        'id' => 702,
        'subject' => '[batteri] Offerten går',
        'status' => 'active',
        'modifiedAt' => '2026-10-18T09:00:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '9702', 'email' => 'ok.kund@brevlada.se', 'first' => 'Ok', 'last' => 'Kund'],
    ], [
        mhc_customer('702', 'ok.kund@brevlada.se', '[batteri] Jag vill köpa ett batteri.'),
    ]),
];
$sent = jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'limit' => 5]);
$closedWrite = false;
foreach ($writes as $write) {
    if (($write[0] ?? '') === 'PATCH' && ($write[1] ?? '') === 'conversations/702') {
        $closedWrite = true;
    }
}
$okTid = '';
foreach (jeab_inkorg_threads_meta() as $tid => $meta) {
    if (is_array($meta) && (string) ($meta['hs_conversation_id'] ?? '') === '702') {
        $okTid = (string) $tid;
    }
}
$okMeta = $okTid !== '' ? jeab_inkorg_thread_meta($okTid) : [];
mhc(!empty($sent['ok']) && $closedWrite, 'offert och påminnelse stänger Help Scout');
mhc((string) ($okMeta['status'] ?? '') === 'closed', 'ärendet stängs lokalt när fyllnaden är klar');

echo "malmo fill close: ok\n";
