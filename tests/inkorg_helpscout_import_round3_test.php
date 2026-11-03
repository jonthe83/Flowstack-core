<?php

declare(strict_types=1);

/**
 * Help Scout-import, omgång 3. Påhittade kontakter och ärenden. Ingen riktig adress.
 */

$tmp = sys_get_temp_dir() . '/jeab-hs3-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'people' => [
        ['id' => 'PersonalExempel', 'email' => 'personal.exempel@example.com', 'name' => 'Personal Exempel', 'company' => 'jarfalla', 'phone' => '0701000000'],
        ['id' => 'SamL', 'email' => 'admin.exempel@example.com', 'name' => 'Admin Exempel', 'company' => 'jarfalla'],
    ],
], true) . ";\n");
file_put_contents($tmp . '/helpscout.json', json_encode([
    'client_id' => 'hs-test-client',
    'client_secret' => 'hs-test-secret',
    'mailbox_id' => 111,
    'mailbox_id_malmo' => 222,
    'import_supplier_domains' => ['kgk.se'],
    'import_keep_domains' => ['post.se'],
    'import_supplier_domain_min' => 6,
], JSON_UNESCAPED_UNICODE));
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/layout.php';
jeab_ordrar_external_live(false);

function r3(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function r3_plan(array $hint): array
{
    return jeab_hs_import_contact_plan($hint);
}

function r3_owner(array $row): string
{
    $owners = is_array($row['owners'] ?? null) ? $row['owners'] : [];
    $first = is_array($owners[0] ?? null) ? $owners[0] : [];
    return trim((string) ($first['name'] ?? ''));
}

function r3_by_email(string $email): ?array
{
    $email = jeab_kontakt_email($email);
    foreach (jeab_kontakter_load() as $row) {
        if (is_array($row) && jeab_kontakt_row_email($row) === $email) {
            return $row;
        }
    }
    return null;
}

function r3_named(string $name): array
{
    $want = jeab_kontakt_fold_text($name);
    $out = [];
    foreach (jeab_kontakter_load() as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (jeab_kontakt_fold_text(r3_owner($row)) === $want) {
            $out[] = $row;
        }
    }
    return $out;
}

/** @param array<string, mixed> $extra */
function r3_store(string $n, string $email, string $name, string $body, string $contactId = '', string $company = 'jarfalla', array $extra = []): string
{
    $tid = 'hsi-' . substr(hash('sha256', 'r3-' . $n), 0, 16);
    $msg = [
        'id' => substr(hash('sha256', 'r3m-' . $n), 0, 16),
        'from' => $email,
        'from_name' => $name,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Fråga ' . $n,
        'body' => $body,
        'received_at' => '2024-04-02T09:00:00+00:00',
        'source' => 'helpscout_import',
        'company' => $company,
        'hs_thread_type' => 'customer',
        'labels' => ['type' => 'Support', 'action' => 'svara', 'priority' => 3],
        'internal_note' => false,
        'attachments' => [],
    ];
    foreach ($extra as $k => $v) {
        $msg[$k] = $v;
    }
    $conv = [
        'id' => $tid,
        'company' => $company,
        'status' => 'open',
        'import_source' => 'helpscout_import',
        'contact_id' => $contactId,
        'messages' => [$msg],
        'meta' => [
            'status' => 'open',
            'tags' => [],
            'assignee' => '',
            'read' => [],
            'contact_id' => $contactId,
            'import_source' => 'helpscout_import',
        ],
    ];
    r3(jeab_hs_import_save_conv($conv) && jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'ärende ' . $n . ' skrivs');
    return $tid;
}

$real = 'Hej, jag vill ha en laddbox till huset.';
$flipped = r3_plan(['name' => 'Exempelnamn, Alias', 'email' => 'kund1.test@example.invalid', 'body' => $real, 'real_customer' => true]);
r3($flipped['action'] === 'create' && $flipped['name'] === 'Alias Exempelnamn', 'Exempelnamn, Alias vänds och behålls');
$tight = r3_plan(['name' => 'Exempelnamn,Alias', 'email' => 'kund1b.test@example.invalid', 'body' => $real, 'real_customer' => true]);
r3($tight['action'] === 'create' && $tight['name'] === 'Alias Exempelnamn', 'Exempelnamn,Alias utan mellanslag vänds');
r3(jeab_hs_import_name_rejected('Exempelnamn, Alias') === false && jeab_kontakt_name_is_garbage('Exempelnamn, Alias') === false, 'komma-namnet är inte skräp efter normalisering');
$prepared = jeab_kontakt_prepare_sources([[
    'kind' => 'reminder',
    'id' => 'flip-1',
    'name' => 'Exempelnamn, Alias',
    'email' => 'kund1c.test@example.invalid',
    'street' => 'Exempelgatan 12',
    'city' => '',
]]);
r3(($prepared['items'][0]['name'] ?? '') === 'Alias Exempelnamn' && ($prepared['skipped'] ?? 1) === 0, 'kontakter normaliserar innan skräpfiltret');
$made = jeab_kontakt_find_or_create(['name' => 'Exempelnamn,Alias', 'email' => 'kund1d.test@example.invalid'], false);
r3(is_array($made) && r3_owner($made) === 'Alias Exempelnamn', 'find_or_create vänder namnet innan filtren');

$locals = [
    'kund.ett' => ['email' => 'kund.ett@gmail.com', 'pretty' => 'Kund Ett'],
    'kundettatvalangt' => ['email' => 'kundettatvalangt@gmail.com', 'pretty' => 'Kundettatvalangt'],
    'kund.tre' => ['email' => 'kund.tre@gmail.com', 'pretty' => 'Kund Tre'],
    'kund.fyra' => ['email' => 'kund.fyra@gmail.com', 'pretty' => 'Kund Fyra'],
    'kund.fem' => ['email' => 'kund.fem@gmail.com', 'pretty' => 'Kund Fem'],
];
foreach ($locals as $raw => $spec) {
    $plan = r3_plan(['name' => $raw, 'email' => $spec['email'], 'body' => $real, 'real_customer' => true]);
    r3($plan['action'] === 'create' && $plan['name'] === $spec['pretty'] && empty($plan['hint']['name_missing']), $spec['pretty'] . ' på konsumentdomän blir en kontakt');
}
$digit = r3_plan(['name' => 'kund42', 'email' => 'kund42@gmail.com', 'body' => $real, 'real_customer' => true]);
r3($digit['action'] === 'create' && $digit['name'] === 'kund42@gmail.com' && !empty($digit['hint']['name_missing']), 'siffror i local-delen visar mejlet och namn saknas');
$digitB = r3_plan(['name' => 'exempel7', 'email' => 'exempel7@gmail.com', 'body' => $real, 'real_customer' => true]);
r3($digitB['action'] === 'create' && $digitB['name'] === 'exempel7@gmail.com' && !empty($digitB['hint']['name_missing']), 'andra siffer-local blir mejlet');
$sigBody = "Hej\n\nJag vill ha en laddbox.\n\nVänliga hälsningar\nAnna Exempel\n";
$sig = r3_plan(['name' => 'kund42', 'email' => 'anna.exempel@gmail.com', 'body' => $sigBody, 'real_customer' => true]);
r3($sig['action'] === 'create' && $sig['name'] === 'Anna Exempel', 'signaturen vinner över local-delen');
$post = r3_plan(['name' => 'kund42', 'email' => 'kund42@post.se']);
r3($post['action'] === 'skip' && $post['shown'] === '', 'post.se utan riktigt konsumentmejl skapar ingen kontakt');
$auto = r3_plan(['name' => 'kund.auto', 'email' => 'kund.auto@gmail.com', 'body' => 'Detta är ett automatiskt meddelande.']);
r3($auto['action'] === 'skip' && jeab_hs_import_message_is_real_customer(['from' => 'kund.auto@gmail.com', 'from_name' => 'kund.auto', 'body' => 'Detta är ett automatiskt meddelande.', 'hs_thread_type' => 'customer']) === false, 'automatiskt mejl blir ingen kontakt');
$noreply = r3_plan(['name' => 'Kund Exempel', 'email' => 'no-reply@gmail.com', 'body' => $real, 'real_customer' => true]);
r3($noreply['action'] === 'skip', 'no-reply tas bort även på gmail');
foreach (['TEST TEST', 'namn', 'Sdfg', 'hjjkh', 'test22 sdg'] as $junk) {
    $plan = r3_plan(['name' => $junk, 'email' => 'skrap.kund@gmail.com', 'phone' => '0701000099', 'body' => $real, 'real_customer' => true]);
    r3($plan['action'] === 'skip', 'skräpnamn ' . $junk . ' tas bort på gmail');
}
$staff = r3_plan(['name' => 'Personal Exempel', 'email' => 'personal.kund@gmail.com', 'body' => $real, 'real_customer' => true]);
r3($staff['action'] === 'skip', 'personal tas bort');
$supplier = r3_plan(['name' => 'Exempel Support', 'email' => 'lev.exempel@gmail.com', 'body' => $real, 'real_customer' => true]);
r3($supplier['action'] === 'skip', 'leverantörsnamn tas bort');

$slash = r3_plan(['name' => 'Anna Exempel / Lund', 'email' => 'kund.ort@gmail.com', 'body' => $real, 'real_customer' => true]);
r3($slash['action'] === 'create' && $slash['name'] === 'Anna Exempel' && ($slash['hint']['city'] ?? '') === 'Lund', 'känd ort efter snedstreck lämnar namnet och fyller ort');
$hbg = r3_plan(['name' => 'Pelle Hbg', 'email' => 'kund.pelle@gmail.com', 'body' => $real, 'real_customer' => true]);
r3($hbg['action'] === 'create' && $hbg['name'] === 'Pelle' && ($hbg['hint']['city'] ?? '') === 'Helsingborg', 'Hbg lämnar namnet och blir Helsingborg');
$keptCity = r3_plan(['name' => 'Pelle Hbg', 'email' => 'kund.hbg@gmail.com', 'city' => 'Exempelstad', 'body' => $real, 'real_customer' => true]);
r3($keptCity['name'] === 'Pelle' && ($keptCity['hint']['city'] ?? '') === 'Exempelstad', 'en ifylld ort skrivs inte över');
$commaPlace = jeab_kontakt_normalize_person_name('Pelle, Hbg');
r3($commaPlace['name'] === 'Pelle' && $commaPlace['city'] === 'Helsingborg', 'ortförkortning efter komma är inte ett förnamn');

$phone = '0701000001';
$telA = jeab_kontakt_blank(['name' => 'Kund Telefon', 'email' => 'kund.telefon.a@gmail.com', 'phone' => $phone]);
$telB = jeab_kontakt_blank(['name' => 'Kund Telefon', 'phone' => $phone]);
$street = 'Exempelgatan 4';
$streetKey = jeab_kontakt_street_key($street);
$gataA = jeab_kontakt_blank(['name' => 'Kund Gata', 'email' => 'kund.gata@gmail.com', 'street' => $street, 'street_key' => $streetKey, 'phone' => '0701000011']);
$gataB = jeab_kontakt_blank(['name' => 'Kund Gata', 'street' => $street, 'street_key' => $streetKey, 'phone' => '0701000022']);
$krockA = jeab_kontakt_blank(['name' => 'Kund Konflikt', 'email' => 'kund.konflikt.a@gmail.com', 'phone' => '0701000003']);
$krockB = jeab_kontakt_blank(['name' => 'Kund Konflikt', 'email' => 'kund.konflikt.b@gmail.com', 'phone' => '0701000003']);
$sparad = jeab_kontakt_blank(['name' => 'Sparat, Namn', 'email' => 'kund.sparad@gmail.com']);
$pelle = jeab_kontakt_blank(['name' => 'Pelle Hbg', 'email' => 'kund.hbg@gmail.com', 'city' => 'Exempelstad']);
$last = jeab_kontakt_blank(['name' => 'Efternamn, Last', 'email' => 'kund.last@gmail.com']);
$last['owners'][0]['personnummer'] = 'sekretess';
$junkRow = jeab_kontakt_blank(['name' => 'Exempel Support', 'email' => 'bort@kgk.se', 'source' => 'hs-import']);
jeab_kontakter_mutate(function (array &$contacts) use ($telA, $telB, $gataA, $gataB, $krockA, $krockB, $sparad, $pelle, $last, $junkRow): void {
    foreach ([$telA, $telB, $gataA, $gataB, $krockA, $krockB, $sparad, $pelle, $last, $junkRow] as $row) {
        $contacts[(string) $row['id']] = $row;
    }
});

r3_store('flip', 'kund1.test@example.invalid', 'Exempelnamn, Alias', $real);
r3_store('ett', 'kund.ett@gmail.com', 'kund.ett', $real);
r3_store('lang', 'kundettatvalangt@gmail.com', 'kundettatvalangt', $real);
r3_store('tre', 'kund.tre@gmail.com', 'kund.tre', $real);
r3_store('fyra', 'kund.fyra@gmail.com', 'kund.fyra', $real);
r3_store('fem', 'kund.fem@gmail.com', 'kund.fem', $real);
r3_store('digit', 'kund42@gmail.com', 'kund42', $real);
r3_store('digitb', 'exempel7@gmail.com', 'exempel7', $real);
r3_store('sig', 'anna.exempel@gmail.com', 'kund42', $sigBody);
r3_store('ort', 'kund.ort@gmail.com', 'Anna Exempel / Lund', $real);
r3_store('tel-a', 'kund.telefon.a@gmail.com', 'Kund Telefon', $real, (string) $telA['id']);
r3_store('tel-b', '', 'Kund Telefon', $real, (string) $telB['id']);
r3_store('gata-a', 'kund.gata@gmail.com', 'Kund Gata', $real, (string) $gataA['id']);
r3_store('gata-b', '', 'Kund Gata', $real, (string) $gataB['id']);
r3_store('krock-a', 'kund.konflikt.a@gmail.com', 'Kund Konflikt', $real, (string) $krockA['id']);
r3_store('krock-b', 'kund.konflikt.b@gmail.com', 'Kund Konflikt', $real, (string) $krockB['id']);
r3_store('sparad', 'kund.sparad@gmail.com', 'Sparat, Namn', $real, (string) $sparad['id']);
r3_store('pelle', 'kund.hbg@gmail.com', 'Pelle Hbg', $real, (string) $pelle['id']);
r3_store('last', 'kund.last@gmail.com', 'Efternamn, Last', $real, (string) $last['id']);
r3_store('junk', 'bort@kgk.se', 'Exempel Support', 'Se fakturan.', (string) $junkRow['id']);
r3_store('auto', 'kund.auto@gmail.com', 'kund.auto', 'Detta är ett automatiskt meddelande.');
r3_store('post', 'kund42@post.se', 'kund42', $real);
r3_store('staff', 'personal.kund@gmail.com', 'Personal Exempel', $real);
r3_store('noreply', 'no-reply@gmail.com', 'Kund Exempel', $real);

$dry = jeab_hs_import_reprocess([]);
r3(!empty($dry['dry']) && ($dry['contacts_recreated'] ?? 0) === 10, 'torrkörning räknar återskapade kontakter');
r3(($dry['contacts_merged'] ?? 0) === 2 && ($dry['contacts_conflict'] ?? 0) === 1 && ($dry['contacts_removed'] ?? 0) === 1, 'torrkörning räknar ihopslagning, konflikt och borttag');
r3(r3_by_email('kund1.test@example.invalid') === null, 'torrkörning skapar inget');
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
r3(is_string($dryJson) && !str_contains($dryJson, '@') && !str_contains($dryJson, 'Alias') && !str_contains($dryJson, 'Exempelgatan'), 'torrkörningens svar är bara antal');

$wet = jeab_hs_import_reprocess(['dry' => 0]);
r3(empty($wet['dry']) && ($wet['contacts_recreated'] ?? 0) === 10, 'omkörning skapar de borttagna kontakterna');
r3(($wet['contacts_merged'] ?? 0) === 2 && ($wet['contacts_conflict'] ?? 0) === 1 && ($wet['contacts_removed'] ?? 0) === 1, 'omkörning slår ihop dubbletter och markerar konflikt');
$flipRow = r3_by_email('kund1.test@example.invalid');
r3(is_array($flipRow) && r3_owner($flipRow) === 'Alias Exempelnamn', 'Alias Exempelnamn kommer tillbaka');
r3(is_array(r3_by_email('kund.ett@gmail.com')) && r3_owner((array) r3_by_email('kund.ett@gmail.com')) === 'Kund Ett', 'Kund Ett kommer tillbaka');
r3(r3_owner((array) r3_by_email('kundettatvalangt@gmail.com')) === 'Kundettatvalangt', 'lång local-del skrivs med stor bokstav');
r3(r3_owner((array) r3_by_email('kund.tre@gmail.com')) === 'Kund Tre', 'Kund Tre kommer tillbaka');
$digitRow = r3_by_email('kund42@gmail.com');
r3(is_array($digitRow) && r3_owner($digitRow) === 'kund42@gmail.com' && !empty($digitRow['name_missing']), 'kund42 får mejlet som namn');
r3(r3_owner((array) r3_by_email('exempel7@gmail.com')) === 'exempel7@gmail.com' && !empty(r3_by_email('exempel7@gmail.com')['name_missing']), 'exempel7 markeras namn saknas');
r3(r3_owner((array) r3_by_email('anna.exempel@gmail.com')) === 'Anna Exempel', 'svag local-del med signatur blir Anna Exempel');
r3(r3_owner((array) r3_by_email('kund.fyra@gmail.com')) === 'Kund Fyra', 'Kund Fyra kommer tillbaka');
r3(r3_owner((array) r3_by_email('kund.fem@gmail.com')) === 'Kund Fem', 'Kund Fem kommer tillbaka');
$ortRow = r3_by_email('kund.ort@gmail.com');
r3(is_array($ortRow) && r3_owner($ortRow) === 'Anna Exempel' && ($ortRow['city'] ?? '') === 'Lund', 'Anna Exempel får orten');
$pelleRow = r3_by_email('kund.hbg@gmail.com');
r3(is_array($pelleRow) && r3_owner($pelleRow) === 'Pelle' && ($pelleRow['city'] ?? '') === 'Exempelstad', 'Pelle Hbg tar bort orten och behåller Exempelstad');
r3(r3_owner((array) r3_by_email('kund.sparad@gmail.com')) === 'Namn Sparat', 'Sparat, Namn vänds på den sparade kontakten');
r3(r3_owner((array) r3_by_email('kund.last@gmail.com')) === 'Efternamn, Last', 'låst kontakt med personnummer rörs inte');
r3(count(r3_named('Kund Telefon')) === 1 && r3_by_email('kund.telefon.a@gmail.com') !== null, 'Kund Telefon med samma telefon slås ihop');
r3(count(r3_named('Kund Gata')) === 1 && ($gataKept = r3_by_email('kund.gata@gmail.com')) && ($gataKept['street'] ?? '') === $street, 'Kund Gata med samma gata slås ihop');
$krockRows = r3_named('Kund Konflikt');
r3(count($krockRows) === 2 && !empty($krockRows[0]['conflict']) && !empty($krockRows[1]['conflict']), 'olika mejl med samma telefon blir en konflikt');
r3(r3_by_email('bort@kgk.se') === null && r3_by_email('kund.auto@gmail.com') === null && r3_by_email('kund42@post.se') === null && r3_by_email('no-reply@gmail.com') === null && r3_by_email('personal.kund@gmail.com') === null, 'skräp, automatik, post.se, no-reply och personal kommer inte tillbaka');
$wetJson = json_encode($wet, JSON_UNESCAPED_UNICODE);
r3(is_string($wetJson) && !str_contains($wetJson, '@') && !str_contains($wetJson, 'Alias') && !str_contains($wetJson, 'sekretess'), 'omkörningens svar är bara antal');

$store = jeab_store();
$error = null;
$flash = null;
$_GET = ['p' => 'kontakter'];
$html = (string) include dirname(__DIR__) . '/src/pages/kontakter.php';
r3(str_contains($html, 'Alias Exempelnamn') && str_contains($html, 'Kund Ett') && str_contains($html, 'Kund Fyra'), 'kontaktlistan visar de återskapade namnen');
r3(str_contains($html, 'namn saknas') && str_contains($html, 'kund42@gmail.com'), 'siffror i local-delen visas som namn saknas');
r3(str_contains($html, 'granska') && str_contains($html, 'Kund Konflikt'), 'konflikten visas som granska');
$_GET = ['p' => 'kontakter', 'id' => (string) ($digitRow['id'] ?? '')];
$detail = (string) include dirname(__DIR__) . '/src/pages/kontakter.php';
r3(str_contains($detail, 'namn saknas') && str_contains($detail, 'kund42@gmail.com'), 'kortet visar namn saknas');

$again = jeab_hs_import_reprocess(['dry' => 0]);
r3(($again['contacts_recreated'] ?? 1) === 0 && ($again['contacts_merged'] ?? 1) === 0 && ($again['contacts_removed'] ?? 1) === 0, 'andra omkörningen ändrar inte antalen');
r3(count(r3_named('Alias Exempelnamn')) === 2 && count(r3_named('Kund Telefon')) === 1, 'andra omkörningen skapar inga dubbletter');

$graphCalls = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function () use (&$graphCalls): array {
    $graphCalls++;
    return ['code' => 202, 'body' => []];
};
$native = jeab_inkorg_ingest([
    'message_id' => '<native-malmo-1@example.com>',
    'from' => 'native.kund@example.com',
    'from_name' => 'Native Kund',
    'to' => 'kontakt@malmo.example.test',
    'subject' => 'Native Malmö',
    'body_text' => $real,
    'source' => 'graph-malmo',
    'company' => 'malmo',
    'graph_mailbox' => 'kontakt@malmo.example.test',
]);
r3(!empty($native['ok']), 'inhemsk Malmö-tråd finns');
$nativeTid = (string) $native['thread_id'];
$nativeMid = (string) $native['id'];
r3(jeab_inkorg_thread_company_of($nativeTid) === 'malmo', 'inhemsk tråd räknas som Malmö');
$beforeStatus = (string) (jeab_inkorg_thread_meta($nativeTid)['status'] ?? '');
$writes = [
    'status' => static fn() => jeab_inkorg_thread_set($nativeTid, 'closed', null),
    'label' => static fn() => jeab_inkorg_relabel($nativeMid, ['type' => 'Support'], 'test'),
    'trash' => static fn() => jeab_inkorg_trash($nativeTid, true, 'test'),
    'note' => static fn() => jeab_inkorg_add_draft($nativeTid, ['kind' => 'note', 'body' => 'Anteckning'], 'test'),
    'reply' => static fn() => jeab_inkorg_send_in_thread($nativeTid, ['body' => 'Hej'], 'test'),
    'flag' => static fn() => jeab_inkorg_flag($nativeTid, 'skrap', 'test'),
    'assign' => static fn() => jeab_inkorg_assign($nativeTid, ''),
    'handled' => static fn() => jeab_inkorg_set_handled($nativeMid, true),
    'approve' => static fn() => jeab_inkorg_approve($nativeTid, 'd-saknas', 'test'),
    'draft' => static fn() => jeab_inkorg_draft_delete($nativeTid, 'd-saknas'),
];
foreach ($writes as $label => $fn) {
    $caught = null;
    try {
        $fn();
    } catch (Throwable $e) {
        $caught = $e;
    }
    r3($caught instanceof JeabInkorgWriteException && jeab_inkorg_error_status($caught) === 403, 'inhemsk Malmö ' . $label . ' ger 403');
}
r3((string) (jeab_inkorg_thread_meta($nativeTid)['status'] ?? '') === $beforeStatus && $graphCalls === 0, 'nekad Malmö-skrivning ändrar inget och skickar inget');
jeab_inkorg_mark_read($nativeTid, 'Test');
r3(true, 'läst-markering på Malmö är kvar');
$pw = $tmp . '/passwords-admin.json';
file_put_contents($pw, json_encode(['secret' => 'test-secret-value', 'hashes' => [['hash' => 'admin-hash-fixture', 'email' => 'admin.exempel@example.com']]]));
putenv('JEAB_PASSWORDS_FILE=' . $pw);
jeab_auth_data(true);
$_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value('admin.exempel@example.com', 'admin-hash-fixture');
try {
    jeab_inkorg_send_new(['company' => 'malmo', 'to' => 'mottagare@example.com', 'subject' => 'Hej', 'body' => 'Hej'], 'test');
    r3(false, 'nytt Malmö-mejl ska nekas');
} catch (JeabInkorgWriteException $e) {
    r3(jeab_inkorg_error_status($e) === 403 && $graphCalls === 0, 'nytt mejl till Malmö ger 403');
}

$malmoId = r3_store('malmo-lock', 'malmo.kund@example.com', 'Malmö Kund', $real, '', 'malmo');
foreach ([
    'status' => static fn() => jeab_inkorg_thread_set($malmoId, 'closed', null),
    'trash' => static fn() => jeab_inkorg_trash($malmoId, true, 'test'),
    'note' => static fn() => jeab_inkorg_add_draft($malmoId, ['kind' => 'note', 'body' => 'Anteckning'], 'test'),
    'reply' => static fn() => jeab_inkorg_send_in_thread($malmoId, ['body' => 'Hej'], 'test'),
] as $label => $fn) {
    $caught = null;
    try {
        $fn();
    } catch (Throwable $e) {
        $caught = $e;
    }
    r3($caught instanceof JeabInkorgWriteException && jeab_inkorg_error_status($caught) === 403, 'importerad Malmö ' . $label . ' ger 403');
}

$jarfallaId = r3_store('jarfalla-lock', 'jarfalla.kund@example.com', 'Järfälla Kund', $real, '', 'jarfalla');
$jarfallaSend = null;
try {
    jeab_inkorg_send_in_thread($jarfallaId, ['body' => 'Hej'], 'test');
} catch (Throwable $e) {
    $jarfallaSend = $e;
}
r3($jarfallaSend instanceof InvalidArgumentException && !($jarfallaSend instanceof JeabInkorgWriteException) && str_contains($jarfallaSend->getMessage(), 'skickas inte') && jeab_inkorg_error_status($jarfallaSend) === 422, 'importerat Järfälla-ärende svarar 422');
$live = jeab_inkorg_ingest([
    'message_id' => '<native-jarfalla-1@example.com>',
    'from' => 'live.kund@example.com',
    'from_name' => 'Live Kund',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Native Järfälla',
    'body_text' => $real,
    'source' => 'graph-jarfalla',
    'company' => 'jarfalla',
]);
jeab_inkorg_thread_set((string) $live['thread_id'], 'waiting_customer', null);
r3((jeab_inkorg_thread_meta((string) $live['thread_id'])['status'] ?? '') === 'waiting_customer', 'Järfälla går fortfarande att skriva');
r3(str_contains((string) file_get_contents(dirname(__DIR__) . '/index.php'), 'jeab_inkorg_error_status'), 'POST-svaret använder 403 för skrivlåset');

$_GET = ['p' => 'inkorg', 'view' => 'v-historik', 'box' => 'malmo', 'id' => $malmoId];
$mo = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
r3(!str_contains($mo, '>Skicka<') && !str_contains($mo, 'id="ix-composer"') && !str_contains($mo, 'Nytt mejl'), 'Malmösida har ingen Skicka');

echo "helpscout import round 3: ok\n";
