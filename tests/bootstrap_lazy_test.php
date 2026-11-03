<?php

declare(strict_types=1);

/**
 * Lätta sidor parsar inte ordrar, inkorg_auto, contacts_sole eller solkampanj.
 * Påminnelser laddar sin arbetsgrupp, inte ordrar eller inkorg_auto.
 * Sköldens läckstämpel hoppar över flocken.
 */

$root = dirname(__DIR__);
$php = getenv('PHP_BINARY') ?: PHP_BINARY;

function bl_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function bl_ok(string $message): void
{
    echo "OK  {$message}\n";
}

function bl_run(string $php, string $root, string $code): string
{
    $cmd = [$php, '-d', 'display_errors=1', '-r', $code];
    $proc = proc_open($cmd, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $root);
    if (!is_resource($proc)) {
        bl_fail('kunde inte starta php');
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codeExit = proc_close($proc);
    if ($codeExit !== 0) {
        bl_fail('underprocess ' . $codeExit . ' ' . trim($out . "\n" . $err));
    }

    return (string) $out;
}

function bl_has(string $output, string $name): bool
{
    return str_contains($output, '/' . $name) || str_contains($output, '\\' . $name);
}

$tmp = sys_get_temp_dir() . '/jeab-boot-lazy-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    bl_fail('temp');
}
$public = $tmp . '/public';
$private = $tmp . '/private';
mkdir($public, 0775, true);
mkdir($private, 0775, true);

$links = bl_run($php, $root, '$_GET["p"]="links";'
    . '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
    . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($private, true) . ';'
    . '$GLOBALS["_JEAB_PUBLIC_DATA_DIR"]=' . var_export($public, true) . ';'
    . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
    . 'jeab_bootstrap_load_page_modules();'
    . 'require_once ' . var_export($root . '/src/layout.php', true) . ';'
    . '$files=get_included_files();'
    . 'echo implode("\n", $files), "\n";'
    . 'echo "FUN_LINKS ", (function_exists("jeab_public_links")?"1":"0"), "\n";'
    . 'echo "FUN_ORDRAR ", (function_exists("jeab_ordrar_visible")?"1":"0"), "\n";'
    . 'echo "FUN_AUTO ", (function_exists("jeab_auto_intent_text")?"1":"0"), "\n";'
    . 'echo "FUN_SOLE ", (function_exists("jeab_contacts_sole_view_for")?"1":"0"), "\n";'
    . 'echo "FUN_SOL ", (function_exists("jeab_solkampanj_page")?"1":"0"), "\n";'
    . 'echo "FUN_DB ", (function_exists("jeab_pdo") || function_exists("jeab_db")?"1":"0"), "\n";'
    . 'echo "FUN_AUTH ", (function_exists("jeab_gate")?"1":"0"), "\n";'
    . 'echo "FUN_LAYOUT ", (function_exists("jeab_layout")?"1":"0"), "\n";'
    . 'echo "FUN_STORE ", (function_exists("jeab_store")?"1":"0"), "\n";'
    . '$store=null;'
    . 'try { $body=require ' . var_export($root . '/src/pages/links.php', true) . '; } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1);} '
    . 'echo "BODY ", (is_string($body) && str_contains($body, "links-page")?"1":"0"), "\n";'
);
if (bl_has($links, 'ordrar.php')) {
    bl_fail('länkar laddade ordrar.php');
}
if (bl_has($links, 'inkorg_auto.php') || bl_has($links, 'contacts_sole.php') || bl_has($links, 'solkampanj.php') || bl_has($links, 'battery.php')) {
    bl_fail('länkar laddade en tung modul');
}
foreach (['src/db.php', 'src/auth.php', 'src/security.php', 'src/store.php', 'src/layout.php', 'src/links.php'] as $need) {
    if (!bl_has($links, $need)) {
        bl_fail('länkar saknar ' . $need);
    }
}
if (!str_contains($links, 'FUN_LINKS 1') || !str_contains($links, 'FUN_ORDRAR 0') || !str_contains($links, 'FUN_AUTO 0') || !str_contains($links, 'FUN_SOLE 0') || !str_contains($links, 'FUN_SOL 0')) {
    bl_fail('funktionskoll länkar: ' . trim($links));
}
if (!str_contains($links, 'FUN_AUTH 1') || !str_contains($links, 'FUN_LAYOUT 1') || !str_contains($links, 'FUN_STORE 1') || !str_contains($links, 'BODY 1')) {
    bl_fail('kärna eller länksida: ' . trim($links));
}
bl_ok('länkar parsar inte ordrar, inkorg_auto, contacts_sole eller solkampanj');

