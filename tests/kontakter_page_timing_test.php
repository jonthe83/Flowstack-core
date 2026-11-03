<?php

declare(strict_types=1);

/**
 * Kontaktlistan och detaljen får inte läsa hela registret eller alla ordrar.
 * Påhittade rader. Skriver inte om kunddata.
 */

$tmp = sys_get_temp_dir() . '/jeab-kontakter-timing-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function kt(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

kt(jeab_kontakter_sql_is_sole_source(), 'SQL är enda läskällan');
$store = jeab_store();
kt($store instanceof JeabPdoStore, 'sqlite-lagret finns');
$pdo = $store->pdo();
jeab_ensure_contacts_tables($pdo, 'sqlite');
jeab_purchases_store_ensure($pdo);

$now = jeab_now();
$fat = str_repeat('påhittad anteckning ', 80);
$n = 3000;
$pdo->beginTransaction();
$ins = $pdo->prepare('INSERT INTO contacts (id, row_json, street_key, postal_code, owner_email, owner_phone, owner_name_fold, company, city_fold, sort_key, sort_city, list_ready, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
for ($i = 1; $i <= $n; $i++) {
    $id = sprintf('kt_%016x', $i);
    $street = 'Aagatan ' . $i;
    $row = [
        'id' => $id,
        'street' => $street,
        'street_key' => jeab_kontakt_fold_text($street),
        'postal_code' => '17730',
        'city' => 'Järfälla',
        'company' => 'jarfalla',
        'owners' => [[
            'name' => 'Test ' . $i,
            'contact' => true,
            'personnummer' => '',
            'email' => 't' . $i . '@example.test',
            'phone' => '',
        ]],
        'ovrigt' => $fat,
    ];
    $ins->execute([
        $id,
        json_encode($row, JSON_UNESCAPED_UNICODE),
        jeab_kontakt_fold_text($street),
        '17730',
        't' . $i . '@example.test',
        '',
        jeab_kontakt_fold_text('Test ' . $i),
        'jarfalla',
        jeab_kontakt_fold_text('Järfälla'),
        jeab_kontakt_sort_key($street),
        jeab_kontakt_sort_key('Järfälla'),
        $now,
        $now,
    ]);
}
$lastId = 'kt_ozz0000000000001';
$lastStreet = 'Özzgatan 99';
$last = [
    'id' => $lastId,
    'street' => $lastStreet,
    'street_key' => jeab_kontakt_fold_text($lastStreet),
    'postal_code' => '17731',
    'city' => 'Järfälla',
    'company' => 'jarfalla',
    'owners' => [[
        'name' => 'Östen Sist',
        'contact' => true,
        'personnummer' => '',
        'email' => 'osten.sist@example.test',
        'phone' => '0701000001',
    ]],
    'ovrigt' => $fat,
];
$ins->execute([
    $lastId,
    json_encode($last, JSON_UNESCAPED_UNICODE),
    jeab_kontakt_fold_text($lastStreet),
    '17731',
    'osten.sist@example.test',
    '0701000001',
    jeab_kontakt_fold_text('Östen Sist'),
    'jarfalla',
    jeab_kontakt_fold_text('Järfälla'),
    jeab_kontakt_sort_key($lastStreet),
    jeab_kontakt_sort_key('Järfälla'),
    $now,
    $now,
]);
$malmoId = 'kt_malmo00000000001';
$malmoStreet = 'Limhamnsgatan 9';
$malmo = [
    'id' => $malmoId,
    'street' => $malmoStreet,
    'street_key' => jeab_kontakt_fold_text($malmoStreet),
    'postal_code' => '21611',
    'city' => 'Malmö',
    'company' => 'malmo',
    'owners' => [[
        'name' => 'Malmö Borta',
        'contact' => true,
        'personnummer' => '',
        'email' => 'malmo.borta@example.test',
        'phone' => '',
    ]],
    'ovrigt' => $fat,
];
$ins->execute([
    $malmoId,
    json_encode($malmo, JSON_UNESCAPED_UNICODE),
    jeab_kontakt_fold_text($malmoStreet),
    '21611',
    'malmo.borta@example.test',
    '',
    jeab_kontakt_fold_text('Malmö Borta'),
    'malmo',
    jeab_kontakt_fold_text('Malmö'),
    jeab_kontakt_sort_key($malmoStreet),
    jeab_kontakt_sort_key('Malmö'),
    $now,
    $now,
]);
$pdo->commit();
kt(jeab_settings_put($pdo, 'sqlite', 'contacts_list_cols_v1', 'ok'), 'listkolumnerna är redan fyllda');

$linked = $store->insertReminder([
    'title' => 'Tidtagning batteri',
    'customer_name' => 'Östen Sist',
    'address' => $lastStreet,
    'company' => 'jarfalla',
    'due_at' => '2026-10-22 09:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$lastId, (int) $linked['id']]);
for ($i = 0; $i < 80; $i++) {
    $store->insertReminder([
        'title' => 'Brus ' . $i,
        'customer_name' => 'Annan',
        'company' => 'jarfalla',
        'contact_id' => sprintf('kt_%016x', ($i % 200) + 1),
        'due_at' => '2026-10-22 10:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}
jeab_purchase_put([
    'reminder_id' => (int) $linked['id'],
    'contact_id' => $lastId,
    'company' => 'jarfalla',
    'kind' => 'batteri',
    'updated_at' => $now,
]);
$noise = $pdo->prepare('INSERT INTO purchases (reminder_id, payload, updated_at, contact_id, contact_indexed) VALUES (?, ?, ?, ?, 1)');
for ($i = 0; $i < 400; $i++) {
    $cid = sprintf('kt_%016x', ($i % 200) + 1);
    $payload = json_encode([
        'reminder_id' => 5000 + $i,
        'contact_id' => $cid,
        'company' => 'jarfalla',
        'kind' => 'laddbox',
        'ovrigt' => $fat,
    ], JSON_UNESCAPED_UNICODE);
    $noise->execute([5000 + $i, $payload, $now, $cid]);
}
kt(jeab_settings_put($pdo, 'sqlite', 'purchases_contact_id_v1', 'ok'), 'orderindexet är redan fyllt');

$GLOBALS['_JEAB_CONTACTS_SQL_ALL'] = 0;
$GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] = 0;
$error = null;
$flash = null;
$_GET = ['p' => 'kontakter', 'id' => $lastId, 'co' => 'jarfalla'];
$started = hrtime(true);
$html = require dirname(__DIR__) . '/src/pages/kontakter.php';
$elapsedMs = (hrtime(true) - $started) / 1e6;
kt(is_string($html), 'detaljsidan renderas');
echo 'TIME detalj+lista ' . round($elapsedMs, 1) . " ms\n";
kt($elapsedMs < 500, 'sidan är under 500 ms (' . round($elapsedMs, 1) . ' ms)');
kt((int) $GLOBALS['_JEAB_CONTACTS_SQL_ALL'] === 0, 'hela kontakttabellen läses inte');
kt((int) $GLOBALS['_JEAB_PURCHASE_LOAD_ALL'] === 0, 'hela ordertabellen läses inte');
kt(str_contains($html, 'data-kt-server="1"'), 'listan är SQL-sidor');
kt(str_contains($html, $lastStreet), 'detaljen visar den valda gatan');
kt(str_contains($html, 'Tidtagning batteri'), 'påminnelsen hämtas via contact_id');
kt(str_contains($html, '>Order<'), 'ordern hämtas via contact_id');
kt(!str_contains($html, $malmoStreet), 'Malmö-kontakten syns inte i Järfälla');
kt(substr_count($html, 'data-id="') === JEAB_KONTAKT_PAGE + 1, 'listan är en sida plus den öppna kontakten');
kt(str_contains($html, 'data-id="' . $lastId . '"'), 'den öppna kontakten ligger med i listan');
kt(str_contains($html, 'data-id="' . sprintf('kt_%016x', 1) . '"'), 'första sidan börjar med Aagatan 1');

$before = (int) $GLOBALS['_JEAB_CONTACTS_SQL_ALL'];
$found = jeab_kontakter_view($store, 'ozzgatan', 'alla', 'jarfalla', JEAB_KONTAKT_PAGE);
kt((int) $GLOBALS['_JEAB_CONTACTS_SQL_ALL'] === $before, 'sökningen läser inte hela tabellen');
kt($found['total'] === 1 && isset($found['rows'][$lastId]), 'sökningen träffar en rad via SQL');
$one = jeab_kontakt_get($lastId);
kt(is_array($one) && (string) ($one['street'] ?? '') === $lastStreet, 'en kontakt hämtas med id');

$looseId = 'kt_loose00000000001';
$looseStreet = 'Tombolag 3';
$loose = [
    'id' => $looseId,
    'street' => $looseStreet,
    'city' => 'Järfälla',
    'company' => '',
    'owners' => [['name' => 'Utan Bolag', 'contact' => true, 'email' => '', 'phone' => '']],
];
$ins->execute([
    $looseId,
    json_encode($loose, JSON_UNESCAPED_UNICODE),
    jeab_kontakt_fold_text($looseStreet),
    '',
    '',
    '',
    jeab_kontakt_fold_text('Utan Bolag'),
    '',
    jeab_kontakt_fold_text('Järfälla'),
    jeab_kontakt_sort_key($looseStreet),
    jeab_kontakt_sort_key('Järfälla'),
    $now,
    $now,
]);
$looseJar = jeab_kontakter_view($store, 'tombolag', 'alla', 'jarfalla', JEAB_KONTAKT_PAGE);
$looseMal = jeab_kontakter_view($store, 'tombolag', 'alla', 'malmo', JEAB_KONTAKT_PAGE);
kt(isset($looseJar['rows'][$looseId]) && isset($looseMal['rows'][$looseId]), 'kontakt utan bolag syns i båda kontoren');

$ownedId = 'kt_owned00000000001';
$ownedStreet = 'Bara Malmö 4';
$owned = [
    'id' => $ownedId,
    'street' => $ownedStreet,
    'city' => 'Malmö',
    'company' => '',
    'owners' => [['name' => 'Kopplad Malmö', 'contact' => true, 'email' => '', 'phone' => '']],
];
$ins->execute([
    $ownedId,
    json_encode($owned, JSON_UNESCAPED_UNICODE),
    jeab_kontakt_fold_text($ownedStreet),
    '',
    '',
    '',
    jeab_kontakt_fold_text('Kopplad Malmö'),
    '',
    jeab_kontakt_fold_text('Malmö'),
    jeab_kontakt_sort_key($ownedStreet),
    jeab_kontakt_sort_key('Malmö'),
    $now,
    $now,
]);
$ownedRem = $store->insertReminder([
    'title' => 'Bara malmö',
    'customer_name' => 'Kopplad Malmö',
    'company' => 'malmo',
    'due_at' => '2026-10-22 11:00:00',
    'created_at' => $now,
    'updated_at' => $now,
]);
$pdo->prepare('UPDATE reminders SET contact_id = ? WHERE id = ?')->execute([$ownedId, (int) $ownedRem['id']]);
$ownedJar = jeab_kontakter_view($store, 'bara malmo', 'alla', 'jarfalla', JEAB_KONTAKT_PAGE);
$ownedMal = jeab_kontakter_view($store, 'bara malmo', 'alla', 'malmo', JEAB_KONTAKT_PAGE);
kt(!isset($ownedJar['rows'][$ownedId]) && isset($ownedMal['rows'][$ownedId]), 'kopplat bolag vinner över tomt bolagsfält');

$pageJs = (string) file_get_contents(dirname(__DIR__) . '/src/pages/kontakter.php');
$inputAt = strpos($pageJs, "input.addEventListener('input'");
$inputFn = $inputAt === false ? '' : substr($pageJs, $inputAt, 700);
kt(str_contains($pageJs, 'event.stopPropagation()') && str_contains($pageJs, "event.target !== input"), 'första tecknet stoppas inte av ett dokumentlyssnare');
kt(str_contains($inputFn, 'setTimeout') && strpos($inputFn, 'clearBtn.hidden') > strpos($inputFn, 'setTimeout'), 'krysset ritas om efter tecknet');
kt(str_contains($inputFn, 'location.assign'), 'SQL-listan söker på servern efter tecknet');

$app = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
kt(str_contains($app, "document.body.classList.contains('page-kontakter')"), 'påminnelsenavigeringen lämnar kontaktsidan');
$focusAt = strpos($app, "matchMedia('(max-width: 480px)')");
$focus = $focusAt === false ? '' : substr($app, $focusAt, 1200);
kt(str_contains($focus, 'openReminderRow(row)') && str_contains($focus, 'field.focus'), 'första trycket på fältet öppnar raden och behåller fokus');

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/paminnelser.css');
kt(preg_match('/@media \(max-width: 480px\) \{.*?\.item:not\(\.is-open\) \.item-head-open[^}]*display:\s*none !important;/s', $css) === 1, 'stängt kort på smal skärm visar inte formulärets höjd');

echo "kontakter page timing ok\n";
