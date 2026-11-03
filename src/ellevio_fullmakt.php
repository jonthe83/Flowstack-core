<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/snapshots.php';

function jeab_ellevio_fullmakt_applies(array $row): bool
{
    $saved = trim((string) ($row['natbolag'] ?? ''));
    if ($saved !== '') {
        return $saved === 'Ellevio';
    }
    if (!function_exists('jeab_ordrar_natbolag')) {
        return false;
    }
    return jeab_ordrar_natbolag($row) === 'Ellevio';
}

function jeab_ellevio_split_address(string $address): array
{
    $address = trim((string) (preg_replace('/\s+/u', ' ', $address) ?? $address));
    if (preg_match('/^(.*?)\s*,?\s*(\d{3}\s?\d{2})\s+(\S.*)$/u', $address, $m) === 1) {
        return [
            'adress' => trim($m[1], " ,"),
            'postnummer' => trim($m[2]),
            'ort' => trim($m[3]),
        ];
    }
    return ['adress' => $address, 'postnummer' => '', 'ort' => ''];
}

function jeab_ellevio_fullmakt_root(): string
{
    return JEAB_ROOT . '/fullmakt';
}

function jeab_ellevio_fullmakt_load(): bool
{
    $root = jeab_ellevio_fullmakt_root();
    $bootstrap = $root . '/lib/bootstrap.php';
    $store = $root . '/lib/store.php';
    $mailer = $root . '/lib/mailer.php';
    if (!is_file($bootstrap) || !is_file($store) || !is_file($mailer)) {
        return false;
    }
    // bootstrap.php owns FULLMAKT_* constants + fullmakt_copy() used by mailer.
    if (!defined('FULLMAKT_ROOT')) {
        require_once $bootstrap;
    }
    require_once __DIR__ . '/ellevio_fullmakt_shims.php';
    if (!function_exists('fullmakt_copy')) {
        return false;
    }
    if (!function_exists('fullmakt_boot')) {
        return false;
    }
    fullmakt_boot();
    if (!is_dir(FULLMAKT_PENDING) || !is_dir(FULLMAKT_SIGNED)) {
        return false;
    }
    require_once $store;
    require_once $mailer;
    return function_exists('fullmakt_create_doc') && function_exists('fullmakt_send_invite');
}

function jeab_ellevio_fullmakt_url(string $token): string
{
    return 'https://flowstack.example.test/fullmakt/s/' . rawurlencode($token);
}

function jeab_ellevio_fullmakt_state(array $row): array
{
    $applies = jeab_ellevio_fullmakt_applies($row);
    $token = trim((string) ($row['fullmakt_token'] ?? ''));
    $signed = !empty($row['fullmakt_signed']);
    $pending = false;
    $viewed = false;
    if ($applies && $token !== '' && jeab_ellevio_fullmakt_load()) {
        $doc = fullmakt_load_doc($token);
        if (is_array($doc)) {
            $signed = $signed || ($doc['status'] ?? '') === 'signed';
            $pending = !$signed;
            $viewed = trim((string) ($doc['viewed_at'] ?? '')) !== '';
        }
    }
    $mailed = jeab_ellevio_fullmakt_was_mailed($row)
        || trim((string) ($row['fullmakt_sent_at'] ?? '')) !== '';
    $handedOff = trim((string) ($row['fullmakt_start_at'] ?? '')) !== '';
    $sent = $mailed || $handedOff;
    return [
        'applies' => $applies,
        'token' => $token,
        'sent' => $sent,
        'handed_off' => $handedOff && !$mailed,
        'viewed' => $viewed,
        'signed' => $signed,
        'pending' => $pending,
        'url' => trim((string) ($row['fullmakt_url'] ?? '')),
        'error' => trim((string) ($row['fullmakt_error'] ?? '')),
    ];
}

function jeab_ellevio_fullmakt_status_from_state(array $state): string
{
    if (empty($state['applies'])) {
        return 'behovs_ej';
    }
    if (!empty($state['signed'])) {
        return 'signerad';
    }
    // Öppnad länk betyder att utskicket redan gått ut. Kortet säger Skickad tills signering.
    if (!empty($state['viewed']) || !empty($state['sent'])) {
        return 'skickad';
    }
    return 'ej_paborjad';
}

function jeab_ellevio_fullmakt_status(array $row): string
{
    return jeab_ellevio_fullmakt_status_from_state(jeab_ellevio_fullmakt_state($row));
}

function jeab_ellevio_fullmakt_status_label(string $status): string
{
    return match ($status) {
        'behovs_ej' => 'Behövs ej',
        'skickad', 'tittat' => 'Skickad',
        'signerad' => 'Signerad',
        default => 'Ej påbörjad/manuell',
    };
}

function jeab_ellevio_fullmakt_status_html(array $row): string
{
    $state = jeab_ellevio_fullmakt_state($row);
    $status = jeab_ellevio_fullmakt_status_from_state($state);
    $tone = match ($status) {
        'signerad' => 'ok',
        'tittat', 'skickad', 'ej_paborjad', 'vantar' => 'warn',
        default => 'muted',
    };
    $html = '<p class="ordrar-fullmakt">Fullmakt <span class="ordrar-status-pill is-on is-' . e($tone) . '">'
        . e(jeab_ellevio_fullmakt_status_label($status)) . '</span></p>';
    if ($status === 'ej_paborjad' || $status === 'vantar') {
        $error = trim((string) ($state['error'] ?? ''));
        if ($error !== '') {
            $html .= '<p class="ordrar-help is-alert">' . e($error) . '</p>';
        }
    }
    return $html;
}

/**
 * Namn, e-post, telefon och adress från kontakten. Personnummer stannar på raden.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ellevio_fullmakt_customer_row(array $row): array
{
    if (function_exists('jeab_snapshots_should_freeze') && jeab_snapshots_should_freeze($row, 'fullmakt')) {
        return jeab_snapshots_frozen_party($row, 'fullmakt');
    }
    if (!function_exists('jeab_contacts_sole_recipient_apply')) {
        return $row;
    }

    return jeab_contacts_sole_recipient_apply($row);
}

/**
 * Giltig e-post, eller tom sträng.
 */
function jeab_ellevio_fullmakt_keep_email(string $raw): string
{
    $email = function_exists('jeab_kontakt_email') ? jeab_kontakt_email($raw) : trim($raw);
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return '';
    }

    return $email;
}

/**
 * Svenskt nummer, eller tom sträng.
 */
function jeab_ellevio_fullmakt_keep_phone(string $raw): string
{
    if (function_exists('jeab_kontakt_phone')) {
        return jeab_kontakt_phone($raw);
    }

    return trim($raw);
}

/**
 * Kontaktens tre e-postfält, sedan ägarnas adresser.
 *
 * @param array<string, mixed> $contact
 */
function jeab_ellevio_fullmakt_first_contact_email(array $contact): string
{
    $raw = [];
    foreach (['email', 'email2', 'email3'] as $key) {
        $raw[] = (string) ($contact[$key] ?? '');
    }
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        if (is_array($owner)) {
            $raw[] = (string) ($owner['email'] ?? '');
        }
    }
    foreach ($raw as $email) {
        $kept = jeab_ellevio_fullmakt_keep_email($email);
        if ($kept !== '') {
            return $kept;
        }
    }

    return '';
}

/**
 * Kontaktens två mobilfält, sedan ägarnas nummer.
 *
 * @param array<string, mixed> $contact
 */
