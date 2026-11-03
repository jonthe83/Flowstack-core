<?php

declare(strict_types=1);

/** Påhittade formulär. Sol+batteri i Järfälla får påminnelse till Sam L. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-form-reminder-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function fr(bool $ok, string $m): void
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
    return ['ok' => true, 'from' => 'demo@example.test', 'graph_id' => 'g', 'conversation_id' => 'C', 'message_id' => 's@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn (): array => ['code' => 200, 'body' => ['value' => []]];

$oneLine = 'Namn: Ada Form E-post: ada.form@example.com Telefon: 070-111 22 33 Adress: Testvägen 12 Postnummer: 177 31 Ort: Järfälla Intresse: Sol+Batteri';
$html = '<div><span>Namn: Beata Form</span><span>E-post: beata.form@example.com</span><span>Telefon: 070-222 33 44</span><span>Adress: Exempelvägen 4</span><span>Postnummer: 177 31</span><span>Ort: Järfälla</span><span>Intresse: Sol+Batteri</span></div>';
$classic = "Namn: Cilla Form\nE-post: cilla.form@example.com\nTelefon: 070-333 44 55\nAdress: Klassvägen 1\nPostnummer: 176 70\nOrt: Järfälla\nIntresse: Sol+Batteri\n";
$blank = "Namn:\n\nDitte Form\n\nE-post:\n\nditte.form@example.com\n\nTelefon:\n\n070-444 55 66\n\nAdress:\n\nMellanvägen 2\n\nPostnummer:\n\n177 31\n\nOrt:\n\nJärfälla\n";

$ada = jeab_lead_customer(['from' => 'info@wix-forms.com', 'body' => $oneLine]);
fr(($ada['email'] ?? '') === 'ada.form@example.com', 'en rad: e-post');
fr(str_contains((string) ($ada['address'] ?? ''), 'Testvägen 12') && str_contains((string) ($ada['address'] ?? ''), '177 31') && str_contains((string) ($ada['address'] ?? ''), 'Järfälla'), 'en rad: adress, postnummer och ort');
fr(str_contains((string) ($ada['phone'] ?? ''), '070'), 'en rad: telefon');
fr(jeab_inkorg_form_product(['body' => $oneLine]) === 'sol', 'Intresse Sol+Batteri är sol för säljaren');
fr(jeab_inkorg_form_quote_offer(['body' => $oneLine]) === 'sol+batteri', 'Intresse Sol+Batteri är offerten sol+batteri');
fr(jeab_inkorg_form_quote_offer(['body' => "Intresse: batteri till solceller\n"]) === 'batteri', 'batteri till solceller förblir batteri');

$beata = jeab_lead_customer(['from' => 'info@wix-forms.com', 'body' => $html]);
fr(($beata['email'] ?? '') === 'beata.form@example.com' && str_contains((string) ($beata['address'] ?? ''), 'Exempelvägen 4') && str_contains((string) ($beata['phone'] ?? ''), '070'), 'html-span: e-post, adress och telefon');

$cilla = jeab_lead_customer(['from' => 'info@wix-forms.com', 'body' => $classic]);
fr(($cilla['name'] ?? '') === 'Cilla Form' && str_contains((string) ($cilla['address'] ?? ''), 'Klassvägen 1') && str_contains((string) ($cilla['phone'] ?? ''), '070'), 'klassiska kolonrader ligger kvar');

$ditte = jeab_lead_customer(['from' => 'info@wix-forms.com', 'body' => $blank]);
fr(($ditte['name'] ?? '') === 'Ditte Form' && str_contains((string) ($ditte['address'] ?? ''), 'Mellanvägen 2') && ($ditte['phone'] ?? '') !== '', 'blankrad mellan etikett och värde ligger kvar');

fr(jeab_inkorg_case_product_name('Sol+Batteri') === 'Sol', 'Sol+Batteri är Sol');
fr(jeab_inkorg_case_product_name('solceller, batteri') === 'Sol', 'solceller, batteri är Sol');
fr(jeab_inkorg_case_product_name('batteri till solceller') === 'Batteri', 'batteri till befintlig sol är Batteri');
fr(jeab_inkorg_case_product_name('Batteri') === 'Batteri', 'bara Batteri är Batteri');
fr(jeab_lead_quote_owner_name('Sol+Batteri', 'jarfalla') === 'Sam L', 'Järfälla sol+batteri är Sam L');
fr(jeab_lead_quote_owner_name('solceller, batteri', 'jarfalla') === 'Sam L', 'produktsträngen solceller, batteri är Sam L');
fr(jeab_lead_quote_owner_name('batteri', 'jarfalla') === 'Roberto', 'bara batteri är Roberto');
fr(jeab_lead_quote_owner_name('sol', 'malmo') === 'Sam W', 'Malmö sol är Sam W');

jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
@mkdir(dirname(jeab_inkorg_token_path()), 0775, true);
file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'ink_form_' . bin2hex(random_bytes(4))]));

$box = jeab_graph_mailboxes()['jarfalla'];
$ingest = static function (string $id, string $body, string $subject, string $at, array $labels = [], string $from = 'info@wix-forms.com') use ($box): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@form.test',
        'from' => $from,
        'from_name' => 'Formulär',
        'to' => $box,
        'subject' => $subject,
        'date' => $at,
        'received_at' => $at,
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'headers' => ['Authentication-Results' => 'mx.example.test; dmarc=pass header.from=wix-forms.com'],
        'preset_labels' => array_merge([
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 90,
            'intent' => 'kop', 'offer' => '', 'product' => 'solceller, batteri', 'question' => '',
        ], $labels),
    ]);
};

$before = count($GLOBALS['_SENT']);
$live = $ingest('live', $oneLine, 'Formulär sol och batteri', '2026-10-20T18:00:00+02:00');
$auto = jeab_auto_on_message((string) $live['id']);
fr(count($GLOBALS['_SENT']) === $before, 'avstängt offertutskick skickar inget');
fr(in_array((string) ($auto['action'] ?? ''), ['hold', 'none'], true), 'automatiken håller eller lämnar leadet, fick ' . (string) ($auto['action'] ?? ''));
$rem = null;
foreach (jeab_list_reminders(jeab_store(), true) as $row) {
    if (($row['email'] ?? '') === 'ada.form@example.com') {
        $rem = $row;
    }
}
fr(is_array($rem), 'påminnelse skapas när kundmejlet är av');
fr(in_array('Sam L', (array) ($rem['assignees'] ?? []), true) || (string) ($rem['person'] ?? '') === 'SamL', 'påminnelsen är Sam L');
fr(str_contains((string) ($rem['address'] ?? ''), 'Testvägen 12'), 'påminnelsen har gatuadressen');
fr((string) ($rem['phone'] ?? '') !== '', 'påminnelsen har telefon');

$thread = null;
foreach (jeab_inkorg_threads() as $t) {
    if ($t['id'] === (string) $live['thread_id']) {
        $thread = $t;
    }
}
fr(is_array($thread), 'ärendet finns');
$place = jeab_inkorg_panel_place($thread, ['address' => '', 'town' => '', 'mobile' => '', 'emails' => []]);
fr(str_contains($place['address'], 'Testvägen 12'), 'kundpanelen läser adressen ur formuläret när kontakten saknar den');
$boxCust = jeab_inkorg_contact_box($thread, ['mobile' => '', 'emails' => []], jeab_inkorg_customer($thread, jeab_inkorg_threads()));
if ($boxCust['phone'] === '') {
    $boxCust['phone'] = trim((string) (jeab_inkorg_party($thread)['phone'] ?? ''));
}
fr($boxCust['phone'] !== '', 'kundpanelen visar telefonen ur formuläret');

$dupBody = "Namn: Eskil Form\nE-post: eskil.form@example.com\nTelefon: 070-555 66 77\nAdress: Kopievägen 3\nPostnummer: 177 31\nOrt: Järfälla\nIntresse: Sol+Batteri\n";
$a = $ingest('dup-a', $dupBody, 'Kopia ett', '2026-10-20T18:10:00+02:00', [], 'eskil.form@example.com');
$b = $ingest('dup-b', $dupBody, 'Kopia två', '2026-10-20T18:12:00+02:00', [], 'eskil.form@example.com');
$c = $ingest('dup-c', $dupBody, 'Kopia tre', '2026-10-20T18:14:00+02:00', [], 'eskil.form@example.com');
fr((string) $a['thread_id'] !== (string) $b['thread_id'] && (string) $b['thread_id'] !== (string) $c['thread_id'], 'tre ämnen är tre ärenden');

$dry = jeab_inkorg_hook(['action' => 'inbox.lead_reminder_backfill', 'since' => '2026-10-20']);
fr(($dry['ok'] ?? false) === true && (int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['sent'] ?? 1) === 0, 'hooken är torr som standard och sent är 0');
$actions = [];
foreach ((array) ($dry['threads'] ?? []) as $row) {
    if (!is_array($row)) {
        continue;
    }
    $actions[(string) ($row['id'] ?? '')] = (string) ($row['action'] ?? '');
    fr(!isset($row['email']) && !isset($row['address']) && !isset($row['phone']), 'svaret har ingen adress');
}
$reminderRows = array_filter($actions, static fn (string $action): bool => $action === 'reminder');
$linkRows = array_filter($actions, static fn (string $action): bool => $action === 'link');
fr(count($reminderRows) >= 1 && count($linkRows) >= 2, 'torrkörning slår ihop dubbletterna till en påminnelse');
$remindersBefore = count(jeab_list_reminders(jeab_store(), true));
$denied = jeab_inkorg_hook(['action' => 'inbox.lead_reminder_backfill', 'since' => '2026-10-20', 'dry' => 0]);
fr(($denied['ok'] ?? true) === false && count(jeab_list_reminders(jeab_store(), true)) === $remindersBefore, 'dry=0 utan confirm skriver inget');
$sentBefore = count($GLOBALS['_SENT']);
$wrote = jeab_inkorg_hook(['action' => 'inbox.lead_reminder_backfill', 'since' => '2026-10-20', 'dry' => 0, 'confirm' => 'SAM']);
fr(($wrote['ok'] ?? false) === true && (int) ($wrote['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === $sentBefore, 'confirm skapar påminnelse utan att skicka');
$eskil = [];
foreach (jeab_list_reminders(jeab_store(), true) as $row) {
    if (($row['email'] ?? '') === 'eskil.form@example.com') {
        $eskil[] = $row;
    }
}
fr(count($eskil) === 1, 'tre formulär från samma avsändare blir en påminnelse');
fr(str_contains((string) ($eskil[0]['address'] ?? ''), 'Kopievägen 3') && (string) ($eskil[0]['phone'] ?? '') !== '', 'den påminnelsen har adress och telefon från formuläret');
fr(in_array('Sam L', (array) ($eskil[0]['assignees'] ?? []), true) || (string) ($eskil[0]['person'] ?? '') === 'SamL', 'dubbletten tilldelas Sam L');
$linked = 0;
foreach ([$b['thread_id'], $c['thread_id']] as $tid) {
    foreach (jeab_inkorg_threads() as $t) {
        if ($t['id'] === $tid && (string) ($t['duplicate_of'] ?? '') !== '') {
            $linked++;
        }
    }
}
fr($linked === 2, 'de två senare ärendena länkas till det första');

echo "inkorg-form-reminder: ok\n";
