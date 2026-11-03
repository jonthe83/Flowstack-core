<?php

declare(strict_types=1);

/**
 * Ellevio-fullmakt postar fullmakt.signed bara med 18-siffrigt anläggnings-id.
 * Overlay och för kort id postar inte. Saknad secret och misslyckad POST
 * syns i felloggen utan url eller nyckel, och signeringen står kvar.
 */

$tmp = sys_get_temp_dir() . '/jeab-bokmalen-ellevio-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempdir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/fullmakt/lib/bootstrap.php';
require dirname(__DIR__) . '/fullmakt/lib/store.php';
fullmakt_boot();

function elf_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function elf_secret(string $url, string $key): void
{
    $path = jeab_bokmalen_ellevio_fullmakt_webhook_secret_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, json_encode(['url' => $url, 'key' => $key], JSON_UNESCAPED_SLASHES));
}

function elf_clear_secret(): void
{
    $path = jeab_bokmalen_ellevio_fullmakt_webhook_secret_path();
    if (is_file($path)) {
        unlink($path);
    }
}

function elf_log(): string
{
    $path = jeab_app_errors_path();
    return is_file($path) ? (string) file_get_contents($path) : '';
}

function elf_reset_capture(): void
{
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
}

/** @return list<array<string,mixed>> */
function elf_captured(): array
{
    $rows = $GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? [];
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function elf_cleanup(string $token): void
{
    if ($token === '') {
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

function elf_order(string $token, array $extra = []): void
{
    $row = array_merge([
        'reminder_id' => 4812,
        'id' => 999001,
        'fullmakt_token' => $token,
        'kind' => 'sol',
        'natbolag' => 'Ellevio',
        'name' => 'Order Kund',
        'huvudsokande' => 'Order Kund',
        'phone' => '0701112233',
        'email' => 'order@example.com',
        'address' => 'Ordergatan 2, 176 74 Järfälla',
        'vaxelriktare' => 'Sigenergy 12 kW TP2',
        'kwh' => '10',
        'kalkyl' => ['b3' => 3, 'b15' => 1],
        'sakring_amp' => '16 A',
    ], $extra);
    jeab_purchase_put($row);
}

$okId = '735999114010135815';
$leakKey = 'do-not-leak-ellevio-fullmakt-key';
$base = [
    'namn' => 'Test Testsson',
    'personnummer' => '19700101-0101',
    'telefon' => '0700000000',
    'epost' => 'kund@example.com',
    'adress' => 'Björkvägen 4',
    'postnummer' => '181 30',
    'ort' => 'Lidingö',
    'typ' => 'privatperson',
];

$overlayDoc = [
    'token' => 'overlay-token',
    'type' => 'overlay',
    'stamp_method' => 'fpdi',
    'anlaggnings_id' => $okId,
    'namn' => 'Ann Overlay',
];
elf_assert(jeab_bokmalen_ellevio_fullmakt_payload($overlayDoc) === null, 'overlay ger ingen payload');

$shortDoc = [
    'token' => 'short-token',
    'type' => 'ellevio',
    'stamp_method' => 'ellevio',
    'anlaggnings_id' => '735999',
    'namn' => 'Kort Id',
];
elf_assert(jeab_bokmalen_ellevio_fullmakt_payload($shortDoc) === null, '17 siffror ger ingen payload');
elf_assert(jeab_bokmalen_ellevio_fullmakt_anlaggnings_id(['anlaggnings_id' => '7359 9911 4010 135815']) === $okId, 'mellanslag skalas av till 18 siffror');

$tokens = [];
try {
    elf_secret('https://bokmalen.example.invalid/hooks/jeab-ellevio-fullmakt', $leakKey);

    $pending = fullmakt_create_doc($base);
    $token = (string) $pending['token'];
    $tokens[] = $token;
    elf_order($token);
    elf_reset_capture();
    $signed = fullmakt_mark_signed($token, $pending, '%PDF-1.4 signerad', [
        'stamp_method' => 'ellevio',
        'anlaggnings_id' => '7359 9911 4010 135815',
    ]);
    elf_assert(($signed['status'] ?? '') === 'signed', 'Ellevio med 18 siffror signeras');
    elf_assert(is_file(fullmakt_signed_pdf_path($token)), 'signerad PDF finns på disk');
    $posted = elf_captured();
    elf_assert(count($posted) === 1, 'Ellevio med 18 siffror fångar en payload, fick ' . count($posted));
    $payload = $posted[0];
    elf_assert(($payload['type'] ?? '') === 'fullmakt.signed', 'payload type är fullmakt.signed');
    elf_assert(($payload['order_id'] ?? '') === '4812', 'order_id är Ordrar-numret, inte probe-id');
    elf_assert(($payload['order_id'] ?? '') !== '999001', 'probe-id används inte som order_id');
    elf_assert(($payload['token'] ?? '') === $token, 'payload har fullmakt-token');
    elf_assert(($payload['anlaggnings_id'] ?? '') === $okId, 'anlaggnings_id är 18 siffror');
    elf_assert(($payload['customer_name'] ?? '') === 'Test Testsson', 'namn kommer från fullmakten');
    elf_assert(($payload['address'] ?? '') === 'Björkvägen 4', 'adress kommer från fullmakten');
    elf_assert(($payload['postnummer'] ?? '') === '181 30', 'postnummer kommer från fullmakten');
    elf_assert(($payload['ort'] ?? '') === 'Lidingö', 'ort kommer från fullmakten');
    elf_assert(($payload['telefon'] ?? '') === '0700000000', 'telefon kommer från fullmakten');
    elf_assert(($payload['epost'] ?? '') === 'kund@example.com', 'epost kommer från fullmakten');
    elf_assert(($payload['personnummer'] ?? '') === '19700101-0101', 'personnummer kommer från fullmakten');
    elf_assert(
        ($payload['pdf_url'] ?? '') === 'https://flowstack.example.test/fullmakt/pdf.php?token=' . rawurlencode($token),
        'pdf_url pekar på hämtbar signerad PDF'
    );
    elf_assert(($payload['vaxelriktare_kw'] ?? '') === '12', 'växelriktarens kW läses från ordern');
    elf_assert(($payload['batteri_kwh'] ?? '') === '10', 'batteri_kwh läses från orderns kWh');
    elf_assert(($payload['batteri_antal'] ?? '') === '3', 'batteri_antal läses från kalkylens moduler');
    elf_assert(($payload['sakring_amp'] ?? '') === '16', 'säkring läses från ordern när den finns');
    $storedOrder = jeab_purchase_get(4812);
    elf_assert(is_array($storedOrder) && ($storedOrder['anlaggnings_id'] ?? '') === $okId, 'signering skriver anläggnings-id på ordern direkt');
    elf_assert(!empty($storedOrder['fullmakt_signed']), 'signering markerar ordern som signerad');
    elf_assert(!str_contains(elf_log(), $leakKey), 'lyckad capture skriver inte nyckeln i felloggen');
    elf_assert(!str_contains(elf_log(), 'example.invalid'), 'lyckad capture skriver inte webhook-url i felloggen');

    $bare = fullmakt_create_doc(array_merge($base, ['adress' => '', 'telefon' => '', 'epost' => '']));
    $bareToken = (string) $bare['token'];
    $tokens[] = $bareToken;
    elf_order($bareToken, [
        'reminder_id' => 77,
        'vaxelriktare' => '',
        'kwh' => '',
        'kalkyl' => [],
        'sakring_amp' => '',
        'phone' => '0733333333',
        'email' => 'fallback@example.com',
        'address' => 'Fallbackgatan 9',
    ]);
    elf_reset_capture();
    fullmakt_mark_signed($bareToken, $bare, '%PDF-1.4', [
        'stamp_method' => 'ellevio',
        'anlaggnings_id' => $okId,
    ]);
    $fallback = elf_captured();
    elf_assert(count($fallback) === 1, 'fält som saknas på fullmakten fylls från ordern');
    elf_assert(($fallback[0]['telefon'] ?? '') === '0733333333', 'telefon faller tillbaka på ordern');
    elf_assert(($fallback[0]['epost'] ?? '') === 'fallback@example.com', 'epost faller tillbaka på ordern');
    elf_assert(($fallback[0]['address'] ?? '') === 'Fallbackgatan 9', 'adress faller tillbaka på ordern');
    elf_assert(($fallback[0]['vaxelriktare_kw'] ?? 'x') === '', 'tom växelriktare ger tom kW');
    elf_assert(($fallback[0]['batteri_kwh'] ?? 'x') === '', 'tom kWh ger tom batteri_kwh');
    elf_assert(($fallback[0]['batteri_antal'] ?? 'x') === '', 'saknat batteriantal ger tom sträng');
    elf_assert(($fallback[0]['sakring_amp'] ?? 'x') === '', 'saknad säkring ger tom sträng');
    elf_assert(($fallback[0]['order_id'] ?? '') === '77', 'order_id följer den kopplade ordern');
    $frozenOrder = [
        '_party_frozen' => 'fullmakt',
        'name' => 'Order Kund',
        'huvudsokande' => 'Order Kund',
        'fastighetsbeteckning' => 'Lidingo 1:2',
        'personnummer' => '19700101-0101',
    ];
    $signedWins = jeab_bokmalen_ellevio_fullmakt_customer_name(['namn' => 'Test Testsson'], $frozenOrder);
    elf_assert($signedWins === 'Test Testsson', 'namn på fullmakten vinner över ordern');
    $nameBack = jeab_bokmalen_ellevio_fullmakt_customer_name(['namn' => ''], $frozenOrder);
    elf_assert($nameBack === 'Order Kund', 'saknat namn på fullmakten faller tillbaka på ordern');
    $fastBack = jeab_bokmalen_ellevio_fullmakt_fastighet(['fastighetsbeteckning' => ''], $frozenOrder);
    elf_assert($fastBack === 'Lidingo 1:2', 'saknad fastighet på fullmakten faller tillbaka på ordern');
    $pnrSigned = jeab_bokmalen_ellevio_fullmakt_pnr(['personnummer' => '19800101-0101'], $frozenOrder);
    $pnrBack = jeab_bokmalen_ellevio_fullmakt_pnr(['personnummer' => ''], $frozenOrder);
    elf_assert($pnrSigned !== '' && $pnrBack !== '' && $pnrSigned !== $pnrBack, 'saknat personnummer på fullmakten faller tillbaka på ordern');

    $overlay = fullmakt_create_doc($base + ['type' => 'overlay']);
    $overlayToken = (string) $overlay['token'];
    $tokens[] = $overlayToken;
    elf_order($overlayToken);
    elf_reset_capture();
    $signedOverlay = fullmakt_mark_signed($overlayToken, $overlay, '%PDF-1.4 overlay', ['stamp_method' => 'fpdi']);
    elf_assert(($signedOverlay['status'] ?? '') === 'signed', 'overlay kan fortfarande signeras');
    elf_assert(elf_captured() === [], 'overlay postar inte');

    elf_clear_secret();
    $noSecret = fullmakt_create_doc($base);
    $noSecretToken = (string) $noSecret['token'];
    $tokens[] = $noSecretToken;
    elf_order($noSecretToken);
    elf_reset_capture();
    $before = elf_log();
    $signedWithoutSecret = fullmakt_mark_signed($noSecretToken, $noSecret, '%PDF-1.4', [
        'stamp_method' => 'ellevio',
        'anlaggnings_id' => $okId,
    ]);
    elf_assert(($signedWithoutSecret['status'] ?? '') === 'signed', 'signering lyckas utan webhook-secret');
    elf_assert(elf_captured() === [], 'utan secret blir det ingen POST');
    $missingLog = elf_log();
    elf_assert(str_contains($missingLog, 'secret saknas'), 'saknad secret loggas i felloggen');
    elf_assert(str_contains($missingLog, 'Ellevio-fullmakt-webhook'), 'felloggen nämner fullmaktswebhooken');
    elf_assert(!str_contains($missingLog, 'http://') && !str_contains($missingLog, 'https://'), 'felloggen innehåller ingen url');
    elf_assert(!str_contains($missingLog, $leakKey), 'nyckeln syns inte när secret saknas');
    elf_assert(strlen($missingLog) > strlen($before), 'saknad secret lägger en ny rad');

    elf_secret('https://127.0.0.1:1/hooks/jeab-ellevio-fullmakt', $leakKey);
    putenv('JEAB_BUG_WEBHOOK_CAPTURE');
    unset($_ENV['JEAB_BUG_WEBHOOK_CAPTURE']);
    $failed = fullmakt_create_doc($base);
    $failedToken = (string) $failed['token'];
    $tokens[] = $failedToken;
    elf_order($failedToken, ['reminder_id' => 88]);
    elf_reset_capture();
    $signedFailedPost = fullmakt_mark_signed($failedToken, $failed, '%PDF-1.4', [
        'stamp_method' => 'ellevio',
        'anlaggnings_id' => $okId,
    ]);
    elf_assert(($signedFailedPost['status'] ?? '') === 'signed', 'signering lyckas när POST fallerar');
    elf_assert(is_file(fullmakt_signed_pdf_path($failedToken)), 'PDF ligger kvar när POST fallerar');
    elf_assert((fullmakt_load_doc($failedToken)['status'] ?? '') === 'signed', 'dokumentet är signerat efter webhook-fel');
    $failLog = elf_log();
    elf_assert(str_contains($failLog, 'POST misslyckades'), 'misslyckad POST loggas');
    elf_assert(!str_contains($failLog, $leakKey), 'nyckeln syns inte i felloggen');
    elf_assert(!str_contains($failLog, '127.0.0.1'), 'webhook-url syns inte i felloggen');
    elf_assert(elf_captured() === [], 'avstängd capture sparar ingen payload vid nätverksfel');

    putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
    $_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
    jeab_bokmalen_ellevio_fullmakt_notify_fail('fel https://api2.cursor.sh/automations/webhook/secret Bearer ' . $leakKey);
    $scrub = elf_log();
    elf_assert(str_contains($scrub, '[url]'), 'url i feltext maskeras');
    elf_assert(str_contains($scrub, 'Bearer [nyckel]'), 'bearer i feltext maskeras');
    elf_assert(!str_contains($scrub, 'api2.cursor.sh'), 'api-host läcker inte till felloggen');
    elf_assert(!str_contains($scrub, $leakKey), 'nyckel läcker inte via feltext');

    $storeSrc = (string) file_get_contents(dirname(__DIR__) . '/fullmakt/lib/store.php');
    $markAt = strpos($storeSrc, 'function fullmakt_mark_signed');
    $markBody = $markAt !== false ? substr($storeSrc, $markAt) : '';
    $assertAt = strpos($markBody, 'fullmakt_assert_ellevio_anlaggnings_id');
    $hookAt = strpos($markBody, 'fullmakt_bokmalen_ellevio_after_signed($signed)');
    elf_assert($assertAt !== false && $hookAt !== false && $hookAt > $assertAt, 'webhooken anropas efter anläggnings-id-kravet');
    $afterAt = strpos($storeSrc, 'function fullmakt_bokmalen_ellevio_after_signed');
    $afterBody = $afterAt !== false ? substr($storeSrc, $afterAt, 900) : '';
    $applyAt = strpos($afterBody, 'jeab_bokmalen_ellevio_fullmakt_apply_signed_order($signed)');
    $notifyAt = strpos($afterBody, 'jeab_bokmalen_ellevio_fullmakt_notify($signed)');
    elf_assert($applyAt !== false && $notifyAt !== false && $applyAt < $notifyAt, 'anläggnings-id skrivs på ordern innan fullmakt.signed');
} finally {
    foreach ($tokens as $token) {
        elf_cleanup($token);
    }
}

echo "bokmalen ellevio fullmakt webhook: ok\n";
