<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Goodwill / Inbyte på en order.
 *
 * Fortnox-metod (kreditfaktura, efter att den vanliga fakturan skapats):
 * 1. Läs debetfakturans Booked. En nyskapad faktura är inte bokförd, och
 *    Fortnox krediterar bara bokförda fakturor. Obokad faktura går därför
 *    direkt till den fristående krediten (steg 3). Ingen faktura bokförs
 *    automatiskt.
 * 2. Bokförd faktura, första utbetalningen: PUT /3/invoices/{debet}/credit.
 *    Det skapar en kopia och sätter CreditInvoiceReference. Fortnox tillåter
 *    en sådan kredit per debetfaktura. Därefter tas kreditfakturans
 *    skattereduktioner bort (DELETE /taxreductions/{id} där ReferenceNumber
 *    är kreditfakturan) innan raderna skrivs om till en negativ rad.
 *    Debetfakturan och dess ROT/grön teknik rörs inte.
 * 3. Obokad faktura, och varje ytterligare utbetalning på en bokförd faktura,
 *    skapas med POST /3/invoices: samma negativa rad,
 *    ExternalInvoiceReference1 = debetfakturan. Inget dokument bokförs.
 * Raden läser debetfakturan (GET /3/invoices/{n}) och kopierar konto, moms
 * och artikel från huvudprodukten (Batteri, Solceller eller Laddbox).
 * Framkörning, ROT och andra tjänsterader används inte. Beloppet är
 * utbetalningen inklusive moms, uttryckt på samma sätt som produktadens pris.
 * Kontonummer skickas inte till Fortnox.
 *
 * Idempotens: kreditnumret sparas med jeab_purchase_patch innan raderna
 * justeras. Det är en databastransaktion med radlås (MySQL: SELECT … FOR
 * UPDATE) eller orderlagrets lås i JSON-läge. Ett nytt försök eller Försök
 * igen ser det sparade numret och anropar inte PUT /credit igen. Om
 * debetfakturan redan har en CreditInvoiceReference som inte hör till en
 * annan utbetalning adopteras den i stället för att skapa en ny.
 */

function jeab_ordrar_payout_types(): array
{
    return [
        'inbyte' => 'Inbyte',
        'goodwill' => 'Goodwill',
    ];
}

function jeab_ordrar_payout_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function jeab_ordrar_payout_safe_error(string $message): string
{
    $message = trim($message);
    if ($message === '') {
        return '';
    }
    $message = jeab_ordrar_payout_mask_note($message);
    if (function_exists('jeab_fortnox_mask_log_text')) {
        $message = jeab_fortnox_mask_log_text($message);
    } elseif (function_exists('jeab_log_mask_text')) {
        $message = jeab_log_mask_text($message);
        $message = preg_replace('/"(AccountNumber|account|konto)"\s*:\s*"?\d{3,12}"?/i', '"$1":"****"', $message) ?? $message;
        $message = preg_replace('/\b(AccountNumber|account|konto)\b\s*[:=]?\s*\d{3,12}\b/i', '$1 ****', $message) ?? $message;
        $message = preg_replace('/\d{7,}/', '****', $message) ?? $message;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($message, 0, 240);
    }
    return substr($message, 0, 240);
}

/** @return array<string,mixed> */
function jeab_ordrar_payout_normalize(array $payout): array
{
    $type = strtolower(trim((string) ($payout['type'] ?? '')));
    if (!isset(jeab_ordrar_payout_types()[$type])) {
        $type = '';
    }
    $status = trim((string) ($payout['status'] ?? 'sparad'));
    if (!in_array($status, ['sparad', 'krediterad', 'kreditering misslyckades'], true)) {
        $status = 'sparad';
    }
    $via = trim((string) ($payout['fortnox_credit_via'] ?? ''));
    if ($via !== 'credit' && $via !== 'post') {
        $via = '';
    }
    return [
        'id' => trim((string) ($payout['id'] ?? '')),
        'type' => $type,
        'amount' => (int) round((float) ($payout['amount'] ?? 0)),
        'clearing' => jeab_ordrar_payout_digits((string) ($payout['clearing'] ?? '')),
        'account' => jeab_ordrar_payout_digits((string) ($payout['account'] ?? '')),
        'status' => $status,
        'fortnox_credit' => trim((string) ($payout['fortnox_credit'] ?? '')),
        'fortnox_credit_via' => $via,
        'error' => jeab_ordrar_payout_safe_error((string) ($payout['error'] ?? '')),
    ];
}

/** @return list<array<string,mixed>> */
function jeab_ordrar_payouts(array $row): array
{
    $raw = $row['payouts'] ?? null;
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $payout) {
        if (!is_array($payout)) {
            continue;
        }
        $norm = jeab_ordrar_payout_normalize($payout);
        if ($norm['id'] === '') {
            continue;
        }
        $out[] = $norm;
    }
    return $out;
}

function jeab_ordrar_payouts_posted(array $input): bool
{
    if (array_key_exists('payouts_form', $input)) {
        return true;
    }
    foreach (['payout_id', 'payout_type', 'payout_amount', 'payout_clearing', 'payout_account', 'payout_konto'] as $key) {
        if (array_key_exists($key, $input)) {
            return true;
        }
    }
    return false;
}

function jeab_ordrar_payout_account_error(string $clearing, string $account): string
{
    if ($clearing === '' && $account === '') {
        return '';
    }
    if ($clearing === '' || $account === '') {
        return 'Fyll i både clearingnummer och kontonummer, eller lämna båda tomma.';
    }
    $clearingLen = strlen($clearing);
    if ($clearingLen < 4 || $clearingLen > 5) {
        return 'Clearingnummer ska vara 4–5 siffror. Swedbank är 5 siffror och börjar med 8.';
    }
    if ($clearingLen === 5 && !str_starts_with($clearing, '8')) {
        return 'Ett clearingnummer med 5 siffror ska börja med 8 (Swedbank).';
    }
    $accountLen = strlen($account);
    if ($accountLen < 7 || $accountLen > 12) {
        return 'Kontonummer ska vara 7–12 siffror.';
    }
    return '';
}

/**
 * Ett fält «Clearing- och kontonummer» till clearing + konto.
 * Första kommatecknet eller mellanslaget skiljer. Swedbank skrivs 8xxx-x.
 * Mellanslag och bindestreck i kontot räknas inte som siffror.
 *
 * @return array{clearing:string,account:string}
 */
function jeab_ordrar_payout_parse_konto(string $raw): array
{
    $raw = str_replace("\xc2\xa0", ' ', trim($raw));
    if ($raw === '') {
        return ['clearing' => '', 'account' => ''];
    }
    $comma = strpos($raw, ',');
    $space = strpos($raw, ' ');
    $split = null;
    if ($comma !== false && ($space === false || $comma < $space)) {
        $split = $comma;
    } elseif ($space !== false) {
        $split = $space;
    }
    if ($split !== null) {
        return [
            'clearing' => jeab_ordrar_payout_digits(substr($raw, 0, $split)),
            'account' => jeab_ordrar_payout_digits(substr($raw, $split + 1)),
        ];
    }
    if (preg_match('/^(8\d{3})-(\d)(?:[^0-9](.*))?$/', $raw, $swed) === 1) {
        return [
            'clearing' => $swed[1] . $swed[2],
            'account' => jeab_ordrar_payout_digits($swed[3] ?? ''),
        ];
    }
    $hyphen = strpos($raw, '-');
    if ($hyphen !== false) {
        return [
            'clearing' => jeab_ordrar_payout_digits(substr($raw, 0, $hyphen)),
            'account' => jeab_ordrar_payout_digits(substr($raw, $hyphen + 1)),
        ];
    }
    $digits = jeab_ordrar_payout_digits($raw);
    $len = strlen($digits);
    $candidates = [];
    if ($len >= 11 && $len <= 16) {
        $account = substr($digits, 4);
        $clearing = substr($digits, 0, 4);
        if (strlen($account) >= 7 && strlen($account) <= 12 && jeab_ordrar_payout_account_error($clearing, $account) === '') {
            $candidates[] = ['clearing' => $clearing, 'account' => $account];
        }
    }
    if (str_starts_with($digits, '8') && $len >= 12 && $len <= 17) {
        $account = substr($digits, 5);
        $clearing = substr($digits, 0, 5);
        if (strlen($account) >= 7 && strlen($account) <= 12 && jeab_ordrar_payout_account_error($clearing, $account) === '') {
            $candidates[] = ['clearing' => $clearing, 'account' => $account];
        }
    }
    if (count($candidates) === 1) {
        return $candidates[0];
    }
    if (count($candidates) > 1) {
        throw new InvalidArgumentException('Skriv clearing och konto med kommatecken eller mellanslag emellan.');
    }
    if ($len <= 5) {
        return ['clearing' => $digits, 'account' => ''];
    }
    return ['clearing' => '', 'account' => $digits];
}

