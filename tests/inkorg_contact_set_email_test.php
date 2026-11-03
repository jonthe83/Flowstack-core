<?php

declare(strict_types=1);

/** inbox.contact_set_email byter kundens adress. Partneradress nekas. Inget mejl. */

$tmp = sys_get_temp_dir() . '/jeab-contact-email-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function cse(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$mail = 0;
$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $opts) use (&$mail): array {
    unset($opts);
    $mail++;
    return ['ok' => true];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$mail): array {
    unset($opts);
    $mail++;
    return ['ok' => true];
};

$partner = 'hej@nordsolen.se';
$next = 'kim.fix@gmail.com';
$ing = jeab_inkorg_ingest([
    'message_id' => 'cse-1@partner.test',
    'from' => $partner,
    'from_name' => 'Nordsolen',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Ny lead',
    'body_text' => "Namn: Kim Exempel\nVi vill ha batteri.\n",
    'source' => 'graph-jarfalla',
    'thread_key' => 'cse-thread',
    'test' => true,
]);
cse(!empty($ing['ok']), 'tråden finns');
$tid = (string) ($ing['thread_id'] ?? '');
$made = jeab_kontakter_mutate(static function (array &$contacts) use ($partner): array {
    $row = jeab_kontakt_blank(['name' => 'Kim Exempel', 'email' => $partner]);
    $contacts[$row['id']] = $row;
    return $row;
});
$cid = (string) ($made['id'] ?? '');
cse($cid !== '', 'kontakten finns');
jeab_inkorg_thread_write($tid, static function (array $t) use ($partner, $cid): array {
    $t['customer_email'] = $partner;
    $t['contact_id'] = $cid;
    return $t;
});
file_put_contents(jeab_inkorg_dir() . '/contacts.json', json_encode([
    'contacts' => [
        'c-bad' => ['name' => 'Nordsolen', 'emails' => [$partner], 'mobile' => '', 'address' => '', 'town' => ''],
    ],
], JSON_UNESCAPED_UNICODE));
$row = jeab_create_reminder(jeab_store(), 'Kim Exempel – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Kim Exempel',
    'email' => $partner,
    'company' => 'jarfalla',
    'who' => 'Roberto',
]);
$rid = (int) ($row['id'] ?? 0);
jeab_store()->saveReminder($rid, ['contact_id' => $cid, 'email' => $partner, 'thread_id' => $tid]);
$auditFile = jeab_inkorg_dir() . '/contact-email.jsonl';

$missing = jeab_inkorg_hook(['action' => 'inbox.contact_set_email', 'email' => $next]);
cse(empty($missing['ok']) && ($missing['error'] ?? '') === 'thread_id eller reminder_id saknas' && ($missing['would_send'] ?? null) === false, 'id krävs');
$badMail = jeab_inkorg_hook(['action' => 'inbox.contact_set_email', 'thread_id' => $tid, 'email' => 'inte-en-adress', 'dry' => 0]);
cse(empty($badMail['ok']) && ($badMail['error'] ?? '') === 'ogiltig e-post', 'ogiltig adress skriver inget');
$gone = jeab_inkorg_hook(['action' => 'inbox.contact_set_email', 'thread_id' => 'saknas-trad', 'email' => $next, 'dry' => 0]);
cse(empty($gone['ok']) && ($gone['error'] ?? '') === 'not_found', 'okänd tråd skriver inget');

$refused = jeab_inkorg_hook([
    'action' => 'inbox.contact_set_email',
    'thread_id' => $tid,
    'reminder_id' => $rid,
    'email' => $partner,
    'dry' => 0,
]);
$stillPartner = jeab_get_reminder(jeab_store(), $rid);
cse(empty($refused['ok']) && ($refused['error'] ?? '') === 'partner' && ($refused['would_send'] ?? null) === false && is_array($stillPartner) && (string) ($stillPartner['email'] ?? '') === $partner, 'partneradress nekas');
cse((string) (jeab_inkorg_thread_meta($tid)['customer_email'] ?? '') === $partner, 'nekad partner lämnar tråden');
cse(jeab_inbox_contact_set_email_audit_list() === [] && !is_file($auditFile), 'nekad partner skriver ingen historik');

