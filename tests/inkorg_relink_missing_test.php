<?php

declare(strict_types=1);

/**
 * Ny lead från inkorgen: tråd och listrad får påminnelsens contact_id i samma flöde.
 * Järfälla och Malmö. inkorg.relink_missing är torr som standard.
 * dry=0 skriver en undo-fil 0600. Kontakter slås inte ihop. Inget skickas.
 */

$tmp = sys_get_temp_dir() . '/jeab-relink-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('CONTACTS_STRICT=1');
putenv('CONTACTS_SOLE=1');
putenv('XAI_API_KEY');
unset($_ENV['XAI_API_KEY'], $_SERVER['XAI_API_KEY']);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function rl(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $args): array {
    $GLOBALS['_SENT'][] = $args;

    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['_JEAB_KLASSNING_NO_SEND'] = 1;
rl(jeab_contacts_strict() && jeab_contacts_sole(), 'CONTACTS_STRICT och CONTACTS_SOLE är på');

$pdo = jeab_store()->pdo();
rl(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan');
$inkorg = jeab_inkorg_pdo_store();
rl($inkorg instanceof JeabPdoInkorgStore, 'SQL-lager för inkorgen');

$pnr = '199001011234';
rl(str_contains((string) file_get_contents(dirname(__DIR__) . '/hook.php'), "=== 'inkorg.relink_missing'"), 'hook.php tar inkorg.relink_missing före omskrivningen');

function rl_list_contact(PDO $pdo, string $tid): string
{
    $stmt = $pdo->prepare('SELECT contact_id FROM inkorg_list WHERE id = ?');
    $stmt->execute([$tid]);
    $col = $stmt->fetchColumn();

    return is_string($col) ? trim($col) : '';
}

function rl_thread(string $id, string $brand, string $offer, string $body, string $name, string $email): array
{
    $msg = [
        'from' => $email,
        'from_name' => $name,
        'subject' => 'Förfrågan ' . $offer,
        'body' => $body,
        'body_text' => $body,
        'labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => $offer, 'product' => $offer, 'confidence' => 90, 'source' => 'test'],
    ];

    return [
        'id' => $id,
        'status' => 'open',
        'assignee' => '',
        'deleted' => false,
        'tags' => [],
        'brand' => $brand,
        'company' => $brand,
        'messages' => [$msg],
        'first' => $msg,
    ];
}

$jarfalla = rl_thread(
    'jf-relink-lead',
    'jarfalla',
    'laddbox',
    "Namn: Ada Relink\nE-post: ada.relink@exempel.test\nTelefon: 070-100 20 01\nAdress: Exempelgatan 16, 177 30 Järfälla\n",
    'Ada Relink',
    'ada.relink@exempel.test'
);
$sentBefore = count($GLOBALS['_SENT']);
$made = jeab_lead_process($jarfalla, true);
$rem = jeab_get_reminder(jeab_store(), (int) ($made['reminder'] ?? 0));
rl(is_array($rem), 'Järfälla skapar en påminnelse');
$jfContact = trim((string) ($rem['contact_id'] ?? ''));
rl($jfContact !== '', 'Järfälla-påminnelsen får contact_id');
$jfMeta = jeab_inkorg_thread_meta('jf-relink-lead');
rl((string) ($jfMeta['contact_id'] ?? '') === $jfContact, 'Järfälla-tråden får samma contact_id');
rl(rl_list_contact($pdo, 'jf-relink-lead') === $jfContact, 'Järfälla-listraden får samma contact_id');
rl(trim((string) ($jfMeta['customer_email'] ?? '')) === '' && trim((string) ($jfMeta['customer_name'] ?? '')) === '', 'Järfälla-tråden lagrar ingen namn- eller mejlkopia');
$jfLead = is_array($jfMeta['lead_customer'] ?? null) ? $jfMeta['lead_customer'] : [];
rl(trim((string) ($jfLead['email'] ?? '')) === '' && trim((string) ($jfLead['name'] ?? '')) === '' && trim((string) ($jfLead['phone'] ?? '')) === '' && trim((string) ($jfLead['address'] ?? '')) === '', 'Järfälla-tråden lagrar ingen lead_customer-kopia');
rl(count($GLOBALS['_SENT']) === $sentBefore, 'Järfälla-leadet skickar inget');

jeab_inkorg_thread_write('mhs-relinktest', static function (array $t): array {
    $t['status'] = 'open';
    $t['brand'] = 'malmo';
    $t['company'] = 'malmo';
    $t['created_at'] = date('c');
    $t['tags'] = [];

    return $t;
});
$filled = jeab_malmo_hs_fill(
    'mhs-relinktest',
    '88021001',
    ['jobs' => ['laddbox']],
    'malmo.relink@exempel.test',
    'Bo Relink',
    ''
);
rl(!empty($filled['reminder_ready']) || !empty($filled['created']), 'Malmö skapar en påminnelse, fick ' . json_encode(['created' => $filled['created'] ?? null, 'ready' => $filled['reminder_ready'] ?? null, 'error' => $filled['error'] ?? '']));
$malmoRem = null;
foreach (jeab_reminders_every(jeab_store()) as $row) {
    if (!is_array($row)) {
        continue;
    }
    if (trim((string) ($row['thread_id'] ?? '')) === 'mhs-relinktest' || str_contains((string) ($row['note'] ?? ''), '88021001')) {
        $malmoRem = $row;
    }
}
rl(is_array($malmoRem), 'Malmö-påminnelsen finns');
$meContact = trim((string) ($malmoRem['contact_id'] ?? ''));
rl($meContact !== '' && $meContact !== $jfContact, 'Malmö får en egen contact_id');
$meMeta = jeab_inkorg_thread_meta('mhs-relinktest');
rl((string) ($meMeta['contact_id'] ?? '') === $meContact, 'Malmö-tråden får samma contact_id');
rl(rl_list_contact($pdo, 'mhs-relinktest') === $meContact, 'Malmö-listraden får samma contact_id');
rl(trim((string) ($meMeta['customer_email'] ?? '')) === '' && trim((string) ($meMeta['customer_name'] ?? '')) === '', 'Malmö-tråden lagrar ingen namn- eller mejlkopia');
$meLead = is_array($meMeta['lead_customer'] ?? null) ? $meMeta['lead_customer'] : [];
rl(trim((string) ($meLead['email'] ?? '')) === '' && trim((string) ($meLead['name'] ?? '')) === '' && trim((string) ($meLead['phone'] ?? '')) === '' && trim((string) ($meLead['address'] ?? '')) === '', 'Malmö-tråden lagrar ingen lead_customer-kopia');
rl(count($GLOBALS['_SENT']) === $sentBefore, 'Malmö-importen skickar inget');
$contactsBefore = count(jeab_kontakter_load());

function rl_gap(string $tid, string $company, string $email, string $name): string
{
    jeab_inkorg_thread_write($tid, static function (array $t) use ($company): array {
        $t['status'] = 'open';
        $t['brand'] = $company;
        $t['company'] = $company;
        $t['created_at'] = date('c');
        $t['tags'] = [];
        unset($t['contact_id']);

        return $t;
    });
    $row = jeab_create_reminder(jeab_store(), $name . ' – Batteri', jeab_today_start(), 'Lucka ' . $tid, null, [
        'customer' => $name,
        'email' => $email,
        'company' => $company,
        'who' => $company === 'malmo' ? 'Kim' : 'Elin',
        'thread_id' => $tid,
        'lookup' => true,
    ]);
    $id = trim((string) ($row['contact_id'] ?? ''));
    if ($id !== '' && function_exists('jeab_kontakt_get')) {
        $contact = jeab_kontakt_get($id);
        if (is_array($contact)) {
            $contact['personnummer'] = '199001011234';
            // Personnumret ligger bara på kontakten. Hooken ska inte läsa eller skriva det.
        }
    }

    return $id;
}

$gapJf = rl_gap('gap-jarfalla', 'jarfalla', 'gap.jarfalla@exempel.test', 'Cia Relink');
$gapMe = rl_gap('gap-malmo', 'malmo', 'gap.malmo@exempel.test', 'Dan Relink');
rl($gapJf !== '' && $gapMe !== '' && $gapJf !== $gapMe, 'luckorna har var sin kontakt');
rl((string) (jeab_inkorg_thread_meta('gap-jarfalla')['contact_id'] ?? '') === '', 'luckans Järfälla-tråd saknar contact_id');
rl((string) (jeab_inkorg_thread_meta('gap-malmo')['contact_id'] ?? '') === '', 'luckans Malmö-tråd saknar contact_id');

function rl_rid_gap(PDO $pdo, string $tid, string $company, string $email, string $name): string
{
    jeab_inkorg_thread_write($tid, static function (array $t) use ($company): array {
        $t['status'] = 'open';
        $t['brand'] = $company;
        $t['company'] = $company;
        $t['created_at'] = date('c');
        $t['tags'] = [];
        unset($t['contact_id']);

        return $t;
    });
    $row = jeab_create_reminder(jeab_store(), $name . ' – Batteri', jeab_today_start(), 'Saknar tradlank', null, [
        'customer' => $name,
        'email' => $email,
        'company' => $company,
        'who' => $company === 'malmo' ? 'Kim' : 'Elin',
        'thread_id' => $tid,
        'lookup' => true,
    ]);
    $rid = (int) ($row['id'] ?? 0);
    $id = trim((string) ($row['contact_id'] ?? ''));
    $pdo->prepare('UPDATE reminders SET thread_id = ?, note = ? WHERE id = ?')->execute(['', 'Saknar tradlank', $rid]);
    jeab_inkorg_thread_write($tid, static function (array $t) use ($company, $rid): array {
        $t['status'] = 'open';
        $t['brand'] = $company;
        $t['company'] = $company;
        $t['reminder_id'] = $rid;
        $t['tags'] = [];
        unset($t['contact_id']);

        return $t;
    });

    return $id;
}

$ridMe = rl_rid_gap($pdo, 'mhs-b1239bf59bef67b2', 'malmo', 'rid.malmo@exempel.test', 'Kim Rid');
$ridJf = rl_rid_gap($pdo, 'jf-b1239bf59bef67b2', 'jarfalla', 'rid.jarfalla@exempel.test', 'Elin Rid');
rl($ridMe !== '' && $ridJf !== '' && $ridMe !== $ridJf, 'reminder_id-luckorna har var sin kontakt');
rl((int) (jeab_inkorg_thread_meta('mhs-b1239bf59bef67b2')['reminder_id'] ?? 0) > 0, 'Malmö-tråden har reminder_id');
rl(trim((string) (jeab_inkorg_thread_meta('mhs-b1239bf59bef67b2')['contact_id'] ?? '')) === '', 'Malmö-tråden med reminder_id saknar contact_id');
rl(trim((string) (jeab_inkorg_thread_meta('jf-b1239bf59bef67b2')['contact_id'] ?? '')) === '', 'Järfälla-tråden med reminder_id saknar contact_id');

$dry = jeab_inkorg_relink_missing([]);
rl(!empty($dry['ok']) && (int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['sent'] ?? 1) === 0 && ($dry['would_send'] ?? true) === false, 'dry=1 är standard och sent är 0');
$dryIds = [];
foreach ((array) ($dry['threads'] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }
    rl(!isset($row['email']) && !isset($row['phone']) && !isset($row['address']) && !isset($row['note']) && !isset($row['personnummer']), 'svaret har inga kundfält');
    $dryIds[(string) ($row['thread'] ?? '')] = (string) ($row['contact_id'] ?? '');
}
rl(($dryIds['gap-jarfalla'] ?? '') === $gapJf && ($dryIds['gap-malmo'] ?? '') === $gapMe, 'dry listar båda bolagen');
rl(($dryIds['mhs-b1239bf59bef67b2'] ?? '') === $ridMe && ($dryIds['jf-b1239bf59bef67b2'] ?? '') === $ridJf, 'dry hittar tråd via reminder_id när påminnelsen saknar thread_id');
rl(!is_dir($tmp . '/inkorg-relink-missing'), 'dry skriver ingen undo-fil');
rl(!str_contains(json_encode($dry), $pnr), 'dry-svaret har inget personnummer');

$wrote = jeab_inkorg_relink_missing(['dry' => 0]);
rl(!empty($wrote['ok']) && (int) ($wrote['written'] ?? 0) >= 2 && (int) ($wrote['sent'] ?? 1) === 0, 'dry=0 sätter contact_id');
rl((string) (jeab_inkorg_thread_meta('gap-jarfalla')['contact_id'] ?? '') === $gapJf, 'Järfälla-luckan länkas');
rl((string) (jeab_inkorg_thread_meta('gap-malmo')['contact_id'] ?? '') === $gapMe, 'Malmö-luckan länkas');
rl((string) (jeab_inkorg_thread_meta('mhs-b1239bf59bef67b2')['contact_id'] ?? '') === $ridMe, 'Malmö länkas via trådens reminder_id');
rl((string) (jeab_inkorg_thread_meta('jf-b1239bf59bef67b2')['contact_id'] ?? '') === $ridJf, 'Järfälla länkas via trådens reminder_id');
rl(rl_list_contact($pdo, 'gap-jarfalla') === $gapJf && rl_list_contact($pdo, 'gap-malmo') === $gapMe, 'listraderna länkas');
$undoId = (string) ($wrote['undo_id'] ?? '');
$undoPath = $tmp . '/inkorg-relink-missing/' . $undoId . '.json';
rl($undoId !== '' && is_file($undoPath), 'undo-filen ligger under data/.private');
rl((fileperms($undoPath) & 0777) === 0600, 'undo-filen är chmod 600');
$undoRaw = (string) file_get_contents($undoPath);
rl(!str_contains($undoRaw, $pnr) && !str_contains($undoRaw, '@'), 'undo-filen har varken personnummer eller e-post');
rl(count(jeab_kontakter_load()) === $contactsBefore + 4, 'inga kontakter slogs ihop');

$again = jeab_inkorg_relink_missing(['dry' => '0']);
rl((int) ($again['written'] ?? 1) === 0, 'en andra körning skriver inget');

$keepContact = jeab_kontakt_find_or_create([
    'name' => 'Eva Kvar',
    'email' => 'eva.kvar@exempel.test',
    'company' => 'jarfalla',
], false, false, true);
$otherContact = jeab_kontakt_find_or_create([
    'name' => 'Fia Annan',
    'email' => 'fia.annan@exempel.test',
    'company' => 'jarfalla',
], false, false, true);
rl(is_array($keepContact) && is_array($otherContact) && (string) $keepContact['id'] !== (string) $otherContact['id'], 'två kontakter finns');
$keepId = (string) $keepContact['id'];
$otherId = (string) $otherContact['id'];
jeab_inkorg_thread_write('gap-kept', static function (array $t) use ($keepId): array {
    $t['status'] = 'open';
    $t['brand'] = 'jarfalla';
    $t['company'] = 'jarfalla';
    $t['contact_id'] = $keepId;
    $t['created_at'] = date('c');
    $t['tags'] = [];

    return $t;
});
$keptRem = jeab_create_reminder(jeab_store(), 'Fia Annan – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Fia Annan',
    'email' => 'fia.annan@exempel.test',
    'company' => 'jarfalla',
    'who' => 'Elin',
    'thread_id' => 'gap-kept',
    'contact_id' => $otherId,
]);
$pdo->prepare('UPDATE reminders SET contact_id = ?, thread_id = ? WHERE id = ?')->execute([$otherId, 'gap-kept', (int) ($keptRem['id'] ?? 0)]);
jeab_inkorg_thread_write('gap-split', static function (array $t): array {
    $t['status'] = 'open';
    $t['brand'] = 'malmo';
    $t['company'] = 'malmo';
    $t['created_at'] = date('c');
    $t['tags'] = [];
    unset($t['contact_id']);

    return $t;
});
$store = jeab_store();
$now = jeab_now();
$splitA = $store->insertReminder([
    'title' => 'Eva Kvar – Batteri',
    'customer_name' => 'Eva Kvar',
    'email' => 'eva.kvar@exempel.test',
    'company' => 'malmo',
    'thread_id' => 'gap-split',
    'due_at' => jeab_today_start(),
    'created_at' => $now,
    'updated_at' => $now,
]);
$splitB = $store->insertReminder([
    'title' => 'Fia Annan – Batteri',
    'customer_name' => 'Fia Annan',
    'email' => 'fia.annan@exempel.test',
    'company' => 'malmo',
    'thread_id' => 'gap-split',
    'due_at' => jeab_today_start(),
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$keepId, (int) ($splitA['id'] ?? 0)]);
$pdo->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$otherId, (int) ($splitB['id'] ?? 0)]);
$splitBefore = count(jeab_kontakter_load());
$guard = jeab_inkorg_relink_missing(['dry' => 0]);
rl((string) (jeab_inkorg_thread_meta('gap-kept')['contact_id'] ?? '') === $keepId, 'en annan contact_id skrivs inte över');
rl(trim((string) (jeab_inkorg_thread_meta('gap-split')['contact_id'] ?? '')) === '', 'tvetydig tråd lämnas');
rl((int) ($guard['ambiguous'] ?? 0) >= 1 && (int) ($guard['kept'] ?? 0) >= 1, 'tvetydig och redan länkad räknas');
rl(count(jeab_kontakter_load()) === $splitBefore, 'kroken skapar och slår inte ihop kontakter');
rl((string) (jeab_kontakt_get($keepId)['email'] ?? jeab_kontakt_get($keepId)['owner_email'] ?? '') !== '' || jeab_kontakt_row_email(jeab_kontakt_get($keepId) ?: []) === 'eva.kvar@exempel.test', 'Evas e-post ligger kvar');

