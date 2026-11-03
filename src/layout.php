<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
function jeab_nav_page(string $page): string
{
    if (in_array($page, ['reminder', 'purchase'], true)) {
        return 'reminders';
    }
    if (in_array($page, ['logg', 'bugg', 'zapier', 'notes', 'manuell-order', 'rot', 'kontakter', 'notiser', 'utskick', 'inloggdiag'], true)) {
        return 'links';
    }
    if (in_array($page, ['inkorg', 'inkorg-regler', 'inkorg-vyer', 'inkorg-kontakter'], true)) {
        return 'inkorg';
    }
    return $page;
}

/** Fliklänk med det valda bolaget. who följer inte med. */
function jeab_tab_url(string $slug): string
{
    $company = function_exists('jeab_selected_company') ? jeab_selected_company() : 'jarfalla';

    return jeab_url('index.php?' . http_build_query(['p' => $slug, 'co' => $company]));
}

function jeab_module_nav(string $page): string
{
    $on = jeab_nav_page($page);
    $restricted = function_exists('jeab_restricted_is_solkampanj') && jeab_restricted_is_solkampanj();
    if ($restricted) {
        $items = [
            'solkampanj' => ['Solkampanj', jeab_url('solkampanj.php')],
            'offert' => ['Kalkyl', jeab_url('kalkyl.php')],
        ];
        $on = $page === 'kalkyl' ? 'offert' : $page;
    } else {
        $items = [
            'inkorg' => ['Inkorg', jeab_tab_url('inkorg')],
            'reminders' => ['Påminnelser', jeab_tab_url('reminders')],
            'bookings' => ['Kalender', jeab_tab_url('bookings')],
            'ordrar' => ['Ordrar', jeab_tab_url('ordrar')],
            'offert' => ['Kalkyl', jeab_tab_url('offert')],
            'links' => ['Övrigt', jeab_tab_url('links')],
        ];
    }
    if (function_exists('jeab_demo_feature_off') && jeab_demo_feature_off('kalkyl')) {
        unset($items['offert']);
    }
    if (function_exists('jeab_app_module_on')) {
        foreach (['inkorg', 'reminders', 'bookings'] as $mod) {
            if (!jeab_app_module_on($mod)) {
                unset($items[$mod]);
            }
        }
    }
    // Märket räknas bara på Inkorgens sidor (läser alla ärenden).
    $unread = $on === 'inkorg' && function_exists('jeab_inkorg_unread_count') ? jeab_inkorg_unread_count() : 0;
    ob_start();
    echo '<nav class="tabs" aria-label="Moduler">';
    foreach ($items as $key => [$label, $href]) {
        $badge = $key === 'inkorg' && $unread > 0 ? ' <span class="tab-count" aria-label="' . $unread . ' olästa">' . $unread . '</span>' : '';
        $full = $restricted && $key === 'solkampanj' ? ' data-jeab-full="1"' : '';
        $on === $key
            ? printf('<a class="tab is-on" href="%s" aria-current="page"%s>%s%s</a>', e($href), $full, e($label), $badge)
            : printf('<a class="tab" href="%s"%s>%s%s</a>', e($href), $full, e($label), $badge);
    }
    echo '</nav>';
    return ob_get_clean();
}

/** Länk som byter bolag men stannar kvar på samma sida med sidans egna filter. */
function jeab_chrome_company_url(string $page, string $company): string
{
    if ($page === 'ordrar' && function_exists('jeab_ordrar_url')) {
        return jeab_ordrar_url(['co' => $company]);
    }
    if ($page === 'bookings') {
        // Kontorsbyte: samma vecka och vy, filtret börjar om på den inloggades förval.
        $q = ['p' => 'bookings', 'co' => $company];
        $w = (int) ($_GET['w'] ?? 0);
        if ($w !== 0) {
            $q['w'] = $w;
        }
        if (function_exists('jeab_booking_view') && jeab_booking_view() === 'month') {
            $q['view'] = 'month';
        } elseif (function_exists('jeab_booking_view') && jeab_booking_view() === 'day') {
            unset($q['w']);
            $q['view'] = 'day';
            $q['d'] = jeab_calendar_focus_day()->format('Y-m-d');
        }
        return jeab_url('index.php?' . http_build_query($q));
    }
    if (in_array($page, ['reminders', 'reminder', 'purchase'], true) && function_exists('jeab_reminders_url')) {
        // Ett aktivt personval (eller Alla) följer med där det finns; annars bolagets eget val.
        return jeab_reminders_url(['co' => $company, 'who' => jeab_reminder_who_for_company($company), 'open' => null]);
    }
    if ($page === 'manuell-order' || $page === 'rot') {
        return jeab_manuell_order_url(['co' => $company]);
    }
    $p = jeab_page_slug((string) ($_GET['p'] ?? $page));
    return jeab_url('index.php?' . http_build_query(['p' => $p, 'co' => $company]));
}

