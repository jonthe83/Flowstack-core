<?php

declare(strict_types=1);

/** inkorg.reclassify dry=0 får inte stänga öppna ärenden eller byta produkt efter offert/påminnelse. */

$tmp = sys_get_temp_dir() . '/jeab-reclassify-safe-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function rs(bool $ok, string $m): void
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

$plain = static function (string $type, string $intent, string $offer): array {
    return [
        'type' => $type,
        'intent' => $intent,
        'offer' => $offer,
        'product' => $offer,
        'confidence' => 95,
        'action' => 'svara',
        'priority' => 1,
        'needs_review' => false,
    ];
};

$in = static function (string $id, string $thread, array $msg): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@reclassify-safe.test',
        'from' => (string) $msg['from'],
        'from_name' => (string) ($msg['from_name'] ?? ''),
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => (string) $msg['subject'],
        'body_text' => (string) ($msg['body'] ?? ''),
        'received_at' => (string) ($msg['received_at'] ?? date('c')),
        'source' => (string) ($msg['source'] ?? 'graph-jarfalla'),
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'thread_key' => $thread,
        'preset_labels' => (array) $msg['labels'],
        'in_reply_to' => (string) ($msg['in_reply_to'] ?? ''),
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
$customerMsg = static function (string $tid): array {
    $row = [];
    foreach (jeab_inkorg_messages() as $message) {
        if ((string) ($message['thread_id'] ?? '') !== $tid || !is_array($message)) {
            continue;
        }
        if (str_starts_with((string) ($message['source'] ?? ''), 'outbound')) {
            continue;
        }
        $row = $message;
    }

    return $row;
};
$offerOf = static function (string $tid) use ($customerMsg): string {
    return (string) ($customerMsg($tid)['labels']['offer'] ?? '');
};
$typeOf = static function (string $tid) use ($customerMsg): string {
    return (string) ($customerMsg($tid)['labels']['type'] ?? '');
};

$kevinId = '8215eff2bba70c48';
$in('kevin', $kevinId, [
    'from' => 'kevin.magnusson@gmail.com',
    'from_name' => 'Kevin Magnusson',
    'subject' => 'Batteri till villan',
    'body' => 'Hej, jag vill ha offert på ett batteri.',
    'labels' => $plain('Ny lead', 'kop', 'batteri'),
]);
jeab_inkorg_thread_write($kevinId, static function (array $t): array {
    $t['status'] = 'open';
    $t['assignee'] = 'Roberto';
    unset($t['hook_closed']);

    return $t;
});

$fredrikId = 'g-781247eeb7bd51b9';
$in('fredrik', $fredrikId, [
    'from' => 'leads@hitta-solceller.se',
    'from_name' => 'Fredrik Karlsson',
    'subject' => 'Hitta-Solceller.se – Ny offert',
    'body' => "Namn: Fredrik Karlsson\nE-post: fredrik.karlsson@gmail.com\nVi vill ha solceller.",
    'labels' => $plain('Skräp', 'skrap', ''),
]);
jeab_inkorg_thread_write($fredrikId, static function (array $t): array {
    $t['status'] = 'open';
    unset($t['hook_closed']);

    return $t;
});

$hansId = 'g-6dbbb38b96979497';
$in('hans', $hansId, [
    'from' => 'hej@hemsol.se',
    'from_name' => 'Hans Steijen',
    'subject' => 'HemSol – offert',
    'body' => 'Vi vill ha offert på solceller till villan.',
    'labels' => $plain('Ny lead', 'kop', 'laddbox'),
]);
jeab_inkorg_thread_write($hansId, static function (array $t): array {
    $t['status'] = 'closed';
    $t['assignee'] = 'Elin';
    $t['quote_sent'] = ['source' => 'offert', 'date' => '2026-10-21', 'product' => 'laddbox'];
    $t['updated_at'] = date('c');

    return $t;
});

$remId = 'g-reminder-lock';
$in('rem', $remId, [
    'from' => 'hej@hemsol.se',
    'from_name' => 'Reminder Kund',
    'subject' => 'HemSol – påminnelse',
    'body' => 'Vi vill ha offert på solceller.',
    'labels' => $plain('Ny lead', 'kop', 'laddbox'),
]);
jeab_inkorg_thread_write($remId, static function (array $t): array {
    $t['status'] = 'closed';
    $t['assignee'] = 'Roberto';
    $t['updated_at'] = date('c');

    return $t;
});
$rem = jeab_create_reminder(jeab_store(), 'Reminder Kund – Elbilsladdare', '2026-10-22 09:00:00', 'Inkorgsärende ' . $remId, null, [
    'company' => 'jarfalla',
    'who' => 'Roberto',
]);
jeab_store()->saveReminder((int) $rem['id'], ['thread_id' => $remId]);

