<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Ett Grok-svar per ärende: leadsäljare, slutkund, köp/support, produkt, slag och klassning.
 * JSON sparas på ärendet. Sidan läser den och anropar inte modellen igen.
 */

function jeab_inkorg_case_products(): array
{
    return ['Sol', 'Batteri', 'Elbilsladdare', 'Servicejobb'];
}

function jeab_inkorg_case_product_name(string $raw): string
{
    $t = mb_strtolower(trim($raw));
    if ($t === '') {
        return '';
    }
    foreach (jeab_inkorg_case_products() as $name) {
        if (mb_strtolower($name) === $t) {
            return $name;
        }
    }
    if (preg_match('/service/u', $t) === 1) {
        return 'Servicejobb';
    }
    if (preg_match('/elbil|laddbox|laddare/u', $t) === 1) {
        return 'Elbilsladdare';
    }
    $battery = preg_match('/batter/u', $t) === 1;
    $solar = preg_match('/solcell|(?<![\p{L}])sol(?![\p{L}])/u', $t) === 1;
    if ($battery && $solar) {
        if (preg_match('/befintlig|redan|tillägg|tillagg|bara\s+batter|till\s+sol/u', $t) === 1) {
            return 'Batteri';
        }
        return 'Sol';
    }
    if ($battery) {
        return 'Batteri';
    }
    if ($solar) {
        return 'Sol';
    }
    return '';
}

function jeab_inkorg_case_offer(string $product): string
{
    return ['Sol' => 'sol', 'Batteri' => 'batteri', 'Elbilsladdare' => 'laddbox', 'Servicejobb' => 'servicejobb'][$product] ?? '';
}

function jeab_inkorg_case_conf(mixed $v): float
{
    if (!is_numeric($v)) {
        return 0.0;
    }
    $n = (float) $v;
    if ($n > 1.0) {
        $n /= 100.0;
    }
    if ($n < 0.0) {
        return 0.0;
    }
    return $n > 1.0 ? 1.0 : $n;
}

function jeab_inkorg_case_redact(string $s): string
{
    $held = [];
    $s = preg_replace_callback('/\b(?:hs|g)-\d{6,}\b/iu', static function (array $m) use (&$held): string {
        $token = "\x1E" . count($held) . "\x1E";
        $held[$token] = (string) $m[0];
        return $token;
    }, $s) ?? $s;
    $s = preg_replace('/\b(?:19|20)?\d{6}[-+]?\d{4}\b/u', '[pnr]', $s) ?? $s;
    $s = preg_replace('/\b\d{8,}\b/u', '[nr]', $s) ?? $s;
    return $held === [] ? $s : strtr($s, $held);
}

function jeab_inkorg_case_mask_text(string $s): string
{
    $s = jeab_inkorg_case_redact($s);
    $s = preg_replace_callback('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', static function (array $m): string {
        $email = (string) $m[0];
        if (function_exists('jeab_lead_mask_email')) {
            $masked = jeab_lead_mask_email($email);
            return $masked !== '' ? $masked : '***';
        }
        return '***';
    }, $s) ?? $s;
    return $s;
}

function jeab_inkorg_case_email(string $raw): string
{
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $raw, $m) !== 1) {
        return '';
    }
    $email = mb_strtolower($m[0]);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

/** Brytaren på Regler. Saknas nyckeln är den av, och den gamla klassningen styr. */
function jeab_inkorg_case_decides(): bool
{
    if (!function_exists('jeab_inkorg_json_file')) {
        return false;
    }
    $d = jeab_inkorg_json_file('settings.json');
    if (!is_array($d) || !array_key_exists('grok_decides', $d)) {
        return false;
    }
    $v = $d['grok_decides'];
    return $v === true || $v === 1 || $v === '1';
}

/** @param array<string,mixed> $msg @return array<string,mixed> */
function jeab_inkorg_case_of(array $msg, array $thread = []): array
{
    if (!jeab_inkorg_case_decides()) {
        return [];
    }
    $fromMsg = $msg['labels']['case_class'] ?? null;
    if (is_array($fromMsg) && $fromMsg !== []) {
        return $fromMsg;
    }
    $fromThread = $thread['case_class'] ?? null;
    return is_array($fromThread) ? $fromThread : [];
}

function jeab_inkorg_case_confident(array $case, string $field): bool
{
    return $case !== [] && (float) (($case['confidence'][$field] ?? 0)) >= 0.8;
}

function jeab_inkorg_case_backorder_text(array $msg): bool
{
    $text = mb_strtolower((string) ($msg['subject'] ?? '') . "\n" . (string) ($msg['body'] ?? '') . "\n" . (string) ($msg['body_text'] ?? ''));
    return preg_match('/restnot|restorder|backorder|ej i lager|inte i lager|slut(?:et)? i lager|out of stock|leveransrest/u', $text) === 1;
}

/** Leverantörsbekräftelse med restnotering. Vanlig kundtext utan orderbekräftelse räcker inte. */
function jeab_inkorg_case_supplier_backorder(array $msg): bool
{
    if (!jeab_inkorg_case_backorder_text($msg)) {
        return false;
    }
    $blob = mb_strtolower((string) ($msg['from'] ?? '') . "\n" . (string) ($msg['subject'] ?? '') . "\n" . (string) ($msg['body'] ?? '') . "\n" . (string) ($msg['body_text'] ?? ''));
    return preg_match('/orderbekräft|orderbekraft|order confirmation|restnot|aprilice|lightleap|leverantör|leverantor/u', $blob) === 1;
}

/**
 * @param array<string,mixed> $out
 * @param array<string,mixed> $msg
 * @return array<string,mixed>|null
 */
function jeab_inkorg_case_normalize(array $out, array $msg): ?array
{
    $has = array_key_exists('case_intent', $out)
        || array_key_exists('primary_product', $out)
        || array_key_exists('kind', $out)
        || array_key_exists('is_lead_seller', $out)
        || array_key_exists('field_confidence', $out);
    if (!$has) {
        return null;
    }
    $intent = trim((string) ($out['case_intent'] ?? ''));
    if (!in_array($intent, ['kopintresse', 'support'], true)) {
        $legacy = (string) ($out['intent'] ?? '');
        if ($legacy === 'kop' || $legacy === 'kopintresse') {
            $intent = 'kopintresse';
        } elseif ($legacy === 'support') {
            $intent = 'support';
        } else {
            $intent = '';
        }
    }
    $kind = (string) ($out['kind'] ?? '');
    if (!in_array($kind, ['information', 'atgard'], true)) {
        $kind = $intent === 'kopintresse' || $intent === 'support' ? 'atgard' : 'information';
    }
    if ($intent === '' && $kind !== 'information') {
        $intent = 'kopintresse';
    }
    $type = function_exists('jeab_inkorg_type_new') ? jeab_inkorg_type_new((string) ($out['type'] ?? '')) : (string) ($out['type'] ?? '');
    $allowed = function_exists('jeab_inkorg_type_list') ? jeab_inkorg_type_list() : [];
    if (!in_array($type, $allowed, true)) {
        if ($kind === 'information') {
            $type = 'Information';
        } elseif ($intent === 'support') {
            $type = 'Support';
        } elseif ($intent === 'kopintresse') {
            $type = 'Ny lead';
        } else {
            $type = 'Oklassad';
        }
    }
    $fallback = jeab_inkorg_case_conf($out['confidence'] ?? 0);
    $rawConf = [];
    if (is_array($out['field_confidence'] ?? null)) {
        $rawConf = $out['field_confidence'];
    } elseif (is_array($out['confidence'] ?? null)) {
        $rawConf = $out['confidence'];
    }
    $confidence = [];
    foreach (['is_lead_seller', 'seller_name', 'customer_email', 'customer_name', 'intent', 'primary_product', 'kind', 'type', 'label'] as $field) {
        $confidence[$field] = array_key_exists($field, $rawConf) ? jeab_inkorg_case_conf($rawConf[$field]) : $fallback;
    }
    $primary = jeab_inkorg_case_product_name((string) ($out['primary_product'] ?? ''));
    $second = [];
    $rawSec = $out['secondary_products'] ?? [];
    if (is_string($rawSec)) {
        $rawSec = preg_split('/\s*,\s*/u', $rawSec) ?: [];
    }
    foreach ((array) $rawSec as $item) {
        $name = jeab_inkorg_case_product_name((string) $item);
        if ($name !== '' && $name !== $primary && !in_array($name, $second, true)) {
            $second[] = $name;
        }
    }
    $label = jeab_inkorg_case_mask_text(mb_substr(trim((string) ($out['label'] ?? '')), 0, 80));
    if (preg_match('/restnot|ej i lager|inte i lager|backorder/iu', $label) === 1) {
        $label = 'Restnoterad/ej i lager';
    }
    $sellerFlag = filter_var($out['is_lead_seller'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $case = [
        'is_lead_seller' => $sellerFlag,
        'seller_name' => jeab_inkorg_case_mask_text(mb_substr(trim((string) ($out['seller_name'] ?? '')), 0, 120)),
        'customer_email' => jeab_inkorg_case_email((string) ($out['customer_email'] ?? '')),
        'customer_name' => jeab_inkorg_case_mask_text(mb_substr(trim((string) ($out['customer_name'] ?? '')), 0, 120)),
        'intent' => $intent,
        'primary_product' => $primary,
        'secondary_products' => $second,
        'kind' => $kind,
        'type' => $type,
        'priority' => function_exists('jeab_inkorg_default_priority') ? jeab_inkorg_default_priority($type) : 3,
        'label' => $label,
        'confidence' => $confidence,
        'order_link' => ['reminder_id' => 0, 'number' => '', 'via' => ''],
    ];
    return jeab_inkorg_case_enforce($case, $msg);
}

/**
 * Kodspärrar: support-kryss och restnotering. De väger tyngre än modellens ord.
 *
 * @param array<string,mixed> $case
 * @param array<string,mixed> $msg
 * @return array<string,mixed>
 */
function jeab_inkorg_case_enforce(array $case, array $msg): array
{
    if (function_exists('jeab_inkorg_form_is_existing_support') && jeab_inkorg_form_is_existing_support($msg)) {
        $case['intent'] = 'support';
        $case['kind'] = 'atgard';
        $case['type'] = 'Support';
        $case['is_lead_seller'] = false;
        $case['confidence']['intent'] = 1.0;
        $case['confidence']['kind'] = 1.0;
        $case['confidence']['type'] = 1.0;
        $case['confidence']['is_lead_seller'] = 1.0;
    }
    if (jeab_inkorg_purchase_blocked($msg) === 'outside') {
        $case['type'] = 'Information';
        $case['kind'] = 'information';
        $case['intent'] = 'information';
        $case['label'] = 'Utanför området';
        $case['is_lead_seller'] = false;
        foreach (['intent', 'kind', 'type', 'label', 'is_lead_seller'] as $field) {
            $case['confidence'][$field] = 1.0;
        }
    }
    if ((string) ($case['kind'] ?? '') === 'information' && !in_array((string) ($case['type'] ?? ''), ['Information', 'Skräp', 'Order'], true) && trim((string) ($case['primary_product'] ?? '')) === '') {
        $case['type'] = 'Information';
    }
    $backorder = jeab_inkorg_case_supplier_backorder($msg) || (string) ($case['label'] ?? '') === 'Restnoterad/ej i lager';
    if ($backorder) {
        $case['kind'] = 'atgard';
        $case['label'] = 'Restnoterad/ej i lager';
        $case['type'] = 'Kräver åtgärd från oss';
        $case['intent'] = 'support';
        $case['is_lead_seller'] = false;
        $case['confidence']['kind'] = 1.0;
        $case['confidence']['label'] = 1.0;
        $case['confidence']['type'] = 1.0;
        $case['confidence']['intent'] = 1.0;
        $case['confidence']['is_lead_seller'] = 1.0;
        $case['order_link'] = jeab_inkorg_case_link_order($msg);
    }
    $case['priority'] = function_exists('jeab_inkorg_default_priority') ? jeab_inkorg_default_priority((string) $case['type']) : (int) ($case['priority'] ?? 3);
    return $case;
}

/**
 * @param array<string,mixed> $msg
 * @return array{reminder_id:int,number:string,via:string}
 */
/** Ordern måste tillhöra ärendets bolag. Okänt bolag lämnar urvalet som förut. */
function jeab_inkorg_case_purchase_company_ok(array $row, string $want): bool
{
    if ($want === '') {
        return true;
    }
    $have = function_exists('jeab_normalize_company')
        ? (jeab_normalize_company($row['company'] ?? null) ?? '')
        : '';

    return $have !== '' && $have === $want;
}

function jeab_inkorg_case_link_order(array $msg): array
{
    $blank = ['reminder_id' => 0, 'number' => '', 'via' => ''];
    $wantCompany = function_exists('jeab_inkorg_message_company') ? jeab_inkorg_message_company($msg) : '';
    $text = (string) ($msg['subject'] ?? '') . "\n" . (string) ($msg['body'] ?? '') . "\n" . (string) ($msg['body_text'] ?? '');
    $number = '';
    if (preg_match_all('/(?:order(?:nummer|nr)?|beställning|bestallning)\s*[:#]?\s*([A-Za-z0-9][A-Za-z0-9\-]{2,24})/iu', $text, $all)) {
        foreach ($all[1] as $cand) {
            if (preg_match('/\d/u', (string) $cand) === 1) {
                $number = (string) $cand;
                break;
            }
        }
    }
    if (!function_exists('jeab_purchase_all')) {
        $blank['number'] = $number;
        return $blank;
    }
    $rows = [];
    foreach (jeab_purchase_all() as $rid => $row) {
        if (!is_array($row) || !jeab_inkorg_case_purchase_company_ok($row, $wantCompany)) {
            continue;
        }
        $id = (int) ($row['reminder_id'] ?? $rid);
        $rows[] = $row + ['_id' => $id];
        if ($number === '') {
            continue;
        }
        $web = is_array($row['webshop'] ?? null) ? $row['webshop'] : [];
        $cands = [(string) ($web['number'] ?? ''), (string) ($row['order_number'] ?? '')];
        if (function_exists('jeab_ordrar_shop_order_no')) {
            foreach (['aprilice', 'lightleap'] as $shop) {
                $cands[] = jeab_ordrar_shop_order_no($row, [], $shop);
            }
        }
        foreach ($cands as $cand) {
            if ($cand !== '' && strcasecmp($cand, $number) === 0) {
                return ['reminder_id' => $id, 'number' => $cand, 'via' => 'order'];
            }
        }
    }
    if ($number !== '' && ctype_digit($number) && function_exists('jeab_inkorg_find_order')) {
        $found = jeab_inkorg_find_order($number);
        $purchase = is_array($found) && is_array($found['purchase'] ?? null) ? $found['purchase'] : [];
        if (is_array($found) && jeab_inkorg_case_purchase_company_ok($purchase, $wantCompany)) {
            return ['reminder_id' => (int) ($found['reminder_id'] ?? 0), 'number' => $number, 'via' => 'order'];
        }
    }
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_party_id')) {
        $partyEmail = '';
        $partyPhone = '';
        if (function_exists('jeab_lead_customer')) {
            $cust = jeab_lead_customer($msg);
            $partyEmail = trim((string) ($cust['email'] ?? ''));
            $partyPhone = trim((string) ($cust['phone'] ?? ''));
        }
        if ($partyEmail === '') {
            $partyEmail = trim((string) ($msg['from'] ?? ''));
        }
        $party = jeab_contacts_sole_party_id($partyEmail, $partyPhone);
        if ($party !== '') {
            $contactHits = [];
            foreach ($rows as $row) {
                if (trim((string) ($row['contact_id'] ?? '')) === $party) {
                    $contactHits[] = (int) $row['_id'];
                }
            }
            $contactHits = array_values(array_unique(array_filter($contactHits)));
            if (count($contactHits) === 1) {
                return ['reminder_id' => $contactHits[0], 'number' => $number, 'via' => 'contact'];
            }
        }
    }
    $want = jeab_inkorg_case_address_key($text);
    if ($want === '' || !function_exists('jeab_inkorg_support_same_address')) {
        $blank['number'] = $number;
        return $blank;
    }
    $hits = [];
    foreach ($rows as $row) {
        if (jeab_inkorg_support_same_address((string) ($row['address'] ?? ''), $want)) {
            $hits[] = (int) $row['_id'];
        }
    }
    $hits = array_values(array_unique(array_filter($hits)));
    if (count($hits) === 1) {
        return ['reminder_id' => $hits[0], 'number' => $number, 'via' => 'address'];
    }
    $blank['number'] = $number;
    return $blank;
}

function jeab_inkorg_case_address_key(string $text): string
{
    if (!function_exists('jeab_inkorg_support_address_key')) {
        return '';
    }
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '' || preg_match('/\d/u', $line) !== 1) {
            continue;
        }
        $key = jeab_inkorg_support_address_key($line);
        if ($key !== '') {
            return $key;
        }
        if (preg_match('/([^\d\n]{2,40}\s+\d{1,4}\s*[a-zA-Z]?)/u', $line, $m) === 1) {
            $key = jeab_inkorg_support_address_key(trim((string) $m[1]));
            if ($key !== '') {
                return $key;
            }
        }
    }
    return '';
}

