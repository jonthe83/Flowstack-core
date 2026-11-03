<?php

declare(strict_types=1);

/**
 * Goodwill/Inbyte: validering, maskering, kreditfaktura mot Fortnox-mock.
 * Inga riktiga Fortnox-anrop.
 */

$tmp = sys_get_temp_dir() . '/jeab-payout-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_WEBHOOK_CAPTURE=1');
$_ENV['JEAB_WEBHOOK_CAPTURE'] = '1';

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function payout_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function payout_base(int $id, int $pris = 20000): array
{
    return [
        'reminder_id' => $id,
        'company' => 'jarfalla',
        'kind' => 'batteri',
        'huvudsokande' => 'Fiktiv Kund',
        'name' => 'Fiktiv Kund',
        'personnummer' => '198001011234',
        'fastighetsbeteckning' => 'FIKTIV 1:1',
        'pris' => $pris,
        'fortnox_order' => '9001',
        'fortnox_order_rows' => [[
            'RowId' => 1,
            'ArticleNumber' => 'BAT',
            'Description' => 'Batteri',
            'Price' => $pris,
            'HouseWork' => true,
            'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
            'DeliveredQuantity' => '1',
        ]],
    ];
}

/** @return array<string,mixed> */
function payout_world(string $debit): array
{
    return [
        'calls' => [],
        'leaked' => false,
        'fail_credit' => false,
        'fail_adjust' => false,
        'next' => 8000,
        'invoices' => [
            $debit => [
                'Invoice' => [
                    'DocumentNumber' => $debit,
                    'CustomerNumber' => '99',
                    'Booked' => false,
                    'Credit' => false,
                    'VATIncluded' => true,
                    'CreditInvoiceReference' => '',
                    'InvoiceRows' => [
                        [
                            'Description' => 'Framkörning',
                            'ArticleNumber' => 'FRAKT',
                            'AccountNumber' => 3990,
                            'Price' => 50000,
                            'VAT' => 25,
                            'DeliveredQuantity' => '1',
                        ],
                        [
                            'Description' => 'Batteri',
                            'ArticleNumber' => 'BAT',
                            'AccountNumber' => 3001,
                            'Price' => 20000,
                            'VAT' => 25,
                            'HouseWork' => true,
                            'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
                            'DeliveredQuantity' => '1',
                        ],
                    ],
                ],
            ],
        ],
        'tax' => [[
            'Id' => 'tr-debit',
            'ReferenceNumber' => $debit,
            'ReferenceDocumentType' => 'INVOICE',
            'TaxReductionAmounts' => [[
                'AskedAmount' => 5000,
                'WorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
            ]],
        ]],
    ];
}

function payout_booked(mixed $flag): bool
{
    if (is_bool($flag)) {
        return $flag;
    }
    if (is_int($flag) || is_float($flag)) {
        return (int) $flag === 1;
    }
    $text = strtolower(trim((string) $flag));
    return $text === '1' || $text === 'true';
}

function payout_install(array &$world, string $debit): void
{
    $rows = [[
        'RowId' => 1,
        'ArticleNumber' => 'BAT',
        'Description' => 'Batteri',
        'Price' => 20000,
        'HouseWork' => true,
        'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
        'DeliveredQuantity' => '1',
    ]];
    $order = ['Order' => [
        'DocumentNumber' => '9001',
        'Total' => 20000,
        'TaxReduction' => 5000,
        'TotalToPay' => 15000,
        'OrderRows' => $rows,
    ]];
    $GLOBALS['_JEAB_FORTNOX_ORDER_GET'] = static function () use ($order): array {
        return $order;
    };
    $GLOBALS['_JEAB_FORTNOX_ORDER_PUT'] = static function (string $no, array $lines) use ($order): array {
        $order['Order']['OrderRows'] = $lines;
        return $order;
    };
    $GLOBALS['_JEAB_FORTNOX_GT_SYNC'] = static function (array $row, array $before, array $after, array $snapshot): array {
        return ['order' => $snapshot, 'asked' => 5000, 'update' => false, 'capped' => false];
    };
    $GLOBALS['_JEAB_FORTNOX_GT_LOWER'] = static function (): array {
        return ['update' => false];
    };
    $GLOBALS['_JEAB_FORTNOX_INVOICE'] = static function () use ($debit): array {
        return ['Invoice' => ['DocumentNumber' => $debit]];
    };
    $GLOBALS['_JEAB_FORTNOX_INVOICE_SYNC'] = static function (): array {
        return [];
    };
    $GLOBALS['_JEAB_FORTNOX_REQUEST'] = static function (string $method, string $path, ?array $body) use (&$world, $debit): array {
        $world['calls'][] = [$method, $path, $body];
        $blob = json_encode($body, JSON_UNESCAPED_UNICODE);
        foreach (['1000000007', '2000000008', '81059', '83333'] as $secret) {
            if (is_string($blob) && str_contains($blob, $secret)) {
                $world['leaked'] = true;
            }
        }
        if ($method === 'GET' && preg_match('#^/invoices/([^/]+)$#', $path, $m)) {
            return $world['invoices'][$m[1]] ?? ['Invoice' => ['DocumentNumber' => $m[1]]];
        }
        if ($method === 'PUT' && preg_match('#^/invoices/([^/]+)/credit$#', $path, $m)) {
            if (!empty($world['fail_credit'])) {
                throw new Exception((string) ($world['fail_credit_message'] ?? 'Fortnox nere'));
            }
            $src = $m[1];
            if (!payout_booked($world['invoices'][$src]['Invoice']['Booked'] ?? false)) {
                throw new Exception('Fakturan är inte bokförd');
            }
            if (trim((string) ($world['invoices'][$src]['Invoice']['CreditInvoiceReference'] ?? '')) !== '') {
                throw new Exception('Fakturan är redan krediterad');
            }
            $num = (string) $world['next'];
            $world['next']++;
            $copy = $world['invoices'][$src];
            $copy['Invoice']['DocumentNumber'] = $num;
            $copy['Invoice']['Credit'] = true;
            $copy['Invoice']['CreditInvoiceReference'] = $src;
            $world['invoices'][$num] = $copy;
            $world['invoices'][$src]['Invoice']['CreditInvoiceReference'] = $num;
            $world['tax'][] = [
                'Id' => 'tr-' . $num,
                'ReferenceNumber' => $num,
                'ReferenceDocumentType' => 'INVOICE',
                'TaxReductionAmounts' => [[
                    'AskedAmount' => 5000,
                    'WorkType' => 'STORAGESELFPRODUCEDELECTRICITY',
                ]],
            ];
            return $world['invoices'][$num];
        }
        if ($method === 'PUT' && preg_match('#^/invoices/([^/]+)$#', $path, $m)) {
            if ($m[1] === $debit) {
                throw new Exception('debetfakturan får inte skrivas om');
            }
            if (!empty($world['fail_adjust'])) {
                $stored = jeab_purchase_get((int) ($world['order_id'] ?? 0));
                $savedNo = '';
                if (is_array($stored)) {
                    foreach (jeab_ordrar_payouts($stored) as $savedPayout) {
                        $savedNo = trim((string) ($savedPayout['fortnox_credit'] ?? ''));
                        if ($savedNo !== '') {
                            break;
                        }
                    }
                }
                $world['credit_saved_before_adjust'] = $savedNo;
                throw new Exception('kunde inte justera raderna');
            }
            $num = $m[1];
            $patch = is_array($body['Invoice'] ?? null) ? $body['Invoice'] : [];
            if (!isset($world['invoices'][$num])) {
                $world['invoices'][$num] = ['Invoice' => ['DocumentNumber' => $num, 'Credit' => true]];
            }
            $world['invoices'][$num]['Invoice'] = array_merge($world['invoices'][$num]['Invoice'], $patch);
            return $world['invoices'][$num];
        }
        if ($method === 'POST' && $path === '/invoices') {
            $num = (string) $world['next'];
            $world['next']++;
            $patch = is_array($body['Invoice'] ?? null) ? $body['Invoice'] : [];
            $world['invoices'][$num] = ['Invoice' => array_merge($patch, [
                'DocumentNumber' => $num,
                'Credit' => true,
            ])];
            return $world['invoices'][$num];
        }
        if ($method === 'GET' && str_starts_with($path, '/taxreductions')) {
            return ['TaxReductions' => $world['tax'], 'MetaInformation' => ['@TotalPages' => 1]];
        }
        if ($method === 'DELETE' && preg_match('#^/taxreductions/(.+)$#', $path, $m)) {
            $id = rawurldecode($m[1]);
            if ($id === 'tr-debit') {
                throw new Exception('ROT/grön teknik på debetfakturan får inte tas bort');
            }
            $world['tax'] = array_values(array_filter(
                $world['tax'],
                static fn (array $tax): bool => (string) ($tax['Id'] ?? '') !== $id
            ));
            return [];
        }
        throw new Exception('oväntat Fortnox-anrop ' . $method . ' ' . $path);
    };
}

