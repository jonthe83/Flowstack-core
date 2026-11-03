<?php

declare(strict_types=1);

/** Syntetiska nummer. Hook-svar och logg får inte innehålla dem. */

$tmp = sys_get_temp_dir() . '/jeab-pnr-hook-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
mkdir($tmp . '/public', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
putenv('JEAB_DISABLE_SQLITE=1');
$token = 'pnr-hook-token';
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => $token,
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox_pnr.php';

function pnrh(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$full = '19800101-0000';
$short = '800101-0000';
$mask = '800101-****';
$other = '19700101-1111';
$badEn = "19800101\u{2013}0000";
$badSpace = '19800101 0000';
$secrets = ['198001010000', '8001010000', '800101-0000', '197001011111', $badEn];

$auth = static function (bool $on) use ($token): void {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    if ($on) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        return;
    }
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_WEBHOOK_TOKEN']);
};

$bag = new stdClass();
$install = static function () use (&$bag, $badEn, $badSpace, $full, $other, $short): void {
    $bag = new stdClass();
    $bag->methods = [];
    $bag->customerPuts = [];
    $bag->invoicePuts = [];
    $bag->drafts = [];
    $world = [
        'Jarfalla' => [
            'customers' => [
                'K1' => ['CustomerNumber' => 'K1', 'Name' => 'Test Kund', 'Address1' => 'Testgatan 1', 'Address2' => '', 'ZipCode' => '11122', 'City' => 'Teststad', 'OrganisationNumber' => $badEn],
                'K2' => ['CustomerNumber' => 'K2', 'Name' => 'Ren Kund', 'Address1' => 'Renagatan 2', 'Address2' => '', 'ZipCode' => '11133', 'City' => 'Teststad', 'OrganisationNumber' => $full],
                'K3' => ['CustomerNumber' => 'K3', 'Name' => 'Annan Kund', 'Address1' => 'Annan 3', 'Address2' => '', 'ZipCode' => '11144', 'City' => 'Teststad', 'OrganisationNumber' => $other],
            ],
            'invoices' => [
                '501' => ['DocumentNumber' => '501', 'CustomerNumber' => 'K1', 'Booked' => true, 'Cancelled' => false, 'Sent' => true, 'ExternalInvoiceReference1' => '', 'InvoiceRows' => [['ArticleNumber' => 'A1', 'Description' => 'Rad', 'DeliveredQuantity' => 1, 'Price' => 100]]],
                '502' => ['DocumentNumber' => '502', 'CustomerNumber' => 'K1', 'Booked' => false, 'Cancelled' => false, 'Sent' => false, 'ExternalInvoiceReference1' => '', 'OrganisationNumber' => $badEn, 'InvoiceRows' => [['ArticleNumber' => 'A1', 'DeliveredQuantity' => 1, 'Price' => 50]]],
                '503' => ['DocumentNumber' => '503', 'CustomerNumber' => 'K1', 'Booked' => false, 'Cancelled' => true, 'Sent' => false, 'ExternalInvoiceReference1' => '', 'InvoiceRows' => []],
                '504' => ['DocumentNumber' => '504', 'CustomerNumber' => 'K2', 'Booked' => true, 'Cancelled' => false, 'Sent' => false, 'ExternalInvoiceReference1' => '', 'InvoiceRows' => []],
                '505' => ['DocumentNumber' => '505', 'CustomerNumber' => 'K3', 'Booked' => false, 'Cancelled' => false, 'Sent' => false, 'ExternalInvoiceReference1' => '', 'InvoiceRows' => []],
            ],
        ],
        'Malmo' => [
            'customers' => [
                'K4' => ['CustomerNumber' => 'K4', 'Name' => 'Malmo Kund', 'Address1' => 'Malmogatan 4', 'Address2' => '', 'ZipCode' => '22211', 'City' => 'Malmo', 'OrganisationNumber' => $badSpace],
            ],
            'invoices' => [],
        ],
    ];
    $state = new stdClass();
    $state->world = $world;
    $GLOBALS['_JEAB_FORTNOX_PNR_FACTORY'] = static function (string $account) use ($state, $bag, $short): FortnoxAPI {
        return new class($account, $state, $bag, $short) extends FortnoxAPI {
            public function __construct(private string $accountName, private stdClass $state, private stdClass $bag, private string $plain)
            {
            }

            private function &book(): array
            {
                if (!isset($this->state->world[$this->accountName]) || !is_array($this->state->world[$this->accountName])) {
                    $this->state->world[$this->accountName] = ['customers' => [], 'invoices' => []];
                }
                return $this->state->world[$this->accountName];
            }

            public function listAll(string $path, string $key, array $query = [], int $sleepUs = 250000): array
            {
                $this->bag->methods[] = 'listAll';
                if ($path !== '/customers' || $key !== 'Customers') {
                    throw new RuntimeException('fel endpoint');
                }
                $book = &$this->book();
                return array_values($book['customers']);
            }

            public function getCustomer(string $number): array
            {
                $this->bag->methods[] = 'getCustomer';
                $book = &$this->book();
                $row = $book['customers'][$number] ?? null;
                if (!is_array($row)) {
                    throw new RuntimeException('saknas');
                }
                return $row;
            }

            public function getInvoice(string $invoiceNumber): array
            {
                $this->bag->methods[] = 'getInvoice:' . $invoiceNumber;
                $book = &$this->book();
                $row = $book['invoices'][$invoiceNumber] ?? null;
                if (!is_array($row)) {
                    throw new RuntimeException('saknas');
                }
                return ['Invoice' => $row];
            }

            public function listInvoicesForCustomer(string $customerNumber): array
            {
                $this->bag->methods[] = 'listInvoices';
                $book = &$this->book();
                $out = [];
                foreach ($book['invoices'] as $row) {
                    if (is_array($row) && (string) ($row['CustomerNumber'] ?? '') === $customerNumber) {
                        $out[] = $row;
                    }
                }
                return $out;
            }

            public function updateCustomerOrganisationNumber(string $number, string $organisationNumber): void
            {
                $this->bag->methods[] = 'updateCustomer';
                $this->bag->customerPuts[] = [
                    'number' => $number,
                    'plain' => $organisationNumber === $this->plain,
                    'bad' => jeab_idnr_has_bad_separator($organisationNumber),
                ];
                $book = &$this->book();
                if (isset($book['customers'][$number]) && is_array($book['customers'][$number])) {
                    $book['customers'][$number]['OrganisationNumber'] = $organisationNumber;
                }
            }

            public function updateInvoice(string $invoiceNumber, array $invoice): array
            {
                $this->bag->methods[] = 'updateInvoice:' . $invoiceNumber;
                $org = (string) ($invoice['OrganisationNumber'] ?? '');
                $this->bag->invoicePuts[] = [
                    'number' => $invoiceNumber,
                    'plain' => $org === '' || $org === $this->plain,
                    'bad' => jeab_idnr_has_bad_separator($org),
                    'booked' => array_key_exists('Booked', $invoice),
                    'sent' => array_key_exists('Sent', $invoice) || array_key_exists('EmailInformation', $invoice),
                ];
                return ['Invoice' => ['DocumentNumber' => $invoiceNumber]];
            }

            public function createDraftInvoice(array $invoice): array
            {
                $this->bag->methods[] = 'createDraftInvoice';
                $encoded = json_encode($invoice);
                $this->bag->drafts[] = [
                    'booked' => array_key_exists('Booked', $invoice) && $invoice['Booked'] !== false,
                    'sent' => array_key_exists('Sent', $invoice) || array_key_exists('EmailInformation', $invoice) || array_key_exists('EDIInformation', $invoice) || array_key_exists('WayOfDelivery', $invoice),
                    'customer' => (string) ($invoice['CustomerNumber'] ?? ''),
                    'mark' => (string) ($invoice['ExternalInvoiceReference1'] ?? ''),
                    'secret' => is_string($encoded) && (str_contains($encoded, '19800101') || str_contains($encoded, '8001010000') || str_contains($encoded, $this->plain) || str_contains($encoded, "\u{2013}")),
                ];
                $book = &$this->book();
                $n = 900;
                while (isset($book['invoices'][(string) $n])) {
                    $n++;
                }
                $number = (string) $n;
                $book['invoices'][$number] = [
                    'DocumentNumber' => $number,
                    'CustomerNumber' => (string) ($invoice['CustomerNumber'] ?? ''),
                    'Booked' => false,
                    'Cancelled' => false,
                    'Sent' => false,
                    'ExternalInvoiceReference1' => (string) ($invoice['ExternalInvoiceReference1'] ?? ''),
                    'InvoiceRows' => is_array($invoice['InvoiceRows'] ?? null) ? $invoice['InvoiceRows'] : [],
                ];
                return ['Invoice' => ['DocumentNumber' => $number, 'Booked' => false, 'Sent' => false, 'OrganisationNumber' => "19800101\u{2013}0000"]];
            }

            public function cancelInvoice(string $invoiceNumber): array
            {
                $this->bag->methods[] = 'cancelInvoice:' . $invoiceNumber;
                $book = &$this->book();
                if (isset($book['invoices'][$invoiceNumber]) && is_array($book['invoices'][$invoiceNumber])) {
                    $book['invoices'][$invoiceNumber]['Cancelled'] = true;
                }
                return ['Invoice' => ['DocumentNumber' => $invoiceNumber, 'Cancelled' => true, 'OrganisationNumber' => "19800101\u{2013}0000"]];
            }

            public function creditInvoice(string $invoiceNumber): array
            {
                $this->bag->methods[] = 'creditInvoice:' . $invoiceNumber;
                $book = &$this->book();
                if (isset($book['invoices'][$invoiceNumber]) && is_array($book['invoices'][$invoiceNumber])) {
                    $book['invoices'][$invoiceNumber]['CreditInvoiceReference'] = 'C' . $invoiceNumber;
                }
                return ['Invoice' => ['DocumentNumber' => '901', 'Booked' => false, 'OrganisationNumber' => "19800101\u{2013}0000"]];
            }
        };
    };
};

$clean = static function (array $result) use ($secrets): bool {
    $json = json_encode($result, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return false;
    }
    foreach ($secrets as $secret) {
        if (str_contains($json, $secret)) {
            return false;
        }
    }
    return !str_contains($json, 'OrganisationNumber') && !str_contains($json, 'personnummer');
};

$noWrite = static function (array $needles) use (&$bag): bool {
    foreach ($bag->methods as $method) {
        foreach ($needles as $needle) {
            if (str_contains($method, $needle)) {
                return false;
            }
        }
    }
    return true;
};

jeab_purchase_put([
    'reminder_id' => 910001,
    'company' => 'jarfalla',
    'personnummer' => $badEn,
    'updated_at' => '2026-01-01 00:00:00',
]);
$stored = jeab_purchase_get(910001);
pnrh(is_array($stored) && ($stored['personnummer'] ?? '') === $full, 'sparad order får vanligt bindestreck');
jeab_purchase_put([
    'reminder_id' => 910002,
    'company' => 'jarfalla',
    'personnummer' => $other,
    'owners' => [['name' => 'Test Kund', 'personnummer' => $badEn]],
    'updated_at' => '2026-01-01 00:00:00',
]);
$ownerOrder = jeab_purchase_get(910002);
$ownerPnr = '';
foreach (is_array($ownerOrder['owners'] ?? null) ? $ownerOrder['owners'] : [] as $owner) {
    if (is_array($owner)) {
        $ownerPnr = (string) ($owner['personnummer'] ?? '');
        break;
    }
}
pnrh(is_array($ownerOrder) && $ownerPnr === $full && ($ownerOrder['personnummer'] ?? '') === $other, 'ägarens nummer ligger på ordern');

$auth(false);
$install();
foreach ([
    'check' => jeab_fortnox_pnr_check([]),
    'hamta' => jeab_fortnox_kund_hamta(['account' => 'Jarfalla', 'customer_number' => 'K1']),
    'jamfor' => jeab_fortnox_kund_jamfor_order(['account' => 'Jarfalla', 'invoice_number' => '501', 'order_id' => 910001]),
    'ratta' => jeab_fortnox_kund_ratta_orgnr(['account' => 'Jarfalla', 'customer_number' => 'K1', 'order_id' => 910001, 'dry' => 0]),
    'kopia' => jeab_fortnox_faktura_kopiera(['account' => 'Jarfalla', 'invoice_number' => '501', 'dry' => 0]),
    'makulera' => jeab_fortnox_faktura_makulera_eller_kreditera(['account' => 'Jarfalla', 'invoice_number' => '501', 'mode' => 'makulera', 'dry' => 0, 'confirm' => 'SAM']),
    'skapa' => jeab_fortnox_faktura_skapa_om(['account' => 'Jarfalla', 'invoice_number' => '501', 'order_id' => 910002, 'dry' => 0, 'confirm' => 'SAM']),
] as $name => $denied) {
    pnrh(($denied['ok'] ?? true) === false && ($denied['error'] ?? '') === 'Fel token.' && ($denied['status'] ?? 0) === 401, $name . ' utan token');
}
pnrh($bag->methods === [], 'anrop utan token läser inte Fortnox');

$auth(true);
$install();
$check = jeab_fortnox_pnr_check([]);
pnrh(($check['ok'] ?? false) === true && ($check['count'] ?? 0) === 2 && ($check['customers'] ?? []) === ['K1', 'K4'], 'pnr_check lämnar bara kundnummer');
pnrh($clean($check), 'pnr_check innehåller inte numret');

$auth(true);
$install();
$hamta = jeab_fortnox_kund_hamta(['account' => 'Jarfalla', 'invoice_number' => '501']);
pnrh(($hamta['ok'] ?? false) === true && ($hamta['customer']['customer_number'] ?? '') === 'K1' && ($hamta['customer']['name'] ?? '') === 'Test Kund', 'hamta lämnar namn och kundnummer');
pnrh(($hamta['customer']['address']['address1'] ?? '') === 'Testgatan 1' && ($hamta['customer']['address']['zip'] ?? '') === '11122', 'hamta lämnar adressen');
pnrh(($hamta['invoice']['booked'] ?? null) === true && ($hamta['invoice']['cancelled'] ?? null) === false && ($hamta['invoice']['sent'] ?? null) === true, 'hamta lämnar fakturastatus');
pnrh(($hamta['invalid_chars'] ?? null) === true && ($hamta['orgnr'] ?? '') === $mask, 'hamta maskerar och flaggar fel tecken');
pnrh($clean($hamta) && $noWrite(['update', 'create', 'cancel', 'credit', 'book', 'email', 'print', 'send']), 'hamta är läsning utan numret');

$auth(true);
$install();
$onlyCustomer = jeab_fortnox_kund_hamta(['account' => 'Jarfalla', 'customer_number' => 'K2']);
pnrh(array_key_exists('invoice', $onlyCustomer) && $onlyCustomer['invoice'] === null && ($onlyCustomer['invalid_chars'] ?? true) === false && ($onlyCustomer['orgnr'] ?? '') === $mask, 'hamta utan faktura är ren och maskerad');
pnrh($clean($onlyCustomer), 'hamta av ren kund innehåller inte numret');

$auth(true);
$install();
$badAccount = jeab_fortnox_kund_hamta(['account' => 'Stockholm', 'customer_number' => 'K1']);
pnrh(($badAccount['ok'] ?? true) === false && $bag->methods === [], 'fel bolag anropar inte Fortnox');

$auth(true);
$install();
$same = jeab_fortnox_kund_jamfor_order(['account' => 'Jarfalla', 'invoice_number' => '504', 'order_id' => 910001]);
$chars = jeab_fortnox_kund_jamfor_order(['account' => 'Jarfalla', 'invoice_number' => '501', 'order_id' => 910001]);
$diff = jeab_fortnox_kund_jamfor_order(['account' => 'Jarfalla', 'invoice_number' => '505', 'order_id' => 910001]);
pnrh(($same['result'] ?? '') === 'stammer' && ($chars['result'] ?? '') === 'bara_tecken' && ($diff['result'] ?? '') === 'skiljer_sig', 'jamfor skiljer lika, tecken och olika');
pnrh($clean($same) && $clean($chars) && $clean($diff), 'jamfor lämnar inte numret');
pnrh(!isset($same['orgnr']) && !isset($chars['orgnr']) && $noWrite(['update', 'create', 'cancel', 'credit']), 'jamfor är läsning');

$auth(true);
$install();
$dry = jeab_fortnox_kund_ratta_orgnr(['account' => 'Jarfalla', 'customer_number' => 'K1', 'order_id' => 910001]);
pnrh(($dry['ok'] ?? false) === true && ($dry['dry'] ?? null) === 1 && ($dry['orgnr'] ?? '') === $mask, 'ratta är dry som standard och maskerar');
pnrh(($dry['invoices_booked'] ?? []) === ['501'] && ($dry['invoices_open'] ?? []) === ['502'] && ($dry['invoices_cancelled'] ?? []) === ['503'], 'ratta rapporterar bokförda och öppna');
pnrh($bag->customerPuts === [] && $bag->invoicePuts === [] && $clean($dry), 'dry ratta skriver inte och läcker inte');

$auth(true);
$install();
$write = jeab_fortnox_kund_ratta_orgnr(['account' => 'Jarfalla', 'customer_number' => 'K1', 'order_id' => 910001, 'dry' => 0]);
pnrh(($write['ok'] ?? false) === true && ($write['dry'] ?? null) === 0 && ($write['customer_changed'] ?? false) === true, 'dry=0 rättar kunden');
pnrh(count($bag->customerPuts) === 1 && $bag->customerPuts[0]['number'] === 'K1' && $bag->customerPuts[0]['plain'] === true && $bag->customerPuts[0]['bad'] === false, 'kundkortet får YYMMDD-XXXX');
$updated = array_map(static fn (array $row): string => (string) $row['number'], $bag->invoicePuts);
pnrh($updated === ['502'] && !in_array('updateInvoice:501', $bag->methods, true) && !in_array('updateInvoice:503', $bag->methods, true), 'bokförd och makulerad faktura rörs inte');
pnrh($bag->invoicePuts[0]['plain'] === true && $bag->invoicePuts[0]['bad'] === false && $bag->invoicePuts[0]['booked'] === false && $bag->invoicePuts[0]['sent'] === false, 'öppen faktura uppdateras utan bokföring eller utskick');
pnrh($clean($write) && $noWrite(['createDraft', 'cancel', 'credit', 'bookkeep', 'email', 'print', 'send']), 'ratta-svaret innehåller inte numret');

$auth(true);
$install();
$copyDry = jeab_fortnox_faktura_kopiera(['account' => 'Jarfalla', 'invoice_number' => '501']);
pnrh(($copyDry['ok'] ?? false) === true && ($copyDry['dry'] ?? null) === 1 && !isset($copyDry['new_invoice']) && $bag->drafts === [], 'kopia är dry som standard');
pnrh($noWrite(['createDraft', 'update', 'cancel', 'credit', 'bookkeep']), 'dry kopia skriver inte');

$auth(true);
$install();
$copy = jeab_fortnox_faktura_kopiera(['account' => 'Jarfalla', 'invoice_number' => '501', 'dry' => 0]);
pnrh(($copy['ok'] ?? false) === true && ($copy['new_invoice'] ?? '') === '900' && ($copy['dry'] ?? null) === 0, 'kopia lämnar det nya numret');
pnrh(count($bag->drafts) === 1 && $bag->drafts[0]['booked'] === false && $bag->drafts[0]['sent'] === false && $bag->drafts[0]['secret'] === false && $bag->drafts[0]['customer'] === 'K1', 'utkastet bokförs inte och skickas inte');
pnrh($clean($copy) && $noWrite(['bookkeep', 'email', 'print', 'send', 'cancel', 'credit']), 'kopia bokför eller skickar inte');

$auth(true);
$install();
$cancelDry = jeab_fortnox_faktura_makulera_eller_kreditera(['account' => 'Jarfalla', 'invoice_number' => '501', 'mode' => 'makulera']);
pnrh(($cancelDry['dry'] ?? null) === 1 && $noWrite(['cancel', 'credit', 'bookkeep']), 'makulera är dry');
$noConfirm = jeab_fortnox_faktura_makulera_eller_kreditera(['account' => 'Jarfalla', 'invoice_number' => '501', 'mode' => 'kreditera', 'dry' => 0]);
pnrh(($noConfirm['ok'] ?? true) === false && ($noConfirm['status'] ?? 0) === 422 && $noWrite(['cancel', 'credit']), 'dry=0 utan bekräftelse skriver inte');
$cancel = jeab_fortnox_faktura_makulera_eller_kreditera(['account' => 'Jarfalla', 'invoice_number' => '502', 'mode' => 'makulera', 'dry' => 0, 'confirm' => 'SAM']);
pnrh(($cancel['ok'] ?? false) === true && in_array('cancelInvoice:502', $bag->methods, true) && $clean($cancel), 'makulera anropar bara cancel');
$credit = jeab_fortnox_faktura_makulera_eller_kreditera(['account' => 'Jarfalla', 'invoice_number' => '501', 'mode' => 'kreditera', 'dry' => '0', 'confirm' => 'SAM']);
pnrh(($credit['ok'] ?? false) === true && ($credit['credit_invoice'] ?? '') === '901' && in_array('creditInvoice:501', $bag->methods, true), 'kreditera lämnar kreditfakturan');
pnrh($clean($credit) && $noWrite(['bookkeep', 'email', 'print', 'send', 'createDraft']), 'kredit bokför eller skickar inte');

$auth(true);
$install();
$planBooked = jeab_fortnox_faktura_skapa_om(['account' => 'Jarfalla', 'invoice_number' => '501', 'order_id' => 910002]);
pnrh(($planBooked['ok'] ?? false) === true && ($planBooked['dry'] ?? null) === 1 && ($planBooked['orgnr_diff'] ?? '') === 'bara_tecken' && ($planBooked['mode'] ?? '') === 'kreditera', 'skapa om torrkör bokförd faktura');
pnrh(!isset($planBooked['new_invoice']) && ($planBooked['would_create'] ?? false) === true && ($planBooked['would_close'] ?? false) === true && $bag->drafts === [] && $bag->customerPuts === [], 'torrkörningen lämnar bara planen');
pnrh($clean($planBooked) && $noWrite(['createDraft', 'update', 'cancel', 'credit', 'bookkeep', 'email', 'print', 'send']), 'torrkörningen skriver inte och läcker inte');

$auth(true);
$install();
$planOpen = jeab_fortnox_faktura_skapa_om(['account' => 'Jarfalla', 'invoice_number' => '502', 'order_id' => 910002]);
$planSame = jeab_fortnox_faktura_skapa_om(['account' => 'Jarfalla', 'invoice_number' => '504', 'order_id' => 910002]);
$planDiff = jeab_fortnox_faktura_skapa_om(['account' => 'Jarfalla', 'invoice_number' => '505', 'order_id' => 910002]);
pnrh(($planOpen['mode'] ?? '') === 'makulera' && ($planSame['orgnr_diff'] ?? '') === 'stammer' && ($planDiff['orgnr_diff'] ?? '') === 'skiljer_sig', 'skapa om skiljer makulera, lika och olika');
pnrh($clean($planOpen) && $clean($planSame) && $clean($planDiff) && $bag->drafts === [], 'jämförelsen lämnar inte numret');

$auth(true);
$install();
$noConfirmRedo = jeab_fortnox_faktura_skapa_om(['account' => 'Jarfalla', 'invoice_number' => '501', 'order_id' => 910002, 'dry' => 0]);
pnrh(($noConfirmRedo['ok'] ?? true) === false && ($noConfirmRedo['status'] ?? 0) === 422 && $bag->methods === [], 'skapa om kräver bekräftelse');

$auth(true);
$install();
$redo = jeab_fortnox_faktura_skapa_om(['account' => 'Jarfalla', 'invoice_number' => '501', 'order_id' => 910002, 'dry' => 0, 'confirm' => 'SAM', 'personnummer' => $other]);
$again = jeab_fortnox_faktura_skapa_om(['account' => 'Jarfalla', 'invoice_number' => '501', 'order_id' => 910002, 'dry' => 0, 'confirm' => 'SAM']);
$credits = array_values(array_filter($bag->methods, static fn (string $method): bool => $method === 'creditInvoice:501'));
pnrh(($redo['ok'] ?? false) === true && ($redo['dry'] ?? null) === 0 && ($redo['new_invoice'] ?? '') === '900' && ($redo['orgnr_diff'] ?? '') === 'bara_tecken' && ($redo['mode'] ?? '') === 'kreditera', 'skapa om lämnar det nya numret');
pnrh(count($bag->drafts) === 1 && $bag->drafts[0]['booked'] === false && $bag->drafts[0]['sent'] === false && $bag->drafts[0]['secret'] === false && $bag->drafts[0]['customer'] === 'K1' && $bag->drafts[0]['mark'] === 'JEAB-SKAPA-OM-501', 'utkastet är omärkt för bokföring och utskick');
pnrh(count($bag->customerPuts) === 1 && $bag->customerPuts[0]['number'] === 'K1' && $bag->customerPuts[0]['plain'] === true && $bag->customerPuts[0]['bad'] === false, 'ägarens nummer skrivs som YYMMDD-XXXX');
pnrh($credits === ['creditInvoice:501'] && !in_array('cancelInvoice:501', $bag->methods, true), 'bokförd faktura krediteras en gång');
pnrh(($again['already'] ?? false) === true && ($again['new_invoice'] ?? '') === '900' && ($again['would_create'] ?? true) === false && count($bag->drafts) === 1 && $credits === ['creditInvoice:501'], 'andra körningen skapar inte en ny faktura');
pnrh($clean($redo) && $clean($again) && $noWrite(['bookkeep', 'email', 'print', 'send']), 'skapa om bokför eller skickar inte');

$auth(true);
$install();
$redoOpen = jeab_fortnox_faktura_skapa_om(['account' => 'Jarfalla', 'invoice_number' => '502', 'order_id' => 910002, 'dry' => '0', 'confirm' => 'SAM']);
$redoOpenAgain = jeab_fortnox_faktura_skapa_om(['account' => 'Jarfalla', 'invoice_number' => '502', 'order_id' => 910002, 'dry' => 0, 'confirm' => 'SAM']);
$cancels = array_values(array_filter($bag->methods, static fn (string $method): bool => $method === 'cancelInvoice:502'));
pnrh(($redoOpen['ok'] ?? false) === true && ($redoOpen['mode'] ?? '') === 'makulera' && ($redoOpen['new_invoice'] ?? '') === '900' && $cancels === ['cancelInvoice:502'], 'obokförd faktura makuleras');
pnrh(!in_array('creditInvoice:502', $bag->methods, true) && ($redoOpenAgain['already'] ?? false) === true && count($bag->drafts) === 1 && $cancels === ['cancelInvoice:502'], 'obokförd faktura skapas inte om');
pnrh($clean($redoOpen) && $clean($redoOpenAgain) && $noWrite(['bookkeep', 'email', 'print', 'send', 'credit']), 'makuleringen bokför eller skickar inte');

$calls = [];
unset($GLOBALS['_JEAB_FORTNOX_PNR_FACTORY']);
$GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body) use (&$calls): array {
    $calls[] = ['method' => $method, 'path' => $path, 'body' => $body];
    return ['Invoice' => ['DocumentNumber' => '901']];
};
$api = new FortnoxAPI('Jarfalla');
$api->createDraftInvoice([
    'CustomerNumber' => 'K1',
    'Booked' => true,
    'Sent' => true,
    'EmailInformation' => ['EmailAddressTo' => 'kund@example.com'],
    'InvoiceRows' => [['ArticleNumber' => 'A1']],
]);
$api->cancelInvoice('502');
$api->creditInvoice('501');
pnrh(count($calls) === 3, 'tre Fortnox-anrop');
pnrh(($calls[0]['method'] ?? '') === 'POST' && ($calls[0]['path'] ?? '') === '/invoices', 'utkastet är POST /invoices');
$draftBody = is_array($calls[0]['body']['Invoice'] ?? null) ? $calls[0]['body']['Invoice'] : [];
pnrh(!array_key_exists('Booked', $draftBody) && !array_key_exists('Sent', $draftBody) && !array_key_exists('EmailInformation', $draftBody), 'utkastet har ingen bokföring eller utskick');
pnrh(($calls[1]['path'] ?? '') === '/invoices/502/cancel' && ($calls[2]['path'] ?? '') === '/invoices/501/credit', 'makulera och kreditera använder sina egna vägar');
pnrh(array_filter($calls, static fn (array $call): bool => str_contains((string) $call['path'], 'bookkeep') || str_contains((string) $call['path'], 'email') || str_contains((string) $call['path'], 'print')) === [], 'ingen bokföring eller utskick i vägarna');

