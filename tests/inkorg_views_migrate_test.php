<?php

declare(strict_types=1);

/** Vyfilter: en krysslista, migrering med backup utanför repot, Parkerat och skräp orörda. */

$tmp = sys_get_temp_dir() . '/jeab-inkvy-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export(['timezone' => 'Europe/Stockholm', 'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json']], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function vm(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$byId = fn() => array_column(jeab_inkorg_views(), null, 'id');
$before = $byId();
vm(isset($before['v-servicekund'], $before['v-vantar'], $before['v-osorterat'], $before['v-papperskorg'], $before['v-vantar-offert']), 'seedade vyer finns');
vm(($before['v-vantar']['name'] ?? '') === 'Parkerat', 'Parkerat heter Parkerat');
$parkFilter = $before['v-vantar']['filter'];
$osoFilter = $before['v-osorterat']['filter'];

$dry = jeab_inkorg_hook(['action' => 'inbox.views_migrate']);
vm(!empty($dry['ok']) && (int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['updated'] ?? 1) === 0, 'hooken är torr som standard');
vm(!is_file(jeab_inkorg_dir() . '/views-backup.json'), 'torrkörning skriver ingen backup');
vm($byId()['v-servicekund']['filter'] === $before['v-servicekund']['filter'], 'torrkörning lämnar Service');
vm($byId()['v-vantar']['filter'] === $parkFilter, 'torrkörning lämnar Parkerat');
vm($byId()['v-osorterat']['filter']['tags_any'] === ['ingen'], 'torrkörning lämnar Osorterat');

$svcRow = null;
$parkRow = null;
foreach ($dry['views'] as $row) {
    if ($row['id'] === 'v-servicekund') {
        $svcRow = $row;
    }
    if ($row['id'] === 'v-vantar') {
        $parkRow = $row;
    }
}
vm(($svcRow['action'] ?? '') === 'show', 'Service planeras som etikett eller typ');
vm(($parkRow['action'] ?? '') === 'kept' && ($parkRow['before'] ?? -1) === ($parkRow['after'] ?? -2), 'Parkerat behåller samma träffar');

$live = jeab_inkorg_views_migrate(['dry' => 0]);
vm((int) $live['dry'] === 0 && (int) $live['updated'] > 0 && $live['backup'] === 'views-backup.json', 'skarp körning skriver backup');
$backup = jeab_inkorg_dir() . '/views-backup.json';
vm(is_file($backup) && str_starts_with($backup, $tmp) && !str_starts_with($backup, dirname(__DIR__)), 'backupen ligger i datakatalogen, inte i repot');
$stamp = (string) file_get_contents($backup);
$after = $byId();
vm($after['v-vantar']['filter'] === $parkFilter, 'Parkerats filter är oförändrat');
vm($after['v-osorterat']['filter'] === $osoFilter, 'Osorterat är kvar som legacy');
vm(!isset($after['v-papperskorg']) && !isset($after['v-vantar-offert']), 'Papperskorg och Väntar på offert tas bort');
vm(in_array('v-papperskorg', $live['removed'], true) && in_array('v-vantar-offert', $live['removed'], true), 'rapporten listar de borttagna vyerna');
$show = $after['v-servicekund']['filter']['show'] ?? [];
vm($show === [['tag', 'servicejobb'], ['type', 'Support']], 'Service är Servicejobb eller Support');

$again = jeab_inkorg_views_migrate(['dry' => '0']);
vm((int) $again['updated'] === 0 && file_get_contents($backup) === $stamp, 'andra körningen skriver inte om');
vm(($byId()['v-servicekund']['filter']['show'] ?? []) === $show, 'Service är idempotent');

