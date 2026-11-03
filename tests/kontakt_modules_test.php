<?php

declare(strict_types=1);

/**
 * Kontaktens moduler läses via contact_id. Kundfält kopieras inte.
 */

$tmp = sys_get_temp_dir() . '/jeab-kontakt-modules-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function km(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$secret = 'HEMLIG-KUND-' . bin2hex(random_bytes(3));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE inkorg_list (id TEXT PRIMARY KEY, contact_id TEXT NOT NULL DEFAULT \'\', list_at TEXT NOT NULL DEFAULT \'\', list_status TEXT NOT NULL DEFAULT \'\', customer_email TEXT NOT NULL DEFAULT \'\', row_json TEXT NOT NULL DEFAULT \'\')');
$pdo->exec('CREATE TABLE reminders (id INTEGER PRIMARY KEY, contact_id TEXT NOT NULL DEFAULT \'\', due_at TEXT, completed_at TEXT, title TEXT, customer_name TEXT, email TEXT, address TEXT)');
$pdo->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY, contact_id TEXT NOT NULL DEFAULT \'\', starts_at TEXT, status TEXT, customer_name TEXT, email TEXT, address TEXT)');
$pdo->exec('CREATE TABLE purchases (reminder_id INTEGER PRIMARY KEY, contact_id TEXT NOT NULL DEFAULT \'\', created_at TEXT, updated_at TEXT, klart INTEGER NOT NULL DEFAULT 0, payload TEXT NOT NULL DEFAULT \'\')');

