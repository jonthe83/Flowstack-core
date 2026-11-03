<?php

declare(strict_types=1);

/**
 * H-ORD-4: utbetalningstaket är fakturanettot. Rabatt (negativt à-pris) sänker
 * taket. Kredit/utbetalningsrad (negativt antal, positivt pris) gör det inte.
 */

$tmp = sys_get_temp_dir() . '/jeab-payout-cap-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_WEBHOOK_CAPTURE=1');
$_ENV['JEAB_WEBHOOK_CAPTURE'] = '1';

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function payout_cap_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$discountRows = [
    ['Price' => 10000, 'DeliveredQuantity' => '1', 'Description' => 'Batteri'],
    ['Price' => -2000, 'DeliveredQuantity' => '1', 'Description' => 'Rabatt'],
];
payout_cap_assert(jeab_ordrar_payout_lines_gross($discountRows) === 8000, 'rabattrad sänker taket till fakturanettot, inte abs-summan');
payout_cap_assert(jeab_ordrar_payout_lines_gross([
    ['Price' => 10000, 'DeliveredQuantity' => '1', 'Description' => 'Batteri'],
]) === 10000, 'enbart positiva rader ger samma tak som tidigare');

$creditRows = [
    ['Price' => 10000, 'DeliveredQuantity' => '1', 'Description' => 'Batteri'],
    ['Price' => 2000, 'DeliveredQuantity' => '-1', 'Description' => 'Goodwill order 9001'],
];
payout_cap_assert(jeab_ordrar_payout_lines_gross($creditRows) === 10000, 'kreditrad med negativt antal räknas inte i taket');

$discountOnly = ['pris' => 0, 'kind' => 'batteri', 'fortnox_order_rows' => $discountRows];
payout_cap_assert(jeab_ordrar_payout_max($discountOnly) === 8000, 'utan annat belopp är taket nettots 8 000');
try {
    jeab_ordrar_payouts_merge($discountOnly, [
        'payout_type' => ['goodwill'],
        'payout_amount' => ['8001'],
        'payout_clearing' => [''],
        'payout_account' => [''],
    ]);
    payout_cap_assert(false, 'utbetalning över netto med rabatt ska avvisas');
} catch (InvalidArgumentException $e) {
    payout_cap_assert(str_contains($e->getMessage(), 'fakturans total'), 'rabatt-taket nämner fakturans total');
}

$underNet = jeab_ordrar_payouts_merge($discountOnly, [
    'payout_type' => ['goodwill'],
    'payout_amount' => ['8000'],
    'payout_clearing' => [''],
    'payout_account' => [''],
]);
payout_cap_assert((int) $underNet['payouts'][0]['amount'] === 8000, 'utbetalning på nettot med rabatt sparas');

$creditCap = jeab_ordrar_payouts_merge(['pris' => 0, 'kind' => 'batteri', 'fortnox_order_rows' => $creditRows], [
    'payout_type' => ['goodwill'],
    'payout_amount' => ['10000'],
    'payout_clearing' => [''],
    'payout_account' => [''],
]);
payout_cap_assert((int) $creditCap['payouts'][0]['amount'] === 10000, 'kreditrad sänker inte taket så utbetalningen ryms');

$postedDiscount = jeab_ordrar_payout_max(['pris' => 0, 'kind' => 'batteri'], [
    'fx_price' => ['10000', '-2000'],
    'fx_qty' => ['1', '1'],
]);
payout_cap_assert($postedDiscount === 8000, 'postade rader med rabatt ger netto, inte abs');

$postedCredit = jeab_ordrar_payout_max(['pris' => 0, 'kind' => 'batteri'], [
    'fx_price' => ['10000', '2000'],
    'fx_qty' => ['1', '-1'],
]);
payout_cap_assert($postedCredit === 10000, 'postad kreditrad med negativt antal lämnar produkttaket');

$batteryWithDiscount = [
    'pris' => 10000,
    'kind' => 'batteri',
    'fortnox_order_rows' => $discountRows,
];
payout_cap_assert(jeab_ordrar_payout_max($batteryWithDiscount) === 8000, 'pris 10 000 höjer inte taket när raderna nettoredovisar 8 000');
$savedAtNet = jeab_ordrar_payouts_merge($batteryWithDiscount, [
    'payout_type' => ['goodwill'],
    'payout_amount' => ['8000'],
    'payout_clearing' => [''],
    'payout_account' => [''],
]);
payout_cap_assert((int) $savedAtNet['payouts'][0]['amount'] === 8000, '8 000 kr sparas när pris är 10 000 och rabatten är 2 000');
foreach (['8001', '10000'] as $over) {
    try {
        jeab_ordrar_payouts_merge($batteryWithDiscount, [
            'payout_type' => ['goodwill'],
            'payout_amount' => [$over],
            'payout_clearing' => [''],
            'payout_account' => [''],
        ]);
        payout_cap_assert(false, $over . ' kr ska avvisas när nettot är 8 000');
    } catch (InvalidArgumentException $e) {
        payout_cap_assert(str_contains($e->getMessage(), 'fakturans total'), $over . ' kr avvisas mot fakturans total');
    }
}

