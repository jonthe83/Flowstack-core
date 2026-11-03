<?php

declare(strict_types=1);

/** Stängt ärende kan inte vara parkerat. SQL, inget utskick, ingen kundtext i hooksvaret. */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-parkclean-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'park-cleanup-secret',
    'hashes' => [
        ['hash' => 'sam-hash-fixture', 'email' => 'demo@example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'people' => [
        ['id' => 'SamL', 'email' => 'demo@example.test', 'name' => 'Sam L', 'company' => 'jarfalla'],
    ],
], true) . ";\n");
file_put_contents($tmp . '/inkorg/settings.json', json_encode([
    'helpscout_companies' => [],
    'send_enabled' => false,
], JSON_UNESCAPED_UNICODE));
require $root . '/src/bootstrap.php';

function pc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_PARK_SENDS'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $mail): array {
    $GLOBALS['_PARK_SENDS'][] = $mail;

    return ['ok' => false, 'error' => 'testet skickar inte'];
};

pc(jeab_inkorg_sql_set_storage('sql') && jeab_inkorg_storage_mode() === 'sql', 'inkorgen kör sql');
$_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value('demo@example.test', 'sam-hash-fixture');
pc(jeab_auth_ok() && jeab_inkorg_me_id() === 'SamL', 'Sam L är inloggad');

$future = '2099-06-01';
$clock = time();
$stamp = static function () use (&$clock): string {
    $clock += 2;

    return date('c', $clock);
};

$mail = static function (string $id, string $company): array {
    return [
        'message_id' => '<' . $id . '@park-cleanup.test>',
        'from' => $id . '@example.test',
        'from_name' => 'Kund ' . $id,
        'to' => $company === 'malmo' ? 'kontakt@malmo.example.test' : 'kontakt@jarfalla.example.test',
        'subject' => 'Ärende ' . $id,
        'body_text' => 'Syntetiskt ärende ' . $id,
        'source' => 'graph-' . $company,
        'company' => $company,
        'test' => true,
    ];
};

$ingest = static function (string $id, string $company) use ($mail): string {
    $res = jeab_inkorg_ingest($mail($id, $company));
    pc(!empty($res['ok']), $id . ' tas in');

    return (string) $res['thread_id'];
};

$park = static function (string $tid, string $status = 'waiting_supplier') use ($future, $stamp): void {
    jeab_inkorg_thread_write($tid, static function (array $t) use ($status, $future, $stamp): array {
        $t['status'] = $status;
        $t['park_until'] = $future;
        $t['waiting_until'] = $future;
        $t['updated_at'] = $stamp();

        return $t;
    });
};

$rowOf = static function (string $tid): array {
    $pdo = jeab_inkorg_store_pdo();
    pc($pdo instanceof PDO, 'sql-handtag finns');
    $stmt = $pdo->prepare('SELECT status, row_json FROM inkorg_threads WHERE id = ?');
    $stmt->execute([$tid]);
    $db = $stmt->fetch(PDO::FETCH_ASSOC);
    $json = json_decode((string) ($db['row_json'] ?? ''), true);

    return [
        'status' => (string) ($db['status'] ?? ''),
        'json' => is_array($json) ? $json : [],
    ];
};

$parkGone = static function (string $tid, string $label) use ($rowOf): void {
    $row = $rowOf($tid);
    pc(!array_key_exists('park_until', $row['json']) && !array_key_exists('waiting_until', $row['json']), $label . ' har ingen parkering i SQL');
};

$idsOf = static function (array $rows): array {
    $ids = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $ids[] = (string) ($row['id'] ?? '');
        }
    }

    return $ids;
};

$parkState = static function (string $box) use ($idsOf): array {
    $_GET = ['p' => 'inkorg', 'box' => $box, 'view' => 'v-vantar'];
    $_SESSION['jeab_inkorg_box'] = $box;
    unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_INKORG_THREADS'], $GLOBALS['_JEAB_INKORG_THREADS_FP']);
    $views = array_column(jeab_inkorg_views(), null, 'id');
    $filter = (array) ($views['v-vantar']['filter'] ?? []) + ['company' => $box];
    $light = jeab_inkorg_fast_light();
    $list = jeab_inkorg_view_list($light, $filter, 1, 200);
    $menu = jeab_inkorg_menu_views(jeab_inkorg_company_views(jeab_inkorg_views(), $box), 'v-vantar');
    $counts = jeab_inkorg_fast_counts($light, $menu, $box, jeab_inkorg_me_id(), true);

    return [
        'ids' => $idsOf($list['rows']),
        'total' => jeab_inkorg_view_total($light, $filter),
        'count' => (int) ($counts['counts']['v-vantar'] ?? -1),
    ];
};

