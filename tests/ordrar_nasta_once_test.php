<?php

declare(strict_types=1);

/**
 * Nästa på Föranmälan och Beställ får bara väcka Bokmalen en gång per ärende.
 * Sol, batteri och laddbox, Järfälla och Malmö, går genom jeab_ordrar_bokmalen_once.
 * Laddbox postar varken shoporder eller föranmälan.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-nasta-once-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempdir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', '<?php return ' . var_export([
    'db' => [
        'driver' => 'json',
        'json_path' => $tmp . '/store.json',
        'sqlite_path' => $tmp . '/app.sqlite',
    ],
], true) . ';');
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
putenv('JEAB_DISABLE_SQLITE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];

require dirname(__DIR__) . '/src/bootstrap.php';
$purchasePdo = new PDO('sqlite:' . $tmp . '/purchases.sqlite');
$purchasePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['_JEAB_PURCHASES_PDO'] = $purchasePdo;
jeab_purchases_store_ensure($purchasePdo);

function once_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function once_secret(): void
{
    $path = jeab_bokmalen_ordrar_webhook_secret_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode([
        'url' => 'https://bokmalen.example.invalid/hooks/jeab-ordrar',
        'key' => 'ordrar-once-key',
    ], JSON_UNESCAPED_SLASHES));
}

function once_reset(): void
{
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
}

/** @return list<array<string,mixed>> */
function once_of_type(string $type): array
{
    $rows = $GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? [];
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (is_array($row) && ($row['type'] ?? '') === $type) {
            $out[] = $row;
        }
    }
    return $out;
}

function once_put(array $extra): array
{
    static $seq = 8800;
    $seq++;
    $row = array_merge([
        'reminder_id' => $seq,
        'kind' => 'sol',
        'company' => 'jarfalla',
        'natbolag' => 'E.ON',
        'huvudsokande' => 'Höglund',
        'name' => 'Höglund',
        'personnummer' => '19700101-0101',
        'fastighetsbeteckning' => 'GYMN 1',
        'phone' => '0701234567',
        'email' => 'ada@example.com',
        'huvudsokande_address' => 'Gymnastikvägen 29, 177 00 Järfälla',
        'anlaggnings_id' => '735999114010135815',
        'kwh' => '12',
        'vaxelriktare' => 'Sigenergy 12 kW TP2',
        'foranmalan_status' => 'A',
        'inkop_lines' => [
            ['sku' => 'SOL-12', 'title' => 'Sol 12 kW', 'qty' => 1, 'shop' => 'aprilice'],
        ],
        'steps' => [],
    ], $extra);
    return jeab_purchase_put($row);
}

function once_reload(array $row): array
{
    $fresh = jeab_purchase_get((int) ($row['reminder_id'] ?? 0));
    once_assert(is_array($fresh), 'ordern finns kvar efter Nästa');
    return $fresh;
}

once_secret();

$ordrarSrc = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$onceStart = strpos($ordrarSrc, 'function jeab_ordrar_bokmalen_once');
$onceEnd = strpos($ordrarSrc, 'function jeab_ordrar_save_foranmalan', $onceStart === false ? 0 : $onceStart);
$onceBody = $onceStart !== false && $onceEnd !== false ? substr($ordrarSrc, $onceStart, $onceEnd - $onceStart) : '';
once_assert($onceBody !== '' && str_contains($onceBody, 'jeab_bokmalen_ordrar_notify('), 'once-vägen är den som postar');
once_assert(substr_count($ordrarSrc, 'jeab_bokmalen_ordrar_notify(') === 1, 'ordrar.php postar bara från once-vägen');
once_assert(!str_contains($onceBody, 'jarfalla') && !str_contains($onceBody, 'malmo'), 'bolag är inte en egen gren i once-vägen');
once_assert(substr_count($ordrarSrc, 'function jeab_ordrar_bestall_html') === 1, 'Beställ-HTML finns i en funktion');
once_assert(substr_count($ordrarSrc, 'jeab_ordrar_bestall_html(') === 1, 'ingen anropar jeab_ordrar_bestall_html');
$htmlStart = strpos($ordrarSrc, 'function jeab_ordrar_bestall_html');
$htmlEnd = strpos($ordrarSrc, 'function jeab_ordrar_boka_bestall_html', $htmlStart === false ? 0 : $htmlStart);
$htmlBody = $htmlStart !== false && $htmlEnd !== false ? substr($ordrarSrc, $htmlStart, $htmlEnd - $htmlStart) : '';
once_assert(str_contains($htmlBody, 'return jeab_ordrar_bestall_step_html('), 'bestall_html lämnar bara steg-HTML:en');

