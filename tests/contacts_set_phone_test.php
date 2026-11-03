<?php

declare(strict_types=1);

/**
 * contacts.set_phone är torr som standard. dry=0 skriver en ångringsfil
 * chmod 0600 under data/.private och rör bara telefonen. Svaret har hash,
 * inte numret. Ångring sätter tillbaka. Inget mejl, inget SMS, ingen webhook.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-set-phone-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
$_ENV['DATA_BACKEND'] = 'mysql';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ph(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
ph($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');
ph(jeab_kontakter_use_sql(), 'kontakterna ligger i SQL');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-set-phone']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_contacts_set_phone_dispatch([
    'action' => 'contacts.set_phone',
    'contact_id' => 'saknas',
    'phone' => '0701000801',
]);
ph(($denied['status'] ?? 0) === 401 && ($denied['ok'] ?? true) === false && ($denied['sent'] ?? 1) === 0 && ($denied['webhook'] ?? 1) === 0, 'utan token ger 401');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-set-phone';

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/contacts_set_phone.php');
ph(str_contains($hookSrc, "'contacts.set_phone'") && str_contains($hookSrc, "'contacts.set_phone_undo'"), 'hook.php registrerar båda krokarna');
ph(!preg_match('/jeab_[a-z_]*(mail|sms)/i', $src), 'kroken anropar varken mejl eller SMS');
ph(!preg_match('/jeab_[a-z_]*webhook/i', $src), 'kroken anropar ingen webhook');

$now = jeab_now();
jeab_kontakter_mutate(static function (array &$contacts) use ($now): void {
    $contacts['c-phone'] = [
        'id' => 'c-phone',
        'phone' => '0701000800',
        'owners' => [[
            'name' => 'Bo Telefon',
            'contact' => true,
            'email' => 'bo.telefon@example.com',
            'phone' => '0701000800',
            'personnummer' => '198001011234',
        ]],
        'created_at' => $now,
        'updated_at' => $now,
    ];
});

$dry = jeab_contacts_set_phone_dispatch([
    'action' => 'contacts.set_phone',
    'contact_id' => 'c-phone',
    'phone' => '070-100 08 01',
]);
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
ph(($dry['status'] ?? 0) === 200 && ($dry['dry'] ?? 0) === 1 && ($dry['written'] ?? 1) === 0, 'utan dry är körningen torr');
ph(is_string($dryJson) && !str_contains($dryJson, '0701000801') && !str_contains($dryJson, '070-100') && !str_contains($dryJson, '+46701000801'), 'torrkörningen har ingen klartext');
ph(($dry['phone_hash'] ?? '') === hash('sha256', '+46701000801'), 'torrkörningen har hash av normaliserat nummer');
$still = jeab_kontakt_fetch('c-phone');
ph(is_array($still) && jeab_kontakt_row_phone($still) === '+46701000800', 'torrkörningen skriver inte');

$bad = jeab_contacts_set_phone_dispatch([
    'action' => 'contacts.set_phone',
    'contact_id' => 'c-phone',
    'phone' => 'inte-ett-nummer',
    'dry' => 0,
]);
$badJson = json_encode($bad, JSON_UNESCAPED_UNICODE);
ph(($bad['status'] ?? 0) === 422 && is_string($badJson) && !str_contains($badJson, 'inte-ett-nummer'), 'ogiltigt nummer blir 422 utan klartext');

$wet = jeab_contacts_set_phone_dispatch([
    'action' => 'contacts.set_phone',
    'contact_id' => 'c-phone',
    'phone' => '0701000801',
    'dry' => 0,
]);
$wetJson = json_encode($wet, JSON_UNESCAPED_UNICODE);
ph(($wet['ok'] ?? false) === true && ($wet['written'] ?? 0) === 1 && ($wet['dry'] ?? 1) === 0, 'dry=0 skriver');
ph(is_string($wetJson) && !str_contains($wetJson, '0701000801') && !str_contains($wetJson, '+46701000801') && !str_contains($wetJson, '198001011234'), 'svaret har varken nummer eller personnummer');
$batch = (string) ($wet['batch'] ?? '');
ph(preg_match('/^[a-f0-9]{32}$/', $batch) === 1, 'batchen är 32 hex');
$path = jeab_data_dir() . '/contacts-set-phone/' . $batch . '.json';
ph(is_file($path) && str_starts_with($path, jeab_data_dir() . '/'), 'ångringsfilen ligger under den privata datakatalogen');
ph((fileperms($path) & 0777) === 0600, 'ångringsfilen är chmod 0600');
ph((fileperms(dirname($path)) & 0777) === 0700, 'ångringskatalogen är chmod 0700');
$undoRaw = (string) file_get_contents($path);
ph(!str_contains($undoRaw, '198001011234'), 'ångringsfilen har inget personnummer');
$saved = jeab_kontakt_fetch('c-phone');
ph(is_array($saved) && jeab_kontakt_row_phone($saved) === '+46701000801', 'numret är sparat');
ph((string) ($saved['owners'][0]['email'] ?? '') === 'bo.telefon@example.com', 'mejl och övriga fält står kvar');

$undoDry = jeab_contacts_set_phone_dispatch([
    'action' => 'contacts.set_phone_undo',
    'batch' => $batch,
]);
ph(($undoDry['dry'] ?? 0) === 1 && ($undoDry['written'] ?? 1) === 0 && is_file($path), 'ångring utan dry skriver inte');

$undo = jeab_contacts_set_phone_dispatch([
    'action' => 'contacts.set_phone_undo',
    'batch' => $batch,
    'dry' => 0,
]);
$undoJson = json_encode($undo, JSON_UNESCAPED_UNICODE);
ph(($undo['ok'] ?? false) === true && ($undo['written'] ?? 0) === 1 && !is_file($path), 'ångring tar tillbaka och tar bort filen');
ph(is_string($undoJson) && !str_contains($undoJson, '0701000800') && !str_contains($undoJson, '+46701000800'), 'ångringssvaret har ingen klartext');
$back = jeab_kontakt_fetch('c-phone');
ph(is_array($back) && jeab_kontakt_row_phone($back) === '+46701000800', 'gamla numret är tillbaka');

$slip = jeab_contacts_set_phone_dispatch([
    'action' => 'contacts.set_phone_undo',
    'batch' => '../' . $batch,
    'dry' => 0,
]);
ph(($slip['status'] ?? 0) === 422, 'batch med sökväg avvisas');

echo "OK  contacts.set_phone\n";
