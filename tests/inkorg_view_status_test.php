<?php

declare(strict_types=1);

/** Vyer och typfilter: Öppen plus förfallen Parkerad. Stängda bara i Historik. Antal = lista. */

$tmp = sys_get_temp_dir() . '/jeab-inkst-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
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

function vs(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
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
            'subject' => $id,
            'from' => $id . '@exempel.test',
            'from_name' => 'Kund ' . $id,
            'body' => 'Hej ' . $id,
        ]],
    ];
};

$past = '2020-01-02';
$future = '2099-06-01';
$threads = [
    $case('sol-open', 'open', 'Ny lead', ['sol']),
    $case('sol-park-future', 'waiting_customer', 'Ny lead', ['sol'], ['park_until' => $future]),
    $case('sol-park-past', 'waiting_supplier', 'Ny lead', ['sol'], ['park_until' => $past]),
    $case('sol-closed', 'closed', 'Ny lead', ['sol']),
    $case('bat-open', 'open', 'Ny lead', ['batteri'], ['assignee' => 'Roberto']),
    $case('el-open', 'open', 'Ny lead', ['elbilsladdare'], ['assignee' => 'Elin']),
    $case('svc-open', 'open', 'Support', ['servicejobb']),
    $case('svc-closed', 'closed', 'Support', ['servicejobb']),
    $case('book-open', 'open', 'Bokning/ombokning', []),
    $case('info-open', 'open', 'Information', ['servicejobb', 'sol']),
    $case('info-closed', 'closed', 'Information', ['sol']),
    $case('plain-open', 'open', 'Ny lead', []),
    $case('plain-park-future', 'waiting_customer', 'Ny lead', [], ['park_until' => $future]),
    $case('closed-park', 'closed', 'Ny lead', ['sol'], ['park_until' => $future]),
    $case('deleted-open', 'open', 'Ny lead', ['sol'], ['deleted' => true]),
];

$storeImport = static function (string $n, string $status, string $type, array $tags): string {
    $tid = 'hsi-' . substr(hash('sha256', 'view-status-' . $n), 0, 16);
    $conv = [
        'id' => $tid,
        'company' => 'jarfalla',
        'status' => $status,
        'import_source' => 'helpscout_import',
        'messages' => [[
            'id' => substr(hash('sha256', 'm-' . $n), 0, 16),
            'from' => $n . '@exempel.test',
            'from_name' => 'Import ' . $n,
            'to' => 'kontakt@jarfalla.example.test',
            'subject' => 'Importerat ' . $n,
            'body' => 'Historik ' . $n,
            'received_at' => '2021-05-01T10:00:00+00:00',
            'source' => 'helpscout_import',
            'company' => 'jarfalla',
            'labels' => ['type' => $type, 'action' => 'arkivera', 'priority' => 4],
            'internal_note' => false,
            'attachments' => [],
        ]],
        'meta' => [
            'status' => $status,
            'tags' => $tags,
            'assignee' => '',
            'read' => [],
            'import_source' => 'helpscout_import',
        ],
    ];
    vs(jeab_hs_import_save_conv($conv) && jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'import ' . $n . ' skrivs');

    return $tid;
};

$importClosedSol = $storeImport('imp-sol', 'closed', 'Ny lead', ['sol']);
$importClosedSvc = $storeImport('imp-svc', 'closed', 'Support', ['servicejobb']);
$importClosedInfo = $storeImport('imp-info', 'closed', 'Information', ['servicejobb']);
$importOpenInfo = $storeImport('imp-open-info', 'open', 'Information', []);
$importClosedPark = (static function () use ($future): string {
    $n = 'imp-closed-park';
    $tid = 'hsi-' . substr(hash('sha256', 'view-status-' . $n), 0, 16);
    $conv = [
        'id' => $tid,
        'company' => 'jarfalla',
        'status' => 'waiting_supplier',
        'closed_at' => '2024-02-02T00:00:00+00:00',
        'import_source' => 'helpscout_import',
        'messages' => [[
            'id' => substr(hash('sha256', 'm-' . $n), 0, 16),
            'from' => $n . '@exempel.test',
            'from_name' => 'Import ' . $n,
            'to' => 'kontakt@jarfalla.example.test',
            'subject' => 'Importerat ' . $n,
            'body' => 'Historik ' . $n,
            'received_at' => '2021-05-01T10:00:00+00:00',
            'source' => 'helpscout_import',
            'company' => 'jarfalla',
            'labels' => ['type' => 'Ny lead', 'action' => 'arkivera', 'priority' => 4],
            'internal_note' => false,
            'attachments' => [],
        ]],
        'meta' => [
            'status' => 'waiting_supplier',
            'tags' => ['sol'],
            'assignee' => '',
            'read' => [],
            'import_source' => 'helpscout_import',
            'park_until' => $future,
            'closed_at' => '2024-02-02T00:00:00+00:00',
        ],
    ];
    vs(jeab_hs_import_save_conv($conv) && jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'import ' . $n . ' skrivs');

    return $tid;
})();

$views = array_column(jeab_inkorg_views(), null, 'id');
$wantViews = [
    'v-solceller' => 'Sam L',
    'v-batteri-oppna' => 'Roberto',
    'v-elbilsladdare' => 'Elbilsladdare',
    'v-elbil-osorterat' => 'Elin',
    'v-servicekund' => 'Service',
    'v-leads' => 'Leads – öppna',
    'v-osorterat' => 'Osorterat',
    'v-information' => 'Information',
    'v-vantar' => 'Parkerat',
    'v-historik' => 'Historik',
];
foreach ($wantViews as $id => $name) {
    vs(isset($views[$id]) && ($views[$id]['name'] ?? '') === $name, 'vyn ' . $name . ' finns');
}

