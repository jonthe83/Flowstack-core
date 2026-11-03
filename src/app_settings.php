<?php

declare(strict_types=1);

/*
 * Generella inställningar (inte demo-specifika): bolagsväljare och moduler.
 * Sparas i tabellen settings (nyckel app.settings, JSON). Ingen ny tabell.
 */

const JEAB_APP_SETTINGS_KEY = 'app.settings';

/** @return array<string, array{0:string,1:bool}> nyckel => [etikett, standard] */
function jeab_app_settings_schema(): array
{
    return [
        'company_jarfalla' => ['Järfälla', false],
        'company_malmo' => ['Malmö', false],
        'module_inkorg' => ['Inkorg', true],
        'module_reminders' => ['Påminnelser', true],
        'module_bookings' => ['Kalender (bokningar)', true],
        'module_notes' => ['Interna anteckningar', true],
        'module_fullmakter' => ['E-signering', true],
    ];
}

function jeab_app_settings_pdo(): ?PDO
{
    try {
        if (!function_exists('jeab_store')) {
            return null;
        }
        $store = jeab_store();
        return (class_exists('JeabPdoStore') && $store instanceof JeabPdoStore) ? $store->pdo() : null;
    } catch (Throwable) {
        return null;
    }
}

function jeab_app_settings_col(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`key`' : '"key"';
}

/** @return array<string, bool> */
function jeab_app_settings(): array
{
    if (isset($GLOBALS['_JEAB_APP_SETTINGS']) && is_array($GLOBALS['_JEAB_APP_SETTINGS'])) {
        return $GLOBALS['_JEAB_APP_SETTINGS'];
    }
    $saved = [];
    $pdo = jeab_app_settings_pdo();
    if ($pdo !== null) {
        try {
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE ' . jeab_app_settings_col($pdo) . ' = ?');
            $stmt->execute([JEAB_APP_SETTINGS_KEY]);
            $raw = $stmt->fetchColumn();
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $saved = is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            $saved = [];
        }
    }
    $out = [];
    foreach (jeab_app_settings_schema() as $key => [, $default]) {
        $out[$key] = array_key_exists($key, $saved) ? (bool) $saved[$key] : $default;
    }
    $GLOBALS['_JEAB_APP_SETTINGS'] = $out;

    return $out;
}

/** @param array<string, bool> $values */
function jeab_app_settings_save(array $values): bool
{
    $pdo = jeab_app_settings_pdo();
    if ($pdo === null) {
        return false;
    }
    $clean = [];
    foreach (jeab_app_settings_schema() as $key => [, $default]) {
        $clean[$key] = (bool) ($values[$key] ?? false);
    }
    $json = json_encode($clean);
    $col = jeab_app_settings_col($pdo);
    try {
        $pdo->prepare('DELETE FROM settings WHERE ' . $col . ' = ?')->execute([JEAB_APP_SETTINGS_KEY]);
        $pdo->prepare('INSERT INTO settings (' . $col . ', value) VALUES (?, ?)')->execute([JEAB_APP_SETTINGS_KEY, $json]);
    } catch (Throwable) {
        return false;
    }
    unset($GLOBALS['_JEAB_APP_SETTINGS']);

    return true;
}

function jeab_app_module_on(string $module): bool
{
    $s = jeab_app_settings();

    return $s['module_' . $module] ?? true;
}

/** @return list<string> påslagna bolag för väljaren */
function jeab_app_companies_on(): array
{
    $s = jeab_app_settings();
    $out = [];
    foreach (['jarfalla', 'malmo'] as $co) {
        if (!empty($s['company_' . $co])) {
            $out[] = $co;
        }
    }

    return $out;
}

/** Bolag som appen ska låsas till, eller null om fritt val (båda på). */
function jeab_app_forced_company(): ?string
{
    $on = jeab_app_companies_on();
    if (count($on) === 2) {
        return null;
    }

    return $on[0] ?? 'jarfalla';
}

