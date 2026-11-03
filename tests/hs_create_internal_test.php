<?php

declare(strict_types=1);

/**
 * hs.create_internal. dry=1 postar inget. dry=0 skapar ett Help Scout-ärende
 * med imported=true, status active och kroppen som intern anteckning.
 * Ingen sändfunktion anropas. Påhittad kund. Svaret maskerar e-post.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-hs-internal-' . bin2hex(random_bytes(4));
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function hs(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$GLOBALS['_HS_SENT'] = 0;
$GLOBALS['JEAB_GRAPH_SEND'] = static function (): array {
    $GLOBALS['_HS_SENT']++;

    return ['ok' => true];
};
$GLOBALS['_HS_POSTS'] = [];
$GLOBALS['_HS_GETS'] = 0;
$GLOBALS['_HS_USERS'] = [
    ['id' => 4242, 'firstName' => 'Sam', 'lastName' => 'Wikström', 'email' => 'demo@malmo.example.test'],
    ['id' => 7, 'firstName' => 'Elin', 'lastName' => 'Berg', 'email' => 'saljare4@jarfalla.example.test'],
];
$GLOBALS['_JEAB_HS_IMPORT_HTTP'] = static function (string $method, string $url): array {
    unset($url);
    $GLOBALS['_HS_GETS']++;
    hs($method === 'GET', 'användarlistan läses med GET');
    $body = json_encode(['_embedded' => ['users' => $GLOBALS['_HS_USERS']]], JSON_UNESCAPED_UNICODE);

    return ['code' => 200, 'headers' => [], 'body' => is_string($body) ? $body : ''];
};
$GLOBALS['_JEAB_MALMO_HS_HTTP'] = static function (string $method, string $path, array $body): array {
    $GLOBALS['_HS_POSTS'][] = ['method' => $method, 'path' => $path, 'body' => $body];
    if (!empty($GLOBALS['_HS_FAIL_POST'])) {
        return ['code' => 500, 'error' => 'hs_svar', 'headers' => [], 'body' => ''];
    }

    return [
        'code' => 201,
        'id' => 9991,
        'headers' => ['Location: https://api.helpscout.net/v2/conversations/9991'],
        'body' => '',
    ];
};

file_put_contents(jeab_inkorg_token_path(), json_encode(['key' => 'tok-hs-internal']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = '';

$email = 'kund.hs.intern@gmail.com';
$pnr = '197505152345';
$base = [
    'action' => 'hs.create_internal',
    'user' => 'Sam W',
    'customer' => ['email' => $email, 'name' => 'Kim Kund'],
    'body' => 'Intern notis ' . $pnr,
    'office' => 'jarfalla',
    'subject' => 'Uppföljning',
    'imported' => false,
];

$denied = jeab_hs_create_internal_dispatch(array_replace($base, ['dry' => 0]));
hs(($denied['status'] ?? 0) === 401 && empty($denied['ok']) && $GLOBALS['_HS_POSTS'] === [], 'utan token postas inget');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-hs-internal';

$dry = jeab_hs_create_internal_dispatch($base);
hs(!empty($dry['ok']) && (int) ($dry['dry'] ?? 0) === 1 && (int) ($dry['sent'] ?? 1) === 0 && (int) ($dry['written'] ?? 1) === 0 && $GLOBALS['_HS_POSTS'] === [], 'utelämnad dry postar inget och sent är 0');
hs(($dry['imported'] ?? false) === true && ($dry['conversation_status'] ?? '') === 'active' && ($dry['thread'] ?? '') === 'note' && (int) ($dry['assignee_id'] ?? 0) === 4242, 'dry visar imported, active och Sam');
$dryBlob = json_encode($dry, JSON_UNESCAPED_UNICODE);
hs(is_string($dryBlob) && !str_contains($dryBlob, $email) && !str_contains($dryBlob, $pnr) && str_contains($dryBlob, 'k***@gmail.com'), 'dry-svaret maskerar e-post och personnummer');

$missingIdem = jeab_hs_create_internal_dispatch(array_replace($base, ['dry' => 0]));
hs(empty($missingIdem['ok']) && ($missingIdem['error'] ?? '') === 'ange idem' && ($missingIdem['status'] ?? 0) === 422 && $GLOBALS['_HS_POSTS'] === [], 'dry=0 utan idem postar inget');

$wet = jeab_hs_create_internal_dispatch(array_replace($base, ['dry' => 0, 'idem' => 'usman']));
hs(!empty($wet['ok']) && (int) ($wet['written'] ?? 0) === 1 && (int) ($wet['id'] ?? 0) === 9991 && (int) ($wet['sent'] ?? 1) === 0 && empty($wet['replayed']), 'dry=0 skapar ärendet usman och sent är 0');
hs(count($GLOBALS['_HS_POSTS']) === 1, 'exakt en POST');
$post = $GLOBALS['_HS_POSTS'][0];
$body = is_array($post['body'] ?? null) ? $post['body'] : [];
hs(($post['method'] ?? '') === 'POST' && ($post['path'] ?? '') === 'conversations', 'POST går till conversations');
hs(($body['imported'] ?? false) === true && ($body['status'] ?? '') === 'active', 'imported=true och status active');
$thread = is_array($body['threads'][0] ?? null) ? $body['threads'][0] : [];
hs(($thread['type'] ?? '') === 'note' && (int) ($body['assignTo'] ?? 0) === 4242 && (int) ($thread['user'] ?? 0) === 4242, 'kroppen är en intern anteckning till användaren');
hs((string) ($body['customer']['email'] ?? '') === $email, 'kunden ligger som customer');
$wetBlob = json_encode($wet, JSON_UNESCAPED_UNICODE);
hs(is_string($wetBlob) && !str_contains($wetBlob, $email) && !str_contains($wetBlob, $pnr), 'svaret maskerar e-post och personnummer');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/hs_internal.php');
hs(!str_contains($src, 'jeab_email_send') && !str_contains($src, 'jeab_sms_send') && !str_contains($src, 'jeab_auto_quote') && !str_contains($src, 'jeab_notiser_send') && !str_contains($src, 'jeab_outbound_graph_send'), 'ingen sändfunktion anropas');
hs(str_contains($src, 'jeab_malmo_hs_http_write') && !str_contains($src, 'api.helpscout.net/v2/conversations'), 'skaparen går via Malmö-klienten');
$postsBefore = count($GLOBALS['_HS_POSTS']);
$missing = jeab_malmo_hs_http_write('POST', 'conversations', [
    'status' => 'active',
    'customer' => ['email' => $email],
    'threads' => [['type' => 'note', 'text' => 'utan imported', 'user' => 4242]],
]);
hs(($missing['error'] ?? '') === 'hs_skriv_nekad' && count($GLOBALS['_HS_POSTS']) === $postsBefore, 'utan imported postas inget');
$reply = jeab_malmo_hs_http_write('POST', 'conversations', [
    'imported' => true,
    'status' => 'active',
    'customer' => ['email' => $email],
    'threads' => [['type' => 'reply', 'text' => 'Hej', 'customer' => ['email' => $email]]],
]);
hs(($reply['error'] ?? '') === 'hs_skriv_nekad' && count($GLOBALS['_HS_POSTS']) === $postsBefore, 'imported med kundsvar postas inte');
$guard = jeab_send_guard_scan_transports(dirname(__DIR__));
$flagged = array_values(array_filter($guard, static fn(string $hit): bool => str_contains($hit, 'hs_internal.php')));
hs($flagged === [], 'spärren flaggar inte den interna skaparen');
$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
hs(str_contains($hook, "'hs.create_internal'") && str_contains($hook, 'jeab_hs_create_internal_dispatch'), 'hook.php tar hs.create_internal');
hs((int) $GLOBALS['_HS_SENT'] === 0, 'grafutskick anropades inte');

$GLOBALS['_HS_USERS'][] = ['id' => 4243, 'firstName' => 'Sam', 'lastName' => 'Wallin', 'email' => 'sam.wallin@example.com'];
$dup = jeab_hs_create_internal_dispatch(array_replace($base, ['dry' => 1]));
hs(empty($dup['ok']) && ($dup['error'] ?? '') === 'användaren är inte entydig i Help Scout', 'tvetydig användare avvisas');

$postsAfterCreate = count($GLOBALS['_HS_POSTS']);
$getsAfterCreate = (int) $GLOBALS['_HS_GETS'];
$again = jeab_hs_create_internal_dispatch(array_replace($base, ['dry' => 0, 'idem' => 'usman', 'body' => 'Annan text']));
hs(!empty($again['ok']) && (int) ($again['replayed'] ?? 0) === 1 && (int) ($again['written'] ?? 1) === 0 && (int) ($again['id'] ?? 0) === 9991 && (int) ($again['sent'] ?? 1) === 0, 'samma idem usman lämnar tillbaka sparat id');
hs(count($GLOBALS['_HS_POSTS']) === $postsAfterCreate && (int) $GLOBALS['_HS_GETS'] === $getsAfterCreate, 'samma idem skapar inget och läser ingen användare');
$store = jeab_store();
hs($store instanceof JeabPdoStore, 'idem ligger i SQL');
$idemRow = $store->pdo()->query("SELECT conversation_id, claim FROM hs_internal_idem WHERE idem_key = 'usman'")->fetch(PDO::FETCH_ASSOC);
hs(is_array($idemRow) && (int) ($idemRow['conversation_id'] ?? 0) === 9991, 'tabellen har ärende-id, inte JSON');
$GLOBALS['_HS_FAIL_POST'] = true;
$failed = jeab_hs_create_internal_dispatch(array_replace($base, ['dry' => 0, 'idem' => 'usman-fel', 'user' => 'Elin Berg']));
$failedPosts = count($GLOBALS['_HS_POSTS']);
hs(empty($failed['ok']) && ($failed['status'] ?? 0) === 502 && $failedPosts === $postsAfterCreate + 1, 'misslyckad skapning svarar 502');
$GLOBALS['_HS_FAIL_POST'] = false;
$retried = jeab_hs_create_internal_dispatch(array_replace($base, ['dry' => 0, 'idem' => 'usman-fel', 'user' => 'Elin Berg']));
hs(!empty($retried['ok']) && (int) ($retried['written'] ?? 0) === 1 && (int) ($retried['id'] ?? 0) === 9991 && empty($retried['replayed']) && count($GLOBALS['_HS_POSTS']) === $failedPosts + 1, 'samma idem kan skapas efter ett fel');
$postsAfterCreate = count($GLOBALS['_HS_POSTS']);
hs(!is_file($tmp . '/hs_internal_idem.json') && !is_file($tmp . '/secrets/hs_internal_idem.json'), 'idem skrivs inte till JSON');
hs(str_contains($src, 'ENGINE=InnoDB') && str_contains($src, 'conversation_id BIGINT UNSIGNED') && !str_contains($src, 'hs_internal_idem.json'), 'lagringen är en MySQL-tabell');
hs(!str_contains($src, 'jeab_pdo_release_if_idle'), 'sökningen släpper inte databasen');

$createHook = '';
if (preg_match("/hs\\.create_internal' && function_exists\\('jeab_hs_create_internal_dispatch'\\)\\) \\{(.*?)\\n\\}/s", $hook, $m) === 1) {
    $createHook = $m[1];
}
hs(str_contains($createHook, 'catch (Throwable $e)') && str_contains($createHook, 'jeab_log_uncaught') && str_contains($createHook, 'http_response_code(500)') && str_contains($createHook, "'error'"), 'hook fångar fel, skriver fellogg och svarar 500');

$GLOBALS['_HS_SEARCH'] = [];
$GLOBALS['_JEAB_MALMO_HS_HTTP_GET'] = static function (string $path, array $query): array {
    $GLOBALS['_HS_SEARCH'][] = ['path' => $path, 'query' => $query];
    $status = (string) ($query['status'] ?? '');
    $rows = $status === 'spam'
        ? [[
            'id' => 55,
            'subject' => 'Offert',
            'status' => 'spam',
            'createdAt' => '2024-01-01T00:00:00Z',
            'primaryCustomer' => ['email' => 'kund.hs.intern@gmail.com'],
        ], [
            'id' => 77,
            'subject' => 'usman',
            'status' => 'spam',
            'createdAt' => '2024-02-02T00:00:00Z',
            'primaryCustomer' => ['email' => 'kund.hs.intern@gmail.com'],
        ]]
        : [[
            'id' => 55,
            'subject' => 'usman',
            'status' => 'closed',
            'createdAt' => '2024-01-01T00:00:00Z',
            'primaryCustomer' => ['email' => 'kund.hs.intern@gmail.com'],
        ]];

    return ['code' => 200, 'body' => json_encode(['_embedded' => ['conversations' => $rows]], JSON_UNESCAPED_UNICODE)];
};

$_SERVER['HTTP_AUTHORIZATION'] = '';
$searchDenied = jeab_hs_search_dispatch(['action' => 'hs.search', 'mailbox' => 372708, 'query' => 'usman']);
hs(($searchDenied['status'] ?? 0) === 401 && $GLOBALS['_HS_SEARCH'] === [] && count($GLOBALS['_HS_POSTS']) === $postsAfterCreate, 'sök utan token läser inget');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-hs-internal';

$search = jeab_hs_search_dispatch([
    'action' => 'hs.search',
    'mailbox' => 'jarfalla',
    'query' => 'usman',
]);
hs(!empty($search['ok']) && (int) ($search['sent'] ?? 1) === 0 && ($search['read_only'] ?? false) === true && (int) ($search['mailbox_id'] ?? 0) === 372708, 'sök är read-only mot Järfällas mailbox');
hs(count($GLOBALS['_HS_SEARCH']) === 2 && count($GLOBALS['_HS_POSTS']) === $postsAfterCreate, 'sök gör två GET och ingen POST');
$statuses = array_map(static fn(array $call): string => (string) ($call['query']['status'] ?? ''), $GLOBALS['_HS_SEARCH']);
hs($statuses === ['all', 'spam'] && ($GLOBALS['_HS_SEARCH'][0]['path'] ?? '') === 'conversations', 'sök tar alla statusar inklusive spam');
hs((string) ($GLOBALS['_HS_SEARCH'][0]['query']['query'] ?? '') === 'usman', 'usman går som query innan något skapas');
$convs = is_array($search['conversations'] ?? null) ? $search['conversations'] : [];
hs(count($convs) === 2 && (int) ($convs[1]['id'] ?? 0) === 77 && ($convs[1]['status'] ?? '') === 'spam' && ($convs[1]['subject'] ?? '') === 'usman', 'spam finns med och id dubbleras inte');
hs(($convs[0]['subject'] ?? '') === 'usman' && ($convs[0]['status'] ?? '') === 'closed' && ($convs[0]['customer_email'] ?? '') === 'kund.hs.intern@gmail.com' && ($convs[0]['createdAt'] ?? '') === '2024-01-01T00:00:00Z', 'svaret har ämne, status, e-post och createdAt');
$searchBlob = json_encode($search, JSON_UNESCAPED_UNICODE);
hs(is_string($searchBlob) && !str_contains($searchBlob, 'threads'), 'sök svaret tar inte med trådar');

$phonePosts = count($GLOBALS['_HS_POSTS']);
$phone = jeab_hs_search_dispatch(['mailbox' => 372708, 'query' => '0701234567']);
hs(!empty($phone['ok']) && count($GLOBALS['_HS_POSTS']) === $phonePosts, 'telefonnummer söks utan att posta');
$lastPhone = $GLOBALS['_HS_SEARCH'][count($GLOBALS['_HS_SEARCH']) - 2]['query']['query'] ?? '';
hs($lastPhone === '0701234567', 'telefonnumret går oförändrat som query');

$text = jeab_hs_search_dispatch(['mailbox' => 372708, 'query' => 'laddbox offert']);
$lastText = $GLOBALS['_HS_SEARCH'][count($GLOBALS['_HS_SEARCH']) - 2]['query']['query'] ?? '';
hs(!empty($text['ok']) && $lastText === 'laddbox offert', 'fritext går oförändrad som query');

$emptyQuery = jeab_hs_search_dispatch(['mailbox' => 372708, 'query' => '  ']);
hs(empty($emptyQuery['ok']) && ($emptyQuery['status'] ?? 0) === 422 && count($GLOBALS['_HS_POSTS']) === $phonePosts, 'tom sökning postar inget');

$reply = jeab_malmo_hs_http_get('conversations/1/reply', ['text' => 'Hej']);
hs(($reply['error'] ?? '') === 'hs_las_nekad' && count($GLOBALS['_HS_POSTS']) === $phonePosts, 'GET-klienten nekar svarsvägen');
hs(str_contains($src, 'function jeab_malmo_hs_http_get') && str_contains($src, 'CURLOPT_HTTPGET => true'), 'sök-klienten är GET och ligger inte i inkorgsfilen');
$malmoHs = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_malmo_hs.php');
hs(!str_contains($malmoHs, 'function jeab_malmo_hs_http_get'), 'inkorgens Help Scout-klient är orörd');
hs(str_contains($hook, "'hs.search'") && str_contains($hook, 'jeab_hs_search_dispatch'), 'hook.php tar hs.search');
$guardAfter = jeab_send_guard_scan_transports(dirname(__DIR__));
$flaggedGet = array_values(array_filter($guardAfter, static fn(string $hit): bool => str_contains($hit, 'inkorg_malmo_hs.php') || str_contains($hit, 'hs_internal.php')));
hs($flaggedGet === [], 'spärren flaggar inte läsningen');

echo "OK\n";
