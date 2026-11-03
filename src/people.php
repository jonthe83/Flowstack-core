<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/normalize.php';
function jeab_company_catalog(): array
{
    return [
        'jarfalla' => [
            'id' => 'jarfalla',
            'label' => 'Järfälla',
            'domain' => 'jarfalla.example.test',
            'mail_name' => 'Demo Energi Järfälla',
        ],
        'malmo' => [
            'id' => 'malmo',
            'label' => 'Malmö',
            'domain' => 'malmo.example.test',
            'mail_name' => 'Demo Energi Malmö',
        ],
    ];
}

function jeab_default_people(): array
{
    return [
        ['id' => 'Roberto', 'email' => 'saljare3@jarfalla.example.test', 'name' => 'Roberto', 'company' => 'jarfalla', 'job' => 'batteri'],
        ['id' => 'SamL', 'email' => 'demo@example.test', 'name' => 'Sam L', 'company' => 'jarfalla', 'job' => null, 'username' => 'sam'],
        ['id' => 'Elin', 'email' => 'saljare4@jarfalla.example.test', 'name' => 'Elin', 'company' => 'jarfalla', 'job' => 'laddbox'],
        ['id' => 'Niman', 'email' => 'saljare5@jarfalla.example.test', 'name' => 'Niman', 'company' => 'jarfalla', 'job' => 'sol'],
        ['id' => 'Isaq', 'email' => 'saljare6@malmo.example.test', 'name' => 'Isaq', 'company' => 'malmo', 'job' => null],
        ['id' => 'SamW', 'email' => 'demo@malmo.example.test', 'name' => 'Sam W', 'company' => 'malmo', 'job' => null],
        ['id' => 'Abol', 'email' => 'saljare7@malmo.example.test', 'name' => 'Abol', 'company' => 'malmo', 'job' => 'sol'],
        ['id' => 'Kim', 'email' => 'saljare.syd@malmo.example.test', 'name' => 'Kim', 'company' => 'malmo', 'job' => 'laddbox'],
    ];
}

function jeab_normalize_person_email(?string $email): ?string
{
    $email = jeab_norm_email((string) $email);
    if ($email === '' || !str_contains($email, '@')) {
        return null;
    }
    return $email;
}

function jeab_normalize_company(mixed $raw): ?string
{
    $raw = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string) $raw), 'UTF-8')
        : strtolower(trim((string) $raw));
    if ($raw === '') {
        return null;
    }
    $raw = strtr($raw, ['ä' => 'a', 'å' => 'a', 'ö' => 'o']);
    if (in_array($raw, ['jarfalla', 'jeab'], true)) {
        return 'jarfalla';
    }
    if (in_array($raw, ['malmo', 'meab'], true)) {
        return 'malmo';
    }
    return null;
}

function jeab_normalize_job_key(mixed $raw): ?string
{
    $raw = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string) $raw), 'UTF-8')
        : strtolower(trim((string) $raw));
    if ($raw === '') {
        return null;
    }
    if (isset(JEAB_JOB_TYPES[$raw])) {
        return $raw;
    }
    foreach (JEAB_JOB_TYPES as $key => $label) {
        $labelKey = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
        if ($raw === $labelKey) {
            return $key;
        }
    }
    return null;
}

function jeab_company_from_email(?string $email): ?string
{
    $email = jeab_normalize_person_email($email);
    if ($email === null) {
        return null;
    }
    $domain = substr(strrchr($email, '@') ?: '', 1);
    foreach (jeab_company_catalog() as $id => $row) {
        if ($domain === $row['domain']) {
            return $id;
        }
    }
    return null;
}

function jeab_people(): array
{
    static $cache;
    if ($cache !== null) {
        return $cache;
    }
    $raw = jeab_config()['people'] ?? null;
    $rows = is_array($raw) && $raw !== [] ? $raw : jeab_default_people();
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $email = jeab_normalize_person_email($row['email'] ?? null);
        $name = trim((string) ($row['name'] ?? ''));
        $company = jeab_normalize_company($row['company'] ?? null) ?? jeab_company_from_email($email);
        if ($email === null || $name === '' || $company === null) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            $id = preg_replace('/\s+/', '', $name) ?: $name;
        }
        $person = [
            'id' => $id,
            'email' => $email,
            'name' => $name,
            'company' => $company,
            'job' => jeab_normalize_job_key($row['job'] ?? null),
            'username' => strtolower(trim((string) ($row['username'] ?? ''))),
        ];
        if (isset($row['alias'])) {
            $person['alias'] = $row['alias'];
        }
        if (isset($row['aliases'])) {
            $person['aliases'] = $row['aliases'];
        }
        $out[] = $person;
    }
    $cache = $out !== [] ? $out : jeab_default_people();
    return $cache;
}

function jeab_company_people(?string $company = null): array
{
    $company = jeab_normalize_company($company) ?? jeab_selected_company();
    return array_values(array_filter(
        jeab_people(),
        static fn(array $row): bool => $row['company'] === $company
    ));
}

function jeab_company_names(?string $company = null): array
{
    $names = [];
    foreach (jeab_company_people($company) as $row) {
        $names[] = $row['name'];
    }
    return $names;
}

function jeab_person_initials(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') {
        return '';
    }
    $parts = preg_split('/\s+/u', $name) ?: [];
    $first = (string) ($parts[0] ?? '');
    $last = count($parts) > 1 ? (string) $parts[count($parts) - 1] : '';
    $a = function_exists('mb_substr') ? mb_substr($first, 0, 1, 'UTF-8') : substr($first, 0, 1);
    $b = $last !== ''
        ? (function_exists('mb_substr') ? mb_substr($last, 0, 1, 'UTF-8') : substr($last, 0, 1))
        : '';
    $out = $a . $b;
    return function_exists('mb_strtoupper') ? mb_strtoupper($out, 'UTF-8') : strtoupper($out);
}

function jeab_known_installer_names(): array
{
    $names = [];
    foreach (jeab_people() as $row) {
        if (!in_array($row['name'], $names, true)) {
            $names[] = $row['name'];
        }
    }
    return $names !== [] ? $names : JEAB_INSTALLERS;
}

function jeab_person_by_email(?string $email): ?array
{
    $email = jeab_normalize_person_email($email);
    if ($email === null) {
        return null;
    }
    foreach (jeab_people() as $row) {
        if ($row['email'] === $email) {
            return $row;
        }
    }
    return null;
}

/**
 * Delade kampanjkoder. De är ingen person och får inte kopplas till en person
 * via config login_people. agenter@flowstack.example.test är ändå admin i jeab_is_admin().
 *
 * @return list<string>
 */
function jeab_shared_login_emails(): array
{
    return ['agenter@flowstack.example.test'];
}

function jeab_is_shared_login_email(?string $email): bool
{
    $email = jeab_normalize_person_email($email);
    return $email !== null && in_array($email, jeab_shared_login_emails(), true);
}

/**
 * Delad agentkod (QA, Kundtjänst, Bokmalen) får båda kontoren.
 * Admin är separat: jeab_is_admin() är sann för den här adressen.
 *
 * @return list<string>
 */
function jeab_shared_identity_companies(): array
{
    return ['jarfalla', 'malmo'];
}

/**
 * Inloggning -> person. Kampanjkoderna i passwords.json bär en e-post som etikett (aldrig koden).
 * Etiketter som inte är personens egen e-post kopplas här. Värdet är person-id i personregistret.
 * config 'login_people' kan lägga till eller ändra rader utan kodändring.
 * agenter@flowstack.example.test lämnas tom: den delade koden är ingen person.
 *
 * @return array<string,string> e-post (gemener) => person-id
 */
function jeab_login_person_map(): array
{
    $map = [];
    $extra = function_exists('jeab_config') ? (jeab_config()['login_people'] ?? null) : null;
    if (is_array($extra)) {
        foreach ($extra as $email => $personId) {
            $email = jeab_normalize_person_email((string) $email);
            if ($email === null || jeab_is_shared_login_email($email) || !is_string($personId) || trim($personId) === '') {
                continue;
            }
            $map[$email] = trim($personId);
        }
    }
    return $map;
}

