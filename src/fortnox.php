<?php

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/idnr.php';
$jeabFortnoxLogger = (defined('JEAB_ROOT') ? JEAB_ROOT : dirname(__DIR__)) . '/rot/logger.php';
if (is_file($jeabFortnoxLogger)) {
    require_once $jeabFortnoxLogger;
}
if (!function_exists('appLog')) {
    /**
     * Fellogg för Fortnox-fel. Rutin-anropet fortnox_request skrivs inte:
     * det är ett spår per anrop, inte ett fel. Texten maskeras (personnummer,
     * kontonummer, mejl, telefon) innan den hamnar i app-errors.jsonl.
     */
    function appLog(string $event, array $context = []): void
    {
        if ($event === 'fortnox_request' || !function_exists('jeab_log_app_error')) {
            return;
        }
        $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $message = $event;
        if (is_string($encoded) && $encoded !== '' && $encoded !== '[]') {
            $message .= ' ' . $encoded;
        }
        $message = jeab_fortnox_mask_log_text($message);
        jeab_log_app_error('fortnox', $message);
    }
}

/** Maskerar personnummer, bankkonto och bokföringskonto innan text når Fellogg. */
function jeab_fortnox_mask_log_text(string $message): string
{
    if (function_exists('jeab_log_mask_text')) {
        $message = jeab_log_mask_text($message);
    }
    $message = preg_replace('/"(AccountNumber|account|konto)"\s*:\s*"?\d{3,12}"?/i', '"$1":"****"', $message) ?? $message;
    $message = preg_replace('/\b(AccountNumber|account|konto)\b\s*[:=]?\s*\d{3,12}\b/i', '$1 ****', $message) ?? $message;
    return preg_replace('/\d{7,}/', '****', $message) ?? $message;
}

/**
 * @param array<string, mixed> $lead
 * @return array{test:bool,reason:string,email:string,phone:string}
 */
function jeab_fortnox_test_lead_probe(array $lead): array
{
    $empty = ['test' => false, 'reason' => '', 'email' => '', 'phone' => ''];
    $match = function_exists('jeab_test_lead_strict') ? jeab_test_lead_strict($lead) : $empty;
    if (!empty($match['test'])) {
        return $match;
    }
    foreach (['fortnox_order', 'fortnox_customer', 'orderNumber', 'customerNumber'] as $key) {
        if (strcasecmp(trim((string) ($lead[$key] ?? '')), 'TEST') === 0) {
            return [
                'test' => true,
                'reason' => 'fortnox test',
                'email' => (string) ($match['email'] ?? ''),
                'phone' => (string) ($match['phone'] ?? ''),
            ];
        }
    }

    return $match;
}

/** @param array<string, mixed>|null $body */
function jeab_fortnox_body_lead(?array $body): array
{
    $lead = ['emails' => [], 'names' => [], 'phones' => []];
    if (!is_array($body)) {
        return $lead;
    }
    $nodes = [$body];
    foreach (['Customer', 'Order', 'Invoice', 'Offer', 'TaxReduction'] as $key) {
        if (isset($body[$key]) && is_array($body[$key])) {
            $nodes[] = $body[$key];
        }
    }
    foreach ($nodes as $node) {
        foreach (['Email', 'email'] as $key) {
            if (isset($node[$key]) && is_scalar($node[$key]) && trim((string) $node[$key]) !== '') {
                $lead['emails'][] = (string) $node[$key];
            }
        }
        foreach (['Name', 'YourReference', 'CustomerName', 'name'] as $key) {
            if (isset($node[$key]) && is_scalar($node[$key]) && trim((string) $node[$key]) !== '') {
                $lead['names'][] = (string) $node[$key];
            }
        }
        foreach (['Phone1', 'Phone2', 'Phone', 'phone'] as $key) {
            if (isset($node[$key]) && is_scalar($node[$key]) && trim((string) $node[$key]) !== '') {
                $lead['phones'][] = (string) $node[$key];
            }
        }
        foreach (['CustomerNumber', 'customerNumber'] as $key) {
            if (isset($node[$key]) && strcasecmp(trim((string) $node[$key]), 'TEST') === 0) {
                $lead['fortnox_customer'] = 'TEST';
            }
        }
        foreach (['DocumentNumber', 'documentNumber'] as $key) {
            if (isset($node[$key]) && strcasecmp(trim((string) $node[$key]), 'TEST') === 0) {
                $lead['fortnox_order'] = 'TEST';
            }
        }
    }

    return $lead;
}

function jeab_fortnox_path_is_test(string $path): bool
{
    $only = (string) (parse_url($path, PHP_URL_PATH) ?: $path);

    return preg_match('#/(?:customers|orders|invoices|offers)/test(?:/|$)#i', $only) === 1;
}

/** @return array<string, mixed> */
function jeab_fortnox_test_fake(string $path): array
{
    $only = strtolower((string) (parse_url($path, PHP_URL_PATH) ?: $path));
    if (str_contains($only, '/customers')) {
        return ['Customer' => ['CustomerNumber' => 'TEST', 'Name' => 'TEST'], 'TEST' => true];
    }
    if (str_contains($only, '/invoices')) {
        return ['Invoice' => ['DocumentNumber' => 'TEST', 'CustomerNumber' => 'TEST'], 'TEST' => true];
    }
    if (str_contains($only, '/offers')) {
        return ['Offer' => ['DocumentNumber' => 'TEST'], 'TEST' => true];
    }
    if (str_contains($only, '/taxreductions')) {
        return ['TaxReduction' => ['Id' => 'TEST'], 'TEST' => true];
    }

    return ['Order' => ['DocumentNumber' => 'TEST', 'CustomerNumber' => 'TEST'], 'TEST' => true];
}

/** @return array{customerNumber:string,orderNumber:string,taxReductions:int,taxReductionError:string,test:bool,updated:bool} */
function jeab_fortnox_test_order_result(): array
{
    return [
        'customerNumber' => 'TEST',
        'orderNumber' => 'TEST',
        'taxReductions' => 0,
        'taxReductionError' => '',
        'test' => true,
        'updated' => true,
    ];
}

/** @param array<string, mixed> $match */
function jeab_fortnox_log_test_skip(array $match): void
{
    if (!isset($GLOBALS['_JEAB_FORTNOX_TEST_LOG']) || !is_array($GLOBALS['_JEAB_FORTNOX_TEST_LOG'])) {
        $GLOBALS['_JEAB_FORTNOX_TEST_LOG'] = [];
    }
    $GLOBALS['_JEAB_FORTNOX_TEST_LOG'][] = 'test lead, Fortnox skipped';
    if (function_exists('jeab_test_lead_log')) {
        jeab_test_lead_log(
            'fortnox',
            (string) (($match['reason'] ?? '') !== '' ? $match['reason'] : 'test'),
            (string) ($match['email'] ?? ''),
            (string) ($match['phone'] ?? ''),
            'test lead, Fortnox skipped'
        );
    }
}

/**
 * Namn, e-post, telefon och adress från kontakten när raden har contact_id.
 * Flaggan av eller saknad kontakt lämnar input orörd. Personnummer rörs inte.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_fortnox_sole_customer_input(array $input): array
{
    if (!function_exists('jeab_contacts_sole') || !jeab_contacts_sole() || !function_exists('jeab_contacts_sole_recipient_contact') || !function_exists('jeab_contacts_sole_recipient')) {
        return $input;
    }
    if (trim((string) ($input['contact_id'] ?? '')) === '') {
        return $input;
    }
    $contact = jeab_contacts_sole_recipient_contact($input);
    if (!is_array($contact) || !function_exists('jeab_contacts_sole_view_from_contact')) {
        return $input;
    }
    $view = jeab_contacts_sole_view_from_contact($contact);
    $rec = jeab_contacts_sole_recipient($input);
    $out = $input;
    $strict = function_exists('jeab_contacts_strict') && jeab_contacts_strict();
    if ($strict || trim((string) ($view['name'] ?? '')) !== '') {
        $out['name'] = $rec['name'];
        $out['huvudsokande'] = $rec['name'];
        $out['customer_name'] = $rec['name'];
    }
    if ($strict || trim((string) ($view['email'] ?? '')) !== '') {
        $out['email'] = $rec['email'];
    }
    if ($strict || trim((string) ($view['phone'] ?? '')) !== '') {
        $out['phone'] = $rec['phone'];
    }
    if ($strict || trim((string) ($view['street'] ?? '')) !== '') {
        $out['gata'] = $rec['street'];
    }
    if ($strict || trim((string) ($view['city'] ?? '')) !== '') {
        $out['ort'] = $rec['city'];
    }
    if ($strict || trim((string) ($view['postal'] ?? '')) !== '') {
        $out['postnummer'] = $rec['postal'];
        $fmt = trim((string) ($view['postal_fmt'] ?? ''));
        $out['postnr'] = $fmt !== '' ? $fmt : $rec['postal'];
    }
    if ($strict || trim((string) ($view['display'] ?? '')) !== '') {
        if (!is_array($out['address'] ?? null)) {
            $out['address'] = $rec['display'];
        }
        $out['huvudsokande_address'] = $rec['display'];
    }

    return $out;
}

/** @param array<string, mixed> $input */
function jeab_fortnox_strict_throw(array $input): void
{
    if (trim((string) ($input['contact_id'] ?? '')) === '' || !function_exists('jeab_contacts_strict_block')) {
        return;
    }
    $block = jeab_contacts_strict_block($input, 'fortnox');
    if ($block !== '') {
        throw new InvalidArgumentException($block);
    }
}

/** @param array<string, mixed> $row */
function jeab_fortnox_row_is_test(array $row): array
{
    $painted = jeab_fortnox_sole_customer_input($row);
    if ($painted !== $row) {
        $raw = jeab_fortnox_row_is_test_inner($row);
        if (!empty($raw['test'])) {
            return $raw;
        }
    }

    return jeab_fortnox_row_is_test_inner($painted);
}

/** @param array<string, mixed> $row */
function jeab_fortnox_row_is_test_inner(array $row): array
{
    $names = [];
    foreach (['huvudsokande', 'customer_name', 'customer', 'name'] as $key) {
        if (isset($row[$key]) && is_scalar($row[$key]) && trim((string) $row[$key]) !== '') {
            $names[] = (string) $row[$key];
        }
    }

    return jeab_fortnox_test_lead_probe([
        'email' => (string) ($row['email'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'names' => $names,
        'fortnox_order' => (string) ($row['fortnox_order'] ?? ''),
        'fortnox_customer' => (string) ($row['fortnox_customer'] ?? ''),
        'is_test' => !empty($row['is_test']),
        'flag_reason' => (string) ($row['flag_reason'] ?? ''),
    ]);
}

/**
 * @param array<string, mixed> $input
 * @return array{test:bool,reason:string,email:string,phone:string}
 */
function jeab_fortnox_input_is_test(array $input, string $orderNumber = ''): array
{
    $names = [];
    foreach (['name', 'huvudsokande', 'customer_name', 'customer'] as $key) {
        if (isset($input[$key]) && is_scalar($input[$key]) && trim((string) $input[$key]) !== '') {
            $names[] = (string) $input[$key];
        }
    }
    foreach (['owners', 'medsokande', 'agare'] as $list) {
        if (!isset($input[$list]) || !is_array($input[$list])) {
            continue;
        }
        foreach ($input[$list] as $person) {
            if (is_array($person) && isset($person['name']) && is_scalar($person['name']) && trim((string) $person['name']) !== '') {
                $names[] = (string) $person['name'];
            }
        }
    }
    $order = $orderNumber !== '' ? $orderNumber : (string) ($input['fortnox_order'] ?? $input['orderNumber'] ?? '');

    return jeab_fortnox_test_lead_probe([
        'email' => (string) ($input['email'] ?? ''),
        'phone' => (string) ($input['phone'] ?? ''),
        'names' => $names,
        'fortnox_order' => $order,
        'fortnox_customer' => (string) ($input['fortnox_customer'] ?? $input['customerNumber'] ?? ''),
        'is_test' => !empty($input['is_test']),
        'flag_reason' => (string) ($input['flag_reason'] ?? ''),
    ]);
}

/**
 * DeliveryDate skrivs som YYYY-MM-DD.
 * Faktura: PUT /3/invoices/{n} {"Invoice":{"DeliveryDate":"YYYY-MM-DD"}}
 * Order:   PUT /3/orders/{n}   {"Order":{"DeliveryDate":"YYYY-MM-DD"}}
 * Offert:  PUT /3/offers/{n}   {"Offer":{"DeliveryDate":"YYYY-MM-DD"}}
 * path är det request() skickar (värden utan /3). wire är sökvägen mot api.fortnox.se.
 *
 * @return array{method:string,path:string,wire:string,body:array<string,array{DeliveryDate:string}>}
 */
function jeab_fortnox_delivery_date_write(string $kind, string $number, string $ymd): array
{
    $number = trim($number);
    $ymd = jeab_fortnox_delivery_ymd($ymd);
    $kind = strtolower(trim($kind));
    $segment = match ($kind) {
        'invoice', 'faktura' => 'invoices',
        'order' => 'orders',
        'offer', 'offert' => 'offers',
        default => '',
    };
    $wrap = match ($segment) {
        'invoices' => 'Invoice',
        'orders' => 'Order',
        'offers' => 'Offer',
        default => '',
    };
    if ($segment === '' || $wrap === '' || $number === '' || $ymd === '' || str_contains($number, '/') || str_contains($number, '\\')) {
        throw new InvalidArgumentException('Leveransdatum saknas.');
    }
    $path = '/' . $segment . '/' . rawurlencode($number);
    return [
        'method' => 'PUT',
        'path' => $path,
        'wire' => '/3' . $path,
        'body' => [$wrap => ['DeliveryDate' => $ymd]],
    ];
}

/** Datumdelen av en bokningstid. Tom sträng om det inte är ett riktigt datum. */
function jeab_fortnox_delivery_ymd(string $raw): string
{
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($raw), $match) !== 1) {
        return '';
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $match[1]);
    if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $match[1]) {
        return '';
    }
    return $match[1];
}

function jeab_fortnox_article_lookup_reason(int $status, int $code, string $message): string
{
    if ($status === 401 || $status === 403 || jeab_fortnox_is_missing_scope($status, $code, $message)) {
        return 'auth';
    }
    if ($status === 404) {
        return 'not_found';
    }
    return 'other';
}

function jeab_fortnox_article_safe_detail(string $message): string
{
    $message = jeab_fortnox_mask_log_text($message);
    $message = preg_replace('/(bearer|access_token|refresh_token|client_secret)\s*[:=]?\s*\S+/i', '$1 ****', $message) ?? $message;
    if (strlen($message) > 180) {
        $message = substr($message, 0, 180);
    }
    return $message;
}

/** Kort paus mellan artikel-GET så en förhandsvisning inte träffar Fortnox 429. */
function jeab_fortnox_article_pause(): void
{
    $GLOBALS['_JEAB_FORTNOX_ARTICLE_PAUSES'] = (int) ($GLOBALS['_JEAB_FORTNOX_ARTICLE_PAUSES'] ?? 0) + 1;
    $pause = (int) ($GLOBALS['_JEAB_FORTNOX_ARTICLE_PAUSE_US'] ?? 200000);
    if ($pause > 0) {
        usleep($pause);
    }
}

class FortnoxApiException extends Exception
{
    public function __construct(
        string $message,
        public int $httpStatus = 0,
        public int $fortnoxCode = 0
    ) {
        parent::__construct($message);
    }
}

function jeab_fortnox_http_error(int $status, array $data): FortnoxApiException
{
    $info = isset($data['ErrorInformation']) && is_array($data['ErrorInformation']) ? $data['ErrorInformation'] : [];
    $message = trim((string) ($info['message'] ?? $info['Message'] ?? $data['error_description'] ?? $data['error'] ?? ''));
    $code = (int) ($info['code'] ?? $info['Code'] ?? 0);
    if ($message === '') {
        $message = 'Fortnox HTTP ' . $status;
    }
    return new FortnoxApiException($message, $status, $code);
}

/** 2000663 — kopplingen saknar scope. Ingen licensfråga (2001101) och inget nytt försök. */
function jeab_fortnox_is_missing_scope(int $status, int $fortnoxCode, string $message): bool
{
    unset($status);
    if ($fortnoxCode === 2000663) {
        return true;
    }
    return (bool) preg_match('/har inte behörighet för scope|no access to the current scope|saknar scope|missing scope/i', $message);
}

function jeab_fortnox_exception_is_missing_scope(Throwable $e): bool
{
    if (!$e instanceof FortnoxApiException) {
        return jeab_fortnox_is_missing_scope(0, 0, $e->getMessage());
    }
    return jeab_fortnox_is_missing_scope($e->httpStatus, $e->fortnoxCode, $e->getMessage());
}

/**
 * Bara ett extra försök, och bara på 429 under en pågående läsning.
 * Saknat scope försöks aldrig om.
 */
function jeab_fortnox_may_retry_read(int $status, int $fortnoxCode, string $message, bool $getOnly, bool $alreadyRetried): bool
{
    if (!$getOnly || $alreadyRetried || $status !== 429) {
        return false;
    }
    return !jeab_fortnox_is_missing_scope($status, $fortnoxCode, $message);
}

function jeab_fortnox_assert_get(string $method): void
{
    if ($method !== 'GET') {
        throw new LogicException('Fortnox-anropet får bara använda GET.');
    }
    assert($method === 'GET');
}

function jeab_fortnox_retry_pause(): void
{
    $wait = array_key_exists('_JEAB_FORTNOX_SLEEP_US', $GLOBALS) ? (int) $GLOBALS['_JEAB_FORTNOX_SLEEP_US'] : 5000000;
    if ($wait > 0) {
        usleep($wait);
    }
}

function jeab_fortnox_scope_denied(): void
{
    $GLOBALS['_JEAB_FORTNOX_SCOPE_DENIED'] = true;
}

/**
 * Ett refresh-anrop i taget, för båda bolagen.
 * Access-token återanvänds tills den är nära utgång. Ett avvisat refresh
 * skickas inte igen: Fortnox drar in hela kopplingen om ett gammalt refresh används.
 *
 * @param callable(string):array $exchange
 */
function jeab_fortnox_oauth_refresh(string $account, callable $exchange): string
{
    if (function_exists('jeab_kontakt_quiet') && jeab_kontakt_quiet()) {
        jeab_kontakt_side_effect_note('fortnox');
        throw new LogicException('Fortnox är avstängt under sammanslagning och import.');
    }
    if (!empty($GLOBALS['_JEAB_FORTNOX_SCOPE_DENIED'])) {
        throw new LogicException('Fortnox-scope saknas. Ingen ny inloggning.');
    }
    $account = jeab_fortnox_token_account_key($account);
    return jeab_fortnox_tokens_with_lock(static function () use ($account, $exchange): string {
        jeab_fortnox_tokens_migrate_unlocked();
        $path = jeab_fortnox_tokens_canonical_path();
        $store = jeab_fortnox_tokens_normalize_store(jeab_fortnox_tokens_read_file($path));
        $row = $store[$account] ?? [];
        if (!is_array($row)) {
            $row = ['refresh_token' => jeab_fortnox_token_refresh_value($row)];
        }
        $now = time();
        $access = trim((string) ($row['access_token'] ?? ''));
        $expires = (int) ($row['expires_at'] ?? 0);
        if ($access !== '' && $expires > $now + 90) {
            return $access;
        }
        $refresh = jeab_fortnox_token_refresh_value($row);
        if ($refresh === '') {
            throw new Exception('Fortnox-token saknas för ' . $account);
        }
        $rejected = (string) ($row['rejected_refresh_sha256'] ?? '');
        if ($rejected !== '' && hash_equals($rejected, hash('sha256', $refresh))) {
            throw new Exception('Fortnox behöver kopplas om för ' . $account);
        }
        $exchanged = $exchange($refresh);
        if (!is_array($exchanged)) {
            $exchanged = ['ok' => false, 'transient' => true, 'status' => 0];
        }
        $status = (int) ($exchanged['status'] ?? 0);
        $transient = !empty($exchanged['transient']) || $status === 0 || $status === 429 || $status >= 500;
        $newAccess = trim((string) ($exchanged['access_token'] ?? ''));
        if (!empty($exchanged['ok']) && $newAccess !== '') {
            $rotated = trim((string) ($exchanged['refresh_token'] ?? ''));
            if ($rotated !== '') {
                $row['refresh_token'] = $rotated;
            }
            $row['access_token'] = $newAccess;
            $ttl = (int) ($exchanged['expires_in'] ?? 3600);
            if ($ttl < 120) {
                $ttl = 3600;
            }
            $row['expires_at'] = $now + $ttl;
            unset($row['rejected_refresh_sha256'], $row['rejected_at']);
            $store[$account] = $row;
            $GLOBALS['_JEAB_FORTNOX_TOKENS_MEMORY'] = $store;
            if (!jeab_fortnox_tokens_write_durable($path, $store)) {
                throw new Exception('Fortnox-token kunde inte sparas');
            }
            return $newAccess;
        }
        if ($transient) {
            // Orsaken (curl-fel eller HTTP-kod, aldrig token) visas och loggas.
            $detail = trim((string) ($exchanged['detail'] ?? ('HTTP ' . $status)));
            if (function_exists('jeab_log_app_error')) {
                jeab_log_app_error('fortnox', 'Fortnox OAuth: ' . $detail);
            }
            throw new Exception('Ingen kontakt med Fortnox OAuth (' . $detail . ')');
        }
        $row['rejected_refresh_sha256'] = hash('sha256', $refresh);
        $row['rejected_at'] = $now;
        $store[$account] = $row;
        jeab_fortnox_tokens_write_durable($path, $store);
        throw new Exception('Fortnox behöver kopplas om för ' . $account);
    });
}

class FortnoxAPI
{
    private string $account;
    private string $clientId;
    private string $clientSecret;
    private array $articles;
    private string $tokensFile;
    private ?string $accessToken = null;

    public function __construct(string $account = 'Jarfalla')
    {
        $configPath = jeab_fortnox_config_path();
        $config = is_file($configPath) ? require $configPath : [];
        if (!is_array($config)) {
            $config = [];
        }
        $this->account = $account;
        $this->clientId = (string) ($config['clientId'] ?? $config['client_id'] ?? '');
        $this->clientSecret = (string) ($config['clientSecret'] ?? $config['client_secret'] ?? '');
        $allArticles = $config['articles'] ?? [];
        $this->articles = is_array($allArticles[$account] ?? null) ? $allArticles[$account] : (is_array($allArticles) ? $allArticles : []);
        $this->tokensFile = jeab_fortnox_tokens_path();
    }

