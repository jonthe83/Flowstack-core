<?php

declare(strict_types=1);

const JEAB_ROOT = __DIR__ . '/..';
const JEAB_TZ = 'Europe/Stockholm';
const JEAB_JOB_TYPES = [
    'batteri' => 'Batteri',
    'sol' => 'Sol',
    'laddbox' => 'Elbilsladdare',
    'service' => 'Service',
];

const JEAB_INSTALLERS = ['Roberto', 'Sam', 'Elin', 'Niman'];
const JEAB_REMINDER_TAGS = ['vunnen', 'förlorad', 'släpp', 'admin'];

require_once __DIR__ . '/deploy_guard.php';
jeab_deploy_guard();

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/demo.php';
require_once __DIR__ . '/app_settings.php';

/**
 * Hela den gamla listan, i samma ordning. CLI, tick, cron och tunga sidor
 * (Påminnelser, Kalender, Ordrar, Inkorg, Kontakter) laddar den oförändrat
 * så HTML och beteende stannar. Lätta sidor laddar bara kärnan plus sin grupp.
 *
 * @return list<string>
 */
function jeab_bootstrap_legacy_files(): array
{
    return [
        'normalize.php',
        'db.php',
        'log_store.php',
        'test_lead.php',
        'people.php',
        'user_responsibility.php',
        'store.php',
        'datastore.php',
        'remain_sql.php',
        'security.php',
        'bokmalen_ellevio_fullmakt_webhook.php',
        'webhooks.php',
        'reminders.php',
        'sms.php',
        'bookings.php',
        'filters.php',
        'offert_mall.php',
        'system_mail.php',
        'offert.php',
        'sigen.php',
        'purchase.php',
        'ordrar.php',
        'kontakter.php',
        'sack_search.php',
        'reminder_admin.php',
        'fastighet_refresh.php',
        'contacts_sole.php',
        'snapshots.php',
        'address_extract.php',
        'ellevio_fullmakt.php',
        'seom_fullmakt.php',
        'solkoll.php',
        'inkop.php',
        'links.php',
        'notiser.php',
        'notes.php',
        'bugg.php',
        'inkorg.php',
        'inkorg_graph.php',
        'inkorg_outbound.php',
        'inkorg_rebook.php',
        'inkorg_auto.php',
        'inkorg_resend.php',
        'inkorg_reminder_open.php',
        'lead_source.php',
        'inkorg_no_email.php',
        'omtago.php',
        'inkorg_follow.php',
        'inkorg_clean.php',
        'inkorg_fast.php',
        'inkorg_klassning.php',
        'inkorg_helpscout_import.php',
        'inkorg_malmo_hs.php',
        'kontakter_name_address.php',
        'kontakter_email_key.php',
        'kontakter_set_place.php',
        'contacts_set_phone.php',
        'contacts_field_source.php',
        'hs_internal.php',
        'graph_person.php',
        'calendar_outlook.php',
        'inkorg_taxonomy.php',
        'webshop.php',
        'webshop_wix.php',
        'wix_spam.php',
        'auth.php',
        'offert_utskick.php',
        'send_guard.php',
        'mail_queue.php',
        'solkampanj.php',
        'restricted_users.php',
    ];
}

/**
 * Varje webbförfrågan: databas, inloggning, session, layout och nödbromsens krok.
 * ordrar, inkorg_auto, contacts_sole, solkampanj och Help Scout-stacken ligger inte här.
 *
 * @return list<string>
 */
function jeab_bootstrap_core_files(): array
{
    return [
        'normalize.php',
        'db.php',
        'people.php',
        'user_responsibility.php',
        'store.php',
        'security.php',
        'auth.php',
        'send_guard.php',
        'mail_queue.php',
        'restricted_users.php',
        'layout.php',
        'view_cache.php',
    ];
}

/**
 * Sidor som inte ritar Påminnelser, Kalender, Ordrar, Inkorg eller Kontakter.
 * Värdet är filer utöver kärnan. Okänd sida laddar hela listan.
 *
 * @return array<string, list<string>>
 */
function jeab_bootstrap_light_pages(): array
{
    return [
        'links' => ['links.php'],
        'offert' => ['offert.php', 'sigen.php'],
        'bugg' => ['bugg.php'],
        'logg' => ['inkorg_case.php'],
        'notes' => ['notes.php', 'notes_store.php'],
        'notiser' => ['notiser.php', 'inkorg.php', 'inkorg_auto.php', 'offert_mall.php', 'fortnox.php', 'offert_utskick.php'],
        'utskick' => ['notiser.php', 'inkorg.php', 'inkorg_auto.php', 'offert_mall.php', 'fortnox.php', 'offert_utskick.php'],
        // Sökningen läser påminnelsens kund via contacts_sole (flags, kontakter). Bara reminders.php
        // gav fatal error i sök-JSON:en, och rutan stängdes tyst.
        // sack_search.php söker även kontakter (kontakter.php) och ordrar (purchase.php).
        'sack' => ['flags.php', 'filters.php', 'reminders.php', 'contacts_sole.php', 'snapshots.php', 'kontakter.php', 'kontakter_name_address.php', 'purchase.php', 'sack_search.php'],
        'fullmakter' => ['fullmakt_signed.php'],
        'inkorg-vyer' => ['inkorg.php', 'inkorg_fast.php'],
        'inloggdiag' => [],
        'zapier' => [],
        'rot' => [],
        'manuellorder' => [],
        'csrf' => [],
    ];
}

function jeab_bootstrap_use_lazy(): bool
{
    if (!empty($GLOBALS['_JEAB_BOOT_ALL'])) {
        return false;
    }
    if (!empty($GLOBALS['_JEAB_BOOT_LAZY'])) {
        return true;
    }
    if (in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
        return false;
    }
    $script = basename(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));

    return $script === 'index.php';
}

/**
 * Inloggningssidan och inloggnings-POST behöver inte Ordrar, Inkorg eller
 * resten av den tunga listan. Kakan jeab_auth saknas: bara kärnan laddas.
 * Efter lyckad inloggning kommer omdirigeringen och laddar sidan som vanligt.
 */
function jeab_bootstrap_skip_heavy_until_login(): bool
{
    if (!empty($GLOBALS['_JEAB_BOOT_ALL'])) {
        return false;
    }
    $sapi = (string) ($GLOBALS['_JEAB_SAPI'] ?? PHP_SAPI);
    if ($sapi === 'cli' || $sapi === 'phpdbg') {
        return false;
    }
    if (!jeab_bootstrap_use_lazy()) {
        return false;
    }
    $cookie = (string) ($_COOKIE['jeab_auth'] ?? '');

    return $cookie === '';
}

function jeab_bootstrap_action_is_heavy(string $action): bool
{
    if ($action === '' || !str_contains($action, '.')) {
        return false;
    }
    $prefix = explode('.', $action, 2)[0];

    return !in_array($prefix, ['offert', 'bugg', 'notes', 'notiser', 'settings', 'sack', 'logout'], true);
}

/**
 * Sidor som ritas ofta. De tar kärnan plus den här gruppen, inte hela
 * legacy-listan (ordrar, inkorg_auto, fortnox, Help Scout). POST med tung
 * åtgärd laddar fortfarande hela listan.
 *
 * @return array<string, list<string>>
 */
function jeab_bootstrap_work_pages(): array
{
    $reminders = [
        'flags.php',
        'filters.php',
        'reminders.php',
        'bookings.php',
        'contacts_sole.php',
        'snapshots.php',
        'kontakter.php',
        'kontakter_name_address.php',
        'pam_desk.php',
        // Mina = samma person som Egna i Inkorg (jeab_egna_person). Saknas filen blir Mina okänd.
        'inkorg_klassning.php',
    ];
    $calendar = [
        'flags.php',
        'filters.php',
        'bookings.php',
        'calendar_outlook.php',
        'reminders.php',
        'contacts_sole.php',
        'snapshots.php',
        'kontakter.php',
        'kontakter_name_address.php',
        'graph_person.php',
    ];

    return [
        'reminders' => $reminders,
        'reminder' => $reminders,
        'bookings' => $calendar,
        'ordrar' => [
            'flags.php',
            'filters.php',
            'ordrar.php',
            'bookings.php',
            'reminders.php',
            'contacts_sole.php',
            'snapshots.php',
            'kontakter.php',
            'kontakter_name_address.php',
            'purchase.php',
            'solkoll.php',
            'fastighet_refresh.php',
            'sms.php',
        ],
        'kontakter' => [
            'flags.php',
            'filters.php',
            'kontakter.php',
            'kontakter_name_address.php',
            'contacts_sole.php',
            'snapshots.php',
            'reminders.php',
            'bookings.php',
            'purchase.php',
        ],
        // Egna/Alla, mappar, köer (inkorg_klassning) och Help Scout-arkivet (Historik)
        // måste finnas här. Saknas de faller inkorg_fast tyst tillbaka till scope=allas,
        // Inkorg-mappen och bara live-raderna (Historik 9 i stället för ~20 000).
        'inkorg' => [
            'flags.php',
            'inkorg.php',
            'inkorg_fast.php',
            'inkorg_store.php',
            'inkorg_klassning.php',
            'inkorg_helpscout_import.php',
            'inkorg_malmo_hs.php',
            'inkorg_graph.php',
            'contacts_sole.php',
            'snapshots.php',
            'kontakter.php',
            'kontakter_name_address.php',
            'graph_person.php',
        ],
    ];
}

/**
 * Öppet orderkort (?p=ordrar&id=) anropar Sigen, offert, fullmakt, systemmejl
 * och webbshop. Hela legacy-listan laddas då. Listan utan id stannar på arbetsgruppen.
 */
function jeab_bootstrap_ordrar_card_request(?string $page = null): bool
{
    $page = $page ?? jeab_page_slug((string) ($_GET['p'] ?? ''));
    if ($page !== 'ordrar') {
        return false;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }

    return (int) ($_GET['id'] ?? $_GET['open'] ?? 0) > 0;
}

/**
 * "Skapa påminnelse" från Inkorg (p=reminders&ny=1&thread=…) läser ärendet och dess kontakt
 * med funktioner i inkorg.php och inkorg_auto.php. Vanliga Påminnelser laddar dem inte.
 */
function jeab_bootstrap_reminder_case_request(?string $page = null): bool
{
    $page = $page ?? jeab_page_slug((string) ($_GET['p'] ?? ''));
    if ($page !== 'reminders') {
        return false;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }

    return (string) ($_GET['ny'] ?? '') === '1' && trim((string) ($_GET['thread'] ?? '')) !== '';
}

/** Öppet inkorgsärende anropar funktioner i inkorg_auto. Listan utan id gör det inte. */
function jeab_bootstrap_inkorg_thread_request(?string $page = null): bool
{
    $page = $page ?? jeab_page_slug((string) ($_GET['p'] ?? ''));
    if ($page !== 'inkorg') {
        return false;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }
    if (trim((string) ($_GET['id'] ?? '')) !== '') {
        return true;
    }
    $part = strtolower(trim((string) ($_GET['ix_part'] ?? '')));

    return in_array($part, ['thread', 'all'], true);
}

/** GET p=notiser&fil= ska bara läsa bilagan, inte Inkorg, Fortnox eller offertmallen. */
function jeab_bootstrap_notiser_file_request(): bool
{
    if (jeab_page_slug((string) ($_GET['p'] ?? '')) !== 'notiser') {
        return false;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }

    return trim((string) ($_GET['fil'] ?? '')) !== '';
}

function jeab_bootstrap_page_wants_full(string $page): bool
{
    if (jeab_bootstrap_ordrar_card_request($page)) {
        return true;
    }
    if (array_key_exists($page, jeab_bootstrap_light_pages())) {
        return false;
    }
    if (array_key_exists($page, jeab_bootstrap_work_pages())) {
        return false;
    }

    return true;
}

/** @param list<string> $files */
function jeab_bootstrap_load_files(array $files): void
{
    $started = microtime(true);
    foreach ($files as $file) {
        if ($file === 'test_lead.php') {
            $path = __DIR__ . '/test_lead.php';
            clearstatcache(true, $path);
            require_once $path;
            continue;
        }
        require_once __DIR__ . '/' . $file;
    }
    if (function_exists('jeab_timing_span') && $files !== []) {
        $prev = (float) ($GLOBALS['_JEAB_TIMING']['modules'] ?? 0);
        jeab_timing_span('modules', ($prev / 1000) + (microtime(true) - $started));
    }
}

