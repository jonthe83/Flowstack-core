<?php

declare(strict_types=1);

/**
 * Tre inkorgsfel: Wix-fältet Elbilsladdare, typen Information i rätt flik, och manuell Skräp som exempel.
 * Påhittade avsändare. Inget skickas till en riktig kund.
 */

$tmp = sys_get_temp_dir() . '/jeab-inbox-three-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function t3(bool $ok, string $message): void
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

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'unknown_product_ask_enabled' => 0,
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
foreach (['laddbox_jarfalla', 'batteri_jarfalla', 'sol_jarfalla'] as $pdfKey) {
    jeab_auto_pdf_save(['key' => $pdfKey, 'data' => base64_encode('%PDF-1.4 ' . $pdfKey)]);
}
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
t3(is_string($jpeg), 'testbilden kan avkodas');
jeab_auto_pdf_save(['key' => 'team_jarfalla', 'data' => base64_encode($jpeg)]);

$secrets = $tmp . '/secrets';
mkdir($secrets, 0700, true);
file_put_contents($secrets . '/inkorg-llm.json', json_encode(['key' => 'test-key-test-key-test-key', 'model' => 'grok-4-fast-non-reasoning']));
$cfg = jeab_inkorg_llm_config();
t3(is_array($cfg) && ($cfg['model'] ?? '') === 'grok-4.7', 'klassningen använder grok-4.7');
$payload = jeab_auto_llm_payload([['role' => 'user', 'content' => 'hej']], ['model' => 'grok-4.7', 'reasoning_effort' => 'medium']);
t3(($payload['model'] ?? '') === 'grok-4.7' && ($payload['reasoning_effort'] ?? '') === 'medium' && !array_key_exists('fast', $payload), 'anropskroppen är grok-4.7 med reasoning medium');
$classifySrc = (string) file_get_contents((string) (new ReflectionFunction('jeab_inkorg_llm_classify'))->getFileName());
$classifyAt = strpos($classifySrc, 'function jeab_inkorg_llm_classify');
$classifyBody = $classifyAt === false ? '' : substr($classifySrc, $classifyAt, 2200);
t3(str_contains($classifyBody, "'model' => 'grok-4.7'") && str_contains($classifyBody, "'reasoning_effort' => 'medium'") && !str_contains($classifyBody, 'grok-4-fast'), 'klassningsanropet ber om grok-4.7 medium');
unlink($secrets . '/inkorg-llm.json');

$suneBody = <<<'TXT'
A site visitor just submitted your form: Kontakt Formulär on Demo Energi Järfälla

Välj vad du vill ha information och mailoffert på?:

Elbilsladdare

Namn:

Sune Formson

Adress:

Testvägen 1

Telefon:

070-000 00 28

E-post:

sune.formbox@gmail.com
TXT;
$suneMsg = [
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'subject' => 'JEAB Kontakt Formulär',
    'body' => $suneBody,
    'body_text' => $suneBody,
];
t3(jeab_inkorg_form_product_raw($suneMsg) === 'Elbilsladdare', 'fältet mailoffert läser Elbilsladdare');
t3(jeab_inkorg_form_quote_offer($suneMsg) === 'laddbox', 'fältet blir laddbox');
t3(jeab_inkorg_wix_known_product_offer($suneMsg) === 'laddbox', 'Kontakt Formulär med svaret är en känd produkt');
t3(jeab_inkorg_wix_support_form($suneMsg) === false, 'samma formulär räknas inte som support');

$plain = ['type' => 'Oklassad', 'action' => 'svara', 'priority' => 3, 'confidence' => 0, 'source' => 'none', 'reason' => 'Ingen modell kopplad.'];
$labeled = jeab_inkorg_finish_labels($plain, $suneMsg);
t3(($labeled['type'] ?? '') === 'Ny lead' && ($labeled['offer'] ?? '') === 'laddbox' && empty($labeled['needs_review']), 'oklassat formulär med vald produkt blir Ny lead');

$low = jeab_inkorg_finish_labels([
    'type' => 'Oklassad', 'action' => 'svara', 'priority' => 3, 'confidence' => 60, 'source' => 'llm', 'reason' => 'osäker',
], $suneMsg);
t3(($low['type'] ?? '') === 'Ny lead' && ($low['offer'] ?? '') === 'laddbox' && empty($low['needs_review']), 'valt formulärsvar blir Ny lead även när Grok är under 70');

$box = jeab_graph_mailboxes()['jarfalla'];
$blind = jeab_inkorg_ingest([
    'message_id' => 'out-blind@inbox-three.test',
    'from' => 'saljare4@jarfalla.example.test',
    'to' => 'sune.formbox@gmail.com',
    'subject' => 'Tidigare',
    'body_text' => 'Hej',
    'source' => 'outbound-graph',
    'graph_mailbox' => $box,
    'received_at' => '',
]);
t3(!empty($blind['ok']), 'utgående rad utan datum tas in');
jeab_inkorg_update((string) ($blind['id'] ?? ''), static function (array $m): array {
    $m['received_at'] = '';
    $m['date'] = '';
    $m['sent_at'] = '';
    $m['created_at'] = '';
    $m['to'] = 'sune.formbox@gmail.com';
    $m['source'] = 'outbound-graph';

    return $m;
});
$history = jeab_auto_outgoing_recent('sune.formbox@gmail.com', 30);
t3(empty($history['ok']), 'utgående historik utan datum är oläsbar: ' . (string) ($history['why'] ?? ''));

