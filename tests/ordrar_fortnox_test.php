<?php

declare(strict_types=1);

/**
 * H-ORD-1, H-ORD-2, H-ORD-3, H-ORD-6, H-ORD-7, H-ORD-8.
 * Misslyckad Vunnen lyfter inte tombstonen. Fel bolag skapar ingen faktura.
 * Manuell order skriver inte ett annat kontors Fortnox. Avbruten export
 * lägger tillbaka länken. Nytt Fortnox-nummer sparas före senare jobb.
 * Feltext från skapa_om maskerar personnummer.
 */

$tmp = sys_get_temp_dir() . '/jeab-ordrar-fortnox-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
$token = 'ordrar-fortnox-token';
$hash = password_hash('ordrar-fortnox-kod', PASSWORD_DEFAULT);
file_put_contents($tmp . '/pw.json', json_encode([
    'secret' => 'ordrar-fortnox-secret',
    'hashes' => [
        ['hash' => $hash, 'email' => 'okand@flowstack.example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $tmp . '/pw.json');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => $token,
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
if (!mkdir($tmp . '/rot', 0775, true) && !is_dir($tmp . '/rot')) {
    fwrite(STDERR, "FAIL rot\n");
    exit(1);
}
if (!mkdir($tmp . '/fortnox', 0775, true) && !is_dir($tmp . '/fortnox')) {
    fwrite(STDERR, "FAIL fortnox\n");
    exit(1);
}
file_put_contents($tmp . '/rot/fortnox_config.php', "<?php\nreturn ['clientId' => 'test', 'clientSecret' => 'test', 'articles' => []];\n");
file_put_contents($tmp . '/fortnox/tokens.json', json_encode([
    'Jarfalla' => ['refresh_token' => 'test-refresh'],
], JSON_UNESCAPED_UNICODE));

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/ordrar.php';
require_once dirname(__DIR__) . '/src/ordrar_fortnox.php';
require_once dirname(__DIR__) . '/src/fortnox_order_link.php';
require_once dirname(__DIR__) . '/src/fortnox_pnr.php';
require_once dirname(__DIR__) . '/src/manuell_order.php';
require_once dirname(__DIR__) . '/src/layout.php';

function of_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
jeab_ordrar_external_live(false);
$store = new JeabJsonStore($tmp . '/store.json');
$GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

$gap = jeab_create_reminder($store, 'Hej', '2026-10-02 00:00:00', 'ingen produkt', null, [
    'customer' => 'QA Tom',
    'company' => 'jarfalla',
    'email' => 'tom@kund.se',
    'tags' => ['vunnen'],
]);
$gapId = (int) ($gap['id'] ?? 0);
of_assert($gapId > 0, 'luck-påminnelsen finns');
jeab_ordrar_deleted_mark($gapId, $store);
of_assert(jeab_ordrar_deleted_blocks($gapId, jeab_get_reminder($store, $gapId)), 'tombstonen finns före misslyckad vunnen');
jeab_update_reminder($store, $gapId, ['tags' => ['vunnen']]);
$gapRow = jeab_get_reminder($store, $gapId);
of_assert(is_array($gapRow), 'luck-påminnelsen kan taggas igen');
$gapThrew = false;
try {
    jeab_ordrar_vunnen_from_tag($store, $gapRow);
} catch (InvalidArgumentException $e) {
    $gapThrew = str_starts_with($e->getMessage(), 'Ordern skapades inte');
}
$gapAfter = jeab_get_reminder($store, $gapId);
of_assert($gapThrew && jeab_purchase_get($gapId) === null, 'ofullständig vunnen skapar ingen order');
of_assert(is_array($gapAfter) && jeab_ordrar_deleted_blocks($gapId, $gapAfter), 'misslyckad vunnen lämnar tombstonen');
of_assert(is_array($gapAfter) && !in_array('vunnen', jeab_parse_tags($gapAfter['tags'] ?? []), true), 'vägran tar bort vunnen-taggen');

$won = jeab_create_reminder($store, 'Testgatan 1', '2026-10-02 00:00:00', 'batteri', null, [
    'customer' => 'Kim Vunnen',
    'company' => 'jarfalla',
    'email' => 'kim@kund.se',
    'address' => 'Testgatan 1',
    'tags' => ['vunnen'],
]);
$wonId = (int) ($won['id'] ?? 0);
jeab_ordrar_deleted_mark($wonId, $store);
jeab_update_reminder($store, $wonId, ['tags' => ['vunnen']]);
$wonRow = jeab_get_reminder($store, $wonId);
of_assert(is_array($wonRow), 'komplett påminnelse finns');
$opened = jeab_ordrar_vunnen_purchase($store, $wonId, $wonRow);
of_assert((int) ($opened['reminder_id'] ?? 0) === $wonId && is_array(jeab_purchase_get($wonId)), 'komplett vunnen skapar ordern');
of_assert(!jeab_ordrar_deleted_blocks($wonId, jeab_get_reminder($store, $wonId)), 'komplett vunnen lyfter den egna tombstonen');

$owner = jeab_create_reminder($store, 'Raderadgatan 1', '2026-10-02 00:00:00', 'HS#777901 raderad', null, [
    'customer' => 'Raderad Kund',
    'company' => 'jarfalla',
    'address' => 'Raderadgatan 1',
    'tags' => ['vunnen'],
]);
$ownerId = (int) ($owner['id'] ?? 0);
jeab_ordrar_deleted_mark($ownerId, $store);
jeab_toggle_reminder($store, $ownerId, true);
$shared = jeab_create_reminder($store, 'Deladgatan 3', '2026-10-02 00:00:00', 'HS#777901 annan', null, [
    'customer' => 'Delad Kund',
    'company' => 'jarfalla',
    'address' => 'Deladgatan 3',
    'tags' => ['vunnen'],
]);
$sharedId = (int) ($shared['id'] ?? 0);
jeab_update_reminder($store, $sharedId, ['tags' => ['vunnen']]);
$sharedRow = jeab_get_reminder($store, $sharedId);
$sharedThrew = false;
try {
    jeab_ordrar_vunnen_purchase($store, $sharedId, is_array($sharedRow) ? $sharedRow : []);
} catch (InvalidArgumentException $e) {
    $sharedThrew = $e->getMessage() === 'Ärendet är raderat.';
}
of_assert($sharedThrew && jeab_purchase_get($sharedId) === null, 'annans Help Scout-nummer skapar ingen order');
of_assert(jeab_ordrar_deleted_blocks($ownerId, jeab_get_reminder($store, $ownerId)), 'den andra tombstonen ligger kvar');

$pnr = '19800101-0000';
jeab_purchase_put([
    'reminder_id' => 910301,
    'company' => 'malmo',
    'personnummer' => $pnr,
    'huvudsokande' => 'Malmo Kund',
    'updated_at' => '2026-01-01 00:00:00',
]);
$pnrCalls = 0;
$GLOBALS['_JEAB_FORTNOX_PNR_FACTORY'] = static function () use (&$pnrCalls): FortnoxAPI {
    $pnrCalls++;
    throw new RuntimeException('Fortnox ska inte anropas');
};
$wrongOffice = jeab_fortnox_faktura_skapa_om([
    'account' => 'Jarfalla',
    'invoice_number' => '501',
    'order_id' => 910301,
    'dry' => 0,
    'confirm' => 'SAM',
]);
of_assert(($wrongOffice['ok'] ?? true) === false && ($wrongOffice['status'] ?? 0) === 422 && $pnrCalls === 0, 'skapa_om för annat bolag skriver inte');
of_assert(($wrongOffice['error'] ?? '') === 'Ordern hör till ett annat bolag.', 'skapa_om säger att bolaget inte stämmer');

$masked = jeab_fortnox_pnr_scrub(['error' => 'Personnummer ' . $pnr . ' saknas']);
$maskedJson = json_encode($masked, JSON_UNESCAPED_UNICODE);
of_assert(is_string($maskedJson) && !str_contains($maskedJson, $pnr) && !str_contains($maskedJson, '198001010000'), 'error-fältet maskerar personnummer');

jeab_purchase_put([
    'reminder_id' => 910302,
    'company' => 'jarfalla',
    'personnummer' => $pnr,
    'huvudsokande' => 'Jarfalla Kund',
    'updated_at' => '2026-01-01 00:00:00',
]);
$GLOBALS['_JEAB_FORTNOX_PNR_FACTORY'] = static function () use ($pnr): FortnoxAPI {
    return new class($pnr) extends FortnoxAPI {
        public function __construct(private string $secret)
        {
        }

        public function getInvoice(string $invoiceNumber): array
        {
            unset($invoiceNumber);
            throw new RuntimeException('Personnummer ' . $this->secret . ' saknas');
        }
    };
};
$leaked = jeab_fortnox_faktura_skapa_om([
    'account' => 'Jarfalla',
    'invoice_number' => '501',
    'order_id' => 910302,
    'dry' => 0,
    'confirm' => 'SAM',
]);
$leakedJson = jeab_fortnox_pnr_json($leaked);
of_assert(($leaked['ok'] ?? true) === false && ($leaked['status'] ?? 0) === 422, 'skapa_om lämnar Fortnox-feltexten');
of_assert(!str_contains((string) ($leaked['error'] ?? ''), $pnr) && !str_contains($leakedJson, $pnr) && !str_contains($leakedJson, '198001010000'), 'skapa_om-svaret har inget personnummer');
unset($GLOBALS['_JEAB_FORTNOX_PNR_FACTORY']);

$exportReminder = jeab_create_reminder($store, 'Exportgatan 2', '2026-10-03 00:00:00', 'export', null, [
    'customer' => 'Ada Export',
    'company' => 'jarfalla',
]);
$exportId = (int) ($exportReminder['id'] ?? 0);
jeab_purchase_put([
    'reminder_id' => $exportId,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Ada Export',
    'huvudsokande' => 'Ada Export',
    'name' => 'Ada Export',
    'personnummer' => '199001011234',
    'email' => 'ada@kund.se',
    'phone' => '0701234567',
    'address' => 'Testgatan 1',
    'postnr' => '11122',
    'postnummer' => '11122',
    'ort' => 'Teststad',
    'fastighetsbeteckning' => 'TEST 1:1',
    'fortnox_order' => '4242',
    'fortnox_invoice' => '13605',
    'fortnox_customer' => 'K9',
    'pris' => 80000,
    'kundpris' => 80000,
    'steps' => [],
]);
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body): array {
    unset($body);
    $route = strtok($path, '?') ?: $path;
    if ($method === 'GET' && $route === '/invoices/13605') {
        return ['Invoice' => [
            'DocumentNumber' => '13605',
            'Cancelled' => true,
            'CreditInvoiceReference' => '0',
            'Booked' => true,
        ]];
    }
    if ($method === 'GET' && $route === '/orders/4242') {
        return ['Order' => [
            'DocumentNumber' => '4242',
            'CustomerNumber' => 'K9',
            'Cancelled' => false,
            'WarehouseReady' => false,
            'InvoiceReference' => '',
            'OrderRows' => [],
        ]];
    }
    throw new RuntimeException('avbruten skrivning');
};
$failedExport = jeab_fortnox_order_export([
    'account' => 'JEAB',
    'order_id' => $exportId,
    'dry' => 0,
    'force_new' => 1,
    'confirm' => 'SAM',
]);
$restored = jeab_purchase_get($exportId);
of_assert(($failedExport['ok'] ?? true) === false, 'avbruten export blir fel');
of_assert(
    is_array($restored)
        && (string) ($restored['fortnox_order'] ?? '') === '4242'
        && (string) ($restored['fortnox_invoice'] ?? '') === '13605',
    'avbruten export lägger tillbaka Fortnox-numren'
);
$clearedText = '';
foreach (is_array($restored['activity'] ?? null) ? $restored['activity'] : [] as $entry) {
    if (is_array($entry)) {
        $clearedText .= ' ' . (string) ($entry['text'] ?? '');
    }
}
of_assert(str_contains($clearedText, '4242') && str_contains($clearedText, '13605'), 'rensningen ligger kvar i aktivitetsloggen');

$createReminder = jeab_create_reminder($store, 'Skapagatan 4', '2026-10-04 00:00:00', 'skapa', null, [
    'customer' => 'Ada Skapa',
    'company' => 'jarfalla',
    'email' => 'ada@example.org',
]);
$createId = (int) ($createReminder['id'] ?? 0);
jeab_purchase_put([
    'reminder_id' => $createId,
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Ada Skapa',
    'huvudsokande' => 'Ada Skapa',
    'name' => 'Ada Skapa',
    'personnummer' => '199001011234',
    'email' => 'ada@example.org',
    'phone' => '0701234567',
    'address' => 'Testgatan 1',
    'postnr' => '11122',
    'postnummer' => '11122',
    'ort' => 'Teststad',
    'fastighetsbeteckning' => 'TEST 1:1',
    'pris' => 80000,
    'kundpris' => 80000,
    'steps' => [],
]);
$posts = 0;
$GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND'] = static fn (): array => [];
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, mixed $body) use (&$posts): array {
    unset($path, $body);
    if ($method !== 'GET') {
        $posts++;
    }
    return [];
};
$GLOBALS['_JEAB_ORDRAR_FORTNOX_PUSH_LOG'] = [];
$GLOBALS['_JEAB_ORDRAR_FORTNOX_AFTER_NUMBER'] = static function (array $row): void {
    unset($row);
    throw new RuntimeException('senare jobb avbröts');
};
$createThrew = false;
try {
    jeab_ordrar_create_fortnox(['id' => $createId]);
} catch (RuntimeException $e) {
    $createThrew = $e->getMessage() === 'senare jobb avbröts';
}
$early = jeab_purchase_get($createId);
of_assert($createThrew, 'senare jobb kan kasta efter Fortnox-svar');
of_assert(
    is_array($early) && (string) ($early['fortnox_order'] ?? '') === 'TEST' && (string) ($early['fortnox_customer'] ?? '') === 'TEST',
    'Fortnox-numret sparas innan senare jobb'
);
of_assert($GLOBALS['_JEAB_ORDRAR_FORTNOX_PUSH_LOG'] === ['create'] && $posts === 0, 'första klicket skapar och anropar inte Fortnox för ett testlead');
unset($GLOBALS['_JEAB_ORDRAR_FORTNOX_AFTER_NUMBER']);
$GLOBALS['_JEAB_FORTNOX_GT_SYNC'] = static fn (): array => ['update' => false];
$GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static fn (): array => ['Order' => ['DocumentNumber' => 'TEST', 'OrderRows' => []]];
$GLOBALS['_JEAB_ORDRAR_FORTNOX_PUSH_LOG'] = [];
$posts = 0;
$second = jeab_ordrar_create_fortnox(['id' => $createId]);
of_assert((string) ($second['fortnox_order'] ?? '') === 'TEST', 'andra klicket behåller samma nummer');
of_assert($GLOBALS['_JEAB_ORDRAR_FORTNOX_PUSH_LOG'] === ['update'] && $posts === 0, 'andra klicket uppdaterar och skapar ingen ny order');

$_SESSION = [];
jeab_auth_data(true);
$cookie = jeab_auth_issue_value('okand@flowstack.example.test', $hash);
of_assert(is_string($cookie) && $cookie !== '', 'okänd användare får en kaka');
$_COOKIE['jeab_auth'] = (string) $cookie;
of_assert(jeab_company_allowed('malmo') === false && jeab_company_allowed('jarfalla') === false, 'okänd inloggning har inget bolag');
$fxCalls = 0;
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function () use (&$fxCalls): array {
    $fxCalls++;
    throw new RuntimeException('Fortnox ska inte anropas');
};
$_GET = ['p' => 'manuell-order', 'co' => 'malmo'];
$_POST = [
    '_csrf' => jeab_csrf_token(),
    'intent' => 'fortnox',
    'co' => 'malmo',
    'fastighetsbeteckning' => 'TEST 1:1',
    'address' => 'Testgatan 1',
    'email' => 'ada@example.org',
    'phone' => '0701234567',
    'owner_name' => ['Ada Person'],
    'owner_pnr' => ['199001011234'],
    'owner_role' => ['huvudsokande'],
];
$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
jeab_manuell_order_page();
$manualHtml = (string) ob_get_clean();
of_assert(str_contains($manualHtml, 'Inte behörig.'), 'manuell order avvisar annat bolag');
of_assert($fxCalls === 0 && !str_contains($manualHtml, 'Importerad till Fortnox'), 'manuell order skriver inte Fortnox');

echo "ordrar_fortnox_test ok\n";
