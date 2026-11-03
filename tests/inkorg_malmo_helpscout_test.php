<?php

declare(strict_types=1);

/** Malmö tillbaka i Help Scout (Sam 2026-10-15): ingen Graph-inläsning och inget Inkorgen-utskick. Bekräftelse utan Help Scout-brevlåda faller tillbaka till Graph från kontakt@. Järfälla oförändrat. */

$tmp = sys_get_temp_dir() . '/jeab-mhs-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export(['timezone' => 'Europe/Stockholm', 'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json']], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);

function mh(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_GRAPH'] = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body) {
    $GLOBALS['_GRAPH'][] = [$method, $url];
    return ['code' => str_ends_with($url, '/sendMail') ? 202 : 500, 'body' => []];
};
jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);

mh(jeab_inkorg_helpscout_companies() === ['malmo'], 'standard: Malmö i Help Scout');
mh(jeab_inkorg_company_live('jarfalla') && !jeab_inkorg_company_live('malmo'), 'Järfälla kvar i Inkorgen');
mh(array_keys(jeab_graph_ingest_mailboxes()) === ['jarfalla'], 'Graph läser bara Järfälla');
$_GET['box'] = 'malmo';
mh(jeab_inkorg_box() === 'malmo' && jeab_inkorg_box_readonly() && !jeab_inkorg_company_live('malmo'), 'Malmö öppnas som skrivskyddad historik');
mh(array_keys(jeab_graph_ingest_mailboxes()) === ['jarfalla'], 'historiken slår inte på Graph för Malmö');
unset($_GET['box']);
mh(jeab_inkorg_box() === 'jarfalla', 'utan val stannar inkorgen på Järfälla');

$r = jeab_inkorg_send_reply('t', 'kund@ex.se', 'Hej', 'x', 'malmo');
mh(empty($r['ok']) && $GLOBALS['_GRAPH'] === [], 'inget utskick från Inkorgen för Malmö');
$o = jeab_outbound_graph_send('malmo', 'kund@ex.se', 'Hej', 'x');
mh(empty($o['ok']) && $GLOBALS['_GRAPH'] === [], 'inget Graph-systemmejl för Malmö');

$m = jeab_system_mail_send(['to' => 'kund@ex.se', 'subject' => 'Installation', 'text' => 'Hej', 'company' => 'malmo', 'from_email' => 'kontakt@malmo.example.test', 'job_type' => 'laddbox']);
mh(($m['ok'] ?? false) === true && ($m['path'] ?? '') === 'graph' && count($GLOBALS['_GRAPH']) === 1 && str_contains((string) ($GLOBALS['_GRAPH'][0][1] ?? ''), 'kontakt%40malmo.example.test'), 'Malmö-bekräftelse utan Help Scout-brevlåda går via Graph från kontakt@');
mh(($m['path'] ?? '') !== 'inkorg', 'Malmö-bekräftelse öppnar inget Inkorgen-ärende');

$j = jeab_system_mail_send(['to' => 'kund@ex.se', 'subject' => 'Installation', 'text' => 'Hej', 'company' => 'jarfalla', 'job_type' => 'laddbox']);
mh(($j['path'] ?? '') === 'inkorg', 'Järfälla-bekräftelse via Inkorgen som förut');

$hs = jeab_email_send('kund@ex.se', 'x', 'y', null, [], ['kalender'], false, 'jarfalla');
mh(empty($hs['ok']) && str_contains((string) $hs['error'], 'Help Scout används inte'), 'Järfälla skriver fortfarande inte till Help Scout');

jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
mh(jeab_inkorg_company_live('malmo') && array_keys(jeab_graph_poll_mailboxes()) === ['jarfalla'], 'Malmö läses inte in via Graph även om bocken tas bort');
echo "inkorg malmö help scout: ok\n";
