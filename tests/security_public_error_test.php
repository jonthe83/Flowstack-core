<?php

declare(strict_types=1);

/**
 * B-012: en avsedd mening får nå användaren. SQL, sökväg och hemlighet får inte det.
 * Detaljen stannar i felloggen. Inget värde från servern skrivs ut.
 */

$tmp = sys_get_temp_dir() . '/jeab-puberr-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
require dirname(__DIR__) . '/src/bootstrap.php';

function pub(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$safe = new InvalidArgumentException('Offerten skickades inte: offertutskick är av');
pub(jeab_public_error_text($safe) === 'Offerten skickades inte: offertutskick är av', 'en avsedd mening når säljaren');
$foranFail = 'Föranmälan kunde inte skickas till Bokmalen. Kontrollera webhook-nyckeln och försök igen.';
pub(jeab_public_error_sentence($foranFail), 'föranmälan-felet utan secret släpps igenom');
pub(jeab_public_error_text(new InvalidArgumentException($foranFail)) === $foranFail, 'föranmälan-felet visas som det är');
pub(!jeab_public_error_sentence('Föranmälan kunde inte skickas till Bokmalen. Kontrollera webhook-secret och försök igen.'), 'webhook-secret stoppas');
pub(jeab_public_error_text(new InvalidArgumentException('Inte behörig.')) === 'Inte behörig.', 'behörighet visas som den är');

$sql = new InvalidArgumentException('SELECT id FROM reminders WHERE name = "Åsa"');
pub(jeab_public_error_text($sql) === 'Något gick fel.', 'SQL i InvalidArgumentException visas inte');
pub(str_contains(jeab_throwable_log_message($sql), 'SELECT id FROM reminders'), 'SQL-texten finns kvar i felloggens meddelande');

$path = new InvalidArgumentException('fel i /var/www/src/security.php:605');
pub(jeab_public_error_text($path) === 'Något gick fel.', 'en sökväg visas inte');
pub(jeab_public_error_text(new RuntimeException('hemlig orsak')) === 'Något gick fel.', 'okänt fel är fortfarande Något gick fel');
pub(jeab_public_error_text(new InvalidArgumentException('')) === 'Något gick fel.', 'tomt argumentfel är Något gick fel');

$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
$at = strpos($index, "intent === 'calendar-match-debug'");
$slice = $at === false ? '' : substr($index, $at, 700);
pub($slice !== '' && str_contains($slice, 'jeab_public_error_text') && !str_contains($slice, 'getMessage'), 'kalender-debug skickar den offentliga meningen');

echo "OK  public error\n";
