<?php

declare(strict_types=1);

/** Klassning av nya mejl: Historik, offertspärr, vyer och sök. Inget utskick. */

$tmp = sys_get_temp_dir() . '/jeab-klass-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function kk(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$src = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_klassning.php');
kk(!str_contains($src, 'file_put_contents') && !str_contains($src, 'jeab_data_file') && !str_contains($src, 'jeab_inkorg_locked'), 'klassningen skriver ingen JSON');
kk(!str_contains((string) file_get_contents(dirname(__DIR__) . '/src/shield.php'), 'klassning'), 'shield.php är orörd av klassningen');

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = function (array $a) {
    $GLOBALS['_SENT'][] = $a;

    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
foreach (['laddbox_jarfalla', 'batteri_jarfalla', 'sol_jarfalla'] as $pdfKey) {
    jeab_auto_pdf_save(['key' => $pdfKey, 'data' => base64_encode('%PDF-1.4 ' . $pdfKey)]);
}

$in = function (string $id, string $from, string $subject, string $text, array $labels): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@klass.test',
        'from' => $from,
        'from_name' => 'Anna Svensson',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => $subject,
        'body_text' => $text,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'preset_labels' => $labels,
    ]);
};
$thread = function (string $tid): array {
    foreach (jeab_inkorg_threads() as $t) {
        if ((string) ($t['id'] ?? '') === $tid) {
            return $t;
        }
    }

    return [];
};
$labels = function (string $type, string $intent, int $conf = 90, string $offer = ''): array {
    return [
        'type' => $type,
        'intent' => $intent,
        'confidence' => $conf,
        'action' => 'svara',
        'priority' => 2,
        'offer' => $offer,
        'needs_review' => false,
    ];
};

$cases = [
    'skrap' => $labels('Skräp', 'skrap', 95),
    'partner' => $labels('Information', 'information', 90),
    'gmail' => $labels('Information', 'information', 90),
    'rest' => $labels('Information', 'information', 90),
    'ladd' => $labels('Ny lead', 'kop', 95, 'laddbox'),
    'batt' => $labels('Ny lead', 'kop', 95, 'batteri'),
    'sol' => $labels('Ny lead', 'kop', 95, 'sol'),
    'support' => $labels('Support', 'support', 95),
];
kk(jeab_inkorg_klassning_route(['labels' => $cases['skrap'], 'from' => 'news@shop.se', 'subject' => 'Rea', 'body' => 'Rabatt']) === 'skrap', 'skräp går till skrap');
kk(jeab_inkorg_klassning_route(['labels' => $cases['partner'], 'from' => 'order@supplier.se', 'subject' => 'Orderbekräftelse', 'body' => 'Tack för ordern']) === 'information', 'leverantörens information går till information');
kk(jeab_inkorg_klassning_route(['labels' => $cases['gmail'], 'from' => 'anna@gmail.com', 'subject' => 'Info', 'body' => 'Hej']) === 'inkorg', 'kundens gmail-information stannar i inkorgen');
kk(jeab_inkorg_klassning_route(['labels' => $cases['rest'], 'from' => 'order@supplier.se', 'subject' => 'Orderbekräftelse restnoterad', 'body' => 'Ej i lager']) === 'inkorg', 'restnoterat stannar i inkorgen');
kk(jeab_inkorg_klassning_route(['labels' => $cases['ladd'], 'from' => 'anna@kund.example', 'subject' => 'Laddbox', 'body' => 'Vill ha laddbox']) === 'kop', 'elbilsladdare är köpintention');
kk(jeab_inkorg_klassning_route(['labels' => $cases['batt'], 'from' => 'anna@kund.example', 'subject' => 'Batteri', 'body' => 'Batteri till solceller']) === 'kop', 'batteri är köpintention');
kk(jeab_inkorg_klassning_route(['labels' => $cases['sol'], 'from' => 'anna@kund.example', 'subject' => 'Solceller', 'body' => 'Solceller med batteri']) === 'kop', 'solceller är köpintention');
kk(jeab_inkorg_klassning_route(['labels' => $cases['support'], 'from' => 'anna@kund.example', 'subject' => 'Hjälp', 'body' => 'Fungerar inte']) === 'inkorg', 'support stannar i inkorgen');

