<?php

declare(strict_types=1);

/**
 * P3: 90-dagars hashbunden kaka, next-sanering, JSON_HEX_TAG,
 * OAuth-state borta, SSRF, PDF-magi, CSRF-nonce.
 */

$tmp = sys_get_temp_dir() . '/jeab-p3-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function p3_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

p3_assert(JEAB_AUTH_TTL === 90 * 24 * 60 * 60, 'JEAB_AUTH_TTL är 90 dagar');

$pwFile = $tmp . '/pw.json';
$keepHash = password_hash('keep-kampanj', PASSWORD_DEFAULT);
$dropHash = password_hash('drop-kampanj', PASSWORD_DEFAULT);
file_put_contents($pwFile, json_encode([
    'secret' => 'p3-secret',
    'hashes' => [
        ['hash' => $keepHash, 'email' => 'keep@example.com'],
        ['hash' => $dropHash, 'email' => 'drop@example.com'],
    ],
], JSON_UNESCAPED_UNICODE));
$prevPw = getenv('JEAB_PASSWORDS_FILE');
putenv('JEAB_PASSWORDS_FILE=' . $pwFile);
jeab_auth_data(true);

$issued = jeab_auth_issue_value('drop@example.com', $dropHash);
p3_assert(is_string($issued) && str_starts_with(jeab_auth_payload($issued) ?? '', '3.'), 'ny kaka är v3 och hashbunden');
p3_assert(jeab_auth_value_ok($issued) === true, 'utfärdad kaka gäller medan hashen finns');
$keepIssued = jeab_auth_issue_value('keep@example.com', $keepHash);
p3_assert(is_string($keepIssued) && jeab_auth_value_ok($keepIssued) === true, 'annan användares kaka gäller');
$now = time();
$v1Payload = '1.' . $now . '.abcd';
$v1 = $v1Payload . '.' . hash_hmac('sha256', $v1Payload, 'p3-secret');
p3_assert(jeab_auth_value_ok($v1) === true, 'v1-kaka gäller medan någon hash finns');
$dropEnc = rtrim(strtr(base64_encode('drop@example.com'), '+/', '-_'), '=');
$v2Payload = '2.' . $dropEnc . '.' . $now . '.abcd';
$v2 = $v2Payload . '.' . hash_hmac('sha256', $v2Payload, 'p3-secret');
p3_assert(jeab_auth_value_ok($v2) === true, 'v2-kaka gäller medan e-posten har hash');
$sharedEnc = rtrim(strtr(base64_encode('keep@example.com'), '+/', '-_'), '=');
$sharedPayload = '2.' . $sharedEnc . '.' . $now . '.abcd';
$sharedV2 = $sharedPayload . '.' . hash_hmac('sha256', $sharedPayload, 'p3-secret');
file_put_contents($pwFile, json_encode([
    'secret' => 'p3-secret',
    'hashes' => [
        ['hash' => $keepHash, 'email' => 'keep@example.com'],
        ['hash' => $dropHash, 'email' => 'keep@example.com'],
    ],
], JSON_UNESCAPED_UNICODE));
jeab_auth_data(true);
p3_assert(jeab_auth_value_ok($sharedV2) === true, 'v2-kaka gäller när e-posten har mer än en hash');
file_put_contents($pwFile, json_encode([
    'secret' => 'p3-secret',
    'hashes' => [
        ['hash' => $keepHash, 'email' => 'keep@example.com'],
    ],
], JSON_UNESCAPED_UNICODE));
jeab_auth_data(true);
p3_assert(jeab_auth_value_ok($issued) === false, 'borttagen hash avvisar befintlig kaka');
p3_assert(jeab_auth_value_ok($v2) === false, 'v2-kaka avvisas när e-postens hash är borta');
p3_assert(jeab_auth_value_ok($keepIssued) === true, 'annan användares kaka gäller efter borttagning');
p3_assert(jeab_auth_value_ok($v1) === true, 'v1-kaka gäller så länge någon hash finns kvar');
p3_assert(jeab_auth_password_ok('drop-kampanj') === false, 'borttagen hash kan inte logga in på nytt');
p3_assert(jeab_auth_password_ok('keep-kampanj') === true, 'kvarvarande hash kan fortfarande logga in');
file_put_contents($pwFile, json_encode([
    'secret' => 'p3-secret',
    'hashes' => [],
], JSON_UNESCAPED_UNICODE));
jeab_auth_data(true);
p3_assert(jeab_auth_value_ok($v1) === false, 'v1-kaka gäller inte när listan är tom');
file_put_contents($pwFile, json_encode([
    'secret' => 'p3-secret',
    'hashes' => [
        ['hash' => $keepHash, 'email' => 'keep@example.com'],
    ],
], JSON_UNESCAPED_UNICODE));
jeab_auth_data(true);

