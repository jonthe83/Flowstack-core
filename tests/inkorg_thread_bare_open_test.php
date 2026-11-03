<?php

declare(strict_types=1);

/**
 * Support/Oklassad utan kontakt, produkt och påminnelse ska öppnas som ix_part=thread.
 * Saknad påminnelse får inte läsa hela reminder-tabellen. JS visar fel och försök igen.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-ixbare-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';

function bare(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$bag = jeab_inkorg_thread_view_bag([
    'id' => 'support4',
    'last' => null,
    'first' => null,
    'messages' => null,
    'drafts' => null,
    'tags' => null,
    'contact_id' => null,
    'case_class' => null,
]);
bare($bag['subject'] === '(ingen rubrik)' && $bag['tags'] === [] && $bag['messages'] === [] && $bag['drafts'] === [], 'null kontakt/produkt/påminnelse blir tomma fält');
$tagged = $bag;
$tagged['tags'] = $bag['tags'];
try {
    bare(in_array('support', $tagged['tags'], true) === false, 'in_array på tomma taggar kastar inte');
} catch (Throwable $e) {
    bare(false, 'taggar: ' . $e->getMessage());
}
$named = jeab_inkorg_thread_view_bag([
    'first' => ['subject' => 'Test Support4'],
    'last' => [],
    'messages' => [null, ['from' => '', 'body' => 'Hej', 'labels' => ['type' => 'Oklassad']]],
    'drafts' => [null],
    'tags' => null,
]);
bare($named['subject'] === 'Test Support4' && count($named['messages']) === 1 && $named['drafts'] === [], 'rubrik finns och null-rader hoppas över');
$cust = jeab_inkorg_customer(['id' => 'support4', 'messages' => null, 'first' => null, 'last' => null], []);
bare(is_array($cust) && ($cust['email'] ?? null) === '', 'kundpanelen tål messages=null');

$pdo = jeab_pdo_connect_sqlite();
bare(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
bare($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');
$store->upsertMessage('support4m', [
    'id' => 'support4m',
    'thread_id' => 'support4',
    'from' => '',
    'from_name' => '',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Test Support4',
    'body' => 'Ett oklassat supportärende utan kontakt.',
    'received_at' => '2026-10-22T08:00:00+00:00',
    'source' => 'graph-jarfalla',
    'labels' => ['type' => 'Oklassad'],
]);
$store->upsertThread('support4', [
    'status' => 'open',
    'tags' => [],
    'assignee' => '',
    'read' => [],
    'company' => 'jarfalla',
    'contact_id' => '',
    'updated_at' => '2026-10-22T08:00:00+00:00',
]);

$app = jeab_store();
for ($i = 0; $i < 12; $i++) {
    jeab_create_reminder($app, 'Brus ' . $i, '2026-10-22 09:00:00', str_repeat('anteckning utan ärende. ', 40), null, [
        'company' => 'jarfalla',
        'who' => 'Roberto',
    ]);
}
$linked = jeab_create_reminder($app, 'Notlänk', '2026-10-22 09:00:00', 'Öppna id=notelink i inkorgen', null, [
    'company' => 'jarfalla',
    'who' => 'Roberto',
]);
$app->saveReminder((int) $linked['id'], ['thread_id' => '']);
unset($GLOBALS['_JEAB_INKORG_THREAD_REM']);
jeab_sql_stats_reset();
$found = jeab_inkorg_thread_reminder(['id' => 'notelink', 'contact_id' => '', 'messages' => [], 'case_class' => null]);
$log = implode("\n", jeab_sql_stats()['log']);
bare(is_array($found) && (int) ($found['id'] ?? 0) === (int) $linked['id'], 'anteckningens ärende-id hittas utan full tabelläsning');
bare(!str_contains($log, 'list_id') && str_contains($log, 'note LIKE'), 'påminnelseslaget söker note, inte listReminders');
unset($GLOBALS['_JEAB_INKORG_THREAD_REM']);
jeab_sql_stats_reset();
$none = jeab_inkorg_thread_reminder(['id' => 'support4', 'contact_id' => '', 'messages' => [], 'case_class' => null, 'tags' => null]);
$noneLog = implode("\n", jeab_sql_stats()['log']);
bare($none === null && !str_contains($noneLog, 'list_id'), 'ärende utan påminnelse svarar null utan att läsa alla rader');

$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
$start = strpos($page, "if (kind === 'thread')");
$end = strpos($page, 'var req = new URL(url.href);', $start === false ? 0 : $start);
$slice = ($start === false || $end === false) ? '' : substr($page, $start, $end - $start);
bare(str_contains($page, 'function ixThreadFail') && str_contains($page, 'data-ix-retry') && str_contains($page, 'Ärendet kunde inte öppnas.'), 'fel i tråden visar text och Försök igen');
bare(str_contains($page, ', 10000)'), 'trådhämtningen avbryts efter 10 s');
bare($slice !== '' && str_contains($slice, 'ixThreadFail') && !str_contains($slice, 'location.href') && !str_contains($slice, 'location.reload'), 'trådfel byter inte sida');
$js = substr($page, (int) strpos($page, "<script>\n") + 8);
$js = substr($js, 0, (int) strpos($js, '</script>'));
$jsFile = $tmp . '/inkorg-inline.js';
file_put_contents($jsFile, $js);
$node = proc_open(['node', '--check', $jsFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $npipes);
$nodeErr = stream_get_contents($npipes[2]);
fclose($npipes[1]);
fclose($npipes[2]);
bare(proc_close($node) === 0, 'inkorgens skript är giltigt JS ' . trim((string) $nodeErr));

$runner = $tmp . '/child.php';
file_put_contents($runner, <<<'PHP'
<?php
$tmp = getenv('JEAB_BARE_TMP');
$root = getenv('JEAB_ROOT');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => 'support4', 'ix_part' => 'thread'];
require $root . '/src/pages/inkorg.php';
PHP);
$proc = proc_open(
    [PHP_BINARY, $runner],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    null,
    ['JEAB_BARE_TMP' => $tmp, 'JEAB_ROOT' => $root, 'JEAB_DISABLE_SQLITE' => '0']
);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);
$json = json_decode((string) $out, true);
bare($code === 0 && trim((string) $err) === '', 'ix_part=thread skriver inga varningar ' . trim((string) $err));
bare($code === 0 && is_array($json) && ($json['ok'] ?? false) === true, 'ix_part=thread svarar JSON');
$html = (string) ($json['html'] ?? '');
bare(str_contains($html, 'Test Support4') && str_contains($html, 'id="ix-conv"') && str_contains($html, 'Oklassad'), 'ärendet ritas utan kontakt, produkt och påminnelse');
bare(!str_contains($html, 'Produkter'), 'tom produkt rad skrivs inte ut');
echo "inkorg bare open: ok\n";
