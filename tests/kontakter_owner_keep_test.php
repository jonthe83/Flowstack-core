<?php

declare(strict_types=1);

/**
 * H-KON-1 och H-KON-2. Autospar behåller ägare, personnummer och ägare utanför
 * de två fälten. Spara behåller den andra ägarens mejl och telefon.
 * Spara kan fortfarande ta bort en tom ägare. view_for byter inte namn eller
 * kontaktpersonens mejl. Påhittade värden, inget nummer i utskrift.
 */

$tmp = sys_get_temp_dir() . '/jeab-owner-keep-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('CONTACTS_SOLE=1');
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
$_ENV['CONTACTS_SOLE'] = '1';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function okk(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$pnr1 = '190101010101';
$pnr2 = '190202020202';
$pnr3 = '190303030303';

$saved = jeab_kontakt_save_input([
    'street' => 'Behallgatan 1',
    'postal_code' => '12345',
    'city' => 'Teststad',
    'owner_name_1' => 'Ett Test',
    'owner_name_2' => 'Tva Test',
    'contact_person' => '1',
    'email' => 'ett@example.test',
    'phone' => '0701110001',
    'company' => 'jarfalla',
]);
$id = (string) ($saved['id'] ?? '');
okk(str_starts_with($id, 'kt_'), 'kontakten får ett id');
okk((string) ($saved['owners'][1]['email'] ?? '') === '', 'ny andra ägare har inget mejl att behålla');

jeab_kontakter_mutate(static function (array &$contacts) use ($id, $pnr1, $pnr2, $pnr3): void {
    $row = $contacts[$id];
    $row['owners'][0]['personnummer'] = $pnr1;
    $row['owners'][0]['email'] = 'ett@example.test';
    $row['owners'][0]['phone'] = '+46701110001';
    $row['owners'][1]['personnummer'] = $pnr2;
    $row['owners'][1]['email'] = 'tva@example.test';
    $row['owners'][1]['phone'] = '+46702220002';
    $row['owners'][] = [
        'name' => 'Tredje Test',
        'personnummer' => $pnr3,
        'contact' => false,
        'email' => 'tre@example.test',
        'phone' => '+46703330003',
    ];
    $contacts[$id] = $row;
});

$base = [
    'id' => $id,
    'street' => 'Behallgatan 1',
    'postal_code' => '12345',
    'city' => 'Teststad',
    'owner_name_1' => 'Ett Test',
    'contact_person' => '1',
    'email' => 'ett@example.test',
    'phone' => '0701110001',
    'company' => 'jarfalla',
    'kt_autosave' => '1',
];

$cleared = jeab_kontakt_save_input(array_merge($base, [
    'street' => 'Behallgatan 2',
    'owner_name_2' => '',
    'owner_pnr_1' => $pnr2,
    'owner_pnr_2' => $pnr1,
]));
okk((string) ($cleared['street'] ?? '') === 'Behallgatan 2', 'autospar skriver gatan');
okk(count($cleared['owners'] ?? []) === 3, 'autospar behåller ägare utanför de två fälten');
okk((string) ($cleared['owners'][0]['name'] ?? '') === 'Ett Test', 'autospar behåller ägare 1');
okk((string) ($cleared['owners'][0]['personnummer'] ?? '') === $pnr1, 'autospar skriver inte över personnummer på ägare 1');
okk((string) ($cleared['owners'][1]['name'] ?? '') === 'Tva Test', 'tomt namn på ägare 2 tar inte bort ägaren');
okk((string) ($cleared['owners'][1]['personnummer'] ?? '') === $pnr2, 'autospar behåller personnummer på ägare 2');
okk((string) ($cleared['owners'][1]['email'] ?? '') === 'tva@example.test', 'autospar behåller ägare 2:s mejl');
okk((string) ($cleared['owners'][1]['phone'] ?? '') === '+46702220002', 'autospar behåller ägare 2:s telefon');
okk((string) ($cleared['owners'][2]['name'] ?? '') === 'Tredje Test', 'tredje ägaren ligger kvar');
okk((string) ($cleared['owners'][2]['personnummer'] ?? '') === $pnr3, 'tredje ägarens personnummer ligger kvar');
okk((string) ($cleared['owners'][2]['email'] ?? '') === 'tre@example.test', 'tredje ägarens mejl ligger kvar');
okk(jeab_kontakt_row_email($cleared) === 'ett@example.test', 'kontaktpersonens mejl är fortfarande ägare 1');
okk(jeab_kontakt_row_name($cleared) === 'Ett Test', 'kontaktpersonens namn är fortfarande ägare 1');

$blankFirst = jeab_kontakt_save_input(array_merge($base, [
    'street' => 'Behallgatan 2',
    'owner_name_1' => '',
    'owner_name_2' => '',
]));
okk((string) ($blankFirst['owners'][0]['name'] ?? '') === 'Ett Test', 'autospar återställer ett tömt namn på ägare 1');
okk((string) ($blankFirst['owners'][0]['personnummer'] ?? '') === $pnr1, 'tömt namn tar inte bort personnumret');

$renamed = jeab_kontakt_save_input(array_merge($base, [
    'owner_name_1' => 'Ett Nytt',
    'owner_name_2' => 'Tva Test',
    'email' => 'nytt@example.test',
    'phone' => '0701110009',
]));
okk((string) ($renamed['owners'][0]['name'] ?? '') === 'Ett Nytt', 'autospar skriver ett ifyllt namn');
okk((string) ($renamed['owners'][0]['personnummer'] ?? '') === $pnr1, 'nytt namn behåller personnumret');
okk((string) ($renamed['owners'][0]['email'] ?? '') === 'nytt@example.test', 'kontaktpersonens mejl uppdateras');
okk((string) ($renamed['owners'][1]['email'] ?? '') === 'tva@example.test', 'den andra ägarens mejl rörs inte');
okk((string) ($renamed['owners'][1]['phone'] ?? '') === '+46702220002', 'den andra ägarens telefon rörs inte');

$party = [
    'contact_id' => $id,
    'customer_name' => 'Annat Namn',
    'email' => 'annat@example.test',
    'name' => 'Annat Namn',
];
$view = jeab_contacts_sole_view_for($party);
okk(is_array($view), 'view_for läser kontakten');
okk((string) ($view['name'] ?? '') === 'Ett Nytt', 'view_for visar kontaktpersonens namn');
okk((string) ($view['email'] ?? '') === 'nytt@example.test', 'view_for visar kontaktpersonens mejl');
okk((string) ($view['email'] ?? '') !== 'tva@example.test', 'view_for tar inte den andra ägarens mejl');
$fromContact = jeab_contacts_sole_view_from_contact(jeab_kontakt_get($id) ?? []);
okk((string) ($fromContact['name'] ?? '') === 'Ett Nytt', 'vyn från kontakten använder samma namnregel');
okk((string) ($fromContact['phone'] ?? '') !== '' && (string) ($fromContact['phone'] ?? '') !== '+46702220002', 'vyn använder kontaktpersonens telefon');

$explicit = jeab_kontakt_save_input([
    'id' => $id,
    'street' => 'Behallgatan 2',
    'postal_code' => '12345',
    'city' => 'Teststad',
    'owner_name_1' => 'Ett Nytt',
    'owner_name_2' => 'Tva Test',
    'owner_pnr_1' => '010101-****',
    'owner_pnr_2' => '020202-****',
    'contact_person' => '1',
    'email' => 'nytt@example.test',
    'phone' => '0701110009',
    'company' => 'jarfalla',
]);
okk(count($explicit['owners'] ?? []) === 2, 'Spara tar bort ägare utanför de två fälten');
okk((string) ($explicit['owners'][0]['personnummer'] ?? '') === $pnr1, 'Spara med mask behåller personnummer');
okk((string) ($explicit['owners'][1]['personnummer'] ?? '') === $pnr2, 'Spara med mask behåller ägare 2');
okk((string) ($explicit['owners'][1]['email'] ?? '') === 'tva@example.test', 'Spara tömmer inte ägare 2:s mejl');
okk((string) ($explicit['owners'][1]['phone'] ?? '') === '+46702220002', 'Spara tömmer inte ägare 2:s telefon');

$switched = jeab_kontakt_save_input([
    'id' => $id,
    'street' => 'Behallgatan 2',
    'postal_code' => '12345',
    'city' => 'Teststad',
    'owner_name_1' => 'Ett Nytt',
    'owner_name_2' => 'Tva Test',
    'owner_pnr_1' => '010101-****',
    'owner_pnr_2' => '020202-****',
    'contact_person' => '2',
    'email' => 'tva@example.test',
    'phone' => '0702220002',
    'company' => 'jarfalla',
]);
okk(!empty($switched['owners'][1]['contact']), 'Spara kan byta kontaktperson');
okk((string) ($switched['owners'][1]['email'] ?? '') === 'tva@example.test', 'nya kontaktpersonen får fältets mejl');
okk((string) ($switched['owners'][0]['email'] ?? '') === 'nytt@example.test', 'förra kontaktpersonen behåller sitt mejl');
okk(jeab_kontakt_row_email($switched) === 'tva@example.test', 'radens mejl följer den nya kontaktpersonen');
$switchedView = jeab_contacts_sole_view_for($party);
okk(is_array($switchedView) && (string) ($switchedView['name'] ?? '') === 'Tva Test', 'view_for följer den nya kontaktpersonen');
okk((string) ($switchedView['email'] ?? '') === 'tva@example.test', 'view_for följer den nya kontaktpersonens mejl');

try {
    jeab_kontakt_save_input([
        'id' => $id,
        'street' => 'Behallgatan 2',
        'postal_code' => '12345',
        'city' => 'Teststad',
        'owner_name_1' => '',
        'owner_pnr_1' => '010101-****',
        'owner_name_2' => 'Tva Test',
        'owner_pnr_2' => '020202-****',
        'contact_person' => '2',
        'email' => 'tva@example.test',
        'phone' => '0702220002',
        'company' => 'jarfalla',
    ]);
    okk(false, 'Spara utan namn ska stoppas när personnummerfältet är ifyllt');
} catch (InvalidArgumentException $e) {
    okk($e->getMessage() === 'Skriv namn på ägaren.', 'Spara kräver fortfarande ett namn');
}
$still = jeab_kontakt_get($id);
okk(is_array($still) && (string) ($still['owners'][0]['name'] ?? '') === 'Ett Nytt', 'nekad Spara skriver inte');

$dropped = jeab_kontakt_save_input([
    'id' => $id,
    'street' => 'Behallgatan 2',
    'postal_code' => '12345',
    'city' => 'Teststad',
    'owner_name_1' => 'Ett Nytt',
    'owner_pnr_1' => '010101-****',
    'owner_name_2' => '',
    'owner_pnr_2' => '',
    'contact_person' => '1',
    'email' => 'nytt@example.test',
    'phone' => '0701110009',
    'company' => 'jarfalla',
]);
okk(count($dropped['owners'] ?? []) === 1, 'Spara utan namn och utan personnummer tar bort ägare 2');
okk((string) ($dropped['owners'][0]['name'] ?? '') === 'Ett Nytt', 'ägare 1 ligger kvar');
okk((string) ($dropped['owners'][0]['email'] ?? '') === 'nytt@example.test', 'kontaktpersonens mejl ligger kvar');

$onlyOne = jeab_kontakt_save_input(array_merge($base, [
    'id' => (string) ($dropped['id'] ?? ''),
    'owner_name_2' => '',
    'street' => 'Behallgatan 3',
]));
okk(count($onlyOne['owners'] ?? []) === 1, 'autospar skapar inte en tom ägare 2');
okk((string) ($onlyOne['street'] ?? '') === 'Behallgatan 3', 'autospar av en ägare skriver gatan');

echo "kontakter owner keep ok\n";
