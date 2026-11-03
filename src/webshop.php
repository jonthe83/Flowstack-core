<?php

declare(strict_types=1);

require_once __DIR__ . '/normalize.php';

/**
 * Webshop (Wix JEAB + MEAB) → påminnelse taggad vunnen → order som vanligt.
 *
 * Idempotens: nyckeln är "<bolag>:<Wix ordernummer>" i log_webshop_orders.
 * Samma Wix-order skapar aldrig två påminnelser.
 * JSON-filen används bara när data_backend tvingar json (rollback). Misslyckad SQL
 * loggas i felloggen och skriver inte filen.
 *
 * Fortnox skrivs bara en gång av flowstack.example.test (integrationer.se används inte längre, men
 * kan ha skrivit äldre ordrar). Innan flowstack.example.test skriver en webshoporder letar den i Fortnox efter:
 *   1. Wix-ordernumret i YourOrderNumber, ExternalInvoiceReference1/2, YourReference
 *      eller Remarks (stark träff), eller
 *   2. samma kund (e-post eller namn) och samma totalbelopp ±1 kr från orderdagen
 *      och framåt (svag träff).
 * Finns en träff länkas den ordern i stället för att en ny skrivs. Går Fortnox inte
 * att läsa skrivs inget (hellre stopp än dubblett).
 */

const JEAB_WEBSHOP_SITES = [
    'b1dfa86d-4acf-4fbc-86a5-d1c08d319d75' => 'jarfalla',
    '5a7c71c4-ed34-4048-adc7-f390de72698f' => 'malmo',
];

function jeab_webshop_index_path(): string
{
    return jeab_data_dir() . '/webshop-orders.json';
}

function jeab_webshop_sql_fail(): never
{
    static $logged = false;
    if (!$logged && function_exists('jeab_log_app_error')) {
        $logged = true;
        jeab_log_app_error('webshop', 'SQL saknas');
    }
    throw new RuntimeException('SQL saknas');
}

function jeab_webshop_json_only(): bool
{
    return function_exists('jeab_cutover_forces_json') && jeab_cutover_forces_json('webshop_orders');
}

/** @return array<string, array<string, mixed>> */
function jeab_webshop_index_read_json(): array
{
    $path = jeab_webshop_index_path();
    $d = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

    return is_array($d) ? $d : [];
}

/** @param array<string, array<string, mixed>> $index */
function jeab_webshop_index_write_json(array $index): void
{
    $path = jeab_webshop_index_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($tmp, 0600);
    @rename($tmp, $path);
}

/** @return array<string, array<string, mixed>> */
function jeab_webshop_index(): array
{
    if (jeab_webshop_json_only()) {
        return jeab_webshop_index_read_json();
    }
    if (function_exists('jeab_log_webshop_read')) {
        $sql = jeab_log_webshop_read();
        if (is_array($sql)) {
            return $sql;
        }
    }
    if (function_exists('jeab_cutover_webshop_index')) {
        $cut = jeab_cutover_webshop_index();
        if (is_array($cut)) {
            return $cut;
        }
    }
    jeab_webshop_sql_fail();
}

/**
 * Läs-ändra-skriv av indexet. På SQL-cutovervägen körs hela cykeln under
 * jeab_cutover_locked (GET_LOCK och transaktion, S4-4). Annars som idag.
 *
 * @param callable(array<string, array<string, mixed>>): array<string, array<string, mixed>> $fn
 */
function jeab_webshop_index_mutate(callable $fn): void
{
    $jsonOnly = jeab_webshop_json_only();
    $cutover = !$jsonOnly
        && function_exists('jeab_cutover_sql') && jeab_cutover_sql('webshop_orders')
        && function_exists('jeab_cutover_log_pdo_missing') && jeab_cutover_log_pdo_missing()
        && function_exists('jeab_cutover_locked');
    if ($cutover) {
        jeab_cutover_locked('webshop_orders', static function (array $index) use ($fn): array {
            return $fn($index);
        });

        return;
    }
    jeab_webshop_index_save($fn(jeab_webshop_index()));
}

/** @param array<string, array<string, mixed>> $index */
function jeab_webshop_index_save(array $index): void
{
    if (jeab_webshop_json_only()) {
        jeab_webshop_index_write_json($index);

        return;
    }
    if (function_exists('jeab_log_webshop_replace') && jeab_log_webshop_replace($index)) {
        return;
    }
    if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('webshop_orders') && function_exists('jeab_cutover_log_pdo_missing') && jeab_cutover_log_pdo_missing() && function_exists('jeab_cutover_locked')) {
        jeab_cutover_locked('webshop_orders', static function (array $current) use ($index): array {
            unset($current);

            return $index;
        });

        return;
    }
    jeab_webshop_sql_fail();
}

function jeab_webshop_key(string $company, string $number): string
{
    return $company . ':' . $number;
}

/** Token: data/.private/secrets/webshop-ingest.json {"key":...}, annars inkorgens ingest-token. */
function jeab_webshop_token(): string
{
    $path = jeab_data_dir() . '/secrets/webshop-ingest.json';
    $d = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    $t = is_array($d) ? trim((string) ($d['key'] ?? '')) : '';
    if ($t === '' && function_exists('jeab_inkorg_token')) {
        $t = jeab_inkorg_token();
    }
    return $t;
}

function jeab_webshop_hook_authorized(): bool
{
    $t = jeab_webshop_token();
    return $t !== '' && function_exists('jeab_incoming_token_ok') && jeab_incoming_token_ok($t);
}

/**
 * Plockar ut Wix-ordern ur en rå order, en Search Orders-rad eller ett webhook-kuvert
 * (createdEvent.entity / updatedEvent.currentEntity / data.order).
 */
function jeab_webshop_unwrap(array $input): array
{
    foreach ([
        $input['order'] ?? null,
        $input['createdEvent']['entity'] ?? null,
        $input['updatedEvent']['currentEntity'] ?? null,
        $input['actionEvent']['body']['order'] ?? null,
        $input['data']['order'] ?? null,
        $input['data'] ?? null,
    ] as $cand) {
        if (is_array($cand) && isset($cand['number'])) {
            return $cand;
        }
    }
    return isset($input['number']) ? $input : [];
}

/** @return array<string, mixed> normaliserad webshoporder */
function jeab_webshop_normalize(array $input): array
{
    $order = jeab_webshop_unwrap($input);
    $number = trim((string) ($order['number'] ?? ''));
    if ($number === '' || !preg_match('/^\d{1,12}$/', $number)) {
        throw new InvalidArgumentException('Wix-ordernummer saknas.');
    }
    $site = trim((string) ($input['site_id'] ?? $input['siteId'] ?? $input['instanceId'] ?? ''));
    $company = jeab_normalize_company($input['company'] ?? $input['co'] ?? null);
    if ($company === null && isset(JEAB_WEBSHOP_SITES[$site])) {
        $company = JEAB_WEBSHOP_SITES[$site];
    }
    if ($company === null) {
        throw new InvalidArgumentException('Okänt bolag för webshopordern.');
    }
    $bill = is_array($order['billingInfo'] ?? null) ? $order['billingInfo'] : [];
    $ship = is_array($order['shippingInfo']['logistics']['shippingDestination'] ?? null)
        ? $order['shippingInfo']['logistics']['shippingDestination'] : [];
    $contact = is_array($bill['contactDetails'] ?? null) ? $bill['contactDetails'] : (is_array($ship['contactDetails'] ?? null) ? $ship['contactDetails'] : []);
    $addr = is_array($ship['address'] ?? null) ? $ship['address'] : (is_array($bill['address'] ?? null) ? $bill['address'] : []);
    $name = trim(trim((string) ($contact['firstName'] ?? '')) . ' ' . trim((string) ($contact['lastName'] ?? '')));
    $street = trim((string) ($addr['addressLine'] ?? $addr['addressLine1'] ?? ''));
    if ($street === '' && is_array($addr['streetAddress'] ?? null)) {
        $street = trim((string) ($addr['streetAddress']['name'] ?? '') . ' ' . (string) ($addr['streetAddress']['number'] ?? ''));
    }
    $zip = trim((string) ($addr['postalCode'] ?? ''));
    $city = trim((string) ($addr['city'] ?? ''));
    $lines = [];
    foreach ((array) ($order['lineItems'] ?? []) as $li) {
        if (!is_array($li)) {
            continue;
        }
        $title = is_array($li['productName'] ?? null) ? (string) ($li['productName']['original'] ?? '') : (string) ($li['productName'] ?? $li['name'] ?? '');
        $opts = $li['catalogReference']['options']['options'] ?? [];
        $optText = is_array($opts) ? implode(', ', array_map('strval', array_values($opts))) : '';
        $lines[] = [
            'title' => trim($title),
            'options' => $optText,
            'qty' => (int) ($li['quantity'] ?? 1),
            'price' => (string) ($li['price']['amount'] ?? $li['price'] ?? ''),
            // Wix skickar ibland ingen sku alls (Collins väg 43A, 2026-10-14). Aldrig index på nil.
            'sku' => trim((string) ($li['physicalProperties']['sku'] ?? $li['sku'] ?? '')),
        ];
    }
    $total = (string) ($order['priceSummary']['total']['amount'] ?? $order['totals']['total'] ?? '');
    return [
        'company' => $company,
        'site_id' => $site,
        'number' => $number,
        'id' => trim((string) ($order['id'] ?? '')),
        'created' => trim((string) ($order['createdDate'] ?? $order['dateCreated'] ?? '')),
        'status' => trim((string) ($order['status'] ?? '')),
        'payment' => trim((string) ($order['paymentStatus'] ?? '')),
        'name' => $name,
        'email' => trim((string) ($order['buyerInfo']['email'] ?? $bill['email'] ?? '')),
        'phone' => trim((string) ($contact['phone'] ?? '')),
        'street' => $street,
        'postnr' => $zip,
        'ort' => $city,
        'address' => trim($street . ($zip !== '' || $city !== '' ? ', ' . trim($zip . ' ' . $city) : '')),
        'lines' => $lines,
        'total' => $total,
    ];
}

