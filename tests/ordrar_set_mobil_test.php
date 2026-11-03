<?php

declare(strict_types=1);

/**
 * Hooken ordrar.set_mobil. Påhittade nummer. Ingen kund.
 * dry=1 visar maskerat före/efter och skriver inget.
 * dry=0 skriver bara mobilfältet och en SQL-audit utan numret.
 */

$tmp = sys_get_temp_dir() . '/jeab-set-mobil-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa temp\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
$_ENV['JEAB_DISABLE_SQLITE'] = '1';
$GLOBALS['JEAB_FORTNOX_WRITES'] = 0;

require dirname(__DIR__) . '/src/bootstrap.php';

function sm_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function sm_order(int $id, string $company, string $phone): void
{
    jeab_purchase_put([
        'reminder_id' => $id,
        'kind' => 'laddbox',
        'company' => $company,
        'name' => 'Testorder',
        'address' => 'Testgatan 1',
        'email' => 'test@example.com',
        'phone' => $phone,
        'personnummer' => '',
        'fastighetsbeteckning' => 'TEST 1:1',
        'fortnox_order' => 'FX-STAY',
        'natbolag' => 'E.ON',
        'applicant' => ['name' => 'Testorder', 'email' => 'test@example.com', 'phone' => $phone],
        'steps' => ['kund_bestallt' => '2026-10-20'],
        'updated_at' => '2026-10-01 08:00:00',
    ]);
}

sm_assert(jeab_ordrar_mask_mobil('+46701234705') === '070***705', 'mask av +46 blir 070***705');
sm_assert(jeab_ordrar_mask_mobil('070-123 47 05') === '070***705', 'mask tar bort mellanslag och bindestreck');
sm_assert(jeab_ordrar_mask_mobil('') === '', 'tom mobil maskeras som tom');
sm_assert(jeab_ordrar_mask_mobil('123') === '***', 'kort nummer blir ***');

$store = jeab_store();
$id = 880441;
sm_order($id, 'jarfalla', '+46701234705');

$denied = jeab_ordrar_set_mobil_dispatch($store, [
    'order_id' => $id,
    'co' => 'jarfalla',
    'mobil' => '0700000001',
    'reason' => 'test',
]);
sm_assert(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'hooken kräver inkorgs-token');

$missing = jeab_ordrar_set_mobil_run($store, ['co' => 'jarfalla', 'mobil' => '', 'reason' => 'test']);
sm_assert(($missing['ok'] ?? true) === false && (int) ($missing['status'] ?? 0) === 422, 'utan order_id blir det fel');

$badCo = jeab_ordrar_set_mobil_run($store, ['order_id' => $id, 'co' => 'stockholm', 'mobil' => '', 'reason' => 'test']);
sm_assert(($badCo['ok'] ?? true) === false, 'co måste vara jarfalla eller malmo');

$otherCo = jeab_ordrar_set_mobil_run($store, [
    'order_id' => $id,
    'co' => 'malmo',
    'mobil' => '0700000001',
    'reason' => 'fel bolag',
    'dry' => 0,
]);
sm_assert(($otherCo['ok'] ?? true) === false && (int) ($otherCo['status'] ?? 0) === 422, 'fel bolag skrivs inte');
sm_assert((string) (jeab_purchase_get($id)['phone'] ?? '') === '+46701234705', 'fel bolag lämnar numret');

$plain = '0700000001';
$canon = (string) jeab_normalize_reminder_phone($plain);
$dry = jeab_ordrar_set_mobil_run($store, [
    'order_id' => $id,
    'co' => 'jarfalla',
    'mobil' => '070-000 00 01',
    'reason' => 'rättat ' . $plain,
]);
$dryJson = json_encode($dry, JSON_UNESCAPED_UNICODE);
sm_assert(($dry['ok'] ?? false) === true && (int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['written'] ?? 1) === 0, 'dry saknas är torr och skriver inget');
sm_assert(($dry['before'] ?? '') === '070***705' && ($dry['after'] ?? '') === '070***001', 'dry=1 visar maskerat före och efter');
sm_assert(($dry['reason'] ?? '') === 'rättat [nummer]', 'orsaken i svaret har inte numret');
sm_assert(is_string($dryJson) && !str_contains($dryJson, $plain) && !str_contains($dryJson, $canon) && !str_contains($dryJson, '46701234705'), 'dry-svaret saknar nummer i klartext');
sm_assert((string) (jeab_purchase_get($id)['phone'] ?? '') === '+46701234705', 'dry=1 lämnar ordern');
sm_assert(jeab_ordrar_mobil_audit_list() === [], 'dry=1 skriver ingen audit');