/**
 * @return list<string>
 */
function jeab_bootstrap_files_for(string $page, string $action = ''): array
{
    if (jeab_bootstrap_notiser_file_request()) {
        return array_merge(jeab_bootstrap_core_files(), ['notiser.php']);
    }
    if (jeab_bootstrap_action_is_heavy($action) || jeab_bootstrap_page_wants_full($page)) {
        return jeab_bootstrap_legacy_files();
    }
    $files = jeab_bootstrap_core_files();
    $extraPages = jeab_bootstrap_light_pages()[$page] ?? jeab_bootstrap_work_pages()[$page] ?? [];
    foreach ($extraPages as $file) {
        $files[] = $file;
    }
    if (jeab_bootstrap_inkorg_thread_request($page)) {
        $files[] = 'inkorg_auto.php';
        $files[] = 'reminders.php';
    }
    if (jeab_bootstrap_reminder_case_request($page)) {
        foreach (jeab_bootstrap_work_pages()['inkorg'] as $file) {
            $files[] = $file;
        }
        $files[] = 'inkorg_auto.php';
    }
    $prefix = explode('.', $action, 2)[0];
    $extra = [
        // sack.send: systemmejlet går via Help Scout (sms.php) eller Inkorg/Graph. Utan dem: "Graph-utskick saknas".
        'sack' => ['system_mail.php', 'kontakter_dedupe.php', 'sms.php', 'inkorg.php', 'inkorg_graph.php', 'inkorg_outbound.php', 'lead_source.php', 'graph_person.php'],
        'notiser' => ['offert_mall.php', 'fortnox.php', 'offert_utskick.php'],
        'purchase' => ['offert.php', 'sigen.php', 'idnr.php'],
        'inkop' => ['inkop.php'],
    ];
    foreach ($extra[$prefix] ?? [] as $file) {
        $files[] = $file;
    }

    return array_values(array_unique($files));
}

function jeab_bootstrap_load_page_modules(): void
{
    if (!jeab_bootstrap_use_lazy() || !empty($GLOBALS['_JEAB_BOOT_PAGE_LOADED'])) {
        return;
    }
    $GLOBALS['_JEAB_BOOT_PAGE_LOADED'] = true;
    if (jeab_bootstrap_notiser_file_request()) {
        jeab_bootstrap_load_files(['notiser.php']);

        return;
    }
    $page = jeab_page_slug((string) ($_GET['p'] ?? 'reminders'));
    if (jeab_bootstrap_page_wants_full($page)) {
        jeab_bootstrap_load_files(jeab_bootstrap_legacy_files());

        return;
    }
    $files = jeab_bootstrap_light_pages()[$page] ?? jeab_bootstrap_work_pages()[$page] ?? [];
    if (jeab_bootstrap_inkorg_thread_request($page)) {
        $files[] = 'inkorg_auto.php';
        $files[] = 'reminders.php';
    }
    if (jeab_bootstrap_reminder_case_request($page)) {
        foreach (jeab_bootstrap_work_pages()['inkorg'] as $file) {
            $files[] = $file;
        }
        $files[] = 'inkorg_auto.php';
    }
    jeab_bootstrap_load_files($files);
}

function jeab_bootstrap_load_action_modules(string $action): void
{
    if (!jeab_bootstrap_use_lazy() || $action === '') {
        return;
    }
    if (jeab_bootstrap_action_is_heavy($action)) {
        jeab_bootstrap_load_files(jeab_bootstrap_legacy_files());

        return;
    }
    $offerSend = ['offert.enable', 'offert.test_preview', 'offert.test_send', 'offert.backfill_preview', 'offert.backfill_send'];
    if (in_array($action, $offerSend, true)) {
        jeab_bootstrap_load_files(jeab_bootstrap_legacy_files());

        return;
    }
    $prefix = explode('.', $action, 2)[0];
    $extra = [
        // sack.send: systemmejlet går via Help Scout (sms.php) eller Inkorg/Graph. Utan dem: "Graph-utskick saknas".
        'sack' => ['system_mail.php', 'kontakter_dedupe.php', 'sms.php', 'inkorg.php', 'inkorg_graph.php', 'inkorg_outbound.php', 'lead_source.php', 'graph_person.php'],
        'notiser' => ['notiser.php', 'inkorg.php', 'inkorg_auto.php', 'offert_mall.php', 'fortnox.php', 'offert_utskick.php'],
        'notes' => ['notes.php', 'notes_store.php'],
        'bugg' => ['bugg.php'],
        'offert' => ['offert.php', 'sigen.php'],
        'purchase' => ['purchase.php', 'purchase_store.php', 'offert.php', 'sigen.php', 'idnr.php'],
        'inkop' => ['inkop.php'],
    ];
    jeab_bootstrap_load_files($extra[$prefix] ?? []);
}

$jeabBootPage = jeab_page_slug((string) ($_GET['p'] ?? 'reminders'));
$jeabGraphHook = (string) ($_GET['graph_hook'] ?? '') === '1';
if (!empty($GLOBALS['_JEAB_WARM'])) {
    jeab_bootstrap_load_files(jeab_bootstrap_core_files());
    $GLOBALS['_JEAB_BOOT_PAGE_LOADED'] = true;
} elseif ($jeabGraphHook) {
    // Kroken har ingen inloggningskaka. Utan de här filerna blir tom kropp 500.
    jeab_bootstrap_load_files(array_merge(jeab_bootstrap_core_files(), ['store.php', 'datastore.php', 'remain_sql.php', 'inkorg.php', 'inkorg_graph.php']));
    $GLOBALS['_JEAB_BOOT_PAGE_LOADED'] = true;
} elseif (jeab_bootstrap_skip_heavy_until_login()) {
    jeab_bootstrap_load_files(jeab_bootstrap_core_files());
    $GLOBALS['_JEAB_BOOT_PAGE_LOADED'] = true;
} elseif (!jeab_bootstrap_use_lazy() || jeab_bootstrap_page_wants_full($jeabBootPage)) {
    jeab_bootstrap_load_files(jeab_bootstrap_legacy_files());
    $GLOBALS['_JEAB_BOOT_PAGE_LOADED'] = true;
} else {
    jeab_bootstrap_load_files(jeab_bootstrap_core_files());
}
unset($jeabBootPage, $jeabGraphHook);

function jeab_config(bool $reload = false): array
{
    static $config;
    if ($reload) {
        $config = null;
    }
    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'timezone' => JEAB_TZ,
        'outgoing_webhook_url' => '',
        'incoming_webhook_token' => '',
        'db' => [
            'driver' => 'sqlite',
            'sqlite_path' => jeab_data_dir() . '/app.sqlite',
            'json_path' => jeab_data_dir() . '/store.json',
            'mysql' => [
                'host' => 'localhost',
                'name' => '',
                'user' => '',
                'pass' => '',
                'charset' => 'utf8mb4',
            ],
        ],
    ];

    $paths = [
        JEAB_ROOT . '/data/config.php',
        JEAB_ROOT . '/config.php',
        jeab_data_dir() . '/config.php',
    ];

    $loaded = [];
    foreach ($paths as $path) {
        if (is_file($path)) {
            $loaded = require $path;
            break;
        }
    }

    $config = jeab_privatize_config(array_replace_recursive($defaults, is_array($loaded) ? $loaded : []));
    return $config;
}

function jeab_boot_session(): void
{
    if (function_exists('jeab_demo_headers')) {
        jeab_demo_headers();
    }
    $config = jeab_config();
    $tz = $config['timezone'] ?: JEAB_TZ;
    date_default_timezone_set($tz);
    jeab_start_session();
}

function jeab_boot_store(): JeabStore
{
    unset($GLOBALS['_JEAB_AUTH_EPOCH_DEFER']);
    $store = jeab_store();
    $GLOBALS['_JEAB_ACTIVE_STORE'] = $store;
    jeab_ensure_incoming_token($store);

    return $store;
}

function jeab_boot(): JeabStore
{
    jeab_boot_session();

    return jeab_boot_store();
}

/** Delanrop från inkorgen (lista, tråd, räknare). Tom sträng för en vanlig sidvisning. */
function jeab_inkorg_ix_part(): string
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return '';
    }
    if ((string) ($_GET['p'] ?? '') !== 'inkorg') {
        return '';
    }
    $part = (string) ($_GET['ix_part'] ?? '');

    return in_array($part, ['list', 'thread', 'counts', 'app', 'all'], true) ? $part : '';
}

/**
 * Ett inkorgs-delanrop åt gången. Sessionen släpps innan databasen öppnas,
 * så nästa klick inte köar bakom låset med en egen anslutning.
 */
function jeab_inkorg_part_enter(): JeabStore
{
    jeab_boot_session();
    jeab_gate();
    // Bolaget i adressen ska vara sparat innan sessionen släpps. Annars
    // ritar nästa anrop utan box den förra mejlboxen.
    if (function_exists('jeab_inkorg_box')) {
        jeab_inkorg_box();
    }
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $partNow = jeab_inkorg_ix_part();
    if ($partNow === 'thread') {
        // Tråden ritas även när platsen är upptagen. busy/stale utan html lämnar «Öppnar ärendet…».
        $slot = function_exists('jeab_db_slot_acquire') ? jeab_db_slot_acquire(2) : 'open';
        if ($slot === null) {
            $slot = 'open';
        }
    } else {
        $slot = function_exists('jeab_db_slot_acquire') ? jeab_db_slot_acquire(1) : 'open';
        if ($slot === null) {
            if (function_exists('jeab_inkorg_trace_event')) {
                jeab_inkorg_trace_event('part_enter.slot_busy', null, ['part' => $partNow, 'scope' => strtolower(trim((string) ($_GET['scope'] ?? '')))]);
            }
            jeab_json(['ok' => true, 'busy' => 1, 'retry' => 1, 'stale' => 1]);
        }
    }
    $GLOBALS['_JEAB_IX_SLOT'] = $slot;
    register_shutdown_function(static function () use ($slot): void {
        if (function_exists('jeab_finish_http_response')) {
            jeab_finish_http_response();
        }
        if (function_exists('jeab_db_slot_release')) {
            jeab_db_slot_release($slot);
        }
    });

    return jeab_boot_store();
}

function jeab_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443';
}

function jeab_request_is_prefetch(): bool
{
    $purpose = strtolower((string) ($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? ''));
    if ($purpose !== '' && (str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender') || str_contains($purpose, 'preview'))) {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')) === 'prefetch';
}

/**
 * Förhämtning, synk, inkorgens värmning och korta intents läser sessionen
 * och släpper den direkt. De skriver inte, så nästa klick inte köar på flock.
 */
function jeab_request_session_readonly(): bool
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }
    if (jeab_request_is_prefetch()) {
        return true;
    }
    $intent = strtolower(trim((string) ($_GET['intent'] ?? '')));
    if (in_array($intent, ['sync', 'card', 'side', 'threads', 'rows', 'calendar-log', 'more', 'page', 'search', 'list', 'get', 'reminder-names', 'calendar-bookings', 'calendar-outlook', 'calendar-match-debug'], true)) {
        return true;
    }
    if (function_exists('jeab_inkorg_ix_part') && jeab_inkorg_ix_part() !== '') {
        return true;
    }

    return false;
}

