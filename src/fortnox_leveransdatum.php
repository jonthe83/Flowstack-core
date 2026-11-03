<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/fortnox.php';

/**
 * Fortnox DeliveryDate är installationsdagen i flowstack.example.test-kalendern.
 * Datumet läses från bokningen på servern. Anropet får inte skicka med det.
 *
 * POST /hook.php
 * Authorization: Bearer <inkommande token eller scoped agent-token>   (samma grind som övriga fortnox.*)
 * {"action":"fortnox.leveransdatum_satt","account":"JEAB","invoice_number":"1234"}
 * {"action":"fortnox.leveransdatum_satt","account":"MEAB","order_id":"55","dry":0}
 *
 * dry=1 är standard och listar current mot target. dry=0 skriver.
 * status: satt | oforandrat | bokford_hoppad | ingen_bokning | datum_pa_order | datum_pa_offert | fel
 *
 * Fortnox tar DeliveryDate som YYYY-MM-DD:
 *   PUT /3/invoices/{n}  {"Invoice":{"DeliveryDate":"YYYY-MM-DD"}}
 *   PUT /3/orders/{n}    {"Order":{"DeliveryDate":"YYYY-MM-DD"}}
 *   PUT /3/offers/{n}    {"Offer":{"DeliveryDate":"YYYY-MM-DD"}}
 * Obokförd vanlig faktura (InvoiceType INVOICE) skrivs på fakturan.
 * Kreditfaktura, räntefaktura (INTRESTINVOICE) och samlingsfaktura
 * skrivs på ordern (OrderReference) eller offerten (OfferReference).
 * WarehouseReady är ett riktigt skrivskyddat fält, inte ett skäl att hoppa
 * över i dry. Fakturan försöks med PUT. Fel 2003125 och en redan fakturerad
 * order ger status last_i_fortnox. Datumet sätts då manuellt i Fortnox.
 * Fel 2003126 (utleveransdatum senare än idag) och felaktig payload
 * (2001392, 2001399) skrivs inte om till ett annat fält. Svaret innehåller
 * ErrorInformation message + code, utan token.
 * En order med InvoiceReference (faktura redan skapad) är låst, fel 2001242.
 * Den hoppas över. Fakturan skrivs först, så ordersteget aldrig stoppar fakturan.
 * Varje steg: {target, ok, skipped_reason}.
 */

function jeab_fortnox_leveransdatum_may_call(): bool
{
    if (isset($GLOBALS['_JEAB_FORTNOX_REQUEST']) && is_callable($GLOBALS['_JEAB_FORTNOX_REQUEST'])) {
        return true;
    }
    if (function_exists('jeab_ordrar_external_live') && !jeab_ordrar_external_live()) {
        return false;
    }
    return function_exists('jeab_fortnox_config_path')
        && is_file(jeab_fortnox_config_path())
        && function_exists('jeab_fortnox_tokens_path')
        && is_file(jeab_fortnox_tokens_path());
}

function jeab_fortnox_leveransdatum_account(string $account): string
{
    $key = function_exists('mb_strtolower') ? mb_strtolower(trim($account), 'UTF-8') : strtolower(trim($account));
    $key = strtr($key, ['ä' => 'a', 'ö' => 'o', 'å' => 'a', 'é' => 'e']);
    if (in_array($key, ['malmo', 'meab'], true)) {
        return 'Malmo';
    }
    if (in_array($key, ['jarfalla', 'jeab'], true)) {
        return 'Jarfalla';
    }
    throw new InvalidArgumentException('account ska vara JEAB eller MEAB.');
}

function jeab_fortnox_leveransdatum_wants_dry(array $input): bool
{
    if (!array_key_exists('dry', $input)) {
        return true;
    }
    $dry = $input['dry'];
    return !($dry === false || $dry === 0 || $dry === '0' || $dry === 0.0);
}

function jeab_fortnox_leveransdatum_truthy(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1' || strtolower((string) $value) === 'true';
}

/**
 * Fortnox ErrorInformation utan token. Meddelandet är Fortnox text, koden är deras code.
 *
 * @return array{message:string,code:int}
 */
