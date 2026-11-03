<?php

declare(strict_types=1);

/** Öppna vyer, 20 osäkra importer, diagnos som inte skriver, återställning av diagnosmarkörer. */

$tmp = sys_get_temp_dir() . '/jeab-incap-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'people' => [
        ['id' => 'SamL', 'email' => 'sam.exempel@personal.test', 'name' => 'Sam L', 'company' => 'jarfalla'],
        ['id' => 'Roberto', 'email' => 'roberto.exempel@personal.test', 'name' => 'Roberto', 'company' => 'jarfalla'],
        ['id' => 'Elin', 'email' => 'elin.exempel@personal.test', 'name' => 'Elin', 'company' => 'jarfalla'],
    ],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function cap_ok(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function cap_store(string $n, string $company, string $status, string $received, string $importAt, string $type = 'Support', array $extraMeta = []): string
{
    $tid = 'hsi-' . substr(hash('sha256', 'open-cap-' . $n), 0, 16);
    $meta = $extraMeta + [
        'status' => $status,
        'tags' => [],
        'assignee' => '',
        'read' => [],
        'import_source' => 'helpscout_import',
        'updated_at' => $importAt,
        'created_at' => $importAt,
    ];
    $conv = [
        'id' => $tid,
        'company' => $company,
        'status' => $status,
        'import_source' => 'helpscout_import',
        'messages' => [[
            'id' => substr(hash('sha256', 'm-' . $n), 0, 16),
            'from' => 'kund.' . $n . '@exempel.test',
            'from_name' => 'Kund',
            'to' => $company === 'malmo' ? 'kontakt@malmo.example.test' : 'kontakt@jarfalla.example.test',
            'subject' => 'Arende ' . $n,
            'body' => 'Hej',
            'received_at' => $received,
            'source' => 'helpscout_import',
            'company' => $company,
            'labels' => ['type' => $type, 'action' => 'arkivera', 'priority' => 4],
            'internal_note' => false,
            'attachments' => [],
        ]],
        'meta' => $meta,
    ];
    cap_ok(jeab_hs_import_save_conv($conv) && jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'import ' . $n . ' skrivs');

    return $tid;
}

$importAt = '2026-10-18T12:00:00+00:00';
$newestImport = '2026-10-19T23:00:00+00:00';
$jarfallaOpen = [];
for ($i = 0; $i < 25; $i++) {
    $day = (new DateTimeImmutable('2024-01-01T10:00:00+00:00'))->modify('+' . $i . ' days')->format('c');
    $stamp = $i === 0 ? $newestImport : $importAt;
    $jarfallaOpen[$i] = cap_store(sprintf('jf-%02d', $i), 'jarfalla', 'open', $day, $stamp);
}
$jarfallaClosed = cap_store('jf-closed', 'jarfalla', 'closed', '2026-03-01T10:00:00+00:00', $importAt);
$jarfallaMarked = cap_store('jf-marked', 'jarfalla', 'open', '2026-04-01T10:00:00+00:00', $importAt, 'Support', ['closed_at' => '2024-06-01T00:00:00+00:00']);
$jarfallaSolClosed = cap_store('jf-sol-closed', 'jarfalla', 'closed', '2025-01-01T10:00:00+00:00', $importAt, 'Ny lead');
$malmoOpen = [];
for ($i = 0; $i < 25; $i++) {
    $day = (new DateTimeImmutable('2023-01-01T10:00:00+00:00'))->modify('+' . $i . ' days')->format('c');
    $malmoOpen[$i] = cap_store(sprintf('mo-%02d', $i), 'malmo', 'open', $day, $importAt);
}

$case = static function (string $id, string $status, string $type, array $tags, array $extra = []): array {
    return $extra + [
        'id' => $id,
        'status' => $status,
        'deleted' => false,
        'labels' => ['type' => $type],
        'tags' => $tags,
        'company' => 'jarfalla',
        'brand' => 'jarfalla',
        'assignee' => '',
        'import_source' => '',
        'source' => 'test-unit',
        'messages' => [[
            'subject' => 'Eget ' . $id,
            'from' => $id . '@exempel.test',
            'from_name' => 'Kund',
            'body' => 'Hej',
        ]],
    ];
};
$threads = [
    $case('sol-open', 'open', 'Ny lead', ['sol']),
    $case('sol-park-future', 'waiting_customer', 'Ny lead', ['sol'], ['park_until' => '2099-06-01']),
    $case('sol-park-past', 'waiting_supplier', 'Ny lead', ['sol'], ['park_until' => '2020-01-02']),
    $case('svc-open', 'open', 'Support', ['servicejobb']),
    $case('svc-closed', 'closed', 'Support', ['servicejobb']),
];

$keptJarfalla = array_slice($jarfallaOpen, 5);
$droppedJarfalla = array_slice($jarfallaOpen, 0, 5);
$keptMalmo = array_slice($malmoOpen, 5);
$droppedMalmo = array_slice($malmoOpen, 0, 5);
$closedIds = array_merge($droppedJarfalla, $droppedMalmo, [$jarfallaClosed, $jarfallaMarked, $jarfallaSolClosed, 'svc-closed']);

$views = array_column(jeab_inkorg_views(), null, 'id');
$filters = [];
foreach ($views as $view) {
    $filters['vy ' . $view['name']] = (array) $view['filter'] + ['company' => 'jarfalla'];
}
foreach (jeab_inkorg_type_list() as $type) {
    $filters['typ ' . $type] = ['company' => 'jarfalla', 'type' => $type];
}
foreach (jeab_inkorg_view_choices() as $choice) {
    $filters['kryss ' . $choice['label']] = ['company' => 'jarfalla', 'show' => [[$choice['kind'], $choice['value']]]];
}
$filters['kryss tomt'] = ['company' => 'jarfalla', 'show' => []];
$filters['malmo service'] = (array) $views['v-servicekund']['filter'] + ['company' => 'malmo'];

$idsOf = static function (array $rows): array {
    $ids = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $ids[] = (string) ($row['id'] ?? '');
        }
    }

    return $ids;
};

