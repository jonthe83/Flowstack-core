<?php

declare(strict_types=1);

/** Gränsen för Behöver granskas är 70, och en manuell rättelse når prompten. */

$tmp = sys_get_temp_dir() . '/jeab-review-min-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/layout.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function rv(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function rv_mail(string $id, array $labels): array
{
    return jeab_inkorg_ingest([
        'message_id' => '<' . $id . '@review.test>',
        'from' => $id . '@telia.com',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Osäker ' . $id,
        'body_text' => 'Hej, jag är osäker.',
        'source' => 'test-unit',
        'test' => true,
        'preset_labels' => $labels,
    ]);
}

function rv_labels(int $conf, bool $review = true): array
{
    return [
        'type' => 'Oklassad',
        'action' => 'svara',
        'priority' => 3,
        'confidence' => $conf,
        'source' => 'llm',
        'reason' => 'Behöver granskas',
        'needs_review' => $review,
        'intent' => '',
        'product' => '',
    ];
}

function rv_corrections(): string
{
    $path = jeab_inkorg_dir() . '/corrections.jsonl';

    return is_file($path) ? (string) file_get_contents($path) : '';
}

function rv_put_settings(array $extra): void
{
    $path = jeab_inkorg_dir() . '/settings.json';
    $d = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    $d = is_array($d) ? $d : [];
    file_put_contents($path, json_encode(array_merge($d, $extra), JSON_UNESCAPED_UNICODE));
}

rv(jeab_inkorg_review_min_confidence() === 70 && jeab_inkorg_review_field_min() === 0.7, 'standardgränsen är 70 och fältgränsen 0,7');
rv(JEAB_INKORG_REVIEW_BELOW === 70, 'konstanten är 70');

$GLOBALS['_JEAB_INKORG_LLM'] = static fn(): string => '{"type":"Ny lead","confidence":69,"product":"batteri","intent":"kop","offer":"batteri"}';
$low = jeab_inkorg_classify(['from' => 'a@telia.com', 'subject' => 'Hej', 'body' => 'Kanske batteri.']);
rv(!empty($low['needs_review']) && ($low['reason'] ?? '') === 'Behöver granskas' && ($low['product'] ?? 'x') === '', '69 hamnar i Behöver granskas utan produkt');
$GLOBALS['_JEAB_INKORG_LLM'] = static fn(): string => '{"type":"Ny lead","confidence":70,"product":"batteri","intent":"kop","offer":"batteri"}';
$ok = jeab_inkorg_classify(['from' => 'b@telia.com', 'subject' => 'Hej', 'body' => 'Jag vill ha batteri.']);
rv(empty($ok['needs_review']) && ($ok['product'] ?? '') === 'batteri', '70 går inte till granskning och behåller produkten');
$prompt = jeab_inkorg_llm_prompt(['from' => 'c@telia.com', 'subject' => 'Hej', 'body' => 'Text']);
rv(str_contains((string) ($prompt[0]['content'] ?? ''), 'confidence under 70'), 'prompten säger under 70');
unset($GLOBALS['_JEAB_INKORG_LLM']);

rv(jeab_auto_settings()['min_confidence'] === 70 && ($GLOBALS['_JEAB_AUTO_MIN_CONFIDENCE_REPORT'] ?? '') === 'saknas, standard 70', 'auto utan sparat värde är 70');
rv_put_settings(['auto_min_confidence' => 90, 'rebook_min_confidence' => 77]);
rv(jeab_auto_settings()['min_confidence'] === 90 && ($GLOBALS['_JEAB_AUTO_MIN_CONFIDENCE_REPORT'] ?? '') === 'sparad 90 lämnas', 'sparad 90 lämnas');
rv(jeab_rebook_settings()['min_confidence'] === 77, 'ombokningens sparade gräns lämnas');
rv_put_settings(['auto_min_confidence' => 80]);
$pick = jeab_auto_min_confidence_pick(['auto_min_confidence' => 80]);
rv($pick['value'] === 70 && $pick['report'] === 'sparad 80 sänkt till 70', 'sparad 80 rapporteras som sänkt till 70');
rv(jeab_auto_settings()['min_confidence'] === 70, 'läsning av sparad 80 ger 70');
$mig = jeab_inkorg_review_threshold_migrate();
$stored = json_decode((string) file_get_contents(jeab_inkorg_dir() . '/settings.json'), true);
rv(!empty($mig['rewritten']) && (int) ($stored['auto_min_confidence'] ?? 0) === 70 && ($mig['report'] ?? '') === 'sparad 80 sänkt till 70', 'filens 80 skrivs om till 70');
rv((int) ($stored['rebook_min_confidence'] ?? 0) === 77, 'ombokningen skrivs inte om');
rv_put_settings(['auto_min_confidence' => 90]);
$kept = jeab_inkorg_review_threshold_migrate();
rv(empty($kept['rewritten']) && $kept['report'] === 'sparad 90 lämnas', 'migreringen rör inte 90');

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['ny' => '1'];
jeab_inkorg_rule_save([
    'id' => '',
    'name' => 'Låg',
    'match' => 'all',
    'conditions' => [['field' => 'from', 'op' => 'contains', 'value' => 'lag@example.test']],
    'type' => 'Information',
    'confidence' => 65,
]);
jeab_inkorg_rule_save([
    'id' => '',
    'name' => 'Hög',
    'match' => 'all',
    'conditions' => [['field' => 'from', 'op' => 'contains', 'value' => 'hog@example.test']],
    'type' => 'Information',
    'confidence' => 70,
]);
$html = require dirname(__DIR__) . '/src/pages/inkorg_regler.php';
rv(is_string($html) && str_contains($html, 'Säkerhet under 70 %'), 'Regler visar gränsen överst');
rv(str_contains($html, 'Säkerhet (under 70 = till granskning)'), 'formuläret säger under 70');
rv(str_contains($html, 'till «Behöver granskas»') && str_contains($html, 'Låg'), 'regel under 70 visas som granskning');
$hogAt = strpos($html, 'Hög');
$hogSlice = $hogAt === false ? '' : substr($html, $hogAt, 220);
rv($hogAt !== false && !str_contains($hogSlice, 'till «Behöver granskas»'), 'regel på 70 visas inte som granskning');