function jeab_chrome_company_html(string $page): string
{
    $company = jeab_selected_company();
    $items = ['malmo' => 'Malmö', 'jarfalla' => 'Järfälla'];
    $allOffices = $page === 'kontakter' && function_exists('jeab_kontakter_all_offices') && jeab_kontakter_all_offices();
    if ($page === 'kontakter') {
        $items = ['alla' => 'Alla kontor'] + $items;
    }
    $actor = function_exists('jeab_actor_companies') ? jeab_actor_companies() : null;
    if ($actor === []) {
        $items = [];
    } elseif (is_array($actor) && $actor !== []) {
        $filtered = [];
        foreach ($items as $key => $label) {
            if ($key === 'alla') {
                if (in_array('jarfalla', $actor, true) && in_array('malmo', $actor, true)) {
                    $filtered[$key] = $label;
                }
                continue;
            }
            if (in_array($key, $actor, true)) {
                $filtered[$key] = $label;
            }
        }
        if ($filtered !== []) {
            $items = $filtered;
        }
    }
    if (function_exists('jeab_app_companies_on')) {
        $coOn = jeab_app_companies_on();
        foreach (array_keys($items) as $key) {
            if ($key === 'alla' ? count($coOn) < 2 : !in_array($key, $coOn, true)) {
                unset($items[$key]);
            }
        }
        if ($items === []) {
            return '';
        }
    }
    $inkorg = jeab_nav_page($page) === 'inkorg' && function_exists('jeab_inkorg_box');
    if ($inkorg) {
        // Inkorgen: separat inkorg per bolag, förvalt efter inloggad person.
        $company = jeab_inkorg_box();
    }
    ob_start();
    echo '<div class="chrome-company' . (count($items) === 3 ? ' has-3' : '') . '" role="group" aria-label="Val">';
    foreach ($items as $key => $label) {
        if ($inkorg && function_exists('jeab_inkorg_company_live') && !jeab_inkorg_company_live($key)) {
            $href = jeab_url('index.php?' . http_build_query(['p' => 'inkorg', 'box' => $key, 'co' => $key]));
            $on = $company === $key;
            printf(
                '<a class="chip%s" href="%s" title="Sol hanteras i Help Scout."%s>%s</a>',
                $on ? ' is-on' : '',
                e($href),
                $on ? ' aria-current="true"' : '',
                e($label)
            );
            continue;
        }
        $href = $inkorg
            ? jeab_url('index.php?' . http_build_query(array_filter(['p' => $page, 'box' => $key, 'co' => $key])))
            : jeab_chrome_company_url($page, $key);
        $on = $key === 'alla' ? $allOffices : (!$allOffices && $company === $key);
        printf(
            '<a class="chip%s" href="%s"%s>%s</a>',
            $on ? ' is-on' : '',
            e($href),
            $on ? ' aria-current="true"' : '',
            e($label)
        );
    }
    echo '</div>';
    return ob_get_clean();
}

function jeab_theme_toggle_html(): string
{
    $sun = '<svg class="chrome-theme-ico is-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/></svg>';
    $moon = '<svg class="chrome-theme-ico is-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A8.5 8.5 0 1 1 11.2 3a6.5 6.5 0 0 0 9.8 9.8Z"/></svg>';
    return '<button type="button" class="chrome-theme" data-js="theme-toggle" aria-label="Byt mellan mörkt och ljust läge" title="Byt mellan mörkt och ljust läge">'
        . $sun . $moon . '</button>';
}

/** Enda stället där toppmenyn byggs — alla undersidor får samma chrome. */
function jeab_restricted_chrome_html(string $page): string
{
    ob_start();
    ?>
    <header class="chrome">
        <div class="top">
            <a class="brand" href="<?= e(function_exists('jeab_demo_mode') && jeab_demo_mode() ? jeab_url('index.php') : jeab_url('solkampanj.php')) ?>" data-jeab-full="1">
                <span class="brand-logo" aria-hidden="true"><?= function_exists('jeab_demo_mode') && jeab_demo_mode() ? jeab_demo_brand_logo_svg() : 'JE' ?></span>
                <span class="brand-name"><?= function_exists('jeab_demo_mode') && jeab_demo_mode() ? 'Flowstack' : 'flowstack.example.test' ?></span>
            </a>
            <?= jeab_theme_toggle_html() ?>
            <form method="post" action="<?= e(jeab_url('index.php')) ?>">
                <?= jeab_csrf_field() ?>
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="chip">Logga ut</button>
            </form>
        </div>
        <?= jeab_module_nav($page) ?>
    </header>
    <?php
    return (string) ob_get_clean();
}

function jeab_chrome_html(string $page): string
{
    $banner = function_exists('jeab_demo_banner_html') ? jeab_demo_banner_html() : '';
    if (function_exists('jeab_restricted_is_solkampanj') && jeab_restricted_is_solkampanj()) {
        return $banner . jeab_restricted_chrome_html($page);
    }
    ob_start();
    echo $banner;
    ?>
    <header class="chrome">
        <div class="top">
            <a class="brand" href="<?= e(jeab_tab_url(function_exists('jeab_app_home_slug') ? jeab_app_home_slug() : 'reminders')) ?>">
                <span class="brand-logo" aria-hidden="true"><?= function_exists('jeab_demo_mode') && jeab_demo_mode() ? jeab_demo_brand_logo_svg() : 'JE' ?></span>
                <span class="brand-name"><?= function_exists('jeab_demo_mode') && jeab_demo_mode() ? 'Flowstack' : 'flowstack.example.test' ?></span>
            </a>
            <?= jeab_chrome_company_html($page) ?>
            <?= jeab_theme_toggle_html() ?>
        </div>
        <?= jeab_module_nav($page) ?>
    </header>
    <?php
    return (string) ob_get_clean();
}

