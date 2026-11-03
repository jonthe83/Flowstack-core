<?php
/**
 * Flowstack-kärnan - installationsguide.
 * Licens: AGPL-3.0-or-later. Inga externa beroenden.
 * Lägg filen i appens rot (bredvid index.php och src/) och öppna den i webbläsaren.
 */
declare(strict_types=1);

const FS_MIN_PHP = '8.0.0';
$root = __DIR__;
$dataDir = $root . '/data';
$privDir = $dataDir . '/.private';
$lockFile = $dataDir . '/.installed';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function page(string $title, string $body): void
{
    echo '<!doctype html><html lang="sv"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . h($title) . ' - Flowstack</title><style>'
        . 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f4f6f8;color:#1d2733;margin:0}'
        . '.w{max-width:680px;margin:40px auto;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.07);padding:28px 32px}'
        . 'h1{font-size:1.4rem;margin:0 0 4px}.sub{color:#66717d;margin:0 0 20px}'
        . 'table{width:100%;border-collapse:collapse;margin:12px 0}td{padding:6px 4px;border-bottom:1px solid #eef0f2}'
        . '.ok{color:#137333;font-weight:600}.bad{color:#b3261e;font-weight:600}.warn{color:#8a5a00;font-weight:600}'
        . 'label{display:block;margin:12px 0 4px;font-weight:600}input[type=text],input[type=password]{width:100%;box-sizing:border-box;padding:9px;border:1px solid #c9d0d6;border-radius:8px;font-size:1rem}'
        . 'button{margin-top:18px;background:#1a73e8;color:#fff;border:0;border-radius:8px;padding:10px 18px;font-size:1rem;cursor:pointer}'
        . '.box{padding:12px 14px;border-radius:8px;margin:12px 0}.e{background:#fdecea;color:#7a1712}.i{background:#e8f0fe;color:#123a7a}.s{background:#e6f4ea;color:#0d4d22}'
        . 'code{background:#f1f3f4;padding:1px 5px;border-radius:4px}.steps{color:#66717d;font-size:.9rem}'
        . '</style></head><body><div class="w">' . $body . '</div></body></html>';
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('fs_install');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
/* ---------- (g) Låst efter lyckad installation ---------- */
if (is_file($lockFile) && (($_SESSION['step'] ?? '') !== 'klar')) {
    http_response_code(403);
    page('Redan installerad', '<h1>Flowstack är redan installerat</h1>'
        . '<p>Installationsguiden är låst för att ingen ska kunna köra om den och skriva över dina inställningar.</p>'
        . '<div class="box i"><b>Så låser du upp (bara om du verkligen vill installera om):</b><ol>'
        . '<li>Logga in på webbhotellets filhanterare eller FTP.</li>'
        . '<li>Ta bort filen <code>data/.installed</code>.</li>'
        . '<li>Ladda om den här sidan.</li></ol>'
        . 'Befintlig <code>data/.private/config.php</code> och databas skrivs då över. Ta en säkerhetskopia först.</div>'
        . '<p>Rekommendation: ta bort <code>install.php</code> från servern när allt fungerar.</p>');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['csrf'];
$csrfField = '<input type="hidden" name="csrf" value="' . h($csrf) . '">';

/* ---------- Hjälpfunktioner ---------- */
function fs_requirements(): array
{
    $r = [];
    $r[] = ['PHP ' . PHP_VERSION . ' (kräver 8.0 eller senare)', version_compare(PHP_VERSION, FS_MIN_PHP, '>='), true];
    foreach (['mbstring', 'curl', 'intl', 'gd', 'zip', 'pdo', 'json', 'openssl'] as $ext) {
        $r[] = ['Tillägget ' . $ext, extension_loaded($ext), in_array($ext, ['mbstring', 'pdo', 'json'], true)];
    }
    $sqlite = extension_loaded('pdo_sqlite');
    $mysql = extension_loaded('pdo_mysql');
    $r[] = ['pdo_sqlite ELLER pdo_mysql (' . ($sqlite ? 'sqlite' : '') . ($sqlite && $mysql ? ' + ' : '') . ($mysql ? 'mysql' : '') . ')', $sqlite || $mysql, true];
    return $r;
}

function fs_sqlite_works(string $privDir): bool
{
    if (!extension_loaded('pdo_sqlite')) { return false; }
    try {
        $p = new PDO('sqlite:' . $privDir . '/.install-probe.sqlite');
        $p->exec('CREATE TABLE IF NOT EXISTS t (x INTEGER)');
        $p = null;
        @unlink($privDir . '/.install-probe.sqlite');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function fs_mysql_pdo(array $m): PDO
{
    $dsn = 'mysql:host=' . $m['host'] . ';dbname=' . $m['name'] . ';charset=utf8mb4';
    return new PDO($dsn, $m['user'], $m['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
}

function fs_ensure_dirs(string $dataDir, string $privDir): ?string
{
    foreach ([$dataDir, $privDir] as $d) {
        if (!is_dir($d) && !@mkdir($d, 0750, true) && !is_dir($d)) {
            return 'Kan inte skapa mappen ' . basename($d) . '. Ge webbservern skrivrätt till appens mapp.';
        }
        if (!is_writable($d)) {
            return 'Mappen ' . basename($d) . ' går inte att skriva till.';
        }
    }
    return null;
}

function fs_write_htaccess(string $dataDir): bool
{
    $want = "# Skapad av Flowstack install.php\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";
    $f = $dataDir . '/.htaccess';
    if (is_file($f) && stripos((string) file_get_contents($f), 'deny') !== false) {
        return true;
    }
    return @file_put_contents($f, $want, FILE_APPEND) !== false;
}

/** Testar via HTTP att data/ inte går att nå. null = kunde inte testas. */
function fs_probe_protected(string $dataDir): ?bool
{
    $name = '.probe-' . bin2hex(random_bytes(6)) . '.txt';
    if (@file_put_contents($dataDir . '/' . $name, 'probe') === false) { return null; }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $result = null;
    if ($host !== '' && preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        $url = ($https ? 'https' : 'http') . '://' . $host . $base . '/data/' . $name;
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false || isset($http_response_header)) {
            $result = ($body !== 'probe');
        }
    }
    @unlink($dataDir . '/' . $name);
    return $result;
}

function fs_config_php(array $db, string $tz): string
{
    $mysql = $db['mysql'] ?? ['host' => 'localhost', 'name' => '', 'user' => '', 'pass' => ''];
    $out = "<?php\n\ndeclare(strict_types=1);\n\n"
        . "if (PHP_SAPI !== 'cli' && realpath((string) (\$_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {\n    http_response_code(403);\n    exit;\n}\n\n"
        . "// Skapad av install.php " . date('Y-m-d H:i') . "\nreturn [\n"
        . "    'timezone' => " . var_export($tz, true) . ",\n"
        . "    'db' => [\n"
        . "        'driver' => " . var_export($db['driver'], true) . ",\n"
        . "        'sqlite_path' => __DIR__ . '/app.sqlite',\n"
        . "        'json_path' => __DIR__ . '/store.json',\n"
        . "        'mysql' => [\n"
        . "            'host' => " . var_export((string) $mysql['host'], true) . ",\n"
        . "            'name' => " . var_export((string) $mysql['name'], true) . ",\n"
        . "            'user' => " . var_export((string) $mysql['user'], true) . ",\n"
        . "            'pass' => " . var_export((string) $mysql['pass'], true) . ",\n"
        . "            'charset' => 'utf8mb4',\n"
        . "        ],\n    ],\n];\n";
    return $out;
}

function fs_write_private(string $file, string $content): bool
{
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) { return false; }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
    @chmod($file, 0600);
    return true;
}

/** (d) Skapar tabeller med appens egen migrering. */
function fs_migrate(string $root, array $db): void
{
    $GLOBALS['_JEAB_SAPI'] = 'cli';
    $GLOBALS['_JEAB_SCHEMA_DDL'] = true;
    if (!is_file($root . '/src/bootstrap.php')) {
        throw new RuntimeException('Hittar inte src/bootstrap.php. Ligger install.php i appens rot?');
    }
    require_once $root . '/src/bootstrap.php';
    if ($db['driver'] === 'sqlite') {
        $pdo = new PDO('sqlite:' . $root . '/data/.private/app.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if (!function_exists('jeab_migrate_sqlite')) { throw new RuntimeException('Saknar jeab_migrate_sqlite i src/db.php'); }
        jeab_migrate_sqlite($pdo);
        $n = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();
    } else {
        $pdo = fs_mysql_pdo($db['mysql']);
        if (!function_exists('jeab_migrate_mysql')) { throw new RuntimeException('Saknar jeab_migrate_mysql i src/db.php'); }
        jeab_migrate_mysql($pdo);
        $n = count($pdo->query('SHOW TABLES')->fetchAll());
    }
    if ($n < 1) { throw new RuntimeException('Inga tabeller skapades.'); }
    $_SESSION['tables'] = $n;
}

/** Exempeldata: kör tools/demo_seed.php med PHP-CLI om det går. */
function fs_seed(string $root): string
{
    $seed = $root . '/tools/demo_seed.php';
    if (!is_file($seed)) { return 'Exempeldata finns inte i den här versionen (tools/demo_seed.php saknas).'; }
    $bin = PHP_BINARY;
    if (!function_exists('proc_open') || $bin === '' || stripos(basename($bin), 'fpm') !== false || stripos(basename($bin), 'cgi') !== false) {
        return 'Exempeldata kunde inte läsas in automatiskt. Kör <code>php tools/demo_seed.php</code> via SSH om du vill ha den.';
    }
    $p = proc_open([$bin, $seed], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, ['JEAB_DEMO' => '1']);
    if (!is_resource($p)) { return 'Exempeldata kunde inte startas.'; }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $code = proc_close($p);
    $fails = substr_count($out, 'FAIL');
    return $code === 0 ? 'Exempeldata inläst' . ($fails ? " ($fails steg misslyckades)" : '') . '.' : 'Exempeldata misslyckades (kod ' . $code . ').';
}

/** (e) Sparar första admin-koden i data/.private/passwords.json (bcrypt + hemlighet). */
function fs_save_code(string $privDir, string $code, string $email): bool
{
    $file = $privDir . '/passwords.json';
    $data = ['secret' => '', 'hashes' => []];
    if (is_file($file)) {
        $old = json_decode((string) file_get_contents($file), true);
        if (is_array($old)) { $data = array_merge($data, $old); }
    }
    if (!is_string($data['secret']) || trim($data['secret']) === '') {
        $data['secret'] = bin2hex(random_bytes(32));
    }
    $entry = ['hash' => password_hash($code, PASSWORD_DEFAULT)];
    if ($email !== '') { $entry['email'] = $email; }
    $data['hashes'] = is_array($data['hashes']) ? $data['hashes'] : [];
    $data['hashes'][] = $entry;
    return fs_write_private($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/* ---------- Flöde ---------- */
$step = (string) ($_SESSION['step'] ?? 'krav');
$err = '';
$note = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        page('Fel', '<h1>Sessionen har gått ut</h1><p>Ladda om sidan och försök igen.</p>');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'krav') {
            $ok = true;
            foreach (fs_requirements() as [$l, $pass, $must]) { if ($must && !$pass) { $ok = false; } }
            if (!$ok) { throw new RuntimeException('Något obligatoriskt saknas. Be webbhotellet slå på det och ladda om.'); }
            if ($e = fs_ensure_dirs($dataDir, $privDir)) { throw new RuntimeException($e); }
            if (!fs_write_htaccess($dataDir)) { throw new RuntimeException('Kunde inte skriva data/.htaccess.'); }
            $_SESSION['step'] = fs_sqlite_works($privDir) ? 'sqlite' : 'mysql';
        } elseif ($action === 'sqlite') {
            $_SESSION['db'] = ['driver' => 'sqlite'];
            $_SESSION['seed'] = !empty($_POST['seed']);
            $_SESSION['step'] = 'skapa';
        } elseif ($action === 'use_mysql') {
            $_SESSION['step'] = 'mysql';
        } elseif ($action === 'mysql') {
            $m = [
                'host' => trim((string) ($_POST['host'] ?? 'localhost')) ?: 'localhost',
                'name' => trim((string) ($_POST['name'] ?? '')),
                'user' => trim((string) ($_POST['user'] ?? '')),
                'pass' => (string) ($_POST['pass'] ?? ''),
            ];
            if ($m['name'] === '' || $m['user'] === '') { throw new RuntimeException('Fyll i databasnamn och användare.'); }
            if (!preg_match('/^[A-Za-z0-9._\-:\[\]]+$/', $m['host']) || !preg_match('/^[A-Za-z0-9_\-$]+$/', $m['name'])) {
                throw new RuntimeException('Värd eller databasnamn har ogiltiga tecken.');
            }
            if (!extension_loaded('pdo_mysql')) { throw new RuntimeException('pdo_mysql saknas på servern.'); }
            try { fs_mysql_pdo($m)->query('SELECT 1'); }
            catch (Throwable $e) { throw new RuntimeException('Kunde inte ansluta till databasen. Kontrollera värd, namn, användare och lösenord.'); }
            $_SESSION['db'] = ['driver' => 'mysql', 'mysql' => $m];
            $_SESSION['seed'] = !empty($_POST['seed']);
            $_SESSION['step'] = 'skapa';
        } elseif ($action === 'skapa') {
            $db = $_SESSION['db'] ?? null;
            if (!is_array($db)) { $_SESSION['step'] = 'krav'; throw new RuntimeException('Börja om från första steget.'); }
            if (!fs_write_private($privDir . '/config.php', fs_config_php($db, 'Europe/Stockholm'))) {
                throw new RuntimeException('Kunde inte skriva data/.private/config.php.');
            }
            fs_migrate($root, $db);
            $_SESSION['seedmsg'] = !empty($_SESSION['seed']) ? fs_seed($root) : '';
            $_SESSION['step'] = 'admin';
        } elseif ($action === 'admin') {
            $code = (string) ($_POST['code'] ?? '');
            $code2 = (string) ($_POST['code2'] ?? '');
            $email = trim((string) ($_POST['email'] ?? ''));
            if (mb_strlen($code) < 10) { throw new RuntimeException('Koden måste vara minst 10 tecken.'); }
            if (!hash_equals($code, $code2)) { throw new RuntimeException('Koderna är inte lika.'); }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('E-postadressen ser fel ut.'); }
            if (!fs_save_code($privDir, $code, $email)) { throw new RuntimeException('Kunde inte spara koden.'); }
            $_SESSION['step'] = 'skydd';
        } elseif ($action === 'skydd') {
            fs_write_htaccess($dataDir);
            $prot = fs_probe_protected($dataDir);
            if ($prot === false && empty($_POST['ignore'])) {
                throw new RuntimeException('Mappen data/ går att nå från webben! Servern läser inte .htaccess (vanligt på nginx). Be webbhotellet spärra /data, eller kryssa i rutan om du redan har gjort det på annat sätt.');
            }
            if (@file_put_contents($lockFile, 'Installerad ' . date('c') . "\n") === false) {
                throw new RuntimeException('Kunde inte skapa data/.installed.');
            }
            $_SESSION['prot'] = $prot;
            $_SESSION['step'] = 'klar';
        } elseif ($action === 'omstart') {
            $_SESSION['step'] = 'krav';
        }
    } catch (Throwable $e) {
        $err = $e instanceof RuntimeException ? $e->getMessage() : 'Ett oväntat fel uppstod (' . get_class($e) . ').';
    }
    if ($err === '') {
        header('Location: ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? 'install.php'), '?'), true, 303);
        exit;
    }
    $step = (string) ($_SESSION['step'] ?? 'krav');
}

$labels = ['krav' => '1. Krav', 'sqlite' => '2. Databas', 'mysql' => '2. Databas', 'skapa' => '3. Tabeller', 'admin' => '4. Admin-kod', 'skydd' => '5. Skydd', 'klar' => '6. Klart'];
$head = '<h1>Installera Flowstack</h1><p class="sub">Steg ' . h($labels[$step] ?? '') . '</p>';
$errBox = $err !== '' ? '<div class="box e">' . h($err) . '</div>' : '';
$seedBox = '<label style="font-weight:400"><input type="checkbox" name="seed" value="1"> Läs in exempeldata (påhittade kunder och bokningar)</label>';

switch ($step) {
    case 'sqlite':
        page('Databas', $head . $errBox . '<div class="box s">SQLite fungerar på den här servern. Det är enklast: ingen separat databas behövs. Databasen sparas i <code>data/.private/app.sqlite</code>.</div>'
            . '<form method="post">' . $csrfField . '<input type="hidden" name="action" value="sqlite">' . $seedBox . '<button>Använd SQLite</button></form>'
            . '<form method="post">' . $csrfField . '<input type="hidden" name="action" value="use_mysql"><button style="background:#5f6b76">Jag vill hellre använda MySQL/MariaDB</button></form>');
    case 'mysql':
        page('Databas', $head . $errBox . '<div class="box i">Ange uppgifterna till en MySQL- eller MariaDB-databas. Du skapar databasen i webbhotellets kontrollpanel. Lösenordet visas aldrig och skrivs bara till den skyddade konfigurationsfilen.</div>'
            . '<form method="post" autocomplete="off">' . $csrfField . '<input type="hidden" name="action" value="mysql">'
            . '<label>Värd (host)</label><input type="text" name="host" value="localhost">'
            . '<label>Databasnamn</label><input type="text" name="name">'
            . '<label>Användare</label><input type="text" name="user">'
            . '<label>Lösenord</label><input type="password" name="pass" autocomplete="new-password">'
            . $seedBox . '<button>Testa anslutningen och fortsätt</button></form>');
    case 'skapa':
        $d = $_SESSION['db']['driver'] ?? '?';
        page('Tabeller', $head . $errBox . '<p>Nu skrivs <code>data/.private/config.php</code> (driver: <b>' . h((string) $d) . '</b>, rättigheter 600) och tabellerna skapas med appens egen migrering.</p>'
            . '<form method="post">' . $csrfField . '<input type="hidden" name="action" value="skapa"><button>Skapa konfiguration och tabeller</button></form>');
    case 'admin':
        $msg = '<div class="box s">Databasen är klar (' . (int) ($_SESSION['tables'] ?? 0) . ' tabeller).' . (!empty($_SESSION['seedmsg']) ? ' ' . $_SESSION['seedmsg'] : '') . '</div>';
        page('Admin-kod', $head . $errBox . $msg . '<p>Välj den första inloggningskoden. Den sparas bara som en hash i <code>data/.private/passwords.json</code>. Skriv upp den på ett säkert ställe (t.ex. en lösenordshanterare).</p>'
            . '<form method="post" autocomplete="off">' . $csrfField . '<input type="hidden" name="action" value="admin">'
            . '<label>Inloggningskod (minst 10 tecken)</label><input type="password" name="code" autocomplete="new-password">'
            . '<label>Upprepa koden</label><input type="password" name="code2" autocomplete="new-password">'
            . '<label>Din e-post (valfritt)</label><input type="text" name="email">'
            . '<button>Spara koden</button></form>');
    case 'skydd':
        page('Skydd', $head . $errBox . '<p>Sista steget: kontrollera att <code>data/</code> är spärrad från webben (<code>data/.htaccess</code> med <code>Require all denied</code>) och lås installationsguiden.</p>'
            . '<form method="post">' . $csrfField . '<input type="hidden" name="action" value="skydd">'
            . ($err !== '' ? '<label style="font-weight:400"><input type="checkbox" name="ignore" value="1"> Jag har spärrat /data på annat sätt</label>' : '')
            . '<button>Testa skyddet och avsluta</button></form>');
    case 'klar':
        $prot = $_SESSION['prot'] ?? null;
        $pmsg = $prot === true ? '<span class="ok">data/ är spärrad från webben.</span>' : '<span class="warn">Skyddet kunde inte testas automatiskt. Öppna <code>/data/.htaccess</code> i webbläsaren: du ska få 403 eller 404.</span>';
        session_destroy();
        page('Klart', '<h1>Flowstack är installerat</h1><div class="box s">Allt klart. Installationsguiden är nu låst (<code>data/.installed</code>).</div><p>' . $pmsg . '</p>'
            . '<p><a href="./">Gå till Flowstack och logga in med din kod</a></p><p class="steps">Ta gärna bort <code>install.php</code> från servern. För att köra guiden igen: ta bort <code>data/.installed</code>.</p>');
    default:
        $rows = '';
        $allOk = true;
        foreach (fs_requirements() as [$l, $pass, $must]) {
            if ($must && !$pass) { $allOk = false; }
            $rows .= '<tr><td>' . h($l) . '</td><td>' . ($pass ? '<span class="ok">OK</span>' : ($must ? '<span class="bad">Saknas</span>' : '<span class="warn">Saknas (rekommenderas)</span>')) . '</td></tr>';
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $rows .= '<tr><td>HTTPS</td><td>' . ($https ? '<span class="ok">OK</span>' : '<span class="warn">Nej - slå på SSL innan du använder appen på riktigt</span>') . '</td></tr>';
        page('Krav', $head . $errBox . '<p>Guiden kontrollerar servern, skapar databasen, din första inloggningskod och skyddar datamappen. Inga konton behöver registreras.</p><table>' . $rows . '</table>'
            . '<form method="post">' . $csrfField . '<input type="hidden" name="action" value="krav"><button' . ($allOk ? '' : ' disabled') . '>' . ($allOk ? 'Fortsätt' : 'Ätgärda det som saknas och ladda om') . '</button></form>');
}