$runner = $tmp . '/hook_runner.php';
$statusFile = $tmp . '/hook_status.txt';
file_put_contents($runner, <<<'PHP'
<?php
declare(strict_types=1);
$tmp = getenv('JEAB_PNR_DATA');
$root = getenv('JEAB_PNR_ROOT');
$token = getenv('JEAB_PNR_TOKEN');
$body = getenv('JEAB_PNR_BODY');
$authOn = getenv('JEAB_PNR_AUTH');
$statusFile = getenv('JEAB_PNR_STATUS');
if (!is_string($tmp) || !is_string($root) || !is_string($token) || !is_string($body) || !is_string($statusFile)) {
    fwrite(STDERR, "missing env\n");
    exit(2);
}
$GLOBALS['JEAB_NO_SESSION'] = true;
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp . '/public';
putenv('JEAB_DISABLE_SQLITE=1');
require_once $root . '/src/fortnox.php';
$GLOBALS['_JEAB_FORTNOX_PNR_FACTORY'] = static function (string $account): FortnoxAPI {
    return new class($account) extends FortnoxAPI {
        public function __construct(private string $accountName)
        {
        }

        public function listAll(string $path, string $key, array $query = [], int $sleepUs = 250000): array
        {
            return $this->accountName === 'Jarfalla'
                ? [['CustomerNumber' => 'K1', 'OrganisationNumber' => "19800101\u{2013}0000"]]
                : [];
        }

        public function getCustomer(string $number): array
        {
            return ['CustomerNumber' => 'K1', 'Name' => 'Test Kund', 'Address1' => 'Testgatan 1', 'ZipCode' => '11122', 'City' => 'Teststad', 'OrganisationNumber' => "19800101\u{2013}0000"];
        }

        public function getInvoice(string $invoiceNumber): array
        {
            return ['Invoice' => ['DocumentNumber' => $invoiceNumber, 'CustomerNumber' => 'K1', 'Booked' => true, 'Cancelled' => false, 'Sent' => false, 'OrganisationNumber' => "19800101\u{2013}0000"]];
        }

        public function listInvoicesForCustomer(string $customerNumber): array
        {
            return [];
        }

        public function createDraftInvoice(array $invoice): array
        {
            file_put_contents($GLOBALS['_JEAB_DATA_DIR'] . '/draft-flag.txt', '1');
            return ['Invoice' => ['DocumentNumber' => '900', 'OrganisationNumber' => "19800101\u{2013}0000"]];
        }
    };
};
$_SERVER['REQUEST_METHOD'] = 'POST';
if ($authOn === '1') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
}
$GLOBALS['_JEAB_RAW_BODY'] = $body;
register_shutdown_function(static function () use ($statusFile): void {
    file_put_contents($statusFile, (string) http_response_code());
});
require $root . '/hook.php';
PHP);