/** Tom sträng = inget håll. Annars orsaken, utan e-post eller personnummer. */
function jeab_inkorg_case_hold_why(array $case): string
{
    if ($case === []) {
        return '';
    }
    $fields = ['is_lead_seller', 'primary_product', 'kind', 'type'];
    if ((string) ($case['intent'] ?? '') !== '' || (string) ($case['kind'] ?? '') !== 'information') {
        $fields[] = 'intent';
    }
    if (!empty($case['is_lead_seller'])) {
        $fields[] = 'customer_email';
        if (trim((string) ($case['customer_email'] ?? '')) === '') {
            return 'kundens e-post saknas i leaden';
        }
    }
    if (trim((string) ($case['label'] ?? '')) !== '') {
        $fields[] = 'label';
    }
    $names = [
        'is_lead_seller' => 'leadsäljare',
        'intent' => 'avsikt',
        'primary_product' => 'produkt',
        'kind' => 'slag',
        'type' => 'klassning',
        'label' => 'etikett',
        'customer_email' => 'kundens e-post',
    ];
    // Samma fältgräns som Behöver granskas (review_min_confidence / 100, standard 0,7).
    $floor = function_exists('jeab_inkorg_review_field_min') ? jeab_inkorg_review_field_min() : 0.7;
    foreach ($fields as $field) {
        if ((float) (($case['confidence'][$field] ?? 0)) < $floor) {
            return 'Grok är osäker på ' . ($names[$field] ?? 'fält');
        }
    }
    return '';
}

/**
 * @param array<string,mixed> $labels
 * @param array<string,mixed> $case
 * @return array<string,mixed>
 */
function jeab_inkorg_case_apply_labels(array $labels, array $case): array
{
    $labels['case_class'] = $case;
    if (!jeab_inkorg_case_decides()) {
        return $labels;
    }
    $labels['type'] = (string) $case['type'];
    $labels['action'] = function_exists('jeab_inkorg_derived_action') ? jeab_inkorg_derived_action((string) $case['type']) : (string) ($labels['action'] ?? 'svara');
    $labels['priority'] = (int) $case['priority'];
    if ((string) $case['kind'] === 'information') {
        $labels['intent'] = 'information';
    } elseif ((string) $case['intent'] === 'support' || (string) $case['type'] === 'Support' || (string) $case['type'] === 'Kräver åtgärd från oss') {
        $labels['intent'] = (string) $case['type'] === 'Kräver åtgärd från oss' ? 'annat' : 'support';
    } elseif ((string) $case['intent'] === 'kopintresse') {
        $labels['intent'] = 'kop';
    }
    $offer = jeab_inkorg_case_offer((string) $case['primary_product']);
    if (in_array($offer, ['laddbox', 'batteri', 'sol'], true)) {
        $labels['offer'] = $offer;
    } else {
        $labels['offer'] = '';
    }
    $productKey = ['Sol' => 'solceller', 'Batteri' => 'batteri', 'Elbilsladdare' => 'elbilsladdare'][(string) $case['primary_product']] ?? '';
    $second = array_map('strval', (array) ($case['secondary_products'] ?? []));
    if ($productKey === 'solceller' && in_array('Batteri', $second, true)) {
        $productKey = 'sol+batteri';
        $labels['offer'] = 'sol+batteri';
    }
    if ($productKey !== '') {
        $labels['product'] = $productKey;
    }
    if (!empty($case['is_lead_seller']) && jeab_inkorg_case_confident($case, 'is_lead_seller')) {
        $labels['lead'] = 'kopt';
    } elseif ((string) $case['intent'] === 'support' || (string) $case['kind'] === 'information') {
        $labels['lead'] = 'eget';
    }
    $tagMap = ['Sol' => 'sol', 'Batteri' => 'batteri', 'Elbilsladdare' => 'elbilsladdare', 'Servicejobb' => 'servicejobb'];
    $tag = $tagMap[(string) $case['primary_product']] ?? '';
    if ((string) ($case['type'] ?? '') === 'Information') {
        $tag = '';
        $labels['product'] = '';
        $labels['offer'] = '';
    }
    $tags = (array) ($labels['add_tags'] ?? []);
    if ($tag !== '') {
        $tags[] = $tag;
    }
    if ((string) ($case['type'] ?? '') !== 'Information') {
        foreach ($second as $extraName) {
            $extraTag = $tagMap[$extraName] ?? '';
            if ($extraTag !== '') {
                $tags[] = $extraTag;
            }
        }
    }
    if ((string) ($case['label'] ?? '') === 'Restnoterad/ej i lager') {
        $tags[] = 'restnoterad';
    }
    $labels['add_tags'] = array_values(array_unique(array_map('strval', $tags)));
    $why = jeab_inkorg_case_hold_why($case);
    if ($why !== '') {
        $cap = 79;
        // Osäkert fält sänks under gränsen. finish_labels sätter då needs_review och «Behöver granskas».
        if (str_starts_with($why, 'Grok är osäker')) {
            $review = function_exists('jeab_inkorg_review_min_confidence') ? jeab_inkorg_review_min_confidence() : 70;
            $cap = max(0, $review - 1);
        }
        $labels['confidence'] = min((int) ($labels['confidence'] ?? 0), $cap);
        $labels['case_hold'] = $why;
    }
    $labels['reason'] = jeab_inkorg_case_mask_text((string) ($labels['reason'] ?? ''));
    return $labels;
}

function jeab_inkorg_reclassify_locked(): bool
{
    return !empty($GLOBALS['_JEAB_RECLASSIFY_LOCK']);
}

/** Omklassning och den äldre spärren. Påminnelser får fortfarande skapas. */
function jeab_inkorg_send_held(): bool
{
    return !empty($GLOBALS['_JEAB_RECLASSIFY_LOCK']) || !empty($GLOBALS['_JEAB_KLASSNING_NO_SEND']);
}

function jeab_inkorg_reclassify_lock(bool $on): void
{
    if ($on) {
        $GLOBALS['_JEAB_RECLASSIFY_LOCK'] = 1;
        return;
    }
    unset($GLOBALS['_JEAB_RECLASSIFY_LOCK']);
}

/** @return array{ok:false,sent:int,dry:int,error:string,blocked:string} */
function jeab_inkorg_reclassify_hold(string $what): array
{
    return ['ok' => false, 'sent' => 0, 'dry' => 1, 'error' => 'omklassning skickar inte', 'blocked' => $what];
}

function jeab_inkorg_purchase_text(array $msg): string
{
    $text = (string) ($msg['subject'] ?? '') . "\n" . (string) ($msg['body'] ?? '') . "\n" . (string) ($msg['body_text'] ?? '');
    $text = preg_replace('/<br\s*\/?>/iu', "\n", $text) ?? $text;
    return trim(strip_tags($text));
}

function jeab_inkorg_purchase_domain(string $email): string
{
    $email = mb_strtolower(trim($email));
    if (!str_contains($email, '@')) {
        return '';
    }
    return (string) substr(strrchr($email, '@') ?: '', 1);
}

function jeab_inkorg_purchase_domain_hit(string $domain, string $blocked): bool
{
    $domain = mb_strtolower(trim($domain));
    $blocked = mb_strtolower(ltrim(trim($blocked), '@'));
    if ($domain === '' || $blocked === '') {
        return false;
    }
    if (!str_contains($blocked, '.')) {
        foreach (explode('.', $domain) as $label) {
            if ($label === $blocked) {
                return true;
            }
            $rest = '';
            if (strlen($blocked) >= 4 && strlen($label) >= 4 && str_starts_with($label, $blocked)) {
                $rest = substr($label, strlen($blocked));
            } elseif (strlen($blocked) >= 4 && strlen($label) >= 4 && str_starts_with($blocked, $label)) {
                $rest = substr($blocked, strlen($label));
            }
            if (in_array($rest, ['solar', 'power', 'energy', 'energi', 'group'], true)) {
                return true;
            }
        }
        return false;
    }
    return $domain === $blocked || str_ends_with($domain, '.' . $blocked);
}

/** @return list<string> */
function jeab_inkorg_purchase_supplier_brands(): array
{
    return ['huawei', 'scanmont', 'epax', 'epaxsolar', 'solaredge', 'fronius', 'growatt', 'goodwe', 'sungrow', 'easee', 'zaptec', 'chargeamps', 'pylontech', 'laniakeaenergy', 'ecosolarcarport', 'ecopvcarport', 'felicitysolar', 'felicity', 'renon', 'renonpower', 'conluxs', 'wwwebb', 'contractors1000', 'ifsek', 'gozan', 'solaxel'];
}

/** Företagsnamn som kan stå i signatur eller brödtext när From är en annan domän. */
function jeab_inkorg_purchase_supplier_names(): array
{
    return ['epax solar', 'epax', 'renon power', 'renon', 'sol axel', 'solaxel', 'gozan', 'ifsek'];
}

function jeab_inkorg_purchase_offers_us(string $text): bool
{
    return preg_match('/erbjud|sälj|salj|försäljning|forsaljning|grossist|distribut|återförsälj|aterforsalj|katalog|prislista|tillverkare|leverantör|leverantor|samarbete|sortiment|tjänst\p{L}*\s+till\s+(?:er|oss)|tjanst\p{L}*\s+till\s+(?:er|oss)|produkter\s+till\s+(?:er|oss)/u', $text) === 1;
}

/** Namn i avsändarnamn, eller namn i texten när mejlet erbjuder oss något. */
function jeab_inkorg_purchase_supplier_named(array $msg): bool
{
    $fromName = mb_strtolower((string) ($msg['from_name'] ?? ''));
    $text = mb_strtolower(jeab_inkorg_purchase_text($msg));
    $offers = jeab_inkorg_purchase_offers_us($text);
    foreach (jeab_inkorg_purchase_supplier_names() as $name) {
        if ($fromName !== '' && str_contains($fromName, $name)) {
            return true;
        }
        if ($offers && str_contains($text, $name)) {
            return true;
        }
    }
    $blob = $fromName . "\n" . $text;
    if (preg_match_all('/[a-z0-9._%+\-]+@([a-z0-9.\-]+\.[a-z]{2,})/u', $blob, $found) !== false) {
        foreach ($found[1] as $domain) {
            if (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address('a@' . $domain)) {
                continue;
            }
            foreach (jeab_inkorg_purchase_supplier_brands() as $brand) {
                if (jeab_inkorg_purchase_domain_hit((string) $domain, $brand)) {
                    return true;
                }
            }
        }
    }
    return false;
}

/** Avsändaren vill sälja eller samarbeta, eller mejlet går till många. */
function jeab_inkorg_purchase_seller_pitch(array $msg): bool
{
    $text = mb_strtolower(jeab_inkorg_purchase_text($msg) . "\n" . (string) ($msg['from_name'] ?? ''));
    if (preg_match('/varma\s+leads|samarbete\s+kring\s+laddstolpar|skrotköp|skrotkop|vi\s+köper\s+(?:ert\s+)?skrot|köper\s+skrot|sälja\s+till\s+(?:er|oss)|salja\s+till\s+(?:er|oss)|vill\s+(?:gärna\s+|garna\s+)?sälja|vi\s+erbjuder|erbjuder\s+er|samarbeta|förslag\s+på\s+samarbete|forslag\s+pa\s+samarbete|återförsäljare|aterforsaljare|lead(?:säljare|saljare)/u', $text) === 1) {
        return true;
    }
    $blob = mb_strtolower((string) ($msg['to'] ?? '') . ' ' . (string) ($msg['cc'] ?? '') . ' ' . jeab_inkorg_purchase_header($msg, 'To') . ' ' . jeab_inkorg_purchase_header($msg, 'Cc'));
    $found = [];
    $count = preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/u', $blob, $found);
    if ($count === false || $count < 1 || !isset($found[0]) || $found[0] === []) {
        return false;
    }
    $seen = [];
    foreach ($found[0] as $email) {
        $email = mb_strtolower($email);
        if (isset($seen[$email])) {
            continue;
        }
        $seen[$email] = true;
        if (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($email)) {
            unset($seen[$email]);
        }
    }
    return count($seen) >= 4;
}

function jeab_inkorg_purchase_test_sender(string $email): bool
{
    $email = mb_strtolower(trim($email));
    if (jeab_inkorg_purchase_domain_hit(jeab_inkorg_purchase_domain($email), 'qpsmedia')) {
        return true;
    }
    return function_exists('jeab_auto_test_email') && jeab_auto_test_email($email);
}

