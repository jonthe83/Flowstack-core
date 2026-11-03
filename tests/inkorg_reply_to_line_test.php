<?php

declare(strict_types=1);

/**
 * Svarsrutan visar mottagaren ovanför textfältet.
 * Saknas en adress står «Till: — (saknar mejl)» och Skicka är avstängd.
 * Anteckning går fortfarande att spara. Inget mejl skickas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-reply-to-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
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

function rtl(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

rtl(jeab_inkorg_reply_recipient(['messages' => [['from' => 'Kund.Till@Example.Test']]]) === 'kund.till@example.test', 'senaste kundavsändaren är mottagaren');
rtl(jeab_inkorg_reply_recipient(['messages' => [['from' => 'kontakt@jarfalla.example.test']]]) === '', 'bara brevlådan ger ingen mottagare');
rtl(jeab_inkorg_reply_recipient(['messages' => [['from' => 'noreply@shop.example.test']]]) === '', 'no-reply är blockerad');
rtl(
    jeab_inkorg_reply_recipient(['messages' => [
        ['from' => 'forst@example.test'],
        ['from' => 'kontakt@jarfalla.example.test'],
        ['from' => 'sist@example.test'],
    ]]) === 'sist@example.test',
    'senaste externa avsändaren vinner'
);

$mail = static function (string $id, string $from, string $to): array {
    return [
        'message_id' => '<' . $id . '@reply-to>',
        'from' => $from,
        'from_name' => 'Kund',
        'to' => $to,
        'subject' => 'Fråga ' . $id,
        'body_text' => 'Hej, vi vill ha ett batteri.',
        'source' => 'test-reply-to',
        'test' => true,
    ];
};

$with = jeab_inkorg_ingest($mail('med', 'anna.till@example.test', 'kontakt@jarfalla.example.test'));
$without = jeab_inkorg_ingest($mail('utan', 'noreply@shop.example.test', 'kontakt@jarfalla.example.test'));
rtl(!empty($with['ok']) && !empty($without['ok']), 'båda trådarna tas in');
$withId = (string) $with['thread_id'];
$withoutId = (string) $without['thread_id'];

$open = static function (string $id): array {
    foreach (jeab_inkorg_threads() as $thread) {
        if ($thread['id'] === $id) {
            return $thread;
        }
    }
    return [];
};
rtl(jeab_inkorg_reply_recipient($open($withId)) === 'anna.till@example.test', 'tråden med kundadress pekar på kunden');
rtl(jeab_inkorg_reply_recipient($open($withoutId)) === '', 'tråden utan kundadress är tom');

$render = static function (string $id): string {
    $_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => $id];
    $_SESSION['jeab_inkorg_box'] = 'jarfalla';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/index.php';

    return (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
};
$composer = static function (string $html): string {
    if (preg_match('/<form class="ix-composer"[\s\S]*?<\/form>/', $html, $match) !== 1) {
        return '';
    }

    return $match[0];
};

$filled = $composer($render($withId));
$empty = $composer($render($withoutId));
rtl($filled !== '' && $empty !== '', 'båda svarsrutorna ritas');
rtl(str_contains($filled, 'Till: anna.till@example.test'), 'rutan visar Till och kundens adress');
rtl(str_contains($empty, 'Till: — (saknar mejl)'), 'tom mottagare visar att mejlet saknas');
$till = strpos($filled, 'Till: anna.till@example.test');
$field = strpos($filled, '<textarea');
rtl($till !== false && $field !== false && $till < $field, 'Till står ovanför textfältet');
rtl(str_contains($filled, 'data-savebtn>Skicka och stäng') && !str_contains($filled, 'data-no-mail'), 'en adress lämnar Skicka på');
rtl(str_contains($empty, 'data-no-mail="1"') && str_contains($empty, 'data-savebtn disabled'), 'utan adress är Skicka avstängd');
rtl(str_contains($empty, 'value="note"'), 'anteckning finns kvar när mejlet saknas');

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg.php');
rtl(str_contains($page, "c.getAttribute('data-no-mail') === '1' && !noteMode()"), 'anteckning släpper igenom när Skicka är avstängt');
$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/inkorg.css');
rtl(str_contains($css, 'body.page-inkorg .ix-to'), 'Till-raden har en egen stil');

echo "inkorg reply to line ok\n";
