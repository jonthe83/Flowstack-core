<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/contacts_copies.php';

/**
 * contacts.drop_customer_fields. dry=1 är standard. Cody kör den efter deploy.
 *
 * Tar bort kundkopiorna i reminders (customer_name, email, phone, address, ort, postal),
 * bookings (customer_name, email, phone, address) och inkorg_list (customer_email).
 * Kontakten via contact_id är källan. solkampanj_recipients är en egen lista utanför
 * kontakter och droppas inte. tables=solkampanj_recipients är okänd: 400 och
 * «Okänd tabell.» innan någon plan eller DROP.
 * tables (kommaseparerad sträng eller array) väljer vilka; tomt eller saknas = alla tre. Okänt namn ger 400 innan något läses.
 * conflict: saknas, tom eller block ger 409 som idag. contact_wins släpper rena fältskillnader mot ett ifyllt värde i kontakten (kontaktens värde gäller, kolumnen droppas ändå, kontakten skrivs inte över). Annat värde ger 400 innan något läses.
 * Historiktrådar i inkorg_list utan kontakt blockerar inte: e-posten läggs i row_json
 * (om den är tom där, annars konflikt) så tråden visas som förut. En länkad tråd får ha
 * vilken som helst av kontaktens adresser.
 * En gammal markörpost för solkampanj_recipients får ligga kvar i settings. Den räknas
 * inte i «En kolumn finns kvar» och inte i den publika markören. Undo återställer
 * namn, first_name, mejl och gata om en gammal backup innehåller tabellen.
 * Idempotent: en kolumn som redan saknas hoppas över, och inställningen
 * contacts_drop_customer_fields gör att schemat inte lägger tillbaka den.
 *
 * Vägrar dry=0 när kontakter inte är enda källan, när en rad utan contact_id
 * har en kopia (den skulle tappas), när en kopia skiljer sig från kontakten
 * (conflicts: id och fältnamn, inga värden) eller när nyckeln inte går att skapa.
 * En länkad rad vars kontakt saknar värdet fyller kontaktens tomma fält, men först när alla
 * tabeller är låsta, kontrollerade och klara (ett 409/500 lämnar inga fyllningar). Planen
 * sparas krypterad (<batch>.pending) och loggen (<batch>.fills) har föregående värde och ett HMAC.
 * Ett befintligt värde skrivs aldrig över och kontakter slås aldrig ihop. Raden och kontakten
 * ska ha samma bolag, och en kontakt utan ägare får ingen ny ägare: båda ger 409.
 *
 * dry=0 skriver backupen först: data/.private/contacts-drop-customer-fields/<batch>.json, 0600.
 * Värdena krypteras med en slumpad nyckel per körning (AES-256-GCM). Den nyckeln sparas bara
 * inslagen med RSA-OAEP mot den publika nyckeln config/contacts-drop-backup.pub (ej hemlig),
 * tillsammans med publika nyckelns sha256. Servern har aldrig den privata nyckeln och skapar
 * ingen nyckel. Saknas publik nyckel: 409 backup_key_missing. Nyckelparet tas fram på boxen
 * med scripts/contacts_drop_keygen.php. Backupen har också kolumnernas exakta typer.
 * Varje tabell låses, läses om och jämförs med planen före DROP; ändrades något stoppar körningen.
 * Markeringen sätts först när alla tabeller är klara. En ny körning gör klart det som är kvar.
 * Svaret har antal, kolumnnamn, id, backupens sökväg och sha256. Inga värden.
 *
 * Varje borttagen kolumn markeras i <batch>.done (MySQL direkt efter sin DROP).
 *
 * contacts.drop_customer_fields_undo tar batch_id, är torr som standard, kontrollerar
 * backupens sha256 (<batch>.sha256, och sha256 i anropet om det finns), lägger tillbaka
 * markerade kolumner med de sparade typerna, fyller bara fält som är tomma och tömmer
 * en fyllning på kontakten bara när värdet är oförändrat.
 * Går ett värde inte att dekryptera stoppar undo innan något skrivs.
 *
 * Undo (och att göra klart en gammal fyllningsplan) kräver den privata nyckeln i en tillfällig
 * fil data/.private/contacts-drop-undo.key (0600). Filen raderas efter anropet oavsett utfall.
 * Den privata nyckeln ska matcha fingeravtrycket i backupen. Svaret visar bara publika nyckelns
 * sha256. Äldre backuper (seal v2, symmetrisk nyckel i .key-filen) läses fortfarande; nya
 * skapas inte och .key-filen skapas aldrig.
 */

final class JeabDropHash
{
    private HashContext $ctx;

    public function __construct()
    {
        $this->ctx = hash_init('sha256');
    }

    public function update(string $data): void
    {
        hash_update($this->ctx, $data);
    }

    public function final(): string
    {
        return hash_final($this->ctx);
    }
}

/** @return list<string> */
function jeab_contacts_drop_tables(): array
{
    return ['reminders', 'bookings', 'inkorg_list'];
}

/**
 * Kolumner i en gammal backup av solkampanj_recipients. Tabellen droppas inte längre,
 * men undo lägger tillbaka dem om backupen och .done har dem.
 *
 * @return list<string>
 */
function jeab_contacts_drop_legacy_solkampanj_columns(): array
{
    return ['namn', 'first_name', 'mejl', 'gata'];
}

/**
 * tables i anropet: kommaseparerad sträng eller array. Mellanslag trimmas, tomma delar
 * hoppas över, dubbletter tas bort. Tomt eller saknas = alla tre. Valda i
 * jeab_contacts_drop_tables()-ordning. Okända namn, inklusive solkampanj_recipients,
 * i den ordning de kom. De ger 400 «Okänd tabell.» innan någon plan.
 *
 * @return array{selected:list<string>,unknown:list<string>}
 */
function jeab_contacts_drop_tables_param(mixed $raw): array
{
    $all = jeab_contacts_drop_tables();
    $parts = [];
    if (is_array($raw)) {
        foreach ($raw as $one) {
            if (is_string($one) || is_int($one)) {
                $parts[] = (string) $one;
            }
        }
    } elseif (is_string($raw)) {
        $parts = explode(',', $raw);
    } else {
        return ['selected' => $all, 'unknown' => []];
    }
    $want = [];
    $unknown = [];
    $seenUnknown = [];
    foreach ($parts as $part) {
        $name = trim($part);
        if ($name === '') {
            continue;
        }
        if (!in_array($name, $all, true)) {
            if (!isset($seenUnknown[$name])) {
                $seenUnknown[$name] = true;
                $unknown[] = $name;
            }
            continue;
        }
        $want[$name] = true;
    }
    if ($want === [] && $unknown === []) {
        return ['selected' => $all, 'unknown' => []];
    }
    $selected = [];
    foreach ($all as $table) {
        if (isset($want[$table])) {
            $selected[] = $table;
        }
    }

    return ['selected' => $selected, 'unknown' => $unknown];
}

/**
 * conflict i anropet. Saknas, null, tom eller block = blockera som idag.
 * contact_wins låter kontaktens ifyllda värde gälla. Allt annat är okänt.
 */
function jeab_contacts_drop_conflict_param(mixed $raw): ?string
{
    if ($raw === null) {
        return 'block';
    }
    if (!is_string($raw)) {
        return null;
    }
    $value = trim($raw);
    if ($value === '' || $value === 'block') {
        return 'block';
    }
    if ($value === 'contact_wins') {
        return 'contact_wins';
    }

    return null;
}

/** Radens id: text i inkorg_list, heltal annars. */
function jeab_contacts_drop_row_id(string $table, mixed $id): int|string
{
    return $table === 'inkorg_list' ? (string) $id : (int) $id;
}

/** e-post i row_json (inkorg_list), normaliserad. */
function jeab_contacts_drop_json_email(string $json): string
{
    $data = json_decode($json, true);

    return is_array($data) ? strtolower(trim((string) ($data['customer_email'] ?? ''))) : '';
}

/** Alla kontaktens e-postadresser, normaliserade (ägare och sekundära). @return array<string, true> */
function jeab_contacts_drop_contact_emails(array $contact): array
{
    $out = [];
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        $e = jeab_contacts_drop_norm('email', (string) (is_array($owner) ? ($owner['email'] ?? '') : ''));
        if ($e !== '') {
            $out[$e] = true;
        }
    }
    if (function_exists('jeab_kontakt_secondary_list')) {
        foreach (jeab_kontakt_secondary_list($contact) as $one) {
            $e = jeab_contacts_drop_norm('email', is_array($one) ? (string) ($one['email'] ?? '') : (string) $one);
            if ($e !== '') {
                $out[$e] = true;
            }
        }
    }

    return $out;
}

function jeab_contacts_drop_dir(): string
{
    $base = jeab_contacts_copies_dir();
    if ($base === '') {
        return '';
    }

    return dirname($base) . '/contacts-drop-customer-fields';
}

/** @return array<string, true> */
function jeab_contacts_drop_live_columns(PDO $pdo, string $table): array
{
    jeab_copy_columns_forget();
    $out = [];
    foreach (jeab_copy_columns($table) as $col) {
        if (jeab_copy_column_present($pdo, $table, $col)) {
            $out[$col] = true;
        }
    }

    return $out;
}

/**
 * @return array{tables:array<string, array<string, mixed>>,rows:array<string, list<array<string, mixed>>>,blocked:list<string>,prints:array<string, string>}
 */
