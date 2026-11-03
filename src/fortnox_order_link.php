<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/fortnox.php';
require_once __DIR__ . '/fortnox_leveransdatum.php';

/**
 * Lokala Fortnox-kopplingar. Rör inte Fortnox vid ompekning.
 *
 * POST /hook.php  (samma grind som övriga fortnox.*)
 * {"action":"fortnox.order_relink","account":"JEAB","order_id":"102","invoice_number":"13606","from_invoice":"13605","dry":0,"confirm":"SAM"}
 * {"action":"fortnox.order_attach","account":"JEAB","order_id":"102","fortnox_order":"900","invoice_number":"13606","dry":0,"confirm":"SAM"}
 * {"action":"fortnox.order_attach_undo","account":"JEAB","order_id":"102","dry":0,"confirm":"SAM"}
 * {"action":"fortnox.invoice_order_lookup","account":"JEAB","invoice_number":"13592"}
 * {"action":"fortnox.invoice_compare","account":"JEAB","invoices":["13593"],"order_id":"102"}
 * {"action":"fortnox.orders_read","co":"jarfalla","order_numbers":["1904"],"customer_number":"K9","jeab_order":"40"}
 * action orders_read räknas som samma läsning. Tom action med type, event eller query-action
 * fortnox.orders_read eller orders_read också. Andra fortnox-åtgärder styrs inte om.
 *
 * order_relink: dry=1 är standard. dry=0 kräver confirm=SAM.
 * Skriver bara fortnox_invoice på ordern och en rad i orderns logg. Idempotent.
 * invoice_order_lookup är bara läsning och returnerar orsaker, inga personuppgifter.
 */

function jeab_fortnox_order_link_doc(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '0' || preg_match('/\A[0-9]{1,12}\z/', $raw) !== 1) {
        return '';
    }
    return $raw;
}

function jeab_fortnox_order_link_id(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '0' || preg_match('/\A[A-Za-z0-9_-]{1,40}\z/', $raw) !== 1) {
        return '';
    }
    return $raw;
}

function jeab_fortnox_order_link_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];
    return !($dry === false || $dry === 0 || $dry === '0' || $dry === 0.0);
}

