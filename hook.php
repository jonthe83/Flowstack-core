<?php

declare(strict_types=1);

require_once __DIR__ . '/src/deploy_guard.php';
jeab_deploy_guard();
require_once __DIR__ . '/src/demo.php';
jeab_demo_forbid_hook();

$GLOBALS['JEAB_NO_SESSION'] = true;
$GLOBALS['_JEAB_EXTERNAL_ALLOW'] = true;
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'GET' && (string) ($_GET['action'] ?? '') === 'warm') {
    $GLOBALS['_JEAB_WARM'] = true;
    $GLOBALS['_JEAB_BOOT_LAZY'] = true;
}
require __DIR__ . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!empty($GLOBALS['_JEAB_WARM']) && function_exists('jeab_warm_respond')) {
    jeab_warm_respond();
}

$jeabHookGetAction = '';
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'GET') {
    $jeabHookGetAction = (string) ($_GET['action'] ?? '');
    if (in_array($jeabHookGetAction, ['data.cutover_all', 'data.rollback_type', 'data.restore_doc', 'data.inkorg_counts', 'data.inkorg_probe', 'diag.mina', 'data.set_storage', 'diag.opcache', 'data.inkorg_trace'], true) && function_exists('jeab_cron_capture_query_token')) {
        jeab_cron_capture_query_token();
    }
}
jeab_reject_get_webhook_token();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $jeabHookGetAction === 'data.inkorg_trace') {
    // Läser Inkorgs diagnostikring (data/.private/inkorg-trace.json). Ingen databas. &clear=1 tömmer.
    require_once __DIR__ . '/src/data_cutover_all.php';
    require_once __DIR__ . '/src/inkorg_trace.php';
    $jeabTraceInput = $_GET;
    unset($jeabTraceInput['token']);
    $result = jeab_inkorg_trace_dispatch($jeabTraceInput);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && $jeabHookGetAction === 'diag.opcache') {
    require_once __DIR__ . '/src/data_cutover_all.php';
    $result = jeab_diag_opcache_dispatch();
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && !in_array($jeabHookGetAction, ['data.cutover_all', 'data.rollback_type', 'data.restore_doc', 'data.inkorg_counts', 'data.inkorg_probe', 'diag.mina', 'data.set_storage', 'diag.opcache', 'data.inkorg_trace'], true)) {
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && !in_array($jeabHookGetAction, ['data.cutover_all', 'data.rollback_type', 'data.restore_doc', 'data.inkorg_counts', 'data.inkorg_probe', 'diag.mina', 'data.set_storage'], true)) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Använd POST.']);
    exit;
}

