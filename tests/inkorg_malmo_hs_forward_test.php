<?php

declare(strict_types=1);

/** Malmö-synk framåt: SQL-markör, stängda och väntande, köpintresse utan etikett, ingen no_auto på nya Kim-kopior. */

$tmp = sys_get_temp_dir() . '/jeab-malmo-fwd-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function mf(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function mf_customer(string $id, string $email, string $body, string $at): array
{
    return [
        'id' => $id,
        'type' => 'customer',
        'createdAt' => $at,
        'body' => $body,
        'createdBy' => ['first' => 'Test', 'last' => 'Kund', 'email' => $email],
        'customer' => ['id' => 'c' . $id, 'email' => $email, 'first' => 'Test', 'last' => 'Kund'],
    ];
}

$store = jeab_store();
mf($store instanceof JeabPdoStore, 'sqlite är uppe');
$cursorTables = $store->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'malmo_hs_cursor'")->fetchAll();
mf($cursorTables !== [], 'db.php skapar malmo_hs_cursor');

jeab_malmo_hs_settings_save(['malmo_hs_since' => '', 'malmo_hs_sync' => '1', 'malmo_hs_close' => '0']);
$first = jeab_malmo_hs_search('');
mf(str_contains($first, 'status:"active"') && str_contains($first, 'status:"pending"') && str_contains($first, 'status:"closed"') && str_contains($first, 'createdAt:[' . JEAB_MALMO_HS_FIXED_START . 'T00:00:00Z TO *]'), 'utan markör finns startdatum och stängda från det datumet');
mf(str_contains($first, 'status:"active" OR status:"pending"'), 'öppna och väntande tas med utan datumkrav');
$marked = jeab_malmo_hs_search('2026-10-20T08:00:00Z');
mf(str_contains($marked, 'status:"closed"') && str_contains($marked, 'status:"pending"') && str_contains($marked, 'modifiedAt:[2026-10-20T08:00:00Z TO *]'), 'markören tar stängda och väntande sedan förra körningen');

jeab_malmo_hs_state_save(['watermark' => '2026-10-20T08:00:00Z', 'page' => 2, 'offset' => 4, 'query' => '2026-10-20T08:00:00Z']);
$saved = $store->pdo()->query('SELECT watermark, page, cursor_offset, query FROM malmo_hs_cursor WHERE id = 1')->fetch();
mf(is_array($saved) && (string) $saved['watermark'] === '2026-10-20T08:00:00Z' && (int) $saved['page'] === 2 && (int) $saved['cursor_offset'] === 4, 'markören ligger i SQL');
mf(!is_file(jeab_malmo_hs_state_path()), 'markören skrevs inte som JSON');
$loaded = jeab_malmo_hs_state();
mf(($loaded['watermark'] ?? '') === '2026-10-20T08:00:00Z' && (int) ($loaded['offset'] ?? 0) === 4, 'läsning kommer från SQL');

