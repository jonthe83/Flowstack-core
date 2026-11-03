<?php

declare(strict_types=1);

/**
 * Batteri och sol bokas i Beställ, efter att förslaget visats.
 * Laddbox bokas fortfarande i Bekräfta. Redan bokad tid bokas inte om.
 * Önskad leverans räknas från installationen (3 dagar, sol 6) och går inte att ändra.
 */

$tmp = sys_get_temp_dir() . '/jeab-bestall-book-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);

function bb_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function bb_secret(): void
{
    $path = jeab_bokmalen_ordrar_webhook_secret_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode([
        'url' => 'https://bokmalen.example.invalid/hooks/jeab-ordrar',
        'key' => 'bestall-book-test-key',
    ], JSON_UNESCAPED_SLASHES));
}

function bb_shop_posts(): int
{
    $n = 0;
    foreach (is_array($GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? null) ? $GLOBALS['_JEAB_BUG_WEBHOOKS'] : [] as $row) {
        if (is_array($row) && ($row['type'] ?? '') === 'ordrar.bestall') {
            $n++;
        }
    }
    return $n;
}

function bb_order(JeabStore $store, string $kind, string $company = 'jarfalla'): array
{
    static $n = 0;
    $n++;
    $reminder = jeab_create_reminder($store, 'BB ' . $kind . ' ' . $n, jeab_now(), 'test', null, [
        'customer' => 'BB Kund ' . $n,
        'company' => $company,
        'address' => 'Testgatan ' . $n,
        'email' => 'bb' . $n . '@example.com',
        'phone' => '0700000' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
    ]);
    return jeab_purchase_put([
        'reminder_id' => (int) ($reminder['id'] ?? 0),
        'company' => $company,
        'kind' => $kind,
        'customer' => 'BB Kund ' . $n,
        'name' => 'BB Kund ' . $n,
        'huvudsokande' => 'BB Kund ' . $n,
        'personnummer' => '19800101-0000',
        'fastighetsbeteckning' => 'Testet 1:1',
        'address' => 'Testgatan ' . $n . ', 175 00 Järfälla',
        'huvudsokande_address' => 'Testgatan ' . $n . ', 175 00 Järfälla',
        'email' => 'bb' . $n . '@example.com',
        'phone' => '0700000' . str_pad((string) $n, 3, '0', STR_PAD_LEFT),
        'pris' => 80000,
        'natbolag' => 'E.ON',
        'anlaggnings_id' => '735999123456789012',
        'kwh' => '10',
        'install_what' => 'Test',
        'inkop_lines' => [['sku' => 'ART-' . $n, 'title' => 'Artikel', 'qty' => 1, 'shop' => 'aprilice']],
        'orderrader_saved' => true,
        'steps' => [],
    ]);
}

function bb_confirm_input(JeabStore $store, array $row): array
{
    $proposal = jeab_ordrar_install_proposal($store, $row);
    bb_assert(is_array($proposal) && ($proposal['starts_at'] ?? '') !== '', 'förslag finns');

    return [
        'id' => (int) ($row['reminder_id'] ?? 0),
        'intent' => 'next',
        'bestallning_status' => 'A',
        'proposal_starts' => (string) $proposal['starts_at'],
        'proposal_ends' => (string) $proposal['ends_at'],
        'install_date' => jeab_ordrar_ymd((string) $proposal['starts_at']),
        'onskad_leverans' => (string) $proposal['delivery'],
        'line_sku' => ['ART-1'],
        'line_title' => ['Artikel'],
        'line_qty' => ['1'],
    ];
}

jeab_ordrar_external_live(false);
bb_secret();
$store = jeab_store();
$GLOBALS['_JEAB_FORTNOX_CREATE'] = static function (array $row): array {
    $row['fortnox_order'] = '91024';
    $row['updated_at'] = jeab_now();
    return jeab_purchase_put($row);
};
$GLOBALS['_JEAB_BUG_WEBHOOK_RESPONSE'] = [
    'aprilice_order' => '2026104099',
    'leverans' => '2026-11-02',
    'ordered_at' => '2026-10-28 09:00:00',
];

