<?php

declare(strict_types=1);

/**
 * Spec-rutans Nästa (ordrar.batterisol, intent=next) med Automatiskt
 * ska posta till Bokmalen innan Föranmälan räknas som klar.
 * Misslyckad POST får inte tända Skickad eller steget is-done.
 */

$tmp = sys_get_temp_dir() . '/jeab-spec-foranmalan-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempdir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', '<?php return ' . var_export([
    'db' => [
        'driver' => 'json',
        'json_path' => $tmp . '/store.json',
        'sqlite_path' => $tmp . '/app.sqlite',
    ],
], true) . ';');
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
putenv('JEAB_DISABLE_SQLITE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
$GLOBALS['SPEC_MAILS'] = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['SPEC_MAILS'][] = $opts;
    return ['ok' => true, 'path' => 'helpscout', 'error' => null, 'id' => 'hs-spec'];
};

require dirname(__DIR__) . '/src/bootstrap.php';

function spec_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function spec_secret(string $which, string $url, string $key): void
{
    $path = $which === 'fullmakt'
        ? jeab_bokmalen_ellevio_fullmakt_webhook_secret_path()
        : jeab_bokmalen_ordrar_webhook_secret_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if ($url === '' && $key === '') {
        if (is_file($path)) {
            unlink($path);
        }
        return;
    }
    file_put_contents($path, json_encode(['url' => $url, 'key' => $key], JSON_UNESCAPED_SLASHES));
}

function spec_reset(): void
{
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
    $GLOBALS['SPEC_MAILS'] = [];
}

/** @return list<array<string,mixed>> */
function spec_of_type(string $type): array
{
    $rows = $GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? [];
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (is_array($row) && ($row['type'] ?? '') === $type) {
            $out[] = $row;
        }
    }
    return $out;
}

function spec_put(array $extra): array
{
    static $seq = 7100;
    $seq++;
    $row = array_merge([
        'reminder_id' => $seq,
        'kind' => 'batteri',
        'company' => 'jarfalla',
        'natbolag' => 'E.ON',
        'huvudsokande' => 'Ada Adolfsson',
        'name' => 'Ada Adolfsson',
        'personnummer' => '19700101-0101',
        'fastighetsbeteckning' => 'Krokus 1',
        'phone' => '0701234567',
        'email' => 'ada@example.com',
        'huvudsokande_address' => 'Krokusvägen 4, 176 74 Järfälla',
        'anlaggnings_id' => '735999114010135815',
        'kwh' => '12',
        'vaxelriktare' => 'Sigenergy 12 kW TP2',
        'foranmalan_status' => 'A',
        'steps' => ['bekraftad' => '2026-09-01', 'spec' => '2026-09-30'],
    ], $extra);
    return jeab_purchase_put($row);
}

function spec_input(array $row, array $extra = []): array
{
    return array_merge([
        'id' => (int) ($row['reminder_id'] ?? 0),
        'intent' => 'next',
        'vaxelriktare' => (string) ($row['vaxelriktare'] ?? ''),
        'kwh' => (string) ($row['kwh'] ?? ''),
        'anlaggnings_id' => (string) ($row['anlaggnings_id'] ?? ''),
        'natbolag' => (string) ($row['natbolag'] ?? ''),
        'foranmalan_status' => (string) ($row['foranmalan_status'] ?? 'A'),
    ], $extra);
}

function spec_flow_done(array $row): bool
{
    foreach (jeab_ordrar_flow($row) as $step) {
        if (($step['key'] ?? '') === 'spec') {
            return !empty($step['done']);
        }
    }
    return false;
}

function spec_li_done(string $html): bool
{
    if (preg_match('/<li class="([^"]*)"[^>]*data-key="spec"/', $html, $m) !== 1) {
        return false;
    }
    return preg_match('/(?:^|\s)is-done(?:\s|$)/', $m[1]) === 1;
}

