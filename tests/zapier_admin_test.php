<?php

declare(strict_types=1);

/**
 * G-05: ?p=zapier och settings.webhook kräver jeab_is_admin().
 * agenter@flowstack.example.test är ingen person och är admin via jeab_is_admin(), så agentkoden
 * får sidan. Elin, Sam W och utloggad får den inte.
 * Hela inkommande token ritas aldrig ut. Sidan byter inte token av sig själv.
 * Rotation sker bara via knappen Ny token (settings.incoming_token).
 */

$tmp = sys_get_temp_dir() . '/jeab-zapier-admin-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$private = $tmp . '/private';
$public = $tmp . '/public';
$privat = $tmp . '/privat';
mkdir($private, 0700, true);
mkdir($public, 0700, true);
mkdir($privat, 0700, true);

$GLOBALS['_JEAB_DATA_DIR'] = $private;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $public;
$GLOBALS['_JEAB_INCOMING_TOKEN_FILE'] = $privat . '/incoming-token.txt';

$configToken = 'g05-config-token-value-OLD1';
file_put_contents($private . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => $configToken,
    'outgoing_webhook_url' => '',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $private . '/app.sqlite',
        'json_path' => $private . '/store.json',
    ],
], true) . ";\n");
file_put_contents($public . '/incoming-token.txt', "g05-public-token-value-PUB1\n");
file_put_contents($GLOBALS['_JEAB_INCOMING_TOKEN_FILE'], "g05-file-token-value-FILE\n");