function jeab_ordrar_payout_konto_text(array $payout): string
{
    $clearing = jeab_ordrar_payout_digits((string) ($payout['clearing'] ?? ''));
    $account = jeab_ordrar_payout_digits((string) ($payout['account'] ?? ''));
    if ($clearing === '' && $account === '') {
        return '';
    }
    if (strlen($clearing) === 5 && str_starts_with($clearing, '8')) {
        $clearing = substr($clearing, 0, 4) . '-' . substr($clearing, 4, 1);
    }
    if ($account === '') {
        return $clearing;
    }
    if ($clearing === '') {
        return $account;
    }
    return $clearing . ', ' . $account;
}

function jeab_ordrar_payout_konto_field(string $name, array $payout, bool $reveal): string
{
    $clearing = (string) ($payout['clearing'] ?? '');
    $account = (string) ($payout['account'] ?? '');
    $placeholder = 't.ex. 8327-9, 123 456 789-0';
    $value = '';
    if (!$reveal && ($clearing !== '' || $account !== '')) {
        $placeholder = jeab_ordrar_payout_mask($account !== '' ? $account : $clearing);
    } else {
        $value = jeab_ordrar_payout_konto_text($payout);
    }
    return '<label><span>Clearing- och kontonummer</span><input type="text" inputmode="text" autocomplete="off" spellcheck="false" data-lpignore="true" name="' . e($name) . '" value="' . e($value) . '" placeholder="' . e($placeholder) . '"></label>';
}

function jeab_ordrar_payout_mask(string $account): string
{
    $digits = jeab_ordrar_payout_digits($account);
    if ($digits === '') {
        return '';
    }
    return '****' . substr($digits, -4);
}

function jeab_ordrar_payout_mask_note(string $text): string
{
    $masked = preg_replace_callback(
        '/\b(\d{4,5})[-\s](\d{7,12})\b/',
        static function (array $match): string {
            return '****' . substr($match[2], -4);
        },
        $text
    );
    return $masked ?? $text;
}

function jeab_ordrar_payout_reveal(): bool
{
    if (!empty($GLOBALS['_JEAB_PAYOUT_REVEAL'])) {
        return true;
    }
    return function_exists('jeab_auth_ok') && jeab_auth_ok();
}

function jeab_ordrar_payout_display_account(array $payout, ?bool $reveal = null): string
{
    $clearing = (string) ($payout['clearing'] ?? '');
    $account = (string) ($payout['account'] ?? '');
    if ($clearing === '' && $account === '') {
        return '';
    }
    $show = $reveal ?? jeab_ordrar_payout_reveal();
    if ($show) {
        if ($clearing !== '' && $account !== '') {
            return $clearing . '-' . $account;
        }
        return $clearing !== '' ? $clearing : $account;
    }
    return jeab_ordrar_payout_mask($account !== '' ? $account : $clearing);
}

function jeab_ordrar_payout_line_unit_price(array $line): float
{
    if (isset($line['Price']) && is_numeric($line['Price'])) {
        return (float) $line['Price'];
    }
    if (isset($line['price']) && is_numeric(str_replace(',', '.', (string) $line['price']))) {
        return (float) str_replace(',', '.', (string) $line['price']);
    }
    return 0.0;
}

function jeab_ordrar_payout_line_qty(array $line): float
{
    $qty = 1.0;
    $qtyRaw = $line['DeliveredQuantity'] ?? $line['OrderedQuantity'] ?? $line['qty'] ?? null;
    if ($qtyRaw !== null && $qtyRaw !== '') {
        $parsed = jeab_purchase_amount($qtyRaw);
        if ($parsed != 0.0) {
            $qty = $parsed;
        }
    }
    return $qty;
}

/**
 * Discount: negative unit price and quantity that is not negative.
 * A credit/payout row has positive price and quantity -1. That stays allowed
 * and does not lower the debit cap.
 */
function jeab_ordrar_payout_line_is_discount(array $line): bool
{
    return jeab_ordrar_payout_line_unit_price($line) < 0.0
        && jeab_ordrar_payout_line_qty($line) >= 0.0;
}

/**
 * Signed line amount for the payout cap.
 *
 * Discount rows: negative Price, positive quantity. They lower the invoice net.
 * Credit/payout rows (jeab_ordrar_payout_plain_row): positive Price and
 * DeliveredQuantity -1 on a separate credit invoice. Those must not change
 * the debit cap; payouts are summed on their own.
 */
function jeab_ordrar_payout_line_signed_amount(array $line): int
{
    $price = jeab_ordrar_payout_line_unit_price($line);
    $qty = jeab_ordrar_payout_line_qty($line);
    if ($qty < 0.0) {
        return 0;
    }
    if ($price == 0.0 && isset($line['Total']) && is_numeric($line['Total'])) {
        return (int) round((float) $line['Total']);
    }
    return (int) round($price * $qty);
}

function jeab_ordrar_payout_lines_gross(array $rows): int
{
    $sum = 0;
    foreach ($rows as $line) {
        if (!is_array($line)) {
            continue;
        }
        $sum += jeab_ordrar_payout_line_signed_amount($line);
    }
    return max(0, $sum);
}

/**
 * Signed net of a line list when it contains a discount, otherwise null.
 * Zero and negative nets are returned as-is. Credit rows contribute nothing.
 *
 * @param list<mixed> $rows
 */
function jeab_ordrar_payout_lines_discount_net(array $rows): ?int
{
    $hasDiscount = false;
    $sum = 0;
    foreach ($rows as $line) {
        if (!is_array($line)) {
            continue;
        }
        if (jeab_ordrar_payout_line_is_discount($line)) {
            $hasDiscount = true;
        }
        $sum += jeab_ordrar_payout_line_signed_amount($line);
    }
    return $hasDiscount ? $sum : null;
}

/**
 * Posted invoice lines from the order form. Empty price slots are skipped.
 *
 * @param array<string,mixed> $input
 * @return list<array{Price:float,DeliveredQuantity:mixed}>
 */
function jeab_ordrar_payout_posted_lines(array $input): array
{
    $prices = is_array($input['fx_price'] ?? null) ? $input['fx_price'] : [];
    $qtys = is_array($input['fx_qty'] ?? null) ? $input['fx_qty'] : [];
    $lines = [];
    foreach ($prices as $i => $rawPrice) {
        if (!is_scalar($rawPrice) || trim((string) $rawPrice) === '') {
            continue;
        }
        $price = jeab_purchase_amount($rawPrice);
        if ($price == 0.0) {
            continue;
        }
        $qtyRaw = $qtys[$i] ?? 1;
        if (!is_scalar($qtyRaw) || trim((string) $qtyRaw) === '') {
            $qtyRaw = 1;
        }
        $lines[] = ['Price' => $price, 'DeliveredQuantity' => $qtyRaw];
    }
    return $lines;
}

