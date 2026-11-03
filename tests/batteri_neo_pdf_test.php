<?php

declare(strict_types=1);

/** Batteriofferten är SigenStor NEO-PDF:en i repot, i alla utskick. Påhittade adresser. */

$tmp = sys_get_temp_dir() . '/jeab-batteri-neo-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function bn(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$srcJarfalla = '/cursor/stores/bot/offert-pdf/alt1-v2.pdf';
$srcMalmo = '/cursor/stores/bot/offert-pdf/alt1-v2-malmo.pdf';
$jarfallaMd5 = 'a80a4d88d44b2360cb29bc4ae4cf6ccf';
$malmoMd5 = '14cfe8b78f636340ef06a22f7353a885';
$repoJarfalla = jeab_auto_bundled_pdf('batteri_jarfalla');
$repoMalmo = jeab_auto_bundled_pdf('batteri_malmo');
bn(is_file($repoJarfalla) && is_file($repoMalmo), 'PDF:erna ligger i repot');
if (is_file($srcJarfalla) && is_file($srcMalmo)) {
    bn(md5_file($srcJarfalla) === $jarfallaMd5 && md5_file($repoJarfalla) === $jarfallaMd5, 'Järfälla-PDF:en är alt1-v2.pdf ' . $jarfallaMd5);
    bn(md5_file($srcMalmo) === $malmoMd5 && md5_file($repoMalmo) === $malmoMd5, 'Malmö-PDF:en är alt1-v2-malmo.pdf ' . $malmoMd5);
} else {
    echo "SKIP källfilerna saknas, jämför repo-PDF:erna\n";
    bn(md5_file($repoJarfalla) === $jarfallaMd5, 'Järfälla-PDF:en i repot är alt1-v2.pdf ' . $jarfallaMd5);
    bn(md5_file($repoMalmo) === $malmoMd5, 'Malmö-PDF:en i repot är alt1-v2-malmo.pdf ' . $malmoMd5);
}
bn(md5_file($repoJarfalla) !== md5_file($repoMalmo), 'bolagen har var sin PDF');

$tracked = array_filter(explode("\n", (string) shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' ls-files "*.pdf"')));
bn(in_array('assets/offert/Batteri-offert.pdf', $tracked, true) && in_array('assets/offert/Malmo-Batteri-offert.pdf', $tracked, true), 'båda NEO-PDF:erna är spårade');
foreach ($tracked as $path) {
    bn(!str_contains($path, 'Batteri-offert-Malmo-Energi') && $path !== 'Malmo-Batteri-offert.pdf' && $path !== 'Batteri-offert.pdf', 'ingen gammal batteri-PDF i rot eller under annat namn: ' . $path);
}
$tree = (string) shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' grep -l -e "Batteri-offert-Malmo-Energi" -e "46 500" -- src/inkorg_auto.php src/inkorg_auto_seed.php src/graph_person.php || true');
bn(trim($tree) === '', 'mejl och utskick nämner inte den gamla Malmöfilen eller 46 500');

$want = [
    '7,5 kWh 39 500 kr',
    '15 kWh 55 500 kr',
    '19,5 kWh 65 500 kr',
    '22,5 kWh 68 500 kr',
    '30 kWh 81 500 kr',
    '37,5 kWh 94 500 kr',
];
bn(jeab_offer_malmo_price_rows() === $want && jeab_offer_batteri_neo_price_rows() === $want, 'prislistan är NEO-raderna');
$para = jeab_offer_batteri_neo_price_paragraph();
foreach ($want as $row) {
    bn(str_contains($para, $row), 'prisstycket har ' . $row);
}
bn(!preg_match('/46\s*500|70\s*000|106\s*000|priset det dubbla|9 kWh|Fox/u', $para), 'prisstycket har inga gamla belopp');

$oldJarfalla = "%PDF-1.4 GAMMAL-JARFALLA\n";
$oldMalmo = "%PDF-1.4 GAMMAL-MALMO\n";
bn((jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode($oldJarfalla)])['ok'] ?? false) === true, 'en gammal Järfälla-PDF kan ligga kvar i data');
bn((jeab_auto_pdf_save(['key' => 'batteri_malmo', 'data' => base64_encode($oldMalmo)])['ok'] ?? false) === true, 'en gammal Malmö-PDF kan ligga kvar i data');
bn(jeab_auto_quote_pdf_file('batteri_jarfalla') === $repoJarfalla && jeab_auto_quote_pdf_file('batteri_malmo') === $repoMalmo, 'den bundlade sökvägen pekar kvar på repo-PDF:en');
bn(md5_file(jeab_auto_pdf_path('batteri_jarfalla')) !== md5_file($repoJarfalla), 'data-filen är den gamla');

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = function (array $a) {
    $GLOBALS['_SENT'][] = $a;
    $person = trim((string) ($a['opts']['person_email'] ?? ''));
    $from = $person !== '' ? $person : 'saljare3@jarfalla.example.test';
    return ['ok' => true, 'from' => $from, 'graph_id' => 'g-bn', 'message_id' => 'sent-bn@local'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn(): array => ['code' => 200, 'body' => ['value' => []]];
$GLOBALS['_JEAB_REBOOK_LLM'] = static fn(): string => json_encode(['answer' => '']);
@mkdir(jeab_data_dir() . '/secrets', 0775, true);
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test']));
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode('%PDF-1.4 ladd')]);
jeab_auto_pdf_save(['key' => 'laddbox_malmo', 'data' => base64_encode('%PDF-1.4 ladd-malmo')]);
require __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();