/** Personen bakom en inloggning: egen e-post i registret först, sedan kopplingstabellen. Okänd = null (Alla). */
function jeab_person_by_login(?string $email): ?array
{
    $email = jeab_normalize_person_email($email);
    if ($email === null || jeab_is_shared_login_email($email)) {
        return null;
    }
    $direct = jeab_person_by_email($email);
    if ($direct !== null) {
        return $direct;
    }
    $personId = jeab_login_person_map()[$email] ?? null;
    if ($personId === null) {
        return null;
    }
    foreach (jeab_people() as $row) {
        if (strcasecmp((string) $row['id'], $personId) === 0) {
            return $row;
        }
    }
    return null;
}

/**
 * Koder utan e-post i passwords.json kan kopplas via config 'login_people' med nyckeln
 * «fp:<16 hex>» (kodens fingeravtryck, inte hemligt) => person-id.
 */
function jeab_person_by_login_fingerprint(?string $fp): ?array
{
    $fp = strtolower(trim((string) $fp));
    if ($fp === '' || !ctype_xdigit($fp) || strlen($fp) !== 16) {
        return null;
    }
    $extra = function_exists('jeab_config') ? (jeab_config()['login_people'] ?? null) : null;
    if (!is_array($extra)) {
        return null;
    }
    $personId = null;
    foreach ($extra as $key => $value) {
        if (strcasecmp(trim((string) $key), 'fp:' . $fp) === 0 && is_string($value) && trim($value) !== '') {
            $personId = trim($value);
            break;
        }
    }
    if ($personId === null) {
        return null;
    }
    foreach (jeab_people() as $row) {
        if (strcasecmp((string) $row['id'], $personId) === 0) {
            return $row;
        }
    }
    return null;
}

/**
 * Användarnamnet som kampanjkoden börjar med. Eget «username» i personlistan,
 * annars standardlistans, annars id med gemener (Roberto → roberto, SamW → samw).
 */
function jeab_person_username(array $row): string
{
    $u = strtolower(trim((string) ($row['username'] ?? '')));
    if ($u === '') {
        foreach (jeab_default_people() as $d) {
            if (strcasecmp((string) $d['id'], (string) ($row['id'] ?? '')) === 0) {
                $u = strtolower(trim((string) ($d['username'] ?? '')));
                break;
            }
        }
    }
    if ($u === '') {
        $u = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', (string) ($row['id'] ?? '')));
    }
    return $u;
}

/**
 * Förnamnet som den personliga koden börjar med. «Sam L» och «Sam W»
 * har samma förnamn. Efternamnsinitialen sitter i det längre prefixet.
 */
function jeab_person_name_prefix(array $row): string
{
    $name = trim((string) ($row['name'] ?? ''));
    $first = preg_split('/\s+/u', $name, 2)[0] ?? '';
    $first = function_exists('mb_strtolower') ? mb_strtolower((string) $first, 'UTF-8') : strtolower((string) $first);
    $first = strtr($first, ['ä' => 'a', 'å' => 'a', 'ö' => 'o', 'é' => 'e']);

    return (string) preg_replace('/[^a-z0-9]/', '', $first);
}

/**
 * De första 9 tecknen i kampanjkoden. De är inte hemliga och identifierar personen.
 * Resten av koden får varken sparas eller visas.
 */
function jeab_code_head(string $code): string
{
    // Blanksteg, punkt, bindestreck och understreck räknas inte. «Sam L…» ger
    // huvudet SamL, inte «Sam » som inte skiljer Sam L från Sam W.
    $code = (string) (preg_replace('/[\s._\-]+/u', '', trim($code)) ?? trim($code));
    if ($code === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($code, 0, 9, 'UTF-8');
    }

    return substr($code, 0, 9);
}

/** Gemener utan skiljetecken. Används på nycklar, inte på hela koden. */
function jeab_person_code_fold(string $value): string
{
    $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
    $value = strtr($value, ['ä' => 'a', 'å' => 'a', 'ö' => 'o', 'é' => 'e']);

    return (string) preg_replace('/[^a-z0-9]/', '', $value);
}

/**
 * Det koden får börja med: användarnamn, namn utan mellanslag (saml) och förnamnet.
 * Förnamnet är den korta nyckeln. Den längre nyckeln skiljer Sam L från Sam W.
 *
 * @return list<string>
 */
function jeab_person_code_keys(array $row): array
{
    $keys = [];
    foreach ([
        jeab_person_username($row),
        (string) ($row['name'] ?? ''),
        jeab_person_name_prefix($row),
    ] as $raw) {
        $key = jeab_person_code_fold($raw);
        if (strlen($key) >= 2) {
            $keys[$key] = true;
        }
    }

    return array_keys($keys);
}

/**
 * Koden som prefixet jämförs med. Gemener, utan blanksteg.
 * Versaler och mellanslag i de första tecknen ska träffa samma person.
 * Koden sparas inte.
 */
function jeab_person_code_probe(string $code): string
{
    $code = trim($code);
    $code = function_exists('mb_strtolower') ? mb_strtolower($code, 'UTF-8') : strtolower($code);
    $code = strtr($code, ['ä' => 'a', 'å' => 'a', 'ö' => 'o', 'é' => 'e']);
    // Blanksteg, punkt, bindestreck och understreck räknas inte: «Sam-L…» är saml.
    $code = preg_replace('/[\s._\-]+/u', '', $code) ?? $code;

    return $code;
}

/** Längden på den längsta nyckel som koden börjar med. 0 om ingen träffar. Koden sparas inte. */
function jeab_person_code_key_len(string $code, array $row): int
{
    $code = jeab_person_code_probe($code);
    $best = 0;
    foreach (jeab_person_code_keys($row) as $key) {
        if (str_starts_with($code, $key) && strlen($key) > $best) {
            $best = strlen($key);
        }
    }

    return $best;
}

/**
 * Personer vars användarnamn, namn utan mellanslag eller förnamn koden börjar med.
 * Längsta prefixet vinner. SamL… är bara Sam L, SamW… bara Sam W.
 * Ett ensamt Sam… träffar båda. Koden sparas aldrig.
 *
 * @return list<array<string, mixed>>
 */
function jeab_people_by_code_prefix(string $code): array
{
    $code = jeab_person_code_probe($code);
    if ($code === '') {
        return [];
    }
    $hits = [];
    $best = 0;
    foreach (jeab_people() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $len = jeab_person_code_key_len($code, $row);
        if ($len < 2) {
            continue;
        }
        if ($len > $best) {
            $best = $len;
            $hits = [$row];
        } elseif ($len === $best) {
            $hits[] = $row;
        }
    }

    return $hits;
}

/**
 * Person från kodens början när prefixet är unikt.
 * SamL… är Sam L och SamW… är Sam W. Sam… ensamt är null.
 */
function jeab_person_by_code_prefix(string $code): ?array
{
    $hits = jeab_people_by_code_prefix($code);

    return count($hits) === 1 ? $hits[0] : null;
}

/** Sant när kodens träff är längre än det delade förnamnet (saml, samw). */
function jeab_person_code_prefix_disambiguated(string $code, array $person): bool
{
    $first = jeab_person_name_prefix($person);
    if ($first === '') {
        return jeab_person_code_key_len($code, $person) >= 2;
    }

    return jeab_person_code_key_len($code, $person) > strlen($first);
}

/** Sant när fler än en person har samma förnamn. Sam L och Sam W delar «sam». */
function jeab_person_first_name_shared(array $person): bool
{
    $prefix = jeab_person_name_prefix($person);
    if ($prefix === '') {
        return false;
    }
    $n = 0;
    foreach (jeab_people() as $row) {
        if (!is_array($row) || jeab_person_name_prefix($row) !== $prefix) {
            continue;
        }
        $n++;
        if ($n > 1) {
            return true;
        }
    }

    return false;
}

