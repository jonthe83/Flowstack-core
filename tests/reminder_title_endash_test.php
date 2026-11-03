<?php

declare(strict_types=1);

/**
 * Ny påminnelse: fältet Adress heter title och ska sparas helt.
 * «TEST Cody #1004 – radera ej» får inte bli «TEST Cody #1004».
 *
 * Den enda avskärningen av en påminnelserubrik vid «–» sitter i
 * src/kontakter_name_address.php, jeab_name_address_finish_shown,
 * där prefixet bara är ett namnförslag. Det skrivs inte tillbaka till title.
 * jeab_solkoll_street_query i src/solkoll.php klipper vid första sifferserien
 * och skulle ge samma prefix, men den används bara som sökfråga mot Solkoll.
 */

$tmp = sys_get_temp_dir() . '/jeab-rem-endash-' . bin2hex(random_bytes(4));
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ed(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$full = 'TEST Cody #1004 – radera ej';
$cut = 'TEST Cody #1004';
$store = jeab_store();
$row = jeab_reminder_create_action($store, [
    'action' => 'reminder.create',
    'ui' => '1',
    'title' => $full,
    'address' => $full,
    'company' => 'jarfalla',
]);
ed((string) ($row['title'] ?? '') === $full, 'sparad titel behåller texten efter tankstreck');
ed((string) ($row['address'] ?? '') === $full, 'sparad adress behåller texten efter tankstreck');
ed((string) ($row['title'] ?? '') !== $cut, 'titeln är inte avklippt vid tankstrecket');

$fresh = $store->getReminder((int) ($row['id'] ?? 0));
ed(is_array($fresh) && (string) ($fresh['title'] ?? '') === $full && (string) ($fresh['address'] ?? '') === $full, 'lagret läser tillbaka hela titeln och adressen');

$html = jeab_reminder_row(is_array($fresh) ? $fresh : $row, true);
ed(str_contains($html, 'name="address"'), 'öppet kort har adressfält');
ed(preg_match('/<textarea\b[^>]*name="address"[^>]*>' . preg_quote($full, '/') . '<\/textarea>/u', $html) === 1, 'adressfältet visar hela texten');
ed(!preg_match('/name="address"[^>]*>' . preg_quote($cut, '/') . '</', $html), 'adressfältet är inte avklippt');

$nameHtml = '';
if (preg_match('/name="customer"[^>]*value="([^"]*)"/u', $html, $nameMatch) === 1) {
    $nameHtml = (string) $nameMatch[1];
}
ed($nameHtml !== $cut, 'namnfältet tar inte prefixet när det inte är ett namn');

$real = 'Mira Exempel – radera ej';
$realRow = jeab_reminder_create_action($store, [
    'action' => 'reminder.create',
    'ui' => '1',
    'title' => $real,
    'address' => $real,
    'company' => 'jarfalla',
]);
ed((string) ($realRow['title'] ?? '') === $real && (string) ($realRow['address'] ?? '') === $real, 'namn och produkt i titeln sparas hela');
$realHtml = jeab_reminder_row($realRow, true);
ed(preg_match('/<textarea\b[^>]*name="address"[^>]*>' . preg_quote($real, '/') . '<\/textarea>/u', $realHtml) === 1, 'adressfältet behåller produkttexten efter tankstreck');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/kontakter_name_address.php');
$lines = explode("\n", $src);
$cause = 0;
foreach ($lines as $i => $line) {
    if (str_contains($line, '/^(.+?)\\s*[–—\\-]\\s+\\S/u')) {
        $cause = $i + 1;
        break;
    }
}
ed($cause === 685, 'namnförslaget klipps i kontakter_name_address.php:685, fick rad ' . $cause);
$around = implode("\n", array_slice($lines, 678, 12));
ed(str_contains($around, '$fallbacks[]'), 'klippet matar bara namnförslag');

echo "OK  reminder title en dash\n";