function payout_rewrite_after_tax_delete(array $world, string $debit): bool
{
    $deleteAt = null;
    $rewriteAt = null;
    foreach ($world['calls'] as $i => $call) {
        $path = (string) $call[1];
        if ($deleteAt === null && $call[0] === 'DELETE' && str_starts_with($path, '/taxreductions/') && !str_ends_with($path, 'tr-debit')) {
            $deleteAt = $i;
        }
        if ($rewriteAt === null && $call[0] === 'PUT' && preg_match('#^/invoices/[^/]+$#', $path) && $path !== '/invoices/' . $debit) {
            $rewriteAt = $i;
        }
    }
    return $deleteAt !== null && $rewriteAt !== null && $deleteAt < $rewriteAt;
}

function payout_count(array $world, string $method, string $pattern): int
{
    $n = 0;
    foreach ($world['calls'] as $call) {
        if ($call[0] === $method && preg_match($pattern, (string) $call[1])) {
            $n++;
        }
    }
    return $n;
}

function payout_input(int $id, array $amounts, array $types, array $clearings, array $accounts, string $intent = 'faktura'): array
{
    return [
        'id' => $id,
        'intent' => $intent,
        'payouts_form' => '1',
        'payout_id' => array_fill(0, count($amounts), ''),
        'payout_type' => $types,
        'payout_amount' => $amounts,
        'payout_clearing' => $clearings,
        'payout_account' => $accounts,
        'fx_rowid' => ['1', '', '', ''],
        'fx_article' => ['BAT', '', '', ''],
        'fx_desc' => ['Batteri', '', '', ''],
        'fx_hw' => ['STORAGESELFPRODUCEDELECTRICITY', '', '', ''],
        'fx_qty' => ['1', '', '', ''],
        'fx_price' => ['20000', '', '', ''],
    ];
}

$masked = jeab_log_mask_text('utbetalning 81059-1000000007 till kund');
payout_assert(str_contains($masked, '****0007'), 'logg maskerar kontonummer');
payout_assert(!str_contains($masked, '1000000007') && !str_contains($masked, '81059'), 'logg lämnar inte clearing eller konto');
payout_assert(jeab_ordrar_payout_mask('1000000007') === '****0007', 'listmask är **** och fyra sista');

$plain = ['pris' => 0, 'kind' => 'batteri', 'payouts' => []];
$empty = jeab_ordrar_payouts_merge($plain, ['payouts_form' => '1']);
payout_assert(($empty['payouts'] ?? null) === [], 'tomt formulär är giltigt och sparar ingen utbetalning');

$optional = jeab_ordrar_payouts_merge($plain, [
    'payouts_form' => '1',
    'payout_type' => ['goodwill'],
    'payout_amount' => [''],
    'payout_clearing' => [''],
    'payout_account' => [''],
]);
payout_assert(count($optional['payouts']) === 1 && (int) $optional['payouts'][0]['amount'] === 0, 'typ utan belopp får sparas');

$noCap = jeab_ordrar_payouts_merge($plain, [
    'payout_type' => ['inbyte'],
    'payout_amount' => ['50'],
    'payout_clearing' => [''],
    'payout_account' => [''],
]);
payout_assert((int) $noCap['payouts'][0]['amount'] === 50, 'utan känt fakturabelopp avvisas inte beloppet');

try {
    jeab_ordrar_payouts_merge(['pris' => 1000, 'kind' => 'batteri'], [
        'payout_type' => ['goodwill'],
        'payout_amount' => ['1001'],
        'payout_clearing' => [''],
        'payout_account' => [''],
    ]);
    payout_assert(false, 'belopp över fakturatotal ska avvisas');
} catch (InvalidArgumentException $e) {
    payout_assert(str_contains($e->getMessage(), 'fakturans total'), 'feltext nämner fakturans total');
}