function jeab_start_session(): void
{
    $skipSession = PHP_SAPI === 'cli' || !empty($GLOBALS['JEAB_NO_SESSION']);
    if (session_status() === PHP_SESSION_ACTIVE || $skipSession) {
        return;
    }
    if (jeab_request_is_prefetch() && empty($_COOKIE['jeab']) && empty($_COOKIE[session_name()])) {
        $GLOBALS['JEAB_NO_SESSION'] = true;
        return;
    }
    session_name('jeab');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 14,
        'path' => '/',
        'secure' => jeab_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $readonly = jeab_request_session_readonly();
    $waitFrom = microtime(true);
    if ($readonly) {
        session_start(['read_and_close' => true]);
        $GLOBALS['_JEAB_SESSION_READONLY'] = true;
        $GLOBALS['_JEAB_SESSION_RELEASED'] = true;
    } else {
        session_start();
    }
    $wait = microtime(true) - $waitFrom;
    // Sessionsfilens flock sitter inuti session_start. Båda spannen är den väntan.
    if (function_exists('jeab_timing_span')) {
        jeab_timing_span('sess_wait', $wait);
        jeab_timing_span('lock_wait', $wait);
    }
    if ($wait >= 0.5) {
        $slowMs = (int) round($wait * 1000);
        register_shutdown_function(static function () use ($slowMs): void {
            if (function_exists('jeab_finish_http_response')) {
                jeab_finish_http_response();
            }
            if (function_exists('jeab_log_slow_request')) {
                $page = function_exists('jeab_timing_page_slug')
                    ? jeab_timing_page_slug((string) ($_GET['p'] ?? ''))
                    : 'okand';
                jeab_log_slow_request('session', $page, $slowMs, 'sess_wait');
            }
        });
    }
    // Tom session vid start = ny eller bortstädad session. Bara diagnostik för felloggen.
    $GLOBALS['_JEAB_SESSION_FRESH'] = $_SESSION === [];
    $sapi = (string) ($GLOBALS['_JEAB_SAPI'] ?? PHP_SAPI);
    if ($sapi !== 'cli' && $sapi !== 'phpdbg') {
        // Epoch läses när butiken öppnas, efter att låset släppts.
        $GLOBALS['_JEAB_AUTH_EPOCH_DEFER'] = true;
    }
}

function jeab_normalize_base_path(string $raw): string
{
    $raw = trim(str_replace('\\', '/', $raw));
    if ($raw === '' || $raw === '/' || $raw === '.') {
        return '';
    }
    return '/' . trim($raw, '/');
}

function jeab_configured_base_path(): ?string
{
    $env = getenv('JEAB_BASE_PATH');
    if ($env !== false) {
        return jeab_normalize_base_path($env);
    }
    $config = jeab_config();
    if (!array_key_exists('base_path', $config) || $config['base_path'] === null) {
        return null;
    }
    return jeab_normalize_base_path((string) $config['base_path']);
}

function jeab_base_path(): string
{
    $configured = jeab_configured_base_path();
    if ($configured !== null) {
        return $configured;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    if ($script === '' || $script === '/') {
        $script = '/index.php';
    }
    // rot/ is a page folder, not the app root. Assets and nav live one folder up.
    if (preg_match('#/rot(?:/[^/]+\.php|/)?$#', $script)) {
        $script = (string) preg_replace('#/rot(?:/[^/]+\.php|/)?$#', '/index.php', $script);
    }
    $dir = dirname($script);
    if ($dir === '/' || $dir === '.' || $dir === '\\') {
        return '';
    }
    return rtrim($dir, '/');
}

function jeab_legacy_bokning_target(?string $uri = null): string
{
    $uri = $uri ?? (string) ($_SERVER['REQUEST_URI'] ?? '/bokning');
    $parts = parse_url($uri);
    $path = str_replace('\\', '/', (string) ($parts['path'] ?? '/bokning'));
    $query = (string) ($parts['query'] ?? '');

    if (preg_match('#^/bokning(?=/|$)#', $path)) {
        $path = (string) preg_replace('#^/bokning#', '', $path);
    }
    $path = '/' . ltrim($path, '/');
    if ($path === '/rot' || $path === '/rot/' || $path === '/rot/index.php') {
        $path = '/index.php';
        $query = $query === '' ? 'p=manuell-order' : ('p=manuell-order&' . $query);
    }

    $base = jeab_configured_base_path();
    if ($base === null || $base === '/bokning') {
        $base = '';
    }

    $target = $base . $path;
    if ($target === '' || str_starts_with($target, '//') || str_contains($target, '\\')) {
        $target = '/';
    }
    if ($query !== '') {
        $target .= '?' . $query;
    }
    return $target;
}

function jeab_page_slug(string $raw): string
{
    $page = strtolower(trim($raw));
    $page = preg_replace('/[^a-z-]/', '', $page) ?? '';
    $page = trim($page, '-');
    if ($page === 'kalkyl') {
        return 'offert';
    }
    return $page !== '' ? $page : 'reminders';
}

/** Öppna sessionen igen om den stängdes innan ett $_SESSION-skriv. */
/**
 * Efter read_and_close: öppna skrivbart, kör $write, stäng igen.
 * $_SESSION behåller värdet så samma svar och nästa anrop ser det.
 */
function jeab_session_write_once(callable $write): bool
{
    if (!empty($GLOBALS['JEAB_NO_SESSION'])) {
        return false;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $write();

        return true;
    }
    if (session_status() !== PHP_SESSION_NONE) {
        return false;
    }
    $readonly = !empty($GLOBALS['_JEAB_SESSION_READONLY']);
    $GLOBALS['_JEAB_SESSION_READONLY'] = false;
    $ok = @session_start();
    if ($ok !== true || session_status() !== PHP_SESSION_ACTIVE) {
        if ($readonly) {
            $GLOBALS['_JEAB_SESSION_READONLY'] = true;
        }

        return false;
    }
    try {
        $write();
    } finally {
        session_write_close();
        $GLOBALS['_JEAB_SESSION_READONLY'] = true;
        $GLOBALS['_JEAB_SESSION_RELEASED'] = true;
    }

    return true;
}

function jeab_session_resume(): void
{
    if (!empty($GLOBALS['_JEAB_SESSION_READONLY']) || !empty($GLOBALS['JEAB_NO_SESSION']) || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        @session_start();
        return;
    }
    jeab_start_session();
}

function jeab_url(string $path = ''): string
{
    $base = jeab_base_path();
    $path = ltrim($path, '/');
    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }
    return ($base === '' ? '' : $base) . '/' . $path;
}

/**
 * Egen CSS/JS under assets/ med ?v=innehållets hash.
 * Varje fil har sin egen version, så en delvis deploy bara bustar det som ändrats.
 * En hårdkodad ?v=3 (eller &v=3) i sökvägen byts ut.
 * Nginx ignorerar .htaccess. Hashen är cache-nyckeln tillsammans med
 * Cache-Control immutable i loopia-nginx.conf.
 */
function jeab_asset_url(string $path): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $extra = '';
    $qpos = strpos($path, '?');
    if ($qpos !== false) {
        $extra = substr($path, $qpos + 1);
        $path = substr($path, 0, $qpos);
    }
    $file = JEAB_ROOT . '/' . $path;
    $v = jeab_asset_version_token($file);
    $keep = [];
    if ($extra !== '') {
        foreach (explode('&', $extra) as $pair) {
            if ($pair === '' || str_starts_with($pair, 'v=')) {
                continue;
            }
            $keep[] = $pair;
        }
    }
    $keep[] = 'v=' . $v;

    return jeab_url($path) . '?' . implode('&', $keep);
}

/** @see jeab_asset_url() */
function jeab_asset(string $rel): string
{
    return jeab_asset_url($rel);
}

/** Tolv tecken av filens sha256. Samma innehåll ger samma ?v= även om mtime flyttas. */
function jeab_asset_version_token(string $file): string
{
    if (!is_file($file)) {
        return '0';
    }
    static $mem = [];
    $mtime = (int) filemtime($file);
    $size = (int) filesize($file);
    $key = $file . '|' . $mtime . '|' . $size;
    if (isset($mem[$key])) {
        return $mem[$key];
    }
    $apcu = 'jeab_av_' . hash('sha256', $key);
    if (function_exists('apcu_fetch')) {
        $ok = false;
        $hit = apcu_fetch($apcu, $ok);
        if ($ok && is_string($hit) && preg_match('/\A[0-9a-f]{12}\z/', $hit) === 1) {
            $mem[$key] = $hit;

            return $hit;
        }
    }
    $disk = sys_get_temp_dir() . '/jeab-av-' . substr(hash('sha256', $key), 0, 24);
    if (is_file($disk)) {
        $hit = trim((string) file_get_contents($disk));
        if (preg_match('/\A[0-9a-f]{12}\z/', $hit) === 1) {
            $mem[$key] = $hit;
            if (function_exists('apcu_store')) {
                apcu_store($apcu, $hit, 86400);
            }

            return $hit;
        }
    }
    $hash = hash_file('sha256', $file);
    $token = is_string($hash) ? substr($hash, 0, 12) : '0';
    $mem[$key] = $token;
    if (function_exists('apcu_store')) {
        apcu_store($apcu, $token, 86400);
    }
    if ($token !== '0') {
        @file_put_contents($disk, $token, LOCK_EX);
    }

    return $token;
}

/** @return array<string, string> */
function jeab_asset_versions(): array
{
    $out = [];
    foreach (['assets/js', 'assets/css'] as $dir) {
        foreach (array_merge(glob(JEAB_ROOT . '/' . $dir . '/*.js') ?: [], glob(JEAB_ROOT . '/' . $dir . '/*.css') ?: []) as $file) {
            $rel = $dir . '/' . basename($file);
            $out[$rel] = jeab_asset_version_token($file);
        }
    }
    return $out;
}

function jeab_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d H:i:s');
}

function jeab_iso(string $local): string
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $local, $tz);
    if (!$dt) {
        $dt = new DateTimeImmutable($local, $tz);
    }
    return $dt->format('c');
}

function jeab_parse_datetime(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $value = str_replace('T', ' ', $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        $value .= ':00';
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $tz);
    if (!$dt) {
        try {
            $dt = new DateTimeImmutable($value, $tz);
        } catch (Exception) {
            return null;
        }
    }
    return $dt->format('Y-m-d H:i:s');
}

function jeab_parse_reminder_due(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $tz = new DateTimeZone(date_default_timezone_get());
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
        if (!$dt) {
            return null;
        }
        return $dt->format('Y-m-d') . ' 00:00:00';
    }
    return jeab_parse_datetime($value);
}

function jeab_setting(JeabStore $store, string $key, ?string $default = null): ?string
{
    return $store->setting($key, $default);
}

function jeab_set_setting(JeabStore $store, string $key, string $value): void
{
    $store->setSetting($key, $value);
}

function jeab_incoming_token_privat_file(): string
{
    if (function_exists('jeab_demo_mode') && jeab_demo_mode()) {
        return jeab_data_dir() . '/incoming-token.txt';
    }
    if (!empty($GLOBALS['_JEAB_INCOMING_TOKEN_FILE']) && is_string($GLOBALS['_JEAB_INCOMING_TOKEN_FILE'])) {
        return $GLOBALS['_JEAB_INCOMING_TOKEN_FILE'];
    }
    $root = realpath(JEAB_ROOT);
    if ($root === false) {
        $root = JEAB_ROOT;
    }
    return dirname($root) . '/privat/incoming-token.txt';
}

function jeab_read_token_file(string $file): string
{
    if (!is_file($file) || !is_readable($file)) {
        return '';
    }
    $raw = file_get_contents($file);
    if (!is_string($raw)) {
        return '';
    }
    return trim($raw);
}

/** Token som en explicit rotation skrev. Null om ingen rotation är gjord. */
function jeab_incoming_token_if_rotated(JeabStore $store): ?string
{
    if (trim((string) jeab_setting($store, 'incoming_webhook_rotated', '')) !== '1') {
        return null;
    }
    $token = trim((string) jeab_setting($store, 'incoming_webhook_token', ''));
    return $token !== '' ? $token : null;
}

/**
 * Token som hook.php tar emot, utan att skapa eller byta den.
 * En rotation (flaggan) går före config och filer.
 */
function jeab_incoming_token_current(JeabStore $store): string
{
    $rotated = jeab_incoming_token_if_rotated($store);
    if ($rotated !== null) {
        return $rotated;
    }
    $fromConfig = trim((string) (jeab_config()['incoming_webhook_token'] ?? ''));
    if ($fromConfig !== '') {
        return $fromConfig;
    }
    $fromPrivat = jeab_read_token_file(jeab_incoming_token_privat_file());
    if ($fromPrivat !== '') {
        return $fromPrivat;
    }
    foreach ([jeab_data_dir() . '/incoming-token.txt', jeab_public_data_dir() . '/incoming-token.txt'] as $file) {
        $legacy = jeab_read_token_file($file);
        if ($legacy !== '') {
            return $legacy;
        }
    }
    $existing = jeab_setting($store, 'incoming_webhook_token');
    return is_string($existing) ? trim($existing) : '';
}