/** Vilken modul en sida hör till, eller null. */
function jeab_app_page_module(string $page): ?string
{
    $map = [
        'inkorg' => 'inkorg', 'inkorg-regler' => 'inkorg', 'inkorg-vyer' => 'inkorg', 'inkorg-bilaga' => 'inkorg',
        'reminders' => 'reminders', 'reminder' => 'reminders',
        'bookings' => 'bookings',
        'notes' => 'notes',
        'fullmakter' => 'fullmakter', 'fullmakt' => 'fullmakter',
    ];

    return $map[$page] ?? null;
}

function jeab_app_home_slug(): string
{
    foreach (['reminders', 'inkorg', 'bookings'] as $m) {
        if (jeab_app_module_on($m)) {
            return $m;
        }
    }

    return 'ordrar';
}

function jeab_app_module_off_page(): never
{
    http_response_code(404);
    $body = '<div class="links-page"><h1>Modulen är avstängd</h1><p>Den här delen är avstängd under Övrigt → Inställningar.</p>'
        . '<p><a class="btn" href="' . e(jeab_url('index.php?p=' . jeab_app_home_slug())) . '">Till startsidan</a></p></div>';
    jeab_layout('Avstängd · JEAB', 'links', $body);
    exit;
}

function jeab_app_settings_page_html(?string $flash = null): string
{
    $s = jeab_app_settings();
    $schema = jeab_app_settings_schema();
    $admin = function_exists('jeab_is_admin') && jeab_is_admin();
    $row = static function (string $key) use ($s, $schema, $admin): string {
        $checked = !empty($s[$key]) ? ' checked' : '';
        $dis = $admin ? '' : ' disabled';
        return '<label class="app-set-row" style="display:flex;gap:10px;align-items:center;padding:8px 0">'
            . '<input type="checkbox" role="switch" name="' . e($key) . '" value="1"' . $checked . $dis . '> '
            . '<span>' . e($schema[$key][0]) . '</span></label>';
    };
    $h = '<div class="links-page settings-page"><h1>Inställningar</h1>';
    if ($flash !== null && $flash !== '') {
        $h .= '<p class="flash" role="status">' . e($flash) . '</p>';
    }
    $h .= '<form method="post" action="' . e(jeab_url('index.php?p=installningar')) . '">' . jeab_csrf_field()
        . '<input type="hidden" name="action" value="app_settings.save">';
    $h .= '<h2>Bolag</h2><p class="ink-muted">Slå på de bolag som ska gå att välja i menyn. När ett bolag är av syns inte dess knapp. När båda är av visas ingen bolagsväljare och appen använder Järfälla. Är bara ett på används det bolaget.</p>';
    $h .= $row('company_jarfalla') . $row('company_malmo');
    $h .= '<h2>Moduler</h2><p class="ink-muted">En avstängd modul försvinner ur menyn och dess sidor visar att modulen är avstängd.</p>';
    foreach (['module_inkorg', 'module_reminders', 'module_bookings', 'module_notes', 'module_fullmakter'] as $k) {
        $h .= $row($k);
    }
    $h .= $admin ? '<p><button class="btn btn-primary" type="submit">Spara</button></p>' : '<p class="ink-muted">Bara admin kan ändra.</p>';
    $h .= '</form></div>';

    return $h;
}

/** Sidan Övrigt → Inställningar. Anropas från index.php efter inloggning. */
function jeab_app_settings_route(string $page): void
{
    if ($page !== 'installningar') {
        return;
    }
    $flash = null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'app_settings.save') {
        if (!jeab_csrf_ok() || !(function_exists('jeab_is_admin') && jeab_is_admin())) {
            $flash = 'Kunde inte spara. Ladda om sidan och försök igen.';
        } else {
            $vals = [];
            foreach (array_keys(jeab_app_settings_schema()) as $k) {
                $vals[$k] = !empty($_POST[$k]);
            }
            $flash = jeab_app_settings_save($vals) ? 'Sparat.' : 'Kunde inte spara.';
        }
    }
    jeab_layout('Inställningar · JEAB', 'links', jeab_app_settings_page_html($flash));
    exit;
}