function jeab_contacts_drop_plan(PDO $pdo, ?array $only = null): array
{
    $tables = [];
    $rows = [];
    $blocked = [];
    $prints = [];
    foreach ($only ?? jeab_contacts_drop_tables() as $table) {
        $live = array_keys(jeab_contacts_drop_live_columns($pdo, $table));
        $info = [
            'drop' => $live,
            'already_dropped' => array_values(array_diff(jeab_copy_columns($table), $live)),
            'rows_with_copy' => 0,
            'unlinked_with_copy' => ['count' => 0, 'ids' => []],
        ];
        $rows[$table] = [];
        if ($live !== []) {
            $hash = new JeabDropHash();
            $companySql = jeab_contacts_drop_has_column($pdo, $table, 'company') ? 'company' : "'' AS company";
            $contactSql = jeab_contacts_drop_has_column($pdo, $table, 'contact_id') ? 'contact_id' : "'' AS contact_id";
            $jsonSql = $table === 'inkorg_list' ? ', row_json' : '';
            $where = $table === 'inkorg_list' ? "id <> ''" : 'id > 0';
            $stmt = $pdo->query('SELECT id, ' . $contactSql . ', ' . $companySql . $jsonSql . ', ' . implode(', ', $live) . ' FROM ' . $table . ' WHERE ' . $where . ' ORDER BY id');
            foreach ($stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $values = [];
                foreach ($live as $col) {
                    $v = trim((string) ($row[$col] ?? ''));
                    if ($v !== '') {
                        $values[$col] = $v;
                    }
                }
                if ($values === []) {
                    continue;
                }
                $info['rows_with_copy']++;
                $id = jeab_contacts_drop_row_id($table, $row['id'] ?? 0);
                $contactId = trim((string) ($row['contact_id'] ?? ''));
                $jsonEmail = $table === 'inkorg_list' ? jeab_contacts_drop_json_email((string) ($row['row_json'] ?? '')) : '';
                if ($table === 'inkorg_list' && $contactId === '') {
                    // Historiktråd utan kontakt: blockerar inte. E-posten flyttas till row_json
                    // (om den är tom där) så tråden visas som förut. Ett annat värde där: konflikt.
                    $copy = strtolower(trim((string) ($values['customer_email'] ?? '')));
                    $info['unlinked_kept'] = ($info['unlinked_kept'] ?? 0) + 1;
                    $rows[$table][] = ['id' => $id, 'contact_id' => '', 'company' => (string) ($row['company'] ?? ''), 'values' => $values, 'json_email' => $jsonEmail, 'to_json' => $jsonEmail === '' && $copy !== '', 'json_conflict' => $jsonEmail !== '' && $jsonEmail !== $copy];
                    $hash->update($id . "\0\0" . json_encode($values, JSON_UNESCAPED_UNICODE) . "\0" . $jsonEmail . "\n");
                    continue;
                }
                if ($contactId === '') {
                    $info['unlinked_with_copy']['count']++;
                    if (count($info['unlinked_with_copy']['ids']) < 200) {
                        $info['unlinked_with_copy']['ids'][] = $id;
                    }
                }
                $rows[$table][] = ['id' => $id, 'contact_id' => $contactId, 'company' => (string) ($row['company'] ?? ''), 'values' => $values];
                $hash->update($id . "\0" . $contactId . "\0" . json_encode($values, JSON_UNESCAPED_UNICODE) . "\0" . $jsonEmail . "\n");
            }
        }
        $prints[$table] = isset($hash) ? $hash->final() : '';
        unset($hash);
        if ($info['unlinked_with_copy']['count'] > 0) {
            $blocked[] = $table;
        }
        $tables[$table] = $info;
    }

    return ['tables' => $tables, 'rows' => $rows, 'blocked' => $blocked, 'prints' => $prints];
}

