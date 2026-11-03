<?php

declare(strict_types=1);

require_once __DIR__ . '/test_lead_address.php';

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Klassning av nya mejl (Sam): skräp och information till Historik,
 * köpintention via befintlig offert, resten i Inkorgen.
 * Nyhetsbrev utan modellklass, köpta leads och privatkund som hamnat i Information.
 * inkorg.reclassify flyttar öppna och nyligen stängda. Inget nytt utskicksspår.
 */

function jeab_inkorg_list_scope(): string
{
    $raw = mb_strtolower(trim((string) ($_GET['scope'] ?? '')));

    // Utan uttryckligt Egna är listan hela boxen, samma urval som inkorg.counts.
    return $raw === 'egna' ? 'egna' : 'allas';
}

/**
 * Köerna i trådlistan. Mina är trådar tilldelade den inloggade personen
 * (jeab_egna_me_id, samma person som Påminnelser och Ordrar).
 *
 * @return list<string>
 */
function jeab_inkorg_queue_ids(): array
{
    return ['mina', 'otilldelade', 'oppna', 'parkerade', 'stangda'];
}

function jeab_inkorg_queue(): string
{
    $raw = mb_strtolower(trim((string) ($_GET['ko'] ?? '')));

    return in_array($raw, jeab_inkorg_queue_ids(), true) ? $raw : '';
}

/** @return array<string, string> */
function jeab_inkorg_queue_labels(): array
{
    return [
        'mina' => 'Mina',
        'otilldelade' => 'Otilldelade',
        'oppna' => 'Alla öppna',
        'parkerade' => 'Parkerade',
        'stangda' => 'Stängda',
    ];
}

