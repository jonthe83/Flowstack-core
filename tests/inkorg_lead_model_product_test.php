<?php

declare(strict_types=1);

/** D8: källan sätter inte produkt eller säljare. Grok läser texten. Osäkert granskas. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-lead-model-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function lm(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$hitta = [
    'from' => 'leads@hitta-solceller.se',
    'from_name' => 'Hitta-Solceller',
    'subject' => 'Ny lead från Hitta-Solceller',
    'body' => "Hej Demo Energi Järfälla,\n\nEn ny lead från Hitta-Solceller.\n\nNamn: Vera Exempel\nE-post: vera.exempel@example.com\nTelefon: 070-111 22 33\nAdress: Exempelvägen 1, 176 70 Järfälla\nBehov: Endast solcellsbatteri\nMeddelande:\nVi vill ha pris.\n\nHitta-Solceller.se\n",
];
$prompt = jeab_inkorg_llm_prompt($hitta);
$user = (string) ($prompt[1]['content'] ?? '');
lm(str_contains($user, 'Behov: Endast solcellsbatteri'), 'hela leadtexten med Behov skickas till modellen');
lm(jeab_inkorg_purchase_offer_product([
    'from' => 'leads@hitta-solceller.se',
    'from_name' => 'Hitta-Solceller',
    'subject' => 'Ny offert från Hitta-Solceller',
    'body' => "Namn: Noa Exempel\nE-post: noa.exempel@example.com\nMeddelande: Kan jag få en offert?\n",
]) === '', 'Hitta-Solceller utan produkt i texten får ingen produkt');
lm(!str_contains((string) (jeab_inkorg_purchase_offer_detail($hitta)['basis'] ?? ''), 'källa'), 'träffen kommer inte från källan');
lm(jeab_lead_infer_offer($hitta + ['labels' => ['source' => 'none', 'confidence' => 0, 'offer' => 'sol']]) === '', 'misslyckad modell ger ingen produkt');
lm(jeab_lead_bought_source($hitta) === 'hitta-solceller', 'portalen är fortfarande Hitta-Solceller');
lm(jeab_lead_bought_source(['from' => 'info@wix-forms.com', 'subject' => 'JEAB', 'body' => 'Intresse: Solceller']) === '', 'Wix är inte en köpt källa');

$rule = jeab_inkorg_rule_save([
    'name' => 'Köpt portal',
    'match' => 'all',
    'conditions' => [['field' => 'domain', 'op' => 'equals', 'value' => 'portal.example']],
    'type' => 'Ny lead',
    'assign' => 'Roberto',
    'lead_sender' => true,
]);
$ruled = jeab_inkorg_classify([
    'from' => 'leads@portal.example',
    'subject' => 'Ny lead',
    'body' => 'Behov: batteri',
]);
lm(($ruled['lead'] ?? '') === 'kopt' && ($ruled['assign'] ?? '') === '' && (string) ($ruled['offer'] ?? '') === '' && (string) ($ruled['product'] ?? '') === '', 'regeln säger köpt och sätter varken produkt eller säljare');
jeab_inkorg_rule_delete((string) $rule['id']);

$GLOBALS['_JEAB_INKORG_LLM'] = static function (array $messages): string {
    $text = (string) ($messages[1]['content'] ?? '');
    if (str_contains($text, 'FAIL-MODEL')) {
        return 'inte json';
    }
    if (str_contains($text, 'OSAKER-LEAD')) {
        return json_encode([
            'type' => 'Ny lead',
            'confidence' => 40,
            'offer' => 'sol',
            'intent' => 'kop',
            'reason' => 'osäker',
        ], JSON_UNESCAPED_UNICODE);
    }
    return json_encode([
        'type' => 'Ny lead',
        'confidence' => 92,
        'offer' => 'batteri',
        'intent' => 'kop',
        'reason' => 'kunden vill bara ha batteri',
        'is_test' => true,
    ], JSON_UNESCAPED_UNICODE);
};

$failed = jeab_inkorg_classify([
    'from' => 'leads@hitta-solceller.se',
    'subject' => 'Ny lead',
    'body' => "Namn: Vera Kund\nBehov: Endast solcellsbatteri\nFAIL-MODEL\n",
]);
lm(($failed['needs_review'] ?? false) === true && (string) ($failed['offer'] ?? 'sol') === '' && (string) ($failed['reason'] ?? '') === 'Behöver granskas', 'tyst modell hamnar i Behöver granskas utan produkt');
$unsure = jeab_inkorg_classify([
    'from' => 'lead@solceller.nu',
    'subject' => 'Ny lead',
    'body' => "Namn: Uno Kund\nOSAKER-LEAD\nBehov: vet inte\n",
]);
lm(($unsure['needs_review'] ?? false) === true && (string) ($unsure['offer'] ?? 'sol') === '' && (string) ($unsure['reason'] ?? '') === 'Behöver granskas', 'osäker modell sätter inte sol');
$sure = jeab_lead_model_decision($hitta);
lm(($sure['review'] ?? true) === false && ($sure['offer'] ?? '') === 'batteri' && ($sure['intent'] ?? '') === 'kop', 'modellen säger batteri');

$dry = jeab_inkorg_hook(['action' => 'leads.product_recheck', 'dry' => 0]);
lm(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1 && ($dry['wrote'] ?? 1) === 0 && ($dry['days'] ?? 0) === 30, 'omkontrollen skriver inte ens med dry=0');
lm(str_contains(implode("\n", jeab_auto_rule_lines()), 'leads.product_recheck'), 'regeln nämner hooken');

echo "OK  lead model product\n";
