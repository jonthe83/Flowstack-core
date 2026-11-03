<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/store.php';
require_once dirname(__DIR__) . '/lib/mailer.php';
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

$token = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($body['token'] ?? '')) ?? '';
$doc = $token !== '' ? fullmakt_load_doc($token) : null;
if ($doc === null) {
    fullmakt_json(404, ['ok' => false, 'error' => 'not_found']);
}
if (($doc['status'] ?? '') === 'signed') {
    fullmakt_json(410, ['ok' => false, 'error' => 'already_signed']);
}

$url = fullmakt_sign_url($token);
$mailed = fullmakt_send_invite($doc, $url);
fullmakt_log('mail.log', 'invite-resend mailed=' . (int) $mailed);

fullmakt_json(200, [
    'ok' => true,
    'token' => $token,
    'url' => $url,
    'mailed' => $mailed,
]);
