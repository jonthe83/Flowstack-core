<?php

declare(strict_types=1);

/**
 * Hämta säck: förslagen söker påminnelser, kontakter och ordrar under den lata
 * bootstrapen (som live) och visar typ per träff. Fel visas i rutan i stället
 * för att den stängs tyst. Påhittade kunder, bara tmp-data, inget mejl skickas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$php = getenv('PHP_BINARY') ?: PHP_BINARY;
$fails = 0;

function ss_check(bool $ok, string $m): void
{
    global $fails;
    if ($ok) {
        echo "OK  {$m}\n";
        return;
    }
    $fails++;
    fwrite(STDERR, "FAIL {$m}\n");
}

$tmp = sys_get_temp_dir() . '/jeab-sack-src-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");

$code = '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
    . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($tmp, true) . ';'
    . '$GLOBALS["_JEAB_PUBLIC_DATA_DIR"]=' . var_export($tmp, true) . ';'
    . 'putenv("JEAB_DISABLE_SQLITE=0"); putenv("XAI_API_KEY");'
    . '$_SERVER["REQUEST_METHOD"]="GET"; $_GET=["p"=>"sack","intent"=>"search","q"=>"x"];'
    . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
    . 'jeab_bootstrap_load_page_modules();'
    . 'if (!function_exists("jeab_sack_search")) { echo "NOFUNC\n"; exit(0); }'
    . '$s=jeab_store(); $co=jeab_selected_company();'
    . '$s->insertReminder(["title"=>"x","customer_name"=>"Anders Provsson","address"=>"Provgatan 1, 19592 Märsta","phone"=>"0739990001","company"=>$co,"due_at"=>date("Y-m-d H:i:s")]);'
    . 'jeab_ensure_contacts_tables($s->pdo(),"sqlite");'
    . 'jeab_kontakter_mutate(static function(array &$c) use ($co): void { $k=jeab_kontakt_blank(["name"=>"Berit Kontaktprov","email"=>"berit.prov@example.test","phone"=>"0705550099","street"=>"Kontaktprovvägen 3","postal_code"=>"17641","city"=>"Järfälla","company"=>$co]); $c[(string)$k["id"]]=$k; });'
    . 'jeab_purchase_put(["reminder_id"=>4242,"kind"=>"laddbox","company"=>$co,"name"=>"Olle Orderprov","address"=>"Orderprovgatan 9, 17500 Järfälla","phone"=>"0701112299","email"=>"olle.prov@example.test","fortnox_order"=>"88123","steps"=>[]]);'
    . '$out=[]; foreach (["Provsson","berit.prov@example","0705550099","Kontaktprovv","Orderprov","88123"] as $q) { $r=jeab_sack_search($s,$q,null); $out[$q]=["errors"=>$r["errors"],"hits"=>array_map(static fn($h)=>[$h["type"],$h["name"],$h["street"],$h["city"],$h["phone"]],$r["hits"])]; }'
    . 'echo "JSON ", json_encode($out, JSON_UNESCAPED_UNICODE), "\n";';
$proc = proc_open([$php, '-d', 'display_errors=stderr', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
$out = (string) stream_get_contents($pipes[1]);
$err = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

ss_check(!str_contains($err, 'Fatal'), 'ingen fatal error under lat bootstrap ' . trim(substr($err, 0, 300)));
ss_check(!str_contains($out, 'NOFUNC'), 'jeab_sack_search laddas för p=sack');
$res = preg_match('/JSON (.*)/', $out, $m) ? json_decode($m[1], true) : null;
$res = is_array($res) ? $res : [];
$expect = [
    'Provsson' => ['reminder', 'Anders Provsson'],
    'berit.prov@example' => ['contact', 'Berit Kontaktprov'],
    '0705550099' => ['contact', 'Berit Kontaktprov'],
    'Kontaktprovv' => ['contact', 'Berit Kontaktprov'],
    'Orderprov' => ['order', 'Olle Orderprov'],
    '88123' => ['order', 'Olle Orderprov'],
];
foreach ($expect as $q => [$type, $name]) {
    $row = $res[$q] ?? ['errors' => ['saknas'], 'hits' => []];
    $hit = null;
    foreach ($row['hits'] as $h) {
        if ($h[0] === $type && $h[1] === $name) {
            $hit = $h;
        }
    }
    ss_check($row['errors'] === [] && $hit !== null && $hit[2] !== '' && $hit[3] !== '' && $hit[4] !== '', "\"{$q}\" ger {$type}-träff {$name} med adress, ort och telefon");
}

$page = (string) file_get_contents($root . '/src/pages/sack.php');
ss_check(str_contains($page, 'hit.type_label') && str_contains($page, '"typ typ-"'), 'sack.php visar typ per förslag');
ss_check(str_contains($page, 'Sökningen misslyckades') && str_contains($page, 'Inga träffar'), 'sack.php visar fel och tomt svar i rutan i stället för att stänga tyst');
ss_check(str_contains($page, 'id="suggest-telefon"'), 'sack.php söker även från telefonfältet');
$index = (string) file_get_contents($root . '/index.php');
ss_check(str_contains($index, 'jeab_sack_search($store'), 'index.php p=sack&intent=search använder jeab_sack_search');

exec('rm -rf ' . escapeshellarg($tmp));
exit($fails > 0 ? 1 : 0);
