<?php

declare(strict_types=1);

/** Admin-sidan Offertutskick. Graph är en attrapp. Ingen hook-nyckel. */

$tmp = sys_get_temp_dir() . '/jeab-offert-utskick-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function ou(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function ou_as(string $email): void
{
    $_SESSION['jeab_email'] = $email;
    unset($_COOKIE[JEAB_AUTH_COOKIE]);
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = [
        'to' => (string) ($a['to'] ?? ''),
        'subject' => (string) ($a['subject'] ?? ''),
    ];

    return ['ok' => true, 'from' => 'demo@example.test', 'graph_id' => 'g' . count($GLOBALS['_SENT']), 'conversation_id' => 'c', 'message_id' => 'm' . count($GLOBALS['_SENT']) . '@x'];
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'grok_decides' => 0,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode('%PDF-1.4 sol')]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode('%PDF-1.4 batteri')]);
jeab_auto_pdf_save(['key' => 'batteri_malmo', 'data' => base64_encode('%PDF-1.4 batteri-malmo')]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode('%PDF-1.4 ladd')]);
require __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();

$_SESSION['csrf'] = 'sid-csrf';
ou_as('saljare4@jarfalla.example.test');
$labels = array_map(static fn (array $item): string => (string) ($item[0] ?? ''), jeab_public_links());
ou(!in_array('Offertutskick', $labels, true) && in_array('Utskick', $labels, true), 'personal ser Utskick och inte en egen offertlänk');
$denied = jeab_offert_utskick_respond('POST', ['_csrf' => 'sid-csrf', 'action' => 'offert.enable']);
ou($denied['code'] === 403 && $denied['result'] === null && $GLOBALS['_SENT'] === [] && jeab_auto_settings()['quote_enabled'] === false, 'personal får 403 och inget slås på');

ou_as('agenter@flowstack.example.test');
ou(jeab_auth_user_person() === null && jeab_is_admin(), 'agenter@ är admin och ingen person');
$labels = array_map(static fn (array $item): string => (string) ($item[0] ?? ''), jeab_public_links());
ou(in_array('Utskick', $labels, true) && !in_array('Offertutskick', $labels, true) && in_array('Användare', $labels, true), 'admin ser Utskick och Inloggningar, inte en andra offertlänk');

$get = jeab_offert_utskick_respond('GET', []);
ou($get['code'] === 200 && $get['result'] === null && str_contains($get['html'], 'är <strong>av</strong>') && str_contains($get['html'], 'Slå på') && str_contains($get['html'], 'Testa mallar (visa)') && str_contains($get['html'], 'Förhandsgranska utskick bakåt') && str_contains($get['html'], 'Skicka offerter till dessa') && str_contains($get['html'], 'name="_csrf"') && str_contains($get['html'], 'p=utskick') && str_contains($get['html'], 'flik=offerter') && str_contains($get['html'], 'Alla lägen') && str_contains($get['html'], 'Alla orter') && !str_contains($get['html'], 'incoming_webhook') && !str_contains($get['html'], 'Bearer'), 'sidan visar status och knappar utan hook-nyckel');
ou(str_contains($get['html'], 'confirm(') && str_contains($get['html'], 'demo@example.test'), 'påslag och testkopia har bekräftelse');

$bad = jeab_offert_utskick_respond('POST', ['_csrf' => 'fel', 'action' => 'offert.backfill_send', 'dry' => 0]);
ou($bad['code'] === 419 && $bad['result'] === null && $GLOBALS['_SENT'] === [] && jeab_auto_settings()['quote_enabled'] === false, 'utan CSRF händer inget');

$token = '';
if (preg_match('/name="_csrf" value="([^"]+)"/', $get['html'], $m) === 1) {
    $token = $m[1];
}
ou($token !== '', 'sidan har en CSRF-token');

$on = jeab_offert_utskick_respond('POST', ['_csrf' => $token, 'action' => 'offert.enable', 'dry' => 1, 'to' => 'kund@gmail.com']);
ou($on['code'] === 200 && (int) ($on['result']['sent'] ?? 1) === 0 && $GLOBALS['_SENT'] === [] && jeab_auto_settings()['quote_enabled'] === true && jeab_auto_settings()['grok_decides'] === false && str_contains($on['html'], 'är <strong>på</strong>') && !str_contains($on['html'], 'value="offert.enable"'), 'Slå på skriver bara brytaren');

$case = static function (string $product): array {
    return [
        'type' => 'Ny lead',
        'intent' => 'kopintresse',
        'kind' => 'atgard',
        'primary_product' => $product,
        'secondary_products' => [],
        'confidence' => ['primary_product' => 0.95, 'intent' => 0.95, 'type' => 0.95, 'kind' => 0.95],
    ];
};
$leadBody = static function (string $name, string $email, string $ort, string $phone): string {
    return "Namn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nAdress: Testvägen 1, {$ort}\n";
};
$in = static function (string $id, string $from, string $name, string $subject, string $body, array $labels): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@offert-utskick.test',
        'from' => $from,
        'from_name' => $name,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => $subject,
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'preset_labels' => $labels,
    ]);
};
$offerLabels = static function (string $offer, array $case, string $lead = ''): array {
    $row = ['type' => 'Ny lead', 'intent' => 'kop', 'confidence' => 95, 'needs_review' => false, 'offer' => $offer, 'product' => $offer, 'case_class' => $case];
    if ($lead !== '') {
        $row['lead'] = $lead;
    }

    return $row;
};
$kopt = $in('kopt', 'kopt.kund@gmail.com', 'Kopt Kund', 'Ny lead: Kopt Kund · Villa i Bromma', $leadBody('Kopt Kund', 'kopt.kund@gmail.com', 'Bromma', '070-200 00 01'), $offerLabels('sol', $case('Sol'), 'kopt'));
$eget = $in('eget', 'eget.kund@gmail.com', 'Eget Kund', 'Ny lead: Eget Kund · Villa i Sundbyberg', $leadBody('Eget Kund', 'eget.kund@gmail.com', 'Sundbyberg', '070-200 00 02'), $offerLabels('sol', $case('Sol')));
ou(isset($kopt['thread_id'], $eget['thread_id']), 'två sollead finns');

