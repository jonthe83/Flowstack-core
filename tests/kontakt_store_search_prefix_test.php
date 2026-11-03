<?php

declare(strict_types=1);

/**
 * Kontaktsök: telefon och e-post är prefix mot de vikta kolumnerna.
 * Namn, gata, ort och postnummer är fortfarande innehållssök.
 * Påhittade rader.
 */

$tmp = sys_get_temp_dir() . '/jeab-kontakt-prefix-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function kp(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $tmp . '/search.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$store = new JeabPdoContactStore($pdo);
$GLOBALS['_JEAB_TICK'] = true;
unset($GLOBALS['_JEAB_PAGE_REQUEST'], $GLOBALS['_JEAB_MIGRATE_RUNS']);
jeab_ensure_contact_search_prefix($pdo, 'sqlite');
unset($GLOBALS['_JEAB_TICK']);

$anna = [
    'id' => 'kt_pref_anna',
    'street' => 'Aagatan 1',
    'street_key' => 'aagatan 1',
    'postal_code' => '17730',
    'city' => 'Järfälla',
    'company' => 'jarfalla',
    'owners' => [['name' => 'Anna Exempel', 'contact' => true, 'email' => 'anna@example.test', 'phone' => '']],
];
$pia = [
    'id' => 'kt_pref_pia',
    'street' => 'Telefonvägen 4',
    'street_key' => 'telefonvagen 4',
    'postal_code' => '21611',
    'city' => 'Malmö',
    'company' => 'malmo',
    'owners' => [['name' => 'Pia Telefon', 'contact' => true, 'email' => 'pia@example.test', 'phone' => '070-123 45 67']],
];
$nio = [
    'id' => 'kt_pref_nio',
    'street' => 'Nian 9',
    'street_key' => 'nian 9',
    'postal_code' => '11120',
    'city' => 'Stockholm',
    'company' => 'jarfalla',
    'owners' => [['name' => 'Bo Nio', 'contact' => true, 'email' => 'nio@niodom.test', 'phone' => '070-199 99 99']],
];
foreach ([$anna, $pia, $nio] as $row) {
    $store->upsert($row['id'], $row);
}
$pdo->prepare('INSERT INTO contact_emails (email, contact_id) VALUES (?, ?)')->execute(['extra@example.test', 'kt_pref_pia']);

$ids = static function (array $page): array {
    return array_keys($page['rows']);
};

kp(jeab_kontakt_search_kind('21611') === 'text', 'postnummer på fem siffror är text');
kp(jeab_kontakt_search_kind('070123') === 'phone', 'mobilprefix är telefon');
kp(jeab_kontakt_search_kind('anna@example.test') === 'email', 'adress med @ är e-post');
kp(jeab_kontakt_search_kind('@example.test') === 'text', 'ensam domän är innehållssök');
kp(jeab_kontakt_search_kind('Anna') === 'text', 'namn är text');

$phoneClause = jeab_kontakt_search_clause('0701234567');
kp($phoneClause['kind'] === 'phone', 'telefonklausulen är telefon');
kp(!str_contains($phoneClause['sql'], 'owner_name_fold'), 'telefonsök tar inte med namnkolumnen');
kp(($phoneClause['args'][0] ?? '') === '+46701234567', '070 blir prefix mot +46, fick ' . ($phoneClause['args'][0] ?? ''));
kp(($phoneClause['args'][1] ?? '') === '+46701234568', 'telefonprefixet är ett öppet intervall');
kp(!str_contains($phoneClause['sql'], 'LIKE'), 'telefonprefixet använder inte LIKE');
$emailClause = jeab_kontakt_search_clause('pia@example');
kp(str_contains($emailClause['sql'], 'owner_email >= ?') && str_contains($emailClause['sql'], 'email < ?'), 'e-postprefixet är ett intervall');