/** @return list<string> */
function jeab_inkorg_purchase_area_words(string $kind): array
{
    if ($kind === 'in') {
        return ['stockholm', 'solna', 'sundbyberg', 'järfälla', 'jarfalla', 'sollentuna', 'täby', 'taby', 'danderyd', 'lidingö', 'lidingo', 'nacka', 'huddinge', 'botkyrka', 'haninge', 'tyresö', 'tyreso', 'nynäshamn', 'nynashamn', 'norrtälje', 'norrtalje', 'södertälje', 'sodertalje', 'vallentuna', 'österåker', 'osteraker', 'vaxholm', 'ekerö', 'ekero', 'salem', 'malmö', 'malmo', 'skåne', 'skane', 'lund', 'helsingborg', 'kristianstad', 'trelleborg', 'ystad', 'eslöv', 'eslov', 'landskrona', 'ängelholm', 'angelholm', 'hässleholm', 'hassleholm', 'lomma', 'kävlinge', 'kavlinge', 'höganäs', 'hoganas', 'staffanstorp'];
    }
    return ['örebro', 'orebro', 'göteborg', 'goteborg', 'uppsala', 'västerås', 'vasteras', 'linköping', 'linkoping', 'norrköping', 'norrkoping', 'jönköping', 'jonkoping', 'umeå', 'umea', 'gävle', 'gavle', 'borås', 'boras', 'karlstad', 'växjö', 'vaxjo', 'halmstad', 'kalmar', 'falun', 'sundsvall', 'östersund', 'ostersund', 'luleå', 'lulea', 'kiruna', 'visby', 'karlskrona', 'eskilstuna', 'nyköping', 'nykoping'];
}

function jeab_inkorg_purchase_area_hit(string $text, string $kind): bool
{
    foreach (jeab_inkorg_purchase_area_words($kind) as $word) {
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u', $text) === 1) {
            return true;
        }
    }
    return false;
}

function jeab_inkorg_purchase_line_value(array $lines, int $i, string $inline): string
{
    $val = trim($inline);
    if ($val !== '') {
        return $val;
    }
    $n = count($lines);
    for ($k = $i + 1; $k < $n; $k++) {
        $next = trim((string) $lines[$k]);
        if ($next === '') {
            continue;
        }
        return $next;
    }
    return '';
}

/** Formulärets ort och postnummer. De väger tyngre än en signatur. */
function jeab_inkorg_purchase_form_area(array $msg): array
{
    $lines = preg_split("/\r\n|\n|\r/", jeab_inkorg_purchase_customer_text(jeab_inkorg_purchase_text($msg))) ?: [];
    $placeLabel = '/^(?:ort|stad|kommun|postort|postadress|adress)\s*[:：\-–]?\s*(.*)$/iu';
    $postalLabel = '/^(?:postnummer|postnr|postnr\.|zip)\s*[:：\-–]?\s*(.*)$/iu';
    $place = '';
    $postal = '';
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = trim((string) $lines[$i]);
        if ($place === '' && preg_match($placeLabel, $line, $m) === 1) {
            $place = jeab_inkorg_purchase_line_value($lines, $i, (string) ($m[1] ?? ''));
        }
        if ($postal === '' && preg_match($postalLabel, $line, $m) === 1) {
            $postal = jeab_inkorg_purchase_line_value($lines, $i, (string) ($m[1] ?? ''));
        }
    }
    return ['place' => $place, 'postal' => $postal];
}

/** Tar bort vår signatur så Järfälla och 177 57 inte slår ut kundens ort. */
function jeab_inkorg_purchase_customer_text(string $text): string
{
    $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
    $cut = count($lines);
    foreach ($lines as $i => $line) {
        if (preg_match('/^(?:med\s+vänlig(?:a)?|med\s+vanlig(?:a)?|vänliga\s+hälsningar|vanliga\s+halsningar|bästa\s+hälsningar|basta\s+halsningar|mvh|hälsningar|halsningar)\b/iu', trim((string) $line)) === 1) {
            $cut = $i;
            break;
        }
    }
    return trim(implode("\n", array_slice($lines, 0, $cut)));
}

function jeab_inkorg_purchase_postal_prefix(string $text, bool $compact): string
{
    $in = false;
    $out = false;
    $pattern = $compact ? '/(?:^|[^0-9])(\d{3})\s?(\d{2})(?:[^0-9]|$)/u' : '/\b(\d{3})\s(\d{2})\b/u';
    if (preg_match_all($pattern, $text, $codes)) {
        foreach ($codes[1] as $prefix) {
            $n = (int) $prefix;
            if ($n >= 100 && $n <= 299) {
                $in = true;
            } elseif ($n >= 300 && $n <= 999) {
                $out = true;
            }
        }
    }
    if ($out && !$in) {
        return 'out';
    }
    if ($in) {
        return 'in';
    }
    return '';
}

function jeab_inkorg_purchase_postal_kind(string $text): string
{
    return jeab_inkorg_purchase_postal_prefix($text, false);
}

/** Järfälla är Stockholmsområdet (1xx xx), Malmö är Skåne (2xx xx). Övrigt är utanför. */
function jeab_inkorg_purchase_outside(array $msg): bool
{
    $form = jeab_inkorg_purchase_form_area($msg);
    $place = mb_strtolower($form['place']);
    $postal = mb_strtolower($form['postal'] . ' ' . $form['place']);
    if ($place !== '' || trim($form['postal']) !== '') {
        $namedOut = $place !== '' && jeab_inkorg_purchase_area_hit($place, 'out');
        $namedIn = $place !== '' && jeab_inkorg_purchase_area_hit($place, 'in');
        $postalKind = jeab_inkorg_purchase_postal_prefix($postal, true);
        if ($namedOut && !$namedIn) {
            return true;
        }
        if ($postalKind === 'out') {
            return true;
        }
        if ($namedIn || $postalKind === 'in') {
            return false;
        }
    }
    $text = jeab_inkorg_purchase_customer_text(mb_strtolower(jeab_inkorg_purchase_text($msg)));
    if (jeab_inkorg_purchase_area_hit($text, 'out')) {
        return true;
    }
    if (jeab_inkorg_purchase_area_hit($text, 'in')) {
        return false;
    }
    return jeab_inkorg_purchase_postal_kind($text) === 'out';
}

/** @return list<string> */
function jeab_inkorg_purchase_block_domains(): array
{
    $out = [];
    if (function_exists('jeab_inkorg_lists')) {
        foreach ((array) (jeab_inkorg_lists()['lead-block']['entries'] ?? []) as $entry) {
            $entry = mb_strtolower(ltrim(trim((string) $entry), '@'));
            if ($entry !== '') {
                $out[] = $entry;
            }
        }
    }
    if (function_exists('jeab_hs_import_domain_list')) {
        foreach (jeab_hs_import_domain_list('import_supplier_domains') as $entry) {
            $out[] = mb_strtolower(ltrim(trim($entry), '@'));
        }
    }
    return array_values(array_unique(array_filter($out)));
}

/** Frågeraden där kunden väljer produkt. Nästa rad, eller texten efter kolon, är svaret. */
function jeab_inkorg_form_choice_label(string $line): bool
{
    return preg_match('/^(?:produkt|product|tjänst|tjanst|kategori|intresse|behov|önskemål|onskemal|vad gäller det|vad galler det|vad är du intresserad av|vad ar du intresserad av|välj vad du vill ha\b)/iu', trim($line)) === 1;
}

/** Namn, e-post och adress är inte produktvalet. */
function jeab_inkorg_form_stop_label(string $line): bool
{
    return preg_match('/^(?:namn|name|e-?post|e-?mail|telefon|mobil|adress|postnummer|ort|meddelande|submission)\b/iu', trim($line)) === 1;
}

/**
 * Kundens eget svar i formuläret.
 * En alternativlista eller en spårlänk räknas inte. Tomt när svaret är otydligt.
 */
function jeab_inkorg_form_selected_answer(string $text): string
{
    $lines = [];
    foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '' || preg_match('/^https?:\/\//iu', $line) === 1) {
            continue;
        }
        if (function_exists('jeab_inkorg_purchase_banner_line') && jeab_inkorg_purchase_banner_line($line)) {
            continue;
        }
        $lines[] = $line;
    }
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = $lines[$i];
        if (!jeab_inkorg_form_choice_label($line)) {
            continue;
        }
        $picked = '';
        if (preg_match('/[:：]\s*(.+)$/u', $line, $m) === 1) {
            $inline = trim((string) $m[1]);
            if ($inline !== '' && !jeab_inkorg_form_choice_label($inline) && !jeab_inkorg_form_stop_label($inline)) {
                $picked = $inline;
            }
        }
        $options = 0;
        $offertLine = '';
        for ($k = $i + 1; $k < $n; $k++) {
            $next = $lines[$k];
            if (jeab_inkorg_form_stop_label($next) || jeab_inkorg_form_choice_label($next)) {
                break;
            }
            if (preg_match('/^offert på\s+\S+/iu', $next) === 1) {
                $offertLine = $next;
                continue;
            }
            $options++;
            if ($picked === '') {
                $picked = $next;
            }
        }
        if ($offertLine !== '') {
            return $offertLine;
        }
        if ($picked !== '' && $options <= 1) {
            return $picked;
        }
    }
    $offs = [];
    foreach ($lines as $line) {
        if (preg_match('/^offert på\s+(?:batteri|solceller|sol\b|laddbox|ladbox|laddare|elbil)/iu', $line) === 1) {
            $offs[] = $line;
        }
    }
    if (count($offs) === 1) {
        return $offs[0];
    }

    return '';
}

function jeab_inkorg_product_token(string $value): string
{
    $raw = trim($value);
    if ($raw === '') {
        return '';
    }
    $choice = jeab_inkorg_form_selected_answer($raw);
    if ($choice !== '' && mb_strtolower($choice) !== mb_strtolower($raw)) {
        $token = jeab_inkorg_product_token_words($choice);
        if ($token !== '') {
            return $token;
        }
    }

    return jeab_inkorg_product_token_words($raw);
}

/** Orden i ett redan avgränsat svar. En sol-URL i samma sträng är inte sol. */
function jeab_inkorg_product_token_words(string $value): string
{
    $v = mb_strtolower(trim($value));
    if ($v === '') {
        return '';
    }
    $v = preg_replace('#https?://\S+#iu', ' ', $v) ?? $v;
    $v = preg_replace('#(?<![\p{L}\p{N}])www\.\S+#iu', ' ', $v) ?? $v;
    $lad = preg_match('/laddbox|elbilsladd|laddstolpe|\bladdare\b|\belbil\b/u', $v) === 1;
    $bat = preg_match('/batter/u', $v) === 1;
    $sol = preg_match('/solcell|solpanel|\bsol\b/u', $v) === 1;
    if ($lad && !$sol) {
        return 'laddbox';
    }
    if ($bat && $sol) {
        if (preg_match('/^offert på batter/imu', $v) === 1 && preg_match('/^offert på sol/imu', $v) !== 1) {
            return 'batteri';
        }
        if (preg_match('/befintlig|redan|tillägg|tillagg|bara\s+batter/u', $v) === 1) {
            return 'batteri';
        }
        return 'sol';
    }
    if ($lad) {
        return 'laddbox';
    }
    if ($bat) {
        return 'batteri';
    }
    if ($sol) {
        return 'sol';
    }
    return '';
}

/** Formulärets produktvärde, oförändrat. Tomt när fältet saknas. */
function jeab_inkorg_form_product_raw(array $msg): string
{
    $chunks = [jeab_inkorg_purchase_text($msg)];
    $body = (string) ($msg['body'] ?? '');
    if (function_exists('jeab_lead_customer_plain') && $body !== '') {
        $chunks[] = jeab_lead_customer_plain($body);
    }
    $selected = jeab_inkorg_form_selected_answer(implode("\n", $chunks));
    if ($selected !== '' && jeab_inkorg_product_token_words($selected) !== '') {
        return $selected;
    }
    $lines = preg_split("/\r\n|\n|\r/", implode("\n", $chunks)) ?: [];
    $label = '/^(?:produkt|product|tjänst|tjanst|kategori|intresse|behov|önskemål|onskemal|vad gäller det|vad galler det|vad är du intresserad av|vad ar du intresserad av|välj vad du vill ha information och mailoffert på\??)(?:\s*[:：\-–]\s*(.*))?$/iu';
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        if (preg_match($label, trim((string) $lines[$i]), $m) !== 1) {
            continue;
        }
        $val = trim((string) ($m[1] ?? ''));
        if ($val === '') {
            for ($k = $i + 1; $k < $n; $k++) {
                $next = trim((string) $lines[$k]);
                if ($next === '') {
                    continue;
                }
                $val = $next;
                break;
            }
        }
        if ($val !== '' && jeab_inkorg_product_token($val) !== '') {
            return $val;
        }
    }
    $blob = implode("\n", $lines);
    if (preg_match('/(?<![\p{L}])(?:intresse|behov|önskemål|onskemal|vad är du intresserad av|vad ar du intresserad av|produkt|product|tjänst|tjanst|kategori|välj vad du vill ha information och mailoffert på\??)\s*[:：]\s*([^\n]{1,80})/iu', $blob, $hit) === 1) {
        $val = trim((string) $hit[1]);
        $val = preg_replace('/\s+(?:namn|e-?post|telefon|adress|postnummer|ort|postort)\s*[:：].*$/iu', '', $val) ?? $val;
        $val = trim($val);
        if ($val !== '' && jeab_inkorg_product_token($val) !== '') {
            return $val;
        }
    }
    return '';
}

/** Formulärets produktval. Tomt när fältet saknas, så texten får vara reserv. */
function jeab_inkorg_form_product(array $msg): string
{
    $val = jeab_inkorg_form_product_raw($msg);
    return $val === '' ? '' : jeab_inkorg_product_token($val);
}

/** Formulärets offert. Sol+batteri är en egen produkt, med samma säljare som sol. */
function jeab_inkorg_form_is_sol_batteri(string $value): bool
{
    $v = mb_strtolower(trim($value));
    if ($v === '') {
        return false;
    }
    if (preg_match('/befintlig|redan|tillägg|tillagg|bara\s+batter|till\s+sol/u', $v) === 1) {
        return false;
    }
    if (preg_match('/solbatter/u', $v) === 1 && preg_match('/sol\s*\+|solcell/u', $v) !== 1) {
        return false;
    }
    $bat = preg_match('/batter/u', $v) === 1;
    $sol = preg_match('/solcell|solpanel|(?<![\p{L}])sol(?![\p{L}])/u', $v) === 1;
    return $bat && $sol;
}

