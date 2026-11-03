<?php

declare(strict_types=1);

/**
 * V4: #vunnen utan Bekräfta skapar ordern men bockar inte av påminnelsen till Klara.
 * Påhittad testkund. Inget utskick.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-v4-tagg-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
jeab_ordrar_external_live(false);

function v4_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$contact = jeab_kontakt_find_or_create([
    'name' => 'Ada Vunnen',
    'email' => 'ada.vunnen@example.test',
    'phone' => '0701000094',
    'address' => 'Testgatan 1, 177 40 Järfälla',
    'city' => 'Järfälla',
    'postal_code' => '17740',
], false);
v4_ok(is_array($contact), 'testkontakten finns');

$rem = jeab_create_reminder($store, 'Batteri', '2026-11-02 00:00:00', 'batteri', null, [
    'customer' => 'Ada Vunnen',
    'company' => 'jarfalla',
    'assignee' => 'Elin',
    'address' => 'Testgatan 1, Järfälla',
    'tags' => ['vunnen'],
    'contact_id' => (string) $contact['id'],
]);
$rid = (int) ($rem['id'] ?? 0);
v4_ok($rid > 0, 'påminnelsen skapades');

$purchase = jeab_ordrar_vunnen_from_tag($store, $rem);
v4_ok((int) ($purchase['reminder_id'] ?? 0) === $rid, 'taggning skapar ordern');
v4_ok(is_array(jeab_purchase_get($rid)), 'ordern ligger kvar');

$after = jeab_get_reminder($store, $rid);
v4_ok(is_array($after) && !empty($after['completed_at']), 'taggning klarmarkerar påminnelsen efter ordern');
v4_ok(in_array('vunnen', jeab_parse_tags($after['tags'] ?? []), true), 'vunnen-taggen ligger kvar');

echo "ordrar v4 tagg: ok\n";
