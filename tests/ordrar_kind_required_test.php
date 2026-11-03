<?php

declare(strict_types=1);

/**
 * Produktvalet Elbilsladdare/Batteri/Solpaneler är obligatoriskt:
 * required på varje radio, och servern avvisar spar utan produkt.
 */

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

jeab_ordrar_external_live(false);

function kind_req_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function kind_req_render(array $open): string
{
    $_GET = ['p' => 'ordrar', 'id' => (string) ($open['reminder_id'] ?? 0), 'stop' => 'bekrafta'];
    $company = 'jarfalla';
    $flash = null;
    $error = null;
    $store = null;
    $reminder = null;
    return (string) (require dirname(__DIR__) . '/src/pages/ordrar.php');
}

function kind_req_group(string $html): string
{
    $start = strpos($html, '<div class="chips ordrar-kind"');
    kind_req_assert($start !== false, 'produktgruppen finns i markupen');
    $end = strpos($html, '</div>', $start);
    kind_req_assert($end !== false, 'produktgruppen är stängd');
    return substr($html, $start, $end - $start);
}

/** @return list<string> */
function kind_req_radios(string $group): array
{
    preg_match_all('/<input type="radio" name="kind"[^>]*>/', $group, $m);
    return $m[0];
}

$base = [
    'reminder_id' => 4242,
    'company' => 'jarfalla',
    'name' => 'Test Testsson',
    'huvudsokande' => 'Test Testsson',
    'personnummer' => '198001011234',
    'fastighetsbeteckning' => 'TESTBY 1:1',
    'address' => 'Testvägen 19, 175 00 Järfälla',
    'natbolag' => 'Ellevio',
    'phone' => '+46700000000',
    'email' => 'test@example.com',
    'fortnox_order' => '',
    'steps' => [],
];

$emptyHtml = kind_req_render(['kind' => ''] + $base);
$emptyGroup = kind_req_group($emptyHtml);
kind_req_assert(str_contains($emptyGroup, 'aria-required="true"'), 'aria-required ligger kvar när ingen produkt är vald');
$emptyRadios = kind_req_radios($emptyGroup);
kind_req_assert(count($emptyRadios) === 3, 'tre produktradios när ingen är vald, fick ' . count($emptyRadios));
kind_req_assert(
    str_contains($emptyRadios[0], 'value="laddbox"') && preg_match('/\srequired(?:\s|>)/', $emptyRadios[0]) === 1,
    'Elbilsladdare har required när ingen produkt är vald'
);
kind_req_assert(preg_match('/\srequired(?:\s|>)/', $emptyRadios[1]) === 1 && preg_match('/\srequired(?:\s|>)/', $emptyRadios[2]) === 1, 'Batteri och Solpaneler har också required');
kind_req_assert(!str_contains($emptyGroup, ' checked'), 'ingen radio är förvald när produkt saknas');

$chosenHtml = kind_req_render(['kind' => 'batteri'] + $base);
$chosenGroup = kind_req_group($chosenHtml);
$chosenRadios = kind_req_radios($chosenGroup);
kind_req_assert(count($chosenRadios) === 3, 'tre produktradios när Batteri redan är valt');
kind_req_assert(str_contains($chosenRadios[1], 'value="batteri"') && str_contains($chosenRadios[1], ' checked'), 'vald produkt är ikryssad');
kind_req_assert(
    preg_match_all('/\srequired(?:\s|>)/', $chosenGroup) === 3,
    'required ligger kvar på alla tre när en produkt redan är vald'
);
kind_req_assert(str_contains($chosenGroup, 'aria-required="true"'), 'aria-required ligger kvar när en produkt är vald');

$_GET = [];
echo "ordrar kind required: ok\n";
