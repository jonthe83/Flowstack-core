<?php

declare(strict_types=1);

/** Järfälla sol: mall A köpt lead, mall B egen lead, som de skickade mejlen. */

$tmp = sys_get_temp_dir() . '/jeab-sol-mall-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function sm(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = function (array $a) {
    $GLOBALS['_SENT'][] = $a;
    $person = trim((string) ($a['opts']['person_email'] ?? ''));
    $from = $person !== '' ? $person : jeab_graph_mailboxes()[$a['company']];
    return ['ok' => true, 'from' => $from, 'graph_id' => 'g-sol', 'conversation_id' => 'CV', 'message_id' => 'sent-sol@x'];
};
@mkdir(jeab_data_dir() . '/secrets', 0775, true);
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test']));
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['answer' => 'Taket på bilden räcker till ungefär 12 paneler.']);

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);

sm(jeab_auto_quote_seed()['sol_jarfalla'] === '(solmallen från offertflödet används)', 'sol_jarfalla pekar på de skickade mallarna');
sm(!jeab_auto_sol_jarfalla_placeholder((string) jeab_auto_settings()['templates']['sol_jarfalla']), 'den riktiga mallen är inte platshållare');
sm(!str_contains(jeab_gp_auto_sender_phrase(), 'pausad'), 'automatiktexten säger inte pausad');
sm(jeab_auto_pdf_name('sol_jarfalla') === 'Solceller offert 2608 Demo Energi Järfälla.pdf', 'bilagans filnamn');
sm(jeab_auto_sol_jarfalla_pdf_sha256() === '11a33172952d00a73c546fce8231136e11c8ad171aeab57606337c1f38c49d8e', 'förväntad sha256');
$tracked = array_filter(explode("\n", (string) shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' ls-files "*.pdf"')));
sm(!in_array('Solceller offert 2608 Demo Energi Järfälla.pdf', $tracked, true), 'PDF:en är inte committad');

$leadBody = "Namn: Anna Svensson\nE-post: kund.b@example.invalid\nTelefon: 070-123 45 67\nAdress: Solvägen 1\nPostnummer: 176 74\nOrt: Järfälla\n";
foreach (['support@leadkit.com', 'leads@leadkit.com'] as $from) {
    $parsed = jeab_lead_customer(['from' => $from, 'from_name' => 'Leadkit', 'subject' => 'Ny lead', 'body' => $leadBody]);
    sm($parsed['platform'] === 'leadkit.com' && $parsed['name'] === 'Anna Svensson' && $parsed['email'] === 'kund.b@example.invalid' && $parsed['phone'] === '070-123 45 67' && $parsed['address'] === 'Solvägen 1, 176 74 Järfälla', 'Leadkit ' . $from . ' läses ur brödtexten');
}
$htmlLead = '<table><tr><td>Kundens namn</td><td>Bo Ek</td></tr><tr><td>Kundens e-post</td><td><a href="mailto:bo.ek@gmail.com">bo.ek@gmail.com</a></td></tr>'
    . '<tr><td>Kundens telefon</td><td>073-555 12 12</td></tr><tr><td>Kundens adress</td><td>Ekvägen 4, 177 31 Järfälla</td></tr></table>';
$parsed = jeab_lead_customer(['from' => 'leads@leadkit.com', 'from_name' => 'Leadkit', 'subject' => 'Ny lead', 'body' => $htmlLead]);
sm($parsed['name'] === 'Bo Ek' && $parsed['email'] === 'bo.ek@gmail.com' && $parsed['phone'] === '073-555 12 12' && str_contains($parsed['address'], 'Ekvägen 4'), 'Leadkit HTML-tabell läses ur brödtexten');

$labels = ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95, 'intent' => 'kop', 'offer' => 'sol', 'product' => 'solceller', 'question' => ''];
$ingest = function (string $id, string $from, string $fromName, string $body, array $extra = []) use ($labels) {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@sol.test',
        'from' => $from,
        'from_name' => $fromName,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Solceller',
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'graph_id' => 'graph-' . $id,
        'thread_key' => 'sol-' . $id,
        'preset_labels' => array_merge($labels, $extra['labels'] ?? []),
        'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass'],
    ] + $extra);
};