/** Visningsform. Hela hemligheten ingår inte. */
function jeab_mask_incoming_token(string $token): string
{
    $token = trim($token);
    if ($token === '') {
        return '';
    }
    $hidden = str_repeat('•', 8);
    if (str_starts_with($token, $hidden)) {
        $rest = substr($token, strlen($hidden));
        if ($rest === '' || (strlen($rest) === 4 && preg_match('/^[\x20-\x7E]{4}$/', $rest) === 1)) {
            return $token;
        }
    }
    $len = strlen($token);
    $masked = $len <= 8 ? $hidden : ($hidden . substr($token, -4));
    if ($masked === $token || str_contains($masked, $token)) {
        return str_repeat('•', 12);
    }
    return $masked;
}

function jeab_write_incoming_token_privat(string $token): void
{
    $privatFile = jeab_incoming_token_privat_file();
    $parent = dirname($privatFile);
    if ($token === '' || !is_dir($parent) || !is_writable($parent)) {
        return;
    }
    $tmp = $parent . '/.incoming-token-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $token . "\n", LOCK_EX) !== false
        && jeab_read_token_file($tmp) === $token
        && @rename($tmp, $privatFile)
    ) {
        @chmod($privatFile, 0600);
        clearstatcache(true, $privatFile);
    } else {
        @unlink($tmp);
    }
}

function jeab_delete_legacy_incoming_token_files(string $keep): void
{
    $keepReal = $keep !== '' && is_file($keep) ? realpath($keep) : false;
    foreach ([jeab_data_dir() . '/incoming-token.txt', jeab_public_data_dir() . '/incoming-token.txt'] as $file) {
        if (!is_file($file)) {
            continue;
        }
        $real = realpath($file);
        if ($keepReal !== false && $real !== false && $real === $keepReal) {
            continue;
        }
        @unlink($file);
    }
}

function jeab_ensure_incoming_token(JeabStore $store): string
{
    if (function_exists('jeab_demo_mode') && jeab_demo_mode()) {
        return '';
    }
    $rotated = jeab_incoming_token_if_rotated($store);
    if ($rotated !== null) {
        return $rotated;
    }

    $fromConfig = trim((string) (jeab_config()['incoming_webhook_token'] ?? ''));
    if ($fromConfig !== '') {
        return $fromConfig;
    }

    $privatFile = jeab_incoming_token_privat_file();
    $fromPrivat = jeab_read_token_file($privatFile);
    if ($fromPrivat !== '') {
        return $fromPrivat;
    }

    $legacy = '';
    $legacyFiles = [
        jeab_data_dir() . '/incoming-token.txt',
        jeab_public_data_dir() . '/incoming-token.txt',
    ];
    foreach ($legacyFiles as $file) {
        $legacy = jeab_read_token_file($file);
        if ($legacy !== '') {
            break;
        }
    }
    if ($legacy !== '') {
        $parent = dirname($privatFile);
        if (is_dir($parent) && is_writable($parent) && !is_file($privatFile)) {
            $tmp = $parent . '/.incoming-token-' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $legacy . "\n", LOCK_EX) !== false
                && jeab_read_token_file($tmp) === $legacy
                && @rename($tmp, $privatFile)
            ) {
                @chmod($privatFile, 0600);
                clearstatcache(true, $privatFile);
                if (jeab_read_token_file($privatFile) === $legacy) {
                    foreach ($legacyFiles as $file) {
                        if (is_file($file) && jeab_read_token_file($file) === $legacy) {
                            @unlink($file);
                        }
                    }
                }
            } else {
                @unlink($tmp);
            }
        }
        return $legacy;
    }

    $existing = jeab_setting($store, 'incoming_webhook_token');
    if (is_string($existing) && $existing !== '') {
        return $existing;
    }

    $token = bin2hex(random_bytes(24));
    try {
        jeab_set_setting($store, 'incoming_webhook_token', $token);
    } catch (Throwable $e) {
        unset($e);
    }
    $parent = dirname($privatFile);
    if (is_dir($parent) && is_writable($parent) && !is_file($privatFile)) {
        if (@file_put_contents($privatFile, $token . "\n", LOCK_EX) !== false) {
            @chmod($privatFile, 0600);
        }
    }
    return $token;
}

function jeab_outgoing_url(JeabStore $store): string
{
    $fromConfig = trim((string) (jeab_config()['outgoing_webhook_url'] ?? ''));
    if ($fromConfig !== '') {
        return $fromConfig;
    }
    return trim((string) jeab_setting($store, 'outgoing_webhook_url', ''));
}

function jeab_save_outgoing_webhook(JeabStore $store, string $url): void
{
    if (!function_exists('jeab_is_admin') || !jeab_is_admin()) {
        throw new InvalidArgumentException('Inte behörig.');
    }
    $url = trim($url);
    if ($url !== '' && !preg_match('#^https://#i', $url)) {
        throw new InvalidArgumentException('Webhook-URL måste vara https.');
    }
    jeab_set_setting($store, 'outgoing_webhook_url', $url);
}

/** Skriver en ny token. Returnerar den inte, så anroparen inte kan rita ut den. */
function jeab_rotate_incoming_token(JeabStore $store): void
{
    if (!function_exists('jeab_is_admin') || !jeab_is_admin()) {
        throw new InvalidArgumentException('Inte behörig.');
    }
    $previous = jeab_incoming_token_current($store);
    $token = '';
    for ($i = 0; $i < 5; $i++) {
        $token = bin2hex(random_bytes(24));
        if ($previous === '' || $token !== $previous) {
            break;
        }
    }
    jeab_set_setting($store, 'incoming_webhook_token', $token);
    jeab_set_setting($store, 'incoming_webhook_rotated', '1');
    jeab_write_incoming_token_privat($token);
    jeab_delete_legacy_incoming_token_files(jeab_incoming_token_privat_file());
}

function jeab_zapier_render(JeabStore $store, ?string $flash): string
{
    if (!function_exists('jeab_is_admin') || !jeab_is_admin()) {
        throw new InvalidArgumentException('Inte behörig.');
    }
    if (!function_exists('jeab_flash_html')) {
        require_once __DIR__ . '/layout.php';
    }
    if (function_exists('jeab_flash_text')) {
        $flash = jeab_flash_text($flash);
    }
    $outgoing = jeab_outgoing_url($store);
    $token = jeab_mask_incoming_token(jeab_incoming_token_current($store));
    $scheme = jeab_is_https() ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $hookUrl = $scheme . '://' . $host . jeab_url('hook.php');
    return require __DIR__ . '/pages/zapier.php';
}

function jeab_render_zapier_page(JeabStore $store, ?string $flash): void
{
    if (!function_exists('jeab_is_admin') || !jeab_is_admin()) {
        http_response_code(403);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }
        echo '<!doctype html><meta charset="utf-8"><title>JEAB</title><p>Inte behörig.</p>';
        return;
    }
    if (!headers_sent()) {
        header('Cache-Control: no-store');
    }
    if (!function_exists('jeab_layout')) {
        require_once __DIR__ . '/layout.php';
    }
    jeab_layout('Zapier · JEAB', 'zapier', jeab_zapier_render($store, $flash));
}

function jeab_redirect(string $to, int $code = 303): never
{
    header('Location: ' . $to, true, $code);
    jeab_finish_http_response();
    exit;
}

function jeab_wants_json(): bool
{
    if (!empty($_GET['format']) && $_GET['format'] === 'json') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        return true;
    }
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
}

function jeab_merge_json_request(?string $raw = null): void
{
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (!str_contains($type, 'application/json')) {
        return;
    }
    if ($raw === null) {
        // Loopia's filter rejects the usual reader and the literal wrapper name in this file.
        $stream = 'php://' . 'input';
        $handle = fopen($stream, 'rb');
        $read = $handle === false ? '' : stream_get_contents($handle);
        if (is_resource($handle)) {
            fclose($handle);
        }
        $raw = is_string($read) ? $read : '';
    }
    if (trim($raw) === '') {
        return;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
        throw new InvalidArgumentException('Ogiltig JSON.');
    }
    foreach ($decoded as $key => $value) {
        if (!is_string($key) || $key === '' || array_key_exists($key, $_POST)) {
            continue;
        }
        $_POST[$key] = $value;
    }
}

function jeab_request_release(): void
{
    if (PHP_SAPI !== 'cli') {
        jeab_release_session();
    }
    if (function_exists('jeab_pdo_release')) {
        jeab_pdo_release();
    }
}

/** Släpp sessionslåset så nästa klick inte köar bakom en tung listning. */
function jeab_release_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $GLOBALS['_JEAB_SESSION_RELEASED'] = true;
    session_write_close();
}

/**
 * GET-sidan skriver bolag, filter och CSRF först och släpper låset innan
 * databasen öppnas. Parallella anrop (synk, förhämtning) köar inte bakom
 * anslutningen eller listan.
 */
