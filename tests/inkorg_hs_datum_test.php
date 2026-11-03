<?php

declare(strict_types=1);

/** Listdatum, Help Scout-skräp i citat, reprocess-sidor och borttagna vyer. Fejkdata. */

$tmp = sys_get_temp_dir() . '/jeab-hsdatum-' . bin2hex(random_bytes(4));
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

function hd(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$junk = "Hej, jag vill ha en offert på en laddbox.\n"
    . "{#HS:884422}\n"
    . "Hur nöjd är du? https://secure.helpscout.net/satisfaction/abc123\n"
    . "https://secure.helpscout.net/conversation/555\n"
    . "Notis: https://secure.helpscout.net/notification/9\n"
    . "<a href=\"https://jeab.helpscout.net/conversation/12\">Öppna ärendet</a>\n";
$clean = jeab_inkorg_strip_hs_junk($junk);
hd(str_contains($clean, 'jag vill ha en offert på en laddbox'), 'kundtexten är kvar');
hd(!str_contains($clean, 'helpscout') && !str_contains($clean, '{#HS:'), 'länkar och HS-koder är borta');
hd(jeab_inkorg_strip_hs_junk($clean) === $clean, 'rensningen är idempotent');
$snippet = jeab_inkorg_message_snippet(['from' => 'exempel.kund@example.test', 'body' => $junk], 200);
hd(!str_contains($snippet, 'helpscout') && str_contains($snippet, 'offert'), 'förhandsvisningen visar inte Help Scout');
$quoted = jeab_inkorg_clean_message([
    'from' => 'exempel.kund@example.test',
    'body' => "Tack för svaret.\n{#HS:42} https://secure.helpscout.net/conversation/42\n> Citerat https://secure.helpscout.net/satisfaction/z",
]);
hd(str_contains($quoted, 'Tack för svaret') && !str_contains($quoted, 'helpscout') && !str_contains($quoted, '{#HS:'), 'citerad text tappar Help Scout-skräp');
$stored = jeab_hs_import_plain('<p>Hej</p><p>{#HS:7}</p><p>https://secure.helpscout.net/conversation/7</p>');
hd($stored === 'Hej' || (str_contains($stored, 'Hej') && !str_contains($stored, 'helpscout')), 'importen normaliserar brödtexten');

$importAt = '2026-10-19T08:00:00+00:00';
$mailAt = '2024-03-01T10:00:00+00:00';
$closedAt = '2024-06-15T12:00:00+00:00';
$deletedAt = '2025-01-02T09:30:00+00:00';

$conv = static function (string $cid, array $meta, string $mail) use ($mailAt): array {
    $id = jeab_hs_import_thread_key($cid);

    return [
        'id' => $id,
        'company' => 'jarfalla',
        'status' => (string) ($meta['status'] ?? 'closed'),
        'import_source' => 'helpscout_import',
        'messages' => [[
            'id' => substr(hash('sha256', $cid), 0, 16),
            'from' => 'exempel.kund@example.test',
            'from_name' => 'Exempel Kund',
            'subject' => 'Fråga ' . $cid,
            'body' => 'Hej, det här är ett fejkärende.',
            'received_at' => $mail,
            'source' => 'helpscout_import',
        ]],
        'meta' => $meta + [
            'updated_at' => '2026-10-19T08:00:00+00:00',
            'import_source' => 'helpscout_import',
            'deleted_at' => '',
        ],
    ];
};

$open = $conv('100', ['status' => 'open', 'updated_at' => $importAt], $mailAt);
hd(str_starts_with(jeab_hs_import_list_at($open), '2024-03-01'), 'öppet ärende använder mejldatum, inte importtid');
$closed = $conv('200', ['status' => 'closed', 'closed_at' => $closedAt, 'updated_at' => $importAt], $mailAt);
hd(str_starts_with(jeab_hs_import_list_at($closed), '2024-06-15'), 'stängt ärende använder stängningsdatum');
$trashed = $conv('300', ['status' => 'closed', 'closed_at' => $closedAt, 'deleted_at' => $deletedAt, 'updated_at' => $importAt], $mailAt);
hd(str_starts_with(jeab_hs_import_list_at($trashed), '2025-01-02'), 'raderat ärende använder raderingsdatum');

foreach ([$closed, $trashed] as $one) {
    hd(jeab_hs_import_save_conv($one), 'konversation sparas');
    $row = jeab_hs_import_index_row($one);
    $row['updated_at'] = $importAt;
    hd(jeab_hs_import_index_append($row), 'indexrad med importtid skrivs');
}
$stale = jeab_hs_import_index()['by_id'][$closed['id']]['updated_at'] ?? '';
hd($stale === $importAt, 'indexet kan ligga kvar på importtid innan omkörning');

$dryDates = jeab_inkorg_hook(['action' => 'inbox.reindex_dates']);
hd((int) ($dryDates['dry'] ?? 0) === 1 && (int) ($dryDates['changed'] ?? 0) === 2, 'inbox.reindex_dates är torr och räknar ändringar');
hd((int) ($dryDates['limit'] ?? 0) === 200 && array_key_exists('next_offset', $dryDates) && $dryDates['next_offset'] === null, 'standardgräns 200 och ingen nästa sida');
$encoded = json_encode($dryDates);
hd(is_string($encoded) && !str_contains($encoded, 'exempel.kund') && !str_contains($encoded, 'Fråga'), 'datumrapporten saknar kunddata');
hd(($jeab = jeab_hs_import_index()['by_id'][$closed['id']]['updated_at'] ?? '') === $importAt, 'torrkörning skriver inte om indexet');

$page = jeab_inkorg_hook(['action' => 'inbox.reindex_dates', 'dry' => 0, 'limit' => 1, 'offset' => 0]);
hd((int) $page['dry'] === 0 && (int) $page['changed'] === 1 && (int) $page['scanned'] === 1 && (int) $page['next_offset'] === 1, 'omkörning är sidindelad');
$rest = jeab_inkorg_hook(['action' => 'inbox.reindex_dates', 'dry' => 0, 'limit' => 1, 'offset' => 1]);
hd((int) $rest['changed'] === 1 && array_key_exists('next_offset', $rest) && $rest['next_offset'] === null, 'sista sidan har inget next_offset');
$again = jeab_inkorg_hook(['action' => 'inbox.reindex_dates', 'dry' => 1]);
hd((int) $again['changed'] === 0 && (int) $again['unchanged'] === 2, 'andra torrkörningen ändrar inget');

$sorted = jeab_hs_import_query(['historik' => true], 1, 10);
$order = array_map(static fn(array $row): string => (string) ($row['id'] ?? ''), $sorted['rows']);
hd($order === [$trashed['id'], $closed['id']], 'Historik sorterar raderingsdatum före stängningsdatum');
hd(str_starts_with((string) $sorted['rows'][0]['updated_at'], '2025-01-02'), 'listan visar raderingsdatum');
hd(jeab_inkorg_thread_matches(jeab_hs_import_row_thread(jeab_hs_import_index()['by_id'][$trashed['id']]), ['historik' => true]), 'raderat importärende ligger i Historik');

$third = $conv('400', ['status' => 'closed', 'closed_at' => '2023-01-01T00:00:00+00:00'], '2022-01-01T00:00:00+00:00');
hd(jeab_hs_import_save_conv($third) && jeab_hs_import_index_append(jeab_hs_import_index_row($third)), 'tredje ärendet finns');
unset($GLOBALS['_JEAB_HS_IMPORT_INDEX']);
$re = jeab_hs_import_reprocess(['limit' => 2]);
hd((int) ($re['dry'] ?? 0) === 1 && (int) ($re['limit'] ?? 0) === 2 && (int) ($re['cases'] ?? 0) === 2 && (int) ($re['next_offset'] ?? 0) === 2, 'helpscout.reprocess tar en sida och lämnar next_offset');
$re2 = jeab_hs_import_reprocess(['limit' => 2, 'offset' => 2]);
hd((int) $re2['cases'] === 1 && array_key_exists('next_offset', $re2) && $re2['next_offset'] === null, 'sista reprocess-sidan är tom på nästa offset');
$all = jeab_hs_import_reprocess([]);
hd((int) $all['limit'] === 200 && (int) $all['cases'] === 3 && array_key_exists('next_offset', $all) && $all['next_offset'] === null, 'utan limit behandlas ett litet index i ett svep');

$mail = jeab_inkorg_ingest([
    'message_id' => '<hd-1@example.test>',
    'from' => 'annan.kund@example.test',
    'from_name' => 'Annan Kund',
    'to' => 'info@jarfalla.example.test',
    'subject' => 'Batterifråga',
    'body_text' => 'Hej, vi vill ha ett batteri.',
    'source' => 'test-hd',
    'test' => true,
]);
hd(!empty($mail['ok']), 'ett levande ärende tas in');
$tid = (string) $mail['thread_id'];
jeab_inkorg_trash($tid, true, 'Test');
$live = array_column(jeab_inkorg_threads(), null, 'id')[$tid] ?? [];
$metaDeleted = (string) (jeab_inkorg_threads_meta()[$tid]['deleted_at'] ?? '');
hd($metaDeleted !== '' && (string) ($live['updated_at'] ?? '') === $metaDeleted, 'levande lista visar raderingsdatum');
hd(jeab_inkorg_thread_matches($live, ['historik' => true]) && !jeab_inkorg_thread_matches($live, ['deleted' => false]), 'raderat levande ärende syns i Historik');

jeab_inkorg_view_save(['id' => 'v-sparad-korg', 'name' => 'Papperskorg', 'menu' => true, 'show' => []]);
$before = array_column(jeab_inkorg_views(), 'id');
hd(in_array('v-papperskorg', $before, true) && in_array('v-vantar-offert', $before, true) && in_array('v-sparad-korg', $before, true), 'systemvyer och en sparad kopia finns');
$menuBefore = array_column(jeab_inkorg_menu_views(jeab_inkorg_views()), 'name');
hd(!in_array('Papperskorg', $menuBefore, true) && !in_array('Väntar på offert', $menuBefore, true), 'menyn visar inte vyerna');

$report = jeab_inkorg_hook(['action' => 'inbox.views_migrate']);
hd((int) ($report['dry'] ?? 0) === 1, 'views_migrate är torr');
$removed = array_column((array) ($report['views'] ?? []), 'action', 'id');
hd(($removed['v-papperskorg'] ?? '') === 'remove' && ($removed['v-vantar-offert'] ?? '') === 'remove' && ($removed['v-sparad-korg'] ?? '') === 'remove', 'rapporten tar bort system och sparad vy');
hd((int) ($report['historik'] ?? 0) >= 4 && (int) ($report['historik_deleted'] ?? 0) >= 2, 'rapporten räknar Historik och de raderade');
$reportJson = json_encode($report);
hd(is_string($reportJson) && !str_contains($reportJson, 'example.test') && !str_contains($reportJson, 'Batterifråga'), 'vyrappporten saknar kunddata');
hd(in_array('v-papperskorg', array_column(jeab_inkorg_views(), 'id'), true), 'torrkörning lämnar vyerna');

$wet = jeab_inkorg_views_migrate(['dry' => 0]);
$left = array_column(jeab_inkorg_views(), 'id');
hd(!in_array('v-papperskorg', $left, true) && !in_array('v-vantar-offert', $left, true) && !in_array('v-sparad-korg', $left, true), 'skarp körning tar bort vyerna');
hd(in_array('v-historik', $left, true) && in_array('v-vantar', $left, true), 'Historik och Parkerat är kvar');
hd((int) ($wet['historik_deleted'] ?? 0) >= 2, 'skarp rapport räknar raderade i Historik');

echo "OK  inkorg hs datum\n";
