<?php

declare(strict_types=1);

/**
 * Radera ärendet ska ligga kvar borta.
 * Påminnelse-backfill och inkommande webhook får inte skapa köpet igen.
 */

$tmp = sys_get_temp_dir() . '/jeab-ordrar-delete-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
$_GET['co'] = 'jarfalla';

require dirname(__DIR__) . '/src/bootstrap.php';

function del_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$path = $tmp . '/store.json';
$store = new JeabJsonStore($path);

$reminder = jeab_create_reminder($store, 'Anna Radera', '2026-09-24 00:00:00', 'HS#424242 raderatest', null, [
    'customer' => 'Anna Radera',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
    'address' => 'Testgatan 1',
]);
$id = (int) ($reminder['id'] ?? 0);
del_assert($id > 0, 'påminnelsen skapades');
del_assert(in_array('vunnen', jeab_parse_tags($reminder['tags'] ?? []), true), 'påminnelsen är vunnen');

$purchase = jeab_purchase_start($store, $id, 'batteri');
del_assert((int) ($purchase['reminder_id'] ?? 0) === $id, 'ordern skapades från påminnelsen');
del_assert(is_array(jeab_purchase_get($id)), 'ordern ligger i lagret');

jeab_ordrar_delete(['id' => $id], $store);

del_assert(jeab_purchase_get($id) === null, 'radera tar bort ordern');
$after = jeab_get_reminder($store, $id);
del_assert(is_array($after), 'påminnelsen ligger kvar');
del_assert(!in_array('vunnen', jeab_parse_tags($after['tags'] ?? []), true), 'vunnen tas bort');
del_assert(jeab_ordrar_deleted_blocks($id, $after), 'raderingen är sparad');

jeab_update_reminder($store, $id, ['tags' => ['vunnen']]);
$retag = jeab_get_reminder($store, $id);
del_assert(is_array($retag) && in_array('vunnen', jeab_parse_tags($retag['tags'] ?? []), true), 'påminnelsen kan taggas vunnen igen');
jeab_ordrar_backfill_vunnen($store, 'jarfalla');
del_assert(jeab_purchase_get($id) === null, 'backfill skapar inte ordern igen');

$started = false;
try {
    jeab_purchase_start($store, $id, 'batteri');
    $started = true;
} catch (InvalidArgumentException $e) {
    del_assert($e->getMessage() === 'Ärendet är raderat.', 'start säger att ärendet är raderat');
}
del_assert($started === false, 'purchase_start skapar ingen order');

$put = false;
try {
    jeab_purchase_put([
        'reminder_id' => $id,
        'kind' => 'batteri',
        'company' => 'jarfalla',
        'name' => 'Anna Radera',
    ]);
    $put = true;
} catch (InvalidArgumentException $e) {
    del_assert($e->getMessage() === 'Ärendet är raderat.', 'put säger att ärendet är raderat');
}
del_assert($put === false && jeab_purchase_get($id) === null, 'put återskapar inte ordern');

$replay = jeab_parse_incoming([
    'type' => 'reminder',
    'title' => 'Anna Radera',
    'note' => 'HS#424242 från webhook',
    'tags' => ['vunnen'],
    'company' => 'jarfalla',
]);
$replayed = jeab_create_reminder($store, $replay['title'], $replay['due_at'], $replay['note'], null, $replay);
del_assert((int) ($replayed['id'] ?? 0) === $id, 'webhook återanvänder samma påminnelse');
del_assert(!in_array('vunnen', jeab_parse_tags($replayed['tags'] ?? []), true), 'webhook tar bort vunnen på raderad påminnelse');
jeab_ordrar_backfill_vunnen($store, 'jarfalla');
del_assert(jeab_purchase_get($id) === null, 'webhook-backfill skapar inte ordern');

jeab_toggle_reminder($store, $id, true);
$fresh = jeab_create_reminder($store, 'Anna Radera igen', '2026-09-25 00:00:00', 'HS#424242 ny rad', null, [
    'customer' => 'Anna Radera',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
]);
$freshId = (int) ($fresh['id'] ?? 0);
del_assert($freshId > 0 && $freshId !== $id, 'ny påminnelse efter avslutad rad');
del_assert(!in_array('vunnen', jeab_parse_tags($fresh['tags'] ?? []), true), 'ny webhook-rad med samma HS får inte vunnen');
jeab_update_reminder($store, $freshId, ['tags' => ['vunnen']]);
jeab_ordrar_backfill_vunnen($store, 'jarfalla');
del_assert(jeab_purchase_get($freshId) === null, 'samma Help Scout-nummer skapar inte en ny order');

$other = jeab_create_reminder($store, 'Bea Kvar', '2026-09-24 00:00:00', 'HS#515151 annan', null, [
    'customer' => 'Bea Kvar',
    'company' => 'jarfalla',
    'tags' => ['vunnen'],
]);
$otherId = (int) ($other['id'] ?? 0);
jeab_ordrar_backfill_vunnen($store, 'jarfalla');
del_assert(is_array(jeab_purchase_get($otherId)), 'en annan vunnen påminnelse blir fortfarande en order');
del_assert(jeab_purchase_get($id) === null && jeab_purchase_get($freshId) === null, 'de raderade raderna är fortfarande borta');