function jeab_webshop_kind(array $w): string
{
    $text = '';
    $skus = '';
    foreach ($w['lines'] as $l) {
        $text .= ' ' . ($l['title'] ?? '') . ' ' . ($l['options'] ?? '');
        $skus .= ' ' . (string) ($l['sku'] ?? '');
    }
    // Batteri före laddare: SigenStor Neo (SKU SIGENSTOR-NEO-*) är batteri, inte laddbox.
    if (preg_match('/\bSIGENSTOR-NEO-/i', $skus) || preg_match('/\b(sigenstor|batteri|batterilagring)/iu', $text)) {
        return 'batteri';
    }
    if (preg_match('/\b(easee|zaptec|laddbox|elbilsladdare|laddare)\b/iu', $text)) {
        return 'laddbox';
    }
    return function_exists('jeab_ordrar_kind_from_text') ? jeab_ordrar_kind_from_text($text) : '';
}

/** Torr klassning (skriver inget). */
function jeab_webshop_classify(array $input): array
{
    $w = jeab_webshop_normalize($input);
    return ['ok' => true, 'number' => $w['number'], 'company' => $w['company'], 'kind' => jeab_webshop_kind($w)];
}

function jeab_webshop_money(string $amount): string
{
    $n = (float) $amount;
    return number_format($n, 0, ',', ' ') . ' kr';
}

function jeab_webshop_note(array $w): string
{
    $out = ['Webshop (Wix) order ' . $w['number'] . ' · ' . jeab_webshop_money($w['total'])];
    foreach ($w['lines'] as $l) {
        $out[] = $l['qty'] . ' × ' . $l['title'] . ($l['options'] !== '' ? ' (' . $l['options'] . ')' : '');
    }
    return implode("\n", $out);
}

/**
 * Skapar påminnelse (vunnen) + order för en webshoporder. Idempotent på bolag:ordernummer.
 *
 * @return array<string, mixed>
 */
