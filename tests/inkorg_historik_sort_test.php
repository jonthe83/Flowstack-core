<?php

declare(strict_types=1);

/** Historik är en sortering. Typchip på Historik räknar samma rader som listan. Fejkdata. */

$tmp = sys_get_temp_dir() . '/jeab-histsort-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function hsrt(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$shared = 'hsi-aaaaaaaaaaaaaaaa';
$ingest = static function (string $id, string $thread, string $type, string $at, string $subject): array {
    $in = jeab_inkorg_ingest([
        'message_id' => '<' . $id . '@example.test>',
        'from' => 'exempel.kund@example.test',
        'from_name' => 'Exempel Kund',
        'to' => 'info@jarfalla.example.test',
        'subject' => $subject,
        'body_text' => 'Hej, det här är ett fejkärende.',
        'source' => 'test-hsrt',
        'test' => true,
        'company' => 'jarfalla',
        'thread_key' => $thread,
        'received_at' => $at,
        'preset_labels' => ['type' => $type],
    ]);
    hsrt(!empty($in['ok']), $id . ' tas in');

    return $in;
};

$ingest('lead-1', 'live-lead', 'Ny lead', '2026-10-19T06:49:00+02:00', 'Ny lead: fejk');
$ingest('lead-2', 'live-lead', '', '2026-10-19T07:10:00+02:00', 'Ny lead: fejk');
jeab_inkorg_thread_write('live-lead', static function (array $t): array {
    $t['status'] = 'closed';
    $t['closed_at'] = '2026-10-18T08:00:00+02:00';

    return $t;
});

$ingest('open-support', 'live-open-support', 'Support', '2026-10-10T09:00:00+02:00', 'Supportfråga');
$ingest('open-support-2', 'live-open-support', '', '2026-10-11T09:00:00+02:00', 'Supportfråga');

$ingest('trash-1', $shared, 'Support', '2026-10-01T09:00:00+02:00', 'Support i korg');
jeab_inkorg_thread_write($shared, static function (array $t): array {
    $t['deleted_at'] = '2026-10-19T12:00:00+02:00';
    $t['status'] = 'closed';

    return $t;
});

$import = static function (string $cid, string $typeOnFirst, string $closed, string $mail, string $subject, bool $deleted = false): string {
    $id = jeab_hs_import_thread_key($cid);
    $conv = [
        'id' => $id,
        'company' => 'jarfalla',
        'status' => 'closed',
        'import_source' => 'helpscout_import',
        'closed_at' => $closed,
        'messages' => [
            [
                'id' => substr(hash('sha256', $cid . 'a'), 0, 16),
                'from' => 'exempel.kund@example.test',
                'from_name' => 'Exempel Kund',
                'subject' => $subject,
                'body' => 'Hej',
                'received_at' => $mail,
                'labels' => ['type' => $typeOnFirst],
                'source' => 'helpscout_import',
            ],
            [
                'id' => substr(hash('sha256', $cid . 'b'), 0, 16),
                'from' => 'personal@example.test',
                'from_name' => 'Personal',
                'subject' => $subject,
                'body' => 'Svar',
                'received_at' => '2026-10-19T08:00:00+00:00',
                'labels' => ['type' => ''],
                'source' => 'helpscout_import',
            ],
        ],
        'meta' => [
            'status' => 'closed',
            'closed_at' => $closed,
            'updated_at' => '2026-10-19T08:00:00+00:00',
            'deleted_at' => $deleted ? '2026-10-19T08:00:00+00:00' : '',
            'import_source' => 'helpscout_import',
        ],
    ];
    hsrt(jeab_hs_import_save_conv($conv) && jeab_hs_import_index_append(jeab_hs_import_index_row($conv)), 'import ' . $cid);

    return $id;
};

$importLead = $import('200', 'Ny lead', '2026-10-19T04:10:00+02:00', '2026-09-01T10:00:00+02:00', 'Nytt lead (HemSol)');
$importSupport = $import('300', 'Support', '2026-10-15T11:00:00+02:00', '2026-08-01T10:00:00+02:00', 'Supportärende');
$importInfo = $import('400', 'Information', '2026-09-29T11:00:00+02:00', '2026-09-01T10:00:00+02:00', 'Kvitto');
$storedDup = [
    'id' => $shared,
    'company' => 'jarfalla',
    'status' => 'closed',
    'import_source' => 'helpscout_import',
    'messages' => [[
        'id' => substr(hash('sha256', 'dupmsg'), 0, 16),
        'from' => 'exempel.kund@example.test',
        'subject' => 'Dubblett',
        'body' => 'Hej',
        'received_at' => '2020-01-01T00:00:00+00:00',
        'labels' => ['type' => 'Ny lead'],
    ]],
    'meta' => [
        'status' => 'closed',
        'closed_at' => '2020-01-01T00:00:00+00:00',
        'updated_at' => '2026-10-19T08:00:00+00:00',
        'import_source' => 'helpscout_import',
    ],
];
hsrt(jeab_hs_import_save_conv($storedDup) && jeab_hs_import_index_append(jeab_hs_import_index_row($storedDup)), 'samma id finns i importindexet');

