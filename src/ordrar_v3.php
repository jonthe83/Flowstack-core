<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/*
 * Ordrar v3 – ny vy för ett öppet ärende (skiss v3, godkänd av Sam).
 *
 * Standardvyn för alla produkter. Gamla vyn finns kvar som reserv med ?v=2 (se jeab_ordrar_v3_mode).
 * Samma POST-åtgärder som den gamla vyn (ordrar.spara, ordrar.slutfor, ordrar.fortnox_utan_bokning, ordrar.pick,
 * ordrar.objekt, ordrar.fakturering, ordrar.stang, ordrar.manual_done, ordrar.delete). Bara presentationen
 * och stegens läge (Låst / Att göra / Klar) är nya. Läget räknas ur ordern, inte URL:en.
 *
 * Elbilsladdare: Bekräfta → Fakturering → Stäng. Batteri och sol: Bekräfta → Föranmälan →
 * (Boka) → Beställ → Fakturering → Stäng.
 */

const JEAB_ORDRAR_V3_SESSION = 'jeab_ordrar_view';

/** Hur länge ett uttryckligt ?v=2 håller i sessionen utan aktivitet (sekunder). */
const JEAB_ORDRAR_OLD_VIEW_TTL = 1800;

/**
 * Vilken vy ett öppet ärende ritas i. Nya vyn (v3) är standard för alla produkter.
 *
 * Gamla vyn finns kvar som reserv, bara uttryckligen: ?v=2 (eller v=old). Valet sparas i
 * sessionen med en glidande utgång (30 min utan Ordrar-anrop) så att POST, Spara och
 * byte av steg i gamla vyn stannar kvar där. Sedan faller man tillbaka till v3 av sig själv,
 * så ett gammalt v=2 i sessionen fastnar aldrig. ?v=3 går direkt tillbaka.
 * Äldre sessionsvärden (t.ex. 3 från när v3 var opt-in) tolkas som v3.
 */
function jeab_ordrar_v3_mode(?array $get = null, ?int $now = null): bool
{
    $get = $get ?? $_GET;
    $now = $now ?? time();
    // session_write_close lämnar värdet. Sidan släpper låset innan listan läses.
    $sessionOn = session_status() === PHP_SESSION_ACTIVE;
    $sessionReadable = $sessionOn || !empty($GLOBALS['_JEAB_SESSION_RELEASED']);
    if (array_key_exists('v', $get)) {
        $want = strtolower(trim((string) $get['v']));
        $old = $want === '2' || $want === 'old';
        if ($sessionOn) {
            if ($old) {
                $_SESSION[JEAB_ORDRAR_V3_SESSION] = ['old_until' => $now + JEAB_ORDRAR_OLD_VIEW_TTL];
            } else {
                unset($_SESSION[JEAB_ORDRAR_V3_SESSION]);
            }
        }
        return !$old;
    }
    if (!$sessionReadable) {
        return true;
    }
    $saved = $_SESSION[JEAB_ORDRAR_V3_SESSION] ?? null;
    $until = is_array($saved) ? (int) ($saved['old_until'] ?? 0) : 0;
    if ($until > $now) {
        // Aktiv i gamla vyn: förläng medan låset finns. Efter släpp räcker det sparade värdet.
        if ($sessionOn) {
            $_SESSION[JEAB_ORDRAR_V3_SESSION] = ['old_until' => $now + JEAB_ORDRAR_OLD_VIEW_TTL];
        }
        return false;
    }
    if ($saved !== null && $sessionOn) {
        unset($_SESSION[JEAB_ORDRAR_V3_SESSION]);
    }
    return true;
}

/** Gamla vyn är vald uttryckligen (reserv). Används för länken tillbaka till nya vyn. */
function jeab_ordrar_old_view_note(int $openId, string $company): string
{
    $url = jeab_ordrar_url(['co' => $company, 'id' => $openId]);
    $url .= (str_contains($url, '?') ? '&' : '?') . 'v=3';
    return '<p class="ordrar-help ordrar-v3-note" role="status">Gamla vyn (reserv). <a href="' . e($url) . '">Till nya vyn</a></p>';
}

/** Vilka ärenden v3 ritar: elbilsladdare, batteri, sol, servicejobb och ärenden utan vald produkt. */
function jeab_ordrar_v3_supports(array $row): bool
{
    return in_array(jeab_ordrar_v3_kind($row), jeab_ordrar_v3_kinds(), true);
}

/**
 * Produkt i v3. servicejobb är ett eget slag här (ui_kind känner bara sol, batteri och laddbox).
 */
function jeab_ordrar_v3_kind(array $row): string
{
    $ui = jeab_ordrar_ui_kind($row['kind'] ?? '');
    if ($ui !== '') {
        return $ui;
    }
    $raw = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string) ($row['kind'] ?? '')), 'UTF-8')
        : strtolower(trim((string) ($row['kind'] ?? '')));
    if ($raw === 'servicejobb' || str_contains($raw, 'servicejobb')) {
        return 'servicejobb';
    }
    return '';
}

/** Produkter med ny vy. */
function jeab_ordrar_v3_kinds(): array
{
    return ['', 'laddbox', 'batteri', 'sol', 'servicejobb'];
}

/** Batteri och sol har det långa flödet (Föranmälan, Beställ). */
function jeab_ordrar_v3_long(array $row): bool
{
    $kind = jeab_ordrar_ui_kind($row['kind'] ?? '');
    return $kind === 'batteri' || $kind === 'sol';
}

function jeab_ordrar_v3_products(): array
{
    return ['laddbox' => 'Elbilsladdare', 'batteri' => 'Batteri', 'sol' => 'Solpaneler'];
}

/** Visningsnamn. Servicejobb finns i föranmälan men inte som valbar produkt i Bekräfta. */
function jeab_ordrar_v3_kind_label(string $kind): string
{
    if ($kind === 'servicejobb') {
        return 'Servicejobb';
    }
    return jeab_ordrar_v3_products()[$kind] ?? 'Produkt saknas';
}

/** Svenskt kort datum: "4 nov". Med år: "4 nov 2026". Med tid: "21 sep 09:12". */
function jeab_ordrar_v3_date(string $raw, bool $year = false, bool $time = false): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($raw, new DateTimeZone('Europe/Stockholm'));
    } catch (Exception) {
        return '';
    }
    $months = [1 => 'jan', 'feb', 'mar', 'apr', 'maj', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
    $out = (int) $dt->format('j') . ' ' . $months[(int) $dt->format('n')];
    if ($year) {
        $out .= ' ' . $dt->format('Y');
    }
    if ($time && preg_match('/\d{1,2}:\d{2}/', $raw) === 1 && $dt->format('H:i') !== '00:00') {
        $out .= ' ' . $dt->format('H:i');
    }
    return $out;
}

function jeab_ordrar_v3_step_raw(array $row, string $key): string
{
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    return trim((string) ($steps[$key] ?? ''));
}

function jeab_ordrar_v3_kr(int|float $amount): string
{
    return jeab_ordrar_kr((int) round($amount)) . ' kr';
}

/**
 * Länk till kundfakturan i Fortnox (samma app-id som ordern). Tom om fakturan eller app-id saknas.
 * Fortnox webbapp: modul/post/nr. Ordern är order/order/{nr} (listan order/orderlist), kundfakturor
 * ligger i modulen kf (listan kf/invoicelist), så fakturan är kf/invoice/{nr}
 * (jfr lf/supplierinvoice/{nr} och bf/voucher/{serie}-{nr} i Fortnox egen kod).
 */
function jeab_ordrar_fortnox_invoice_url(array $row): string
{
    $no = jeab_ordrar_invoice_number($row);
    $app = function_exists('jeab_ordrar_fortnox_app_id') ? jeab_ordrar_fortnox_app_id($row['company'] ?? null) : '';
    if ($no === '' || $app === '') {
        return '';
    }
    return 'https://apps5.fortnox.se/app/' . $app . '/kf/invoice/' . rawurlencode($no);
}

/**
 * Bekräfta är klar när «Skapa order och boka» har gjort sitt: tiden är bokad
 * eller Fortnox-ordern finns. Autospar eller Spara räknas inte.
 * Saknas uppgifter för Fortnox-ordern (personnummer m.m.) står Bekräfta kvar
 * som Att göra med en hint, men stegen efter låses inte upp av det.
 */
function jeab_ordrar_v3_bekrafta_klar(array $row, ?JeabStore $store = null): bool
{
    if (!jeab_ordrar_v3_bekraftad($row, $store)) {
        return false;
    }
    return jeab_ordrar_v3_fortnox_missing($row) === [];
}

/** Uppgifter som saknas för Fortnox-ordern när ordern inte finns än. Tom lista när ordern eller fakturan finns. */
function jeab_ordrar_v3_fortnox_missing(array $row): array
{
    if (trim((string) ($row['fortnox_order'] ?? '')) !== '' || jeab_ordrar_has_invoice($row)) {
        return [];
    }
    $missing = jeab_ordrar_identity_missing($row);
    if (!in_array('personnummer', $missing, true) || !function_exists('jeab_ordrar_fortnox_submit_personnummer')) {
        return $missing;
    }
    // Samma nummer som Fortnox-anropet. Orderns eget nummer räcker när ingen kontakt är länkad, så Bekräfta räknas som klar.
    if (jeab_ordrar_fortnox_submit_personnummer($row) === '') {
        return $missing;
    }

    return array_values(array_filter(
        $missing,
        static fn (string $item): bool => $item !== 'personnummer'
    ));
}

/** «Skapa order och boka» är gjort: bokad tid, Fortnox-order eller faktura. Låser upp stegen efter. */
function jeab_ordrar_v3_bekraftad(array $row, ?JeabStore $store = null): bool
{
    $ui = jeab_ordrar_ui_kind($row['kind'] ?? '');
    if ($ui === '' && jeab_ordrar_v3_kind($row) !== 'servicejobb') {
        return false;
    }
    if (trim((string) ($row['fortnox_order'] ?? '')) !== '') {
        return true;
    }
    if ((int) ($row['booking_id'] ?? 0) > 0 && jeab_ordrar_is_booked($row, $store)) {
        return true;
    }
    return jeab_ordrar_has_invoice($row);
}

/** Fakturering är klar först när fakturan finns. Fortnox-ordern räcker inte (gäller även laddbox). */
function jeab_ordrar_v3_faktura_klar(array $row): bool
{
    return jeab_ordrar_has_invoice($row);
}

/** Ärendet är stängt (för hand eller automatiskt till Allt klart). */
function jeab_ordrar_v3_stang_klar(array $row): bool
{
    return jeab_ordrar_allt_klart($row);
}

/**
 * Föranmälan är klar enligt samma regel som gamla vyn: Automatiskt när Bokmalen
 * tagit emot (eller Ellevio-fullmakten startats), utkast och Manuellt/ingen när sparat.
 */
function jeab_ordrar_v3_foranmalan_klar(array $row): bool
{
    return jeab_ordrar_foranmalan_wizard_done($row);
}

/**
 * Beställ är klar först när beställningen är lagd (steps.bestalld). Autosparad
 * inköpslista (orderrader_saved) räknas inte – annars blev bocken grön av autosparet.
 */
function jeab_ordrar_v3_bestall_klar(array $row): bool
{
    return jeab_ordrar_bestalld($row);
}

/**
 * Avtryck av fälten stegen och listcellerna läser. Anteckning, adress och
 * råtext ingår inte. En sparning i samma request höjer generationen.
 */
function jeab_ordrar_v3_row_stamp(array $row): string
{
    static $skip = [
        'install_note' => true,
        'install_note_rev' => true,
        'note' => true,
        'notes' => true,
        'lookup' => true,
        'lookup_raw' => true,
        'raw' => true,
        'html' => true,
        'body' => true,
        'message' => true,
        'pdf' => true,
        'base64' => true,
        'address' => true,
        'huvudsokande_address' => true,
        'gata' => true,
        'ort' => true,
        'email' => true,
        'phone' => true,
        'postnummer' => true,
        'postnr' => true,
        'postal' => true,
        'street' => true,
        'customer' => true,
        'display' => true,
        'name' => true,
        'customer_name' => true,
    ];
    $parts = [];
    foreach ($row as $key => $value) {
        $name = (string) $key;
        if (isset($skip[$name]) || str_ends_with($name, '_raw') || str_ends_with($name, '_html') || str_ends_with($name, '_pdf') || str_ends_with($name, '_body')) {
            continue;
        }
        if (is_array($value)) {
            if ($name === 'applicant') {
                $parts[] = 'applicant=' . (string) ($value['name'] ?? '') . '|' . (string) ($value['email'] ?? '') . '|' . (string) ($value['phone'] ?? '');
                continue;
            }
            if ($name === 'owners') {
                foreach ($value as $person) {
                    if (!is_array($person)) {
                        continue;
                    }
                    $parts[] = 'owner=' . (string) ($person['name'] ?? $person['namn'] ?? '') . '/' . (string) ($person['personnummer'] ?? $person['pnr'] ?? '');
                }
                continue;
            }
            if ($name === 'inkop_lines' || $name === 'supplier_lines') {
                $parts[] = $name . '=' . count($value);
                foreach ($value as $line) {
                    if (!is_array($line)) {
                        continue;
                    }
                    $parts[] = (string) ($line['shop'] ?? '')
                        . '/' . (string) ($line['order'] ?? $line['order_no'] ?? $line['ordernummer'] ?? '')
                        . '/' . (string) ($line['leverans'] ?? $line['leveransdatum'] ?? $line['delivery'] ?? '')
                        . '/' . (string) ($line['qty'] ?? $line['antal'] ?? '');
                }
                continue;
            }
            foreach ($value as $subKey => $subValue) {
                if (is_scalar($subValue) || $subValue === null) {
                    $parts[] = $name . '.' . $subKey . '=' . (string) $subValue;
                }
            }
            continue;
        }
        if (is_bool($value)) {
            $parts[] = $name . '=' . ($value ? '1' : '0');
            continue;
        }
        if (is_scalar($value) || $value === null) {
            $parts[] = $name . '=' . (string) $value;
        }
    }

    return implode("\n", $parts);
}

function jeab_ordrar_v3_row_stamp_hash(array $row): string
{
    return substr(hash('sha256', jeab_ordrar_v3_row_stamp($row)), 0, 16);
}

/**
 * Nyckel för stegcachen. Hela raden ingår inte: ett fält som stegen läser
 * räcker, plus en generation som höjs när ordern sparas i samma request.
 */
function jeab_ordrar_v3_steps_key(array $row, ?JeabStore $store = null): string
{
    return (int) ($row['reminder_id'] ?? 0)
        . '|' . (int) ($GLOBALS['_JEAB_V3_STEPS_GEN'] ?? 0)
        . '|' . jeab_ordrar_v3_row_stamp_hash($row)
        . '|' . ($store instanceof JeabStore ? '1' : '0');
}

/** Tömmer stegcachen. Anropas när ordern eller bokningen skrivs. */
function jeab_ordrar_v3_steps_forget(): void
{
    unset($GLOBALS['_JEAB_V3_STEPS'], $GLOBALS['_JEAB_LISTA_CELLS'], $GLOBALS['_JEAB_ORDRAR_BOOKING'], $GLOBALS['_JEAB_ORDRAR_PNR']);
    $GLOBALS['_JEAB_V3_STEPS_GEN'] = (int) ($GLOBALS['_JEAB_V3_STEPS_GEN'] ?? 0) + 1;
}

/**
 * Stegen i v3 med läge per steg. Läget följer ordern, aldrig URL:en.
 * Elbilsladdare: Bekräfta → Fakturering → Stäng.
 * Batteri och sol: Bekräfta → Föranmälan → Beställ → Fakturering → Stäng.
 * Installationen bokas i Beställ. Boka visas bara när Beställ är klar och tiden saknas.
 *
 * @return list<array{id:string,title:string,short:string,state:string,reason:string,n:int}>
 */
function jeab_ordrar_v3_steps(array $row, ?JeabStore $store = null): array
{
    $stepId = (int) ($row['reminder_id'] ?? 0);
    $stepKey = jeab_ordrar_v3_steps_key($row, $store);
    if ($stepId > 0 && isset($GLOBALS['_JEAB_V3_STEPS'][$stepKey]) && is_array($GLOBALS['_JEAB_V3_STEPS'][$stepKey])) {
        return $GLOBALS['_JEAB_V3_STEPS'][$stepKey];
    }
    $manualOn = function_exists('jeab_ordrar_manual_done_on') && jeab_ordrar_manual_done_on($row);
    $bekraftad = jeab_ordrar_v3_bekraftad($row, $store);
    $bekrafta = $bekraftad && jeab_ordrar_v3_fortnox_missing($row) === [];
    $faktura = jeab_ordrar_v3_faktura_klar($row);
    // Stäng-knappen och auto-Allt klart är stängda på riktigt. Klarmarkeringen
    // gör samma gröna läge utan att låtsas att de stegen är utförda.
    $closedWithoutManual = jeab_ordrar_manuellt_stangt($row) || jeab_ordrar_auto_allt_klart($row);
    $stang = $closedWithoutManual || $manualOn;
    $needBek = 'Kräver att Bekräfta är klar';
    $steps = [];
    $steps[] = [
        'id' => 'bekrafta',
        'title' => 'Bekräfta',
        'short' => 'Bekräfta',
        'state' => $bekrafta ? 'klar' : 'todo',
        'reason' => '',
    ];
    if (jeab_ordrar_v3_long($row)) {
        $foran = jeab_ordrar_v3_foranmalan_klar($row);
        $steps[] = [
            'id' => 'foranmalan',
            'title' => 'Föranmälan',
            'short' => 'Föranmälan',
            'state' => $foran ? 'klar' : ($bekraftad ? 'todo' : 'locked'),
            'reason' => $bekraftad ? '' : $needBek,
        ];
        $booked = jeab_ordrar_is_booked($row, $store);
        $bestall = jeab_ordrar_v3_bestall_klar($row);
        if ($bekraftad && !$booked && !$stang && $bestall) {
            $steps[] = [
                'id' => 'boka',
                'title' => 'Boka',
                'short' => 'Boka',
                'state' => 'todo',
                'reason' => '',
            ];
        }
        $steps[] = [
            'id' => 'bestall',
            'title' => 'Beställ',
            'short' => 'Beställ',
            'state' => $bestall ? 'klar' : ($bekraftad && $foran ? 'todo' : 'locked'),
            'reason' => $bestall ? '' : (!$bekraftad ? $needBek : ($foran ? '' : 'Kräver att Föranmälan är klar')),
        ];
    }
    $fakturaReady = $bekraftad;
    $fakturaReason = $bekraftad ? '' : $needBek;
    if (!jeab_ordrar_v3_long($row) && !$faktura && trim((string) ($row['fortnox_order'] ?? '')) === '') {
        $fakturaReady = false;
        $fakturaReason = $bekraftad ? 'Kräver en Fortnox-order' : $needBek;
    }
    if (jeab_ordrar_v3_long($row) && !$faktura) {
        $foranDone = jeab_ordrar_v3_foranmalan_klar($row);
        $bestallDone = jeab_ordrar_v3_bestall_klar($row);
        if (!$bekraftad) {
            $fakturaReady = false;
            $fakturaReason = $needBek;
        } elseif (!$foranDone) {
            $fakturaReady = false;
            $fakturaReason = 'Kräver att Föranmälan är klar';
        } elseif (!$bestallDone) {
            $fakturaReady = false;
            $fakturaReason = 'Kräver att Beställ är klar';
        }
    }
    $steps[] = [
        'id' => 'faktura',
        'title' => 'Fakturering',
        'short' => 'Faktura',
        'state' => $faktura ? 'klar' : ($fakturaReady ? 'todo' : 'locked'),
        'reason' => $faktura ? '' : $fakturaReason,
    ];
    $steps[] = [
        'id' => 'stang',
        'title' => 'Stäng ärendet',
        'short' => 'Stäng',
        'state' => $stang ? 'klar' : ($faktura ? 'todo' : 'locked'),
        'reason' => $faktura ? '' : 'Kräver att fakturan är skapad',
        'manual' => $manualOn && !$closedWithoutManual,
    ];
    if ($manualOn) {
        foreach ($steps as $i => $step) {
            if (($step['state'] ?? '') === 'klar') {
                continue;
            }
            $steps[$i]['state'] = 'klar';
            $steps[$i]['reason'] = '';
            $steps[$i]['manual'] = true;
        }
    }
    foreach ($steps as $i => $step) {
        $steps[$i]['n'] = $i + 1;
        if (!array_key_exists('manual', $steps[$i])) {
            $steps[$i]['manual'] = false;
        }
    }
    if ($stepId > 0) {
        if (!isset($GLOBALS['_JEAB_V3_STEPS']) || !is_array($GLOBALS['_JEAB_V3_STEPS'])) {
            $GLOBALS['_JEAB_V3_STEPS'] = [];
        }
        $GLOBALS['_JEAB_V3_STEPS'][$stepKey] = $steps;
    }

    return $steps;
}