require dirname(__DIR__) . '/src/bootstrap.php';
jeab_config(true);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function za(bool $ok, string $message): void
{
    if ($ok) {
        $GLOBALS['_ZA'][] = "OK  {$message}";
        return;
    }
    foreach ($GLOBALS['_ZA'] ?? [] as $line) {
        echo $line . "\n";
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function za_throws(callable $fn, string $needle, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        za(str_contains($e->getMessage(), $needle), $label . ' (' . $e->getMessage() . ')');
        return;
    }
    fwrite(STDERR, "FAIL {$label} kastade inte\n");
    exit(1);
}

function za_login(?string $email): void
{
    unset($_COOKIE[JEAB_AUTH_COOKIE]);
    if ($email === null || $email === '') {
        unset($_SESSION['jeab_email']);
        return;
    }
    $_SESSION['jeab_email'] = $email;
}

$store = jeab_store(true);
za($store instanceof JeabStore, 'testlagret går att öppna');

za(jeab_person_by_login('agenter@flowstack.example.test') === null, 'agenter@ är ingen person');
za(jeab_person_by_email('agenter@flowstack.example.test') === null, 'agentkoden är inte en egen e-post');

za_login(null);
za(!jeab_is_admin() && !jeab_may_edit_webhook(), 'utan inloggning: inte admin');
za_login('agenter@flowstack.example.test');
za(jeab_auth_user_person() === null && jeab_is_admin() && jeab_may_edit_webhook(), 'agenter@ är ingen person och är admin');
za_login('saljare4@jarfalla.example.test');
za(!jeab_is_admin() && !jeab_may_edit_webhook(), 'Elin är inte admin');
za_login('demo@malmo.example.test');
za(!jeab_is_admin() && !jeab_may_edit_webhook(), 'Sam W är inte admin för Zapier');
za_login('Sam@jarfalla-demo.se');
za(jeab_is_admin() && jeab_may_edit_webhook(), 'Sam L:s egen e-post är admin');

za(jeab_ensure_incoming_token($store) === $configToken, 'config-token gäller innan rotation');
za(jeab_incoming_token_current($store) === $configToken, 'sidan läser samma token utan att skapa en ny');
za(jeab_setting($store, 'incoming_webhook_rotated', '') !== '1', 'ingen rotation är gjord');

$beforeUrl = jeab_setting($store, 'outgoing_webhook_url', '');
foreach ([
    [null, 'utan inloggning'],
    ['saljare4@jarfalla.example.test', 'Elin'],
    ['demo@malmo.example.test', 'Sam W'],
] as [$email, $who]) {
    za_login($email);
    za_throws(
        static fn () => jeab_save_outgoing_webhook($store, 'https://evil.example/steal'),
        'Inte behörig',
        $who . ' får inte spara webhook'
    );
    za_throws(
        static fn () => jeab_save_outgoing_webhook($store, 'http://evil.example/steal'),
        'Inte behörig',
        $who . ' får http-felet först efter admin-kollen'
    );
    za_throws(
        static fn () => jeab_save_outgoing_webhook($store, ''),
        'Inte behörig',
        $who . ' får inte tömma webhook-URL:en'
    );
    za(jeab_setting($store, 'outgoing_webhook_url', '') === $beforeUrl, $who . ': nekad sparning lämnar URL:en');
    za(jeab_ensure_incoming_token($store) === $configToken, $who . ': nekad sparning byter inte token');
    za_throws(
        static fn () => jeab_rotate_incoming_token($store),
        'Inte behörig',
        $who . ' får inte rotera'
    );
    za(jeab_ensure_incoming_token($store) === $configToken, $who . ': nekad rotation lämnar token');
    za(is_file($public . '/incoming-token.txt'), $who . ': nekad rotation lämnar den publika filen');

    ob_start();
    $rendered = false;
    $renderError = '';
    try {
        jeab_zapier_render($store, null);
        $rendered = true;
    } catch (Throwable $e) {
        $renderError = $e->getMessage();
    }
    $deniedHtml = (string) ob_get_clean();
    za($rendered === false && $renderError === 'Inte behörig.', $who . ' får inte sidans HTML');
    za($deniedHtml === '', $who . ': nekad rendering skriver ingen HTML');
    za(!str_contains($deniedHtml, $configToken), $who . ': nekad rendering innehåller inte token');

    http_response_code(200);
    ob_start();
    jeab_render_zapier_page($store, 'Ny token sparad.');
    $denied = (string) ob_get_clean();
    za(http_response_code() === 403, $who . ' får 403 på ?p=zapier');
    za(str_contains($denied, 'Inte behörig.'), $who . ': 403 säger inte behörig');
    za(!str_contains($denied, $configToken), $who . ': 403 innehåller inte config-token');
    za(!str_contains($denied, 'g05-public-token-value-PUB1'), $who . ': 403 innehåller inte fil-token');
    za(!str_contains($denied, 'g05-file-token-value-FILE'), $who . ': 403 innehåller inte privat fil-token');
    za(!str_contains($denied, 'settings.webhook'), $who . ': 403 har inget webhook-formulär');
    za(!str_contains($denied, 'settings.incoming_token'), $who . ': 403 har ingen rotationsknapp');
    za(!str_contains($denied, 'Ny token sparad.'), $who . ': 403 visar inte flash från en annan vy');
}

za_login('agenter@flowstack.example.test');
jeab_save_outgoing_webhook($store, 'https://hooks.example.test/agent');
za(jeab_outgoing_url($store) === 'https://hooks.example.test/agent', 'agenter@ sparar https-webhook');
za(jeab_ensure_incoming_token($store) === $configToken, 'agenter@-sparning byter inte token');
za_throws(
    static fn () => jeab_save_outgoing_webhook($store, 'http://hooks.example.test/agent'),
    'https',
    'agenter@ får https-felet efter admin-kollen'
);
za(jeab_outgoing_url($store) === 'https://hooks.example.test/agent', 'nekad http lämnar agenter@-URL:en');
$agentMask = jeab_mask_incoming_token($configToken);
$agentHtml = jeab_zapier_render($store, null);
za(str_contains($agentHtml, $agentMask) && !str_contains($agentHtml, $configToken), 'agenter@ ser masken och inte hela token');
za(str_contains($agentHtml, 'settings.webhook') && str_contains($agentHtml, '>Ny token<'), 'agenter@ ser formulär och knappen Ny token');
za(jeab_ensure_incoming_token($store) === $configToken, 'agenter@-sidan roterar inte');
za(jeab_setting($store, 'incoming_webhook_rotated', '') !== '1', 'agenter@-sidan sätter inte rotationsflaggan');
http_response_code(200);
ob_start();
jeab_render_zapier_page($store, null);
$agentPage = (string) ob_get_clean();
za(http_response_code() === 200 && str_contains($agentPage, 'settings.incoming_token'), 'agenter@ får ?p=zapier');
za(!str_contains($agentPage, $configToken), 'agenter@-sidan innehåller inte config-token');
jeab_set_setting($store, 'outgoing_webhook_url', '');

za_login('demo@example.test');
$html = jeab_zapier_render($store, null);
$mask = jeab_mask_incoming_token($configToken);
za($mask !== $configToken && !str_contains($mask, $configToken), 'masken är inte token');
za(str_contains($html, $mask), 'sidan visar masken');
za(!str_contains($html, $configToken), 'sidan innehåller inte hela config-token');
za(!str_contains($html, 'g05-public-token-value-PUB1'), 'sidan innehåller inte publika fil-token');
za(!str_contains($html, 'g05-file-token-value-FILE'), 'sidan innehåller inte privat fil-token');
za(str_contains($html, 'settings.webhook'), 'admin ser utgående formulär');
za(str_contains($html, 'settings.incoming_token'), 'admin ser rotation');
za(str_contains($html, 'Ny token'), 'admin kan välja ny token');
za(jeab_ensure_incoming_token($store) === $configToken, 'att visa sidan roterar inte');
za(jeab_setting($store, 'incoming_webhook_rotated', '') !== '1', 'visning sätter inte rotationsflaggan');

jeab_save_outgoing_webhook($store, 'https://hooks.example.test/catch');
za(jeab_outgoing_url($store) === 'https://hooks.example.test/catch', 'admin sparar https-webhook');
za(jeab_ensure_incoming_token($store) === $configToken, 'sparad webhook byter inte token');
za_throws(
    static fn () => jeab_save_outgoing_webhook($store, 'http://hooks.example.test/catch'),
    'https',
    'admin får inte spara http'
);
za(jeab_outgoing_url($store) === 'https://hooks.example.test/catch', 'nekad http lämnar den sparade URL:en');

$again = jeab_zapier_render($store, $configToken);
za(str_contains($again, 'https://hooks.example.test/catch'), 'sparad URL syns i formuläret');
za(!str_contains($again, $configToken), 'token som flash ritas inte ut');
za(jeab_flash_text($configToken) === null, 'flash-listan släpper inte igenom en token');
za(jeab_flash_text('Ny token sparad.') === 'Ny token sparad.', 'rotationens besked är tillåtet');
za(jeab_flash_text('Zapier sparad.') === 'Zapier sparad.', 'webhook-beskedet är kvar');

jeab_rotate_incoming_token($store);
$rotated = jeab_ensure_incoming_token($store);
za($rotated !== '' && $rotated !== $configToken, 'rotation skriver en ny token');
za(jeab_incoming_token_current($store) === $rotated, 'sidan läser den nya token');
za(jeab_setting($store, 'incoming_webhook_rotated', '') === '1', 'rotationen är markerad');
za(!is_file($public . '/incoming-token.txt'), 'rotation tar bort publik kopia');
za(is_file($GLOBALS['_JEAB_INCOMING_TOKEN_FILE']), 'rotation skriver privat fil');
za(jeab_read_token_file($GLOBALS['_JEAB_INCOMING_TOKEN_FILE']) === $rotated, 'privat fil är den nya token');
za(!str_contains((string) file_get_contents($GLOBALS['_JEAB_INCOMING_TOKEN_FILE']), $configToken), 'privat fil har inte den gamla token');

$after = jeab_zapier_render($store, 'Ny token sparad.');
$newMask = jeab_mask_incoming_token($rotated);
za(str_contains($after, $newMask), 'sidan visar den nya masken');
za(str_contains($after, 'Ny token sparad.'), 'beskedet syns utan token');
za(!str_contains($after, $rotated), 'sidan innehåller inte den nya token');
za(!str_contains($after, $configToken), 'sidan innehåller inte den gamla token');
za(!str_contains($after, 'OLD1'), 'sidan visar inte den gamla ändelsen');
za(jeab_ensure_incoming_token($store) === $rotated, 'en ny visning roterar inte igen');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = $configToken;
za(jeab_incoming_token_ok($rotated) === false, 'gamla token accepteras inte');
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = $rotated;
za(jeab_incoming_token_ok($rotated) === true, 'nya token accepteras via X-Webhook-Token');
$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTP_X_WEBHOOK_TOKEN']);
$_GET['token'] = $rotated;
za(jeab_incoming_token_ok($rotated) === false, 'nya token accepteras inte via GET');

$short = 'short';
$shortMask = jeab_mask_incoming_token($short);
za($shortMask !== $short && !str_contains($shortMask, $short), 'kort token maskeras helt');

file_put_contents($private . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => '',
    'outgoing_webhook_url' => '',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $private . '/app.sqlite',
        'json_path' => $private . '/store.json',
    ],
], true) . ";\n");
jeab_config(true);
jeab_set_setting($store, 'incoming_webhook_rotated', '0');
jeab_set_setting($store, 'incoming_webhook_token', '');
@unlink($GLOBALS['_JEAB_INCOMING_TOKEN_FILE']);
@unlink($public . '/incoming-token.txt');
@unlink($private . '/incoming-token.txt');