/** Öppen, parkerad (väntar på leverantör) eller stängd. Raderade räknas som stängda. */
function jeab_inkorg_queue_ok(array $t, string $ko): bool
{
    if ($ko === '') {
        return true;
    }
    $face = function_exists('jeab_inkorg_status_face')
        ? jeab_inkorg_status_face((string) ($t['status'] ?? 'open'))
        : 'open';
    $assignee = trim((string) ($t['assignee'] ?? ''));
    $me = function_exists('jeab_egna_me_id') ? jeab_egna_me_id() : '';
    $deleted = !empty($t['deleted']);

    return match ($ko) {
        'mina' => !$deleted && $face === 'open' && $me !== '' && jeab_inkorg_same_person($assignee, $me),
        'otilldelade' => !$deleted && $face === 'open' && $assignee === '',
        'oppna' => !$deleted && $face === 'open',
        'parkerade' => !$deleted && $face === 'parked',
        'stangda' => $deleted || $face === 'closed',
        default => true,
    };
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array<string, int>
 */
function jeab_inkorg_queue_counts(array $rows): array
{
    $out = array_fill_keys(jeab_inkorg_queue_ids(), 0);
    foreach ($rows as $t) {
        if (!is_array($t)) {
            continue;
        }
        foreach (jeab_inkorg_queue_ids() as $ko) {
            if (jeab_inkorg_queue_ok($t, $ko)) {
                $out[$ko]++;
            }
        }
    }

    return $out;
}

/** Produktfiltret i listan. Elbilsladdare lagras som laddbox. */
function jeab_inkorg_produkt_filter(): string
{
    $raw = mb_strtolower(trim((string) ($_GET['produkt'] ?? '')));

    return match ($raw) {
        'sol' => 'sol',
        'batteri' => 'batteri',
        'laddbox', 'elbilsladdare' => 'laddbox',
        default => '',
    };
}

function jeab_inkorg_offer_ok(array $t, string $offer): bool
{
    if ($offer === '') {
        return true;
    }
    $got = function_exists('jeab_inkorg_stored_offer') ? jeab_inkorg_stored_offer($t) : (string) ($t['offer'] ?? '');

    return $got === $offer;
}

/** @return list<array<string, mixed>> */
function jeab_inkorg_folder_views(): array
{
    $row = static function (string $id, string $name, string $folder): array {
        return [
            'id' => $id,
            'name' => $name,
            'menu' => true,
            'company' => '',
            'filter' => ['folder' => $folder],
        ];
    };

    return [
        $row('v-inkorg', 'Inkorg', 'inkorg'),
        $row('v-service', 'Service', 'service'),
        $row('v-information', 'Information', 'information'),
        $row('v-alla', 'Alla', 'alla'),
        $row('v-historik', 'Historik', 'historik'),
    ];
}

/** @return array<string, mixed>|null */
function jeab_inkorg_folder_by_id(string $id): ?array
{
    foreach (jeab_inkorg_folder_views() as $view) {
        if ((string) ($view['id'] ?? '') === $id) {
            return $view;
        }
    }

    return null;
}

/**
 * Bara Egna-filtret. jeab_who_me_person() rörs inte.
 * Sessionens jeab_code_person (saml, samw) räknas här.
 */
function jeab_egna_person(): ?array
{
    $person = function_exists('jeab_who_me_person') ? jeab_who_me_person() : null;
    if (is_array($person) && trim((string) ($person['id'] ?? '')) !== '') {
        return $person;
    }
    if (function_exists('jeab_login_identity')) {
        $login = jeab_login_identity(false, false);
        $reason = (string) ($login['reason'] ?? '');
        if (empty($login['certain']) && in_array($reason, ['ambiguous-no-head', 'ambiguous-no-email', 'ambiguous-stored', 'conflict', 'shared'], true)) {
            return null;
        }
    }
    $code = $_SESSION['jeab_code_person'] ?? '';
    if (!is_string($code) || trim($code) === '' || !function_exists('jeab_person_by_token')) {
        return null;
    }
    $fromCode = jeab_person_by_token($code);

    return is_array($fromCode) && trim((string) ($fromCode['id'] ?? '')) !== '' ? $fromCode : null;
}

function jeab_egna_me_id(): string
{
    $person = jeab_egna_person();

    return is_array($person) ? trim((string) ($person['id'] ?? '')) : '';
}

/** @param list<array<string, mixed>> $menu @return list<array<string, mixed>> */
function jeab_inkorg_fast_scope_menu(string $scope): array
{
    $menu = jeab_inkorg_folder_views();
    if ($scope !== 'egna') {
        return $menu;
    }
    $me = jeab_egna_me_id();
    if ($me === '') {
        // Okänd person: Egna visar samma vyer och tal som Alla.
        return $menu;
    }
    foreach ($menu as $i => $view) {
        $filter = (array) ($view['filter'] ?? []);
        $filter['assignee'] = 'me';
        $menu[$i]['filter'] = $filter;
    }

    return $menu;
}

/** Egna utan känd person faller tillbaka på Alla. Samma person som json-vägens Egna-filter. */
function jeab_inkorg_egna_fallback(): bool
{
    return jeab_inkorg_list_scope() === 'egna' && jeab_egna_me_id() === '';
}

/** Personen som Egna och räknarna filtrerar på: jeab_egna_me_id() under Egna, annars inloggningen. */
function jeab_inkorg_scope_me_id(): string
{
    if (jeab_inkorg_list_scope() === 'egna') {
        return jeab_egna_me_id();
    }

    return function_exists('jeab_inkorg_me_id') ? jeab_inkorg_me_id() : '';
}

function jeab_inkorg_same_person(string $a, string $b): bool
{
    $a = trim($a);
    $b = trim($b);
    if ($a === '' || $b === '') {
        return false;
    }
    if (strcasecmp($a, $b) === 0) {
        return true;
    }
    $ca = preg_replace('/\s+/u', '', $a) ?? $a;
    $cb = preg_replace('/\s+/u', '', $b) ?? $b;

    return strcasecmp($ca, $cb) === 0;
}

/** Bolag för produktägaren: ärendets bolag, annars den öppna boxen. */
function jeab_inkorg_case_company(array $t): string
{
    $company = mb_strtolower(trim((string) ($t['company'] ?? '')));
    if ($company === 'meab') {
        $company = 'malmo';
    }
    if (in_array($company, ['malmo', 'jarfalla'], true)) {
        return $company;
    }
    $box = function_exists('jeab_inkorg_box') ? jeab_inkorg_box() : '';
    if ($box === 'meab') {
        $box = 'malmo';
    }

    return $box === 'malmo' ? 'malmo' : 'jarfalla';
}

/**
 * Produkter personen äger i bolaget, via jeab_lead_quote_owner_name / jeab_lead_owner.
 * Järfälla: sol Sam L, batteri Roberto, laddbox Elin.
 * Malmö: sol Sam W, batteri och laddbox Kim.
 *
 * @return list<string>
 */
function jeab_inkorg_person_offers(string $personId, string $company): array
{
    $personId = trim($personId);
    if ($personId === '' || !function_exists('jeab_lead_quote_owner_name')) {
        return [];
    }
    $company = mb_strtolower(trim($company));
    if ($company === 'meab') {
        $company = 'malmo';
    }
    if (!in_array($company, ['malmo', 'jarfalla'], true)) {
        $company = 'jarfalla';
    }
    $offers = [];
    foreach (['sol', 'batteri', 'laddbox'] as $offer) {
        $name = (string) jeab_lead_quote_owner_name($offer, $company);
        $id = '';
        if ($company !== 'malmo' && function_exists('jeab_lead_owner')) {
            $id = (string) (jeab_lead_owner($offer)[0] ?? '');
        }
        if (jeab_inkorg_same_person($personId, $name) || ($id !== '' && jeab_inkorg_same_person($personId, $id))) {
            $offers[] = $offer;
        }
    }

    return $offers;
}

/**
 * Sparad produkt från klassningen: offer, case_class.primary_product, product, sedan tagg eller jobb.
 * Första mejlet räknas när senaste svaret saknar etikett. Ingen ämnesrad och ingen brödtext.
 *
 * @param list<string> $candidates
 */
function jeab_inkorg_stored_offer_push(array &$candidates, array $bag, bool $labelsOnly): void
{
    $labels = is_array($bag['labels'] ?? null) ? $bag['labels'] : [];
    $case = is_array($bag['case_class'] ?? null) ? $bag['case_class'] : [];
    if ($case === [] && is_array($labels['case_class'] ?? null)) {
        $case = $labels['case_class'];
    }
    if ($labelsOnly) {
        foreach ([
            (string) ($labels['offer'] ?? ''),
            (string) ($bag['offer'] ?? ''),
            (string) ($case['primary_product'] ?? ''),
            (string) ($labels['product'] ?? ''),
            (string) ($bag['product'] ?? ''),
        ] as $raw) {
            $candidates[] = $raw;
        }

        return;
    }
    foreach ((array) ($bag['tags'] ?? []) as $tag) {
        $candidates[] = (string) $tag;
    }
    foreach ((array) ($bag['jobs'] ?? []) as $job) {
        if (is_array($job)) {
            $candidates[] = (string) ($job['product'] ?? $job['offer'] ?? '');
        } else {
            $candidates[] = (string) $job;
        }
    }
}

/** @return list<array<string, mixed>> */
function jeab_inkorg_stored_offer_bags(array $t): array
{
    $bags = [$t];
    foreach (['first', 'last'] as $key) {
        if (is_array($t[$key] ?? null)) {
            $bags[] = $t[$key];
        }
    }
    foreach ((array) ($t['messages'] ?? []) as $msg) {
        if (is_array($msg)) {
            $bags[] = $msg;
        }
    }

    return $bags;
}

function jeab_inkorg_stored_offer(array $t): string
{
    $candidates = [];
    $bags = jeab_inkorg_stored_offer_bags($t);
    foreach ($bags as $bag) {
        jeab_inkorg_stored_offer_push($candidates, $bag, true);
    }
    foreach ($bags as $bag) {
        jeab_inkorg_stored_offer_push($candidates, $bag, false);
    }
    if (!function_exists('jeab_inkorg_product_token')) {
        return '';
    }
    foreach ($candidates as $raw) {
        $raw = trim($raw);
        if ($raw === '' || mb_strlen($raw) > 80) {
            continue;
        }
        $token = jeab_inkorg_product_token($raw);
        if (in_array($token, ['sol', 'batteri', 'laddbox'], true)) {
            return $token;
        }
    }

    return '';
}

function jeab_inkorg_list_offer_label(string $offer): string
{
    return ['sol' => 'Sol', 'batteri' => 'Batteri', 'laddbox' => 'Elbilsladdare'][$offer] ?? '';
}

function jeab_inkorg_assignee_ok(array $t, string $as): bool
{
    if ($as === '') {
        return true;
    }
    $scopeEgna = function_exists('jeab_inkorg_list_scope') && jeab_inkorg_list_scope() === 'egna';
    if ($scopeEgna) {
        $person = jeab_egna_person();
        $me = is_array($person) ? (string) ($person['id'] ?? '') : '';
    } else {
        $me = function_exists('jeab_who_me_person') ? (string) ((jeab_who_me_person() ?? [])['id'] ?? '') : '';
    }
    $cur = (string) ($t['assignee'] ?? '');
    if ($as === 'none') {
        return $cur === '';
    }
    if ($as === 'me') {
        // Tilldelat mig, plus otilldelat bara när produkten är min.
        if ($me === '') {
            return false;
        }
        if ($cur !== '') {
            return jeab_inkorg_same_person($cur, $me);
        }
        $offer = jeab_inkorg_stored_offer($t);
        if ($offer === '') {
            return false;
        }

        return in_array($offer, jeab_inkorg_person_offers($me, jeab_inkorg_case_company($t)), true);
    }
    if ($as === 'others') {
        return $cur !== '' && ($me === '' || !jeab_inkorg_same_person($cur, $me));
    }

    return jeab_inkorg_same_person($cur, $as);
}

function jeab_inkorg_in_historik(array $t): bool
{
    if (!empty($t['deleted'])) {
        return true;
    }
    // Öppen lagrad status är inte Historik, även utan ny klassning eller vid gammal import.
    if ((string) ($t['status'] ?? '') === 'open') {
        return false;
    }
    $status = function_exists('jeab_inkorg_thread_status') ? jeab_inkorg_thread_status($t) : (string) ($t['status'] ?? '');

    return $status === 'closed';
}

function jeab_inkorg_folder_thread(array $t, string $folder): bool
{
    // Historik är stängd eller raderad. Partnerklassning hör inte hit och får inte köras per rad.
    if ($folder === 'historik') {
        return jeab_inkorg_in_historik($t);
    }
    $type = trim((string) ($t['labels']['type'] ?? ''));
    $tags = array_map('strval', (array) ($t['tags'] ?? []));
    $backorder = in_array('restnoterad', $tags, true);
    $manual = array_map('strval', (array) ($t['manual_tags'] ?? []));
    $hist = jeab_inkorg_in_historik($t);
    if ($hist || !empty($t['deleted'])) {
        return false;
    }
    // Listan använder sparad klass. Hela tråden läses bara vid omklassning och ett enskilt ärende.
    $face = jeab_inkorg_klassning_stored_message($t);
    // Sparad typ Information eller Service lämnar Inkorgen även när avsändaren är en person eller svaret har Sv:.
    // En kund med order, installation eller påminnelse stannar. Den kontrollen bygger orderkartan en gång.
    $typedAway = in_array($type, ['Information', 'Service'], true);
    if ($typedAway && !$backorder && jeab_inkorg_klassning_purchase_sender($face)) {
        $typedAway = false;
    }
    $information = !$backorder && ($typedAway || jeab_inkorg_klassning_quiet_information($face));
    $service = in_array('service', $manual, true) && !$information;
    if ($folder === 'information') {
        return $information;
    }
    if ($folder === 'service') {
        return $service;
    }
    if ($folder === 'alla') {
        return true;
    }
    if ($folder === 'inkorg') {
        if ($type === 'Skräp' || $information || $service) {
            return false;
        }

        // Saknad klass (tom typ) är Inkorg.
        return true;
    }

    return false;
}

/** Samma mejl klassas flera gånger i mappfiltret. Nyckeln följer fälten som styr svaret. */
function jeab_inkorg_klassning_msg_key(array $msg): string
{
    $labels = (array) ($msg['labels'] ?? []);
    $body = (string) ($msg['body'] ?? '');
    $text = (string) ($msg['body_text'] ?? '');

    return (string) ($msg['id'] ?? '')
        . "\x1e" . (string) ($msg['from'] ?? '')
        . "\x1e" . (string) ($msg['from_name'] ?? '')
        . "\x1e" . (string) ($msg['subject'] ?? '')
        . "\x1e" . (string) ($labels['type'] ?? '')
        . "\x1e" . (string) ($labels['intent'] ?? '')
        . "\x1e" . (string) ($labels['confidence'] ?? '')
        . "\x1e" . (string) ($labels['offer'] ?? '')
        . "\x1e" . (string) ($msg['in_reply_to'] ?? '')
        . "\x1e" . (string) ($msg['envelope_from'] ?? '')
        . "\x1e" . (string) ($msg['source'] ?? '')
        . "\x1e" . (string) ($msg['thread_id'] ?? '')
        . "\x1e" . ($body === '' ? '0' : hash('md5', $body))
        . "\x1e" . ($text === '' ? '0' : hash('md5', $text));
}

function jeab_inkorg_klassning_cached(string $bucket, array $msg, callable $body): bool
{
    $key = jeab_inkorg_klassning_msg_key($msg);
    if (isset($GLOBALS['_JEAB_KLASSNING_MEMO'][$bucket][$key])) {
        return (bool) $GLOBALS['_JEAB_KLASSNING_MEMO'][$bucket][$key];
    }
    $value = (bool) $body($msg);
    $GLOBALS['_JEAB_KLASSNING_MEMO'][$bucket][$key] = $value;

    return $value;
}

function jeab_inkorg_klassning_backorder(array $msg): bool
{
    return jeab_inkorg_klassning_cached('back', $msg, jeab_inkorg_klassning_backorder_body(...));
}

function jeab_inkorg_klassning_backorder_body(array $msg): bool
{
    $text = function_exists('jeab_inkorg_message_blob') ? jeab_inkorg_message_blob($msg) : mb_strtolower((string) ($msg['subject'] ?? '') . "\n" . (string) ($msg['body'] ?? ''));

    return preg_match('/restnot|ej\s+i\s+lager|inte\s+i\s+lager|slut\s+i\s+lager|out of stock|backorder/iu', $text) === 1;
}

function jeab_inkorg_klassning_personal_domain(string $email): bool
{
    $email = mb_strtolower(trim($email));
    $at = strrpos($email, '@');
    if ($at === false) {
        return false;
    }
    $host = substr($email, $at + 1);

    return in_array($host, [
        'gmail.com', 'googlemail.com', 'hotmail.com', 'outlook.com', 'live.com', 'live.se',
        'icloud.com', 'me.com', 'yahoo.com', 'yahoo.se', 'telia.com', 'bredband.net',
        'msn.com', 'proton.me', 'protonmail.com',
    ], true);
}

/** Leverantörsmejl som inte kräver åtgärd. Kundsvar och restnoterat stannar i Inkorgen. */
function jeab_inkorg_klassning_partner_info(array $msg): bool
{
    $GLOBALS['_JEAB_PARTNER_INFO_CALLS'] = (int) ($GLOBALS['_JEAB_PARTNER_INFO_CALLS'] ?? 0) + 1;

    return jeab_inkorg_klassning_cached('pi', $msg, jeab_inkorg_klassning_partner_info_body(...));
}

function jeab_inkorg_klassning_partner_info_body(array $msg): bool
{
    if (jeab_inkorg_klassning_backorder($msg)) {
        return false;
    }
    if (jeab_inkorg_klassning_supplier_quiet($msg)) {
        return true;
    }
    $labels = (array) ($msg['labels'] ?? []);
    $type = function_exists('jeab_inkorg_type_new') ? jeab_inkorg_type_new((string) ($labels['type'] ?? '')) : (string) ($labels['type'] ?? '');
    $intent = (string) ($labels['intent'] ?? '');
    if (in_array($type, ['Ny lead', 'Support', 'Service', 'Kräver åtgärd från oss', 'Order', 'Bokning/ombokning', 'Skräp'], true)) {
        return false;
    }
    if (in_array($intent, ['kop', 'support', 'skrap'], true)) {
        return false;
    }
    if (jeab_inkorg_klassning_customer_mail($msg) && !jeab_inkorg_klassning_lead_sender($msg)) {
        return false;
    }
    $order = function_exists('jeab_inkorg_order_confirmation') ? jeab_inkorg_order_confirmation($msg) : ['confirm' => false];
    $fortnox = function_exists('jeab_inkorg_is_fortnox_system') && jeab_inkorg_is_fortnox_system($msg);
    $sure = (int) ($labels['confidence'] ?? 0) >= (function_exists('jeab_inkorg_review_min_confidence') ? jeab_inkorg_review_min_confidence() : 70) || $fortnox || !empty($order['confirm']);
    if (!$sure) {
        return false;
    }
    if ($fortnox || !empty($order['confirm'])) {
        return true;
    }
    if ($type !== 'Information' && $intent !== 'information') {
        return false;
    }
    if (jeab_inkorg_klassning_personal_domain((string) ($msg['from'] ?? ''))) {
        return false;
    }
    if (trim((string) ($msg['in_reply_to'] ?? '')) !== '') {
        return false;
    }
    if (preg_match('/^\s*(re|sv)\s*:/iu', (string) ($msg['subject'] ?? '')) === 1) {
        return false;
    }
    if (trim((string) ($msg['envelope_from'] ?? '')) !== '') {
        return false;
    }

    return true;
}

function jeab_inkorg_klassning_label_type(array $msg): string
{
    $labels = (array) ($msg['labels'] ?? []);
    $type = (string) ($labels['type'] ?? '');

    return function_exists('jeab_inkorg_type_new') ? jeab_inkorg_type_new($type) : $type;
}

/** @return array<string, mixed> */
function jeab_inkorg_klassning_case_bag(array $msg): array
{
    $labels = (array) ($msg['labels'] ?? []);
    if (is_array($labels['case_class'] ?? null) && $labels['case_class'] !== []) {
        return $labels['case_class'];
    }

    return is_array($msg['case_class'] ?? null) ? $msg['case_class'] : [];
}

/**
 * Grok sa Information: typen, avsikten, eller case_class när «Grok bestämmer» är av.
 * En redan satt åtgärdstyp (Ny lead, Support, Service) vinner.
 */
function jeab_inkorg_klassning_says_information(array $msg): bool
{
    $type = jeab_inkorg_klassning_label_type($msg);
    $intent = (string) (($msg['labels']['intent'] ?? ''));
    $action = ['Ny lead', 'Support', 'Service', 'Kräver åtgärd från oss', 'Order', 'Bokning/ombokning', 'Skräp'];
    if (in_array($type, $action, true) || in_array($intent, ['kop', 'support', 'skrap'], true)) {
        return false;
    }
    if ($type === 'Information' || $intent === 'information') {
        return true;
    }
    $case = jeab_inkorg_klassning_case_bag($msg);
    if ($case === []) {
        return false;
    }
    $caseType = function_exists('jeab_inkorg_type_new') ? jeab_inkorg_type_new((string) ($case['type'] ?? '')) : (string) ($case['type'] ?? '');

    return $caseType === 'Information' || (string) ($case['kind'] ?? '') === 'information';
}

/** Privatperson via domän eller personnamn. Känd kontakt och rolladress räknas inte. */
function jeab_inkorg_klassning_person_sender(array $msg): bool
{
    if (jeab_inkorg_klassning_own($msg) || jeab_inkorg_klassning_supplier_quiet($msg)) {
        return false;
    }
    $from = trim((string) ($msg['from'] ?? ''));
    if ($from === '' || jeab_inkorg_klassning_role_address($from)) {
        return false;
    }
    if (jeab_inkorg_klassning_personal_domain($from)) {
        return true;
    }
    $name = trim((string) ($msg['from_name'] ?? ''));
    if (preg_match('/^\p{L}{2,}(?:[ \'-]\p{L}{2,})+$/u', $name) !== 1) {
        return false;
    }
    if (preg_match('/\b(?:ab|hb|kb|inc|ltd|gmbh|group)\b/iu', $name) === 1) {
        return false;
    }

    return true;
}

/** Kund med order eller installation. Kontaktregistret ensamt räknas inte. */
function jeab_inkorg_klassning_purchase_sender(array $msg): bool
{
    $from = mb_strtolower(trim((string) ($msg['from'] ?? '')));
    if ($from === '' || !function_exists('jeab_inkorg_purchase_customer_kind')) {
        return false;
    }

    return jeab_inkorg_purchase_customer_kind($from) !== '';
}

/**
 * Information som inte kräver åtgärd. Öppen i mappen Information, inte Inkorg och inte Historik.
 * Privatperson, gmail, defekt, återmontering, svar och restnoterat ingår inte.
 */
function jeab_inkorg_klassning_quiet_information(array $msg): bool
{
    if (!jeab_inkorg_klassning_says_information($msg)) {
        return false;
    }
    if (jeab_inkorg_klassning_backorder($msg)) {
        return false;
    }
    if (jeab_inkorg_klassning_person_sender($msg) || jeab_inkorg_klassning_purchase_sender($msg)) {
        return false;
    }
    if (jeab_inkorg_klassning_lead_sender($msg) || jeab_inkorg_wix_known_product_offer($msg) !== '' || jeab_inkorg_klassning_kop_offer($msg) !== '') {
        return false;
    }
    if (preg_match('/defekt|återmonter|atermonter|trasig|fungerar\s+inte/iu', jeab_inkorg_klassning_blob($msg)) === 1) {
        return false;
    }
    if (trim((string) ($msg['in_reply_to'] ?? '')) !== '') {
        return false;
    }
    if (preg_match('/^\s*(re|sv)\s*:/iu', (string) ($msg['subject'] ?? '')) === 1) {
        return false;
    }

    return true;
}

/**
 * Produkt från Groks case_class när ärendet är ett lead.
 * Läser case_class även när brytaren «Grok bestämmer» är av. Skriver inget.
 * Tom sträng när det inte är ett offertlead.
 */
function jeab_inkorg_lead_case_offer(array $msg): string
{
    $labels = (array) ($msg['labels'] ?? []);
    if (!empty($labels['needs_review'])) {
        return '';
    }
    $labelType = jeab_inkorg_klassning_label_type($msg);
    $blocked = ['Support', 'Service', 'Skräp', 'Order', 'Kräver åtgärd från oss', 'Bokning/ombokning'];
    if (in_array($labelType, $blocked, true)) {
        return '';
    }
    $case = jeab_inkorg_klassning_case_bag($msg);
    $caseType = '';
    $caseIntent = '';
    $kind = '';
    if ($case !== []) {
        $caseType = function_exists('jeab_inkorg_type_new') ? jeab_inkorg_type_new((string) ($case['type'] ?? '')) : (string) ($case['type'] ?? '');
        $caseIntent = (string) ($case['intent'] ?? '');
        $kind = (string) ($case['kind'] ?? '');
    }
    $labelLead = $labelType === 'Ny lead' || (string) ($labels['intent'] ?? '') === 'kop' || (string) ($labels['lead'] ?? '') === 'kopt';
    if ($kind === 'information' && !$labelLead) {
        return '';
    }
    $caseLead = $caseType === 'Ny lead' || $caseIntent === 'kopintresse';
    if (!$labelLead && !$caseLead) {
        return '';
    }
    if (!$labelLead) {
        $conf = (float) ($case['confidence']['primary_product'] ?? 0);
        $intentConf = (float) ($case['confidence']['intent'] ?? 0);
        if ($conf < 0.8 && $intentConf < 0.8) {
            return '';
        }
    }
    if ($case === []) {
        return '';
    }
    $product = function_exists('jeab_inkorg_case_product_name') ? jeab_inkorg_case_product_name((string) ($case['primary_product'] ?? '')) : '';
    $offer = function_exists('jeab_inkorg_case_offer') ? jeab_inkorg_case_offer($product) : '';
    if (!in_array($offer, ['laddbox', 'batteri', 'sol'], true)) {
        return '';
    }
    $second = array_map('strval', (array) ($case['secondary_products'] ?? []));
    if ($offer === 'sol' && in_array('Batteri', $second, true)) {
        return 'sol+batteri';
    }

    return $offer;
}

function jeab_inkorg_klassning_kop_offer(array $msg): string
{
    $labels = (array) ($msg['labels'] ?? []);
    $type = function_exists('jeab_inkorg_type_new') ? jeab_inkorg_type_new((string) ($labels['type'] ?? '')) : (string) ($labels['type'] ?? '');
    if ($type !== 'Ny lead' && (string) ($labels['intent'] ?? '') !== 'kop') {
        return '';
    }
    $offer = (string) ($labels['offer'] ?? '');
    if (function_exists('jeab_inkorg_force_offer')) {
        $offer = jeab_inkorg_force_offer($offer);
    }
    if (!in_array($offer, ['laddbox', 'batteri', 'sol', 'sol+batteri'], true)) {
        return '';
    }

    return $offer;
}

/** skrap, information, kop, egen eller inkorg. Skriver inget. */
function jeab_inkorg_klassning_route(array $msg): string
{
    $labels = (array) ($msg['labels'] ?? []);
    $type = function_exists('jeab_inkorg_type_new') ? jeab_inkorg_type_new((string) ($labels['type'] ?? '')) : (string) ($labels['type'] ?? '');
    if (jeab_inkorg_klassning_backorder($msg)) {
        return 'inkorg';
    }
    if (jeab_inkorg_klassning_lead_sender($msg)) {
        return 'kop';
    }
    if (jeab_inkorg_wix_known_product_offer($msg) !== '') {
        return 'kop';
    }
    if (jeab_inkorg_klassning_own($msg)) {
        return 'egen';
    }
    if (jeab_inkorg_klassning_kop_offer($msg) !== '') {
        return 'kop';
    }
    if (jeab_inkorg_klassning_supplier_quiet($msg)) {
        return 'information';
    }
    if (jeab_inkorg_klassning_newsletter($msg)) {
        return 'skrap';
    }
    if ($type === 'Skräp' || (string) ($labels['intent'] ?? '') === 'skrap') {
        return 'skrap';
    }
    if (jeab_inkorg_klassning_quiet_information($msg)) {
        return 'information';
    }
    if (jeab_inkorg_klassning_customer_mail($msg)) {
        return 'inkorg';
    }
    if (jeab_inkorg_klassning_partner_info($msg)) {
        return 'information';
    }

    return 'inkorg';
}

function jeab_inkorg_klassning_same_offer(string $a, string $b): bool
{
    $left = function_exists('jeab_offer_product_family') ? jeab_offer_product_family($a) : '';
    $right = function_exists('jeab_offer_product_family') ? jeab_offer_product_family($b) : '';
    if ($left !== '' && $left === $right) {
        return true;
    }

    return $a !== '' && $a === $b;
}

/** Offert till samma kund och produkt de senaste 6 månaderna. */
function jeab_inkorg_klassning_recent_quote(array $thread, array $msg, string $offer): bool
{
    if (function_exists('jeab_inkorg_force_offer')) {
        $offer = jeab_inkorg_force_offer($offer);
    }
    if (!in_array($offer, ['laddbox', 'batteri', 'sol', 'sol+batteri'], true)) {
        return false;
    }
    if (!function_exists('jeab_lead_customer') || !function_exists('jeab_inkorg_same_party') || !function_exists('jeab_inkorg_quote_within_days')) {
        return false;
    }
    $cust = jeab_lead_customer($msg);
    $contactIds = function_exists('jeab_inkorg_party_contact_ids') ? jeab_inkorg_party_contact_ids($cust) : [];
    if ($contactIds === null) {
        return false;
    }
    $tid = (string) ($thread['id'] ?? '');
    foreach (jeab_inkorg_threads() as $other) {
        if (!is_array($other) || (string) ($other['id'] ?? '') === $tid || !empty($other['deleted'])) {
            continue;
        }
        $mark = (array) ($other['quote_sent'] ?? []);
        if ($mark === [] || !jeab_inkorg_quote_within_days($mark, 183)) {
            continue;
        }
        $old = function_exists('jeab_lead_infer_offer')
            ? jeab_lead_infer_offer((array) ($other['first'] ?? []), (array) ($other['tags'] ?? []))
            : '';
        if (!jeab_inkorg_klassning_same_offer($old, $offer)) {
            continue;
        }
        if (!jeab_inkorg_same_party($other, $cust, $contactIds)) {
            continue;
        }

        return true;
    }

    return false;
}

function jeab_inkorg_klassning_ensure_tag(string $tid, string $tag): void
{
    if ($tid === '' || $tag === '') {
        return;
    }
    jeab_inkorg_thread_write($tid, static function (array $t) use ($tag): array {
        $tags = array_map('strval', (array) ($t['tags'] ?? []));
        if (!in_array($tag, $tags, true)) {
            $tags[] = $tag;
            $t['tags'] = array_values($tags);
        }

        return $t;
    });
}

function jeab_inkorg_klassning_close(string $tid, string $via): void
{
    if ($tid === '') {
        return;
    }
    try {
        jeab_inkorg_mark_hook_closed($tid, $via);
    } catch (Throwable $e) {
        unset($e);
    }
}

/**
 * Varje köpintention till Historik. Skickar inget.
 * kop-skickad: offerten gick iväg.
 * kop-sex-manader: samma produkt inom 6 månader, ingen ny offert.
 * kop-hallen: offertutskick är av, eller avsändaren står inte på listan.
 * Hållen offert har redan kontakt och påminnelse.
 */
function jeab_inkorg_klassning_kop_historik(string $tid, string $via): void
{
    jeab_inkorg_klassning_close($tid, $via);
}

/**
 * Mejl i tidsordning, äldst först. Interna anteckningar ingår inte.
 *
 * @return list<array<string, mixed>>
 */
function jeab_inkorg_klassning_ordered_messages(array $t): array
{
    $GLOBALS['_JEAB_KLASSNING_FACE_SCANS'] = (int) ($GLOBALS['_JEAB_KLASSNING_FACE_SCANS'] ?? 0) + 1;
    $msgs = [];
    foreach ((array) ($t['messages'] ?? []) as $message) {
        if (is_array($message) && empty($message['internal_note'])) {
            $msgs[] = $message;
        }
    }
    if ($msgs === []) {
        if (is_array($t['first'] ?? null)) {
            $msgs[] = $t['first'];
        }
        $last = $t['last'] ?? null;
        if (is_array($last)) {
            $same = $msgs !== [] && (string) ($msgs[0]['id'] ?? '') !== '' && (string) ($msgs[0]['id'] ?? '') === (string) ($last['id'] ?? '');
            if (!$same) {
                $msgs[] = $last;
            }
        }
    }
    $decorated = [];
    foreach ($msgs as $i => $message) {
        $decorated[] = [$i, (string) ($message['received_at'] ?? $message['date'] ?? ''), $message];
    }
    usort($decorated, static function (array $a, array $b): int {
        if ($a[1] !== '' && $b[1] !== '' && $a[1] !== $b[1]) {
            return $a[1] <=> $b[1];
        }

        return $a[0] <=> $b[0];
    });
    $out = [];
    foreach ($decorated as $row) {
        $out[] = $row[2];
    }

    return $out;
}

/**
 * Sparad klass för listan. Senaste lagrade mejlet, inte en genomgång av alla mejl.
 *
 * @return array<string, mixed>
 */
function jeab_inkorg_klassning_stored_message(array $t): array
{
    $face = [];
    if (is_array($t['last'] ?? null)) {
        $face = $t['last'];
    } elseif (is_array($t['first'] ?? null)) {
        $face = $t['first'];
    }
    if (!isset($face['labels']) && isset($t['labels']) && is_array($t['labels'])) {
        $face['labels'] = $t['labels'];
    }
    if (trim((string) ($face['from'] ?? '')) === '' && trim((string) ($t['from'] ?? '')) !== '') {
        $face['from'] = (string) $t['from'];
    }
    if (trim((string) ($face['from_name'] ?? '')) === '' && trim((string) ($t['from_name'] ?? '')) !== '') {
        $face['from_name'] = (string) $t['from_name'];
    }
    if (trim((string) ($face['subject'] ?? '')) === '' && trim((string) ($t['subject'] ?? '')) !== '') {
        $face['subject'] = (string) $t['subject'];
    }

    return $face;
}

/**
 * Egen bara när inget mejl kommer utifrån.
 * Annars kundens senaste inkommande mejl, oavsett vem som började tråden.
 * Omklassning och ett enskilt ärende. Listan använder den sparade klassen.
 */
function jeab_inkorg_klassning_face(array $t): array
{
    $id = (string) ($t['id'] ?? '');
    $lastId = is_array($t['last'] ?? null) ? (string) ($t['last']['id'] ?? '') : '';
    $key = $id
        . "\x1e" . $lastId
        . "\x1e" . (string) ($t['updated_at'] ?? '')
        . "\x1e" . (string) ($t['from'] ?? '')
        . "\x1e" . (string) (($t['labels']['type'] ?? ''))
        . "\x1e" . count((array) ($t['messages'] ?? []));
    if ($id !== '' && isset($GLOBALS['_JEAB_KLASSNING_FACE'][$key]) && is_array($GLOBALS['_JEAB_KLASSNING_FACE'][$key])) {
        return $GLOBALS['_JEAB_KLASSNING_FACE'][$key];
    }
    $face = jeab_inkorg_klassning_face_body($t);
    if ($id !== '') {
        $GLOBALS['_JEAB_KLASSNING_FACE'][$key] = $face;
    }

    return $face;
}

function jeab_inkorg_klassning_face_body(array $t): array
{
    $msgs = jeab_inkorg_klassning_ordered_messages($t);
    $omtago = jeab_inkorg_klassning_omtago_lead($t, []);
    $face = [];
    foreach ($msgs as $message) {
        $probe = $message;
        if ($omtago) {
            $probe['source'] = 'omtago';
            if (str_starts_with((string) ($t['id'] ?? ''), 'omc')) {
                $probe['thread_id'] = (string) $t['id'];
            }
        }
        if (!jeab_inkorg_klassning_own($probe)) {
            $face = $probe;
        }
    }
    if ($face === [] && $msgs !== []) {
        $face = $msgs[array_key_last($msgs)];
    }
    if (!isset($face['labels']) && isset($t['labels']) && is_array($t['labels'])) {
        $face['labels'] = $t['labels'];
    }
    if (trim((string) ($face['from'] ?? '')) === '' && trim((string) ($t['from'] ?? '')) !== '') {
        $face['from'] = (string) $t['from'];
    }
    if (trim((string) ($face['from_name'] ?? '')) === '' && trim((string) ($t['from_name'] ?? '')) !== '') {
        $face['from_name'] = (string) $t['from_name'];
    }
    if (trim((string) ($face['subject'] ?? '')) === '' && trim((string) ($t['subject'] ?? '')) !== '') {
        $face['subject'] = (string) $t['subject'];
    }

    return $face;
}

function jeab_inkorg_klassning_blob(array $msg): string
{
    return mb_strtolower(trim(
        (string) ($msg['subject'] ?? '') . "\n"
        . (string) ($msg['body'] ?? '') . "\n"
        . (string) ($msg['body_text'] ?? '')
    ));
}

function jeab_inkorg_klassning_role_address(string $email): bool
{
    $email = mb_strtolower(trim($email));
    $local = (string) strstr($email, '@', true);
    if ($local === '') {
        return false;
    }

    return preg_match('/^(?:info|order|orders|no-?reply|donotreply|do-not-reply|faktura|invoice|support|news|nyhetsbrev|newsletter|admin|kontakt|sales|office|hello|hej|mail|notification|notifications|ekonomi|kundtjanst|kundtjänst)(?:[.+_-]|$)/u', $local) === 1;
}

function jeab_inkorg_klassning_contacts_sql(): bool
{
    if (array_key_exists('_JEAB_KLASSNING_SQL', $GLOBALS)) {
        return (bool) $GLOBALS['_JEAB_KLASSNING_SQL'];
    }
    $on = function_exists('jeab_kontakter_use_sql') && jeab_kontakter_use_sql();
    $GLOBALS['_JEAB_KLASSNING_SQL'] = $on;

    return $on;
}

/**
 * Ett indexerat uppslag, eller en IN-fråga för adresserna på sidan.
 * Träffar och missar memoiseras. Hela kontaktregistret läses inte.
 *
 * @param list<array<string, mixed>> $threads
 */
function jeab_inkorg_klassning_contacts_prefetch(array $threads, bool $informationOnly = true): void
{
    $want = [];
    foreach ($threads as $thread) {
        if (!is_array($thread)) {
            continue;
        }
        if ($informationOnly && trim((string) ($thread['labels']['type'] ?? '')) !== 'Information') {
            continue;
        }
        $from = mb_strtolower(trim((string) (jeab_inkorg_klassning_stored_message($thread)['from'] ?? '')));
        if ($from === '' || !str_contains($from, '@') || array_key_exists($from, $GLOBALS['_JEAB_KLASSNING_CONTACT_SQL'] ?? [])) {
            continue;
        }
        $want[$from] = true;
    }
    if ($want === [] || !jeab_inkorg_klassning_contacts_sql() || !function_exists('jeab_contact_emails_find')) {
        return;
    }
    $found = jeab_contact_emails_find(array_keys($want));
    foreach (array_keys($want) as $email) {
        $GLOBALS['_JEAB_KLASSNING_CONTACT_SQL'][$email] = isset($found[$email]);
    }
}

function jeab_inkorg_klassning_contact_indexed(string $email): bool
{
    $email = mb_strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) {
        return false;
    }
    if (array_key_exists($email, $GLOBALS['_JEAB_KLASSNING_CONTACT_SQL'] ?? [])) {
        return (bool) $GLOBALS['_JEAB_KLASSNING_CONTACT_SQL'][$email];
    }
    if (!jeab_inkorg_klassning_contacts_sql() || !function_exists('jeab_contact_emails_find')) {
        $GLOBALS['_JEAB_KLASSNING_CONTACT_SQL'][$email] = false;

        return false;
    }
    $found = jeab_contact_emails_find([$email]);
    $hit = isset($found[$email]);
    $GLOBALS['_JEAB_KLASSNING_CONTACT_SQL'][$email] = $hit;

    return $hit;
}