function jeab_contacts_drop_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $rows = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC)
            : $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return false;
    }
    foreach (is_array($rows) ? $rows : [] as $col) {
        if ((string) ($col['Field'] ?? $col['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

/** Rå settings-post, inklusive en gammal solkampanj_recipients som den publika markören döljer. @return array<string, mixed> */
function jeab_contacts_drop_marker_raw(PDO $pdo): array
{
    try {
        $col = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`key`' : 'key';
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE ' . $col . ' = ?');
        $stmt->execute([jeab_copy_columns_marker_key()]);
        $raw = $stmt->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        return [];
    }
}

/** @param array<string, mixed> $marker */
function jeab_contacts_drop_marker_save(PDO $pdo, array $marker): void
{
    // Publik markör bär inte solkampanj. En gammal tidsstämpel får ligga kvar utan att störa.
    $kept = $marker['solkampanj_recipients'] ?? null;
    unset($marker['solkampanj_recipients']);
    if (!is_string($kept) || $kept === '') {
        $rawKept = jeab_contacts_drop_marker_raw($pdo)['solkampanj_recipients'] ?? null;
        $kept = is_string($rawKept) ? $rawKept : '';
    }
    if ($kept !== '') {
        $marker['solkampanj_recipients'] = $kept;
    }
    $json = json_encode($marker, JSON_UNESCAPED_SLASHES);
    $col = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? '`key`' : 'key';
    $pdo->prepare('DELETE FROM settings WHERE ' . $col . ' = ?')->execute([jeab_copy_columns_marker_key()]);
    if ($marker !== []) {
        $pdo->prepare('INSERT INTO settings (' . $col . ', value) VALUES (?, ?)')->execute([jeab_copy_columns_marker_key(), (string) $json]);
    }
    jeab_copy_columns_forget();
}


/** Äldre symmetrisk nyckel (seal v2). Läses bara för gamla backuper, skapas aldrig. */
function jeab_contacts_drop_key_path(): string
{
    $dir = jeab_contacts_drop_dir();

    return $dir === '' ? '' : $dir . '.key';
}

function jeab_contacts_drop_legacy_key(): string
{
    $path = jeab_contacts_drop_key_path();
    if ($path === '' || !is_file($path) || (fileperms($path) & 0077) !== 0) {
        return '';
    }
    $hex = trim((string) @file_get_contents($path));
    if (preg_match('/\A[a-f0-9]{64}\z/', $hex) !== 1) {
        return '';
    }

    return hash_hkdf('sha256', (string) hex2bin($hex), 32, 'jeab-contacts-drop-v2');
}

/** Publik nyckel (PEM, ej hemlig). Testerna pekar om den. */
function jeab_contacts_drop_pubkey_path(): string
{
    $over = $GLOBALS['_JEAB_DROP_PUBKEY_PATH'] ?? null;

    return is_string($over) ? $over : JEAB_ROOT . '/config/contacts-drop-backup.pub';
}

/** sha256 av nyckelns DER (SubjectPublicKeyInfo), hex. */
function jeab_contacts_drop_key_fingerprint(string $pubPem): string
{
    $body = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pubPem);
    $der = base64_decode((string) $body, true);

    return is_string($der) && $der !== '' ? hash('sha256', $der) : '';
}

/** @return array{key:OpenSSLAsymmetricKey,sha256:string}|null RSA, minst 2048 bitar */
function jeab_contacts_drop_pubkey(): ?array
{
    $path = jeab_contacts_drop_pubkey_path();
    if (!function_exists('openssl_pkey_get_public') || $path === '' || !is_file($path)) {
        return null;
    }
    $pem = (string) @file_get_contents($path);
    if (str_contains($pem, 'PRIVATE')) {
        return null;
    }
    $key = @openssl_pkey_get_public($pem);
    if ($key === false) {
        return null;
    }
    $info = openssl_pkey_get_details($key);
    if (!is_array($info) || ($info['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA || (int) ($info['bits'] ?? 0) < 2048) {
        return null;
    }
    $sha = jeab_contacts_drop_key_fingerprint((string) $info['key']);

    return $sha === '' ? null : ['key' => $key, 'sha256' => $sha];
}

/** Slår in körningens nyckel med RSA-OAEP. */
function jeab_contacts_drop_wrap(string $runKey, OpenSSLAsymmetricKey $pub): ?string
{
    $out = '';
    if (strlen($runKey) !== 32 || !openssl_public_encrypt($runKey, $out, $pub, OPENSSL_PKCS1_OAEP_PADDING)) {
        return null;
    }

    return base64_encode($out);
}

/** Tillfällig fil med den privata nyckeln för undo. */
function jeab_contacts_drop_undo_key_path(): string
{
    $dir = jeab_contacts_drop_dir();

    return $dir === '' ? '' : dirname($dir) . '/contacts-drop-undo.key';
}

/**
 * Läser den privata nyckeln ur den tillfälliga filen och raderar filen direkt, vad som än händer.
 * Filen måste vara 0600 (ingen grupp/andra). Nyckeln loggas eller returneras aldrig som text.
 *
 * @return array{key:OpenSSLAsymmetricKey,sha256:string}|null
 */
function jeab_contacts_drop_take_undo_key(): ?array
{
    $path = jeab_contacts_drop_undo_key_path();
    if ($path === '' || !is_file($path)) {
        return null;
    }
    try {
        if ((fileperms($path) & 0077) !== 0 || !function_exists('openssl_pkey_get_private')) {
            return null;
        }
        $pem = (string) @file_get_contents($path);
        $key = @openssl_pkey_get_private($pem);
        $pem = '';
        if ($key === false) {
            return null;
        }
        $info = openssl_pkey_get_details($key);
        $sha = is_array($info) ? jeab_contacts_drop_key_fingerprint((string) ($info['key'] ?? '')) : '';

        return $sha === '' ? null : ['key' => $key, 'sha256' => $sha];
    } finally {
        jeab_contacts_drop_wipe_undo_key();
    }
}

function jeab_contacts_drop_wipe_undo_key(): void
{
    $path = jeab_contacts_drop_undo_key_path();
    if ($path !== '' && is_file($path)) {
        $size = (int) @filesize($path);
        if ($size > 0) {
            @file_put_contents($path, str_repeat("\0", $size));
        }
        @unlink($path);
    }
}

/**
 * Körningens nyckel för en backup: v3 packas upp med den privata nyckeln (fingeravtrycket ska
 * stämma), v2 läser den gamla symmetriska nyckeln. '' om det inte går.
 *
 * @param array<string, mixed> $data
 * @param array{key:OpenSSLAsymmetricKey,sha256:string}|null $priv
 */
function jeab_contacts_drop_batch_key(array $data, ?array $priv): string
{
    $seal = (string) ($data['seal'] ?? '');
    if ($seal === 'v2') {
        return jeab_contacts_drop_legacy_key();
    }
    if ($seal !== 'v3' || $priv === null || !hash_equals((string) ($data['pub_sha256'] ?? ''), $priv['sha256'])) {
        return '';
    }
    $wrapped = base64_decode((string) ($data['wrapped_key'] ?? ''), true);
    $out = '';
    if (!is_string($wrapped) || !@openssl_private_decrypt($wrapped, $out, $priv['key'], OPENSSL_PKCS1_OAEP_PADDING)) {
        return '';
    }

    return strlen($out) === 32 ? $out : '';
}

/** @return array<string, mixed>|null backupens json för en batch */
function jeab_contacts_drop_read_backup(string $batch): ?array
{
    $path = jeab_contacts_drop_dir() . '/' . $batch . '.json';
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

    return is_array($data) ? $data : null;
}

function jeab_contacts_drop_seal(string $plain, string $key): ?string
{
    if ($plain === '' || strlen($key) !== 32 || !function_exists('openssl_encrypt')) {
        return null;
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if (!is_string($cipher) || strlen($tag) !== 16) {
        return null;
    }

    return 'v2:' . implode('g', str_split(bin2hex($iv . $tag . $cipher), 4));
}

function jeab_contacts_drop_open(string $token, string $key): ?string
{
    if (!str_starts_with($token, 'v2:') || strlen($key) !== 32 || !function_exists('openssl_decrypt')) {
        return null;
    }
    $hex = str_replace('g', '', substr($token, 3));
    if ($hex === '' || strlen($hex) % 2 !== 0 || preg_match('/\A[0-9a-f]+\z/', $hex) !== 1) {
        return null;
    }
    $blob = (string) hex2bin($hex);
    if (strlen($blob) < 29) {
        return null;
    }
    $plain = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16));

    return is_string($plain) && $plain !== '' ? $plain : null;
}

/** @param list<array<string, mixed>> $rows */
function jeab_contacts_drop_seal_rows(array $rows, string $key): ?array
{
    $out = [];
    foreach ($rows as $row) {
        $sealed = [];
        foreach ((array) $row['values'] as $col => $value) {
            $token = jeab_contacts_drop_seal((string) $value, $key);
            if (!is_string($token)) {
                return null;
            }
            $sealed[$col] = ['sealed' => $token];
        }
        $one = ['id' => $row['id'], 'contact_id' => (string) $row['contact_id'], 'fields' => $sealed];
        if (!empty($row['to_json'])) {
            $one['to_json'] = true;
        }
        $out[] = $one;
    }

    return $out;
}

/**
 * Exakt kolumndefinition före DROP.
 *
 * @return array<string, array{type:string,null:bool,default:?string}>
 */
function jeab_contacts_drop_column_defs(PDO $pdo, string $table): array
{
    $out = [];
    $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $rows = $mysql
        ? $pdo->query('SHOW FULL COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC)
        : $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
    foreach (is_array($rows) ? $rows : [] as $col) {
        $name = (string) ($col['Field'] ?? $col['name'] ?? '');
        if (!in_array($name, jeab_copy_columns($table), true)) {
            continue;
        }
        if ($mysql) {
            $default = $col['Default'] ?? null;
            $out[$name] = [
                'type' => strtolower((string) ($col['Type'] ?? '')),
                'null' => strtoupper((string) ($col['Null'] ?? 'YES')) === 'YES',
                'default' => $default === null ? null : (string) $default,
                'collation' => (string) ($col['Collation'] ?? ''),
            ];
        } else {
            $default = $col['dflt_value'] ?? null;
            $out[$name] = [
                'type' => strtoupper((string) ($col['type'] ?? '')),
                'null' => (int) ($col['notnull'] ?? 0) === 0,
                'default' => $default === null ? null : (string) $default,
            ];
        }
    }

    return $out;
}

/** ALTER-definition ur backupen. Typen måste se ut som en vanlig SQL-typ. */
function jeab_contacts_drop_column_sql(PDO $pdo, array $def): ?string
{
    $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $type = trim((string) ($def['type'] ?? ''));
    if (preg_match('/\A[a-z]+( ?\(\d{1,5}\))?( unsigned)?\z/i', $type) !== 1) {
        return null;
    }
    $sql = $type;
    $collation = (string) ($def['collation'] ?? '');
    if ($mysql && $collation !== '') {
        if (preg_match('/\A[a-z0-9_]+\z/i', $collation) !== 1) {
            return null;
        }
        $sql .= ' COLLATE ' . $collation;
    }
    $default = $def['default'] ?? null;
    $notNull = ($def['null'] ?? true) === false;
    if ($notNull) {
        $sql .= ' NOT NULL';
    }
    if ($default !== null) {
        $literal = (string) $default;
        // sqlite sparar standardvärdet som SQL-text, t.ex. '' med citattecken.
        if (!$mysql && preg_match("/\A'(.*)'\z/s", $literal, $m) === 1) {
            $literal = str_replace("''", "'", $m[1]);
        }
        $sql .= ' DEFAULT ' . $pdo->quote($literal);
    } elseif ($notNull && !$mysql) {
        $sql .= " DEFAULT ''";
    }

    return $sql;
}

function jeab_contacts_drop_norm(string $field, string $value): string
{
    $value = trim($value);
    if ($value === '' || $value === '·') {
        return '';
    }

    return match ($field) {
        'email' => jeab_kontakt_email($value),
        'phone' => jeab_kontakt_phone($value),
        'postal' => jeab_kontakt_postal($value),
        'street' => jeab_kontakt_street_key($value),
        'city' => function_exists('jeab_kontakt_city_key') ? jeab_kontakt_city_key($value) : mb_strtolower($value),
        default => preg_replace('/\s+/u', ' ', mb_strtolower($value)) ?? $value,
    };
}

function jeab_contacts_drop_same(string $field, string $a, string $b): bool
{
    if ($field === 'name') {
        return jeab_contacts_drop_norm('name', $a) === jeab_contacts_drop_norm('name', $b)
            || jeab_kontakt_party_names_agree($a, $b);
    }

    return jeab_contacts_drop_norm($field, $a) === jeab_contacts_drop_norm($field, $b);
}

/**
 * Kopians värden i kontaktens fält: name, email, phone, street, postal, city.
 *
 * @param array<string, string> $values
 * @return array<string, string>
 */
function jeab_contacts_drop_copy_fields(array $values): array
{
    $out = [];
    foreach (['customer_name' => 'name', 'namn' => 'name', 'email' => 'email', 'customer_email' => 'email', 'mejl' => 'email', 'phone' => 'phone'] as $col => $field) {
        if (!isset($out[$field]) && jeab_contacts_drop_norm($field, (string) ($values[$col] ?? '')) !== '') {
            $out[$field] = trim((string) $values[$col]);
        }
    }
    // first_name är solkampanjens eget fält (listan droppas inte) och jämförs inte. namn, mejl och gata kan finnas i en gammal backup.
    if (trim((string) ($values['gata'] ?? '')) !== '') {
        $out['street'] = trim((string) $values['gata']);
    }
    $address = trim((string) ($values['address'] ?? ''));
    if ($address !== '') {
        $parts = jeab_address_parts($address);
        $street = trim((string) ($parts['street'] ?? ''));
        $out['street'] = $street !== '' ? $street : $address;
        if (trim((string) ($parts['postal'] ?? '')) !== '') {
            $out['postal'] = trim((string) $parts['postal']);
        }
        if (trim((string) ($parts['city'] ?? '')) !== '') {
            $out['city'] = trim((string) $parts['city']);
        }
    }
    if (trim((string) ($values['postal'] ?? '')) !== '') {
        $out['postal'] = trim((string) $values['postal']);
    }
    if (trim((string) ($values['ort'] ?? '')) !== '') {
        $out['city'] = trim((string) $values['ort']);
    }
    foreach ($out as $field => $value) {
        if (jeab_contacts_drop_norm($field, $value) === '') {
            unset($out[$field]);
        }
    }

    return $out;
}

/** @return array<string, string> kontaktens värden i samma fält */
function jeab_contacts_drop_contact_fields(array $contact): array
{
    $view = jeab_contacts_sole_view_from_contact($contact);

    return [
        'name' => trim((string) ($view['name'] ?? '')),
        'email' => trim((string) ($view['email'] ?? '')),
        'phone' => trim((string) ($view['phone'] ?? '')),
        'street' => trim((string) ($view['street'] ?? '')),
        'postal' => trim((string) ($view['postal'] ?? '')),
        'city' => trim((string) ($view['city'] ?? '')),
    ];
}

/**
 * Jämför kopiorna med kontakten. fills: kontakt-id => fält => värde (bara tomma fält).
 * conflicts: alla konflikter i radordning (tabell, id och fältnamn, aldrig värden).
 * blocking: sådant som alltid stoppar (bolag, json, ägare, två olika förslag till ett tomt fält).
 * overridable: rena fältskillnader mot kontaktens ifyllda värde. De ingår också i conflicts.
 * Bara blocking tar bort fyllningar här. Standardläget tar dessutom bort fyllningar för
 * overridable, så det läget beter sig som förut. contact_wins låter tomma fält fyllas.
 *
 * @param array<string, list<array<string, mixed>>> $rows
 * @return array{fills:array<string, array<string, string>>,fill_table:array<string, string>,conflicts:list<array<string, mixed>>,blocking:list<array<string, mixed>>,overridable:list<array<string, mixed>>,missing:list<array{table:string,id:int|string}>}
 */
function jeab_contacts_drop_reconcile(array $rows): array
{
    $fills = [];
    $fillTable = [];
    $conflicts = [];
    $blocking = [];
    $overridable = [];
    $missing = [];
    $contacts = [];
    $raw = [];
    foreach ($rows as $table => $list) {
        foreach ($list as $row) {
            $contactId = (string) ($row['contact_id'] ?? '');
            $tableName = (string) $table;
            $id = $row['id'];
            if (!empty($row['json_conflict'])) {
                $entry = ['table' => $tableName, 'id' => $id, 'contact_id' => '', 'fields' => ['email'], 'kind' => 'json'];
                $conflicts[] = $entry;
                $blocking[] = $entry;
                continue;
            }
            if ($contactId === '') {
                continue;
            }
            if (!array_key_exists($contactId, $contacts)) {
                $got = jeab_kontakt_get($contactId);
                $raw[$contactId] = is_array($got) ? $got : null;
                $contacts[$contactId] = is_array($got) ? jeab_contacts_drop_contact_fields($got) : null;
            }
            $have = $contacts[$contactId];
            if ($have === null) {
                $missing[] = ['table' => $tableName, 'id' => $id];
                continue;
            }
            $bad = [];
            $badBlock = [];
            $badOver = [];
            // Kontakten ska höra till samma bolag som raden.
            $rowCo = jeab_contacts_drop_office((string) ($row['company'] ?? ''));
            $contactCo = jeab_contacts_drop_office((string) ($raw[$contactId]['company'] ?? ''));
            // Saknar ena sidan bolag går det inte att visa att det är samma: 409.
            if ($rowCo !== $contactCo) {
                $entry = ['table' => $tableName, 'id' => $id, 'contact_id' => $contactId, 'fields' => ['company'], 'kind' => 'company'];
                $conflicts[] = $entry;
                $blocking[] = $entry;
                continue;
            }
            $hasOwner = jeab_contacts_drop_owner_index($raw[$contactId]) !== null;
            $allEmails = jeab_contacts_drop_contact_emails((array) $raw[$contactId]);
            foreach (jeab_contacts_drop_copy_fields((array) $row['values']) as $field => $value) {
                $now = (string) ($have[$field] ?? '');
                // Trådens e-post får vara vilken som helst av kontaktens adresser.
                if ($tableName === 'inkorg_list' && $field === 'email' && $allEmails !== []) {
                    if (!isset($allEmails[jeab_contacts_drop_norm('email', $value)])) {
                        $bad[] = 'email';
                        $badOver[] = 'email';
                    }
                    continue;
                }
                if (jeab_contacts_drop_norm($field, $now) !== '') {
                    // Ifyllt värde som skiljer sig. contact_wins får släppa det här.
                    if (!jeab_contacts_drop_same($field, $now, $value)) {
                        $bad[] = $field;
                        $badOver[] = $field;
                    }
                    continue;
                }
                if (!$hasOwner && in_array($field, ['name', 'email', 'phone'], true)) {
                    // Ingen ny ägare skapas: kontakten utan ägare stoppar körningen.
                    $bad[] = 'owner';
                    $badBlock[] = 'owner';
                    continue;
                }
                $planned = $fills[$contactId][$field] ?? null;
                if ($planned !== null && !jeab_contacts_drop_same($field, $planned, $value)) {
                    // Två rader föreslår olika värden till samma tomma fält.
                    $bad[] = $field;
                    $badBlock[] = $field;
                    continue;
                }
                $fills[$contactId][$field] = $planned ?? $value;
                $fillTable[$contactId] = $fillTable[$contactId] ?? $tableName;
            }
            if ($bad !== []) {
                $conflicts[] = ['table' => $tableName, 'id' => $id, 'contact_id' => $contactId, 'fields' => array_values(array_unique($bad))];
            }
            if ($badBlock !== []) {
                $blockFields = array_values(array_unique($badBlock));
                // owner och två olika förslag kan dela rad. Båda blockerar.
                $kind = $blockFields === ['owner'] ? 'owner' : 'diverge';
                $blocking[] = ['table' => $tableName, 'id' => $id, 'contact_id' => $contactId, 'fields' => $blockFields, 'kind' => $kind];
            }
            if ($badOver !== []) {
                $overridable[] = ['table' => $tableName, 'id' => $id, 'contact_id' => $contactId, 'fields' => array_values(array_unique($badOver)), 'kind' => 'field'];
            }
        }
    }
    if ($blocking !== []) {
        // En kontakt med blockerande konflikt fylls inte alls i den här körningen.
        foreach ($blocking as $c) {
            if ($c['contact_id'] !== '') {
                unset($fills[$c['contact_id']], $fillTable[$c['contact_id']]);
            }
        }
    }

    return ['fills' => $fills, 'fill_table' => $fillTable, 'conflicts' => $conflicts, 'blocking' => $blocking, 'overridable' => $overridable, 'missing' => $missing];
}

function jeab_contacts_drop_office(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    return function_exists('jeab_address_company') ? (string) jeab_address_company($raw) : mb_strtolower($raw);
}

/** Ägaren som är kontaktperson, annars den första. null: kontakten har ingen ägare. */
function jeab_contacts_drop_owner_index(?array $contact): ?int
{
    $first = null;
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $i => $owner) {
        if (!is_array($owner)) {
            continue;
        }
        if (!empty($owner['contact'])) {
            return (int) $i;
        }
        $first = $first ?? (int) $i;
    }

    return $first;
}

/** Spårvärde för ett fyllt fält. Inga värden i klartext i loggen. */
function jeab_contacts_drop_fill_mark(string $field, string $value, string $key): string
{
    return hash_hmac('sha256', $field . "\0" . jeab_contacts_drop_norm($field, $value), $key);
}

/**
 * Fyller bara tomma fält. Ett värde som hunnit dyka upp lämnas orört.
 * Kontakt utan ägare får inga ägarfält (ingen ny ägare skapas).
 * Svaret: kontakt-id => fält => {prev, mark}. prev är det tidigare (tomma) värdet,
 * mark är ett HMAC av det fyllda värdet, så undo bara tömmer ett oförändrat fält.
 *
 * @param array<string, array<string, string>> $fills
 * @return array<string, array<string, array{prev:string,mark:string}>>
 */
function jeab_contacts_drop_fill_contacts(array $fills, string $key): array
{
    if ($fills === []) {
        return [];
    }

    return (array) jeab_kontakter_mutate(static function (array &$contacts) use ($fills, $key): array {
        $done = [];
        foreach ($fills as $contactId => $fields) {
            if (!isset($contacts[$contactId]) || !is_array($contacts[$contactId])) {
                continue;
            }
            $row = $contacts[$contactId];
            $have = jeab_contacts_drop_contact_fields($row);
            $index = jeab_contacts_drop_owner_index($row);
            $owners = is_array($row['owners'] ?? null) ? $row['owners'] : [];
            $ownerMap = ['name' => 'name', 'email' => 'email', 'phone' => 'phone'];
            $rowMap = ['street' => 'street', 'postal' => 'postal_code', 'city' => 'city'];
            $touched = [];
            foreach ($fields as $field => $value) {
                if (jeab_contacts_drop_norm($field, (string) ($have[$field] ?? '')) !== '') {
                    continue;
                }
                if (isset($ownerMap[$field])) {
                    $k = $ownerMap[$field];
                    if ($index === null || !is_array($owners[$index] ?? null)) {
                        continue;
                    }
                    $prev = (string) ($owners[$index][$k] ?? '');
                    if (trim($prev) !== '') {
                        continue;
                    }
                    $put = $field === 'email' ? jeab_kontakt_email($value) : ($field === 'phone' ? jeab_kontakt_phone($value) : $value);
                    $owners[$index][$k] = $put;
                    $touched[$field] = ['prev' => $prev, 'mark' => jeab_contacts_drop_fill_mark($field, $put, $key)];
                    continue;
                }
                $k = $rowMap[$field] ?? '';
                if ($k === '' || trim((string) ($row[$k] ?? '')) !== '') {
                    continue;
                }
                $prev = (string) ($row[$k] ?? '');
                $put = $field === 'postal' ? jeab_kontakt_postal($value) : $value;
                $row[$k] = $put;
                if ($field === 'street') {
                    $row['street_key'] = jeab_kontakt_street_key($value);
                }
                $touched[$field] = ['prev' => $prev, 'mark' => jeab_contacts_drop_fill_mark($field, $put, $key)];
            }
            if ($touched === []) {
                continue;
            }
            if (array_intersect(array_keys($touched), ['name', 'email', 'phone']) !== []) {
                $row['owners'] = $owners;
            }
            $contacts[$contactId] = $row;
            $done[$contactId] = $touched;
        }

        return $done;
    });
}

/**
 * Backar fyllningar. Ett fält töms bara när värdet fortfarande är det som fylldes.
 *
 * @param array<string, array<string, array{prev?:string,mark?:string}>> $log
 * @return array{reverted:int,kept:int}
 */
function jeab_contacts_drop_unfill(array $log, string $key): array
{
    if ($log === []) {
        return ['reverted' => 0, 'kept' => 0];
    }

    return (array) jeab_kontakter_mutate(static function (array &$contacts) use ($log, $key): array {
        $reverted = 0;
        $kept = 0;
        foreach ($log as $contactId => $fields) {
            if (!isset($contacts[$contactId]) || !is_array($contacts[$contactId]) || !is_array($fields)) {
                $kept += is_array($fields) ? count($fields) : 0;
                continue;
            }
            $row = $contacts[$contactId];
            $index = jeab_contacts_drop_owner_index($row);
            foreach ($fields as $field => $entry) {
                $mark = is_array($entry) ? (string) ($entry['mark'] ?? '') : '';
                $prev = is_array($entry) ? (string) ($entry['prev'] ?? '') : '';
                $ownerKey = ['name' => 'name', 'email' => 'email', 'phone' => 'phone'][$field] ?? null;
                $rowKey = ['street' => 'street', 'postal' => 'postal_code', 'city' => 'city'][$field] ?? null;
                if ($ownerKey !== null && $index !== null && is_array($row['owners'][$index] ?? null)) {
                    $now = (string) ($row['owners'][$index][$ownerKey] ?? '');
                    if ($mark !== '' && hash_equals($mark, jeab_contacts_drop_fill_mark((string) $field, $now, $key))) {
                        $row['owners'][$index][$ownerKey] = $prev;
                        $reverted++;
                        continue;
                    }
                } elseif ($rowKey !== null) {
                    $now = (string) ($row[$rowKey] ?? '');
                    if ($mark !== '' && hash_equals($mark, jeab_contacts_drop_fill_mark((string) $field, $now, $key))) {
                        $row[$rowKey] = $prev;
                        if ($field === 'street') {
                            $row['street_key'] = $prev === '' ? '' : jeab_kontakt_street_key($prev);
                        }
                        $reverted++;
                        continue;
                    }
                }
                $kept++;
            }
            $contacts[$contactId] = $row;
        }

        return ['reverted' => $reverted, 'kept' => $kept];
    });
}

/** @param array<string, mixed> $log */
function jeab_contacts_drop_save_fills(string $batch, array $log): bool
{
    $path = jeab_contacts_drop_dir() . '/' . $batch . '.fills';
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, (string) json_encode($log, JSON_UNESCAPED_SLASHES)) !== false && jeab_contacts_copies_chmod($tmp, 0600) && @rename($tmp, $path)) {
        return true;
    }
    @unlink($tmp);

    return false;
}

/** @return array<string, mixed> */
function jeab_contacts_drop_load_fills(string $batch): array
{
    $path = jeab_contacts_drop_dir() . '/' . $batch . '.fills';
    $log = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

    return is_array($log) ? $log : [];
}

/** @param array<string, array<string, string>> $fills */
function jeab_contacts_drop_save_pending(string $batch, array $fills, string $key): bool
{
    $path = jeab_contacts_drop_dir() . '/' . $batch . '.pending';
    if ($fills === []) {
        return true;
    }
    $sealed = [];
    foreach ($fills as $contactId => $fields) {
        foreach ($fields as $field => $value) {
            $token = jeab_contacts_drop_seal((string) $value, $key);
            if ($token === null) {
                return false;
            }
            $sealed[(string) $contactId][(string) $field] = $token;
        }
    }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, (string) json_encode($sealed, JSON_UNESCAPED_SLASHES)) === false || !jeab_contacts_copies_chmod($tmp, 0600) || !@rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }

    return true;
}

