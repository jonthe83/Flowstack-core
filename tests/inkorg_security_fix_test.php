<?php

declare(strict_types=1);

/** Säkerhetsfix: ombokning, admin utan person, lead-autentisering, bilaga, kontakter. */

$tmp = sys_get_temp_dir() . '/jeab-sec-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'test-secret-value',
    'hashes' => [
        ['hash' => 'elin-hash-fixture', 'email' => 'saljare4@jarfalla.example.test'],
        ['hash' => 'sam-hash-fixture', 'email' => 'demo@example.test'],
        ['hash' => 'bare-hash-fixture', 'email' => ''],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);

function sec(bool $ok, string $m): void
{
    if (!$ok) {
        foreach ($GLOBALS['_SEC_LOG'] ?? [] as $line) {
            echo $line;
        }
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    $GLOBALS['_SEC_LOG'][] = "OK  {$m}\n";
}

function sec_throws(callable $fn, string $needle, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        sec(str_contains($e->getMessage(), $needle), $label);
        return;
    }
    fwrite(STDERR, "FAIL {$label} kastade inte\n");
    exit(1);
}

function sec_cookie(?string $email, ?string $hash): void
{
    if ($hash === 'sam-hash-fixture') {
        require_once dirname(__DIR__) . '/tests/sam_l_login.php';
        jeab_test_login_sam_l();
        $_SESSION = [];
        return;
    }
    if ($hash === null) {
        unset($_COOKIE['jeab_auth']);
    } else {
        $_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value($email, $hash);
    }
    $_SESSION = [];
}

function sec_v1(): void
{
    $payload = '1.' . time() . '.ab';
    $_COOKIE['jeab_auth'] = $payload . '.' . hash_hmac('sha256', $payload, jeab_auth_secret());
    $_SESSION = [];
}

@mkdir(jeab_inkorg_dir(), 0775, true);
file_put_contents(jeab_inkorg_dir() . '/settings.json', json_encode(['helpscout_companies' => []]));
@mkdir(jeab_data_dir() . '/secrets', 0775, true);
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test-fixture']));