function jeab_inkorg_form_quote_offer(array $msg): string
{
    $raw = jeab_inkorg_form_product_raw($msg);
    if ($raw !== '' && jeab_inkorg_form_is_sol_batteri($raw)) {
        return 'sol+batteri';
    }
    $token = jeab_inkorg_form_product($msg);
    // «batteri till solceller» har båda orden men är batteri till befintlig sol, inte sol+batteri.
    if ($raw !== '' && $token === 'sol' && preg_match('/befintlig|redan|tillägg|tillagg|bara\s+batter|till\s+sol/u', mb_strtolower($raw)) === 1) {
        return 'batteri';
    }
    return $token;
}

/**
 * Kundens val i formulärfältet, när det är en känd offertprodukt.
 * Tomt när fältet saknas, när en människa redan satt typen, eller när Grok är under gränsen.
 */
function jeab_inkorg_form_offer_sure(array $msg, array $labels = []): string
{
    if ($labels === []) {
        $labels = (array) ($msg['labels'] ?? []);
    }
    if ((string) ($labels['source'] ?? '') === 'manual') {
        return '';
    }
    $offer = jeab_inkorg_form_quote_offer($msg);
    if (!in_array($offer, ['laddbox', 'batteri', 'sol', 'sol+batteri'], true)) {
        return '';
    }
    $conf = (int) ($labels['confidence'] ?? 0);
    $floor = function_exists('jeab_inkorg_review_min_confidence') ? jeab_inkorg_review_min_confidence() : 70;
    if ((string) ($labels['source'] ?? '') === 'llm' && $conf < $floor) {
        return '';
    }

    return $offer;
}

function jeab_inkorg_purchase_labeled(array $msg, string $kind): string
{
    $label = $kind === 'email'
        ? '/^(?:e-?post(?:adress)?|e-?mail|mail)\s*[:：\-–]\s*(.*)$/iu'
        : '/^(?:kundens\s+)?(?:namn|name)\s*[:：\-–]\s*(.*)$/iu';
    $lines = preg_split("/\r\n|\n|\r/", jeab_inkorg_purchase_text($msg)) ?: [];
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        if (preg_match($label, trim((string) $lines[$i]), $m) !== 1) {
            continue;
        }
        $val = trim((string) $m[1]);
        if ($val === '') {
            for ($k = $i + 1; $k < $n; $k++) {
                $next = trim((string) $lines[$k]);
                if ($next === '') {
                    continue;
                }
                $val = $next;
                break;
            }
        }
        if ($val !== '') {
            return $val;
        }
    }
    return '';
}

function jeab_inkorg_purchase_header(array $msg, string $name): string
{
    $want = mb_strtolower($name);
    foreach ((array) ($msg['headers'] ?? []) as $key => $value) {
        if (mb_strtolower((string) $key) !== $want) {
            continue;
        }
        if (is_array($value)) {
            return trim(implode(' ', array_map('strval', $value)));
        }
        return trim((string) $value);
    }
    return '';
}

function jeab_inkorg_purchase_bulk(array $msg): bool
{
    if (jeab_inkorg_purchase_header($msg, 'List-Unsubscribe') !== '' || jeab_inkorg_purchase_header($msg, 'List-Unsubscribe-Post') !== '' || jeab_inkorg_purchase_header($msg, 'List-Id') !== '') {
        return true;
    }
    if (preg_match('/\b(?:bulk|list|junk)\b/iu', jeab_inkorg_purchase_header($msg, 'Precedence')) === 1) {
        return true;
    }
    $auto = jeab_inkorg_purchase_header($msg, 'Auto-Submitted');
    return $auto !== '' && preg_match('/^no$/iu', $auto) !== 1;
}

function jeab_inkorg_purchase_noreply(string $email): bool
{
    $local = mb_strtolower((string) strstr(trim($email), '@', true));
    if ($local === '') {
        return false;
    }
    return preg_match('/(?:^|[.+_-])(?:no-?reply|donotreply|do-not-reply|mailer-daemon|newsletter|nyhetsbrev)(?:[.+_-]|$)/u', $local) === 1
        || preg_match('/^notifications?(?:[.+_-]|$)/u', $local) === 1;
}

/** @param array<string,mixed> $thread */
function jeab_inkorg_purchase_started_by_us(array $msg, array $thread): bool
{
    $messages = (array) ($thread['messages'] ?? $msg['_messages'] ?? []);
    $firstFromUs = false;
    $saw = false;
    foreach ($messages as $message) {
        if (!is_array($message) || !empty($message['internal_note'])) {
            continue;
        }
        $saw = true;
        $from = (string) ($message['from'] ?? '');
        $source = (string) ($message['source'] ?? '');
        $firstFromUs = str_starts_with($source, 'outbound') || (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($from));
        break;
    }
    if ($firstFromUs) {
        return true;
    }
    $source = (string) ($msg['source'] ?? '');
    if (!$saw && str_starts_with($source, 'outbound')) {
        return true;
    }
    $earliest = null;
    $earliestAt = null;
    foreach ($messages as $message) {
        if (!is_array($message) || !empty($message['internal_note'])) {
            continue;
        }
        $at = strtotime((string) ($message['received_at'] ?? $message['date'] ?? '')) ?: 0;
        if ($earliest === null || ($at > 0 && ($earliestAt === null || $at < $earliestAt))) {
            $earliest = $message;
            $earliestAt = $at > 0 ? $at : $earliestAt;
        }
    }
    if (is_array($earliest)) {
        $from = (string) ($earliest['from'] ?? '');
        $source = (string) ($earliest['source'] ?? '');
        if (str_starts_with($source, 'outbound') || (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($from))) {
            return true;
        }
    }
    $subject = (string) ($msg['subject'] ?? '');
    $reply = preg_match('/^(?:sv|re|aw|fwd|vb)\s*:/iu', trim($subject)) === 1;
    $confirm = preg_match('/installation\s+av\s+(?:en\s+|ett\s+)?(?:laddbox|batteri|elbilsladdare|laddstolpe|solcell\p{L}*|solpanel\p{L}*|solanlägg\p{L}*|solanlagg\p{L}*)/iu', $subject) === 1;
    return $reply && $confirm;
}

function jeab_inkorg_purchase_support_words(string $text): bool
{
    return preg_match('/support\s+till\s+befintlig\s+kund|befintlig(?:a)?\s+(?:kund|installation|anläggning|anlaggning)|redan\s+install|min\s+anläggning|vår\s+anläggning|er\s+anläggning|\bfel(?:kod|et|et)?\b|felsök|felsok|servicejobb|serviceärende|servicearende|\bservice\b|garanti|\btrasig|\bfungerar\s+inte\b|appen|\bi\s+appen\b|överlåt|overlat|sålt\s+huset|salt\s+huset|ny\s+ägare|ny\s+agare|flytta\s+(?:över\s+|over\s+)?(?:easee|laddbox|laddaren)|överför\p{L}*\s+(?:easee|laddbox|laddaren)|overfor\p{L}*\s+(?:easee|laddbox|laddaren)|koppla\s+bort\s+från\s+mitt\s+konto|koppla\s+bort\s+fran\s+mitt\s+konto|easee[-\s]?konto|tillägg(?:et)?\s+på\s+laddbox|tillagg(?:et)?\s+pa\s+laddbox/iu', $text) === 1;
}

/**
 * E-post och installationsdatum ur payload, utan att läsa Fortnox-blobben.
 * false betyder att frågan inte gick; tom lista betyder att det inte finns ordrar.
 *
 * @return list<array<string, mixed>>|false
 */
function jeab_inkorg_purchase_kind_sql(PDO $pdo): array|false
{
    try {
        $mysql = function_exists('jeab_sql_purchases_is_mysql')
            ? jeab_sql_purchases_is_mysql($pdo)
            : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql) {
            $pick = static function (string $path): string {
                $ex = "JSON_EXTRACT(payload, '" . $path . "')";

                return 'CASE WHEN JSON_TYPE(' . $ex . ") = 'NULL' OR " . $ex . ' IS NULL THEN NULL ELSE JSON_UNQUOTE(' . $ex . ') END';
            };
            $sql = 'SELECT ' . $pick('$.email') . ' AS email, '
                . $pick('$.applicant.email') . ' AS applicant_email, '
                . $pick('$.install_date') . ' AS install_date, '
                . $pick('$.install_ymd') . ' AS install_ymd FROM purchases';
        } else {
            $sql = "SELECT json_extract(payload, '$.email') AS email, "
                . "json_extract(payload, '$.applicant.email') AS applicant_email, "
                . "json_extract(payload, '$.install_date') AS install_date, "
                . "json_extract(payload, '$.install_ymd') AS install_ymd FROM purchases";
        }
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return false;
        }
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $shaped = [];
            if (array_key_exists('email', $row) && $row['email'] !== null) {
                $shaped['email'] = $row['email'];
            }
            if (array_key_exists('applicant_email', $row) && $row['applicant_email'] !== null && $row['applicant_email'] !== '') {
                $shaped['applicant'] = ['email' => $row['applicant_email']];
            }
            foreach (['install_date', 'install_ymd'] as $key) {
                if (array_key_exists($key, $row) && $row[$key] !== null) {
                    $shaped[$key] = $row[$key];
                }
            }
            $out[] = $shaped;
        }

        return $out;
    } catch (Throwable $e) {
        unset($e);

        return false;
    }
}

/** @return list<array<string, mixed>> */
function jeab_inkorg_purchase_kind_rows(): array
{
    $cached = function_exists('jeab_purchase_cache_all') ? jeab_purchase_cache_all() : null;
    if (is_array($cached)) {
        return $cached;
    }
    $pdo = function_exists('jeab_purchase_sql_pdo') ? jeab_purchase_sql_pdo() : null;
    if ($pdo instanceof PDO) {
        $extracted = jeab_inkorg_purchase_kind_sql($pdo);
        if (is_array($extracted)) {
            return $extracted;
        }
    }
    if (!function_exists('jeab_purchase_all')) {
        return [];
    }
    try {
        $all = jeab_purchase_all();
    } catch (Throwable $e) {
        unset($e);

        return [];
    }

    return is_array($all) ? $all : [];
}

/** @return list<string> */
function jeab_inkorg_purchase_kind_reminder_emails(): array
{
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
    } catch (Throwable $e) {
        unset($e);
        $store = null;
    }
    if (!$store instanceof JeabStore) {
        return [];
    }
    if ($store instanceof JeabPdoStore) {
        try {
            $casePdo = $store->pdo();
            $caseEmail = jeab_copy_column_present($casePdo, 'reminders', 'email') ? "COALESCE(NULLIF(c.owner_email, ''), r.email)" : 'c.owner_email';
            $stmt = $casePdo->query('SELECT ' . $caseEmail . ' AS email FROM reminders r LEFT JOIN contacts c ON c.id = r.contact_id WHERE r.id > 0');
            $out = [];
            foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [] as $email) {
                if (trim((string) $email) === '') {
                    continue;
                }
                $out[] = (string) $email;
            }

            return $out;
        } catch (Throwable $e) {
            unset($e);
        }
    }
    if (!function_exists('jeab_reminders_every')) {
        return [];
    }
    $out = [];
    foreach (jeab_reminders_every($store) as $row) {
        if (is_array($row)) {
            $out[] = (string) ($row['email'] ?? '');
        }
    }

    return $out;
}

/** installation slår order. Påminnelse bara när adressen saknar order. */
function jeab_inkorg_purchase_kind_map(): array
{
    if (isset($GLOBALS['_JEAB_PURCHASE_KIND_MAP']) && is_array($GLOBALS['_JEAB_PURCHASE_KIND_MAP'])) {
        return $GLOBALS['_JEAB_PURCHASE_KIND_MAP'];
    }
    $GLOBALS['_JEAB_PURCHASE_KIND_BUILDS'] = (int) ($GLOBALS['_JEAB_PURCHASE_KIND_BUILDS'] ?? 0) + 1;
    $map = [];
    $purchases = jeab_inkorg_purchase_kind_rows();
    foreach ($purchases as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowEmail = mb_strtolower(trim((string) ($row['email'] ?? '')));
        if ($rowEmail === '' && is_array($row['applicant'] ?? null)) {
            $rowEmail = mb_strtolower(trim((string) ($row['applicant']['email'] ?? '')));
        }
        if ($rowEmail === '' || !str_contains($rowEmail, '@')) {
            continue;
        }
        if (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($rowEmail)) {
            continue;
        }
        $install = trim((string) ($row['install_date'] ?? $row['install_ymd'] ?? ''));
        if ($install !== '' && $install !== '0000-00-00') {
            $map[$rowEmail] = 'support_installation';
            continue;
        }
        if (($map[$rowEmail] ?? '') !== 'support_installation') {
            $map[$rowEmail] = 'support_order';
        }
    }
    foreach (jeab_inkorg_purchase_kind_reminder_emails() as $rawEmail) {
        $rowEmail = mb_strtolower(trim($rawEmail));
        if ($rowEmail === '' || isset($map[$rowEmail])) {
            continue;
        }
        if (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($rowEmail)) {
            continue;
        }
        $map[$rowEmail] = 'support_reminder';
    }
    $GLOBALS['_JEAB_PURCHASE_KIND_MAP'] = $map;

    return $map;
}

/** installation, order eller reminder. Tomt när adressen inte är en befintlig kund. */
function jeab_inkorg_purchase_customer_kind(string $email): string
{
    $email = mb_strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@') || (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($email))) {
        return '';
    }
    $map = jeab_inkorg_purchase_kind_map();

    return (string) ($map[$email] ?? '');
}

/**
 * Tom sträng när mejlet får bli köpintresse.
 * Annars orsaken: bulk, noreply, supplier, blocklist, staff, ours, autotest, formtest, support_*.
 *
 * @param array<string,mixed> $thread
 */
