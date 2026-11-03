<?php

declare(strict_types=1);

/**
 * Sparvägar i ordrar och kalkyl efter en delad PDO.
 * Nästlad transaktion, DDL i transaktion, släppt anslutning,
 * session efter stängning, kalkyl-slug och fellogg.
 * Påhittad data. Inget personnummer i klartext.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

final class JeabSaveDdlProbe extends PDO
{
    /** @var list<string> */
    public array $ddl = [];

    public function exec(string $statement): int|false
    {
        if ($this->inTransaction() && preg_match('/^\s*(CREATE|ALTER|DROP|RENAME|TRUNCATE)\b/i', $statement) === 1) {
            $this->ddl[] = $statement;
        }

        return parent::exec($statement);
    }
}

$tmp = sys_get_temp_dir() . '/jeab-ordrar-kalkyl-tx-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
mkdir($tmp . '/sess', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = 'flowstack.example.test';
$_GET['p'] = 'ordrar';
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
require dirname(__DIR__) . '/src/bootstrap.php';

session_save_path($tmp . '/sess');
session_id('jeabkalkyltest');
session_start();
$_SESSION['jeab_flash'] = 'kvar';
session_write_close();
if (session_status() === PHP_SESSION_ACTIVE) {
    fwrite(STDERR, "FAIL sessionen är stängd före nästa skrivning\n");
    exit(1);
}
$csrf = jeab_csrf_session_token();
if (session_status() !== PHP_SESSION_ACTIVE || ($_SESSION['jeab_flash'] ?? '') !== 'kvar' || ($_SESSION['csrf'] ?? '') !== $csrf || $csrf === '') {
    fwrite(STDERR, "FAIL csrf skrivs efter att sessionen stängts\n");
    exit(1);
}
session_write_close();

function okx(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$probe = new JeabSaveDdlProbe('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$probe->beginTransaction();
jeab_purchases_store_ensure($probe);
jeab_log_ensure($probe);
jeab_notes_store_ensure($probe);
jeab_sms_store_ensure($probe);
jeab_ensure_table_columns($probe, 'purchases', ['kind' => 'TEXT'], 'sqlite');
jeab_list_index_create($probe, 'CREATE INDEX purchases_kind ON purchases (kind)');
okx($probe->ddl === [], 'DDL körs inte medan en transaktion är öppen');
okx($probe->inTransaction(), 'transaktionen är kvar när DDL hoppas över');
$probe->rollBack();
jeab_purchases_store_ensure($probe);
okx(!$probe->inTransaction(), 'ensure utanför transaktion får skapa tabellen');

$store = jeab_store();
$pdo = $store->pdo();
okx($pdo instanceof PDO, 'sqlite-butiken är öppen');
jeab_notes_store_ensure($pdo);
jeab_sms_store_ensure($pdo);
jeab_purchases_store_ensure($pdo);

jeab_pdo_transaction($pdo, static function (PDO $pdo): void {
    jeab_notes_store_mutate(static function (array $data): string {
        $data['notes'][] = ['id' => 1, 'text' => 'inne i transaktion'];

        return 'ok';
    });
    okx($pdo->inTransaction(), 'anteckningar delar den yttre transaktionen');
    jeab_sql_purchases_put($pdo, [
        'reminder_id' => 4401,
        'kind' => 'laddbox',
        'company' => 'jarfalla',
        'pris' => 1000,
        'updated_at' => '2026-10-20 12:00:00',
    ]);
    $patched = jeab_sql_purchases_patch($pdo, 4401, static function (array $row): array {
        $row['pris'] = 2500;

        return $row;
    });
    okx(is_array($patched) && (int) ($patched['pris'] ?? 0) === 2500, 'orderpatch inuti transaktion skriver pris');
    jeab_sms_store_update(9, static function (?array $prev): array {
        unset($prev);

        return ['channel' => 'sms'];
    });
    okx($pdo->inTransaction(), 'sms rullar inte tillbaka den yttre transaktionen');
});
$savedPatch = jeab_sql_purchases_get($pdo, 4401);
okx(is_array($savedPatch) && (int) ($savedPatch['pris'] ?? 0) === 2500, 'orderpatch finns kvar efter commit');

$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
$reminder = jeab_create_reminder($store, 'Ada Laddbox', '2026-11-01 00:00:00', 'test', null, [
    'customer' => 'Ada Laddbox',
    'company' => 'jarfalla',
    'address' => 'Testgatan 1',
]);
$id = (int) $reminder['id'];
jeab_purchase_put([
    'reminder_id' => $id,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Ada Laddbox',
    'address' => 'Testgatan 1',
    'steps' => [],
]);
$saved = jeab_ordrar_save_questions([
    'id' => $id,
    'kind' => 'laddbox',
    'pris' => '12 500',
    'owner_name' => ['Ada Laddbox', 'Bea Laddbox'],
    'owner_pnr' => ['', ''],
    'owner_address' => ['Testgatan 1', 'Testgatan 1'],
    'owner_forslag' => [],
    'install_what' => 'Laddbox',
    'payouts_form' => '1',
]);
okx((int) ($saved['pris'] ?? 0) === 12500, 'Spara laddbox med ägarkort och att betala');
okx(count(is_array($saved['owners'] ?? null) ? $saved['owners'] : []) === 2, 'båda ägarkorten sparas');

$held = jeab_store();
jeab_pdo_release();
$alive = (int) $held->pdo()->query('SELECT 1')->fetchColumn();
okx($alive === 1, 'släppt PDO öppnas igen åt anroparen');
$after = jeab_purchase_get($id);
okx(is_array($after) && (int) ($after['pris'] ?? 0) === 12500, 'ordern går att läsa efter att anslutningen släppts');

okx(jeab_page_slug('kalkyl') === 'offert', 'p=kalkyl är kalkylsidan');
$_SERVER['REQUEST_METHOD'] = 'GET';
$GLOBALS['_JEAB_PAGE_REQUEST'] = true;
$GLOBALS['_JEAB_SAPI'] = 'fpm-fcgi';
$started = microtime(true);
okx(jeab_rot_kalkyl_address_search('Testgatan 1') === null, 'kalkyl-uppslag görs inte på en sidvisning');
okx(microtime(true) - $started < 1, 'blockerat uppslag väntar inte på nätet');
okx(jeab_page_blocks_external(), 'GET-sidan blockerar externa anrop');
$_SERVER['REQUEST_METHOD'] = 'POST';
okx(!jeab_page_blocks_external(), 'POST får nå externa anrop');
$calc = jeab_offert_calc(['sigen_typ' => 'tp2', 'sigen_kw' => '12.0', 'b3' => 2, 'b14' => 16, 'sokande' => 1]);
okx(isset($calc['i14']) && (float) $calc['i14'] > 0, 'kalkylen räknar att betala');
$standard = jeab_offert_save_standard($store, ['b3' => 3, 'hourly' => 750]);
okx((float) ($standard['offert']['b3'] ?? 0) === 3.0, 'kalkylens standard sparas');

okx(true, 'csrf skrivs efter att sessionen stängts');

$marker = 'spar-token-' . bin2hex(random_bytes(4));
$hidden = '1999' . '0101-' . '1234';
$logPdo = jeab_store()->pdo();
$logPdo->beginTransaction();
$boom = new RuntimeException('Ordern gick inte att skriva ' . $marker . ' ' . $hidden);
okx(jeab_public_error_text($boom) === 'Något gick fel.', 'okänt fel visas som Något gick fel');
jeab_log_uncaught('ordrar', $boom);
$logPdo->rollBack();
$blob = json_encode(jeab_app_errors_read(30), JSON_UNESCAPED_UNICODE);
okx(is_string($blob) && str_contains($blob, $marker), 'fellogg har det riktiga felet efter rollback');
okx(is_string($blob) && str_contains($blob, '[pnr]') && !str_contains($blob, $hidden), 'personnummer maskeras i felloggen');

$v3 = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
okx(str_contains($v3, 'data.busy') && str_contains($v3, 'data.retry'), 'ordrar försöker igen vid busy i stället för att tolka det som sparfel');

echo "ordrar_kalkyl_save_tx_test ok\n";