/**
 * Signed invoice net when a discount row is present. Null when there is none.
 * A posted form that itself contains a discount wins over stored rows and over pris.
 * Stored rows are used when the form has no discount line.
 */
function jeab_ordrar_payout_discount_net(array $row, array $input = []): ?int
{
    $posted = jeab_ordrar_payout_posted_lines($input);
    if ($posted !== []) {
        $postedNet = jeab_ordrar_payout_lines_discount_net($posted);
        if ($postedNet !== null) {
            return $postedNet;
        }
    }
    $stored = is_array($row['fortnox_order_rows'] ?? null) ? $row['fortnox_order_rows'] : [];
    return jeab_ordrar_payout_lines_discount_net($stored);
}

/**
 * @return array{max:int,hard:bool}
 */
function jeab_ordrar_payout_cap_state(array $row, array $input = []): array
{
    $discount = jeab_ordrar_payout_discount_net($row, $input);
    if ($discount !== null) {
        // 0 is a real cap: a fully discounted invoice cannot pay out.
        return ['max' => max(0, $discount), 'hard' => true];
    }
    $candidates = [];
    $stored = is_array($row['fortnox_order_rows'] ?? null) ? $row['fortnox_order_rows'] : [];
    $fromRows = jeab_ordrar_payout_lines_gross($stored);
    if ($fromRows > 0) {
        $candidates[] = $fromRows;
    }
    if (function_exists('jeab_ordrar_fortnox_price')) {
        $price = jeab_ordrar_fortnox_price($row);
        if ($price > 0) {
            $candidates[] = $price;
        }
    }
    $pris = (int) round(jeab_purchase_amount($row['pris'] ?? 0));
    if ($pris > 0) {
        $candidates[] = $pris;
    }
    $posted = 0;
    $prices = is_array($input['fx_price'] ?? null) ? $input['fx_price'] : [];
    $qtys = is_array($input['fx_qty'] ?? null) ? $input['fx_qty'] : [];
    foreach ($prices as $i => $rawPrice) {
        $price = jeab_purchase_amount($rawPrice);
        if ($price == 0.0) {
            continue;
        }
        $qty = jeab_purchase_amount($qtys[$i] ?? 1);
        if ($qty < 0.0) {
            continue;
        }
        if ($qty == 0.0) {
            $qty = 1.0;
        }
        $posted += (int) round($price * $qty);
    }
    if ($posted > 0) {
        $candidates[] = $posted;
    }
    return ['max' => $candidates === [] ? 0 : max($candidates), 'hard' => false];
}

function jeab_ordrar_payout_max(array $row, array $input = []): int
{
    return jeab_ordrar_payout_cap_state($row, $input)['max'];
}

/**
 * @param list<array<string,mixed>> $memory
 * @param list<array<string,mixed>> $fresh
 * @return list<array<string,mixed>>
 */
function jeab_ordrar_payouts_preserve_credits(array $memory, array $fresh): array
{
    $byId = [];
    foreach ($fresh as $payout) {
        $id = (string) ($payout['id'] ?? '');
        if ($id !== '') {
            $byId[$id] = $payout;
        }
    }
    $seen = [];
    $out = [];
    foreach ($memory as $payout) {
        $id = (string) ($payout['id'] ?? '');
        if ($id !== '' && isset($byId[$id])) {
            $disk = $byId[$id];
            $diskNo = trim((string) ($disk['fortnox_credit'] ?? ''));
            if ($diskNo !== '') {
                $payout['fortnox_credit'] = $diskNo;
                $payout['fortnox_credit_via'] = (string) ($disk['fortnox_credit_via'] ?? '');
                $payout['type'] = (string) ($disk['type'] ?? $payout['type']);
                $payout['amount'] = (int) ($disk['amount'] ?? $payout['amount']);
                if ((string) ($disk['status'] ?? '') === 'krediterad') {
                    $payout['status'] = 'krediterad';
                    $payout['error'] = '';
                }
            }
        }
        if ($id !== '') {
            $seen[$id] = true;
        }
        $out[] = jeab_ordrar_payout_normalize($payout);
    }
    foreach ($byId as $id => $disk) {
        if (isset($seen[$id])) {
            continue;
        }
        if (trim((string) ($disk['fortnox_credit'] ?? '')) === '') {
            continue;
        }
        $out[] = $disk;
    }
    return $out;
}

/** @return array<string,mixed> */
function jeab_ordrar_payouts_merge(array $row, array $input): array
{
    $existing = [];
    foreach (jeab_ordrar_payouts($row) as $payout) {
        $existing[(string) $payout['id']] = $payout;
    }
    $ids = is_array($input['payout_id'] ?? null) ? $input['payout_id'] : [];
    $types = is_array($input['payout_type'] ?? null) ? $input['payout_type'] : [];
    $amounts = is_array($input['payout_amount'] ?? null) ? $input['payout_amount'] : [];
    $clearings = is_array($input['payout_clearing'] ?? null) ? $input['payout_clearing'] : [];
    $accounts = is_array($input['payout_account'] ?? null) ? $input['payout_account'] : [];
    $kontos = is_array($input['payout_konto'] ?? null) ? $input['payout_konto'] : [];
    $indexes = array_unique(array_merge(
        array_keys($ids),
        array_keys($types),
        array_keys($amounts),
        array_keys($clearings),
        array_keys($accounts),
        array_keys($kontos)
    ));
    sort($indexes);
    $cap = jeab_ordrar_payout_cap_state($row, $input);
    $max = $cap['max'];
    $hardCap = $cap['hard'];
    $next = [];
    $seen = [];
    $sum = 0;
    foreach ($indexes as $index) {
        $rawId = trim((string) ($ids[$index] ?? ''));
        $previous = ($rawId !== '' && isset($existing[$rawId])) ? $existing[$rawId] : null;
        $type = strtolower(trim((string) ($types[$index] ?? '')));
        $amountText = trim((string) ($amounts[$index] ?? ''));
        if (array_key_exists($index, $kontos)) {
            $parsed = jeab_ordrar_payout_parse_konto((string) $kontos[$index]);
            $clearing = $parsed['clearing'];
            $account = $parsed['account'];
        } else {
            $clearing = jeab_ordrar_payout_digits((string) ($clearings[$index] ?? ''));
            $account = jeab_ordrar_payout_digits((string) ($accounts[$index] ?? ''));
        }
        $accountError = jeab_ordrar_payout_account_error($clearing, $account);
        if ($accountError !== '') {
            throw new InvalidArgumentException($accountError);
        }
        $locked = is_array($previous) && trim((string) ($previous['fortnox_credit'] ?? '')) !== '';
        if ($locked) {
            $item = $previous;
            $item['clearing'] = $clearing;
            $item['account'] = $account;
            $next[] = jeab_ordrar_payout_normalize($item);
            $seen[(string) $previous['id']] = true;
            $sum += (int) $item['amount'];
            continue;
        }
        $blank = $amountText === '' && $type === '' && $clearing === '' && $account === '';
        if ($blank) {
            continue;
        }
        $amount = 0;
        if ($amountText !== '') {
            $amount = (int) round(jeab_purchase_amount($amountText));
            if ($amount <= 0) {
                throw new InvalidArgumentException('Utbetalningens belopp ska vara ett positivt belopp i kronor.');
            }
        }
        if ($amount > 0 && !isset(jeab_ordrar_payout_types()[$type])) {
            throw new InvalidArgumentException('Välj Inbyte eller Goodwill.');
        }
        if ($type !== '' && !isset(jeab_ordrar_payout_types()[$type])) {
            throw new InvalidArgumentException('Välj Inbyte eller Goodwill.');
        }
        if ($amount > 0 && ($hardCap || $max > 0) && $amount > $max) {
            throw new InvalidArgumentException('Utbetalningen kan högst vara fakturans total (' . jeab_ordrar_kr($max) . ' kr).');
        }
        $id = is_array($previous) ? (string) $previous['id'] : bin2hex(random_bytes(4));
        $status = is_array($previous) ? (string) $previous['status'] : 'sparad';
        $next[] = jeab_ordrar_payout_normalize([
            'id' => $id,
            'type' => $type,
            'amount' => $amount,
            'clearing' => $clearing,
            'account' => $account,
            'status' => $status,
            'fortnox_credit' => is_array($previous) ? (string) $previous['fortnox_credit'] : '',
            'fortnox_credit_via' => is_array($previous) ? (string) $previous['fortnox_credit_via'] : '',
            'error' => is_array($previous) ? (string) $previous['error'] : '',
        ]);
        $seen[$id] = true;
        $sum += $amount;
    }
    foreach ($existing as $id => $previous) {
        if (isset($seen[$id])) {
            continue;
        }
        if (trim((string) ($previous['fortnox_credit'] ?? '')) === '') {
            continue;
        }
        $next[] = $previous;
        $sum += (int) $previous['amount'];
    }
    if (($hardCap || $max > 0) && $sum > $max) {
        throw new InvalidArgumentException('Utbetalningarna kan tillsammans högst vara fakturans total (' . jeab_ordrar_kr($max) . ' kr).');
    }
    $row['payouts'] = $next;
    foreach ($next as $payout) {
        if ((string) ($payout['status'] ?? '') === 'sparad' && trim((string) ($payout['fortnox_credit'] ?? '')) === '') {
            continue;
        }
        try {
            jeab_ordrar_payout_reminder_sync($row, $payout);
        } catch (Throwable) {
            // Påminnelsen får inte hindra att ordern sparas. Kreditnumret ligger kvar.
        }
    }
    return $row;
}

