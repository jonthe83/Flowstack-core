<?php

declare(strict_types=1);

/**
 * Anteckning till installation sparas för sig, utan att bekräfta ordern.
 * Kvittot «Sparad.» sitter vid fältet. Inget personnummer skrivs ut.
 */

$tmp = sys_get_temp_dir() . '/jeab-install-note-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'json',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';
jeab_ordrar_external_live(false);
date_default_timezone_set('Europe/Stockholm');

function note_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$store = new JeabJsonStore($tmp . '/store.json');
$reminder = jeab_create_reminder($store, 'Anteckning Testsson', null, 'Order', null, [
    'customer' => 'Anteckning Testsson',
    'company' => 'jarfalla',
    'address' => 'Testgatan 2, 175 00 Järfälla',
    'notes' => 'Order',
]);
$order = jeab_purchase_put([
    'reminder_id' => (int) $reminder['id'],
    'company' => 'jarfalla',
    'kind' => 'laddbox',
    'customer' => 'Anteckning Testsson',
    'address' => 'Testgatan 2, 175 00 Järfälla',
    'pris' => 12000,
    'install_note' => 'Gammal rad',
    'steps' => ['markerad' => '2026-10-01'],
]);
$id = (int) $order['reminder_id'];
$beforeSteps = $order['steps'] ?? [];
$beforeKind = (string) ($order['kind'] ?? '');

$saved = jeab_ordrar_save_install_note([
    'id' => $id,
    'install_note' => '  Nyckel under mattan  ',
]);
note_assert(jeab_ordrar_install_note($saved) === 'Nyckel under mattan', 'anteckningen sparas trimmad');
note_assert(($saved['steps'] ?? []) === $beforeSteps, 'autospar stämplar inte Bekräfta');
note_assert((string) ($saved['kind'] ?? '') === $beforeKind, 'produktvalet står kvar');
$again = jeab_purchase_get($id);
note_assert(is_array($again) && jeab_ordrar_install_note($again) === 'Nyckel under mattan', 'anteckningen ligger kvar i ordern');

$newer = jeab_ordrar_save_install_note([
    'id' => $id,
    'install_note' => 'QA11',
    'note_rev' => 2,
]);
note_assert(jeab_ordrar_install_note($newer) === 'QA11' && (int) ($newer['install_note_rev'] ?? 0) === 2, 'högre revision sparar texten');
$staleRev = jeab_ordrar_save_install_note([
    'id' => $id,
    'install_note' => 'QA10',
    'note_rev' => 1,
]);
note_assert(jeab_ordrar_install_note($staleRev) === 'QA11' && (int) ($staleRev['install_note_rev'] ?? 0) === 2, 'lägre revision skriver inte över');
jeab_ordrar_save_install_note(['id' => $id, 'install_note' => 'Nyckel under mattan']);

$cleared = jeab_ordrar_save_install_note(['id' => $id, 'install_note' => '   ']);
note_assert(jeab_ordrar_install_note($cleared) === '', 'tom anteckning töms');

$missing = false;
try {
    jeab_ordrar_save_install_note(['id' => $id]);
} catch (InvalidArgumentException $e) {
    $missing = $e->getMessage() === 'Anteckningen saknas.';
}
note_assert($missing, 'fältet install_note krävs');

$long = false;
try {
    jeab_ordrar_save_install_note(['id' => $id, 'install_note' => str_repeat('a', 4001)]);
} catch (InvalidArgumentException $e) {
    $long = $e->getMessage() === 'Anteckningen är för lång.';
}
note_assert($long, 'för lång anteckning avvisas');

$html = jeab_ordrar_v3_html($again, 'jarfalla', $id, $store);
$at = strpos($html, 'id="v3-note"');
$slice = $at === false ? '' : substr($html, max(0, $at - 80), 900);
note_assert(str_contains($slice, 'name="install_note"'), 'fältnamnet är install_note');
note_assert(str_contains($slice, 'data-v3-autosave-action="ordrar.install_note"'), 'autospar går till ordrar.install_note');
$statusAt = strpos($slice, 'data-v3-field-status');
$helpAt = strpos($slice, 'class="v3-help"');
note_assert($statusAt !== false && $helpAt !== false && $statusAt < $helpAt, 'kvittot sitter vid fältet');
note_assert(str_contains($slice, 'v3-fieldline') && str_contains($slice, 'class="v3-autosave"'), 'kvittot är en egen rad bredvid textfältet');

