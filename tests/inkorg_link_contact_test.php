<?php

declare(strict_types=1);

/**
 * inbox.link_contact: dry=1 visar länk eller ny kontakt och skriver inget.
 * dry=0 anropar jeab_reminder_case_link_contact och sparar undo_id.
 * Automatiken länkar kontakten innan ärendet stängs efter utskick, så
 * CONTACTS_STRICT inte stoppar stängningen. Inget skickas mot nätet.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-link-contact-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('CONTACTS_STRICT=1');
putenv('CONTACTS_SOLE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function lc(bool $ok, string $m): void
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

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g-lc', 'conversation_id' => 'c-lc', 'message_id' => 'm-lc@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn (): array => ['code' => 200, 'body' => ['value' => []]];
$GLOBALS['_JEAB_CONTACTS_STRICT'] = false;

lc(jeab_settings_put(jeab_store()->pdo(), 'sqlite', 'inkorg_storage', 'sql'), 'inkorgen ligger i SQL');
lc(function_exists('jeab_inkorg_link_contact') && function_exists('jeab_auto_link_contact_before_close'), 'hook och förlänk finns');
$rules = implode("\n", jeab_auto_rule_lines());
lc(str_contains($rules, 'inbox.link_contact') && str_contains($rules, 'jeab_reminder_case_link_contact'), 'regeln nämner hooken');
$auto = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_auto.php');
$quoteFn = '';
if (preg_match('/function jeab_auto_quote\(.*?^}/ms', $auto, $m) === 1) {
    $quoteFn = $m[0];
}
lc(substr_count($quoteFn, 'jeab_auto_link_contact_before_close') >= 2, 'offertflödet länkar före båda stängningarna');
lc(str_contains($auto, "function jeab_auto_answer_only") && substr_count($auto, 'jeab_auto_link_contact_before_close') >= 4, 'kortsvar länkar också före stängning');

$ingest = static function (string $id, string $name, string $email, string $phone): string {
    $res = jeab_inkorg_ingest([
        'message_id' => $id . '@link.test',
        'from' => $email,
        'from_name' => $name,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Offert batteri',
        'body_text' => "Namn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nAdress: Länkgatan 2, 176 70 Järfälla\nProdukt: Batteri\n",
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'thread_key' => $id,
        'preset_labels' => [
            'type' => 'Ny lead', 'intent' => 'kop', 'confidence' => 95,
            'offer' => 'batteri', 'product' => 'batteri', 'needs_review' => false,
        ],
    ]);

    return (string) ($res['thread_id'] ?? '');
};

$contactCount = static fn (): int => count(jeab_kontakter_load());
$threadContact = static fn (string $tid): string => trim((string) (jeab_inkorg_thread_meta($tid)['contact_id'] ?? ''));

$newTid = $ingest('lc-new', 'Maja Länk', 'maja.link@gmail.com', '070-555 10 01');
lc($newTid !== '' && $threadContact($newTid) === '', 'nytt ärende saknar kontakt');
$before = $contactCount();
$dry = jeab_inkorg_hook(['action' => 'inbox.link_contact', 'thread_id' => $newTid]);
lc(($dry['ok'] ?? false) === true && (int) ($dry['dry'] ?? 0) === 1, 'dry är standard');
lc(($dry['undo_id'] ?? '') === '' && ($dry['would_send'] ?? true) === false && (int) ($dry['sent'] ?? 1) === 0, 'dry sparar ingen undo och skickar inget');
lc($contactCount() === $before && $threadContact($newTid) === '', 'dry skriver varken kontakt eller ärende');
$prefetch = (string) ($dry['contact_id'] ?? '');
if ($prefetch !== '') {
    jeab_store()->pdo()->prepare('DELETE FROM contacts WHERE id = ?')->execute([$prefetch]);
    unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
}
$before = $contactCount();
$dry = jeab_inkorg_hook(['action' => 'inbox.link_contact', 'thread_id' => $newTid]);
lc(($dry['result'] ?? '') === 'create' && (int) ($dry['would_create'] ?? 0) === 1 && (int) ($dry['would_link'] ?? 0) === 0 && ($dry['contact_id'] ?? '') === '', 'dry visar att kontakten skulle skapas');
lc($contactCount() === $before && $threadContact($newTid) === '', 'förhandsvisningen skapar inget');

$wet = jeab_inkorg_hook(['action' => 'inbox.link_contact', 'thread_id' => $newTid, 'dry' => 0]);
lc(($wet['ok'] ?? false) === true && ($wet['result'] ?? '') === 'create' && (int) ($wet['created'] ?? 0) === 1, 'dry=0 skapar kontakten');
lc($threadContact($newTid) === (string) ($wet['contact_id'] ?? '') && (string) ($wet['contact_id'] ?? '') !== '', 'dry=0 sätter contact_id');
$undo = (string) ($wet['undo_id'] ?? '');
lc(preg_match('/\Alc_[a-f0-9]{16}\z/', $undo) === 1, 'dry=0 sparar undo_id');
$undoPath = jeab_inkorg_link_contact_undo_dir() . '/' . $undo . '.json';
lc(is_file($undoPath) && (fileperms($undoPath) & 0777) === 0600, 'ångerfilen är 0600');
$again = jeab_inkorg_hook(['action' => 'inkorg.link_contact', 'thread_id' => $newTid]);
lc(($again['result'] ?? '') === 'already' && ($again['undo_id'] ?? '') === '', 'redan länkad rörs inte');

$known = jeab_kontakt_find_or_create([
    'name' => 'Olle Känd',
    'email' => 'olle.kand@gmail.com',
    'phone' => '0705551002',
    'street' => 'Länkgatan 2',
    'postal_code' => '17670',
    'city' => 'Järfälla',
    'source' => 'inkorg',
], true, false, true);
$knownId = is_array($known) ? (string) ($known['id'] ?? '') : '';
lc($knownId !== '', 'känd kontakt finns');
$knownCount = $contactCount();
$linkTid = $ingest('lc-known', 'Olle Känd', 'olle.kand@gmail.com', '070-555 10 02');
$linkDry = jeab_inkorg_hook(['action' => 'inbox.link_contact', 'thread_id' => $linkTid, 'dry' => 1]);
lc(($linkDry['result'] ?? '') === 'link' && ($linkDry['contact_id'] ?? '') === $knownId && (int) ($linkDry['would_link'] ?? 0) === 1, 'dry visar befintlig kontakt');
lc($contactCount() === $knownCount && $threadContact($linkTid) === '', 'dry länkar inte på riktigt');
$linkWet = jeab_inkorg_hook(['action' => 'inbox.link_contact', 'thread_id' => $linkTid, 'dry' => '0']);
lc(($linkWet['result'] ?? '') === 'link' && (int) ($linkWet['created'] ?? 1) === 0 && $threadContact($linkTid) === $knownId, 'dry=0 länkar utan att skapa');
lc(preg_match('/\Alc_[a-f0-9]{16}\z/', (string) ($linkWet['undo_id'] ?? '')) === 1, 'länk sparar också undo_id');
lc($contactCount() === $knownCount, 'ingen extra kontakt');

$missing = jeab_inkorg_hook(['action' => 'inbox.link_contact', 'thread_id' => 'saknas-helt']);
lc(($missing['ok'] ?? true) === false && ($missing['error'] ?? '') === 'ärende saknas', 'okänt ärende nekas');

$strictTid = $ingest('lc-strict', 'Stina Strict', 'stina.strict@gmail.com', '070-555 10 03');
$rem = jeab_create_reminder(jeab_store(), 'Stina Strict – Batteri', jeab_today_start(), 'mail: https://flowstack.example.test/index.php?p=inkorg', null, [
    'customer' => 'Stina Strict',
    'email' => 'stina.strict@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'phone' => '0705551003',
    'address' => 'Striktgatan 1, 176 70 Järfälla',
    'thread_id' => $strictTid,
    'lookup' => false,
]);
lc((int) ($rem['id'] ?? 0) > 0, 'påminnelsen pekar på ärendet');
jeab_inkorg_thread_write($strictTid, static function (array $t): array {
    $t['contact_id'] = '';
    $t['status'] = 'open';

    return $t;
});
lc($threadContact($strictTid) === '' && (string) (jeab_inkorg_thread_meta($strictTid)['status'] ?? '') === 'open', 'ärendet är öppet och olänkat igen');
$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;
lc(jeab_contacts_strict_active(), 'CONTACTS_STRICT är på');
$threw = false;
try {
    jeab_inkorg_thread_set($strictTid, 'closed', null);
} catch (Throwable $e) {
    $threw = $e instanceof InvalidArgumentException && $e->getMessage() === 'Ärendet har en påminnelse men saknar kontakt.';
}
lc($threw && (string) (jeab_inkorg_thread_meta($strictTid)['status'] ?? '') === 'open', 'stängning utan kontakt nekas');
jeab_auto_link_contact_before_close($strictTid);
lc($threadContact($strictTid) !== '', 'förlänken sätter kontakten');
jeab_inkorg_thread_set($strictTid, 'closed', null);
lc((string) (jeab_inkorg_thread_meta($strictTid)['status'] ?? '') === 'closed', 'stängning efter länk går igenom');

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 link\n")]);
require __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();
$sendTid = $ingest('lc-send', 'Vera Utskick', 'vera.utskick@gmail.com', '070-555 10 04');
lc($threadContact($sendTid) === '', 'utskicksärendet saknar kontakt');
$sent = jeab_auto_send_quote(['thread_id' => $sendTid, 'dry' => 0]);
lc(($sent['action'] ?? '') === 'quoted', 'utskicket stänger ärendet, fick ' . (string) ($sent['action'] ?? '') . ' ' . (string) ($sent['why'] ?? ''));
lc((string) (jeab_inkorg_thread_meta($sendTid)['status'] ?? '') === 'closed', 'ärendet är stängt');
lc($threadContact($sendTid) !== '', 'utskicket länkar kontakten före stängning');
lc($GLOBALS['_SENT'] === [1], 'ett mejl gick via mocken');

echo "Alla link_contact-tester OK\n";
