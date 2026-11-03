<?php

declare(strict_types=1);

/** Samma ärende får en andra offert när produkten är en annan. Inget riktigt mejl. */

$tmp = sys_get_temp_dir() . '/jeab-quote-product-' . bin2hex(random_bytes(4));
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

function pq(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = function (array $a) {
    $GLOBALS['_SENT'][] = $a;
    $person = trim((string) ($a['opts']['person_email'] ?? ''));
    $from = $person !== '' ? $person : jeab_graph_mailboxes()[$a['company']];
    return ['ok' => true, 'from' => $from, 'graph_id' => 'g-pq', 'conversation_id' => 'CV', 'message_id' => 'sent-pq@x'];
};
$GLOBALS['_SENT_BOX'] = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url) {
    if ($method === 'GET' && str_contains($url, '/mailFolders/sentitems/messages')) {
        return ['code' => 200, 'body' => ['value' => $GLOBALS['_SENT_BOX']]];
    }
    return ['code' => 404, 'body' => []];
};
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['answer' => 'Ja, det ingår i offerten.']);
@mkdir(jeab_data_dir() . '/secrets', 0775, true);
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test']));
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'ink_product']));

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode('%PDF-1.4 ladd')]);
jeab_notiser_save_body('offert-batteri-jarfalla', "Hej\n\nOffert batteri.", 'test');
jeab_notiser_save_body('offert-laddbox-jarfalla', "Hej\n\nOffert elbilsladdare.", 'test');
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
pq(is_string($jpeg) && str_starts_with($jpeg, "\xFF\xD8"), 'testbilden är JPEG');
jeab_auto_pdf_save(['key' => 'team_jarfalla', 'data' => base64_encode($jpeg)]);

pq(jeab_job_person('batteri') === 'Roberto' && jeab_job_person('laddbox') === 'Elin', 'produktkartan pekar ut Roberto och Elin');
pq(jeab_inkorg_product_ui('laddbox') === 'Elbilsladdare' && jeab_inkorg_product_ui('Elbilsladdare') === 'Elbilsladdare' && jeab_inkorg_product_ui('elbilsladdare') === 'Elbilsladdare', 'gränssnittet säger Elbilsladdare');
pq(!str_contains(jeab_inkorg_product_ui('laddbox'), 'laddbox') && str_contains((string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg.php'), 'jeab_inkorg_product_ui'), 'ärendesidan visar visningsnamnet');

$ingest = function (string $id, string $from, string $fromName, string $body, string $offer): array {
    $box = jeab_graph_mailboxes()['jarfalla'];
    return jeab_inkorg_ingest([
        'message_id' => $id . '@pq.test',
        'from' => $from,
        'from_name' => $fromName,
        'to' => $box,
        'subject' => $offer === 'batteri' ? 'Batteri' : 'Elbilsladdare',
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'graph_id' => 'graph-' . $id,
        'thread_key' => 'pq-' . $id,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => $offer, 'product' => $offer, 'question' => '',
        ],
        'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass'],
    ]);
};
$body = function (string $name, string $email, string $wish): string {
    return "Hej, {$wish}\nNamn: {$name}\nE-post: {$email}\nTelefon: 070-100 20 30\nAdress: Exempelvägen 4, 176 70 Järfälla\n";
};
$saysLaddbox = function (array $res): bool {
    $visible = implode("\n", [
        (string) ($res['why'] ?? ''),
        (string) ($res['template'] ?? ''),
        (string) ($res['body'] ?? ''),
        (string) ($res['subject'] ?? ''),
        (string) ($res['sender'] ?? ''),
        (string) ($res['from'] ?? ''),
    ]);
    return preg_match('/laddbox/iu', $visible) === 1;
};

$miraEmail = 'mira.qvist.pq@gmail.com';
$mira = $ingest('mira', $miraEmail, 'Mira Qvist', $body('Mira Qvist', $miraEmail, 'jag vill ha ett batteri.'), 'batteri');
$miraTid = (string) $mira['thread_id'];
$before = count($GLOBALS['_SENT']);
$first = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $miraTid, 'dry' => 0]);
pq(($first['ok'] ?? false) === true && ($first['from'] ?? '') === 'saljare3@jarfalla.example.test' && count($GLOBALS['_SENT']) === $before + 1, 'första batteriofferten går från Roberto');
$mark = jeab_inkorg_thread_meta($miraTid)['quote_sent'] ?? [];
pq(($mark['product'] ?? '') === 'batteri' && in_array('batteri', (array) ($mark['products'] ?? []), true), 'markören sparar produkten');