foreach (['sol', 'batteri'] as $kind) {
    foreach (['jarfalla', 'malmo'] as $company) {
        $label = $kind . '/' . $company;
        once_reset();
        $row = once_put([
            'kind' => $kind,
            'company' => $company,
            'natbolag' => 'E.ON',
            'kwh' => $kind === 'batteri' ? '9' : '12',
        ]);
        $first = jeab_ordrar_foranmalan_nasta(null, $row);
        $second = jeab_ordrar_foranmalan_nasta(null, once_reload($first));
        $posts = once_of_type('ordrar.foranmalan');
        once_assert(count($posts) === 1, $label . ' föranmälan Nästa postar en gång, fick ' . count($posts));
        once_assert(($posts[0]['utkast'] ?? null) === false, $label . ' föranmälan är live');
        once_assert(($posts[0]['kind'] ?? '') === $kind, $label . ' föranmälan-kind följer med');
        once_assert(($posts[0]['company'] ?? '') === $company, $label . ' föranmälan-bolag följer med');
        once_assert(once_of_type('ordrar.bestall') === [], $label . ' föranmälan skapar ingen shoporder');
        once_assert(trim((string) ($second['steps']['foranmald'] ?? '')) !== '', $label . ' föranmälan ligger kvar stämplad');
        once_assert(
            (string) ($second['steps']['foranmald'] ?? '') === (string) ($first['steps']['foranmald'] ?? ''),
            $label . ' andra Nästa byter inte föranmälningsdagen'
        );

        once_reset();
        $shop = once_put([
            'kind' => $kind,
            'company' => $company,
            'bestallning_status' => 'A',
        ]);
        $placed = jeab_ordrar_save_bestall([
            'id' => (int) $shop['reminder_id'],
            'intent' => 'bestall',
        ]);
        $again = jeab_ordrar_save_bestall([
            'id' => (int) $shop['reminder_id'],
            'intent' => 'bestall',
        ]);
        $orders = once_of_type('ordrar.bestall');
        once_assert(count($orders) === 1, $label . ' beställ Nästa postar en shoporder, fick ' . count($orders));
        once_assert(($orders[0]['utkast'] ?? null) === false, $label . ' shopordern är live');
        once_assert(($orders[0]['kind'] ?? '') === $kind, $label . ' shop-kind följer med');
        once_assert(($orders[0]['company'] ?? '') === $company, $label . ' shop-bolag följer med');
        once_assert(once_of_type('ordrar.foranmalan') === [], $label . ' beställ skapar ingen föranmälan');
        once_assert(jeab_ordrar_bestalld($again), $label . ' ärendet är fortfarande beställt');
        once_assert(
            (string) ($again['steps']['bestalld'] ?? '') === (string) ($placed['steps']['bestalld'] ?? ''),
            $label . ' andra Nästa byter inte beställningsdagen'
        );
    }
}

once_reset();
$spec = once_put(['kind' => 'sol', 'company' => 'jarfalla', 'foranmalan_status' => 'A']);
jeab_ordrar_save_batterisol([
    'id' => (int) $spec['reminder_id'],
    'intent' => 'next',
    'vaxelriktare' => 'Sigenergy 12 kW TP2',
    'kwh' => '12',
    'anlaggnings_id' => '735999114010135815',
    'natbolag' => 'E.ON',
    'foranmalan_status' => 'A',
]);
jeab_ordrar_save_batterisol([
    'id' => (int) $spec['reminder_id'],
    'intent' => 'next',
    'vaxelriktare' => 'Sigenergy 12 kW TP2',
    'kwh' => '12',
    'anlaggnings_id' => '735999114010135815',
    'natbolag' => 'E.ON',
    'foranmalan_status' => 'A',
]);
once_assert(count(once_of_type('ordrar.foranmalan')) === 1, 'spec-Nästa två gånger postar en föranmälan');

