<?php

declare(strict_types=1);

/**
 * Fullmaktens saknas-koll och fullmakt.start läser e-post och mobil
 * från orderns kontakt (samma vy som Objekt & läge, plus kontaktens
 * tre e-postfält och två mobilfält). Tomma orderfält är reserven.
 * Saknas båda blir toasten. Inget mejl och ingen webhook-POST.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-fullmakt-channel-' . bin2hex(random_bytes(4));
$private = $tmp . '/private';
mkdir($private . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $private;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $private;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
$GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
$GLOBALS['CHANNEL_MAILS'] = [];
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['CHANNEL_MAILS'][] = $opts;

    return ['ok' => true, 'path' => 'helpscout', 'error' => null, 'id' => 'hs-channel'];
};
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('JEAB_BUG_WEBHOOK_CAPTURE=1');
putenv('CONTACTS_SOLE');
putenv('CONTACTS_STRICT');
unset($_ENV['CONTACTS_SOLE'], $_ENV['CONTACTS_STRICT'], $GLOBALS['_JEAB_CONTACTS_STRICT']);
$_ENV['JEAB_BUG_WEBHOOK_CAPTURE'] = '1';
file_put_contents($private . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $private . '/app.sqlite',
        'json_path' => $private . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
    'incoming_webhook_token' => 'not-the-inbox-token',
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ch_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";

        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** @param array<string, mixed> $row */
function ch_contact(string $id, array $row): void
{
    $row['id'] = $id;
    $store = jeab_contact_store();
    ch_ok($store instanceof JeabPdoContactStore, 'kontaktlagret är SQL');
    $store->upsert($id, $row);
    if (function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget($id);
    }
}

/** @return array<string, mixed> */
function ch_order(int $id, string $contactId, string $email, string $phone): array
{
    return [
        'reminder_id' => $id,
        'id' => $id,
        'contact_id' => $contactId,
        'company' => 'jarfalla',
        'natbolag' => 'Ellevio',
        'kind' => 'batteri',
        'huvudsokande' => 'Kim Kontakt',
        'name' => 'Kim Kontakt',
        'personnummer' => '19700101-0101',
        'email' => $email,
        'phone' => $phone,
        'address' => 'Kontaktgatan 1, 176 74 Järfälla',
        'huvudsokande_address' => 'Kontaktgatan 1, 176 74 Järfälla',
        'fullmakt_start_at' => '2026-10-01 09:00:00',
        'fullmakt_token' => '',
    ];
}

function ch_reset(): void
{
    $GLOBALS['_JEAB_BUG_WEBHOOKS'] = [];
    $GLOBALS['CHANNEL_MAILS'] = [];
}

/** @return array<string, mixed>|null */
function ch_start_payload(): ?array
{
    $rows = $GLOBALS['_JEAB_BUG_WEBHOOKS'] ?? [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (is_array($row) && ($row['type'] ?? '') === 'fullmakt.start') {
            return $row;
        }
    }

    return null;
}

$secretPath = jeab_bokmalen_ellevio_fullmakt_webhook_secret_path();
$secretDir = dirname($secretPath);
if (!is_dir($secretDir)) {
    mkdir($secretDir, 0775, true);
}
file_put_contents($secretPath, json_encode([
    'url' => 'https://bokmalen.example.invalid/hooks/jeab-ellevio-fullmakt',
    'key' => 'fullmakt-test-key',
], JSON_UNESCAPED_SLASHES));

$store = jeab_store();
ch_ok($store instanceof JeabPdoStore, 'sqlite-lagret är öppet');
jeab_ensure_contacts_tables($store->pdo(), 'sqlite');

ch_contact('kt_fm_primary', [
    'name' => 'Kim Kontakt',
    'email' => 'kim.kontakt@example.test',
    'phone' => '0701000301',
    'email2' => '',
    'email3' => '',
    'phone2' => '',
    'street' => 'Kontaktgatan 1',
    'postal_code' => '17674',
    'city' => 'Järfälla',
    'owners' => [[
        'name' => 'Kim Kontakt',
        'email' => 'kim.kontakt@example.test',
        'phone' => '0701000301',
        'contact' => true,
    ]],
]);
ch_contact('kt_fm_slots', [
    'name' => 'Kim Kontakt',
    'email' => '',
    'phone' => '',
    'email2' => '',
    'email3' => 'kim.tre@example.test',
    'phone2' => '0701000302',
    'street' => 'Kontaktgatan 1',
    'postal_code' => '17674',
    'city' => 'Järfälla',
    'owners' => [[
        'name' => 'Kim Kontakt',
        'email' => '',
        'phone' => '',
        'contact' => true,
    ]],
]);
ch_contact('kt_fm_blank', [
    'name' => 'Kim Kontakt',
    'email' => '',
    'phone' => '',
    'email2' => '',
    'email3' => '',
    'phone2' => '',
    'owners' => [[
        'name' => 'Kim Kontakt',
        'email' => '',
        'phone' => '',
        'contact' => true,
    ]],
]);