$omcId = 'omc40b524df1c8c0';
$in('omc', $omcId, [
    'from' => 'test.omc@example.test',
    'from_name' => 'Test Testsson',
    'subject' => 'Ny lead',
    'body' => 'Test av batteri.',
    'source' => 'omtago',
    'labels' => $plain('Ny lead', 'kop', 'batteri'),
]);
$in('omc-svar', $omcId, [
    'from' => 'saljare4@jarfalla.example.test',
    'from_name' => 'Elin',
    'subject' => 'Re: Ny lead',
    'body' => 'Vi återkommer.',
    'source' => 'outbound-graph',
    'labels' => $plain('Oklassad', '', ''),
    'in_reply_to' => 'omc@reclassify-safe.test',
]);
jeab_inkorg_thread_write($omcId, static function (array $t): array {
    $t['status'] = 'open';
    $t['lead_source'] = 'omtago';
    unset($t['hook_closed']);

    return $t;
});

$kevin = $thread($kevinId);
$fredrik = $thread($fredrikId);
$hans = $thread($hansId);
$reminderThread = $thread($remId);
$omc = $thread($omcId);
rs(($kevin['id'] ?? '') === $kevinId && ($kevin['status'] ?? '') === 'open' && ($kevin['assignee'] ?? '') === 'Roberto', 'Kevin ligger öppen på Roberto');
rs(($fredrik['status'] ?? '') === 'open' && $typeOf($fredrikId) === 'Skräp', 'Fredrik ligger öppen som Skräp');
rs(($hans['status'] ?? '') === 'closed' && $offerOf($hansId) === 'laddbox' && ($hans['assignee'] ?? '') === 'Elin', 'Hans är stängd med laddbox och Elin');
rs(jeab_inkorg_quote_sent_in_thread($hans), 'Hans har skickad offert');
rs(($reminderThread['status'] ?? '') === 'closed' && isset(jeab_inkorg_klassning_reclassify_reminder_ids()[$remId]), 'påminnelsen sitter på ärendet');

$kevinFace = jeab_inkorg_klassning_face($kevin);
$kevinDec = jeab_inkorg_klassning_decide($kevin, $kevinFace);
rs(is_array($kevinDec) && ($kevinDec['status'] ?? '') === 'closed', 'beslutet ville stänga Kevin');
$fredrikFace = jeab_inkorg_klassning_face($fredrik);
$fredrikDec = jeab_inkorg_klassning_decide($fredrik, $fredrikFace);
rs(is_array($fredrikDec) && ($fredrikDec['status'] ?? '') === 'closed' && ($fredrikDec['type'] ?? '') === 'Ny lead' && ($fredrikDec['offer'] ?? '') === 'sol', 'beslutet ville stänga Fredrik som Ny lead sol');
$hansFace = jeab_inkorg_klassning_face($hans);
$hansDec = jeab_inkorg_klassning_decide($hans, $hansFace);
rs(is_array($hansDec) && ($hansDec['offer'] ?? '') === 'sol', 'beslutet ville byta Hans från laddbox till sol');
$remFace = jeab_inkorg_klassning_face($reminderThread);
$remDec = jeab_inkorg_klassning_decide($reminderThread, $remFace);
rs(is_array($remDec) && ($remDec['offer'] ?? '') === 'sol', 'beslutet ville byta produkt på påminnelseärendet');
$omcFace = jeab_inkorg_klassning_face($omc);
rs(jeab_inkorg_klassning_own($omcFace) === false && jeab_inkorg_klassning_route($omcFace) !== 'egen', 'Omtago omc* är inte egen post');
rs((string) ($omcFace['from_name'] ?? '') === 'Test Testsson', 'Omtago klassas på testkunden, inte vårt svar');