$jfOpen = $ingest('jf-open', 'jarfalla');
$jfWait = $ingest('jf-wait', 'jarfalla');
$jfSwipe = $ingest('jf-swipe', 'jarfalla');
$jfButton = $ingest('jf-button', 'jarfalla');
$jfAuto = $ingest('jf-auto', 'jarfalla');
$jfLegacy = $ingest('jf-legacy', 'jarfalla');
$moOpen = $ingest('mo-open', 'malmo');
$moWrap = $ingest('mo-wrap', 'malmo');
$moLegacy = $ingest('mo-legacy', 'malmo');

$park($jfOpen, 'waiting_supplier');
$park($jfWait, 'waiting_customer');
$park($jfSwipe);
$park($jfButton);
$park($jfAuto);
$park($moOpen);

$before = $parkState('jarfalla');
pc(in_array($jfOpen, $before['ids'], true) && in_array($jfWait, $before['ids'], true), 'öppen parkering och väntar på kund ligger i Parkerat');
pc(!in_array($jfSwipe, $before['ids'], true) === false, 'svep-ärendet syns i Parkerat innan stängning');
pc($before['count'] === $before['total'] && $before['total'] === count($before['ids']), 'Järfällas vykort räknar samma öppna Parkerat som listan');

$_POST = ['thread' => $jfSwipe, 'status' => 'closed'];
pc(jeab_inkorg_action('inkorg.thread') === 'Ärendet sparat.', 'svep stänger utan status_ui');
$parkGone($jfSwipe, 'svep');
pc($rowOf($jfSwipe)['status'] === 'closed', 'svep lämnar status stängd');

$_POST = ['thread' => $jfButton, 'status' => 'closed', 'status_ui' => '1'];
pc(jeab_inkorg_action('inkorg.thread') === 'Ärendet sparat.', 'knappen stänger via statusytan');
$parkGone($jfButton, 'knapp');

jeab_inkorg_mark_hook_closed($jfAuto, 'test-auto');
$parkGone($jfAuto, 'auto-stäng');
pc(($rowOf($jfAuto)['json']['hook_closed'] ?? '') === 'test-auto', 'auto-stäng behåller stängvägen');

jeab_inkorg_thread_write($moWrap, static function (array $t) use ($future, $stamp): array {
    $t['status'] = 'closed';
    $t['park_until'] = $future;
    $t['waiting_until'] = $future;
    $t['updated_at'] = $stamp();

    return $t;
});
$parkGone($moWrap, 'HS-sync-väg');
pc($rowOf($moWrap)['status'] === 'closed', 'skrivning som sätter stängd och parkering lämnar status stängd');

$store = jeab_inkorg_pdo_store();
pc($store instanceof JeabPdoInkorgStore, 'sql-lagret finns');
foreach (['jf' => $jfLegacy, 'mo' => $moLegacy] as $label => $tid) {
    $meta = jeab_inkorg_thread_meta($tid);
    $meta['status'] = 'closed';
    $meta['park_until'] = $future;
    $meta['waiting_until'] = $future;
    $meta['updated_at'] = $stamp();
    $store->upsertThread($tid, $meta);
    $row = $rowOf($tid);
    pc($row['status'] === 'closed' && ($row['json']['park_until'] ?? '') === $future, $label . ' legacy behåller parkering förbi skrivspärren');
}

$hsId = 'hsi-' . substr(hash('sha256', 'park-cleanup-hs'), 0, 16);
$hsConv = [
    'id' => $hsId,
    'company' => 'jarfalla',
    'status' => 'waiting_supplier',
    'closed_at' => '2024-02-02T00:00:00+00:00',
    'import_source' => 'helpscout_import',
    'messages' => [[
        'id' => substr(hash('sha256', 'park-cleanup-hs-m'), 0, 16),
        'from' => 'hs-park@example.test',
        'from_name' => 'Importkund',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Importerat parkerat',
        'body' => 'Historik utan utskick',
        'received_at' => '2021-05-01T10:00:00+00:00',
        'source' => 'helpscout_import',
        'company' => 'jarfalla',
        'labels' => ['type' => 'Ny lead'],
        'internal_note' => false,
        'attachments' => [],
    ]],
    'meta' => [
        'status' => 'waiting_supplier',
        'tags' => [],
        'assignee' => '',
        'read' => [],
        'import_source' => 'helpscout_import',
        'park_until' => $future,
        'waiting_until' => $future,
        'closed_at' => '2024-02-02T00:00:00+00:00',
    ],
];
pc(jeab_hs_import_save_conv($hsConv) && jeab_hs_import_index_append(jeab_hs_import_index_row($hsConv)), 'Help Scout-rad med stängningsmärke sparas');

