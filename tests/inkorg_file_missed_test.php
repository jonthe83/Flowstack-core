<?php

declare(strict_types=1);

/** Information lämnar Inkorgen. Lead med Grok-produkt får påminnelse. Hooken skickar inget. */

$tmp = sys_get_temp_dir() . '/jeab-file-missed-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function fm(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$rules = implode("\n", jeab_auto_rule_lines());
fm(str_contains($rules, 'inbox.file_missed') && str_contains($rules, 'Skicka offerter automatiskt'), 'reglerna nämner mappen Information och hooken');

$case = static function (string $product, string $type = 'Ny lead', string $kind = 'atgard', string $intent = 'kopintresse'): array {
    return [
        'type' => $type,
        'intent' => $intent,
        'kind' => $kind,
        'primary_product' => $product,
        'secondary_products' => [],
        'confidence' => ['primary_product' => 0.95, 'intent' => 0.95, 'type' => 0.95, 'kind' => 0.95],
    ];
};

$perific = [
    'from' => 'news@perific.se',
    'from_name' => 'Perific',
    'subject' => 'Nu skickas Perific One V3',
    'body' => 'Produkten skickas nu. Ingen åtgärd behövs.',
    'labels' => [
        'type' => 'Information',
        'intent' => 'information',
        'confidence' => 69,
        'needs_review' => true,
        'offer' => '',
        'case_class' => $case('', 'Information', 'information', 'information'),
    ],
];
fm(jeab_inkorg_klassning_route($perific) === 'information' && jeab_inkorg_klassning_quiet_information($perific), 'Perific med låg säkerhet är information');
fm(jeab_inkorg_lead_case_offer($perific) === '', 'Information med kind information är inte ett offertlead');

$gmail = [
    'from' => 'anna@gmail.com',
    'from_name' => 'Anna Svensson',
    'subject' => 'Info',
    'body' => 'Hej',
    'labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => 90],
];
fm(jeab_inkorg_klassning_route($gmail) === 'inkorg' && !jeab_inkorg_klassning_quiet_information($gmail), 'gmail-kund stannar i Inkorgen');

$onlyCase = [
    'from' => 'hello@perific.se',
    'from_name' => 'Perific',
    'subject' => 'Nu skickas Perific One V3',
    'body' => 'Ingen åtgärd.',
    'labels' => [
        'type' => 'Oklassad',
        'intent' => 'annat',
        'confidence' => 90,
        'needs_review' => false,
        'case_class' => $case('', 'Information', 'information', ''),
    ],
];
fm(jeab_inkorg_klassning_says_information($onlyCase) && jeab_inkorg_klassning_route($onlyCase) === 'information', 'Groks kind information räcker när typen inte är satt');

$batteri = [
    'from' => 'mattias.haag@gmail.com',
    'from_name' => 'Mattias Haag',
    'subject' => 'JEAB Batteri lead',
    'body' => 'Vi vill ha offert på batteri.',
    'labels' => [
        'type' => 'Oklassad',
        'intent' => 'annat',
        'confidence' => 92,
        'needs_review' => false,
        'offer' => '',
        'source' => 'llm',
        'case_class' => $case('Batteri'),
    ],
];
fm(jeab_inkorg_lead_case_offer($batteri) === 'batteri', 'Batteri i case_class blir offert även utan etiketten Ny lead');
fm(!jeab_inkorg_klassning_lead_sender($batteri), 'JEAB Batteri lead utan Wix-formulär är fortfarande inte en leadkälla');

$sol = $batteri;
$sol['from'] = 'andy.madani@gmail.com';
$sol['from_name'] = 'Andy Madani';
$sol['subject'] = 'Ny lead: Andy Madani · Villa i Upplands-Bro';
$sol['labels']['case_class'] = $case('Sol');
fm(jeab_inkorg_lead_case_offer($sol) === 'sol', 'Sol i case_class blir offert');

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);

$contactPdo = jeab_contact_store_pdo();
fm($contactPdo instanceof PDO, 'kontakt-SQL finns');
jeab_settings_put($contactPdo, jeab_driver(), JEAB_KONTAKTER_STORAGE_KEY, 'sql');
unset($GLOBALS['_JEAB_KLASSNING_SQL'], $GLOBALS['_JEAB_KLASSNING_KNOWN'], $GLOBALS['_JEAB_KLASSNING_CONTACT_SQL']);
jeab_kontakter_mutate(static function (array &$rows): null {
    $rows['k-perific'] = ['owners' => [['name' => 'Perific', 'email' => 'news@perific.se']]];

    return null;
});
unset($GLOBALS['_JEAB_KLASSNING_KNOWN'], $GLOBALS['_JEAB_KLASSNING_CONTACT_SQL'], $GLOBALS['_JEAB_KLASSNING_MEMO']);
fm(jeab_inkorg_klassning_known_contact('news@perific.se') && jeab_inkorg_klassning_route($perific) === 'information', 'känd företagskontakt med Information lämnar Inkorgen');

