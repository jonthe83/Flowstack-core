<?php

declare(strict_types=1);

/**
 * HemSol-sidfot och Drip-adress ska inte bli Örebro.
 * leads.reparse_place listar ort och postnummer före och efter.
 * Påminnelserna 276, 277 och 279 och deras länkade kontakter får inte visa Örebro.
 * En kontakt som verkligen ligger i Örebro ändras inte.
 */

$tmp = sys_get_temp_dir() . '/jeab-lead-place-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
unset($_ENV['XAI_API_KEY'], $_SERVER['XAI_API_KEY']);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function lp(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $args): array {
    $GLOBALS['_SENT'][] = $args;

    return ['ok' => true, 'from' => 'demo@example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
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

$track = '<https://mandrillapp.com/track/click/' . str_repeat('a', 240) . '>';
lp(mb_strlen($track) > 200, 'spårningslänken är längre än 200 tecken');

$footer = "\n\n[Hemsol]\nHemSol Group AB\nOrg.nr: 559123-4567\nDrottninggatan 32\n111 51 Stockholm\n";
$hemsol = jeab_lead_customer([
    'from' => 'lead@hemsol.se',
    'from_name' => 'HemSol',
    'subject' => 'Offertförfrågan solceller - Ada Holm - Hemvägen 2, 176 70 Järfälla',
    'body' => "Namn: Ada Holm\nE-post: ada.holm@example.test\nTelefon: 070-123 45 67\nAdress: {$track}\n\nMeddelande: Vill ha solceller.{$footer}",
]);
$hemsolAddr = (string) ($hemsol['address'] ?? '');
lp(str_contains($hemsolAddr, 'Hemvägen 2'), 'HemSol tar gatan från ämnet, fick ' . $hemsolAddr);
lp(str_contains(preg_replace('/\D+/', '', $hemsolAddr) ?? '', '17670'), 'HemSol behåller 176 70, fick ' . $hemsolAddr);
lp(str_contains(mb_strtolower($hemsolAddr), 'järfälla') || str_contains(mb_strtolower($hemsolAddr), 'jarfalla'), 'HemSol behåller Järfälla, fick ' . $hemsolAddr);
lp(!str_contains(mb_strtolower($hemsolAddr), 'drottninggatan'), 'HemSol ignorerar sidfotens gata, fick ' . $hemsolAddr);
lp(!str_contains(preg_replace('/\D+/', '', $hemsolAddr) ?? '', '11151'), 'HemSol ignorerar 111 51, fick ' . $hemsolAddr);
lp(!jeab_lead_town_is_orebro($hemsolAddr), 'HemSol blir inte Örebro');

$drip = jeab_lead_customer([
    'from' => 'notifications@drip.com',
    'from_name' => 'Drip',
    'subject' => 'Ny lead',
    'body' => "Namn: Bo Drip\nE-post: bo.drip@example.test\nTelefon: 070-222 33 44\nAdress: Arlavägen 13, 177 61 järfälla {$track}\n",
]);
$dripAddr = (string) ($drip['address'] ?? '');
lp(str_contains($dripAddr, 'Arlavägen 13'), 'Drip behåller gatan, fick ' . $dripAddr);
lp(str_contains(preg_replace('/\D+/', '', $dripAddr) ?? '', '17761'), 'Drip behåller 177 61, fick ' . $dripAddr);
lp(str_contains(mb_strtolower($dripAddr), 'järfälla') || str_contains(mb_strtolower($dripAddr), 'jarfalla'), 'Drip behåller Järfälla, fick ' . $dripAddr);
lp(!str_contains(preg_replace('/\D+/', '', $dripAddr) ?? '', '70363'), 'Drip blir inte 703 63, fick ' . $dripAddr);
lp(!jeab_lead_town_is_orebro($dripAddr), 'Drip blir inte Örebro');

$wrongStreet = jeab_kontakt_find_or_create([
    'name' => 'Fel Gata',
    'email' => 'fel.gata@example.test',
    'address' => 'Arlavägen 13',
    'postal_code' => '70363',
    'city' => 'Örebro',
    'company' => 'jarfalla',
], false, false);
lp(is_array($wrongStreet) && (string) ($wrongStreet['id'] ?? '') !== '', 'Örebro-kontakt på samma gata finns');
$linked = jeab_kontakt_find_or_create([
    'name' => 'Bo Berg',
    'email' => 'bo.berg@example.test',
    'address' => 'Arlavägen 13',
    'postal_code' => '17761',
    'city' => 'Järfälla',
    'company' => 'jarfalla',
    'require_postal' => true,
], false, false);
lp(is_array($linked) && (string) ($linked['id'] ?? '') !== (string) $wrongStreet['id'], 'samma gata utan samma postnummer länkar inte');
lp((string) ($linked['postal_code'] ?? '') === '17761', 'ny kontakt behåller 17761');
lp(!jeab_lead_town_is_orebro((string) ($linked['city'] ?? '')), 'ny kontakt blir inte Örebro');

$GLOBALS['_JEAB_ADDRESS_GEOCODE'] = static function (string $street): array {
    if (str_contains(mb_strtolower($street), 'arlavägen') || str_contains(mb_strtolower($street), 'arlavagen')) {
        return [['city' => 'Örebro', 'postal' => '70363', 'score' => 95]];
    }

    return [];
};
$looked = jeab_kontakt_find_or_create([
    'name' => 'Lookup Kund',
    'email' => 'lookup.kund@example.test',
    'address' => 'Arlavägen 13',
    'postal_code' => '17761',
    'city' => '',
    'company' => 'jarfalla',
], true, false);
lp(is_array($looked) && (string) ($looked['postal_code'] ?? '') === '17761', 'uppslag skriver inte över givet postnummer, fick ' . (string) ($looked['postal_code'] ?? ''));
lp(!jeab_lead_town_is_orebro((string) ($looked['city'] ?? '')), 'uppslag skriver inte Örebro över en given adress');
unset($GLOBALS['_JEAB_ADDRESS_GEOCODE']);

$store = jeab_store();
$first = jeab_create_reminder($store, 'Tråd Kund – Solceller', jeab_today_start(), null, null, [
    'customer' => 'Tråd Kund',
    'email' => 'trad.kund@example.test',
    'address' => 'Hemvägen 2, Järfälla',
    'postal' => '17670',
    'ort' => 'Järfälla',
    'company' => 'jarfalla',
    'thread_id' => 'trad-en',
    'require_postal' => true,
]);
$second = jeab_create_reminder($store, 'Tråd Kund igen – Solceller', jeab_today_start(), null, null, [
    'customer' => 'Annan Person',
    'email' => 'annan.person@example.test',
    'address' => 'Annan gata 9, Järfälla',
    'postal' => '17760',
    'ort' => 'Järfälla',
    'company' => 'jarfalla',
    'thread_id' => 'trad-en',
    'require_postal' => true,
]);
lp((int) ($first['id'] ?? 0) > 0 && (int) ($second['id'] ?? 0) === (int) $first['id'] && !empty($second['reused']), 'samma tråd skapar ingen andra påminnelse');

$open = jeab_reminder_row($first, true);
lp(str_contains($open, '>Öppna ärendet<') && str_contains($open, 'p=inkorg') && str_contains($open, 'trad-en'), 'kortet länkar till ärendet');

$labels = [
    'type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 90,
    'intent' => 'annat', 'offer' => '', 'product' => '', 'question' => '',
];
$box = jeab_graph_mailboxes()['jarfalla'];
$cases = [
    276 => [
        'message_id' => 'hem-276@place.test',
        'from' => 'lead@hemsol.se',
        'from_name' => 'HemSol',
        'subject' => 'Offertförfrågan solceller - Ada Holm - Hemvägen 2, 176 70 Järfälla',
        'body' => "Namn: Ada Holm\nE-post: ada.holm@example.test\nTelefon: 070-123 45 67\nAdress: {$track}\n\nMeddelande: Vill ha solceller.{$footer}",
        'customer' => 'Ada Holm',
        'email' => 'ada.holm@example.test',
        'street' => 'Hemvägen 2',
    ],
    277 => [
        'message_id' => 'hem-277@place.test',
        'from' => 'lead@hemsol.se',
        'from_name' => 'HemSol',
        'subject' => 'Offertförfrågan solceller - Siv Holm - Solvägen 4, 177 40 Järfälla',
        'body' => "Namn: Siv Holm\nE-post: siv.holm@example.test\nTelefon: 070-555 66 77\nAdress: {$track}\n\nMeddelande: Vill ha solceller.{$footer}",
        'customer' => 'Siv Holm',
        'email' => 'siv.holm@example.test',
        'street' => 'Solvägen 4',
    ],
    279 => [
        'message_id' => 'drip-279@place.test',
        'from' => 'notifications@drip.com',
        'from_name' => 'Drip',
        'subject' => 'Ny lead - Bo Berg - Arlavägen 13, 177 61 Järfälla',
        'body' => "Namn: Bo Berg\nE-post: bo.berg279@example.test\nTelefon: 070-222 33 44\nAdress: Arlavägen 13, 177 61 järfälla {$track}\n",
        'customer' => 'Bo Berg',
        'email' => 'bo.berg279@example.test',
        'street' => 'Arlavägen 13',
    ],
];

$genuine = jeab_kontakt_find_or_create([
    'name' => 'Bo Akta',
    'email' => 'bo.akta@example.test',
    'address' => 'Storgatan 9',
    'postal_code' => '70222',
    'city' => 'Örebro',
    'company' => 'jarfalla',
], false, false);
lp(is_array($genuine) && jeab_lead_town_is_orebro((string) ($genuine['city'] ?? '')), 'äkta Örebro-kontakt finns');
$genuineId = (string) $genuine['id'];
$snap = static function (string $id): array {
    $row = jeab_kontakt_get($id) ?: [];

    return [
        'street' => (string) ($row['street'] ?? ''),
        'city' => (string) ($row['city'] ?? ''),
        'postal' => (string) ($row['postal_code'] ?? ''),
        'email' => function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($row) : '',
    ];
};
$genuineBefore = $snap($genuineId);
$wrongIds = [];
$threads = [];
foreach ($cases as $wantId => $case) {
    $ingested = jeab_inkorg_ingest([
        'message_id' => $case['message_id'],
        'from' => $case['from'],
        'from_name' => $case['from_name'],
        'to' => $box,
        'subject' => $case['subject'],
        'body_text' => $case['body'],
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'thread_key' => 'g-place-' . $wantId,
        'received_at' => '2026-10-20T08:00:00+02:00',
        'preset_labels' => $labels,
        'test' => true,
    ]);
    $tid = (string) ($ingested['thread_id'] ?? '');
    lp($tid !== '', 'ärende ' . $wantId . ' läses in');
    $threads[$wantId] = $tid;
    $wrong = jeab_kontakt_find_or_create([
        'name' => $case['customer'] . ' fel',
        'email' => 'fel' . $wantId . '@example.test',
        'address' => $case['street'],
        'postal_code' => '70363',
        'city' => 'Örebro',
        'company' => 'jarfalla',
    ], false, false);
    lp(is_array($wrong) && (string) ($wrong['id'] ?? '') !== '', 'fel Örebro-kontakt för ' . $wantId);
    $wrongIds[$wantId] = (string) $wrong['id'];
    $made = $store->insertReminder([
        'title' => $case['customer'] . ' – Solceller',
        'customer_name' => $case['customer'],
        'email' => $case['email'],
        'phone' => '',
        'address' => $case['street'] . ', Örebro',
        'ort' => 'Örebro',
        'postal' => '70363',
        'company' => 'jarfalla',
        'thread_id' => $tid,
        'due_at' => jeab_today_start(),
        'created_at' => jeab_now(),
        'updated_at' => jeab_now(),
    ]);
    $fromId = (int) ($made['id'] ?? 0);
    lp($fromId > 0 && $store instanceof JeabPdoStore, 'påminnelse för ' . $wantId . ' skapas');
    $store->pdo()->prepare('UPDATE reminders SET id = ?, contact_id = ? WHERE id = ?')->execute([$wantId, $wrongIds[$wantId], $fromId]);
}
$wrongBefore = [];
foreach ($wrongIds as $wantId => $cid) {
    $wrongBefore[$wantId] = $snap($cid);
}

$dry = jeab_inkorg_hook(['action' => 'leads.reparse_place', 'ids' => [276, 277, 279], 'dry' => 1]);
lp(!empty($dry['ok']) && (int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['sent'] ?? 1) === 0 && empty($dry['would_send']) && (int) ($dry['written'] ?? 1) === 0, 'dry skriver inget och skickar inget');
$dryPlaces = is_array($dry['places'] ?? null) ? $dry['places'] : [];
lp(count($dryPlaces) === 3, 'dry listar tre påminnelser');
foreach ($dryPlaces as $place) {
    $id = (int) ($place['id'] ?? 0);
    lp(isset($cases[$id]), 'dry-rad har känt id ' . $id);
    $before = is_array($place['before'] ?? null) ? $place['before'] : [];
    $after = is_array($place['after'] ?? null) ? $place['after'] : [];
    lp(jeab_lead_town_is_orebro((string) ($before['ort'] ?? '')) && jeab_lead_town_is_orebro((string) ($before['contact_ort'] ?? '')), 'dry före är Örebro för ' . $id);
    lp((string) ($before['postal'] ?? '') === '70363' && (string) ($before['contact_postal'] ?? '') === '70363', 'dry före har 70363 för ' . $id);
    lp(!jeab_lead_town_is_orebro((string) ($after['ort'] ?? '')) && !jeab_lead_town_is_orebro((string) ($after['contact_ort'] ?? '')), 'dry efter är inte Örebro för ' . $id . ' ' . json_encode($after, JSON_UNESCAPED_UNICODE));
    lp((string) ($after['postal'] ?? '') !== '70363' && (string) ($after['contact_postal'] ?? '') !== '70363', 'dry efter har inte 70363 för ' . $id);
}
lp($snap($genuineId) === $genuineBefore, 'dry rör inte den äkta Örebro-kontakten');
foreach ($wrongBefore as $wantId => $beforeSnap) {
    lp($snap($wrongIds[$wantId]) === $beforeSnap, 'dry rör inte felkontakten ' . $wantId);
}

$denied = jeab_lead_reparse_place(['ids' => [276, 277, 279], 'dry' => 0]);
lp(empty($denied['ok']) && (int) ($denied['sent'] ?? 1) === 0 && empty($denied['would_send']), 'dry=0 utan confirm skrivs inte');
lp(jeab_lead_town_is_orebro((string) (($store->getReminder(276)['ort'] ?? ''))), 'nekad körning lämnar 276');

$live = jeab_lead_reparse_place(['ids' => [276, 277, 279], 'dry' => 0, 'confirm' => 'SAM']);
lp(!empty($live['ok']) && (int) ($live['dry'] ?? 1) === 0 && (int) ($live['sent'] ?? 1) === 0 && empty($live['would_send']) && (int) ($live['written'] ?? 0) === 3, 'live rättar tre och skickar inget');
lp(trim((string) ($live['batch'] ?? '')) !== '', 'live sparar ångring');
$livePlaces = is_array($live['places'] ?? null) ? $live['places'] : [];
lp(count($livePlaces) === 3, 'live listar tre påminnelser');
foreach ([276, 277, 279] as $id) {
    $rem = $store->getReminder($id);
    lp(is_array($rem), 'påminnelse ' . $id . ' finns kvar');
    lp(!jeab_lead_town_is_orebro((string) ($rem['ort'] ?? '')), 'påminnelse ' . $id . ' visar inte Örebro, fick ' . (string) ($rem['ort'] ?? ''));
    lp((string) ($rem['postal'] ?? '') !== '70363' && (string) ($rem['postal'] ?? '') !== '', 'påminnelse ' . $id . ' har inte 70363');
    $cid = trim((string) ($rem['contact_id'] ?? ''));
    $contact = $cid !== '' ? jeab_kontakt_get($cid) : null;
    lp(is_array($contact) && !jeab_lead_town_is_orebro((string) ($contact['city'] ?? '')), 'kontakten för ' . $id . ' visar inte Örebro');
    lp((string) ($contact['postal_code'] ?? '') !== '70363', 'kontakten för ' . $id . ' har inte 70363');
    lp($cid !== $wrongIds[$id] && $cid !== $genuineId, 'påminnelse ' . $id . ' är inte kvar på en Örebro-kontakt');
    $shown = jeab_reminder_shown_customer($rem);
    lp(!jeab_lead_town_is_orebro((string) ($shown['city'] ?? '')), 'kortet för ' . $id . ' visar inte Örebro');
}
foreach ($livePlaces as $place) {
    $before = is_array($place['before'] ?? null) ? $place['before'] : [];
    $after = is_array($place['after'] ?? null) ? $place['after'] : [];
    lp(array_key_exists('ort', $before) && array_key_exists('postal', $before) && array_key_exists('contact_ort', $before) && array_key_exists('contact_postal', $before), 'live före har ort och postnummer');
    lp(array_key_exists('ort', $after) && array_key_exists('postal', $after) && array_key_exists('contact_ort', $after) && array_key_exists('contact_postal', $after), 'live efter har ort och postnummer');
    lp(jeab_lead_town_is_orebro((string) ($before['ort'] ?? '')) && !jeab_lead_town_is_orebro((string) ($after['ort'] ?? '')), 'live byter påminnelsens ort');
    lp(jeab_lead_town_is_orebro((string) ($before['contact_ort'] ?? '')) && !jeab_lead_town_is_orebro((string) ($after['contact_ort'] ?? '')), 'live byter kontaktens ort');
}
lp($snap($genuineId) === $genuineBefore, 'äkta Örebro-kontakt är oförändrad');
foreach ($wrongBefore as $wantId => $beforeSnap) {
    lp($snap($wrongIds[$wantId]) === $beforeSnap, 'fel Örebro-kontakt ' . $wantId . ' är oförändrad');
}
lp($GLOBALS['_SENT'] === [], 'hooken skickade inget');
lp(str_contains(implode("\n", jeab_auto_rule_lines()), 'leads.reparse_place'), 'regeln nämner hooken');
lp(str_contains((string) file_get_contents(dirname(__DIR__) . '/hook.php'), 'leads.reparse_place'), 'hook.php släpper in anropet');

$undo = jeab_lead_reparse_place(['undo' => 1, 'batch' => (string) $live['batch'], 'dry' => 0, 'confirm' => 'SAM']);
lp(!empty($undo['ok']) && (int) ($undo['sent'] ?? 1) === 0, 'ångring skriver tillbaka utan utskick');
$back = $store->getReminder(276);
lp(is_array($back) && jeab_lead_town_is_orebro((string) ($back['ort'] ?? '')) && (string) ($back['contact_id'] ?? '') === $wrongIds[276], 'ångring återställer 276');
lp($snap($genuineId) === $genuineBefore, 'ångring rör inte den äkta Örebro-kontakten');

$looseIn = jeab_inkorg_ingest([
    'message_id' => 'loose-280@place.test',
    'from' => 'loos.holm@example.test',
    'from_name' => 'Loos Holm',
    'to' => $box,
    'subject' => 'Offertförfrågan - Loos Holm - Lösagatan 5, 177 50 Järfälla',
    'body_text' => "Namn: Loos Holm\nE-post: loos.holm@example.test\nTelefon: 070-111 22 33\nAdress: Lösagatan 5, 177 50 Järfälla\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => $box,
    'thread_key' => 'g-place-loose',
    'received_at' => '2026-10-21T08:00:00+02:00',
    'preset_labels' => $labels,
    'test' => true,
]);
$looseTid = (string) ($looseIn['thread_id'] ?? '');
lp($looseTid !== '', 'löst ärende läses in');
$looseMade = $store->insertReminder([
    'title' => 'Loos Holm – Solceller',
    'customer_name' => 'Loos Holm',
    'email' => 'loos.holm@example.test',
    'phone' => '',
    'address' => 'Lösagatan 5, Örebro',
    'ort' => 'Örebro',
    'postal' => '70363',
    'company' => 'jarfalla',
    'note' => 'ingen länk',
    'due_at' => jeab_today_start(),
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$looseId = (int) ($looseMade['id'] ?? 0);
lp($looseId > 0, 'lös påminnelse skapas');
jeab_inkorg_thread_write($looseTid, static function (array $t) use ($looseId): array {
    $t['reminder_id'] = $looseId;

    return $t;
});
$looseRem = $store->getReminder($looseId);
lp(is_array($looseRem) && trim((string) ($looseRem['thread_id'] ?? '')) === '', 'lös påminnelse saknar thread_id');
lp(!str_contains((string) ($looseRem['note'] ?? ''), 'id='), 'lös påminnelse saknar mail-länk');
lp((int) (jeab_inkorg_thread_meta($looseTid)['reminder_id'] ?? 0) === $looseId, 'ärendet har reminder_id');

$byId = jeab_lead_reparse_place(['ids' => [$looseId], 'dry' => 1]);
lp(!empty($byId['ok']) && (string) (($byId['places'][0]['thread_id'] ?? '')) === $looseTid, 'ids hittar ärendet via reminder_id, fick ' . json_encode($byId, JSON_UNESCAPED_UNICODE));
lp(trim((string) ($store->getReminder($looseId)['thread_id'] ?? '')) === '', 'dry skriver inte thread_id');

$byThread = jeab_lead_reparse_place(['thread' => $looseTid, 'dry' => 1]);
lp(!empty($byThread['ok']) && (int) (($byThread['places'][0]['id'] ?? 0)) === $looseId, 'thread hittar påminnelsen via reminder_id, fick ' . json_encode($byThread, JSON_UNESCAPED_UNICODE));

$both = jeab_lead_reparse_place(['id' => $looseId, 'thread' => $looseTid, 'dry' => 1]);
lp(!empty($both['ok']) && count(is_array($both['places'] ?? null) ? $both['places'] : []) === 1, 'id och thread tillsammans');
lp((string) (($both['places'][0]['thread_id'] ?? '')) === $looseTid && (int) (($both['places'][0]['id'] ?? 0)) === $looseId, 'tillsammans pekar på samma par');

$liveLoose = jeab_lead_reparse_place(['ids' => [$looseId], 'thread' => $looseTid, 'dry' => 0, 'confirm' => 'SAM']);
lp(!empty($liveLoose['ok']) && (int) ($liveLoose['sent'] ?? 1) === 0 && empty($liveLoose['would_send']), 'bekräftad lös körning skickar inget');
$looseAfter = $store->getReminder($looseId);
lp(is_array($looseAfter) && trim((string) ($looseAfter['thread_id'] ?? '')) === $looseTid, 'bekräftad körning sätter thread_id');
lp(!jeab_lead_town_is_orebro((string) ($looseAfter['ort'] ?? '')), 'lös påminnelse lämnar Örebro');
$looseCard = jeab_reminder_row($looseAfter, true);
lp(str_contains($looseCard, '>Öppna ärendet<') && str_contains($looseCard, rawurlencode($looseTid)), 'Öppna ärendet använder det sparade ärendet');

$looseUndo = jeab_lead_reparse_place(['undo' => 1, 'batch' => (string) ($liveLoose['batch'] ?? ''), 'dry' => 0, 'confirm' => 'SAM']);
$looseBack = $store->getReminder($looseId);
lp(!empty($looseUndo['ok']) && is_array($looseBack) && trim((string) ($looseBack['thread_id'] ?? '')) === '', 'ångring tar bort thread_id igen');

echo "lead place reparse ok\n";