function jeab_ellevio_fullmakt_first_contact_phone(array $contact): string
{
    $raw = [];
    foreach (['phone', 'phone2'] as $key) {
        $raw[] = (string) ($contact[$key] ?? '');
    }
    foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
        if (!is_array($owner)) {
            continue;
        }
        $raw[] = (string) ($owner['phone'] ?? '');
        $raw[] = (string) ($owner['phone2'] ?? '');
    }
    foreach ($raw as $phone) {
        $kept = jeab_ellevio_fullmakt_keep_phone($phone);
        if ($kept !== '') {
            return $kept;
        }
    }

    return '';
}

/**
 * E-post och mobil från orderns contact_id, samma vy som Objekt & läge.
 * Saknas värdet där läses kontaktens tre e-postfält och två mobilfält.
 * Flaggan av eller saknad länk ger tomt. Orderns egna fält är anroparens reserv.
 *
 * @param array<string, mixed> $row
 * @return array{email:string,phone:string}
 */
function jeab_ellevio_fullmakt_contact_channel(array $row): array
{
    $empty = ['email' => '', 'phone' => ''];
    if (!function_exists('jeab_contacts_sole') || !jeab_contacts_sole()) {
        return $empty;
    }
    if (!function_exists('jeab_contacts_sole_view_for')) {
        return $empty;
    }
    $view = jeab_contacts_sole_view_for($row);
    if (!is_array($view) || empty($view['linked'])) {
        return $empty;
    }
    $email = jeab_ellevio_fullmakt_keep_email((string) ($view['email'] ?? ''));
    $phone = jeab_ellevio_fullmakt_keep_phone((string) ($view['phone'] ?? ''));
    $contact = function_exists('jeab_contacts_sole_recipient_contact')
        ? jeab_contacts_sole_recipient_contact($row)
        : null;
    if (!is_array($contact)) {
        return ['email' => $email, 'phone' => $phone];
    }
    if ($email === '') {
        $email = jeab_ellevio_fullmakt_first_contact_email($contact);
    }
    if ($phone === '') {
        $phone = jeab_ellevio_fullmakt_first_contact_phone($contact);
    }

    return ['email' => $email, 'phone' => $phone];
}

function jeab_ellevio_fullmakt_fields(array $row, ?JeabStore $store = null): array
{
    $row = jeab_ellevio_fullmakt_customer_row($row);
    $name = trim((string) ($row['huvudsokande'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($row['name'] ?? ''));
    }
    $email = trim((string) ($row['email'] ?? ''));
    $phone = trim((string) ($row['phone'] ?? ''));
    $strict = function_exists('jeab_contacts_strict') && jeab_contacts_strict()
        && function_exists('jeab_contacts_sole') && jeab_contacts_sole();
    $frozen = ($row['_party_frozen'] ?? '') === 'fullmakt';
    $linked = function_exists('jeab_contacts_sole_recipient_contact')
        && is_array(jeab_contacts_sole_recipient_contact($row));
    $fromContact = !$frozen && function_exists('jeab_contacts_sole_preferred_pnr') ? jeab_contacts_sole_preferred_pnr($row) : '';
    $pnr = $frozen
        ? trim((string) ($row['personnummer'] ?? ''))
        : ($strict ? $fromContact : ($fromContact !== '' ? $fromContact : trim((string) ($row['personnummer'] ?? ''))));
    if ($pnr !== '' && function_exists('jeab_contacts_sole_pnr_text')) {
        $pnr = jeab_contacts_sole_pnr_text($pnr);
    }
    $fromFast = !$frozen && function_exists('jeab_contacts_sole_preferred_fastighet') ? jeab_contacts_sole_preferred_fastighet($row) : '';
    $fastighet = $frozen
        ? trim((string) ($row['fastighetsbeteckning'] ?? ''))
        : ($strict ? $fromFast : ($fromFast !== '' ? $fromFast : trim((string) ($row['fastighetsbeteckning'] ?? ''))));
    $address = trim((string) ($row['huvudsokande_address'] ?? ''));
    if ($address === '') {
        $address = trim((string) ($row['address'] ?? ''));
    }
    if ($strict && !$linked && !$frozen) {
        $name = '';
        $email = '';
        $phone = '';
        $address = '';
    }
    $channel = jeab_ellevio_fullmakt_contact_channel($row);
    if ($email === '' && $channel['email'] !== '') {
        $email = $channel['email'];
    }
    if ($phone === '' && $channel['phone'] !== '') {
        $phone = $channel['phone'];
    }
    $id = (int) ($row['reminder_id'] ?? 0);
    if (!$strict && $store instanceof JeabStore && $id > 0 && function_exists('jeab_get_reminder')) {
        $reminder = jeab_get_reminder($store, $id);
        if (is_array($reminder)) {
            if ($email === '') {
                $email = trim((string) ($reminder['email'] ?? ''));
            }
            if ($phone === '') {
                $phone = trim((string) ($reminder['phone'] ?? ''));
            }
            if ($address === '') {
                $address = trim((string) ($reminder['address'] ?? ''));
            }
        }
    }
    $parts = jeab_ellevio_split_address($address);
    $post = trim((string) ($parts['postnummer'] ?? ''));
    $ort = trim((string) ($parts['ort'] ?? ''));
    $street = trim((string) ($parts['adress'] ?? ''));
    if (!$strict) {
        $savedPost = jeab_ellevio_fullmakt_row_postnummer($row);
        if ($post === '' && $savedPost !== '') {
            $post = $savedPost;
        }
        if ($ort === '') {
            $ort = trim((string) ($row['ort'] ?? ''));
        }
        $gata = trim((string) ($row['gata'] ?? ''));
        if ($gata !== '' && ($street === '' || $street === $address || str_contains($street, ','))) {
            $street = $gata;
        }
    }
    return [
        'namn' => $name,
        'personnummer' => $pnr,
        'telefon' => $phone,
        'epost' => $email,
        'adress' => $street,
        'postnummer' => $post,
        'ort' => $ort,
        'foretagsnamn' => '',
        'typ' => 'privatperson',
        'anlaggningsadress' => $address !== '' ? $address : $street,
        'fastighetsbeteckning' => $fastighet,
    ];
}

function jeab_ellevio_fullmakt_row_postnummer(array $row): string
{
    $raw = trim((string) ($row['postnr'] ?? ''));
    if ($raw === '') {
        $raw = trim((string) ($row['postnummer'] ?? ''));
    }
    if ($raw === '') {
        return '';
    }
    if (function_exists('jeab_ordrar_postnummer_digits') && function_exists('jeab_ordrar_format_postnummer')) {
        $digits = jeab_ordrar_postnummer_digits($raw);
        return $digits !== '' ? jeab_ordrar_format_postnummer($digits) : '';
    }
    if (preg_match('/\b(\d{3})\s?(\d{2})\b/u', $raw, $m) === 1) {
        return $m[1] . ' ' . $m[2];
    }
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) === 5) {
        return substr($digits, 0, 3) . ' ' . substr($digits, 3);
    }
    return '';
}

function jeab_ellevio_fullmakt_party_from_fields(array $fields): array
{
    return [
        'namn' => trim((string) ($fields['namn'] ?? '')),
        'personnummer' => trim((string) ($fields['personnummer'] ?? '')),
        'telefon' => trim((string) ($fields['telefon'] ?? '')),
        'epost' => trim((string) ($fields['epost'] ?? '')),
        'adress' => trim((string) ($fields['adress'] ?? '')),
        'postnummer' => trim((string) ($fields['postnummer'] ?? '')),
        'ort' => trim((string) ($fields['ort'] ?? '')),
        'anlaggningsadress' => trim((string) ($fields['anlaggningsadress'] ?? '')),
        'foretagsnamn' => trim((string) ($fields['foretagsnamn'] ?? '')),
    ];
}

