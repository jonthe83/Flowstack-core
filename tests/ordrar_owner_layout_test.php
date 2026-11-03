<?php

declare(strict_types=1);

/**
 * Ägarkort i Bekräfta → Ändra ska hålla namn, personnummer och adress inne i kortet.
 * Tillhör-väljaren i sidhuvudet delar klassnamn och ska ligga kvar på en rad.
 */

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/assets/css/ordrar-v3.css');
$php = (string) file_get_contents($root . '/src/ordrar_v3.php');

function ol_assert(bool $ok, string $message): void
{
    if ($ok) {
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

ol_assert(
    !preg_match('/\.ov3 \.v3-owner \{[^}]*display:\s*flex;\s*align-items:\s*center/', $css),
    'ägarkortet ärver inte Tillhör-raden'
);
ol_assert(
    str_contains($css, '.ov3 .v3-hright > .v3-owner { display: flex; align-items: center; gap: 6px; margin: 0; }'),
    'Tillhör i sidhuvudet är kvar som rad'
);
ol_assert(
    str_contains($css, '.ov3 .v3-hright > .v3-owner select { width: auto; min-width: 7.5rem; max-width: 11rem; min-height: 30px; padding: 4px 8px; font-size: 13px; }'),
    'Tillhör-listan har samma mått'
);
$cardRule = strpos($css, '.ov3 .v3-owners > .v3-owner {');
ol_assert($cardRule !== false, 'ägarkortet har en egen regel');
$cardBody = substr($css, (int) $cardRule, 400);
ol_assert(str_contains($cardBody, 'flex-direction: column'), 'ägarkortet staplar fälten');
ol_assert(str_contains($cardBody, 'min-width: 0'), 'ägarkortet får krympa i griden');
ol_assert(str_contains($css, '.ov3 .v3-kv > * { min-width: 0; max-width: 100%; }'), 'fält i raden får min-width 0');
ol_assert(str_contains($css, '.ov3 .v3-kv input,'), 'inmatning i Ändra-läge är begränsad till raden');
ol_assert(str_contains($css, '@container v3owner (max-width: 260px)'), 'smalt kort lägger etiketten över värdet');
ol_assert(str_contains($css, 'grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr))'), 'ägargriden svämmar inte över på smal bredd');
ol_assert(str_contains($php, '<div class="v3-owners">'), 'markup har v3-owners');
ol_assert(str_contains($php, '<article class="v3-owner"'), 'ägare ritas som article.v3-owner');
ol_assert(str_contains($php, 'class="v3-owner-in"'), 'Ändra-läge skriver fälten i kortet');
ol_assert(str_contains($php, 'data-v3="huvud"') && str_contains($php, 'Huvudsökande'), 'rollknapparna är kvar');
ol_assert(str_contains($css, '.ov3 .v3-pillsw button { min-height: 44px;'), 'mobilens piller är 44 px');

echo "OK  owner layout\n";
