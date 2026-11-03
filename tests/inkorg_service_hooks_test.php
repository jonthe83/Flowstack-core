<?php

declare(strict_types=1);

/** Kundtjänst: stäng, öppna, lista och länka. Påhittade namn. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-service-hooks-' . bin2hex(random_bytes(4));
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

function sh(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$battery = mb_strtolower(jeab_gp_owner_email('batteri', 'jarfalla'));
$charger = mb_strtolower(jeab_gp_owner_email('laddbox', 'jarfalla'));
$solar = mb_strtolower(jeab_gp_owner_email('sol', 'jarfalla'));
sh($battery !== '' && $charger !== '' && $solar !== '' && $battery !== $charger, 'säljarna går att skilja åt');

$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use ($battery, $charger, $solar): array {
    unset($method);
    $decoded = mb_strtolower(rawurldecode($url));
    if (str_contains($url, 'mailFolders/sentitems') && !str_contains($url, '/messages')) {
        return ['code' => 200, 'body' => ['id' => 'sent-box']];
    }
    if (str_contains($decoded, '/attachments')) {
        return ['code' => 200, 'body' => ['value' => [['name' => 'Batteri-offert.pdf']]]];
    }
    $seller = '';
    if (str_contains($decoded, $battery)) {
        $seller = 'batteri';
    } elseif (str_contains($decoded, $charger)) {
        $seller = 'laddbox';
    } elseif (str_contains($decoded, $solar)) {
        $seller = 'sol';
    }
    $customer = '';
    foreach (['elsa.borg@gmail.com', 'pia.holm@gmail.com', 'tove.rask@gmail.com', 'rune.feld@gmail.com'] as $addr) {
        if (str_contains($decoded, $addr)) {
            $customer = $addr;
        }
    }
    if ($customer === 'rune.feld@gmail.com' && str_contains($decoded, '$search=')) {
        return ['code' => 500, 'body' => ['error' => ['message' => 'oläsbar']]];
    }
    $hit = false;
    $subject = 'Hej';
    $files = false;
    $id = 'Q1';
    if ($customer === 'elsa.borg@gmail.com' && $seller === 'batteri' && str_contains($decoded, '$search=')) {
        $hit = true;
        $subject = 'Offert batteri';
        $id = 'Q-elsa';
    }
    if ($customer === 'pia.holm@gmail.com' && ($seller === 'batteri' || $seller === 'laddbox') && str_contains($decoded, '$search=')) {
        $hit = true;
        $subject = 'Hej';
        $id = 'Q-pia-' . $seller;
    }
    if ($customer === 'tove.rask@gmail.com' && $seller === 'laddbox' && str_contains($decoded, '$search=')) {
        $hit = true;
        $subject = 'Hej';
        $files = true;
        $id = 'Q-tove';
    }
    if (!$hit) {
        return ['code' => 200, 'body' => ['value' => []]];
    }
    return ['code' => 200, 'body' => ['value' => [[
        'id' => $id,
        'subject' => $subject,
        'hasAttachments' => $files,
        'toRecipients' => [['emailAddress' => ['address' => $customer]]],
        'ccRecipients' => [],
        'sentDateTime' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400),
        'parentFolderId' => 'sent-box',
    ]]]];
};

$lead = static function (string $id, string $email, string $name, string $company = 'jarfalla', string $offer = ''): array {
    $box = jeab_graph_mailboxes()[$company];
    return jeab_inkorg_ingest([
        'message_id' => $id . '@hooks.test',
        'from' => $email,
        'from_name' => $name,
        'to' => $box,
        'subject' => 'Fråga från ' . $name,
        'body_text' => "Namn: {$name}\nE-post: {$email}\nTelefon: 070-555 10 20\nAdress: Tallstigen 2, 177 40 Järfälla\nHej, jag vill ha hjälp.\n",
        'source' => 'graph-' . $company,
        'graph_mailbox' => $box,
        'thread_key' => 'sh-' . $id,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => $offer, 'product' => $offer === '' ? 'okänd produkt' : $offer, 'question' => '',
        ],
    ]);
};
$remind = static function (string $tid, string $name, string $email, string $title): array {
    $wasOpen = in_array((string) (jeab_inkorg_thread_meta($tid)['status'] ?? 'open'), ['', 'open', 'waiting_customer', 'waiting_supplier'], true);
    $row = jeab_create_reminder(jeab_store(), $title, jeab_today_start(), null, null, [
        'customer' => $name,
        'email' => $email,
        'company' => 'jarfalla',
        'who' => 'Roberto',
        'note' => 'Ring och följ upp.',
    ]);
    // close_quoted testar sin egen regel. Ett ärende som var öppet öppnas igen efter skapandet.
    if ($wasOpen) {
        try {
            jeab_inkorg_thread_write($tid, static function (array $t): array {
                $t['status'] = 'open';
                unset($t['hook_closed']);
                return $t;
            });
        } catch (Throwable $e) {
            unset($e);
        }
    }
    return $row;
};
$statusOf = static function (string $tid): string {
    return (string) (jeab_inkorg_thread_meta($tid)['status'] ?? '');
};
$rowOf = static function (array $res, string $tid): array {
    foreach ((array) ($res['threads'] ?? []) as $row) {
        if (is_array($row) && (string) ($row['thread_id'] ?? '') === $tid) {
            return $row;
        }
    }
    return [];
};

$elsa = $lead('elsa', 'elsa.borg@gmail.com', 'Elsa Borg');
$elsaTid = (string) $elsa['thread_id'];
$elsaRem = $remind($elsaTid, 'Elsa Borg', 'elsa.borg@gmail.com', 'Elsa Borg – Batteri');

$dryHandled = jeab_inkorg_hook(['action' => 'inbox.handled', 'thread_id' => $elsaTid]);
$dryAlias = jeab_inkorg_hook(['action' => 'inkorg.close', 'thread_id' => $elsaTid]);
$dryJson = (string) json_encode($dryHandled, JSON_UNESCAPED_UNICODE);
sh(($dryHandled['dry'] ?? 0) === 1 && ($dryAlias['reason'] ?? '') === ($dryHandled['reason'] ?? '') && ($dryHandled['reason'] ?? '') === 'offert och påminnelse', 'inbox.handled och inkorg.close är samma anrop, dry=1');
sh($statusOf($elsaTid) === 'open' && !str_contains($dryJson, '@'), 'torr stängning skriver inget och ingen adress');

$live = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $elsaTid, 'dry' => 0]);
$elsaThread = jeab_auto_thread_by_id($elsaTid);
sh(($live['dry'] ?? 1) === 0 && ($live['status'] ?? '') === 'closed' && $statusOf($elsaTid) === 'closed', 'offert från batterisäljaren och påminnelse stänger');
sh(is_array($elsaThread) && in_array('batteri', (array) $elsaThread['tags'], true) && empty($elsaThread['deleted']), 'produkten Batteri skrivs på ärendet och det finns kvar');
sh((string) (jeab_inkorg_thread_meta($elsaTid)['hook_closed'] ?? '') === 'close', 'stängningen är gjord av hooken');

$dryOpen = jeab_inkorg_hook(['action' => 'inbox.reopen', 'thread_id' => $elsaTid]);
sh(($dryOpen['dry'] ?? 0) === 1 && $statusOf($elsaTid) === 'closed', 'torr reopen skriver inget');
$opened = jeab_inkorg_hook(['action' => 'inbox.reopen', 'thread_id' => $elsaTid, 'dry' => '0', 'confirm' => 'SAM']);
sh(($opened['status'] ?? '') === 'open' && $statusOf($elsaTid) === 'open' && (string) ($opened['reason'] ?? '') === 'öppnad via hook', 'reopen återställer hook-stängningen');
$again = jeab_inkorg_hook(['action' => 'inbox.close_quoted', 'dry' => 0]);
sh($statusOf($elsaTid) === 'open' && ($rowOf($again, $elsaTid)['reason'] ?? '') === 'öppnad via hook', 'automatiken stänger inte ett återöppnat ärende');

$mio = $lead('mio', 'mio.sant@gmail.com', 'Mio Sant');
$mioTid = (string) $mio['thread_id'];
$held = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $mioTid, 'dry' => 0]);
sh($statusOf($mioTid) === 'open' && ($held['reason'] ?? 'x') !== jeab_inkorg_manual_close_note(), 'utan force stannar ett ärende utan offert och påminnelse');
$forcedDry = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $mioTid, 'force' => 1]);
$noted = false;
foreach ((array) (jeab_auto_thread_by_id($mioTid)['drafts'] ?? []) as $d) {
    if (is_array($d) && str_contains((string) ($d['body'] ?? ''), jeab_inkorg_manual_close_note())) {
        $noted = true;
    }
}
sh(($forcedDry['dry'] ?? 0) === 1 && $statusOf($mioTid) === 'open' && !$noted, 'torr force skriver ingen anteckning');
$forced = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $mioTid, 'force' => 1, 'dry' => 0]);
$noted = false;
foreach ((array) (jeab_auto_thread_by_id($mioTid)['drafts'] ?? []) as $d) {
    if (is_array($d) && (string) ($d['body'] ?? '') === jeab_inkorg_manual_close_note()) {
        $noted = true;
    }
}
sh(($forced['status'] ?? '') === 'closed' && $noted && empty(jeab_auto_thread_by_id($mioTid)['deleted']), 'force stänger och antecknar stängd manuellt via hook');

$ove = $lead('ove', 'ove.lind@gmail.com', 'Ove Lind', 'jarfalla', 'batteri');
$oveTid = (string) $ove['thread_id'];
jeab_inkorg_thread_set($oveTid, 'closed', null);
$refused = jeab_inkorg_hook(['action' => 'inbox.reopen', 'thread_id' => $oveTid, 'dry' => 0]);
sh(($refused['ok'] ?? true) === false && $statusOf($oveTid) === 'closed' && ($refused['reason'] ?? '') === 'inte stängd via hook', 'en annan stängning öppnas inte');

$maja = $lead('maja', 'maja.holm@gmail.com', 'Maja Holm', 'malmo', 'batteri');
$majaTid = (string) $maja['thread_id'];
$malmo = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $majaTid, 'force' => 1, 'dry' => 0]);
sh(($malmo['status'] ?? 0) === 403 && $statusOf($majaTid) === 'open', 'Malmö blir 403 och skrivs inte');

$pia = $lead('pia', 'pia.holm@gmail.com', 'Pia Holm');
$piaTid = (string) $pia['thread_id'];
$remind($piaTid, 'Pia Holm', 'pia.holm@gmail.com', 'Pia Holm – Batteri');
$piaClose = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $piaTid, 'dry' => 0]);
sh($statusOf($piaTid) === 'open' && ($piaClose['reason'] ?? '') === 'okänd produkt', 'två säljare utan produktord lämnas öppet');

$tove = $lead('tove', 'tove.rask@gmail.com', 'Tove Rask');
$toveTid = (string) $tove['thread_id'];
$remind($toveTid, 'Tove Rask', 'tove.rask@gmail.com', 'Tove Rask – Batteri');
$toveClose = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $toveTid, 'dry' => 0]);
sh($statusOf($toveTid) === 'open' && ($toveClose['reason'] ?? '') === 'okänd produkt', 'bilaga och säljare som pekar åt olika håll lämnas öppet');

$rune = $lead('rune', 'rune.feld@gmail.com', 'Rune Feld');
$runeTid = (string) $rune['thread_id'];
$remind($runeTid, 'Rune Feld', 'rune.feld@gmail.com', 'Rune Feld – Batteri');
$runeClose = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $runeTid, 'dry' => 0]);
sh($statusOf($runeTid) === 'open' && ($runeClose['reason'] ?? '') === 'okänd produkt', 'oläsbar Skickat-källa lämnas öppen');

$noel = $lead('noel', 'noel.vik@gmail.com', 'Noel Vik');
$noelTid = (string) $noel['thread_id'];
jeab_inkorg_ingest([
    'message_id' => 'noel-sent@hooks.test',
    'from' => $battery,
    'from_name' => 'Säljare',
    'to' => 'noel.vik@gmail.com',
    'subject' => 'Hej',
    'body_text' => 'Här kommer offerten.',
    'source' => 'outbound-graph',
    'thread_key' => 'sh-noel',
    'attachments' => [['filename' => 'Batteri-offert.pdf']],
    'preset_labels' => ['type' => 'Information', 'action' => 'arkivera', 'priority' => 3, 'confidence' => 90, 'intent' => '', 'offer' => '', 'product' => '', 'question' => ''],
]);
$remind($noelTid, 'Noel Vik', 'noel.vik@gmail.com', 'Noel Vik – Batteri');
$noelClose = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $noelTid, 'dry' => 0]);
sh($statusOf($noelTid) === 'closed' && in_array('batteri', (array) (jeab_auto_thread_by_id($noelTid)['tags'] ?? []), true), 'bilaganamn Batteri-offert sätter produkten och stänger');

$remy = $lead('remy', 'remy.dal@gmail.com', 'Remy Dal', 'jarfalla', 'batteri');
$remyTid = (string) $remy['thread_id'];
jeab_inkorg_thread_write($remyTid, static function (array $t): array {
    $t['drafts'] = [[
        'id' => 'd-remy',
        'kind' => 'note',
        'body' => 'Offert (Batteri) skickad till kunden.',
        'state' => 'note',
        'created_at' => date('c'),
    ]];
    return $t;
});
$remyRem = $remind($remyTid, 'Remy Dal', 'remy.dal@gmail.com', 'Remy Dal uppföljning');
$remyId = (int) $remyRem['id'];
$linkDry = jeab_inkorg_hook(['action' => 'inbox.link_reminder', 'thread_id' => $remyTid, 'reminder_id' => $remyId]);
$remyNote = (string) (jeab_store()->getReminder($remyId)['note'] ?? '');
sh(($linkDry['dry'] ?? 0) === 1 && ($linkDry['linked'] ?? 1) === 0 && !str_contains($remyNote, 'id=' . $remyTid), 'torr länkning skriver ingen mail-rad');
$link = jeab_inkorg_hook(['action' => 'inbox.link_reminder', 'thread_id' => $remyTid, 'reminder_id' => $remyId, 'dry' => 0]);
$remySaved = jeab_store()->getReminder($remyId);
sh(($link['reason'] ?? '') === 'länkad' && str_contains((string) ($remySaved['note'] ?? ''), jeab_lead_mail_url($remyTid)), 'länkningen skriver mail-raden');
sh(str_contains((string) ($remySaved['title'] ?? ''), 'Batteri'), 'påminnelse utan produkt får trådens produkt');
jeab_inkorg_thread_write($remyTid, static function (array $t): array {
    $t['status'] = 'open';
    unset($t['hook_closed']);
    return $t;
});
$remyClose = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $remyTid, 'dry' => 0]);
sh($statusOf($remyTid) === 'closed' && ($remyClose['reason'] ?? '') === 'offert och påminnelse', 'länkad påminnelse och offert stänger');

$solRem = $remind($oveTid, 'Ove Lind', 'ove.lind@gmail.com', 'Ove Lind – Solceller');
$badLink = jeab_inkorg_hook(['action' => 'inbox.link_reminder', 'thread_id' => $oveTid, 'reminder_id' => (int) $solRem['id'], 'dry' => 0]);
sh(($badLink['ok'] ?? true) === false && ($badLink['reason'] ?? '') === 'påminnelsen gäller annan produkt', 'fel produkt länkas inte');
$badNote = (string) (jeab_store()->getReminder((int) $solRem['id'])['note'] ?? '');
sh(!str_contains($badNote, 'id=' . $oveTid), 'avvisad länkning skriver ingen mail-rad');

$list = jeab_inkorg_hook(['action' => 'inbox.threads', 'mailbox' => 'jarfalla', 'status' => 'all', 'limit' => 50]);
$listJson = (string) json_encode($list, JSON_UNESCAPED_UNICODE);
$elsaRow = $rowOf($list, $elsaTid);
$piaRow = $rowOf($list, $piaTid);
sh(($list['ok'] ?? false) === true && !str_contains($listJson, '@'), 'listan har ingen adress');
sh(($elsaRow['name'] ?? '') === 'Elsa Borg' && ($elsaRow['product'] ?? '') === 'Batteri' && ($elsaRow['quote_sent'] ?? '') === 'yes' && (int) ($elsaRow['reminder_id'] ?? 0) === (int) $elsaRem['id'], 'listan visar namn, produkt, offert och påminnelse');
sh(($elsaRow['status'] ?? '') === 'open' && ($piaRow['product'] ?? '') === 'okänd produkt' && ($piaRow['quote_sent'] ?? '') === 'no', 'billig lista lämnar Graph och osäker produkt öppen');
$deepList = jeab_inkorg_hook(['action' => 'inbox.threads', 'mailbox' => 'jarfalla', 'status' => 'all', 'limit' => 50, 'deep' => 1]);
$piaDeep = $rowOf($deepList, $piaTid);
sh(($piaDeep['quote_sent'] ?? '') === 'uncertain' && ($piaDeep['product'] ?? '') === 'okänd produkt' && ($piaDeep['status'] ?? '') === 'open', 'deep=1 visar osäker offert');
$closedOnly = jeab_inkorg_hook(['action' => 'inbox.threads', 'mailbox' => 'jarfalla', 'status' => 'closed']);
$closedIds = array_column((array) ($closedOnly['threads'] ?? []), 'thread_id');
sh(in_array($noelTid, $closedIds, true) && !in_array($piaTid, $closedIds, true), 'status=closed är bara de stängda');
$page = jeab_inkorg_hook(['action' => 'inbox.threads', 'mailbox' => 'jarfalla', 'status' => 'all', 'limit' => 1, 'offset' => 1]);
sh(($page['total'] ?? 0) > 1 && count((array) ($page['threads'] ?? [])) === 1, 'limit och offset paginerar');
$owned = jeab_inkorg_hook(['action' => 'inbox.threads', 'mailbox' => 'jarfalla', 'owner' => 'Roberto', 'status' => 'closed']);
$ownedIds = array_column((array) ($owned['threads'] ?? []), 'thread_id');
sh(in_array($noelTid, $ownedIds, true) && ($rowOf($owned, $noelTid)['owner'] ?? '') === 'Roberto', 'owner filtrerar batterisäljaren utan adress');

echo "OK  service-hooks\n";