bb_assert(jeab_ordrar_wanted_delivery_ymd('2026-11-16', 'batteri') === '2026-11-13', 'batteri måndag ger fredag tre dagar före');
bb_assert(jeab_ordrar_wanted_delivery_ymd('2026-11-16', 'laddbox') === '2026-11-13', 'laddbox använder samma 3-dagarsfönster');
bb_assert(jeab_ordrar_wanted_delivery_ymd('2026-11-11', 'batteri') === '2026-11-09', 'söndag flyttas mot installationen till måndag');
bb_assert(jeab_ordrar_wanted_delivery_ymd('2026-11-10', 'batteri') === '2026-11-07', 'lördag utan arbetsdag i fönstret behålls');
bb_assert(jeab_ordrar_wanted_delivery_ymd('2026-11-16', 'sol') === '2026-11-10', 'sol måndag ger tisdag sex dagar före');
bb_assert(jeab_ordrar_wanted_delivery_ymd('2026-11-13', 'sol') === '2026-11-09', 'sol-lördag flyttas till måndag inom 4–6 dagar');
bb_assert(jeab_ordrar_delivery_window('sol') === ['min' => 4, 'max' => 6, 'default' => 6], 'sol-fönster');
bb_assert(jeab_ordrar_delivery_window('batteri') === ['min' => 2, 'max' => 3, 'default' => 3], 'batteri-fönster');

$battery = bb_order($store, 'batteri');
$beforeHtml = jeab_ordrar_v3_html($battery, 'jarfalla', (int) $battery['reminder_id'], $store);
bb_assert(str_contains($beforeHtml, '>Skapa order<') && !str_contains($beforeHtml, '>Skapa order och boka<'), 'Bekräfta-knappen bokar inte batteri');
$before = count($store->listAllBookings());
$done = jeab_ordrar_slutfor($store, [
    'id' => (int) $battery['reminder_id'],
    'kind' => 'batteri',
    'pris' => '80000',
    'install_what' => 'Batteri',
    'huvudsokande' => 'BB Kund',
    'huvudsokande_personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'Testet 1:1',
]);
bb_assert((int) ($done['booking_id'] ?? 0) === 0, 'batteri Bekräfta skapar ingen bokning');
bb_assert(trim((string) ($done['fortnox_order'] ?? '')) === '91024', 'batteri Bekräfta skriver Fortnox');
bb_assert(count($store->listAllBookings()) === $before, 'batteri Bekräfta skriver ingen kalenderrad');

$solar = bb_order($store, 'sol');
$solarDone = jeab_ordrar_slutfor($store, [
    'id' => (int) $solar['reminder_id'],
    'kind' => 'sol',
    'pris' => '180000',
    'install_what' => 'Sol',
    'huvudsokande' => 'BB Kund',
    'huvudsokande_personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'Testet 1:1',
]);
bb_assert((int) ($solarDone['booking_id'] ?? 0) === 0, 'sol Bekräfta skapar ingen bokning');

$box = bb_order($store, 'laddbox');
$boxDone = jeab_ordrar_slutfor($store, [
    'id' => (int) $box['reminder_id'],
    'kind' => 'laddbox',
    'pris' => '12000',
    'install_what' => 'Laddbox',
    'huvudsokande' => 'BB Kund',
    'huvudsokande_personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'Testet 1:1',
]);
bb_assert(jeab_ordrar_is_booked($boxDone, $store), 'laddbox bokas fortfarande i Bekräfta');
$boxBooking = $store->getBooking((int) $boxDone['booking_id']);
bb_assert(is_array($boxBooking) && in_array('Elin', (array) ($boxBooking['assignees'] ?? []), true), 'laddbox Järfälla bokas på Elin');