$undoDry = jeab_inkorg_relink_missing(['undo' => $undoId]);
rl((int) ($undoDry['dry'] ?? 0) === 1 && (int) ($undoDry['written'] ?? 1) === 0 && (string) (jeab_inkorg_thread_meta('gap-jarfalla')['contact_id'] ?? '') === $gapJf, 'undo utan dry=0 skriver inte');
$undoWet = jeab_inkorg_relink_missing(['undo' => $undoId, 'dry' => 0]);
rl(!empty($undoWet['ok']) && (int) ($undoWet['written'] ?? 0) >= 2 && (int) ($undoWet['sent'] ?? 1) === 0, 'undo tar bort länken');
rl(trim((string) (jeab_inkorg_thread_meta('gap-jarfalla')['contact_id'] ?? '')) === '', 'Järfälla-luckan är olänkad igen');
rl(trim((string) (jeab_inkorg_thread_meta('gap-malmo')['contact_id'] ?? '')) === '', 'Malmö-luckan är olänkad igen');
rl(rl_list_contact($pdo, 'gap-jarfalla') === '' && rl_list_contact($pdo, 'gap-malmo') === '', 'listraderna är olänkade igen');
rl((string) (jeab_get_reminder(jeab_store(), (int) ($malmoRem['id'] ?? 0))['contact_id'] ?? '') === $meContact, 'påminnelsens kontakt ligger kvar');

