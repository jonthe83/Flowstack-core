<?php

declare(strict_types=1);

/**
 * SQL som enda sanning: sms_sent-snabbkollen, dry check=1, explicit sql-flagga på huvudlagret,
 * graph_week och notes utan JSON-reserv, orsak när log_lines-append faller,
 * Inkorgens ärenden/Historik ur JSON och inkorg_contact_link json_only.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-sole-' . bin2hex(random_bytes(4));
mkdir($tmp . '/privat', 0775, true);
mkdir($tmp . '/inkorg', 0775, true);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'people' => [['id' => 'SamL', 'email' => 'demo@example.test', 'name' => 'Sam L', 'company' => 'jarfalla']],
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PRIVAT_DIR'] = $tmp . '/privat';
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
require $root . '/src/bootstrap.php';
foreach (['data_cutover_all', 'log_store', 'notes_store', 'inkorg_contact_link', 'graph_person', 'migrate/sms_sent'] as $f) {
    if (is_file($root . '/src/' . $f . '.php')) {
        require_once $root . '/src/' . $f . '.php';
    }
}

function sole(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

// 1. sms_sent: payload får inte få booking_id som appens egen SQL-rad saknar.
$rows = jeab_migrate_sms_sent_rows(['9' => ['at' => '2026-01-01', 'ok' => true]]);
$payload = json_decode((string) ($rows[0]['payload'] ?? ''), true);
sole((int) ($rows[0]['booking_id'] ?? 0) === 9, 'sms_sent nyckel är boknings-id');
sole(is_array($payload) && !array_key_exists('booking_id', $payload), 'sms_sent payload utan inlagt booking_id');
$appRow = ['at' => '2026-01-01', 'ok' => true];
$fields = jeab_cutover_real_fields(jeab_cutover_row_fields(
    $rows[0],
    ['booking_id' => 9, 'payload' => json_encode($appRow)],
    ['booking_id', 'payload']
));
sole($fields === [], 'sms_sent-raden som appen skrev avviker inte');

// 2. dry check=1 ger flagga, driver och snabbkoll, utan skrivning.
$dry = jeab_cutover_all(['type' => 'reminders', 'check' => 1]);
$row = $dry['types'][0] ?? [];
sole(!empty($dry['dry']) && empty($dry['written']), 'dry skriver inget');
sole(array_key_exists('flag', $row) && (string) $row['flag'] === '', 'dry visar flaggan (tom)');
sole((string) ($row['driver'] ?? '') === 'sqlite' && ($row['ready'] ?? null) === false, 'dry visar driver och att mysql krävs');
sole(is_array($row['check'] ?? null), 'check=1 kör snabbkollen');
$plain = jeab_cutover_all(['type' => 'reminders']);
sole(!array_key_exists('check', $plain['types'][0] ?? []), 'utan check ingen snabbkoll');
sole(!is_file($tmp . '/privat/data-sql-types.txt'), 'ingen flagga skrevs');

// 3. Explicit sql-flagga på huvudlagret: store.json är ingen reserv.
sole(jeab_store_sql_required() === false, 'utan flagga styr drivern (sqlite)');
file_put_contents($tmp . '/privat/data-sql-types.txt', "reminders\ngraph_week\nnotes\n");
unset($GLOBALS['_JEAB_DATA_TYPE_LISTS']);
sole(jeab_store_sql_required() === true, 'sql-flagga på reminders kräver SQL');
$set = jeab_cutover_set_storage(['type' => 'bookings', 'mode' => 'sql']);
sole(!empty($set['ok']) && !empty($set['dry']) && (string) ($set['target'] ?? '') === 'bookings', 'set_storage tar bookings i dry');

// 4. graph_week: med sql-flagga läses inte JSON-cachen.
$monday = new DateTimeImmutable('2026-10-26 00:00:00');
$key = jeab_gp_week_events_cache_key($monday, null, null);
file_put_contents(jeab_gp_week_events_cache_path(), json_encode([$key => ['at' => time(), 'events' => [['id' => 'json']]]]));
$read = jeab_gp_week_events_cache_read($monday, null, null);
sole($read['events'] === [] && $read['stale'] === true, 'graph_week läser inte färsk JSON när sql är flaggad');

// 5. notes: sql-flagga, tom SQL och ej importerad ger SQL (tom), inte notes.json.
file_put_contents($tmp . '/notes.json', json_encode(['next_id' => 2, 'notes' => [['id' => 1, 'text' => 'json']]]));
$probe = jeab_notes_json_read();
sole(is_array($probe) && count($probe['notes'] ?? []) === 1, 'notes.json finns för testet');
$notes = jeab_notes_store_load();
sole(($notes['notes'] ?? null) === [], 'notes läser inte JSON-reserven när sql är flaggad');

// 6. log_lines: orsak sparas när SQL saknas.
unset($GLOBALS['_JEAB_LOG_APPEND_FAIL']);
$ok = jeab_log_line_append_raw('who_diag', '{"at":"2026-10-30T10:00:00Z"}');
sole($ok === false && str_starts_with((string) ($GLOBALS['_JEAB_LOG_APPEND_FAIL']['who_diag'] ?? ''), 'ingen_pdo'), 'append utan SQL säger varför');
$mem = new PDO('sqlite::memory:');
$mem->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_LOG_PDO'] = $mem;
$ok = jeab_log_line_append_raw('who_diag', '{"at":"' . gmdate('Y-m-d\TH:i:s\Z') . '"}');
sole($ok === true, 'append med SQL går');
unset($GLOBALS['_JEAB_LOG_PDO']);

// 7. Inkorg: ärenden och Historik ur threads.json och arkivet.
file_put_contents($tmp . '/inkorg/threads.json', json_encode(['threads' => [
    'a' => ['status' => 'open'],
    'b' => ['status' => 'closed'],
    'c' => ['deleted' => true],
    'd' => [],
]]));
$fig = jeab_cutover_inkorg_json_figures($tmp . '/inkorg');
sole($fig['error'] === '' && $fig['arenden'] === 2 && $fig['historik'] - $fig['archive'] === 2, 'JSON-ärenden 2 och Historik 2');

// 8. inkorg_contact_link: JSON-rader som saknas i SQL räknas.
$left = jeab_inkorg_contact_link_json_left([['id' => 'x1']]);
sole($left['json_only'] === $left['json_count'] - (isset(jeab_inkorg_contacts_json_map()['x1']) ? 1 : 0), 'json_only räknar JSON-rader utanför SQL');

echo "ALL OK\n";