/**
 * Gör de sparade fyllningarna, skriver loggen (föregående värde + HMAC) och tar bort planen.
 *
 * @return array<string, array<string, array{prev:string,mark:string}>>
 */
function jeab_contacts_drop_finish_pending(string $batch, string $key): array
{
    $path = jeab_contacts_drop_dir() . '/' . $batch . '.pending';
    $raw = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    $old = jeab_contacts_drop_load_fills($batch);
    if (!is_array($raw)) {
        return $old;
    }
    $fills = [];
    foreach ($raw as $contactId => $fields) {
        foreach (is_array($fields) ? $fields : [] as $field => $token) {
            $plain = jeab_contacts_drop_open((string) $token, $key);
            if ($plain === null) {
                // Planen ligger kvar, så en ny körning med rätt nyckel kan göra klart.
                throw new RuntimeException('Fyllningsplanen gick inte att dekryptera.');
            }
            $fills[(string) $contactId][(string) $field] = $plain;
        }
    }
    // Loggen skrivs före kontaktskrivningen. intent = planerad men ännu inte bekräftad.
    $intent = $old;
    foreach ($fills as $contactId => $fields) {
        foreach ($fields as $field => $value) {
            if (!isset($intent[$contactId][$field])) {
                $intent[$contactId][$field] = ['prev' => '', 'mark' => jeab_contacts_drop_fill_mark($field, $value, $key), 'intent' => true];
            }
        }
    }
    if (!jeab_contacts_drop_save_fills($batch, $intent)) {
        throw new RuntimeException('Fyllningsloggen gick inte att skriva.');
    }
    $log = jeab_contacts_drop_fill_contacts($fills, $key);
    foreach ($log as $contactId => $fields) {
        $old[$contactId] = array_merge(is_array($old[$contactId] ?? null) ? $old[$contactId] : [], $fields);
    }
    if (!jeab_contacts_drop_save_fills($batch, $old)) {
        throw new RuntimeException('Fyllningsloggen gick inte att skriva.');
    }
    @unlink($path);

    return $old;
}