$runHook = static function (string $body, bool $withToken) use ($runner, $tmp, $token, $statusFile, $secrets): array {
    @unlink($statusFile);
    @unlink($tmp . '/draft-flag.txt');
    $cmd = 'JEAB_DISABLE_SQLITE=1'
        . ' JEAB_PNR_DATA=' . escapeshellarg($tmp)
        . ' JEAB_PNR_ROOT=' . escapeshellarg(dirname(__DIR__))
        . ' JEAB_PNR_TOKEN=' . escapeshellarg($token)
        . ' JEAB_PNR_BODY=' . escapeshellarg($body)
        . ' JEAB_PNR_AUTH=' . ($withToken ? '1' : '0')
        . ' JEAB_PNR_STATUS=' . escapeshellarg($statusFile)
        . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    pnrh(is_resource($proc), 'hook-processen startade');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $text = is_string($stdout) ? $stdout : '';
    $err = is_string($stderr) ? $stderr : '';
    foreach ($secrets as $secret) {
        pnrh(!str_contains($text, $secret) && !str_contains($err, $secret), 'hook.php läcker inte numret');
    }
    return [
        'status' => is_file($statusFile) ? (int) file_get_contents($statusFile) : 0,
        'json' => json_decode($text, true),
        'body' => $text,
    ];
};