$rel = rv_mail('relabel', rv_labels(60));
$row = jeab_inkorg_relabel((string) $rel['id'], ['type' => 'Ny lead'], 'Test');
rv(($row['labels']['type'] ?? '') === 'Ny lead' && str_contains(rv_corrections(), (string) $rel['id']) && str_contains(rv_corrections(), '"how":"relabel"'), 'ommärkning skrivs i corrections');
rv(jeab_inkorg_learn_drain() >= 1, 'inlärningskön körs');
$examples = jeab_inkorg_examples_for(['from' => 'ny@annat.se'], 20);
$relEx = null;
foreach ($examples as $ex) {
    if ((string) ($ex['message'] ?? '') === (string) $rel['id']) {
        $relEx = $ex;
    }
}
rv(is_array($relEx) && ($relEx['label'] ?? '') === 'typ:Ny lead' && ($relEx['intent'] ?? '') === 'kop', 'rättad typ och avsikt når prompten');
$again = jeab_inkorg_llm_prompt(['from' => 'ny@annat.se', 'subject' => 'Ny', 'body' => 'Hej']);
rv(str_contains((string) ($again[0]['content'] ?? ''), 'typ Ny lead') && str_contains((string) ($again[0]['content'] ?? ''), 'avsikt kop'), 'prompten tar med exemplet');

$prod = rv_mail('produkt', rv_labels(55));
jeab_inkorg_thread_set((string) $prod['thread_id'], 'open', ['batteri'], true, true);
rv(str_contains(rv_corrections(), '"how":"product"') && str_contains(rv_corrections(), (string) $prod['id']), 'ändrad produkt på ett granskningsmejl skrivs i corrections');
jeab_inkorg_learn_drain();
$prodHit = false;
foreach (jeab_inkorg_examples_for(['from' => 'annan@post.se'], 20) as $ex) {
    if ((string) ($ex['message'] ?? '') === (string) $prod['id'] && str_contains((string) ($ex['product'] ?? '') . (string) ($ex['label'] ?? ''), 'atteri')) {
        $prodHit = true;
    }
}
rv($prodHit, 'produktändringen når exemplen');

$sell = rv_mail('saljare', rv_labels(50));
jeab_inkorg_assign((string) $sell['thread_id'], 'Roberto', true);
rv(str_contains(rv_corrections(), '"how":"seller"') && str_contains(rv_corrections(), 'Roberto'), 'ändrad säljare skrivs i corrections');
jeab_inkorg_learn_drain();
$sellHit = false;
foreach (jeab_inkorg_examples_for(['from' => 'x@y.se'], 20) as $ex) {
    if ((string) ($ex['message'] ?? '') === (string) $sell['id'] && ($ex['seller'] ?? '') === 'Roberto') {
        $sellHit = true;
    }
}
rv($sellHit, 'säljaren når exemplen');

$close = rv_mail('stang', rv_labels(40));
jeab_inkorg_thread_set((string) $close['thread_id'], 'closed', null, true, true);
rv((string) (jeab_inkorg_thread_meta((string) $close['thread_id'])['status'] ?? '') === 'closed' && str_contains(rv_corrections(), '"how":"close"'), 'manuell stängning skrivs i corrections');
jeab_inkorg_learn_drain();
$closeHit = false;
foreach (jeab_inkorg_examples_for(['from' => 'z@y.se'], 20) as $ex) {
    if ((string) ($ex['message'] ?? '') === (string) $close['id'] && !empty($ex['closed'])) {
        $closeHit = true;
    }
}
rv($closeHit, 'stängningen når exemplen');

