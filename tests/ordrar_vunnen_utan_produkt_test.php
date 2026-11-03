<?php

declare(strict_types=1);

/**
 * #vunnen utan produkt skapar ändå ordern.
 * Kontakten och påminnelsen ligger kvar. Produkten är tom.
 * Första steget i Ordrar v3 kräver produktval.
 * Fortnox, bokning och kundutskick körs inte förrän produkten är vald.
 */

$tmp = sys_get_temp_dir() . '/jeab-vunnen-utan-produkt-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['JEAB_FORTNOX_WRITES'] = 0;
$GLOBALS['_JEAB_FORTNOX_CREATE'] = static function (array $row): array {
    $GLOBALS['JEAB_FORTNOX_WRITES'] = (int) $GLOBALS['JEAB_FORTNOX_WRITES'] + 1;

    return $row;
};
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
jeab_ordrar_external_live(false);

function vup(bool $ok, string $message): void
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
    'name' => 'Kim Utanprodukt',
    'email' => 'kim.utanprodukt@example.test',
    'phone' => '0701000193',
    'address' => 'Tomproduktgatan 4, 111 22 Teststad',
    'city' => 'Teststad',
    'postal_code' => '11122',
], false);
vup(is_array($contact) && trim((string) ($contact['id'] ?? '')) !== '', 'testkontakten finns');
$contactId = (string) $contact['id'];

$now = '2026-11-01 12:00:00';
$reminder = $store->insertReminder([
    'title' => 'Uppföljning',
    'company' => 'jarfalla',
    'customer_name' => 'Kim Utanprodukt',
    'email' => 'kim.utanprodukt@example.test',
    'phone' => '0701000193',
    'tags' => [],
    'note' => 'ring imorgon',
    'created_at' => $now,
    'updated_at' => $now,
]);
$rid = (int) ($reminder['id'] ?? 0);
vup($rid > 0, 'påminnelsen finns');
$reminder = $store->saveReminder($rid, ['contact_id' => $contactId]);
vup(is_array($reminder), 'kontakten är kopplad');

$source = jeab_ordrar_vunnen_source($reminder);
vup(trim((string) ($source['kind'] ?? 'x')) === '', 'källan har ingen produkt');
vup((string) ($source['contact_id'] ?? '') === $contactId, 'källan har kontakt-id');
vup(jeab_ordrar_vunnen_gaps($source, $reminder) === [], 'saknad produkt är ingen lucka');
vup(jeab_ordrar_vunnen_need_label($reminder) === '', 'knappen kräver inte produkt');
$form = jeab_reminder_row($reminder, true);
$vunnenAt = strpos($form, 'data-js="vunnen"');
vup($vunnenAt !== false, 'Vunnen-knappen finns');
$formStart = strrpos(substr($form, 0, $vunnenAt), '<form');
$formEnd = strpos($form, '</form>', $vunnenAt);
vup($formStart !== false && $formEnd !== false, 'Vunnen-formuläret finns');
$vunnenForm = substr($form, $formStart, $formEnd + 7 - $formStart);
vup(!str_contains($vunnenForm, 'data-vunnen-need') && !str_contains($vunnenForm, 'Saknar produkt'), 'saknad produkt blockerar inte klicket');

$booksBefore = count($store->listAllBookings());
$reminder = $store->saveReminder($rid, ['tags' => ['vunnen']]);
vup(is_array($reminder), 'vunnen är på');
$order = jeab_ordrar_vunnen_from_tag($store, $reminder);
vup((int) ($order['reminder_id'] ?? 0) === $rid, 'ordern pekar på påminnelsen');
vup((string) ($order['contact_id'] ?? '') === $contactId, 'ordern har samma kontakt');
vup(trim((string) ($order['kind'] ?? 'satt')) === '', 'produktfältet är tomt');
vup((int) ($order['booking_id'] ?? 0) === 0, 'ingen bokning skapas');
vup(trim((string) ($order['fortnox_order'] ?? '')) === '', 'ingen Fortnox-order skapas');
vup((int) $GLOBALS['JEAB_FORTNOX_WRITES'] === 0, 'Fortnox anropas inte');
vup(count($store->listAllBookings()) === $booksBefore, 'kalendern är orörd');
$booked = 0;
foreach (jeab_calendar_log_read()['entries'] ?? [] as $entry) {
    if (is_array($entry) && (string) ($entry['type'] ?? '') === 'booked') {
        $booked++;
    }
}
vup($booked === 0, 'inget kundutskick via kalenderloggen');

