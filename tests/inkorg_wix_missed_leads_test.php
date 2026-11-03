<?php

declare(strict_types=1);

/**
 * Tre Wix-formulär som blev kvar i granskning när xAI var tyst.
 * Påhittade kunder. Inget skickas.
 */

$tmp = sys_get_temp_dir() . '/jeab-wix-missed-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function wm(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;

    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn (): array => ['code' => 200, 'body' => ['value' => []]];
$GLOBALS['_JEAB_KONTAKT_POSTORT_LOOKUP'] = static fn (): array => [];
$GLOBALS['_JEAB_INKORG_LEARN_RETRY_US'] = 0;

$solarUrl = 'https://www.jarfalla.example.test/solceller?utm_source=wix&utm_campaign=' . str_repeat('k', 180);
$charger = <<<TXT
A site visitor just submitted your form: Ladbox lead on Demo Energi Järfälla‎<https://www.jarfalla.example.test/laddbox?utm_source=wix>

Namn:

Bo Laddform

E-post:

bo.laddform@gmail.com

Telefon:

070-100 00 21

Välj vad du vill ha av oss i nästa steg:

Hjälp att välja rätt laddare för mig
TXT;

$solar = <<<TXT
A site visitor just submitted your form: Solceller lead on Demo Energi Järfälla‎<{$solarUrl}>

Offert på din mail, ring oss om du vill, vi ringer inte och tjatar.:

Hej, vi vill ha en offert på solceller.

Namn:

Bo Solform

E-post:

bo.solform@gmail.com
TXT;

$battery = <<<TXT
A site visitor just submitted your form: Solceller lead on Demo Energi Järfälla‎<{$solarUrl}>

Namn:

Bo Battform

E-post:

bo.battform@gmail.com

Välj vad du vill ha av oss i nästa steg:

Solceller
Batteri
Laddbox

Offert på batteri
TXT;

$silent = ['type' => 'Oklassad', 'action' => 'svara', 'priority' => 3, 'confidence' => 0, 'source' => 'none', 'reason' => 'Ingen modell kopplad.'];
$low = ['type' => 'Oklassad', 'action' => 'svara', 'priority' => 3, 'confidence' => 55, 'source' => 'llm', 'reason' => 'osäker', 'needs_review' => true];
$msg = static function (string $body, string $subject): array {
    return [
        'from' => 'info@wix-forms.com',
        'from_name' => 'Formulär',
        'subject' => $subject,
        'body' => $body,
        'body_text' => $body,
    ];
};

$chargerMsg = $msg($charger, 'Ladbox lead');
$solarMsg = $msg($solar, 'Solceller lead');
$batteryMsg = $msg($battery, 'Solceller lead');

wm(jeab_inkorg_form_selected_answer($charger) === 'Hjälp att välja rätt laddare för mig', 'laddboxsvaret är laddare, inte rubriken');
wm(jeab_inkorg_form_quote_offer($chargerMsg) === 'laddbox', 'laddare blir laddbox');
wm(jeab_inkorg_wix_known_product_offer($solarMsg) === 'sol', 'kundens mening offert på solceller blir sol');
$unclearSolar = $msg("A site visitor just submitted your form: Solceller lead\n\nMeddelande:\n\nVi vill ha en offert.\n", 'Solceller lead');
wm(jeab_inkorg_wix_known_product_offer($unclearSolar) === '', 'offert utan produktord tar inte formulärnamnet');
wm(jeab_inkorg_form_selected_answer($battery) === 'Offert på batteri', 'batterisvaret är offert på batteri');
wm(jeab_inkorg_product_token($battery) === 'batteri', 'alternativlista och sol-URL blir inte sol');
wm(jeab_inkorg_form_quote_offer($batteryMsg) === 'batteri', 'formulärofferten är batteri');
wm(jeab_inkorg_wix_known_product_offer($batteryMsg) === 'batteri', 'känd produkt följer svaret, inte solceller lead');
wm(jeab_inkorg_purchase_offer_product($batteryMsg) === 'batteri', 'inköpsregeln följer formulärsvar före sol-URL');
wm(jeab_inkorg_purchase_offer_product($chargerMsg) === 'laddbox', 'inköpsregeln läser laddboxsvaret');
wm(jeab_inkorg_purchase_offer_product($solarMsg) === 'sol', 'inköpsregeln läser solsvaret');

$bare = $msg("A site visitor just submitted your form: Ladbox lead\n\nNamn:\n\nBo Laddform\n", 'Ladbox lead');
wm(jeab_inkorg_wix_known_product_offer($bare) === '', 'rubrik utan svar sätter ingen produkt');
$bareLabels = jeab_inkorg_finish_labels($silent, $bare);
wm(($bareLabels['type'] ?? '') === 'Oklassad' && ($bareLabels['offer'] ?? 'x') === '' && !empty($bareLabels['needs_review']), 'otydligt formulär stannar i Behöver granskas');