$open = jeab_purchase_get((int) $battery['reminder_id']);
bb_assert(is_array($open), 'batteriordern finns kvar');
$proposal = jeab_ordrar_install_proposal($store, $open);
bb_assert(is_array($proposal) && ($proposal['who'] ?? []) === ['Roberto'], 'Järfälla-batteri föreslår Roberto');
$wanted = jeab_ordrar_wanted_delivery_ymd((string) $proposal['starts_at'], 'batteri');
bb_assert((string) $proposal['delivery'] === $wanted, 'förslagets leverans följer installationen');
$bekrafta = jeab_ordrar_v3_html($open, 'jarfalla', (int) $open['reminder_id'], $store);
bb_assert(str_contains($bekrafta, 'Ingen tid bokad') && !str_contains($bekrafta, '>Skapa order och boka<'), 'klar Bekräfta visar att tiden inte är bokad');
$open['foranmalan_status'] = 'A';
$open['steps']['foranmald'] = '2026-10-20';
$open = jeab_purchase_put($open);
$bestallHtml = jeab_ordrar_v3_html($open, 'jarfalla', (int) $open['reminder_id'], $store);
bb_assert(str_contains($bestallHtml, 'data-install-proposal="open"'), 'Beställ visar förslaget');
bb_assert(str_contains($bestallHtml, 'name="proposal_starts"'), 'Beställ postar den visade luckan');
bb_assert(!str_contains($bestallHtml, 'type="date" name="onskad_leverans"') && !str_contains($bestallHtml, 'id="v3-lev"'), 'Beställ har inget redigerbart leveransdatum');
bb_assert(str_contains($bestallHtml, 'Datumet går inte att ändra.'), 'hjälpen säger att datumet är låst');

$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
$saved = jeab_ordrar_save_bestall_steg($store, [
    'id' => (int) $open['reminder_id'],
    'bestallning_status' => 'A',
    'proposal_starts' => (string) $proposal['starts_at'],
    'proposal_ends' => (string) $proposal['ends_at'],
]);
bb_assert((int) ($saved['booking_id'] ?? 0) === 0 && bb_shop_posts() === 0, 'Spara bokar inte och postar ingen shoporder');

$threw = false;
try {
    jeab_ordrar_save_bestall_steg($store, array_replace(bb_confirm_input($store, jeab_purchase_get((int) $open['reminder_id'])), [
        'proposal_starts' => '1999-01-04 07:00:00',
        'proposal_ends' => '1999-01-04 16:00:00',
    ]));
} catch (InvalidArgumentException $e) {
    $threw = str_contains($e->getMessage(), 'Förslaget har ändrats');
}
bb_assert($threw, 'avvikande förslag stoppas');
$afterMiss = jeab_purchase_get((int) $open['reminder_id']);
bb_assert((int) ($afterMiss['booking_id'] ?? 0) === 0 && bb_shop_posts() === 0, 'avvikelse bokar inte och postar inte');

$draft = jeab_ordrar_save_bestall_steg($store, array_replace(bb_confirm_input($store, $afterMiss), ['bestallning_status' => 'B']));
bb_assert((int) ($draft['booking_id'] ?? 0) === 0, 'utkast bokar inte');
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];

$placed = jeab_ordrar_save_bestall_steg($store, bb_confirm_input($store, jeab_purchase_get((int) $open['reminder_id'])));
bb_assert(jeab_ordrar_is_booked($placed, $store), 'bekräftad Beställ bokar');
bb_assert(bb_shop_posts() === 1, 'Bokmalen får en shoporder, ingen egen webbshop');
$bookedRow = $store->getBooking((int) $placed['booking_id']);
bb_assert(is_array($bookedRow) && (string) $bookedRow['starts_at'] === (string) $proposal['starts_at'] && (string) $bookedRow['ends_at'] === (string) $proposal['ends_at'], 'bokningen är den visade luckan');
bb_assert(in_array('Roberto', (array) ($bookedRow['assignees'] ?? []), true), 'batteri bokas på Roberto');
$fresh = jeab_purchase_get((int) $open['reminder_id']);
bb_assert((string) ($fresh['onskad_leverans'] ?? '') === $wanted, 'önskad leverans sparas från installationen, fick ' . (string) ($fresh['onskad_leverans'] ?? ''));
$again = jeab_ordrar_save_bestall_steg($store, [
    'id' => (int) $open['reminder_id'],
    'intent' => 'next',
    'bestallning_status' => 'A',
    'proposal_starts' => '2030-01-07 07:00:00',
    'proposal_ends' => '2030-01-07 16:00:00',
]);
$againBooking = $store->getBooking((int) $again['booking_id']);
bb_assert((int) $again['booking_id'] === (int) $placed['booking_id'] && is_array($againBooking) && (string) $againBooking['starts_at'] === (string) $proposal['starts_at'], 'redan bokad tid bokas inte om');

