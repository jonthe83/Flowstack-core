<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Moduler där kontakten förekommer. Läser bara contact_id och
 * datum/status. Namn, e-post, telefon, adress och personnummer kopieras inte.
 */

/** @return array<string, string> */
function jeab_kontakt_module_labels(): array
{
    return [
        'inkorg' => 'Inkorg',
        'paminnelse' => 'Påminnelse',
        'kalender' => 'Kalender',
        'order' => 'Order',
        'fullmakt' => 'Fullmakt',
    ];
}

/** @return array<string, list<array{id:string,date:string,status:string,href:string,label:string}>> */
function jeab_kontakt_module_groups_empty(): array
{
    $out = [];
    foreach (jeab_kontakt_module_labels() as $key => $label) {
        unset($label);
        $out[$key] = [];
    }

    return $out;
}

function jeab_kontakt_module_date(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/', $raw, $m) === 1) {
        return $m[1] . ' ' . $m[2];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw, $m) === 1) {
        return $m[0];
    }

    return '';
}

function jeab_kontakt_module_url(string $page, array $query): string
{
    $query = ['p' => $page] + $query;
    $built = 'index.php?' . http_build_query($query);

    return function_exists('jeab_url') ? jeab_url($built) : $built;
}

function jeab_kontakt_module_label(string $raw): string
{
    $raw = trim($raw, "\" \t\n\r");
    if (strlen($raw) > 180) {
        $raw = substr($raw, 0, 180);
    }

    return $raw;
}

/**
 * @return array{id:string,date:string,status:string,href:string,label:string}
 */
function jeab_kontakt_module_row(string $id, string $date, string $status, string $href, string $label = ''): array
{
    return [
        'id' => $id,
        'date' => jeab_kontakt_module_date($date),
        'status' => $status,
        'href' => $href,
        'label' => jeab_kontakt_module_label($label),
    ];
}

function jeab_kontakt_module_inkorg_status(string $status): string
{
    return match (strtolower(trim($status))) {
        '', 'open' => 'Öppen',
        'closed' => 'Stängd',
        'deleted' => 'Raderad',
        'waiting_customer' => 'Väntar på kund',
        'waiting_supplier' => 'Väntar på leverantör',
        default => 'Öppen',
    };
}

function jeab_kontakt_module_booking_status(string $status): string
{
    return match (strtolower(trim($status))) {
        'cancelled' => 'Avbokad',
        'done', 'completed' => 'Klar',
        default => 'Bokad',
    };
}

function jeab_kontakt_module_fullmakt_status(string $signed, string $sentAt, string $seom): string
{
    $seom = strtolower(trim($seom));
    $signedFlag = $signed === '1' || strtolower($signed) === 'true' || $seom === 'kund_signerad';
    if ($signedFlag) {
        return 'Signerad';
    }
    if ($seom === 'kund_skickad') {
        return 'Skickad';
    }
    if ($seom === 'sam_skickad') {
        return 'Skickad till Sam';
    }
    if (trim($sentAt) !== '') {
        return 'Skickad';
    }

    return 'Ej påbörjad';
}

function jeab_kontakt_module_fullmakt_href(string $token, string $status, string $orderId): string
{
    if ($status === 'Signerad' && $token !== '') {
        $vis = substr(hash('sha256', 'jeab-signed-v1:' . $token), 0, 24);

        return jeab_kontakt_module_url('fullmakter', ['vis' => $vis]);
    }
    if ($orderId !== '') {
        return jeab_kontakt_module_url('ordrar', ['id' => $orderId]);
    }

    return jeab_kontakt_module_url('fullmakter', []);
}

/** @return list<array<string, mixed>>|null */
function jeab_kontakt_module_query(PDO $pdo, string $sql, array $args): ?array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable) {
        return null;
    }
}

/**
 * @return array<string, list<array{id:string,date:string,status:string,href:string,label:string}>>
 */