function jeab_theme_class(): string
{
    $cookie = (string) ($_COOKIE['jeab_theme'] ?? '');
    return $cookie === 'light' ? 'theme-light' : 'theme-dark';
}

/** Tidigare Google-adressen. Den används inte: den blockerade load-eventet. */
function jeab_font_stylesheet_href(): string
{
    return '';
}

/** @font-face mot egna woff2. Tom sträng om en fil saknas — då används Google-länken. */
function jeab_font_face_css(): string
{
    if (!function_exists('jeab_fonts_hosted_locally') || !jeab_fonts_hosted_locally()) {
        return '';
    }
    $range = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';
    $css = '';
    foreach (jeab_self_hosted_font_rels() as $rel) {
        $base = basename($rel, '.woff2');
        $family = '';
        $weight = '';
        if (str_starts_with($base, 'inter-latin-')) {
            $family = 'Inter';
            $weight = substr($base, strlen('inter-latin-'));
        } elseif (str_starts_with($base, 'ibm-plex-mono-latin-')) {
            $family = 'IBM Plex Mono';
            $weight = substr($base, strlen('ibm-plex-mono-latin-'));
        }
        if ($family === '' || preg_match('/^[1-9]00$/', $weight) !== 1) {
            continue;
        }
        $url = function_exists('jeab_asset_url') ? jeab_asset_url($rel) : '/' . $rel;
        $css .= '@font-face{font-family:"' . $family . '";font-style:normal;font-weight:' . $weight
            . ';font-display:swap;src:url("' . $url . '") format("woff2");unicode-range:' . $range . ';}';
    }

    return $css;
}

function jeab_font_link_tags(): string
{
    $preload = '';
    if (function_exists('jeab_fonts_hosted_locally') && jeab_fonts_hosted_locally() && function_exists('jeab_asset_url')) {
        $href = e(jeab_asset_url('assets/fonts/inter-latin-400.woff2'));
        $preload = '<link rel="preload" as="font" type="font/woff2" href="' . $href . '" crossorigin>' . "\n    ";
    }
    $faces = jeab_font_face_css();
    if ($faces === '') {
        return '';
    }

    return $preload . '<style>' . $faces . '</style>';
}

/**
 * Sidor där app.js binder. Övriga hela dokument hoppar över scriptet.
 * Skalet läser samma lista och hämtar filen först när man byter dit.
 *
 * @return list<string>
 */
function jeab_app_js_pages(): array
{
    return ['reminders', 'reminder', 'bookings', 'offert', 'kalkyl', 'ordrar', 'rot', 'manuell-order'];
}

function jeab_page_needs_app_js(string $page): bool
{
    return in_array($page, jeab_app_js_pages(), true);
}

/** Temaknappen sitter i chromen på varje sida, även där app.js inte laddas. */
function jeab_theme_boot_html(): string
{
    return <<<'HTML'
<script>
(function () {
  var THEME_COLOR = { dark: '#0b0c0e', light: '#f5f6f8' };
  function readTheme() {
    var cookie = document.cookie.match(/(?:^|;\s*)jeab_theme=(light|dark)/);
    if (cookie) { return cookie[1]; }
    return document.documentElement.classList.contains('theme-light') ? 'light' : 'dark';
  }
  function applyTheme(theme, persist) {
    var light = theme === 'light';
    var root = document.documentElement;
    root.classList.toggle('theme-light', light);
    root.classList.toggle('theme-dark', !light);
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) { meta.setAttribute('content', light ? THEME_COLOR.light : THEME_COLOR.dark); }
    document.querySelectorAll('[data-js="theme-toggle"]').forEach(function (btn) {
      btn.setAttribute('aria-pressed', light ? 'true' : 'false');
    });
    if (persist) {
      document.cookie = 'jeab_theme=' + (light ? 'light' : 'dark') + ';path=/;max-age=31536000;samesite=lax';
    }
  }
  window.jeabApplyTheme = function () { applyTheme(readTheme(), false); };
  applyTheme(readTheme(), false);
  document.addEventListener('click', function (event) {
    var btn = event.target && event.target.closest ? event.target.closest('[data-js="theme-toggle"]') : null;
    if (!btn) { return; }
    applyTheme(readTheme() === 'light' ? 'dark' : 'light', true);
  });
})();
</script>
HTML;
}

/**
 * Minsta yta så sidan inte ritas som orörd HTML om webbläsaren hinner måla
 * innan tokens.css och app.css. Selektorerna är svagare än sidans egna ark.
 */