function jeab_contacts_drop_write_backup(string $batch, array $payload): ?array
{
    $dir = jeab_contacts_drop_dir();
    if ($dir === '' || (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir))) {
        return null;
    }
    @chmod($dir, 0700);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return null;
    }
    $path = $dir . '/' . $batch . '.json';
    $tmp = $dir . '/.' . $batch . '.tmp';
    if (file_put_contents($tmp, $json) === false) {
        @unlink($tmp);

        return null;
    }
    if (!jeab_contacts_copies_chmod($tmp, 0600) || !@rename($tmp, $path)) {
        @unlink($tmp);

        return null;
    }

    $sha = hash('sha256', $json);
    // Undo jämför backupen mot den här summan och vägrar vid skillnad.
    $shaPath = $dir . '/' . $batch . '.sha256';
    if (file_put_contents($shaPath . '.tmp', $sha) === false || !jeab_contacts_copies_chmod($shaPath . '.tmp', 0600) || !@rename($shaPath . '.tmp', $shaPath)) {
        @unlink($shaPath . '.tmp');
        @unlink($path);

        return null;
    }

    return ['path' => jeab_contacts_drop_rel_path($path), 'sha256' => $sha];
}

/**
 * Vilka kolumner batchen faktiskt tog bort, per tabell. Undo återställer bara dem,
 * så en tabell eller kolumn som stoppades före DROP inte fylls med gamla värden.
 * MySQL: varje kolumn markeras direkt efter sin DROP (DDL committas en och en).
 * SQLite: tabellens kolumner markeras efter COMMIT.
 *
 * @param list<string> $cols
 */
function jeab_contacts_drop_mark_columns(string $batch, string $table, array $cols): bool
{
    $have = jeab_contacts_drop_done_columns($batch);
    $have[$table] = array_values(array_unique(array_merge($have[$table] ?? [], $cols)));
    $path = jeab_contacts_drop_dir() . '/' . $batch . '.done';
    $tmp = $path . '.tmp';
    if (empty($GLOBALS['_JEAB_DROP_MARK_FAIL']) && file_put_contents($tmp, (string) json_encode(['columns' => $have])) !== false && jeab_contacts_copies_chmod($tmp, 0600) && @rename($tmp, $path)) {
        return true;
    }
    @unlink($tmp);

    return false;
}

/**
 * Planer från tidigare körningar. En batch utan .done tog aldrig bort något: planen
 * raderas (den kontrollerades mot ett läge som inte gäller längre). Med .done görs den klart.
 */
/**
 * Gör klart planer från tidigare körningar. $keyFor ger batchens nyckel eller ''.
 * strict: saknad nyckel är ett fel; annars ligger planen kvar tills undo-nyckeln finns.
 *
 * @param callable(string):string $keyFor
 * @return list<string> batchar som väntar på undo-nyckeln
 */
function jeab_contacts_drop_finish_leftovers(callable $keyFor, string $skip = '', bool $strict = true): array
{
    $waiting = [];
    foreach (glob(jeab_contacts_drop_dir() . '/*.pending') ?: [] as $pending) {
        $pb = basename($pending, '.pending');
        if ($pb === $skip || preg_match('/\A[a-f0-9]{16}\z/', $pb) !== 1) {
            continue;
        }
        if (jeab_contacts_drop_done_columns($pb) === []) {
            @unlink($pending);
            continue;
        }
        $key = (string) $keyFor($pb);
        if (strlen($key) !== 32) {
            if ($strict) {
                throw new RuntimeException('Fyllningsplanen gick inte att dekryptera.');
            }
            $waiting[] = $pb;
            continue;
        }
        jeab_contacts_drop_finish_pending($pb, $key);
    }
    sort($waiting);

    return $waiting;
}

