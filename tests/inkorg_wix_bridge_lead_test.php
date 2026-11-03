<?php

declare(strict_types=1);

/** Wix Sol/Batteri från bron: Ny lead, öppen tråd. Test med produkt får påminnelse med Test-chip och inget utskick. */

$tmp = sys_get_temp_dir() . '/jeab-wix-bridge-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

function wb(bool $ok, string $m): void
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

    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'unknown_product_ask_enabled' => 0,
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);

wb(jeab_inkorg_own_address('jeab@mail.grokbot.com') && jeab_inkorg_own_address('jeab+inkorg@mail.grokbot.com'), 'jeab@ och jeab+inkorg@ är egna');
wb(!jeab_inkorg_own_address('jeab+test-1@mail.grokbot.com') && !jeab_inkorg_own_address('ada@mail.grokbot.com') && !jeab_inkorg_own_address('kund@example.com'), 'jeab+test, annan grokbot och example.com är inte egna');
wb(!isset(jeab_inkorg_klassning_staff_sets()['domains']['mail.grokbot.com']), 'mail.grokbot.com är inte en personaldomän');

$summary = static function (string $form, string $email, string $name): string {
    return "A site visitor just submitted your form {$form}\n\nNamn:\n\n{$name}\n\nE-post:\n\n{$email}\n\nTelefon:\n\n070-123 45 67\n\nAdress:\n\nSolvägen 1, 177 32 Järfälla\n\nMeddelande:\n\nVi vill ha en offert.\n";
};

$meta = [
    'from' => 'sara.kund@gmail.com',
    'from_name' => 'Sara Kund',
    'subject' => 'JEAB Batteri lead',
    'body' => 'Test inskick - Meta',
];
wb(!jeab_inkorg_klassning_wix_lead_summary($meta) && !jeab_inkorg_klassning_lead_sender($meta), 'JEAB Batteri lead utan Wix-formulär är inte ett lead');
$metaForm = $meta;
$metaForm['body'] = "A site visitor just submitted your form Batteri lead\n\nTest inskick - Meta\n";
wb(jeab_inkorg_klassning_wix_lead_summary($metaForm) && jeab_inkorg_klassning_lead_sender($metaForm) && !jeab_inkorg_klassning_own($metaForm), 'Wix Batteri-formulär med Test inskick är lead och inte egen');
wb(jeab_inkorg_klassning_route($metaForm) === 'kop' && jeab_test_lead_fields($metaForm)['test'] === true, 'Wix Batteri-formulär med Test inskick klassas som lead (test)');
$intern = [
    'from' => 'jeab@mail.grokbot.com',
    'from_name' => 'JEAB',
    'subject' => 'JEAB Batteri lead',
    'body' => "JEAB Batteri lead\nIntern anteckning.",
];
wb(!jeab_inkorg_klassning_wix_lead_summary($intern) && !jeab_inkorg_klassning_lead_sender($intern) && jeab_inkorg_klassning_own($intern) && jeab_inkorg_klassning_route($intern) === 'egen', 'internpost från jeab@ med JEAB Batteri lead är inte ett lead');
$internForm = $intern;
$internForm['body'] = "A site visitor just submitted your form Batteri lead\n\nJEAB Batteri lead\n";
wb(!jeab_inkorg_klassning_lead_sender($internForm) && jeab_inkorg_klassning_own($internForm), 'jeab@ med Wix-text är fortfarande egen post');

$kontakt = [
    'from' => 'luis.form@gmail.com',
    'from_name' => 'Luis Carvajal',
    'subject' => 'JEAB Kontakt Formulär',
    'body' => "A site visitor just submitted your form: Kontakt Formulär\n\nNamn:\n\nLuis Carvajal\n\nE-post:\n\nlui.form@gmail.com\n\nMeddelande:\n\nHej.\n",
];
wb(!jeab_inkorg_klassning_wix_lead_summary($kontakt) && !jeab_inkorg_klassning_lead_sender($kontakt), 'Kontakt Formulär utan produkt är inte ett lead');

$ingest = static function (string $id, string $from, string $name, string $subject, string $body): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@wix-bridge.test',
        'from' => $from,
        'from_name' => $name,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => $subject,
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
    ]);
};
$thread = static function (string $tid): array {
    $meta = jeab_inkorg_thread_meta($tid);

    return is_array($meta) ? $meta : [];
};
$remindersFor = static function (string $email): array {
    $email = mb_strtolower($email);
    $hits = [];
    foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
        if (!is_array($rem)) {
            continue;
        }
        if (mb_strtolower(trim((string) ($rem['email'] ?? ''))) === $email) {
            $hits[] = $rem;
        }
    }

    return $hits;
};