once_reset();
$specBatt = once_put(['kind' => 'batteri', 'company' => 'malmo', 'kwh' => '9', 'foranmalan_status' => 'A']);
jeab_ordrar_save_batterisol([
    'id' => (int) $specBatt['reminder_id'],
    'intent' => 'next',
    'vaxelriktare' => 'Sigenergy 12 kW TP2',
    'kwh' => '9',
    'anlaggnings_id' => '735999114010135815',
    'natbolag' => 'E.ON',
    'foranmalan_status' => 'A',
]);
jeab_ordrar_save_batterisol([
    'id' => (int) $specBatt['reminder_id'],
    'intent' => 'next',
    'vaxelriktare' => 'Sigenergy 12 kW TP2',
    'kwh' => '9',
    'anlaggnings_id' => '735999114010135815',
    'natbolag' => 'E.ON',
    'foranmalan_status' => 'A',
]);
once_assert(count(once_of_type('ordrar.foranmalan')) === 1, 'batteri Malmö spec-Nästa två gånger postar en föranmälan');

once_reset();
$five = once_put([
    'kind' => 'sol',
    'company' => 'jarfalla',
    'natbolag' => 'E.ON',
    'kwh' => '12',
    'huvudsokande_address' => 'Gymnastikvägen 29, 177 00 Järfälla',
]);
$cursor = $five;
for ($i = 0; $i < 5; $i++) {
    $cursor = jeab_ordrar_foranmalan_nasta(null, once_reload($cursor));
}
once_assert(count(once_of_type('ordrar.foranmalan')) === 1, 'fem Nästa på samma föranmälan postar en gång');
once_reset();
$fiveShop = once_put(['kind' => 'batteri', 'company' => 'malmo', 'kwh' => '9']);
for ($i = 0; $i < 5; $i++) {
    jeab_ordrar_save_bestall([
        'id' => (int) $fiveShop['reminder_id'],
        'intent' => 'bestall',
    ]);
}
once_assert(count(once_of_type('ordrar.bestall')) === 1, 'fem Nästa på samma beställning postar en shoporder');

foreach (['jarfalla', 'malmo'] as $company) {
    once_reset();
    $ladd = once_put([
        'kind' => 'laddbox',
        'company' => $company,
        'anlaggnings_id' => '',
        'kwh' => '',
        'inkop_lines' => [],
    ]);
    $nasta1 = jeab_ordrar_foranmalan_nasta(null, $ladd);
    $nasta2 = jeab_ordrar_foranmalan_nasta(null, once_reload($nasta1));
    once_assert(once_of_type('ordrar.foranmalan') === [], $company . ' laddbox föranmälan Nästa postar inte');
    once_assert(once_of_type('ordrar.bestall') === [], $company . ' laddbox föranmälan skapar ingen shoporder');
    once_assert(trim((string) ($nasta2['steps']['foranmald'] ?? '')) === '', $company . ' laddbox stämplas inte föranmäld');

    once_reset();
    $laddShop = once_put([
        'kind' => 'laddbox',
        'company' => $company,
        'inkop_lines' => [],
    ]);
    $shop1 = jeab_ordrar_save_bestall([
        'id' => (int) $laddShop['reminder_id'],
        'intent' => 'bestall',
    ]);
    $shop2 = jeab_ordrar_save_bestall([
        'id' => (int) $laddShop['reminder_id'],
        'intent' => 'bestall',
    ]);
    once_assert(once_of_type('ordrar.bestall') === [], $company . ' laddbox beställ Nästa postar ingen shoporder');
    once_assert(once_of_type('ordrar.foranmalan') === [], $company . ' laddbox beställ skapar ingen föranmälan');
    once_assert(!jeab_ordrar_bestalld($shop1) && !jeab_ordrar_bestalld($shop2), $company . ' laddbox blir inte beställd');
}

echo "nästa en gång: ok\n";
