<?php

declare(strict_types=1);

if (!defined('JEAB_ROOT') && PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * jeab_migrate_<id>_module(), _rows() och _checksum().
 * jeab_migrate_module() och jeab_migrate_declare() bygger samma beskrivning.
 */

if (!function_exists('jeab_migrate_declare')) {
    /**
     * @param list<string> $columns
     * @param list<string> $depends
     * @return array{id:string,table:string,json:string,primary_key:string,columns:list<string>,depends:list<string>,schema:array{mysql:string,sqlite:string}}
     */
    function jeab_migrate_declare(
        string $id,
        string $table,
        string $jsonFile,
        string $primaryKey,
        array $columns,
        string $mysql,
        string $sqlite,
        array $depends = []
    ): array {
        return [
            'id' => $id,
            'table' => $table,
            'json' => $jsonFile,
            'primary_key' => $primaryKey,
            'columns' => array_values($columns),
            'depends' => array_values($depends),
            'schema' => [
                'mysql' => $mysql,
                'sqlite' => $sqlite,
            ],
        ];
    }
}

if (!function_exists('jeab_migrate_module')) {
    /**
     * @param list<string> $columns
     * @param list<string> $depends
     * @return array{id:string,table:string,json:string,primary_key:string,columns:list<string>,depends:list<string>,schema:array{mysql:string,sqlite:string}}
     */
    function jeab_migrate_module(
        string $id,
        string $table,
        string $jsonFile,
        string $primaryKey,
        array $columns,
        string $mysql,
        string $sqlite,
        array $depends = []
    ): array {
        return jeab_migrate_declare($id, $table, $jsonFile, $primaryKey, $columns, $mysql, $sqlite, $depends);
    }
}

if (!function_exists('jeab_migrate_text')) {
    function jeab_migrate_text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}

if (!function_exists('jeab_migrate_company')) {
    function jeab_migrate_company(mixed $value, bool $defaultJarfalla = true): ?string
    {
        $raw = function_exists('mb_strtolower')
            ? mb_strtolower(trim((string) $value), 'UTF-8')
            : strtolower(trim((string) $value));
        $raw = strtr($raw, ['ä' => 'a', 'å' => 'a', 'ö' => 'o']);
        if ($raw === 'jarfalla' || $raw === 'jeab') {
            return 'jarfalla';
        }
        if ($raw === 'malmo' || $raw === 'meab') {
            return 'malmo';
        }
        return $defaultJarfalla ? 'jarfalla' : null;
    }
}

if (!function_exists('jeab_migrate_names')) {
    /**
     * @param mixed $raw
     * @return list<string>
     */
    function jeab_migrate_names(mixed $raw): array
    {
        $names = [];
        if (is_array($raw)) {
            foreach ($raw as $item) {
                foreach (jeab_migrate_names($item) as $name) {
                    $names[] = $name;
                }
            }
            return $names;
        }
        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }
        if (str_starts_with($text, '[')) {
            $decoded = json_decode($text, true);
            if (is_array($decoded)) {
                return jeab_migrate_names($decoded);
            }
        }
        foreach (preg_split('/\s*,\s*/', $text) ?: [] as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $names[] = $part;
            }
        }
        return $names;
    }
}

if (!function_exists('jeab_migrate_names_join')) {
    function jeab_migrate_names_join(mixed $raw): ?string
    {
        $names = jeab_migrate_names($raw);
        return $names === [] ? null : implode(', ', $names);
    }
}

if (!function_exists('jeab_migrate_names_json')) {
    function jeab_migrate_names_json(mixed $raw): ?string
    {
        $names = jeab_migrate_names($raw);
        if ($names === []) {
            return null;
        }
        $json = json_encode(array_values($names), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }
}

if (!function_exists('jeab_migrate_tags_json')) {
    function jeab_migrate_tags_json(mixed $raw): ?string
    {
        $tags = [];
        $items = is_array($raw) ? $raw : (trim((string) $raw) === '' ? [] : preg_split('/\s*,\s*/', (string) $raw));
        foreach (is_array($items) ? $items : [] as $item) {
            $tag = trim((string) $item);
            if ($tag !== '' && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }
        if ($tags === []) {
            return null;
        }
        $json = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }
}

if (!function_exists('jeab_migrate_sort')) {
    function jeab_migrate_sort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = jeab_migrate_sort($item);
        }
        return $value;
    }
}

if (!function_exists('jeab_migrate_checksum')) {
    function jeab_migrate_checksum(mixed $value): string
    {
        $json = json_encode(jeab_migrate_sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? hash('sha256', $json) : '';
    }
}

if (!function_exists('jeab_migrate_project')) {
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    function jeab_migrate_project(array $rows, string $primaryKey, array $columns): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) ($row[$primaryKey] ?? '');
            if ($key === '' || $key === '0') {
                continue;
            }
            $projected = [];
            foreach ($columns as $column) {
                $projected[$column] = $row[$column] ?? null;
            }
            $byKey[$key] = $projected;
        }
        ksort($byKey, SORT_STRING);
        return array_values($byKey);
    }
}