$skipId = 'hsi-' . substr(hash('sha256', 'park-cleanup-skip'), 0, 16);
$skipConv = $hsConv;
$skipConv['id'] = $skipId;
$skipConv['company'] = 'other';
$skipConv['messages'][0]['id'] = substr(hash('sha256', 'park-cleanup-skip-m'), 0, 16);
$skipConv['meta']['status'] = 'closed';
pc(jeab_hs_import_save_conv($skipConv) && jeab_hs_import_index_append(jeab_hs_import_index_row($skipConv)), 'okänt bolag sparas');

$meabId = 'hsi-' . substr(hash('sha256', 'park-cleanup-meab'), 0, 16);
$meabConv = $hsConv;
$meabConv['id'] = $meabId;
$meabConv['company'] = 'meab';
$meabConv['messages'][0]['id'] = substr(hash('sha256', 'park-cleanup-meab-m'), 0, 16);
$meabConv['meta']['status'] = 'closed';
pc(jeab_hs_import_save_conv($meabConv) && jeab_hs_import_index_append(jeab_hs_import_index_row($meabConv)), 'meab-rad sparas');

$hidden = $parkState('jarfalla');
pc(!in_array($jfLegacy, $hidden['ids'], true) && !in_array($hsId, $hidden['ids'], true) && !in_array($jfSwipe, $hidden['ids'], true), 'stängda ligger inte i Järfällas Parkerat');
pc(in_array($jfOpen, $hidden['ids'], true) && in_array($jfWait, $hidden['ids'], true), 'öppna parkerade ligger kvar');
pc($hidden['count'] === $hidden['total'] && $hidden['total'] === count($hidden['ids']), 'förberäknat tal följer listan efter stängning');

$moState = $parkState('malmo');
pc(in_array($moOpen, $moState['ids'], true) && !in_array($moLegacy, $moState['ids'], true) && !in_array($moWrap, $moState['ids'], true) && !in_array($meabId, $moState['ids'], true), 'Malmös Parkerat visar bara det öppna ärendet');
pc($moState['count'] === 1 && $moState['count'] === $moState['total'], 'Malmös vykort räknar ett öppet parkerat ärende');
pc(!in_array($jfLegacy, $moState['ids'], true) && !in_array($jfOpen, $moState['ids'], true), 'Järfälla syns inte i Malmös Parkerat');

$listed = static function (array $res, string $box): array {
    $threads = $res['boxes'][$box]['threads'] ?? null;
    pc(is_array($threads), $box . ' har en trådlista');

    return array_values(array_map('strval', $threads));
};

$dry = jeab_inkorg_hook(['action' => 'inkorg.park_cleanup']);
pc(!empty($dry['ok']) && (int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['sent'] ?? -1) === 0, 'utelämnad dry är torr och skickar inget');
$jfDry = $listed($dry, 'jarfalla');
$moDry = $listed($dry, 'malmo');
pc($jfDry === [$hsId, $jfLegacy] || $jfDry === [$jfLegacy, $hsId], 'Järfällas torrkörning listar legacy och Help Scout');
sort($jfDry);
pc($jfDry === (static function (array $ids): array {
    sort($ids);

    return $ids;
})([$hsId, $jfLegacy]), 'Järfällas id är de två stängda');
pc(in_array($meabId, $moDry, true) && in_array($moLegacy, $moDry, true), 'Malmös torrkörning listar legacy och meab');
pc(!in_array($skipId, $jfDry, true) && !in_array($skipId, $moDry, true), 'okänt bolag listas inte');
pc(!in_array($jfOpen, $jfDry, true) && !in_array($jfWait, $jfDry, true) && !in_array($moOpen, $moDry, true), 'öppna parkerade rörs inte av torrkörningen');
pc((int) $dry['boxes']['jarfalla']['count'] === count($jfDry) && (int) $dry['boxes']['malmo']['count'] === count($moDry), 'antalet är listans längd');
pc(($rowOf($jfLegacy)['json']['park_until'] ?? '') === $future, 'torrkörning skriver inte');