$in = jeab_inkorg_ingest([
    'message_id' => 'sune-form@inbox-three.test',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'to' => $box,
    'subject' => 'JEAB Kontakt Formulär',
    'body_text' => $suneBody,
    'source' => 'graph-jarfalla',
    'graph_mailbox' => $box,
    'headers' => ['Authentication-Results' => 'mx.example.test; dmarc=pass header.from=wix-forms.com'],
]);
t3(!empty($in['ok']), 'formuläret tas in');
$mid = (string) ($in['id'] ?? '');
$row = jeab_inkorg_get($mid);
t3(is_array($row) && (string) ($row['labels']['type'] ?? '') === 'Ny lead' && (string) ($row['labels']['offer'] ?? '') === 'laddbox', 'intaget sparas som laddbox');
jeab_address_mark_review((string) ($row['thread_id'] ?? ''));
$row = jeab_inkorg_get($mid);
t3(is_array($row) && empty($row['labels']['needs_review']), 'osäker ort sätter inte Behöver granskas på en vald produkt');
$before = count($GLOBALS['_SENT']);
$auto = jeab_auto_on_message($mid);
$thread = null;
foreach (jeab_inkorg_threads() as $one) {
    if ((string) ($one['id'] ?? '') === (string) ($in['thread_id'] ?? '')) {
        $thread = $one;
    }
}
t3(is_array($thread), 'ärendet finns');
t3(($auto['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $before + 1, 'offerten skickas trots oläsbar utgående historik: ' . (string) ($auto['action'] ?? '') . ' ' . (string) ($auto['why'] ?? ''));
t3((string) ($thread['status'] ?? '') === 'closed', 'ärendet stängs när offerten gått');
$reminded = false;
foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
    if (is_array($rem) && str_contains((string) ($rem['title'] ?? ''), 'Elbilsladdare')) {
        $reminded = true;
    }
}
t3($reminded, 'påminnelsen skapas');

$hans = [
    'id' => 'hans',
    'status' => 'open',
    'labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => 92],
    'tags' => [],
    'manual_tags' => [],
    'messages' => [[
        'from' => 'order@Demos.se',
        'from_name' => 'Hans Johan Edin',
        'subject' => 'Sv: Hämtning All-inclusive XL',
        'body' => 'Vi hämtar.',
        'labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => 92],
    ]],
];
t3(jeab_inkorg_folder_thread($hans, 'information') && !jeab_inkorg_folder_thread($hans, 'inkorg'), 'Information med Sv: ligger i Information');
$service = $hans;
$service['labels']['type'] = 'Service';
$service['messages'][0]['labels']['type'] = 'Service';
$service['messages'][0]['labels']['intent'] = 'support';
t3(jeab_inkorg_folder_thread($service, 'information') && !jeab_inkorg_folder_thread($service, 'inkorg'), 'Service ligger i Information');
$lead = $hans;
$lead['labels']['type'] = 'Ny lead';
$lead['messages'][0]['labels']['type'] = 'Ny lead';
$lead['messages'][0]['labels']['intent'] = 'kop';
t3(jeab_inkorg_folder_thread($lead, 'inkorg') && !jeab_inkorg_folder_thread($lead, 'information'), 'Ny lead stannar i Inkorgen');

$epax = jeab_inkorg_ingest([
    'message_id' => 'epax@inbox-three.test',
    'from' => 'info@epax-solar.de',
    'from_name' => 'Epax Solar GmbH',
    'to' => $box,
    'subject' => 'Neu: Dyness Speicher und Webshop',
    'body_text' => "Angebot an Sie.\nDyness Speicher und Pionierkraft.",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => $box,
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 80,
        'intent' => 'kop', 'offer' => 'batteri', 'source' => 'llm',
    ],
]);
t3(!empty($epax['ok']), 'leverantörsmejlet tas in');
jeab_inkorg_panel_choice_save((string) ($epax['thread_id'] ?? ''), 'spam', 'Sam');
$saved = null;
foreach (jeab_inkorg_threads() as $one) {
    if ((string) ($one['id'] ?? '') === (string) ($epax['thread_id'] ?? '')) {
        $saved = $one;
    }
}
t3(is_array($saved) && (string) ($saved['labels']['type'] ?? '') === 'Skräp', 'panelen sätter Skräp');
$similar = [
    'from' => 'sales@epax-solar.de',
    'from_name' => 'Epax Solar',
    'subject' => 'Dyness Speicher Webshop',
    'body' => 'Weitere Angebote.',
];
$examples = jeab_inkorg_examples_for($similar, 20);
t3($examples !== [] && str_ends_with(strtolower((string) ($examples[0]['from'] ?? '')), '@epax-solar.de'), 'samma domän kommer först bland exemplen');
t3((string) ($examples[0]['type'] ?? '') === 'Skräp' || str_contains((string) ($examples[0]['label'] ?? ''), 'Skräp'), 'exemplet är Skräp');
$prompt = jeab_inkorg_llm_prompt($similar);
$system = (string) ($prompt[0]['content'] ?? '');
t3(str_contains($system, 'epax-solar.de') && str_contains($system, 'Skräp'), 'prompten får domänexemplet');
t3(str_contains($system, (string) jeab_inkorg_review_min_confidence()), 'osäker klassning ska fortfarande granskas');
t3(!str_contains($system, 'epax-solar.de är'), 'domänen är ett exempel, inte en egen regel');