function jeab_ellevio_fullmakt_party(string $name, string $pnr, string $phone, string $email, string $address, string $plant): array
{
    $parts = jeab_ellevio_split_address($address);
    $plant = trim($plant);
    if ($plant === '') {
        $plant = trim($address);
    }
    return [
        'namn' => trim($name),
        'personnummer' => trim($pnr),
        'telefon' => trim($phone),
        'epost' => trim($email),
        'adress' => $parts['adress'],
        'postnummer' => $parts['postnummer'],
        'ort' => $parts['ort'],
        'anlaggningsadress' => $plant,
        'foretagsnamn' => '',
    ];
}

function jeab_ellevio_fullmakt_item_text(array $item, array $keys): string
{
    foreach ($keys as $key) {
        $value = trim((string) ($item[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function jeab_ellevio_fullmakt_med_parties(array $row, string $plant = ''): array
{
    $row = jeab_ellevio_fullmakt_customer_row($row);
    $list = $row['medsokande'] ?? [];
    if (!is_array($list)) {
        return [];
    }
    $out = [];
    foreach ($list as $item) {
        if (count($out) >= 3) {
            break;
        }
        if (is_string($item) || is_numeric($item)) {
            $item = ['name' => (string) $item];
        }
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string) ($item['name'] ?? ''));
        $frozenParty = ($row['_party_frozen'] ?? '') === 'fullmakt';
        $fromOwner = !$frozenParty && function_exists('jeab_contacts_sole_preferred_owner_pnr') ? jeab_contacts_sole_preferred_owner_pnr($row, $name) : '';
        $pnr = $fromOwner !== '' ? $fromOwner : trim((string) ($item['personnummer'] ?? ''));
        if ($pnr !== '' && function_exists('jeab_contacts_sole_pnr_text')) {
            $pnr = jeab_contacts_sole_pnr_text($pnr);
        }
        if ($name === '' && $pnr === '') {
            continue;
        }
        $address = trim((string) ($item['address'] ?? $item['adress'] ?? ''));
        $phone = jeab_ellevio_fullmakt_item_text($item, ['phone', 'telefon', 'tel', 'mobil']);
        $email = jeab_ellevio_fullmakt_item_text($item, ['email', 'epost', 'e-post']);
        $ownPlant = $address !== '' ? $address : $plant;
        $out[] = jeab_ellevio_fullmakt_party($name, $pnr, $phone, $email, $address, $ownPlant);
    }
    return $out;
}

function jeab_ellevio_fullmakt_party_bundle(array $row, ?array $fields = null, ?JeabStore $store = null): array
{
    if ($fields === null) {
        $fields = jeab_ellevio_fullmakt_fields($row, $store);
    }
    $huvud = jeab_ellevio_fullmakt_party_from_fields($fields);
    return [
        'invite_epost' => $huvud['epost'],
        'invite_namn' => $huvud['namn'],
        'parter' => [
            'huvudsokande' => $huvud,
            'medsokande' => jeab_ellevio_fullmakt_med_parties($row, $huvud['anlaggningsadress']),
        ],
    ];
}

function jeab_ellevio_fullmakt_med_parties_for_token(string $token): array
{
    $token = trim($token);
    if ($token === '' || !function_exists('jeab_purchase_all')) {
        return [];
    }
    foreach (jeab_purchase_all() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $matches = trim((string) ($row['fullmakt_token'] ?? '')) === $token;
        if (!$matches && function_exists('jeab_bokmalen_ellevio_fullmakt_row_has_token')) {
            $matches = jeab_bokmalen_ellevio_fullmakt_row_has_token($row, $token);
        }
        if (!$matches) {
            continue;
        }
        $fields = jeab_ellevio_fullmakt_fields($row);
        return jeab_ellevio_fullmakt_med_parties($row, (string) ($fields['anlaggningsadress'] ?? ''));
    }
    return [];
}

function jeab_ellevio_fullmakt_anlaggnings_digits(array $row): string
{
    $raw = (string) ($row['anlaggnings_id'] ?? '');
    if (function_exists('fullmakt_anlaggnings_id_digits')) {
        return fullmakt_anlaggnings_id_digits($raw);
    }
    return preg_replace('/\D+/', '', $raw) ?? '';
}

function jeab_ellevio_fullmakt_anlaggnings_id_for_token(string $token): string
{
    $token = trim($token);
    if ($token === '' || !function_exists('jeab_purchase_all')) {
        return '';
    }
    foreach (jeab_purchase_all() as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (trim((string) ($row['fullmakt_token'] ?? '')) !== $token) {
            continue;
        }
        return jeab_ellevio_fullmakt_anlaggnings_digits($row);
    }
    return '';
}

function jeab_ellevio_fullmakt_missing(array $fields): array
{
    // Anläggnings-id hör inte hit. Kunden fyller det vid signering.
    // Tomt fält i Ordrar får inte stoppa inbjudan, omskick eller Skicka igen.
    $missing = [];
    foreach ([
        'namn' => 'namn',
        'personnummer' => 'personnummer',
        'telefon' => 'telefon',
        'epost' => 'e-post',
        'adress' => 'adress',
        'postnummer' => 'postnummer',
        'ort' => 'ort',
    ] as $key => $label) {
        if (trim((string) ($fields[$key] ?? '')) === '') {
            $missing[] = $label;
        }
    }
    $email = trim((string) ($fields['epost'] ?? ''));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false && !in_array('e-post', $missing, true)) {
        $missing[] = 'e-post';
    }
    return $missing;
}

/**
 * Bokmalen-sökväg efter signering: anläggnings-id finns på fullmaktsdokumentet,
 * inte i Ordrar innan kunden skrivit under. Kopiera till orderraden när fältet
 * är tomt. En redan lagd order skickas inte om.
 */
function jeab_ellevio_fullmakt_pull_signed(array $row, bool $notify = true): array
{
    $original = $row;
    try {
        return jeab_ellevio_fullmakt_copy_signed_id($row, $notify);
    } catch (Throwable) {
        return $original;
    }
}

function jeab_ellevio_fullmakt_copy_signed_id(array $row, bool $notify = true): array
{
    if (!jeab_ellevio_fullmakt_applies($row)) {
        return $row;
    }
    $token = trim((string) ($row['fullmakt_token'] ?? ''));
    if ($token === '' || !jeab_ellevio_fullmakt_load() || !function_exists('fullmakt_load_doc')) {
        return $row;
    }
    $doc = fullmakt_load_doc($token);
    if (!is_array($doc) || ($doc['status'] ?? '') !== 'signed') {
        return $row;
    }
    $changed = empty($row['fullmakt_signed']);
    $row['fullmakt_signed'] = true;
    if (trim((string) ($row['fullmakt_error'] ?? '')) !== '') {
        $row['fullmakt_error'] = '';
        $changed = true;
    }
    $aid = preg_replace('/\D+/u', '', (string) ($doc['anlaggnings_id'] ?? '')) ?? '';
    $have = function_exists('jeab_ordrar_anlaggnings_id_digits')
        ? jeab_ordrar_anlaggnings_id_digits((string) ($row['anlaggnings_id'] ?? ''))
        : (preg_replace('/\D+/u', '', (string) ($row['anlaggnings_id'] ?? '')) ?? '');
    if ($have !== (string) ($row['anlaggnings_id'] ?? '')) {
        $row['anlaggnings_id'] = $have;
        $changed = true;
    }
    if ($aid !== '' && $have === '') {
        $row['anlaggnings_id'] = $aid;
        $changed = true;
    }
    if ($changed) {
        $id = (int) ($row['reminder_id'] ?? 0);
        $signed = !empty($row['fullmakt_signed']);
        $error = (string) ($row['fullmakt_error'] ?? '');
        $aid = (string) ($row['anlaggnings_id'] ?? '');
        $saved = null;
        if ($id > 0 && function_exists('jeab_purchase_patch')) {
            $saved = jeab_purchase_patch($id, static function (array $current) use ($signed, $error, $aid): array {
                $current['fullmakt_signed'] = $signed;
                $current['fullmakt_error'] = $error;
                if ($aid !== '') {
                    $current['anlaggnings_id'] = $aid;
                }
                $current['updated_at'] = function_exists('jeab_now') ? jeab_now() : (string) ($current['updated_at'] ?? '');

                return $current;
            });
        } elseif (function_exists('jeab_purchase_put')) {
            $row = jeab_purchase_put($row);
        }
        if (is_array($saved)) {
            foreach (['fullmakt_signed', 'fullmakt_error', 'anlaggnings_id', 'updated_at'] as $key) {
                if (array_key_exists($key, $saved)) {
                    $row[$key] = $saved[$key];
                }
            }
        }
    }
    return $row;
}

function jeab_ellevio_fullmakt_was_mailed(array $row): bool
{
    $value = $row['fullmakt_mailed'] ?? false;
    return $value === true || $value === 1 || $value === '1';
}

function jeab_ellevio_fullmakt_should_resend(array $row): bool
{
    if (!jeab_ellevio_fullmakt_applies($row) || !empty($row['fullmakt_signed'])) {
        return false;
    }
    if (jeab_ellevio_fullmakt_was_mailed($row)) {
        return false;
    }
    $error = trim((string) ($row['fullmakt_error'] ?? ''));
    if (trim((string) ($row['fullmakt_start_at'] ?? '')) !== '' && $error === '') {
        return false;
    }
    $token = trim((string) ($row['fullmakt_token'] ?? ''));
    return $token !== '' || $error !== '';
}

function jeab_ellevio_fullmakt_mail_reason(): string
{
    if (function_exists('fullmakt_mail_last_error')) {
        $reason = trim(fullmakt_mail_last_error());
        if ($reason !== '') {
            return $reason;
        }
    }
    return 'Mejlet kunde inte skickas.';
}

function jeab_ellevio_fullmakt_record_send(array $row, array $doc, string $url): array
{
    $ok = false;
    $reason = '';
    try {
        if (function_exists('fullmakt_mail_last_error')) {
            fullmakt_mail_last_error('');
        }
        $ok = fullmakt_send_invite($doc, $url);
        if (!$ok) {
            $reason = jeab_ellevio_fullmakt_mail_reason();
        }
    } catch (Throwable $e) {
        $ok = false;
        $reason = trim($e->getMessage());
        if ($reason === '') {
            $reason = 'Utskick avbröts.';
        }
    }
    $row['fullmakt_url'] = $url;
    $row['fullmakt_mailed'] = $ok;
    if ($ok) {
        $row['fullmakt_error'] = '';
        $row['fullmakt_sent_at'] = function_exists('jeab_now') ? jeab_now() : date('c');
        $path = function_exists('fullmakt_mail_last_path') ? trim(fullmakt_mail_last_path()) : '';
        $row['fullmakt_mail_path'] = $path;
        $via = function_exists('jeab_system_mail_path_label') ? jeab_system_mail_path_label($path) : '';
        if (function_exists('jeab_ordrar_append_shop_activity_row')) {
            $note = 'Fullmakt till Ellevio skickad till kunden';
            $note .= $via !== '' ? ' via ' . $via . '.' : '.';
            $row = jeab_ordrar_append_shop_activity_row($row, $note, 'fullmakt');
        }
    } else {
        $row['fullmakt_mail_path'] = '';
        $row['fullmakt_error'] = $reason !== '' ? $reason : 'Mejlet kunde inte skickas.';
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('ordrar', 'Ellevio-fullmakt: ' . $row['fullmakt_error']);
        }
    }
    return function_exists('jeab_purchase_put') ? jeab_purchase_put($row) : $row;
}

/** Orderns id som sträng, samma som fullmakt.start skickar. */
function jeab_ellevio_fullmakt_order_ref(array $row): string
{
    foreach ([$row['reminder_id'] ?? null, $row['id'] ?? null] as $raw) {
        $id = (int) $raw;
        if ($id > 0) {
            return (string) $id;
        }
    }
    return '';
}

/**
 * Sparar orderns id på den väntande fullmakten, så fullmakt.signed får
 * samma order_id som fullmakt.start även om ordern inte går att slå upp
 * via token vid signeringen. Signerad fullmakt rörs inte.
 */
function jeab_ellevio_fullmakt_attach_order_id(array $doc, array $row): array
{
    if (($doc['status'] ?? '') === 'signed') {
        return $doc;
    }
    $ref = jeab_ellevio_fullmakt_order_ref($row);
    if ($ref === '' || (string) ($doc['order_id'] ?? '') === $ref) {
        return $doc;
    }
    $doc['order_id'] = $ref;
    $token = trim((string) ($doc['token'] ?? ''));
    if ($token === '' || !jeab_ellevio_fullmakt_can_save_pending($token)) {
        return $doc;
    }
    try {
        fullmakt_save_pending($doc);
    } catch (Throwable) {
        // Signeringen hittar ordern via token som reserv.
    }
    return $doc;
}

/**
 * Finns anläggnings-id på ordern kopieras det till den väntande fullmakten
 * så kunden ser det förifyllt. Tomt fält ändrar inget och stoppar inget utskick.
 */
function jeab_ellevio_fullmakt_attach_order_anlaggnings_id(array $doc, array $row): array
{
    if (($doc['status'] ?? '') === 'signed') {
        return $doc;
    }
    $digits = jeab_ellevio_fullmakt_anlaggnings_digits($row);
    if ($digits === '') {
        return $doc;
    }
    if ((string) ($doc['anlaggnings_id'] ?? '') === $digits) {
        return $doc;
    }
    $doc['anlaggnings_id'] = $digits;
    $token = trim((string) ($doc['token'] ?? ''));
    if ($token === '' || !jeab_ellevio_fullmakt_can_save_pending($token)) {
        return $doc;
    }
    try {
        fullmakt_save_pending($doc);
    } catch (Throwable) {
        // Utskicket ska gå även om förifyllningen inte gick att spara.
    }
    return $doc;
}

function jeab_ellevio_fullmakt_prepare_row(array $row): array
{
    if (!function_exists('jeab_ordrar_persist_postnummer')) {
        return $row;
    }
    return jeab_ordrar_persist_postnummer($row);
}

/**
 * Tomt postnummer stoppar utskick. Anläggnings-id gör det inte.
 * Redan skickad eller signerad fullmakt rörs inte.
 */
function jeab_ellevio_fullmakt_reject_missing_postnummer(array $row, ?JeabStore $store = null): ?array
{
    if (jeab_ellevio_fullmakt_was_mailed($row) || !empty($row['fullmakt_signed'])) {
        return null;
    }
    $fields = jeab_ellevio_fullmakt_fields($row, $store);
    if (trim((string) ($fields['postnummer'] ?? '')) !== '') {
        return null;
    }
    $row['fullmakt_error'] = 'Saknas för fullmakt: postnummer. Fyll i postnummer bredvid adressen innan du skickar.';
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id > 0 && function_exists('jeab_purchase_get') && is_array(jeab_purchase_get($id)) && function_exists('jeab_purchase_put')) {
        return jeab_purchase_put($row);
    }
    return $row;
}