function jeab_inkorg_klassning_known_contact(string $email): bool
{
    $email = mb_strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) {
        return false;
    }
    if (array_key_exists($email, $GLOBALS['_JEAB_KLASSNING_KNOWN'] ?? [])) {
        return (bool) $GLOBALS['_JEAB_KLASSNING_KNOWN'][$email];
    }
    $hit = jeab_inkorg_klassning_contact_indexed($email);
    if (!$hit && function_exists('jeab_inkorg_purchase_customer_kind') && jeab_inkorg_purchase_customer_kind($email) !== '') {
        $hit = true;
    }
    $GLOBALS['_JEAB_KLASSNING_KNOWN'][$email] = $hit;

    return $hit;
}

/** Privatadress, känd kontakt, eller ett personnamn som inte är en rolladress. */
function jeab_inkorg_klassning_private_person(array $msg): bool
{
    return jeab_inkorg_klassning_cached('priv', $msg, jeab_inkorg_klassning_private_person_body(...));
}

function jeab_inkorg_klassning_private_person_body(array $msg): bool
{
    $from = trim((string) ($msg['from'] ?? ''));
    if ($from === '') {
        return false;
    }
    if (jeab_inkorg_klassning_own($msg) || jeab_inkorg_klassning_supplier_quiet($msg)) {
        return false;
    }
    if (jeab_inkorg_klassning_personal_domain($from) || jeab_inkorg_klassning_known_contact($from)) {
        return true;
    }
    if (jeab_inkorg_klassning_role_address($from)) {
        return false;
    }
    if (function_exists('jeab_lead_platform') && jeab_lead_platform($from) !== '') {
        return false;
    }
    $name = trim((string) ($msg['from_name'] ?? ''));
    if (preg_match('/^\p{L}{2,}(?:[ \'-]\p{L}{2,})+$/u', $name) !== 1) {
        return false;
    }
    if (preg_match('/\b(?:ab|hb|kb|inc|ltd|gmbh|group)\b/iu', $name) === 1) {
        return false;
    }

    return true;
}