$manual = bb_order($store, 'sol');
$manualProposal = jeab_ordrar_install_proposal($store, $manual);
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
$manualDone = jeab_ordrar_save_bestall_steg($store, [
    'id' => (int) $manual['reminder_id'],
    'intent' => 'next',
    'bestallning_status' => 'C',
    'proposal_starts' => (string) ($manualProposal['starts_at'] ?? ''),
    'proposal_ends' => (string) ($manualProposal['ends_at'] ?? ''),
]);
$manualBooking = $store->getBooking((int) ($manualDone['booking_id'] ?? 0));
bb_assert(is_array($manualBooking) && (string) $manualBooking['starts_at'] === (string) $manualProposal['starts_at'], 'manuell Beställ bokar den visade soltiden');
bb_assert(($manualProposal['who'] ?? []) === ['Niman'], 'Järfälla-sol föreslår Niman');
bb_assert(bb_shop_posts() === 0, 'manuell Beställ lägger ingen shoporder');
bb_assert((string) ($manualDone['onskad_leverans'] ?? '') === jeab_ordrar_wanted_delivery_ymd((string) $manualProposal['starts_at'], 'sol'), 'solens önskade leverans är sex dagar före, med helgflytt');

$malmo = bb_order($store, 'batteri', 'malmo');
$malmoSlot = jeab_ordrar_proposed_slot($store, $malmo);
bb_assert(is_array($malmoSlot) && ($malmoSlot['who'] ?? []) === ['Kim'], 'Malmö-batteri föreslår Kim');
$malmoSol = bb_order($store, 'sol', 'malmo');
$malmoSolSlot = jeab_ordrar_proposed_slot($store, $malmoSol);
bb_assert(is_array($malmoSolSlot) && ($malmoSolSlot['who'] ?? []) === ['Isaq', 'Abol'], 'Malmö-sol föreslår Isaq och Abol');

$recover = bb_order($store, 'batteri');
$recover['steps'] = ['spec' => '2026-10-01', 'orderrader' => '2026-10-01', 'bestalld' => '2026-10-01'];
$recover['bestalld_at'] = '2026-10-01 10:00:00';
$recover = jeab_purchase_put($recover);
bb_assert(jeab_ordrar_default_stop($recover) === 'boka', 'beställd men obokad öppnas på Boka');
$recover = jeab_ordrar_maybe_book_current($store, $recover);
bb_assert((int) ($recover['booking_id'] ?? 0) === 0, 'sidöppning bokar inte batteri');

$failed = bb_order($store, 'batteri');
putenv('JEAB_BUG_WEBHOOK_CAPTURE');
unset($_ENV['JEAB_BUG_WEBHOOK_CAPTURE']);
$failedThrew = false;
try {
    jeab_ordrar_save_bestall_steg($store, bb_confirm_input($store, $failed));
} catch (InvalidArgumentException $e) {
    $failedThrew = str_contains($e->getMessage(), 'Bokmalen');
}
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$failedRow = jeab_purchase_get((int) $failed['reminder_id']);
bb_assert($failedThrew && (int) ($failedRow['booking_id'] ?? 0) === 0, 'misslyckad shoporder lämnar kalendern tom');

$states = [];
foreach (jeab_ordrar_v3_steps($placed, $store) as $step) {
    $states[$step['id']] = $step['state'];
}
bb_assert(!isset($states['boka']), 'bokad och beställd order har inget Boka-steg');
$gap = bb_order($store, 'batteri');
$gap['fortnox_order'] = '1902';
$gap['steps']['bestalld'] = '2026-10-02';
$gap['bestalld_at'] = '2026-10-02 10:00:00';
$gapStates = [];
foreach (jeab_ordrar_v3_steps($gap, $store) as $step) {
    $gapStates[$step['id']] = $step['state'];
}
bb_assert(($gapStates['boka'] ?? '') === 'todo' && ($gapStates['bestall'] ?? '') === 'klar', 'Boka kommer tillbaka när Beställ är klar och tiden saknas');

$sms = (string) file_get_contents(dirname(__DIR__) . '/src/sms.php');
bb_assert(str_contains($sms, 'ungefär en vecka innan'), 'kundens sms-text är oförändrad');

echo "OK  beställ bokar installationen\n";
