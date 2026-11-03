<?php

declare(strict_types=1);

/**
 * B22: bokningsarkets Spara låser med data-busy/aria-busy/disabled,
 * blockerar andra submit och återställs vid fel och pageshow.
 */

$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');

function b22(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$from = strpos($js, 'function bookingSaveButtons()');
$to = strpos($js, 'function viewBox()', $from === false ? 0 : $from);
b22($from !== false && $to !== false && $to > $from, 'busy-hjälpare sitter vid bokningsformulärets submit');
$chunk = substr($js, $from, $to - $from);

b22(str_contains($chunk, "bookingForm.getAttribute('data-busy') === '1'"), 'andra submit stoppas när formuläret är busy');
b22(str_contains($chunk, 'event.preventDefault()'), 'busy-kollen avbryter native submit');
b22(str_contains($chunk, "bookingForm.setAttribute('data-busy', '1')"), 'formuläret får data-busy vid submit');
b22(str_contains($chunk, "btn.setAttribute('aria-busy', 'true')"), 'Spara får aria-busy');
b22(str_contains($chunk, 'btn.disabled = true'), 'Spara blir disabled');
b22(str_contains($chunk, "bookingForm.removeAttribute('data-busy')"), 'data-busy tas bort vid återställning');
b22(str_contains($chunk, "btn.removeAttribute('aria-busy')"), 'aria-busy tas bort vid återställning');
b22(str_contains($chunk, 'btn.disabled = false'), 'Spara slås på vid återställning');
b22(str_contains($chunk, 'sheet-discard-save'), 'kasta-rutans Spara låses också');
b22(str_contains($chunk, 'if (paintDurationError())') && str_contains($chunk, 'releaseBookingSubmit()'), 'fel i längden återställer knappen');
b22(str_contains($chunk, "window.confirm(names + ' är redan bokad då. Spara ändå?')") && str_contains($chunk, '} else {') && str_contains($chunk, 'releaseBookingSubmit()'), 'avbruten dublettfråga återställer');
b22(str_contains($chunk, "addEventListener('pageshow'") && str_contains($chunk, 'releaseBookingSubmit()'), 'pageshow återställer efter bfcache');
b22(str_contains($chunk, 'lockBookingSubmit()'), 'godkänt submit låser knappen');

$paint = strpos($js, 'function paintDurationError()');
$paintEnd = strpos($js, 'function addMinutes(', $paint === false ? 0 : $paint);
$paintChunk = $paint === false || $paintEnd === false ? '' : substr($js, $paint, $paintEnd - $paint);
b22(str_contains($paintChunk, "bookingForm.getAttribute('data-busy') !== '1'"), 'längdkollen slår inte på Spara under busy');

echo "ALLA OK\n";