/** Kundfråga eller privatperson. Aldrig en köpt lead, egen avsändare eller tyst leverantör. */
function jeab_inkorg_klassning_customer_mail(array $msg): bool
{
    return jeab_inkorg_klassning_cached('cust', $msg, jeab_inkorg_klassning_customer_mail_body(...));
}

function jeab_inkorg_klassning_customer_mail_body(array $msg): bool
{
    if (jeab_inkorg_klassning_lead_sender($msg) || jeab_inkorg_klassning_own($msg) || jeab_inkorg_klassning_supplier_quiet($msg)) {
        return false;
    }
    if (jeab_inkorg_klassning_private_person($msg)) {
        return true;
    }
    $text = jeab_inkorg_klassning_blob($msg);

    return preg_match('/defekt|återmonter|atermonter|trasig|fungerar\s+inte/iu', $text) === 1;
}

function jeab_inkorg_klassning_lead_hay(array $msg): string
{
    return mb_strtolower(trim(
        (string) ($msg['from'] ?? '') . ' '
        . (string) ($msg['from_name'] ?? '') . ' '
        . (string) ($msg['subject'] ?? '')
    ));
}

/** Köpta leadkällor är alltid köpintention, även utan modellklass. */
function jeab_inkorg_klassning_lead_sender(array $msg): bool
{
    return jeab_inkorg_klassning_cached('lead', $msg, jeab_inkorg_klassning_lead_sender_body(...));
}

/**
 * Wix-sammanfattning för Sol, Batteri eller Laddbox.
 * Bron skickar kundens adress som avsändare. Egen post (inkorgsadresserna från test_lead_address) är aldrig lead.
 * Kräver Wix-strukturen «A site visitor just submitted your form» och ett produktformulär.
 * «JEAB … lead» ensamt räcker inte. Kontakt Formulär är inte ett lead.
 */
function jeab_inkorg_klassning_wix_lead_summary(array $msg): bool
{
    $from = jeab_inkorg_klassning_email((string) ($msg['from'] ?? ''));
    if ($from !== '' && function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($from)) {
        return false;
    }
    $text = jeab_inkorg_klassning_blob($msg);
    if ($text === '' || !str_contains($text, 'a site visitor just submitted your form')) {
        return false;
    }

    return preg_match('/\b(?:batteri|solceller|sol\s*\+\s*batteri|laddbox)\s+lead\b/iu', $text) === 1;
}

/** Formulärnamnet i «submitted your form», utan sajtnamn och utan spårlänk. */
function jeab_inkorg_wix_form_title(array $msg): string
{
    $text = jeab_inkorg_klassning_blob($msg);
    if ($text === '' || !str_contains($text, 'a site visitor just submitted your form')) {
        return '';
    }
    if (preg_match('/a site visitor just submitted your form:?\s*([^\n]{1,180})/iu', $text, $m) !== 1) {
        return '';
    }
    $title = (string) $m[1];
    $title = preg_replace('/[\x{200E}\x{200F}\x{2060}\x{FEFF}].*/u', '', $title) ?? $title;
    $title = preg_replace('/\s+on\s+.*/u', '', $title) ?? $title;
    $title = preg_replace('/<[^>]*>/', '', $title) ?? $title;

    return trim($title, " \t:.");
}

/** Brödtexten, en gång. Ämnet ingår inte. */
function jeab_inkorg_wix_body_text(array $msg): string
{
    $chunks = [];
    foreach (['body', 'body_text'] as $key) {
        $chunk = trim((string) ($msg[$key] ?? ''));
        if ($chunk === '') {
            continue;
        }
        $keep = true;
        foreach ($chunks as $i => $have) {
            if ($have === $chunk || str_contains($have, $chunk)) {
                $keep = false;
                break;
            }
            if (str_contains($chunk, $have)) {
                $chunks[$i] = $chunk;
                $keep = false;
                break;
            }
        }
        if ($keep) {
            $chunks[] = $chunk;
        }
    }

    return trim(implode("\n", $chunks));
}

/** Formulärnamnet i brödtextens «submitted your form», inte i ämnet. */
function jeab_inkorg_wix_title_in(string $text): string
{
    if ($text === '' || !str_contains(mb_strtolower($text), 'a site visitor just submitted your form')) {
        return '';
    }
    if (preg_match('/a site visitor just submitted your form:?\s*([^\n]{1,180})/iu', $text, $m) !== 1) {
        return '';
    }
    $title = (string) $m[1];
    $title = preg_replace('/[\x{200E}\x{200F}\x{2060}\x{FEFF}].*/u', '', $title) ?? $title;
    $title = preg_replace('/\s+on\s+.*/u', '', $title) ?? $title;
    $title = preg_replace('/<[^>]*>/', '', $title) ?? $title;
    $title = preg_replace('/https?:\/\/\S+/iu', '', $title) ?? $title;

    return trim($title, " \t:.");
}

