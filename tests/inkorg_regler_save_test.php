<?php

declare(strict_types=1);

/** Regler: flash efter sparning, och bara den sparade sektionens datum. */

$tmp = sys_get_temp_dir() . '/jeab-regler-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/layout.php';

function rs(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function rs_settings(): array
{
    $d = json_decode((string) file_get_contents(jeab_inkorg_dir() . '/settings.json'), true);
    return is_array($d) ? $d : [];
}

rs(jeab_flash_text('Automatik sparad.') === 'Automatik sparad.', 'tillåten flash-text');
rs(jeab_flash_text('Inte en flash.') === null, 'okänd flash-text visas inte');

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['flash' => 'Automatik sparad.'];
$flash = null;
$html = require dirname(__DIR__) . '/src/pages/inkorg_regler.php';
rs(is_string($html) && str_contains($html, 'class="flash"') && str_contains($html, 'Automatik sparad.'), 'sidan visar Automatik sparad.');

$_GET = ['flash' => 'Inte en flash.'];
$flash = null;
$html = require dirname(__DIR__) . '/src/pages/inkorg_regler.php';
rs(is_string($html) && !str_contains($html, 'class="flash"') && !str_contains($html, 'Inte en flash.'), 'otillåten flash renderas inte');

$today = date('Y-m-d');
$tpl = jeab_auto_quote_seed();
$example = ['id' => 'x-keep', 'from' => 'a@example.com', 'subject' => 'behall', 'snippet' => 's', 'label' => 'skrap', 'by' => 't', 'at' => '2020-01-01', 'message' => 'm1'];
$seed = [
    'intent' => 'UNIK-INSTRUKTION-XYZ',
    'product_tags' => ['solceller' => '', 'batteri' => '', 'elbilsladdare' => '', 'support' => ''],
    'examples' => [$example],
    'rebook_enabled' => true,
    'rebook_email_only' => true,
    'rebook_prompt' => 'UNIK-OMBOKNING-XYZ',
    'rebook_only_from' => 'only@example.com',
    'rebook_min_confidence' => 77,
    'helpscout_companies' => ['jarfalla'],
    'auto_quote_enabled' => false,
    'grok_decides' => true,
    'unknown_product_ask_enabled' => false,
    'auto_trash_spam' => true,
    'auto_quote_only_from' => 'lista@example.com',
    'auto_min_confidence' => 80,
    'auto_send_per_hour' => 10,
    'auto_send_per_day' => 40,
    'quote_templates' => $tpl,
    'section_saved' => [
        'instruktion' => '2020-01-02',
        'automatik' => '2020-01-03',
        'mejlboxar' => '2020-01-04',
        'ombokning' => '2020-01-05',
        'exempel' => '2020-01-06',
    ],
];
file_put_contents(jeab_inkorg_dir() . '/settings.json', json_encode($seed, JSON_UNESCAPED_UNICODE));

jeab_auto_settings_save([
    'auto_quote_enabled' => '1',
    'grok_decides' => '1',
    'auto_trash_spam' => '1',
    'auto_quote_only_from' => 'lista@example.com',
    'auto_min_confidence' => 80,
    'auto_send_per_hour' => 10,
    'auto_send_per_day' => 40,
    'tpl' => $tpl,
]);
$afterAuto = rs_settings();
rs(($afterAuto['section_saved']['automatik'] ?? '') === $today, 'automatik får dagens datum');
rs(($afterAuto['section_saved']['instruktion'] ?? '') === '2020-01-02', 'instruktionens datum står still');
rs(($afterAuto['section_saved']['mejlboxar'] ?? '') === '2020-01-04', 'mejlboxarnas datum står still');
rs(($afterAuto['section_saved']['ombokning'] ?? '') === '2020-01-05', 'ombokningens datum står still');
rs(($afterAuto['section_saved']['exempel'] ?? '') === '2020-01-06', 'exemplens datum står still');
rs(($afterAuto['intent'] ?? '') === 'UNIK-INSTRUKTION-XYZ', 'instruktionen skrivs inte om');
rs(($afterAuto['rebook_prompt'] ?? '') === 'UNIK-OMBOKNING-XYZ', 'ombokningstexten skrivs inte om');
rs(($afterAuto['helpscout_companies'] ?? []) === ['jarfalla'], 'mejlboxarna skrivs inte om');
rs(($afterAuto['examples'] ?? []) === [$example], 'exemplen skrivs inte om');
rs(!empty($afterAuto['grok_decides']), 'grok-brytaren i automatik finns kvar');
rs(jeab_inkorg_section_saved_label('automatik') === ' · senast ändrad ' . $today, 'automatik-etiketten är dagens datum');
rs(jeab_inkorg_section_saved_label('instruktion') === ' · senast ändrad 2020-01-02', 'instruktion-etiketten är den gamla');

jeab_inkorg_settings_save('UNIK-INSTRUKTION-XYZ', $seed['product_tags']);
$afterIntent = rs_settings();
rs(($afterIntent['section_saved']['instruktion'] ?? '') === $today, 'instruktion får dagens datum');
rs(($afterIntent['section_saved']['automatik'] ?? '') === $today, 'automatikens datum står still efter instruktion');
rs(($afterIntent['rebook_prompt'] ?? '') === 'UNIK-OMBOKNING-XYZ', 'ombokning står still efter instruktion');
rs(($afterIntent['helpscout_companies'] ?? []) === ['jarfalla'], 'mejlboxar står still efter instruktion');
rs(($afterIntent['quote_templates'] ?? []) === $tpl, 'mallarna står still efter instruktion');
rs(!empty($afterIntent['auto_quote_enabled']), 'automatikens brytare står still');

jeab_inkorg_helpscout_companies_save(['helpscout_companies' => ['jarfalla']]);
$afterHs = rs_settings();
rs(($afterHs['section_saved']['mejlboxar'] ?? '') === $today, 'mejlboxar får dagens datum');
rs(($afterHs['section_saved']['ombokning'] ?? '') === '2020-01-05', 'ombokningens datum står still efter mejlboxar');
rs(($afterHs['intent'] ?? '') === 'UNIK-INSTRUKTION-XYZ', 'instruktionen står still efter mejlboxar');

jeab_rebook_settings_save([
    'rebook_enabled' => '1',
    'rebook_email_only' => '1',
    'rebook_prompt' => 'UNIK-OMBOKNING-XYZ',
    'rebook_only_from' => 'only@example.com',
    'rebook_min_confidence' => 77,
]);
$afterRb = rs_settings();
rs(($afterRb['section_saved']['ombokning'] ?? '') === $today, 'ombokning får dagens datum');
rs(($afterRb['section_saved']['exempel'] ?? '') === '2020-01-06', 'exemplens datum står still efter ombokning');
rs(($afterRb['intent'] ?? '') === 'UNIK-INSTRUKTION-XYZ', 'instruktionen står still efter ombokning');
rs(($afterRb['helpscout_companies'] ?? []) === ['jarfalla'], 'mejlboxarna står still efter ombokning');

jeab_inkorg_example_delete('finns-inte');
$afterEx = rs_settings();
rs(($afterEx['section_saved']['exempel'] ?? '') === $today, 'exempel får dagens datum');
rs(($afterEx['examples'] ?? []) === [$example], 'befintligt exempel står still');
rs(($afterEx['rebook_prompt'] ?? '') === 'UNIK-OMBOKNING-XYZ', 'ombokning står still efter exempel');
rs(jeab_inkorg_section_saved_label('saknas') === '', 'sektion utan datum visar inget');

echo "OK\n";