$filters = [];
foreach ($views as $id => $view) {
    $filters['vy ' . $view['name']] = (array) $view['filter'] + ['company' => 'jarfalla'];
}
foreach (jeab_inkorg_type_list() as $type) {
    $filters['typ ' . $type] = ['company' => 'jarfalla', 'type' => $type];
    $svcAnd = (array) $views['v-servicekund']['filter'];
    $svcAnd['company'] = 'jarfalla';
    $svcAnd['type'] = $type;
    $filters['vy Service + typ ' . $type] = $svcAnd;
    $showAnd = ['company' => 'jarfalla', 'show' => [['tag', 'servicejobb'], ['type', 'Support']], 'type' => $type];
    $filters['kryss Service + typ ' . $type] = $showAnd;
}
foreach (jeab_inkorg_view_choices() as $choice) {
    $filters['kryss ' . $choice['label']] = ['company' => 'jarfalla', 'show' => [[$choice['kind'], $choice['value']]]];
}
$filters['kryss tomt'] = ['company' => 'jarfalla', 'show' => []];
$filters['kryss Servicejobb + Support'] = ['company' => 'jarfalla', 'show' => [['tag', 'servicejobb'], ['type', 'Support']]];

$closedIds = ['sol-closed', 'svc-closed', 'info-closed', 'closed-park', $importClosedSol, $importClosedSvc, $importClosedInfo, $importClosedPark];
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
    vs($total === (int) $list['total'] && count($ids) === $total, $label . ' antal ' . $total . ' = lista ' . (int) $list['total']);
    $closedHit = array_values(array_intersect($closedIds, $ids));
    if (!empty($filter['historik']) && !jeab_inkorg_filter_has_kind($filter)) {
        vs($closedHit !== [], $label . ' visar stängda');
        continue;
    }
    vs($closedHit === [], $label . ' har inga stängda' . ($closedHit !== [] ? ' (' . implode(', ', $closedHit) . ')' : ''));
}

$sol = (array) $views['v-solceller']['filter'] + ['company' => 'jarfalla'];
$park = (array) $views['v-vantar']['filter'] + ['company' => 'jarfalla'];
$info = (array) $views['v-information']['filter'] + ['company' => 'jarfalla'];
$svc = (array) $views['v-servicekund']['filter'] + ['company' => 'jarfalla'];
$hist = (array) $views['v-historik']['filter'] + ['company' => 'jarfalla'];
$byId = static function (array $filter) use ($threads, $idsOf): array {
    return $idsOf(jeab_inkorg_view_list($threads, $filter, 1, 500)['rows']);
};

vs(in_array('sol-open', $byId($sol), true) && in_array('sol-park-past', $byId($sol), true), 'Sol visar öppen och förfallen Parkerad');
vs(!in_array('sol-park-future', $byId($sol), true) && in_array('sol-park-future', $byId($park), true), 'framtida Parkerad ligger bara i Parkerat');
vs(!in_array('sol-park-past', $byId($park), true), 'förfallen Parkerad har lämnat Parkerat');
vs(!in_array('closed-park', $byId($park), true) && !in_array($importClosedPark, $byId($park), true), 'stängt ärende med parkering ligger inte i Parkerat');
vs(jeab_inkorg_view_total($threads, $park) === count($byId($park)), 'Parkerats antal är samma som listan');
vs(in_array('info-open', $byId($info), true) && !in_array('info-open', $byId($sol), true), 'Information med produktetikett ligger i Information, inte i Sol');
vs(!in_array('info-open', $byId(['company' => 'jarfalla', 'show' => [['tag', 'servicejobb']]]), true), 'Information syns inte när krysset är Servicejobb');
vs(in_array('svc-open', $byId($svc), true) && in_array('book-open', $byId($svc), true) && !in_array('svc-closed', $byId($svc), true), 'Service är öppna Support och Bokning');
vs(in_array($importClosedSvc, $byId($hist), true) && in_array('svc-closed', $byId($hist), true) && in_array('deleted-open', $byId($hist), true), 'Historik har importerat stängd, egen stängd och raderad');
vs(in_array($importOpenInfo, $byId($hist), true), 'öppen import ligger kvar i Historik');
vs(!in_array('plain-open', $byId($hist), true), 'öppet eget ärende ligger inte i Historik');

$supportOnly = ['company' => 'jarfalla', 'type' => 'Support'];
vs(in_array('svc-open', $byId($supportOnly), true) && !in_array('svc-closed', $byId($supportOnly), true) && !in_array($importClosedSvc, $byId($supportOnly), true), 'typfiltret Support tar inte med stängda');

$finished = jeab_inkorg_finish_labels([
    'type' => 'Information',
    'product' => 'solceller',
    'add_tags' => ['servicejobb', 'sol', 'test'],
    'confidence' => 90,
    'action' => 'arkivera',
    'priority' => 4,
], ['from' => 'order@exempel.test', 'subject' => 'Orderbekräftelse', 'body' => 'I lager']);
vs(!array_intersect(['sol', 'batteri', 'elbilsladdare', 'servicejobb'], (array) ($finished['add_tags'] ?? [])) && in_array('test', (array) $finished['add_tags'], true), 'Information får ingen produktetikett');
