<?php

declare(strict_types=1);

/** Bara samma e-post och samma produktfamilj de senaste 30 dagarna stoppar en offert. */

$tmp = sys_get_temp_dir() . '/jeab-offer-one-rule-' . bin2hex(random_bytes(4));
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

function one(bool $ok, string $m): void
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

    return ['ok' => true, 'from' => 'demo@example.test', 'graph_id' => 'g-one', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (): array {
    return ['code' => 200, 'body' => ['value' => []]];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => 'bara-den-har@gmail.com',
    'auto_send_per_hour' => 1,
    'auto_send_per_day' => 1,
    'tpl' => jeab_auto_quote_seed(),
]);
foreach (['batteri_jarfalla', 'laddbox_jarfalla', 'sol_jarfalla'] as $key) {
    jeab_auto_pdf_save(['key' => $key, 'data' => base64_encode('%PDF-1.4 ' . $key)]);
}
jeab_notiser_save_body('offert-batteri-jarfalla', "Hej\n\nOffert batteri.", 'test');
jeab_notiser_save_body('offert-laddbox-jarfalla', "Hej\n\nOffert laddbox.", 'test');
jeab_notiser_save_body('offert-sol-jarfalla-eget', "Hej\n\nSol eget.", 'test');
jeab_notiser_save_body('offert-sol-jarfalla-kopt', "Hej\n\nSol köpt.", 'test');

$in = static function (string $id, string $email, string $offer, string $phone): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@one-rule.test',
        'from' => $email,
        'from_name' => 'Nora Regel',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Offert',
        'body_text' => "Namn: Nora Regel\nE-post: {$email}\nTelefon: {$phone}\nProdukt: {$offer}\n",
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'thread_key' => 'one-' . $id,
        'preset_labels' => [
            'type' => 'Ny lead',
            'intent' => 'kop',
            'confidence' => 40,
            'offer' => $offer,
            'product' => $offer,
            'needs_review' => false,
        ],
    ]);
};
$thread = static function (string $tid): array {
    $row = jeab_auto_thread_by_id($tid);

    return is_array($row) ? $row : [];
};
$mark = static function (string $tid, string $product, int $ageDays): void {
    jeab_inkorg_thread_write($tid, static function (array $t) use ($product, $ageDays): array {
        $t['quote_sent'] = [
            'source' => 'Skickat',
            'date' => date('Y-m-d H:i', time() - $ageDays * 86400),
            'product' => $product,
        ];
        $t['status'] = 'closed';

        return $t;
    });
};
$set = ['quote_enabled' => false];

$oldSol = $in('old-sol', 'mira.one@gmail.com', 'sol+batteri', '0703001001');
$mark((string) $oldSol['thread_id'], 'sol+batteri', 5);
$freshSol = $in('fresh-sol', 'mira.one@gmail.com', 'sol', '0703001002');
$freshSolThread = $thread((string) $freshSol['thread_id']);
$blocked = jeab_auto_offer_window_for($freshSolThread, (array) ($freshSolThread['first'] ?? []), $set, 'sol');
one(($blocked['action'] ?? '') === 'blocked' && str_contains((string) ($blocked['note'] ?? ''), 'skickande av offert har stoppats pga. att offert på samma produkt skickats ut datum '), 'sol stoppas av sol+batteri på samma e-post, fick ' . (string) ($blocked['action'] ?? ''));

$otherMail = $in('other-mail', 'annan.one@gmail.com', 'sol', '0703001001');
$otherThread = $thread((string) $otherMail['thread_id']);
$otherWindow = jeab_auto_offer_window_for($otherThread, (array) ($otherThread['first'] ?? []), $set, 'sol');
one(($otherWindow['action'] ?? '') === 'send', 'annan e-post stoppas inte, även med samma telefon');

$oldBox = $in('old-box', 'bo.one@gmail.com', 'elbilsladdare', '0703002001');
$mark((string) $oldBox['thread_id'], 'elbilsladdare', 3);
$freshBox = $in('fresh-box', 'bo.one@gmail.com', 'laddbox', '0703002002');
$boxThread = $thread((string) $freshBox['thread_id']);
$boxWindow = jeab_auto_offer_window_for($boxThread, (array) ($boxThread['first'] ?? []), $set, 'laddbox');
one(($boxWindow['action'] ?? '') === 'blocked', 'laddbox stoppas av elbilsladdare på samma e-post');