function jeab_fortnox_leveransdatum_error_info(Throwable $e): array
{
    $code = $e instanceof FortnoxApiException ? $e->fortnoxCode : 0;
    $message = trim($e->getMessage());
    $message = preg_replace('/(bearer|access_token|refresh_token|client_secret)\s*[:=]?\s*\S+/i', '', $message) ?? '';
    $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');
    if ($message === '' || preg_match('/bearer|access_token|refresh_token|client_secret/i', $message)) {
        $message = 'Fortnox-fel.';
    }
    if (strlen($message) > 240) {
        $message = substr($message, 0, 240);
    }
    return ['message' => $message, 'code' => $code];
}

/** @param array{message:string,code:int} $info */
function jeab_fortnox_leveransdatum_type_rejected(array $info): bool
{
    if ($info['code'] === 2003125) {
        return true;
    }
    return (bool) preg_match('/kreditfaktura|rantefaktura|räntefaktura|samlingsfaktura|kan inte ändra|kan inte uppdatera|klarmarker|warehouseready/iu', $info['message']);
}

/**
 * Vart DeliveryDate ska skrivas. invoice = vanlig obokförd faktura.
 * order/offer = Fortnox tar inte ändringen på fakturan.
 * none = ingen order eller offert att skriva på.
 *
 * @param array<string,mixed> $invoice
 * @return array{target:string,reason:string,offer:string}
 */
function jeab_fortnox_leveransdatum_date_destination(array $invoice, string $orderNo): array
{
    $offer = trim((string) ($invoice['OfferReference'] ?? ''));
    if ($offer === '0') {
        $offer = '';
    }
    $orderRef = trim((string) ($invoice['OrderReference'] ?? ''));
    if ($orderRef === '0') {
        $orderRef = '';
    }
    $type = strtoupper(trim((string) ($invoice['InvoiceType'] ?? '')));
    // WarehouseReady är läsbart och betyder att lagret låst dokumentet, men det
    // räcker inte för att hoppa över. Bara Fortnox fel 2003125 visar att PUT nekades.
    $lockedType = jeab_fortnox_leveransdatum_truthy($invoice['Credit'] ?? false)
        || in_array($type, ['CREDITINVOICE', 'INTRESTINVOICE', 'SUMMARYINVOICE'], true);
    if (!$lockedType) {
        return ['target' => 'invoice', 'reason' => '', 'offer' => $offer];
    }
    $reason = 'fakturatyp';
    if ($type === 'CREDITINVOICE' || jeab_fortnox_leveransdatum_truthy($invoice['Credit'] ?? false)) {
        $reason = 'kreditfaktura';
    } elseif ($type === 'INTRESTINVOICE') {
        $reason = 'rantefaktura';
    } elseif ($type === 'SUMMARYINVOICE') {
        $reason = 'samlingsfaktura';
    }
    if ($orderNo !== '' || $orderRef !== '') {
        return ['target' => 'order', 'reason' => $reason, 'offer' => $offer];
    }
    if ($offer !== '') {
        return ['target' => 'offer', 'reason' => $reason, 'offer' => $offer];
    }
    return ['target' => 'none', 'reason' => $reason, 'offer' => ''];
}

function jeab_fortnox_leveransdatum_rule_note(): string
{
    return 'Fortnox tar DeliveryDate som YYYY-MM-DD på PUT /3/invoices/{n}. Kreditfaktura, räntefaktura och samlingsfaktura skrivs på ordern eller offerten. WarehouseReady hoppas inte över i förväg. Fel 2003125 på en redan fakturerad order sätts manuellt i Fortnox.';
}

function jeab_fortnox_leveransdatum_manual_advice(): string
{
    return 'Sätt leveransdatumet manuellt i Fortnox.';
}

function jeab_fortnox_leveransdatum_order_invoiced(array $orderBody): bool
{
    $ref = trim((string) ($orderBody['InvoiceReference'] ?? ''));
    if ($ref !== '' && $ref !== '0') {
        return true;
    }
    return jeab_fortnox_leveransdatum_truthy($orderBody['Invoiced'] ?? false);
}

