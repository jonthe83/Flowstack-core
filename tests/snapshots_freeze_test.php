<?php

declare(strict_types=1);

/**
 * Skickade offerter, fakturor, fullmakter och sent_log läser en egen kopia.
 * Påfyllnad tar radens fält, aldrig den nuvarande kontakten.
 * Påhittade namn. Hooksvaret har inga personnummer. Inga utskick.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-snapshots-' . bin2hex(random_bytes(4));
$private = $tmp . '/private';
$public = $tmp . '/public';
mkdir($private . '/secrets', 0775, true);
mkdir($public, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $private;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE');
putenv('CONTACTS_STRICT');
unset($_ENV['CONTACTS_SOLE'], $_ENV['CONTACTS_STRICT'], $GLOBALS['_JEAB_CONTACTS_STRICT']);
file_put_contents($private . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $private . '/app.sqlite',
        'json_path' => $private . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
    'incoming_webhook_token' => 'not-the-inbox-token',
], true) . ";\n");
file_put_contents($private . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-snapshots']));
require dirname(__DIR__) . '/src/bootstrap.php';

function snap_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function snap_no_pnr(array $payload, string $label): void
{
    $copy = $payload;
    unset($copy['batch_id']);
    $json = json_encode($copy, JSON_UNESCAPED_UNICODE);
    snap_ok(is_string($json), $label . ' går att läsa som JSON');
    $lower = is_string($json) ? strtolower($json) : '';
    $leaked = $lower !== '' && (
        str_contains($lower, '19900101')
        || str_contains($lower, '19850505')
        || str_contains($lower, 'personnummer')
        || str_contains($lower, '"pnr"')
        || str_contains($lower, 'live.kontakt')
        || str_contains($lower, 'faktura.rad')
    );
    snap_ok(!$leaked, $label . ' innehåller inget personnummer');
}

/** @param array<string, mixed> $row */
function snap_contact(array $row): string
{
    $made = jeab_kontakt_find_or_create($row, false);
    snap_ok(is_array($made) && trim((string) ($made['id'] ?? '')) !== '', 'kontakten kan skapas');
    $id = (string) $made['id'];
    $saved = jeab_kontakt_get($id);
    snap_ok(is_array($saved), 'kontakten kan läsas');
    foreach ($row as $key => $value) {
        $saved[$key] = $value;
    }
    $saved['id'] = $id;
    $store = jeab_contact_store();
    snap_ok($store instanceof JeabPdoContactStore, 'kontaktlagret är SQL');
    $store->upsert($id, $saved);
    unset($GLOBALS['_JEAB_CONTACTS_SOLE_CACHE']);

    return $id;
}

$root = dirname(__DIR__);
$hookSrc = (string) file_get_contents($root . '/hook.php');
$dispatchSrc = (string) file_get_contents($root . '/src/contacts_sole.php');
snap_ok(str_contains($hookSrc, "'snapshots.audit'"), 'hook.php tar snapshots.audit');
snap_ok(str_contains($hookSrc, "'snapshots.backfill'"), 'hook.php tar snapshots.backfill');
snap_ok(str_contains($hookSrc, "'snapshots.backfill_undo'"), 'hook.php tar snapshots.backfill_undo');
snap_ok(str_contains($dispatchSrc, 'jeab_snapshots_handle('), 'dispatch skickar snapshot-hakarna');