$ada = 'kt_ada';
$bo = 'kt_bo';
$pdo->prepare('INSERT INTO inkorg_list (id, contact_id, list_at, list_status, customer_email, row_json) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute(['th-ada', $ada, '2026-10-02 09:15:00', 'open', $secret . '@example.test', json_encode(['subject' => 'Tak till Ada', 'customer_name' => $secret])]);
$pdo->prepare('INSERT INTO inkorg_list (id, contact_id, list_at, list_status, customer_email) VALUES (?, ?, ?, ?, ?)')
    ->execute(['th-bo', $bo, '2026-10-03 09:00:00', 'closed', 'bo@example.test']);
$pdo->prepare('INSERT INTO reminders (id, contact_id, due_at, completed_at, title, customer_name, email, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([7, $ada, '2026-09-01 08:00:00', '2026-09-02 10:00:00', 'Tidtagning batteri', $secret, $secret . '@example.test', $secret . 'gatan']);
$pdo->prepare('INSERT INTO reminders (id, contact_id, due_at, customer_name) VALUES (?, ?, ?, ?)')
    ->execute([8, $bo, '2026-09-03 08:00:00', 'Bo Annan']);
$pdo->prepare('INSERT INTO bookings (id, contact_id, starts_at, status, customer_name, email, address) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([3, $ada, '2026-08-11 13:00:00', 'booked', $secret, $secret . '@example.test', $secret . 'gatan']);
$pdo->prepare('INSERT INTO bookings (id, contact_id, starts_at, status, customer_name) VALUES (?, ?, ?, ?, ?)')
    ->execute([4, $bo, '2026-08-12 13:00:00', 'cancelled', 'Bo Annan']);
$payload = json_encode([
    'name' => $secret,
    'email' => $secret . '@example.test',
    'address' => $secret . 'gatan',
    'personnummer' => '199001011234',
    'fullmakt_token' => 'tok-ada',
    'fullmakt_sent_at' => '2026-07-01 11:00:00',
    'fullmakt_signed' => false,
], JSON_UNESCAPED_UNICODE);
$pdo->prepare('INSERT INTO purchases (reminder_id, contact_id, created_at, updated_at, klart, payload) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([11, $ada, '2026-07-01 10:00:00', '2026-07-01 10:00:00', 0, $payload]);
$pdo->prepare('INSERT INTO purchases (reminder_id, contact_id, created_at, updated_at, klart, payload) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([12, $ada, '2026-06-01 10:00:00', '2026-06-01 10:00:00', 1, json_encode(['name' => $secret])]);
$pdo->prepare('INSERT INTO purchases (reminder_id, contact_id, created_at, updated_at, klart, payload) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([13, $bo, '2026-05-01 10:00:00', '2026-05-01 10:00:00', 0, json_encode(['fullmakt_token' => 'tok-bo', 'name' => 'Bo'])]);

$groups = jeab_kontakt_modules($pdo, $ada);
$blob = json_encode($groups, JSON_UNESCAPED_UNICODE);
km(is_string($blob) && !str_contains($blob, $secret) && !str_contains($blob, '199001011234'), 'listan innehåller inte kundens namn, e-post, adress eller personnummer');
km(count($groups['inkorg']) === 1 && $groups['inkorg'][0]['id'] === 'th-ada', 'inkorgen läser bara den här kontaktens contact_id');
km($groups['inkorg'][0]['date'] === '2026-10-02 09:15' && $groups['inkorg'][0]['status'] === 'Öppen' && $groups['inkorg'][0]['label'] === 'Tak till Ada' && str_contains($groups['inkorg'][0]['href'], 'p=inkorg') && str_contains($groups['inkorg'][0]['href'], 'th-ada'), 'inkorgsraden har datum, status, ärende och länk');
km(count($groups['paminnelse']) === 1 && $groups['paminnelse'][0]['id'] === '7' && $groups['paminnelse'][0]['status'] === 'Klar' && $groups['paminnelse'][0]['label'] === 'Tidtagning batteri' && str_contains($groups['paminnelse'][0]['href'], 'open=7'), 'påminnelsen länkas via contact_id');
km(count($groups['kalender']) === 1 && $groups['kalender'][0]['status'] === 'Bokad' && str_contains($groups['kalender'][0]['href'], 'p=bookings') && str_contains($groups['kalender'][0]['href'], 'open=3'), 'kalendern länkas via contact_id');
km(count($groups['order']) === 2 && $groups['order'][0]['id'] === '11' && $groups['order'][0]['status'] === 'Öppen' && $groups['order'][1]['status'] === 'Klar' && str_contains($groups['order'][0]['href'], 'p=ordrar') && str_contains($groups['order'][0]['href'], 'id=11'), 'ordrarna länkas via contact_id');
km(count($groups['fullmakt']) === 1 && $groups['fullmakt'][0]['status'] === 'Skickad' && str_contains($groups['fullmakt'][0]['href'], 'id=11'), 'fullmakten läses från orderns contact_id');
km(!str_contains($blob, 'tok-bo') && !str_contains($blob, 'th-bo'), 'en annan kontakts rader följer inte med');

$empty = jeab_kontakt_modules($pdo, 'kt_tom');
$html = jeab_kontakt_modules_html($empty);
foreach (['Inkorg', 'Påminnelse', 'Kalender', 'Order', 'Fullmakt'] as $label) {
    km(str_contains($html, '>' . $label . '<'), 'gruppen ' . $label . ' finns');
}
km(substr_count($html, '>Inga<') === 5, 'tomma grupper säger Inga');
km(!str_contains($html, $secret), 'tom html kopierar ingen kundtext');

$filled = jeab_kontakt_modules_html($groups);
km(str_contains($filled, '2026-10-02 09:15') && str_contains($filled, 'Öppen') && str_contains($filled, 'Tak till Ada') && str_contains($filled, 'Tidtagning batteri') && str_contains($filled, 'p=inkorg'), 'html länkar inkorgsraden');
km(substr_count($filled, '>Inga<') === 0, 'fyllda grupper har ingen Inga-rad');
km(!str_contains($filled, $secret) && !str_contains($filled, '199001011234'), 'html kopierar inte kunddata');

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/paminnelser.css');
$mobile = strpos($css, 'html body.page-reminders.page-kontakter .kt-module-row');
$desktop = strpos($css, '@media (min-width: 769px)');
km($mobile !== false && str_contains(substr($css, $mobile, 400), 'flex-direction: column'), 'mobil staplar modulraden');
km($desktop !== false && $desktop > $mobile, 'datorn får raden efter den staplade grundlayouten');

file_put_contents($tmp . '/modules.html', $filled);
echo "kontakt modules ok\n";