function jeab_webshop_ingest(JeabStore $store, array $input): array
{
    $w = jeab_webshop_normalize($input);
    if (in_array(strtoupper($w['status']), ['CANCELED', 'REJECTED', 'INITIALIZED'], true)) {
        return ['ok' => true, 'skipped' => 'status_' . strtolower($w['status']), 'number' => $w['number']];
    }
    $key = jeab_webshop_key($w['company'], $w['number']);
    $duplicateRid = 0;
    $lock = fopen(jeab_webshop_index_path() . '.lock', 'c');
    if ($lock) {
        flock($lock, LOCK_EX);
    }
    try {
        $index = jeab_webshop_index();
        $known = $index[$key] ?? null;
        if (is_array($known) && (int) ($known['reminder_id'] ?? 0) > 0 && jeab_get_reminder($store, (int) $known['reminder_id'])) {
            $duplicateRid = (int) $known['reminder_id'];
        } else {
            $duplicateRid = 0;
        }
        if ($duplicateRid === 0) {
            $kind = jeab_webshop_kind($w);
            $who = jeab_webshop_assignee($w['company'], $kind);
            $phone = jeab_webshop_reminder_phone($w['phone']);
            $product = ['laddbox' => 'Elbilsladdare', 'batteri' => 'Batteri', 'sol' => 'Solceller'][$kind] ?? 'Webshop';
            $row = jeab_reminder_create_action($store, [
                'title' => ($w['name'] !== '' ? $w['name'] : 'Webshopkund') . ' – ' . $product,
                'name' => $w['name'] !== '' ? $w['name'] : 'Webshopkund',
                'email' => $w['email'],
                'phone' => $phone,
                'address' => $w['address'],
                'company' => $w['company'],
                'who' => $who,
                'note' => jeab_webshop_note($w),
                'tags' => ['vunnen'],
                'webshop_order' => true,
            ], true);
            $rid = (int) ($row['id'] ?? 0);
            $entry = ['reminder_id' => $rid, 'created_at' => jeab_now(), 'wix_id' => $w['id']];
            jeab_webshop_index_mutate(static function (array $fresh) use ($key, $entry): array {
                $fresh[$key] = $entry;

                return $fresh;
            });
        }
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    if ($duplicateRid > 0) {
        jeab_webshop_sole_bind($store, $duplicateRid, $w);
        return ['ok' => true, 'duplicate' => true, 'number' => $w['number'], 'reminder_id' => $duplicateRid];
    }

    $error = '';
    try {
        $purchase = jeab_purchase_get($rid);
        if (!is_array($purchase)) {
            $purchase = jeab_purchase_start($store, $rid, $kind);
        }
        $purchase = jeab_webshop_mark_purchase($purchase, $w, $kind);
        $live = jeab_get_reminder($store, $rid) ?? $row;
        if (empty($live['completed_at'])) {
            jeab_toggle_reminder($store, $rid, true);
            $live = jeab_get_reminder($store, $rid) ?? $live;
        }
        if (function_exists('jeab_ordrar_autobook_meab_won')) {
            $purchase = jeab_ordrar_autobook_meab_won($store, $live, $purchase);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $purchase = jeab_purchase_get($rid) ?? [];
    jeab_webshop_sole_bind($store, $rid, $w);
    return [
        'ok' => true,
        'number' => $w['number'],
        'company' => $w['company'],
        'reminder_id' => $rid,
        'kind' => (string) ($purchase['kind'] ?? $kind),
        'booking_id' => (int) ($purchase['booking_id'] ?? 0),
        'source' => (string) ($purchase['source'] ?? ''),
        'error' => $error,
    ];
}

function jeab_webshop_mark_purchase(array $purchase, array $w, string $kind): array
{
    $purchase['source'] = 'webshop';
    $purchase['webshop'] = [
        'platform' => 'wix',
        'company' => $w['company'],
        'number' => $w['number'],
        'id' => $w['id'],
        'created' => $w['created'],
        'total' => $w['total'],
        'email' => $w['email'],
        'name' => $w['name'],
        'lines' => $w['lines'],
    ];
    if ($kind !== '' && trim((string) ($purchase['kind'] ?? '')) === '') {
        $purchase['kind'] = $kind;
    }
    foreach (['email' => 'email', 'phone' => 'phone'] as $from => $to) {
        if (trim((string) ($purchase[$to] ?? '')) === '' && $w[$from] !== '') {
            $purchase[$to] = $w[$from];
        }
    }
    if (trim((string) ($purchase['kundpris'] ?? '')) === '' && (float) $w['total'] > 0) {
        $purchase['kundpris'] = (string) (int) round((float) $w['total']);
    }
    if (function_exists('jeab_ordrar_append_shop_activity_row')) {
        $purchase = jeab_ordrar_append_shop_activity_row($purchase, 'Från webshoppen (Wix order ' . $w['number'] . ').', 'webshop-' . $w['number']);
    }
    $purchase['updated_at'] = jeab_now();
    return jeab_purchase_put($purchase);
}

function jeab_webshop_is_purchase(array $row): bool
{
    return ($row['source'] ?? '') === 'webshop' && is_array($row['webshop'] ?? null) && trim((string) ($row['webshop']['number'] ?? '')) !== '';
}

function jeab_webshop_norm(string $s): string
{
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    return trim((string) preg_replace('/\s+/u', ' ', $s));
}

/** Alla ord i Wix-namnet finns i Fortnox-namnet («Olof Bladh» ⊂ «Jan Olof Martin Bladh»). */
function jeab_webshop_same_name(string $wixNorm, string $fortnox): bool
{
    $a = array_values(array_filter(explode(' ', jeab_webshop_norm($wixNorm))));
    $b = array_values(array_filter(explode(' ', jeab_webshop_norm($fortnox))));
    if (count($a) < 2 || $b === []) {
        return false;
    }
    return array_diff($a, $b) === [];
}

/**
 * Letar efter webshopordern i Fortnox. Kastar om Fortnox inte går att läsa.
 *
 * @return list<array<string, mixed>> träffar, starkast först
 */
function jeab_webshop_fortnox_find(array $webshop, ?FortnoxAPI $api = null): array
{
    $number = trim((string) ($webshop['number'] ?? ''));
    $company = jeab_normalize_company($webshop['company'] ?? null) ?? 'jarfalla';
    if ($api === null) {
        $api = jeab_ordrar_fortnox_api_for(['company' => $company]);
    }
    $created = trim((string) ($webshop['created'] ?? ''));
    $from = $created !== '' ? (new DateTimeImmutable($created))->setTimezone(new DateTimeZone('Europe/Stockholm'))->modify('-1 day') : new DateTimeImmutable('-14 days');
    $list = $api->listOrders(['fromdate' => $from->format('Y-m-d'), 'limit' => 200]);
    $GLOBALS['_JEAB_WEBSHOP_SCAN'] = ['from' => $from->format('Y-m-d'), 'scanned' => count($list), 'recent' => array_map(
        static fn (array $o): array => [
            'order' => (string) ($o['DocumentNumber'] ?? ''),
            'date' => (string) ($o['OrderDate'] ?? ''),
            'customer' => (string) ($o['CustomerName'] ?? ''),
            'total' => (string) ($o['Total'] ?? ''),
            'ref' => trim((string) ($o['YourOrderNumber'] ?? '') . ' ' . (string) ($o['ExternalInvoiceReference1'] ?? '')),
        ],
        array_slice(array_values(array_filter($list, 'is_array')), 0, 15)
    )];
    $total = (float) ($webshop['total'] ?? 0);
    $email = jeab_webshop_norm((string) ($webshop['email'] ?? ''));
    $name = jeab_webshop_norm((string) ($webshop['name'] ?? ''));
    $hits = [];
    foreach ($list as $o) {
        if (!is_array($o) || !empty($o['Cancelled'])) {
            continue;
        }
        $doc = (string) ($o['DocumentNumber'] ?? '');
        $listRefs = implode(' ', array_map('strval', [$o['YourOrderNumber'] ?? '', $o['ExternalInvoiceReference1'] ?? '', $o['ExternalInvoiceReference2'] ?? '', $o['YourReference'] ?? '']));
        $sameTotal = $total > 0 && abs((float) ($o['Total'] ?? 0) - $total) <= 1.0;
        $sameName = jeab_webshop_same_name($name, (string) ($o['CustomerName'] ?? ''));
        $refHit = $number !== '' && preg_match('/(?<!\d)' . preg_quote($number, '/') . '(?!\d)/', $listRefs);
        if (!$refHit && !$sameTotal && !$sameName) {
            continue;
        }
        $full = $api->getOrder($doc);
        $f = is_array($full['Order'] ?? null) ? $full['Order'] : $full;
        $refs = implode(' ', array_map('strval', [
            $f['YourOrderNumber'] ?? '', $f['ExternalInvoiceReference1'] ?? '', $f['ExternalInvoiceReference2'] ?? '',
            $f['YourReference'] ?? '', $f['Remarks'] ?? '', $f['OurReference'] ?? '',
        ]));
        $strong = $number !== '' && preg_match('/(?<!\d)' . preg_quote($number, '/') . '(?!\d)/', $refs);
        $fEmail = jeab_webshop_norm((string) ($f['EmailInformation']['EmailAddressTo'] ?? ''));
        $sameCustomer = ($email !== '' && $fEmail === $email) || $sameName;
        $fTotal = abs((float) ($f['Total'] ?? $o['Total'] ?? 0) - $total) <= 1.0;
        // Fortnox-totalen är före skattereduktion och skiljer sig från Wix. Samma kund
        // från orderdagen och framåt räcker som träff.
        if (!$strong && !$sameCustomer) {
            continue;
        }
        $hits[] = [
            'order' => $doc,
            'customer' => (string) ($f['CustomerNumber'] ?? $o['CustomerNumber'] ?? ''),
            'match' => $strong ? 'wix_number' : ($fTotal ? 'customer_total' : 'customer'),
            'total' => (string) ($f['Total'] ?? ''),
            'date' => (string) ($f['OrderDate'] ?? ''),
            'invoice_reference' => trim((string) ($f['InvoiceReference'] ?? '')),
            'refs' => [
                'YourOrderNumber' => (string) ($f['YourOrderNumber'] ?? ''),
                'ExternalInvoiceReference1' => (string) ($f['ExternalInvoiceReference1'] ?? ''),
                'ExternalInvoiceReference2' => (string) ($f['ExternalInvoiceReference2'] ?? ''),
                'YourReference' => (string) ($f['YourReference'] ?? ''),
                'Remarks' => mb_substr((string) ($f['Remarks'] ?? ''), 0, 200),
            ],
        ];
    }
    usort($hits, static fn (array $a, array $b): int => ($a['match'] === 'wix_number' ? 0 : 1) <=> ($b['match'] === 'wix_number' ? 0 : 1));
    return $hits;
}

function jeab_webshop_hit_invoice_reference(array $hit): string
{
    $ref = trim((string) ($hit['invoice_reference'] ?? $hit['InvoiceReference'] ?? ''));
    if ($ref === '' || $ref === '0') {
        return '';
    }
    if (function_exists('jeab_fortnox_order_link_doc')) {
        return jeab_fortnox_order_link_doc($ref);
    }
    return preg_match('/\A[0-9]{1,12}\z/', $ref) === 1 ? $ref : '';
}

/** FO med InvoiceReference till en krediterad eller makulerad faktura återanvänds inte. */
function jeab_webshop_hit_released_invoice(array $hit, array $row): bool
{
    $ref = jeab_webshop_hit_invoice_reference($hit);
    if ($ref === '') {
        return false;
    }
    if (!function_exists('jeab_fortnox_order_export_released') || !function_exists('jeab_fortnox_order_link_read_invoice')) {
        require_once __DIR__ . '/fortnox_order_link.php';
    }
    $company = function_exists('jeab_normalize_company') ? (jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla') : 'jarfalla';
    $account = function_exists('jeab_ordrar_fortnox_account')
        ? jeab_ordrar_fortnox_account($company)
        : ($company === 'malmo' ? 'Malmo' : 'Jarfalla');
    $loaded = jeab_fortnox_order_link_read_invoice($account, $ref);
    $invoice = is_array($loaded['invoice'] ?? null) ? $loaded['invoice'] : [];
    if ($invoice === []) {
        return false;
    }
    return jeab_fortnox_order_export_released($invoice);
}

/**
 * @param list<array<string,mixed>> $hits
 * @param array<string,mixed> $row
 * @return list<array<string,mixed>>
 */
function jeab_webshop_hits_reusable(array $hits, array $row): array
{
    $kept = [];
    foreach ($hits as $hit) {
        if (!is_array($hit) || trim((string) ($hit['order'] ?? '')) === '') {
            continue;
        }
        if (jeab_webshop_hit_released_invoice($hit, $row)) {
            continue;
        }
        $kept[] = $hit;
    }
    return $kept;
}

/**
 * Länkar en befintlig Fortnox-order till webshop-ordern om den finns. Skriver aldrig.
 *
 * @return array<string, mixed>|null uppdaterad rad om länkad
 */
function jeab_webshop_fortnox_link(array $row): ?array
{
    if (!jeab_webshop_is_purchase($row) || trim((string) ($row['fortnox_order'] ?? '')) !== '') {
        return null;
    }
    $webshop = $row['webshop'];
    $webshop['company'] = $row['company'] ?? $webshop['company'] ?? null;
    $hits = isset($GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND']) && is_callable($GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND'])
        ? ($GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND'])($webshop)
        : jeab_webshop_fortnox_find($webshop);
    $hits = jeab_webshop_hits_reusable($hits, $row);
    if (!$hits) {
        return null;
    }
    $hit = $hits[0];
    $row['fortnox_order'] = (string) $hit['order'];
    if (trim((string) ($row['fortnox_customer'] ?? '')) === '' && (string) ($hit['customer'] ?? '') !== '') {
        $row['fortnox_customer'] = (string) $hit['customer'];
    }
    $row['webshop']['fortnox_linked'] = ['order' => (string) $hit['order'], 'match' => (string) $hit['match'], 'at' => jeab_now()];
    if (!is_array($row['steps'] ?? null)) {
        $row['steps'] = [];
    }
    if (trim((string) ($row['steps']['order_skriven'] ?? '')) === '') {
        $row['steps']['order_skriven'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
    }
    if (function_exists('jeab_ordrar_append_shop_activity_row')) {
        $row = jeab_ordrar_append_shop_activity_row($row, 'Fortnox-order ' . $hit['order'] . ' fanns redan (webshop). Ingen ny skrevs.', 'webshop-fortnox-' . $hit['order']);
    }
    $row['updated_at'] = jeab_now();
    return jeab_purchase_put($row);
}

/**
 * Före varje ny Fortnox-skrivning. Returnerar länkad rad, eller null = skriv som vanligt.
 */
function jeab_webshop_precheck(array $row): ?array
{
    if (trim((string) ($row['fortnox_order'] ?? '')) !== '') {
        return null;
    }
    if (jeab_webshop_is_purchase($row)) {
        try {
            $linked = jeab_webshop_fortnox_link($row);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Kunde inte kolla Fortnox efter webshopordern. Inget skrevs. Försök igen.');
        }
        if (is_array($linked)) {
            return $linked;
        }
        return null; // Saknas i Fortnox: flowstack.example.test skriver (integrationer.se används inte längre).
    }
    // Formulär/telefon: finns kunden redan som webshoporder i Fortnox? Länka i stället.
    $name = trim((string) ($row['huvudsokande'] ?? $row['name'] ?? ''));
    $email = trim((string) ($row['email'] ?? ''));
    if ($name === '' && $email === '') {
        return null;
    }
    $probe = [
        'number' => '', 'company' => $row['company'] ?? null, 'name' => $name, 'email' => $email, 'total' => '',
        'created' => trim((string) ($row['reminder_created_at'] ?? $row['created_at'] ?? '')),
    ];
    try {
        $hits = isset($GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND']) && is_callable($GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND'])
            ? ($GLOBALS['_JEAB_WEBSHOP_FORTNOX_FIND'])($probe)
            : jeab_webshop_fortnox_find($probe);
    } catch (Throwable) {
        return null;
    }
    $hits = jeab_webshop_hits_reusable($hits, $row);
    if (!$hits) {
        return null;
    }
    $hit = $hits[0];
    $row['fortnox_order'] = (string) $hit['order'];
    if (trim((string) ($row['fortnox_customer'] ?? '')) === '' && (string) ($hit['customer'] ?? '') !== '') {
        $row['fortnox_customer'] = (string) $hit['customer'];
    }
    $row['webshop_link'] = ['order' => (string) $hit['order'], 'match' => (string) $hit['match'], 'at' => jeab_now()];
    if (!is_array($row['steps'] ?? null)) {
        $row['steps'] = [];
    }
    if (trim((string) ($row['steps']['order_skriven'] ?? '')) === '') {
        $row['steps']['order_skriven'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y-m-d');
    }
    if (function_exists('jeab_ordrar_append_shop_activity_row')) {
        $row = jeab_ordrar_append_shop_activity_row($row, 'Fortnox-order ' . $hit['order'] . ' fanns redan (webshop). Ingen ny skrevs.', 'webshop-fortnox-' . $hit['order']);
    }
    $row['updated_at'] = jeab_now();
    return jeab_purchase_put($row);
}

/** Vid sidvisning: webshoporder utan Fortnox-order försöker länka (högst var 10:e minut). */
function jeab_webshop_view_sync(array $row): array
{
    return $row;
}

/** @return array<string, mixed> */
function jeab_webshop_hook(JeabStore $store, array $input): array
{
    $action = (string) ($input['action'] ?? '');
    switch ($action) {
        case 'webshop.order':
            return jeab_webshop_ingest($store, $input);
        case 'webshop.webhook_key':
            // Admin (inkorg-token): skapar/visar webhook-nyckeln för Wix-automationen.
            return ['ok' => true, 'key' => jeab_webshop_webhook_key_ensure()];
        case 'webshop.wix_secret':
            $k = trim((string) ($input['api_key'] ?? ''));
            if ($k === '') {
                return ['ok' => true, 'has_key' => trim((string) (jeab_webshop_secret_read('wix-api.json')['api_key'] ?? '')) !== ''];
            }
            jeab_webshop_secret_write('wix-api.json', ['api_key' => $k, 'account_id' => trim((string) ($input['account_id'] ?? '')), 'saved_at' => jeab_now()]);
            return ['ok' => true, 'saved' => true];
        case 'webshop.catchup':
            return jeab_webshop_catchup($store, ($input['dry'] ?? true) !== false, max(1, min(14, (int) ($input['days'] ?? 2))));
        case 'webshop.classify':
            return jeab_webshop_classify($input);
        case 'webshop.status':
            $index = jeab_webshop_index();
            $out = [];
            foreach ($index as $k => $v) {
                $p = jeab_purchase_get((int) ($v['reminder_id'] ?? 0)) ?? [];
                $out[] = [
                    'key' => $k,
                    'reminder_id' => (int) ($v['reminder_id'] ?? 0),
                    'kind' => (string) ($p['kind'] ?? ''),
                    'booking_id' => (int) ($p['booking_id'] ?? 0),
                    'fortnox_order' => (string) ($p['fortnox_order'] ?? ''),
                    'source' => (string) ($p['source'] ?? ''),
                ];
            }
            return ['ok' => true, 'orders' => $out];
        case 'webshop.fortnox_check':
            // Läser bara. Visar vilka Fortnox-ordrar som matchar webshopordern.
            $company = jeab_normalize_company($input['company'] ?? null);
            $number = trim((string) ($input['number'] ?? ''));
            $key = $company !== null ? jeab_webshop_key($company, $number) : '';
            $rid = (int) (jeab_webshop_index()[$key]['reminder_id'] ?? 0);
            $p = $rid > 0 ? (jeab_purchase_get($rid) ?? []) : [];
            $webshop = is_array($p['webshop'] ?? null) ? $p['webshop'] : [
                'number' => $number, 'company' => $company,
                'total' => (string) ($input['total'] ?? ''), 'name' => (string) ($input['name'] ?? ''),
                'email' => (string) ($input['email'] ?? ''), 'created' => (string) ($input['created'] ?? ''),
            ];
            $webshop['company'] = $company;
            try {
                $hits = jeab_webshop_fortnox_find($webshop);
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => 'fortnox: ' . $e->getMessage()];
            }
            return ['ok' => true, 'reminder_id' => $rid, 'fortnox_order' => (string) ($p['fortnox_order'] ?? ''), 'hits' => $hits, 'scan' => $GLOBALS['_JEAB_WEBSHOP_SCAN'] ?? null];
        case 'webshop.fortnox_link':
            $company = jeab_normalize_company($input['company'] ?? null);
            $key = $company !== null ? jeab_webshop_key($company, trim((string) ($input['number'] ?? ''))) : '';
            $rid = (int) (jeab_webshop_index()[$key]['reminder_id'] ?? 0);
            $p = $rid > 0 ? jeab_purchase_get($rid) : null;
            if (!is_array($p)) {
                return ['ok' => false, 'error' => 'not_found'];
            }
            try {
                $linked = jeab_webshop_fortnox_link($p);
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => 'fortnox: ' . $e->getMessage()];
            }
            $p = jeab_purchase_get($rid) ?? $p;
            return ['ok' => true, 'linked' => $linked !== null, 'fortnox_order' => (string) ($p['fortnox_order'] ?? '')];
        case 'webshop.write':
            // Förkoll + skriv (länkar om ordern redan finns i Fortnox).
            $company = jeab_normalize_company($input['company'] ?? null);
            $key = $company !== null ? jeab_webshop_key($company, trim((string) ($input['number'] ?? ''))) : '';
            $rid = (int) (jeab_webshop_index()[$key]['reminder_id'] ?? 0);
            $p = $rid > 0 ? jeab_purchase_get($rid) : null;
            if (!is_array($p)) {
                return ['ok' => false, 'error' => 'not_found'];
            }
            $full = function_exists('jeab_ordrar_backfill_lookup') ? jeab_ordrar_backfill_lookup($p, null, false) : $p;
            $missing = jeab_ordrar_fortnox_missing($full);
            if ($missing) {
                return ['ok' => false, 'error' => 'missing', 'missing' => $missing];
            }
            try {
                $row = jeab_ordrar_create_fortnox(['id' => $rid]);
            } catch (InvalidArgumentException $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }
            return ['ok' => true, 'reminder_id' => $rid, 'fortnox_order' => (string) ($row['fortnox_order'] ?? ''), 'linked' => isset($row['webshop']['fortnox_linked'])];
    }
    return ['ok' => false, 'error' => 'unknown_action'];
}

function jeab_webshop_assignee(string $company, string $kind): string
{
    if (function_exists('jeab_user_responsibility_for')) {
        $region = $company === 'malmo' ? 'skane' : 'stockholm';
        $pid = in_array($kind, ['batteri', 'laddbox', 'sol'], true) ? jeab_user_responsibility_for($kind, $region, 'owner') : '';
        if ($pid === '' && $company === 'malmo') {
            $pid = jeab_user_responsibility_for('batteri', 'skane', 'owner');
        }
        return $pid === '' ? '' : jeab_user_responsibility_name($pid);
    }
    if ($company === 'malmo') {
        return 'Kim';
    }
    return match ($kind) {
        'laddbox' => 'Elin',
        'batteri' => 'Roberto',
        'sol' => 'Sam L',
        default => '',
    };
}

/** Ogiltigt nummer sparas inte. Ordern ska ändå skapas. */
function jeab_webshop_reminder_phone(string $raw): string
{
    if (!function_exists('jeab_normalize_reminder_phone')) {
        return trim($raw);
    }
    try {
        return (string) (jeab_normalize_reminder_phone($raw) ?? '');
    } catch (InvalidArgumentException) {
        return '';
    }
}

function jeab_webshop_mail_text(array $msg): string
{
    $body = (string) ($msg['body'] ?? $msg['body_text'] ?? '');
    if (function_exists('jeab_lead_customer_plain')) {
        $body = jeab_lead_customer_plain($body);
    }
    return trim((string) ($msg['subject'] ?? '') . "\n" . $body);
}

function jeab_webshop_mail_number(string $text): string
{
    if (preg_match('/(?:order(?:\s*(?:nummer|number))?|beställningsnummer)\s*[#:]?\s*#?\s*(\d{3,12})/iu', $text, $m) === 1) {
        return $m[1];
    }
    return '';
}

function jeab_webshop_mail_unpaid(string $text): bool
{
    if (preg_match('/\b(avbruten|annullerad|makulerad|cancelled|canceled|övergiven|overgiven|abandoned|obetald|unpaid)\b/iu', $text) === 1) {
        return true;
    }
    if (preg_match('/\b(?:ej|inte|not)\s+(?:betalt|betald|paid)\b/iu', $text) === 1) {
        return true;
    }
    return preg_match('/väntar på betalning|awaiting payment|payment pending|payment failed/iu', $text) === 1;
}

function jeab_webshop_mail_orderish(string $text): bool
{
    if (jeab_webshop_mail_number($text) !== '') {
        return true;
    }
    return preg_match('/beställning|orderbekräftelse|order confirmation|new order|abandoned cart|övergiven varukorg|varukorg/iu', $text) === 1;
}

function jeab_webshop_mail_is_paid(array $msg): bool
{
    if (!function_exists('jeab_lead_is_store_mail') || !jeab_lead_is_store_mail($msg)) {
        return false;
    }
    $text = jeab_webshop_mail_text($msg);
    if (jeab_webshop_mail_unpaid($text)) {
        return false;
    }
    if (preg_match('/(?:\b(?:betalt|betald|paid)\b|payment received|betalning mottagen|har betalats)/iu', $text) !== 1) {
        return false;
    }
    return jeab_webshop_mail_number($text) !== '';
}

function jeab_webshop_mail_company(array $msg): string
{
    $src = (string) ($msg['source'] ?? '');
    $box = mb_strtolower((string) ($msg['graph_mailbox'] ?? '') . ' ' . (string) ($msg['to'] ?? ''));
    if ($src === 'graph-malmo' || str_contains($box, 'malmo')) {
        return 'malmo';
    }
    if ($src === 'graph-jarfalla' || str_contains($box, 'jarfalla') || str_contains($box, 'järfälla')) {
        return 'jarfalla';
    }
    $blob = (string) ($msg['subject'] ?? '') . "\n" . (string) ($msg['body'] ?? $msg['body_text'] ?? '');
    if (preg_match('/Malmö\s+Energi/u', $blob) === 1) {
        return 'malmo';
    }
    if (preg_match('/Järfälla\s+Energi/u', $blob) === 1) {
        return 'jarfalla';
    }
    $co = (string) ($msg['company'] ?? '');
    return $co === 'malmo' ? 'malmo' : 'jarfalla';
}

function jeab_webshop_mail_total(string $text): string
{
    if (preg_match('/(?:totalt|total|summa|att betala|amount paid|grand total)\s*[:\-]?\s*([0-9][0-9 \x{00A0}]*(?:[.,][0-9]{1,2})?)/iu', $text, $m) !== 1) {
        return '';
    }
    $n = str_replace(["\u{00A0}", ' '], '', $m[1]);
    $n = str_replace(',', '.', $n);
    return is_numeric($n) ? $n : '';
}

/** @return list<array{title:string,options:string,qty:int,price:string,sku:string}> */
function jeab_webshop_mail_lines(string $text): array
{
    $lines = [];
    if (preg_match_all('/(?:^|\n)\s*(\d+)\s*[x×]\s*(.+)$/imu', $text, $all, PREG_SET_ORDER)) {
        foreach ($all as $m) {
            $title = trim((string) $m[2]);
            $title = preg_replace('/\s+[0-9][0-9 \x{00A0}]*(?:[.,][0-9]{1,2})?\s*(?:kr|sek)\b.*$/iu', '', $title) ?? $title;
            $title = trim($title, " \t-–");
            if ($title !== '' && mb_strlen($title) < 160) {
                $lines[] = ['title' => $title, 'options' => '', 'qty' => max(1, (int) $m[1]), 'price' => '', 'sku' => ''];
            }
        }
    }
    return $lines;
}

/** @return array<string, mixed> */
function jeab_webshop_mail_parse(array $msg): array
{
    $text = jeab_webshop_mail_text($msg);
    $plain = function_exists('jeab_lead_customer_plain')
        ? jeab_lead_customer_plain((string) ($msg['body'] ?? $msg['body_text'] ?? ''))
        : (string) ($msg['body'] ?? $msg['body_text'] ?? '');
    $cust = function_exists('jeab_lead_customer')
        ? jeab_lead_customer(['from' => (string) ($msg['from'] ?? ''), 'from_name' => (string) ($msg['from_name'] ?? ''), 'subject' => (string) ($msg['subject'] ?? ''), 'body' => $plain])
        : ['name' => '', 'email' => '', 'phone' => '', 'address' => ''];
    $name = trim((string) ($cust['name'] ?? ''));
    $bits = preg_split('/\s+/u', $name) ?: [];
    $first = (string) array_shift($bits);
    $last = trim(implode(' ', $bits));
    $address = trim((string) ($cust['address'] ?? ''));
    $parsed = function_exists('jeab_kontakt_parse_address') ? jeab_kontakt_parse_address($address) : ['street' => $address, 'postal' => '', 'city' => ''];
    $lines = jeab_webshop_mail_lines($text);
    $phone = jeab_webshop_reminder_phone((string) ($cust['phone'] ?? ''));
    $company = jeab_webshop_mail_company($msg);
    $order = [
        'number' => jeab_webshop_mail_number($text),
        'status' => 'APPROVED',
        'paymentStatus' => 'PAID',
        'priceSummary' => ['total' => ['amount' => jeab_webshop_mail_total($text)]],
        'buyerInfo' => ['email' => (string) ($cust['email'] ?? '')],
        'billingInfo' => [
            'email' => (string) ($cust['email'] ?? ''),
            'address' => [
                'addressLine' => (string) ($parsed['street'] ?? ''),
                'postalCode' => (string) ($parsed['postal'] ?? ''),
                'city' => (string) ($parsed['city'] ?? ''),
            ],
            'contactDetails' => ['firstName' => $first, 'lastName' => $last, 'phone' => $phone],
        ],
        'lineItems' => array_map(static fn (array $l): array => [
            'productName' => ['original' => $l['title']],
            'quantity' => $l['qty'],
            'price' => ['amount' => $l['price']],
        ], $lines),
    ];
    return [
        'number' => (string) $order['number'],
        'company' => $company,
        'lines' => $lines,
        'customer' => [
            'name' => $name,
            'email' => mb_strtolower(trim((string) ($cust['email'] ?? ''))),
            'phone' => (string) ($cust['phone'] ?? ''),
            'address' => $address,
        ],
        'input' => ['company' => $company, 'order' => $order],
    ];
}

function jeab_webshop_builtin_rule(): ?array
{
    if (!function_exists('jeab_inkorg_rules_ordered')) {
        return null;
    }
    foreach (jeab_inkorg_rules_ordered() as $rule) {
        if ((string) ($rule['id'] ?? '') === 'builtin-webshop-order') {
            return $rule;
        }
    }
    return null;
}

/** @return array<string, mixed> */
function jeab_webshop_label_row(string $type, array $rule, string $reason): array
{
    return [
        'type' => $type,
        'action' => jeab_inkorg_derived_action($type),
        'priority' => jeab_inkorg_default_priority($type),
        'confidence' => max(0, min(100, (int) ($rule['confidence'] ?? 100))),
        'source' => 'rule',
        'rule_id' => (string) ($rule['id'] ?? 'builtin-webshop-order'),
        'reason' => mb_substr($reason, 0, 200),
        'add_tags' => [],
    ];
}

/** Betald butiksorder → Order. Obetald butiksorder → Information. Annars null. */
function jeab_webshop_mail_labels(array $msg): ?array
{
    if (!function_exists('jeab_lead_is_store_mail') || !jeab_lead_is_store_mail($msg)) {
        return null;
    }
    $rule = jeab_webshop_builtin_rule();
    if ($rule === null || empty($rule['enabled'])) {
        return null;
    }
    $paid = jeab_webshop_mail_is_paid($msg);
    $matches = function_exists('jeab_inkorg_rule_matches') && jeab_inkorg_rule_matches($rule, $msg);
    $text = jeab_webshop_mail_text($msg);
    if ($paid && $matches) {
        $type = jeab_inkorg_type_new((string) ($rule['type'] ?? ''));
        if (!in_array($type, jeab_inkorg_type_list(), true) || in_array($type, ['Information', 'Ny lead', 'Oklassad'], true)) {
            $type = 'Order';
        }
        return jeab_webshop_label_row($type, $rule, 'Regel: ' . (string) ($rule['name'] ?? ''));
    }
    if (!$paid && (jeab_webshop_mail_orderish($text) || $matches)) {
        return jeab_webshop_label_row('Information', $rule, 'Obetald eller avbruten webbshoporder');
    }
    return null;
}

function jeab_webshop_phone_tail(string $phone): string
{
    $norm = function_exists('jeab_norm_phone') ? jeab_norm_phone($phone) : '';
    $digits = preg_replace('/\D+/', '', $norm !== '' ? $norm : $phone) ?? '';
    if (strlen($digits) > 9) {
        return substr($digits, -9);
    }
    return $digits;
}

function jeab_webshop_customer_address(array $c): string
{
    $full = trim((string) ($c['address'] ?? ''));
    if ($full !== '') {
        return $full;
    }
    $street = trim((string) ($c['street'] ?? ''));
    $zip = trim((string) ($c['postnr'] ?? $c['postal'] ?? ''));
    $city = trim((string) ($c['ort'] ?? $c['city'] ?? ''));
    return trim($street . ($zip !== '' || $city !== '' ? ', ' . trim($zip . ' ' . $city) : ''), ' ,');
}

function jeab_webshop_same_customer(array $a, array $b): bool
{
    $ea = mb_strtolower(trim((string) ($a['email'] ?? '')));
    $eb = mb_strtolower(trim((string) ($b['email'] ?? '')));
    if ($ea !== '' && $ea === $eb) {
        return true;
    }
    $pa = jeab_webshop_phone_tail((string) ($a['phone'] ?? ''));
    $pb = jeab_webshop_phone_tail((string) ($b['phone'] ?? ''));
    if (strlen($pa) >= 9 && $pa === $pb) {
        return true;
    }
    if (!function_exists('jeab_kontakt_parse_address')) {
        return false;
    }
    $aa = jeab_kontakt_parse_address(jeab_webshop_customer_address($a));
    $ab = jeab_kontakt_parse_address(jeab_webshop_customer_address($b));
    return $aa['street_key'] !== '' && $aa['postal'] !== ''
        && $aa['street_key'] === $ab['street_key'] && $aa['postal'] === $ab['postal'];
}

function jeab_webshop_lead_product(array $thread, array $msg): string
{
    $tags = (array) ($thread['tags'] ?? []);
    $offer = function_exists('jeab_lead_offer') ? jeab_lead_offer((array) ($msg['labels'] ?? []), $tags) : '';
    if ($offer !== '') {
        return $offer;
    }
    if (function_exists('jeab_lead_infer_offer')) {
        $offer = jeab_lead_infer_offer($msg, $tags);
        if ($offer !== '') {
            return $offer;
        }
    }
    $text = (string) ($msg['subject'] ?? '') . "\n" . (string) ($msg['body'] ?? '');
    return jeab_webshop_kind(['lines' => [['title' => $text, 'options' => '', 'sku' => '']]]);
}

/** @return list<array{thread_id:string,product:string}> */
function jeab_webshop_open_leads(string $orderTid, array $customer, string $kind): array
{
    if ($kind === '' || !function_exists('jeab_inkorg_threads') || !function_exists('jeab_lead_is_lead')) {
        return [];
    }
    $out = [];
    foreach (jeab_inkorg_threads() as $t) {
        if (!is_array($t)) {
            continue;
        }
        $tid = (string) ($t['id'] ?? '');
        $leadStatus = (string) ($t['status'] ?? '');
        $reminderShut = $leadStatus === 'closed'
            && $tid !== ''
            && function_exists('jeab_inkorg_thread_meta')
            && (string) (jeab_inkorg_thread_meta($tid)['hook_closed'] ?? '') === 'reminder';
        if ($tid === '' || $tid === $orderTid || !empty($t['deleted']) || ($leadStatus !== 'open' && !$reminderShut)) {
            continue;
        }
        // Trådens test_lead och taggen test utelämnas. Första mejlets test/is_test
        // kommer från intagets test-flagga och får inte tömma den torra träfflistan.
        if (function_exists('jeab_thread_counts_as_production_lead') && !jeab_thread_counts_as_production_lead($t)) {
            continue;
        }
        if (function_exists('jeab_hs_import_frozen') && jeab_hs_import_frozen($t)) {
            continue;
        }
        $msg = function_exists('jeab_auto_quote_lead_message') ? jeab_auto_quote_lead_message($t) : (array) ($t['first'] ?? []);
        if ($msg === [] || (function_exists('jeab_lead_is_store_mail') && jeab_lead_is_store_mail($msg)) || !jeab_lead_is_lead($t, $msg)) {
            continue;
        }
        $offer = jeab_webshop_lead_product($t, $msg);
        if ($offer === '' || $offer !== $kind) {
            continue;
        }
        $cust = is_array($t['lead_customer'] ?? null) ? $t['lead_customer'] : [];
        $parsed = jeab_lead_customer($msg);
        foreach (['email', 'phone', 'address', 'name'] as $k) {
            if (trim((string) ($cust[$k] ?? '')) === '') {
                $cust[$k] = (string) ($parsed[$k] ?? '');
            }
        }
        if (!jeab_webshop_same_customer($customer, $cust)) {
            continue;
        }
        $out[] = ['thread_id' => $tid, 'product' => $offer];
    }
    return $out;
}

function jeab_webshop_order_link(int $rid, string $company): string
{
    $company = $company === 'malmo' ? 'malmo' : 'jarfalla';
    return 'https://flowstack.example.test/index.php?p=ordrar&id=' . $rid . '&co=' . $company;
}

function jeab_webshop_note_once(string $tid, string $body): void
{
    foreach (jeab_inkorg_threads() as $t) {
        if ((string) ($t['id'] ?? '') !== $tid) {
            continue;
        }
        foreach ((array) ($t['drafts'] ?? []) as $d) {
            if (is_array($d) && str_contains((string) ($d['body'] ?? ''), $body)) {
                return;
            }
        }
    }
    jeab_inkorg_add_draft($tid, ['kind' => 'note', 'body' => $body], 'Webshop', 'webshop-order');
}

function jeab_webshop_mark_message(string $id, array $labels): void
{
    if ($id === '' || !function_exists('jeab_inkorg_update')) {
        return;
    }
    jeab_inkorg_update($id, static function (array $m) use ($labels): array {
        $m['labels'] = $labels + (array) ($m['labels'] ?? []);
        return $m;
    });
}

function jeab_webshop_sole_bind_pending(int $rid): bool
{
    if ($rid <= 0 || !function_exists('jeab_purchase_get')) {
        return false;
    }
    $row = jeab_purchase_get($rid);

    return is_array($row) && !empty($row['webshop']['sole_bind_pending']);
}

function jeab_webshop_sole_bind_mark_pending(int $rid): void
{
    if ($rid <= 0 || !function_exists('jeab_purchase_patch')) {
        return;
    }
    jeab_purchase_patch($rid, static function (array $row): array {
        $webshop = is_array($row['webshop'] ?? null) ? $row['webshop'] : [];
        $webshop['sole_bind_pending'] = true;
        $row['webshop'] = $webshop;

        return $row;
    });
}

function jeab_webshop_sole_bind_clear_pending(int $rid): void
{
    if ($rid <= 0 || !jeab_webshop_sole_bind_pending($rid) || !function_exists('jeab_purchase_patch')) {
        return;
    }
    jeab_purchase_patch($rid, static function (array $row): array {
        if (is_array($row['webshop'] ?? null)) {
            unset($row['webshop']['sole_bind_pending']);
        }

        return $row;
    });
}

/** Känd order där bindningen föll. Ingest körs inte om. */
function jeab_webshop_sole_bind_retry(JeabStore $store, int $rid, array $order, string $siteId): void
{
    if ($rid <= 0 || !jeab_webshop_sole_bind_pending($rid)) {
        return;
    }
    $bound = jeab_webshop_sole_bind($store, $rid, jeab_webshop_normalize(['order' => $order, 'site_id' => $siteId]));
    if ($bound !== '') {
        jeab_webshop_sole_bind_clear_pending($rid);
    }
}

/** Kontakt och contact_id när flaggan är på. Flaggan av rör inget. */
function jeab_webshop_sole_bind(JeabStore $store, int $rid, array $w): string
{
    if (isset($GLOBALS['_JEAB_WEBSHOP_SOLE_BIND']) && is_callable($GLOBALS['_JEAB_WEBSHOP_SOLE_BIND'])) {
        return (string) ($GLOBALS['_JEAB_WEBSHOP_SOLE_BIND'])($store, $rid, $w);
    }
    if ($rid <= 0 || !function_exists('jeab_contacts_sole') || !jeab_contacts_sole()) {
        return '';
    }
    if (!function_exists('jeab_contacts_sole_inflow_upsert') || !function_exists('jeab_contacts_sole_inflow_link')) {
        return '';
    }
    try {
        $contact = jeab_contacts_sole_inflow_upsert([
            'name' => (string) ($w['name'] ?? ''),
            'email' => (string) ($w['email'] ?? ''),
            'phone' => (string) ($w['phone'] ?? ''),
            'address' => (string) ($w['address'] ?? ''),
            'street' => (string) ($w['street'] ?? ''),
            'postal_code' => (string) ($w['postnr'] ?? ''),
            'city' => (string) ($w['ort'] ?? ''),
            'company' => (string) ($w['company'] ?? ''),
        ]);
    } catch (Throwable) {
        jeab_webshop_sole_bind_mark_pending($rid);
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('webshop', 'sole-bind misslyckades');
        }

        return '';
    }
    $contactId = is_array($contact) ? trim((string) ($contact['id'] ?? '')) : '';
    if ($contactId === '') {
        return '';
    }
    try {
        jeab_contacts_sole_inflow_link($store, $rid, $contactId, true);
    } catch (Throwable) {
        jeab_webshop_sole_bind_mark_pending($rid);
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('webshop', 'sole-bind misslyckades');
        }

        return '';
    }
    jeab_webshop_sole_bind_clear_pending($rid);

    return $contactId;
}

/** @return array<string, mixed>|null */
function jeab_webshop_contact_upsert(array $w): ?array
{
    if (!function_exists('jeab_kontakt_find_or_create') || !function_exists('jeab_kontakter_load')) {
        return null;
    }
    $name = trim((string) ($w['name'] ?? ''));
    $email = function_exists('jeab_kontakt_email') ? jeab_kontakt_email((string) ($w['email'] ?? '')) : trim((string) ($w['email'] ?? ''));
    if (function_exists('jeab_kontakt_address_blocked') && jeab_kontakt_address_blocked($email)) {
        $email = '';
    }
    $phone = function_exists('jeab_kontakt_phone') ? jeab_kontakt_phone((string) ($w['phone'] ?? '')) : trim((string) ($w['phone'] ?? ''));
    $address = trim((string) ($w['address'] ?? ''));
    $parsed = jeab_kontakt_parse_address($address);
    if ($parsed['postal'] === '' && trim((string) ($w['postnr'] ?? '')) !== '') {
        $scan = jeab_kontakt_postal_scan((string) $w['postnr']);
        if (!$scan['invalid'] && $scan['postal'] !== '') {
            $parsed['postal'] = $scan['postal'];
        }
    }
    if ($parsed['city'] === '' && trim((string) ($w['ort'] ?? '')) !== '') {
        $parsed['city'] = trim((string) $w['ort']);
    }
    $contacts = jeab_kontakter_load();
    $id = null;
    if ($parsed['street_key'] !== '') {
        $id = jeab_kontakt_find($contacts, $parsed['street_key'], $parsed['postal'], '', '', true, $name);
    }
    if ($id === null) {
        $id = jeab_kontakt_find($contacts, '', '', $email, $phone, false, $name);
    }
    if ($id !== null && isset($contacts[$id]) && is_array($contacts[$id])) {
        $contacts[$id]['id'] = $id;
        return $contacts[$id];
    }
    return jeab_kontakt_find_or_create([
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'postal_code' => $parsed['postal'],
        'city' => $parsed['city'],
        'street' => (string) $parsed['street'],
    ], false, false);
}

/**
 * @param list<array{thread_id:string,product:string}> $leads
 * @return list<array<string, mixed>>
 */
function jeab_webshop_close_leads(JeabStore $store, array $leads, int $rid, string $company): array
{
    $url = $rid > 0 ? jeab_webshop_order_link($rid, $company) : '';
    $out = [];
    foreach ($leads as $lead) {
        $tid = (string) $lead['thread_id'];
        $row = ['thread_id' => $tid, 'product' => (string) $lead['product'], 'would_close' => true, 'closed' => false];
        if ($url === '') {
            $out[] = $row;
            continue;
        }
        try {
            jeab_webshop_note_once($tid, "Vunnen.\n" . $url);
            $meta = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($tid) : [];
            if ((string) ($meta['status'] ?? '') !== 'closed') {
                jeab_inkorg_thread_set($tid, 'closed', null);
            }
            $row['closed'] = true;
            $rem = jeab_get_reminder($store, $rid);
            if (is_array($rem) && function_exists('jeab_lead_reminder_append')) {
                jeab_lead_reminder_append($rem, $tid);
            }
        } catch (JeabInkorgWriteException | InvalidArgumentException) {
            $row['closed'] = false;
        }
        $out[] = $row;
    }
    return $out;
}

function jeab_webshop_flag(mixed $v): bool
{
    if ($v === true || $v === 1 || $v === 1.0) {
        return true;
    }
    return is_string($v) && in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'ja'], true);
}

