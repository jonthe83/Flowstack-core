<?php

declare(strict_types=1);

/**
 * Påminnelse som inbox.threads ser via e-post räknas inte som fastnat lead.
 * inbox.review_list är bara läsning och har ingen kundtext.
 */

$tmp = sys_get_temp_dir() . '/jeab-review-list-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function rl(bool $ok, string $m): void
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

    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g', 'message_id' => 'm@x'];
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

$box = 'kontakt@jarfalla.example.test';
$since = '2026-10-20T00:00:00+02:00';
$secretName = 'Maja Hemlig';
$secretMail = 'vera.match@gmail.com';
$secretPhone = '070-555 31 31';
$secretStreet = 'Hemligvägen 9';
$secretPnr = '19800101-1234';
$lead = static function (string $offer = 'batteri', bool $review = false): array {
    return [
        'type' => 'Ny lead',
        'confidence' => $review ? 60 : 92,
        'intent' => 'kop',
        'offer' => $offer,
        'product' => $offer === 'batteri' ? 'batteri' : '',
        'lead' => 'eget',
        'question' => '',
        'reason' => $review ? 'Behöver granskas ' . $GLOBALS['_RL_SECRET'] : 'klassad',
        'source' => 'llm',
        'needs_review' => $review,
    ];
};
$GLOBALS['_RL_SECRET'] = $secretName . ' ' . $secretMail . ' ' . $secretPhone . ' ' . $secretStreet . ' ' . $secretPnr;
$ingest = static function (string $id, string $from, string $body, array $labels, string $at) use ($box): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@review-list.test',
        'from' => $from,
        'from_name' => 'Maja Hemlig',
        'to' => $box,
        'subject' => 'Lead ' . $id,
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'thread_key' => $id,
        'received_at' => $at,
        'preset_labels' => $labels,
    ]);
};
$body = static function (string $email): string {
    return "Namn: Maja Hemlig\nE-post: {$email}\nTelefon: 070-555 31 31\nAdress: Hemligvägen 9, 177 31 Järfälla\nPersonnummer: 19800101-1234\nBatteri\n";
};

$made = jeab_create_reminder(jeab_store(), 'Batteri', jeab_today_start(), 'redan skapad', null, [
    'email' => $secretMail,
    'customer' => $secretName,
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'lookup' => false,
]);
$rid = (int) ($made['id'] ?? 0);
rl($rid > 0, 'påminnelsen finns');
$match = $ingest('match-lead', $secretMail, $body($secretMail), $lead(), '2026-10-23T14:55:00+02:00');
$bare = $ingest('bare-lead', 'nils.bar@gmail.com', $body('nils.bar@gmail.com'), $lead(), '2026-10-23T15:00:00+02:00');
$matchTid = (string) ($match['thread_id'] ?? '');
$bareTid = (string) ($bare['thread_id'] ?? '');
$matchThread = jeab_auto_thread_by_id($matchTid);
rl(is_array($matchThread), 'matchtråden är öppen');
rl(jeab_inkorg_thread_list_reminder_for($matchThread) === $rid, 'inbox.threads-träffen är samma påminnelse');
rl(!is_array(jeab_lead_find_reminder($matchTid, '')), 'ärende-id ensamt ser inte påminnelsen');

$before = count(jeab_list_reminders(jeab_store(), true));
$noteBefore = (string) (jeab_get_reminder(jeab_store(), $rid)['note'] ?? '');
$dry = jeab_inkorg_hook(['action' => 'inbox.stuck_leads', 'since' => $since]);
rl(!in_array($matchTid, (array) ($dry['threads'] ?? []), true), 'tråd med påminnelse är inte fast');
rl(in_array($bareTid, (array) ($dry['threads'] ?? []), true), 'tråd utan påminnelse listas');
$link = null;
foreach ((array) ($dry['links'] ?? []) as $row) {
    if ((string) ($row['id'] ?? '') === $matchTid) {
        $link = $row;
    }
}
rl(is_array($link) && (int) ($link['reminder_id'] ?? 0) === $rid && (int) ($link['linked'] ?? 1) === 0, 'dry loggar länken utan att skriva');
rl((string) (jeab_get_reminder(jeab_store(), $rid)['note'] ?? '') === $noteBefore && count(jeab_list_reminders(jeab_store(), true)) === $before && count($GLOBALS['_SENT']) === 0, 'dry skapar ingen påminnelse och skickar inget');
rl(!str_contains((string) json_encode($dry, JSON_UNESCAPED_UNICODE), '@') && !str_contains((string) json_encode($dry, JSON_UNESCAPED_UNICODE), $secretName), 'stuck-svaret har ingen kunddata');

