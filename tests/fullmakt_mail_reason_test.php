<?php

declare(strict_types=1);

require dirname(__DIR__) . '/fullmakt/lib/bootstrap.php';
require dirname(__DIR__) . '/fullmakt/lib/mailer.php';
require dirname(__DIR__) . '/src/ellevio_fullmakt.php';

function fm_assert(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
}

putenv('FULLMAKT_DISABLE_MAIL=1');
$ok = fullmakt_send_invite([
    'epost' => 'kund@example.com',
    'namn' => 'Test',
    'type' => 'ellevio',
], 'https://flowstack.example.test/fullmakt/s/abc');
fm_assert($ok === false, 'disabled mail should not send');
fm_assert(
    str_contains(fullmakt_mail_last_error(), 'FULLMAKT_DISABLE_MAIL'),
    'disabled mail should name FULLMAKT_DISABLE_MAIL, got ' . fullmakt_mail_last_error()
);

putenv('FULLMAKT_DISABLE_MAIL');
$ok = fullmakt_send_invite(['epost' => '', 'namn' => 'Test'], 'https://flowstack.example.test/fullmakt/s/abc');
fm_assert($ok === false, 'empty recipient should fail');
fm_assert(str_contains(fullmakt_mail_last_error(), 'saknas'), 'empty recipient reason, got ' . fullmakt_mail_last_error());

$ok = fullmakt_send_invite(['epost' => 'inte-en-adress', 'namn' => 'Test'], 'https://flowstack.example.test/fullmakt/s/abc');
fm_assert($ok === false, 'invalid recipient should fail');
fm_assert(str_contains(fullmakt_mail_last_error(), 'ogiltig'), 'invalid recipient reason, got ' . fullmakt_mail_last_error());

$failed = [
    'natbolag' => 'Ellevio',
    'fullmakt_token' => 'abc',
    'fullmakt_mailed' => false,
    'fullmakt_error' => 'Mailet kunde inte skickas.',
];
fm_assert(jeab_ellevio_fullmakt_should_resend($failed) === true, 'failed invite should retry');

$pending = [
    'natbolag' => 'Ellevio',
    'fullmakt_token' => 'abc',
    'fullmakt_mailed' => true,
    'fullmakt_error' => '',
];
fm_assert(jeab_ellevio_fullmakt_should_resend($pending) === false, 'mailed pending invite must not resend');

$pendingWithStaleError = $pending;
$pendingWithStaleError['fullmakt_error'] = 'gammal text';
fm_assert(jeab_ellevio_fullmakt_should_resend($pendingWithStaleError) === false, 'mailed=true wins over stale error');

$signed = $failed;
$signed['fullmakt_signed'] = true;
fm_assert(jeab_ellevio_fullmakt_should_resend($signed) === false, 'signed must not resend');

$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    if (($opts['to'] ?? '') !== 'kund@example.com') {
        return ['ok' => false, 'path' => '', 'error' => 'oväntad mottagare', 'id' => ''];
    }
    if (!str_contains((string) ($opts['text'] ?? ''), 'https://flowstack.example.test/fullmakt/s/abc')) {
        return ['ok' => false, 'path' => '', 'error' => 'länk saknas', 'id' => ''];
    }
    return ['ok' => true, 'path' => 'helpscout', 'error' => null, 'id' => 'hs-1'];
};
$ok = fullmakt_send_invite([
    'epost' => 'kund@example.com',
    'namn' => 'Test',
    'type' => 'ellevio',
], 'https://flowstack.example.test/fullmakt/s/abc');
fm_assert($ok === true, 'shared helper should send a valid invite');
fm_assert(fullmakt_mail_last_error() === '', 'success should clear the error');
fm_assert(fullmakt_mail_last_path() === 'helpscout', 'path should be helpscout, got ' . fullmakt_mail_last_path());

echo "fullmakt mail reason: ok\n";
