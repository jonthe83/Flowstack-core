<?php

declare(strict_types=1);

/**
 * Nästa på Föranmälan (eon):
 * Ellevio postar fullmakt.start till Bokmalen och mejlar inte själv.
 * E.ON/Vattenfall postar ordrar.foranmalan med utkast:false.
 * Manuell/ingen (C) undantar anläggnings-id. Beställ startar inte fullmakt.
 */

$tmp = sys_get_temp_dir() . '/jeab-foranmalan-nasta-' . bin2hex(random_bytes(4));
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
$GLOBALS['NASTA_MAILS'] = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['NASTA_MAILS'][] = $opts;
    return ['ok' => true, 'path' => 'helpscout', 'error' => null, 'id' => 'hs-nasta'];
};

require dirname(__DIR__) . '/src/bootstrap.php';

function nasta_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function nasta_secret(string $which, string $url, string $key): void
{
    $path = $which === 'fullmakt'
        ? jeab_bokmalen_ellevio_fullmakt_webhook_secret_path()
        : jeab_bokmalen_ordrar_webhook_secret_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode(['url' => $url, 'key' => $key], JSON_UNESCAPED_SLASHES));
}

function nasta_reset(): void
{
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
    $GLOBALS['NASTA_MAILS'] = [];
}

/** @return list<array<string,mixed>> */
function nasta_captured(): array
{
    $rows = $GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? [];
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

/** @return list<array<string,mixed>> */
function nasta_of_type(string $type): array
{
    $out = [];
    foreach (nasta_captured() as $row) {
        if (($row['type'] ?? '') === $type) {
            $out[] = $row;
        }
    }
    return $out;
}

function nasta_cleanup_token(string $token): void
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

function nasta_put(array $extra): array
{
    static $seq = 7200;
    $seq++;
    $row = array_merge([
        'reminder_id' => $seq,
        'kind' => 'sol',
        'company' => 'jarfalla',
        'natbolag' => 'Ellevio',
        'huvudsokande' => 'Ada Adolfsson',
        'name' => 'Ada Adolfsson',
        'personnummer' => '19700101-0101',
        'phone' => '0701234567',
        'email' => 'ada@example.com',
        'huvudsokande_address' => 'Storgatan 1, 176 74 Järfälla',
        'anlaggnings_id' => '',
        'kwh' => '12',
        'foranmalan_status' => 'A',
    ], $extra);
    return jeab_purchase_put($row);
}

function nasta_body(string $src, string $fn, string $next): string
{
    $start = strpos($src, 'function ' . $fn);
    $end = $next !== '' ? strpos($src, 'function ' . $next, $start === false ? 0 : $start + 1) : false;
    if ($start === false) {
        return '';
    }
    if ($end === false) {
        return substr($src, $start);
    }
    return substr($src, $start, $end - $start);
}

nasta_secret('fullmakt', 'https://bokmalen.example.invalid/hooks/jeab-ellevio-fullmakt', 'fullmakt-test-key');
nasta_secret('ordrar', 'https://bokmalen.example.invalid/hooks/jeab-ordrar', 'ordrar-test-key');

$tokens = [];
try {
    nasta_reset();
    $ellevio = nasta_put(['anlaggnings_id' => '']);
    $started = jeab_ordrar_foranmalan_nasta(null, $ellevio);
    $token = trim((string) ($started['fullmakt_token'] ?? ''));
    $tokens[] = $token;
    $starts = nasta_of_type('fullmakt.start');
    nasta_assert(count($starts) === 1, 'Ellevio Nästa postar ett fullmakt.start, fick ' . count($starts));
    nasta_assert(nasta_of_type('ordrar.foranmalan') === [], 'Ellevio Nästa postar inte ordrar.foranmalan');
    nasta_assert(nasta_of_type('fullmakt.signed') === [], 'starten är inte en signering');
    nasta_assert($GLOBALS['NASTA_MAILS'] === [], 'Ordrar mejlar inte fullmakten själv');
    nasta_assert(empty($started['fullmakt_mailed']), 'fullmakt_mailed sätts inte vid Bokmalen-start');
    nasta_assert(trim((string) ($started['fullmakt_start_at'] ?? '')) !== '', 'starten stämplas på ordern');
    nasta_assert(($started['fullmakt_channel'] ?? '') === 'bokmalen', 'kanalen är bokmalen');
    nasta_assert($token !== '', 'väntande dokument finns så kunden kan signera');
    $payload = $starts[0];
    nasta_assert(($payload['source'] ?? '') === 'jeab', 'källan är jeab');
    nasta_assert(($payload['order_id'] ?? '') === (string) ($ellevio['reminder_id'] ?? ''), 'order_id är påminnelsens id');
    nasta_assert(($payload['natbolag'] ?? '') === 'Ellevio', 'nätbolag är Ellevio');
    nasta_assert(($payload['anlaggnings_id'] ?? 'x') === '', 'tomt anläggnings-id stoppar inte starten');
    nasta_assert(($payload['customer_name'] ?? '') === 'Ada Adolfsson', 'namn följer med');
    nasta_assert(($payload['postnummer'] ?? '') === '176 74', 'postnummer följer med');
    nasta_assert(($payload['token'] ?? '') === $token, 'token pekar på dokumentet');
    nasta_assert(
        ($payload['sign_url'] ?? '') === 'https://flowstack.example.test/fullmakt/s/' . rawurlencode($token),
        'sign_url är kundens signeringslänk'
    );
    nasta_assert(($payload['epost'] ?? '') === 'ada@example.com', 'e-post följer med så Bokmalen kan kontakta kunden');
    nasta_assert(is_array($payload['parter']['huvudsokande'] ?? null), 'huvudsökande ingår i parter');

    nasta_reset();
    $again = jeab_ordrar_foranmalan_nasta(null, jeab_purchase_get((int) $started['reminder_id']) ?: $started);
    nasta_assert(nasta_captured() === [], 'Nästa en gång till startar inte en ny fullmakt');
    nasta_assert((string) ($again['fullmakt_token'] ?? '') === $token, 'samma dokument ligger kvar');

    $blocked = false;
    try {
        fullmakt_mark_signed($token, fullmakt_load_doc($token) ?: [], '%PDF-1.4', [
            'stamp_method' => 'ellevio',
            'anlaggnings_id' => '',
        ]);
    } catch (RuntimeException $e) {
        $blocked = str_contains($e->getMessage(), 'Fyll i anläggnings-id');
    }
    nasta_assert($blocked, 'kunden måste fortfarande fylla i 18 siffror vid signering');

    $okId = '735999114010135815';
    nasta_reset();
    $signed = fullmakt_mark_signed($token, fullmakt_load_doc($token) ?: [], '%PDF-1.4 signerad', [
        'stamp_method' => 'ellevio',
        'anlaggnings_id' => '7359 9911 4010 135815',
    ]);
    nasta_assert(($signed['status'] ?? '') === 'signed', '18 siffror släpper igenom signering');
    $signedPosts = nasta_of_type('fullmakt.signed');
    nasta_assert(count($signedPosts) === 1 && ($signedPosts[0]['anlaggnings_id'] ?? '') === $okId, 'fullmakt.signed går fortfarande');
    $afterSign = jeab_purchase_get((int) $started['reminder_id']);
    nasta_assert(is_array($afterSign) && ($afterSign['anlaggnings_id'] ?? '') === $okId, 'signerat id skrivs på ordern i mark_signed');
    nasta_assert(!empty($afterSign['fullmakt_signed']), 'ordern markeras signerad direkt');

    nasta_reset();
    $noZip = nasta_put([
        'huvudsokande_address' => 'Testgatan 1, Teststad',
        'postnummer' => '',
        'postnr' => '',
        'ort' => 'Teststad',
        'gata' => 'Testgatan 1',
    ]);
    $zipThrew = false;
    try {
        jeab_ordrar_foranmalan_nasta(null, $noZip);
    } catch (InvalidArgumentException $e) {
        $zipThrew = str_contains($e->getMessage(), 'postnummer');
    }
    nasta_assert($zipThrew, 'utan postnummer startas ingen fullmakt');
    nasta_assert(nasta_of_type('fullmakt.start') === [], 'saknat postnummer ger ingen webhook');

    nasta_reset();
    $eon = nasta_put([
        'natbolag' => 'E.ON',
        'anlaggnings_id' => '735999114010135815',
        'kwh' => '12',
        'foranmalan_status' => 'A',
    ]);
    $sentEon = jeab_ordrar_foranmalan_nasta(null, $eon);
    $foranPosts = nasta_of_type('ordrar.foranmalan');
    nasta_assert(count($foranPosts) === 1, 'E.ON Nästa postar ordrar.foranmalan');
    nasta_assert(($foranPosts[0]['utkast'] ?? null) === false, 'komplett E.ON har utkast false');
    nasta_assert(($foranPosts[0]['natbolag'] ?? '') === 'E.ON', 'payloadens nätbolag är E.ON');
    nasta_assert(($foranPosts[0]['anlaggnings_id'] ?? '') === '735999114010135815', 'anläggnings-id följer med');
    nasta_assert(nasta_of_type('fullmakt.start') === [], 'E.ON startar inte Ellevio-fullmakt');
    nasta_assert(!empty($sentEon['foranmalan_saved']), 'föranmälan markeras sparad');
    nasta_assert(trim((string) (($sentEon['steps']['foranmald'] ?? ''))) !== '', 'skickad föranmälan stämplas');

    nasta_reset();
    $eonGap = nasta_put([
        'natbolag' => 'E.ON',
        'anlaggnings_id' => '',
        'kwh' => '12',
        'foranmalan_status' => 'A',
    ]);
    $gapThrew = false;
    try {
        jeab_ordrar_foranmalan_nasta(null, $eonGap);
    } catch (InvalidArgumentException $e) {
        $gapThrew = str_contains($e->getMessage(), 'anläggnings-id') || str_contains($e->getMessage(), 'Saknas för Bokmalen');
    }
    nasta_assert($gapThrew, 'E.ON utan anläggnings-id stoppas');
    nasta_assert(nasta_captured() === [], 'ofullständig E.ON postar inte');

    nasta_reset();
    $manual = nasta_put([
        'natbolag' => 'Vattenfall',
        'anlaggnings_id' => '',
        'kwh' => '8',
        'foranmalan_status' => 'C',
    ]);
    $sentManual = jeab_ordrar_foranmalan_nasta(null, $manual);
    $manualPosts = nasta_of_type('ordrar.foranmalan');
    nasta_assert(count($manualPosts) === 1, 'Vattenfall Manuell/ingen postar föranmälan utan anläggnings-id');
    nasta_assert(($manualPosts[0]['utkast'] ?? null) === false, 'Manuell/ingen Nästa har utkast false');
    nasta_assert(($manualPosts[0]['anlaggnings_id'] ?? 'x') === '', 'tomt id följer med när C undantar kravet');
    nasta_assert(($manualPosts[0]['natbolag'] ?? '') === 'Vattenfall', 'sparat Vattenfall skrivs inte över vid Nästa');
    nasta_assert(($sentManual['natbolag'] ?? '') === 'Vattenfall', 'ordern behåller Vattenfall');
    nasta_assert(($sentManual['foranmalan_status'] ?? '') === 'C', 'C ligger kvar på ordern');
    nasta_assert(
        jeab_ordrar_foranmalan_chosen_natbolag([
            'natbolag' => 'Vattenfall',
            'huvudsokande_address' => 'Storgatan 1, 176 74 Järfälla',
            'company' => 'jarfalla',
        ]) === 'Vattenfall',
        'valt nätbolag går före adresslookup'
    );

    nasta_reset();
    $manualEon = nasta_put([
        'natbolag' => 'E.ON',
        'anlaggnings_id' => '',
        'kwh' => '',
        'foranmalan_status' => 'C',
    ]);
    $kwhThrew = false;
    try {
        jeab_ordrar_foranmalan_nasta(null, $manualEon);
    } catch (InvalidArgumentException $e) {
        $kwhThrew = str_contains($e->getMessage(), 'kWh');
    }
    nasta_assert($kwhThrew, 'Manuell/ingen undantar inte kWh');
    nasta_assert(nasta_captured() === [], 'C utan kWh postar inte');

    $ordrarSrc = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
    $indexSrc = (string) file_get_contents(dirname(__DIR__) . '/index.php');
    $bestallSteg = nasta_body($ordrarSrc, 'jeab_ordrar_save_bestall_steg', 'jeab_ordrar_save_pick');
    $bokaBestall = nasta_body($ordrarSrc, 'jeab_ordrar_save_boka_bestall', 'jeab_ordrar_is_booked');
    nasta_assert($bestallSteg !== '' && !str_contains($bestallSteg, 'jeab_ellevio_fullmakt_ensure'), 'Beställ-steget startar inte fullmakt');
    nasta_assert($bokaBestall !== '' && !str_contains($bokaBestall, 'jeab_ellevio_fullmakt_ensure'), 'Boka+Beställ startar inte fullmakt');
    $advanceAt = strpos($indexSrc, "case 'ordrar.advance':");
    $advanceEnd = strpos($indexSrc, "case 'ordrar.boka':", $advanceAt === false ? 0 : $advanceAt);
    $advance = $advanceAt !== false && $advanceEnd !== false ? substr($indexSrc, $advanceAt, $advanceEnd - $advanceAt) : '';
    nasta_assert(str_contains($advance, 'jeab_ordrar_foranmalan_nasta'), 'Föranmälan Nästa går via foranmalan_nasta');
    nasta_assert(str_contains($ordrarSrc, 'Nästa skickar föranmälan till Bokmalen.'), 'E.ON-rutan säger att Nästa skickar');
    nasta_assert(str_contains($ordrarSrc, 'Fullmakt till Ellevio startas hos Bokmalen när du trycker Nästa.'), 'Ellevio-rutan pekar på Nästa');
    nasta_assert(str_contains($ordrarSrc, 'name="action" value="ordrar.advance"') && str_contains($ordrarSrc, 'name="from" value="eon"'), 'Nästa-knappen på Föranmälan finns kvar');

    nasta_reset();
    nasta_assert(
        jeab_ordrar_anlaggnings_id_digits("735 999 114 009 241 688") === '735999114009241688',
        'mellanslag skalas till siffror'
    );
    nasta_assert(
        jeab_ordrar_anlaggnings_id_digits("735\u{00A0}999\t114") === '735999114',
        'alla blanksteg skalas av'
    );
    $spaced = nasta_put([
        'natbolag' => 'E.ON',
        'anlaggnings_id' => '735 999 114 009 241 688',
        'kwh' => '9',
        'foranmalan_status' => 'A',
        'vaxelriktare' => 'Sigenergy 12 kW TP2',
    ]);
    $display = $spaced;
    $display['huvudsokande_address'] = '';
    $display['address'] = '';
    $display['gata'] = '';
    $display['postnummer'] = '';
    $display['postnr'] = '';
    $display['ort'] = '';
    $specHtml = jeab_ordrar_spec_html($display, 'jarfalla', (int) $spaced['reminder_id']);
    nasta_assert(str_contains($specHtml, 'value="735999114009241688"'), 'anläggnings-id visas utan mellanslag');
    nasta_assert(str_contains($specHtml, 'data-js="anlaggnings-id"'), 'fältet kan normaliseras medan man skriver');
    $sentSpaced = jeab_ordrar_foranmalan_nasta(null, $spaced);
    $spacedPosts = nasta_of_type('ordrar.foranmalan');
    nasta_assert(count($spacedPosts) === 1, 'E.ON med mellanslag i id postar ordrar.foranmalan');
    nasta_assert(($spacedPosts[0]['anlaggnings_id'] ?? '') === '735999114009241688', 'webhooken får bara siffror');
    nasta_assert(($sentSpaced['anlaggnings_id'] ?? '') === '735999114009241688', 'ordern sparar id utan mellanslag');
    $pageJs = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
    nasta_assert(str_contains($pageJs, "replace(/\\D+/g, '')"), 'inmatningen tar bort allt utom siffror');

    nasta_reset();
    nasta_secret('ordrar', '', 'bad'); // ogiltig secret → notify null
    @unlink(jeab_bokmalen_ordrar_webhook_secret_path());
    $blocked = nasta_put([
        'natbolag' => 'E.ON',
        'anlaggnings_id' => '735999114009241688',
        'kwh' => '9',
        'foranmalan_status' => 'A',
    ]);
    $threw = false;
    try {
        jeab_ordrar_foranmalan_nasta(null, $blocked);
    } catch (InvalidArgumentException $e) {
        $threw = str_contains($e->getMessage(), 'Bokmalen');
    }
    nasta_assert($threw, 'saknad webhook-secret stoppar Nästa med fel');
    $reloaded = jeab_purchase_get((int) $blocked['reminder_id']);
    nasta_assert(trim((string) (($reloaded['steps']['foranmald'] ?? ''))) === '', 'misslyckad POST stämplar inte foranmald');
    nasta_assert(nasta_of_type('ordrar.foranmalan') === [], 'ingen payload fångas utan giltig secret');
    nasta_secret('ordrar', 'https://bokmalen.example.invalid/hooks/jeab-ordrar', 'ordrar-test-key');

} finally {
    foreach ($tokens as $token) {
        nasta_cleanup_token($token);
    }
}

echo "föranmälan nästa: ok\n";