$cases = [
    ['id' => 'grok', 'from' => 'jeab+test-9@mail.grokbot.com', 'name' => 'Test Bron', 'subject' => 'JEAB Batteri lead', 'form' => 'Batteri lead', 'test' => true],
    ['id' => 'ex', 'from' => 'ada.bridge@example.com', 'name' => 'Ada Bridge', 'subject' => 'JEAB Solceller lead', 'form' => 'Solceller lead', 'test' => true],
    ['id' => 'real', 'from' => 'vera.bridge@gmail.com', 'name' => 'Vera Bridge', 'subject' => 'JEAB Batteri lead', 'form' => 'Batteri lead', 'test' => false],
];
foreach ($cases as $case) {
    $saved = $ingest($case['id'], $case['from'], $case['name'], $case['subject'], $summary($case['form'], $case['from'], $case['name']));
    wb(!empty($saved['ok']), $case['id'] . ' tas emot');
    $auto = jeab_auto_on_message((string) ($saved['id'] ?? ''));
    $row = jeab_inkorg_get((string) ($saved['id'] ?? ''));
    $metaRow = $thread((string) ($saved['thread_id'] ?? ''));
    $tags = array_map('strval', (array) ($metaRow['tags'] ?? []));
    $type = is_array($row) ? (string) (($row['labels']['type'] ?? '')) : '';
    $status = (string) ($metaRow['status'] ?? '');
    $rems = $remindersFor($case['from']);
    $offerNow = is_array($row) ? (string) ($row['labels']['offer'] ?? '') : 'batteri';
    if ($case['test']) {
        wb($type === 'Ny lead' && $offerNow === '' && $status === 'open' && ($auto['sent'] ?? 0) === 0 && count($rems) === 0, $case['id'] . ' blir testlead utan produkt från formulärrubriken, fick ' . $type . '/' . $status . '/' . $offerNow . '/' . (string) ($auto['why'] ?? ''));
        wb(in_array('test', $tags, true) && !empty($metaRow['test_lead']), $case['id'] . ' har taggen Test på tråden');
        continue;
    }
    wb($type === 'Oklassad' && $offerNow === '' && ($row['labels']['needs_review'] ?? false) === true && ($row['labels']['reason'] ?? '') === 'Behöver granskas' && $status === 'open' && ($auto['sent'] ?? 0) === 0 && count($rems) === 0, $case['id'] . ' utan modell granskas utan produkt, fick ' . $type . '/' . $status . '/' . $offerNow . '/' . (string) ($auto['why'] ?? ''));
}

$metaSaved = $ingest('meta', 'sara.kund@gmail.com', 'Sara Kund', 'JEAB Batteri lead', "A site visitor just submitted your form Batteri lead\n\nTest inskick - Meta\n");
$metaAuto = jeab_auto_on_message((string) ($metaSaved['id'] ?? ''));
$metaRow = jeab_inkorg_get((string) ($metaSaved['id'] ?? ''));
$metaThread = $thread((string) ($metaSaved['thread_id'] ?? ''));
$metaRems = $remindersFor('sara.kund@gmail.com');
wb((string) (($metaRow['labels']['type'] ?? '')) === 'Ny lead'
    && (string) ($metaRow['labels']['offer'] ?? '') === ''
    && (string) ($metaThread['status'] ?? '') === 'open'
    && count($metaRems) === 0
    && ($metaAuto['sent'] ?? 0) === 0
    && ($metaAuto['why'] ?? '') === 'testlead', 'Test inskick - Meta blir testlead utan produkt och utan utskick');

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'unknown_product_ask_enabled' => 0,
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
$sentBefore = count($GLOBALS['_SENT']);
$quoteOn = $ingest('quoteon', 'jeab+test-3@mail.grokbot.com', 'Ladd Test', 'JEAB Laddbox lead', $summary('Laddbox lead', 'jeab+test-3@mail.grokbot.com', 'Ladd Test'));
$quoteAuto = jeab_auto_on_message((string) ($quoteOn['id'] ?? ''));
$quoteRems = $remindersFor('jeab+test-3@mail.grokbot.com');
$quoteThread = $thread((string) ($quoteOn['thread_id'] ?? ''));
wb(count($GLOBALS['_SENT']) === $sentBefore
    && ($quoteAuto['why'] ?? '') === 'testlead'
    && ($quoteAuto['sent'] ?? 0) === 0
    && (string) ($quoteThread['status'] ?? '') === 'open'
    && count($quoteRems) === 0
    && in_array('test', array_map('strval', (array) ($quoteThread['tags'] ?? [])), true), 'test med laddbox-rubrik skickar inget och skapar ingen påminnelse');

