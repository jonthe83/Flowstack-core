<?php

declare(strict_types=1);

/**
 * Wix Kontakt Formulär, enda fritexten «Ferroamp fasbalansering».
 * Modellen säger Ny lead / köp / egen, men ingen känd offertprodukt.
 * Förr: action none, inget utkast, ingen påminnelse, needs_review false.
 * Nu: påminnelse till personen för okänd produkt, eller granskning. Inget nytt kundmejl.
 */

$tmp = sys_get_temp_dir() . '/jeab-ferro-stuck-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ferro_ok(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;

    return ['ok' => true, 'from' => 'demo@example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'grok_decides' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'unknown_product_ask_enabled' => 0,
    'follow_receipt_enabled' => 0,
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);

$bare = [
    'from' => 'info@wix-forms.com',
    'subject' => 'Kontakt Formulär',
    'body' => "A site visitor just submitted your form: Kontakt Formulär\n\nMeddelande:\n\nHej.\n",
];
ferro_ok(jeab_inkorg_productless_form_skips_reminder($bare), 'oklassat Kontakt Formulär skapar fortfarande ingen påminnelse');
$classified = $bare;
$classified['labels'] = ['type' => 'Ny lead', 'confidence' => 92, 'needs_review' => false, 'intent' => 'kop', 'source' => 'llm'];
ferro_ok(!jeab_inkorg_productless_form_skips_reminder($classified), 'klassat Ny lead på Kontakt Formulär hoppas inte över');

$form = static function (string $email, string $phone): string {
    return "A site visitor just submitted your form: Kontakt Formulär\n\nNamn:\n\nMaja Ferro\n\nE-post:\n\n{$email}\n\nTelefon:\n\n{$phone}\n\nAdress:\n\nStorgatan 1\n\nPostnummer:\n\n177 31\n\nOrt:\n\nJärfälla\n\nMeddelande:\n\nFerroamp fasbalansering\n";
};

$ingest = static function (string $id, string $email, string $phone, array $model): array {
    $GLOBALS['_JEAB_INKORG_LLM'] = static function () use ($model): string {
        return json_encode($model, JSON_UNESCAPED_UNICODE);
    };

    return jeab_inkorg_ingest([
        'message_id' => $id . '@ferro-stuck.test',
        'from' => 'no-reply@crm.wix.com',
        'from_name' => 'Wix',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'JEAB Kontakt Formulär',
        'body_text' => $GLOBALS['_FERRO_BODY']($email, $phone),
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
    ]);
};
$GLOBALS['_FERRO_BODY'] = $form;

$remindersFor = static function (string $tid): array {
    $hits = [];
    foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
        if (!is_array($rem)) {
            continue;
        }
        $note = (string) ($rem['note'] ?? '');
        if (trim((string) ($rem['thread_id'] ?? '')) === $tid || ($tid !== '' && str_contains($note, $tid))) {
            $hits[] = $rem;
        }
    }

    return $hits;
};