require __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();

$before = count($GLOBALS['_SENT']);
$r = $ingest('no-pdf', 'leads@leadkit.com', 'Leadkit', $leadBody);
$a = jeab_auto_on_message((string) $r['id']);
sm(($a['action'] ?? '') === 'hold' && str_contains((string) ($a['why'] ?? ''), 'PDF') && count($GLOBALS['_SENT']) === $before, 'saknad sol-PDF håller offerten');

jeab_auto_pdf_save(['key' => 'sol_jarfalla', 'data' => base64_encode("%PDF-1.4 jarfalla-sol")]);

$quoteBody = str_replace(
    ['Anna Svensson', 'kund.b@example.invalid', '070-123 45 67', 'Solvägen 1'],
    ['Anna Lind', 'anna.lind@gmail.com', '070-123 45 68', 'Solvägen 9'],
    $leadBody
);
$r = $ingest('leadkit', 'leads@leadkit.com', 'Leadkit', $quoteBody);
$a = jeab_auto_on_message((string) $r['id']);
sm(($a['action'] ?? '') === 'quoted' && ($a['mall'] ?? '') === 'A' && ($a['from'] ?? '') === 'demo@example.test', 'Leadkit får mall A från sam@');
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
sm($s['subject'] === 'Solceller med batteri' && ($s['opts']['person_email'] ?? '') === jeab_gp_owner_email('sol', 'jarfalla') && ($s['opts']['reply_to_graph_id'] ?? 'x') === '', 'ämne, avsändare och nytt mejl');
sm(($s['to'] ?? '') === 'anna.lind@gmail.com', 'mottagaren är kunden i brödtexten');
sm(str_contains($s['body'], 'Hej Anna,') && str_contains($s['body'], 'Du har visat intresse för att installera solceller med oss!') && str_contains($s['body'], 'satelitbild') && str_contains($s['body'], 'https://flowstack.example.test/assets/img/medal-solpanel.png') && !str_contains($s['body'], 'https://flowstack.example.test/signatur/medal-solpanel.png'), 'mall A är det skickade köpt-mejlet');
sm(!str_contains($s['body'], '1. Du svarar') && str_contains($s['body'], 'Så jag skippar säljsnacket =)'), 'mall A är inte mall B');
sm(!str_contains($s['body'], 'Anna Lind') && !str_contains($s['body'], 'Solvägen 9, 176 74 Järfälla') && !str_contains($s['body'], '070-123 45 68') && !str_contains($s['body'], 'anna.lind@gmail.com'), 'inget kundblock efter signaturen');
sm(count($s['attachments']) === 1 && ($s['attachments'][0]['filename'] ?? '') === 'Solceller offert 2608 Demo Energi Järfälla.pdf' && str_starts_with((string) $s['attachments'][0]['content'], '%PDF'), 'bilagan är sol-PDF:en');
$th = null;
foreach (jeab_inkorg_threads() as $t) {
    if ($t['id'] === $r['thread_id']) {
        $th = $t;
    }
}
sm(is_array($th) && ($th['status'] ?? '') === 'closed', 'ärendet stängs');
$rem = jeab_get_reminder(jeab_store(), (int) $a['reminder']);
$whoGot = is_array($rem) ? (string) (($rem['assignee'] ?? '') !== '' ? $rem['assignee'] : ($rem['person'] ?? '')) : 'saknas';
sm(is_array($rem) && ($whoGot === 'Sam L' || $whoGot === 'SamL'), 'påminnelsen ligger på Sam L, fick ' . $whoGot);

