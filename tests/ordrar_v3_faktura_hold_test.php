<?php

declare(strict_types=1);

/**
 * Order 40 Järfälla: andra klicket under sexsekundersnedräkningen
 * får inte posta Skapa faktura utan faktura_bekrafta. Den väntande
 * posten ska gå igenom efter 6 s, med flaggan. Spara är utkast.
 */

function hold_ok(bool $ok, string $message): void
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
$handlerAt = strpos($js, "root.addEventListener('submit'");
$handlerEnd = strpos($js, 'submitStep(form, submitter);', $handlerAt === false ? 0 : $handlerAt);
$handler = $handlerAt !== false && $handlerEnd !== false ? substr($js, $handlerAt, $handlerEnd - $handlerAt) : '';
hold_ok($handler !== '', 'submit-lyssnaren finns');
hold_ok(str_contains($handler, "getAttribute('data-v3-held') === '1'") && str_contains($handler, 'draftSave'), 'andra klicket under nedräkningen postar inte faktura');
hold_ok(str_contains($handler, "submitter.value || '') === 'save'"), 'Spara släpps igenom utan flagga');
hold_ok(str_contains($handler, 'submitHeldStep(form, submitter)') && !str_contains($handler, 'om 6 sekunder') && !str_contains($handler, 'toastUndo('), 'Skapa faktura skickas direkt, ingen sexsekundersnedräkning');
hold_ok(str_contains($js, 'function submitHeldStep') && str_contains($js, 'if (busy && tries < 150)'), 'väntande faktura väntar ut ett pågående Spara');

$node = trim((string) shell_exec('command -v node'));
hold_ok($node !== '', 'node finns');
$run = 'env -u FORCE_COLOR ' . escapeshellarg($node) . ' ' . escapeshellarg($root . '/tests/ordrar_v3_faktura_hold_run.js');
$out = [];
$code = 0;
exec($run . ' 2>&1', $out, $code);
$text = implode("\n", $out);
if ($code !== 0) {
    fwrite(STDERR, $text . "\n");
}
hold_ok($code === 0, 'första klicket postar fakturan direkt med flagga');
echo $text . "\n";