$histEmail = 'vera.historik@gmail.com';
$hist = $ingest('hist', $histEmail, 'Vera Historik', 'JEAB Batteri lead', $summary('Batteri lead', $histEmail, 'Vera Historik'));
$histTid = (string) ($hist['thread_id'] ?? '');
jeab_auto_on_message((string) ($hist['id'] ?? ''));
jeab_inkorg_klassning_kop_historik($histTid, 'kop-skickad');
jeab_auto_quote_sent_remember($histTid, 'note', ['hit' => true, 'date' => date('c')], 'batteri');
$histSnap = $thread($histTid);
$histSent = count($GLOBALS['_SENT']);
$histAgain = jeab_auto_on_message((string) ($hist['id'] ?? ''));
$histAfter = $thread($histTid);
$histSentMark = is_array($histSnap['quote_sent'] ?? null) && trim((string) ($histSnap['quote_sent']['date'] ?? '')) !== '';
wb((string) ($histSnap['status'] ?? '') === 'closed' && $histSentMark, 'Wix-leadet ligger i Historik med skickad offert');
$histAfterMark = is_array($histAfter['quote_sent'] ?? null) && trim((string) ($histAfter['quote_sent']['date'] ?? '')) !== '';
wb((string) ($histAfter['status'] ?? '') === 'closed'
    && (string) ($histAfter['hook_closed'] ?? '') === 'kop-skickad'
    && $histAfterMark
    && count($GLOBALS['_SENT']) === $histSent
    && ($histAgain['sent'] ?? 1) === 1
    && ($histAgain['action'] ?? '') === 'historik', 'riktigt Wix-lead med sent=1 i Historik lämnas orört');
jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'unknown_product_ask_enabled' => 0,
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);

$okandEmail = 'nils.okand@gmail.com';
$okand = jeab_inkorg_ingest([
    'message_id' => 'okand@wix-bridge.test',
    'from' => $okandEmail,
    'from_name' => 'Nils Okand',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Fråga om hjälp',
    'body_text' => "Hej, jag vill ha en offert.\nNamn: Nils Okand\nE-post: {$okandEmail}\nAdress: Hjälpvägen 4, 177 32 Järfälla\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'preset_labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'offer' => '', 'confidence' => 90, 'question' => ''],
]);
wb(!empty($okand['ok']), 'lead utan produkt tas emot');
jeab_auto_on_message((string) ($okand['id'] ?? ''));
$okandRems = $remindersFor($okandEmail);
$okandOwner = (string) (jeab_inkorg_unknown_lead_owner()[1] ?? '');
wb(count($okandRems) === 1
    && str_contains((string) ($okandRems[0]['title'] ?? ''), 'okänd produkt')
    && strcasecmp((string) ($okandRems[0]['assignee'] ?? ''), $okandOwner) === 0
    && mb_strtolower((string) ($okandRems[0]['email'] ?? '')) === $okandEmail, 'lead utan produkt får påminnelse okänd produkt hos ' . $okandOwner . ', fick ' . count($okandRems) . ' st ' . (string) ($okandRems[0]['title'] ?? '') . ' / ' . (string) ($okandRems[0]['assignee'] ?? ''));

$before = count(jeab_list_reminders(jeab_store(), true));
$form = $ingest('kontakt', 'luis.form@gmail.com', 'Luis Carvajal', 'JEAB Kontakt Formulär', $kontakt['body']);
$formAuto = jeab_auto_on_message((string) ($form['id'] ?? ''));
$formThread = $thread((string) ($form['thread_id'] ?? ''));
$after = count(jeab_list_reminders(jeab_store(), true));
wb((string) ($formThread['status'] ?? '') === 'open' && $after === $before && ($formAuto['action'] ?? '') !== 'hold', 'Kontakt Formulär utan produkt stannar i inkorgen utan påminnelse');
wb($GLOBALS['_SENT'] === [], 'bron skickade inget');

echo "wix bridge lead: ok\n";