function jeab_ordrar_payout_order_label(array $row): string
{
    $orderNo = trim((string) ($row['fortnox_order'] ?? ''));
    if ($orderNo !== '') {
        return $orderNo;
    }
    $id = (int) ($row['reminder_id'] ?? 0);
    return $id > 0 ? (string) $id : '';
}

function jeab_ordrar_payout_label(array $payout): string
{
    $type = (string) ($payout['type'] ?? '');
    return jeab_ordrar_payout_types()[$type] ?? 'Utbetalning';
}

function jeab_ordrar_payout_row_text(array $line): string
{
    $text = trim((string) ($line['Description'] ?? '')) . ' ' . trim((string) ($line['ArticleNumber'] ?? ''));
    $fold = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    return strtr($fold, ['ö' => 'o', 'ä' => 'a', 'å' => 'a', 'é' => 'e']);
}

function jeab_ordrar_payout_row_is_service(array $line): bool
{
    $hw = strtoupper(trim((string) ($line['HouseWorkType'] ?? '')));
    if (in_array($hw, ['OTHERCOSTS', 'ROT', 'RUT', 'CONSTRUCTION'], true)) {
        return true;
    }
    return (bool) preg_match('/framkorning|schablon|\brot\b|\brut\b|montage|service|administration|ovrigt|ovriga/', jeab_ordrar_payout_row_text($line));
}

function jeab_ordrar_payout_row_is_product(array $line): bool
{
    if (jeab_ordrar_payout_row_is_service($line)) {
        return false;
    }
    $hw = strtoupper(trim((string) ($line['HouseWorkType'] ?? '')));
    if (in_array($hw, ['STORAGESELFPRODUCEDELECTRICITY', 'SOLARCELLS', 'CHARGINGSTATIONELECTRICVEHICLE'], true)) {
        return true;
    }
    return (bool) preg_match('/batteri|solcell|solpanel|laddbox|elbilsladd/', jeab_ordrar_payout_row_text($line));
}

function jeab_ordrar_payout_row_amount(array $line): float
{
    if (isset($line['Total']) && is_numeric($line['Total'])) {
        return abs((float) $line['Total']);
    }
    $price = is_numeric($line['Price'] ?? null) ? (float) $line['Price'] : 0.0;
    $qty = 1.0;
    $raw = $line['DeliveredQuantity'] ?? $line['OrderedQuantity'] ?? null;
    if ($raw !== null && $raw !== '' && is_numeric($raw) && (float) $raw != 0.0) {
        $qty = abs((float) $raw);
    }
    return abs($price * $qty);
}

/**
 * Huvudprodukten på debetfakturan: Batteri, Solceller eller Laddbox.
 * Framkörning, ROT och andra tjänsterader hoppas över. Flera produktrader
 * avgörs av störst belopp.
 *
 * @return array<string,mixed>
 */
function jeab_ordrar_payout_product_row(array $invoice): array
{
    $rows = is_array($invoice['InvoiceRows'] ?? null) ? $invoice['InvoiceRows'] : [];
    $products = [];
    $rest = [];
    foreach ($rows as $line) {
        if (!is_array($line)) {
            continue;
        }
        if (jeab_ordrar_payout_row_is_service($line)) {
            continue;
        }
        if (jeab_ordrar_payout_row_is_product($line)) {
            $products[] = $line;
            continue;
        }
        $rest[] = $line;
    }
    $pool = $products !== [] ? $products : $rest;
    if ($pool === []) {
        throw new RuntimeException('Fakturan saknar en produktrad.');
    }
    $best = $pool[0];
    $bestAmount = jeab_ordrar_payout_row_amount($best);
    foreach ($pool as $line) {
        $amount = jeab_ordrar_payout_row_amount($line);
        if ($amount > $bestAmount) {
            $best = $line;
            $bestAmount = $amount;
        }
    }
    return $best;
}

function jeab_ordrar_payout_price_includes_vat(array $invoice, array $product): bool
{
    $flag = $invoice['VATIncluded'] ?? null;
    if (is_bool($flag)) {
        return $flag;
    }
    if (is_int($flag) || is_float($flag)) {
        return (int) $flag === 1;
    }
    if (is_string($flag) && trim($flag) !== '') {
        $text = strtolower(trim($flag));
        if ($text === '1' || $text === 'true') {
            return true;
        }
        if ($text === '0' || $text === 'false') {
            return false;
        }
    }
    $price = is_numeric($product['Price'] ?? null) ? (float) $product['Price'] : 0.0;
    $ex = $product['PriceExcludingVAT'] ?? null;
    return is_numeric($ex) && $price > (float) $ex + 0.009;
}

/** Utbetalningen är inklusive moms. Priset skrivs på samma sätt som produktaden. */
function jeab_ordrar_payout_line_price(int $amount, float $vatPercent, bool $priceIncludesVat): int|float
{
    if ($priceIncludesVat || $vatPercent <= 0) {
        return $amount;
    }
    $net = round($amount / (1 + ($vatPercent / 100)), 2);
    if (abs($net - round($net)) < 0.001) {
        return (int) round($net);
    }
    return $net;
}

/**
 * Negativ rad med samma försäljningskonto, moms och artikel som huvudprodukten.
 * Ingen husarbetstyp, så raden inte blir ROT/grön teknik.
 *
 * @param array<string,mixed> $product
 * @param array<string,mixed> $invoice
 * @return array<string,mixed>
 */