/** @return array<string, mixed> */
function jeab_webshop_inbox_apply(JeabStore $store, array $msg, bool $dry): array
{
    $parsed = jeab_webshop_mail_parse($msg);
    $tid = (string) ($msg['thread_id'] ?? $msg['id'] ?? '');
    $kind = jeab_webshop_kind(['lines' => $parsed['lines']]);
    $leads = jeab_webshop_open_leads($tid, $parsed['customer'], $kind);
    $out = [
        'number' => $parsed['number'],
        'found' => $parsed['number'] !== '' && jeab_webshop_mail_is_paid($msg),
        'thread_id' => $tid,
        'type' => (string) ($msg['labels']['type'] ?? ''),
        'contact' => '',
        'reminder_id' => 0,
        'booking_started' => false,
        'fortnox' => $dry ? 'dry' : 'missing',
        'duplicate' => false,
        'thread_closed' => false,
        'leads' => array_map(static fn (array $l): array => [
            'thread_id' => $l['thread_id'],
            'product' => $l['product'],
            'would_close' => true,
        ], $leads),
    ];
    if (!$out['found'] || $dry) {
        return $out;
    }
    $input = $parsed['input'];
    if (function_exists('jeab_webshop_wix_lookup_number')) {
        $wix = jeab_webshop_wix_lookup_number($parsed['company'], $parsed['number']);
        if (is_array($wix)) {
            if (strtoupper((string) ($wix['paymentStatus'] ?? '')) !== 'PAID') {
                $id = (string) ($msg['id'] ?? '');
                if ($id !== '') {
                    jeab_inkorg_update($id, static function (array $m): array {
                        $m['webshop_api'] = 'not_paid';
                        return $m;
                    });
                }
                $out['skipped'] = 'not_paid';
                $out['fortnox'] = 'dry';
                $out['leads'] = array_map(static fn (array $l): array => $l + ['would_close' => false, 'closed' => false], $out['leads']);
                return $out;
            }
            $site = array_search($parsed['company'], JEAB_WEBSHOP_SITES, true);
            $input = ['order' => $wix, 'company' => $parsed['company'], 'site_id' => is_string($site) ? $site : ''];
        }
    }
    try {
        $ing = jeab_webshop_ingest($store, $input);
    } catch (Throwable) {
        $out['fortnox'] = 'unread';
        return $out;
    }
    if (!empty($ing['skipped'])) {
        $out['skipped'] = (string) $ing['skipped'];
        return $out;
    }
    $rid = (int) ($ing['reminder_id'] ?? 0);
    $out['reminder_id'] = $rid;
    $out['duplicate'] = !empty($ing['duplicate']);
    $out['booking_started'] = empty($ing['duplicate']) && (int) ($ing['booking_id'] ?? 0) > 0;
    $purchase = $rid > 0 ? (jeab_purchase_get($rid) ?? []) : [];
    $norm = [];
    try {
        $norm = jeab_webshop_normalize($input);
    } catch (Throwable) {
        $norm = [];
    }
    if ($rid > 0 && $norm !== []) {
        try {
            $contact = jeab_webshop_contact_upsert($norm);
        } catch (Throwable) {
            $contact = null;
        }
        $cid = is_array($contact) ? (string) ($contact['id'] ?? '') : '';
        $out['contact'] = $cid;
        if ($cid !== '') {
            jeab_kontakt_stamp_reminder($store, $rid, $cid);
            jeab_kontakt_stamp_order($rid, $cid);
            $bid = (int) ($purchase['booking_id'] ?? 0);
            if ($bid > 0) {
                jeab_kontakt_stamp_booking($store, $bid, $cid);
            }
        }
    }
    $purchase = $rid > 0 ? (jeab_purchase_get($rid) ?? $purchase) : $purchase;
    $closedOrder = is_array($purchase) && function_exists('jeab_kontakt_order_is_closed') && jeab_kontakt_order_is_closed($purchase);
    $have = trim((string) ($purchase['fortnox_order'] ?? ''));
    if ($have !== '') {
        $out['fortnox'] = 'already';
    } elseif ($closedOrder || $rid <= 0 || !jeab_webshop_is_purchase($purchase)) {
        $out['fortnox'] = 'missing';
    } else {
        try {
            $linked = jeab_webshop_fortnox_link($purchase);
            $out['fortnox'] = is_array($linked) ? 'linked' : 'missing';
        } catch (Throwable) {
            $out['fortnox'] = 'unread';
        }
    }
    $url = $rid > 0 ? jeab_webshop_order_link($rid, $parsed['company']) : '';
    if ($url !== '' && $tid !== '') {
        try {
            jeab_webshop_note_once($tid, "Vunnen.\n" . $url);
            $meta = function_exists('jeab_inkorg_thread_meta') ? jeab_inkorg_thread_meta($tid) : [];
            if ((string) ($meta['status'] ?? '') !== 'closed') {
                jeab_inkorg_thread_set($tid, 'closed', null);
            }
            $out['thread_closed'] = true;
        } catch (JeabInkorgWriteException | InvalidArgumentException) {
            $out['thread_closed'] = false;
        }
    }
    $id = (string) ($msg['id'] ?? '');
    if ($id !== '') {
        $labels = (array) ($msg['labels'] ?? []);
        $labels['type'] = 'Order';
        $labels['action'] = jeab_inkorg_derived_action('Order');
        $labels['priority'] = jeab_inkorg_default_priority('Order');
        $labels['source'] = 'rule';
        $labels['rule_id'] = 'builtin-webshop-order';
        $labels['reason'] = 'Betald webbshoporder';
        $labels['needs_review'] = false;
        jeab_webshop_mark_message($id, $labels);
    }
    $out['leads'] = jeab_webshop_close_leads($store, $leads, $rid, $parsed['company']);
    return $out;
}