function bn_pdf(array $sent): string
{
    foreach ((array) ($sent['attachments'] ?? []) as $att) {
        if (!is_array($att)) {
            continue;
        }
        $name = (string) ($att['filename'] ?? '');
        if (str_ends_with(strtolower($name), '.pdf')) {
            return (string) ($att['content'] ?? '');
        }
    }
    return '';
}

$ingest = static function (string $id, string $company, string $email, string $name): array {
    $box = jeab_graph_mailboxes()[$company];
    return jeab_inkorg_ingest([
        'message_id' => $id . '@neo.test',
        'from' => $email,
        'from_name' => $name,
        'to' => $box,
        'subject' => 'Batteri',
        'body_text' => "Namn: {$name}\nE-post: {$email}\nTelefon: 070-100 " . substr(md5($id), 0, 2) . " " . substr(md5($id), 2, 2) . "\nAdress: Exempelgatan " . (hexdec(substr(md5($id), 0, 2)) % 80 + 1) . "\nHej, jag vill ha ett batteri.\n",
        'source' => 'graph-' . $company,
        'graph_mailbox' => $box,
        'graph_id' => 'graph-' . $id,
        'thread_key' => 'neo-' . $id,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
        ],
    ]);
};

$jIn = $ingest('jarfalla-live', 'jarfalla', 'mia.neo@gmail.com', 'Mia Neo');
$jSend = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $jIn['thread_id'], 'dry' => 0]);
$jSent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
$jBytes = bn_pdf($jSent);
bn(($jSend['ok'] ?? false) === true && ($jSent['opts']['person_email'] ?? '') === 'saljare3@jarfalla.example.test', 'send_quote Järfälla går från Roberto');
bn(str_contains($jBytes, 'GAMMAL-JARFALLA') && md5($jBytes) !== $jarfallaMd5, 'send_quote Järfälla bifogar Notiser-PDF:en');
bn(str_contains((string) ($jSent['body'] ?? ''), '39 500') && str_contains((string) ($jSent['body'] ?? ''), '94 500') && !str_contains((string) ($jSent['body'] ?? ''), '46 500') && !preg_match('/priset det dubbla/iu', (string) ($jSent['body'] ?? '')), 'Järfälla-mejlet har den sparade Notis-texten');

$mIn = $ingest('malmo-live', 'malmo', 'kim.neo@gmail.com', 'Kim Neo');
$mSend = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $mIn['thread_id'], 'dry' => 0]);
$mSent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
$mBytes = bn_pdf($mSent);
bn(($mSend['ok'] ?? false) === true && ($mSend['from'] ?? '') === 'saljare.syd@malmo.example.test', 'send_quote Malmö går från Kim');
bn(str_contains($mBytes, 'GAMMAL-MALMO') && md5($mBytes) !== $malmoMd5, 'send_quote Malmö bifogar Notiser-PDF:en');
bn(str_contains((string) ($mSent['body'] ?? ''), '65 500') && !str_contains((string) ($mSent['body'] ?? ''), '46 500'), 'Malmö-mejlet har den sparade Notis-texten');

$dry = jeab_offer_send_malmo([
    'to' => 'dry.neo@example.com',
    'product' => 'batteri',
    'customer_name' => 'Nora Neo',
    'dry' => 1,
    'answer' => 'Vi tar storleken i offerten.',
    'gron_teknik' => false,
]);
bn(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1 && ($dry['sent'] ?? 1) === 0 && ($dry['prices'] ?? null) === $want && ($dry['answer_included'] ?? false) === true && !array_key_exists('gron_teknik', $dry), 'Malmö dry har NEO-raderna, answer och inget gron_teknik');
bn(str_contains((string) ($dry['preview'] ?? ''), '39 500') && !str_contains((string) ($dry['preview'] ?? '') . (string) ($dry['body_preview'] ?? ''), '46 500') && !preg_match('/priset det dubbla/iu', (string) ($dry['body_preview'] ?? '')), 'dry-texten är NEO utan dubbelt pris');

$before = count($GLOBALS['_SENT']);
$live = jeab_offer_send_malmo([
    'to' => 'live.neo@example.com',
    'product' => 'batteri',
    'customer_name' => 'Nora Neo',
    'dry' => 0,
    'gron_teknik' => 0,
]);
$liveSent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
$liveBytes = bn_pdf($liveSent);
bn(count($GLOBALS['_SENT']) === $before + 1 && ($live['ok'] ?? false) === true && ($live['prices'] ?? null) === $want && !array_key_exists('gron_teknik', $live) && md5($liveBytes) === $malmoMd5, 'offer.send_malmo bifogar Malmö-PDF:en och NEO-raderna');

$autoIn = $ingest('jarfalla-auto', 'jarfalla', 'auto.neo@gmail.com', 'Auto Neo');
$auto = jeab_auto_on_message((string) $autoIn['id']);
$autoSent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
bn(($auto['action'] ?? '') === 'quoted' && ($auto['from'] ?? '') === 'saljare3@jarfalla.example.test' && str_contains(bn_pdf($autoSent), 'GAMMAL-JARFALLA'), 'automatisk inkorgsoffert bifogar Notiser-PDF:en');

$gp = (string) file_get_contents(dirname(__DIR__) . '/src/graph_person.php');
bn(str_contains($gp, 'jeab_auto_quote_pdf_file($key)'), 'lead-utkastet använder samma PDF-fil');

echo "batteri neo pdf: ok\n";