/** @param array{message:string,code:int} $info */
function jeab_fortnox_leveransdatum_order_lock_error(array $info): bool
{
    if ($info['code'] === 2001242) {
        return true;
    }
    return (bool) preg_match('/faktura genererad|värden som är låsta|varden som ar lasta/iu', $info['message']);
}

/** @return array{target:string,ok:bool,skipped_reason:string} */
function jeab_fortnox_leveransdatum_step(string $target, bool $ok, string $skipped): array
{
    return ['target' => $target, 'ok' => $ok, 'skipped_reason' => $skipped];
}

function jeab_fortnox_leveransdatum_row_company(array $row): string
{
    $company = function_exists('jeab_normalize_company') ? jeab_normalize_company($row['company'] ?? null) : null;
    return $company ?? 'jarfalla';
}

function jeab_fortnox_leveransdatum_log_skip(string $account, string $order, string $invoice, string $reason): void
{
    if (!function_exists('appLog')) {
        return;
    }
    appLog('fortnox_deliverydate_skipped', [
        'account' => $account,
        'order' => $order,
        'invoice' => $invoice,
        'reason' => $reason,
    ]);
}

/** @return array<string,mixed>|null */
function jeab_fortnox_leveransdatum_find(string $account, string $invoice, string $orderId): ?array
{
    $company = $account === 'Malmo' ? 'malmo' : 'jarfalla';
    $matches = static function (array $row) use ($company): bool {
        return jeab_fortnox_leveransdatum_row_company($row) === $company;
    };
    if ($invoice !== '') {
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row) || !$matches($row)) {
                continue;
            }
            $no = trim((string) ($row['fortnox_invoice'] ?? ''));
            if ($no !== '' && $no === $invoice) {
                if ($orderId === '') {
                    return $row;
                }
                $reminder = (string) (int) ($row['reminder_id'] ?? 0);
                $fortnox = trim((string) ($row['fortnox_order'] ?? ''));
                if ($orderId === $reminder || $orderId === $fortnox) {
                    return $row;
                }
                return null;
            }
        }
        return null;
    }
    if ($orderId !== '' && ctype_digit($orderId)) {
        $byId = jeab_purchase_get((int) $orderId);
        if (is_array($byId) && $matches($byId)) {
            return $byId;
        }
    }
    if ($orderId !== '') {
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row) || !$matches($row)) {
                continue;
            }
            if (trim((string) ($row['fortnox_order'] ?? '')) === $orderId) {
                return $row;
            }
        }
    }
    return null;
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function jeab_fortnox_leveransdatum_satt(array $input): array
{
    $account = jeab_fortnox_leveransdatum_account((string) ($input['account'] ?? ''));
    $invoice = trim((string) ($input['invoice_number'] ?? ''));
    $orderId = trim((string) ($input['order_id'] ?? ''));
    if ($invoice === '' && $orderId === '') {
        throw new InvalidArgumentException('invoice_number eller order_id saknas.');
    }
    unset($input['date'], $input['delivery_date'], $input['DeliveryDate']);
    $dry = jeab_fortnox_leveransdatum_wants_dry($input);
    $row = jeab_fortnox_leveransdatum_find($account, $invoice, $orderId);
    if (!is_array($row)) {
        return [
            'ok' => false,
            'status' => 'fel',
            'date' => '',
            'dry' => $dry ? 1 : 0,
            'account' => $account,
            'error' => 'Ordern finns inte.',
        ];
    }
    return jeab_fortnox_leveransdatum_push($row, !$dry);
}

/**
 * Skriver installationsdagen till ordern och, om fakturan inte är bokförd
 * eller makulerad, till fakturan. Datumet kommer från bokningen.
 *
 * @return array<string,mixed>
 */
