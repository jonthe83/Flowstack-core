<?php

declare(strict_types=1);

/** Solkampanj: import, ett mejl i taget, testläge och personbrevlåda. Inget riktigt utskick. */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-solkampanj-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
mkdir($tmp . '/privat', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_SOLKAMPANJ_DIR'] = $tmp . '/privat/solkampanj';
$GLOBALS['_JEAB_APP_ROOT'] = $tmp . '/public';
mkdir($tmp . '/public', 0775, true);
putenv('JEAB_DISABLE_SQLITE');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'solkampanj-token',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function sk(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$calls = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$calls): array {
    $calls[] = $opts;
    return ['ok' => true, 'path' => 'graph', 'error' => null, 'id' => 'm-1', 'from' => (string) ($opts['person_from'] ?? '')];
};

sk(jeab_driver() === 'sqlite', 'sqlite');
sk(jeab_solkampanj_first_name('Bergström, ANNA MARIA') === 'Anna', 'efternamn, förnamn');
sk(jeab_solkampanj_first_name('ÅSA NILSSON') === 'Åsa', 'versalt förnamn');
sk(jeab_solkampanj_first_name('anna-maria svensson') === 'Anna-Maria', 'bindestreck');
sk(jeab_solkampanj_first_name('Erik') === 'Erik', 'enkelt namn');
sk(jeab_solkampanj_test_mode() === true, 'testläge är på tills det stängs av');

$csv = "kundnummer;namn;gata;postnr;ort;mejl;saldo\n"
    . "10;Bergström, ANNA;Solgatan 1;177 31;Järfälla;anna@kund.se;100\n"
    . "11;Erik Eriksson;Mångatan 2;177 32;Järfälla;;0\n"
    . "12;ÅSA NILSSON;Takvägen 3;177 33;Järfälla;asa@kund.se;50\n";
$stats = jeab_solkampanj_import_csv($csv);
sk($stats['imported'] === 2 && $stats['skipped'] === 1, 'rader utan mejl hoppas över');
sk(is_file($GLOBALS['_JEAB_SOLKAMPANJ_DIR'] . '/recipients.csv') === false, 'import_csv själv skriver inte filen');
$GLOBALS['_JEAB_FORTNOX_KUND_FAKTURASUMMOR_FACTORY'] = static function (string $account): object {
    return new class($account) extends FortnoxAPI {
        public function __construct(private string $accountName)
        {
        }

        public function listAll(string $path, string $key, array $query = [], int $sleepUs = 250000): array
        {
            return [];
        }
    };
};
$saved = jeab_solkampanj_import_dispatch(['csv' => $csv]);
unset($GLOBALS['_JEAB_FORTNOX_KUND_FAKTURASUMMOR_FACTORY']);
sk(!empty($saved['ok']) && is_file($GLOBALS['_JEAB_SOLKAMPANJ_DIR'] . '/recipients.csv'), 'hooken sparar CSV i privat');
$real = realpath($GLOBALS['_JEAB_SOLKAMPANJ_DIR']);
$web = realpath($tmp . '/public');
sk(is_string($real) && is_string($web) && !str_starts_with($real, $web), 'listan ligger utanför webbroten');
sk($saved['updated'] === 2 && $saved['imported'] === 0, 'samma fil uppdaterar och dubblerar inte');

$next = jeab_solkampanj_next();
sk(is_array($next) && (string) $next['first_name'] === 'Anna' && (string) $next['mejl'] === 'anna@kund.se', 'första väntande är filordning');
$png = base64_encode((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$quote = ['antal' => '12', 'kwh' => '800', 'pris' => '94000', 'image' => 'data:image/png;base64,' . $png];
$built = jeab_solkampanj_text($next, jeab_solkampanj_quote($quote));
$html = jeab_solkampanj_html($built['text'], $built['address']);
sk($built['subject'] === 'Solceller till Solgatan 1?', 'ämnet är gatan');
sk(str_contains($built['text'], 'Hej Anna,'), 'hälsning');
sk(str_contains($built['text'], '12 paneler') && str_contains($built['text'], '800 kWh') && str_contains($built['text'], '94 000 kr'), 'siffrorna i brödtexten');
sk(str_contains($html, 'cid:solkampanj-bild'), 'bilden är inline');
sk(str_contains(trim(strip_tags($html)), jeab_solkampanj_copy()['inspections']), 'besiktningsraden');
sk(str_contains($html, 'Sam Exempel') && str_contains($html, 'Elektriker') && str_contains($html, '08-000 00 02'), 'befintlig signatur');
sk(str_contains($html, 'medal-solpanel.png'), 'loggan');
sk(str_ends_with(trim(strip_tags($html)), 'Solgatan 1, 177 31 Järfälla'), 'adressen sist');

$sent = jeab_solkampanj_send(['id' => (int) $next['id']] + $quote, 'Sam Exempel');
sk(!empty($sent['ok']) && count($calls) === 1, 'skicka använder systemmejlet en gång');
$mail = $calls[0];
sk(($mail['to'] ?? '') === 'demo.person@example.invalid', 'testläge går till Sam gmail');
sk(($mail['person_from'] ?? '') === 'demo@example.test', 'avsändaren är Sams brevlåda');
sk(($mail['from_email'] ?? '') === 'demo@example.test', 'From är samma adress');
sk(($mail['guard_mode'] ?? '') === 'manual' && ($mail['guard_via'] ?? '') === 'manual', 'manuellt utskick');
sk((string) ($mail['html'] ?? '') !== '' && str_contains((string) $mail['html'], 'cid:solkampanj-bild'), 'HTML med CID följer med');
$files = $mail['attachments'] ?? [];
$brochureFile = jeab_solkampanj_brochure_attachment();
$brochureDisk = is_array($brochureFile) ? (string) $brochureFile['content'] : '';
$brochureOf = static function (array $attachments) : ?array {
    foreach ($attachments as $att) {
        if (is_array($att) && ($att['filename'] ?? '') === JEAB_SOLKAMPANJ_BROCHURE_NAME) {
            return $att;
        }
    }

    return null;
};
$brochure = is_array($files) ? $brochureOf($files) : null;
$quoteNames = [];
foreach (is_array($files) ? $files : [] as $att) {
    if (is_array($att)) {
        $quoteNames[] = (string) ($att['filename'] ?? '');
    }
}
sk(is_array($files) && count($files) === 2 && ($files[0]['content_id'] ?? '') === 'solkampanj-bild' && !in_array('soloofferten.pdf', $quoteNames, true), 'bilden och broschyren när offert-PDF saknas');
sk(
    jeab_solkampanj_test_mode()
    && ($mail['to'] ?? '') === JEAB_SOLKAMPANJ_TEST_TO
    && is_array($brochure)
    && ($brochure['content_type'] ?? '') === 'application/pdf'
    && ($brochure['content'] ?? '') === $brochureDisk
    && str_starts_with($brochureDisk, '%PDF-')
    && !isset($brochure['content_id']),
    'testläge har broschyren i utgående bilagor'
);
$again = jeab_solkampanj_send(['id' => (int) $next['id']] + $quote, 'Sam Exempel');
$annaNow = jeab_solkampanj_fetch(jeab_solkampanj_pdo(), (int) $next['id']);
sk(!empty($again['ok']) && empty($again['already']) && count($calls) === 2, 'testläge skickar igen utan att räknas som redan skickat');
sk(is_array($annaNow) && ($annaNow['status'] ?? '') === 'pending' && trim((string) ($annaNow['sent_by'] ?? '')) === '', 'testläge lämnar mottagaren väntande');
sk(is_array($sent['next']) && ($sent['next']['mejl'] ?? '') === 'anna@kund.se' && ($again['next']['mejl'] ?? '') === 'anna@kund.se', 'testläge flyttar inte kön');

jeab_solkampanj_set_test_mode(false);
sk(jeab_solkampanj_test_mode() === false, 'testläge kan stängas av');
$asaId = (int) jeab_solkampanj_pdo()->query("SELECT id FROM solkampanj_recipients WHERE mejl = 'asa@kund.se'")->fetchColumn();
$skip = jeab_solkampanj_skip(['id' => $asaId], 'Sam Exempel');
sk(!empty($skip['ok']) && count($calls) === 2 && ($skip['next']['mejl'] ?? '') === 'anna@kund.se', 'hoppa över skickar inget och testet lämnade kön');
sk($skip['progress']['sent'] === 0 && $skip['progress']['skipped'] === 1 && $skip['progress']['pending'] === 1 && $skip['progress']['total'] === 1, 'förloppet är skickade plus väntande, utan överhoppade');

$pdo = jeab_solkampanj_pdo();
$pdo->prepare("UPDATE solkampanj_recipients SET status = 'pending', sent_at = NULL, sent_by = '' WHERE mejl = 'anna@kund.se'")->execute();
$calls = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$calls): array {
    $calls[] = $opts;
    return ['ok' => false, 'path' => '', 'error' => 'Graph avvisade', 'id' => ''];
};
$fail = jeab_solkampanj_send(['id' => (int) $next['id']] + $quote, 'Sam Exempel');
sk(empty($fail['ok']) && count($calls) === 1, 'misslyckat utskick anropas en gång');
$back = jeab_solkampanj_fetch($pdo, (int) $next['id']);
sk(is_array($back) && (string) $back['status'] === 'pending', 'misslyckat utskick lämnar mottagaren väntande');

jeab_send_guard_ensure($pdo);
$pdo->prepare('UPDATE send_guard_state SET killed = 1 WHERE id = 1')->execute();
$calls = [];
$blocked = jeab_solkampanj_send(['id' => (int) $next['id']] + $quote, 'Sam Exempel');
sk(empty($blocked['ok']) && count($calls) === 0 && str_contains((string) ($blocked['error'] ?? ''), 'stoppade'), 'spärren stoppar även ett manuellt klick');
$still = jeab_solkampanj_fetch($pdo, (int) $next['id']);
sk(is_array($still) && (string) $still['status'] === 'pending', 'spärren ändrar inte status');
$pdo->prepare('UPDATE send_guard_state SET killed = 0 WHERE id = 1')->execute();

jeab_solkampanj_save_pdf("%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
sk(jeab_solkampanj_pdf_ready(), 'PDF:en räknas bara om den är en PDF');
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$calls): array {
    $calls[] = $opts;
    return ['ok' => true, 'path' => 'graph', 'error' => null, 'id' => 'm-2', 'from' => 'demo@example.test'];
};
$calls = [];
$withPdf = jeab_solkampanj_send(['id' => (int) $next['id']] + $quote, 'Sam Exempel');
sk(!empty($withPdf['ok']), 'utskick med PDF');
$names = array_map(static fn (array $att): string => (string) ($att['filename'] ?? ''), $calls[0]['attachments'] ?? []);
$liveBrochure = $brochureOf($calls[0]['attachments'] ?? []);
sk(in_array('soloofferten.pdf', $names, true) && ($calls[0]['to'] ?? '') === 'anna@kund.se', 'offert-PDF bifogas och test av går till kunden');
sk(is_array($liveBrochure) && ($liveBrochure['content'] ?? '') === $brochureDisk, 'broschyren följer med även när testläge är av');

sk(jeab_solkampanj_ort('JÄRFÄLLA', '111 22') === 'Järfälla', 'JÄRFÄLLA och Järfälla är samma ort');
sk(jeab_solkampanj_ort('  uppsala ', '') === 'Uppsala', 'orten trimmas');
sk(jeab_solkampanj_ort('Bromma', '176 70') === 'Järfälla', 'postnummer 176 räknas som Järfälla');
sk(jeab_solkampanj_ort('Viksjö', '17564') === 'Järfälla', 'postnummer 175 räknas som Järfälla');
sk(jeab_solkampanj_ort('Jakobsberg', '177 57') === 'Järfälla', 'postnummer 177 räknas som Järfälla');
sk(jeab_solkampanj_ort('STOCKHOLM', '191 00') === 'Stockholm', 'övriga postnummer behåller orten');

$towns = "kundnummer;namn;gata;postnr;ort;mejl\n"
    . "40;Bo Berg;Storgatan 1;191 00;STOCKHOLM;bo@kund.se\n"
    . "40;Bo Berg;Nya gatan 9;191 00;stockholm;bo@kund.se\n"
    . "41;Mia Mil;Ängsvägen 2;753 10;uppsala;mia@kund.se\n"
    . "42;Kim Klang;Parkvägen 3;176 70;Bromma;kim@kund.se\n"
    . "43;Samma En;A 1;191 00;Stockholm;samma@kund.se\n"
    . "44;Samma Två;B 2;191 00;Stockholm;samma@kund.se\n";
$townStats = jeab_solkampanj_import_csv($towns);
sk($townStats['imported'] === 5 && $townStats['updated'] === 1, 'samma mejl och kundnummer i filen blir en rad');
$pdo = jeab_solkampanj_pdo();
$bo = $pdo->query("SELECT id, gata, ort, status FROM solkampanj_recipients WHERE mejl = 'bo@kund.se'")->fetchAll(PDO::FETCH_ASSOC);
sk(count($bo) === 1 && ($bo[0]['gata'] ?? '') === 'Nya gatan 9' && ($bo[0]['ort'] ?? '') === 'Stockholm' && ($bo[0]['status'] ?? '') === 'pending', 'dubbletten uppdaterar gatan och lämnar status');
$kim = $pdo->query("SELECT ort FROM solkampanj_recipients WHERE mejl = 'kim@kund.se'")->fetch(PDO::FETCH_ASSOC);
sk(is_array($kim) && ($kim['ort'] ?? '') === 'Järfälla', '176 grupperas under Järfälla');
$sameMail = (int) $pdo->query("SELECT COUNT(*) FROM solkampanj_recipients WHERE mejl = 'samma@kund.se'")->fetchColumn();
sk($sameMail === 2, 'samma mejl med olika kundnummer är två mottagare');
sk(jeab_solkampanj_active_ort() === 'Järfälla' && (jeab_solkampanj_next()['mejl'] ?? '') === 'kim@kund.se', 'utan val är nästa mottagare i Järfälla');
$GLOBALS['_JEAB_SOLKAMPANJ_USER'] = 'fp:cccccccccccccccc';
sk(jeab_solkampanj_active_ort() === 'Järfälla' && (jeab_solkampanj_next()['mejl'] ?? '') === 'kim@kund.se', 'en användare utan sparat val får Järfälla');
unset($GLOBALS['_JEAB_SOLKAMPANJ_USER']);
$noUser = jeab_solkampanj_remember_ort('Stockholm');
sk(empty($noUser['ok']), 'utan användare sparas ingen ort');
$callsBefore = count($calls);
$GLOBALS['_JEAB_SOLKAMPANJ_USER'] = 'fp:aaaaaaaaaaaaaaaa';
$picked = jeab_solkampanj_remember_ort('Uppsala');
sk(!empty($picked['ok']) && ($picked['next']['mejl'] ?? '') === 'mia@kund.se', 'vald ort styr nästa mottagare');
$otherTown = jeab_solkampanj_send(['id' => (int) $bo[0]['id']] + $quote, 'Test');
sk(empty($otherTown['ok']) && count($calls) === $callsBefore, 'en annan ort går inte att skicka');
$stillBo = jeab_solkampanj_fetch($pdo, (int) $bo[0]['id']);
sk(is_array($stillBo) && ($stillBo['status'] ?? '') === 'pending', 'nekad ort lämnar raden väntande');
$GLOBALS['_JEAB_SOLKAMPANJ_USER'] = 'fp:bbbbbbbbbbbbbbbb';
$stockholm = jeab_solkampanj_remember_ort('Stockholm');
sk(!empty($stockholm['ok']) && ($stockholm['next']['mejl'] ?? '') === 'bo@kund.se', 'varje användare har sin ort');
$GLOBALS['_JEAB_SOLKAMPANJ_USER'] = 'fp:aaaaaaaaaaaaaaaa';
sk((jeab_solkampanj_next()['mejl'] ?? '') === 'mia@kund.se', 'första användarens ort ligger kvar');
$pdo->prepare("UPDATE solkampanj_recipients SET status = 'sent', sent_at = '2020-01-01T00:00:00+00:00', sent_by = 'Test' WHERE mejl = 'bo@kund.se'")->execute();
$pdo->prepare("UPDATE solkampanj_recipients SET status = 'skipped', sent_at = '2020-01-01T00:00:00+00:00', sent_by = 'Test' WHERE mejl = 'mia@kund.se'")->execute();
$rerun = jeab_solkampanj_import_csv(
    "kundnummer;namn;gata;postnr;ort;mejl\n"
    . "40;Bo Berg;Ändrad gata 1;191 00;STOCKHOLM;bo@kund.se\n"
    . "41;Mia Mil;Ändrad äng 2;753 10;UPPSALA;mia@kund.se\n"
);
sk($rerun['imported'] === 0 && $rerun['updated'] === 2, 'omimport uppdaterar och lägger inte nya rader');
$boAgain = jeab_solkampanj_fetch($pdo, (int) $bo[0]['id']);
$miaId = (int) $pdo->query("SELECT id FROM solkampanj_recipients WHERE mejl = 'mia@kund.se'")->fetchColumn();
$miaAgain = jeab_solkampanj_fetch($pdo, $miaId);
sk(is_array($boAgain) && ($boAgain['status'] ?? '') === 'sent' && ($boAgain['gata'] ?? '') === 'Ändrad gata 1', 'skickad rad skickas inte om');
sk(is_array($miaAgain) && ($miaAgain['status'] ?? '') === 'skipped' && ($miaAgain['gata'] ?? '') === 'Ändrad äng 2', 'överkörd rad förblir överkörd');
$pdo->prepare("UPDATE solkampanj_recipients SET ort = 'JÄRFÄLLA', postnr = '111 22' WHERE mejl = 'kim@kund.se'")->execute();
jeab_solkampanj_normalize_orts($pdo);
$kimAgain = $pdo->query("SELECT ort FROM solkampanj_recipients WHERE mejl = 'kim@kund.se'")->fetch(PDO::FETCH_ASSOC);
sk(is_array($kimAgain) && ($kimAgain['ort'] ?? '') === 'Järfälla', 'sparad versal ort normaliseras');
unset($GLOBALS['_JEAB_SOLKAMPANJ_USER']);

$root = (string) file_get_contents(dirname(__DIR__) . '/solkampanj.php');
$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/solkampanj.php');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/solkampanj.php');
$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$shield = (string) file_get_contents(dirname(__DIR__) . '/src/shield.php');
sk(str_contains($root, "require_once __DIR__ . '/src/shield.php'") && str_contains($root, 'jeab_gate()') && str_contains($root, 'jeab_is_admin()'), 'sidan ligger bakom inloggning och admin');
sk(str_contains($page, 'id="paste-zone"') && str_contains($page, 'contenteditable="true"') && str_contains($page, 'Klistra in skärmdump här (Ctrl+V)'), 'klistrazon under bildfältet');
sk(substr_count($page, "addEventListener('paste'") >= 2 && str_contains($page, 'data.items') && str_contains($page, 'getAsFile()') && str_contains($page, 'data.files'), 'paste på zon och dokument läser items och files');
sk(str_contains($page, 'Välj bild') && str_contains($page, "addEventListener('drop'") && str_contains($page, "data.append('image', imageUrl)") && !preg_match('/type="file"[^>]*name="image"/', $page), 'välj bild och släpp använder samma image-värde');
sk(str_contains($page, 'id="ort"') && !str_contains($page, 'name="csv"'), 'sidan väljer ort och har ingen uppladdning av listan');
sk(str_contains($page, "(int) \$solkampanjProgress['sent'] + (int) \$solkampanjProgress['pending']") && str_contains($page, "(progress.sent || 0) + (progress.pending || 0)"), 'räknaren visar skickade av väntande plus skickade');
$applyAt = strpos($src, 'function jeab_solkampanj_apply_settings');
$apply = $applyAt === false ? '' : substr($src, $applyAt);
sk($apply !== '' && !str_contains($apply, 'import_csv') && !str_contains($apply, 'import_dispatch'), 'inställningarna importerar inte mottagarlistan');
sk(str_contains($root, 'solkampanj.ort'), 'orten sparas från sidan');
sk(str_contains($src, 'Vi har ju varit i kontakt tidigare om laddbox') && str_contains($page, 'jeab_solkampanj_copy()'), 'sidan visar samma brödtext');
sk(preg_match('/(?<![A-Za-z0-9_])mail\s*\(/', $src) !== 1 && str_contains($src, 'jeab_system_mail_send'), 'utskicket går via systemmejlet');
sk(str_contains($hook, "solkampanj.import") && str_contains($hook, 'jeab_inkorg_hook_authorized'), 'hooken solkampanj.import kräver token');
$hookAt = strpos($hook, "=== 'solkampanj.test_send'");
$hookSlice = $hookAt === false ? '' : substr($hook, $hookAt, 900);
sk($hookAt !== false && str_contains($hookSlice, 'jeab_inkorg_hook_authorized') && str_contains($hook, 'solkampanj.test_consumed') && str_contains($hook, 'solkampanj.reset_pending'), 'testhookarna kräver token');
sk(!str_contains($shield, 'solkampanj'), 'shield.php är orörd');

unset($GLOBALS['JEAB_SYSTEM_MAIL']);
$graphCalls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body) use (&$graphCalls): array {
    $graphCalls[] = ['method' => $method, 'url' => $url, 'body' => $body];
    if (strtoupper($method) === 'POST' && str_contains($url, 'sendMail')) {
        $message = is_array($body) ? ($body['message'] ?? []) : [];
        return ['code' => 202, 'body' => ['id' => 'g-sol'], 'message' => $message];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};
$person = jeab_system_mail_send([
    'to' => 'kund@kund.se',
    'subject' => 'Solceller till Solgatan 1?',
    'text' => 'Hej Anna,',
    'html' => '<p>Hej Anna,</p><img src="cid:solkampanj-bild">',
    'company' => 'jarfalla',
    'from_email' => 'demo@example.test',
    'from_name' => 'Sam Exempel',
    'person_from' => 'demo@example.test',
    'guard_mode' => 'manual',
    'guard_via' => 'manual',
    'guard_actor' => 'Sam Exempel',
    'attachments' => [[
        'filename' => 'solceller.png',
        'content_type' => 'image/png',
        'content' => (string) base64_decode($png),
        'content_id' => 'solkampanj-bild',
    ]],
]);
sk(!empty($person['ok']) && ($person['from'] ?? '') === 'demo@example.test', 'Graph-reserven skickar från Sams brevlåda');
$sendMail = null;
foreach ($graphCalls as $call) {
    if (($call['method'] ?? '') === 'POST' && str_contains((string) $call['url'], 'sendMail')) {
        $sendMail = $call;
    }
}
sk(is_array($sendMail), 'sendMail anropas');
$reply = $sendMail['body']['message']['replyTo'][0]['emailAddress']['address'] ?? '';
$fromUser = rawurldecode((string) parse_url((string) $sendMail['url'], PHP_URL_PATH));
sk($reply === 'demo@example.test', 'Reply-To är samma brevlåda');
sk(str_contains($fromUser, 'demo@example.test'), 'Graph-anropet går mot Sams brevlåda');
$inline = $sendMail['body']['message']['attachments'][0] ?? [];
sk(($inline['isInline'] ?? false) === true && ($inline['contentId'] ?? '') === 'solkampanj-bild', 'bilden är en CID-bilaga');
$hs = false;
foreach ($graphCalls as $call) {
    if (str_contains((string) ($call['url'] ?? ''), 'helpscout')) {
        $hs = true;
    }
}
sk($hs === false, 'Help Scout anropas inte för en personlig From');

$brochurePath = jeab_solkampanj_brochure_path();
$brochureRel = '/src/solkampanj/brochure.php';
$brochureSrc = (string) file_get_contents($brochurePath);
$placeholder = (string) file_get_contents(jeab_solkampanj_brochure_placeholder_path());
sk(str_ends_with(str_replace('\\', '/', $brochurePath), $brochureRel), 'broschyren ligger i brochure.php');
sk(str_starts_with($brochureSrc, '<?php') && str_contains($brochureSrc, 'http_response_code(403)') && !str_contains($brochureSrc, '%PDF-'), 'PHP-filen svarar 403 och innehåller inte råa PDF-bytes');
sk($brochureDisk !== '' && strlen($brochureDisk) === 801029 && str_starts_with($brochureDisk, '%PDF-'), 'include ger broschyren');
sk($placeholder === 'x', 'gamla PDF-sökvägen är en byte och skrivs över på plats');
sk(jeab_http_path_is_sensitive($brochureRel) === true, 'broschyrens adress är spärrad');
sk(jeab_http_path_is_sensitive('/src/solkampanj/jarfalla-energi-solceller.pdf') === true, 'platshållarens adress är spärrad i skölden');
sk(jeab_http_path_is_sensitive('/data/.private/solkampanj/jarfalla-energi-solceller.pdf') === true, 'data/.private är fortfarande spärrad');

jeab_solkampanj_set_test_mode(true);
$added = jeab_solkampanj_import_csv("kundnummer;namn;gata;postnr;ort;mejl\n90;Nora Nilsson;Broschyrgatan 1;177 40;Järfälla;nora@kund.se\n");
sk(($added['imported'] ?? 0) === 1, 'mottagare för payload-test');
$noraId = (int) $pdo->query("SELECT id FROM solkampanj_recipients WHERE mejl = 'nora@kund.se'")->fetchColumn();
$graphCalls = [];
$payloadSent = jeab_solkampanj_send(['id' => $noraId] + $quote, 'Sam Exempel');
sk(!empty($payloadSent['ok']) && jeab_solkampanj_test_mode(), 'testläge skickar via systemmejlets Graph-reserv');
$payloadMail = null;
foreach ($graphCalls as $call) {
    if (($call['method'] ?? '') === 'POST' && str_contains((string) ($call['url'] ?? ''), 'sendMail')) {
        $payloadMail = $call;
    }
}
sk(is_array($payloadMail), 'sendMail är den utgående payloaden');
$payloadTo = (string) ($payloadMail['body']['message']['toRecipients'][0]['emailAddress']['address'] ?? '');
sk($payloadTo === JEAB_SOLKAMPANJ_TEST_TO, 'testlägets payload går till testadressen');
$payloadPdf = null;
foreach ((array) ($payloadMail['body']['message']['attachments'] ?? []) as $att) {
    if (is_array($att) && ($att['name'] ?? '') === JEAB_SOLKAMPANJ_BROCHURE_NAME) {
        $payloadPdf = $att;
    }
}
$payloadBytes = is_array($payloadPdf) ? base64_decode((string) ($payloadPdf['contentBytes'] ?? ''), true) : false;
sk(
    is_array($payloadPdf)
    && ($payloadPdf['contentType'] ?? '') === 'application/pdf'
    && empty($payloadPdf['isInline'])
    && $payloadBytes === $brochureDisk,
    'testlägets payload innehåller broschyren'
);
$payloadHs = false;
foreach ($graphCalls as $call) {
    if (str_contains((string) ($call['url'] ?? ''), 'helpscout')) {
        $payloadHs = true;
    }
}
sk($payloadHs === false, 'payload-testet anropar inte Help Scout');
$nora = jeab_solkampanj_fetch($pdo, $noraId);
sk(is_array($nora) && ($nora['status'] ?? '') === 'pending', 'payload-testet i testläge förbrukar inte raden');
jeab_solkampanj_set_test_mode(false);
$beforeDenied = count($graphCalls);
$deniedSend = jeab_solkampanj_test_send(['id' => $noraId] + $quote, 'Sam Exempel');
sk(empty($deniedSend['ok']) && (int) ($deniedSend['status'] ?? 0) === 403 && count($graphCalls) === $beforeDenied, 'test_send utan admin skickar inget');
$sendMails = static function (array $calls): int {
    $n = 0;
    foreach ($calls as $call) {
        if (($call['method'] ?? '') === 'POST' && str_contains((string) ($call['url'] ?? ''), 'sendMail')) {
            $n++;
        }
    }

    return $n;
};
$beforeGraph = $sendMails($graphCalls);
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';
$adminSend = jeab_solkampanj_test_send(['id' => $noraId] + $quote, 'Sam Exempel');
$nora = jeab_solkampanj_fetch($pdo, $noraId);
sk(!empty($adminSend['ok']) && $sendMails($graphCalls) === $beforeGraph + 1 && is_array($nora) && ($nora['status'] ?? '') === 'pending', 'test_send går till testadressen och lämnar kön');
$adminMail = null;
foreach ($graphCalls as $call) {
    if (($call['method'] ?? '') === 'POST' && str_contains((string) ($call['url'] ?? ''), 'sendMail')) {
        $adminMail = $call;
    }
}
$adminTo = (string) ($adminMail['body']['message']['toRecipients'][0]['emailAddress']['address'] ?? '');
$adminPdf = false;
foreach ((array) ($adminMail['body']['message']['attachments'] ?? []) as $att) {
    if (is_array($att) && ($att['name'] ?? '') === JEAB_SOLKAMPANJ_BROCHURE_NAME) {
        $decoded = base64_decode((string) ($att['contentBytes'] ?? ''), true);
        $adminPdf = $decoded === $brochureDisk;
    }
}
sk($adminTo === JEAB_SOLKAMPANJ_TEST_TO && $adminPdf === true && jeab_solkampanj_test_mode() === false, 'test_send bifogar broschyren även när testläge är av');

$col = jeab_solkampanj_values_sql($pdo);
$pdo->prepare('UPDATE solkampanj_recipients SET status = \'sent\', sent_at = ?, sent_by = ?, ' . $col . ' = ? WHERE id = ?')->execute([
    '2026-10-26T09:47:00+02:00',
    'Estelle',
    '{"test_mode":1}',
    $noraId,
]);
$boId = (int) $pdo->query("SELECT id FROM solkampanj_recipients WHERE mejl = 'bo@kund.se'")->fetchColumn();
$pdo->prepare('UPDATE solkampanj_recipients SET status = \'sent\', sent_at = ?, sent_by = ?, ' . $col . ' = ? WHERE id = ?')->execute([
    '2026-10-26T09:47:00+02:00',
    'Sam Exempel',
    '{"test_mode":0}',
    $boId,
]);
$listed = jeab_solkampanj_test_consumed([]);
$listedIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), (array) ($listed['rows'] ?? []));
sk(!empty($listed['ok']) && (int) ($listed['dry'] ?? 0) === 1 && $listedIds === [$noraId] && (($listed['rows'][0]['sent_by'] ?? '') === 'Estelle'), 'test_consumed listar bara testläge');
$dryReset = jeab_solkampanj_reset_pending(['ids' => [$noraId, $boId]]);
$nora = jeab_solkampanj_fetch($pdo, $noraId);
$boRow = jeab_solkampanj_fetch($pdo, $boId);
sk(!empty($dryReset['ok']) && (int) ($dryReset['dry'] ?? 0) === 1 && ($dryReset['ids'] ?? []) === [$noraId] && ($dryReset['kept'] ?? []) === [$boId] && ($nora['status'] ?? '') === 'sent' && ($boRow['status'] ?? '') === 'sent', 'torr undo skriver inget och hoppar över riktiga utskick');
$noConfirm = jeab_solkampanj_reset_pending(['ids' => [$noraId], 'dry' => 0, 'confirm' => 'ja']);
sk(empty($noConfirm['ok']) && ($noConfirm['error'] ?? '') === 'confirm saknas' && (jeab_solkampanj_fetch($pdo, $noraId)['status'] ?? '') === 'sent', 'undo kräver confirm=SAM');
$undone = jeab_solkampanj_reset_pending(['ids' => [(string) $noraId, (string) $boId], 'dry' => 0, 'confirm' => 'SAM', 'undo' => 1]);
$nora = jeab_solkampanj_fetch($pdo, $noraId);
$boRow = jeab_solkampanj_fetch($pdo, $boId);
sk(!empty($undone['ok']) && (int) ($undone['dry'] ?? 1) === 0 && ($undone['ids'] ?? []) === [$noraId] && ($undone['kept'] ?? []) === [$boId] && ($nora['status'] ?? '') === 'pending' && trim((string) ($nora['sent_by'] ?? '')) === '' && ($boRow['status'] ?? '') === 'sent', 'confirm lägger tillbaka bara testraden');
unset($_SESSION['jeab_email']);

