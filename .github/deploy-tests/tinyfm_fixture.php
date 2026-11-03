<?php
/**
 * Lokal TinyFM för övning. Samma fält som Tiny File Manager 2.6:
 * fm_usr, fm_pwd, token, upload file+fullpath, newfile+newfilename,
 * edit-sidan med window.csrf och JSON-save (ajax/type/token/content),
 * äldre edit+savedata, rename_from+rename_to, dl och del.
 * Kampanjgrinden sitter framför, precis som filer.php via filer_boot.php.
 * Rör inte flowstack.example.test.
 */
declare(strict_types=1);

session_start();

$root = getenv('JEAB_TFM_ROOT') ?: sys_get_temp_dir();
$campaign = getenv('JEAB_TFM_CAMPAIGN') ?: '';
$user = getenv('JEAB_TFM_USER') ?: '';
$pass = getenv('JEAB_TFM_PASS') ?: '';
$mode = getenv('JEAB_TFM_MODE') ?: 'ok';
$gateMode = getenv('JEAB_GATE_MODE') ?: 'ok';
$idMode = getenv('JEAB_TFM_ID_MODE') ?: '';
$expectId = getenv('JEAB_TFM_GATE_ID') ?: '';
$hitsFile = getenv('JEAB_GATE_HITS') ?: '';
$logFile = getenv('JEAB_TFM_LOG') ?: '';

function tfm_note(string $action): void
{
    $logFile = getenv('JEAB_TFM_LOG') ?: '';
    if ($logFile !== '') {
        file_put_contents($logFile, $action . "\n", FILE_APPEND);
    }
}

function tfm_waf(): void
{
    tfm_note('waf');
    http_response_code(403);
    echo '<title>Blocked Page</title>';
    exit;
}

function tfm_token(): string
{
    if (empty($_SESSION['token'])) {
        $_SESSION['token'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['token'];
}

function tfm_path(string $root, string $dir, string $name): string
{
    $dir = trim(str_replace('\\', '/', $dir), '/');
    $name = str_replace('\\', '/', $name);
    if (str_contains($dir, '..') || str_contains($name, '..')) {
        http_response_code(400);
        echo 'ogiltig sökväg';
        exit;
    }
    $base = rtrim($root, '/');
    if ($dir !== '') {
        $base .= '/' . $dir;
    }
    return $base . '/' . ltrim($name, '/');
}

function tfm_json_body(): ?array
{
    $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($type, 'application/json') === false) {
        return null;
    }
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function tfm_dir(string $root, string $dir): string
{
    $dir = trim(str_replace('\\', '/', $dir), '/');
    if ($dir !== '' && (str_contains($dir, '..') || str_contains($dir, "\0"))) {
        http_response_code(400);
        echo 'ogiltig sökväg';
        exit;
    }
    $base = rtrim($root, '/');
    if ($dir !== '') {
        $base .= '/' . $dir;
    }
    return $base;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
tfm_note($uri . ' ' . ($_SERVER['REQUEST_METHOD'] ?? ''));

if ($uri === '/index.php' || $uri === '/') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'login') {
        tfm_note('kampanjinloggning');
        $keys = array_keys($_POST);
        sort($keys);
        tfm_note('gate-fields:' . implode(',', $keys));
        $postedId = (string) ($_POST['id'] ?? '');
        if ($idMode === 'required' && $postedId === '') {
            header('Content-Type: application/json');
            echo '{"ok":false,"error":"Fel kod"}';
            exit;
        }
        if ($expectId !== '' && $postedId !== '' && !hash_equals($expectId, $postedId)) {
            header('Content-Type: application/json');
            echo '{"ok":false,"error":"Fel kod"}';
            exit;
        }
        if (!hash_equals($campaign, (string) ($_POST['password'] ?? ''))) {
            http_response_code(200);
            header('Content-Type: application/json');
            echo '{"ok":false,"error":"Fel kod"}';
            exit;
        }
        $_SESSION['jeab_gate'] = 1;
        header('Content-Type: application/json');
        echo '{"ok":true}';
        exit;
    }
    $n = 1;
    if ($hitsFile !== '') {
        $n = (is_file($hitsFile) ? (int) file_get_contents($hitsFile) : 0) + 1;
        file_put_contents($hitsFile, (string) $n);
    }
    if ($n > 1 && $gateMode === 'waf') {
        tfm_waf();
    }
    if ($n > 1 && $gateMode === 'crash') {
        http_response_code(500);
        echo 'broken';
        exit;
    }
    if ($n > 1 && $gateMode === 'notext') {
        http_response_code(200);
        echo 'hej';
        exit;
    }
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    $idInput = '';
    if ($idMode === 'optional') {
        $idInput = '<input name="id" value="">';
    } elseif ($idMode === 'required') {
        $idInput = '<input name="id" value="" required>';
    }
    echo '<label>Ange kampanjkod</label><form method="post">'
        . '<input type="hidden" name="_csrf" value="lokal">'
        . '<input type="hidden" name="action" value="login">'
        . '<input type="hidden" name="next" value="/">'
        . '<input name="password" required>'
        . $idInput
        . '</form>';
    exit;
}

if ($uri === '/hook.php') {
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $raw = file_get_contents('php://input');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $ua === 'curl/8.5' && $raw === '{}') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo '{"ok":false}';
        exit;
    }
    http_response_code(400);
    echo '{"ok":false}';
    exit;
}