$offert = bl_run($php, $root, '$_GET["p"]="offert";'
    . '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
    . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($private, true) . ';'
    . '$GLOBALS["_JEAB_PUBLIC_DATA_DIR"]=' . var_export($public, true) . ';'
    . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
    . 'jeab_bootstrap_load_page_modules();'
    . '$files=implode("\n", get_included_files());'
    . 'echo $files, "\n";'
    . 'if (str_contains($files, "battery.php")) { fwrite(STDERR, "battery"); exit(1);} '
    . 'if (str_contains($files, "/ordrar.php") || str_contains($files, "/inkorg_auto.php") || str_contains($files, "/solkampanj.php")) { fwrite(STDERR, "tung"); exit(1);} '
    . '$store=null;'
    . '$body=require ' . var_export($root . '/src/pages/offert.php', true) . ';'
    . 'echo "OFFERT ", (is_string($body) && str_contains($body, "offert-app")?"1":"0"), "\n";'
);
if (!str_contains($offert, 'OFFERT 1')) {
    bl_fail('offert ritades inte');
}
bl_ok('offert ritades utan batteribroschyren och utan ordrar');

$legacy = bl_run($php, $root, '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
    . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($private, true) . ';'
    . '$GLOBALS["_JEAB_PUBLIC_DATA_DIR"]=' . var_export($public, true) . ';'
    . '$_GET["p"]="offertutskick";'
    . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
    . '$list=jeab_bootstrap_legacy_files();'
    . '$auth=array_search("auth.php", $list, true);'
    . '$ut=array_search("offert_utskick.php", $list, true);'
    . 'echo ($auth !== false && $ut === $auth + 1) ? "ORDER1" : "ORDER0", "\n";'
    . 'echo function_exists("jeab_offert_utskick_page") ? "UTSKICK1" : "UTSKICK0", "\n";'
    . 'echo function_exists("jeab_ordrar_visible") ? "ORDRAR1" : "ORDRAR0", "\n";'
    . '$_GET["p"]="links";'
    . 'jeab_offert_utskick_page();'
    . 'echo "EARLY1\n";'
);
if (!str_contains($legacy, 'ORDER1') || !str_contains($legacy, 'UTSKICK1') || !str_contains($legacy, 'ORDRAR1') || !str_contains($legacy, 'EARLY1')) {
    bl_fail('offertutskick saknas i den tunga listan: ' . trim($legacy));
}
bl_ok('tung sida laddar offert_utskick efter auth, och sidan återvänder när p inte är offertutskick');