$batteryCopy = jeab_solkampanj_battery_copy();
$beaAdded = jeab_solkampanj_import_csv("kundnummer;namn;gata;postnr;ort;mejl\n91;Bea Berg;Batterigatan 4;177 41;Järfälla;bea@kund.se\n92;Cilla Berg;Cillagatan 5;177 42;Järfälla;cilla@kund.se\n");
sk(($beaAdded['imported'] ?? 0) === 2, 'två mottagare för batterioffert');
$bea = jeab_solkampanj_fetch($pdo, (int) $pdo->query("SELECT id FROM solkampanj_recipients WHERE mejl = 'bea@kund.se'")->fetchColumn());
sk(is_array($bea), 'Bea finns');
$batteryBuilt = jeab_solkampanj_battery_text($bea);
$batteryHtml = jeab_solkampanj_html($batteryBuilt['text'], $batteryBuilt['address'], false);
sk($batteryBuilt['subject'] === 'Batteri till Batterigatan 4?', 'batteriämnet är gatan');
sk(str_contains($batteryBuilt['text'], 'Hej Bea,'), 'batterihälsningen är förnamnet');
sk(str_contains($batteryBuilt['text'], $batteryCopy['intro']), 'batterifrågan');
sk(str_contains($batteryBuilt['text'], '7,5 kWh') && str_contains($batteryBuilt['text'], '39 500 kr') && str_contains($batteryBuilt['text'], '37,5 kWh') && str_contains($batteryBuilt['text'], '94 500 kr'), 'batteripriserna');
sk(str_contains($batteryBuilt['text'], 'brandcell') && str_contains($batteryBuilt['text'], 'SolarEdge'), 'brandcell och SolarEdge');
sk(!str_contains($batteryHtml, 'cid:solkampanj-bild') && !str_contains($batteryHtml, '<img src="cid:'), 'batterimejlet har ingen bild');
sk(str_contains($batteryHtml, 'Sam Exempel') && str_contains($batteryHtml, 'Elektriker') && str_contains(trim(strip_tags($batteryHtml)), jeab_solkampanj_copy()['inspections']), 'samma signatur');
sk(str_ends_with(trim(strip_tags($batteryHtml)), 'Batterigatan 4, 177 41 Järfälla'), 'adressen sist');
$batteryFile = jeab_solkampanj_battery_attachment();
$batteryDisk = is_array($batteryFile) ? (string) $batteryFile['content'] : '';
$batteryPath = jeab_solkampanj_battery_path();
$batterySrc = (string) file_get_contents($batteryPath);
sk(str_ends_with(str_replace('\\', '/', $batteryPath), '/src/solkampanj/battery.php'), 'batteribroschyren ligger i battery.php');
sk(str_starts_with($batterySrc, '<?php') && str_contains($batterySrc, 'http_response_code(403)') && !str_contains($batterySrc, '%PDF-'), 'batterifilen svarar 403 och innehåller inte råa PDF-bytes');
sk(strlen($batteryDisk) === 1096308 && str_starts_with($batteryDisk, '%PDF-') && is_array($batteryFile) && ($batteryFile['filename'] ?? '') === 'Demo Energi Järfälla - Batteri.pdf' && !isset($batteryFile['content_id']), 'include ger batteri-PDF:en');
sk(jeab_http_path_is_sensitive('/src/solkampanj/battery.php') === true, 'batterifilens adress är spärrad');
sk(!is_file(dirname($batteryPath) . '/jarfalla-energi-batteri.pdf'), 'ingen publik batteri-pdf');
sk(str_contains($page, 'id="send-battery"') && str_contains($page, 'Skicka batterioffert') && str_contains($page, "post('solkampanj.send_battery')"), 'knappen ligger bredvid Skicka');
sk(str_contains($root, "=== 'solkampanj.send_battery'") && str_contains($root, 'jeab_solkampanj_send_battery'), 'sidan tar emot batteriutskicket');
sk(preg_match("/action === 'solkampanj.send' && !ready\\(\\)/", $page) === 1 && !str_contains($page, "action === 'solkampanj.send_battery' && !ready()"), 'batteriknappen kräver inga fält');
sk(str_contains($hook, "solkampanj.test_send_battery") && str_contains($src, 'function jeab_solkampanj_test_send_battery'), 'hooken solkampanj.test_send_battery finns');

