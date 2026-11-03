<?php

declare(strict_types=1);

/**
 * Mina-pillen visar bara «Mina» och räknaren. Personnamnet står en gång,
 * i hjälptexten «Mina visar …» till höger.
 */

$tmp = sys_get_temp_dir() . '/jeab-mina-pill-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function mp_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function mp_reminders(string $email, string $company): string
{
    $_GET = ['p' => 'reminders', 'co' => $company];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SESSION['jeab_email'] = $email;
    unset($_SESSION['jeab_reminder_who']);
    $view = 'all';
    $counts = ['today' => 0, 'scheduled' => 0, 'all' => 0, 'waiting' => 0, 'done' => 0];
    $visible = [];
    $search = '';

    return require dirname(__DIR__) . '/src/pages/reminders.php';
}

function mp_who_block(string $html): string
{
    if (preg_match('#<div class="cal-filter crm-who">(.*?)</div>\s*</div>#s', $html, $m) !== 1) {
        return '';
    }

    return $m[1];
}

$jw = mp_reminders('demo@malmo.example.test', 'malmo');
$block = mp_who_block($jw);
mp_assert($block !== '', 'Påminnelser har filterraden');
mp_assert(preg_match('#data-scope="mina"[^>]*>Mina<span class="chip-count">\d+</span></a>#', $block) === 1, 'Påminnelser: Mina-pillen är bara Mina och räknare');
mp_assert(!str_contains($block, 'chip-who-name'), 'Påminnelser: pillen har inget personnamn');
mp_assert(substr_count($block, 'Sam W') === 1, 'Påminnelser: Sam W står en gång i filterraden');
mp_assert(str_contains($block, '<p class="who-scope-note">Mina visar Sam W.</p>'), 'Påminnelser: hjälptexten säger Mina visar Sam W.');

$elin = mp_reminders('saljare4@jarfalla.example.test', 'jarfalla');
$elinBlock = mp_who_block($elin);
mp_assert(preg_match('#data-scope="mina"[^>]*>Mina<span class="chip-count">\d+</span></a>#', $elinBlock) === 1, 'Påminnelser: Elins pillen är bara Mina');
mp_assert(str_contains($elinBlock, '<p class="who-scope-note">Mina visar Elin.</p>'), 'Påminnelser: Elin står i hjälptexten');
mp_assert(preg_match('#data-scope="mina"[^>]*>[^<]*Elin#', $elinBlock) !== 1, 'Påminnelser: Elin står inte i pillens text');

$_SESSION['jeab_email'] = 'demo@malmo.example.test';
$_GET = ['p' => 'ordrar', 'co' => 'malmo'];
$chips = jeab_ordrar_mina_chips_html('malmo', 'Sam W', null);
$note = jeab_who_scope_note_html('malmo');
$row = $chips . $note;
mp_assert(preg_match('#data-scope="mina"[^>]*>Mina</a>#', $row) === 1, 'Ordrar: Mina-pillen är bara Mina');
mp_assert(!str_contains($chips, 'chip-who-name') && !str_contains($chips, 'Sam W'), 'Ordrar: pillraden visar inte Sam W');
mp_assert(str_contains($chips, 'who=Sam+W'), 'Ordrar: Mina-länken pekar fortfarande på Sam W');
mp_assert(substr_count($row, 'Sam W') === 1 && str_contains($note, 'Mina visar Sam W.'), 'Ordrar: namnet står bara i hjälptexten');

$_SESSION['jeab_email'] = 'demo@malmo.example.test';
$empty = jeab_ordrar_mina_chips_html('jarfalla', null, null) . jeab_who_scope_note_html('jarfalla');
mp_assert(preg_match('#data-scope="mina"[^>]*>Mina</a>#', $empty) === 1, 'Ordrar i andra staden: pillen är fortfarande bara Mina');
mp_assert(substr_count($empty, 'Sam W') === 1 && str_contains($empty, 'Mina visar Sam W.'), 'Ordrar i andra staden: namnet står bara i hjälptexten');

echo "OK  mina-pillen\n";