$stamped = jeab_malmo_hs_stamp_link([
    'customer_email' => 'gammal@exempel.test',
    'customer_name' => 'Gammal',
    'lead_customer' => ['email' => 'gammal@exempel.test', 'name' => 'Gammal', 'note' => 'kvar'],
], $meContact, 'ny.malmo@exempel.test');
rl((string) ($stamped['contact_id'] ?? '') === $meContact && (string) ($stamped['customer_email'] ?? 'x') === '' && (string) ($stamped['lead_customer']['email'] ?? 'x') === '' && (string) ($stamped['lead_customer']['note'] ?? '') === 'kvar', 'Malmö-stämpel med kontakt skriver ingen mejlkopia');
$openStamp = jeab_malmo_hs_stamp_link(['customer_email' => ''], '', 'spam.malmo@exempel.test');
rl((string) ($openStamp['customer_email'] ?? '') === 'spam.malmo@exempel.test' && trim((string) ($openStamp['contact_id'] ?? '')) === '', 'Malmö-stämpel utan kontakt behåller mejlen');

function rl_ingest(string $tid, string $company, string $contactId, string $from): void
{
    jeab_inkorg_thread_write($tid, static function (array $t) use ($company, $contactId): array {
        $t['status'] = 'open';
        $t['brand'] = $company;
        $t['company'] = $company;
        $t['created_at'] = date('c');
        $t['tags'] = [];
        $t['customer_email'] = '';
        $t['customer_name'] = '';
        if ($contactId === '') {
            unset($t['contact_id']);
        } else {
            $t['contact_id'] = $contactId;
        }

        return $t;
    });
    jeab_inkorg_thread_on_message([
        'thread_id' => $tid,
        'id' => $tid . '-m1',
        'from' => $from,
        'from_name' => 'Inlasning',
        'subject' => 'Hej',
        'body' => 'Hej',
        'body_text' => 'Hej',
        'source' => 'graph',
    ]);
}

