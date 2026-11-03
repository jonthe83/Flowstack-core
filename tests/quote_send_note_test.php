<?php

declare(strict_types=1);

/**
 * N-44: manuellt offertskick antecknas som manuellt (vem), automatiskt som
 * automatiskt. Påhittade adresser. Inget skickas mot nätet.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-quote-note-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function qn(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

qn(jeab_auto_quote_sent_how(['automatic' => true]) === 'automatiskt', 'automatik antecknas som automatiskt');
qn(str_starts_with(jeab_auto_quote_sent_how([]), 'manuellt av '), 'manuellt skick nämner vem');
qn(jeab_auto_quote_sender_label(['automatic' => true]) === '', 'automatik har ingen avsändarperson');
qn(jeab_auto_quote_sender_label([]) !== '', 'manuellt skick har vem');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_auto.php');
qn(str_contains($src, 'jeab_auto_quote_sent_how($opts)'), 'anteckningen använder sent_how');
qn(str_contains($src, "'note' => 'Offert skickad ' . \$sentHow"), 'påminnelsen använder samma hur');

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static fn(): array => ['code' => 200, 'body' => ['value' => []]];
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 note\n")]);
require __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();

$notesOf = static function (string $tid): string {
    foreach (jeab_inkorg_threads() as $t) {
        if ((string) ($t['id'] ?? '') !== $tid) {
            continue;
        }
        $bits = [];
        foreach ((array) ($t['drafts'] ?? []) as $d) {
            if (($d['kind'] ?? '') === 'note') {
                $bits[] = (string) ($d['body'] ?? '');
            }
        }
        return implode("\n", $bits);
    }
    return '';
};

$manualIn = jeab_inkorg_ingest([
    'message_id' => 'note-manual@note.test',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert',
    'body_text' => "Namn: Fejk Manuell\nE-post: fejk.manuell@gmail.com\nTelefon: 070-000 00 23\nAdress: Fejkgatan 4, 176 70 Järfälla\nProdukt: Batteri\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'thread_key' => 'note-manual',
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
        'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
    ],
]);
$manualTid = (string) ($manualIn['thread_id'] ?? '');
$manual = jeab_auto_send_quote(['thread_id' => $manualTid, 'dry' => 0]);
$manualNotes = $notesOf($manualTid);
qn(($manual['action'] ?? '') === 'quoted', 'manuellt skick går igenom');
qn(str_contains($manualNotes, 'skickad manuellt av') && str_contains($manualNotes, 'fejk.manuell@gmail.com'), 'manuell anteckning nämner vem och mottagaren');
qn(!str_contains($manualNotes, 'skickad automatiskt'), 'manuellt skick sägs inte vara automatiskt');

echo "OK  quote_send_note\n";
