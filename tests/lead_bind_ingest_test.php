<?php

declare(strict_types=1);

/**
 * Testlead får egen kontakt. Formulärrubrik sätter inte produkt.
 * Syskon stängs inte före lead-kroken, och inbox.ingest dry=1 skriver inget.
 * Påhittade namn. Inget utskick.
 */

$tmp = sys_get_temp_dir() . '/jeab-lead-bind-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
unset($_ENV['XAI_API_KEY'], $_SERVER['XAI_API_KEY']);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function lb(bool $ok, string $message): void
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

$store = jeab_store();
$phone = '070-111 22 33';
$street = 'Provgatan 8, 177 32 Järfälla';
$old = jeab_kontakt_find_or_create([
    'name' => 'Anna Kund',
    'email' => 'anna.kund.leadfix@gmail.com',
    'phone' => $phone,
    'address' => $street,
], false, false);
lb(is_array($old) && (string) ($old['id'] ?? '') !== '', 'gammal kontakt skapas');
$oldId = (string) $old['id'];

$test = jeab_kontakt_find_or_create([
    'name' => 'Test Laddare3',
    'email' => 'jeab+laddare3@mail.grokbot.com',
    'phone' => $phone,
    'address' => $street,
], false, false);
lb(is_array($test) && (string) ($test['id'] ?? '') !== $oldId, 'testlead får en egen kontakt');
lb(jeab_kontakt_row_email($test) === 'jeab+laddare3@mail.grokbot.com', 'testlead behåller grokbot-adressen');
lb(jeab_kontakt_row_name(jeab_kontakt_get($oldId) ?: []) === 'Anna Kund', 'gamla kontakten byter inte namn');