$preview = jeab_offert_utskick_respond('POST', ['_csrf' => $token, 'action' => 'offert.test_preview', 'dry' => 0, 'to' => 'kund@gmail.com']);
ou($preview['code'] === 200 && (int) ($preview['result']['sent'] ?? 1) === 0 && $GLOBALS['_SENT'] === [] && str_contains($preview['html'], 'Sol köpt lead') && str_contains($preview['html'], 'Sol eget lead') && str_contains($preview['html'], 'Batteri') && str_contains($preview['html'], 'Solceller offert 2608 Demo Energi Järfälla.pdf') && !str_contains($preview['html'], 'kund@gmail.com'), 'visa mallar skickar inget och listar filer');

$threadsBefore = count(jeab_inkorg_threads());
$remBefore = count(jeab_list_reminders(jeab_store(), true));
$copy = jeab_offert_utskick_respond('POST', ['_csrf' => $token, 'action' => 'offert.test_send', 'to' => 'kund@gmail.com', 'dry' => 0]);
ou($copy['code'] === 200 && (int) ($copy['result']['sent'] ?? 0) >= 4 && count($GLOBALS['_SENT']) === (int) $copy['result']['sent'] && count(jeab_inkorg_threads()) === $threadsBefore && count(jeab_list_reminders(jeab_store(), true)) === $remBefore, 'testkopia rör inga ärenden');
$tos = array_map(static fn (array $mail): string => mb_strtolower($mail['to']), $GLOBALS['_SENT']);
ou($tos !== [] && count(array_unique($tos)) === 1 && $tos[0] === 'demo@example.test' && str_starts_with($GLOBALS['_SENT'][0]['subject'], '[TEST] ') && !str_contains($copy['html'], 'kund@gmail.com'), 'testkopian går bara till Sam');

jeab_inkorg_ingest([
    'message_id' => 'prior-redan@offert-utskick.test',
    'from' => 'saljare4@jarfalla.example.test',
    'from_name' => 'Elin',
    'to' => 'Redan.Kund@Gmail.com',
    'subject' => 'Hej',
    'body_text' => 'Vi hörde av oss.',
    'source' => 'outbound-jarfalla',
    'received_at' => date('c', time() - 86400),
    'preset_labels' => ['type' => 'Information', 'intent' => 'information', 'confidence' => 100, 'offer' => ''],
]);
$in('redan', 'redan.kund@gmail.com', 'Redan Kund', 'Ny lead: Redan Kund · Villa i Nacka', $leadBody('Redan Kund', 'redan.kund@gmail.com', 'Nacka', '070-200 00 03'), $offerLabels('batteri', $case('Batteri')));
$sentBefore = count($GLOBALS['_SENT']);
$look = jeab_offert_utskick_respond('POST', ['_csrf' => $token, 'action' => 'offert.backfill_preview', 'days' => 30, 'limit' => 500, 'dry' => 0, 'thread' => 'all']);
ou($look['code'] === 200 && (int) ($look['result']['sent'] ?? 1) === 0 && (int) ($look['result']['limit'] ?? 0) === 25 && count($GLOBALS['_SENT']) === $sentBefore && str_contains($look['html'], 'Sol köpt') && str_contains($look['html'], 'Sol eget') && str_contains($look['html'], 'skulle skickas') && str_contains($look['html'], 'Skicka 2 offerter') && str_contains($look['html'], 'redan mailad') && !str_contains($look['html'], 'kopt.kund@gmail.com') && !str_contains($look['html'], 'eget.kund@gmail.com') && !str_contains($look['html'], 'redan.kund@gmail.com'), 'förhandsgranskning listar mall, redan mailad och antal utan att skicka');

$sentBefore = count($GLOBALS['_SENT']);
$send = jeab_offert_utskick_respond('POST', ['_csrf' => $token, 'action' => 'offert.backfill_send', 'days' => 30, 'limit' => 9, 'to' => 'kund@gmail.com']);
ou($send['code'] === 200 && (int) ($send['result']['sent'] ?? 0) === 1 && count($GLOBALS['_SENT']) === $sentBefore + 1 && str_contains($send['html'], 'skickad') && str_contains($send['html'], 'Sol köpt') && !str_contains($send['html'], 'kopt.kund@gmail.com'), 'klicket skickar offerten och visar läget per tråd: ' . json_encode(array_map(static fn ($row) => ['mall' => $row['mall'] ?? '', 'action' => $row['action'] ?? ''], (array) ($send['result']['rows'] ?? [])), JSON_UNESCAPED_UNICODE));
$sentBefore = count($GLOBALS['_SENT']);
$again = jeab_offert_utskick_respond('POST', ['_csrf' => $token, 'action' => 'offert.backfill_send', 'days' => 30]);
ou((int) ($again['result']['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === $sentBefore && str_contains($again['html'], 'redan'), 'samma körning skickar inte igen');

echo "offertutskick sida: ok\n";