$batteryNames = static function (array $mail) use ($brochureOf): array {
    $names = [];
    foreach ((array) ($mail['attachments'] ?? []) as $att) {
        if (is_array($att)) {
            $names[] = (string) ($att['filename'] ?? '');
        }
    }

    return $names;
};
jeab_solkampanj_set_test_mode(true);
$calls = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$calls): array {
    $calls[] = $opts;

    return ['ok' => true, 'path' => 'graph', 'error' => null, 'id' => 'm-bat', 'from' => (string) ($opts['person_from'] ?? '')];
};
$queueBefore = (int) (jeab_solkampanj_next()['id'] ?? 0);
$batteryTest = jeab_solkampanj_send_battery(['id' => (int) $bea['id']], 'Estelle');
$beaNow = jeab_solkampanj_fetch($pdo, (int) $bea['id']);
$batteryMail = $calls[0] ?? [];
sk(!empty($batteryTest['ok']) && count($calls) === 1, 'batteriutskick i testläge skickar en gång');
sk(($batteryMail['to'] ?? '') === JEAB_SOLKAMPANJ_TEST_TO && ($batteryMail['person_from'] ?? '') === 'demo@example.test' && ($batteryMail['from_email'] ?? '') === 'demo@example.test', 'testläget går till Sam från hans brevlåda');
sk(($batteryMail['subject'] ?? '') === 'Batteri till Batterigatan 4?' && str_contains((string) ($batteryMail['text'] ?? ''), '39 500 kr') && str_ends_with(trim((string) ($batteryMail['text'] ?? '')), 'Batterigatan 4, 177 41 Järfälla'), 'brödtext, pris och adressrad');
sk(!str_contains((string) ($batteryMail['html'] ?? ''), 'cid:solkampanj-bild'), 'HTML utan solbild');
sk($batteryNames($batteryMail) === ['Demo Energi Järfälla - Batteri.pdf'] && $brochureOf((array) ($batteryMail['attachments'] ?? [])) === null && (($batteryMail['attachments'][0]['content'] ?? '') === $batteryDisk), 'bara batteribroschyren, inte solbroschyren');
sk(is_array($beaNow) && ($beaNow['status'] ?? '') === 'pending' && trim((string) ($beaNow['sent_by'] ?? '')) === '' && (int) ($batteryTest['next']['id'] ?? 0) === $queueBefore, 'testläge förbrukar inte och flyttar inte kön');
$batteryAgain = jeab_solkampanj_send_battery(['id' => (int) $bea['id']], 'Estelle');
sk(!empty($batteryAgain['ok']) && empty($batteryAgain['already']) && count($calls) === 2 && (jeab_solkampanj_fetch($pdo, (int) $bea['id'])['status'] ?? '') === 'pending', 'testläge kan skickas igen utan att räknas som skickat');