$notiser = bl_run($php, $root, '$_GET["p"]="notiser";'
    . '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
    . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($private, true) . ';'
    . '$GLOBALS["_JEAB_PUBLIC_DATA_DIR"]=' . var_export($public, true) . ';'
    . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
    . 'jeab_bootstrap_load_page_modules();'
    . '$files=implode("\n", get_included_files());'
    . 'echo $files, "\n";'
    . 'if (str_contains($files, "/ordrar.php") || str_contains($files, "/contacts_sole.php") || str_contains($files, "/solkampanj.php")) { fwrite(STDERR, "tung"); exit(1);} '
    . 'if (!str_contains($files, "/offert_mall.php") || !str_contains($files, "/fortnox.php")) { fwrite(STDERR, "saknar mall eller fortnox"); exit(1);} '
    . 'if (!function_exists("jeab_offert_mall_body") || !class_exists("FortnoxAPI")) { fwrite(STDERR, "funktion"); exit(1);} '
    . '$set=jeab_auto_settings();'
    . 'if (trim((string) ($set["templates"]["sol_malmo"] ?? "")) === "") { fwrite(STDERR, "tom malmösol"); exit(1);} '
    . '$rows=jeab_notiser_catalog();'
    . 'echo "ROWS ", (count($rows) > 0 ? "1" : "0"), "\n";'
    . '$filter=jeab_notiser_filter_from_request();'
    . '$visible=jeab_notiser_filter($rows, $filter);'
    . '$counts=jeab_notiser_counts($rows);'
    . '$body=require ' . var_export($root . '/src/pages/notiser.php', true) . ';'
    . 'echo "NOTIS ", (is_string($body) && str_contains($body, "Notiser till slutkund") ? "1" : "0"), "\n";'
);
if (bl_has($notiser, 'ordrar.php') || bl_has($notiser, 'contacts_sole.php')) {
    bl_fail('notiser laddade en tung modul');
}
if (!str_contains($notiser, 'ROWS 1') || !str_contains($notiser, 'NOTIS 1')) {
    bl_fail('notiser ritades inte: ' . trim($notiser));
}
bl_ok('notiser laddar offertmall och fortnox och ritas utan 500');

$pages = [
    'notiser' => 'jeab_utskick_should_redirect',
    'utskick' => 'jeab_utskick_body',
    'offertutskick' => 'jeab_offert_utskick_page',
];
foreach ($pages as $page => $fn) {
    $hit = bl_run($php, $root, '$_GET["p"]=' . var_export($page, true) . ';'
        . '$_SERVER["REQUEST_METHOD"]="GET";'
        . '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
        . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($private, true) . ';'
        . '$GLOBALS["_JEAB_PUBLIC_DATA_DIR"]=' . var_export($public, true) . ';'
        . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
        . 'jeab_bootstrap_load_page_modules();'
        . 'foreach (["jeab_utskick_should_redirect","jeab_notiser_catalog","jeab_offert_utskick_allowed","jeab_offert_mall_body","jeab_inkorg_quote_backfill"] as $need) {'
        . '  if (!function_exists($need)) { fwrite(STDERR, "saknar ".$need); exit(1);} '
        . '}'
        . 'if (!function_exists(' . var_export($fn, true) . ')) { fwrite(STDERR, "saknar sida"); exit(1);} '
        . 'try { $redir=jeab_utskick_should_redirect(' . var_export($page, true) . '); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1);} '
        . 'echo "REDIR ", ($redir ? "1" : "0"), "\n";'
        . 'if (' . var_export($page, true) . ' !== "offertutskick") {'
        . '  $rows=jeab_notiser_catalog();'
        . '  if ($rows === []) { fwrite(STDERR, "tom katalog"); exit(1);} '
        . '  $body=jeab_utskick_body(' . var_export($page === 'utskick' ? 'alla' : 'notiser', true) . ');'
        . '  if (!is_string($body) || !str_contains($body, "Utskick") || !str_contains($body, "Notiser")) { fwrite(STDERR, "ritning"); exit(1);} '
        . '}'
        . 'echo "PAGE1\n";'
    );
    if (!str_contains($hit, 'PAGE1')) {
        bl_fail($page . ' saknar funktioner: ' . trim($hit));
    }
    if ($page === 'offertutskick' && !str_contains($hit, 'REDIR 1')) {
        bl_fail('offertutskick styrs inte om');
    }
    if ($page === 'notiser' && !str_contains($hit, 'REDIR 1')) {
        bl_fail('notiser styrs inte om');
    }
}
bl_ok('notiser, utskick och offertutskick har funktionerna efter lazy bootstrap');