$reopen = jeab_create_reminder($store, 'Krokusvägen 10', '2026-09-29 00:00:00', 'HS#24514 krokus', null, [
    'customer' => 'Test Kund',
    'company' => 'jarfalla',
    'address' => 'Krokusvägen 10',
    'tags' => ['vunnen'],
]);
$reopenId = (int) ($reopen['id'] ?? 0);
del_assert($reopenId > 0 && $reopenId !== $id, 'krokus-påminnelsen är en egen rad');
jeab_purchase_start($store, $reopenId, '');
jeab_ordrar_delete(['id' => $reopenId], $store);
$reopenRow = jeab_get_reminder($store, $reopenId);
del_assert(is_array($reopenRow) && empty($reopenRow['completed_at']), 'raderad order lämnar påminnelsen kvar');
del_assert(jeab_ordrar_deleted_blocks($reopenId, $reopenRow), 'den egna raderingen spärrar köpet');
$blockedStart = false;
try {
    jeab_purchase_start($store, $reopenId, '');
    $blockedStart = true;
} catch (InvalidArgumentException $e) {
    del_assert($e->getMessage() === 'Ärendet är raderat.', 'start utan ny vunnen säger att ärendet är raderat');
}
del_assert($blockedStart === false, 'purchase_start utan release skapar ingen order');

jeab_update_reminder($store, $reopenId, ['tags' => ['vunnen']]);
$reopenRow = jeab_get_reminder($store, $reopenId);
del_assert(is_array($reopenRow), 'påminnelsen finns när vunnen väljs');
$opened = jeab_ordrar_vunnen_purchase($store, $reopenId, $reopenRow);
del_assert((int) ($opened['reminder_id'] ?? 0) === $reopenId, 'explicit vunnen startar köpet');
del_assert(is_array(jeab_purchase_get($reopenId)), 'köpet ligger kvar efter vunnen');
$reopenDone = jeab_get_reminder($store, $reopenId);
del_assert(is_array($reopenDone) && empty($reopenDone['completed_at']), 'vunnen utan bekräfta lämnar påminnelsen aktiv');
del_assert(in_array('vunnen', jeab_parse_tags($reopenDone['tags'] ?? []), true), 'vunnen-taggen ligger kvar på den öppna raden');
del_assert(!jeab_ordrar_deleted_blocks($reopenId, $reopenDone), 'egen tombstone är borta efter vunnen');

$owner = jeab_create_reminder($store, 'Raderadgatan 1', '2026-09-29 00:00:00', 'HS#777001 raderad', null, [
    'customer' => 'Raderad Kund',
    'company' => 'jarfalla',
    'address' => 'Raderadgatan 1',
    'tags' => ['vunnen'],
]);
$ownerId = (int) ($owner['id'] ?? 0);
jeab_purchase_start($store, $ownerId, '');
jeab_ordrar_delete(['id' => $ownerId], $store);
jeab_toggle_reminder($store, $ownerId, true);
$shared = jeab_create_reminder($store, 'Deladgatan 3', '2026-09-29 00:00:00', 'HS#777001 annan levande', null, [
    'customer' => 'Delad Kund',
    'company' => 'jarfalla',
    'address' => 'Deladgatan 3',
    'tags' => ['vunnen'],
]);
$sharedId = (int) ($shared['id'] ?? 0);
del_assert($sharedId > 0 && $sharedId !== $ownerId, 'delad rad är ett annat ärende');
del_assert(!in_array('vunnen', jeab_parse_tags($shared['tags'] ?? []), true), 'ny rad med raderat HS får inte vunnen');
jeab_update_reminder($store, $sharedId, ['tags' => ['vunnen']]);
$sharedRow = jeab_get_reminder($store, $sharedId);
del_assert(is_array($sharedRow), 'den delade påminnelsen finns');
$sharedBlocked = false;
try {
    jeab_ordrar_vunnen_purchase($store, $sharedId, $sharedRow);
    $sharedBlocked = true;
} catch (InvalidArgumentException $e) {
    del_assert($e->getMessage() === 'Ärendet är raderat.', 'annans raderade Help Scout-nummer säger att ärendet är raderat');
}
del_assert($sharedBlocked === false && jeab_purchase_get($sharedId) === null, 'annans radering skapar inget köp');
$sharedAfter = jeab_get_reminder($store, $sharedId);
del_assert(is_array($sharedAfter) && empty($sharedAfter['completed_at']), 'stoppad vunnen lämnar påminnelsen aktiv');
del_assert(jeab_ordrar_deleted_blocks($ownerId, jeab_get_reminder($store, $ownerId)), 'den andra raderingen ligger kvar');
del_assert(jeab_purchase_get($ownerId) === null, 'den andra ordern är fortfarande borta');
del_assert(jeab_ordrar_deleted_blocks($id, jeab_get_reminder($store, $id)), 'den ursprungliga raderingen ligger kvar');
del_assert(jeab_purchase_get($id) === null, 'den ursprungliga ordern är fortfarande borta');

echo "ordrar delete permanence: ok\n";