jeab_solkampanj_set_test_mode(false);
$annaSentId = (int) $pdo->query("SELECT id FROM solkampanj_recipients WHERE mejl = 'anna@kund.se'")->fetchColumn();
$blockedBattery = jeab_solkampanj_send_battery(['id' => $annaSentId], 'Estelle');
sk(!empty($blockedBattery['ok']) && !empty($blockedBattery['already']) && count($calls) === 2, 'redan skickad får inget batterimejl');
$calls = [];
$head = jeab_solkampanj_next();
$headId = (int) ($head['id'] ?? 0);
$headMail = (string) ($head['mejl'] ?? '');
$liveBattery = jeab_solkampanj_send_battery(['id' => $headId], 'Estelle');
$headSent = jeab_solkampanj_fetch($pdo, $headId);
$headValues = jeab_solkampanj_values_of(is_array($headSent) ? $headSent : []);
sk(!empty($liveBattery['ok']) && empty($liveBattery['already']) && count($calls) === 1 && ($calls[0]['to'] ?? '') === $headMail && $headMail !== '', 'riktigt batteriutskick går till kunden');
sk(is_array($headSent) && ($headSent['status'] ?? '') === 'sent' && ($headSent['sent_by'] ?? '') === 'Estelle' && ($headValues['type'] ?? '') === 'batteri' && !isset($headValues['antal']), 'raden markeras skickad med type=batteri och avsändare');
sk((int) ($liveBattery['next']['id'] ?? 0) !== $headId && (int) ($liveBattery['next']['id'] ?? 0) > 0, 'kön går vidare till nästa');
$resent = jeab_solkampanj_send_battery(['id' => $headId], 'admin@flowstack.example.test');
sk(!empty($resent['ok']) && !empty($resent['already']) && count($calls) === 1, 'samma mottagare skickas inte igen');
$log = $pdo->query("SELECT kind, actor, outcome FROM inbox_sent WHERE kind = 'batteri' AND actor = 'Estelle' AND outcome = 'sent'")->fetch(PDO::FETCH_ASSOC);
sk(is_array($log) && ($log['kind'] ?? '') === 'batteri' && ($log['actor'] ?? '') === 'Estelle', 'loggen har type=batteri och vem som skickade');
$adminLive = jeab_solkampanj_send_battery(['id' => (int) $bea['id']], 'admin@flowstack.example.test');
$beaSent = jeab_solkampanj_fetch($pdo, (int) $bea['id']);
$beaValues = jeab_solkampanj_values_of(is_array($beaSent) ? $beaSent : []);
sk(!empty($adminLive['ok']) && ($calls[1]['to'] ?? '') === 'bea@kund.se' && is_array($beaSent) && ($beaSent['sent_by'] ?? '') === 'admin@flowstack.example.test' && ($beaValues['type'] ?? '') === 'batteri', 'admin markeras som avsändare');
$adminLog = $pdo->query("SELECT kind, actor FROM inbox_sent WHERE kind = 'batteri' AND actor = 'admin@flowstack.example.test' AND outcome = 'sent'")->fetch(PDO::FETCH_ASSOC);
sk(is_array($adminLog), 'loggen har admin som avsändare');