$in = static function (string $id, array $msg): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@file-missed.test',
        'from' => (string) $msg['from'],
        'from_name' => (string) ($msg['from_name'] ?? ''),
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => (string) $msg['subject'],
        'body_text' => (string) ($msg['body'] ?? ''),
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'preset_labels' => (array) $msg['labels'],
    ]);
};
$thread = static function (string $tid): array {
    foreach (jeab_inkorg_threads() as $t) {
        if ((string) ($t['id'] ?? '') === $tid) {
            return $t;
        }
    }

    return [];
};
$rems = static function (): int {
    return count(jeab_list_reminders(jeab_store(), true));
};

$infoIn = $in('perific', $perific);
$infoAuto = jeab_auto_on_message((string) $infoIn['id']);
$infoThread = $thread((string) $infoIn['thread_id']);
fm(($infoAuto['action'] ?? '') === 'information' && ($infoAuto['sent'] ?? 1) === 0 && ($infoThread['status'] ?? '') === 'open' && jeab_inkorg_folder_thread($infoThread, 'information') && !jeab_inkorg_folder_thread($infoThread, 'inkorg'), 'Perific hamnar öppen i Information');
fm($GLOBALS['_SENT'] === [], 'Perific skickade inget');

$remBefore = $rems();
$leadIn = $in('mattias', $batteri);
$leadAuto = jeab_auto_on_message((string) $leadIn['id']);
$leadMsg = jeab_inkorg_get((string) $leadIn['id']);
$leadThread = $thread((string) $leadIn['thread_id']);
fm(is_array($leadMsg) && (string) ($leadMsg['labels']['type'] ?? '') === 'Ny lead' && (string) ($leadMsg['labels']['offer'] ?? '') === 'batteri', 'leadet får produkt från case_class');
fm($rems() > $remBefore, 'leadet får en påminnelse när utskicket är av');
fm(($leadThread['status'] ?? '') === 'closed' && ($leadAuto['sent'] ?? 1) === 0 && jeab_inkorg_folder_thread($leadThread, 'historik'), 'leadet stängs utan kundmejl när utskicket är av: ' . (string) ($leadAuto['action'] ?? '') . ' ' . (string) ($leadAuto['why'] ?? ''));
$draftOffert = false;
foreach ((array) ($leadThread['drafts'] ?? []) as $draft) {
    if (is_array($draft) && (string) ($draft['kind'] ?? '') === 'lead-offert') {
        $draftOffert = true;
    }
}
fm(!$draftOffert && $GLOBALS['_SENT'] === [], 'avstängt utskick sparar inget offertutkast och skickar inget');

$testMsg = $batteri;
$testMsg['from'] = 'test.kund@example.com';
$testMsg['from_name'] = 'Test';
$testMsg['subject'] = 'JEAB Batteri lead';
$testIn = $in('testlead', $testMsg);
$testAuto = jeab_auto_on_message((string) $testIn['id']);
$testThread = $thread((string) $testIn['thread_id']);
fm(($testAuto['sent'] ?? 1) === 0 && ($testThread['status'] ?? '') === 'open', 'nytt testlead lämnas öppet av automationen så inget skickas');

$caseOnly = $onlyCase;
$caseIn = $in('case-info', $caseOnly);
$openCase = $thread((string) $caseIn['thread_id']);
fm(jeab_inkorg_folder_thread($openCase, 'information') && !jeab_inkorg_folder_thread($openCase, 'inkorg'), 'oklassad med kind information syns i Information');

$dry = jeab_inkorg_hook(['action' => 'inbox.file_missed']);
fm(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1 && ($dry['sent'] ?? 1) === 0 && ($dry['would_send'] ?? true) === false && ($dry['changed'] ?? 1) === 0, 'dry skriver inget och skickar inget');
fm(str_contains(json_encode($dry, JSON_UNESCAPED_UNICODE) ?: '', '@') === false, 'dry-svaret har ingen e-post');
$dryTest = false;
foreach ((array) ($dry['rows'] ?? []) as $row) {
    if ((string) ($row['id'] ?? '') === (string) $testIn['thread_id'] && (string) ($row['action'] ?? '') === 'closed_test') {
        $dryTest = true;
    }
}
fm($dryTest, 'dry listar testärendet som stängning');
fm(($thread((string) $testIn['thread_id'])['status'] ?? '') === 'open', 'dry lämnar testärendet öppet');

$sentBefore = count($GLOBALS['_SENT']);
$wet = jeab_inkorg_hook(['action' => 'inbox.file_missed', 'dry' => 0, 'thread' => (string) $testIn['thread_id']]);
$wetThread = $thread((string) $testIn['thread_id']);
fm(($wet['sent'] ?? 1) === 0 && ($wet['would_send'] ?? true) === false && ($wetThread['status'] ?? '') === 'closed' && count($GLOBALS['_SENT']) === $sentBefore, 'dry=0 stänger testärendet utan att skicka');
$again = jeab_inkorg_hook(['action' => 'inbox.file_missed', 'dry' => 0, 'thread' => (string) $testIn['thread_id']]);
fm(($again['rows'] ?? []) === [] && count($GLOBALS['_SENT']) === $sentBefore, 'samma hook igen rör inte det stängda ärendet och skickar inget');

echo "inkorg file missed: ok\n";