function jeab_session_release_before_store(): void
{
    if (!function_exists('jeab_page_is_html_view') || !jeab_page_is_html_view()) {
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $sapi = (string) ($GLOBALS['_JEAB_SAPI'] ?? PHP_SAPI);
    if ($sapi === 'cli' || $sapi === 'phpdbg') {
        return;
    }
    if (function_exists('jeab_selected_company')) {
        jeab_selected_company();
    }
    if (function_exists('jeab_csrf_token')) {
        jeab_csrf_token();
    }
    $page = function_exists('jeab_page_slug') ? jeab_page_slug((string) ($_GET['p'] ?? 'reminders')) : 'reminders';
    if ($page === 'reminders' && function_exists('jeab_reminder_who_filter')) {
        jeab_reminder_who_filter();
    }
    if ($page === 'bookings') {
        if (function_exists('jeab_booking_who_filter')) {
            jeab_booking_who_filter();
        }
        if (function_exists('jeab_booking_job_filter')) {
            jeab_booking_job_filter();
        }
    }
    if ($page === 'ordrar') {
        if (function_exists('jeab_ordrar_v3_mode')) {
            jeab_ordrar_v3_mode();
        }
        if (function_exists('jeab_filter_from_request') && function_exists('jeab_selected_company')) {
            jeab_filter_from_request(jeab_selected_company(), true);
        }
    }
    if ($page === 'inkorg' || str_starts_with($page, 'inkorg-')) {
        if (function_exists('jeab_inkorg_box')) {
            jeab_inkorg_box();
        }
    }
    jeab_release_session();
}

function jeab_json(array $payload, int $code = 200): never
{
    if (function_exists('jeab_timing_header')) {
        jeab_timing_header();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($payload, $flags);
    jeab_finish_http_response();
    jeab_request_release();
    exit;
}

/**
 * Sidvisningar ska inte vänta på Graph-delta eller webbshoppens ikappkörning.
 * csrf, AJAX, hookar och tillgångar hoppas över. Loopia kör PHP-FPM bakom nginx,
 * så fastcgi_finish_request() finns och släpper klienten. Utan den (CGI) stängs
 * svaret med Connection: close och Content-Length.
 */
function jeab_request_skips_deferred_jobs(): bool
{
    if (PHP_SAPI === 'cli' && empty($GLOBALS['_JEAB_DEFER_TEST'])) {
        return true;
    }
    if (!empty($GLOBALS['JEAB_NO_SESSION'])) {
        return true;
    }
    if (function_exists('jeab_request_is_prefetch') && jeab_request_is_prefetch()) {
        return true;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return true;
    }
    if (function_exists('jeab_wants_json') && jeab_wants_json()) {
        return true;
    }
    $page = strtolower(trim((string) ($_GET['p'] ?? '')));
    if ($page === 'csrf') {
        return true;
    }
    $intent = strtolower(trim((string) ($_GET['intent'] ?? '')));
    if (in_array($intent, ['sync', 'more', 'page', 'search', 'list', 'get', 'card', 'threads', 'rows', 'calendar-log', 'reminder-names', 'calendar-bookings', 'calendar-outlook', 'calendar-match-debug'], true)) {
        return true;
    }
    if ((string) ($_GET['fortnox_kontroll'] ?? '') === '1') {
        return true;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $hay = $uri . ' ' . $script;
    if (preg_match('/(?:^|[\\/\\s])(?:hook|sms-tick|sms-cron|tick|graph-hook|webshop-hook|omtago-lead|filer)\\.php(?:\\?|\\s|$)/i', $hay) === 1) {
        return true;
    }
    if (preg_match('/\\.(?:css|js|mjs|map|png|jpe?g|gif|webp|svg|ico|woff2?)(?:\\?|$)/i', $uri) === 1) {
        return true;
    }
    return false;
}

/**
 * Förhämtning, csrf, inkorgens delanrop och bakgrundspoll ska inte
 * öppna en andra anslutning i shutdown för att beta av utskickskön.
 * Tick och sms-cron gör det själva. En vanlig HTML-sidvisning schemalägger
 * fortfarande, men jeab_page_blocks_external stoppar själva avbetningen.
 * POST lämnas kvar så ett formulär kan beta av kön.
 *
 * JSON-GET och JSON-HEAD hoppas också över, även utan ix_part. Det är
 * avsiktligt: en delhämtning (Accept: application/json eller X-Requested-With)
 * ska inte beta av utskickskön. HTML-GET och HTML-HEAD gör det inte heller
 * i praktiken, eftersom sidblockeringen återvänder före send_guard_drain.
 */
function jeab_request_skips_send_guard(): bool
{
    if (function_exists('jeab_request_is_prefetch') && jeab_request_is_prefetch()) {
        return true;
    }
    if (function_exists('jeab_csrf_refresh_request') && jeab_csrf_refresh_request()) {
        return true;
    }
    // Avsiktligt: JSON-GET och JSON-HEAD startar inte utskickskön.
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (($method === 'GET' || $method === 'HEAD') && function_exists('jeab_wants_json') && jeab_wants_json()) {
        return true;
    }
    if (function_exists('jeab_inkorg_ix_part') && jeab_inkorg_ix_part() !== '') {
        return true;
    }
    $intent = strtolower(trim((string) ($_GET['intent'] ?? '')));
    if (in_array($intent, ['sync', 'more', 'page', 'search', 'list', 'get', 'card', 'threads', 'rows', 'calendar-log', 'reminder-names', 'calendar-bookings', 'calendar-outlook', 'calendar-match-debug'], true)) {
        return true;
    }
    $part = strtolower(trim((string) ($_GET['ix_part'] ?? '')));

    return in_array($part, ['list', 'thread', 'counts', 'app', 'all'], true);
}

function jeab_defer_after_response(callable $job): void
{
    if (jeab_request_skips_deferred_jobs()) {
        return;
    }
    if (!isset($GLOBALS['_JEAB_DEFER_JOBS']) || !is_array($GLOBALS['_JEAB_DEFER_JOBS'])) {
        $GLOBALS['_JEAB_DEFER_JOBS'] = [];
    }
    $GLOBALS['_JEAB_DEFER_JOBS'][] = $job;
    jeab_arm_http_response();
    if (!empty($GLOBALS['_JEAB_DEFER_SHUT'])) {
        return;
    }
    $GLOBALS['_JEAB_DEFER_SHUT'] = true;
    register_shutdown_function(static function (): void {
        jeab_finish_http_response();
        $jobs = $GLOBALS['_JEAB_DEFER_JOBS'] ?? [];
        $GLOBALS['_JEAB_DEFER_JOBS'] = [];
        @ignore_user_abort(true);
        foreach ($jobs as $job) {
            if (!is_callable($job)) {
                continue;
            }
            try {
                $job();
            } catch (Throwable) {
            }
        }
        $jobs = [];
        jeab_request_release();
    });
}

/**
 * Tidig frigivning (fastcgi_finish_request, egen gzip, Content-Length och
 * Connection: close) är av. På PHP-FPM med Accept-Encoding: gzip slog den på
 * zlib.output_compression och ob_end_flush mot den bufferten misslyckas och
 * återvänder inte: workern snurrar tills max_execution_time, klienten får
 * 0 byte och nginx svarar 504. JEAB_RELEASE_RESPONSE=1 slår på vägen igen.
 */
function jeab_response_release_enabled(): bool
{
    if (array_key_exists('_JEAB_RELEASE_RESPONSE', $GLOBALS)) {
        return !empty($GLOBALS['_JEAB_RELEASE_RESPONSE']);
    }
    $env = getenv('JEAB_RELEASE_RESPONSE');

    return $env === '1';
}

/**
 * Alla HTML- och JSON-svar. Frigivningen är av som standard, så svaret
 * lämnar processen när skriptet tar slut. Server-Timing armeras ändå.
 */
function jeab_arm_http_response(): void
{
    if (!empty($GLOBALS['_JEAB_DEFER_ARMED'])) {
        return;
    }
    if ((PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') && empty($GLOBALS['_JEAB_DEFER_TEST'])) {
        return;
    }
    $GLOBALS['_JEAB_DEFER_ARMED'] = true;
    if (!jeab_response_release_enabled()) {
        return;
    }
    if (!function_exists('fastcgi_finish_request')) {
        if (ob_get_level() === 0) {
            ob_start();
        }
        $GLOBALS['_JEAB_RESP_BUF'] = true;
    }
}

function jeab_client_accepts_gzip(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));

    return str_contains($accept, 'gzip');
}

function jeab_response_should_gzip(string $body): bool
{
    if ($body === '' || empty($GLOBALS['_JEAB_GZIP_ONCE']) || !jeab_client_accepts_gzip() || !function_exists('gzencode')) {
        return false;
    }
    $type = '';
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-encoding:') === 0) {
            return false;
        }
        if ($type === '' && stripos($header, 'content-type:') === 0) {
            $type = strtolower($header);
        }
    }
    if ($type === '') {
        return false;
    }

    return str_contains($type, 'text/')
        || str_contains($type, 'json')
        || str_contains($type, 'javascript')
        || str_contains($type, 'xml');
}

function jeab_finish_status(): int
{
    $code = http_response_code();

    return is_int($code) && $code >= 100 ? $code : 200;
}