function jeab_critical_css(): string
{
    return 'html{color-scheme:dark}'
        . 'html.theme-light{color-scheme:light}'
        . 'html,body{margin:0;background:#0b0c0e;color:#eef0f3;font-family:"Segoe UI",system-ui,-apple-system,sans-serif;font-size:16px}'
        . 'html.theme-light,html.theme-light body{background:#f4f6f8;color:#101319}'
        . 'a{color:inherit;text-decoration:none}'
        . '.tab,.chip{display:inline-flex;align-items:center;min-height:32px;padding:0 12px;border-radius:999px}'
        . '.ordrar-list,.ordrar-list>li{list-style:none;margin:0;padding:0}'
        . '.ordrar-row{display:flex;flex-direction:column;align-items:flex-start;gap:6px;padding:14px 16px;color:inherit}'
        . '.ordrar-name,.ordrar-product{display:block}'
        . '.ordrar-filters{display:flex;flex-wrap:wrap;gap:8px}'
        . '.ordrar-filter{display:inline-flex;align-items:center;gap:6px;min-height:32px;padding:0 12px;border-radius:999px;border:1px solid currentColor;background:transparent;color:inherit;font:inherit}'
        . '.jeab-skel{display:grid;gap:10px}'
        . '.jeab-skel span{display:block;height:64px;border-radius:14px;background:rgba(127,127,127,.14)}';
}

function jeab_nav_spinner_css(): string
{
    return '.jeab-nav-spinner{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;background:color-mix(in oklab,#0b0c0e 55%,transparent);backdrop-filter:blur(2px)}'
        . '.jeab-nav-spinner[hidden]{display:none!important}'
        . '.jeab-nav-spinner-card{display:inline-flex;align-items:center;gap:12px;padding:14px 18px;border-radius:14px;border:1px solid rgba(255,255,255,.14);background:#1c1c1e;color:#f5f5f7;font:14px/1.3 system-ui,sans-serif;box-shadow:0 10px 40px rgba(0,0,0,.35)}'
        . '.jeab-nav-spinner-ring{width:22px;height:22px;border-radius:50%;border:2px solid color-mix(in oklab,currentColor 25%,transparent);border-top-color:currentColor;animation:jeab-nav-spin .7s linear infinite;flex:0 0 auto}'
        . '.jeab-nav-spinner-cancel{box-sizing:border-box;min-width:44px;min-height:44px;padding:0 12px;border-radius:10px;border:1px solid rgba(255,255,255,.35);background:transparent;color:inherit;font:inherit;cursor:pointer}'
        . '@keyframes jeab-nav-spin{to{transform:rotate(360deg)}}'
        . 'html.is-nav-loading,html.is-nav-loading body{cursor:progress}';
}

function jeab_nav_spinner_html(string $label): string
{
    $text = trim($label) !== '' ? $label : 'Laddar…';
    return '<div id="jeab-nav-spinner" class="jeab-nav-spinner" role="status">'
        . '<div class="jeab-nav-spinner-card">'
        . '<span class="jeab-nav-spinner-ring" aria-hidden="true"></span>'
        . '<span class="jeab-nav-spinner-label">' . e($text) . '</span>'
        . '<button type="button" class="jeab-nav-spinner-cancel tap44">Avbryt</button>'
        . '</div></div>';
}

function jeab_layout_body_classes(string $page): array
{
    $dark = in_array($page, ['reminders', 'reminder', 'purchase'], true);
    $classes = [];
    if ($dark) {
        $classes[] = 'theme-reminders';
    }
    if ($page === 'rot' || $page === 'manuell-order') {
        $classes[] = 'page-rot';
    }
    if ($page === 'bookings') {
        $classes[] = 'page-bookings';
    }
    if ($page === 'offert') {
        $classes[] = 'page-offert';
    }
    if ($page === 'links') {
        $classes[] = 'page-links';
    }
    if ($page === 'notiser' || $page === 'utskick') {
        $classes[] = 'page-notiser';
    }
    if (in_array($page, ['reminders', 'reminder'], true)) {
        $classes[] = 'page-reminders';
    }
    if ($page === 'purchase') {
        $classes[] = 'page-purchase';
    }
    if ($page === 'ordrar') {
        $classes[] = 'page-ordrar';
    }
    if ($page === 'kontakter') {
        $classes[] = 'page-kontakter';
        $classes[] = 'page-reminders';
        if (!empty($GLOBALS['_JEAB_KONTAKT_DETAIL'])) {
            $classes[] = 'is-detail';
        }
    }
    if ($page === 'notes') {
        $classes[] = 'page-notes';
    }
    if (in_array($page, ['inkorg', 'inkorg-regler'], true)) {
        $classes[] = 'page-inkorg';
    }
    if ($page === 'inkorg-vyer') {
        $classes[] = 'page-inkorg-vyer';
    }
    return $classes;
}

/** GET med X-Jeab-Partial: 1 byter bara innehållet. POST och inloggningsgrinden rörs inte. */
function jeab_shell_wants_partial(): bool
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return false;
    }
    return (string) ($_SERVER['HTTP_X_JEAB_PARTIAL'] ?? '') === '1';
}

