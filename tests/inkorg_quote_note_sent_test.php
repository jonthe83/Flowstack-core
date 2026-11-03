<?php

declare(strict_types=1);

/**
 * Relabel-kön och uppföljningen kräver ett riktigt «skickad». Help Scout behåller det gamla mönstret.
 * Påhittade adresser. Inget skickas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-quote-note-' . bin2hex(random_bytes(4));
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

function qn(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "OK  {$message}\n";
}

$held = 'Offert skickades inte automatiskt: klassningen misslyckades (xAI svarade inte). Ärendet lämnas till er.';
$product = 'Offert (batteri) skickad';
$plain = 'Offert skickad';

qn(jeab_inkorg_quote_note_is_sent($held) === false, 'skickades inte är inte ett utskick i relabel');
qn(jeab_inkorg_relabel_offer_reminder_quoted(['note' => $held]) === false, 'relabel-påminnelsen med skickades inte är inte skickad');
qn(jeab_inkorg_quote_note_is_sent($product) === true && jeab_inkorg_relabel_offer_reminder_quoted(['note' => $product]) === true, 'Offert (batteri) skickad räknas i relabel');
qn(jeab_inkorg_quote_note_is_sent($plain) === true && jeab_inkorg_relabel_offer_reminder_quoted(['note' => $plain]) === true, 'Offert skickad räknas i relabel');

$made = jeab_create_reminder(jeab_store(), 'Fejk Not – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Fejk Not',
    'email' => 'fejk.not@gmail.com',
    'phone' => '0701112202',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => $held,
    'thread_id' => 'trad-not',
]);
qn(is_array($made) && (int) ($made['id'] ?? 0) > 0, 'påminnelsen finns');
$thread = ['id' => 'annan-trad'];
$cust = ['name' => 'Fejk Not', 'email' => 'fejk.not@gmail.com', 'phone' => '0701112202'];
qn(jeab_follow_prior_quote($thread, $cust, true) === false, 'uppföljningen räknar inte skickades inte som offert');

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $mail): array {
    $GLOBALS['_SENT'][] = $mail;

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['_SENT'][] = $opts;

    return ['ok' => true, 'path' => 'stub', 'id' => 'm1', 'from' => 'saljare3@jarfalla.example.test'];
};
$hsNote = static function (string $email, string $body, string $key): void {
    $id = 'hsi-' . substr(hash('sha256', $key), 0, 16);
    $conv = [
        'id' => $id,
        'company' => 'jarfalla',
        'messages' => [
            [
                'id' => substr(hash('sha256', $key . '-kund'), 0, 16),
                'from' => $email,
                'from_name' => 'Fejk Hs',
                'subject' => 'Offert',
                'body' => 'Hej',
                'received_at' => date('c'),
                'hs_thread_type' => 'customer',
            ],
            [
                'id' => substr(hash('sha256', $key . '-not'), 0, 16),
                'from' => 'anteckning@jarfalla.example.test',
                'body' => $body,
                'received_at' => date('c'),
                'internal_note' => true,
                'hs_thread_type' => 'note',
            ],
        ],
        'meta' => ['status' => 'closed', 'import_source' => 'helpscout_import'],
    ];
    qn(jeab_hs_import_save_conv($conv) && jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'Help Scout-anteckningen lagras');
    unset($GLOBALS['_JEAB_QUOTE_HS_SET'][$email], $GLOBALS['_JEAB_QUOTE_INBOX_SET'][$email]);
    $why = jeab_auto_quote_already_sent(['id' => 'hs-' . $key], '', $email, 'batteri');
    qn(str_contains($why, 'redan skickad') && str_contains($why, 'Help Scout'), $body . ' stoppar auto-offerten');
};
$hsNote('fejk.hs1@gmail.com', 'Offert skickades till kunden 12/10', 'hs-skickades');
$hsNote('fejk.hs2@gmail.com', 'Skickade offert idag', 'hs-skickade');

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
$in = jeab_inkorg_ingest([
    'message_id' => 'lead-not@relabel.test',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert',
    'body_text' => "Namn: Fejk Ko\nE-post: fejk.ko@gmail.com\nTelefon: 0701112203\nAdress: Fejkgatan 3, 176 70 Järfälla\nProdukt: Batteri\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'thread_key' => 'lead-not',
    'received_at' => date('c'),
    'preset_labels' => [
        'type' => 'Information', 'action' => 'arkiv', 'priority' => 3, 'confidence' => 90,
        'intent' => 'info', 'offer' => '', 'product' => '', 'question' => '',
    ],
]);
qn(!empty($in['ok']), 'ärendet tas in');
$tid = (string) $in['thread_id'];
$mid = (string) $in['id'];
jeab_inkorg_thread_set($tid, 'open', ['batteri'], false);
jeab_inkorg_update($mid, static function (array $m): array {
    $labels = (array) ($m['labels'] ?? []);
    $labels['type'] = 'Ny lead';
    $labels['offer'] = 'batteri';
    $labels['product'] = 'batteri';
    $labels['intent'] = 'kop';
    $m['labels'] = $labels;

    return $m;
});
jeab_create_reminder(jeab_store(), 'Fejk Ko – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Fejk Ko',
    'email' => 'fejk.ko@gmail.com',
    'phone' => '0701112203',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => $held,
    'thread_id' => $tid,
]);
$dry = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_queue', 'thread_id' => $tid, 'dry' => 1]);
qn((int) ($dry['quote'] ?? 0) === 1 && ($dry['reason'] ?? '') !== 'redan skickad' && (int) ($dry['queued'] ?? 1) === 0, 'samma anteckning stoppar inte den namngivna kön');
$live = jeab_inkorg_hook(['action' => 'inbox.relabel_offer_queue', 'thread_id' => $tid, 'dry' => 0]);
qn((int) ($live['queued'] ?? 0) === 1 && ($live['reason'] ?? '') !== 'redan skickad' && count($GLOBALS['_SENT']) === 0, 'den namngivna tråden köas en gång');

echo "OK  offertanteckning\n";
