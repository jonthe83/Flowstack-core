<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Skrivbordets högerpanel. Anropas bara med intent=side, inte när listan ritas.
 * Läser kontakt, bokning och en rad i inkorg_list. Rör inte Inkorg-koden.
 */

function jeab_pam_desk_pin(array $row): string
{
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_reminder_shown_customer')) {
        return '';
    }
    $view = jeab_reminder_shown_customer($row);
    $href = function_exists('jeab_reminders_url') ? jeab_reminders_url(['open' => $id]) : '#';
    $phone = trim((string) ($view['phone'] ?? ''));
    $call = function_exists('jeab_reminder_call_link') ? jeab_reminder_call_link($phone, !empty($row['completed_at'])) : '';
    $face = function_exists('jeab_reminder_desk_face_html') ? jeab_reminder_desk_face_html($row) : '';

    return '<li class="pam-desk-pin is-selected" data-desk-pin="' . $id . '">'
        . '<span class="pam-desk-pin-mark" aria-hidden="true"></span>'
        . '<a class="pam-desk-pin-main" href="' . e($href) . '">'
        . $face
        . '</a>'
        . $call
        . '</li>';
}

function jeab_pam_side_offer(PDO $pdo, int $id): string
{
    try {
        $stmt = $pdo->prepare('SELECT payload FROM purchases WHERE reminder_id = ?');
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable) {
        return 'Okänd';
    }
    if (!is_string($raw) || $raw === '') {
        return 'Ingen offert i ordern';
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return 'Order finns';
    }
    $labels = [
        'kund_bestallt' => 'Kund beställt',
        'fatt_alla_uppgifter' => 'Bekräftad',
        'foranmald' => 'Föranmäld',
        'bokad_tid' => 'Bokad tid',
        'bestalld' => 'Beställd',
        'order_skriven' => 'Order skriven',
        'faktura_skapad' => 'Faktura skapad',
    ];
    $steps = is_array($data['steps'] ?? null) ? $data['steps'] : [];
    $last = '';
    foreach ($labels as $key => $label) {
        if (trim((string) ($steps[$key] ?? '')) !== '') {
            $last = $label;
        }
    }
    $kind = trim((string) ($data['kind'] ?? ''));
    if ($last !== '' && $kind !== '') {
        return $last . ' · ' . $kind;
    }
    if ($last !== '') {
        return $last;
    }

    return $kind !== '' ? 'Order (' . $kind . ')' : 'Order finns';
}

/** @return array{status:string,subject:string,preview:string} */
function jeab_pam_side_case(PDO $pdo, string $tid): array
{
    $empty = ['status' => '', 'subject' => '', 'preview' => ''];
    if ($tid === '' || preg_match('/^[A-Za-z0-9_%.\-]+$/', $tid) !== 1) {
        return $empty;
    }
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $sql = 'SELECT list_status, json_extract(row_json, \'$.subject\') AS subject, json_extract(row_json, \'$.preview\') AS preview FROM inkorg_list WHERE id = ? LIMIT 1';
    } else {
        $sql = 'SELECT list_status, JSON_UNQUOTE(JSON_EXTRACT(row_json, \'$.subject\')) AS subject, JSON_UNQUOTE(JSON_EXTRACT(row_json, \'$.preview\')) AS preview FROM inkorg_list WHERE id = ? LIMIT 1';
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return $empty;
    }
    if (!is_array($row)) {
        return $empty;
    }
    $preview = trim((string) ($row['preview'] ?? ''));
    if (function_exists('mb_substr')) {
        $preview = mb_substr($preview, 0, 180);
    } else {
        $preview = substr($preview, 0, 180);
    }

    return [
        'status' => trim((string) ($row['list_status'] ?? '')),
        'subject' => trim((string) ($row['subject'] ?? '')),
        'preview' => $preview,
    ];
}