$bad = jeab_ordrar_set_mobil_run($store, [
    'order_id' => $id,
    'co' => 'jarfalla',
    'mobil' => '0800000000',
    'reason' => 'landlinje',
    'dry' => 0,
]);
$badJson = json_encode($bad, JSON_UNESCAPED_UNICODE);
sm_assert(($bad['ok'] ?? true) === false && str_contains((string) ($bad['error'] ?? ''), 'mobil'), 'ogiltigt nummer ger fel');
sm_assert(is_string($badJson) && !str_contains($badJson, '0800000000') && !str_contains($badJson, '4680000000'), 'fel-svaret saknar det ogiltiga numret');
sm_assert((string) (jeab_purchase_get($id)['phone'] ?? '') === '+46701234705', 'ogiltigt nummer skrivs inte');

$beforeRow = jeab_purchase_get($id);
$wet = jeab_ordrar_set_mobil_run($store, [
    'order_id' => $id,
    'co' => 'jarfalla',
    'mobil' => $plain,
    'reason' => 'rättat nummer',
    'dry' => 0,
]);
$wetJson = json_encode($wet, JSON_UNESCAPED_UNICODE);
$saved = jeab_purchase_get($id);
sm_assert(($wet['ok'] ?? false) === true && (int) ($wet['dry'] ?? 1) === 0 && (int) ($wet['written'] ?? 0) === 1, 'dry=0 skriver');
sm_assert(($wet['after'] ?? '') === '070***001', 'dry=0-svaret är maskerat');
sm_assert(is_string($wetJson) && !str_contains($wetJson, $plain) && !str_contains($wetJson, $canon), 'dry=0-svaret saknar nummer i klartext');
sm_assert(is_array($saved) && (string) ($saved['phone'] ?? '') === $canon, 'orderns mobil är kanonisk');
sm_assert((string) ($saved['applicant']['phone'] ?? '') === $canon, 'sökandens mobil följer med');
sm_assert((string) ($saved['email'] ?? '') === 'test@example.com', 'e-post ligger kvar');
sm_assert((string) ($saved['applicant']['email'] ?? '') === 'test@example.com', 'sökandens e-post ligger kvar');
sm_assert((string) ($saved['address'] ?? '') === 'Testgatan 1', 'adressen ligger kvar');
sm_assert((string) ($saved['fortnox_order'] ?? '') === 'FX-STAY', 'Fortnox-ordern ligger kvar');
sm_assert((string) ($saved['natbolag'] ?? '') === 'E.ON', 'nätägaren ligger kvar');
sm_assert((string) ($saved['fastighetsbeteckning'] ?? '') === 'TEST 1:1', 'fastigheten ligger kvar');
sm_assert(($saved['steps']['kund_bestallt'] ?? '') === '2026-10-20', 'stegen ligger kvar');
sm_assert((string) ($saved['updated_at'] ?? '') !== (string) ($beforeRow['updated_at'] ?? ''), 'tidsstämpeln flyttas');
sm_assert((int) $GLOBALS['JEAB_FORTNOX_WRITES'] === 0, 'ingen Fortnox-skrivning');

$audit = jeab_ordrar_mobil_audit_list();
$auditJson = json_encode($audit, JSON_UNESCAPED_UNICODE);
sm_assert(count($audit) === 1, 'en auditrad');
sm_assert((int) ($audit[0]['reminder_id'] ?? 0) === $id && ($audit[0]['company'] ?? '') === 'jarfalla' && ($audit[0]['change_kind'] ?? '') === 'set', 'auditen har id, bolag och set');
sm_assert(($audit[0]['reason'] ?? '') === 'rättat nummer', 'auditen har orsaken');
sm_assert(is_string($auditJson) && !str_contains($auditJson, $plain) && !str_contains($auditJson, $canon) && !str_contains($auditJson, '0701234705') && !str_contains($auditJson, '46701234705'), 'auditen saknar numret');
sm_assert(!is_file($tmp . '/order-mobil.json') && !is_file($tmp . '/order-mobil.jsonl'), 'ingen json-fil för auditen');

$same = jeab_ordrar_set_mobil_run($store, [
    'order_id' => $id,
    'co' => 'jarfalla',
    'mobil' => '+46 70 000 00 01',
    'reason' => 'samma',
    'dry' => 0,
]);
sm_assert((int) ($same['written'] ?? 1) === 0 && count(jeab_ordrar_mobil_audit_list()) === 1, 'samma nummer skrivs inte om');

