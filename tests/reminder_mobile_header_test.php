<?php

declare(strict_types=1);

/**
 * Påminnelser, stängt kort under 900px.
 * Adress över namn, ellips, ingen bockring.
 * Samma mobilbrytpunkt som sidan (max-width 899px): telefon och båda datumen
 * är dolda, siffran är ett stort märke. Gäller 375, 390, 393, 430 och 899.
 * Öppet kort: datumet ligger på en egen rad. Adressfältet tar hela kortbredden
 * och radbryts. Skrivbordets min-width 900px låses med hash, utan git.
 */

$tmp = sys_get_temp_dir() . '/jeab-rem-mobile-head-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_GET = ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'today'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION['jeab_email'] = 'agenter@flowstack.example.test';

function mh_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function mh_media_matches(string $query, int $width): bool
{
    if (!preg_match_all('/\((min|max)-width:\s*(\d+)px\)/', $query, $parts, PREG_SET_ORDER)) {
        return false;
    }
    foreach ($parts as $part) {
        $limit = (int) $part[2];
        if ($part[1] === 'max' && $width > $limit) {
            return false;
        }
        if ($part[1] === 'min' && $width < $limit) {
            return false;
        }
    }
    return true;
}

/** @return array<string, array<string, string>> */
function mh_cascade(string $css, int $width): array
{
    $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    $rules = [];
    $len = strlen($css);
    $i = 0;
    $takeRules = static function (string $chunk) use (&$rules): void {
        $n = strlen($chunk);
        $j = 0;
        while ($j < $n) {
            while ($j < $n && ctype_space($chunk[$j])) {
                $j++;
            }
            if ($j >= $n) {
                break;
            }
            if ($chunk[$j] === '@' || $chunk[$j] === '}') {
                break;
            }
            $brace = strpos($chunk, '{', $j);
            if ($brace === false) {
                break;
            }
            $selector = trim(preg_replace('/\s+/', ' ', substr($chunk, $j, $brace - $j)) ?? '');
            $j = $brace + 1;
            $depth = 1;
            $start = $j;
            while ($j < $n && $depth > 0) {
                if ($chunk[$j] === '{') {
                    $depth++;
                } elseif ($chunk[$j] === '}') {
                    $depth--;
                }
                $j++;
            }
            $body = substr($chunk, $start, $j - $start - 1);
            if ($selector !== '' && !str_starts_with($selector, '@')) {
                $rules[] = [$selector, $body];
            }
        }
    };
    while ($i < $len) {
        while ($i < $len && ctype_space($css[$i])) {
            $i++;
        }
        if ($i >= $len) {
            break;
        }
        if (substr($css, $i, 6) === '@media') {
            $brace = strpos($css, '{', $i);
            if ($brace === false) {
                break;
            }
            $query = substr($css, $i, $brace - $i);
            $i = $brace + 1;
            $depth = 1;
            $start = $i;
            while ($i < $len && $depth > 0) {
                if ($css[$i] === '{') {
                    $depth++;
                } elseif ($css[$i] === '}') {
                    $depth--;
                }
                $i++;
            }
            if (mh_media_matches($query, $width)) {
                $takeRules(substr($css, $start, $i - $start - 1));
            }
            continue;
        }
        if ($css[$i] === '@') {
            $brace = strpos($css, '{', $i);
            $semi = strpos($css, ';', $i);
            if ($brace !== false && ($semi === false || $brace < $semi)) {
                $i = $brace + 1;
                $depth = 1;
                while ($i < $len && $depth > 0) {
                    if ($css[$i] === '{') {
                        $depth++;
                    } elseif ($css[$i] === '}') {
                        $depth--;
                    }
                    $i++;
                }
            } else {
                $i = $semi === false ? $len : $semi + 1;
            }
            continue;
        }
        $brace = strpos($css, '{', $i);
        if ($brace === false) {
            break;
        }
        $end = $brace + 1;
        $depth = 1;
        while ($end < $len && $depth > 0) {
            if ($css[$end] === '{') {
                $depth++;
            } elseif ($css[$end] === '}') {
                $depth--;
            }
            $end++;
        }
        $takeRules(substr($css, $i, $end - $i));
        $i = $end;
    }
    $out = [];
    foreach ($rules as [$selectorList, $body]) {
        if (!preg_match_all('/([A-Za-z-]+)\s*:\s*([^;]+);/', $body, $decls, PREG_SET_ORDER)) {
            continue;
        }
        foreach (explode(',', $selectorList) as $selector) {
            $selector = trim($selector);
            if ($selector === '') {
                continue;
            }
            foreach ($decls as $decl) {
                $out[$selector][$decl[1]] = trim(preg_replace('/\s+/', ' ', $decl[2]) ?? $decl[2]);
            }
        }
    }
    return $out;
}

