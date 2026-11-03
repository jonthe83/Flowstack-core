<?php

declare(strict_types=1);

/**
 * reminder.create_manual. dry=1 skriver inget. dry=0 skapar påminnelse och
 * kontakt samma väg som ett lead, utan offert, bekräftelse eller sms.
 * Samma e-post och adress inom 24 timmar återanvänder raden.
 * Påhittade kunder. Svaret maskerar e-post, telefon och personnummer.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-rem-manual-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function cm(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_MANUAL_SENT'] = 0;
$GLOBALS['JEAB_GRAPH_SEND'] = static function (): array {
    $GLOBALS['_MANUAL_SENT']++;

    return ['ok' => true];
};

file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-manual-test']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';

$store = jeab_store();
cm($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
$pdo = $store->pdo();

$email = 'ada.manual.hook@gmail.com';
$phone = '0701112233';
$address = 'Manualvägen 12, 177 31 Järfälla';
$pnr = '198001011234';
$base = [
    'action' => 'reminder.create_manual',
    'office' => 'jarfalla',
    'owner' => 'Elin',
    'product' => 'okänd produkt',
    'name' => 'Ada Manual',
    'email' => $email,
    'phone' => $phone,
    'address' => $address,
    'note' => 'Ring inte. Ref ' . $pnr,
];

$denied = jeab_reminder_admin_dispatch($store, array_replace($base, ['dry' => 0]));
cm(($denied['status'] ?? 0) === 401 && empty($denied['ok']), 'utan token blir det 401');
cm((int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn() === 0, 'utan token skapas ingen påminnelse');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-manual-test';

$before = (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn();
$dry = jeab_reminder_admin_dispatch($store, $base);
cm(!empty($dry['ok']) && (int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['sent'] ?? 1) === 0 && (int) ($dry['written'] ?? 1) === 0 && (int) ($dry['created'] ?? 1) === 0, 'utelämnad dry skriver inget och sent är 0');
cm((int) ($dry['reminder']['id'] ?? 1) === 0 && ($dry['reminder']['product'] ?? '') === 'okänd produkt' && ($dry['reminder']['title'] ?? '') === 'Ada Manual – okänd produkt', 'dry visar titeln som skulle skapas');
cm((int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn() === $before, 'dry lämnar tabellen orörd');
cm(jeab_reminder_admin_audit_list() === [], 'dry skriver ingen audit');

$blob = json_encode($dry, JSON_UNESCAPED_UNICODE);
cm(is_string($blob) && !str_contains($blob, $email) && !str_contains($blob, $phone) && !str_contains($blob, '0701112233') && !str_contains($blob, $pnr), 'dry-svaret maskerar e-post, telefon och personnummer');
cm(is_string($blob) && str_contains($blob, 'a***@gmail.com'), 'dry visar maskerad e-post');

$badOffice = jeab_reminder_admin_dispatch($store, array_replace($base, ['office' => 'uppsala', 'dry' => 0]));
cm(empty($badOffice['ok']) && ($badOffice['status'] ?? 0) === 422, 'okänt kontor avvisas');
$badPhone = jeab_reminder_admin_dispatch($store, array_replace($base, ['phone' => '123', 'dry' => 0]));
cm(empty($badPhone['ok']) && ($badPhone['status'] ?? 0) === 422 && (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn() === $before, 'ogiltigt nummer skapar inget');
$wrongOwner = jeab_reminder_admin_dispatch($store, array_replace($base, ['owner' => 'Sam W', 'dry' => 0]));
cm(empty($wrongOwner['ok']) && ($wrongOwner['status'] ?? 0) === 422, 'ägare från andra kontoret avvisas');

$known = jeab_kontakt_find_or_create([
    'name' => 'Ada Manual',
    'email' => $email,
    'phone' => '+46701112233',
    'address' => $address,
], false);
cm(is_array($known) && (string) ($known['id'] ?? '') !== '', 'kontakten finns redan');
$knownId = (string) $known['id'];

$wet = jeab_reminder_admin_dispatch($store, array_replace($base, ['dry' => 0]));
cm(!empty($wet['ok']) && (int) ($wet['dry'] ?? 1) === 0 && (int) ($wet['sent'] ?? 1) === 0 && (int) ($wet['created'] ?? 0) === 1 && (int) ($wet['reused'] ?? 1) === 0, 'dry=0 skapar påminnelsen och sent är 0');
$id = (int) ($wet['reminder']['id'] ?? 0);
cm($id > 0 && (string) ($wet['contact_id'] ?? '') === $knownId, 'samma kontaktmatchning som leadflödet');
$row = jeab_get_reminder($store, $id);
cm(is_array($row) && (string) ($row['company'] ?? '') === 'jarfalla' && (string) ($row['person'] ?? '') === 'Elin', 'kontor och ägare sparas');
cm((string) ($row['phone'] ?? '') === '+46701112233', 'telefonen normaliseras');
$wetBlob = json_encode($wet, JSON_UNESCAPED_UNICODE);
cm(is_string($wetBlob) && !str_contains($wetBlob, $email) && !str_contains($wetBlob, '+46701112233') && !str_contains($wetBlob, $pnr) && str_contains($wetBlob, 'a***@gmail.com'), 'skapatsvaret maskerar e-post, telefon och personnummer');
$audit = jeab_reminder_admin_audit_list();
cm(count($audit) === 1 && (string) ($audit[0]['action'] ?? '') === 'create_manual' && (string) ($audit[0]['new_owner'] ?? '') === 'Elin' && (string) ($audit[0]['contact_id'] ?? '') === $knownId, 'auditrad create_manual');

$again = jeab_reminder_admin_dispatch($store, array_replace($base, ['dry' => 0, 'product' => 'batteri', 'note' => 'annan']));
cm(!empty($again['ok']) && (int) ($again['reused'] ?? 0) === 1 && (int) ($again['created'] ?? 1) === 0 && (int) ($again['reminder']['id'] ?? 0) === $id, 'samma e-post och adress inom 24 timmar återanvänds');
cm(count(jeab_reminder_admin_audit_list()) === 1 && (int) $pdo->query('SELECT COUNT(*) FROM reminders')->fetchColumn() === 1, 'återanvändning skriver ingen ny rad');

$other = jeab_reminder_admin_dispatch($store, array_replace($base, ['dry' => 0, 'address' => 'Annanvägen 3, 177 32 Järfälla']));
$otherId = (int) ($other['reminder']['id'] ?? 0);
cm(!empty($other['ok']) && (int) ($other['created'] ?? 0) === 1 && $otherId > 0 && $otherId !== $id, 'annan adress blir en ny påminnelse');

$pdo->prepare('UPDATE reminders SET created_at = ? WHERE id = ?')->execute([
    (new DateTimeImmutable('-25 hours'))->format('Y-m-d H:i:s'),
    $id,
]);
$later = jeab_reminder_admin_dispatch($store, array_replace($base, ['dry' => 0]));
$laterId = (int) ($later['reminder']['id'] ?? 0);
cm(!empty($later['ok']) && (int) ($later['created'] ?? 0) === 1 && $laterId > 0 && $laterId !== $id, 'samma e-post och adress efter 24 timmar skapar en ny rad');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/reminder_admin.php');
$fn = strstr($src, 'function jeab_reminder_create_manual');
cm(is_string($fn) && !str_contains($fn, 'jeab_email_send') && !str_contains($fn, 'jeab_sms_send') && !str_contains($fn, 'jeab_auto_quote') && !str_contains($fn, 'jeab_notiser_send') && !str_contains($fn, 'jeab_outbound_graph_send'), 'skapandet anropar ingen sändfunktion');
$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
cm(str_contains($hook, "'reminder.create_manual'") && str_contains($hook, 'jeab_reminder_admin_dispatch'), 'hook.php tar reminder.create_manual med samma Bearer-väg');
cm((int) $GLOBALS['_MANUAL_SENT'] === 0, 'inget mejl skickades');

echo "OK\n";
