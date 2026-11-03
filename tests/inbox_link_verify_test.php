<?php

declare(strict_types=1);

/**
 * inbox.link_verify. Bara läsning. Svaret har bool|null och inga
 * påhittade e-postadresser, telefoner, namn eller ämnen.
 */

$tmp = sys_get_temp_dir() . '/jeab-ilv-' . bin2hex(random_bytes(4));
if (!mkdir($tmp . '/secrets', 0775, true) && !is_dir($tmp . '/secrets')) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('CONTACTS_SOLE');
putenv('CONTACTS_STRICT');
unset($_ENV['CONTACTS_SOLE'], $_ENV['CONTACTS_STRICT']);
$_SERVER['REQUEST_METHOD'] = 'POST';
$token = 'tok-link-verify';
file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => $token]));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

require dirname(__DIR__) . '/src/bootstrap.php';

function lv_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @param list<string> $forbidden */
function lv_no_pii(array $result, array $forbidden): void
{
    $json = json_encode($result, JSON_UNESCAPED_UNICODE);
    lv_assert(is_string($json), 'svaret kan kodas');
    foreach ($forbidden as $needle) {
        if ($needle !== '' && str_contains((string) $json, $needle)) {
            fwrite(STDERR, "FAIL personuppgift i svaret\n");
            exit(1);
        }
    }
    lv_assert(!str_contains((string) $json, '@'), 'svaret har ingen e-post');
}

$senderEmail = 'fake.sender@example.test';
$otherEmail = 'fake.other@example.test';
$laterEmail = 'fake.later@example.test';
$noteEmail = 'fake.note@example.test';
$outboundEmail = 'fake.outbound@example.test';
$decoyEmail = 'decoy.customer@example.test';
$mismatchEmail = 'fake.mismatch@example.test';
$senderPhone = '0701112233';
$senderPhoneNorm = '+46701112233';
$otherPhone = '0709998877';
$otherPhoneNorm = '+46709998877';
$mismatchPhone = '+46700000000';
$linkPhone = '070-555 66 77';
$linkPhoneNorm = '+46705556677';
$linkPhoneDigits = '46705556677';
$senderName = 'Fake Namnsson';
$senderNameFold = 'fake namnsson';
$subject = 'Fake Amne Hemligt';
$address = 'Fakegatan';
$emptyName = 'Fake Tomsson';
$emptyStreet = 'Tomgatan';
$emptySubject = 'Fake Tomt Amne';
$forbidden = [
    $senderEmail, $otherEmail, $laterEmail, $noteEmail, $outboundEmail, $decoyEmail, $mismatchEmail,
    $senderPhone, $senderPhoneNorm, '46701112233', $otherPhone, $otherPhoneNorm, '46709998877', $mismatchPhone,
    $linkPhone, $linkPhoneNorm, $linkPhoneDigits, '0705556677', 'Fake Phonsen',
    $senderName, $senderNameFold, $subject, $address, 'fakegatan', $emptyName, $emptyStreet, $emptySubject,
];

$root = dirname(__DIR__);
$hook = (string) file_get_contents($root . '/hook.php');
$dispatch = (string) file_get_contents($root . '/src/contacts_sole.php');
lv_assert(str_contains($hook, "'inbox.link_verify'"), 'hook.php tar inbox.link_verify');
lv_assert(str_contains($dispatch, "=== 'inbox.link_verify'") && str_contains($dispatch, 'jeab_inbox_link_verify('), 'dispatch skickar inbox.link_verify');
lv_assert(jeab_driver() === 'sqlite', 'testet använder sqlite');

$store = jeab_store(true);
lv_assert($store instanceof JeabPdoStore, 'pdo-lager');
$pdo = $store->pdo();