file_put_contents(jeab_helpscout_path(), json_encode(['mailbox_id_malmo' => 4242]));
$GLOBALS['_JEAB_INKORG_LLM'] = static function (): string {
    return json_encode([
        'type' => 'Information',
        'confidence' => 92,
        'intent' => 'information',
        'offer' => '',
        'product' => '',
        'reason' => 'info',
    ], JSON_UNESCAPED_UNICODE);
};
$openMail = 'batteri.ny@brevlada.se';
$waitMail = 'laddbox.ny@brevlada.se';
$closedMail = 'batteri.stangd@brevlada.se';
$solMail = 'sol.ny@brevlada.se';
$plainMail = 'fraga.ny@brevlada.se';
$GLOBALS['_JEAB_MALMO_HS_FIXTURES'] = [
    [
        'id' => 501,
        'subject' => 'Batteri',
        'status' => 'active',
        'createdAt' => '2026-10-20T09:00:00Z',
        'modifiedAt' => '2026-10-20T09:00:00Z',
        'tags' => [],
        'primaryCustomer' => ['id' => '501', 'email' => $openMail, 'first' => 'Test', 'last' => 'Kund'],
        '_threads' => [mf_customer('501', $openMail, 'Vi vill köpa och installera ett batteri. Vad kostar det?', '2026-10-20T09:00:00Z')],
    ],
    [
        'id' => 502,
        'subject' => 'Laddbox',
        'status' => 'pending',
        'createdAt' => '2026-10-20T10:00:00Z',
        'modifiedAt' => '2026-10-20T10:00:00Z',
        'tags' => [],
        'primaryCustomer' => ['id' => '502', 'email' => $waitMail, 'first' => 'Test', 'last' => 'Kund'],
        '_threads' => [mf_customer('502', $waitMail, 'Vad kostar en laddbox hemma?', '2026-10-20T10:00:00Z')],
    ],
    [
        'id' => 503,
        'subject' => 'Stängt batteri',
        'status' => 'closed',
        'createdAt' => '2026-10-20T11:00:00Z',
        'modifiedAt' => '2026-10-20T11:00:00Z',
        'tags' => [],
        'primaryCustomer' => ['id' => '503', 'email' => $closedMail, 'first' => 'Test', 'last' => 'Kund'],
        '_threads' => [mf_customer('503', $closedMail, 'Vi vill köpa ett batteri.', '2026-10-20T11:00:00Z')],
    ],
    [
        'id' => 504,
        'subject' => 'Sol',
        'status' => 'active',
        'createdAt' => '2026-10-20T12:00:00Z',
        'modifiedAt' => '2026-10-20T12:00:00Z',
        'tags' => [],
        'primaryCustomer' => ['id' => '504', 'email' => $solMail, 'first' => 'Test', 'last' => 'Kund'],
        '_threads' => [mf_customer('504', $solMail, 'Offert på solceller tack.', '2026-10-20T12:00:00Z')],
    ],
    [
        'id' => 505,
        'subject' => 'Fråga',
        'status' => 'active',
        'createdAt' => '2026-10-20T13:00:00Z',
        'modifiedAt' => '2026-10-20T13:00:00Z',
        'tags' => [],
        'primaryCustomer' => ['id' => '505', 'email' => $plainMail, 'first' => 'Test', 'last' => 'Kund'],
        '_threads' => [mf_customer('505', $plainMail, 'Bara en fråga om fakturan.', '2026-10-20T13:00:00Z')],
    ],
    [
        'id' => 506,
        'subject' => 'Nyhet',
        'status' => 'active',
        'createdAt' => '2026-10-20T14:00:00Z',
        'modifiedAt' => '2026-10-20T14:00:00Z',
        'tags' => [['tag' => 'batteri']],
        'primaryCustomer' => ['id' => '506', 'email' => 'news@huawei.com', 'first' => 'Huawei', 'last' => 'News'],
        'headers' => ['List-Unsubscribe' => '<mailto:leave@huawei.com>'],
        '_threads' => [mf_customer('506', 'news@huawei.com', 'Kan vi få en offert på batteri?', '2026-10-20T14:00:00Z')],
    ],
    [
        'id' => 507,
        'subject' => 'Fel',
        'status' => 'active',
        'createdAt' => '2026-10-20T14:10:00Z',
        'modifiedAt' => '2026-10-20T14:10:00Z',
        'tags' => [],
        'primaryCustomer' => ['id' => '507', 'email' => 'support.kund@brevlada.se', 'first' => 'Support', 'last' => 'Kund'],
        '_threads' => [mf_customer('507', 'support.kund@brevlada.se', 'Det är fel på laddboxen. Kan jag få pris på en ny?', '2026-10-20T14:10:00Z')],
    ],
    [
        'id' => 508,
        'subject' => 'Test',
        'status' => 'active',
        'createdAt' => '2026-10-20T14:20:00Z',
        'modifiedAt' => '2026-10-20T14:20:00Z',
        'tags' => [],
        'primaryCustomer' => ['id' => '508', 'email' => 'testkund@example.com', 'first' => 'Testkund', 'last' => 'Exempel'],
        '_threads' => [mf_customer('508', 'testkund@example.com', 'Jag vill köpa och installera ett batteri.', '2026-10-20T14:20:00Z')],
    ],
    [
        'id' => 509,
        'subject' => 'Formulär laddbox',
        'status' => 'active',
        'createdAt' => '2026-10-20T14:30:00Z',
        'modifiedAt' => '2026-10-20T14:30:00Z',
        'tags' => [],
        'primaryCustomer' => ['id' => '509', 'email' => 'form.ladd@brevlada.se', 'first' => 'Form', 'last' => 'Ladd'],
        '_threads' => [mf_customer('509', 'form.ladd@brevlada.se', "Produkt: Laddbox\nVi har solceller och batteri hemma. Kan vi få pris?", '2026-10-20T14:30:00Z')],
    ],
    [
        'id' => 510,
        'subject' => 'Formulär batteri',
        'status' => 'active',
        'createdAt' => '2026-10-20T14:40:00Z',
        'modifiedAt' => '2026-10-20T14:40:00Z',
        'tags' => [],
        'primaryCustomer' => ['id' => '510', 'email' => 'form.batt@brevlada.se', 'first' => 'Form', 'last' => 'Batt'],
        '_threads' => [mf_customer('510', 'form.batt@brevlada.se', "Produkt: Batteri\nVi har solceller på taket och vill ha offert.", '2026-10-20T14:40:00Z')],
    ],
];
$dry = jeab_malmo_hs_sync(['dry' => 1, 'limit' => 10]);
$by = [];
foreach ((array) ($dry['conversations'] ?? []) as $row) {
    $by[(string) ($row['id'] ?? '')] = $row;
}
mf(($by['501']['decision'] ?? '') === 'kim' && ($by['501']['target'] ?? '') === 'Kim aktiv' && !empty($by['501']['would_send_offer']), 'batteri utan etikett blir Kim och offert');
mf(($by['502']['decision'] ?? '') === 'kim' && ($by['502']['template'] ?? '') === 'laddbox_malmo' && !empty($by['502']['would_send_offer']), 'väntande laddbox utan etikett blir Kim');
mf(($by['503']['decision'] ?? '') === 'kim' && ($by['503']['skipped'] ?? '') === 'hs_closed' && empty($by['503']['would_send_offer']), 'stängt batteri syns men skickas inte');
mf(($by['504']['decision'] ?? '') === 'historik' && empty($by['504']['would_send_offer']), 'sol utan etikett blir inte Kim-offert');
mf(($by['505']['decision'] ?? '') === 'historik' && empty($by['505']['would_send_offer']), 'fråga utan köp förblir historik');
mf(($by['506']['decision'] ?? '') === 'historik' && empty($by['506']['would_send_offer']) && ($by['506']['reason'] ?? '') === 'undantagen', 'leverantör blir inte Kim-offert');
mf(($by['507']['decision'] ?? '') === 'historik' && empty($by['507']['would_send_offer']), 'support blir inte Kim-offert');
mf(($by['508']['decision'] ?? '') === 'historik' && empty($by['508']['would_send_offer']), 'formulärtest blir inte Kim-offert');
mf(($by['509']['decision'] ?? '') === 'kim' && ($by['509']['template'] ?? '') === 'laddbox_malmo' && !empty($by['509']['would_send_offer']), 'formulärets laddbox vinner över sol och batteri i texten');
mf(($by['510']['decision'] ?? '') === 'kim' && ($by['510']['template'] ?? '') === 'batteri_malmo' && !empty($by['510']['would_send_offer']), 'formulärets batteri vinner över solceller i texten');

