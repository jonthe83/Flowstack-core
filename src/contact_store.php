<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Kontaktlagring för hookar. JSON idag. En SQL-klass kan ersätta implementationen
 * utan att hooken byter anrop: all, backup och insertMany.
 */
interface JeabContactStore
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array;

    /** Tidsstämplad kopia. Filnamn, eller tom sträng om kopian inte gick att skriva. */
    public function backup(): string;

    /**
     * Lägger in nya rader. Hoppar över id som redan finns.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    public function insertMany(array $rows): array;
}

function jeab_contact_store(bool $reset = false): JeabContactStore
{
    unset($reset);
    $custom = $GLOBALS['_JEAB_CONTACT_STORE'] ?? null;
    if ($custom instanceof JeabContactStore) {
        return $custom;
    }
    if (function_exists('jeab_kontakter_pdo_store')) {
        $sql = jeab_kontakter_pdo_store();
        if ($sql instanceof JeabContactStore) {
            return $sql;
        }
    }
    return new JeabJsonContactStore();
}

final class JeabJsonContactStore implements JeabContactStore
{
    public function all(): array
    {
        $rows = jeab_kontakter_load();
        $out = [];
        foreach ($rows as $id => $row) {
            if (is_array($row)) {
                $out[(string) $id] = $row;
            }
        }
        return $out;
    }

    public function backup(): string
    {
        $store = function_exists('jeab_store') ? jeab_store() : null;
        if (!$store instanceof JeabStore || !function_exists('jeab_kontakter_backup_snapshot')) {
            return '';
        }
        return jeab_kontakter_backup_snapshot($store);
    }

    public function insertMany(array $rows): array
    {
        $saved = jeab_kontakter_mutate(static function (array &$contacts) use ($rows): array {
            $ids = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                if ($id === '' || isset($contacts[$id])) {
                    continue;
                }
                $row['id'] = $id;
                $contacts[$id] = $row;
                $ids[] = $id;
            }
            return $ids;
        });
        return is_array($saved) ? array_values($saved) : [];
    }
}
