<?php

declare(strict_types=1);

/** Utskick samlar notiser och offerter. Ingen kundmejl. */

$tmp = sys_get_temp_dir() . '/jeab-utskick-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function ut(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function ut_as(string $email): void
{
    $_SESSION['jeab_email'] = $email;
    unset($_COOKIE[JEAB_AUTH_COOKIE]);
}

ut(function_exists('jeab_offert_mall_body'), 'jeab_offert_mall_body finns kvar');
$notisSrc = (string) file_get_contents(dirname(__DIR__) . '/src/notiser.php');
ut(str_contains($notisSrc, 'p=notiser&fil='), 'bilagan hämtas fortfarande via p=notiser');
$boot = (string) file_get_contents(dirname(__DIR__) . '/src/bootstrap.php');
ut(str_contains($boot, "'notiser.php'") && str_contains($boot, "'utskick'") && str_contains($boot, "'offert_utskick.php'"), 'bootstrap laddar notiser och offertutskick på de lätta sidorna');

$labels = array_map(static fn (array $item): string => (string) ($item[0] ?? ''), jeab_public_links());
ut($labels[0] === 'Utskick', 'Utskick är första länken');
ut(!in_array('Notiser till slutkund', $labels, true) && !in_array('Offertutskick', $labels, true), 'de gamla länkarna är borta');

$_GET = ['co' => 'jarfalla', 'st' => 'Aktiv', 'flash' => 'Sparad.'];
$_SERVER['REQUEST_METHOD'] = 'GET';
unset($_SERVER['HTTP_X_JEAB_PARTIAL']);
ut(jeab_utskick_should_redirect('notiser') === true, 'vanlig GET p=notiser styrs om');
$target = jeab_utskick_legacy_target('notiser');
ut(str_contains($target, 'p=utskick') && str_contains($target, 'flik=notiser') && str_contains($target, 'co=jarfalla') && str_contains($target, 'st=Aktiv'), 'notiser landar på notisfliken med filtren');
ut(str_contains(jeab_utskick_legacy_target('offertutskick'), 'flik=offerter'), 'offertutskick landar på offertfliken');
$_GET['fil'] = 'laddbox_jarfalla';
ut(jeab_utskick_should_redirect('notiser') === false, 'filhämtning styrs inte om');
unset($_GET['fil']);
$_SERVER['HTTP_X_JEAB_PARTIAL'] = '1';
ut(jeab_utskick_should_redirect('notiser') === false && jeab_utskick_should_redirect('offertutskick') === true, 'skalets partial för notiser stannar, offertadressen styrs om');
unset($_SERVER['HTTP_X_JEAB_PARTIAL']);
$_SERVER['REQUEST_METHOD'] = 'POST';
ut(jeab_utskick_should_redirect('notiser') === false && jeab_utskick_should_redirect('offertutskick') === false, 'POST styrs inte om');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];

$url = jeab_notiser_url(['flik' => 'alla', 'co' => 'malmo', 'st' => 'Av']);
ut(str_contains($url, 'p=utskick') && str_contains($url, 'flik=alla') && str_contains($url, 'co=malmo') && str_contains($url, 'st=Av'), 'filterlänken behåller flik, ort och status');

ut_as('saljare4@jarfalla.example.test');
ut(!jeab_is_admin(), 'personal är inte admin');
$staff = jeab_utskick_body('alla');
ut(str_contains($staff, 'Utskick') && str_contains($staff, '>Notiser<') && str_contains($staff, 'Notiser till slutkund') && str_contains($staff, 'Järfälla') && str_contains($staff, '>Aktiv<'), 'personal ser notiser, status och ort');
ut(!str_contains($staff, '>Offerter<') && !str_contains($staff, 'offert.enable') && !str_contains($staff, 'offert.backfill_send') && !str_contains($staff, 'Skicka offerter till dessa'), 'personal ser inga offertknappar');
$denied = jeab_utskick_body('offerter');
ut(str_contains($denied, 'Bara admin.') && !str_contains($denied, 'offert.backfill_send'), 'offertfliken utan admin visar bara avvisningen');

ut_as('agenter@flowstack.example.test');
ut(jeab_is_admin(), 'delad kod är admin');
$admin = jeab_utskick_body('alla');
ut(str_contains($admin, '>Alla<') && str_contains($admin, '>Notiser<') && str_contains($admin, '>Offerter<') && str_contains($admin, 'Notiser till slutkund') && str_contains($admin, 'value="offert.enable"') && str_contains($admin, 'name="_csrf"') && str_contains($admin, 'Alla lägen') && str_contains($admin, 'Järfälla') && str_contains($admin, 'Malmö'), 'admin ser båda delarna och filtren');
$only = jeab_utskick_body('offerter');
ut(str_contains($only, '>Offerter<') && !str_contains($only, 'Notiser till slutkund') && str_contains($only, 'Skicka offerter till dessa') && str_contains($only, 'confirm('), 'offertfliken är bara offerter');
$notes = jeab_utskick_body('notiser');
ut(str_contains($notes, 'Notiser till slutkund') && !str_contains($notes, 'offert.enable'), 'notisfliken döljer offertknapparna även för admin');

$filtered = jeab_offert_utskick_html([
    'kind' => 'backfill',
    'dry' => 1,
    'ok' => true,
    'rows' => [
        ['id' => 't1', 'name' => 'A', 'product' => 'Sol', 'ort' => 'Bromma', 'mall' => 'A', 'status' => 'open', 'action' => 'would_send'],
        ['id' => 't2', 'name' => 'B', 'product' => 'Batteri', 'ort' => 'Malmö', 'mall' => '', 'status' => 'open', 'action' => 'redan_mailad', 'mailed_at' => '2026-10-01'],
    ],
], ['days' => 30]);
ut(str_contains($filtered, 'data-offer-table') && str_contains($filtered, 'data-action="would_send"') && str_contains($filtered, 'data-action="redan_mailad"') && str_contains($filtered, 'data-ort="Bromma"') && str_contains($filtered, 'data-offer-ort="bromma"') && str_contains($filtered, 'data-offer-ort="malmo"') && str_contains($filtered, 'Sol köpt') && str_contains($filtered, 'redan mailad') && !str_contains($filtered, 'incoming_webhook'), 'resultatet kan filtreras på läge och ort');

ut_as('saljare4@jarfalla.example.test');
$deniedPost = jeab_offert_utskick_respond('POST', ['_csrf' => 'x', 'action' => 'offert.backfill_send', 'dry' => 0, 'to' => 'kund@gmail.com', 'limit' => 500]);
ut($deniedPost['code'] === 403 && $deniedPost['result'] === null, 'personal får 403 på offertklick');

echo "utskick sida: ok\n";