$clearDry = jeab_ordrar_set_mobil_run($store, [
    'order_id' => $id,
    'co' => 'jarfalla',
    'mobil' => '',
    'reason' => 'töm',
    'dry' => 1,
]);
sm_assert((int) ($clearDry['cleared'] ?? 0) === 1 && ($clearDry['after'] ?? 'x') === '' && (int) ($clearDry['written'] ?? 1) === 0, 'dry=1 visar tömning');
sm_assert((string) (jeab_purchase_get($id)['phone'] ?? '') === $canon, 'torr tömning skriver inte');

$clear = jeab_ordrar_set_mobil_run($store, [
    'order_id' => $id,
    'co' => 'jarfalla',
    'mobil' => '',
    'reason' => 'töm ogiltigt',
    'dry' => 0,
]);
$cleared = jeab_purchase_get($id);
sm_assert((int) ($clear['written'] ?? 0) === 1 && (string) ($cleared['phone'] ?? 'x') === '', 'mobil="" tömmer fältet');
sm_assert((string) ($cleared['applicant']['phone'] ?? 'x') === '', 'sökandens mobil töms');
sm_assert((string) ($cleared['fortnox_order'] ?? '') === 'FX-STAY', 'tömning rör inte Fortnox');
$clearAudit = jeab_ordrar_mobil_audit_list();
sm_assert(count($clearAudit) === 2 && ($clearAudit[1]['change_kind'] ?? '') === 'clear', 'tömning auditloggas som clear');
$clearAuditJson = (string) json_encode($clearAudit, JSON_UNESCAPED_UNICODE);
sm_assert(!str_contains($clearAuditJson, $plain) && !str_contains($clearAuditJson, $canon), 'clear-auditen saknar nummer');

$gone = jeab_ordrar_set_mobil_run($store, ['order_id' => 880499, 'co' => 'jarfalla', 'mobil' => '', 'reason' => 'saknas']);
sm_assert(($gone['ok'] ?? true) === false && (int) ($gone['status'] ?? 0) === 404, 'saknad order är 404');

$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
sm_assert(str_contains($hook, "=== 'ordrar.set_mobil'") && str_contains($hook, 'jeab_ordrar_set_mobil_dispatch'), 'hook.php tar ordrar.set_mobil');
$src = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$start = strpos($src, 'function jeab_ordrar_set_mobil_run(');
$end = strpos($src, 'function jeab_ordrar_set_mobil_dispatch(');
sm_assert($start !== false && $end !== false && $end > $start, 'funktionen finns');
$body = substr($src, (int) $start, (int) $end - (int) $start);
sm_assert(str_contains($body, 'jeab_purchase_patch'), 'skrivningen går via orderlagret');
sm_assert(str_contains($body, 'jeab_ordrar_mobil_audit'), 'skrivningen auditloggas');
sm_assert(!str_contains($body, 'jeab_purchase_json_write') && !str_contains($body, 'file_put_contents'), 'hooken skriver ingen egen json-fil');
sm_assert(!preg_match('/jeab_(fortnox|sms|mail|send)_/', $body), 'hooken skickar inget och rör inte Fortnox');

$landId = 880442;
sm_order($landId, 'jarfalla', '0800000000');
$landBefore = jeab_purchase_get($landId);
$blocked = false;
try {
    jeab_ordrar_save_objekt([
        'id' => $landId,
        'objekt_adress' => 'Annangatan 2',
        'objekt_postort' => 'Teststad',
        'phone' => '0800000000',
        'email' => 'test@example.com',
        'objekt_fastighetsbeteckning' => 'TEST 9:9',
    ]);
} catch (InvalidArgumentException $e) {
    $blocked = str_contains($e->getMessage(), 'mobil');
}
$landAfter = jeab_purchase_get($landId);
sm_assert($blocked, 'Objekt avvisar mobil som redan står och inte är 07');
sm_assert((string) ($landAfter['address'] ?? '') === (string) ($landBefore['address'] ?? '') && (string) ($landAfter['fastighetsbeteckning'] ?? '') === 'TEST 1:1', 'avvisad Objekt-sparning lämnar adressen och fastigheten');
$v3 = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
sm_assert(str_contains($v3, "step === 'objekt' && !validateObj()"), 'v3 släpper inte Objekt-sparning när mobilfältet faller på kontrollen');

echo "ordrar set_mobil: ok\n";