function jeab_ordrar_payout_plain_row(array $row, array $payout, array $product, array $invoice): array
{
    $vatRaw = $product['VAT'] ?? $product['VATPercent'] ?? null;
    if (!is_numeric($vatRaw)) {
        throw new RuntimeException('Produktraden saknar moms.');
    }
    $article = trim((string) ($product['ArticleNumber'] ?? ''));
    $account = trim((string) ($product['AccountNumber'] ?? ''));
    if ($article === '' && $account === '') {
        throw new RuntimeException('Produktraden saknar konto och artikel.');
    }
    $vat = (float) $vatRaw;
    $label = jeab_ordrar_payout_order_label($row);
    $line = [
        'Description' => trim(jeab_ordrar_payout_label($payout) . ' order ' . $label),
        'DeliveredQuantity' => '-1',
        'Price' => jeab_ordrar_payout_line_price((int) ($payout['amount'] ?? 0), $vat, jeab_ordrar_payout_price_includes_vat($invoice, $product)),
        'VAT' => abs($vat - round($vat)) < 0.001 ? (int) round($vat) : $vat,
        'HouseWork' => false,
    ];
    if ($article !== '') {
        $line['ArticleNumber'] = $article;
    }
    if ($account !== '') {
        $line['AccountNumber'] = ctype_digit($account) ? (int) $account : $account;
    }
    return $line;
}

function jeab_ordrar_payout_api(array $row): FortnoxAPI
{
    require_once __DIR__ . '/fortnox.php';
    if (isset($GLOBALS['_JEAB_FORTNOX_REQUEST']) && is_callable($GLOBALS['_JEAB_FORTNOX_REQUEST'])) {
        return new FortnoxAPI(jeab_ordrar_fortnox_account($row['company'] ?? null));
    }
    return jeab_ordrar_fortnox_api_for($row);
}

function jeab_ordrar_payout_invoice_body(array $response): array
{
    $invoice = $response['Invoice'] ?? null;
    return is_array($invoice) ? $invoice : $response;
}

function jeab_ordrar_payout_credit_reference(array $response, string $debit): string
{
    $invoice = jeab_ordrar_payout_invoice_body($response);
    $ref = trim((string) ($invoice['CreditInvoiceReference'] ?? ''));
    if ($ref === '' || $ref === '0' || $ref === $debit) {
        return '';
    }
    return $ref;
}

function jeab_ordrar_payout_invoice_booked(array $invoice): bool
{
    $flag = $invoice['Booked'] ?? $invoice['booked'] ?? false;
    if (is_bool($flag)) {
        return $flag;
    }
    if (is_int($flag) || is_float($flag)) {
        return (int) $flag === 1;
    }
    $text = strtolower(trim((string) $flag));
    return $text === '1' || $text === 'true';
}

function jeab_ordrar_payout_credit_number(array $response, string $debit): string
{
    $invoice = jeab_ordrar_payout_invoice_body($response);
    $doc = trim((string) ($invoice['DocumentNumber'] ?? ''));
    $flag = $invoice['Credit'] ?? false;
    $isCredit = $flag === true || $flag === 1 || (is_string($flag) && strtolower($flag) === 'true');
    if ($isCredit && $doc !== '' && $doc !== $debit) {
        return $doc;
    }
    $ref = jeab_ordrar_payout_credit_reference($response, $debit);
    if ($ref !== '') {
        return $ref;
    }
    if ($doc !== '' && $doc !== $debit) {
        return $doc;
    }
    return '';
}

/** @param list<array<string,mixed>> $payouts */
function jeab_ordrar_payout_credit_taken(array $payouts, string $number, string $exceptId = ''): bool
{
    $number = trim($number);
    if ($number === '') {
        return false;
    }
    foreach ($payouts as $payout) {
        if ($exceptId !== '' && (string) ($payout['id'] ?? '') === $exceptId) {
            continue;
        }
        if (trim((string) ($payout['fortnox_credit'] ?? '')) === $number) {
            return true;
        }
    }
    return false;
}