$wixBody = "Namn:\n\nSara Ek\n\nAdress:\n\nEkvägen 2\n\nTelefon:\n\n070-222 33 44\n\nE-post:\n\nsara.ek@gmail.com\n";
$r = $ingest('wix', 'info@wix-forms.com', 'Formulär JEAB', $wixBody);
$a = jeab_auto_on_message((string) $r['id']);
sm(($a['mall'] ?? '') === 'B' && ($a['to'] ?? '') === 'sara.ek@gmail.com', 'Wix får mall B');
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
sm(str_contains($s['body'], 'Hej Sara,') && str_contains($s['body'], '1. Du svarar på detta mail eller ringer mig') && str_contains($s['body'], '2. Jag tittar på ditt hus på satelitbild') && str_contains($s['body'], 'https://flowstack.example.test/assets/img/medal-solpanel.png'), 'mall B är det skickade egna mejlet');
sm(!str_contains($s['body'], 'Du har visat intresse') && !str_contains($s['body'], 'prislista/offert') && $s['subject'] === 'Solceller med batteri', 'mall B har egen text och samma ämne');
sm(str_contains($s['body'], 'Hej Sara,') && !str_contains($s['body'], 'Sara Ek') && !str_contains($s['body'], 'Ekvägen 2') && !str_contains($s['body'], '070-222 33 44') && !str_contains($s['body'], 'sara.ek@gmail.com'), 'mall B har inget kundblock');

$own = "Hej, vi vill ha solceller på villan.\nNamn: Kim Alm\nE-post: kim.alm@gmail.com\nTelefon: 070-999 88 77\nAdress: Almvägen 3, 176 70 Järfälla\n";
$r = $ingest('own', 'kim.alm@gmail.com', 'Kim Alm', $own);
$a = jeab_auto_on_message((string) $r['id']);
sm(($a['mall'] ?? '') === 'B' && ($a['to'] ?? '') === 'kim.alm@gmail.com' && ($GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1]['opts']['reply_to_graph_id'] ?? 'x') === '', 'egen sajt är mall B och ett nytt mejl');

$dripCom = jeab_lead_customer([
    'from' => 'notifications@drip.com',
    'from_name' => 'Drip',
    'subject' => 'Hitta-Solceller via Drip',
    'body' => "Namn: Nora Drip\nE-post: nora.drip@gmail.com\nTelefon: 070-333 22 11\nAdress: Dripvägen 3\nPostnummer: 176 70\nOrt: Järfälla\n",
]);
sm($dripCom['platform'] === 'drip.com' && $dripCom['name'] === 'Nora Drip' && $dripCom['email'] === 'nora.drip@gmail.com' && $dripCom['address'] === 'Dripvägen 3, 176 70 Järfälla', 'drip.com läses ur brödtexten');
$leakBody = "Namn: Namn Anna Svensson\nE-post: E-post kund.b@example.invalid\nTelefon: Telefon 070-123 45 67\nAdress: Adress Decembervägen 10, 17760 Järfälla\n";
$parsed = jeab_lead_customer(['from' => 'leads@leadkit.com', 'from_name' => 'Avsändare', 'subject' => 'Ny lead', 'body' => $leakBody]);
sm($parsed['platform'] === 'leadkit.com' && $parsed['name'] === 'Anna Svensson' && $parsed['email'] === 'kund.b@example.invalid' && $parsed['phone'] === '070-123 45 67' && $parsed['address'] === 'Decembervägen 10, 17760 Järfälla', 'etiketten följer inte med värdet för leads@leadkit.com');
$parsed = jeab_lead_customer(['from' => 'kund.direkt@gmail.com', 'from_name' => 'Avsändare', 'subject' => 'Ny lead', 'body' => $leakBody]);
sm($parsed['platform'] === '' && $parsed['name'] === 'Anna Svensson' && $parsed['email'] === 'kund.direkt@gmail.com' && $parsed['email_from'] === 'from' && $parsed['phone'] === '070-123 45 67' && $parsed['address'] === 'Decembervägen 10, 17760 Järfälla' && !str_contains($parsed['address'], 'Adress ') && !str_contains($parsed['name'], 'Namn '), 'vanligt mejl tar namnet ur fältet och etiketten följer inte med');
$street = jeab_lead_customer(['from' => 'leads@leadkit.com', 'from_name' => 'Leadkit', 'subject' => 'Ny lead', 'body' => "Namn: Bo Ek\nAdress: Adressvägen 12, 17760 Järfälla\nE-post: bo.ek@gmail.com\n"]);
sm($street['address'] === 'Adressvägen 12, 17760 Järfälla' && $street['name'] === 'Bo Ek', 'Adressvägen är en gata, inte en etikett');
$nextLeak = jeab_lead_customer(['from' => 'support@leadkit.com', 'from_name' => 'Leadkit', 'subject' => 'Ny lead', 'body' => "Namn\nNamn Bo Ek\nAdress\nAdress Decembervägen 10, 17760 Järfälla\nTelefon\nTelefon 073-555 12 12\nE-post\nE-post bo.ek@gmail.com\n"]);
sm($nextLeak['name'] === 'Bo Ek' && $nextLeak['address'] === 'Decembervägen 10, 17760 Järfälla' && $nextLeak['phone'] === '073-555 12 12' && $nextLeak['email'] === 'bo.ek@gmail.com', 'etikett på nästa rad skalas av');