function jeab_fortnox_leveransdatum_push(array $row, bool $write): array
{
    $ymd = function_exists('jeab_ordrar_install_start')
        ? jeab_fortnox_delivery_ymd(jeab_ordrar_install_start($row))
        : '';
    $account = function_exists('jeab_ordrar_fortnox_account')
        ? jeab_ordrar_fortnox_account($row['company'] ?? null)
        : 'Jarfalla';
    $dry = $write ? 0 : 1;
    $base = ['ok' => true, 'dry' => $dry, 'date' => $ymd, 'account' => $account, 'target' => $ymd];
    if ($ymd === '') {
        return $base + ['status' => 'ingen_bokning', 'current' => '', 'target' => ''];
    }
    $probe = function_exists('jeab_fortnox_row_is_test') ? jeab_fortnox_row_is_test($row) : ['test' => false];
    if (!empty($probe['test'])) {
        jeab_fortnox_log_test_skip($probe);
        $existing = trim((string) ($row['fortnox_order'] ?? ''));

        return $base + [
            'status' => 'test',
            'ok' => true,
            'order' => $existing !== '' ? $existing : 'TEST',
            'wrote' => [],
            'skipped' => 'test lead, Fortnox skipped',
            'current' => '',
        ];
    }
    $orderNo = trim((string) ($row['fortnox_order'] ?? ''));
    $invoiceNo = trim((string) ($row['fortnox_invoice'] ?? ''));
    if ($invoiceNo === '0') {
        $invoiceNo = '';
    }
    if ($orderNo === '' && $invoiceNo === '') {
        return array_merge($base, [
            'ok' => true,
            'status' => 'vantar_order',
            'current' => '',
            'skipped' => 'Ingen Fortnox-order än',
        ]);
    }
    if (!jeab_fortnox_leveransdatum_may_call()) {
        return array_merge($base, ['ok' => false, 'status' => 'fel', 'current' => '', 'error' => 'Fortnox är inte kopplad.']);
    }
    try {
        $api = new FortnoxAPI($account);
        $orderBody = [];
        if ($orderNo !== '') {
            $orderLive = $api->getOrder($orderNo);
            $orderBody = function_exists('jeab_ordrar_fortnox_order_body')
                ? jeab_ordrar_fortnox_order_body($orderLive)
                : (is_array($orderLive['Order'] ?? null) ? $orderLive['Order'] : $orderLive);
            if ($invoiceNo === '') {
                $ref = trim((string) ($orderBody['InvoiceReference'] ?? ''));
                if ($ref !== '' && $ref !== '0') {
                    $invoiceNo = $ref;
                }
            }
        }
        $invoiceBody = [];
        if ($invoiceNo !== '') {
            $invoiceLive = $api->getInvoice($invoiceNo);
            $invoiceBody = is_array($invoiceLive['Invoice'] ?? null) ? $invoiceLive['Invoice'] : $invoiceLive;
        }
    } catch (Throwable $e) {
        $info = jeab_fortnox_leveransdatum_error_info($e);
        jeab_fortnox_leveransdatum_log_skip($account, $orderNo, $invoiceNo, 'fel:' . $info['code']);
        return array_merge($base, [
            'ok' => false,
            'status' => 'fel',
            'current' => '',
            'error' => $info['message'],
            'error_information' => $info,
        ]);
    }
    $orderDate = jeab_fortnox_delivery_ymd((string) ($orderBody['DeliveryDate'] ?? ''));
    $invoiceDate = jeab_fortnox_delivery_ymd((string) ($invoiceBody['DeliveryDate'] ?? ''));
    $current = $invoiceNo !== '' ? $invoiceDate : $orderDate;
    $out = $base + ['current' => $current, 'order' => $orderNo, 'invoice' => $invoiceNo];
    $destination = ['target' => 'invoice', 'reason' => '', 'offer' => ''];
    if ($invoiceNo !== '') {
        $booked = jeab_fortnox_leveransdatum_truthy($invoiceBody['Booked'] ?? false);
        $cancelled = jeab_fortnox_leveransdatum_truthy($invoiceBody['Cancelled'] ?? false);
        if ($booked || $cancelled) {
            $reason = $booked ? 'bokford' : 'makulerad';
            if ($write) {
                jeab_fortnox_leveransdatum_log_skip($account, $orderNo, $invoiceNo, $reason);
            }
            return $out + ['status' => 'bokford_hoppad', 'reason' => $reason];
        }
        $destination = jeab_fortnox_leveransdatum_date_destination($invoiceBody, $orderNo);
        if ($destination['target'] === 'order') {
            $ref = trim((string) ($invoiceBody['OrderReference'] ?? ''));
            if ($orderNo === '' && $ref !== '' && $ref !== '0') {
                $orderNo = $ref;
                $out['order'] = $orderNo;
            }
        }
    }
    if ($destination['target'] === 'order' && $orderNo !== '' && $orderBody === []) {
        try {
            $orderLive = $api->getOrder($orderNo);
            $orderBody = function_exists('jeab_ordrar_fortnox_order_body')
                ? jeab_ordrar_fortnox_order_body($orderLive)
                : (is_array($orderLive['Order'] ?? null) ? $orderLive['Order'] : $orderLive);
            $orderDate = jeab_fortnox_delivery_ymd((string) ($orderBody['DeliveryDate'] ?? ''));
        } catch (Throwable $e) {
            $info = jeab_fortnox_leveransdatum_error_info($e);
            jeab_fortnox_leveransdatum_log_skip($account, $orderNo, $invoiceNo, 'fel:' . $info['code']);
            return array_merge($out, [
                'ok' => false,
                'status' => 'fel',
                'error' => $info['message'],
                'error_information' => $info,
                'invoice_skipped' => $destination['reason'],
            ]);
        }
    }
    $offerNo = $destination['offer'];
    $offerDate = '';
    if ($destination['target'] === 'offer' && $offerNo !== '') {
        try {
            $offerLive = $api->getOffer($offerNo);
        } catch (Throwable $e) {
            $info = jeab_fortnox_leveransdatum_error_info($e);
            jeab_fortnox_leveransdatum_log_skip($account, $orderNo, $invoiceNo, 'fel:' . $info['code']);
            return array_merge($out, [
                'ok' => false,
                'status' => 'fel',
                'error' => $info['message'],
                'error_information' => $info,
                'invoice_skipped' => $destination['reason'],
            ]);
        }
        $offerBody = is_array($offerLive['Offer'] ?? null) ? $offerLive['Offer'] : $offerLive;
        $offerDate = jeab_fortnox_delivery_ymd((string) ($offerBody['DeliveryDate'] ?? ''));
        $out['offer'] = $offerNo;
        $out['current'] = $offerDate;
    }
    $orderInvoiced = jeab_fortnox_leveransdatum_order_invoiced($orderBody);
    $orderNeeds = !$orderInvoiced && $destination['target'] !== 'offer' && $orderNo !== '' && $orderDate !== $ymd;
    $invoiceNeeds = $destination['target'] === 'invoice' && $invoiceNo !== '' && $invoiceDate !== $ymd;
    $offerNeeds = $destination['target'] === 'offer' && $offerNo !== '' && $offerDate !== $ymd;
    $steps = [];
    if ($invoiceNo !== '' && $destination['target'] === 'invoice') {
        $steps[] = jeab_fortnox_leveransdatum_step('invoice', true, $invoiceNeeds ? '' : 'oforandrat');
    } elseif ($invoiceNo !== '' && $destination['reason'] !== '') {
        $steps[] = jeab_fortnox_leveransdatum_step('invoice', true, $destination['reason']);
    }
    if ($destination['target'] === 'offer' && $offerNo !== '') {
        $steps[] = jeab_fortnox_leveransdatum_step('offer', true, $offerNeeds ? '' : 'oforandrat');
    }
    if ($orderNo !== '' && $destination['target'] !== 'offer') {
        if ($orderInvoiced) {
            $steps[] = jeab_fortnox_leveransdatum_step('order', true, 'faktura_genererad');
        } else {
            $steps[] = jeab_fortnox_leveransdatum_step('order', true, $orderNeeds ? '' : 'oforandrat');
        }
    }
    $out['steps'] = $steps;
    if ($destination['target'] === 'none') {
        return array_merge($out, [
            'ok' => false,
            'status' => 'fel',
            'error' => 'Fortnox tar inte DeliveryDate på den här fakturatypen, och fakturan saknar order och offert.',
            'invoice_skipped' => $destination['reason'],
            'note' => jeab_fortnox_leveransdatum_rule_note(),
        ]);
    }
    $dateStillWrong = ($invoiceNo !== '' && $invoiceDate !== $ymd && $destination['target'] !== 'invoice')
        && $orderInvoiced
        && !$offerNeeds;
    if (!$orderNeeds && !$invoiceNeeds && !$offerNeeds) {
        $same = $out + ['status' => $dateStillWrong ? 'fel' : 'oforandrat'];
        if ($destination['reason'] !== '') {
            $same['invoice_skipped'] = $destination['reason'];
            $same['note'] = jeab_fortnox_leveransdatum_rule_note();
        }
        if ($dateStillWrong) {
            $same['ok'] = false;
            $same['error'] = 'Ordern är fakturerad och fakturatypen tar inte DeliveryDate.';
        }
        return $same;
    }
    if (!$write) {
        if ($destination['reason'] !== '') {
            $out['invoice_skipped'] = $destination['reason'];
            $out['via'] = $destination['target'];
            $out['note'] = jeab_fortnox_leveransdatum_rule_note();
        }
        if ($orderInvoiced) {
            $out['order_skipped'] = 'faktura_genererad';
        }
        $calls = [];
        foreach ($steps as $step) {
            if (($step['skipped_reason'] ?? '') !== '') {
                continue;
            }
            $target = (string) $step['target'];
            $number = $target === 'invoice' ? $invoiceNo : ($target === 'offer' ? $offerNo : $orderNo);
            $calls[] = jeab_fortnox_delivery_date_write($target, $number, $ymd);
        }
        $out['calls'] = $calls;
        return $out;
    }
    $wrote = [];
    $done = [];
    $info = null;
    foreach ($steps as $i => $step) {
        if ($step['skipped_reason'] !== '' || isset($done[$step['target']])) {
            continue;
        }
        $target = $step['target'];
        $number = $target === 'invoice' ? $invoiceNo : ($target === 'offer' ? $offerNo : $orderNo);
        $done[$target] = true;
        try {
            $api->putDeliveryDate($target, $number, $ymd);
            $wrote[] = $target;
        } catch (Throwable $e) {
            $err = jeab_fortnox_leveransdatum_error_info($e);
            jeab_fortnox_leveransdatum_log_skip($account, $orderNo, $invoiceNo, 'fel:' . $err['code']);
            if ($target === 'order' && jeab_fortnox_leveransdatum_order_lock_error($err)) {
                $steps[$i] = jeab_fortnox_leveransdatum_step('order', true, 'faktura_genererad');
                continue;
            }
            if ($target === 'invoice' && $err['code'] === 2003125 && ($orderInvoiced || $orderNo === '')) {
                $steps[$i] = jeab_fortnox_leveransdatum_step('invoice', false, '');
                $info = $err;
                continue;
            }
            if ($target === 'invoice' && jeab_fortnox_leveransdatum_type_rejected($err)) {
                $steps[$i] = jeab_fortnox_leveransdatum_step('invoice', true, $destination['reason'] !== '' ? $destination['reason'] : 'fakturatyp');
                $fallback = !$orderInvoiced && $orderNo !== '' ? 'order' : ($orderNo === '' && $offerNo !== '' ? 'offer' : '');
                if ($fallback === 'order') {
                    try {
                        if ($orderDate !== $ymd) {
                            $api->putDeliveryDate('order', $orderNo, $ymd);
                            $wrote[] = 'order';
                        }
                        $done['order'] = true;
                        foreach ($steps as $j => $other) {
                            if ($other['target'] === 'order') {
                                $steps[$j] = jeab_fortnox_leveransdatum_step('order', true, '');
                            }
                        }
                        $destination['target'] = 'order';
                        $destination['reason'] = $destination['reason'] !== '' ? $destination['reason'] : 'fakturatyp';
                        continue;
                    } catch (Throwable $again) {
                        $againInfo = jeab_fortnox_leveransdatum_error_info($again);
                        if (jeab_fortnox_leveransdatum_order_lock_error($againInfo)) {
                            foreach ($steps as $j => $other) {
                                if ($other['target'] === 'order') {
                                    $steps[$j] = jeab_fortnox_leveransdatum_step('order', true, 'faktura_genererad');
                                }
                            }
                            $info = $err;
                            $steps[$i] = jeab_fortnox_leveransdatum_step('invoice', false, '');
                            continue;
                        }
                        $info = $againInfo;
                        foreach ($steps as $j => $other) {
                            if ($other['target'] === 'order') {
                                $steps[$j] = jeab_fortnox_leveransdatum_step('order', false, '');
                            }
                        }
                        continue;
                    }
                }
                if ($fallback === 'offer') {
                    try {
                        $api->putDeliveryDate('offer', $offerNo, $ymd);
                        $wrote[] = 'offer';
                        $done['offer'] = true;
                        $steps[] = jeab_fortnox_leveransdatum_step('offer', true, '');
                        $destination['target'] = 'offer';
                        $destination['reason'] = $destination['reason'] !== '' ? $destination['reason'] : 'fakturatyp';
                        continue;
                    } catch (Throwable $again) {
                        $info = jeab_fortnox_leveransdatum_error_info($again);
                        $steps[] = jeab_fortnox_leveransdatum_step('offer', false, '');
                        continue;
                    }
                }
            }
            $steps[$i] = jeab_fortnox_leveransdatum_step($target, false, '');
            $info = $err;
        }
    }
    $out['steps'] = $steps;
    $failed = false;
    foreach ($steps as $step) {
        if (!$step['ok']) {
            $failed = true;
        }
    }
    if ($failed || is_array($info)) {
        $locked = is_array($info) && $info['code'] === 2003125 && $wrote === [];
        $fail = [
            'ok' => false,
            'status' => $locked ? 'last_i_fortnox' : 'fel',
            'wrote' => $wrote,
        ];
        if ($locked) {
            $fail['advice'] = jeab_fortnox_leveransdatum_manual_advice();
        }
        if (is_array($info)) {
            $fail['error'] = $info['message'];
            $fail['error_information'] = $info;
        }
        return array_merge($out, $fail);
    }
    if ($destination['target'] === 'offer' && in_array('offer', $wrote, true) && !in_array('invoice', $wrote, true)) {
        return $out + [
            'status' => 'datum_pa_offert',
            'invoice_skipped' => $destination['reason'],
            'note' => jeab_fortnox_leveransdatum_rule_note(),
            'wrote' => $wrote,
        ];
    }
    if ($destination['target'] === 'order' && $destination['reason'] !== '' && !in_array('invoice', $wrote, true)) {
        return $out + [
            'status' => 'datum_pa_order',
            'invoice_skipped' => $destination['reason'],
            'note' => jeab_fortnox_leveransdatum_rule_note(),
            'wrote' => $wrote,
        ];
    }
    return $out + ['status' => 'satt', 'wrote' => $wrote];
}

