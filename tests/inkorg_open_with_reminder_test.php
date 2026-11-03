<?php

declare(strict_types=1);

/** Öppna ärenden med påminnelse. Påhittade adresser. Inget skickas. Inkorgen i SQL. */

$tmp = sys_get_temp_dir() . '/jeab-open-rem-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ow(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_boot();
jeab_set_setting($store, 'inkorg_storage', 'sql');
ow(jeab_inkorg_storage_mode() === 'sql', 'inkorgen ligger i SQL');

$sent = 0;
$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $opts) use (&$sent): array {
    unset($opts);
    $sent++;
    $GLOBALS['_SENT'][] = ['n' => $sent];
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g-ow', 'message_id' => 'ow@local'];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$sent): array {
    unset($opts);
    $sent++;
    return ['ok' => true];
};

$labels = [
    'type' => 'Information', 'action' => 'arkivera', 'priority' => 4, 'confidence' => 90,
    'intent' => 'annat', 'offer' => '', 'product' => '', 'question' => '',
];
$box = jeab_graph_mailboxes()['jarfalla'];
$ingest = static function (string $id, string $email, string $when) use ($labels, $box): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@open-rem.test',
        'from' => $email,
        'from_name' => 'Kund',
        'to' => $box,
        'subject' => 'Formulär ' . $id,
        'body_text' => "Namn: Kund\nE-post: {$email}\nHej.\n",
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'thread_key' => 'g-' . $id,
        'received_at' => $when,
        'preset_labels' => $labels,
        'test' => true,
    ]);
};

$older = $ingest('older', 'ada.open@example.test', '2026-09-01T10:00:00+02:00');
$olderTid = (string) $older['thread_id'];
jeab_inkorg_thread_write($olderTid, static function (array $t): array {
    $t['status'] = 'closed';
    return $t;
});
$rem = jeab_create_reminder(jeab_store(), 'Kund – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Kund',
    'email' => 'ada.open@example.test',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'thread_id' => $olderTid,
    'note' => 'mail: ' . jeab_lead_mail_url($olderTid),
]);
$remId = (int) $rem['id'];
ow($remId > 0 && (jeab_inkorg_thread_meta($olderTid)['status'] ?? '') === 'closed', 'påminnelsen finns och den stängda tråden är stängd');

$form = $ingest('form', 'ada.open@example.test', '2026-10-19T08:30:00+02:00');
$formTid = (string) $form['thread_id'];
ow($formTid !== '' && $formTid !== $olderTid, 'nytt formulär får ett eget ärende');
ow((jeab_inkorg_thread_meta($formTid)['status'] ?? '') === 'open', 'formuläret är öppet innan stängning');

$listed = jeab_inkorg_hook(['action' => 'inbox.open_with_reminder', 'co' => 'jarfalla']);
$listedJson = (string) json_encode($listed, JSON_UNESCAPED_UNICODE);
ow(($listed['ok'] ?? false) === true && ($listed['would_send'] ?? true) === false, 'inbox.open_with_reminder är bara läsning');
ow(!str_contains($listedJson, '@') && !str_contains($listedJson, 'Kund'), 'listan har varken adress eller namn');
$hit = null;
foreach ((array) ($listed['rows'] ?? []) as $row) {
    if (is_array($row) && ($row['thread_id'] ?? '') === $formTid && (int) ($row['reminder_id'] ?? 0) === $remId) {
        $hit = $row;
    }
}
ow(is_array($hit) && ($hit['cause'] ?? '') === 'other_thread' && ($hit['date'] ?? '') === '2026-10-19', 'öppet syskon listas med datum och other_thread');
$allowed = ['thread_id', 'reminder_id', 'date', 'cause'];
ow(is_array($hit) && array_keys($hit) === $allowed, 'raden har bara tråd, påminnelse, datum och orsak');

$other = $ingest('other', 'boa.other@example.test', '2026-10-19T09:00:00+02:00');
$again = jeab_inkorg_hook(['action' => 'inbox.open_with_reminder', 'co' => 'jarfalla']);
$ids = array_column((array) ($again['rows'] ?? []), 'thread_id');
ow(in_array($formTid, $ids, true) && !in_array((string) $other['thread_id'], $ids, true), 'en annan kund listas inte');
ow((jeab_inkorg_hook(['action' => 'inbox.open_with_reminder', 'co' => 'malmo'])['rows'] ?? null) === [], 'fel bolag är tomt');
ow((jeab_inkorg_hook(['action' => 'inbox.open_with_reminder'])['ok'] ?? true) === false, 'co krävs');

$dry = jeab_inkorg_hook(['action' => 'inbox.close_with_reminder', 'co' => 'jarfalla']);
ow(($dry['dry'] ?? 0) === 1 && ($dry['closed'] ?? 1) === 0 && ($dry['would_send'] ?? true) === false && (jeab_inkorg_thread_meta($formTid)['status'] ?? '') === 'open', 'dry=1 stänger inget');
$no = jeab_inkorg_hook(['action' => 'inbox.close_with_reminder', 'co' => 'jarfalla', 'dry' => 0]);
ow(($no['ok'] ?? true) === false && ($no['error'] ?? '') === 'confirm' && (jeab_inkorg_thread_meta($formTid)['status'] ?? '') === 'open', 'dry=0 utan confirm stänger inget');