function jeab_ordrar_v3_step_state(array $steps, string $id): string
{
    foreach ($steps as $step) {
        if ($step['id'] === $id) {
            return $step['state'];
        }
    }
    return 'locked';
}

/**
 * Fakturaraderna som i den gamla vyn. Vanlig öppning läser sparade rader
 * (tomma om kopian saknas). Fortnox hämtas bara på Uppdatera (?refresh=1).
 * Fyra tomma rader utan order.
 *
 * @return array{lines:list<array>,pay:string,error:string,source:string,invoice:string}
 */
function jeab_ordrar_v3_fakturering_data(array $open): array
{
    $empty = ['row_id' => '', 'article' => '', 'description' => '', 'housework' => '', 'qty' => '', 'price' => ''];
    $out = [
        'lines' => [$empty, $empty, $empty, $empty],
        'pay' => '',
        'error' => '',
        'source' => '',
        'invoice' => jeab_ordrar_invoice_number($open),
    ];
    $orderNo = trim((string) ($open['fortnox_order'] ?? ''));
    if ($orderNo === '') {
        return jeab_ordrar_v3_fakturering_from_kalkyl($open, $out);
    }
    $stored = is_array($open['fortnox_order_rows'] ?? null) ? $open['fortnox_order_rows'] : [];
    $live = jeab_ordrar_external_live();
    if (!$live) {
        if ($stored !== []) {
            $out['lines'] = jeab_ordrar_fortnox_editor_lines($stored);
            $out['source'] = 'snapshot';
        }
        $out['pay'] = trim((string) ($open['fortnox_att_betala'] ?? ''));
        return $out;
    }
    try {
        $snapshot = jeab_ordrar_fortnox_fetch_order($open);
        $rows = jeab_ordrar_fortnox_order_rows($snapshot);
        $out['lines'] = jeab_ordrar_fortnox_editor_lines($rows);
        $out['pay'] = jeab_ordrar_fortnox_att_betala($snapshot);
        $ref = jeab_ordrar_fortnox_invoice_reference($snapshot);
        if ($ref !== '') {
            $out['invoice'] = $ref;
        }
        $out['source'] = 'fortnox';
        if ($live) {
            jeab_ordrar_fortnox_store_rows($open, $rows, $out['pay']);
        }
    } catch (Throwable $e) {
        $reason = trim($e->getMessage());
        $out['error'] = $reason !== '' ? $reason : 'Fortnox-ordern kunde inte hämtas.';
        if ($stored !== []) {
            $out['lines'] = jeab_ordrar_fortnox_editor_lines($stored);
            $out['pay'] = trim((string) ($open['fortnox_att_betala'] ?? ''));
            $out['source'] = 'snapshot';
        }
    }
    return $out;
}

/**
 * Ingen Fortnox-order ännu: förifyll raderna från Kalkylen med Kalkylens egna
 * formler (jeab_ordrar_kalkyl_logical → samma rader som Bekräfta skriver,
 * med artikelnummer och husarbete/grön teknik). Att betala = Kalkylens.
 * Skriver ingenting till Fortnox.
 */
function jeab_ordrar_v3_fakturering_from_kalkyl(array $open, array $out): array
{
    try {
        if (!function_exists('jeab_offert_fortnox_kop_rows')) {
            require_once JEAB_ROOT . '/src/offert.php';
        }
        if (!class_exists('FortnoxAPI')) {
            require_once JEAB_ROOT . '/src/fortnox.php';
        }
        // Samma fält som jeab_ordrar_fortnox_payload, men utan krav på
        // personnummer/fastighet: förifyllningen ska synas ändå.
        $kind = (string) (jeab_purchase_normalize_kind($open['kind'] ?? '') ?? '');
        $price = jeab_ordrar_fortnox_price($open);
        if ($kind === '' || $price <= 0) {
            return $out;
        }
        $payload = [
            'company' => trim((string) ($open['company'] ?? '')),
            'kind' => $kind,
            'price' => $price,
            'kundpris' => $price,
            'install_what' => trim((string) ($open['install_what'] ?? '')),
            'kwh' => trim((string) ($open['kwh'] ?? '')),
            'vaxelriktare' => trim((string) ($open['vaxelriktare'] ?? '')),
            'kalkyl' => is_array($open['kalkyl'] ?? null) ? $open['kalkyl'] : [],
        ];
        $logical = jeab_offert_fortnox_kop_rows($kind, $price, $payload['install_what'], $payload);
        $input = jeab_offert_input_from_purchase($payload, $kind);
        $people = max(1, (int) round((float) ($input['sokande'] ?? 1)));
        $attBetala = jeab_offert_rows_att_betala($logical, $people);
        $api = new FortnoxAPI(jeab_ordrar_fortnox_account($open['company'] ?? null));
        if (!jeab_offert_rows_match_payable($logical, $price, $people)) {
            $logical = jeab_offert_rows_for_payable($kind, $price, $payload['install_what'], $payload);
            $attBetala = jeab_offert_rows_att_betala($logical, $people);
            $rows = $api->kopRowsFromLogical($logical);
        } else {
            $rows = $api->kopOrderRows($kind, $price, $payload['install_what'], $payload);
        }
    } catch (Throwable) {
        return $out;
    }
    if ($rows === []) {
        return $out;
    }
    $out['lines'] = jeab_ordrar_fortnox_editor_lines($rows);
    $out['pay'] = jeab_ordrar_fortnox_money((int) $attBetala);
    $out['source'] = 'kalkyl';
    return $out;
}

/** SVG-ikoner från skissen. */
function jeab_ordrar_v3_icon(string $name): string
{
    return match ($name) {
        'check' => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7"/></svg>',
        'lock' => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3.5" y="7" width="9" height="6.5" rx="1.5"/><path d="M5.5 7V5a2.5 2.5 0 015 0v2"/></svg>',
        'dots' => '<svg viewBox="0 0 16 16" width="16" height="16" fill="currentColor" aria-hidden="true"><circle cx="3.5" cy="8" r="1.3"/><circle cx="8" cy="8" r="1.3"/><circle cx="12.5" cy="8" r="1.3"/></svg>',
        'ext' => '<svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4.5 2.5h5v5M9.5 2.5l-6.5 6.5"/></svg>',
        'home' => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M2.5 7.5L8 3l5.5 4.5V13h-11z"/></svg>',
        'list' => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5.5 4.5h8M5.5 8h8M5.5 11.5h8M2.5 4.5h.1M2.5 8h.1M2.5 11.5h.1"/></svg>',
        'info' => '<svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><circle cx="8" cy="8" r="6"/><path d="M8 7.2v3.6M8 5.2v.1"/></svg>',
        'refresh' => '<svg viewBox="0 0 16 16" width="15" height="15" style="width:15px;height:15px" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M13 8a5 5 0 11-1.5-3.6M13 2.5v3h-3"/></svg>',
        'cal' => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="2.5" y="3.5" width="11" height="10" rx="1.5"/><path d="M2.5 6.5h11M5.5 2v3M10.5 2v3"/></svg>',
        'copy' => '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5v-2a1 1 0 00-1-1h-6a1 1 0 00-1 1v6a1 1 0 001 1h2"/></svg>',
        default => '',
    };
}

function jeab_ordrar_v3_step_icon(string $state, int $n): string
{
    if ($state === 'klar') {
        return '<span class="v3-ic is-klar">' . jeab_ordrar_v3_icon('check') . '</span>';
    }
    if ($state === 'locked') {
        return '<span class="v3-ic is-locked">' . jeab_ordrar_v3_icon('lock') . '</span>';
    }
    return '<span class="v3-ic is-todo">' . $n . '</span>';
}

function jeab_ordrar_v3_copy(string $value): string
{
    return '<button type="button" class="v3-cp" data-v3="copy" data-v="' . e($value) . '" title="Kopiera" aria-label="Kopiera">' . jeab_ordrar_v3_icon('copy') . '</button>';
}

/** Personnummer maskat: 19800101-••••. */
function jeab_ordrar_v3_mask(string $pnr): string
{
    $pnr = trim($pnr);
    if (strlen($pnr) < 9) {
        return $pnr;
    }
    return rtrim(substr($pnr, 0, 9), '-') . '-••••';
}

/** «…»-menyn. $items: [[act, label, danger?], '-', …]. */
function jeab_ordrar_v3_kebab(array $items, string $label = 'Fler åtgärder'): string
{
    $html = '<div class="v3-kebab"><button type="button" class="v3-kebab-btn" data-v3="kebab" title="' . e($label) . '" aria-label="' . e($label) . '" aria-haspopup="true" aria-expanded="false">' . jeab_ordrar_v3_icon('dots') . '</button><div class="v3-pop" role="menu">';
    foreach ($items as $item) {
        if ($item === '-') {
            $html .= '<hr>';
            continue;
        }
        $danger = !empty($item[2]) ? ' is-danger' : '';
        $html .= '<button type="button" role="menuitem" class="v3-pop-item' . $danger . '" data-v3="act" data-act="' . e((string) $item[0]) . '">' . e((string) $item[1]) . '</button>';
    }
    return $html . '</div></div>';
}

/** Kortets skal (sektion) som i skissen. */
function jeab_ordrar_v3_shell(string $id, int $n, string $state, string $title, string $sub, string $body, string $menu = ''): string
{
    $cls = $state === 'klar' ? 'is-done' : ($state === 'locked' ? 'is-locked' : 'is-todo');
    $badge = '';
    if ($state === 'klar') {
        $badge = '<span class="v3-state is-klar">Klar</span>';
    } elseif ($state === 'todo') {
        $badge = '<span class="v3-state">Att göra</span>';
    }
    return '<section class="v3-card ' . $cls . '" id="steg-' . e($id) . '" data-step="' . e($id) . '" data-state="' . e($state) . '">'
        . '<div class="v3-chead">' . jeab_ordrar_v3_step_icon($state, $n)
        . '<div class="v3-grow"><h2>' . $title . '</h2>' . ($sub !== '' ? '<div class="v3-sub">' . $sub . '</div>' : '') . '</div>'
        . $badge . $menu . '</div>' . $body . '</section>';
}

/**
 * En grön knapp per steg med verbet, plus ev. sekundärknapp. Under arbete
 * visas en snurra i samma knapp (aldrig grön → grå → grön).
 */
function jeab_ordrar_v3_actions(string $step, string $label, string $working, string $what, string $formId, string $action, string $secondary = '', string $intent = '', bool $disabled = false, string $below = '', bool $hold = false): string
{
    $intentAttr = $intent !== '' ? ' name="intent" value="' . e($intent) . '"' : ' name="action" value="' . e($action) . '"';
    $disabledAttr = $disabled ? ' disabled' : '';
    $holdAttr = $hold ? ' data-v3-hold="1"' : '';
    $html = '<div class="v3-actrow">' . $secondary
        . '<button type="submit" form="' . e($formId) . '" class="v3-btn is-primary" data-v3-primary="' . e($step) . '" data-working="' . e($working) . '"' . $intentAttr . $holdAttr . $disabledAttr . '>' . e($label) . '</button>'
        . $below
        . '</div>'
        . '<div class="v3-reason" data-v3-reason="' . e($step) . '" data-what="' . e($what) . '">' . e($what) . '</div>'
        . '<div class="v3-err" data-v3-err="' . e($step) . '" role="alert" hidden></div>';
    return $html;
}

/** Själva sidan för ett öppet ärende. */
function jeab_ordrar_v3_html(array $open, string $company, int $openId, ?JeabStore $store = null, ?string $error = null, ?string $flash = null, ?array $reminder = null): string
{
    if (function_exists('jeab_ordrar_bekrafta_prepare')) {
        $prepared = jeab_ordrar_bekrafta_prepare($open, $reminder);
        $open = is_array($prepared['row'] ?? null) ? $prepared['row'] : $open;
        if (is_array($prepared['suggest'] ?? null)) {
            $GLOBALS['_JEAB_ORDRAR_SUGGEST'][$openId] = $prepared['suggest'];
        }
    }
    $kind = jeab_ordrar_v3_kind($open);
    $kindLabel = jeab_ordrar_v3_kind_label($kind);
    $steps = jeab_ordrar_v3_steps($open, $store);
    // Samma räkning som listan: Bekräfta, Föranmälan, Beställ, Fakturering.
    $progress = jeab_ordrar_v3_progress($open, $store, $steps);
    $doneCount = $progress['done'];
    $total = $progress['total'];
    $next = $progress['next'];
    $place = jeab_ordrar_board_place($open);
    $name = jeab_ordrar_name($open);
    $title = $place !== '' ? $place : ($name !== '' ? $name : 'Order');
    $nat = jeab_ordrar_status_natbolag($open);
    $context = function_exists('jeab_ordrar_context_params') ? jeab_ordrar_context_params($company) : [];
    $selfUrl = jeab_ordrar_url(['co' => $company, 'id' => $openId] + $context);
    $refreshUrl = jeab_ordrar_url(['co' => $company, 'id' => $openId, 'refresh' => '1'] + $context);
    $backUrl = jeab_ordrar_url(['co' => $company] + $context);
    $listUrl = $backUrl;
    $activity = jeab_ordrar_activity_sorted($open);

    $menu = '<div class="v3-mh">Ärende</div>'
        . '<a class="v3-mi" href="#steg-status" data-nav="status"><span class="v3-ic is-tag">' . jeab_ordrar_v3_icon('home') . '</span><em class="v3-sn">Objekt</em><span class="v3-mt"><b>Objekt &amp; läge</b><small>' . e($kindLabel) . ' · ' . e($nat !== '' ? $nat : 'nätägare saknas') . '</small></span></a>'
        . '<div class="v3-msep"></div>';
    foreach ($steps as $step) {
        if ($step['id'] === 'faktura' && !jeab_ordrar_v3_long($open) && jeab_ordrar_v3_plomb_applies($open)) {
            $menu .= '<a class="v3-mi" href="#steg-foranmalan" data-nav="foranmalan"><span class="v3-ic is-tag">' . jeab_ordrar_v3_icon('info') . '</span><em class="v3-sn">Föranmälan</em><span class="v3-mt"><b>Föranmälan</b><small>Plombbrytning</small></span></a>';
        }
        $tip = $step['state'] === 'locked' ? ' data-tip="' . e($step['reason']) . '" title="' . e($step['reason']) . '" aria-disabled="true"' : '';
        $menu .= '<a class="v3-mi is-' . e($step['state']) . '" href="#steg-' . e($step['id']) . '" data-nav="' . e($step['id']) . '"' . $tip . '>'
            . jeab_ordrar_v3_step_icon($step['state'], $step['n'])
            . '<em class="v3-sn">' . e($step['short']) . '</em><span class="v3-mt"><b>' . e($step['title']) . '</b><small>' . e(jeab_ordrar_v3_menu_sub($open, $step, $store)) . '</small></span></a>';
    }
    $menu .= '<div class="v3-msep"></div><a class="v3-mi" href="#steg-aktivitet" data-nav="aktivitet"><span class="v3-ic is-tag">' . jeab_ordrar_v3_icon('list') . '</span><em class="v3-sn">Aktivitet</em><span class="v3-mt"><b>Aktivitet</b><small>' . count($activity) . ' händelser</small></span></a>'
        . '<div class="v3-menu-foot">' . $doneCount . ' av ' . $total . ' klara</div>';

    $seg = '';
    foreach ($progress['steps'] as $step) {
        $seg .= '<i class="' . ($progress['closed'] || $step['state'] === 'klar' ? 'is-d' : '') . '"></i>';
    }
    $head = '<div class="v3-ohead" id="v3-ohead"><div class="v3-ohead-main"><h1>' . e($title) . '</h1><div class="v3-meta">'
        . ($name !== '' ? '<span class="v3-kname">' . e($name) . '</span><span class="v3-dot"></span>' : '')
        . '<span class="v3-badge">' . e($kindLabel) . '</span><span class="v3-dot"></span>'
        . '<span class="v3-prog"><span class="v3-segbar">' . $seg . '</span><span>' . $doneCount . ' av ' . $total . ' klara</span></span>'
        . ($next !== null
            ? '<span class="v3-dot"></span><span class="v3-nextwrap">Nästa: <a class="v3-nextlink" href="#steg-' . e($next['id']) . '" data-nav="' . e($next['id']) . '">' . e($next['title']) . '</a></span>'
            : '<span class="v3-dot"></span><span class="v3-closedtag">Allt klart</span>')
        . '</div></div><div class="v3-hright">'
        . jeab_ordrar_staff_owner_html($open, $company, $openId)
        . '<a class="v3-iconbtn" href="' . e($refreshUrl) . '" data-v3="refresh" title="Uppdatera – läser Fortnox. Bokmalens läge är det senast mottagna." aria-label="Uppdatera">' . jeab_ordrar_v3_icon('refresh') . '</a>'
        . jeab_ordrar_v3_header_kebab($open)
        . '</div></div>';

    $main = '';
    $plombShort = !jeab_ordrar_v3_long($open) && jeab_ordrar_v3_plomb_applies($open);
    $banner = trim((string) ($error ?? ''));
    if ($banner !== '') {
        $main .= '<div class="v3-banner" role="alert" data-v3-banner><span>' . e(jeab_ordrar_save_fail_text($banner)) . '</span><button type="button" data-v3="close-banner" aria-label="Stäng">×</button></div>';
    }
    $lmErr = trim((string) ($open['lm_lookup_error'] ?? ''));
    if ($lmErr !== '') {
        $lmErr = is_string(jeab_ordrar_scrub_pnr($lmErr)) ? (string) jeab_ordrar_scrub_pnr($lmErr) : $lmErr;
        $main .= '<div class="v3-banner" role="status" data-v3-lm><span>' . e($lmErr) . '</span></div>';
    }
    $levErr = trim((string) ($open['fortnox_leverans_error'] ?? ''));
    // Sparad text från innan vantar_order. Utan order- och fakturanummer är det inget fel.
    if ($levErr !== '' && !jeab_ordrar_stale_leverans_missing_order($open)) {
        $levErr = is_string(jeab_ordrar_scrub_pnr($levErr)) ? (string) jeab_ordrar_scrub_pnr($levErr) : $levErr;
        $main .= '<div class="v3-banner" role="alert" data-v3-leverans><span>Fortnox tog inte emot leveransdatum. ' . e($levErr) . '</span></div>';
    }
    $main .= jeab_ordrar_v3_overview_html($open, $company, $openId, $store);
    foreach ($steps as $step) {
        if ($plombShort && $step['id'] === 'faktura') {
            $main .= jeab_ordrar_v3_plomb_section($open, $company, $openId);
        }
        if (!empty($step['manual'])) {
            $main .= jeab_ordrar_v3_manual_done_card($step);
            continue;
        }
        $main .= match ($step['id']) {
            'bekrafta' => jeab_ordrar_v3_bekrafta_html($open, $company, $openId, $step, $store, $reminder),
            'foranmalan' => jeab_ordrar_v3_foranmalan_html($open, $company, $openId, $step),
            'boka' => jeab_ordrar_v3_boka_html($open, $company, $openId, $step, $store),
            'bestall' => jeab_ordrar_v3_bestall_html($open, $company, $openId, $step, $store),
            'faktura' => jeab_ordrar_v3_faktura_html($open, $company, $openId, $step),
            'stang' => jeab_ordrar_v3_stang_html($open, $company, $openId, $step),
            default => '',
        };
    }
    $main .= jeab_ordrar_v3_activity_html($activity);
    $main .= '<div class="v3-spacer"></div>';

    $deleteForm = '<form id="v3-f-delete" method="post" action="' . e($listUrl) . '" hidden>' . jeab_csrf_field()
        . '<input type="hidden" name="action" value="ordrar.delete"><input type="hidden" name="id" value="' . $openId . '"></form>';
    $manualForm = '<form id="v3-f-manual-done" method="post" action="' . e($selfUrl) . '" hidden>' . jeab_csrf_field()
        . '<input type="hidden" name="action" value="ordrar.manual_done"><input type="hidden" name="id" value="' . $openId . '">'
        . '<input type="hidden" name="undo" value="0"></form>';
    $bookingId = (int) ($open['booking_id'] ?? 0);
    $cancelForm = $bookingId > 0
        ? '<form id="v3-f-avboka" method="post" action="' . e($selfUrl) . '" hidden>' . jeab_csrf_field()
            . '<input type="hidden" name="action" value="booking.cancel"><input type="hidden" name="id" value="' . $bookingId . '"></form>'
        : '';
    $oldUrl = jeab_ordrar_url(['co' => $company, 'id' => $openId]);
    $oldUrl .= (str_contains($oldUrl, '?') ? '&' : '?') . 'v=2';

    $flashHtml = '';
    $flash = trim((string) ($flash ?? ''));
    if ($flash !== '') {
        $flashHtml = '<div class="v3-toast is-show" data-v3-toast role="status">' . e($flash) . '</div>';
    } else {
        $flashHtml = '<div class="v3-toast" data-v3-toast role="status"></div>';
    }

    return '<section class="ov3" data-v3-root data-id="' . $openId . '" data-kind="' . e($kind) . '" data-booking-id="' . $bookingId . '" data-self="' . e($selfUrl) . '" data-list="' . e($listUrl) . '" data-old="' . e($oldUrl) . '" data-next="' . e($next['id'] ?? 'stang') . '">'
        . '<a class="v3-back" href="' . e($backUrl) . '">← Tillbaka</a>'
        . $head
        . '<div class="v3-grid"><aside class="v3-aside"><nav class="v3-menu" id="v3-menu" aria-label="Steg">' . $menu . '</nav></aside>'
        . '<div class="v3-main" id="v3-main">' . $main . '</div></div>'
        . '<div class="v3-mbar" id="v3-mbar" hidden><div class="v3-mbar-step" id="v3-mbar-step"></div><div class="v3-mbar-reason" id="v3-mbar-reason"></div><div class="v3-mbar-utanwrap" id="v3-mbar-utanwrap" hidden><button type="button" class="v3-btn is-sec" id="v3-mbar-utan"></button></div><div class="v3-mbar-row"><button type="button" class="v3-btn is-sec" id="v3-mbar-sec" hidden></button><button type="button" class="v3-btn is-primary" id="v3-mbar-pri"></button></div></div>'
        . $flashHtml
        . '<div class="v3-modal-bg" id="v3-modal" hidden><div class="v3-modal" role="dialog" aria-modal="true" aria-labelledby="v3-m-title"><h3 id="v3-m-title"></h3><p id="v3-m-text"></p><div class="v3-modal-row"><button type="button" class="v3-btn is-sec" data-v3="modal-cancel">Avbryt</button><button type="button" class="v3-btn is-sec" id="v3-m-ok"></button></div></div></div>'
        . (function_exists('jeab_fastighet_refresh_dialog') ? jeab_fastighet_refresh_dialog() : '')
        . $deleteForm
        . $manualForm
        . $cancelForm
        . '</section>';
}