$spam = $in('spam', 'news@shop.se', 'Veckans reklam', 'Nyhetsbrev och rea', $cases['skrap']);
$spamAuto = jeab_auto_on_message((string) $spam['id']);
kk(($spamAuto['action'] ?? '') === 'historik' && ($spamAuto['sent'] ?? 1) === 0 && ($thread((string) $spam['thread_id'])['status'] ?? '') === 'closed' && empty($thread((string) $spam['thread_id'])['deleted_at']), 'reklam stängs till Historik');

$support = $in('support', 'anna@kund.example', 'Hjälp med anläggningen', 'Batteriet svarar inte', $cases['support']);
$supportAuto = jeab_auto_on_message((string) $support['id']);
kk(($supportAuto['action'] ?? '') === 'none' && ($thread((string) $support['thread_id'])['status'] ?? '') === 'open', 'support lämnas öppen');

$rest = $in('rest', 'order@supplier.se', 'Orderbekräftelse restnoterad', 'Artikeln är ej i lager', $cases['rest']);
$restAuto = jeab_auto_on_message((string) $rest['id']);
$restThread = $thread((string) $rest['thread_id']);
kk(($restAuto['action'] ?? '') === 'none' && ($restAuto['why'] ?? '') === 'restnoterad' && in_array('restnoterad', (array) ($restThread['tags'] ?? []), true) && ($restThread['status'] ?? '') === 'open', 'restnoterat taggas och lämnas öppet');
kk(jeab_inkorg_folder_thread($restThread, 'inkorg') && !jeab_inkorg_folder_thread($restThread, 'information'), 'restnoterat syns i Inkorg och inte i Information');

$info = $in('info', 'order@supplier.se', 'Månadsrapport från leverantören', 'Ingen åtgärd behövs.', $cases['partner']);
$infoAuto = jeab_auto_on_message((string) $info['id']);
$infoThread = $thread((string) $info['thread_id']);
kk(($infoAuto['action'] ?? '') === 'information' && ($infoAuto['sent'] ?? 1) === 0 && ($infoThread['status'] ?? '') === 'open' && jeab_inkorg_folder_thread($infoThread, 'information') && !jeab_inkorg_folder_thread($infoThread, 'inkorg') && !jeab_inkorg_folder_thread($infoThread, 'historik'), 'leverantörsinformation ligger öppen i Information');

kk($GLOBALS['_SENT'] === [], 'klassningen skickade inget');

$old = $in('old-batt', 'mira@kund.example', 'Batteri till befintliga solceller', 'Vi vill ha batteri.', $cases['batt']);
jeab_inkorg_thread_write((string) $old['thread_id'], static function (array $t): array {
    $t['quote_sent'] = ['source' => 'Skickat', 'date' => date('Y-m-d H:i', time() - 100 * 86400), 'product' => 'batteri'];
    $t['status'] = 'closed';

    return $t;
});
$oldMsg = jeab_inkorg_get((string) $old['id']);
$probe = ['id' => 'inte-sparad'];
$probeMsg = ['from' => 'mira@kund.example', 'from_name' => 'Anna Svensson', 'subject' => 'Ny förfrågan', 'body' => 'Hej', 'labels' => $cases['batt']];
kk(is_array($oldMsg) && jeab_inkorg_klassning_recent_quote($probe, $probeMsg, 'laddbox') === false, 'annan produkt inom sex månader stoppar inte offerten');
kk(jeab_inkorg_klassning_recent_quote($probe, $probeMsg, 'batteri'), 'samma produkt inom sex månader stoppar offerten');

$oldSol = $in('old-sol', 'mira@kund.example', 'Solceller', 'Offert på solceller.', $cases['sol']);
jeab_inkorg_thread_write((string) $oldSol['thread_id'], static function (array $t): array {
    $t['quote_sent'] = ['source' => 'Skickat', 'date' => date('Y-m-d H:i', time() - 100 * 86400), 'product' => 'sol'];
    $t['status'] = 'closed';

    return $t;
});
kk(jeab_inkorg_klassning_recent_quote($probe, $probeMsg, 'sol+batteri'), 'sol och sol med batteri är samma offert');