/** Orsak till senaste identitetsbeslutet. Tomt tills någon resolver körts. */
function jeab_who_identity_reason(?string $set = null): string
{
    if ($set !== null) {
        $GLOBALS['_JEAB_WHO_REASON'] = $set;
        if (in_array($set, ['ambiguous-no-email', 'ambiguous-stored', 'conflict'], true)
            && empty($GLOBALS['_JEAB_WHO_REASON_LOGGED'])) {
            $GLOBALS['_JEAB_WHO_REASON_LOGGED'] = true;
            error_log('jeab identity: ' . $set);
        }
    }

    return (string) ($GLOBALS['_JEAB_WHO_REASON'] ?? '');
}

/**
 * Personen bakom inloggningen som just användes. Längsta kodprefixet först
 * (saml, samw), annars unikt förnamn. Delad kampanjkod är ingen person.
 * Ett ensamt Sam… är osäkert och blir ingen person. E-postdomänen ger stad,
 * den väljer inte mellan Sam L och Sam W.
 * SamL… är Sam L även när radens e-post är Malmö, och tvärtom.
 * Unikt förnamn som krockar med en annan persons e-post är fortfarande ingen.
 */
/**
 * Gemensamt svar: är identiteten säker, och vilken person.
 * Delat förnamn utan 9-teckenshuvud är osäkert. En kod som inte träffar
 * något prefix är osäker när e-posten tillhör det delade förnamnet.
 * Unikt förnamn följer e-posten. Funktionen väljer inte kontor och rör inte sessionen.
 *
 * @return array{certain:bool,person:?array,reason:string}
 */
function jeab_certain_identity(?string $email, string $code = '', string $head = ''): array
{
    $none = static function (string $reason): array {
        return ['certain' => false, 'person' => null, 'reason' => $reason];
    };
    if (jeab_is_shared_login_email($email)) {
        return $none('shared');
    }
    $probe = trim($code) !== '' ? $code : $head;
    $byEmail = jeab_person_by_email($email);
    if ($probe !== '') {
        $hits = jeab_people_by_code_prefix($probe);
        if (count($hits) > 1) {
            return $none('ambiguous-no-email');
        }
        if (count($hits) === 1) {
            $hit = $hits[0];
            $disambiguated = jeab_person_code_prefix_disambiguated($probe, $hit);
            $same = is_array($byEmail)
                && strcasecmp((string) ($hit['id'] ?? ''), (string) ($byEmail['id'] ?? '')) === 0;
            if (is_array($byEmail) && !$same && !$disambiguated) {
                return $none('conflict');
            }
            if ($disambiguated || !jeab_person_first_name_shared($hit)) {
                $reason = ($disambiguated || $byEmail === null) ? 'code' : 'email';

                return ['certain' => true, 'person' => $hit, 'reason' => $reason];
            }

            return $none('ambiguous-no-head');
        }
    }
    if (is_array($byEmail) && jeab_person_first_name_shared($byEmail)) {
        return $none('ambiguous-no-head');
    }
    if (is_array($byEmail)) {
        return ['certain' => true, 'person' => $byEmail, 'reason' => 'email'];
    }
    $mapped = jeab_person_by_login($email);
    if (is_array($mapped) && jeab_person_first_name_shared($mapped)) {
        return $none('ambiguous-no-head');
    }
    if (is_array($mapped)) {
        return ['certain' => true, 'person' => $mapped, 'reason' => 'email'];
    }

    return $none('none');
}

/**
 * Personen bakom inloggningen som just användes. Längsta kodprefixet först
 * (saml, samw), annars unikt förnamn. Delad kampanjkod är ingen person.
 * Ett ensamt Sam… är osäkert och blir ingen person. En kod utan prefixträff
 * blir inte Sam L eller Sam W via e-posten. E-postdomänen ger stad,
 * den väljer inte mellan Sam L och Sam W.
 * SamL… är Sam L även när radens e-post är Malmö, och tvärtom.
 * Unikt förnamn som krockar med en annan persons e-post är fortfarande ingen.
 */
function jeab_person_from_login(string $code, ?string $email): ?array
{
    $id = jeab_certain_identity($email, $code, '');
    jeab_who_identity_reason((string) ($id['reason'] ?? 'none'));

    return is_array($id['person'] ?? null) ? $id['person'] : null;
}

function jeab_code_people_path(): string
{
    return (function_exists('jeab_data_dir') ? jeab_data_dir() : sys_get_temp_dir()) . '/code-people.json';
}

/** @return array<string, array{person: string, via: string, at: string, head?: string}> fp:… → person */
function jeab_code_people_read(bool $allowDdl = true): array
{
    if (!$allowDdl) {
        return jeab_code_people_read_noddl();
    }
    if (!function_exists('jeab_biz_doc_load')) {
        require_once __DIR__ . '/biz_doc_store.php';
    }
    // SQL-läge läser SQL. Tom kopia utan typflagga sql faller tillbaka till JSON i jeab_biz_doc_load.
    if (function_exists('jeab_biz_doc_storage_mode') && jeab_biz_doc_storage_mode('code_people') === 'sql') {
        if (function_exists('jeab_biz_doc_use_sql') && jeab_biz_doc_use_sql('code_people')) {
            $data = jeab_biz_doc_load('code_people');

            return is_array($data) ? $data : [];
        }

        return jeab_code_people_read_noddl();
    }
    return jeab_code_people_json_read();
}

/** @return array<string, mixed> */
function jeab_code_people_json_read(): array
{
    $path = jeab_code_people_path();
    $raw = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;

    return is_array($raw) ? $raw : [];
}

/**
 * Nyare `at` vinner per fingeravtryck. Lika tid behåller SQL.
 * SQL före en keep_json-backfill är facit: sparningen skriver dit och rör inte filen.
 * Filen är den äldre kopian, utom en post som just sparats med nyare at.
 *
 * @param array<string, mixed> $sql
 * @param array<string, mixed> $json
 * @return array<string, mixed>
 */
function jeab_code_people_prefer_newer(array $sql, array $json): array
{
    foreach ($json as $key => $row) {
        if (!is_string($key) || !is_array($row)) {
            continue;
        }
        $have = $sql[$key] ?? null;
        if (!is_array($have)) {
            $sql[$key] = $row;
            continue;
        }
        $sqlAt = jeab_code_people_entry_stamp($have);
        $jsonAt = jeab_code_people_entry_stamp($row);
        if ($jsonAt !== '' && ($sqlAt === '' || $jsonAt > $sqlAt)) {
            $sql[$key] = $row;
        }
    }

    return $sql;
}

/** @param array<string, mixed> $row */
function jeab_code_people_entry_stamp(array $row): string
{
    $norm = function_exists('jeab_migrate_datetime') ? jeab_migrate_datetime($row['at'] ?? null) : null;
    if (!is_string($norm) || $norm === '' || $norm === '1970-01-01 00:00:00') {
        return '';
    }

    return $norm;
}

/**
 * Inloggning och Påminnelser läser den här, utan CREATE TABLE och utan SHOW/PRAGMA.
 * SQL-raden biz_code_people är facit när den finns. Filen fyller bara saknade
 * fingeravtryck, eller en post vars at är nyare (ett huvud som just sparats i JSON-läge).
 *
 * @return array<string, mixed>
 */
function jeab_code_people_read_noddl(): array
{
    // Kärnsidor och hook.php laddar inte biz_doc_store.php. Utan den syntes bara JSON-filen,
    // och live sparar code_people i json_docs (biz_code_people). Filen har bara funktioner, ingen DDL.
    if (!function_exists('jeab_biz_doc_store_pdo')) {
        require_once __DIR__ . '/biz_doc_store.php';
    }
    $json = jeab_code_people_json_read();
    $forcedSql = function_exists('jeab_data_type_forced') && jeab_data_type_forced('sql', 'biz_docs');
    if (!function_exists('jeab_biz_doc_store_pdo') || !function_exists('jeab_sql_doc_get') || !function_exists('jeab_biz_doc_sql_key')) {
        return $forcedSql ? [] : $json;
    }
    $pdo = jeab_biz_doc_store_pdo();
    if (!$pdo instanceof PDO) {
        return $forcedSql ? [] : $json;
    }
    try {
        $sql = jeab_sql_doc_get($pdo, jeab_biz_doc_sql_key('code_people'));
    } catch (Throwable) {
        return $forcedSql ? [] : $json;
    }
    // Ingen SQL-rad ännu: filen har huvudena. En tom sparad rad i sql-läge är facit.
    if ($sql === null) {
        return $json;
    }
    if ($sql === []) {
        return $forcedSql ? [] : $json;
    }

    return jeab_code_people_prefer_newer($sql, $json);
}

