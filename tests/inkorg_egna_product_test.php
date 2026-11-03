<?php

declare(strict_types=1);

/**
 * Egna: tilldelat mig, plus otilldelat bara när produkten är min.
 * Otilldelat utan produkt och otilldelat med någon annans produkt stannar utanför.
 * Vykortet använder samma filter. Inget utskick.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-egna-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
$egJlHash = password_hash('SamLegna', PASSWORD_DEFAULT);
file_put_contents($tmp . '/passwords.json', json_encode([
    'secret' => bin2hex(random_bytes(16)),
    'hashes' => [
        ['email' => 'demo@example.test', 'hash' => $egJlHash],
    ],
], JSON_UNESCAPED_UNICODE));
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'passwords_file' => $tmp . '/passwords.json',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function eg(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

function eg_as(string $email): void
{
    $_SESSION['jeab_email'] = $email;
    unset($_COOKIE[JEAB_AUTH_COOKIE]);
    unset($GLOBALS['_JEAB_INKORG_UNREAD'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_THREADS']);
}

function eg_login_sam_l(): void
{
    global $egJlHash;
    $_SESSION['jeab_email'] = 'demo@example.test';
    jeab_code_people_save(jeab_auth_hash_fingerprint($egJlHash), 'SamL', 'prefix', 'SamL');
    $_COOKIE[JEAB_AUTH_COOKIE] = (string) jeab_auth_issue_value('demo@example.test', $egJlHash);
}

eg(jeab_inkorg_person_offers('SamL', 'jarfalla') === ['sol'], 'Sam L äger sol i Järfälla');
eg(jeab_inkorg_person_offers('Roberto', 'jarfalla') === ['batteri'], 'Roberto äger batteri');
eg(jeab_inkorg_person_offers('Elin', 'jarfalla') === ['laddbox'], 'Elin äger elbilsladdare');
eg(jeab_inkorg_person_offers('Kim', 'malmo') === ['batteri', 'laddbox'], 'Kim äger Malmös batteri och laddare');
eg(jeab_inkorg_person_offers('SamW', 'malmo') === ['sol'], 'Sam W äger Malmös sol');
eg(jeab_inkorg_person_offers('SamL', 'malmo') === [], 'Sam L äger ingen Malmö-produkt');
eg(jeab_inkorg_person_offers('Roberto', 'malmo') === [], 'Roberto äger ingen Malmö-produkt');

eg_as('saljare3@jarfalla.example.test');
eg(jeab_inkorg_me_id() === 'Roberto', 'inloggad som Roberto');
$mine = static fn (array $t): bool => jeab_inkorg_assignee_ok($t, 'me');
eg($mine(['assignee' => 'Roberto', 'company' => 'jarfalla', 'labels' => ['offer' => 'sol']]), 'tilldelat mig räknas även med annans produkt');
eg($mine(['assignee' => 'Roberto', 'company' => 'jarfalla', 'labels' => ['type' => 'Support']]), 'tilldelat mig utan produkt räknas');
eg($mine(['assignee' => '', 'company' => 'jarfalla', 'labels' => ['product' => 'batteri']]), 'otilldelat med min produkt räknas');
eg($mine(['assignee' => '', 'company' => 'jarfalla', 'labels' => ['type' => 'Support'], 'tags' => ['batteri']]), 'otilldelat med min produkttagg räknas');
eg(!$mine(['assignee' => '', 'company' => 'jarfalla', 'labels' => ['offer' => 'sol']]), 'otilldelat med annans produkt räknas inte');
eg(!$mine(['assignee' => '', 'company' => 'jarfalla', 'labels' => ['type' => 'Support']]), 'otilldelat utan produkt räknas inte');
eg(!$mine(['assignee' => 'Elin', 'company' => 'jarfalla', 'labels' => ['product' => 'batteri']]), 'någon annans ärende räknas inte');
$saved = [
    'assignee' => '',
    'company' => 'jarfalla',
    'labels' => ['type' => 'Support'],
    'subject' => 'Fråga om solceller',
    'body' => 'Vi vill ha solceller på taket.',
    'first' => [
        'subject' => 'Fråga om solceller',
        'body' => 'Vi vill ha solceller på taket.',
        'labels' => ['type' => 'Support', 'case_class' => ['primary_product' => 'Batteri']],
    ],
    'last' => [
        'subject' => 'Re: solceller',
        'body' => 'Vi återkommer om solcellerna.',
        'labels' => ['type' => 'Support'],
    ],
];
eg(jeab_inkorg_stored_offer($saved) === 'batteri', 'sparad klassning ger batteri, inte texten solceller');
eg(jeab_inkorg_stored_offer($saved + ['tags' => ['sol']]) === 'batteri', 'klassningen vinner över en annan tagg');
eg($mine($saved), 'otilldelat med sparad klass Batteri räknas');
$prose = [
    'assignee' => '',
    'company' => 'jarfalla',
    'labels' => ['type' => 'Support'],
    'subject' => 'Behöver ett batteri',
    'body' => 'Kan ni sätta ett batteri i garaget?',
];
eg(jeab_inkorg_stored_offer($prose) === '', 'mejltext utan sparad klass ger ingen produkt');
eg(!$mine($prose), 'otilldelat som bara nämner batteri i texten räknas inte');

eg_as('demo@example.test');
eg(jeab_inkorg_me_id() === '', 'Sam utan huvud är ingen person');
eg_login_sam_l();
eg(jeab_inkorg_me_id() === 'SamL', 'inloggad som Sam L');
eg($mine(['assignee' => 'Sam L', 'company' => 'jarfalla', 'labels' => []]), 'visningsnamnet Sam L är samma person');
eg($mine(['assignee' => '', 'company' => 'jarfalla', 'labels' => ['offer' => 'sol']]), 'Sam L får otilldelad sol');
eg(!$mine(['assignee' => '', 'company' => 'jarfalla', 'labels' => ['product' => 'batteri']]), 'Sam L får inte otilldelat batteri');
eg(!$mine(['assignee' => '', 'company' => 'jarfalla', 'labels' => ['type' => 'Support'], 'tags' => []]), 'Sam L får inte otilldelat utan produkt');
eg(!$mine(['assignee' => '', 'company' => 'malmo', 'labels' => ['offer' => 'sol'], 'tags' => ['sol']]), 'Sam L får inte Malmös otilldelade sol');

eg_as('saljare.syd@malmo.example.test');
eg($mine(['assignee' => '', 'company' => 'malmo', 'tags' => ['elbilsladdare'], 'labels' => ['type' => 'Ny lead']]), 'Kim får otilldelad elbilsladdare');
eg($mine(['assignee' => '', 'company' => 'malmo', 'tags' => ['batteri']]), 'Kim får otilldelat batteri');
eg(!$mine(['assignee' => '', 'company' => 'malmo', 'tags' => ['sol']]), 'Kim får inte otilldelad sol');
eg(!$mine(['assignee' => '', 'company' => 'malmo', 'labels' => ['type' => 'Support']]), 'Kim får inte otilldelat utan produkt');

eg_as('saljare3@jarfalla.example.test');
$pdo = jeab_pdo_connect_sqlite();
eg(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan går att sätta');
$store = jeab_inkorg_pdo_store();
eg($store instanceof JeabPdoInkorgStore, 'SQL-lagret är öppet');

$seed = [
    ['id' => 'till', 'assignee' => 'Roberto', 'name' => 'Till mig', 'product' => 'sol'],
    ['id' => 'mitt', 'assignee' => '', 'name' => 'Mitt batteri', 'product' => 'batteri'],
    ['id' => 'annan', 'assignee' => '', 'name' => 'Annan sol', 'product' => 'sol'],
    ['id' => 'tom', 'assignee' => '', 'name' => 'Utan produkt', 'product' => ''],
    ['id' => 'klass', 'assignee' => '', 'name' => 'Anna Klass', 'product' => '', 'case' => 'Batteri', 'subject' => 'Fråga om solceller', 'body' => 'Vi vill ha solceller på taket.'],
    ['id' => 'text', 'assignee' => '', 'name' => 'Bo Text', 'product' => '', 'subject' => 'Behöver ett batteri', 'body' => 'Kan ni sätta ett batteri i garaget?'],
];
foreach ($seed as $i => $row) {
    $labels = ['type' => 'Support', 'intent' => 'support', 'confidence' => 90];
    if ($row['product'] !== '') {
        $labels['product'] = $row['product'];
    }
    $case = (string) ($row['case'] ?? '');
    if ($case !== '') {
        $labels['case_class'] = ['primary_product' => $case, 'type' => 'Support'];
    }
    $subject = (string) ($row['subject'] ?? $row['name']);
    $body = (string) ($row['body'] ?? $row['name']);
    $stamp = sprintf('2024-06-%02dT10:00:00+00:00', $i + 1);
    $store->upsertMessage($row['id'] . 'm', [
        'id' => $row['id'] . 'm',
        'thread_id' => $row['id'],
        'from' => $row['id'] . '@exempel.test',
        'from_name' => $row['name'],
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => $subject,
        'body' => $body,
        'received_at' => $stamp,
        'source' => 'graph-jarfalla',
        'labels' => $labels,
    ]);
    if ($case !== '') {
        $store->upsertMessage($row['id'] . 'r', [
            'id' => $row['id'] . 'r',
            'thread_id' => $row['id'],
            'from' => 'kontakt@jarfalla.example.test',
            'from_name' => $row['name'],
            'to' => $row['id'] . '@exempel.test',
            'subject' => 'Re: ' . $subject,
            'body' => 'Vi återkommer om solcellerna.',
            'received_at' => sprintf('2024-06-%02dT11:00:00+00:00', $i + 1),
            'source' => 'graph-jarfalla',
            'labels' => ['type' => 'Support', 'intent' => 'support'],
        ]);
    }
    $store->upsertThread($row['id'], [
        'status' => 'open',
        'tags' => [],
        'assignee' => $row['assignee'],
        'read' => [],
        'company' => 'jarfalla',
        'updated_at' => $stamp,
    ]);
}
$store->clearListStamp();
$GLOBALS['_JEAB_INKORG_PART'] = 'counts';
jeab_inkorg_sql_list_sync($store, 'seed-egna');
unset($GLOBALS['_JEAB_INKORG_PART']);

function eg_row(string $html, string $subject): string
{
    if (preg_match_all('/<li class="item ix-it.*?<\/li>/s', $html, $found) !== 1 && ($found[0] ?? []) === []) {
        return '';
    }
    foreach ($found[0] as $li) {
        if (str_contains($li, $subject)) {
            return $li;
        }
    }

    return '';
}

function eg_open(string $scope, string $box = 'jarfalla'): array
{
    $_GET = ['p' => 'inkorg', 'box' => $box, 'co' => $box, 'view' => 'v-inkorg'];
    if ($scope !== '') {
        $_GET['scope'] = $scope;
    }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    unset($GLOBALS['_JEAB_INKORG_UNREAD'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_THREADS'], $GLOBALS['_JEAB_INKORG_BOX_PIN']);
    $prepared = jeab_inkorg_fast_prepare(true);
    if ((int) ($prepared['counts_fresh'] ?? 0) !== 1 || ($prepared['counts'] ?? []) === []) {
        $_GET['ix_part'] = 'counts';
        jeab_inkorg_fast_counts_refresh();
        unset($_GET['ix_part'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_INKORG_BOX_PIN']);
        $prepared = jeab_inkorg_fast_prepare(true);
    }
    $page = (string) include dirname(__DIR__) . '/src/pages/inkorg.php';

    return ['prepared' => $prepared, 'page' => $page];
}

$own = eg_open('egna');
$html = (string) ($own['prepared']['html'] ?? '');
$rows = substr_count($html, 'class="item ix-it');
$tile = (int) (($own['prepared']['counts'] ?? [])['v-inkorg'] ?? -1);
eg($rows === 3 && $tile === 3 && (int) ($own['prepared']['total'] ?? -1) === 3, 'Egna lista och vykort är 3');
eg(preg_match('/data-ix-count="v-inkorg">3</', $own['page']) === 1, 'Egna-vykortet i sidans HTML är 3');
eg(str_contains($html, 'Till mig') && str_contains($html, 'Mitt batteri') && str_contains($html, 'Anna Klass'), 'Egna visar tilldelat mig och otilldelat med min produkt');
eg(!str_contains($html, 'Annan sol') && !str_contains($html, 'Utan produkt') && !str_contains($html, 'Bo Text'), 'Egna döljer annans produkt, saknad produkt och text utan klass');
$till = eg_row($html, 'Till mig');
$mitt = eg_row($html, 'Mitt batteri');
eg(str_contains($till, 'data-ix-assignee>Roberto<') && str_contains($till, 'data-ix-product>Sol<'), 'raden visar Roberto och Sol');
eg(str_contains($mitt, 'data-ix-assignee>Otilldelad<') && str_contains($mitt, 'data-ix-product>Batteri<'), 'raden visar Otilldelad och Batteri');
$klass = eg_row($html, 'Anna Klass');
eg(str_contains($klass, 'data-ix-assignee>Otilldelad<') && str_contains($klass, 'data-ix-product>Batteri<') && !str_contains($klass, 'data-ix-product>Sol<'), 'otilldelad rad tar Batteri från klassningen, inte solceller i texten');
eg($rows !== 6 && $tile !== 6, 'Egna är en annan lista än hela boxen');

$all = eg_open('allas');
$allHtml = (string) ($all['prepared']['html'] ?? '');
$allRows = substr_count($allHtml, 'class="item ix-it');
$allTile = (int) (($all['prepared']['counts'] ?? [])['v-inkorg'] ?? -1);
eg($allRows === 6 && $allTile === 6, 'Allas lista och vykort är hela boxen');
eg(preg_match('/data-ix-count="v-inkorg">6</', $all['page']) === 1, 'Allas-vykortet i sidans HTML är 6');
$annan = eg_row($allHtml, 'Annan sol');
$tom = eg_row($allHtml, 'Utan produkt');
eg(str_contains($annan, 'data-ix-assignee>Otilldelad<') && str_contains($annan, 'data-ix-product>Sol<'), 'Allas visar Otilldelad och Sol på annans produkt');
eg(str_contains($tom, 'data-ix-assignee>Otilldelad<') && !str_contains($tom, 'data-ix-product'), 'Allas visar Otilldelad utan produkttagg');
$text = eg_row($allHtml, 'Bo Text');
eg(str_contains($text, 'data-ix-assignee>Otilldelad<') && !str_contains($text, 'data-ix-product'), 'otilldelad rad utan sparad klass får ingen produkttagg');
$klassAll = eg_row($allHtml, 'Anna Klass');
eg(str_contains($klassAll, 'data-ix-product>Batteri<') && !str_contains($klassAll, 'data-ix-product>Sol<'), 'Allas visar samma klassade produkt');
$bare = eg_open('');
eg((string) ($bare['prepared']['scope'] ?? '') === 'allas' && (int) ($bare['prepared']['total'] ?? -1) === 6, 'utan scope är listan Allas');
eg(str_contains($bare['page'], 'class="chip is-on" data-ix-scope="allas"') && !str_contains($bare['page'], 'class="chip is-on" data-ix-scope="egna"'), 'utan scope är Allas markerad');
eg(str_contains($bare['page'], 'scope=egna'), 'Egna-länken bär scope=egna');
$hook = jeab_inkorg_hook(['action' => 'inkorg.counts', 'box' => 'jarfalla']);
eg((int) (($hook['tabs']['v-inkorg']['total'] ?? -1)) === 6, 'kroken räknar hela boxen');
eg($tile !== (int) (($hook['tabs']['v-inkorg']['total'] ?? -1)), 'Egna-vykortet är inte hela boxen');

eg((jeab_person_by_token('saml')['id'] ?? '') === 'SamL', 'saml är Sam L');
eg((jeab_person_by_token('Sam L')['id'] ?? '') === 'SamL', 'visningsnamnet Sam L är Sam L');
eg((jeab_person_by_token('SamL')['id'] ?? '') === 'SamL', 'SamL är Sam L');
eg((jeab_person_by_token('sam')['id'] ?? '') === 'SamL', 'användarnamnet sam är Sam L');
eg((jeab_person_by_token('samw')['id'] ?? '') === 'SamW', 'samw är Sam W');
eg((jeab_person_by_token('Sam W')['id'] ?? '') === 'SamW', 'Sam W är Sam W');
eg((jeab_person_by_token('Sam')['id'] ?? '') === 'SamL', 'Sam är användarnamnet sam, inte Sam W');
eg(jeab_person_by_code_prefix('sam-hemlig') === null, 'förnamnet Sam utan e-post är inte en person');
eg(jeab_person_from_login('sam-hemlig', 'demo@example.test') === null, 'Sam utan L/W blir ingen med Järfälla-adress');
eg(jeab_person_from_login('sam-hemlig', 'demo@malmo.example.test') === null, 'Sam utan L/W blir ingen med Malmö-adress');
jeab_code_people_save('aabbccddeeff0011', 'saml', 'code');
eg((jeab_person_by_code_fp('aabbccddeeff0011')['id'] ?? '') === 'SamL', 'sparad kod saml blir Sam L');

$liveNames = [
    ['id' => 'ga', 'name' => 'GetAccept', 'product' => '', 'company' => 'jarfalla'],
    ['id' => 'pr', 'name' => 'Patrik Rundin', 'product' => '', 'company' => 'jarfalla'],
    ['id' => 'ts', 'name' => 'tommysunrise02', 'product' => '', 'company' => 'jarfalla'],
    ['id' => 'lg', 'name' => 'Lg Wickstrom', 'product' => '', 'company' => 'jarfalla'],
    ['id' => 'bat', 'name' => 'Batteri Kund', 'product' => 'batteri', 'company' => 'jarfalla'],
    ['id' => 'lad', 'name' => 'Laddare Kund', 'product' => 'laddbox', 'company' => 'jarfalla'],
    ['id' => 'sol', 'name' => 'Sol Kund', 'product' => 'sol', 'company' => 'jarfalla'],
    ['id' => 'roos', 'name' => 'Familjen Roos', 'product' => 'laddbox', 'company' => 'malmo'],
    ['id' => 'wendel', 'name' => 'Sam Wendel', 'product' => 'laddbox', 'company' => 'malmo'],
    ['id' => 'msol', 'name' => 'Malmö Sol', 'product' => 'sol', 'company' => 'malmo'],
];
foreach ($liveNames as $i => $row) {
    $labels = ['type' => 'Support', 'intent' => 'support', 'confidence' => 90];
    if ($row['product'] !== '') {
        $labels['product'] = $row['product'];
    }
    $stamp = sprintf('2024-08-%02dT10:00:00+00:00', $i + 1);
    $to = $row['company'] === 'malmo' ? 'kontakt@malmo.example.test' : 'kontakt@jarfalla.example.test';
    $store->upsertMessage($row['id'] . 'm', [
        'id' => $row['id'] . 'm',
        'thread_id' => $row['id'],
        'from' => $row['id'] . '@exempel.test',
        'from_name' => $row['name'],
        'to' => $to,
        'subject' => $row['name'],
        'body' => $row['name'],
        'received_at' => $stamp,
        'source' => $row['company'] === 'malmo' ? 'helpscout-malmo' : 'graph-jarfalla',
        'labels' => $labels,
    ]);
    $store->upsertThread($row['id'], [
        'status' => 'open',
        'tags' => [],
        'assignee' => '',
        'read' => [],
        'company' => $row['company'],
        'updated_at' => $stamp,
    ]);
}
$store->clearListStamp();
$GLOBALS['_JEAB_INKORG_PART'] = 'counts';
jeab_inkorg_sql_list_sync($store, 'seed-live');
unset($GLOBALS['_JEAB_INKORG_PART'], $GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP']);

unset($_SESSION['jeab_email']);
$_SESSION['jeab_code_person'] = 'saml';
eg(jeab_who_me_person() === null, 'kodpersonen ändrar inte jeab_who_me_person');
eg(jeab_egna_me_id() === 'SamL', 'sessionen saml är Sam L för Egna');
eg(!jeab_inkorg_admin_allowed(jeab_egna_person()), 'personobjekt utan personlig inloggning är inte admin');
eg(!jeab_inkorg_is_admin(), 'sessionens kodperson ger inte admin-genväg utan kopplad inloggning');
eg(jeab_inkorg_person_offers(jeab_egna_me_id(), 'jarfalla') === ['sol'], 'saml äger bara Järfällas sol');
eg(jeab_inkorg_person_offers(jeab_egna_me_id(), 'malmo') === [], 'saml äger inget i Malmö');
eg(!$mine(['assignee' => '', 'company' => 'jarfalla', 'labels' => ['type' => 'Support']]), 'admin släpper inte in otilldelat utan produkt');
eg(!$mine(['assignee' => '', 'company' => 'malmo', 'labels' => ['product' => 'laddbox']]), 'admin släpper inte in Kims laddare');
eg(!$mine(['assignee' => 'Kim', 'company' => 'malmo', 'labels' => ['product' => 'laddbox']]), 'Kims tilldelade laddare är inte Sam L');

$jl = eg_open('egna');
$jlHtml = (string) ($jl['prepared']['html'] ?? '');
eg((int) ($jl['prepared']['total'] ?? -1) !== 6, 'Egna för saml är inte den gamla Allas-listan');
foreach (['GetAccept', 'Patrik Rundin', 'tommysunrise02', 'Lg Wickstrom', 'Batteri Kund', 'Laddare Kund'] as $hidden) {
    eg(!str_contains($jlHtml, $hidden), 'Järfälla Egna döljer ' . $hidden);
}
eg(str_contains($jlHtml, 'Sol Kund'), 'Järfälla Egna visar otilldelad sol');
$solRow = eg_row($jlHtml, 'Sol Kund');
eg(str_contains($solRow, 'data-ix-product>Sol<'), 'otilldelad sol har produkttagg');
$jlAll = eg_open('allas');
eg((int) ($jlAll['prepared']['total'] ?? -1) > (int) ($jl['prepared']['total'] ?? -1), 'Allas är större än Egna för saml');
$allasKey = jeab_inkorg_fast_count_key(jeab_inkorg_fast_scope_menu('allas'), 'jarfalla', 'SamL');
$egnaKey = jeab_inkorg_fast_count_key(jeab_inkorg_fast_scope_menu('egna'), 'jarfalla', 'SamL');
eg($allasKey !== $egnaKey, 'räknarcachen skiljer Egna från Allas');

$mo = eg_open('egna', 'malmo');
$moHtml = (string) ($mo['prepared']['html'] ?? '');
eg(!str_contains($moHtml, 'Familjen Roos') && !str_contains($moHtml, 'Sam Wendel'), 'Malmö Egna visar inte Kims elbilsladdare');
eg(!str_contains($moHtml, 'Malmö Sol'), 'Malmö Egna visar inte Sam W:s sol');
eg((int) ($mo['prepared']['total'] ?? -1) === 0, 'Sam L har inga egna rader i Malmö');

$_SESSION['jeab_code_person'] = 'samw';
eg(jeab_who_me_person() === null && jeab_egna_me_id() === 'SamW', 'sessionen samw är Sam W för Egna');
eg(jeab_inkorg_person_offers('SamW', 'malmo') === ['sol'], 'Sam W äger Malmös sol');
$jw = eg_open('egna', 'malmo');
$jwHtml = (string) ($jw['prepared']['html'] ?? '');
eg(str_contains($jwHtml, 'Malmö Sol'), 'Sam W ser otilldelad Malmö-sol');
eg(!str_contains($jwHtml, 'Familjen Roos') && !str_contains($jwHtml, 'Sam Wendel'), 'Sam W ser inte Kims elbilsladdare');

eg_login_sam_l();
$_SESSION['jeab_code_person'] = 'saml';
eg(jeab_inkorg_is_admin() && jeab_inkorg_me_id() === 'SamL', 'kopplad Sam L är admin och fortfarande Sam L');
$adminMo = eg_open('egna', 'malmo');
$adminHtml = (string) ($adminMo['prepared']['html'] ?? '');
eg(!str_contains($adminHtml, 'Familjen Roos') && !str_contains($adminHtml, 'Sam Wendel') && (int) ($adminMo['prepared']['total'] ?? -1) === 0, 'admin-läget visar inte Malmös elbilsladdare');

unset($_SESSION['jeab_email'], $_SESSION['jeab_code_person'], $_COOKIE[JEAB_AUTH_COOKIE]);
$lost = eg_open('egna');
$lostAll = eg_open('allas');
// Okänd person: Egna visar Alla med en rad om det (jeab_inkorg_egna_fallback).
eg((int) ($lost['prepared']['total'] ?? -1) === (int) ($lostAll['prepared']['total'] ?? -2) && (int) ($lostAll['prepared']['total'] ?? 0) > 0, 'Egna utan person visar Alla');
eg((array) ($lost['prepared']['counts'] ?? []) == (array) ($lostAll['prepared']['counts'] ?? []), 'Egna utan person har Allas tal');

echo "inkorg egna product: ok\n";