$live = jeab_get_reminder($store, $rid);
vup(
    is_array($live)
        && (string) ($live['contact_id'] ?? '') === $contactId
        && in_array('vunnen', jeab_parse_tags($live['tags'] ?? []), true)
        && !empty($live['completed_at']),
    'påminnelsen behåller kontakt och vunnen och klarmarkeras efter ordern'
);
$stored = jeab_purchase_get($rid);
vup(is_array($stored) && (int) ($stored['reminder_id'] ?? 0) === $rid, 'ordern ligger kvar på påminnelsens id');

$again = jeab_ordrar_vunnen_purchase($store, $rid, is_array($live) ? $live : $reminder);
vup(
    (int) ($again['reminder_id'] ?? 0) === $rid
        && trim((string) ($again['kind'] ?? 'satt')) === ''
        && (string) ($again['contact_id'] ?? '') === $contactId
        && count(array_filter(jeab_purchase_all(), static fn ($row): bool => is_array($row) && (int) ($row['reminder_id'] ?? 0) === $rid)) === 1,
    'andra klicket lämnar samma order och länken'
);

$steps = jeab_ordrar_v3_steps($stored, $store);
vup(($steps[0]['id'] ?? '') === 'bekrafta' && ($steps[0]['state'] ?? '') === 'todo', 'första steget är Bekräfta och att göra');
vup(jeab_ordrar_v3_kind($stored) === '' && jeab_ordrar_v3_kind_label('') === 'Produkt saknas', 'tom produkt visas som Produkt saknas');
vup(jeab_ordrar_bekrafta_block_message($stored) === 'Välj produkt.', 'Bekräfta kräver produkt');
vup(jeab_ordrar_v3_bekraftad($stored, $store) === false, 'tom produkt låser inte upp Fortnox-steget');

$html = jeab_ordrar_v3_html($stored, 'jarfalla', $rid, $store, null, null, $live);
$firstAt = strpos($html, 'id="steg-bekrafta"');
$nextAt = strpos($html, 'id="steg-faktura"');
vup($firstAt !== false && $nextAt !== false && $firstAt < $nextAt, 'Bekräfta är första steget');
$first = substr($html, $firstAt, $nextAt - $firstAt);
vup(str_contains($first, 'data-state="todo"') && str_contains($first, '>Välj produkt.<'), 'första steget visar att produkt saknas');
vup(str_contains($first, 'data-v3-kind') && str_contains($first, 'aria-required="true"'), 'första steget har produktvalet');
preg_match_all('/<input type="radio" name="kind"[^>]*>/', $first, $radios);
vup(count($radios[0]) === 3, 'tre produkter att välja, fick ' . count($radios[0]));
$checked = 0;
$required = 0;
foreach ($radios[0] as $radio) {
    if (str_contains($radio, ' checked')) {
        $checked++;
    }
    if (preg_match('/\srequired(?:\s|>)/', $radio) === 1) {
        $required++;
    }
}
vup($checked === 0 && $required === 3, 'ingen produkt är förvald och alla tre är obligatoriska');
vup(str_contains($html, 'Produkt saknas'), 'menyn visar tom produkt');

$refusedBuy = false;
try {
    jeab_ordrar_slutfor($store, ['id' => $rid]);
} catch (InvalidArgumentException $e) {
    $refusedBuy = str_contains($e->getMessage(), 'Elbilsladdare');
}
vup($refusedBuy, 'Skapa order och boka kräver vald produkt');
$refusedFx = false;
try {
    jeab_ordrar_fortnox_utan_bokning(['id' => $rid]);
} catch (InvalidArgumentException $e) {
    $refusedFx = str_contains($e->getMessage(), 'Elbilsladdare');
}
vup($refusedFx, 'Fortnox kräver vald produkt');
$after = jeab_purchase_get($rid);
vup(
    is_array($after)
        && (int) ($after['reminder_id'] ?? 0) === $rid
        && (string) ($after['contact_id'] ?? '') === $contactId
        && trim((string) ($after['kind'] ?? 'satt')) === ''
        && trim((string) ($after['fortnox_order'] ?? '')) === ''
        && (int) ($after['booking_id'] ?? 0) === 0
        && (int) $GLOBALS['JEAB_FORTNOX_WRITES'] === 0
        && count($store->listAllBookings()) === $booksBefore,
    'vägrad Fortnox lämnar ordern, kontakten och påminnelselänken'
);
$still = jeab_get_reminder($store, $rid);
vup(is_array($still) && in_array('vunnen', jeab_parse_tags($still['tags'] ?? []), true), 'vunnen ligger kvar efter vägrad Fortnox');

echo "OK  vunnen utan produkt\n";
