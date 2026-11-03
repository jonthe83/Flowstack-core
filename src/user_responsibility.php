<?php

declare(strict_types=1);

/**
 * Användare: ansvarsområde per person. EN SQL-tabell (user_responsibility), ingen JSON-fil.
 *
 * Rad per person: fritext (note) och strukturerade regler (rules, JSON-text i kolumnen):
 *   [{"product":"sol|batteri|laddbox","region":"stockholm|skane","roles":["owner","quote","sender","install"]}]
 *
 * Roller:
 *   owner   påminnelse-/leadägare (jeab_lead_owner, jeab_no_email_owner, jeab_webshop_assignee, support-tilldelning)
 *   quote   namn på offertägaren i inkorgen (jeab_lead_quote_owner_name)
 *   sender  offert-avsändare (graph_person.php). Läses ännu inte av avsändarkoden, paket H flyttar dit.
 *   install installationskalender/arbetslag (jeab_malmo_job_crew)
 *
 * Tom tabell seedas med dagens hårdkodade värden, så beteendet är oförändrat tills någon redigerar.
 * Utan SQL (json-drivern eller tester med JEAB_DISABLE_SQLITE) gäller seeden.
 */

const JEAB_RESP_PRODUCTS = ['sol' => 'Solceller', 'batteri' => 'Batteri', 'laddbox' => 'Elbilsladdare'];
const JEAB_RESP_REGIONS = ['stockholm' => 'Stockholm/Järfälla', 'skane' => 'Skåne/Malmö'];
const JEAB_RESP_ROLES = ['owner' => 'Påminnelseägare', 'quote' => 'Offertägare', 'sender' => 'Offert-avsändare', 'install' => 'Installation'];

/** @return array<string, array{note:string, rules:list<array{product:string,region:string,roles:list<string>}>}> */
function jeab_user_responsibility_seed(): array
{
    $r = static fn (string $p, string $reg, array $roles): array => ['product' => $p, 'region' => $reg, 'roles' => $roles];
    return [
        'SamL' => ['note' => 'Solceller (sol/solpaneler, även sol+batteri) i Stockholm/Järfälla, offerter.', 'rules' => [
            $r('sol', 'stockholm', ['owner', 'quote', 'sender']),
        ]],
        'Roberto' => ['note' => 'Batteri Stockholm/Järfälla: offerter och installation.', 'rules' => [
            $r('batteri', 'stockholm', ['owner', 'quote', 'sender', 'install']),
        ]],
        'Elin' => ['note' => 'Elbilsladdare Stockholm/Järfälla: offerter och installation.', 'rules' => [
            $r('laddbox', 'stockholm', ['owner', 'quote', 'sender', 'install']),
        ]],
        'Niman' => ['note' => 'Solcellsinstallation Stockholm/Järfälla.', 'rules' => [
            $r('sol', 'stockholm', ['install']),
        ]],
        'Kim' => ['note' => 'Batteri och elbilsladdare i Skåne: offerter och installation.', 'rules' => [
            $r('batteri', 'skane', ['owner', 'quote', 'sender', 'install']),
            $r('laddbox', 'skane', ['owner', 'quote', 'sender', 'install']),
        ]],
        'SamW' => ['note' => 'Solceller Skåne (offertnamn i inkorgen). Ingen påminnelseregel.', 'rules' => [
            $r('sol', 'skane', ['quote']),
        ]],
        'Isaq' => ['note' => 'Solcellsinstallation Skåne.', 'rules' => [$r('sol', 'skane', ['install'])]],
        'Abol' => ['note' => 'Solcellsinstallation Skåne.', 'rules' => [$r('sol', 'skane', ['install'])]],
    ];
}

function jeab_resp_region(string $raw): string
{
    $k = mb_strtolower(trim($raw), 'UTF-8');
    return match ($k) {
        'stockholm', 'jarfalla', 'järfälla', 'sthlm' => 'stockholm',
        'skane', 'skåne', 'malmo', 'malmö' => 'skane',
        default => '',
    };
}

function jeab_resp_product(string $raw): string
{
    $k = mb_strtolower(trim($raw), 'UTF-8');
    if (in_array($k, ['sol+batteri', 'solpaneler', 'solceller', 'solcell'], true)) {
        return 'sol';
    }
    if (in_array($k, ['elbilsladdare', 'laddare', 'laddbox'], true)) {
        return 'laddbox';
    }
    if (isset(JEAB_RESP_PRODUCTS[$k])) {
        return $k;
    }
    if (function_exists('jeab_auto_offer_canonical') && jeab_auto_offer_canonical($k) === 'sol+batteri') {
        return 'sol';
    }
    return '';
}