function jeab_inkorg_purchase_blocked(array $msg, array $thread = []): string
{
    $tid = (string) ($msg['thread_id'] ?? $thread['id'] ?? '');
    $mid = (string) ($msg['id'] ?? '');
    $source = (string) ($msg['source'] ?? '');
    if (str_starts_with($tid, 'autotest-') || str_starts_with($mid, 'autotest-') || str_contains($source, 'autotest')) {
        return 'autotest';
    }
    if (jeab_inkorg_purchase_bulk($msg)) {
        return 'bulk';
    }
    $from = trim((string) ($msg['from'] ?? ''));
    if (jeab_inkorg_purchase_noreply($from)) {
        return 'noreply';
    }
    if (jeab_inkorg_purchase_seller_pitch($msg)) {
        return 'supplier';
    }
    $domain = jeab_inkorg_purchase_domain($from);
    foreach (jeab_inkorg_purchase_supplier_brands() as $brand) {
        if (jeab_inkorg_purchase_domain_hit($domain, $brand)) {
            return 'supplier';
        }
    }
    if (function_exists('jeab_inkorg_form_is_existing_support') && jeab_inkorg_form_is_existing_support($msg)) {
        return 'support_form';
    }
    if (jeab_inkorg_purchase_support_words(jeab_inkorg_purchase_text($msg))) {
        return 'support_words';
    }
    if (jeab_inkorg_purchase_supplier_named($msg)) {
        return 'supplier';
    }
    $labeledEmail = jeab_inkorg_purchase_labeled($msg, 'email');
    $labeledName = jeab_inkorg_purchase_labeled($msg, 'name');
    $fromName = trim((string) ($msg['from_name'] ?? ''));
    $testEmail = jeab_inkorg_purchase_test_sender($from) || ($labeledEmail !== '' && jeab_inkorg_purchase_test_sender($labeledEmail));
    $testName = function_exists('jeab_auto_test_name')
        && (($labeledName !== '' && jeab_auto_test_name($labeledName)) || ($fromName !== '' && jeab_auto_test_name($fromName) && $testEmail));
    if ($testEmail || $testName) {
        return 'formtest';
    }
    foreach (jeab_inkorg_purchase_block_domains() as $blocked) {
        if (jeab_inkorg_purchase_domain_hit($domain, $blocked)) {
            if (jeab_inkorg_purchase_domain_hit($domain, 'huawei') || jeab_inkorg_purchase_domain_hit($domain, 'scanmont') || jeab_inkorg_purchase_domain_hit($domain, 'epax')) {
                return 'supplier';
            }
            $known = false;
            foreach (jeab_inkorg_purchase_supplier_brands() as $brand) {
                if (jeab_inkorg_purchase_domain_hit($domain, $brand) || jeab_inkorg_purchase_domain_hit($blocked, $brand)) {
                    $known = true;
                    break;
                }
            }
            return $known ? 'supplier' : 'blocklist';
        }
    }
    if (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($from)) {
        return 'staff';
    }
    if (jeab_inkorg_purchase_started_by_us($msg, $thread)) {
        return 'ours';
    }
    if (jeab_inkorg_purchase_outside($msg)) {
        return 'outside';
    }
    foreach ([$from, $labeledEmail] as $email) {
        $kind = jeab_inkorg_purchase_customer_kind($email);
        if ($kind !== '') {
            return $kind;
        }
    }
    return '';
}

/**
 * Första riktiga solordet. Sammansättningar som slutar på batteri eller lagring
 * (solcellsbatteri, solcellslagring) och «batteri till solceller» räknas inte.
 * sol+batteri räknas.
 */
function jeab_inkorg_purchase_solar_hit(string $text): string
{
    $text = mb_strtolower($text);
    $text = preg_replace(
        '/batter\p{L}*\s+till\s+(?:(?:mina|min|vår|var|våra|vara|befintlig(?:a)?|redan|de|dom)\s+){0,3}(?:solcell\p{L}*|solpanel\p{L}*|solanlägg\p{L}*|solanlagg\p{L}*|(?<![\p{L}])paneler(?![\p{L}]))/u',
        ' ',
        $text
    ) ?? $text;
    if (preg_match_all('/solcell\p{L}*|solpanel\p{L}*|(?<![\p{L}])paneler(?![\p{L}])|solanlägg\p{L}*|solanlagg\p{L}*|sol\s*\+\s*batter\p{L}*/u', $text, $all) < 1) {
        return '';
    }
    foreach ($all[0] as $hit) {
        $hit = (string) $hit;
        if (!str_contains($hit, '+') && preg_match('/(?:batter\p{L}*|lagring)$/u', $hit) === 1) {
            continue;
        }
        return $hit;
    }
    return '';
}

function jeab_inkorg_purchase_solar_words(string $text): bool
{
    return jeab_inkorg_purchase_solar_hit($text) !== '';
}

/** «Har redan solceller», «befintliga solceller» och «vi har sol» utan nya paneler. */
function jeab_inkorg_purchase_existing_solar_only(string $text): bool
{
    if (preg_match('/har\s+redan\s+sol|befintlig(?:a)?\s+sol|vi\s+har\s+sol/u', $text) !== 1) {
        return false;
    }
    $rest = preg_replace('/har\s+redan\s+sol\p{L}*|befintlig(?:a)?\s+sol\p{L}*|vi\s+har\s+sol\p{L}*/u', ' ', $text) ?? $text;
    return !jeab_inkorg_purchase_solar_words($rest);
}

/** Källnamn som inte i sig är en önskan om nya solceller. */
function jeab_inkorg_purchase_solar_content(string $text): string
{
    $text = mb_strtolower($text);
    return preg_replace('/hitta-?\s*solceller|hittasolceller|solcellsformulär|solcellsformular|hemsol/u', ' ', $text) ?? $text;
}

function jeab_inkorg_purchase_battery_words(string $text): bool
{
    return preg_match('/batter|lagring|\bkwh\b/u', $text) === 1;
}

/** HemSol, Hitta-Solceller eller solcellsformulär. Inte formulärets produktfält. */
function jeab_inkorg_purchase_solar_source(array $msg): bool
{
    $blob = mb_strtolower((string) ($msg['from'] ?? '') . ' ' . (string) ($msg['from_name'] ?? '') . "\n" . jeab_inkorg_purchase_text($msg));
    return preg_match('/hemsol|hitta-?solceller|hittasolceller|solcellsformulär|solcellsformular/u', $blob) === 1;
}

/** hemsol, hitta-solceller eller solcellsformulär. Tomt för vanlig kundpost. */
function jeab_inkorg_purchase_portal_kind(array $msg): string
{
    $from = mb_strtolower((string) ($msg['from'] ?? '') . ' ' . (string) ($msg['from_name'] ?? '') . ' ' . (string) ($msg['to'] ?? ''));
    if (preg_match('/hemsol/u', $from) === 1) {
        return 'hemsol';
    }
    if (preg_match('/hitta-?\s*solceller|hittasolceller/u', $from) === 1) {
        return 'hitta-solceller';
    }
    $text = mb_strtolower((string) ($msg['subject'] ?? '') . "\n" . (string) ($msg['body'] ?? '') . "\n" . (string) ($msg['body_text'] ?? ''));
    if (preg_match('/hemsol/u', $text) === 1) {
        return 'hemsol';
    }
    if (preg_match('/hitta-?\s*solceller|hittasolceller/u', $text) === 1) {
        return 'hitta-solceller';
    }
    if (preg_match('/solcellsformulär|solcellsformular/u', $text) === 1) {
        return 'solcellsformulär';
    }
    return '';
}

/** Rubrik, ämne, tagg och mailbox i HemSol- och Hitta-Solceller-mallen. Inte kundens mening. */
function jeab_inkorg_purchase_template_line(string $line): bool
{
    $t = trim(mb_strtolower($line));
    $t = trim($t, " \t[]");
    if ($t === '') {
        return false;
    }
    if (preg_match('/^(?:ämne|amne|rubrik|subject|mailbox|etiketter|tags|taggar)\s*[:：]/u', $t) === 1) {
        return true;
    }
    if (preg_match('/^[^\s@]+@(?:hemsol\.se|hitta-?solceller\.se|hittasolceller\.se)$/u', $t) === 1) {
        return true;
    }
    return preg_match('/^(?:nytt lead(?:\s*\([^)\n]{0,40}\))?|offert(?:förfrågan|forfragan)(?:\s+solceller)?|solceller|solpaneler)$/u', $t) === 1;
}

/**
 * Kundens egen text. För HemSol och Hitta-Solceller utelämnas ämne, rubrik, tagg och mailbox.
 * Sparad produkt och modellens product läses inte.
 */
/** Wix-rubriken och «submitted your form …» är källan, inte kundens produktval. */
function jeab_inkorg_purchase_banner_line(string $line): bool
{
    $t = trim(mb_strtolower($line));
    if ($t === '') {
        return false;
    }
    if (preg_match('/submitted your form/u', $t) === 1) {
        return true;
    }

    return preg_match('/^(?:jeab\s+)?(?:solceller|batteri|elbilsladdare|laddbox|ladbox|kontakt)\s+lead$/u', $t) === 1;
}

function jeab_inkorg_purchase_customer_product_text(array $msg): string
{
    $kind = jeab_inkorg_purchase_portal_kind($msg);
    $body = (string) ($msg['subject'] ?? '') . "\n" . (string) ($msg['body'] ?? '') . "\n" . (string) ($msg['body_text'] ?? '');
    if ($kind === 'hemsol' || $kind === 'hitta-solceller') {
        $body = (string) ($msg['body'] ?? '') . "\n" . (string) ($msg['body_text'] ?? '');
    }
    $keep = [];
    foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
        if (($kind === 'hemsol' || $kind === 'hitta-solceller') && jeab_inkorg_purchase_template_line((string) $line)) {
            continue;
        }
        if (jeab_inkorg_purchase_banner_line((string) $line)) {
            continue;
        }
        $keep[] = (string) $line;
    }
    return trim(implode("\n", $keep));
}

function jeab_inkorg_purchase_basis(string $kind, string $word): string
{
    $word = trim(preg_replace('/\s+/u', ' ', $word) ?? $word);
    $word = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '', $word) ?? $word;
    $word = preg_replace('/\+?\d[\d\s\-]{6,}\d/u', '', $word) ?? $word;
    $word = preg_replace('/\b\d{6,8}-?\d{4}\b/u', '', $word) ?? $word;
    $text = trim($kind . ' ' . trim($word));
    if (mb_strlen($text) > 40) {
        $text = rtrim(mb_substr($text, 0, 40));
    }
    return $text;
}

/**
 * Produkt och vilken regel som gav den. Sparad produkt, trådens products och modellen används inte.
 *
 * @return array{offer:string,basis:string}
 */
/** Wix-mejl. Ämnet och formulärnamnet är inte kundens produkt. */
function jeab_inkorg_purchase_wix_mail(array $msg): bool
{
    $from = mb_strtolower((string) ($msg['from'] ?? '') . ' ' . (string) ($msg['from_name'] ?? ''));
    if (str_contains($from, 'wix-forms') || str_contains($from, 'wixforms') || str_contains($from, '@wix.com')) {
        return true;
    }
    $body = mb_strtolower((string) ($msg['body'] ?? '') . "\n" . (string) ($msg['body_text'] ?? ''));

    return str_contains($body, 'submitted your form');
}

/**
 * Wix-inmatning utan ämne och utan formulärnamn.
 * Svaret är fälten efter «submitted your form».
 *
 * @param array<string,mixed> $msg
 * @return array<string,mixed>
 */
function jeab_inkorg_purchase_wix_input(array $msg): array
{
    if (!jeab_inkorg_purchase_wix_mail($msg)) {
        return $msg;
    }
    $msg['subject'] = '';
    $body = function_exists('jeab_inkorg_wix_body_text')
        ? jeab_inkorg_wix_body_text($msg)
        : trim((string) ($msg['body'] ?? '') . "\n" . (string) ($msg['body_text'] ?? ''));
    if (str_contains(mb_strtolower($body), 'submitted your form') && function_exists('jeab_inkorg_wix_answer_text')) {
        $msg['body'] = jeab_inkorg_wix_answer_text($msg);
        $msg['body_text'] = '';
    }

    return $msg;
}

function jeab_inkorg_purchase_offer_detail(array $msg): array
{
    $blank = ['offer' => '', 'basis' => ''];
    if (jeab_inkorg_purchase_wix_mail($msg)) {
        $msg = jeab_inkorg_purchase_wix_input($msg);
    }
    if (function_exists('jeab_lead_is_store_mail') && jeab_lead_is_store_mail($msg)) {
        return $blank;
    }
    if (jeab_inkorg_case_supplier_backorder($msg)) {
        return $blank;
    }
    $full = mb_strtolower(jeab_inkorg_purchase_text($msg));
    if (preg_match('/orderbekräft|orderbekraft|order confirmation|kvitto/u', $full) === 1) {
        return $blank;
    }
    $form = jeab_inkorg_form_product($msg);
    $formRaw = jeab_inkorg_form_product_raw($msg);
    $asks = preg_match('/offert|pris(?:uppgift|förslag|forslag)?|vad kostar|vill\s+(?:gärna\s+|garna\s+)?(?:köpa|kopa|beställa|bestalla|installera|ha)|installera|installation/u', $full) === 1;
    if (!$asks && $form === '') {
        return $blank;
    }
    $customer = mb_strtolower(jeab_inkorg_purchase_customer_product_text($msg));
    $content = jeab_inkorg_purchase_solar_content($customer);
    $existing = jeab_inkorg_purchase_existing_solar_only($customer);
    // Formulärets svar gäller före en sol-URL eller en alternativlista i samma mejl.
    if ($form !== '' && !($existing && $form === 'sol')) {
        return ['offer' => $form, 'basis' => jeab_inkorg_purchase_basis('formulär', $formRaw)];
    }
    $solar = jeab_inkorg_purchase_solar_hit($content);
    if (!$existing && $solar !== '') {
        return ['offer' => 'sol', 'basis' => jeab_inkorg_purchase_basis('solord', $solar)];
    }
    if (!$asks) {
        return $blank;
    }
    if (preg_match('/(?<![\p{L}])\p{L}*(?:batter|lagring)\p{L}*|\d+(?:[.,]\d+)?\s*kwh/u', $content, $battery) === 1) {
        return ['offer' => 'batteri', 'basis' => jeab_inkorg_purchase_basis('batteriord', (string) $battery[0])];
    }
    if (preg_match('/laddbox|elbilsladd\p{L}*|laddstolpe/u', $content, $charger) === 1) {
        return ['offer' => 'laddbox', 'basis' => jeab_inkorg_purchase_basis('ord', (string) $charger[0])];
    }
    return $blank;
}

/**
 * Sol bara när kundtext eller produktfält nämner nya solceller, paneler eller en solanläggning.
 * Sammansättningar som slutar på batteri eller lagring, och batteri till solceller, är batteri.
 * HemSol- och Hitta-Solceller-mallens ämne, rubrik, tagg och mailbox räknas inte.
 * Källan sätter ingen produkt. Saknas batteri och sol i kundtexten blir erbjudandet tomt.
 */
function jeab_inkorg_purchase_solar_primary(array $msg): bool
{
    return jeab_inkorg_purchase_offer_detail($msg)['offer'] === 'sol';
}

function jeab_inkorg_purchase_product_locked(array $msg): bool
{
    if (jeab_inkorg_purchase_blocked($msg) !== '') {
        return false;
    }
    if (jeab_inkorg_purchase_solar_primary($msg)) {
        return true;
    }
    $text = mb_strtolower(jeab_inkorg_purchase_text($msg));
    return preg_match('/batter/u', $text) === 1;
}

/**
 * Offert, pris, eller att någon vill köpa eller installera sol, batteri eller laddbox.
 * Formulärets produkt gäller före texten. Tom sträng när mejlet är undantaget.
 *
 * @param array<string,mixed> $thread
 */
/** Produkt utan undantagsspärr. Omklassningen läser den här, inte sparad produkt eller modellens product. */
function jeab_inkorg_purchase_offer_product(array $msg): string
{
    return jeab_inkorg_purchase_offer_detail($msg)['offer'];
}