try {
    jeab_ordrar_payouts_merge(['pris' => 1000, 'kind' => 'batteri'], [
        'payout_type' => ['goodwill', 'inbyte'],
        'payout_amount' => ['600', '500'],
        'payout_clearing' => ['', ''],
        'payout_account' => ['', ''],
    ]);
    payout_assert(false, 'summan över fakturatotal ska avvisas');
} catch (InvalidArgumentException $e) {
    payout_assert(str_contains($e->getMessage(), 'tillsammans'), 'feltext gäller summan');
}

try {
    jeab_ordrar_payouts_merge(['pris' => 1000, 'kind' => 'batteri'], [
        'payout_type' => [''],
        'payout_amount' => ['10'],
        'payout_clearing' => [''],
        'payout_account' => [''],
    ]);
    payout_assert(false, 'belopp utan typ ska avvisas');
} catch (InvalidArgumentException $e) {
    payout_assert($e->getMessage() === 'Välj Inbyte eller Goodwill.', 'typ krävs bara när belopp finns');
}

try {
    jeab_ordrar_payouts_merge(['pris' => 1000, 'kind' => 'batteri'], [
        'payout_type' => ['goodwill'],
        'payout_amount' => ['0'],
        'payout_clearing' => [''],
        'payout_account' => [''],
    ]);
    payout_assert(false, 'nollbelopp ska avvisas');
} catch (InvalidArgumentException $e) {
    payout_assert(str_contains($e->getMessage(), 'positivt belopp'), 'nollbelopp får rätt feltext');
}

$four = jeab_ordrar_payouts_merge(['pris' => 5000, 'kind' => 'batteri'], [
    'payout_type' => ['inbyte'],
    'payout_amount' => ['10'],
    'payout_clearing' => ['8123'],
    'payout_account' => ['1234567'],
]);
payout_assert($four['payouts'][0]['clearing'] === '8123' && $four['payouts'][0]['account'] === '1234567', 'fyra siffror i clearing är giltigt även om det börjar med 8');

$swed = jeab_ordrar_payouts_merge(['pris' => 5000, 'kind' => 'batteri'], [
    'payout_type' => ['goodwill'],
    'payout_amount' => ['10'],
    'payout_clearing' => ['8105 9'],
    'payout_account' => ['1000000007'],
]);
payout_assert($swed['payouts'][0]['clearing'] === '81059', 'mellanslag i clearing tas bort');

$example = jeab_ordrar_payout_parse_konto('8327-9, 123 456 789-0');
payout_assert($example['clearing'] === '83279' && $example['account'] === '1234567890', 'komma skiljer Swedbank 8xxx-x från kontot');
$spaced = jeab_ordrar_payout_parse_konto('1234 123 456-7');
payout_assert($spaced['clearing'] === '1234' && $spaced['account'] === '1234567', 'första mellanslaget skiljer, resten av kontot behåller bara siffror');
$tight = jeab_ordrar_payout_parse_konto('81059,1000000007');
payout_assert($tight['clearing'] === '81059' && $tight['account'] === '1000000007', 'komma utan mellanslag delas');
$swedSpace = jeab_ordrar_payout_parse_konto('8327-9 123456789-0');
payout_assert($swedSpace['clearing'] === '83279' && $swedSpace['account'] === '1234567890', 'mellanslag efter 8xxx-x skiljer kontot');
$hyphenSplit = jeab_ordrar_payout_parse_konto('1234-1234567');
payout_assert($hyphenSplit['clearing'] === '1234' && $hyphenSplit['account'] === '1234567', 'bindestreck mellan fyrasiffrig clearing och konto delas');
$emptyKonto = jeab_ordrar_payout_parse_konto('  ');
payout_assert($emptyKonto['clearing'] === '' && $emptyKonto['account'] === '', 'tomt kontofält ger ingen clearing och inget konto');
$plainDigits = jeab_ordrar_payout_parse_konto('12341234567');
payout_assert($plainDigits['clearing'] === '1234' && $plainDigits['account'] === '1234567', 'siffror utan skiljetecken delas när bara en uppdelning är giltig');
try {
    jeab_ordrar_payout_parse_konto('810591000000007');
    payout_assert(false, 'tvetydiga siffror ska avvisas');
} catch (InvalidArgumentException $e) {
    payout_assert(str_contains($e->getMessage(), 'kommatecken eller mellanslag'), 'tvetydigt konto ber om skiljetecken');
    payout_assert(!str_contains($e->getMessage(), '810591000000007'), 'feltexten ekar inte den ihopskrivna strängen');
}
$joined = jeab_ordrar_payout_konto_text(['clearing' => '81059', 'account' => '1000000007']);
payout_assert($joined === '8105-9, 1000000007', 'sparad Swedbank visas som 8xxx-x, konto');
$joinedBack = jeab_ordrar_payout_parse_konto($joined);
payout_assert($joinedBack['clearing'] === '81059' && $joinedBack['account'] === '1000000007', 'ihopslagen text kan läsas tillbaka');
$fourText = jeab_ordrar_payout_konto_text(['clearing' => '8123', 'account' => '1234567']);
payout_assert($fourText === '8123, 1234567', 'fyrasiffrig clearing visas med kommatecken');
$fromField = jeab_ordrar_payouts_merge(['pris' => 5000, 'kind' => 'batteri'], [
    'payout_type' => ['goodwill'],
    'payout_amount' => ['10'],
    'payout_konto' => ['8327-9, 123 456 789-0'],
]);
payout_assert($fromField['payouts'][0]['clearing'] === '83279' && $fromField['payouts'][0]['account'] === '1234567890', 'formulärets kontofält sparas uppdelat');
$optionalKonto = jeab_ordrar_payouts_merge(['pris' => 0, 'kind' => 'batteri'], [
    'payout_type' => ['inbyte'],
    'payout_amount' => [''],
    'payout_konto' => [''],
]);
payout_assert(count($optionalKonto['payouts']) === 1 && $optionalKonto['payouts'][0]['clearing'] === '' && $optionalKonto['payouts'][0]['account'] === '', 'belopp och konto är fortfarande frivilliga');

