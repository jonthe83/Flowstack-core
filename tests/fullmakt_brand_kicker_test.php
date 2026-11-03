<?php

declare(strict_types=1);

/**
 * Överst på fullmakten: en gemensam brand-kicker för JEAB och MEAB.
 * Ingen kopia per bolag/doc-type. Övrig fullmaktstext orörd.
 */

$root = dirname(__DIR__);
require $root . '/fullmakt/lib/bootstrap.php';

function bk_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function bk_ok(string $message): void
{
    echo "OK  {$message}\n";
}

$expected = 'Demo Energi Järfälla & Demo Energi Malmö';
if (!defined('FULLMAKT_BRAND_KICKER') || FULLMAKT_BRAND_KICKER !== $expected) {
    bk_fail('FULLMAKT_BRAND_KICKER saknas eller har fel värde');
}
bk_ok('gemensam konstant finns');

$ellevio = fullmakt_copy(['doc_type' => 'ellevio'], 'kicker');
$overlay = fullmakt_copy(['doc_type' => 'overlay'], 'kicker');
if ($ellevio !== $expected || $overlay !== $expected) {
    bk_fail('ellevio/overlay-kicker skiljer sig från konstanten');
}
if ($ellevio !== $overlay) {
    bk_fail('ellevio och overlay har olika kicker');
}
bk_ok('fullmakt_copy ger samma kicker för ellevio och overlay');

// Per-doc override of kicker must not reopen bolags-kopior: shared constant wins.
$override = fullmakt_copy(['doc_type' => 'ellevio', 'kicker' => 'Annat Bolag'], 'kicker');
if ($override !== $expected) {
    bk_fail('doc-override får inte byta den gemensamma kickern');
}
bk_ok('doc-override kan inte byta brand-kickern');

$boot = (string) file_get_contents($root . '/fullmakt/lib/bootstrap.php');
if (substr_count($boot, "FULLMAKT_BRAND_KICKER = 'Demo Energi Järfälla & Demo Energi Malmö'") !== 1) {
    bk_fail('konstanten ska finnas exakt en gång');
}
if (preg_match("/'kicker'\\s*=>/", $boot) === 1) {
    bk_fail('FULLMAKT_COPY ska inte ha egen kicker-kopia');
}
bk_ok('en strängdefinition, ingen kopia i FULLMAKT_COPY');

// Övrig text orörd
if (!str_contains($boot, 'Du ger Demo Energi Järfälla rätt att anmäla')) {
    bk_fail('ellevio-lead får inte ändras');
}
if (!str_contains($boot, "'foretag' => 'Demo Energi Järfälla'")) {
    bk_fail('installatörsföretag får inte ändras');
}
bk_ok('övrig fullmaktstext orörd');

foreach (['sign.php', 'index.php', 'tack.php'] as $file) {
    $src = (string) file_get_contents($root . '/fullmakt/' . $file);
    if (!str_contains($src, 'FULLMAKT_BRAND_KICKER')) {
        bk_fail($file . ' använder inte FULLMAKT_BRAND_KICKER');
    }
    if (str_contains($src, 'brand-kicker">Demo Energi Järfälla</')) {
        bk_fail($file . ' har kvar hårdkodad gammal kicker');
    }
}
bk_ok('sign/index/tack pekar på konstanten');

echo "fullmakt_brand_kicker_test: ok\n";