$cillaId = (int) $pdo->query("SELECT id FROM solkampanj_recipients WHERE mejl = 'cilla@kund.se'")->fetchColumn();
$calls = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$calls): array {
    $calls[] = $opts;

    return ['ok' => false, 'path' => '', 'error' => 'Graph avvisade', 'id' => ''];
};
$batteryFail = jeab_solkampanj_send_battery(['id' => $cillaId], 'admin@flowstack.example.test');
$cilla = jeab_solkampanj_fetch($pdo, $cillaId);
sk(empty($batteryFail['ok']) && count($calls) === 1 && is_array($cilla) && ($cilla['status'] ?? '') === 'pending', 'misslyckat batteriutskick lämnar mottagaren väntande');
jeab_send_guard_ensure($pdo);
$pdo->prepare('UPDATE send_guard_state SET killed = 1 WHERE id = 1')->execute();
$calls = [];
$batteryKilled = jeab_solkampanj_send_battery(['id' => $cillaId], 'admin@flowstack.example.test');
sk(empty($batteryKilled['ok']) && count($calls) === 0 && (jeab_solkampanj_fetch($pdo, $cillaId)['status'] ?? '') === 'pending', 'spärren stoppar batteriutskicket');
$pdo->prepare('UPDATE send_guard_state SET killed = 0 WHERE id = 1')->execute();

