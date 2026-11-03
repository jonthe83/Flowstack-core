<?php

declare(strict_types=1);

/**
 * jeab_auth_match verifierar kandidaten från de 9 första tecknen.
 * En redan godkänd session kör inte bcrypt igen.
 * Sam utan L/W är ingen person.
 */

$tmp = sys_get_temp_dir() . '/jeab-auth-fast-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');

$people = [
    'SamL' => 'SamL',
    'SamW' => 'SamW',
    'Elin' => 'Elin',
    'Roberto' => 'Roberto',
    'Niman' => 'Niman',
    'Kim' => 'Kim',
];
$codes = [];
$entries = [];
foreach ($people as $id => $prefix) {
    $codes[$id] = $prefix . 'SECRET-' . bin2hex(random_bytes(3));
    $entries[] = [
        'email' => strtolower($id) . '@example.test',
        'hash' => password_hash($codes[$id], PASSWORD_DEFAULT),
    ];
}
$codes['ambiguous'] = 'SamSECRET-' . bin2hex(random_bytes(3));
$entries[] = ['hash' => password_hash($codes['ambiguous'], PASSWORD_DEFAULT)];
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => $entries,
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'passwords_file' => $tmp . '/passwords.json',
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
jeab_auth_data(true);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$fail = 0;
function af(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? 'OK  ' : 'FAIL ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

af(jeab_person_by_code_prefix($codes['ambiguous']) === null, 'Sam utan L/W är ingen person');
af((jeab_person_by_code_prefix($codes['SamL'])['id'] ?? '') === 'SamL', 'SamL är Sam L');
af(jeab_code_head($codes['SamL']) === 'SamL', 'huvudet är nio tecken');
af(!str_contains(jeab_code_head($codes['SamL']), 'SECRET'), 'resten av koden ingår inte i huvudet');

$GLOBALS['_JEAB_AUTH_VERIFY_COUNT'] = 0;
unset($GLOBALS['_JEAB_AUTH_MATCH_FAST'], $GLOBALS['_JEAB_AUTH_HEAD_FP']);
$_SESSION = [];
$first = jeab_auth_match($codes['Elin']);
$afterFirst = jeab_auth_verify_count();
af(is_array($first) && $afterFirst >= 1 && $afterFirst <= count($entries), 'första träffen verifierar, fick ' . $afterFirst);
$second = jeab_auth_match($codes['Elin']);
af(is_array($second) && jeab_auth_verify_count() === $afterFirst, 'samma request kör inte bcrypt igen');

unset($GLOBALS['_JEAB_AUTH_MATCH_FAST']);
$_SESSION = [];
jeab_code_people_remember($codes['Elin'], (string) $first['hash'], null);
$beforeHead = jeab_auth_verify_count();
$third = jeab_auth_match($codes['Elin']);
af(is_array($third) && jeab_auth_verify_count() === $beforeHead + 1, 'sparat huvud verifierar en kandidat, fick ' . (jeab_auth_verify_count() - $beforeHead));

$amb = jeab_auth_match($codes['ambiguous']);
af(is_array($amb), 'tvetydig kod matchar fortfarande en rad');
af(jeab_person_from_login($codes['ambiguous'], '') === null, 'tvetydig kod blir ingen person');
$session = json_encode($_SESSION);
af(is_string($session) && !str_contains($session, $codes['Elin']) && !str_contains($session, 'SECRET'), 'sessionen innehåller inte koden');
af(!str_contains($session, '$2y$'), 'sessionen innehåller inte hashen');

echo $fail > 0 ? "FAIL {$fail}\n" : "ALLA OK\n";
exit($fail > 0 ? 1 : 0);
