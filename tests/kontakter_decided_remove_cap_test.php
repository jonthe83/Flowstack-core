<?php

declare(strict_types=1);

/**
 * Beslutad radering (sammanslagning, skräpkontakter) har ett tak:
 * högst 50 per anrop och aldrig över 5 % i ett stort register (från 200 rader).
 * Ett litet register får ta bort exakt de beslutade raderna.
 * Över taket tas ingenting bort och felloggen får en rad.
 * Påhittad data. Inget skickas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-kontakter-cap-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
ini_set('error_log', $tmp . '/error.log');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function cap(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** Fyller registret med $n påhittade kontakter. */
function cap_fill(int $n): void
{
    jeab_kontakter_mutate(static function (array &$contacts) use ($n): void {
        foreach (array_keys($contacts) as $id) {
            unset($contacts[$id]);
        }
        for ($i = 1; $i <= $n; $i++) {
            $id = sprintf('kt_cap%04d', $i);
            $contacts[$id] = ['id' => $id, 'name' => 'Test Person ' . $i, 'city' => 'Teststad'];
        }
    }, true);
}

/** Tar bort de $k första kontakterna som beslutad radering. */
function cap_drop(int $k): mixed
{
    return jeab_kontakter_decided_remove(static function (array &$contacts) use ($k): bool {
        foreach (array_slice(array_keys($contacts), 0, $k) as $id) {
            unset($contacts[$id]);
        }

        return true;
    }, 'test.cap');
}

// Regeln.
cap(jeab_kontakter_decided_remove_ok(3, 2), 'litet register får ta bort 2 av 3');
cap(jeab_kontakter_decided_remove_ok(10, 10), 'litet register får ta bort alla beslutade');
cap(!jeab_kontakter_decided_remove_ok(100, 51), 'över 50 per anrop vägras även i litet register');
cap(jeab_kontakter_decided_remove_ok(200, 10), 'stort register: exakt 5 % går');
cap(!jeab_kontakter_decided_remove_ok(200, 11), 'stort register: över 5 % vägras');
cap(jeab_kontakter_decided_remove_ok(5000, 50), '5000 rader: 50 går');
cap(!jeab_kontakter_decided_remove_ok(5000, 51), '5000 rader: 51 vägras');

// Litet register under taket: exakt de beslutade raderna försvinner.
cap_fill(4);
$res = cap_drop(3);
cap($res === true && count(jeab_kontakter_load()) === 1, 'litet register: 3 av 4 tas bort');

// Stort register under taket.
cap_fill(220);
$res = cap_drop(11);
cap($res === true && count(jeab_kontakter_load()) === 209, 'stort register: 11 av 220 tas bort');

// Stort register över taket: ingenting tas bort.
cap_fill(220);
$res = cap_drop(12);
$blocked = $GLOBALS['_JEAB_KONTAKTER_DECIDED_BLOCKED'] ?? null;
cap($res === null && count(jeab_kontakter_load()) === 220, 'över 5 %: ingenting tas bort');
cap(is_array($blocked) && $blocked['removed'] === 12 && $blocked['before'] === 220, 'spärren säger hur många');
$logText = '';
foreach (glob($tmp . '/*.jsonl') ?: [] as $f) {
    $logText .= (string) file_get_contents($f);
}
cap(str_contains($logText, 'Radering över taket avbruten'), 'felloggen får en rad');

// Över 50 per anrop: ingenting tas bort.
cap_fill(120);
$res = cap_drop(51);
cap($res === null && count(jeab_kontakter_load()) === 120, 'över 50: ingenting tas bort');

echo "kontakter_decided_remove_cap_test ok\n";
