<?php

declare(strict_types=1);

/**
 * H-REM-2. «Visa fler» skickar serverns next-cursor på knappen,
 * inte antalet .item i DOM.
 */

function rmo_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/assets/js/app.js');
$at = strpos($js, 'function bindReminderMore(');
$end = strpos($js, "\n  function ", $at === false ? 0 : $at + 10);
$fn = ($at === false || $end === false) ? '' : substr($js, $at, $end - $at);
rmo_assert($fn !== '', 'hittade Visa fler');
rmo_assert(str_contains($fn, 'jeabReminderMoreOffset') && str_contains($fn, "btn.getAttribute('data-offset')"), 'klicket läser data-offset');
rmo_assert(!str_contains($fn, "querySelectorAll('.item')"), 'klicket räknar inte korten i listan');
rmo_assert(str_contains($js, "more.setAttribute('data-offset', String(payload.next))"), 'svaret skriver nästa cursor på knappen');

$page = (string) file_get_contents($root . '/src/pages/reminders.php');
rmo_assert(str_contains($page, 'data-js="reminder-more"') && str_contains($page, 'data-offset="<?= $visibleNext ?>"'), 'första knappen bär serverns next');

$opt = (string) file_get_contents($root . '/assets/js/optimistic.js');
rmo_assert(str_contains($opt, 'function jeabReminderMoreOffset(') && str_contains($opt, 'root.jeabReminderMoreOffset = jeabReminderMoreOffset'), 'offset-hjälpen exporteras');

$node = trim((string) shell_exec('command -v node'));
rmo_assert($node !== '', 'node finns');
$script = <<<'JS'
const m = require(process.argv[2]);
function eq(got, want, label) {
  if (String(got) !== String(want)) {
    console.error(label + ' fick ' + got);
    process.exit(1);
  }
}
eq(m.jeabReminderMoreOffset('50'), '50', 'cursor 50');
eq(m.jeabReminderMoreOffset(' 100 '), '100', 'mellanslag');
eq(m.jeabReminderMoreOffset(null), '0', 'saknas');
eq(m.jeabReminderMoreOffset(''), '0', 'tom');
eq(m.jeabReminderMoreOffset('12kort'), '0', 'inte ett tal');
eq(m.jeabReminderMoreOffset('0007'), '7', 'nollor');
console.log('offset ok');
JS;
$tmp = tempnam(sys_get_temp_dir(), 'jeab-more-');
if ($tmp === false) {
    fwrite(STDERR, "FAIL tempfil\n");
    exit(1);
}
file_put_contents($tmp, $script);
$out = [];
$code = 0;
exec(escapeshellarg($node) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($root . '/assets/js/optimistic.js') . ' 2>&1', $out, $code);
@unlink($tmp);
rmo_assert($code === 0, 'offset-hjälpen (' . trim(implode("\n", $out)) . ')');

echo "ALLA OK\n";