foreach ([
    ['12345', '1000000007', 'börja med 8'],
    ['123', '1234567', '4–5 siffror'],
    ['1234', '123456', '7–12 siffror'],
    ['1234', '', 'båda tomma'],
] as $bad) {
    try {
        jeab_ordrar_payouts_merge(['pris' => 5000, 'kind' => 'batteri'], [
            'payout_type' => ['goodwill'],
            'payout_amount' => ['10'],
            'payout_clearing' => [$bad[0]],
            'payout_account' => [$bad[1]],
        ]);
        payout_assert(false, 'ogiltigt konto ska avvisas: ' . $bad[0]);
    } catch (InvalidArgumentException $e) {
        payout_assert(str_contains($e->getMessage(), $bad[2]), 'feltext för ' . $bad[0] . ' innehåller förväntad del');
        payout_assert(!str_contains($e->getMessage(), '1000000007') && !str_contains($e->getMessage(), '1234567'), 'feltexten ekar inte kontonumret');
    }
}

jeab_purchase_put(payout_base(11, 10000));
$questions = jeab_ordrar_save_questions([
    'id' => 11,
    'kind' => 'batteri',
    'pris' => '10000',
    'payouts_form' => '1',
    'payout_id' => [''],
    'payout_type' => ['goodwill'],
    'payout_amount' => ['400'],
    'payout_clearing' => ['81059'],
    'payout_account' => ['1000000007'],
]);
payout_assert((int) ($questions['payouts'][0]['amount'] ?? 0) === 400, 'prissteget sparar goodwill');
payout_assert(($questions['payouts'][0]['status'] ?? '') === 'sparad', 'sparad utbetalning är inte krediterad');
payout_assert(!isset($GLOBALS['_JEAB_FORTNOX_REQUEST']), 'prissteget anropar inte Fortnox-kredit');

$GLOBALS['_JEAB_PAYOUT_REVEAL'] = true;
$bekrafta = jeab_ordrar_v3_bekrafta_form($questions, 'jarfalla', 11, null)['html'];
$fakturaHtml = jeab_ordrar_v3_faktura_html($questions, 'jarfalla', 11, ['state' => 'todo', 'n' => 4, 'reason' => '']);
$both = $bekrafta . $fakturaHtml;
payout_assert(str_contains($both, '+ Lägg till utbetalning'), 'knappen finns i prissteget och på fakturan');
payout_assert(str_contains($both, 'Goodwill') && str_contains($both, 'Inbyte'), 'segmentet har båda typerna');
payout_assert(str_contains($both, 'Clearing- och kontonummer'), 'raden har ett kontofält');
payout_assert(str_contains($both, 't.ex. 8327-9, 123 456 789-0'), 'kontofältet har Swedbank-exemplet');
payout_assert(str_contains($both, 'Clearing- och kontonummer</span><input type="text" inputmode="text"'), 'kontofältet tillåter bindestreck');
payout_assert(str_contains($both, 'value="8105-9, 1000000007"'), 'sparad utbetalning visas ihopslagen i fältet');
payout_assert(!str_contains($both, '>Clearingnummer<') && !str_contains($both, '>Kontonummer<'), 'clearing och konto är inte längre två fält');
payout_assert(!str_contains($both, 'name="payout_clearing') && !str_contains($both, 'name="payout_account'), 'formuläret postar det samlade kontofältet');
$emptyHtml = jeab_ordrar_payouts_html(['payouts' => []]);
$emptyList = '';
if (preg_match('/data-jeab-pay-list>(.*)<\/div><template>/s', $emptyHtml, $emptyMatch) === 1) {
    $emptyList = $emptyMatch[1];
}
payout_assert(trim($emptyList) === '', 'utan sparad utbetalning ritas inget kort');
payout_assert(str_contains($emptyHtml, 'data-jeab-pay-help hidden>'), 'hjälptexten är dold när inget kort finns');
payout_assert(str_contains($emptyHtml, 'Inbyte eller Goodwill. Belopp och konto är inte obligatoriska.'), 'hjälptexten finns kvar i källan');
payout_assert(!str_contains(explode('<template>', $emptyHtml, 2)[0], 'name="payout_amount['), 'tom vy postar inget tomt kort');
$savedHtml = jeab_ordrar_payouts_html($questions);
payout_assert(str_contains(explode('<template>', $savedHtml, 2)[0], 'name="payout_amount[0]"'), 'sparad utbetalning ritas som kort');
payout_assert(str_contains($savedHtml, 'data-jeab-pay-help>') && !str_contains($savedHtml, 'data-jeab-pay-help hidden'), 'hjälptexten syns när ett kort finns');
payout_assert(str_contains($both, 'help.hidden=list.querySelector("[data-jeab-pay]")===null'), 'sista kortet döljer hjälptexten igen');
payout_assert(str_contains($both, '1000000007'), 'inloggad ordervy visar kontonumret');
payout_assert(substr_count($both, 'window.jeabPayBound=true') === 1, 'läggtill-scriptet skrivs en gång');
payout_assert(str_contains($fakturaHtml, 'name="payout_amount[0]"'), 'sista steget visar det ifyllda beloppet');
unset($GLOBALS['_JEAB_PAYOUT_REVEAL']);
$maskedHtml = jeab_ordrar_payouts_summary_html($questions);
payout_assert(str_contains($maskedHtml, '****0007'), 'listan maskerar kontot');
payout_assert(!str_contains($maskedHtml, '1000000007') && !str_contains($maskedHtml, '81059'), 'listan visar inte hela kontot');

$debit = '5001';
$world = payout_world($debit);
payout_install($world, $debit);
jeab_purchase_put(payout_base(21));
$without = jeab_ordrar_save_fakturering(payout_input(21, [], [], [], []));
payout_assert(jeab_ordrar_invoice_number($without) === $debit, 'faktura utan utbetalning skapas som vanligt');
payout_assert(jeab_ordrar_payouts($without) === [], 'utan utbetalning skapas ingen kredit');
payout_assert($world['calls'] === [], 'utan utbetalning görs inget kredit-anrop');
$withoutRows = $without['fortnox_order_rows'] ?? [];
$withoutAsked = (int) ($without['fortnox_gt_asked'] ?? 0);
$withoutTypes = array_map(static fn (array $line): string => (string) ($line['HouseWorkType'] ?? ''), $withoutRows);