$portal = jeab_inkorg_hook(['action' => 'inbox.contact_set_email', 'thread_id' => $tid, 'email' => 'leads@hitta-solceller.se', 'dry' => 0]);
cse(empty($portal['ok']) && ($portal['error'] ?? '') === 'partner', 'Hitta-Solceller nekas');
$noreply = jeab_inkorg_hook(['action' => 'inbox.contact_set_email', 'reminder_id' => $rid, 'email' => 'noreply@okand-portal.example', 'dry' => 0]);
cse(empty($noreply['ok']) && ($noreply['error'] ?? '') === 'partner', 'no-reply nekas');

$dry = jeab_inkorg_hook([
    'action' => 'inbox.contact_set_email',
    'thread_id' => $tid,
    'reminder_id' => $rid,
    'email' => $next,
]);
$preview = jeab_get_reminder(jeab_store(), $rid);
cse(!empty($dry['dry']) && ($dry['changed'] ?? 1) === 0 && ($dry['would_change'] ?? 0) === 1 && ($dry['thread'] ?? 0) === 1 && ($dry['reminder'] ?? 0) === 1 && ($dry['inbox'] ?? 0) === 1 && ($dry['kontakt'] ?? 0) === 1 && ($dry['would_send'] ?? null) === false && (int) ($dry['audit']['audit_id'] ?? 1) === 0 && (string) ($dry['audit']['thread']['old'] ?? '') === $partner && (string) ($dry['audit']['thread']['new'] ?? '') === $next, 'torrkörning visar bytet och skriver inget');
cse(is_array($preview) && (string) ($preview['email'] ?? '') === $partner && (string) (jeab_inkorg_thread_meta($tid)['customer_email'] ?? '') === $partner, 'torrkörning skriver inget');
cse(jeab_inbox_contact_set_email_audit_list() === [] && !is_file($auditFile), 'torrkörning skriver ingen historik');

$wet = jeab_inkorg_hook([
    'action' => 'inbox.contact_set_email',
    'thread_id' => $tid,
    'reminder_id' => $rid,
    'email' => $next,
    'dry' => 0,
]);
$fresh = jeab_get_reminder(jeab_store(), $rid);
$contact = jeab_kontakt_get($cid);
$inbox = jeab_inkorg_contacts(true)['c-bad'] ?? [];
cse(($wet['changed'] ?? 0) === 1 && ($wet['would_change'] ?? 1) === 0 && ($wet['would_send'] ?? null) === false && (int) ($wet['audit']['audit_id'] ?? 0) > 0 && (string) ($wet['audit']['reminder']['old'] ?? '') === $partner && (string) ($wet['audit']['reminder']['new'] ?? '') === $next, 'skrivning byter och skickar inget');
cse((string) (jeab_inkorg_thread_meta($tid)['customer_email'] ?? '') === $next, 'tråden får kundens adress');
cse(is_array($fresh) && (string) ($fresh['email'] ?? '') === $next && (string) ($fresh['person'] ?? '') === 'Roberto', 'påminnelsen får kundens adress och behåller ägaren');
cse(is_array($inbox) && in_array($next, array_map('strval', (array) ($inbox['emails'] ?? [])), true) && !in_array($partner, array_map('strval', (array) ($inbox['emails'] ?? [])), true), 'inkorgskontakten byter adress');
cse(is_array($contact) && jeab_kontakt_row_email($contact) === $next, 'Kontakter-posten får kundens adress');
$rows = jeab_inbox_contact_set_email_audit_list();
$one = $rows[0] ?? [];
cse(count($rows) === 1 && (string) ($one['old_thread'] ?? '') === $partner && (string) ($one['old_reminder'] ?? '') === $partner && str_contains((string) ($one['old_inbox'] ?? ''), $partner) && str_contains((string) ($one['old_kontakt'] ?? ''), $partner) && (string) ($one['email'] ?? '') === $next, 'historiken i databasen behåller den gamla adressen');
cse(!is_file($auditFile), 'ingen jsonl-fil med kundadress');

