<?php

declare(strict_types=1);

/**
 * Faktura skapad från order ska ha samma sökande som ordern.
 * Order 1877 (Krokusvägen-fallet): två sökande à 32 961 på ordern, fakturan
 * bara huvudsökanden. Synken sänker först och skapar sedan medsökanden.
 */

$tmp = sys_get_temp_dir() . '/jeab-invoice-coowner-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function ic_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

const IC_T = 'STORAGESELFPRODUCEDELECTRICITY';

function ic_tax(string $id, string $type, string $ref, string $name, string $pnr, int $amount): array
{
    return [
        'Id' => $id, 'ReferenceDocumentType' => $type, 'ReferenceNumber' => $ref,
        'CustomerName' => $name, 'SocialSecurityNumber' => $pnr, 'PropertyDesignation' => 'TEST 1:1',
        'TaxReductionAmounts' => [['WorkType' => IC_T, 'AskedAmount' => $amount]],
    ];
}

function ic_run(array &$state): void
{
    $GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body = null) use (&$state): array {
        $state['calls'][] = $method . ' ' . $path;
        if ($method === 'GET' && $path === '/orders/1877') {
            return ['Order' => ['DocumentNumber' => '1877', 'InvoiceReference' => '13595']];
        }
        if ($method === 'GET' && $path === '/invoices/13595') {
            return ['Invoice' => ['DocumentNumber' => '13595', 'Booked' => $state['booked'], 'Cancelled' => false]];
        }
        if ($method === 'GET' && str_starts_with($path, '/taxreductions?filter=')) {
            $want = str_contains($path, 'orders') ? 'ORDER' : 'INVOICE';
            return ['TaxReductions' => array_values(array_filter($state['taxes'], static fn ($t) => $t['ReferenceDocumentType'] === $want)), 'MetaInformation' => ['@TotalPages' => 1]];
        }
        if ($method === 'GET' && preg_match('#^/taxreductions/(\w+)$#', $path, $m)) {
            foreach ($state['taxes'] as $t) {
                if ($t['Id'] === $m[1]) {
                    return ['TaxReduction' => $t];
                }
            }
            return [];
        }
        if ($method === 'PUT' && preg_match('#^/taxreductions/(\w+)$#', $path, $m)) {
            foreach ($state['taxes'] as &$t) {
                if ($t['Id'] === $m[1]) {
                    $t['TaxReductionAmounts'] = $body['TaxReduction']['TaxReductionAmounts'];
                }
            }
            unset($t);
            $state['check']();
            return [];
        }
        if ($method === 'POST' && $path === '/taxreductions') {
            $new = $body['TaxReduction'];
            $new['Id'] = 'N' . count($state['taxes']);
            $state['taxes'][] = $new;
            $state['check']();
            return ['TaxReduction' => $new];
        }
        return [];
    };
}

function ic_invoice_sum(array $state): int
{
    $sum = 0;
    foreach ($state['taxes'] as $t) {
        if ($t['ReferenceDocumentType'] === 'INVOICE') {
            foreach ($t['TaxReductionAmounts'] as $a) {
                $sum += (int) $a['AskedAmount'];
            }
        }
    }
    return $sum;
}

function ic_state(bool $booked): array
{
    $state = [
        'booked' => $booked,
        'calls' => [],
        'taxes' => [
            ic_tax('18901', 'ORDER', '1877', 'Siv Medsökande', '19550826-1111', 32961),
            ic_tax('18902', 'ORDER', '1877', 'Ove Huvudsökande', '19550907-2222', 32961),
            ic_tax('19000', 'INVOICE', '13595', 'Ove Huvudsökande', '195509072222', 50000),
        ],
    ];
    return $state;
}

// 1) Torrkörning: plan, inga skrivningar.
$state = ic_state(false);
$state['check'] = static function (): void {};
ic_run($state);
$api = new FortnoxAPI('Jarfalla');
$plan = $api->syncInvoiceTaxReductionsFromOrder('1877', '13595', false);
ic_assert(!$plan['in_sync'] && !$plan['applied'] && !$plan['locked'], 'torrkörning: ur synk, inget skrivet');
ic_assert(count($plan['create']) === 1 && $plan['create'][0]['name'] === 'Siv Medsökande', 'torrkörning: medsökande ska skapas');
ic_assert(count($plan['update']) === 1 && $plan['update'][0]['amounts'][0]['AskedAmount'] === 32961, 'torrkörning: huvudsökande sänks till 32 961');
ic_assert(!str_contains(json_encode($plan), '2222') && !str_contains(json_encode($plan), '1111'), 'planen visar inga hela personnummer');
ic_assert(array_filter($state['calls'], static fn ($c) => !str_starts_with($c, 'GET')) === [], 'torrkörning gör bara GET');

// 2) Skarpt: sänk först, sedan skapa. Summan överskrider aldrig 65 922.
$state = ic_state(false);
$state['check'] = static function () use (&$state): void {
    if (ic_invoice_sum($state) > 65922) {
        throw new Exception('överskrider möjligt belopp');
    }
};
ic_run($state);
$plan = (new FortnoxAPI('Jarfalla'))->syncInvoiceTaxReductionsFromOrder('1877', '13595', true);
ic_assert($plan['applied'] === true, 'skarpt: tillämpad');
$writes = array_values(array_filter($state['calls'], static fn ($c) => !str_starts_with($c, 'GET')));
ic_assert($writes === ['PUT /taxreductions/19000', 'POST /taxreductions'], 'skarpt: PUT före POST, fick ' . implode(', ', $writes));
$inv = array_values(array_filter($state['taxes'], static fn ($t) => $t['ReferenceDocumentType'] === 'INVOICE'));
ic_assert(count($inv) === 2 && ic_invoice_sum($state) === 65922, 'skarpt: två sökande på fakturan, 65 922 totalt');
$posted = $inv[1];
ic_assert($posted['ReferenceNumber'] === '13595' && $posted['SocialSecurityNumber'] === '19550826-1111' && $posted['PropertyDesignation'] === 'TEST 1:1', 'skarpt: medsökande på fakturan med pnr och fastighet');
ic_assert(count($plan['after']['tax_reductions']) === 2, 'skarpt: kontrolläsning efteråt visar två sökande');

// 3) Andra körningen: redan i synk, inga skrivningar.
$state['calls'] = [];
$again = (new FortnoxAPI('Jarfalla'))->syncInvoiceTaxReductionsFromOrder('1877', '13595', true);
ic_assert($again['in_sync'] && !$again['applied'], 'idempotent: i synk');
ic_assert(array_filter($state['calls'], static fn ($c) => !str_starts_with($c, 'GET')) === [], 'idempotent: inga skrivningar');

// 4) Bokförd faktura: aldrig skrivning, bara plan + låst.
$state = ic_state(true);
$state['check'] = static function (): void {};
ic_run($state);
$plan = (new FortnoxAPI('Jarfalla'))->syncInvoiceTaxReductionsFromOrder('1877', '13595', true);
ic_assert($plan['locked'] && !$plan['applied'] && count($plan['create']) === 1, 'bokförd: låst, plan men inget skrivet');
ic_assert(array_filter($state['calls'], static fn ($c) => !str_starts_with($c, 'GET')) === [], 'bokförd: bara GET');

unset($GLOBALS['_JEAB_FORTNOX_REQUEST']);
echo "Alla tester OK\n";