function mh_decl(array $cascade, string $selector, string $prop): ?string
{
    return $cascade[$selector][$prop] ?? null;
}

function mh_spec(string $selector): array
{
    $classes = preg_match_all('/\.[A-Za-z0-9_-]+/', $selector);
    $tmp = preg_replace('/\.[A-Za-z0-9_-]+/', '', $selector) ?? $selector;
    $tmp = preg_replace('/:(?:not|has|is|where)\([^)]*\)/', '', $tmp) ?? $tmp;
    $elements = preg_match_all('/[A-Za-z][\w-]*/', $tmp);
    return [$classes, $elements];
}

function mh_media_blocks(string $css, string $needle): string
{
    $out = '';
    $len = strlen($css);
    $i = 0;
    while (($at = strpos($css, '@media', $i)) !== false) {
        $brace = strpos($css, '{', $at);
        if ($brace === false) {
            break;
        }
        $query = substr($css, $at, $brace - $at);
        $i = $brace + 1;
        $depth = 1;
        while ($i < $len && $depth > 0) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
            }
            $i++;
        }
        if (str_contains($query, $needle)) {
            $out .= substr($css, $at, $i - $at) . "\n";
        }
    }
    return $out;
}

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/assets/css/paminnelser.css');
$js = (string) file_get_contents($root . '/assets/js/app.js');

$copy = 'html body.page-reminders:not(.page-kontakter) .apple-list .item:not(.is-open) .item-shut-copy';
$line = 'html body.page-reminders:not(.page-kontakter) .apple-list .item:not(.is-open) .item-shut-addr';
$name = 'html body.page-reminders:not(.page-kontakter) .apple-list .item:not(.is-open) .item-shut-name';
$check = 'html body.page-reminders:not(.page-kontakter) .apple-list .item:not(.is-open) > .check-form';
$meta = 'html body.page-reminders:not(.page-kontakter) .apple-list .item:not(.is-open) .item-shut-meta:has(.item-call)';
$due = $meta . ' .item-due-chip';
$dueOld = 'html body.page-reminders:not(.page-kontakter) .apple-list .item:not(.is-open) .item-shut-meta .item-due-chip';
$badge = $meta . ' .item-tap-total';
$call = $meta . ' .item-call';
$arrived = 'html body.page-reminders:not(.page-kontakter) .apple-list .item:not(.is-open) .item-arrived';
$openAddr = 'html body.page-reminders:not(.page-kontakter) .apple-list .item.is-open .item-addr-row';
$openDue = $openAddr . ' button.item-due-chip';
$openInput = $openAddr . ' textarea.item-title';
$openStack = 'html body.page-reminders:not(.page-kontakter) .apple-list .item.is-open > .item-stack';
$openItem = 'html body.page-reminders:not(.page-kontakter) .apple-list .item.is-open';

