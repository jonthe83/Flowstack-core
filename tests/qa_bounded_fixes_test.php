<?php

declare(strict_types=1);

/**
 * Tre QA-fixar: manuellt «Skicka offert» till jeab+test-*@mail.grokbot.com,
 * sök på kundnamn/e-post inklusive Historik, och personvalet i Påminnelser
 * som ska ligga kvar när man söker.
 */

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/jeab-qa3-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
], true) . ";\n");
require $root . '/src/bootstrap.php';
require $root . '/src/layout.php';

function qa(bool $ok, string $message): void
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
$_SESSION['jeab_email'] = 'demo@example.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';

$inbox = 'jeab+test-batteri4@mail.grokbot.com';
$lead = ['platform' => 'wix-forms.com', 'name' => 'Test Batteri4', 'email' => $inbox];

qa(jeab_auto_manual_test_inbox($inbox), 'egen testinkorg känns igen');
qa(jeab_auto_manual_test_inbox('Test Batteri4 <' . $inbox . '>'), 'adress i visningsnamn räknas');
qa(jeab_auto_manual_test_inbox('JEAB+Test-Batteri4@Mail.Grokbot.com'), 'skiftläge spelar ingen roll');
qa(!jeab_auto_manual_test_inbox('user@example.org'), 'example.org är inte testinkorgen');
qa(!jeab_auto_manual_test_inbox('jeab+test-x@example.org'), 'jeab+test- på example.org släpps inte');
qa(!jeab_auto_manual_test_inbox('jeab+test@mail.grokbot.com'), 'jeab+test utan suffix släpps inte');
qa(!jeab_auto_manual_test_inbox('jeab+batteri4@mail.grokbot.com'), 'annan grokbot-adress släpps inte');
qa(!jeab_auto_manual_test_inbox('jeab+test-x@a.mail.grokbot.com'), 'underdomän släpps inte');

qa(jeab_auto_quote_block($lead, $inbox, true) === '', 'manuellt utskick till testinkorgen blockeras inte');
$autoBlocked = jeab_auto_quote_block($lead, $inbox, false);
qa(str_contains($autoBlocked, 'ser ut som ett test'), 'automatiskt utskick till samma lead stoppas, fick ' . $autoBlocked);
qa(jeab_auto_quote_is_automatic(['automatic' => true]) === true, 'automatic-flaggan är automatik');
qa(
    str_contains(jeab_auto_quote_block($lead, $inbox, !jeab_auto_quote_is_automatic(['automatic' => true])), 'ser ut som ett test'),
    'anropet med automatic skickar inte manual-undantaget'
);
qa(jeab_auto_quote_is_automatic([]) === false, 'utan automatic är det ett manuellt utskick');

$example = ['platform' => 'wix-forms.com', 'name' => 'Test Batteri4', 'email' => 'user@example.org'];
$exampleWhy = jeab_auto_quote_block($example, 'user@example.org', true);
qa(str_contains($exampleWhy, 'ser ut som ett test'), 'manuellt till example.org stoppas, fick ' . $exampleWhy);
qa(
    str_contains(jeab_auto_quote_block($lead, 'jeab+test-x@example.org', true), 'ser ut som ett test'),
    'manuellt till jeab+test- på example.org stoppas'
);
qa(
    str_contains(jeab_auto_quote_block($lead, 'no-reply@wixforms.com', true), 'no-reply'),
    'formuläradress stoppas före testinkorgsundantaget'
);
qa(
    str_contains(jeab_auto_quote_block(['platform' => 'wix-forms.com', 'name' => 'Test Batteri4', 'email' => ''], $inbox, true), 'kundadress'),
    'lead utan kundadress stoppas även manuellt'
);

$autoSrc = (string) file_get_contents($root . '/src/inkorg_auto.php');
qa(str_contains($autoSrc, "jeab_send_once('offert'"), 'send_once-spärren är kvar');
qa(
    str_contains($autoSrc, 'jeab_auto_quote_block($cust, $rcpt, !jeab_auto_quote_is_automatic($opts))'),
    'offertanropet skiljer manuellt från automatiskt'
);