$world = payout_world($debit);
$world['invoices'][$debit]['Invoice']['Booked'] = true;
payout_install($world, $debit);
jeab_purchase_put(payout_base(22));
$with = jeab_ordrar_save_fakturering(payout_input(
    22,
    ['500', '700'],
    ['goodwill', 'inbyte'],
    ['81059', '83333'],
    ['1000000007', '2000000008']
));
payout_assert(jeab_ordrar_invoice_number($with) === $debit, 'fakturan skapas även med utbetalning');
payout_assert((int) ($with['fortnox_gt_asked'] ?? 0) === $withoutAsked, 'ansökt grön teknik är samma med och utan utbetalning');
$withRows = $with['fortnox_order_rows'] ?? [];
$withTypes = array_map(static fn (array $line): string => (string) ($line['HouseWorkType'] ?? ''), $withRows);
payout_assert($withTypes === $withoutTypes, 'husarbetstyperna på ordern är oförändrade');
payout_assert(json_encode($withRows) === json_encode($withoutRows), 'orderraderna är identiska med och utan utbetalning');
payout_assert(
    FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases($withRows)) === FortnoxAPI::greenTechPossible(FortnoxAPI::houseworkBases($withoutRows)),
    'grön teknik-underlaget räknas likadant'
);
payout_assert(empty($world['leaked']), 'kontonummer skickas inte till Fortnox');
payout_assert(payout_count($world, 'PUT', '#^/invoices/' . $debit . '/credit$#') === 1, 'första utbetalningen använder PUT credit en gång');
payout_assert(payout_count($world, 'POST', '#^/invoices$#') === 1, 'andra utbetalningen blir en egen kreditfaktura');
payout_assert(payout_count($world, 'PUT', '#^/invoices/' . $debit . '$#') === 0, 'debetfakturan skrivs inte om');
payout_assert(payout_count($world, 'DELETE', '#^/taxreductions/tr-debit$#') === 0, 'debetfakturans skattereduktion tas inte bort');
payout_assert(payout_rewrite_after_tax_delete($world, $debit), 'skattereduktionen tas bort innan ROT-raderna skrivs om');
payout_assert(payout_count($world, 'PUT', '#/bookkeep#') === 0 && payout_count($world, 'POST', '#/bookkeep#') === 0, 'ingen faktura bokförs automatiskt');

$credits = [];
foreach (jeab_ordrar_payouts($with) as $payout) {
    payout_assert(($payout['status'] ?? '') === 'krediterad', 'utbetalningen är krediterad');
    payout_assert(trim((string) ($payout['fortnox_credit'] ?? '')) !== '', 'kreditfakturanummer sparas');
    $credits[] = (string) $payout['fortnox_credit'];
    $doc = $world['invoices'][$payout['fortnox_credit']]['Invoice'] ?? [];
    $lines = $doc['InvoiceRows'] ?? [];
    payout_assert(count($lines) === 1, 'kreditfakturan har en rad');
    $line = $lines[0];
    payout_assert(($line['HouseWork'] ?? null) === false, 'kreditraden är inte husarbete');
    payout_assert(!array_key_exists('HouseWorkType', $line), 'kreditraden har ingen husarbetstyp');
    payout_assert((int) ($line['VAT'] ?? -1) === 25, 'kreditraden har samma moms som batteriraden');
    payout_assert((string) ($line['ArticleNumber'] ?? '') === 'BAT', 'kreditraden har produktartikeln');
    payout_assert((int) ($line['AccountNumber'] ?? 0) === 3001, 'kreditraden har batteriradens försäljningskonto');
    payout_assert((int) ($line['AccountNumber'] ?? 0) !== 3990, 'framkörning väljs inte trots större belopp');
    payout_assert((string) ($line['DeliveredQuantity'] ?? '') === '-1', 'kreditraden har negativt antal');
    $expect = ((string) $payout['type'] === 'inbyte' ? 'Inbyte' : 'Goodwill') . ' order 9001';
    payout_assert((string) ($line['Description'] ?? '') === $expect, 'radtexten är ' . $expect);
    payout_assert((int) ($line['Price'] ?? 0) === (int) $payout['amount'], 'radens pris är utbetalningen');
}
payout_assert(count(array_unique($credits)) === 2, 'två utbetalningar ger två kreditfakturor');
$taxIds = array_map(static fn (array $tax): string => (string) ($tax['Id'] ?? ''), $world['tax']);
payout_assert(in_array('tr-debit', $taxIds, true), 'ROT/grön teknik på originalet finns kvar');
payout_assert($taxIds === ['tr-debit'], 'bara kreditfakturans skattereduktion tas bort');

$callsBefore = count($world['calls']);
$again = jeab_ordrar_save_fakturering([
    'id' => 22,
    'intent' => 'faktura',
    'payouts_form' => '1',
    'payout_id' => array_column($with['payouts'], 'id'),
    'payout_type' => ['goodwill', 'inbyte'],
    'payout_amount' => ['500', '700'],
    'payout_clearing' => ['81059', '83333'],
    'payout_account' => ['1000000007', '2000000008'],
    'fx_rowid' => ['1', '', '', ''],
    'fx_article' => ['BAT', '', '', ''],
    'fx_desc' => ['Batteri', '', '', ''],
    'fx_hw' => ['STORAGESELFPRODUCEDELECTRICITY', '', '', ''],
    'fx_qty' => ['1', '', '', ''],
    'fx_price' => ['20000', '', '', ''],
]);
payout_assert(count($world['calls']) === $callsBefore, 'andra trycket skapar ingen ny kredit');
payout_assert(array_column($again['payouts'], 'fortnox_credit') === $credits, 'samma kreditfakturanummer ligger kvar');
$afterBookedLog = is_file(jeab_app_errors_path()) ? (string) file_get_contents(jeab_app_errors_path()) : '';
payout_assert(!str_contains($afterBookedLog, '3001') && !str_contains($afterBookedLog, '3990'), 'lyckad kreditering loggar inte försäljningskontot');
payout_assert(!is_file($GLOBALS['_JEAB_DATA_DIR'] . '/rot/fortnox_config.php'), 'krediteringen behöver ingen Fortnox-konfiguration');

