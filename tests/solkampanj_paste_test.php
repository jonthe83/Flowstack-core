<?php

declare(strict_types=1);

/** Solkampanj: klistra in skärmdump i Chrome/ChromeOS. Bara sidkällan. */

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/solkampanj.php');

function sp(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$zoneAt = strpos($page, 'id="paste"');
$underAt = strpos($page, 'id="paste-zone"');
sp($zoneAt !== false && $underAt !== false && $underAt > $zoneAt, 'klistrazonen ligger under bildfältet');
sp(str_contains($page, 'tabindex="0"') && str_contains($page, 'contenteditable="true"') && str_contains($page, 'Klistra in skärmdump här (Ctrl+V)'), 'zonen är fokuserbar och heter Klistra in skärmdump här');
sp(substr_count($page, "addEventListener('paste'") >= 2, 'paste lyssnas på zonen och på document');
sp(str_contains($page, 'data.items') && str_contains($page, 'getAsFile()') && str_contains($page, 'data.files'), 'första bilden tas från items eller files');
sp(preg_match('/function onPaste\(ev\) \{\s*var file = imageFileFromClipboard\(ev\.clipboardData\);\s*if \(!file\) return;\s*ev\.preventDefault\(\);/', $page) === 1, 'textklistring preventDefault:as inte');
sp(str_contains($page, '>Välj bild<') && str_contains($page, "addEventListener('drop'") && str_contains($page, "addEventListener('dragover'"), 'välj bild och dra-och-släpp finns');
sp(str_contains($page, "data.append('image', imageUrl)") && !preg_match('/type="file"[^>]*name="image"/', $page), 'utskicket använder samma image-värde');
sp(str_contains($page, 'file.size > 6000000'), 'samma storleksgräns som servern');

echo "solkampanj paste: all passed\n";