$oldBatt = $in('old-batt', 'bea.one@gmail.com', 'batteri', '0703003001');
$mark((string) $oldBatt['thread_id'], 'batteri', 2);
$freshBatt = $in('fresh-batt', 'bea.one@gmail.com', 'batteri', '0703003002');
$battThread = $thread((string) $freshBatt['thread_id']);
$battWindow = jeab_auto_offer_window_for($battThread, (array) ($battThread['first'] ?? []), $set, 'batteri');
one(($battWindow['action'] ?? '') === 'blocked', 'batteri stoppas av batteri på samma e-post');
$cross = jeab_auto_offer_window_for($battThread, (array) ($battThread['first'] ?? []), $set, 'laddbox');
one(($cross['action'] ?? '') === 'send', 'en annan familj stoppas inte');

$stale = $in('stale', 'nol.one@gmail.com', 'sol', '0703004001');
$mark((string) $stale['thread_id'], 'sol', 40);
$after = $in('after', 'nol.one@gmail.com', 'sol+batteri', '0703004002');
$afterThread = $thread((string) $after['thread_id']);
$oldWindow = jeab_auto_offer_window_for($afterThread, (array) ($afterThread['first'] ?? []), $set, 'sol+batteri');
one(($oldWindow['action'] ?? '') === 'send', 'offert äldre än 30 dagar stoppar inte');

$before = count($GLOBALS['_SENT']);
$stopped = jeab_auto_on_message((string) $freshSol['id']);
$stoppedThread = $thread((string) $freshSol['thread_id']);
$note = '';
foreach ((array) ($stoppedThread['drafts'] ?? []) as $draft) {
    if (is_array($draft)) {
        $note .= (string) ($draft['body'] ?? '') . "\n";
    }
}
one(
    ($stopped['action'] ?? '') === 'hold'
    && ($stopped['sent'] ?? 1) === 0
    && str_contains($note, (string) ($blocked['note'] ?? 'saknas'))
    && ($stoppedThread['status'] ?? '') === 'closed'
    && count($GLOBALS['_SENT']) === $before,
    'stoppet antecknar, stänger och skickar inte, fick ' . (string) ($stopped['action'] ?? '') . ' ' . (string) ($stopped['why'] ?? '')
);
$remHit = false;
foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
    if (mb_strtolower((string) ($rem['email'] ?? '')) === 'mira.one@gmail.com' && str_contains((string) ($rem['note'] ?? ''), (string) ($blocked['note'] ?? 'saknas'))) {
        $remHit = true;
    }
}
one($remHit, 'påminnelsen har stoppraden');

$open = $in('open-batt', 'ny.one@gmail.com', 'batteri', '0703005001');
$sent = jeab_auto_on_message((string) $open['id']);
one(($sent['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $before + 1, 'avstängt utskick, tak och osäkerhet stoppar inte, fick ' . (string) ($sent['action'] ?? '') . ' ' . (string) ($sent['why'] ?? ''));
$again = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => (string) $open['thread_id'], 'dry' => 0, 'confirm' => 'send', 'product' => 'batteri']);
one(($again['error'] ?? '') === 'already_out' && (int) ($again['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === $before + 1, 'samma ärende skickas inte en gång till');

$malmo = jeab_inkorg_ingest([
    'message_id' => 'malmo@one-rule.test',
    'from' => 'malmo.one@gmail.com',
    'from_name' => 'Malmö Kund',
    'to' => 'kontakt@malmo.example.test',
    'subject' => 'Offert',
    'body_text' => "Namn: Malmö Kund\nE-post: malmo.one@gmail.com\nTelefon: 0703006001\nProdukt: batteri\n",
    'source' => 'graph-malmo',
    'graph_mailbox' => 'kontakt@malmo.example.test',
    'preset_labels' => [
        'type' => 'Ny lead', 'intent' => 'kop', 'confidence' => 95, 'offer' => 'batteri', 'product' => 'batteri',
    ],
]);
$malmoAuto = jeab_auto_on_message((string) $malmo['id']);
one((int) ($malmoAuto['sent'] ?? 0) === 0 && str_contains((string) ($malmoAuto['why'] ?? ''), 'Help Scout') && count($GLOBALS['_SENT']) === $before + 1, 'Malmö lämnas utanför Järfällas autoflöde, fick ' . (string) ($malmoAuto['action'] ?? '') . ' ' . (string) ($malmoAuto['why'] ?? '') . ' sent=' . count($GLOBALS['_SENT']));

$dryMis = jeab_inkorg_hook(['action' => 'inbox.offer_send_one', 'thread_id' => (string) $open['thread_id'], 'product' => 'laddbox']);
one(!in_array('product_mismatch', (array) ($dryMis['blockers'] ?? []), true) && (int) ($dryMis['locked'] ?? 1) === 0, 'offer_send_one låser inte och mismatch stoppar inte');

echo "Alla en-regel-tester OK\n";
