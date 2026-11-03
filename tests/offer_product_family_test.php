<?php

declare(strict_types=1);

/** sol och sol+batteri är samma offert. elbilsladdare och laddbox är samma. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-offer-family-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function of(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (): array {
    $GLOBALS['_SENT'][] = 1;

    return ['ok' => true, 'from' => 'demo@example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (): array {
    return ['code' => 200, 'body' => ['value' => []]];
};

foreach (['sol', 'sol+batteri', 'Sol+batteri', 'sol + batteri', 'solceller och batteri'] as $raw) {
    of(jeab_offer_product_family($raw) === 'sol', $raw . ' är solfamiljen, fick ' . jeab_offer_product_family($raw));
}
foreach (['elbilsladdare', 'Elbilsladdare', 'laddbox', 'laddare'] as $raw) {
    of(jeab_offer_product_family($raw) === 'laddbox', $raw . ' är laddboxfamiljen, fick ' . jeab_offer_product_family($raw));
}
of(jeab_offer_product_family('batteri') === 'batteri', 'batteri är egen familj');
of(jeab_offer_product_family('batteri till solceller') === '', 'fri text om batteri till solceller är inte ett alias');
of(jeab_auto_quote_offer_key('sol+batteri') === 'sol', 'nyckeln för sol+batteri är sol');
of(jeab_auto_quote_offer_key('elbilsladdare') === 'laddbox', 'nyckeln för elbilsladdare är laddbox');
of(jeab_auto_quote_offer_key('batteri till solceller') === 'sol', 'fri text läses som förut');
of(jeab_auto_offer_mail('sol+batteri') === 'sol' && jeab_auto_offer_mail('sol') === 'sol', 'sol och sol+batteri tar solmallen');
of(jeab_auto_offer_mail('elbilsladdare') === 'laddbox' && jeab_auto_offer_mail('laddbox') === 'laddbox', 'elbilsladdare tar laddboxmallen');
of(jeab_auto_offer_mail('batteri') === 'batteri', 'batteri tar batterimallen');
of(jeab_notiser_quote_notice_id(jeab_auto_offer_mail('sol+batteri'), 'jarfalla', '', false) === 'offert-sol-jarfalla', 'solmallens id är oförändrat');
of(jeab_notiser_quote_notice_id(jeab_auto_offer_mail('batteri'), 'jarfalla', '', false) === 'offert-batteri-jarfalla', 'batterimallens id är oförändrat');
of(jeab_notiser_quote_notice_id(jeab_auto_offer_mail('elbilsladdare'), 'jarfalla', '', false) === 'offert-laddbox-jarfalla', 'laddboxmallens id är oförändrat');
of(jeab_quote_note_matches_offer('Offert (Sol+batteri) skickad', 'sol') && !jeab_quote_note_matches_offer('Offert (Sol+batteri) skickad', 'batteri'), 'anteckning om sol+batteri är sol, inte batteri');
of(jeab_quote_note_matches_offer('Offert (Elbilsladdare) skickad', 'laddbox') && !jeab_quote_note_matches_offer('Offert (Elbilsladdare) skickad', 'sol'), 'anteckning om elbilsladdare är laddbox');

$in = static function (string $id, string $email, string $offer, string $classified = '', string $phone = '0701112288'): array {
    $labels = [
        'type' => 'Ny lead',
        'intent' => 'kop',
        'confidence' => 95,
        'offer' => $offer,
        'product' => $offer,
        'needs_review' => false,
    ];
    if ($classified !== '') {
        $labels['classified_offer'] = $classified;
    }
    return jeab_inkorg_ingest([
        'message_id' => $id . '@family.test',
        'from' => $email,
        'from_name' => 'Nora Familj',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Offert',
        'body_text' => "Namn: Nora Familj\nE-post: {$email}\nTelefon: {$phone}\nProdukt: {$offer}\n",
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'preset_labels' => $labels,
    ]);
};
$thread = static function (string $tid): array {
    $row = jeab_auto_thread_by_id($tid);

    return is_array($row) ? $row : [];
};

$sol = $in('sol', 'nora.sol.family@example.com', 'batteri', 'sol+batteri');
$solTid = (string) ($sol['thread_id'] ?? '');
$solThread = $thread($solTid);
of($solThread !== [] && jeab_auto_thread_classified_offer($solThread) === 'sol', 'classified_offer sol+batteri vinner och blir sol, fick ' . jeab_auto_thread_classified_offer($solThread));
of(jeab_quote_outbox_product_blocked($solTid, 'sol') === '', 'autoflödet släpper sol mot sol+batteri');
of(jeab_quote_outbox_product_blocked($solTid, 'sol+batteri') === '', 'autoflödet släpper sol+batteri mot klassad sol');
of(jeab_quote_outbox_product_blocked($solTid, 'batteri') === '', 'en annan produkt stoppar inte utskicket');

$box = $in('box', 'nora.box.family@example.com', 'sol', 'elbilsladdare');
$boxTid = (string) ($box['thread_id'] ?? '');
of(jeab_auto_thread_classified_offer($thread($boxTid)) === 'laddbox', 'classified_offer elbilsladdare blir laddbox');
of(jeab_quote_outbox_product_blocked($boxTid, 'laddbox') === '' && jeab_quote_outbox_product_blocked($boxTid, 'elbilsladdare') === '', 'laddbox och elbilsladdare matchar');
of(jeab_quote_outbox_product_blocked($boxTid, 'sol') === '', 'sol mot laddbox stoppar inte utskicket');

$plain = $in('plain', 'nora.plain.family@example.com', 'sol');
$plainTid = (string) ($plain['thread_id'] ?? '');
$asSol = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $plainTid, 'product' => 'sol']);
$asCombo = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $plainTid, 'product' => 'sol+batteri']);
$asBatt = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $plainTid, 'product' => 'batteri']);
of(!in_array('product_mismatch', (array) ($asSol['blockers'] ?? []), true) && ($asSol['error'] ?? '') !== 'product_mismatch', 'offer_send_one sol är ingen mismatch');
of(!in_array('product_mismatch', (array) ($asCombo['blockers'] ?? []), true) && ($asCombo['error'] ?? '') !== 'product_mismatch', 'offer_send_one sol+batteri är ingen mismatch');
of(($asCombo['template'] ?? '') === ($asSol['template'] ?? '') && str_starts_with((string) ($asCombo['template'] ?? ''), 'offert-sol-'), 'samma solmall, fick ' . (string) ($asCombo['template'] ?? '') . ' mot ' . (string) ($asSol['template'] ?? ''));
of(($asBatt['error'] ?? '') !== 'product_mismatch' && !in_array('product_mismatch', (array) ($asBatt['blockers'] ?? []), true), 'offer_send_one batteri är ingen mismatch');
of($GLOBALS['_SENT'] === [], 'förhandsvisningen skickade inget');

$boxPlain = $in('boxplain', 'nora.boxplain.family@example.com', 'laddbox');
$boxPlainTid = (string) ($boxPlain['thread_id'] ?? '');
$asBox = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $boxPlainTid, 'product' => 'laddbox']);
$asCar = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => $boxPlainTid, 'product' => 'elbilsladdare']);
of(!in_array('product_mismatch', (array) ($asCar['blockers'] ?? []), true) && ($asCar['template'] ?? '') === ($asBox['template'] ?? '') && str_starts_with((string) ($asCar['template'] ?? ''), 'offert-laddbox-'), 'elbilsladdare behåller laddboxmallen, fick ' . (string) ($asCar['template'] ?? ''));

$old = $in('oldsol', 'mira.family@example.com', 'sol', '', '0702223301');
jeab_inkorg_thread_write((string) ($old['thread_id'] ?? ''), static function (array $t): array {
    $t['quote_sent'] = ['source' => 'Skickat', 'date' => date('Y-m-d H:i', time() - 5 * 86400), 'product' => 'sol+batteri'];

    return $t;
});
$fresh = $in('freshsol', 'mira.family@example.com', 'sol', '', '0702223301');
$freshThread = $thread((string) ($fresh['thread_id'] ?? ''));
$freshMsg = (array) ($freshThread['first'] ?? []);
$set = ['quote_enabled' => true];
$blocked = jeab_auto_offer_window_for($freshThread, $freshMsg, $set, 'sol');
of(($blocked['action'] ?? '') === 'blocked', 'sol inom 30 dagar stoppas av tidigare sol+batteri, fick ' . (string) ($blocked['action'] ?? ''));
$blockedCar = jeab_auto_offer_window_for($freshThread, $freshMsg, $set, 'elbilsladdare');
of(($blockedCar['action'] ?? '') === 'send', 'elbilsladdare stoppas inte av en soloffert');

$oldBox = $in('oldbox', 'bo.family@example.com', 'laddbox', '', '0702223302');
jeab_inkorg_thread_write((string) ($oldBox['thread_id'] ?? ''), static function (array $t): array {
    $t['quote_sent'] = ['source' => 'Skickat', 'date' => date('Y-m-d H:i', time() - 3 * 86400), 'product' => 'elbilsladdare'];

    return $t;
});
$freshBox = $in('freshbox', 'bo.family@example.com', 'laddbox', '', '0702223302');
$freshBoxThread = $thread((string) ($freshBox['thread_id'] ?? ''));
$boxWindow = jeab_auto_offer_window_for($freshBoxThread, (array) ($freshBoxThread['first'] ?? []), $set, 'laddbox');
of(($boxWindow['action'] ?? '') === 'blocked', 'laddbox inom 30 dagar stoppas av tidigare elbilsladdare');

$stale = $in('stale', 'nol.family@example.com', 'sol', '', '0702223303');
jeab_inkorg_thread_write((string) ($stale['thread_id'] ?? ''), static function (array $t): array {
    $t['quote_sent'] = ['source' => 'Skickat', 'date' => date('Y-m-d H:i', time() - 40 * 86400), 'product' => 'sol+batteri'];

    return $t;
});
$after = $in('after', 'nol.family@example.com', 'sol+batteri', '', '0702223303');
$afterThread = $thread((string) ($after['thread_id'] ?? ''));
$oldWindow = jeab_auto_offer_window_for($afterThread, (array) ($afterThread['first'] ?? []), $set, 'sol+batteri');
of(($oldWindow['action'] ?? '') === 'send', 'offert äldre än 30 dagar stoppar inte');

echo "Alla produktfamiljstester OK\n";
