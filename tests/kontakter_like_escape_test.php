<?php

declare(strict_types=1);

/**
 * Kontaktsökets LIKE får inte använda backslash som ESCAPE.
 * MariaDB läser '\' som ett escape i strängen och 1064:ar frågan.
 * Påhittade rader.
 */

$tmp = sys_get_temp_dir() . '/jeab-kontakter-like-' . bin2hex(random_bytes(4));
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

function le(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

final class JeabLikeLogPdo extends PDO
{
    /** @var list<string> */
    public array $sql = [];

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->sql[] = $query;

        return parent::prepare($query, $options);
    }
}

$pdo = new JeabLikeLogPdo('sqlite:' . $tmp . '/search.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$store = new JeabPdoContactStore($pdo);

$anna = [
    'id' => 'kt_like_anna',
    'street' => 'Aagatan 1',
    'street_key' => 'aagatan 1',
    'postal_code' => '17730',
    'city' => 'Järfälla',
    'company' => 'jarfalla',
    'owners' => [['name' => 'Anna Exempel', 'contact' => true, 'email' => 'anna@example.test', 'phone' => '']],
];
$town = [
    'id' => 'kt_like_town',
    'street' => 'Limhamnsgatan 2',
    'street_key' => 'limhamnsgatan 2',
    'postal_code' => '21611',
    'city' => 'Malmö',
    'company' => 'malmo',
    'owners' => [['name' => 'Bo Stad', 'contact' => true, 'email' => 'bo@example.test', 'phone' => '']],
];
$wild = [
    'id' => 'kt_like_wild',
    'street' => 'Specialgatan 5',
    'street_key' => 'specialgatan 5',
    'postal_code' => '11122',
    'city' => 'Teststad',
    'company' => 'jarfalla',
    'owners' => [['name' => '50%_off!', 'contact' => true, 'email' => 'wild@example.test', 'phone' => '']],
];
$decoy = [
    'id' => 'kt_like_decoy',
    'street' => 'Decoygatan 9',
    'street_key' => 'decoygatan 9',
    'postal_code' => '33344',
    'city' => 'Teststad',
    'company' => 'jarfalla',
    'owners' => [['name' => '50XXoff!', 'contact' => true, 'email' => 'decoy@example.test', 'phone' => '']],
];
foreach ([$anna, $town, $wild, $decoy] as $row) {
    $store->upsert($row['id'], $row);
}
if (function_exists('jeab_settings_put')) {
    jeab_settings_put($pdo, 'sqlite', 'contacts_list_cols_v1', 'ok');
}

$ids = static function (array $page): array {
    return array_keys($page['rows']);
};

$pdo->sql = [];
$name = $store->page('Anna', 'alla', null, 50);
le($ids($name) === ['kt_like_anna'], 'sök på namn träffar Anna');

$townHit = $store->page('Malmö', 'alla', null, 50);
le($ids($townHit) === ['kt_like_town'], 'sök på ort träffar Malmö');

$postal = $store->page('21611', 'alla', null, 50);
le($ids($postal) === ['kt_like_town'], 'sök på postnummer träffar 21611');

$special = $store->page('50%_off!', 'alla', null, 50);
le($ids($special) === ['kt_like_wild'], 'sök med % och _ träffar bara det bokstavliga värdet');

$likeSql = array_values(array_filter($pdo->sql, static fn (string $sql): bool => str_contains($sql, 'LIKE')));
le($likeSql !== [], 'sökningen skickar LIKE till SQL');
$badEscape = false;
$missingBang = false;
foreach ($likeSql as $sql) {
    if (str_contains($sql, "ESCAPE '\\'")) {
        $badEscape = true;
    }
    if (!str_contains($sql, "ESCAPE '!'")) {
        $missingBang = true;
    }
}
le(!$badEscape, 'genererad SQL har inte ESCAPE \'\\\\\'');
le(!$missingBang, 'genererad SQL använder ESCAPE \'!\'');

echo "kontakter like escape ok\n";