/**
 * Spara person för en kods fingeravtryck (16 hex, ingen hemlighet). Skriver bara vid ändring.
 * $head är högst de 9 första tecknen. null behåller ett redan sparat huvud.
 */
function jeab_code_people_save(string $fp, string $personId, string $via, ?string $head = null): void
{
    $fp = strtolower(trim($fp));
    if ($fp === '' || !ctype_xdigit($fp) || strlen($fp) !== 16) {
        return;
    }
    if ($personId === '' && !in_array($via, ['conflict', 'ambiguous'], true)) {
        return;
    }
    $all = jeab_code_people_read();
    $cur = $all['fp:' . $fp] ?? null;
    $keptHead = is_array($cur) ? jeab_code_head((string) ($cur['head'] ?? '')) : '';
    $nextHead = $head === null ? $keptHead : jeab_code_head($head);
    if (is_array($cur) && ($cur['person'] ?? '') === $personId && ($cur['via'] ?? '') === $via
        && (string) ($cur['head'] ?? '') === $nextHead) {
        return;
    }
    // Unikt förnamn (via=code) får inte skrivas över av en svagare stämpel.
    // En gammal gissning på delat förnamn får däremot vika för prefix eller e-post.
    if (!in_array($via, ['conflict', 'ambiguous', 'prefix', 'manual'], true) && is_array($cur) && ($cur['via'] ?? '') === 'code' && $via !== 'code'
        && !jeab_code_people_stored_is_shared_guess($cur)) {
        return;
    }
    if ($via === 'email' && is_array($cur) && ($cur['via'] ?? '') === 'prefix') {
        return;
    }
    if ($via === 'manual' && is_array($cur)) {
        $curVia = (string) ($cur['via'] ?? '');
        $curPerson = trim((string) ($cur['person'] ?? ''));
        $curHead = jeab_code_head((string) ($cur['head'] ?? ''));
        // Ett sparat huvud har redan en person. Utan huvud får en osäker Sam pekas om.
        // En prefixrad vinner bara om huvudet i dagens config pekar ut exakt den personen.
        // Tvetydig eller inaktuell prefixrad får skrivas över, huvudet sparas om i nytt format.
        $prefixStale = false;
        if ($curVia === 'prefix' && $curPerson !== '' && $curHead !== '') {
            $hits = jeab_people_by_code_prefix($curHead);
            $prefixStale = count($hits) !== 1 || strcasecmp((string) ($hits[0]['id'] ?? ''), $curPerson) !== 0;
        }
        if ($curPerson !== '' && $curHead !== '' && !$prefixStale && !in_array($curVia, ['manual', 'ambiguous', 'conflict'], true)) {
            return;
        }
    }
    $row = ['person' => $personId, 'via' => $via, 'at' => date('Y-m-d H:i:s')];
    if ($nextHead !== '') {
        $row['head'] = $nextHead;
    }
    $all['fp:' . $fp] = $row;
    if (!function_exists('jeab_biz_doc_save')) {
        require_once __DIR__ . '/biz_doc_store.php';
    }
    if (function_exists('jeab_biz_doc_use_sql') && jeab_biz_doc_use_sql('code_people')) {
        jeab_biz_doc_save('code_people', $all);
        return;
    }
    $path = jeab_code_people_path();
    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0775, true);
    }
    @file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** via=code som pekar på ett delat förnamn är en gammal gissning, inte ett unikt prefix. */
function jeab_code_people_stored_is_shared_guess(array $cur): bool
{
    if (($cur['via'] ?? '') !== 'code') {
        return false;
    }
    $id = trim((string) ($cur['person'] ?? ''));
    if ($id === '') {
        return true;
    }
    foreach (jeab_people() as $row) {
        if (!is_array($row) || strcasecmp((string) ($row['id'] ?? ''), $id) !== 0) {
            continue;
        }

        return jeab_person_first_name_shared($row);
    }

    return false;
}

/**
 * Sparad kod och radens e-post pekar åt olika håll, eller raden är märkt konflikt.
 * Då är inloggningen ingen person.
 */
function jeab_auth_code_people_conflict(string $fp, ?array $emailPerson): bool
{
    $fp = strtolower(trim($fp));
    if ($fp === '' || !ctype_xdigit($fp) || strlen($fp) !== 16) {
        return false;
    }
    $stored = jeab_code_people_read(false)['fp:' . $fp] ?? null;
    if (!is_array($stored)) {
        return false;
    }
    if (($stored['via'] ?? '') === 'conflict') {
        return true;
    }
    if (($stored['via'] ?? '') !== 'prefix' || !is_array($emailPerson)) {
        return false;
    }
    $storedId = trim((string) ($stored['person'] ?? ''));
    // En inaktuell prefixrad är ingen konflikt: den gäller inte längre.
    if ($storedId !== '' && jeab_code_people_row_person($stored) === null) {
        return false;
    }

    return $storedId !== '' && strcasecmp($storedId, (string) ($emailPerson['id'] ?? '')) !== 0;
}

/** Ta bort en sparad person för ett fingeravtryck. Används när förnamnet inte räcker. */
function jeab_code_people_forget(string $fp): void
{
    $fp = strtolower(trim($fp));
    if ($fp === '' || !ctype_xdigit($fp) || strlen($fp) !== 16) {
        return;
    }
    $all = jeab_code_people_read();
    if (!isset($all['fp:' . $fp])) {
        return;
    }
    unset($all['fp:' . $fp]);
    if (!function_exists('jeab_biz_doc_save')) {
        require_once __DIR__ . '/biz_doc_store.php';
    }
    if (function_exists('jeab_biz_doc_use_sql') && jeab_biz_doc_use_sql('code_people')) {
        jeab_biz_doc_save('code_people', $all);
        return;
    }
    $path = jeab_code_people_path();
    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0775, true);
    }
    @file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Person-id, visningsnamn eller användarnamn. saml är Sam L.
 * samw är Sam W. Förnamnet Sam ensamt träffar ingen.
 */
function jeab_person_by_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $compact = preg_replace('/\s+/u', '', $token) ?? $token;
    $hits = [];
    foreach (jeab_people() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string) ($row['id'] ?? '');
        $name = trim((string) ($row['name'] ?? ''));
        $nameCompact = preg_replace('/\s+/u', '', $name) ?? $name;
        $user = jeab_person_username($row);
        $match = strcasecmp($id, $token) === 0
            || ($name !== '' && strcasecmp($name, $token) === 0)
            || ($nameCompact !== '' && strcasecmp($nameCompact, $compact) === 0)
            || ($user !== '' && strcasecmp($user, $token) === 0);
        if ($match && $id !== '') {
            $hits[$id] = $row;
        }
    }

    return count($hits) === 1 ? array_values($hits)[0] : null;
}

function jeab_person_by_code_fp(?string $fp): ?array
{
    $fp = strtolower(trim((string) $fp));
    if ($fp === '') {
        return null;
    }
    $stored = jeab_code_people_read(false)['fp:' . $fp] ?? null;

    return is_array($stored) ? jeab_code_people_row_person($stored) : null;
}

/**
 * Personen en sparad code_people-rad pekar på, bara om den fortfarande stämmer.
 * via=prefix och via=code med huvud: huvudet måste i dagens people-config ge exakt
 * en träff med samma id (som jeab_auth_stored_login_person). Prefix utan huvud,
 * conflict och ambiguous ger null. manual och email litar på id.
 *
 * @param array<string, mixed> $stored
 */