function jeab_ellevio_fullmakt_attach_order_postnummer(array $doc, array $fields): array
{
    if (($doc['status'] ?? '') === 'signed') {
        return $doc;
    }
    $post = trim((string) ($fields['postnummer'] ?? ''));
    if ($post === '' || trim((string) ($doc['postnummer'] ?? '')) !== '') {
        return $doc;
    }
    $doc['postnummer'] = $post;
    if (trim((string) ($doc['ort'] ?? '')) === '') {
        $ort = trim((string) ($fields['ort'] ?? ''));
        if ($ort !== '') {
            $doc['ort'] = $ort;
        }
    }
    $token = trim((string) ($doc['token'] ?? ''));
    if ($token === '' || !jeab_ellevio_fullmakt_can_save_pending($token)) {
        return $doc;
    }
    try {
        fullmakt_save_pending($doc);
    } catch (Throwable) {
    }
    return $doc;
}

function jeab_ellevio_fullmakt_can_save_pending(string $token): bool
{
    if ($token === '' || !function_exists('fullmakt_save_pending')) {
        return false;
    }
    if (function_exists('fullmakt_pending_exists')) {
        return fullmakt_pending_exists($token);
    }

    return function_exists('fullmakt_pending_path') && is_file(fullmakt_pending_path($token));
}

