<?php

declare(strict_types=1);

/**
 * Solkoll visas och anropas bara för solceller.
 * Laddbox och batteri har ingen Solkoll-rad under Läge, och ingen HTTP.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

function sk_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sk_row(string $kind, string $stored = 'paborjad'): array
{
    return [
        'kind' => $kind,
        'company' => 'jarfalla',
        'name' => 'Ada Soltest',
        'huvudsokande' => 'Ada Soltest',
        'personnummer' => '198001011234',
        'fastighetsbeteckning' => 'TEST 1:1',
        'address' => 'Testgatan 1, 177 00 Järfälla',
        'solkoll_status' => $stored,
        'fortnox_order' => '',
        'steps' => [],
    ];
}

function sk_doc_label(string $html): string
{
    if (!preg_match('/ordrar-lage-kicker(?: is-as-written)?">(Dokumentation|DOKUMENTATION \(Solkoll\))<\/p>\s*<p class="ordrar-lage-label">([^<]*)</', $html, $m)) {
        return '';
    }
    return html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

jeab_ordrar_external_live(true);
$calls = [];
$apiStatus = 'COMPLETED';
$GLOBALS['_JEAB_SOLKOLL_HTTP'] = static function (string $method, string $path, ?array $body = null) use (&$calls, &$apiStatus): array {
    unset($body);
    $calls[] = strtoupper($method) . ' ' . $path;
    if (strtoupper($method) === 'GET' && str_starts_with($path, 'facilities')) {
        return [
            'Items' => [[
                'FacilityId' => 'fac-ada',
                'Name' => 'Ada Soltest',
                'FirstName' => 'Ada',
                'LastName' => 'Soltest',
                'FacilityStatus' => $apiStatus,
            ]],
            'NextCursor' => '',
        ];
    }
    return ['Items' => [], 'NextCursor' => ''];
};
unset($GLOBALS['_JEAB_SOLKOLL_FACILITIES']);

foreach (['laddbox', 'elbilsladdare'] as $kind) {
    $calls = [];
    $html = jeab_ordrar_status_html(sk_row($kind), 'jarfalla', 901, null);
    sk_assert(str_contains($html, 'ordrar-status-lage'), $kind . ' ritar Läge');
    sk_assert(!str_contains($html, 'Solkoll'), $kind . ' har ingen Solkoll-rad');
    sk_assert(!str_contains($html, 'ordrar-solkoll'), $kind . ' har inget solkoll-märke');
    sk_assert(!str_contains($html, 'Påbörjad'), $kind . ' visar inte Påbörjad');
    sk_assert(sk_doc_label($html) === '–', $kind . ' lämnar Dokumentation tom, fick: ' . sk_doc_label($html));
    sk_assert($calls === [], $kind . ' gör inget Solkoll-anrop');
    sk_assert(jeab_solkoll_status_html(sk_row($kind)) === '', $kind . ' status-html är tom');
    sk_assert((jeab_ordrar_besiktning_track(sk_row($kind))['label'] ?? 'x') === '', $kind . ' besiktning är tom');
    $before = sk_row($kind);
    $after = jeab_solkoll_sync_order($before);
    sk_assert($calls === [], $kind . ' sync anropar inte Solkoll');
    sk_assert(($after['solkoll_status'] ?? '') === 'paborjad', $kind . ' sync skriver inte om status');
}
$calls = [];
$batHtml = jeab_ordrar_status_html(sk_row('batteri'), 'jarfalla', 902, null);
sk_assert(str_contains($batHtml, 'ordrar-status-lage'), 'batteri ritar Läge');
sk_assert(!str_contains($batHtml, 'Solkoll') && !str_contains($batHtml, 'Dokumentation'), 'batteri visar inget dokumentationskort');
sk_assert(!str_contains($batHtml, 'ordrar-solkoll'), 'batteri har inget solkoll-märke');
sk_assert(!str_contains($batHtml, 'Påbörjad'), 'batteri visar inte Påbörjad');
sk_assert(sk_doc_label($batHtml) === '', 'batteri har inget dokumentationsläge, fick: ' . sk_doc_label($batHtml));
sk_assert($calls === [], 'batteri gör inget Solkoll-anrop');
sk_assert(jeab_solkoll_status_html(sk_row('batteri')) === '', 'batteri status-html är tom');
sk_assert((jeab_ordrar_besiktning_track(sk_row('batteri'))['label'] ?? 'x') === '', 'batteri besiktning är tom');
$batBefore = sk_row('batteri');
$batAfter = jeab_solkoll_sync_order($batBefore);
sk_assert($calls === [], 'batteri sync anropar inte Solkoll');
sk_assert(($batAfter['solkoll_status'] ?? '') === 'paborjad', 'batteri sync skriver inte om status');

$calls = [];
$apiStatus = 'COMPLETED';
$sol = sk_row('sol', 'paborjad');
$solHtml = jeab_ordrar_status_html($sol, 'jarfalla', 903, null);
sk_assert($calls !== [], 'sol läser FacilityStatus från API');
sk_assert(str_contains($solHtml, 'DOKUMENTATION (Solkoll)'), 'dokumentationskortet heter DOKUMENTATION (Solkoll)');
sk_assert(!str_contains($solHtml, 'ordrar-status-solkoll'), 'fristående Solkoll-rad är borta');
sk_assert(!str_contains($solHtml, 'ordrar-solkoll'), 'solkoll-märket ligger inte på status');
sk_assert(!str_contains($solHtml, 'ordrar-result-lbl">Solkoll'), 'solkoll-etiketten ligger inte ovanför läget');
sk_assert(str_contains($solHtml, 'Slutförd'), 'sol visar API-status Slutförd, inte lagrad Påbörjad');
sk_assert(!str_contains($solHtml, 'Påbörjad'), 'sol byter ut den lagrade etiketten mot API');
sk_assert(sk_doc_label($solHtml) === 'Slutförd', 'Läge Dokumentation följer API, fick: ' . sk_doc_label($solHtml));

$GLOBALS['_JEAB_SOLKOLL_FACILITIES'] = [[
    'FacilityId' => 'fac-ada',
    'Name' => 'Ada Soltest',
    'FirstName' => 'Ada',
    'LastName' => 'Soltest',
    'FacilityStatus' => 'IN_PROGRESS',
]];
$liveHtml = jeab_ordrar_status_html(sk_row('sol', 'ny'), 'jarfalla', 903, null);
sk_assert(str_contains($liveHtml, 'Påbörjad') && !str_contains($liveHtml, 'Ny'), 'ny API-status IN_PROGRESS blir Påbörjad');
sk_assert(sk_doc_label($liveHtml) === 'Påbörjad', 'Läge följer IN_PROGRESS');

$GLOBALS['_JEAB_SOLKOLL_FACILITIES'][0]['FacilityStatus'] = 'COMPLETED';
$doneHtml = jeab_ordrar_status_html(sk_row('sol', 'paborjad'), 'jarfalla', 903, null);
sk_assert(sk_doc_label($doneHtml) === 'Slutförd', 'samma order byter till Slutförd när API gör det');
sk_assert(str_contains($doneHtml, 'DOKUMENTATION (Solkoll)'), 'sol behåller dokumentationskortet');

$GLOBALS['_JEAB_SOLKOLL_FACILITIES'][0]['FacilityStatus'] = 'IN_PROGRESS';
foreach ([0, 1, 2, 3, 4] as $done) {
    $GLOBALS['_JEAB_SOLKOLL_FACILITIES'][0]['TodosCompleted'] = $done;
    $GLOBALS['_JEAB_SOLKOLL_FACILITIES'][0]['TotalTodos'] = 4;
    $stepHtml = jeab_ordrar_status_html(sk_row('sol', 'ny'), 'jarfalla', 903, null);
    sk_assert(sk_doc_label($stepHtml) === 'Påbörjad ' . $done . '/4', 'API-steg ' . $done . '/4, fick: ' . sk_doc_label($stepHtml));
    sk_assert(!str_contains($stepHtml, 'ordrar-status-solkoll'), 'steg ' . $done . '/4 har ingen fristående Solkoll-rad');
}
$GLOBALS['_JEAB_SOLKOLL_FACILITIES'][0]['FacilityStatus'] = 'COMPLETED';
$GLOBALS['_JEAB_SOLKOLL_FACILITIES'][0]['TodosCompleted'] = 4;
$fourHtml = jeab_ordrar_status_html(sk_row('sol', 'paborjad'), 'jarfalla', 903, null);
sk_assert(sk_doc_label($fourHtml) === 'Slutförd 4/4', 'slutförd behåller steget 4/4, fick: ' . sk_doc_label($fourHtml));
$storedOver = sk_row('sol', 'slutford');
$storedOver['solkoll_todos_done'] = 0;
$storedOver['solkoll_todos_total'] = 4;
$GLOBALS['_JEAB_SOLKOLL_FACILITIES'][0]['FacilityStatus'] = 'IN_PROGRESS';
$GLOBALS['_JEAB_SOLKOLL_FACILITIES'][0]['TodosCompleted'] = 2;
$overHtml = jeab_ordrar_status_html($storedOver, 'jarfalla', 903, null);
sk_assert(sk_doc_label($overHtml) === 'Påbörjad 2/4', 'live API-steg slår lagrat värde, fick: ' . sk_doc_label($overHtml));
$GLOBALS['_JEAB_SOLKOLL_FACILITIES'][0]['FacilityStatus'] = 'COMPLETED';

$synced = jeab_solkoll_sync_order(sk_row('sol', ''));
sk_assert(($synced['solkoll_status'] ?? '') === 'slutford', 'sync sparar API-status slutford, fick: ' . (string) ($synced['solkoll_status'] ?? ''));

$GLOBALS['_JEAB_SOLKOLL_FACILITIES'] = [];
$kept = jeab_solkoll_sync_order([
    'kind' => 'sol',
    'name' => 'Ada Soltest',
    'huvudsokande' => 'Ada Soltest',
    'solkoll_facility_id' => 'fac-missing',
    'solkoll_status' => '',
]);
sk_assert(($kept['solkoll_status'] ?? '') === '', 'saknad facility blir inte fejkad status ny');

jeab_ordrar_external_live(false);
$stored = jeab_ordrar_status_html(sk_row('laddbox', 'paborjad'), 'jarfalla', 901, null);
sk_assert(!str_contains($stored, 'Solkoll') && !str_contains($stored, 'Påbörjad'), 'laddbox döljer även sparad Påbörjad');
$storedSol = jeab_ordrar_status_html(sk_row('solceller', 'slutford'), 'jarfalla', 904, null);
sk_assert(str_contains($storedSol, 'DOKUMENTATION (Solkoll)') && sk_doc_label($storedSol) === 'Slutförd', 'solceller visar sparad API-status när live är av');
$storedSteps = sk_row('solceller', 'paborjad');
$storedSteps['solkoll_todos_done'] = 3;
$storedSteps['solkoll_todos_total'] = 4;
$storedStepHtml = jeab_ordrar_status_html($storedSteps, 'jarfalla', 905, null);
sk_assert(sk_doc_label($storedStepHtml) === 'Påbörjad 3/4', 'sparade API-steg visas när live är av, fick: ' . sk_doc_label($storedStepHtml));
sk_assert(!str_contains($storedStepHtml, 'ordrar-status-solkoll'), 'sparade steg har ingen fristående Solkoll-rad');
$boxSteps = sk_row('laddbox', 'paborjad');
$boxSteps['solkoll_todos_done'] = 3;
$boxSteps['solkoll_todos_total'] = 4;
$boxStepHtml = jeab_ordrar_status_html($boxSteps, 'jarfalla', 901, null);
sk_assert(sk_doc_label($boxStepHtml) === '–' && !str_contains($boxStepHtml, '3/4'), 'laddbox visar inte sparade steg');
$batSteps = sk_row('batteri', 'slutford');
$batSteps['solkoll_todos_done'] = 4;
$batSteps['solkoll_todos_total'] = 4;
$batStepHtml = jeab_ordrar_status_html($batSteps, 'jarfalla', 902, null);
sk_assert(!str_contains($batStepHtml, '4/4') && !str_contains($batStepHtml, 'Solkoll'), 'batteri visar inte steg');

echo "solkoll kind gate: ok\n";