/** @return list<array{product:string,region:string,roles:list<string>}> */
function jeab_resp_normalize_rules(mixed $raw): array
{
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }
    $out = [];
    $seen = [];
    foreach (is_array($raw) ? $raw : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $p = jeab_resp_product((string) ($row['product'] ?? ''));
        $reg = jeab_resp_region((string) ($row['region'] ?? ''));
        if ($p === '' || $reg === '') {
            continue;
        }
        $roles = [];
        foreach ((array) ($row['roles'] ?? []) as $role) {
            $role = (string) $role;
            if (isset(JEAB_RESP_ROLES[$role]) && !in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }
        if ($roles === [] || isset($seen[$p . '|' . $reg])) {
            continue;
        }
        $seen[$p . '|' . $reg] = true;
        $out[] = ['product' => $p, 'region' => $reg, 'roles' => $roles];
    }
    return $out;
}

function jeab_resp_ensure(PDO $pdo, string $driver): void
{
    static $done = [];
    $id = spl_object_id($pdo);
    if (isset($done[$id])) {
        return;
    }
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_responsibility (
                person_id VARCHAR(64) NOT NULL,
                note TEXT NOT NULL,
                rules TEXT NOT NULL,
                updated_at DATETIME NOT NULL,
                updated_by VARCHAR(64) NOT NULL DEFAULT \'\',
                PRIMARY KEY (person_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_responsibility (
                person_id TEXT PRIMARY KEY,
                note TEXT NOT NULL DEFAULT \'\',
                rules TEXT NOT NULL DEFAULT \'[]\',
                updated_at TEXT NOT NULL,
                updated_by TEXT NOT NULL DEFAULT \'\'
            )'
        );
    }
    $n = (int) $pdo->query('SELECT COUNT(*) FROM user_responsibility')->fetchColumn();
    if ($n === 0) {
        $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $st = $pdo->prepare('INSERT INTO user_responsibility (person_id, note, rules, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)');
        foreach (jeab_user_responsibility_seed() as $pid => $row) {
            $st->execute([$pid, $row['note'], json_encode($row['rules'], JSON_UNESCAPED_UNICODE), $at, 'seed']);
        }
    }
    $done[$id] = true;
}

/**
 * Läsning öppnar ingen egen databasanslutning: bara en som redan är öppen (aktiv store eller poolen).
 * Utan öppen anslutning gäller seeden (= dagens regler). Sidan Användare och sparande ansluter ($connect).
 */