function jeab_ellevio_fullmakt_persist(array $row): array
{
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id > 0 && function_exists('jeab_purchase_get') && is_array(jeab_purchase_get($id)) && function_exists('jeab_purchase_put')) {
        return jeab_purchase_put($row);
    }
    return $row;
}

function jeab_ellevio_fullmakt_handed_off(array $row): bool
{
    if (jeab_ellevio_fullmakt_was_mailed($row)) {
        return true;
    }
    return trim((string) ($row['fullmakt_start_at'] ?? '')) !== '';
}

/**
 * Startar Ellevio-fullmakt via webhook till Bokmalen.
 * Dokumentet skapas så kunden kan signera på sign_url. Ordrar skickar inte mejlet.
 * Anläggnings-id krävs inte. Redan startad rad postas inte igen, utom vid omsändning.
 */
function jeab_ellevio_fullmakt_start(array $row, ?JeabStore $store = null, bool $force = false): array
{
    $row = jeab_ellevio_fullmakt_prepare_row($row);
    if (!jeab_ellevio_fullmakt_applies($row)) {
        return $row;
    }
    if (!empty($row['fullmakt_signed'])) {
        return jeab_ellevio_fullmakt_pull_signed($row, true);
    }
    if (!$force && jeab_ellevio_fullmakt_handed_off($row)) {
        return $row;
    }
    if (jeab_ellevio_fullmakt_frozen_identity_empty($row)) {
        throw new InvalidArgumentException('Den skickade fullmakten saknar kunduppgifter och skickas inte tom.');
    }
    if (function_exists('jeab_contacts_strict_block')) {
        $block = jeab_contacts_strict_block($row, 'ellevio');
        if ($block !== '') {
            $row['fullmakt_error'] = $block;
            $row = jeab_ellevio_fullmakt_persist($row);
            throw new InvalidArgumentException($block);
        }
    }
    if (!jeab_ellevio_fullmakt_load()) {
        $row['fullmakt_error'] = 'Fullmaktmodulen saknas på servern.';
        $row = jeab_ellevio_fullmakt_persist($row);
        throw new InvalidArgumentException($row['fullmakt_error']);
    }
    $rejected = jeab_ellevio_fullmakt_reject_missing_postnummer($row, $store);
    if ($rejected !== null) {
        $message = trim((string) ($rejected['fullmakt_error'] ?? ''));
        throw new InvalidArgumentException($message !== '' ? $message : 'Saknas för fullmakt: postnummer.');
    }
    $fields = jeab_ellevio_fullmakt_fields($row, $store);
    $missing = jeab_ellevio_fullmakt_missing($fields);
    if ($missing !== []) {
        $row['fullmakt_error'] = 'Saknas för fullmakt: ' . implode(', ', $missing) . '.';
        $row = jeab_ellevio_fullmakt_persist($row);
        throw new InvalidArgumentException($row['fullmakt_error']);
    }

    $token = trim((string) ($row['fullmakt_token'] ?? ''));
    $doc = null;
    if ($token !== '' && function_exists('fullmakt_load_doc')) {
        $loaded = fullmakt_load_doc($token);
        if (is_array($loaded)) {
            if (($loaded['status'] ?? '') === 'signed') {
                return jeab_ellevio_fullmakt_pull_signed($row, true);
            }
            $doc = jeab_ellevio_fullmakt_attach_order_anlaggnings_id($loaded, $row);
            $doc = jeab_ellevio_fullmakt_attach_order_postnummer($doc, $fields);
            $doc = jeab_ellevio_fullmakt_attach_order_id($doc, $row);
        }
    }
    if (!is_array($doc)) {
        $bundleForDoc = jeab_ellevio_fullmakt_party_bundle($row, $fields, $store);
        try {
            $doc = fullmakt_create_doc([
                'namn' => $fields['namn'],
                'personnummer' => $fields['personnummer'],
                'telefon' => $fields['telefon'],
                'epost' => $fields['epost'],
                'adress' => $fields['adress'],
                'postnummer' => $fields['postnummer'],
                'ort' => $fields['ort'],
                'foretagsnamn' => '',
                'typ' => 'privatperson',
                'anlaggningsadress' => $fields['anlaggningsadress'],
                'anlaggnings_id' => jeab_ellevio_fullmakt_anlaggnings_digits($row),
                'order_id' => jeab_ellevio_fullmakt_order_ref($row),
                'part' => 'huvudsokande',
                'invite_epost' => $bundleForDoc['invite_epost'],
                'invite_namn' => $bundleForDoc['invite_namn'],
                'parter' => $bundleForDoc['parter'],
            ]);
        } catch (Throwable) {
            $row['fullmakt_error'] = 'Kunde inte skapa fullmakten.';
            $row = jeab_ellevio_fullmakt_persist($row);
            throw new InvalidArgumentException($row['fullmakt_error']);
        }
        $token = trim((string) ($doc['token'] ?? ''));
    }
    if ($token === '') {
        $row['fullmakt_error'] = 'Kunde inte skapa fullmakten.';
        $row = jeab_ellevio_fullmakt_persist($row);
        throw new InvalidArgumentException($row['fullmakt_error']);
    }
    $row['fullmakt_token'] = $token;
    $row['fullmakt_url'] = jeab_ellevio_fullmakt_url($token);
    $row['fullmakt_channel'] = 'bokmalen';
    $row['fullmakt_mailed'] = false;
    $bundle = jeab_ellevio_fullmakt_party_bundle($row, $fields, $store);
    $row = jeab_ellevio_fullmakt_persist($row);
    $ok = function_exists('jeab_bokmalen_ellevio_fullmakt_start_notify')
        && jeab_bokmalen_ellevio_fullmakt_start_notify($row, $fields, $bundle, $token);
    if (!$ok) {
        $row['fullmakt_error'] = 'Fullmakten kunde inte startas hos Bokmalen.';
        $row['fullmakt_start_at'] = '';
        $row = jeab_ellevio_fullmakt_persist($row);
        throw new InvalidArgumentException($row['fullmakt_error']);
    }
    $row['fullmakt_error'] = '';
    $row['fullmakt_start_at'] = function_exists('jeab_now') ? jeab_now() : date('c');
    if (function_exists('jeab_snapshots_capture_sent')) {
        $party = [
            'name' => (string) ($fields['namn'] ?? ''),
            'email' => (string) ($fields['epost'] ?? ''),
            'phone' => (string) ($fields['telefon'] ?? ''),
            'address' => (string) ($fields['anlaggningsadress'] ?? ''),
            'owners' => [],
        ];
        $huvud = is_array($bundle['parter']['huvudsokande'] ?? null) ? $bundle['parter']['huvudsokande'] : [];
        if ($huvud !== []) {
            $party['owners'][] = $huvud;
        }
        foreach ((array) ($bundle['parter']['medsokande'] ?? []) as $med) {
            if (is_array($med)) {
                $party['owners'][] = $med;
            }
        }
        $row = jeab_snapshots_capture_sent($row, 'fullmakt', $party, 'send');
    }
    if (function_exists('jeab_ordrar_append_shop_activity_row')) {
        $row = jeab_ordrar_append_shop_activity_row($row, 'Fullmakt startad hos Bokmalen.', 'fullmakt-start');
    }
    $row['updated_at'] = function_exists('jeab_now') ? jeab_now() : ($row['updated_at'] ?? '');
    return jeab_ellevio_fullmakt_persist($row);
}

