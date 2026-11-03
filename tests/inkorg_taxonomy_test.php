<?php

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/jeab-tax-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export(['timezone' => 'Europe/Stockholm', 'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/a.sqlite', 'json_path' => $tmp . '/s.json']], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
$dir = jeab_inkorg_dir();
file_put_contents($dir . '/messages.json', json_encode(['messages' => [
    ['id' => 'a', 'labels' => ['type' => 'Klagomål', 'action' => 'svara', 'priority' => 1]],
    ['id' => 'b', 'labels' => ['type' => 'Säljförsök till oss', 'action' => 'arkivera', 'priority' => 4]],
    ['id' => 'c', 'labels' => ['type' => 'Signering/dokument', 'action' => 'svara', 'priority' => 2]],
]]));
file_put_contents($dir . '/settings.json', json_encode(['intent' => jeab_inkorg_seed_settings()['intent'] . "\n- Prioritet: x\n- Typ Spara ok", 'examples' => [['label' => 'typ:Fråga från befintlig kund']]]));
$r = jeab_inkorg_taxonomy_migrate(false);
$m = json_decode(file_get_contents($dir . '/messages.json'), true)['messages'];
$ok = $r['messages'] === 3 && $m[0]['labels']['type'] === 'Support' && $m[1]['labels']['type'] === 'Skräp' && $m[2]['labels']['type'] === 'Information'
    && jeab_inkorg_settings()['examples'][0]['label'] === 'typ:Support' && !str_contains(jeab_inkorg_settings()['intent'], 'Prioritet:') && is_dir($dir . '/' . $r['backup']);
echo $ok ? "inkorg taxonomy: ok\n" : "FAIL " . json_encode($r) . "\n";
exit($ok ? 0 : 1);