/**
 * Ordernyckel i webshop-indexet (samma källa som webshop.status).
 *
 * @return array{key:string,reminder_id:int,fortnox_order:string,done:bool}
 */
function jeab_webshop_index_state(JeabStore $store, string $company, string $number): array
{
    $key = jeab_webshop_key($company, $number);
    $known = jeab_webshop_index()[$key] ?? null;
    $rid = is_array($known) ? (int) ($known['reminder_id'] ?? 0) : 0;
    $purchase = $rid > 0 && function_exists('jeab_purchase_get') ? (jeab_purchase_get($rid) ?? []) : [];
    $live = $rid > 0 && is_array(jeab_get_reminder($store, $rid));
    $fortnox = trim((string) ($purchase['fortnox_order'] ?? ''));
    return [
        'key' => $key,
        'reminder_id' => ($live || $fortnox !== '') ? $rid : 0,
        'fortnox_order' => $fortnox,
        'done' => $live || $fortnox !== '',
    ];
}

/**
 * Betald butiksorder vars nyckel redan har påminnelse eller Fortnox-order.
 * Null betyder att hooken eller automatiken ska köra flödet. Skriver inget.
 *
 * @return array<string, mixed>|null
 */
function jeab_webshop_inbox_already(array $msg): ?array
{
    if (!jeab_webshop_mail_is_paid($msg)) {
        return null;
    }
    $number = jeab_webshop_mail_number(jeab_webshop_mail_text($msg));
    if ($number === '') {
        return null;
    }
    $state = jeab_webshop_index_state(jeab_store(), jeab_webshop_mail_company($msg), $number);
    if (!$state['done']) {
        return null;
    }
    return [
        'number' => $number,
        'found' => true,
        'already_done' => true,
        'thread_id' => (string) ($msg['thread_id'] ?? $msg['id'] ?? ''),
        'type' => (string) ($msg['labels']['type'] ?? ''),
        'contact' => '',
        'reminder_id' => (int) $state['reminder_id'],
        'booking_started' => false,
        'fortnox' => $state['fortnox_order'] !== '' ? 'already' : 'missing',
        'duplicate' => true,
        'thread_closed' => false,
        'leads' => [],
        'key' => $state['key'],
    ];
}

