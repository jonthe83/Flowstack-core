<?php

declare(strict_types=1);

/**
 * settings.json och rules.json i inkorg_side_doc när inkorg_storage=sql.
 * Syntetiska dokument. Ingen kunddata.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-inkorg-setrules-' . bin2hex(random_bytes(4));
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

function sr(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$settingsPath = jeab_inkorg_dir() . '/settings.json';
$rulesPath = jeab_inkorg_dir() . '/rules.json';
$settingsDoc = [
    'send_enabled' => true,
    'intent' => 'Syntetisk instruktion',
    'auto_quote_enabled' => false,
    'helpscout_companies' => ['malmo'],
    'quote_templates' => ['batteri' => 'Hej test@example.com'],
];
$rulesDoc = [
    'rules' => [[
        'id' => 'r-syntet',
        'name' => 'Syntet',
        'type' => 'Skräp',
        'match' => 'all',
        'conditions' => [['field' => 'from', 'header' => '', 'op' => 'contains', 'value' => 'noreply']],
        'confidence' => 100,
    ]],
    'lists' => ['lista-syntet' => ['name' => 'Syntetlista', 'entries' => ['foo']]],
    'tags' => ['syntet' => ['name' => 'Syntet']],
    'views' => [['id' => 'v-syntet', 'name' => 'Syntetvy', 'menu' => true, 'filter' => []]],
    'removed_seeds' => array_values(array_column(jeab_inkorg_default_rules(), 'id')),
    'removed_lists' => array_keys(function_exists('jeab_inkorg_seed_lists') ? jeab_inkorg_seed_lists() : []),
    'removed_views' => array_values(array_column(function_exists('jeab_inkorg_seed_views') ? jeab_inkorg_seed_views() : [], 'id')),
    'removed_tags' => array_keys(function_exists('jeab_inkorg_seed_tags') ? jeab_inkorg_seed_tags() : []),
    'views_v3' => true,
    'views_v4' => true,
    'views_v5' => true,
    'views_v6' => true,
    'views_v7' => true,
    'views_v8' => true,
];
$settingsJson = json_encode($settingsDoc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$rulesJson = json_encode($rulesDoc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($settingsPath, $settingsJson);
file_put_contents($rulesPath, $rulesJson);
file_put_contents(jeab_inkorg_dir() . '/messages.json', json_encode(['messages' => []], JSON_UNESCAPED_UNICODE));
file_put_contents(jeab_inkorg_dir() . '/threads.json', json_encode(['threads' => new stdClass()], JSON_UNESCAPED_UNICODE));

sr(jeab_inkorg_storage_mode() === 'json', 'flaggan är json före testet');
jeab_inkorg_locked('settings.json', static function (?array $d): array {
    $d = $d ?? [];
    $d['send_enabled'] = false;

    return [$d, $d];
});
$afterJson = json_decode((string) file_get_contents($settingsPath), true);
sr(is_array($afterJson) && $afterJson['send_enabled'] === false, 'json-läge skriver settings.json');
$settingsDoc['send_enabled'] = false;
$settingsJson = json_encode($settingsDoc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($settingsPath, $settingsJson);

sr(jeab_inkorg_sql_set_storage('sql') && jeab_inkorg_storage_mode() === 'sql', 'sidolagret körs i sql-läge');
sr(jeab_inkorg_side_sql(), 'side_sql är på');
$pdo = jeab_inkorg_store_pdo();
sr($pdo instanceof PDO, 'PDO är öppen');

$readSettings = jeab_inkorg_json_file('settings.json');
sr(is_array($readSettings) && $readSettings['intent'] === 'Syntetisk instruktion' && $readSettings['send_enabled'] === false, 'settings läses från importen');
sr((string) file_get_contents($settingsPath) === $settingsJson, 'settings-filen skrivs inte vid läsning');

$readRules = jeab_inkorg_json_file('rules.json');
sr(is_array($readRules) && ($readRules['rules'][0]['id'] ?? '') === 'r-syntet', 'rules läses från importen');
sr((string) file_get_contents($rulesPath) === $rulesJson, 'rules-filen skrivs inte vid läsning');

$againSettings = jeab_inkorg_json_file('settings.json');
$againRules = jeab_inkorg_json_file('rules.json');
sr($againSettings === $readSettings, 'andra settings-läsningen är samma dokument');
sr($againRules === $readRules, 'andra rules-läsningen är samma dokument');

$stmt = $pdo->prepare('SELECT payload, imported FROM inkorg_side_doc WHERE name = ?');
$stmt->execute(['settings.json']);
$row = $stmt->fetch();
sr(is_array($row) && (int) ($row['imported'] ?? 0) === 1, 'settings är importerad');
$stmt->execute(['rules.json']);
$row = $stmt->fetch();
sr(is_array($row) && (int) ($row['imported'] ?? 0) === 1, 'rules är importerad');

jeab_inkorg_locked('settings.json', static function (?array $d): array {
    $d = $d ?? [];
    $d['auto_quote_enabled'] = true;
    $d['intent'] = 'Uppdaterad instruktion';

    return [$d, $d];
});
sr((string) file_get_contents($settingsPath) === $settingsJson, 'settings-skrivning rör inte filen');
$fromSql = jeab_inkorg_json_file('settings.json');
sr(is_array($fromSql) && $fromSql['auto_quote_enabled'] === true && $fromSql['intent'] === 'Uppdaterad instruktion', 'settings-skrivning syns i SQL');
sr(jeab_auto_settings()['quote_enabled'] === true, 'auto_settings läser SQL');
sr(jeab_inkorg_send_enabled() === false, 'send_enabled läser SQL');

jeab_inkorg_locked('rules.json', static function (?array $d): array {
    $d = $d ?? [];
    $d['lists']['lista-syntet']['entries'][] = 'bar';

    return [$d, $d];
});
sr((string) file_get_contents($rulesPath) === $rulesJson, 'rules-skrivning rör inte filen');
$lists = jeab_inkorg_lists();
sr(isset($lists['lista-syntet']) && in_array('bar', $lists['lista-syntet']['entries'], true), 'listor läses från SQL');
$views = jeab_inkorg_views();
$viewIds = array_column($views, 'id');
sr(in_array('v-syntet', $viewIds, true), 'inkorg_views läser vyer ur rules-dokumentet');

sr(jeab_inkorg_side_export('settings.json'), 'settings exporteras till fil');
$exported = json_decode((string) file_get_contents($settingsPath), true);
sr(is_array($exported) && ($exported['intent'] ?? '') === 'Uppdaterad instruktion', 'exporten har SQL-innehållet');
sr(jeab_inkorg_side_export('rules.json'), 'rules exporteras till fil');
$exportedRules = json_decode((string) file_get_contents($rulesPath), true);
sr(is_array($exportedRules) && in_array('bar', $exportedRules['lists']['lista-syntet']['entries'] ?? [], true), 'rules-exporten har SQL-innehållet');
file_put_contents($settingsPath, $settingsJson);
file_put_contents($rulesPath, $rulesJson);

$pdo->prepare('DELETE FROM inkorg_side_doc WHERE name = ?')->execute(['settings.json']);
unset($GLOBALS['_JEAB_INKORG_SIDE_PEEK'], $GLOBALS['_JEAB_INKORG_SETTINGS_MEM'], $GLOBALS['_JEAB_INKORG_SETTINGS_STAMP']);
$first = jeab_inkorg_side_doc('settings.json');
$pdo->prepare('UPDATE inkorg_side_doc SET imported = 0 WHERE name = ?')->execute(['settings.json']);
unset($GLOBALS['_JEAB_INKORG_SIDE_PEEK']);
$second = jeab_inkorg_side_doc('settings.json');
sr($first === $second && ($first['intent'] ?? '') === 'Syntetisk instruktion', 'import två gånger ger samma dokument');

sr(jeab_inkorg_sql_set_storage('json') && jeab_inkorg_storage_mode() === 'json', 'rollback till json');
sr(!jeab_inkorg_side_sql(), 'side_sql är av efter rollback');
jeab_inkorg_locked('settings.json', static function (?array $d): array {
    $d = $d ?? [];
    $d['intent'] = 'JSON efter rollback';

    return [$d, $d];
});
$disk = json_decode((string) file_get_contents($settingsPath), true);
sr(is_array($disk) && ($disk['intent'] ?? '') === 'JSON efter rollback', 'json-läge skriver filen efter rollback');

$mod = jeab_migrate_inkorg_settings_module();
sr($mod['id'] === 'inkorg_settings' && $mod['table'] === 'inkorg_side_doc', 'settings-modulen pekar på inkorg_side_doc');
$rows = jeab_migrate_inkorg_settings_rows($settingsDoc);
sr(count($rows) === 1 && $rows[0]['name'] === 'settings.json', 'settings-rader är ett dokument');
sr(jeab_migrate_inkorg_settings_checksum($settingsDoc) === jeab_migrate_inkorg_settings_checksum($settingsDoc), 'settings-checksumma är stabil');

$viewRows = jeab_migrate_inkorg_views_rows($rulesDoc);
sr(count($viewRows) === 1 && $viewRows[0]['view_id'] === 'v-syntet', 'inkorg_views extraherar bara vyer');
$encodedViews = (string) json_encode($viewRows);
sr(!str_contains($encodedViews, 'lista-syntet') && !str_contains($encodedViews, 'r-syntet'), 'vyer tar inte med listor eller regler');

$ruleMod = jeab_migrate_inkorg_rules_module();
sr($ruleMod['json'] === 'data/inkorg/rules.json', 'rules-modulen läser samma fil som inkorg_views');
$ruleRows = jeab_migrate_inkorg_rules_rows($rulesDoc);
sr(count($ruleRows) === 1 && $ruleRows[0]['name'] === 'rules.json', 'rules-rader är hela dokumentet');

$order = jeab_migrate_order();
sr(($order['inkorg_settings'] ?? '') === 'C' && ($order['inkorg_rules'] ?? '') === 'C' && ($order['inkorg_views'] ?? '') === 'C', 'modulerna ligger i grupp C');

echo "ok\n";
