<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/store.php';
require_once dirname(__DIR__) . '/lib/mailer.php';
require_once dirname(__DIR__) . '/lib/pdf.php';
require_once dirname(__DIR__) . '/lib/parties.php';

fullmakt_boot();
fullmakt_load_jeab();
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    fullmakt_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$key = (string) ($_SERVER['HTTP_X_FULLMAKT_KEY'] ?? '');
$expected = fullmakt_key();
if ($expected === null || $key === '' || !hash_equals($expected, $key)) {
    fullmakt_json(401, ['ok' => false, 'error' => 'unauthorized']);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw === false ? '' : $raw, true);
if (!is_array($body)) {
    fullmakt_json(400, ['ok' => false, 'error' => 'invalid_json']);
}

$trim = static function (string $key, int $max) use ($body): string {
    $v = trim((string) ($body[$key] ?? ''));
    $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
    return mb_substr($v, 0, $max);
};

$docType = $trim('type', 20);
if ($docType === '') {
    $docType = 'ellevio';
}
if (!in_array($docType, FULLMAKT_DOC_TYPES, true)) {
    fullmakt_json(400, ['ok' => false, 'error' => 'invalid_type']);
}

$namn = $trim('namn', 120);
$epost = $trim('epost', 120);
if ($namn === '' || $epost === '') {
    fullmakt_json(400, ['ok' => false, 'error' => 'missing_fields', 'fields' => array_values(array_filter([
        $namn === '' ? 'namn' : null,
        $epost === '' ? 'epost' : null,
    ]))]);
}
if (!filter_var($epost, FILTER_VALIDATE_EMAIL)) {
    fullmakt_json(400, ['ok' => false, 'error' => 'invalid_email']);
}

$fields = [
    'type' => $docType,
    'namn' => $namn,
    'epost' => $epost,
];

foreach (['title', 'lead', 'kicker', 'button', 'invite_subject', 'invite_body'] as $copyKey) {
    $v = $trim($copyKey, $copyKey === 'invite_body' || $copyKey === 'lead' ? 2000 : 180);
    if ($v !== '') {
        $fields[$copyKey] = $v;
    }
}

$staff = $trim('staff_email', 120);
if ($staff !== '') {
    if (!filter_var($staff, FILTER_VALIDATE_EMAIL)) {
        fullmakt_json(400, ['ok' => false, 'error' => 'invalid_staff_email']);
    }
    $fields['staff_email'] = $staff;
}

$fields['email_signer'] = !empty($body['email_signer']);

$pdfBytes = null;
$previewBytes = null;

if ($docType === 'overlay') {
    $pdfBytes = fullmakt_decode_b64_file((string) ($body['pdf'] ?? $body['pdf_b64'] ?? ''), 8_000_000);
    if ($pdfBytes === null || strncmp($pdfBytes, '%PDF', 4) !== 0) {
        fullmakt_json(400, ['ok' => false, 'error' => 'invalid_pdf']);
    }
    $previewRaw = (string) ($body['preview'] ?? $body['preview_b64'] ?? '');
    if ($previewRaw !== '') {
        $previewBytes = fullmakt_decode_b64_file($previewRaw, 6_000_000);
        if ($previewBytes === null || strncmp($previewBytes, "\x89PNG\r\n\x1a\n", 8) !== 0) {
            fullmakt_json(400, ['ok' => false, 'error' => 'invalid_preview']);
        }
    }
    $sig = [
        'x' => (float) ($body['sig_x'] ?? ($body['sig']['x'] ?? 28)),
        'y' => (float) ($body['sig_y'] ?? ($body['sig']['y'] ?? 198)),
        'w' => (float) ($body['sig_w'] ?? ($body['sig']['w'] ?? 70)),
        'h' => (float) ($body['sig_h'] ?? ($body['sig']['h'] ?? 16)),
    ];
    if ($sig['w'] <= 0 || $sig['h'] <= 0 || $sig['x'] < 0 || $sig['y'] < 0) {
        fullmakt_json(400, ['ok' => false, 'error' => 'invalid_sig_box']);
    }
    $fields['sig'] = $sig;
} else {
    $personnummer = $trim('personnummer', 20);
    $telefon = $trim('telefon', 30);
    $adress = $trim('adress', 160);
    $postnummer = $trim('postnummer', 12);
    $ort = $trim('ort', 60);
    $foretagsnamn = $trim('foretagsnamn', 120);
    $anlaggningsadress = $trim('anlaggningsadress', 160);
    $typ = $trim('typ', 20);
    if ($typ === '') {
        $typ = 'privatperson';
    }
    $missing = [];
    foreach (['personnummer' => $personnummer, 'telefon' => $telefon, 'adress' => $adress, 'postnummer' => $postnummer, 'ort' => $ort] as $field => $val) {
        if ($val === '') {
            $missing[] = $field;
        }
    }
    if ($missing !== []) {
        fullmakt_json(400, ['ok' => false, 'error' => 'missing_fields', 'fields' => $missing]);
    }
    if (!isset(FULLMAKT_TYPER[$typ])) {
        fullmakt_json(400, ['ok' => false, 'error' => 'invalid_typ']);
    }
    if ($anlaggningsadress === '') {
        $anlaggningsadress = $adress;
    }
    $aid = preg_replace('/\D+/', '', (string) ($body['anlaggnings_id'] ?? '')) ?? '';
    if ($aid !== '' && strlen($aid) !== 18) {
        fullmakt_json(400, ['ok' => false, 'error' => 'invalid_anlaggnings_id']);
    }
    $fields['typ'] = $typ;
    $fields['personnummer'] = $personnummer;
    $fields['telefon'] = $telefon;
    $fields['adress'] = $adress;
    $fields['postnummer'] = $postnummer;
    $fields['ort'] = $ort;
    $fields['foretagsnamn'] = $foretagsnamn;
    $fields['anlaggningsadress'] = $anlaggningsadress;
    $fields['anlaggnings_id'] = $aid;
}

$sendInvite = array_key_exists('send_invite', $body) ? (bool) $body['send_invite'] : true;

try {
    $doc = fullmakt_create_doc($fields, $pdfBytes, $previewBytes);
} catch (Throwable $e) {
    fullmakt_log('error.log', 'create ' . fullmakt_redact_log_line($e->getMessage(), '', ''));
    fullmakt_json(500, ['ok' => false, 'error' => 'store_failed']);
}

$url = fullmakt_sign_url($doc['token']);
$mailed = false;
if ($sendInvite) {
    $mailed = fullmakt_send_invite($doc, $url);
    fullmakt_log('mail.log', 'invite mailed=' . (int) $mailed);
}

fullmakt_json(200, [
    'ok' => true,
    'token' => $doc['token'],
    'url' => $url,
    'type' => $docType,
    'mailed' => $mailed,
]);