$emptyTitle = [
    'Elbilsladdare' => "A site visitor just submitted your form: Elbilsladdare\n\nElbilsladdare\n\nMeddelande:\n\nRing mig\n",
    'Sol + batteri lead' => "A site visitor just submitted your form: Sol + batteri lead\n\nSol + batteri lead\n\nMeddelande:\n\nRing mig\n",
];
foreach ($emptyTitle as $title => $body) {
    $one = $msg($body, $title);
    $labels = jeab_inkorg_finish_labels($silent, $one);
    wm(jeab_inkorg_wix_known_product_offer($one) === '' && ($labels['offer'] ?? 'x') === '' && !empty($labels['needs_review']) && ($labels['type'] ?? '') !== 'Ny lead', 'rubriken ' . $title . ' och Ring mig granskas utan produkt, fick ' . (string) ($labels['type'] ?? '') . '/' . (string) ($labels['offer'] ?? ''));
}
$subjectSol = $msg("A site visitor just submitted your form: Allmänt\n\nMeddelande:\n\nRing mig\n", 'Solceller offert');
$subjectLabels = jeab_inkorg_finish_labels($silent, $subjectSol);
wm(jeab_inkorg_wix_known_product_offer($subjectSol) === '' && ($subjectLabels['offer'] ?? 'x') === '' && !empty($subjectLabels['needs_review']) && ($subjectLabels['type'] ?? '') !== 'Ny lead', 'ämnet Solceller offert och Ring mig granskas utan produkt, fick ' . (string) ($subjectLabels['type'] ?? '') . '/' . (string) ($subjectLabels['offer'] ?? ''));
wm(!str_contains(mb_strtolower(jeab_inkorg_wix_answer_text($subjectSol)), 'solceller') && !str_contains(mb_strtolower(jeab_inkorg_wix_answer_text($msg($emptyTitle['Elbilsladdare'], 'Elbilsladdare'))), 'elbilsladdare'), 'ämne och formulärnamn finns inte i svarstexten');
$beforeBanner = $msg("Elbilsladdare\nA site visitor just submitted your form\n\nMeddelande:\n\nRing mig\n", 'Elbilsladdare');
$beforeLabels = jeab_inkorg_finish_labels($silent, $beforeBanner);
wm(jeab_inkorg_wix_answer_text($beforeBanner) === 'Ring mig', 'raden före submitted your form är inte ett svar, fick ' . jeab_inkorg_wix_answer_text($beforeBanner));
wm(jeab_inkorg_wix_known_product_offer($beforeBanner) === '' && ($beforeLabels['offer'] ?? 'x') === '' && !empty($beforeLabels['needs_review']) && ($beforeLabels['type'] ?? '') !== 'Ny lead', 'formulärnamn före banderollen granskas utan produkt');
$subjectOnly = [
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'subject' => 'Solceller offert',
    'body' => 'Ring mig',
    'body_text' => 'Ring mig',
];
wm(jeab_inkorg_purchase_offer_product($subjectOnly) === '', 'Wix-ämnet Solceller offert med bara Ring mig ger ingen produkt');
$titled = $msg("Elbilsladdare\nA site visitor just submitted your form: Elbilsladdare\n\nMeddelande:\n\nRing mig\n", 'Solceller offert');
wm(jeab_inkorg_purchase_offer_product($titled) === '', 'Wix-rubrik och ämne ger ingen produkt när svaret är Ring mig');

foreach ([
    'laddbox' => $chargerMsg,
    'sol' => $solarMsg,
    'batteri' => $batteryMsg,
] as $offer => $one) {
    $done = jeab_inkorg_finish_labels($silent, $one);
    wm(($done['type'] ?? '') === 'Ny lead' && ($done['offer'] ?? '') === $offer && empty($done['needs_review']), 'tyst modell: ' . $offer . ' blev ' . (string) ($done['type'] ?? '') . '/' . (string) ($done['offer'] ?? ''));
    $unsure = jeab_inkorg_finish_labels($low, $one);
    wm(($unsure['type'] ?? '') === 'Ny lead' && ($unsure['offer'] ?? '') === $offer && empty($unsure['needs_review']), 'under 70: ' . $offer);
}

$sure = jeab_inkorg_finish_labels([
    'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 92, 'source' => 'llm', 'offer' => 'sol', 'intent' => 'kop', 'needs_review' => false,
], $batteryMsg);
wm(($sure['offer'] ?? '') === 'sol' && ($sure['type'] ?? '') === 'Ny lead', 'säker modell lämnas');

$box = jeab_graph_mailboxes()['jarfalla'];
$before = count($GLOBALS['_SENT']);
$in = jeab_inkorg_ingest([
    'message_id' => 'batt-missed@wix-missed.test',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'to' => $box,
    'subject' => 'Solceller lead',
    'body_text' => $battery,
    'source' => 'graph-jarfalla',
    'graph_mailbox' => $box,
    'headers' => ['Authentication-Results' => 'mx.example.test; dmarc=pass header.from=wix-forms.com'],
]);
$row = jeab_inkorg_get((string) ($in['id'] ?? ''));
wm(!empty($in['ok']) && is_array($row) && (string) ($row['labels']['type'] ?? '') === 'Ny lead' && (string) ($row['labels']['offer'] ?? '') === 'batteri' && empty($row['labels']['needs_review']), 'intaget batterisvar sparas som Ny lead');
wm(count($GLOBALS['_SENT']) === $before, 'intaget skickar inget');

