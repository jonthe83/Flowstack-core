<?php

declare(strict_types=1);

/**
 * Sidolagring när inkorg_storage=sql. Syntetiska rader, ingen kunddata.
 * auto-send-cap och malmo-offer-sends importeras vid första läsningen.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-inkorg-side-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'side-store-secret',
    'hashes' => [
        ['hash' => 'side-hash-fixture', 'email' => 'sam@example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function side(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$dbSrc = (string) file_get_contents(dirname(__DIR__) . '/src/db.php');
$storeSrc = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_side_store.php');
side(str_contains($storeSrc, 'CREATE TABLE IF NOT EXISTS inkorg_side_doc'), 'sidotabellen skapas i side_store');
side(!str_contains($dbSrc, 'inkorg_side_doc') && !str_contains($dbSrc, 'inkorg_side_correction'), 'db.php har inte sidotabellerna');
side(jeab_inkorg_storage_mode() === 'json', 'flaggan är av före testet');

$now = time();
$addr = 'cap.mottagare@example.test';
$addrKey = jeab_auto_send_cap_key($addr);
$sends = [];
for ($i = 0; $i < 9; $i++) {
    $sends[] = ['at' => $now - 60, 'rcpt' => hash('sha256', 'annan' . $i . '@example.test')];
}
$sends[] = ['at' => $now - 120, 'rcpt' => $addrKey];
$capPath = jeab_inkorg_dir() . '/auto-send-cap.json';
$capJson = json_encode(['sends' => $sends], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($capPath, $capJson);

$malmoEmail = 'malmo.mottagare@example.test';
$malmoOffer = 'batteri';
$malmoKey = jeab_offer_malmo_sent_key($malmoEmail, $malmoOffer);
$malmoRow = [
    'at' => date('c', $now - 3600),
    'date' => date('Y-m-d H:i', $now - 3600),
    'product' => $malmoOffer,
    'masked' => 'm***@example.test',
    'key' => $malmoKey,
];
$malmoPath = jeab_inkorg_dir() . '/malmo-offer-sends.json';
$malmoJson = json_encode(['sends' => [$malmoRow]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($malmoPath, $malmoJson);

$quotePath = jeab_inkorg_dir() . '/quote-preview.json';
$quoteJson = json_encode(['thread' => 'th-side', 'text' => 'Förhandsvisning', 'at' => date('c', $now - 10)], JSON_UNESCAPED_UNICODE);
file_put_contents($quotePath, $quoteJson);

$hsPath = jeab_inkorg_dir() . '/hs-uncertain-open.json';
$hsJson = json_encode(['facts' => ['conv-1' => ['mail' => 100, 'closed' => 0, 'status' => 'open']]], JSON_UNESCAPED_UNICODE);
file_put_contents($hsPath, $hsJson);

$rebookPath = jeab_inkorg_dir() . '/rebook-log.json';
$rebookJson = json_encode(['done' => ['msg-old' => ['action' => 'skip', 'why' => 'redan', 'at' => date('c', $now - 50)]]], JSON_UNESCAPED_UNICODE);
file_put_contents($rebookPath, $rebookJson);

$corrPath = jeab_inkorg_dir() . '/corrections.jsonl';
file_put_contents($corrPath, "{\"id\":\"old\"}\n");

file_put_contents($tmp . '/inkorg/messages.json', json_encode(['messages' => []], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/inkorg/threads.json', json_encode(['threads' => new stdClass()], JSON_UNESCAPED_UNICODE));

side(jeab_inkorg_sql_set_storage('sql') && jeab_inkorg_storage_mode() === 'sql', 'sidolagret körs i sql-läge');
$pdo = jeab_inkorg_store_pdo();
side($pdo instanceof PDO, 'PDO är öppen');

side(str_contains(jeab_auto_send_cap_why($addr), '30 dagar'), 'importerat tak stoppar samma adress');
side(str_contains(jeab_auto_send_cap_why('ny.mottagare@example.test'), 'per timme'), 'importerat timtak är inte noll');
side((string) file_get_contents($capPath) === $capJson, 'cap-filen skrivs inte om vid läsning');

$capRow = $pdo->prepare('SELECT payload, imported FROM inkorg_side_doc WHERE name = ?');
$capRow->execute(['auto-send-cap']);
$capDb = $capRow->fetch();
$capDoc = is_array($capDb) ? json_decode((string) ($capDb['payload'] ?? ''), true) : null;
side(is_array($capDoc) && count((array) ($capDoc['sends'] ?? [])) === 10 && (int) ($capDb['imported'] ?? 0) === 1, 'första läsningen lade tio utskick i SQL');

jeab_auto_send_cap_record('extra.mottagare@example.test');
$capRow->execute(['auto-send-cap']);
$capDb = $capRow->fetch();
$capDoc = is_array($capDb) ? json_decode((string) ($capDb['payload'] ?? ''), true) : null;
side(is_array($capDoc) && count((array) ($capDoc['sends'] ?? [])) === 11, 'nytt utskick läggs ovanpå importen');
side((string) file_get_contents($capPath) === $capJson, 'nytt utskick skrivs inte till cap-filen');
side(str_contains(jeab_auto_send_cap_why($addr), '30 dagar'), 'adressen är fortfarande spärrad efter påfyllnad');

$prior = jeab_offer_malmo_sent_find($malmoEmail, $malmoOffer);
side(is_array($prior) && (string) ($prior['key'] ?? '') === $malmoKey && (string) ($prior['date'] ?? '') !== '', 'importerad Malmö-rad stoppar samma offert');
side((string) file_get_contents($malmoPath) === $malmoJson, 'Malmö-filen skrivs inte om vid läsning');
jeab_offer_malmo_sent_record('annan.mottagare@example.test', 'elbilsladdare', '');
side((string) file_get_contents($malmoPath) === $malmoJson, 'ny Malmö-rad skrivs inte till filen');
side(is_array(jeab_offer_malmo_sent_find($malmoEmail, $malmoOffer)), 'första Malmö-offerten kan inte skickas igen');
$malmoStmt = $pdo->prepare('SELECT payload FROM inkorg_side_doc WHERE name = ?');
$malmoStmt->execute(['malmo-offer-sends']);
$malmoDoc = json_decode((string) $malmoStmt->fetchColumn(), true);
side(is_array($malmoDoc) && count((array) ($malmoDoc['sends'] ?? [])) === 2, 'båda Malmö-raderna ligger i SQL');

$pdo->prepare('DELETE FROM inkorg_side_doc WHERE name = ?')->execute(['auto-send-cap']);
file_put_contents($capPath, '{');
$blocked = jeab_auto_send_cap_why('ny.mottagare@example.test');
side(str_contains($blocked, 'kunde inte läsas'), 'oläsbar cap-fil släpper inte igenom ett utskick');
$left = $pdo->prepare('SELECT imported FROM inkorg_side_doc WHERE name = ?');
$left->execute(['auto-send-cap']);
$imported = $left->fetchColumn();
side($imported === false || (int) $imported !== 1, 'oläsbar fil markeras inte som importerad');

file_put_contents($capPath, $capJson);
$pdo->prepare('DELETE FROM inkorg_side_doc WHERE name = ?')->execute(['auto-send-cap']);
side(str_contains(jeab_auto_send_cap_why($addr), '30 dagar'), 'en misslyckad import kan göras om');

side(jeab_inkorg_quote_preview_text('th-side') === 'Förhandsvisning', 'quote-preview läses från importen');
side((string) file_get_contents($quotePath) === $quoteJson, 'quote-preview-filen rörs inte');
jeab_inkorg_quote_preview_save('th-side', '<p>Ny text</p>');
side(jeab_inkorg_quote_preview_text('th-side') === 'Ny text', 'ny förhandsvisning läses från SQL');
side((string) file_get_contents($quotePath) === $quoteJson, 'ny förhandsvisning skrivs inte till filen');

jeab_inkorg_hs_open_cap_store($hsPath, ['conv-2' => ['mail' => 200, 'closed' => 0, 'status' => 'open']]);
$hsStmt = $pdo->prepare('SELECT payload FROM inkorg_side_doc WHERE name = ?');
$hsStmt->execute(['hs-uncertain-open']);
$hsDoc = json_decode((string) $hsStmt->fetchColumn(), true);
side(is_array($hsDoc) && isset($hsDoc['facts']['conv-1'], $hsDoc['facts']['conv-2']), 'osäker-cache importeras och fylls på');
side((string) file_get_contents($hsPath) === $hsJson, 'osäker-cache-filen rörs inte');

side(jeab_rebook_seen('msg-old'), 'ombokningslogg importeras');
jeab_rebook_log('msg-new', ['action' => 'skip', 'why' => 'test']);
side(jeab_rebook_seen('msg-old') && jeab_rebook_seen('msg-new'), 'ny ombokningsrad tar inte bort den importerade');
side((string) file_get_contents($rebookPath) === $rebookJson, 'ombokningsfilen rörs inte');

$beforeCorr = (string) file_get_contents($corrPath);
jeab_inkorg_side_correction_add('{"id":"new"}');
$corrCount = (int) $pdo->query('SELECT COUNT(*) FROM inkorg_side_correction')->fetchColumn();
side($corrCount === 2 && (string) file_get_contents($corrPath) === $beforeCorr, 'korrigeringar importeras och filen skrivs inte');

$ing = jeab_inkorg_ingest([
    'message_id' => '<side-cutover@example.test>',
    'from' => 'kund.side@example.test',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Hej',
    'body_text' => 'Ett kort hej utan produkt.',
    'source' => 'graph-jarfalla',
    'received_at' => '2020-01-02T10:00:00+01:00',
    'test' => true,
]);
side(!empty($ing['ok']), 'gammalt Graph-mejl finns i SQL');
$cut = jeab_graph_cutover_cleanup('2024-01-01T00:00:00+01:00', false);
$copies = glob(jeab_inkorg_dir() . '/*.cutover-*') ?: [];
$jsonCopies = glob(jeab_inkorg_dir() . '/*.json.cutover-*') ?: [];
side($copies === [] && $jsonCopies === [], 'sql-läge skapar ingen cutover-kopia');
side(($cut['remove_count']['jarfalla'] ?? 0) >= 1 && ($cut['backup'] ?? '') === 'sql', 'borttaget Graph-ärende pekar på sql');

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg.php');
side(str_contains($page, 'class="ix-storage"') && str_contains($page, 'jeab_auth_ok()') && str_contains($page, 'jeab_inkorg_storage_mode()'), 'läget finns i inkorgssidan för inloggad personal');
side(str_contains($page, '<h1 class="crm-title">Inkorg</h1>'), 'rubriken är oförändrad');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla'];
$_SESSION['jeab_inkorg_box'] = 'jarfalla';
unset($_COOKIE['jeab_auth']);
$loggedOut = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
side(!str_contains($loggedOut, 'ix-storage'), 'läget syns inte utan inloggning');
$ticket = jeab_auth_issue_value('sam@example.test', 'side-hash-fixture');
$_COOKIE['jeab_auth'] = (string) $ticket;
$loggedIn = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
side(str_contains($loggedIn, 'class="ix-storage">sql</span>'), 'inloggad personal ser sql');

echo "ok\n";