/** @return callable(string):string */
function jeab_contacts_drop_key_for(?array $priv): callable
{
    return static function (string $batch) use ($priv): string {
        $data = jeab_contacts_drop_read_backup($batch);

        return is_array($data) ? jeab_contacts_drop_batch_key($data, $priv) : '';
    };
}

/** @return array<string, list<string>> */
function jeab_contacts_drop_done_columns(string $batch): array
{
    $path = jeab_contacts_drop_dir() . '/' . $batch . '.done';
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    $out = [];
    foreach (is_array($data['columns'] ?? null) ? $data['columns'] : [] as $table => $cols) {
        if (is_string($table) && is_array($cols)) {
            $out[$table] = array_values(array_filter($cols, 'is_string'));
        }
    }

    return $out;
}

/** Sökväg relativt data/, så svaret inte visar serverns mappar. */
function jeab_contacts_drop_rel_path(string $path): string
{
    $base = rtrim(str_replace('\\', '/', jeab_data_dir()), '/') . '/';
    $norm = str_replace('\\', '/', $path);

    return str_starts_with($norm, $base) ? 'data/' . substr($norm, strlen($base)) : basename($norm);
}

function jeab_contacts_drop_column(PDO $pdo, string $table, string $col): void
{
    if (!in_array($col, jeab_copy_columns($table), true)) {
        throw new InvalidArgumentException('kolumn');
    }
    if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        // SQLite tar inte bort en indexerad kolumn. Indexet tas bort först, undo lägger tillbaka det.
        foreach (jeab_contacts_drop_column_indexes($pdo, $table, $col) as $name => $sql) {
            unset($sql);
            $pdo->exec('DROP INDEX IF EXISTS ' . $name);
        }
    }
    $pdo->exec('ALTER TABLE ' . $table . ' DROP COLUMN ' . $col);
}

/**
 * Index som bara gäller kolumnen: namn => CREATE [UNIQUE] INDEX. Unika index återskapas som unika,
 * och MySQL behåller prefixlängden (t.ex. gata(100)).
 *
 * @return array<string, string>
 */