$rem = $store->insertReminder([
    'title' => 'Test Laddare3 – Elbilsladdare',
    'customer_name' => 'Test Laddare3',
    'email' => 'jeab+laddare3@mail.grokbot.com',
    'phone' => $phone,
    'address' => $street,
    'company' => 'jarfalla',
    'due_at' => jeab_today_start(),
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$bound = jeab_reminder_bind_contact($store, $rem, [
    'name' => 'Test Laddare3',
    'email' => 'jeab+laddare3@mail.grokbot.com',
    'phone' => $phone,
    'address' => $street,
]);
$boundId = (string) ($bound['contact_id'] ?? '');
lb($boundId !== '' && $boundId !== $oldId, 'påminnelsen kopplas inte till den gamla kontakten');
lb(jeab_kontakt_row_name(jeab_kontakt_get($oldId) ?: []) === 'Anna Kund', 'anteckningen byter inte den gamla kontakten');
lb(jeab_kontakt_row_email(jeab_kontakt_get($boundId) ?: []) === 'jeab+laddare3@mail.grokbot.com', 'kopplad kontakt har testleadets e-post');

$erik = jeab_kontakt_find_or_create([
    'name' => 'Erik Berg',
    'email' => 'erik.berg.leadfix@gmail.com',
    'phone' => '070-222 33 44',
], false, false);
lb(is_array($erik), 'Erik skapas utan gata');
$maja = jeab_kontakt_find_or_create([
    'name' => 'Maja Holm',
    'email' => 'maja.holm.leadfix@gmail.com',
    'phone' => '070-222 33 44',
], false, false);
lb(is_array($maja) && (string) ($maja['id'] ?? '') !== (string) ($erik['id'] ?? ''), 'telefon med annat namn blir en egen kontakt');
lb(jeab_kontakt_row_name(jeab_kontakt_get((string) $erik['id']) ?: []) === 'Erik Berg', 'Eriks namn ligger kvar');
$erikAgain = jeab_kontakt_find_or_create([
    'name' => 'Erik',
    'email' => 'erik.igen.leadfix@gmail.com',
    'phone' => '070-222 33 44',
], false, false);
lb(is_array($erikAgain) && (string) ($erikAgain['id'] ?? '') === (string) $erik['id'], 'samma telefon utan adress återanvänder kontakten');
lb(jeab_kontakt_row_name(jeab_kontakt_get((string) $erik['id']) ?: []) === 'Erik Berg', 'Eriks kontakt byter inte namn');

$form = static function (string $title): string {
    return "A site visitor just submitted your form {$title}\n\nNamn:\n\nTest Laddare3\n\nE-post:\n\njeab+laddare3@mail.grokbot.com\n\nTelefon:\n\n070-111 22 33\n";
};
$ladbox = [
    'from' => 'jeab@mail.grokbot.com',
    'from_name' => 'JEAB',
    'subject' => 'JEAB Ladbox lead',
    'body' => $form('Ladbox lead'),
];
$classified = jeab_inkorg_classify($ladbox);
lb((string) ($classified['type'] ?? '') === 'Oklassad', 'test från egen post blir inte Ny lead av formulärrubriken, fick ' . (string) ($classified['type'] ?? ''));
lb((string) ($classified['offer'] ?? '') === '' && (string) ($classified['product'] ?? '') === '', 'formulärrubriken sätter inte produkt');
lb(!empty($classified['is_test']) && jeab_inkorg_klassning_own($ladbox), 'avsändaren är test och egen post');

foreach (['Batteri lead', 'Solceller lead', 'Sol+batteri', 'Laddbox lead'] as $title) {
    $one = jeab_inkorg_classify([
        'from' => 'test.produkt@mail.grokbot.com',
        'from_name' => 'Test Produkt',
        'subject' => 'JEAB ' . $title,
        'body' => $form($title),
    ]);
    lb((string) ($one['type'] ?? '') !== 'Ny lead' && (string) ($one['offer'] ?? '') === '', $title . ' lämnar produkt till modellen, fick ' . (string) ($one['type'] ?? '') . '/' . (string) ($one['offer'] ?? ''));
}
$kontakt = jeab_inkorg_classify([
    'from' => 'luis.form.leadfix@gmail.com',
    'from_name' => 'Luis Form',
    'subject' => 'JEAB Kontakt Formulär',
    'body' => "A site visitor just submitted your form: Kontakt Formulär\n\nNamn:\n\nLuis Form\n",
]);
lb((string) ($kontakt['type'] ?? '') !== 'Ny lead', 'Kontakt Formulär utan produkt är inte Ny lead');

$before = count(jeab_inkorg_messages());
$dry = jeab_inkorg_hook([
    'action' => 'inbox.ingest',
    'dry' => 1,
    'message' => [
        'message_id' => 'dry-laddare3@lead.test',
        'from' => 'jeab@mail.grokbot.com',
        'from_name' => 'JEAB',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'JEAB Ladbox lead',
        'body_text' => $form('Ladbox lead'),
        'source' => 'graph-jarfalla',
    ],
]);
lb(($dry['ok'] ?? false) === true && (int) ($dry['dry'] ?? 0) === 1 && ($dry['written'] ?? true) === false && ($dry['would_send'] ?? true) === false, 'dry=1 svarar utan att skriva');
lb((string) ($dry['labels']['type'] ?? '') !== 'Ny lead' && (string) ($dry['labels']['offer'] ?? '') === '', 'dry=1 sätter inte produkt från rubriken');
lb(jeab_inkorg_get((string) ($dry['id'] ?? '')) === null && count(jeab_inkorg_messages()) === $before, 'dry=1 lämnar inkorgen orörd');
lb($GLOBALS['_SENT'] === [], 'dry=1 skickar inget');

$wet = jeab_inkorg_ingest([
    'message_id' => 'wet-laddare3@lead.test',
    'from' => 'jeab+laddare3@mail.grokbot.com',
    'from_name' => 'Test Laddare3',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'JEAB Ladbox lead',
    'body_text' => $form('Ladbox lead'),
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
]);
lb(!empty($wet['ok']) && (string) ($wet['labels']['offer'] ?? '') === '', 'intag sparar mejlet utan produkt från rubriken');
lb(count(jeab_inkorg_messages()) === $before + 1, 'intag utan dry skriver mejlet');

$oldRem = $store->insertReminder([
    'title' => 'Tidigare batteri',
    'customer_name' => 'Test Batteri3',
    'email' => 'jeab+batteri3@mail.grokbot.com',
    'phone' => '070-333 44 55',
    'address' => 'Annan gata 1',
    'company' => 'jarfalla',
    'due_at' => jeab_today_start(),
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
]);
$batteri = jeab_inkorg_ingest([
    'message_id' => 'wet-batteri3@lead.test',
    'from' => 'jeab+batteri3@mail.grokbot.com',
    'from_name' => 'Test Batteri3',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'JEAB Batteri lead',
    'body_text' => "A site visitor just submitted your form Batteri lead\n\nNamn:\n\nTest Batteri3\n\nE-post:\n\njeab+batteri3@mail.grokbot.com\n\nTelefon:\n\n070-333 44 55\n\nAdress:\n\nBatterivägen 3, 177 32 Järfälla\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'preset_labels' => [
        'type' => 'Ny lead',
        'action' => 'svara',
        'priority' => 1,
        'confidence' => 90,
        'source' => 'llm',
        'intent' => 'kop',
        'offer' => 'batteri',
        'needs_review' => false,
    ],
]);
$tid = (string) ($batteri['thread_id'] ?? '');
$meta = jeab_inkorg_thread_meta($tid);
lb($tid !== '' && is_array($meta) && (string) ($meta['status'] ?? '') === 'open', 'nytt batterilead är öppet');
$closed = jeab_inkorg_close_open_for_reminder($oldRem, true);
lb(!in_array($tid, $closed, true), 'syskonstängning tar inte leadeet före kroken');
jeab_inkorg_close_open_for_thread($tid);
$still = jeab_inkorg_thread_meta($tid);
lb(is_array($still) && (string) ($still['status'] ?? '') === 'open', 'tråden är öppen efter close_open_for_thread');
$hook = jeab_lead_hook(['dry' => 0, 'thread' => $tid]);
lb(($hook['ok'] ?? false) === true, 'lead-kroken körs');
$linked = false;
foreach (jeab_list_reminders($store, false) as $row) {
    if (!is_array($row)) {
        continue;
    }
    $note = (string) ($row['note'] ?? '');
    $rowTid = (string) ($row['thread_id'] ?? '');
    if ($rowTid === $tid || ($tid !== '' && str_contains($note, $tid))) {
        $linked = true;
        break;
    }
}
lb($linked, 'kroken ger batterileadet en påminnelse');
lb($GLOBALS['_SENT'] === [], 'ingen offert skickas');

echo "lead bind ingest ok\n";