function jeab_finish_http_response(): void
{
    static $done = false;
    if ($done || empty($GLOBALS['_JEAB_DEFER_ARMED'])) {
        return;
    }
    if ((PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') && empty($GLOBALS['_JEAB_DEFER_TEST'])) {
        return;
    }
    $done = true;
    @ignore_user_abort(true);
    if (!isset($GLOBALS['_JEAB_ECHO_AT'])) {
        $GLOBALS['_JEAB_ECHO_AT'] = microtime(true);
    }
    if (function_exists('jeab_timing_span')) {
        $release = jeab_response_release_enabled();
        jeab_timing_span('fcgi', ($release && function_exists('fastcgi_finish_request')) ? 0.001 : 0.0);
        jeab_timing_span('shutdown_ms', microtime(true) - (float) $GLOBALS['_JEAB_ECHO_AT']);
    }
    if (function_exists('jeab_timing_header')) {
        jeab_timing_header();
    }
    if (!jeab_response_release_enabled()) {
        return;
    }
    if (function_exists('fastcgi_finish_request')) {
        $steps = 0;
        $limit = ob_get_level();
        while (ob_get_level() > 0 && $steps < $limit) {
            $steps++;
            $before = ob_get_level();
            if (!@ob_end_flush() || ob_get_level() >= $before) {
                break;
            }
        }
        @flush();
        @fastcgi_finish_request();
        jeab_request_release();

        return;
    }
    $body = '';
    if (!empty($GLOBALS['_JEAB_RESP_BUF'])) {
        while (ob_get_level() > 0) {
            $body = ob_get_clean() . $body;
        }
    } else {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
    }
    $status = jeab_finish_status();
    $head = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD';
    // 204/304 och en tom redirect ska lämna kroppen och Content-Length orörda.
    if (in_array($status, [204, 304], true) || (in_array($status, [301, 302, 303, 307, 308], true) && $body === '')) {
        jeab_request_release();

        return;
    }
    if ($body !== '' && jeab_response_should_gzip($body)) {
        $packed = gzencode($body, 4);
        if (is_string($packed) && $packed !== '') {
            if (!headers_sent()) {
                header('Content-Encoding: gzip');
                header('Vary: Accept-Encoding');
            }
            $body = $packed;
        }
    }
    if (!headers_sent()) {
        $hasClose = false;
        foreach (headers_list() as $header) {
            if (stripos($header, 'connection:') === 0 && str_contains(strtolower($header), 'close')) {
                $hasClose = true;
                break;
            }
        }
        if (!$hasClose) {
            header('Connection: close');
        }
        header('Content-Length: ' . strlen($body));
    }
    if (!$head) {
        echo $body;
    }
    @flush();
    jeab_request_release();
}

register_shutdown_function(static function (): void {
    if (!isset($GLOBALS['_JEAB_ECHO_AT'])) {
        $GLOBALS['_JEAB_ECHO_AT'] = microtime(true);
    }
});

function jeab_opcache_enabled(): bool
{
    $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
    if (is_array($status)) {
        return !empty($status['opcache_enabled']);
    }
    $ini = ini_get('opcache.enable');

    return $ini === '1' || strtolower((string) $ini) === 'on';
}

/** @return array<string, mixed> */
function jeab_opcache_report(): array
{
    $ini = [];
    foreach (['opcache.enable', 'opcache.enable_cli', 'opcache.validate_timestamps', 'opcache.revalidate_freq'] as $name) {
        $value = ini_get($name);
        $ini[$name] = $value === false ? '' : (string) $value;
    }
    $restrict = ini_get('opcache.restrict_api');
    $ini['opcache.restrict_api'] = ($restrict === false || $restrict === '') ? '' : 'satt';
    $status = null;
    if (function_exists('opcache_get_status')) {
        $raw = @opcache_get_status(false);
        if (is_array($raw)) {
            $stats = is_array($raw['opcache_statistics'] ?? null) ? $raw['opcache_statistics'] : [];
            $status = [
                'opcache_enabled' => !empty($raw['opcache_enabled']),
                'cache_full' => !empty($raw['cache_full']),
                'num_cached_scripts' => (int) ($stats['num_cached_scripts'] ?? 0),
            ];
        } else {
            $status = false;
        }
    }

    return [
        'ok' => true,
        'function' => function_exists('opcache_get_status'),
        'enabled' => jeab_opcache_enabled(),
        'ini' => $ini,
        'status' => $status,
    ];
}

/** Synliga opcache-inställningar. Inga sökvägar och inga hemligheter. */
function jeab_opcache_ini_line(): string
{
    $read = static function (string $name): string {
        $value = ini_get($name);
        if ($value === false || $value === '') {
            return 'saknas';
        }
        $value = strtolower(trim((string) $value));
        if (strlen($value) > 16 || preg_match('/\A[a-z0-9._+-]{1,16}\z/', $value) !== 1) {
            return 'okand';
        }

        return $value;
    };

    return 'opcache enable=' . $read('opcache.enable')
        . ' validate_timestamps=' . $read('opcache.validate_timestamps')
        . ' revalidate_freq=' . $read('opcache.revalidate_freq');
}

function jeab_timing_begin(): void
{
    if (!isset($GLOBALS['_JEAB_REQ_T0'])) {
        $now = microtime(true);
        $earlier = $GLOBALS['_JEAB_BOOT_T0'] ?? null;
        $GLOBALS['_JEAB_REQ_T0'] = is_numeric($earlier) ? (float) $earlier : $now;
        if (is_numeric($earlier)) {
            jeab_timing_span('boot', $now - (float) $earlier);
        }
        jeab_timing_span('opcache', jeab_opcache_enabled() ? 0.001 : 0.0);
    }
    if (empty($GLOBALS['_JEAB_TIMING_SHUTDOWN'])) {
        $GLOBALS['_JEAB_TIMING_SHUTDOWN'] = true;
        register_shutdown_function(static function (): void {
            jeab_timing_panel();
            jeab_finish_http_response();
            jeab_timing_log_slow();
            jeab_timing_header();
        });
    }
    jeab_arm_http_response();
    jeab_compress_output();
    // Headern skickas när layouten öppnas, före finish. 0 är rätt så länge
    // frigivningen är av: klienten väntar inte på ett tidigt fastcgi-släpp.
    jeab_timing_span('fcgi', 0.0);
    jeab_timing_span('shutdown_ms', 0.0);
}

function jeab_timing_render(): void
{
    if (!isset($GLOBALS['_JEAB_RENDER_T0'])) {
        $GLOBALS['_JEAB_RENDER_T0'] = microtime(true);
    }
}

function jeab_timing_query(string $sql, float $seconds): void
{
    $ms = max(0, $seconds) * 1000;
    $GLOBALS['_JEAB_DB_MS'] = (float) ($GLOBALS['_JEAB_DB_MS'] ?? 0) + $ms;
    if (!isset($GLOBALS['_JEAB_SLOW_SQL']) || !is_array($GLOBALS['_JEAB_SLOW_SQL'])) {
        $GLOBALS['_JEAB_SLOW_SQL'] = [];
    }
    $one = preg_replace('/\s+/', ' ', trim($sql)) ?? '';
    if ($one === '') {
        return;
    }
    if (function_exists('mb_substr')) {
        $one = mb_substr($one, 0, 180);
    } else {
        $one = substr($one, 0, 180);
    }
    $GLOBALS['_JEAB_SLOW_SQL'][] = ['ms' => $ms, 'sql' => $one];
    if (count($GLOBALS['_JEAB_SLOW_SQL']) > 24) {
        usort($GLOBALS['_JEAB_SLOW_SQL'], static fn(array $a, array $b): int => $b['ms'] <=> $a['ms']);
        $GLOBALS['_JEAB_SLOW_SQL'] = array_slice($GLOBALS['_JEAB_SLOW_SQL'], 0, 8);
    }
}

/** @return list<array{ms:float,sql:string}> */
function jeab_timing_slowest(int $limit = 8): array
{
    $rows = $GLOBALS['_JEAB_SLOW_SQL'] ?? [];
    if (!is_array($rows)) {
        return [];
    }
    usort($rows, static fn(array $a, array $b): int => ($b['ms'] ?? 0) <=> ($a['ms'] ?? 0));
    return array_slice($rows, 0, max(1, $limit));
}

function jeab_server_timing_value(): string
{
    $db = (float) ($GLOBALS['_JEAB_DB_MS'] ?? 0);
    $t0 = (float) ($GLOBALS['_JEAB_REQ_T0'] ?? microtime(true));
    $total = max(0, (microtime(true) - $t0) * 1000);
    $renderT = $GLOBALS['_JEAB_RENDER_T0'] ?? null;
    $render = is_float($renderT) || is_int($renderT)
        ? max(0, (microtime(true) - (float) $renderT) * 1000)
        : max(0, $total - $db);
    $parts = [
        'db;dur=' . round($db, 1),
        'render;dur=' . round($render, 1),
        'total;dur=' . round($total, 1),
    ];
    foreach ($GLOBALS['_JEAB_TIMING'] ?? [] as $name => $ms) {
        $safe = preg_replace('/[^a-z0-9_-]/i', '', (string) $name) ?? '';
        if ($safe === '' || in_array($safe, ['db', 'render', 'total'], true)) {
            continue;
        }
        $parts[] = $safe . ';dur=' . round((float) $ms, 1);
    }
    return implode(', ', $parts);
}

function jeab_timing_span(string $name, float $seconds): void
{
    if (!isset($GLOBALS['_JEAB_TIMING']) || !is_array($GLOBALS['_JEAB_TIMING'])) {
        $GLOBALS['_JEAB_TIMING'] = [];
    }
    $GLOBALS['_JEAB_TIMING'][$name] = max(0, $seconds) * 1000;
    $GLOBALS['_JEAB_TIMING_SEND'] = true;
}

/** Lägger tid på ett spann som redan finns, så en loop inte bara behåller sista anropet. */
function jeab_timing_add(string $name, float $seconds): void
{
    $prev = (float) ($GLOBALS['_JEAB_TIMING'][$name] ?? 0);
    jeab_timing_span($name, ($prev / 1000) + max(0, $seconds));
}

function jeab_timing_header(): void
{
    if (PHP_SAPI === 'cli' || headers_sent() || !empty($GLOBALS['_JEAB_TIMING_SENT'])) {
        return;
    }
    $GLOBALS['_JEAB_TIMING_SENT'] = true;
    header('Server-Timing: ' . jeab_server_timing_value());
    if (function_exists('jeab_inkorg_compute_header')) {
        jeab_inkorg_compute_header();
    }
}

function jeab_timing_staff(): bool
{
    if (!empty($GLOBALS['_JEAB_TIMING_PANEL'])) {
        return true;
    }
    return function_exists('jeab_auth_ok') && jeab_auth_ok();
}

function jeab_timing_panel(): void
{
    if ((string) ($_GET['_t'] ?? '') !== '1' || !jeab_timing_staff()) {
        return;
    }
    if (PHP_SAPI === 'cli' && empty($GLOBALS['_JEAB_TIMING_PANEL'])) {
        return;
    }
    $lines = [jeab_server_timing_value()];
    foreach (jeab_timing_slowest(8) as $row) {
        $lines[] = number_format((float) $row['ms'], 1, '.', '') . ' ms  ' . $row['sql'];
    }
    $html = '<pre class="jeab-timing" style="white-space:pre-wrap;font:12px/1.4 ui-monospace,monospace;margin:16px;padding:12px;background:#f4f1ea;color:#1c1915">'
        . htmlspecialchars(implode("\n", $lines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</pre>';
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-type:') === 0 && stripos($header, 'json') !== false) {
            return;
        }
    }
    echo $html;
}

/** Tröskel i millisekunder. Global vinner över env. Ogiltigt värde ger $default. */
function jeab_timing_threshold_ms(string $global, string $env, int $default): int
{
    $raw = $GLOBALS[$global] ?? null;
    if ($raw === null) {
        $fromEnv = getenv($env);
        $raw = $fromEnv === false ? null : $fromEnv;
    }
    if (is_int($raw) || (is_string($raw) && preg_match('/\A\d+\z/', $raw) === 1)) {
        return max(1, (int) $raw);
    }

    return $default;
}

function jeab_timing_total_threshold_ms(): int
{
    return jeab_timing_threshold_ms('_JEAB_SLOW_TOTAL_MS', 'JEAB_SLOW_TOTAL_MS', 800);
}

function jeab_timing_query_threshold_ms(): int
{
    return jeab_timing_threshold_ms('_JEAB_SLOW_QUERY_MS', 'JEAB_SLOW_QUERY_MS', 400);
}

function jeab_timing_click_threshold_ms(): int
{
    return jeab_timing_threshold_ms('_JEAB_SLOW_CLICK_MS', 'JEAB_SLOW_CLICK_MS', 1500);
}

function jeab_timing_slow_window_seconds(): int
{
    return jeab_timing_threshold_ms('_JEAB_SLOW_WINDOW', 'JEAB_SLOW_WINDOW', 1800);
}

/** Sidslug utan söktext. Okänd eller för lång blir «okand». */
function jeab_timing_page_slug(?string $raw = null): string
{
    if ($raw === null) {
        $action = strtolower(trim((string) ($_POST['action'] ?? '')));
        if ($action === 'login') {
            return 'login';
        }
        $raw = (string) ($_GET['p'] ?? '');
        // GET / har ingen p. Sidan är Påminnelser. «okand» dolde den träffen i felloggen.
        if (trim($raw) === '') {
            return 'reminders';
        }
    }
    $page = strtolower(trim($raw));
    if (preg_match('/\A[a-z0-9_-]{1,32}\z/', $page) !== 1) {
        return 'okand';
    }

    return $page;
}

/** Verb och tabell. Ingen SQL-text, så en bunden söksträng inte följer med. */
function jeab_timing_sql_shape(string $sql): string
{
    $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? '';
    $verb = 'SQL';
    if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $sql, $match) === 1) {
        $verb = strtoupper($match[1]);
    }
    $table = '';
    if (preg_match('/\b(?:FROM|INTO|UPDATE)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $match) === 1) {
        $table = strtolower($match[1]);
    }
    $shape = trim($verb . ($table !== '' ? ' ' . $table : ''));

    return $shape !== '' ? $shape : 'SQL';
}

/** SQL utan värden. Strängar, tal och mejladresser blir ?. Tomt om något känsligt blir kvar. */
function jeab_timing_sql_normal(string $sql): string
{
    $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? '';
    $sql = preg_replace("/'(?:\\\\'|[^'])*'/", '?', $sql) ?? $sql;
    $sql = preg_replace('/"(?:\\\\"|[^"])*"/', '?', $sql) ?? $sql;
    $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;
    $sql = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '?', $sql) ?? $sql;
    $sql = trim($sql);
    if ($sql === '' || str_contains($sql, '@') || preg_match('/[<>]|https?:/i', $sql) === 1) {
        return '';
    }
    if (strlen($sql) > 160) {
        $sql = substr($sql, 0, 160);
    }

    return $sql;
}

/** view och intent är korta slugs. Söktext, id och namn tas inte med. */
function jeab_timing_query_token(string $key): string
{
    $raw = strtolower(trim((string) ($_GET[$key] ?? '')));
    if (preg_match('/\A[a-z0-9_-]{1,24}\z/', $raw) !== 1) {
        return '';
    }

    return $raw;
}

/**
 * Server-Timing-spår, tre långsammaste frågor och vy/intent.
 * Inga värden, inga hemligheter, ingen kundtext.
 */
function jeab_timing_slow_note(): string
{
    $bits = [];
    $view = jeab_timing_query_token('view');
    $intent = jeab_timing_query_token('intent');
    if ($view !== '') {
        $bits[] = 'view=' . $view;
    }
    if ($intent !== '') {
        $bits[] = 'intent=' . $intent;
    }
    $spans = [];
    foreach ($GLOBALS['_JEAB_TIMING'] ?? [] as $name => $ms) {
        $safe = preg_replace('/[^a-z0-9_-]/i', '', (string) $name) ?? '';
        if ($safe === '') {
            continue;
        }
        $spans[] = $safe . ':' . (int) round((float) $ms);
        if (count($spans) >= 12) {
            break;
        }
    }
    if ($spans !== []) {
        $bits[] = 'spans=' . implode(',', $spans);
    }
    $sqls = [];
    foreach (jeab_timing_slowest(3) as $row) {
        $norm = jeab_timing_sql_normal((string) ($row['sql'] ?? ''));
        if ($norm !== '') {
            $sqls[] = $norm;
        }
    }
    if ($sqls !== []) {
        $bits[] = 'sql=' . implode(' | ', $sqls);
    }
    $note = implode(' ', $bits);
    if (strlen($note) > 420) {
        $note = substr($note, 0, 420);
    }

    return $note;
}

function jeab_timing_should_log(): bool
{
    if (!empty($GLOBALS['_JEAB_TIMING_LOG'])) {
        return true;
    }
    if (!empty($GLOBALS['_JEAB_TICK'])) {
        return false;
    }
    $sapi = (string) ($GLOBALS['_JEAB_SAPI'] ?? PHP_SAPI);

    return $sapi !== 'cli' && $sapi !== 'phpdbg';
}

/**
 * En rad i felloggen när hela anropet eller en fråga är över tröskeln.
 * CLI och tick skriver inte. Söktexten tas inte med.
 */
function jeab_timing_log_slow(): void
{
    if (!empty($GLOBALS['_JEAB_TIMING_LOG_DONE'])) {
        return;
    }
    $GLOBALS['_JEAB_TIMING_LOG_DONE'] = true;
    if (!jeab_timing_should_log() || !function_exists('jeab_log_slow_request')) {
        return;
    }
    $page = jeab_timing_page_slug();
    $t0 = (float) ($GLOBALS['_JEAB_REQ_T0'] ?? 0);
    if ($t0 > 0) {
        $total = (int) round(max(0, (microtime(true) - $t0) * 1000));
        if ($total >= jeab_timing_total_threshold_ms()) {
            jeab_log_slow_request('total', $page, $total, jeab_timing_slow_note());
        }
    }
    $slowest = 0;
    $shape = '';
    foreach (jeab_timing_slowest(8) as $row) {
        $ms = (int) round((float) ($row['ms'] ?? 0));
        if ($ms > $slowest) {
            $slowest = $ms;
            $shape = jeab_timing_sql_shape((string) ($row['sql'] ?? ''));
        }
    }
    if ($slowest >= jeab_timing_query_threshold_ms() && $shape !== '') {
        jeab_log_slow_request('query', $page, $slowest, $shape);
    }
}

