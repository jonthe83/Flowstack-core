<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * contacts.merge_duplicates och contacts.normalize_addresses.
 *
 * Automatisk sammanslagning är tillåten efter torrkörning, med ånger.
 * inbox.contacts_from_threads (#1073) slår fortfarande inte ihop kontakter.
 *
 * Matchning: samma normaliserade gata och samma ort, inom samma bolag. Aldrig över bolagsgräns.
 * Gatan är husnummer med ensam bokstav ihopskriven («38 A» = «38A», «38 BRO» lämnas),
 * skiftläge, mellanslag och punkter. Tom gata eller tom ort grupperas aldrig.
 * Telefon och mejl är aldrig gruppnyckel. De fyller bara tomma fält.
 * Har båda ett postnummer och jeab_norm_postal skiljer dem åt är det inte samma grupp,
 * inte ens som overflow. Saknas postnummer på ena sidan får den följa med.
 * Okänt bolag grupperas på den trimmade råtexten, inte på tomt bolag.
 *
 * Master är den med flest ifyllda fält (namn, mejl 1–3, mobil 1–2, gata, postnr, ort,
 * personnummer räknas men skrivs aldrig ut). Lika antal: äldst created_at, sedan lägst id.
 *
 * Dubblettens data fyller bara masterns tomma fält. Extra mejl går till email, email2, email3
 * i den ordningen. Extra mobil går till phone och sedan phone2. Ett mejl eller en mobil som
 * redan finns i en plats är inte nytt. Ryms inte alla nya mejl/mobil, eller har dubbletten
 * ett annat icke-tomt värde där mastern redan har ett annat (namn bara när båda är riktiga,
 * personnummer, postnummer via jeab_norm_postal, fastighetsbeteckning), hamnar dubbletten i
 * overflow_ids och slås inte ihop alls. Övrigt fylls om mastern är tom och stoppar inte.
 * Gata och ort i samma grupp delar adressnyckel och är ingen krock.
 *
 * Länkar med contact_id flyttas till mastern. En snapshot-nod (snapshot, snapshots,
 * snapshot_json) skrivs aldrig om, oavsett frysflagga. Övriga frysta noder (frozen,
 * _frozen, _party_frozen) skrivs inte om. Toppens contact_id flyttas även när ordern
 * är fryst, så ordern inte tappar sin länk. purchases-payload skrivs aldrig om i
 * sin helhet: bara contact_id patchas på plats med json_set. Blir en payload
 * kortare eller tappar en nyckel avbryts paret, även när radantalet är oförändrat.
 *
 * Varje master/dubblett körs i en transaktion: ångerrader, länkflytt, kontaktfält, radering.
 * Misslyckas en länkflytt rullas paret tillbaka. Dubbletten ligger kvar och fälten skrivs inte.
 * Radering går via DELETE och kontrolleras med COUNT. Annars rullas paret tillbaka.
 * contact_emails med samma adress på båda kan inte peka om. Den gamla raden tas bort
 * och ångern sätter in den igen. En flyttad rad ångras bara på just den raden.
 *
 * contacts.merge_duplicates_undo och contacts.normalize_addresses_undo är torra som standard.
 * Skrivande ånger kräver confirm:undo. Svaret har antal, id och fältnamn, inga värden.
 *
 * dry=0 kräver att jeab_kontakter_use_sql() är sant. Annars blir svaret
 * sql_store_required (409) och kontakter.json skrivs inte.
 *
 * jeab_pdo_transaction som byter anslutning tappar låset. Resten av paren
 * avbryts med busy och en rad i felloggen.
 */

function jeab_contacts_merge_dispatch(array $input): array
{
    $action = (string) ($input['action'] ?? '');
    $undo = jeab_contacts_merge_wants_undo($input);
    if ($action === 'contacts.normalize_addresses_undo' || ($action === 'contacts.normalize_addresses' && $undo)) {
        return jeab_contacts_normalize_addresses_undo($input);
    }
    if ($action === 'contacts.normalize_addresses') {
        return jeab_contacts_normalize_addresses($input);
    }
    if ($action === 'contacts.merge_duplicates_undo' || ($action === 'contacts.merge_duplicates' && $undo)) {
        return jeab_contacts_merge_duplicates_undo($input);
    }
    if ($action === 'contacts.merge_duplicates') {
        return jeab_contacts_merge_duplicates($input);
    }

    return jeab_contacts_merge_fail('Okänd åtgärd.', 400, $action);
}

function jeab_contacts_merge_wants_undo(array $input): bool
{
    if (!array_key_exists('undo', $input)) {
        return false;
    }
    $value = $input['undo'];

    return $value === true || $value === 1 || $value === '1' || $value === 'true';
}

function jeab_contacts_merge_is_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $value = $input['dry'];
    if ($value === null || $value === '') {
        return true;
    }

    return !($value === false || $value === 0 || $value === '0' || $value === 'false');
}

/** @return array<string, mixed> */
function jeab_contacts_merge_fail(string $error, int $status, string $action): array
{
    return [
        'ok' => false,
        'error' => $error,
        'status' => $status,
        'action' => $action,
        'written' => false,
    ];
}

function jeab_contacts_merge_pdo(): ?PDO
{
    $store = function_exists('jeab_store') ? jeab_store() : null;
    if ($store instanceof JeabPdoStore) {
        return $store->pdo();
    }

    return null;
}

function jeab_contacts_merge_driver(PDO $pdo): string
{
    return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
}

function jeab_contacts_merge_qi(string $driver, string $name): string
{
    if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
        return '';
    }

    return $driver === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';
}

function jeab_contacts_merge_ensure(PDO $pdo): void
{
    if (function_exists('jeab_ensure_contacts_merge_undo_table')) {
        jeab_ensure_contacts_merge_undo_table($pdo, jeab_contacts_merge_driver($pdo));
    }
}

function jeab_contacts_merge_forget(string $id = ''): void
{
    unset($GLOBALS['_JEAB_KONTAKTER_MEM'], $GLOBALS['_JEAB_KONTAKTER_MEM_KEY']);
    if ($id !== '' && function_exists('jeab_contacts_sole_cache_forget')) {
        jeab_contacts_sole_cache_forget($id);
    }
}

/** @return array<string, true> */
function jeab_contacts_merge_columns(PDO $pdo, string $table): array
{
    $have = [];
    try {
        if (jeab_contacts_merge_driver($pdo) === 'mysql') {
            $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
            $key = 'Field';
        } else {
            $stmt = $pdo->query('PRAGMA table_info("' . $table . '")');
            $key = 'name';
        }
    } catch (Throwable) {
        return [];
    }
    foreach ($stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        if (is_array($row) && isset($row[$key]) && is_string($row[$key])) {
            $have[$row[$key]] = true;
        }
    }

    return $have;
}

/** @return list<string> */
function jeab_contacts_merge_tables(PDO $pdo): array
{
    $names = [];
    try {
        if (jeab_contacts_merge_driver($pdo) === 'mysql') {
            $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        } else {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
        }
    } catch (Throwable) {
        return [];
    }
    foreach ($stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_NUM) : [] as $row) {
        if (is_array($row) && isset($row[0]) && is_string($row[0])) {
            $names[] = $row[0];
        }
    }

    return $names;
}

function jeab_contacts_merge_skip_table(string $name): bool
{
    if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
        return true;
    }
    if (str_ends_with($name, '_audit') || str_starts_with($name, 'contacts_write_snapshot')) {
        return true;
    }

    return in_array($name, [
        'contacts',
        'contacts_merge_undo',
        'inbox_link_contacts_undo',
    ], true);
}

/** @param array<string, true> $cols @param list<string> $pk @return list<string> */
function jeab_contacts_merge_pk(array $cols, array $pk): array
{
    if (in_array('email', $pk, true) && in_array('contact_id', $pk, true)) {
        return ['email'];
    }
    foreach (['id', 'reminder_id', 'thread_id', 'email'] as $prefer) {
        if (in_array($prefer, $pk, true) || ($pk === [] && isset($cols[$prefer]))) {
            return [$prefer];
        }
    }

    return $pk !== [] ? [$pk[0]] : [];
}

