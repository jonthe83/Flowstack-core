<?php

declare(strict_types=1);

/**
 * Första klicket på Ordrar ska lämna Påminnelser utan history.back().
 * Snoozens 2 s-håll och ångra rörs inte.
 */

function nav_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
$leaveAt = strpos($js, 'function leavesReminders');
$leave = $leaveAt === false ? '' : substr($js, $leaveAt, 900);
nav_assert(str_contains($leave, "link.closest('nav.tabs')"), 'modulflikar räknas som att lämna');
nav_assert(str_contains($leave, 'p=ordrar'), 'Ordrar räknas som att lämna');
nav_assert(str_contains($leave, 'p=inkorg'), 'Inkorgen räknas som att lämna');

$outAt = strpos($js, 'const onOutside');
$out = $outAt === false ? '' : substr($js, $outAt, 2200);
$snoozeAt = strpos($out, 'snoozeRowBusy(openRow)');
$leaveCall = strpos($out, 'leavesReminders(event)');
$backAt = strpos($out, 'closeOpenReminder()');
nav_assert($snoozeAt !== false && $leaveCall !== false && $snoozeAt < $leaveCall, 'snooze-håll går före navigering');
nav_assert($leaveCall !== false && $backAt !== false && $leaveCall < $backAt, 'lämna sidan sker före history.back');
nav_assert(str_contains($out, 'saveInlineForm(inline)') && str_contains($out, 'return;'), 'osparad rad sparas utan att backa');

nav_assert(str_contains($js, 'const SNOOZE_LEAVE_MS'), 'snooze-timern finns kvar');
nav_assert(str_contains($js, 'function bindSnoozeForm') && str_contains($js, 'function queueSnoozeSave'), 'snooze-håll och kö finns kvar');
$closeAt = strpos($js, 'function closeOpenReminder');
$close = $closeAt === false ? '' : substr($js, $closeAt, 1200);
nav_assert(str_contains($close, 'history.back()'), 'stängning av kortet backar fortfarande');
$bindAt = strpos($js, 'function bindSnoozeForm');
$bind = $bindAt === false ? '' : substr($js, $bindAt, 1800);
nav_assert($bind !== '' && !str_contains($bind, 'history.back'), 'snooze-bindningen backar inte historiken');