$before = count($GLOBALS['_SENT']);
$same = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $miraTid, 'dry' => 0]);
$sameForce = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $miraTid, 'dry' => 1, 'product' => 'batteri', 'force_product' => 1]);
pq(($same['action'] ?? '') === 'hold' && str_contains((string) ($same['why'] ?? ''), 'redan skickad') && ($sameForce['action'] ?? '') === 'hold' && str_contains((string) ($sameForce['why'] ?? ''), 'redan skickad') && count($GLOBALS['_SENT']) === $before && !array_key_exists('body', $same) && !$saysLaddbox($same) && !$saysLaddbox($sameForce), 'samma produkt stoppas');

$before = count($GLOBALS['_SENT']);
$noForce = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $miraTid, 'dry' => 0, 'product' => 'laddbox']);
pq(($noForce['action'] ?? '') === 'hold' && str_contains((string) ($noForce['why'] ?? ''), 'redan skickad') && count($GLOBALS['_SENT']) === $before && !array_key_exists('body', $noForce) && ($noForce['sender'] ?? '') !== 'saljare4@jarfalla.example.test', 'annan produkt utan force_product följer klassningen');

$noaEmail = 'noa.ek.pq@gmail.com';
$noa = $ingest('noa', $noaEmail, 'Noa Ek', $body('Noa Ek', $noaEmail, 'jag vill ha ett batteri.'), 'batteri');
$noaPlain = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $noa['thread_id'], 'dry' => 1, 'product' => 'laddbox']);
pq(($noaPlain['ok'] ?? false) === true && ($noaPlain['template'] ?? '') === 'Batteri' && ($noaPlain['sender'] ?? '') === 'saljare3@jarfalla.example.test' && !$saysLaddbox($noaPlain), 'utan force_product skickas den klassade produkten');

$before = count($GLOBALS['_SENT']);
$otherDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $miraTid, 'dry' => 1, 'product' => 'laddbox', 'force_product' => '1']);
pq(($otherDry['ok'] ?? false) === true && ($otherDry['dry'] ?? 0) === 1 && ($otherDry['template'] ?? '') === 'Elbilsladdare' && ($otherDry['sender'] ?? '') === 'saljare4@jarfalla.example.test' && ($otherDry['recipient'] ?? '') === $miraEmail && count($GLOBALS['_SENT']) === $before && !$saysLaddbox($otherDry), 'annan produkt förhandsvisas från Elin');
$other = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $miraTid, 'dry' => 0, 'product' => 'laddbox', 'force_product' => 1]);
pq(($other['ok'] ?? false) === true && ($other['action'] ?? '') === 'quoted' && ($other['from'] ?? '') === 'saljare4@jarfalla.example.test' && count($GLOBALS['_SENT']) === $before + 1 && !$saysLaddbox($other), 'annan produkt skickas från Elin');
$notes = '';
foreach ((array) (jeab_auto_thread_by_id($miraTid)['drafts'] ?? []) as $d) {
    if (($d['kind'] ?? '') === 'note') {
        $notes .= (string) ($d['body'] ?? '') . "\n";
    }
}
pq(str_contains($notes, 'Offert (Elbilsladdare) skickad') && str_contains($notes, 'saljare4@jarfalla.example.test') && !preg_match('/laddbox/iu', $notes), 'anteckningen säger Elbilsladdare');
$againBox = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $miraTid, 'dry' => 0, 'product' => 'laddbox', 'force_product' => 1]);
pq(($againBox['action'] ?? '') === 'hold' && str_contains((string) ($againBox['why'] ?? ''), 'redan skickad') && count($GLOBALS['_SENT']) === $before + 1, 'samma Elbilsladdare stoppas andra gången');
$products = (array) (jeab_inkorg_thread_meta($miraTid)['quote_sent']['products'] ?? []);
pq(in_array('batteri', $products, true) && in_array('laddbox', $products, true), 'båda produkterna ligger kvar på markören');

