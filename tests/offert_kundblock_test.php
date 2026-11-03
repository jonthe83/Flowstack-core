<?php

declare(strict_types=1);

/**
 * Offertmallar ska inte lägga kundens namn eller e-post efter signaturen.
 * Påhittad kund. dry=1 och Graph-stub: inget mejl lämnar testet.
 */

$tmp = sys_get_temp_dir() . '/jeab-kundblock-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function kb(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = function (array $a) {
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => false, 'error' => 'testet ska inte skicka'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = fn() => ['code' => 200, 'body' => ['value' => []]];
$GLOBALS['_JEAB_REBOOK_LLM'] = fn() => json_encode(['answer' => 'Ja, det ingår i offerten.']);
@mkdir(jeab_data_dir() . '/secrets', 0775, true);
file_put_contents(jeab_data_dir() . '/secrets/inkorg-llm.json', json_encode(['key' => 'test']));

$name = 'Fejk Kund';
$email = 'fejk.kund@exempel.se';
$phone = '070-000 00 01';
$address = 'Fejkgatan 1, 176 70 Järfälla';

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 1,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
foreach (array_keys(jeab_auto_quote_seed()) as $key) {
    jeab_auto_pdf_save(['key' => $key, 'data' => base64_encode("%PDF-1.4 {$key}")]);
}
require __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();

$after = static function (string $html): string {
    $pos = -1;
    foreach (['Elektriker', 'Demo Energi Malmö', 'Demo Energi Järfälla', 'cid:team'] as $mark) {
        $at = mb_strrpos($html, $mark);
        if ($at !== false) {
            $pos = max($pos, $at);
        }
    }
    return $pos < 0 ? $html : mb_substr($html, $pos);
};
$clean = static function (string $html, string $label) use ($after, $name, $email): void {
    $tail = $after($html);
    kb($tail !== $html || str_contains($html, 'Elektriker'), $label . ' har en signatur');
    kb(!str_contains($tail, $name), $label . ': namnet ligger inte efter signaturen');
    kb(!str_contains($tail, $email), $label . ': e-posten ligger inte efter signaturen');
    kb(!str_contains($html, $email), $label . ': e-posten finns inte i offerten');
};

$lead = "Namn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nAdress: {$address}\n";
$ingest = static function (string $id, string $from, string $offer, string $company) use ($lead, $name): array {
    $box = jeab_graph_mailboxes()[$company];
    return jeab_inkorg_ingest([
        'message_id' => $id . '@kb.test',
        'from' => $from,
        'from_name' => 'Formulär',
        'to' => $box,
        'subject' => 'Offert',
        'body_text' => $lead,
        'source' => 'graph-' . $company,
        'graph_mailbox' => $box,
        'graph_id' => 'graph-' . $id,
        'thread_key' => 'kb-' . $id,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => $offer, 'product' => $offer, 'question' => '',
        ],
        'headers' => ['Authentication-Results' => 'abc.protection.outlook.com; dmarc=pass'],
    ]);
};

$cases = [
    ['ladd-jf', 'info@wix-forms.com', 'laddbox', 'jarfalla', 'Elbilsladdare Järfälla'],
    ['ladd-ma', 'info@wix-forms.com', 'laddbox', 'malmo', 'Elbilsladdare Malmö'],
    ['batt-jf', 'info@wix-forms.com', 'batteri', 'jarfalla', 'Batteri Järfälla'],
    ['batt-ma', 'info@wix-forms.com', 'batteri', 'malmo', 'Batteri Malmö'],
    ['sol-a', 'leads@leadkit.com', 'sol', 'jarfalla', 'Solceller Järfälla mall A'],
    ['sol-b', 'info@wix-forms.com', 'sol', 'jarfalla', 'Solceller Järfälla mall B'],
];
foreach ($cases as [$id, $from, $offer, $company, $label]) {
    $row = $ingest($id, $from, $offer, $company);
    $dry = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => (string) $row['thread_id'], 'dry' => 1]);
    kb(($dry['ok'] ?? false) === true && ($dry['body'] ?? '') !== '', $label . ' renderas, fick ' . (string) ($dry['why'] ?? $dry['error'] ?? ''));
    kb(str_contains((string) $dry['body'], 'Hej Fejk,'), $label . ' hälsar med förnamnet');
    $clean((string) $dry['body'], $label);
}

$solMalmo = jeab_auto_quote_html(jeab_auto_malmo_template('sol_malmo'), 'malmo', [
    'from' => 'info@wix-forms.com',
    'from_name' => $name,
    'body' => $lead,
], false);
kb($solMalmo !== '', 'Solceller Malmö renderas');
$clean($solMalmo, 'Solceller Malmö');

foreach (['kopt' => 'mall A', 'eget' => 'mall B'] as $variant => $label) {
    $tpl = jeab_offert_template([
        'variant' => $variant,
        'name' => $name,
        'email' => $email,
        'address' => $address,
        'phone' => $phone,
    ]);
    kb(str_contains($tpl['body'], 'Hej Fejk,'), $label . ' hälsar med förnamnet');
    $clean($tpl['body'], 'Solceller ' . $label);
}

kb($GLOBALS['_SENT'] === [], 'inget mejl skickades');
echo "offert kundblock: ok\n";