/** @return list<array{when:string,what:string,who:string,status:string}> */
function jeab_pam_side_meetings(PDO $pdo, array $row, string $contactId): array
{
    $out = [];
    $seen = [];
    $push = static function (?array $booking) use (&$out, &$seen): void {
        if (!is_array($booking)) {
            return;
        }
        $id = (int) ($booking['id'] ?? 0);
        if ($id <= 0 || isset($seen[$id])) {
            return;
        }
        $status = strtolower(trim((string) ($booking['status'] ?? '')));
        if (in_array($status, ['cancelled', 'canceled', 'avbokad'], true)) {
            return;
        }
        $seen[$id] = true;
        $when = trim((string) ($booking['starts_at'] ?? ''));
        $what = trim((string) ($booking['job_type'] ?? ''));
        if ($what === '') {
            $what = trim((string) ($booking['address'] ?? ''));
        }
        $out[] = [
            'when' => $when,
            'what' => $what,
            'who' => trim((string) ($booking['assignee'] ?? '')),
            'status' => $status === '' ? 'bokad' : $status,
        ];
    };
    $bid = (int) ($row['booking_id'] ?? 0);
    if ($bid > 0 && function_exists('jeab_store')) {
        try {
            $store = jeab_store();
            if (is_object($store) && method_exists($store, 'getBooking')) {
                $push($store->getBooking($bid));
            }
        } catch (Throwable) {
        }
    }
    if ($contactId !== '') {
        try {
            $stmt = $pdo->prepare('SELECT id, starts_at, ends_at, status, job_type, assignee, ' . jeab_copy_select($pdo, 'bookings', ['address']) . ' FROM bookings WHERE contact_id = ? ORDER BY starts_at DESC LIMIT 5');
            $stmt->execute([$contactId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($rows)) {
                foreach ($rows as $booking) {
                    $push(is_array($booking) ? $booking : null);
                }
            }
        } catch (Throwable) {
        }
    }

    return $out;
}

function jeab_pam_side_payload(JeabStore $store): array
{
    $id = function_exists('jeab_reminder_open_id') ? jeab_reminder_open_id() : (int) ($_GET['open'] ?? 0);
    if (function_exists('jeab_release_session') && session_status() === PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
        jeab_release_session();
    }
    if ($id <= 0) {
        return ['ok' => true, 'id' => 0, 'html' => '<p class="pam-side-empty">Öppna en påminnelse.</p>'];
    }
    $row = function_exists('jeab_get_reminder') ? jeab_get_reminder($store, $id) : null;
    if (!is_array($row) || (function_exists('jeab_reminder_is_trashed') && jeab_reminder_is_trashed($row))) {
        return ['ok' => false, 'id' => $id, 'html' => ''];
    }
    if (function_exists('jeab_reminder_preload_contacts')) {
        jeab_reminder_preload_contacts([$row]);
    }
    $view = function_exists('jeab_reminder_shown_customer') ? jeab_reminder_shown_customer($row) : [];
    $contactId = trim((string) ($row['contact_id'] ?? ''));
    $contact = null;
    if ($contactId !== '' && function_exists('jeab_kontakt_get')) {
        try {
            $contact = jeab_kontakt_get($contactId);
        } catch (Throwable) {
            $contact = null;
        }
    }
    $name = is_array($contact) && function_exists('jeab_kontakt_row_name') ? jeab_kontakt_row_name($contact) : '';
    $email = is_array($contact) && function_exists('jeab_kontakt_row_email') ? jeab_kontakt_row_email($contact) : '';
    $phone = is_array($contact) && function_exists('jeab_kontakt_row_phone') ? jeab_kontakt_row_phone($contact) : '';
    $street = is_array($contact) ? trim((string) ($contact['street'] ?? '')) : '';
    $postal = is_array($contact) && function_exists('jeab_kontakt_postal')
        ? jeab_kontakt_postal((string) ($contact['postal_code'] ?? ''))
        : trim((string) (is_array($contact) ? ($contact['postal_code'] ?? '') : ''));
    $city = is_array($contact) ? trim((string) ($contact['city'] ?? '')) : '';
    if ($name === '') {
        $name = trim((string) ($view['name'] ?? ''));
    }
    if ($email === '') {
        $email = trim((string) ($view['email'] ?? ''));
    }
    if ($phone === '') {
        $phone = trim((string) ($view['phone'] ?? ''));
    }
    if ($street === '') {
        $street = trim((string) ($view['street'] ?? ''));
    }
    if ($city === '') {
        $city = trim((string) ($view['city'] ?? ''));
    }
    if ($postal === '') {
        $postal = trim((string) ($view['postal'] ?? ''));
    }
    $address = trim($street . ($postal !== '' || $city !== '' ? ', ' . trim($postal . ' ' . $city) : ''));
    $created = function_exists('jeab_format_created_day') ? jeab_format_created_day($row['created_at'] ?? null) : '';
    $book = function_exists('jeab_reminder_calendar_btn_html') ? jeab_reminder_calendar_btn_html($row) : '';
    $pdo = $store->pdo();
    $offer = jeab_pam_side_offer($pdo, $id);
    $tid = trim((string) ($row['thread_id'] ?? ''));
    $case = jeab_pam_side_case($pdo, $tid);
    $meetings = jeab_pam_side_meetings($pdo, $row, $contactId);
    $caseHref = $tid !== '' && function_exists('jeab_url')
        ? jeab_url('index.php?p=inkorg&id=' . rawurlencode($tid))
        : '';

    $listAction = function_exists('jeab_reminders_url') ? jeab_reminders_url(['open' => $id]) : '';
    $html = '<div class="pam-side-tools">';
    $html .= $book;
    if (function_exists('jeab_reminder_tags_html')) {
        $html .= jeab_reminder_tags_html($row, $listAction, false, [], 'side-');
    }
    if (function_exists('jeab_reminder_who_html')) {
        $html .= jeab_reminder_who_html($row, $listAction);
    }
    $html .= '</div>';
    $html .= '<section class="pam-side-block"><h2>Mer</h2>';
    $html .= '<p class="pam-side-line"><span>Skapad</span> ' . e($created !== '' ? $created : '—') . '</p>';
    $html .= '</section>';

    $html .= '<section class="pam-side-block"><h2>Kontakt</h2>';
    if ($contactId === '') {
        $html .= '<p class="pam-side-muted">Ingen kopplad kontakt.</p>';
    }
    $html .= '<p class="pam-side-name">' . e($name !== '' ? $name : '—') . '</p>';
    if ($phone !== '') {
        $href = function_exists('jeab_tel_href') ? jeab_tel_href($phone) : 'tel:' . rawurlencode($phone);
        $html .= '<p class="pam-side-line"><a href="' . e($href) . '">' . e($phone) . '</a></p>';
    }
    if ($email !== '') {
        $html .= '<p class="pam-side-line"><a href="mailto:' . e($email) . '">' . e($email) . '</a></p>';
    }
    if ($address !== '') {
        $html .= '<p class="pam-side-line">' . e($address) . '</p>';
    }
    $html .= '</section>';

    $html .= '<section class="pam-side-block"><h2>Ärende</h2>';
    if ($caseHref === '') {
        $html .= '<p class="pam-side-muted">Inget kopplat ärende.</p>';
    } else {
        if ($case['subject'] !== '') {
            $html .= '<p class="pam-side-name">' . e($case['subject']) . '</p>';
        }
        if ($case['preview'] !== '') {
            $html .= '<p class="pam-side-preview">' . e($case['preview']) . '</p>';
        }
        if ($case['status'] !== '') {
            $html .= '<p class="pam-side-muted">' . e($case['status']) . '</p>';
        }
        $html .= '<p class="pam-side-line"><a href="' . e($caseHref) . '">Öppna ärendet</a></p>';
    }
    $html .= '</section>';

    $html .= '<section class="pam-side-block"><h2>Bokade möten</h2>';
    if ($meetings === []) {
        $html .= '<p class="pam-side-muted">Inga bokade möten.</p>';
    } else {
        $html .= '<ul class="pam-side-meets">';
        foreach ($meetings as $meet) {
            $html .= '<li><b>' . e($meet['when'] !== '' ? $meet['when'] : 'Tid saknas') . '</b>';
            if ($meet['what'] !== '') {
                $html .= ' ' . e($meet['what']);
            }
            if ($meet['who'] !== '') {
                $html .= ' <span>' . e($meet['who']) . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
    }
    $html .= '</section>';

    $html .= '<section class="pam-side-block"><h2>Offert-status</h2><p class="pam-side-name">' . e($offer) . '</p></section>';

    return ['ok' => true, 'id' => $id, 'html' => $html];
}