$alias = jeab_inkorg_hook(['action' => 'inbox.park_cleanup', 'dry' => '1']);
pc($listed($alias, 'jarfalla') === $jfDry && $listed($alias, 'malmo') === $moDry, 'inbox.park_cleanup är samma torrkörning');

$denied = jeab_inkorg_hook(['action' => 'inkorg.park_cleanup', 'dry' => 0]);
pc(empty($denied['ok']) && ($denied['error'] ?? '') === 'confirm' && (int) ($denied['sent'] ?? -1) === 0, 'live utan confirm stoppas');
pc(($rowOf($jfLegacy)['json']['park_until'] ?? '') === $future, 'nekad live skriver inte');
$wrong = jeab_inkorg_hook(['action' => 'inkorg.park_cleanup', 'dry' => '0', 'confirm' => 'sam']);
pc(empty($wrong['ok']) && ($wrong['error'] ?? '') === 'confirm', 'confirm måste vara exakt SAM');

$live = jeab_inkorg_hook(['action' => 'inkorg.park_cleanup', 'dry' => 0, 'confirm' => 'SAM']);
pc(!empty($live['ok']) && (int) ($live['dry'] ?? 1) === 0 && (int) ($live['sent'] ?? -1) === 0, 'bekräftad live tar bort parkering och skickar inget');
$parkGone($jfLegacy, 'live Järfälla');
$parkGone($moLegacy, 'live Malmö');
pc($rowOf($jfLegacy)['status'] === 'closed' && $rowOf($moLegacy)['status'] === 'closed', 'live lämnar status stängd');
$hsMeta = jeab_hs_import_read_conv($hsId);
pc(is_array($hsMeta) && !isset($hsMeta['meta']['park_until']) && !isset($hsMeta['meta']['waiting_until']), 'Help Scout-parkeringen är borta');
pc(($hsMeta['meta']['closed_at'] ?? '') === '2024-02-02T00:00:00+00:00' && ($hsMeta['meta']['status'] ?? '') === 'waiting_supplier', 'stängningsmärket och väntestatusen ligger kvar');
$meabMeta = jeab_hs_import_read_conv($meabId);
pc(is_array($meabMeta) && !isset($meabMeta['meta']['park_until']), 'meab-parkeringen är borta');

$again = jeab_inkorg_hook(['action' => 'inkorg.park_cleanup']);
pc($listed($again, 'jarfalla') === [] && $listed($again, 'malmo') === [], 'andra torrkörningen är tom');
pc((int) $again['boxes']['jarfalla']['count'] === 0 && (int) $again['boxes']['malmo']['count'] === 0, 'antalen är noll');

$after = $parkState('jarfalla');
pc($after['ids'] === [$jfWait, $jfOpen] || (in_array($jfOpen, $after['ids'], true) && in_array($jfWait, $after['ids'], true) && count($after['ids']) === 2), 'Järfällas Parkerat är de två öppna');
pc($after['count'] === 2 && $after['total'] === 2, 'vykortet räknar två efter rensningen');
$moAfter = $parkState('malmo');
pc($moAfter['ids'] === [$moOpen] && $moAfter['count'] === 1, 'Malmös öppna parkering ligger kvar');
pc(($rowOf($jfOpen)['json']['park_until'] ?? '') === $future && ($rowOf($moOpen)['json']['park_until'] ?? '') === $future, 'öppna parkerade behåller datumet');

$encoded = json_encode($live, JSON_UNESCAPED_UNICODE);
pc(is_string($encoded) && !str_contains($encoded, '@') && !str_contains($encoded, 'example') && !str_contains($encoded, 'Importerat') && !str_contains($encoded, 'Syntetiskt'), 'hooksvaret har inga kunduppgifter');
pc(count($GLOBALS['_PARK_SENDS']) === 0, 'inget utskick');
pc(!is_file($tmp . '/inkorg/threads.json') && !is_file($tmp . '/inkorg/messages.json'), 'inkorgen skrev ingen json-kopia');

echo "OK  park-cleanup\n";
