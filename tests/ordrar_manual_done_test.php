<?php

declare(strict_types=1);

/**
 * Klarmarkera ordern från ⋯-menyn.
 * Alla räknade steg blir gröna (4 av 4 för batteri), ordern hamnar i Allt klart,
 * manual_done (användare + tid) sparas och aktivitetsloggen får en rad.
 * Ångra tar bort flaggan och loggar det. Inget mejl, sms, Fortnox, bokning
 * eller påminnelse, och inga fält raderas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-manual-done-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
$GLOBALS['_JEAB_CALENDAR_LOG_PATH'] = $tmp . '/calendar-log.json';
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
$mdHash = password_hash('SamLmanual', PASSWORD_DEFAULT);
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [
        ['email' => 'demo@example.test', 'hash' => $mdHash],
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'passwords_file' => $tmp . '/passwords.json',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
$GLOBALS['_JEAB_PURCHASES_LEGACY_SQL'] = true;
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path($tmp);
    session_start();
}
$_SESSION['jeab_email'] = 'demo@example.test';
jeab_code_people_save(jeab_auth_hash_fingerprint($mdHash), 'SamL', 'prefix', 'SamL');
$_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value('demo@example.test', $mdHash);

function md_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function md_ids(array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $ids[] = (int) ($row['reminder_id'] ?? 0);
        }
    }
    sort($ids);
    return $ids;
}

function md_texts(array $row): array
{
    $out = [];
    foreach (jeab_ordrar_activity_list($row) as $item) {
        $out[] = (string) ($item['text'] ?? '');
    }
    return $out;
}

$store = jeab_store();
$rem = jeab_create_reminder($store, 'Kim Klart', '2026-11-02 00:00:00', 'batteri', null, [
    'customer' => 'Kim Klart',
    'company' => 'jarfalla',
    'address' => 'Testgatan 4, 175 00 Järfälla',
    'phone' => '0700000000',
    'tags' => ['vunnen'],
]);
$rid = (int) ($rem['id'] ?? 0);
md_ok($rid > 0, 'påminnelsen finns');
$reminderBefore = jeab_get_reminder($store, $rid);
md_ok(is_array($reminderBefore), 'påminnelsen kan läsas');

$seed = [
    'reminder_id' => $rid,
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'name' => 'Kim Klart',
    'customer' => 'Kim Klart',
    'address' => 'Testgatan 4, 175 00 Järfälla',
    'huvudsokande' => 'Kim Klart',
    'personnummer' => '19800101-0000',
    'fastighetsbeteckning' => 'Testet 4:4',
    'pris' => 79000,
    'install_note' => 'lämna kvar',
    'fortnox_order' => '',
    'fortnox_invoice' => '',
    'booking_id' => 0,
    'reminder_created_at' => '2026-09-01 09:00:00',
    'created_at' => '2026-09-01 09:00:00',
    'steps' => ['kund_bestallt' => '2026-09-01'],
    'activity' => [['at' => '2026-09-01 10:00:00', 'text' => 'Tidigare rad']],
];
jeab_purchase_put($seed);
$open = jeab_purchase_get($rid);
md_ok(is_array($open), 'ordern finns');

$beforeHtml = jeab_ordrar_v3_html($open, 'jarfalla', $rid, $store);
$markAt = strpos($beforeHtml, 'data-act="manual-done">Klarmarkera ordern…');
$delAt = strpos($beforeHtml, 'data-act="radera">Radera ärendet…');
$oldAt = strpos($beforeHtml, 'data-act="gamla">Visa gamla vyn');
md_ok($markAt !== false && $delAt !== false && $oldAt !== false && $markAt < $delAt && $delAt < $oldAt, 'Klarmarkera ligger i ⋯-menyn bredvid Radera och Visa gamla vyn');
md_ok(str_contains($beforeHtml, 'id="v3-f-manual-done"') && str_contains($beforeHtml, 'name="action" value="ordrar.manual_done"'), 'menyn postar ordrar.manual_done');
md_ok(str_contains($beforeHtml, 'id="v3-modal"') && str_contains($beforeHtml, 'data-v3="modal-cancel">Avbryt'), 'bekräftelsen är mallens dialog');
md_ok(!str_contains($beforeHtml, 'Ångra klarmarkering'), 'ångra syns inte innan ordern är klarmarkerad');

$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
$jsStart = strpos($js, "'manual-done': function");
$jsEnd = strpos($js, "'edit-bekrafta':", $jsStart === false ? 0 : $jsStart);
$jsBody = $jsStart !== false && $jsEnd !== false ? substr($js, $jsStart, $jsEnd - $jsStart) : '';
md_ok(str_contains($jsBody, "confirmBox(") && str_contains($jsBody, 'Klarmarkera ordern?') && str_contains($jsBody, 'postManual(false)') && str_contains($jsBody, 'postManual(true)') && str_contains($jsBody, 'Ångra klarmarkering?'), 'både klarmarkera och ångra kräver dialog');
$postStart = strpos($js, 'function postManual');
$postEnd = strpos($js, 'var ACTS', $postStart === false ? 0 : $postStart);
$postBody = $postStart !== false && $postEnd !== false ? substr($js, $postStart, $postEnd - $postStart) : '';
md_ok(str_contains($postBody, "action: 'ordrar.manual_done'") && !str_contains($postBody, 'ordrar.fortnox') && !str_contains($postBody, 'ordrar.slutfor') && !str_contains($postBody, 'ordrar.boka') && !str_contains($postBody, 'booking.cancel') && !str_contains($postBody, 'ordrar.foranmalan'), 'dialogen postar bara ordrar.manual_done');

$fnSrc = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$fnStart = strpos($fnSrc, 'function jeab_ordrar_manual_done(');
$fnEnd = strpos($fnSrc, 'function jeab_ordrar_stang(', $fnStart === false ? 0 : $fnStart);
$fnBody = $fnStart !== false && $fnEnd !== false ? substr($fnSrc, $fnStart, $fnEnd - $fnStart) : '';
$fnScan = preg_replace('#/\*.*?\*/#s', '', $fnBody) ?? '';
$fnScan = preg_replace('#//.*#', '', $fnScan) ?? '';
foreach ([
    'jeab_update_reminder',
    'jeab_ordrar_create_booking',
    'jeab_ordrar_create_fortnox',
    'jeab_purchase_delete',
    'jeab_ordrar_delete',
    'jeab_send_',
    'sms',
    'mail',
    'fortnox',
] as $needle) {
    md_ok(!str_contains(strtolower($fnScan), $needle), 'åtgärden anropar inte ' . $needle);
}
$indexSrc = (string) file_get_contents(dirname(__DIR__) . '/index.php');
md_ok(str_contains($indexSrc, "case 'ordrar.manual_done':") && str_contains($indexSrc, 'jeab_ordrar_manual_done($_POST)'), 'POST-åtgärden finns');