kp($ids($store->page('0701234567', 'alla', null, 50)) === ['kt_pref_pia'], 'helt mobilnummer träffar Pia');
kp($ids($store->page('070-123 45', 'alla', null, 50)) === ['kt_pref_pia'], 'mobilnummer med bindestreck är samma prefix');
kp($ids($store->page('1234567', 'alla', null, 50)) === [], 'slutet av numret är inte en träff');
kp($ids($store->page('pia@example', 'alla', null, 50)) === ['kt_pref_pia'], 'e-postprefix träffar Pia');
kp($ids($store->page('extra@example', 'alla', null, 50)) === ['kt_pref_pia'], 'prefix träffar även contact_emails');
kp($ids($store->page('example.test', 'alla', null, 50)) === ['kt_pref_anna', 'kt_pref_pia'] || $ids($store->page('example.test', 'alla', null, 50)) === ['kt_pref_pia', 'kt_pref_anna'], 'domän utan @ är fortfarande innehållssök');
kp($ids($store->page('nna', 'alla', null, 50)) === ['kt_pref_anna'], 'mitten av namnet träffar fortfarande');
kp($ids($store->page('21611', 'alla', null, 50)) === ['kt_pref_pia'], 'postnummer träffar fortfarande');
kp($ids($store->page('elef', 'alla', null, 50)) === ['kt_pref_pia'], 'mitten av telefon-namnet är kvar');
$nioSpan = jeab_kontakt_prefix_span('+4670199');
kp($nioSpan !== null && $nioSpan['upper'] === '+467019a', 'nioans övre gräns är a, inte kolon');
kp($ids($store->page('070199', 'alla', null, 50)) === ['kt_pref_nio'], 'prefix som slutar på 9 träffar hela numret');
kp($ids($store->page('070200', 'alla', null, 50)) === [], 'nästa hundratal är inte med i nio-intervallet');

$ins = $pdo->prepare('INSERT INTO contacts (id, row_json, street_key, postal_code, owner_email, owner_phone, owner_name_fold, company, city_fold, sort_key, sort_city, list_ready, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
$now = '2026-10-28 12:00:00';
$pdo->beginTransaction();
for ($i = 0; $i < 2000; $i++) {
    $id = 'kt_bulk_' . $i;
    $phone = sprintf('+4670%07d', $i);
    $ins->execute([$id, '{}', '', '', 'bulk' . $i . '@example.test', $phone, 'bulk ' . $i, '', '', 'bulk', '', $now, $now]);
}
$pdo->commit();

$span = jeab_kontakt_prefix_span('+4670123');
kp($span !== null, 'mätprefixet har ett intervall');
$planRows = $pdo->query(
    'EXPLAIN QUERY PLAN SELECT id FROM contacts WHERE owner_phone >= ' . $pdo->quote((string) $span['lower'])
    . ' AND owner_phone < ' . $pdo->quote((string) $span['upper'])
)->fetchAll();
$plan = '';
foreach ($planRows as $planRow) {
    $plan .= ' ' . (string) ($planRow['detail'] ?? '');
}
kp(str_contains($plan, 'contacts_phone_prefix') || str_contains($plan, 'owner_phone'), 'telefonprefixet kan använda index: ' . trim($plan));

$time = static function (string $sql, array $args) use ($pdo): float {
    $stmt = $pdo->prepare($sql);
    $t0 = microtime(true);
    for ($n = 0; $n < 40; $n++) {
        $stmt->execute($args);
        $stmt->fetchAll();
    }

    return (microtime(true) - $t0) * 1000;
};
$prefixMs = $time(
    'SELECT id FROM contacts WHERE owner_phone >= ? AND owner_phone < ?',
    [(string) $span['lower'], (string) $span['upper']]
);
$containsMs = $time('SELECT id FROM contacts WHERE owner_phone LIKE ? ESCAPE \'!\'' , ['%1234567%']);
echo 'perf prefix ' . number_format($prefixMs, 1, '.', '') . ' ms / 40, contains ' . number_format($containsMs, 1, '.', '') . ' ms / 40, plan ' . trim($plan) . "\n";
kp($prefixMs < $containsMs * 4 || $prefixMs < 80, 'prefixsöket är inte långsammare än en tabellskanning i den här fixturen');

echo "kontakter search prefix ok\n";
