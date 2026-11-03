<?php

declare(strict_types=1);

$jeabBootClock = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
$GLOBALS['_JEAB_BOOT_T0'] = is_numeric($jeabBootClock) ? (float) $jeabBootClock : microtime(true);

require_once __DIR__ . '/src/deploy_guard.php';
jeab_deploy_guard();

// Graph-validering och notiser. Loopia släpper tom User-Agent hit, inte till graph-hook.php.
// Filen lämnas kvar. En trasig eller saknad graph-hook.php får inte stoppa inloggningen.
if ((string) ($_GET['graph_hook'] ?? '') === '1') {
    $jeabGraphHook = __DIR__ . '/graph-hook.php';
    if (is_file($jeabGraphHook) && is_readable($jeabGraphHook)) {
        require $jeabGraphHook;
    }
    exit;
}

require_once __DIR__ . '/src/shield.php';

// Loopia nginx ignorerar .htaccess. /bokning/ är en katalog utan körd
// index, så anropet landar här som kampanjsidan om vi inte styr om.
$jeabUriPath = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/'));
if (jeab_request_is_bokning_index($jeabUriPath)) {
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Location: ' . jeab_bokning_apex_target(), true, 302);
    exit;
}

// /fullmakt/s/{token} finns inte som fil och hamnar här.
// Kundlänken ska öppna signeringen utan kampanjkod.
if (preg_match('#^/fullmakt/s/([A-Za-z0-9_-]+)/?$#', $jeabUriPath, $jeabFullmaktMatch) === 1) {
    $_GET['token'] = $jeabFullmaktMatch[1];
    require __DIR__ . '/fullmakt/sign.php';
    exit;
}
if (preg_match('#^/fullmakt/tack/?$#', $jeabUriPath) === 1) {
    require __DIR__ . '/fullmakt/tack.php';
    exit;
}
if (preg_match('#^/fullmakt/pdf\.php$#', $jeabUriPath) === 1) {
    require __DIR__ . '/fullmakt/pdf.php';
    exit;
}
if ($jeabUriPath === '/fullmakt' || $jeabUriPath === '/fullmakt/') {
    require __DIR__ . '/fullmakt/index.php';
    exit;
}

require_once __DIR__ . '/src/bootstrap.php';
if (function_exists('jeab_demo_mode') && jeab_demo_mode() && ($jeabUriPath === '/robots.txt')) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo "User-agent: *\nDisallow: /\n";
    exit;
}
$jeabDeferPageModules = function_exists('jeab_view_cache_defer_modules') && jeab_view_cache_defer_modules();
if (!$jeabDeferPageModules) {
    jeab_bootstrap_load_page_modules();
}
require_once __DIR__ . '/src/layout.php';
jeab_timing_begin();