/** @param list<array<string,mixed>> $payouts */
function jeab_ordrar_payout_credit_slot_used(array $payouts, string $exceptId, string $debitRef): bool
{
    if ($debitRef !== '') {
        return true;
    }
    foreach ($payouts as $payout) {
        if ($exceptId !== '' && (string) ($payout['id'] ?? '') === $exceptId) {
            continue;
        }
        if ((string) ($payout['fortnox_credit_via'] ?? '') === 'credit' && trim((string) ($payout['fortnox_credit'] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

/**
 * @param list<array<string,mixed>> $siblings
 * @return array{number:string,via:string}
 */
function jeab_ordrar_payout_open_credit(FortnoxAPI $api, array $row, array $payout, array $siblings): array
{
    $debit = jeab_ordrar_invoice_number($row);
    if ($debit === '') {
        throw new RuntimeException('Fakturan saknas.');
    }
    $have = trim((string) ($payout['fortnox_credit'] ?? ''));
    if ($have !== '') {
        $via = (string) ($payout['fortnox_credit_via'] ?? '');
        return ['number' => $have, 'via' => $via !== '' ? $via : 'credit'];
    }
    $debitResponse = $api->getInvoice($debit);
    $debitInvoice = jeab_ordrar_payout_invoice_body($debitResponse);
    $existingRef = jeab_ordrar_payout_credit_reference($debitResponse, $debit);
    $id = (string) ($payout['id'] ?? '');
    if ($existingRef !== '' && !jeab_ordrar_payout_credit_taken($siblings, $existingRef, $id)) {
        return ['number' => $existingRef, 'via' => 'credit'];
    }
    // Obokad faktura kan inte krediteras med PUT /credit. Ingen bokföring görs.
    if (!jeab_ordrar_payout_invoice_booked($debitInvoice)) {
        return jeab_ordrar_payout_post_credit($api, $row, $payout, $debit, $debitInvoice);
    }
    if (jeab_ordrar_payout_credit_slot_used($siblings, $id, $existingRef)) {
        return jeab_ordrar_payout_post_credit($api, $row, $payout, $debit, $debitInvoice);
    }
    try {
        $created = $api->creditInvoice($debit);
        $number = jeab_ordrar_payout_credit_number($created, $debit);
        if ($number === '') {
            $again = $api->getInvoice($debit);
            $number = jeab_ordrar_payout_credit_reference($again, $debit);
        }
        if ($number === '' || $number === $debit) {
            throw new RuntimeException('Fortnox skapade ingen kreditfaktura.');
        }
        return ['number' => $number, 'via' => 'credit'];
    } catch (Throwable $e) {
        try {
            $after = $api->getInvoice($debit);
        } catch (Throwable) {
            throw $e;
        }
        $ref = jeab_ordrar_payout_credit_reference($after, $debit);
        if ($ref !== '' && !jeab_ordrar_payout_credit_taken($siblings, $ref, $id)) {
            return ['number' => $ref, 'via' => 'credit'];
        }
        throw $e;
    }
}

/**
 * @param array<string,mixed> $debitInvoice
 * @return array{number:string,via:string}
 */
function jeab_ordrar_payout_post_credit(FortnoxAPI $api, array $row, array $payout, string $debit, array $debitInvoice): array
{
    $customer = trim((string) ($debitInvoice['CustomerNumber'] ?? ''));
    if ($customer === '') {
        throw new RuntimeException('Fortnox-kunden saknas på fakturan.');
    }
    $created = $api->createInvoice(jeab_ordrar_payout_post_body($row, $payout, $debit, $customer, $debitInvoice));
    $number = jeab_ordrar_payout_credit_number($created, $debit);
    if ($number === '' || $number === $debit) {
        throw new RuntimeException('Fortnox skapade ingen kreditfaktura.');
    }
    return ['number' => $number, 'via' => 'post'];
}

/**
 * @param array<string,mixed> $debitInvoice
 * @return array<string,mixed>
 */
function jeab_ordrar_payout_post_body(array $row, array $payout, string $debit, string $customer, array $debitInvoice): array
{
    $product = jeab_ordrar_payout_product_row($debitInvoice);
    $label = jeab_ordrar_payout_order_label($row);
    return [
        'CustomerNumber' => $customer,
        'InvoiceRows' => [jeab_ordrar_payout_plain_row($row, $payout, $product, $debitInvoice)],
        'HouseWork' => false,
        'Freight' => 0,
        'AdministrationFee' => 0,
        'VATIncluded' => jeab_ordrar_payout_price_includes_vat($debitInvoice, $product),
        'Comments' => trim(jeab_ordrar_payout_label($payout) . ' order ' . $label),
        'YourOrderNumber' => $label,
        'ExternalInvoiceReference1' => $debit,
    ];
}

function jeab_ordrar_payout_adjust_credit(FortnoxAPI $api, array $row, array $payout, string $creditNo, string $debit): void
{
    if ($creditNo === '' || $creditNo === $debit) {
        throw new RuntimeException('Kreditfakturan saknar ett eget fakturanummer.');
    }
    $debitInvoice = jeab_ordrar_payout_invoice_body($api->getInvoice($debit));
    $product = jeab_ordrar_payout_product_row($debitInvoice);
    $label = jeab_ordrar_payout_order_label($row);
    // Skattereduktionen måste bort innan ROT-raderna tas bort, annars avvisar Fortnox omskrivningen.
    $api->deleteInvoiceTaxReductions($creditNo);
    $api->updateInvoice($creditNo, [
        'InvoiceRows' => [jeab_ordrar_payout_plain_row($row, $payout, $product, $debitInvoice)],
        'HouseWork' => false,
        'Freight' => 0,
        'AdministrationFee' => 0,
        'VATIncluded' => jeab_ordrar_payout_price_includes_vat($debitInvoice, $product),
        'Comments' => trim(jeab_ordrar_payout_label($payout) . ' order ' . $label),
    ]);
}

/** @param array<string,mixed> $payout */
function jeab_ordrar_payout_note_failure(array $payout, Throwable $e): array
{
    $payout['status'] = 'kreditering misslyckades';
    $payout['error'] = jeab_ordrar_payout_safe_error($e->getMessage());
    $message = 'Kreditering misslyckades';
    if ($payout['error'] !== '') {
        $message .= ': ' . $payout['error'];
    }
    if (function_exists('jeab_log_mask_text')) {
        $message = jeab_log_mask_text($message);
    }
    $message = preg_replace('/\d{7,}/', '****', $message) ?? $message;
    if (function_exists('jeab_log_app_error')) {
        jeab_log_app_error('ordrar', $message);
    }
    return $payout;
}

function jeab_ordrar_payout_replace(array $row, array $payout): array
{
    $payouts = jeab_ordrar_payouts($row);
    $found = false;
    foreach ($payouts as $i => $current) {
        if ((string) ($current['id'] ?? '') === (string) ($payout['id'] ?? '')) {
            $payouts[$i] = jeab_ordrar_payout_normalize($payout);
            $found = true;
            break;
        }
    }
    if (!$found) {
        $payouts[] = jeab_ordrar_payout_normalize($payout);
    }
    $row['payouts'] = $payouts;
    return $row;
}

/** @return list<array<string,mixed>> */
function jeab_ordrar_payouts_pending(array $row, string $onlyId): array
{
    $pending = [];
    foreach (jeab_ordrar_payouts($row) as $payout) {
        if ($onlyId !== '' && (string) ($payout['id'] ?? '') !== $onlyId) {
            continue;
        }
        if ((int) ($payout['amount'] ?? 0) <= 0) {
            continue;
        }
        if ((string) ($payout['status'] ?? '') === 'krediterad' && trim((string) ($payout['fortnox_credit'] ?? '')) !== '') {
            continue;
        }
        $pending[] = $payout;
    }
    return $pending;
}

/**
 * Under orderradens lås: slå ihop med det som redan är sparat och hämta
 * kreditnummer från Fortnox. Numret skrivs när patchen committar, innan
 * raderna på kreditfakturan justeras.
 *
 * @return array<string,mixed>
 */
function jeab_ordrar_payouts_store_credit_numbers(array $row, array $fresh, string $onlyId): array
{
    $memory = jeab_ordrar_payouts($row);
    $disk = jeab_ordrar_payouts($fresh);
    if ($memory !== [] || $disk !== []) {
        $row['payouts'] = jeab_ordrar_payouts_preserve_credits($memory, $disk);
    }
    $pending = jeab_ordrar_payouts_pending($row, $onlyId);
    if ($pending === []) {
        $row['updated_at'] = jeab_now();
        return $row;
    }
    if (jeab_ordrar_invoice_number($row) === '') {
        throw new RuntimeException('Fakturan saknas.');
    }
    $api = jeab_ordrar_payout_api($row);
    foreach ($pending as $payout) {
        if (trim((string) ($payout['fortnox_credit'] ?? '')) !== '') {
            continue;
        }
        try {
            $opened = jeab_ordrar_payout_open_credit($api, $row, $payout, jeab_ordrar_payouts($row));
            $payout['fortnox_credit'] = $opened['number'];
            $payout['fortnox_credit_via'] = $opened['via'];
        } catch (Throwable $e) {
            $payout = jeab_ordrar_payout_note_failure($payout, $e);
        }
        $row = jeab_ordrar_payout_replace($row, $payout);
    }
    $row['updated_at'] = jeab_now();
    return $row;
}

/**
 * Kreditnumret ligger redan på ordern. Justera raderna och markera status.
 * Ett misslyckande här behåller numret så nästa försök inte skapar en ny kredit.
 *
 * @return array<string,mixed>
 */
function jeab_ordrar_payouts_finish_credits(array $row, string $onlyId): array
{
    $pending = jeab_ordrar_payouts_pending($row, $onlyId);
    if ($pending === []) {
        return $row;
    }
    $api = null;
    $touched = false;
    foreach ($pending as $payout) {
        $number = trim((string) ($payout['fortnox_credit'] ?? ''));
        if ($number === '') {
            $touched = true;
            continue;
        }
        try {
            if (!$api instanceof FortnoxAPI) {
                $api = jeab_ordrar_payout_api($row);
            }
            jeab_ordrar_payout_adjust_credit($api, $row, $payout, $number, jeab_ordrar_invoice_number($row));
            $payout['status'] = 'krediterad';
            $payout['error'] = '';
        } catch (Throwable $e) {
            $payout = jeab_ordrar_payout_note_failure($payout, $e);
        }
        $row = jeab_ordrar_payout_replace($row, $payout);
        $touched = true;
    }
    if (!$touched) {
        return $row;
    }
    $id = (int) ($row['reminder_id'] ?? 0);
    $row['updated_at'] = jeab_now();
    if ($id > 0 && function_exists('jeab_purchase_patch')) {
        $saved = jeab_purchase_patch($id, static function (array $fresh) use ($row): array {
            $memory = jeab_ordrar_payouts($row);
            $disk = jeab_ordrar_payouts($fresh);
            if ($memory !== [] || $disk !== []) {
                $row['payouts'] = jeab_ordrar_payouts_preserve_credits($memory, $disk);
            }
            $row['updated_at'] = jeab_now();
            return $row;
        });
        if (is_array($saved)) {
            $row = $saved;
        }
    } else {
        $row = jeab_purchase_put($row);
    }
    foreach (jeab_ordrar_payouts($row) as $payout) {
        if ($onlyId !== '' && (string) ($payout['id'] ?? '') !== $onlyId) {
            continue;
        }
        if ((int) ($payout['amount'] ?? 0) <= 0) {
            continue;
        }
        $status = (string) ($payout['status'] ?? '');
        if ($status !== 'krediterad' && $status !== 'kreditering misslyckades') {
            continue;
        }
        try {
            jeab_ordrar_payout_reminder_sync($row, $payout);
        } catch (Throwable) {
            // Kreditnumret är redan sparat.
        }
    }
    return $row;
}

/** @return array<string,mixed> */
function jeab_ordrar_payouts_credit_locked(array $row, string $onlyId = ''): array
{
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_purchase_patch')) {
        return jeab_purchase_put($row);
    }
    $stored = jeab_purchase_patch($id, static function (array $fresh) use ($row, $onlyId): array {
        return jeab_ordrar_payouts_store_credit_numbers($row, $fresh, $onlyId);
    });
    if (!is_array($stored)) {
        $stored = jeab_purchase_put($row);
    }
    return jeab_ordrar_payouts_finish_credits($stored, $onlyId);
}

function jeab_ordrar_payout_any_failed(array $row): bool
{
    foreach (jeab_ordrar_payouts($row) as $payout) {
        if ((string) ($payout['status'] ?? '') === 'kreditering misslyckades') {
            return true;
        }
    }
    return false;
}

function jeab_ordrar_payout_failed_errors(array $row): string
{
    $parts = [];
    foreach (jeab_ordrar_payouts($row) as $payout) {
        if ((string) ($payout['status'] ?? '') !== 'kreditering misslyckades') {
            continue;
        }
        $error = trim((string) ($payout['error'] ?? ''));
        if ($error !== '') {
            $parts[] = $error;
        }
    }
    return implode(' ', $parts);
}

function jeab_ordrar_payout_reminder_line(array $row, array $payout): string
{
    $date = substr(jeab_today_start(), 0, 10);
    $name = jeab_ordrar_name($row);
    if ($name === '') {
        $name = 'Kund';
    }
    $account = jeab_ordrar_payout_display_account($payout, true);
    if ($account === '') {
        $account = 'saknas';
    }
    $invoice = jeab_ordrar_invoice_number($row);
    if ($invoice === '') {
        $invoice = 'saknas';
    }
    $parts = [
        $date,
        $name,
        jeab_ordrar_payout_label($payout),
        jeab_ordrar_kr((int) ($payout['amount'] ?? 0)) . ' kr',
        'order ' . jeab_ordrar_payout_order_label($row),
        'faktura ' . $invoice,
        'konto ' . $account,
    ];
    if ((string) ($payout['status'] ?? '') === 'kreditering misslyckades') {
        $parts[] = 'kreditering misslyckades';
    }
    $credit = trim((string) ($payout['fortnox_credit'] ?? ''));
    if ($credit !== '') {
        $parts[] = 'kreditfaktura ' . $credit;
    }
    $parts[] = '[payout:' . (string) ($payout['id'] ?? '') . ']';
    return implode(' · ', $parts);
}

function jeab_ordrar_payout_upsert_line(string $notes, string $line, string $token): string
{
    $rows = preg_split("/\r\n|\n|\r/", $notes) ?: [];
    $found = false;
    foreach ($rows as $i => $existing) {
        if ($token !== '' && str_contains((string) $existing, $token)) {
            $rows[$i] = $line;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $rows[] = $line;
    }
    $kept = [];
    foreach ($rows as $existing) {
        if (trim((string) $existing) !== '') {
            $kept[] = trim((string) $existing);
        }
    }
    return implode("\n", $kept);
}

function jeab_ordrar_payout_find_reminder(JeabStore $store): ?array
{
    $fallback = null;
    foreach ($store->listReminders(false) as $reminder) {
        if (!is_array($reminder)) {
            continue;
        }
        if (trim((string) ($reminder['title'] ?? '')) !== 'Utbetalningar') {
            continue;
        }
        if (trim((string) ($reminder['completed_at'] ?? '')) !== '') {
            continue;
        }
        $names = jeab_assignees_from_row($reminder);
        if (in_array('Sam L', $names, true)) {
            return $reminder;
        }
        if ($names === [] && $fallback === null) {
            $fallback = $reminder;
        }
    }
    return $fallback;
}

function jeab_ordrar_payout_reminder_sync(array $row, array $payout): void
{
    $id = trim((string) ($payout['id'] ?? ''));
    if ($id === '' || (int) ($payout['amount'] ?? 0) <= 0) {
        return;
    }
    $store = jeab_store();
    $line = jeab_ordrar_payout_reminder_line($row, $payout);
    $token = '[payout:' . $id . ']';
    $existing = jeab_ordrar_payout_find_reminder($store);
    if (is_array($existing)) {
        $notes = (string) ($existing['note'] ?? '');
        $merged = jeab_ordrar_payout_upsert_line($notes, $line, $token);
        if ($merged !== trim($notes)) {
            jeab_update_reminder($store, (int) $existing['id'], ['note' => $merged]);
        }
        return;
    }
    jeab_create_reminder($store, 'Utbetalningar', jeab_today_start(), $line, null, [
        'who' => 'Sam L',
        'company' => 'jarfalla',
    ]);
}

/** @return array<string,mixed> */
function jeab_ordrar_payout_retry(array $input): array
{
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    $payoutId = trim((string) ($input['payout_id'] ?? ''));
    if ($payoutId === '') {
        throw new InvalidArgumentException('Utbetalningen saknas.');
    }
    $found = false;
    $payouts = jeab_ordrar_payouts($row);
    foreach ($payouts as $i => $payout) {
        if ((string) ($payout['id'] ?? '') !== $payoutId) {
            continue;
        }
        $found = true;
        if (array_key_exists('payout_konto', $input) || array_key_exists('payout_clearing', $input) || array_key_exists('payout_account', $input)) {
            if (array_key_exists('payout_konto', $input)) {
                $parsed = jeab_ordrar_payout_parse_konto((string) $input['payout_konto']);
                $clearing = $parsed['clearing'];
                $account = $parsed['account'];
            } else {
                $clearing = jeab_ordrar_payout_digits((string) ($input['payout_clearing'] ?? $payout['clearing']));
                $account = jeab_ordrar_payout_digits((string) ($input['payout_account'] ?? $payout['account']));
            }
            $accountError = jeab_ordrar_payout_account_error($clearing, $account);
            if ($accountError !== '') {
                throw new InvalidArgumentException($accountError);
            }
            $payouts[$i]['clearing'] = $clearing;
            $payouts[$i]['account'] = $account;
        }
        break;
    }
    if (!$found) {
        throw new InvalidArgumentException('Utbetalningen saknas.');
    }
    $row['payouts'] = $payouts;
    if (jeab_ordrar_invoice_number($row) === '') {
        throw new InvalidArgumentException('Fakturan saknas. Utbetalningen kan krediteras först när fakturan finns.');
    }
    return jeab_ordrar_payouts_credit_locked($row, $payoutId);
}

function jeab_ordrar_payout_retry_flash(array $row, string $payoutId): string
{
    foreach (jeab_ordrar_payouts($row) as $payout) {
        if ((string) ($payout['id'] ?? '') !== $payoutId) {
            continue;
        }
        if ((string) ($payout['status'] ?? '') === 'krediterad') {
            return 'Utbetalningen krediterad.';
        }
        $error = trim((string) ($payout['error'] ?? ''));
        return 'Kreditering misslyckades.' . ($error !== '' ? ' ' . $error : '');
    }
    return 'Utbetalningen saknas.';
}

function jeab_ordrar_payout_script(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<script>(function(){if(window.jeabPayBound){return;}window.jeabPayBound=true;function sync(root){var help=root.querySelector("[data-jeab-pay-help]");var list=root.querySelector("[data-jeab-pay-list]");if(!help||!list){return;}help.hidden=list.querySelector("[data-jeab-pay]")===null;}document.addEventListener("click",function(ev){var t=ev.target;if(!t||!t.closest){return;}var add=t.closest("[data-jeab-pay-add]");if(add){ev.preventDefault();var root=add.closest("[data-jeab-pays]");if(!root){return;}var tpl=root.querySelector("template");var list=root.querySelector("[data-jeab-pay-list]");if(!tpl||!list){return;}var n=list.querySelectorAll("[data-jeab-pay]").length;list.insertAdjacentHTML("beforeend",tpl.innerHTML.split("__N__").join(String(n)));sync(root);return;}var rm=t.closest("[data-jeab-pay-remove]");if(!rm){return;}ev.preventDefault();var row=rm.closest("[data-jeab-pay]");if(!row||row.hasAttribute("data-jeab-pay-locked")){return;}var root=row.closest("[data-jeab-pays]");row.remove();if(root){sync(root);}});})();</script>';
}

function jeab_ordrar_payout_row_html(int|string $index, array $payout, bool $blank): string
{
    $locked = trim((string) ($payout['fortnox_credit'] ?? '')) !== '';
    $type = (string) ($payout['type'] ?? '');
    $amount = (int) ($payout['amount'] ?? 0);
    $reveal = jeab_ordrar_payout_reveal();
    $idx = (string) $index;
    $html = '<div class="jeab-pay" data-jeab-pay' . ($locked ? ' data-jeab-pay-locked' : '') . '>';
    $html .= '<input type="hidden" name="payout_id[' . e($idx) . ']" value="' . e((string) ($payout['id'] ?? '')) . '">';
    if ($locked) {
        $html .= '<input type="hidden" name="payout_type[' . e($idx) . ']" value="' . e($type) . '">';
        $html .= '<input type="hidden" name="payout_amount[' . e($idx) . ']" value="' . e((string) $amount) . '">';
    }
    $html .= '<div class="jeab-pay-top"><span class="v3-lbl">Typ</span><div class="v3-seg jeab-pay-seg" role="radiogroup" aria-label="Typ av utbetalning">';
    foreach (jeab_ordrar_payout_types() as $value => $label) {
        $on = $type === $value;
        $name = $locked ? '' : ' name="payout_type[' . e($idx) . ']"';
        $html .= '<label class="v3-seg-opt' . ($on ? ' is-on' : '') . '"><input type="radio"' . $name . ' value="' . e($value) . '"' . ($on ? ' checked' : '') . ($locked ? ' disabled' : '') . '><span>' . e($label) . '</span></label>';
    }
    $html .= '</div>';
    if (!$locked && !$blank) {
        $html .= '<button type="button" class="jeab-pay-remove" data-jeab-pay-remove>Ta bort</button>';
    } elseif (!$locked && $blank) {
        $html .= '<button type="button" class="jeab-pay-remove" data-jeab-pay-remove>Ta bort</button>';
    }
    $html .= '</div>';
    $amountValue = $amount > 0 ? jeab_ordrar_kr($amount) : '';
    $html .= '<div class="jeab-pay-fields">';
    $html .= '<label><span>Belopp (kr)</span><input type="text" inputmode="numeric" autocomplete="off" spellcheck="false" data-lpignore="true"' . ($locked ? ' readonly' : ' name="payout_amount[' . e($idx) . ']"') . ' value="' . e($amountValue) . '"></label>';
    $html .= jeab_ordrar_payout_konto_field('payout_konto[' . $idx . ']', $payout, $reveal);
    $html .= '</div>';
    $status = (string) ($payout['status'] ?? '');
    if ($status === 'krediterad') {
        $credit = trim((string) ($payout['fortnox_credit'] ?? ''));
        $html .= '<p class="jeab-pay-status">Krediterad' . ($credit !== '' ? ' · kreditfaktura ' . e($credit) : '') . '</p>';
    } elseif ($status === 'kreditering misslyckades') {
        $error = trim((string) ($payout['error'] ?? ''));
        $html .= '<p class="jeab-pay-status is-bad">Kreditering misslyckades' . ($error !== '' ? ' · ' . e($error) : '') . '</p>';
    }
    $html .= '</div>';
    return $html;
}

function jeab_ordrar_payouts_html(array $row): string
{
    $payouts = jeab_ordrar_payouts($row);
    $html = '<div class="jeab-pays" data-jeab-pays><input type="hidden" name="payouts_form" value="1">';
    $html .= '<div class="jeab-pay-head"><span>Utbetalningar</span><button type="button" class="jeab-pay-add" data-jeab-pay-add>+ Lägg till utbetalning</button></div>';
    $html .= '<p class="jeab-pay-help" data-jeab-pay-help' . ($payouts === [] ? ' hidden' : '') . '>Inbyte eller Goodwill. Belopp och konto är inte obligatoriska. Flera utbetalningar går bra.</p>';
    $html .= '<div data-jeab-pay-list>';
    foreach ($payouts as $i => $payout) {
        $html .= jeab_ordrar_payout_row_html($i, $payout, trim((string) ($payout['id'] ?? '')) === '');
    }
    $html .= '</div><template>' . jeab_ordrar_payout_row_html('__N__', ['id' => '', 'type' => '', 'amount' => 0, 'clearing' => '', 'account' => '', 'status' => 'sparad', 'fortnox_credit' => ''], true) . '</template></div>';
    return $html . jeab_ordrar_payout_script();
}

function jeab_ordrar_payouts_summary_html(array $row): string
{
    $payouts = jeab_ordrar_payouts($row);
    if ($payouts === []) {
        return '';
    }
    $html = '<div class="jeab-pays is-summary" data-jeab-pays-summary><div class="jeab-pay-head"><span>Utbetalningar</span></div>';
    foreach ($payouts as $payout) {
        $account = jeab_ordrar_payout_display_account($payout);
        $status = (string) ($payout['status'] ?? 'sparad');
        $statusText = $status === 'krediterad' ? 'Krediterad' : ($status === 'kreditering misslyckades' ? 'Kreditering misslyckades' : 'Sparad');
        $credit = trim((string) ($payout['fortnox_credit'] ?? ''));
        $html .= '<p class="jeab-pay-line"><b>' . e(jeab_ordrar_payout_label($payout)) . '</b> · ' . e(jeab_ordrar_kr((int) ($payout['amount'] ?? 0))) . ' kr';
        if ($account !== '') {
            $html .= ' · konto ' . e($account);
        }
        $html .= ' · ' . e($statusText);
        if ($status === 'kreditering misslyckades') {
            $error = trim((string) ($payout['error'] ?? ''));
            if ($error !== '') {
                $html .= ' · ' . e($error);
            }
        }
        if ($credit !== '') {
            $html .= ' · kreditfaktura ' . e($credit);
        }
        $html .= '</p>';
    }
    return $html . '</div>';
}

function jeab_ordrar_payout_retry_html(array $row, string $company, int $openId): string
{
    $html = '';
    $action = jeab_ordrar_url(['co' => $company, 'id' => $openId, 'stop' => 'fakturering']);
    foreach (jeab_ordrar_payouts($row) as $payout) {
        if ((string) ($payout['status'] ?? '') !== 'kreditering misslyckades') {
            continue;
        }
        $reveal = jeab_ordrar_payout_reveal();
        $html .= '<form method="post" class="jeab-pay-retry" action="' . e($action) . '" data-v3-form="payout">';
        $html .= jeab_csrf_field();
        $html .= '<input type="hidden" name="action" value="ordrar.payout_retry">';
        $html .= '<input type="hidden" name="v3" value="1">';
        $html .= '<input type="hidden" name="id" value="' . (int) $openId . '">';
        $html .= '<input type="hidden" name="payout_id" value="' . e((string) ($payout['id'] ?? '')) . '">';
        $error = trim((string) ($payout['error'] ?? ''));
        $html .= '<p class="jeab-pay-line"><b>' . e(jeab_ordrar_payout_label($payout)) . '</b> · ' . e(jeab_ordrar_kr((int) ($payout['amount'] ?? 0))) . ' kr · kreditering misslyckades' . ($error !== '' ? ' · ' . e($error) : '') . '</p>';
        $html .= '<div class="jeab-pay-fields">';
        $html .= jeab_ordrar_payout_konto_field('payout_konto', $payout, $reveal);
        $html .= '</div><button class="ordrar-secondary v3-btn is-sec" type="submit" data-working="Försöker…">Försök igen</button></form>';
    }
    return $html;
}