function jeab_inkorg_purchase_offer(array $msg, array $thread = []): string
{
    if (jeab_inkorg_purchase_blocked($msg, $thread) !== '') {
        return '';
    }
    return jeab_inkorg_purchase_offer_product($msg);
}

/**
 * Help Scout-konversation som samma köp- och undantagsregel som ett inkorgsmejl.
 *
 * @param array<string,mixed> $conversation
 * @return array<string,mixed>
 */
function jeab_inkorg_purchase_msg_from_hs(array $conversation): array
{
    $threads = [];
    if (isset($conversation['_threads']) && is_array($conversation['_threads'])) {
        $threads = array_values(array_filter($conversation['_threads'], 'is_array'));
    }
    $body = '';
    $messages = [];
    foreach ($threads as $thread) {
        if (!is_array($thread)) {
            continue;
        }
        $plain = trim(strip_tags((string) ($thread['body'] ?? '')));
        $type = (string) ($thread['type'] ?? '');
        $email = trim((string) ($thread['createdBy']['email'] ?? $thread['customer']['email'] ?? ''));
        $from = $email;
        $source = 'helpscout';
        if ($type !== '' && $type !== 'customer') {
            $source = 'outbound';
            if ($from === '') {
                $from = 'kontakt@malmo.example.test';
            }
        }
        $messages[] = [
            'from' => $from,
            'subject' => (string) ($conversation['subject'] ?? ''),
            'body' => $plain,
            'source' => $source,
        ];
        if (($type === '' || $type === 'customer') && $plain !== '') {
            $body .= ($body === '' ? '' : "\n") . $plain;
        }
    }
    if ($body === '') {
        $body = trim((string) ($conversation['subject'] ?? '') . "\n" . (string) ($conversation['preview'] ?? '') . "\n" . (string) ($conversation['jeab_text'] ?? ''));
    }
    $tags = [];
    foreach ((array) ($conversation['tags'] ?? []) as $tag) {
        $name = is_array($tag) ? (string) ($tag['tag'] ?? $tag['name'] ?? '') : (string) $tag;
        $name = trim($name);
        if ($name !== '') {
            $tags[] = $name;
        }
    }
    if ($tags !== []) {
        $body .= ($body === '' ? '' : "\n\n") . 'Etiketter: ' . implode(', ', $tags);
    }
    $from = '';
    if (function_exists('jeab_malmo_hs_customer_email')) {
        $from = jeab_malmo_hs_customer_email($conversation);
    }
    if ($from === '' && function_exists('jeab_hs_import_customer_hint')) {
        $primary = (array) ($conversation['primaryCustomer'] ?? []);
        $from = (string) (jeab_hs_import_customer_hint($primary, $primary)['email'] ?? '');
    }
    return [
        'from' => $from,
        'from_name' => '',
        'subject' => (string) ($conversation['subject'] ?? ''),
        'body' => mb_substr($body, 0, 4000),
        'headers' => is_array($conversation['headers'] ?? null) ? $conversation['headers'] : [],
        'thread_id' => (string) ($conversation['id'] ?? ''),
        '_messages' => $messages,
    ];
}

/**
 * Fyller offert och produkt från köporden. Typetiketten rörs inte.
 *
 * @param array<string,mixed> $labels
 * @param array<string,mixed> $msg
 * @param array<string,mixed> $thread
 * @return array<string,mixed>
 */
function jeab_inkorg_purchase_apply(array $labels, array $msg, array $thread = []): array
{
    $offer = jeab_inkorg_purchase_offer($msg, $thread);
    if ($offer === '') {
        return $labels;
    }
    $type = (string) ($labels['type'] ?? '');
    if (!in_array($type, ['Information', 'Oklassad', ''], true)) {
        return $labels;
    }
    if ((string) ($labels['offer'] ?? '') === '') {
        $labels['offer'] = $offer;
    }
    $product = ['sol' => 'solceller', 'sol+batteri' => 'sol+batteri', 'batteri' => 'batteri', 'laddbox' => 'elbilsladdare'][$offer] ?? '';
    $blob = mb_strtolower(jeab_inkorg_purchase_text($msg));
    $withBattery = $offer === 'sol' && preg_match('/batter|\bkwh\b/u', $blob) === 1;
    if ($withBattery) {
        $product = 'solceller, batteri';
    }
    if ((string) ($labels['product'] ?? '') === '' && $product !== '') {
        $labels['product'] = $product;
    }
    if (is_array($labels['case_class'] ?? null)) {
        $mapped = ['sol' => 'Sol', 'batteri' => 'Batteri', 'laddbox' => 'Elbilsladdare'][$offer] ?? '';
        if ($mapped !== '' && (string) ($labels['case_class']['primary_product'] ?? '') === '') {
            $second = array_map('strval', (array) ($labels['case_class']['secondary_products'] ?? []));
            if ($withBattery && !in_array('Batteri', $second, true)) {
                $second[] = 'Batteri';
            }
            $labels['case_class']['secondary_products'] = array_values(array_filter($second, static fn(string $name): bool => $name !== $mapped));
            $labels['case_class']['primary_product'] = $mapped;
        }
    }
    return $labels;
}

function jeab_inkorg_reclassify_wants_dry(array $in): bool
{
    if (!array_key_exists('dry', $in)) {
        return true;
    }
    $dry = $in['dry'];
    return !($dry === false || $dry === 0 || $dry === '0' || $dry === 0.0);
}

function jeab_inkorg_reclassify_days(array $in): int
{
    if (!array_key_exists('days', $in) || $in['days'] === '' || $in['days'] === null) {
        return 14;
    }
    $n = (int) $in['days'];
    if ($n < 1) {
        return 14;
    }
    return min(90, $n);
}

/**
 * Manuell offert på Information. Raden har tråd-id och etikett, ingen kundadress.
 */
function jeab_inkorg_misclass_log(string $threadId, string $label, string $path): void
{
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
    } catch (Throwable $e) {
        unset($e);
        return;
    }
    if (!$store instanceof JeabPdoStore) {
        return;
    }
    $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
    jeab_ensure_inbox_misclass($store->pdo(), $driver);
    $stmt = $store->pdo()->prepare('INSERT INTO inbox_misclass (created_at, thread_id, label, path) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        date('Y-m-d H:i:s'),
        mb_substr($threadId, 0, 64),
        mb_substr($label, 0, 64),
        mb_substr($path, 0, 32),
    ]);
}

/**
 * Rader till felloggen. Ingen e-postadress.
 *
 * @return list<array{time:string,surface:string,message:string,count:int}>
 */
function jeab_inkorg_misclass_public(int $limit = 50): array
{
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
    } catch (Throwable $e) {
        unset($e);
        return [];
    }
    if (!$store instanceof JeabPdoStore) {
        return [];
    }
    $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
    jeab_ensure_inbox_misclass($store->pdo(), $driver);
    $limit = max(1, min(200, $limit));
    $stmt = $store->pdo()->query('SELECT created_at, thread_id, label, path FROM inbox_misclass ORDER BY id DESC LIMIT ' . $limit);
    $rows = [];
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $label = (string) ($row['label'] ?? '');
        $path = (string) ($row['path'] ?? '');
        $tid = (string) ($row['thread_id'] ?? '');
        $rows[] = [
            'time' => (string) ($row['created_at'] ?? ''),
            'surface' => 'felklassning',
            'message' => 'Manuell offert på etiketten ' . ($label !== '' ? $label : 'Information') . ($path !== '' ? ' (' . $path . ')' : '') . ($tid !== '' ? ', ärende ' . $tid : ''),
            'count' => 1,
        ];
    }
    return $rows;
}

function jeab_inkorg_reclassify_audit_write(string $threadId, string $messageId, string $old, string $offer, string $new = 'Ny lead', int $dry = 0): bool
{
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
    } catch (Throwable $e) {
        unset($e);
        return false;
    }
    if (!$store instanceof JeabPdoStore) {
        return false;
    }
    $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
    jeab_ensure_inbox_reclassify_audit($store->pdo(), $driver);
    $stmt = $store->pdo()->prepare(
        'INSERT INTO inbox_reclassify_audit (created_at, thread_id, message_id, old_type, new_type, offer, dry) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([date('Y-m-d H:i:s'), mb_substr($threadId, 0, 64), mb_substr($messageId, 0, 64), mb_substr($old, 0, 64), mb_substr($new, 0, 64), mb_substr($offer, 0, 32), $dry === 1 ? 1 : 0]);
    return true;
}

/**
 * inbox.reclassify_leads. days=14 och dry=1 är standard.
 * dry=0 byter bara typen till Ny lead och skriver en rad i SQL. Inget skickas.
 *
 * @param array<string,mixed> $in
 * @return array<string,mixed>
 */
function jeab_inkorg_reclassify_leads(array $in): array
{
    $dry = jeab_inkorg_reclassify_wants_dry($in);
    $days = jeab_inkorg_reclassify_days($in);
    jeab_inkorg_reclassify_lock(true);
    try {
        return jeab_inkorg_reclassify_leads_run($dry, $days);
    } finally {
        jeab_inkorg_reclassify_lock(false);
    }
}

/**
 * @return array<string,mixed>
 */
function jeab_inkorg_reclassify_leads_run(bool $dry, int $days): array
{
    $cut = time() - $days * 86400;
    $rows = [];
    $seen = [];
    $consider = static function (string $tid, string $mid, string $old, string $offer, string $at, string $reason = '', string $basis = '') use (&$rows, &$seen, $cut): void {
        if ($tid === '' || isset($seen[$tid])) {
            return;
        }
        if ($offer === '' && $reason === '') {
            return;
        }
        if (!in_array($old, ['Information', 'Oklassad', ''], true)) {
            return;
        }
        $ts = strtotime($at);
        if ($ts === false || $ts < $cut) {
            return;
        }
        $seen[$tid] = true;
        $outside = $reason === 'utanför området';
        $rows[] = [
            'thread_id' => $tid,
            'message_id' => $mid,
            'old_type' => $old === '' ? 'Oklassad' : $old,
            'new_type' => $outside ? 'Information' : 'Ny lead',
            'offer' => $outside ? '' : $offer,
            'reason' => $reason,
            'offer_basis' => $basis,
            'day' => date('Y-m-d', $ts),
            'would_quote' => false,
            'would_send' => false,
            'would_remind' => false,
        ];
    };
    $classify = static function (array $message, array $thread) use ($consider): void {
        if (jeab_inkorg_offer_manual_thread($thread, $message)) {
            return;
        }
        $detail = jeab_inkorg_purchase_offer_detail($message);
        $product = (string) $detail['offer'];
        $basis = (string) $detail['basis'];
        $block = jeab_inkorg_purchase_blocked($message, $thread);
        $labels = (array) ($message['labels'] ?? []);
        $old = (string) ($labels['type'] ?? '');
        $at = (string) ($message['received_at'] ?? $message['date'] ?? '');
        $tid = (string) ($thread['id'] ?? $message['thread_id'] ?? '');
        $mid = (string) ($message['id'] ?? '');
        if ($block === 'outside' && $product !== '') {
            $consider($tid, $mid, $old, $product, $at, 'utanför området', $basis);
            return;
        }
        if ($block !== '') {
            return;
        }
        $consider($tid, $mid, $old, $product, $at, '', $basis);
    };
    if (function_exists('jeab_inkorg_threads')) {
        foreach (jeab_inkorg_threads() as $thread) {
            if (!is_array($thread)) {
                continue;
            }
            foreach ((array) ($thread['messages'] ?? []) as $message) {
                if (!is_array($message) || !empty($message['internal_note'])) {
                    continue;
                }
                $classify($message, $thread);
            }
        }
    }
    if (function_exists('jeab_hs_import_index') && function_exists('jeab_hs_import_read_conv')) {
        foreach ((array) (jeab_hs_import_index()['by_id'] ?? []) as $tid => $row) {
            if (!is_array($row)) {
                continue;
            }
            $at = (string) ($row['updated_at'] ?? '');
            $ts = strtotime($at);
            if ($ts === false || $ts < $cut) {
                continue;
            }
            $conv = jeab_hs_import_read_conv((string) $tid);
            if (!is_array($conv)) {
                continue;
            }
            $thread = ['id' => (string) $tid, 'messages' => (array) ($conv['messages'] ?? [])];
            foreach ($thread['messages'] as $message) {
                if (!is_array($message) || !empty($message['internal_note'])) {
                    continue;
                }
                if ((string) ($message['received_at'] ?? '') === '') {
                    $message['received_at'] = $at;
                }
                $classify($message, $thread);
            }
        }
    }
    usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['day'], (string) $a['day']) ?: strcmp((string) $a['thread_id'], (string) $b['thread_id']));
    foreach ($rows as $i => $row) {
        $rows[$i]['would_quote'] = false;
        $rows[$i]['would_send'] = false;
        $rows[$i]['would_remind'] = false;
        $quoted = false;
        $tid = (string) ($row['thread_id'] ?? '');
        if ($tid !== '' && function_exists('jeab_auto_thread_by_id') && function_exists('jeab_inkorg_lead_backfill_quoted')) {
            $known = jeab_auto_thread_by_id($tid);
            $quoted = is_array($known) && jeab_inkorg_lead_backfill_quoted($known);
        }
        $rows[$i]['quote_already'] = $quoted;
    }
    $audited = 0;
    if (!$dry) {
        foreach ($rows as $row) {
            $mid = (string) $row['message_id'];
            $offer = (string) $row['offer'];
            $reason = (string) ($row['reason'] ?? '');
            $saved = $mid !== '' && function_exists('jeab_inkorg_get') ? jeab_inkorg_get($mid) : null;
            if (jeab_inkorg_offer_manual_thread(['id' => (string) $row['thread_id']], is_array($saved) ? $saved : [])) {
                continue;
            }
            $row['would_quote'] = false;
            $row['would_send'] = false;
            $row['would_remind'] = false;
            if ($reason === 'utanför området') {
                if ($mid !== '' && function_exists('jeab_inkorg_update')) {
                    jeab_inkorg_update($mid, static function (array $message): array {
                        $labels = (array) ($message['labels'] ?? []);
                        $labels['reason'] = 'utanför området';
                        $labels['label'] = 'Utanför området';
                        if (!in_array((string) ($labels['type'] ?? ''), ['Support', 'Order', 'Skräp'], true)) {
                            $labels['type'] = 'Information';
                        }
                        $message['labels'] = $labels;
                        return $message;
                    });
                }
                continue;
            }
            if ($mid !== '' && function_exists('jeab_inkorg_update') && $offer !== '') {
                jeab_inkorg_update($mid, static function (array $message) use ($offer): array {
                    $message['labels'] = jeab_inkorg_purchase_apply((array) ($message['labels'] ?? []), $message + ['body' => (string) ($message['body'] ?? '')]);
                    $message['labels']['offer'] = $offer;
                    $message['labels']['type'] = 'Ny lead';
                    $message['labels']['intent'] = 'kop';
                    return $message;
                });
            }
            if ($offer !== '' && jeab_inkorg_reclassify_audit_write((string) $row['thread_id'], $mid, (string) $row['old_type'], $offer)) {
                $audited++;
            }
        }
    }
    return [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'days' => $days,
        'count' => count($rows),
        'audited' => $audited,
        'rows' => $rows,
    ];
}

