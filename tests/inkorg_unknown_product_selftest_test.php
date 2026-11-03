<?php

declare(strict_types=1);

/** step=unknown_product. Påhittade adresser. Inget riktigt kundmejl. */

$tmp = sys_get_temp_dir() . '/jeab-unknown-selftest-' . bin2hex(random_bytes(4));
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

function up(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['_GRAPH_URLS'] = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url) {
    $GLOBALS['_GRAPH_URLS'][] = $method . ' ' . $url;
    if (str_contains($url, 'mailFolders/sentitems?')) {
        return ['code' => 200, 'body' => ['id' => 'sent-folder']];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};
$GLOBALS['JEAB_GRAPH_SEND'] = function (array $a) {
    $GLOBALS['_SENT'][] = $a;
    $person = trim((string) ($a['opts']['person_email'] ?? ''));
    $from = $person !== '' ? $person : jeab_graph_mailboxes()[$a['company']];
    return ['ok' => true, 'from' => $from, 'graph_id' => 'g-up', 'message_id' => 'selftest-unknown@local', 'path' => 'users/' . rawurlencode($from) . '/sendMail'];
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
$settingsPath = jeab_inkorg_dir() . '/settings.json';
$settings = json_decode((string) file_get_contents($settingsPath), true);
unset($settings['unknown_product_ask_enabled']);
file_put_contents($settingsPath, json_encode($settings));
$beforeSettings = (string) file_get_contents($settingsPath);
up(jeab_auto_settings()['unknown_product_ask'] === false, 'saknad brytare är av');

$injected = 'empty.form@exempel.se';
$st = jeab_auto_selftest([
    'step' => 'unknown_product',
    'deliver_to' => $injected,
    'email' => $injected,
    'to' => $injected,
    'from' => $injected,
    'text' => "Namn: Någon\nE-post: {$injected}\nGäller det solceller?\n",
]);
$fixed = jeab_auto_selftest_fixed_recipient();
$expect = 'Hej! Tack för att du hörde av dig. Gäller det solceller, batteri eller Elbilsladdare?';
$sent = $GLOBALS['_SENT'][0] ?? [];
up(($st['ok'] ?? false) === true, 'självtestet svarar ok');
up(($st['message_id'] ?? '') === 'selftest-unknown@local', 'självtestet lämnar message id');
up(($st['path'] ?? '') === 'users/' . rawurlencode((string) ($st['sender'] ?? '')) . '/sendMail', 'självtestet lämnar sökvägen');
up(($st['sender'] ?? '') === 'demo@example.test' && ($sent['opts']['person_email'] ?? '') === 'demo@example.test', 'avsändaren är den ansvariga brevlådan');
up(($st['subject'] ?? '') === 'Gäller det solceller, batteri eller Elbilsladdare?' && ($st['body'] ?? '') === $expect && ($sent['body'] ?? '') === $expect && ($sent['html'] ?? true) === false, 'brödtexten är exakt svarsreglertexten');
up(($sent['to'] ?? '') === $fixed && ($st['body'] ?? '') !== '' && !str_contains((string) ($sent['to'] ?? ''), $injected) && !str_contains((string) ($sent['body'] ?? ''), $injected), 'mottagaren är alltid självtestets fasta adress');
$graphHit = false;
foreach ($GLOBALS['_GRAPH_URLS'] as $url) {
    if (str_contains($url, rawurlencode($injected)) || str_contains($url, $injected)) {
        $graphHit = true;
    }
}
up($graphHit === false, 'självtestet räknas inte som redan skickat till den injicerade adressen');
up(in_array($fixed, jeab_auto_selftest_addresses(), true), 'fasta adressen finns i självtestets lista');
up((string) file_get_contents($settingsPath) === $beforeSettings && jeab_auto_settings()['unknown_product_ask'] === false, 'brytaren och settings.json är orörda');

$tid = (string) ($st['thread'] ?? '');
up(str_starts_with($tid, 'autotest-'), 'ärendet är ett autotest-');
$testRem = null;
$foreign = 0;
foreach (jeab_reminders_every(jeab_store()) as $rem) {
    if (!is_array($rem)) {
        continue;
    }
    if (($rem['email'] ?? '') === $injected) {
        $foreign++;
    }
    if (str_starts_with((string) ($rem['title'] ?? ''), 'TEST') && str_contains((string) ($rem['note'] ?? ''), $tid)) {
        $testRem = $rem;
    }
}
up($foreign === 0 && is_array($testRem) && ($testRem['email'] ?? '') === $fixed, 'påminnelsen är TEST och inte den injicerade adressen');

$clean = jeab_auto_selftest(['step' => 'cleanup', 'thread' => $tid]);
$left = false;
foreach (jeab_reminders_every(jeab_store()) as $rem) {
    if (is_array($rem) && str_contains((string) ($rem['note'] ?? ''), $tid)) {
        $left = true;
    }
}
up(($clean['ok'] ?? false) === true && ($clean['reminders'] ?? 0) >= 1 && !$left && jeab_auto_thread_by_id($tid) === null, 'cleanup tar bort ärendet och TEST-påminnelsen');

$before = count($GLOBALS['_SENT']);
$box = jeab_graph_mailboxes()['jarfalla'];
$normal = jeab_inkorg_ingest([
    'message_id' => 'normal-empty@example.test',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'to' => $box,
    'subject' => 'Formulär',
    'body_text' => "Namn:\n\nE-post: {$injected}\nTelefon:\n\nMeddelande:\n",
    'source' => 'graph-jarfalla',
    'thread_key' => 'normal-empty-form',
    'graph_mailbox' => $box,
    'company' => 'jarfalla',
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 40,
        'intent' => '', 'offer' => '', 'product' => '', 'question' => '',
    ],
]);
$flow = jeab_auto_on_message((string) $normal['id']);
up(count($GLOBALS['_SENT']) === $before && ($flow['sent'] ?? 1) === 0 && ($flow['action'] ?? '') !== 'asked', 'avstängd brytare skickar inget i det vanliga flödet');
up(jeab_auto_settings()['unknown_product_ask'] === false, 'det vanliga flödet tänder inte brytaren');

echo "OK  unknown product selftest\n";
