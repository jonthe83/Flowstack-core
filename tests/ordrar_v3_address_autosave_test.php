<?php

declare(strict_types=1);

/**
 * N-53: adress-autosave i Ordrar v3 postar via fetch/XHR (ordrar.objekt + CSRF)
 * utan hel omladdning, så osparad inmatning i andra fält inte tappas.
 * Misslyckad sparning visas. Omladdning bara efter att övriga fält sparats eller varnats.
 */

$root = dirname(__DIR__);
$js = (string) file_get_contents($root . '/assets/js/ordrar-v3.js');
$v3php = (string) file_get_contents($root . '/src/ordrar_v3.php');

function aa_ok(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$secStart = strpos($js, '/* ---------- Objekt: mobil och e-post ---------- */');
$secEnd = strpos($js, '/* ---------- fakturarader ---------- */');
aa_ok($secStart !== false && $secEnd !== false && $secEnd > $secStart, 'Objekt-sektionen finns');
$sec = substr($js, $secStart, $secEnd - $secStart);

$fnStart = strpos($sec, 'function lookupAddressSoon()');
aa_ok($fnStart !== false, 'lookupAddressSoon finns');
$fn = substr($sec, $fnStart);

aa_ok(!str_contains($fn, 'submitStep('), 'adress-autosave anropar inte submitStep');
aa_ok(!str_contains($fn, 'location.reload') && !str_contains($fn, 'window.location'), 'adress-autosave gör inte hel sidomladdning');
aa_ok(str_contains($fn, 'post(f, {})'), 'adress-autosave postar via befintlig post()/fetch');
aa_ok(str_contains($fn, "showErr('objekt'"), 'felrutan visas när sparningen misslyckas');
aa_ok(str_contains($fn, 'applyObjektSave(f, data && data.contact)'), 'orörda fält fylls från kontakten i svaret (N-53)');
$applyAt = strpos($js, 'function applyObjektSave');
$apply = $applyAt === false ? '' : substr($js, $applyAt, 1800);
aa_ok($apply !== '' && !str_contains($apply, 'row.gata') && !str_contains($apply, 'row.ort') && !str_contains($apply, 'row.fastighetsbeteckning'), 'applyObjektSave läser inte orderns egna adressfält');
$idx = (string) file_get_contents(dirname(__DIR__) . '/index.php');
aa_ok(str_contains($idx, "\$payload['contact'] = jeab_ordrar_objekt_contact_fill(\$last)"), 'ordrar.objekt-svaret har kontaktens adress');
$ord = (string) file_get_contents(dirname(__DIR__) . '/src/ordrar.php');
$fAt = strpos($ord, 'function jeab_ordrar_objekt_contact_fill');
$fSrc = $fAt === false ? '' : substr($ord, $fAt, 1200);
aa_ok($fSrc !== '' && str_contains($fSrc, 'jeab_contacts_sole_view_for') && !str_contains($fSrc, "\$row['gata']") && !str_contains($fSrc, "\$row['ort']"), 'kontaktfyllningen läser bara Kontakter');
aa_ok(str_contains($fn, "toast('Adress sparad.')"), 'lyckad autosave utan uppslag laddar inte om');

aa_ok(str_contains($sec, 'function unsavedOutside('), 'osparade fält i andra formulär upptäcks');
aa_ok(str_contains($sec, 'inkSave()'), 'inköpslistan sparas före omladdning');
aa_ok(str_contains($sec, 'unsavedOutside(form)'), 'omladdning väntar på övriga osparade fält');
aa_ok(str_contains($sec, 'Spara övriga fält först'), 'osparad inmatning varnas i stället för att tappas');

$reloadAt = strpos($sec, 'reloadTo(step, msg)');
$warnAt = strpos($sec, 'unsavedOutside(form)');
aa_ok($reloadAt !== false && $warnAt !== false && $warnAt < $reloadAt, 'reloadTo körs bara efter koll av osparade fält');

$postStart = strpos($js, 'function post(form, extra, prebuilt, signal, attempt)');
$postEnd = strpos($js, 'function rebindShell()');
aa_ok($postStart !== false && $postEnd !== false && $postEnd > $postStart, 'post() finns');
$post = substr($js, $postStart, $postEnd - $postStart);
aa_ok(str_contains($post, 'new FormData(form)') || str_contains($js, 'function formBody(form, extra)'), 'CSRF följer med i FormData från formuläret');
aa_ok(str_contains($post, "headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }"), 'post är XHR mot JSON-endpointen');
aa_ok(str_contains($js, "body.set('v3', '1')"), 'v3=1 ger JSON-svar från ordrar.objekt');

$objektFormAt = strpos($v3php, 'id="v3-f-objekt"');
aa_ok($objektFormAt !== false, 'objektformuläret ritas');
$objektChunk = substr($v3php, $objektFormAt - 200, 900);
aa_ok(str_contains($objektChunk, 'jeab_csrf_field()'), 'objektformuläret har CSRF-fält');
aa_ok(str_contains($objektChunk, 'ordrar.objekt'), 'objektformuläret postar till ordrar.objekt');

aa_ok(str_contains($js, 'submitStep(form, submitter)'), 'Spara-knappen postar fortfarande via submitStep');

require_once dirname(__DIR__) . '/src/ordrar.php';
$fill = jeab_ordrar_objekt_contact_fill(['gata' => 'Ordergatan 1', 'ort' => 'Orderort', 'postnummer' => '11111', 'fastighetsbeteckning' => 'ORDER 1:1']);
aa_ok(!in_array('Ordergatan 1', $fill, true) && !in_array('Orderort', $fill, true) && !in_array('ORDER 1:1', $fill, true), 'utan länkad kontakt fylls inget från orderns kopia');
echo "ALL OK\n";