$offerPost = bl_run($php, $root, '$_GET["p"]="links";'
    . '$_SERVER["REQUEST_METHOD"]="POST";'
    . '$_POST["action"]="offert.backfill_preview";'
    . '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
    . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($private, true) . ';'
    . '$GLOBALS["_JEAB_PUBLIC_DATA_DIR"]=' . var_export($public, true) . ';'
    . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
    . 'jeab_bootstrap_load_page_modules();'
    . 'echo function_exists("jeab_utskick_handle_offer_post") ? "BEFORE1" : "BEFORE0", "\n";'
    . 'jeab_bootstrap_load_action_modules("offert.backfill_preview");'
    . 'echo function_exists("jeab_utskick_handle_offer_post") ? "AFTER1" : "AFTER0", "\n";'
    . 'echo function_exists("jeab_inkorg_quote_backfill") ? "BACK1" : "BACK0", "\n";'
    . 'jeab_bootstrap_load_action_modules("notiser.toggle");'
    . 'echo function_exists("jeab_notiser_set_enabled") ? "NOTIS1" : "NOTIS0", "\n";'
);
if (!str_contains($offerPost, 'BEFORE0') || !str_contains($offerPost, 'AFTER1') || !str_contains($offerPost, 'BACK1') || !str_contains($offerPost, 'NOTIS1')) {
    bl_fail('offert-POST laddar inte utskicksfilerna: ' . trim($offerPost));
}
bl_ok('offert-POST och notis-POST laddar sina moduler på en lätt sida');

