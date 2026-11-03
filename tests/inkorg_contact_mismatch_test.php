<?php

declare(strict_types=1);

/** Kundrutan matchar exakt avsändar-e-post. Testkontakter kopplas aldrig till en riktig kund. */

$tmp = sys_get_temp_dir() . '/jeab-contact-mismatch-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function mm(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$real = 'riktig.kund@exempel.test';
$testMail = 'test@test.se';
$phone = '+46707654321';
file_put_contents(jeab_inkorg_dir() . '/contacts.json', json_encode([
    'contacts' => [
        'c-test' => [
            'name' => 'Test Testsson',
            'emails' => [$testMail],
            'email' => $testMail,
            'mobile' => $phone,
            'address' => 'Testgatan 1',
            'street' => 'Testgatan 1',
            'town' => 'Teststad',
            'city' => 'Teststad',
            'test' => true,
            'source' => 'test',
        ],
        'c-real' => [
            'name' => 'Riktig Kund',
            'emails' => [$real],
            'email' => $real,
            'mobile' => '',
            'address' => 'Testgatan 1',
            'street' => 'Testgatan 1',
            'town' => 'Teststad',
            'city' => 'Teststad',
        ],
    ],
], JSON_UNESCAPED_UNICODE));

$ing = jeab_inkorg_ingest([
    'message_id' => 'mm-real-1@exempel.test',
    'from' => $real,
    'from_name' => 'Riktig Kund',
    'to' => 'support@exempel.test',
    'subject' => 'Fråga om service',
    'body_text' => "Hej.\nTelefon: 070-765 43 21\nTestgatan 1\nTeststad\n",
    'received_at' => '2026-10-18T11:00:00Z',
    'source' => 'bridge',
    'thread_key' => 'mm-real',
]);
mm(!empty($ing['ok']), 'riktig avsändare ger en tråd');
jeab_inkorg_thread_write('mm-real', static function (array $t): array {
    $t['contact_id'] = 'c-test';
    $t['customer_email'] = '';
    return $t;
});

$open = null;
foreach (jeab_inkorg_threads() as $t) {
    if ((string) ($t['id'] ?? '') === 'mm-real') {
        $open = $t;
        break;
    }
}
mm(is_array($open), 'tråden går att läsa');
$found = jeab_inkorg_contact_for_thread($open);
mm(is_array($found) && (string) ($found['id'] ?? '') === 'c-real', 'exakt avsändar-e-post vinner');
$panel = jeab_inkorg_panel_contact($open);
mm($panel === null || (string) ($panel['id'] ?? '') !== 'c-test', 'sparad testkontakt visas inte för den riktiga kunden');

$dry = jeab_inkorg_hook(['action' => 'inkorg.contact_mismatch_scan']);
$blob = json_encode($dry, JSON_UNESCAPED_UNICODE);
mm(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1 && ($dry['would_send'] ?? true) === false, 'torrkörning är standard och skickar inget');
mm(($dry['threads'] ?? []) === ['mm-real'] && ($dry['count'] ?? 0) === 1, 'torrkörning listar tråd-id');
mm(is_string($blob) && !str_contains($blob, '@'), 'svaret innehåller ingen e-post');
mm((string) (jeab_inkorg_threads_meta()['mm-real']['contact_id'] ?? '') === 'c-test', 'torrkörning kopplar inte bort');

$blocked = jeab_inkorg_hook(['action' => 'inkorg.contact_mismatch_scan', 'dry' => 0]);
mm(($blocked['ok'] ?? true) === false && ($blocked['error'] ?? '') === 'confirm' && ($blocked['would_send'] ?? true) === false, 'utan bekräftelse skrivs inget');
mm((string) (jeab_inkorg_threads_meta()['mm-real']['contact_id'] ?? '') === 'c-test', 'kontaktkopplingen ligger kvar');

$wet = jeab_inkorg_hook(['action' => 'inbox.contact_mismatch_scan', 'dry' => '0', 'confirm' => 'SAM']);
mm(($wet['ok'] ?? false) === true && ($wet['threads'] ?? []) === ['mm-real'] && ($wet['would_send'] ?? true) === false, 'bekräftad körning listar samma tråd');
mm((string) (jeab_inkorg_threads_meta()['mm-real']['contact_id'] ?? 'x') === '', 'fel kontakt kopplas bort');

jeab_inkorg_thread_write('mm-real', static function (array $t): array {
    $t['contact_id'] = 'c-real';
    return $t;
});
$exact = jeab_inkorg_hook(['action' => 'inkorg.contact_mismatch_scan', 'dry' => 1]);
mm(($exact['threads'] ?? ['x']) === [], 'exakt e-post är inte en felkoppling');

$testThread = jeab_inkorg_ingest([
    'message_id' => 'mm-test-1@test.se',
    'from' => $testMail,
    'from_name' => 'Test Testsson',
    'to' => 'support@exempel.test',
    'subject' => 'Test',
    'body_text' => 'Detta är ett testärende.',
    'received_at' => '2026-10-18T12:00:00Z',
    'source' => 'bridge',
    'thread_key' => 'mm-test',
]);
mm(!empty($testThread['ok']), 'testavsändare ger en tråd');
jeab_inkorg_thread_write('mm-test', static function (array $t): array {
    $t['contact_id'] = 'c-test';
    return $t;
});
$testScan = jeab_inkorg_hook(['action' => 'inkorg.contact_mismatch_scan']);
mm(!in_array('mm-test', (array) ($testScan['threads'] ?? []), true), 'testkontakt mot testavsändare lämnas');

$contacts = [
    'c-test' => [
        'name' => 'Test Testsson',
        'email' => $testMail,
        'owners' => [['name' => 'Test Testsson', 'email' => $testMail]],
        'street' => 'Testgatan 1',
        'city' => 'Teststad',
        'test' => true,
    ],
];
$byAddress = jeab_kontakt_dedupe_match_contact($contacts, [
    'name' => 'Riktig Kund',
    'email' => $real,
    'owners' => [['name' => 'Riktig Kund', 'email' => $real]],
    'street' => 'Testgatan 1',
    'city' => 'Teststad',
]);
mm($byAddress === null, 'samma adress gör inte testkontakten till träff');
$byMail = jeab_kontakt_dedupe_match_contact($contacts, [
    'name' => 'Riktig Kund',
    'email' => $testMail,
    'owners' => [['name' => 'Test Testsson', 'email' => $testMail]],
    'street' => 'Testgatan 1',
    'city' => 'Teststad',
]);
mm($byMail === 'c-test', 'samma testadress får fortfarande träffa testkontakten');

echo "contact mismatch ok\n";