foreach ([375, 390, 393, 430, 899] as $width) {
    $cascade = mh_cascade($css, $width);
    mh_assert($width < 900, $width . ' px är under skrivbordets 900');
    mh_assert(mh_decl($cascade, $copy, 'flex-direction') === 'column', $width . ' px: namnet ligger på raden under adressen');
    mh_assert(mh_decl($cascade, $line, 'overflow') === 'hidden' && mh_decl($cascade, $line, 'text-overflow') === 'ellipsis' && mh_decl($cascade, $line, 'white-space') === 'nowrap', $width . ' px: adressen får ellips');
    mh_assert(mh_decl($cascade, $name, 'overflow') === 'hidden' && mh_decl($cascade, $name, 'text-overflow') === 'ellipsis' && mh_decl($cascade, $name, 'white-space') === 'nowrap', $width . ' px: namnet får ellips');
    mh_assert(mh_decl($cascade, $check, 'display') === 'none !important', $width . ' px: bockringen är dold');
    mh_assert(mh_decl($cascade, $meta, 'display') === 'flex' && mh_decl($cascade, $meta, 'width') === 'auto' && mh_decl($cascade, $meta, 'grid-template-columns') === 'none', $width . ' px: högerkolumnen är bara märket');
    mh_assert(mh_decl($cascade, $due, 'display') === 'none !important' && mh_decl($cascade, $dueOld, 'display') === 'none !important', $width . ' px: det röda och grå korta datumet är dolt');
    mh_assert(mh_decl($cascade, $arrived, 'display') === 'none !important', $width . ' px: inkomstdatumet är dolt på stängt kort');
    mh_assert(mh_decl($cascade, $call, 'display') === 'none !important', $width . ' px: telefonsymbolen är dold');
    mh_assert(mh_decl($cascade, $badge, 'font-size') === '28px' && mh_decl($cascade, $badge, 'height') === '44px' && mh_decl($cascade, $badge, 'min-width') === '44px' && mh_decl($cascade, $badge, 'border-radius') === '999px' && mh_decl($cascade, $badge, 'transform') === 'none', $width . ' px: siffran är ett stort märke');
    mh_assert(mh_decl($cascade, $openItem, 'flex-wrap') === 'wrap', $width . ' px: bockringen tar ingen bredd från adressen');
    mh_assert(mh_decl($cascade, $openStack, 'width') === '100%' && mh_decl($cascade, $openStack, 'min-width') === '0' && mh_decl($cascade, $openStack, 'flex') === '1 1 100%', $width . ' px: kortets innehåll är hela bredden');
    mh_assert(mh_decl($cascade, $openAddr, 'flex-direction') === 'column' && mh_decl($cascade, $openAddr, 'width') === '100%' && mh_decl($cascade, $openAddr, 'min-width') === '0', $width . ' px: öppet kort staplar datum och adress');
    mh_assert(mh_decl($cascade, $openDue, 'order') === '-1' && mh_decl($cascade, $openDue, 'width') === '100%' && mh_decl($cascade, $openDue, 'white-space') === 'nowrap', $width . ' px: det långa datumet har en egen rad');
    mh_assert(mh_decl($cascade, $openInput, 'width') === '100%' && mh_decl($cascade, $openInput, 'max-width') === '100%' && mh_decl($cascade, $openInput, 'min-width') === '0', $width . ' px: adressfältet får hela bredden');
    mh_assert(mh_decl($cascade, $openInput, 'white-space') === 'pre-wrap' && mh_decl($cascade, $openInput, 'field-sizing') === 'content' && mh_decl($cascade, $openInput, 'height') === 'auto', $width . ' px: adressen radbryts i fältet');
}