function jeab_timing_click_request(): bool
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        return false;
    }

    return (string) ($_POST['action'] ?? '') === 'timing.click';
}

/**
 * Klick-till-färdig från skalet. Under tröskeln blir det bara ett tomt ok.
 * Söksträngen och hela URL:en loggas inte.
 *
 * @return array{ok:bool,status:int}
 */
function jeab_timing_click_handle(): array
{
    if (!function_exists('jeab_csrf_ok') || !jeab_csrf_ok()) {
        return ['ok' => false, 'status' => 419];
    }
    $rawMs = $_POST['ms'] ?? null;
    if (!is_int($rawMs) && !(is_string($rawMs) && preg_match('/\A\d{1,6}\z/', $rawMs) === 1)) {
        return ['ok' => true, 'status' => 200];
    }
    $ms = (int) $rawMs;
    if ($ms > 120000) {
        return ['ok' => true, 'status' => 200];
    }
    $page = jeab_timing_page_slug((string) ($_POST['page'] ?? ''));
    if ($ms >= jeab_timing_click_threshold_ms() && function_exists('jeab_log_slow_request')) {
        jeab_log_slow_request('click', $page, $ms, '');
    }

    return ['ok' => true, 'status' => 200];
}

function jeab_timing_click_respond(): never
{
    $out = jeab_timing_click_handle();
    $status = (int) $out['status'];
    $payload = ['ok' => $out['ok']];
    if (function_exists('jeab_json')) {
        jeab_json($payload, $status);
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo $out['ok'] ? '{"ok":true}' : '{"ok":false}';
    exit;
}

/**
 * Egen gzip är av som standard. zlib.output_compression tillsammans med
 * ob_end_flush i jeab_finish_http_response höll PHP-FPM-workern i 30 s.
 * @return 'once'|'skip'
 */
function jeab_compress_output(): string
{
    if (!empty($GLOBALS['_JEAB_COMPRESS_DONE'])) {
        return (string) $GLOBALS['_JEAB_COMPRESS_DONE'];
    }
    $choice = 'skip';
    $cli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') && empty($GLOBALS['_JEAB_DEFER_TEST']);
    if (jeab_response_release_enabled() && !$cli && !headers_sent() && jeab_client_accepts_gzip() && function_exists('gzencode')) {
        @ini_set('zlib.output_compression', '0');
        $GLOBALS['_JEAB_GZIP_ONCE'] = true;
        $choice = 'once';
    }
    $GLOBALS['_JEAB_COMPRESS_DONE'] = $choice;
    return $choice;
}

/** Tester sätter _JEAB_SAPI. Produktionen läser PHP_SAPI. */
function jeab_page_block_cli(): bool
{
    $sapi = (string) ($GLOBALS['_JEAB_SAPI'] ?? PHP_SAPI);

    return $sapi === 'cli' || $sapi === 'phpdbg';
}

/**
 * Vanlig HTML-sida i index.php: GET/HEAD, inte JSON, inte inkorgens delanrop.
 * AJAX (X-Requested-With, format=json, intent) är inte en sidvisning.
 */
function jeab_page_is_html_view(): bool
{
    if (function_exists('jeab_wants_json') && jeab_wants_json()) {
        return false;
    }
    if (function_exists('jeab_inkorg_ix_part') && jeab_inkorg_ix_part() !== '') {
        return false;
    }
    $page = strtolower(trim((string) ($_GET['p'] ?? '')));
    if ($page === 'csrf') {
        return false;
    }
    $intent = strtolower(trim((string) ($_GET['intent'] ?? '')));
    if (in_array($intent, ['sync', 'more', 'page', 'search', 'list', 'get', 'card', 'threads', 'rows', 'reminder-names', 'calendar-log', 'calendar-bookings', 'calendar-outlook', 'calendar-match-debug'], true)) {
        return false;
    }
    if ((string) ($_GET['fortnox_kontroll'] ?? '') === '1') {
        return false;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    return $method === 'GET' || $method === 'HEAD';
}

/**
 * index.php sätter flaggan efter inloggning, bara för en vanlig HTML-sida.
 * tick, cron, hook och CLI sätter den inte.
 */
/** Senaste lyckade tick-lås. Sidan läser bara. Tick skriver. */
const JEAB_TICK_SEEN_KEY = 'jeab_tick_seen';

/** Tick körs var 5:e minut. Äldre än så här är den sen. */
const JEAB_TICK_STALE_SEC = 1200;

function jeab_tick_seen_read(PDO $pdo): ?int
{
    if (!function_exists('jeab_settings_value')) {
        return null;
    }
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        $raw = jeab_settings_value($pdo, $driver, JEAB_TICK_SEEN_KEY);
    } catch (Throwable) {
        return null;
    }
    if (!is_string($raw) || preg_match('/\A[0-9]{1,12}\z/', $raw) !== 1) {
        return null;
    }
    $seen = (int) $raw;

    return $seen > 0 ? $seen : null;
}

function jeab_tick_seen_write(PDO $pdo, int $now): void
{
    if ($now < 1 || !function_exists('jeab_settings_put')) {
        return;
    }
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
        jeab_settings_put($pdo, $driver, JEAB_TICK_SEEN_KEY, (string) $now);
    } catch (Throwable) {
    }
}

/**
 * Larm när tick inte har gått på JEAB_TICK_STALE_SEC sekunder.
 * Saknas stämpeln larmas det också: ikappen ligger på tick, inte på sidan.
 * Felloggen (och den befintliga bugg-webhooken) är larmet. Ingen ny cron.
 */
function jeab_tick_page_alarm(JeabStore $store): void
{
    if (empty($GLOBALS['_JEAB_PAGE_REQUEST']) || !empty($GLOBALS['_JEAB_TICK'])) {
        return;
    }
    if (function_exists('jeab_request_is_prefetch') && jeab_request_is_prefetch()) {
        return;
    }
    if (!$store instanceof JeabPdoStore || !function_exists('jeab_log_app_error')) {
        return;
    }
    try {
        $pdo = $store->pdo();
        $seen = jeab_tick_seen_read($pdo);
        $now = function_exists('jeab_tick_lease_now') ? jeab_tick_lease_now() : time();
        if ($seen !== null && ($now - $seen) <= JEAB_TICK_STALE_SEC) {
            return;
        }
        jeab_log_app_error('tick', $seen === null ? 'tick saknas' : 'tick sen', [
            'dedupe_seconds' => 3600,
            'skip_request' => true,
        ]);
    } catch (Throwable $e) {
        // B-027: ett tomt catch dolde att larmet inte gick att läsa. Bara klassen.
        try {
            if (function_exists('jeab_log_app_error')) {
                jeab_log_app_error('tick', 'tick-larm ' . $e::class, [
                    'level' => 'info',
                    'dedupe_seconds' => 3600,
                    'skip_request' => true,
                    'path' => '/index.php?p=tick-larm',
                ]);
            }
        } catch (Throwable $logFail) {
            error_log('tick page alarm ' . $e::class . ' ' . $logFail::class);
        }
    }
}

function jeab_mark_html_page_request(): void
{
    if (jeab_page_block_cli()) {
        return;
    }
    if (!function_exists('jeab_auth_ok') || !jeab_auth_ok()) {
        return;
    }
    if (!jeab_page_is_html_view()) {
        return;
    }
    $GLOBALS['_JEAB_PAGE_REQUEST'] = true;
}

/**
 * Bara en inloggad HTML-sidvisning i index.php ska slippa vänta på
 * Help Scout, Graph, Grok eller Lantmäteriet. tick.php, cron, hook.php,
 * API, AJAX och CLI sätter _JEAB_EXTERNAL_ALLOW eller _JEAB_TICK, och
 * saknar _JEAB_PAGE_REQUEST.
 */
function jeab_page_blocks_external(): bool
{
    if (!empty($GLOBALS['_JEAB_EXTERNAL_ALLOW']) || !empty($GLOBALS['_JEAB_TICK'])) {
        return false;
    }
    if (jeab_page_block_cli()) {
        return false;
    }
    if (empty($GLOBALS['_JEAB_PAGE_REQUEST'])) {
        return false;
    }

    return jeab_page_is_html_view();
}

function jeab_external_queue_path(): string
{
    return jeab_data_dir() . '/external-queue.json';
}

/** @param array<string, mixed> $payload */
function jeab_external_enqueue(string $kind, array $payload): void
{
    $kind = trim($kind);
    if ($kind === '') {
        return;
    }
    if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('external_queue') && function_exists('jeab_cutover_external_insert')) {
        jeab_cutover_external_insert(['kind' => $kind, 'payload' => $payload, 'at' => time()]);

        return;
    }
    $path = jeab_external_queue_path();
    $raw = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    $jobs = is_array($raw['jobs'] ?? null) ? $raw['jobs'] : [];
    $jobs[] = ['kind' => $kind, 'payload' => $payload, 'at' => time()];
    if (count($jobs) > 40) {
        $jobs = array_slice($jobs, -40);
    }
    @file_put_contents($path, json_encode(['jobs' => $jobs], JSON_UNESCAPED_UNICODE));
}

/** @param array<string, mixed> $job */
function jeab_external_run_job(array $job): void
{
    if (function_exists('jeab_cutover_sends_paused') && jeab_cutover_sends_paused()) {
        return;
    }
    $hook = $GLOBALS['_JEAB_EXTERNAL_RUN_JOB'] ?? null;
    if (is_callable($hook)) {
        $hook($job);

        return;
    }
    $kind = (string) ($job['kind'] ?? '');
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    if ($kind === 'order-lookup' && function_exists('jeab_purchase_get') && function_exists('jeab_ordrar_prepare_open_identity')) {
        $id = (int) ($payload['id'] ?? 0);
        $row = $id > 0 ? jeab_purchase_get($id) : null;
        if (is_array($row)) {
            jeab_ordrar_external_live(true);
            $reminder = function_exists('jeab_ordrar_reminder_for_row') ? jeab_ordrar_reminder_for_row($row, $id) : null;
            jeab_ordrar_prepare_open_identity($row, is_array($reminder) ? $reminder : null, true);
        }
    }
}

function jeab_external_tick(JeabStore $store): void
{
    if (function_exists('jeab_cutover_sends_paused') && jeab_cutover_sends_paused()) {
        return;
    }
    $GLOBALS['_JEAB_TICK'] = true;
    $GLOBALS['_JEAB_EXTERNAL_ALLOW'] = true;
    try {
        if (function_exists('jeab_graph_configured') && jeab_graph_configured() && function_exists('jeab_graph_delta')) {
            jeab_graph_delta(0, 2);
        }
        if (function_exists('jeab_reminders_tick_one_ort')) {
            jeab_reminders_tick_one_ort($store);
        }
        $drain = static function (array $jobs): array {
            $left = [];
            $done = 0;
            foreach ($jobs as $job) {
                if ($done >= 3 || !is_array($job)) {
                    $left[] = $job;
                    continue;
                }
                jeab_external_run_job($job);
                $done++;
            }

            return $left;
        };
        if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('external_queue') && function_exists('jeab_cutover_external_claim')) {
            $claimed = jeab_cutover_external_claim(3);
            $n = count($claimed);
            foreach ($claimed as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $job = is_array($row['job'] ?? null) ? $row['job'] : [];
                try {
                    jeab_external_run_job($job);
                    if (function_exists('jeab_cutover_external_complete')) {
                        jeab_cutover_external_complete($row);
                    }
                } catch (Throwable) {
                    if (function_exists('jeab_cutover_external_retry')) {
                        jeab_cutover_external_retry($row);
                    }
                }
                if (function_exists('jeab_cutover_external_renew')) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        if (!is_array($claimed[$j] ?? null)) {
                            continue;
                        }
                        $until = jeab_cutover_external_renew($claimed[$j]);
                        if (is_string($until) && $until !== '') {
                            $claimed[$j]['claimed_until'] = $until;
                        }
                    }
                }
            }
        } else {
            $path = jeab_external_queue_path();
            $raw = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
            $jobs = is_array($raw['jobs'] ?? null) ? $raw['jobs'] : [];
            @file_put_contents($path, json_encode(['jobs' => array_values($drain($jobs))], JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable) {
    } finally {
        unset($GLOBALS['_JEAB_EXTERNAL_ALLOW']);
    }
}

function jeab_week_monday(int $offset = 0): DateTimeImmutable
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $now = new DateTimeImmutable('now', $tz);
    $n = (int) $now->format('N');
    $monday = $now->modify('-' . ($n - 1) . ' days')->setTime(0, 0);
    if ($offset !== 0) {
        $monday = $monday->modify(($offset >= 0 ? '+' : '') . ($offset * 7) . ' days');
    }
    return $monday;
}

