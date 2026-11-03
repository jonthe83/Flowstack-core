<?php

declare(strict_types=1);

/**
 * contacts.drop_customer_fields: DROP som stannar efter första kolumnen går att ångra,
 * fyllningar backas bara när de är oförändrade, bolag och ägare stoppar, migreringen
 * filtrerar per saknad kolumn. Påhittade namn, inget personnummer.
 */
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-drop-partial-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$public = $tmp . '/public-data';
mkdir($public, 0775, true);
$errLog = $tmp . '/php-error.log';
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['_JEAB_CONTACTS_STRICT'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('CONTACTS_SOLE=1');
putenv('CONTACTS_STRICT');
unset($_ENV['CONTACTS_STRICT']);
putenv('JEAB_WEBHOOK_CAPTURE=1');
$GLOBALS['_JEAB_WEBHOOKS'] = [];
ini_set('log_errors', '1');
ini_set('error_log', $errLog);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
    'incoming_webhook_token' => 'not-the-drop-token',
    'outgoing_webhook_url' => '',
], true) . ";\n");
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-drop-fields']));
require dirname(__DIR__) . '/src/bootstrap.php';
putenv('JEAB_PNR_HMAC_KEY=drop-fields-test-key');

function cc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/src/contacts_drop_customer_fields.php';
require_once $root . '/tests/fixtures/drop_keypair.php';
$dropPair = t_drop_keypair($tmp . '/keys');
t_drop_use_pub($dropPair);
$store = jeab_store();
$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;
putenv('CONTACTS_STRICT=1');
cc(jeab_contacts_copies_hold(), 'enda-källa är på');
$pdo = $store->pdo();
$stamp = '2026-01-01 00:00:00';
$bo = jeab_kontakt_find_or_create(['name' => 'Bo Delvis', 'email' => 'bo.delvis@example.test', 'company' => 'jarfalla'], false, false);
$boId = (string) $bo['id'];
$rem = $store->insertReminder(['title' => 'Delvis', 'customer_name' => 'Bo Delvis', 'email' => 'bo.delvis@example.test', 'phone' => '0709000022', 'company' => 'jarfalla', 'contact_id' => $boId, 'due_at' => '2026-11-02 09:00:00', 'created_at' => $stamp, 'updated_at' => $stamp]);
$remId = (int) $rem['id'];
$pdo->prepare("UPDATE reminders SET customer_name = 'Bo Delvis', email = 'bo.delvis@example.test', phone = '0709000022' WHERE id = ?")->execute([$remId]);

// Annat bolag på raden än på kontakten: 409, inget fylls.
$pdo->prepare("UPDATE reminders SET company = 'malmo' WHERE id = ?")->execute([$remId]);
$co = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
cc(($co['status'] ?? 0) === 409 && ($co['conflicts'][0]['fields'] ?? []) === ['company'] && jeab_kontakt_row_phone((array) jeab_kontakt_get($boId)) === '', 'annat bolag stoppar och fyller inget');
$pdo->prepare("UPDATE reminders SET company = 'jarfalla' WHERE id = ?")->execute([$remId]);

// Raden saknar bolag men kontakten har ett: 409, kontrollen hoppas inte över.
$pdo->prepare("UPDATE reminders SET company = '' WHERE id = ?")->execute([$remId]);
$noCo = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
cc(($noCo['status'] ?? 0) === 409 && ($noCo['conflicts'][0]['fields'] ?? []) === ['company'], 'bolag på bara ena sidan stoppar');
$pdo->prepare("UPDATE reminders SET company = 'jarfalla' WHERE id = ?")->execute([$remId]);

