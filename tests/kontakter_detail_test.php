<?php

declare(strict_types=1);

/**
 * Kontaktdetalj via SQL, Alla kontor, ortens versaler och länk från påminnelse.
 * Påhittade rader. Skriver inte om befintliga kontakter i JSON.
 */

$tmp = sys_get_temp_dir() . '/jeab-kontakter-detail-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('DATA_BACKEND=mysql');
putenv('CONTACTS_SOLE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';

function kd(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

kd(jeab_kontakter_storage_mode() === 'sql', 'DATA_BACKEND=mysql läser kontakter från SQL');
kd(jeab_kontakter_sql_is_sole_source(), 'SQL är enda läskällan');
kd(jeab_kontakter_sql_set_storage('sql'), 'lagringsflaggan står på sql');

$pdo = jeab_contact_store_pdo();
kd($pdo instanceof PDO, 'SQLite-kopplingen finns');
$sql = new JeabPdoContactStore($pdo);
$jarfalla = [
    'id' => 'kt_detail_jarfalla',
    'street' => 'Detaljgatan 4',
    'street_key' => 'detaljgatan 4',
    'postal_code' => '17730',
    'city' => 'järfälla',
    'company' => 'jarfalla',
    'owners' => [[
        'name' => 'Ada Detalj',
        'contact' => true,
        'personnummer' => '',
        'email' => 'ada.detalj@example.test',
        'phone' => '',
    ]],
];
$malmo = [
    'id' => 'kt_detail_malmo',
    'street' => 'Limhamnsgatan 2',
    'street_key' => 'limhamnsgatan 2',
    'postal_code' => '21611',
    'city' => 'malmö',
    'company' => 'malmo',
    'owners' => [[
        'name' => 'Bo Detalj',
        'contact' => true,
        'personnummer' => '',
        'email' => 'bo.detalj@example.test',
        'phone' => '',
    ]],
];
$sql->upsert($jarfalla['id'], $jarfalla);
$sql->upsert($malmo['id'], $malmo);

kd(jeab_kontakt_city_name('järfälla') === 'Järfälla', 'järfälla blir Järfälla');
kd(jeab_kontakt_city_name('järfälla kommun, Sverige') === 'Järfälla', 'kommun och Sverige tas bort innan versalen');
kd((string) (jeab_kontakt_get($jarfalla['id'])['city'] ?? '') === 'järfälla', 'läsning skriver inte om orten i SQL');

$store = jeab_store();
$error = null;
$flash = null;
$_GET = ['p' => 'kontakter', 'id' => $jarfalla['id'], 'co' => 'jarfalla'];
$detail = require dirname(__DIR__) . '/src/pages/kontakter.php';
kd(is_string($detail), 'detaljsidan renderas');
kd(!str_contains($detail, 'Välj en kontakt i listan.'), 'id i adressen öppnar detaljen');
kd(str_contains($detail, 'name="street"') && str_contains($detail, 'Detaljgatan 4'), 'detaljen visar gatufältet');
kd(str_contains($detail, 'value="Järfälla"'), 'orten visas med versal');
kd(preg_match('/data-id="' . $jarfalla['id'] . '"[\s\S]*?href="([^"]+)"/', $detail, $href) === 1, 'listan har en länk för kontakten');
$link = html_entity_decode($href[1] ?? '', ENT_QUOTES);
kd(str_contains($link, 'id=' . $jarfalla['id']), 'länkens href bär samma id som SQL-raden');
kd((string) (jeab_kontakt_get($jarfalla['id'])['city'] ?? '') === 'järfälla', 'visningen skrev inte tillbaka orten');

$saved = jeab_kontakt_save_input([
    'id' => $jarfalla['id'],
    'street' => 'Detaljgatan 4',
    'postal_code' => '17730',
    'city' => 'järfälla',
    'owner_name_1' => 'Ada Detalj',
    'contact_person' => '1',
    'email' => 'ada.detalj@example.test',
    'company' => 'jarfalla',
]);
kd((string) ($saved['city'] ?? '') === 'Järfälla', 'sparning normaliserar orten');
kd((string) (jeab_kontakt_get($jarfalla['id'])['city'] ?? '') === 'Järfälla', 'sparning skriver den normaliserade orten');
kd((string) (jeab_kontakt_get($malmo['id'])['city'] ?? '') === 'malmö', 'en annan rad skrivs inte om');

$_GET = ['p' => 'kontakter', 'co' => 'alla'];
$all = require dirname(__DIR__) . '/src/pages/kontakter.php';
$allView = jeab_kontakter_view($store, '', 'alla', null, 50);
kd($sql->count() === 2 && $allView['total'] === 2 && $allView['counts']['alla'] === 2, 'Alla kontor räknar varje SQL-rad');
kd(str_contains($all, '>2<') && str_contains($all, 'Detaljgatan 4') && str_contains($all, 'Limhamnsgatan 2'), 'Alla kontor visar båda bolagen');
kd(str_contains($all, 'Malmö') && str_contains($all, 'Järfälla'), 'orterna visas med versal i listan');

$_GET = ['p' => 'kontakter', 'co' => 'alla'];
$chromeAll = jeab_chrome_company_html('kontakter');
kd(str_contains($chromeAll, 'Alla kontor') && str_contains($chromeAll, 'has-3'), 'kontakter har valet Alla kontor');
kd(preg_match('/<a class="chip is-on"[^>]*>Alla kontor<\/a>/', $chromeAll) === 1, 'Alla kontor är valt');
kd(!str_contains($chromeAll, 'chip is-on">Järfälla') && !str_contains($chromeAll, 'chip is-on">Malmö'), 'ett bolag är inte valt samtidigt');
kd(str_contains(html_entity_decode($chromeAll), 'co=alla'), 'länken behåller co=alla');

$_GET = ['p' => 'kontakter', 'co' => 'jarfalla'];
$chromeOne = jeab_chrome_company_html('kontakter');
kd(preg_match('/<a class="chip is-on"[^>]*>Järfälla<\/a>/', $chromeOne) === 1, 'Järfälla är valt när co=jarfalla');
kd(preg_match('/<a class="chip"[^>]*>Alla kontor<\/a>/', $chromeOne) === 1, 'Alla kontor syns men är inte valt');

$_GET = ['p' => 'reminders', 'co' => 'jarfalla'];
$chromeRem = jeab_chrome_company_html('reminders');
kd(!str_contains($chromeRem, 'Alla kontor'), 'påminnelser behåller två kontor');

$app = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
$fnAt = strpos($app, 'function bindItemMain(link)');
$fn = $fnAt === false ? '' : substr($app, $fnAt, 1400);
kd(str_contains($fn, 'data-kt-root') && str_contains($fn, 'page-kontakter'), 'klick på kontaktlistan öppnar inte en påminnelserad');
kd(strpos($fn, 'data-kt-root') < strpos($fn, "addEventListener('click'"), 'kontakten hoppas över innan klicket stoppas');
kd(str_contains($app, 'function bindReminderItemMains') && str_contains($app, 'isKontaktRow(link)'), 'påminnelsebindningen hoppar över kontaktrader');
kd(substr_count($app, '.forEach(bindItemMain)') === 0, 'item-main binds inte per rad utan filter');
$ktPage = (string) file_get_contents(dirname(__DIR__) . '/src/pages/kontakter.php');
$ktClick = strpos($ktPage, "root.addEventListener('click'");
kd($ktClick !== false && str_contains(substr($ktPage, $ktClick, 500), "closest('a.item-main')") && str_contains(substr($ktPage, $ktClick, 700), 'stopPropagation'), 'kontaktlistan har en delegerad radklick');
kd(substr_count($ktPage, "querySelectorAll('[data-kt-list] .item") === 0, 'kontaktsidan binder inte klick per rad');

$linked = [
    'id' => 41,
    'title' => 'Batteri',
    'customer_name' => 'Ada Detalj',
    'email' => 'ada.detalj@example.test',
    'phone' => '',
    'address' => 'Detaljgatan 4',
    'company' => 'jarfalla',
    'contact_id' => $jarfalla['id'],
    'due_at' => '2026-10-22 09:00:00',
    'created_at' => jeab_now(),
    'updated_at' => jeab_now(),
];
$card = jeab_reminder_row($linked, true);
kd(str_contains($card, 'class="item-kontakt"') && str_contains($card, '>Kontakt<'), 'påminnelsen har en kontaktlänk');
kd(str_contains(html_entity_decode($card), 'p=kontakter') && str_contains(html_entity_decode($card), 'id=' . $jarfalla['id']), 'länken går till contact_id');
$shutAt = strpos($card, 'item-head-shut');
$linkAt = strpos($card, 'item-kontakt');
$anchorEnd = $shutAt !== false ? strpos($card, '</a>', $shutAt) : false;
kd($anchorEnd !== false && $linkAt !== false && $linkAt > $anchorEnd, 'kontaktlänken ligger utanför radlänken');

$bare = $linked;
$bare['id'] = 42;
$bare['contact_id'] = '';
kd(!str_contains(jeab_reminder_row($bare, true), 'item-kontakt'), 'utan contact_id finns ingen kontaktlänk');

echo "kontakter detail ok\n";