try {
    $store = jeab_boot();
} catch (Throwable $e) {
    $busy = $e instanceof JeabDbBusyException;
    http_response_code($busy ? 503 : 500);
    if ($busy && function_exists('jeab_pdo_is_background') && jeab_pdo_is_background() && !headers_sent()) {
        header('Retry-After: 3');
    }
    echo json_encode([
        'ok' => false,
        'error' => $busy ? 'Databasen är upptagen. Försök igen om en stund.' : 'Något gick fel.',
        'busy' => $busy ? 1 : 0,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = isset($GLOBALS['_JEAB_RAW_BODY']) && is_string($GLOBALS['_JEAB_RAW_BODY'])
    ? $GLOBALS['_JEAB_RAW_BODY']
    : (file_get_contents('php://input') ?: '');
$input = [];
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}
if (!$input) {
    $input = $_POST;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $input = $_GET;
    unset($input['token']);
}

$jeabHookAction = trim((string) ($input['action'] ?? ''));
// Bokmalen skickar ofta type, eller bara orders_read. Utan fortnox.-prefixet
// träffar anropet order-callbacken och svarar 404 not_found.
$jeabOrdersReadName = static function (mixed $name): string {
    if (!is_string($name)) {
        return '';
    }
    $name = strtolower(trim($name));
    if ($name === 'fortnox.orders_read' || $name === 'orders_read') {
        return 'fortnox.orders_read';
    }

    return '';
};
$jeabOrdersRead = $jeabOrdersReadName($jeabHookAction);
if ($jeabOrdersRead === '' && $jeabHookAction === '') {
    foreach ([$input['type'] ?? null, $input['event'] ?? null, $_GET['action'] ?? null] as $jeabOrdersReadCandidate) {
        $jeabOrdersRead = $jeabOrdersReadName($jeabOrdersReadCandidate);
        if ($jeabOrdersRead !== '') {
            break;
        }
    }
}
if ($jeabOrdersRead !== '') {
    $jeabHookAction = $jeabOrdersRead;
    $input['action'] = $jeabOrdersRead;
}
if ($jeabHookAction === 'seom.fullmakt_status' || $jeabHookAction === 'seom.customer_signed') {
    $result = $jeabHookAction === 'seom.customer_signed'
        ? (function_exists('jeab_seom_customer_signed_dispatch')
            ? jeab_seom_customer_signed_dispatch(is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400])
        : (function_exists('jeab_seom_fullmakt_status_dispatch')
            ? jeab_seom_fullmakt_status_dispatch(is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400]);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'test_lead.audit') {
    $result = function_exists('jeab_test_lead_audit_dispatch')
        ? jeab_test_lead_audit_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'omtago.lead_status' || $jeabHookAction === 'omtago.reopen_test' || $jeabHookAction === 'omtago.key_status') {
    if ($jeabHookAction === 'omtago.reopen_test') {
        $result = function_exists('jeab_omtago_reopen_test_dispatch')
            ? jeab_omtago_reopen_test_dispatch(is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'http_status' => 400];
    } elseif ($jeabHookAction === 'omtago.key_status') {
        $result = function_exists('jeab_omtago_key_status_dispatch')
            ? jeab_omtago_key_status_dispatch()
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'http_status' => 400];
    } else {
        $result = function_exists('jeab_omtago_lead_status_dispatch')
            ? jeab_omtago_lead_status_dispatch(is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'http_status' => 400];
    }
    $status = (int) ($result['http_status'] ?? 200);
    unset($result['http_status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'wix.spam_check') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Fel token.\n";
        exit;
    }
    $result = function_exists('jeab_wix_spam_dispatch')
        ? jeab_wix_spam_dispatch(is_array($input) ? $input : [])
        : ['status' => 400, 'text' => "Okänd åtgärd.\n"];
    http_response_code((int) ($result['status'] ?? 200));
    header('Content-Type: text/plain; charset=utf-8');
    echo (string) ($result['text'] ?? '');
    exit;
}
if ($jeabHookAction === 'inkorg.list_rebuild') {
    if (!function_exists('jeab_inkorg_list_rebuild_authorized') || !jeab_inkorg_list_rebuild_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    $result = function_exists('jeab_inkorg_list_rebuild_hook')
        ? jeab_inkorg_list_rebuild_hook()
        : ['ok' => false, 'error' => 'not_found'];
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'company.realign_by_assignee' || $jeabHookAction === 'company.realign_by_assignee_undo') {
    require_once __DIR__ . '/src/company_realign.php';
    $result = function_exists('jeab_company_realign_dispatch')
        ? jeab_company_realign_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400, 'sent' => 0, 'would_send' => false];
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'inkorg.body') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    try {
        $result = function_exists('jeab_inkorg_hook')
            ? jeab_inkorg_hook(is_array($input) ? $input : ['action' => 'inkorg.body'])
            : ['ok' => false, 'error' => 'not_found'];
        $status = (int) ($result['status'] ?? 0);
        unset($result['status']);
        if ($status < 400 || $status > 599) {
            $status = !empty($result['ok']) ? 200 : ((string) ($result['error'] ?? '') === 'not_found' ? 404 : 400);
        }
        http_response_code($status);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        $result = ['ok' => false, 'error' => $e->getMessage()];
    } catch (Throwable $e) {
        if ($e instanceof RuntimeException && $e->getMessage() === 'SQL saknas') {
            http_response_code(503);
            $result = ['ok' => false, 'error' => 'SQL saknas'];
        } else {
            if (function_exists('jeab_log_app_error')) {
                jeab_log_app_error('inkorg.body', $e::class . ': ' . $e->getMessage());
            }
            http_response_code(500);
            $result = ['ok' => false, 'error' => 'hook_failed', 'reason' => $e::class];
        }
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'inkorg.relink_missing') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.', 'sent' => 0, 'would_send' => false]);
        exit;
    }
    $result = function_exists('jeab_inkorg_relink_missing')
        ? jeab_inkorg_relink_missing(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'not_found', 'sent' => 0, 'would_send' => false];
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (str_starts_with($jeabHookAction, 'inkorg.')) {
    $jeabHookAction = 'inbox.' . substr($jeabHookAction, 7);
    $input['action'] = $jeabHookAction;
}
if ($jeabHookAction === 'kontakter.dubbletter') {
    $jeabHookAction = 'kontakter.dedupe';
    $input['action'] = $jeabHookAction;
}
if (in_array($jeabHookAction, ['kontakter.stats', 'kontakter.text_export'], true)) {
    $result = function_exists('jeab_kontakter_export_dispatch')
        ? jeab_kontakter_export_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'booking.move_mail_test' || $jeabHookAction === 'booking.move_test' || $jeabHookAction === 'booking.move_queue_clear' || $jeabHookAction === 'booking.create_test' || $jeabHookAction === 'booking.delete_test') {
    if ($jeabHookAction === 'booking.create_test') {
        $result = function_exists('jeab_booking_create_test_dispatch')
            ? jeab_booking_create_test_dispatch($store, is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    } elseif ($jeabHookAction === 'booking.delete_test') {
        $result = function_exists('jeab_booking_delete_test_dispatch')
            ? jeab_booking_delete_test_dispatch($store, is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    } elseif ($jeabHookAction === 'booking.move_queue_clear') {
        $result = function_exists('jeab_booking_move_queue_clear_dispatch')
            ? jeab_booking_move_queue_clear_dispatch($store, is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    } elseif ($jeabHookAction === 'booking.move_test') {
        $result = function_exists('jeab_booking_move_test_dispatch')
            ? jeab_booking_move_test_dispatch($store, is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    } else {
        $result = function_exists('jeab_booking_move_mail_test_dispatch')
            ? jeab_booking_move_mail_test_dispatch($store, is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    }
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'bookings.unlinked_for_contacts' || $jeabHookAction === 'bookings.link_order' || $jeabHookAction === 'bookings.link_order_undo') {
    require_once __DIR__ . '/src/bookings_link_order.php';
    $result = function_exists('jeab_bookings_link_dispatch')
        ? jeab_bookings_link_dispatch($store, is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'bookings.delete_one' || $jeabHookAction === 'bookings.delete_one_undo') {
    require_once __DIR__ . '/src/bookings_delete_one.php';
    $result = function_exists('jeab_bookings_delete_one_dispatch')
        ? jeab_bookings_delete_one_dispatch($store, is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'flags.get' || $jeabHookAction === 'flags.set') {
    require_once __DIR__ . '/src/flags.php';
    $result = $jeabHookAction === 'flags.get'
        ? jeab_flags_get_dispatch(is_array($input) ? $input : [])
        : jeab_flags_set_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'auth.logout_all') {
    $result = function_exists('jeab_auth_logout_all_dispatch')
        ? jeab_auth_logout_all_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'auth.code_people_shape') {
    $result = function_exists('jeab_auth_code_people_shape_dispatch')
        ? jeab_auth_code_people_shape_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'auth.session_user_repair') {
    $result = function_exists('jeab_auth_session_user_repair_dispatch')
        ? jeab_auth_session_user_repair_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'data.backend_set' || $jeabHookAction === 'settings.contacts_sole') {
    if ($jeabHookAction === 'data.backend_set') {
        $result = function_exists('jeab_data_backend_set_dispatch')
            ? jeab_data_backend_set_dispatch(is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    } else {
        $result = function_exists('jeab_contacts_sole_setting_dispatch')
            ? jeab_contacts_sole_setting_dispatch(is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    }
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'data.inkorg_contact_link') {
    require_once __DIR__ . '/src/data_cutover_all.php';
    require_once __DIR__ . '/src/inkorg_contact_link.php';
    $result = jeab_inkorg_contact_link_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'data.storage_status') {
    $result = function_exists('jeab_data_storage_status_dispatch')
        ? jeab_data_storage_status_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'data.migrate_types') {
    $result = function_exists('jeab_data_migrate_types_dispatch')
        ? jeab_data_migrate_types_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'data.cutover_all' || $jeabHookAction === 'data.rollback_type' || $jeabHookAction === 'data.restore_doc' || $jeabHookAction === 'data.inkorg_counts' || $jeabHookAction === 'data.inkorg_probe' || $jeabHookAction === 'diag.mina' || $jeabHookAction === 'data.set_storage') {
    require_once __DIR__ . '/src/data_cutover_all.php';
    if ($jeabHookAction === 'data.rollback_type') {
        $result = jeab_cutover_rollback_dispatch(is_array($input) ? $input : []);
    } elseif ($jeabHookAction === 'data.restore_doc') {
        $result = jeab_cutover_restore_doc_dispatch(is_array($input) ? $input : []);
    } elseif ($jeabHookAction === 'data.inkorg_counts') {
        $result = jeab_cutover_inkorg_counts_dispatch(is_array($input) ? $input : []);
    } elseif ($jeabHookAction === 'data.inkorg_probe') {
        $result = jeab_cutover_inkorg_probe_dispatch(is_array($input) ? $input : []);
    } elseif ($jeabHookAction === 'diag.mina') {
        $result = jeab_diag_mina_dispatch(is_array($input) ? $input : []);
    } elseif ($jeabHookAction === 'data.set_storage') {
        $result = jeab_cutover_set_storage_dispatch(is_array($input) ? $input : []);
    } else {
        $result = jeab_cutover_all_dispatch(is_array($input) ? $input : []);
    }
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'data.migrate_all' || $jeabHookAction === 'data.job_status' || $jeabHookAction === 'data.job_cancel') {
    require_once __DIR__ . '/src/data_jobs.php';
    if ($jeabHookAction === 'data.job_status' || $jeabHookAction === 'data.job_cancel') {
        $result = $jeabHookAction === 'data.job_cancel'
            ? jeab_data_job_cancel_dispatch(is_array($input) ? $input : [])
            : jeab_data_job_status_dispatch(is_array($input) ? $input : []);
        $status = (int) ($result['status'] ?? 200);
        unset($result['status']);
        http_response_code($status);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $result = jeab_data_migrate_dispatch(is_array($input) ? $input : []);
    jeab_data_job_respond($result);
}
if ($jeabHookAction === 'deploy.flag_status') {
    require_once __DIR__ . '/src/deploy_guard.php';
    $result = jeab_deploy_flag_status_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'migrate.backup' || $jeabHookAction === 'migrate.restore_verify') {
    require_once __DIR__ . '/src/migrate/backup.php';
    $result = $jeabHookAction === 'migrate.restore_verify'
        ? jeab_migrate_restore_verify_dispatch(is_array($input) ? $input : [])
        : jeab_migrate_backup_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (in_array($jeabHookAction, ['purchases.sql_migrate', 'purchases.sql_verify', 'purchases.sql_cutover'], true)) {
    require_once __DIR__ . '/src/purchase_sql_migrate.php';
    $result = jeab_purchases_sql_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'data.inkorg_contact_create' || $jeabHookAction === 'data.inkorg_contact_create_undo') {
    require_once __DIR__ . '/src/inkorg_contact_link.php';
    require_once __DIR__ . '/src/inkorg_contact_create.php';
    $result = $jeabHookAction === 'data.inkorg_contact_create_undo'
        ? jeab_inkorg_contact_create_undo_dispatch(is_array($input) ? $input : [])
        : jeab_inkorg_contact_create_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'calendar_log.sql_resync') {
    require_once __DIR__ . '/src/calendar_log_sql.php';
    $result = jeab_calendar_log_sql_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'inkorg_views.sql_resync') {
    require_once __DIR__ . '/src/inkorg_views_sql.php';
    $result = jeab_inkorg_views_sql_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (in_array($jeabHookAction, ['ordrar_deleted.sql_migrate', 'ordrar_deleted.sql_verify', 'ordrar_deleted.sql_cutover'], true)) {
    require_once __DIR__ . '/src/ordrar_deleted_sql_migrate.php';
    $result = jeab_ordrar_deleted_sql_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (in_array($jeabHookAction, ['biz_doc.sql_migrate', 'biz_doc.sql_verify', 'biz_doc.sql_cutover'], true)) {
    require_once __DIR__ . '/src/biz_doc_sql_migrate.php';
    $result = jeab_biz_doc_sql_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (in_array($jeabHookAction, ['kontakter.sql_migrate', 'kontakter.sql_verify', 'kontakter.sql_cutover'], true)) {
    $result = function_exists('jeab_kontakter_sql_dispatch')
        ? jeab_kontakter_sql_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (in_array($jeabHookAction, ['gi_loans.sql_migrate', 'gi_loans.sql_verify', 'gi_loans.sql_cutover'], true)) {
    require_once __DIR__ . '/src/gi_loans_sql_migrate.php';
    $result = jeab_gi_loans_sql_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (in_array($jeabHookAction, ['store.sql_migrate', 'store.sql_verify', 'store.sql_cutover'], true)) {
    require_once __DIR__ . '/src/store_sql_migrate.php';
    $result = jeab_store_sql_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'kontakter.set_place') {
    require_once __DIR__ . '/src/kontakter_set_place.php';
    $result = function_exists('jeab_kontakter_set_place_dispatch')
        ? jeab_kontakter_set_place_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'contacts.set_phone' || $jeabHookAction === 'contacts.set_phone_undo') {
    require_once __DIR__ . '/src/contacts_set_phone.php';
    $result = function_exists('jeab_contacts_set_phone_dispatch')
        ? jeab_contacts_set_phone_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'contacts.field_source') {
    require_once __DIR__ . '/src/contacts_field_source.php';
    $result = function_exists('jeab_contacts_field_source_dispatch')
        ? jeab_contacts_field_source_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($jeabHookAction === 'contacts.merge_runs' || $jeabHookAction === 'rows.keys') {
    require_once __DIR__ . '/src/hook_readonly_keys.php';
    $result = function_exists('jeab_hook_readonly_dispatch')
        ? jeab_hook_readonly_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (in_array($jeabHookAction, ['kontakter.sole', 'snapshots.counts', 'kontakter.sole_verify', 'kontakter.sole_undo', 'kontakter.sole_undo_rows', 'kontakter.sole_fill_empty', 'kontakter.sole_resnapshot', 'kontakter.sole_snapshot_diff', 'kontakter.pnr_ambiguous_resolve', 'kontakter.pnr_ambiguous_list', 'kontakter.pnr_fix', 'kontakter.owner_pnr_fill', 'kontakter.link_compare', 'kontakter.sole_paint_resolve', 'kontakter.gap_fill', 'kontakter.strict_report', 'kontakter.restore_contact_from_backup', 'kontakter.prune_orphan', 'contacts.sole_audit', 'contacts.link_backfill', 'contacts.link_backfill_undo', 'contacts.link_missing_core', 'contacts.link_missing_core_undo', 'contacts.link_by_email', 'contacts.link_by_email_undo', 'contacts.link_diag', 'contacts.link_inkorg_from_reminder', 'contacts.link_inkorg_from_reminder_undo', 'contacts.name_source', 'contacts.name_candidates', 'contacts.fill_name', 'contacts.fill_name_undo', 'inbox.link_contacts', 'inbox.link_contacts_undo', 'inbox.link_verify', 'inbox.contacts_from_threads', 'inbox.contacts_from_threads_undo', 'contacts.merge_duplicates', 'contacts.merge_duplicates_undo', 'contacts.normalize_addresses', 'contacts.normalize_addresses_undo', 'contacts.inbox_strip', 'contacts.inbox_strip_undo', 'contacts.copies_strip', 'contacts.copies_strip_undo', 'contacts.drop_customer_fields', 'contacts.drop_customer_fields_undo', 'contacts.customers_contact_key', 'contacts.customers_contact_key_undo', 'contacts.solkampanj_link', 'snapshots.audit', 'snapshots.backfill', 'snapshots.backfill_undo'], true)) {
    $result = function_exists('jeab_contacts_sole_dispatch')
        ? jeab_contacts_sole_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (in_array($jeabHookAction, ['kontakter.link', 'kontakter.link_report', 'kontakter.link_audit', 'kontakter.prune', 'kontakter.dedupe', 'kontakter.dedupe_log', 'kontakter.migrate_inbox_contacts', 'kontakter.address_import', 'kontakter.name_address_backfill', 'kontakter.name_address_explain', 'kontakter.find', 'kontakter.import_new', 'kontakter.relink_case', 'kontakter.conflicts', 'kontakter.conflicts_undo', 'kontakter.email_dupes', 'kontakter.email_merge', 'kontakter.email_unique_index'], true)) {
    if ($jeabHookAction === 'kontakter.conflicts' || $jeabHookAction === 'kontakter.conflicts_undo') {
        require_once __DIR__ . '/src/kontakter_conflicts.php';
        $result = function_exists('jeab_kontakter_conflicts_dispatch')
            ? jeab_kontakter_conflicts_dispatch($input)
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
        if ($jeabHookAction === 'kontakter.conflicts') {
            require_once __DIR__ . '/src/data_jobs.php';
            jeab_data_job_respond($result);
        }
    } elseif ($jeabHookAction === 'kontakter.import_new') {
        require_once __DIR__ . '/src/kontakter_import_new.php';
        $result = function_exists('jeab_kontakter_import_new_dispatch')
            ? jeab_kontakter_import_new_dispatch($input)
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    } elseif (in_array($jeabHookAction, ['kontakter.email_dupes', 'kontakter.email_merge', 'kontakter.email_unique_index'], true)) {
        require_once __DIR__ . '/src/kontakter_email_key.php';
        $result = function_exists('jeab_email_key_dispatch')
            ? jeab_email_key_dispatch($jeabHookAction, $input)
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    } elseif ($jeabHookAction === 'kontakter.relink_case') {
        require_once __DIR__ . '/src/kontakter_relink_case.php';
        $result = function_exists('jeab_kontakter_relink_case_dispatch')
            ? jeab_kontakter_relink_case_dispatch($input)
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    } elseif ($jeabHookAction === 'kontakter.address_import') {
        require_once __DIR__ . '/src/kontakter_address_import.php';
        $result = function_exists('jeab_kontakter_address_import_dispatch')
            ? jeab_kontakter_address_import_dispatch($input)
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    } elseif ($jeabHookAction === 'kontakter.find') {
        require_once __DIR__ . '/src/kontakter_name_address.php';
        $result = function_exists('jeab_kontakter_find_dispatch')
            ? jeab_kontakter_find_dispatch($input)
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    } elseif ($jeabHookAction === 'kontakter.name_address_explain') {
        require_once __DIR__ . '/src/kontakter_name_address.php';
        $result = function_exists('jeab_kontakter_name_address_explain_dispatch')
            ? jeab_kontakter_name_address_explain_dispatch($input)
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    } elseif ($jeabHookAction === 'kontakter.name_address_backfill') {
        require_once __DIR__ . '/src/kontakter_name_address.php';
        $jeabNameAddressDone = false;
        register_shutdown_function(static function () use (&$jeabNameAddressDone): void {
            if ($jeabNameAddressDone) {
                return;
            }
            $err = error_get_last();
            if (!is_array($err) || !in_array((int) ($err['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }
            $message = trim((string) ($err['message'] ?? ''));
            if (function_exists('jeab_name_address_log_failure')) {
                jeab_name_address_log_failure($message !== '' ? $message : 'okänt fel');
            }
        });
        try {
            $result = function_exists('jeab_kontakter_name_address_backfill_dispatch')
                ? jeab_kontakter_name_address_backfill_dispatch($input)
                : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
            $jeabNameAddressDone = true;
        } catch (Throwable $e) {
            $jeabNameAddressDone = true;
            if (function_exists('jeab_name_address_log_failure')) {
                jeab_name_address_log_failure($e::class . ': ' . $e->getMessage());
            }
            $result = ['ok' => false, 'error' => 'name_address_backfill avbröts.', 'status' => 500, 'sent' => 0];
        }
    } elseif ($jeabHookAction === 'kontakter.migrate_inbox_contacts' && function_exists('jeab_kontakter_migrate_inbox_dispatch')) {
        $result = jeab_kontakter_migrate_inbox_dispatch($input);
    } elseif ($jeabHookAction === 'kontakter.dedupe_log' && function_exists('jeab_kontakter_dedupe_log_dispatch')) {
        $result = jeab_kontakter_dedupe_log_dispatch($input);
    } elseif ($jeabHookAction === 'kontakter.dedupe' && function_exists('jeab_kontakter_dedupe_dispatch')) {
        $result = jeab_kontakter_dedupe_dispatch($input);
    } elseif ($jeabHookAction === 'kontakter.prune' && function_exists('jeab_kontakter_prune_dispatch')) {
        $result = jeab_kontakter_prune_dispatch($input);
    } elseif ($jeabHookAction === 'kontakter.link_audit' && function_exists('jeab_kontakter_link_audit_dispatch')) {
        $result = jeab_kontakter_link_audit_dispatch($input);
    } elseif ($jeabHookAction === 'kontakter.link_report' && function_exists('jeab_kontakter_link_report_dispatch')) {
        $result = jeab_kontakter_link_report_dispatch($input);
    } elseif (function_exists('jeab_kontakter_link_dispatch')) {
        $result = jeab_kontakter_link_dispatch($input);
    } else {
        $result = ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    }
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'db.conn_stats') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    $result = function_exists('jeab_pdo_conn_stats_hook')
        ? jeab_pdo_conn_stats_hook()
        : ['ok' => false, 'error' => 'not_found'];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'mail_queue.list' || $jeabHookAction === 'mail_queue.pause') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    $in = is_array($input) ? $input : [];
    if ($jeabHookAction === 'mail_queue.list') {
        // Bara läsning: kö och logg, även väntande rader.
        $result = function_exists('jeab_mail_queue_list') ? jeab_mail_queue_list($in) : ['ok' => false, 'error' => 'not_found', 'read_only' => true];
    } else {
        // Kill switch: paused=1 pausar, paused=0 släpper.
        $result = function_exists('jeab_mail_queue_set_paused')
            ? jeab_mail_queue_set_paused(!empty($in['paused']) && (string) $in['paused'] !== '0')
            : ['ok' => false, 'error' => 'not_found'];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'send_guard.queue_diag') {
    if (!function_exists('jeab_send_guard_diag_authorized') || !jeab_send_guard_diag_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.', 'read_only' => true]);
        exit;
    }
    $result = function_exists('jeab_send_guard_queue_diag_hook')
        ? jeab_send_guard_queue_diag_hook(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'not_found', 'read_only' => true];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'send_guard.peek') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    $result = function_exists('jeab_send_guard_peek_hook')
        ? jeab_send_guard_peek_hook(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'not_found', 'read_only' => true];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'send_guard.replay_one') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    $result = function_exists('jeab_send_guard_replay_one_hook')
        ? jeab_send_guard_replay_one_hook(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'not_found', 'sent' => 0];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'send_guard.stats') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    $result = function_exists('jeab_send_guard_stats_hook')
        ? jeab_send_guard_stats_hook(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'not_found'];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'send_guard.status' || $jeabHookAction === 'send_guard.reset') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    if ($jeabHookAction === 'send_guard.reset') {
        $result = function_exists('jeab_send_guard_reset_hook')
            ? jeab_send_guard_reset_hook(is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'not_found'];
    } else {
        $result = function_exists('jeab_send_guard_status_hook')
            ? jeab_send_guard_status_hook(is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'not_found'];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'notiser.list') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    $result = function_exists('jeab_notiser_list') ? jeab_notiser_list() : ['ok' => false, 'error' => 'not_found'];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'reminders.deleted_audit' && function_exists('jeab_reminders_deleted_audit_dispatch')) {
    $result = jeab_reminders_deleted_audit_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'reminders.restore_from_backup' && function_exists('jeab_reminders_restore_from_backup_dispatch')) {
    $result = jeab_reminders_restore_from_backup_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'reminders.restore_undo' && function_exists('jeab_reminders_restore_undo_dispatch')) {
    $result = jeab_reminders_restore_undo_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'reminder.get_due' && function_exists('jeab_reminder_get_due_dispatch')) {
    $result = jeab_reminder_get_due_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'reminders.snooze_audit' && function_exists('jeab_reminders_snooze_audit_dispatch')) {
    $result = jeab_reminders_snooze_audit_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'reminders.lookup_hsi' && function_exists('jeab_reminders_lookup_hsi_dispatch')) {
    $result = jeab_reminders_lookup_hsi_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'reminders.strip_hs_links' && function_exists('jeab_reminders_strip_hs_links_dispatch')) {
    $result = jeab_reminders_strip_hs_links_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (
    ($jeabHookAction === 'reminders.dedupe_existing' || $jeabHookAction === 'reminders.dedupe_undo' || $jeabHookAction === 'reminders.get')
    && function_exists('jeab_reminders_dedupe_dispatch')
) {
    $result = jeab_reminders_dedupe_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'reminder.delete_test') {
    require_once __DIR__ . '/src/reminder_delete_test.php';
    $result = function_exists('jeab_reminder_delete_test_dispatch')
        ? jeab_reminder_delete_test_dispatch($store, is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'json.inventory') {
    require_once __DIR__ . '/src/json_trash.php';
    $result = function_exists('jeab_json_inventory_dispatch')
        ? jeab_json_inventory_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'json.trash_unused') {
    require_once __DIR__ . '/src/json_trash.php';
    $result = function_exists('jeab_json_trash_unused_dispatch')
        ? jeab_json_trash_unused_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (str_starts_with($jeabHookAction, 'calendar.') && function_exists('jeab_outlook_hook')) {
    if (!jeab_outlook_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    try {
        $result = jeab_outlook_hook($store, is_array($input) ? $input : []);
        $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
        unset($result['status']);
        http_response_code($status);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        $result = ['ok' => false, 'error' => $e->getMessage()];
    } catch (Throwable) {
        http_response_code(400);
        $result = ['ok' => false, 'error' => 'Kalendersynken gick inte att köra.'];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.schablon_check' && function_exists('jeab_ordrar_schablon_check_dispatch')) {
    $result = jeab_ordrar_schablon_check_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (
    $jeabHookAction === 'ordrar.state_sources'
    || $jeabHookAction === 'ordrar.get'
    || $jeabHookAction === 'ordrar.rebuild_state'
) {
    require_once __DIR__ . '/src/ordrar_restore_state.php';
    $result = function_exists('jeab_ordrar_state_sources_dispatch')
        ? jeab_ordrar_state_sources_dispatch($store, is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.restore_state_from_snapshot' || $jeabHookAction === 'ordrar.restore_state_undo') {
    require_once __DIR__ . '/src/ordrar_restore_state.php';
    $result = function_exists('jeab_ordrar_restore_state_dispatch')
        ? jeab_ordrar_restore_state_dispatch($store, is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.restore_from_snapshot' && function_exists('jeab_ordrar_restore_from_snapshot_dispatch')) {
    $result = jeab_ordrar_restore_from_snapshot_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.restore_undo' && function_exists('jeab_ordrar_restore_undo_dispatch')) {
    $result = jeab_ordrar_restore_undo_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.missing_report' && function_exists('jeab_ordrar_missing_report_dispatch')) {
    $result = jeab_ordrar_missing_report_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.delete_audit' && function_exists('jeab_ordrar_delete_audit_dispatch')) {
    $result = jeab_ordrar_delete_audit_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.supplier_lines_recompute' && function_exists('jeab_ordrar_supplier_lines_recompute_dispatch')) {
    $result = jeab_ordrar_supplier_lines_recompute_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.blank_scan' && function_exists('jeab_ordrar_blank_scan_dispatch')) {
    $result = jeab_ordrar_blank_scan_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.clear_stale_leverans_error' && function_exists('jeab_ordrar_clear_stale_leverans_error_dispatch')) {
    $result = jeab_ordrar_clear_stale_leverans_error_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.fullmakt_preview') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'error' => 'Fel token.',
            'action' => 'ordrar.fullmakt_preview',
            'dry' => 1,
            'sent' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $input['dry'] = 1;
    $result = function_exists('jeab_ellevio_fullmakt_preview_dispatch')
        ? jeab_ellevio_fullmakt_preview_dispatch($store, is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'action' => 'ordrar.fullmakt_preview', 'dry' => 1, 'sent' => false, 'status' => 400];
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'fullmakt.docs_status') {
    require_once __DIR__ . '/src/fullmakt_docs_status.php';
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'error' => 'Fel token.',
            'action' => 'fullmakt.docs_status',
            'read_only' => true,
            'sent' => 0,
            'would_send' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    try {
        $result = function_exists('jeab_fullmakt_docs_status_dispatch')
            ? jeab_fullmakt_docs_status_dispatch(is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'action' => 'fullmakt.docs_status', 'read_only' => true, 'sent' => 0, 'would_send' => false, 'status' => 400];
        $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
        unset($result['status']);
        http_response_code($status);
    } catch (Throwable $e) {
        if (function_exists('jeab_log_uncaught')) {
            jeab_log_uncaught('fullmakt.docs_status', $e);
        }
        http_response_code(500);
        $result = [
            'ok' => false,
            'error' => function_exists('jeab_public_error_text') ? jeab_public_error_text($e) : 'Något gick fel.',
            'action' => 'fullmakt.docs_status',
            'read_only' => true,
            'sent' => 0,
            'would_send' => false,
        ];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'fullmakt.public_probe') {
    require_once __DIR__ . '/src/fullmakt_public_probe.php';
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'error' => 'Fel token.',
            'action' => 'fullmakt.public_probe',
            'read_only' => true,
            'sent' => 0,
            'would_send' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    try {
        $result = function_exists('jeab_fullmakt_public_probe_dispatch')
            ? jeab_fullmakt_public_probe_dispatch(is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'action' => 'fullmakt.public_probe', 'read_only' => true, 'sent' => 0, 'would_send' => false, 'status' => 400];
        $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
        unset($result['status']);
        http_response_code($status);
    } catch (Throwable $e) {
        if (function_exists('jeab_log_uncaught')) {
            jeab_log_uncaught('fullmakt.public_probe', $e);
        }
        http_response_code(500);
        $result = [
            'ok' => false,
            'error' => function_exists('jeab_public_error_text') ? jeab_public_error_text($e) : 'Något gick fel.',
            'action' => 'fullmakt.public_probe',
            'read_only' => true,
            'sent' => 0,
            'would_send' => false,
        ];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.backfill' && function_exists('jeab_ordrar_backfill_dispatch')) {
    $result = jeab_ordrar_backfill_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'order_owner_backfill' && function_exists('jeab_ordrar_order_owner_backfill_dispatch')) {
    $result = jeab_ordrar_order_owner_backfill_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'contacts.address_recheck' && function_exists('jeab_contacts_address_recheck_dispatch')) {
    $result = jeab_contacts_address_recheck_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.pnr_owner_backfill' && function_exists('jeab_ordrar_pnr_owner_backfill_dispatch')) {
    $result = jeab_ordrar_pnr_owner_backfill_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.lm_refetch' && function_exists('jeab_ordrar_lm_refetch_dispatch')) {
    $result = jeab_ordrar_lm_refetch_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.lm_lookup' && function_exists('jeab_ordrar_lm_lookup_dispatch')) {
    $result = jeab_ordrar_lm_lookup_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'lm.mismatch_scan' || $jeabHookAction === 'rot.lm_cache_suspects') {
    require_once __DIR__ . '/src/address_lookup.php';
    $result = function_exists('jeab_lm_mismatch_scan_dispatch')
        ? jeab_lm_mismatch_scan_dispatch(is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_lm_mismatch_scrub')) {
        $scrubbed = jeab_lm_mismatch_scrub($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'order.find' && function_exists('jeab_order_find_dispatch')) {
    $result = jeab_order_find_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_fastighet_scrub_public')) {
        $scrubbed = jeab_fastighet_scrub_public($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'order.fastighet_refresh' && function_exists('jeab_order_fastighet_refresh_dispatch')) {
    $result = jeab_order_fastighet_refresh_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_fastighet_scrub_public')) {
        $scrubbed = jeab_fastighet_scrub_public($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.applicants_repair' && function_exists('jeab_ordrar_applicants_repair_dispatch')) {
    $result = jeab_ordrar_applicants_repair_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    if (function_exists('jeab_fastighet_scrub_public')) {
        $scrubbed = jeab_fastighet_scrub_public($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'order.fastighet_undo' && function_exists('jeab_order_fastighet_undo_dispatch')) {
    $result = jeab_order_fastighet_undo_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_fastighet_scrub_public')) {
        $scrubbed = jeab_fastighet_scrub_public($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.fastighet_probe' && function_exists('jeab_ordrar_fastighet_probe_dispatch')) {
    $result = jeab_ordrar_fastighet_probe_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    if (function_exists('jeab_fastighet_scrub_public')) {
        $scrubbed = jeab_fastighet_scrub_public($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.fastighet_scan' && function_exists('jeab_ordrar_fastighet_scan_dispatch')) {
    $result = jeab_ordrar_fastighet_scan_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_fastighet_scrub_public')) {
        $scrubbed = jeab_fastighet_scrub_public($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.bekrafta_blockers' && function_exists('jeab_ordrar_bekrafta_blockers_dispatch')) {
    $result = jeab_ordrar_bekrafta_blockers_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    if (function_exists('jeab_fastighet_scrub_public')) {
        $scrubbed = jeab_fastighet_scrub_public($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.bekrafta_fill' && function_exists('jeab_ordrar_bekrafta_fill_dispatch')) {
    $result = jeab_ordrar_bekrafta_fill_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    if (function_exists('jeab_fastighet_scrub_public')) {
        $scrubbed = jeab_fastighet_scrub_public($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.set_fastighet' && function_exists('jeab_ordrar_set_fastighet_dispatch')) {
    $result = jeab_ordrar_set_fastighet_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    if (function_exists('jeab_fastighet_scrub_public')) {
        $scrubbed = jeab_fastighet_scrub_public($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.lm_candidates' && function_exists('jeab_ordrar_lm_candidates_dispatch')) {
    $result = jeab_ordrar_lm_candidates_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    if (function_exists('jeab_fastighet_scrub_public')) {
        $scrubbed = jeab_fastighet_scrub_public($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.set_owners' && function_exists('jeab_ordrar_set_owners_dispatch')) {
    $result = jeab_ordrar_set_owners_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    if (function_exists('jeab_fastighet_scrub_public')) {
        $scrubbed = jeab_fastighet_scrub_public($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.sync_owner_pnr' || $jeabHookAction === 'ordrar.fortnox_rerun' || $jeabHookAction === 'ordrar.fortnox_check') {
    require_once __DIR__ . '/src/kontakter.php';
    require_once __DIR__ . '/src/contacts_sole.php';
    require_once __DIR__ . '/src/ordrar_fortnox.php';
}

if ($jeabHookAction === 'ordrar.sync_owner_pnr' && function_exists('jeab_ordrar_sync_owner_pnr_dispatch')) {
    $result = jeab_ordrar_sync_owner_pnr_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    if (function_exists('jeab_fastighet_scrub_public')) {
        $scrubbed = jeab_fastighet_scrub_public($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.solkoll_ny' && function_exists('jeab_solkoll_ny_dispatch')) {
    $result = jeab_solkoll_ny_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.fortnox_rerun' && function_exists('jeab_ordrar_fortnox_rerun_dispatch')) {
    $result = jeab_ordrar_fortnox_rerun_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.fortnox_check' && function_exists('jeab_ordrar_fortnox_check_dispatch')) {
    $result = jeab_ordrar_fortnox_check_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($result);
        if (is_array($scrubbed)) {
            $result = $scrubbed;
        }
    }
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'ordrar.set_mobil' && function_exists('jeab_ordrar_set_mobil_dispatch')) {
    $result = jeab_ordrar_set_mobil_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (
    ($jeabHookAction === 'reminder.set_owner' || $jeabHookAction === 'reminder.set_address' || $jeabHookAction === 'reminder.set_email' || $jeabHookAction === 'reminder.create_manual' || $jeabHookAction === 'reminders.zero_id_check')
    && function_exists('jeab_reminder_admin_dispatch')
) {
    $result = jeab_reminder_admin_dispatch($store, is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? 200);
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'hs.create_internal' && function_exists('jeab_hs_create_internal_dispatch')) {
    try {
        $result = jeab_hs_create_internal_dispatch(is_array($input) ? $input : []);
        $status = (int) ($result['status'] ?? 200);
        unset($result['status']);
        http_response_code($status);
    } catch (Throwable $e) {
        if (function_exists('jeab_log_uncaught')) {
            jeab_log_uncaught('hs.create_internal', $e);
        }
        http_response_code(500);
        $result = [
            'ok' => false,
            'error' => function_exists('jeab_public_error_text') ? jeab_public_error_text($e) : 'Något gick fel.',
            'action' => 'hs.create_internal',
        ];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'hs.search' && function_exists('jeab_hs_search_dispatch')) {
    try {
        $result = jeab_hs_search_dispatch(is_array($input) ? $input : []);
        $status = (int) ($result['status'] ?? 200);
        unset($result['status']);
        http_response_code($status);
    } catch (Throwable $e) {
        if (function_exists('jeab_log_uncaught')) {
            jeab_log_uncaught('hs.search', $e);
        }
        http_response_code(500);
        $result = [
            'ok' => false,
            'error' => function_exists('jeab_public_error_text') ? jeab_public_error_text($e) : 'Något gick fel.',
            'action' => 'hs.search',
        ];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'system.disk') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    $result = function_exists('jeab_system_disk') ? jeab_system_disk() : ['ok' => false, 'error' => 'not_found'];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'sys.health') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    $result = function_exists('jeab_sys_health') ? jeab_sys_health() : ['ok' => false, 'error' => 'not_found', 'read_only' => true];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'hs_archive.import') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    $result = jeab_hs_archive_import_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'sms.reminder_preview') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.', 'dry' => 1, 'read_only' => true]);
        exit;
    }
    $input['dry'] = 1;
    $result = function_exists('jeab_sms_reminder_preview_dispatch')
        ? jeab_sms_reminder_preview_dispatch($store, is_array($input) ? $input : [])
        : ['ok' => false, 'error' => 'Okänd åtgärd.', 'dry' => 1, 'read_only' => true, 'status' => 400];
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'notes.import' || $jeabHookAction === 'sms.import') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    $result = $jeabHookAction === 'notes.import'
        ? jeab_notes_import_dispatch(is_array($input) ? $input : [])
        : jeab_sms_import_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (str_starts_with($jeabHookAction, 'helpscout.') && function_exists('jeab_hs_import_hook')) {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    try {
        $result = jeab_hs_import_hook($input);
        http_response_code(!empty($result['ok']) ? 200 : 400);
    } catch (Throwable $e) {
        http_response_code(400);
        $result = ['ok' => false, 'error' => 'Importen stoppades.'];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'offer.send_malmo' && function_exists('jeab_inkorg_hook_authorized')) {
    if (!jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    try {
        $result = jeab_offer_send_malmo($input);
        http_response_code(!empty($result['ok']) ? 200 : 400);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        $result = ['ok' => false, 'error' => $e->getMessage()];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'inbox.fortnox_kunder') {
    require_once __DIR__ . '/src/inkorg_fortnox_kunder.php';
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    try {
        $result = jeab_inkorg_fortnox_kunder(is_array($input) ? $input : []);
    } catch (Throwable) {
        $result = ['ok' => false, 'error' => 'Kundlistan gick inte att hämta.', 'status' => 400];
    }
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'users.auth_diag') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    try {
        $result = function_exists('jeab_auth_diag')
            ? jeab_auth_diag()
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    } catch (Throwable $e) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('users', 'auth diag failed');
        }
        $result = ['ok' => false, 'error' => 'Inloggningen kunde inte beskrivas.', 'status' => 400];
    }
    unset($result['code'], $result['hash'], $result['hashes'], $result['password'], $result['secret'], $result['path']);
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'users.add_restricted') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    $restrictedIn = is_array($input) ? $input : [];
    try {
        $result = function_exists('jeab_restricted_add')
            ? jeab_restricted_add($restrictedIn)
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    } catch (Throwable $e) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('users', 'restricted user create failed');
        }
        $result = ['ok' => false, 'error' => 'Användaren kunde inte skapas.', 'status' => 400];
    }
    if (function_exists('jeab_restricted_scrub')) {
        $result = jeab_restricted_scrub($result, (string) ($restrictedIn['code'] ?? ''));
    }
    unset($result['code'], $result['hash'], $result['password'], $result['secret']);
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'solkampanj.import') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    try {
        $result = function_exists('jeab_solkampanj_import_dispatch')
            ? jeab_solkampanj_import_dispatch(is_array($input) ? $input : [])
            : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
    } catch (Throwable $e) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('solkampanj', $e->getMessage());
        }
        $result = ['ok' => false, 'error' => 'Importen gick inte att läsa.', 'status' => 400];
    }
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'solkampanj.list_stats' || $jeabHookAction === 'solkampanj.exclude') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    try {
        $solIn = is_array($input) ? $input : [];
        if ($jeabHookAction === 'solkampanj.list_stats' && function_exists('jeab_solkampanj_list_stats')) {
            $result = jeab_solkampanj_list_stats();
        } elseif ($jeabHookAction === 'solkampanj.exclude' && function_exists('jeab_solkampanj_exclude')) {
            $result = jeab_solkampanj_exclude($solIn);
        } else {
            $result = ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
        }
    } catch (Throwable $e) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('solkampanj', $e->getMessage());
        }
        $result = ['ok' => false, 'error' => 'Solkampanjen svarade inte.', 'status' => 400];
    }
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'solkampanj.test_send' || $jeabHookAction === 'solkampanj.test_send_battery' || $jeabHookAction === 'solkampanj.test_consumed' || $jeabHookAction === 'solkampanj.reset_pending') {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    try {
        $solIn = is_array($input) ? $input : [];
        if ($jeabHookAction === 'solkampanj.test_send' && function_exists('jeab_solkampanj_test_send')) {
            $result = jeab_solkampanj_test_send($solIn, function_exists('jeab_solkampanj_actor') ? jeab_solkampanj_actor() : '');
        } elseif ($jeabHookAction === 'solkampanj.test_send_battery' && function_exists('jeab_solkampanj_test_send_battery')) {
            $result = jeab_solkampanj_test_send_battery($solIn, function_exists('jeab_solkampanj_actor') ? jeab_solkampanj_actor() : '');
        } elseif ($jeabHookAction === 'solkampanj.test_consumed' && function_exists('jeab_solkampanj_test_consumed')) {
            $result = jeab_solkampanj_test_consumed($solIn);
        } elseif ($jeabHookAction === 'solkampanj.reset_pending' && function_exists('jeab_solkampanj_reset_pending')) {
            $result = jeab_solkampanj_reset_pending($solIn);
        } else {
            $result = ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
        }
    } catch (InvalidArgumentException $e) {
        $result = ['ok' => false, 'error' => $e->getMessage(), 'status' => 422];
    } catch (Throwable $e) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('solkampanj', $e->getMessage());
        }
        $result = ['ok' => false, 'error' => 'Solkampanjen svarade inte.', 'status' => 400];
    }
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'fortnox.kund_fakturasummor') {
    require_once __DIR__ . '/src/fortnox_kund_fakturasummor.php';
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    try {
        $result = jeab_fortnox_kund_fakturasummor(is_array($input) ? $input : []);
    } catch (Throwable) {
        $result = ['ok' => false, 'error' => 'Fakturorna gick inte att hämta.', 'status' => 400];
    }
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($jeabHookAction === 'fellogg.stats' && function_exists('jeab_fellogg_stats')) || ($jeabHookAction === 'fellogg.get' && function_exists('jeab_fellogg_get'))) {
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    $jeabFelloggIn = is_array($input) ? $input : [];
    $jeabFelloggOut = $jeabHookAction === 'fellogg.get' ? jeab_fellogg_get($jeabFelloggIn) : jeab_fellogg_stats($jeabFelloggIn);
    echo json_encode($jeabFelloggOut, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($jeabHookAction === 'remain.import' || $jeabHookAction === 'remain.storage') && function_exists('jeab_remain_import_dispatch')) {
    $result = $jeabHookAction === 'remain.storage' && function_exists('jeab_remain_storage_dispatch')
        ? jeab_remain_storage_dispatch(is_array($input) ? $input : [])
        : jeab_remain_import_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($jeabHookAction === 'logs.import' || $jeabHookAction === 'logs.dedupe') && function_exists('jeab_log_import_dispatch')) {
    $result = $jeabHookAction === 'logs.dedupe' && function_exists('jeab_log_dedupe_dispatch')
        ? jeab_log_dedupe_dispatch(is_array($input) ? $input : [])
        : jeab_log_import_dispatch(is_array($input) ? $input : []);
    $status = (int) ($result['status'] ?? (!empty($result['ok']) ? 200 : 400));
    unset($result['status']);
    http_response_code($status);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($jeabHookAction === 'inbox.quote_queue_legacy_count' && function_exists('jeab_inkorg_hook_authorized')) {
    if (!jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    $result = function_exists('jeab_inbox_quote_queue_legacy_count')
        ? jeab_inbox_quote_queue_legacy_count()
        : ['ok' => false, 'error' => 'not_found', 'read_only' => true, 'sent' => 0, 'would_send' => false, 'total' => 0, 'companies' => ['jarfalla' => 0, 'malmo' => 0, 'other' => 0]];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ((str_starts_with($jeabHookAction, 'inbox.') || $jeabHookAction === 'offer.resend' || $jeabHookAction === 'rebook.pending' || $jeabHookAction === 'leads.product_recheck' || $jeabHookAction === 'leads.reparse_place') && function_exists('jeab_inkorg_hook_authorized')) {
    if (!jeab_inkorg_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    try {
        $result = jeab_inkorg_hook($input);
        $rawStatus = $result['status'] ?? null;
        $status = 0;
        if (is_int($rawStatus) || (is_string($rawStatus) && ctype_digit(trim($rawStatus)))) {
            $status = (int) $rawStatus;
            unset($result['status']);
        }
        if ($status < 400 || $status > 599) {
            $status = !empty($result['ok']) ? 200 : ((string) ($result['error'] ?? '') === 'not_found' ? 404 : 400);
        }
        http_response_code($status);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        $result = ['ok' => false, 'error' => $e->getMessage()];
    } catch (Throwable $e) {
        if ($e instanceof RuntimeException && $e->getMessage() === 'SQL saknas') {
            http_response_code(503);
            $result = ['ok' => false, 'error' => 'SQL saknas'];
        } else {
            if (function_exists('jeab_log_app_error')) {
                jeab_log_app_error($jeabHookAction !== '' ? $jeabHookAction : 'inbox', $e::class . ': ' . $e->getMessage());
            }
            http_response_code(500);
            $result = [
                'ok' => false,
                'error' => $jeabHookAction === 'inbox.reopen' ? 'reopen_failed' : 'hook_failed',
                'reason' => $e::class,
            ];
        }
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!empty($result['background']) && function_exists('jeab_inkorg_resend_after_response')) {
        jeab_inkorg_resend_after_response((string) ($result['job'] ?? ''));
    }
    exit;
}

if (str_starts_with((string) ($input['action'] ?? ''), 'fortnox.')) {
    require_once __DIR__ . '/src/fortnox_lon.php';
    $jeabFortnoxAction = (string) ($input['action'] ?? '');
    $jeabFortnoxAttach = $jeabFortnoxAction === 'fortnox.order_attach' || $jeabFortnoxAction === 'fortnox.order_attach_undo';
    if ($jeabFortnoxAttach) {
        require_once __DIR__ . '/src/fortnox_order_link.php';
    }
    $jeabFortnoxAllowed = jeab_fortnox_lon_hook_authorized($store, $jeabFortnoxAction);
    if (!$jeabFortnoxAllowed && $jeabFortnoxAttach && function_exists('jeab_fortnox_order_attach_admin_post')) {
        $jeabFortnoxAllowed = jeab_fortnox_order_attach_admin_post();
    }
    if (!$jeabFortnoxAllowed) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    if (in_array($jeabFortnoxAction, ['fortnox.pnr_check', 'fortnox.kund_hamta', 'fortnox.kund_jamfor_order', 'fortnox.kund_ratta_orgnr', 'fortnox.faktura_kopiera', 'fortnox.faktura_makulera_eller_kreditera', 'fortnox.faktura_skapa_om'], true)) {
        if ($jeabFortnoxAction === 'fortnox.faktura_skapa_om') {
            $jeabRedoOff = array_key_exists('dry', $input)
                && ($input['dry'] === 0 || $input['dry'] === '0' || $input['dry'] === false);
            if (!$jeabRedoOff || (string) ($input['confirm'] ?? '') !== 'SAM') {
                $input['dry'] = 1;
            }
        }
        require_once __DIR__ . '/src/fortnox_pnr.php';
        try {
            $result = jeab_fortnox_pnr_dispatch($jeabFortnoxAction, is_array($input) ? $input : []);
            $code = !empty($result['ok']) ? 200 : (int) ($result['status'] ?? 400);
            if ($code < 400 && empty($result['ok'])) {
                $code = 400;
            }
            http_response_code($code);
        } catch (InvalidArgumentException) {
            http_response_code(422);
            $result = ['ok' => false, 'error' => 'Ogiltigt anrop.'];
        } catch (Throwable) {
            http_response_code(400);
            $result = ['ok' => false, 'error' => 'Fortnox-fel.'];
        }
        unset($result['status']);
        echo jeab_fortnox_pnr_json(is_array($result) ? $result : ['ok' => false, 'error' => 'Fortnox-fel.']);
        exit;
    }
    if ($jeabFortnoxAction === 'fortnox.leveransdatum_satt') {
        require_once __DIR__ . '/src/fortnox_leveransdatum.php';
        try {
            $result = jeab_fortnox_leveransdatum_satt(is_array($input) ? $input : []);
            http_response_code(!empty($result['ok']) ? 200 : 400);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            $result = ['ok' => false, 'status' => 'fel', 'date' => '', 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            http_response_code(400);
            $msg = $e->getMessage();
            if ($msg === '' || preg_match('/bearer|access_token|refresh_token|client_secret/i', $msg)) {
                $msg = 'Fortnox-fel.';
            }
            $result = ['ok' => false, 'status' => 'fel', 'date' => '', 'error' => $msg];
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($jeabFortnoxAction === 'fortnox.order_relink' || $jeabFortnoxAction === 'fortnox.order_attach' || $jeabFortnoxAction === 'fortnox.order_attach_undo' || $jeabFortnoxAction === 'fortnox.invoice_order_lookup' || $jeabFortnoxAction === 'fortnox.invoice_compare' || $jeabFortnoxAction === 'fortnox.order_export' || $jeabFortnoxAction === 'fortnox.orders_read') {
        require_once __DIR__ . '/src/fortnox_order_link.php';
        try {
            $result = jeab_fortnox_order_link_dispatch(is_array($input) ? $input : []);
            http_response_code(!empty($result['ok']) ? 200 : 400);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            $result = ['ok' => false, 'error' => $e->getMessage()];
        } catch (Throwable) {
            http_response_code(400);
            $result = ['ok' => false, 'error' => 'Fortnox-fel.'];
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Okänd åtgärd.']);
    exit;
}

if (str_starts_with((string) ($input['action'] ?? ''), 'webshop.') && function_exists('jeab_webshop_hook_authorized')) {
    if (!jeab_webshop_hook_authorized()) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Fel token.']);
        exit;
    }
    try {
        $result = jeab_webshop_hook($store, $input);
        http_response_code(!empty($result['ok']) ? 200 : ((string) ($result['error'] ?? '') === 'not_found' ? 404 : 400));
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        $result = ['ok' => false, 'error' => $e->getMessage()];
    } catch (RuntimeException $e) {
        // Ordrarnas SQL går inte att läsa (webshop.php jeab_webshop_sql_fail). Inget skrivs.
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('webshop.hook', $e::class . ': ' . $e->getMessage());
        }
        http_response_code(503);
        $result = ['ok' => false, 'error' => 'sql_unavailable'];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (function_exists('jeab_einstein_inbound_authorized') && jeab_einstein_inbound_authorized()) {
    $result = jeab_einstein_inbound(is_array($input) ? $input : []);
    http_response_code(!empty($result['ok']) ? 200 : 404);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (function_exists('jeab_bokmalen_ordrar_inbound_authorized') && jeab_bokmalen_ordrar_inbound_authorized()) {
    $result = jeab_bokmalen_ordrar_inbound(is_array($input) ? $input : []);
    $code = 200;
    if (empty($result['ok'])) {
        $err = (string) ($result['error'] ?? '');
        $code = $err === 'not_found' ? 404 : 400;
    }
    http_response_code($code);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$token = jeab_ensure_incoming_token($store);
if (!jeab_incoming_token_ok($token)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Fel token.']);
    exit;
}

try {
    $parsed = jeab_parse_incoming($input);
    if ($parsed['type'] === 'reminder') {
        $row = jeab_create_reminder($store, $parsed['title'], $parsed['due_at'], $parsed['note'], null, $parsed);
        echo json_encode(['ok' => true, 'type' => 'reminder', 'data' => jeab_reminder_payload($row)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($parsed['type'] === 'sms' || $parsed['type'] === 'mail') {
        $row = $store->getBooking((int) $parsed['booking_id']);
        if (!$row) {
            throw new InvalidArgumentException('Bokningen finns inte.');
        }
        jeab_calendar_log_append($parsed['type'], $row, $parsed['occurred_at']);
        echo json_encode(['ok' => true, 'type' => $parsed['type'], 'booking_id' => (int) $row['id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $row = jeab_create_booking(
        $store,
        $parsed['customer'],
        $parsed['assignees'],
        $parsed['starts_at'],
        $parsed['ends_at'],
        $parsed['job_type'],
        $parsed
    );
    echo json_encode(['ok' => true, 'type' => 'booking', 'data' => jeab_booking_payload($row)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