/** ⋯ i sidhuvudet: klarmarkera, radera, gamla vyn. */
function jeab_ordrar_v3_header_kebab(array $row): string
{
    $mark = jeab_ordrar_manual_done_on($row)
        ? ['manual-undo', 'Ångra klarmarkering']
        : ['manual-done', 'Klarmarkera ordern…'];

    return jeab_ordrar_v3_kebab([
        $mark,
        '-',
        ['radera', 'Radera ärendet…', true],
        '-',
        ['gamla', 'Visa gamla vyn'],
    ]);
}

/** Grönt steg utan att påstå att arbetet är gjort. */
function jeab_ordrar_v3_manual_done_card(array $step): string
{
    return jeab_ordrar_v3_shell(
        (string) $step['id'],
        (int) $step['n'],
        'klar',
        (string) $step['title'],
        'Manuellt klarmarkerad',
        '<div class="v3-summary"><div class="v3-sline"><b>Manuellt klarmarkerad</b> · inget har skickats</div></div>'
    );
}

function jeab_ordrar_v3_menu_sub(array $row, array $step, ?JeabStore $store = null): string
{
    $state = $step['state'];
    if ($state === 'locked') {
        return 'Låst';
    }
    if (!empty($step['manual'])) {
        return 'Manuellt klarmarkerad';
    }
    $id = $step['id'];
    if ($state === 'todo') {
        if ($id === 'faktura' && trim((string) ($row['fortnox_order'] ?? '')) !== '') {
            return 'Order skriven';
        }
        if ($id === 'bekrafta' && jeab_ordrar_v3_bekraftad($row, $store)) {
            return 'Saknas för Fortnox';
        }
        if ($id === 'bestall' && jeab_ordrar_orderrader_saved($row)) {
            return 'Inköpslista sparad';
        }
        return 'Att göra';
    }
    if ($id === 'foranmalan') {
        $parts = [];
        $date = jeab_ordrar_v3_step_raw($row, 'foranmald');
        if ($date === '') {
            $date = trim((string) ($row['fullmakt_start_at'] ?? ''));
        }
        if ($date === '') {
            $date = trim((string) ($row['seom_sam_sent_at'] ?? ''));
        }
        if ($date === '') {
            $date = jeab_ordrar_v3_step_raw($row, 'spec');
        }
        if ($date !== '') {
            $parts[] = jeab_ordrar_v3_date($date);
        }
        $parts[] = jeab_ordrar_v3_foranmalan_short($row);
        return implode(' · ', array_filter($parts, static fn($v) => $v !== ''));
    }
    if ($id === 'bestall') {
        $date = jeab_ordrar_v3_bestall_when($row);
        return 'Beställd' . ($date !== '' ? ' ' . jeab_ordrar_v3_date($date) : '');
    }
    if ($id === 'bekrafta') {
        $date = jeab_ordrar_v3_bekrafta_when($row);
        $booked = jeab_ordrar_install_start($row, $store);
        $parts = [];
        if ($date !== '') {
            $parts[] = jeab_ordrar_v3_date($date);
        }
        if ($booked !== '') {
            $parts[] = 'Bokad ' . jeab_ordrar_v3_date($booked);
        } elseif (trim((string) ($row['fortnox_order'] ?? '')) !== '') {
            $parts[] = 'Order skapad';
        }
        return implode(' · ', $parts);
    }
    if ($id === 'faktura') {
        $date = jeab_ordrar_v3_step_raw($row, 'faktura_skapad');
        $no = jeab_ordrar_invoice_number($row);
        return trim(($date !== '' ? jeab_ordrar_v3_date($date) . ' · ' : '') . 'Faktura ' . ($no !== '' ? $no : 'skapad'));
    }
    if ($id === 'stang') {
        $date = trim((string) ($row['closed_at'] ?? ''));
        return ($date !== '' ? jeab_ordrar_v3_date($date) . ' · ' : '') . 'Stängt';
    }
    return '';
}

/** När Bekräfta gjordes: bekräftad, annars order skriven eller bokningen. */
function jeab_ordrar_v3_bekrafta_when(array $row): string
{
    foreach (['bekraftad', 'order_skriven', 'fatt_alla_uppgifter'] as $key) {
        $raw = jeab_ordrar_v3_step_raw($row, $key);
        if ($raw !== '') {
            return $raw;
        }
    }
    return '';
}

function jeab_ordrar_v3_tile(string $kicker, string $value, string $tone, string $note = ''): string
{
    $valueHtml = e($value);
    return '<div class="v3-tile"><span class="v3-tdot is-' . e($tone) . '"></span><div class="v3-k">' . e($kicker) . '</div><div class="v3-v">' . $valueHtml . '</div>'
        . ($note !== '' ? '<div class="v3-n">' . $note . '</div>' : '') . '</div>';
}

/** Kortet «Objekt & läge»: samma fält som Status i den gamla vyn (ordrar.objekt). */
function jeab_ordrar_v3_overview_html(array $open, string $company, int $openId, ?JeabStore $store = null): string
{
    $address = jeab_ordrar_status_address_lines($open);
    $nat = jeab_ordrar_status_natbolag($open);
    $contact = jeab_ordrar_status_contact($open, $openId);
    $postnrShown = $address['postnummer'];
    $phoneShown = $contact['phone'];
    $emailShown = $contact['email'];
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_contacts_sole_paint_order')) {
        $painted = jeab_contacts_sole_paint_order($open);
        $fromPaint = trim((string) ($painted['postnr'] ?? ''));
        if ($fromPaint === '' && function_exists('jeab_ordrar_format_postnummer')) {
            $fromPaint = jeab_ordrar_format_postnummer((string) ($painted['postnummer'] ?? ''));
        }
        if ($fromPaint === '') {
            $fromPaint = trim((string) ($painted['postnummer'] ?? ''));
        }
        $postnrShown = $fromPaint;
        $phoneShown = trim((string) ($painted['phone'] ?? ''));
        $emailShown = trim((string) ($painted['email'] ?? ''));
    }
    $identity = jeab_ordrar_status_identity($open, $openId);
    $action = jeab_ordrar_url(['co' => $company, 'id' => $openId]);
    $bookedRaw = jeab_ordrar_install_start($open, $store);
    $booked = jeab_ordrar_status_date_label($bookedRaw);
    $calUrl = $booked !== '' ? jeab_ordrar_booking_cal_url($open, $store) : '';
    $kind = jeab_ordrar_ui_kind($open['kind'] ?? '');

    $f = '<form class="v3-objekt" id="v3-f-objekt" method="post" action="' . e($action) . '" data-v3-form="objekt">'
        . jeab_csrf_field()
        . '<input type="hidden" name="action" value="ordrar.objekt"><input type="hidden" name="id" value="' . $openId . '">';
    if (($identity['fastighetsbeteckning_forslag'] ?? '') !== '') {
        $f .= '<input type="hidden" name="objekt_fastighetsbeteckning_forslag" value="' . e((string) $identity['fastighetsbeteckning_forslag']) . '">';
    }
    $f .= '<p class="v3-kick">Objekt</p>'
        . '<label class="v3-orow"><span>Adress</span><input type="text" name="objekt_adress" value="' . e($address['street']) . '" placeholder="Adress" autocomplete="street-address" data-v3-addr></label>'
        . '<div class="v3-orow v3-help-row" data-v3-addr-help hidden><span></span><div class="v3-help">Ny adress med postnummer slår upp Lantmäteriet direkt. Samma sparning som Spara. Ingen bokning, mejl eller Fortnox.</div></div>'
        . '<div class="v3-orow is-addr"><span>Postnr / ort</span><input type="text" name="objekt_postnummer" value="' . e($postnrShown) . '" placeholder="123 45" maxlength="7" inputmode="numeric" autocomplete="postal-code" aria-label="Postnummer"><input type="text" name="objekt_postort" value="' . e($address['place']) . '" placeholder="Ort" autocomplete="address-level2" aria-label="Ort"></div>'
        . '<label class="v3-orow"><span>Mobil</span><input type="tel" name="phone" value="' . e($phoneShown) . '" placeholder="07…" autocomplete="tel" inputmode="tel" data-v3-check="mobil"></label><div class="v3-ferr" data-v3-ferr="mobil" hidden>Måste vara ett svenskt mobilnummer.</div>'
        . '<label class="v3-orow"><span>E-post</span><input type="email" name="email" value="' . e($emailShown) . '" placeholder="namn@example.com" autocomplete="email" inputmode="email" data-v3-check="epost"></label><div class="v3-ferr" data-v3-ferr="epost" hidden>Ogiltig e-postadress.</div>'
        . '<label class="v3-orow"><span>Fastighet</span><input type="text" name="objekt_fastighetsbeteckning" value="' . e((string) ($identity['fastighetsbeteckning'] ?? '')) . '" placeholder="Fastighetsbeteckning" autocomplete="off"></label>'
        . (function_exists('jeab_fastighet_refresh_button') ? '<div class="v3-orow"><span></span><div>' . jeab_fastighet_refresh_button($openId, 'v3-btn is-sec') . '</div></div>' : '')
        . '<label class="v3-orow"><span>Nätägare</span><select name="natbolag">' . ($nat === '' ? '<option value="" selected>Välj</option>' : '');
    foreach (jeab_ordrar_natbolag_options() as $option) {
        $f .= '<option value="' . e($option) . '"' . ($nat === $option ? ' selected' : '') . '>' . e($option) . '</option>';
    }
    $f .= '</select></label>'
        . '<div class="v3-orow"><span></span><div class="v3-rowbtns"><button type="submit" class="v3-btn is-sec" data-v3-sec="objekt" data-working="Sparar…">Spara</button><span class="v3-help is-inline">Sparar bara ordern. Ingen bokning, mejl, sms eller Fortnox.</span></div></div>'
        . '<div class="v3-orow"><span></span><div class="v3-err" data-v3-err="objekt" role="alert" hidden></div></div>'
        . '</form>';

    $rows = [];
    if ($booked !== '') {
        $cal = $calUrl !== ''
            ? '<a class="v3-calbtn" href="' . e($calUrl) . '" title="Öppna bokningen i kalendern" aria-label="Öppna bokningen i kalendern">' . jeab_ordrar_v3_icon('cal') . '</a>'
            : '';
        $rows[] = '<div class="v3-drow"><span>Bokat installationsdatum</span><b>' . e($booked) . $cal . '</b></div>';
    }
    if ($kind === 'sol' || $kind === 'batteri') {
        $wanted = jeab_ordrar_status_date_label(jeab_ordrar_onskad_leverans($open, $store));
        if ($wanted !== '') {
            $rows[] = '<div class="v3-drow"><span>Önskat leveransdatum</span><b>' . e($wanted) . '</b></div>';
        }
    }
    foreach (['Aprilice' => jeab_ordrar_shop_order_no($open, [], 'aprilice'), 'Lightleap' => jeab_ordrar_shop_order_no($open, [], 'lightleap'), 'Fortnox' => trim((string) ($open['fortnox_order'] ?? ''))] as $label => $no) {
        if ($no !== '') {
            $rows[] = '<div class="v3-drow"><span>' . e($label) . '</span><b>' . e($no) . '</b></div>';
        }
    }
    $datum = '<p class="v3-kick">Datum &amp; order</p>' . ($rows !== [] ? implode('', $rows) : '<p class="v3-help2">Inget bokat eller beställt än.</p>');

    // Läge-korten: samma källor som Status i den gamla vyn.
    $tiles = [];
    if ($kind === 'sol' && function_exists('jeab_solkoll_status_for_row')) {
        $doc = jeab_solkoll_status_for_row($open);
        $docLabel = trim((string) ($doc['label'] ?? ''));
        $tone = match ((string) ($doc['key'] ?? '')) {
            'slutford' => 'ok',
            'paborjad' => 'warn',
            'ny' => 'brand',
            default => 'muted',
        };
        $tiles[] = jeab_ordrar_v3_tile('Dokumentation (Solkoll)', $docLabel !== '' ? $docLabel : '–', $tone);
    } elseif ($kind !== 'batteri') {
        $tiles[] = jeab_ordrar_v3_tile('Dokumentation', '–', 'muted');
    }
    $full = function_exists('jeab_ordrar_fullmakt_lage')
        ? jeab_ordrar_fullmakt_lage($open, '#steg-foranmalan')
        : ['label' => '', 'tone' => 'muted', 'href' => ''];
    $fullNote = $full['href'] !== ''
        ? '<a href="' . e($full['href']) . '">Öppna SEOM-fullmakt</a>'
        : '';
    $tiles[] = jeab_ordrar_v3_tile('Fullmakt', $full['label'] !== '' ? $full['label'] : '–', $full['tone'], $fullNote);
    $foranKey = jeab_ordrar_foranmalan_case_status($open);
    $foranLabel = jeab_ordrar_foranmalan_case_label($open);
    $foranTone = match ($foranKey) {
        'fardiganmald' => 'ok',
        'godkand', 'skickad' => 'brand',
        'nekad' => 'danger',
        default => 'muted',
    };
    $comment = jeab_ordrar_status_comment($open);
    $foranArende = trim((string) ($open['foranmalan_arende'] ?? ''));
    $foranSent = jeab_ordrar_v3_step_raw($open, 'foranmald');
    if ($foranLabel !== '' && $foranSent !== '') {
        $foranLabel .= ' ' . jeab_ordrar_v3_date($foranSent);
    }
    $foranSub = trim(($foranArende !== '' ? 'Ärende ' . $foranArende : '') . ($foranArende !== '' && $comment !== '' ? ' · ' : '') . $comment);
    $tiles[] = jeab_ordrar_v3_tile('Föranmälan', $foranLabel !== '' ? $foranLabel : '–', $foranTone, e($foranSub !== '' ? $foranSub : 'Ingen kommentar'));
    if ($kind === 'sol' || $kind === 'batteri') {
        $bestalld = jeab_ordrar_bestalld($open);
        $orderNos = jeab_ordrar_v3_shop_numbers($open);
        $tiles[] = jeab_ordrar_v3_tile(
            'Beställning',
            $bestalld ? 'Beställd' : (jeab_ordrar_orderrader_saved($open) ? 'Inköpslista sparad' : '–'),
            $bestalld ? 'ok' : (jeab_ordrar_orderrader_saved($open) ? 'warn' : 'muted'),
            $orderNos !== [] ? e(implode(' · ', $orderNos)) : ''
        );
    }
    $fx = jeab_ordrar_fakturera_status($open);
    $fxErrTile = trim((string) ($open['fortnox_error'] ?? ''));
    $fxLabel = match ($fx) {
        'faktura_skapad' => 'Faktura skapad',
        'order_skapad' => 'Order skriven',
        default => '–',
    };
    $fxTone = match ($fx) {
        'faktura_skapad' => 'ok',
        'order_skapad' => 'warn',
        default => 'muted',
    };
    $fxNote = '';
    if ($fx === '' && $fxErrTile !== '') {
        $fxLabel = 'Fortnox-fel';
        $fxTone = 'danger';
        $fxNote = e(jeab_ordrar_save_fail_text($fxErrTile));
        if (function_exists('jeab_ordrar_fortnox_pnr_gap') && jeab_ordrar_fortnox_pnr_gap($fxErrTile)) {
            $fxNote .= jeab_ordrar_fortnox_pnr_next_html($open, $openId);
        }
    }
    $tiles[] = jeab_ordrar_v3_tile('Fakturering', $fxLabel, $fxTone, $fxNote);

    return '<section class="v3-card" id="steg-status" data-step="status">'
        . '<div class="v3-chead"><span class="v3-ic is-tag">' . jeab_ordrar_v3_icon('home') . '</span><div class="v3-grow"><h2>Objekt &amp; läge</h2><div class="v3-sub">Kunduppgifter och var ordern står. Läget läses från Bokmalen' . ($kind === 'sol' ? ', Solkoll' : '') . ' och Fortnox.</div></div></div>'
        . '<div class="v3-ov"><div>' . $f . '</div><div>' . $datum . '</div></div>'
        . '<p class="v3-kick v3-kick-lage">Läge</p><div class="v3-tiles">' . implode('', $tiles) . '</div>'
        . '</section>';
}