/**
 * Kundens svar efter fältetiketter.
 * Allt till och med raden «submitted your form» tas bort, även ett formulärnamn på raden före.
 * Ämnet läses inte.
 */
function jeab_inkorg_wix_answer_text(array $msg): string
{
    $text = jeab_inkorg_wix_body_text($msg);
    $raw = preg_split("/\r\n|\n|\r/", $text) ?: [];
    $after = null;
    foreach ($raw as $i => $line) {
        if (str_contains(mb_strtolower((string) $line), 'submitted your form')) {
            $after = $i + 1;
            break;
        }
    }
    if ($after === null) {
        return '';
    }
    $title = mb_strtolower(jeab_inkorg_wix_title_in($text));
    $lines = [];
    $pending = false;
    $choice = false;
    foreach (array_slice($raw, $after) as $line) {
        $line = trim((string) $line);
        if ($line === '' || preg_match('/^https?:\/\//iu', $line) === 1) {
            continue;
        }
        $low = mb_strtolower($line);
        if (str_contains($low, 'submitted your form')) {
            $pending = false;
            $choice = false;
            continue;
        }
        if ($title !== '' && ($low === $title || $low === 'jeab ' . $title)) {
            $pending = false;
            $choice = false;
            continue;
        }
        if (function_exists('jeab_inkorg_purchase_banner_line') && jeab_inkorg_purchase_banner_line($line)) {
            $pending = false;
            $choice = false;
            continue;
        }
        if (preg_match('/^offert på din mail\b/iu', $line) === 1) {
            $pending = true;
            $choice = false;
            continue;
        }
        $isChoice = function_exists('jeab_inkorg_form_choice_label') && jeab_inkorg_form_choice_label($line);
        $isStop = function_exists('jeab_inkorg_form_stop_label') && jeab_inkorg_form_stop_label($line);
        if ($isChoice || $isStop) {
            $pending = true;
            $choice = $isChoice;
            if ($isChoice) {
                $lines[] = $line;
            }
            if (preg_match('/[:：]\s*(.+)$/u', $line, $m) === 1) {
                $inline = trim((string) $m[1]);
                if ($inline !== '' && !$isStop && !$isChoice) {
                    $lines[] = $inline;
                    $pending = false;
                }
            }
            continue;
        }
        if (!$pending && !$choice) {
            continue;
        }
        $lines[] = $line;
        if (!$choice) {
            $pending = false;
        }
    }

    return implode("\n", $lines);
}

/**
 * Wix-formulär med känd produkt. Produkten är kundens svar i brödtexten.
 * Ämne, formulärnamn och «Ring mig» utan produktord lämnas tomt.
 */
function jeab_inkorg_wix_known_product_offer(array $msg): string
{
    $body = jeab_inkorg_wix_body_text($msg);
    if ($body === '' || !str_contains(mb_strtolower($body), 'a site visitor just submitted your form')) {
        return '';
    }
    $answerText = jeab_inkorg_wix_answer_text($msg);
    if ($answerText === '') {
        return '';
    }
    $known = ['laddbox', 'batteri', 'sol', 'sol+batteri'];
    $answerMsg = ['subject' => '', 'body' => $answerText, 'body_text' => ''];
    if (function_exists('jeab_inkorg_form_quote_offer')) {
        $form = jeab_inkorg_form_quote_offer($answerMsg);
        if (in_array($form, $known, true)) {
            return $form;
        }
    }
    if (function_exists('jeab_inkorg_form_selected_answer') && function_exists('jeab_inkorg_form_is_sol_batteri')) {
        $picked = jeab_inkorg_form_selected_answer($answerText);
        if ($picked !== '' && jeab_inkorg_form_is_sol_batteri($picked)) {
            return 'sol+batteri';
        }
    }
    if (!function_exists('jeab_inkorg_product_token')) {
        return '';
    }
    $token = jeab_inkorg_product_token($answerText);
    if (in_array($token, ['laddbox', 'batteri', 'sol'], true)) {
        return $token;
    }

    return '';
}

/**
 * Wix-formuläret kontakta-oss / Kontakt Formulär. Support, inte en produktlead.
 * Ett laddbox-, batteri- eller solformulär räknas inte, även om texten nämner kontakt.
 */
function jeab_inkorg_wix_support_form(array $msg): bool
{
    if (jeab_inkorg_wix_known_product_offer($msg) !== '') {
        return false;
    }
    $title = jeab_inkorg_wix_form_title($msg);
    if ($title === '') {
        return false;
    }

    return preg_match('/kontakta[-\s]?oss|kontakt\s+formul/u', $title) === 1;
}

/** Oklassad på kontakta-oss blir Support. En satt typ (Ny lead, Information) lämnas. */
function jeab_inkorg_wix_support_apply(array $labels, array $msg): array
{
    if (!jeab_inkorg_wix_support_form($msg)) {
        return $labels;
    }
    $type = function_exists('jeab_inkorg_type_new') ? jeab_inkorg_type_new((string) ($labels['type'] ?? '')) : (string) ($labels['type'] ?? '');
    if ($type !== '' && $type !== 'Oklassad') {
        return $labels;
    }
    $labels['type'] = 'Support';
    $labels['intent'] = 'support';
    $labels['offer'] = '';
    unset($labels['lead']);
    $labels['action'] = function_exists('jeab_inkorg_derived_action') ? jeab_inkorg_derived_action('Support') : 'svara';
    $labels['priority'] = function_exists('jeab_inkorg_default_priority') ? jeab_inkorg_default_priority('Support') : 2;
    $labels['reason'] = 'Wix-formulär kontakta-oss';

    return $labels;
}

/**
 * Känd produkt på ett Wix-formulär är Ny lead när modellen är tyst eller under 70.
 * Ett säkert modellsvar och en manuell typ lämnas. Otydligt svar lämnas till granskning.
 */
function jeab_inkorg_wix_known_product_apply(array $labels, array $msg): array
{
    $source = (string) ($labels['source'] ?? '');
    if ($source === 'manual' || $source === 'test') {
        return $labels;
    }
    if (function_exists('jeab_inkorg_model_missed') && !jeab_inkorg_model_missed($labels)) {
        return $labels;
    }
    $offer = jeab_inkorg_wix_known_product_offer($msg);
    if ($offer === '') {
        return $labels;
    }
    $labels['type'] = 'Ny lead';
    $labels['intent'] = 'kop';
    $labels['offer'] = $offer;
    $labels['action'] = function_exists('jeab_inkorg_derived_action') ? jeab_inkorg_derived_action('Ny lead') : 'svara';
    $labels['priority'] = function_exists('jeab_inkorg_default_priority') ? jeab_inkorg_default_priority('Ny lead') : 1;
    $floor = function_exists('jeab_inkorg_review_min_confidence') ? jeab_inkorg_review_min_confidence() : 70;
    if ((int) ($labels['confidence'] ?? 0) < $floor) {
        $labels['confidence'] = $floor;
    }
    $labels['needs_review'] = false;
    $labels['reason'] = 'Wix-formulär med känd produkt';

    return $labels;
}

/** Bara frågan om mailoffert. «Produkt:» på ett köpt lead är inte det fältet. */
function jeab_inkorg_form_has_mailoffert_choice(array $msg): bool
{
    $chunks = [(string) ($msg['body'] ?? ''), (string) ($msg['body_text'] ?? ''), (string) ($msg['subject'] ?? '')];
    if (function_exists('jeab_inkorg_purchase_text')) {
        $chunks[] = jeab_inkorg_purchase_text($msg);
    }
    $blob = mb_strtolower(implode("\n", $chunks));

    return str_contains($blob, 'välj vad du vill ha information och mailoffert på');
}

/**
 * Svaret på «Välj vad du vill ha information och mailoffert på?» är en offertprodukt.
 * Formulärnamnet Kontakt Formulär räcker inte. Ett «Produkt:»-fält på Weply eller HemSol
 * lämnas, så omklassningen fortfarande kan göra Oklassad till Ny lead och skapa påminnelse.
 * Osäker Grok (under 70) lämnas till granskning.
 */
function jeab_inkorg_form_answer_apply(array $labels, array $msg): array
{
    if ((string) ($labels['source'] ?? '') === 'manual') {
        return $labels;
    }
    if (!jeab_inkorg_form_has_mailoffert_choice($msg)) {
        return $labels;
    }
    $type = function_exists('jeab_inkorg_type_new') ? jeab_inkorg_type_new((string) ($labels['type'] ?? '')) : (string) ($labels['type'] ?? '');
    if (in_array($type, ['Support', 'Service', 'Information', 'Skräp', 'Order'], true)) {
        return $labels;
    }
    $offer = function_exists('jeab_inkorg_form_offer_sure') ? jeab_inkorg_form_offer_sure($msg, $labels) : '';
    if ($offer === '') {
        return $labels;
    }
    $floor = function_exists('jeab_inkorg_review_min_confidence') ? jeab_inkorg_review_min_confidence() : 70;
    $labels['type'] = 'Ny lead';
    $labels['intent'] = 'kop';
    $labels['offer'] = $offer;
    $labels['action'] = function_exists('jeab_inkorg_derived_action') ? jeab_inkorg_derived_action('Ny lead') : 'svara';
    $labels['priority'] = function_exists('jeab_inkorg_default_priority') ? jeab_inkorg_default_priority('Ny lead') : 1;
    if ((int) ($labels['confidence'] ?? 0) < $floor) {
        $labels['confidence'] = $floor;
    }
    $labels['needs_review'] = false;
    $labels['reason'] = 'Formulär: vald produkt';

    return $labels;
}

function jeab_inkorg_klassning_lead_sender_body(array $msg): bool
{
    $from = jeab_inkorg_klassning_email((string) ($msg['from'] ?? ''));
    if ($from !== '' && function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($from)) {
        return false;
    }
    if (jeab_inkorg_klassning_wix_lead_summary($msg)) {
        return true;
    }
    $from = (string) ($msg['from'] ?? '');
    if (function_exists('jeab_lead_platform_lead_only') && jeab_lead_platform_lead_only($from) !== '') {
        return true;
    }
    if (function_exists('jeab_lead_seller_match') && jeab_lead_seller_match($from) !== '') {
        return true;
    }
    $domain = function_exists('jeab_lead_email_domain') ? jeab_lead_email_domain($from) : '';
    if ($domain !== '' && str_contains($domain, 'kalkyl')) {
        return true;
    }
    $hay = jeab_inkorg_klassning_lead_hay($msg);
    foreach ([
        'hitta-solceller', 'hitta solceller', 'hittasolceller', 'offerta',
        'solöversikt', 'soloversikt', 'solceller.nu', 'hemsol', 'nordsolen',
        'leadkit', 'bra byggfirmor', 'brabyggfirmor', 'servicefinder',
        'byggahus', 'omtago', 'weply',
    ] as $needle) {
        if (str_contains($hay, $needle)) {
            return true;
        }
    }

    return false;
}

function jeab_inkorg_klassning_lead_offer(array $msg): string
{
    if (!jeab_inkorg_klassning_lead_sender($msg)) {
        return '';
    }
    $known = ['laddbox', 'batteri', 'sol', 'sol+batteri'];
    if (function_exists('jeab_inkorg_purchase_offer_detail')) {
        $offer = (string) (jeab_inkorg_purchase_offer_detail($msg)['offer'] ?? '');
        if (in_array($offer, $known, true)) {
            return $offer;
        }
    }
    if (function_exists('jeab_lead_infer_offer')) {
        $offer = jeab_lead_infer_offer($msg, []);
        if (function_exists('jeab_inkorg_force_offer')) {
            $offer = jeab_inkorg_force_offer($offer);
        }
        if (in_array($offer, $known, true)) {
            return $offer;
        }
    }
    return '';
}

/**
 * Egen domän, personal, bolagsnamn eller testavsändare. Köpt lead (kalkyl) räknas inte.
 */
function jeab_inkorg_klassning_own(array $msg): bool
{
    return jeab_inkorg_klassning_cached('own', $msg, jeab_inkorg_klassning_own_body(...));
}