$stuckModel = [
    'type' => 'Ny lead',
    'confidence' => 92,
    'product' => '',
    'offer' => '',
    'intent' => 'kop',
    'lead' => 'eget',
    'question' => 'Ferroamp fasbalansering',
    'reason' => 'Ferroamp fasbalansering',
    'case_intent' => 'kopintresse',
    'primary_product' => '',
    'kind' => 'atgard',
    'is_lead_seller' => false,
    'customer_email' => 'maja.stuck@gmail.com',
    'customer_name' => 'Maja Ferro',
    'field_confidence' => [
        'is_lead_seller' => 0.95,
        'customer_email' => 0.95,
        'customer_name' => 0.95,
        'intent' => 0.95,
        'primary_product' => 0.95,
        'kind' => 0.95,
        'type' => 0.95,
        'label' => 0.9,
        'seller_name' => 0.2,
    ],
];
$saved = $ingest('stuck', 'maja.stuck@gmail.com', '070-555 19 19', $stuckModel);
ferro_ok(!empty($saved['ok']), 'Ferroamp-formuläret tas emot');
$sentBefore = count($GLOBALS['_SENT']);
$auto = jeab_auto_on_message((string) ($saved['id'] ?? ''));
$row = jeab_inkorg_get((string) ($saved['id'] ?? ''));
$labels = is_array($row) ? (array) ($row['labels'] ?? []) : [];
$tid = (string) ($saved['thread_id'] ?? '');
$meta = jeab_inkorg_thread_meta($tid);
$rems = $remindersFor($tid);
$case = (array) ($labels['case_class'] ?? []);
$orderLink = (int) (($case['order_link']['reminder_id'] ?? 0));
ferro_ok((string) ($labels['type'] ?? '') === 'Ny lead' && (string) ($labels['intent'] ?? '') === 'kop' && (string) ($labels['lead'] ?? '') === 'eget', 'klassningen förblir Ny lead, köp, eget');
ferro_ok((string) ($labels['offer'] ?? 'sol') === '' && (string) ($labels['product'] ?? 'solceller') === '', 'Ferroamp-texten sätter varken offer eller produkt');
ferro_ok(count($GLOBALS['_SENT']) === $sentBefore, 'inget kundmejl skickas');
$ended = count($rems) === 1 || !empty($labels['needs_review']);
ferro_ok($ended, 'påminnelse eller granskning, fick action=' . (string) ($auto['action'] ?? '') . ' rems=' . count($rems) . ' review=' . (int) !empty($labels['needs_review']));
ferro_ok(!(count($rems) === 0 && empty($labels['needs_review']) && ($auto['action'] ?? '') === 'none'), 'stannar inte tyst');
if (count($rems) === 1) {
    $title = (string) ($rems[0]['title'] ?? '');
    $who = (string) ($rems[0]['assignee'] ?? '');
    ferro_ok(str_contains($title, 'okänd produkt'), 'påminnelsen gäller okänd produkt, fick ' . $title);
    ferro_ok(strcasecmp($who, 'Sam L') === 0, 'okänd produkt ligger hos Sam L, fick ' . $who);
    ferro_ok((string) ($meta['assignee'] ?? '') === 'SamL', 'ärendet tilldelas SamL');
    ferro_ok(empty($labels['needs_review']), 'påminnelse och granskning används inte samtidigt');
    ferro_ok($orderLink === 0, 'order_link.reminder_id är fortfarande inte leadets påminnelse');
}

$solarModel = $stuckModel;
$solarModel['product'] = 'solceller';
$solarModel['offer'] = 'sol';
$solarModel['primary_product'] = 'Sol';
$solarModel['question'] = 'Ferroamp fasbalansering';
$solarModel['customer_email'] = 'maja.solar@gmail.com';
$solarSaved = $ingest('solar', 'maja.solar@gmail.com', '070-555 20 20', $solarModel);
$solarSent = count($GLOBALS['_SENT']);
$solarAuto = jeab_auto_on_message((string) ($solarSaved['id'] ?? ''));
$solarRow = jeab_inkorg_get((string) ($solarSaved['id'] ?? ''));
$solarLabels = is_array($solarRow) ? (array) ($solarRow['labels'] ?? []) : [];
$solarTid = (string) ($solarSaved['thread_id'] ?? '');
$solarMeta = jeab_inkorg_thread_meta($solarTid);
$solarRems = $remindersFor($solarTid);
ferro_ok((string) ($solarLabels['offer'] ?? '') === 'sol' && (string) ($solarLabels['product'] ?? '') === 'solceller', 'modellens solprodukt lämnas');
ferro_ok(count($GLOBALS['_SENT']) === $solarSent, 'befintlig spärr skickar ingen offert utan autentisering');
ferro_ok(count($solarRems) === 1 && str_contains((string) ($solarRems[0]['title'] ?? ''), 'Solceller'), 'sol-lead får solpåminnelse, fick ' . (string) ($solarRems[0]['title'] ?? ''));
ferro_ok((string) ($solarMeta['assignee'] ?? '') === 'SamL' && empty($solarLabels['needs_review']), 'sol-lead tilldelas SamL utan granskning');
ferro_ok(($solarAuto['action'] ?? '') !== 'none' && ($solarAuto['why'] ?? '') !== 'etiketten är inte Ny lead', 'sol-lead lämnar inte kedjan tyst');

echo "ferroamp stuck lead: ok\n";
