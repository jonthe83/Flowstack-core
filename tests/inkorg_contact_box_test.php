<?php

declare(strict_types=1);

/** Kontaktboxen på en hs-tråd visar sparad kundadress, inte en partner som hittas via telefon. */

$tmp = sys_get_temp_dir() . '/jeab-contact-box-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function cb(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$from = 'leads@hitta-solceller.se';
$partner = 'hej@nordsolen.se';
$customer = 'kim.box@gmail.com';
$phone = '+46701234567';
$ing = jeab_inkorg_ingest([
    'message_id' => 'hs-promote-44110-m1',
    'from' => $from,
    'from_name' => 'Hitta-Solceller',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Ny förfrågan',
    'body_text' => "Namn: Kim Exempel\nTelefon: 070-123 45 67\nVi vill ha batteri.\n",
    'source' => 'helpscout_import',
    'thread_key' => 'hs-44110',
    'hs_conversation_id' => '44110',
    'company' => 'jarfalla',
    'test' => true,
]);
cb(!empty($ing['ok']) && (string) ($ing['thread_id'] ?? '') === 'hs-44110', 'hs-tråden finns');
jeab_inkorg_thread_write('hs-44110', static function (array $t) use ($customer): array {
    $t['customer_email'] = $customer;
    $t['customer_source'] = 'hook';
    $t['customer_name'] = 'Kim Exempel';
    $t['import_source'] = 'helpscout_import';
    $t['helpscout_import'] = true;
    $t['no_auto'] = true;
    return $t;
});
file_put_contents(jeab_inkorg_dir() . '/contacts.json', json_encode([
    'contacts' => [
        'c-phone' => ['name' => 'Portalen', 'emails' => [$partner], 'mobile' => $phone, 'address' => '', 'town' => ''],
        'c-other' => ['name' => 'Annan', 'emails' => [$partner], 'mobile' => '+46709999999', 'address' => '', 'town' => ''],
        'c-real' => ['name' => 'Maja', 'emails' => ['maja.kund@gmail.com'], 'mobile' => $phone, 'address' => '', 'town' => ''],
    ],
], JSON_UNESCAPED_UNICODE));

$open = null;
foreach (jeab_inkorg_threads() as $t) {
    if ((string) ($t['id'] ?? '') === 'hs-44110') {
        $open = $t;
        break;
    }
}
cb(is_array($open), 'tråden går att öppna');
$found = jeab_inkorg_contact_for_thread($open);
cb($found === null, 'känd avsändare binder inte en partner via telefon');
$lines = jeab_inkorg_contact_box($open, $found, ['email' => $customer, 'phone' => '', 'name' => 'Kim Exempel']);
cb($lines['emails'] === [$customer] && $lines['phone'] === $phone, 'boxen visar sparad kundadress och telefonen i mejlet');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'inkorg', 'view' => 'v-historik', 'id' => 'hs-44110'];
require_once dirname(__DIR__) . '/src/layout.php';
$html = require dirname(__DIR__) . '/src/pages/inkorg.php';
$at = strpos((string) $html, 'aria-label="Kund"');
$box = $at === false ? '' : substr((string) $html, $at, 1800);
cb($box !== '' && str_contains($box, 'Kontakt') && str_contains($box, $customer) && str_contains($box, $phone) && !str_contains($box, $partner), 'renderad kontaktbox visar kunden och telefonen i mejlet');

$otherBefore = jeab_inkorg_contacts(true)['c-other']['emails'] ?? [];
$realBefore = jeab_inkorg_contacts(true)['c-real']['emails'] ?? [];
$dry = jeab_inkorg_hook(['action' => 'inbox.contact_set_email', 'thread_id' => 'hs-44110', 'email' => $customer]);
cb(($dry['changed'] ?? 1) === 0 && ($dry['would_change'] ?? 0) === 1 && ($dry['inbox'] ?? 0) === 1 && (string) (jeab_inkorg_contacts(true)['c-phone']['emails'][0] ?? '') === $partner, 'torrkörning ser partnerkontakten och skriver inget');
$wet = jeab_inkorg_hook(['action' => 'inbox.contact_set_email', 'thread_id' => 'hs-44110', 'email' => $customer, 'dry' => 0]);
$phoneRow = jeab_inkorg_contacts(true)['c-phone'] ?? [];
cb(($wet['inbox'] ?? 0) === 1 && in_array($customer, array_map('strval', (array) ($phoneRow['emails'] ?? [])), true) && !in_array($partner, array_map('strval', (array) ($phoneRow['emails'] ?? [])), true), 'hooken byter partneradressen på telefonträffen');
cb((jeab_inkorg_contacts(true)['c-other']['emails'] ?? []) === $otherBefore, 'en annan post med samma partneradress lämnas');
cb((jeab_inkorg_contacts(true)['c-real']['emails'] ?? []) === $realBefore, 'en riktig adress på samma telefon lämnas');

$_GET = ['p' => 'inkorg', 'view' => 'v-historik', 'id' => 'hs-44110'];
$html2 = require dirname(__DIR__) . '/src/pages/inkorg.php';
$at2 = strpos((string) $html2, 'aria-label="Kund"');
$box2 = $at2 === false ? '' : substr((string) $html2, $at2, 1800);
cb(str_contains($box2, $customer) && !str_contains($box2, $partner), 'kontaktboxen visar inte partnern efter bytet');

echo "contact box ok\n";