/** @return list<string> */
function jeab_page_style_hrefs(string $page): array
{
    $href = static function (string $rel): string {
        return jeab_asset_url($rel);
    };
    $out = [
        $href('assets/css/tokens-apple-dark.css'),
        $href('assets/css/app.css'),
        $href('assets/css/flash.css'),
    ];
    if ($page === 'purchase' || $page === 'reminders' || $page === 'reminder') {
        $out[] = $href('assets/css/kop.css');
    }
    if (in_array($page, ['reminders', 'reminder', 'kontakter'], true)) {
        $out[] = $href('assets/css/paminnelser.css');
    }
    if ($page === 'bookings') {
        $out[] = $href('assets/css/bokning-sheet.css');
        $out[] = $href('assets/css/kalender.css');
    }
    if ($page === 'ordrar') {
        $out[] = $href('assets/css/ordrar.css');
        if (!empty($GLOBALS['_JEAB_ORDRAR_V3'])) {
            $out[] = $href('assets/css/ordrar-v3.css');
        }
    }
    if ($page === 'notiser' || $page === 'utskick') {
        $out[] = $href('assets/css/notiser.css');
    }
    if ($page === 'notes') {
        $out[] = $href('assets/css/notes.css');
    }
    if (in_array($page, ['inkorg', 'inkorg-regler', 'inkorg-vyer'], true)) {
        $out[] = $href('assets/css/inkorg.css');
    }
    return $out;
}

function jeab_partial_etag(string $json): string
{
    return '"j' . substr(hash('sha256', $json), 0, 20) . '"';
}

function jeab_partial_not_modified(string $etag): bool
{
    $ifNone = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));

    return $ifNone !== '' && hash_equals($etag, $ifNone);
}

/** @param list<string> $extra */
function jeab_partial_json_send(string $json, array $extra = []): void
{
    if (function_exists('jeab_timing_header')) {
        jeab_timing_header();
    }
    $etag = jeab_partial_etag($json);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: same-origin');
    header('X-Jeab-Partial: 1');
    header('ETag: ' . $etag);
    foreach ($extra as $line) {
        header($line);
    }
    if (jeab_partial_not_modified($etag)) {
        http_response_code(304);

        return;
    }
    echo $json;
}

function jeab_shell_emit(string $title, string $page, string $body): never
{
    $payload = [
        'ok' => true,
        'title' => $title,
        'page' => $page,
        'bodyClass' => implode(' ', jeab_layout_body_classes($page)),
        'html' => $body,
        'chrome' => jeab_chrome_html($page),
        'styles' => jeab_page_style_hrefs($page),
        'csrf' => jeab_csrf_token(),
    ];
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    if (!is_string($json)) {
        $json = '{"ok":false}';
    }
    if (function_exists('jeab_view_cache_store')) {
        jeab_view_cache_store($json);
    }
    jeab_partial_json_send($json);
    exit;
}