rl_ingest('jf-copy-stop', 'jarfalla', $jfContact, 'ada.relink@exempel.test');
rl_ingest('mhs-copy-stop', 'malmo', $meContact, 'malmo.relink@exempel.test');
rl_ingest('spam-copy-keep', 'jarfalla', '', 'spam.person@exempel.test');
rl(trim((string) (jeab_inkorg_thread_meta('jf-copy-stop')['customer_email'] ?? '')) === '', 'inläsning på länkad Järfälla-tråd skriver ingen mejlkopia');
rl(trim((string) (jeab_inkorg_thread_meta('mhs-copy-stop')['customer_email'] ?? '')) === '', 'inläsning på länkad Malmö-tråd skriver ingen mejlkopia');
rl(trim((string) (jeab_inkorg_thread_meta('spam-copy-keep')['customer_email'] ?? '')) === 'spam.person@exempel.test', 'tråd utan kontakt behåller mejlen');
rl(count($GLOBALS['_SENT']) === $sentBefore, 'kopplingen skickar inget');

function rl_mismatch(PDO $pdo, string $tid, string $threadCompany, string $reminderCompany, string $reminderThread, string $email, string $name): int
{
    jeab_inkorg_thread_write($tid, static function (array $t) use ($threadCompany): array {
        $t['status'] = 'open';
        $t['brand'] = $threadCompany;
        $t['company'] = $threadCompany;
        $t['created_at'] = date('c');
        $t['tags'] = [];
        unset($t['contact_id']);

        return $t;
    });
    $row = jeab_create_reminder(jeab_store(), $name . ' – Batteri', jeab_today_start(), 'Saknar tradlank', null, [
        'customer' => $name,
        'email' => $email,
        'company' => $reminderCompany,
        'who' => $reminderCompany === 'malmo' ? 'Kim' : 'Elin',
        'thread_id' => $reminderThread !== '' ? $reminderThread : $tid,
        'lookup' => true,
    ]);
    $rid = (int) ($row['id'] ?? 0);
    $pdo->prepare('UPDATE reminders SET thread_id = ?, note = ? WHERE id = ?')->execute([$reminderThread, 'Saknar tradlank', $rid]);
    jeab_inkorg_thread_write($tid, static function (array $t) use ($threadCompany, $rid): array {
        $t['status'] = 'open';
        $t['brand'] = $threadCompany;
        $t['company'] = $threadCompany;
        $t['reminder_id'] = $rid;
        $t['tags'] = [];
        unset($t['contact_id']);

        return $t;
    });

    return $rid;
}

