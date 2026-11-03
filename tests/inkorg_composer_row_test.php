<?php

declare(strict_types=1);

/** N-24: svarsrutans knapprad wrappar under 430px. Inget skickas. */

function n24(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function n24_media_blocks(string $css): array
{
    $blocks = [];
    $offset = 0;
    while (preg_match('/@media\s*\(([^)]*)\)\s*\{/', $css, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $start = $m[0][1];
        $headerLen = strlen($m[0][0]);
        $depth = 1;
        $i = $start + $headerLen;
        $len = strlen($css);
        while ($i < $len && $depth > 0) {
            $ch = $css[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
            }
            $i++;
        }
        $blocks[] = [
            'query' => trim($m[1][0]),
            'body' => substr($css, $start + $headerLen, $i - $start - $headerLen - 1),
            'start' => $start,
        ];
        $offset = $i;
    }
    return $blocks;
}

function n24_rule(string $body, string $selector): string
{
    $pattern = '/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/';
    if (preg_match($pattern, $body, $m) !== 1) {
        return '';
    }
    return $m[1];
}

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/assets/css/inkorg.css');
n24($css !== '', 'inkorg.css läses');

$blocks = n24_media_blocks($css);
$narrow = null;
foreach ($blocks as $block) {
    if (str_contains($block['query'], 'max-width: 430px') && str_contains($block['body'], '.ix-cfoot')) {
        $narrow = $block;
    }
}
n24($narrow !== null, '430px-blocket styr knappraden');

$cfoot = n24_rule($narrow['body'], 'body.page-inkorg .ix-v2 .ix-cfoot');
n24($cfoot !== '', '430px sätter .ix-v2 .ix-cfoot');
n24(str_contains($cfoot, 'flex-wrap: wrap'), 'knappraden wrappar under 430px');
n24(str_contains($cfoot, 'min-width: 0') && str_contains($cfoot, 'max-width: 100%'), 'knappraden krymper inom 430px');

$split = n24_rule($narrow['body'], 'body.page-inkorg .ix-v2 .ix-split');
n24($split !== '', '430px sätter .ix-v2 .ix-split');
n24(str_contains($split, 'flex-wrap: wrap') || str_contains($split, 'min-width: 0'), 'skickagruppen wrappar eller krymper');
n24(str_contains($split, 'max-width: 100%'), 'skickagruppen stannar inom bredden');

n24(str_contains($narrow['body'], '.ix-cfoot .ix-btn') && str_contains($narrow['body'], '.ix-cfoot .ix-pri') && str_contains($narrow['body'], '.ix-cfoot .ix-cc'), '430px träffar knapparna i raden');
n24(preg_match('/\.ix-cfoot \.ix-btn,\s*body\.page-inkorg \.ix-v2 \.ix-cfoot \.ix-pri,\s*body\.page-inkorg \.ix-v2 \.ix-cfoot \.ix-cc\s*\{([^}]*)\}/', $narrow['body'], $btnRule) === 1, 'knappregeln är samlad');
n24(str_contains($btnRule[1], 'min-height: 44px'), 'knapparna är minst 44 px höga');
n24(str_contains($btnRule[1], 'max-width: 100%'), 'knapparna ryms inom raden');

$desktopPos = strpos($css, 'body.page-inkorg .ix-v2 .ix-cfoot {');
n24($desktopPos !== false && $desktopPos < $narrow['start'], 'desktopregeln ligger före 430px-blocket');
$desktop = n24_rule(substr($css, $desktopPos, $narrow['start'] - $desktopPos), 'body.page-inkorg .ix-v2 .ix-cfoot');
n24(str_contains($desktop, 'display: flex') && str_contains($desktop, 'justify-content: space-between'), 'desktop behåller flex och space-between');
n24(!str_contains($desktop, 'flex-wrap') && !str_contains($desktop, 'max-width: 100%'), 'desktopregeln wrappar eller krymper inte');

$wide = null;
foreach ($blocks as $block) {
    if (str_contains($block['query'], 'max-width: 760px') && str_contains($block['body'], '.ix-v2 .ix-cfoot')) {
        $wide = $block;
    }
}
n24($wide !== null && $wide['start'] < $narrow['start'], '760px-regeln ligger före 430px');
$wideFoot = n24_rule($wide['body'], 'body.page-inkorg .ix-v2 .ix-cfoot');
n24(str_contains($wideFoot, 'display: flex') && !str_contains($wideFoot, 'flex-wrap: wrap'), '760px ändrar inte wrap');

echo "inkorg composer row ok\n";