if ($uri !== '/filer.php') {
    http_response_code(404);
    echo 'saknas';
    exit;
}

if ($mode === 'waf' && !empty($_SESSION['tiny_user'])) {
    tfm_waf();
}

if (empty($_SESSION['jeab_gate'])) {
    http_response_code(200);
    echo '<label>Ange kampanjkod</label>';
    exit;
}

if (empty($_SESSION['tiny_user'])) {
    if (isset($_POST['fm_usr'], $_POST['fm_pwd'], $_POST['token'])) {
        tfm_note('tinyfm-inloggning');
        if (!hash_equals(tfm_token(), (string) $_POST['token']) || !hash_equals($user, (string) $_POST['fm_usr']) || !hash_equals($pass, (string) $_POST['fm_pwd'])) {
            http_response_code(200);
            echo '<input type="password" name="fm_pwd"> Login failed';
            exit;
        }
        $_SESSION['tiny_user'] = (string) $_POST['fm_usr'];
        header('Location: filer.php?p=', true, 302);
        exit;
    }
    http_response_code(200);
    echo '<form><input name="fm_usr"><input type="password" name="fm_pwd"><input type="hidden" name="token" value="' . htmlspecialchars(tfm_token(), ENT_QUOTES) . '"></form>';
    exit;
}

if ($mode === 'waf') {
    tfm_waf();
}

if ($mode === 'waf-upload' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (!empty($_FILES) || isset($_POST['newfilename']) || isset($_POST['rename_from']) || isset($_GET['dl']) || isset($_GET['del']))) {
    tfm_waf();
}

$p = (string) ($_GET['p'] ?? $_POST['p'] ?? '');
$token = (string) ($_POST['token'] ?? '');
if ($token !== '' && !hash_equals(tfm_token(), $token)) {
    http_response_code(200);
    echo 'Invalid Token.';
    exit;
}

$json = tfm_json_body();
if (is_array($json) && isset($_GET['edit']) && array_key_exists('ajax', $json) && (string) ($json['type'] ?? '') === 'save') {
    $posted = (string) ($json['token'] ?? '');
    if ($posted === '' || !hash_equals(tfm_token(), $posted)) {
        http_response_code(401);
        echo 'Invalid Token.';
        exit;
    }
    $name = basename(str_replace('\\', '/', (string) $_GET['edit']));
    $dest = tfm_path($root, $p, $name);
    if (!is_file($dest)) {
        header('Location: filer.php?p=' . rawurlencode($p), true, 302);
        echo 'File not found';
        exit;
    }
    $content = $json['content'] ?? null;
    if (!is_string($content)) {
        http_response_code(400);
        echo 'ogiltigt innehåll';
        exit;
    }
    if (file_put_contents($dest, $content) === false) {
        http_response_code(500);
        echo 'Could Not Write File! - Check Permissions / Ownership';
        exit;
    }
    tfm_note('save');
    http_response_code(200);
    echo '1';
    exit;
}

