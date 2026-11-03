<?php

declare(strict_types=1);

/**
 * Ångra radering av en order. ordrar.delete raderar direkt men sparar först en
 * kopia av raden i SQL-tabellen order_delete_undo. ordrar.restore läser kopian
 * (bara en kort stund efter raderingen), tar bort raderad-markeringen och lägger
 * tillbaka raden. Ingen JSON-fil skrivs eller raderas.
 */

/** Hur länge efter raderingen Ångra fungerar på servern. Knappen visas i ca 6 s. */
const JEAB_ORDRAR_DELETE_UNDO_SECONDS = 900;

function jeab_ordrar_delete_undo_pdo(?JeabStore $store = null): ?PDO
{
    $pdo = function_exists('jeab_purchase_sql_live') ? jeab_purchase_sql_live() : null;
    if (!$pdo instanceof PDO) {
        if (!$store instanceof JeabStore && function_exists('jeab_ordrar_resolve_store')) {
            $store = jeab_ordrar_resolve_store();
        }
        if ($store instanceof JeabStore && method_exists($store, 'pdo')) {
            $pdo = $store->pdo();
        }
    }
    if (!$pdo instanceof PDO) {
        return null;
    }
    jeab_ensure_order_delete_undo($pdo, (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite');

    return $pdo;
}

/** Idempotent. Ingen JSON-fil. */
function jeab_ensure_order_delete_undo(PDO $pdo, string $driver): void
{
    if ($driver === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS order_delete_undo (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                created_at DATETIME NOT NULL,
                reminder_id INT NOT NULL DEFAULT 0,
                actor VARCHAR(191) NOT NULL DEFAULT \'\',
                payload MEDIUMTEXT NOT NULL,
                restored_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY order_delete_undo_rid (reminder_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS order_delete_undo (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            reminder_id INTEGER NOT NULL DEFAULT 0,
            actor TEXT NOT NULL DEFAULT \'\',
            payload TEXT NOT NULL,
            restored_at TEXT NULL DEFAULT NULL
        )'
    );
}

/** Sparar raden före radering. Ett SQL-fel kastar, så raderingen inte går igenom utan Ångra. */
function jeab_ordrar_delete_undo_save(array $row, bool $hadVunnen, ?JeabStore $store = null): void
{
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0) {
        return;
    }
    $pdo = jeab_ordrar_delete_undo_pdo($store);
    if (!$pdo instanceof PDO) {
        // Utan SQL (äldre JSON-läge) går raderingen igenom men kan inte ångras.
        return;
    }
    $payload = json_encode(['row' => $row, 'vunnen' => $hadVunnen], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Kunde inte spara raderingen.');
    }
    $at = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    $actor = function_exists('jeab_ordrar_deleted_actor') ? jeab_ordrar_deleted_actor() : '';
    $pdo->prepare('INSERT INTO order_delete_undo (created_at, reminder_id, actor, payload) VALUES (?, ?, ?, ?)')
        ->execute([$at, $id, mb_substr($actor, 0, 191), $payload]);
}

/** Senaste ej återställda kopian inom ångra-fönstret, eller null. */
function jeab_ordrar_delete_undo_latest(int $reminderId, ?JeabStore $store = null): ?array
{
    $pdo = jeab_ordrar_delete_undo_pdo($store);
    if (!$pdo instanceof PDO || $reminderId <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT id, created_at, payload FROM order_delete_undo WHERE reminder_id = ? AND restored_at IS NULL ORDER BY id DESC LIMIT 1');
    $st->execute([$reminderId]);
    $hit = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($hit)) {
        return null;
    }
    $now = function_exists('jeab_now') ? strtotime(jeab_now()) : time();
    $at = strtotime((string) $hit['created_at']);
    if ($at === false || ($now !== false && $now - $at > JEAB_ORDRAR_DELETE_UNDO_SECONDS)) {
        return null;
    }
    $data = json_decode((string) $hit['payload'], true);
    if (!is_array($data) || !is_array($data['row'] ?? null)) {
        return null;
    }
    $data['undo_id'] = (int) $hit['id'];

    return $data;
}

/** ordrar.restore: lägger tillbaka den nyss raderade ordern. */
function jeab_ordrar_restore(array $input, ?JeabStore $store = null): array
{
    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('Ordern finns inte.');
    }
    if (!$store instanceof JeabStore && function_exists('jeab_ordrar_resolve_store')) {
        $store = jeab_ordrar_resolve_store();
    }
    if (is_array(jeab_purchase_get($id))) {
        throw new InvalidArgumentException('Ordern finns redan.');
    }
    $snap = jeab_ordrar_delete_undo_latest($id, $store);
    if ($snap === null) {
        throw new InvalidArgumentException('Raderingen går inte längre att ångra.');
    }
    $row = $snap['row'];
    $company = jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla';
    if (function_exists('jeab_company_allowed') && !jeab_company_allowed($company)) {
        throw new InvalidArgumentException('Inte behörig.');
    }
    jeab_ordrar_deleted_release($id);
    $row['reminder_id'] = $id;
    $saved = jeab_purchase_put($row, true);
    if (!empty($snap['vunnen']) && $store instanceof JeabStore) {
        $reminder = jeab_get_reminder($store, $id);
        if (is_array($reminder)) {
            $tags = jeab_parse_tags($reminder['tags'] ?? []);
            if (!in_array('vunnen', $tags, true)) {
                $tags[] = 'vunnen';
                jeab_update_reminder($store, $id, ['tags' => $tags]);
            }
        }
    }
    $pdo = jeab_ordrar_delete_undo_pdo($store);
    if ($pdo instanceof PDO) {
        $pdo->prepare('UPDATE order_delete_undo SET restored_at = ? WHERE id = ?')
            ->execute([function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'), (int) $snap['undo_id']]);
    }

    return $saved;
}
