<?php

declare(strict_types=1);

/**
 * Skalnavigering: partial-svar, inloggningsgrind och klientkontrakt.
 * Ingen kunddata. Ingen databas i arbetsrepot.
 */

require_once __DIR__ . '/jeab_test_proc_env.php';

$tmp = sys_get_temp_dir() . '/jeab-shell-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('JEAB_SHELL_TMP=' . $tmp);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
file_put_contents($tmp . '/store.json', "{}\n");
$pwFile = $tmp . '/pw.json';
$hash = password_hash('shell-test-kod', PASSWORD_DEFAULT);
$hashAgent = password_hash('agent-test-kod', PASSWORD_DEFAULT);
file_put_contents($pwFile, json_encode([
    'secret' => 'shell-test-secret',
    'hashes' => [
        ['hash' => $hash, 'email' => 'saljare4@jarfalla.example.test'],
        ['hash' => $hashAgent, 'email' => 'agenter@flowstack.example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $pwFile);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

function sh_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

if (($argv[1] ?? '') === '--emit') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_X_JEAB_PARTIAL'] = '1';
    $_GET = ['p' => 'links', 'co' => 'jarfalla'];
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['jeab_company'] = 'jarfalla';
    jeab_layout('Övrigt · JEAB', 'links', '<h1 class="crm-title">Övrigt</h1><a class="chip">Alla</a><a class="chip chip-who"><span class="chip-who-name">Testare</span></a>');
}

if (($argv[1] ?? '') === '--full') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_X_JEAB_PARTIAL']);
    $_GET = ['p' => 'links', 'co' => 'jarfalla'];
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    ob_start();
    jeab_layout('Övrigt · JEAB', 'links', '<h1 class="crm-title">Övrigt</h1>');
    $html = (string) ob_get_clean();
    echo (str_contains($html, '<!doctype html') ? 'DOCTYPE' : 'NODOCTYPE') . "\n";
    echo (str_contains($html, 'assets/js/shell.js') ? 'SHELL' : 'NOSHELL') . "\n";
    echo (preg_match('#<script[^>]+src="[^"]*assets/js/app\\.js[?"]#', $html) === 1 ? 'APP' : 'NOAPP') . "\n";
    echo (str_contains($html, 'jeabApplyTheme') ? 'THEME' : 'NOTHEME') . "\n";
    echo (str_contains($html, 'name="csrf"') ? 'CSRF' : 'NOCSRF') . "\n";
    $bare = [];
    if (preg_match_all('/<(?:script|link)\b[^>]*(?:src|href)\s*=\s*"([^"]+)"/', $html, $assetTags)) {
        foreach ($assetTags[1] as $assetHref) {
            if (preg_match('#^https?://#', $assetHref) === 1 || preg_match('#\.(?:js|css)(?:\?|$)#', $assetHref) !== 1) {
                continue;
            }
            if (preg_match('#(?:\?|&)v=[0-9a-f]+#', $assetHref) !== 1) {
                $bare[] = $assetHref;
            }
        }
    }
    echo ($bare === [] && preg_match('#assets/js/shell\.js\?v=[0-9a-f]+#', $html) === 1 ? 'ASSETV' : 'BAREASSET ' . implode(' ', $bare)) . "\n";
    exit;
}

$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTP_X_JEAB_PARTIAL']);
sh_ok(jeab_shell_wants_partial() === false, 'vanlig GET är inte en partial');
$_SERVER['HTTP_X_JEAB_PARTIAL'] = '1';
sh_ok(jeab_shell_wants_partial() === true, 'X-Jeab-Partial på GET är en partial');
$_SERVER['REQUEST_METHOD'] = 'POST';
sh_ok(jeab_shell_wants_partial() === false, 'POST blir aldrig partial');
$_SERVER['REQUEST_METHOD'] = 'GET';

$root = dirname(__DIR__);
$shell = (string) file_get_contents($root . '/assets/js/shell.js');
$layout = (string) file_get_contents($root . '/src/layout.php');
$shield = (string) file_get_contents($root . '/src/shield.php');
sh_ok(str_contains($layout, 'jeab_shell_emit') && str_contains($layout, 'assets/js/shell.js'), 'layout skickar partial och shell.js');
sh_ok(!str_contains($shield, 'jeab_shell'), 'shield.php är orörd av skalet');
sh_ok(str_contains($shell, 'history.pushState') && str_contains($shell, "addEventListener('popstate'"), 'pushState och popstate finns');
sh_ok(str_contains($shell, 'HOVER_MS = 65') && str_contains($shell, 'touchstart') && str_contains($shell, 'Sec-Purpose'), 'prefetch vid 65 ms, touchstart och Sec-Purpose');
sh_ok(str_contains($shell, 'function formControlTarget') && str_contains($shell, 'button, input, select, textarea, label, option'), 'förhämtning och klick hoppar över formulärkontroller');
sh_ok(str_contains($shell, 'sessionStorage') && !str_contains($shell, 'localStorage'), 'vyn ligger i sessionStorage och inte i localStorage');
sh_ok(str_contains($shell, "action.value === 'logout'") && str_contains($shell, 'jeab.view.'), 'utloggning rensar vyerna');
sh_ok(str_contains($shell, "performance.mark('jeab:click')") && str_contains($shell, "performance.mark('jeab:paint')") && str_contains($shell, "performance.mark('jeab:done')"), 'märken för klick, synlig och klar');
sh_ok(str_contains($shell, '_t=1') && str_contains($shell, "console.log('[jeab]'"), 'konsollogg bara när _t=1');
sh_ok(str_contains($shell, 'isInbox') && str_contains($shell, 'page-inkorg'), 'inkorgen lämnas åt sin egen navigering');
sh_ok(str_contains($shell, 'jeab-skel') && str_contains($shell, 'crm-title') && str_contains($shell, 'chip-who'), 'platshållaren har rubrik och filterpiller');
sh_ok(str_contains($shell, 'location.href = url'), 'fel faller tillbaka till vanlig navigering');
sh_ok(str_contains($shell, 'function pageStyleHrefs') && str_contains($shell, '/assets/css/ordrar.css'), 'skalet ber om sidans css vid hovring och klick');
sh_ok(str_contains($layout, 'jeab_critical_css') && !str_contains($layout, 'media="print"') && !str_contains($layout, 'fonts.googleapis.com'), 'kritisk css först och webbtypsnittet blockerar inte');
$appCss = (string) file_get_contents($root . '/assets/css/app.css');
sh_ok(str_contains($appCss, '.page-ordrar .ordrar-list') && str_contains($appCss, 'list-style: none'), 'ordrar-listan har ram i app.css innan ordrar.css');
$ht = (string) file_get_contents($root . '/.htaccess');
$nx = (string) file_get_contents($root . '/.github/loopia-nginx.conf');
sh_ok(str_contains($ht, 'JEAB_ASSET_IMMUTABLE') && str_contains($nx, 'max-age=31536000, immutable'), 'versionerade assets får lång cache i htaccess och nginx');

$emit = shell_exec('php ' . escapeshellarg(__FILE__) . ' --emit');
$json = json_decode((string) $emit, true);
sh_ok(is_array($json) && ($json['ok'] ?? false) === true, 'partial är JSON');
sh_ok(($json['page'] ?? '') === 'links' && str_contains((string) ($json['html'] ?? ''), 'crm-title'), 'partial bär sidans html');
sh_ok(str_contains((string) ($json['chrome'] ?? ''), 'nav class="tabs"') && str_contains((string) ($json['chrome'] ?? ''), 'Påminnelser'), 'partial bär menyn');
sh_ok(!str_contains((string) $emit, '<!doctype'), 'partial är inte ett helt dokument');
sh_ok(is_string($json['csrf'] ?? null) && $json['csrf'] !== '', 'partial har CSRF-token');
sh_ok(in_array('links', explode(' ', (string) ($json['bodyClass'] ?? '')), true) || str_contains((string) ($json['bodyClass'] ?? ''), 'page-links'), 'body-klass följer med');

$full = shell_exec('php ' . escapeshellarg(__FILE__) . ' --full');
sh_ok(str_contains((string) $full, 'DOCTYPE') && str_contains((string) $full, 'SHELL') && str_contains((string) $full, 'CSRF'), 'hel sida har doctype, shell.js och csrf');
sh_ok(str_contains((string) $full, 'NOAPP') && str_contains((string) $full, 'THEME'), 'Övrigt hoppar över app.js och behåller temaknappen: ' . trim((string) $full));
sh_ok(str_contains((string) $full, 'ASSETV'), 'hel sida har ?v=hash på lokal css och js: ' . trim((string) $full));

jeab_auth_data(true);
$cookie = jeab_auth_issue_value('saljare4@jarfalla.example.test', $hash);
$adminCookie = jeab_auth_issue_value('agenter@flowstack.example.test', $hashAgent);
$agentFp = jeab_auth_hash_fingerprint($hashAgent);
file_put_contents($tmp . '/code-people.json', json_encode([
    'fp:' . $agentFp => ['person' => 'SamL', 'via' => 'prefix', 'head' => 'SamL', 'at' => '2026-01-01 00:00:00'],
], JSON_UNESCAPED_UNICODE));
sh_ok(is_string($cookie) && $cookie !== '' && is_string($adminCookie) && $adminCookie !== '', 'testkaka utfärdad');

$router = $tmp . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
$tmp = getenv('JEAB_SHELL_TMP');
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_PASSWORDS_FILE=' . $tmp . '/pw.json');
putenv('JEAB_DISABLE_SQLITE=1');
$uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if ($uri === '/index.php' || $uri === '/') {
    require dirname(__DIR__, 3) . '/index.php';
    return true;
}
return false;
PHP);
// Routern ligger i temp, inte i repot. Peka på workspace-roten.
$workspace = dirname(__DIR__);
file_put_contents($router, "<?php\n" . jeab_test_sqlite_boot() . <<<PHP
\$tmp = getenv('JEAB_SHELL_TMP');
\$GLOBALS['_JEAB_DATA_DIR'] = \$tmp;
putenv('JEAB_PASSWORDS_FILE=' . \$tmp . '/pw.json');
putenv('JEAB_DISABLE_SQLITE=1');
\$uri = (string) (parse_url((string) (\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
if (\$uri === '/index.php' || \$uri === '/') {
    require '{$workspace}/index.php';
    return true;
}
return false;
PHP);

$port = 8877;
$server = proc_open(
    ['php', '-S', '127.0.0.1:' . $port, '-t', $workspace, $router],
    [1 => ['file', $tmp . '/server.out', 'w'], 2 => ['file', $tmp . '/server.err', 'w']],
    $pipes,
    $workspace
);
sh_ok(is_resource($server), 'php -S startade');
register_shutdown_function(static function () use ($server): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
});
$deadline = microtime(true) + 5;
do {
    $probe = @file_get_contents('http://127.0.0.1:' . $port . '/index.php?p=links');
    if (is_string($probe) && $probe !== '') {
        break;
    }
    usleep(80000);
} while (microtime(true) < $deadline);

function sh_curl(string $url, array $headers, string $method = 'GET'): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$status, substr($raw, 0, $headerSize), substr($raw, $headerSize)];
}

$anon = sh_curl('http://127.0.0.1:' . $port . '/index.php?p=reminders', ['X-Jeab-Partial: 1', 'Accept: text/html, application/json']);
sh_ok($anon[0] === 200 && !str_contains($anon[2], '"ok":true') && (str_contains($anon[2], 'kampanjkod') || str_contains($anon[2], 'login')), 'ogrindad partial blir inloggning, inte appdata');

$auth = ['Cookie: jeab_auth=' . rawurlencode((string) $cookie), 'X-Jeab-Partial: 1', 'Accept: text/html, application/json'];
$pages = [
    'links' => 'links-page',
    'reminders' => 'Påminnelser',
    'bookings' => 'Kalender',
    'ordrar' => 'Ordrar',
    'offert' => 'offert',
    'kontakter' => 'Kontakter',
    'notes' => 'Interna anteckningar',
    'notiser' => 'Notiser',
    'utskick' => 'Utskick',
    'logg' => 'Fellogg',
    'bugg' => 'Rapportera bugg',
    'zapier' => 'Zapier',
    'manuell-order' => 'Manuell order',
    'fullmakter' => 'E-signering',
];
foreach ($pages as $page => $needle) {
    if ($page === 'zapier') {
        continue;
    }
    $res = sh_curl('http://127.0.0.1:' . $port . '/index.php?p=' . $page . '&co=jarfalla', $auth);
    $body = $res[2];
    $decoded = json_decode($body, true);
    $html = (string) ($decoded['html'] ?? '');
    $expectPage = in_array($page, ['logg', 'bugg', 'fullmakter'], true) ? 'links' : $page;
    $ok = $res[0] === 200 && is_array($decoded) && ($decoded['ok'] ?? false) === true && ($decoded['page'] ?? '') === $expectPage && str_contains($html, $needle);
    if (!$ok) {
        fwrite(STDERR, "status {$res[0]} page {$page} head " . substr(preg_replace('/\s+/', ' ', $body) ?? '', 0, 240) . "\n");
    }
    sh_ok($ok, "curl partial {$page}");
    if ($page === 'links') {
        sh_ok(str_contains($html, '>E-signering<') && str_contains($html, 'p=fullmakter') && !str_contains($html, 'Signerade fullmakter'), 'Övrigt-menyn säger E-signering och behåller p=fullmakter');
    }
    if ($page === 'fullmakter') {
        sh_ok(($decoded['title'] ?? '') === 'E-signering · JEAB' && str_contains($html, '<h1>E-signering</h1>'), 'fullmakter-sidan har rubrik och titel E-signering');
    }
    sh_ok(str_contains($res[1], 'X-Jeab-Partial: 1') && str_contains($res[1], 'application/json'), "curl {$page} är JSON-partial");
    $assetBare = [];
    foreach (array_merge([$html], (array) ($decoded['styles'] ?? [])) as $assetChunk) {
        if (!is_string($assetChunk)) {
            continue;
        }
        if (preg_match_all('/(?:src|href)\s*=\s*"([^"]+)"/', $assetChunk, $assetTags) !== false) {
            foreach ($assetTags[1] as $assetHref) {
                if (preg_match('#^https?://#', $assetHref) === 1 || preg_match('#\.(?:js|css)(?:\?|$)#', $assetHref) !== 1) {
                    continue;
                }
                if (preg_match('#(?:\?|&)v=[0-9a-f]+#', $assetHref) !== 1) {
                    $assetBare[] = $assetHref;
                }
            }
        }
        if (preg_match('#\.(?:js|css)(?:\?|$)#', $assetChunk) === 1 && preg_match('#^https?://#', $assetChunk) !== 1 && preg_match('#(?:\?|&)v=[0-9a-f]+#', $assetChunk) !== 1) {
            $assetBare[] = $assetChunk;
        }
    }
    sh_ok($assetBare === [], "curl {$page} har ?v=hash på css och js");
}

$redir = sh_curl('http://127.0.0.1:' . $port . '/index.php?p=notiser&co=malmo&st=Aktiv', ['Cookie: jeab_auth=' . rawurlencode((string) $cookie)]);
sh_ok($redir[0] === 302 && str_contains($redir[1], 'flik=notiser') && str_contains($redir[1], 'co=malmo') && str_contains($redir[1], 'st=Aktiv'), 'GET p=notiser styrs om till notisfliken');
$fileHit = sh_curl('http://127.0.0.1:' . $port . '/index.php?p=notiser&fil=laddbox_jarfalla', ['Cookie: jeab_auth=' . rawurlencode((string) $cookie)]);
sh_ok($fileHit[0] !== 302 && !str_contains($fileHit[1], 'Location:'), 'p=notiser&fil styrs inte om');
$off = sh_curl('http://127.0.0.1:' . $port . '/index.php?p=offertutskick', ['Cookie: jeab_auth=' . rawurlencode((string) $adminCookie)]);
sh_ok($off[0] === 302 && str_contains($off[1], 'flik=offerter'), 'GET p=offertutskick styrs om till offertfliken');
$offStaff = sh_curl('http://127.0.0.1:' . $port . '/index.php?p=offertutskick', ['Cookie: jeab_auth=' . rawurlencode((string) $cookie)]);
sh_ok($offStaff[0] === 403 && str_contains($offStaff[2], 'Bara admin.'), 'personal får 403 på gamla offertadressen');

$zapierDenied = sh_curl('http://127.0.0.1:' . $port . '/index.php?p=zapier&co=jarfalla', $auth);
sh_ok($zapierDenied[0] === 403 && str_contains($zapierDenied[2], 'Inte behörig.'), 'icke-admin får 403 på Zapier');
sh_ok(!str_contains($zapierDenied[2], 'settings.webhook') && !str_contains($zapierDenied[2], 'settings.incoming_token') && !str_contains($zapierDenied[2], 'Ny token'), '403 visar varken webhook eller Ny token');

$adminAuth = ['Cookie: jeab_auth=' . rawurlencode((string) $adminCookie), 'X-Jeab-Partial: 1', 'Accept: text/html, application/json'];
$res = sh_curl('http://127.0.0.1:' . $port . '/index.php?p=zapier&co=jarfalla', $adminAuth);
$body = $res[2];
$decoded = json_decode($body, true);
$html = (string) ($decoded['html'] ?? '');
$ok = $res[0] === 200 && is_array($decoded) && ($decoded['ok'] ?? false) === true && ($decoded['page'] ?? '') === 'zapier' && str_contains($html, 'Zapier');
if (!$ok) {
    fwrite(STDERR, "status {$res[0]} page zapier head " . substr(preg_replace('/\s+/', ' ', $body) ?? '', 0, 240) . "\n");
}
sh_ok($ok, 'curl partial zapier');
sh_ok(str_contains($res[1], 'X-Jeab-Partial: 1') && str_contains($res[1], 'application/json'), 'curl zapier är JSON-partial');
sh_ok(str_contains($html, 'settings.webhook') && str_contains($html, '>Ny token<'), 'admin-partial har webhook och knappen Ny token');
$tokenField = '';
if (preg_match('/<span>Token<\/span>\s*<input\b[^>]*\bvalue="([^"]*)"/', $html, $tokenMatch) === 1) {
    $tokenField = html_entity_decode($tokenMatch[1], ENT_QUOTES);
}
sh_ok($tokenField === '' || (str_starts_with($tokenField, str_repeat('•', 8)) && preg_match('/^[a-f0-9]{16,}$/', $tokenField) !== 1), 'admin-partial visar ingen token i klartext');

foreach (['jarfalla', 'malmo'] as $co) {
    $res = sh_curl('http://127.0.0.1:' . $port . '/index.php?p=offert&co=' . $co, $auth);
    $decoded = json_decode($res[2], true);
    $html = (string) ($decoded['html'] ?? '');
    sh_ok($res[0] === 200 && ($decoded['page'] ?? '') === 'offert', "curl kalkyl {$co}");
    sh_ok(substr_count($html, 'name="sigen_typ"') === 3, "curl {$co} har ett systemval: NEO, TP2, EC");
    sh_ok(substr_count($html, 'name="sigen_kw"') >= 1, "curl {$co} har storlek");
    sh_ok(preg_match_all('/name="sigen_typ"[^>]*checked/', $html) === 1, "curl {$co} har ett valt system");
    sh_ok(str_contains($html, 'data-js="offert-step"'), "curl {$co} har antal-knappar");
}

$post = sh_curl('http://127.0.0.1:' . $port . '/index.php?p=links', $auth, 'POST');
sh_ok(!str_contains($post[1], 'X-Jeab-Partial'), 'POST med headern blir inte en partial');

$pref = sh_curl('http://127.0.0.1:' . $port . '/index.php?p=links&co=jarfalla', array_merge($auth, ['Sec-Purpose: prefetch']));
$prefJson = json_decode($pref[2], true);
sh_ok($pref[0] === 200 && is_array($prefJson) && ($prefJson['page'] ?? '') === 'links', 'prefetch-partial av Övrigt svarar');

proc_terminate($server);
proc_close($server);

echo "OK  shell-nav\n";