$stale = $in('stale', 'nol@kund.example', 'Batteri', 'Gammal offert.', $cases['batt']);
jeab_inkorg_thread_write((string) $stale['thread_id'], static function (array $t): array {
    $t['quote_sent'] = ['source' => 'Skickat', 'date' => date('Y-m-d H:i', time() - 200 * 86400), 'product' => 'batteri'];

    return $t;
});
$staleProbe = ['id' => 'inte-heller'];
$staleMsg = ['from' => 'nol@kund.example', 'from_name' => 'Nol', 'subject' => 'Batteri', 'body' => 'Hej', 'labels' => $cases['batt']];
kk(jeab_inkorg_klassning_recent_quote($staleProbe, $staleMsg, 'batteri') === false, 'offert äldre än sex månader stoppar inte');

$again = $in('again', 'mira@kund.example', 'Batteri igen', 'Vi vill ha ett batteri till solcellerna.', $cases['batt']);
$againAuto = jeab_auto_on_message((string) $again['id']);
$againThread = $thread((string) $again['thread_id']);
$remHit = false;
foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
    if (mb_strtolower((string) ($rem['email'] ?? '')) === 'mira@kund.example') {
        $remHit = true;
    }
}
$party = function (string $email): array {
    $email = mb_strtolower($email);
    $remHit = null;
    foreach (jeab_list_reminders(jeab_store(), true) as $rem) {
        if (mb_strtolower((string) ($rem['email'] ?? '')) === $email) {
            $remHit = $rem;
        }
    }
    $contact = false;
    $cid = is_array($remHit) ? trim((string) ($remHit['contact_id'] ?? '')) : '';
    $rows = jeab_kontakter_load();
    if ($cid !== '' && isset($rows[$cid])) {
        $contact = true;
    }
    if (!$contact) {
        foreach ($rows as $row) {
            foreach ((array) ($row['owners'] ?? []) as $owner) {
                if (is_array($owner) && mb_strtolower(trim((string) ($owner['email'] ?? ''))) === $email) {
                    $contact = true;
                }
            }
        }
    }

    return ['reminder' => is_array($remHit), 'contact' => $contact];
};
$inHistorik = function (array $t): bool {
    return ($t['status'] ?? '') === 'closed'
        && jeab_inkorg_folder_thread($t, 'historik')
        && !jeab_inkorg_folder_thread($t, 'inkorg');
};
kk(($againAuto['action'] ?? '') === 'quoted' && (int) ($againAuto['sent'] ?? 0) >= 1 && $inHistorik($againThread) && $remHit && ($party('mira@kund.example')['contact'] ?? false) && count($GLOBALS['_SENT']) === 1, 'sexmånadersspärren stoppar inte en ny offert, fick ' . (string) ($againAuto['action'] ?? '') . ' ' . (string) ($againAuto['why'] ?? ''));

$sentBefore = count($GLOBALS['_SENT']);
$fresh = $in('fresh', 'vera@kund.example', 'Laddbox till villan', 'Vi vill ha en laddbox.', $cases['ladd']);
$freshAuto = jeab_auto_on_message((string) $fresh['id']);
$freshThread = $thread((string) $fresh['thread_id']);
$freshParty = $party('vera@kund.example');
kk(($freshAuto['action'] ?? '') === 'quoted' && ($freshAuto['historik'] ?? 0) === 1 && $inHistorik($freshThread) && (string) (jeab_inkorg_thread_meta((string) $fresh['thread_id'])['hook_closed'] ?? '') === 'kop-skickad' && $freshParty['reminder'] && $freshParty['contact'] && count($GLOBALS['_SENT']) === $sentBefore + 1, 'skickad offert hamnar i Historik med kontakt och påminnelse');

jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
$heldBefore = count($GLOBALS['_SENT']);
$heldOff = $in('held-off', 'vera.off@kund.example', 'Batteri när utskick är av', 'Vi vill ha batteri till solcellerna.', $cases['batt']);
$heldOffAuto = jeab_auto_on_message((string) $heldOff['id']);
$heldOffThread = $thread((string) $heldOff['thread_id']);
$heldOffParty = $party('vera.off@kund.example');
kk(($heldOffAuto['action'] ?? '') === 'quoted' && ($heldOffAuto['historik'] ?? 0) === 1 && $inHistorik($heldOffThread) && (string) (jeab_inkorg_thread_meta((string) $heldOff['thread_id'])['hook_closed'] ?? '') === 'kop-skickad' && $heldOffParty['reminder'] && $heldOffParty['contact'] && count($GLOBALS['_SENT']) === $heldBefore + 1, 'avstängt utskick skickar ändå, fick ' . (string) ($heldOffAuto['action'] ?? '') . ' ' . (string) ($heldOffAuto['why'] ?? ''));

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => 'tillaten@kund.example',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
$listBefore = count($GLOBALS['_SENT']);
$heldList = $in('held-list', 'vera.lista@kund.example', 'Solceller utanför listan', 'Vi vill ha solceller med batteri.', $cases['sol']);
$heldListAuto = jeab_auto_on_message((string) $heldList['id']);
$heldListThread = $thread((string) $heldList['thread_id']);
$heldListParty = $party('vera.lista@kund.example');
kk(($heldListAuto['action'] ?? '') === 'quoted' && ($heldListAuto['historik'] ?? 0) === 1 && $inHistorik($heldListThread) && (string) (jeab_inkorg_thread_meta((string) $heldList['thread_id'])['hook_closed'] ?? '') === 'kop-skickad' && $heldListParty['reminder'] && $heldListParty['contact'] && count($GLOBALS['_SENT']) === $listBefore + 1, 'avsändarlistan stoppar inte offerten, fick ' . (string) ($heldListAuto['action'] ?? '') . ' ' . (string) ($heldListAuto['why'] ?? ''));
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);

$own = $in('own', 'bosse@kund.example', 'robertounik fråga', 'Behöver hjälp med växelriktaren.', $cases['support']);
$other = $in('other', 'elsa@kund.example', 'elinunik kvitto', 'En fråga till Elin om laddaren.', $cases['support']);
jeab_inkorg_assign((string) $own['thread_id'], 'Roberto');
jeab_inkorg_assign((string) $other['thread_id'], 'Elin');
jeab_inkorg_thread_set((string) $support['thread_id'], 'open', ['service']);
$service = $thread((string) $support['thread_id']);
kk(in_array('service', (array) ($service['manual_tags'] ?? []), true) && jeab_inkorg_folder_thread($service, 'service'), 'egen service-etikett syns i Service');
jeab_inkorg_thread_write((string) $other['thread_id'], static function (array $t): array {
    $t['tags'] = ['service'];
    $t['manual_tags'] = [];

    return $t;
});
kk(!jeab_inkorg_folder_thread($thread((string) $other['thread_id']), 'service'), 'automatisk service-tagg räknas inte som Service');

$plain = $in('plain', 'gammal@kund.example', 'Gammal kundfråga', 'Detta mejl klassas inte om.', $cases['support']);
$before = (string) ($thread((string) $plain['thread_id'])['status'] ?? '');
$plainMsg = (array) jeab_inkorg_get((string) $plain['id']);
kk(jeab_inkorg_klassning_route($plainMsg) === 'inkorg' && ($thread((string) $plain['thread_id'])['status'] ?? '') === $before && $before === 'open', 'gammal post klassas inte om');