unset($GLOBALS['JEAB_SYSTEM_MAIL']);
$beforeBatteryHook = $sendMails($graphCalls);
$deniedBattery = jeab_solkampanj_test_send_battery(['id' => $cillaId], 'Estelle');
sk(empty($deniedBattery['ok']) && (int) ($deniedBattery['status'] ?? 0) === 403 && $sendMails($graphCalls) === $beforeBatteryHook, 'test_send_battery utan admin skickar inget');
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';
$hookBattery = jeab_solkampanj_test_send_battery(['id' => $cillaId], 'Sam Exempel');
$cilla = jeab_solkampanj_fetch($pdo, $cillaId);
sk(!empty($hookBattery['ok']) && $sendMails($graphCalls) === $beforeBatteryHook + 1 && is_array($cilla) && ($cilla['status'] ?? '') === 'pending' && jeab_solkampanj_test_mode() === false, 'test_send_battery lämnar kön även när testläge är av');
$hookMail = null;
foreach ($graphCalls as $call) {
    if (($call['method'] ?? '') === 'POST' && str_contains((string) ($call['url'] ?? ''), 'sendMail')) {
        $hookMail = $call;
    }
}
$hookTo = (string) ($hookMail['body']['message']['toRecipients'][0]['emailAddress']['address'] ?? '');
$hookPdf = null;
$hookSawSolar = false;
foreach ((array) ($hookMail['body']['message']['attachments'] ?? []) as $att) {
    if (!is_array($att)) {
        continue;
    }
    if (($att['name'] ?? '') === JEAB_SOLKAMPANJ_BROCHURE_NAME) {
        $hookSawSolar = true;
    }
    if (($att['name'] ?? '') === JEAB_SOLKAMPANJ_BATTERY_NAME) {
        $hookPdf = $att;
    }
}
$hookBytes = is_array($hookPdf) ? base64_decode((string) ($hookPdf['contentBytes'] ?? ''), true) : false;
sk($hookTo === JEAB_SOLKAMPANJ_TEST_TO && $hookSawSolar === false && is_array($hookPdf) && ($hookPdf['contentType'] ?? '') === 'application/pdf' && empty($hookPdf['isInline']) && $hookBytes === $batteryDisk, 'test_send_battery bifogar bara batteri-PDF:en till testadressen');
unset($_SESSION['jeab_email']);

echo "solkampanj: ok\n";
