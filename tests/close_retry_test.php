<?php

declare(strict_types=1);

/** En stoppad stängning av ett offertärende loggar orsaken och prövas igen. */

$tmp = sys_get_temp_dir() . '/jeab-close-retry-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp . '/fel';
mkdir($GLOBALS['_JEAB_FELLOGG_DIR'], 0775, true);
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/tick.php';

function cr(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$graph = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_graph.php');
cr(str_contains($graph, 'Stängning av offertärende stoppades. ') && str_contains($graph, '$e->getMessage()'), 'graph-loggen tar med orsaken');
cr(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/inkorg_auto.php'), 'jeab_inkorg_close_quoted_try('), 'offertärenden stängs fortfarande automatiskt');

$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a = []): array {
    unset($a);
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'C', 'message_id' => 's@x'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null, array $headers = []): array {
    unset($method, $url, $body, $headers);
    return ['code' => 200, 'body' => ['value' => []]];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'tpl' => jeab_auto_quote_seed(),
]);

$lead = static function (string $id, string $email, string $name): array {
    $box = jeab_graph_mailboxes()['jarfalla'];
    return jeab_inkorg_ingest([
        'message_id' => $id . '@close-retry.test',
        'from' => $email,
        'from_name' => $name,
        'to' => $box,
        'subject' => 'Batteri till ' . $name,
        'body_text' => "Namn: {$name}\nE-post: {$email}\nTelefon: 070-100 20 40\nAdress: Solvägen 4, 176 70 Järfälla\nHej, jag vill ha ett batteri.\n",
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'thread_key' => 'cr-' . $id,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
        ],
    ]);
};
$quote = static function (string $tid): void {
    jeab_inkorg_thread_write($tid, static function (array $t): array {
        $drafts = array_values((array) ($t['drafts'] ?? []));
        $drafts[] = [
            'id' => 'd-quote-' . bin2hex(random_bytes(3)),
            'kind' => 'note',
            'body' => 'Offert (Batteri) skickad till kunden.',
            'state' => 'note',
            'author' => 'Test',
            'source' => 'manual',
            'created_at' => date('c'),
        ];
        $t['drafts'] = $drafts;
        return $t;
    });
};
$status = static function (string $tid): string {
    return (string) (jeab_inkorg_thread_meta($tid)['status'] ?? '');
};

$ada = $lead('ada', 'ada.ny@gmail.com', 'Ada Ny');
$adaTid = (string) $ada['thread_id'];
$quote($adaTid);
jeab_create_reminder(jeab_store(), 'Ada Ny – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Ada Ny',
    'email' => 'ada.ny@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'mail: ' . jeab_lead_mail_url($adaTid),
]);
jeab_inkorg_thread_write($adaTid, static function (array $t): array {
    $t['status'] = 'open';
    unset($t['hook_closed']);
    return $t;
});

$only = $lead('bosse', 'bosse.lund@gmail.com', 'Bosse Lund');
$onlyTid = (string) $only['thread_id'];
$quote($onlyTid);

$GLOBALS['_JEAB_CLOSE_FAIL_ONCE'] = 'SQLSTATE[HY000] 1203 för ada.ny@gmail.com';
jeab_inkorg_close_quoted_try($adaTid);
cr($status($adaTid) === 'open' && isset(jeab_inkorg_close_retry_read()[$adaTid]), 'en stoppad stängning lämnar ärendet och köar det');
$why = (string) (jeab_inkorg_close_retry_read()[$adaTid]['why'] ?? '');
$closeRows = jeab_store()->pdo()->query('SELECT thread_id, why FROM inbox_close_retry')->fetchAll();
$closeJson = (string) json_encode($closeRows);
cr(str_contains($why, '1203') && str_contains($why, '[mejl]') && !str_contains($why, 'ada.ny@gmail.com') && count($closeRows) === 1 && !str_contains($closeJson, '@') && !is_file(jeab_inkorg_dir() . '/close-retry.json'), 'kön ligger i SQL utan mejladress och utan JSON');
$log = (string) file_get_contents($GLOBALS['_JEAB_FELLOGG_DIR'] . '/app-errors.jsonl');
cr(str_contains($log, 'Stängning av offertärende stoppades.') && str_contains($log, '1203') && str_contains($log, '[mejl]') && !str_contains($log, 'ada.ny@gmail.com'), 'felloggen visar maskerad orsak');

jeab_inkorg_close_quoted_try($onlyTid);
cr($status($onlyTid) === 'open' && !isset(jeab_inkorg_close_retry_read()[$onlyTid]), 'offert utan påminnelse stängs inte och köas inte');

$tick = jeab_tick_inkorg_live(microtime(true) + 5);
cr(($tick['ok'] ?? false) === true && $status($adaTid) === 'closed' && !isset(jeab_inkorg_close_retry_read()[$adaTid]), 'nästa tick stänger offertärendet');

$pia = $lead('pia', 'pia.ek@gmail.com', 'Pia Ek');
$piaTid = (string) $pia['thread_id'];
$quote($piaTid);
jeab_create_reminder(jeab_store(), 'Pia Ek – Batteri', jeab_today_start(), null, null, [
    'customer' => 'Pia Ek',
    'email' => 'pia.ek@gmail.com',
    'company' => 'jarfalla',
    'who' => 'Roberto',
    'note' => 'mail: ' . jeab_lead_mail_url($piaTid),
]);
jeab_inkorg_thread_write($piaTid, static function (array $t): array {
    $t['status'] = 'open';
    unset($t['hook_closed']);
    return $t;
});
jeab_inkorg_close_quoted_try($piaTid);
cr($status($piaTid) === 'closed', 'skickad offert med påminnelse stängs direkt när inget stoppar');

echo "close retry: ok\n";