function jeab_ellevio_fullmakt_resend(array $row, ?JeabStore $store = null): array
{
    $row = jeab_ellevio_fullmakt_prepare_row($row);
    if (!jeab_ellevio_fullmakt_applies($row)) {
        return $row;
    }
    if (!empty($row['fullmakt_signed'])) {
        return jeab_ellevio_fullmakt_pull_signed($row, true);
    }
    $channel = trim((string) ($row['fullmakt_channel'] ?? ''));
    $token = trim((string) ($row['fullmakt_token'] ?? ''));
    if ($channel === 'bokmalen' || $token === '') {
        return jeab_ellevio_fullmakt_start($row, $store, true);
    }
    if (!jeab_ellevio_fullmakt_load()) {
        $row['fullmakt_error'] = 'Fullmaktmodulen saknas på servern.';
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('ordrar', 'Ellevio-fullmakt: ' . $row['fullmakt_error']);
        }
        return function_exists('jeab_purchase_put') ? jeab_purchase_put($row) : $row;
    }
    $token = trim((string) ($row['fullmakt_token'] ?? ''));
    if ($token !== '') {
        $doc = fullmakt_load_doc($token);
        if (is_array($doc)) {
            if (($doc['status'] ?? '') === 'signed') {
                return jeab_ellevio_fullmakt_pull_signed($row, true);
            }
            $doc = jeab_ellevio_fullmakt_attach_order_anlaggnings_id($doc, $row);
            $doc = jeab_ellevio_fullmakt_attach_order_id($doc, $row);
            if (jeab_ellevio_fullmakt_was_mailed($row)) {
                return $row;
            }
            $rejected = jeab_ellevio_fullmakt_reject_missing_postnummer($row, $store);
            if ($rejected !== null) {
                return $rejected;
            }
            $doc = jeab_ellevio_fullmakt_attach_order_postnummer($doc, jeab_ellevio_fullmakt_fields($row, $store));
            return jeab_ellevio_fullmakt_record_send($row, $doc, jeab_ellevio_fullmakt_url($token));
        }
    }
    if (jeab_ellevio_fullmakt_was_mailed($row)) {
        return $row;
    }
    return jeab_ellevio_fullmakt_ensure($row, $store);
}

function jeab_ellevio_fullmakt_ensure(array $row, ?JeabStore $store = null): array
{
    $row = jeab_ellevio_fullmakt_prepare_row($row);
    if (!jeab_ellevio_fullmakt_applies($row)) {
        return $row;
    }
    if (!jeab_ellevio_fullmakt_load()) {
        $row['fullmakt_error'] = 'Fullmaktmodulen saknas på servern.';
        return function_exists('jeab_purchase_put') ? jeab_purchase_put($row) : $row;
    }

    $rejected = jeab_ellevio_fullmakt_reject_missing_postnummer($row, $store);
    if ($rejected !== null) {
        return $rejected;
    }

    $token = trim((string) ($row['fullmakt_token'] ?? ''));
    if ($token !== '') {
        $doc = fullmakt_load_doc($token);
        if (is_array($doc)) {
            if (($doc['status'] ?? '') === 'signed') {
                return jeab_ellevio_fullmakt_pull_signed($row, true);
            }
            $doc = jeab_ellevio_fullmakt_attach_order_anlaggnings_id($doc, $row);
            $doc = jeab_ellevio_fullmakt_attach_order_id($doc, $row);
            if (jeab_ellevio_fullmakt_was_mailed($row)) {
                return $row;
            }
            $doc = jeab_ellevio_fullmakt_attach_order_postnummer($doc, jeab_ellevio_fullmakt_fields($row, $store));
            return jeab_ellevio_fullmakt_record_send($row, $doc, jeab_ellevio_fullmakt_url($token));
        }
    }

    $fields = jeab_ellevio_fullmakt_fields($row, $store);
    $missing = jeab_ellevio_fullmakt_missing($fields);
    if ($missing !== []) {
        $row['fullmakt_error'] = 'Saknas för fullmakt: ' . implode(', ', $missing) . '.';
        return function_exists('jeab_purchase_put') ? jeab_purchase_put($row) : $row;
    }

    $bundle = jeab_ellevio_fullmakt_party_bundle($row, $fields, $store);
    try {
        $doc = fullmakt_create_doc([
            'namn' => $fields['namn'],
            'personnummer' => $fields['personnummer'],
            'telefon' => $fields['telefon'],
            'epost' => $fields['epost'],
            'adress' => $fields['adress'],
            'postnummer' => $fields['postnummer'],
            'ort' => $fields['ort'],
            'foretagsnamn' => '',
            'typ' => 'privatperson',
            'anlaggningsadress' => $fields['anlaggningsadress'],
            'anlaggnings_id' => jeab_ellevio_fullmakt_anlaggnings_digits($row),
            'order_id' => jeab_ellevio_fullmakt_order_ref($row),
            'part' => 'huvudsokande',
            'invite_epost' => $bundle['invite_epost'],
            'invite_namn' => $bundle['invite_namn'],
            'parter' => $bundle['parter'],
        ]);
    } catch (Throwable $e) {
        $row['fullmakt_error'] = 'Kunde inte skapa fullmakten.';
        return function_exists('jeab_purchase_put') ? jeab_purchase_put($row) : $row;
    }
    $token = (string) ($doc['token'] ?? '');
    $row['fullmakt_token'] = $token;
    if ($token === '') {
        $row['fullmakt_error'] = 'Kunde inte skapa fullmakten.';
        return function_exists('jeab_purchase_put') ? jeab_purchase_put($row) : $row;
    }
    return jeab_ellevio_fullmakt_record_send($row, $doc, jeab_ellevio_fullmakt_url($token));
}