$js = (string) file_get_contents($root . '/assets/js/app.js');
qa(str_contains($js, "const keepWho = url.searchParams.has('who');"), 'sökningen behåller personvalet i adressen');
qa(str_contains($js, "if (keepWho && (key === 'who' || key === 'ws'))"), 'formulärets who skriver inte över adressen');

$whoPage = static function (array $query): string {
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $view = 'all';
    $counts = ['today' => 0, 'scheduled' => 0, 'all' => 0, 'waiting' => 0, 'done' => 0];
    $visible = [];
    $search = (string) ($query['q'] ?? '');

    return require dirname(__DIR__) . '/src/pages/reminders.php';
};
$chipOn = static function (string $html): string {
    if (preg_match('#<div class="cal-filter crm-who">(.*?)</div>\s*</div>#s', $html, $m) !== 1) {
        return '?';
    }
    $on = [];
    foreach (preg_split('#</a>#', $m[1]) ?: [] as $chip) {
        if (!str_contains($chip, 'is-on')) {
            continue;
        }
        if (preg_match('#data-scope="(mina|alla)"#', $chip, $n) === 1) {
            $on[] = $n[1];
        } elseif (preg_match('#chip-who-name">([^<]+)#', $chip, $n) === 1) {
            $on[] = trim($n[1]);
        } elseif (str_contains($chip, '>Alla')) {
            $on[] = 'alla';
        }
    }

    return implode(',', $on);
};

unset($_SESSION['jeab_reminder_who']);
$stale = (string) (time() - 4000);
$_GET = ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all', 'tag' => 'all', 'who' => 'Roberto', 'ws' => $stale];
unset($_SESSION['jeab_reminder_who']);
qa(jeab_reminder_who_filter() === 'Sam L', 'gammal who-länk utan sök faller tillbaka till Sam L');
qa(($GLOBALS['_JEAB_WHO_SRC'] ?? '') === 'url-stale-ignored', 'källan är en gammal adress');

unset($_SESSION['jeab_reminder_who']);
$_GET = ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all', 'tag' => 'all', 'who' => 'Roberto', 'q' => 'Batteri', 'ws' => $stale];
$html = $whoPage($_GET);
qa(jeab_reminder_who_filter() === 'Roberto', 'sök med gammal ws behåller Roberto');
qa($chipOn($html) === '' && jeab_reminder_who_filter() === 'Roberto', 'varken Mina eller Alla är på under sök med who=Roberto');
qa(str_contains($html, '<input type="hidden" name="who" value="Roberto">'), 'sökrutan bär Roberto');

unset($_SESSION['jeab_reminder_who']);
$_GET = ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all', 'tag' => 'all', 'who' => 'all', 'q' => 'Test Batteri4', 'ws' => $stale];
$html = $whoPage($_GET);
qa(jeab_reminder_who_filter() === null, 'sök med who=all och gammal ws stannar på Alla');
qa($chipOn($html) === 'alla', 'pillret Alla är på');
qa(str_contains($html, '<input type="hidden" name="who" value="all">'), 'sökrutan bär who=all');

unset($_SESSION['jeab_reminder_who']);
$_GET = ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all', 'tag' => 'all', 'who' => 'Elin', 'q' => 'batteri'];
qa(jeab_reminder_who_filter() === 'Elin', 'sök utan ws behåller Elin');

$pdo = jeab_pdo_connect_sqlite();
qa(jeab_settings_put($pdo, 'sqlite', 'inkorg_storage', 'sql'), 'sql-flaggan för inkorgen');
$store = jeab_inkorg_pdo_store();
qa($store instanceof JeabPdoInkorgStore, 'listlagret är SQL');

$contact = jeab_inkorg_contact_save([
    'name' => 'Kontakt Batteri4',
    'emails' => 'kontakt.batteri4@brevlada.example',
]);
qa((string) ($contact['id'] ?? '') !== '', 'kontakten sparades');

$bare = static function (string $id, string $status, array $extra): array {
    return [
        'id' => $id,
        'company' => 'jarfalla',
        'status' => $status,
        'deleted' => false,
        'assignee' => '',
        'updated_at' => '2024-08-01T10:00:00+00:00',
        'customer_email' => '',
        'customer_name' => '',
        'labels' => ['type' => 'Ny lead', 'intent' => 'kop', 'confidence' => 90],
        'tags' => [],
        'search_hay' => "wix\nny lead",
        'list_snippet' => 'Ny lead',
        'last' => [
            'from' => 'noreply@wixforms.com',
            'from_name' => 'Wix',
            'subject' => 'Ny lead',
            'received_at' => '2024-08-01T10:00:00+00:00',
        ],
        'first' => [
            'from' => 'noreply@wixforms.com',
            'from_name' => 'Wix',
            'subject' => 'Ny lead',
        ],
    ] + $extra;
};