/** Ägarna och fastigheten (samma fält och dolda fält som den gamla Bekräfta). */
function jeab_ordrar_v3_owners_html(array $open, array $suggest, array $ownerCards, bool $edit): string
{
    $fastighet = trim((string) ($suggest['fastighetsbeteckning'] ?? ''));
    $address = trim((string) ($open['huvudsokande_address'] ?? $open['address'] ?? ''));
    $huvudAddr = trim((string) ($suggest['huvudsokande_address'] ?? $suggest['address'] ?? $address));
    $huvudPlaceholder = (string) ($suggest['huvudsokande_placeholder'] ?? '');
    $h = '<div class="v3-fastrow">'
        . ($edit
            ? '<input type="text" class="v3-fast-input" name="fastighetsbeteckning" value="' . e($fastighet) . '" placeholder="Fastighetsbeteckning" autocomplete="off" data-v3-need="fastighet"' . ($fastighet === '' ? ' aria-invalid="true"' : '') . '>'
            : '<span class="v3-fast-val">' . e($fastighet) . '</span><input type="hidden" name="fastighetsbeteckning" value="' . e($fastighet) . '" data-v3-need="fastighet">')
        . jeab_ordrar_v3_copy($fastighet) . '</div>'
        . ($fastighet === '' ? '<p class="v3-alert" data-v3-missing-fast>Saknas: fastighetsbeteckning.</p>' : '')
        . ($huvudAddr !== '' ? '<div class="v3-fastaddr">' . e($huvudAddr) . '</div>' : '')
        . (function_exists('jeab_fastighet_refresh_button') ? '<div class="v3-fast-refresh">' . jeab_fastighet_refresh_button((int) ($open['reminder_id'] ?? 0), 'v3-btn is-sec') . '</div>' : '')
        . '<div class="v3-owners">';
    foreach ($ownerCards as $i => $owner) {
        $isHuvud = !empty($owner['huvud']);
        $oName = (string) ($owner['name'] ?? '');
        $oPnr = (string) ($owner['personnummer'] ?? '');
        $oAddr = (string) ($owner['address'] ?? $huvudAddr);
        $ownEdit = $edit && $isHuvud;
        $pnrCell = $ownEdit || $oPnr === ''
            ? '<input type="text" class="v3-owner-in" data-field="pnr" value="' . e($oPnr) . '" placeholder="ÅÅÅÅMMDD-XXXX" inputmode="numeric" autocomplete="off"' . ($oPnr === '' ? ' aria-invalid="true"' : '') . '>'
            : '<span class="v3-reveal" data-v3-reveal><span class="v3-mask">' . e(jeab_ordrar_v3_mask($oPnr)) . '</span> <button type="button" class="v3-eye" data-v3="reveal">Visa</button><span class="v3-mask" data-field="pnr" hidden>' . e($oPnr) . '</span></span>';
        $pnrFlag = $oPnr === '' ? '<span class="v3-warnmark" data-v3-missing-pnr>Saknas: personnummer.</span>' : '';
        $h .= '<article class="v3-owner" data-name="' . e($oName) . '" data-pnr="' . e($oPnr) . '" data-addr="' . e($oAddr) . '" data-huvud="' . ($isHuvud ? '1' : '0') . '">'
            . '<header><strong>Ägare ' . ((int) $i + 1) . '</strong><span class="v3-pillsw" role="group" aria-label="Roll">'
            . '<button type="button" class="' . ($isHuvud ? 'is-on' : '') . '" data-v3="huvud" aria-pressed="' . ($isHuvud ? 'true' : 'false') . '">Huvudsökande</button>'
            . '<button type="button" class="' . ($isHuvud ? '' : 'is-on') . '" data-v3="med" aria-pressed="' . ($isHuvud ? 'false' : 'true') . '">Medsökande</button></span></header>'
            . '<div class="v3-kv"><span>Namn:</span>' . ($ownEdit ? '<input type="text" class="v3-owner-in" data-field="name" value="' . e($oName) . '" placeholder="' . e($huvudPlaceholder !== '' ? $huvudPlaceholder : 'Namn') . '" autocomplete="name">' : '<b data-field="name">' . e($oName) . '</b>') . jeab_ordrar_v3_copy($oName) . '</div>'
            . '<div class="v3-kv"><span>Personnummer:</span>' . $pnrCell . $pnrFlag . jeab_ordrar_v3_copy($oPnr) . '</div>'
            . '<div class="v3-kv"><span>Adress:</span>' . ($ownEdit ? '<input type="text" class="v3-owner-in" data-field="address" value="' . e($oAddr) . '" autocomplete="off">' : '<span data-field="address">' . e($oAddr) . '</span>') . jeab_ordrar_v3_copy($oAddr) . '</div>'
            . '</article>';
    }
    $help = 'Hämtat från Lantmäteriet. Alla ägare skickas till Fortnox för grön teknik. Klick på Huvudsökande sparas direkt.';
    if (function_exists('jeab_rot_fetched_address_text')) {
        $fetchedNote = jeab_rot_fetched_address_text($suggest);
        $choiceNote = function_exists('jeab_rot_lookup_choice_text') ? jeab_rot_lookup_choice_text($suggest) : '';
        if ($fetchedNote !== '') {
            $help = $fetchedNote . ($choiceNote !== '' ? ' ' . $choiceNote : '') . ' Alla ägare skickas till Fortnox för grön teknik. Klick på Huvudsökande sparas direkt.';
        }
    }
    return $h . '</div><p class="v3-help">' . e($help) . '</p>';
}

/** Anteckningen läses från samma orderlager som sparningen, inte från en tidigare kopia i minnet. */
function jeab_ordrar_v3_install_note(array $open, int $openId): string
{
    $id = (int) ($open['reminder_id'] ?? 0);
    if ($id <= 0) {
        $id = $openId;
    }
    if ($id > 0 && function_exists('jeab_purchase_cache_forget') && function_exists('jeab_purchase_get')) {
        jeab_purchase_cache_forget();
        $fresh = jeab_purchase_get($id);
        if (is_array($fresh)) {
            return trim((string) ($fresh['install_note'] ?? ''));
        }
    }

    return trim((string) ($open['install_note'] ?? ''));
}

/** Bekräfta-formuläret: samma fältnamn som den gamla vyn (ordrar.spara / ordrar.slutfor / ordrar.pick). */
function jeab_ordrar_v3_bekrafta_form(array $open, string $company, int $openId, ?array $reminder, bool $forceEdit = false): array
{
    $cachedSuggest = $GLOBALS['_JEAB_ORDRAR_SUGGEST'][$openId] ?? null;
    if (is_array($cachedSuggest)) {
        $suggest = $cachedSuggest;
        unset($GLOBALS['_JEAB_ORDRAR_SUGGEST'][$openId]);
    } else {
        $prepared = function_exists('jeab_ordrar_bekrafta_prepare')
            ? jeab_ordrar_bekrafta_prepare($open, $reminder)
            : ['row' => $open, 'suggest' => jeab_ordrar_confirm_suggest($open, $reminder)];
        $open = is_array($prepared['row'] ?? null) ? $prepared['row'] : $open;
        $suggest = is_array($prepared['suggest'] ?? null) ? $prepared['suggest'] : jeab_ordrar_confirm_suggest($open, $reminder);
    }
    $huvud = (string) ($suggest['huvudsokande'] ?? '');
    $huvudPnr = (string) ($suggest['personnummer'] ?? '');
    $address = trim((string) ($suggest['address'] ?? $open['huvudsokande_address'] ?? $open['address'] ?? ''));
    $huvudAddr = trim((string) ($suggest['huvudsokande_address'] ?? $address));
    $fastighet = trim((string) ($suggest['fastighetsbeteckning'] ?? ''));
    $ownerCards = jeab_ordrar_owner_cards($suggest);
    $edit = $forceEdit || $huvud === '' || $huvudPnr === '' || $fastighet === '';
    $kind = jeab_ordrar_ui_kind($open['kind'] ?? '');

    $hidden = jeab_csrf_field()
        . '<input type="hidden" name="action" value="ordrar.spara">'
        . '<input type="hidden" name="id" value="' . $openId . '">'
        . '<input type="hidden" name="huvudsokande" value="' . e($huvud) . '">'
        . '<input type="hidden" name="huvudsokande_personnummer" value="' . e($huvudPnr) . '">'
        . '<input type="hidden" name="huvudsokande_address" value="' . e($huvudAddr) . '">'
        . '<input type="hidden" name="personnummer_forslag" value="' . e($huvudPnr) . '">'
        . '<input type="hidden" name="fastighetsbeteckning_forslag" value="' . e($fastighet) . '">';
    foreach (jeab_ordrar_owner_forslag($open, $suggest, $ownerCards) as $sig) {
        $hidden .= '<input type="hidden" name="owner_forslag[]" value="' . e($sig) . '">';
    }
    foreach ($ownerCards as $owner) {
        $hidden .= '<input type="hidden" name="owner_name[]" value="' . e((string) ($owner['name'] ?? '')) . '" data-v3-canon>'
            . '<input type="hidden" name="owner_pnr[]" value="' . e((string) ($owner['personnummer'] ?? '')) . '" data-v3-canon>'
            . '<input type="hidden" name="owner_address[]" value="' . e((string) ($owner['address'] ?? '')) . '" data-v3-canon>';
        if (!empty($owner['huvud'])) {
            continue;
        }
        $hidden .= '<input type="hidden" name="medsokande_name[]" value="' . e((string) ($owner['name'] ?? '')) . '" data-v3-canon>'
            . '<input type="hidden" name="medsokande_personnummer[]" value="' . e((string) ($owner['personnummer'] ?? '')) . '" data-v3-canon>'
            . '<input type="hidden" name="medsokande_address[]" value="' . e((string) ($owner['address'] ?? '')) . '" data-v3-canon>';
    }

    $block = function_exists('jeab_ordrar_bekrafta_block_message') ? jeab_ordrar_bekrafta_block_message($open) : '';
    $missing = function_exists('jeab_ordrar_bekrafta_ui_missing') ? jeab_ordrar_bekrafta_ui_missing($open) : jeab_ordrar_identity_missing($open);
    if ($block === '' && jeab_ordrar_ui_kind($open['kind'] ?? '') === '') {
        $block = 'Välj produkt.';
    }
    $ownSum = count($ownerCards) . ' ägare · huvudsökande ' . e($huvud !== '' ? $huvud : 'saknas')
        . ($missing !== [] ? ' · <span class="v3-warnmark">saknas: ' . e(implode(', ', $missing)) . '</span>' : '');
    $body = ($block !== '' ? '<p class="v3-alert" data-v3-missing>' . e($block) . '</p>' : '')
        . '<details class="v3-sub-d" data-sub="agare"' . ($missing !== [] || $edit ? ' open' : '') . '><summary><span class="v3-chev">▶</span><span class="v3-t">Fastighet &amp; ägare</span><span class="v3-s">' . e($fastighet !== '' ? $fastighet : '–') . ' · ' . $ownSum . '</span></summary><div class="v3-subbody">'
        . jeab_ordrar_v3_owners_html($open, $suggest, $ownerCards, $edit) . '</div></details>'
        . '<span class="v3-lbl" id="v3-prod-lbl">Produkt</span><div class="v3-seg" role="radiogroup" aria-labelledby="v3-prod-lbl" aria-required="true" data-v3-kind>';
    foreach (jeab_ordrar_v3_products() as $key => $label) {
        $on = $kind === $key;
        $body .= '<label class="v3-seg-opt' . ($on ? ' is-on' : '') . '"><input type="radio" name="kind" value="' . e($key) . '" required' . ($on ? ' checked' : '') . '><span>' . e($label) . '</span></label>';
    }
    $body .= '</div><div class="v3-help">Sparas direkt vid byte. Menyn och stegen ändras efter produkten.</div>'
        . '<p class="v3-alert" data-v3-kind-req hidden>Detta är obligatoriskt.</p>';
    if ($kind === 'sol') {
        $body .= jeab_ordrar_v3_kalkyl_html($open);
    } elseif ($kind === 'batteri') {
        $body .= jeab_ordrar_v3_sigen_kalkyl_html($open);
    }
    $price = jeab_ordrar_att_betala_amount($open);
    $body .= '<div class="v3-fgrid">'
        . '<div class="v3-f is-full"><label for="v3-what">Vad som ska installeras</label><input id="v3-what" type="text" name="install_what" value="' . e((string) ($open['install_what'] ?? '')) . '" autocomplete="off"><div class="v3-help">Detta blir specifikationen på fakturan och följer med i kalenderbokningen.</div></div>'
        . '<div class="v3-f"><label for="v3-pris">Att betala</label><input id="v3-pris" type="text" name="pris" inputmode="numeric" value="' . ($price > 0 ? e(jeab_ordrar_kr($price)) : '') . '" autocomplete="off" data-v3-need="pris"' . ($price > 0 ? '' : ' aria-invalid="true"') . '><div class="v3-help">Det kunden betalar efter grön teknik. Samma belopp som i Fakturera.</div>'
        . ($price > 0 ? '' : '<p class="v3-alert" data-v3-missing-pris>Saknas: pris.</p>')
        . '</div>'
        . jeab_ordrar_v3_att_betala_error_html($open)
        . '<div class="v3-f"><label for="v3-note">Anteckning till installation</label><div class="v3-fieldline"><textarea id="v3-note" name="install_note" rows="3" data-v3-autosave-action="ordrar.install_note">' . e(jeab_ordrar_v3_install_note($open, $openId)) . '</textarea><p class="v3-autosave" data-v3-field-status role="status"></p></div><div class="v3-help">Syns inte för kunden. Följer med i bokningen i kalendern till installatören.</div></div>'
        . '</div>'
        . jeab_ordrar_payouts_html($open);
    $form = '<form id="v3-f-bekrafta" method="post" action="' . e(jeab_ordrar_url(['co' => $company, 'id' => $openId])) . '" data-v3-form="bekrafta">' . $hidden . '<fieldset class="v3-cbody">' . $body . '</fieldset></form>';
    return ['html' => $form, 'huvud' => $huvud, 'owners' => $ownerCards];
}

function jeab_ordrar_v3_bekrafta_html(array $open, string $company, int $openId, array $step, ?JeabStore $store, ?array $reminder): string
{
    $state = $step['state'];
    $n = (int) $step['n'];
    $kind = jeab_ordrar_ui_kind($open['kind'] ?? '');
    $form = jeab_ordrar_v3_bekrafta_form($open, $company, $openId, $reminder);
    if ($state === 'klar') {
        $orderNo = trim((string) ($open['fortnox_order'] ?? ''));
        $fxUrl = jeab_ordrar_fortnox_order_url($open);
        $bookedRaw = jeab_ordrar_install_start($open, $store);
        $calUrl = $bookedRaw !== '' ? jeab_ordrar_booking_cal_url($open, $store) : '';
        $price = jeab_ordrar_fortnox_price($open);
        $owners = $form['owners'];
        $med = max(0, count($owners) - 1);
        $when = jeab_ordrar_v3_bekrafta_when($open);
        $facts = [jeab_ordrar_v3_products()[$kind] ?? ''];
        $facts[] = e($form['huvud'] !== '' ? $form['huvud'] : jeab_ordrar_name($open)) . ($med > 0 ? ' + ' . $med . ' medsökande' : '');
        if ($price > 0) {
            $facts[] = e(jeab_ordrar_v3_kr($price)) . ' efter grön teknik';
        }
        $sigenFact = function_exists('jeab_sigen_summary_label') ? jeab_sigen_summary_label($open) : '';
        if ($sigenFact !== '') {
            $facts[] = e($sigenFact);
        }
        if ($when !== '') {
            $facts[] = e(jeab_ordrar_v3_date($when));
        }
        $links = [];
        if ($orderNo !== '') {
            $links[] = $fxUrl !== ''
                ? '<a class="v3-lnk" href="' . e($fxUrl) . '" target="_blank" rel="noopener">Fortnox-order ' . e($orderNo) . ' ' . jeab_ordrar_v3_icon('ext') . '</a>'
                : '<span class="v3-lnk is-plain">Fortnox-order ' . e($orderNo) . '</span>';
        }
        if ($bookedRaw !== '') {
            $links[] = '<a class="v3-lnk" href="' . e($calUrl) . '">Bokad tid i kalendern: ' . e(jeab_ordrar_v3_date($bookedRaw)) . ' ' . jeab_ordrar_v3_icon('ext') . '</a>';
        }
        $open = function_exists('jeab_webshop_view_sync') ? jeab_webshop_view_sync($open) : $open;
        $fxErr = trim((string) ($open['fortnox_error'] ?? ''));
        $summary = '<div class="v3-summary" data-v3-summary><div class="v3-sline"><b>' . ($orderNo !== '' ? 'Fortnox-order skapad' : 'Bekräftad') . '</b> · '
            . ($bookedRaw !== '' ? 'Installation ' . e(jeab_ordrar_v3_date($bookedRaw)) : 'Ingen tid bokad') . '</div>'
            . '<div class="v3-sfacts">' . implode(' · ', array_filter($facts, static fn($v) => $v !== '')) . '</div>'
            . ($orderNo === '' && $fxErr !== '' ? '<p class="v3-alert">' . e(jeab_ordrar_save_fail_text($fxErr)) . ' Bokningen gjordes ändå. Fakturering kan skapa ordern.</p>'
                . (function_exists('jeab_ordrar_fortnox_pnr_gap') && jeab_ordrar_fortnox_pnr_gap($fxErr) ? jeab_ordrar_fortnox_pnr_next_html($open, $openId) : '') : '')
            . ($links !== [] ? '<div class="v3-links">' . implode('', $links) . '</div>' : '')
            . ($bookedRaw !== '' ? '<p class="v3-help2" data-v3-kundmail>' . e(jeab_ordrar_v3_kundbekraftelse_status($open, $store)) . '</p>' : '')
            . jeab_ordrar_payouts_summary_html($open)
            . ($orderNo === '' ? '<p class="v3-under"><button type="submit" form="v3-f-bekrafta" class="v3-btn is-primary" name="action" value="ordrar.fortnox_utan_bokning" data-v3-primary="bekrafta" data-working="Skapar order…">Skapa Fortnox-order</button></p>' : '')
            . '</div>';
        $edit = '<div class="v3-editbox" data-v3-edit="bekrafta" hidden>' . $form['html']
            . '<div class="v3-rowbtns v3-edit-actions"><button type="submit" form="v3-f-bekrafta" class="v3-btn is-sec" name="action" value="ordrar.spara" data-v3-sec="bekrafta" data-working="Sparar…">Spara</button><button type="button" class="v3-btn is-ghost" data-v3="close-edit">Stäng</button></div>'
            . '<div class="v3-err" data-v3-err="bekrafta" role="alert" hidden></div></div>';
        return jeab_ordrar_v3_shell('bekrafta', $n, 'klar', 'Bekräfta', '', $summary . $edit, jeab_ordrar_v3_kebab([['edit-bekrafta', 'Ändra uppgifter…']]));
    }
    $secondary = '<button type="submit" form="v3-f-bekrafta" class="v3-btn is-sec" name="action" value="ordrar.spara" data-v3-sec="bekrafta" data-working="Sparar…">Spara</button>';
    $utanBokning = '<p class="v3-under"><button type="submit" form="v3-f-bekrafta" class="v3-textlink" name="action" value="ordrar.fortnox_utan_bokning" data-v3-utan-bokning="1" data-working="Skapar order…" title="Skriver ordern i Fortnox. Ingen tid bokas och inget skickas till kunden.">Skapa Fortnox-order (utan bokning)</button></p>';
    $block = function_exists('jeab_ordrar_bekrafta_block_message') ? jeab_ordrar_bekrafta_block_message($open) : '';
    $fxErrTodo = trim((string) ($open['fortnox_error'] ?? ''));
    $pnrNext = $fxErrTodo !== '' && function_exists('jeab_ordrar_fortnox_pnr_gap') && jeab_ordrar_fortnox_pnr_gap($fxErrTodo)
        ? jeab_ordrar_fortnox_pnr_next_html($open, $openId)
        : '';
    if (jeab_ordrar_v3_bekraftad($open, $store)) {
        // Tiden är bokad men Fortnox-ordern kunde inte skrivas: uppgifter saknas.
        $bookedRaw = jeab_ordrar_install_start($open, $store);
        $actions = jeab_ordrar_v3_actions(
            'bekrafta',
            'Skapa Fortnox-order',
            'Skapar order…',
            'Tiden är redan bokad. Ordern skrivs i Fortnox när uppgifterna är kompletta.',
            'v3-f-bekrafta',
            'ordrar.fortnox_utan_bokning',
            $secondary,
            '',
            $block !== ''
        );
        $sub = ($bookedRaw !== '' ? 'Bokad ' . e(jeab_ordrar_v3_date($bookedRaw)) . '. ' : '') . 'Fyll i det som saknas så skrivs ordern i Fortnox.';
        return jeab_ordrar_v3_shell('bekrafta', $n, 'todo', 'Bekräfta', $sub, $form['html'] . $pnrNext . $actions);
    }
    $booksLater = jeab_ordrar_books_at_bestall($open);
    $actions = jeab_ordrar_v3_actions(
        'bekrafta',
        $booksLater ? 'Skapa order' : 'Skapa order och boka',
        'Skapar order…',
        $block !== '' ? $block : ($booksLater
            ? 'När ärendet slutförs skrivs ordern i Fortnox. Installationen bokas i Beställ när du bekräftar förslaget.'
            : 'När ärendet slutförs skrivs ordern i Fortnox och första lediga tiden i kalendern bokas. Datumet kan ändras ovan.'),
        'v3-f-bekrafta',
        'ordrar.slutfor',
        $secondary,
        '',
        $block !== '',
        $utanBokning
    );
    $slotHtml = '';
    if (!$booksLater && $store instanceof JeabStore) {
        $slotHtml = jeab_ordrar_proposed_slot_html($store, $open, 'v3-help2');
        if ($slotHtml !== '') {
            $slotHtml .= jeab_ordrar_v3_install_date_html($open, $store, 'v3-bekrafta-install', 'v3-f-bekrafta');
        }
    }
    return jeab_ordrar_v3_shell(
        'bekrafta',
        $n,
        'todo',
        'Bekräfta',
        $booksLater
            ? 'Kontrollera uppgifterna. Ordern skapas i Fortnox. Installationen bokas senare i Beställ.'
            : 'Kontrollera uppgifterna. Ordern skapas i Fortnox och första lediga tid bokas. Välj ett annat datum för att byta lucka.',
        $form['html'] . $slotHtml . $pnrNext . $actions,
        jeab_ordrar_v3_kebab([['utan-bokning', 'Skapa Fortnox-order (utan bokning)']])
    );
}