/**
 * @return list<array<string, mixed>>
 */
function jeab_ellevio_fullmakt_history(array $row): array
{
    $history = $row['fullmakt_history'] ?? [];
    if (!is_array($history)) {
        return [];
    }
    $out = [];
    foreach ($history as $item) {
        if (is_array($item)) {
            $out[] = $item;
        }
    }
    return $out;
}

/**
 * Lägger den nuvarande fullmakten i historiken och tömmer pekaren.
 * Filer och signeringslänkar raderas inte.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ellevio_fullmakt_archive_current(array $row): array
{
    $token = trim((string) ($row['fullmakt_token'] ?? ''));
    $url = trim((string) ($row['fullmakt_url'] ?? ''));
    $start = trim((string) ($row['fullmakt_start_at'] ?? ''));
    $signed = !empty($row['fullmakt_signed']);
    $mailed = jeab_ellevio_fullmakt_was_mailed($row);
    if ($token === '' && $url === '' && $start === '' && !$signed && !$mailed) {
        return $row;
    }
    $history = jeab_ellevio_fullmakt_history($row);
    $history[] = [
        'token' => $token,
        'url' => $url,
        'signed' => $signed,
        'start_at' => $start,
        'sent_at' => trim((string) ($row['fullmakt_sent_at'] ?? '')),
        'mailed' => $mailed,
        'channel' => trim((string) ($row['fullmakt_channel'] ?? '')),
        'archived_at' => function_exists('jeab_now') ? jeab_now() : date('c'),
    ];
    $row['fullmakt_history'] = $history;
    $row['fullmakt_token'] = '';
    $row['fullmakt_url'] = '';
    $row['fullmakt_signed'] = false;
    $row['fullmakt_start_at'] = '';
    $row['fullmakt_mailed'] = false;
    $row['fullmakt_sent_at'] = '';
    $row['fullmakt_error'] = '';
    $row['fullmakt_mail_path'] = '';
    $row['fullmakt_channel'] = '';
    return $row;
}

function jeab_ellevio_fullmakt_new_recently(array $row, int $seconds = 60): bool
{
    $at = trim((string) ($row['fullmakt_new_at'] ?? ''));
    if ($at === '') {
        return false;
    }
    $ts = strtotime($at);
    $now = strtotime(function_exists('jeab_now') ? jeab_now() : 'now');
    return $ts !== false && $now !== false && ($now - $ts) < $seconds && ($now - $ts) >= -5;
}

function jeab_ellevio_fullmakt_show_new(array $row): bool
{
    if (!jeab_ellevio_fullmakt_applies($row)) {
        return false;
    }
    if (trim((string) ($row['fullmakt_token'] ?? '')) !== '' || trim((string) ($row['fullmakt_url'] ?? '')) !== '') {
        return true;
    }
    if (trim((string) ($row['fullmakt_start_at'] ?? '')) !== '' || jeab_ellevio_fullmakt_was_mailed($row) || !empty($row['fullmakt_signed'])) {
        return true;
    }
    return jeab_ellevio_fullmakt_history($row) !== [];
}

/**
 * Ny fullmakt via samma start som första skicket (fullmakt.start, ägare, signerlänk).
 * Den tidigare fullmakten ligger kvar i historiken. Dubbelklick inom en minut
 * postar inte en andra gång.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ellevio_fullmakt_send_new(array $row, ?JeabStore $store = null): array
{
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0) {
        $id = (int) ($row['id'] ?? 0);
    }
    $run = static function () use ($row, $store, $id): array {
        $keepNew = !empty($row['_snapshot_new_version']) || !empty($GLOBALS['_JEAB_SNAPSHOT_NEW_VERSION']);
        if ($id > 0 && function_exists('jeab_purchase_get')) {
            $fresh = jeab_purchase_get($id);
            if (is_array($fresh)) {
                $row = $fresh;
            }
        }
        if ($keepNew) {
            $row['_snapshot_new_version'] = true;
        }
        if (!jeab_ellevio_fullmakt_applies($row)) {
            throw new InvalidArgumentException('Ny fullmakt finns bara för Ellevio.');
        }
        if (jeab_ellevio_fullmakt_new_recently($row)) {
            $row['fullmakt_new_kept'] = true;
            return $row;
        }
        $previous = trim((string) ($row['fullmakt_token'] ?? ''));
        $row = jeab_ellevio_fullmakt_row_for_new($row);
        $row = jeab_ellevio_fullmakt_start($row, $store, true);
        if (trim((string) ($row['fullmakt_error'] ?? '')) === '' && trim((string) ($row['fullmakt_start_at'] ?? '')) !== '') {
            $row['fullmakt_new_at'] = function_exists('jeab_now') ? jeab_now() : date('c');
            if ($previous !== '' && function_exists('jeab_ordrar_append_shop_activity_row')) {
                $row = jeab_ordrar_append_shop_activity_row($row, 'Ny fullmakt startad. Den tidigare fullmakten ligger kvar.', 'fullmakt-ny');
            }
            $row = jeab_ellevio_fullmakt_persist($row);
        }
        $row['fullmakt_new_kept'] = false;
        return $row;
    };
    if ($id > 0 && function_exists('jeab_ordrar_with_order_lock')) {
        $out = jeab_ordrar_with_order_lock($id, $run);
        return is_array($out) ? $out : $row;
    }
    return $run();
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ellevio_fullmakt_new_from_input(array $input, ?JeabStore $store = null): array
{
    if ((string) ($input['confirm'] ?? '') !== '1') {
        throw new InvalidArgumentException('Bekräfta att en ny fullmakt ska skickas.');
    }
    if (!function_exists('jeab_ordrar_require')) {
        throw new InvalidArgumentException('Ordern saknas.');
    }
    $row = jeab_ordrar_require((int) ($input['id'] ?? 0));
    if (function_exists('jeab_snapshots_wants_new') && jeab_snapshots_wants_new($input)) {
        $row['_snapshot_new_version'] = true;
    }

    return jeab_ellevio_fullmakt_send_new($row, $store);
}

/**
 * Ny fullmakt utan ny version behåller den skickade kopian.
 * Arkivet tar bort token och skickad-flaggor, så kopian sparas först.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ellevio_fullmakt_row_for_new(array $row): array
{
    $newVersion = !empty($row['_snapshot_new_version']);
    if (!$newVersion && function_exists('jeab_snapshots_capture_sent') && !jeab_snapshots_has_local($row, 'fullmakt')) {
        $row = jeab_snapshots_capture_sent($row, 'fullmakt', $row, 'send');
    }
    $row = jeab_ellevio_fullmakt_archive_current($row);
    if ($newVersion) {
        $row['_snapshot_new_version'] = true;
    }

    return $row;
}

/**
 * Mejltexten som första skick och omskick använder. Skickar inget.
 *
 * @param array<string, mixed> $bundle
 * @return array{subject:string,text:string}
 */