    /** @return array{ok?:bool,status?:int,access_token?:string,refresh_token?:string,expires_in?:int,error?:string,transient?:bool} */
    private function exchangeRefreshToken(string $refresh): array
    {
        if (function_exists('jeab_kontakt_quiet') && jeab_kontakt_quiet()) {
            jeab_kontakt_side_effect_note('fortnox');
            throw new LogicException('Fortnox är avstängt under sammanslagning och import.');
        }
        if (isset($GLOBALS['_JEAB_FORTNOX_OAUTH']) && is_callable($GLOBALS['_JEAB_FORTNOX_OAUTH'])) {
            $stub = ($GLOBALS['_JEAB_FORTNOX_OAUTH'])($refresh, $this->account);
            return is_array($stub) ? $stub : ['ok' => false, 'transient' => true, 'status' => 0];
        }
        if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('fortnox', 'oauth')) {
            return ['ok' => false, 'error' => 'demo', 'demo' => true, 'status' => 0];
        }
        $ch = curl_init('https://apps.fortnox.se/oauth-v1/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh,
            ]),
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        // Anslutningen ligger kvar: anropet skriver eller en databasrad sparas efteråt.
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $errstr = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $code === 0) {
            return ['ok' => false, 'transient' => true, 'status' => 0, 'detail' => 'curl ' . $errno . ': ' . substr($errstr, 0, 120)];
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $data = [];
        }
        if ($code === 429 || $code >= 500) {
            return ['ok' => false, 'transient' => true, 'status' => $code, 'detail' => 'HTTP ' . $code . ' ' . substr((string) ($data['error'] ?? ''), 0, 60)];
        }
        $access = trim((string) ($data['access_token'] ?? ''));
        if ($code >= 400 || $access === '') {
            return [
                'ok' => false,
                'transient' => false,
                'status' => $code,
                'error' => (string) ($data['error'] ?? ''),
            ];
        }
        return [
            'ok' => true,
            'status' => $code,
            'access_token' => $access,
            'refresh_token' => trim((string) ($data['refresh_token'] ?? '')),
            'expires_in' => (int) ($data['expires_in'] ?? 3600),
        ];
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }
        $this->accessToken = jeab_fortnox_oauth_refresh(
            $this->account,
            fn (string $refresh): array => $this->exchangeRefreshToken($refresh)
        );
        return $this->accessToken;
    }

    /** @return array<string, mixed>|null */
    private function testLeadWrite(string $method, string $path, ?array $body): ?array
    {
        $verb = strtoupper($method);
        $pathTest = jeab_fortnox_path_is_test($path);
        if ($verb === 'GET' && !$pathTest) {
            return null;
        }
        if (!in_array($verb, ['POST', 'PUT', 'DELETE', 'GET'], true)) {
            return null;
        }
        $probe = jeab_fortnox_test_lead_probe(jeab_fortnox_body_lead($body));
        if ($pathTest && empty($probe['test'])) {
            $probe = ['test' => true, 'reason' => 'fortnox test', 'email' => '', 'phone' => ''];
        }
        if (empty($probe['test'])) {
            return null;
        }
        jeab_fortnox_log_test_skip($probe);

        return jeab_fortnox_test_fake($path);
    }

    private function request(string $method, string $path, ?array $body = null, bool $retried = false): array
    {
        if (function_exists('jeab_kontakt_quiet') && jeab_kontakt_quiet()) {
            jeab_kontakt_side_effect_note('fortnox');
            throw new LogicException('Fortnox är avstängt under sammanslagning och import.');
        }
        $skipped = $this->testLeadWrite($method, $path, $body);
        if ($skipped !== null) {
            return $skipped;
        }
        if (!empty($GLOBALS['_JEAB_FORTNOX_GET_ONLY'])) {
            jeab_fortnox_assert_get($method);
        }
        if (!empty($GLOBALS['_JEAB_FORTNOX_SCOPE_DENIED'])) {
            throw new LogicException('Fortnox-scope saknas. Inget nytt anrop och ingen ny inloggning.');
        }
        if ($body !== null) {
            $body = jeab_fortnox_normalize_ids($body);
        }
        if (isset($GLOBALS['_JEAB_FORTNOX_REQUEST']) && is_callable($GLOBALS['_JEAB_FORTNOX_REQUEST'])) {
            $stub = ($GLOBALS['_JEAB_FORTNOX_REQUEST'])($method, $path, $body);
            return is_array($stub) ? $stub : [];
        }
        if (function_exists('jeab_demo_outbound') && jeab_demo_outbound('fortnox', $method . ' ' . $path)) {
            return [];
        }
        $url = 'https://api.fortnox.se/3' . $path;
        $headers = [
            'Authorization: Bearer ' . $this->getAccessToken(),
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        $payload = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        appLog('fortnox_request', [
            'account' => $this->account,
            'method' => $method,
            'path' => $path,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($payload !== null && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        // Anslutningen ligger kvar: anropet skriver eller en databasrad sparas efteråt.
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new Exception($err !== '' ? $err : 'Ingen kontakt med Fortnox');
        }
        $data = json_decode((string) $raw, true);
        if ($code >= 400) {
            $ex = jeab_fortnox_http_error($code, is_array($data) ? $data : []);
            if (jeab_fortnox_exception_is_missing_scope($ex)) {
                jeab_fortnox_scope_denied();
            } elseif (jeab_fortnox_may_retry_read($ex->httpStatus, $ex->fortnoxCode, $ex->getMessage(), !empty($GLOBALS['_JEAB_FORTNOX_GET_ONLY']), $retried)) {
                jeab_fortnox_retry_pause();
                return $this->request($method, $path, $body, true);
            }
            throw $ex;
        }
        return is_array($data) ? $data : [];
    }

    /** Läsning. Anropas bara med GET; skrivskyddet ligger i jeab_fortnox_assert_get. */
    public function get(string $path): array
    {
        jeab_fortnox_assert_get('GET');
        return $this->request('GET', $path);
    }

    /** Artikelnummer från fortnox_config, samma karta som orderraderna. */
    public function configuredArticle(string $key): string
    {
        return trim((string) ($this->articles[$key] ?? ''));
    }

    /**
     * Slår upp en artikel med samma GET som övriga Fortnox-anrop.
     * Direkt /articles/{nummer} först. Vid 404 eller ett tomt 200-svar
     * samma lista som ordrar använder: http_build_query, limit och page,
     * filtrerat på articlenumber.
     *
     * @return array{http:int,direct_http:int,list_http:?int,reason:string,lookup:string,article:?array,detail:string}
     */
    public function probeArticle(string $number): array
    {
        $number = trim($number);
        $empty = [
            'http' => 0,
            'direct_http' => 0,
            'list_http' => null,
            'reason' => 'other',
            'lookup' => '',
            'article' => null,
            'detail' => 'Artikelnummer saknas.',
        ];
        if ($number === '') {
            return $empty;
        }
        $direct = $this->articleGet('/articles/' . rawurlencode($number));
        if ($direct['reason'] === 'auth') {
            return $this->articleProbe($direct, null, '', null);
        }
        $article = $this->articleFromPayload($direct['data'], $number, '');
        if ($direct['reason'] === 'ok' && is_array($article)) {
            return $this->articleProbe($direct, null, 'direct', $article);
        }
        if ($direct['reason'] === 'other') {
            return $this->articleProbe($direct, null, '', null);
        }
        $list = $this->articleFindInList(['articlenumber' => $number], $number, '');
        if ($list['reason'] === 'auth' || ($list['reason'] === 'other' && $list['http'] >= 400)) {
            return [
                'http' => $list['http'],
                'direct_http' => $direct['http'],
                'list_http' => $list['http'],
                'reason' => $list['reason'],
                'lookup' => '',
                'article' => null,
                'detail' => $list['detail'],
            ];
        }
        if (is_array($list['article'])) {
            return [
                'http' => $list['http'],
                'direct_http' => $direct['http'],
                'list_http' => $list['http'],
                'reason' => 'found',
                'lookup' => 'articlenumber',
                'article' => $list['article'],
                'detail' => '',
            ];
        }
        $reason = $direct['reason'] === 'not_found' ? 'not_found' : 'not_found';
        $detail = $direct['detail'] !== '' ? $direct['detail'] : 'ingen träff';
        return [
            'http' => $direct['http'] >= 400 ? $direct['http'] : $list['http'],
            'direct_http' => $direct['http'],
            'list_http' => $list['http'],
            'reason' => $reason,
            'lookup' => '',
            'article' => null,
            'detail' => $detail,
        ];
    }

    /**
     * Beskrivningssökning med samma query-byggare som orderlistan, sida för sida.
     *
     * @return array{http:int,direct_http:int,list_http:?int,reason:string,lookup:string,article:?array,detail:string}
     */
    public function probeArticleByDescription(string $description): array
    {
        $description = trim($description);
        if ($description === '') {
            return [
                'http' => 0,
                'direct_http' => 0,
                'list_http' => null,
                'reason' => 'other',
                'lookup' => '',
                'article' => null,
                'detail' => 'Beskrivning saknas.',
            ];
        }
        $fold = function_exists('jeab_sigen_articles_fold')
            ? jeab_sigen_articles_fold($description)
            : strtolower($description);
        $list = $this->articleFindInList(['description' => $description], '', $fold);
        return [
            'http' => $list['http'],
            'direct_http' => 0,
            'list_http' => $list['http'],
            'reason' => $list['reason'] === 'found' ? 'found' : $list['reason'],
            'lookup' => $list['reason'] === 'found' ? 'description' : '',
            'article' => $list['article'],
            'detail' => $list['reason'] === 'found' ? '' : ($list['detail'] !== '' ? $list['detail'] : 'ingen träff'),
        ];
    }

    /** @return array<string,mixed>|null */
    public function getArticle(string $number): ?array
    {
        $probe = $this->probeArticle($number);
        if ($probe['reason'] === 'found') {
            return $probe['article'];
        }
        if ($probe['reason'] === 'not_found') {
            return null;
        }
        throw new FortnoxApiException(
            $probe['detail'] !== '' ? $probe['detail'] : 'Fortnox-fel.',
            $probe['http']
        );
    }

    /** @return list<array<string,mixed>> */
    public function searchArticles(string $description): array
    {
        $probe = $this->probeArticleByDescription($description);
        if ($probe['reason'] === 'auth' || ($probe['reason'] === 'other' && $probe['http'] >= 400)) {
            throw new FortnoxApiException(
                $probe['detail'] !== '' ? $probe['detail'] : 'Fortnox-fel.',
                $probe['http']
            );
        }
        return is_array($probe['article']) ? [$probe['article']] : [];
    }

    /** @param array{http:int,reason:string,detail:string} $direct */
    private function articleProbe(array $direct, ?int $listHttp, string $lookup, ?array $article): array
    {
        $reason = $direct['reason'] === 'ok' && is_array($article) ? 'found' : $direct['reason'];
        if ($reason === 'ok') {
            $reason = 'other';
        }
        return [
            'http' => $direct['http'],
            'direct_http' => $direct['http'],
            'list_http' => $listHttp,
            'reason' => $reason,
            'lookup' => $lookup,
            'article' => $article,
            'detail' => $reason === 'found' ? '' : $direct['detail'],
        ];
    }

    /** @return array{http:int,data:array<string,mixed>,reason:string,detail:string} */
    private function articleGet(string $path): array
    {
        jeab_fortnox_article_pause();
        try {
            $data = $this->request('GET', $path);
            return [
                'http' => 200,
                'data' => is_array($data) ? $data : [],
                'reason' => 'ok',
                'detail' => '',
            ];
        } catch (FortnoxApiException $e) {
            return [
                'http' => $e->httpStatus,
                'data' => [],
                'reason' => jeab_fortnox_article_lookup_reason($e->httpStatus, $e->fortnoxCode, $e->getMessage()),
                'detail' => jeab_fortnox_article_safe_detail($e->getMessage()),
            ];
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    private function articleFromPayload(array $data, string $number, string $descriptionFold): ?array
    {
        $one = $data['Article'] ?? null;
        if (!is_array($one) && isset($data['ArticleNumber'])) {
            $one = $data;
        }
        if (is_array($one) && ($number === '' || trim((string) ($one['ArticleNumber'] ?? '')) === $number)) {
            if ($descriptionFold === '' || $this->articleFold((string) ($one['Description'] ?? '')) === $descriptionFold) {
                return $one;
            }
        }
        return null;
    }

    /**
     * @param array<string,scalar> $query
     * @return array{http:int,reason:string,article:?array,detail:string}
     */
    private function articleFindInList(array $query, string $number, string $descriptionFold): array
    {
        $page = 1;
        $lastHttp = 0;
        $lastDetail = '';
        do {
            $params = ['limit' => 100, 'page' => $page];
            foreach ($query as $key => $value) {
                $params[$key] = $value;
            }
            $res = $this->articleGet('/articles?' . http_build_query($params));
            $lastHttp = $res['http'];
            $lastDetail = $res['detail'];
            if ($res['reason'] === 'auth' || ($res['reason'] === 'other' && $res['http'] >= 400)) {
                return ['http' => $res['http'], 'reason' => $res['reason'], 'article' => null, 'detail' => $res['detail']];
            }
            $rows = $res['data']['Articles'] ?? [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $gotNumber = trim((string) ($row['ArticleNumber'] ?? ''));
                    if ($number !== '' && $gotNumber === $number) {
                        return ['http' => 200, 'reason' => 'found', 'article' => $row, 'detail' => ''];
                    }
                    if ($descriptionFold !== '' && $this->articleFold((string) ($row['Description'] ?? '')) === $descriptionFold) {
                        return ['http' => 200, 'reason' => 'found', 'article' => $row, 'detail' => ''];
                    }
                }
            }
            $meta = is_array($res['data']['MetaInformation'] ?? null) ? $res['data']['MetaInformation'] : [];
            $pages = (int) ($meta['@TotalPages'] ?? $meta['TotalPages'] ?? 1);
            if ($pages < 1) {
                $pages = 1;
            }
            $page++;
        } while ($page <= $pages && $page <= 20);
        return [
            'http' => $lastHttp,
            'reason' => 'not_found',
            'article' => null,
            'detail' => $lastDetail !== '' ? $lastDetail : 'ingen träff',
        ];
    }

    private function articleFold(string $value): string
    {
        if (function_exists('jeab_sigen_articles_fold')) {
            return jeab_sigen_articles_fold($value);
        }
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function pnrDigits(string $pnr): string
    {
        return preg_replace('/\D+/', '', jeab_idnr_clean($pnr)) ?? '';
    }

    private function pnrSearchQueries(string $pnr): array
    {
        $digits = $this->pnrDigits($pnr);
        $out = [];
        $add = static function (string $value) use (&$out): void {
            $value = trim($value);
            if ($value !== '' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        };
        if (strlen($digits) === 12) {
            $ten = substr($digits, 2);
            $add($digits);
            $add(substr($digits, 0, 8) . '-' . substr($digits, 8));
            $add($ten);
            $add(substr($ten, 0, 6) . '-' . substr($ten, 6));
        } elseif (strlen($digits) === 10) {
            $add($digits);
            $add(substr($digits, 0, 6) . '-' . substr($digits, 6));
        } elseif ($digits !== '') {
            $add($digits);
        }
        $add(jeab_orgnr_normalize($pnr));
        $add(jeab_pnr_normalize($pnr));
        return $out;
    }

    private function customerNumber(array $customer): string
    {
        return trim((string) ($customer['CustomerNumber'] ?? $customer['customerNumber'] ?? ''));
    }

    private function customerIsActive(array $customer): bool
    {
        if (array_key_exists('Active', $customer)) {
            return !empty($customer['Active']);
        }
        if (array_key_exists('active', $customer)) {
            return !empty($customer['active']);
        }
        return true;
    }

    private function customersFromResponse(array $found): array
    {
        $list = $found['Customers'] ?? $found['Customer'] ?? [];
        if ($list === []) {
            return [];
        }
        if (isset($list['CustomerNumber']) || isset($list['customerNumber'])) {
            return [$list];
        }
        return is_array($list) ? $list : [];
    }

    private function searchCustomersByPnr(string $pnr): array
    {
        $seen = [];
        $all = [];
        foreach ($this->pnrSearchQueries($pnr) as $query) {
            $found = $this->request('GET', '/customers?organisationnumber=' . rawurlencode($query));
            foreach ($this->customersFromResponse($found) as $customer) {
                if (!is_array($customer)) {
                    continue;
                }
                $number = $this->customerNumber($customer);
                if ($number === '' || isset($seen[$number])) {
                    continue;
                }
                $seen[$number] = true;
                $all[] = $customer;
            }
        }
        return $all;
    }

    private function pickHighestNumber(array $numbers): string
    {
        $numbers = array_values(array_filter($numbers, static fn($n): bool => is_string($n) && $n !== ''));
        if ($numbers === []) {
            return '';
        }
        usort($numbers, static function (string $a, string $b): int {
            if (ctype_digit($a) && ctype_digit($b)) {
                return ((int) $b) <=> ((int) $a);
            }
            return strcmp($b, $a);
        });
        return $numbers[0];
    }

    private function pickHighestActive(array $customers, array $skip = []): string
    {
        $active = [];
        foreach ($customers as $customer) {
            if (!is_array($customer) || !$this->customerIsActive($customer)) {
                continue;
            }
            $number = $this->customerNumber($customer);
            if ($number === '' || isset($skip[$number])) {
                continue;
            }
            $active[] = $number;
        }
        return $this->pickHighestNumber($active);
    }

    private function pickHighestExisting(array $customers, array $skip = []): string
    {
        $numbers = [];
        foreach ($customers as $customer) {
            if (!is_array($customer)) {
                continue;
            }
            $number = $this->customerNumber($customer);
            if ($number === '' || isset($skip[$number])) {
                continue;
            }
            $numbers[] = $number;
        }
        return $this->pickHighestNumber($numbers);
    }

    private function isMissingOrInactiveError(string $message): bool
    {
        return (bool) preg_match('/inaktiv|inte var aktiv|kunden finns inte|not active|inactive|does not exist/i', $message);
    }

    public static function pickField(array $raw, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $raw) || is_array($raw[$key])) {
                continue;
            }
            $value = trim((string) $raw[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /** Landssvans «Sverige»/«Sweden» hör inte hemma i gata, ort eller land. */
    private static function addressWithoutCountry(string $raw): string
    {
        if (function_exists('jeab_kontakt_strip_country')) {
            return jeab_kontakt_strip_country($raw);
        }
        $raw = trim($raw);
        $prev = null;
        while ($raw !== '' && $raw !== $prev) {
            $prev = $raw;
            $raw = preg_replace('/\s*,?\s*(?:sverige|sweden|suede|suède)\s*$/iu', '', $raw) ?? $raw;
            $raw = preg_replace('/\s*,\s*se\s*$/iu', '', $raw) ?? $raw;
            $raw = trim($raw, " ,");
        }
        return $raw;
    }

    private static function addressIsCountry(string $value): bool
    {
        return preg_match('/^(?:sverige|sweden|suede|suède|se)$/iu', trim($value)) === 1;
    }

    /** Postnummer som fem siffror. Annan text lämnas orörd. */
    private static function addressPostal(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 5) {
            return $digits;
        }
        return trim($raw);
    }

    private static function addressCity(string $city): string
    {
        $city = self::addressWithoutCountry($city);
        if ($city === '' || self::addressIsCountry($city)) {
            return '';
        }
        if (function_exists('jeab_kontakt_city_name')) {
            $city = jeab_kontakt_city_name($city);
        }
        return trim($city, " ,");
    }

    /**
     * Text efter husnummer (+ en bokstav) är ort när den är versal eller en känd ort.
     *
     * @return array{gata:string,ort:string}
     */
    private static function addressStreetAndCity(string $street): array
    {
        $street = trim($street);
        if (preg_match('/^(.+?\s+\d+(?:\s*[A-Za-zÅÄÖåäö])?)\s+(.+)$/u', $street, $m) !== 1) {
            return ['gata' => $street, 'ort' => ''];
        }
        $gata = trim($m[1]);
        $tail = trim($m[2]);
        if ($tail === '' || preg_match('/\d/u', $tail) === 1 || self::addressIsCountry($tail)) {
            return ['gata' => $street, 'ort' => ''];
        }
        if (preg_match('/^(\p{L})\s+(.+)$/u', $tail, $letter) === 1) {
            $gata = trim($gata . ' ' . $letter[1]);
            $tail = trim($letter[2]);
        }
        if (preg_match('/^(?:lgh|lägenhet|lagenhet|tr|bv|vån|van|nb|uppg|port)$/iu', $tail) === 1) {
            return ['gata' => $street, 'ort' => ''];
        }
        $known = function_exists('jeab_kontakt_place_canon') ? jeab_kontakt_place_canon($tail) : '';
        $capital = preg_match('/^\p{Lu}[\p{L}\'-]+(?:\s+\p{Lu}[\p{L}\'-]+)*$/u', $tail) === 1;
        if ($known === '' && !$capital) {
            return ['gata' => $street, 'ort' => ''];
        }
        return ['gata' => $gata, 'ort' => $known !== '' ? $known : $tail];
    }

    /** Tar bort en känd ort som hänger efter husnumret, med eller utan komma. */
    private static function addressStripCity(string $street, string $city): string
    {
        $city = trim($city);
        if ($street === '' || $city === '' || self::addressIsCountry($city)) {
            return $street;
        }
        $pattern = '/^(.*\d(?:\s*[A-Za-zÅÄÖåäö])?)\s*,?\s*' . preg_quote($city, '/') . '\s*$/iu';
        if (preg_match($pattern, $street, $m) === 1) {
            return trim($m[1], " ,");
        }
        return $street;
    }

    public static function parseDisplayAddress(string $line): array
    {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
        $line = self::addressWithoutCountry($line);
        if ($line === '') {
            return [];
        }
        $postnr = '';
        $ort = '';
        $gata = $line;
        if (preg_match('/(?<!\d)(\d{3})\s?(\d{2})(?!\d)/u', $line, $m, PREG_OFFSET_CAPTURE) === 1) {
            $postnr = $m[1][0] . $m[2][0];
            $gata = trim(substr($line, 0, (int) $m[0][1]), " ,");
            $ort = self::addressCity(trim(substr($line, (int) $m[0][1] + strlen($m[0][0])), " ,"));
        }
        if ($ort === '' && str_contains($gata, ',')) {
            $parts = array_values(array_filter(
                array_map('trim', explode(',', $gata)),
                static fn (string $part): bool => $part !== '' && !self::addressIsCountry($part)
            ));
            $last = $parts !== [] ? (string) $parts[count($parts) - 1] : '';
            if ($last !== '' && count($parts) > 1 && preg_match('/\d/u', $last) !== 1) {
                array_pop($parts);
                $ort = self::addressCity($last);
                $gata = implode(', ', $parts);
            }
        }
        if ($ort === '') {
            $split = self::addressStreetAndCity($gata);
            $gata = $split['gata'];
            $ort = self::addressCity($split['ort']);
        }
        $gata = self::addressWithoutCountry($gata);
        return [
            'gata' => $gata,
            'postnr' => $postnr,
            'ort' => $ort,
            'land' => '',
        ];
    }

    public static function collectAddress(array $raw): array
    {
        $nested = $raw['address'] ?? $raw['adress'] ?? $raw['delivery'] ?? null;
        if (is_array($nested)) {
            $fromNested = self::collectAddress($nested);
            if ($fromNested !== []) {
                return $fromNested;
            }
        }
        $gata = self::pickField($raw, ['gata', 'Address1', 'street', 'adress1', 'address1']);
        if ($gata === '' && is_string($raw['adress'] ?? null)) {
            $gata = trim((string) $raw['adress']);
        }
        if ($gata === '' && is_string($raw['address'] ?? null)) {
            $gata = trim((string) $raw['address']);
        }
        $postnr = self::pickField($raw, ['postnr', 'ZipCode', 'postnummer', 'zip', 'postalCode', 'zipcode']);
        $ort = self::addressCity(self::pickField($raw, ['ort', 'City', 'city', 'postort']));
        $land = self::addressWithoutCountry(self::pickField($raw, ['land', 'Country', 'country']));
        if (self::addressIsCountry($land)) {
            $land = '';
        }
        if ($gata !== '') {
            $parsed = self::parseDisplayAddress($gata);
            if ($parsed !== []) {
                $gata = (string) ($parsed['gata'] ?? $gata);
                if ($postnr === '' && ($parsed['postnr'] ?? '') !== '') {
                    $postnr = (string) $parsed['postnr'];
                }
                if ($ort === '' && ($parsed['ort'] ?? '') !== '') {
                    $ort = (string) $parsed['ort'];
                }
            }
            if ($ort !== '') {
                $gata = self::addressStripCity($gata, $ort);
            }
        }
        $gata = self::addressWithoutCountry($gata);
        $postnr = self::addressPostal($postnr);
        if ($gata === '' && $postnr === '' && $ort === '' && $land === '') {
            return [];
        }
        return [
            'gata' => $gata,
            'postnr' => $postnr,
            'ort' => $ort,
            'land' => $land,
        ];
    }

    public static function collectOwner(array $item): array
    {
        $name = self::pickField($item, ['name', 'namn', 'Namn', 'fullName', 'full_name', 'CustomerName', 'huvudsokande']);
        $pnr = self::pickField($item, ['personnummer', 'pnr', 'ssn', 'Personnummer', 'SocialSecurityNumber']);
        if ($name === '' || $pnr === '') {
            return [];
        }
        $addr = self::collectAddress($item);
        $owner = [
            'name' => $name,
            'personnummer' => $pnr,
        ];
        if ($addr !== []) {
            $owner['address'] = $addr;
            $owner['gata'] = (string) ($addr['gata'] ?? '');
            $owner['postnr'] = (string) ($addr['postnr'] ?? '');
            $owner['ort'] = (string) ($addr['ort'] ?? '');
            $owner['land'] = (string) ($addr['land'] ?? '');
        }
        return $owner;
    }

    public static function ownerList(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }
        if (isset($raw['name']) || isset($raw['namn']) || isset($raw['personnummer']) || isset($raw['pnr']) || isset($raw['ssn']) || isset($raw['address']) || isset($raw['adress'])) {
            return [$raw];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    public static function collectAddressFromOwners(array $body): array
    {
        foreach (['owners', 'agare', 'ägare', 'medsokande', 'medsökande'] as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            foreach (self::ownerList($body[$key]) as $item) {
                $addr = self::collectAddress($item);
                if ($addr !== []) {
                    return $addr;
                }
            }
        }
        return [];
    }

    private static function ownerSeenKey(string $name, string $pnr): string
    {
        $fold = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $digits = preg_replace('/\D+/', '', $pnr) ?? '';
        if (strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }
        return $digits . '|' . $fold;
    }

    private static function appendOwner(array &$out, array &$seen, array $item, bool $walkNested = true): void
    {
        $owner = self::collectOwner($item);
        if ($owner !== []) {
            $keySeen = self::ownerSeenKey((string) $owner['name'], (string) $owner['personnummer']);
            if (!isset($seen[$keySeen])) {
                $seen[$keySeen] = true;
                $out[] = $owner;
            }
        }
        if (!$walkNested) {
            return;
        }
        foreach (['medsokande', 'medsökande', 'agare', 'ägare'] as $nestedKey) {
            if (!isset($item[$nestedKey])) {
                continue;
            }
            foreach (self::ownerList($item[$nestedKey]) as $child) {
                self::appendOwner($out, $seen, $child, false);
            }
        }
    }

    public static function collectOwners(array $body): array
    {
        $out = [];
        $seen = [];
        foreach (['owners', 'medsokande', 'medsökande', 'agare', 'ägare'] as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            foreach (self::ownerList($body[$key]) as $item) {
                self::appendOwner($out, $seen, $item, true);
            }
        }
        return $out;
    }

    public static function requireOwners(array $body): array
    {
        $missing = [];
        foreach (['owners', 'medsokande', 'medsökande', 'agare', 'ägare'] as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            foreach (self::ownerList($body[$key]) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $name = self::pickField($item, ['name', 'namn', 'Namn', 'fullName', 'full_name', 'CustomerName', 'huvudsokande']);
                $pnr = self::pickField($item, ['personnummer', 'pnr', 'ssn', 'Personnummer', 'SocialSecurityNumber']);
                if ($name !== '' && $pnr === '') {
                    $missing[$name] = true;
                }
            }
        }
        if ($missing !== []) {
            throw new Exception('Personnummer saknas för medsökande (' . implode(', ', array_keys($missing)) . ').');
        }
        return self::collectOwners($body);
    }

    private static function houseWorkHours(string $hw): int
    {
        return match ($hw) {
            'CHARGINGSTATIONELECTRICVEHICLE' => 4,
            'SOLARCELLS' => 16,
            'STORAGESELFPRODUCEDELECTRICITY' => 24,
            default => 0,
        };
    }

    public static function wireAddressFields(array $address): array
    {
        $address = self::collectAddress($address) ?: $address;
        $street = trim((string) ($address['gata'] ?? $address['Address1'] ?? $address['street'] ?? $address['adress'] ?? ''));
        $zip = trim((string) ($address['postnr'] ?? $address['ZipCode'] ?? $address['postnummer'] ?? $address['zip'] ?? ''));
        $city = trim((string) ($address['ort'] ?? $address['City'] ?? $address['city'] ?? $address['postort'] ?? ''));
        if ($street === '' && $zip === '' && $city === '') {
            return [];
        }
        $out = [];
        if ($street !== '') {
            $out['Address1'] = $street;
        }
        if ($zip !== '') {
            $out['ZipCode'] = $zip;
        }
        if ($city !== '') {
            $out['City'] = $city;
        }
        return $out;
    }

    private function addressFields(array $address): array
    {
        return self::wireAddressFields($address);
    }

    private function deliveryAddressFields(array $address): array
    {
        $base = $this->addressFields($address);
        if ($base === []) {
            return [];
        }
        $out = [];
        if (isset($base['Address1'])) {
            $out['DeliveryAddress1'] = $base['Address1'];
        }
        if (isset($base['ZipCode'])) {
            $out['DeliveryZipCode'] = $base['ZipCode'];
        }
        if (isset($base['City'])) {
            $out['DeliveryCity'] = $base['City'];
        }
        if (isset($base['Country'])) {
            $out['DeliveryCountry'] = $base['Country'];
        }
        return $out;
    }

    private function withOrderAddress(array $order, array $address): array
    {
        return array_merge($order, $this->addressFields($address), $this->deliveryAddressFields($address));
    }

    // Order JSON has Address1/Address2/Comments — no PropertyDesignation (Fortnox /3/orders, go-fortnox Order).
    // The husarbete UI field Fastighetsbeteckning/Lägenhetsnr is TaxReduction.PropertyDesignation.
    // Address2 is the extra street line, not that field. Customer-card Fastighetsbeteckning is not in the Customer API.
    private function requirePropertyDesignation(string $fastighetsbeteckning): string
    {
        $fastighetsbeteckning = trim($fastighetsbeteckning);
        if ($fastighetsbeteckning === '') {
            throw new Exception('Fastighetsbeteckning saknas.');
        }
        return $fastighetsbeteckning;
    }

    private function apiPathFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        if (str_starts_with($path, '/3/')) {
            $path = substr($path, 2);
        } elseif ($path === '/3') {
            $path = '/';
        }
        if ($path === '') {
            return '';
        }
        return $query !== '' ? $path . '?' . $query : $path;
    }

    private function taxReductionsFromResponse(array $data): array
    {
        $list = $data['TaxReductions'] ?? $data['TaxReduction'] ?? [];
        if ($list === []) {
            return [];
        }
        if (isset($list['Id']) || isset($list['id']) || isset($list['PropertyDesignation'])) {
            return [$list];
        }
        return is_array($list) ? $list : [];
    }

    private function listTaxReductionsForOrder(string $orderNumber, array $orderResponse): array
    {
        $order = is_array($orderResponse['Order'] ?? null) ? $orderResponse['Order'] : $orderResponse;
        $listUrl = trim((string) ($order['@urlTaxReductionList'] ?? ''));
        if ($listUrl !== '') {
            $path = $this->apiPathFromUrl($listUrl);
            if ($path !== '') {
                return $this->taxReductionsFromResponse($this->request('GET', $path));
            }
        }
        $matched = [];
        $page = 1;
        do {
            $data = $this->request('GET', '/taxreductions?filter=orders&limit=100&page=' . $page);
            foreach ($this->taxReductionsFromResponse($data) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (trim((string) ($row['ReferenceNumber'] ?? '')) === $orderNumber) {
                    $matched[] = $row;
                }
            }
            $meta = is_array($data['MetaInformation'] ?? null) ? $data['MetaInformation'] : [];
            $totalPages = (int) ($meta['@TotalPages'] ?? $meta['TotalPages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= 20);
        return $matched;
    }

    /**
     * @param list<array{WorkType?:string,AskedAmount?:int}> $amounts
     * @return array<string,int>
     */
    private static function taxAmountMap(array $amounts): array
    {
        $map = [];
        foreach (self::positiveTaxAmounts($amounts) as $amount) {
            $type = $amount['WorkType'];
            $map[$type] = ($map[$type] ?? 0) + $amount['AskedAmount'];
        }
        ksort($map);
        return $map;
    }

    /**
     * Sänk belopp som ligger över målet. En typ som målet inte har lämnas,
     * så Fortnox inte får en nollad arbetstyp.
     *
     * @param list<array{WorkType?:string,AskedAmount?:int}> $current
     * @param list<array{WorkType?:string,AskedAmount?:int}> $target
     * @return list<array{WorkType:string,AskedAmount:int}>
     */
    private static function taxAmountsCappedTo(array $current, array $target): array
    {
        $cur = self::taxAmountMap($current);
        $tgt = self::taxAmountMap($target);
        $out = [];
        foreach ($cur as $type => $asked) {
            $amount = array_key_exists($type, $tgt) ? min($asked, $tgt[$type]) : $asked;
            if ($amount > 0) {
                $out[] = ['WorkType' => $type, 'AskedAmount' => $amount];
            }
        }
        return $out;
    }

    /** @param list<array{WorkType:string,AskedAmount:int}> $amounts */
    private function putTaxReductionAmounts(array $taxRow, array $amounts): void
    {
        $id = trim((string) ($taxRow['Id'] ?? $taxRow['id'] ?? ''));
        if ($id === '' || $amounts === []) {
            return;
        }
        $body = ['TaxReductionAmounts' => array_values($amounts)];
        $designation = trim((string) ($taxRow['PropertyDesignation'] ?? ''));
        if ($designation !== '') {
            $body['PropertyDesignation'] = $designation;
        }
        $this->request('PUT', '/taxreductions/' . rawurlencode($id), [
            'TaxReduction' => $body,
        ]);
    }

    /**
     * Saknad medsökande på en order som redan har grön teknik.
     * Befintliga ansökningar sänks till sin nya andel innan någon ny
     * ansökan skapas, annars överskrider arbetstypen möjligt belopp.
     *
     * @param list<array<string,mixed>> $owners
     * @param list<array<string,mixed>> $existing
     * @param array<string,float> $prices
     */
    private function addMissingOwnerTaxReductions(array $owners, array $existing, string $fastighetsbeteckning, string $orderNumber, array $prices): int
    {
        [$hydrated] = $this->hydrateTaxReductions($existing);
        $eligible = [];
        $seen = [];
        foreach ($owners as $owner) {
            if (!is_array($owner)) {
                continue;
            }
            $name = trim((string) ($owner['name'] ?? ''));
            $pnr = trim((string) ($owner['personnummer'] ?? ''));
            if ($name === '' || $pnr === '') {
                continue;
            }
            $key = self::ownerSeenKey($name, $pnr);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $eligible[] = ['name' => $name, 'personnummer' => $pnr, 'key' => $key];
        }
        if ($eligible === []) {
            throw new Exception('Saknas person för skattereduktion.');
        }
        $n = count($eligible);
        $splits = self::greenTechSplits($prices, $n, self::greenTechPossible($prices, $n));
        $byKey = [];
        foreach ($hydrated as $taxRow) {
            if (!is_array($taxRow)) {
                continue;
            }
            $name = trim((string) ($taxRow['CustomerName'] ?? $taxRow['name'] ?? ''));
            $pnr = trim((string) ($taxRow['SocialSecurityNumber'] ?? $taxRow['personnummer'] ?? ''));
            if ($name === '' && $pnr === '') {
                continue;
            }
            $key = self::ownerSeenKey($name, $pnr);
            if (!isset($byKey[$key])) {
                $byKey[$key] = $taxRow;
            }
        }
        $raises = [];
        foreach ($eligible as $i => $owner) {
            $taxRow = $byKey[$owner['key']] ?? null;
            if (!is_array($taxRow)) {
                continue;
            }
            $target = self::positiveTaxAmounts(is_array($splits[$i] ?? null) ? $splits[$i] : []);
            $current = self::taxReductionAmountRows($taxRow);
            $lowered = self::taxAmountsCappedTo($current, $target);
            // En typ som finns på raderna men saknas hos personen får sin andel
            // direkt. Annars svarar Fortnox «får inte vara 0» för typen.
            $loweredMap = self::taxAmountMap($lowered);
            foreach (self::taxAmountMap($target) as $type => $amount) {
                if (!isset($loweredMap[$type]) && $amount > 0) {
                    $lowered[] = ['WorkType' => $type, 'AskedAmount' => $amount];
                }
            }
            if (self::taxAmountMap($current) !== self::taxAmountMap($lowered)) {
                $this->putTaxReductionAmounts($taxRow, $lowered);
            }
            if (self::taxAmountMap($lowered) !== self::taxAmountMap($target)) {
                $raises[] = [$taxRow, $target];
            }
        }
        $created = 0;
        foreach ($eligible as $i => $owner) {
            if (isset($byKey[$owner['key']])) {
                continue;
            }
            $target = self::positiveTaxAmounts(is_array($splits[$i] ?? null) ? $splits[$i] : []);
            if ($target === []) {
                continue;
            }
            try {
                $this->request('POST', '/taxreductions', [
                    'TaxReduction' => [
                        'CustomerName' => $owner['name'],
                        'SocialSecurityNumber' => $owner['personnummer'],
                        'PropertyDesignation' => $fastighetsbeteckning,
                        'ReferenceDocumentType' => 'ORDER',
                        'ReferenceNumber' => $orderNumber,
                        'TaxReductionAmounts' => $target,
                    ],
                ]);
            } catch (Throwable $e) {
                if (!$this->putRelistedTaxReductions($e->getMessage(), $orderNumber, $splits)) {
                    if (self::isTaxReductionMustUpdate($e->getMessage())) {
                        throw new Exception('Orderraderna är inte märkta för husarbete, så skattereduktionen kan inte skapas.');
                    }
                    throw $e;
                }
            }
            $created++;
        }
        foreach ($raises as [$taxRow, $target]) {
            if ($target === []) {
                continue;
            }
            $this->putTaxReductionAmounts($taxRow, $target);
        }
        if ($created === 0 && $hydrated === []) {
            throw new Exception('Kunde inte skapa skattereduktion med PropertyDesignation.');
        }
        return $created > 0 ? $created : count($hydrated);
    }

    private function attachTaxReductions(array $owners, string $fastighetsbeteckning, string $orderNumber, bool $forKop = false): int
    {
        $fastighetsbeteckning = $this->requirePropertyDesignation($fastighetsbeteckning);
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '') {
            throw new Exception('Ordernummer saknas.');
        }
        $order = $this->request('GET', '/orders/' . rawurlencode($orderNumber));
        $existing = $this->listTaxReductionsForOrder($orderNumber, $order);
        $touched = 0;
        $hasDesignation = false;
        $covered = [];
        foreach ($existing as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['Id'] ?? $row['id'] ?? ''));
            $current = trim((string) ($row['PropertyDesignation'] ?? ''));
            $coveredName = trim((string) ($row['CustomerName'] ?? $row['name'] ?? ''));
            $coveredPnr = trim((string) ($row['SocialSecurityNumber'] ?? $row['personnummer'] ?? ''));
            if ($coveredName !== '' || $coveredPnr !== '') {
                $covered[self::ownerSeenKey($coveredName, $coveredPnr)] = true;
            }
            if ($id !== '' && $current !== $fastighetsbeteckning) {
                $this->request('PUT', '/taxreductions/' . rawurlencode($id), [
                    'TaxReduction' => [
                        'PropertyDesignation' => $fastighetsbeteckning,
                    ],
                ]);
                $current = $fastighetsbeteckning;
                $touched++;
            }
            if ($current === $fastighetsbeteckning) {
                $hasDesignation = true;
            }
        }
        if (!$forKop) {
            if ($existing === []) {
                $prices = $this->extractHWPrices($order);
                $touched = $this->createTaxReductions($owners, $fastighetsbeteckning, $orderNumber, $prices);
                $hasDesignation = $touched > 0;
            }
            if (!$hasDesignation) {
                throw new Exception('TaxReduction.PropertyDesignation kunde inte sättas från fastighetsbeteckning.');
            }
            return $touched > 0 ? $touched : count($existing);
        }
        $missing = [];
        foreach ($owners as $owner) {
            if (!is_array($owner)) {
                continue;
            }
            $name = trim((string) ($owner['name'] ?? ''));
            $pnr = trim((string) ($owner['personnummer'] ?? ''));
            if ($name === '' && $pnr === '') {
                continue;
            }
            if ($name === '' || $pnr === '') {
                throw new Exception('Personnummer saknas för medsökande' . ($name !== '' ? ' (' . $name . ')' : '') . '.');
            }
            if (!isset($covered[self::ownerSeenKey($name, $pnr)])) {
                $missing[] = ['name' => $name, 'personnummer' => $pnr];
            }
        }
        if ($missing !== []) {
            $prices = $this->extractHWPrices($order);
            if ($existing !== []) {
                // Befintlig ansökan håller ofta hela det gamla beloppet.
                // Sänk den först, posta sedan den saknade andelen.
                $created = $this->addMissingOwnerTaxReductions($owners, $existing, $fastighetsbeteckning, $orderNumber, $prices);
            } else {
                $splitAmong = 0;
                foreach ($owners as $owner) {
                    if (!is_array($owner)) {
                        continue;
                    }
                    $name = trim((string) ($owner['name'] ?? ''));
                    $pnr = trim((string) ($owner['personnummer'] ?? ''));
                    if ($name !== '' && $pnr !== '') {
                        $splitAmong++;
                    }
                }
                $created = $this->createTaxReductions($missing, $fastighetsbeteckning, $orderNumber, $prices, $splitAmong);
            }
            $touched += $created;
            $hasDesignation = $hasDesignation || $created > 0;
        }
        if (!$hasDesignation) {
            throw new Exception('TaxReduction.PropertyDesignation kunde inte sättas från fastighetsbeteckning.');
        }
        return $touched > 0 ? $touched : count($existing);
    }

    /** @return array{count:int,error:string} */
    private function writeTaxReductionPropertyDesignation(array $owners, string $fastighetsbeteckning, string $orderNumber, bool $forKop = false): array
    {
        try {
            return [
                'count' => $this->attachTaxReductions($owners, $fastighetsbeteckning, $orderNumber, $forKop),
                'error' => '',
            ];
        } catch (Throwable $e) {
            $message = $e->getMessage();
            if ($orderNumber !== '' && !str_contains($message, $orderNumber)) {
                $message = 'Fortnox-order ' . $orderNumber . ': ' . $message;
            }
            appLog('tax_reduction_failed', [
                'account' => $this->account,
                'orderNumber' => $orderNumber,
                'error' => $message,
            ]);
            return [
                'count' => 0,
                'error' => $message,
            ];
        }
    }

    private function putCustomerAddress(string $number, array $address): void
    {
        $fields = $this->addressFields($address);
        if ($number === '' || $fields === []) {
            return;
        }
        $this->request('PUT', '/customers/' . rawurlencode($number), ['Customer' => $fields]);
    }

    private function createPrivateCustomer(string $name, string $pnr, string $email, string $phone, array $address = []): string
    {
        $customer = [
            'Name' => $name,
            'Type' => 'PRIVATE',
            'OrganisationNumber' => jeab_orgnr_normalize($pnr),
        ];
        if ($email !== '') {
            $customer['Email'] = $email;
        }
        if ($phone !== '') {
            $customer['Phone1'] = $phone;
        }
        $customer = array_merge($customer, $this->addressFields($address));
        $created = $this->request('POST', '/customers', ['Customer' => $customer]);
        return $this->customerNumber(is_array($created['Customer'] ?? null) ? $created['Customer'] : $created);
    }

    public function findOrCreateCustomer(array $owner, string $email, string $phone, array $address = [], array $skip = []): string
    {
        $probe = jeab_fortnox_test_lead_probe([
            'email' => $email,
            'phone' => $phone,
            'names' => [trim((string) ($owner['name'] ?? ''))],
        ]);
        if (!empty($probe['test'])) {
            jeab_fortnox_log_test_skip($probe);

            return 'TEST';
        }
        $pnr = trim((string) ($owner['personnummer'] ?? ''));
        $name = trim((string) ($owner['name'] ?? ''));
        try {
            $found = $this->searchCustomersByPnr($pnr);
        } catch (Exception $e) {
            if (!$this->isMissingOrInactiveError($e->getMessage())) {
                throw $e;
            }
            $found = [];
        }
        $active = $this->pickHighestActive($found, $skip);
        if ($active !== '') {
            try {
                $this->putCustomerAddress($active, $address);
            } catch (Throwable $e) {
                appLog('fortnox_customer_address_skipped', [
                    'account' => $this->account,
                    'customerNumber' => $active,
                    'error' => $e->getMessage(),
                ]);
            }
            return $active;
        }
        try {
            $number = $this->createPrivateCustomer($name, $pnr, $email, $phone, $address);
            if ($number !== '') {
                return $number;
            }
        } catch (Exception $e) {
            try {
                $found = $this->searchCustomersByPnr($pnr);
            } catch (Exception $searchError) {
                if (!$this->isMissingOrInactiveError($searchError->getMessage())) {
                    throw $e;
                }
                $found = [];
            }
            $again = $this->pickHighestActive($found, $skip);
            if ($again === '') {
                $again = $this->pickHighestExisting($found, $skip);
            }
            if ($again !== '') {
                try {
                    $this->putCustomerAddress($again, $address);
                } catch (Throwable $putError) {
                    appLog('fortnox_customer_address_skipped', [
                        'account' => $this->account,
                        'customerNumber' => $again,
                        'error' => $putError->getMessage(),
                    ]);
                }
                return $again;
            }
            throw $e;
        }
        throw new Exception('Fortnox skapade ingen kund');
    }

    public function createOrder(string $customerNumber, string $fastighetsbeteckning, string $customerName, array $address = []): string
    {
        $probe = jeab_fortnox_test_lead_probe([
            'names' => [$customerName],
            'fortnox_customer' => $customerNumber,
        ]);
        if (!empty($probe['test'])) {
            jeab_fortnox_log_test_skip($probe);

            return 'TEST';
        }
        $fastighetsbeteckning = $this->requirePropertyDesignation($fastighetsbeteckning);
        $hw = [
            'charging' => 'CHARGINGSTATIONELECTRICVEHICLE',
            'solar' => 'SOLARCELLS',
            'battery' => 'STORAGESELFPRODUCEDELECTRICITY',
        ];
        $rows = [];
        foreach ($hw as $key => $type) {
            $article = trim((string) ($this->articles[$key] ?? ''));
            if ($article === '') {
                continue;
            }
            $rows[] = [
                'ArticleNumber' => $article,
                'DeliveredQuantity' => 1,
                'HouseWork' => true,
                'HouseWorkType' => $type,
            ];
        }
        foreach (['material', 'labor'] as $key) {
            $article = trim((string) ($this->articles[$key] ?? ''));
            if ($article === '') {
                continue;
            }
            $rows[] = [
                'ArticleNumber' => $article,
                'DeliveredQuantity' => 1,
            ];
        }
        $order = [
            'CustomerNumber' => $customerNumber,
            'TaxReductionType' => 'green',
            'YourReference' => $customerName,
            'Comments' => $fastighetsbeteckning,
            'OrderRows' => $rows,
        ];
        $order = $this->withOrderAddress($order, $address);
        $created = $this->request('POST', '/orders', [
            'Order' => $order,
        ]);
        $number = trim((string) ($created['Order']['DocumentNumber'] ?? ''));
        if ($number === '') {
            throw new Exception('Fortnox skapade ingen order');
        }
        return $number;
    }

    public function kopOrderRow(string $kind, int $price): array
    {
        $rows = $this->kopOrderRows($kind, $price > 0 ? $price : 1, '');
        if ($rows === []) {
            throw new Exception('Välj Laddbox, Batteri eller Solceller.');
        }
        return $rows[0];
    }

    /** @param array<string,mixed> $logical */
    private function materializeKopRow(array $logical): array
    {
        $qty = (int) ($logical['qty'] ?? 1);
        if ($qty < 1) {
            $qty = 1;
        }
        $line = (int) ($logical['price'] ?? 0);
        $row = [
            'DeliveredQuantity' => $qty,
            'Price' => intdiv($line, $qty),
            'Description' => (string) ($logical['desc'] ?? ''),
        ];
        $articleKey = trim((string) ($logical['article'] ?? ''));
        $article = $articleKey !== '' ? trim((string) ($this->articles[$articleKey] ?? '')) : '';
        if ($article !== '') {
            $row['ArticleNumber'] = $article;
        } elseif ($articleKey !== '' && preg_match('/^\d+$/', $articleKey) === 1) {
            $row['ArticleNumber'] = $articleKey;
        }
        $hw = $logical['hw'] ?? null;
        if (is_string($hw) && $hw !== '') {
            $row['HouseWork'] = true;
            $row['HouseWorkType'] = $hw;
            $mapped = self::houseWorkHours($hw);
            $hours = $mapped > 0 ? $mapped : (int) ($logical['hours'] ?? 0);
            if ($hours > 0) {
                $row['HouseWorkHoursToReport'] = $hours;
            }
        }
        return $row;
    }

    /** @param array<string,mixed> $purchase */
    public function kopOrderRows(string $kind, int $price, string $installWhat = '', array $purchase = []): array
    {
        if (!function_exists('jeab_offert_fortnox_kop_rows')) {
            require_once dirname(__DIR__) . '/src/offert.php';
        }
        try {
            $logical = jeab_offert_fortnox_kop_rows($kind, $price, $installWhat, $purchase);
        } catch (InvalidArgumentException $e) {
            throw new Exception($e->getMessage());
        }
        return $this->kopRowsFromLogical($logical);
    }

    /**
     * Samma materialisering som kopOrderRows: artikelnyckel, antal, à-pris och
     * husarbete. Öresresten när à-pris × antal inte går jämnt upp stannar på
     * den raden: antal−1 behåller à-priset och en ny rad med antal 1 bär resten.
     * Resten flyttas inte till en annan rad. Husarbetstimmar ligger bara på
     * den första delen, så de inte rapporteras två gånger.
     *
     * @param list<array<string,mixed>> $logical
     * @return list<array<string,mixed>>
     */
    public function kopRowsFromLogical(array $logical): array
    {
        $out = [];
        foreach ($logical as $row) {
            if (!is_array($row)) {
                continue;
            }
            $built = $this->materializeKopRow($row);
            $qty = (int) ($built['DeliveredQuantity'] ?? 1);
            $unit = (int) ($built['Price'] ?? 0);
            $line = (int) ($row['price'] ?? 0);
            $remainder = $line - ($unit * $qty);
            if ($remainder !== 0 && $qty > 1) {
                $built['DeliveredQuantity'] = $qty - 1;
                $tail = $built;
                $tail['DeliveredQuantity'] = 1;
                $tail['Price'] = $unit + $remainder;
                unset($tail['HouseWorkHoursToReport']);
                $out[] = $built;
                $out[] = $tail;
                continue;
            }
            $out[] = $built;
        }
        return $out;
    }

    /**
     * Samma orderrad och skattereduktion som createKopOrder skickar.
     * Ingen träff mot Fortnox.
     *
     * @param array<string,mixed> $input
     * @return array{order:array<string,mixed>,owners:list<array<string,string>>,fastighet:string,total:int,deduction:list<array{applicant:int,amount:int}>,housework:list<array{applicant:int,amounts:list<array{type:string,amount:int}>}>,deduction_total:int,amount_to_pay:int}
     */
    public function kopExportPlan(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $pnr = trim((string) ($input['personnummer'] ?? ''));
        $kind = trim((string) ($input['kind'] ?? ''));
        $price = (int) ($input['price'] ?? $input['kundpris'] ?? 0);
        $installWhat = trim((string) ($input['install_what'] ?? ''));
        if ($name === '') {
            throw new Exception('Huvudsökande saknas.');
        }
        if ($pnr === '') {
            throw new Exception('Personnummer saknas.');
        }
        $fastighet = $this->requirePropertyDesignation((string) ($input['fastighetsbeteckning'] ?? ''));
        $rows = $this->kopOrderRows($kind, $price, $installWhat, $input);
        $address = $input['address'] ?? [];
        if (!is_array($address)) {
            $address = self::collectAddress(['address' => (string) $address]);
        } else {
            $address = self::collectAddress($address) ?: $address;
        }
        if ($address === []) {
            $address = self::collectAddressFromOwners($input);
        }
        $owners = self::requireOwners($input);
        if ($owners === []) {
            $owners = [['name' => $name, 'personnummer' => $pnr]];
        }
        $order = [
            'TaxReductionType' => 'green',
            'YourReference' => $name,
            'Comments' => $fastighet,
            'OrderRows' => $rows,
        ];
        $customerNumber = trim((string) ($input['fortnox_customer'] ?? ''));
        if ($customerNumber !== '') {
            $order['CustomerNumber'] = $customerNumber;
        }
        $delivery = jeab_fortnox_delivery_ymd((string) ($input['delivery_date'] ?? ''));
        if ($delivery !== '') {
            $order['DeliveryDate'] = $delivery;
        }
        $order = $this->withOrderAddress($order, $address);
        $people = max(1, count($owners));
        $bases = self::houseworkBases($rows);
        $perType = self::greenTechClampToPeopleCap(self::greenTechPossibleByType($bases), $people);
        $splits = self::greenTechSplitsFromTypes($perType, $people);
        $deduction = [];
        $housework = [];
        $deductionTotal = 0;
        foreach ($splits as $index => $amounts) {
            $sum = 0;
            $lines = [];
            if (is_array($amounts)) {
                foreach ($amounts as $amount) {
                    if (!is_array($amount)) {
                        continue;
                    }
                    $asked = (int) ($amount['AskedAmount'] ?? 0);
                    $sum += $asked;
                    if ($asked > 0) {
                        $lines[] = [
                            'type' => (string) ($amount['WorkType'] ?? ''),
                            'amount' => $asked,
                        ];
                    }
                }
            }
            $deduction[] = ['applicant' => $index + 1, 'amount' => $sum];
            $housework[] = ['applicant' => $index + 1, 'amounts' => $lines];
            $deductionTotal += $sum;
        }
        $total = 0;
        foreach ($rows as $line) {
            $qty = (int) ($line['DeliveredQuantity'] ?? 1);
            if ($qty < 1) {
                $qty = 1;
            }
            $total += (int) ($line['Price'] ?? 0) * $qty;
        }
        self::assertGreenTechPayload($input, $rows, $deductionTotal);
        return [
            'order' => $order,
            'owners' => $owners,
            'fastighet' => $fastighet,
            'total' => $total,
            'deduction' => $deduction,
            'housework' => $housework,
            'deduction_total' => $deductionTotal,
            'amount_to_pay' => max(0, $total - $deductionTotal),
        ];
    }

    /**
     * Grön teknik på ordern kräver husarbete och ett avdrag i underlaget.
     * Annars skapas ingen faktura.
     *
     * @param array<string,mixed> $input
     * @param list<array<string,mixed>> $rows
     */
    public static function assertGreenTechPayload(array $input, array $rows, ?int $deductionTotal = null): void
    {
        $kind = trim((string) ($input['kind'] ?? ''));
        $price = (int) ($input['price'] ?? $input['kundpris'] ?? 0);
        if (!in_array($kind, ['laddbox', 'batteri', 'sol'], true) || $price <= 0) {
            return;
        }
        $hasHouseWork = false;
        foreach ($rows as $line) {
            if (!is_array($line)) {
                continue;
            }
            $type = trim((string) ($line['HouseWorkType'] ?? ''));
            if ($type !== '' && !self::houseworkRowSkipped($line)) {
                $hasHouseWork = true;
                break;
            }
        }
        if ($deductionTotal === null) {
            $people = 1;
            $owners = $input['owners'] ?? [];
            if (is_array($owners) && $owners !== []) {
                $people = max(1, count($owners));
            }
            $deductionTotal = self::greenTechPossible(self::houseworkBases($rows), $people);
        }
        if (!$hasHouseWork || $deductionTotal <= 0) {
            throw new InvalidArgumentException('Ordern har grön teknik men underlaget saknar skattereduktion.');
        }
    }

    public function cancelOrder(string $orderNumber): array
    {
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '') {
            throw new Exception('Ordernummer saknas.');
        }
        return $this->request('PUT', '/orders/' . rawurlencode($orderNumber) . '/cancel');
    }

    public function createKopOrder(array $input): array
    {
        jeab_fortnox_strict_throw($input);
        // Testspärren prövas på både radens kopia och kontakten: test om någon av dem är test.
        $rawInput = $input;
        $input = jeab_fortnox_sole_customer_input($input);
        $probeRaw = jeab_fortnox_input_is_test($rawInput);
        $probe = jeab_fortnox_input_is_test($input);
        if (empty($probe['test']) && !empty($probeRaw['test'])) {
            $probe = $probeRaw;
        }
        if (!empty($probe['test'])) {
            jeab_fortnox_log_test_skip($probe);

            return jeab_fortnox_test_order_result();
        }
        $kind = trim((string) ($input['kind'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $pnr = trim((string) ($input['personnummer'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $fastighet = trim((string) ($input['fastighetsbeteckning'] ?? ''));
        $price = (int) ($input['price'] ?? $input['kundpris'] ?? 0);
        $installWhat = trim((string) ($input['install_what'] ?? ''));
        if ($name === '') {
            throw new Exception('Huvudsökande saknas.');
        }
        if ($pnr === '') {
            throw new Exception('Personnummer saknas.');
        }
        $plan = $this->kopExportPlan($input);
        $owners = $plan['owners'];
        $fastighet = $plan['fastighet'];
        $owner = ['name' => $name, 'personnummer' => $pnr];
        $address = $input['address'] ?? [];
        if (!is_array($address)) {
            $address = self::collectAddress(['address' => (string) $address]);
        } else {
            $address = self::collectAddress($address) ?: $address;
        }
        if ($address === []) {
            $address = self::collectAddressFromOwners($input);
        }
        $customerNumber = $this->findOrCreateCustomer($owner, $email, $phone, $address);
        $order = $plan['order'];
        $order['CustomerNumber'] = $customerNumber;
        $payload = [
            'Order' => $order,
        ];
        try {
            $created = $this->request('POST', '/orders', $payload);
        } catch (Exception $e) {
            if (!$this->isMissingOrInactiveError($e->getMessage())) {
                throw $e;
            }
            $skip = [$customerNumber => true];
            $next = $this->findOrCreateCustomer($owner, $email, $phone, $address, $skip);
            if ($next === '' || $next === $customerNumber) {
                $next = $this->createPrivateCustomer($name, $pnr, $email, $phone, $address);
            }
            if ($next === '' || $next === $customerNumber) {
                throw $e;
            }
            $customerNumber = $next;
            $payload['Order']['CustomerNumber'] = $customerNumber;
            $created = $this->request('POST', '/orders', $payload);
        }
        $number = trim((string) ($created['Order']['DocumentNumber'] ?? ''));
        if ($number === '') {
            throw new Exception('Fortnox skapade ingen order');
        }
        $tax = $this->writeTaxReductionPropertyDesignation($owners, $fastighet, $number, true);
        return [
            'customerNumber' => $customerNumber,
            'orderNumber' => $number,
            'taxReductions' => $tax['count'],
            'taxReductionError' => $tax['error'],
        ];
    }

    public function updateKopOrder(string $orderNumber, array $input): array
    {
        jeab_fortnox_strict_throw($input);
        // Testspärren prövas på både radens kopia och kontakten: test om någon av dem är test.
        $rawInput = $input;
        $input = jeab_fortnox_sole_customer_input($input);
        $probeRaw = jeab_fortnox_input_is_test($rawInput, $orderNumber);
        $probe = jeab_fortnox_input_is_test($input, $orderNumber);
        if (empty($probe['test']) && !empty($probeRaw['test'])) {
            $probe = $probeRaw;
        }
        if (!empty($probe['test'])) {
            jeab_fortnox_log_test_skip($probe);

            return jeab_fortnox_test_order_result();
        }
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '') {
            throw new Exception('Ordernummer saknas.');
        }
        $kind = trim((string) ($input['kind'] ?? ''));
        $price = (int) ($input['price'] ?? $input['kundpris'] ?? 0);
        $installWhat = trim((string) ($input['install_what'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $pnr = trim((string) ($input['personnummer'] ?? ''));
        $fastighet = $this->requirePropertyDesignation((string) ($input['fastighetsbeteckning'] ?? ''));
        $rows = $this->kopOrderRows($kind, $price, $installWhat, $input);
        self::assertGreenTechPayload($input, $rows);
        $order = [
            'OrderRows' => $rows,
            'Comments' => $fastighet,
        ];
        if ($name !== '') {
            $order['YourReference'] = $name;
        }
        $customerNumber = trim((string) ($input['fortnox_customer'] ?? ''));
        $address = $input['address'] ?? [];
        if (!is_array($address)) {
            $address = self::collectAddress(['address' => (string) $address]);
        } else {
            $address = self::collectAddress($address) ?: $address;
        }
        if ($name !== '' && $pnr !== '') {
            $email = trim((string) ($input['email'] ?? ''));
            $phone = trim((string) ($input['phone'] ?? ''));
            $customerNumber = $this->findOrCreateCustomer([
                'name' => $name,
                'personnummer' => $pnr,
            ], $email, $phone, $address);
            if ($customerNumber !== '') {
                $order['CustomerNumber'] = $customerNumber;
            }
        }
        $owners = self::requireOwners($input);
        if ($owners === [] && $name !== '' && $pnr !== '') {
            $owners = [['name' => $name, 'personnummer' => $pnr]];
        }
        $order['TaxReductionType'] = 'green';
        $delivery = jeab_fortnox_delivery_ymd((string) ($input['delivery_date'] ?? ''));
        if ($delivery !== '') {
            $order['DeliveryDate'] = $delivery;
        }
        $order = $this->withOrderAddress($order, $address);
        // Ny grön teknik-typ på en order med ansökan: samma omskrivning som Fakturering.
        // Fastighetsbeteckningen följer med även när Fortnox-ordern saknar ansökan och kommentar.
        $rewrite = $this->rewriteRowsWithNewGreenTechTypes($orderNumber, $rows, $owners, array_diff_key($order, ['OrderRows' => true]), $fastighet);
        if (!empty($rewrite['applies']) && is_array($rewrite['order'] ?? null)) {
            $updated = $rewrite['order'];
        } else {
            $updated = $this->request('PUT', '/orders/' . rawurlencode($orderNumber), [
                'Order' => $order,
            ]);
        }
        $number = trim((string) ($updated['Order']['DocumentNumber'] ?? $orderNumber));
        $customerNumber = trim((string) ($updated['Order']['CustomerNumber'] ?? $customerNumber));
        $tax = $this->writeTaxReductionPropertyDesignation($owners, $fastighet, $number !== '' ? $number : $orderNumber, true);
        return [
            'customerNumber' => $customerNumber,
            'orderNumber' => $number !== '' ? $number : $orderNumber,
            'taxReductions' => $tax['count'],
            'taxReductionError' => $tax['error'],
            'updated' => true,
        ];
    }

    /** Grön teknik-satser. Samma tabell som när ansökan skapas. */
    public static function greenTechRates(): array
    {
        return [
            'CHARGINGSTATIONELECTRICVEHICLE' => 0.50,
            'SOLARCELLS' => 0.15,
            'STORAGESELFPRODUCEDELECTRICITY' => 0.50,
        ];
    }

    /**
     * Skatteverkets tak för grön teknik per person och år.
     * Samma siffra som Offert ($gtCap = sökande × taket).
     */
    public static function greenTechPersonCap(): int
    {
        return 50000;
    }

    /** Totalt tak för ansökan: personer × taket per person. */
    public static function greenTechPeopleCap(int $people): int
    {
        return max(1, $people) * self::greenTechPersonCap();
    }

    /**
     * Klampa belopp per arbetstyp så summan inte går över person-taket.
     * Skär från slutet av satstabellen (lagring → sol → laddbox), samma
     * ordning som när greenTechSplits sänker ett för högt mål.
     *
     * @param array<string,int> $perType
     * @return array<string,int>
     */
    public static function greenTechClampToPeopleCap(array $perType, int $people): array
    {
        $cap = self::greenTechPeopleCap($people);
        $ordered = [];
        foreach (self::greenTechRates() as $type => $_rate) {
            $full = (int) ($perType[$type] ?? 0);
            if ($full > 0) {
                $ordered[$type] = $full;
            }
        }
        $sum = (int) array_sum($ordered);
        if ($sum <= $cap) {
            return $ordered;
        }
        $left = $sum - $cap;
        $types = array_keys($ordered);
        for ($i = count($types) - 1; $i >= 0 && $left > 0; $i--) {
            $type = $types[$i];
            $cut = min($ordered[$type], $left);
            $ordered[$type] -= $cut;
            $left -= $cut;
            if ($ordered[$type] <= 0) {
                unset($ordered[$type]);
            }
        }
        return $ordered;
    }

    /**
     * Underlag per husarbete-typ. Total från Fortnox när den inte ligger över
     * à-pris × antal (rabatt). En högre Total är moms eller ett gammalt
     * radbelopp och får inte höja en typ, till exempel Solceller, över det
     * belopp Fortnox räknar som möjligt. Rader med husarbete avstängt räknas
     * inte, även om typen fortfarande står kvar.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,float>
     */
    public static function houseworkBases(array $rows): array
    {
        $prices = [
            'CHARGINGSTATIONELECTRICVEHICLE' => 0.0,
            'SOLARCELLS' => 0.0,
            'STORAGESELFPRODUCEDELECTRICITY' => 0.0,
        ];
        foreach ($rows as $row) {
            if (!is_array($row) || self::houseworkRowSkipped($row)) {
                continue;
            }
            $type = (string) ($row['HouseWorkType'] ?? '');
            if (!isset($prices[$type])) {
                continue;
            }
            $prices[$type] += self::houseworkRowAmount($row);
        }
        return $prices;
    }

    private static function houseworkRowSkipped(array $row): bool
    {
        if (!array_key_exists('HouseWork', $row)) {
            return false;
        }
        $flag = $row['HouseWork'];
        if ($flag === false || $flag === 0 || $flag === 0.0) {
            return true;
        }
        if (!is_string($flag)) {
            return false;
        }
        $flag = strtolower(trim($flag));
        return $flag === '0' || $flag === 'false' || $flag === 'no';
    }

    private static function houseworkRowAmount(array $row): float
    {
        $priced = self::houseworkPriceQuantity($row);
        if (isset($row['Total']) && is_numeric($row['Total'])) {
            $total = (float) $row['Total'];
            if ($priced === null || $total <= $priced + 1.0) {
                return $total;
            }
            return $priced;
        }
        return $priced ?? 0.0;
    }

    private static function houseworkPriceQuantity(array $row): ?float
    {
        if (!isset($row['Price']) || !is_numeric($row['Price'])) {
            return null;
        }
        $price = (float) $row['Price'];
        $qtyRaw = $row['DeliveredQuantity'] ?? $row['OrderedQuantity'] ?? 1;
        if (is_string($qtyRaw)) {
            $qtyRaw = str_replace(',', '.', trim($qtyRaw));
        }
        $qty = is_numeric($qtyRaw) ? (float) $qtyRaw : 1.0;
        if ($qty <= 0) {
            $qty = 1.0;
        }
        return $price * $qty;
    }

    /**
     * Möjligt belopp per arbetstyp: floor(underlag × sats). Typer utan underlag utelämnas.
     *
     * @param array<string,float> $bases
     * @return array<string,int>
     */
    public static function greenTechPossibleByType(array $bases): array
    {
        $out = [];
        foreach (self::greenTechRates() as $type => $rate) {
            $base = (float) ($bases[$type] ?? 0);
            if ($base <= 0) {
                continue;
            }
            $full = (int) floor($base * $rate);
            if ($full > 0) {
                $out[$type] = $full;
            }
        }
        return $out;
    }

    /**
     * Möjlig skattereduktion: summan av floor(underlag × sats) per typ,
     * högst personer × 50 000 kr. Utan $people räknas en person.
     */
    public static function greenTechPossible(array $bases, int $people = 1): int
    {
        $sum = (int) array_sum(self::greenTechPossibleByType($bases));
        return min($sum, self::greenTechPeopleCap($people));
    }

    public static function taxReductionAskedSum(array $row): int
    {
        $amounts = $row['TaxReductionAmounts'] ?? $row['taxReductionAmounts'] ?? [];
        if (is_array($amounts) && (isset($amounts['AskedAmount']) || isset($amounts['WorkType']))) {
            $amounts = [$amounts];
        }
        if (!is_array($amounts)) {
            return 0;
        }
        $sum = 0;
        foreach ($amounts as $amount) {
            if (!is_array($amount) || !is_numeric($amount['AskedAmount'] ?? null)) {
                continue;
            }
            $sum += (int) round((float) $amount['AskedAmount']);
        }
        return $sum;
    }

    /**
     * Fördela målbeloppet per person och arbetstyp.
     * Ingen typ får AskedAmount över floor(underlag × sats). Är målet högre
     * än summan av de taken klampas det dit. Överskottet läggs inte på
     * första typen (Solceller när laddbox saknas).
     *
     * @param array<string,float> $bases
     * @return list<list<array{WorkType:string,AskedAmount:int}>>
     */
    public static function greenTechSplits(array $bases, int $people, int $targetTotal): array
    {
        $people = max(1, $people);
        $perType = self::greenTechClampToPeopleCap(self::greenTechPossibleByType($bases), $people);
        $sum = (int) array_sum($perType);
        if ($targetTotal < 0) {
            $targetTotal = 0;
        }
        if ($targetTotal > $sum) {
            $targetTotal = $sum;
        }
        if ($sum > $targetTotal) {
            $left = $sum - $targetTotal;
            $types = array_keys($perType);
            for ($i = count($types) - 1; $i >= 0 && $left > 0; $i--) {
                $type = $types[$i];
                $cut = min($perType[$type], $left);
                $perType[$type] -= $cut;
                $left -= $cut;
                if ($perType[$type] <= 0) {
                    unset($perType[$type]);
                }
            }
        }
        return self::greenTechSplitsFromTypes($perType, $people);
    }

    /**
     * Fördela färdiga belopp per arbetstyp på personerna. Resten hamnar på sista personen.
     *
     * @param array<string,int> $perType
     * @return list<list<array{WorkType:string,AskedAmount:int}>>
     */
    public static function greenTechSplitsFromTypes(array $perType, int $people): array
    {
        $people = max(1, $people);
        $ordered = self::greenTechClampToPeopleCap($perType, $people);
        $splits = [];
        for ($i = 0; $i < $people; $i++) {
            $splits[$i] = [];
        }
        foreach ($ordered as $type => $full) {
            $share = intdiv($full, $people);
            $rem = $full - $share * $people;
            for ($i = 0; $i < $people; $i++) {
                $amount = $share + ($i === $people - 1 ? $rem : 0);
                if ($amount <= 0) {
                    continue;
                }
                $splits[$i][] = ['WorkType' => $type, 'AskedAmount' => $amount];
            }
        }
        return $splits;
    }

    /**
     * Ansökt belopp per arbetstyp, summerat över personerna.
     *
     * @param list<array<string,mixed>> $hydrated
     * @return array<string,int>
     */
    public static function greenTechAskedByType(array $hydrated): array
    {
        $out = [];
        foreach ($hydrated as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (self::taxReductionAmountRows($row) as $amount) {
                $out[$amount['WorkType']] = ($out[$amount['WorkType']] ?? 0) + $amount['AskedAmount'];
            }
        }
        return $out;
    }

    /**
     * Belopp som får skrivas medan Fortnox fortfarande har de gamla raderna.
     * En typ får bara sänkas till det som både gammalt och nytt underlag rymmer.
     * Höjning väntar tills de nya raderna är sparade, annars svarar Fortnox att
     * ansökan överskrider möjligt belopp för den typen.
     * En typ som försvinner kan inte utelämnas än: «den totala ansökta summan
     * för en typ får inte vara 0» så länge raden finns. Den parkeras på en
     * krona per person, och samma summa tas från en typ som blir kvar, så att
     * orderraderna rymmer totalsumman. Efter radskrivningen tar synken bort
     * kronan.
     *
     * @param array<string,float> $beforeBases
     * @param array<string,float> $afterBases
     * @param array<string,int> $askedByType
     * @return array{update:bool,per_type:array<string,int>,asked:int}
     */
    public static function greenTechHoldBeforeRows(array $beforeBases, array $afterBases, array $askedByType, int $people = 1): array
    {
        $people = max(1, $people);
        $old = self::greenTechPossibleByType($beforeBases);
        $new = self::greenTechPossibleByType($afterBases);
        $perType = [];
        $parked = [];
        $update = false;
        $types = array_values(array_unique(array_merge(array_keys($askedByType), array_keys($old), array_keys($new))));
        foreach ($types as $type) {
            if (!isset(self::greenTechRates()[$type])) {
                continue;
            }
            $asked = max(0, (int) ($askedByType[$type] ?? 0));
            $oldCap = (int) ($old[$type] ?? 0);
            $newCap = (int) ($new[$type] ?? 0);
            if ($newCap <= 0 && $asked > 0 && $oldCap > 0) {
                $keep = min($people, $oldCap);
                if ($keep > 0) {
                    $parked[$type] = $keep;
                    if ($asked !== $keep) {
                        $update = true;
                    }
                    continue;
                }
            }
            // Gammalt underlag 0 betyder att raden inte räknades (husarbete av).
            // De nya raderna rymmer typen, så befintligt belopp får ligga kvar.
            // min(0, nytt) skulle nollställa typen och Fortnox svarar «får inte vara 0».
            $ceiling = $newCap;
            if ($oldCap > 0) {
                $ceiling = min($ceiling, $oldCap);
            }
            $safe = min($asked, $ceiling);
            if ($safe !== $asked) {
                $update = true;
            }
            if ($safe > 0) {
                $perType[$type] = $safe;
            }
        }
        if ($parked !== []) {
            $need = (int) array_sum($perType) + (int) array_sum($parked) - (int) array_sum($new);
            $released = $need > 0 ? self::greenTechReleaseForPark($perType, $need) : $perType;
            if ($released === null) {
                $update = true;
            } else {
                $perType = $released;
                foreach ($parked as $type => $amount) {
                    $perType[$type] = $amount;
                }
                $update = true;
            }
        }
        $beforeCap = (int) array_sum($perType);
        $perType = self::greenTechClampToPeopleCap($perType, $people);
        if ((int) array_sum($perType) !== $beforeCap) {
            $update = true;
        }
        return [
            'update' => $update,
            'per_type' => $perType,
            'asked' => (int) array_sum($perType),
        ];
    }

    /**
     * Frigör kronor från typer som blir kvar, utan att nolla någon av dem.
     * Null när det inte finns tillräckligt att ta av.
     *
     * @param array<string,int> $perType
     * @return array<string,int>|null
     */
    private static function greenTechReleaseForPark(array $perType, int $need): ?array
    {
        if ($need <= 0) {
            return $perType;
        }
        $order = array_reverse(array_keys(self::greenTechRates()));
        foreach ($order as $type) {
            if ($need <= 0) {
                break;
            }
            $current = (int) ($perType[$type] ?? 0);
            if ($current <= 1) {
                continue;
            }
            $cut = min($need, $current - 1);
            $perType[$type] = $current - $cut;
            $need -= $cut;
        }
        return $need > 0 ? null : $perType;
    }

    /**
     * Arbetstyper som orderraderna rymmer men som ansökan saknar eller bara
     * har en parkerad krona per person på. Det är inget tak användaren satt:
     * Fortnox godtar inte 0 för en typ som finns på raderna. Typen fylls till
     * hela möjliga beloppet, övriga typer behåller sitt ansökta belopp.
     *
     * @param array<string,float> $afterBases
     * @param array<string,int> $askedByType
     * @return array<string,int>|null null när ingen typ saknas
     */
    public static function greenTechFillMissingTypes(array $afterBases, array $askedByType, int $people = 1): ?array
    {
        $people = max(1, $people);
        $possible = self::greenTechPossibleByType($afterBases);
        $missing = false;
        $perType = [];
        foreach ($possible as $type => $cap) {
            $asked = max(0, (int) ($askedByType[$type] ?? 0));
            if ($asked <= $people && $cap > $asked) {
                $missing = true;
                $perType[$type] = $cap;
                continue;
            }
            if ($asked > 0) {
                $perType[$type] = min($asked, $cap);
            }
        }
        if (!$missing) {
            return null;
        }
        return self::greenTechClampToPeopleCap($perType, $people);
    }

    /**
     * Arbetstyper som fortfarande är ansökta men som de nya raderna inte
     * längre är märkta för. Nollpris på en kvarvarande typ räknas inte —
     * då ska ansökan behållas, inte tvingas mot DELETE.
     *
     * @param list<array<string,mixed>> $hydrated
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    public static function greenTechLingeringTypes(array $hydrated, array $rows): array
    {
        $present = [];
        foreach ($rows as $row) {
            if (!is_array($row) || self::houseworkRowSkipped($row)) {
                continue;
            }
            $type = (string) ($row['HouseWorkType'] ?? '');
            if (isset(self::greenTechRates()[$type])) {
                $present[$type] = true;
            }
        }
        $out = [];
        foreach (self::greenTechAskedByType($hydrated) as $type => $amount) {
            if ($amount > 0 && !isset($present[$type])) {
                $out[] = $type;
            }
        }
        return $out;
    }

    /**
     * Arbetstyp som Fortnox nämner i «överskrider möjligt belopp för den typen (Solceller)».
     */
    public static function workTypeFromTooHighMessage(string $message): ?string
    {
        $folded = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
        $needles = [
            'SOLARCELLS' => 'solcell',
            'STORAGESELFPRODUCEDELECTRICITY' => 'lagring',
            'CHARGINGSTATIONELECTRICVEHICLE' => 'laddning',
        ];
        foreach ($needles as $type => $needle) {
            if (str_contains($folded, $needle)) {
                return $type;
            }
        }
        $upper = strtoupper($message);
        foreach (array_keys(self::greenTechRates()) as $type) {
            if (str_contains($upper, $type)) {
                return $type;
            }
        }
        return null;
    }

    /**
     * @param list<list<array<string,mixed>>> $splits
     * @return array<string,int>
     */
    public static function greenTechPerTypeFromSplits(array $splits): array
    {
        $per = [];
        foreach ($splits as $person) {
            if (!is_array($person)) {
                continue;
            }
            foreach (self::positiveTaxAmounts($person) as $amount) {
                $per[$amount['WorkType']] = ($per[$amount['WorkType']] ?? 0) + $amount['AskedAmount'];
            }
        }
        return $per;
    }

    /**
     * Sänk en krona per person på typen Fortnox pekar ut. Utan typnamn sänks sista typen.
     *
     * @param array<string,int> $perType
     * @return array<string,int>
     */
    public static function shrinkGreenTechTypes(array $perType, string $message, int $people): array
    {
        $cut = max(1, $people);
        $named = self::workTypeFromTooHighMessage($message);
        $order = array_keys(self::greenTechRates());
        if ($named !== null && (int) ($perType[$named] ?? 0) > 0) {
            $order = [$named];
        } else {
            $order = array_reverse($order);
        }
        foreach ($order as $type) {
            $current = (int) ($perType[$type] ?? 0);
            if ($current <= 0) {
                continue;
            }
            $next = $current - $cut;
            if ($next > 0) {
                $perType[$type] = $next;
            } else {
                unset($perType[$type]);
            }
            return $perType;
        }
        return $perType;
    }

    /**
     * Belopp som får skickas till Fortnox. Nollrader tas bort: en typ som
     * försvunnit ska utelämnas, inte skickas som AskedAmount 0.
     *
     * @param list<array<string,mixed>> $amounts
     * @return list<array{WorkType:string,AskedAmount:int}>
     */
    public static function positiveTaxAmounts(array $amounts): array
    {
        $out = [];
        foreach ($amounts as $amount) {
            if (!is_array($amount)) {
                continue;
            }
            $type = strtoupper(trim((string) ($amount['WorkType'] ?? $amount['workType'] ?? '')));
            $raw = $amount['AskedAmount'] ?? $amount['askedAmount'] ?? null;
            if ($type === '' || !is_numeric($raw)) {
                continue;
            }
            $asked = (int) round((float) $raw);
            if ($asked <= 0) {
                continue;
            }
            $out[] = ['WorkType' => $type, 'AskedAmount' => $asked];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return list<array{WorkType:string,AskedAmount:int}>
     */
    public static function taxReductionAmountRows(array $row): array
    {
        $amounts = $row['TaxReductionAmounts'] ?? $row['taxReductionAmounts'] ?? [];
        if (is_array($amounts) && (isset($amounts['AskedAmount']) || isset($amounts['WorkType']) || isset($amounts['askedAmount']) || isset($amounts['workType']))) {
            $amounts = [$amounts];
        }
        return is_array($amounts) ? self::positiveTaxAmounts($amounts) : [];
    }

    /**
     * Arbetstyper som fanns på ansökan och som de nya beloppen inte längre har.
     * Fortnox godtar inte att den totala summan för en sådan typ blir 0.
     *
     * @param list<array<string,mixed>> $hydrated
     * @param list<list<array<string,mixed>>> $splits
     * @return list<string>
     */
    public static function greenTechDroppedTypes(array $hydrated, array $splits): array
    {
        $before = [];
        foreach ($hydrated as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (self::taxReductionAmountRows($row) as $amount) {
                $before[$amount['WorkType']] = ($before[$amount['WorkType']] ?? 0) + $amount['AskedAmount'];
            }
        }
        $after = [];
        foreach ($splits as $person) {
            if (!is_array($person)) {
                continue;
            }
            foreach (self::positiveTaxAmounts($person) as $amount) {
                $after[$amount['WorkType']] = ($after[$amount['WorkType']] ?? 0) + $amount['AskedAmount'];
            }
        }
        $dropped = [];
        foreach ($before as $type => $sum) {
            if ($sum > 0 && ($after[$type] ?? 0) <= 0) {
                $dropped[] = $type;
            }
        }
        return $dropped;
    }

    /**
     * Hur ansökan ska skrivas. replace betyder att en typ försvunnit: den
     * gamla ansökan tas bort och skrivs om utan nollrad för den typen.
     *
     * @param list<array<string,mixed>> $hydrated
     * @param list<list<array<string,mixed>>> $splits
     * @return array{replace:bool,amounts:list<list<array{WorkType:string,AskedAmount:int}>>}
     */
    public static function greenTechAmountWrite(array $hydrated, array $splits): array
    {
        $people = max(count($hydrated), count($splits), 1);
        $amounts = [];
        for ($i = 0; $i < $people; $i++) {
            $person = $splits[$i] ?? [];
            $amounts[] = self::positiveTaxAmounts(is_array($person) ? $person : []);
        }
        return [
            'replace' => self::greenTechDroppedTypes($hydrated, $splits) !== [],
            'amounts' => $amounts,
        ];
    }

    private function extractHWPrices(array $order): array
    {
        $rows = $order['Order']['OrderRows'] ?? $order['OrderRows'] ?? [];
        if (!is_array($rows)) {
            return self::houseworkBases([]);
        }
        return self::houseworkBases($rows);
    }

    public function createTaxReductions(array $owners, string $fastighetsbeteckning, string $orderNumber, array $prices, ?int $splitAmong = null, string $documentType = 'ORDER'): int
    {
        $fastighetsbeteckning = $this->requirePropertyDesignation($fastighetsbeteckning);
        if (trim($orderNumber) === '') {
            throw new Exception('Ordernummer saknas.');
        }
        $eligible = [];
        $seen = [];
        foreach ($owners as $owner) {
            if (!is_array($owner)) {
                continue;
            }
            $name = trim((string) ($owner['name'] ?? ''));
            $pnr = trim((string) ($owner['personnummer'] ?? ''));
            if ($name === '' || $pnr === '') {
                continue;
            }
            $fold = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
            $digits = preg_replace('/\D+/', '', $pnr) ?? '';
            if (strlen($digits) === 12) {
                $digits = substr($digits, 2);
            }
            $key = $digits . '|' . $fold;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $eligible[] = ['name' => $name, 'personnummer' => $pnr];
        }
        if ($eligible === []) {
            throw new Exception('Saknas person för skattereduktion.');
        }
        $n = $splitAmong !== null && $splitAmong > 0 ? $splitAmong : count($eligible);
        $n = max(1, $n);
        $perType = self::greenTechClampToPeopleCap(self::greenTechPossibleByType($prices), $n);
        $splits = self::greenTechSplitsFromTypes($perType, $n);
        $created = 0;
        foreach ($eligible as $index => $owner) {
            $amounts = is_array($splits[$index] ?? null) ? $splits[$index] : [];
            if ($amounts === []) {
                continue;
            }
            try {
                $this->request('POST', '/taxreductions', [
                    'TaxReduction' => [
                        // TypeOfReduction omitted (live): Fortnox rejects GREEN here.
                        'CustomerName' => $owner['name'],
                        'SocialSecurityNumber' => $owner['personnummer'],
                        'PropertyDesignation' => $fastighetsbeteckning,
                        'ReferenceDocumentType' => $documentType === 'INVOICE' ? 'INVOICE' : 'ORDER',
                        'ReferenceNumber' => $orderNumber,
                        'TaxReductionAmounts' => $amounts,
                    ],
                ]);
            } catch (Throwable $e) {
                $pad = [];
                for ($padAt = 0; $padAt < $created; $padAt++) {
                    $pad[] = [];
                }
                $pad[] = $amounts;
                if (!$this->putRelistedTaxReductions($e->getMessage(), $orderNumber, $pad)) {
                    if (self::isTaxReductionMustUpdate($e->getMessage())) {
                        throw new Exception('Orderraderna är inte märkta för husarbete, så skattereduktionen kan inte skapas.');
                    }
                    throw $e;
                }
            }
            $created++;
        }
        if ($created === 0) {
            throw new Exception('Kunde inte skapa skattereduktion med PropertyDesignation.');
        }
        return $created;
    }

    /**
     * När orderrader redan är skrivna: höj ansökan till möjlig skattereduktion,
     * om användaren inte själv har begränsat beloppet. Sänkning som måste ske
     * före radskrivningen görs i lowerKopTaxReduction.
     *
     * @param list<array<string,mixed>> $beforeRows
     * @param list<array<string,mixed>> $afterRows
     * @param array<string,mixed> $order
     * @return array{order:array<string,mixed>,capped?:bool,asked?:int,mojlig?:int,error?:string,update?:bool}
     */
    public function syncKopTaxReduction(array $row, array $beforeRows, array $afterRows, array $order): array
    {
        $orderNumber = trim((string) ($row['fortnox_order'] ?? ''));
        if ($orderNumber === '') {
            return ['order' => $order, 'update' => false];
        }
        $explicit = !empty($row['fortnox_gt_capped']);
        $storedAsked = (int) ($row['fortnox_gt_asked'] ?? 0);
        try {
            $live = $this->getOrder($orderNumber);
        } catch (Throwable $e) {
            return ['order' => $order, 'error' => $e->getMessage(), 'update' => false];
        }
        $liveRows = function_exists('jeab_ordrar_fortnox_order_rows')
            ? jeab_ordrar_fortnox_order_rows($live)
            : [];
        if ($liveRows !== []) {
            $afterRows = $liveRows;
            $order = $live;
        }
        try {
            $existing = $this->listTaxReductionsForOrder($orderNumber, $live);
        } catch (Throwable $e) {
            return ['order' => $order, 'error' => $e->getMessage(), 'update' => false];
        }
        [$hydrated, $currentAsked] = $this->hydrateTaxReductions($existing);
        $owners = [];
        if (function_exists('jeab_ordrar_fortnox_owners')) {
            try {
                $owners = jeab_ordrar_fortnox_owners($row);
            } catch (Throwable) {
                $owners = [];
            }
        }
        $ownerKeys = [];
        foreach ($owners as $owner) {
            if (!is_array($owner)) {
                continue;
            }
            $name = trim((string) ($owner['name'] ?? ''));
            $pnr = trim((string) ($owner['personnummer'] ?? ''));
            if ($name === '' || $pnr === '') {
                continue;
            }
            $ownerKeys[self::ownerSeenKey($name, $pnr)] = true;
        }
        $coveredKeys = [];
        foreach ($hydrated as $taxRow) {
            if (!is_array($taxRow)) {
                continue;
            }
            $name = trim((string) ($taxRow['CustomerName'] ?? $taxRow['name'] ?? ''));
            $pnr = trim((string) ($taxRow['SocialSecurityNumber'] ?? $taxRow['personnummer'] ?? ''));
            if ($name === '' && $pnr === '') {
                continue;
            }
            $key = self::ownerSeenKey($name, $pnr);
            if (isset($ownerKeys[$key])) {
                $coveredKeys[$key] = true;
            }
        }
        $missingOwner = false;
        foreach ($ownerKeys as $key => $_present) {
            if (!isset($coveredKeys[$key])) {
                $missingOwner = true;
                break;
            }
        }
        if ($hydrated !== [] && $missingOwner) {
            $fast = trim((string) ($row['fastighetsbeteckning'] ?? ''));
            if ($fast === '') {
                $first = $hydrated[0] ?? [];
                $fast = is_array($first) ? trim((string) ($first['PropertyDesignation'] ?? '')) : '';
            }
            if ($fast !== '') {
                $attached = $this->writeTaxReductionPropertyDesignation($owners, $fast, $orderNumber, true);
                if (trim((string) ($attached['error'] ?? '')) !== '') {
                    return ['order' => $order, 'error' => (string) $attached['error'], 'update' => false];
                }
                try {
                    $existing = $this->listTaxReductionsForOrder($orderNumber, $live);
                    [$hydrated, $currentAsked] = $this->hydrateTaxReductions($existing);
                } catch (Throwable $e) {
                    return ['order' => $order, 'error' => $e->getMessage(), 'update' => false];
                }
                $order = $this->projectOrderAsked($order, $currentAsked);
            }
        }
        $people = max(count($hydrated), count($ownerKeys), 1);
        $plan = jeab_ordrar_gt_sync_plan(
            $beforeRows,
            $afterRows,
            $currentAsked,
            $people,
            $hydrated !== [],
            $explicit,
            $storedAsked,
            function_exists('jeab_ordrar_gt_follows_kalkyl') && jeab_ordrar_gt_follows_kalkyl($row, $afterRows)
        );
        if ($hydrated !== []) {
            $filled = self::greenTechFillMissingTypes(
                self::houseworkBases($afterRows),
                self::greenTechAskedByType($hydrated),
                $people
            );
            if ($filled !== null && (empty($plan['update']) || !empty($plan['capped']))) {
                $plan['update'] = true;
                $plan['capped'] = false;
                $plan['asked'] = (int) array_sum($filled);
                $plan['splits'] = self::greenTechSplitsFromTypes($filled, $people);
            }
        }
        $lingering = self::greenTechLingeringTypes($hydrated, $afterRows);
        if ($lingering !== [] && empty($plan['update'])) {
            $plan['update'] = true;
            if (!empty($plan['capped'])) {
                $askedByType = self::greenTechAskedByType($hydrated);
                $lingeringSum = 0;
                foreach ($lingering as $type) {
                    $lingeringSum += (int) ($askedByType[$type] ?? 0);
                }
                $trimmed = max(0, (int) ($plan['asked'] ?? 0) - $lingeringSum);
                $plan['asked'] = $trimmed;
                $plan['splits'] = self::greenTechSplits(self::houseworkBases($afterRows), $people, $trimmed);
            }
        }
        if (empty($plan['update'])) {
            $order = $this->alignStaleHighTax($order, $currentAsked);
            return [
                'order' => $order,
                'capped' => !empty($plan['capped']),
                'asked' => (int) ($plan['asked'] ?? $currentAsked),
                'mojlig' => (int) ($plan['mojlig'] ?? 0),
                'update' => false,
            ];
        }
        $splits = is_array($plan['splits'] ?? null) ? $plan['splits'] : [];
        $target = (int) ($plan['asked'] ?? 0);
        $fast = trim((string) ($row['fastighetsbeteckning'] ?? ''));
        try {
            $target = $this->writeAskedSplits($hydrated, $owners, $fast, $orderNumber, $splits, $people, $target, $afterRows);
        } catch (Throwable $e) {
            return ['order' => $order, 'error' => $e->getMessage(), 'update' => false, 'capped' => !empty($plan['capped'])];
        }
        try {
            $refreshed = $this->getOrder($orderNumber);
        } catch (Throwable) {
            $refreshed = $order;
        }
        if (function_exists('jeab_ordrar_fortnox_with_asked')) {
            $refreshed = jeab_ordrar_fortnox_with_asked($refreshed, $currentAsked, $target);
        }
        return [
            'order' => $refreshed,
            'capped' => !empty($plan['capped']),
            'asked' => $target,
            'mojlig' => (int) ($plan['mojlig'] ?? 0),
            'update' => true,
        ];
    }

    /**
     * Sänk ansökan innan orderraderna skrivs. Varje arbetstyp får bara gå ner
     * till det belopp som både de gamla och de nya raderna rymmer. En typ som
     * ska höjas, till exempel solceller när batteriet samtidigt blir billigare,
     * lämnas tills raderna är sparade. Fortnox jämför annars den högre
     * ansökan med det gamla underlaget och avvisar med «överskrider möjligt
     * belopp för den typen».
     * Live-orderns rader används inte: de är fortfarande de gamla.
     *
     * @param list<array<string,mixed>> $beforeRows
     * @param list<array<string,mixed>> $afterRows
     * @param array<string,mixed> $order
     * @return array{order:array<string,mixed>,capped?:bool,asked?:int,mojlig?:int,error?:string,update?:bool}
     */
    public function lowerKopTaxReduction(array $row, array $beforeRows, array $afterRows, array $order): array
    {
        $orderNumber = trim((string) ($row['fortnox_order'] ?? ''));
        if ($orderNumber === '') {
            return ['order' => $order, 'update' => false];
        }
        $explicit = !empty($row['fortnox_gt_capped']);
        $storedAsked = (int) ($row['fortnox_gt_asked'] ?? 0);
        try {
            $existing = $this->listTaxReductionsForOrder($orderNumber, $order);
        } catch (Throwable) {
            return ['order' => $order, 'update' => false];
        }
        [$hydrated, $currentAsked] = $this->hydrateTaxReductions($existing);
        if ($hydrated === []) {
            return ['order' => $order, 'update' => false, 'asked' => $currentAsked];
        }
        $people = count($hydrated);
        $plan = jeab_ordrar_gt_sync_plan(
            $beforeRows,
            $afterRows,
            $currentAsked,
            $people,
            true,
            $explicit,
            $storedAsked,
            function_exists('jeab_ordrar_gt_follows_kalkyl') && jeab_ordrar_gt_follows_kalkyl($row, $afterRows)
        );
        $hold = self::greenTechHoldBeforeRows(
            self::houseworkBases($beforeRows),
            self::houseworkBases($afterRows),
            self::greenTechAskedByType($hydrated),
            $people
        );
        if (empty($hold['update'])) {
            return [
                'order' => $order,
                'capped' => !empty($plan['capped']),
                'asked' => $currentAsked,
                'mojlig' => (int) ($plan['mojlig'] ?? 0),
                'update' => false,
            ];
        }
        $splits = self::greenTechSplitsFromTypes($hold['per_type'], $people);
        $target = (int) $hold['asked'];
        try {
            $target = $this->writeAskedSplits($hydrated, [], '', $orderNumber, $splits, $people, $target, $afterRows);
        } catch (Throwable $e) {
            return [
                'order' => $order,
                'error' => $e->getMessage(),
                'update' => false,
                'capped' => !empty($plan['capped']),
            ];
        }
        if (function_exists('jeab_ordrar_fortnox_with_asked')) {
            $order = jeab_ordrar_fortnox_with_asked($order, $currentAsked, $target);
        }
        return [
            'order' => $order,
            'capped' => !empty($plan['capped']),
            'asked' => $target,
            'mojlig' => (int) ($plan['mojlig'] ?? 0),
            'update' => true,
        ];
    }

    /**
     * @param list<array<string,mixed>> $existing
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    private function hydrateTaxReductions(array $existing): array
    {
        $hydrated = [];
        $currentAsked = 0;
        foreach ($existing as $taxRow) {
            if (!is_array($taxRow)) {
                continue;
            }
            $id = trim((string) ($taxRow['Id'] ?? $taxRow['id'] ?? ''));
            if ($id !== '') {
                try {
                    $full = $this->request('GET', '/taxreductions/' . rawurlencode($id));
                    if (is_array($full['TaxReduction'] ?? null)) {
                        $taxRow = $full['TaxReduction'];
                    }
                } catch (Throwable) {
                    // Listan får räcka om detaljen inte går att läsa.
                }
            }
            $hydrated[] = $taxRow;
            $currentAsked += self::taxReductionAskedSum($taxRow);
        }
        return [$hydrated, $currentAsked];
    }

    /**
     * @param list<array<string,mixed>> $hydrated
     * @param list<array{name?:string,personnummer?:string}> $owners
     * @param list<list<array{WorkType:string,AskedAmount:int}>> $splits
     * @param list<array<string,mixed>> $afterRows
     */
    private function writeAskedSplits(array $hydrated, array $owners, string $fast, string $orderNumber, array $splits, int $people, int $target, array $afterRows): int
    {
        if (
            $hydrated === []
            && self::greenTechHasPositiveAmounts($splits)
            && $afterRows !== []
            && !self::rowsHaveGreenTechHouseWork($afterRows)
        ) {
            throw new Exception('Orderraderna är inte märkta för husarbete, så skattereduktionen kan inte skapas.');
        }
        $people = max(1, $people);
        // Tomma splits + kvarvarande grön-teknik-rader: bygg om från underlaget.
        // Utan underlag (t.ex. tillfälligt nollade priser) behåll befintlig ansökan —
        // aldrig DELETE medan husarbete fortfarande behövs.
        if (!self::greenTechHasPositiveAmounts($splits) && self::rowsHaveGreenTechHouseWork($afterRows)) {
            $rebuilt = self::greenTechRebuildSplitsIfEmpty($afterRows, $splits, $people, $target);
            if (self::greenTechHasPositiveAmounts($rebuilt)) {
                $splits = $rebuilt;
                $target = (int) array_sum(self::greenTechPerTypeFromSplits($rebuilt));
            } elseif ($hydrated !== []) {
                return self::greenTechHydratedAskedSum($hydrated);
            }
        }
        try {
            $this->applyGreenTechSplits($hydrated, $owners, $fast, $orderNumber, $splits);
            return $target;
        } catch (Throwable $e) {
            return $this->recoverAskedSplits($e, $hydrated, $owners, $fast, $orderNumber, $splits, $people, $target, $afterRows);
        }
    }

    /**
     * Fortnox avvisade skrivningen. Nollad typ skrivs tillbaka inom möjligt
     * belopp. Överskridande efter den återställningen försöker igen på taken.
     *
     * @param list<array<string,mixed>> $hydrated
     * @param list<array{name?:string,personnummer?:string}> $owners
     * @param list<list<array{WorkType:string,AskedAmount:int}>> $splits
     * @param list<array<string,mixed>> $afterRows
     */
    private function recoverAskedSplits(Throwable $e, array $hydrated, array $owners, string $fast, string $orderNumber, array $splits, int $people, int $target, array $afterRows): int
    {
        $message = $e->getMessage();
        if (self::isZeroWorkTypeAmount($message) && $hydrated !== []) {
            try {
                $restored = $this->putRestoredZeroedType($message, $hydrated, $splits, $afterRows);
            } catch (Throwable $restoreError) {
                if (self::isPerTypeTaxAmountTooHigh($restoreError->getMessage())) {
                    return $this->retryPerTypeCap($hydrated, $owners, $fast, $orderNumber, $people, $afterRows, true);
                }
                throw $restoreError;
            }
            if ($restored > 0) {
                return $restored;
            }
            throw new Exception(self::zeroWorkTypeUiMessage($message));
        }
        if (self::isTaxReductionMustUpdate($message)) {
            $trySplits = self::greenTechRebuildSplitsIfEmpty($afterRows, $splits, $people, $target);
            if ($this->putRelistedTaxReductions($message, $orderNumber, $trySplits)) {
                if (self::greenTechHasPositiveAmounts($trySplits)) {
                    return (int) array_sum(self::greenTechPerTypeFromSplits($trySplits));
                }
                return $target;
            }
            // Fortnox kräver PUT men relist misslyckades. Ta inte bort ansökan
            // så länge ordern fortfarande har grön-teknik-husarbete.
            if (self::rowsHaveGreenTechHouseWork($afterRows) && $hydrated !== []) {
                return self::greenTechHydratedAskedSum($hydrated);
            }
            throw new Exception(self::taxReductionUpdateUiMessage($afterRows));
        }
        if (self::isPersonCapTaxAmountTooHigh($message)) {
            return $this->retryPerTypeCap($hydrated, $owners, $fast, $orderNumber, $people, $afterRows, false);
        }
        if (self::isPerTypeTaxAmountTooHigh($message)) {
            return $this->retryPerTypeCap($hydrated, $owners, $fast, $orderNumber, $people, $afterRows, false);
        }
        if (!self::isTaxAmountTooHigh($message)) {
            throw $e;
        }
        $shrunk = self::shrinkGreenTechTypes(self::greenTechPerTypeFromSplits($splits), $message, $people);
        $retry = self::greenTechSplitsFromTypes($shrunk, $people);
        try {
            $this->applyGreenTechSplits($hydrated, $owners, $fast, $orderNumber, $retry);
        } catch (Throwable $retryError) {
            $retryMessage = $retryError->getMessage();
            if (self::isPerTypeTaxAmountTooHigh($retryMessage)) {
                return $this->retryPerTypeCap($hydrated, $owners, $fast, $orderNumber, $people, $afterRows, false);
            }
            if (self::isZeroWorkTypeAmount($retryMessage) && $hydrated !== []) {
                return $this->restoreZeroedOrGiveUp($retryMessage, $hydrated, $retry, $afterRows);
            }
            throw $retryError;
        }
        return (int) array_sum($shrunk);
    }

    /**
     * @param list<array<string,mixed>> $hydrated
     * @param list<array{name?:string,personnummer?:string}> $owners
     * @param list<array<string,mixed>> $afterRows
     */
    private function retryPerTypeCap(array $hydrated, array $owners, string $fast, string $orderNumber, int $people, array $afterRows, bool $fromRestore): int
    {
        $bases = self::houseworkBases($afterRows);
        $people = max(1, $people);
        $capTarget = self::greenTechPossible($bases, $people);
        $retry = self::greenTechSplits($bases, $people, $capTarget);
        try {
            $this->applyGreenTechSplits($hydrated, $owners, $fast, $orderNumber, $retry);
            return $capTarget;
        } catch (Throwable $retryError) {
            $retryMessage = $retryError->getMessage();
            if (self::isZeroWorkTypeAmount($retryMessage) && $hydrated !== []) {
                return $this->restoreZeroedOrGiveUp($retryMessage, $hydrated, $retry, $afterRows);
            }
            if ($fromRestore && self::isPerTypeTaxAmountTooHigh($retryMessage)) {
                throw new Exception(self::zeroWorkTypeUiMessage($retryMessage));
            }
            throw $retryError;
        }
    }

    /**
     * @param list<array<string,mixed>> $hydrated
     * @param list<list<array{WorkType:string,AskedAmount:int}>> $splits
     * @param list<array<string,mixed>> $afterRows
     */
    private function restoreZeroedOrGiveUp(string $message, array $hydrated, array $splits, array $afterRows): int
    {
        try {
            $restored = $this->putRestoredZeroedType($message, $hydrated, $splits, $afterRows);
        } catch (Throwable $restoreError) {
            if (self::isPerTypeTaxAmountTooHigh($restoreError->getMessage())) {
                throw new Exception(self::zeroWorkTypeUiMessage($message));
            }
            throw $restoreError;
        }
        if ($restored > 0) {
            return $restored;
        }
        throw new Exception(self::zeroWorkTypeUiMessage($message));
    }

    /**
     * @param list<array<string,mixed>> $hydrated
     * @param list<array{name?:string,personnummer?:string}> $owners
     * @param list<list<array<string,mixed>>> $splits
     */
    private function applyGreenTechSplits(array $hydrated, array $owners, string $fast, string $orderNumber, array $splits): void
    {
        if ($hydrated === []) {
            $this->postGreenTechSplits($owners, $fast, $orderNumber, $splits);
            return;
        }
        $write = self::greenTechAmountWrite($hydrated, $splits);
        // En typ som försvinner medan en annan finns kvar ska PUT:as.
        // DELETE nekas så länge ordern har husarbete: «använd PUT för att
        // istället uppdatera». Tomma belopp får aldrig leda till DELETE —
        // skrivaren ska ha byggt om eller behållit ansökan innan den kommer hit.
        if (!empty($write['replace']) && !self::greenTechHasPositiveAmounts($write['amounts'])) {
            throw new Exception('Skattereduktionen kan inte tas bort så länge ordern är märkt för husarbete. Behåll en rad med husarbete.');
        }
        $this->putGreenTechSplits($hydrated, $write['amounts']);
    }

    /** Orderns visade skattereduktion följer summan på ansökningarna. */
    private function projectOrderAsked(array $order, int $asked): array
    {
        if ($asked < 0 || !function_exists('jeab_ordrar_fortnox_order_body') || !function_exists('jeab_ordrar_fortnox_with_asked')) {
            return $order;
        }
        $body = jeab_ordrar_fortnox_order_body($order);
        if (!isset($body['TaxReduction']) || !is_numeric($body['TaxReduction'])) {
            return $order;
        }
        $tax = (int) round((float) $body['TaxReduction']);
        if ($tax === $asked) {
            return $order;
        }
        return jeab_ordrar_fortnox_with_asked($order, $tax, $asked);
    }

    /** Om orderns skattereduktion fortfarande är högre än ansökan, följer att betala ansökan. */
    private function alignStaleHighTax(array $order, int $asked): array
    {
        if ($asked < 0 || !function_exists('jeab_ordrar_fortnox_order_body') || !function_exists('jeab_ordrar_fortnox_with_asked')) {
            return $order;
        }
        $body = jeab_ordrar_fortnox_order_body($order);
        if (!isset($body['TaxReduction']) || !is_numeric($body['TaxReduction'])) {
            return $order;
        }
        $tax = (int) round((float) $body['TaxReduction']);
        if ($tax <= $asked) {
            return $order;
        }
        return jeab_ordrar_fortnox_with_asked($order, $tax, $asked);
    }

    /**
     * Fortnox säger både «överstiger» och «överskrider» när ansökan är för hög.
     * Nollad arbetstyp är ett annat fel och ska inte försöka igen med ett lägre belopp.
     */
    public static function isTaxAmountTooHigh(string $message): bool
    {
        if (self::isZeroWorkTypeAmount($message) || self::isTaxReductionMustUpdate($message)) {
            return false;
        }
        if (self::isPersonCapTaxAmountTooHigh($message)) {
            return true;
        }
        if (preg_match('/skattereduktion|tax.?reduction|ansökta summan|asked amount|möjlig(?:t)? belopp|possible amount/iu', $message) !== 1) {
            return false;
        }
        return preg_match('/för högt|for hogt|exceed|överskrider|överskrid|överstiger|too high/iu', $message) === 1;
    }

    /**
     * Fortnox: «Angivet belopp gör att totalen överskrider 50000 kr.»
     * Taket per person och år, inte per arbetstyp.
     */
    public static function isPersonCapTaxAmountTooHigh(string $message): bool
    {
        if (self::isZeroWorkTypeAmount($message) || self::isTaxReductionMustUpdate($message)) {
            return false;
        }
        if (preg_match('/överskrider|överskrida|överstiger|exceed|too high/iu', $message) !== 1) {
            return false;
        }
        if (preg_match('/50\s*000|50000/u', $message) === 1) {
            return true;
        }
        return preg_match('/totalen\s+överskrider|överskrider\s+(?:att\s+)?totalen/iu', $message) === 1;
    }

    /**
     * Fortnox: «Den ansökta summan för en typ får inte överskrida möjligt belopp
     * för den typen (Solceller)». Det är taket per arbetstyp, inte fakturans totalsumma
     * och inte person-taket på 50 000 kr.
     */
    public static function isPerTypeTaxAmountTooHigh(string $message): bool
    {
        if (self::isZeroWorkTypeAmount($message) || self::isTaxReductionMustUpdate($message)) {
            return false;
        }
        if (self::isPersonCapTaxAmountTooHigh($message)) {
            return false;
        }
        if (preg_match('/överskrida|överskrider/iu', $message) !== 1) {
            return false;
        }
        return preg_match('/typ|solceller|belopp/iu', $message) === 1;
    }

    /**
     * Finns minst ett belopp kvar att skriva. Då ska ansökan uppdateras,
     * inte tas bort.
     *
     * @param list<list<array<string,mixed>>> $amounts
     */
    public static function greenTechHasPositiveAmounts(array $amounts): bool
    {
        foreach ($amounts as $person) {
            if (is_array($person) && $person !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * Summan av AskedAmount på hydrerade ansökningar.
     *
     * @param list<array<string,mixed>> $hydrated
     */
    public static function greenTechHydratedAskedSum(array $hydrated): int
    {
        $sum = 0;
        foreach ($hydrated as $row) {
            if (is_array($row)) {
                $sum += self::taxReductionAskedSum($row);
            }
        }
        return $sum;
    }

    /**
     * Bygg positiva splits från raderna när planen råkat bli tom
     * men husarbete för grön teknik fortfarande finns.
     *
     * @param list<array<string,mixed>> $afterRows
     * @param list<list<array{WorkType:string,AskedAmount:int}>> $splits
     * @return list<list<array{WorkType:string,AskedAmount:int}>>
     */
    public static function greenTechRebuildSplitsIfEmpty(array $afterRows, array $splits, int $people, int $target): array
    {
        if (self::greenTechHasPositiveAmounts($splits)) {
            return $splits;
        }
        if (!self::rowsHaveGreenTechHouseWork($afterRows)) {
            return $splits;
        }
        $people = max(1, $people);
        $bases = self::houseworkBases($afterRows);
        $possible = self::greenTechPossible($bases, $people);
        if ($possible <= 0) {
            return $splits;
        }
        $want = $target > 0 ? min($target, $possible) : $possible;
        return self::greenTechSplits($bases, $people, $want);
    }

    /**
     * Fortnox: «Minst en skattereduktion måste vara kopplat till en faktura
     * som är märkt för husarbete, använd PUT för att isället uppdatera.»
     * Live-texten skriver «isället». «istället» och «use PUT» räknas också.
     */
    public static function isTaxReductionMustUpdate(string $message): bool
    {
        return preg_match('/använd\s+PUT|anvand\s+PUT|use\s+PUT/iu', $message) === 1;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public static function rowsHaveGreenTechHouseWork(array $rows): bool
    {
        foreach ($rows as $row) {
            if (!is_array($row) || self::houseworkRowSkipped($row)) {
                continue;
            }
            if (isset(self::greenTechRates()[(string) ($row['HouseWorkType'] ?? '')])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private static function taxReductionUpdateUiMessage(array $rows): string
    {
        if ($rows !== [] && !self::rowsHaveGreenTechHouseWork($rows)) {
            return 'Orderraderna är inte märkta för husarbete, så skattereduktionen kan inte skapas.';
        }
        return 'Skattereduktionen kan inte tas bort så länge ordern är märkt för husarbete. Behåll en rad med husarbete.';
    }

    /**
     * Fortnox bad om PUT. Läs om ansökningarna för ordern och skriv beloppen där.
     *
     * @param list<list<array<string,mixed>>> $splits
     */
    private function putRelistedTaxReductions(string $message, string $orderNumber, array $splits): bool
    {
        if (!self::isTaxReductionMustUpdate($message) || !self::greenTechHasPositiveAmounts($splits)) {
            return false;
        }
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '') {
            return false;
        }
        $order = $this->getOrder($orderNumber);
        $existing = $this->listTaxReductionsForOrder($orderNumber, $order);
        [$hydrated] = $this->hydrateTaxReductions($existing);
        $hydrated = array_values(array_filter($hydrated, static function ($row): bool {
            return is_array($row) && trim((string) ($row['Id'] ?? $row['id'] ?? '')) !== '';
        }));
        if ($hydrated === []) {
            return false;
        }
        try {
            return $this->putGreenTechSplits($hydrated, $splits) > 0;
        } catch (Throwable $e) {
            if (self::isTaxReductionMustUpdate($e->getMessage())) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Fortnox: «Den totala ansökta summan för en typ får inte vara 0 (Lagring …)».
     * Typen finns kvar på ansökan och får inte utelämnas.
     */
    public static function zeroWorkTypeUiMessage(string $message): string
    {
        $named = '';
        if (preg_match('/\(([^)]+)\)/u', $message, $match) === 1) {
            $named = trim((string) $match[1]);
        }
        if ($named !== '') {
            return 'Skattereduktionen för ' . $named . ' kan inte nollställas så länge ordern är märkt för husarbete. Behåll den raden.';
        }
        return 'Skattereduktionen kan inte nollställas så länge ordern är märkt för husarbete. Behåll raden med husarbete.';
    }

    /** @return array<string,string> */
    public static function greenTechTypeLabels(): array
    {
        return [
            'CHARGINGSTATIONELECTRICVEHICLE' => 'Laddningspunkt elfordon',
            'SOLARCELLS' => 'Solceller',
            'STORAGESELFPRODUCEDELECTRICITY' => 'Lagring egenproducerad el',
        ];
    }

    /**
     * Felet när en ny arbetstyp inte gick att lägga till.
     *
     * @param list<string> $types
     */
    public static function newTypeUiMessage(string $message, array $types, string $restoreError = ''): string
    {
        $labels = self::greenTechTypeLabels();
        $names = [];
        foreach ($types as $type) {
            $names[] = $labels[$type] ?? $type;
        }
        $message = rtrim(trim($message), '. ');
        $out = 'Fortnox tog inte emot ' . implode(' och ', $names) . ' på ordern'
            . ($message !== '' ? ': ' . $message : '') . '.';
        if (trim($restoreError) === '') {
            return $out . ' Raderna och skattereduktionen är återställda som innan.';
        }
        return $out . ' Återställningen gick inte hela vägen (' . trim($restoreError) . '). Kontrollera ordern i Fortnox.';
    }

    /**
     * Belopp att skriva tillbaka när Fortnox vägrar nolla en arbetstyp.
     * Högst möjligt belopp på raderna. Utan underlag parkeras en krona per person.
     *
     * @param array<string,int> $askedByType
     * @param array<string,float> $bases
     */
    public static function greenTechRestoreKept(string $type, array $askedByType, array $bases, int $people): int
    {
        if (!isset(self::greenTechRates()[$type])) {
            return 0;
        }
        $previous = max(0, (int) ($askedByType[$type] ?? 0));
        if ($previous <= 0) {
            return 0;
        }
        $people = max(1, $people);
        $cap = (int) (self::greenTechPossibleByType($bases)[$type] ?? 0);
        if ($cap <= 0) {
            return min($people, $previous);
        }
        return min($previous, $cap);
    }

    /**
     * PUT som utelämnade en typ fick «får inte vara 0». Skriv typen igen,
     * högst till möjligt belopp på raderna. Utan underlag parkeras en krona
     * per person. Ingen DELETE.
     *
     * @param list<array<string,mixed>> $hydrated
     * @param list<list<array<string,mixed>>> $splits
     * @param list<array<string,mixed>> $rows
     */
    private function putRestoredZeroedType(string $message, array $hydrated, array $splits, array $rows): int
    {
        $type = self::workTypeFromTooHighMessage($message);
        if ($type === null || !isset(self::greenTechRates()[$type])) {
            return 0;
        }
        $people = max(1, count($hydrated), count($splits));
        $bases = self::houseworkBases($rows);
        $keep = self::greenTechRestoreKept($type, self::greenTechAskedByType($hydrated), $bases, $people);
        if ($keep <= 0) {
            return 0;
        }
        $per = self::greenTechPerTypeFromSplits($splits);
        $possible = (int) (self::greenTechPossibleByType($bases)[$type] ?? 0);
        if ($possible <= 0) {
            $without = $per;
            unset($without[$type]);
            $released = self::greenTechReleaseForPark($without, $keep);
            if ($released !== null) {
                $per = $released;
            }
        }
        $per[$type] = $keep;
        try {
            $wrote = $this->putGreenTechSplits($hydrated, self::greenTechSplitsFromTypes($per, $people));
        } catch (Throwable $e) {
            if (self::isZeroWorkTypeAmount($e->getMessage()) || self::isTaxReductionMustUpdate($e->getMessage())) {
                return 0;
            }
            throw $e;
        }
        return $wrote > 0 ? (int) array_sum($per) : 0;
    }

    /** Fortnox: «Den totala ansökta summan för en typ får inte vara 0». */
    private static function isZeroWorkTypeAmount(string $message): bool
    {
        return preg_match('/får inte vara 0|far inte vara 0|must not be 0|cannot be 0/iu', $message) === 1;
    }

    /**
     * @param list<array<string,mixed>> $hydrated
     * @param list<list<array{WorkType:string,AskedAmount:int}>> $splits
     */
    private function putGreenTechSplits(array $hydrated, array $splits): int
    {
        $wrote = 0;
        foreach ($hydrated as $i => $taxRow) {
            $id = trim((string) ($taxRow['Id'] ?? $taxRow['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $amounts = self::positiveTaxAmounts(is_array($splits[$i] ?? null) ? $splits[$i] : []);
            if ($amounts === []) {
                continue;
            }
            $body = ['TaxReductionAmounts' => $amounts];
            $designation = trim((string) ($taxRow['PropertyDesignation'] ?? ''));
            if ($designation !== '') {
                $body['PropertyDesignation'] = $designation;
            }
            $this->request('PUT', '/taxreductions/' . rawurlencode($id), [
                'TaxReduction' => $body,
            ]);
            $wrote++;
        }
        return $wrote;
    }

    /**
     * En försvunnen arbetstyp går inte att nolla. Ansökan tas bort och skrivs
     * om med bara de typer som fortfarande har ett belopp över 0.
     *
     * @param list<array<string,mixed>> $hydrated
     * @param list<list<array<string,mixed>>> $splits
     */
    private function replaceGreenTechSplits(array $hydrated, string $orderNumber, array $splits): void
    {
        $orderNumber = trim($orderNumber);
        if (!self::greenTechHasPositiveAmounts($splits)) {
            throw new Exception('Skattereduktionen kan inte tas bort så länge ordern är märkt för husarbete. Behåll en rad med husarbete.');
        }
        $templates = [];
        foreach ($hydrated as $i => $taxRow) {
            if (!is_array($taxRow)) {
                continue;
            }
            $templates[$i] = $taxRow;
            $id = trim((string) ($taxRow['Id'] ?? $taxRow['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            try {
                $this->request('DELETE', '/taxreductions/' . rawurlencode($id));
            } catch (Throwable $e) {
                if (!self::isTaxReductionMustUpdate($e->getMessage())) {
                    throw $e;
                }
                if ($this->putRelistedTaxReductions($e->getMessage(), $orderNumber, $splits)) {
                    return;
                }
                throw new Exception('Skattereduktionen kan inte tas bort så länge ordern är märkt för husarbete. Behåll en rad med husarbete.');
            }
        }
        foreach ($templates as $i => $taxRow) {
            $amounts = self::positiveTaxAmounts(is_array($splits[$i] ?? null) ? $splits[$i] : []);
            if ($amounts === []) {
                continue;
            }
            $name = trim((string) ($taxRow['CustomerName'] ?? ''));
            $pnr = trim((string) ($taxRow['SocialSecurityNumber'] ?? ''));
            $designation = trim((string) ($taxRow['PropertyDesignation'] ?? ''));
            if ($name === '' || $pnr === '' || $designation === '' || $orderNumber === '') {
                throw new Exception('Kunde inte skriva om skattereduktionen utan den borttagna arbetstypen.');
            }
            $this->request('POST', '/taxreductions', [
                'TaxReduction' => [
                    'CustomerName' => $name,
                    'SocialSecurityNumber' => $pnr,
                    'PropertyDesignation' => $designation,
                    'ReferenceDocumentType' => 'ORDER',
                    'ReferenceNumber' => $orderNumber,
                    'TaxReductionAmounts' => $amounts,
                ],
            ]);
        }
    }

    /**
     * @param list<array{name?:string,personnummer?:string}> $owners
     * @param list<list<array{WorkType:string,AskedAmount:int}>> $splits
     */
    private function postGreenTechSplits(array $owners, string $fastighetsbeteckning, string $orderNumber, array $splits): void
    {
        $fastighetsbeteckning = $this->requirePropertyDesignation($fastighetsbeteckning);
        if (trim($orderNumber) === '') {
            throw new Exception('Ordernummer saknas.');
        }
        $eligible = [];
        $seen = [];
        foreach ($owners as $owner) {
            if (!is_array($owner)) {
                continue;
            }
            $name = trim((string) ($owner['name'] ?? ''));
            $pnr = trim((string) ($owner['personnummer'] ?? ''));
            if ($name === '' || $pnr === '') {
                continue;
            }
            $key = self::ownerSeenKey($name, $pnr);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $eligible[] = ['name' => $name, 'personnummer' => $pnr];
        }
        if ($eligible === []) {
            throw new Exception('Saknas person för skattereduktion.');
        }
        $created = 0;
        foreach ($eligible as $i => $owner) {
            $amounts = self::positiveTaxAmounts(is_array($splits[$i] ?? null) ? $splits[$i] : []);
            if ($amounts === []) {
                continue;
            }
            try {
                $this->request('POST', '/taxreductions', [
                    'TaxReduction' => [
                        'CustomerName' => $owner['name'],
                        'SocialSecurityNumber' => $owner['personnummer'],
                        'PropertyDesignation' => $fastighetsbeteckning,
                        'ReferenceDocumentType' => 'ORDER',
                        'ReferenceNumber' => $orderNumber,
                        'TaxReductionAmounts' => $amounts,
                    ],
                ]);
            } catch (Throwable $e) {
                if ($this->putRelistedTaxReductions($e->getMessage(), $orderNumber, $splits)) {
                    return;
                }
                throw $e;
            }
            $created++;
        }
        if ($created === 0) {
            throw new Exception('Kunde inte skapa skattereduktion med PropertyDesignation.');
        }
    }

    public function addProperty(array $owners, string $fastighetsbeteckning, string $email, string $phone, array $address = []): array
    {
        $first = $owners[0] ?? [];
        $probe = jeab_fortnox_test_lead_probe([
            'email' => $email,
            'phone' => $phone,
            'names' => [trim((string) ($first['name'] ?? ''))],
        ]);
        if (!empty($probe['test'])) {
            jeab_fortnox_log_test_skip($probe);

            return jeab_fortnox_test_order_result();
        }
        $customerNumber = $this->findOrCreateCustomer($first, $email, $phone, $address);
        try {
            $orderNumber = $this->createOrder($customerNumber, $fastighetsbeteckning, (string) ($first['name'] ?? ''), $address);
        } catch (Exception $e) {
            if (!$this->isMissingOrInactiveError($e->getMessage())) {
                throw $e;
            }
            $skip = [$customerNumber => true];
            $next = $this->findOrCreateCustomer($first, $email, $phone, $address, $skip);
            if ($next === '' || $next === $customerNumber) {
                $next = $this->createPrivateCustomer(
                    trim((string) ($first['name'] ?? '')),
                    trim((string) ($first['personnummer'] ?? '')),
                    $email,
                    $phone,
                    $address
                );
            }
            if ($next === '' || $next === $customerNumber) {
                throw $e;
            }
            $customerNumber = $next;
            $orderNumber = $this->createOrder($customerNumber, $fastighetsbeteckning, (string) ($first['name'] ?? ''), $address);
        }
        $tax = $this->writeTaxReductionPropertyDesignation($owners, $fastighetsbeteckning, $orderNumber);
        return [
            'customerNumber' => $customerNumber,
            'orderNumber' => $orderNumber,
            'taxReductions' => $tax['count'],
            'taxReductionError' => $tax['error'],
        ];
    }

    /**
     * Arbetstyper som de nya raderna rymmer men som ansökan saknar helt,
     * till exempel en laddbox som läggs till på en batteriorder.
     *
     * @param list<array<string,mixed>> $afterRows
     * @param array<string,int> $askedByType
     * @return list<string>
     */
    public static function greenTechNewTypes(array $afterRows, array $askedByType): array
    {
        $out = [];
        foreach (self::greenTechPossibleByType(self::houseworkBases($afterRows)) as $type => $cap) {
            if ($cap > 0 && (int) ($askedByType[$type] ?? 0) <= 0) {
                $out[] = $type;
            }
        }
        return $out;
    }

    /**
     * Ny grön teknik-typ på en order som redan har ansökan.
     *
     * Fortnox har två regler som låser varandra:
     * - en ansökan får inte ha en typ som saknar husarbetsrad på ordern
     *   («Ogiltig husarbetestyp. Det finns ingen husarbetesrad …»);
     * - en orderrad med husarbete får inte sparas medan ansökan finns och
     *   typens summa är 0 («Den totala ansökta summan för en typ får inte vara 0»).
     * Fortnox har inget anrop som skriver rader och belopp samtidigt. Samma väg
     * som en ny order därför: ta bort ansökningarna, skriv raderna, skapa
     * ansökningarna igen med hela beloppet per person och typ. Nekar Fortnox
     * att ta bort den sista ansökan («använd PUT») tas markeringen för
     * skattereduktion av ordern först och sätts tillbaka med raderna.
     * Går något fel återställs raderna och de gamla ansökningarna.
     *
     * Ingenting görs (applies=false) om ordern saknar ansökan eller om
     * raderna inte har någon ny typ.
     *
     * @param list<array<string,mixed>> $afterRows
     * @param list<array<string,mixed>> $owners
     * @param array<string,mixed> $orderFields övriga orderfält i samma PUT som raderna
     * @return array{applies:bool,order?:array<string,mixed>,asked?:int,types?:list<string>,splits?:list<list<array{WorkType:string,AskedAmount:int}>>}
     */
    public function rewriteRowsWithNewGreenTechTypes(string $orderNumber, array $afterRows, array $owners = [], array $orderFields = [], string $fallbackDesignation = ''): array
    {
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '' || !self::rowsHaveGreenTechHouseWork($afterRows)) {
            return ['applies' => false];
        }
        $live = $this->getOrder($orderNumber);
        $liveBody = is_array($live['Order'] ?? null) ? $live['Order'] : $live;
        $existing = $this->listTaxReductionsForOrder($orderNumber, $live);
        [$hydrated] = $this->hydrateTaxReductions($existing);
        $hydrated = array_values(array_filter($hydrated, static function ($row): bool {
            return is_array($row) && trim((string) ($row['Id'] ?? $row['id'] ?? '')) !== '';
        }));
        $beforeRows = is_array($liveBody['OrderRows'] ?? null) ? array_values(array_filter($liveBody['OrderRows'], 'is_array')) : [];
        $liveIds = [];
        foreach ($beforeRows as $r) {
            $liveIds[(string) ($r['RowId'] ?? '')] = true;
        }
        if ($hydrated === []) {
            // Ordern saknar ansökan. Märkt för grön teknik med husarbetsrader:
            // den vanliga vägen. Annars (typ none eller inga husarbetsrader,
            // t.ex. efter ett avbrutet försök) skrivs den som en ny order:
            // rader + green, sedan en ansökan per ägare.
            $liveType = strtolower(trim((string) ($liveBody['TaxReductionType'] ?? '')));
            if (($liveType === 'green' && self::rowsHaveGreenTechHouseWork($beforeRows)) || $owners === []) {
                return ['applies' => false];
            }
            return $this->writeGreenTechAsNew($orderNumber, $afterRows, $beforeRows, $liveBody, $owners, $orderFields, $fallbackDesignation, $liveIds);
        }
        $types = self::greenTechNewTypes($afterRows, self::greenTechAskedByType($hydrated));
        if ($types === []) {
            return ['applies' => false];
        }

        // Ögonblicksbild av ansökningarna. Utan namn och personnummer går de
        // inte att skapa igen, och då rörs ingenting.
        $snapshot = [];
        $designation = '';
        foreach ($hydrated as $taxRow) {
            $name = trim((string) ($taxRow['CustomerName'] ?? ''));
            $pnr = trim((string) ($taxRow['SocialSecurityNumber'] ?? ''));
            $fast = trim((string) ($taxRow['PropertyDesignation'] ?? ''));
            if ($name === '' || $pnr === '') {
                throw new Exception(self::newTypeUiMessage('ansökan saknar namn eller personnummer och kan inte skrivas om', $types) );
            }
            if ($designation === '' && $fast !== '') {
                $designation = $fast;
            }
            $snapshot[] = [
                'id' => trim((string) ($taxRow['Id'] ?? $taxRow['id'] ?? '')),
                'name' => $name,
                'personnummer' => $pnr,
                'designation' => $fast,
                'key' => self::ownerSeenKey($name, $pnr),
                'amounts' => self::taxReductionAmountRows($taxRow),
            ];
        }
        if ($designation === '') {
            $designation = trim((string) ($liveBody['Comments'] ?? ''));
        }
        if ($designation === '') {
            $designation = trim($fallbackDesignation);
        }
        if ($designation === '') {
            throw new Exception(self::newTypeUiMessage('fastighetsbeteckning saknas på ansökan', $types));
        }
        $applicants = [];
        $seen = [];
        foreach ($snapshot as $person) {
            $seen[$person['key']] = true;
            $applicants[] = $person;
        }
        foreach ($owners as $owner) {
            if (!is_array($owner)) {
                continue;
            }
            $name = trim((string) ($owner['name'] ?? ''));
            $pnr = trim((string) ($owner['personnummer'] ?? ''));
            if ($name === '' || $pnr === '') {
                continue;
            }
            $key = self::ownerSeenKey($name, $pnr);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $applicants[] = ['name' => $name, 'personnummer' => $pnr, 'designation' => $designation, 'key' => $key, 'amounts' => []];
        }
        $people = max(1, count($applicants));
        $bases = self::houseworkBases($afterRows);
        $splits = self::greenTechSplits($bases, $people, self::greenTechPossible($bases, $people));

        $state = ['unmarked' => false, 'rows_written' => false, 'rows_dropped' => false];
        try {
            $survivors = [];
            foreach ($snapshot as $i => $person) {
                if ($survivors !== []) {
                    $survivors[] = $person;
                    continue;
                }
                try {
                    $this->request('DELETE', '/taxreductions/' . rawurlencode($person['id']));
                } catch (Throwable $e) {
                    if (!self::isTaxReductionMustUpdate($e->getMessage())) {
                        throw $e;
                    }
                    $survivors[] = $person;
                }
            }
            if ($survivors !== []) {
                // Den sista ansökan går inte att ta bort så länge ordern är
                // märkt för husarbete (live 7/10 22:58, även med husarbete av
                // på raderna), och TaxReductionType none nekas medan rader har
                // husarbete (live 22:47). Därför: rader med husarbete av,
                // sedan typ none i samma PUT, sedan bort med det som är kvar.
                $this->clearLastTaxReductions($orderNumber, $beforeRows, $state);
            }
            $body = $orderFields;
            unset($body['OrderRows']);
            $body['OrderRows'] = self::cleanOrderRows($afterRows, $state['rows_dropped'], $liveIds);
            if ($state['unmarked']) {
                $body['TaxReductionType'] = 'green';
            }
            $state['rows_written'] = true;
            $this->request('PUT', '/orders/' . rawurlencode($orderNumber), ['Order' => $body]);
            $posted = 0;
            foreach ($applicants as $i => $person) {
                $amounts = self::positiveTaxAmounts(is_array($splits[$i] ?? null) ? $splits[$i] : []);
                if ($amounts === []) {
                    continue;
                }
                $this->request('POST', '/taxreductions', [
                    'TaxReduction' => [
                        'CustomerName' => $person['name'],
                        'SocialSecurityNumber' => $person['personnummer'],
                        'PropertyDesignation' => $person['designation'] !== '' ? $person['designation'] : $designation,
                        'ReferenceDocumentType' => 'ORDER',
                        'ReferenceNumber' => $orderNumber,
                        'TaxReductionAmounts' => $amounts,
                    ],
                ]);
                $posted++;
            }
            if ($posted === 0) {
                throw new Exception('Ingen ansökan kunde skapas.');
            }
        } catch (Throwable $e) {
            $restoreError = $this->restoreGreenTechRewrite($orderNumber, $beforeRows, $snapshot, $state);
            appLog('fortnox_gt_new_type_failed', [
                'account' => $this->account,
                'orderNumber' => $orderNumber,
                'types' => $types,
                'error' => $e->getMessage(),
                'restoreError' => $restoreError,
            ]);
            throw new Exception(self::newTypeUiMessage($e->getMessage(), $types, $restoreError));
        }
        return [
            'applies' => true,
            'order' => $this->getOrder($orderNumber),
            'asked' => (int) array_sum(self::greenTechPerTypeFromSplits($splits)),
            'types' => $types,
            'splits' => $splits,
        ];
    }

    /**
     * Ta bort de ansökningar Fortnox inte släpper så länge ordern är märkt
     * för husarbete. Live 7/10:
     *  - DELETE av sista ansökan nekas medan ordern är märkt (22:58, även med
     *    husarbete av på raderna);
     *  - TaxReductionType none nekas medan rader har husarbete (22:47) eller
     *    en grön husarbetstyp (23:03), och en tom typ på raden ignoreras (23:06).
     * Därför PUT med typ none där raderna med grön typ är borttagna en stund
     * (de skrivs tillbaka i nästa PUT, utan gamla RowId). Ett nekat försök
     * ändrar ingenting i Fortnox, så varianterna prövas i tur och ordning.
     *
     * @param list<array<string,mixed>> $beforeRows
     * @param array{unmarked:bool,rows_written:bool,rows_dropped:bool} $state
     */
    private function clearLastTaxReductions(string $orderNumber, array $beforeRows, array &$state): void
    {
        $green = ['STORAGESELFPRODUCEDELECTRICITY', 'SOLARCELLS', 'CHARGINGSTATIONELECTRICVEHICLE'];
        $variants = [
            'gröna rader borttagna' => static fn (string $type): bool => in_array($type, $green, true),
            'alla husarbetsrader borttagna' => static fn (string $type): bool => $type !== '',
        ];
        $errors = [];
        $state['rows_written'] = true;
        $state['unmarked'] = true;
        $state['rows_dropped'] = true;
        $done = false;
        foreach ($variants as $label => $drop) {
            $keep = [];
            foreach (self::cleanOrderRows($beforeRows) as $r) {
                $type = trim((string) ($r['HouseWorkType'] ?? ''));
                if (!empty($r['HouseWork']) || $drop($type)) {
                    continue;
                }
                unset($r['HouseWorkHoursToReport']);
                $keep[] = $r;
            }
            try {
                $this->request('PUT', '/orders/' . rawurlencode($orderNumber), ['Order' => ['TaxReductionType' => 'none', 'OrderRows' => $keep]]);
                $done = true;
                break;
            } catch (Throwable $e) {
                $errors[] = 'typ none, ' . $label . ': ' . $e->getMessage();
            }
        }
        if (!$done) {
            throw new Exception('sista ansökan kan inte tas bort (' . implode(' / ', $errors) . ')');
        }
        try {
            $this->deleteRemainingTaxReductions($orderNumber);
        } catch (Throwable $e) {
            throw new Exception('sista ansökan kan inte tas bort (ta bort med typ none: ' . $e->getMessage() . ')');
        }
    }

    private function deleteRemainingTaxReductions(string $orderNumber): void
    {
        foreach ($this->listTaxReductionsForOrder($orderNumber, $this->getOrder($orderNumber)) as $taxRow) {
            $id = is_array($taxRow) ? trim((string) ($taxRow['Id'] ?? $taxRow['id'] ?? '')) : '';
            if ($id !== '') {
                $this->request('DELETE', '/taxreductions/' . rawurlencode($id));
            }
        }
    }

    private const WRITABLE_ROW_FIELDS = [
        'AccountNumber', 'ArticleNumber', 'CostCenter', 'DeliveredQuantity', 'Description', 'Discount',
        'DiscountType', 'HouseWork', 'HouseWorkHoursToReport', 'HouseWorkType', 'OrderedQuantity',
        'Price', 'Project', 'Unit', 'VAT',
    ];

    /**
     * Ordern saknar ansökan: rader + green i en PUT, sedan en ansökan per ägare
     * (samma ordning som när en ny order skapas). Fel: raderna och typen
     * tillbaka, skapade ansökningar bort.
     *
     * @param list<array<string,mixed>> $afterRows
     * @param list<array<string,mixed>> $beforeRows
     * @param array<string,mixed> $liveBody
     * @param list<array<string,mixed>> $owners
     * @param array<string,mixed> $orderFields
     * @param array<string,bool> $liveIds
     */
    private function writeGreenTechAsNew(string $orderNumber, array $afterRows, array $beforeRows, array $liveBody, array $owners, array $orderFields, string $designation, array $liveIds): array
    {
        $types = array_keys(self::houseworkBases($afterRows));
        $designation = trim((string) ($liveBody['Comments'] ?? '')) !== '' && trim($designation) === '' ? trim((string) $liveBody['Comments']) : trim($designation);
        if ($designation === '') {
            throw new Exception(self::newTypeUiMessage('fastighetsbeteckning saknas', $types));
        }
        $applicants = [];
        $seen = [];
        foreach ($owners as $owner) {
            $name = is_array($owner) ? trim((string) ($owner['name'] ?? '')) : '';
            $pnr = is_array($owner) ? trim((string) ($owner['personnummer'] ?? '')) : '';
            $key = self::ownerSeenKey($name, $pnr);
            if ($name === '' || $pnr === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $applicants[] = ['name' => $name, 'personnummer' => $pnr];
        }
        if ($applicants === []) {
            return ['applies' => false];
        }
        $bases = self::houseworkBases($afterRows);
        $splits = self::greenTechSplits($bases, count($applicants), self::greenTechPossible($bases, count($applicants)));
        $path = '/orders/' . rawurlencode($orderNumber);
        $beforeType = strtolower(trim((string) ($liveBody['TaxReductionType'] ?? 'none')));
        try {
            $body = $orderFields;
            unset($body['OrderRows']);
            $body['TaxReductionType'] = 'green';
            $body['OrderRows'] = self::cleanOrderRows($afterRows, false, $liveIds);
            $this->request('PUT', $path, ['Order' => $body]);
            $posted = 0;
            foreach ($applicants as $i => $person) {
                $amounts = self::positiveTaxAmounts(is_array($splits[$i] ?? null) ? $splits[$i] : []);
                if ($amounts === []) {
                    continue;
                }
                $this->request('POST', '/taxreductions', [
                    'TaxReduction' => [
                        'CustomerName' => $person['name'],
                        'SocialSecurityNumber' => $person['personnummer'],
                        'PropertyDesignation' => $designation,
                        'ReferenceDocumentType' => 'ORDER',
                        'ReferenceNumber' => $orderNumber,
                        'TaxReductionAmounts' => $amounts,
                    ],
                ]);
                $posted++;
            }
            if ($posted === 0) {
                throw new Exception('Ingen ansökan kunde skapas.');
            }
        } catch (Throwable $e) {
            $errors = [];
            try {
                foreach ($this->listTaxReductionsForOrder($orderNumber, $this->getOrder($orderNumber)) as $taxRow) {
                    $id = is_array($taxRow) ? trim((string) ($taxRow['Id'] ?? $taxRow['id'] ?? '')) : '';
                    if ($id !== '') {
                        $this->request('DELETE', '/taxreductions/' . rawurlencode($id));
                    }
                }
            } catch (Throwable $e2) {
                $errors[] = $e2->getMessage();
            }
            try {
                $this->request('PUT', $path, ['Order' => ['TaxReductionType' => $beforeType !== '' ? $beforeType : 'none', 'OrderRows' => self::cleanOrderRows($beforeRows)]]);
            } catch (Throwable $e2) {
                $errors[] = 'raderna: ' . $e2->getMessage();
            }
            appLog('fortnox_gt_new_type_failed', ['account' => $this->account, 'orderNumber' => $orderNumber, 'types' => $types, 'error' => $e->getMessage(), 'restoreError' => implode('; ', $errors)]);
            throw new Exception(self::newTypeUiMessage($e->getMessage(), $types, implode('; ', $errors)));
        }
        return [
            'applies' => true,
            'order' => $this->getOrder($orderNumber),
            'asked' => (int) array_sum(self::greenTechPerTypeFromSplits($splits)),
            'types' => $types,
            'splits' => $splits,
        ];
    }

    /**
     * Rader utan fält som Fortnox räknar fram själv.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function cleanOrderRows(array $rows, bool $newRows = false, ?array $liveIds = null): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            unset($row['Total'], $row['ContributionPercent'], $row['ContributionValue']);
            $id = (string) ($row['RowId'] ?? '');
            // Bara skrivbara fält (live 23:08: «Fältet ReservedQuantity är
            // endast läsbart»). RowId bara när raden finns kvar på ordern.
            $keepId = !$newRows && $id !== '' && ($liveIds === null || isset($liveIds[$id]));
            $row = array_intersect_key($row, array_flip(self::WRITABLE_ROW_FIELDS));
            if ($keepId) {
                $row = ['RowId' => is_numeric($id) ? (int) $id : $id] + $row;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Tillbaka till raderna och ansökningarna före omskrivningen.
     * Tom sträng när allt stämmer igen, annars vad som inte gick.
     *
     * @param list<array<string,mixed>> $beforeRows
     * @param list<array{id?:string,name:string,personnummer:string,designation:string,key:string,amounts:list<array{WorkType:string,AskedAmount:int}>}> $snapshot
     * @param array{unmarked:bool,rows_written:bool} $state
     */
    private function restoreGreenTechRewrite(string $orderNumber, array $beforeRows, array $snapshot, array $state): string
    {
        $errors = [];
        $path = '/orders/' . rawurlencode($orderNumber);
        $current = [];
        try {
            [$current] = $this->hydrateTaxReductions($this->listTaxReductionsForOrder($orderNumber, $this->getOrder($orderNumber)));
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
        if ($state['rows_written'] || $state['unmarked']) {
            $body = ['OrderRows' => self::cleanOrderRows($beforeRows, !empty($state['rows_dropped']))];
            if ($state['unmarked']) {
                $body['TaxReductionType'] = 'green';
            }
            try {
                $this->request('PUT', $path, ['Order' => $body]);
            } catch (Throwable $e) {
                $errors[] = 'raderna: ' . $e->getMessage();
            }
        }
        $wanted = [];
        foreach ($snapshot as $person) {
            $wanted[$person['key']] = $person;
        }
        $have = [];
        foreach ($current as $taxRow) {
            if (!is_array($taxRow)) {
                continue;
            }
            $key = self::ownerSeenKey(
                trim((string) ($taxRow['CustomerName'] ?? '')),
                trim((string) ($taxRow['SocialSecurityNumber'] ?? ''))
            );
            $id = trim((string) ($taxRow['Id'] ?? $taxRow['id'] ?? ''));
            if (isset($wanted[$key])) {
                $have[$key] = true;
                if (self::taxAmountMap(self::taxReductionAmountRows($taxRow)) !== self::taxAmountMap($wanted[$key]['amounts'])) {
                    try {
                        $this->putTaxReductionAmounts($taxRow, $wanted[$key]['amounts']);
                    } catch (Throwable $e) {
                        $errors[] = $wanted[$key]['name'] . ': ' . $e->getMessage();
                    }
                }
                continue;
            }
            if ($id !== '') {
                try {
                    $this->request('DELETE', '/taxreductions/' . rawurlencode($id));
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
        foreach ($snapshot as $person) {
            if (isset($have[$person['key']]) || $person['amounts'] === []) {
                continue;
            }
            try {
                $this->request('POST', '/taxreductions', [
                    'TaxReduction' => [
                        'CustomerName' => $person['name'],
                        'SocialSecurityNumber' => $person['personnummer'],
                        'PropertyDesignation' => $person['designation'],
                        'ReferenceDocumentType' => 'ORDER',
                        'ReferenceNumber' => $orderNumber,
                        'TaxReductionAmounts' => $person['amounts'],
                    ],
                ]);
            } catch (Throwable $e) {
                $errors[] = $person['name'] . ': ' . $e->getMessage();
            }
        }
        return implode('; ', array_values(array_unique($errors)));
    }

    /**
     * Läsning för kontroll: orderns summor och rader samt ansökan per person
     * och typ. Personnumrets fyra sista siffror visas inte.
     *
     * @return array<string,mixed>
     */
    public function greenTechReadout(string $orderNumber): array
    {
        $live = $this->getOrder($orderNumber);
        $body = is_array($live['Order'] ?? null) ? $live['Order'] : $live;
        $rows = [];
        foreach (is_array($body['OrderRows'] ?? null) ? $body['OrderRows'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = array_intersect_key($row, array_flip([
                'RowId', 'ArticleNumber', 'Description', 'DeliveredQuantity', 'Price', 'PriceExcludingVAT',
                'Total', 'TotalExcludingVAT', 'VAT', 'HouseWork', 'HouseWorkType', 'HouseWorkHoursToReport',
            ]));
        }
        [$hydrated] = $this->hydrateTaxReductions($this->listTaxReductionsForOrder($orderNumber, $live));
        $people = [];
        foreach ($hydrated as $taxRow) {
            if (!is_array($taxRow)) {
                continue;
            }
            $pnr = trim((string) ($taxRow['SocialSecurityNumber'] ?? ''));
            $people[] = [
                'Id' => (string) ($taxRow['Id'] ?? $taxRow['id'] ?? ''),
                'ReferenceDocumentType' => (string) ($taxRow['ReferenceDocumentType'] ?? ''),
                'ReferenceNumber' => (string) ($taxRow['ReferenceNumber'] ?? ''),
                'CustomerName' => (string) ($taxRow['CustomerName'] ?? ''),
                'SocialSecurityNumber' => $pnr !== '' ? substr($pnr, 0, max(0, strlen($pnr) - 4)) . 'XXXX' : '',
                'PropertyDesignation' => (string) ($taxRow['PropertyDesignation'] ?? ''),
                'AskedAmount' => self::taxReductionAskedSum($taxRow),
                'TaxReductionAmounts' => self::taxReductionAmountRows($taxRow),
            ];
        }
        $articles = [];
        foreach (['charging', 'battery', 'solar', 'material'] as $key) {
            $articles[$key] = trim((string) ($this->articles[$key] ?? ''));
        }
        return [
            'order' => array_intersect_key($body, array_flip([
                'DocumentNumber', 'VATIncluded', 'Net', 'Total', 'TotalVAT', 'RoundOff', 'TaxReduction',
                'TaxReductionType', 'BasisTaxReduction', 'HouseWork', 'TotalToPay', 'Cancelled', 'InvoiceReference',
            ])),
            'rows' => $rows,
            'tax_reductions' => $people,
            'asked_by_type' => self::greenTechAskedByType($hydrated),
            'possible_by_type' => self::greenTechPossibleByType(self::houseworkBases(is_array($body['OrderRows'] ?? null) ? $body['OrderRows'] : [])),
            'articles' => $articles,
        ];
    }

    /**
     * Orderlista (läser bara). $query t.ex. ['fromdate' => 'Y-m-d', 'limit' => 200].
     *
     * @return list<array<string,mixed>>
     */
    public function listOrders(array $query = []): array
    {
        $limit = max(1, min(500, (int) ($query['limit'] ?? 100)));
        $params = ['limit' => $limit, 'sortby' => 'documentnumber', 'sortorder' => 'descending'];
        foreach (['fromdate', 'todate', 'customername', 'customernumber', 'lastmodified'] as $k) {
            if (isset($query[$k]) && trim((string) $query[$k]) !== '') {
                $params[$k] = trim((string) $query[$k]);
            }
        }
        $data = $this->request('GET', '/orders?' . http_build_query($params));
        $rows = $data['Orders'] ?? [];
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    public function getOrder(string $orderNumber): array
    {
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '') {
            throw new Exception('Ordernummer saknas.');
        }
        return $this->request('GET', '/orders/' . rawurlencode($orderNumber));
    }

    /** Sätter leveransdatum på en order, en faktura eller en offert. */
    public function putDeliveryDate(string $kind, string $number, string $ymd): array
    {
        $spec = jeab_fortnox_delivery_date_write($kind, $number, $ymd);
        return $this->request($spec['method'], $spec['path'], $spec['body']);
    }

    /** @param list<array<string,mixed>> $rows */
    public function saveOrderRows(string $orderNumber, array $rows): array
    {
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '') {
            throw new Exception('Ordernummer saknas.');
        }
        $liveIds = null;
        try {
            $live = $this->getOrder($orderNumber);
            $body = is_array($live['Order'] ?? null) ? $live['Order'] : $live;
            $liveIds = [];
            foreach (is_array($body['OrderRows'] ?? null) ? $body['OrderRows'] : [] as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $id = trim((string) ($r['RowId'] ?? ''));
                if ($id !== '' && $id !== '0') {
                    $liveIds[$id] = true;
                }
            }
        } catch (Throwable) {
            $liveIds = null;
        }
        return $this->request('PUT', '/orders/' . rawurlencode($orderNumber), [
            'Order' => ['OrderRows' => self::cleanOrderRows(array_values($rows), false, $liveIds)],
        ]);
    }

    public function getInvoice(string $invoiceNumber): array
    {
        $invoiceNumber = trim($invoiceNumber);
        if ($invoiceNumber === '') {
            throw new Exception('Fakturanummer saknas.');
        }
        return $this->request('GET', '/invoices/' . rawurlencode($invoiceNumber));
    }

    public function getOffer(string $offerNumber): array
    {
        $offerNumber = trim($offerNumber);
        if ($offerNumber === '') {
            throw new Exception('Offertnummer saknas.');
        }
        return $this->request('GET', '/offers/' . rawurlencode($offerNumber));
    }

    /** Skattereduktioner som hör till en faktura (inte ordern). */
    private function listTaxReductionsForInvoice(string $invoiceNumber, array $invoiceResponse): array
    {
        $invoice = is_array($invoiceResponse['Invoice'] ?? null) ? $invoiceResponse['Invoice'] : $invoiceResponse;
        $listUrl = trim((string) ($invoice['@urlTaxReductionList'] ?? ''));
        if ($listUrl !== '') {
            $path = $this->apiPathFromUrl($listUrl);
            if ($path !== '') {
                $out = [];
                foreach ($this->taxReductionsFromResponse($this->request('GET', $path)) as $row) {
                    if (is_array($row) && strtoupper(trim((string) ($row['ReferenceDocumentType'] ?? 'INVOICE'))) === 'INVOICE') {
                        $out[] = $row;
                    }
                }
                return $out;
            }
        }
        $matched = [];
        $page = 1;
        do {
            $data = $this->request('GET', '/taxreductions?filter=invoices&limit=100&page=' . $page);
            foreach ($this->taxReductionsFromResponse($data) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (trim((string) ($row['ReferenceNumber'] ?? '')) === $invoiceNumber
                    && strtoupper(trim((string) ($row['ReferenceDocumentType'] ?? 'INVOICE'))) === 'INVOICE') {
                    $matched[] = $row;
                }
            }
            $meta = is_array($data['MetaInformation'] ?? null) ? $data['MetaInformation'] : [];
            $totalPages = (int) ($meta['@TotalPages'] ?? $meta['TotalPages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= 20);
        return $matched;
    }

    private static function pnrKey(string $pnr): string
    {
        $digits = preg_replace('/\D+/', '', $pnr) ?? '';
        return strlen($digits) === 12 ? substr($digits, 2) : $digits;
    }

    private static function maskPnr(string $pnr): string
    {
        $pnr = trim($pnr);
        return $pnr !== '' ? substr($pnr, 0, max(0, strlen($pnr) - 4)) . 'XXXX' : '';
    }

    /** Låst faktura: bokförd eller makulerad går inte att ändra. */
    public static function invoiceLocked(array $invoice): bool
    {
        $truthy = static fn ($v): bool => $v === true || $v === 1 || $v === '1' || strtolower((string) $v) === 'true';
        return $truthy($invoice['Booked'] ?? false) || $truthy($invoice['Cancelled'] ?? false);
    }

    /**
     * Fakturans grön teknik (bara läsning). Personnummer maskas.
     *
     * @return array<string,mixed>
     */
    public function invoiceGreenTechReadout(string $invoiceNumber): array
    {
        $live = $this->getInvoice($invoiceNumber);
        $body = is_array($live['Invoice'] ?? null) ? $live['Invoice'] : $live;
        [$hydrated] = $this->hydrateTaxReductions($this->listTaxReductionsForInvoice(trim($invoiceNumber), $live));
        $people = [];
        foreach ($hydrated as $taxRow) {
            if (!is_array($taxRow)) {
                continue;
            }
            $people[] = [
                'Id' => (string) ($taxRow['Id'] ?? $taxRow['id'] ?? ''),
                'ReferenceDocumentType' => (string) ($taxRow['ReferenceDocumentType'] ?? ''),
                'ReferenceNumber' => (string) ($taxRow['ReferenceNumber'] ?? ''),
                'CustomerName' => (string) ($taxRow['CustomerName'] ?? ''),
                'SocialSecurityNumber' => self::maskPnr((string) ($taxRow['SocialSecurityNumber'] ?? '')),
                'PropertyDesignation' => (string) ($taxRow['PropertyDesignation'] ?? ''),
                'AskedAmount' => self::taxReductionAskedSum($taxRow),
                'TaxReductionAmounts' => self::taxReductionAmountRows($taxRow),
            ];
        }
        return [
            'invoice' => array_intersect_key($body, array_flip([
                'DocumentNumber', 'OrderReference', 'Booked', 'Cancelled', 'Sent', 'FinalPayDate', 'Balance',
                'Total', 'TotalVAT', 'TaxReduction', 'TaxReductionType', 'BasisTaxReduction', 'HouseWork', 'TotalToPay',
                'InvoiceDate', 'VoucherNumber',
            ])),
            'locked' => self::invoiceLocked($body),
            'tax_reductions' => $people,
        ];
    }

    /**
     * Fakturan ska ha samma sökande och belopp som ordern. Fortnox tar inte med
     * medsökande som läggs till på ordern efter att fakturan skapats, och
     * fakturan kan ha skapats med bara huvudsökanden. Ordern är facit.
     * Låst faktura (bokförd/makulerad) ändras aldrig; planen returneras.
     *
     * @return array<string,mixed>
     */
    public function syncInvoiceTaxReductionsFromOrder(string $orderNumber, string $invoiceNumber, bool $apply = true): array
    {
        $orderNumber = trim($orderNumber);
        $invoiceNumber = trim($invoiceNumber);
        $orderLive = $this->getOrder($orderNumber);
        [$orderTax] = $this->hydrateTaxReductions($this->listTaxReductionsForOrder($orderNumber, $orderLive));
        $invLive = $this->getInvoice($invoiceNumber);
        $invBody = is_array($invLive['Invoice'] ?? null) ? $invLive['Invoice'] : $invLive;
        [$invTax] = $this->hydrateTaxReductions($this->listTaxReductionsForInvoice($invoiceNumber, $invLive));
        $locked = self::invoiceLocked($invBody);
        $byPnr = [];
        foreach ($invTax as $taxRow) {
            if (is_array($taxRow)) {
                $byPnr[self::pnrKey((string) ($taxRow['SocialSecurityNumber'] ?? ''))] = $taxRow;
            }
        }
        $lower = [];
        $raise = [];
        $create = [];
        $wanted = [];
        foreach ($orderTax as $src) {
            if (!is_array($src)) {
                continue;
            }
            $pnr = (string) ($src['SocialSecurityNumber'] ?? '');
            $key = self::pnrKey($pnr);
            if ($key === '') {
                continue;
            }
            $amounts = self::positiveTaxAmounts(self::taxReductionAmountRows($src));
            if ($amounts === []) {
                continue;
            }
            $wanted[$key] = true;
            $label = ['name' => (string) ($src['CustomerName'] ?? ''), 'personnummer' => self::maskPnr($pnr), 'amounts' => $amounts];
            if (!isset($byPnr[$key])) {
                $create[] = $label + ['_src' => $src];
                continue;
            }
            $cur = self::taxAmountMap(self::taxReductionAmountRows($byPnr[$key]));
            $tgt = self::taxAmountMap($amounts);
            if ($cur === $tgt) {
                continue;
            }
            $entry = $label + ['_row' => $byPnr[$key], 'from' => $cur];
            if (array_sum($tgt) <= array_sum($cur)) {
                $lower[] = $entry;
            } else {
                $raise[] = $entry;
            }
        }
        $extra = [];
        foreach ($byPnr as $key => $taxRow) {
            if (!isset($wanted[$key])) {
                $extra[] = ['name' => (string) ($taxRow['CustomerName'] ?? ''), 'personnummer' => self::maskPnr((string) ($taxRow['SocialSecurityNumber'] ?? ''))];
            }
        }
        $strip = static function (array $list): array {
            return array_map(static fn (array $e): array => array_diff_key($e, ['_src' => 1, '_row' => 1]), $list);
        };
        $plan = [
            'fortnox_order' => $orderNumber,
            'fortnox_invoice' => $invoiceNumber,
            'locked' => $locked,
            'booked' => (bool) ($invBody['Booked'] ?? false),
            'cancelled' => (bool) ($invBody['Cancelled'] ?? false),
            'create' => $strip($create),
            'update' => $strip(array_merge($lower, $raise)),
            'not_on_order' => $extra,
            'in_sync' => $create === [] && $lower === [] && $raise === [],
            'applied' => false,
        ];
        if ($orderTax === [] || $plan['in_sync'] || $locked || !$apply) {
            return $plan;
        }
        // Sänk först, skapa sedan, höj sist: summan per typ får aldrig överskrida möjligt belopp.
        foreach ($lower as $e) {
            $this->putTaxReductionAmounts($e['_row'], $e['amounts']);
        }
        foreach ($create as $e) {
            $src = $e['_src'];
            $this->request('POST', '/taxreductions', [
                'TaxReduction' => [
                    'CustomerName' => (string) ($src['CustomerName'] ?? ''),
                    'SocialSecurityNumber' => (string) ($src['SocialSecurityNumber'] ?? ''),
                    'PropertyDesignation' => (string) ($src['PropertyDesignation'] ?? ''),
                    'ReferenceDocumentType' => 'INVOICE',
                    'ReferenceNumber' => $invoiceNumber,
                    'TaxReductionAmounts' => $e['amounts'],
                ],
            ]);
        }
        foreach ($raise as $e) {
            $this->putTaxReductionAmounts($e['_row'], $e['amounts']);
        }
        $plan['applied'] = true;
        $plan['after'] = $this->invoiceGreenTechReadout($invoiceNumber);
        return $plan;
    }

    /**
     * Hela fakturan krediteras (PUT /invoices/{n}/credit).
     * Svaret är den nya kreditfakturan, och debetfakturans CreditInvoiceReference
     * pekar på den. Fortnox tillåter en sådan kredit per debetfaktura.
     */
    public function creditInvoice(string $invoiceNumber): array
    {
        $invoiceNumber = trim($invoiceNumber);
        if ($invoiceNumber === '') {
            throw new Exception('Fakturanummer saknas.');
        }
        return $this->request('PUT', '/invoices/' . rawurlencode($invoiceNumber) . '/credit');
    }

    /** Skriver om en faktura. Anropas på kreditfakturan, aldrig på debetfakturan. */
    public function updateInvoice(string $invoiceNumber, array $invoice): array
    {
        $invoiceNumber = trim($invoiceNumber);
        if ($invoiceNumber === '') {
            throw new Exception('Fakturanummer saknas.');
        }
        return $this->request('PUT', '/invoices/' . rawurlencode($invoiceNumber), [
            'Invoice' => $invoice,
        ]);
    }

    public function createInvoice(array $invoice): array
    {
        return $this->request('POST', '/invoices', [
            'Invoice' => $invoice,
        ]);
    }

    /** Nytt utkast. Bokför inte och skickar inte. */
    public function createDraftInvoice(array $invoice): array
    {
        foreach (['Booked', 'Sent', 'Cancelled', 'EmailInformation', 'PrintTemplate', 'EDIInformation', 'WayOfDelivery'] as $drop) {
            unset($invoice[$drop]);
        }
        return $this->createInvoice($invoice);
    }

    /** Makulerar en faktura. Bokför inte och skickar inte. */
    public function cancelInvoice(string $invoiceNumber): array
    {
        $invoiceNumber = trim($invoiceNumber);
        if ($invoiceNumber === '') {
            throw new Exception('Fakturanummer saknas.');
        }
        return $this->request('PUT', '/invoices/' . rawurlencode($invoiceNumber) . '/cancel');
    }

    /**
     * Tar bort skattereduktioner som hör till just denna faktura.
     * Rader vars ReferenceNumber pekar på en annan faktura lämnas kvar.
     *
     * @return list<string> raderade id
     */
    public function deleteInvoiceTaxReductions(string $invoiceNumber): array
    {
        $invoiceNumber = trim($invoiceNumber);
        if ($invoiceNumber === '') {
            return [];
        }
        $invoice = $this->getInvoice($invoiceNumber);
        $deleted = [];
        foreach ($this->listTaxReductionsForInvoice($invoiceNumber, $invoice) as $taxRow) {
            if (!is_array($taxRow)) {
                continue;
            }
            $id = trim((string) ($taxRow['Id'] ?? $taxRow['id'] ?? ''));
            $ref = trim((string) ($taxRow['ReferenceNumber'] ?? ''));
            if ($id === '') {
                continue;
            }
            if ($ref !== '' && $ref !== $invoiceNumber) {
                continue;
            }
            $this->request('DELETE', '/taxreductions/' . rawurlencode($id));
            $deleted[] = $id;
        }
        return $deleted;
    }

    public function createInvoiceFromOrder(string $orderNumber): array
    {
        $orderNumber = trim($orderNumber);
        if ($orderNumber === '') {
            throw new Exception('Ordernummer saknas.');
        }
        return $this->request('PUT', '/orders/' . rawurlencode($orderNumber) . '/createinvoice');
    }

    public static function houseWorkHoursFor(string $hw): int
    {
        return self::houseWorkHours($hw);
    }

    /** Läser ett kundkort. Organisationsnumret lämnas kvar i svaret och loggas inte. */
    public function getCustomer(string $number): array
    {
        $number = trim($number);
        if ($number === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,30}$/', $number) !== 1) {
            throw new InvalidArgumentException('Kundnummer saknas.');
        }
        $data = $this->request('GET', '/customers/' . rawurlencode($number));
        $customer = $data['Customer'] ?? $data;
        return is_array($customer) ? $customer : [];
    }

    /** Obokförda och bokförda fakturor för en kund. Bara läsning. */
    public function listInvoicesForCustomer(string $customerNumber): array
    {
        $customerNumber = trim($customerNumber);
        if ($customerNumber === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,30}$/', $customerNumber) !== 1) {
            throw new InvalidArgumentException('Kundnummer saknas.');
        }
        return $this->listAll('/invoices', 'Invoices', ['customernumber' => $customerNumber], 0);
    }

    /** Skriver bara organisationsnumret, normaliserat. Inga andra fält. */
    public function updateCustomerOrganisationNumber(string $number, string $organisationNumber): void
    {
        $number = trim($number);
        if ($number === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,30}$/', $number) !== 1) {
            throw new InvalidArgumentException('Kundnummer saknas.');
        }
        $this->request('PUT', '/customers/' . rawurlencode($number), [
            'Customer' => [
                'OrganisationNumber' => jeab_orgnr_normalize($organisationNumber),
            ],
        ]);
    }

    /** Read-only: hämtar alla sidor av en Fortnox-lista (t.ex. /customers → Customers). */
    public function listAll(string $path, string $key, array $query = [], int $sleepUs = 250000): array
    {
        $out = [];
        $page = 1;
        do {
            $q = http_build_query($query + ['limit' => 500, 'page' => $page]);
            $data = $this->request('GET', $path . '?' . $q);
            foreach ((array) ($data[$key] ?? []) as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
            $total = (int) ($data['MetaInformation']['@TotalPages'] ?? 1);
            $page++;
            if ($page <= $total && $sleepUs > 0) {
                usleep($sleepUs);
            }
        } while ($page <= $total && $page < 1000);
        return $out;
    }
}

/** Kundlista per bolag: totalt fakturerat inkl moms i perioden (kredit dras av, makulerade räknas inte). Read-only. */
function jeab_fortnox_customer_totals(string $account, string $from, string $to, int $sleepUs = 250000): array
{
    $api = new FortnoxAPI($account);
    $customers = $api->listAll('/customers', 'Customers', [], $sleepUs);
    usleep($sleepUs);
    $invoices = $api->listAll('/invoices', 'Invoices', ['fromdate' => $from, 'todate' => $to], $sleepUs);
    $rows = [];
    foreach ($customers as $c) {
        $nr = (string) ($c['CustomerNumber'] ?? '');
        if ($nr === '') {
            continue;
        }
        $rows[$nr] = ['kundnr' => $nr, 'namn' => (string) ($c['Name'] ?? ''), 'ort' => (string) ($c['City'] ?? ''),
            'epost' => (string) ($c['Email'] ?? ''), 'total' => 0.0, 'fakturor' => 0];
    }
    $cancelled = 0;
    foreach ($invoices as $inv) {
        $date = (string) ($inv['InvoiceDate'] ?? '');
        if ($date !== '' && ($date < $from || $date > $to)) {
            continue;
        }
        $c = $inv['Cancelled'] ?? false;
        if ($c === true || $c === 'true' || $c === 1) {
            $cancelled++;
            continue;
        }
        $amount = (float) ($inv['Total'] ?? 0);
        $rate = (float) ($inv['CurrencyRate'] ?? 1);
        if (strtoupper((string) ($inv['Currency'] ?? 'SEK')) !== 'SEK' && $rate > 0) {
            $amount *= $rate;
        }
        $cr = $inv['Credit'] ?? false;
        if ($cr === true || $cr === 'true' || $cr === 1) {
            $amount = -abs($amount);
        }
        $nr = (string) ($inv['CustomerNumber'] ?? '');
        if (!isset($rows[$nr])) {
            $rows[$nr] = ['kundnr' => $nr, 'namn' => (string) ($inv['CustomerName'] ?? ''), 'ort' => (string) ($inv['City'] ?? ''), 'epost' => '', 'total' => 0.0, 'fakturor' => 0];
        }
        $rows[$nr]['total'] += $amount;
        $rows[$nr]['fakturor']++;
    }
    $list = array_values($rows);
    foreach ($list as &$r) {
        $r['total'] = round($r['total'], 2);
    }
    unset($r);
    usort($list, fn ($a, $b) => $b['total'] <=> $a['total']);
    return ['account' => $account, 'from' => $from, 'to' => $to, 'kunder' => count($customers), 'fakturor' => count($invoices), 'makulerade' => $cancelled, 'rows' => $list];
}