function jeab_inkorg_klassning_own_body(array $msg): bool
{
    static $cache = [];
    $from = jeab_inkorg_klassning_email((string) ($msg['from'] ?? ''));
    // Omtago-webhook (omc* eller source omtago) är kundens lead. Testnamn gör det inte till egen post.
    // Personalens egen adress är fortfarande vår.
    if (jeab_inkorg_klassning_omtago_msg($msg)) {
        return $from !== '' && function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($from);
    }
    if ($from !== '' && function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($from)) {
        return true;
    }
    if (jeab_inkorg_klassning_wix_lead_summary($msg) || jeab_inkorg_klassning_lead_sender($msg)) {
        return false;
    }
    if ($from !== '' && array_key_exists($from, $cache)) {
        return $cache[$from];
    }
    $own = ($from !== '' && jeab_inkorg_klassning_own_email($from))
        || jeab_inkorg_klassning_staff_name((string) ($msg['from_name'] ?? ''))
        || jeab_inkorg_klassning_test_sender($msg);
    if ($from !== '') {
        $cache[$from] = $own;
    }

    return $own;
}

/** Personaladress, en gång per adress och request. */
function jeab_inkorg_klassning_own_email(string $email): bool
{
    static $cache = [];
    if (array_key_exists($email, $cache)) {
        return $cache[$email];
    }
    $cache[$email] = jeab_inkorg_klassning_staff_address($email);

    return $cache[$email];
}

/**
 * Domäner, adresser och namn för personal. Byggs en gång per request.
 *
 * @return array{domains: array<string, true>, emails: array<string, true>, names: array<string, true>}
 */
function jeab_inkorg_klassning_staff_sets(): array
{
    static $sets = null;
    if ($sets !== null) {
        return $sets;
    }
    $GLOBALS['_JEAB_KLASSNING_STAFF_BUILDS'] = (int) ($GLOBALS['_JEAB_KLASSNING_STAFF_BUILDS'] ?? 0) + 1;
    $domains = [
        'jarfalla.example.test' => true,
        'malmo.example.test' => true,
        'flowstack.example.test' => true,
    ];
    $emails = [];
    $names = [
        'sam Demo' => true,
        'sam l' => true,
    ];
    $addDomain = static function (string $domain) use (&$domains): void {
        $domain = mb_strtolower(trim($domain));
        if ($domain !== '' && !str_contains($domain, '@') && !str_contains($domain, '/')) {
            $domains[$domain] = true;
        }
    };
    // Bara den exakta adressen. demo.person@example.invalid gör inte hela gmail.com till personal.
    $addEmail = static function (string $email) use (&$emails): void {
        $email = jeab_inkorg_klassning_email($email);
        if ($email === '' || !str_contains($email, '@')) {
            return;
        }
        $emails[$email] = true;
    };
    if (function_exists('jeab_company_catalog')) {
        foreach (jeab_company_catalog() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $addDomain((string) ($row['domain'] ?? ''));
        }
    }
    foreach (jeab_inkorg_klassning_company_name_keys() as $mail) {
        if ($mail !== '') {
            $names[$mail] = true;
        }
    }
    if (function_exists('jeab_system_mail_sender_domains')) {
        foreach (jeab_system_mail_sender_domains() as $list) {
            foreach ((array) $list as $domain) {
                $addDomain((string) $domain);
            }
        }
    }
    if (function_exists('jeab_people')) {
        foreach (jeab_people() as $person) {
            if (!is_array($person)) {
                continue;
            }
            $addEmail((string) ($person['email'] ?? ''));
            $personName = jeab_inkorg_klassning_name_key((string) ($person['name'] ?? ''));
            if ($personName !== '') {
                $names[$personName] = true;
            }
        }
    }
    if (function_exists('jeab_gp_staff_emails')) {
        foreach (array_keys(jeab_gp_staff_emails()) as $email) {
            $addEmail((string) $email);
        }
    }
    if (function_exists('jeab_system_mail_address')) {
        foreach (['jarfalla', 'malmo'] as $company) {
            $addEmail(jeab_system_mail_address($company));
        }
    }
    if (function_exists('jeab_auto_selftest_addresses')) {
        foreach (jeab_auto_selftest_addresses() as $one) {
            $addEmail((string) $one);
        }
    }
    $sets = ['domains' => $domains, 'emails' => $emails, 'names' => $names];

    return $sets;
}

/** Domäner från bolagskatalogen, plus systemmejlets avsändardomäner. */
function jeab_inkorg_klassning_staff_domains(): array
{
    return array_keys(jeab_inkorg_klassning_staff_sets()['domains']);
}

/** @return array<string, true> */
function jeab_inkorg_klassning_staff_email_set(): array
{
    return jeab_inkorg_klassning_staff_sets()['emails'];
}

function jeab_inkorg_klassning_email(string $email): string
{
    $email = trim($email);
    if (preg_match('/<([^>]+)>/', $email, $m) === 1) {
        $email = trim($m[1]);
    }

    return mb_strtolower($email);
}

function jeab_inkorg_klassning_staff_address(string $email): bool
{
    $email = jeab_inkorg_klassning_email($email);
    if ($email === '' || !str_contains($email, '@')) {
        return false;
    }
    if (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address($email)) {
        return true;
    }
    $sets = jeab_inkorg_klassning_staff_sets();
    if (isset($sets['emails'][$email])) {
        return true;
    }
    $domain = (string) substr(strrchr($email, '@') ?: '', 1);

    return $domain !== '' && isset($sets['domains'][$domain]);
}

function jeab_inkorg_klassning_name_key(string $name): string
{
    $name = mb_strtolower(trim($name));
    $name = strtr($name, ['ä' => 'a', 'å' => 'a', 'ö' => 'o', 'é' => 'e']);
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

    return trim($name);
}

/** Bolagsnamn från katalogen, plus varianten med AB. Bara exakt träff. */
function jeab_inkorg_klassning_company_name_keys(): array
{
    if (isset($GLOBALS['_JEAB_KLASSNING_COMPANY_KEYS']) && is_array($GLOBALS['_JEAB_KLASSNING_COMPANY_KEYS'])) {
        return $GLOBALS['_JEAB_KLASSNING_COMPANY_KEYS'];
    }
    $keys = [];
    if (function_exists('jeab_company_catalog')) {
        foreach (jeab_company_catalog() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mail = jeab_inkorg_klassning_name_key((string) ($row['mail_name'] ?? ''));
            if ($mail === '') {
                continue;
            }
            $keys[] = $mail;
            $keys[] = $mail . ' ab';
        }
    }

    $keys = array_values(array_unique($keys));
    $GLOBALS['_JEAB_KLASSNING_COMPANY_KEYS'] = $keys;

    return $keys;
}

/** @return array<string, true> */
function jeab_inkorg_klassning_staff_name_set(): array
{
    return jeab_inkorg_klassning_staff_sets()['names'];
}

function jeab_inkorg_klassning_staff_name(string $name): bool
{
    $key = jeab_inkorg_klassning_name_key($name);
    if ($key === '') {
        return false;
    }

    return isset(jeab_inkorg_klassning_staff_sets()['names'][$key]);
}

function jeab_inkorg_klassning_test_sender(array $msg): bool
{
    $name = (string) ($msg['from_name'] ?? '');
    if (function_exists('jeab_test_lead_name_reason') && jeab_test_lead_name_reason($name) !== '') {
        return true;
    }
    $from = (string) ($msg['from'] ?? '');

    return function_exists('jeab_test_lead_email_reason') && jeab_test_lead_email_reason($from) !== '';
}

/** Avdelningsnamn hos en leverantör, till exempel Export Sales. Ingen kundfråga. */
function jeab_inkorg_klassning_supplier_name(string $name): bool
{
    return preg_match('/\b(?:export|sales|inköp|inkop|purchasing|procurement|order(?:desk)?|invoice|faktura|accounts|finance|ekonomi)\b/iu', $name) === 1;
}

function jeab_inkorg_klassning_supplier_quiet(array $msg): bool
{
    return jeab_inkorg_klassning_cached('quiet', $msg, jeab_inkorg_klassning_supplier_quiet_body(...));
}

function jeab_inkorg_klassning_supplier_quiet_body(array $msg): bool
{
    if (jeab_inkorg_klassning_own($msg) || jeab_inkorg_klassning_lead_sender($msg) || jeab_inkorg_klassning_backorder($msg)) {
        return false;
    }
    if (!jeab_inkorg_klassning_supplier_name((string) ($msg['from_name'] ?? ''))) {
        return false;
    }

    return preg_match('/defekt|återmonter|atermonter|trasig|fungerar\s+inte/iu', jeab_inkorg_klassning_blob($msg)) !== 1;
}

/**
 * Nyhetsbrev utan modellklass: List-Unsubscribe, avregistreringslänk, massutskick eller no-reply.
 * Köpt lead, privatperson, egen avsändare, restnoterat, kvitto och orderbekräftelse ingår inte.
 */
function jeab_inkorg_klassning_newsletter(array $msg): bool
{
    if (jeab_inkorg_klassning_lead_sender($msg) || jeab_inkorg_klassning_own($msg) || jeab_inkorg_klassning_supplier_quiet($msg) || jeab_inkorg_klassning_private_person($msg)) {
        return false;
    }
    if (jeab_inkorg_klassning_backorder($msg)) {
        return false;
    }
    if (function_exists('jeab_inkorg_is_fortnox_system') && jeab_inkorg_is_fortnox_system($msg)) {
        return false;
    }
    if (function_exists('jeab_inkorg_order_confirmation')) {
        $order = jeab_inkorg_order_confirmation($msg);
        if (!empty($order['confirm'])) {
            return false;
        }
    }
    if (function_exists('jeab_lead_is_store_mail') && jeab_lead_is_store_mail($msg)) {
        return false;
    }
    if (function_exists('jeab_inkorg_purchase_bulk') && jeab_inkorg_purchase_bulk($msg)) {
        return true;
    }
    $from = (string) ($msg['from'] ?? '');
    if (function_exists('jeab_inkorg_purchase_noreply') && jeab_inkorg_purchase_noreply($from)) {
        return true;
    }
    if (function_exists('jeab_lead_noreply_local') && jeab_lead_noreply_local($from)) {
        return true;
    }

    return preg_match('/\b(?:unsubscribe|avregistrera|avprenumerera|avsluta prenumeration|opt[- ]?out|list-unsubscribe)\b/iu', jeab_inkorg_klassning_blob($msg)) === 1;
}

/** Skriver om etiketten på ett redan sparat mejl. Ingen JSON-fil här. */
function jeab_inkorg_klassning_stamp(string $mid, string $type, string $intent, string $offer, bool $forceOffer = false): void
{
    if ($mid === '' || !function_exists('jeab_inkorg_update')) {
        return;
    }
    jeab_inkorg_update($mid, static function (array $message) use ($type, $intent, $offer, $forceOffer): array {
        $labels = (array) ($message['labels'] ?? []);
        if ($type !== '') {
            $labels['type'] = $type;
        }
        if ($intent !== '') {
            $labels['intent'] = $intent;
        }
        if ($offer !== '' || $forceOffer) {
            $labels['offer'] = $offer;
        }
        if ($type === 'Skräp' || $type === 'Ny lead') {
            $labels['confidence'] = max(90, (int) ($labels['confidence'] ?? 0));
            $labels['needs_review'] = false;
        }
        $message['labels'] = $labels;

        return $message;
    });
}