function jeab_resp_pdo(bool $connect = false): ?PDO
{
    if (($GLOBALS['_JEAB_RESP_PDO'] ?? null) instanceof PDO) {
        $pdo = $GLOBALS['_JEAB_RESP_PDO'];
        jeab_resp_ensure($pdo, (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite');
        return $pdo;
    }
    try {
        $driver = function_exists('jeab_driver') ? jeab_driver() : 'json';
        if (!$connect) {
            $store = $GLOBALS['_JEAB_ACTIVE_STORE'] ?? null;
            $open = class_exists('JeabPdoStore', false) && $store instanceof JeabPdoStore && $store->hasPdo();
            if (!$open && empty($GLOBALS['_JEAB_PDO_POOL'])) {
                return null;
            }
        }
        if ($driver === 'mysql' && function_exists('jeab_pdo_connect_mysql')) {
            $pdo = jeab_pdo_connect_mysql();
        } elseif ($driver === 'sqlite' && function_exists('jeab_pdo_connect_sqlite') && getenv('JEAB_DISABLE_SQLITE') !== '1') {
            $pdo = jeab_pdo_connect_sqlite();
        } else {
            return null;
        }
        jeab_resp_ensure($pdo, $driver);
        return $pdo;
    } catch (Throwable $e) {
        error_log('user_responsibility: ' . $e::class);
        return null;
    }
}

function jeab_user_responsibility_reset_cache(): void
{
    unset($GLOBALS['_JEAB_RESP_CACHE']);
}

/** @return array<string, array{note:string, rules:list<array{product:string,region:string,roles:list<string>}>, updated_at?:string, updated_by?:string}> */
function jeab_user_responsibility_all(bool $connect = false): array
{
    if (isset($GLOBALS['_JEAB_RESP_CACHE']) && is_array($GLOBALS['_JEAB_RESP_CACHE'])) {
        return $GLOBALS['_JEAB_RESP_CACHE'];
    }
    $pdo = jeab_resp_pdo($connect);
    if (!$pdo instanceof PDO) {
        // Seed cachas inte, så en senare öppnad anslutning läses.
        return jeab_user_responsibility_seed();
    } else {
        $all = [];
        try {
            foreach ($pdo->query('SELECT person_id, note, rules, updated_at, updated_by FROM user_responsibility ORDER BY person_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $all[(string) $row['person_id']] = [
                    'note' => (string) $row['note'],
                    'rules' => jeab_resp_normalize_rules((string) $row['rules']),
                    'updated_at' => (string) $row['updated_at'],
                    'updated_by' => (string) $row['updated_by'],
                ];
            }
        } catch (Throwable $e) {
            error_log('user_responsibility read: ' . $e::class);
            $all = jeab_user_responsibility_seed();
        }
    }
    $GLOBALS['_JEAB_RESP_CACHE'] = $all;
    return $all;
}

/** @return array{note:string, rules:list<array{product:string,region:string,roles:list<string>}>} */
function jeab_user_responsibility_get(string $personId): array
{
    $all = jeab_user_responsibility_all();
    return $all[$personId] ?? ['note' => '', 'rules' => []];
}

/** Sparar fritext + regler för en person. Kräver SQL. */
function jeab_user_responsibility_save(string $personId, string $note, mixed $rules, string $by = ''): bool
{
    $personId = trim($personId);
    if ($personId === '' || strlen($personId) > 64) {
        return false;
    }
    $pdo = jeab_resp_pdo(true);
    if (!$pdo instanceof PDO) {
        return false;
    }
    $note = mb_substr(trim($note), 0, 2000, 'UTF-8');
    $json = json_encode(jeab_resp_normalize_rules($rules), JSON_UNESCAPED_UNICODE);
    $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $pdo->prepare('DELETE FROM user_responsibility WHERE person_id = ?')->execute([$personId]);
    $pdo->prepare('INSERT INTO user_responsibility (person_id, note, rules, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)')
        ->execute([$personId, $note, $json, $at, mb_substr($by, 0, 64, 'UTF-8')]);
    jeab_user_responsibility_reset_cache();
    return true;
}

/**
 * Alla person-id med rollen för produkt+region, i tabellordning (seedordning först).
 *
 * @return list<string>
 */
function jeab_user_responsibility_people_for(string $product, string $region, string $role = 'owner'): array
{
    $p = jeab_resp_product($product);
    $reg = jeab_resp_region($region);
    if ($p === '' || $reg === '' || !isset(JEAB_RESP_ROLES[$role])) {
        return [];
    }
    $all = jeab_user_responsibility_all();
    $order = array_keys(jeab_user_responsibility_seed());
    $ids = array_keys($all);
    usort($ids, static function (string $a, string $b) use ($order): int {
        $ia = array_search($a, $order, true);
        $ib = array_search($b, $order, true);
        return ($ia === false ? 999 : $ia) <=> ($ib === false ? 999 : $ib) ?: strcmp($a, $b);
    });
    $out = [];
    foreach ($ids as $pid) {
        foreach ($all[$pid]['rules'] as $rule) {
            if ($rule['product'] === $p && $rule['region'] === $reg && in_array($role, $rule['roles'], true)) {
                $out[] = $pid;
                break;
            }
        }
    }
    return $out;
}

/** Första person-id för produkt+region+roll, '' när ingen regel finns (t.ex. sol i Skåne, owner). */
function jeab_user_responsibility_for(string $product, string $region, string $role = 'owner'): string
{
    return jeab_user_responsibility_people_for($product, $region, $role)[0] ?? '';
}

/** Visningsnamn för ett person-id (Sam L för SamL). */
function jeab_user_responsibility_name(string $personId): string
{
    if ($personId === '') {
        return '';
    }
    if (function_exists('jeab_people')) {
        foreach (jeab_people() as $p) {
            if (is_array($p) && strcasecmp((string) ($p['id'] ?? ''), $personId) === 0) {
                return (string) ($p['name'] ?? $personId);
            }
        }
    }
    return $personId;
}

/** Läsbar rad: «Solceller · Stockholm/Järfälla · Påminnelseägare, Offertägare». */
function jeab_user_responsibility_rule_label(array $rule): string
{
    $roles = array_map(static fn ($r) => JEAB_RESP_ROLES[$r] ?? $r, (array) ($rule['roles'] ?? []));
    return (JEAB_RESP_PRODUCTS[$rule['product']] ?? $rule['product']) . ' · '
        . (JEAB_RESP_REGIONS[$rule['region']] ?? $rule['region']) . ' · ' . implode(', ', $roles);
}

/** Regler från formuläret: resp[product|region] = list<role>. */
function jeab_user_responsibility_rules_from_post(array $grid): array
{
    $rules = [];
    foreach (JEAB_RESP_PRODUCTS as $p => $_) {
        foreach (JEAB_RESP_REGIONS as $reg => $_r) {
            $roles = (array) ($grid[$p . '|' . $reg] ?? []);
            if ($roles !== []) {
                $rules[] = ['product' => $p, 'region' => $reg, 'roles' => array_values(array_map('strval', $roles))];
            }
        }
    }
    return jeab_resp_normalize_rules($rules);
}