$progressOpen = jeab_ordrar_v3_progress($open, $store);
md_ok($progressOpen['total'] === 4 && $progressOpen['done'] === 0 && $progressOpen['closed'] === false, 'öppen batteriorder är 0 av 4');

$marked = jeab_ordrar_manual_done(['id' => $rid]);
md_ok(jeab_ordrar_manual_done_on($marked), 'manual_done är satt');
$record = jeab_ordrar_manual_done_record($marked);
md_ok(is_array($record) && $record['by'] === 'Sam L' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $record['at']) === 1, 'manual_done har användare och tid, fick ' . json_encode($record, JSON_UNESCAPED_UNICODE));
$when = jeab_ordrar_manual_done_when($record['at']);
$markLine = 'Manuellt klarmarkerad av Sam L ' . $when;
md_ok(in_array($markLine, md_texts($marked), true), 'loggen säger «' . $markLine . '», fick ' . json_encode(md_texts($marked), JSON_UNESCAPED_UNICODE));
md_ok(in_array('Tidigare rad', md_texts($marked), true), 'tidigare aktivitet ligger kvar');
md_ok((string) ($marked['install_note'] ?? '') === 'lämna kvar', 'anteckningen är kvar');
md_ok((int) ($marked['booking_id'] ?? -1) === 0 && trim((string) ($marked['fortnox_order'] ?? 'x')) === '' && trim((string) ($marked['fortnox_invoice'] ?? 'x')) === '', 'bokning och Fortnox-nummer är orörda');
$stepStamp = is_array($marked['steps'] ?? null) ? (string) ($marked['steps']['kund_bestallt'] ?? '') : '';
md_ok($stepStamp === '2026-09-01', 'stegstämplar är kvar');
md_ok(jeab_get_reminder($store, $rid) === $reminderBefore, 'påminnelsen är oförändrad');
md_ok(!is_file($GLOBALS['_JEAB_CALENDAR_LOG_PATH']), 'ingen kalenderlogg skrivs');

$progress = jeab_ordrar_v3_progress($marked, $store);
md_ok($progress['closed'] === true && $progress['done'] === 4 && $progress['total'] === 4 && $progress['label'] === 'Klart', 'listläget är 4 av 4 Klart');
$states = [];
foreach (jeab_ordrar_v3_steps($marked, $store) as $step) {
    $states[$step['id']] = $step['state'];
}
md_ok($states === [
    'bekrafta' => 'klar',
    'foranmalan' => 'klar',
    'bestall' => 'klar',
    'faktura' => 'klar',
    'stang' => 'klar',
], 'alla steg är gröna, fick ' . json_encode($states, JSON_UNESCAPED_UNICODE));