$GLOBALS['_SEC_SENDS'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = function (array $a) {
    $GLOBALS['_SEC_SENDS'][] = $a;
    return ['ok' => true, 'from' => 'kontakt@jarfalla.example.test', 'via' => 'test'];
};

// --- Bilaga: annat bolag och inloggning utan person ---
$malmo = jeab_inkorg_ingest([
    'message_id' => 'sec-malmo-att@example.test',
    'from' => 'kund.malmo@example.test',
    'to' => 'kontakt@malmo.example.test',
    'subject' => 'Bilaga',
    'body_text' => 'Se bilaga.',
    'source' => 'graph-malmo',
    'company' => 'malmo',
    'attachments' => [[
        'name' => 'offert.pdf',
        'mime' => 'application/pdf',
        'size' => 7,
        'hs_conversation_id' => '42',
        'hs_attachment_id' => '7',
    ]],
]);
$jarfalla = jeab_inkorg_ingest([
    'message_id' => 'sec-jarfalla-att@example.test',
    'from' => 'kund.jarfalla@example.test',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Bilaga',
    'body_text' => 'Se bilaga.',
    'source' => 'graph-jarfalla',
    'company' => 'jarfalla',
    'attachments' => [[
        'name' => 'offert.pdf',
        'mime' => 'application/pdf',
        'size' => 7,
        'hs_conversation_id' => '43',
        'hs_attachment_id' => '8',
    ]],
]);
$hsCalls = 0;
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = function () use (&$hsCalls) {
    $hsCalls++;
    return ['code' => 200, 'body' => json_encode(['data' => base64_encode('PDFDATA'), 'state' => 'ok'])];
};

sec_cookie('saljare4@jarfalla.example.test', 'elin-hash-fixture');
sec(jeab_auth_ok(), 'Elin är inloggad');
sec(!jeab_inkorg_may_fetch_attachment(jeab_inkorg_get((string) $malmo['id'])), 'Elin får inte Malmö-bilagan');
ob_start();
jeab_hs_import_send_attachment((string) $malmo['id'], 0);
$elinOut = (string) ob_get_clean();
sec(http_response_code() === 403 && str_contains($elinOut, 'Inte behörig') && $hsCalls === 0, 'Elin: bilaga för andra bolaget är 403 och hämtas inte');

sec_v1();
sec(jeab_auth_ok() && jeab_inkorg_linked_person() === null, 'v1-kaka är inloggad utan person');
ob_start();
jeab_hs_import_send_attachment((string) $jarfalla['id'], 0);
$v1Out = (string) ob_get_clean();
sec(http_response_code() === 403 && str_contains($v1Out, 'Inte behörig') && $hsCalls === 0, 'inloggning utan person får ingen bilaga');

sec_cookie('demo@example.test', 'sam-hash-fixture');
http_response_code(200);
ob_start();
jeab_hs_import_send_attachment((string) $malmo['id'], 0);
$jonOut = (string) ob_get_clean();
sec(http_response_code() === 200 && $jonOut === 'PDFDATA' && $hsCalls === 1, 'Sam (admin) hämtar Malmö-bilagan, base64 avkodad');

$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = fn() => ['code' => 200, 'body' => json_encode(['data' => base64_encode('NO'), 'state' => 'virus'])];
sec(jeab_hs_import_hs_bytes(['hs_conversation_id' => '1', 'hs_attachment_id' => '2', 'size' => 2]) === null, 'Help Scout-bilaga med state som inte är ok hämtas inte');
$raw = json_encode(['data' => base64_encode('PDFDATA')]);
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = fn() => ['code' => 200, 'body' => $raw];
$decoded = jeab_hs_import_hs_bytes(['hs_conversation_id' => '9', 'hs_attachment_id' => '9', 'size' => 7]);
sec($decoded === 'PDFDATA' && $decoded !== $raw, 'Help Scout-bilaga är avkodad base64, inte rå JSON');

// --- Admin ---
sec_cookie(null, null);
sec_throws(fn() => jeab_inkorg_require_admin(), 'Inte behörig', 'utan kaka: inte admin');
sec_v1();
sec_throws(fn() => jeab_inkorg_require_admin(), 'Inte behörig', 'v1 utan person: inte admin');
sec_cookie('saljare4@jarfalla.example.test', 'elin-hash-fixture');
sec_throws(fn() => jeab_inkorg_require_admin(), 'Inte behörig', 'Elin: inte admin');
sec(jeab_inkorg_person_companies() === ['jarfalla'], 'Elin hör till Järfälla');
sec_cookie('demo@example.test', 'sam-hash-fixture');
jeab_inkorg_require_admin();
sec(jeab_inkorg_is_admin() && jeab_inkorg_person_companies() === ['jarfalla', 'malmo'], 'Sam L är admin för båda bolagen');
sec(jeab_inkorg_admin_allowed(null) === false, 'ingen person är inte admin');
$jw = null;
foreach (jeab_people() as $p) {
    if (strcasecmp((string) $p['id'], 'samw') === 0) {
        $jw = $p;
    }
}
sec(is_array($jw) && !jeab_inkorg_admin_allowed($jw), 'Sam W är inte admin');

sec_cookie('saljare4@jarfalla.example.test', 'elin-hash-fixture');
$beforeSend = count($GLOBALS['_SEC_SENDS']);
sec_throws(fn() => jeab_inkorg_send_new(['company' => 'malmo', 'to' => 'kund@example.test', 'subject' => 'Hej', 'body' => 'Text'], 'Elin'), 'Du kan bara skicka från ditt bolag.', 'Elin kan inte skicka från Malmö');
sec(count($GLOBALS['_SEC_SENDS']) === $beforeSend, 'nekad Malmö-sändning anropar inte Graph');
sec_throws(fn() => jeab_inkorg_action('inkorg.rule_save'), 'Inte behörig', 'Elin kan inte spara regler');
sec_throws(fn() => jeab_inkorg_action('inkorg.token'), 'Inte behörig', 'Elin kan inte läsa hook-token');

$counts = jeab_inkorg_unlinked_login_counts();
sec($counts['logins_total'] === 3 && $counts['logins_without_person'] === 1, 'en av tre koder saknar person');
$diag = jeab_who_diag_path();
$now = date('Y-m-d H:i:s');
file_put_contents($diag, implode("\n", [
    json_encode(['at' => $now, 'fp' => 'aaaaaaaaaaaaaaaa', 'me' => null, 'me_src' => 'none', 'email' => 'hidden-a@example.test']),
    json_encode(['at' => $now, 'fp' => 'aaaaaaaaaaaaaaaa', 'me' => 'Sam L', 'me_src' => 'pick', 'email' => 'hidden-b@example.test']),
    json_encode(['at' => $now, 'key' => 'c1:fixture', 'me' => '', 'me_src' => 'none']),
    json_encode(['at' => $now, 'fp' => 'bbbbbbbbbbbbbbbb', 'me' => 'Elin', 'me_src' => 'email', 'email' => 'saljare4@jarfalla.example.test']),
]) . "\n");
$counts = jeab_inkorg_hook(['action' => 'inbox.unlinked_logins']);
$blob = json_encode($counts);
sec(($counts['recent_sessions_without_person'] ?? -1) === 2 && ($counts['logins_without_person'] ?? -1) === 1, 'hooken räknar sessioner utan kopplad person');
sec(!str_contains((string) $blob, '@') && !str_contains((string) $blob, 'aaaaaaaa') && !str_contains((string) $blob, 'hidden'), 'räkningen innehåller inga personuppgifter');
sec(str_contains((string) file_get_contents(dirname(__DIR__) . '/index.php'), "case 'inkorg.helpscout_save':"), 'inkorg.helpscout_save är kopplad i routern');

// --- Kontakter ---
$saved = jeab_inkorg_contact_save(['name' => 'Fiktiv Kund', 'emails' => 'fiktiv.kund@example.test']);
$cid = (string) $saved['id'];
jeab_inkorg_contact_delete($cid, 'Elin');
sec(!isset(jeab_inkorg_contacts()[$cid]) && isset(jeab_inkorg_contacts(true)[$cid]), 'borttagen kontakt döljs och finns kvar');
$hidden = jeab_inkorg_contacts(true)[$cid];
sec(($hidden['deleted_by'] ?? '') === 'Elin' && ($hidden['log'][0]['action'] ?? '') === 'delete' && ($hidden['deleted_at'] ?? '') !== '', 'döljningen loggar vem och när');
jeab_inkorg_contact_restore($cid, 'Elin');
$back = jeab_inkorg_contacts()[$cid] ?? [];
sec(isset(jeab_inkorg_contacts()[$cid]) && ($back['log'][1]['action'] ?? '') === 'restore' && empty($back['deleted_at']), 'kontakten kan återställas');

// --- Ombokning ---
sec(!empty(jeab_rebook_settings()['email_only']), 'saknad rebook_email_only läses som på');
$statusBefore = jeab_inkorg_hook(['action' => 'inbox.status']);
sec(($statusBefore['rebook_email_only'] ?? null) === true, 'inbox.status visar rebook_email_only när nyckeln saknas');
sec(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg_regler.php'), 'name="rebook_email_only"'), 'Regler har rutan rebook_email_only under Ombokning');
$store = jeab_store();
$tz = new DateTimeZone('Europe/Stockholm');
$day = (new DateTimeImmutable('now', $tz))->setTime(0, 0)->modify('+21 days');
while (!jeab_ordrar_is_workday($day)) {
    $day = $day->modify('+1 day');
}
$booking = jeab_create_booking($store, 'Elin Testkund', ['Elin'], $day->format('Y-m-d') . ' 07:00:00', $day->format('Y-m-d') . ' 11:00:00', 'laddbox', [
    'email' => 'kund.bokning@example.test',
    'phone' => '0701112233',
    'address' => 'Exempelgatan 12, Teststad',
    'company' => 'jarfalla',
], false);
jeab_rebook_settings_save(['rebook_enabled' => 1, 'rebook_only_from' => '', 'rebook_prompt' => '', 'rebook_email_only' => 0]);
sec(jeab_rebook_settings()['email_only'] === false && (jeab_inkorg_hook(['action' => 'inbox.status'])['rebook_email_only'] ?? null) === false, 'avbockad rebook_email_only läses som av');
$before = count($GLOBALS['_SEC_SENDS']);
$addr = jeab_inkorg_ingest([
    'message_id' => 'sec-rebook-addr@example.test',
    'from' => 'angripare@example.test',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Flytta tiden',
    'body_text' => 'Kan ni flytta tiden på Exempelgatan 12?',
    'source' => 'graph-jarfalla',
]);
$held = jeab_rebook_on_message((string) $addr['id']);
$still = jeab_get_booking($store, (int) $booking['id']);
sec(($held['action'] ?? '') === 'hold' && str_contains((string) ($held['why'] ?? ''), 'bara adressen'), 'adress utan avsändarmatch hålls');
sec($still['starts_at'] === $booking['starts_at'] && count($GLOBALS['_SEC_SENDS']) === $before, 'adress-träff flyttar inte och svarar inte');
$meta = jeab_inkorg_thread_meta((string) $addr['thread_id']);
$note = '';
foreach ((array) ($meta['drafts'] ?? []) as $d) {
    if (($d['kind'] ?? '') === 'note') {
        $note .= (string) ($d['body'] ?? '');
    }
}
sec(str_contains($note, 'Ingen flytt') && str_contains($note, 'Exempelgatan 12') && ($meta['assignee'] ?? '') === 'Elin' && ($meta['status'] ?? '') === 'open', 'adress-träff får intern anteckning och tilldelas personalen');

$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['reschedule' => false, 'confidence' => 20, 'proposals' => [], 'reason' => 'inte ombokning']);
$phone = jeab_inkorg_ingest([
    'message_id' => 'sec-rebook-phone@example.test',
    'from' => 'annan@example.test',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Hej',
    'body_text' => 'Mitt nummer är 070-111 22 33. Ingen flytt.',
    'source' => 'graph-jarfalla',
]);
$phoneRes = jeab_rebook_on_message((string) $phone['id']);
sec(($phoneRes['action'] ?? '') === 'none' && !str_contains((string) ($phoneRes['why'] ?? ''), 'bara adressen'), 'telefonmatch går in i ombokningen och hålls inte som adress');
sec(count($GLOBALS['_SEC_SENDS']) === $before, 'telefonmatch utan ombokning skickar inget');

$thread = null;
foreach (jeab_inkorg_threads() as $t) {
    if ($t['id'] === (string) $addr['thread_id']) {
        $thread = $t;
    }
}
$msg = jeab_inkorg_get((string) $addr['id']);
jeab_rebook_reply($thread, $msg, $still, 'Vi har flyttat din tid till måndag.', 'closed');
$sent = $GLOBALS['_SEC_SENDS'][count($GLOBALS['_SEC_SENDS']) - 1];
sec(($sent['to'] ?? '') === 'kund.bokning@example.test' && ($sent['reply'] ?? null) === false && !str_contains(json_encode($sent), 'angripare@'), 'automatiskt svar går till bokningens e-post, inte till From');

jeab_rebook_settings_save(['rebook_enabled' => 1, 'rebook_only_from' => '', 'rebook_prompt' => '', 'rebook_email_only' => 1]);
sec(!empty(jeab_rebook_settings()['email_only']), 'sparad rebook_email_only läses tillbaka som på');
$statusOn = jeab_inkorg_hook(['action' => 'inbox.status']);
sec(($statusOn['rebook_email_only'] ?? null) === true && !str_contains((string) json_encode($statusOn), '@'), 'inbox.status läser tillbaka rebook_email_only utan personuppgifter');
$GLOBALS['_JEAB_REBOOK_LLM'] = function () {
    fwrite(STDERR, "FAIL llm anropades för telefon utan e-post\n");
    exit(1);
};
$sendsBeforeHold = count($GLOBALS['_SEC_SENDS']);
$phoneHeldMsg = jeab_inkorg_ingest([
    'message_id' => 'sec-rebook-phone-hold@example.test',
    'from' => 'annan.telefon@example.test',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Hej igen',
    'body_text' => 'Mitt nummer är 070-111 22 33 och adressen är Exempelgatan 12. Flytta gärna.',
    'source' => 'graph-jarfalla',
]);
$phoneHeld = jeab_rebook_on_message((string) $phoneHeldMsg['id']);
$stillHeld = jeab_get_booking($store, (int) $booking['id']);
sec(($phoneHeld['action'] ?? '') === 'hold' && str_contains((string) ($phoneHeld['why'] ?? ''), 'bara telefonen'), 'telefon utan samma e-post hålls när rebook_email_only är på');
sec($stillHeld['starts_at'] === $booking['starts_at'] && count($GLOBALS['_SEC_SENDS']) === $sendsBeforeHold, 'telefon-träff med brytaren på flyttar inte och svarar inte');
$phoneMeta = jeab_inkorg_thread_meta((string) $phoneHeldMsg['thread_id']);
$phoneNote = '';
foreach ((array) ($phoneMeta['drafts'] ?? []) as $d) {
    if (($d['kind'] ?? '') === 'note') {
        $phoneNote .= (string) ($d['body'] ?? '');
    }
}
sec(str_contains($phoneNote, 'Ingen flytt') && ($phoneMeta['assignee'] ?? '') === 'Elin' && ($phoneMeta['status'] ?? '') === 'open', 'telefon-träff får intern anteckning och tilldelas personalen');
$addrOn = jeab_inkorg_ingest([
    'message_id' => 'sec-rebook-addr-on@example.test',
    'from' => 'annan.adress@example.test',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Flytta tiden igen',
    'body_text' => 'Kan ni flytta tiden på Exempelgatan 12?',
    'source' => 'graph-jarfalla',
]);
$addrOnRes = jeab_rebook_on_message((string) $addrOn['id']);
sec(($addrOnRes['action'] ?? '') === 'hold' && str_contains((string) ($addrOnRes['why'] ?? ''), 'bara adressen'), 'adress utan avsändarmatch hålls även när rebook_email_only är på');
unset($GLOBALS['_JEAB_REBOOK_LLM']);

// --- Leadplattform ---
$pass = ['headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass header.from=leadkit.com']];
$fail = ['headers' => ['Authentication-Results' => "forged.example; dmarc=pass header.from=leadkit.com\nabc.protection.outlook.com; dmarc=fail header.from=leadkit.com"]];
sec(jeab_auto_platform_auth_why($pass, 'leadkit.com') === '', 'dmarc=pass från Outlook godkänns');
sec(jeab_auto_platform_auth_why($fail, 'leadkit.com') !== '', 'dmarc=fail från Outlook vinner över ett påstått pass');
$spf = ['headers' => ['Authentication-Results' => 'abc.protection.outlook.com; spf=pass smtp.mailfrom=leadkit.com; dkim=pass header.d=leadkit.com']];
$mis = ['headers' => ['Authentication-Results' => 'abc.protection.outlook.com; spf=pass smtp.mailfrom=leadkit.com; dkim=pass header.d=attacker.example']];
sec(jeab_auto_platform_auth_why($spf, 'leadkit.com') === '' && jeab_auto_platform_auth_why($mis, 'leadkit.com') !== '', 'SPF och DKIM måste båda peka på plattformen');
sec(jeab_auto_platform_auth_why(['headers' => []], 'leadkit.com') !== '', 'saknad Authentication-Results hålls');