function jeab_code_people_row_person(array $stored): ?array
{
    $id = trim((string) ($stored['person'] ?? ''));
    $via = (string) ($stored['via'] ?? '');
    if ($id === '' || in_array($via, ['conflict', 'ambiguous'], true)) {
        return null;
    }
    $head = jeab_code_head((string) ($stored['head'] ?? ''));
    if ($via === 'prefix' || ($via === 'code' && $head !== '')) {
        if ($head === '') {
            return null;
        }
        $hits = jeab_people_by_code_prefix($head);

        return count($hits) === 1 && strcasecmp((string) ($hits[0]['id'] ?? ''), $id) === 0 ? $hits[0] : null;
    }
    foreach (jeab_people() as $row) {
        if (is_array($row) && strcasecmp((string) ($row['id'] ?? ''), $id) === 0) {
            return $row;
        }
    }

    return null;
}

/**
 * Vid inlösen: personen från kodens förnamn och radens e-post sparas för fingeravtrycket.
 * $email kan utelämnas. Då läses etiketten på hashen. Delad adress och begränsad
 * inloggning sparas inte.
 */
function jeab_code_people_remember(string $code, string $hash, ?string $email = null): ?array
{
    if ($hash !== '' && function_exists('jeab_restricted_by_hash') && jeab_restricted_by_hash($hash) !== null) {
        return null;
    }
    if (($email === null || trim($email) === '') && $hash !== '' && function_exists('jeab_auth_entries')) {
        foreach (jeab_auth_entries() as $entry) {
            $entryHash = (string) ($entry['hash'] ?? '');
            if ($entryHash !== '' && hash_equals($entryHash, $hash)) {
                $email = (string) ($entry['email'] ?? '');
                break;
            }
        }
    }
    $head = jeab_code_head($code);
    $person = jeab_person_from_login($code, $email);
    if ($hash !== '' && function_exists('jeab_auth_hash_fingerprint')) {
        $fp = jeab_auth_hash_fingerprint($hash);
        $reason = jeab_who_identity_reason();
        $hits = jeab_people_by_code_prefix($code);
        if ($reason === 'conflict') {
            // Unikt förnamn och e-post pekar åt olika håll. En gammal gissning får inte ligga kvar.
            jeab_code_people_save($fp, '', 'conflict', $head);
        } elseif (count($hits) > 1) {
            // Sam… utan L/W. Huvudet sparas så listan kan visa det. Personen sparas inte.
            jeab_code_people_save($fp, '', 'ambiguous', $head);
            jeab_who_identity_reason('ambiguous-no-email');
            $person = null;
        } elseif (!is_array($person)) {
            if ($head !== '') {
                jeab_code_people_save($fp, '', 'ambiguous', $head);
            }
        } elseif (jeab_person_code_prefix_disambiguated($code, $person)) {
            jeab_code_people_save($fp, (string) $person['id'], 'prefix', $head);
        } elseif (count($hits) === 1) {
            jeab_code_people_save($fp, (string) $person['id'], 'code', $head);
        } else {
            jeab_code_people_forget($fp);
        }
    }

    return $person;
}

/**
 * E-posten på lösenordsraden för den här kakan. null = ingen rad (då gäller sessionens
 * e-post). Tom sträng = raden finns men saknar e-post, och en gammal session får inte
 * fylla i någon annans adress.
 */
function jeab_auth_row_email(): ?string
{
    $fp = function_exists('jeab_auth_user_fingerprint') ? jeab_auth_user_fingerprint() : null;
    if (!is_string($fp) || $fp === '' || !function_exists('jeab_auth_entry_by_fingerprint')) {
        return null;
    }
    $entry = jeab_auth_entry_by_fingerprint($fp);
    if (!is_array($entry)) {
        return null;
    }

    return jeab_normalize_person_email((string) ($entry['email'] ?? '')) ?? '';
}

/** E-posten som identiteten får använda. Raden slår sessionen. Staden läses inte. */
function jeab_auth_identity_email(): ?string
{
    $row = jeab_auth_row_email();
    if ($row !== null) {
        return $row === '' ? null : $row;
    }

    return function_exists('jeab_auth_user_email') ? jeab_auth_user_email() : null;
}

/**
 * Sparat huvud (9 tecken), manuell pekning, konflikt eller tvetydig kod.
 * none = inget huvud som slår e-posten. Läser utan CREATE TABLE.
 *
 * @return array{status:string,person:?array}
 */
function jeab_auth_stored_login_person(): array
{
    $empty = ['status' => 'none', 'person' => null];
    $fp = function_exists('jeab_auth_user_fingerprint') ? jeab_auth_user_fingerprint() : null;
    $fpKey = strtolower(trim((string) $fp));
    if ($fpKey === '' || !ctype_xdigit($fpKey) || strlen($fpKey) !== 16) {
        return $empty;
    }
    $stored = jeab_code_people_read(false)['fp:' . $fpKey] ?? null;
    if (!is_array($stored)) {
        return $empty;
    }
    $via = (string) ($stored['via'] ?? '');
    $head = jeab_code_head((string) ($stored['head'] ?? ''));
    $id = trim((string) ($stored['person'] ?? ''));
    if ($via === 'manual' && $id !== '') {
        foreach (jeab_people() as $row) {
            if (is_array($row) && strcasecmp((string) ($row['id'] ?? ''), $id) === 0) {
                return ['status' => 'manual', 'person' => $row];
            }
        }
    }
    if ($via === 'conflict') {
        return ['status' => 'conflict', 'person' => null];
    }
    // via=prefix gäller bara om huvudet fortfarande pekar ut exakt den sparade personen
    // i people-config. Namnbyte eller en ny person med samma prefix ger gamla logiken,
    // och väljaren vid tvetydighet.
    if ($via === 'prefix' && $id !== '' && $head !== '') {
        $hits = jeab_people_by_code_prefix($head);
        if (count($hits) === 1 && strcasecmp((string) ($hits[0]['id'] ?? ''), $id) === 0) {
            return ['status' => 'person', 'person' => $hits[0]];
        }
        // Huvudet pekar nu på någon annan eller på flera: inte gissa, visa väljaren.
        if ($hits !== []) {
            return ['status' => 'ambiguous', 'person' => null];
        }
    }
    if ($via === 'ambiguous') {
        return ['status' => 'ambiguous', 'person' => null];
    }
    if ($head !== '') {
        $hits = jeab_people_by_code_prefix($head);
        if (count($hits) > 1) {
            return ['status' => 'ambiguous', 'person' => null];
        }
        if (count($hits) === 1) {
            $hit = $hits[0];
            if (jeab_person_code_prefix_disambiguated($head, $hit) || !jeab_person_first_name_shared($hit)) {
                return ['status' => 'person', 'person' => $hit];
            }
        }
    }

    return $empty;
}

/** Sant när $who är den andra Sam än den inloggade. Delad kod och övrig personal rörs inte. */
function jeab_who_is_other_sam(string $who): bool
{
    $who = trim($who);
    if ($who === '' || jeab_reminder_who_is_all_token($who)) {
        return false;
    }
    $me = function_exists('jeab_who_me_person') ? jeab_who_me_person() : null;
    if (!is_array($me)) {
        return false;
    }
    $meId = (string) ($me['id'] ?? '');
    $other = $meId === 'SamL' ? 'SamW' : ($meId === 'SamW' ? 'SamL' : '');
    if ($other === '') {
        return false;
    }
    if (strcasecmp($who, $other) === 0) {
        return true;
    }
    $label = $other === 'SamW' ? 'Sam W' : 'Sam L';
    if (strcasecmp($who, $label) === 0) {
        return true;
    }
    $hit = jeab_person_by_token($who);

    return is_array($hit) && strcasecmp((string) ($hit['id'] ?? ''), $other) === 0;
}

/** who=all utan att dra in påminnelsemodulen. */
function jeab_reminder_who_is_all_token(string $who): bool
{
    return strcasecmp(trim($who), 'all') === 0;
}

