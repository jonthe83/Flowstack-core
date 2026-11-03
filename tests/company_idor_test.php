<?php

declare(strict_types=1);

/** Inloggad personal har båda kontoren i jeab_actor_companies() (hemma först). Väljaren, Inkorgens ärendeåtgärder och register som använder samma lista följer den. Nya mejl och bilagor styrs av jeab_inkorg_person_companies() och stannar på hemma-kontoret. Okänd inloggning utan person har tom lista. */

$tmp = sys_get_temp_dir() . '/jeab-idor-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
$passwords = $tmp . '/passwords.json';
file_put_contents($passwords, json_encode([
    'secret' => 'test-secret-value',
    'hashes' => [
        ['hash' => 'elin-hash-fixture', 'email' => 'saljare4@jarfalla.example.test'],
        ['hash' => 'isaq-hash-fixture', 'email' => 'saljare6@malmo.example.test'],
        ['hash' => 'sam-hash-fixture', 'email' => 'demo@example.test'],
        ['hash' => 'samw-hash-fixture', 'email' => 'demo@malmo.example.test'],
        ['hash' => 'agenter-hash-fixture', 'email' => 'agenter@flowstack.example.test'],
    ],
], JSON_UNESCAPED_UNICODE));
putenv('JEAB_PASSWORDS_FILE=' . $passwords);
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/layout.php';
jeab_ordrar_external_live(false);

function idor(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function idor_throws(callable $fn, string $needle, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        idor(str_contains($e->getMessage(), $needle), $label . ' (' . $e->getMessage() . ')');
        return;
    }
    fwrite(STDERR, "FAIL {$label} kastade inte\n");
    exit(1);
}

