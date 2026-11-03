<?php

declare(strict_types=1);

/**
 * Radera ordern direkt och Ångra efteråt: ordrar.delete sparar en kopia i SQL
 * (order_delete_undo), ordrar.restore lägger tillbaka ordern och tar bort
 * raderad-markeringen. Påhittad order, bara tmp-data, inget utskick.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-del-restore-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
jeab_ordrar_external_live(false);

function dr_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

dr_ok(function_exists('jeab_ordrar_restore'), 'ordrar.restore finns (jeab_ordrar_restore)');

$store = jeab_store();
$rem = jeab_create_reminder($store, 'Ada Ångra', '2026-11-02 00:00:00', 'laddbox', null, [
    'customer' => 'Ada Ångra',
    'company' => 'jarfalla',
    'address' => 'Ångragatan 1, Järfälla',
]);
$rid = (int) ($rem['id'] ?? 0);
dr_ok($rid > 0, 'påminnelsen finns');
jeab_update_reminder($store, $rid, ['tags' => ['vunnen']]);
jeab_purchase_put([
    'reminder_id' => $rid,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Ada Ångra',
    'address' => 'Ångragatan 1, Järfälla',
    'fortnox_order' => '77001',
]);
dr_ok(is_array(jeab_purchase_get($rid)), 'ordern finns');

jeab_ordrar_delete(['id' => $rid], $store);
dr_ok(jeab_purchase_get($rid) === null, 'radera tar bort ordern direkt');
dr_ok(jeab_ordrar_deleted_blocks($rid), 'ordern är markerad som raderad');
$tagsAfterDelete = jeab_parse_tags((jeab_get_reminder($store, $rid) ?? [])['tags'] ?? []);
dr_ok(!in_array('vunnen', $tagsAfterDelete, true), 'radera tar bort vunnen-taggen');

$restored = jeab_ordrar_restore(['id' => $rid], $store);
$back = jeab_purchase_get($rid);
dr_ok(is_array($back) && (string) ($back['fortnox_order'] ?? '') === '77001' && (string) ($back['kind'] ?? '') === 'laddbox', 'Ångra lägger tillbaka ordern med sina uppgifter');
dr_ok((int) ($restored['reminder_id'] ?? 0) === $rid, 'restore returnerar ordern');
dr_ok(!jeab_ordrar_deleted_blocks($rid), 'raderad-markeringen är borttagen');
$tags = jeab_parse_tags((jeab_get_reminder($store, $rid) ?? [])['tags'] ?? []);
dr_ok(in_array('vunnen', $tags, true), 'vunnen-taggen är tillbaka');

$threw = '';
try {
    jeab_ordrar_restore(['id' => $rid], $store);
} catch (InvalidArgumentException $e) {
    $threw = $e->getMessage();
}
dr_ok($threw === 'Ordern finns redan.', 'Ångra två gånger gör inget');

jeab_ordrar_delete(['id' => $rid], $store);
$pdo = jeab_ordrar_delete_undo_pdo($store);
$pdo->exec("UPDATE order_delete_undo SET created_at = '2000-01-01 00:00:00' WHERE restored_at IS NULL");
$threw = '';
try {
    jeab_ordrar_restore(['id' => $rid], $store);
} catch (InvalidArgumentException $e) {
    $threw = $e->getMessage();
}
dr_ok($threw === 'Raderingen går inte längre att ångra.' && jeab_purchase_get($rid) === null, 'gammal radering går inte att ångra');

$index = (string) file_get_contents($root . '/index.php');
dr_ok(str_contains($index, "case 'ordrar.restore':") && str_contains($index, 'jeab_ordrar_restore($_POST'), 'index.php hanterar ordrar.restore');

// Lazy bootstrap (som live): ordrar.restore ska finnas för ordrar-sidans actions.
$php = getenv('PHP_BINARY') ?: PHP_BINARY;
$code = '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
    . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($tmp, true) . ';'
    . '$_SERVER["REQUEST_METHOD"]="POST"; $_GET=["p"=>"ordrar"]; $_POST=["action"=>"ordrar.restore","id"=>"1"];'
    . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
    . 'jeab_bootstrap_load_page_modules();'
    . 'if (function_exists("jeab_bootstrap_load_action_modules")) { jeab_bootstrap_load_action_modules("ordrar.restore"); }'
    . 'echo function_exists("jeab_ordrar_restore") ? "HAS\n" : "MISSING\n";';
$proc = proc_open([$php, '-d', 'display_errors=stderr', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
$out = (string) stream_get_contents($pipes[1]);
$err = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
dr_ok(str_contains($out, 'HAS') && !str_contains($err, 'Fatal'), 'ordrar.restore laddas under lat bootstrap ' . trim(substr($out . $err, 0, 200)));

exec('rm -rf ' . escapeshellarg($tmp));
echo "ordrar delete restore: ok\n";