$threads = jeab_inkorg_threads();
$byId = array_column($threads, null, 'id');
hsrt((string) ($byId['live-lead']['labels']['type'] ?? '') === 'Ny lead', 'typ hämtas från mejlet som har typ, inte det tomma svaret');
hsrt((string) ($byId['live-open-support']['labels']['type'] ?? '') === 'Support', 'öppen Support behåller typen');
hsrt(str_starts_with((string) ($byId['live-lead']['updated_at'] ?? ''), '2026-10-18'), 'stängt ärende sorteras på stängningsdatum');
hsrt(str_starts_with((string) ($byId[$shared]['updated_at'] ?? ''), '2026-10-19T12:'), 'raderat ärende sorteras på raderingsdatum');
hsrt(str_starts_with((string) (jeab_hs_import_index()['by_id'][$importInfo]['updated_at'] ?? ''), '2026-09-29'), 'importtid används inte som listdatum');
hsrt((string) (jeab_hs_import_index()['by_id'][$importLead]['labels']['type'] ?? '') === 'Ny lead', 'importerad typ sitter kvar när sista mejlet saknar typ');

$hist = ['historik' => true, 'company' => 'jarfalla'];
$all = jeab_inkorg_view_list($threads, $hist, 1, 20);
$ids = array_map(static fn(array $row): string => (string) ($row['id'] ?? ''), $all['rows']);
$expect = [$shared, $importLead, 'live-lead', $importSupport, $importInfo];
hsrt($ids === $expect, 'Historik blandar källorna nyast först: ' . implode(', ', $ids));
hsrt(count($ids) === count(array_unique($ids)), 'samma id räknas en gång');
hsrt((int) $all['total'] === 5 && jeab_inkorg_view_total($threads, $hist) === 5, 'antalet är den sammanslagna listan');

$p1 = jeab_inkorg_view_list($threads, $hist, 1, 2);
$p2 = jeab_inkorg_view_list($threads, $hist, 2, 2);
$p3 = jeab_inkorg_view_list($threads, $hist, 3, 2);
$pages = [
    array_map(static fn(array $row): string => (string) $row['id'], $p1['rows']),
    array_map(static fn(array $row): string => (string) $row['id'], $p2['rows']),
    array_map(static fn(array $row): string => (string) $row['id'], $p3['rows']),
];
hsrt($pages[0] === [$shared, $importLead] && $pages[1] === ['live-lead', $importSupport] && $pages[2] === [$importInfo], 'sidor utan lucka');
$flat = array_merge(...$pages);
hsrt($flat === $expect && count($flat) === count(array_unique($flat)), 'offset ger varje ärende en gång');
hsrt((int) $p1['import']['pages'] === 3 && (int) $p1['total'] === 5, 'sidantalet gäller hela listan');

$idsOf = static function (array $filter) use ($threads): array {
    $list = jeab_inkorg_view_list($threads, $filter, 1, 20);

    return array_map(static fn(array $row): string => (string) $row['id'], $list['rows']);
};
$leadFilter = $hist + ['type' => 'Ny lead'];
$supportFilter = $hist + ['type' => 'Support'];
$infoFilter = $hist + ['type' => 'Information'];
$leadIds = $idsOf($leadFilter);
$sharedType = (string) ($byId[$shared]['labels']['type'] ?? '');
hsrt($leadIds === [$importLead, 'live-lead'], 'Ny lead i Historik är de två leadsen: ' . implode(', ', $leadIds) . ' typ=' . $sharedType);
hsrt(jeab_inkorg_view_total($threads, $leadFilter) === count($idsOf($leadFilter)), 'Ny lead-antalet är listan');
hsrt($idsOf($supportFilter) === [$shared, $importSupport], 'Support i Historik är raderad och importerad');
hsrt(jeab_inkorg_view_total($threads, $supportFilter) === 2, 'Support-antalet är 2');
hsrt($idsOf($infoFilter) === [$importInfo], 'Information i Historik är kvittot');
$openSupport = ['company' => 'jarfalla', 'type' => 'Support'];
hsrt($idsOf($openSupport) === ['live-open-support'], 'typchip Support utan Historik tar bara det öppna');
hsrt(!in_array('live-lead', $idsOf($openSupport), true) && !in_array($importSupport, $idsOf($openSupport), true), 'stängd Support ligger inte i arbetsfiltrets chip');

echo "OK  historik sort\n";