$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/ordrar-v3.js');
note_assert(str_contains($js, "fieldStatus(area, 'Sparad.', false)"), 'lyckat sparande visar Sparad.');
note_assert(str_contains($js, 'fieldStatus(area, failText('), 'fel visar Kunde inte spara.');
note_assert(str_contains($js, "body.set('action', snap.action)"), 'åtgärden tas från fältet');
note_assert(str_contains($js, 'body.set(snap.name, snap.value)'), 'bara fältets eget namn och värde postas');
note_assert(str_contains($js, 'keepalive: true') && str_contains($js, "addEventListener('pagehide', flushFields"), 'pagehide skickar med keepalive');
note_assert(str_contains($js, 'visibilitychange') && str_contains($js, "visibilityState === 'hidden'") && str_contains($js, 'flushFields()'), 'dold sida skickar väntande anteckning');
note_assert(str_contains($js, 'function fieldBusy') && str_contains($js, 'ev.preventDefault()') && str_contains($js, "ev.returnValue = ''"), 'beforeunload varnar medan sparandet pågår');
note_assert(str_contains($js, "matches('textarea[data-v3-autosave-action]')") && str_contains($js, 'fieldSave(t)'), 'blur sparar direkt');
note_assert(str_contains($js, 'setTimeout(function () { fieldSave(area); }, 500)'), 'inmatning debounceas');
note_assert(str_contains($js, 'area._v3Sent === value'), 'samma text skickas inte två gånger');
note_assert(str_contains($js, 'navigator.sendBeacon'), 'sendBeacon är reserv när fetch inte kan köas');
note_assert(str_contains($js, "body.set('note_rev'"), 'pagehide bär med revisionsnummer');
note_assert(str_contains($js, 'signal.aborted'), 'avbruten fetch skickar inte den gamla kroppen');
note_assert(!str_contains($js, '_v3Leave'), 'pågående sparande räknas inte som levererat');
$unloadAt = strpos($js, 'function fieldUnload');
$unload = $unloadAt === false ? '' : substr($js, $unloadAt, 1800);
$beaconAt = strpos($unload, 'navigator.sendBeacon');
$deliverAt = strpos($unload, 'fieldDeliver(');
$sentAt = strpos($unload, 'area._v3Sent = null');
note_assert($beaconAt !== false && $deliverAt !== false && $beaconAt < $deliverAt, 'pagehide köar sendBeacon före fetch keepalive');
note_assert($sentAt !== false && $sentAt < $beaconAt, 'pagehide nollar pågående skick innan beacon');
note_assert(!str_contains($js, '{"ok":true}'), 'sendBeacon låtsas inte att svaret är ok');
note_assert(str_contains($js, 'res.status !== 200'), 'Sparad. kräver HTTP 200');
note_assert(str_contains($js, 'data.ok !== true'), 'Sparad. kräver ok:true');
note_assert(str_contains($js, "ink.querySelector('[data-v3-autosave-status]')"), 'inköpslistans kvitto läses i sitt formulär');
note_assert(str_contains($js, 'textarea[data-v3-autosave-action]'), 'alla autosparade textfält binds');

$index = (string) file_get_contents(dirname(__DIR__) . '/index.php');
note_assert(str_contains($index, "case 'ordrar.install_note':"), 'endpointen ordrar.install_note finns');
note_assert(preg_match("/case 'ordrar\\.install_note':.*?return 'Sparad\\.';/s", $index) === 1, 'endpointen svarar Sparad.');

$saveSrc = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$fnAt = strpos($saveSrc, 'function jeab_ordrar_save_install_note');
$fn = $fnAt === false ? '' : substr($saveSrc, $fnAt, 1400);
note_assert($fn !== '' && !str_contains($fn, 'personnummer') && !str_contains($fn, 'error_log'), 'antespar loggar inte personnummer');
note_assert(str_contains($fn, 'note_rev') && str_contains($fn, 'install_note_rev') && str_contains($fn, '$prevRev > $rev'), 'äldre revision skrivs inte tillbaka');

$node = trim((string) shell_exec('command -v node'));
note_assert($node !== '', 'node finns');
$run = $node . ' ' . escapeshellarg(dirname(__DIR__) . '/tests/ordrar_note_keepalive_run.js');
$out = [];
$code = 0;
exec($run . ' 2>&1', $out, $code);
$text = implode("\n", $out);
if ($code !== 0) {
    fwrite(STDERR, $text . "\n");
}
note_assert($code === 0, 'debounce, blur, pagehide och omritning sparar en gång');
echo $text . "\n";
