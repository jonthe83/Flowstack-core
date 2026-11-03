<?php

declare(strict_types=1);

/**
 * H-REM-1. Ångra efter knuff får bara flytta due_at.
 * Klar som satts efter knuffen ligger kvar även om posten har tom completed_at.
 */

$tmp = sys_get_temp_dir() . '/jeab-snooze-undo-klar-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'timezone' => 'Europe/Stockholm',
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function suk_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$past = '2026-09-01 14:30:00';
$open = jeab_create_reminder($store, 'Knuff Klar ' . bin2hex(random_bytes(3)), $past, null, null, []);
$id = (int) ($open['id'] ?? 0);
suk_assert($id > 0 && trim((string) ($open['completed_at'] ?? '')) === '', 'raden skapas öppen');

$snoozed = jeab_snooze_reminder($store, $id, '2h');
$snoozeDue = (string) ($snoozed['due_at'] ?? '');
suk_assert($snoozeDue !== '' && $snoozeDue !== $past && trim((string) ($snoozed['completed_at'] ?? '')) === '', 'knuff flyttar bara tiden');

$done = jeab_toggle_reminder($store, $id, true);
$doneAt = trim((string) ($done['completed_at'] ?? ''));
suk_assert($doneAt !== '', 'Klar efter knuffen sätter completed_at');

$post = [
    'action' => 'reminder.due',
    'id' => (string) $id,
    'due_at' => $past,
    'completed_at' => '',
];
$fields = jeab_reminder_due_fields($post, $snoozed);
suk_assert(array_keys($fields) === ['due_at'], 'due-fälten innehåller inte completed_at');
suk_assert(($fields['due_at'] ?? '') === $past, 'Ångra ber om den tidigare tiden');

$restored = jeab_update_reminder($store, $id, $fields);
suk_assert((string) ($restored['due_at'] ?? '') === $past, 'Ångra sätter tillbaka due_at');
suk_assert(trim((string) ($restored['completed_at'] ?? '')) === $doneAt, 'Klar ligger kvar efter Ångra');

$again = jeab_get_reminder($store, $id);
suk_assert(is_array($again) && (string) ($again['due_at'] ?? '') === $past && trim((string) ($again['completed_at'] ?? '')) === $doneAt, 'omläsning har samma tid och Klar');

$openDue = jeab_reminder_due_fields(['due_at' => '2026-10-02', 'completed_at' => $doneAt], $again);
$openSaved = jeab_update_reminder($store, $id, $openDue);
suk_assert(str_starts_with((string) ($openSaved['due_at'] ?? ''), '2026-10-02') && trim((string) ($openSaved['completed_at'] ?? '')) === $doneAt, 'en postad completed_at ändrar inte Klar');

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');
$caseAt = strpos($index, "case 'reminder.due':");
$caseEnd = strpos($index, "case 'reminder.update':", $caseAt === false ? 0 : $caseAt);
$case = ($caseAt === false || $caseEnd === false) ? '' : substr($index, $caseAt, $caseEnd - $caseAt);
suk_assert($case !== '' && str_contains($case, 'jeab_reminder_due_fields') && !str_contains($case, "['completed_at']"), 'reminder.due kopierar inte completed_at');

$js = (string) file_get_contents($root . '/assets/js/app.js');
$undoAt = strpos($js, 'function postUndoEntry(');
$undoEnd = strpos($js, "\n  function ", $undoAt === false ? 0 : $undoAt + 10);
$undo = ($undoAt === false || $undoEnd === false) ? '' : substr($js, $undoAt, $undoEnd - $undoAt);
suk_assert(str_contains($undo, "body.set('due_at', dueAt)") && str_contains($undo, "body.delete('completed_at')") && !str_contains($undo, "body.set('completed_at'"), 'Ångra-posten skickar bara due_at');
$rawAt = strpos($js, 'function postDueRaw(');
$rawEnd = strpos($js, "\n  function ", $rawAt === false ? 0 : $rawAt + 10);
$raw = ($rawAt === false || $rawEnd === false) ? '' : substr($js, $rawAt, $rawEnd - $rawAt);
suk_assert(str_contains($raw, "body.delete('completed_at')") && !str_contains($raw, "body.set('completed_at'"), 'misslyckad knuff återställer inte Klar');

echo "ALLA OK\n";
