<?php

declare(strict_types=1);

/**
 * kontakter.relink_case när tråden saknar contact_id och kopplingen bara finns via e-post
 * (som g-79c953464ee3d504 live). Samma uppslag som Inkorg/prefill: jeab_reminder_case_contact
 * på jeab_reminder_case_bundle. Write sätter contact_id och inkorg_list-spegeln. Påhittade personer.
 */

$tmp = sys_get_temp_dir() . '/jeab-relink-email-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/inkorg_contacts.php';
require_once dirname(__DIR__) . '/src/kontakter_relink_case.php';

function rc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
rc($store instanceof JeabPdoStore, 'sqlite-lagret finns');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');
rc(jeab_contact_store() instanceof JeabPdoContactStore, 'kontakter i SQLite');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
rc(str_contains($hook, "'kontakter.relink_case'") && str_contains($hook, 'jeab_kontakter_relink_case_dispatch'), 'hook.php registrerar kontakter.relink_case');

$tokenDir = dirname(jeab_inkorg_token_path());
if (!is_dir($tokenDir)) {
    mkdir($tokenDir, 0775, true);
}
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-relink']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$denied = jeab_kontakter_relink_case_dispatch(['thread_id' => 'x']);
rc(($denied['status'] ?? 0) === 401, 'utan token ger 401');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-relink';

$wrong = 'cara.prov@example.test';
$ing = jeab_inkorg_ingest([
    'message_id' => 'relink-email-1@example.test',
    'from' => $wrong,
    'from_name' => 'Cara Prov',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Solceller',
    'body_text' => "Hej, jag vill ha en offert.\n",
    'source' => 'graph-jarfalla',
    'thread_key' => 'relink-email-thread',
    'test' => true,
]);
rc(!empty($ing['ok']), 'tråden finns');
$tid = (string) ($ing['thread_id'] ?? '');

[$from, $other] = jeab_kontakter_mutate(static function (array &$contacts) use ($wrong): array {
    $a = jeab_kontakt_blank(['name' => 'Matt Prov', 'email' => $wrong, 'street' => 'Provgatan 134', 'street_key' => jeab_kontakt_street_key('Provgatan 134'), 'postal_code' => '19592', 'city' => 'Märsta', 'company' => 'jarfalla']);
    $a['emails'][] = 'matt.prov@example.test';
    $contacts[$a['id']] = $a;
    $b = jeab_kontakt_blank(['name' => 'Annan Person', 'email' => 'annan2@example.test']);
    $contacts[$b['id']] = $b;
    return [$a['id'], $b['id']];
});
// Som live: inget sparat contact_id, bara kundens e-post på tråden.
jeab_inkorg_thread_write($tid, static function (array $t) use ($wrong): array {
    $t['contact_id'] = '';
    $t['customer_email'] = $wrong;
    return $t;
});
rc((string) (jeab_inkorg_thread_meta($tid)['contact_id'] ?? 'x') === '', 'tråden har inget contact_id');
$page = jeab_reminder_case_contact(jeab_reminder_case_bundle($tid));
rc(is_array($page) && (string) ($page['id'] ?? '') === $from, 'Inkorg/prefill visar from via e-post');

$listContact = static function () use ($tid): ?string {
    $s = function_exists('jeab_inkorg_pdo_store') ? jeab_inkorg_pdo_store() : null;
    if (!$s instanceof JeabPdoInkorgStore) {
        return null;
    }
    $st = $s->pdo()->prepare('SELECT row_json FROM inkorg_list WHERE id = ?');
    $st->execute([$tid]);
    $raw = $st->fetchColumn();
    $row = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($row) ? (string) ($row['contact_id'] ?? '') : null;
};

$args = [
    'thread_id' => $tid,
    'from_contact_id' => $from,
    'remove_email' => $wrong,
    'new_name' => 'Cara Prov',
    'new_email' => $wrong,
    'new_address' => 'Provgatan 134',
    'postnummer' => '195 92',
    'ort' => 'Märsta',
];
$bad = jeab_kontakter_relink_case_dispatch(['from_contact_id' => $other] + $args);
rc(($bad['ok'] ?? true) === false && ($bad['thread_link']['via'] ?? '') === 'page' && ($bad['thread_link']['resolved'] ?? '') === $from, 'annan from stoppas och visar e-postkopplingen');

$dry = jeab_kontakter_relink_case_dispatch($args);
rc(($dry['ok'] ?? false) === true && $dry['dry'] === true && $dry['would_change'] === 1, 'dry går igenom utan contact_id: ' . json_encode($dry['error'] ?? ''));
rc($dry['thread']['before'] === '' && $dry['thread']['after'] === '(ny)' && $dry['thread']['link_before'] === ['contact_id' => '', 'via' => 'page', 'resolved' => $from], 'dry visar kopplingen via e-post');
rc($dry['contacts']['from']['after']['emails'] === ['matt.prov@example.test'], 'dry visar from efter');
rc((string) (jeab_inkorg_thread_meta($tid)['contact_id'] ?? 'x') === '', 'dry rör inte tråden');

$GLOBALS['_JEAB_PRIVAT_DIR'] = $tmp . '/privat';
unset($GLOBALS['_JEAB_CUTOVER_TREE_ZIP']);
$wet = jeab_kontakter_relink_case_dispatch($args + ['write' => 1]);
rc(($wet['ok'] ?? false) === true && $wet['written'] === true, 'write går igenom: ' . json_encode($wet['error'] ?? ''));
$newId = (string) $wet['new_contact_id'];
rc(str_starts_with($newId, 'kt_') && $newId !== $from, 'ny kontakt skapad');
rc((string) (jeab_inkorg_thread_meta($tid)['contact_id'] ?? '') === $newId && $wet['thread']['after'] === $newId, 'tråden får contact_id (enda källan)');
$mirror = $listContact();
rc($mirror === $newId, 'inkorg_list-spegeln pekar på nya kontakten');
rc(($wet['list_contact_id'] ?? '') === $newId, 'svaret visar listans contact_id');
rc(!in_array($wrong, jeab_kontakter_relink_case_emails(jeab_kontakt_get($from)), true), 'adressen borta från from');
$page = jeab_reminder_case_contact(jeab_reminder_case_bundle($tid));
rc(is_array($page) && (string) ($page['id'] ?? '') === $newId, 'Inkorg/prefill visar nya kontakten');

$again = jeab_kontakter_relink_case_dispatch($args + ['write' => 1]);
rc(($again['ok'] ?? false) === true && $again['already'] === 1 && $again['changed'] === 0, 'andra körningen gör inget');

// Avbruten körning: kontakterna klara men tråden utan contact_id. Då pinnas tråden, ingen ny kontakt.
jeab_inkorg_thread_write($tid, static function (array $t): array {
    $t['contact_id'] = '';
    return $t;
});
$count = count(jeab_kontakter_load());
$resume = jeab_kontakter_relink_case_dispatch($args + ['write' => 1]);
rc(($resume['ok'] ?? false) === true && ($resume['reuse_existing'] ?? 0) === 1 && $resume['new_contact_id'] === $newId, 'återupptag återanvänder kontakten');
rc(count(jeab_kontakter_load()) === $count && (string) (jeab_inkorg_thread_meta($tid)['contact_id'] ?? '') === $newId, 'återupptag sätter bara contact_id');

echo "Alla relink_case e-postkopplingstester gick igenom.\n";
