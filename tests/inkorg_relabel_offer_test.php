<?php

declare(strict_types=1);

/**
 * Manuell Ny lead + produkt köar samma offert en gång.
 * Påhittade namn och adresser. Inget skickas mot nätet.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-relabel-offer-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ro(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$srcAuto = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_auto.php');
$srcInkorg = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg.php');
$previewAt = strpos($srcAuto, 'function jeab_inkorg_relabel_offer_preview');
$previewEnd = strpos($srcAuto, 'function jeab_inkorg_relabel_offer_queue');
$previewFn = $previewAt !== false && $previewEnd !== false ? substr($srcAuto, $previewAt, $previewEnd - $previewAt) : '';
ro(str_contains($srcAuto, 'function jeab_inkorg_relabel_offer_apply'), 'manuell offert återanvänder en funktion');
ro(str_contains($srcAuto, "jeab_inbox_queue_quote([") && str_contains($srcInkorg, 'jeab_inkorg_relabel_offer_apply'), 'relabel köar via den befintliga kön');
ro(str_contains($srcInkorg, "case 'inbox.relabel_offer_preview':"), 'förhandsvisningen är en hook');
ro(str_contains($srcInkorg, "case 'inbox.relabel_offer_queue':"), 'en namngiven tråd köas via en hook');
ro($previewFn !== '' && !str_contains($previewFn, 'jeab_inbox_quote_queue_save') && !str_contains($previewFn, 'jeab_auto_send_quote') && !str_contains($previewFn, 'jeab_no_email_maybe') && !str_contains($previewFn, 'jeab_inkorg_thread_write'), 'förhandsvisningen skriver inte och skickar inte');
ro(str_contains(implode("\n", jeab_auto_rule_lines()), 'inbox.relabel_offer_preview') && str_contains(implode("\n", jeab_auto_rule_lines()), 'inbox.relabel_offer_queue'), 'regeln nämner hooken');

$GLOBALS['_SENT'] = [];
$GLOBALS['_SMS'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['_SENT'][] = $opts;

    return ['ok' => true, 'path' => 'stub', 'id' => 'm1', 'from' => 'saljare3@jarfalla.example.test'];
};
$GLOBALS['JEAB_SMS_SEND'] = static function (): array {
    $GLOBALS['_SMS'][] = 1;

    return ['ok' => true, 'queued' => 1];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (): array {
    return ['code' => 200, 'body' => ['value' => []]];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);

$ingest = static function (string $key, string $body): array {
    $in = jeab_inkorg_ingest([
        'message_id' => $key . '@relabel.test',
        'from' => 'info@wix-forms.com',
        'from_name' => 'Formulär',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Offert',
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'thread_key' => $key,
        'preset_labels' => [
            'type' => 'Information', 'action' => 'arkiv', 'priority' => 3, 'confidence' => 90,
            'intent' => 'info', 'offer' => '', 'product' => '', 'question' => '',
        ],
    ]);
    ro(!empty($in['ok']), 'ärendet ' . $key . ' tas in');

    return ['id' => (string) $in['id'], 'thread' => (string) $in['thread_id']];
};

$queueCount = static function (): int {
    unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);

    return count(jeab_inbox_quote_queue_load());
};

$reminderCount = static function (): int {
    return count(jeab_list_reminders(jeab_store(), true));
};

$body = static function (string $name, string $email, string $phone): string {
    $mail = $email === '' ? '' : "E-post: {$email}\n";

    return "Namn: {$name}\n{$mail}Telefon: {$phone}\nAdress: Fejkgatan 3, 176 70 Järfälla\nProdukt: Batteri\n";
};

$one = $ingest('lead-a', $body('Fejk Lead', 'fejk.lead@gmail.com', '0701112233'));
jeab_inkorg_thread_set($one['thread'], 'open', ['batteri'], false);
jeab_inkorg_case_product_labels($one['thread'], ['batteri']);
ro($queueCount() === 0, 'produkt utan Ny lead köar inget');

$beforeReminders = $reminderCount();
$preview = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_preview', 'thread_id' => $one['thread']]);
ro(($preview['ok'] ?? false) === true && (int) ($preview['quote'] ?? 1) === 0 && ($preview['reason'] ?? '') === 'saknar Ny lead och produkt' && (int) ($preview['sent'] ?? 1) === 0 && ($preview['read_only'] ?? false) === true, 'förhandsvisning utan Ny lead säger nej');
ro($queueCount() === 0 && $reminderCount() === $beforeReminders && jeab_inkorg_relabel_offer_idem_map($one['thread']) === [], 'förhandsvisningen lämnar kön, påminnelser och idemnyckeln');

jeab_create_reminder(jeab_store(), 'Fejk Lead – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Fejk Lead',
    'email' => 'fejk.lead@gmail.com',
    'phone' => '0701112233',
    'address' => 'Fejkgatan 3',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'fanns redan',
    'thread_id' => $one['thread'],
]);
$withReminder = $reminderCount();
ro($withReminder === $beforeReminders + 1, 'en påminnelse utan offert finns');

jeab_inkorg_relabel($one['id'], ['type' => 'Ny lead'], 'personal');
ro($queueCount() === 1, 'relabel till Ny lead och produkt köar en offert');
$queue = jeab_inbox_quote_queue_load();
ro(isset($queue[$one['thread']]) && (string) ($queue[$one['thread']]['product'] ?? '') === 'batteri' && (string) ($queue[$one['thread']]['idem'] ?? '') === $one['thread'] . '|batteri', 'kön bär tråd och produkt');
ro($reminderCount() === $withReminder, 'befintlig påminnelse används, ingen kopia');
ro(count($GLOBALS['_SENT']) === 0, 'relabel skickar inget mejl');

$look = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_preview', 'thread_id' => $one['thread']]);
$lookRaw = json_encode($look, JSON_UNESCAPED_UNICODE) ?: '';
ro((int) ($look['quote'] ?? 1) === 0 && ($look['reason'] ?? '') === 'redan köad' && ($look['template'] ?? '') === 'offert-batteri-jarfalla' && ($look['seller'] ?? '') === 'Roberto' && (int) ($look['sent'] ?? 1) === 0 && ($look['would_send'] ?? true) === false, 'förhandsvisningen visar mall, säljare och att den redan är köad');
ro(!str_contains($lookRaw, '@') && !str_contains($lookRaw, 'Fejk') && !str_contains($lookRaw, '070'), 'förhandsvisningen har ingen adress och inget kundnamn');

jeab_inkorg_relabel($one['id'], ['type' => 'Ny lead'], 'personal');
ro($queueCount() === 1 && $reminderCount() === $withReminder, 'samma relabel ger ingen andra kö och ingen andra påminnelse');

unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
jeab_inbox_quote_queue_save([]);
ro($queueCount() === 0, 'kön är tömd');
jeab_inkorg_relabel($one['id'], ['type' => 'Information'], 'personal');
jeab_inkorg_relabel($one['id'], ['type' => 'Ny lead'], 'personal');
ro($queueCount() === 1, 'en tömd kö utan utskick får köas igen');
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
jeab_inbox_quote_queue_save([]);
ro($queueCount() === 0, 'kön är tömd igen');

$quoted = $ingest('lead-b', $body('Fejk Sparad', 'fejk.sparad@gmail.com', '0701112244'));
jeab_inkorg_thread_set($quoted['thread'], 'open', ['batteri'], false);
jeab_inkorg_thread_write($quoted['thread'], static function (array $t): array {
    $t['quote_sent'] = ['source' => 'anteckning', 'date' => '2026-10-01', 'at' => '2026-10-01T10:00:00+02:00', 'product' => 'batteri'];

    return $t;
});
jeab_inkorg_case_product_labels($quoted['thread'], ['batteri']);
jeab_inkorg_relabel($quoted['id'], ['type' => 'Ny lead'], 'personal');
ro($queueCount() === 0, 'en redan skickad offert köas inte');

$closed = $ingest('lead-c', $body('Fejk Stangd', 'fejk.stangd@gmail.com', '0701112255'));
jeab_inkorg_thread_set($closed['thread'], 'open', ['batteri'], false);
$made = jeab_create_reminder(jeab_store(), 'Fejk Stangd – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Fejk Stangd',
    'email' => 'fejk.stangd@gmail.com',
    'phone' => '0701112255',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'Offert (Batteri) skickad',
    'thread_id' => $closed['thread'],
]);
ro(is_array($made) && (int) ($made['id'] ?? 0) > 0, 'stängd påminnelse med offert skapas');
jeab_toggle_reminder(jeab_store(), (int) $made['id'], true);
jeab_inkorg_case_product_labels($closed['thread'], ['batteri']);
jeab_inkorg_relabel($closed['id'], ['type' => 'Ny lead'], 'personal');
ro($queueCount() === 0, 'en stängd påminnelse med offert stoppar ny offert');

$silent = $ingest('lead-d', $body('Fejk Ring', '', '0701112266'));
jeab_inkorg_thread_set($silent['thread'], 'open', ['batteri'], false);
$beforeCall = $reminderCount();
$smsBefore = count($GLOBALS['_SMS']);
jeab_inkorg_case_product_labels($silent['thread'], ['batteri']);
jeab_inkorg_relabel($silent['id'], ['type' => 'Ny lead'], 'personal');
ro($queueCount() === 0, 'utan kundmejl köas ingen offert');
$callNotes = '';
foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
    if (!is_array($rem) || (string) ($rem['thread_id'] ?? '') !== $silent['thread']) {
        continue;
    }
    $callNotes .= (string) ($rem['note'] ?? '');
}
ro($reminderCount() === $beforeCall + 1 && str_contains($callNotes, 'ring kunden och ta mejladressen'), 'utan kundmejl blir det en påminnelse att ringa');
ro(count($GLOBALS['_SMS']) === $smsBefore + 1, 'utan kundmejl går SMS den vanliga vägen');
ro(count($GLOBALS['_SENT']) === 0, 'samtalet skickar inget offertmejl');

$fake = $ingest('lead-e', $body('Fejk Exempel', 'testkund@example.com', '0701112277'));
jeab_inkorg_thread_set($fake['thread'], 'open', ['batteri'], false);
jeab_inkorg_case_product_labels($fake['thread'], ['batteri']);
jeab_inkorg_relabel($fake['id'], ['type' => 'Ny lead'], 'personal');
ro($queueCount() === 0, 'testdata köas inte');

$later = $ingest('lead-g', $body('Fejk Sen', 'fejk.sen@gmail.com', '0701112299'));
jeab_inkorg_relabel($later['id'], ['type' => 'Ny lead'], 'personal');
ro($queueCount() === 0, 'Ny lead utan produkt köar inget');
jeab_inkorg_thread_set($later['thread'], 'open', ['elbilsladdare'], false);
jeab_inkorg_case_product_labels($later['thread'], ['elbilsladdare']);
$laterQueue = jeab_inbox_quote_queue_load();
ro($queueCount() === 1 && (string) ($laterQueue[$later['thread']]['product'] ?? '') === 'laddbox' && ($laterQueue[$later['thread']]['idem'] ?? '') === $later['thread'] . '|laddbox', 'produktetikett på Ny lead köar elbilsladdare en gång');
jeab_inkorg_case_product_labels($later['thread'], ['elbilsladdare']);
ro($queueCount() === 1, 'samma produktetikett köar inte igen');
unset($GLOBALS['_JEAB_QUOTE_QUEUE_MEM']);
jeab_inbox_quote_queue_save([]);

$previewOnly = $ingest('lead-f', $body('Fejk Titt', 'fejk.titt@gmail.com', '0701112288'));
jeab_inkorg_thread_write($previewOnly['thread'], static function (array $t): array {
    $t['tags'] = ['sol'];

    return $t;
});
jeab_inkorg_update($previewOnly['id'], static function (array $m): array {
    $labels = (array) ($m['labels'] ?? []);
    $labels['type'] = 'Ny lead';
    $labels['offer'] = 'sol';
    $labels['product'] = 'sol';
    $labels['intent'] = 'kop';
    $m['labels'] = $labels;

    return $m;
});
$same = $ingest('lead-h', $body('Fejk Om', 'fejk.om@gmail.com', '0701112200'));
jeab_inkorg_thread_set($same['thread'], 'open', ['batteri'], false);
jeab_inkorg_update($same['id'], static function (array $m): array {
    $labels = (array) ($m['labels'] ?? []);
    $labels['type'] = 'Ny lead';
    $labels['offer'] = 'batteri';
    $labels['product'] = 'batteri';
    $labels['intent'] = 'kop';
    $m['labels'] = $labels;

    return $m;
});
jeab_inkorg_relabel($same['id'], ['type' => 'Ny lead'], 'personal');
ro($queueCount() === 0, 'oförändrad Ny lead köar inget');
$_POST = ['thread' => $same['thread'], 'status' => 'closed', 'status_ui' => '1'];
ro(jeab_inkorg_action('inkorg.thread') === 'Ärendet sparat.' && $queueCount() === 0, 'en statusändring köar inget');

$oldAt = date('c', time() - 15 * 86400);
$oldIn = jeab_inkorg_ingest([
    'message_id' => 'lead-i@relabel.test',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert',
    'body_text' => $body('Fejk Gammal', 'fejk.gammal@gmail.com', '0701112211'),
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'thread_key' => 'lead-i',
    'received_at' => $oldAt,
    'preset_labels' => [
        'type' => 'Information', 'action' => 'arkiv', 'priority' => 3, 'confidence' => 90,
        'intent' => 'info', 'offer' => '', 'product' => '', 'question' => '',
    ],
]);
ro(!empty($oldIn['ok']), 'ärendet lead-i tas in');
$old = ['id' => (string) $oldIn['id'], 'thread' => (string) $oldIn['thread_id']];
jeab_inkorg_thread_set($old['thread'], 'open', ['batteri'], false);
jeab_inkorg_case_product_labels($old['thread'], ['batteri']);
$oldReminders = $reminderCount();
jeab_inkorg_relabel($old['id'], ['type' => 'Ny lead'], 'personal');
$oldLook = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_preview', 'thread_id' => $old['thread']]);
ro($queueCount() === 0 && $reminderCount() === $oldReminders && ($oldLook['reason'] ?? '') === 'första mejlet är äldre än 14 dagar' && (int) ($oldLook['quote'] ?? 1) === 0, 'ett första mejl äldre än 14 dagar köas inte');
jeab_inkorg_relabel($old['id'], ['type' => 'Ny lead'], 'personal');
$_POST = ['thread' => $old['thread'], 'status' => 'closed', 'status_ui' => '1'];
ro(jeab_inkorg_action('inkorg.thread') === 'Ärendet sparat.' && $queueCount() === 0, 'oförändrad gammal Ny lead och en statusändring köar inget');

jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
$held = $ingest('lead-j', $body('Fejk Av', 'fejk.av@gmail.com', '0701112222'));
jeab_inkorg_thread_set($held['thread'], 'open', ['batteri'], false);
jeab_inkorg_case_product_labels($held['thread'], ['batteri']);
$heldReminders = $reminderCount();
jeab_inkorg_relabel($held['id'], ['type' => 'Ny lead'], 'personal');
$heldNotes = '';
foreach ((array) (jeab_inkorg_thread_meta($held['thread'])['drafts'] ?? []) as $draft) {
    if (is_array($draft) && ($draft['kind'] ?? '') === 'note') {
        $heldNotes .= (string) ($draft['body'] ?? '');
    }
}
ro($queueCount() === 0 && $reminderCount() === $heldReminders && str_contains($heldNotes, 'offertutskick är av'), 'avstängt offertutskick skriver bara en anteckning');
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);

$qBefore = $queueCount();
$rBefore = $reminderCount();
$sol = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_preview', 'thread_id' => $previewOnly['thread']]);
ro((int) ($sol['quote'] ?? 0) === 1 && ($sol['seller'] ?? '') === 'Sam L' && str_starts_with((string) ($sol['template'] ?? ''), 'offert-sol-jarfalla') && ($sol['reason'] ?? '') === 'första kontakt', 'eget sollead visar mall och säljare');
ro($queueCount() === $qBefore && $reminderCount() === $rBefore, 'solförhandsvisningen skriver inget');

$undated = $ingest('lead-k', $body('Fejk Udat', 'fejk.udat@gmail.com', '0701112201'));
jeab_inkorg_update($undated['id'], static function (array $m): array {
    $m['received_at'] = '';
    $m['date'] = '';

    return $m;
});
jeab_inkorg_thread_set($undated['thread'], 'open', ['batteri'], false);
jeab_inkorg_case_product_labels($undated['thread'], ['batteri']);
$undatedReminders = $reminderCount();
jeab_inkorg_relabel($undated['id'], ['type' => 'Ny lead'], 'personal');
$undatedLook = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_preview', 'thread_id' => $undated['thread']]);
ro($queueCount() === $qBefore && $reminderCount() === $undatedReminders && (int) ($undatedLook['quote'] ?? 1) === 0 && ($undatedLook['reason'] ?? '') === 'första mejlet saknar datum', 'ett mejl utan datum köas inte');

$namedBefore = $queueCount();
$namedReminders = $reminderCount();
$dry = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_queue', 'thread_id' => $undated['thread'], 'dry' => 1]);
ro((int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['quote'] ?? 0) === 1 && ($dry['reason'] ?? '') === 'första kontakt' && ($dry['template'] ?? '') === 'offert-batteri-jarfalla' && ($dry['seller'] ?? '') === 'Roberto' && (int) ($dry['queued'] ?? 1) === 0 && (int) ($dry['sent'] ?? 1) === 0 && ($dry['would_send'] ?? true) === false, 'dry visar planen för den namngivna tråden');
ro($queueCount() === $namedBefore && $reminderCount() === $namedReminders && jeab_inkorg_relabel_offer_idem_map($undated['thread']) === [], 'dry skriver inte');
$live = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_queue', 'thread_id' => $undated['thread'], 'dry' => 0]);
$namedQueue = jeab_inbox_quote_queue_load();
ro((int) ($live['queued'] ?? 0) === 1 && (int) ($live['sent'] ?? 1) === 0 && $queueCount() === $namedBefore + 1 && (string) ($namedQueue[$undated['thread']]['product'] ?? '') === 'batteri', 'dry 0 köar den namngivna tråden en gång');
$again = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_queue', 'thread_id' => $undated['thread'], 'dry' => '0']);
ro((int) ($again['queued'] ?? 1) === 0 && ($again['reason'] ?? '') === 'redan köad' && $queueCount() === $namedBefore + 1, 'samma tråd köas inte en andra gång');
$batch = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_queue', 'threads' => [$undated['thread'], $old['thread']], 'dry' => 0]);
$pair = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_queue', 'thread_id' => $undated['thread'] . ',' . $old['thread'], 'dry' => 0]);
$bare = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_queue', 'dry' => 0]);
ro(($batch['error'] ?? '') === 'one_thread' && ($pair['error'] ?? '') === 'one_thread' && ($bare['error'] ?? '') === 'one_thread' && $queueCount() === $namedBefore + 1, 'en lista eller flera id köar inget');
$testQueue = $queueCount();
$testHook = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_queue', 'thread_id' => $fake['thread'], 'dry' => 0]);
ro((int) ($testHook['queued'] ?? 1) === 0 && ($testHook['reason'] ?? '') === 'testlead' && $queueCount() === $testQueue, 'testdata köas inte via hooken');

echo "OK  relabel-offert\n";