function jeab_fortnox_leveransdatum_on_booking(array $booking): void
{
    if (($booking['status'] ?? '') === 'cancelled') {
        return;
    }
    $bookingId = (int) ($booking['id'] ?? 0);
    if ($bookingId <= 0 || !function_exists('jeab_purchase_by_booking')) {
        return;
    }
    $row = jeab_purchase_by_booking($bookingId);
    if (!is_array($row)) {
        return;
    }
    $deliveryResult = null;
    try {
        $deliveryResult = jeab_fortnox_leveransdatum_push($row, true);
    } catch (Throwable $e) {
        $deliveryResult = $e;
    }
    if (function_exists('jeab_ordrar_remember_leveransdatum')) {
        jeab_ordrar_remember_leveransdatum($row, $deliveryResult);
    }
}

/** @param array<string,mixed> $made */
function jeab_fortnox_leveransdatum_after_invoice(array $row, array $made): array
{
    $no = function_exists('jeab_ordrar_fortnox_invoice_reference')
        ? jeab_ordrar_fortnox_invoice_reference($made)
        : '';
    if ($no !== '' && trim((string) ($row['fortnox_invoice'] ?? '')) === '') {
        $row['fortnox_invoice'] = $no;
    }
    return jeab_fortnox_leveransdatum_push($row, true);
}