// 409 «Kör igen» före DROP: planen raderas, inget fylls nu eller senare.
$GLOBALS['_JEAB_DROP_BEFORE_LOCK'] = static function (PDO $pdo, string $table) use ($remId): void {
    if ($table === 'reminders') {
        $pdo->prepare("UPDATE reminders SET phone = '0709000055' WHERE id = ?")->execute([$remId]);
    }
};
$race = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
unset($GLOBALS['_JEAB_DROP_BEFORE_LOCK']);
jeab_copy_columns_forget();
cc(($race['status'] ?? 0) === 409 && ($race['changed'] ?? '') === 'reminders' && !is_file(jeab_contacts_drop_dir() . '/' . $race['batch_id'] . '.pending'), '409 före DROP raderar fyllningsplanen');
cc(jeab_kontakt_row_phone((array) jeab_kontakt_get($boId)) === '' && jeab_copy_column_present($pdo, 'reminders', 'phone'), '409 före DROP fyller och tar bort inget');
$pdo->prepare("UPDATE reminders SET phone = '0709000022' WHERE id = ?")->execute([$remId]);

// En gammal plan utan .done görs inte, den raderas.
$key = random_bytes(32);
$stale = 'aaaaaaaaaaaaaaaa';
cc(jeab_contacts_drop_save_pending($stale, [$boId => ['phone' => '0709000044']], $key), 'gammal plan sparad');
jeab_contacts_drop_finish_leftovers(static fn (string $b): string => $key);
cc(!is_file(jeab_contacts_drop_dir() . '/' . $stale . '.pending') && jeab_kontakt_row_phone((array) jeab_kontakt_get($boId)) === '', 'plan utan .done raderas och fylls inte');

// En plan som inte går att dekryptera ger fel och ligger kvar.
$bad = 'bbbbbbbbbbbbbbbb';
file_put_contents(jeab_contacts_drop_dir() . '/' . $bad . '.pending', json_encode([$boId => ['phone' => 'inte-krypterad']]));
cc(jeab_contacts_drop_mark_columns($bad, 'reminders', ['phone']), 'markering skrivs');
$threw = '';
try {
    jeab_contacts_drop_finish_leftovers(static fn (string $b): string => $key);
} catch (Throwable $e) {
    $threw = $e->getMessage();
}
cc(str_contains($threw, 'dekryptera') && is_file(jeab_contacts_drop_dir() . '/' . $bad . '.pending'), 'odekrypterbar plan ger fel och ligger kvar');
$blocked = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
jeab_copy_columns_forget();
cc(($blocked['ok'] ?? false) === true && is_file(jeab_contacts_drop_dir() . '/' . $bad . '.pending') && jeab_copy_column_present($pdo, 'reminders', 'customer_name') === false, 'en gammal plan utan nyckel ligger kvar och stoppar inte körningen');
$again = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
cc(($again['status'] ?? 0) === 500 && str_contains((string) ($again['error'] ?? ''), 'dekryptera'), 'utan undo-nyckel går den gamla planen inte att göra klart: 500');
// Städa testets påhittade batch och lägg tillbaka det som togs bort.
@unlink(jeab_contacts_drop_dir() . '/' . $bad . '.pending');
@unlink(jeab_contacts_drop_dir() . '/' . $bad . '.done');
t_drop_undo_key($dropPair);
$fix = jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => (string) $blocked['batch_id']], $store);
jeab_copy_columns_forget();
cc(($fix['ok'] ?? false) === true && jeab_copy_column_present($pdo, 'bookings', 'customer_name') && jeab_copy_column_present($pdo, 'reminders', 'customer_name'), 'undo lägger tillbaka körningen');

// Kontakt utan ägare: 409 i stället för en ny ägare.
jeab_kontakter_mutate(static function (array &$contacts) use ($boId): void {
    $contacts[$boId]['owners_backup'] = $contacts[$boId]['owners'];
    $contacts[$boId]['owners'] = [];
});
$noOwner = jeab_contacts_drop_customer_fields(['dry' => 1], $store);
cc(in_array('owner', (array) ($noOwner['conflicts'][0]['fields'] ?? []), true), 'kontakt utan ägare ger konflikt');
cc(((array) jeab_kontakt_get($boId))['owners'] === [], 'ingen ny ägare skapas');
jeab_kontakter_mutate(static function (array &$contacts) use ($boId): void {
    $contacts[$boId]['owners'] = $contacts[$boId]['owners_backup'];
    unset($contacts[$boId]['owners_backup']);
});

