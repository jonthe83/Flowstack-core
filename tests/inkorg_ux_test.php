<?php

declare(strict_types=1);

/** Inkorg UX-runda: vyfilter, mejlbox, läst/oläst, papperskorg, sök, kontakter, testa regel. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-inkux-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export(['timezone' => 'Europe/Stockholm', 'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json']], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ux(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$mail = fn(string $id, string $from, string $to, string $subj, string $body) => ['message_id' => "<{$id}@ux>", 'from' => $from, 'from_name' => 'Kund ' . $id, 'to' => $to, 'subject' => $subj, 'body_text' => $body, 'source' => 'test-ux', 'test' => true];
$a = jeab_inkorg_ingest($mail('a', 'anna@example.se', 'info@malmo.example.test', 'Solceller till villan', "Hej, vill ha solceller.\nAnna\nStorgatan 12, 211 22 Malmö\n070-123 45 67"));
$b = jeab_inkorg_ingest($mail('b', 'bo@example.se', 'info@jarfalla.example.test', 'Batteri', 'Hej, vi vill ha ett batteri.'));
$c = jeab_inkorg_ingest($mail('c', 'okand@example.se', 'inkorg+inkorg@example.test', 'Fråga', 'Hej, en fråga om faktura.'));
ux($a['ok'] && $b['ok'] && $c['ok'], 'tre testmejl intagna');

// Vyer: Sol = etikett sol, Batteri = etikett batteri, Allt = alla öppna, standard = Öppna.
$tA = $a['thread_id'];
$tB = $b['thread_id'];
$malmoWrite = null;
try {
    jeab_inkorg_thread_set($tA, 'open', ['sol']);
} catch (Throwable $e) {
    $malmoWrite = $e;
}
ux($malmoWrite instanceof JeabInkorgWriteException && jeab_inkorg_error_status($malmoWrite) === 403, 'Malmö-tråd tar inte emot status eller etikett');
jeab_inkorg_thread_write($tA, function (array $t): array {
    $t['status'] = 'open';
    $t['tags'] = ['sol'];
    return $t;
});
jeab_inkorg_thread_set($tB, 'open', ['batteri']);
$views = array_column(jeab_inkorg_views(), null, 'id');
$ids = fn(string $v, array $extra = []) => array_column(array_values(array_filter(jeab_inkorg_threads(), fn($t) => jeab_inkorg_thread_matches($t, $views[$v]['filter'] + $extra))), 'id');
ux($ids('v-solceller') === [$tA], 'Solceller visar bara etikett Sol');
ux($ids('v-batteri-oppna') === [$tB], 'Batteri visar bara etikett Batteri');
ux($ids('v-osorterat') === [$c['thread_id']], 'Osorterat visar ärenden utan produktetikett');
ux($ids('v-elbilsladdare') === [], 'Elbilsladdare visar bara etikett Elbilsladdare');
ux($ids('v-elbil-osorterat') === [$c['thread_id']], 'Elbilsladdare + Osorterat');
ux(!isset($views['v-sol']) && !isset($views['v-batteri']) && in_array('v-servicekund', array_keys($views), true), 'gamla Sol/Batteri ersatta, Service finns');
ux(count($ids('v-allt')) === 3, 'Allt visar alla öppna');
ux(jeab_inkorg_default_view(array_values($views), '') === 'v-oppna', 'standardvyn utan identitet är Öppna');
ux(jeab_inkorg_default_view(array_values($views), 'SamL') === 'v-solceller', 'Sam L får Solceller');
ux(jeab_inkorg_default_view(array_values($views), 'Roberto') === 'v-batteri-oppna', 'Roberto får Batteri');
ux(jeab_inkorg_default_view(array_values($views), 'Elin') === 'v-elbil-osorterat', 'Elin får Elbilsladdare + Osorterat');
$sv = $views['v-osorterat']; $sv['owner'] = 'Roberto';
jeab_inkorg_view_save(['id' => 'v-osorterat', 'name' => 'Osorterat', 'owner' => 'Roberto'] + $sv['filter']);
$moved = array_values(array_filter(jeab_inkorg_views(), fn($v) => $v['id'] !== 'v-batteri-oppna'));
ux(jeab_inkorg_default_view($moved, 'Roberto') === 'v-osorterat', 'standardvyn styrs av ägaren som sparas på Vyer');
ux(array_column(jeab_inkorg_views(), null, 'id')['v-osorterat']['filter']['tags_any'] === ['ingen'], 'etikettfilter sparas');
$menu = array_column(jeab_inkorg_menu_views(jeab_inkorg_views()), 'name');
ux($menu === ['Sam L', 'Roberto', 'Elin', 'Service', 'Parkerat', 'Information', 'Historik'], 'menyn har produktvyerna och Historik, utan offertvy eller papperskorg: ' . implode(', ', $menu));
ux(count(jeab_inkorg_views()) > 7 && in_array('v-skrap', array_column(jeab_inkorg_menu_views(jeab_inkorg_views(), 'v-skrap'), 'id'), true), 'dolda vyer finns kvar och öppen vy syns');

// Mejlbox (Malmö/Järfälla) från mottagaradressen; okänd mejlbox syns i båda.
$th = array_column(jeab_inkorg_threads(), null, 'id');
ux($th[$tA]['brand'] === 'malmo' && $th[$tB]['brand'] === 'jarfalla' && $th[$c['thread_id']]['brand'] === '', 'mejlbox läses ur mottagaren');
$m = $ids('v-oppna', ['brand' => 'malmo', 'brand_keep_unknown' => true]);
ux(in_array($tA, $m, true) && !in_array($tB, $m, true) && in_array($c['thread_id'], $m, true), 'Malmö-filtret döljer Järfälla-ärenden');
$j = $ids('v-oppna', ['brand' => 'jarfalla', 'brand_keep_unknown' => true]);
ux(in_array($tB, $j, true) && !in_array($tA, $j, true), 'Järfälla-filtret döljer Malmö-ärenden');

// Läst/oläst per person.
ux(jeab_inkorg_is_unread($th[$tA], 'Elin') && jeab_inkorg_is_unread($th[$tA], 'Roberto'), 'nytt ärende är oläst för alla');
jeab_inkorg_mark_read($tA, 'Elin');
$th = array_column(jeab_inkorg_threads(), null, 'id');
ux(!jeab_inkorg_is_unread($th[$tA], 'Elin') && jeab_inkorg_is_unread($th[$tA], 'Roberto'), 'läst gäller bara den som öppnade');

// Sök över rubrik, avsändare och text.
ux($ids('v-oppna', ['q' => 'faktura']) === [$c['thread_id']], 'sök hittar text i brödtexten');
ux($ids('v-oppna', ['q' => 'bo@example']) === [$tB], 'sök hittar avsändare');

// Radera = papperskorg, kan återställas.
jeab_inkorg_trash($tB, true, 'Test');
ux(!in_array($tB, $ids('v-oppna'), true) && $ids('v-papperskorg') === [$tB], 'raderat ärende ligger i papperskorgen');
jeab_inkorg_trash($tB, false, 'Test');
ux(in_array($tB, $ids('v-oppna'), true), 'återställt ärende syns igen');

// Kontakter: skapas från mejl, mobil och adress ur signaturen, kopplas på e-post eller mobil.
$all = jeab_inkorg_contacts();
ux(count($all) === 3, 'en kontakt per avsändare');
$ca = jeab_inkorg_contact_for_thread($th[$tA]);
ux($ca !== null && $ca['mobile'] === '+46701234567' && $ca['town'] === 'Malmö' && str_contains($ca['address'], 'Storgatan 12'), 'mobil och adress ur signaturen');
ux(!empty($ca['test']) && $ca['source'] === 'test-ux', 'testkontakt märkt med källa');
jeab_inkorg_ingest($mail('a2', 'anna.jobb@example.se', 'info@malmo.example.test', 'Ny fråga', "Ringer från jobbet\n+46 70 123 45 67"));
ux(count(jeab_inkorg_contacts()) === 3, 'nytt mejl med samma mobil kopplas till samma kontakt');
$caId = $ca['id'];
$ca = ['id' => $caId] + jeab_inkorg_contact($caId);
ux(in_array('anna.jobb@example.se', $ca['emails'], true), 'kontakten fick den nya e-posten');
ux(count(jeab_inkorg_contact_threads($ca, jeab_inkorg_threads())) === 2, 'kontaktens ärenden: båda');
$saved = jeab_inkorg_contact_save(['id' => $ca['id'], 'name' => 'Anna A', 'emails' => 'anna@example.se, anna.jobb@example.se', 'mobile' => '0701234567', 'address' => 'Storgatan 12', 'town' => 'Malmö', 'notes' => 'Vill bli uppringd']);
ux($saved['name'] === 'Anna A' && $saved['mobile'] === '+46701234567', 'kontakten kan redigeras, mobil normaliseras');
jeab_inkorg_ingest($mail('a3', 'anna@example.se', 'info@malmo.example.test', 'Tack', 'Tack!'));
ux(jeab_inkorg_contact($ca['id'])['name'] === 'Anna A', 'redigerat namn skrivs inte över av nya mejl');
ux(jeab_inkorg_contact_matches(jeab_inkorg_contact($ca['id']), '070-123 45 67') && jeab_inkorg_contact_matches(jeab_inkorg_contact($ca['id']), 'uppringd'), 'kontakter söks på mobil och anteckning');
ux(jeab_inkorg_phone_norm('0046 70 123 45 67') === '+46701234567' && jeab_inkorg_phone_norm('123') === '', 'telefon normaliseras +46');

// Testa regel: visar träffar utan att ändra något.
$before = md5((string) file_get_contents(jeab_inkorg_dir() . '/rules.json')) . md5((string) json_encode(jeab_inkorg_messages()));
$r = jeab_inkorg_rule_test(['match' => 'all', 'conditions' => [['field' => 'subject', 'op' => 'contains', 'value' => 'batteri']]]);
$after = md5((string) file_get_contents(jeab_inkorg_dir() . '/rules.json')) . md5((string) json_encode(jeab_inkorg_messages()));
ux($r['count'] === 1 && $r['hits'][0]['from'] === 'bo@example.se' && $before === $after, 'testa regel visar träffar och ändrar inget');

// Listtid som Help Scout.
$now = strtotime('2026-11-10 15:00:00');
ux(jeab_inkorg_list_time('2026-11-10T09:05:00', $now) === '09:05' && jeab_inkorg_list_time('2026-11-04T09:05:00', $now) === '4 nov', 'tid: klockslag i dag, annars «4 nov»');

// Rensa testkälla tar även testkontakterna.
jeab_inkorg_delete_source('test-ux');
ux(jeab_inkorg_contacts() === [], 'testkontakter rensas med sin batch');
echo "inkorg_ux_test klart\n";
