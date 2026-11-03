<?php

declare(strict_types=1);

/**
 * B-010: webbshopens nyckel tas emot i header eller body, inte i query.
 * Nyckeln skapas här och skrivs inte ut.
 */

$tmp = sys_get_temp_dir() . '/jeab-wixkey-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
require dirname(__DIR__) . '/src/bootstrap.php';

function wixkey(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$key = bin2hex(random_bytes(18));
jeab_webshop_secret_write('webshop-webhook.json', ['key' => $key]);
$src = (string) file_get_contents(dirname(__DIR__) . '/src/webshop_wix.php');
$fnAt = strpos($src, 'function jeab_webshop_webhook_key_ok');
$fn = $fnAt === false ? '' : substr($src, $fnAt, 900);
wixkey($fn !== '' && !str_contains($fn, '$_GET'), 'funktionen läser inte query');
$hook = (string) file_get_contents(dirname(__DIR__) . '/webshop-hook.php');
wixkey(!str_contains($hook, '?k='), 'hook-kommentaren ber inte om query-nyckel');

function wixkey_reset(): void
{
    $_GET = [];
    $_POST = [];
    unset(
        $_SERVER['HTTP_X_WEBSHOP_KEY'],
        $_SERVER['HTTP_AUTHORIZATION'],
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    );
    $_SERVER['REQUEST_METHOD'] = 'POST';
}

wixkey_reset();
$_GET['k'] = $key;
wixkey(jeab_webshop_webhook_key_ok([]) === false, 'query-nyckel räcker inte');

wixkey_reset();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_X_WEBSHOP_KEY'] = $key;
wixkey(jeab_webshop_webhook_key_ok([]) === false, 'GET med header räcker inte');

wixkey_reset();
$_SERVER['HTTP_X_WEBSHOP_KEY'] = $key;
wixkey(jeab_webshop_webhook_key_ok(['site' => 'malmo']) === true, 'POST X-Webshop-Key räcker');

wixkey_reset();
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
wixkey(jeab_webshop_webhook_key_ok([]) === true, 'POST Bearer räcker');

wixkey_reset();
wixkey(jeab_webshop_webhook_key_ok(['key' => $key, 'orderId' => 'x']) === true, 'POST body.key räcker');

wixkey_reset();
$_SERVER['HTTP_X_WEBSHOP_KEY'] = $key . 'x';
wixkey(jeab_webshop_webhook_key_ok(['key' => 'fel']) === false, 'fel nyckel avvisas');

echo "OK  webshop key\n";