foreach ($filters as $label => $filter) {
    $total = jeab_inkorg_view_total($threads, $filter);
    $list = jeab_inkorg_view_list($threads, $filter, 1, 500);
    $ids = $idsOf($list['rows']);
    cap_ok($total === (int) $list['total'] && count($ids) === $total, $label . ' antal ' . $total . ' = lista');
    $closedHit = array_values(array_intersect($closedIds, $ids));
    if (!empty($filter['historik']) && !jeab_inkorg_filter_has_kind($filter)) {
        cap_ok($closedHit !== [], $label . ' visar stängda');
        continue;
    }
    cap_ok($closedHit === [], $label . ' har inga stängda' . ($closedHit !== [] ? ' (' . implode(', ', $closedHit) . ')' : ''));
}

$svc = (array) $views['v-servicekund']['filter'] + ['company' => 'jarfalla'];
$svcIds = $idsOf(jeab_inkorg_view_list($threads, $svc, 1, 500)['rows']);
sort($svcIds);
$expectSvc = array_merge($keptJarfalla, ['svc-open']);
sort($expectSvc);
cap_ok($svcIds === $expectSvc, 'Service är de 20 senaste osäkra plus det egna öppna');
cap_ok(!in_array($jarfallaOpen[0], $svcIds, true), 'äldsta mejlet med nyast importdatum räknas inte som öppet');

$malmoSvc = (array) $views['v-servicekund']['filter'] + ['company' => 'malmo'];
$malmoIds = $idsOf(jeab_inkorg_view_list($threads, $malmoSvc, 1, 500)['rows']);
sort($malmoIds);
$expectMalmo = $keptMalmo;
sort($expectMalmo);
cap_ok($malmoIds === $expectMalmo, 'Malmö har egna 20 senaste');