/** Hook inbox.webshop_orders. dry=1 är standard och skriver inget. */
function jeab_webshop_inbox_hook(JeabStore $store, array $in): array
{
    $dry = function_exists('jeab_auto_send_quote_is_dry') ? jeab_auto_send_quote_is_dry($in) : true;
    $order = trim((string) ($in['order'] ?? ''));
    $all = jeab_webshop_flag($in['all'] ?? null);
    if ($order === '' && !$all) {
        return ['ok' => false, 'error' => 'ange order eller all=1'];
    }
    $since = trim((string) ($in['since'] ?? ''));
    if ($since !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) !== 1) {
        return ['ok' => false, 'error' => 'since ska vara YYYY-MM-DD'];
    }
    $rows = [];
    foreach (jeab_inkorg_messages() as $msg) {
        if (!is_array($msg) || (string) ($msg['webshop_api'] ?? '') === 'not_paid') {
            continue;
        }
        if (!jeab_webshop_mail_is_paid($msg)) {
            continue;
        }
        $num = jeab_webshop_mail_number(jeab_webshop_mail_text($msg));
        if ($order !== '' && $num !== $order) {
            continue;
        }
        if ($since !== '') {
            $day = substr((string) ($msg['received_at'] ?? ''), 0, 10);
            if ($day === '' || $day < $since) {
                continue;
            }
        }
        $done = jeab_webshop_inbox_already($msg);
        if (is_array($done)) {
            $rows[] = $done;
            continue;
        }
        $rows[] = jeab_webshop_inbox_apply($store, $msg, $dry);
    }
    if ($order !== '' && $rows === []) {
        $rows[] = [
            'number' => $order, 'found' => false, 'thread_id' => '', 'type' => '', 'contact' => '',
            'reminder_id' => 0, 'booking_started' => false, 'fortnox' => $dry ? 'dry' : 'missing', 'leads' => [],
        ];
    }
    return ['ok' => true, 'dry' => $dry, 'orders' => $rows];
}

/** Lägger Order-meningen i den sparade instruktionen en gång, om den inte redan står där. */
function jeab_webshop_intent_migrate(): void
{
    if (!function_exists('jeab_inkorg_locked')) {
        return;
    }
    $line = "\n- En betald webbshoporder (Wix, betalt) är typen Order, inte Information och inte lead. Obetald eller avbruten är Information.";
    jeab_inkorg_locked('settings.json', static function (?array $d) use ($line) {
        $d = $d ?? [];
        if (!empty($d['webshop_order_intent_v1'])) {
            return [false, null];
        }
        $cur = trim((string) ($d['intent'] ?? ''));
        if ($cur !== '' && !str_contains($cur, 'typen Order')) {
            $d['intent'] = $cur . $line;
        }
        $d['webshop_order_intent_v1'] = date('c');
        return [true, $d];
    });
}