/** Köpt lead blir Ny lead med offert, så befintlig offertväg kan ta vid. */
function jeab_inkorg_klassning_as_lead(array $msg): array
{
    if (!jeab_inkorg_klassning_lead_sender($msg)) {
        return $msg;
    }
    $labels = (array) ($msg['labels'] ?? []);
    $own = function_exists('jeab_lead_message_is_own') && jeab_lead_message_is_own($msg);
    $leadKind = $own ? 'eget' : 'kopt';
    $src = (string) ($labels['source'] ?? '');
    $offer = jeab_inkorg_klassning_lead_offer($msg);
    $grokProduct = function_exists('jeab_lead_grok_product_exclusive') ? jeab_lead_grok_product_exclusive($labels) : '';
    if ($grokProduct !== '' && $offer !== $grokProduct) {
        $offer = $grokProduct;
    }
    $typeNow = (string) ($labels['type'] ?? '');
    $silent = $src === 'llm' || (function_exists('jeab_inkorg_model_missed') && jeab_inkorg_model_missed($labels));
    $canFill = $offer !== '' && in_array($typeNow, ['', 'Oklassad', 'Ny lead'], true);
    $already = $typeNow === 'Ny lead' && (string) ($labels['intent'] ?? '') === 'kop' && (string) ($labels['offer'] ?? '') === $offer && (string) ($labels['lead'] ?? '') === $leadKind;
    if ($silent && (!$canFill || $already)) {
        if ($own && (string) ($labels['lead'] ?? '') !== 'eget') {
            $labels['lead'] = 'eget';
            $msg['labels'] = $labels;
            $mid = (string) ($msg['id'] ?? '');
            if ($mid !== '' && function_exists('jeab_inkorg_update')) {
                jeab_inkorg_update($mid, static function (array $message): array {
                    $stored = (array) ($message['labels'] ?? []);
                    $stored['lead'] = 'eget';
                    $message['labels'] = $stored;

                    return $message;
                });
            }
        }

        return $msg;
    }
    if ((string) ($labels['type'] ?? '') === 'Ny lead' && (string) ($labels['intent'] ?? '') === 'kop' && $offer !== '' && (string) ($labels['offer'] ?? '') === $offer && (string) ($labels['lead'] ?? '') === $leadKind) {
        return $msg;
    }
    $labels['type'] = 'Ny lead';
    $labels['intent'] = 'kop';
    $labels['lead'] = $leadKind;
    if ($offer !== '') {
        $labels['offer'] = $offer;
    }
    $labels['confidence'] = max(90, (int) ($labels['confidence'] ?? 0));
    $labels['needs_review'] = false;
    $msg['labels'] = $labels;
    $mid = (string) ($msg['id'] ?? '');
    jeab_inkorg_klassning_stamp($mid, 'Ny lead', 'kop', $offer);
    if ($mid !== '' && function_exists('jeab_inkorg_update')) {
        jeab_inkorg_update($mid, static function (array $message) use ($leadKind): array {
            $stored = (array) ($message['labels'] ?? []);
            $stored['lead'] = $leadKind;
            $message['labels'] = $stored;

            return $message;
        });
    }

    return $msg;
}

function jeab_inkorg_klassning_view_name(array $thread): string
{
    if ((string) ($thread['status'] ?? '') === 'closed' || jeab_inkorg_in_historik($thread)) {
        return 'historik';
    }
    if (jeab_inkorg_folder_thread($thread, 'information')) {
        return 'information';
    }
    if (jeab_inkorg_folder_thread($thread, 'service')) {
        return 'service';
    }
    if (jeab_inkorg_folder_thread($thread, 'inkorg')) {
        return 'inkorg';
    }

    return 'alla';
}

function jeab_inkorg_klassning_thread_time(array $thread): int
{
    $best = 0;
    foreach (['updated_at', 'created_at'] as $key) {
        $ts = strtotime((string) ($thread[$key] ?? ''));
        if ($ts !== false && $ts > $best) {
            $best = $ts;
        }
    }
    foreach ((array) ($thread['messages'] ?? []) as $message) {
        if (!is_array($message)) {
            continue;
        }
        $ts = strtotime((string) ($message['received_at'] ?? $message['date'] ?? ''));
        if ($ts !== false && $ts > $best) {
            $best = $ts;
        }
    }

    return $best;
}

function jeab_inkorg_klassning_reclassify_dry(array $in): bool
{
    if (!array_key_exists('dry', $in)) {
        return true;
    }
    $dry = $in['dry'];

    return !($dry === false || $dry === 0 || $dry === '0' || $dry === 0.0);
}

function jeab_inkorg_klassning_reclassify_days(array $in): int
{
    if (!array_key_exists('days', $in) || $in['days'] === '' || $in['days'] === null) {
        return 30;
    }
    $n = (int) $in['days'];
    if ($n < 1) {
        return 30;
    }

    return min(90, $n);
}

function jeab_inkorg_klassning_reclassify_company(array $in): string
{
    $company = mb_strtolower(trim((string) ($in['company'] ?? '')));
    if ($company === 'meab') {
        return 'malmo';
    }
    if (in_array($company, ['', 'jarfalla', 'malmo'], true)) {
        return $company;
    }

    return '';
}

/**
 * @return array{status:string,type:string,intent:string,offer:string,via:string,remind:bool}|null
 */
/** Webhook-lead från Omtago. Tråd-id omc* eller källa omtago. Aldrig egen post. */
function jeab_inkorg_klassning_omtago_msg(array $msg): bool
{
    if ((string) ($msg['source'] ?? '') === 'omtago') {
        return true;
    }
    foreach (['thread_id', 'id'] as $key) {
        if (str_starts_with((string) ($msg[$key] ?? ''), 'omc')) {
            return true;
        }
    }

    return false;
}

function jeab_inkorg_klassning_omtago_lead(array $thread, array $msg): bool
{
    if (str_starts_with((string) ($thread['id'] ?? ''), 'omc')) {
        return true;
    }
    if ((string) ($thread['lead_source'] ?? '') === 'omtago' || (string) ($thread['source'] ?? '') === 'omtago') {
        return true;
    }
    if (jeab_inkorg_klassning_omtago_msg($msg)) {
        return true;
    }
    foreach ((array) ($thread['messages'] ?? []) as $message) {
        if (is_array($message) && jeab_inkorg_klassning_omtago_msg($message)) {
            return true;
        }
    }

    return false;
}

/**
 * Påminnelse kopplad till ärendet. E-postträff på en annan kund räknas inte.
 *
 * @return array<string, true>
 */
function jeab_inkorg_klassning_reclassify_reminder_ids(): array
{
    if (isset($GLOBALS['_JEAB_KLASSNING_REMINDER_IDS']) && is_array($GLOBALS['_JEAB_KLASSNING_REMINDER_IDS'])) {
        return $GLOBALS['_JEAB_KLASSNING_REMINDER_IDS'];
    }
    $ids = [];
    if (function_exists('jeab_list_reminders') && function_exists('jeab_store')) {
        try {
            foreach (jeab_list_reminders(jeab_store(), true) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $tid = trim((string) ($row['thread_id'] ?? ''));
                if ($tid !== '') {
                    $ids[$tid] = true;
                }
            }
        } catch (Throwable $e) {
            unset($e);
        }
    }
    $GLOBALS['_JEAB_KLASSNING_REMINDER_IDS'] = $ids;

    return $ids;
}

function jeab_inkorg_klassning_reclassify_protected(array $thread): bool
{
    if (function_exists('jeab_inkorg_quote_sent_in_thread') && jeab_inkorg_quote_sent_in_thread($thread)) {
        return true;
    }
    $tid = (string) ($thread['id'] ?? '');

    return $tid !== '' && isset(jeab_inkorg_klassning_reclassify_reminder_ids()[$tid]);
}

/**
 * inkorg.reclassify får inte stänga ett öppet ärende.
 * Produkt och ansvarig rörs inte när offert är skickad eller en påminnelse finns.
 * Det som får göras är Skräp eller Oklassad → Ny lead, och ärendet lämnas öppet.
 * Omtago omc* klassas aldrig som egen post.
 *
 * @param array{status:string,type:string,intent:string,offer:string,via:string,remind:bool} $decision
 * @return array{status:string,type:string,intent:string,offer:string,via:string,remind:bool}|null
 */
function jeab_inkorg_klassning_reclassify_guard(array $thread, array $msg, array $decision): ?array
{
    $labels = (array) ($msg['labels'] ?? []);
    $oldType = trim((string) ($labels['type'] ?? ''));
    if ($oldType === '') {
        $oldType = 'Oklassad';
    }
    $oldOffer = (string) ($labels['offer'] ?? '');
    $oldIntent = (string) ($labels['intent'] ?? '');
    $open = (string) ($thread['status'] ?? '') === 'open';
    $upgrade = in_array($oldType, ['Skräp', 'Oklassad'], true) && (string) ($decision['type'] ?? '') === 'Ny lead';
    $protected = jeab_inkorg_klassning_reclassify_protected($thread);
    if (jeab_inkorg_klassning_omtago_lead($thread, $msg) && (string) ($decision['via'] ?? '') === 'egen') {
        return null;
    }
    if ($open && (string) ($decision['status'] ?? '') === 'closed' && !$upgrade) {
        return null;
    }
    if ($open && $upgrade) {
        $decision['status'] = 'open';
        $decision['via'] = 'kop-oppen';
    }
    if ($protected) {
        $decision['offer'] = $oldOffer;
        $decision['remind'] = false;
        if (!$upgrade) {
            $decision['type'] = trim((string) ($labels['type'] ?? ''));
            $decision['intent'] = $oldIntent;
            if ($open) {
                $decision['status'] = 'open';
            }
        }
    }

    return $decision;
}

function jeab_inkorg_klassning_decide(array $thread, array $msg): ?array
{
    $route = jeab_inkorg_klassning_route($msg);
    if ($route === 'egen' && jeab_inkorg_klassning_omtago_lead($thread, $msg)) {
        $route = 'inkorg';
    }
    $labels = (array) ($msg['labels'] ?? []);
    $type = trim((string) ($labels['type'] ?? ''));
    $closed = (string) ($thread['status'] ?? '') === 'closed' || jeab_inkorg_in_historik($thread);
    if ($route === 'skrap') {
        return ['status' => 'closed', 'type' => 'Skräp', 'intent' => 'skrap', 'offer' => (string) ($labels['offer'] ?? ''), 'via' => 'skrap', 'remind' => false];
    }
    if ($route === 'kop') {
        $offer = jeab_inkorg_klassning_lead_sender($msg) ? jeab_inkorg_klassning_lead_offer($msg) : jeab_inkorg_klassning_kop_offer($msg);
        if ($offer === '') {
            $offer = jeab_inkorg_klassning_kop_offer($msg);
        }

        return ['status' => 'closed', 'type' => 'Ny lead', 'intent' => 'kop', 'offer' => $offer, 'via' => 'kop-omklass', 'remind' => true];
    }
    if ($route === 'egen') {
        return ['status' => 'closed', 'type' => 'Oklassad', 'intent' => '', 'offer' => '', 'via' => 'egen', 'remind' => false];
    }
    if ($route === 'information' && jeab_inkorg_klassning_supplier_quiet($msg)) {
        return ['status' => 'open', 'type' => 'Information', 'intent' => 'information', 'offer' => '', 'via' => 'information', 'remind' => false];
    }
    if ($route === 'information') {
        // Redan stängd historik öppnas inte igen. Öppen information ligger kvar i mappen Information.
        if ($closed) {
            return null;
        }

        return ['status' => 'open', 'type' => 'Information', 'intent' => 'information', 'offer' => '', 'via' => 'information', 'remind' => false];
    }
    if ($closed && !in_array($type, ['Information', ''], true)) {
        return null;
    }
    if ($closed && !jeab_inkorg_klassning_customer_mail($msg)) {
        return null;
    }

    return ['status' => 'open', 'type' => $type, 'intent' => (string) ($labels['intent'] ?? ''), 'offer' => (string) ($labels['offer'] ?? ''), 'via' => '', 'remind' => false];
}

function jeab_inkorg_klassning_log_pdo(): ?PDO
{
    try {
        $store = function_exists('jeab_store') ? jeab_store() : null;
    } catch (Throwable $e) {
        unset($e);

        return null;
    }
    if (!$store instanceof JeabPdoStore) {
        return null;
    }
    $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
    if (function_exists('jeab_ensure_inbox_klassning_log')) {
        jeab_ensure_inbox_klassning_log($store->pdo(), $driver);
    }

    return $store->pdo();
}