$contact = $pdo->prepare(
    'INSERT INTO contacts (id, row_json, street_key, postal_code, owner_email, owner_phone, owner_name_fold, email2, email3, company, city_fold, sort_key, sort_city, list_ready, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$now = '2026-10-01 10:00:00';
$putContact = static function (string $id, array $json, string $ownerEmail, string $ownerPhone, string $fold) use ($contact, $now): void {
    $contact->execute([
        $id,
        json_encode($json, JSON_UNESCAPED_UNICODE),
        '',
        '',
        $ownerEmail,
        $ownerPhone,
        $fold,
        '',
        '',
        'jarfalla',
        '',
        '',
        '',
        0,
        $now,
        $now,
    ]);
};
$putContact('c-sender', [
    'name' => $senderName,
    'email' => $senderEmail,
    'phone' => $senderPhoneNorm,
    'address' => $address . ' 1',
    'owners' => [['name' => $senderName, 'email' => $senderEmail, 'phone' => $senderPhoneNorm]],
], $senderEmail, $senderPhoneNorm, $senderNameFold);
$putContact('c-other', [
    'name' => $emptyName,
    'email' => $mismatchEmail,
    'phone' => $mismatchPhone,
    'address' => $emptyStreet . ' 2',
], $mismatchEmail, $mismatchPhone, '');
$putContact('c-empty', [
    'name' => $emptyName,
    'address' => $emptyStreet . ' 3',
], '', '', '');
$putContact('c-decoy', [
    'name' => 'Fake Decoy',
    'email' => $decoyEmail,
], $decoyEmail, '', '');
$forbidden[] = 'Fake Decoy';
$putContact('c-phone', [
    'name' => 'Fake Phonsen',
    'owners' => [['name' => 'Fake Phonsen', 'mobile' => $linkPhoneNorm, 'contact' => true]],
], '', '', 'fake phonsen');

$thread = $pdo->prepare(
    'INSERT INTO inkorg_list (id, company, customer_email, list_at, list_status, contact_id, row_json) VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$thread->execute([
    'th-match',
    'jarfalla',
    $decoyEmail,
    '2026-10-01 10:00:00',
    'open',
    'c-decoy',
    json_encode(['subject' => $subject, 'customer_email' => $decoyEmail], JSON_UNESCAPED_UNICODE),
]);
$thread->execute([
    'th-other',
    'jarfalla',
    $decoyEmail,
    '2026-10-02 10:00:00',
    'open',
    'c-decoy',
    json_encode(['subject' => $subject], JSON_UNESCAPED_UNICODE),
]);
$thread->execute([
    'th-empty',
    'jarfalla',
    '',
    '2026-10-03 10:00:00',
    'open',
    '',
    json_encode(['subject' => $emptySubject], JSON_UNESCAPED_UNICODE),
]);
$thread->execute([
    'th-phone',
    'jarfalla',
    '',
    '2026-10-04 10:00:00',
    'open',
    'c-phone',
    json_encode([
        'subject' => $subject,
        'first' => [
            'from' => $otherEmail,
            'source' => 'inbound',
            'body' => "Telefon: {$linkPhone}\n",
        ],
    ], JSON_UNESCAPED_UNICODE),
]);
$pdo->prepare('INSERT INTO inkorg_threads (id, status, updated_at, row_json) VALUES (?, ?, ?, ?)')->execute([
    'th-phone',
    'open',
    $now,
    json_encode(['subject' => $subject], JSON_UNESCAPED_UNICODE),
]);

$msg = $pdo->prepare(
    'INSERT INTO inkorg_messages (id, thread_id, received_at, from_addr, is_note, updated_at, row_json) VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$msg->execute([
    'm-note',
    'th-match',
    '2026-10-01 08:00:00',
    $noteEmail,
    1,
    $now,
    json_encode(['from' => $noteEmail, 'is_note' => 1, 'source' => 'inbound'], JSON_UNESCAPED_UNICODE),
]);
$msg->execute([
    'm-out',
    'th-match',
    '2026-10-01 09:00:00',
    $outboundEmail,
    0,
    $now,
    json_encode(['from' => $outboundEmail, 'source' => 'outbound-mail', 'is_note' => 0], JSON_UNESCAPED_UNICODE),
]);
$msg->execute([
    'm-send',
    'th-match',
    '2026-10-01 10:00:00',
    $senderName . ' <' . $senderEmail . '>',
    0,
    $now,
    json_encode([
        'from' => $senderName . ' <' . $senderEmail . '>',
        'source' => 'inbound',
        'phone' => $senderPhone,
        'subject' => $subject,
        'body' => 'Adress ' . $address . ' 9',
        'is_note' => 0,
    ], JSON_UNESCAPED_UNICODE),
]);
$msg->execute([
    'm-later',
    'th-match',
    '2026-10-01 11:00:00',
    $laterEmail,
    0,
    $now,
    json_encode(['from' => $laterEmail, 'source' => 'inbound', 'phone' => $mismatchPhone], JSON_UNESCAPED_UNICODE),
]);
$msg->execute([
    'm-other',
    'th-other',
    '2026-10-02 10:00:00',
    $otherEmail,
    0,
    $now,
    json_encode(['from' => $otherEmail, 'source' => 'inbound', 'phone' => $otherPhone, 'subject' => $subject], JSON_UNESCAPED_UNICODE),
]);
$msg->execute([
    'm-phone-decoy',
    'th-phone',
    '2026-10-04 09:00:00',
    $otherEmail,
    0,
    $now,
    json_encode([
        'from' => $otherEmail,
        'source' => 'inbound',
        'phone' => $mismatchPhone,
        'subject' => $subject,
        'body' => 'Hej',
    ], JSON_UNESCAPED_UNICODE),
]);