$store = jeab_store();
$reminder = jeab_ordrar_payout_find_reminder($store);
payout_assert(is_array($reminder), 'påminnelsen Utbetalningar finns');
payout_assert((string) ($reminder['title'] ?? '') === 'Utbetalningar', 'titeln är Utbetalningar');
payout_assert(in_array('Sam L', jeab_assignees_from_row($reminder), true), 'påminnelsen är till Sam L');
$note = (string) ($reminder['note'] ?? '');
payout_assert(substr_count($note, '[payout:') === 2, 'två utbetalningar blir två rader');
payout_assert(str_contains($note, 'Fiktiv Kund') && str_contains($note, 'Goodwill') && str_contains($note, 'Inbyte'), 'raden har kund, typ');
payout_assert(str_contains($note, '81059-1000000007') && str_contains($note, '83333-2000000008'), 'påminnelsen visar hela kontot');
payout_assert(str_contains($note, 'faktura ' . $debit) && str_contains($note, 'order 9001'), 'raden har order och faktura');
$hookNote = (string) (jeab_reminder_payload($reminder)['note'] ?? '');
payout_assert($hookNote !== '' && !str_contains($hookNote, '1000000007') && !str_contains($hookNote, '81059'), 'webhook maskerar kontot');
payout_assert(str_contains($hookNote, '****0007') && str_contains($hookNote, '****0008'), 'webhook visar den maskerade ändelsen');

$changed = jeab_ordrar_payouts_merge($again, [
    'payouts_form' => '1',
    'payout_id' => array_column($again['payouts'], 'id'),
    'payout_type' => ['inbyte', 'goodwill'],
    'payout_amount' => ['1', '1'],
    'payout_clearing' => ['1234', '83333'],
    'payout_account' => ['7654321', '2000000008'],
]);
payout_assert((int) $changed['payouts'][0]['amount'] === 500, 'krediterat belopp kan inte ändras');
payout_assert((string) $changed['payouts'][0]['type'] === 'goodwill', 'krediterad typ kan inte ändras');
payout_assert((string) $changed['payouts'][0]['account'] === '7654321', 'kontonummer kan kompletteras efter kreditering');
$updatedNote = (string) (jeab_ordrar_payout_find_reminder(jeab_store())['note'] ?? '');
payout_assert(str_contains($updatedNote, '1234-7654321') && substr_count($updatedNote, '[payout:') === 2, 'ändrat konto uppdaterar samma påminnelserad');

$world = payout_world('5002');
$world['invoices']['5002']['Invoice']['Booked'] = true;
$world['fail_credit'] = true;
$world['fail_credit_message'] = 'Kan inte kreditera kund 198001011234 AccountNumber 3001 konto 81059-1000000007';
payout_install($world, '5002');
jeab_purchase_put(payout_base(31));
$failed = jeab_ordrar_save_fakturering(payout_input(31, ['300'], ['goodwill'], ['81059'], ['1000000007']));
payout_assert(jeab_ordrar_invoice_number($failed) === '5002', 'fakturan ligger kvar när krediteringen misslyckas');
payout_assert(($failed['payouts'][0]['status'] ?? '') === 'kreditering misslyckades', 'status är kreditering misslyckades');
payout_assert(trim((string) ($failed['payouts'][0]['fortnox_credit'] ?? '')) === '', 'misslyckad kredit utan nummer sparar inget nummer');
payout_assert(!str_contains((string) ($failed['payouts'][0]['error'] ?? ''), '1000000007'), 'feltexten innehåller inte kontot');
payout_assert(!str_contains((string) ($failed['payouts'][0]['error'] ?? ''), '198001011234'), 'feltexten innehåller inte personnummer');
payout_assert(!str_contains((string) ($failed['payouts'][0]['error'] ?? ''), '3001'), 'feltexten innehåller inte försäljningskontot');
payout_assert(str_contains((string) ($failed['payouts'][0]['error'] ?? ''), '****'), 'feltexten är maskerad');
$failedHtml = jeab_ordrar_payouts_html($failed) . jeab_ordrar_payouts_summary_html($failed) . jeab_ordrar_payout_retry_html($failed, 'jarfalla', 31);
payout_assert(str_contains($failedHtml, 'Kreditering misslyckades'), 'ytan visar Kreditering misslyckades');
payout_assert(substr_count($failedHtml, (string) $failed['payouts'][0]['error']) >= 2, 'det maskerade felet står bredvid statusen');
payout_assert(!str_contains($failedHtml, '1000000007') && !str_contains($failedHtml, '198001011234') && !str_contains($failedHtml, '81059') && !str_contains($failedHtml, '3001'), 'ytan visar inte personnummer eller konto');
$logFile = jeab_app_errors_path();
$logText = is_file($logFile) ? (string) file_get_contents($logFile) : '';
payout_assert(str_contains($logText, 'Kreditering misslyckades'), 'Fellogg har krediteringsfelet');
payout_assert(!str_contains($logText, '1000000007') && !str_contains($logText, '198001011234') && !str_contains($logText, '81059') && !str_contains($logText, '3001'), 'Fellogg maskerar personnummer och konto');
appLog('tax_reduction_failed', ['error' => 'kund 19800101-1234 AccountNumber 3001 konto 81059-1000000007']);
$logAfter = (string) file_get_contents($logFile);
payout_assert(str_contains($logAfter, 'tax_reduction_failed'), 'appLog skriver till Fellogg');
payout_assert(!str_contains($logAfter, '19800101-1234') && !str_contains($logAfter, '1000000007') && !str_contains($logAfter, '3001'), 'appLog maskerar personnummer och konto');
$failedNote = (string) (jeab_ordrar_payout_find_reminder(jeab_store())['note'] ?? '');
payout_assert(str_contains($failedNote, 'kreditering misslyckades'), 'påminnelsen nämner misslyckandet');
payout_assert(str_contains($failedNote, '[payout:' . $failed['payouts'][0]['id'] . ']'), 'misslyckad utbetalning har en egen rad');

$retryHtml = jeab_ordrar_payout_retry_html($failed, 'jarfalla', 31);
payout_assert(str_contains($retryHtml, 'Försök igen') && str_contains($retryHtml, 'ordrar.payout_retry'), 'misslyckad utbetalning har en omförsöksknapp');
payout_assert(!str_contains($retryHtml, 'name="action" value="ordrar.fakturering"'), 'omförsök postar inte om fakturaraderna');

