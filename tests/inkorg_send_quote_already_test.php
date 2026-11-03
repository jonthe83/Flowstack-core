<?php

declare(strict_types=1);

/**
 * N-41/N-42: redan skickad offert ger «Offerten är redan skickad»,
 * inte «Något gick fel», och knappen blir «Offert skickad».
 * Påhittade adresser. Inget skickas.
 */

$tmp = sys_get_temp_dir() . '/jeab-quote-already-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
session_save_path($tmp);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function qa(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$already = new JeabAlreadySentException('already sent');
qa(jeab_inkorg_error_status($already) === 409, 'redan skickad är 409');
qa(jeab_public_error_text($already) === 'Offerten är redan skickad', 'undantaget visas som Offerten är redan skickad');
qa(jeab_public_error_text(new RuntimeException('hemlig orsak')) === 'Något gick fel.', 'okänt fel är fortfarande Något gick fel');

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg.php');
qa(str_contains($page, 'jeab_inkorg_quote_was_sent($open)') && str_contains($page, 'Offert skickad'), 'menyn använder flaggan och visar Offert skickad');
qa(str_contains($page, '>Skicka offert<'), 'Skicka offert finns kvar när offert inte är skickad');

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['_SENT'][] = $opts;
    return ['ok' => true, 'path' => 'stub', 'id' => 'm1', 'from' => 'saljare3@jarfalla.example.test'];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 already\n")]);
jeab_notiser_save_body('offert-batteri-jarfalla', "Hej\n\nOffert batteri.", 'test');

$open = jeab_inkorg_ingest([
    'message_id' => 'open-quote@already.test',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert',
    'body_text' => "Namn: Fejk Öppen\nE-post: fejk.open@exempel.test\nTelefon: 070-000 00 21\nAdress: Fejkgatan 2, 176 70 Järfälla\nProdukt: Batteri\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'graph_id' => 'graph-open-quote',
    'thread_key' => 'already-open',
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
        'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
    ],
]);
$sent = jeab_inkorg_ingest([
    'message_id' => 'sent-quote@already.test',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert',
    'body_text' => "Namn: Fejk Skickad\nE-post: fejk.sent@exempel.test\nTelefon: 070-000 00 22\nAdress: Fejkgatan 3, 176 70 Järfälla\nProdukt: Batteri\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'graph_id' => 'graph-sent-quote',
    'thread_key' => 'already-sent',
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
        'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
    ],
]);
qa(!empty($open['ok']) && !empty($sent['ok']), 'båda ärendena tas in');
$openTid = (string) ($open['thread_id'] ?? '');
$sentTid = (string) ($sent['thread_id'] ?? '');
jeab_inkorg_add_draft($sentTid, ['kind' => 'note', 'body' => 'Offert (Batteri) skickad till kunden.'], 'Test');
jeab_inkorg_thread_write($sentTid, static function (array $t): array {
    $t['quote_sent'] = ['source' => 'anteckning', 'date' => '2026-10-21', 'product' => 'batteri'];
    return $t;
});

$threadOf = static function (string $id): array {
    foreach (jeab_inkorg_threads() as $thread) {
        if ((string) ($thread['id'] ?? '') === $id) {
            return $thread;
        }
    }
    return [];
};
qa(jeab_inkorg_quote_was_sent($threadOf($sentTid)) && !jeab_inkorg_quote_was_sent($threadOf($openTid)), 'flaggan är satt bara på den skickade tråden');

$before = count($GLOBALS['_SENT']);
$_POST = ['thread' => $sentTid, 'dry' => '0'];
$caught = null;
try {
    jeab_inkorg_action('inkorg.send_quote');
} catch (Throwable $e) {
    $caught = $e;
}
qa($caught instanceof JeabAlreadySentException, 'send_quote kastar när offert redan finns');
qa($caught instanceof JeabAlreadySentException && $caught->getMessage() === 'Offerten är redan skickad', 'meddelandet är Offerten är redan skickad');
qa(jeab_public_error_text($caught) === 'Offerten är redan skickad', 'användaren ser Offerten är redan skickad');
qa(count($GLOBALS['_SENT']) === $before, 'inget mejl går ut en andra gång');

$render = static function (string $id): string {
    $_GET = ['p' => 'inkorg', 'box' => 'jarfalla', 'id' => $id];
    $_SESSION['jeab_inkorg_box'] = 'jarfalla';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/index.php';

    return (string) include dirname(__DIR__) . '/src/pages/inkorg.php';
};
$openHtml = $render($openTid);
$sentHtml = $render($sentTid);
qa(str_contains($openHtml, '>Skicka offert<') && str_contains($openHtml, 'data-send-quote="1"'), 'utan offert syns Skicka offert');
qa(str_contains($sentHtml, 'disabled>Offert skickad</button>') && !str_contains($sentHtml, 'data-send-quote="1"'), 'med offert visas Offert skickad och knappen skickar inte');

echo "inkorg send quote already ok\n";