$loveEmail = 'love.berg.pq@gmail.com';
$love = $ingest('love', $loveEmail, 'Love Berg', $body('Love Berg', $loveEmail, 'jag vill ha ett batteri.'), 'batteri');
$loveTid = (string) $love['thread_id'];
jeab_inkorg_thread_write($loveTid, static function (array $t): array {
    $t['quote_sent'] = ['source' => 'Skickat', 'date' => '2026-10-01 09:00', 'at' => '2026-10-01T07:00:00+00:00'];
    return $t;
});
$stored = jeab_inkorg_thread_meta($loveTid)['quote_sent'] ?? [];
pq(!isset($stored['product']) && !isset($stored['products']), 'den gamla markören saknar produkt');
$legacyMark = jeab_auto_quote_sent_marker(jeab_auto_thread_by_id($loveTid), 'batteri');
$legacyOther = jeab_auto_quote_sent_marker(jeab_auto_thread_by_id($loveTid), 'laddbox');
pq(!empty($legacyMark['hit']) && str_contains((string) $legacyMark['why'], 'redan skickad') && empty($legacyOther['hit']), 'gammal markör gäller den klassade produkten');
$before = count($GLOBALS['_SENT']);
$legacyBlock = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $loveTid, 'dry' => 1]);
pq(($legacyBlock['action'] ?? '') === 'hold' && str_contains((string) ($legacyBlock['why'] ?? ''), 'redan skickad') && count($GLOBALS['_SENT']) === $before && !$saysLaddbox($legacyBlock), 'gammal markör stoppar den klassade produkten');
$legacyDry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $loveTid, 'dry' => 1, 'product' => 'laddbox', 'force_product' => 1]);
pq(($legacyDry['ok'] ?? false) === true && ($legacyDry['template'] ?? '') === 'Elbilsladdare' && ($legacyDry['sender'] ?? '') === 'saljare4@jarfalla.example.test' && !$saysLaddbox($legacyDry), 'gammal markör släpper en annan produkt');
$legacySend = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $loveTid, 'dry' => 0, 'product' => 'laddbox', 'force_product' => 1]);
pq(($legacySend['ok'] ?? false) === true && ($legacySend['from'] ?? '') === 'saljare4@jarfalla.example.test' && count($GLOBALS['_SENT']) === $before + 1, 'gammal markör låter den andra produkten skickas');
$afterLegacy = (array) (jeab_inkorg_thread_meta($loveTid)['quote_sent']['products'] ?? []);
pq(in_array('batteri', $afterLegacy, true) && in_array('laddbox', $afterLegacy, true), 'den klassade produkten blir kvar när den nya sparas');
$legacyAgain = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $loveTid, 'dry' => 1]);
pq(($legacyAgain['action'] ?? '') === 'hold' && str_contains((string) ($legacyAgain['why'] ?? ''), 'redan skickad'), 'batteri är fortfarande stoppat');

$siriEmail = 'siri.dahl.pq@gmail.com';
$siri = $ingest('siri', $siriEmail, 'Siri Dahl', $body('Siri Dahl', $siriEmail, 'jag vill ha en elbilsladdare.'), 'laddbox');
$siriTid = (string) $siri['thread_id'];
$before = count($GLOBALS['_SENT']);
$siriSent = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $siriTid, 'dry' => 0]);
pq(($siriSent['from'] ?? '') === 'saljare4@jarfalla.example.test' && count($GLOBALS['_SENT']) === $before + 1, 'klassad Elbilsladdare går från Elin');
$siriAgain = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $siriTid, 'dry' => 1, 'product' => 'laddbox', 'force_product' => 1]);
pq(($siriAgain['action'] ?? '') === 'hold' && str_contains((string) ($siriAgain['why'] ?? ''), 'redan skickad') && !$saysLaddbox($siriAgain), 'samma Elbilsladdare stoppas även med force_product');

echo "inkorg quote product: ok\n";