function jeab_contacts_drop_column_indexes(PDO $pdo, string $table, string $col): array
{
    $out = [];
    try {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $by = [];
            $unique = [];
            $part = [];
            foreach ($pdo->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $name = (string) $r['Key_name'];
                $by[$name][] = (string) $r['Column_name'];
                $unique[$name] = (int) $r['Non_unique'] === 0;
                $part[$name] = ($r['Sub_part'] ?? null) !== null ? (int) $r['Sub_part'] : 0;
            }
            foreach ($by as $name => $cols) {
                if ($name !== 'PRIMARY' && $cols === [$col]) {
                    $out[$name] = 'CREATE ' . ($unique[$name] ? 'UNIQUE ' : '') . 'INDEX ' . $name . ' ON ' . $table
                        . ' (' . $col . ($part[$name] > 0 ? '(' . $part[$name] . ')' : '') . ')';
                }
            }

            return $out;
        }
        foreach ($pdo->query('PRAGMA index_list(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $idx) {
            $name = (string) ($idx['name'] ?? '');
            if ($name === '' || str_starts_with($name, 'sqlite_')) {
                continue;
            }
            $cols = array_map(static fn (array $r): string => (string) $r['name'], $pdo->query('PRAGMA index_info(' . $name . ')')->fetchAll(PDO::FETCH_ASSOC));
            if ($cols === [$col]) {
                $out[$name] = 'CREATE ' . ((int) ($idx['unique'] ?? 0) === 1 ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS ' . $name . ' ON ' . $table . ' (' . $col . ')';
            }
        }
    } catch (Throwable) {
        return $out;
    }

    return $out;
}

/**
 * Historiktrådar utan kontakt: kopian läggs i row_json.customer_email när den är tom där.
 * Körs i låset före DROP. Skriver bara om row_json fortfarande saknar värdet. Tom eller NULL
 * row_json blir '{}' först (JSON_SET(NULL, …) är NULL i MySQL). Efteråt läses varje rad om;
 * saknas mejlet i row_json kastas ett fel, så låset rullas tillbaka och inget DROP körs.
 *
 * @param list<array<string, mixed>> $rows
 */
function jeab_contacts_drop_inkorg_to_json(PDO $pdo, array $rows): int
{
    $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $sql = $mysql
        ? "UPDATE inkorg_list SET row_json = JSON_SET(COALESCE(NULLIF(row_json, ''), '{}'), '$.customer_email', ?) WHERE id = ? AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(NULLIF(row_json, ''), '{}'), '$.customer_email')), '') = ''"
        : "UPDATE inkorg_list SET row_json = json_set(COALESCE(NULLIF(row_json, ''), '{}'), '$.customer_email', ?) WHERE id = ? AND COALESCE(json_extract(COALESCE(NULLIF(row_json, ''), '{}'), '$.customer_email'), '') = ''";
    $up = $pdo->prepare($sql);
    $n = 0;
    foreach ($rows as $row) {
        if (empty($row['to_json'])) {
            continue;
        }
        $up->execute([strtolower(trim((string) ($row['values']['customer_email'] ?? ''))), (string) $row['id']]);
        $n += $up->rowCount();
    }
    $check = $pdo->prepare('SELECT row_json FROM inkorg_list WHERE id = ?');
    foreach ($rows as $row) {
        $want = strtolower(trim((string) ($row['values']['customer_email'] ?? '')));
        if (empty($row['to_json']) || $want === '') {
            continue;
        }
        $check->execute([(string) $row['id']]);
        $json = json_decode((string) $check->fetchColumn(), true);
        if (!is_array($json) || strtolower(trim((string) ($json['customer_email'] ?? ''))) !== $want) {
            throw new RuntimeException('E-posten kom inte med i row_json.');
        }
    }

    return $n;
}

/** Läser tabellens kopior igen. Samma som planen? */
function jeab_contacts_drop_unchanged(PDO $pdo, string $table, string $print): bool
{
    jeab_copy_columns_forget();
    $now = jeab_contacts_drop_plan($pdo, [$table]);

    return ($now['prints'][$table] ?? null) === $print;
}

/**
 * Låser tabellen, läser om och tar bort kolumnerna. Ändrades tabellen sedan planen: false.
 *
 * @param list<string> $cols
 */
function jeab_contacts_drop_table_locked(PDO $pdo, string $table, array $cols, string $print, ?callable $mark = null, array $rows = []): bool
{
    $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    // Testkrok: kör som MySQL utan transaktion (varje DROP står kvar för sig).
    $noTx = !$mysql && !empty($GLOBALS['_JEAB_DROP_NO_TX']);
    $hook = $GLOBALS['_JEAB_DROP_BEFORE_LOCK'] ?? null;
    if (is_callable($hook)) {
        $hook($pdo, $table);
    }
    $release = static function (bool $ok) use ($pdo, $mysql, $noTx): void {
        if ($mysql) {
            $pdo->exec('UNLOCK TABLES');
        } elseif (!$noTx) {
            $pdo->exec($ok ? 'COMMIT' : 'ROLLBACK');
        }
    };
    if ($mysql) {
        $pdo->exec('LOCK TABLES `' . $table . '` WRITE');
    } elseif (!$noTx) {
        $pdo->exec('BEGIN IMMEDIATE');
    }
    $perColumn = $mysql || $noTx;
    try {
        if (!jeab_contacts_drop_unchanged($pdo, $table, $print)) {
            $release(false);

            return false;
        }
        if ($table === 'inkorg_list') {
            jeab_contacts_drop_inkorg_to_json($pdo, $rows);
        }
        $after = $GLOBALS['_JEAB_DROP_AFTER_COLUMN'] ?? null;
        foreach ($cols as $col) {
            jeab_contacts_drop_column($pdo, $table, $col);
            if ($perColumn && $mark !== null) {
                $mark([$col]);
            }
            if (is_callable($after)) {
                $after($pdo, $table, $col);
            }
        }
        $release(true);
        if (!$perColumn && $mark !== null) {
            $mark($cols);
        }
    } catch (Throwable $e) {
        try {
            $release(false);
        } catch (Throwable) {
        }
        throw $e;
    } finally {
        jeab_copy_columns_forget();
    }

    return true;
}

/**
 * Konflikter till svaret: tabell, id och fältnamn. Inga värden och inget contact_id.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array{table:string,id:mixed,fields:list<string>}>
 */
function jeab_contacts_drop_conflict_public(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $fields = [];
        foreach ((array) ($row['fields'] ?? []) as $field) {
            if (is_string($field) && $field !== '') {
                $fields[] = $field;
            }
        }
        $out[] = ['table' => (string) ($row['table'] ?? ''), 'id' => $row['id'] ?? 0, 'fields' => $fields];
    }

    return $out;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_drop_customer_fields(array $input, ?JeabStore $store = null): array
{
    $dry = jeab_contacts_copies_dry($input);
    $base = ['ok' => true, 'action' => 'contacts.drop_customer_fields', 'dry' => $dry, 'written' => false, 'batch_id' => '', 'sent' => 0];
    $pick = jeab_contacts_drop_tables_param($input['tables'] ?? null);
    $selected = $pick['selected'];
    $base['selected'] = $selected;
    // Okänt namn stoppar före plan och DROP, även vid dry.
    if ($pick['unknown'] !== []) {
        return array_merge($base, ['ok' => false, 'error' => 'Okänd tabell.', 'unknown' => $pick['unknown'], 'status' => 400]);
    }
    // Okänt conflict-värde stoppar också före plan och DROP, även vid dry.
    $mode = jeab_contacts_drop_conflict_param(array_key_exists('conflict', $input) ? $input['conflict'] : null);
    if ($mode === null) {
        return array_merge($base, ['ok' => false, 'error' => 'Okänt conflict-värde.', 'status' => 400]);
    }
    $base['conflict_mode'] = $mode;
    $base['conflicts_overridden'] = [];
    $pdo = $store instanceof JeabPdoStore ? $store->pdo() : (function_exists('jeab_data_pdo') ? jeab_data_pdo() : null);
    if (!$pdo instanceof PDO) {
        return $base + ['ok' => false, 'error' => 'Ingen lagring.', 'status' => 400];
    }
    $plan = jeab_contacts_drop_plan($pdo, $selected);
    $check = jeab_contacts_drop_reconcile($plan['rows']);
    if ($mode === 'block') {
        // En konflikt, även en som contact_wins skulle släppa, gör att kontakten inte fylls.
        foreach ($check['overridable'] as $c) {
            $cid = (string) ($c['contact_id'] ?? '');
            if ($cid !== '') {
                unset($check['fills'][$cid], $check['fill_table'][$cid]);
            }
        }
    }
    // contact_wins tar bort de rena fältskillnaderna ur conflicts. Övriga stoppar som vanligt.
    $showConflicts = $mode === 'contact_wins' ? $check['blocking'] : $check['conflicts'];
    $showOver = $mode === 'contact_wins' ? $check['overridable'] : [];
    $base['tables'] = $plan['tables'];
    $base['blocked'] = $plan['blocked'];
    $base['conflicts'] = jeab_contacts_drop_conflict_public($showConflicts);
    $base['conflicts_overridden'] = jeab_contacts_drop_conflict_public($showOver);
    $base['missing_contact'] = $check['missing'];
    $base['fill'] = ['contacts' => count($check['fills']), 'fields' => array_sum(array_map('count', $check['fills']))];
    if ($dry) {
        return $base;
    }
    if (!jeab_contacts_copies_hold()) {
        return array_merge($base, ['ok' => false, 'error' => 'Kontakter är inte enda källan.', 'status' => 400]);
    }
    if ($plan['blocked'] !== []) {
        return array_merge($base, ['ok' => false, 'error' => 'Rader utan contact_id har kopior.', 'status' => 409]);
    }
    if ($showConflicts !== [] || $check['missing'] !== []) {
        return array_merge($base, ['ok' => false, 'error' => 'Kopior skiljer sig från kontakten.', 'status' => 409]);
    }
    $todo = [];
    foreach ($plan['tables'] as $table => $info) {
        if ($info['drop'] !== []) {
            $todo[(string) $table] = $info['drop'];
        }
    }
    $marker = jeab_copy_columns_marker($pdo);
    if ($todo === []) {
        if (glob(jeab_contacts_drop_dir() . '/*.pending') ?: []) {
            // En gammal plan kräver den privata nyckeln (tillfällig fil, raderas direkt).
            $priv = jeab_contacts_drop_take_undo_key();
            try {
                jeab_contacts_drop_finish_leftovers(jeab_contacts_drop_key_for($priv));
            } catch (Throwable $e) {
                return array_merge($base, ['ok' => false, 'error' => $e->getMessage(), 'status' => 500]);
            } finally {
                $priv = null;
            }
        }
        $existing = array_values(array_filter($selected, static fn (string $t): bool => jeab_contacts_drop_has_column($pdo, $t, 'id')));
        $missing = array_diff($existing, array_keys($marker));
        foreach ($existing as $table) {
            $marker[$table] = $marker[$table] ?? date('c');
        }
        jeab_contacts_drop_marker_save($pdo, $marker);

        return array_merge($base, ['written' => $missing !== [], 'already' => $missing === [], 'marker' => array_keys($marker)]);
    }
    $pub = jeab_contacts_drop_pubkey();
    if ($pub === null) {
        return array_merge($base, ['ok' => false, 'error' => 'backup_key_missing', 'status' => 409]);
    }
    // Slumpad nyckel per körning. Den finns bara i minnet och inslagen mot publika nyckeln.
    $key = random_bytes(32);
    $wrapped = jeab_contacts_drop_wrap($key, $pub['key']);
    if ($wrapped === null) {
        return array_merge($base, ['ok' => false, 'error' => 'Krypteringen gick inte.', 'status' => 500]);
    }
    $base['pub_sha256'] = $pub['sha256'];
    $payload = ['batch_id' => '', 'at' => date('c'), 'seal' => 'v3', 'pub_sha256' => $pub['sha256'], 'wrapped_key' => $wrapped, 'tables' => []];
    foreach ($todo as $table => $cols) {
        $sealed = jeab_contacts_drop_seal_rows($plan['rows'][$table], $key);
        if ($sealed === null) {
            return array_merge($base, ['ok' => false, 'error' => 'Krypteringen gick inte.', 'status' => 500]);
        }
        $defs = jeab_contacts_drop_column_defs($pdo, $table);
        if (array_diff($cols, array_keys($defs)) !== []) {
            return array_merge($base, ['ok' => false, 'error' => 'Kolumntypen gick inte att läsa.', 'status' => 500]);
        }
        $indexes = [];
        foreach ($cols as $col) {
            $indexes += jeab_contacts_drop_column_indexes($pdo, $table, $col);
        }
        $payload['tables'][$table] = ['columns' => $cols, 'defs' => array_intersect_key($defs, array_flip($cols)), 'indexes' => $indexes, 'rows' => $sealed];
    }
    $payload['fills'] = array_map(static fn (array $f): array => array_keys($f), $check['fills']);
    $payload['fill_table'] = $check['fill_table'];
    $batch = bin2hex(random_bytes(8));
    $payload['batch_id'] = $batch;
    $backup = jeab_contacts_drop_write_backup($batch, $payload);
    if ($backup === null) {
        return array_merge($base, ['ok' => false, 'error' => 'Backupen gick inte att skriva.', 'status' => 500]);
    }
    $base['batch_id'] = $batch;
    $base['backup'] = $backup;
    // Planerade fyllningar sparas krypterade. De görs först när alla tabeller är låsta,
    // kontrollerade och klara, så ett 409/500 under DROP lämnar inga fyllningar.
    if (!jeab_contacts_drop_save_pending($batch, $check['fills'], $key)) {
        return array_merge($base, ['ok' => false, 'error' => 'Fyllningsplanen gick inte att spara.', 'status' => 500]);
    }
    $log = [];
    // Stoppar körningen innan något i batchen tagits bort raderas planen. Annars ligger den
    // kvar, så nästa körning fyller från kopior som bara finns i backupen.
    $dropPlan = static function () use ($batch): void {
        if (jeab_contacts_drop_done_columns($batch) === []) {
            @unlink(jeab_contacts_drop_dir() . '/' . $batch . '.pending');
        }
    };
    $filledFor = static function (array $log): array {
        return ['contacts' => count($log), 'fields' => array_sum(array_map('count', $log))];
    };
    $dropped = [];
    try {
        foreach ($todo as $table => $cols) {
            $mark = static function (array $done) use ($batch, $table): void {
                if (!jeab_contacts_drop_mark_columns($batch, $table, $done)) {
                    throw new RuntimeException('Markeringen gick inte att skriva.');
                }
            };
            if (!jeab_contacts_drop_table_locked($pdo, $table, $cols, (string) ($plan['prints'][$table] ?? ''), $mark, $plan['rows'][$table] ?? [])) {
                $dropPlan();

                return array_merge($base, ['ok' => false, 'error' => 'Tabellen ändrades under körningen. Kör igen.', 'changed' => $table, 'dropped' => $dropped, 'filled' => $filledFor($log), 'written' => true, 'status' => 409]);
            }
            $dropped[$table] = $cols;
        }
        $log = jeab_contacts_drop_finish_pending($batch, $key);
        // Planer från tidigare körningar som stoppade efter sin DROP görs klart nu.
        // Bara planer vars nyckel finns utan privat nyckel (äldre v2); andra ligger kvar.
        // De andra rapporteras; de görs klart i en körning med undo-nyckeln på plats.
        $base['pending_waiting'] = jeab_contacts_drop_finish_leftovers(jeab_contacts_drop_key_for(null), $batch, false);
        $base['filled'] = $filledFor($log);
    } catch (Throwable $e) {
        error_log('contacts.drop_customer_fields stoppade: ' . get_class($e));
        jeab_copy_columns_forget();
        $dropPlan();

        return array_merge($base, ['ok' => false, 'error' => 'DROP stoppade. Kör igen eller undo.', 'dropped' => $dropped, 'filled' => $filledFor($log), 'written' => true, 'status' => 500]);
    }
    // Markeringen först när de valda tabellerna är klara. Ovalda tabeller rörs inte.
    jeab_copy_columns_forget();
    foreach ($selected as $table) {
        // En tabell som inte finns markeras inte: skapas den senare har den kolumnerna.
        if (!jeab_contacts_drop_has_column($pdo, $table, 'id')) {
            continue;
        }
        // Bara valda kopie-tabeller. solkampanj_recipients ingår inte, även med en gammal markörpost.
        foreach (jeab_copy_columns($table) as $col) {
            if (jeab_copy_column_present($pdo, $table, $col)) {
                return array_merge($base, ['ok' => false, 'error' => 'En kolumn finns kvar. Kör igen.', 'dropped' => $dropped, 'written' => true, 'status' => 409]);
            }
        }
        $marker[$table] = $marker[$table] ?? date('c');
    }
    jeab_contacts_drop_marker_save($pdo, $marker);
    jeab_copy_columns_forget();

    return array_merge($base, ['written' => true, 'dropped' => $dropped]);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_drop_customer_fields_undo(array $input, ?JeabStore $store = null): array
{
    // Den privata nyckeln läses en gång och den tillfälliga filen raderas oavsett utfall.
    $priv = jeab_contacts_drop_take_undo_key();
    try {
        return jeab_contacts_drop_customer_fields_undo_with($input, $store, $priv);
    } finally {
        $priv = null;
        jeab_contacts_drop_wipe_undo_key();
    }
}

/**
 * @param array<string, mixed> $input
 * @param array{key:OpenSSLAsymmetricKey,sha256:string}|null $priv
 * @return array<string, mixed>
 */
function jeab_contacts_drop_customer_fields_undo_with(array $input, ?JeabStore $store, ?array $priv): array
{
    $dry = jeab_contacts_copies_dry($input);
    $batch = trim((string) ($input['batch_id'] ?? ''));
    $base = ['ok' => true, 'action' => 'contacts.drop_customer_fields_undo', 'dry' => $dry, 'written' => false, 'batch_id' => $batch, 'sent' => 0];
    if (preg_match('/\A[a-f0-9]{16}\z/', $batch) !== 1) {
        return array_merge($base, ['ok' => false, 'error' => 'batch_id saknas.', 'status' => 400]);
    }
    $path = jeab_contacts_drop_dir() . '/' . $batch . '.json';
    $raw = is_file($path) ? (string) file_get_contents($path) : '';
    $data = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($data) || !is_array($data['tables'] ?? null)) {
        return array_merge($base, ['ok' => false, 'error' => 'Backupen finns inte.', 'status' => 404]);
    }
    $sha = hash('sha256', $raw);
    $base['backup'] = ['path' => jeab_contacts_drop_rel_path($path), 'sha256' => $sha];
    $shaPath = jeab_contacts_drop_dir() . '/' . $batch . '.sha256';
    $want = is_file($shaPath) ? trim((string) file_get_contents($shaPath)) : '';
    $given = strtolower(trim((string) ($input['sha256'] ?? '')));
    if ($want === '' || !hash_equals($want, $sha) || ($given !== '' && !hash_equals($given, $sha))) {
        return array_merge($base, ['ok' => false, 'error' => 'Backupens sha256 stämmer inte. Inget skrevs.', 'status' => 409]);
    }
    $pdo = $store instanceof JeabPdoStore ? $store->pdo() : (function_exists('jeab_data_pdo') ? jeab_data_pdo() : null);
    if (!$pdo instanceof PDO) {
        return array_merge($base, ['ok' => false, 'error' => 'Ingen lagring.', 'status' => 400]);
    }
    $seal = (string) ($data['seal'] ?? '');
    if ($seal === 'v3') {
        $base['pub_sha256'] = (string) ($data['pub_sha256'] ?? '');
        if ($priv === null) {
            return array_merge($base, ['ok' => false, 'error' => 'undo_key_missing', 'status' => 409]);
        }
        if (!hash_equals($base['pub_sha256'], $priv['sha256'])) {
            return array_merge($base, ['ok' => false, 'error' => 'undo_key_mismatch', 'status' => 409]);
        }
    }
    $key = jeab_contacts_drop_batch_key($data, $priv);
    if (strlen($key) !== 32) {
        return array_merge($base, ['ok' => false, 'error' => 'Backupnyckeln går inte att läsa. Inget skrevs.', 'status' => 409]);
    }
    $doneCols = jeab_contacts_drop_done_columns($batch);
    $base['not_dropped'] = [];
    // Allt dekrypteras och varje typ prövas innan något skrivs.
    $counts = [];
    $plain = [];
    $sqlDefs = [];
    $toJson = [];
    $bad = 0;
    foreach ($data['tables'] as $table => $pack) {
        $table = (string) $table;
        // Gammal backup kan ha solkampanj_recipients. Den återställs om den finns, men är inte en drop-tabell.
        $legacy = $table === 'solkampanj_recipients';
        if ((!in_array($table, jeab_contacts_drop_tables(), true) && !$legacy) || !is_array($pack)) {
            continue;
        }
        $allowed = $legacy ? jeab_contacts_drop_legacy_solkampanj_columns() : jeab_copy_columns($table);
        // Varje kolumn som batchen markerat som borttagen återställs, även när DROP
        // stannade halvvägs i tabellen.
        $marked = $doneCols[$table] ?? [];
        $cols = [];
        foreach ((array) ($pack['columns'] ?? []) as $c) {
            if (is_string($c) && in_array($c, $allowed, true) && in_array($c, $marked, true)) {
                $cols[] = $c;
            }
        }
        if ($cols === []) {
            $base['not_dropped'][] = (string) $table;
            continue;
        }
        $counts[$table] = ['columns' => $cols, 'rows' => count((array) ($pack['rows'] ?? [])), 'restored' => 0, 'skipped' => 0];
        foreach ($cols as $col) {
            $sql = is_array($pack['defs'][$col] ?? null) ? jeab_contacts_drop_column_sql($pdo, $pack['defs'][$col]) : null;
            if ($sql === null) {
                return array_merge($base, ['ok' => false, 'error' => 'Backupen saknar kolumntyp.', 'table' => $table, 'column' => $col, 'status' => 409]);
            }
            $sqlDefs[$table][$col] = $sql;
        }
        foreach ((array) ($pack['rows'] ?? []) as $row) {
            $id = jeab_contacts_drop_row_id((string) $table, $row['id'] ?? 0);
            if (!empty($row['to_json'])) {
                $toJson[$table][$id] = true;
            }
            foreach (is_array($row['fields'] ?? null) ? $row['fields'] : [] as $col => $stored) {
                if (!in_array($col, $cols, true)) {
                    continue;
                }
                $token = is_array($stored) ? (string) ($stored['sealed'] ?? '') : '';
                $text = jeab_contacts_drop_open($token, $key);
                if ($text === null) {
                    $bad++;
                    continue;
                }
                $plain[$table][$id][$col] = $text;
            }
        }
    }
    $base['tables'] = $counts;
    if ($bad > 0) {
        return array_merge($base, ['ok' => false, 'error' => 'Värden gick inte att dekryptera. Inget skrevs.', 'unreadable' => $bad, 'status' => 409]);
    }
    $fillLog = jeab_contacts_drop_load_fills($batch);
    $base['fills'] = ['contacts' => count($fillLog), 'fields' => array_sum(array_map(static fn ($f): int => is_array($f) ? count($f) : 0, $fillLog))];
    if ($dry) {
        return $base;
    }
    foreach ($counts as $table => $info) {
        jeab_copy_columns_forget();
        // solkampanj är inte en kopiekolumn, så närvaron läses ur schemat.
        $legacy = $table === 'solkampanj_recipients';
        foreach ($info['columns'] as $col) {
            $present = $legacy
                ? jeab_contacts_drop_has_column($pdo, $table, $col)
                : jeab_copy_column_present($pdo, $table, $col);
            if (!$present) {
                $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $col . ' ' . $sqlDefs[$table][$col]);
            }
        }
        jeab_copy_columns_forget();
        foreach ((array) ($data['tables'][$table]['indexes'] ?? []) as $name => $sql) {
            if (is_string($sql) && preg_match('/\ACREATE (UNIQUE )?INDEX (IF NOT EXISTS )?[a-z0-9_]+ ON [a-z_]+ \([a-z_]+(\([0-9]+\))?\)\z/', $sql) === 1) {
                try {
                    $pdo->exec($sql);
                } catch (Throwable) {
                    // Finns redan.
                }
            }
        }
        $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        foreach ((array) ($plain[$table] ?? []) as $id => $fields) {
            $done = false;
            foreach ($fields as $col => $text) {
                $stmt = $pdo->prepare('UPDATE ' . $table . ' SET ' . $col . " = ? WHERE id = ? AND (" . $col . " IS NULL OR " . $col . " = '')");
                $stmt->execute([$text, jeab_contacts_drop_row_id($table, $id)]);
                $done = $done || $stmt->rowCount() > 0;
            }
            if (isset($toJson[$table][$id]) && isset($fields['customer_email'])) {
                // row_json-kopian tas bort bara när den är oförändrad.
                $sql = $mysql
                    ? "UPDATE inkorg_list SET row_json = JSON_REMOVE(row_json, '$.customer_email') WHERE id = ? AND JSON_UNQUOTE(JSON_EXTRACT(row_json, '$.customer_email')) = ?"
                    : "UPDATE inkorg_list SET row_json = json_remove(row_json, '$.customer_email') WHERE id = ? AND json_extract(row_json, '$.customer_email') = ?";
                $pdo->prepare($sql)->execute([(string) $id, strtolower(trim($fields['customer_email']))]);
            }
            $done ? $counts[$table]['restored']++ : $counts[$table]['skipped']++;
        }
    }

    // Markeringen tas bort efter att kolumnerna finns igen, så schemat inte hinner lägga till dem med andra typer.
    $marker = jeab_copy_columns_marker($pdo);
    foreach (array_keys($counts) as $table) {
        unset($marker[$table]);
    }
    jeab_contacts_drop_marker_save($pdo, $marker);
    $unfilled = jeab_contacts_drop_unfill($fillLog, $key);
    @unlink(jeab_contacts_drop_dir() . '/' . $batch . '.pending');
    if ($fillLog !== []) {
        @unlink(jeab_contacts_drop_dir() . '/' . $batch . '.fills');
    }

    return array_merge($base, ['written' => true, 'tables' => $counts, 'unfilled' => $unfilled]);
}