function idor_login(?string $email, ?string $hash): void
{
    $_GET = [];
    $_POST = [];
    unset($GLOBALS['_JEAB_INKORG_BOX_PIN'], $GLOBALS['_JEAB_BOOKING_CACHE']);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION = [];
    if ($hash === null) {
        unset($_COOKIE['jeab_auth']);
        return;
    }
    $_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value($email, $hash);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
@mkdir(jeab_inkorg_dir(), 0775, true);
file_put_contents(jeab_inkorg_dir() . '/settings.json', json_encode(['helpscout_companies' => []]));
$GLOBALS['_IDOR_SENDS'] = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url, ?array $body = null): array {
    if ($method === 'POST' && (str_contains($url, '/sendMail') || str_contains($url, '/reply'))) {
        $GLOBALS['_IDOR_SENDS'][] = [$url, $body];
        return ['code' => 202, 'body' => []];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};

$store = jeab_store();
$stamp = jeab_now();
$tz = new DateTimeZone('Europe/Stockholm');
$day = (new DateTimeImmutable('now', $tz))->setTime(0, 0)->modify('+21 days');
while (!jeab_ordrar_is_workday($day)) {
    $day = $day->modify('+1 day');
}
$start = $day->format('Y-m-d') . ' 09:00:00';
$end = $day->format('Y-m-d') . ' 10:00:00';

idor_login(null, null);
$jfRem = $store->insertReminder([
    'title' => 'Järfälla påminnelse',
    'customer_name' => 'Ada Järfälla',
    'company' => 'jarfalla',
    'person' => 'Elin',
    'assignees' => ['Elin'],
    'due_at' => $stamp,
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
$moRem = $store->insertReminder([
    'title' => 'Malmö påminnelse',
    'customer_name' => 'Bo Malmö',
    'company' => 'malmo',
    'person' => 'Isaq',
    'assignees' => ['Isaq'],
    'due_at' => $stamp,
    'created_at' => $stamp,
    'updated_at' => $stamp,
]);
$jfId = (int) $jfRem['id'];
$moId = (int) $moRem['id'];
jeab_purchase_put(['reminder_id' => $jfId, 'company' => 'jarfalla', 'name' => 'Ada Järfälla', 'kind' => 'laddbox']);
jeab_purchase_put(['reminder_id' => $moId, 'company' => 'malmo', 'name' => 'Bo Malmö', 'kind' => 'laddbox']);
$jfBook = jeab_create_booking($store, 'Ada Järfälla', ['Elin'], $start, $end, 'laddbox', [
    'email' => 'ada@example.test',
    'phone' => '0701112233',
    'address' => 'Exempelgatan 1',
    'company' => 'jarfalla',
], false);
$moBook = jeab_create_booking($store, 'Bo Malmö', ['Kim'], $start, $end, 'laddbox', [
    'email' => 'bo@example.test',
    'phone' => '0701112244',
    'address' => 'Exempelgatan 2',
    'company' => 'malmo',
], false);
$jfBookId = (int) $jfBook['id'];
$moBookId = (int) $moBook['id'];
$jfContact = jeab_kontakt_save_input([
    'street' => 'Järfällagatan 1',
    'postal_code' => '17730',
    'city' => 'Järfälla',
    'owner_name_1' => 'Ada Järfälla',
    'company' => 'jarfalla',
]);
$moContact = jeab_kontakt_save_input([
    'street' => 'Malmögatan 2',
    'postal_code' => '21120',
    'city' => 'Malmö',
    'owner_name_1' => 'Bo Malmö',
    'company' => 'malmo',
]);
$jfMsg = jeab_inkorg_ingest([
    'message_id' => 'idor-jf@example.test',
    'from' => 'ada@example.test',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Järfälla ärende',
    'body_text' => 'Hej från Järfälla.',
    'source' => 'graph-jarfalla',
    'company' => 'jarfalla',
]);
$moMsg = jeab_inkorg_ingest([
    'message_id' => 'idor-mo@example.test',
    'from' => 'bo@example.test',
    'to' => 'kontakt@malmo.example.test',
    'subject' => 'Malmö ärende',
    'body_text' => 'Hej från Malmö.',
    'source' => 'graph-malmo',
    'company' => 'malmo',
]);
$jfTid = (string) $jfMsg['thread_id'];
$moTid = (string) $moMsg['thread_id'];

// Utan person: adressen får fortfarande välja bolag (cron, CLI, djuplänk utan inloggning).
$_GET = ['co' => 'malmo'];
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
idor(jeab_selected_company() === 'malmo', 'utan person följer ?co=malmo');
$_GET = ['box' => 'malmo'];
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
idor(jeab_inkorg_box() === 'malmo', 'utan person följer ?box=malmo');

// Elin, Järfälla. Listan är båda kontoren med Järfälla först. Nya mejl stannar på Järfälla.
idor_login('saljare4@jarfalla.example.test', 'elin-hash-fixture');
idor(jeab_auth_ok() && jeab_inkorg_person_companies() === ['jarfalla'] && jeab_actor_companies() === ['jarfalla', 'malmo'], 'Elin är Järfälla först och får båda kontoren');
$_GET = [];
unset($_SESSION['jeab_company']);
idor(jeab_selected_company() === 'jarfalla', 'Elin: utan val är Järfälla förvalet');
$_GET = ['co' => 'malmo'];
idor(jeab_selected_company() === 'malmo', 'Elin: ?co=malmo byter kontor');
$_SESSION['jeab_company'] = 'malmo';
$_GET = [];
idor(jeab_selected_company() === 'malmo', 'Elin: sparat Malmö-kontor ligger kvar');
$_GET = ['co' => 'jarfalla'];
idor(jeab_selected_company() === 'jarfalla', 'Elin: ?co=jarfalla fungerar');
$_GET = ['box' => 'malmo'];
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
idor(jeab_inkorg_box() === 'malmo', 'Elin: ?box=malmo öppnar Malmö');
$_SESSION['jeab_inkorg_box'] = 'malmo';
$_GET = [];
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
idor(jeab_inkorg_box() === 'malmo', 'Elin: sparad Malmö-box ligger kvar');
$_GET = ['box' => 'jarfalla'];
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
idor(jeab_inkorg_box() === 'jarfalla', 'Elin: ?box=jarfalla fungerar');
$chrome = jeab_chrome_company_html('reminders');
idor(str_contains($chrome, 'Järfälla') && str_contains($chrome, 'Malmö') && !str_contains($chrome, 'Alla kontor'), 'Elin ser båda kontoren i väljaren');

idor(is_array(jeab_get_reminder($store, $jfId)) && is_array(jeab_get_reminder($store, $moId)), 'Elin läser båda påminnelserna');
$cross = jeab_update_reminder($store, $moId, ['title' => 'Malmö via Elin']);
idor((string) ($cross['title'] ?? '') === 'Malmö via Elin', 'Elin sparar Malmö-påminnelsen');
$own = jeab_update_reminder($store, $jfId, ['title' => 'Järfälla uppdaterad']);
idor((string) ($own['title'] ?? '') === 'Järfälla uppdaterad', 'Elin sparar sin påminnelse');

idor((string) (jeab_ordrar_require($moId)['company'] ?? '') === 'malmo' && (string) (jeab_ordrar_require($jfId)['company'] ?? '') === 'jarfalla', 'Elin läser båda ordrarna');
idor(jeab_ordrar_visible_get($moId) !== null, 'Elin ser Malmö-ordern');

idor(is_array(jeab_get_booking($store, $moBookId)) && is_array(jeab_get_booking($store, $jfBookId)), 'Elin läser båda bokningarna');
$bookedMo = jeab_update_booking($store, $moBookId, [
    'customer' => 'Bo Malmö',
    'assignees' => ['Kim'],
    'starts_at' => $start,
    'ends_at' => $end,
    'notes' => 'elin',
    'company' => 'malmo',
]);
idor((string) ($bookedMo['notes'] ?? '') === 'elin', 'Elin sparar Malmö-bokningen');
$booked = jeab_update_booking($store, $jfBookId, [
    'customer' => 'Ada Järfälla',
    'assignees' => ['Elin'],
    'starts_at' => $start,
    'ends_at' => $end,
    'notes' => 'egen',
    'company' => 'jarfalla',
]);
idor((string) ($booked['notes'] ?? '') === 'egen', 'Elin sparar sin bokning');

idor(is_array(jeab_kontakt_get((string) $moContact['id'])) && is_array(jeab_kontakt_get((string) $jfContact['id'])), 'Elin läser båda kontakterna');
$savedContact = jeab_kontakt_save_input([
    'id' => (string) $moContact['id'],
    'street' => 'Elingatan 9',
    'postal_code' => '21120',
    'city' => 'Malmö',
    'owner_name_1' => 'Bo Malmö',
    'company' => 'malmo',
]);
idor((string) ($savedContact['street'] ?? '') === 'Elingatan 9', 'Elin sparar Malmö-kontakten');

$sends = count($GLOBALS['_IDOR_SENDS']);
$sentMo = jeab_inkorg_send_in_thread($moTid, ['body' => 'Svar från Elin i Malmö-ärendet'], 'Elin');
idor((string) ($sentMo['state'] ?? '') === 'sent' && (string) ($sentMo['from'] ?? '') === 'kontakt@malmo.example.test' && count($GLOBALS['_IDOR_SENDS']) === $sends + 1, 'Elin svarar i Malmö-ärendet');
$elinSendUrl = (string) ($GLOBALS['_IDOR_SENDS'][count($GLOBALS['_IDOR_SENDS']) - 1][0] ?? '');
idor(str_contains($elinSendUrl, 'malmo-demo') && !str_contains($elinSendUrl, 'jarfalla-demo'), 'Elins svar går från Malmös brevlåda');
$_POST = ['thread' => $moTid, 'read' => '1'];
idor(jeab_inkorg_action('inkorg.read') === 'Markerat som läst.', 'Elin kan markera Malmö-ärendet läst');
$_POST = ['thread' => $moTid];
idor(str_starts_with(jeab_inkorg_action('inkorg.trash'), 'Ärendet flyttat till papperskorgen'), 'Elin kan slänga Malmö-ärendet');
$_POST = ['thread' => $moTid];
idor(jeab_inkorg_action('inkorg.restore') === 'Ärendet återställt.', 'Elin kan återställa Malmö-ärendet');
$_POST = ['thread' => $moTid, 'assignee' => 'Kim'];
idor(jeab_inkorg_action('inkorg.assign') === 'Tilldelning sparad.', 'Elin kan tilldela Malmö-ärendet');
$elinCounts = jeab_inkorg_hook(['action' => 'inkorg.counts', 'box' => 'malmo']);
idor(($elinCounts['ok'] ?? false) === true && ($elinCounts['box'] ?? '') === 'malmo', 'Elin kan räkna Malmö');
$ownCounts = jeab_inkorg_hook(['action' => 'inkorg.counts', 'box' => 'jarfalla']);
idor(($ownCounts['ok'] ?? false) === true && ($ownCounts['box'] ?? '') === 'jarfalla', 'Elin räknar Järfälla');
$sends = count($GLOBALS['_IDOR_SENDS']);
$sent = jeab_inkorg_send_in_thread($jfTid, ['body' => 'Svar i eget bolag'], 'Elin');
idor((string) ($sent['state'] ?? '') === 'sent' && (string) ($sent['from'] ?? '') === 'kontakt@jarfalla.example.test' && count($GLOBALS['_IDOR_SENDS']) === $sends + 1, 'Elin svarar i Järfälla-ärendet');

// Isaq, Malmö. Samma flöde åt andra hållet.
idor_login('saljare6@malmo.example.test', 'isaq-hash-fixture');
idor(jeab_inkorg_person_companies() === ['malmo'] && jeab_actor_companies() === ['malmo', 'jarfalla'], 'Isaq är Malmö först och får båda kontoren');
$_GET = [];
unset($_SESSION['jeab_company'], $_SESSION['jeab_inkorg_box'], $GLOBALS['_JEAB_INKORG_BOX_PIN']);
idor(jeab_selected_company() === 'malmo' && jeab_inkorg_box() === 'malmo', 'Isaq: utan val är Malmö förvalet');
$_GET = ['co' => 'jarfalla'];
idor(jeab_selected_company() === 'jarfalla', 'Isaq: ?co=jarfalla byter kontor');
$_GET = ['co' => 'malmo'];
idor(jeab_selected_company() === 'malmo', 'Isaq: ?co=malmo fungerar');
$_GET = ['box' => 'jarfalla'];
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
idor(jeab_inkorg_box() === 'jarfalla', 'Isaq: ?box=jarfalla öppnar Järfälla');
$_GET = ['box' => 'malmo'];
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
idor(jeab_inkorg_box() === 'malmo', 'Isaq: ?box=malmo fungerar');
idor(is_array(jeab_get_reminder($store, $jfId)) && is_array(jeab_get_reminder($store, $moId)), 'Isaq läser båda påminnelserna');
idor((string) (jeab_ordrar_require($jfId)['company'] ?? '') === 'jarfalla' && (string) (jeab_ordrar_require($moId)['company'] ?? '') === 'malmo', 'Isaq läser båda ordrarna');
idor(is_array(jeab_get_booking($store, $jfBookId)) && is_array(jeab_get_booking($store, $moBookId)), 'Isaq läser båda bokningarna');
idor(is_array(jeab_kontakt_get((string) $jfContact['id'])) && is_array(jeab_kontakt_get((string) $moContact['id'])), 'Isaq läser båda kontakterna');
$sends = count($GLOBALS['_IDOR_SENDS']);
$isaqSent = jeab_inkorg_send_in_thread($jfTid, ['body' => 'Svar från Isaq i Järfälla'], 'Isaq');
idor((string) ($isaqSent['state'] ?? '') === 'sent' && (string) ($isaqSent['from'] ?? '') === 'kontakt@jarfalla.example.test' && count($GLOBALS['_IDOR_SENDS']) === $sends + 1, 'Isaq svarar i Järfälla-ärendet');
$isaqSendUrl = (string) ($GLOBALS['_IDOR_SENDS'][count($GLOBALS['_IDOR_SENDS']) - 1][0] ?? '');
idor(str_contains($isaqSendUrl, 'jarfalla-demo') && !str_contains($isaqSendUrl, 'malmo-demo'), 'Isaqs svar går från Järfällas brevlåda');
$_POST = ['thread' => $jfTid, 'status' => 'open'];
idor(jeab_inkorg_action('inkorg.thread') === 'Ärendet sparat.', 'Isaq kan öppna Järfälla-ärendet igen');
$_POST = ['thread' => $jfTid, 'status' => 'closed'];
idor(jeab_inkorg_action('inkorg.thread') === 'Ärendet sparat.', 'Isaq kan stänga Järfälla-ärendet');
$_POST = ['thread' => $jfTid, 'status' => 'open'];
jeab_inkorg_action('inkorg.thread');
$_POST = ['thread' => $jfTid];
idor(str_starts_with(jeab_inkorg_action('inkorg.trash'), 'Ärendet flyttat till papperskorgen'), 'Isaq kan slänga Järfälla-ärendet');
$_POST = ['thread' => $jfTid];
idor(jeab_inkorg_action('inkorg.restore') === 'Ärendet återställt.', 'Isaq kan återställa Järfälla-ärendet');
$_POST = ['thread' => $jfTid, 'assignee' => 'Elin'];
idor(jeab_inkorg_action('inkorg.assign') === 'Tilldelning sparad.', 'Isaq kan tilldela Järfälla-ärendet');
$assignIds = array_keys(jeab_inkorg_assign_staff('jarfalla'));
idor(in_array('Elin', $assignIds, true) && in_array('Isaq', $assignIds, true) && array_search('Elin', $assignIds, true) < array_search('Isaq', $assignIds, true), 'tilldelning visar båda kontoren med Järfälla först');
$isaqCounts = jeab_inkorg_hook(['action' => 'inkorg.counts', 'box' => 'jarfalla']);
idor(($isaqCounts['ok'] ?? false) === true && ($isaqCounts['box'] ?? '') === 'jarfalla', 'Isaq kan räkna Järfälla');

// Sam utan kodhuvud är ingen person och inte admin. Domänen ger hemma-kontoret först och båda kontoren.
idor_login('demo@example.test', 'sam-hash-fixture');
idor(jeab_auth_user_person() === null && !jeab_is_admin() && !jeab_inkorg_is_admin() && jeab_actor_companies() === ['jarfalla', 'malmo'], 'Sam L utan huvud är inte admin och har båda kontoren');
$_GET = ['co' => 'malmo'];
idor(jeab_selected_company() === 'malmo', 'Sam: ?co=malmo gäller');
$_GET = ['co' => 'jarfalla'];
idor(jeab_selected_company() === 'jarfalla', 'Sam: ?co=jarfalla gäller');
$_GET = ['box' => 'malmo'];
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
idor(jeab_inkorg_box() === 'malmo', 'Sam: ?box=malmo gäller');
$_GET = ['box' => 'jarfalla'];
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
idor(jeab_inkorg_box() === 'jarfalla', 'Sam: ?box=jarfalla gäller');
$adminCounts = jeab_inkorg_hook(['action' => 'inkorg.counts', 'box' => 'malmo']);
idor(($adminCounts['ok'] ?? false) === true && ($adminCounts['box'] ?? '') === 'malmo', 'Sam räknar Malmö');
$adminChrome = jeab_chrome_company_html('reminders');
idor(str_contains($adminChrome, 'Järfälla') && str_contains($adminChrome, 'Malmö'), 'Sam ser båda kontoren');
idor(is_array(jeab_get_reminder($store, $jfId)) && is_array(jeab_get_reminder($store, $moId)), 'Sam läser båda påminnelserna');
idor((string) (jeab_ordrar_require($jfId)['company'] ?? '') === 'jarfalla' && (string) (jeab_ordrar_require($moId)['company'] ?? '') === 'malmo', 'Sam läser båda ordrarna');
idor(is_array(jeab_get_booking($store, $jfBookId)) && is_array(jeab_get_booking($store, $moBookId)), 'Sam läser båda bokningarna');
idor(is_array(jeab_kontakt_get((string) $jfContact['id'])) && is_array(jeab_kontakt_get((string) $moContact['id'])), 'Sam läser båda kontakterna');
$_POST = ['thread' => $moTid, 'assignee' => 'Kim'];
idor(jeab_inkorg_action('inkorg.assign') === 'Tilldelning sparad.', 'Sam tilldelar Malmö-ärendet');

idor_login('demo@malmo.example.test', 'samw-hash-fixture');
idor(!jeab_is_admin() && jeab_auth_user_person() === null && jeab_actor_companies() === ['malmo', 'jarfalla'] && jeab_inkorg_person_companies() === [], 'Sam W utan huvud är inte admin, Malmö först, och mejlregistret följer inte e-posten');
$_GET = [];
unset($_SESSION['jeab_company']);
idor(jeab_selected_company() === 'malmo', 'Sam W: utan val är Malmö förvalet');
$_GET = ['co' => 'jarfalla'];
idor(jeab_selected_company() === 'jarfalla', 'Sam W: ?co=jarfalla byter kontor');
$_GET = ['box' => 'jarfalla'];
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
idor(jeab_inkorg_box() === 'jarfalla', 'Sam W: ?box=jarfalla öppnar Järfälla');
idor(is_array(jeab_get_reminder($store, $jfId)) && is_array(jeab_get_reminder($store, $moId)), 'Sam W läser båda påminnelserna');
idor((string) (jeab_ordrar_require($jfId)['company'] ?? '') === 'jarfalla', 'Sam W öppnar Järfälla-ordern');
idor(is_array(jeab_get_booking($store, $jfBookId)) && is_array(jeab_get_booking($store, $moBookId)), 'Sam W läser båda bokningarna');
idor(is_array(jeab_kontakt_get((string) $jfContact['id'])) && is_array(jeab_kontakt_get((string) $moContact['id'])), 'Sam W läser båda kontakterna');
$sends = count($GLOBALS['_IDOR_SENDS']);
$wSent = jeab_inkorg_send_in_thread($jfTid, ['body' => 'Svar från Sam W i Järfälla'], 'Sam W');
idor((string) ($wSent['state'] ?? '') === 'sent' && (string) ($wSent['from'] ?? '') === 'kontakt@jarfalla.example.test' && count($GLOBALS['_IDOR_SENDS']) === $sends + 1, 'Sam W svarar i Järfälla');
$wSendUrl = (string) ($GLOBALS['_IDOR_SENDS'][count($GLOBALS['_IDOR_SENDS']) - 1][0] ?? '');
idor(str_contains($wSendUrl, 'jarfalla-demo') && !str_contains($wSendUrl, 'malmo-demo'), 'Sam W:s svar går från Järfällas brevlåda');

// Delad agentkod: båda kontoren och admin.
idor_login('agenter@flowstack.example.test', 'agenter-hash-fixture');
$whoKey = jeab_who_cookie_info()['key'] ?? null;
idor(is_string($whoKey) && $whoKey !== '', 'agenter@ har en who-nyckel');
$_SESSION['jeab_who_pick'][$whoKey] = 'Kim';
idor(jeab_who_me_person() === null, 'sparat personval gör inte agentkoden till Kim');
idor(jeab_auth_user_person() === null && jeab_is_admin() && jeab_inkorg_is_admin() && jeab_inkorg_can_approve() && jeab_may_edit_webhook(), 'agenter@ är ingen person och är admin');
idor(jeab_actor_companies() === ['jarfalla', 'malmo'] && jeab_inkorg_person_companies() === ['jarfalla', 'malmo'] && jeab_shared_identity_companies() === ['jarfalla', 'malmo'], 'agenter@ har båda bolagen som delad identitet');
jeab_inkorg_require_admin();
idor(true, 'agenter@ kan spara inkorgsadmin');
$index = file_get_contents(dirname(__DIR__) . '/index.php');
idor(str_contains($index, "case 'calendar_log.cleanup':") && str_contains($index, "case 'booking.delete_silent':") && substr_count($index, 'jeab_is_admin()') >= 2, 'städning och tyst borttagning kräver admin');
idor(str_contains($index, 'jeab_may_edit_webhook()'), 'Zapier-webhook kräver admin');
$_GET = ['co' => 'malmo'];
idor(jeab_selected_company() === 'malmo', 'agenter@: ?co=malmo öppnar Malmö');
$_GET = ['co' => 'jarfalla'];
idor(jeab_selected_company() === 'jarfalla', 'agenter@: ?co=jarfalla öppnar Järfälla');
$_GET = ['box' => 'malmo'];
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
idor(jeab_inkorg_box() === 'malmo', 'agenter@: ?box=malmo öppnar Malmö trots who=Kim');
$_GET = ['box' => 'jarfalla'];
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
idor(jeab_inkorg_box() === 'jarfalla', 'agenter@: ?box=jarfalla öppnar Järfälla');
$agentChrome = jeab_chrome_company_html('reminders');
idor(str_contains($agentChrome, 'Järfälla') && str_contains($agentChrome, 'Malmö'), 'agenter@ ser båda kontoren');
idor(jeab_visible_company_names('jarfalla') === jeab_filter_staff('jarfalla') && jeab_visible_company_names('malmo') === jeab_filter_staff('malmo'), 'agenter@ ser personalistan i båda kontoren');
idor(is_array(jeab_get_reminder($store, $jfId)) && is_array(jeab_get_reminder($store, $moId)), 'agenter@ läser båda påminnelserna');
$agentRem = jeab_update_reminder($store, $moId, ['title' => 'Malmö via agentkod']);
idor((string) ($agentRem['title'] ?? '') === 'Malmö via agentkod', 'agenter@ sparar Malmö-påminnelsen');
idor((string) (jeab_ordrar_require($jfId)['company'] ?? '') === 'jarfalla' && (string) (jeab_ordrar_require($moId)['company'] ?? '') === 'malmo', 'agenter@ läser båda ordrarna');
idor(is_array(jeab_get_booking($store, $jfBookId)) && is_array(jeab_get_booking($store, $moBookId)), 'agenter@ läser båda bokningarna');
$agentBook = jeab_update_booking($store, $moBookId, [
    'customer' => 'Bo Malmö',
    'assignees' => ['Kim'],
    'starts_at' => $start,
    'ends_at' => $end,
    'notes' => 'agentkod',
    'company' => 'malmo',
]);
idor((string) ($agentBook['notes'] ?? '') === 'agentkod', 'agenter@ sparar Malmö-bokningen');
idor(is_array(jeab_kontakt_get((string) $jfContact['id'])) && is_array(jeab_kontakt_get((string) $moContact['id'])), 'agenter@ läser båda kontakterna');
idor(jeab_inkorg_fast_working_light('jarfalla') !== [] && jeab_inkorg_fast_working_light('malmo') !== [], 'agenter@ ser inkorgen i båda bolagen');
$sends = count($GLOBALS['_IDOR_SENDS']);
$agentSent = jeab_inkorg_send_in_thread($moTid, ['body' => 'Svar från agentkoden'], 'agenter');
idor((string) ($agentSent['state'] ?? '') === 'sent' && count($GLOBALS['_IDOR_SENDS']) === $sends + 1, 'agenter@ svarar i Malmö');
$agentSentJf = jeab_inkorg_send_in_thread($jfTid, ['body' => 'Svar från agentkoden i Järfälla'], 'agenter');
idor((string) ($agentSentJf['state'] ?? '') === 'sent' && count($GLOBALS['_IDOR_SENDS']) === $sends + 2, 'agenter@ svarar i Järfälla');
$_POST = ['thread' => $moTid, 'assignee' => 'Kim'];
idor(jeab_inkorg_action('inkorg.assign') === 'Tilldelning sparad.', 'agenter@ tilldelar Malmö-ärendet');
foreach (['jarfalla', 'malmo'] as $box) {
    $agentCounts = jeab_inkorg_hook(['action' => 'inkorg.counts', 'box' => $box]);
    idor(($agentCounts['ok'] ?? false) === true && ($agentCounts['box'] ?? '') === $box, 'agenter@ räknar ' . $box);
}

// Annan inloggning utan person: tom lista.
idor_login(null, null);
$_SESSION['jeab_email'] = 'okand@flowstack.example.test';
idor(jeab_auth_user_email() === 'okand@flowstack.example.test' && jeab_auth_user_person() === null && !jeab_is_admin() && !jeab_is_shared_login_email(jeab_auth_user_email()) && jeab_actor_companies() === [] && jeab_inkorg_person_companies() === [], 'okand@flowstack.example.test är inte admin och har inga bolag');
$_GET = ['co' => 'malmo'];
idor(jeab_selected_company() === 'jarfalla', 'okänd inloggning: ?co=malmo öppnar inte Malmö');
$_GET = ['box' => 'malmo'];
unset($GLOBALS['_JEAB_INKORG_BOX_PIN']);
idor(jeab_inkorg_box() === 'jarfalla', 'okänd inloggning: ?box=malmo öppnar inte Malmö');
idor(jeab_get_reminder($store, $jfId) === null && jeab_get_reminder($store, $moId) === null, 'okänd inloggning läser ingen påminnelse');
idor_throws(fn() => jeab_ordrar_require($jfId), 'Inte behörig', 'okänd inloggning kan inte öppna Järfälla-ordern');
idor_throws(fn() => jeab_ordrar_require($moId), 'Inte behörig', 'okänd inloggning kan inte öppna Malmö-ordern');
idor(jeab_get_booking($store, $jfBookId) === null && jeab_get_booking($store, $moBookId) === null, 'okänd inloggning läser ingen bokning');
idor(jeab_kontakt_get((string) $jfContact['id']) === null && jeab_kontakt_get((string) $moContact['id']) === null, 'okänd inloggning läser ingen kontakt');
$sends = count($GLOBALS['_IDOR_SENDS']);
idor_throws(fn() => jeab_inkorg_send_in_thread($moTid, ['body' => 'Fel'], 'okand'), 'Inte behörig', 'okänd inloggning kan inte svara');
idor(count($GLOBALS['_IDOR_SENDS']) === $sends, 'okänd inloggning anropar inte Graph');

echo "ALL OK\n";