$hist = (array) $views['v-historik']['filter'] + ['company' => 'jarfalla'];
$histIds = $idsOf(jeab_inkorg_view_list($threads, $hist, 1, 500)['rows']);
cap_ok(in_array($jarfallaOpen[0], $histIds, true) && in_array($jarfallaMarked, $histIds, true) && in_array($jarfallaClosed, $histIds, true), 'överskott och stängda ligger i Historik');
cap_ok(!in_array('svc-open', $histIds, true) && !in_array('sol-open', $histIds, true), 'öppna egna ärenden ligger inte i Historik');

$sol = (array) $views['v-solceller']['filter'] + ['company' => 'jarfalla'];
$solIds = $idsOf(jeab_inkorg_view_list($threads, $sol, 1, 500)['rows']);
cap_ok(in_array('sol-open', $solIds, true) && in_array('sol-park-past', $solIds, true) && !in_array('sol-park-future', $solIds, true) && !in_array($jarfallaSolClosed, $solIds, true), 'Sam L visar öppen och förfallen Parkerad');

$openForClose = cap_store('jf-stang', 'jarfalla', 'open', '2024-08-01T10:00:00+00:00', $importAt);
$stangErr = null;
try {
    jeab_inkorg_thread_set($openForClose, 'closed', null);
} catch (Throwable $e) {
    $stangErr = $e;
}
cap_ok($stangErr === null && (string) (jeab_hs_import_index()['by_id'][$openForClose]['status'] ?? '') === 'closed', 'Stäng skriver stängd på importerat Järfälla');
$afterClose = $idsOf(jeab_inkorg_view_list($threads, $svc, 1, 500)['rows']);
cap_ok(!in_array($openForClose, $afterClose, true), 'stängt Järfälla-ärende lämnar Service');

$malmoLock = cap_store('mo-lock', 'malmo', 'open', '2024-08-02T10:00:00+00:00', $importAt);
$malmoErr = null;
try {
    jeab_inkorg_thread_set($malmoLock, 'closed', null);
} catch (Throwable $e) {
    $malmoErr = $e;
}
cap_ok($malmoErr instanceof JeabInkorgWriteException && jeab_inkorg_error_status($malmoErr) === 403, 'Malmö-import ger 403');
cap_ok((string) (jeab_hs_import_index()['by_id'][$malmoLock]['status'] ?? '') === 'open', 'nekad Malmö-stängning skriver inte');

require_once dirname(__DIR__) . '/src/layout.php';
$_GET = ['p' => 'inkorg', 'view' => 'v-servicekund', 'box' => 'jarfalla', 'id' => $jarfallaOpen[24]];
$jfHtml = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
cap_ok(str_contains($jfHtml, '>Stängd</span>'), 'Service i Järfälla har Stängd');
$_GET = ['p' => 'inkorg', 'view' => 'v-servicekund', 'box' => 'malmo', 'id' => $malmoOpen[24]];
$moHtml = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
cap_ok(!str_contains($moHtml, '>Stäng<') && str_contains($moHtml, 'Sol hanteras i Help Scout.'), 'Malmö har ingen Stäng-knapp');

