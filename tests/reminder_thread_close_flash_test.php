<?php

declare(strict_types=1);

/**
 * N-30: skapa påminnelse från tråd stänger ärendet. Flash säger det och
 * ger Öppna igen mot inkorg.thread. Påhittade adresser. Inget skickas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-n30-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/layout.php';

ini_set('session.save_path', $tmp);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['jeab_email'] = 'saljare3@jarfalla.example.test';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'POST';

function n30(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_SENT'] = 0;
$GLOBALS['JEAB_GRAPH_SEND'] = static function (): array {
    $GLOBALS['_SENT']++;

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g-n30', 'message_id' => 'n30@local'];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (): array {
    $GLOBALS['_SENT']++;

    return ['ok' => true];
};

$root = dirname(__DIR__);
$store = jeab_boot();
jeab_set_setting($store, 'inkorg_storage', 'sql');
n30(jeab_inkorg_storage_mode() === 'sql', 'inkorgen ligger i SQL');

$_POST = [];
unset($GLOBALS['_JEAB_REMINDER_CLOSED_THREADS']);
n30(jeab_reminder_create_flash(['id' => 1]) === 'Sparad.', 'utan tråd är flash Sparad.');
n30(jeab_reminder_create_flash(['id' => 1, 'reused' => true]) === 'Befintlig påminnelse.', 'återanvänd utan tråd nämner inte stängt ärende');

$_POST['thread_id'] = 'tid-fake';
n30(jeab_reminder_create_flash(['id' => 1]) === 'Sparad.', 'tråd utan stängning är fortfarande Sparad.');

$GLOBALS['_JEAB_REMINDER_CLOSED_THREADS'] = ['tid-fake'];
n30(jeab_reminder_create_flash(['id' => 1]) === 'Sparad. Ärendet stängt.', 'skapad från tråd visar att ärendet stängts');
n30(jeab_reminder_create_flash(['id' => 1, 'reused' => true]) === 'Befintlig påminnelse. Ärendet stängt.', 'återanvänd från tråd visar att ärendet stängts');
n30(jeab_reminder_create_closed_tid() === 'tid-fake', 'stängd tråd är den som POST:ades');
$extra = jeab_reminder_create_query_extra(['id' => 7]);
n30(($extra['flash'] ?? '') === 'Sparad. Ärendet stängt.' && ($extra['stangd'] ?? '') === 'tid-fake', 'returadressen bär flash och stangd');

n30(jeab_flash_text('Sparad. Ärendet stängt.') === 'Sparad. Ärendet stängt.', 'flash-texten är tillåten');
n30(jeab_flash_text('Befintlig påminnelse. Ärendet stängt.') === 'Befintlig påminnelse. Ärendet stängt.', 'återanvänd stängd-flash är tillåten');

$_GET['stangd'] = 'tid-fake';
$html = jeab_flash_html('Sparad. Ärendet stängt.');
n30(str_contains($html, 'Ärendet stängt.') && str_contains($html, 'Öppna igen'), 'flash-HTML visar Öppna igen');
n30(str_contains($html, 'name="action" value="inkorg.thread"') && str_contains($html, 'name="status" value="open"'), 'Öppna igen går mot inkorg.thread status=open');
n30(str_contains($html, 'name="thread" value="tid-fake"') && str_contains($html, 'class="flash-reopen"'), 'formuläret bär stängd tråd');

$js = (string) file_get_contents($root . '/assets/js/app.js');
n30(str_contains($js, 'closed_thread') && str_contains($js, "undoBtn.textContent = 'Öppna igen'"), 'AJAX-baren byter Ångra till Öppna igen');
n30(str_contains($js, "body.set('action', 'inkorg.thread')") && str_contains($js, "body.set('status', 'open')"), 'AJAX-ångra öppnar ärendet med inkorg.thread');

$index = (string) file_get_contents($root . '/index.php');
n30(str_contains($index, "jeab_reminder_create_flash") && str_contains($index, "payload['closed_thread']"), 'JSON-svaret bär flash och closed_thread');
n30(str_contains($index, 'jeab_reminder_create_query_extra'), 'redirect efter skapa tar med stangd');

$labels = [
    'type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 90,
    'intent' => 'annat', 'offer' => '', 'product' => '', 'question' => '',
];
$box = jeab_graph_mailboxes()['jarfalla'];
$ingested = jeab_inkorg_ingest([
    'message_id' => 'n30-form@open-rem.test',
    'from' => 'ada.n30@example.test',
    'from_name' => 'Ada N30',
    'to' => $box,
    'subject' => 'Formulär n30',
    'body_text' => "Namn: Ada N30\nE-post: ada.n30@example.test\nHej.\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => $box,
    'thread_key' => 'g-n30-form',
    'received_at' => '2026-10-19T08:30:00+02:00',
    'preset_labels' => $labels,
    'test' => true,
]);
$tid = (string) ($ingested['thread_id'] ?? '');
n30($tid !== '' && (jeab_inkorg_thread_meta($tid)['status'] ?? '') === 'open', 'ärendet är öppet innan påminnelsen');

unset($GLOBALS['_JEAB_REMINDER_CLOSED_THREADS']);
$_POST = [
    'title' => 'Ada N30 – Batteri',
    'name' => 'Ada N30',
    'customer' => 'Ada N30',
    'email' => 'ada.n30@example.test',
    'company' => 'jarfalla',
    'thread_id' => $tid,
];
$row = jeab_reminder_create_action($store, $_POST);
n30((int) ($row['id'] ?? 0) > 0, 'påminnelse skapad från tråden');
n30((jeab_inkorg_thread_meta($tid)['status'] ?? '') === 'closed', 'ärendet stängs när påminnelsen skapas från tråden');
n30(jeab_reminder_create_closed_tid() === $tid, 'stängd tråd registreras för flash');
n30(jeab_reminder_create_flash($row) === 'Sparad. Ärendet stängt.', 'flash efter skapa från tråd nämner stängningen');

$reopened = jeab_inkorg_thread_set($tid, 'open', null);
n30((string) ($reopened['status'] ?? jeab_inkorg_thread_meta($tid)['status'] ?? '') === 'open', 'befintlig endpoint öppnar ärendet igen');
n30((int) $GLOBALS['_SENT'] === 0, 'inget mejl skickades');

echo "OK\n";