function jeab_kontakt_modules(PDO $pdo, string $contactId): array
{
    $out = jeab_kontakt_module_groups_empty();
    $contactId = trim($contactId);
    if ($contactId === '') {
        return $out;
    }
    $mysql = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $json = static function (string $expr, string $path) use ($mysql): string {
        return $mysql
            ? "JSON_UNQUOTE(JSON_EXTRACT(" . $expr . ", '" . $path . "'))"
            : "json_extract(" . $expr . ", '" . $path . "')";
    };
    $inkorgSql = 'SELECT id, list_at, list_status, '
        . $json('row_json', '$.subject') . ' AS subject, '
        . $json('row_json', '$.first.subject') . ' AS first_subject '
        . 'FROM inkorg_list WHERE contact_id = ? ORDER BY list_at DESC, id DESC';
    $inkorgRows = jeab_kontakt_module_query($pdo, $inkorgSql, [$contactId]);
    if ($inkorgRows === null) {
        $inkorgRows = jeab_kontakt_module_query(
            $pdo,
            'SELECT id, list_at, list_status FROM inkorg_list WHERE contact_id = ? ORDER BY list_at DESC, id DESC',
            [$contactId]
        ) ?? [];
    }
    foreach ($inkorgRows as $row) {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $status = jeab_kontakt_module_inkorg_status((string) ($row['list_status'] ?? ''));
        $label = jeab_kontakt_module_label((string) ($row['subject'] ?? ''));
        if ($label === '') {
            $label = jeab_kontakt_module_label((string) ($row['first_subject'] ?? ''));
        }
        $out['inkorg'][] = jeab_kontakt_module_row(
            $id,
            (string) ($row['list_at'] ?? ''),
            $status,
            jeab_kontakt_module_url('inkorg', ['id' => $id]),
            $label
        );
    }
    $reminderRows = jeab_kontakt_module_query(
        $pdo,
        'SELECT id, due_at, completed_at, title FROM reminders WHERE contact_id = ? ORDER BY due_at DESC, id DESC',
        [$contactId]
    );
    if ($reminderRows === null) {
        $reminderRows = jeab_kontakt_module_query(
            $pdo,
            'SELECT id, due_at, completed_at FROM reminders WHERE contact_id = ? ORDER BY due_at DESC, id DESC',
            [$contactId]
        ) ?? [];
    }
    foreach ($reminderRows as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id === '' || $id === '0') {
            continue;
        }
        $done = trim((string) ($row['completed_at'] ?? '')) !== '';
        $out['paminnelse'][] = jeab_kontakt_module_row(
            $id,
            (string) ($row['due_at'] ?? ''),
            $done ? 'Klar' : 'Öppen',
            jeab_kontakt_module_url('reminders', ['open' => $id]),
            (string) ($row['title'] ?? '')
        );
    }
    $bookingRows = jeab_kontakt_module_query(
        $pdo,
        'SELECT id, starts_at, status FROM bookings WHERE contact_id = ? ORDER BY starts_at DESC, id DESC',
        [$contactId]
    ) ?? [];
    foreach ($bookingRows as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id === '' || $id === '0') {
            continue;
        }
        $out['kalender'][] = jeab_kontakt_module_row(
            $id,
            (string) ($row['starts_at'] ?? ''),
            jeab_kontakt_module_booking_status((string) ($row['status'] ?? '')),
            jeab_kontakt_module_url('bookings', ['open' => $id])
        );
    }
    $orderRows = jeab_kontakt_module_query(
        $pdo,
        'SELECT reminder_id, created_at, updated_at, klart FROM purchases WHERE contact_id = ? ORDER BY created_at DESC, reminder_id DESC',
        [$contactId]
    ) ?? [];
    foreach ($orderRows as $row) {
        $id = (string) ($row['reminder_id'] ?? '');
        if ($id === '' || $id === '0') {
            continue;
        }
        $when = trim((string) ($row['created_at'] ?? ''));
        if ($when === '') {
            $when = (string) ($row['updated_at'] ?? '');
        }
        $out['order'][] = jeab_kontakt_module_row(
            $id,
            $when,
            ((int) ($row['klart'] ?? 0)) === 1 ? 'Klar' : 'Öppen',
            jeab_kontakt_module_url('ordrar', ['id' => $id])
        );
    }
    $token = $json('payload', '$.fullmakt_token');
    $signed = $json('payload', '$.fullmakt_signed');
    $sent = $json('payload', '$.fullmakt_sent_at');
    $seom = $json('payload', '$.seom_fullmakt_status');
    $fullmaktSql = 'SELECT reminder_id, created_at, updated_at, '
        . $token . ' AS token, ' . $signed . ' AS signed, ' . $sent . ' AS sent_at, ' . $seom . ' AS seom '
        . 'FROM purchases WHERE contact_id = ? AND ('
        . "COALESCE(" . $token . ", '') <> '' OR COALESCE(" . $seom . ", '') <> '' OR COALESCE(" . $signed . ", '') NOT IN ('', '0', 'false')"
        . ') ORDER BY created_at DESC, reminder_id DESC';
    foreach (jeab_kontakt_module_query($pdo, $fullmaktSql, [$contactId]) ?? [] as $row) {
        $id = (string) ($row['reminder_id'] ?? '');
        $tokenValue = trim((string) ($row['token'] ?? ''), "\" \t\n\r");
        $sentAt = trim((string) ($row['sent_at'] ?? ''), "\" \t\n\r");
        $status = jeab_kontakt_module_fullmakt_status(
            trim((string) ($row['signed'] ?? ''), "\" \t\n\r"),
            $sentAt,
            trim((string) ($row['seom'] ?? ''), "\" \t\n\r")
        );
        $when = $sentAt !== '' ? $sentAt : (string) ($row['created_at'] ?? $row['updated_at'] ?? '');
        $out['fullmakt'][] = jeab_kontakt_module_row(
            $id !== '' ? $id : $tokenValue,
            $when,
            $status,
            jeab_kontakt_module_fullmakt_href($tokenValue, $status, $id)
        );
    }

    return $out;
}

/** @param array<string, list<array{id:string,date:string,status:string,href:string,label:string}>> $groups */
function jeab_kontakt_modules_html(array $groups): string
{
    $html = '<section class="kt-modules" aria-label="Moduler">';
    foreach (jeab_kontakt_module_labels() as $key => $label) {
        $rows = is_array($groups[$key] ?? null) ? $groups[$key] : [];
        $html .= '<article class="kt-module"><h3 class="crm-section-title">' . e($label) . '</h3>';
        if ($rows === []) {
            $html .= '<p class="muted kt-module-empty">Inga</p>';
        } else {
            $html .= '<ul class="kt-module-rows">';
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $date = trim((string) ($row['date'] ?? ''));
                $status = trim((string) ($row['status'] ?? ''));
                $itemLabel = trim((string) ($row['label'] ?? ''));
                $html .= '<li><a class="kt-module-row" href="' . e((string) ($row['href'] ?? '')) . '">';
                if ($itemLabel !== '') {
                    $html .= '<span class="kt-module-label">' . e($itemLabel) . '</span>';
                }
                $html .= '<span class="kt-module-date">' . e($date !== '' ? $date : '–') . '</span>'
                    . '<span class="kt-module-status">' . e($status !== '' ? $status : '–') . '</span>'
                    . '</a></li>';
            }
            $html .= '</ul>';
        }
        $html .= '</article>';
    }
    $html .= '</section>';

    return $html;
}