$leadBody = "Namn: Mia Holm\nE-post: mia.holm@gmail.com\nTelefon: 070-555 66 77\nAdress: Exempelgatan 12, 17760 Järfälla\n";
$leadIn = function (string $id, array $headers) use ($leadBody) {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@lead.test',
        'from' => 'leads@leadkit.com',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Nytt lead',
        'body_text' => $leadBody,
        'source' => 'graph-jarfalla',
        'headers' => $headers,
        'preset_labels' => ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95, 'intent' => 'kop', 'offer' => 'batteri'],
        'thread_key' => 'lead-' . $id,
    ]);
};
$threadOf = function (string $tid): array {
    foreach (jeab_inkorg_threads() as $t) {
        if ($t['id'] === $tid) {
            return $t;
        }
    }
    return [];
};
$forged = $leadIn('forged', ['Authentication-Results' => "forged.example; dmarc=pass header.from=leadkit.com\nabc.protection.outlook.com; dmarc=fail header.from=leadkit.com"]);
$q = jeab_auto_quote($threadOf((string) $forged['thread_id']), jeab_inkorg_get((string) $forged['id']), jeab_auto_settings(), [], ['dry' => true, 'automatic' => true]);
sec(($q['action'] ?? '') === 'hold' && str_contains((string) ($q['why'] ?? ''), 'autentisering'), 'förfalskad lead hålls och skickas inte');