// Fel efter första kolumnen, utan transaktion (som MySQL): kolumn 1 är borta.
$GLOBALS['_JEAB_DROP_NO_TX'] = true;
$GLOBALS['_JEAB_DROP_AFTER_COLUMN'] = static function (PDO $pdo, string $table, string $col): void {
    if ($table === 'reminders' && $col === 'customer_name') {
        throw new RuntimeException('test');
    }
};
$half = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
unset($GLOBALS['_JEAB_DROP_AFTER_COLUMN'], $GLOBALS['_JEAB_DROP_NO_TX']);
jeab_copy_columns_forget();
cc(($half['status'] ?? 0) === 500 && !jeab_copy_column_present($pdo, 'reminders', 'customer_name') && jeab_copy_column_present($pdo, 'reminders', 'email'), 'DROP stannade efter första kolumnen');
cc(jeab_kontakt_row_phone((array) jeab_kontakt_get($boId)) === '' && ($half['filled']['fields'] ?? -1) === 0, '500 lämnar inga fyllningar');
$batch = (string) ($half['batch_id'] ?? '');
cc(jeab_contacts_drop_done_columns($batch) === ['reminders' => ['customer_name']], 'kolumnen markeras när den är borta');

// Ett nytt värde på kontakten efter planen ska inte tömmas av undo.
$canonCols = (static function () use ($pdo, $root): array {
    require_once $root . '/src/migrate/reminders.php';
    $GLOBALS['_JEAB_MIGRATE_PDO'] = $pdo;
    $cols = jeab_migrate_reminders_module()['columns'];
    unset($GLOBALS['_JEAB_MIGRATE_PDO']);

    return $cols;
})();
cc(jeab_copy_columns_marker($pdo) === [] && !in_array('customer_name', $canonCols, true) && in_array('email', $canonCols, true), 'migreringen filtrerar per saknad kolumn utan markering');

t_drop_undo_key($dropPair);
$undo = jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => $batch], $store);
jeab_copy_columns_forget();
cc(($undo['ok'] ?? false) === true && array_keys((array) ($undo['tables'] ?? [])) === ['reminders'] && ($undo['tables']['reminders']['columns'] ?? []) === ['customer_name'], 'undo återställer kolumnen som hann tas bort');
$r = $pdo->query('SELECT customer_name, email FROM reminders WHERE id = ' . $remId)->fetch(PDO::FETCH_ASSOC);
cc(is_array($r) && $r['customer_name'] === 'Bo Delvis' && $r['email'] === 'bo.delvis@example.test', 'värdet är tillbaka');

// Hel körning, sedan ändras den fyllda telefonen: undo låter den stå.
$full = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
cc(($full['ok'] ?? false) === true && ($full['filled']['fields'] ?? 0) === 1, 'hel körning fyller telefonen');
jeab_kontakter_mutate(static function (array &$contacts) use ($boId): void {
    $contacts[$boId]['owners'][0]['phone'] = jeab_kontakt_phone('0709000033');
});
t_drop_undo_key($dropPair);
$undoFull = jeab_contacts_drop_customer_fields_undo(['dry' => 0, 'batch_id' => (string) $full['batch_id']], $store);
cc(($undoFull['unfilled']['kept'] ?? 0) === 1 && jeab_kontakt_phone(jeab_kontakt_row_phone((array) jeab_kontakt_get($boId))) === jeab_kontakt_phone('0709000033'), 'undo tömmer inte ett ändrat värde');

// .done går inte att skriva: 500, inte tyst.
jeab_copy_columns_forget();
$pdo->prepare("UPDATE reminders SET phone = '0709000033' WHERE id = ?")->execute([$remId]);
$GLOBALS['_JEAB_DROP_MARK_FAIL'] = true;
$noMark = jeab_contacts_drop_customer_fields(['dry' => 0], $store);
unset($GLOBALS['_JEAB_DROP_MARK_FAIL']);
cc(($noMark['status'] ?? 0) === 500 && ($noMark['ok'] ?? true) === false, 'markering som inte kan skrivas ger 500');
cc(($GLOBALS['_JEAB_WEBHOOKS'] ?? []) === [], 'inga webhooks');
echo "ALL OK\n";