/** Läser om kundbekräftelsen redan gått. Skickar inget mejl och ingen sms. */
function jeab_ordrar_v3_kundbekraftelse_status(array $row, ?JeabStore $store = null): string
{
    $bookingId = (int) ($row['booking_id'] ?? 0);
    if ($bookingId <= 0 || !function_exists('jeab_sms_sent_row')) {
        return '';
    }
    $start = function_exists('jeab_ordrar_install_start') ? jeab_ordrar_install_start($row, $store) : '';
    $booking = null;
    if ($store instanceof JeabStore && function_exists('jeab_ordrar_linked_booking')) {
        $linked = jeab_ordrar_linked_booking($row, $store);
        $booking = is_array($linked) ? $linked : null;
    }
    if (!is_array($booking)) {
        $booking = [
            'id' => $bookingId,
            'starts_at' => $start,
            'status' => 'booked',
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
    if (($booking['status'] ?? 'booked') === 'cancelled') {
        return '';
    }
    $sent = jeab_sms_sent_row($bookingId, (string) ($booking['starts_at'] ?? $start));
    $emailAt = trim((string) ($sent['email_at'] ?? ''));
    if (!empty($sent['email']) || $emailAt !== '') {
        return 'Kundbekräftelse skickad' . ($emailAt !== '' ? ' ' . jeab_ordrar_v3_date($emailAt) : '') . '.';
    }
    if (function_exists('jeab_sol_calendar_customer_mail_blocked') && jeab_sol_calendar_customer_mail_blocked($booking)) {
        return 'Ingen kundbekräftelse skickas för den här bokningen.';
    }
    if (function_exists('jeab_booking_mail_age_ready') && !jeab_booking_mail_age_ready($booking, jeab_now())) {
        return 'Kundbekräftelse tidigast två timmar efter senaste ändring.';
    }
    return 'Kundbekräftelse kan skickas. Inget har gått än.';
}

function jeab_ordrar_v3_fx_rows_html(array $lines, bool $readonly): string
{
    $choices = jeab_ordrar_fortnox_housework_choices();
    if ($readonly) {
        $html = '<table class="v3-tbl is-ro"><thead><tr><th>Artikelnr</th><th>Benämning</th><th>Husarbete</th><th>Antal</th><th>À-pris</th><th class="is-num">Summa</th></tr></thead><tbody>';
        $mlist = '<div class="v3-mlist">';
        foreach ($lines as $line) {
            if (trim((string) $line['description']) === '' && trim((string) $line['article']) === '' && trim((string) $line['price']) === '') {
                continue;
            }
            $hw = $choices[(string) $line['housework']] ?? (string) $line['housework'];
            $sum = jeab_ordrar_fortnox_line_sum_text($line);
            $html .= '<tr><td>' . e((string) $line['article']) . '</td><td>' . e((string) $line['description']) . '</td><td>' . e($hw) . '</td><td>' . e((string) $line['qty']) . '</td><td>' . e((string) $line['price']) . '</td><td class="is-num">' . e($sum) . '</td></tr>';
            $mlist .= '<div class="v3-li"><span class="v3-mn">' . e((string) $line['description']) . '<small>' . e((string) ($line['qty'] !== '' ? $line['qty'] : '1')) . ' × ' . e((string) $line['price']) . ' · ' . e($hw) . '</small></span><span class="v3-ma">' . e($sum) . '</span></div>';
        }
        return $html . '</tbody></table>' . $mlist . '</div>';
    }
    $head = '<div class="v3-fxhead" aria-hidden="true"><span>Artikelnr</span><span>Benämning</span><span>Husarbete</span><span>Antal</span><span>À-pris</span><span class="is-num">Summa</span></div>';
    $rows = '';
    foreach ($lines as $i => $line) {
        $sum = jeab_ordrar_fortnox_line_sum_text($line);
        $desc = (string) $line['description'];
        $qty = (string) ($line['qty'] ?? '');
        $rows .= '<details class="v3-fxrow" open data-rowid="' . e((string) $line['row_id']) . '" data-v3-fxrow>'
            . '<summary><span class="v3-mn' . ($desc === '' ? ' is-empty' : '') . '" data-v3-fxn>' . e($desc !== '' ? $desc : 'Tom rad') . '</span><span class="v3-mq" data-v3-fxq>' . e($qty !== '' ? $qty . ' st' : '') . '</span><span class="v3-ma" data-v3-fxsum>' . e($sum !== '' ? $sum : '–') . '</span></summary>'
            . '<div class="v3-fxbody">'
            . '<input type="hidden" name="fx_rowid[]" value="' . e((string) $line['row_id']) . '">'
            . '<input type="hidden" name="fx_touched[]" value="0" data-v3-fx-touched>'
            . '<label class="v3-fx-art"><span>Artikelnr</span><input type="text" name="fx_article[]" value="' . e((string) $line['article']) . '" inputmode="text" autocomplete="off"></label>'
            . '<label class="v3-fx-desc"><span>Benämning</span><input type="text" name="fx_desc[]" value="' . e($desc) . '" autocomplete="off"></label>'
            . jeab_ordrar_v3_fx_hw_select('fx_hw[' . (int) $i . ']', $choices, (string) $line['housework'])
            . '<label class="v3-fx-qty"><span>Antal</span><input type="text" name="fx_qty[]" inputmode="decimal" value="' . e($qty) . '" autocomplete="off"></label>'
            . '<label class="v3-fx-price"><span>À-pris</span><input type="text" name="fx_price[]" inputmode="decimal" value="' . e((string) $line['price']) . '" autocomplete="off"></label>'
            . '<span class="v3-fx-sum is-num" data-v3-fxsum>' . e($sum !== '' ? $sum : '–') . '</span>'
            . '</div></details>';
    }
    return '<div class="v3-fxlines">' . $head . $rows . '</div>';
}

/** Husarbete på fakturaraden. Samma koder som Fortnox HouseWorkType. Etiketten syns i kolumnhuvudet, och på mobil via label > span. */
function jeab_ordrar_v3_fx_hw_select(string $name, array $choices, string $current): string
{
    $html = '<label class="v3-fx-hw"><span>Husarbete</span><select name="' . e($name) . '">';
    foreach ($choices as $value => $label) {
        $selected = (string) $value === $current ? ' selected' : '';
        $html .= '<option value="' . e((string) $value) . '"' . $selected . '>' . e((string) $label) . '</option>';
    }
    return $html . '</select></label>';
}

function jeab_ordrar_v3_att_betala_error_html(array $open): string
{
    $error = jeab_ordrar_att_betala_error($open);
    if ($error === '') {
        return '';
    }
    return '<p class="v3-alert" data-v3-pay-error>' . e($error) . '</p>';
}

/** Samma kundpris som Bekräfta. Ett lagrat belopp, två fält. */
function jeab_ordrar_v3_att_betala_field(array $open): string
{
    $amount = jeab_ordrar_att_betala_amount($open);
    $value = $amount > 0 ? jeab_ordrar_kr($amount) : '';
    return '<div class="v3-fxpay"><label for="v3-faktura-att-betala">Att betala</label>'
        . '<input id="v3-faktura-att-betala" name="pris" inputmode="numeric" autocomplete="off" value="' . e($value) . '"></div>';
}

function jeab_ordrar_v3_faktura_html(array $open, string $company, int $openId, array $step): string
{
    $open = function_exists('jeab_ordrar_display_row') ? jeab_ordrar_display_row($open) : $open;
    $customer = function_exists('jeab_ordrar_fakturering_customer')
        ? jeab_ordrar_fakturering_customer($open)
        : ['name' => '', 'email' => '', 'phone' => '', 'address' => ''];
    $state = $step['state'];
    $n = (int) $step['n'];
    if ($state === 'locked') {
        return jeab_ordrar_v3_shell('faktura', $n, 'locked', 'Fakturering', e($step['reason']), '');
    }
    $orderNo = trim((string) ($open['fortnox_order'] ?? ''));
    $fxUrl = jeab_ordrar_fortnox_order_url($open);
    $data = jeab_ordrar_v3_fakturering_data($open);
    $orderLink = $orderNo !== ''
        ? ($fxUrl !== '' ? '<a class="v3-lnk" href="' . e($fxUrl) . '" target="_blank" rel="noopener">' . e($orderNo) . ' ' . jeab_ordrar_v3_icon('ext') . '</a>' : e($orderNo))
        : '';
    if ($state === 'klar') {
        $invoice = $data['invoice'] !== '' ? $data['invoice'] : jeab_ordrar_invoice_number($open);
        $row = $open;
        if ($invoice !== '' && jeab_ordrar_invoice_number($row) === '') {
            $row['fortnox_invoice'] = $invoice;
        }
        $invUrl = jeab_ordrar_fortnox_invoice_url($row);
        $date = jeab_ordrar_v3_step_raw($open, 'faktura_skapad');
        $pay = $data['pay'] !== '' ? $data['pay'] : trim((string) ($open['fortnox_att_betala'] ?? ''));
        $links = [];
        if ($invUrl !== '') {
            $links[] = '<a class="v3-lnk" href="' . e($invUrl) . '" target="_blank" rel="noopener">Öppna faktura ' . e($invoice) . ' ' . jeab_ordrar_v3_icon('ext') . '</a>';
        }
        if ($orderNo !== '' && $fxUrl !== '') {
            $links[] = '<a class="v3-lnk" href="' . e($fxUrl) . '" target="_blank" rel="noopener">Fortnox-order ' . e($orderNo) . ' ' . jeab_ordrar_v3_icon('ext') . '</a>';
        }
        $facts = [];
        if ($date !== '') {
            $facts[] = e(jeab_ordrar_v3_date($date));
        }
        if ($orderNo !== '') {
            $facts[] = 'Fortnox-order ' . e($orderNo);
        }
        $facts[] = 'belopp hämtat från Fortnox';
        $count = 0;
        foreach ($data['lines'] as $line) {
            if (trim((string) $line['description']) !== '' || trim((string) $line['price']) !== '') {
                $count++;
            }
        }
        $creditNo = trim((string) ($open['fortnox_credit_invoice'] ?? ''));
        $cancelled = !empty($open['fortnox_invoice_cancelled']);
        if ($creditNo !== '') {
            $creditRow = $open;
            $creditRow['fortnox_invoice'] = $creditNo;
            $creditUrl = jeab_ordrar_fortnox_invoice_url($creditRow);
            $links[] = $creditUrl !== ''
                ? '<a class="v3-lnk" href="' . e($creditUrl) . '" target="_blank" rel="noopener">Kreditfaktura ' . e($creditNo) . ' ' . jeab_ordrar_v3_icon('ext') . '</a>'
                : '<span class="v3-lnk is-plain">Kreditfaktura ' . e($creditNo) . '</span>';
        }
        $void = '';
        if ($invoice !== '' && !$cancelled && $creditNo === '') {
            $post = e(jeab_ordrar_url(['co' => $company, 'id' => $openId]));
            $void = '<form id="v3-f-makulera" method="post" action="' . $post . '" data-v3-form="faktura-void" hidden>' . jeab_csrf_field()
                . '<input type="hidden" name="action" value="ordrar.faktura_makulera"><input type="hidden" name="id" value="' . $openId . '"></form>'
                . '<form id="v3-f-kreditera" method="post" action="' . $post . '" data-v3-form="faktura-void" hidden>' . jeab_csrf_field()
                . '<input type="hidden" name="action" value="ordrar.faktura_kreditera"><input type="hidden" name="id" value="' . $openId . '"></form>'
                . '<div class="v3-actrow"><button type="submit" form="v3-f-makulera" class="v3-btn is-sec" data-v3-hold="1">Makulera</button>'
                . '<button type="submit" form="v3-f-kreditera" class="v3-btn is-sec" data-v3-hold="1">Kreditera</button></div>'
                . '<p class="v3-help">Makulera och kreditera skickas direkt till Fortnox. En bokförd faktura makuleras inte, den krediteras.</p>';
        } elseif ($cancelled) {
            $void = '<p class="v3-help">Fakturan är makulerad.</p>';
        }
        $summary = '<div class="v3-summary"><div class="v3-sline"><b>Faktura ' . e($invoice !== '' ? $invoice : '') . ' skapad</b>' . ($pay !== '' ? ' · Att betala ' . e($pay) : '') . '</div>'
            . '<div class="v3-sfacts">' . implode(' · ', $facts) . '</div>'
            . ($links !== [] ? '<div class="v3-links">' . implode('', $links) . '</div>' : '')
            . ($count > 0 ? '<details class="v3-sub-d" data-sub="fx_ro"><summary><span class="v3-chev">▶</span><span class="v3-t">Fakturarader</span><span class="v3-s">' . $count . ' rader</span></summary><div class="v3-subbody">' . jeab_ordrar_v3_fx_rows_html($data['lines'], true) . '</div></details>' : '')
            . jeab_ordrar_payouts_summary_html($open)
            . jeab_ordrar_payout_retry_html($open, $company, $openId)
            . $void
            . '</div>';
        return jeab_ordrar_v3_shell('faktura', $n, 'klar', 'Fakturering', '', $summary);
    }

    $errors = '';
    if ($data['error'] !== '') {
        $errors .= '<p class="v3-alert">' . e(jeab_ordrar_save_fail_text($data['error'])) . ($data['source'] === 'snapshot' ? ' Visar senast sparade rader.' : '') . '</p>';
    }
    $gtError = trim((string) ($open['fortnox_gt_error'] ?? ''));
    if ($gtError !== '') {
        $errors .= '<p class="v3-alert">' . e(jeab_ordrar_save_fail_text($gtError)) . '</p>';
    }
    $open = function_exists('jeab_webshop_view_sync') ? jeab_webshop_view_sync($open) : $open;
    $fxErr = trim((string) ($open['fortnox_error'] ?? ''));
    if ($orderNo === '' && $fxErr !== '') {
        $errors .= '<p class="v3-alert">' . e(jeab_ordrar_save_fail_text($fxErr)) . '</p>';
        if (function_exists('jeab_ordrar_fortnox_pnr_gap') && jeab_ordrar_fortnox_pnr_gap($fxErr)) {
            $errors .= jeab_ordrar_fortnox_pnr_next_html($open, $openId);
        }
    }
    $payError = jeab_ordrar_att_betala_error($open);
    $head = $orderNo !== ''
        ? '<p class="v3-help2 is-first">Fortnox-order: ' . $orderLink . '</p>'
        : '<p class="v3-help2 is-first">Ingen Fortnox-order ännu.' . ($data['source'] === 'kalkyl' ? ' Raderna är förifyllda från Kalkylen.' : '') . ' Spara skapar ordern och skriver raderna.</p>';
    $form = '<form id="v3-f-faktura" method="post" action="' . e(jeab_ordrar_url(['co' => $company, 'id' => $openId])) . '" data-v3-form="faktura"' . jeab_ordrar_fx_gt_attrs($open) . ' data-sole-name="' . e($customer['name']) . '" data-sole-email="' . e($customer['email']) . '" data-sole-phone="' . e($customer['phone']) . '" data-sole-address="' . e($customer['address']) . '">'
        . jeab_csrf_field()
        . '<input type="hidden" name="action" value="ordrar.fakturering"><input type="hidden" name="id" value="' . $openId . '">'
        . '<fieldset class="v3-cbody">' . $errors . $head
        . ($payError !== '' ? '<p class="v3-alert" data-v3-pay-error>' . e($payError) . '</p>' : '')
        . jeab_ordrar_v3_fx_rows_html($data['lines'], false)
        . jeab_ordrar_v3_att_betala_field($open)
        . '<label class="v3-confirmline"><input type="checkbox" name="faktura_bekrafta" value="1" data-v3-faktura-check> Belopp och rader är bekräftade</label>'
        . '<div class="v3-stale" data-v3-stale hidden>Ändrat. Orörda rader räknas om. Handredigerade rader lämnas.</div>'
        . '<p class="v3-note">Att betala är det kunden betalar efter grön teknik. Orörda rader räknas om och sparas. Handredigerade rader lämnas.</p>'
        . jeab_ordrar_payouts_html($open)
        . '</fieldset></form>';
    $blocked = $payError !== '';
    $form = str_replace(
        '<input type="hidden" name="action" value="ordrar.fakturering">',
        '<input type="hidden" name="action" value="ordrar.fakturering"><input type="hidden" name="faktura_bekrafta" value="1" data-v3-faktura-flag disabled>',
        $form
    );
    $secondary = '<button type="submit" form="v3-f-faktura" class="v3-btn is-sec" name="intent" value="save" data-v3-sec="faktura" data-working="Sparar…"' . ($blocked ? ' disabled' : '') . '>Spara</button>';
    $what = $payError !== '' ? $payError : 'Visar belopp och rader. Fakturan skapas i Fortnox direkt när du trycker. Raderna nedan är de som skrivs till Fortnox. Inget skickas till kunden.';
    $actions = jeab_ordrar_v3_actions('faktura', 'Skapa faktura', 'Skapar faktura…', $what, 'v3-f-faktura', '', $secondary, 'faktura', $blocked, '', true);
    return jeab_ordrar_v3_shell('faktura', $n, 'todo', 'Fakturering', 'Att betala är samma belopp som i Bekräfta. Husarbete styr grön teknik.', $form . $actions);
}

function jeab_ordrar_v3_stang_html(array $open, string $company, int $openId, array $step): string
{
    $state = $step['state'];
    $n = (int) $step['n'];
    if ($state === 'locked') {
        return jeab_ordrar_v3_shell('stang', $n, 'locked', 'Stäng ärendet', e($step['reason']), '');
    }
    if ($state === 'klar') {
        $date = trim((string) ($open['closed_at'] ?? ''));
        return jeab_ordrar_v3_shell('stang', $n, 'klar', 'Stäng ärendet', '', '<div class="v3-summary"><div class="v3-sline"><b>Ärendet stängt</b>' . ($date !== '' ? ' ' . e(jeab_ordrar_v3_date($date)) : '') . ' · ligger under Allt klart</div></div>');
    }
    $kind = jeab_ordrar_ui_kind($open['kind'] ?? '');
    $checks = [['Riktig Fortnox-faktura finns', jeab_ordrar_has_invoice($open)]];
    if ($kind === 'sol' || $kind === 'batteri') {
        $checks[] = ['Föranmälan Färdiganmäld', jeab_ordrar_fardiganmalan_klar($open)];
    }
    if ($kind === 'sol') {
        $checks[] = ['Solkoll Slutförd eller 4/4', jeab_ordrar_solkoll_dokumentation_klar($open)];
    }
    $list = '<ul class="v3-checklist">';
    foreach ($checks as $c) {
        $list .= '<li class="' . ($c[1] ? 'is-y' : 'is-n') . '">' . e($c[0]) . '</li>';
    }
    $list .= '</ul>';
    $form = '<form id="v3-f-stang" method="post" action="' . e(jeab_ordrar_url(['co' => $company, 'id' => $openId])) . '" data-v3-form="stang">'
        . jeab_csrf_field()
        . '<input type="hidden" name="action" value="ordrar.stang"><input type="hidden" name="id" value="' . $openId . '"><input type="hidden" name="v3" value="1"></form>';
    $sub = $kind === 'laddbox'
        ? 'Fakturan finns. Stäng ärendet så flyttas ordern till Allt klart.'
        : 'Ordern flyttas till Allt klart automatiskt när allt nedan är uppfyllt. Du kan också stänga själv.';
    $actions = jeab_ordrar_v3_actions('stang', 'Allt är fakturerat och klart, stäng ärendet', 'Stänger…', 'Ordern flyttas till Allt klart.', 'v3-f-stang', 'ordrar.stang');
    return jeab_ordrar_v3_shell('stang', $n, 'todo', 'Stäng ärendet', e($sub), $list . $form . $actions);
}

/* ================= Batteri och sol: Föranmälan, Boka, Beställ ================= */

/** Segmentväljare med radioknappar (samma utseende som Produkt). */
function jeab_ordrar_v3_seg(string $name, array $choices, string $current, string $label, string $attr = ''): string
{
    $id = 'v3-seg-' . preg_replace('/[^a-z0-9_-]/i', '', $name);
    $html = '<span class="v3-lbl" id="' . e($id) . '">' . e($label) . '</span><div class="v3-seg" role="radiogroup" aria-labelledby="' . e($id) . '"' . $attr . '>';
    foreach ($choices as $value => $text) {
        $on = (string) $value === $current;
        $html .= '<label class="v3-seg-opt' . ($on ? ' is-on' : '') . '"><input type="radio" name="' . e($name) . '" value="' . e((string) $value) . '"' . ($on ? ' checked' : '') . '><span>' . e((string) $text) . '</span></label>';
    }
    return $html . '</div>';
}

/** Kort läge för menyn: Skickad / Godkänd / Fullmakt skickad / Utkast / Manuell. */
function jeab_ordrar_v3_foranmalan_short(array $row): string
{
    $status = jeab_ordrar_foranmalan_status($row);
    if ($status === 'C') {
        return 'Manuell';
    }
    if ($status === 'B') {
        return 'Utkast';
    }
    $case = jeab_ordrar_foranmalan_case_label($row);
    if ($case !== '') {
        return $case;
    }
    if (function_exists('jeab_ellevio_fullmakt_applies') && jeab_ellevio_fullmakt_applies($row)) {
        return !empty($row['fullmakt_signed']) ? 'Fullmakt signerad' : 'Fullmakt skickad';
    }
    if (function_exists('jeab_seom_fullmakt_applies') && jeab_seom_fullmakt_applies($row)) {
        return match (jeab_seom_fullmakt_status($row)) {
            'kund_signerad' => 'Kund signerad',
            'kund_skickad' => 'Skickad till kund',
            'sam_skickad' => 'Till Sam',
            default => 'SEOM',
        };
    }
    return 'Skickad';
}

/** När beställningen lades (tid om den finns). */
function jeab_ordrar_v3_bestall_when(array $row): string
{
    $at = trim((string) ($row['bestalld_at'] ?? ''));
    return $at !== '' ? $at : jeab_ordrar_v3_step_raw($row, 'bestalld');
}

/** Ordernummer hos webshopparna: ["Aprilice 2026104078", "Lightleap KO9"]. */
function jeab_ordrar_v3_shop_numbers(array $row): array
{
    $out = [];
    foreach (['aprilice' => 'Aprilice', 'lightleap' => 'Lightleap'] as $shop => $label) {
        $no = jeab_ordrar_shop_order_no($row, [], $shop);
        if ($no !== '') {
            $out[] = $label . ' ' . $no;
        }
    }
    return $out;
}

/**
 * Kostnad för beställningen (kr) om Bokmalen skickat den. 0 = okänd.
 * Finns supplier_lines är summan deras belopp exkl. moms (Aprilice oförändrat,
 * Lightleap omräknat från inkl. moms). Annars bestall_kostnad som den sparats.
 */
function jeab_ordrar_v3_bestall_kostnad(array $row): int
{
    if (function_exists('jeab_ordrar_supplier_cost_sum')) {
        $sum = jeab_ordrar_supplier_cost_sum($row);
        if ($sum > 0) {
            return $sum;
        }
    }
    foreach (['bestall_kostnad', 'shop_kostnad'] as $key) {
        $raw = $row[$key] ?? null;
        if (is_int($raw) || is_float($raw)) {
            return (int) round((float) $raw);
        }
        if (is_string($raw)) {
            $clean = str_replace(["\u{00A0}", "\u{202F}", ' ', 'kr'], '', $raw);
            $clean = str_replace(',', '.', $clean);
            if (is_numeric($clean)) {
                return (int) round((float) $clean);
            }
        }
    }
    return 0;
}

/** Läget hos nätbolaget som Bokmalen rapporterat (Skickad, Nekad, Godkänd, Färdiganmäld). */
function jeab_ordrar_v3_case_pills(array $row): string
{
    $current = jeab_ordrar_foranmalan_case_status($row);
    $html = '<div class="v3-pills" role="list" aria-label="Läge hos nätbolaget">';
    foreach (jeab_ordrar_foranmalan_case_statuses() as $value => $label) {
        $on = $current === $value;
        $tone = $on ? match ($value) {
            'fardiganmald' => 'ok',
            'nekad' => 'danger',
            default => 'brand',
        } : 'muted';
        $html .= '<span class="v3-pill is-' . e($tone) . ($on ? ' is-on' : '') . '" role="listitem"' . ($on ? ' aria-current="true"' : '') . '>' . e($label) . '</span>';
    }
    return $html . '</div>';
}

/** Ellevio: fullmaktens läge och ev. «Skicka igen» (ordrar.fullmakt_resend, samma som gamla vyn). */
function jeab_ordrar_v3_fullmakt_html(array $open, string $company, int $openId, bool $idleHint): string
{
    if (!function_exists('jeab_ellevio_fullmakt_state') || !jeab_ellevio_fullmakt_applies($open)) {
        return '';
    }
    $f = jeab_ellevio_fullmakt_state($open);
    $err = trim((string) ($f['error'] ?? ''));
    $started = trim((string) ($f['token'] ?? '')) !== '' || $err !== '' || !empty($f['sent']) || !empty($f['signed']);
    if (!$started && !$idleHint) {
        return '';
    }
    if (!empty($f['signed'])) {
        $text = 'Fullmakten är signerad.';
    } elseif (!empty($f['viewed'])) {
        $text = 'Kunden har öppnat fullmakten. Vi väntar på signering.';
    } elseif (!empty($f['handed_off'])) {
        $text = 'Fullmakten är startad hos Bokmalen. Vi väntar på signering.';
    } elseif (!empty($f['sent'])) {
        $via = function_exists('jeab_system_mail_path_label') ? jeab_system_mail_path_label(trim((string) ($open['fullmakt_mail_path'] ?? ''))) : '';
        $text = 'Fullmakten är skickad till kunden' . ($via !== '' ? ' via ' . $via : '') . '. Vi väntar på signering.';
    } elseif ($err !== '') {
        $text = '';
    } else {
        $text = 'Ellevio: kunden signerar en fullmakt. Bokmalen gör föranmälan när den är signerad.';
    }
    $html = '<div class="v3-fullmakt">' . ($text !== '' ? '<p class="v3-help2">' . e($text) . '</p>' : '')
        . ($err !== '' ? '<p class="v3-alert">' . e(jeab_ordrar_save_fail_text($err)) . '</p>' : '');
    $retry = function_exists('jeab_ellevio_fullmakt_should_resend') && jeab_ellevio_fullmakt_should_resend($open) && empty($f['viewed']);
    if ($retry) {
        $html .= '<form id="v3-f-fullmakt" method="post" action="' . e(jeab_ordrar_url(['co' => $company, 'id' => $openId])) . '" data-v3-form="fullmakt">'
            . jeab_csrf_field()
            . '<input type="hidden" name="action" value="ordrar.fullmakt_resend"><input type="hidden" name="from" value="eon"><input type="hidden" name="id" value="' . $openId . '">'
            . '<button type="submit" class="v3-btn is-sec" data-v3-sec="fullmakt" data-working="Skickar…" aria-busy="false">Skicka fullmakten igen</button></form>';
    }
    if (function_exists('jeab_ellevio_fullmakt_show_new') && jeab_ellevio_fullmakt_show_new($open)) {
        $html .= jeab_ellevio_fullmakt_new_form_html(jeab_ordrar_url(['co' => $company, 'id' => $openId]), $openId, 'eon', true);
    }
    return $html . '</div>';
}

function jeab_ordrar_v3_plomb_applies(array $row): bool
{
    return in_array(jeab_ordrar_v3_kind($row), ['sol', 'batteri', 'laddbox', 'servicejobb'], true);
}

function jeab_ordrar_v3_plomb_status(string $sentAt): string
{
    $sentAt = trim($sentAt);
    if ($sentAt === '') {
        return '';
    }
    try {
        $dt = new DateTimeImmutable($sentAt, new DateTimeZone('Europe/Stockholm'));
    } catch (Exception) {
        return 'Plombbrytning begärd';
    }
    return 'Plombbrytning begärd ' . $dt->format('d/m');
}

/** Knapp i föranmälan för sol, batteri, elbilsladdare och servicejobb. Efter skick ersätts den av status. */
function jeab_ordrar_v3_plomb_html(array $open, string $company, int $openId): string
{
    $sentAt = trim((string) ($open['plombbrytning_sent_at'] ?? ''));
    if ($sentAt !== '') {
        $status = jeab_ordrar_v3_plomb_status($sentAt);
        return '<p class="v3-plomb-status" data-v3-plomb-status>' . e($status !== '' ? $status : 'Plombbrytning begärd') . '</p>';
    }
    $closed = jeab_ordrar_allt_klart($open);
    $button = '<button type="submit" class="v3-btn is-sec v3-plomb-btn" data-working="Skickar…"'
        . ($closed ? ' disabled' : '')
        . '>Plombbrytning</button>';
    if ($closed) {
        return '<div class="v3-plomb">' . $button . '</div>';
    }
    $action = jeab_ordrar_url(['co' => $company, 'id' => $openId]);
    return '<form class="v3-plomb" method="post" action="' . e($action) . '" data-v3-form="plomb">'
        . jeab_csrf_field()
        . '<input type="hidden" name="action" value="ordrar.plombbrytning"><input type="hidden" name="id" value="' . $openId . '">'
        . '<input type="hidden" name="plombbrytning" value="1">'
        . $button
        . '<span class="v3-help">Föranmäler plombbrytning hos nätägaren via Bokmalen.</span>'
        . '<div class="v3-err" data-v3-err="plomb" role="alert" hidden></div>'
        . '</form>';
}

/** Föranmälan-kortet för elbilsladdare och servicejobb: bara plombrutan. */
function jeab_ordrar_v3_plomb_section(array $open, string $company, int $openId): string
{
    $sentAt = trim((string) ($open['plombbrytning_sent_at'] ?? ''));
    $sub = $sentAt !== '' ? 'Plombbrytning är begärd hos nätägaren.' : 'Föranmäl plombbrytning på mätartavlan hos nätägaren.';
    return '<section class="v3-card" id="steg-foranmalan" data-step="foranmalan">'
        . '<div class="v3-chead"><div class="v3-grow"><h2>Föranmälan</h2><div class="v3-sub">' . e($sub) . '</div></div></div>'
        . jeab_ordrar_v3_plomb_html($open, $company, $openId)
        . '</section>';
}

/** Följdfrågorna (samma fält som gamla Föranmälan: ordrar.batterisol). */
function jeab_ordrar_v3_foranmalan_fields(array $open): string
{
    $vaxel = trim((string) ($open['vaxelriktare'] ?? ''));
    $nat = jeab_ordrar_status_natbolag($open);
    $aid = jeab_ordrar_anlaggnings_id_digits((string) ($open['anlaggnings_id'] ?? ''));
    $natChoices = [];
    foreach (jeab_ordrar_natbolag_options() as $opt) {
        $natChoices[$opt] = $opt;
    }
    return '<div class="v3-fgrid">'
        . '<div class="v3-f"><label for="v3-vaxel">Växelriktare</label><input id="v3-vaxel" type="text" name="vaxelriktare" value="' . e($vaxel) . '" placeholder="Välj växelriktare" autocomplete="off" data-v3-need="vaxel"></div>'
        . '<div class="v3-f"><label for="v3-kwh">kWh</label><input id="v3-kwh" type="text" name="kwh" value="' . e((string) ($open['kwh'] ?? '')) . '" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" title="Tal större än 0, komma eller punkt" autocomplete="off" data-v3-need="kwh"></div>'
        . '<div class="v3-f is-full" data-v3-aid-row><label for="v3-aid">Anläggnings-id</label><input id="v3-aid" type="text" name="anlaggnings_id" value="' . e($aid) . '" inputmode="numeric" spellcheck="false" autocomplete="off" data-v3-need="aid"' . ($nat === 'SEOM' ? ' required aria-required="true"' : '') . '>'
        . '<div class="v3-help" data-v3-aid-help' . ($nat === 'Ellevio' ? ' hidden' : '') . '>Står på elräkningen. 18 siffror.</div>'
        . '<div class="v3-help" data-v3-aid-ellevio' . ($nat === 'Ellevio' ? '' : ' hidden') . '>Ellevio: kunden fyller i anläggnings-id i fullmakten.</div>'
        . '<div class="v3-help" data-v3-aid-seom' . ($nat === 'SEOM' ? '' : ' hidden') . '>SEOM: anläggnings-id krävs innan blanketten skapas.</div></div>'
        . '</div>'
        . jeab_ordrar_v3_seg('natbolag', $natChoices, $nat, 'Nätbolag', ' data-v3-nat')
        . (trim((string) ($open['natbolag'] ?? '')) === '' && $nat !== ''
            ? '<div class="v3-help" data-v3-nat-prefill>Nätbolag är förifyllt från adressen.</div>'
            : ($nat === '' ? '<div class="v3-help">Ingen säker träff på adressen. Välj nätbolag.</div>' : ''));
}

function jeab_ordrar_v3_foranmalan_html(array $open, string $company, int $openId, array $step): string
{
    $state = $step['state'];
    $n = (int) $step['n'];
    if ($state === 'locked') {
        return jeab_ordrar_v3_shell('foranmalan', $n, 'locked', 'Föranmälan', e($step['reason']), jeab_ordrar_v3_plomb_html($open, $company, $openId));
    }
    $action = jeab_ordrar_url(['co' => $company, 'id' => $openId]);
    $nat = jeab_ordrar_status_natbolag($open);
    $ellevio = $nat === 'Ellevio';
    $seom = $nat === 'SEOM';
    $mode = jeab_ordrar_foranmalan_status($open);
    if ($mode === '') {
        $mode = 'A';
    }
    $missing = function_exists('jeab_ordrar_bokmalen_missing') ? jeab_ordrar_bokmalen_missing($open, 'ordrar.foranmalan') : [];
    $hidden = jeab_csrf_field() . '<input type="hidden" name="action" value="ordrar.batterisol"><input type="hidden" name="id" value="' . $openId . '">';

    if ($state === 'klar') {
        $steps = is_array($open['steps'] ?? null) ? $open['steps'] : [];
        $sentAt = trim((string) ($steps['foranmald'] ?? ''));
        $short = jeab_ordrar_v3_foranmalan_short($open);
        if ($mode === 'C') {
            $line = '<b>Manuell föranmälan</b> · görs utanför appen';
        } elseif ($mode === 'B') {
            $line = '<b>Sparad som utkast</b> · inget skickat till Bokmalen';
        } elseif ($ellevio && $sentAt === '') {
            $line = '<b>' . e($short) . '</b> · Bokmalen gör föranmälan hos Ellevio efter signering';
        } elseif ($seom && $sentAt === '') {
            $line = '<b>' . e($short) . '</b> · blanketten är skickad till Sam';
        } else {
            $line = '<b>Föranmälan skickad' . ($sentAt !== '' ? ' ' . e(jeab_ordrar_v3_date($sentAt)) : '') . '</b> · Bokmalen har ärendet';
        }
        $facts = array_filter([
            $nat,
            trim((string) ($open['anlaggnings_id'] ?? '')) !== '' ? 'Anl.-id ' . jeab_ordrar_anlaggnings_id_digits((string) $open['anlaggnings_id']) : '',
            trim((string) ($open['kwh'] ?? '')) !== '' ? trim((string) $open['kwh']) . ' kWh' : '',
            trim((string) ($open['vaxelriktare'] ?? '')),
        ], static fn($v) => $v !== '');
        $resent = trim((string) ($open['foranmalan_resent_at'] ?? ''));
        $comment = jeab_ordrar_status_comment($open);
        $statusAt = trim((string) ($open['foranmalan_status_at'] ?? ''));
        $refreshUrl = jeab_ordrar_url(['co' => $company, 'id' => $openId]) . '#steg-foranmalan';
        $canResend = $mode === 'A' && !$ellevio && $sentAt !== '' && jeab_ordrar_foranmalan_can_resend($open);
        $lage = '';
        if ($mode === 'A') {
            $lage = '<p class="v3-kick v3-kick-lage">Läge hos nätbolaget</p>' . jeab_ordrar_v3_case_pills($open)
                . ($comment !== '' ? '<p class="v3-help2">Kommentar från Bokmalen: ' . e($comment) . '</p>' : '')
                . '<div class="v3-links"><a class="v3-lnk is-plain" href="' . e($refreshUrl) . '" data-v3="status-refresh"><span class="v3-ricon">' . jeab_ordrar_v3_icon('refresh') . '</span> Uppdatera status</a>'
                . '<span class="v3-help is-inline">Senast från Bokmalen' . ($statusAt !== '' ? ' ' . e(jeab_ordrar_v3_date($statusAt, false, true)) : '') . '. Hämtar läget från Bokmalen.</span></div>';
        }
        $summary = '<div class="v3-summary" data-v3-summary><div class="v3-sline">' . $line . '</div>'
            . jeab_ordrar_v3_plomb_html($open, $company, $openId)
            . ($facts !== [] ? '<div class="v3-sfacts">' . e(implode(' · ', $facts)) . '</div>' : '')
            . ($resent !== '' ? '<div class="v3-sfacts">Skickad igen ' . e(jeab_ordrar_v3_date($resent, false, true)) . '</div>' : '')
            . $lage
            . ($canResend
                ? '<div class="v3-actrow"><button type="submit" form="v3-f-resend" class="v3-btn is-sec" data-v3-hold="1" data-v3-resend="1">Skicka igen</button></div><p class="v3-help">Skickas direkt när du trycker.</p>'
                : '')
            . jeab_ordrar_v3_fullmakt_html($open, $company, $openId, false)
            . (function_exists('jeab_seom_fullmakt_panel_html') ? jeab_seom_fullmakt_panel_html($open, $action) : '')
            . '</div>';
        $kebab = [];
        if ($canResend) {
            $case = jeab_ordrar_foranmalan_case_status($open);
            $kebab[] = ['resend-foranmalan', $case === 'nekad' ? 'Skicka igen…' : 'Skicka föranmälan igen…'];
        }
        $kebab[] = ['edit-foranmalan', 'Ändra följdfrågor…'];
        $resendForm = $canResend
            ? '<form id="v3-f-resend" method="post" action="' . e($action) . '" data-v3-form="foranmalan" hidden>' . jeab_csrf_field()
                . '<input type="hidden" name="action" value="ordrar.foranmalan_resend"><input type="hidden" name="id" value="' . $openId . '"></form>'
            : '';
        $statusForm = $mode === 'A'
            ? '<form id="v3-f-status" method="post" action="' . e($action) . '" hidden>' . jeab_csrf_field()
                . '<input type="hidden" name="action" value="ordrar.foranmalan_status_live"><input type="hidden" name="id" value="' . $openId . '"></form>'
            : '';
        // Ändra: bara spara (ingen intent=next) – skickar inget.
        $edit = '<div class="v3-editbox" data-v3-edit="foranmalan" hidden><form id="v3-f-foranmalan" method="post" action="' . e($action) . '" data-v3-form="foranmalan">' . $hidden
            . '<input type="hidden" name="foranmalan_status" value="' . e($mode) . '"><fieldset class="v3-cbody">' . jeab_ordrar_v3_foranmalan_fields($open) . '</fieldset></form>'
            . '<div class="v3-rowbtns v3-edit-actions"><button type="submit" form="v3-f-foranmalan" class="v3-btn is-sec" data-v3-sec="foranmalan" data-working="Sparar…">Spara</button><button type="button" class="v3-btn is-ghost" data-v3="close-edit" data-step="foranmalan">Stäng</button>'
            . '<span class="v3-help is-inline">Sparar bara uppgifterna. Inget skickas.</span></div></div>';
        $err = '<div class="v3-err" data-v3-err="foranmalan" role="alert" hidden></div>';
        return jeab_ordrar_v3_shell('foranmalan', $n, 'klar', 'Föranmälan', '', $summary . $edit . $resendForm . $statusForm . $err, jeab_ordrar_v3_kebab($kebab));
    }

    $modes = jeab_ordrar_foranmalan_choices();
    $sendFailed = $mode === 'A' && !$ellevio && !$seom && jeab_ordrar_foranmalan_send_failed($open);
    $body = '<fieldset class="v3-cbody">'
        . '<p class="v3-help2" data-v3-foran-idle>Steget är startat. Inget skickas till nätbolaget förrän du trycker på knappen. Ångra inom sex sekunder stoppar skicket.</p>'
        . jeab_ordrar_v3_foranmalan_fields($open)
        . jeab_ordrar_v3_seg('foranmalan_status', $modes, $mode, 'Föranmälan', ' data-v3-mode')
        . '<div class="v3-help" data-v3-mode-help></div>'
        . ($missing !== [] ? '<p class="v3-alert" data-v3-bm-missing>Saknas för Bokmalen: ' . e(implode(', ', $missing)) . '.</p>' : '')
        . ($sendFailed
            ? '<p class="v3-alert" role="alert" data-v3-foran-fail>' . e(jeab_ordrar_foranmalan_send_fail_message()) . '</p>'
                . '<p class="v3-help">Status: ' . e(jeab_ordrar_foranmalan_unsent_status()) . '.</p>'
            : '')
        . '</fieldset>';
    $form = '<form id="v3-f-foranmalan" method="post" action="' . e($action) . '" data-v3-form="foranmalan" data-nat="' . e($nat) . '">' . $hidden . $body . '</form>';
    $label = $mode === 'B' ? 'Spara som utkast' : ($mode === 'C' ? 'Markera som manuell' : ($ellevio ? 'Skicka fullmakt' : ($seom ? 'Skicka SEOM-blankett' : ($sendFailed ? 'Skicka igen' : 'Skicka föranmälan'))));
    $saveLink = '<p class="v3-under"><button type="submit" form="v3-f-foranmalan" class="v3-textlink" data-v3-sec="foranmalan" data-working="Sparar…"' . ($mode === 'A' ? '' : ' hidden') . '>Spara utan att skicka</button></p>';
    $actions = jeab_ordrar_v3_actions(
        'foranmalan',
        $label,
        'Skickar…',
        $ellevio ? 'Bokmalen skickar fullmakten till kunden. Föranmälan görs när den är signerad.' : ($seom ? 'Blanketten mejlas till Sam för signering. Kunden får den bara om du skickar manuellt och SEOM_CUSTOMER_SEND är på.' : 'Föranmälan skickas till Bokmalen som anmäler hos nätbolaget.'),
        'v3-f-foranmalan',
        '',
        '',
        'next',
        false,
        $saveLink,
        $mode === 'A'
    );
    $seomPanel = function_exists('jeab_seom_fullmakt_panel_html') ? jeab_seom_fullmakt_panel_html($open, $action) : '';
    return jeab_ordrar_v3_shell('foranmalan', $n, 'todo', 'Föranmälan', 'Följdfrågorna är redo. Inget skickas till nätbolaget förrän du trycker på knappen. Ångra inom sex sekunder stoppar skicket.', $form . jeab_ordrar_v3_plomb_html($open, $company, $openId) . jeab_ordrar_v3_fullmakt_html($open, $company, $openId, false) . $seomPanel . $actions);
}

/** Datumväljare som Boka och Bekräfta använder. Luckan blir första lediga tid den dagen eller senare. */
function jeab_ordrar_v3_install_date_html(array $open, ?JeabStore $store, string $id, string $formId = ''): string
{
    $ymd = jeab_ordrar_suggest_install_ymd($open, $store);
    $clock = function_exists('jeab_ordrar_install_clock') ? jeab_ordrar_install_clock((string) ($open['install_time'] ?? '')) : '';
    $formAttr = $formId !== '' ? ' form="' . e($formId) . '"' : '';
    return '<div class="v3-fgrid"><div class="v3-f"><label for="' . e($id) . '">Installation</label>'
        . '<input id="' . e($id) . '" type="date" name="install_date"' . $formAttr . ' value="' . e($ymd) . '"></div>'
        . '<div class="v3-f"><label for="' . e($id) . '-tid">Klockslag</label>'
        . '<input id="' . e($id) . '-tid" type="time" name="install_time"' . $formAttr . ' value="' . e($clock) . '">'
        . '<div class="v3-help">Klockslaget väljer förmiddag eller eftermiddag. Batteri bokas som heldag 07–16. Tomt klockslag tar förmiddagen först.</div>'
        . '<div class="v3-help">' . e(jeab_ordrar_book_lead_help((string) ($open['kind'] ?? ''))) . '</div></div></div>';
}

function jeab_ordrar_v3_boka_html(array $open, string $company, int $openId, array $step, ?JeabStore $store): string
{
    $n = (int) $step['n'];
    $slotHtml = $store instanceof JeabStore ? jeab_ordrar_proposed_slot_html($store, $open, 'v3-help2') : '';
    $form = '<form id="v3-f-boka" method="post" action="' . e(jeab_ordrar_url(['co' => $company, 'id' => $openId])) . '" data-v3-form="boka">'
        . jeab_csrf_field()
        . '<input type="hidden" name="action" value="ordrar.boka"><input type="hidden" name="id" value="' . $openId . '">'
        . '<fieldset class="v3-cbody">' . $slotHtml
        . jeab_ordrar_v3_install_date_html($open, $store, 'v3-install')
        . '</fieldset></form>';
    $actions = jeab_ordrar_v3_actions('boka', 'Boka', 'Bokar…', 'Bokar första lediga tiden på eller efter valt datum.', 'v3-f-boka', 'ordrar.boka');
    return jeab_ordrar_v3_shell('boka', $n, 'todo', 'Boka', 'Ingen tid är bokad. Föreslagen tid visas ovanför datumet.', $form . $actions);
}

/** En inköpsrad räknas om den har artikelnr, namn eller antal över 0 (mallens tomma rader har antal 0). */
function jeab_ordrar_v3_ink_row_has(array $line): bool
{
    if (trim((string) ($line['sku'] ?? $line['artikelnummer'] ?? '')) !== '' || trim((string) ($line['title'] ?? $line['artikelnamn'] ?? '')) !== '') {
        return true;
    }
    $qty = str_replace(',', '.', trim((string) ($line['qty'] ?? $line['antal'] ?? '')));
    return is_numeric($qty) && (float) $qty > 0;
}

/** Inköpslistan (samma fält och mall-JS som gamla vyn: ordrar.orderrader + ordrar-mall). Sparas automatiskt. */
function jeab_ordrar_v3_inkop_html(array $open, string $company, int $openId, bool $readonly): string
{
    $kind = jeab_ordrar_ui_kind($open['kind'] ?? '');
    $lines = jeab_ordrar_inkop_pad(is_array($open['inkop_lines'] ?? null) ? $open['inkop_lines'] : []);
    if ($readonly) {
        $used = array_values(array_filter($lines, static fn(array $l): bool => jeab_ordrar_v3_ink_row_has($l)));
        if ($used === []) {
            return '';
        }
        $tbl = '<table class="v3-tbl is-ro"><thead><tr><th>Artikelnr</th><th>Artikelnamn</th><th class="is-num">Antal</th></tr></thead><tbody>';
        $ml = '<div class="v3-mlist">';
        foreach ($used as $l) {
            $tbl .= '<tr><td>' . e((string) $l['sku']) . '</td><td>' . e((string) $l['title']) . '</td><td class="is-num">' . e(jeab_ordrar_inkop_qty_input($l['qty'] ?? '')) . '</td></tr>';
            $ml .= '<div class="v3-li"><span class="v3-mn">' . e((string) ($l['title'] !== '' ? $l['title'] : $l['sku'])) . '<small>' . e((string) $l['sku']) . '</small></span><span class="v3-ma">' . e(jeab_ordrar_inkop_qty_input($l['qty'] ?? '')) . ' st</span></div>';
        }
        return '<details class="v3-sub-d" data-sub="inkop_ro"><summary><span class="v3-chev">▶</span><span class="v3-t">Inköpslista</span><span class="v3-s">' . count($used) . ' rader</span></summary><div class="v3-subbody">' . $tbl . '</tbody></table>' . $ml . '</div></div></details>';
    }
    $mallar = function_exists('jeab_inkop_list') ? jeab_inkop_list() : [];
    $mallKind = $kind === 'sol' ? 'sol' : 'batteri';
    $used = count(array_filter($lines, static fn(array $l): bool => jeab_ordrar_v3_ink_row_has($l)));
    $h = '<form id="v3-f-inkop" class="v3-inkop" method="post" action="' . e(jeab_ordrar_url(['co' => $company, 'id' => $openId])) . '" data-v3-autosave>'
        . jeab_csrf_field()
        . '<input type="hidden" name="action" value="ordrar.orderrader"><input type="hidden" name="id" value="' . $openId . '">'
        . '<div class="ordrar-mall v3-mall" data-js="ordrar-mall" data-action="' . e(jeab_url('index.php')) . '" data-rows-start="' . (int) jeab_ordrar_inkop_start_rows() . '" data-rows-step="' . (int) jeab_ordrar_inkop_more_rows() . '" data-rows-max="' . (int) jeab_ordrar_inkop_max_rows() . '" data-mallar="' . e((string) json_encode($mallar, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '">'
        . '<div class="v3-mallrow"><div class="inkop-kind ordrar-mall-kind v3-seg" role="group" aria-label="Mallens produkt">'
        . '<button type="button" class="chip v3-chip' . ($mallKind === 'batteri' ? ' is-on' : '') . '" data-kind="batteri">Batteri</button>'
        . '<button type="button" class="chip v3-chip' . ($mallKind === 'sol' ? ' is-on' : '') . '" data-kind="sol">Solpaneler</button></div>'
        . '<label class="v3-mallpick"><span class="v3-sr">Mall</span><select class="ordrar-mall-pick" data-js="ordrar-mall-pick" aria-label="Mall"><option value="">Välj mall…</option>';
    foreach (array_slice($mallar, 0, 10) as $mall) {
        if (!is_array($mall)) {
            continue;
        }
        $h .= '<option value="' . e((string) ($mall['id'] ?? '')) . '">' . e((string) ($mall['name'] ?? '')) . '</option>';
    }
    $h .= '</select></label></div>'
        . '<div class="v3-inkhead" aria-hidden="true"><span>Artikelnr</span><span>Artikelnamn</span><span class="is-num">Antal</span></div>'
        . '<div class="ordrar-lines v3-lines">';
    foreach ($lines as $l) {
        $h .= '<div class="ordrar-line">'
            . '<input type="text" name="line_sku[]" value="' . e((string) $l['sku']) . '" placeholder="Artikelnummer" autocomplete="off" aria-label="Artikelnummer">'
            . '<input type="text" name="line_title[]" value="' . e((string) $l['title']) . '" placeholder="Artikelnamn" autocomplete="off" aria-label="Artikelnamn">'
            . '<input type="number" name="line_qty[]" value="' . e(jeab_ordrar_inkop_qty_input($l['qty'] ?? '')) . '" placeholder="Antal" min="0" step="1" inputmode="numeric" aria-label="Antal">'
            . '</div>';
    }
    $h .= '</div>'
        . '<div class="v3-mlines" data-v3-mlines aria-label="Inköpslista"></div>'
        . '<div class="v3-rowbtns"><button type="button" class="v3-btn is-sec" data-js="ordrar-lines-more"' . (count($lines) >= jeab_ordrar_inkop_max_rows() ? ' hidden' : '') . '>Lägg till 5 rader</button>'
        . '<span class="v3-mallsave"><input class="ordrar-mall-name" data-js="ordrar-mall-name" type="text" maxlength="80" placeholder="Namn på mall" autocomplete="off" aria-label="Namn på mall"><button type="button" class="v3-btn is-sec" data-js="ordrar-mall-save">Spara som mall</button></span>'
        . '<p class="v3-autosave" data-v3-autosave-status role="status">' . ($used > 0 ? 'Sparad.' : 'Sparas automatiskt.') . '</p></div>'
        . '<p class="v3-help" data-js="ordrar-mall-status" role="status"></p>'
        . '</div>'
        . '</form>';
    return '<details class="v3-sub-d" data-sub="inkop" open><summary><span class="v3-chev">▶</span><span class="v3-t">Inköpslista</span><span class="v3-s" data-v3-inkop-sum>' . ($used > 0 ? $used . ($used === 1 ? ' rad' : ' rader') : 'Tom') . ' · sparas automatiskt</span></summary><div class="v3-subbody">' . $h . '</div></details>';
}

function jeab_ordrar_v3_bestall_html(array $open, string $company, int $openId, array $step, ?JeabStore $store): string
{
    $state = $step['state'];
    $n = (int) $step['n'];
    if ($state === 'locked') {
        return jeab_ordrar_v3_shell('bestall', $n, 'locked', 'Beställ', e($step['reason']), '');
    }
    $kind = jeab_ordrar_ui_kind($open['kind'] ?? '');
    $solkoll = $kind === 'sol' ? jeab_ordrar_v3_solkoll_html($open, $company, $openId) : '';
    if ($state === 'klar') {
        $when = jeab_ordrar_v3_bestall_when($open);
        $delivery = jeab_ordrar_actual_delivery($open);
        if ($delivery === '') {
            $delivery = jeab_ordrar_onskad_leverans($open, $store);
        }
        $nos = jeab_ordrar_v3_shop_numbers($open);
        $cost = jeab_ordrar_v3_bestall_kostnad($open);
        $kv = static fn(string $k, string $v): string => '<div class="v3-drow"><span>' . e($k) . '</span><b>' . e($v !== '' ? $v : '–') . '</b></div>';
        $summary = '<div class="v3-summary" data-v3-summary><div class="v3-sumgrid">'
            . $kv('Orderdatum', $when !== '' ? jeab_ordrar_v3_date($when, true) : '')
            . $kv('Leveransdatum', $delivery !== '' ? jeab_ordrar_v3_date($delivery, true) : '')
            . $kv('Ordernummer', implode(' · ', $nos))
            . $kv('Kostnad', $cost > 0 ? jeab_ordrar_v3_kr($cost) : '')
            . '</div></div>';
        return jeab_ordrar_v3_shell('bestall', $n, 'klar', 'Beställ', '', $summary . jeab_ordrar_v3_inkop_html($open, $company, $openId, true) . $solkoll);
    }
    $mode = jeab_ordrar_bestallning_status($open);
    if ($mode === '') {
        $mode = 'A';
    }
    $proposal = $store instanceof JeabStore ? jeab_ordrar_install_proposal($store, $open) : null;
    $form = '<form id="v3-f-bestall" method="post" action="' . e(jeab_ordrar_url(['co' => $company, 'id' => $openId])) . '" data-v3-form="bestall">'
        . jeab_csrf_field()
        . '<input type="hidden" name="action" value="ordrar.bestall_steg"><input type="hidden" name="id" value="' . $openId . '">'
        . '<fieldset class="v3-cbody v3-bestall-f">'
        . jeab_ordrar_proposal_block_html($proposal)
        . jeab_ordrar_v3_seg('bestallning_status', jeab_ordrar_bestallning_choices(), $mode, 'Beställning', ' data-v3-bmode')
        . '</fieldset></form>';
    $label = $mode === 'B' ? 'Skicka utkast till Bokmalen' : ($mode === 'C' ? 'Markera som beställd' : 'Beställ');
    $actions = jeab_ordrar_v3_actions(
        'bestall',
        $label,
        'Beställer…',
        'Bokmalen lägger ordern hos Aprilice/Lightleap med raderna ovan. Installationen bokas med tiden ovan när du beställer. Utkast bokar inte.',
        'v3-f-bestall',
        '',
        '',
        'next'
    );
    return jeab_ordrar_v3_shell('bestall', $n, 'todo', 'Beställ', 'Inköpslistan sparas automatiskt. Steget blir klart först när beställningen är lagd.', jeab_ordrar_v3_inkop_html($open, $company, $openId, false) . $solkoll . $form . $actions);
}

/**
 * Batteri-kalkyl: först typ (NEO, TP2, EC), sedan storlek för den typen.
 * Antal batterier är samma stegare som övriga Kalkylen. Batteri, bottenplatta
 * och TP2-tillägg läggs till från config/sigen_priser.php.
 */
function jeab_ordrar_v3_sigen_kalkyl_html(array $open): string
{
    if (!function_exists('jeab_sigen_catalog') || !function_exists('jeab_offert_input_from_purchase')) {
        return '';
    }
    $input = jeab_offert_input_from_purchase($open, 'batteri');
    $kalkyl = is_array($open['kalkyl'] ?? null) ? $open['kalkyl'] : [];
    $selectedType = strtolower(trim((string) ($kalkyl['sigen_typ'] ?? '')));
    $selectedKw = jeab_sigen_kw_key((string) ($kalkyl['sigen_kw'] ?? ''));
    if (!in_array($selectedKw, jeab_sigen_sizes($selectedType), true)) {
        $selectedKw = '';
    }
    if (!in_array($selectedType, jeab_sigen_types(), true)) {
        $selectedType = '';
        $selectedKw = '';
    }
    $qty = array_key_exists('b3', $kalkyl)
        ? max(0, (int) round(jeab_offert_num($kalkyl['b3'])))
        : max(0, (int) round((float) ($input['b3'] ?? 0)));
    $sokande = max(1, (int) round((float) ($input['sokande'] ?? 1)));
    $choice = $selectedType !== '' ? jeab_sigen_resolve($selectedType, $selectedKw, $qty) : null;
    $sum = $choice === null
        ? 'Välj typ'
        : jeab_sigen_summary_label($open + ['kalkyl' => $kalkyl + ['b3' => $qty]]);
    $catalog = jeab_sigen_client_catalog();
    $json = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $h = '<details class="v3-sub-d v3-sigen" data-sub="sigen" data-sigen="' . e($json !== false ? $json : '{}') . '" open>'
        . '<summary><span class="v3-chev">▶</span><span class="v3-t">Kalkyl</span><span class="v3-s" data-v3-sigen-sum>' . e($sum) . '</span></summary>'
        . '<div class="v3-subbody">'
        . '<input type="hidden" name="kalkyl_present" value="1">'
        . '<input type="hidden" name="sokande" value="' . $sokande . '">'
        . '<span class="v3-lbl" id="v3-sigen-typ">1. Typ</span><div class="v3-seg" role="radiogroup" aria-labelledby="v3-sigen-typ" data-sigen-types>';
    foreach (jeab_sigen_catalog() as $type => $row) {
        $on = $selectedType === $type;
        $label = (string) ($row['label'] ?? $type);
        $h .= '<label class="v3-seg-opt' . ($on ? ' is-on' : '') . '"><input type="radio" name="sigen_typ" value="' . e($type) . '"' . ($on ? ' checked' : '') . '><span>' . e($label) . '</span></label>';
    }
    $h .= '</div>';
    $step2Hidden = $selectedType === '' ? ' hidden' : '';
    $h .= '<div data-sigen-step2' . $step2Hidden . '><span class="v3-lbl" id="v3-sigen-kw">2. Växelriktare</span><div class="v3-seg" role="radiogroup" aria-labelledby="v3-sigen-kw" data-sigen-sizes>';
    foreach (jeab_sigen_catalog() as $type => $row) {
        foreach (is_array($row['sizes'] ?? null) ? $row['sizes'] : [] as $kw => $size) {
            if (!is_array($size)) {
                continue;
            }
            $on = $selectedType === $type && $selectedKw === (string) $kw;
            $show = $selectedType === $type;
            $h .= '<label class="v3-seg-opt' . ($on ? ' is-on' : '') . '" data-sigen-type="' . e($type) . '"' . ($show ? '' : ' hidden') . '>'
                . '<input type="radio" name="sigen_kw" value="' . e((string) $kw) . '"' . ($on ? ' checked' : '') . ($show ? '' : ' disabled') . '>'
                . '<span>' . e((string) $kw) . ' kW</span></label>';
        }
    }
    $h .= '</div></div>';
    $h .= '<label class="offert-field offert-mini"><span>Batterimoduler</span><span class="offert-step">'
        . '<button type="button" data-js="sigen-step" data-delta="-1" aria-label="Minska batterimoduler">−</button>'
        . '<input type="text" inputmode="numeric" name="b3" data-sigen-qty value="' . e(jeab_offert_int((float) $qty)) . '">'
        . '<button type="button" data-js="sigen-step" data-delta="1" aria-label="Öka batterimoduler">+</button>'
        . '</span></label>';
    $note = 'Välj typ så läggs batteri och bottenplatta till.';
    if ($choice !== null) {
        $note = jeab_sigen_included_text($choice);
    }
    $schablonLine = '';
    if (function_exists('jeab_offert_calc') && function_exists('jeab_offert_schablon_kronor') && function_exists('jeab_offert_input_from_purchase')) {
        $batteryCalc = jeab_offert_calc(jeab_offert_input_from_purchase($open, 'batteri'));
        $schablon = jeab_offert_schablon_kronor($batteryCalc, 'batteri');
        $schablonLine = '<p class="v3-help" data-out="h29"><span>Schablon</span> <b>' . e(jeab_offert_money((float) $schablon)) . '</b></p>';
    }
    $h .= '<p class="v3-help" data-sigen-ingår>' . e($note) . '</p>'
        . $schablonLine
        . '</div></details>';
    return $h;
}

/** Kalkylen för sol i Bekräfta (samma fält och räkning som Kalkyl-sidan, jeab_offert_*). Pris följer Att betala tills det ändras. */
function jeab_ordrar_v3_kalkyl_html(array $open): string
{
    if (!function_exists('jeab_ordrar_kalkyl_html') || !function_exists('jeab_offert_calc')) {
        return '';
    }
    $calc = jeab_offert_calc(jeab_offert_kalkyl_input($open, 'sol'));
    $b14 = (int) round((float) ($calc['b14'] ?? 0));
    $b3 = (int) round((float) ($calc['b3'] ?? 0));
    $sok = max(1, (int) round((float) ($calc['sokande'] ?? 1)));
    $sum = $b14 . ' paneler · ' . $b3 . ' batterimoduler · ' . $sok . ' sökande'
        . (!empty($calc['b15']) ? ' · 12 kW TP2' : '')
        . (!empty($calc['laddare']) ? ' · elbilsladdare' : '')
        . ' · Att betala <span data-out="i14">' . e(jeab_offert_money((float) ($calc['i14'] ?? 0))) . '</span>';
    return '<details class="v3-sub-d v3-kalkyl" data-sub="kalkyl"><summary><span class="v3-chev">▶</span><span class="v3-t">Kalkyl</span><span class="v3-s" data-v3-kalk-sum>' . $sum . '</span></summary><div class="v3-subbody">'
        . jeab_ordrar_kalkyl_html($open)
        . '<p class="v3-help">Kalkylens rader styr Fortnox-raderna och ansökan om grön teknik. Pris följer Att betala tills du ändrar det själv.</p>'
        . '</div></details>';
}

/** Solkoll (bara sol): status och resultat. Beställ skapar/kopplar; knappen ordrar.solkoll gör samma sak igen. */
function jeab_ordrar_v3_solkoll_html(array $open, string $company, int $openId): string
{
    if (!function_exists('jeab_solkoll_applies') || !jeab_solkoll_applies($open)) {
        return '';
    }
    $id = trim((string) ($open['solkoll_facility_id'] ?? ''));
    $info = jeab_solkoll_status_for_row($open);
    $url = $id !== '' ? jeab_solkoll_facility_url($id) : '';
    $added = array_values(array_filter(array_map('strval', is_array($open['solkoll_added'] ?? null) ? $open['solkoll_added'] : [])));
    $unmatched = array_values(array_filter(is_array($open['solkoll_unmatched'] ?? null) ? $open['solkoll_unmatched'] : [], 'is_array'));
    $differs = array_values(array_filter(array_map('strval', is_array($open['solkoll_differs'] ?? null) ? $open['solkoll_differs'] : [])));
    $fixed = array_values(array_filter(array_map('strval', is_array($open['solkoll_fixed'] ?? null) ? $open['solkoll_fixed'] : [])));
    $dupes = (int) ($open['solkoll_duplicates'] ?? 0);
    $err = trim((string) ($open['solkoll_error'] ?? ''));

    if ($id === '') {
        $sum = 'Inte skapad';
        $h = '<p class="v3-help2 is-first">Skapar anläggningen i Solkoll, eller kopplar den som redan finns på adressen, och lägger till paneler, montage och växelriktare från orderraderna. Går att köra igen.</p>';
    } else {
        $sum = e($info['label'] !== '' ? $info['label'] : 'Kopplad');
        $h = '<p class="v3-help2 is-first">' . $sum
            . ($url !== '' ? ' · <a class="v3-lnk" href="' . e($url) . '" target="_blank" rel="noopener">Öppna i Solkoll ' . jeab_ordrar_v3_icon('ext') . '</a>' : '') . '</p>';
    }
    if ($unmatched !== []) {
        $sum .= ' · <span class="v3-warnmark">' . count($unmatched) . ' lades inte till</span>';
    }
    $res = '';
    if ($added !== []) {
        $res .= '<p><b>Tillagt:</b> ' . e(implode(' · ', $added)) . '</p>';
    }
    if ($fixed !== []) {
        $res .= '<p><b>Rättat efter ordern:</b> ' . e(implode(' ', $fixed)) . '</p>';
    }
    if ($unmatched !== []) {
        $res .= '<p class="v3-alert">Lades inte till i Solkoll:</p><ul>';
        foreach ($unmatched as $miss) {
            $title = trim((string) ($miss['title'] ?? ''));
            $res .= '<li>' . e(trim((string) ($miss['what'] ?? '')) . ($title !== '' ? ' «' . $title . '»' : '') . ': ' . trim((string) ($miss['reason'] ?? ''))) . '</li>';
        }
        $res .= '</ul>';
    }
    if ($differs !== []) {
        $res .= '<p><b>Skiljer sig (ändras inte automatiskt):</b> ' . e(implode(' ', $differs)) . '</p>';
    }
    if ($dupes > 0) {
        $res .= '<p class="v3-alert">Solkoll har ' . ($dupes + 1) . ' anläggningar på adressen. Kopplad till den med mest data.</p>';
    }
    if ($res !== '') {
        $h .= '<div class="v3-skres">' . $res . '</div>';
    }
    $label = $id !== '' ? 'Uppdatera Solkoll' : 'Skapa i Solkoll';
    $h .= '<form id="v3-f-solkoll" method="post" action="' . e(jeab_ordrar_url(['co' => $company, 'id' => $openId])) . '" data-v3-form="solkoll">'
        . jeab_csrf_field()
        . '<input type="hidden" name="action" value="ordrar.solkoll"><input type="hidden" name="id" value="' . $openId . '">'
        . '<div class="v3-rowbtns"><button type="submit" class="v3-btn is-sec" data-working="Kör Solkoll…">' . e($label) . '</button></div></form>'
        . '<div class="v3-err" data-v3-err="solkoll" role="alert"' . ($err !== '' ? '' : ' hidden') . '>' . ($err !== '' ? e(jeab_ordrar_save_fail_text($err)) : '') . '</div>';
    return '<details class="v3-sub-d" data-sub="solkoll"' . ($err !== '' || $unmatched !== [] ? ' open' : '') . '><summary><span class="v3-chev">▶</span><span class="v3-t">Solkoll</span><span class="v3-s">' . $sum . '</span></summary><div class="v3-subbody">' . $h . '</div></details>';
}

function jeab_ordrar_v3_activity_html(array $activity): string
{
    $html = '<section class="v3-card is-plain" id="steg-aktivitet" data-step="aktivitet"><div class="v3-chead"><span class="v3-ic is-tag">' . jeab_ordrar_v3_icon('list') . '</span><div class="v3-grow"><h2>Aktivitet</h2></div></div>';
    if ($activity === []) {
        return $html . '<p class="v3-help2">Inga händelser än.</p></section>';
    }
    $html .= '<ul class="v3-act">';
    foreach ($activity as $item) {
        $html .= '<li><span>' . e(jeab_ordrar_activity_when((string) ($item['at'] ?? ''))) . '</span><div>' . e((string) ($item['text'] ?? '')) . '</div></li>';
    }
    return $html . '</ul></section>';
}

/** Lägger till en rad i den gamla vyn när v3 är på men produkten inte har ny vy än. */
function jeab_ordrar_v3_fallback_note(int $openId, string $company): string
{
    $url = jeab_ordrar_url(['co' => $company, 'id' => $openId]);
    $url .= (str_contains($url, '?') ? '&' : '?') . 'v=2';
    return '<p class="ordrar-help ordrar-v3-note" role="status">Nya vyn finns inte för den här produkten än. Den visas i den vanliga vyn. <a href="' . e($url) . '">Stäng av nya vyn</a></p>';
}

/**
 * Stegen som räknas i «x av N»: Bekräfta, Föranmälan, Beställ, Fakturering
 * (4 för sol och batteri, 2 för elbilsladdare). Boka och Stäng räknas inte.
 */
function jeab_ordrar_v3_counted_ids(): array
{
    return ['bekrafta', 'foranmalan', 'bestall', 'faktura'];
}

/**
 * Ordens läge för listan OCH v3-huvudet – en källa: jeab_ordrar_v3_steps.
 *
 * @return array{steps:list<array>,all:list<array>,done:int,total:int,next:?array,current:?array,closed:bool,label:string}
 */
function jeab_ordrar_v3_progress(array $row, ?JeabStore $store = null, ?array $steps = null): array
{
    $steps ??= jeab_ordrar_v3_steps($row, $store);
    $ids = jeab_ordrar_v3_counted_ids();
    $counted = array_values(array_filter($steps, static fn(array $s): bool => in_array($s['id'], $ids, true)));
    $closed = jeab_ordrar_v3_step_state($steps, 'stang') === 'klar';
    $done = $closed
        ? count($counted)
        : count(array_filter($counted, static fn(array $s): bool => $s['state'] === 'klar'));
    $next = null;
    if (!$closed) {
        foreach ($steps as $s) {
            if ($s['state'] === 'todo') {
                $next = $s;
                break;
            }
        }
    }
    $current = null;
    if (!$closed) {
        foreach ($counted as $s) {
            if ($s['state'] !== 'klar') {
                $current = $s;
                break;
            }
        }
    }
    if ($closed) {
        $label = 'Klart';
    } elseif ($current !== null) {
        $label = (string) $current['title'];
    } else {
        $label = 'Stäng ärendet';
    }
    return [
        'steps' => $counted,
        'all' => $steps,
        'done' => $done,
        'total' => count($counted),
        'next' => $next,
        'current' => $current,
        'closed' => $closed,
        'label' => $label,
    ];
}

/**
 * Nästa åtgärd som verb (samma ord som knappen i v3). wait=true när ordern väntar
 * på kund, bot eller annan part – visas som grå text.
 *
 * @return array{text:string,wait:bool,key:string}
 */
function jeab_ordrar_v3_next_action(array $row, ?JeabStore $store = null, ?array $progress = null): array
{
    $progress ??= jeab_ordrar_v3_progress($row, $store);
    if ($progress['closed']) {
        return ['text' => '', 'wait' => false, 'key' => 'klart'];
    }
    $long = jeab_ordrar_v3_long($row);
    if ($long && jeab_ordrar_v3_step_state($progress['all'], 'foranmalan') === 'klar'
        && jeab_ordrar_foranmalan_case_status($row) === 'nekad') {
        return ['text' => 'Skicka föranmälan igen', 'wait' => false, 'key' => 'foranmalan'];
    }
    $next = $progress['next'];
    if ($next === null) {
        return ['text' => '', 'wait' => false, 'key' => ''];
    }
    $id = (string) $next['id'];
    switch ($id) {
        case 'bekrafta':
            if (jeab_ordrar_v3_bekraftad($row, $store)) {
                return ['text' => 'Skapa Fortnox-order', 'wait' => false, 'key' => $id];
            }
            if ($long && jeab_ordrar_needs_reply($row)) {
                return ['text' => 'Väntar på kunden', 'wait' => true, 'key' => $id];
            }
            if (jeab_ordrar_books_at_bestall($row)) {
                return ['text' => 'Skapa order', 'wait' => false, 'key' => $id];
            }
            return ['text' => 'Skapa order och boka', 'wait' => false, 'key' => $id];
        case 'foranmalan':
            if (function_exists('jeab_ordrar_foranmalan_send_failed') && jeab_ordrar_foranmalan_send_failed($row)) {
                return ['text' => 'Skicka igen', 'wait' => false, 'key' => $id];
            }
            return ['text' => 'Skicka föranmälan', 'wait' => false, 'key' => $id];
        case 'boka':
            return ['text' => 'Boka', 'wait' => false, 'key' => $id];
        case 'bestall':
            return ['text' => 'Beställ', 'wait' => false, 'key' => $id];
        case 'faktura':
            return ['text' => 'Skapa faktura', 'wait' => false, 'key' => $id];
        case 'stang':
            if ($long && !jeab_ordrar_fardiganmalan_klar($row)) {
                return ['text' => 'Väntar på nätägaren', 'wait' => true, 'key' => $id];
            }
            if (jeab_ordrar_ui_kind($row['kind'] ?? '') === 'sol' && !jeab_ordrar_solkoll_dokumentation_klar($row)) {
                return ['text' => 'Väntar på Solkoll', 'wait' => true, 'key' => $id];
            }
            return ['text' => 'Stäng ärendet', 'wait' => false, 'key' => $id];
    }
    return ['text' => '', 'wait' => false, 'key' => $id];
}

/**
 * Slutfas: alla räknade steg är klara och det enda som återstår är att vänta
 * på nätägaren, eller så är fakturering det enda räknade steg som inte är klart.
 * Stängda ärenden, «Väntar på Solkoll» och «Stäng ärendet» stannar utanför.
 */
function jeab_ordrar_is_slutfas(array $row, ?JeabStore $store = null): bool
{
    if (function_exists('jeab_ordrar_allt_klart') && jeab_ordrar_allt_klart($row)) {
        return false;
    }
    $progress = jeab_ordrar_v3_progress($row, $store);
    if (!empty($progress['closed'])) {
        return false;
    }
    $next = jeab_ordrar_v3_next_action($row, $store, $progress);
    $text = (string) ($next['text'] ?? '');
    if ($text === 'Väntar på nätägaren') {
        return (int) $progress['total'] > 0 && (int) $progress['done'] === (int) $progress['total'];
    }
    if ($text === 'Skapa faktura' && (string) ($next['key'] ?? '') === 'faktura') {
        $open = [];
        foreach ($progress['steps'] as $step) {
            if (!is_array($step) || ($step['state'] ?? '') === 'klar') {
                continue;
            }
            $open[] = (string) ($step['id'] ?? '');
        }
        return count($open) === 1 && $open[0] === 'faktura';
    }
    return false;
}

/** Datumet «4 nov» (år när det inte är i år). */
function jeab_ordrar_lista_date(string $raw): string
{
    $day = jeab_ordrar_day($raw);
    if (!$day instanceof DateTimeImmutable) {
        return '';
    }
    if (!isset($GLOBALS['_JEAB_ORDRAR_YEAR']) || !is_string($GLOBALS['_JEAB_ORDRAR_YEAR'])) {
        $GLOBALS['_JEAB_ORDRAR_YEAR'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm')))->format('Y');
    }
    $thisYear = $GLOBALS['_JEAB_ORDRAR_YEAR'];
    return jeab_ordrar_v3_date($day->format('Y-m-d'), $day->format('Y') !== $thisYear);
}

/**
 * Nyckel för cellcachen: id och updated_at, plus ett kort avtryck av raden
 * utan anteckning och råtext. Två versioner i samma anrop med samma stämpel
 * (tester, målad rad) får inte dela celler. Hela payload kodas inte.
 */
function jeab_ordrar_lista_cells_fp(array $row): string
{
    return jeab_ordrar_v3_row_stamp_hash($row);
}

/**
 * Listans celler, byggda på samma hjälpare som v3 (menu_sub, bestall_when,
 * shop_facts, foranmalan_short).
 *
 * @return array<string,mixed>
 */
function jeab_ordrar_lista_cells(array $row, ?JeabStore $store = null): array
{
    $cellId = (int) ($row['reminder_id'] ?? 0);
    $cellKey = $cellId . '|' . trim((string) ($row['updated_at'] ?? '')) . '|' . jeab_ordrar_lista_cells_fp($row);
    if ($cellId > 0 && isset($GLOBALS['_JEAB_LISTA_CELLS'][$cellKey]) && is_array($GLOBALS['_JEAB_LISTA_CELLS'][$cellKey])) {
        return $GLOBALS['_JEAB_LISTA_CELLS'][$cellKey];
    }
    $progress = jeab_ordrar_v3_progress($row, $store);
    $next = jeab_ordrar_v3_next_action($row, $store, $progress);
    $long = jeab_ordrar_v3_long($row);
    $all = $progress['all'];

    // Föranmälan: datum + läge som i v3-menyn, annars —.
    $foran = ['text' => '', 'sub' => '', 'tone' => ''];
    if ($long) {
        foreach ($all as $s) {
            if ($s['id'] === 'foranmalan' && $s['state'] === 'klar') {
                $date = jeab_ordrar_v3_step_raw($row, 'foranmald');
                if ($date === '') {
                    $date = trim((string) ($row['fullmakt_start_at'] ?? ''));
                }
                if ($date === '') {
                    $date = trim((string) ($row['seom_sam_sent_at'] ?? ''));
                }
                if ($date === '') {
                    $date = jeab_ordrar_v3_step_raw($row, 'spec');
                }
                $foran['text'] = $date !== '' ? jeab_ordrar_lista_date($date) : '✓';
                $foran['sub'] = jeab_ordrar_v3_foranmalan_short($row);
                $foran['tone'] = match (jeab_ordrar_foranmalan_case_status($row)) {
                    'skickad' => 'info',
                    'nekad' => 'danger',
                    'godkand', 'fardiganmald' => 'ok',
                    default => '',
                };
            }
        }
    }

    // Installation: bokad tid. Försenad = passerad och ännu inte fakturerad.
    $installRaw = jeab_ordrar_install_start($row, $store);
    $installDay = jeab_ordrar_day($installRaw);
    $today = jeab_ordrar_day(jeab_ordrar_today());
    $late = $installDay instanceof DateTimeImmutable && $today instanceof DateTimeImmutable
        && $installDay < $today && !$progress['closed']
        && jeab_ordrar_v3_step_state($all, 'faktura') !== 'klar';
    $install = $installDay instanceof DateTimeImmutable ? jeab_ordrar_lista_date($installRaw) : '';

    // Beställd: orderdatum (inte leverans) + ordernummer från Bokmalen.
    $bestalld = ['text' => '', 'sub' => '', 'wait' => false];
    if ($long && jeab_ordrar_v3_step_state($all, 'bestall') === 'klar') {
        $when = jeab_ordrar_v3_bestall_when($row);
        $bestalld['text'] = $when !== '' && jeab_ordrar_lista_date($when) !== '' ? jeab_ordrar_lista_date($when) : '✓';
        $nos = jeab_ordrar_v3_shop_numbers($row);
        if ($nos !== []) {
            $bestalld['sub'] = implode(' · ', $nos);
        } elseif ((jeab_ordrar_bestallning_lage($row, $store)['key'] ?? '') === 'bokmalen') {
            $bestalld['sub'] = 'Väntar på Bokmalen';
            $bestalld['wait'] = true;
        } else {
            $bestalld['sub'] = jeab_ordrar_shop_label($row);
        }
    }

    // Leverans: faktisk (Bokmalen/Aprilice), annars önskad.
    $leverans = ['text' => '', 'sub' => ''];
    if ($long) {
        $actual = jeab_ordrar_actual_delivery($row);
        if ($actual !== '') {
            $leverans['text'] = jeab_ordrar_lista_date($actual);
            $leverans['sub'] = (string) ($row['leverans_source'] ?? '') === 'shop' ? 'Bekräftad av butik' : '';
            $wantedLimit = $installDay instanceof DateTimeImmutable
                ? jeab_ordrar_wanted_delivery_ymd($installDay->format('Y-m-d'), (string) ($row['kind'] ?? ''))
                : '';
            $limit = jeab_ordrar_day($wantedLimit);
            $actDay = jeab_ordrar_day($actual);
            if ($limit instanceof DateTimeImmutable && $actDay instanceof DateTimeImmutable && $actDay > $limit && !$progress['closed']) {
                $leverans['sub'] = 'För sent: senast ' . jeab_ordrar_lista_date($limit->format('Y-m-d'));
                $leverans['late'] = true;
            }
        } else {
            $wanted = jeab_ordrar_onskad_leverans($row, $store);
            if ($wanted !== '' && !$progress['closed'] && jeab_ordrar_v3_step_state($all, 'faktura') !== 'klar') {
                $leverans['sub'] = 'Önskad ' . jeab_ordrar_lista_date($wanted);
            }
        }
    }

    $closedSub = '';
    if ($progress['closed']) {
        foreach ($all as $s) {
            if ($s['id'] === 'faktura' && $s['state'] === 'klar') {
                $closedSub = jeab_ordrar_v3_menu_sub($row, $s, $store);
            }
        }
    }

    $out = [
        'progress' => $progress,
        'next' => $next,
        'foran' => $foran,
        'install' => $install,
        'install_raw' => $installDay instanceof DateTimeImmutable ? $installDay->format('Y-m-d') : '',
        'late' => $late,
        'bestalld' => $bestalld,
        'leverans' => $leverans,
        'closed_sub' => $closedSub,
    ];
    if ($cellId > 0) {
        if (!isset($GLOBALS['_JEAB_LISTA_CELLS']) || !is_array($GLOBALS['_JEAB_LISTA_CELLS'])) {
            $GLOBALS['_JEAB_LISTA_CELLS'] = [];
        }
        $GLOBALS['_JEAB_LISTA_CELLS'][$cellKey] = $out;
    }
    return $out;
}

/** Tunn stegbar: ett segment per räknat steg. */
function jeab_ordrar_lista_bar_html(array $progress): string
{
    $html = '<span class="ordrar-bar" aria-hidden="true">';
    foreach ($progress['steps'] as $s) {
        $on = $progress['closed'] || $s['state'] === 'klar';
        $html .= '<i' . ($on ? ' class="is-d"' : '') . '></i>';
    }
    return $html . '</span>';
}

/**
 * Brådskande först: närmaste installation (passerade först), sedan utan datum
 * (nyast först).
 */
function jeab_ordrar_lista_sort(array $rows, ?JeabStore $store = null): array
{
    $keyed = [];
    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $day = jeab_ordrar_day(jeab_ordrar_install_start($row, $store));
        $keyed[] = [
            'row' => $row,
            'day' => $day instanceof DateTimeImmutable ? $day->format('Y-m-d') : '',
            'created' => (string) ($row['created_at'] ?? ''),
            'id' => (int) ($row['reminder_id'] ?? 0),
            'i' => $i,
        ];
    }
    usort($keyed, static function (array $a, array $b): int {
        if (($a['day'] === '') !== ($b['day'] === '')) {
            return $a['day'] === '' ? 1 : -1;
        }
        if ($a['day'] !== $b['day']) {
            return strcmp($a['day'], $b['day']);
        }
        $c = strcmp($b['created'], $a['created']);
        if ($c !== 0) {
            return $c;
        }
        $id = $a['id'] <=> $b['id'];
        return $id !== 0 ? $id : $a['i'] <=> $b['i'];
    });
    return array_map(static fn(array $k): array => $k['row'], $keyed);
}