function jeab_layout_open(string $title, string $page, ?string $waitLabel = null): void
{
    if (function_exists('jeab_demo_title')) {
        $title = jeab_demo_title($title);
    }
    if (function_exists('jeab_timing_header')) {
        jeab_timing_header();
    }
    if (!empty($GLOBALS['_JEAB_LAYOUT_OPEN'])) {
        return;
    }
    $GLOBALS['_JEAB_LAYOUT_OPEN'] = true;
    $GLOBALS['_JEAB_LAYOUT_PAGE'] = $page;
    $GLOBALS['_JEAB_LAYOUT_WAIT'] = $waitLabel;
    $csrf = jeab_csrf_token();
    $dark = in_array($page, ['reminders', 'reminder', 'purchase'], true);
    $classes = jeab_layout_body_classes($page);
    $bodyClass = $classes ? ' class="' . e(implode(' ', $classes)) . '"' : '';
    $themeClass = jeab_theme_class();
    $htmlClass = $themeClass . ($waitLabel !== null ? ' is-nav-loading' : '');
    $coldAttr = !empty($GLOBALS['_JEAB_SESSION_FRESH']) ? ' data-jeab-cold="1"' : '';
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: private, no-store');
    if ($waitLabel !== null) {
        header('Cache-Control: no-store');
    }
    ?>
<!doctype html>
<html lang="sv" class="<?= e($htmlClass) ?>"<?= $coldAttr ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <link rel="icon" href="/favicon.ico?v=20261104" sizes="48x48">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#0A1630">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?= function_exists('jeab_demo_mode') && jeab_demo_mode() ? 'Flowstack' : 'JEAB' ?>">
    <meta name="application-name" content="<?= function_exists('jeab_demo_mode') && jeab_demo_mode() ? 'Flowstack' : 'JEAB' ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="format-detection" content="telephone=no">
    <meta name="csrf" content="<?= e($csrf) ?>">
    <?php if (function_exists('jeab_demo_mode') && jeab_demo_mode()): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <title><?= e($title) ?></title>
    <style><?= jeab_critical_css() ?></style>
    <?php
    $jeabPreloadJs = ['assets/js/poll-lock.js', 'assets/js/shell.js'];
    if (jeab_page_needs_app_js($page)) {
        array_splice($jeabPreloadJs, 1, 0, ['assets/js/optimistic.js', 'assets/js/app.js']);
    }
    foreach (jeab_page_style_hrefs($page) as $styleHref): ?>
    <link rel="preload" href="<?= e($styleHref) ?>" as="style">
    <?php endforeach; ?>
    <?php foreach ($jeabPreloadJs as $jeabJsRel): ?>
    <link rel="preload" href="<?= e(jeab_asset_url($jeabJsRel)) ?>" as="script">
    <?php endforeach; ?>
    <?php foreach (jeab_page_style_hrefs($page) as $styleHref): ?>
    <link rel="stylesheet" href="<?= e($styleHref) ?>">
    <?php endforeach; ?>
    <?= jeab_font_link_tags() ?>

    <script type="application/json" id="jeab-asset-v"><?= json_encode(jeab_asset_versions(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script type="application/json" id="jeab-app-js-pages"><?= json_encode(jeab_app_js_pages(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script>
    (function () {
      // Tyst CSRF-förnyelse: POST får alltid aktuell token; vid 419 hämtas ny token och försöker en gång till.
      var of = window.fetch ? window.fetch.bind(window) : null;
      if (!of || window.__jeabCsrf) { return; }
      window.__jeabCsrf = true;
      var base = <?= json_encode(jeab_url('index.php'), JSON_UNESCAPED_SLASHES) ?>;
      function tok() { var m = document.querySelector('meta[name="csrf"]'); return m ? m.getAttribute('content') || '' : ''; }
      function setTok(t) {
        if (!t) { return; }
        var m = document.querySelector('meta[name="csrf"]'); if (m) { m.setAttribute('content', t); }
        document.querySelectorAll('input[name="_csrf"]').forEach(function (i) { i.value = t; });
      }
      function refresh() {
        return of(base + '?p=csrf', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (j) { if (j && j.csrf) { setTok(j.csrf); return j.csrf; } return ''; })
          .catch(function () { return ''; });
      }
      window.jeabCsrfRefresh = refresh;
      function same(u) { try { return new URL(u, location.href).origin === location.origin; } catch (e) { return false; } }
      function prep(init, t) {
        var o = Object.assign({}, init || {});
        var h = new Headers(o.headers || {});
        if (t) { h.set('X-CSRF-Token', t); }
        o.headers = h;
        if (t && typeof FormData !== 'undefined' && o.body instanceof FormData && o.body.has('_csrf')) { o.body.set('_csrf', t); }
        if (t && typeof URLSearchParams !== 'undefined' && o.body instanceof URLSearchParams && o.body.has('_csrf')) { o.body.set('_csrf', t); }
        return o;
      }
      window.fetch = function (input, init) {
        var url = typeof input === 'string' ? input : (input && input.url) || '';
        var method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
        if (method !== 'POST' || !same(url) || typeof input !== 'string') { return of(input, init); }
        return of(input, prep(init, tok())).then(function (res) {
          if (res.status !== 419) { return res; }
          return refresh().then(function (t) { return t ? of(input, prep(init, t)) : res; });
        });
      };
      document.addEventListener('submit', function (e) {
        var f = e.target, t = tok();
        if (f && f.querySelectorAll && t) { f.querySelectorAll('input[name="_csrf"]').forEach(function (i) { i.value = t; }); }
      }, true);
      var csrfWait = 15 * 60 * 1000;
      function csrfLeader() {
        if (window.jeabPollClaim && !window.jeabPollClaim(document.hidden)) { return false; }
        return !document.hidden;
      }
      setInterval(function () { if (csrfLeader()) { window.__jeabCsrfAt = Date.now(); refresh(); } }, csrfWait);
      document.addEventListener('visibilitychange', function () {
        if (document.hidden) { return; }
        if ((Date.now() - (window.__jeabCsrfAt || 0)) <= csrfWait) { return; }
        if (!csrfLeader()) { return; }
        window.__jeabCsrfAt = Date.now();
        refresh();
      });
      window.__jeabCsrfAt = Date.now();
    })();
    </script>
    <?php if ($waitLabel !== null): ?>
    <style><?= jeab_nav_spinner_css() ?></style>
    <?php endif; ?>
    <?php if ($page === 'ordrar'): ?>
    <style>.ov3 .v3-seg-opt{min-height:44px}.ov3 .v3-seg-opt span{box-sizing:border-box;min-height:44px;display:inline-flex;align-items:center}</style>
    <?php endif; ?>
    <?php if ($dark): ?>
    <script>
    history.scrollRestoration = 'manual';
    if (location.hash.indexOf('#r-') === 0) {
        history.replaceState(null, '', location.pathname + location.search);
    }
    </script>
    <?php endif; ?>
</head>
<body<?= $bodyClass ?>>
    <?php
    if ($waitLabel !== null) {
        echo jeab_nav_spinner_html($waitLabel);
        echo str_repeat(' ', 8192);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
}

function jeab_layout_close(string $body): void
{
    $page = (string) ($GLOBALS['_JEAB_LAYOUT_PAGE'] ?? 'reminders');
    $waitLabel = $GLOBALS['_JEAB_LAYOUT_WAIT'] ?? null;
    echo jeab_chrome_html($page);
    echo '<main class="main">' . $body . '</main>';
    if (in_array($page, ['inkorg', 'inkorg-regler'], true)) {
        // Samma växande textyta som app.js (data-js="grow-note"), så Inkorgen och Regler
        // inte är beroende av att det stora scriptet hinner dit.
        echo '<script>(function(){function grow(el){if(!el||getComputedStyle(el).display==="none"){return;}el.style.overflowY="hidden";el.style.height="auto";var edge=el.offsetHeight-el.clientHeight;el.style.height=(el.scrollHeight+Math.max(0,edge))+"px";}function all(){document.querySelectorAll("[data-js=\\"grow-note\\"]:not([data-clamp])").forEach(grow);}all();document.querySelectorAll("[data-js=\\"grow-note\\"]:not([data-clamp])").forEach(function(el){if(el._jeabInkorgGrow){return;}el._jeabInkorgGrow=true;el.addEventListener("input",function(){grow(el);});});window.addEventListener("resize",all);})();</script>';
    }
    echo jeab_theme_boot_html();
    echo '<script src="' . e(jeab_asset_url('assets/js/poll-lock.js')) . '" defer></script>';
    if (jeab_page_needs_app_js($page)) {
        echo '<script src="' . e(jeab_asset_url('assets/js/optimistic.js')) . '" defer></script>';
        echo '<script src="' . e(jeab_asset_url('assets/js/app.js')) . '" defer></script>';
    }
    echo '<script src="' . e(jeab_asset_url('assets/js/shell.js')) . '" defer></script>';
    if ($page === 'inkorg') {
        echo '<script src="' . e(jeab_asset_url('assets/js/inkorg-swipe.js')) . '" defer></script>';
    }
    if ($waitLabel !== null) {
        echo '<script>document.documentElement.classList.remove("is-nav-loading");var s=document.getElementById("jeab-nav-spinner");if(s)s.hidden=true;</script>';
    }
    echo '</body></html>';
    $GLOBALS['_JEAB_LAYOUT_OPEN'] = false;
}

function jeab_layout(string $title, string $page, string $body): void
{
    if (function_exists('jeab_demo_title')) {
        $title = jeab_demo_title($title);
    }
    if (jeab_shell_wants_partial()) {
        jeab_shell_emit($title, $page, $body);
    }
    jeab_layout_open($title, $page, null);
    jeab_layout_close($body);
}

function jeab_flash_text(?string $flash): ?string
{
    if ($flash === null || $flash === '') {
        return null;
    }
    $allowed = ['Sparad.', 'Sparad. Ärendet stängt.', 'Befintlig påminnelse. Ärendet stängt.', 'Klar.', 'Öppen igen.', 'Tid sparad.', 'Knuffad.', 'Flaggad.', 'Avflaggad.', 'Tagg sparad.', 'Tagg borttagen.', 'Bokad.', 'Bokad. Bekräftelsemejl skickat.', 'Bokad. Bekräftelsemejlet gick inte iväg.', 'Uppdaterad.', 'Uppdaterad. Bekräftelsemejl skickat.', 'Uppdaterad. Bekräftelsemejlet gick inte iväg.', 'Avbokad.', 'Påminnelse skapad.', 'Zapier sparad.', 'Ny token sparad.', 'Outlook kopplad.', 'Outlook bortkopplad.', 'Outlook-fel.', 'Mall sparad.', 'Fortnox-order skapad.', 'Faktura skapad.', 'Beställd.', 'Raderad.', 'Skickad.', 'Offerten skickad.', 'Offerten är köad.', 'Offerten ligger redan i kön.', 'Föranmälan skickad.', 'Fullmakt startad.', 'Utkast skapat.', 'webhook ej konfigurerad', 'Automatik sparad.', 'Instruktionen sparad.', 'Ombokning sparad.', 'Ombokning prövad.', 'Mejlboxar sparade.', 'Malmö-synken sparad.', 'Regeln sparad.', 'Regeln är på.', 'Regeln är av.', 'Regeln borttagen.', 'Ordningen sparad.', 'Listan sparad.', 'Listan borttagen.', 'Exemplet borttaget.'];
    if (in_array($flash, $allowed, true)) {
        return $flash;
    }
    if (preg_match('/^(?:Bokad|Uppdaterad)\.(?: Bekräftelsemejl skickat\.| Bekräftelsemejlet gick inte iväg\.)?(?: Outlook-synken misslyckades\.)?(?: Samma person är redan bokad då\.)?$/', $flash) === 1) {
        return $flash;
    }
    if ((str_starts_with($flash, 'Offerten skickad.') || str_starts_with($flash, 'Förhandsvisning klar. Inget skickat.'))
        && mb_strlen($flash) <= 400
        && preg_match('/[<>]|https?:|@/u', $flash) !== 1) {
        return $flash;
    }
    return null;
}

function jeab_quote_sent_reminder_id(): int
{
    $pam = (int) ($_GET['pam'] ?? 0);
    if ($pam > 0) {
        return $pam;
    }
    $open = (int) ($_GET['open'] ?? 0);
    if ($open > 0) {
        return $open;
    }

    return (int) ($GLOBALS['_JEAB_QUOTE_REMINDER'] ?? 0);
}

function jeab_quote_sent_with_reminder(string $url, string $action, string $flash, int $reminderId): string
{
    if ($action !== 'inkorg.send_quote' || !str_starts_with($flash, 'Offerten skickad.') || $reminderId <= 0) {
        return $url;
    }
    if (preg_match('/(?:\?|&)pam=\d+/', $url) === 1) {
        return $url;
    }

    return $url . (str_contains($url, '?') ? '&' : '?') . 'pam=' . $reminderId;
}

function jeab_flash_html(?string $flash): string
{
    if (!$flash) {
        return '';
    }
    $inner = e($flash);
    if (str_starts_with($flash, 'Offerten skickad.')) {
        if (!function_exists('jeab_reminders_url') && is_file(__DIR__ . '/reminders.php')) {
            require_once __DIR__ . '/reminders.php';
        }
        $id = jeab_quote_sent_reminder_id();
        if ($id > 0 && function_exists('jeab_reminders_url')) {
            $href = jeab_reminders_url(['open' => $id, 'q' => '']);
            $inner .= ' <a class="flash-link" href="' . e($href) . '">Öppna påminnelsen</a>';
        }
    } elseif (str_ends_with($flash, 'Ärendet stängt.')) {
        $tid = trim((string) ($_GET['stangd'] ?? ''));
        if ($tid !== '' && function_exists('jeab_csrf_field') && function_exists('jeab_url')) {
            $inner .= ' <form class="flash-reopen" method="post" action="' . e(jeab_url('index.php?p=inkorg')) . '">'
                . jeab_csrf_field()
                . '<input type="hidden" name="action" value="inkorg.thread">'
                . '<input type="hidden" name="thread" value="' . e($tid) . '">'
                . '<input type="hidden" name="status" value="open">'
                . '<button type="submit" class="flash-link">Öppna igen</button>'
                . '</form>';
        }
    }

    return '<p class="flash" role="status">' . $inner . '</p>';
}

function jeab_manuell_order_url(array $query = []): string
{
    $params = ['p' => 'manuell-order'];
    foreach ($query as $key => $value) {
        if ($key === 'p' || !is_scalar($value)) {
            continue;
        }
        $text = trim((string) $value);
        if ($text === '') {
            continue;
        }
        $params[(string) $key] = $text;
    }
    return jeab_url('index.php?' . http_build_query($params));
}

function jeab_fortnox_oauth_setup_url(): string
{
    return 'https://flowstack.example.test/rot/oauth_setup.php';
}

function jeab_fortnox_repair_link_html(): string
{
    return '<p class="rot-laga-foot"><a href="' . e(jeab_fortnox_oauth_setup_url()) . '">laga här om trasig</a></p>';
}

function jeab_error_is_fortnox_oauth(string $error): bool
{
    return (bool) preg_match('/fortnox\s*oauth|oauth\s*misslyckades|fortnox-token saknas|ingen kontakt med fortnox oauth/i', $error);
}

function jeab_error_html(?string $error): string
{
    if (!$error) {
        return '';
    }
    $html = '<p class="error" role="alert"><span class="error-text">' . e($error) . '</span>';
    if (jeab_error_is_fortnox_oauth($error)) {
        $html .= ' <a class="error-laga" href="' . e(jeab_fortnox_oauth_setup_url()) . '">Laga</a>';
    }
    $html .= '</p>';
    return $html;
}

function jeab_empty_lines(): array
{
    return [
        'Tomt. Inte ens en lös skruv.',
        'Tystare än i en avstängd central.',
        'Inget att dra kabel till just nu.',
        'Listan är tom. Ta fem.',
        'Inga jobb i den här högen.',
        'Rent. Nästan läskigt.',
        'Inget att bocka av. Ovanligt.',
        'Mer luft än koppar här.',
        'Ingen som väntar. Bilen kan stå.',
        'Tomt. Som en låda utan bits.',
        'Noll på mätaren. Inget att jaga.',
        'Inget rött, inget gult. Bara tyst.',
        'Den här högen har semester.',
        'Ingen som glömt något. Än.',
        'Inget att skruva i. Lägg ifrån dig nyckeln.',
        'Inte ens en jordfelsbrytare som klagar.',
        'Mer damm än jobb.',
        'Inget att åka på. Kaffet hinner bli kallt.',
        'Lugnare än en lördag utan jour.',
        'Inget att släppa. Inget att vinna.',
        'Batteriet är fullt och ingen ringer.',
        'Mer rast än rot just nu.',
        'Tomt. Som en ny dosa.',
        'Inga flaggor. Misstänkt lugnt.',
    ];
}

function jeab_empty_line(): string
{
    $lines = jeab_empty_lines();
    $count = count($lines);
    $pick = random_int(0, $count - 1);
    $prev = $GLOBALS['_JEAB_EMPTY_LINE'] ?? null;
    if (session_status() === PHP_SESSION_ACTIVE && array_key_exists('_jeab_empty_line', $_SESSION)) {
        $prev = $_SESSION['_jeab_empty_line'];
    }
    if ($count > 1 && $pick === $prev) {
        $pick = ($pick + random_int(1, $count - 1)) % $count;
    }
    $GLOBALS['_JEAB_EMPTY_LINE'] = $pick;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['_jeab_empty_line'] = $pick;
    }
    return $lines[$pick];
}

function jeab_empty_html(): string
{
    return '<p class="empty">' . e(jeab_empty_line()) . '</p>';
}
