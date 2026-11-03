<?php

declare(strict_types=1);

/** Stäng en påminnelse och rensa säkra rader. Inget mejl. */

$tmp = sys_get_temp_dir() . '/jeab-rem-close-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function rc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$mail = 0;
$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $opts) use (&$mail): array {
    $mail++;
    $GLOBALS['_SENT'][] = $opts;
    return ['ok' => true];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts) use (&$mail): array {
    unset($opts);
    $mail++;
    return ['ok' => true];
};

$note = "Behåll den här raden.\n"
    . "Offert skickades inte automatiskt: testadress.\n"
    . "mail: https://flowstack.example.test/index.php?page=inkorg&id=autotest-abc123\n"
    . "Ring om autotest-def456 imorgon.";
$row = jeab_create_reminder(jeab_store(), 'Vera Holm – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Vera Holm',
    'email' => 'vera.holm.close@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => $note,
]);
$id = (int) $row['id'];
$audit = jeab_inkorg_dir() . '/reminder-hook.jsonl';

$dryClose = jeab_inkorg_hook(['action' => 'inbox.reminder_close', 'id' => $id]);
$still = jeab_get_reminder(jeab_store(), $id);
rc(!empty($dryClose['dry']) && ($dryClose['status'] ?? '') === 'closed' && ($dryClose['changed'] ?? 0) === 1 && ($dryClose['would_send'] ?? null) === false && is_array($still) && empty($still['completed_at']), 'torrkörning av close skriver inget');

$closed = jeab_inkorg_hook(['action' => 'inbox.reminder_close', 'id' => $id, 'dry' => 0]);
$now = jeab_get_reminder(jeab_store(), $id);
rc(($closed['status'] ?? '') === 'closed' && ($closed['changed'] ?? 0) === 1 && ($closed['would_send'] ?? null) === false && is_array($now) && !empty($now['completed_at']) && (string) ($now['person'] ?? '') === 'Roberto', 'close stänger utan att byta ägare');
$stamp = (string) ($now['completed_at'] ?? '');
$again = jeab_inkorg_hook(['action' => 'inbox.reminder_close', 'id' => $id, 'dry' => 0]);
$againRow = jeab_get_reminder(jeab_store(), $id);
rc(($again['changed'] ?? 1) === 0 && is_array($againRow) && (string) ($againRow['completed_at'] ?? '') === $stamp, 'close en gång till ändrar inget');
$closeLines = is_file($audit) ? count(array_filter(array_map('trim', file($audit) ?: []))) : 0;
rc($closeLines === 1 && str_contains((string) file_get_contents($audit), '"action":"close"'), 'close skriver en rad i historiken');

$bad = jeab_inkorg_hook(['action' => 'inbox.reminder_clean', 'id' => $id, 'patterns' => ['.*'], 'dry' => 0]);
$untouched = jeab_get_reminder(jeab_store(), $id);
rc(empty($bad['ok']) && ($bad['error'] ?? '') === 'okänt mönster' && is_array($untouched) && str_contains((string) ($untouched['note'] ?? ''), 'Offert skickades inte automatiskt:'), 'okänt mönster skriver inget');

$dryClean = jeab_inkorg_hook(['action' => 'inbox.reminder_clean', 'id' => $id, 'patterns' => ['offert_skipped', 'autotest']]);
$preview = jeab_get_reminder(jeab_store(), $id);
rc(!empty($dryClean['dry']) && ($dryClean['changed'] ?? 0) === 1 && ($dryClean['would_send'] ?? null) === false && count((array) ($dryClean['removed'] ?? [])) === 3 && is_array($preview) && str_contains((string) ($preview['note'] ?? ''), 'Offert skickades inte automatiskt:'), 'torrkörning av clean visar raderna');

$cleaned = jeab_inkorg_hook(['action' => 'inbox.reminder_clean', 'id' => $id, 'patterns' => 'offert_skipped, autotest', 'dry' => 0]);
$fresh = jeab_get_reminder(jeab_store(), $id);
$freshNote = (string) ($fresh['note'] ?? '');
rc(
    ($cleaned['changed'] ?? 0) === 1
    && ($cleaned['would_send'] ?? null) === false
    && ($cleaned['status'] ?? '') === 'closed'
    && is_array($fresh)
    && !empty($fresh['completed_at'])
    && str_contains($freshNote, 'Behåll den här raden.')
    && str_contains($freshNote, 'Ring om imorgon.')
    && !str_contains($freshNote, 'Offert skickades inte automatiskt:')
    && !str_contains($freshNote, 'autotest-'),
    'clean tar bort offertraden och autotest-länkarna'
);
$second = jeab_inkorg_hook(['action' => 'inbox.reminder_clean', 'id' => $id, 'dry' => 0]);
$secondRow = jeab_get_reminder(jeab_store(), $id);
rc(($second['changed'] ?? 1) === 0 && (string) ($secondRow['note'] ?? '') === $freshNote, 'clean en gång till ändrar inget');
$all = array_filter(array_map('trim', is_file($audit) ? (file($audit) ?: []) : []));
rc(count($all) === 2 && substr_count((string) file_get_contents($audit), '"action":"clean"') === 1, 'clean skriver en rad i historiken');
rc($mail === 0 && $GLOBALS['_SENT'] === [], 'varken close eller clean skickar mejl');

echo "OK\n";