if (!function_exists('jeab_migrate_redact')) {
    /** Tar bort personnummer-nycklar och värden som själva är ett personnummer. */
    function jeab_migrate_redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/personnummer|personal_?number|^pnr$|^ssn$/i', $key) === 1) {
                    continue;
                }
                $out[$key] = jeab_migrate_redact($item);
            }
            return $out;
        }
        if (!is_string($value)) {
            return $value;
        }
        $trimmed = trim($value);
        if (preg_match('/^(?:(?:19|20)\d{6}|\d{6})[-+]?\d{4}$/', $trimmed) === 1) {
            return null;
        }
        $clean = preg_replace('/\b(?:(?:19|20)\d{6}|\d{6})[-+]?\d{4}\b/u', '', $value);
        return is_string($clean) ? $clean : $value;
    }
}

if (!function_exists('jeab_migrate_payload')) {
    function jeab_migrate_payload(mixed $value): string
    {
        $json = json_encode(jeab_migrate_sort(jeab_migrate_redact($value)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '{}';
    }
}

if (!function_exists('jeab_migrate_datetime')) {
    function jeab_migrate_datetime(mixed $raw): ?string
    {
        if (is_int($raw) || (is_string($raw) && preg_match('/^\d+$/', trim($raw)) === 1)) {
            $ts = (int) $raw;
            return $ts > 0 ? date('Y-m-d H:i:s', $ts) : null;
        }
        $text = trim((string) $raw);
        if ($text === '') {
            return null;
        }
        $ts = strtotime($text);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}

if (!function_exists('jeab_migrate_doc_schema')) {
    /** @return array{mysql:string,sqlite:string} */
    function jeab_migrate_doc_schema(string $table, string $keyColumn, string $keyMysql, string $keySqlite): array
    {
        return [
            'mysql' => <<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
    {$keyColumn} {$keyMysql} NOT NULL,
    payload LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY ({$keyColumn})
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'sqlite' => <<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
    {$keyColumn} {$keySqlite} PRIMARY KEY,
    payload TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL,
        ];
    }
}

if (!function_exists('jeab_migrate_doc_rows')) {
    /**
     * @param array<string, mixed> $source
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    function jeab_migrate_doc_rows(array $source, string $keyColumn, string $key, array $columns): array
    {
        $body = $source;
        unset($body['updated_at']);
        $row = [
            $keyColumn => $key,
            'payload' => jeab_migrate_payload($body),
            'updated_at' => jeab_migrate_datetime($source['updated_at'] ?? ($source['at'] ?? null)) ?? '1970-01-01 00:00:00',
        ];
        return jeab_migrate_project([$row], $keyColumn, $columns);
    }
}

if (!function_exists('jeab_migrate_list')) {
    /**
     * @param array<string, mixed> $source
     * @return list<array<string, mixed>>
     */
    function jeab_migrate_list(array $source, string $key): array
    {
        if (isset($source[$key]) && is_array($source[$key])) {
            $list = $source[$key];
        } else {
            $list = $source;
        }
        if (!array_is_list($list)) {
            $rows = [];
            foreach ($list as $id => $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (!isset($row['id']) || $row['id'] === '' || $row['id'] === null) {
                    $row['id'] = (string) $id;
                }
                $rows[] = $row;
            }
            return $rows;
        }
        $rows = [];
        foreach ($list as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}

/**
 * Kundkopior som contacts.drop_customer_fields har tagit bort hoppas över, så
 * importen inte skriver till en kolumn som saknas och kontrollsumman jämför samma fält.
 * Filtrerar per kolumn som faktiskt saknas, så även ett halvfärdigt DROP (utan markering) täcks.
 * Finns inte tabellen än, eller ingen databas: alla kolumner.
 *
 * @param list<string> $columns
 * @return list<string>
 */
function jeab_migrate_copy_filter(string $table, array $columns): array
{
    if (!function_exists('jeab_copy_columns') || !function_exists('jeab_copy_column_present') || jeab_copy_columns($table) === []) {
        return $columns;
    }
    $pdo = $GLOBALS['_JEAB_MIGRATE_PDO'] ?? (function_exists('jeab_data_pdo') ? jeab_data_pdo() : null);
    if (!$pdo instanceof PDO || !jeab_migrate_table_exists($pdo, $table)) {
        return $columns;
    }

    return array_values(array_filter($columns, static fn (string $c): bool => jeab_copy_column_present($pdo, $table, $c)));
}

function jeab_migrate_table_exists(PDO $pdo, string $table): bool
{
    if (preg_match('/\A[a-z_]+\z/', $table) !== 1) {
        return false;
    }
    // Bara ja cachas; en tabell som saknas kan skapas senare i samma körning.
    $key = spl_object_id($pdo) . "\0" . $table;
    if (isset($GLOBALS['_JEAB_MIGRATE_TABLE_SEEN'][$key])) {
        return true;
    }
    try {
        $rows = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll()
            : $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    } catch (Throwable) {
        return false;
    }
    if (is_array($rows) && $rows !== []) {
        $GLOBALS['_JEAB_MIGRATE_TABLE_SEEN'][$key] = true;

        return true;
    }

    return false;
}