$postedWithPris = jeab_ordrar_payout_max(['pris' => 10000, 'kind' => 'batteri'], [
    'fx_price' => ['10000', '-2000'],
    'fx_qty' => ['1', '1'],
]);
payout_cap_assert($postedWithPris === 8000, 'samma rabatt i fx_price ger 8 000 även när pris är 10 000');
try {
    jeab_ordrar_payouts_merge(['pris' => 10000, 'kind' => 'batteri'], [
        'payout_type' => ['goodwill'],
        'payout_amount' => ['8001'],
        'payout_clearing' => [''],
        'payout_account' => [''],
        'fx_price' => ['10000', '-2000'],
        'fx_qty' => ['1', '1'],
    ]);
    payout_cap_assert(false, 'postat netto under pris ska avvisa 8 001');
} catch (InvalidArgumentException $e) {
    payout_cap_assert(str_contains($e->getMessage(), 'fakturans total'), 'postat netto nämner fakturans total');
}
$postedSaved = jeab_ordrar_payouts_merge(['pris' => 10000, 'kind' => 'batteri'], [
    'payout_type' => ['goodwill'],
    'payout_amount' => ['8000'],
    'payout_clearing' => [''],
    'payout_account' => [''],
    'fx_price' => ['10000', '-2000'],
    'fx_qty' => ['1', '1'],
]);
payout_cap_assert((int) $postedSaved['payouts'][0]['amount'] === 8000, 'postat netto 8 000 sparas trots pris 10 000');

$postedLower = jeab_ordrar_payout_max([
    'pris' => 10000,
    'kind' => 'batteri',
    'fortnox_order_rows' => [
        ['Price' => 10000, 'DeliveredQuantity' => '1', 'Description' => 'Batteri'],
    ],
], [
    'fx_price' => ['10000', '-3000'],
    'fx_qty' => ['1', '1'],
]);
payout_cap_assert($postedLower === 7000, 'formulärets lägre netto vinner över lagrat pris och lagrade rader utan rabatt');

$zeroNet = [
    'pris' => 10000,
    'kind' => 'batteri',
    'fortnox_order_rows' => [
        ['Price' => 10000, 'DeliveredQuantity' => '1', 'Description' => 'Batteri'],
        ['Price' => -10000, 'DeliveredQuantity' => '1', 'Description' => 'Rabatt'],
    ],
];
payout_cap_assert(jeab_ordrar_payout_max($zeroNet) === 0, 'netto 0 är ett tak, inte obegränsat');
try {
    jeab_ordrar_payouts_merge($zeroNet, [
        'payout_type' => ['goodwill'],
        'payout_amount' => ['1'],
        'payout_clearing' => [''],
        'payout_account' => [''],
    ]);
    payout_cap_assert(false, 'positiv utbetalning ska avvisas när nettot är 0');
} catch (InvalidArgumentException $e) {
    payout_cap_assert(str_contains($e->getMessage(), 'fakturans total'), 'netto 0 avvisar positivt belopp');
}

$negativeNet = [
    'pris' => 5000,
    'kind' => 'batteri',
    'fortnox_order_rows' => [
        ['Price' => 1000, 'DeliveredQuantity' => '1', 'Description' => 'Batteri'],
        ['Price' => -2000, 'DeliveredQuantity' => '1', 'Description' => 'Rabatt'],
    ],
];
payout_cap_assert(jeab_ordrar_payout_max($negativeNet) === 0, 'negativt netto ger tak 0');
try {
    jeab_ordrar_payouts_merge($negativeNet, [
        'payout_type' => ['goodwill'],
        'payout_amount' => ['1'],
        'payout_clearing' => [''],
        'payout_account' => [''],
    ]);
    payout_cap_assert(false, 'positiv utbetalning ska avvisas när nettot är negativt');
} catch (InvalidArgumentException $e) {
    payout_cap_assert(str_contains($e->getMessage(), 'fakturans total'), 'negativt netto avvisar positivt belopp');
}

$incomplete = [
    'pris' => 10000,
    'kind' => 'batteri',
    'fortnox_order_rows' => [
        ['Price' => 1000, 'DeliveredQuantity' => '1', 'Description' => 'Framkörning'],
    ],
];
payout_cap_assert(jeab_ordrar_payout_max($incomplete) === 10000, 'utan rabatt höjer en ofullständig radlista inte bort pris');

$creditWithPris = jeab_ordrar_payouts_merge([
    'pris' => 10000,
    'kind' => 'batteri',
    'fortnox_order' => '9001',
    'fortnox_order_rows' => $creditRows,
], [
    'payout_type' => ['goodwill'],
    'payout_amount' => ['10000'],
    'payout_clearing' => [''],
    'payout_account' => [''],
]);
payout_cap_assert((int) $creditWithPris['payouts'][0]['amount'] === 10000, 'kreditrad med negativt antal sänker inte taket när pris är satt');

$negativePriceAndQty = jeab_ordrar_payout_max([
    'pris' => 10000,
    'kind' => 'batteri',
    'fortnox_order_rows' => [
        ['Price' => 10000, 'DeliveredQuantity' => '1', 'Description' => 'Batteri'],
        ['Price' => -2000, 'DeliveredQuantity' => '-1', 'Description' => 'Kredit med negativt pris'],
    ],
]);
payout_cap_assert($negativePriceAndQty === 10000, 'negativt pris med negativt antal är inte en rabatt och sänker inte taket');

$creditLine = jeab_ordrar_payout_plain_row(
    ['fortnox_order' => '9001'],
    ['type' => 'goodwill', 'amount' => 2000],
    ['Price' => 10000, 'VAT' => 25, 'ArticleNumber' => 'BAT', 'AccountNumber' => '3001'],
    ['VATIncluded' => true]
);
payout_cap_assert((string) ($creditLine['DeliveredQuantity'] ?? '') === '-1', 'kreditfakturaraden har fortfarande negativt antal');
payout_cap_assert((int) ($creditLine['Price'] ?? 0) === 2000, 'kreditfakturaraden har positivt pris');

echo "payout cap tests done\n";