$class = jeab_hs_import_classify([
    'subject' => 'Batteri',
    'preview' => '',
    'tags' => [],
    'jeab_text' => 'Vi vill köpa och installera ett batteri.',
]);
mf(($class['type'] ?? '') === 'Information' && in_array('batteri', (array) ($class['tags'] ?? []), true), 'import utan etikett fyller batteri utan Ny lead');

$hsi = 'hsi-' . substr(hash('sha256', 'fwd-old'), 0, 16);
$conv = [
    'id' => $hsi,
    'company' => 'malmo',
    'status' => 'closed',
    'import_source' => 'helpscout_import',
    'no_auto' => true,
    'hs_ids' => ['conversation' => '88099', 'number' => '88099'],
    'messages' => [[
        'id' => substr(hash('sha256', 'fwd-m'), 0, 16),
        'from' => 'arkiv@example.com',
        'subject' => 'Gammal',
        'body' => 'Vi vill köpa ett batteri.',
        'received_at' => '2026-10-07T09:00:00+02:00',
        'source' => 'helpscout_import',
        'no_auto' => true,
        'labels' => ['type' => 'Information'],
    ]],
    'meta' => ['import_source' => 'helpscout_import', 'no_auto' => true, 'status' => 'closed'],
];
mf(jeab_hs_import_save_conv($conv) && jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'historisk hsi sparas');