payout_assert(trim((string) ($world['invoices']['5002']['Invoice']['CreditInvoiceReference'] ?? '')) === '', 'misslyckat anrop lämnar ingen kreditfaktura');
$failedCalls = payout_count($world, 'PUT', '#^/invoices/5002/credit$#');
$world['fail_credit'] = false;
$retried = jeab_ordrar_payout_retry(['id' => 31, 'payout_id' => $failed['payouts'][0]['id']]);
payout_assert(($retried['payouts'][0]['status'] ?? '') === 'krediterad', 'omförsök krediterar');
payout_assert(payout_count($world, 'PUT', '#^/invoices/5002/credit$#') === $failedCalls + 1, 'omförsök skapar en kredit');
payout_assert(trim((string) ($world['invoices']['5002']['Invoice']['CreditInvoiceReference'] ?? '')) !== '', 'debetfakturan pekar på en kredit');
$retriedAgain = jeab_ordrar_payout_retry(['id' => 31, 'payout_id' => $failed['payouts'][0]['id']]);
payout_assert(payout_count($world, 'PUT', '#^/invoices/5002/credit$#') === $failedCalls + 1, 'andra omförsöket skapar ingen ny kredit');
payout_assert((string) ($retriedAgain['payouts'][0]['fortnox_credit'] ?? '') === (string) ($retried['payouts'][0]['fortnox_credit'] ?? ''), 'kreditnumret är stabilt');

$world = payout_world('5003');
$world['invoices']['5003']['Invoice']['Booked'] = true;
payout_install($world, '5003');
$world['fail_adjust'] = true;
$world['order_id'] = 32;
jeab_purchase_put(payout_base(32));
$half = jeab_ordrar_save_fakturering(payout_input(32, ['150'], ['inbyte'], ['1234'], ['1234567']));
payout_assert(jeab_ordrar_invoice_number($half) === '5003', 'fakturan finns kvar när justeringen misslyckas');
payout_assert(($half['payouts'][0]['status'] ?? '') === 'kreditering misslyckades', 'justeringsfel ger kreditering misslyckades');
payout_assert(trim((string) ($half['payouts'][0]['fortnox_credit'] ?? '')) !== '', 'kreditnumret sparas innan justeringen');
payout_assert(trim((string) ($world['credit_saved_before_adjust'] ?? '')) !== '', 'kreditnumret är committat innan raderna justeras');
payout_assert(glob($tmp . '/payout-*.lock') === [], 'ingen egen fillås för utbetalningen');
payout_assert(payout_count($world, 'PUT', '#^/invoices/5003/credit$#') === 1, 'en kredit skapades');
$world['fail_adjust'] = false;
$fixed = jeab_ordrar_payout_retry(['id' => 32, 'payout_id' => $half['payouts'][0]['id']]);
payout_assert(($fixed['payouts'][0]['status'] ?? '') === 'krediterad', 'omförsök slutför samma kreditfaktura');
payout_assert(payout_count($world, 'PUT', '#^/invoices/5003/credit$#') === 1, 'omförsök anropar inte PUT credit igen');
payout_assert((string) $fixed['payouts'][0]['fortnox_credit'] === (string) $half['payouts'][0]['fortnox_credit'], 'samma kreditfaktura justeras');

$afterFail = (string) (jeab_ordrar_payout_find_reminder(jeab_store())['note'] ?? '');
payout_assert(substr_count($afterFail, '[payout:') === 4, 'nya utbetalningar läggs som rader i samma påminnelse');

$world = payout_world('5004');
payout_install($world, '5004');
jeab_purchase_put(payout_base(41));
$open = jeab_ordrar_save_fakturering(payout_input(41, ['250'], ['goodwill'], ['81059'], ['1000000007']));
payout_assert(jeab_ordrar_invoice_number($open) === '5004', 'obokad faktura skapas');
payout_assert(($open['payouts'][0]['status'] ?? '') === 'krediterad', 'obokad faktura krediteras via fristående faktura');
payout_assert((string) ($open['payouts'][0]['fortnox_credit_via'] ?? '') === 'post', 'obokad väg är post');
payout_assert(payout_count($world, 'PUT', '#^/invoices/5004/credit$#') === 0, 'obokad faktura anropar inte PUT credit');
payout_assert(payout_count($world, 'POST', '#^/invoices$#') === 1, 'obokad faktura skapar en fristående kredit');
payout_assert(payout_count($world, 'PUT', '#/bookkeep#') === 0, 'obokad faktura bokförs inte');
$openNo = (string) $open['payouts'][0]['fortnox_credit'];
payout_assert((string) ($world['invoices'][$openNo]['Invoice']['ExternalInvoiceReference1'] ?? '') === '5004', 'fristående kredit pekar på originalet');
$openLine = $world['invoices'][$openNo]['Invoice']['InvoiceRows'][0];
payout_assert(($openLine['HouseWork'] ?? null) === false, 'fristående kredit har ingen ROT-rad');
payout_assert((int) ($openLine['VAT'] ?? -1) === 25 && (int) ($openLine['AccountNumber'] ?? 0) === 3001, 'fristående kredit kopierar batteriets konto och moms');
payout_assert((int) ($openLine['Price'] ?? 0) === 250, 'beloppet inklusive moms skrivs som på debetfakturan');
$openCalls = count($world['calls']);
$openAgain = jeab_ordrar_payout_retry(['id' => 41, 'payout_id' => $open['payouts'][0]['id']]);
payout_assert(count($world['calls']) === $openCalls, 'omförsök på obokad kredit skapar ingen ny kredit');
payout_assert((string) ($openAgain['payouts'][0]['fortnox_credit'] ?? '') === $openNo, 'samma fristående kreditnummer återanvänds');
$openPosted = jeab_ordrar_save_fakturering([
    'id' => 41,
    'intent' => 'faktura',
    'payouts_form' => '1',
    'payout_id' => [$open['payouts'][0]['id']],
    'payout_type' => ['goodwill'],
    'payout_amount' => ['250'],
    'payout_clearing' => ['81059'],
    'payout_account' => ['1000000007'],
    'fx_rowid' => ['1', '', '', ''],
    'fx_article' => ['BAT', '', '', ''],
    'fx_desc' => ['Batteri', '', '', ''],
    'fx_hw' => ['STORAGESELFPRODUCEDELECTRICITY', '', '', ''],
    'fx_qty' => ['1', '', '', ''],
    'fx_price' => ['20000', '', '', ''],
]);
payout_assert(count($world['calls']) === $openCalls, 'andra fakturatrycket på obokad order skapar ingen ny kredit');
payout_assert((string) ($openPosted['payouts'][0]['fortnox_credit'] ?? '') === $openNo, 'kreditnumret ligger kvar efter andra trycket');

