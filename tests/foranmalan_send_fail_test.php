<?php

declare(strict_types=1);

/**
 * Misslyckad föranmälan till Bokmalen sparar formuläret, visar felet
 * och lämnar «Skicka igen» på samma once-väg. Ingen automatisk omsändning
 * och inget andra utskick när nästa klick väl går fram.
 */

$tmp = sys_get_temp_dir() . '/jeab-foranmalan-fail-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempdir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
putenv('JEAB_DISABLE_SQLITE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];

require dirname(__DIR__) . '/src/bootstrap.php';

function ff_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ff_secret(string $url, string $key): void
{
    $path = jeab_bokmalen_ordrar_webhook_secret_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode(['url' => $url, 'key' => $key], JSON_UNESCAPED_SLASHES));
}

function ff_clear_secret(): void
{
    $path = jeab_bokmalen_ordrar_webhook_secret_path();
    if (is_file($path)) {
        unlink($path);
    }
}

/** @return list<array<string,mixed>> */
function ff_posts(): array
{
    $out = [];
    foreach ($GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? [] as $row) {
        if (is_array($row) && ($row['type'] ?? '') === 'ordrar.foranmalan') {
            $out[] = $row;
        }
    }
    return $out;
}

$failText = jeab_ordrar_foranmalan_send_fail_message();
ff_assert(
    $failText === 'Föranmälan kunde inte skickas till Bokmalen. Kontrollera webhook-nyckeln och försök igen.',
    'feltexten ber om webhook-nyckeln'
);
ff_assert(!str_contains($failText, 'secret'), 'feltexten innehåller inte secret');
ff_assert(jeab_public_error_sentence($failText), 'jeab_public_error_sentence släpper igenom meningen');
ff_assert(jeab_public_error_text(new InvalidArgumentException($failText)) === $failText, 'användaren ser meningen');

$blocked = jeab_einstein_webhook_exchange(['url' => '', 'key' => ''], ['type' => 'ordrar.foranmalan']);
ff_assert(($blocked['ok'] ?? true) === false && ($blocked['http'] ?? null) === 0, 'avvisad växling lämnar http 0');
$captured = jeab_einstein_webhook_exchange(
    ['url' => 'https://bokmalen.example.invalid/hooks/jeab-ordrar', 'key' => 'k'],
    ['type' => 'ping']
);
ff_assert(($captured['ok'] ?? false) === true && ($captured['http'] ?? 0) === 200, 'fångad växling lämnar http 200');
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];

ff_clear_secret();
$row = jeab_purchase_put([
    'reminder_id' => 8801,
    'kind' => 'batteri',
    'company' => 'jarfalla',
    'natbolag' => 'E.ON',
    'huvudsokande' => 'Ada Adolfsson',
    'name' => 'Ada Adolfsson',
    'phone' => '0701234567',
    'email' => 'ada@example.com',
    'huvudsokande_address' => 'Storgatan 1, 176 74 Järfälla',
    'anlaggnings_id' => '',
    'kwh' => '10',
    'vaxelriktare' => 'Sigenergy',
    'foranmalan_status' => 'A',
    'fortnox_order' => '1001',
    'steps' => ['bekraftad' => '2026-09-01'],
]);

$threw = '';
try {
    jeab_ordrar_save_foranmalan([
        'id' => 8801,
        'intent' => 'send',
        'natbolag' => 'Vattenfall',
        'anlaggnings_id' => '735 999 114 010 135 815',
    ]);
} catch (InvalidArgumentException $e) {
    $threw = $e->getMessage();
}
ff_assert($threw === $failText, 'misslyckad POST kastar den synliga meningen, fick: ' . $threw);
ff_assert(ff_posts() === [], 'misslyckad POST skickar inget och försöker inte igen');

$saved = jeab_purchase_get(8801);
ff_assert(is_array($saved), 'ordern finns kvar');
ff_assert(!empty($saved['foranmalan_saved']), 'foranmalan_saved är true');
ff_assert(($saved['foranmalan_case_status'] ?? '') === 'ej skickad till Bokmalen', 'status är ej skickad till Bokmalen');
ff_assert(($saved['natbolag'] ?? '') === 'Vattenfall', 'nätbolag från formuläret sparas');
ff_assert(($saved['anlaggnings_id'] ?? '') === '735999114010135815', 'anläggnings-id från formuläret sparas');
ff_assert(($saved['kwh'] ?? '') === '10', 'övriga formulärfält ligger kvar');
ff_assert(trim((string) (($saved['steps']['foranmald'] ?? ''))) === '', 'foranmald stämplas inte');
ff_assert(jeab_ordrar_foranmalan_case_status($saved) !== 'skickad', 'läget är inte Skickad');
ff_assert(jeab_ordrar_foranmalan_wizard_done($saved) === false, 'steget är inte klart');