function jeab_fortnox_order_link_invoice_of(array $row): string
{
    foreach (['fortnox_invoice', 'invoice_number', 'invoice', 'fortnox_invoice_number'] as $key) {
        $value = jeab_fortnox_order_link_doc((string) ($row[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_fortnox_order_link_dispatch(array $input): array
{
    $action = trim((string) ($input['action'] ?? ''));
    if ($action === 'fortnox.order_relink') {
        return jeab_fortnox_order_relink($input);
    }
    if ($action === 'fortnox.invoice_order_lookup') {
        return jeab_fortnox_invoice_order_lookup($input);
    }
    if ($action === 'fortnox.invoice_compare') {
        return jeab_fortnox_invoice_compare($input);
    }
    if ($action === 'fortnox.order_export') {
        return jeab_fortnox_order_export($input);
    }
    if ($action === 'fortnox.orders_read') {
        return jeab_fortnox_orders_read($input);
    }
    if ($action === 'fortnox.order_attach') {
        return jeab_fortnox_order_attach($input);
    }
    if ($action === 'fortnox.order_attach_undo') {
        return jeab_fortnox_order_attach_undo($input);
    }
    throw new InvalidArgumentException('Okänd åtgärd.');
}

/**
 * Bara GET. Namn, personnummer och adress lämnas ute.
 * order_numbers och/eller customer_number. jeab_order lägger till fortnox_order_audit.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_fortnox_orders_read(array $input): array
{
    $account = jeab_fortnox_leveransdatum_account((string) ($input['co'] ?? $input['account'] ?? ''));
    $company = $account === 'Malmo' ? 'malmo' : 'jarfalla';
    $numbers = jeab_fortnox_orders_read_numbers($input['order_numbers'] ?? []);
    $customer = jeab_fortnox_orders_read_customer((string) ($input['customer_number'] ?? ''));
    $jeabOrder = jeab_fortnox_orders_read_jeab_order($input['jeab_order'] ?? null);
    if ($numbers === [] && $customer === '' && $jeabOrder === null) {
        throw new InvalidArgumentException('order_numbers, customer_number eller jeab_order saknas.');
    }
    $audit = $jeabOrder === null ? null : jeab_fortnox_orders_read_audit($jeabOrder);
    $base = [
        'ok' => true,
        'action' => 'fortnox.orders_read',
        'co' => $company,
        'read_only' => true,
        'orders' => [],
    ];
    if ($jeabOrder !== null) {
        $base['jeab_order'] = $jeabOrder;
        $base['audit'] = $audit;
    }
    if ($numbers === [] && $customer === '') {
        return $base;
    }
    if (!jeab_fortnox_leveransdatum_may_call()) {
        return $base + [
            'ok' => false,
            'error' => 'Fortnox är inte kopplad.',
        ];
    }
    $base['orders'] = jeab_fortnox_orders_read_fetch($account, $numbers, $customer);

    return $base;
}

/**
 * @param mixed $value
 * @return list<string>
 */
function jeab_fortnox_orders_read_numbers(mixed $value): array
{
    if ($value === null || $value === '' || $value === []) {
        return [];
    }
    if (is_string($value)) {
        $value = preg_split('/\s*,\s*/', trim($value)) ?: [];
    }
    if (!is_array($value)) {
        throw new InvalidArgumentException('order_numbers ska vara en lista.');
    }
    $out = [];
    foreach ($value as $item) {
        if (!is_scalar($item)) {
            throw new InvalidArgumentException('order_numbers ska vara ordernummer.');
        }
        $no = jeab_fortnox_order_link_doc(trim((string) $item));
        if ($no === '') {
            throw new InvalidArgumentException('order_numbers ska vara ordernummer.');
        }
        if (!in_array($no, $out, true)) {
            $out[] = $no;
        }
    }
    if (count($out) > 20) {
        throw new InvalidArgumentException('Högst 20 ordernummer.');
    }

    return $out;
}

function jeab_fortnox_orders_read_customer(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $id = jeab_fortnox_order_link_id($raw);
    if ($id === '') {
        throw new InvalidArgumentException('customer_number ska vara ett kundnummer.');
    }

    return $id;
}

function jeab_fortnox_orders_read_jeab_order(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }
    if (preg_match('/\A[1-9]\d{0,8}\z/', $text) !== 1) {
        throw new InvalidArgumentException('jeab_order ska vara ett order-id.');
    }

    return (int) $text;
}

/**
 * @param list<string> $numbers
 * @return list<array<string,mixed>>
 */
function jeab_fortnox_orders_read_fetch(string $account, array $numbers, string $customer): array
{
    $hadGetOnly = array_key_exists('_JEAB_FORTNOX_GET_ONLY', $GLOBALS);
    $prevGetOnly = $GLOBALS['_JEAB_FORTNOX_GET_ONLY'] ?? null;
    $GLOBALS['_JEAB_FORTNOX_GET_ONLY'] = true;
    try {
        $api = new FortnoxAPI($account);
        if ($customer !== '') {
            foreach ($api->listOrders(['customernumber' => $customer, 'limit' => 20]) as $row) {
                if (count($numbers) >= 20) {
                    break;
                }
                $no = jeab_fortnox_order_link_doc((string) ($row['DocumentNumber'] ?? ''));
                if ($no !== '' && !in_array($no, $numbers, true)) {
                    $numbers[] = $no;
                }
            }
        }
        $out = [];
        foreach ($numbers as $no) {
            try {
                $out[] = jeab_fortnox_orders_read_project($api->getOrder($no), $no);
            } catch (Throwable $e) {
                $info = jeab_fortnox_leveransdatum_error_info($e);
                $out[] = [
                    'DocumentNumber' => $no,
                    'error' => $info['message'] !== '' ? $info['message'] : 'Fortnox-fel.',
                ];
            }
        }

        return $out;
    } finally {
        if ($hadGetOnly) {
            $GLOBALS['_JEAB_FORTNOX_GET_ONLY'] = $prevGetOnly;
        } else {
            unset($GLOBALS['_JEAB_FORTNOX_GET_ONLY']);
        }
    }
}

/**
 * @param array<string,mixed> $live
 * @return array<string,mixed>
 */
function jeab_fortnox_orders_read_project(array $live, string $fallbackNo): array
{
    $body = isset($live['Order']) && is_array($live['Order']) ? $live['Order'] : $live;
    $count = 0;
    foreach (is_array($body['OrderRows'] ?? null) ? $body['OrderRows'] : [] as $row) {
        if (is_array($row)) {
            $count++;
        }
    }
    $doc = jeab_fortnox_order_link_doc((string) ($body['DocumentNumber'] ?? ''));
    $day = static function (mixed $value): string {
        $text = trim((string) $value);

        return preg_match('/\A\d{4}-\d{2}-\d{2}/', $text) === 1 ? substr($text, 0, 10) : '';
    };
    $total = $body['Total'] ?? '';
    if (!is_int($total) && !is_float($total)) {
        $text = trim((string) $total);
        $total = is_numeric($text) ? $text + 0 : '';
    }

    return [
        'DocumentNumber' => $doc !== '' ? $doc : $fallbackNo,
        'CustomerNumber' => jeab_fortnox_order_link_id((string) ($body['CustomerNumber'] ?? '')),
        'OrderDate' => $day($body['OrderDate'] ?? ''),
        'DeliveryDate' => $day($body['DeliveryDate'] ?? ''),
        'Total' => $total,
        'Cancelled' => jeab_fortnox_leveransdatum_truthy($body['Cancelled'] ?? false),
        'WarehouseReady' => jeab_fortnox_leveransdatum_truthy($body['WarehouseReady'] ?? false),
        'InvoiceReference' => trim((string) ($body['InvoiceReference'] ?? '')),
        'YourOrderNumber' => trim((string) ($body['YourOrderNumber'] ?? '')),
        'ExternalInvoiceReference1' => trim((string) ($body['ExternalInvoiceReference1'] ?? '')),
        'ExternalInvoiceReference2' => trim((string) ($body['ExternalInvoiceReference2'] ?? '')),
        'rows' => $count,
    ];
}

/** @return list<array{created_at:string,fortnox_order:string,reason:string,message:string}> */
function jeab_fortnox_orders_read_audit(int $reminderId): array
{
    $pdo = $GLOBALS['_JEAB_FORTNOX_ORDER_AUDIT_PDO'] ?? null;
    if (!$pdo instanceof PDO && function_exists('jeab_purchase_sql_pdo')) {
        $pdo = jeab_purchase_sql_pdo();
    }
    if (!$pdo instanceof PDO) {
        return [];
    }
    if (!function_exists('jeab_ensure_fortnox_order_audit')) {
        require_once __DIR__ . '/db.php';
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
    jeab_ensure_fortnox_order_audit($pdo, $driver);
    $stmt = $pdo->prepare(
        'SELECT created_at, fortnox_order, reason, message FROM fortnox_order_audit WHERE reminder_id = ? ORDER BY id'
    );
    $stmt->execute([$reminderId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $out[] = [
            'created_at' => trim((string) ($row['created_at'] ?? '')),
            'fortnox_order' => trim((string) ($row['fortnox_order'] ?? '')),
            'reason' => trim((string) ($row['reason'] ?? '')),
            'message' => trim((string) ($row['message'] ?? '')),
        ];
    }

    return $out;
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_fortnox_order_relink(array $input): array
{
    $account = jeab_fortnox_leveransdatum_account((string) ($input['account'] ?? ''));
    $orderId = jeab_fortnox_order_link_doc((string) ($input['order_id'] ?? ''));
    $target = jeab_fortnox_order_link_doc((string) ($input['invoice_number'] ?? ''));
    $from = trim((string) ($input['from_invoice'] ?? ''));
    if ($from !== '') {
        $from = jeab_fortnox_order_link_doc($from);
        if ($from === '') {
            throw new InvalidArgumentException('from_invoice ska vara ett fakturanummer.');
        }
    }
    if ($orderId === '' || $target === '') {
        throw new InvalidArgumentException('order_id och invoice_number ska vara nummer.');
    }
    $dry = jeab_fortnox_order_link_dry($input);
    if (!$dry && (string) ($input['confirm'] ?? '') !== 'SAM') {
        throw new InvalidArgumentException('Bekräftelse saknas.');
    }
    $company = $account === 'Malmo' ? 'malmo' : 'jarfalla';
    $row = jeab_purchase_get((int) $orderId);
    if (!is_array($row) || jeab_fortnox_leveransdatum_row_company($row) !== $company) {
        return [
            'ok' => false,
            'status' => 'fel',
            'dry' => $dry ? 1 : 0,
            'account' => $account,
            'order_id' => $orderId,
            'error' => 'Ordern finns inte.',
        ];
    }
    $current = jeab_fortnox_order_link_invoice_of($row);
    $holder = jeab_fortnox_order_link_holder($company, $target, $orderId);
    $base = [
        'ok' => true,
        'action' => 'fortnox.order_relink',
        'dry' => $dry ? 1 : 0,
        'account' => $account,
        'order_id' => $orderId,
        'from_invoice' => $current,
        'invoice_number' => $target,
    ];
    if ($holder !== '') {
        return array_merge($base, [
            'ok' => false,
            'status' => 'fel',
            'error' => 'Fakturan pekas redan ut av en annan order.',
            'other_order_id' => $holder,
        ]);
    }
    if ($current === $target) {
        return $base + ['status' => 'oforandrat'];
    }
    if ($from !== '' && $current !== $from) {
        return array_merge($base, [
            'ok' => false,
            'status' => 'fel',
            'error' => 'Ordern pekar på en annan faktura.',
        ]);
    }
    if ($dry) {
        return $base + ['status' => 'skulle_byta'];
    }
    $saved = jeab_purchase_patch((int) $orderId, static function (array $fresh) use ($target, $from): ?array {
        $now = jeab_fortnox_order_link_invoice_of($fresh);
        if ($now === $target) {
            return null;
        }
        if ($from !== '' && $now !== $from) {
            return null;
        }
        $fresh['fortnox_invoice'] = $target;
        $text = 'Fortnox-faktura ' . ($now !== '' ? $now . ' bytt till ' : 'satt till ') . $target;
        if (function_exists('jeab_ordrar_activity_append')) {
            return jeab_ordrar_activity_append($fresh, $text, '', 'fortnox-relink:' . $target, false);
        }
        $list = is_array($fresh['log'] ?? null) ? $fresh['log'] : [];
        $list[] = [
            'at' => function_exists('jeab_now') ? jeab_now() : gmdate('Y-m-d H:i:s'),
            'text' => $text,
            'key' => 'fortnox-relink:' . $target,
        ];
        $fresh['log'] = $list;
        $fresh['activity'] = $list;
        return $fresh;
    });
    $after = is_array($saved) ? jeab_fortnox_order_link_invoice_of($saved) : '';
    if ($after !== $target) {
        return array_merge($base, [
            'ok' => false,
            'status' => 'fel',
            'error' => 'Ordern pekar på en annan faktura.',
            'from_invoice' => $after !== '' ? $after : $current,
        ]);
    }
    if (function_exists('appLog')) {
        appLog('fortnox_invoice_relink', [
            'account' => $account,
            'order' => $orderId,
            'from' => $current,
            'to' => $target,
        ]);
    }
    return $base + ['status' => 'bytt'];
}

function jeab_fortnox_order_link_holder(string $company, string $invoice, string $exceptOrderId): string
{
    foreach (jeab_purchase_all() as $row) {
        if (!is_array($row) || jeab_fortnox_leveransdatum_row_company($row) !== $company) {
            continue;
        }
        $id = (string) (int) ($row['reminder_id'] ?? 0);
        if ($id === $exceptOrderId || $id === '0') {
            continue;
        }
        if (jeab_fortnox_order_link_invoice_of($row) === $invoice) {
            return $id;
        }
    }
    return '';
}

function jeab_fortnox_order_attach_locked(array $row): bool
{
    $flag = $row['fortnox_attached'] ?? null;

    return $flag === true || $flag === 1 || $flag === '1';
}

function jeab_fortnox_order_attach_export_error(): string
{
    return 'Ordern är kopplad till Fortnox. Ingen export.';
}

/** Inloggad app-admin via POST. Kundtjänst är den inkommande hook-token, Bokmalen det scoped token. */
function jeab_fortnox_order_attach_admin_post(): bool
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        return false;
    }

    return function_exists('jeab_auth_ok')
        && jeab_auth_ok()
        && function_exists('jeab_is_admin')
        && jeab_is_admin();
}

/**
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
/**
 * @param array<string,mixed> $base
 * @param array<string,mixed> $extra
 * @return array<string,mixed>
 */
function jeab_fortnox_order_attach_result(array $base, array $extra): array
{
    return jeab_fortnox_order_attach_out(array_merge($base, $extra));
}

function jeab_fortnox_order_attach_out(array $payload): array
{
    $clean = function_exists('jeab_ordrar_scrub_pnr') ? jeab_ordrar_scrub_pnr($payload) : $payload;
    if (!is_array($clean)) {
        return ['ok' => false, 'error' => 'Fortnox-fel.'];
    }
    foreach (['personnummer', 'email', 'address', 'customer', 'CustomerName', 'OrganisationNumber', 'Comments', 'huvudsokande'] as $key) {
        unset($clean[$key]);
    }

    return $clean;
}

function jeab_fortnox_order_attach_customer_of(array $row): string
{
    return jeab_fortnox_order_link_id((string) ($row['fortnox_customer'] ?? ''));
}

function jeab_fortnox_order_attach_order_of(array $row): string
{
    return jeab_fortnox_order_link_doc((string) ($row['fortnox_order'] ?? ''));
}

function jeab_fortnox_order_attach_holder(string $company, string $field, string $value, string $exceptOrderId): string
{
    if ($value === '') {
        return '';
    }
    foreach (jeab_purchase_all() as $row) {
        if (!is_array($row) || jeab_fortnox_leveransdatum_row_company($row) !== $company) {
            continue;
        }
        $id = (string) (int) ($row['reminder_id'] ?? 0);
        if ($id === $exceptOrderId || $id === '0') {
            continue;
        }
        $have = $field === 'fortnox_invoice'
            ? jeab_fortnox_order_link_invoice_of($row)
            : jeab_fortnox_order_attach_order_of($row);
        if ($have === $value) {
            return $id;
        }
    }

    return '';
}

/**
 * @param array<string,mixed> $live
 * @return array<string,mixed>
 */
function jeab_fortnox_order_attach_document(array $live, string $key): array
{
    if (isset($live[$key]) && is_array($live[$key])) {
        return $live[$key];
    }

    return $live;
}

/**
 * @return array{ok:bool,error:string,customer:string,error_information:?array{message:string,code:int}}
 */
function jeab_fortnox_order_attach_read(string $account, string $invoice, string $fortnoxOrder): array
{
    $fail = static function (string $error, ?array $info = null): array {
        return [
            'ok' => false,
            'error' => $error,
            'customer' => '',
            'error_information' => $info,
        ];
    };
    if (!jeab_fortnox_leveransdatum_may_call()) {
        return $fail('Fortnox är inte kopplad.', ['message' => 'Fortnox är inte kopplad.', 'code' => 0]);
    }
    $hadGetOnly = array_key_exists('_JEAB_FORTNOX_GET_ONLY', $GLOBALS);
    $prevGetOnly = $GLOBALS['_JEAB_FORTNOX_GET_ONLY'] ?? null;
    $GLOBALS['_JEAB_FORTNOX_GET_ONLY'] = true;
    try {
        $api = new FortnoxAPI($account);
        $invoiceLive = $api->getInvoice($invoice);
        $orderLive = $api->getOrder($fortnoxOrder);
    } catch (Throwable $e) {
        $info = jeab_fortnox_leveransdatum_error_info($e);

        return $fail($info['message'] !== '' ? $info['message'] : 'Fortnox-fel.', $info);
    } finally {
        if ($hadGetOnly) {
            $GLOBALS['_JEAB_FORTNOX_GET_ONLY'] = $prevGetOnly;
        } else {
            unset($GLOBALS['_JEAB_FORTNOX_GET_ONLY']);
        }
    }
    $invoiceBody = jeab_fortnox_order_attach_document(is_array($invoiceLive) ? $invoiceLive : [], 'Invoice');
    $orderBody = jeab_fortnox_order_attach_document(is_array($orderLive) ? $orderLive : [], 'Order');
    $invoiceOrder = jeab_fortnox_order_link_doc((string) ($invoiceBody['OrderReference'] ?? ''));
    $orderNo = jeab_fortnox_order_link_doc((string) ($orderBody['DocumentNumber'] ?? ''));
    $orderInvoice = jeab_fortnox_order_link_doc((string) ($orderBody['InvoiceReference'] ?? ''));
    if ($invoiceOrder !== $fortnoxOrder || $orderNo !== $fortnoxOrder || ($orderInvoice !== '' && $orderInvoice !== $invoice)) {
        return $fail('Fakturan hör inte till Fortnox-ordern.');
    }
    $invoiceCustomer = jeab_fortnox_order_link_id((string) ($invoiceBody['CustomerNumber'] ?? ''));
    $orderCustomer = jeab_fortnox_order_link_id((string) ($orderBody['CustomerNumber'] ?? ''));
    if ($invoiceCustomer === '' || $orderCustomer === '' || $invoiceCustomer !== $orderCustomer) {
        return $fail('Fakturan och ordern har olika kund.');
    }

    return [
        'ok' => true,
        'error' => '',
        'customer' => $invoiceCustomer,
        'error_information' => null,
    ];
}

function jeab_fortnox_order_attach_step(array $row, string $key): string
{
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];

    return trim((string) ($steps[$key] ?? ''));
}

/**
 * @param array<string,mixed> $input
 * @param array<string,mixed>|null $row
 */
function jeab_fortnox_order_attach_account(array $input, ?array $row): string
{
    $raw = trim((string) ($input['account'] ?? ''));
    if ($raw !== '') {
        return jeab_fortnox_leveransdatum_account($raw);
    }
    if (is_array($row) && function_exists('jeab_fortnox_leveransdatum_row_company') && jeab_fortnox_leveransdatum_row_company($row) === 'malmo') {
        return 'Malmo';
    }

    return 'Jarfalla';
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_fortnox_order_attach(array $input): array
{
    $orderId = jeab_fortnox_order_link_doc((string) ($input['order_id'] ?? ''));
    $fortnoxOrder = jeab_fortnox_order_link_doc((string) ($input['fortnox_order'] ?? ''));
    $invoice = jeab_fortnox_order_link_doc((string) ($input['invoice_number'] ?? ''));
    if ($orderId === '' || $fortnoxOrder === '' || $invoice === '') {
        throw new InvalidArgumentException('order_id, fortnox_order och invoice_number ska vara nummer.');
    }
    $dry = jeab_fortnox_order_link_dry($input);
    if (!$dry && (string) ($input['confirm'] ?? '') !== 'SAM') {
        throw new InvalidArgumentException('Bekräftelse saknas.');
    }
    $row = jeab_purchase_get((int) $orderId);
    $account = jeab_fortnox_order_attach_account($input, is_array($row) ? $row : null);
    $company = $account === 'Malmo' ? 'malmo' : 'jarfalla';
    $base = [
        'ok' => true,
        'action' => 'fortnox.order_attach',
        'dry' => $dry ? 1 : 0,
        'read_only' => 1,
        'account' => $account,
        'order_id' => $orderId,
        'fortnox_order' => $fortnoxOrder,
        'invoice_number' => $invoice,
    ];
    if (!is_array($row) || jeab_fortnox_leveransdatum_row_company($row) !== $company) {
        return jeab_fortnox_order_attach_result($base, [
            'ok' => false,
            'status' => 'fel',
            'error' => 'Ordern finns inte.',
        ]);
    }
    $currentOrder = jeab_fortnox_order_attach_order_of($row);
    $currentInvoice = jeab_fortnox_order_link_invoice_of($row);
    $currentCustomer = jeab_fortnox_order_attach_customer_of($row);
    $invoiceHolder = jeab_fortnox_order_attach_holder($company, 'fortnox_invoice', $invoice, $orderId);
    $orderHolder = jeab_fortnox_order_attach_holder($company, 'fortnox_order', $fortnoxOrder, $orderId);
    if ($invoiceHolder !== '' || $orderHolder !== '') {
        return jeab_fortnox_order_attach_result($base, [
            'ok' => false,
            'status' => 'fel',
            'error' => $invoiceHolder !== ''
                ? 'Fakturan pekas redan ut av en annan order.'
                : 'Fortnox-ordern pekas redan ut av en annan order.',
            'other_order_id' => $invoiceHolder !== '' ? $invoiceHolder : $orderHolder,
        ]);
    }
    if ($currentOrder !== '' && $currentOrder !== $fortnoxOrder) {
        return jeab_fortnox_order_attach_result($base, [
            'ok' => false,
            'status' => 'fel',
            'error' => 'Ordern har redan en annan Fortnox-order.',
        ]);
    }
    if ($currentInvoice !== '' && $currentInvoice !== $invoice) {
        return jeab_fortnox_order_attach_result($base, [
            'ok' => false,
            'status' => 'fel',
            'error' => 'Ordern har redan en annan faktura.',
        ]);
    }
    if (jeab_fortnox_order_attach_locked($row) && $currentOrder === $fortnoxOrder && $currentInvoice === $invoice) {
        return jeab_fortnox_order_attach_result($base, [
            'status' => 'oforandrat',
            'customer_number' => $currentCustomer,
        ]);
    }
    $remote = jeab_fortnox_order_attach_read($account, $invoice, $fortnoxOrder);
    if (!$remote['ok']) {
        $fail = array_merge($base, [
            'ok' => false,
            'status' => 'fel',
            'error' => $remote['error'] !== '' ? $remote['error'] : 'Fortnox-fel.',
        ]);
        if (is_array($remote['error_information'])) {
            $fail['error_information'] = $remote['error_information'];
        }

        return jeab_fortnox_order_attach_out($fail);
    }
    $customer = $remote['customer'];
    if ($currentCustomer !== '' && $currentCustomer !== $customer) {
        return jeab_fortnox_order_attach_result($base, [
            'ok' => false,
            'status' => 'fel',
            'error' => 'Ordern har redan ett annat kundnummer.',
        ]);
    }
    $preview = array_merge($base, [
        'status' => 'skulle_koppla',
        'customer_number' => $customer,
    ]);
    if ($dry) {
        return jeab_fortnox_order_attach_out($preview);
    }
    $text = '«Fortnox-order ' . $fortnoxOrder . ' / faktura ' . $invoice . ' kopplad (ingen export)»';
    $saved = jeab_purchase_patch((int) $orderId, static function (array $fresh) use ($company, $orderId, $fortnoxOrder, $invoice, $customer, $text): ?array {
        if (jeab_fortnox_leveransdatum_row_company($fresh) !== $company) {
            return null;
        }
        if (jeab_fortnox_order_attach_holder($company, 'fortnox_invoice', $invoice, $orderId) !== ''
            || jeab_fortnox_order_attach_holder($company, 'fortnox_order', $fortnoxOrder, $orderId) !== '') {
            return null;
        }
        $nowOrder = jeab_fortnox_order_attach_order_of($fresh);
        $nowInvoice = jeab_fortnox_order_link_invoice_of($fresh);
        $nowCustomer = jeab_fortnox_order_attach_customer_of($fresh);
        if (($nowOrder !== '' && $nowOrder !== $fortnoxOrder)
            || ($nowInvoice !== '' && $nowInvoice !== $invoice)
            || ($nowCustomer !== '' && $nowCustomer !== $customer)) {
            return null;
        }
        if (jeab_fortnox_order_attach_locked($fresh) && $nowOrder === $fortnoxOrder && $nowInvoice === $invoice) {
            return null;
        }
        if (!jeab_fortnox_order_attach_locked($fresh)) {
            $fresh['fortnox_attach'] = [
                'fortnox_order' => $nowOrder,
                'fortnox_invoice' => $nowInvoice,
                'fortnox_customer' => $nowCustomer,
                'order_skriven' => jeab_fortnox_order_attach_step($fresh, 'order_skriven'),
                'faktura_skapad' => jeab_fortnox_order_attach_step($fresh, 'faktura_skapad'),
            ];
        }
        if ($nowOrder === '') {
            $fresh['fortnox_order'] = $fortnoxOrder;
        }
        if ($nowInvoice === '') {
            $fresh['fortnox_invoice'] = $invoice;
        }
        if ($nowCustomer === '') {
            $fresh['fortnox_customer'] = $customer;
        }
        if (!is_array($fresh['steps'] ?? null)) {
            $fresh['steps'] = [];
        }
        $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
        if (trim((string) ($fresh['steps']['order_skriven'] ?? '')) === '') {
            $fresh['steps']['order_skriven'] = $today;
        }
        if (trim((string) ($fresh['steps']['faktura_skapad'] ?? '')) === '') {
            $fresh['steps']['faktura_skapad'] = $today;
        }
        $fresh['fortnox_attached'] = 1;
        if (function_exists('jeab_ordrar_activity_append')) {
            return jeab_ordrar_activity_append($fresh, $text, '', 'fortnox-attach:' . $fortnoxOrder . ':' . $invoice, false);
        }
        $list = is_array($fresh['log'] ?? null) ? $fresh['log'] : [];
        $list[] = [
            'at' => function_exists('jeab_now') ? jeab_now() : gmdate('Y-m-d H:i:s'),
            'text' => $text,
            'key' => 'fortnox-attach:' . $fortnoxOrder . ':' . $invoice,
        ];
        $fresh['log'] = $list;
        $fresh['activity'] = $list;

        return $fresh;
    });
    if (!is_array($saved) || !jeab_fortnox_order_attach_locked($saved)) {
        $again = jeab_purchase_get((int) $orderId);
        if (is_array($again) && jeab_fortnox_order_attach_locked($again)
            && jeab_fortnox_order_attach_order_of($again) === $fortnoxOrder
            && jeab_fortnox_order_link_invoice_of($again) === $invoice) {
            return jeab_fortnox_order_attach_result($base, [
                'status' => 'oforandrat',
                'customer_number' => jeab_fortnox_order_attach_customer_of($again),
            ]);
        }

        return jeab_fortnox_order_attach_result($base, [
            'ok' => false,
            'status' => 'fel',
            'error' => 'Ordern gick inte att koppla.',
        ]);
    }
    if (function_exists('appLog')) {
        appLog('fortnox_order_attach', [
            'account' => $account,
            'order' => $orderId,
            'fortnox_order' => $fortnoxOrder,
            'invoice' => $invoice,
        ]);
    }

    return jeab_fortnox_order_attach_result($base, [
        'status' => 'kopplad',
        'customer_number' => jeab_fortnox_order_attach_customer_of($saved),
    ]);
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_fortnox_order_attach_undo(array $input): array
{
    $orderId = jeab_fortnox_order_link_doc((string) ($input['order_id'] ?? ''));
    if ($orderId === '') {
        throw new InvalidArgumentException('order_id ska vara ett nummer.');
    }
    $dry = jeab_fortnox_order_link_dry($input);
    if (!$dry && (string) ($input['confirm'] ?? '') !== 'SAM') {
        throw new InvalidArgumentException('Bekräftelse saknas.');
    }
    $row = jeab_purchase_get((int) $orderId);
    $account = jeab_fortnox_order_attach_account($input, is_array($row) ? $row : null);
    $company = $account === 'Malmo' ? 'malmo' : 'jarfalla';
    $base = [
        'ok' => true,
        'action' => 'fortnox.order_attach_undo',
        'dry' => $dry ? 1 : 0,
        'read_only' => 1,
        'account' => $account,
        'order_id' => $orderId,
    ];
    if (!is_array($row) || jeab_fortnox_leveransdatum_row_company($row) !== $company) {
        return jeab_fortnox_order_attach_result($base, [
            'ok' => false,
            'status' => 'fel',
            'error' => 'Ordern finns inte.',
        ]);
    }
    if (!jeab_fortnox_order_attach_locked($row)) {
        return jeab_fortnox_order_attach_result($base, [
            'ok' => false,
            'status' => 'fel',
            'error' => 'Ingen koppling att ångra.',
        ]);
    }
    $snap = is_array($row['fortnox_attach'] ?? null) ? $row['fortnox_attach'] : [];
    $fortnoxOrder = jeab_fortnox_order_attach_order_of($row);
    $invoice = jeab_fortnox_order_link_invoice_of($row);
    $base['fortnox_order'] = $fortnoxOrder;
    $base['invoice_number'] = $invoice;
    if ($dry) {
        return jeab_fortnox_order_attach_result($base, ['status' => 'skulle_angra']);
    }
    $text = '«Fortnox-order ' . $fortnoxOrder . ' / faktura ' . $invoice . ' frånkopplad»';
    $saved = jeab_purchase_patch((int) $orderId, static function (array $fresh) use ($snap, $text, $fortnoxOrder, $invoice): ?array {
        if (!jeab_fortnox_order_attach_locked($fresh)) {
            return null;
        }
        $stored = is_array($fresh['fortnox_attach'] ?? null) ? $fresh['fortnox_attach'] : $snap;
        $fresh['fortnox_order'] = jeab_fortnox_order_link_doc((string) ($stored['fortnox_order'] ?? ''));
        $fresh['fortnox_invoice'] = jeab_fortnox_order_link_doc((string) ($stored['fortnox_invoice'] ?? ''));
        $fresh['fortnox_customer'] = jeab_fortnox_order_link_id((string) ($stored['fortnox_customer'] ?? ''));
        if (!is_array($fresh['steps'] ?? null)) {
            $fresh['steps'] = [];
        }
        $fresh['steps']['order_skriven'] = trim((string) ($stored['order_skriven'] ?? ''));
        $fresh['steps']['faktura_skapad'] = trim((string) ($stored['faktura_skapad'] ?? ''));
        unset($fresh['fortnox_attached'], $fresh['fortnox_attach']);
        if (function_exists('jeab_ordrar_activity_append')) {
            return jeab_ordrar_activity_append($fresh, $text, '', 'fortnox-attach-undo:' . $fortnoxOrder . ':' . $invoice, false);
        }
        $list = is_array($fresh['log'] ?? null) ? $fresh['log'] : [];
        $list[] = [
            'at' => function_exists('jeab_now') ? jeab_now() : gmdate('Y-m-d H:i:s'),
            'text' => $text,
            'key' => 'fortnox-attach-undo:' . $fortnoxOrder . ':' . $invoice,
        ];
        $fresh['log'] = $list;
        $fresh['activity'] = $list;

        return $fresh;
    });
    if (!is_array($saved) || jeab_fortnox_order_attach_locked($saved)) {
        return jeab_fortnox_order_attach_result($base, [
            'ok' => false,
            'status' => 'fel',
            'error' => 'Kopplingen gick inte att ångra.',
        ]);
    }
    if (function_exists('appLog')) {
        appLog('fortnox_order_attach_undo', [
            'account' => $account,
            'order' => $orderId,
            'fortnox_order' => $fortnoxOrder,
            'invoice' => $invoice,
        ]);
    }

    return jeab_fortnox_order_attach_result($base, ['status' => 'angrad']);
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_fortnox_invoice_order_lookup(array $input): array
{
    $account = jeab_fortnox_leveransdatum_account((string) ($input['account'] ?? ''));
    $invoice = jeab_fortnox_order_link_doc((string) ($input['invoice_number'] ?? ''));
    if ($invoice === '') {
        throw new InvalidArgumentException('invoice_number ska vara ett fakturanummer.');
    }
    $company = $account === 'Malmo' ? 'malmo' : 'jarfalla';
    $reasons = [];
    $linked = jeab_fortnox_order_link_local_hits($company, 'fortnox_invoice', $invoice);
    if ($linked === []) {
        $reasons[] = [
            'reason' => 'no_matching_order',
            'key' => 'fortnox_invoice',
            'value' => $invoice,
        ];
    } else {
        $reasons[] = [
            'reason' => 'matching_order',
            'key' => 'fortnox_invoice',
            'value' => $invoice,
            'order_id' => $linked,
        ];
    }
    $fortnox = jeab_fortnox_order_link_read_invoice($account, $invoice);
    if ($fortnox['error_information'] !== null) {
        $reasons[] = [
            'reason' => 'fortnox_error',
            'key' => 'ErrorInformation',
            'message' => $fortnox['error_information']['message'],
            'code' => $fortnox['error_information']['code'],
        ];
    }
    $body = $fortnox['invoice'];
    if ($body !== []) {
        $reasons = array_merge($reasons, jeab_fortnox_order_link_invoice_reasons($company, $invoice, $body));
    }
    return [
        'ok' => true,
        'action' => 'fortnox.invoice_order_lookup',
        'read_only' => 1,
        'account' => $account,
        'invoice' => $invoice,
        'linked' => $linked !== [],
        'reasons' => $reasons,
    ];
}

/**
 * @return array{invoice:array<string,mixed>,error_information:?array{message:string,code:int}}
 */
function jeab_fortnox_order_link_read_invoice(string $account, string $invoice): array
{
    if (!jeab_fortnox_leveransdatum_may_call()) {
        return [
            'invoice' => [],
            'error_information' => ['message' => 'Fortnox är inte kopplad.', 'code' => 0],
        ];
    }
    try {
        $api = new FortnoxAPI($account);
        $live = $api->getInvoice($invoice);
        $body = is_array($live['Invoice'] ?? null) ? $live['Invoice'] : $live;
        return ['invoice' => is_array($body) ? $body : [], 'error_information' => null];
    } catch (Throwable $e) {
        return [
            'invoice' => [],
            'error_information' => jeab_fortnox_leveransdatum_error_info($e),
        ];
    }
}

/**
 * @param array<string,mixed> $invoice
 * @return list<array<string,mixed>>
 */
function jeab_fortnox_order_link_invoice_reasons(string $company, string $invoice, array $invoiceBody): array
{
    $reasons = [];
    $type = strtoupper(trim((string) ($invoiceBody['InvoiceType'] ?? '')));
    if ($type !== '' && preg_match('/\A[A-Z]{1,32}\z/', $type) === 1) {
        $reasons[] = ['reason' => 'invoice_type', 'key' => 'InvoiceType', 'value' => $type];
    }
    $customer = jeab_fortnox_order_link_id((string) ($invoiceBody['CustomerNumber'] ?? ''));
    if ($customer !== '') {
        $byCustomer = jeab_fortnox_order_link_local_hits($company, 'fortnox_customer', $customer);
        $reasons[] = [
            'reason' => $byCustomer === [] ? 'customer_number_unmatched' : 'customer_number',
            'key' => 'CustomerNumber',
            'value' => $customer,
            'order_id' => $byCustomer,
        ];
    }
    $orderRef = jeab_fortnox_order_link_id((string) ($invoiceBody['OrderReference'] ?? ''));
    if ($orderRef !== '') {
        $byOrder = jeab_fortnox_order_link_local_hits($company, 'fortnox_order', $orderRef);
        $reasons[] = [
            'reason' => $byOrder === [] ? 'no_matching_order' : 'matching_order',
            'key' => 'OrderReference',
            'value' => $orderRef,
            'order_id' => $byOrder,
        ];
    } else {
        $reasons[] = ['reason' => 'no_matching_order', 'key' => 'OrderReference', 'value' => ''];
    }
    $your = trim((string) ($invoiceBody['YourOrderNumber'] ?? ''));
    $yourId = jeab_fortnox_order_link_doc($your);
    if ($your !== '' && $yourId === '') {
        $reasons[] = ['reason' => 'your_order_number_not_an_id', 'key' => 'YourOrderNumber'];
    } elseif ($yourId !== '') {
        $local = jeab_purchase_get((int) $yourId);
        $sameCompany = is_array($local) && jeab_fortnox_leveransdatum_row_company($local) === $company;
        if (!$sameCompany) {
            $reasons[] = [
                'reason' => 'no_matching_order',
                'key' => 'YourOrderNumber',
                'value' => $yourId,
            ];
        } else {
            $pointed = jeab_fortnox_order_link_invoice_of($local);
            $reasons[] = [
                'reason' => $pointed === $invoice ? 'matching_order' : 'order_points_elsewhere',
                'key' => 'YourOrderNumber',
                'value' => $yourId,
                'order_id' => [$yourId],
                'fortnox_invoice' => $pointed,
            ];
        }
    }
    $otherInvoice = jeab_fortnox_order_link_doc((string) ($invoiceBody['InvoiceReference'] ?? ''));
    if ($otherInvoice === '') {
        $otherInvoice = jeab_fortnox_order_link_doc((string) ($invoiceBody['CreditInvoiceReference'] ?? ''));
    }
    if ($otherInvoice !== '' && $otherInvoice !== $invoice) {
        $holders = jeab_fortnox_order_link_local_hits($company, 'fortnox_invoice', $otherInvoice);
        $reasons[] = [
            'reason' => $holders === [] ? 'no_matching_order' : 'order_points_at_other_invoice',
            'key' => 'InvoiceReference',
            'value' => $otherInvoice,
            'order_id' => $holders,
        ];
    }
    return $reasons;
}

/**
 * @return list<string>
 */
function jeab_fortnox_order_link_local_hits(string $company, string $field, string $value): array
{
    if ($value === '') {
        return [];
    }
    $hits = [];
    foreach (jeab_purchase_all() as $row) {
        if (!is_array($row) || jeab_fortnox_leveransdatum_row_company($row) !== $company) {
            continue;
        }
        $id = (string) (int) ($row['reminder_id'] ?? 0);
        if ($id === '0') {
            continue;
        }
        $have = $field === 'fortnox_invoice'
            ? jeab_fortnox_order_link_invoice_of($row)
            : jeab_fortnox_order_link_id((string) ($row[$field] ?? ''));
        if ($have === $value) {
            $hits[] = $id;
        }
    }
    return $hits;
}

/**
 * Bara läsning. Jämför fakturor och, om order_id finns, den lokala ordern.
 * Inga personnummer, namn eller adresser. Gatuadress bara som hash och träff.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_fortnox_invoice_compare(array $input): array
{
    $account = jeab_fortnox_leveransdatum_account((string) ($input['account'] ?? ''));
    $company = $account === 'Malmo' ? 'malmo' : 'jarfalla';
    $orderId = jeab_fortnox_order_link_doc((string) ($input['order_id'] ?? ''));
    $order = null;
    if ($orderId !== '') {
        $row = jeab_purchase_get((int) $orderId);
        if (!is_array($row) || jeab_fortnox_leveransdatum_row_company($row) !== $company) {
            return [
                'ok' => false,
                'action' => 'fortnox.invoice_compare',
                'read_only' => 1,
                'account' => $account,
                'error' => 'Ordern finns inte.',
            ];
        }
        $order = jeab_fortnox_invoice_compare_order($row);
    }
    $numbers = jeab_fortnox_invoice_compare_numbers($input);
    if ($order !== null && $order['fortnox_invoice'] !== '' && !in_array($order['fortnox_invoice'], $numbers, true)) {
        $numbers[] = $order['fortnox_invoice'];
    }
    if ($numbers === [] && $order === null) {
        throw new InvalidArgumentException('invoices eller order_id saknas.');
    }
    $invoices = [];
    foreach (array_slice($numbers, 0, 20) as $number) {
        $invoices[] = jeab_fortnox_invoice_compare_one($account, $company, $number, $order);
    }
    return [
        'ok' => true,
        'action' => 'fortnox.invoice_compare',
        'read_only' => 1,
        'account' => $account,
        'order' => $order,
        'invoices' => $invoices,
    ];
}

/**
 * @param array<string,mixed> $input
 * @return list<string>
 */
function jeab_fortnox_invoice_compare_numbers(array $input): array
{
    $raw = [];
    if (isset($input['invoices']) && is_array($input['invoices'])) {
        foreach ($input['invoices'] as $item) {
            $raw[] = (string) $item;
        }
    } elseif (isset($input['invoices'])) {
        $raw = preg_split('/\s*,\s*/', (string) $input['invoices']) ?: [];
    }
    if (isset($input['invoice_number'])) {
        $raw[] = (string) $input['invoice_number'];
    }
    $out = [];
    foreach ($raw as $item) {
        $no = jeab_fortnox_order_link_doc($item);
        if ($no !== '' && !in_array($no, $out, true)) {
            $out[] = $no;
        }
    }
    return $out;
}

/**
 * @param array<string,mixed> $row
 * @return array{order_id:string,fortnox_invoice:string,customer_number:string,amount:string,product:string,address_hash:string}
 */
function jeab_fortnox_invoice_compare_order(array $row): array
{
    $amount = '';
    foreach (['fortnox_att_betala', 'pris', 'kundpris'] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '' && is_numeric($value)) {
            $amount = $value;
            break;
        }
    }
    $product = '';
    if (function_exists('jeab_purchase_normalize_kind')) {
        $product = (string) (jeab_purchase_normalize_kind($row['kind'] ?? '') ?? '');
    }
    if ($product === '') {
        $product = jeab_fortnox_order_link_id((string) ($row['kind'] ?? ''));
    }
    return [
        'order_id' => (string) (int) ($row['reminder_id'] ?? 0),
        'fortnox_invoice' => jeab_fortnox_order_link_invoice_of($row),
        'customer_number' => jeab_fortnox_order_link_id((string) ($row['fortnox_customer'] ?? '')),
        'amount' => $amount,
        'product' => $product,
        'address_hash' => jeab_fortnox_invoice_compare_address_hash(jeab_fortnox_invoice_compare_order_street($row)),
    ];
}

/** Gata utan postnummer och ort. Address1 jämförs mot den, inte mot hela adressen. */
function jeab_fortnox_invoice_compare_street(string $line, string $zip = '', string $city = ''): string
{
    $line = trim($line);
    if ($line === '') {
        return '';
    }
    if (class_exists('FortnoxAPI')) {
        $collected = FortnoxAPI::collectAddress([
            'address' => $line,
            'postnr' => $zip,
            'ort' => $city,
        ]);
        $street = trim((string) ($collected['gata'] ?? ''));
        if ($street !== '') {
            return $street;
        }
    }
    if (preg_match('/(?<!\d)(\d{3})\s?(\d{2})(?!\d)/u', $line, $match, PREG_OFFSET_CAPTURE) === 1) {
        $line = trim(substr($line, 0, (int) $match[0][1]), " ,");
    }
    return trim($line);
}

/** @param array<string,mixed> $row */
function jeab_fortnox_invoice_compare_order_street(array $row): string
{
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_recipient') && function_exists('jeab_contacts_sole_recipient_contact')) {
        $contact = jeab_contacts_sole_recipient_contact($row);
        if (is_array($contact) && function_exists('jeab_contacts_sole_view_from_contact')) {
            $view = jeab_contacts_sole_view_from_contact($contact);
            $rec = jeab_contacts_sole_recipient($row);
            if (function_exists('jeab_contacts_strict') && jeab_contacts_strict()) {
                $street = trim((string) ($view['street'] ?? ''));
                if ($street === '' && trim((string) ($view['display'] ?? '')) !== '') {
                    return jeab_fortnox_invoice_compare_street((string) $rec['display'], (string) $rec['postal'], (string) $rec['city']);
                }

                return jeab_fortnox_invoice_compare_street($street, (string) $rec['postal'], (string) $rec['city']);
            }
            if (trim((string) ($view['street'] ?? '')) !== '') {
                return jeab_fortnox_invoice_compare_street($rec['street'], $rec['postal'], $rec['city']);
            }
            if (trim((string) ($view['display'] ?? '')) !== '' && trim((string) ($row['address'] ?? '')) === '' && trim((string) ($row['gata'] ?? '')) === '') {
                return jeab_fortnox_invoice_compare_street($rec['display'], $rec['postal'], $rec['city']);
            }
        }
    }
    if (class_exists('FortnoxAPI')) {
        $collected = FortnoxAPI::collectAddress([
            'address' => (string) ($row['address'] ?? ''),
            'gata' => (string) ($row['gata'] ?? ''),
            'postnr' => (string) ($row['postnr'] ?? $row['postnummer'] ?? ''),
            'ort' => (string) ($row['ort'] ?? ''),
        ]);
        $street = trim((string) ($collected['gata'] ?? ''));
        if ($street !== '') {
            return $street;
        }
    }
    foreach (['address', 'gata', 'huvudsokande_address'] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value !== '') {
            return jeab_fortnox_invoice_compare_street(
                $value,
                (string) ($row['postnr'] ?? $row['postnummer'] ?? ''),
                (string) ($row['ort'] ?? '')
            );
        }
    }
    return '';
}

function jeab_fortnox_invoice_compare_address_hash(string $raw): string
{
    $raw = jeab_fortnox_invoice_compare_street($raw);
    if ($raw === '') {
        return '';
    }
    $key = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    $key = preg_replace('/\s+/u', ' ', $key) ?? $key;
    return hash('sha256', $key);
}

function jeab_fortnox_invoice_compare_tax_type(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $text = strtolower(trim((string) $value));
    return in_array($text, ['green', 'rot', 'rut', 'none'], true) ? $text : '';
}

function jeab_fortnox_invoice_compare_scrub(string $text): string
{
    $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '', $text) ?? $text;
    $text = preg_replace('/\b(?:19|20)\d{6}[-+]?\d{4}\b/', '', $text) ?? $text;
    $text = preg_replace('/\b\d{6}[-+]\d{4}\b/', '', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

/**
 * @param array{order_id:string,fortnox_invoice:string,customer_number:string,amount:string,product:string,address_hash:string}|null $order
 * @return array<string,mixed>
 */
function jeab_fortnox_invoice_compare_one(string $account, string $company, string $number, ?array $order): array
{
    $holders = jeab_fortnox_order_link_local_hits($company, 'fortnox_invoice', $number);
    $loaded = jeab_fortnox_order_link_read_invoice($account, $number);
    if ($loaded['error_information'] !== null || $loaded['invoice'] === []) {
        return [
            'invoice' => $number,
            'ok' => false,
            'order_id' => $holders,
            'error_information' => $loaded['error_information'] ?? ['message' => 'Fakturan finns inte.', 'code' => 0],
        ];
    }
    $body = $loaded['invoice'];
    $localHash = $order['address_hash'] ?? '';
    if ($localHash === '' && $holders !== []) {
        $linked = jeab_purchase_get((int) $holders[0]);
        if (is_array($linked)) {
            $localHash = jeab_fortnox_invoice_compare_address_hash(jeab_fortnox_invoice_compare_order_street($linked));
        }
    }
    $sides = [];
    foreach ([
        ['Address1', 'ZipCode', 'City'],
        ['DeliveryAddress1', 'DeliveryZipCode', 'DeliveryCity'],
    ] as [$addressKey, $zipKey, $cityKey]) {
        $street = jeab_fortnox_invoice_compare_street(
            (string) ($body[$addressKey] ?? ''),
            (string) ($body[$zipKey] ?? ''),
            (string) ($body[$cityKey] ?? '')
        );
        if ($street === '') {
            continue;
        }
        $sides[] = jeab_fortnox_invoice_compare_address_hash($street);
    }
    $hash = $sides[0] ?? '';
    $addressMatch = null;
    if ($localHash !== '' && $sides !== []) {
        $addressMatch = true;
        foreach ($sides as $sideHash) {
            if (!hash_equals($sideHash, $localHash)) {
                $addressMatch = false;
                break;
            }
        }
    }
    $rows = [];
    $source = $body['InvoiceRows'] ?? [];
    if (is_array($source)) {
        foreach ($source as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'ArticleNumber' => jeab_fortnox_order_link_id((string) ($row['ArticleNumber'] ?? '')),
                'Description' => jeab_fortnox_invoice_compare_scrub((string) ($row['Description'] ?? '')),
                'DeliveredQuantity' => jeab_fortnox_invoice_compare_number($row['DeliveredQuantity'] ?? ''),
                'Price' => jeab_fortnox_invoice_compare_number($row['Price'] ?? ''),
                'HouseWork' => jeab_fortnox_leveransdatum_truthy($row['HouseWork'] ?? false),
                'BasisTaxReduction' => jeab_fortnox_invoice_compare_number(is_scalar($row['BasisTaxReduction'] ?? null) ? $row['BasisTaxReduction'] : ''),
            ];
        }
    }
    $your = trim((string) ($body['YourOrderNumber'] ?? ''));
    $yourId = jeab_fortnox_order_link_doc($your);
    return [
        'invoice' => $number,
        'ok' => true,
        'InvoiceType' => preg_match('/\A[A-Z]{1,32}\z/', strtoupper(trim((string) ($body['InvoiceType'] ?? '')))) === 1
            ? strtoupper(trim((string) $body['InvoiceType']))
            : '',
        'Booked' => jeab_fortnox_leveransdatum_truthy($body['Booked'] ?? false),
        'Cancelled' => jeab_fortnox_leveransdatum_truthy($body['Cancelled'] ?? false),
        'CustomerNumber' => jeab_fortnox_order_link_id((string) ($body['CustomerNumber'] ?? '')),
        'OrderReference' => jeab_fortnox_order_link_id((string) ($body['OrderReference'] ?? '')),
        'YourOrderNumber' => $yourId,
        'InvoiceDate' => jeab_fortnox_delivery_ymd((string) ($body['InvoiceDate'] ?? '')),
        'Total' => jeab_fortnox_invoice_compare_number($body['Total'] ?? ''),
        'TotalVAT' => jeab_fortnox_invoice_compare_number($body['TotalVAT'] ?? ''),
        'TaxReduction' => jeab_fortnox_invoice_compare_number(is_scalar($body['TaxReduction'] ?? null) ? $body['TaxReduction'] : ''),
        'TaxReductionType' => jeab_fortnox_invoice_compare_tax_type($body['TaxReductionType'] ?? ''),
        'BasisTaxReduction' => jeab_fortnox_invoice_compare_number(is_scalar($body['BasisTaxReduction'] ?? null) ? $body['BasisTaxReduction'] : ''),
        'TotalToPay' => jeab_fortnox_invoice_compare_number(is_scalar($body['TotalToPay'] ?? null) ? $body['TotalToPay'] : ''),
        'CreditInvoiceReference' => jeab_fortnox_order_link_doc((string) ($body['CreditInvoiceReference'] ?? '')),
        'rows' => $rows,
        'order_id' => $holders,
        'address_hash' => $hash,
        'address_match' => $addressMatch,
    ];
}

function jeab_fortnox_invoice_compare_number(mixed $value): string
{
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    $text = trim((string) $value);
    return is_numeric($text) ? $text : '';
}

function jeab_fortnox_order_export_force(array $input): bool
{
    $flag = $input['force_new'] ?? false;
    return $flag === true || $flag === 1 || $flag === '1';
}

/**
 * Samma export som Ordrar kör efter Bekräfta: ordern med grön teknik och sedan faktura från ordern.
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_fortnox_order_export(array $input): array
{
    $account = jeab_fortnox_leveransdatum_account((string) ($input['account'] ?? ''));
    $orderId = jeab_fortnox_order_link_doc((string) ($input['order_id'] ?? ''));
    if ($orderId === '') {
        throw new InvalidArgumentException('order_id ska vara ett nummer.');
    }
    $dry = jeab_fortnox_order_link_dry($input);
    $confirm = (string) ($input['confirm'] ?? '') === 'SAM';
    if (!$dry && !$confirm) {
        throw new InvalidArgumentException('Bekräftelse saknas.');
    }
    $company = $account === 'Malmo' ? 'malmo' : 'jarfalla';
    $row = jeab_purchase_get((int) $orderId);
    if (!is_array($row) || jeab_fortnox_leveransdatum_row_company($row) !== $company) {
        return [
            'ok' => false,
            'action' => 'fortnox.order_export',
            'dry' => $dry ? 1 : 0,
            'account' => $account,
            'order_id' => $orderId,
            'error' => 'Ordern finns inte.',
        ];
    }
    if (jeab_fortnox_order_attach_locked($row)) {
        return [
            'ok' => false,
            'action' => 'fortnox.order_export',
            'dry' => $dry ? 1 : 0,
            'account' => $account,
            'order_id' => $orderId,
            'error' => jeab_fortnox_order_attach_export_error(),
        ];
    }
    if (function_exists('jeab_contacts_strict_block')) {
        $block = jeab_contacts_strict_block($row, 'fortnox');
        if ($block !== '') {
            return [
                'ok' => false,
                'action' => 'fortnox.order_export',
                'dry' => $dry ? 1 : 0,
                'account' => $account,
                'order_id' => $orderId,
                'error' => $block,
            ];
        }
    }
    $current = jeab_fortnox_order_link_invoice_of($row);
    $fortnoxOrder = trim((string) ($row['fortnox_order'] ?? ''));
    $released = false;
    if ($current !== '') {
        $loaded = jeab_fortnox_order_link_read_invoice($account, $current);
        if ($loaded['error_information'] !== null || $loaded['invoice'] === []) {
            return [
                'ok' => false,
                'action' => 'fortnox.order_export',
                'dry' => $dry ? 1 : 0,
                'account' => $account,
                'order_id' => $orderId,
                'invoice' => $current,
                'error' => 'Fakturan gick inte att läsa.',
                'error_information' => $loaded['error_information'] ?? ['message' => 'Fakturan finns inte.', 'code' => 0],
            ];
        }
        $released = jeab_fortnox_order_export_released($loaded['invoice']);
    }
    $force = jeab_fortnox_order_export_force($input);
    $fail = [
        'ok' => false,
        'action' => 'fortnox.order_export',
        'dry' => $dry ? 1 : 0,
        'account' => $account,
        'order_id' => $orderId,
    ];
    if ($current !== '' && !$released) {
        return $fail + [
            'invoice' => $current,
            'error' => 'Ordern pekar på en faktura som varken är makulerad eller krediterad.',
        ];
    }
    // Bekräfta och «utan bokning» hoppar över en order som redan har FO.
    // En fakturerad FO är låst i Fortnox även efter makulering, så den vanliga
    // uppdateringen tar inte grön teknik. force_new rensar de lokala numren
    // och låter create_fortnox skapa en ny order, samma gren som första exporten.
    $needsReset = $released && ($fortnoxOrder !== '' || $current !== '');
    if ($needsReset && !$force) {
        return $fail + [
            'invoice' => $current,
            'fortnox_order' => $fortnoxOrder,
            'error' => 'Ordern har en Fortnox-order och fakturan är makulerad eller krediterad. force_new=1 krävs.',
        ];
    }
    if (!function_exists('jeab_ordrar_create_fortnox')) {
        require_once __DIR__ . '/ordrar.php';
        require_once __DIR__ . '/ordrar_fortnox.php';
    }
    $foLock = '';
    if ($fortnoxOrder !== '') {
        try {
            $foLock = jeab_ordrar_fortnox_order_lock_reason(jeab_ordrar_fortnox_fetch_order($row));
        } catch (Throwable $e) {
            return $fail + [
                'fortnox_order' => $fortnoxOrder,
                'error' => jeab_ordrar_fortnox_sv($e->getMessage()),
            ];
        }
    }
    $export = jeab_ordrar_fortnox_payload($row);
    $plan = jeab_ordrar_fortnox_api_for($row)->kopExportPlan($export);
    $planned = is_array($plan['order'] ?? null) ? $plan['order'] : [];
    $clear = [];
    if ($foLock === 'cancelled' && $fortnoxOrder !== '') {
        $clear['fortnox_order'] = $fortnoxOrder;
        $clear['reason'] = 'cancelled';
    }
    if ($needsReset && $force) {
        if ($fortnoxOrder !== '') {
            $clear['fortnox_order'] = $fortnoxOrder;
        }
        if ($current !== '') {
            $clear['fortnox_invoice'] = $current;
        }
    }
    $base = [
        'ok' => true,
        'action' => 'fortnox.order_export',
        'dry' => $dry ? 1 : 0,
        'account' => $account,
        'order_id' => $orderId,
        'total' => (int) ($plan['total'] ?? 0),
        'green_tech' => ($planned['TaxReductionType'] ?? '') === 'green' && (int) ($plan['deduction_total'] ?? 0) > 0,
        'housework' => is_array($plan['housework'] ?? null) ? $plan['housework'] : [],
        'delivery_date' => (string) ($planned['DeliveryDate'] ?? ''),
        'deduction' => $plan['deduction'] ?? [],
        'deduction_total' => (int) ($plan['deduction_total'] ?? 0),
        'amount_to_pay' => (int) ($plan['amount_to_pay'] ?? 0),
        'clear' => $clear,
    ];
    if ($dry) {
        return $base + ['payload' => $planned];
    }
    if (!function_exists('jeab_ordrar_save_fakturering')) {
        require_once __DIR__ . '/ordrar.php';
    }
    $previousOrder = $fortnoxOrder;
    $previousInvoice = $current;
    try {
        $skipPrecheck = false;
        // Bara en uttrycklig force_new-export får släppa, och bara en makulerad order.
        // WarehouseReady är standard på tjänsteordrar och får inte skapa en ny FO.
        if ($foLock === 'cancelled' && $force) {
            jeab_ordrar_fortnox_release_cancelled($row);
            $skipPrecheck = true;
        }
        if ($needsReset && $force) {
            jeab_fortnox_order_export_clear((int) $orderId, [
                'fortnox_order' => $fortnoxOrder,
                'fortnox_invoice' => $current,
            ]);
            $skipPrecheck = true;
        }
        // FO:n är redan borta när create läser om ordern. Utan skip länkar
        // förkollen kundens gamla order och hoppar över nya rader och grön teknik.
        jeab_ordrar_create_fortnox([
            'id' => (int) $orderId,
            'skip_precheck' => $skipPrecheck,
        ]);
        $saved = jeab_ordrar_save_fakturering([
            'id' => (int) $orderId,
            'intent' => 'faktura',
        ]);
    } catch (Throwable $e) {
        jeab_fortnox_order_export_restore_links((int) $orderId, $previousOrder, $previousInvoice);
        $msg = trim($e->getMessage());
        return array_merge($base, [
            'ok' => false,
            'error' => $msg !== '' ? jeab_ordrar_fortnox_sv($msg) : 'Fortnox-ordern kunde inte exporteras.',
        ]);
    }
    $newInvoice = is_array($saved) ? jeab_fortnox_order_link_invoice_of($saved) : '';
    if ($newInvoice === '') {
        jeab_fortnox_order_export_restore_links((int) $orderId, $previousOrder, $previousInvoice);
        return array_merge($base, [
            'ok' => false,
            'error' => 'Fortnox skapade ingen faktura.',
        ]);
    }
    return $base + ['invoice' => $newInvoice];
}

function jeab_fortnox_order_export_released(array $invoice): bool
{
    $creditRef = trim((string) ($invoice['CreditInvoiceReference'] ?? ''));
    $credited = $creditRef !== '' && $creditRef !== '0';
    $cancelled = jeab_fortnox_leveransdatum_truthy($invoice['Cancelled'] ?? false);
    return $credited || $cancelled;
}

/**
 * @param array{fortnox_order?:string,fortnox_invoice?:string} $clear
 */
function jeab_fortnox_order_export_clear(int $orderId, array $clear): void
{
    $fo = trim((string) ($clear['fortnox_order'] ?? ''));
    $invoice = trim((string) ($clear['fortnox_invoice'] ?? ''));
    $text = 'Fortnox-koppling rensad inför ny export: order ' . ($fo !== '' ? $fo : '-') . ', faktura ' . ($invoice !== '' ? $invoice : '-') . '.';
    jeab_purchase_patch($orderId, static function (array $fresh) use ($fo, $invoice, $text): array {
        if (function_exists('jeab_ordrar_activity_append')) {
            $fresh = jeab_ordrar_activity_append($fresh, $text, '', 'fortnox-export-clear:' . $fo . ':' . $invoice, false);
        }
        $fresh['fortnox_order'] = '';
        $fresh['fortnox_invoice'] = '';
        return $fresh;
    });
    if (function_exists('appLog')) {
        appLog('fortnox_order_export_clear', [
            'order' => (string) $orderId,
            'fortnox_order' => $fo,
            'invoice' => $invoice,
        ]);
    }
}

/**
 * Lägger tillbaka länken när ny faktura inte blev sparad.
 * Ett nytt Fortnox-ordernummer som redan skrivits lämnas kvar.
 */
function jeab_fortnox_order_export_restore_links(int $orderId, string $orderNo, string $invoice): void
{
    $orderNo = trim($orderNo);
    $invoice = trim($invoice);
    if ($orderId <= 0 || ($orderNo === '' && $invoice === '') || !function_exists('jeab_purchase_patch')) {
        return;
    }
    jeab_purchase_patch($orderId, static function (array $row) use ($orderNo, $invoice): ?array {
        $haveOrder = trim((string) ($row['fortnox_order'] ?? ''));
        $haveInvoice = trim((string) ($row['fortnox_invoice'] ?? ''));
        if ($haveInvoice !== '' && $haveInvoice !== $invoice) {
            return null;
        }
        $changed = false;
        if ($haveOrder === '' && $orderNo !== '') {
            $row['fortnox_order'] = $orderNo;
            $haveOrder = $orderNo;
            $changed = true;
        }
        if ($haveInvoice === '' && $invoice !== '' && ($haveOrder === '' || $haveOrder === $orderNo)) {
            $row['fortnox_invoice'] = $invoice;
            $changed = true;
        }

        return $changed ? $row : null;
    });
}