za(jeab_incoming_token_current($store) === '', 'utan källa finns ingen token att visa');
$emptyHtml = jeab_zapier_render($store, null);
za(str_contains($emptyHtml, 'settings.incoming_token'), 'tom sida har fortfarande rotation');
za(jeab_incoming_token_current($store) === '', 'tom sida skapar ingen token');
za(jeab_setting($store, 'incoming_webhook_rotated', '') !== '1', 'tom sida sätter inte rotationsflaggan');
$created = jeab_ensure_incoming_token($store);
za($created !== '', 'ensure skapar en token när ingen finns');
za(jeab_ensure_incoming_token($store) === $created, 'ensure byter inte en token som redan finns');
za(jeab_zapier_render($store, null) !== '' && !str_contains(jeab_zapier_render($store, null), $created), 'skapad token ritas inte ut');
za(jeab_ensure_incoming_token($store) === $created, 'rendering efter ensure roterar inte');

$root = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');
$webhookAt = strpos($index, "case 'settings.webhook':");
$offertAt = strpos($index, "case 'offert.defaults':");
za($webhookAt !== false && $offertAt !== false && $offertAt > $webhookAt, 'webhook-casen ligger kvar i routern');
$block = substr($index, (int) $webhookAt, $offertAt - $webhookAt);
za(str_contains($block, 'jeab_may_edit_webhook()'), 'settings.webhook kräver jeab_is_admin via jeab_may_edit_webhook');
za(str_contains($block, 'jeab_save_outgoing_webhook'), 'settings.webhook går via admin-sparningen');
za(str_contains($block, 'jeab_rotate_incoming_token'), 'settings.incoming_token roterar');
za(!str_contains($block, 'jeab_set_setting'), 'routern skriver inte webhook-URL själv');
za(!str_contains($block, 'jeab_ensure_incoming_token'), 'routern läser inte ut token i svaret');
za(str_contains($index, 'jeab_render_zapier_page'), 'sidan ritas via admin-grinden');
za(!str_contains($index, 'jeab_ensure_incoming_token'), 'index.php anropar inte ensure för sidan');
$boot = (string) file_get_contents($root . '/src/bootstrap.php');
za(!str_contains($boot, 'function jeab_zapier_is_admin'), 'ingen lokal zapier-admin');
za(!str_contains($boot, 'function jeab_require_zapier_admin'), 'ingen lokal require-kopia');
za(substr_count($boot, 'jeab_is_admin()') >= 4, 'sida, sparning, rendering och rotation använder jeab_is_admin');
$page = (string) file_get_contents($root . '/src/pages/zapier.php');
za(!str_contains($page, 'jeab_ensure_incoming_token'), 'mallen anropar inte ensure');
za(str_contains($page, 'settings.incoming_token'), 'mallen har rotationsformuläret');
$rawPage = (static function () use ($configToken): string {
    $outgoing = '';
    $token = $configToken;
    $hookUrl = 'https://jeab.test/hook.php';
    $flash = null;
    return require dirname(__DIR__) . '/src/pages/zapier.php';
})();
za(!str_contains($rawPage, $configToken), 'mallen ritar inte ut en token som skickas in i klartext');
za(str_contains($rawPage, jeab_mask_incoming_token($configToken)), 'mallen visar masken av den token den får');

foreach ($GLOBALS['_ZA'] ?? [] as $line) {
    echo $line . "\n";
}
echo "OK  zapier-admin\n";
