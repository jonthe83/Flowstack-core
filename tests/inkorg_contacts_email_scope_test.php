<?php

declare(strict_types=1);

/** H-INK-8: kundadress byter inte andra kontakter med samma mobil, och fel bolag nekas. */

$tmp = sys_get_temp_dir() . '/jeab-mail-scope-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'test-secret-value',
    'hashes' => [
        ['hash' => 'bare-hash-fixture', 'email' => ''],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ces(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$partner = 'hej@nordsolen.se';
$other = 'noreply@quoted-partner.example';
$next = 'kund.scope@gmail.com';
$phone = '070-111 22 33';
$ing = jeab_inkorg_ingest([
    'message_id' => 'scope-1@partner.test',
    'from' => $partner,
    'from_name' => 'Nordsolen',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Ny lead',
    'body_text' => "Namn: Kim Exempel\nMobil: {$phone}\nVi vill ha batteri.\n",
    'source' => 'graph-jarfalla',
    'thread_key' => 'scope-thread',
    'test' => true,
]);
ces(!empty($ing['ok']), 'tråden finns');
$tid = (string) ($ing['thread_id'] ?? '');
$normPhone = jeab_inkorg_find_mobile($phone);
ces($normPhone !== '', 'testnumret är ett mobilnummer');
file_put_contents(jeab_inkorg_dir() . '/contacts.json', json_encode([
    'contacts' => [
        'c-thread' => ['name' => 'Nordsolen', 'emails' => [$partner], 'mobile' => '', 'address' => '', 'town' => ''],
        'c-other' => ['name' => 'Annan partner', 'emails' => [$other], 'mobile' => $normPhone, 'address' => '', 'town' => ''],
    ],
], JSON_UNESCAPED_UNICODE));
jeab_inkorg_thread_write($tid, static function (array $t) use ($partner): array {
    $t['customer_email'] = $partner;
    return $t;
});

$wet = jeab_inkorg_hook([
    'action' => 'inbox.contact_set_email',
    'thread_id' => $tid,
    'email' => $next,
    'dry' => 0,
]);
$contacts = jeab_inkorg_contacts(true);
$threadMails = array_map('strval', (array) (($contacts['c-thread']['emails'] ?? [])));
$otherMails = array_map('strval', (array) (($contacts['c-other']['emails'] ?? [])));
ces(!empty($wet['ok']) && ($wet['changed'] ?? 0) === 1, 'trådens partneradress byts');
ces(in_array($next, $threadMails, true) && !in_array($partner, $threadMails, true), 'kontakten på tråden får kundens adress');
ces($otherMails === [$other], 'en annan kontakt med samma mobil i brödtexten behåller sin adress');

$row = jeab_create_reminder(jeab_store(), 'Malmö kund', jeab_today_start(), null, null, [
    'customer' => 'Malmö Kund',
    'email' => 'kvar@example.test',
    'company' => 'malmo',
    'who' => 'Kim',
]);
$rid = (int) ($row['id'] ?? 0);
ces($rid > 0, 'påminnelsen finns');
$payload = '1.' . time() . '.ab';
$_COOKIE['jeab_auth'] = $payload . '.' . hash_hmac('sha256', $payload, jeab_auth_secret());
$_SESSION = [];
ces(jeab_auth_ok() && jeab_actor_companies() === [], 'inloggning utan person har inga bolag');
$denied = jeab_inkorg_hook([
    'action' => 'inbox.contact_set_email',
    'reminder_id' => $rid,
    'email' => $next,
    'dry' => 0,
]);
$fresh = jeab_store()->getReminder($rid);
ces(empty($denied['ok']) && ($denied['error'] ?? '') === 'bolag', 'påminnelse utanför personens bolag nekas');
ces(is_array($fresh) && (string) ($fresh['email'] ?? '') === 'kvar@example.test', 'nekad påminnelse behåller e-posten');

echo "contact email scope: ok\n";
