<?php

declare(strict_types=1);

/**
 * gp_flags skriver inte över settings, inbox.settings_restore är torr som standard,
 * och offertfönstret väljer skicka, stopp eller avstängd offert.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-settings-restore-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
putenv('DATA_BACKEND');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function rs_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function rs_sql(): array
{
    jeab_inkorg_side_forget('settings.json');
    if (function_exists('jeab_inkorg_json_bump_settings')) {
        jeab_inkorg_json_bump_settings();
    }
    $doc = jeab_inkorg_settings_doc();

    return is_array($doc) ? $doc : [];
}

$secret = 'sekret-aa11bb22cc33';
$disk = [
    'intent' => 'Behåll instruktionen',
    'auto_quote_enabled' => false,
    'auto_trash_spam' => true,
    'api_key' => $secret,
    'graph_person_mail' => false,
    'graph_person_calendar' => false,
    'quote_templates' => ['batteri_jarfalla' => 'Hej kund'],
];
file_put_contents(jeab_inkorg_dir() . '/settings.json', json_encode($disk, JSON_UNESCAPED_UNICODE));
file_put_contents(jeab_inkorg_dir() . '/messages.json', json_encode(['messages' => []]));
file_put_contents(jeab_inkorg_dir() . '/threads.json', json_encode(['threads' => new stdClass()]));

rs_ok(jeab_inkorg_sql_set_storage('sql') && jeab_inkorg_side_sql(), 'sidolagret är sql');
$imported = jeab_inkorg_settings_doc();
rs_ok(is_array($imported) && ($imported['intent'] ?? '') === 'Behåll instruktionen', 'importen läser diskens instruktion');

$flags = jeab_gp_flags_save(['graph_person_mail' => '1', 'graph_person_calendar' => '0']);
rs_ok($flags === ['graph_person_mail' => true, 'graph_person_calendar' => false], 'flaggsvar är bara de två flaggorna');
$afterFlags = rs_sql();
rs_ok(($afterFlags['intent'] ?? '') === 'Behåll instruktionen' && ($afterFlags['api_key'] ?? '') === $secret && ($afterFlags['auto_quote_enabled'] ?? null) === false, 'flaggsparning behåller övriga nycklar');
rs_ok(($afterFlags['graph_person_mail'] ?? false) === true && ($afterFlags['graph_person_calendar'] ?? true) === false, 'flaggorna mergas in');
rs_ok(jeab_auto_settings()['quote_enabled'] === false && jeab_auto_quote_enabled_read()['auto_quote_enabled'] === false, 'auto_settings och quote_enabled läser samma dokument');

$pdo = jeab_inkorg_store_pdo();
rs_ok($pdo instanceof PDO, 'PDO är öppen');
$stripped = json_encode(['graph_person_mail' => true, 'graph_person_calendar' => false]);
$pdo->prepare('UPDATE inkorg_side_doc SET payload = ?, imported = 1 WHERE name = ?')->execute([$stripped, 'settings.json']);
jeab_inkorg_side_forget('settings.json');
jeab_inkorg_json_bump_settings();
$broken = rs_sql();
rs_ok(array_keys($broken) === ['graph_person_mail', 'graph_person_calendar'] && jeab_auto_settings()['quote_enabled'] === false, 'överlämnad SQL-rad har bara graph-flaggor');

$dry = jeab_inkorg_hook(['action' => 'inbox.settings_restore']);
$dryJson = json_encode($dry);
rs_ok(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1 && ($dry['sent'] ?? 1) === 0 && ($dry['would_send'] ?? true) === false && ($dry['wrote'] ?? 1) === 0, 'utelämnad dry skriver inte och skickar inte');
rs_ok(is_array($dry['keys'] ?? null) && in_array('intent', $dry['keys'], true) && in_array('api_key', $dry['keys'], true) && !in_array('graph_person_mail', $dry['keys'], true), 'dry listar saknade nycklar och lämnar graph-flaggor');
rs_ok(is_array($dry['counts'] ?? null) && ($dry['counts']['sql'] ?? 0) === 2 && ($dry['counts']['disk'] ?? 0) === count($disk) && ($dry['counts']['fill'] ?? 0) === count($dry['keys']), 'dry visar antal');
rs_ok(is_string($dryJson) && !str_contains($dryJson, $secret) && !str_contains($dryJson, 'Behåll instruktionen'), 'dry innehåller inga värden');
rs_ok(rs_sql() === ['graph_person_mail' => true, 'graph_person_calendar' => false], 'dry lämnar SQL-raden');

$denied = jeab_inkorg_hook(['action' => 'inbox.settings_restore', 'dry' => 0]);
rs_ok(($denied['ok'] ?? true) === false && ($denied['wrote'] ?? 1) === 0 && ($denied['sent'] ?? 1) === 0 && rs_sql() === ['graph_person_mail' => true, 'graph_person_calendar' => false], 'dry=0 utan confirm skriver inte');

$merged = jeab_inkorg_settings_restore_merge(
    ['graph_person_mail' => true, 'graph_person_calendar' => false],
    ['graph_person_mail' => false, 'intent' => 'Hej', 'api_key' => $secret]
);
rs_ok(($merged['graph_person_mail'] ?? false) === true && ($merged['graph_person_calendar'] ?? true) === false && ($merged['intent'] ?? '') === 'Hej', 'merge fyller luckor och behåller graph-flaggor');

$off = jeab_auto_offer_window_decision(false, [['hit' => true, 'date' => '2026-10-20 12:00:00']]);
rs_ok($off['action'] === 'quote_off' && $off['note'] === 'automatisk offert avstängd' && $off['would_send'] === false && $off['sent'] === 0, 'avstängd offert ger anteckning och inget utskick');
$send = jeab_auto_offer_window_decision(true, []);
rs_ok($send['action'] === 'send' && $send['would_send'] === true && $send['sent'] === 0 && $send['note'] === '', 'ingen tidigare offert ska skickas');
$old = jeab_auto_offer_window_decision(true, [['hit' => true, 'date' => '2026-09-01 10:00:00']]);
rs_ok($old['action'] === 'send' && $old['would_send'] === true, 'offert äldre än 30 dagar stoppar inte');
$hit = jeab_auto_offer_window_decision(true, [
    ['hit' => false, 'date' => '2026-10-28 09:00:00'],
    ['hit' => true, 'date' => '2026-10-20 15:30:00'],
]);
rs_ok(
    $hit['action'] === 'blocked'
    && $hit['date'] === '2026-10-20'
    && $hit['would_send'] === false
    && $hit['sent'] === 0
    && $hit['note'] === 'skickande av offert har stoppats pga. att offert på samma produkt skickats ut datum 2026-10-20',
    'samma produkt inom 30 dagar stoppar med datum'
);

echo "inkorg settings restore: ok\n";