$store = jeab_store();
snap_ok($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');

$rowPnr = '19900101-1234';
$contactPnr = '19850505-5558';
$contactId = snap_contact([
    'name' => 'Live Kontakt',
    'email' => 'live.kontakt@example.test',
    'phone' => '0701000201',
    'address' => 'Livegatan 1, 111 22 Teststad',
    'street' => 'Livegatan 1',
    'city' => 'Teststad',
    'postal_code' => '11122',
    'owners' => [[
        'name' => 'Live Kontakt',
        'personnummer' => $contactPnr,
        'email' => 'live.kontakt@example.test',
        'phone' => '0701000201',
        'address' => 'Livegatan 1',
    ]],
]);

$unsent = [
    'reminder_id' => 88001,
    'contact_id' => $contactId,
    'company' => 'jarfalla',
    'name' => 'Osänd Rad',
    'huvudsokande' => 'Osänd Rad',
    'email' => 'osand.rad@example.test',
    'phone' => '0701000202',
    'address' => 'Osandgatan 2',
    'huvudsokande_address' => 'Osandgatan 2',
    'owners' => [[
        'name' => 'Osänd Rad',
        'personnummer' => '',
        'email' => 'osand.rad@example.test',
        'phone' => '0701000202',
        'address' => 'Osandgatan 2',
    ]],
];
$unsentBlock = jeab_offert_kundblock($unsent);
snap_ok($unsentBlock['name'] === 'Live Kontakt' && $unsentBlock['email'] === 'live.kontakt@example.test', 'oskickad offert läser kontakten');

$sentQuote = $unsent;
$sentQuote['quote_sent'] = ['hit' => true, 'date' => '2026-01-02 10:00'];
$sentQuote['name'] = 'Skickad Rad';
$sentQuote['huvudsokande'] = 'Skickad Rad';
$sentQuote['email'] = 'skickad.rad@example.test';
$sentQuote['phone'] = '0701000203';
$sentQuote['address'] = 'Skickadgatan 3';
$sentQuote['huvudsokande_address'] = 'Skickadgatan 3';
$sentBlock = jeab_offert_kundblock($sentQuote);
snap_ok($sentBlock['name'] === 'Skickad Rad' && $sentBlock['email'] === 'skickad.rad@example.test', 'skickad offert utan kopia läser raden');
snap_ok(!str_contains($sentBlock['name'] . $sentBlock['email'], 'Live Kontakt'), 'skickad offert utan kopia hoppar över kontakten');

$snapQuote = $sentQuote;
$snapQuote['snapshots'] = [
    'offert' => [
        'name' => 'Fryst Offert',
        'address' => 'Frystgatan 4',
        'email' => 'fryst.offert@example.test',
        'phone' => '0701000204',
        'owners' => [[
            'name' => 'Fryst Offert',
            'address' => 'Frystgatan 4',
            'email' => 'fryst.offert@example.test',
            'phone' => '0701000204',
        ]],
        'at' => '2026-01-02T10:00:00+01:00',
        'source' => 'send',
    ],
];
$snapBlock = jeab_offert_kundblock($snapQuote);
snap_ok($snapBlock['name'] === 'Fryst Offert' && $snapBlock['email'] === 'fryst.offert@example.test' && $snapBlock['phone'] === '0701000204', 'offert med kopia läser kopian');
snap_ok($snapBlock['display'] === 'Frystgatan 4', 'offert med kopia läser den frysta adressen');

$invoice = [
    'reminder_id' => 88002,
    'id' => 88002,
    'contact_id' => $contactId,
    'company' => 'jarfalla',
    'fortnox_order' => '1001',
    'name' => 'Faktura Rad',
    'huvudsokande' => 'Faktura Rad',
    'customer_name' => 'Faktura Rad',
    'email' => 'faktura.rad@example.test',
    'phone' => '0701000205',
    'address' => 'Fakturagatan 5',
    'huvudsokande_address' => 'Fakturagatan 5',
    'personnummer' => $rowPnr,
    'owners' => [[
        'name' => 'Faktura Rad',
        'personnummer' => $rowPnr,
        'email' => 'faktura.rad@example.test',
        'phone' => '0701000205',
        'address' => 'Fakturagatan 5',
    ]],
];
$invoiceRow = jeab_ordrar_fortnox_customer_row($invoice);
snap_ok(($invoiceRow['name'] ?? '') === 'Faktura Rad' && ($invoiceRow['email'] ?? '') === 'faktura.rad@example.test', 'skickad Fortnox-rad läser ordern');
snap_ok(!str_contains((string) ($invoiceRow['name'] ?? '') . (string) ($invoiceRow['email'] ?? ''), 'live.kontakt'), 'skickad Fortnox-rad hoppar över kontakten');
$submit = jeab_ordrar_fortnox_submit_personnummer($invoice);
snap_ok($submit === $rowPnr && $submit !== $contactPnr, 'skickad Fortnox-rad använder orderns personnummer');
$invoice['snapshots'] = [
    'invoice' => [
        'name' => 'Fryst Faktura',
        'address' => 'Frystfakturagatan 6',
        'email' => 'fryst.faktura@example.test',
        'phone' => '0701000206',
        'owners' => [[
            'name' => 'Fryst Faktura',
            'address' => 'Frystfakturagatan 6',
            'email' => 'fryst.faktura@example.test',
            'phone' => '0701000206',
        ]],
        'at' => '2026-01-03T10:00:00+01:00',
        'source' => 'send',
    ],
];
$frozenInvoice = jeab_ordrar_fortnox_customer_row($invoice);
snap_ok(($frozenInvoice['name'] ?? '') === 'Fryst Faktura' && ($frozenInvoice['email'] ?? '') === 'fryst.faktura@example.test', 'faktura med kopia läser kopian');
$invoice['fortnox_order'] = '';
$invoice['snapshots'] = [];
$openInvoice = jeab_ordrar_fortnox_customer_row($invoice);
snap_ok(($openInvoice['email'] ?? '') === 'live.kontakt@example.test', 'oskickad Fortnox-rad läser fortfarande kontakten');

$fullmakt = [
    'reminder_id' => 88003,
    'contact_id' => $contactId,
    'company' => 'jarfalla',
    'fullmakt_start_at' => '2026-01-04 09:00:00',
    'fullmakt_token' => 'tok-gammal',
    'huvudsokande' => 'Fullmakt Rad',
    'name' => 'Fullmakt Rad',
    'email' => 'fullmakt.rad@example.test',
    'phone' => '0701000207',
    'address' => 'Fullmaktgatan 7',
    'huvudsokande_address' => 'Fullmaktgatan 7',
    'fastighetsbeteckning' => 'RADEN 1:1',
    'owners' => [[
        'name' => 'Fullmakt Rad',
        'personnummer' => $rowPnr,
        'email' => 'fullmakt.rad@example.test',
        'phone' => '0701000207',
        'address' => 'Fullmaktgatan 7',
    ]],
];
$fullmaktRow = jeab_ellevio_fullmakt_customer_row($fullmakt);
snap_ok(($fullmaktRow['huvudsokande'] ?? '') === 'Fullmakt Rad' && ($fullmaktRow['email'] ?? '') === 'fullmakt.rad@example.test', 'skickad fullmakt läser raden');
$fields = jeab_ellevio_fullmakt_fields($fullmakt);
snap_ok(($fields['namn'] ?? '') === 'Fullmakt Rad' && ($fields['epost'] ?? '') === 'fullmakt.rad@example.test', 'fullmaktsfält läser radens namn');
$kept = jeab_ellevio_fullmakt_row_for_new($fullmakt);
snap_ok(trim((string) ($kept['fullmakt_start_at'] ?? '')) === '', 'ny fullmakt arkiverar den gamla token-stämpeln');
$keptSnap = jeab_snapshots_read($kept, 'fullmakt');
snap_ok(is_array($keptSnap) && ($keptSnap['name'] ?? '') === 'Fullmakt Rad' && ($keptSnap['email'] ?? '') === 'fullmakt.rad@example.test', 'ny fullmakt behåller den skickade kopian');
$keptRow = jeab_ellevio_fullmakt_customer_row($kept);
snap_ok(($keptRow['email'] ?? '') === 'fullmakt.rad@example.test', 'omskick utan ny version läser kopian');
$fresh = $fullmakt;
$fresh['_snapshot_new_version'] = true;
$freshRow = jeab_ellevio_fullmakt_customer_row($fresh);
snap_ok(($freshRow['email'] ?? '') === 'live.kontakt@example.test', 'new_version läser kontakten');

$seom = [
    'reminder_id' => 88004,
    'contact_id' => $contactId,
    'company' => 'jarfalla',
    'natbolag' => 'SEOM',
    'anlaggnings_id' => '735999000000000001',
    'seom_sam_sent_at' => '2026-01-05 08:00:00',
    'huvudsokande' => 'Seom Rad',
    'name' => 'Seom Rad',
    'email' => 'seom.rad@example.test',
    'phone' => '0701000208',
    'address' => 'Seomgatan 8',
    'huvudsokande_address' => 'Seomgatan 8',
    'kind' => 'sol',
    'owners' => [[
        'name' => 'Seom Rad',
        'personnummer' => $rowPnr,
        'email' => 'seom.rad@example.test',
        'phone' => '0701000208',
        'address' => 'Seomgatan 8',
    ]],
];
$seomFields = jeab_seom_fullmakt_fields($seom);
$seomKund = is_array($seomFields['kund'] ?? null) ? $seomFields['kund'] : [];
snap_ok(($seomKund['namn'] ?? '') === 'Seom Rad' && ($seomKund['mejl'] ?? '') === 'seom.rad@example.test', 'skickad SEOM-blankett läser raden');
snap_ok(($seomKund['personnummer'] ?? '') !== $contactPnr, 'skickad SEOM-blankett tar inte kontaktens personnummer');

$resendThread = [
    'id' => 'th-resend',
    'snapshots' => [
        'offert' => [
            'name' => 'Fryst Offert',
            'address' => 'Frystgatan 4',
            'email' => 'fryst.offert@example.test',
            'phone' => '0701000204',
            'owners' => [],
            'at' => '2026-01-02T10:00:00+01:00',
            'source' => 'send',
        ],
    ],
];
snap_ok(jeab_snapshots_resend_email($resendThread, 'live.kontakt@example.test', []) === 'fryst.offert@example.test', 'offer.resend läser den frysta adressen');
snap_ok(jeab_snapshots_resend_email($resendThread, 'live.kontakt@example.test', ['new_version' => '1']) === 'live.kontakt@example.test', 'new_version lämnar den levande adressen');
snap_ok(jeab_snapshots_resend_email(['id' => 'th-tom'], 'lead.kund@example.test', []) === 'lead.kund@example.test', 'första omskick utan kopia behåller leadadressen');

$storedInvoice = $invoice;
$storedInvoice['fortnox_order'] = '1001';
$storedInvoice['snapshots'] = [];
$storedInvoice['name'] = 'Faktura Rad';
$storedInvoice['huvudsokande'] = 'Faktura Rad';
$storedInvoice['customer_name'] = 'Faktura Rad';
$storedInvoice['email'] = 'faktura.rad@example.test';
$storedInvoice['phone'] = '0701000205';
$storedInvoice['address'] = 'Fakturagatan 5';
jeab_purchase_put($storedInvoice);
$withSnap = $invoice;
$withSnap['reminder_id'] = 88005;
$withSnap['id'] = 88005;
$withSnap['fortnox_order'] = '1002';
$withSnap['name'] = 'Redan Fryst';
$withSnap['huvudsokande'] = 'Redan Fryst';
$withSnap['email'] = 'redan.fryst@example.test';
$withSnap['snapshots'] = [
    'invoice' => [
        'name' => 'Redan Fryst',
        'address' => 'Redangatan 9',
        'email' => 'redan.fryst@example.test',
        'phone' => '0701000209',
        'owners' => [],
        'at' => '2026-01-06T10:00:00+01:00',
        'source' => 'send',
    ],
];
jeab_purchase_put($withSnap);
jeab_purchase_put($fullmakt);
jeab_purchase_put($seom);

jeab_inkorg_thread_write('th-offert', static function (array $thread): array {
    $thread['company'] = 'jarfalla';
    $thread['customer_name'] = 'Trad Kund';
    $thread['customer_email'] = 'trad.kund@example.test';
    $thread['phone'] = '0701000210';
    $thread['address'] = 'Tradgatan 10';
    $thread['quote_sent'] = ['hit' => true, 'date' => '2026-01-07 11:00'];
    $thread['contact_id'] = '';

    return $thread;
});
jeab_ensure_inbox_sent($store->pdo(), 'sqlite');
$store->pdo()->prepare(
    'INSERT INTO inbox_sent (created_at, thread_id, via, actor, kind, outcome, channel, body_hash, from_addr, snapshot_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([date('c'), 'th-offert', 'manual', 'hook', 'offert', 'sent', 'email', hash('sha256', 'mail-utan-kopia'), 'saljare@example.test', '']);

$audit = jeab_snapshots_handle(['action' => 'snapshots.audit']);
snap_ok(($audit['ok'] ?? false) === true && ($audit['action'] ?? '') === 'snapshots.audit' && ($audit['read_only'] ?? false) === true && ($audit['written'] ?? true) === false, 'snapshots.audit är skrivskyddad');
snap_ok((int) ($audit['missing']['invoice'] ?? -1) >= 1 && (int) ($audit['missing']['fullmakt'] ?? -1) >= 1 && (int) ($audit['missing']['offert'] ?? -1) >= 1 && (int) ($audit['missing']['mail'] ?? -1) >= 1, 'auditen räknar saknade kopior per typ');
snap_ok((int) ($audit['sent']['invoice'] ?? 0) > (int) ($audit['missing']['invoice'] ?? 0), 'en faktura som redan har kopia räknas inte som saknad');
snap_no_pnr($audit, 'snapshots.audit');

$dry = jeab_snapshots_handle(['action' => 'snapshots.backfill']);
snap_ok(($dry['dry'] ?? false) === true && ($dry['written'] ?? true) === false, 'backfill utan dry skriver inget');
$still = jeab_purchase_get(88002);
snap_ok(is_array($still) && !jeab_snapshots_has_local($still, 'invoice'), 'torrkörning lämnar fakturan utan kopia');
snap_no_pnr($dry, 'snapshots.backfill dry');

$filled = jeab_snapshots_handle(['action' => 'snapshots.backfill', 'dry' => '0']);
snap_ok(($filled['dry'] ?? true) === false && ($filled['written'] ?? false) === true && (int) ($filled['filled']['invoice'] ?? 0) >= 1, 'dry=0 fyller saknade kopior');
snap_no_pnr($filled, 'snapshots.backfill dry=0');
$after = jeab_purchase_get(88002);
$afterSnap = is_array($after) ? jeab_snapshots_read($after, 'invoice') : null;
$snapName = is_array($afterSnap) ? (string) ($afterSnap['name'] ?? '') : '';
$snapEmail = is_array($afterSnap) ? (string) ($afterSnap['email'] ?? '') : '';
snap_ok($snapName === 'Faktura Rad' && $snapEmail === 'faktura.rad@example.test', 'påfyllnaden tar fakturans egna fält');
snap_ok(is_array($afterSnap) && ($afterSnap['source'] ?? '') === 'backfill' && !str_contains((string) ($afterSnap['email'] ?? ''), 'live.kontakt'), 'påfyllnaden läser inte kontakten');
$afterJson = json_encode($afterSnap, JSON_UNESCAPED_UNICODE);
snap_ok(is_string($afterJson) && !str_contains($afterJson, $rowPnr) && !str_contains($afterJson, '199001011234'), 'den sparade kopian har inget personnummer');
$mailRows = jeab_snapshots_mail_rows();
$mailSnap = null;
foreach ($mailRows as $mailRow) {
    if ((string) ($mailRow['id'] ?? '') !== '') {
        $item = $mailRow['item'];
        if ((string) ($item['thread_id'] ?? '') === 'th-offert' && jeab_snapshots_has_local($item, 'mail')) {
            $mailSnap = jeab_snapshots_read($item, 'mail');
        }
    }
}
snap_ok(is_array($mailSnap) && ($mailSnap['email'] ?? '') === 'trad.kund@example.test', 'mejlkopian tar trådens kund, inte avsändaren');

$batch = (string) ($filled['batch_id'] ?? '');
snap_ok(preg_match('/^[0-9]{14}-[a-f0-9]+$/', $batch) === 1, 'backfill lämnar ett batch-id');
$undo = jeab_snapshots_handle(['action' => 'snapshots.backfill', 'undo' => '1', 'batch_id' => $batch]);
snap_ok(($undo['ok'] ?? false) === true && ($undo['written'] ?? false) === true, 'undo tar bort påfyllnaden');
snap_no_pnr($undo, 'snapshots.backfill undo');
$undone = jeab_purchase_get(88002);
snap_ok(is_array($undone) && !jeab_snapshots_has_local($undone, 'invoice'), 'undo tar bort fakturans påfyllda kopia');

jeab_inkorg_thread_write('th-commit', static function (array $thread): array {
    $thread['company'] = 'malmo';
    $thread['customer_name'] = 'Live Kontakt';
    $thread['customer_email'] = 'live.kontakt@example.test';

    return $thread;
});
jeab_quote_send_commit('th-commit', 'saljare@example.test', 'manual', 'hook', 'batteri', hash('sha256', 'commit-test'), 'offert', [
    'name' => 'Commit Kund',
    'email' => 'commit.kund@example.test',
    'phone' => '0701000211',
    'address' => 'Commitgatan 11',
    'owners' => [[
        'name' => 'Commit Kund',
        'email' => 'commit.kund@example.test',
        'phone' => '0701000211',
        'address' => 'Commitgatan 11',
    ]],
]);
$committed = jeab_inkorg_thread_meta('th-commit');
$commitSnap = jeab_snapshots_read(is_array($committed) ? $committed : [], 'offert');
snap_ok(is_array($commitSnap) && ($commitSnap['email'] ?? '') === 'commit.kund@example.test' && ($commitSnap['name'] ?? '') === 'Commit Kund', 'quote_send_commit sparar offertkopian');
$sentStmt = $store->pdo()->prepare('SELECT snapshot_json FROM inbox_sent WHERE thread_id = ? AND kind = ?');
$sentStmt->execute(['th-commit', 'offert']);
$sentJson = (string) $sentStmt->fetchColumn();
$sentSnap = jeab_snapshots_decode($sentJson);
snap_ok(is_array($sentSnap) && ($sentSnap['email'] ?? '') === 'commit.kund@example.test', 'sent_log får samma kopia');

$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;
$strictSent = [
    'reminder_id' => 88060,
    'contact_id' => '',
    'company' => 'jarfalla',
    'natbolag' => 'Ellevio',
    'fullmakt_start_at' => '2026-01-01T10:00:00+01:00',
    'name' => 'Skulle Tommas',
    'huvudsokande' => 'Skulle Tommas',
    'email' => 'tom.rad@example.test',
    'phone' => '0701000260',
    'address' => 'Tomgatan 1, 176 70 Jarfalla',
    'huvudsokande_address' => 'Tomgatan 1, 176 70 Jarfalla',
    'personnummer' => $rowPnr,
    'snapshots' => [
        'fullmakt' => [
            'name' => 'Fryst Fullmakt',
            'address' => 'Frystgatan 1, 176 70 Jarfalla',
            'email' => 'fryst.fullmakt@example.test',
            'phone' => '0701000261',
            'owners' => [],
            'at' => '2026-01-01T10:00:00+01:00',
            'source' => 'send',
        ],
    ],
];
$strictFields = jeab_ellevio_fullmakt_fields($strictSent);
snap_ok(($strictFields['namn'] ?? '') === 'Fryst Fullmakt' && ($strictFields['epost'] ?? '') === 'fryst.fullmakt@example.test', 'strict utan kontakt behåller den skickade fullmakten');
$emptyFrozen = $strictSent;
$emptyFrozen['snapshots']['fullmakt']['name'] = '';
$emptyFrozen['snapshots']['fullmakt']['email'] = '';
$emptyFrozen['snapshots']['fullmakt']['phone'] = '';
$emptyFrozen['snapshots']['fullmakt']['address'] = '';
$emptyFields = jeab_ellevio_fullmakt_fields($emptyFrozen);
snap_ok(($emptyFields['namn'] ?? 'x') === '' && ($emptyFields['epost'] ?? 'x') === '', 'förhandsvisning kastar inte på tom fryst fullmakt');
$emptyPreview = jeab_ellevio_fullmakt_preview_row($emptyFrozen);
snap_ok(str_contains((string) ($emptyPreview['warning'] ?? ''), 'saknar kunduppgifter') && ($emptyPreview['sent'] ?? true) === false, 'förhandsvisning varnar när den skickade fullmakten är tom');
$refused = false;
try {
    jeab_ellevio_fullmakt_start($emptyFrozen, null, true);
} catch (InvalidArgumentException $e) {
    $refused = str_contains($e->getMessage(), 'skickas inte tom') && !str_contains($e->getMessage(), $rowPnr);
}
snap_ok($refused, 'tom fryst fullmakt skickas inte');
unset($GLOBALS['_JEAB_CONTACTS_STRICT']);

jeab_purchase_put([
    'reminder_id' => 88061,
    'company' => 'jarfalla',
    'snapshots' => [
        'fullmakt' => [
            'name' => 'Kvar Fullmakt',
            'address' => 'Kvar 1',
            'email' => 'kvar.fullmakt@example.test',
            'phone' => '0701000262',
            'owners' => [],
            'at' => '2026-01-01T10:00:00+01:00',
            'source' => 'send',
        ],
    ],
]);
jeab_ordrar_fortnox_store_created_number([
    'reminder_id' => 88061,
    'fortnox_order' => '88061',
    'snapshots' => [
        'invoice' => [
            'name' => 'Ny Faktura',
            'address' => 'Ny 2',
            'email' => 'ny.faktura@example.test',
            'phone' => '0701000263',
            'owners' => [],
            'at' => '2026-01-02T10:00:00+01:00',
            'source' => 'send',
        ],
    ],
]);
$merged = jeab_purchase_get(88061);
$mergedSnaps = is_array($merged) && is_array($merged['snapshots'] ?? null) ? $merged['snapshots'] : [];
snap_ok(is_array($merged) && (string) ($merged['fortnox_order'] ?? '') === '88061', 'Fortnox-numret sparas');
snap_ok(is_array($mergedSnaps['fullmakt'] ?? null) && ($mergedSnaps['fullmakt']['name'] ?? '') === 'Kvar Fullmakt', 'Fortnox-sparningen lämnar fullmaktskopian');
snap_ok(is_array($mergedSnaps['invoice'] ?? null) && ($mergedSnaps['invoice']['email'] ?? '') === 'ny.faktura@example.test', 'Fortnox-sparningen skriver bara fakturakopian');
$putBase = jeab_purchase_get(88061);
snap_ok(is_array($putBase), 'raden finns före purchase_put');
$putIncoming = is_array($putBase) ? $putBase : [];
$putIncoming['snapshots'] = [
    'invoice' => [
        'name' => 'Endast Faktura',
        'address' => 'Endast 3',
        'email' => 'endast.faktura@example.test',
        'phone' => '0701000264',
        'owners' => [],
        'at' => '2026-01-03T10:00:00+01:00',
        'source' => 'send',
    ],
];
jeab_purchase_put($putIncoming);
$putKept = jeab_purchase_get(88061);
$putSnaps = is_array($putKept) && is_array($putKept['snapshots'] ?? null) ? $putKept['snapshots'] : [];
snap_ok(is_array($putSnaps['fullmakt'] ?? null) && ($putSnaps['fullmakt']['name'] ?? '') === 'Kvar Fullmakt', 'purchase_put lämnar övriga kopior');
snap_ok(is_array($putSnaps['invoice'] ?? null) && ($putSnaps['invoice']['name'] ?? '') === 'Endast Faktura', 'purchase_put skriver den inkommande kopian');

$snapInvoice = static function (int $id, string $name, string $email): void {
    jeab_purchase_put([
        'reminder_id' => $id,
        'company' => 'jarfalla',
        'fortnox_order' => (string) $id,
        'name' => $name,
        'huvudsokande' => $name,
        'email' => $email,
        'phone' => '0701000270',
        'address' => 'Batchgatan 1',
        'owners' => [[
            'name' => $name,
            'email' => $email,
            'phone' => '0701000270',
            'address' => 'Batchgatan 1',
        ]],
    ]);
};
$snapInvoice(88070, 'Batch Ett', 'batch.ett@example.test');
$snapInvoice(88071, 'Batch Tva', 'batch.tva@example.test');
$batchFill = jeab_snapshots_handle(['action' => 'snapshots.backfill', 'dry' => '0']);
$batchId = (string) ($batchFill['batch_id'] ?? '');
snap_ok(($batchFill['ok'] ?? false) === true && (int) ($batchFill['failed'] ?? 1) === 0 && preg_match('/^[0-9]{14}-[a-f0-9]+$/', $batchId) === 1, 'backfill skriver ångerfilen före raderna');
snap_no_pnr($batchFill, 'backfill med batch');
$batchSnap = jeab_snapshots_read(is_array(jeab_purchase_get(88070)) ? jeab_purchase_get(88070) : [], 'invoice');
snap_ok(is_array($batchSnap) && ($batchSnap['source'] ?? '') === 'backfill' && ($batchSnap['batch'] ?? '') === $batchId, 'påfylld kopia bär samma batch');
snap_ok(is_file(jeab_data_dir() . '/snapshots-backfill/' . $batchId . '.json'), 'ångerfilen ligger på disk');
jeab_purchase_patch(88071, static function (array $row): array {
    if (isset($row['snapshots']['invoice']) && is_array($row['snapshots']['invoice'])) {
        $row['snapshots']['invoice']['source'] = 'send';
        $row['snapshots']['invoice']['name'] = 'Senare Sand';
        unset($row['snapshots']['invoice']['batch']);
    }

    return $row;
});
$guardUndo = jeab_snapshots_handle(['action' => 'snapshots.backfill', 'undo' => '1', 'batch_id' => $batchId]);
snap_ok(($guardUndo['ok'] ?? true) === false && (int) ($guardUndo['skipped'] ?? 0) >= 1 && (int) ($guardUndo['status'] ?? 0) === 400, 'undo rapporterar rader som inte längre är från batchen');
snap_no_pnr($guardUndo, 'undo med batch');
$batchGone = jeab_purchase_get(88070);
snap_ok(is_array($batchGone) && !jeab_snapshots_has_local($batchGone, 'invoice'), 'undo tar bort kopian som fortfarande är från batchen');
$batchKept = jeab_snapshots_read(is_array(jeab_purchase_get(88071)) ? jeab_purchase_get(88071) : [], 'invoice');
snap_ok(is_array($batchKept) && ($batchKept['source'] ?? '') === 'send' && ($batchKept['name'] ?? '') === 'Senare Sand', 'undo rör inte en kopia som bytt källa');

jeab_purchase_put([
    'reminder_id' => 88073,
    'company' => 'jarfalla',
    'fortnox_order' => '88073',
    'name' => 'Utan Batch',
    'huvudsokande' => 'Utan Batch',
    'email' => 'utan.batch@example.test',
    'phone' => '0701000273',
    'address' => 'Batchgatan 3',
    'snapshots' => [
        'invoice' => [
            'name' => 'Utan Batch',
            'address' => 'Batchgatan 3',
            'email' => 'utan.batch@example.test',
            'phone' => '0701000273',
            'owners' => [],
            'at' => '2020-01-01T10:00:00+01:00',
            'source' => 'backfill',
        ],
    ],
]);
$batchlessId = '20200101010101-a1b2c3';
$batchlessPath = jeab_data_dir() . '/snapshots-backfill/' . $batchlessId . '.json';
file_put_contents($batchlessPath, json_encode([
    'batch_id' => $batchlessId,
    'rows' => [[
        'store' => 'purchase',
        'id' => '88073',
        'type' => 'invoice',
        'before' => null,
    ]],
], JSON_UNESCAPED_UNICODE));
$batchlessUndo = jeab_snapshots_handle(['action' => 'snapshots.backfill', 'undo' => '1', 'batch_id' => $batchlessId]);
snap_ok(($batchlessUndo['ok'] ?? true) === false && (int) ($batchlessUndo['skipped'] ?? 0) >= 1 && (int) ($batchlessUndo['status'] ?? 0) === 400, 'undo av batchlös påfyllnad rapporteras');
snap_no_pnr($batchlessUndo, 'undo av batchlös påfyllnad');
$batchlessKept = jeab_snapshots_read(is_array(jeab_purchase_get(88073)) ? jeab_purchase_get(88073) : [], 'invoice');
snap_ok(is_array($batchlessKept) && ($batchlessKept['source'] ?? '') === 'backfill' && ($batchlessKept['name'] ?? '') === 'Utan Batch' && !isset($batchlessKept['batch']), 'batchlös påfyllnad tas inte bort');

$undoDir = jeab_data_dir() . '/snapshots-backfill';
$undoBak = $undoDir . '-bak';
snap_ok(rename($undoDir, $undoBak), 'ångerkatalogen kan flyttas');
file_put_contents($undoDir, 'not-a-directory');
$snapInvoice(88072, 'Inte Skriven', 'inte.skriven@example.test');
$aborted = jeab_snapshots_handle(['action' => 'snapshots.backfill', 'dry' => '0']);
snap_ok(($aborted['ok'] ?? true) === false && ($aborted['written'] ?? true) === false, 'backfill avbryter när ångerfilen inte kan skrivas');
$abortedRow = jeab_purchase_get(88072);
snap_ok(is_array($abortedRow) && !jeab_snapshots_has_local($abortedRow, 'invoice'), 'avbruten backfill skriver inga rader');
unlink($undoDir);
snap_ok(rename($undoBak, $undoDir), 'ångerkatalogen kan läggas tillbaka');

$snapPdo = jeab_store()->pdo();
$snapPdo->exec("CREATE TRIGGER jeab_snap_fail_88072 BEFORE UPDATE ON purchases WHEN NEW.reminder_id = 88072 BEGIN SELECT RAISE(ABORT, 'snapshot apply test'); END");
try {
    $partial = jeab_snapshots_handle(['action' => 'snapshots.backfill', 'dry' => '0']);
} finally {
    $snapPdo->exec('DROP TRIGGER IF EXISTS jeab_snap_fail_88072');
}
$partialId = (string) ($partial['batch_id'] ?? '');
snap_ok(($partial['ok'] ?? true) === false && (int) ($partial['failed'] ?? 0) > 0 && $partialId !== '', 'backfill svarar ok=false när en rad misslyckas');
snap_ok(is_file($undoDir . '/' . $partialId . '.json'), 'ångerfilen finns när en rad misslyckas');
$missed = jeab_purchase_get(88072);
snap_ok(is_array($missed) && !jeab_snapshots_has_local($missed, 'invoice'), 'misslyckad rad får ingen kopia');
$partialUndo = jeab_snapshots_handle(['action' => 'snapshots.backfill', 'undo' => '1', 'batch_id' => $partialId]);
snap_ok(($partialUndo['ok'] ?? false) === true, 'undo tar bort det som hann skrivas');
snap_no_pnr($partial, 'delvis backfill');
snap_no_pnr($partialUndo, 'undo efter delvis backfill');

$pdfPath = jeab_auto_pdf_path('laddbox_jarfalla');
if (!is_dir(dirname($pdfPath))) {
    mkdir(dirname($pdfPath), 0775, true);
}
file_put_contents($pdfPath, "%PDF-1.4\n%GAMMAL-FIL\n");
$GLOBALS['SNAP_SENDS'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $args): array {
    $GLOBALS['SNAP_SENDS'][] = $args;

    return ['ok' => true, 'from' => 'saljare@example.test'];
};
$snapOffer = static function (string $tid, string $body, array $extra = []): void {
    $live = jeab_inkorg_sql_store_live();
    snap_ok($live instanceof JeabPdoInkorgStore, 'inkorgen kan ta emot mejlet');
    $live->upsertMessage($tid . '-lead', [
        'id' => $tid . '-lead',
        'thread_id' => $tid,
        'source' => 'inbound',
        'from' => 'support@weply.se',
        'body' => $body,
        'subject' => 'Ny lead',
        'received_at' => '2026-01-08T10:00:00+01:00',
    ]);
    $live->upsertMessage($tid . '-quote', [
        'id' => $tid . '-quote',
        'thread_id' => $tid,
        'source' => 'outbound',
        'from' => 'saljare@example.test',
        'to' => 'nagon.annan@example.test',
        'subject' => 'Offert',
        'body' => "Offert\n[Bilaga: Elbilsladdare-offert.pdf]",
        'received_at' => '2026-01-08T11:00:00+01:00',
    ]);
    unset($GLOBALS['_JEAB_INKORG_MSGS'], $GLOBALS['_JEAB_INKORG_MSGS_FP'], $GLOBALS['_JEAB_INKORG_THREADS'], $GLOBALS['_JEAB_INKORG_THREADS_FP']);
    jeab_inkorg_thread_write($tid, static function (array $thread) use ($tid, $extra): array {
        $thread['id'] = $tid;
        $thread['company'] = 'jarfalla';
        $thread['customer_name'] = 'Trad Annan';
        $thread['customer_email'] = 'trad.annan@example.test';
        $thread['phone'] = '0701000288';
        $thread['address'] = 'Tradgatan 99';
        $thread['owners'] = [[
            'name' => 'Trad Agare',
            'email' => 'trad.annan@example.test',
            'phone' => '0701000288',
            'address' => 'Tradgatan 99',
        ]];
        foreach ($extra as $key => $value) {
            $thread[$key] = $value;
        }

        return $thread;
    });
};
$snapOffer('th-forsta', "Kund: Skickad Person · skickad.person@example.test · 0701112233");
$firstResend = jeab_offer_resend([
    'thread_id' => 'th-forsta',
    'dry' => '0',
    'confirm' => 'SAM',
]);
snap_ok(($firstResend['ok'] ?? false) === true && ($firstResend['action'] ?? '') === 'resent', 'första omskick utan kopia skickas');
$firstMeta = jeab_inkorg_thread_meta('th-forsta');
$firstSnap = jeab_snapshots_read(is_array($firstMeta) ? $firstMeta : [], 'offert');
snap_ok(is_array($firstSnap) && ($firstSnap['name'] ?? '') === 'Skickad Person' && ($firstSnap['email'] ?? '') === 'skickad.person@example.test', 'första omskick fryser det som skickades');
$firstOwner = '';
foreach ((array) ($firstSnap['owners'] ?? []) as $owner) {
    if (is_array($owner)) {
        $firstOwner .= (string) ($owner['name'] ?? '');
    }
}
snap_ok(
    !str_contains($firstOwner, 'Trad Agare')
    && !str_contains((string) ($firstSnap['address'] ?? ''), 'Tradgatan')
    && ($firstSnap['phone'] ?? '') !== '0701000288',
    'första omskick tar inte trådens senare kund'
);
$oldBytes = (string) ($GLOBALS['SNAP_SENDS'][0]['attachments'][0]['content'] ?? '');
snap_ok(str_contains($oldBytes, 'GAMMAL-FIL'), 'omskick utan ny version bifogar den gamla pdf-filen');
$snapOffer('th-nyversion', "Kund: Ny Version · ny.version@example.test · 0701112244", [
    'snapshots' => [
        'offert' => [
            'name' => 'Gammal Offert',
            'address' => 'Gammelgatan 1',
            'email' => 'gammal.offert@example.test',
            'phone' => '0701000200',
            'owners' => [],
            'at' => '2026-01-01T10:00:00+01:00',
            'source' => 'send',
        ],
    ],
]);
$GLOBALS['SNAP_SENDS'] = [];
$newResend = jeab_offer_resend([
    'thread_id' => 'th-nyversion',
    'dry' => '0',
    'confirm' => 'SAM',
    'new_version' => '1',
]);
snap_ok(($newResend['ok'] ?? true) === false && ($newResend['action'] ?? '') === 'stop' && str_contains((string) ($newResend['why'] ?? ''), 'varken bilaga eller mejltext'), 'new_version av offert skickas inte');
snap_ok($GLOBALS['SNAP_SENDS'] === [], 'new_version skickar varken bilaga eller mejl');
$newMeta = jeab_inkorg_thread_meta('th-nyversion');
$newSnap = jeab_snapshots_read(is_array($newMeta) ? $newMeta : [], 'offert');
snap_ok(is_array($newSnap) && ($newSnap['name'] ?? '') === 'Gammal Offert' && ($newSnap['source'] ?? '') === 'send', 'new_version lämnar den skickade offerten');
$seomHtml = jeab_seom_fullmakt_panel_html([
    'natbolag' => 'SEOM',
    'seom_sam_sent_at' => '2026-01-01T10:00:00+01:00',
    'reminder_id' => 88080,
], '/ordrar');
snap_ok(str_contains($seomHtml, 'name="new_version"') && str_contains($seomHtml, 'value="1"'), 'SEOM-knappen kan skicka new_version');
unset($GLOBALS['JEAB_GRAPH_SEND'], $GLOBALS['SNAP_SENDS']);

$nyContact = snap_contact([
    'name' => 'Ny Fullmakt',
    'email' => 'ny.fullmakt@example.test',
    'phone' => '0701000299',
    'address' => 'Nygatan 9, 176 70 Jarfalla',
    'street' => 'Nygatan 9',
    'city' => 'Jarfalla',
    'postal_code' => '17670',
    'owners' => [[
        'name' => 'Ny Fullmakt',
        'personnummer' => $contactPnr,
        'email' => 'ny.fullmakt@example.test',
        'phone' => '0701000299',
        'address' => 'Nygatan 9',
    ]],
]);
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    return ['ok' => true, 'path' => 'test', 'error' => null, 'id' => 'snap-mail'];
};
file_put_contents($private . '/secrets/bokmalen-ellevio-fullmakt-webhook.json', json_encode([
    'url' => 'https://bokmalen.example.invalid/hooks/fullmakt',
    'key' => 'ny-test-key',
]));
if (!is_dir($tmp . '/fullmakt')) {
    mkdir($tmp . '/fullmakt', 0775, true);
}
putenv('FULLMAKT_DATA=' . $tmp . '/fullmakt');
$_ENV['FULLMAKT_DATA'] = $tmp . '/fullmakt';
$frozenParty = [
    'name' => 'Fryst Ellevio',
    'address' => 'Frystgatan 1, 176 70 Jarfalla',
    'email' => 'fryst.ellevio@example.test',
    'phone' => '0701000290',
    'owners' => [[
        'name' => 'Fryst Ellevio',
        'address' => 'Frystgatan 1, 176 70 Jarfalla',
        'email' => 'fryst.ellevio@example.test',
        'phone' => '0701000290',
    ]],
    'at' => '2026-01-01T10:00:00+01:00',
    'source' => 'send',
];
$ellevioOrder = static function (int $id) use ($nyContact, $rowPnr, $frozenParty): array {
    return jeab_purchase_put([
        'reminder_id' => $id,
        'contact_id' => $nyContact,
        'company' => 'jarfalla',
        'kind' => 'sol',
        'natbolag' => 'Ellevio',
        'name' => 'Fryst Ellevio',
        'huvudsokande' => 'Fryst Ellevio',
        'email' => 'fryst.ellevio@example.test',
        'phone' => '0701000290',
        'address' => 'Frystgatan 1, 176 70 Jarfalla',
        'huvudsokande_address' => 'Frystgatan 1, 176 70 Jarfalla',
        'personnummer' => $rowPnr,
        'fullmakt_start_at' => '2026-01-01T10:00:00+01:00',
        'fullmakt_token' => 'gammal-' . $id,
        'snapshots' => ['fullmakt' => $frozenParty],
    ]);
};
$ellevioOrder(88050);
$keptNew = jeab_ellevio_fullmakt_new_from_input(['id' => 88050, 'confirm' => '1']);
$keptSnap = jeab_snapshots_read(is_array(jeab_purchase_get(88050)) ? jeab_purchase_get(88050) : [], 'fullmakt');
snap_ok(is_array($keptNew) && is_array($keptSnap) && ($keptSnap['name'] ?? '') === 'Fryst Ellevio' && ($keptSnap['source'] ?? '') === 'send', 'ny fullmakt utan new_version behåller den frysta parten');
$ellevioOrder(88051);
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
$replaced = jeab_ellevio_fullmakt_new_from_input(['id' => 88051, 'confirm' => '1', 'new_version' => '1']);
$replacedSnap = jeab_snapshots_read(is_array(jeab_purchase_get(88051)) ? jeab_purchase_get(88051) : [], 'fullmakt');
$hookName = '';
foreach ((array) ($GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? []) as $hook) {
    if (is_array($hook) && ($hook['type'] ?? '') === 'fullmakt.start') {
        $hookName = (string) ($hook['customer_name'] ?? '');
    }
}
snap_ok(is_array($replaced) && $hookName === 'Ny Fullmakt', 'new_version går genom new_from_input och använder kontakten');
snap_ok(is_array($replacedSnap) && ($replacedSnap['name'] ?? '') === 'Ny Fullmakt' && ($replacedSnap['source'] ?? '') === 'version', 'new_version byter den sparade fullmaktskopian');
$replacedJson = json_encode($replacedSnap, JSON_UNESCAPED_UNICODE);
snap_ok(is_string($replacedJson) && !str_contains($replacedJson, '19900101') && !str_contains($replacedJson, '19850505'), 'den nya fullmaktskopian har inget personnummer');

$seomOrder = static function (int $id) use ($nyContact, $rowPnr): array {
    return jeab_purchase_put([
        'reminder_id' => $id,
        'contact_id' => $nyContact,
        'company' => 'jarfalla',
        'kind' => 'sol',
        'natbolag' => 'SEOM',
        'anlaggnings_id' => '735999114010135815',
        'name' => 'Ny Fullmakt',
        'huvudsokande' => 'Ny Fullmakt',
        'email' => 'ny.fullmakt@example.test',
        'phone' => '0701000299',
        'address' => 'Nygatan 9, 176 70 Jarfalla',
        'huvudsokande_address' => 'Nygatan 9, 176 70 Jarfalla',
        'personnummer' => $rowPnr,
        'seom_sam_sent_at' => date('c', time() - 180),
        'snapshots' => [
            'fullmakt' => [
                'name' => 'Fryst Seom',
                'address' => 'Fryst Seom 1, 176 70 Jarfalla',
                'email' => 'fryst.seom@example.test',
                'phone' => '0701000291',
                'owners' => [[
                    'name' => 'Fryst Seom',
                    'address' => 'Fryst Seom 1, 176 70 Jarfalla',
                    'email' => 'fryst.seom@example.test',
                    'phone' => '0701000291',
                ]],
                'at' => '2026-01-01T10:00:00+01:00',
                'source' => 'send',
            ],
        ],
    ]);
};
$seomKept = jeab_seom_fullmakt_resend($seomOrder(88090));
$seomKeptSnap = jeab_snapshots_read(is_array($seomKept) ? $seomKept : [], 'fullmakt');
snap_ok(is_array($seomKeptSnap) && ($seomKeptSnap['name'] ?? '') === 'Fryst Seom' && ($seomKeptSnap['source'] ?? '') === 'send', 'SEOM-omskick utan new_version behåller den frysta parten');
$seomNew = jeab_seom_fullmakt_resend($seomOrder(88091), ['new_version' => '1']);
$seomNewSnap = jeab_snapshots_read(is_array($seomNew) ? $seomNew : [], 'fullmakt');
snap_ok(is_array($seomNewSnap) && ($seomNewSnap['name'] ?? '') === 'Ny Fullmakt' && ($seomNewSnap['source'] ?? '') === 'version', 'SEOM new_version byter kopian');
$seomJson = json_encode($seomNewSnap, JSON_UNESCAPED_UNICODE);
snap_ok(is_string($seomJson) && !str_contains($seomJson, '19900101') && !str_contains($seomJson, '19850505'), 'SEOM-kopian har inget personnummer');
unset($GLOBALS['JEAB_SYSTEM_MAIL'], $GLOBALS['_JEAB_BUG_WEBHOOKS']);

$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTP_X_WEBHOOK_TOKEN'], $_SERVER['HTTP_AUTHORIZATION']);
$denied = jeab_snapshots_dispatch(['action' => 'snapshots.audit']);
snap_ok(($denied['status'] ?? 0) === 401 && empty($denied['ok']), 'dispatch utan token får 401');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'tok-snapshots';
$allowed = jeab_contacts_sole_dispatch(['action' => 'snapshots.audit']);
snap_ok(($allowed['ok'] ?? false) === true && ($allowed['action'] ?? '') === 'snapshots.audit', 'kontakter-dispatch tar snapshots.audit');
snap_no_pnr($allowed, 'dispatch-audit');

$port = 18080 + random_int(0, 1000);
$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = " . var_export($private, true) . ";\n\$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = " . var_export($public, true) . ";\nrequire " . var_export($root . '/hook.php', true) . ";\n");
$log = $tmp . '/php-server.log';
$cmd = sprintf('exec php -S 127.0.0.1:%d -t %s %s', $port, escapeshellarg($root), escapeshellarg($router));
$proc = proc_open($cmd, [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
snap_ok(is_resource($proc), 'php -S startar');
register_shutdown_function(static function () use ($proc): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
});
$up = false;
for ($i = 0; $i < 40; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $errno, $err, 0.2);
    if (is_resource($fp)) {
        fclose($fp);
        $up = true;
        break;
    }
    usleep(100000);
}
snap_ok($up, 'php -S lyssnar');
$post = static function (string $body, string $token) use ($port): array {
    $ch = curl_init('http://127.0.0.1:' . $port . '/hook.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Token: ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $raw = is_string($raw) ? $raw : '';
    $parts = explode("\r\n\r\n", $raw, 2);
    $decoded = json_decode($parts[1] ?? '', true);

    return ['status' => $status, 'json' => is_array($decoded) ? $decoded : [], 'keys' => is_array($decoded) ? implode(',', array_keys($decoded)) : 'nojson'];
};
$curlDenied = $post('{"action":"snapshots.audit"}', 'fel');
snap_ok($curlDenied['status'] === 401 && empty($curlDenied['json']['ok']), 'curl utan token får 401');
$curlOk = $post('{"action":"snapshots.audit"}', 'tok-snapshots');
snap_ok($curlOk['status'] === 200 && ($curlOk['json']['action'] ?? '') === 'snapshots.audit' && ($curlOk['json']['read_only'] ?? false) === true, 'curl med token får auditen');
snap_no_pnr($curlOk['json'], 'curl snapshots.audit');
$curlDry = $post('{"action":"snapshots.backfill","dry":1}', 'tok-snapshots');
snap_ok($curlDry['status'] === 200 && ($curlDry['json']['dry'] ?? false) === true && ($curlDry['json']['written'] ?? true) === false, 'curl backfill dry skriver inget');
snap_no_pnr($curlDry['json'], 'curl snapshots.backfill');
$curlFill = $post('{"action":"snapshots.backfill","dry":"0"}', 'tok-snapshots');
snap_ok($curlFill['status'] === 200 && ($curlFill['json']['ok'] ?? false) === true && (int) ($curlFill['json']['failed'] ?? 1) === 0, 'curl backfill dry=0 skriver ångerfilen och svarar ok');
snap_no_pnr($curlFill['json'], 'curl snapshots.backfill dry=0');
$curlBatch = (string) ($curlFill['json']['batch_id'] ?? '');
$curlUndo = $post('{"action":"snapshots.backfill","undo":"1","batch_id":' . json_encode($curlBatch) . '}', 'tok-snapshots');
snap_ok($curlUndo['status'] === 200 && ($curlUndo['json']['ok'] ?? false) === true, 'curl undo tar bort påfyllnaden');
snap_no_pnr($curlUndo['json'], 'curl snapshots.backfill undo');
if (is_resource($proc)) {
    proc_terminate($proc);
    proc_close($proc);
}

echo "OK  snapshots freeze\n";