function jeab_inkorg_offer_manual_labels(array $labels): bool
{
    return !empty($labels['offer_manual']);
}

function jeab_inkorg_thread_override_forget(): void
{
    unset($GLOBALS['_JEAB_THREAD_OVERRIDE'], $GLOBALS['_JEAB_THREAD_OVERRIDE_ONE']);
}

/**
 * @return array<string,array<string,mixed>>
 */
function jeab_inkorg_thread_overrides(): array
{
    if (isset($GLOBALS['_JEAB_THREAD_OVERRIDE']) && is_array($GLOBALS['_JEAB_THREAD_OVERRIDE'])) {
        return $GLOBALS['_JEAB_THREAD_OVERRIDE'];
    }
    $rows = [];
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
    } catch (Throwable $e) {
        unset($e);
        $store = null;
    }
    if ($store instanceof JeabPdoStore) {
        $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
        jeab_ensure_inbox_thread_override($store->pdo(), $driver);
        $stmt = $store->pdo()->query('SELECT thread_id, offer, primary_product, assignee, type, reason, manual, updated_at, ' . ($driver === 'mysql' ? '`by`' : '"by"') . ' AS actor FROM inbox_thread_override');
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['thread_id'] ?? '');
            if ($id !== '') {
                $rows[$id] = $row;
            }
        }
    }
    $GLOBALS['_JEAB_THREAD_OVERRIDE'] = $rows;
    return $rows;
}

/** @return array<string,mixed>|null */
function jeab_inkorg_thread_override_get(string $tid): ?array
{
    if ($tid === '') {
        return null;
    }
    if (isset($GLOBALS['_JEAB_THREAD_OVERRIDE']) && is_array($GLOBALS['_JEAB_THREAD_OVERRIDE'])) {
        $row = $GLOBALS['_JEAB_THREAD_OVERRIDE'][$tid] ?? null;

        return is_array($row) ? $row : null;
    }
    $one = $GLOBALS['_JEAB_THREAD_OVERRIDE_ONE'] ?? null;
    if (is_array($one) && array_key_exists($tid, $one)) {
        $row = $one[$tid];

        return is_array($row) ? $row : null;
    }
    $row = jeab_inkorg_thread_override_fetch($tid);
    if (!is_array($one)) {
        $one = [];
    }
    $one[$tid] = $row;
    $GLOBALS['_JEAB_THREAD_OVERRIDE_ONE'] = $one;

    return $row;
}

/** @return array<string,mixed>|null */
function jeab_inkorg_thread_override_fetch(string $tid): ?array
{
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
    } catch (Throwable $e) {
        unset($e);
        $store = null;
    }
    if (!$store instanceof JeabPdoStore) {
        return null;
    }
    $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
    jeab_ensure_inbox_thread_override($store->pdo(), $driver);
    $by = $driver === 'mysql' ? '`by`' : '"by"';
    $stmt = $store->pdo()->prepare(
        'SELECT thread_id, offer, primary_product, assignee, type, reason, manual, updated_at, ' . $by . ' AS actor FROM inbox_thread_override WHERE thread_id = ?'
    );
    $stmt->execute([mb_substr($tid, 0, 64)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @param array{offer:string,product:string,primary:string} $mapped
 */
function jeab_inkorg_thread_override_save(string $tid, array $mapped, string $type, string $reason, string $assignee): bool
{
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
    } catch (Throwable $e) {
        unset($e);
        return false;
    }
    if (!$store instanceof JeabPdoStore || $tid === '') {
        return false;
    }
    $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
    jeab_ensure_inbox_thread_override($store->pdo(), $driver);
    $by = $driver === 'mysql' ? '`by`' : '"by"';
    $sql = 'INSERT INTO inbox_thread_override (thread_id, offer, primary_product, assignee, type, reason, manual, updated_at, ' . $by . ') VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)';
    if ($driver === 'mysql') {
        $sql .= ' ON DUPLICATE KEY UPDATE offer = VALUES(offer), primary_product = VALUES(primary_product), assignee = VALUES(assignee), type = VALUES(type), reason = VALUES(reason), manual = 1, updated_at = VALUES(updated_at), `by` = VALUES(`by`)';
    } else {
        $sql .= ' ON CONFLICT(thread_id) DO UPDATE SET offer = excluded.offer, primary_product = excluded.primary_product, assignee = excluded.assignee, type = excluded.type, reason = excluded.reason, manual = 1, updated_at = excluded.updated_at, "by" = excluded."by"';
    }
    $stmt = $store->pdo()->prepare($sql);
    $stmt->execute([
        mb_substr($tid, 0, 64),
        mb_substr((string) $mapped['offer'], 0, 32),
        mb_substr((string) $mapped['primary'], 0, 64),
        mb_substr($assignee, 0, 64),
        mb_substr($type, 0, 64),
        mb_substr($reason, 0, 191),
        date('Y-m-d H:i:s'),
        'inbox.set_offer',
    ]);
    jeab_inkorg_thread_override_forget();
    unset($GLOBALS['_JEAB_INKORG_THREADS'], $GLOBALS['_JEAB_INKORG_THREADS_FP']);
    return true;
}

/**
 * Lägger SQL-overriden ovanpå en tråd i minnet. Skriver ingen fil.
 *
 * @param array<string,mixed> $thread
 * @return array<string,mixed>
 */
function jeab_inkorg_thread_apply_override(array $thread): array
{
    $tid = (string) ($thread['id'] ?? '');
    $row = jeab_inkorg_thread_override_get($tid);
    if ($row === null || (int) ($row['manual'] ?? 0) !== 1) {
        return $thread;
    }
    $offer = (string) ($row['offer'] ?? '');
    $product = ['sol' => 'solceller', 'sol+batteri' => 'sol+batteri', 'batteri' => 'batteri', 'laddbox' => 'elbilsladdare'][$offer] ?? '';
    $labels = is_array($thread['labels'] ?? null) ? $thread['labels'] : [];
    if ((string) ($row['type'] ?? '') !== '') {
        $labels['type'] = (string) $row['type'];
    }
    if ($offer !== '') {
        $labels['offer'] = $offer;
    }
    if ($product !== '') {
        $labels['product'] = $product;
    }
    if ((string) ($row['reason'] ?? '') !== '') {
        $labels['reason'] = (string) $row['reason'];
    }
    $labels['intent'] = 'kop';
    $labels['offer_manual'] = 1;
    $case = is_array($labels['case_class'] ?? null) ? $labels['case_class'] : [];
    $case['primary_product'] = (string) ($row['primary_product'] ?? '');
    $case['type'] = (string) ($row['type'] ?? $case['type'] ?? '');
    $labels['case_class'] = $case;
    $thread['labels'] = $labels;
    if ((string) ($row['assignee'] ?? '') !== '') {
        $thread['assignee'] = (string) $row['assignee'];
    }
    $thread['offer_override'] = 1;
    $thread['case_class'] = $case;
    return $thread;
}

/** Manuell produkt från inbox.set_offer. Omklassningen lämnar tråden. */
function jeab_inkorg_offer_manual_thread(array $thread, array $message = []): bool
{
    if (jeab_inkorg_offer_manual_labels((array) ($message['labels'] ?? []))) {
        return true;
    }
    foreach ((array) ($thread['messages'] ?? []) as $one) {
        if (is_array($one) && jeab_inkorg_offer_manual_labels((array) ($one['labels'] ?? []))) {
            return true;
        }
    }
    $tid = (string) ($thread['id'] ?? $message['thread_id'] ?? '');
    if ($tid !== '' && jeab_inkorg_thread_override_get($tid) !== null) {
        return true;
    }
    if ($tid !== '' && function_exists('jeab_inkorg_thread_meta')) {
        $meta = jeab_inkorg_thread_meta($tid);
        if (!empty($meta['offer_manual'])) {
            return true;
        }
    }
    if ($tid !== '' && function_exists('jeab_hs_import_read_conv')) {
        $conv = jeab_hs_import_read_conv($tid);
        $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [];
        if (!empty($meta['offer_manual'])) {
            return true;
        }
    }
    return false;
}

/**
 * Inkorgen, jeab_inkorg_threads och HS-importarkivet. Samma arkiv som omklassningen.
 *
 * @return array{id:string,messages:list<array<string,mixed>>,assignee:string,brand:string}
 */
function jeab_inkorg_set_offer_find(string $tid): array
{
    $blank = ['id' => $tid, 'messages' => [], 'assignee' => '', 'brand' => ''];
    $messages = [];
    if (function_exists('jeab_inkorg_messages')) {
        foreach (jeab_inkorg_messages() as $message) {
            if (!is_array($message)) {
                continue;
            }
            if ((string) ($message['thread_id'] ?? $message['id'] ?? '') === $tid) {
                $messages[] = $message;
            }
        }
    }
    if ($messages === [] && function_exists('jeab_inkorg_threads')) {
        foreach (jeab_inkorg_threads() as $thread) {
            if (!is_array($thread) || (string) ($thread['id'] ?? '') !== $tid) {
                continue;
            }
            foreach ((array) ($thread['messages'] ?? []) as $message) {
                if (is_array($message)) {
                    $messages[] = $message;
                }
            }
            if ($messages !== []) {
                return [
                    'id' => $tid,
                    'messages' => $messages,
                    'assignee' => (string) ($thread['assignee'] ?? ''),
                    'brand' => (string) ($thread['brand'] ?? $thread['company'] ?? ''),
                ];
            }
            break;
        }
    }
    if ($messages !== []) {
        $assignee = function_exists('jeab_inkorg_thread_meta') ? (string) (jeab_inkorg_thread_meta($tid)['assignee'] ?? '') : '';
        $brand = '';
        foreach ($messages as $message) {
            $company = (string) ($message['company'] ?? '');
            if ($company === 'malmo' || $company === 'jarfalla') {
                $brand = $company;
            }
        }
        return ['id' => $tid, 'messages' => $messages, 'assignee' => $assignee, 'brand' => $brand];
    }
    if (!function_exists('jeab_hs_import_index') || !function_exists('jeab_hs_import_read_conv')) {
        return $blank;
    }
    $index = jeab_hs_import_index();
    $archiveId = isset($index['by_id'][$tid]) ? $tid : (string) ($index['by_msg'][$tid] ?? '');
    if ($archiveId === '') {
        return $blank;
    }
    $conv = jeab_hs_import_read_conv($archiveId);
    if (!is_array($conv)) {
        return $blank;
    }
    foreach ((array) ($conv['messages'] ?? []) as $message) {
        if (is_array($message)) {
            $messages[] = $message;
        }
    }
    if ($messages === []) {
        return $blank;
    }
    $meta = is_array($conv['meta'] ?? null) ? $conv['meta'] : [];
    $row = is_array($index['by_id'][$archiveId] ?? null) ? $index['by_id'][$archiveId] : [];
    $brand = (string) ($conv['company'] ?? '');
    if ($brand !== 'malmo' && $brand !== 'jarfalla') {
        $brand = (string) ($row['company'] ?? '');
    }
    $assignee = (string) ($meta['assignee'] ?? $row['assignee'] ?? '');
    return ['id' => $archiveId, 'messages' => $messages, 'assignee' => $assignee, 'brand' => $brand];
}

/**
 * @return array{offer:string,product:string,primary:string}
 */
function jeab_inkorg_set_offer_map(string $offer): array
{
    $offer = mb_strtolower(trim($offer));
    if ($offer === 'sol') {
        return ['offer' => 'sol', 'product' => 'solceller', 'primary' => 'Sol'];
    }
    $compact = str_replace([' ', '_', '-'], '', $offer);
    if (in_array($compact, ['sol+batteri', 'solbatteri', 'solochbatteri'], true)) {
        return ['offer' => 'sol+batteri', 'product' => 'sol+batteri', 'primary' => 'Sol'];
    }
    if ($offer === 'batteri') {
        return ['offer' => 'batteri', 'product' => 'batteri', 'primary' => 'Batteri'];
    }
    if ($offer === 'elbilsladdare') {
        return ['offer' => 'laddbox', 'product' => 'elbilsladdare', 'primary' => 'Elbilsladdare'];
    }
    return ['offer' => '', 'product' => '', 'primary' => ''];
}

function jeab_inkorg_set_offer_reason(string $reason): string
{
    $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? $reason);
    if (function_exists('jeab_inkorg_case_mask_text')) {
        $reason = jeab_inkorg_case_mask_text($reason);
    }
    if (mb_strlen($reason) > 80) {
        $reason = rtrim(mb_substr($reason, 0, 80));
    }
    return $reason;
}

/**
 * @param array<string,mixed> $labels
 * @return array<string,mixed>
 */
function jeab_inkorg_set_offer_labels(array $labels, array $mapped, string $reason): array
{
    $offer = (string) $mapped['offer'];
    $primary = (string) $mapped['primary'];
    $labels['type'] = 'Ny lead';
    $labels['action'] = function_exists('jeab_inkorg_derived_action') ? jeab_inkorg_derived_action('Ny lead') : 'svara';
    $labels['priority'] = function_exists('jeab_inkorg_default_priority') ? jeab_inkorg_default_priority('Ny lead') : 1;
    $labels['intent'] = 'kop';
    $labels['offer'] = $offer;
    $labels['product'] = (string) $mapped['product'];
    $labels['reason'] = $reason;
    $labels['offer_manual'] = 1;
    $case = is_array($labels['case_class'] ?? null) ? $labels['case_class'] : [];
    $case['type'] = 'Ny lead';
    $case['kind'] = 'atgard';
    $case['intent'] = 'kopintresse';
    $second = array_map('strval', (array) ($case['secondary_products'] ?? []));
    $prev = (string) ($case['primary_product'] ?? '');
    if ($prev !== '' && $prev !== $primary && !in_array($prev, $second, true)) {
        array_unshift($second, $prev);
    }
    $case['secondary_products'] = array_values(array_filter($second, static fn (string $name): bool => $name !== $primary));
    $case['primary_product'] = $primary;
    $confidence = is_array($case['confidence'] ?? null) ? $case['confidence'] : [];
    $confidence['primary_product'] = 1.0;
    $case['confidence'] = $confidence;
    $labels['case_class'] = $case;
    return $labels;
}

/**
 * @param array<string,mixed> $message
 * @param array<string,mixed> $thread
 * @return array<string,mixed>
 */