if (!empty($_FILES['file'])) {
    tfm_note('upload');
    if ($mode === 'noupload') {
        header('Content-Type: application/json');
        echo '{"status":"error","info":"upload disabled"}';
        exit;
    }
    $full = (string) ($_POST['fullpath'] ?? '');
    $dest = tfm_path($root, $p, $full);
    $folder = dirname($dest);
    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
    }
    if (!move_uploaded_file((string) $_FILES['file']['tmp_name'], $dest)) {
        header('Content-Type: application/json');
        echo '{"status":"error","info":"move failed"}';
        exit;
    }
    header('Content-Type: application/json');
    echo '{"status":"success","info":"file upload successful"}';
    exit;
}

if (isset($_POST['newfilename'], $_POST['newfile']) && (string) $_POST['newfile'] === 'folder') {
    tfm_note('newfolder');
    $name = basename(str_replace('\\', '/', (string) $_POST['newfilename']));
    $dest = tfm_dir($root, $p) . '/' . $name;
    if (!is_dir($dest) && !@mkdir($dest, 0777, true) && !is_dir($dest)) {
        http_response_code(500);
        echo 'mkdir failed';
        exit;
    }
    header('Location: filer.php?p=' . rawurlencode($p), true, 302);
    exit;
}

if (isset($_POST['newfilename'], $_POST['newfile'])) {
    tfm_note('newfile');
    $name = basename(str_replace('\\', '/', (string) $_POST['newfilename']));
    $dest = tfm_path($root, $p, $name);
    $folder = dirname($dest);
    if (!is_dir($folder)) {
        mkdir($folder, 0777, true);
    }
    if (!is_file($dest)) {
        fclose(fopen($dest, 'wb'));
    }
    header('Location: filer.php?p=' . rawurlencode($p), true, 302);
    exit;
}

if (isset($_GET['edit'], $_POST['savedata'])) {
    tfm_note('save');
    $name = basename(str_replace('\\', '/', (string) $_GET['edit']));
    $dest = tfm_path($root, $p, $name);
    if (!is_file($dest)) {
        header('Location: filer.php?p=' . rawurlencode($p), true, 302);
        echo 'File not found';
        exit;
    }
    file_put_contents($dest, (string) $_POST['savedata']);
    http_response_code(200);
    echo 'File Saved Successfully';
    exit;
}

if (isset($_POST['rename_from'], $_POST['rename_to'])) {
    tfm_note('rename');
    $from = tfm_path($root, $p, basename(str_replace('\\', '/', (string) $_POST['rename_from'])));
    $to = tfm_path($root, $p, basename(str_replace('\\', '/', (string) $_POST['rename_to'])));
    if (is_file($from) && !file_exists($to)) {
        rename($from, $to);
    }
    header('Location: filer.php?p=' . rawurlencode($p), true, 302);
    exit;
}

if (isset($_GET['dl'])) {
    tfm_note('download');
    $name = basename(str_replace('\\', '/', (string) $_GET['dl']));
    $dest = tfm_path($root, $p, $name);
    if (!is_file($dest) || filesize($dest) === 0) {
        header('Location: filer.php?p=' . rawurlencode($p), true, 302);
        echo 'File not found';
        exit;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . (string) filesize($dest));
    readfile($dest);
    exit;
}

if (isset($_GET['del'])) {
    tfm_note('delete');
    $name = basename(str_replace('\\', '/', (string) $_GET['del']));
    $dest = tfm_path($root, $p, $name);
    if (is_file($dest)) {
        unlink($dest);
    }
    header('Location: filer.php?p=' . rawurlencode($p), true, 302);
    exit;
}

if (isset($_GET['edit']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $name = basename(str_replace('\\', '/', (string) $_GET['edit']));
    $dest = tfm_path($root, $p, $name);
    if (!is_file($dest)) {
        header('Location: filer.php?p=' . rawurlencode($p), true, 302);
        echo 'File not found';
        exit;
    }
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    $csrf = htmlspecialchars(tfm_token(), ENT_QUOTES);
    echo '<script type="text/javascript">window.csrf = \'' . $csrf . '\';</script>';
    exit;
}

http_response_code(200);
echo '<input type="hidden" name="token" value="' . htmlspecialchars(tfm_token(), ENT_QUOTES) . '">';
$folder = tfm_dir($root, $p);
if (is_dir($folder)) {
    foreach (scandir($folder) ?: [] as $name) {
        if ($name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, '\\')) {
            continue;
        }
        if (!is_file($folder . '/' . $name)) {
            continue;
        }
        echo '<input type="checkbox" name="file[]" value="' . htmlspecialchars($name, ENT_QUOTES) . '">';
    }
}
exit;