if (jeab_csrf_refresh_request() && function_exists('jeab_auth_ok') && jeab_auth_ok()) {
    try {
        jeab_csrf_refresh_respond();
    } catch (Throwable $e) {
        $busy = $e instanceof JeabDbBusyException || (function_exists('jeab_pdo_is_max_user_connections') && jeab_pdo_is_max_user_connections($e));
        if ($busy && function_exists('jeab_pdo_busy_response')) {
            jeab_pdo_busy_response($e);
        }
        http_response_code($busy ? 503 : 500);
        header('Content-Type: application/json; charset=utf-8');
        $public = function_exists('jeab_public_error_text') ? jeab_public_error_text($e) : 'Något gick fel.';
        echo json_encode(['ok' => false, 'error' => $public], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

try {
    if (jeab_inkorg_ix_part() !== '') {
        $store = jeab_inkorg_part_enter();
    } else {
    jeab_boot_session();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['action'] ?? '') === 'logout') {
        if (jeab_csrf_ok()) {
            jeab_auth_logout();
            jeab_redirect(jeab_url('index.php'));
        }
    }
    jeab_gate();
    if (function_exists('jeab_timing_click_request') && jeab_timing_click_request()) {
        jeab_timing_click_respond();
    }
    $jeabPrefetch = function_exists('jeab_request_is_prefetch') && jeab_request_is_prefetch();
    if (function_exists('jeab_auth_ok') && jeab_auth_ok() && function_exists('jeab_send_guard_schedule') && !jeab_request_skips_send_guard()) {
        jeab_send_guard_schedule();
    }
    if (function_exists('jeab_auth_ok') && jeab_auth_ok() && function_exists('jeab_mail_queue_schedule_fallback') && !$jeabPrefetch) {
        jeab_mail_queue_schedule_fallback();
    }
    if ($jeabPrefetch && PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        // Förhämtning får inte byta Inkorgs kontor. Bolaget i adressen
        // skrivs inte i sessionen innan den stängs.
        session_write_close();
    }
    if (empty($jeabDeferPageModules) && function_exists('jeab_session_release_before_store')) {
        jeab_session_release_before_store();
    }
    $store = jeab_boot_store();
    if (function_exists('jeab_auth_recheck_or_login') && !jeab_auth_recheck_or_login()) {
        jeab_render_login(null, 0);
        exit;
    }
    jeab_mark_html_page_request();
    if (function_exists('jeab_tick_page_alarm')) {
        jeab_tick_page_alarm($store);
    }
    if (!empty($jeabDeferPageModules) && function_exists('jeab_view_cache_serve') && jeab_view_cache_serve($store)) {
        exit;
    }
    }
} catch (Throwable $e) {
    $busy = $e instanceof JeabDbBusyException || (function_exists('jeab_pdo_is_max_user_connections') && jeab_pdo_is_max_user_connections($e));
    if ($busy && function_exists('jeab_pdo_busy_response')) {
        jeab_pdo_busy_response($e);
    }
    http_response_code($busy ? 503 : 500);
    header('Content-Type: text/html; charset=utf-8');
    if (function_exists('jeab_log_uncaught')) {
        jeab_log_uncaught('app', $e);
    } elseif (function_exists('jeab_log_app_error')) {
        jeab_log_app_error('app', $e->getMessage());
    }
    $public = function_exists('jeab_public_error_text') ? jeab_public_error_text($e) : ($busy ? $e->getMessage() : 'Något gick fel.');
    if ($busy && function_exists('jeab_wants_json') && jeab_wants_json()) {
        jeab_json(['ok' => false, 'error' => $public], 503);
    }
    $jeabErrTitle = function_exists('jeab_demo_title') ? jeab_demo_title('JEAB') : 'JEAB';
    echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($jeabErrTitle, ENT_QUOTES, 'UTF-8') . '</title><p>' . htmlspecialchars($public, ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

if (!empty($jeabDeferPageModules)) {
    jeab_bootstrap_load_page_modules();
    if (function_exists('jeab_session_release_before_store')) {
        jeab_session_release_before_store();
    }
}

$page = jeab_page_slug((string) ($_GET['p'] ?? (function_exists('jeab_app_home_slug') ? jeab_app_home_slug() : 'reminders')));
if (function_exists('jeab_app_home_slug') && !isset($_GET['p']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && jeab_app_home_slug() !== 'reminders') {
    header('Location: ' . jeab_url('index.php?p=' . jeab_app_home_slug()), true, 302);
    exit;
}
if (function_exists('jeab_app_page_module')) {
    $jeabMod = jeab_app_page_module($page);
    if ($jeabMod !== null && !jeab_app_module_on($jeabMod)) {
        jeab_app_module_off_page();
    }
    jeab_app_settings_route($page);
}
$jeabPostAction = (string) ($_POST['action'] ?? '');
if (function_exists('jeab_demo_feature_off') && jeab_demo_feature_off('kalkyl')
    && ($page === 'offert' || str_starts_with($jeabPostAction, 'offert.'))) {
    jeab_demo_forbid_feature('kalkyl');
}
if ($page === 'inkorg-bilaga' && function_exists('jeab_hs_import_send_attachment')) {
    jeab_hs_import_send_attachment((string) ($_GET['m'] ?? ''), (int) ($_GET['n'] ?? -1));
    exit;
}
$flash = null;
$error = null;

if (function_exists('jeab_demo_route_guard')) {
    jeab_demo_route_guard($page, (string) ($_POST['action'] ?? ''));
}

if ($page === 'rot' || $page === 'manuellorder') {
    $carry = $_GET;
    unset($carry['p']);
    $code = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') ? 307 : 302;
    jeab_redirect(jeab_manuell_order_url($carry), $code);
}

if ($page === 'inkorg-kontakter') {
    jeab_redirect(jeab_url('index.php?p=kontakter'), 302);
}

if ($page === 'manuell-order') {
    require_once __DIR__ . '/src/manuell_order.php';
    jeab_manuell_order_page();
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = '';
    try {
        jeab_merge_json_request();
        jeab_require_csrf();
        $action = (string) ($_POST['action'] ?? '');
        jeab_bootstrap_load_action_modules($action);
        $jeabOfferActions = ['offert.enable', 'offert.test_preview', 'offert.test_send', 'offert.backfill_preview', 'offert.backfill_send'];
        if (in_array($action, $jeabOfferActions, true)) {
            jeab_utskick_handle_offer_post();
        }
        if ($action === 'reminder.tap') {
            jeab_release_session();
        } elseif ($action === 'reminder.untap') {
            jeab_release_session();
        }
        $flash = jeab_handle_action($store, $action);
        if ($action !== 'ordrar.delete' && str_starts_with($action, 'ordrar.') && function_exists('jeab_ordrar_repair_saved')) {
            $repairId = 0;
            $purchaseNow = $GLOBALS['_JEAB_LAST_PURCHASE'] ?? null;
            if (is_array($purchaseNow)) {
                $repairId = (int) ($purchaseNow['reminder_id'] ?? 0);
            }
            if ($repairId <= 0) {
                $repairId = (int) ($_POST['id'] ?? 0);
            }
            try {
                $repaired = jeab_ordrar_repair_saved($repairId);
                if (is_array($repaired)) {
                    $GLOBALS['_JEAB_LAST_PURCHASE'] = $repaired;
                    $GLOBALS['_JEAB_LAST_ROW'] = $repaired;
                }
            } catch (Throwable) {
            }
        }
        if (jeab_wants_json()) {
            if (in_array($action, ['order.fastighet_refresh', 'order.fastighet_undo', 'order.find'], true)) {
                $payload = $GLOBALS['_JEAB_FASTIGHET_PAYLOAD'] ?? ['ok' => false, 'error' => 'Inget svar.'];
                if (!is_array($payload)) {
                    $payload = ['ok' => false, 'error' => 'Inget svar.'];
                }
                $status = (int) ($payload['status'] ?? 200);
                unset($payload['status']);
                if (function_exists('jeab_fastighet_scrub_public')) {
                    $scrubbed = jeab_fastighet_scrub_public($payload);
                    if (is_array($scrubbed)) {
                        $payload = $scrubbed;
                    }
                }
                jeab_json($payload, $status);
            }
            if (str_starts_with($action, 'ordrar.') && function_exists('jeab_ordrar_external_live')) {
                jeab_ordrar_external_live(false);
            }
            $last = $GLOBALS['_JEAB_LAST_ROW'] ?? null;
            $payload = ['ok' => true, 'row' => $last];
            if ($action === 'kontakter.save' || (str_starts_with($action, 'ordrar.') && (string) ($_POST['v3'] ?? '') === '1')) {
                // Kontakter och nya Ordrar visar beskedet som toast.
                $payload['flash'] = $flash;
            }
            if ($action === 'ordrar.objekt' && is_array($last)) {
                // N-53: orörda fält fylls från Kontakter (enda källan), aldrig från orderns kopia.
                $payload['contact'] = jeab_ordrar_objekt_contact_fill($last);
            }
            if ($action === 'ordrar.nasta' && is_array($last)) {
                $payload = array_merge($payload, jeab_ordrar_ajax_nasta_payload($last, $flash));
            } elseif (in_array($action, ['ordrar.spara', 'ordrar.slutfor', 'ordrar.fortnox_utan_bokning'], true) && is_array($last)) {
                $stop = 'bekrafta';
                if ($action === 'ordrar.slutfor' || $action === 'ordrar.fortnox_utan_bokning') {
                    $stop = (jeab_ordrar_is_booked($last) || jeab_ordrar_fakturering_done($last) || trim((string) ($last['fortnox_order'] ?? '')) !== '')
                        ? 'fakturering'
                        : 'bekrafta';
                }
                $payload = array_merge($payload, jeab_ordrar_ajax_kind_payload($last, $stop, $flash));
            } elseif ($action === 'ordrar.solkoll' && is_array($last)) {
                $payload['status'] = $flash !== '' ? $flash : 'Solkoll klart.';
                $payload['stop'] = 'orderrader';
                $payload['solkoll_html'] = jeab_solkoll_card_html($last, (string) ($last['company'] ?? jeab_selected_company()), (int) ($last['reminder_id'] ?? 0));
            } elseif ($action === 'ordrar.orderrader' && is_array($last)) {
                $payload['status'] = $flash !== '' ? $flash : 'Sparad.';
                $payload['stop'] = 'orderrader';
            } elseif ($action === 'ordrar.install_note' && is_array($last)) {
                $payload['status'] = $flash !== '' ? $flash : 'Sparad.';
                $payload['stop'] = 'bekrafta';
            } elseif (in_array($action, ['ordrar.boka', 'ordrar.bestall', 'ordrar.bestall_steg', 'ordrar.boka_bestall', 'ordrar.advance', 'ordrar.fullmakt_resend', 'ordrar.fullmakt_ny', 'ordrar.foranmalan_resend', 'ordrar.seom_customer_send', 'ordrar.vad', 'ordrar.fakturering', 'ordrar.payout_retry'], true) && is_array($last)) {
                $from = trim((string) ($_POST['from'] ?? ''));
                if ($action === 'ordrar.advance') {
                    $stop = jeab_ordrar_solar_next_stop($last, $from !== '' ? $from : 'boka');
                } elseif ($action === 'ordrar.fullmakt_resend' || $action === 'ordrar.fullmakt_ny') {
                    $stop = ($from === 'boka_bestall' || $from === 'bestall' || $from === 'eon') ? $from : 'eon';
                } elseif ($action === 'ordrar.foranmalan_resend' || $action === 'ordrar.seom_customer_send') {
                    $stop = 'eon';
                } elseif ($action === 'ordrar.boka_bestall') {
                    if (jeab_ordrar_wants_advance($_POST) && jeab_ordrar_is_booked($last) && jeab_ordrar_bestalld($last)) {
                        $stop = jeab_ordrar_solar_next_stop($last, 'boka_bestall');
                    } else {
                        $stop = 'boka_bestall';
                    }
                } elseif ($action === 'ordrar.boka') {
                    $stop = jeab_ordrar_is_solbatteri($last) ? 'bestall' : 'fakturering';
                } elseif ($action === 'ordrar.vad') {
                    $stop = jeab_ordrar_wants_advance($_POST) ? 'spec' : 'bekrafta';
                } elseif ($action === 'ordrar.payout_retry') {
                    $stop = 'fakturering';
                } elseif ($action === 'ordrar.fakturering') {
                    $stop = 'fakturering';
                } elseif ($action === 'ordrar.bestall_steg') {
                    $stop = jeab_ordrar_wants_advance($_POST) && jeab_ordrar_bestalld($last) ? 'fakturering' : 'bestall';
                } elseif (jeab_ordrar_is_solbatteri($last) && jeab_ordrar_bestalld($last)) {
                    $stop = jeab_ordrar_solar_next_stop($last, 'bestall');
                } else {
                    $stop = 'bestall';
                }
                $payload = array_merge($payload, jeab_ordrar_ajax_stop_payload($last, $stop, $flash));
            }
            if (is_array($last) && !empty($last['due_at'])) {
                $payload['due'] = jeab_reminder_due_with_clock(jeab_format_due($last['due_at']), (string) $last['due_at']);
                $payload['due_contact'] = jeab_format_due_contact($last['due_at']);
                $payload['due_value'] = jeab_due_input_value($last['due_at']);
                $payload['due_late'] = !$last['completed_at'] && $last['due_at'] < jeab_now();
                $payload['today'] = (new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
            }
            if (is_array($last) && array_key_exists('ringt', $last)) {
                $payload['heading'] = jeab_reminder_heading($last);
                $payload['tap_total'] = jeab_reminder_tap_total($last);
                $payload['tap_label'] = jeab_reminder_tap_total_label($last);
            }
            if ($action === 'reminder.create' && is_array($last)) {
                $payload['reused'] = !empty($last['reused']);
                $payload['flash'] = $flash;
                $createQ = function_exists('jeab_reminder_create_query_extra')
                    ? jeab_reminder_create_query_extra($last)
                    : array_merge(jeab_reminder_saved_query($last), ['flash' => $flash !== '' ? $flash : null]);
                $payload['next'] = jeab_reminders_url($createQ);
                $payload['shown'] = !empty($GLOBALS['_JEAB_REMINDER_SHOWN']);
                $closedTid = function_exists('jeab_reminder_create_closed_tid') ? jeab_reminder_create_closed_tid() : '';
                if ($closedTid !== '') {
                    $payload['closed_thread'] = $closedTid;
                    $payload['reopen'] = jeab_url('index.php?p=inkorg');
                }
            }
            if (is_array($last) && in_array($action, ['reminder.create', 'reminder.toggle', 'reminder.snooze', 'reminder.update', 'reminder.due'], true)) {
                $payload['id'] = (int) ($last['id'] ?? 0);
                $payload['html'] = jeab_reminder_row($last, $action === 'reminder.toggle' && empty($last['completed_at']));
                $payload['tags'] = jeab_parse_tags($last['tags'] ?? []);
                if ($action === 'reminder.create' && isset($GLOBALS['_JEAB_REMINDER_COUNTS']) && is_array($GLOBALS['_JEAB_REMINDER_COUNTS'])) {
                    $payload['counts'] = $GLOBALS['_JEAB_REMINDER_COUNTS'];
                } else {
                    $payload['counts'] = jeab_reminder_view_counts($store);
                }
                unset($GLOBALS['_JEAB_REMINDER_COUNTS'], $GLOBALS['_JEAB_REMINDER_SHOWN']);
            }
            if ($action === 'reminder.tag' && !empty($GLOBALS['_JEAB_OPEN_ORDRAR'])) {
                $purchase = $GLOBALS['_JEAB_LAST_PURCHASE'] ?? null;
                $rid = is_array($purchase) ? (int) ($purchase['reminder_id'] ?? 0) : (int) ($_POST['id'] ?? 0);
                $company = is_array($purchase)
                    ? (string) ($purchase['company'] ?? jeab_selected_company())
                    : jeab_selected_company();
                $payload['next'] = jeab_ordrar_url([
                    'id' => $rid,
                    'co' => $company,
                    'flash' => $flash !== '' ? $flash : null,
                    'stop' => 'bekrafta',
                ]);
            }
            $vunnenNotice = trim((string) ($GLOBALS['_JEAB_VUNNEN_NOTICE'] ?? ''));
            if ($vunnenNotice !== '') {
                $payload['warn'] = $vunnenNotice;
            }
            jeab_json($payload);
        }
        jeab_redirect(jeab_after_action($page, $action, $flash));
    } catch (Throwable $e) {
        $busy = $e instanceof JeabDbBusyException || (function_exists('jeab_pdo_is_max_user_connections') && jeab_pdo_is_max_user_connections($e));
        if ($busy && function_exists('jeab_pdo_busy_response')) {
            jeab_pdo_busy_response($e);
        }
        $error = function_exists('jeab_throwable_log_message') ? jeab_throwable_log_message($e) : ($e->getMessage() !== '' ? $e->getMessage() : 'Något gick fel.');
        $code = function_exists('jeab_inkorg_error_status') ? jeab_inkorg_error_status($e) : ($e instanceof InvalidArgumentException ? 422 : 500);
        if (!$e instanceof JeabDbBusyException && function_exists('jeab_pdo_is_max_user_connections') && jeab_pdo_is_max_user_connections($e)) {
            $code = 503;
        }
        http_response_code($code);
        if (!function_exists('jeab_log_skip_expected_refusal') || !jeab_log_skip_expected_refusal($e)) {
            jeab_log_app_error(jeab_log_surface_from_action($action), $error);
        }
        $publicError = function_exists('jeab_public_error_text')
            ? jeab_public_error_text($e)
            : (($e instanceof InvalidArgumentException || $e instanceof JeabDbBusyException) ? ($e->getMessage() !== '' ? $e->getMessage() : 'Något gick fel.') : 'Något gick fel.');
        if ($action !== 'ordrar.delete' && str_starts_with($action, 'ordrar.') && function_exists('jeab_ordrar_save_fail_text')) {
            $publicError = jeab_ordrar_save_fail_text($publicError);
        }
        if (jeab_wants_json()) {
            jeab_json(['ok' => false, 'error' => $publicError], $code);
        }
        $error = $publicError;
    }
}

try {
    jeab_render_page($store, $page, $flash, $error);
} catch (Throwable $e) {
    $busy = $e instanceof JeabDbBusyException || (function_exists('jeab_pdo_is_max_user_connections') && jeab_pdo_is_max_user_connections($e));
    if ($busy && function_exists('jeab_pdo_busy_response')) {
        jeab_pdo_busy_response($e);
    }
    http_response_code($busy ? 503 : 500);
    if (function_exists('jeab_log_uncaught')) {
        jeab_log_uncaught('app', $e);
    } else {
        jeab_log_app_error('app', $e->getMessage());
    }
    $public = function_exists('jeab_public_error_text') ? jeab_public_error_text($e) : ($busy ? $e->getMessage() : 'Något gick fel.');
    if (function_exists('jeab_wants_json') && jeab_wants_json()) {
        jeab_json(['ok' => false, 'error' => $public], $busy ? 503 : 500);
    }
    echo '<!doctype html><meta charset="utf-8"><p>' . htmlspecialchars($public, ENT_QUOTES, 'UTF-8') . '</p>';
}
jeab_finish_http_response();

function jeab_handle_action(JeabStore $store, string $action): string
{
    if (function_exists('jeab_restricted_is_solkampanj') && jeab_restricted_is_solkampanj() && $action !== 'offert.defaults') {
        throw new InvalidArgumentException('Inte behörig.');
    }
    switch ($action) {
        case 'reminder.create':
            $row = jeab_reminder_create_action($store, $_POST);
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return function_exists('jeab_reminder_create_flash')
                ? jeab_reminder_create_flash($row)
                : (!empty($row['reused']) ? 'Befintlig påminnelse.' : 'Sparad.');

        case 'reminder.toggle':
            // done=1/0 från «Klar · Ångra» sätter läget uttryckligen (ingen dubbelvändning).
            $want = array_key_exists('done', $_POST) ? ((string) $_POST['done'] === '1') : null;
            $row = jeab_toggle_reminder($store, (int) ($_POST['id'] ?? 0), $want);
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return !empty($row['completed_at']) ? 'Klar.' : 'Öppen igen.';

        case 'reminder.due':
            $dueId = (int) ($_POST['id'] ?? 0);
            $dueExisting = $dueId > 0 ? jeab_get_reminder($store, $dueId) : null;
            // H-REM-1: Ångra efter knuff skickar bara due_at. completed_at i
            // posten är ögonblicksbilden från före knuffen och får inte öppna
            // ett kort som markerats Klar efteråt. saveReminder läser om raden
            // i transaktionen, så Klar som landar samtidigt ligger kvar.
            $fields = jeab_reminder_due_fields($_POST, is_array($dueExisting) ? $dueExisting : null);
            $row = jeab_update_reminder($store, $dueId, $fields);
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Tid sparad.';

        case 'reminder.update':
            $fields = [];
            foreach (['title', 'customer', 'address', 'email', 'phone'] as $key) {
                if (array_key_exists($key, $_POST)) {
                    $fields[$key] = (string) $_POST[$key];
                }
            }
            if (array_key_exists('notes', $_POST) || array_key_exists('note', $_POST)) {
                $fields['note'] = (string) ($_POST['notes'] ?? $_POST['note'] ?? '');
            }
            if (array_key_exists('flagged', $_POST)) {
                $fields['flagged'] = !empty($_POST['flagged']);
            }
            if (array_key_exists('tags', $_POST)) {
                $fields['tags'] = $_POST['tags'];
            }
            if (array_key_exists('due_at', $_POST)) {
                $dueExisting = jeab_get_reminder($store, (int) ($_POST['id'] ?? 0));
                $fields['due_at'] = jeab_reminder_due_from_post(
                    jeab_parse_reminder_due((string) $_POST['due_at']),
                    is_array($dueExisting) ? (string) ($dueExisting['due_at'] ?? '') : null,
                    $_POST
                );
            }
            $row = jeab_update_reminder($store, (int) ($_POST['id'] ?? 0), $fields, true);
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            $id = (int) ($row['id'] ?? $_POST['id'] ?? 0);
            if ($id > 0 && array_key_exists('kind', $_POST)) {
                $kind = jeab_purchase_normalize_kind($_POST['kind'] ?? '');
                $purchase = jeab_purchase_get($id);
                if (is_array($purchase) && $kind !== null) {
                    $purchase['kind'] = $kind;
                    $purchase['updated_at'] = jeab_now();
                    jeab_purchase_put($purchase);
                }
            }
            return 'Sparad.';

        case 'reminder.tap':
            $row = jeab_tap_reminder($store, (int) ($_POST['id'] ?? 0), (string) ($_POST['field'] ?? ''));
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Sparad.';

        case 'reminder.untap':
            $row = jeab_untap_reminder($store, (int) ($_POST['id'] ?? 0), (string) ($_POST['field'] ?? ''));
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Ångrad.';

        case 'reminder.flag':
            $row = jeab_toggle_flag($store, (int) ($_POST['id'] ?? 0));
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return !empty($row['flagged']) ? 'Flaggad.' : 'Avflaggad.';

        case 'reminder.tag':
            $tag = jeab_normalize_tag((string) ($_POST['tag'] ?? ''));
            $id = (int) ($_POST['id'] ?? 0);
            $before = $id > 0 ? jeab_get_reminder($store, $id) : null;
            $hadVunnen = is_array($before) && in_array('vunnen', jeab_parse_tags($before['tags'] ?? []), true);
            $row = jeab_toggle_reminder_tag($store, $id, (string) ($_POST['tag'] ?? ''));
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            $on = $tag !== null && in_array($tag, $row['tags'] ?? [], true);
            if ($tag === 'vunnen' && $hadVunnen && !$on) {
                if ((string) ($_POST['drop_related'] ?? '') === '1') {
                    $dropped = jeab_reminder_vunnen_drop_related($store, $row);
                    $fresh = $id > 0 ? jeab_get_reminder($store, $id) : null;
                    if (is_array($fresh)) {
                        $row = $fresh;
                        $GLOBALS['_JEAB_LAST_ROW'] = $row;
                    }
                    $notice = jeab_reminder_vunnen_drop_message($dropped);
                    $GLOBALS['_JEAB_VUNNEN_NOTICE'] = $notice;
                    return $notice;
                }
                $notice = jeab_reminder_vunnen_untag_notice($store, $row);
                if ($notice !== '') {
                    $GLOBALS['_JEAB_VUNNEN_NOTICE'] = $notice;
                    return $notice;
                }
            }
            if ($tag === 'vunnen' && $on && !$hadVunnen) {
                $rid = (int) ($row['id'] ?? $id);
                $source = jeab_ordrar_vunnen_source($row);
                if (jeab_ordrar_vunnen_gaps($source, $row) === []) {
                    jeab_ordrar_backfill_vunnen($store, jeab_reminder_company($row), false, $rid);
                }
                $purchase = jeab_ordrar_vunnen_from_tag($store, $row);
                $GLOBALS['_JEAB_LAST_PURCHASE'] = $purchase;
                $GLOBALS['_JEAB_OPEN_ORDRAR'] = true;
                // Påminnelsen klarmarkerades efter att ordern skapats. Svara med den färska raden.
                $closed = jeab_get_reminder($store, $rid);
                if (is_array($closed)) {
                    $row = $closed;
                    $GLOBALS['_JEAB_LAST_ROW'] = $row;
                }
                return !empty($row['completed_at']) ? 'Vunnen: order skapad och påminnelsen klarmarkerad.' : 'Tagg sparad.';
            }
            return $on ? 'Tagg sparad.' : 'Tagg borttagen.';

        case 'reminder.snooze':
            $row = jeab_snooze_reminder($store, (int) ($_POST['id'] ?? 0), (string) ($_POST['by'] ?? ''));
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Knuffad.';

        case 'reminder.who':
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['assignee'] ?? $_POST['who'] ?? ''));
            if ($name === '') {
                throw new InvalidArgumentException('Välj ansvarig.');
            }
            $existing = $id > 0 ? jeab_get_reminder($store, $id) : null;
            $company = jeab_normalize_company($_POST['company'] ?? $_POST['co'] ?? null)
                ?? (is_array($existing) ? jeab_reminder_company($existing) : jeab_selected_company());
            $row = jeab_update_reminder($store, $id, [
                'assignee' => $name,
                'who' => $name,
                'company' => $company,
            ]);
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Sparad.';

        case 'purchase.start':
            $row = jeab_purchase_start($store, (int) ($_POST['id'] ?? 0), (string) ($_POST['kind'] ?? ''));
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            return '';

        case 'purchase.save':
            $row = jeab_purchase_save($store, $_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            return !empty($row['done']) ? 'Sparad.' : '';

        case 'ordrar.staff_owner':
            $row = jeab_ordrar_set_staff_owner((int) ($_POST['id'] ?? 0), (string) ($_POST['staff'] ?? $_POST['owner'] ?? ''));
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            return 'Tillhör sparad.';

        case 'ordrar.confirm':
        case 'ordrar.spara':
            $row = jeab_ordrar_save_questions($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Sparad.';

        case 'ordrar.nasta':
            $row = jeab_ordrar_save_questions($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Sparad.';

        case 'ordrar.slutfor':
            $row = jeab_ordrar_slutfor($store, $_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            $flash = jeab_ordrar_is_booked($row, $store)
                ? jeab_booking_saved_flash('Bokad.')
                : 'Order skapad.';
            $fortnoxNote = trim((string) ($row['fortnox_error'] ?? ''));
            if ($fortnoxNote !== '') {
                return $flash . ' ' . $fortnoxNote;
            }
            if (trim((string) ($row['fortnox_order'] ?? '')) !== '') {
                return $flash . ' Fortnox-order skapad.';
            }
            return $flash;

        case 'ordrar.fortnox_utan_bokning':
            $before = jeab_ordrar_require((int) ($_POST['id'] ?? 0));
            $hadOrder = trim((string) ($before['fortnox_order'] ?? '')) !== '';
            $row = jeab_ordrar_fortnox_utan_bokning($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            $orderNo = trim((string) ($row['fortnox_order'] ?? ''));
            if ($hadOrder && $orderNo !== '') {
                return 'Fortnox-order ' . $orderNo . ' finns redan.';
            }
            if ($orderNo !== '') {
                return 'Fortnox-order ' . $orderNo . ' skapad.';
            }
            return 'Fortnox-order skapades inte.';

        case 'ordrar.pick':
            $row = jeab_ordrar_save_pick($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return '';

        case 'ordrar.laddbox':
            $row = jeab_ordrar_save_laddbox($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Sparad.';

        case 'ordrar.batterisol':
            $row = jeab_ordrar_save_batterisol($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            if (jeab_ordrar_wants_advance($_POST) && jeab_ordrar_is_solbatteri($row) && jeab_ordrar_foranmalan_status($row) === 'A') {
                $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
                if (trim((string) ($row['fullmakt_start_at'] ?? '')) !== '' && trim((string) ($steps['foranmald'] ?? '')) === '') {
                    return 'Fullmakt startad.';
                }
                if (trim((string) ($row['seom_sam_sent_at'] ?? '')) !== '' && trim((string) ($steps['foranmald'] ?? '')) === '') {
                    return 'SEOM-blankett skickad till Sam.';
                }
                if (trim((string) ($steps['foranmald'] ?? '')) !== '') {
                    return 'Föranmälan skickad.';
                }
            }
            $fortnoxNote = trim((string) ($row['fortnox_error'] ?? ''));
            return $fortnoxNote !== '' ? 'Sparad. ' . $fortnoxNote : 'Sparad.';

        case 'ordrar.foranmalan':
            $row = jeab_ordrar_save_foranmalan($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return jeab_ordrar_is_utkast_post($_POST) ? 'Sparad.' : 'Föranmälan skickad.';

        case 'ordrar.foranmalan_resend':
            $row = jeab_ordrar_foranmalan_resend($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Föranmälan skickad igen.';

        case 'ordrar.plombbrytning':
            $row = jeab_ordrar_plombbrytning_save($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            $deliver = (string) ($row['_plomb_deliver'] ?? '');
            if ($deliver === 'sent') {
                return 'Plombbrytning skickad till Bokmalen.';
            }
            if ($deliver === 'kept') {
                return 'Plombbrytning är redan skickad till Bokmalen.';
            }
            return 'Sparad.';

        case 'ordrar.foranmalan-status':
            $row = jeab_ordrar_save_foranmalan_status($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Sparad.';

        case 'ordrar.foranmalan_status_live':
            $row = jeab_ordrar_require((int) ($_POST['id'] ?? 0));
            if (!function_exists('jeab_bokmalen_ordrar_status_read')) {
                throw new InvalidArgumentException('Kunde inte läsa status från Bokmalen.');
            }
            $read = jeab_bokmalen_ordrar_status_read($row);
            if (($read['ok'] ?? false) !== true) {
                throw new InvalidArgumentException('Kunde inte läsa status från Bokmalen.');
            }
            $fresh = function_exists('jeab_purchase_get') ? jeab_purchase_get((int) ($row['reminder_id'] ?? 0)) : null;
            $row = is_array($fresh) ? $fresh : $row;
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Status uppdaterad.';

        case 'ordrar.faktura_makulera':
            $row = jeab_ordrar_faktura_makulera($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Fakturan är makulerad.';

        case 'ordrar.faktura_kreditera':
            $row = jeab_ordrar_faktura_kreditera($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Fakturan är krediterad.';

        case 'ordrar.orderrader':
            $row = jeab_ordrar_save_orderrader($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Sparad.';

        case 'ordrar.install_note':
            $row = jeab_ordrar_save_install_note($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Sparad.';

        case 'ordrar.solkoll':
            $row = jeab_solkoll_run_order((int) ($_POST['id'] ?? 0));
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return trim((string) ($row['solkoll_note'] ?? '')) !== '' ? (string) $row['solkoll_note'] : 'Solkoll klart.';

        case 'ordrar.vad':
            $row = jeab_ordrar_save_vad($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Sparad.';

        case 'ordrar.fakturering':
            try {
                jeab_ordrar_fakturering_gate($_POST);
            } catch (InvalidArgumentException $e) {
                $row = jeab_ordrar_require((int) ($_POST['id'] ?? 0));
                $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
                $GLOBALS['_JEAB_LAST_ROW'] = $row;
                throw $e;
            }
            $row = jeab_ordrar_save_fakturering($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            $created = jeab_ordrar_fakturering_intent($_POST) === 'faktura';
            if ($created && jeab_ordrar_payout_any_failed($row)) {
                $detail = jeab_ordrar_payout_failed_errors($row);
                return 'Faktura skapad. Kreditering misslyckades för minst en utbetalning.' . ($detail !== '' ? ' ' . $detail : '');
            }
            if ($created) {
                return 'Faktura skapad.';
            }
            $changes = trim((string) ($GLOBALS['_JEAB_FAKTURA_CHANGES'] ?? ''));
            unset($GLOBALS['_JEAB_FAKTURA_CHANGES']);
            return $changes !== '' ? 'Sparad. ' . $changes : 'Sparad.';

        case 'ordrar.payout_retry':
            $row = jeab_ordrar_payout_retry($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return jeab_ordrar_payout_retry_flash($row, trim((string) ($_POST['payout_id'] ?? '')));

        case 'ordrar.bestall_steg':
            $row = jeab_ordrar_save_bestall_steg($store, $_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return jeab_ordrar_bestalld($row) && jeab_ordrar_wants_advance($_POST) ? 'Beställd.' : 'Sparad.';

        case 'ordrar.boka_bestall':
            $row = jeab_ordrar_save_boka_bestall($store, $_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            $status = jeab_ordrar_bestallning_status($row);
            if ($status === 'B') {
                return 'Sparad.';
            }
            if (jeab_ordrar_wants_advance($_POST) && jeab_ordrar_is_booked($row) && jeab_ordrar_bestalld($row)) {
                return $status === 'A' ? 'Beställd.' : 'Sparad.';
            }
            return 'Sparad.';

        case 'ordrar.bestall':
            $row = jeab_ordrar_save_bestall($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return jeab_ordrar_is_utkast_post($_POST) ? 'Sparad.' : 'Beställd.';

        case 'ordrar.stang':
            $row = jeab_ordrar_stang($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            if (array_key_exists('done', $_POST) && (string) $_POST['done'] === '0') {
                return 'Öppen igen.';
            }
            return 'Stängt.';

        case 'ordrar.manual_done':
            $row = jeab_ordrar_manual_done($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return jeab_ordrar_manual_done_on($row) ? 'Ordern är klarmarkerad.' : 'Klarmarkeringen är ångrad.';

        case 'ordrar.objekt':
            $row = jeab_ordrar_save_objekt($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Sparad.';

        case 'ordrar.delete':
            $row = jeab_ordrar_delete($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Raderad.';

        case 'ordrar.restore':
            $row = jeab_ordrar_restore($_POST, $store);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Radering ångrad.';

        case 'ordrar.seom_customer_send':
            $row = jeab_ordrar_require((int) ($_POST['id'] ?? 0));
            $row = jeab_seom_fullmakt_send_customer($row);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'SEOM-blankett skickad till kunden.';

        case 'ordrar.fullmakt_ny':
            $row = jeab_ellevio_fullmakt_new_from_input($_POST, $store);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            if (!empty($row['fullmakt_new_kept'])) {
                return 'En ny fullmakt skickades nyss. Inget mer skickades.';
            }
            if (trim((string) ($row['fullmakt_start_at'] ?? '')) !== '' && trim((string) ($row['fullmakt_error'] ?? '')) === '') {
                return 'Ny fullmakt startad. Den gamla länken ligger kvar.';
            }
            $newError = trim((string) ($row['fullmakt_error'] ?? ''));
            return $newError !== '' ? $newError : 'Ny fullmakt kunde inte startas.';

        case 'ordrar.fullmakt_resend':
            $row = jeab_ordrar_require((int) ($_POST['id'] ?? 0));
            $row = jeab_ellevio_fullmakt_resend($row, $store);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            if (trim((string) ($row['fullmakt_start_at'] ?? '')) !== '' && trim((string) ($row['fullmakt_error'] ?? '')) === '') {
                return 'Fullmakt startad.';
            }
            if (jeab_ellevio_fullmakt_was_mailed($row)) {
                $mailPath = function_exists('jeab_system_mail_path_label')
                    ? jeab_system_mail_path_label(trim((string) ($row['fullmakt_mail_path'] ?? '')))
                    : '';
                return $mailPath !== '' ? 'Fullmakt skickad via ' . $mailPath . '.' : 'Fullmakt skickad.';
            }
            $mailError = trim((string) ($row['fullmakt_error'] ?? ''));
            return $mailError !== '' ? $mailError : 'Mejlet kunde inte skickas.';

        case 'ordrar.advance':
            $row = jeab_ordrar_require((int) ($_POST['id'] ?? 0));
            $from = trim((string) ($_POST['from'] ?? ''));
            if ($from === 'eon') {
                $row = jeab_ordrar_foranmalan_nasta($store, $row);
            } elseif ($from === 'fardiganmal' || $from === 'fakturera') {
                $row = jeab_ordrar_stamp_today($row, 'fardiganmald');
                $row['updated_at'] = jeab_now();
                $row = jeab_purchase_put($row);
            }
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            if ($from === 'eon') {
                if (function_exists('jeab_ellevio_fullmakt_applies') && jeab_ellevio_fullmakt_applies($row)) {
                    return 'Fullmakt startad.';
                }
                if (function_exists('jeab_seom_fullmakt_applies') && jeab_seom_fullmakt_applies($row)) {
                    $seomStatus = jeab_ordrar_foranmalan_status($row);
                    if ($seomStatus === 'B' || $seomStatus === 'C') {
                        return 'Sparad.';
                    }
                    return 'SEOM-blankett skickad till Sam.';
                }
                return 'Föranmälan skickad.';
            }
            return '';

        case 'ordrar.boka':
            $row = jeab_ordrar_create_booking($store, $_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return jeab_booking_saved_flash('Bokad.');

        case 'ordrar.fortnox_invoice_owners':
            $res = jeab_ordrar_fortnox_invoice_owners($_POST);
            $GLOBALS['_JEAB_LAST_ROW'] = $res;
            return !empty($res['applied']) ? 'Fakturan uppdaterad.' : 'Kontrollerad.';

        case 'ordrar.fortnox_retry':
            $row = jeab_ordrar_fortnox_retry($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Fortnox-order skapad.';

        case 'ordrar.fortnox':
            $row = jeab_ordrar_create_fortnox($_POST);
            $GLOBALS['_JEAB_LAST_PURCHASE'] = $row;
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Fortnox-order skapad.';

        case 'booking.create':
            $postedCompany = jeab_normalize_company($_POST['company'] ?? null);
            if ($postedCompany !== null && function_exists('jeab_company_allowed') && !jeab_company_allowed($postedCompany)) {
                throw new InvalidArgumentException('Inte behörig.');
            }
            $row = jeab_create_booking(
                $store,
                (string) ($_POST['customer'] ?? ''),
                $_POST['assignees'] ?? [],
                (string) ($_POST['starts_at'] ?? ''),
                isset($_POST['ends_at']) ? (string) $_POST['ends_at'] : null,
                $_POST['job_types'] ?? ($_POST['job_type'] ?? null),
                $_POST
            );
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return jeab_booking_saved_flash('Bokad.');

        case 'booking.update':
            $row = jeab_update_booking($store, (int) ($_POST['id'] ?? 0), [
                'customer' => (string) ($_POST['customer'] ?? ''),
                'assignees' => $_POST['assignees'] ?? [],
                'starts_at' => (string) ($_POST['starts_at'] ?? ''),
                'ends_at' => (string) ($_POST['ends_at'] ?? ''),
                'job_type' => $_POST['job_types'] ?? ($_POST['job_type'] ?? []),
                'email' => $_POST['email'] ?? '',
                'phone' => $_POST['phone'] ?? '',
                'address' => $_POST['address'] ?? '',
                'notes' => $_POST['notes'] ?? '',
                'company' => $_POST['company'] ?? null,
            ]);
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return jeab_booking_saved_flash('Uppdaterad.');

        case 'booking.cancel':
            $row = jeab_cancel_booking($store, (int) ($_POST['id'] ?? 0));
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Avbokad.';

        case 'calendar_log.cleanup':
            // Engångsstädning av veckologgen (QA/test-rader). Bara JSON, bara
            // admin (Sam L:s egen inloggning eller agenter@flowstack.example.test). Kopia först.
            if (!jeab_wants_json()) {
                throw new InvalidArgumentException('Bara JSON.');
            }
            if (!function_exists('jeab_is_admin') || !jeab_is_admin()) {
                throw new InvalidArgumentException('Inte behörig.');
            }
            $rawIds = $_POST['ids'] ?? [];
            if (is_string($rawIds)) {
                $rawIds = preg_split('/[\s,]+/', $rawIds) ?: [];
            }
            $ids = array_values(array_filter(array_map('intval', is_array($rawIds) ? $rawIds : []), static fn(int $id): bool => $id > 0));
            $dry = !empty($_POST['dry']);
            $GLOBALS['_JEAB_LAST_ROW'] = jeab_calendar_log_cleanup($store, $ids, $dry);
            return $dry ? 'Kandidater.' : 'Städad.';

        case 'booking.delete_silent':
            // Tyst borttagning av EN testbokning: bara JSON, bara admin
            // (Sam L:s egen inloggning eller agenter@flowstack.example.test), namnet måste skrivas in. Kopia först; inget
            // mejl/sms/webhook. dry=1 räknar bara och lämnar inga personuppgifter.
            if (!jeab_wants_json()) {
                throw new InvalidArgumentException('Bara JSON.');
            }
            if (!function_exists('jeab_is_admin') || !jeab_is_admin()) {
                throw new InvalidArgumentException('Inte behörig.');
            }
            $GLOBALS['_JEAB_LAST_ROW'] = jeab_booking_delete_silent(
                $store,
                (int) ($_POST['id'] ?? 0),
                (string) ($_POST['confirm_name'] ?? ''),
                !empty($_POST['dry'])
            );
            return !empty($_POST['dry']) ? 'Kandidat.' : 'Borttagen.';

        case 'booking.remind':
            $booking = jeab_get_booking($store, (int) ($_POST['id'] ?? 0));
            if (!$booking) {
                throw new InvalidArgumentException('Bokningen finns inte.');
            }
            $row = jeab_reminder_from_booking($store, $booking);
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Påminnelse skapad.';

        case 'settings.webhook':
            if (!function_exists('jeab_may_edit_webhook') || !jeab_may_edit_webhook()) {
                throw new InvalidArgumentException('Inte behörig.');
            }
            jeab_save_outgoing_webhook($store, (string) ($_POST['outgoing_webhook_url'] ?? ''));
            return 'Zapier sparad.';

        case 'settings.incoming_token':
            jeab_rotate_incoming_token($store);
            return 'Ny token sparad.';

        case 'offert.defaults':
            jeab_offert_save_standard($store, $_POST);
            return 'Sparad.';

        case 'outlook.connect':
        case 'outlook.disconnect':
            throw new InvalidArgumentException('Kalendrarna läses direkt från Microsoft 365.');

        case 'inkop.list':
            $GLOBALS['_JEAB_LAST_ROW'] = ['mallar' => jeab_inkop_list()];
            return '';

        case 'inkop.save':
            $saved = jeab_inkop_save($_POST);
            $GLOBALS['_JEAB_LAST_ROW'] = ['mall' => $saved, 'mallar' => jeab_inkop_list()];
            return 'Mall sparad.';

        case 'inkop.company':
            $GLOBALS['_JEAB_LAST_ROW'] = jeab_inkop_set_company((string) ($_POST['company'] ?? ''));
            return '';

        case 'bugg.submit':
            return jeab_bug_submit($_POST, $_FILES);

        case 'sack.send':
            $result = jeab_sack_send($_POST);
            if (empty($result['ok'])) {
                throw new RuntimeException((string) ($result['error'] ?? 'Kunde inte skicka mejlet.'));
            }
            $GLOBALS['_JEAB_LAST_ROW'] = $result;
            return 'Hämtning skickad.';

        case 'inkorg.send_quote':
            $sent = jeab_inkorg_action($action);
            if (str_starts_with($sent, 'Offerten skickad.')) {
                $tid = trim((string) ($_POST['thread'] ?? ''));
                $rem = ($tid !== '' && function_exists('jeab_lead_find_reminder')) ? jeab_lead_find_reminder($tid, '') : null;
                $rid = is_array($rem) ? (int) ($rem['id'] ?? 0) : 0;
                if ($rid > 0) {
                    $GLOBALS['_JEAB_QUOTE_REMINDER'] = $rid;
                }
            }
            return $sent;

        case 'inkorg.relabel':
        case 'inkorg.handled':
        case 'inkorg.rule_save':
        case 'inkorg.rule_toggle':
        case 'inkorg.list_save':
        case 'inkorg.thread':
        case 'inkorg.read':
        case 'inkorg.assign':
        case 'inkorg.reply':
        case 'inkorg.compose':
        case 'inkorg.approve':
        case 'inkorg.draft_delete':
        case 'inkorg.draft_undo':
        case 'inkorg.tag_save':
        case 'inkorg.tag_delete':
        case 'inkorg.view_save':
        case 'inkorg.view_delete':
        case 'inkorg.list_delete':
        case 'inkorg.rule_delete':
        case 'inkorg.rule_move':
        case 'inkorg.token':
        case 'inkorg.import_helpscout':
        case 'inkorg.clear_source':
        case 'inkorg.hs_senders':
        case 'inkorg.stats':
        case 'inkorg.graph_cutover':
        case 'inkorg.dedup_scan':
        case 'inkorg.fortnox_kundlista':
        case 'inkorg.merge_duplicate':
        case 'inkorg.link_customer':
        case 'inkorg.graph_status':
        case 'inkorg.graph_catchup':
        case 'inkorg.reclassify':
        case 'inkorg.llm_key':
        case 'inkorg.trash':
        case 'inkorg.restore':
        case 'inkorg.rule_test':
        case 'inkorg.flag':
        case 'inkorg.learn_undo':
        case 'inkorg.settings_save':
        case 'inkorg.rebook_save':
        case 'inkorg.helpscout_save':
        case 'inkorg.malmo_hs_save':
        case 'inkorg.auto_save':
        case 'inkorg.rebook_run':
        case 'inkorg.example_delete':
        case 'inkorg.rules_grok_only':
        case 'inkorg.contact_save':
        case 'inkorg.contact_delete':
        case 'inkorg.contact_restore':
        case 'inkorg.unlinked_logins':
        case 'inkorg.contacts_backfill':
            return jeab_inkorg_action($action);

        case 'kontakter.save':
            $row = jeab_kontakt_save_input($_POST);
            $GLOBALS['_JEAB_LAST_ROW'] = jeab_kontakt_public($row);
            return 'Sparad.';

        case 'kontakter.delete':
            jeab_kontakt_delete((string) ($_POST['id'] ?? ''));
            $GLOBALS['_JEAB_LAST_ROW'] = ['id' => ''];
            return 'Raderad.';

        case 'kontakter.pnr':
            $pnr = jeab_kontakt_reveal_personnummer((string) ($_POST['id'] ?? ''), (string) ($_POST['slot'] ?? ''));
            $GLOBALS['_JEAB_LAST_ROW'] = [
                'id' => trim((string) ($_POST['id'] ?? '')),
                'personnummer' => $pnr,
            ];
            return '';

        case 'notes.create':
            $row = jeab_notes_create($_POST);
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Anteckning skapad.';

        case 'notes.update':
            $row = jeab_notes_update((int) ($_POST['id'] ?? 0), $_POST);
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Sparad.';

        case 'notes.delete':
            jeab_notes_delete((int) ($_POST['id'] ?? 0));
            $GLOBALS['_JEAB_LAST_ROW'] = ['id' => 0];
            return 'Borttagen.';

        case 'notes.photo':
            $file = is_array($_FILES['photo'] ?? null) ? $_FILES['photo'] : [];
            $row = jeab_notes_add_photo((int) ($_POST['id'] ?? 0), $file);
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Bild tillagd.';

        case 'notes.photo_delete':
            $row = jeab_notes_delete_photo((int) ($_POST['id'] ?? 0), (string) ($_POST['photo'] ?? ''));
            $GLOBALS['_JEAB_LAST_ROW'] = $row;
            return 'Bild borttagen.';

        case 'notiser.save':
            $file = is_array($_FILES['pdf'] ?? null) ? $_FILES['pdf'] : [];
            return jeab_notiser_save_upload((string) ($_POST['id'] ?? ''), (string) ($_POST['body'] ?? ''), $file, jeab_notiser_actor());

        case 'notiser.restore':
            jeab_notiser_restore_body((string) ($_POST['id'] ?? ''), jeab_notiser_actor());
            return 'Återställd.';

        case 'notiser.restore_pdf':
            jeab_notiser_restore_pdf((string) ($_POST['id'] ?? ''), jeab_notiser_actor());
            return 'PDF återställd.';

        case 'notiser.toggle':
            $on = (string) ($_POST['enabled'] ?? '') === '1';
            jeab_notiser_set_enabled((string) ($_POST['id'] ?? ''), $on, jeab_notiser_actor());
            return $on ? 'Notisen är på.' : 'Notisen är av.';

        case 'calendar.match_debug':
            jeab_json(jeab_calendar_match_debug(
                $store,
                (string) ($_POST['date'] ?? ''),
                (string) ($_POST['who'] ?? '')
            ));

        case 'order.find':
            $GLOBALS['_JEAB_FASTIGHET_PAYLOAD'] = function_exists('jeab_order_find')
                ? jeab_order_find($_POST)
                : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
            return '';

        case 'order.fastighet_refresh':
            $GLOBALS['_JEAB_FASTIGHET_PAYLOAD'] = function_exists('jeab_order_fastighet_refresh')
                ? jeab_order_fastighet_refresh($store, $_POST)
                : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
            return '';

        case 'order.fastighet_undo':
            $GLOBALS['_JEAB_FASTIGHET_PAYLOAD'] = function_exists('jeab_order_fastighet_undo')
                ? jeab_order_fastighet_undo($store, $_POST)
                : ['ok' => false, 'error' => 'Okänd åtgärd.', 'status' => 400];
            return '';

        default:
            throw new InvalidArgumentException('Okänd åtgärd.');
    }
}

function jeab_after_action(string $page, string $action, string $flash): string
{
    $q = ['flash' => $flash];
    if (str_starts_with($action, 'inkorg.')) {
        $url = jeab_inkorg_after_action($action, $flash);

        return jeab_quote_sent_with_reminder($url, $action, $flash, (int) ($GLOBALS['_JEAB_QUOTE_REMINDER'] ?? 0));
    }
    if (str_starts_with($action, 'notiser.')) {
        $flik = trim((string) ($_POST['flik'] ?? ''));
        if (!in_array($flik, ['alla', 'notiser', 'offerter'], true)) {
            $flik = 'notiser';
        }
        $q = ['p' => 'utskick', 'flik' => $flik, 'flash' => $flash];
        foreach (['co', 'ch', 'st', 'pr'] as $key) {
            $value = trim((string) ($_POST[$key] ?? ''));
            if ($value !== '') {
                $q[$key] = $value;
            }
        }
        return jeab_url('index.php?' . http_build_query($q));
    }
    if ($action === 'purchase.start' || str_starts_with($action, 'ordrar.')) {
        $purchase = $GLOBALS['_JEAB_LAST_PURCHASE'] ?? null;
        $id = is_array($purchase) ? (int) ($purchase['reminder_id'] ?? 0) : (int) ($_POST['id'] ?? 0);
        $company = is_array($purchase)
            ? (string) ($purchase['company'] ?? jeab_selected_company())
            : jeab_selected_company();
        $kind = is_array($purchase) ? jeab_ordrar_ui_kind($purchase['kind'] ?? '') : '';
        $solar = is_array($purchase) && jeab_ordrar_is_solbatteri($purchase);
        $stop = '';
        if ($action === 'ordrar.delete' || $action === 'ordrar.stang') {
            return jeab_ordrar_url([
                'co' => $company,
                'flash' => $flash !== '' ? $flash : null,
            ]);
        }
        if ($action === 'ordrar.pick' || $action === 'ordrar.spara' || $action === 'ordrar.confirm' || $action === 'ordrar.install_note') {
            $stop = 'bekrafta';
        } elseif ($action === 'ordrar.nasta') {
            $stop = $solar ? 'spec' : 'bekrafta';
        } elseif ($action === 'ordrar.slutfor' || $action === 'ordrar.fortnox_utan_bokning') {
            $bookedOrOrder = is_array($purchase) && (
                jeab_ordrar_is_booked($purchase)
                || jeab_ordrar_fakturering_done($purchase)
                || trim((string) ($purchase['fortnox_order'] ?? '')) !== ''
            );
            $stop = $bookedOrOrder ? 'fakturering' : 'bekrafta';
        } elseif ($action === 'ordrar.vad') {
            $stop = jeab_ordrar_wants_advance($_POST) ? 'spec' : 'bekrafta';
        } elseif ($action === 'ordrar.batterisol') {
            $stop = jeab_ordrar_wants_advance($_POST) ? 'orderrader' : 'spec';
        } elseif ($action === 'ordrar.foranmalan') {
            $stop = jeab_ordrar_is_utkast_post($_POST) ? 'eon' : 'fakturering';
        } elseif ($action === 'ordrar.foranmalan-status' || $action === 'ordrar.foranmalan_status_live' || $action === 'ordrar.foranmalan_resend' || $action === 'ordrar.plombbrytning') {
            $stop = 'eon';
        } elseif ($action === 'ordrar.faktura_makulera' || $action === 'ordrar.faktura_kreditera') {
            $stop = 'fakturering';
        } elseif ($action === 'ordrar.orderrader') {
            $stop = jeab_ordrar_wants_advance($_POST) ? 'bestall' : 'orderrader';
        } elseif ($action === 'ordrar.solkoll') {
            $stop = 'orderrader';
        } elseif ($action === 'ordrar.payout_retry') {
            $stop = 'fakturering';
        } elseif ($action === 'ordrar.fakturering') {
            $stop = 'fakturering';
        } elseif ($action === 'ordrar.bestall_steg') {
            $stop = (is_array($purchase) && jeab_ordrar_wants_advance($_POST) && jeab_ordrar_bestalld($purchase)) ? 'fakturering' : 'bestall';
        } elseif ($action === 'ordrar.boka_bestall') {
            if (!$solar || !is_array($purchase) || !jeab_ordrar_wants_advance($_POST) || !jeab_ordrar_is_booked($purchase) || !jeab_ordrar_bestalld($purchase)) {
                $stop = 'boka_bestall';
            } else {
                $stop = jeab_ordrar_solar_next_stop($purchase, 'boka_bestall');
            }
        } elseif ($action === 'ordrar.bestall') {
            if (!$solar || !is_array($purchase) || !jeab_ordrar_bestalld($purchase)) {
                $stop = 'bestall';
            } else {
                $stop = jeab_ordrar_solar_next_stop($purchase, 'bestall');
            }
        } elseif ($action === 'ordrar.seom_customer_send') {
            $stop = 'eon';
        } elseif ($action === 'ordrar.fullmakt_resend' || $action === 'ordrar.fullmakt_ny') {
            $from = trim((string) ($_POST['from'] ?? ''));
            $stop = ($from === 'boka_bestall' || $from === 'eon') ? $from : 'eon';
        } elseif ($action === 'ordrar.advance') {
            $from = trim((string) ($_POST['from'] ?? ''));
            $stop = is_array($purchase) ? jeab_ordrar_solar_next_stop($purchase, $from !== '' ? $from : '') : '';
        } elseif ($action === 'ordrar.boka') {
            $stop = $solar ? 'bestall' : 'fakturering';
        } elseif ($action === 'ordrar.fortnox') {
            $stop = 'fakturering';
        }
        if ($action !== 'ordrar.staff_owner' && is_array($purchase) && jeab_ordrar_identity_missing($purchase) !== [] && trim((string) ($purchase['fortnox_order'] ?? '')) === '') {
            $stop = 'bekrafta';
        }
        $stop = jeab_ordrar_keep_status_stop($action, $stop);
        $urlExtra = [
            'id' => $id,
            'co' => $company,
            'flash' => $flash !== '' ? $flash : null,
            'stop' => $stop,
        ];
        if ($action === 'ordrar.staff_owner') {
            foreach (jeab_ordrar_context_params($company) as $key => $value) {
                $urlExtra[$key] = $value;
            }
        }
        return jeab_ordrar_url($urlExtra);
    }
    if (str_starts_with($action, 'purchase.')) {
        $purchase = $GLOBALS['_JEAB_LAST_PURCHASE'] ?? null;
        $id = is_array($purchase) ? (int) ($purchase['reminder_id'] ?? 0) : (int) ($_POST['id'] ?? 0);
        $company = is_array($purchase)
            ? (string) ($purchase['company'] ?? jeab_selected_company())
            : jeab_selected_company();
        if ($action === 'purchase.save' && is_array($purchase) && !empty($purchase['done'])) {
            return jeab_reminders_url([
                'flash' => $flash,
                'open' => $id,
                'co' => $company,
            ]);
        }
        $step = $action === 'purchase.save' ? 2 : 1;
        return jeab_purchase_url([
            'id' => $id,
            'step' => $step,
            'co' => $company,
            'flash' => $flash !== '' ? $flash : null,
        ]);
    }
    if ($action === 'booking.remind') {
        return jeab_reminders_url(['flash' => $flash, 'open' => null]);
    }
    if (str_starts_with($action, 'outlook.') || str_starts_with($action, 'booking.') || $page === 'bookings') {
        $q['p'] = 'bookings';
        if (isset($_GET['w'])) {
            $q['w'] = (string) $_GET['w'];
        }
        if (isset($_GET['d'])) {
            $q['d'] = (string) $_GET['d'];
        }
        foreach (jeab_filter_url_params(jeab_booking_who_filter(), jeab_booking_job_filter()) as $filterKey => $filterValue) {
            $q[$filterKey] = $filterValue;
        }
        $q['co'] = jeab_selected_company();
        if (jeab_booking_view() === 'month') {
            $q['view'] = 'month';
        } elseif (jeab_booking_view() === 'day') {
            $q['view'] = 'day';
        }
        return jeab_url('index.php?' . http_build_query($q));
    }
    if (str_starts_with($action, 'inkop.')) {
        return jeab_manuell_order_url();
    }
    if ($action === 'sack.send') {
        return jeab_url('index.php?' . http_build_query([
            'p' => 'sack',
            'flash' => $flash !== '' ? $flash : null,
        ]));
    }
    if (str_starts_with($action, 'kontakter.')) {
        $id = trim((string) ($GLOBALS['_JEAB_LAST_ROW']['id'] ?? $_POST['id'] ?? ''));
        $q = ['p' => 'kontakter'];
        if ($id !== '') {
            $q['id'] = $id;
        }
        if ($flash !== '') {
            $q['flash'] = $flash;
        }
        return jeab_url('index.php?' . http_build_query($q));
    }
    if (str_starts_with($action, 'notes.')) {
        $last = $GLOBALS['_JEAB_LAST_ROW'] ?? null;
        $id = is_array($last) ? (int) ($last['id'] ?? 0) : (int) ($_POST['id'] ?? 0);
        $q = ['p' => 'notes'];
        if ($id > 0) {
            $q['id'] = $id;
        }
        if ($flash !== '') {
            $q['flash'] = $flash;
        }
        return jeab_url('index.php?' . http_build_query($q));
    }
    if ($page === 'offert' || str_starts_with($action, 'offert.')) {
        return jeab_url('index.php?' . http_build_query(['p' => 'offert', 'flash' => $flash]));
    }
    if ($page === 'zapier') {
        return jeab_url('index.php?' . http_build_query(['p' => 'zapier', 'flash' => $flash]));
    }
    if ($page === 'bugg' || str_starts_with($action, 'bugg.')) {
        return jeab_url('index.php?' . http_build_query(['p' => 'bugg', 'flash' => $flash]));
    }
    if ($action === 'reminder.tag' && !empty($GLOBALS['_JEAB_OPEN_ORDRAR'])) {
        $purchase = $GLOBALS['_JEAB_LAST_PURCHASE'] ?? null;
        $id = is_array($purchase) ? (int) ($purchase['reminder_id'] ?? 0) : (int) ($_POST['id'] ?? 0);
        $company = is_array($purchase)
            ? (string) ($purchase['company'] ?? jeab_selected_company())
            : jeab_selected_company();
        return jeab_ordrar_url([
            'id' => $id,
            'co' => $company,
            'flash' => $flash !== '' ? $flash : null,
            'stop' => 'bekrafta',
        ]);
    }
    $extra = ['flash' => $flash, 'open' => null];
    if ($action === 'reminder.create') {
        $last = $GLOBALS['_JEAB_LAST_ROW'] ?? null;
        if (is_array($last) && (int) ($last['id'] ?? 0) > 0) {
            $extra = function_exists('jeab_reminder_create_query_extra')
                ? array_merge($extra, jeab_reminder_create_query_extra($last))
                : array_merge($extra, jeab_reminder_saved_query($last));
            $extra['flash'] = $flash;
        }
    } elseif (str_starts_with($action, 'reminder.')) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $extra['open'] = $id;
        }
    }
    return jeab_reminders_url($extra);
}

function jeab_render_page(JeabStore $store, string $page, ?string $flash, ?string $error): void
{
    if (function_exists('jeab_timing_render')) {
        jeab_timing_render();
    }
    $flash = jeab_flash_text($flash ?: (isset($_GET['flash']) ? (string) $_GET['flash'] : null));

    if ($page === 'reminder') {
        $id = (int) ($_GET['id'] ?? 0);
        $to = jeab_reminders_url(['open' => $id > 0 ? $id : null]);
        jeab_redirect($to);
    }

    if ($page === 'outlook') {
        jeab_redirect(jeab_bookings_url());
    }

    if ($page === 'bookings') {
        $intent = (string) ($_GET['intent'] ?? '');
        // Bolag, filter och CSRF skrivs först. Logg, våg och HTML håller inte låset.
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg'
            && function_exists('jeab_release_session')
            && session_status() === PHP_SESSION_ACTIVE) {
            if (function_exists('jeab_selected_company')) {
                jeab_selected_company();
            }
            if (function_exists('jeab_booking_who_filter')) {
                jeab_booking_who_filter();
            }
            if (function_exists('jeab_booking_job_filter')) {
                jeab_booking_job_filter();
            }
            if (function_exists('jeab_csrf_session_token')) {
                jeab_csrf_session_token();
            }
            if (function_exists('jeab_csrf_token')) {
                jeab_csrf_token();
            }
            jeab_release_session();
        }
        if ($intent === 'reminder-names') {
            $company = jeab_normalize_company($_GET['co'] ?? null) ?? jeab_selected_company();
            jeab_json([
                'ok' => true,
                'hits' => jeab_search_reminder_names($store, (string) ($_GET['q'] ?? ''), $company),
            ]);
        }
        if ($intent === 'calendar-match-debug') {
            try {
                jeab_json(jeab_calendar_match_debug(
                    $store,
                    (string) ($_GET['date'] ?? ''),
                    (string) ($_GET['who'] ?? '')
                ));
            } catch (InvalidArgumentException $e) {
                if (function_exists('jeab_log_uncaught')) {
                    jeab_log_uncaught('calendar-match-debug', $e);
                }
                $public = function_exists('jeab_public_error_text') ? jeab_public_error_text($e) : 'Något gick fel.';
                jeab_json(['ok' => false, 'error' => $public], 400);
            }
        }
        if ($intent === 'calendar-log') {
            $jeabLogStarted = microtime(true);
            $win = jeab_calendar_visible_window();
            $lines = [];
            try {
                foreach (jeab_calendar_log_for_week($win['monday'], 100, $store, jeab_selected_company()) as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $line = jeab_calendar_log_line($entry);
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }
            } catch (Throwable $e) {
                error_log('calendar week log ' . $e::class);
            }
            jeab_timing_span('calendar_log', microtime(true) - $jeabLogStarted);
            jeab_json(['ok' => true, 'lines' => $lines]);
        }
        $openBooking = jeab_bookings_apply_open($store);
        if ($intent === 'calendar-bookings' || $intent === 'calendar-outlook') {
            jeab_calendar_respond_wave($store, $intent);
        }
        $jeabCalStarted = microtime(true);
        $fromReminderId = (int) ($_GET['from_reminder'] ?? 0);
        $fromReminder = null;
        if ($fromReminderId > 0) {
            $fromRow = jeab_get_reminder($store, $fromReminderId);
            if (is_array($fromRow)) {
                $fromReminder = jeab_reminder_booking_hit($fromRow);
            }
        }
        $win = jeab_calendar_visible_window();
        $weekOffset = (int) $win['weekOffset'];
        $monday = $win['monday'];
        $monthView = (bool) $win['monthView'];
        $bookings = [];
        $selectedInstallers = jeab_default_sheet_assignees();
        $selectedDay = isset($_GET['d']) ? (int) $_GET['d'] : -1;
        $body = require __DIR__ . '/src/pages/bookings.php';
        jeab_timing_span('calendar', microtime(true) - $jeabCalStarted);
        jeab_layout('Kalender · JEAB', 'bookings', $body);
        return;
    }

    if ($page === 'offert') {
        $body = require __DIR__ . '/src/pages/offert.php';
        jeab_layout('Kalkyl · JEAB', 'offert', $body);
        return;
    }

    if ($page === 'ordrar') {
        // Nya vyn (v3) är standard. ?v=2 visar gamla vyn som reserv (glidande 30 min i sessionen), ?v=3 tillbaka.
        $ordrarV3 = jeab_ordrar_v3_mode();
        if (array_key_exists('klart', $_GET)) {
            $q = $_GET;
            unset($q['klart']);
            $q['p'] = 'ordrar';
            jeab_redirect(jeab_url('index.php?' . http_build_query($q)));
        }
        $company = jeab_selected_company();
        $openId = (int) ($_GET['id'] ?? $_GET['open'] ?? 0);
        $jeabOrdrarYield = false;
        if ($openId > 0
            && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
            && PHP_SAPI !== 'cli'
            && PHP_SAPI !== 'phpdbg'
            && function_exists('jeab_release_session')
            && session_status() === PHP_SESSION_ACTIVE) {
            // F5 skickar anteckningens keepalive samtidigt som sidhämtningen.
            // Släpp låset så sparningen hinner skrivas innan ordern läses.
            jeab_release_session();
            $jeabOrdrarYield = true;
        }
        try {
        $open = $openId > 0 && function_exists('jeab_ordrar_visible_get') ? jeab_ordrar_visible_get($openId) : ($openId > 0 ? jeab_purchase_get($openId) : null);
        if (is_array($open) && (string) ($_GET['fortnox_kontroll'] ?? '') === '1'
            && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            // Bara läsning: Fortnox-orderns rader, summor och ansökan per person.
            jeab_json(jeab_ordrar_fortnox_kontroll($open));
        }
        if (is_array($open)) {
            $company = jeab_normalize_company($open['company'] ?? $company) ?? $company;
        }
        $GLOBALS['_JEAB_ORDRAR_V3'] = $ordrarV3 && is_array($open) && $openId > 0 && jeab_ordrar_v3_supports($open);
        $wantStop = jeab_ordrar_requested_stop();
        $jeabOrdrarStarted = microtime(true);
        if ($openId > 0 && !is_array($open)) {
            $open = function_exists('jeab_ordrar_visible_get') ? jeab_ordrar_visible_get($openId) : jeab_purchase_get($openId);
        }
        if ($openId > 0 && !is_array($open)) {
            $back = jeab_ordrar_url(['co' => $company] + jeab_ordrar_context_params($company));
            $body = '<section class="ordrar is-open" data-v3-root data-id="' . $openId . '">'
                . '<a class="v3-back" href="' . e($back) . '">← Tillbaka</a>'
                . '<h1>Ordern hittades inte</h1></section>';
            jeab_timing_span('order', microtime(true) - $jeabOrdrarStarted);
            jeab_layout('Ordrar · JEAB', 'ordrar', $body);
            return;
        }
        $ordrarRefresh = (string) ($_GET['refresh'] ?? '') === '1';
        // Vanlig öppning läser sparad data. Lantmäteriet och ArcGIS köas till tick, även på Uppdatera.
        if (!$ordrarRefresh && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && function_exists('jeab_ordrar_external_live')) {
            jeab_ordrar_external_live(false);
        } elseif ($ordrarRefresh && function_exists('jeab_page_blocks_external') && jeab_page_blocks_external()) {
            jeab_ordrar_external_live(false);
            if (function_exists('jeab_external_enqueue') && $openId > 0) {
                jeab_external_enqueue('order-lookup', ['id' => $openId]);
            }
        }
        if (is_array($open)) {
            $reminder = jeab_ordrar_reminder_for_row($open, $openId);
            $allowLookup = $ordrarRefresh && jeab_ordrar_identity_missing($open) !== [];
            $open = jeab_ordrar_prepare_open_identity($open, is_array($reminder) ? $reminder : null, $allowLookup);
            if ($ordrarRefresh) {
                $open = jeab_ordrar_persist_postnummer($open, is_array($reminder) ? $reminder : null);
            }
            // P-8: shopnumret som tidigare skrevs här syns vid nästa tick, eller efter en sparande POST.
            if ($ordrarRefresh) {
                $open = jeab_ordrar_sync_matched_owner($open, $reminder);
                // Hela ägarlistan från uppslaget ersätter automatiska ägare (handskrivna lämnas).
                $open = jeab_ordrar_sync_lookup_owners($open, is_array($reminder) ? $reminder : null);
            }
        }
        $openKind = is_array($open) ? (jeab_purchase_normalize_kind($open['kind'] ?? '') ?? '') : '';
        $openSolar = $openKind === 'batteri' || $openKind === 'sol';
        if (is_array($open)
            && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && $wantStop !== 'bekrafta'
            && (string) ($_POST['action'] ?? '') !== 'ordrar.objekt'
            && ($openSolar || trim((string) ($open['fortnox_order'] ?? '')) === '')) {
            try {
                $wasBooked = jeab_ordrar_is_booked($open);
                $open = jeab_ordrar_maybe_book_current($store, $open, $error);
                if (!$wasBooked && jeab_ordrar_is_booked($open)) {
                    $land = 'fakturering';
                    if ($openSolar && !jeab_ordrar_bestalld($open)) {
                        $land = 'bestall';
                    }
                    jeab_redirect(jeab_ordrar_url([
                        'id' => (int) ($open['reminder_id'] ?? $openId),
                        'co' => $company,
                        'flash' => 'Bokad.',
                        'stop' => $land,
                    ]));
                }
            } catch (Throwable $e) {
                $error = $e->getMessage() !== '' ? $e->getMessage() : 'Bokningen misslyckades.';
                jeab_log_app_error('ordrar', $error);
            }
        }
        if (!$ordrarRefresh && function_exists('jeab_ordrar_external_live')) {
            jeab_ordrar_external_live(false);
        }
        if (is_array($open) && $error && (string) ($_POST['action'] ?? '') === 'ordrar.objekt') {
            // Visa det som skrevs så felet kan rättas. Sparas inte.
            $GLOBALS['_JEAB_OBJEKT_POSTED'] = [
                'fastighetsbeteckning' => (string) ($_POST['objekt_fastighetsbeteckning'] ?? ($open['fastighetsbeteckning'] ?? '')),
            ];
        }
        if (is_array($open) && $error && (string) ($_POST['action'] ?? '') === 'ordrar.batterisol') {
            foreach (['kwh', 'vaxelriktare', 'anlaggnings_id'] as $key) {
                $posted = (string) ($_POST[$key] ?? '');
                if ($key === 'anlaggnings_id') {
                    $posted = jeab_ordrar_anlaggnings_id_digits($posted);
                } else {
                    $posted = trim($posted);
                }
                if ($posted !== '') {
                    $open[$key] = $posted;
                }
            }
            $postedKundpris = trim((string) ($_POST['kundpris'] ?? ''));
            $postedPrice = (int) round(jeab_purchase_amount($postedKundpris));
            if ($postedPrice > 0) {
                $open['kundpris'] = $postedPrice;
                unset($open['kundpris_text']);
            } elseif ($postedKundpris !== '') {
                $open['kundpris_text'] = $postedKundpris;
            }
        }
        if ($openId > 0) {
            $rows = [];
            $klartRows = [];
        } else {
            // B-021: CSRF och who/job skrivs först. Listan håller inte sessionslåset.
            if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg'
                && function_exists('jeab_release_session')
                && session_status() === PHP_SESSION_ACTIVE) {
                if (function_exists('jeab_csrf_session_token')) {
                    jeab_csrf_session_token();
                }
                if (function_exists('jeab_csrf_token')) {
                    jeab_csrf_token();
                }
                if (function_exists('jeab_filter_from_request')) {
                    jeab_filter_from_request($company, true);
                }
                jeab_release_session();
            }
            // Listan läses i sidan, efter att låset släppts. Öppna ordrar är
            // list_json. Allt klart skärs i SQL när inget filter ligger på.
            $rows = [];
            $klartRows = [];
        }
        if ($ordrarV3 && is_array($open) && $openId > 0 && jeab_ordrar_v3_supports($open)) {
            $body = require __DIR__ . '/src/pages/ordrar_v3.php';
        } else {
            $body = require __DIR__ . '/src/pages/ordrar.php';
            if ($ordrarV3 && is_array($open) && $openId > 0) {
                $body = jeab_ordrar_v3_fallback_note($openId, $company) . $body;
            } elseif (!$ordrarV3 && is_array($open) && $openId > 0 && jeab_ordrar_v3_supports($open)) {
                $body = jeab_ordrar_old_view_note($openId, $company) . $body;
            }
        }
        jeab_timing_span($openId > 0 ? 'order' : 'ordrar', microtime(true) - $jeabOrdrarStarted);
        jeab_layout('Ordrar · JEAB', 'ordrar', $body);
        } finally {
            if (!empty($jeabOrdrarYield) && function_exists('jeab_session_resume')) {
                jeab_session_resume();
            }
        }
        return;
    }

    if ($page === 'links') {
        $body = require __DIR__ . '/src/pages/links.php';
        jeab_layout('Länkar · JEAB', 'links', $body);
        return;
    }

    if ($page === 'inloggdiag') {
        if (!function_exists('jeab_login_diag_allowed') || !jeab_login_diag_allowed()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            echo 'Bara admin.';
            return;
        }
        $body = require __DIR__ . '/src/pages/inloggningar.php';
        jeab_layout('Användare · JEAB', 'links', $body);
        return;
    }

    if ($page === 'offertutskick') {
        if (!jeab_offert_utskick_allowed()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            echo 'Bara admin.';
            return;
        }
        if (jeab_utskick_should_redirect($page)) {
            jeab_redirect(jeab_utskick_legacy_target($page), 302);
        }
        jeab_utskick_render('offerter', null, [], 'utskick');
    }

    if ($page === 'notiser') {
        if ((string) ($_GET['fil'] ?? '') !== '') {
            jeab_notiser_send_file((string) $_GET['fil']);
            return;
        }
        if (jeab_utskick_should_redirect($page)) {
            jeab_redirect(jeab_utskick_legacy_target($page), 302);
        }
        jeab_utskick_render('notiser', null, [], 'notiser');
    }

    if ($page === 'utskick') {
        jeab_utskick_render(jeab_utskick_normalize_flik((string) ($_GET['flik'] ?? 'alla')), null, [], 'utskick');
    }

    if ($page === 'fullmakter') {
        require_once __DIR__ . '/src/fullmakt_signed.php';
        $vis = trim((string) ($_GET['vis'] ?? ''));
        if ($vis !== '') {
            jeab_fullmakt_signed_send($vis);
        }
        $rows = jeab_fullmakt_signed_rows();
        $body = require __DIR__ . '/src/pages/fullmakter.php';
        jeab_layout('E-signering · JEAB', 'links', $body);
        return;
    }

    if ($page === 'notes') {
        $photo = trim((string) ($_GET['photo'] ?? ''));
        if ($photo !== '') {
            jeab_notes_send_photo((int) ($_GET['id'] ?? 0), $photo);
        }
        $intent = (string) ($_GET['intent'] ?? '');
        if ($intent === 'list') {
            jeab_json([
                'ok' => true,
                'notes' => jeab_notes_list((string) ($_GET['q'] ?? ''), (string) ($_GET['section'] ?? '')),
            ]);
        }
        if ($intent === 'get') {
            $row = jeab_notes_get((int) ($_GET['id'] ?? 0));
            jeab_json(['ok' => $row !== null, 'row' => $row]);
        }
        $notes = jeab_notes_list();
        $openId = (int) ($_GET['id'] ?? 0);
        $open = $openId > 0 ? jeab_notes_get($openId) : ($notes[0] ?? null);
        $sections = jeab_notes_sections();
        $body = require __DIR__ . '/src/pages/notes.php';
        jeab_layout('Anteckningar · JEAB', 'notes', $body);
        return;
    }

    if ($page === 'sack') {
        if ((string) ($_GET['intent'] ?? '') === 'search') {
            $company = jeab_normalize_company($_GET['co'] ?? null) ?? jeab_selected_company();
            // Påminnelser, kontakter och ordrar. En källa som fallerar står i "errors" (loggas), resten svarar.
            $found = jeab_sack_search($store, (string) ($_GET['q'] ?? ''), $company);
            jeab_json([
                'ok' => true,
                'hits' => $found['hits'],
                'errors' => $found['errors'],
            ]);
        }
        $searchUrl = jeab_url('index.php?p=sack&intent=search');
        $backUrl = jeab_url('index.php?p=links');
        require __DIR__ . '/src/pages/sack.php';
        return;
    }

    if ($page === 'kontakter') {
        $body = require __DIR__ . '/src/pages/kontakter.php';
        jeab_layout('Kontakter · JEAB', 'kontakter', $body);
        return;
    }

    if ($page === 'inkorg-kontakter' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && function_exists('jeab_inkorg_contacts_archived') && jeab_inkorg_contacts_archived()) {
        $query = ['p' => 'kontakter'];
        $cid = trim((string) ($_GET['c'] ?? ''));
        if ($cid !== '' && !str_starts_with($cid, 'kt_') && function_exists('jeab_kontakter_load') && function_exists('jeab_kontakt_migrate_already')) {
            $found = jeab_kontakt_migrate_already(jeab_kontakter_load(), $cid);
            if ($found !== null) {
                $cid = $found;
            }
        }
        if ($cid !== '') {
            $query['id'] = $cid;
        }
        $seek = trim((string) ($_GET['q'] ?? ''));
        if ($seek !== '') {
            $query['q'] = $seek;
        }
        if ((string) ($_GET['ny'] ?? '') !== '') {
            $query['ny'] = '1';
        }
        if (trim((string) ($_GET['flash'] ?? '')) !== '') {
            $query['flash'] = trim((string) $_GET['flash']);
        }
        jeab_redirect(jeab_url('index.php?' . http_build_query($query)), 302);
    }

    if ($page === 'inkorg-regler' && (!function_exists('jeab_is_admin') || !jeab_is_admin())) {
        $body = '<section class="apple-app"><h1 class="crm-title">Regler</h1><p class="ink-muted">Inte behörig.</p></section>';
        jeab_layout('Regler · JEAB', 'inkorg', $body);
        return;
    }

    if (in_array($page, ['inkorg', 'inkorg-regler', 'inkorg-vyer', 'inkorg-kontakter'], true)) {
        $file = ['inkorg' => 'inkorg.php', 'inkorg-regler' => 'inkorg_regler.php', 'inkorg-vyer' => 'inkorg_vyer.php', 'inkorg-kontakter' => 'inkorg_kontakter.php'][$page];
        $body = require __DIR__ . '/src/pages/' . $file;
        jeab_layout(['inkorg' => 'Inkorg', 'inkorg-regler' => 'Regler', 'inkorg-vyer' => 'Vyer och etiketter', 'inkorg-kontakter' => 'Kontakter'][$page] . ' · JEAB', $page, $body);
        return;
    }

    if ($page === 'logg') {
        if (function_exists('jeab_demo_mode') && jeab_demo_mode()) {
            $body = '<div class="links-page logg-page"><h1>Fellogg</h1><p class="logg-empty">Fellogg visas inte i demon.</p></div>';
            jeab_layout('Fellogg · JEAB', 'links', $body);
            return;
        }
        $entries = jeab_app_errors_read();
        if (function_exists('jeab_inkorg_misclass_public')) {
            $entries = array_merge(jeab_inkorg_misclass_public(), $entries);
        }
        $body = require __DIR__ . '/src/pages/logg.php';
        jeab_layout('Fellogg · JEAB', 'links', $body);
        return;
    }

    if ($page === 'bugg') {
        $file = trim((string) ($_GET['file'] ?? ''));
        if ($file !== '') {
            jeab_bug_send_file($file);
        }
        $body = require __DIR__ . '/src/pages/bugg.php';
        jeab_layout('Rapportera bugg · JEAB', 'links', $body);
        return;
    }

    if ($page === 'purchase') {
        $id = (int) ($_GET['id'] ?? 0);
        $reminder = $id > 0 ? jeab_get_reminder($store, $id) : null;
        $purchase = $id > 0 ? jeab_purchase_get($id) : null;
        if (!$reminder || !$purchase) {
            jeab_redirect(jeab_reminders_url(['open' => $id > 0 ? $id : null]));
        }
        $step = (int) ($_GET['step'] ?? ($purchase['step'] ?? 1));
        $label = jeab_purchase_kind_label((string) ($purchase['kind'] ?? ''));
        $body = require __DIR__ . '/src/pages/purchase.php';
        jeab_layout(($label !== '' ? $label : 'Köp') . ' · JEAB', 'purchase', $body);
        return;
    }

    if ($page === 'zapier') {
        jeab_render_zapier_page($store, $flash);
        return;
    }

    $nyExisting = function_exists('jeab_reminder_ny_existing_url') ? jeab_reminder_ny_existing_url() : '';
    if ($nyExisting !== '') {
        jeab_redirect($nyExisting);
    }

    $jeabRemStarted = microtime(true);
    if ((string) ($_GET['intent'] ?? '') === 'card') {
        $card = jeab_reminder_card_payload($store);
        jeab_timing_span('rem_card', microtime(true) - $jeabRemStarted);
        jeab_timing_span('reminders', microtime(true) - $jeabRemStarted);
        jeab_json($card, !empty($card['ok']) ? 200 : 404);
    }
    if ((string) ($_GET['intent'] ?? '') === 'side' && function_exists('jeab_pam_side_payload')) {
        $side = jeab_pam_side_payload($store);
        jeab_timing_span('rem_side', microtime(true) - $jeabRemStarted);
        jeab_timing_span('reminders', microtime(true) - $jeabRemStarted);
        jeab_json($side, !empty($side['ok']) ? 200 : 404);
    }
    $GLOBALS['_JEAB_REMINDER_LIST_DIAG'] = true;
    if ((string) ($_GET['intent'] ?? '') !== 'more') {
        jeab_reminders_apply_open($store);
    }
    $reminderCompany = jeab_selected_company();
    $reminderWho = jeab_reminder_who_filter();
    // Länkar och formulär efter sessionsläppet använder det här värdet.
    $GLOBALS['_JEAB_REMINDER_WHO_FOR_URL'] = $reminderWho;
    $flash = jeab_flash_text($flash);
    jeab_csrf_session_token();
    jeab_csrf_token();
    jeab_release_session();
    $search = trim((string) ($_GET['q'] ?? ''));
    // Hela bolaget först när sök behöver anteckning och kontaktnamn.
    // Utan sök läses sidan i SQL. Korten hämtar sin anteckning efter sidbrytningen.
    $view = jeab_reminder_view();
    $tagFilter = jeab_reminder_tag_filter();
    $scope = jeab_reminder_list_scope($view, $tagFilter, $search);
    $view = $scope['view'];
    $tagFilter = is_array($scope['tag']) ? $scope['tag'] : jeab_reminder_tag_mode($scope['tag']);
    $activeTag = $tagFilter['mode'] === 'tag' ? $tagFilter['tag'] : null;
    $tags = jeab_tag_catalog($store);
    $intent = (string) ($_GET['intent'] ?? '');
    $searchFallback = false;
    $whoCountsReady = null;
    $sqlPack = null;
    if ($search === '' && $store instanceof JeabPdoStore) {
        $pageOffset = $intent === 'sync' ? 0 : max(0, (int) ($_GET['offset'] ?? 0));
        $pageLimit = jeab_reminder_draw_limit((int) ($_GET['n'] ?? 0), $intent);
        $pageOpen = $intent === 'more' ? 0 : jeab_reminder_open_id();
        try {
            $jeabSqlStarted = microtime(true);
            $sqlPack = jeab_reminders_sql_pack(
                $store,
                $reminderCompany,
                $reminderWho,
                $view,
                $tagFilter,
                $pageOffset,
                $pageLimit,
                $pageOpen,
                $intent !== 'pulse'
            );
            if (function_exists('jeab_timing_span')) {
                jeab_timing_span('rem_sql', microtime(true) - $jeabSqlStarted);
            }
        } catch (Throwable $e) {
            $sqlPack = null;
            if (function_exists('jeab_log_app_error')) {
                jeab_log_app_error('reminders', 'sql-sidan föll tillbaka', []);
            }
        }
    }
    if (is_array($sqlPack)) {
        $remindersAll = [];
        $reminders = [];
        $counts = $sqlPack['counts'];
        $whoCountsReady = $sqlPack['who'];
        $page = [
            'rows' => $sqlPack['rows'],
            'total' => $sqlPack['total'],
            'offset' => $sqlPack['offset'],
            'next' => $sqlPack['next'],
        ];
        if ($intent === 'more' && !jeab_reminder_view_paged($view, $search)) {
            $page['rows'] = [];
        }
    } else {
        $remindersAll = jeab_reminders_load_company($store, $reminderCompany, $search);
        $reminders = jeab_filter_reminders_scope($remindersAll, $reminderCompany, $reminderWho);
        $visible = jeab_filter_reminders($reminders, $view, $tagFilter, $search);
        $countRows = $reminders;
        if ($search !== '' && $visible === [] && $reminderWho !== null) {
            $visible = jeab_reminder_search_fallback($remindersAll, $view, $tagFilter, $search);
            $searchFallback = $visible !== [];
            if ($searchFallback) {
                $countRows = $remindersAll;
            }
        }
        $counts = jeab_reminder_counts($countRows, $tagFilter, $search);
        if ($intent === 'more') {
            $page = jeab_reminder_page($visible, $view, $search, max(0, (int) ($_GET['offset'] ?? 0)), JEAB_REMINDER_DONE_PAGE);
            if (!jeab_reminder_view_paged($view, $search)) {
                $page['rows'] = [];
            }
        } else {
            $page = jeab_reminder_page($visible, $view, $search, 0, (int) ($_GET['n'] ?? 0), jeab_reminder_open_id());
        }
    }
    if ($intent === 'more') {
        header('Cache-Control: no-store');
        $moreRows = jeab_reminders_ensure_ort($store, jeab_reminders_fill_bodies($store, $page['rows']), false);
        jeab_reminder_preload_contacts($moreRows);
        jeab_timing_span('reminders', microtime(true) - $jeabRemStarted);
        jeab_reminder_log_slow('reminders', microtime(true) - $jeabRemStarted);
        jeab_json(jeab_reminder_sync_payload($moreRows, $counts, $page['total'], $page['next']));
    }
    if ($intent === 'pulse') {
        header('Cache-Control: no-store');
        jeab_timing_span('reminders', microtime(true) - $jeabRemStarted);
        jeab_reminder_log_slow('reminders', microtime(true) - $jeabRemStarted);
        jeab_json(jeab_reminder_pulse_payload($page['rows'], $counts, (int) $page['total'], $page['next']));
    }
    if ($intent === 'sync' && jeab_reminder_sync_stamp_matches((string) ($_GET['stamp'] ?? ''), $page['rows'])) {
        header('Cache-Control: no-store');
        jeab_timing_span('reminders', microtime(true) - $jeabRemStarted);
        jeab_reminder_log_slow('reminders', microtime(true) - $jeabRemStarted);
        jeab_json(jeab_reminder_pulse_payload($page['rows'], $counts, (int) $page['total'], $page['next']));
    }
    $visibleTotal = $page['total'];
    $visibleNext = $page['next'];
    $visible = jeab_reminders_ensure_ort($store, jeab_reminders_fill_bodies($store, $page['rows']), false);
    jeab_reminder_preload_contacts($visible);
    if ($intent === 'sync') {
        jeab_timing_span('reminders', microtime(true) - $jeabRemStarted);
        jeab_reminder_log_slow('reminders', microtime(true) - $jeabRemStarted);
        header('Cache-Control: no-store');
        jeab_json(jeab_reminder_sync_payload($visible, $counts, $visibleTotal, $visibleNext));
    }
    $body = require __DIR__ . '/src/pages/reminders.php';
    if ($intent === 'page') {
        jeab_timing_span('reminders', microtime(true) - $jeabRemStarted);
        jeab_reminder_log_slow('reminders', microtime(true) - $jeabRemStarted);
        // Filter, flikar och sök byter listan utan helsidesladdning.
        header('Cache-Control: no-store');
        jeab_json([
            'ok' => true,
            'html' => $body,
            'company' => function_exists('jeab_chrome_company_html') ? jeab_chrome_company_html('reminders') : '',
        ]);
    }
    jeab_timing_span('reminders', microtime(true) - $jeabRemStarted);
    jeab_reminder_log_slow('reminders', microtime(true) - $jeabRemStarted);
    jeab_layout('Påminnelser · JEAB', 'reminders', $body);
}