$otherRid = rl_mismatch($pdo, 'jf-annan-trad', 'jarfalla', 'jarfalla', 'jf-annan-kalla', 'annan.trad@exempel.test', 'Bo Annan');
$companyRid = rl_mismatch($pdo, 'mhs-fel-bolag', 'malmo', 'jarfalla', '', 'fel.bolag@exempel.test', 'Kim Bolag');
rl($otherRid > 0 && $companyRid > 0, 'avvikande påminnelser finns');
$skipLog = $tmp . '/relink-skip.log';
ini_set('error_log', $skipLog);
if (is_file($skipLog)) {
    unlink($skipLog);
}
$skipDry = jeab_inkorg_relink_missing([]);
$skipIds = [];
foreach ((array) ($skipDry['threads'] ?? []) as $skipRow) {
    if (is_array($skipRow)) {
        $skipIds[(string) ($skipRow['thread'] ?? '')] = true;
    }
}
rl(!isset($skipIds['jf-annan-trad']) && !isset($skipIds['mhs-fel-bolag']), 'fel tråd och fel bolag listas inte');
$logged = is_file($skipLog) ? (string) file_get_contents($skipLog) : '';
rl(str_contains($logged, 'thread=jf-annan-trad') && str_contains($logged, 'reminder=' . $otherRid) && str_contains($logged, 'orsak=thread_id'), 'annan thread_id loggas');
rl(str_contains($logged, 'thread=mhs-fel-bolag') && str_contains($logged, 'reminder=' . $companyRid) && str_contains($logged, 'orsak=bolag'), 'fel bolag loggas');
rl(!str_contains($logged, 'annan.trad@exempel.test') && !str_contains($logged, 'fel.bolag@exempel.test') && !str_contains($logged, '199001011234'), 'loggen har inga kundvärden');
$skipWet = jeab_inkorg_relink_missing(['dry' => 0]);
rl(trim((string) (jeab_inkorg_thread_meta('jf-annan-trad')['contact_id'] ?? '')) === '', 'Järfälla med annan thread_id länkas inte');
rl(trim((string) (jeab_inkorg_thread_meta('mhs-fel-bolag')['contact_id'] ?? '')) === '', 'Malmö med fel bolag länkas inte');
rl((int) ($skipWet['sent'] ?? 1) === 0 && (int) ($skipDry['sent'] ?? 1) === 0, 'avvisad relink skickar inget');

echo "inkorg_relink_missing_test: klart\n";