$mail = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$mail): array {
    $mail[] = mb_strtolower((string) ($opts['to'] ?? ''));
    return ['ok' => true, 'path' => 'inkorg', 'id' => 'stub-fwd'];
};
$live = jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'limit' => 10]);
mf(!empty($live['ok']), 'synken kör');
$laddQuote = jeab_offer_send_malmo(['dry' => 0, 'product' => 'laddbox', 'to' => $waitMail, 'customer_name' => 'Test', 'hs_conversation_id' => '502']);
$laddReached = in_array($waitMail, $mail, true) || str_contains((string) ($laddQuote['error'] ?? ''), 'offert-PDF');
mf(in_array($openMail, $mail, true) && $laddReached, 'nya öppna och väntande når offert');
mf(!in_array($closedMail, $mail, true) && !in_array($solMail, $mail, true) && !in_array('arkiv@example.com', $mail, true) && !in_array('news@huawei.com', $mail, true) && !in_array('support.kund@brevlada.se', $mail, true) && !in_array('testkund@example.com', $mail, true), 'stängt, sol, hsi, leverantör, support och test skickas inte');
$again = count($mail);
jeab_malmo_hs_sync(['dry' => 0, 'backfill' => 1, 'limit' => 10]);
mf(count($mail) === $again, 'andra körningen skickar inte igen');

$kimTid = '';
foreach (jeab_inkorg_threads_meta() as $tid => $t) {
    if (is_array($t) && (string) ($t['hs_conversation_id'] ?? '') === '501') {
        $kimTid = (string) $tid;
    }
}
$waitTid = '';
foreach (jeab_inkorg_threads_meta() as $tid => $t) {
    if (is_array($t) && (string) ($t['hs_conversation_id'] ?? '') === '502') {
        $waitTid = (string) $tid;
    }
}
mf($kimTid !== '' && $waitTid !== '' && empty(jeab_inkorg_thread_meta($kimTid)['no_auto']) && empty(jeab_inkorg_thread_meta($waitTid)['no_auto']), 'nya Kim-trådar saknar no_auto');
$kimMsg = null;
foreach (jeab_inkorg_messages() as $m) {
    if (is_array($m) && (string) ($m['thread_id'] ?? '') === $kimTid) {
        $kimMsg = $m;
        break;
    }
}
mf(is_array($kimMsg) && (string) ($kimMsg['source'] ?? '') === 'malmo_hs' && empty($kimMsg['no_auto']) && (string) ($kimMsg['labels']['type'] ?? '') === 'Ny lead', 'ny Kim-kopia är Ny lead utan no_auto');
$old = jeab_hs_import_read_conv($hsi);
mf(is_array($old) && !empty($old['no_auto']) && !empty($old['messages'][0]['no_auto']), 'historisk hsi behåller no_auto');
$liveJson = json_encode($live, JSON_UNESCAPED_UNICODE) ?: '';
mf(!str_contains($liveJson, 'batteri.ny@') && !str_contains($liveJson, 'laddbox.ny@') && !str_contains($liveJson, 'arkiv@') && !str_contains($liveJson, 'huawei') && !str_contains($liveJson, 'testkund') && !str_contains($liveJson, 'form.ladd') && !str_contains($liveJson, 'form.batt') && !str_contains($liveJson, 'support.kund'), 'synksvaret har ingen kundadress');

echo "OK  malmo forward\n";