/** @return list<string> */
function jeab_contacts_merge_primary(PDO $pdo, string $table): array
{
    $pk = [];
    try {
        if (jeab_contacts_merge_driver($pdo) === 'mysql') {
            $stmt = $pdo->query('SHOW KEYS FROM `' . $table . "` WHERE Key_name = 'PRIMARY'");
            $rows = $stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            usort($rows, static function (array $a, array $b): int {
                return ((int) ($a['Seq_in_index'] ?? 0)) <=> ((int) ($b['Seq_in_index'] ?? 0));
            });
            foreach ($rows as $row) {
                if (isset($row['Column_name']) && is_string($row['Column_name'])) {
                    $pk[] = $row['Column_name'];
                }
            }

            return $pk;
        }
        $stmt = $pdo->query('PRAGMA table_info("' . $table . '")');
        $ranked = [];
        foreach ($stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rank = (int) ($row['pk'] ?? 0);
            if ($rank > 0 && isset($row['name']) && is_string($row['name'])) {
                $ranked[$rank] = $row['name'];
            }
        }
        ksort($ranked);

        return array_values($ranked);
    } catch (Throwable) {
        return [];
    }
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_merge_sql_store(string $action): ?array
{
    if (function_exists('jeab_kontakter_use_sql') && jeab_kontakter_use_sql()) {
        return null;
    }

    return jeab_contacts_merge_fail('sql_store_required', 409, $action);
}

function jeab_contacts_merge_lock_lost(PDO $locked): bool
{
    if (function_exists('jeab_pdo_replaced') && jeab_pdo_replaced($locked) instanceof PDO) {
        return true;
    }
    $store = function_exists('jeab_store') ? jeab_store() : null;
    if ($store instanceof JeabPdoStore) {
        $current = $store->pdo();
        if ($current instanceof PDO && $current !== $locked) {
            return true;
        }
    }

    return false;
}

function jeab_contacts_merge_log_lock_lost(): void
{
    $message = 'contacts.merge_duplicates återanslutning låset borta';
    error_log($message);
    if (function_exists('jeab_log_app_error')) {
        jeab_log_app_error('contacts', $message, [
            'skip_request' => true,
            'no_webhook' => true,
        ]);
    }
}

function jeab_contacts_merge_duplicates(array $input): array
{
    $action = 'contacts.merge_duplicates';
    $dry = jeab_contacts_merge_is_dry($input);
    if (!$dry) {
        $sqlRequired = jeab_contacts_merge_sql_store($action);
        if ($sqlRequired !== null) {
            return $sqlRequired;
        }
    }
    $pdo = jeab_contacts_merge_pdo();
    if (!$pdo instanceof PDO) {
        return jeab_contacts_merge_fail('Ingen lagring.', 400, $action);
    }
    if (!$dry) {
        if (!function_exists('jeab_flag') || !jeab_flag('CONTACTS_AUTO_MERGE')) {
            return jeab_contacts_merge_fail('CONTACTS_AUTO_MERGE är av.', 403, $action);
        }
        if (($input['confirm'] ?? null) !== 'merge') {
            return jeab_contacts_merge_fail('Kräver confirm:merge.', 400, $action);
        }
    }
    jeab_contacts_merge_ensure($pdo);
    $plan = jeab_contacts_merge_plan($pdo);
    $groups = [];
    $duplicates = 0;
    $overflow = 0;
    $fields = 0;
    $linkOps = [];
    foreach ($plan as $group) {
        $groups[] = [
            'master_id' => $group['master_id'],
            'duplicate_ids' => $group['duplicate_ids'],
            'overflow_ids' => $group['overflow_ids'],
        ];
        $duplicates += count($group['duplicate_ids']);
        $overflow += count($group['overflow_ids']);
        $fields += count($group['changes']);
        foreach ($group['duplicate_ids'] as $dup) {
            foreach (jeab_contacts_merge_link_ops($pdo, $dup, $group['master_id']) as $op) {
                $linkOps[] = $op;
            }
        }
    }
    $counts = [
        'groups' => count($groups),
        'duplicates' => $duplicates,
        'overflow' => $overflow,
        'merged' => 0,
        'links' => count($linkOps),
        'refused' => 0,
        'fields' => $fields,
    ];
    if ($dry) {
        return [
            'ok' => true,
            'action' => $action,
            'dry' => true,
            'written' => false,
            'groups' => $groups,
            'counts' => $counts,
            'status' => 200,
        ];
    }
    if (!jeab_contacts_merge_run_lock($pdo)) {
        return jeab_contacts_merge_busy($action);
    }
    try {
        $undoId = bin2hex(random_bytes(8));
        $linksDone = 0;
        $fieldDone = 0;
        $merged = 0;
        $refused = 0;
        $failed = [];
        $lost = false;
        foreach ($plan as $group) {
            foreach ($group['duplicate_ids'] as $dup) {
                if ($lost || jeab_contacts_merge_lock_lost($pdo)) {
                    $lost = true;
                    $failed[] = ['id' => (string) $dup, 'reason' => 'busy'];
                    continue;
                }
                try {
                    $one = jeab_pdo_transaction($pdo, static function (PDO $tx) use ($undoId, $group, $dup): array {
                        // Tester kastar 2006 en gång så jeab_pdo_transaction byter anslutning.
                        $pending = $GLOBALS['_JEAB_CONTACTS_MERGE_GONE_ONCE'] ?? null;
                        if ($pending instanceof PDOException) {
                            unset($GLOBALS['_JEAB_CONTACTS_MERGE_GONE_ONCE']);
                            throw $pending;
                        }

                        return jeab_contacts_merge_pair($tx, $undoId, (string) $group['master_id'], (string) $dup);
                    });
                } catch (JeabContactsMergePairException $e) {
                    jeab_contacts_merge_forget();
                    $reason = $e->getMessage();
                    if (!in_array($reason, ['link', 'changed', 'refused'], true)) {
                        $reason = 'link';
                    }
                    if ($reason === 'refused') {
                        $refused++;
                    }
                    $failed[] = ['id' => (string) $dup, 'reason' => $reason];
                    if (jeab_contacts_merge_lock_lost($pdo)) {
                        $lost = true;
                    }
                    continue;
                } catch (Throwable $e) {
                    if (!jeab_contacts_merge_lock_lost($pdo)) {
                        throw $e;
                    }
                    jeab_contacts_merge_forget();
                    $lost = true;
                    $failed[] = ['id' => (string) $dup, 'reason' => 'busy'];
                    continue;
                }
                jeab_contacts_merge_forget((string) $dup);
                $linksDone += (int) ($one['links'] ?? 0);
                $fieldDone += (int) ($one['fields'] ?? 0);
                $merged++;
                if (jeab_contacts_merge_lock_lost($pdo)) {
                    $lost = true;
                }
            }
        }
        if ($lost) {
            jeab_contacts_merge_log_lock_lost();
        }
        usort($failed, static function (array $a, array $b): int {
            return strcmp($a['id'], $b['id']);
        });
        $counts['merged'] = $merged;
        $counts['links'] = $linksDone;
        $counts['refused'] = $refused;
        $counts['fields'] = $fieldDone;

        $out = [
            'ok' => !$lost,
            'action' => $action,
            'dry' => false,
            'written' => $lost ? $merged > 0 : true,
            'undo_id' => $undoId,
            'groups' => $groups,
            'failed_ids' => $failed,
            'counts' => $counts,
            'status' => $lost ? 409 : 200,
        ];
        if ($lost) {
            $out['error'] = 'busy';
            $out['busy'] = true;
        }

        return $out;
    } finally {
        if (function_exists('jeab_db_release_lock')) {
            jeab_db_release_lock($pdo, 'jeab_contacts_merge');
        }
    }
}

final class JeabContactsMergePairException extends RuntimeException
{
}

/** @return array<string, mixed> */
function jeab_contacts_merge_busy(string $action): array
{
    return [
        'ok' => false,
        'error' => 'busy',
        'busy' => true,
        'status' => 409,
        'action' => $action,
        'written' => false,
    ];
}

function jeab_contacts_merge_order_count(PDO $pdo): int
{
    try {
        $names = jeab_contacts_merge_tables($pdo);
    } catch (Throwable) {
        return 0;
    }
    if (!in_array('purchases', $names, true)) {
        return 0;
    }
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Längd och nycklar per purchases.payload. Jämförelsen sker efter länkflytten.
 *
 * @return array<string, array{len:int, keys:list<string>}>
 */
function jeab_contacts_merge_purchase_payloads(PDO $pdo): array
{
    try {
        $names = jeab_contacts_merge_tables($pdo);
    } catch (Throwable) {
        return [];
    }
    if (!in_array('purchases', $names, true)) {
        return [];
    }
    $cols = jeab_contacts_merge_columns($pdo, 'purchases');
    if (!isset($cols['payload'], $cols['reminder_id'])) {
        return [];
    }
    try {
        $rows = $pdo->query('SELECT reminder_id, payload FROM purchases')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['reminder_id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $raw = $row['payload'] === null ? '' : (string) $row['payload'];
        $decoded = json_decode($raw, true);
        $keys = jeab_contacts_merge_payload_keys(is_array($decoded) ? $decoded : []);
        sort($keys, SORT_STRING);
        $out[$id] = ['len' => strlen($raw), 'keys' => $keys];
    }

    return $out;
}

/**
 * @param array<string, array{len:int, keys:list<string>}> $before
 * @param array<string, array{len:int, keys:list<string>}> $after
 * @param array<string, int> $patched exakt längd för rader som patchats med json_set
 */
function jeab_contacts_merge_purchase_payloads_harm(array $before, array $after, array $patched = []): bool
{
    foreach ($before as $id => $shape) {
        if (!isset($after[$id])) {
            return true;
        }
        $id = (string) $id;
        if (isset($patched[$id]) ? $after[$id]['len'] !== $patched[$id] : $after[$id]['len'] < $shape['len']) {
            return true;
        }
        $left = array_fill_keys($shape['keys'], true);
        foreach ($after[$id]['keys'] as $key) {
            unset($left[$key]);
        }
        if ($left !== []) {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function jeab_contacts_merge_payload_keys(mixed $node, string $prefix = ''): array
{
    if (!is_array($node)) {
        return [];
    }
    $out = [];
    foreach ($node as $key => $value) {
        $name = (string) $key;
        $path = $prefix === '' ? $name : $prefix . '.' . $name;
        $out[] = $path;
        if (is_array($value)) {
            foreach (jeab_contacts_merge_payload_keys($value, $path) as $child) {
                $out[] = $child;
            }
        }
    }

    return $out;
}

function jeab_contacts_merge_run_lock(PDO $pdo): bool
{
    if (!function_exists('jeab_db_get_lock') || !function_exists('jeab_db_lock_name') || !function_exists('jeab_db_lock_bag')) {
        return false;
    }
    $name = 'jeab_contacts_merge';
    $key = spl_object_id($pdo) . ':' . jeab_db_lock_name($name);
    $bag = &jeab_db_lock_bag();
    if (!empty($bag[$key])) {
        return false;
    }

    return jeab_db_get_lock($pdo, $name);
}

/**
 * Ett par. Kast vid länkfel, ändrad dubblett eller nekad radering rullar tillbaka allt i paret.
 *
 * @return array{links:int,fields:int}
 */
function jeab_contacts_merge_pair(PDO $pdo, string $undoId, string $masterId, string $dup): array
{
    $seen = jeab_contacts_merge_locked_json($pdo, $dup);
    if ($seen === null) {
        throw new JeabContactsMergePairException('changed');
    }
    $links = 0;
    $ordersBefore = jeab_contacts_merge_order_count($pdo);
    $payloadBefore = jeab_contacts_merge_purchase_payloads($pdo);
    $payloadPatched = [];
    foreach (jeab_contacts_merge_link_ops($pdo, $dup, $masterId) as $op) {
        if ($op['tbl'] === 'purchases' && ($op['mode'] ?? '') === 'json' && $op['col'] === 'payload') {
            // json_set kan normalisera blanktecken eller korta ett längre id. Den exakta längden är känd.
            $payloadPatched[(string) $op['row_key']] = strlen((string) $op['new']);
        }
        if (!jeab_contacts_merge_run_link($pdo, $undoId, $op)) {
            throw new JeabContactsMergePairException('link');
        }
        $links++;
    }
    if (jeab_contacts_merge_order_count($pdo) < $ordersBefore
        || jeab_contacts_merge_purchase_payloads_harm($payloadBefore, jeab_contacts_merge_purchase_payloads($pdo), $payloadPatched)) {
        throw new JeabContactsMergePairException('order');
    }
    $master = jeab_contacts_merge_fetch($pdo, $masterId);
    $dupRow = jeab_contacts_merge_fetch($pdo, $dup);
    if (!is_array($master) || !is_array($dupRow)) {
        throw new JeabContactsMergePairException('changed');
    }
    $step = jeab_contacts_merge_absorb($master, $dupRow);
    if (!$step['ok']) {
        throw new JeabContactsMergePairException('changed');
    }
    $changes = jeab_contacts_merge_diff($master, $step['row']);
    foreach ($changes as $change) {
        jeab_contacts_merge_log($pdo, [
            'undo_id' => $undoId,
            'kind' => 'contact_update',
            'tbl' => 'contacts',
            'row_key' => $masterId,
            'col' => $change['col'],
            'old_value' => $change['old'],
            'new_value' => $change['new'],
        ]);
    }
    if ($changes !== []) {
        jeab_kontakter_mutate(static function (array &$contacts) use ($masterId, $changes): void {
            if (!isset($contacts[$masterId]) || !is_array($contacts[$masterId])) {
                return;
            }
            $row = $contacts[$masterId];
            foreach ($changes as $change) {
                $row = jeab_contacts_merge_apply($row, $change['col'], $change['new']);
            }
            $contacts[$masterId] = $row;
        });
        jeab_contacts_merge_mark_kind($pdo, $undoId, 'contact_update');
    }
    $again = jeab_contacts_merge_locked_json($pdo, $dup);
    if ($again === null || $again !== $seen) {
        throw new JeabContactsMergePairException('changed');
    }
    $old = jeab_contacts_merge_json($dupRow);
    jeab_contacts_merge_log($pdo, [
        'undo_id' => $undoId,
        'kind' => 'contact_delete',
        'tbl' => 'contacts',
        'row_key' => $dup,
        'col' => 'row_json',
        'old_value' => $old,
        'new_value' => '',
    ]);
    $pdo->prepare('DELETE FROM contacts WHERE id = ?')->execute([$dup]);
    $check = $pdo->prepare('SELECT COUNT(*) FROM contacts WHERE id = ?');
    $check->execute([$dup]);
    if ((int) $check->fetchColumn() !== 0) {
        throw new JeabContactsMergePairException('refused');
    }
    jeab_contacts_merge_mark_row($pdo, $undoId, 'contact_delete', $dup);

    return ['links' => $links, 'fields' => count($changes)];
}

function jeab_contacts_merge_locked_json(PDO $pdo, string $id): ?string
{
    $sql = 'SELECT row_json FROM contacts WHERE id = ?';
    if (jeab_contacts_merge_driver($pdo) === 'mysql') {
        $sql .= ' FOR UPDATE';
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();
    } catch (Throwable) {
        return null;
    }
    if ($value === false || $value === null) {
        return null;
    }

    return (string) $value;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_merge_duplicates_undo(array $input): array
{
    return jeab_contacts_merge_undo($input, 'contacts.merge_duplicates_undo', ['link', 'contact_update', 'contact_delete']);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_normalize_addresses(array $input): array
{
    $action = 'contacts.normalize_addresses';
    $dry = jeab_contacts_merge_is_dry($input);
    if (!$dry) {
        $sqlRequired = jeab_contacts_merge_sql_store($action);
        if ($sqlRequired !== null) {
            return $sqlRequired;
        }
    }
    $pdo = jeab_contacts_merge_pdo();
    if (!$pdo instanceof PDO) {
        return jeab_contacts_merge_fail('Ingen lagring.', 400, $action);
    }
    if (!$dry && ($input['confirm'] ?? null) !== 'normalize') {
        return jeab_contacts_merge_fail('Kräver confirm:normalize.', 400, $action);
    }
    jeab_contacts_merge_ensure($pdo);
    $todo = [];
    foreach (jeab_contacts_merge_load($pdo) as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        $street = (string) ($row['street'] ?? '');
        if ($id === '' || !is_string($row['street'] ?? null)) {
            continue;
        }
        $next = jeab_contacts_normalize_next_street($street);
        if ($next === $street) {
            continue;
        }
        $todo[$id] = ['old' => $street, 'new' => $next];
    }
    ksort($todo, SORT_STRING);
    $ids = array_keys($todo);
    if ($dry) {
        return [
            'ok' => true,
            'action' => $action,
            'dry' => true,
            'written' => false,
            'contact_ids' => $ids,
            'counts' => ['would' => count($ids), 'changed' => 0],
            'undo_id' => '',
            'status' => 200,
        ];
    }
    $undoId = $ids === [] ? '' : bin2hex(random_bytes(8));
    foreach ($todo as $id => $change) {
        jeab_contacts_merge_log($pdo, [
            'undo_id' => $undoId,
            'kind' => 'address_normalize',
            'tbl' => 'contacts',
            'row_key' => (string) $id,
            'col' => 'street',
            'old_value' => $change['old'],
            'new_value' => $change['new'],
        ]);
    }
    if ($todo !== []) {
        jeab_kontakter_mutate(static function (array &$contacts) use ($todo): void {
            foreach ($todo as $id => $change) {
                if (!isset($contacts[$id]) || !is_array($contacts[$id])) {
                    continue;
                }
                $contacts[$id]['street'] = $change['new'];
                if (function_exists('jeab_kontakt_street_key')) {
                    $contacts[$id]['street_key'] = jeab_kontakt_street_key($change['new']);
                }
            }
        });
        jeab_contacts_merge_mark_kind($pdo, $undoId, 'address_normalize');
        jeab_contacts_merge_forget();
    }
    $changed = 0;
    foreach ($todo as $id => $change) {
        $fresh = jeab_contacts_merge_fetch($pdo, (string) $id);
        if (!is_array($fresh)) {
            continue;
        }
        if ((string) ($fresh['street'] ?? '') === (string) $change['new']) {
            $changed++;
        }
    }

    return [
        'ok' => true,
        'action' => $action,
        'dry' => false,
        'written' => $changed > 0,
        'contact_ids' => $ids,
        'counts' => ['would' => count($ids), 'changed' => $changed],
        'undo_id' => $undoId,
        'status' => 200,
    ];
}

/**
 * Gatan som skrivningen skulle spara. Bara gatutexten, inte street_key.
 */
function jeab_contacts_normalize_next_street(string $street): string
{
    if (function_exists('jeab_contact_apply_street_letter')) {
        $applied = jeab_contact_apply_street_letter(['street' => $street]);

        return (string) ($applied['street'] ?? $street);
    }

    return function_exists('jeab_norm_street_letter') ? jeab_norm_street_letter($street) : $street;
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_contacts_normalize_addresses_undo(array $input): array
{
    return jeab_contacts_merge_undo($input, 'contacts.normalize_addresses_undo', ['address_normalize']);
}

/**
 * @param list<string> $kinds
 * @return array<string, mixed>
 */
function jeab_contacts_merge_undo(array $input, string $action, array $kinds): array
{
    $dry = jeab_contacts_merge_is_dry($input);
    if (!$dry) {
        $sqlRequired = jeab_contacts_merge_sql_store($action);
        if ($sqlRequired !== null) {
            return $sqlRequired;
        }
    }
    $undoId = trim((string) ($input['undo_id'] ?? ''));
    if (preg_match('/^[a-f0-9]{16}$/', $undoId) !== 1) {
        return jeab_contacts_merge_fail('Ogiltigt undo_id.', 400, $action);
    }
    if (!$dry && (string) ($input['confirm'] ?? '') !== 'undo') {
        return jeab_contacts_merge_fail('Kräver confirm:undo.', 400, $action);
    }
    $pdo = jeab_contacts_merge_pdo();
    if (!$pdo instanceof PDO) {
        return jeab_contacts_merge_fail('Ingen lagring.', 400, $action);
    }
    jeab_contacts_merge_ensure($pdo);
    $rows = jeab_contacts_merge_undo_rows($pdo, $undoId, $kinds);
    if ($rows === []) {
        return jeab_contacts_merge_fail('Ångerloggen saknas.', 404, $action);
    }
    foreach ($rows as $row) {
        if (trim((string) ($row['undone_at'] ?? '')) !== '') {
            return jeab_contacts_merge_fail('Ångringen är redan gjord.', 409, $action);
        }
    }
    usort($rows, static function (array $a, array $b): int {
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });
    $restored = [];
    $skipped = [];
    $linkN = 0;
    $fieldN = 0;
    $fieldBack = [];
    // Radering först, sedan länkar, sist fält. Mejlsynken vid fältånger
    // raderar masterns contact_emails. Länkarna måste peka tillbaka innan dess.
    foreach (['contact_delete', 'link', 'field'] as $phase) {
    foreach ($rows as $row) {
        $kind = (string) ($row['kind'] ?? '');
        $isField = $kind === 'contact_update' || $kind === 'address_normalize';
        if ($phase === 'contact_delete' && $kind !== 'contact_delete') {
            continue;
        }
        if ($phase === 'link' && $kind !== 'link') {
            continue;
        }
        if ($phase === 'field' && !$isField) {
            continue;
        }
        $key = (string) ($row['row_key'] ?? '');
        $col = (string) ($row['col'] ?? '');
        $status = (string) ($row['status'] ?? '');
        $old = $row['old_value'];
        $new = $row['new_value'];
        $oldText = $old === null ? '' : (string) $old;
        $newText = $new === null ? '' : (string) $new;
        if ($kind === 'contact_delete') {
            $exists = jeab_contacts_merge_fetch($pdo, $key) !== null;
            if (!$exists) {
                if (!$dry && $oldText !== '') {
                    $decoded = json_decode($oldText, true);
                    if (!is_array($decoded)) {
                        $skipped[] = ['row_key' => $key, 'col' => $col, 'reason' => 'refused'];
                        continue;
                    }
                    $decoded['id'] = $key;
                    jeab_kontakter_mutate(static function (array &$contacts) use ($decoded, $key): void {
                        if (!isset($contacts[$key])) {
                            $contacts[$key] = $decoded;
                        }
                    });
                    jeab_contacts_merge_forget($key);
                    if (jeab_contacts_merge_fetch($pdo, $key) === null) {
                        $skipped[] = ['row_key' => $key, 'col' => $col, 'reason' => 'refused'];
                        continue;
                    }
                }
                $restored[] = $key;
                continue;
            }
            $current = jeab_contacts_merge_fetch($pdo, $key);
            $currentJson = is_array($current) ? jeab_contacts_merge_json($current) : '';
            if ($status === 'pending' && $currentJson === $oldText) {
                $skipped[] = ['row_key' => $key, 'col' => $col, 'reason' => 'pending'];
            } else {
                $skipped[] = ['row_key' => $key, 'col' => $col, 'reason' => 'changed'];
            }
            continue;
        }
        if ($kind === 'contact_update' || $kind === 'address_normalize') {
            $currentRow = jeab_contacts_merge_fetch($pdo, $key);
            if (!is_array($currentRow)) {
                $skipped[] = ['row_key' => $key, 'col' => $col, 'reason' => 'changed'];
                continue;
            }
            $current = $kind === 'address_normalize'
                ? (string) ($currentRow['street'] ?? '')
                : jeab_contacts_merge_read($currentRow, $col);
            $reason = jeab_contacts_merge_undo_reason($status, $current, $oldText, $newText);
            if ($reason !== '') {
                $skipped[] = ['row_key' => $key, 'col' => $col, 'reason' => $reason];
                continue;
            }
            if ($kind === 'address_normalize') {
                if (!$dry) {
                    $currentRow['street'] = $oldText;
                    if (function_exists('jeab_kontakt_street_key')) {
                        $currentRow['street_key'] = jeab_kontakt_street_key($oldText);
                    }
                    jeab_contacts_merge_write_json($pdo, $key, $currentRow);
                    jeab_contacts_merge_forget($key);
                }
                $restored[] = $key;
                continue;
            }
            $fieldBack[$key][] = ['col' => $col, 'value' => $oldText];
            $fieldN++;
            continue;
        }
        if ($kind === 'link') {
            $reason = jeab_contacts_merge_undo_link($pdo, $row, $dry);
            if ($reason !== '') {
                $skipped[] = ['row_key' => $key, 'col' => $col, 'reason' => $reason];
                continue;
            }
            $linkN++;
        }
    }
    }
    if (!$dry && $fieldBack !== []) {
        jeab_kontakter_mutate(static function (array &$contacts) use ($fieldBack): void {
            foreach ($fieldBack as $id => $changes) {
                if (!isset($contacts[$id]) || !is_array($contacts[$id])) {
                    continue;
                }
                $row = $contacts[$id];
                foreach ($changes as $change) {
                    $row = jeab_contacts_merge_apply($row, $change['col'], $change['value']);
                }
                $contacts[$id] = $row;
            }
        });
        jeab_contacts_merge_forget();
    }
    if (!$dry) {
        $stamp = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE contacts_merge_undo SET undone_at = ? WHERE undo_id = ?')->execute([$stamp, $undoId]);
    }
    $restored = array_values(array_unique(array_map(
        static fn (string $id): string => jeab_contacts_merge_public_id($id),
        $restored
    )));
    $restored = array_values(array_filter($restored, static fn (string $id): bool => $id !== ''));
    sort($restored, SORT_STRING);
    foreach ($skipped as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $skipped[$i]['row_key'] = jeab_contacts_merge_public_id((string) ($row['row_key'] ?? ''));
    }

    return [
        'ok' => true,
        'action' => $action,
        'dry' => $dry,
        'written' => !$dry,
        'undo_id' => $undoId,
        'restored' => $restored,
        'counts' => [
            'contacts' => count($restored),
            'links' => $linkN,
            'fields' => $fieldN,
            'skipped' => count($skipped),
        ],
        'skipped' => $skipped,
        'status' => 200,
    ];
}

/** Id eller tomt. Mejl och text med mellanslag lämnas ute. */
function jeab_contacts_merge_public_id(string $key): string
{
    if ($key === '' || str_contains($key, '@') || preg_match('/\s/u', $key) === 1) {
        return '';
    }
    if (preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $key) !== 1) {
        return '';
    }

    return $key;
}

function jeab_contacts_merge_undo_reason(string $status, string $current, string $old, string $new): string
{
    if ($current === $new) {
        return '';
    }
    if ($status === 'pending' && $current === $old) {
        return 'pending';
    }

    return 'changed';
}

/** @param array<string, mixed> $log */
function jeab_contacts_merge_undo_link(PDO $pdo, array $log, bool $dry): string
{
    $table = (string) ($log['tbl'] ?? '');
    $col = (string) ($log['col'] ?? '');
    $key = (string) ($log['row_key'] ?? '');
    $status = (string) ($log['status'] ?? '');
    $old = $log['old_value'] === null ? '' : (string) $log['old_value'];
    $new = $log['new_value'] === null ? '' : (string) $log['new_value'];
    if ($table === 'contact_emails' && $col === 'contact_id') {
        return jeab_contacts_merge_undo_email($pdo, $key, $old, $new, $dry);
    }
    if (jeab_contacts_merge_skip_table($table) && $table !== 'contact_emails') {
        return 'changed';
    }
    if (preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1 || preg_match('/^[a-z][a-z0-9_]*$/', $col) !== 1) {
        return 'changed';
    }
    $pkList = jeab_contacts_merge_pk(jeab_contacts_merge_columns($pdo, $table), jeab_contacts_merge_primary($pdo, $table));
    $pk = $pkList[0] ?? '';
    if ($pk === '' || $key === '') {
        return 'changed';
    }
    $driver = jeab_contacts_merge_driver($pdo);
    $qt = jeab_contacts_merge_qi($driver, $table);
    $qc = jeab_contacts_merge_qi($driver, $col);
    $qp = jeab_contacts_merge_qi($driver, $pk);
    if ($qt === '' || $qc === '' || $qp === '') {
        return 'changed';
    }
    try {
        $stmt = $pdo->prepare('SELECT ' . $qc . ' FROM ' . $qt . ' WHERE ' . $qp . ' = ?');
        $stmt->execute([$key]);
        $currentRaw = $stmt->fetchColumn();
    } catch (Throwable) {
        return 'changed';
    }
    if ($currentRaw === false) {
        return 'changed';
    }
    $current = $currentRaw === null ? '' : (string) $currentRaw;
    $reason = jeab_contacts_merge_undo_reason($status, $current, $old, $new);
    if ($reason !== '' || $dry) {
        return $reason;
    }
    try {
        $upd = $pdo->prepare('UPDATE ' . $qt . ' SET ' . $qc . ' = ? WHERE ' . $qp . ' = ?');
        $upd->execute([$old, $key]);
    } catch (Throwable) {
        return 'refused';
    }

    return '';
}

/** @param list<string> $kinds @return list<array<string, mixed>> */
function jeab_contacts_merge_undo_rows(PDO $pdo, string $undoId, array $kinds): array
{
    $stmt = $pdo->prepare('SELECT id, undo_id, kind, tbl, row_key, col, old_value, new_value, status, undone_at FROM contacts_merge_undo WHERE undo_id = ? ORDER BY id ASC');
    $stmt->execute([$undoId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!in_array((string) ($row['kind'] ?? ''), $kinds, true)) {
            continue;
        }
        $out[] = $row;
    }

    return $out;
}

/**
 * Gruppnyckel: normaliserad gata + ort. Tom om gata eller ort saknas.
 * Punkt blir mellanslag innan bokstavsregeln. En telefon eller ett mejl i gatufältet är ingen nyckel.
 */
function jeab_contacts_merge_address_text(string $value, bool $street): string
{
    $value = str_replace(['.', "\u{00B7}", "\u{FF0E}", "\u{2024}"], ' ', $value);
    if (!function_exists('jeab_contact_address_part')) {
        return '';
    }
    $part = jeab_contact_address_part($value, $street);
    if ($part === '' || !$street) {
        return $part;
    }
    if (str_contains($part, '@') || preg_match('/\p{L}/u', $part) !== 1) {
        return '';
    }

    return $part;
}

function jeab_contacts_merge_address_key(string $street, string $city): string
{
    $street = jeab_contacts_merge_address_text($street, true);
    $city = jeab_contacts_merge_address_text($city, false);
    if ($street === '' || $city === '') {
        return '';
    }

    return $street . '|' . $city;
}

/** @param array<string, mixed> $row */
function jeab_contacts_merge_postal_norm(array $row): string
{
    $raw = trim((string) ($row['postal_code'] ?? ''));
    if ($raw === '') {
        return '';
    }

    return function_exists('jeab_norm_postal') ? jeab_norm_postal($raw) : $raw;
}

/**
 * Olika ifyllda postnummer är olika kluster. Tomt postnummer följer med bara när
 * hela hinken har ett enda postnummer, så det inte binder ihop två postnummer.
 *
 * @param list<array<string, mixed>> $members
 * @return list<list<array<string, mixed>>>
 */
function jeab_contacts_merge_postal_clusters(array $members): array
{
    $byPostal = [];
    $blanks = [];
    foreach ($members as $row) {
        if (!is_array($row)) {
            continue;
        }
        $postal = jeab_contacts_merge_postal_norm($row);
        if ($postal === '') {
            $blanks[] = $row;
            continue;
        }
        $byPostal[$postal][] = $row;
    }
    if ($byPostal === []) {
        return $blanks === [] ? [] : [$blanks];
    }
    if (count($byPostal) === 1) {
        $only = array_values($byPostal)[0];

        return [array_merge($only, $blanks)];
    }
    $clusters = array_values($byPostal);
    if (count($blanks) >= 2) {
        $clusters[] = $blanks;
    }

    return $clusters;
}

/**
 * @param list<array<string, mixed>> $members
 * @return array{master_id:string,duplicate_ids:list<string>,overflow_ids:list<string>,changes:list<array{col:string,old:string,new:string}>}
 */
function jeab_contacts_merge_group(array $members): array
{
    usort($members, static function (array $a, array $b): int {
        $score = jeab_contacts_merge_score($b) <=> jeab_contacts_merge_score($a);
        if ($score !== 0) {
            return $score;
        }
        $created = strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
        if ($created !== 0) {
            return $created;
        }

        return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
    });
    $master = $members[0];
    $masterId = (string) $master['id'];
    $rest = array_slice($members, 1);
    usort($rest, static function (array $a, array $b): int {
        return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
    });
    $working = $master;
    $dups = [];
    $overflow = [];
    foreach ($rest as $dup) {
        $step = jeab_contacts_merge_absorb($working, $dup);
        if (!$step['ok']) {
            $overflow[] = (string) $dup['id'];
            continue;
        }
        $working = $step['row'];
        $dups[] = (string) $dup['id'];
    }
    sort($dups, SORT_STRING);
    sort($overflow, SORT_STRING);

    return [
        'master_id' => $masterId,
        'duplicate_ids' => $dups,
        'overflow_ids' => $overflow,
        'changes' => jeab_contacts_merge_diff($master, $working),
    ];
}

/**
 * @return list<array{master_id:string,duplicate_ids:list<string>,overflow_ids:list<string>,changes:list<array{col:string,old:string,new:string}>}>
 */
function jeab_contacts_merge_plan(PDO $pdo): array
{
    $buckets = [];
    foreach (jeab_contacts_merge_load($pdo) as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $row['id'] = $id;
        $key = jeab_contacts_merge_company_key((string) ($row['company'] ?? ''));
        $addr = jeab_contacts_merge_address_key((string) ($row['street'] ?? ''), (string) ($row['city'] ?? ''));
        if ($addr === '') {
            continue;
        }
        $buckets[$key . "\n" . $addr][] = $row;
    }
    $groups = [];
    foreach ($buckets as $members) {
        foreach (jeab_contacts_merge_postal_clusters($members) as $cluster) {
            if (count($cluster) < 2) {
                continue;
            }
            $groups[] = jeab_contacts_merge_group($cluster);
        }
    }
    usort($groups, static function (array $a, array $b): int {
        return strcmp($a['master_id'], $b['master_id']);
    });

    return $groups;
}

function jeab_contacts_merge_company_key(string $raw): string
{
    $norm = function_exists('jeab_normalize_company') ? jeab_normalize_company($raw) : null;
    if (is_string($norm) && $norm !== '') {
        return $norm;
    }
    $trim = trim($raw);
    if ($trim === '') {
        return '';
    }

    return function_exists('mb_strtolower') ? mb_strtolower($trim, 'UTF-8') : strtolower($trim);
}

/** @return list<array<string, mixed>> */
function jeab_contacts_merge_load(PDO $pdo): array
{
    $cols = jeab_contacts_merge_columns($pdo, 'contacts');
    if (!isset($cols['id'], $cols['row_json'])) {
        return [];
    }
    $driver = jeab_contacts_merge_driver($pdo);
    $select = ['id', 'row_json'];
    foreach (['company', 'created_at'] as $col) {
        if (isset($cols[$col])) {
            $select[] = $col;
        }
    }
    $sql = [];
    foreach ($select as $col) {
        $quoted = jeab_contacts_merge_qi($driver, $col);
        if ($quoted === '') {
            return [];
        }
        $sql[] = $quoted;
    }
    try {
        $stmt = $pdo->query('SELECT ' . implode(', ', $sql) . ' FROM ' . jeab_contacts_merge_qi($driver, 'contacts'));
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach ($stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $db) {
        if (!is_array($db)) {
            continue;
        }
        $decoded = json_decode((string) ($db['row_json'] ?? ''), true);
        if (!is_array($decoded)) {
            continue;
        }
        $decoded['id'] = (string) ($db['id'] ?? ($decoded['id'] ?? ''));
        $columnCompany = trim((string) ($db['company'] ?? ''));
        if ($columnCompany !== '') {
            $decoded['company'] = $columnCompany;
        }
        $jsonCreated = trim((string) ($decoded['created_at'] ?? ''));
        $columnCreated = trim((string) ($db['created_at'] ?? ''));
        if ($jsonCreated === '' && $columnCreated !== '') {
            $decoded['created_at'] = $columnCreated;
        }
        $out[] = $decoded;
    }

    return $out;
}

/** @return array<string, mixed>|null */
function jeab_contacts_merge_fetch(PDO $pdo, string $id): ?array
{
    if ($id === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT id, row_json FROM contacts WHERE id = ?');
        $stmt->execute([$id]);
        $db = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($db)) {
        return null;
    }
    $decoded = json_decode((string) ($db['row_json'] ?? ''), true);
    if (!is_array($decoded)) {
        return null;
    }
    $decoded['id'] = (string) ($db['id'] ?? $id);

    return $decoded;
}

/** @param array<string, mixed> $row */
function jeab_contacts_merge_json(array $row): string
{
    $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return is_string($json) ? $json : '';
}

/** @param array<string, mixed> $row */
function jeab_contacts_merge_write_json(PDO $pdo, string $id, array $row): void
{
    $json = jeab_contacts_merge_json($row);
    $cols = jeab_contacts_merge_columns($pdo, 'contacts');
    $sets = ['row_json = ?'];
    $params = [$json];
    if (isset($cols['street_key'])) {
        $sets[] = 'street_key = ?';
        $params[] = (string) ($row['street_key'] ?? '');
    }
    if (isset($cols['updated_at'])) {
        $sets[] = 'updated_at = ?';
        $params[] = function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s');
    }
    $params[] = $id;
    $pdo->prepare('UPDATE contacts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
}

function jeab_contacts_merge_score(array $row): int
{
    $n = 0;
    foreach (['name', 'email', 'email2', 'email3', 'phone', 'phone2', 'street', 'postal_code', 'city', 'personnummer'] as $col) {
        if (jeab_contacts_merge_read($row, $col) !== '') {
            $n++;
        }
    }

    return $n;
}

/** @param array<string, mixed> $row @return list<array<string, mixed>> */
function jeab_contacts_merge_owners(array $row): array
{
    $owners = [];
    foreach (is_array($row['owners'] ?? null) ? $row['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $owners[] = $owner;
        }
    }

    return $owners;
}

/** @param list<array<string, mixed>> $owners */
function jeab_contacts_merge_contact_index(array $owners): int
{
    foreach ($owners as $i => $owner) {
        if (!empty($owner['contact'])) {
            return (int) $i;
        }
    }

    return 0;
}

function jeab_contacts_merge_fold(string $value): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function jeab_contacts_merge_pnr_key(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (function_exists('jeab_norm_pnr_digits')) {
        $digits = jeab_norm_pnr_digits($raw);
        if ($digits !== '') {
            return $digits;
        }
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';

    return $digits !== '' ? $digits : $raw;
}

function jeab_contacts_merge_primary_phone(array $row): string
{
    $fallback = '';
    foreach (jeab_contacts_merge_owners($row) as $owner) {
        $phone = function_exists('jeab_kontakt_phone') ? jeab_kontakt_phone((string) ($owner['phone'] ?? '')) : trim((string) ($owner['phone'] ?? ''));
        if (!empty($owner['contact'])) {
            return $phone;
        }
        if ($fallback === '' && $phone !== '') {
            $fallback = $phone;
        }
    }
    if ($fallback === '') {
        $fallback = function_exists('jeab_kontakt_phone') ? jeab_kontakt_phone((string) ($row['phone'] ?? '')) : trim((string) ($row['phone'] ?? ''));
    }

    return $fallback;
}

/** @return list<string> */
function jeab_contacts_merge_email_values(array $row): array
{
    $out = [];
    $push = static function (string $raw) use (&$out): void {
        $norm = function_exists('jeab_kontakt_email') ? jeab_kontakt_email($raw) : trim($raw);
        if ($norm === '' || in_array($norm, $out, true)) {
            return;
        }
        $out[] = $norm;
    };
    if (function_exists('jeab_kontakt_row_email')) {
        $push(jeab_kontakt_row_email($row));
    }
    $push((string) ($row['email2'] ?? ''));
    $push((string) ($row['email3'] ?? ''));
    foreach (jeab_contacts_merge_owners($row) as $owner) {
        $push((string) ($owner['email'] ?? ''));
    }
    $push((string) ($row['email'] ?? ''));

    return $out;
}

/** @return list<string> */
function jeab_contacts_merge_phone_values(array $row): array
{
    $out = [];
    $push = static function (string $raw) use (&$out): void {
        $norm = function_exists('jeab_kontakt_phone') ? jeab_kontakt_phone($raw) : trim($raw);
        if ($norm === '' || in_array($norm, $out, true)) {
            return;
        }
        $out[] = $norm;
    };
    $push(jeab_contacts_merge_primary_phone($row));
    $push((string) ($row['phone2'] ?? ''));
    foreach (jeab_contacts_merge_owners($row) as $owner) {
        $push((string) ($owner['phone'] ?? ''));
        $push((string) ($owner['phone2'] ?? ''));
    }
    $push((string) ($row['phone'] ?? ''));
    $push((string) ($row['mobile'] ?? ''));

    return $out;
}

function jeab_contacts_merge_name_real(string $name, array $row): bool
{
    $emails = jeab_contacts_merge_email_values($row);
    if (function_exists('jeab_contacts_sole_name_is_real')) {
        return jeab_contacts_sole_name_is_real($name, $emails);
    }

    return trim($name) !== '' && !str_contains($name, '@');
}

function jeab_contacts_merge_read(array $row, string $col): string
{
    if ($col === 'name') {
        return function_exists('jeab_kontakt_row_name') ? trim(jeab_kontakt_row_name($row)) : trim((string) ($row['name'] ?? ''));
    }
    if ($col === 'email') {
        return function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($row) : trim((string) ($row['email'] ?? ''));
    }
    if ($col === 'email2' || $col === 'email3') {
        $raw = (string) ($row[$col] ?? '');

        return function_exists('jeab_kontakt_email') ? jeab_kontakt_email($raw) : trim($raw);
    }
    if ($col === 'phone') {
        return jeab_contacts_merge_primary_phone($row);
    }
    if ($col === 'phone2') {
        $raw = (string) ($row['phone2'] ?? '');

        return function_exists('jeab_kontakt_phone') ? jeab_kontakt_phone($raw) : trim($raw);
    }
    if ($col === 'personnummer') {
        $owners = jeab_contacts_merge_owners($row);
        if ($owners === []) {
            return '';
        }

        return trim((string) ($owners[jeab_contacts_merge_contact_index($owners)]['personnummer'] ?? ''));
    }
    if ($col === 'owner2_name') {
        $owners = jeab_contacts_merge_owners($row);

        return trim((string) ($owners[1]['name'] ?? ''));
    }
    if ($col === 'owner2_personnummer') {
        $owners = jeab_contacts_merge_owners($row);

        return trim((string) ($owners[1]['personnummer'] ?? ''));
    }
    if ($col === 'postal_code') {
        return trim((string) ($row['postal_code'] ?? ''));
    }

    return trim((string) ($row[$col] ?? ''));
}

/** @param array<string, mixed> $row @return array<string, mixed> */
function jeab_contacts_merge_apply(array $row, string $col, string $value): array
{
    if ($col === 'name' || $col === 'email' || $col === 'phone' || $col === 'personnummer') {
        $owners = jeab_contacts_merge_owners($row);
        if ($owners === []) {
            $owners[] = ['name' => '', 'email' => '', 'phone' => '', 'contact' => true];
        }
        $i = jeab_contacts_merge_contact_index($owners);
        if ($col === 'name') {
            $owners[$i]['name'] = $value;
            $row['name'] = $value;
        } elseif ($col === 'email') {
            $email = function_exists('jeab_kontakt_email') ? jeab_kontakt_email($value) : $value;
            $owners[$i]['email'] = $email;
            $row['email'] = $email;
        } elseif ($col === 'phone') {
            $phone = function_exists('jeab_kontakt_phone') ? jeab_kontakt_phone($value) : $value;
            $owners[$i]['phone'] = $phone;
            $row['phone'] = $phone;
        } else {
            $owners[$i]['personnummer'] = $value;
        }
        $row['owners'] = $owners;

        return $row;
    }
    if ($col === 'owner2_name' || $col === 'owner2_personnummer') {
        $owners = jeab_contacts_merge_owners($row);
        if (!isset($owners[1]) || !is_array($owners[1])) {
            $owners[1] = ['name' => '', 'email' => '', 'phone' => '', 'contact' => false];
        }
        if ($col === 'owner2_name') {
            $owners[1]['name'] = $value;
        } else {
            $owners[1]['personnummer'] = $value;
        }
        $row['owners'] = $owners;

        return $row;
    }
    if ($col === 'email2' || $col === 'email3') {
        $row[$col] = function_exists('jeab_kontakt_email') ? jeab_kontakt_email($value) : $value;

        return $row;
    }
    if ($col === 'phone2') {
        $row['phone2'] = function_exists('jeab_kontakt_phone') ? jeab_kontakt_phone($value) : $value;

        return $row;
    }
    if ($col === 'street') {
        $street = function_exists('jeab_norm_street_letter') ? jeab_norm_street_letter($value) : $value;
        $row['street'] = $street;
        if (function_exists('jeab_kontakt_street_key')) {
            $row['street_key'] = jeab_kontakt_street_key($street);
        }

        return $row;
    }
    $row[$col] = $value;

    return $row;
}

/**
 * @param array<string, mixed> $master
 * @param array<string, mixed> $dup
 * @return array{ok:bool,row:array<string, mixed>}
 */
function jeab_contacts_merge_absorb(array $master, array $dup): array
{
    $pairs = [
        ['name', 'name'],
        ['owner2_name', 'owner2_name'],
    ];
    foreach ($pairs as $pair) {
        $left = jeab_contacts_merge_read($master, $pair[0]);
        $right = jeab_contacts_merge_read($dup, $pair[1]);
        if ($left !== '' && $right !== '' && jeab_contacts_merge_fold($left) !== jeab_contacts_merge_fold($right)) {
            if (jeab_contacts_merge_name_real($left, $master) && jeab_contacts_merge_name_real($right, $dup)) {
                return ['ok' => false, 'row' => $master];
            }
        }
    }
    foreach (['personnummer', 'owner2_personnummer'] as $col) {
        $left = jeab_contacts_merge_read($master, $col);
        $right = jeab_contacts_merge_read($dup, $col);
        if ($left !== '' && $right !== '' && jeab_contacts_merge_pnr_key($left) !== jeab_contacts_merge_pnr_key($right)) {
            return ['ok' => false, 'row' => $master];
        }
    }
    $leftPostal = jeab_contacts_merge_postal_norm($master);
    $rightPostal = jeab_contacts_merge_postal_norm($dup);
    if ($leftPostal !== '' && $rightPostal !== '' && $leftPostal !== $rightPostal) {
        return ['ok' => false, 'row' => $master];
    }
    $leftFast = jeab_contacts_merge_read($master, 'fastighetsbeteckning');
    $rightFast = jeab_contacts_merge_read($dup, 'fastighetsbeteckning');
    if ($leftFast !== '' && $rightFast !== '' && $leftFast !== $rightFast) {
        return ['ok' => false, 'row' => $master];
    }
    $leftKey = jeab_contacts_merge_address_key(jeab_contacts_merge_read($master, 'street'), jeab_contacts_merge_read($master, 'city'));
    $rightKey = jeab_contacts_merge_address_key(jeab_contacts_merge_read($dup, 'street'), jeab_contacts_merge_read($dup, 'city'));
    if ($leftKey !== '' && $rightKey !== '' && $leftKey !== $rightKey) {
        return ['ok' => false, 'row' => $master];
    }
    $emailSlots = ['email', 'email2', 'email3'];
    $phoneSlots = ['phone', 'phone2'];
    $haveMail = [];
    $emptyMail = [];
    foreach ($emailSlots as $slot) {
        $value = jeab_contacts_merge_read($master, $slot);
        if ($value === '') {
            $emptyMail[] = $slot;
        } else {
            $haveMail[$value] = true;
        }
    }
    $newMail = [];
    foreach (jeab_contacts_merge_email_values($dup) as $email) {
        if (!isset($haveMail[$email])) {
            $newMail[] = $email;
        }
    }
    if (count($newMail) > count($emptyMail)) {
        return ['ok' => false, 'row' => $master];
    }
    $havePhone = [];
    $emptyPhone = [];
    foreach ($phoneSlots as $slot) {
        $value = jeab_contacts_merge_read($master, $slot);
        if ($value === '') {
            $emptyPhone[] = $slot;
        } else {
            $havePhone[$value] = true;
        }
    }
    $newPhone = [];
    foreach (jeab_contacts_merge_phone_values($dup) as $phone) {
        if (!isset($havePhone[$phone])) {
            $newPhone[] = $phone;
        }
    }
    if (count($newPhone) > count($emptyPhone)) {
        return ['ok' => false, 'row' => $master];
    }
    $row = $master;
    foreach (['name', 'owner2_name', 'personnummer', 'owner2_personnummer', 'postal_code', 'fastighetsbeteckning', 'ovrigt', 'street', 'city'] as $col) {
        if (jeab_contacts_merge_read($row, $col) === '') {
            $incoming = jeab_contacts_merge_read($dup, $col);
            if ($incoming !== '') {
                $row = jeab_contacts_merge_apply($row, $col, $incoming);
            }
        }
    }
    foreach ($newMail as $i => $email) {
        $row = jeab_contacts_merge_apply($row, $emptyMail[$i], $email);
    }
    foreach ($newPhone as $i => $phone) {
        $row = jeab_contacts_merge_apply($row, $emptyPhone[$i], $phone);
    }

    return ['ok' => true, 'row' => $row];
}

/**
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @return list<array{col:string,old:string,new:string}>
 */
function jeab_contacts_merge_diff(array $before, array $after): array
{
    $changes = [];
    foreach (['name', 'email', 'email2', 'email3', 'phone', 'phone2', 'street', 'postal_code', 'city', 'fastighetsbeteckning', 'personnummer', 'owner2_name', 'owner2_personnummer', 'ovrigt'] as $col) {
        $old = jeab_contacts_merge_read($before, $col);
        $new = jeab_contacts_merge_read($after, $col);
        if ($old !== $new) {
            $changes[] = ['col' => $col, 'old' => $old, 'new' => $new];
        }
    }

    return $changes;
}

/** @param array<string, true> $cols @return list<string> */
function jeab_contacts_merge_json_cols(array $cols): array
{
    $out = [];
    foreach (array_keys($cols) as $name) {
        if (!is_string($name)) {
            continue;
        }
        if ($name === 'payload' || $name === 'values' || str_ends_with($name, '_json')) {
            $out[] = $name;
        }
    }

    return $out;
}

function jeab_contacts_merge_email_count(PDO $pdo, string $email, string $contactId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM contact_emails WHERE email = ? AND contact_id = ?');
    $stmt->execute([$email, $contactId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Delad adress går inte att peka om. Raden tas bort och ångern sätter in den.
 *
 * @param array{tbl:string,row_key:string,col:string,old:string,new:string,pk:string,mode:string} $op
 */
function jeab_contacts_merge_run_email_link(PDO $pdo, int $logId, array $op): bool
{
    $email = $op['row_key'];
    $old = $op['old'];
    $new = $op['new'];
    if ($email === '' || $old === '' || $new === '' || $old === $new) {
        return false;
    }
    try {
        $pdo->prepare('UPDATE contact_emails SET contact_id = ? WHERE email = ? AND contact_id = ?')->execute([$new, $email, $old]);
    } catch (Throwable) {
    }
    try {
        $stillOld = jeab_contacts_merge_email_count($pdo, $email, $old);
        $hasNew = jeab_contacts_merge_email_count($pdo, $email, $new);
    } catch (Throwable) {
        return false;
    }
    if ($stillOld === 0 && $hasNew > 0) {
        $pdo->prepare('UPDATE contacts_merge_undo SET status = ? WHERE id = ?')->execute(['done', $logId]);

        return true;
    }
    if ($stillOld > 0 && $hasNew > 0) {
        try {
            $pdo->prepare('DELETE FROM contact_emails WHERE email = ? AND contact_id = ?')->execute([$email, $old]);
            if (jeab_contacts_merge_email_count($pdo, $email, $old) !== 0) {
                return false;
            }
            $pdo->prepare('UPDATE contacts_merge_undo SET new_value = ?, status = ? WHERE id = ?')->execute(['', 'done', $logId]);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    return false;
}

/** Tom new_value betyder att raden togs bort. Annars pekas bara den raden tillbaka. */
function jeab_contacts_merge_undo_email(PDO $pdo, string $email, string $old, string $new, bool $dry): string
{
    if ($email === '' || $old === '') {
        return 'changed';
    }
    try {
        $hasOld = jeab_contacts_merge_email_count($pdo, $email, $old);
        $hasNew = $new === '' ? 0 : jeab_contacts_merge_email_count($pdo, $email, $new);
    } catch (Throwable) {
        return 'changed';
    }
    if ($new === '') {
        if ($hasOld > 0) {
            return '';
        }
        if ($dry) {
            return '';
        }
        try {
            $pdo->prepare('INSERT INTO contact_emails (email, contact_id) VALUES (?, ?)')->execute([$email, $old]);
        } catch (Throwable) {
            return 'refused';
        }

        return '';
    }
    if ($hasOld > 0 && $hasNew === 0) {
        return '';
    }
    if ($hasOld > 0 && $hasNew > 0) {
        if ($dry) {
            return '';
        }
        try {
            $pdo->prepare('DELETE FROM contact_emails WHERE email = ? AND contact_id = ?')->execute([$email, $new]);
        } catch (Throwable) {
            return 'refused';
        }

        return '';
    }
    if ($hasNew > 0 && $hasOld === 0) {
        if ($dry) {
            return '';
        }
        try {
            $pdo->prepare('UPDATE contact_emails SET contact_id = ? WHERE email = ? AND contact_id = ?')->execute([$old, $email, $new]);
        } catch (Throwable) {
            try {
                if (jeab_contacts_merge_email_count($pdo, $email, $old) > 0) {
                    $pdo->prepare('DELETE FROM contact_emails WHERE email = ? AND contact_id = ?')->execute([$email, $new]);

                    return '';
                }
            } catch (Throwable) {
            }

            return 'refused';
        }

        return '';
    }

    return 'changed';
}

/**
 * @return list<array{tbl:string,row_key:string,col:string,old:string,new:string,pk:string,mode:string}>
 */
function jeab_contacts_merge_link_ops(PDO $pdo, string $from, string $to): array
{
    if ($from === '' || $to === '' || $from === $to) {
        return [];
    }
    $ops = [];
    $driver = jeab_contacts_merge_driver($pdo);
    foreach (jeab_contacts_merge_tables($pdo) as $table) {
        if ($table !== 'contact_emails' && jeab_contacts_merge_skip_table($table)) {
            continue;
        }
        $cols = jeab_contacts_merge_columns($pdo, $table);
        $jsonCols = jeab_contacts_merge_json_cols($cols);
        $hasCid = isset($cols['contact_id']);
        if (!$hasCid && $jsonCols === []) {
            continue;
        }
        $pkList = jeab_contacts_merge_pk($cols, jeab_contacts_merge_primary($pdo, $table));
        $pk = $pkList[0] ?? '';
        if ($pk === '' || !isset($cols[$pk])) {
            continue;
        }
        $qt = jeab_contacts_merge_qi($driver, $table);
        $qp = jeab_contacts_merge_qi($driver, $pk);
        if ($qt === '' || $qp === '') {
            continue;
        }
        $seen = [];
        $pull = static function (string $sql, array $params) use ($pdo, &$seen, $pk): void {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $key = (string) ($row[$pk] ?? '');
                    if ($key === '' || isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = $row;
                }
            } catch (Throwable) {
            }
        };
        if ($hasCid) {
            $qc = jeab_contacts_merge_qi($driver, 'contact_id');
            $pull('SELECT * FROM ' . $qt . ' WHERE ' . $qc . ' = ?', [$from]);
        }
        foreach ($jsonCols as $col) {
            $qc = jeab_contacts_merge_qi($driver, $col);
            if ($qc === '') {
                continue;
            }
            // Filtrera exakt id i PHP. Understreck i id är jokertecken i LIKE.
            $pull('SELECT * FROM ' . $qt . ' WHERE ' . $qc . ' LIKE ?', ['%"contact_id"%']);
        }
        foreach ($seen as $key => $row) {
            if ($hasCid && trim((string) ($row['contact_id'] ?? '')) === $from) {
                $ops[] = [
                    'tbl' => $table,
                    'row_key' => (string) $key,
                    'col' => 'contact_id',
                    'old' => $from,
                    'new' => $to,
                    'pk' => $pk,
                    'mode' => 'column',
                ];
            }
            foreach ($jsonCols as $col) {
                $raw = $row[$col] ?? null;
                if (!is_string($raw) || $raw === '') {
                    continue;
                }
                if ($table === 'purchases') {
                    $purchase = jeab_contacts_merge_purchase_json_op($pdo, $col, $pk, (string) $key, $raw, $from, $to);
                    if ($purchase !== null) {
                        $ops[] = $purchase;
                    }
                    continue;
                }
                $next = jeab_contacts_merge_rewrite_column($col, $raw, $from, $to);
                if ($next === null) {
                    continue;
                }
                $ops[] = [
                    'tbl' => $table,
                    'row_key' => (string) $key,
                    'col' => $col,
                    'old' => $raw,
                    'new' => $next,
                    'pk' => $pk,
                    'mode' => 'json',
                ];
            }
        }
    }

    return $ops;
}

/** @param array{tbl:string,row_key:string,col:string,old:string,new:string,pk:string,mode:string} $op */
function jeab_contacts_merge_run_link(PDO $pdo, string $undoId, array $op): bool
{
    $logId = jeab_contacts_merge_log($pdo, [
        'undo_id' => $undoId,
        'kind' => 'link',
        'tbl' => $op['tbl'],
        'row_key' => $op['row_key'],
        'col' => $op['col'],
        'old_value' => $op['old'],
        'new_value' => $op['new'],
    ]);
    if ($logId <= 0) {
        return false;
    }
    $driver = jeab_contacts_merge_driver($pdo);
    $qt = jeab_contacts_merge_qi($driver, $op['tbl']);
    $qc = jeab_contacts_merge_qi($driver, $op['col']);
    $qp = jeab_contacts_merge_qi($driver, $op['pk']);
    if ($qt === '' || $qc === '' || $qp === '') {
        return false;
    }
    if ($op['tbl'] === 'contact_emails' && $op['mode'] === 'column' && $op['col'] === 'contact_id') {
        return jeab_contacts_merge_run_email_link($pdo, $logId, $op);
    }
    if ($op['tbl'] === 'purchases' && $op['mode'] === 'json') {
        return jeab_contacts_merge_run_purchase_json($pdo, $logId, $op);
    }
    try {
        $sql = 'UPDATE ' . $qt . ' SET ' . $qc . ' = ? WHERE ' . $qp . ' = ? AND ' . $qc . ' = ?';
        $pdo->prepare($sql)->execute([$op['new'], $op['row_key'], $op['old']]);
        $check = $pdo->prepare('SELECT ' . $qc . ' FROM ' . $qt . ' WHERE ' . $qp . ' = ?');
        $check->execute([$op['row_key']]);
        $now = $check->fetchColumn();
    } catch (Throwable) {
        return false;
    }
    if ((string) $now !== $op['new']) {
        return false;
    }
    $pdo->prepare('UPDATE contacts_merge_undo SET status = ? WHERE id = ?')->execute(['done', $logId]);

    return true;
}

/** @param array<string, mixed> $row */
function jeab_contacts_merge_log(PDO $pdo, array $row): int
{
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO contacts_merge_undo (undo_id, created_at, kind, tbl, row_key, col, old_value, new_value, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (string) ($row['undo_id'] ?? ''),
            function_exists('jeab_now') ? jeab_now() : date('Y-m-d H:i:s'),
            (string) ($row['kind'] ?? ''),
            (string) ($row['tbl'] ?? ''),
            (string) ($row['row_key'] ?? ''),
            (string) ($row['col'] ?? ''),
            $row['old_value'] ?? null,
            $row['new_value'] ?? null,
            'pending',
        ]);
        $id = (int) $pdo->lastInsertId();
        if ($own) {
            $pdo->commit();
        }

        return $id;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function jeab_contacts_merge_mark_kind(PDO $pdo, string $undoId, string $kind): void
{
    $pdo->prepare("UPDATE contacts_merge_undo SET status = 'done' WHERE undo_id = ? AND kind = ? AND status = 'pending'")->execute([$undoId, $kind]);
}

function jeab_contacts_merge_mark_row(PDO $pdo, string $undoId, string $kind, string $rowKey): void
{
    $pdo->prepare("UPDATE contacts_merge_undo SET status = 'done' WHERE undo_id = ? AND kind = ? AND row_key = ? AND status = 'pending'")->execute([$undoId, $kind, $rowKey]);
}

/**
 * purchases skrivs inte om. Bara contact_id-sökvägar som rewrite_doc skulle
 * flytta patchas med json_set. new är det json_set ger, så ångern kan jämföra.
 *
 * @return array{tbl:string,row_key:string,col:string,old:string,new:string,pk:string,mode:string,paths:list<string>}|null
 */
function jeab_contacts_merge_purchase_json_op(PDO $pdo, string $col, string $pk, string $key, string $raw, string $from, string $to): ?array
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || $from === '' || $to === '' || $from === $to) {
        return null;
    }
    $paths = jeab_contacts_merge_contact_paths($decoded, $from);
    if ($paths === []) {
        return null;
    }
    $preview = jeab_contacts_merge_json_set_read($pdo, 'purchases', $col, $pk, $key, $raw, $paths, $to);
    if ($preview === null || $preview === $raw) {
        return null;
    }

    return [
        'tbl' => 'purchases',
        'row_key' => $key,
        'col' => $col,
        'old' => $raw,
        'new' => $preview,
        'pk' => $pk,
        'mode' => 'json',
        'paths' => $paths,
        'to' => $to,
    ];
}

/**
 * @param array{tbl:string,row_key:string,col:string,old:string,new:string,pk:string,mode:string,paths?:list<string>,to?:string} $op
 */
function jeab_contacts_merge_run_purchase_json(PDO $pdo, int $logId, array $op): bool
{
    $paths = $op['paths'] ?? [];
    $to = (string) ($op['to'] ?? '');
    if (!is_array($paths) || $paths === [] || $to === '') {
        return false;
    }
    $clean = [];
    foreach ($paths as $path) {
        if (!is_string($path) || preg_match('/\A\$(?:\.[A-Za-z_][A-Za-z0-9_]*|\[\d+\])+\z/', $path) !== 1) {
            return false;
        }
        $clean[] = $path;
    }
    $driver = jeab_contacts_merge_driver($pdo);
    $qt = jeab_contacts_merge_qi($driver, 'purchases');
    $qc = jeab_contacts_merge_qi($driver, $op['col']);
    $qp = jeab_contacts_merge_qi($driver, $op['pk']);
    if ($qt === '' || $qc === '' || $qp === '') {
        return false;
    }
    $expr = jeab_contacts_merge_json_set_expr($qc, $clean, $to);
    try {
        $sql = 'UPDATE ' . $qt . ' SET ' . $qc . ' = ' . $expr['sql'] . ' WHERE ' . $qp . ' = ? AND ' . $qc . ' = ?';
        $params = $expr['params'];
        $params[] = $op['row_key'];
        $params[] = $op['old'];
        $pdo->prepare($sql)->execute($params);
        $check = $pdo->prepare('SELECT ' . $qc . ' FROM ' . $qt . ' WHERE ' . $qp . ' = ?');
        $check->execute([$op['row_key']]);
        $now = $check->fetchColumn();
    } catch (Throwable) {
        return false;
    }
    if ((string) $now !== (string) $op['new']) {
        return false;
    }
    $pdo->prepare('UPDATE contacts_merge_undo SET status = ? WHERE id = ?')->execute(['done', $logId]);

    return true;
}

/**
 * @param list<string> $paths
 * @return array{sql:string, params:list<string>}
 */
function jeab_contacts_merge_json_set_expr(string $columnSql, array $paths, string $to): array
{
    $slots = [];
    $params = [];
    foreach ($paths as $path) {
        $slots[] = '?';
        $slots[] = '?';
        $params[] = $path;
        $params[] = $to;
    }

    return [
        'sql' => 'json_set(' . $columnSql . ', ' . implode(', ', $slots) . ')',
        'params' => $params,
    ];
}

/**
 * @param list<string> $paths
 */
function jeab_contacts_merge_json_set_read(PDO $pdo, string $table, string $col, string $pk, string $key, string $raw, array $paths, string $to): ?string
{
    $clean = [];
    foreach ($paths as $path) {
        if (preg_match('/\A\$(?:\.[A-Za-z_][A-Za-z0-9_]*|\[\d+\])+\z/', $path) !== 1) {
            return null;
        }
        $clean[] = $path;
    }
    if ($clean === []) {
        return null;
    }
    $driver = jeab_contacts_merge_driver($pdo);
    $qt = jeab_contacts_merge_qi($driver, $table);
    $qc = jeab_contacts_merge_qi($driver, $col);
    $qp = jeab_contacts_merge_qi($driver, $pk);
    if ($qt === '' || $qc === '' || $qp === '') {
        return null;
    }
    $expr = jeab_contacts_merge_json_set_expr($qc, $clean, $to);
    try {
        $stmt = $pdo->prepare('SELECT ' . $expr['sql'] . ' FROM ' . $qt . ' WHERE ' . $qp . ' = ? AND ' . $qc . ' = ?');
        $params = $expr['params'];
        $params[] = $key;
        $params[] = $raw;
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
    } catch (Throwable) {
        return null;
    }
    if ($value === false || $value === null) {
        return null;
    }

    return (string) $value;
}

/**
 * Sökvägar rewrite_doc skulle flytta. Snapshot och frysta barn hoppas över.
 * Toppens contact_id flyttas även när dokumentet självt är fryst.
 *
 * @param array<mixed> $doc
 * @return list<string>
 */
function jeab_contacts_merge_contact_paths(array $doc, string $from, string $path = '$', bool $top = true): array
{
    if (!$top && jeab_contacts_merge_frozen($doc)) {
        return [];
    }
    $out = [];
    if (isset($doc['contact_id']) && (string) $doc['contact_id'] === $from) {
        $out[] = $path . '.contact_id';
    }
    if (isset($doc['meta']) && is_array($doc['meta']) && (string) ($doc['meta']['contact_id'] ?? '') === $from) {
        $out[] = $path . '.meta.contact_id';
    }
    foreach ($doc as $key => $value) {
        if (!is_string($key) || $key === 'contact_id' || $key === 'meta') {
            if (is_int($key) && is_array($value)) {
                foreach (jeab_contacts_merge_contact_paths($value, $from, $path . '[' . $key . ']', false) as $child) {
                    $out[] = $child;
                }
            }
            continue;
        }
        if ($key === 'snapshot' || $key === 'snapshots' || $key === 'snapshot_json') {
            continue;
        }
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key) !== 1 || !is_array($value)) {
            continue;
        }
        foreach (jeab_contacts_merge_contact_paths($value, $from, $path . '.' . $key, false) as $child) {
            $out[] = $child;
        }
    }

    return $out;
}

function jeab_contacts_merge_rewrite_column(string $col, string $raw, string $from, string $to): ?string
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    if ($col === 'snapshot_json' || $col === 'snapshot' || $col === 'snapshots') {
        return null;
    }
    $next = jeab_contacts_merge_rewrite_doc($decoded, $from, $to);
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $before = json_encode($decoded, $flags);
    $after = json_encode($next, $flags);
    if (!is_string($before) || !is_string($after) || $before === $after) {
        return null;
    }

    return $after;
}

/** @param array<string, mixed> $doc @return array<string, mixed> */
function jeab_contacts_merge_rewrite_doc(array $doc, string $from, string $to): array
{
    if (isset($doc['contact_id']) && (string) $doc['contact_id'] === $from) {
        $doc['contact_id'] = $to;
    }
    if (isset($doc['meta']) && is_array($doc['meta']) && (string) ($doc['meta']['contact_id'] ?? '') === $from) {
        $doc['meta']['contact_id'] = $to;
    }
    foreach ($doc as $key => $value) {
        if ($key === 'contact_id' || $key === 'meta') {
            continue;
        }
        $doc[$key] = jeab_contacts_merge_rewrite_value((string) $key, $value, $from, $to);
    }

    return $doc;
}

function jeab_contacts_merge_rewrite_value(string $key, mixed $value, string $from, string $to): mixed
{
    if ($key === 'snapshot' || $key === 'snapshots' || $key === 'snapshot_json') {
        return jeab_contacts_merge_rewrite_snapshot($value, $from, $to);
    }
    if (!is_array($value) || jeab_contacts_merge_frozen($value)) {
        return $value;
    }
    if (isset($value['contact_id']) && (string) $value['contact_id'] === $from) {
        $value['contact_id'] = $to;
    }
    if (isset($value['meta']) && is_array($value['meta']) && (string) ($value['meta']['contact_id'] ?? '') === $from) {
        $value['meta']['contact_id'] = $to;
    }
    foreach ($value as $childKey => $child) {
        if ($childKey === 'contact_id' || $childKey === 'meta') {
            continue;
        }
        $value[$childKey] = jeab_contacts_merge_rewrite_value((string) $childKey, $child, $from, $to);
    }

    return $value;
}

function jeab_contacts_merge_rewrite_snapshot(mixed $value, string $from, string $to): mixed
{
    // Snapshot-noder behåller contact_id. Frysflaggor och $from/$to spelar ingen roll.
    return $value;
}

/** @param array<mixed> $node */
function jeab_contacts_merge_frozen(array $node): bool
{
    foreach (['frozen', '_frozen', '_party_frozen'] as $key) {
        if (!empty($node[$key])) {
            return true;
        }
    }

    return false;
}