$store->saveListRow($bare('open-batteri', 'open', [
    'lead_customer' => ['name' => 'Test Batteri4', 'email' => $inbox],
]));
$store->saveListRow($bare('closed-batteri', 'closed', [
    'lead_customer' => ['name' => 'Test Batteri4', 'email' => $inbox],
    'updated_at' => '2024-07-01T10:00:00+00:00',
]));
$store->saveListRow($bare('open-anna', 'open', [
    'customer_name' => 'Anna Kund',
    'customer_email' => 'anna@brevlada.example',
    'search_hay' => 'anna kund',
    'updated_at' => '2024-08-02T10:00:00+00:00',
]));
$store->saveListRow($bare('open-case', 'open', [
    'case_class' => ['customer_name' => 'Case Namn', 'customer_email' => 'case.namn@brevlada.example'],
    'updated_at' => '2024-08-03T10:00:00+00:00',
]));
$store->saveListRow($bare('open-kontakt', 'open', [
    'contact_id' => (string) $contact['id'],
    'updated_at' => '2024-08-04T10:00:00+00:00',
]));
$store->setListStamp($store->listFingerprint());

$seek = static function (array $query) use ($store): array {
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($GLOBALS['_JEAB_INKORG_LIGHT'], $GLOBALS['_JEAB_INKORG_LIGHT_FP'], $GLOBALS['_JEAB_HS_IMPORT_INDEX']);
    $store->setListStamp($store->listFingerprint());

    return jeab_inkorg_fast_prepare(true, 'list');
};

$hit = $seek(['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'allas', 'q' => 'Test Batteri4']);
qa((string) $hit['vyId'] === 'v-inkorg', 'sökningen stannar i Inkorgen');
qa(str_contains((string) $hit['html'], 'data-ix-id="open-batteri"'), 'öppen tråd hittas på kundnamnet');
qa(str_contains((string) $hit['html'], 'data-ix-id="closed-batteri"'), 'stängd tråd i Historik hittas från Inkorgen');
qa(!str_contains((string) $hit['html'], 'data-ix-id="open-anna"'), 'annan kund följer inte med');

$mail = $seek(['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'allas', 'q' => $inbox]);
qa(str_contains((string) $mail['html'], 'data-ix-id="closed-batteri"'), 'sök på kundens e-post hittar den stängda tråden');

$hist = $seek(['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-historik', 'scope' => 'allas', 'q' => 'Test Batteri4']);
qa((string) $hist['vyId'] === 'v-historik', 'sökningen stannar i Historik');
qa(str_contains((string) $hist['html'], 'data-ix-id="closed-batteri"'), 'Historik hittar kundnamnet');
qa(!str_contains((string) $hist['html'], 'data-ix-id="open-batteri"'), 'öppen tråd ligger inte i Historik-söket');

$case = $seek(['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'allas', 'q' => 'Case Namn']);
qa(str_contains((string) $case['html'], 'data-ix-id="open-case"'), 'sök träffar namnet i ärendeklassen');
$caseMail = $seek(['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'allas', 'q' => 'case.namn@brevlada.example']);
qa(str_contains((string) $caseMail['html'], 'data-ix-id="open-case"'), 'sök träffar e-posten i ärendeklassen');

$kontakt = $seek(['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'allas', 'q' => 'Kontakt Batteri4']);
qa(str_contains((string) $kontakt['html'], 'data-ix-id="open-kontakt"'), 'sök träffar kontaktens namn');
$kontaktMail = $seek(['p' => 'inkorg', 'box' => 'jarfalla', 'view' => 'v-inkorg', 'scope' => 'allas', 'q' => 'kontakt.batteri4@brevlada.example']);
qa(str_contains((string) $kontaktMail['html'], 'data-ix-id="open-kontakt"'), 'sök träffar kontaktens e-post');

echo "ALLA OK\n";