$tokens = [];
try {
    ch_reset();
    $primary = ch_order(62, 'kt_fm_primary', '', '');
    $primaryMissing = jeab_ellevio_fullmakt_missing(jeab_ellevio_fullmakt_fields(jeab_ellevio_fullmakt_row_for_new($primary)));
    ch_ok($primaryMissing === [], 'kontaktens mobil och e-post räcker när orderfälten är tomma, fick: ' . implode(', ', $primaryMissing));
    $primaryStarted = jeab_ellevio_fullmakt_send_new($primary, null);
    $tokens[] = (string) ($primaryStarted['fullmakt_token'] ?? '');
    $primaryPayload = ch_start_payload();
    ch_ok(is_array($primaryPayload), 'fullmakt.start fångas utan POST');
    ch_ok(($primaryPayload['epost'] ?? '') === 'kim.kontakt@example.test', 'fullmakt.start får kontaktens e-post, fick: ' . (string) ($primaryPayload['epost'] ?? ''));
    ch_ok(($primaryPayload['telefon'] ?? '') === '+46701000301', 'fullmakt.start får kontaktens mobil, fick: ' . (string) ($primaryPayload['telefon'] ?? ''));
    ch_ok(($primaryPayload['invite_epost'] ?? '') === 'kim.kontakt@example.test', 'inbjudan använder kontaktens e-post');
    ch_ok($GLOBALS['CHANNEL_MAILS'] === [], 'fullmakt.start skickar inget mejl');

    ch_reset();
    $slots = ch_order(63, 'kt_fm_slots', '', '');
    $slotsMissing = jeab_ellevio_fullmakt_missing(jeab_ellevio_fullmakt_fields($slots));
    ch_ok($slotsMissing === [], 'e-post 3 och mobil 2 räcker när primärfälten är tomma, fick: ' . implode(', ', $slotsMissing));
    $slotsStarted = jeab_ellevio_fullmakt_send_new($slots, null);
    $tokens[] = (string) ($slotsStarted['fullmakt_token'] ?? '');
    $slotsPayload = ch_start_payload();
    ch_ok(is_array($slotsPayload) && ($slotsPayload['epost'] ?? '') === 'kim.tre@example.test' && ($slotsPayload['telefon'] ?? '') === '+46701000302', 'fullmakt.start får e-post 3 och mobil 2');
    ch_ok($GLOBALS['CHANNEL_MAILS'] === [], 'slot-fallet skickar inget mejl');

    ch_reset();
    $fallback = ch_order(64, 'kt_fm_blank', 'order.kvar@example.test', '0701000303');
    $fallbackStarted = jeab_ellevio_fullmakt_send_new($fallback, null);
    $tokens[] = (string) ($fallbackStarted['fullmakt_token'] ?? '');
    $fallbackPayload = ch_start_payload();
    ch_ok(is_array($fallbackPayload) && ($fallbackPayload['epost'] ?? '') === 'order.kvar@example.test' && ($fallbackPayload['telefon'] ?? '') === '0701000303', 'tom kontakt faller tillbaka på orderfälten');

    ch_reset();
    $empty = ch_order(65, 'kt_fm_blank', '', '');
    $toast = '';
    try {
        jeab_ellevio_fullmakt_send_new($empty, null);
    } catch (InvalidArgumentException $e) {
        $toast = $e->getMessage();
    }
    ch_ok(str_contains($toast, 'Saknas för fullmakt: telefon, e-post.'), 'utan mobil och e-post blir feltexten, fick: ' . $toast);
    $shown = function_exists('jeab_ordrar_save_fail_text') ? jeab_ordrar_save_fail_text($toast) : '';
    ch_ok($shown === 'Kunde inte spara. Saknas för fullmakt: telefon, e-post.', 'toasten är «Kunde inte spara» plus orsaken, fick: ' . $shown);
    ch_ok(ch_start_payload() === null && $GLOBALS['CHANNEL_MAILS'] === [], 'avslaget startar inget och skickar inget');

    $seom = $empty;
    $seom['natbolag'] = 'SEOM';
    $seom['contact_id'] = 'kt_fm_primary';
    $seom['email'] = '';
    $seom['phone'] = '';
    $seomFields = jeab_seom_fullmakt_fields($seom);
    $seomKund = is_array($seomFields['kund'] ?? null) ? $seomFields['kund'] : [];
    ch_ok(($seomKund['mejl'] ?? '') === 'kim.kontakt@example.test' && ($seomKund['telefon'] ?? '') === '+46701000301', 'SEOM-blanketten läser samma kontakt när orderfälten är tomma');
} finally {
    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token === '' || !function_exists('fullmakt_pending_path')) {
            continue;
        }
        foreach ([
            fullmakt_pending_path($token),
            function_exists('fullmakt_signed_json_path') ? fullmakt_signed_json_path($token) : '',
            function_exists('fullmakt_signed_pdf_path') ? fullmakt_signed_pdf_path($token) : '',
        ] as $path) {
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }
}

echo "ellevio fullmakt contact channel: ok\n";