$sentBefore = count($GLOBALS['_SENT']);
$dry = jeab_inkorg_hook(['action' => 'inkorg.reclassify', 'dry' => 1, 'days' => 30]);
rs(($dry['ok'] ?? false) === true && ($dry['dry'] ?? 0) === 1 && ($dry['changed'] ?? 1) === 0 && (int) ($dry['sent'] ?? 1) === 0, 'dry skriver inget och skickar inget');
rs(str_contains(json_encode($dry, JSON_UNESCAPED_UNICODE) ?: '', '@') === false, 'dry-svaret har ingen e-post');
rs(count($GLOBALS['_SENT']) === $sentBefore, 'dry skickade inget');
$dryBy = [];
foreach ((array) ($dry['rows'] ?? []) as $row) {
    $dryBy[(string) ($row['thread_id'] ?? '')] = $row;
}
rs(!isset($dryBy[$kevinId]), 'dry stänger inte Kevin');
rs(isset($dryBy[$fredrikId]) && ($dryBy[$fredrikId]['new_status'] ?? '') === 'open' && ($dryBy[$fredrikId]['new_type'] ?? '') === 'Ny lead' && ($dryBy[$fredrikId]['new_offer'] ?? '') === 'sol' && ($dryBy[$fredrikId]['new'] ?? '') !== 'historik', 'dry gör Fredrik till Ny lead sol och lämnar honom öppen');
rs(!isset($dryBy[$hansId]), 'dry byter inte produkt på Hans');
rs(!isset($dryBy[$remId]), 'dry byter inte produkt när en påminnelse finns');
rs(!isset($dryBy[$omcId]) || (($dryBy[$omcId]['route'] ?? '') !== 'egen' && ($dryBy[$omcId]['new_status'] ?? '') !== 'closed'), 'dry stänger inte Omtago som egen post');
rs(($thread($kevinId)['status'] ?? '') === 'open' && $offerOf($hansId) === 'laddbox', 'dry lämnar Kevin öppen och Hans på laddbox');

$wet = jeab_inkorg_hook(['action' => 'inkorg.reclassify', 'dry' => 0, 'days' => 30]);
rs(($wet['ok'] ?? false) === true && ($wet['dry'] ?? 1) === 0 && (int) ($wet['sent'] ?? 1) === 0, 'dry=0 skickar inget');
rs(count($GLOBALS['_SENT']) === $sentBefore, 'dry=0 skickade inget');
rs(str_contains(json_encode($wet, JSON_UNESCAPED_UNICODE) ?: '', '@') === false, 'svaret har ingen e-post');

$kevinAfter = $thread($kevinId);
rs(($kevinAfter['status'] ?? '') === 'open' && ($kevinAfter['assignee'] ?? '') === 'Roberto' && $offerOf($kevinId) === 'batteri' && $typeOf($kevinId) === 'Ny lead' && !jeab_inkorg_folder_thread($kevinAfter, 'historik'), 'Kevin Magnusson förblir öppet batteri hos Roberto');

$fredrikAfter = $thread($fredrikId);
rs(($fredrikAfter['status'] ?? '') === 'open' && $typeOf($fredrikId) === 'Ny lead' && $offerOf($fredrikId) === 'sol' && !jeab_inkorg_folder_thread($fredrikAfter, 'historik') && jeab_inkorg_folder_thread($fredrikAfter, 'inkorg'), 'Fredrik Karlsson blir Ny lead sol och lämnas öppen');

$hansAfter = $thread($hansId);
rs(($hansAfter['status'] ?? '') === 'closed' && ($hansAfter['assignee'] ?? '') === 'Elin' && $offerOf($hansId) === 'laddbox' && $typeOf($hansId) === 'Ny lead', 'Hans Steijen behåller laddbox och Elin');

$remAfter = $thread($remId);
rs(($remAfter['status'] ?? '') === 'closed' && ($remAfter['assignee'] ?? '') === 'Roberto' && $offerOf($remId) === 'laddbox', 'påminnelseärendet behåller produkt och ansvarig');

$omcAfter = $thread($omcId);
$omcFaceAfter = jeab_inkorg_klassning_face($omcAfter);
rs(($omcAfter['status'] ?? '') === 'open' && !jeab_inkorg_folder_thread($omcAfter, 'historik') && $typeOf($omcId) === 'Ny lead' && jeab_inkorg_klassning_route($omcFaceAfter) !== 'egen', 'Omtago omc40b524df1c8c0 lämnas öppet och är inte egen post');

echo "OK  reclassify-safe\n";