function jeab_inkorg_set_offer_view(array $message, array $thread): array
{
    $labels = (array) ($message['labels'] ?? []);
    $case = is_array($labels['case_class'] ?? null) ? $labels['case_class'] : [];
    return [
        'offer' => (string) ($labels['offer'] ?? ''),
        'product' => (string) ($labels['product'] ?? ''),
        'primary_product' => (string) ($case['primary_product'] ?? ''),
        'type' => (string) ($labels['type'] ?? ''),
        'intent' => (string) ($labels['intent'] ?? ''),
        'reason' => (string) ($labels['reason'] ?? ''),
        'assignee' => (string) ($thread['assignee'] ?? ''),
        'offer_manual' => jeab_inkorg_offer_manual_labels($labels),
    ];
}

/**
 * @param list<array<string,mixed>> $messages
 */
function jeab_inkorg_set_offer_assignee(string $company, string $offer): string
{
    if ($offer === 'sol+batteri' && $company !== 'malmo') {
        return 'SamL';
    }
    if ($company !== 'malmo' || !in_array($offer, ['batteri', 'laddbox'], true)) {
        return '';
    }
    if (!function_exists('jeab_inkorg_support_owner_id')) {
        return '';
    }
    $pid = jeab_inkorg_support_owner_id($offer, 'malmo');
    if ($pid === '' || !function_exists('jeab_inkorg_staff') || !isset(jeab_inkorg_staff()[$pid])) {
        return '';
    }
    return $pid;
}

/**
 * inbox.set_offer. dry=1 är standard. Skriver samma fält som omklassningens dry=0
 * och en manuell markering. Skickar inget.
 *
 * @param array<string,mixed> $in
 * @return array<string,mixed>
 */
function jeab_inkorg_set_offer(array $in): array
{
    jeab_inkorg_reclassify_lock(true);
    try {
        return jeab_inkorg_set_offer_run($in);
    } finally {
        jeab_inkorg_reclassify_lock(false);
    }
}

/**
 * @param array<string,mixed> $in
 * @return array<string,mixed>
 */
function jeab_inkorg_set_offer_run(array $in): array
{
    $flags = ['would_quote' => false, 'would_send' => false, 'would_remind' => false];
    $tid = trim((string) ($in['thread_id'] ?? ''));
    if ($tid === '') {
        return ['ok' => false, 'error' => 'thread_id saknas'] + $flags;
    }
    $mapped = jeab_inkorg_set_offer_map((string) ($in['offer'] ?? ''));
    if ($mapped['offer'] === '') {
        return ['ok' => false, 'error' => 'offer ska vara sol, sol+batteri, batteri eller elbilsladdare'] + $flags;
    }
    $reason = jeab_inkorg_set_offer_reason((string) ($in['reason'] ?? ''));
    if ($reason === '') {
        return ['ok' => false, 'error' => 'reason saknas'] + $flags;
    }
    $dry = jeab_inkorg_reclassify_wants_dry($in);
    $found = jeab_inkorg_set_offer_find($tid);
    $tid = (string) $found['id'];
    $messages = $found['messages'];
    if ($messages === []) {
        return ['ok' => false, 'error' => 'ärendet finns inte', 'dry' => $dry ? 1 : 0] + $flags;
    }
    $latest = $messages[array_key_last($messages)];
    foreach ($messages as $message) {
        if (empty($message['internal_note'])) {
            $latest = $message;
        }
    }
    $thread = ['id' => $tid, 'messages' => $messages, 'assignee' => (string) $found['assignee'], 'brand' => (string) $found['brand']];
    $before = jeab_inkorg_set_offer_view($latest, $thread);
    $company = function_exists('jeab_inkorg_thread_company') ? jeab_inkorg_thread_company($thread) : '';
    $assignee = jeab_inkorg_set_offer_assignee($company, (string) $mapped['offer']);
    $afterLabels = jeab_inkorg_set_offer_labels((array) ($latest['labels'] ?? []), $mapped, $reason);
    $afterThread = $thread;
    if ($assignee !== '') {
        $afterThread['assignee'] = $assignee;
    }
    $after = jeab_inkorg_set_offer_view(['labels' => $afterLabels], $afterThread);
    $out = [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'thread_id' => $tid,
        'offer' => (string) $mapped['offer'],
        'product' => (string) $mapped['product'],
        'primary_product' => (string) $mapped['primary'],
        'reason' => $reason,
        'before' => $before,
        'after' => $after,
        'audited' => 0,
    ] + $flags;
    if ($dry) {
        return $out;
    }
    $savedOverride = jeab_inkorg_thread_override_save($tid, $mapped, 'Ny lead', $reason, $assignee);
    $audited = jeab_inkorg_reclassify_audit_write($tid, (string) ($latest['id'] ?? ''), (string) $before['type'], (string) $mapped['offer']) ? 1 : 0;
    $out['audited'] = $audited;
    $row = jeab_inkorg_thread_override_get($tid);
    if ($savedOverride && is_array($row)) {
        $shown = jeab_inkorg_thread_apply_override($thread + ['labels' => (array) ($latest['labels'] ?? [])]);
        $out['after'] = jeab_inkorg_set_offer_view(['labels' => (array) ($shown['labels'] ?? [])], $shown);
    }
    return $out;
}

function jeab_inkorg_case_product_line(array $case): string
{
    $primary = (string) ($case['primary_product'] ?? '');
    if ($primary === '') {
        return '';
    }
    $second = [];
    foreach ((array) ($case['secondary_products'] ?? []) as $item) {
        $item = (string) $item;
        if ($item !== '' && $item !== $primary && !in_array($item, $second, true)) {
            $second[] = $item;
        }
    }
    if ($second === []) {
        return 'Produkt: ' . $primary;
    }
    return 'Produkt: ' . $primary . ' (även ' . implode(', ', $second) . ')';
}

/** @param array<string,mixed> $thread @param array<string,mixed> $labels */
function jeab_inkorg_case_product_line_for(array $thread, array $labels): string
{
    return jeab_inkorg_case_product_line(jeab_inkorg_case_of(['labels' => $labels], $thread));
}

function jeab_inkorg_case_supplier_sample(array $thread): bool
{
    $msg = (array) ($thread['first'] ?? []);
    $blob = mb_strtolower((string) ($msg['from'] ?? '') . "\n" . (string) ($msg['subject'] ?? ''));
    return preg_match('/orderbekräft|orderbekraft|order confirmation|aprilice|lightleap/u', $blob) === 1;
}

function jeab_inkorg_case_mixed_words(array $thread): bool
{
    $msg = (array) ($thread['first'] ?? []);
    $text = mb_strtolower((string) ($msg['subject'] ?? '') . "\n" . (string) ($msg['body'] ?? '') . "\n" . (string) ($msg['body_text'] ?? ''));
    $hits = 0;
    foreach (['solcell', 'batter', 'elbil', 'laddbox', 'laddare', 'servicejobb'] as $word) {
        if (preg_match('/' . $word . '/u', $text) === 1) {
            $hits++;
        }
    }
    return $hits >= 2;
}

/**
 * inbox.classify_eval. dry=1 är enda läget. Skriver inget.
 * limit är 8 om den saknas och högst 10. offset väljer sida i samma urval.
 *
 * @param array<string,mixed> $in
 * @return array<string,mixed>
 */
function jeab_inkorg_classify_eval(array $in): array
{
    if ((string) ($in['dry'] ?? '1') === '0') {
        return ['ok' => false, 'error' => 'classify_eval är bara läsning'];
    }
    $limit = 8;
    if (array_key_exists('limit', $in) && $in['limit'] !== '' && $in['limit'] !== null) {
        $limit = (int) $in['limit'];
        if ($limit < 1) {
            $limit = 8;
        }
        $limit = min(10, $limit);
    }
    $offset = max(0, (int) ($in['offset'] ?? 0));
    $quoted = [];
    $mixed = [];
    $supplier = [];
    foreach (jeab_inkorg_threads() as $thread) {
        if (!is_array($thread) || !empty($thread['deleted'])) {
            continue;
        }
        if (jeab_inkorg_quote_was_sent($thread)) {
            $quoted[] = $thread;
            continue;
        }
        if (jeab_inkorg_case_supplier_sample($thread)) {
            $supplier[] = $thread;
            continue;
        }
        if (jeab_inkorg_case_mixed_words($thread)) {
            $mixed[] = $thread;
        }
    }
    $mixed = array_slice($mixed, 0, 8);
    $supplier = array_slice($supplier, 0, 8);
    $sample = array_merge($quoted, $mixed, $supplier);
    $total = count($sample);
    $page = array_slice($sample, $offset, $limit);
    $next = $offset + count($page);
    $score = [];
    foreach (['product', 'intent', 'recipient', 'kind', 'type', 'label'] as $field) {
        $score[$field] = ['hit' => 0, 'n' => 0];
    }
    $rows = [];
    $mismatches = [];
    $confKey = ['product' => 'primary_product', 'intent' => 'intent', 'recipient' => 'customer_email', 'kind' => 'kind', 'type' => 'type', 'label' => 'label'];
    foreach ($page as $thread) {
        $msg = (array) ($thread['first'] ?? []);
        $pred = null;
        try {
            $pred = jeab_inkorg_llm_classify($msg);
        } catch (Throwable $e) {
            $pred = null;
        }
        $case = is_array($pred) ? (array) ($pred['case_class'] ?? []) : [];
        $row = jeab_inkorg_case_eval_row($thread, $case, $case === [] ? 'ingen klassning' : '');
        $rows[] = $row;
        foreach ($score as $field => $_) {
            if (!array_key_exists($field . '_match', $row)) {
                continue;
            }
            if ($field !== 'label' && (string) ($row[$field . '_stored'] ?? '') === '') {
                continue;
            }
            $score[$field]['n']++;
            if (!empty($row[$field . '_match'])) {
                $score[$field]['hit']++;
                continue;
            }
            $conf = $case['confidence'][$confKey[$field]] ?? null;
            $mismatches[] = [
                'thread' => jeab_inkorg_case_mask_text((string) ($thread['id'] ?? '')),
                'field' => $field,
                'old' => jeab_inkorg_case_mask_text((string) ($row[$field . '_stored'] ?? '')),
                'new' => jeab_inkorg_case_mask_text((string) ($row[$field . '_pred'] ?? '')),
                'confidence' => is_numeric($conf) ? round((float) $conf, 3) : null,
            ];
        }
    }
    $accuracy = [];
    foreach ($score as $field => $cell) {
        $accuracy[$field] = [
            'hit' => $cell['hit'],
            'n' => $cell['n'],
            'rate' => $cell['n'] > 0 ? round($cell['hit'] / $cell['n'], 3) : null,
        ];
    }
    return [
        'ok' => true,
        'dry' => 1,
        'accuracy' => $accuracy,
        'mismatches' => $mismatches,
        'quoted' => count($quoted),
        'mixed' => count($mixed),
        'supplier' => count($supplier),
        'total' => $total,
        'next_offset' => $next < $total ? $next : null,
        'rows' => $rows,
    ];
}

/**
 * @param array<string,mixed> $thread
 * @param array<string,mixed> $case
 * @return array<string,mixed>
 */
function jeab_inkorg_case_eval_row(array $thread, array $case, string $error = ''): array
{
    $msg = (array) ($thread['first'] ?? []);
    $labels = (array) ($msg['labels'] ?? []);
    $mark = is_array($thread['quote_sent'] ?? null) ? $thread['quote_sent'] : [];
    $storedProduct = jeab_inkorg_case_product_name((string) ($mark['product'] ?? ''));
    if ($storedProduct === '') {
        $storedProduct = jeab_inkorg_case_product_name((string) ($labels['offer'] ?? ''));
    }
    if ($storedProduct === '') {
        $storedProduct = jeab_inkorg_case_product_name((string) ($labels['product'] ?? ''));
    }
    $storedIntent = (string) ($labels['intent'] ?? '');
    if ($storedIntent === 'kop' || (string) ($labels['type'] ?? '') === 'Ny lead') {
        $storedIntent = 'kopintresse';
    } elseif ($storedIntent === 'support' || (string) ($labels['type'] ?? '') === 'Support') {
        $storedIntent = 'support';
    } elseif (function_exists('jeab_inkorg_form_is_existing_support') && jeab_inkorg_form_is_existing_support($msg)) {
        $storedIntent = 'support';
    } else {
        $storedIntent = '';
    }
    $storedType = (string) ($labels['type'] ?? '');
    $storedKind = in_array($storedType, ['Information', 'Skräp', 'Order'], true) ? 'information' : (in_array($storedType, ['Ny lead', 'Support', 'Bokning/ombokning', 'Kräver åtgärd från oss'], true) ? 'atgard' : '');
    $storedLabel = in_array('restnoterad', (array) ($thread['tags'] ?? []), true) ? 'Restnoterad/ej i lager' : '';
    $lead = is_array($thread['lead_customer'] ?? null) ? $thread['lead_customer'] : [];
    // Utvärderingen räknar bara lead_customer. customer_email är visningsadressen och finns på varje ärende.
    $storedEmail = mb_strtolower(trim((string) ($lead['email'] ?? '')));
    $predEmail = mb_strtolower(trim((string) ($case['customer_email'] ?? '')));
    $mask = static function (string $email): string {
        if ($email === '') {
            return '';
        }
        if (function_exists('jeab_lead_mask_email')) {
            return jeab_lead_mask_email($email);
        }
        return '***';
    };
    $predProduct = (string) ($case['primary_product'] ?? '');
    $predIntent = (string) ($case['intent'] ?? '');
    $predKind = (string) ($case['kind'] ?? '');
    $predType = (string) ($case['type'] ?? '');
    $predLabel = (string) ($case['label'] ?? '');
    return [
        'thread' => (string) ($thread['id'] ?? ''),
        'error' => $error,
        'product_stored' => $storedProduct,
        'product_pred' => $predProduct,
        'product_match' => $case !== [] && $storedProduct !== '' && $storedProduct === $predProduct,
        'intent_stored' => $storedIntent,
        'intent_pred' => $predIntent,
        'intent_match' => $case !== [] && $storedIntent !== '' && $storedIntent === $predIntent,
        'recipient_stored' => $mask($storedEmail),
        'recipient_pred' => $mask($predEmail),
        'recipient_match' => $case !== [] && $storedEmail !== '' && $storedEmail === $predEmail,
        'kind_stored' => $storedKind,
        'kind_pred' => $predKind,
        'kind_match' => $case !== [] && $storedKind !== '' && $storedKind === $predKind,
        'type_stored' => $storedType,
        'type_pred' => $predType,
        'type_match' => $case !== [] && $storedType !== '' && $storedType === $predType,
        'label_stored' => $storedLabel,
        'label_pred' => $predLabel,
        'label_match' => $case !== [] && $storedLabel === $predLabel,
    ];
}
