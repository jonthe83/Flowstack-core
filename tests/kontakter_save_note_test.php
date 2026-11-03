<?php

declare(strict_types=1);

/**
 * «Sparad.» sitter vid Spara-knappen i 2,5 s, och bara efter serverns ok.
 * Påhittade personer. Inget skickas.
 */

$tmp = sys_get_temp_dir() . '/jeab-kt-save-note-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ksn(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = jeab_store();
$id = 'kt_note' . bin2hex(random_bytes(4));
jeab_kontakter_mutate(static function (array &$contacts) use ($id): void {
    $contacts[$id] = [
        'id' => $id,
        'street' => 'Notegatan 2',
        'city' => 'Järfälla',
        'company' => 'jarfalla',
        'ovrigt' => 'QA12',
        'owners' => [['name' => 'Nora Note', 'contact' => true, 'personnummer' => '', 'email' => '', 'phone' => '']],
    ];
});

$_GET = ['p' => 'kontakter', 'co' => 'jarfalla', 'id' => $id];
$flash = 'Sparad.';
$page = require dirname(__DIR__) . '/src/pages/kontakter.php';
ksn(is_string($page), 'kontaktsidan ritas');
$page = (string) $page;

$button = '<button class="btn" type="submit">Spara</button>';
$status = '<p class="kt-save-status" data-kt-save-status role="status"></p>';
$saveAt = strpos($page, '<div class="kt-save">');
$buttonAt = strpos($page, $button);
$statusAt = strpos($page, $status);
ksn($saveAt !== false && $buttonAt !== false && $statusAt !== false && $saveAt < $buttonAt && $buttonAt < $statusAt, 'statusraden ligger i samma ruta som Spara');
$gap = substr($page, $buttonAt + strlen($button), $statusAt - ($buttonAt + strlen($button)));
ksn(trim($gap) === '', 'statusen sitter direkt efter Spara');
$closeAt = strpos($page, '</div>', $statusAt);
ksn($closeAt !== false && trim(substr($page, $statusAt + strlen($status), $closeAt - ($statusAt + strlen($status)))) === '', 'inget annat ligger mellan statusen och rutans slut');
ksn(str_contains($page, 'data-kt-flash="Sparad."') && !str_contains($page, 'id="kt-saved-toast"'), 'flash finns, kropps-toasten ritas inte');
$formAt = strpos($page, '<form class="reminder-create kt-form"');
$formEnd = strpos($page, '</form>', $formAt === false ? 0 : $formAt);
$formHtml = ($formAt !== false && $formEnd !== false) ? substr($page, $formAt, $formEnd - $formAt) : '';
$ovrigtAt = strpos($formHtml, 'name="ovrigt"');
$qaAt = strpos($formHtml, '>QA12</textarea>');
ksn($formHtml !== '' && $ovrigtAt !== false && $qaAt !== false && $ovrigtAt < $qaAt && $qaAt < strpos($formHtml, 'data-kt-save-status'), 'Övrigt och Sparad. sitter i samma formulär som Spara');
ksn(!preg_match('/<textarea name="ovrigt"[^>]*(onblur|oninput|onchange)=/', $formHtml), 'Övrigt har ingen egen blur-sparning');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/pages/kontakter.php');
$pushAt = strpos($src, 'function push(includeHeavy)');
$soonAt = strpos($src, 'function soon()');
ksn($pushAt !== false && $soonAt !== false && $pushAt < $soonAt, 'sparfunktionen finns');
$push = substr($src, $pushAt, $soonAt - $pushAt);
$sameAt = strpos($push, 'if (same) return;');
$fetchAt = strpos($push, "fetch(saveForm.getAttribute('action') || location.href");
$throwAt = strpos($push, 'data.ok === false');
$noteAt = strpos($push, 'if (includeHeavy) ktSaveNote(savedText(data), false);');
$errAt = strpos($push, "ktSaveNote(err && err.message ? err.message : 'Kunde inte spara.', true)");
ksn($sameAt !== false && $fetchAt !== false && $sameAt < $fetchAt, 'oförändrat formulär visar inte Sparad. utan anrop');
ksn(!str_contains($src, "rememberToast('Sparad.')") && !str_contains($src, 'jeab.kt.toast') && !str_contains($src, 'kt-saved-toast'), 'ingen Sparad. utan serverns svar');
ksn($throwAt !== false && $noteAt !== false && $throwAt < $noteAt, 'Sparad. skrivs först efter ok-kontrollen');
$between = substr($push, $throwAt, $noteAt - $throwAt);
ksn(str_contains($between, 'throw new Error'), 'noten kommer efter kastet när servern säger nej');
ksn(str_contains($src, 'SAVE_MS = 2500') && str_contains($src, '}, SAVE_MS);'), 'texten ligger kvar 2,5 s');
ksn($errAt !== false && $errAt > $noteAt && str_contains($src, "note.classList.toggle('is-err', !!isErr)"), 'feltexten hamnar i samma rad');
ksn(substr_count($src, 'ktSaveNote(savedText(data), false)') === 1, 'bara Spara-svaret skriver Sparad.');
ksn(str_contains($src, "var heavyNames = ['owner_pnr_1', 'owner_pnr_2'];"), 'Övrigt ingår inte i Spara-fälten');
ksn(str_contains($src, 'includeHeavy ? heavyNames : []') && str_contains($src, 'push(true)'), 'Spara tar med personnummer och visar noten');
$smallAt = strpos($src, 'var smallNames = [');
$smallEnd = strpos($src, '];', $smallAt === false ? 0 : $smallAt);
$small = ($smallAt !== false && $smallEnd !== false) ? substr($src, $smallAt, $smallEnd - $smallAt) : '';
ksn($small !== '' && str_contains($small, "'ovrigt'"), 'Övrigt autosparas');
ksn(str_contains($src, "note.scrollIntoView({ block: 'nearest', inline: 'nearest' })"), 'noten scrollas in i detaljpanelen');
$app = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
ksn(!str_contains($app, 'kontakter.save') && !str_contains($app, 'data-kt-save-status'), 'app.js sparar inte Övrigt');

$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/paminnelser.css');
$saveRule = strpos($css, 'html body.page-reminders.page-kontakter .kt-save {');
$saveBlock = $saveRule === false ? '' : substr($css, $saveRule, 180);
ksn(str_contains($saveBlock, 'display: flex;') && str_contains($saveBlock, 'flex-wrap: nowrap;'), 'raden håller texten på samma rad som knappen');
$btnRule = strpos($css, 'html body.page-reminders.page-kontakter .kt-save .btn {');
$btnBlock = $btnRule === false ? '' : substr($css, $btnRule, 120);
ksn(str_contains($btnBlock, 'width: auto;'), 'Spara är inte helbredd, så Sparad. inte hamnar under scrollkanten');
ksn(str_contains($css, '.kt-save-status {') && str_contains($css, '.kt-save-status.is-err {') && !str_contains($css, '.kt-toast'), 'statusen är inline och den fasta toasten är borta');
$layout = (string) file_get_contents(dirname(__DIR__) . '/src/layout.php');
ksn(str_contains($layout, 'assets/css/paminnelser.css') && str_contains($layout, 'jeab_asset_url'), 'sidans css får ?v= via jeab_asset_url');

echo "kontakter save note ok\n";