$partnerPhone = ['drip' => '070-411 00 01', 'dripcom' => '070-411 00 02', 'hitta' => '070-411 00 03', 'nu' => '070-411 00 04'];
foreach ([
    'drip' => 'notify@drip.se',
    'dripcom' => 'notifications@drip.com',
    'hitta' => 'lead@hitta-solceller.se',
    'nu' => 'lead@solceller.nu',
] as $id => $from) {
    $surname = ['drip' => 'Se', 'dripcom' => 'Com', 'hitta' => 'Hitta', 'nu' => 'Solnu'][$id];
    $body = str_replace(
        ['Anna Svensson', 'kund.b@example.invalid', '070-123 45 67'],
        ['Nora ' . $surname, 'nora.' . $id . '@gmail.com', $partnerPhone[$id]],
        $leadBody
    );
    $r = $ingest($id, $from, 'Lead', $body);
    $a = jeab_auto_on_message((string) $r['id']);
    $sent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
    sm(($a['mall'] ?? '') === 'A' && str_contains($sent['body'], 'Du har visat intresse'), $from . ' är mall A');
}
$weplyBody = str_replace(
    ['Anna Svensson', 'kund.b@example.invalid', '070-123 45 67'],
    ['Nora Weply', 'nora.weply@gmail.com', '070-411 00 05'],
    $leadBody
);
$r = $ingest('weply', 'support@weply.se', 'Weply', $weplyBody);
$a = jeab_auto_on_message((string) $r['id']);
$sent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
$weplyTo = mb_strtolower((string) ($sent['to'] ?? ''));
sm(($a['mall'] ?? '') === 'B' && $weplyTo === 'nora.weply@gmail.com' && !str_contains($weplyTo, '@weply.'), 'Weply är mall B och offerten går inte till weply');
$kalkyl = str_replace(
    ['Sara Ek', 'sara.ek@gmail.com', '070-222 33 44'],
    ['Eli Kalkyl', 'eli.kalkyl@gmail.com', '070-222 33 45'],
    $wixBody
);
$r = $ingest('kalkyl', 'form@kalkyl.jarfalla.example.test', 'Kalkyl', $kalkyl);
$a = jeab_auto_on_message((string) $r['id']);
sm(($a['mall'] ?? '') === 'B', 'JEAB-formuläret är mall B');

$qLabels = $labels;
$qLabels['question'] = 'Hur många paneler får plats?';
$qBody = str_replace(
    ['Anna Svensson', 'kund.b@example.invalid', '070-123 45 67'],
    ['Mia Fraga', 'mia.fraga@gmail.com', '070-511 00 01'],
    $leadBody
) . "\nHur många paneler får plats?\n";
$r = $ingest('q', 'leads@leadkit.com', 'Leadkit', $qBody, ['labels' => ['question' => 'Hur många paneler får plats?']]);
$a = jeab_auto_on_message((string) $r['id']);
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
sm(($a['mall'] ?? '') === 'A' && preg_match('~^<div[^>]*>Taket på bilden räcker till ungefär 12 paneler\.</div><div[^>]*><br></div><div[^>]*>Hej Mia,~u', $s['body']) === 1, 'svaret ligger ovanför mallen');
sm(str_contains($s['body'], 'Du har visat intresse'), 'mallen följer efter svaret');

