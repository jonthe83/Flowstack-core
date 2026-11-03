<?php

declare(strict_types=1);

/**
 * Hämta säck med den lata bootstrapen som index.php kör live.
 *
 * Felet: p=sack laddade bara reminders.php. Sökningen föll med fatal error
 * (jeab_flag_explicit saknas i contacts_sole.php), så förslagsrutan stängdes tyst.
 * sack.send saknade sms.php/inkorg*, så utskicket svarade "Graph-utskick saknas".
 * CLI-tester laddar allt och såg det inte. Påhittad kund, bara tmp-data, inget mejl.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$php = getenv('PHP_BINARY') ?: PHP_BINARY;

function sk_fail(string $m): void
{
    fwrite(STDERR, "FAIL {$m}\n");
    exit(1);
}

/** @return array{0:string,1:string} */
function sk_run(string $php, string $root, string $mode): array
{
    $tmp = sys_get_temp_dir() . '/jeab-sack-lazy-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0775, true);
    file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
        'timezone' => 'Europe/Stockholm',
        'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
        'kontakter' => ['lookup_provider' => 'off'],
    ], true) . ";\n");
    $send = $mode === 'send';
    $code = '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
        . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($tmp, true) . ';'
        . '$GLOBALS["_JEAB_PUBLIC_DATA_DIR"]=' . var_export($tmp, true) . ';'
        . 'putenv("JEAB_DISABLE_SQLITE=0"); putenv("XAI_API_KEY");'
        . '$_SERVER["REQUEST_METHOD"]=' . var_export($send ? 'POST' : 'GET', true) . ';'
        . '$_GET=' . var_export($send ? [] : ['p' => 'sack', 'intent' => 'search', 'q' => 'Prov'], true) . ';'
        . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
        . 'jeab_bootstrap_load_page_modules();'
        . ($send
            ? 'jeab_bootstrap_load_action_modules("sack.send");'
              . '$GLOBALS["JEAB_GRAPH_SEND"]=static function(...$a){ echo "GRAPH_CALLED\n"; return ["ok"=>true,"message_id"=>"t"]; };'
              . '$GLOBALS["JEAB_SYSTEM_MAIL"]=static function(...$a){ echo "GRAPH_CALLED\n"; return ["ok"=>true]; };'
              . '$r=jeab_sack_send(["namn"=>"Anna Prov","adress"=>"Provgatan 1","ort"=>"Märsta","telefon"=>"0701234567"]);'
              . 'echo "RESULT ", json_encode($r, JSON_UNESCAPED_UNICODE), "\n";'
            : '$s=jeab_store();'
              . '$s->insertReminder(["title"=>"x","customer_name"=>"Anna Prov","address"=>"Provgatan 1, 19592 Märsta","company"=>jeab_selected_company(),"due_at"=>date("Y-m-d H:i:s")]);'
              . '$h=jeab_search_reminders_for_sack($s,"Prov",null);'
              . 'echo "HITS ", count($h), "\n";');
    $proc = proc_open([$php, '-d', 'display_errors=stderr', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($proc)) {
        sk_fail('kunde inte starta php');
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return [$out, $err];
}

[$out, $err] = sk_run($php, $root, 'search');
if (str_contains($err, 'Fatal') || !preg_match('/HITS (\d+)/', $out, $m) || (int) $m[1] < 1) {
    sk_fail('säck-sökningen ska ge träff utan fatal error under lat bootstrap: ' . trim($out . ' ' . $err));
}
echo "OK  säck-sökningen ger träff under lat bootstrap\n";

[$out, $err] = sk_run($php, $root, 'send');
if (str_contains($err, 'Fatal') || !str_contains($out, 'GRAPH_CALLED') || str_contains($out, 'saknas')) {
    sk_fail('sack.send ska nå utskicket under lat bootstrap: ' . trim($out . ' ' . $err));
}
echo "OK  sack.send når utskicket under lat bootstrap\n";