function jeab_inkorg_klassning_log_write(string $batch, array $row): void
{
    $pdo = jeab_inkorg_klassning_log_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO inbox_klassning_log (created_at, batch, thread_id, message_id, old_status, new_status, old_type, new_type, old_intent, new_intent, old_offer, new_offer, old_hook, new_hook, old_view, new_view, reminder_id, dry, undone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)'
    );
    $stmt->execute([
        date('Y-m-d H:i:s'),
        mb_substr($batch, 0, 40),
        mb_substr((string) ($row['thread_id'] ?? ''), 0, 64),
        mb_substr((string) ($row['message_id'] ?? ''), 0, 64),
        mb_substr((string) ($row['old_status'] ?? ''), 0, 32),
        mb_substr((string) ($row['new_status'] ?? ''), 0, 32),
        mb_substr((string) ($row['old_type'] ?? ''), 0, 64),
        mb_substr((string) ($row['new_type'] ?? ''), 0, 64),
        mb_substr((string) ($row['old_intent'] ?? ''), 0, 32),
        mb_substr((string) ($row['new_intent'] ?? ''), 0, 32),
        mb_substr((string) ($row['old_offer'] ?? ''), 0, 32),
        mb_substr((string) ($row['new_offer'] ?? ''), 0, 32),
        mb_substr((string) ($row['old_hook'] ?? ''), 0, 64),
        mb_substr((string) ($row['new_hook'] ?? ''), 0, 64),
        mb_substr((string) ($row['old_view'] ?? ''), 0, 32),
        mb_substr((string) ($row['new_view'] ?? ''), 0, 32),
        (int) ($row['reminder_id'] ?? 0),
        !empty($row['dry']) ? 1 : 0,
    ]);
}

function jeab_inkorg_klassning_log_reminder(string $batch, string $tid, int $reminderId): void
{
    $pdo = jeab_inkorg_klassning_log_pdo();
    if (!$pdo instanceof PDO || $reminderId < 1 || $tid === '') {
        return;
    }
    $stmt = $pdo->prepare('UPDATE inbox_klassning_log SET reminder_id = ? WHERE batch = ? AND thread_id = ? AND dry = 0 AND undone = 0');
    $stmt->execute([$reminderId, $batch, $tid]);
}

/**
 * inkorg.reclassify. dry=1 (standard) och days=30.
 * dry=0 stänger aldrig ett öppet ärende och byter inte produkt eller ansvarig
 * när offert är skickad eller en påminnelse finns. Skräp/Oklassad får bli Ny lead och lämnas öppet.
 * Inget utskick.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
/** Spärr runt omklassning. Släpps i finally. Påminnelse får skapas, inget utskick. */
function jeab_inkorg_klassning_no_send(?bool $on = null): bool
{
    if ($on === true) {
        $GLOBALS['_JEAB_KLASSNING_NO_SEND'] = (int) ($GLOBALS['_JEAB_KLASSNING_NO_SEND'] ?? 0) + 1;

        return true;
    }
    if ($on === false) {
        $left = (int) ($GLOBALS['_JEAB_KLASSNING_NO_SEND'] ?? 0) - 1;
        if ($left <= 0) {
            unset($GLOBALS['_JEAB_KLASSNING_NO_SEND']);

            return false;
        }
        $GLOBALS['_JEAB_KLASSNING_NO_SEND'] = $left;

        return true;
    }

    return (int) ($GLOBALS['_JEAB_KLASSNING_NO_SEND'] ?? 0) > 0;
}

function jeab_inkorg_klassning_reclassify(array $in): array
{
    $dry = jeab_inkorg_klassning_reclassify_dry($in);
    if (!$dry) {
        jeab_inkorg_klassning_no_send(true);
    }
    try {
        return jeab_inkorg_klassning_reclassify_body($in);
    } finally {
        if (!$dry) {
            jeab_inkorg_klassning_no_send(false);
        }
    }
}

function jeab_inkorg_klassning_reclassify_body(array $in): array
{
    $dry = jeab_inkorg_klassning_reclassify_dry($in);
    $days = jeab_inkorg_klassning_reclassify_days($in);
    $company = jeab_inkorg_klassning_reclassify_company($in);
    $cut = time() - $days * 86400;
    $batch = date('YmdHis') . bin2hex(random_bytes(3));
    $rows = [];
    $changed = 0;
    unset($GLOBALS['_JEAB_KLASSNING_KNOWN'], $GLOBALS['_JEAB_KLASSNING_CONTACT_SQL'], $GLOBALS['_JEAB_KLASSNING_REMINDER_IDS']);
    if (!function_exists('jeab_inkorg_threads')) {
        return ['ok' => false, 'error' => 'ingen inkorg', 'dry' => $dry ? 1 : 0, 'days' => $days, 'company' => $company, 'rows' => [], 'changed' => 0];
    }
    $prefetch = [];
    foreach (jeab_inkorg_threads() as $thread) {
        if (is_array($thread)) {
            $prefetch[] = $thread;
        }
    }
    jeab_inkorg_klassning_contacts_prefetch($prefetch, false);
    foreach ($prefetch as $thread) {
        if (!is_array($thread) || !empty($thread['deleted'])) {
            continue;
        }
        $status = (string) ($thread['status'] ?? '');
        if (!in_array($status, ['open', 'closed'], true)) {
            continue;
        }
        $when = jeab_inkorg_klassning_thread_time($thread);
        if ($when < $cut) {
            continue;
        }
        if ($company !== '' && function_exists('jeab_inkorg_thread_company') && jeab_inkorg_thread_company($thread) !== $company) {
            continue;
        }
        $msg = jeab_inkorg_klassning_face($thread);
        if ($msg === []) {
            continue;
        }
        $labels = (array) ($msg['labels'] ?? []);
        if ((string) ($labels['source'] ?? '') === 'manual') {
            continue;
        }
        $decision = jeab_inkorg_klassning_decide($thread, $msg);
        if ($decision === null) {
            continue;
        }
        $decision = jeab_inkorg_klassning_reclassify_guard($thread, $msg, $decision);
        if ($decision === null) {
            continue;
        }
        $oldType = trim((string) ($labels['type'] ?? ''));
        $oldIntent = (string) ($labels['intent'] ?? '');
        $oldOffer = (string) ($labels['offer'] ?? '');
        $oldView = jeab_inkorg_klassning_view_name($thread);
        $projected = $thread;
        $projected['status'] = $decision['status'];
        $projectedLabels = (array) ($projected['labels'] ?? []);
        if ((string) $decision['type'] !== '') {
            $projectedLabels['type'] = (string) $decision['type'];
        }
        $projected['labels'] = $projectedLabels;
        if ($decision['status'] === 'open') {
            unset($projected['hook_closed']);
        }
        $newView = jeab_inkorg_klassning_view_name($projected);
        $same = $status === $decision['status']
            && $oldType === $decision['type']
            && $oldIntent === $decision['intent']
            && $oldOffer === (string) $decision['offer']
            && $oldView === $newView;
        if ($same) {
            continue;
        }
        $tid = (string) ($thread['id'] ?? '');
        $mid = (string) ($msg['id'] ?? '');
        $oldHook = '';
        if (function_exists('jeab_inkorg_thread_meta')) {
            $oldHook = (string) (jeab_inkorg_thread_meta($tid)['hook_closed'] ?? '');
        }
        $row = [
            'thread_id' => $tid,
            'message_id' => $mid,
            'old' => $oldView,
            'new' => $newView,
            'old_status' => $status,
            'new_status' => $decision['status'],
            'old_type' => $oldType === '' ? 'Oklassad' : $oldType,
            'new_type' => $decision['type'] === '' ? 'Oklassad' : $decision['type'],
            'old_intent' => $oldIntent,
            'new_intent' => $decision['intent'],
            'old_offer' => $oldOffer,
            'new_offer' => (string) $decision['offer'],
            'old_hook' => $oldHook,
            'new_hook' => $decision['status'] === 'closed' ? (string) $decision['via'] : '',
            'old_view' => $oldView,
            'new_view' => $newView,
            'route' => jeab_inkorg_klassning_route($msg),
            'would_send' => false,
            'would_remind' => false,
            'remind' => !empty($decision['remind']) ? 1 : 0,
            'dry' => $dry ? 1 : 0,
            'reminder_id' => 0,
        ];
        $rows[] = $row;
        if ($dry) {
            continue;
        }
        jeab_inkorg_klassning_log_write($batch, $row);
        $changed++;
        if ($decision['type'] !== $oldType || $decision['intent'] !== $oldIntent || (string) $decision['offer'] !== $oldOffer) {
            jeab_inkorg_klassning_stamp($mid, (string) $decision['type'], (string) $decision['intent'], (string) $decision['offer'], true);
        }
        if ($decision['status'] === 'closed' && $status !== 'closed') {
            jeab_inkorg_klassning_close($tid, (string) $decision['via']);
        } elseif ($decision['status'] === 'open' && $status !== 'open') {
            jeab_inkorg_thread_write($tid, static function (array $t): array {
                $t['status'] = 'open';
                unset($t['hook_closed']);
                $t['hook_reopened_at'] = date('c');

                return $t;
            });
        }
        if (!empty($decision['remind']) && function_exists('jeab_lead_process') && function_exists('jeab_inkorg_threads')) {
            $fresh = null;
            foreach (jeab_inkorg_threads() as $again) {
                if (is_array($again) && (string) ($again['id'] ?? '') === $tid) {
                    $fresh = $again;
                    break;
                }
            }
            if (is_array($fresh)) {
                try {
                    $lead = jeab_lead_process($fresh, true);
                    $reminderId = (int) ($lead['reminder'] ?? 0);
                    if ($reminderId > 0 && !empty($lead['reminder_created'])) {
                        jeab_inkorg_klassning_log_reminder($batch, $tid, $reminderId);
                    }
                } catch (Throwable $e) {
                    unset($e);
                }
            }
        }
    }

    return [
        'ok' => true,
        'dry' => $dry ? 1 : 0,
        'days' => $days,
        'company' => $company,
        'batch' => $dry ? '' : $batch,
        'rows' => $rows,
        'changed' => $dry ? 0 : $changed,
        'sent' => 0,
    ];
}

/**
 * Ångrar senaste wet-körningen av inkorg.reclassify. Skickar inget.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_inkorg_klassning_reclassify_undo(array $in): array
{
    jeab_inkorg_klassning_no_send(true);
    try {
        return jeab_inkorg_klassning_reclassify_undo_body($in);
    } finally {
        jeab_inkorg_klassning_no_send(false);
    }
}

function jeab_inkorg_klassning_reclassify_undo_body(array $in): array
{
    $pdo = jeab_inkorg_klassning_log_pdo();
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'ingen logg', 'undone' => 0];
    }
    $batch = trim((string) ($in['batch'] ?? ''));
    if ($batch === '') {
        $found = $pdo->query('SELECT batch FROM inbox_klassning_log WHERE dry = 0 AND undone = 0 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $batch = is_array($found) ? (string) ($found['batch'] ?? '') : '';
    }
    if ($batch === '') {
        return ['ok' => true, 'batch' => '', 'undone' => 0, 'sent' => 0];
    }
    $stmt = $pdo->prepare('SELECT * FROM inbox_klassning_log WHERE batch = ? AND dry = 0 AND undone = 0 ORDER BY id ASC');
    $stmt->execute([$batch]);
    $n = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tid = (string) ($row['thread_id'] ?? '');
        $mid = (string) ($row['message_id'] ?? '');
        $oldType = (string) ($row['old_type'] ?? '');
        if ($mid !== '') {
            jeab_inkorg_klassning_stamp($mid, $oldType, (string) ($row['old_intent'] ?? ''), (string) ($row['old_offer'] ?? ''), true);
        }
        $oldStatus = (string) ($row['old_status'] ?? 'open');
        $oldHook = (string) ($row['old_hook'] ?? '');
        if ($tid !== '' && in_array($oldStatus, ['open', 'closed'], true)) {
            jeab_inkorg_thread_write($tid, static function (array $t) use ($oldStatus, $oldHook): array {
                $t['status'] = $oldStatus;
                if ($oldHook === '') {
                    unset($t['hook_closed']);
                } else {
                    $t['hook_closed'] = $oldHook;
                }
                if ($oldStatus === 'open') {
                    unset($t['hook_reopened_at']);
                }

                return $t;
            });
        }
        $reminderId = (int) ($row['reminder_id'] ?? 0);
        if ($reminderId > 0 && function_exists('jeab_store')) {
            try {
                jeab_store()->saveReminder($reminderId, ['deleted_at' => date('Y-m-d H:i:s')]);
            } catch (Throwable $e) {
                unset($e);
            }
        }
        $n++;
    }
    $mark = $pdo->prepare('UPDATE inbox_klassning_log SET undone = 1 WHERE batch = ? AND dry = 0 AND undone = 0');
    $mark->execute([$batch]);

    return ['ok' => true, 'batch' => $batch, 'undone' => $n, 'sent' => 0];
}