$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn(): array => ['code' => 404, 'body' => []];
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static fn(): array => ['code' => 200, 'body' => json_encode(['_embedded' => ['conversations' => []], 'page' => ['totalPages' => 1]])];
$diagIn = jeab_inkorg_ingest([
    'message_id' => '<diag-cap-1@exempel.test>',
    'from' => 'kund.diag@exempel.test',
    'from_name' => 'Kund',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Fraga om batteri',
    'body_text' => 'Hej, jag vill ha ett batteri.',
    'source' => 'test-unit',
    'company' => 'jarfalla',
    'preset_labels' => ['type' => 'Ny lead', 'product' => 'batteri', 'offer' => 'batteri'],
]);
cap_ok(!empty($diagIn['ok']), 'diagnosärendet finns');
$diagTid = (string) $diagIn['thread_id'];
jeab_inkorg_add_draft($diagTid, ['kind' => 'note', 'body' => 'Offert (Batteri) skickad'], 'Test');
$before = (string) file_get_contents(jeab_inkorg_dir() . '/threads.json');
$diag = jeab_inkorg_hook(['action' => 'inbox.quote_sent_diag', 'thread_id' => $diagTid]);
$after = (string) file_get_contents(jeab_inkorg_dir() . '/threads.json');
$diagMeta = jeab_inkorg_thread_meta($diagTid);
cap_ok(($diag['ok'] ?? false) === true && !isset($diagMeta['quote_sent']) && $before === $after && !is_file(jeab_data_dir() . '/graph-sent-scan.json'), 'diagnosen skriver varken markör eller cache');

$markOn = static function (string $key, string $subject, array $mark): string {
    $in = jeab_inkorg_ingest([
        'message_id' => '<' . $key . '@exempel.test>',
        'from' => 'kund.' . $key . '@exempel.test',
        'from_name' => 'Kund',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => $subject,
        'body_text' => 'Hej',
        'source' => 'test-unit',
        'company' => 'jarfalla',
        'preset_labels' => ['type' => 'Ny lead'],
    ]);
    $tid = (string) $in['thread_id'];
    jeab_inkorg_thread_write($tid, static function (array $t) use ($mark): array {
        $t['quote_sent'] = $mark;

        return $t;
    });

    return $tid;
};
$diagA = $markOn('diag-a', 'Offertfraga ett', [
    'source' => 'Skickat',
    'date' => '2026-09-01 10:00',
    'at' => '2026-10-19T02:05:30+02:00',
    'cached' => true,
]);
$diagEdgeStart = $markOn('diag-b', 'Offertfraga tva', [
    'source' => 'Help Scout',
    'date' => '2026-09-02 10:00',
    'at' => '2026-10-19T02:04:00+02:00',
    'cached' => true,
]);
$diagEdgeEnd = $markOn('diag-c', 'Offertfraga tre', [
    'source' => 'Skickat',
    'date' => '2026-09-03 10:00',
    'at' => '2026-10-19T02:07:59+02:00',
    'cached' => true,
]);
$realSend = $markOn('real-send', 'Verkligt utskick', [
    'source' => 'anteckning',
    'date' => '2026-10-19 02:06',
    'at' => '2026-10-19T02:06:10+02:00',
    'product' => 'batteri',
]);
$tooEarly = $markOn('too-early', 'For tidig markor', [
    'source' => 'Skickat',
    'date' => '2026-09-04 10:00',
    'at' => '2026-10-19T02:03:59+02:00',
    'cached' => true,
]);
$tooLate = $markOn('too-late', 'For sen markor', [
    'source' => 'Skickat',
    'date' => '2026-09-05 10:00',
    'at' => '2026-10-19T02:08:00+02:00',
    'cached' => true,
]);
$noCached = $markOn('no-cached', 'Skickat utan cached', [
    'source' => 'Skickat',
    'date' => '2026-09-06 10:00',
    'at' => '2026-10-19T02:05:00+02:00',
]);
$noSource = $markOn('no-source', 'Markor utan kalla', [
    'cached' => true,
    'date' => '2026-09-07 10:00',
    'at' => '2026-10-19T02:05:00+02:00',
]);
$noAt = $markOn('no-at', 'Markor utan tid', [
    'source' => 'Skickat',
    'date' => '2026-09-08 10:00',
    'cached' => true,
]);
$noon = $markOn('noon', 'Mitt pa dagen', [
    'source' => 'Skickat',
    'date' => '2026-09-09 10:00',
    'at' => '2026-10-19T12:00:00+02:00',
    'cached' => true,
]);
$closeShape = $markOn('close-shape', 'Stangningens markor', [
    'source' => 'Skickat',
    'date' => '',
    'at' => '2026-10-19T02:06:00+02:00',
]);
$keptQuote = $markOn('has-quote', 'Har utgaende offert', [
    'source' => 'Skickat',
    'date' => '2026-09-10 10:00',
    'at' => '2026-10-19T02:10:00+02:00',
    'cached' => true,
]);
jeab_inkorg_add_draft($keptQuote, ['kind' => 'note', 'body' => 'Offert (Batteri) skickad'], 'Test');