/** Varifrån personen kom: email, code (unikt förnamn eller kodhuvud), manual, fp-config, eller tomt. */
function jeab_auth_user_person_source(): string
{
    jeab_auth_user_person();
    $reason = (string) ($GLOBALS['_JEAB_WHO_REASON'] ?? '');
    if ($reason === 'email') {
        return 'email';
    }
    if ($reason === 'code') {
        return 'code';
    }
    if ($reason === 'manual') {
        return 'manual';
    }
    if ($reason === 'fp-config') {
        return 'fp-config';
    }

    return '';
}

/**
 * Inloggad kaka: säker person, eller osäker (ingen person).
 * Osäker inloggning tömmer $_SESSION['jeab_code_person'].
 * $configFingerprint är bara för listfiltret. Admin använder det inte.
 * $persist false hoppar över sparning, så en anslutningsetikett inte skapar tabeller.
 *
 * @return array{certain:bool,person:?array,reason:string}
 */
function jeab_login_identity(bool $configFingerprint = true, bool $persist = true): array
{
    $forced = $GLOBALS['_JEAB_LOGIN_IDENTITY_FORCE'] ?? null;
    if (is_array($forced) && array_key_exists('certain', $forced)) {
        return [
            'certain' => !empty($forced['certain']),
            'person' => is_array($forced['person'] ?? null) ? $forced['person'] : null,
            'reason' => (string) ($forced['reason'] ?? 'sim'),
        ];
    }
    static $depth = 0;
    if ($depth > 0) {
        return ['certain' => false, 'person' => null, 'reason' => 'none'];
    }
    $fp = function_exists('jeab_auth_user_fingerprint') ? (string) jeab_auth_user_fingerprint() : '';
    $email = function_exists('jeab_auth_identity_email') ? (string) jeab_auth_identity_email() : '';
    $key = ($configFingerprint ? '1' : '0') . ($persist ? '1' : '0') . '|' . $fp . '|' . $email;
    $memo = $GLOBALS['_JEAB_LOGIN_IDENTITY'] ?? null;
    if (is_array($memo) && isset($memo[$key]) && is_array($memo[$key])) {
        $cached = $memo[$key];
        jeab_who_identity_reason((string) ($cached['reason'] ?? 'none'));

        return $cached;
    }
    $depth++;
    try {
        $result = jeab_login_identity_resolve($configFingerprint, $persist);
        if (!empty($result['certain']) && is_array($result['person'] ?? null) && function_exists('jeab_auth_session_remember_resolved')) {
            jeab_auth_session_remember_resolved($result);
        }
        $reason = (string) ($result['reason'] ?? 'none');
        if (empty($result['certain']) && in_array($reason, ['ambiguous-no-head', 'ambiguous-no-email', 'ambiguous-stored', 'conflict', 'shared'], true)) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                unset($_SESSION['jeab_code_person']);
                if ($reason === 'shared') {
                    unset($_SESSION['jeab_user'], $_SESSION['jeab_user_via']);
                }
            }
        }
        jeab_who_identity_reason($reason);
        if (!isset($GLOBALS['_JEAB_LOGIN_IDENTITY']) || !is_array($GLOBALS['_JEAB_LOGIN_IDENTITY'])) {
            $GLOBALS['_JEAB_LOGIN_IDENTITY'] = [];
        }
        $GLOBALS['_JEAB_LOGIN_IDENTITY'][$key] = $result;

        return $result;
    } finally {
        $depth--;
    }
}

/**
 * @return array{certain:bool,person:?array,reason:string}
 */
function jeab_login_identity_resolve(bool $configFingerprint, bool $persist = true): array
{
    $pack = static function (bool $certain, ?array $person, string $reason): array {
        return ['certain' => $certain, 'person' => $person, 'reason' => $reason];
    };
    if (function_exists('jeab_demo_mode') && jeab_demo_mode()
        && function_exists('jeab_auth_ok') && jeab_auth_ok()
        && function_exists('jeab_demo_person')) {
        $demoPerson = jeab_demo_person();
        if (is_array($demoPerson) && trim((string) ($demoPerson['id'] ?? '')) !== '') {
            return $pack(true, $demoPerson, 'email');
        }
    }
    // Delad kod är ingen person, även när sessionen fortfarande har någon annan.
    $email = jeab_auth_identity_email();
    if (jeab_is_shared_login_email($email)) {
        return $pack(false, null, 'shared');
    }
    // Sessionen kan redan ha användaren, eller ett huvud (9 tecken) som pekar ut hen.
    // Det fyller i sessioner som skapades innan prefixet sparades.
    if (function_exists('jeab_auth_session_user_pack')) {
        $fromSession = jeab_auth_session_user_pack();
        if (is_array($fromSession)) {
            return $fromSession;
        }
    }
    $fp = function_exists('jeab_auth_user_fingerprint') ? jeab_auth_user_fingerprint() : null;
    $fpKey = strtolower(trim((string) $fp));
    $fpOk = $fpKey !== '' && ctype_xdigit($fpKey) && strlen($fpKey) === 16;
    $storedLogin = jeab_auth_stored_login_person();
    if ($storedLogin['status'] === 'ambiguous') {
        return $pack(false, null, 'ambiguous-stored');
    }
    if ($storedLogin['status'] === 'conflict') {
        return $pack(false, null, 'conflict');
    }
    if (in_array($storedLogin['status'], ['person', 'manual'], true) && is_array($storedLogin['person'])) {
        return $pack(true, $storedLogin['person'], $storedLogin['status'] === 'manual' ? 'manual' : 'code');
    }
    $direct = jeab_person_by_email($email);
    if ($fpOk && jeab_auth_code_people_conflict($fpKey, is_array($direct) ? $direct : null)) {
        return $pack(false, null, 'conflict');
    }
    $id = jeab_certain_identity($email, '', '');
    if (($id['reason'] ?? '') === 'ambiguous-no-head') {
        return $id;
    }
    if (!empty($id['certain']) && is_array($id['person']) && is_array($direct)) {
        if ($persist && $fpOk) {
            jeab_code_people_save($fpKey, (string) $direct['id'], 'email');
        }

        return $id;
    }
    if (!empty($id['certain']) && is_array($id['person'])) {
        return $id;
    }
    if ($fpOk) {
        $stored = jeab_code_people_read(false)['fp:' . $fpKey] ?? null;
        $via = is_array($stored) ? (string) ($stored['via'] ?? '') : '';
        $byCode = jeab_person_by_code_fp($fpKey);
        if ($byCode !== null && ($via === 'prefix' || $via === 'code')) {
            if (jeab_person_first_name_shared($byCode)) {
                return $pack(false, null, 'ambiguous-no-head');
            }

            return $pack(true, $byCode, 'code');
        }
    }
    if ($configFingerprint && $fpOk) {
        $configured = jeab_person_by_login_fingerprint($fpKey);
        if (is_array($configured)) {
            return $pack(true, $configured, 'fp-config');
        }
    }

    return $pack(false, null, jeab_auth_row_email() === '' ? 'no-email' : 'none');
}

/**
 * Hook-parameter person (id) eller email simulerar inloggningen för diag.mina och inkorg-proben.
 * E-posten sparas inte i svaret. Utan träff rörs inte den riktiga identiteten.
 *
 * @param array<string, mixed> $input
 * @return array{certain:bool,person:?array,reason:string}
 */
function jeab_login_identity_simulate(array $input): array
{
    $personId = trim((string) ($input['person'] ?? $input['person_id'] ?? ''));
    $email = jeab_normalize_person_email((string) ($input['email'] ?? ''));
    $person = null;
    $reason = 'none';
    if ($personId !== '') {
        foreach (jeab_people() as $row) {
            if (strcasecmp((string) ($row['id'] ?? ''), $personId) === 0) {
                $person = $row;
                $reason = 'sim-person';
                break;
            }
        }
    }
    if (!is_array($person) && $email !== null) {
        $by = jeab_person_by_email($email);
        if (!is_array($by)) {
            $by = jeab_person_by_login($email);
        }
        if (is_array($by)) {
            $person = $by;
            $reason = 'sim-email';
        }
    }
    if (!is_array($person)) {
        return ['certain' => false, 'person' => null, 'reason' => $personId !== '' || $email !== null ? 'sim-miss' : 'none'];
    }
    $pack = ['certain' => true, 'person' => $person, 'reason' => $reason];
    $GLOBALS['_JEAB_LOGIN_IDENTITY_FORCE'] = $pack;
    unset($GLOBALS['_JEAB_LOGIN_IDENTITY']);

    return $pack;
}

