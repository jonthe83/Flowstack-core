<?php

declare(strict_types=1);

/**
 * Vunnen påminnelse sätter orderns ägare till ansvarig, samma namn som personfiltret.
 * order_owner_backfill listar avvikelser och fyller bara tom ägare. Ingen Fortnox-skrivning.
 */

$tmp = sys_get_temp_dir() . '/jeab-order-owner-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
$_GET['co'] = 'jarfalla';
$GLOBALS['JEAB_FORTNOX_WRITES'] = 0;

require dirname(__DIR__) . '/src/bootstrap.php';

function ow_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ow_reminder(JeabStore $store, string $title, string $who, string $company = 'jarfalla'): array
{
    return jeab_create_reminder($store, $title, '2026-10-20 00:00:00', null, null, [
        'customer' => $title,
        'company' => $company,
        'who' => $who,
        'tags' => ['vunnen'],
        'address' => 'Exempelvägen 1',
    ]);
}

$path = $tmp . '/store.json';
$store = new JeabJsonStore($path);

$sam = ow_reminder($store, 'Provkund Alfa', 'sam l');
$samId = (int) ($sam['id'] ?? 0);
ow_assert($samId > 0, 'påminnelsen skapades');
ow_assert(in_array('Sam L', jeab_assignees_from_row($sam), true), 'ansvarig normaliseras till Sam L');

$purchase = jeab_ordrar_vunnen_purchase($store, $samId, $sam);
ow_assert((string) ($purchase['assignee'] ?? '') === 'Sam L', 'orderns ägare är påminnelsens ansvarige');
ow_assert(($purchase['assignees'] ?? []) === ['Sam L'], 'ägarlistan är samma nyckel');
ow_assert(jeab_order_matches_filter($purchase, 'Sam L', null), 'personfiltret träffar Sam L');
ow_assert(!jeab_order_matches_filter($purchase, 'Elin', null), 'tom produkt gör inte Elin till ägare');
ow_assert((int) $GLOBALS['JEAB_FORTNOX_WRITES'] === 0, 'skapandet skriver inte Fortnox');

$elin = ow_reminder($store, 'Provkund Beta', 'Elin');
$elinId = (int) ($elin['id'] ?? 0);
$elinOrder = jeab_purchase_start($store, $elinId, '');
ow_assert((string) ($elinOrder['assignee'] ?? '') === 'Elin', 'Elin blir ägare när hon är ansvarig');
ow_assert(jeab_order_matches_filter($elinOrder, 'Elin', null), 'filtret träffar Elin');

$kim = ow_reminder($store, 'Provkund Gamma', 'Kim', 'malmo');
$kimId = (int) ($kim['id'] ?? 0);
$kimOrder = jeab_purchase_start($store, $kimId, 'laddbox');
ow_assert((string) ($kimOrder['assignee'] ?? '') === 'Kim', 'Malmö-ansvarig blir Kim');
ow_assert(jeab_order_matches_filter($kimOrder, 'Kim', null), 'filtret träffar Kim');

$bare = ow_reminder($store, 'Provkund Delta', 'SamL');
$bareId = (int) ($bare['id'] ?? 0);
jeab_purchase_put([
    'reminder_id' => $bareId,
    'company' => 'jarfalla',
    'name' => 'Provkund Delta',
    'kind' => '',
    'fortnox_order' => 'FX-KEEP',
    'steps' => ['kund_bestallt' => '2026-10-20'],
]);
ow_assert(jeab_ordrar_stored_owner_names(jeab_purchase_get($bareId) ?? []) === [], 'order utan ägare');
ow_assert(!jeab_order_matches_filter(jeab_purchase_get($bareId) ?? [], 'Sam L', null), 'filtret missar order utan ägare');

$other = ow_reminder($store, 'Provkund Epsilon', 'sam l');
$otherId = (int) ($other['id'] ?? 0);
jeab_purchase_put([
    'reminder_id' => $otherId,
    'company' => 'jarfalla',
    'name' => 'Provkund Epsilon',
    'kind' => '',
    'assignee' => 'Elin',
    'assignees' => ['Elin'],
    'fortnox_order' => 'FX-OTHER',
]);