function spec_skickad_on(string $html): bool
{
    return preg_match('/class="ordrar-status-pill[^"]*\bis-on\b[^"]*"[^>]*>Skickad</', $html) === 1;
}

function spec_reload(array $row): array
{
    $got = jeab_purchase_get((int) ($row['reminder_id'] ?? 0));
    spec_assert(is_array($got), 'ordern finns kvar');
    return $got;
}

function spec_cleanup_token(string $token): void
{
    if ($token === '' || !function_exists('fullmakt_pending_path')) {
        return;
    }
    foreach ([
        fullmakt_pending_path($token),
        fullmakt_signed_json_path($token),
        fullmakt_signed_pdf_path($token),
    ] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

spec_secret('ordrar', 'https://bokmalen.example.invalid/hooks/jeab-ordrar', 'ordrar-test-key');
spec_secret('fullmakt', 'https://bokmalen.example.invalid/hooks/jeab-ellevio-fullmakt', 'fullmakt-test-key');

$tokens = [];
try {
    spec_reset();
    $stale = spec_put([
        'kind' => 'batteri',
        'natbolag' => 'E.ON',
        'foranmalan_status' => 'A',
    ]);
    $staleHtml = jeab_ordrar_stepper_html($stale, 'jarfalla', (int) $stale['reminder_id'], 'spec');
    $staleEon = jeab_ordrar_eon_html($stale, 'jarfalla', (int) $stale['reminder_id']);
    spec_assert(spec_flow_done($stale) === false, 'Automatiskt med spec-stämpel men utan POST är inte klart i flödet');
    spec_assert(spec_li_done($staleHtml) === false, 'steppern visar inte Föranmälan som klar utan POST');
    spec_assert(spec_skickad_on($staleEon) === false, 'Skickad är släckt utan POST');
    spec_assert(str_contains($staleEon, '>Nästa</button>'), 'eon-kortet har kvar Nästa');
    spec_assert(str_contains(jeab_ordrar_spec_html($stale, 'jarfalla', (int) $stale['reminder_id']), 'Nästa med Automatiskt skickar föranmälan till Bokmalen.'), 'spec-rutan säger att Nästa skickar');

    spec_reset();
    spec_secret('ordrar', '', '');
    $blocked = spec_put(['kind' => 'batteri', 'natbolag' => 'E.ON', 'foranmalan_status' => 'A']);
    $threw = false;
    $throwText = '';
    try {
        jeab_ordrar_save_batterisol(spec_input($blocked));
    } catch (InvalidArgumentException $e) {
        $threw = true;
        $throwText = $e->getMessage();
    }
    spec_assert($threw, 'Nästa utan webhook-secret kastar');
    spec_assert(str_contains($throwText, 'Bokmalen'), 'feltexten nämner Bokmalen, fick: ' . $throwText);
    $blockedNow = spec_reload($blocked);
    spec_assert(trim((string) (($blockedNow['steps']['foranmald'] ?? ''))) === '', 'misslyckad POST stämplar inte foranmald');
    spec_assert(jeab_ordrar_foranmalan_case_status($blockedNow) !== 'skickad', 'misslyckad POST sätter inte Skickad');
    spec_assert(spec_flow_done($blockedNow) === false, 'flödet är inte klart efter misslyckad POST');
    spec_assert(spec_li_done(jeab_ordrar_stepper_html($blockedNow, 'jarfalla', (int) $blockedNow['reminder_id'], 'spec')) === false, 'steppern är inte is-done efter misslyckad POST');
    spec_assert(spec_skickad_on(jeab_ordrar_eon_html($blockedNow, 'jarfalla', (int) $blockedNow['reminder_id'])) === false, 'Skickad förblir släckt efter misslyckad POST');
    spec_assert(spec_of_type('ordrar.foranmalan') === [], 'ingen ordrar.foranmalan fångas utan secret');
    $blockedActivity = array_column(is_array($blockedNow['activity'] ?? null) ? $blockedNow['activity'] : [], 'text');
    spec_assert(!in_array('Föranmälan skickad', $blockedActivity, true), 'ingen aktivitet Föranmälan skickad vid fel');

    spec_reset();
    spec_secret('ordrar', 'https://bokmalen.example.invalid/hooks/jeab-ordrar', 'ordrar-test-key');
    $battery = spec_put(['kind' => 'batteri', 'natbolag' => 'E.ON', 'foranmalan_status' => 'A']);
    $sentBattery = jeab_ordrar_save_batterisol(spec_input($battery));
    $batteryPosts = spec_of_type('ordrar.foranmalan');
    spec_assert(count($batteryPosts) === 1, 'batteri E.ON Nästa postar ordrar.foranmalan, fick ' . count($batteryPosts));
    spec_assert(($batteryPosts[0]['utkast'] ?? null) === false, 'Automatiskt har utkast false');
    spec_assert(($batteryPosts[0]['kind'] ?? '') === 'batteri', 'payloadens kind är batteri');
    spec_assert(($batteryPosts[0]['natbolag'] ?? '') === 'E.ON', 'payloadens nätbolag är E.ON');
    spec_assert(($batteryPosts[0]['anlaggnings_id'] ?? '') === '735999114010135815', 'anläggnings-id följer med');
    spec_assert(($batteryPosts[0]['order_id'] ?? 0) === (int) $battery['reminder_id'], 'order_id är påminnelsens id');
    spec_assert(trim((string) (($sentBattery['steps']['foranmald'] ?? ''))) !== '', 'lyckad POST stämplar foranmald');
    spec_assert(jeab_ordrar_foranmalan_case_status($sentBattery) === 'skickad', 'lyckad POST sätter Skickad');
    spec_assert(spec_flow_done($sentBattery) === true, 'flödet är klart efter lyckad POST');
    spec_assert(spec_li_done(jeab_ordrar_stepper_html($sentBattery, 'jarfalla', (int) $sentBattery['reminder_id'], 'eon')) === true, 'steppern är is-done efter lyckad POST');
    spec_assert(spec_skickad_on(jeab_ordrar_eon_html($sentBattery, 'jarfalla', (int) $sentBattery['reminder_id'])) === true, 'Skickad tänds efter lyckad POST');
    spec_assert(!str_contains(jeab_ordrar_eon_html($sentBattery, 'jarfalla', (int) $sentBattery['reminder_id']), '>Nästa</button>'), 'Nästa byts mot Skicka igen efter lyckad POST');
    $sentActivity = array_column(is_array($sentBattery['activity'] ?? null) ? $sentBattery['activity'] : [], 'text');
    spec_assert(in_array('Föranmälan skickad', $sentActivity, true), 'aktivitet Föranmälan skickad skrivs');

    spec_reset();
    $solar = spec_put(['kind' => 'sol', 'natbolag' => 'Vattenfall', 'foranmalan_status' => 'A', 'kwh' => '8']);
    $sentSolar = jeab_ordrar_save_batterisol(spec_input($solar, ['kwh' => '8', 'natbolag' => 'Vattenfall']));
    $solarPosts = spec_of_type('ordrar.foranmalan');
    spec_assert(count($solarPosts) === 1, 'sol Vattenfall Nästa postar ordrar.foranmalan');
    spec_assert(($solarPosts[0]['kind'] ?? '') === 'sol', 'payloadens kind är sol');
    spec_assert(($solarPosts[0]['natbolag'] ?? '') === 'Vattenfall', 'payloadens nätbolag är Vattenfall');
    spec_assert(($solarPosts[0]['utkast'] ?? null) === false, 'Vattenfall Automatiskt har utkast false');
    spec_assert(jeab_ordrar_foranmalan_case_status($sentSolar) === 'skickad', 'Vattenfall sätter Skickad');
    spec_assert(spec_flow_done($sentSolar) === true, 'Vattenfall-flödet är klart');

    spec_reset();
    $savedOnly = spec_put(['kind' => 'batteri', 'natbolag' => 'E.ON', 'foranmalan_status' => 'A', 'steps' => ['bekraftad' => '2026-09-01']]);
    $saved = jeab_ordrar_save_batterisol(spec_input($savedOnly, ['intent' => '']));
    spec_assert(spec_of_type('ordrar.foranmalan') === [], 'Spara utan Nästa postar inte');
    spec_assert(trim((string) (($saved['steps']['spec'] ?? ''))) !== '', 'Spara stämplar spec');
    spec_assert(trim((string) (($saved['steps']['foranmald'] ?? ''))) === '', 'Spara stämplar inte foranmald');
    spec_assert(spec_flow_done($saved) === false, 'Spara gör inte Automatiskt klart');
    spec_assert(jeab_ordrar_foranmalan_case_status($saved) !== 'skickad', 'Spara sätter inte Skickad');

    spec_reset();
    $manual = spec_put(['kind' => 'batteri', 'natbolag' => 'E.ON', 'foranmalan_status' => 'C', 'anlaggnings_id' => '']);
    $manualSaved = jeab_ordrar_save_batterisol(spec_input($manual, ['foranmalan_status' => 'C', 'anlaggnings_id' => '']));
    spec_assert(spec_of_type('ordrar.foranmalan') === [], 'Manuellt/ingen från spec postar inte ordrar.foranmalan');
    spec_assert(spec_flow_done($manualSaved) === true, 'Manuellt/ingen kan vara klart utan Bokmalen-POST');
    spec_assert(jeab_ordrar_foranmalan_case_status($manualSaved) !== 'skickad', 'Manuellt/ingen sätter inte Skickad');

    spec_reset();
    $gap = spec_put(['kind' => 'sol', 'natbolag' => 'E.ON', 'foranmalan_status' => 'A', 'anlaggnings_id' => '', 'kwh' => '10']);
    $gapThrew = false;
    try {
        jeab_ordrar_save_batterisol(spec_input($gap, ['anlaggnings_id' => '']));
    } catch (InvalidArgumentException $e) {
        $gapThrew = str_contains($e->getMessage(), 'anläggnings-id') || str_contains($e->getMessage(), 'Saknas för Bokmalen');
    }
    $gapNow = spec_reload($gap);
    spec_assert($gapThrew, 'E.ON utan anläggnings-id stoppas');
    spec_assert(spec_of_type('ordrar.foranmalan') === [], 'ofullständig E.ON postar inte');
    spec_assert(trim((string) (($gapNow['steps']['foranmald'] ?? ''))) === '', 'ofullständig E.ON stämplar inte foranmald');
    spec_assert(spec_flow_done($gapNow) === false, 'ofullständig E.ON lämnar steget oklart');

    spec_reset();
    $ellevio = spec_put([
        'kind' => 'batteri',
        'natbolag' => 'Ellevio',
        'foranmalan_status' => 'A',
        'anlaggnings_id' => '',
        'kwh' => '12',
    ]);
    $started = jeab_ordrar_save_batterisol(spec_input($ellevio, ['natbolag' => 'Ellevio', 'anlaggnings_id' => '']));
    $token = trim((string) ($started['fullmakt_token'] ?? ''));
    $tokens[] = $token;
    spec_assert(count(spec_of_type('fullmakt.start')) === 1, 'Ellevio Automatiskt Nästa postar fullmakt.start');
    spec_assert(spec_of_type('ordrar.foranmalan') === [], 'Ellevio Automatiskt postar inte ordrar.foranmalan');
    spec_assert(trim((string) ($started['fullmakt_start_at'] ?? '')) !== '', 'Ellevio stämplar fullmakt_start_at');
    spec_assert(spec_flow_done($started) === true, 'Ellevio-steget är klart när fullmakten startat');
    spec_assert($GLOBALS['SPEC_MAILS'] === [], 'spec-Nästa mejlar inte fullmakten själv');
} finally {
    foreach ($tokens as $token) {
        spec_cleanup_token($token);
    }
}

echo "spec föranmälan post: ok\n";