$httpDeny = $runHook(json_encode(['action' => 'fortnox.kund_hamta', 'account' => 'Jarfalla', 'invoice_number' => '501']), false);
pnrh($httpDeny['status'] === 401 && ($httpDeny['json']['error'] ?? '') === 'Fel token.', 'hook.php kräver token');

$httpHamta = $runHook(json_encode(['action' => 'fortnox.kund_hamta', 'account' => 'Jarfalla', 'invoice_number' => '501']), true);
pnrh($httpHamta['status'] === 200 && ($httpHamta['json']['orgnr'] ?? '') === $mask && ($httpHamta['json']['invalid_chars'] ?? false) === true, 'hook.php hamta maskerar');
pnrh(!str_contains($httpHamta['body'], 'OrganisationNumber'), 'hook.php hamta har inget organisationsnummerfält');

$httpCopy = $runHook(json_encode(['action' => 'fortnox.faktura_kopiera', 'account' => 'Jarfalla', 'invoice_number' => '501']), true);
pnrh($httpCopy['status'] === 200 && ($httpCopy['json']['dry'] ?? null) === 1 && !is_file($tmp . '/draft-flag.txt'), 'hook.php kopia är dry');

$httpRedo = $runHook(json_encode(['action' => 'fortnox.faktura_skapa_om', 'account' => 'Jarfalla', 'invoice_number' => '501', 'order_id' => 910001]), true);
pnrh($httpRedo['status'] === 200 && ($httpRedo['json']['dry'] ?? null) === 1 && ($httpRedo['json']['orgnr_diff'] ?? '') === 'bara_tecken' && ($httpRedo['json']['mode'] ?? '') === 'kreditera' && !isset($httpRedo['json']['new_invoice']) && !is_file($tmp . '/draft-flag.txt'), 'hook.php skapa om är dry');

$httpOld = $runHook(json_encode(['action' => 'fortnox.customer_fix_pnr', 'customer' => 'K1', 'dry' => 0]), true);
pnrh($httpOld['status'] === 400 && ($httpOld['json']['error'] ?? '') === 'Okänd åtgärd.', 'customer_fix_pnr är borttagen');

$httpLon = $runHook(json_encode(['action' => 'fortnox.lon_rapport', 'company' => 'jarfalla']), true);
pnrh($httpLon['status'] === 400 && ($httpLon['json']['error'] ?? '') === 'Okänd åtgärd.', 'lon_rapport är borttagen');

echo "Alla OK\n";