$html = '<html><body><p>' . nl2br(htmlspecialchars($solar, ENT_QUOTES)) . '</p></body></html>';
$once = jeab_inkorg_llm_lead_text(['body' => $html, 'body_text' => $solar]);
wm(substr_count(mb_strtolower($once), 'vi vill ha en offert') === 1, 'html och body_text skickas en gång');
$same = jeab_inkorg_llm_lead_text(['body' => $battery, 'body_text' => $battery]);
wm(substr_count($same, 'Offert på batteri') === 1, 'identisk brödtext skickas inte två gånger');

$pad = str_repeat("Mellanrad utan produkt.\n", 800);
$longUrl = 'https://click.example.test/track?utm_source=wix&utm_campaign=' . str_repeat('z', 400);
$long = $pad . $longUrl . "\n\nVälj vad du vill ha av oss i nästa steg:\n\nOffert på batteri\n";
$trimmed = jeab_inkorg_llm_lead_text(['body' => $long]);
wm(mb_strlen($trimmed) <= 4000, 'texten ryms i 4000, fick ' . (string) mb_strlen($trimmed));
wm(str_contains($trimmed, 'Offert på batteri') && str_contains($trimmed, 'Välj vad du vill ha'), 'formulärsvar överlever klippet');
wm(!str_contains($trimmed, str_repeat('z', 80)), 'spårlänken är borta ur texten till Grok');

$batteryMsg['id'] = (string) ($in['id'] ?? '');
$snippet = jeab_inkorg_example_snippet($batteryMsg, 200);
wm($snippet === 'Offert på batteri', 'exemplets utdrag är svaret, fick ' . $snippet);
$added = jeab_inkorg_example_add($batteryMsg, 'typ:Ny lead', 'Bo');
wm(str_contains((string) ($added['snippet'] ?? ''), 'Offert på batteri') && !str_contains((string) ($added['snippet'] ?? ''), 'submitted your form'), 'sparat exempel har formulärsvar');
jeab_inkorg_locked('settings.json', static function (?array $d) use ($batteryMsg) {
    $d = $d ?? [];
    $examples = [];
    foreach ((array) ($d['examples'] ?? []) as $example) {
        if (is_array($example) && (string) ($example['message'] ?? '') === (string) $batteryMsg['id']) {
            $example['snippet'] = 'A site visitor just submitted your form: Solceller lead ' . str_repeat('mall ', 30);
        }
        $examples[] = $example;
    }
    $d['examples'] = $examples;

    return [null, $d];
});
unset($GLOBALS['_JEAB_INKORG_SETTINGS_MEM'], $GLOBALS['_JEAB_INKORG_SETTINGS_STAMP']);
$seen = '';
foreach (jeab_inkorg_examples_for($batteryMsg, 20) as $example) {
    if ((string) ($example['message'] ?? '') === (string) $batteryMsg['id']) {
        $seen = (string) ($example['snippet'] ?? '');
    }
}
wm($seen === 'Offert på batteri', 'exempellistan byter ut malltexten, fick ' . $seen);

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "inkorg-wix-missed-leads: ok\n";
    exit(0);
}
$pdo = jeab_inkorg_learn_pdo();
wm($pdo instanceof PDO, 'inlärningen har en databas');
jeab_inkorg_learn_enqueue(['id' => 'x-warm', 'kind' => 'type', 'label' => 'typ:Oklassad', 'message' => 'warm']);
$pdo->exec('DELETE FROM inkorg_learn_queue');
$job = [
    'id' => 'x-missed-learn',
    'kind' => 'type',
    'message' => (string) $batteryMsg['id'],
    'from' => 'info@wix-forms.com',
    'subject' => 'Solceller lead',
    'body' => $battery,
    'label' => 'typ:Ny lead',
    'by' => 'Bo',
];
$pdo->beginTransaction();
jeab_inkorg_learn_enqueue($job);
$during = (int) $pdo->query('SELECT COUNT(*) FROM inkorg_learn_queue')->fetchColumn();
wm($during === 0, 'upptagen transaktion skriver inte kön, fick ' . (string) $during);
jeab_inkorg_learn_flush_held();
$log = (string) @file_get_contents($tmp . '/app-errors.jsonl');
wm(str_contains($log, 'upptagen') || str_contains($log, 'transaktion'), 'felloggen får veta att kön inte skrevs');
$pdo->commit();
jeab_inkorg_learn_flush_held();
$after = (int) $pdo->query('SELECT COUNT(*) FROM inkorg_learn_queue')->fetchColumn();
wm($after === 1, 'rättelsen ligger i kön efter omförsök, fick ' . (string) $after);
wm(jeab_inkorg_learn_held() === [], 'köns minne är tömt');
wm(count($GLOBALS['_SENT']) === $before, 'testet skickar inget');

echo "inkorg-wix-missed-leads: ok\n";