$doneHtml = jeab_ordrar_v3_html($marked, 'jarfalla', $rid, $store);
md_ok(str_contains($doneHtml, '4 av 4 klara') && str_contains($doneHtml, 'Allt klart'), 'sidan visar 4 av 4 och Allt klart');
md_ok(substr_count($doneHtml, 'class="is-d"') === 4, 'stegbaren har fyra gröna segment');
foreach (['bekrafta', 'foranmalan', 'bestall', 'faktura', 'stang'] as $stepId) {
    md_ok(str_contains($doneHtml, 'id="steg-' . $stepId . '" data-step="' . $stepId . '" data-state="klar"'), $stepId . ' ritas klar');
}
md_ok(substr_count($doneHtml, 'v3-card is-done') === 5 && substr_count($doneHtml, 'v3-state is-klar') === 5, 'korten använder den gröna Klar-mallen');
md_ok(str_contains($doneHtml, 'Manuellt klarmarkerad') && !str_contains($doneHtml, 'Föranmälan skickad') && !str_contains($doneHtml, 'Skapa order och boka'), 'gröna kort påstår inte att något skickats');
md_ok(str_contains($doneHtml, $markLine), 'aktiviteten visar klarmarkeringen');
md_ok(str_contains($doneHtml, 'data-act="manual-undo">Ångra klarmarkering') && !str_contains($doneHtml, 'Klarmarkera ordern…'), 'menyn byter till Ångra klarmarkering');

$GLOBALS['_JEAB_ORDRAR_VISIBLE'] = [];
$closedIds = md_ids(jeab_ordrar_visible('jarfalla', true));
$openIds = md_ids(jeab_ordrar_visible('jarfalla', false));
md_ok(in_array($rid, $closedIds, true) && !in_array($rid, $openIds, true), 'ordern ligger i Allt klart');
$listHtml = jeab_ordrar_board_row_html($marked, 'jarfalla', $store);
md_ok(str_contains($listHtml, 'is-closed') && str_contains($listHtml, 'Klart') && str_contains($listHtml, '4 av 4') && substr_count($listHtml, 'class="is-d"') === 4, 'listraden är stängd och 4 av 4');

$again = jeab_ordrar_manual_done(['id' => $rid]);
md_ok(md_texts($again) === md_texts($marked) && ($again['manual_done']['at'] ?? '') === $record['at'], 'en andra klarmarkering skriver inte om');

$undone = jeab_ordrar_manual_done(['id' => $rid, 'undo' => '1']);
md_ok(!jeab_ordrar_manual_done_on($undone), 'manual_done är borta efter ångra');
$undoLine = 'Klarmarkering ångrad av Sam L ' . jeab_ordrar_manual_done_when((string) ($undone['updated_at'] ?? ''));
md_ok(in_array($markLine, md_texts($undone), true) && in_array($undoLine, md_texts($undone), true), 'ånger-raden loggas och klarmarkeringen ligger kvar, fick ' . json_encode(md_texts($undone), JSON_UNESCAPED_UNICODE));
md_ok((string) ($undone['install_note'] ?? '') === 'lämna kvar' && (int) ($undone['booking_id'] ?? -1) === 0, 'ångra raderar inte övriga fält');
md_ok(jeab_get_reminder($store, $rid) === $reminderBefore, 'påminnelsen är oförändrad efter ångra');
$back = jeab_ordrar_v3_progress($undone, $store);
md_ok($back['closed'] === false && $back['done'] === 0 && $back['total'] === 4, 'efter ångra är ordern 0 av 4 igen');
$undoHtml = jeab_ordrar_v3_html($undone, 'jarfalla', $rid, $store);
md_ok(str_contains($undoHtml, 'data-act="manual-done">Klarmarkera ordern…') && str_contains($undoHtml, 'data-state="todo"') && str_contains($undoHtml, $undoLine), 'menyn och stegen är tillbaka, och ångern syns i aktiviteten');
$GLOBALS['_JEAB_ORDRAR_VISIBLE'] = [];
md_ok(in_array($rid, md_ids(jeab_ordrar_visible('jarfalla', false)), true) && !in_array($rid, md_ids(jeab_ordrar_visible('jarfalla', true)), true), 'ordern är öppen i listan igen');

$marked2 = jeab_ordrar_manual_done(['id' => $rid]);
$markCount = 0;
foreach (md_texts($marked2) as $text) {
    if (str_starts_with($text, 'Manuellt klarmarkerad av Sam L ')) {
        $markCount++;
    }
}
md_ok($markCount === 2 && jeab_ordrar_manual_done_on($marked2), 'ny klarmarkering efter ångra loggas igen');

$noop = jeab_ordrar_manual_done(['id' => $rid, 'undo' => '1']);
$still = jeab_ordrar_manual_done(['id' => $rid, 'undo' => '1']);
md_ok(!jeab_ordrar_manual_done_on($still) && md_texts($still) === md_texts($noop), 'en andra ångra skriver inte om');

echo "ALL OK\n";