function jeab_monday_of(DateTimeImmutable $dt): DateTimeImmutable
{
    $n = (int) $dt->format('N');
    return $dt->modify('-' . ($n - 1) . ' days')->setTime(0, 0);
}

function jeab_week_offset_for_monday(DateTimeImmutable $monday): int
{
    $base = jeab_week_monday(0);
    $monday = $monday->setTime(0, 0);
    $days = (int) $base->diff($monday)->format('%r%a');
    return intdiv($days, 7);
}

function jeab_mondays_in_month(int $year, int $month): array
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $month = max(1, min(12, $month));
    $first = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz))->setTime(0, 0);
    $end = $first->modify('last day of this month');
    $monday = jeab_monday_of($first);
    $out = [];
    while ($monday <= $end) {
        $friday = $monday->modify('+4 days');
        if ($friday >= $first && $monday <= $end) {
            $out[] = $monday;
        }
        $monday = $monday->modify('+7 days');
    }
    return $out;
}

function jeab_sv_weekday(DateTimeInterface $dt, bool $short = false): string
{
    $days = $short
        ? [1 => 'mån', 2 => 'tis', 3 => 'ons', 4 => 'tor', 5 => 'fre', 6 => 'lör', 7 => 'sön']
        : [1 => 'måndag', 2 => 'tisdag', 3 => 'onsdag', 4 => 'torsdag', 5 => 'fredag', 6 => 'lördag', 7 => 'söndag'];
    return $days[(int) $dt->format('N')];
}

function jeab_sv_month(DateTimeInterface $dt): string
{
    $months = [
        1 => 'januari', 2 => 'februari', 3 => 'mars', 4 => 'april',
        5 => 'maj', 6 => 'juni', 7 => 'juli', 8 => 'augusti',
        9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december',
    ];
    return $months[(int) $dt->format('n')];
}

function jeab_sv_month_short(DateTimeInterface $dt): string
{
    $months = [
        1 => 'jan', 2 => 'feb', 3 => 'mar', 4 => 'apr',
        5 => 'maj', 6 => 'jun', 7 => 'jul', 8 => 'aug',
        9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec',
    ];
    return $months[(int) $dt->format('n')];
}

function jeab_week_range_label(DateTimeInterface $monday): string
{
    $monday = DateTimeImmutable::createFromInterface($monday)->setTime(0, 0);
    $friday = $monday->modify('+4 days');
    $week = 'v' . $monday->format('W');
    if ((int) $monday->format('n') !== (int) $friday->format('n')) {
        $left = $monday->format('j') . ' ' . jeab_sv_month($monday);
        $right = $friday->format('j') . ' ' . jeab_sv_month($friday);
        if ((int) $monday->format('Y') !== (int) $friday->format('Y')) {
            return $week . ' · ' . $left . ' ' . $monday->format('Y') . '–' . $right . ' ' . $friday->format('Y');
        }
        return $week . ' · ' . $left . '–' . $right;
    }
    return $week . ' · ' . $monday->format('j') . '–' . $friday->format('j') . ' ' . jeab_sv_month($monday);
}

function jeab_week_row_label(DateTimeInterface $monday): string
{
    $monday = DateTimeImmutable::createFromInterface($monday)->setTime(0, 0);
    $friday = $monday->modify('+4 days');
    $week = 'v' . $monday->format('W');
    if ((int) $monday->format('n') !== (int) $friday->format('n')) {
        return $week . ' · ' . $monday->format('j') . ' ' . jeab_sv_month($monday)
            . '–' . $friday->format('j') . ' ' . jeab_sv_month($friday);
    }
    return $week . ' · ' . $monday->format('j') . '–' . $friday->format('j') . ' ' . jeab_sv_month($monday);
}

function jeab_month_label(DateTimeInterface $dt): string
{
    return jeab_sv_month($dt) . ' ' . $dt->format('Y');
}

function jeab_month_anchor_monday(int $year, int $month): DateTimeImmutable
{
    foreach (jeab_mondays_in_month($year, $month) as $monday) {
        if ((int) $monday->format('n') === $month && (int) $monday->format('Y') === $year) {
            return $monday;
        }
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    $first = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, max(1, min(12, $month))), $tz))->setTime(0, 0);
    return jeab_monday_of($first);
}

function jeab_today_start(): string
{
    $tz = new DateTimeZone(date_default_timezone_get());
    return (new DateTimeImmutable('today', $tz))->format('Y-m-d') . ' 00:00:00';
}

function jeab_tomorrow_start(): string
{
    $tz = new DateTimeZone('Europe/Stockholm');
    return (new DateTimeImmutable('tomorrow', $tz))->format('Y-m-d') . ' 00:00:00';
}

function jeab_format_due(?string $dueAt): string
{
    if (!$dueAt) {
        return '';
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    $dt = new DateTimeImmutable($dueAt, $tz);
    $today = new DateTimeImmutable('today', $tz);
    $day = $dt->setTime(0, 0);
    $diff = (int) $today->diff($day)->format('%r%a');

    if ($diff === 0) {
        return 'idag';
    }
    if ($diff === 1) {
        return 'imorgon';
    }
    if ($diff === -1) {
        return 'igår';
    }
    return jeab_sv_weekday($dt, true) . ' ' . $dt->format('j') . ' ' . jeab_sv_month_short($dt);
}

function jeab_format_due_contact(?string $dueAt): string
{
    if (!$dueAt) {
        return '';
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    $dt = new DateTimeImmutable($dueAt, $tz);
    return $dt->format('ymd');
}

function jeab_format_created_day(?string $createdAt): string
{
    if (!$createdAt) {
        return '';
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    $dt = new DateTimeImmutable($createdAt, $tz);
    return $dt->format('j') . '/' . $dt->format('n');
}

function jeab_due_has_clock(?string $dueAt): bool
{
    if (!$dueAt) {
        return false;
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    $dt = new DateTimeImmutable($dueAt, $tz);
    return $dt->format('H:i') !== '00:00';
}

function jeab_due_input_type(?string $dueAt): string
{
    return 'date';
}

function jeab_due_input_value(?string $dueAt): string
{
    if (!$dueAt) {
        return '';
    }
    $tz = new DateTimeZone(date_default_timezone_get());
    $dt = new DateTimeImmutable($dueAt, $tz);
    return $dt->format('Y-m-d');
}

function jeab_parse_assignees(mixed $raw, ?string $company = null): array
{
    $names = [];
    if (is_array($raw)) {
        foreach ($raw as $item) {
            if (is_array($item)) {
                $names = array_merge($names, jeab_parse_assignees($item, $company));
                continue;
            }
            $text = trim((string) $item);
            if ($text !== '') {
                $names[] = $text;
            }
        }
    } else {
        $text = trim((string) $raw);
        if ($text !== '') {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                return jeab_parse_assignees($decoded, $company);
            }
            foreach (preg_split('/\s*,\s*/', $text) ?: [] as $item) {
                if ($item !== '') {
                    $names[] = $item;
                }
            }
        }
    }

    $company = jeab_normalize_company($company);
    $picked = [];
    foreach ($names as $given) {
        $canonical = jeab_resolve_person_name($given, $company);
        if ($canonical !== null && !in_array($canonical, $picked, true)) {
            $picked[] = $canonical;
        }
    }
    return $picked;
}

function jeab_assignees_from_input(array $input): array
{
    if (array_key_exists('assignees', $input)) {
        return jeab_parse_assignees($input['assignees']);
    }
    if (array_key_exists('assignee', $input)) {
        return jeab_parse_assignees($input['assignee']);
    }
    return [];
}

function jeab_assignees_from_row(array $row): array
{
    $company = jeab_normalize_company($row['company'] ?? null);
    if ($company === null && isset($row['starts_at'])) {
        $company = jeab_booking_company($row);
    }
    if ($company === null) {
        $company = 'jarfalla';
    }
    if (array_key_exists('assignees', $row) && $row['assignees'] !== null && $row['assignees'] !== '') {
        if (is_array($row['assignees'])) {
            return jeab_parse_assignees($row['assignees'], $company);
        }
        $parsed = jeab_parse_assignees($row['assignees'], $company);
        if ($parsed) {
            return $parsed;
        }
    }
    return jeab_parse_assignees($row['assignee'] ?? '', $company);
}

function jeab_assignees_join(array $names): string
{
    return implode(', ', jeab_parse_assignees($names));
}

function jeab_assignees_json(array $names): ?string
{
    $names = jeab_parse_assignees($names);
    if (!$names) {
        return null;
    }
    return json_encode($names, JSON_UNESCAPED_UNICODE);
}

function jeab_require_installers(mixed $raw): array
{
    $names = jeab_parse_assignees($raw);
    if (!$names) {
        throw new InvalidArgumentException('Vem åker?');
    }
    return $names;
}

function jeab_assignees_cookie(): array
{
    return jeab_parse_assignees($_COOKIE['jeab_me'] ?? '');
}

function jeab_remember_assignees(array $names): void
{
    $names = jeab_parse_assignees($names);
    if (!$names || PHP_SAPI === 'cli') {
        return;
    }
    $value = jeab_assignees_join($names);
    setcookie('jeab_me', $value, [
        'expires' => time() + 60 * 60 * 24 * 400,
        'path' => jeab_base_path() ?: '/',
        'secure' => jeab_is_https(),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['jeab_me'] = $value;
}

function jeab_installer_chips(array $selected = [], string $defaultCsv = ''): string
{
    $company = jeab_selected_company();
    $selected = jeab_parse_assignees($selected, $company);
    ob_start();
    ?>
    <div class="chips" data-js="assignee-chips" data-default="<?= e($defaultCsv) ?>">
        <?php foreach (jeab_company_names($company) as $name): ?>
            <?php $job = jeab_person_job($name, $company); ?>
            <label class="chip-opt">
                <input type="checkbox" name="assignees[]" value="<?= e($name) ?>"<?= $job ? ' data-job="' . e($job) . '"' : '' ?><?= in_array($name, $selected, true) ? ' checked' : '' ?>>
                <span><?= e($name) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

/** Keep-warm. Token från JEAB_WARM_TOKEN eller config warm_token. Aldrig i kod. */
function jeab_warm_token_expected(): string
{
    $env = getenv('JEAB_WARM_TOKEN');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    if (function_exists('jeab_config')) {
        $config = jeab_config();
        $from = $config['warm_token'] ?? '';
        if (is_string($from) && trim($from) !== '') {
            return trim($from);
        }
    }

    return '';
}

function jeab_warm_respond(): never
{
    $okToken = function_exists('jeab_cron_token_matches') && jeab_cron_token_matches(jeab_warm_token_expected());
    if (!$okToken) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo '{"ok":false}';
        exit;
    }
    $GLOBALS['_JEAB_WARM'] = true;
    $ok = false;
    try {
        if (function_exists('jeab_pdo_store_connect')) {
            $pdo = jeab_pdo_store_connect();
            $stmt = $pdo->query('SELECT 1');
            $ok = $stmt instanceof PDOStatement && (string) $stmt->fetchColumn() === '1';
        }
    } catch (Throwable) {
        $ok = false;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$ok) {
        http_response_code(503);
        echo '{"ok":false}';
        exit;
    }
    echo '{"ok":true}';
    exit;
}

if (PHP_SAPI !== 'cli') {
    jeab_shield_web();
    if (function_exists('jeab_promote_kalkyl_request')) {
        jeab_promote_kalkyl_request();
    }
    if (function_exists('jeab_restricted_fence')) {
        jeab_restricted_fence(true);
    }
}
jeab_timing_begin();
