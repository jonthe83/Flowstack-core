<?php

declare(strict_types=1);

/**
 * N-46: hold/skip vid offertskick visas som fel (role=alert), inte som
 * neutral flash. Påhittade adresser. Inget skickas.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-quote-hold-flash-' . bin2hex(random_bytes(4));
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
require_once dirname(__DIR__) . '/src/layout.php';

function qhf(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$src = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg.php');
qhf(
    str_contains($src, "(\$res['action'] ?? '') === 'hold'")
    && str_contains($src, "throw new InvalidArgumentException('Offerten skickades inte:"),
    'hold/skip kastar så säljaren ser felet'
);

$err = new InvalidArgumentException('Offerten skickades inte: offertutskick är av');
qhf(jeab_public_error_text($err) === 'Offerten skickades inte: offertutskick är av', 'feltexten når säljaren');
qhf(jeab_inkorg_error_status($err) === 422, 'hold är 422');
$html = jeab_error_html($err->getMessage());
qhf(str_contains($html, 'role="alert"') && str_contains($html, 'class="error"') && str_contains($html, 'Offerten skickades inte'), 'felet ritas som error/alert');
qhf(jeab_flash_text('Offerten skickades inte: offertutskick är av') === null, 'hold går inte som neutral flash');

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 hold\n")]);

$held = jeab_inkorg_ingest([
    'message_id' => 'hold-flash@hold.test',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert',
    'body_text' => "Namn: Test Hold\nE-post: test.hold@example.org\nTelefon: 070-000 00 22\nAdress: Testgatan 3, 176 70 Järfälla\nProdukt: Batteri\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'thread_key' => 'hold-flash',
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
        'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
    ],
]);
$_POST = ['thread' => (string) ($held['thread_id'] ?? ''), 'dry' => '0'];
$caught = null;
$before = count($GLOBALS['_SENT']);
try {
    jeab_inkorg_action('inkorg.send_quote');
} catch (Throwable $e) {
    $caught = $e;
}
qhf($caught instanceof InvalidArgumentException, 'hold kastar InvalidArgumentException');
qhf(is_object($caught) && str_starts_with($caught->getMessage(), 'Offerten skickades inte:'), 'meddelandet säger att inget skickades');
qhf(count($GLOBALS['_SENT']) === $before, 'inget mejl gick ut');

echo "OK  quote_hold_flash\n";