$eon = jeab_ordrar_eon_html($saved, 'jarfalla', 8801);
ff_assert(str_contains($eon, '>Skicka igen</button>'), 'ordersteget visar Skicka igen');
ff_assert(str_contains($eon, 'value="ordrar.advance"') && str_contains($eon, 'value="eon"'), 'Skicka igen postar samma Nästa-väg');
ff_assert(!str_contains($eon, 'ordrar.foranmalan_resend'), 'misslyckad föranmälan använder inte resend');
ff_assert(!str_contains($eon, '>Nästa</button>'), 'Nästa är bytt mot Skicka igen');
ff_assert(str_contains($eon, $failText), 'felet syns på steget');
ff_assert(str_contains($eon, 'ej skickad till Bokmalen'), 'statusen syns på steget');

$v3 = jeab_ordrar_v3_foranmalan_html($saved, 'jarfalla', 8801, ['state' => 'todo', 'n' => 2, 'reason' => '']);
ff_assert(str_contains($v3, '>Skicka igen<'), 'v3-steget visar Skicka igen');
ff_assert(str_contains($v3, 'value="ordrar.batterisol"') && str_contains($v3, 'name="intent" value="next"'), 'v3-knappen går via samma skickning');
ff_assert(!str_contains($v3, 'ordrar.foranmalan_resend'), 'v3-steget använder inte resend');
ff_assert(str_contains($v3, $failText) && str_contains($v3, 'ej skickad till Bokmalen'), 'v3 visar fel och status');
ff_assert(jeab_ordrar_v3_next_action($saved)['text'] === 'Skicka igen', 'listans nästa åtgärd är Skicka igen');

try {
    jeab_ordrar_foranmalan_nasta(null, jeab_purchase_get(8801) ?: $saved);
    ff_assert(false, 'andra klicket utan nyckel ska också kasta');
} catch (InvalidArgumentException $e) {
    ff_assert($e->getMessage() === $failText, 'andra klicket visar samma fel');
}
ff_assert(ff_posts() === [], 'andra klicket postar inte heller');

ff_secret('https://bokmalen.example.invalid/hooks/jeab-ordrar', 'ordrar-test-key');
$sent = jeab_ordrar_foranmalan_nasta(null, jeab_purchase_get(8801) ?: $saved);
$posts = ff_posts();
ff_assert(count($posts) === 1, 'Skicka igen postar en föranmälan, fick ' . count($posts));
ff_assert(($posts[0]['utkast'] ?? null) === false, 'omförsöket är live');
ff_assert(($posts[0]['natbolag'] ?? '') === 'Vattenfall', 'sparat nätbolag följer med');
ff_assert(trim((string) (($sent['steps']['foranmald'] ?? ''))) !== '', 'lyckat omförsök stämplar foranmald');
ff_assert(jeab_ordrar_foranmalan_case_status($sent) === 'skickad', 'lyckat omförsök sätter Skickad');
ff_assert(!jeab_ordrar_foranmalan_send_failed($sent), 'statusen ej skickad är borta');

$again = jeab_ordrar_foranmalan_nasta(null, jeab_purchase_get(8801) ?: $sent);
ff_assert(count(ff_posts()) === 1, 'ytterligare klick skapar ingen dubblett');
ff_assert(trim((string) (($again['steps']['foranmald'] ?? ''))) === trim((string) (($sent['steps']['foranmald'] ?? ''))), 'stämpeln ligger kvar');

$plain = jeab_purchase_put([
    'reminder_id' => 8802,
    'kind' => 'batteri',
    'company' => 'jarfalla',
    'natbolag' => 'E.ON',
    'anlaggnings_id' => '735999114010135815',
    'kwh' => '10',
    'foranmalan_status' => 'A',
]);
$plainHtml = jeab_ordrar_eon_html($plain, 'jarfalla', 8802);
ff_assert(str_contains($plainHtml, '>Nästa</button>'), 'oskickad föranmälan utan fel har kvar Nästa');
ff_assert(!str_contains($plainHtml, '>Skicka igen</button>'), 'Skicka igen syns inte innan ett misslyckat skick');

echo "föranmälan send fail: ok\n";
