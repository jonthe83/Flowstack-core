<?php

declare(strict_types=1);

/**
 * Radera ärendet verkställs direkt efter bekräftelsen. Ingen sexsekundersväntan.
 * Efteråt visas Ångra i ca 6 s som återställer ordern via ordrar.restore. Inget utskick.
 */

function du_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/assets/js/ordrar-v3.js');
$css = (string) file_get_contents($root . '/assets/css/ordrar-v3.css');

du_ok(!str_contains($js, 'setTimeout(commitPendingDelete, 6000)'), 'raderingen väntar inte sex sekunder');
$start = strpos($js, 'radera: function');
$end = strpos($js, 'gamla: function', $start === false ? 0 : $start);
$body = $start !== false && $end !== false ? substr($js, $start, $end - $start) : '';
du_ok(str_contains($body, 'commitPendingDelete();'), 'radera-handlern postar direkt');
du_ok(str_contains($js, "action: 'ordrar.restore'") && str_contains($js, "toast('Ärendet raderat.', function ()"), 'Ångra efter raderingen återställer via ordrar.restore');
du_ok(str_contains($body, 'confirmBox('), 'radera kräver fortfarande bekräftelse');

$node = trim((string) shell_exec('command -v node'));
du_ok($node !== '', 'node finns');
$run = 'env -u FORCE_COLOR ' . escapeshellarg($node) . ' ' . escapeshellarg($root . '/tests/ordrar_v3_delete_undo_run.js');
$out = [];
$code = 0;
exec($run . ' 2>&1', $out, $code);
$text = implode("\n", $out);
if ($code !== 0) {
    fwrite(STDERR, $text . "\n");
}
du_ok($code === 0, 'radera direkt, Ångra visas och återställer');
echo $text . "\n";
