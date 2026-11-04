<?php

declare(strict_types=1);

/**
 * P2: CSP, delad spärrlista, privat sqlite, fullmakt flyttas på vanlig request,
 * webhook-token skrivs inte under webbträdet, /bokning/ pekar på apex.
 */

$tmp = sys_get_temp_dir() . '/jeab-p2-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp . '/private';
if (!is_dir($GLOBALS['_JEAB_DATA_DIR']) && !mkdir($GLOBALS['_JEAB_DATA_DIR'], 0700, true)) {
    fwrite(STDERR, "FAIL private temp\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

function p2_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$root = JEAB_ROOT;
$rules = jeab_web_deny_rules();
foreach (['data', 'schema', 'src', 'tests', 'fullmakt/data', 'fullmakt/lib', 'fullmakt/vendor', 'gi/data'] as $prefix) {
    p2_assert(in_array($prefix, $rules['prefixes'], true), "spärrlistan har prefix {$prefix}");
}
p2_assert(in_array('README.md', $rules['exact'], true), 'spärrlistan har README.md');
p2_assert(in_array('AGENTS.md', $rules['exact'], true), 'spärrlistan har AGENTS.md');

$spec = (string) file_get_contents($root . '/deploy/sensitive-paths.txt');
$htaccess = (string) file_get_contents($root . '/.htaccess');
p2_assert(str_contains($htaccess, 'RewriteRule (?:^|/)(?:README|AGENTS)\\.md$ - [F,L,NC]'), 'htaccess spärrar README i valfri katalog');
p2_assert(str_contains($spec, 'prefix tests'), 'deploy-listan och skölden delar tests');
p2_assert(str_contains($spec, 'prefix src'), 'deploy-listan och skölden delar src');

foreach ([
    '/private/deploying.flag',
    '/private/backups/20260101-000000/manifest.json',
    '/private',
    '/tests/missing.php',
    '/tests',
    '/src/no-such.php',
    '/data/notes.json',
    '/schema/mysql.php',
    '/fullmakt/data/secret.php',
    '/fullmakt/data/pending/not-a-real-token.json',
    '/fullmakt/lib/store.php',
    '/fullmakt/lib/bootstrap.php',
    '/fullmakt/lib/mailer.php',
    '/fullmakt/lib/pdf.php',
    '/fullmakt/vendor/fpdf/fpdf.php',
    '/fullmakt/vendor/fpdi/autoload.php',
    '/gi/data/loans.json',
    '/gi/data/photos/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg',
    '/README.md',
    '/AGENTS.md',
    '/fullmakt/README.md',
    '/fullmakt/README.md?x=1',
    '/fullmakt%2FREADME.md',
    '/fullmakt/readme.MD',
    '/fullmakt/AGENTS.md',
    '/nested/README.md',
] as $blocked) {
    p2_assert(jeab_http_path_is_sensitive($blocked) === true, "{$blocked} är 403-spärrad");
}
foreach ([
    '/',
    '/index.php',
    '/fullmakt/sign.php',
    '/fullmakt/pdf.php',
    '/fullmakt/tack.php',
    '/fullmakt/api/create.php',
    '/fullmakt/api/invite.php',
    '/fullmakt/assets/app.css',
    '/fullmakt/notes.md',
    '/fullmakt/README.md/extra',
    '/fullmakt/preview.php',
    '/fullmakt/s/not-a-real-token',
    '/bokning/',
    '/bokning/index.php',
    '/gi/',
    '/gi/photo.php',
    '/gi/lib.php',
    '/gi/style.css',
    '/gi/index.php',
] as $open) {
    p2_assert(jeab_http_path_is_sensitive($open) === false, "{$open} är inte en data-spärr");
}

$csp = jeab_csp_value();
p2_assert(str_contains($csp, "script-src 'self' 'unsafe-inline'"), 'CSP tillåter inline-script');
p2_assert(str_contains($csp, "font-src 'self'"), 'CSP font-src är self');
p2_assert(!str_contains($csp, 'fonts.googleapis.com'), 'CSP släpper inte in Google Fonts när filerna finns lokalt');
p2_assert(!str_contains($csp, 'fonts.gstatic.com'), 'CSP släpper inte in gstatic när filerna finns lokalt');
$cspGoogle = jeab_csp_value('self', false);
p2_assert(!str_contains($cspGoogle, 'fonts.googleapis.com'), 'CSP öppnar inte Google Fonts CSS');
p2_assert(!str_contains($cspGoogle, 'fonts.gstatic.com'), 'CSP öppnar inte gstatic');
p2_assert(str_contains($csp, "img-src 'self' data: blob:"), 'CSP tillåter data- och blob-bilder');
p2_assert(str_contains($csp, "frame-ancestors 'self'"), 'appens CSP är frame-ancestors self');
p2_assert(str_contains($csp, "form-action 'self'"), 'CSP form-action behåller self');
p2_assert(!str_contains($csp, 'nylas'), 'CSP form-action släpper inte Nylas');
p2_assert(str_contains($csp, 'https://login.microsoftonline.com'), 'CSP form-action tillåter Microsoft-inloggning');
p2_assert(str_contains($csp, 'https://login.microsoftonline.com'), 'CSP form-action tillåter Microsoft-login');
p2_assert(str_contains(jeab_csp_value('none'), "frame-ancestors 'none'"), 'fullmakt-CSP är frame-ancestors none');
p2_assert(jeab_frame_options_value() === 'SAMEORIGIN', 'appen sätter X-Frame-Options SAMEORIGIN');
p2_assert(!str_contains($csp, 'includeSubDomains'), 'CSP nämner inte includeSubDomains');
p2_assert(jeab_hsts_value() === null || !str_contains((string) jeab_hsts_value(), 'includeSubDomains'), 'HSTS har inte includeSubDomains');
$fullmaktBoot = (string) file_get_contents($root . '/fullmakt/lib/bootstrap.php');
p2_assert(str_contains($fullmaktBoot, "header('X-Frame-Options: DENY')"), 'fullmakt behåller X-Frame-Options DENY');
p2_assert(str_contains($fullmaktBoot, "jeab_csp_value('none')"), 'fullmakt-CSP sätter inte SAMEORIGIN');
p2_assert(!str_contains($fullmaktBoot, '0777'), 'fullmakt bootstrap använder inte 0777');

p2_assert(JEAB_AUTH_TTL === 90 * 24 * 60 * 60, 'kampanjkakan är 90 dagar');
$bootSrc = (string) file_get_contents($root . '/src/bootstrap.php');
p2_assert(str_contains($bootSrc, "'lifetime' => 60 * 60 * 24 * 14"), 'sessionskakan jeab är kvar på 14 dagar');
p2_assert(!str_contains($bootSrc, "jeab_data_file('incoming-token.txt')"), 'bootstrap skriver inte incoming-token.txt via data-fil');

$sqlite = (string) jeab_config()['db']['sqlite_path'];
p2_assert(str_starts_with(str_replace('\\', '/', $sqlite), str_replace('\\', '/', jeab_data_dir()) . '/'), 'sqlite-default ligger i privat katalog');
p2_assert(jeab_store_path_is_public_data($sqlite) === false, 'sqlite-default är inte publik data/');

$_SERVER['QUERY_STRING'] = 'p=bookings&x=1';
$_SERVER['REQUEST_URI'] = '/bokning/?p=bookings&x=1';
p2_assert(jeab_request_is_bokning_index() === true, '/bokning/ känns igen');
p2_assert(jeab_bokning_apex_target() === '/index.php?p=bookings&x=1', '/bokning/ går till apex med samma query');
p2_assert(jeab_request_is_bokning_index('/bokning/rot') === false, '/bokning/rot är inte index-redirecten');
$bokningSrc = (string) file_get_contents($root . '/bokning/index.php');
p2_assert(str_contains($bokningSrc, 'jeab_bokning_apex_target()'), 'bokning/index.php redirectar utan .htaccess');
p2_assert(!str_contains($bokningSrc, 'bootstrap.php'), 'bokning/index.php laddar inte bootstrap före redirect');

$public = $tmp . '/public';
mkdir($public, 0775, true);
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
$legacyToken = 'p2-legacy-token-value';
file_put_contents($public . '/incoming-token.txt', $legacyToken . "\n");
$store = jeab_store(true);
$read = jeab_ensure_incoming_token($store);
p2_assert($read === $legacyToken, 'befintlig token-fil validerar fortfarande');
p2_assert(is_file($public . '/incoming-token.txt'), 'enda kopian ligger kvar när privat/ saknas');
p2_assert(!is_file(jeab_data_dir() . '/incoming-token.txt'), 'ingen ny token-fil i data/.private');
p2_assert(!is_file($public . '/incoming-token-new.txt'), 'ingen extra publik token-fil');
@unlink($public . '/incoming-token.txt');
$generated = jeab_ensure_incoming_token($store);
p2_assert($generated !== '', 'en token finns utan fil');
p2_assert(!is_file(jeab_data_dir() . '/incoming-token.txt'), 'ny token skapar inte incoming-token.txt i .private');
p2_assert(!is_file($public . '/incoming-token.txt'), 'ny token skapar inte incoming-token.txt i publik data/');

$blockedFile = $tmp . '/blocked-file';
file_put_contents($blockedFile, 'x');
$pubBlocked = $tmp . '/public-blocked';
mkdir($pubBlocked, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $blockedFile . '/private';
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $pubBlocked;
jeab_config(true);
$dead = jeab_store(true);
p2_assert($dead instanceof JeabUnavailableStore, 'saknad privat katalog ger ingen 500-store');
p2_assert(!is_file($pubBlocked . '/app.sqlite'), 'ingen ny publik sqlite skapas');
p2_assert(!is_file($pubBlocked . '/store.json'), 'ingen ny publik store.json skapas');
p2_assert(!is_file($blockedFile . '/private/app.sqlite'), 'sqlite skapas inte när katalogen inte går');

$sqlitePrivate = $tmp . '/sqlite-private';
$sqlitePublic = $tmp . '/sqlite-public';
mkdir($sqlitePrivate, 0700, true);
mkdir($sqlitePublic, 0775, true);
file_put_contents($sqlitePublic . '/app.sqlite', 'sqlite-bytes');
$GLOBALS['_JEAB_DATA_DIR'] = $sqlitePrivate;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $sqlitePublic;
jeab_shield_relocate_sqlite();
p2_assert(!is_file($sqlitePublic . '/app.sqlite'), 'publik sqlite flyttas bort');
p2_assert(is_file($sqlitePrivate . '/app.sqlite'), 'sqlite landar i privat katalog');
p2_assert(file_get_contents($sqlitePrivate . '/app.sqlite') === 'sqlite-bytes', 'sqlite-innehållet följde med');

$fixture = "<?php\n\$FULLMAKT_KEY = 'p2-fixture-not-a-real-secret';\n";
$legacyDir = $root . '/fullmakt/data';
$legacySecret = $legacyDir . '/secret.php';
$legacyPending = $legacyDir . '/pending/not-a-real-token.json';
$fmParent = $tmp . '/fm-parent';
mkdir($fmParent, 0700, true);
$canonicalDir = $fmParent . '/fullmakt';
putenv('FULLMAKT_DATA=' . $canonicalDir);
$realArchive = $root . '/data/.private/fullmakt';
$asideArchive = $root . '/data/.private/fullmakt.p2-aside';
if (is_dir($realArchive) && !is_dir($asideArchive)) {
    rename($realArchive, $asideArchive);
}
$cleanup = static function () use ($legacyDir, $legacySecret, $legacyPending, $fixture, $canonicalDir, $fmParent, $realArchive, $asideArchive): void {
    if (is_file($legacySecret) && is_readable($legacySecret) && file_get_contents($legacySecret) === $fixture) {
        @unlink($legacySecret);
    }
    if (is_file($legacyPending)) {
        @unlink($legacyPending);
    }
    @rmdir($legacyDir . '/pending');
    @rmdir($legacyDir);
    $canonical = $canonicalDir . '/secret.php';
    if (is_file($canonical)) {
        @unlink($canonical);
    }
    $movedPending = $canonicalDir . '/pending/not-a-real-token.json';
    if (is_file($movedPending)) {
        @unlink($movedPending);
    }
    @rmdir($canonicalDir . '/pending');
    @rmdir($canonicalDir . '/signed');
    @rmdir($canonicalDir . '/tmp');
    @rmdir($canonicalDir . '/originals');
    @rmdir($canonicalDir);
    @chmod($fmParent, 0700);
    @rmdir($fmParent);
    putenv('FULLMAKT_DATA');
    if (is_dir($asideArchive) && !is_dir($realArchive)) {
        @rename($asideArchive, $realArchive);
    }
};

try {
    if (!is_dir($legacyDir . '/pending') && !mkdir($legacyDir . '/pending', 0700, true)) {
        p2_assert(false, 'kunde inte plantera legacy-katalog');
    }
    file_put_contents($legacySecret, $fixture);
    file_put_contents($legacyPending, '{"token":"not-a-real-token"}');
    chmod($fmParent, 0555);
    clearstatcache(true, $fmParent);
    jeab_shield_relocate_fullmakt();
    p2_assert(is_file($legacySecret), 'enda secret.php ligger kvar när privat katalog inte går att skapa');
    p2_assert(file_get_contents($legacySecret) === $fixture, 'kvarlämnad secret.php är oförändrad');
    chmod($fmParent, 0700);
    clearstatcache(true, $fmParent);
    jeab_shield_relocate_fullmakt();
    $canonical = $canonicalDir . '/secret.php';
    p2_assert(is_file($canonical), 'secret.php finns i privat katalog efter vanlig flytt');
    p2_assert(!is_file($legacySecret), 'secret.php är borta från webbroten');
    p2_assert(filesize($canonical) === strlen($fixture), 'privat kopia har samma storlek');
    p2_assert(file_get_contents($canonical) === $fixture, 'privat kopia matchar källan');
    p2_assert((fileperms($canonical) & 0777) === 0600, 'secret.php är 0600');
    p2_assert((fileperms($canonicalDir) & 0777) === 0700, 'fullmakt-katalogen är 0700');
    p2_assert(!is_file($legacyPending), 'pending-json är borta från webbroten');
    p2_assert(is_file($canonicalDir . '/pending/not-a-real-token.json'), 'pending-json följde med');
} finally {
    $cleanup();
}

$giLib = (string) file_get_contents($root . '/gi/lib.php');
p2_assert(str_contains($giLib, 'jeab_gi_loans_file'), 'GI läser lånearkivet via privat sökväg');
p2_assert(str_contains($giLib, "return __DIR__ . '/data/photos'"), 'fotokatalogen är kvar för photo.php');
p2_assert(!is_file($root . '/gi/photo.php'), 'photo.php ändras inte i repot');

$loanFixture = "{\"loans\":[]}\n";
$giData = $root . '/gi/data';
$giPhotos = $giData . '/photos';
$publicLoan = $giData . '/loans.json';
$photoName = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg';
$publicPhoto = $giPhotos . '/' . $photoName;
$savedPrivat = $GLOBALS['_JEAB_PRIVAT_DIR'] ?? null;
$savedDataDir = $GLOBALS['_JEAB_DATA_DIR'];
if (!is_dir($giPhotos) && !mkdir($giPhotos, 0775, true)) {
    p2_assert(false, 'kunde inte skapa gi/data/photos');
}
file_put_contents($publicPhoto, 'jpeg-bytes');
file_put_contents($publicLoan, $loanFixture);
$blockedLoanDir = $tmp . '/gi-blocked-file';
file_put_contents($blockedLoanDir, 'x');
$GLOBALS['_JEAB_DATA_DIR'] = $blockedLoanDir . '/private';
unset($GLOBALS['_JEAB_PRIVAT_DIR']);
jeab_shield_relocate_gi_loans();
p2_assert(is_file($publicLoan), 'enda loans.json ligger kvar när privat katalog inte går att skapa');
p2_assert(is_file($publicPhoto), 'fotot rörs inte när flytten av lån misslyckas');
$GLOBALS['_JEAB_DATA_DIR'] = $savedDataDir;
jeab_shield_relocate_gi_loans();
$privateLoan = rtrim((string) $savedDataDir, '/') . '/gi/loans.json';
p2_assert(is_file($privateLoan), 'loans.json landar i privat katalog');
p2_assert(!is_file($publicLoan), 'loans.json är borta som statisk fil');
p2_assert(filesize($privateLoan) === strlen($loanFixture), 'privat loans.json har samma storlek');
p2_assert(file_get_contents($privateLoan) === $loanFixture, 'privat loans.json matchar källan');
p2_assert((fileperms($privateLoan) & 0777) === 0600, 'privat loans.json är 0600');
p2_assert(is_file($publicPhoto) && file_get_contents($publicPhoto) === 'jpeg-bytes', 'fotot ligger kvar i gi/data/photos');
$privatDir = $tmp . '/privat';
mkdir($privatDir, 0700, true);
file_put_contents($publicLoan, $loanFixture);
$GLOBALS['_JEAB_PRIVAT_DIR'] = $privatDir;
jeab_shield_relocate_gi_loans();
$outsideLoan = $privatDir . '/gi/loans.json';
p2_assert(is_file($outsideLoan), 'loans.json kan landa i privat/ utanför webbroten');
p2_assert(!is_file($publicLoan), 'publik loans.json tas bort när privat/-kopian finns');
p2_assert(filesize($outsideLoan) === strlen($loanFixture), 'privat/-kopian har samma storlek');
if ($savedPrivat === null) {
    unset($GLOBALS['_JEAB_PRIVAT_DIR']);
} else {
    $GLOBALS['_JEAB_PRIVAT_DIR'] = $savedPrivat;
}
@unlink($publicLoan);
@unlink($privateLoan);
@unlink($outsideLoan);
@unlink($publicPhoto);
@rmdir($privatDir . '/gi');
@rmdir($privatDir);
@rmdir(dirname($privateLoan));
@rmdir($giPhotos);
@rmdir($giData);

p2_assert(!is_file($root . '/rot/add_to_fortnox.php.unused-20260925'), 'oanvänd fortnox-fil är borttagen');
p2_assert(!is_file($root . '/rot/add_to_fortnox.php'), 'add_to_fortnox.php återupplivas inte');
p2_assert(!is_file($root . '/data/.private/tinyfm_allowlist.txt'), 'skarpa TinyFM-listan ligger inte i arbetskopian');

echo "p2 security: ok\n";
