<?php

declare(strict_types=1);

require_once __DIR__ . '/src/deploy_guard.php';
jeab_deploy_guard();

require_once __DIR__ . '/src/shield.php';
require_once __DIR__ . '/src/bootstrap.php';
if (function_exists('jeab_demo_mode') && jeab_demo_mode()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Finns inte i demon.\n";
    exit;
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

try {
    jeab_boot_session();
    jeab_gate();
    jeab_boot_store();
} catch (Throwable $e) {
    $busy = $e instanceof JeabDbBusyException || (function_exists('jeab_pdo_is_max_user_connections') && jeab_pdo_is_max_user_connections($e));
    http_response_code($busy ? 503 : 500);
    header('Content-Type: text/html; charset=utf-8');
    $public = function_exists('jeab_public_error_text') ? jeab_public_error_text($e) : 'Något gick fel.';
    echo '<!doctype html><meta charset="utf-8"><title>JEAB</title><p>' . htmlspecialchars($public, ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

$solkampanjAdmin = function_exists('jeab_is_admin') && jeab_is_admin();
if (!function_exists('jeab_solkampanj_may_use') || !jeab_solkampanj_may_use()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Solkampanj</title><p>Inte behörig.</p>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    jeab_require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'solkampanj.send') {
            $result = jeab_solkampanj_send($_POST, jeab_solkampanj_actor());
            jeab_json($result, !empty($result['ok']) ? 200 : 400);
        }
        if ($action === 'solkampanj.send_battery') {
            $result = jeab_solkampanj_send_battery($_POST, jeab_solkampanj_actor());
            jeab_json($result, !empty($result['ok']) ? 200 : 400);
        }
        if ($action === 'solkampanj.skip') {
            $result = jeab_solkampanj_skip($_POST, jeab_solkampanj_actor());
            jeab_json($result, !empty($result['ok']) ? 200 : 400);
        }
        if ($action === 'solkampanj.ort') {
            $result = jeab_solkampanj_remember_ort((string) ($_POST['ort'] ?? ''));
            if (jeab_wants_json()) {
                jeab_json($result, !empty($result['ok']) ? 200 : 422);
            }
            if (empty($result['ok'])) {
                jeab_redirect(jeab_url('solkampanj.php?fel=' . rawurlencode((string) ($result['error'] ?? 'Orten finns inte.'))));
            }
            jeab_redirect(jeab_url('solkampanj.php'));
        }
        if ($action === 'solkampanj.settings') {
            if (!$solkampanjAdmin) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'error' => 'Inte behörig.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $result = jeab_solkampanj_apply_settings($_POST, $_FILES);
            if (empty($result['ok'])) {
                jeab_redirect(jeab_url('solkampanj.php?fel=' . rawurlencode((string) ($result['error'] ?? 'Kunde inte spara.'))));
            }
            jeab_redirect(jeab_url('solkampanj.php?sparat=1'));
        }
        jeab_json(['ok' => false, 'error' => 'Okänd åtgärd.'], 400);
    } catch (InvalidArgumentException $e) {
        if (jeab_wants_json() || $action === 'solkampanj.send' || $action === 'solkampanj.send_battery' || $action === 'solkampanj.skip' || $action === 'solkampanj.ort') {
            jeab_json(['ok' => false, 'error' => $e->getMessage(), 'ort' => (string) (jeab_solkampanj_active_ort() ?? ''), 'towns' => jeab_solkampanj_towns_public(), 'progress' => jeab_solkampanj_progress(), 'next' => jeab_solkampanj_public_row(jeab_solkampanj_next())], 422);
        }
        jeab_redirect(jeab_url('solkampanj.php?fel=' . rawurlencode($e->getMessage())));
    } catch (Throwable $e) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('solkampanj', $e->getMessage());
        }
        $public = function_exists('jeab_public_error_text') ? jeab_public_error_text($e) : 'Något gick fel.';
        if (jeab_wants_json() || $action === 'solkampanj.send' || $action === 'solkampanj.send_battery' || $action === 'solkampanj.skip' || $action === 'solkampanj.ort') {
            jeab_json(['ok' => false, 'error' => $public], 500);
        }
        jeab_redirect(jeab_url('solkampanj.php?fel=' . rawurlencode($public)));
    }
}

$solkampanjTowns = jeab_solkampanj_towns_public();
$solkampanjOrt = (string) (jeab_solkampanj_active_ort() ?? '');
$solkampanjRow = jeab_solkampanj_public_row(jeab_solkampanj_next());
$solkampanjProgress = jeab_solkampanj_progress();
$solkampanjTest = jeab_solkampanj_test_mode();
$solkampanjPdf = jeab_solkampanj_pdf_ready();
$solkampanjFlash = (string) ($_GET['sparat'] ?? '') === '1' ? 'Sparat.' : '';
$solkampanjError = trim((string) ($_GET['fel'] ?? ''));
require __DIR__ . '/src/pages/solkampanj.php';