$undo = $pdo->prepare(
    'INSERT INTO inbox_link_contacts_undo (undo_id, created_at, thread_id, tbl, old_contact_id, new_contact_id, action, old_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
$undoId = 'a11ce0000000c0de';
$undo->execute([$undoId, $now, 'th-match', 'inkorg_list', '', 'c-sender', 'link', $senderName]);
$linkMatch = (int) $pdo->lastInsertId();
$undo->execute([$undoId, $now, 'c-sender', 'contacts', '', 'c-sender', 'fill_name', $senderName]);
$undo->execute([$undoId, $now, 'th-other', 'inkorg_list', '', 'c-other', 'link', $emptyName]);
$linkOther = (int) $pdo->lastInsertId();
$undo->execute([$undoId, $now, 'c-other', 'contacts', '', 'c-other', 'create', $senderName]);
$undo->execute([$undoId, $now, 'th-empty', 'inkorg_list', '', 'c-empty', '', $emptyName]);
$linkEmpty = (int) $pdo->lastInsertId();

$onlyId = 'b22ce0000000c0de';
$undo->execute([$onlyId, $now, 'c-sender', 'contacts', '', 'c-sender', 'create', $senderName]);

$phoneUndo = 'd44ce0000000c0de';
$phoneBy = json_encode(['match_by' => 'phone'], JSON_UNESCAPED_UNICODE);
$undo->execute([$phoneUndo, $now, 'th-phone', 'inkorg_threads', '', 'c-phone', 'link', $phoneBy]);
$undo->execute([$phoneUndo, $now, 'th-phone', 'inkorg_list', '', 'c-phone', 'link', $phoneBy]);
$linkPhoneId = (int) $pdo->lastInsertId();

$snapshot = $pdo->query(
    'SELECT id, undo_id, thread_id, tbl, old_contact_id, new_contact_id, action, old_value, undone_at FROM inbox_link_contacts_undo ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);
$contactSnap = $pdo->query('SELECT id, row_json, owner_email, owner_phone FROM contacts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_contacts_sole_dispatch(['action' => 'inbox.link_verify', 'undo_id' => $senderEmail]);
lv_no_pii($denied, $forbidden);
lv_assert(($denied['ok'] ?? true) === false && ($denied['status'] ?? 0) === 401, 'fel token');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

$bad = jeab_contacts_sole_dispatch(['action' => 'inbox.link_verify', 'undo_id' => $senderEmail]);
lv_no_pii($bad, $forbidden);
lv_assert(($bad['ok'] ?? true) === false && ($bad['status'] ?? 0) === 400 && !isset($bad['undo_id']) && !isset($bad['rows']), 'ogiltigt undo_id skrivs inte ut');

$missingKey = jeab_contacts_sole_dispatch(['action' => 'inbox.link_verify']);
lv_no_pii($missingKey, $forbidden);
lv_assert(($missingKey['status'] ?? 0) === 400, 'undo_id krävs');

$unknown = jeab_contacts_sole_dispatch(['action' => 'inbox.link_verify', 'undo_id' => 'c33ce0000000c0de']);
lv_no_pii($unknown, $forbidden);
lv_assert(($unknown['ok'] ?? true) === false && ($unknown['status'] ?? 0) === 404 && ($unknown['error'] ?? '') === 'Okänt undo_id.', 'okänt undo_id');

$only = jeab_contacts_sole_dispatch(['action' => 'inbox.link_verify', 'undo_id' => $onlyId]);
lv_no_pii($only, $forbidden);
lv_assert(($only['ok'] ?? false) === true && ($only['rows'] ?? null) === [] && array_keys($only) === ['ok', 'undo_id', 'rows', 'status'], 'bara create ger tom lista');

$result = jeab_contacts_sole_dispatch(['action' => 'inbox.link_verify', 'undo_id' => $undoId]);
lv_no_pii($result, $forbidden);
lv_assert(($result['ok'] ?? false) === true && ($result['undo_id'] ?? '') === $undoId && ($result['status'] ?? 0) === 200, 'läsningen svarar ok');
lv_assert(array_keys($result) === ['ok', 'undo_id', 'rows', 'status'], 'svaret har bara de fyra fälten');
$rows = $result['rows'] ?? null;
lv_assert(is_array($rows) && count($rows) === 3, 'tre länk-rader');
$expect = [
    ['index' => 0, 'link_id' => $linkMatch, 'thread_ref_kind' => 'list', 'email_match' => true, 'phone_match' => true, 'match_by' => null],
    ['index' => 1, 'link_id' => $linkOther, 'thread_ref_kind' => 'list', 'email_match' => false, 'phone_match' => false, 'match_by' => null],
    ['index' => 2, 'link_id' => $linkEmpty, 'thread_ref_kind' => 'list', 'email_match' => null, 'phone_match' => null, 'match_by' => null],
];
$rowKeys = ['index', 'link_id', 'thread_ref_kind', 'email_match', 'phone_match', 'match_by'];
foreach ($expect as $i => $want) {
    $row = $rows[$i] ?? null;
    lv_assert(is_array($row) && array_keys($row) === $rowKeys, 'rad ' . $i . ' har bara de sex fälten');
    lv_assert(($row['index'] ?? -1) === $want['index'] && (int) ($row['link_id'] ?? 0) === $want['link_id'], 'rad ' . $i . ' pekar på länk-raden');
    lv_assert(($row['thread_ref_kind'] ?? '') === $want['thread_ref_kind'], 'rad ' . $i . ' har trådtyp utan tabellnamn');
    lv_assert($row['email_match'] === $want['email_match'] && $row['phone_match'] === $want['phone_match'], 'rad ' . $i . ' har rätt bool eller null');
    lv_assert(array_key_exists('match_by', $row) && $row['match_by'] === $want['match_by'], 'rad ' . $i . ' har match_by');
    lv_assert(
        ($row['email_match'] === null || is_bool($row['email_match'])) && ($row['phone_match'] === null || is_bool($row['phone_match'])),
        'rad ' . $i . ' är bool eller null'
    );
}

$phoneResult = jeab_contacts_sole_dispatch(['action' => 'inbox.link_verify', 'undo_id' => $phoneUndo]);
lv_no_pii($phoneResult, $forbidden);
lv_assert(($phoneResult['ok'] ?? false) === true && ($phoneResult['status'] ?? 0) === 200, 'telefonlänken svarar ok');
$phoneRows = $phoneResult['rows'] ?? null;
lv_assert(is_array($phoneRows) && count($phoneRows) === 1, 'list och tråd är en länk');
$phoneRow = $phoneRows[0] ?? null;
lv_assert(is_array($phoneRow) && array_keys($phoneRow) === $rowKeys, 'telefonlänken har bara de sex fälten');
lv_assert(
    ($phoneRow['index'] ?? -1) === 0
    && (int) ($phoneRow['link_id'] ?? 0) === $linkPhoneId
    && ($phoneRow['thread_ref_kind'] ?? '') === 'list'
    && $phoneRow['email_match'] === null
    && $phoneRow['phone_match'] === true
    && ($phoneRow['match_by'] ?? null) === 'phone',
    'telefonlänken matchar mobilfältet och match_by'
);

$again = $pdo->query(
    'SELECT id, undo_id, thread_id, tbl, old_contact_id, new_contact_id, action, old_value, undone_at FROM inbox_link_contacts_undo ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);
$contactAgain = $pdo->query('SELECT id, row_json, owner_email, owner_phone FROM contacts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
lv_assert($again === $snapshot, 'ångerloggen är oförändrad');
lv_assert($contactAgain === $contactSnap, 'kontakterna är oförändrade');

echo "ALLA OK\n";
