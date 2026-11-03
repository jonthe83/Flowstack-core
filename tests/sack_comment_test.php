<?php

declare(strict_types=1);

/**
 * Hämtning av säck: kommentar i mejltexten, inga bilagor.
 * jeab_system_mail_send är en stubb som definieras före reminders.php,
 * så inget riktigt mejl skickas.
 */

function sack_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function jeab_system_mail_send(array $opts): array
{
    $GLOBALS['sack_mail_calls'][] = $opts;
    return ['ok' => true, 'error' => null, 'path' => 'stub', 'id' => 'stub'];
}

require dirname(__DIR__) . '/src/reminders.php';

sack_assert(function_exists('jeab_sack_attachments_from_files') === false, 'bilagehjälpen är borta');
$ref = new ReflectionFunction('jeab_sack_send');
sack_assert($ref->getNumberOfParameters() === 1, 'jeab_sack_send tar inte emot filer');

$base = [
    'namn' => 'Test Person',
    'adress' => 'Exempelgatan 1',
    'ort' => 'Järfälla',
    'telefon' => '0701234567',
];

$GLOBALS['sack_mail_calls'] = [];
$empty = jeab_sack_send($base + [
    'kommentar' => "  \n\t",
    'photos' => [
        'name' => ['hack.php'],
        'type' => ['image/jpeg'],
        'tmp_name' => ['/tmp/not-a-real-upload'],
        'error' => [0],
        'size' => [12],
    ],
]);
sack_assert($empty['ok'] === true, 'tom kommentar skickas via stubb');
sack_assert($empty['to'] === 'container@example.test', 'mottagare oförändrad');
sack_assert(!str_contains($empty['body'], 'Kommentar:'), 'tom kommentar utelämnar Kommentar:');
sack_assert(str_contains($empty['body'], "Telefon: 0701234567\n\nDet är endast 1 st välfylld säck, ganska lätt, mestadels kartong och trä."), 'tom kommentar behåller telefon och fast mening');
sack_assert(count($GLOBALS['sack_mail_calls']) === 1, 'stubben anropades en gång');
$opts = $GLOBALS['sack_mail_calls'][0];
sack_assert(!array_key_exists('attachments', $opts), 'uppladdning bifogas inte');
sack_assert(($opts['text'] ?? '') === $empty['body'], 'stubben fick samma brödtext');

$comment = "Står vid grinden\nPortkod 1234";
$GLOBALS['sack_mail_calls'] = [];
$with = jeab_sack_send($base + ['comment' => "\0" . $comment . "\0"]);
sack_assert(str_contains($with['body'], "Telefon: 0701234567\n\nKommentar:\n" . $comment . "\n\nDet är endast 1 st välfylld säck, ganska lätt, mestadels kartong och trä."), 'kommentar med radbrytning ligger i brödtexten');
sack_assert(!str_contains($with['subject'], $comment), 'kommentaren ligger inte i ämnet');
sack_assert(!str_contains($with['subject'], 'Kommentar'), 'ämnet nämner inte Kommentar');
sack_assert(!array_key_exists('attachments', $GLOBALS['sack_mail_calls'][0]), 'inga bilagor när kommentaren finns');
sack_assert(str_contains($with['body'], '/ Demo Energi Järfälla, Sam 080000002'), 'avslut oförändrat');
sack_assert(str_contains($with['body'], 'Produkt: All-inclusive XL 1,6 kbm'), 'produktrad oförändrad');

$long = str_repeat('å', 2001);
$capped = jeab_sack_comment_text(['kommentar' => $long]);
sack_assert(mb_strlen($capped, 'UTF-8') === 2000, 'kommentar kapas vid 2000 tecken');

$threw = false;
try {
    jeab_sack_send([
        'namn' => '',
        'adress' => 'Exempelgatan 1',
        'ort' => 'Järfälla',
        'telefon' => '0701234567',
        'kommentar' => 'bara en kommentar',
    ]);
} catch (InvalidArgumentException $e) {
    $threw = $e->getMessage() === 'Alla fält måste vara ifyllda.';
}
sack_assert($threw, 'kommentar ersätter inte namn, adress, ort och telefon');

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/sack.php');
sack_assert(str_contains($page, 'name="kommentar"'), 'formuläret har kommentar');
sack_assert(str_contains($page, 'id="kommentar"'), 'kommentaren har id');
sack_assert(!str_contains($page, 'photoBtn'), 'fotosknapp borta');
sack_assert(!str_contains($page, 'id="camera"'), 'filinput borta');
sack_assert(!str_contains($page, 'Ta gärna en bild'), 'bilduppmaning borta');
sack_assert(!str_contains($page, 'Bilderna bifogas'), 'klart-texten nämner inte bilder');
sack_assert(str_contains($page, 'Mejlet skickades från kontakt@jarfalla.example.test till '), 'klart-texten nämner avsändare och mottagare');
sack_assert(!str_contains($page, 'getElementById("tools")'), 'showDone rör inte tools');
sack_assert(!str_contains($page, 'getElementById("thumbs")'), 'showDone rör inte thumbs');

echo "sack comment ok\n";
