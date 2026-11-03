<?php

declare(strict_types=1);

/**
 * Köerna Mina, Otilldelade, Alla öppna, Parkerade och Stängda.
 * Mina är tilldelat den inloggade personen, inte Egna-regeln med otilldelad produkt.
 * Inget skickas.
 */

$tmp = sys_get_temp_dir() . '/jeab-ixq-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
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
$_SESSION['jeab_email'] = 'saljare3@jarfalla.example.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';

function iq(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

iq(jeab_egna_me_id() === 'Roberto', 'Mina använder Roberto');
iq(jeab_inkorg_queue() === '', 'utan ko är kön tom');
$_GET['ko'] = 'mina';
iq(jeab_inkorg_queue() === 'mina', 'ko=mina läses');
$_GET['produkt'] = 'elbilsladdare';
iq(jeab_inkorg_produkt_filter() === 'laddbox', 'elbilsladdare lagras som laddbox');
$_GET['produkt'] = '';

$open = ['status' => 'open', 'assignee' => 'Roberto', 'company' => 'jarfalla', 'labels' => ['offer' => 'batteri']];
$other = ['status' => 'open', 'assignee' => 'Elin', 'company' => 'jarfalla', 'labels' => ['offer' => 'sol']];
$free = ['status' => 'open', 'assignee' => '', 'company' => 'jarfalla', 'labels' => ['offer' => 'batteri']];
$park = ['status' => 'waiting_supplier', 'assignee' => 'Roberto', 'company' => 'jarfalla'];
$shut = ['status' => 'closed', 'assignee' => '', 'company' => 'jarfalla'];
iq(jeab_inkorg_queue_ok($open, 'mina') && !jeab_inkorg_queue_ok($free, 'mina'), 'Mina är bara tilldelat mig');
iq(jeab_inkorg_queue_ok($free, 'otilldelade') && !jeab_inkorg_queue_ok($open, 'otilldelade'), 'Otilldelade saknar person');
iq(jeab_inkorg_queue_ok($open, 'oppna') && jeab_inkorg_queue_ok($free, 'oppna') && !jeab_inkorg_queue_ok($park, 'oppna'), 'Alla öppna är öppna');
iq(jeab_inkorg_queue_ok($park, 'parkerade') && !jeab_inkorg_queue_ok($open, 'parkerade'), 'Parkerade är väntar på leverantör');
iq(jeab_inkorg_queue_ok($shut, 'stangda') && !jeab_inkorg_queue_ok($park, 'stangda'), 'Stängda är stängda');
iq(!jeab_inkorg_offer_ok($open, 'sol') && jeab_inkorg_offer_ok($open, 'batteri'), 'produktfiltret följer sparad offert');

function iq_mail(string $id, string $subject, string $offer): array
{
    return jeab_inkorg_ingest([
        'message_id' => '<' . $id . '@example.test>',
        'from' => $id . '@kund.example',
        'from_name' => 'Kund ' . $id,
        'to' => 'info@jarfalla.example.test',
        'subject' => $subject,
        'body_text' => 'Hej, det här är ett fejkmejl om ' . $subject,
        'source' => 'test-ixq',
        'test' => true,
        'received_at' => '2026-10-20T10:00:00+00:00',
        'preset_labels' => [
            'type' => 'Ny lead',
            'action' => 'svara',
            'priority' => 3,
            'confidence' => 100,
            'source' => 'test',
            'reason' => '',
            'needs_review' => false,
            'add_tags' => [],
            'offer' => $offer,
        ],
    ]);
}

$mine = iq_mail('mina', 'Batteri till Roberto', 'batteri');
$elin = iq_mail('elin', 'Sol till Elin', 'sol');
$none = iq_mail('none', 'Laddbox utan person', 'laddbox');
iq(!empty($mine['ok']) && !empty($elin['ok']) && !empty($none['ok']), 'tre ärenden tas in');
jeab_inkorg_assign((string) $mine['thread_id'], 'Roberto');
jeab_inkorg_assign((string) $elin['thread_id'], 'Elin');
jeab_inkorg_thread_write((string) $elin['thread_id'], static function (array $t): array {
    $t['status'] = 'waiting_supplier';

    return $t;
});

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-alla', 'ko' => 'mina', 'scope' => 'allas'];
$minaList = jeab_inkorg_fast_prepare(true);
iq((int) ($minaList['total'] ?? 0) === 1 && str_contains((string) $minaList['html'], 'Batteri till Roberto') && !str_contains((string) $minaList['html'], 'Laddbox utan person'), 'listan Mina är bara Roberto');
iq((int) (($minaList['queues']['otilldelade'] ?? 0)) >= 1 && (int) (($minaList['queues']['mina'] ?? 0)) >= 1, 'kötalen räknas');

$_GET['ko'] = 'otilldelade';
$freeList = jeab_inkorg_fast_prepare(true);
iq((int) ($freeList['total'] ?? 0) === 1 && str_contains((string) $freeList['html'], 'Laddbox utan person'), 'Otilldelade visar den tomma raden');

$_GET['ko'] = 'parkerade';
$parkList = jeab_inkorg_fast_prepare(true);
iq((int) ($parkList['total'] ?? 0) === 1 && str_contains((string) $parkList['html'], 'Sol till Elin'), 'Parkerade visar Elin');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-alla', 'ko' => 'oppna', 'produkt' => 'batteri'];
$prodList = jeab_inkorg_fast_prepare(true);
iq((int) ($prodList['total'] ?? 0) === 1 && str_contains((string) $prodList['html'], 'Batteri till Roberto') && str_contains((string) $prodList['html'], 'class="ix-l2"') && str_contains((string) $prodList['html'], '>Batteri<'), 'produktfiltret lämnar batteri på rad två');

$_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-alla'];
$html = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
foreach (['data-ix-queue="mina"', '>Mina ', 'data-ix-queue="otilldelade"', '>Otilldelade ', '>Alla öppna ', 'data-ix-queue="parkerade"', '>Parkerade ', 'data-ix-queue="stangda"', '>Stängda ', 'aria-label="Typ"', 'aria-label="Produkt"', 'data-ix-scope="egna"', 'data-ix-scope="allas"', 'class="smart-grid crm-stats ix-folders"', 'Sök namn, adress, rubrik eller text'] as $needle) {
    iq(str_contains($html, $needle), 'sidan har ' . $needle);
}
iq(str_contains($html, 'class="ix-qtab') && !str_contains($html, '>Allas</a>'), 'köflikarna ersätter inte ordet Alla');