$sentBefore = count($GLOBALS['_SENT']);
$wet = jeab_inkorg_hook(['action' => 'inbox.stuck_leads', 'since' => $since, 'dry' => 0, 'thread' => $matchTid]);
$noteAfter = (string) (jeab_get_reminder(jeab_store(), $rid)['note'] ?? '');
rl(($wet['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === $sentBefore && count(jeab_list_reminders(jeab_store(), true)) === $before, 'dry=0 skickar inte och skapar ingen andra påminnelse');
rl(str_contains($noteAfter, $matchTid) && ($wet['links'][0]['linked'] ?? 0) === 1, 'dry=0 länkar den funna påminnelsen');
$wetAgain = jeab_inkorg_hook(['action' => 'inbox.stuck_leads', 'since' => $since, 'dry' => 0, 'thread' => $matchTid]);
rl(($wetAgain['sent'] ?? 1) === 0 && ($wetAgain['threads'] ?? ['x']) === [] && count($GLOBALS['_SENT']) === $sentBefore && count(jeab_list_reminders(jeab_store(), true)) === $before, 'en andra körning rör inte tråden');

$own = $ingest('review-own', 'disa.own@gmail.com', $body('disa.own@gmail.com'), $lead('batteri', true), '2026-10-23T16:00:00+02:00');
jeab_inkorg_thread_write((string) ($own['thread_id'] ?? ''), static function (array $row): array {
    $row['assignee'] = 'SamL';

    return $row;
});
$boughtLabels = $lead('', true);
$boughtLabels['lead'] = 'kopt';
$boughtLabels['product'] = '';
$boughtLabels['offer'] = '';
$bought = $ingest('review-bought', 'kim.bought@gmail.com', "Namn: Kim Kop\nE-post: kim.bought@gmail.com\nHej\n", $boughtLabels, '2026-10-23T16:10:00+02:00');
$supportLabels = $lead('', true);
$supportLabels['type'] = 'Support';
$supportLabels['intent'] = 'support';
$support = $ingest('review-support', 'sia.support@gmail.com', "Namn: Sia Support\nE-post: sia.support@gmail.com\nFel på anläggningen\n", $supportLabels, '2026-10-23T16:20:00+02:00');
$quoted = $ingest('review-quoted', 'quo.quoted@gmail.com', $body('quo.quoted@gmail.com'), $lead('batteri', true), '2026-10-23T16:30:00+02:00');
jeab_inkorg_add_draft((string) ($quoted['thread_id'] ?? ''), ['kind' => 'note', 'body' => 'Offert (Batteri) skickad'], 'test');
$old = $ingest('review-old', 'ove.old@gmail.com', "Namn: Ove Old\nE-post: ove.old@gmail.com\nHej\n", $lead('', true), '2026-10-01T08:00:00+02:00');
$closed = $ingest('review-closed', 'cia.closed@gmail.com', "Namn: Cia Closed\nE-post: cia.closed@gmail.com\nHej\n", $lead('', true), '2026-10-23T16:40:00+02:00');
jeab_inkorg_thread_write((string) ($closed['thread_id'] ?? ''), static function (array $row): array {
    $row['status'] = 'closed';

    return $row;
});
$openLead = $ingest('review-open', 'open.lead@gmail.com', $body('open.lead@gmail.com'), $lead(), '2026-10-23T16:50:00+02:00');
$linkedReview = $ingest('review-linked', $secretMail, $body($secretMail), $lead('batteri', true), '2026-10-23T17:00:00+02:00');

$remBefore = count(jeab_list_reminders(jeab_store(), true));
$sentNow = count($GLOBALS['_SENT']);
$badSince = jeab_inkorg_hook(['action' => 'inbox.review_list', 'since' => 'inte-ett-datum']);
rl(($badSince['ok'] ?? true) === false && ($badSince['sent'] ?? 1) === 0, 'ogiltigt since nekas');
$list = jeab_inkorg_hook(['action' => 'inbox.review_list', 'since' => $since]);
rl(($list['ok'] ?? false) === true && ($list['sent'] ?? 1) === 0 && ($list['total'] ?? -1) === count((array) ($list['threads'] ?? [])), 'listan är läsning och total är antalet');
$by = [];
foreach ((array) ($list['threads'] ?? []) as $row) {
    $by[(string) ($row['id'] ?? '')] = $row;
}
$ownTid = (string) ($own['thread_id'] ?? '');
$boughtTid = (string) ($bought['thread_id'] ?? '');
$supportTid = (string) ($support['thread_id'] ?? '');
$quotedTid = (string) ($quoted['thread_id'] ?? '');
rl(isset($by[$ownTid]) && ($by[$ownTid]['source'] ?? '') === 'own' && ($by[$ownTid]['type'] ?? '') === 'Ny lead' && ($by[$ownTid]['product'] ?? '') === 'batteri' && ($by[$ownTid]['owner'] ?? '') !== '' && ($by[$ownTid]['confidence'] ?? 0) === 60 && ($by[$ownTid]['label_source'] ?? '') === 'llm' && ($by[$ownTid]['intent'] ?? '') === 'kop' && ($by[$ownTid]['mailbox'] ?? '') === 'jarfalla' && ($by[$ownTid]['quoted'] ?? '') === 'n' && ($by[$ownTid]['reminder'] ?? '') === 'n', 'eget lead i granskning får fälten');
rl(($by[$boughtTid]['source'] ?? '') === 'bought', 'köpt lead är bought');
rl(($by[$supportTid]['source'] ?? '') === 'support', 'support är support');
rl(($by[$quotedTid]['quoted'] ?? '') === 'y', 'offert i ärendet är y');
rl(($by[(string) ($linkedReview['thread_id'] ?? '')]['reminder'] ?? '') === 'y', 'samma påminnelseträff som inbox.threads är y');
rl(!isset($by[(string) ($old['thread_id'] ?? '')]) && !isset($by[(string) ($closed['thread_id'] ?? '')]) && !isset($by[(string) ($openLead['thread_id'] ?? '')]), 'gammalt, stängt och färdigklassat lead listas inte');
$blob = (string) json_encode($list, JSON_UNESCAPED_UNICODE);
rl(!str_contains($blob, '@') && !str_contains($blob, $secretName) && !str_contains($blob, $secretPhone) && !str_contains($blob, $secretStreet) && !str_contains($blob, $secretPnr) && !str_contains($blob, '19800101') && !str_contains($blob, 'Maja') && !str_contains($blob, 'Hemlig'), 'granskningslistan har ingen kunddata');
rl(str_contains((string) ($by[$ownTid]['reason'] ?? ''), 'Behöver granskas'), 'orsaken blir kvar utan kundtexten');
rl(count(jeab_list_reminders(jeab_store(), true)) === $remBefore && count($GLOBALS['_SENT']) === $sentNow, 'granskningslistan skriver inget');
$all = jeab_inkorg_hook(['action' => 'inbox.review_list']);
rl(($all['total'] ?? 0) >= ($list['total'] ?? 0) && isset(((array) array_column((array) ($all['threads'] ?? []), 'id', 'id'))[(string) ($old['thread_id'] ?? '')]), 'utan since ingår det äldre ärendet');

echo "review list: ok\n";
