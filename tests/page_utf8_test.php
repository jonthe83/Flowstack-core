<?php

declare(strict_types=1);

/**
 * T1: varje sida under src/pages är giltig UTF-8 och går att rita.
 * Ritar med tom lokal sqlite. Ingen kunddata. Åäö måste överleva layouten.
 */

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'render') {
    $file = basename((string) ($argv[2] ?? ''));
    $slug = (string) ($argv[3] ?? 'reminders');
    $tmp = (string) ($argv[4] ?? '');
    if ($file === '' || $tmp === '' || !preg_match('/^[a-z0-9_]+\.php$/', $file)) {
        fwrite(STDERR, "FAIL render-argument\n");
        exit(1);
    }
    $GLOBALS['_JEAB_DATA_DIR'] = $tmp;
    $GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
    putenv('JEAB_DISABLE_SQLITE=0');
    putenv('XAI_API_KEY');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/index.php';
    $_SERVER['HTTP_HOST'] = '127.0.0.1';
    $_GET = ['p' => $slug];
    $_POST = [];
    require dirname(__DIR__) . '/src/bootstrap.php';
    if (function_exists('jeab_bootstrap_load_page_modules')) {
        jeab_bootstrap_load_page_modules();
    }
    require_once dirname(__DIR__) . '/src/layout.php';
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['jeab_email'] = 'saljare3@jarfalla.example.test';
    if (function_exists('jeab_ordrar_external_live')) {
        jeab_ordrar_external_live(false);
    }
    $monday = new DateTimeImmutable('2026-10-26');
    $weekOffset = 0;
    $bookings = [];
    $selectedInstallers = [];
    $selectedDay = -1;
    $fromReminder = null;
    $rows = [];
    $open = null;
    $openId = 0;
    $flash = null;
    $error = null;
    $company = 'jarfalla';
    $reminders = [];
    $visible = [];
    $counts = ['today' => 0, 'scheduled' => 0, 'all' => 0, 'waiting' => 0, 'done' => 0];
    $tags = [];
    $view = 'today';
    $activeTag = null;
    $search = '';
    $entries = [];
    $notes = [];
    $sections = function_exists('jeab_notes_sections') ? jeab_notes_sections() : [];
    $purchase = ['id' => 1, 'kind' => 'batteri', 'step' => 1, 'customer_name' => 'Åsa Öberg'];
    $reminder = ['id' => 1, 'name' => 'Åsa Öberg', 'address' => 'Malmövägen 1', 'company' => 'jarfalla'];
    $step = 1;
    $store = jeab_store();
    if ($file === 'ordrar_v3.php') {
        $open = [
            'id' => 1,
            'customer_name' => 'Åsa Öberg',
            'address' => 'Malmövägen 1',
            'company' => 'jarfalla',
            'kind' => 'batteri',
            'reminder_id' => 1,
        ];
        $openId = 1;
        $reminder = ['id' => 1, 'name' => 'Åsa Öberg', 'address' => 'Malmövägen 1', 'company' => 'jarfalla'];
    }
    if ($file === 'zapier.php') {
        $outgoing = '';
        $token = '';
        $hookUrl = 'https://example.invalid/hook';
    }
    if ($file === 'fullmakter.php' && !function_exists('jeab_fullmakt_signed_list_html')) {
        require_once dirname(__DIR__) . '/src/fullmakt_signed.php';
    }
    $path = dirname(__DIR__) . '/src/pages/' . $file;
    try {
        ob_start();
        $result = require $path;
        $out = ob_get_clean();
        if (!is_string($out)) {
            $out = '';
        }
        if (is_string($result) && $result !== '' && !str_contains($out, '<html')) {
            ob_start();
            jeab_layout('Sida Åäö · JEAB', $slug, $result);
            $wrapped = ob_get_clean();
            $out = is_string($wrapped) ? $wrapped : $out . $result;
        } elseif (is_string($result)) {
            $out .= $result;
        }
    } catch (Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        $text = $e::class;
        $message = trim($e->getMessage());
        if (strlen($message) > 180) {
            $message = substr($message, 0, 180);
        }
        fwrite(STDERR, "FAIL {$file} {$text} {$message}\n");
        exit(1);
    }
    echo $out . "\n<!-- Åsa Öberg i Malmö -->\n";
    exit(0);
}

function utf8_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function utf8_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    utf8_fail($message);
}

$root = dirname(__DIR__);
$pages = [
    'reminders.php' => 'reminders',
    'bookings.php' => 'bookings',
    'ordrar.php' => 'ordrar',
    'ordrar_v3.php' => 'ordrar',
    'inkorg.php' => 'inkorg',
    'inkorg_vyer.php' => 'inkorg-vyer',
    'inkorg_regler.php' => 'inkorg-regler',
    'inkorg_kontakter.php' => 'inkorg-kontakter',
    'kontakter.php' => 'kontakter',
    'offert.php' => 'offert',
    'notiser.php' => 'notiser',
    'notes.php' => 'notes',
    'links.php' => 'links',
    'logg.php' => 'logg',
    'bugg.php' => 'bugg',
    'fullmakter.php' => 'fullmakter',
    'inloggningar.php' => 'inloggdiag',
    'purchase.php' => 'purchase',
    'sack.php' => 'sack',
    'solkampanj.php' => 'solkampanj',
    'zapier.php' => 'zapier',
];
$dir = $root . '/src/pages';
$seen = [];
foreach (scandir($dir) ?: [] as $name) {
    if (!str_ends_with($name, '.php')) {
        continue;
    }
    $seen[$name] = true;
    $raw = (string) file_get_contents($dir . '/' . $name);
    utf8_ok(mb_check_encoding($raw, 'UTF-8'), $name . ' är giltig UTF-8');
    utf8_ok(isset($pages[$name]), $name . ' ingår i ritningen');
}
foreach (array_keys($pages) as $name) {
    utf8_ok(isset($seen[$name]), $name . ' finns på disk');
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas, källan är kontrollerad\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-utf8-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

foreach ($pages as $file => $slug) {
    $proc = proc_open(
        [PHP_BINARY, '-d', 'display_errors=0', __FILE__, 'render', $file, $slug, $tmp],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    utf8_ok(is_resource($proc), $file . ' startade');
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $out = is_string($out) ? $out : '';
    $err = is_string($err) ? trim($err) : '';
    if ($code !== 0) {
        utf8_fail($file . ' ritade inte' . ($err !== '' ? ' ' . $err : ''));
    }
    utf8_ok($out !== '', $file . ' gav HTML');
    utf8_ok(mb_check_encoding($out, 'UTF-8'), $file . ' HTML är giltig UTF-8');
    utf8_ok(stripos($out, 'charset') !== false && stripos($out, 'utf-8') !== false, $file . ' säger utf-8');
    utf8_ok(preg_match('/[ÅÄÖåäö]/u', $out) === 1, $file . ' behåller åäö');
    utf8_ok(!str_contains($out, "\u{FFFD}"), $file . ' har inget ersättningstecken');
}

echo "OK  page utf-8\n";