$world = payout_world('5005');
payout_install($world, '5005');
$world['fail_adjust'] = true;
$world['order_id'] = 42;
jeab_purchase_put(payout_base(42));
$openHalf = jeab_ordrar_save_fakturering(payout_input(42, ['80'], ['inbyte'], ['1234'], ['1234567']));
payout_assert(payout_count($world, 'PUT', '#^/invoices/5005/credit$#') === 0, 'justeringsfel på obokad faktura använder inte PUT credit');
payout_assert(payout_count($world, 'POST', '#^/invoices$#') === 1, 'en fristående kredit skapades innan justeringen');
payout_assert(trim((string) ($openHalf['payouts'][0]['fortnox_credit'] ?? '')) !== '', 'kreditnumret sparas när justeringen faller');
$world['fail_adjust'] = false;
$openFixed = jeab_ordrar_payout_retry(['id' => 42, 'payout_id' => $openHalf['payouts'][0]['id']]);
payout_assert(($openFixed['payouts'][0]['status'] ?? '') === 'krediterad', 'omförsök slutför samma fristående kredit');
payout_assert(payout_count($world, 'POST', '#^/invoices$#') === 1, 'omförsök skapar ingen andra fristående kredit');
payout_assert((string) $openFixed['payouts'][0]['fortnox_credit'] === (string) $openHalf['payouts'][0]['fortnox_credit'], 'samma fristående kredit justeras');
$fixedLine = $world['invoices'][$openFixed['payouts'][0]['fortnox_credit']]['Invoice']['InvoiceRows'][0] ?? [];
payout_assert((int) ($fixedLine['AccountNumber'] ?? 0) === 3001 && (int) ($fixedLine['VAT'] ?? -1) === 25, 'omförsöket skriver produktens konto och moms');

$world = payout_world('5006');
$world['invoices']['5006']['Invoice']['InvoiceRows'] = [
    ['Description' => 'Framkörning', 'ArticleNumber' => 'FRAKT', 'AccountNumber' => 3990, 'Price' => 45, 'VAT' => 25, 'DeliveredQuantity' => '1'],
    ['Description' => 'Schablon', 'ArticleNumber' => 'SCH', 'AccountNumber' => 3991, 'Price' => 90000, 'VAT' => 25, 'HouseWorkType' => 'OTHERCOSTS', 'DeliveredQuantity' => '1'],
    ['Description' => 'Solpaneler', 'ArticleNumber' => 'SOL', 'AccountNumber' => 3010, 'Price' => 12000, 'VAT' => 25, 'HouseWork' => true, 'HouseWorkType' => 'SOLARCELLS', 'DeliveredQuantity' => '1'],
    ['Description' => 'Batteri', 'ArticleNumber' => 'BAT2', 'AccountNumber' => 3020, 'Price' => 40000, 'VAT' => 25, 'HouseWork' => true, 'HouseWorkType' => 'STORAGESELFPRODUCEDELECTRICITY', 'DeliveredQuantity' => '1'],
];
payout_install($world, '5006');
jeab_purchase_put(payout_base(51));
$solar = jeab_ordrar_save_fakturering(payout_input(51, ['1000'], ['goodwill'], ['81059'], ['1000000007']));
$solarNo = (string) ($solar['payouts'][0]['fortnox_credit'] ?? '');
$solarLine = $world['invoices'][$solarNo]['Invoice']['InvoiceRows'][0] ?? [];
payout_assert(($solar['payouts'][0]['status'] ?? '') === 'krediterad', 'solfaktura med flera rader krediteras');
payout_assert((int) ($solarLine['AccountNumber'] ?? 0) === 3020, 'största produktraden är batteriet, inte solpanelen');
payout_assert((string) ($solarLine['ArticleNumber'] ?? '') === 'BAT2', 'artikeln följer den valda produktraden');
payout_assert((int) ($solarLine['VAT'] ?? -1) === 25, 'momsen följer produktraden');
payout_assert((int) ($solarLine['AccountNumber'] ?? 0) !== 3991 && (int) ($solarLine['AccountNumber'] ?? 0) !== 3010, 'schablon och den mindre solraden väljs inte');

$world = payout_world('5007');
$world['invoices']['5007']['Invoice']['InvoiceRows'] = [
    ['Description' => 'Framkörning', 'ArticleNumber' => 'FRAKT', 'AccountNumber' => 3990, 'Price' => 90000, 'VAT' => 25, 'DeliveredQuantity' => '1'],
    ['Description' => 'Elbilsladdare', 'ArticleNumber' => 'LAD', 'AccountNumber' => 3040, 'Price' => 9000, 'VAT' => 25, 'HouseWork' => true, 'HouseWorkType' => 'CHARGINGSTATIONELECTRICVEHICLE', 'DeliveredQuantity' => '1'],
];
payout_install($world, '5007');
jeab_purchase_put(payout_base(52));
$box = jeab_ordrar_save_fakturering(payout_input(52, ['90'], ['inbyte'], ['1234'], ['1234567']));
$boxLine = $world['invoices'][(string) ($box['payouts'][0]['fortnox_credit'] ?? '')]['Invoice']['InvoiceRows'][0] ?? [];
payout_assert((int) ($boxLine['AccountNumber'] ?? 0) === 3040 && (string) ($boxLine['ArticleNumber'] ?? '') === 'LAD', 'laddboxraden väljs framför en större framkörning');
payout_assert((int) ($boxLine['VAT'] ?? -1) === 25 && (int) ($boxLine['Price'] ?? 0) === 90, 'laddboxens moms och belopp inklusive moms följer med');

$world = payout_world('5008');
$world['invoices']['5008']['Invoice']['VATIncluded'] = false;
payout_install($world, '5008');
jeab_purchase_put(payout_base(53));
$net = jeab_ordrar_save_fakturering(payout_input(53, ['500'], ['goodwill'], ['81059'], ['1000000007']));
$netLine = $world['invoices'][(string) ($net['payouts'][0]['fortnox_credit'] ?? '')]['Invoice']['InvoiceRows'][0] ?? [];
payout_assert((int) ($netLine['VAT'] ?? -1) === 25 && (int) ($netLine['AccountNumber'] ?? 0) === 3001, 'exklusive moms behåller produktens konto och momssats');
payout_assert((int) ($netLine['Price'] ?? 0) === 400, 'belopp inklusive moms räknas om till samma prisuttryck som debetraden');
payout_assert(($world['invoices'][(string) ($net['payouts'][0]['fortnox_credit'] ?? '')]['Invoice']['VATIncluded'] ?? null) === false, 'kreditfakturan använder samma momssätt som debetfakturan');

echo "payout tests done\n";