$dry = jeab_inkorg_hook(['action' => 'inbox.quote_marker_revert']);
$dryJson = (string) json_encode($dry, JSON_UNESCAPED_UNICODE);
$certainIds = array_map(static fn(array $row): string => (string) ($row['id'] ?? ''), (array) ($dry['certain'] ?? []));
sort($certainIds);
$expectCertain = [$diagA, $diagEdgeStart, $diagEdgeEnd, $tooEarly, $tooLate, $noCached];
sort($expectCertain);
cap_ok(($dry['dry'] ?? 0) === 1 && ($dry['removed'] ?? 1) === 0 && $certainIds === $expectCertain, 'torrläge listar diagnosens form i körningen');
cap_ok(!str_contains($dryJson, '@') && !str_contains($dryJson, 'exempel.test'), 'svaret har varken adress eller mejl');
$subjects = array_column((array) ($dry['certain'] ?? []), 'subject');
cap_ok(in_array('Offertfraga ett', $subjects, true) && in_array('Offertfraga tva', $subjects, true), 'listan har ämne');
$reasons = array_column((array) ($dry['certain'] ?? []), 'reason');
cap_ok($reasons !== [] && !in_array('', $reasons, true), 'varje säker rad har orsak');
$uncertainIds = array_map(static fn(array $row): string => (string) ($row['id'] ?? ''), (array) ($dry['uncertain'] ?? []));
cap_ok(in_array($realSend, $uncertainIds, true) && in_array($noSource, $uncertainIds, true) && in_array($noAt, $uncertainIds, true) && in_array($closeShape, $uncertainIds, true) && in_array($keptQuote, $uncertainIds, true), 'osäkra markörer listas och lämnas');
cap_ok(!in_array($noon, $certainIds, true) && !in_array($noon, $uncertainIds, true) && !in_array($noCached, $uncertainIds, true), 'markörer utanför körningen rörs inte');
cap_ok(isset(jeab_inkorg_thread_meta($diagA)['quote_sent']), 'torrläge skriver inte');

$live = jeab_inkorg_hook(['action' => 'inbox.quote_marker_revert', 'dry' => 0]);
cap_ok(($live['dry'] ?? 1) === 0 && ($live['removed'] ?? 0) === 6, 'sex diagnosmarkörer tas bort');
cap_ok(!isset(jeab_inkorg_thread_meta($diagA)['quote_sent']) && !isset(jeab_inkorg_thread_meta($diagEdgeEnd)['quote_sent']) && !isset(jeab_inkorg_thread_meta($noCached)['quote_sent']) && !isset(jeab_inkorg_thread_meta($tooEarly)['quote_sent']), 'körningens markörer är borta');
cap_ok(isset(jeab_inkorg_thread_meta($realSend)['quote_sent']) && isset(jeab_inkorg_thread_meta($noon)['quote_sent']) && isset(jeab_inkorg_thread_meta($closeShape)['quote_sent']) && isset(jeab_inkorg_thread_meta($keptQuote)['quote_sent']) && isset(jeab_inkorg_thread_meta($noSource)['quote_sent']) && isset(jeab_inkorg_thread_meta($noAt)['quote_sent']), 'övriga markörer är kvar');
$again = jeab_inkorg_hook(['action' => 'inbox.quote_marker_revert', 'dry' => 0]);
cap_ok(($again['removed'] ?? 1) === 0 && (array) ($again['certain'] ?? []) === [], 'andra körningen ändrar inget');

echo "inkorg open cap: ok\n";