$dry = jeab_ordrar_order_owner_backfill($store, ['dry' => 1]);
ow_assert(($dry['dry'] ?? 0) === 1 && ($dry['fixed'] ?? 1) === 0, 'dry=1 skriver inget');
$dryIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $dry['rows'] ?? []);
sort($dryIds);
$expect = [$bareId, $otherId];
sort($expect);
ow_assert($dryIds === $expect, 'dry=1 tar med tom ägare och avvikande ägare');
ow_assert(!in_array($samId, $dryIds, true) && !in_array($elinId, $dryIds, true), 'rätt ägare listas inte');
foreach ($dry['rows'] as $row) {
    ow_assert(array_keys($row) === ['id', 'owner'], 'raden har bara id och föreslagen ägare');
    ow_assert(($row['owner'] ?? '') === 'Sam L', 'föreslagen ägare är ansvarig');
    ow_assert(!str_contains(json_encode($row, JSON_UNESCAPED_UNICODE), 'Provkund'), 'listan saknar kundtext');
}
ow_assert(jeab_ordrar_owner_audit_list() === [], 'dry=1 skriver ingen audit');
ow_assert((string) (jeab_purchase_get($bareId)['assignee'] ?? '') === '', 'dry=1 lämnar tom ägare');
ow_assert((string) (jeab_purchase_get($otherId)['assignee'] ?? '') === 'Elin', 'dry=1 rör inte avvikande ägare');
ow_assert((string) (jeab_purchase_get($bareId)['fortnox_order'] ?? '') === 'FX-KEEP', 'dry=1 rör inte Fortnox-ordern');

$live = jeab_ordrar_order_owner_backfill($store, ['dry' => 0]);
ow_assert(($live['dry'] ?? 1) === 0 && (int) ($live['fixed'] ?? 0) === 1, 'dry=0 rättar bara den tomma');
$filled = jeab_purchase_get($bareId) ?? [];
ow_assert((string) ($filled['assignee'] ?? '') === 'Sam L', 'tom ägare blir ansvarig');
ow_assert(jeab_order_matches_filter($filled, 'Sam L', null), 'backfillad order syns i filtret');
ow_assert((string) ($filled['fortnox_order'] ?? '') === 'FX-KEEP', 'dry=0 skriver ingen Fortnox-order');
ow_assert((string) (jeab_purchase_get($otherId)['assignee'] ?? '') === 'Elin', 'avvikande ägare skrivs inte över');
ow_assert((string) (jeab_purchase_get($otherId)['fortnox_order'] ?? '') === 'FX-OTHER', 'avvikande Fortnox-order ligger kvar');
ow_assert((int) $GLOBALS['JEAB_FORTNOX_WRITES'] === 0, 'backfillen anropar inte Fortnox');

$auditRows = jeab_ordrar_owner_audit_list();
$auditOne = $auditRows[0] ?? [];
$auditJson = json_encode($auditRows, JSON_UNESCAPED_UNICODE);
ow_assert(count($auditRows) === 1 && (int) ($auditOne['reminder_id'] ?? 0) === $bareId && (string) ($auditOne['owner'] ?? '') === 'Sam L', 'auditen i SQL har id och ägare');
ow_assert(is_string($auditJson) && !str_contains($auditJson, 'Provkund') && !str_contains($auditJson, 'FX-KEEP'), 'auditen saknar kund och Fortnox');
ow_assert(!is_file($tmp . '/order-owner-backfill.jsonl') && !is_file(jeab_data_dir() . '/order-owner-backfill.jsonl'), 'ingen jsonl-fil');

$again = jeab_ordrar_order_owner_backfill($store, ['dry' => 1]);
$againIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $again['rows'] ?? []);
ow_assert($againIds === [$otherId], 'fylld ägare försvinner ur listan');

$denied = jeab_ordrar_order_owner_backfill_dispatch($store, ['dry' => 1]);
ow_assert(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'hooken kräver token');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
ow_assert(str_contains($hook, 'order_owner_backfill'), 'hook.php tar emot order_owner_backfill');

echo "ordrar owner backfill: ok\n";
