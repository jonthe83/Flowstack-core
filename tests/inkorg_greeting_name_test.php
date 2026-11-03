<?php

declare(strict_types=1);

/** Hälsningens förnamn: stor begynnelsebokstav, åäö, bindestreck. Blandat skiftläge lämnas. */

$tmp = sys_get_temp_dir() . '/jeab-greeting-name-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function gn(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$greet = static function (string $name, string $platform = ''): string {
    return jeab_auto_greeting_name(
        ['from' => 'nagon@gmail.com', 'from_name' => 'Formulär JEAB', 'body' => ''],
        ['name' => $name, 'platform' => $platform, 'email' => '']
    );
};

gn($greet('magnus', 'wix-forms.com') === 'Magnus', 'formulärfältet magnus blir Magnus');
gn($greet('åke') === 'Åke', 'åke blir Åke');
gn($greet('ÖRJAN') === 'Örjan', 'ÖRJAN blir Örjan');
gn($greet('ÅKE') === 'Åke', 'ÅKE blir Åke');
gn($greet('anna-lena') === 'Anna-Lena', 'anna-lena blir Anna-Lena');
gn($greet('ANNA-LENA') === 'Anna-Lena', 'ANNA-LENA blir Anna-Lena');
gn($greet('McDonald') === 'McDonald', 'McDonald lämnas');
gn($greet('DeLuca') === 'DeLuca', 'DeLuca lämnas');
gn($greet('Torbjörn') === 'Torbjörn', 'Torbjörn som redan är rätt lämnas');
gn($greet('magnus ek', 'wix-forms.com') === 'Magnus', 'bara förnamnet, med stor bokstav');
gn(jeab_auto_greeting_name(['from_name' => ''], ['name' => '', 'platform' => '']) === '', 'tomt namn ger tom hälsning');
gn(jeab_auto_greeting_name(['from_name' => 'Formulär JEAB'], ['name' => 'Formulär JEAB', 'platform' => 'wix-forms.com']) === '', 'formuläraviseringen är inte ett namn');

$form = static function (string $name): array {
    $body = "A site visitor just submitted your form Batteri lead\n\nNamn:\n\n{$name}\n\nE-post:\n\nkund.gn@gmail.com\n";
    $msg = ['from' => 'info@wix-forms.com', 'from_name' => 'Formulär JEAB', 'subject' => 'JEAB Batteri lead', 'body' => $body];
    $cust = jeab_lead_customer($msg);
    return [$cust, jeab_auto_greeting_name($msg, $cust)];
};
[$formCust, $formGreet] = $form('magnus');
gn($formCust['name'] === 'magnus' && $formGreet === 'Magnus', 'källan formulärfält: magnus hälsas Magnus, fältet skrivs inte om');
gn($form('åke')[1] === 'Åke', 'källan formulärfält: åke');
gn($form('ÖRJAN')[1] === 'Örjan', 'källan formulärfält: ÖRJAN');
gn($form('anna-lena')[1] === 'Anna-Lena', 'källan formulärfält: anna-lena');
gn($form('McDonald')[1] === 'McDonald', 'källan formulärfält: McDonald');
gn($form('DeLuca Rossi')[1] === 'DeLuca', 'källan formulärfält: DeLuca');

$fromMsg = ['from' => 'orjan.svensson@gmail.com', 'from_name' => 'ÖRJAN', 'subject' => 'Batteri', 'body' => "Hej, jag vill ha ett batteri till villan.\n"];
$fromCust = jeab_lead_customer($fromMsg);
gn($fromCust['name'] === 'ÖRJAN' && jeab_auto_greeting_name($fromMsg, $fromCust) === 'Örjan', 'källan From: ÖRJAN blir Örjan');
$fromMixed = ['from' => 'x.deluca@gmail.com', 'from_name' => 'DeLuca', 'subject' => 'Batteri', 'body' => "Hej, jag vill ha ett batteri.\n"];
gn(jeab_auto_greeting_name($fromMixed, jeab_lead_customer($fromMixed)) === 'DeLuca', 'källan From: DeLuca lämnas');

$sigMsg = [
    'from' => 'ake.berg@gmail.com',
    'from_name' => '',
    'subject' => 'Batteri',
    'body' => "Hej,\nVi vill ha ett batteri.\n\nMvh\nåke Berg\n",
];
$sigCust = jeab_lead_customer($sigMsg);
gn($sigCust['name'] === 'åke Berg' && jeab_auto_greeting_name($sigMsg, $sigCust) === 'Åke', 'källan signatur: åke blir Åke');

$localMsg = ['from' => 'magnus.ek@gmail.com', 'from_name' => '', 'subject' => 'Batteri', 'body' => "Hej, jag vill ha ett batteri till villan.\n"];
$localCust = jeab_lead_customer($localMsg);
gn($localCust['name'] === 'Magnus' && jeab_auto_greeting_name($localMsg, $localCust) === 'Magnus', 'källan local-del: magnus blir Magnus');
$localCaps = ['from' => 'örjan@gmail.com', 'from_name' => '', 'subject' => 'Batteri', 'body' => "Hej, jag vill ha ett batteri till villan.\n"];
gn(jeab_auto_greeting_name($localCaps, jeab_lead_customer($localCaps)) === 'Örjan', 'källan local-del: örjan blir Örjan');
$localAscii = ['from' => 'orjan@gmail.com', 'from_name' => '', 'subject' => 'Batteri', 'body' => "Hej, jag vill ha ett batteri till villan.\n"];
gn(jeab_auto_greeting_name($localAscii, jeab_lead_customer($localAscii)) === 'Orjan', 'källan local-del: orjan blir Orjan');

$GLOBALS['JEAB_GRAPH_SEND'] = static fn(): array => ['ok' => true, 'from' => 'saljare3@jarfalla.example.test'];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url): array {
    if ($method === 'GET' && str_contains($url, '/mailFolders/sentitems/messages')) {
        return ['code' => 200, 'body' => ['value' => []]];
    }
    return ['code' => 404, 'body' => []];
};
jeab_auto_settings_save(['auto_quote_enabled' => 1, 'tpl' => jeab_auto_quote_seed()]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 magnus")]);
jeab_notiser_save_body('offert-batteri-jarfalla', "Hej\n\n" . jeab_offer_batteri_neo_price_paragraph(), 'test');
$in = jeab_inkorg_ingest([
    'message_id' => 'magnus-gn@t',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär JEAB',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'JEAB Batteri lead',
    'body_text' => "A site visitor just submitted your form Batteri lead\n\nNamn:\n\nmagnus\n\nAdress:\n\nMagnusvägen 1\n\nTelefon:\n\n070-123 45 67\n\nE-post:\n\nmagnus.ek.gn@gmail.com\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'graph_id' => 'graph-magnus-gn',
    'thread_key' => 'gn-magnus',
    'preset_labels' => ['type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95, 'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => ''],
]);
$dry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $in['thread_id'], 'dry' => 1]);
$body = (string) ($dry['body'] ?? '');
gn(($dry['ok'] ?? false) === true && str_contains($body, 'Hej Magnus,') && !str_contains($body, 'Hej magnus,') && !str_contains($body, 'magnus<br>'), 'förhandsvisningen hälsar Hej Magnus och tar inte med formulärfältet');

echo "inkorg greeting name: ok\n";