$before = count($GLOBALS['_SENT']);
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['answer' => '']);
$failBody = str_replace(
    ['Anna Svensson', 'kund.b@example.invalid', '070-123 45 67'],
    ['Fail Fraga', 'fail.fraga@gmail.com', '070-511 00 02'],
    $leadBody
) . "Meddelande: Kan ni lova installation nästa vecka?\n";
$r = $ingest('q-fail', 'support@leadkit.com', 'Leadkit', $failBody, ['labels' => ['question' => 'Kan ni lova installation nästa vecka?']]);
$a = jeab_auto_on_message((string) $r['id']);
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
$safe = jeab_auto_answer_safe_line();
sm(($a['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $before + 1 && str_contains((string) ($s['body'] ?? ''), $safe) && !preg_match('/\d/', $safe), 'tomt svar skickar den säkra raden utan siffror');
$GLOBALS['_JEAB_REBOOK_LLM'] = function () { throw new RuntimeException('modell nere'); };
$before = count($GLOBALS['_SENT']);
$downBody = str_replace(
    ['Anna Svensson', 'kund.b@example.invalid', '070-123 45 67'],
    ['Nere Fraga', 'nere.fraga@gmail.com', '070-511 00 03'],
    $leadBody
) . "Meddelande: Ingår batteri?\n";
$r = $ingest('q-down', 'support@leadkit.com', 'Leadkit', $downBody, ['labels' => ['question' => 'Ingår batteri?']]);
$a = jeab_auto_on_message((string) $r['id']);
$downSent = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1] ?? [];
sm(($a['action'] ?? '') === 'quoted' && count($GLOBALS['_SENT']) === $before + 1 && str_contains((string) ($downSent['body'] ?? ''), 'Du har visat intresse') && !str_contains((string) ($downSent['body'] ?? ''), 'modell nere'), 'modellfel skickar mallen utan det korta svaret');
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['answer' => 'Taket på bilden räcker till ungefär 12 paneler.']);

$bad = jeab_inkorg_hook(['action' => 'inbox.auto_selftest', 'mall' => 'A', 'deliver_to' => 'kund@ex.se']);
sm(($bad['ok'] ?? true) === false, 'deliver_to till någon annan nekas');
$a = jeab_inkorg_hook(['action' => 'inbox.auto_selftest', 'mall' => 'A', 'deliver_to' => 'demo.person@example.invalid', 'email' => 'self.a@gmail.com', 'name' => 'Self A', 'phone' => '070-777 66 55', 'address' => 'Självvägen 9, 176 70 Järfälla']);
sm(($a['ok'] ?? false) === true && ($a['mall'] ?? '') === 'A' && ($a['auto']['mall'] ?? '') === 'A' && ($a['auto']['to'] ?? '') === 'demo.person@example.invalid', 'självtest deliver_to ger mall A');
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
sm(str_contains($s['body'], 'Du har visat intresse') && $s['subject'] === 'Solceller med batteri' && ($s['opts']['person_email'] ?? '') === 'demo@example.test', 'självtest A är sam@ och köpt-texten');
$b = jeab_inkorg_hook(['action' => 'inbox.auto_selftest', 'mall' => 'B', 'deliver_to' => 'demo@example.test', 'email' => 'self.b@gmail.com', 'name' => 'Self B', 'phone' => '070-777 66 56', 'address' => 'Självvägen 10, 176 70 Järfälla']);
sm(($b['ok'] ?? false) === true && ($b['auto']['mall'] ?? '') === 'B' && ($b['auto']['to'] ?? '') === 'demo@example.test', 'självtest deliver_to ger mall B');
$s = $GLOBALS['_SENT'][count($GLOBALS['_SENT']) - 1];
sm(str_contains($s['body'], '1. Du svarar på detta mail eller ringer mig') && str_contains($s['body'], 'https://flowstack.example.test/assets/img/medal-solpanel.png') && !str_contains($s['body'], 'Du har visat intresse'), 'självtest B är det egna mejlet');

echo "inkorg sol mall: ok\n";