$desk = mh_cascade($css, 900);
mh_assert(mh_decl($desk, $copy, 'flex-direction') === null, '900 px: namn och adress staplas inte av mobilregeln');
mh_assert(mh_decl($desk, $check, 'display') === null, '900 px: bockringen döljs inte');
mh_assert(mh_decl($desk, $badge, 'border-radius') === null && mh_decl($desk, $badge, 'font-size') === null, '900 px: siffran blir inte det stora märket');
mh_assert(mh_decl($desk, $call, 'display') === null && mh_decl($desk, $due, 'display') === null && mh_decl($desk, $arrived, 'display') === null, '900 px: telefon och datum döljs inte');
mh_assert(mh_decl($desk, $openAddr, 'flex-direction') === null && mh_decl($desk, $openDue, 'width') === null, '900 px: öppet kort delar rad mellan adress och datum');
mh_assert(mh_decl($desk, $openInput, 'white-space') === null && mh_decl($desk, $openInput, 'width') === null, '900 px: mobilens radbrytning gäller inte adressfältet');
mh_assert(mh_decl($desk, 'html body.page-reminders .check-form', 'display') === 'flex', '900 px: bockringen är kvar i grundregeln');

$ours900 = mh_media_blocks($css, 'min-width: 900px');
mh_assert($ours900 !== '' && hash('sha256', $ours900) === '213fb0959977c4471b959ea4a807b2a669f7aff563c13f9914049aa1d2b8be99', 'skrivbordets 900px-block är oförändrade');
mh_assert(str_contains($ours900, '.pam-board-list .item-arrived'), 'skrivbordets listrad döljer fortfarande inkomstdatumet');

$now = jeab_now();
$row = [
    'id' => 14,
    'title' => 'Norrskensvägen 12',
    'address' => 'Norrskensvägen 12, Järfälla',
    'customer_name' => 'Ahmet Erzurum',
    'due_at' => '2026-10-14 09:00:00',
    'phone' => '0701112233',
    'mailat' => 1,
    'company' => 'jarfalla',
    'created_at' => $now,
    'updated_at' => $now,
];
$html = jeab_reminder_row($row, false);
$addrAt = strpos($html, 'class="item-shut-addr"');
$nameAt = strpos($html, 'class="item-shut-name"');
$metaAt = strpos($html, 'class="item-shut-meta"');
mh_assert($addrAt !== false && $nameAt !== false && $addrAt < $nameAt, 'markup: adressen står före namnet');
mh_assert(str_contains($html, 'class="check-form"') && str_contains($html, 'class="check"'), 'markup: bockringen finns kvar och döljs med CSS');
mh_assert(preg_match('#<div class="item-shut-meta">\s*<span class="item-due-chip">.*?<span class="item-tap-total"[^>]*>1</span>.*?<a class="item-call"#s', $html) === 1, 'markup: siffran 1 sitter hos telefonlänken');
mh_assert($metaAt !== false && $nameAt < $metaAt, 'markup: namnet ligger i rubriken, inte i telefonkolumnen');
mh_assert(str_contains($html, 'Ahmet Erzurum') && str_contains($html, 'Norrskensvägen 12'), 'markup: namn och adress ritas ut');

$openAt = strpos($js, 'function openReminderRow');
$collapseAt = $openAt === false ? false : strpos($js, 'collapseOtherReminderCards(row)', $openAt);
$earlyAt = $openAt === false ? false : strpos($js, "row.classList.contains('is-open')", $openAt);
mh_assert($collapseAt !== false && $earlyAt !== false && $collapseAt < $earlyAt, 'öppning stänger andra kort innan ett redan öppet kort lämnas');
mh_assert(str_contains($js, "matchMedia('(max-width: 899px)')"), 'ett öppet kort gäller under 900px');
mh_assert(str_contains($js, 'function reminderMobileList') && str_contains($js, "classList.contains('page-kontakter')"), 'kontakter använder inte påminnelsens dragspel');
mh_assert(str_contains($js, "querySelectorAll('.apple-list .item.is-open')") && str_contains($js, 'closeOpenReminder({ history: false, row: other })'), 'övriga öppna kort stängs utan history.back');
mh_assert(str_contains($js, 'function closeOpenReminder(opts)') && str_contains($js, 'opts?.row || currentOpenRow()'), 'vanlig stängning tar fortfarande det öppna kortet');

echo "ALLA OK\n";