p3_assert(jeab_auth_safe_next('/index.php?p=bookings') === '/index.php?p=bookings', 'vanlig next-sökväg godkänns');
p3_assert(jeab_auth_safe_next('/\\evil.example') === null, 'backslash i next avvisas');
p3_assert(jeab_auth_safe_next('//evil.example') === null, 'protokollrelativ next avvisas');
p3_assert(jeab_auth_safe_next('/bokning//evil.example') === null, 'dubbel slash i next avvisas');
p3_assert(jeab_auth_safe_next('https://evil.example/') === null, 'absolut URL i next avvisas');

$legacySlash = jeab_legacy_bokning_target('/bokning//evil.example');
p3_assert(str_starts_with($legacySlash, '/') && !str_starts_with($legacySlash, '//'), '/bokning// blir inte protokollrelativ');
$legacyBs = jeab_legacy_bokning_target('/bokning/\\evil.example');
p3_assert(str_starts_with($legacyBs, '/') && !str_starts_with($legacyBs, '//') && !str_contains($legacyBs, '\\'), 'backslash i /bokning/ blir inte protokollrelativ');

$escaped = json_encode(['note' => '</script><img src=x onerror=alert(1)>'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
p3_assert(is_string($escaped) && !str_contains($escaped, '</script>'), 'JSON_HEX_TAG escapar </script>');
$notesSrc = (string) file_get_contents(dirname(__DIR__) . '/src/pages/notes.php');
$bookingsSrc = (string) file_get_contents(dirname(__DIR__) . '/src/pages/bookings.php');
p3_assert(str_contains($notesSrc, 'JSON_HEX_TAG'), 'notes.php encodar med JSON_HEX_TAG');
p3_assert(str_contains($bookingsSrc, 'JSON_HEX_TAG'), 'bookings.php encodar med JSON_HEX_TAG');

$indexSrc = (string) file_get_contents(dirname(__DIR__) . '/index.php');
p3_assert(str_contains($indexSrc, "REQUEST_METHOD'] ?? 'GET') === 'POST'"), 'order-vy bokar bara på POST');
p3_assert(!preg_match('/\$_GET\[.logout.\]/', $indexSrc), 'GET logout=1 är borta');
p3_assert(str_contains($indexSrc, "action'] ?? '') === 'logout'"), 'utloggning sker via POST action=logout');

$linksSrc = (string) file_get_contents(dirname(__DIR__) . '/src/pages/links.php');
p3_assert(str_contains($linksSrc, 'method="post"') && str_contains($linksSrc, 'name="action" value="logout"'), 'utloggningslänken är ett POST-formulär');

p3_assert(!is_file(dirname(__DIR__) . '/src/nylas.php'), 'nylas.php är borta');
p3_assert(!str_contains($indexSrc, 'jeab_nylas_'), 'index anropar inte Nylas');

p3_assert(jeab_url_ssrf_safe('https://127.0.0.1/hook') === false, 'SSRF stoppar 127.0.0.1');
p3_assert(jeab_url_ssrf_safe('https://localhost/hook') === false, 'SSRF stoppar localhost');
p3_assert(jeab_url_ssrf_safe('https://[::1]/hook') === false, 'SSRF stoppar ::1');
p3_assert(jeab_url_ssrf_safe('http://example.com/hook') === false, 'SSRF kräver https');
$webhooksSrc = (string) file_get_contents(dirname(__DIR__) . '/src/webhooks.php');
$einsteinSrc = (string) file_get_contents(dirname(__DIR__) . '/src/einstein_webhook.php');
p3_assert(str_contains($webhooksSrc, 'follow_location') && str_contains($webhooksSrc, 'jeab_http_no_follow'), 'webhook följer inte Location');
p3_assert(str_contains($einsteinSrc, 'jeab_url_ssrf_safe') && str_contains($einsteinSrc, 'jeab_http_no_follow'), 'Einstein-webhook använder samma SSRF-spärr');

p3_assert(jeab_offert_bytes_are_pdf('%PDF-1.4 testdata') === true, 'PDF-magi godkänner %PDF-');
p3_assert(jeab_offert_bytes_are_pdf('<html>not pdf</html>') === false, 'PDF-magi avvisar HTML');
try {
    jeab_offert_require_pdf_bytes('not-a-pdf', 'offert.pdf');
    p3_assert(false, 'icke-PDF ska kasta');
} catch (InvalidArgumentException $e) {
    p3_assert(str_contains($e->getMessage(), 'PDF'), 'icke-PDF ger PDF-fel');
}

p3_assert(jeab_solkoll_names_match('Erik Testsson', [
    'FirstName' => 'Erika',
    'LastName' => 'Testsson',
]) === false, 'Erik matchar inte Erika i Solkoll');
p3_assert(jeab_solkoll_names_match('Erik Testsson', [
    'FirstName' => 'Erik',
    'LastName' => 'Testsson',
]) === true, 'samma förnamn och efternamn matchar');
p3_assert(jeab_solkoll_names_match('Testsson', [
    'FirstName' => 'Erik',
    'LastName' => 'Testsson',
]) === false, 'saknat förnamn matchar inte på efternamn');
p3_assert(jeab_solkoll_names_match('Erik Testsson', [
    'FirstName' => '',
    'LastName' => 'Testsson',
]) === false, 'anläggning utan förnamn matchar inte på efternamn');

$erik = jeab_ordrar_match_owner([
    ['name' => 'Erika Testsson', 'personnummer' => '19800101-1111'],
    ['name' => 'Erik Testsson', 'personnummer' => '19700101-2222'],
], 'Erik Testsson');
p3_assert(($erik['personnummer'] ?? '') === '19700101-2222', 'ägarträff kräver samma förnamn');
$emptyOwner = jeab_ordrar_match_owner([
    ['name' => 'Erika Testsson', 'personnummer' => '19800101-1111'],
], '');
p3_assert(($emptyOwner['personnummer'] ?? '') === '', 'saknad identitet tar inte owners[0]');

p3_assert(FortnoxAPI::isTaxAmountTooHigh('Kunde inte spara belopp. Max rader.') === false, 'belopp/max sänker inte grön teknik');
p3_assert(FortnoxAPI::isTaxAmountTooHigh('Den ansökta summan överskrider möjligt belopp för Solceller') === true, 'riktigt för högt belopp känns igen');

$blocked = $tmp . '/not-a-directory';
file_put_contents($blocked, 'x');
putenv('JEAB_LOGIN_THROTTLE_FILE=' . $blocked . '/throttle.json');
$attempt = jeab_auth_attempt('fel-kod');
p3_assert(($attempt['ok'] ?? true) === false, 'trasig throttlefil misslyckas stängt');
p3_assert(str_contains((string) ($attempt['error'] ?? ''), 'just nu'), 'throttle fail-closed ger spärrtext');
putenv('JEAB_LOGIN_THROTTLE_FILE');

$xff = '203.0.113.9';
$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
p3_assert(jeab_auth_client_ip() === '198.51.100.10', 'publik REMOTE_ADDR litar inte på X-Forwarded-For');
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.20, 10.0.0.1';
p3_assert(jeab_auth_client_ip() === '203.0.113.20', 'proxy tar högra publika XFF-hoppet');
unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['REMOTE_ADDR']);

$GLOBALS['_JEAB_CSRF_AUTH_NONCE'] = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$tokenA = jeab_csrf_signed_token('auth:' . jeab_csrf_auth_nonce());
$GLOBALS['_JEAB_CSRF_AUTH_NONCE'] = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
$tokenB = jeab_csrf_signed_token('auth:' . jeab_csrf_auth_nonce());
p3_assert(is_string($tokenA) && is_string($tokenB) && $tokenA !== $tokenB, 'auth-CSRF roterar per session-nonce');
$emailToken = jeab_csrf_signed_token('email:keep@example.com');
p3_assert(is_string($emailToken) && $emailToken !== $tokenA, 'e-postidentitet har egen CSRF-token');

$smsSrc = (string) file_get_contents(dirname(__DIR__) . '/src/sms.php');
$smsStoreSrc = (string) file_get_contents(dirname(__DIR__) . '/src/sms_store.php');
p3_assert(!str_contains($smsSrc, 'fopen') && !str_contains($smsSrc, 'file_put_contents'), 'sms.php skriver inte sms-sent.json');
p3_assert(str_contains($smsStoreSrc, 'beginTransaction'), 'sms-raden sparas i en SQL-transaktion');
p3_assert(str_contains($smsSrc, "empty(\$row['email'])"), 'mejlflaggan slås ihop innan raden sparas');

$mailerSrc = (string) file_get_contents(dirname(__DIR__) . '/fullmakt/lib/mailer.php');
$signSrc = (string) file_get_contents(dirname(__DIR__) . '/fullmakt/sign.php');
$pdfSrc = (string) file_get_contents(dirname(__DIR__) . '/fullmakt/pdf.php');
p3_assert(!preg_match('/Token:\s*\{/', $mailerSrc) && str_contains($mailerSrc, 'signerat.pdf'), 'fullmakt-mejl bär inte token i body eller filnamn');
p3_assert(!str_contains($signSrc, 'token=' . '$token') && str_contains($signSrc, "fullmakt_log('mail.log', 'signed mailed="), 'signeringsloggen ekar inte token');
$createSrc = (string) file_get_contents(dirname(__DIR__) . '/fullmakt/api/create.php');
$inviteSrc = (string) file_get_contents(dirname(__DIR__) . '/fullmakt/api/invite.php');
p3_assert(!str_contains($createSrc, 'token=') && !str_contains($createSrc, "to='"), 'create-loggen har varken token eller mottagare');
p3_assert(str_contains($createSrc, "fullmakt_log('mail.log', 'invite mailed="), 'create loggar att inbjudan skickades');
p3_assert(str_contains($createSrc, "'token' => \$doc['token']"), 'create-svaret får fortfarande lämna token till anroparen');
p3_assert(!str_contains($inviteSrc, 'token=') && str_contains($inviteSrc, "fullmakt_log('mail.log', 'invite-resend mailed="), 'resend-loggen har inte token');
p3_assert(str_contains($inviteSrc, "'token' => \$token"), 'resend-svaret får fortfarande lämna token till anroparen');
p3_assert(str_contains($pdfSrc, 'filename="fullmakt.pdf"'), 'PDF-filnamnet är fullmakt.pdf');

$ipSrc = (string) file_get_contents(dirname(__DIR__) . '/fullmakt/lib/bootstrap.php');
p3_assert(str_contains($ipSrc, "REMOTE_ADDR") && !str_contains($ipSrc, 'HTTP_X_FORWARDED_FOR'), 'signerings-IP tar bara REMOTE_ADDR');

$httpsSaved = $_SERVER['HTTPS'] ?? null;
$portSaved = $_SERVER['SERVER_PORT'] ?? null;
$_SERVER['HTTPS'] = 'off';
unset($_SERVER['SERVER_PORT']);
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
p3_assert(jeab_is_https() === false, 'X-Forwarded-Proto ensamt sätter inte Secure');
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
if ($httpsSaved === null) {
    unset($_SERVER['HTTPS']);
} else {
    $_SERVER['HTTPS'] = $httpsSaved;
}
if ($portSaved === null) {
    unset($_SERVER['SERVER_PORT']);
} else {
    $_SERVER['SERVER_PORT'] = $portSaved;
}

$canonical = $tmp . '/fortnox/tokens.json';
@mkdir(dirname($canonical), 0775, true);
$store = [
    'Jarfalla' => [
        'refresh_token' => 'fixture-refresh-next',
        'access_token' => 'fixture-access',
        'expires_at' => time() + 3600,
    ],
];
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
p3_assert(jeab_fortnox_tokens_write_durable($canonical, $store) === true, 'Fortnox durable-skrivning lyckas till kanonisk fil');
$asDir = $tmp . '/fortnox-blocked';
@mkdir($asDir, 0775, true);
$blockedCanonical = $asDir . '/tokens.json';
if (is_file($blockedCanonical)) {
    unlink($blockedCanonical);
}
mkdir($blockedCanonical, 0775, true);
$prevData = $GLOBALS['_JEAB_DATA_DIR'];
$GLOBALS['_JEAB_DATA_DIR'] = $asDir;
$okRotated = jeab_fortnox_tokens_write_durable($blockedCanonical, $store);
p3_assert($okRotated === true, 'Fortnox skriver tokens.rotated.json när kanonisk sökväg inte går att skriva');
p3_assert(is_file(jeab_fortnox_tokens_rotated_path()), 'rotated-filen finns efter misslyckad kanonisk skrivning');
$GLOBALS['_JEAB_DATA_DIR'] = $prevData;

if ($prevPw === false) {
    putenv('JEAB_PASSWORDS_FILE');
} else {
    putenv('JEAB_PASSWORDS_FILE=' . $prevPw);
}

echo "p3 security: ok\n";
