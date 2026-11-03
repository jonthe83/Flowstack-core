<?php

declare(strict_types=1);

/** Listan får inte läsa varje mejl. Personallistorna byggs en gång. */

$tmp = sys_get_temp_dir() . '/jeab-klass-face-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function fp_ok(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$threads = [];
for ($i = 0; $i < 5000; $i++) {
    $messages = [];
    for ($n = 0; $n < 5; $n++) {
        $own = $n === 4;
        $messages[] = [
            'id' => $i . '-' . $n,
            'from' => $own ? 'saljare4@jarfalla.example.test' : 'kund' . $i . '@gmail.com',
            'from_name' => $own ? 'Elin' : 'Kund ' . $i,
            'subject' => 'Hej',
            'body' => 'Kort meddelande.',
            'received_at' => sprintf('2026-10-%02dT10:%02d:00+02:00', ($n % 28) + 1, $n),
            'labels' => ['type' => $i % 5 === 0 ? 'Information' : 'Oklassad', 'intent' => '', 'confidence' => 90],
        ];
    }
    $threads[] = [
        'id' => 't' . $i,
        'status' => 'open',
        'labels' => $messages[4]['labels'],
        'tags' => [],
        'first' => $messages[0],
        'last' => $messages[4],
        'messages' => $messages,
    ];
}

$GLOBALS['_JEAB_KLASSNING_FACE_SCANS'] = 0;
$beforeBuilds = (int) ($GLOBALS['_JEAB_KLASSNING_STAFF_BUILDS'] ?? 0);
$started = hrtime(true);
foreach ($threads as $thread) {
    jeab_inkorg_folder_thread($thread, 'inkorg');
    jeab_inkorg_folder_thread($thread, 'information');
}
jeab_inkorg_klassning_contacts_prefetch($threads, true);
$folderSeconds = (hrtime(true) - $started) / 1e9;
fp_ok((int) $GLOBALS['_JEAB_KLASSNING_FACE_SCANS'] === 0, 'listan och förhandshämtningen läser inte hela tråden');
fp_ok($folderSeconds < 1, '5000 ärenden i listan tar under en sekund, fick ' . round($folderSeconds, 3) . ' s');

$one = jeab_inkorg_klassning_face($threads[0]);
fp_ok((string) ($one['from'] ?? '') === 'kund0@gmail.com' && (int) $GLOBALS['_JEAB_KLASSNING_FACE_SCANS'] === 1, 'ett enskilt ärende klassas på kundens senaste inkommande mejl');
fp_ok(jeab_inkorg_klassning_own(['from' => 'kund0@gmail.com', 'from_name' => 'Kund 0']) === false, 'gmail-kund är inte personal');
fp_ok(jeab_inkorg_klassning_own(['from' => 'demo.person@example.invalid', 'from_name' => 'Sam Exempel']) === true, 'självtestets gmail-adress är personal');
fp_ok(!isset(jeab_inkorg_klassning_staff_sets()['domains']['gmail.com']), 'gmail.com är inte en personaldomän');

$started = hrtime(true);
foreach ($threads as $thread) {
    jeab_inkorg_klassning_face($thread);
}
$faceSeconds = (hrtime(true) - $started) / 1e9;
$builds = (int) ($GLOBALS['_JEAB_KLASSNING_STAFF_BUILDS'] ?? 0);
fp_ok($faceSeconds < 1, '5000 ärenden i face tar under en sekund, fick ' . round($faceSeconds, 3) . ' s');
fp_ok($builds === $beforeBuilds + 1 || ($beforeBuilds === 0 && $builds === 1), 'personallistorna byggs en gång, fick ' . $builds);
fp_ok((int) $GLOBALS['_JEAB_KLASSNING_FACE_SCANS'] === 5000, 'omklassningens face läser varje ärende en gång');
