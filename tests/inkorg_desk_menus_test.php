<?php

declare(strict_types=1);

/**
 * Dator ≥1024px: menyerna i mittenpanelen (status, Tilldelad, ⋯ och pilen vid
 * Skicka) ritas ovanpå tråden och klipps inte av overflow: hidden.
 * Rotorsak: .ix-casehead (z-index 1) låg under .ix-vcol (z-index 1, senare i DOM)
 * och .ix-split/.ix-cmain/.ix-vcol har overflow: hidden. Menyn läggs därför med
 * position: fixed vid knappen (ixMenuPlace) och lagren lyfts (.ix-menu-lift).
 * Under 1024px är allt oförändrat: klasserna finns bara i en min-width: 1024px-block.
 */

$root = dirname(__DIR__);

function dmenu(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function dmenu_media_blocks(string $css): array
{
    $blocks = [];
    $offset = 0;
    while (preg_match('/@media\s*([^{]*)\{/', $css, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $start = $m[0][1];
        $headerLen = strlen($m[0][0]);
        $depth = 1;
        $i = $start + $headerLen;
        $len = strlen($css);
        while ($i < $len && $depth > 0) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
            }
            $i++;
        }
        $blocks[] = ['query' => trim($m[1][0]), 'body' => substr($css, $start + $headerLen, $i - $start - $headerLen - 1)];
        $offset = $i;
    }
    return $blocks;
}

$css = (string) file_get_contents($root . '/assets/css/inkorg.css');
$page = (string) file_get_contents($root . '/src/pages/inkorg.php');

// CSS: flytande meny och lyft finns bara på dator.
$floatIn = [];
$liftIn = [];
foreach (dmenu_media_blocks($css) as $b) {
    if (preg_match('/\.ix-menu\.ix-float\s*\{/', $b['body']) === 1) {
        $floatIn[] = $b['query'];
    }
    if (preg_match('/\.ix-menu-lift\s*\{/', $b['body']) === 1) {
        $liftIn[] = $b['query'];
    }
}
dmenu($floatIn === ['(min-width: 1024px)'], '.ix-menu.ix-float finns bara i @media (min-width: 1024px)');
dmenu($liftIn === ['(min-width: 1024px)'], '.ix-menu-lift finns bara i @media (min-width: 1024px)');
dmenu(preg_match_all('/\.ix-menu\.ix-float\s*\{/', $css) === 1 && preg_match_all('/\.ix-menu-lift\s*\{/', $css) === 1, 'inga regler för ix-float/ix-menu-lift utanför datorblocket');
dmenu(preg_match('/\.ix-menu\.ix-float\s*\{[^}]*position:\s*fixed;[^}]*z-index:\s*1000;/', $css) === 1, 'flytande meny: position fixed med z-index 1000');
dmenu(preg_match('/\.ix-menu\.ix-float\s*\{[^}]*overflow-y:\s*auto;/', $css) === 1, 'lång meny (Tilldelad) rullar i stället för att gå utanför fönstret');
dmenu(preg_match('/\.ix-menu-lift\s*\{[^}]*z-index:\s*50\s*!important;/', $css) === 1, 'lyfta lager får z-index 50');

// Befintliga klassnamn och grundregler orörda.
foreach (['ix-st', 'ix-as', 'ix-mo', 'ix-sd'] as $id) {
    dmenu(str_contains($page, 'data-menu-btn="' . $id . '"') && str_contains($page, 'id="' . $id . '"'), "menyn {$id} har kvar knapp och id");
}
dmenu(str_contains($page, 'class="ix-menu ix-up ix-replyonly" id="ix-sd"'), 'Skicka-menyn har kvar sina klasser');
dmenu(preg_match('/body\.page-inkorg \.ix-v2 \.ix-menu \{[^}]*position: absolute;[^}]*z-index: 20;/', $css) === 1, 'grundregeln för .ix-menu (mobil/surfplatta) är oförändrad');
dmenu(preg_match('/body\.page-inkorg \.ix-v2 \.ix-menu\.ix-up \{[^}]*bottom: 50px;/', $css) === 1, '.ix-menu.ix-up är oförändrad');

// JS: placering bara på dator, öppning via ixMenuShow, städning vid stängning.
dmenu(str_contains($page, 'function ixMenuDesk()') && str_contains($page, "window.matchMedia('(min-width: 1024px)').matches"), 'ixMenuDesk kollar min-width 1024px');
dmenu(preg_match('/function ixMenuPlace\(menu\) \{\s*if \(!menu \|\| menu\.hidden\) \{ return; \}\s*if \(!ixMenuDesk\(\)\) \{ ixMenuUnfloat\(menu\); ixMenuUnlift\(\); return; \}/', $page) === 1, 'ixMenuPlace gör ingenting under 1024px (tar bort ev. dator-stil)');
dmenu(str_contains($page, "menu.classList.add('ix-float')") && str_contains($page, "classList.add('ix-menu-lift')"), 'ixMenuPlace lägger ix-float och lyfter lager med z-index');
dmenu(str_contains($page, "if (getComputedStyle(el).zIndex !== 'auto') { el.classList.add('ix-menu-lift'); }"), 'bara föräldrar med eget z-index lyfts');
dmenu(str_contains($page, "if (open) { ixMenuShow(menu); } else { menu.hidden = true; }"), 'menyknappen öppnar via ixMenuShow');
dmenu(str_contains($page, "if (m) { closeMenus(m); ixMenuShow(m); }"), 'Parkerad utan datum visar Skicka-menyn på rätt plats');
dmenu(str_contains($page, "if (m !== except) { m.hidden = true; } });\n    if (!except) { ixMenuUnlift(); }"), 'closeMenus tar bort lyften');
dmenu(str_contains($page, "menu.closest('.ix-split')"), 'Skicka-menyn placeras mot hela delade knappen (högerkant)');
dmenu(str_contains($page, "window.addEventListener('resize', ixMenuReplace);"), 'menyn flyttas med vid storleksändring');
dmenu(preg_match("/document\.addEventListener\('scroll', function \(ev\) \{.*?ixMenuReplace\(\);\s*\}, true\);/s", $page) === 1, 'menyn flyttas med vid rullning (capture)');
dmenu(preg_match('/var menu = document\.getElementById\(b\.getAttribute\(\'data-menu-btn\'\)\);/', $page) === 1, 'knappen hittar sin meny via data-menu-btn');

// Bindningen sker i jeabIxBind, som körs efter varje omritning (ix_part/auto-open).
dmenu(substr_count($page, "jeabIxBind(document.getElementById('ix'));") >= 3, 'jeabIxBind körs efter omritning av tråd och lista');

echo "inkorg_desk_menus_test: alla OK\n";