function jeab_auth_user_person(): ?array
{
    $id = jeab_login_identity(true);

    return is_array($id['person'] ?? null) ? $id['person'] : null;
}

/**
 * Personen bakom en personlig inloggning: egen e-post i registret, ett unikt
 * kodprefix (via=prefix, saml/samw) eller ett unikt förnamn (via=code).
 * Delad kampanjkod, konflikt mot e-posten, en gammal förnamnsgissning, config-alias
 * och ett eget val i listan räknas inte.
 */
function jeab_personal_login_person(): ?array
{
    $id = jeab_login_identity(false);
    // Ett val i «Välj användare» styr listan. Det gör inte inloggningen till admin.
    if ((string) ($id['reason'] ?? '') === 'choice') {
        return null;
    }

    return !empty($id['certain']) && is_array($id['person'] ?? null) ? $id['person'] : null;
}

/**
 * Person-id som är admin när inloggningen är just den personen.
 * Identiteten är lösenordsradens e-post, eller en personlig kod med unikt prefix
 * (SamL…). Ett ensamt Sam…, en gammal gissning och stadsväljaren räknas inte.
 * Lägg till ett id här för att ge samma admin.
 *
 * @return list<string>
 */
function jeab_admin_person_ids(): array
{
    return ['SamL'];
}

/** Admin är personerna i jeab_admin_person_ids(), och den delade koden agenter@flowstack.example.test. */
function jeab_is_admin(): bool
{
    if (function_exists('jeab_demo_mode') && jeab_demo_mode()) {
        if (function_exists('jeab_demo_session_is_demo_person') && jeab_demo_session_is_demo_person()) {
            return true;
        }
        if (function_exists('jeab_auth_ok') && jeab_auth_ok()) {
            return true;
        }
    }
    $email = function_exists('jeab_auth_user_email') ? jeab_auth_user_email() : null;
    if (jeab_is_shared_login_email($email)) {
        return true;
    }
    $person = jeab_personal_login_person();
    if (!is_array($person)) {
        return false;
    }
    $id = (string) ($person['id'] ?? '');
    foreach (jeab_admin_person_ids() as $adminId) {
        if ($id !== '' && strcasecmp($id, $adminId) === 0) {
            return true;
        }
    }

    return false;
}

/** Zapier och utgående webhook. Bara admin får spara den. */
function jeab_may_edit_webhook(): bool
{
    return jeab_is_admin();
}

function jeab_person_aliases(array $row): array
{
    $out = [];
    $email = trim((string) ($row['email'] ?? ''));
    $local = '';
    if ($email !== '' && str_contains($email, '@')) {
        $local = trim(explode('@', $email, 2)[0]);
    }
    foreach ([$row['name'] ?? '', $row['id'] ?? '', $email, $local] as $value) {
        $value = trim((string) $value);
        if ($value !== '' && !in_array($value, $out, true)) {
            $out[] = $value;
        }
    }
    $name = trim((string) ($row['name'] ?? ''));
    $compact = preg_replace('/\s+/', '', $name);
    if (is_string($compact) && $compact !== '' && !in_array($compact, $out, true)) {
        $out[] = $compact;
    }
    if (str_contains($name, ' ')) {
        $first = trim(explode(' ', $name, 2)[0]);
        if ($first !== '' && !in_array($first, $out, true)) {
            $out[] = $first;
        }
    }
    $extra = [];
    if (isset($row['alias'])) {
        $extra[] = $row['alias'];
    }
    if (isset($row['aliases'])) {
        $extra[] = $row['aliases'];
    }
    foreach ($extra as $value) {
        $parts = is_array($value) ? $value : preg_split('/\s*,\s*/', (string) $value);
        if (!is_array($parts)) {
            continue;
        }
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '' && !in_array($part, $out, true)) {
                $out[] = $part;
            }
        }
    }

    return $out;
}

function jeab_person_alias_matches(array $row, string $given): bool
{
    $given = trim($given);
    if ($given === '') {
        return false;
    }
    foreach (jeab_person_aliases($row) as $alias) {
        if (strcasecmp($alias, $given) === 0) {
            return true;
        }
    }
    return false;
}

function jeab_resolve_person_name(string $given, ?string $company = null): ?string
{
    $given = trim($given);
    if ($given === '') {
        return null;
    }
    $company = jeab_normalize_company($company);
    $fold = function_exists('mb_strtolower') ? mb_strtolower($given, 'UTF-8') : strtolower($given);
    $key = ($company ?? '') . "\0" . $fold;
    $cache = $GLOBALS['_JEAB_PERSON_NAME_CACHE'] ?? null;
    if (is_array($cache) && array_key_exists($key, $cache)) {
        $hit = $cache[$key];

        return is_string($hit) ? $hit : null;
    }
    if ($company !== null) {
        $found = jeab_person_by_name($given, $company)['name'] ?? null;
        $result = is_string($found) && $found !== '' ? $found : null;
    } else {
        $hits = [];
        foreach (jeab_people() as $row) {
            if (jeab_person_alias_matches($row, $given)) {
                $hits[$row['email']] = $row['name'];
            }
        }
        $only = count($hits) === 1 ? array_values($hits)[0] : null;
        $result = is_string($only) && $only !== '' ? $only : null;
    }
    if (!is_array($cache)) {
        $cache = [];
    }
    $cache[$key] = $result;
    $GLOBALS['_JEAB_PERSON_NAME_CACHE'] = $cache;

    return $result;
}

function jeab_person_by_name(string $name, ?string $company = null): ?array
{
    $name = trim($name);
    $company = jeab_normalize_company($company) ?? jeab_selected_company();
    if ($name === '') {
        return null;
    }
    $fold = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $key = $company . "\0" . $fold;
    $cache = $GLOBALS['_JEAB_PERSON_BY_NAME'] ?? null;
    if (is_array($cache) && array_key_exists($key, $cache)) {
        $hit = $cache[$key];

        return is_array($hit) ? $hit : null;
    }
    $found = null;
    foreach (jeab_company_people($company) as $row) {
        if (jeab_person_alias_matches($row, $name)) {
            $found = $row;
            break;
        }
    }
    if (!is_array($cache)) {
        $cache = [];
    }
    $cache[$key] = $found;
    $GLOBALS['_JEAB_PERSON_BY_NAME'] = $cache;

    return $found;
}

function jeab_person_job(string $name, ?string $company = null): ?string
{
    return jeab_person_by_name($name, $company)['job'] ?? null;
}

function jeab_infer_company_from_names(array $names): ?string
{
    $votes = [];
    foreach ($names as $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $hits = [];
        foreach (jeab_people() as $row) {
            if (jeab_person_alias_matches($row, $name)) {
                $hits[$row['company']] = true;
            }
        }
        if (count($hits) === 1) {
            $votes[array_key_first($hits)] = true;
        }
    }
    return count($votes) === 1 ? array_key_first($votes) : null;
}

function jeab_job_person(string $job, ?string $company = null): ?string
{
    $job = jeab_normalize_job_key($job);
    if ($job === null) {
        return null;
    }
    foreach (jeab_company_people($company) as $row) {
        if ($row['job'] === $job) {
            return $row['name'];
        }
    }
    return null;
}

function jeab_remember_company(string $company): void
{
    $company = jeab_normalize_company($company);
    if ($company === null) {
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['jeab_company'] = $company;
    }
}

