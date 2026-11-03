<?php

declare(strict_types=1);

/**
 * Ordersidan: Status har ingen Nästa-knapp men en egen rubrik «Status», och
 * en order utan stop landar på Bekräfta (Status ligger ovanför). Uttryckligt
 * stop landar på sin panel, stop=status landar högst upp.
 */

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

jeab_ordrar_external_live(false);

function land_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function land_render(array $open, array $get): string
{
    $_GET = $get + ['p' => 'ordrar', 'id' => (string) ($open['reminder_id'] ?? 0)];
    $company = 'jarfalla';
    $flash = null;
    $error = null;
    $store = null;
    $reminder = null;
    return (string) (require dirname(__DIR__) . '/src/pages/ordrar.php');
}

/** @return array{status: string, landed: list<string>, visible: list<string>} */
function land_panes(string $html): array
{
    $out = ['status' => '', 'landed' => [], 'visible' => []];
    preg_match_all('/<div class="ordrar-step-pane" data-stop="([a-z_]+)"([^>]*)>/', $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($m as $i => $hit) {
        $stop = $hit[1][0];
        $attrs = $hit[2][0];
        if (!str_contains($attrs, 'hidden')) {
            $out['visible'][] = $stop;
        }
        if (str_contains($attrs, 'data-land="1"')) {
            $out['landed'][] = $stop;
        }
        if ($stop === 'status') {
            $start = $hit[0][1];
            $end = isset($m[$i + 1]) ? $m[$i + 1][0][1] : strlen($html);
            $out['status'] = substr($html, $start, $end - $start);
        }
    }
    return $out;
}

$order = [
    'reminder_id' => 4242,
    'kind' => 'sol',
    'company' => 'jarfalla',
    'name' => 'Test Testsson',
    'huvudsokande' => 'Test Testsson',
    'personnummer' => '198001011234',
    'fastighetsbeteckning' => 'TESTBY 1:1',
    'address' => 'Testvägen 19, 175 00 Järfälla',
    'natbolag' => 'Ellevio',
    'phone' => '+46700000000',
    'email' => 'test@example.com',
    'fortnox_order' => '9999',
    'steps' => [],
];

// Statuskortet själv: rubrik, ingen Nästa.
$card = jeab_ordrar_status_html($order, 'jarfalla', 4242, null);
land_assert(str_contains($card, '<h2>Status</h2>'), 'statuskortet har rubriken Status');
land_assert(strpos($card, '<h2>Status</h2>') < strpos($card, 'ordrar-status-top'), 'rubriken står först i statuskortet');
land_assert(!str_contains($card, 'Nästa'), 'statuskortet har ingen Nästa-knapp');
land_assert(!str_contains($card, 'data-js="ordrar-jump"'), 'statuskortet har ingen hoppknapp');
land_assert(!str_contains($card, 'ordrar-next-row'), 'statuskortet har ingen Nästa-rad');

// Landningshjälparen.
$stops = ['status', 'eon', 'bekrafta', 'fakturering'];
land_assert(jeab_ordrar_landing_pane('', 'status', $stops) === 'bekrafta', 'utan stop landar sidan på bekräfta');
land_assert(jeab_ordrar_landing_pane('status', 'status', $stops) === '', 'stop=status landar högst upp');
land_assert(jeab_ordrar_landing_pane('fakturering', 'fakturering', $stops) === 'fakturering', 'stop=fakturering landar på fakturering');
land_assert(jeab_ordrar_landing_pane('', 'status', ['status', 'eon']) === '', 'utan bekräfta-panel landar sidan högst upp');

// Hela sidan utan stop.
$html = land_render($order, []);
$panes = land_panes($html);
land_assert($panes['status'] !== '', 'statuspanelen ritas');
land_assert(str_contains($panes['status'], '<h2>Status</h2>'), 'statuspanelen har rubriken Status');
land_assert(!str_contains($panes['status'], '>Nästa<'), 'ingen Nästa i statuspanelen');
land_assert($panes['landed'] === ['bekrafta'], 'utan stop landar sidan på bekräfta, fick: ' . implode(',', $panes['landed']));
land_assert($panes['visible'] === ['status', 'bekrafta'], 'Status ovanför och Bekräfta synliga, fick: ' . implode(',', $panes['visible']));
land_assert(str_contains($html, 'data-land="bekrafta" data-land-default="bekrafta"'), 'panelerna bär landningsmålet');
land_assert((bool) preg_match('/<li class="ordrar-step[^"]*is-current[^"]*" data-key="bekrafta"/', $html), 'vänsterspalten markerar Bekräfta');
land_assert(str_contains($html, 'ordrar-step-pane[data-land="1"]') && str_contains($html, 'window.scrollTo(0, top)'), 'sidan scrollar till landningspanelen direkt');
$landAt = strpos($html, 'Landa på panelen direkt');
$landJs = $landAt !== false ? substr($html, $landAt, (int) strpos($html, '</script>', $landAt) - $landAt) : '';
land_assert($landJs !== '' && !str_contains($landJs, 'smooth'), 'landningen hoppar inte mjukt');
land_assert(str_contains($html, 'location.hash && location.hash.length > 1'), 'ankare i adressen vinner över landningen');
land_assert(str_contains($html, 'id="ordrar-confirm-go"'), 'Nästa på Bekräfta finns kvar');
$bekAt = strpos($html, 'data-stop="bekrafta"');
$statusAt = strpos($html, 'data-stop="status"');
land_assert($statusAt !== false && $bekAt !== false && $statusAt < $bekAt, 'Status ligger ovanför Bekräfta');

// Uttryckligt stop.
$html = land_render($order, ['stop' => 'status']);
$panes = land_panes($html);
land_assert($panes['landed'] === [], 'stop=status landar inte på någon panel');
land_assert($panes['visible'] === ['status'], 'stop=status visar bara Status');
land_assert(!str_contains($panes['status'], '>Nästa<'), 'ingen Nästa i statuspanelen med stop=status');

$html = land_render($order, ['stop' => 'fakturering']);
$panes = land_panes($html);
land_assert($panes['landed'] === ['fakturering'], 'stop=fakturering landar på fakturering, fick: ' . implode(',', $panes['landed']));
land_assert(in_array('status', $panes['visible'], true), 'Status ligger kvar ovanför vid stop=fakturering');

// Laddbox följer samma regel.
$box = ['kind' => 'laddbox'] + $order;
$panes = land_panes(land_render($box, []));
land_assert($panes['landed'] === ['bekrafta'], 'laddbox utan stop landar på bekräfta');

$_GET = [];
echo "OK  status utan Nästa, landar på Bekräfta\n";