$postFile = $private . '/utskick-post.php';
file_put_contents($postFile, '<?php
declare(strict_types=1);
$root = ' . var_export($root, true) . ';
$private = ' . var_export($private, true) . ';
$public = ' . var_export($public, true) . ';
$GLOBALS["_JEAB_BOOT_LAZY"] = true;
$GLOBALS["_JEAB_DATA_DIR"] = $private;
$GLOBALS["_JEAB_PUBLIC_DATA_DIR"] = $public;
$_GET = ["p" => "utskick", "flik" => "offerter"];
$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["SCRIPT_FILENAME"] = $root . "/index.php";
putenv("XAI_API_KEY");
putenv("JEAB_DISABLE_SQLITE=0");
file_put_contents($private . "/config.php", "<?php\nreturn " . var_export([
    "timezone" => "Europe/Stockholm",
    "db" => ["driver" => "sqlite", "sqlite_path" => $private . "/app.sqlite", "json_path" => $private . "/store.json"],
], true) . ";\n");
$GLOBALS["_SENT"] = [];
$GLOBALS["JEAB_GRAPH_SEND"] = static function (array $a): array {
    $GLOBALS["_SENT"][] = [
        "to" => (string) ($a["to"] ?? ""),
        "subject" => (string) ($a["subject"] ?? ""),
    ];
    return ["ok" => true, "from" => "demo@example.test", "graph_id" => "g" . count($GLOBALS["_SENT"]), "conversation_id" => "c", "message_id" => "m" . count($GLOBALS["_SENT"]) . "@x"];
};
require $root . "/src/bootstrap.php";
jeab_bootstrap_load_page_modules();
if (function_exists("jeab_outbound_graph_send")) {
    fwrite(STDERR, "sidan laddade sändfunktionen\n");
    exit(1);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION["jeab_email"] = "agenter@flowstack.example.test";
unset($_COOKIE["jeab_auth"]);
jeab_auto_settings_save([
    "auto_quote_enabled" => 0,
    "grok_decides" => 0,
    "auto_trash_spam" => 1,
    "auto_quote_only_from" => "",
    "auto_send_per_hour" => 500,
    "auto_send_per_day" => 2000,
    "tpl" => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(["key" => "sol_jarfalla", "data" => base64_encode("%PDF-1.4 sol")]);
jeab_auto_pdf_save(["key" => "laddbox_jarfalla", "data" => base64_encode("%PDF-1.4 ladd")]);
$token = jeab_csrf_token();
$_POST = ["_csrf" => $token, "action" => "offert.test_send", "to" => "kund@gmail.com", "dry" => "0"];
jeab_require_csrf();
jeab_bootstrap_load_action_modules("offert.test_send");
if (!function_exists("jeab_outbound_graph_send")) {
    fwrite(STDERR, "offert.test_send laddade inte sändfunktionen\n");
    exit(1);
}
try {
    $send = jeab_offert_utskick_respond("POST", $_POST);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$tos = [];
foreach ($GLOBALS["_SENT"] as $mail) {
    $tos[] = strtolower((string) ($mail["to"] ?? ""));
}
echo "CODE " . (int) ($send["code"] ?? 0) . "\n";
echo "NSENT " . count($tos) . "\n";
echo "TOS " . implode(",", $tos) . "\n";
echo "SUB " . (string) ($GLOBALS["_SENT"][0]["subject"] ?? "") . "\n";
$before = count($tos);
$_POST = ["_csrf" => $token, "action" => "offert.backfill_send", "days" => "30", "limit" => "500", "to" => "kund@gmail.com", "dry" => "0"];
jeab_bootstrap_load_action_modules("offert.backfill_send");
try {
    $back = jeab_offert_utskick_respond("POST", $_POST);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "BACK " . (int) ($back["code"] ?? 0) . "\n";
echo "BACKN " . (int) ($back["result"]["sent"] ?? -1) . "\n";
echo "BACKEXTRA " . (count($GLOBALS["_SENT"]) - $before) . "\n";
');
$postProc = proc_open([$php, '-d', 'display_errors=1', $postFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $postPipes, $root);
if (!is_resource($postProc)) {
    bl_fail('kunde inte starta offert-POST');
}
$postOut = (string) stream_get_contents($postPipes[1]);
$postErr = (string) stream_get_contents($postPipes[2]);
fclose($postPipes[1]);
fclose($postPipes[2]);
$postExit = proc_close($postProc);
if ($postExit !== 0) {
    bl_fail('offert-POST ' . $postExit . ' ' . trim($postOut . "\n" . $postErr));
}
if (!str_contains($postOut, 'CODE 200') || !str_contains($postOut, 'BACK 200')) {
    bl_fail('offert-POST gav inte 200: ' . trim($postOut . "\n" . $postErr));
}
if (!preg_match('/NSENT (\d+)/', $postOut, $sentMatch) || (int) $sentMatch[1] < 1) {
    bl_fail('testkopian skickades inte: ' . trim($postOut));
}
if (!preg_match('/TOS (.+)/', $postOut, $tosMatch)) {
    bl_fail('testkopian saknar mottagare: ' . trim($postOut));
}
$tos = array_values(array_filter(explode(',', trim($tosMatch[1]))));
if ($tos === [] || count(array_unique($tos)) !== 1 || $tos[0] !== 'demo@example.test' || !str_contains($postOut, 'SUB [TEST] ')) {
    bl_fail('testkopian gick inte bara till Sam: ' . trim($postOut));
}
if (!str_contains($postOut, 'BACKN 0') || !str_contains($postOut, 'BACKEXTRA 0') || str_contains($postOut, 'kund@gmail.com')) {
    bl_fail('backfill skickade något den inte skulle: ' . trim($postOut));
}
bl_ok('offert.test_send och backfill_send på p=utskick ger inte 500, och testkopian går bara till Sam');

if (!str_contains($links, 'offert_utskick.php')) {
    bl_ok('länkar laddar inte offertutskick');
} else {
    bl_fail('länkar laddade offert_utskick.php');
}

$heavy = bl_run($php, $root, '$_GET["p"]="reminders";'
    . '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
    . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($private, true) . ';'
    . '$_SERVER["SCRIPT_FILENAME"]=' . var_export($root . '/index.php', true) . ';'
    . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
    . 'jeab_bootstrap_load_page_modules();'
    . '$files=implode("\n", get_included_files());'
    . 'echo $files, "\n";'
    . 'echo function_exists("jeab_ordrar_visible")?"ORDRAR1":"ORDRAR0", "\n";'
    . 'echo function_exists("jeab_contacts_sole_paint_reminder")?"SOLE1":"SOLE0", "\n";'
    . 'echo function_exists("jeab_auto_intent_text")?"AUTO1":"AUTO0", "\n";'
    . 'echo function_exists("jeab_reminder_row")?"ROW1":"ROW0", "\n";'
);
if (bl_has($heavy, 'ordrar.php') || bl_has($heavy, 'inkorg_auto.php')) {
    bl_fail('påminnelser laddade ordrar eller inkorg_auto');
}
if (!str_contains($heavy, 'ORDRAR0') || !str_contains($heavy, 'SOLE1') || !str_contains($heavy, 'AUTO0') || !str_contains($heavy, 'ROW1')) {
    bl_fail('påminnelser saknar arbetsgruppen: ' . trim($heavy));
}
bl_ok('påminnelser laddar kontakter och sole, inte ordrar eller inkorg_auto');

$cli = bl_run($php, $root, '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($private, true) . ';'
    . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
    . 'echo function_exists("jeab_ordrar_visible") && function_exists("jeab_auto_intent_text")?"CLI1":"CLI0", "\n";'
);
if (!str_contains($cli, 'CLI1')) {
    bl_fail('cli-bootstrap saknar moduler');
}
bl_ok('cli och tick laddar modulerna');

if (!defined('JEAB_ROOT')) {
    define('JEAB_ROOT', $root);
}
require_once $root . '/src/shield.php';
$GLOBALS['_JEAB_DATA_DIR'] = $private;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
file_put_contents($public . '/login-throttle.json', '{"n":1}');
if (jeab_shield_leaks_deferred()) {
    bl_fail('stämpel fanns redan');
}
jeab_shield_relocate_leaks_for_page();
if (is_file($public . '/login-throttle.json') || !is_file($private . '/login-throttle.json')) {
    bl_fail('första sidträffen flyttade inte läckan');
}
if (!jeab_shield_leaks_deferred()) {
    bl_fail('stämpel sattes inte');
}
file_put_contents($public . '/app-errors.jsonl', "rad\n");
jeab_shield_relocate_leaks_for_page();
if (!is_file($public . '/app-errors.jsonl')) {
    bl_fail('sidan tog läckan trots stämpel');
}
jeab_shield_relocate_leaks_from_tick();
if (is_file($public . '/app-errors.jsonl') || !is_file($private . '/app-errors.jsonl')) {
    bl_fail('tick flyttade inte läckan');
}
bl_ok('sidan hoppar över läckflytten när stämpeln är satt, tick tar den');

$tickSrc = (string) file_get_contents($root . '/src/tick.php');
if (!str_contains($tickSrc, 'jeab_shield_relocate_leaks_from_tick')) {
    bl_fail('tick anropar inte läckflytten');
}
bl_ok('tick anropar läckflytten');

$index = (string) file_get_contents($root . '/index.php');
if (!str_contains($index, 'jeab_bootstrap_load_page_modules()') || !str_contains($index, 'jeab_bootstrap_load_action_modules($action)')) {
    bl_fail('index laddar inte sidans moduler');
}
if (!str_contains($index, "require_once __DIR__ . '/src/layout.php';")) {
    bl_fail('index kräver layout en gång till och krockar med kärnan');
}
bl_ok('index laddar sidans moduler innan sidan och åtgärden');