$same = $ingest('same', 'cia.same@example.test', '2026-10-18T12:00:00+02:00');
$sameTid = (string) $same['thread_id'];
$sameRem = jeab_create_reminder(jeab_store(), 'Kund – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Kund',
    'email' => 'cia.same@example.test',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'utan tråd',
]);
ow((jeab_inkorg_thread_meta($sameTid)['status'] ?? '') === 'closed', 'ny påminnelse stänger öppna ärenden med samma kundmejl');

$contactTid = (string) $ingest('contact', 'disa.contact@example.test', '2026-10-17T12:00:00+02:00')['thread_id'];
jeab_inkorg_thread_write($contactTid, static function (array $t): array {
    $t['contact_id'] = 'k-ow-1';
    $t['status'] = 'open';
    return $t;
});
$contactRem = jeab_create_reminder(jeab_store(), 'Kund – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Kund',
    'email' => 'nagon.annan@example.test',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'contact_id' => 'k-ow-1',
    'thread_id' => 'hs-ow-1',
]);
ow((jeab_inkorg_thread_meta($contactTid)['status'] ?? '') === 'closed', 'samma kontakt stängs även när mejlen skiljer');
jeab_inkorg_thread_write($contactTid, static function (array $t): array {
    $t['status'] = 'open';
    unset($t['hook_closed']);
    return $t;
});
$contactList = jeab_inkorg_hook(['action' => 'inbox.open_with_reminder', 'co' => 'jarfalla']);
$contactCause = '';
foreach ((array) ($contactList['rows'] ?? []) as $row) {
    if (is_array($row) && ($row['thread_id'] ?? '') === $contactTid && (int) ($row['reminder_id'] ?? 0) === (int) $contactRem['id']) {
        $contactCause = (string) ($row['cause'] ?? '');
    }
}
ow($contactCause === 'hs_twin', 'hs-id mot ett annat ärende är hs_twin');

jeab_inkorg_thread_write($formTid, static function (array $t): array {
    $t['status'] = 'open';
    $t['reopened_at'] = '2026-10-19T18:00:00+02:00';
    unset($t['hook_closed']);
    return $t;
});
$reList = jeab_inkorg_hook(['action' => 'inbox.open_with_reminder', 'co' => 'jarfalla']);
$reCause = '';
foreach ((array) ($reList['rows'] ?? []) as $row) {
    if (is_array($row) && ($row['thread_id'] ?? '') === $formTid) {
        $reCause = (string) ($row['cause'] ?? '');
    }
}
ow($reCause === 'reopened_mail', 'ett nytt mejl som öppnat ärendet är reopened_mail');
$second = jeab_create_reminder(jeab_store(), 'Kund – Sol', jeab_today_start(), null, null, [
    'customer' => 'Kund',
    'email' => 'ada.open@example.test',
    'company' => 'jarfalla',
    'who' => 'Sam L',
    'note' => 'mail: ' . jeab_lead_mail_url($olderTid),
]);
ow((int) ($second['id'] ?? 0) > 0 && (jeab_inkorg_thread_meta($formTid)['status'] ?? '') === 'open', 'automatisk stängning lämnar ett kundsvar öppet');

$live = jeab_inkorg_hook(['action' => 'inbox.close_with_reminder', 'co' => 'jarfalla', 'dry' => 0, 'confirm' => 'SAM']);
$liveJson = (string) json_encode($live, JSON_UNESCAPED_UNICODE);
ow(($live['ok'] ?? false) === true && ($live['dry'] ?? 1) === 0 && ($live['would_send'] ?? true) === false && !str_contains($liveJson, '@'), 'confirm stänger utan att skriva en adress');
ow((jeab_inkorg_thread_meta($formTid)['status'] ?? '') === 'closed' && (jeab_inkorg_thread_meta($contactTid)['status'] ?? '') === 'closed', 'confirm stänger de öppna träffarna');
$after = jeab_inkorg_hook(['action' => 'inbox.open_with_reminder', 'co' => 'jarfalla']);
ow(($after['rows'] ?? null) === [], 'stängda ärenden försvinner ur listan');
ow($sent === 0 && $GLOBALS['_SENT'] === [], 'varken lista eller stängning skickar något');
ow(!is_file(jeab_inkorg_dir() . '/messages.json'), 'SQL-läget skriver ingen messages.json');

$alias = jeab_inkorg_hook(['action' => 'inkorg.open_with_reminder', 'co' => 'jarfalla']);
ow(($alias['ok'] ?? false) === true && ($alias['rows'] ?? null) === [], 'inkorg.open_with_reminder är samma anrop');

echo "OK  open-with-reminder\n";
