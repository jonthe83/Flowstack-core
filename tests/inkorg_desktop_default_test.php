<?php

declare(strict_types=1);

/**
 * Dator (minst 1024 px): översta ärendet väljs automatiskt utan läsmarkering,
 * och tomt läge behåller tre fält med neutral kundpanel. Mobil/platta orörda.
 */

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
$css = (string) file_get_contents($root . '/assets/css/inkorg.css');
$fail = 0;
$ok = static function (bool $c, string $msg) use (&$fail): void {
    if (!$c) {
        $fail++;
        fwrite(STDERR, "FAIL: $msg\n");
    }
};

$p = strpos($page, 'function ixAutoOpenTop(attempt)');
$ok($p !== false, 'ixAutoOpenTop finns');
$body = $p !== false ? substr($page, $p, 4200) : '';
$ok(strpos($body, "matchMedia('(min-width: 1024px)')") !== false, 'auto-val bara från 1024 px');
$ok(strpos($body, 'read_on_open = false') !== false && strpos($body, 'mark_read = false') !== false, 'auto-val markerar inte läst');
$ok(strpos($body, 'ixPaintThread(') !== false && strpos($body, 'ixNav.epoch') !== false, 'målar tråden och kollar epoch');
$ok(!preg_match('/method\s*:\s*[\'"]POST/i', $body), 'auto-val skickar ingen POST');
$ok(substr_count($page, 'ixAutoOpenTop();') >= 3, 'anropas vid start, efter listbyte och efter bolagsbyte');
// Första inladdningen: en avbruten, upptagen eller långsam hämtning tappar inte valet.
$ok(strpos($page, 'var ixAutoDelays = [700, 1800, 4000];') !== false, 'begränsade nya försök');
$ok(strpos($body, 'ixAutoOpenTop(attempt + 1)') !== false, 'försöker igen efter fel');
$ok(strpos($body, 'attempt >= ixAutoDelays.length') !== false, 'slutar efter sista försöket');
$ok(strpos($body, "setAttribute('data-ix-auto'") !== false, 'läget syns i data-ix-auto');
$ok(strpos($body, 'top.href !== link.href') !== false, 'listbyte stoppar gamla försök');
$app = strpos($page, "if (kind === 'app') {");
$appBody = $app !== false ? substr($page, $app, 1400) : '';
$ok(strpos($appBody, 'ixAutoOpenTop();') !== false, 'bolagsbyte (app) väljer också översta');
$ok(substr_count($page, 'ix-cust-empty') >= 2, 'neutral kundpanel i både PHP- och JS-tomläget');

$m = strpos($css, 'Platta och mobil (under 1024 px) är oförändrade');
$ok($m !== false, 'CSS-blocket för dator finns');
$tail = $m !== false ? substr($css, $m) : '';
$ok(strpos($tail, '@media (min-width: 1024px)') !== false, 'tre fält i tomt läge bara från 1024 px');
$ok(strpos($tail, '#ix.ix:not(.is-open) > .ix-split') !== false, 'tomt läge använder befintliga ix-split');

if ($fail) {
    exit(1);
}
echo "OK inkorg_desktop_default\n";