/**
 * Bolag den inloggade personen får läsa och skriva, och som väljaren visar.
 * null = ingen inloggning (cron, CLI, okopplad kod) och då gäller ingen spärr.
 * jeab_is_admin() (Sam L och agenter@flowstack.example.test) får båda kontoren, Järfälla först.
 * Annars kopplad person, sedan jeab_personal_login_person().
 * En person med hemma-kontor får båda kontoren, hemma först, så förvalet stannar där.
 * Inloggad utan person: e-postens domän ger hemma-kontoret och båda kontoren.
 * Domänen väljer inte person och inte admin. Utan känd domän är listan tom.
 *
 * @return list<string>|null
 */
function jeab_actor_companies(): ?array
{
    static $depth = 0;
    if ($depth > 0) {
        return null;
    }
    $depth++;
    try {
        if (jeab_is_admin()) {
            return jeab_staff_both_offices(['jarfalla', 'malmo']);
        }
        if (!function_exists('jeab_inkorg_linked_person') || !function_exists('jeab_inkorg_person_companies')) {
            return null;
        }
        $person = jeab_inkorg_linked_person();
        if (!is_array($person)) {
            $person = jeab_personal_login_person();
        }
        if (is_array($person)) {
            $home = jeab_inkorg_person_companies($person);
            return $home === [] ? [] : jeab_staff_both_offices($home);
        }
        $email = function_exists('jeab_auth_user_email') ? jeab_auth_user_email() : null;
        if (jeab_is_shared_login_email($email)) {
            return jeab_staff_both_offices(jeab_shared_identity_companies());
        }
        $loggedIn = (is_string($email) && $email !== '')
            || (function_exists('jeab_auth_ok') && jeab_auth_ok());
        if ($loggedIn) {
            // Osäker Sam (inget kodhuvud) är ingen person, men Malmö-adressen
            // ska fortfarande öppna Malmö. Samma för Järfälla. Okänd domän får inget.
            $home = function_exists('jeab_company_from_email') ? jeab_company_from_email(is_string($email) ? $email : null) : null;
            if ($home !== null) {
                return jeab_staff_both_offices([$home]);
            }

            return [];
        }

        return null;
    } finally {
        $depth--;
    }
}

/**
 * Båda kontoren, med personens hemma-kontor först.
 *
 * @param list<string> $home
 * @return list<string>
 */
function jeab_staff_both_offices(array $home): array
{
    $all = ['jarfalla', 'malmo'];
    $ordered = [];
    foreach (array_merge($home, $all) as $id) {
        if (!is_string($id) || !in_array($id, $all, true) || in_array($id, $ordered, true)) {
            continue;
        }
        $ordered[] = $id;
    }

    return $ordered;
}

/** Sant när bolaget ingår i den inloggades lista. Utan inloggning släpps båda bolagen igenom. Inloggad utan person och utan känd domän släpper inget. */
function jeab_company_allowed(?string $company): bool
{
    $company = jeab_normalize_company($company);
    if ($company === null) {
        return false;
    }
    $actor = jeab_actor_companies();
    if ($actor === null) {
        return true;
    }

    return in_array($company, $actor, true);
}

/**
 * Kontakt utan sparat bolag följer listan (syns i båda kontoren tills ett bolag satts).
 * Ett satt bolag måste ingå i personens lista.
 */
function jeab_record_company_allowed(mixed $company): bool
{
    $actor = jeab_actor_companies();
    if ($actor === null) {
        return true;
    }
    $company = jeab_normalize_company($company);
    if ($company === null) {
        return $actor !== [];
    }

    return in_array($company, $actor, true);
}

function jeab_selected_company(): string
{
    $forced = function_exists('jeab_app_forced_company') ? jeab_app_forced_company() : null;
    if ($forced !== null) {
        return $forced;
    }
    $fromGet = jeab_normalize_company($_GET['co'] ?? null);
    if ($fromGet && jeab_company_allowed($fromGet)) {
        jeab_remember_company($fromGet);
        return $fromGet;
    }
    // session_write_close lämnar värdet kvar. Kalender-HTML släpper låset innan den ritar.
    $sessionReadable = session_status() === PHP_SESSION_ACTIVE || !empty($GLOBALS['_JEAB_SESSION_RELEASED']);
    if ($sessionReadable && isset($_SESSION['jeab_company'])) {
        $fromSession = jeab_normalize_company($_SESSION['jeab_company']);
        if ($fromSession && jeab_company_allowed($fromSession)) {
            return $fromSession;
        }
    }
    $fromUser = jeab_company_from_email(function_exists('jeab_auth_user_email') ? jeab_auth_user_email() : null);
    if ($fromUser && jeab_company_allowed($fromUser)) {
        jeab_remember_company($fromUser);
        return $fromUser;
    }
    $actor = jeab_actor_companies();
    if (is_array($actor) && $actor !== []) {
        $pick = (string) $actor[0];
        jeab_remember_company($pick);
        return $pick;
    }
    return 'jarfalla';
}

function jeab_booking_company(array $row): string
{
    return jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla';
}

function jeab_filter_bookings_for_company(array $rows, ?string $company = null): array
{
    $company = jeab_normalize_company($company) ?? jeab_selected_company();
    return array_values(array_filter(
        $rows,
        static fn(array $row): bool => jeab_booking_company($row) === $company
    ));
}

function jeab_visible_company_names(?string $company = null): array
{
    $company = jeab_normalize_company($company) ?? jeab_selected_company();
    $all = function_exists('jeab_filter_staff') ? jeab_filter_staff($company) : jeab_company_names($company);
    $me = jeab_auth_user_person();
    if ($me === null) {
        $actor = function_exists('jeab_actor_companies') ? jeab_actor_companies() : null;
        if (is_array($actor) && $actor === []) {
            return [];
        }

        return $all;
    }
    $id = (string) ($me['id'] ?? '');
    if (jeab_is_admin() || strcasecmp($id, 'SamW') === 0) {
        return $all;
    }
    return in_array($me['name'], $all, true) ? [$me['name']] : [];
}

function jeab_default_sheet_assignees(): array
{
    $company = jeab_selected_company();
    $allowed = jeab_company_names($company);
    $identity = jeab_login_identity(false);
    $me = is_array($identity['person'] ?? null) ? $identity['person'] : null;
    if ($me && $me['company'] === $company && in_array($me['name'], $allowed, true)) {
        return [$me['name']];
    }
    $cookie = jeab_parse_assignees($_COOKIE['jeab_me'] ?? '', $company);
    return $cookie;
}

/**
 * Who is allowed on a Malmö job. Calendar, orders and staff assignment
 * all use this list. Sol is Isaq and Abol. Laddbox and batteri are Kim.
 *
 * @return list<string>
 */
function jeab_malmo_job_crew(string $kind): array
{
    $kind = jeab_normalize_job_key($kind) ?? '';
    if (function_exists('jeab_user_responsibility_people_for') && in_array($kind, ['sol', 'laddbox', 'batteri'], true)) {
        $ids = jeab_user_responsibility_people_for($kind, 'skane', 'install');
        if ($ids !== []) {
            return array_map('jeab_user_responsibility_name', $ids);
        }
    }
    if ($kind === 'sol') {
        return ['Isaq', 'Abol'];
    }
    if ($kind === 'laddbox' || $kind === 'batteri') {
        return ['Kim'];
    }
    return [];
}

/** True when the half-open span covers a Wednesday. Midnight end is exclusive. */
function jeab_malmo_span_hits_wednesday(string $start, string $end): bool
{
    try {
        $tz = new DateTimeZone('Europe/Stockholm');
        $from = (new DateTimeImmutable($start, $tz))->setTime(0, 0);
        $to = new DateTimeImmutable($end, $tz);
    } catch (Exception) {
        return false;
    }
    $endDay = $to->setTime(0, 0);
    if ($to->format('H:i:s') !== '00:00:00') {
        $endDay = $endDay->modify('+1 day');
    }
    if ($endDay <= $from) {
        $endDay = $from->modify('+1 day');
    }
    for ($day = $from; $day < $endDay; $day = $day->modify('+1 day')) {
        if ((int) $day->format('N') === 3) {
            return true;
        }
    }
    return false;
}
