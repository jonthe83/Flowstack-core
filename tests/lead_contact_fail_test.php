<?php

declare(strict_types=1);

/**
 * Lead som inte får en kontakt ska synas: fellogg, bugg-webhook en gång,
 * en påminnelse till ärendets säljare, inget till kunden. Omförsök dubblerar inte.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-lead-contact-fail-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
mkdir($tmp . '/public', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['_JEAB_WEBHOOK_SYNC'] = true;
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_MAILS'] = 0;
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;

    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g', 'message_id' => 's@x'];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (): array {
    $GLOBALS['JEAB_MAILS']++;

    return ['ok' => true, 'path' => 'mock', 'id' => 'm'];
};
putenv('JEAB_DISABLE_SQLITE=0');
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
putenv('CONTACTS_SOLE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
file_put_contents($tmp . '/secrets/einstein-bug-webhook.json', json_encode([
    'url' => 'https://example.com/jeab-bug',
    'key' => 'test-bug-key',
], JSON_UNESCAPED_SLASHES));
$GLOBALS['_JEAB_EINSTEIN_SECRET'] = ['url' => 'https://example.com/jeab-bug', 'key' => 'test-bug-key'];

require dirname(__DIR__) . '/src/bootstrap.php';

function lcf(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function lcf_log_blob(): string
{
    $blob = '';
    $path = function_exists('jeab_app_errors_path') ? jeab_app_errors_path() : '';
    if ($path !== '' && is_file($path)) {
        $blob .= (string) file_get_contents($path);
    }
    $pdo = function_exists('jeab_log_pdo_raw') ? jeab_log_pdo_raw() : null;
    if (!$pdo instanceof PDO && function_exists('jeab_log_pdo')) {
        try {
            $pdo = jeab_log_pdo();
        } catch (Throwable) {
            $pdo = null;
        }
    }
    if ($pdo instanceof PDO) {
        try {
            $rows = $pdo->query("SELECT payload FROM log_lines WHERE kind = 'app_error'")->fetchAll(PDO::FETCH_COLUMN);
            foreach (is_array($rows) ? $rows : [] as $row) {
                $blob .= "\n" . (string) $row;
            }
        } catch (Throwable) {
        }
    }

    return $blob;
}

function lcf_fail_reminders(string $tid): array
{
    $out = [];
    $store = jeab_store();
    if (!$store instanceof JeabPdoStore) {
        return $out;
    }
    $stmt = $store->pdo()->prepare('SELECT * FROM reminders WHERE thread_id = ? AND id > 0 ORDER BY id ASC');
    $stmt->execute([$tid]);
    $rows = $stmt->fetchAll();
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $hydrated = jeab_hydrate_reminder($row);
        if (jeab_reminder_is_contact_fail($hydrated)) {
            $out[] = $hydrated;
        }
    }

    return $out;
}

function lcf_bug_hooks(string $tid): array
{
    $out = [];
    foreach ($GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? [] as $hook) {
        if (!is_array($hook)) {
            continue;
        }
        $message = (string) ($hook['message'] ?? '');
        if (str_contains($message, 'kontakt kunde inte skapas') && str_contains($message, $tid)) {
            $out[] = $hook;
        }
    }

    return $out;
}

$secretEmail = 'pii-secret-lead@example.test';
$secretPhone = '0701234567';
$tid = 'ink_' . bin2hex(random_bytes(8));
jeab_inkorg_thread_write($tid, static function (array $t): array {
    $t['status'] = 'open';
    $t['assignee'] = 'Elin';
    $t['brand'] = 'jarfalla';
    $t['created_at'] = $t['created_at'] ?? date('c');
    $t['updated_at'] = date('c');

    return $t;
});
$now = jeab_now();
$sales = jeab_store()->insertReminder([
    'title' => 'Test Kund – Batteri',
    'customer_name' => 'Test Kund',
    'email' => 'sales.kept@example.test',
    'phone' => null,
    'address' => 'Testgatan 1',
    'assignees' => ['Elin'],
    'company' => 'jarfalla',
    'note' => 'Köpt lead',
    'due_at' => jeab_today_start(),
    'thread_id' => $tid,
    'created_at' => $now,
    'updated_at' => $now,
    'tags' => [],
]);
$salesId = (int) ($sales['id'] ?? 0);
lcf($salesId > 0, 'säljpåminnelsen finns innan felet');

$hint = [
    'name' => 'Test Kund',
    'email' => $secretEmail,
    'phone' => $secretPhone,
    'address' => 'Testgatan 1',
    'postal_code' => '17760',
    'city' => 'Järfälla',
    'company' => 'jarfalla',
    'thread_id' => $tid,
    'require_postal' => true,
];
// Registret är SQL. En okskrivbar kontakter.json stoppar inte längre sparningen.
// Triggern ger samma fel som JSON-filen: insättningen kastar, upsert lämnar null.
$lcfPdo = jeab_store()->pdo();
$lcfPdo->exec("CREATE TRIGGER lcf_block_contact_insert BEFORE INSERT ON contacts BEGIN SELECT RAISE(ABORT, 'Kunde inte spara kontakten.'); END");
try {
    $first = jeab_lead_sole_upsert($hint);
    $second = jeab_lead_sole_upsert($hint);
} finally {
    $lcfPdo->exec('DROP TRIGGER IF EXISTS lcf_block_contact_insert');
}

lcf($first === null && $second === null, 'misslyckad kontakt returnerar null och kastar inte');
$blob = lcf_log_blob();
lcf(str_contains($blob, 'kontakt kunde inte skapas'), 'felloggen innehåller kontakt kunde inte skapas');
lcf(str_contains($blob, 'thread=' . $tid), 'felloggen innehåller tråd-id');
lcf(str_contains($blob, 'PDOException'), 'felloggen innehåller undantagsklassen');
lcf(str_contains($blob, 'Kunde inte spara kontakten'), 'felloggen innehåller orsaken');
lcf(!str_contains($blob, $secretEmail) && !str_contains($blob, $secretPhone), 'felloggen saknar mejl och telefon');

$hooks = lcf_bug_hooks($tid);
lcf(count($hooks) === 1, 'bugg-webhook anropas en gång vid omförsök');
$hookMessage = (string) ($hooks[0]['message'] ?? '');
lcf(
    str_contains($hookMessage, 'kontakt kunde inte skapas')
    && str_contains($hookMessage, $tid)
    && str_contains($hookMessage, 'PDOException')
    && !str_contains($hookMessage, $secretEmail)
    && !str_contains($hookMessage, $secretPhone),
    'webhooken har tråd och klass utan kunduppgifter'
);
lcf(($hooks[0]['type'] ?? '') === 'fellogg_error', 'webhooken är den befintliga fellogg-typen');

$fails = lcf_fail_reminders($tid);
lcf(count($fails) === 1, 'en påminnelse skapas och omförsök dubblerar inte');
$fail = $fails[0];
lcf((int) ($fail['id'] ?? 0) !== $salesId, 'felpåminnelsen är inte säljpåminnelsen');
lcf(trim((string) ($fail['thread_id'] ?? '')) === $tid, 'påminnelsen är kopplad till ärendet');
lcf(str_contains((string) ($fail['assignee'] ?? ''), 'Elin'), 'påminnelsen ligger hos ärendets säljare');
$failNote = (string) ($fail['note'] ?? '');
lcf(
    str_contains($failNote, 'Kontakten kunde inte skapas')
    && str_contains($failNote, 'manuellt i Kontakter'),
    'påminnelsen säger att kontakten ska läggas in manuellt'
);
lcf(trim((string) ($fail['email'] ?? '')) === '' && trim((string) ($fail['phone'] ?? '')) === '', 'påminnelsen har ingen kundadress');
lcf($GLOBALS['_SENT'] === [] && (int) $GLOBALS['JEAB_MAILS'] === 0, 'inget skickas till kunden');

$found = jeab_lead_find_reminder($tid, '');
lcf(is_array($found) && (int) ($found['id'] ?? 0) === $salesId, 'leadets påminnelse är fortfarande säljpåminnelsen');
$byThread = jeab_reminder_for_thread($tid);
lcf(is_array($byThread) && (int) ($byThread['id'] ?? 0) === $salesId, 'trådens påminnelse för offert är inte felpåminnelsen');

$made = jeab_lead_sole_upsert([
    'name' => 'Lyckad Kund',
    'email' => 'lyckad.kund@example.test',
    'phone' => '0701112233',
    'address' => 'Lyckadgatan 2',
    'postal_code' => '17760',
    'city' => 'Järfälla',
    'company' => 'jarfalla',
    'thread_id' => $tid,
    'require_postal' => true,
]);
lcf(is_array($made) && trim((string) ($made['id'] ?? '')) !== '', 'en sparad kontakt returneras som tidigare');
lcf(count(lcf_fail_reminders($tid)) === 1, 'lyckad sparning skapar ingen extra felpåminnelse');
lcf(count(lcf_bug_hooks($tid)) === 1, 'lyckad sparning skickar ingen ny webhook');

$nullTid = 'ink_' . bin2hex(random_bytes(8));
jeab_inkorg_thread_write($nullTid, static function (array $t): array {
    $t['status'] = 'open';
    $t['assignee'] = 'Roberto';
    $t['brand'] = 'jarfalla';
    $t['created_at'] = $t['created_at'] ?? date('c');
    $t['updated_at'] = date('c');

    return $t;
});
$nullEmail = 'null-path-secret@example.test';
$nullRow = jeab_lead_sole_upsert([
    'name' => 'Onlineutbildning',
    'email' => $nullEmail,
    'phone' => '0709998877',
    'thread_id' => $nullTid,
]);
lcf($nullRow === null, 'null från find-or-create lämnar returtypen');
$nullBlob = lcf_log_blob();
lcf(str_contains($nullBlob, 'kontakt kunde inte skapas') && str_contains($nullBlob, 'thread=' . $nullTid), 'null-vägen skrivs i felloggen');
lcf(!str_contains($nullBlob, $nullEmail), 'null-vägens logg saknar mejlen');
lcf(count(lcf_bug_hooks($nullTid)) === 1, 'null-vägen postar webhooken en gång');
lcf(count(lcf_fail_reminders($nullTid)) === 1, 'null-vägen skapar en påminnelse');
$nullAgain = jeab_lead_sole_upsert([
    'name' => 'Onlineutbildning',
    'email' => $nullEmail,
    'phone' => '0709998877',
    'thread_id' => $nullTid,
]);
lcf($nullAgain === null && count(lcf_fail_reminders($nullTid)) === 1, 'null-vägens omförsök dubblerar inte påminnelsen');
lcf(count(lcf_bug_hooks($nullTid)) === 1, 'null-vägens omförsök postar inte webhooken igen');
lcf($GLOBALS['_SENT'] === [] && (int) $GLOBALS['JEAB_MAILS'] === 0, 'null-vägen skickar inget till kunden');

$hooksBeforeOff = count($GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? []);
$GLOBALS['_JEAB_CONTACTS_SOLE'] = false;
$off = jeab_lead_sole_upsert([
    'name' => 'Avstängd Kund',
    'email' => 'avstangd@example.test',
    'thread_id' => $tid,
]);
lcf($off === null && count($GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? []) === $hooksBeforeOff, 'avstängd kontaktflagga loggar inte');

echo "lead_contact_fail_test ok\n";