jeab_inkorg_example_add(['id' => 'old-bat', 'from' => 'gammal@post.se', 'subject' => 'Batteri', 'body' => 'Batteri tack', 'type' => 'Support', 'product' => 'Batteri'], 'typ:Support', 'Test', 'old-bat');
for ($i = 0; $i < 24; $i++) {
    jeab_inkorg_example_add(['id' => 'sol' . $i, 'from' => "sol{$i}@sol.se", 'subject' => 'Sol', 'body' => 'Sol', 'type' => 'Information', 'product' => 'Sol'], 'typ:Information', 'Test', 'sol' . $i);
}
$mixed = jeab_inkorg_examples_for(['from' => 'ny@annat.se'], 20);
rv(count($mixed) === 20, 'taket är fortfarande 20');
$keptOld = false;
foreach ($mixed as $ex) {
    if ((string) ($ex['id'] ?? '') === 'old-bat') {
        $keptOld = true;
    }
}
rv($keptOld, 'en äldre produkt blir kvar bland de senaste per typ');

$beforeSql = rv_corrections();
putenv('LEGACY_JSON_WRITES=0');
$sqlMail = rv_mail('sqlflag', rv_labels(45));
jeab_inkorg_relabel((string) $sqlMail['id'], ['type' => 'Support'], 'Test');
rv(rv_corrections() === $beforeSql, 'LEGACY_JSON_WRITES=0 skriver inte jsonl');
$pdo = jeab_data_pdo();
rv($pdo instanceof PDO, 'SQL är öppen');
$sqlHit = false;
if ($pdo instanceof PDO) {
    foreach ($pdo->query('SELECT line FROM inkorg_corrections') ?: [] as $dbRow) {
        $line = (string) ($dbRow['line'] ?? '');
        if (str_contains($line, (string) $sqlMail['id']) && str_contains($line, '"how":"relabel"')) {
            $sqlHit = true;
        }
    }
}
rv($sqlHit, 'korrigeringen ligger i inkorg_corrections');
putenv('LEGACY_JSON_WRITES');

$info = static function (int $conf): array {
    return [
        'from' => 'order@nordel.example',
        'subject' => 'Orderbekräftelse 100',
        'body' => 'Tack, ordern är registrerad.',
        'labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => $conf, 'source' => 'llm'],
    ];
};
rv(jeab_inkorg_klassning_partner_info($info(75)) === true, '75 räcker för säker leverantörsinformation');
rv(jeab_inkorg_klassning_partner_info($info(69)) === false, '69 är inte säker leverantörsinformation');

$GLOBALS['_JEAB_INKORG_LLM'] = static function (array $messages): string {
    $user = (string) ($messages[1]['content'] ?? '');
    $field = str_contains($user, 'LAGFALT') ? 0.65 : 0.75;

    return json_encode([
        'type' => 'Ny lead',
        'confidence' => 75,
        'intent' => 'kop',
        'offer' => 'batteri',
        'product' => 'batteri',
        'case_intent' => 'kopintresse',
        'primary_product' => 'Batteri',
        'field_confidence' => ['intent' => $field, 'primary_product' => $field],
    ], JSON_UNESCAPED_UNICODE);
};
$sureConv = [
    'id' => 'malmo-75',
    'subject' => 'Hej där',
    'status' => 'active',
    'primaryCustomer' => ['id' => '1', 'email' => 'malmo.kund@telia.com', 'first' => 'Mal', 'last' => 'Kund'],
    '_threads' => [[
        'type' => 'customer',
        'body' => 'Hej, jag undrar över ett batteri.',
        'createdBy' => ['email' => 'malmo.kund@telia.com'],
    ]],
];
$sureDecision = jeab_malmo_hs_decision($sureConv);
rv(($sureDecision['reason'] ?? '') !== 'osäker klassning', 'Malmö med 75 och fält 0,75 är inte osäker, fick ' . (string) ($sureDecision['reason'] ?? ''));
$lowConv = $sureConv;
$lowConv['id'] = 'malmo-lag';
$lowConv['subject'] = 'LAGFALT';
$lowConv['_threads'][0]['body'] = 'LAGFALT hej';
$lowDecision = jeab_malmo_hs_decision($lowConv);
rv(($lowDecision['reason'] ?? '') === 'osäker klassning', 'Malmö med fält 0,65 blir osäker klassning, fick ' . (string) ($lowDecision['reason'] ?? ''));

echo "OK\n";