$_SESSION['jeab_email'] = 'saljare3@jarfalla.example.test';
$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$ownList = jeab_inkorg_fast_prepare(true);
kk(($ownList['scope'] ?? '') === 'egna' && (int) ($ownList['total'] ?? 0) >= 1 && str_contains((string) ($ownList['html'] ?? ''), 'robertounik') && !str_contains((string) ($ownList['html'] ?? ''), 'elinunik'), 'Egna visar bara egna ärenden');
$_GET['q'] = 'elinunik';
$wide = jeab_inkorg_fast_prepare(true);
kk(!empty($wide['search_widened']) && str_contains((string) ($wide['html'] ?? ''), 'elinunik'), 'tom egen sökning visar andras träffar');
$_GET['q'] = 'robertounik';
$mine = jeab_inkorg_fast_prepare(true);
kk(empty($mine['search_widened']) && str_contains((string) ($mine['html'] ?? ''), 'robertounik') && !str_contains((string) ($mine['html'] ?? ''), 'Hos andra medarbetare'), 'träff bara bland egna visar inga andra');
$_GET['q'] = 'unik';
$both = jeab_inkorg_fast_prepare(true);
$bothHtml = (string) ($both['html'] ?? '');
$ownAt = strpos($bothHtml, 'robertounik');
$headAt = strpos($bothHtml, 'Hos andra medarbetare');
$otherAt = strpos($bothHtml, 'elinunik');
kk(empty($both['search_widened']) && $ownAt !== false && $headAt !== false && $otherAt !== false && $ownAt < $headAt && $headAt < $otherAt, 'egna träffar visas före andra medarbetare');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'q' => 'elinunik'];
$html = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
kk(str_contains($html, '>Egna</a>') && str_contains($html, 'data-ix-scope="allas"') && str_contains($html, '>Alla</a>') && !str_contains($html, '>Allas</a>') && !str_contains($html, 'Alla typer') && !str_contains($html, '>Filter</summary>'), 'sidan visar Egna och Alla utan filterknapp');
kk(str_contains($html, 'Inget bland dina ärenden. Träffar hos andra medarbetare.'), 'sidan förklarar den vidgade sökningen');
preg_match_all('/<a class="smart-tile[^"]*".*?<span>([^<]*)<\/span>/s', $html, $tiles);
kk(($tiles[1] ?? []) === ['Inkorg', 'Service', 'Information', 'Alla', 'Historik'], 'översta raden är Inkorg, Service, Information, Alla och Historik');

$router = $tmp . '/router.php';
file_put_contents($router, "<?php\n\$GLOBALS['_JEAB_DATA_DIR'] = getenv('JEAB_DATA');\nputenv('JEAB_DISABLE_SQLITE=1');\nputenv('XAI_API_KEY');\nrequire getenv('JEAB_ROOT') . '/src/bootstrap.php';\nrequire getenv('JEAB_ROOT') . '/src/layout.php';\nif (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }\n\$_SESSION['jeab_email'] = 'saljare3@jarfalla.example.test';\n\$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'q' => 'elinunik'];\n\$_SERVER['REQUEST_METHOD'] = 'GET';\n\$_SERVER['SCRIPT_NAME'] = '/index.php';\necho include getenv('JEAB_ROOT') . '/src/pages/inkorg.php';\n");
$port = 0;
for ($try = 8171; $try < 8190; $try++) {
    $socket = @stream_socket_server('tcp://127.0.0.1:' . $try, $errno, $errstr);
    if ($socket === false) {
        continue;
    }
    fclose($socket);
    $port = $try;
    break;
}
kk($port > 0, 'en lokal port är ledig');
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, $router],
    [1 => ['file', $tmp . '/server.log', 'a'], 2 => ['file', $tmp . '/server.log', 'a']],
    $pipes,
    $tmp,
    ['JEAB_DATA' => $tmp, 'JEAB_ROOT' => dirname(__DIR__), 'JEAB_DISABLE_SQLITE' => '1']
);
kk(is_resource($server), 'php-servern startade');
$code = 0;
$body = '';
for ($i = 0; $i < 20; $i++) {
    $curl = curl_init('http://127.0.0.1:' . $port . '/');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $body = (string) curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($code === 200 && str_contains($body, '>Egna</a>')) {
        break;
    }
    usleep(150000);
}
if (is_resource($server)) {
    proc_terminate($server);
    proc_close($server);
}
kk($code === 200 && str_contains($body, '>Egna</a>') && str_contains($body, '>Alla</a>') && !str_contains($body, '>Allas</a>') && str_contains($body, 'elinunik') && !str_contains($body, '>Filter</summary>'), 'curl ser Egna, Alla och den vidgade träffen, fick ' . $code);

echo "klassning ok\n";
