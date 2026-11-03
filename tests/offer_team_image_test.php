<?php

declare(strict_types=1);

/**
 * Malmö-offerter har ingen teambild. En saknad eller tom fil ritas inte som img.
 */

$tmp = sys_get_temp_dir() . '/jeab-team-img-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa testkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';

function ti(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

register_shutdown_function(static function () use ($tmp): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($tmp);
});

$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
ti(is_string($jpeg) && $jpeg !== '' && str_starts_with($jpeg, "\xFF\xD8"), 'testbilden är en JPEG');

$malmoText = "Hej\n\nOffert.\n\nKim\nElektriker\nDemo Energi Malmö";
$jfText = "Hej\n\nOffert.\n\nRoberto\nElektriker\nDemo Energi Järfälla";

@mkdir(dirname(jeab_auto_team_image_path('malmo')), 0700, true);
file_put_contents(jeab_auto_team_image_path('malmo'), $jpeg);
file_put_contents(jeab_auto_team_image_path('jarfalla'), $jpeg);

ti(jeab_auto_team_image_ok('malmo') === false, 'Malmö har ingen teambild även när filen har innehåll');
ti(jeab_auto_team_image_ok('jarfalla') === true, 'Järfälla med en riktig fil har teambild');

$malmoHtml = jeab_auto_quote_html($malmoText, 'malmo', [], true);
ti(!str_contains($malmoHtml, '<img') && !str_contains($malmoHtml, 'cid:team') && !str_contains($malmoHtml, 'team-malmo'), 'Malmö-mejlet ritar ingen teambild');
ti(str_contains($malmoHtml, 'Demo Energi Malmö') && str_contains($malmoHtml, 'Offert.'), 'Malmö-mejlet behåller texten');

$jfHtml = jeab_auto_quote_html($jfText, 'jarfalla', [], true, ['hs_batteri' => true]);
ti(str_contains($jfHtml, '<img src="cid:team-jarfalla-energi.jpg"') && str_contains($jfHtml, 'width="280"'), 'Järfälla behåller teambilden');

file_put_contents(jeab_auto_team_image_path('jarfalla'), '');
ti(is_file(jeab_auto_team_image_path('jarfalla')) && filesize(jeab_auto_team_image_path('jarfalla')) === 0, 'tom Järfälla-fil är 0 byte');
ti(jeab_auto_team_image_ok('jarfalla') === false, '0 byte räknas inte som teambild');
$emptyHtml = jeab_auto_quote_html($jfText, 'jarfalla', [], true, ['hs_batteri' => true]);
ti(!str_contains($emptyHtml, '<img') && !str_contains($emptyHtml, 'cid:'), 'tom fil ritar ingen img');
ti(str_contains($emptyHtml, 'Demo Energi Järfälla'), 'texten står kvar utan bild');

@unlink(jeab_auto_team_image_path('jarfalla'));
ti(jeab_auto_team_image_ok('jarfalla') === false, 'saknad fil är ingen teambild');
$missingHtml = jeab_auto_quote_html($jfText, 'jarfalla', [], true);
ti(!str_contains($missingHtml, '<img'), 'saknad fil ritar ingen img');

function ti_names(array $row): array
{
    $names = [];
    foreach ((array) ($row['attachments'] ?? []) as $att) {
        if (is_array($att)) {
            $names[] = (string) ($att['name'] ?? '');
        }
    }
    return $names;
}

$malmoRow = jeab_notiser_quote_row('batteri_malmo', 'batteri', 'malmo', "Hej\n\nMalmö.", '', 'quote_templates.batteri_malmo', 'redigeras här');
ti(!in_array('team-malmo.jpg', ti_names($malmoRow), true) && !str_contains(json_encode($malmoRow), '<img'), 'Notiser visar ingen Malmö-teambild');

file_put_contents(jeab_auto_team_image_path('jarfalla'), $jpeg);
$jfRow = jeab_notiser_quote_row('batteri_jarfalla', 'batteri', 'jarfalla', "Hej\n\nJärfälla.", '', 'quote_templates.batteri_jarfalla', 'redigeras här');
$jfNames = ti_names($jfRow);
ti(in_array('team-jarfalla.jpg', $jfNames, true), 'Notiser visar Järfällas teambild');
foreach ((array) $jfRow['attachments'] as $att) {
    if (is_array($att) && ($att['name'] ?? '') === 'team-jarfalla.jpg') {
        ti(empty($att['missing']) && (int) ($att['bytes'] ?? 0) > 0 && (string) ($att['href'] ?? '') !== '', 'Järfällas teambild är en riktig bilaga');
    }
}

file_put_contents(jeab_auto_team_image_path('jarfalla'), '');
$zeroRow = jeab_notiser_quote_row('batteri_jarfalla', 'batteri', 'jarfalla', "Hej\n\nJärfälla.", '', 'quote_templates.batteri_jarfalla', 'redigeras här');
ti(!in_array('team-jarfalla.jpg', ti_names($zeroRow), true), 'Notiser utelämnar en tom teambild');

@unlink(jeab_auto_team_image_path('jarfalla'));
$goneRow = jeab_notiser_quote_row('laddbox_jarfalla', 'laddbox', 'jarfalla', "Hej\n\nJärfälla.", '', 'quote_templates.laddbox_jarfalla', 'redigeras här');
ti(!in_array('team-jarfalla.jpg', ti_names($goneRow), true), 'Notiser utelämnar en saknad teambild');

file_put_contents(jeab_auto_team_image_path('malmo'), '');
$emptyAsset = jeab_notiser_asset('team_malmo', 'team-malmo.jpg');
ti(!empty($emptyAsset['missing']) && (string) ($emptyAsset['href'] ?? '') === '' && (int) ($emptyAsset['bytes'] ?? -1) === 0, 'en tom teambild har ingen länk');
file_put_contents(jeab_auto_team_image_path('jarfalla'), $jpeg);
$liveAsset = jeab_notiser_asset('team_jarfalla', 'team-jarfalla.jpg');
ti(empty($liveAsset['missing']) && (string) ($liveAsset['href'] ?? '') !== '' && (int) ($liveAsset['bytes'] ?? 0) === strlen($jpeg), 'Järfällas fil kan laddas ner');

echo "offer team image: all passed\n";