$sends = [];
for ($i = 0; $i < 10; $i++) {
    $sends[] = ['at' => time(), 'rcpt' => hash('sha256', 'kund' . $i . '@example.test')];
}
sec(str_contains(jeab_auto_send_cap_why_in($sends, 'ny.kund@example.test', time()), 'per timme'), 'elfte automatiska offerten samma timme hålls');
$sends[] = ['at' => time(), 'rcpt' => jeab_auto_send_cap_key('mia.holm@gmail.com')];
sec(str_contains(jeab_auto_send_cap_why_in($sends, 'Mia.Holm@gmail.com', time()), '30 dagar'), 'samma adress får högst en automatisk offert på 30 dagar');
jeab_auto_send_cap_record('mia.holm@gmail.com');
$again = $leadIn('again', ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass header.from=leadkit.com']);
$q2 = jeab_auto_quote($threadOf((string) $again['thread_id']), jeab_inkorg_get((string) $again['id']), jeab_auto_settings(), [], ['dry' => true, 'automatic' => true]);
sec(($q2['action'] ?? '') === 'hold' && str_contains((string) ($q2['why'] ?? ''), '30 dagar'), 'tak per adress håller offerten i offertflödet');
sec(str_contains(implode("\n", jeab_auto_rule_lines()), 'per timme') && str_contains((string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg_regler.php'), 'auto_send_per_hour'), 'taken syns på Regler');

// Graph behåller båda Authentication-Results-raderna.
$GLOBALS['JEAB_GRAPH_SEND'] = function () {
    fwrite(STDERR, "FAIL graph skickade\n");
    exit(1);
};
$ing = jeab_graph_ingest_message('jarfalla', [
    'id' => 'G-SEC',
    'internetMessageId' => '<sec-graph-auth@example.test>',
    'conversationId' => 'CONV-SEC-AUTH',
    'from' => ['emailAddress' => ['address' => 'leads@leadkit.com', 'name' => 'Lead']],
    'toRecipients' => [['emailAddress' => ['address' => 'kontakt@jarfalla.example.test']]],
    'subject' => 'Lead',
    'receivedDateTime' => date('c'),
    'body' => ['content' => $leadBody],
    'internetMessageHeaders' => [
        ['name' => 'Authentication-Results', 'value' => 'forged.example; dmarc=pass header.from=leadkit.com'],
        ['name' => 'Authentication-Results', 'value' => 'abc.protection.outlook.com; dmarc=fail header.from=leadkit.com'],
    ],
]);
$stored = jeab_inkorg_get((string) ($ing['id'] ?? ''));
$header = '';
foreach ((array) ($stored['headers'] ?? []) as $k => $v) {
    if (strcasecmp((string) $k, 'Authentication-Results') === 0) {
        $header .= (string) $v;
    }
}
sec(str_contains($header, 'dmarc=pass') && str_contains($header, 'dmarc=fail') && jeab_auto_platform_auth_why($stored, 'leadkit.com') !== '', 'Graph sparar både pass och fail, och fail håller');

foreach ($GLOBALS['_SEC_LOG'] ?? [] as $line) {
    echo $line;
}
echo "ALL OK\n";