function jeab_ellevio_fullmakt_invite_message(array $bundle, string $url): array
{
    if ($url === '') {
        $url = 'https://flowstack.example.test/fullmakt/s/…';
    }
    if (!function_exists('fullmakt_invite_parts') && function_exists('jeab_ellevio_fullmakt_load')) {
        jeab_ellevio_fullmakt_load();
    }
    $doc = [
        'type' => 'ellevio',
        'namn' => (string) ($bundle['invite_namn'] ?? ''),
        'epost' => (string) ($bundle['invite_epost'] ?? ''),
        'invite_namn' => (string) ($bundle['invite_namn'] ?? ''),
        'invite_epost' => (string) ($bundle['invite_epost'] ?? ''),
    ];
    if (function_exists('fullmakt_invite_parts')) {
        [$subject, $text] = fullmakt_invite_parts($doc, $url);
        return ['subject' => $subject, 'text' => $text];
    }
    $notes = function_exists('fullmakt_invite_ellevio_notes') ? fullmakt_invite_ellevio_notes() : '';
    $name = trim((string) ($bundle['invite_namn'] ?? ''));
    return [
        'subject' => 'Fullmakt till Ellevio — signera',
        'text' => trim("Hej {$name}\n\n{$notes}\nLänk: {$url}"),
    ];
}

function jeab_ellevio_fullmakt_address_hay(array $row): string
{
    $parts = [
        (string) ($row['address'] ?? ''),
        (string) ($row['huvudsokande_address'] ?? ''),
        (string) ($row['gata'] ?? ''),
        (string) ($row['ort'] ?? ''),
    ];
    $hay = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($hay, 'UTF-8');
    }
    return strtolower($hay);
}

function jeab_ellevio_fullmakt_address_match(array $row, string $query): bool
{
    $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
    if ($query === '') {
        return false;
    }
    if (function_exists('mb_strtolower')) {
        $query = mb_strtolower($query, 'UTF-8');
    } else {
        $query = strtolower($query);
    }
    $hay = jeab_ellevio_fullmakt_address_hay($row);
    if ($hay === '') {
        return false;
    }
    if (str_contains($hay, $query)) {
        return true;
    }
    $words = preg_split('/\s+/u', $query) ?: [];
    foreach ($words as $word) {
        if ($word !== '' && !str_contains($hay, $word)) {
            return false;
        }
    }
    return $words !== [];
}

/**
 * Torr förhandsvisning: mottagare, valbara ägare och mejltext. Skickar inget.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_ellevio_fullmakt_frozen_identity_empty(array $row): bool
{
    if (!function_exists('jeab_snapshots_should_freeze') || !jeab_snapshots_should_freeze($row, 'fullmakt')) {
        return false;
    }
    $fields = jeab_ellevio_fullmakt_fields($row);

    return trim((string) ($fields['namn'] ?? '')) === ''
        && trim((string) ($fields['epost'] ?? '')) === ''
        && trim((string) ($fields['telefon'] ?? '')) === ''
        && trim((string) ($fields['adress'] ?? '')) === '';
}

function jeab_ellevio_fullmakt_preview_row(array $row): array
{
    $warning = jeab_ellevio_fullmakt_frozen_identity_empty($row)
        ? 'Den skickade fullmakten saknar kunduppgifter och skickas inte.'
        : '';
    $fields = jeab_ellevio_fullmakt_fields($row);
    $bundle = jeab_ellevio_fullmakt_party_bundle($row, $fields);
    $message = jeab_ellevio_fullmakt_invite_message($bundle, 'https://flowstack.example.test/fullmakt/s/…');
    $owners = [];
    $huvud = trim((string) ($bundle['parter']['huvudsokande']['namn'] ?? ''));
    if ($huvud !== '') {
        $owners[] = ['roll' => 'huvudsokande', 'namn' => $huvud];
    }
    foreach ((array) ($bundle['parter']['medsokande'] ?? []) as $med) {
        if (!is_array($med)) {
            continue;
        }
        $name = trim((string) ($med['namn'] ?? ''));
        if ($name === '') {
            continue;
        }
        $owners[] = ['roll' => 'medsokande', 'namn' => $name];
    }
    $id = (int) ($row['reminder_id'] ?? $row['id'] ?? 0);
    return [
        'order_id' => $id > 0 ? (string) $id : '',
        'address' => trim((string) ($fields['anlaggningsadress'] ?? $row['huvudsokande_address'] ?? $row['address'] ?? '')),
        'recipient' => trim((string) ($bundle['invite_epost'] ?? '')),
        'owners' => $owners,
        'subject' => $message['subject'],
        'text' => $message['text'],
        'warning' => $warning,
        'sent' => false,
    ];
}

/**
 * Hook-åtgärd ordrar.fullmakt_preview. dry ignoreras: inget mejl och ingen webhook.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_ellevio_fullmakt_preview_dispatch(JeabStore $store, array $input): array
{
    unset($store);
    $id = (int) ($input['id'] ?? $input['order_id'] ?? 0);
    $address = trim((string) ($input['address'] ?? $input['q'] ?? ''));
    $rows = [];
    if ($id > 0 && function_exists('jeab_purchase_get')) {
        $one = jeab_purchase_get($id);
        if (is_array($one)) {
            $rows[] = $one;
        }
    } elseif ($address !== '' && function_exists('jeab_purchase_all')) {
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row) || !jeab_ellevio_fullmakt_address_match($row, $address)) {
                continue;
            }
            $rows[] = $row;
            if (count($rows) >= 20) {
                break;
            }
        }
    } else {
        return [
            'ok' => false,
            'error' => 'Ange id eller address.',
            'action' => 'ordrar.fullmakt_preview',
            'dry' => 1,
            'sent' => false,
            'status' => 400,
        ];
    }
    $previews = [];
    foreach ($rows as $row) {
        if (!jeab_ellevio_fullmakt_applies($row)) {
            continue;
        }
        $previews[] = jeab_ellevio_fullmakt_preview_row($row);
    }
    return [
        'ok' => true,
        'action' => 'ordrar.fullmakt_preview',
        'dry' => 1,
        'sent' => false,
        'count' => count($previews),
        'previews' => $previews,
    ];
}

function jeab_ellevio_fullmakt_new_form_html(string $actionUrl, int $openId, string $from, bool $v3): string
{
    $ask = 'Skicka en ny fullmakt till kunden? Den gamla fullmakten och länken ligger kvar.';
    $onclick = 'return confirm(' . json_encode($ask, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) . ');';
    $hidden = jeab_csrf_field()
        . '<input type="hidden" name="action" value="ordrar.fullmakt_ny">'
        . '<input type="hidden" name="confirm" value="1">'
        . '<input type="hidden" name="from" value="' . e($from) . '">'
        . '<input type="hidden" name="id" value="' . $openId . '">';
    if ($v3) {
        return '<form id="v3-f-fullmakt-ny" method="post" action="' . e($actionUrl) . '" data-v3-form="fullmakt">'
            . $hidden
            . '<button type="submit" class="v3-btn is-sec" data-v3-sec="fullmakt" data-working="Skickar…" aria-busy="false" onclick="' . e($onclick) . '">Skicka ny fullmakt</button>'
            . '<span class="v3-help">Startar en ny fullmakt med orderns ägare. Kunden väljer vem som signerar. Den gamla länken ligger kvar.</span>'
            . '</form>';
    }
    return '<form data-js="ordrar-ajax" method="post" action="' . e($actionUrl) . '">'
        . $hidden
        . '<button class="ordrar-secondary" type="submit" onclick="' . e($onclick) . '">Skicka ny fullmakt</button>'
        . '<p class="ordrar-help">Startar en ny fullmakt med orderns ägare. Kunden väljer vem som signerar. Den gamla länken ligger kvar.</p>'
        . '</form>';
}