$again = jeab_inkorg_hook([
    'action' => 'inbox.contact_set_email',
    'thread_id' => $tid,
    'reminder_id' => $rid,
    'email' => $next,
    'dry' => 0,
]);
cse(($again['changed'] ?? 1) === 0 && count(jeab_inbox_contact_set_email_audit_list()) === 1, 'samma adress en gång till skriver inget');
cse($mail === 0 && $GLOBALS['_SENT'] === [], 'inget mejl skickas');

$hsId = 'hs-88421';
$hsIng = jeab_inkorg_ingest([
    'message_id' => 'hs-promote-88421-m1',
    'from' => $partner,
    'from_name' => 'Nordsolen',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Ny förfrågan batteri',
    'body_text' => "Namn: Kim Exempel\nVi vill ha batteri.\n",
    'source' => 'helpscout_import',
    'thread_key' => $hsId,
    'hs_conversation_id' => '88421',
    'test' => true,
]);
cse(!empty($hsIng['ok']) && (string) ($hsIng['thread_id'] ?? '') === $hsId, 'promote-tråd hs-88421 finns');
$hsMsgId = (string) ($hsIng['id'] ?? '');
jeab_inkorg_thread_write($hsId, static function (array $t) use ($next): array {
    $t['status'] = 'open';
    $t['import_source'] = 'helpscout_import';
    $t['helpscout_import'] = true;
    $t['no_auto'] = true;
    $t['customer_email'] = $next;
    $t['customer_source'] = 'from';
    $hs = is_array($t['hs'] ?? null) ? $t['hs'] : [];
    $hs['id'] = '88421';
    $t['hs'] = $hs;
    return $t;
});
$hsMade = jeab_kontakter_mutate(static function (array &$contacts) use ($partner): array {
    $row = jeab_kontakt_blank(['name' => 'Kim Exempel', 'email' => $partner]);
    $contacts[$row['id']] = $row;
    return $row;
});
$hsCid = (string) ($hsMade['id'] ?? '');
jeab_inkorg_thread_write($hsId, static function (array $t) use ($hsCid): array {
    $t['contact_id'] = $hsCid;
    return $t;
});
$hsContacts = jeab_inkorg_contacts(true);
$hsContacts['c-hs'] = ['name' => 'Nordsolen', 'emails' => [$partner], 'mobile' => '', 'address' => '', 'town' => ''];
file_put_contents(jeab_inkorg_dir() . '/contacts.json', json_encode(['contacts' => $hsContacts], JSON_UNESCAPED_UNICODE));
$hsRow = jeab_create_reminder(jeab_store(), 'Kim Exempel – Batteri hs', jeab_today_start(), null, null, [
    'customer' => 'Kim Exempel',
    'email' => $next,
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'E-post: ' . $partner,
]);
$hsRid = (int) ($hsRow['id'] ?? 0);
jeab_store()->saveReminder($hsRid, ['contact_id' => $hsCid, 'email' => $next, 'thread_id' => $hsId, 'note' => 'E-post: ' . $partner]);
$beforeMeta = (string) (jeab_inkorg_thread_meta($hsId)['customer_email'] ?? '');
$beforeMsg = jeab_inkorg_get($hsMsgId);
$beforeRem = jeab_get_reminder(jeab_store(), $hsRid);
$hsDry = jeab_inkorg_hook([
    'action' => 'inbox.contact_set_email',
    'thread_id' => $hsId,
    'reminder_id' => $hsRid,
    'email' => $next,
]);
$afterDryMsg = jeab_inkorg_get($hsMsgId);
$afterDryRem = jeab_get_reminder(jeab_store(), $hsRid);
cse(($hsDry['changed'] ?? 1) === 0 && ($hsDry['would_change'] ?? 0) === 1 && ($hsDry['thread'] ?? 0) === 1 && ($hsDry['reminder'] ?? 0) === 1 && ($hsDry['inbox'] ?? 0) === 1 && ($hsDry['kontakt'] ?? 0) === 1, 'hs-tråd: torrkörning skulle byta det sidan visar');
cse((int) ($hsDry['audit']['audit_id'] ?? 1) === 0 && (string) ($hsDry['audit']['thread']['old'] ?? '') === $partner && (string) ($hsDry['audit']['thread']['new'] ?? '') === $next && (string) ($hsDry['audit']['reminder']['old'] ?? '') === $partner, 'hs-tråd: audit visar old/new utan audit_id');
cse($beforeMeta === $next && (string) (jeab_inkorg_thread_meta($hsId)['lead_customer']['email'] ?? '') === '' && (string) ($afterDryMsg['from'] ?? '') === $partner && (string) ($afterDryMsg['customer_email'] ?? '') === '' && (string) ($afterDryRem['note'] ?? '') === (string) ($beforeRem['note'] ?? '') && jeab_kontakt_row_email(jeab_kontakt_get($hsCid) ?? []) === $partner, 'hs-tråd: torrkörning skriver inget');
$hsWet = jeab_inkorg_hook([
    'action' => 'inbox.contact_set_email',
    'thread_id' => $hsId,
    'reminder_id' => $hsRid,
    'email' => $next,
    'dry' => 0,
]);
$hsMeta = jeab_inkorg_thread_meta($hsId);
$hsMsg = jeab_inkorg_get($hsMsgId);
$open = null;
foreach (jeab_inkorg_threads() as $t) {
    if ((string) ($t['id'] ?? '') === $hsId) {
        $open = $t;
        break;
    }
}
$party = is_array($open) ? jeab_inkorg_party($open) : ['email' => '', 'source' => ''];
$hsFresh = jeab_get_reminder(jeab_store(), $hsRid);
$hsGot = jeab_inkorg_hook(['action' => 'inbox.reminder_get', 'id' => $hsRid]);
$lookup = is_array($hsFresh) ? jeab_inkorg_reminder_lookup_email($hsFresh) : '';
cse(($hsWet['changed'] ?? 0) === 1 && ($hsWet['would_change'] ?? 1) === 0 && (int) ($hsWet['audit']['audit_id'] ?? 0) > 0, 'hs-tråd: skrivning ger audit_id');
cse((string) ($hsMsg['from'] ?? '') === $partner && (string) ($hsMsg['customer_email'] ?? '') === $next, 'hs-tråd: From är kvar, kundadressen ligger på mejlet');
cse((string) ($hsMeta['customer_email'] ?? '') === $next && (string) ($hsMeta['customer_source'] ?? '') === 'hook' && (string) ($hsMeta['lead_customer']['email'] ?? '') === $next, 'hs-tråd: tråd och lead_customer har kundens adress');
cse($party['email'] === $next && $party['source'] === 'stored', 'hs-tråd: inkorgssidan visar kunden, inte partnern');
cse(is_array($hsFresh) && (string) ($hsFresh['email'] ?? '') === $next && !str_contains((string) ($hsFresh['note'] ?? ''), $partner) && str_contains((string) ($hsFresh['note'] ?? ''), $next), 'hs-tråd: påminnelsens text byter partneradressen');
cse(jeab_kontakt_row_email(jeab_kontakt_get($hsCid) ?? []) === $next && ($hsGot['email'] ?? '') === $next && ($hsGot['contact_name'] ?? '') === 'Kim Exempel', 'hs-tråd: reminder_get ger e-post och namn');
cse($lookup === $next, 'hs-tråd: reminder_epost läser kundens adress');
cse($mail === 0, 'hs-tråd: inget mejl skickas');

echo "contact set email ok\n";