$svc = $after['v-servicekund']['filter'];
$thread = fn(string $type, array $tags = [], bool $deleted = false) => ['deleted' => $deleted, 'labels' => ['type' => $type], 'tags' => $tags, 'status' => 'open', 'messages' => []];
vm(jeab_inkorg_thread_matches($thread('Support'), $svc), 'Support träffar Service');
vm(jeab_inkorg_thread_matches($thread('Ny lead', ['servicejobb']), $svc), 'etiketten Servicejobb träffar Service');
vm(!jeab_inkorg_thread_matches($thread('Bokning/ombokning'), $svc), 'enbart Bokning träffar inte Service');
$all = ['show' => []];
vm(jeab_inkorg_thread_matches($thread('Ny lead', ['sol']), $all), 'tom lista visar ett vanligt ärende');
vm(!jeab_inkorg_thread_matches($thread('Ny lead', [], true), $all), 'raderat ärende visas inte');
vm(!jeab_inkorg_thread_matches($thread('Skräp'), $all), 'typen Skräp visas inte');
vm(!jeab_inkorg_thread_matches($thread('Ny lead', ['skrap']), $all), 'etiketten skrap visas inte');

$labels = array_column(jeab_inkorg_view_choices(), 'label');
vm(!in_array('Skräp', $labels, true) && !in_array('Papperskorg', $labels, true) && !in_array('Väntar på offert', $labels, true), 'krysslistan har varken skräp, papperskorg eller offert');
vm(jeab_inkorg_view_hidden_in_editor($before['v-papperskorg']) && jeab_inkorg_view_hidden_in_editor($before['v-vantar-offert']) && jeab_inkorg_view_hidden_in_editor($before['v-skrap']), 'de vyerna döljs i listan');

$nv = jeab_inkorg_view_save(['name' => 'Väntar på lev', 'status' => ['waiting_supplier']]);
vm(jeab_inkorg_thread_matches(['deleted' => false, 'status' => 'waiting_supplier', 'labels' => ['type' => 'Ny lead'], 'tags' => []], $nv['filter']), 'gamla vy-sparningen filtrerar fortfarande på status');
vm(!jeab_inkorg_thread_matches(['deleted' => false, 'status' => 'open', 'labels' => ['type' => 'Ny lead'], 'tags' => []], $nv['filter']), 'öppet ärende faller utanför statusfiltret');

$_SERVER['SCRIPT_NAME'] = '/index.php';
require_once dirname(__DIR__) . '/src/layout.php';
$_GET = [];
$html = require dirname(__DIR__) . '/src/pages/inkorg_vyer.php';
vm(is_string($html), 'vy-sidan renderas');
vm(!str_contains($html, 'Papperskorg') && !str_contains($html, 'Väntar på offert') && !str_contains($html, 'name="deleted"') && !str_contains($html, 'awaiting_quote') && !str_contains($html, 'types_not') && !str_contains($html, '>Skräp<'), 'listan nämner inte papperskorg, offert eller skräp');
vm(str_contains($html, '← Inkorg') && str_contains($html, 'Parkerade ärenden') && !str_contains($html, '#1C1C1E'), 'rubriklänken är Inkorg och Parkerat saknar väntestatus');
$_GET = ['vy' => 'ny'];
$ny = require dirname(__DIR__) . '/src/pages/inkorg_vyer.php';
vm(str_contains($ny, 'Visa ärenden som är') && str_contains($ny, 'name="view_form" value="show"') && !str_contains($ny, 'keep_filter'), 'ny vy är en krysslista');
$_GET = ['vy' => 'v-vantar'];
$park = require dirname(__DIR__) . '/src/pages/inkorg_vyer.php';
vm(str_contains($park, 'name="keep_filter" value="1"') && !str_contains($park, 'name="show[]"'), 'Parkerat sparar namn och behåller filtret');
$_GET = ['vy' => 'v-osorterat'];
$osoHtml = require dirname(__DIR__) . '/src/pages/inkorg_vyer.php';
vm(str_contains($osoHtml, 'krysslistan inte visar') && str_contains($osoHtml, 'name="keep_filter" value="1"') && !str_contains($osoHtml, 'name="show[]"'), 'R5 Osorterats dolda filter visas och lämnas kvar');
$osoBefore = $byId()['v-osorterat']['filter'];
$osoSaved = jeab_inkorg_view_save_simple(['id' => 'v-osorterat', 'name' => 'Osorterat', 'show' => ['tag:batteri']]);
vm($osoSaved['filter'] === $osoBefore, 'R5 sparning skriver inte över det dolda filtret');
