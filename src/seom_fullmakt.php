<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
require_once __DIR__ . '/snapshots.php';
jeab_deny_if_direct(__FILE__);

const JEAB_SEOM_SIGNER_EMAIL = 'demo@example.test';

function jeab_seom_fullmakt_applies(array $row): bool
{
    $saved = trim((string) ($row['natbolag'] ?? ''));
    if ($saved !== '') {
        return $saved === 'SEOM';
    }
    if (!function_exists('jeab_ordrar_natbolag')) {
        return false;
    }
    return jeab_ordrar_natbolag($row) === 'SEOM';
}

/**
 * Kundutskick. Av om inget av miljövariabeln SEOM_CUSTOMER_SEND eller
 * $GLOBALS['_JEAB_SEOM_CUSTOMER_SEND'] uttryckligen är på.
 */
function jeab_seom_customer_send_enabled(): bool
{
    if (array_key_exists('_JEAB_SEOM_CUSTOMER_SEND', $GLOBALS)) {
        return (bool) $GLOBALS['_JEAB_SEOM_CUSTOMER_SEND'];
    }
    $env = getenv('SEOM_CUSTOMER_SEND');
    if ($env === false || $env === '') {
        $env = (string) ($_ENV['SEOM_CUSTOMER_SEND'] ?? '');
    }
    $env = strtolower(trim((string) $env));
    return in_array($env, ['1', 'true', 'on', 'yes'], true);
}

/** @return array{foretag:string,adress:string,postnummer:string,ort:string,telefon:string,orgnr:string,handlaggare:string,epost:string} */
function jeab_seom_installer(): array
{
    return [
        'foretag' => 'Demo Energi Järfälla',
        'adress' => 'Dimvägen 38',
        'postnummer' => '17770',
        'ort' => 'Järfälla',
        'telefon' => '08-0000002',
        'orgnr' => '559999-0000',
        'handlaggare' => 'Demo Säljare',
        'epost' => JEAB_SEOM_SIGNER_EMAIL,
    ];
}

function jeab_seom_scrub(string $text): string
{
    if (function_exists('jeab_ordrar_scrub_pnr')) {
        $scrubbed = jeab_ordrar_scrub_pnr($text);
        $text = is_string($scrubbed) ? $scrubbed : $text;
    }
    if (function_exists('jeab_log_mask_text')) {
        return jeab_log_mask_text($text);
    }
    return $text;
}

function jeab_seom_log(string $message): void
{
    $safe = jeab_seom_scrub($message);
    if ($safe === '') {
        $safe = 'SEOM-blanketten kunde inte skickas.';
    }
    if (function_exists('jeab_log_app_error')) {
        jeab_log_app_error('seom', $safe);
    }
}

function jeab_seom_anlaggnings_id(array $row): string
{
    $raw = (string) ($row['anlaggnings_id'] ?? '');
    if (function_exists('jeab_ordrar_anlaggnings_id_digits')) {
        return jeab_ordrar_anlaggnings_id_digits($raw);
    }
    return preg_replace('/\D+/', '', $raw) ?? '';
}

function jeab_seom_require_anlaggnings_id(array $row): string
{
    $id = jeab_seom_anlaggnings_id($row);
    if ($id === '') {
        throw new InvalidArgumentException('Anläggnings-id krävs för SEOM-föranmälan.');
    }
    if (strlen($id) !== 18) {
        throw new InvalidArgumentException('Anläggnings-id ska vara 18 siffror.');
    }
    return $id;
}

/** @return array{adress:string,postnummer:string,ort:string} */
function jeab_seom_split_address(string $address): array
{
    $address = trim((string) (preg_replace('/\s+/u', ' ', $address) ?? $address));
    if (preg_match('/^(.*?)\s*,?\s*(\d{3}\s?\d{2})\s+(\S.*)$/u', $address, $m) === 1) {
        $digits = preg_replace('/\D+/', '', $m[2]) ?? '';
        $post = strlen($digits) === 5 ? (substr($digits, 0, 3) . ' ' . substr($digits, 3)) : trim($m[2]);
        return [
            'adress' => trim($m[1], " ,"),
            'postnummer' => $post,
            'ort' => trim($m[3]),
        ];
    }
    return ['adress' => $address, 'postnummer' => '', 'ort' => ''];
}

function jeab_seom_order_id(array $row): int
{
    $id = (int) ($row['reminder_id'] ?? 0);
    if ($id <= 0) {
        $id = (int) ($row['id'] ?? 0);
    }
    return $id;
}

function jeab_seom_postnummer(array $row, string $fromAddress): string
{
    $raw = trim((string) ($row['postnummer'] ?? $row['postnr'] ?? ''));
    if ($raw === '') {
        $raw = $fromAddress;
    }
    if (function_exists('jeab_ordrar_format_postnummer')) {
        $formatted = jeab_ordrar_format_postnummer($raw);
        if ($formatted !== '') {
            return $formatted;
        }
    }
    return $fromAddress;
}

function jeab_seom_fardig_datum(array $row): string
{
    $manual = trim((string) ($row['install_date'] ?? ''));
    if ($manual !== '') {
        return substr($manual, 0, 10);
    }
    $steps = is_array($row['steps'] ?? null) ? $row['steps'] : [];
    $booked = trim((string) ($steps['bokad_tid'] ?? $row['bokad_tid'] ?? ''));
    if ($booked !== '') {
        return substr($booked, 0, 10);
    }
    return '';
}

/**
 * Fält till blanketten. Personnumret ligger bara här, aldrig i hook eller logg.
 *
 * @return array<string, mixed>
 */
function jeab_seom_fullmakt_fields(array $row): array
{
    $newVersion = !empty($row['_snapshot_new_version']) || !empty($GLOBALS['_JEAB_SNAPSHOT_NEW_VERSION']);
    $frozen = !$newVersion && function_exists('jeab_snapshots_should_freeze') && jeab_snapshots_should_freeze($row, 'fullmakt');
    if ($frozen && function_exists('jeab_snapshots_frozen_party')) {
        $row = jeab_snapshots_frozen_party($row, 'fullmakt');
    } elseif ($newVersion && function_exists('jeab_contacts_sole_recipient_apply')) {
        $row = jeab_contacts_sole_recipient_apply($row);
    }
    $name = trim((string) ($row['huvudsokande'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($row['name'] ?? ''));
    }
    $address = trim((string) ($row['huvudsokande_address'] ?? ''));
    if ($address === '') {
        $address = trim((string) ($row['address'] ?? ''));
    }
    $split = jeab_seom_split_address($address);
    if (!$frozen) {
        $street = trim((string) ($row['gata'] ?? ''));
        if ($street !== '') {
            $split['adress'] = $street;
        }
        $ort = trim((string) ($row['ort'] ?? ''));
        if ($ort !== '') {
            $split['ort'] = $ort;
        }
        $split['postnummer'] = jeab_seom_postnummer($row, $split['postnummer']);
    }
    if ($frozen && function_exists('jeab_snapshots_row_pnr')) {
        $rawFrozen = jeab_snapshots_row_pnr($row);
        $pnr = function_exists('jeab_ordrar_format_pnr') ? jeab_ordrar_format_pnr($rawFrozen) : $rawFrozen;
    } else {
        $pnr = function_exists('jeab_ordrar_canonical_personnummer')
            ? jeab_ordrar_canonical_personnummer($row)
            : '';
    }
    if ($pnr === '') {
        $rawPnr = trim((string) ($row['personnummer'] ?? ''));
        $pnr = function_exists('jeab_ordrar_format_pnr') ? jeab_ordrar_format_pnr($rawPnr) : $rawPnr;
    }
    $kind = function_exists('jeab_ordrar_ui_kind')
        ? jeab_ordrar_ui_kind($row['kind'] ?? '')
        : trim((string) ($row['kind'] ?? ''));
    $id = jeab_seom_order_id($row);
    $referens = trim((string) ($row['kundreferens'] ?? $row['referens'] ?? ''));
    if ($referens === '' && $id > 0) {
        $referens = 'JEAB-' . $id;
    }
    $offert = trim((string) ($row['offertnummer'] ?? $row['offert'] ?? ''));
    if ($offert === '') {
        $offert = trim((string) ($row['fortnox_order'] ?? ''));
    }
    $lgh = trim((string) ($row['lgh'] ?? $row['lagenhetsnummer'] ?? $row['apartment'] ?? ''));
    $matar = trim((string) ($row['matarnummer'] ?? $row['matar_nr'] ?? $row['meter_number'] ?? ''));
    $fast = trim((string) ($row['fastighetsbeteckning'] ?? ''));
    $phone = trim((string) ($row['phone'] ?? ''));
    $email = trim((string) ($row['email'] ?? ''));
    if (function_exists('jeab_ellevio_fullmakt_contact_channel')) {
        $channel = jeab_ellevio_fullmakt_contact_channel($row);
        if ($phone === '' && $channel['phone'] !== '') {
            $phone = $channel['phone'];
        }
        if ($email === '' && $channel['email'] !== '') {
            $email = $channel['email'];
        }
    }
    $party = [
        'namn' => $name,
        'personnummer' => $pnr,
        'adress' => $split['adress'],
        'postnummer' => $split['postnummer'],
        'ort' => $split['ort'],
        'telefon' => $phone,
        'mejl' => $email,
    ];
    return [
        'referensnummer' => $referens,
        'offertnummer' => $offert,
        'produktion_typ_a' => $kind === '' || $kind === 'sol' || $kind === 'batteri',
        'adress' => $split['adress'],
        'lgh' => $lgh,
        'anlaggnings_id' => jeab_seom_anlaggnings_id($row),
        'postnummer' => $split['postnummer'],
        'ort' => $split['ort'],
        'matarnummer' => $matar,
        'fastighetsbeteckning' => $fast,
        'kund' => $party,
        'fastighetsagare' => $party,
        'ager_fastighet' => 'ja',
        'kundanlaggning' => 'Enbostadshus',
        'servissakring' => '25 A',
        'trefas' => '1',
        'fardig_datum' => jeab_seom_fardig_datum($row),
        'installator' => jeab_seom_installer(),
        'order_id' => $id,
        'kind' => $kind,
    ];
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $fields
 * @return array<string, mixed>
 */
function jeab_seom_fullmakt_capture(array $row, array $fields): array
{
    if (!function_exists('jeab_snapshots_capture_sent')) {
        return $row;
    }
    $kund = is_array($fields['kund'] ?? null) ? $fields['kund'] : [];
    $address = trim(implode(' ', array_filter([
        trim((string) ($kund['adress'] ?? '')),
        trim((string) ($kund['postnummer'] ?? '')),
        trim((string) ($kund['ort'] ?? '')),
    ], static fn (string $part): bool => $part !== '')));
    $party = [
        'name' => (string) ($kund['namn'] ?? ''),
        'email' => (string) ($kund['mejl'] ?? ''),
        'phone' => (string) ($kund['telefon'] ?? ''),
        'address' => $address,
        'owners' => [[
            'name' => (string) ($kund['namn'] ?? ''),
            'email' => (string) ($kund['mejl'] ?? ''),
            'phone' => (string) ($kund['telefon'] ?? ''),
            'address' => $address,
        ]],
    ];

    return jeab_snapshots_capture_sent($row, 'fullmakt', $party, 'send');
}

function jeab_seom_fullmakt_pdf(array $fields): string
{
    if (!class_exists('SeomFullmaktPdf', false)) {
        require_once __DIR__ . '/seom_pdf.php';
    }
    $pdf = new SeomFullmaktPdf($fields);
    return $pdf->render();
}

function jeab_seom_fullmakt_status(array $row): string
{
    if (!jeab_seom_fullmakt_applies($row)) {
        return 'behovs_ej';
    }
    $stored = trim((string) ($row['seom_fullmakt_status'] ?? ''));
    if ($stored === 'kund_signerad' || trim((string) ($row['seom_customer_signed_at'] ?? '')) !== '') {
        return 'kund_signerad';
    }
    if ($stored === 'kund_skickad' || trim((string) ($row['seom_customer_sent_at'] ?? '')) !== '') {
        return 'kund_skickad';
    }
    if ($stored === 'sam_skickad' || trim((string) ($row['seom_sam_sent_at'] ?? '')) !== '') {
        return 'sam_skickad';
    }
    return 'ej_paborjad';
}

/**
 * Status utan personnummer, namn, telefon eller mejl.
 *
 * @return array<string, mixed>
 */
function jeab_seom_hook_payload(string $event, array $row): array
{
    if ($event !== 'seom.fullmakt_status' && $event !== 'seom.customer_signed') {
        throw new InvalidArgumentException('Okänd SEOM-hook.');
    }
    return [
        'event' => $event,
        'order_id' => (string) jeab_seom_order_id($row),
        'natbolag' => 'SEOM',
        'status' => jeab_seom_fullmakt_status($row),
        'anlaggnings_id' => jeab_seom_anlaggnings_id($row),
        'sam_sent_at' => trim((string) ($row['seom_sam_sent_at'] ?? '')),
        'customer_sent_at' => trim((string) ($row['seom_customer_sent_at'] ?? '')),
        'customer_signed_at' => trim((string) ($row['seom_customer_signed_at'] ?? '')),
        'customer_send_enabled' => jeab_seom_customer_send_enabled(),
        'at' => function_exists('jeab_now') ? jeab_now() : date('c'),
    ];
}

/** @return array<string, mixed> */
function jeab_seom_record_hook(string $event, array $row): array
{
    $payload = jeab_seom_hook_payload($event, $row);
    if (!isset($GLOBALS['_JEAB_SEOM_HOOKS']) || !is_array($GLOBALS['_JEAB_SEOM_HOOKS'])) {
        $GLOBALS['_JEAB_SEOM_HOOKS'] = [];
    }
    $GLOBALS['_JEAB_SEOM_HOOKS'][] = $payload;
    $hooks = is_array($row['seom_hooks'] ?? null) ? $row['seom_hooks'] : [];
    $hooks[] = $payload;
    if (count($hooks) > 20) {
        $hooks = array_slice($hooks, -20);
    }
    $row['seom_hooks'] = $hooks;
    $row['seom_last_hook'] = $payload;
    return $row;
}

function jeab_seom_now(): string
{
    return function_exists('jeab_now') ? jeab_now() : date('c');
}

/** @return array<string, mixed> */
function jeab_seom_put(array $row): array
{
    $row['updated_at'] = jeab_seom_now();
    if (jeab_seom_order_id($row) > 0 && function_exists('jeab_purchase_put')) {
        $saved = jeab_purchase_put($row);
        return is_array($saved) ? $saved : $row;
    }
    return $row;
}

/**
 * Skapar PDF och mejlar den till Sam. Kunden får inget mejl här.
 *
 * @return array<string, mixed>
 */
function jeab_seom_fullmakt_start(array $row, bool $force = false): array
{
    if (!jeab_seom_fullmakt_applies($row)) {
        throw new InvalidArgumentException('Ordern är inte SEOM.');
    }
    if (!$force && trim((string) ($row['seom_sam_sent_at'] ?? '')) !== '') {
        return $row;
    }
    $aid = jeab_seom_require_anlaggnings_id($row);
    $row['anlaggnings_id'] = $aid;
    $fields = jeab_seom_fullmakt_fields($row);
    $pdf = jeab_seom_fullmakt_pdf($fields);
    if ($pdf === '' || !str_starts_with($pdf, '%PDF')) {
        throw new InvalidArgumentException('SEOM-blanketten kunde inte skapas.');
    }
    $id = jeab_seom_order_id($row);
    $sent = jeab_seom_mail_pdf(
        JEAB_SEOM_SIGNER_EMAIL,
        'SEOM föranmälan ' . ($id > 0 ? 'JEAB-' . $id : ''),
        "Sollentuna Elnät-blanketten är ifylld från ordern och bifogad.\nSignera som installatör. Kunden får blanketten bara om du skickar den manuellt.",
        $pdf,
        $id
    );
    if (empty($sent['ok'])) {
        $err = jeab_seom_scrub(trim((string) ($sent['error'] ?? '')) ?: 'Mejlet till Sam kunde inte skickas.');
        $row['seom_fullmakt_error'] = $err;
        $row['seom_fullmakt_status'] = 'ej_paborjad';
        jeab_seom_log($err);
        jeab_seom_put($row);
        throw new InvalidArgumentException($err !== '' ? $err : 'Mejlet till Sam kunde inte skickas.');
    }
    $now = jeab_seom_now();
    $row['seom_sam_sent_at'] = $now;
    $row['seom_mail_path'] = trim((string) ($sent['path'] ?? ''));
    $row['seom_fullmakt_error'] = '';
    $row['seom_fullmakt_status'] = 'sam_skickad';
    $row = jeab_seom_fullmakt_capture($row, $fields);
    $row['foranmalan_saved'] = true;
    if (!is_array($row['steps'] ?? null)) {
        $row['steps'] = [];
    }
    $row = jeab_seom_record_hook('seom.fullmakt_status', $row);
    return jeab_seom_put($row);
}

/**
 * Nytt mejl till Sam. Aldrig till kunden.
 *
 * @return array<string, mixed>
 */
function jeab_seom_fullmakt_resend(array $row, array $input = []): array
{
    if (trim((string) ($row['seom_sam_sent_at'] ?? '')) === '') {
        throw new InvalidArgumentException('SEOM-blanketten är inte skickad till Sam än.');
    }
    if (!empty($row['_snapshot_new_version'])
        || !empty($GLOBALS['_JEAB_SNAPSHOT_NEW_VERSION'])
        || (function_exists('jeab_snapshots_wants_new') && jeab_snapshots_wants_new($input))) {
        $row['_snapshot_new_version'] = true;
    }
    $last = trim((string) ($row['seom_sam_resent_at'] ?? $row['seom_sam_sent_at'] ?? ''));
    if ($last !== '') {
        $then = strtotime($last);
        if ($then !== false && (time() - $then) < 60) {
            throw new InvalidArgumentException('Blanketten skickades nyss. Vänta en minut innan du försöker igen.');
        }
    }
    $row = jeab_seom_fullmakt_start($row, true);
    $row['seom_sam_resent_at'] = jeab_seom_now();
    return jeab_seom_put($row);
}

/**
 * Manuellt kundutskick. Kräver flaggan SEOM_CUSTOMER_SEND. Anropas inte från starten.
 *
 * @return array<string, mixed>
 */
function jeab_seom_fullmakt_send_customer(array $row): array
{
    if (!jeab_seom_customer_send_enabled()) {
        throw new InvalidArgumentException('Utskick till kunden är avstängt (SEOM_CUSTOMER_SEND).');
    }
    if (!jeab_seom_fullmakt_applies($row)) {
        throw new InvalidArgumentException('Ordern är inte SEOM.');
    }
    if (trim((string) ($row['seom_sam_sent_at'] ?? '')) === '') {
        throw new InvalidArgumentException('Blanketten ska först till Sam.');
    }
    $email = trim((string) ($row['email'] ?? ''));
    if ($email === '' && function_exists('jeab_ellevio_fullmakt_contact_channel')) {
        $channel = jeab_ellevio_fullmakt_contact_channel($row);
        if ($channel['email'] !== '') {
            $email = $channel['email'];
        }
    }
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Kontaktens e-post saknas. Lägg till e-post på kontakten och skicka igen.');
    }
    $aid = jeab_seom_require_anlaggnings_id($row);
    $row['anlaggnings_id'] = $aid;
    $fields = jeab_seom_fullmakt_fields($row);
    $pdf = jeab_seom_fullmakt_pdf($fields);
    $id = jeab_seom_order_id($row);
    $sent = jeab_seom_mail_pdf(
        $email,
        'Föranmälan till Sollentuna Elnät ' . ($id > 0 ? 'JEAB-' . $id : ''),
        "Bifogat finns föranmälan och installationsmedgivande för din elanläggning hos Sollentuna Elnät.\nSkriv under på kundens rad och skicka tillbaka blanketten.",
        $pdf,
        $id
    );
    if (empty($sent['ok'])) {
        $err = jeab_seom_scrub(trim((string) ($sent['error'] ?? '')) ?: 'Mejlet till kunden kunde inte skickas.');
        $row['seom_fullmakt_error'] = $err;
        jeab_seom_log($err);
        jeab_seom_put($row);
        throw new InvalidArgumentException($err !== '' ? $err : 'Mejlet till kunden kunde inte skickas.');
    }
    $row['seom_customer_sent_at'] = jeab_seom_now();
    // Tom stämpel skriver över en signering som orderlagret annars behåller vid sammanslagning.
    $row['seom_customer_signed_at'] = '';
    $row['seom_fullmakt_error'] = '';
    $row['seom_fullmakt_status'] = 'kund_skickad';
    $row = jeab_seom_fullmakt_capture($row, $fields);
    $row = jeab_seom_record_hook('seom.fullmakt_status', $row);
    return jeab_seom_put($row);
}

/**
 * Bokmalen kan senare lämna in ärendet. Här sparas bara att kunden signerat.
 *
 * @return array<string, mixed>
 */
function jeab_seom_fullmakt_mark_customer_signed(array $row): array
{
    if (!jeab_seom_fullmakt_applies($row)) {
        throw new InvalidArgumentException('Ordern är inte SEOM.');
    }
    $row['seom_customer_signed_at'] = jeab_seom_now();
    $row['seom_fullmakt_status'] = 'kund_signerad';
    $row = jeab_seom_record_hook('seom.customer_signed', $row);
    $row = jeab_seom_record_hook('seom.fullmakt_status', $row);
    return jeab_seom_put($row);
}

/**
 * @return array{ok:bool,path:string,error:?string,id:string}
 */
function jeab_seom_mail_pdf(string $to, string $subject, string $text, string $pdf, int $orderId): array
{
    if (!function_exists('jeab_system_mail_send')) {
        return ['ok' => false, 'path' => '', 'error' => 'Systemmejlet saknas.', 'id' => ''];
    }
    $name = 'seom-foranmalan' . ($orderId > 0 ? '-' . $orderId : '') . '.pdf';
    $sent = jeab_system_mail_send([
        'to' => $to,
        'subject' => $subject,
        'text' => $text,
        'company' => 'jarfalla',
        'job_type' => 'seom-fullmakt',
        'tags' => ['seom', 'fullmakt'],
        'skip_helpscout' => true,
        'attachments' => [[
            'filename' => $name,
            'content_type' => 'application/pdf',
            'content' => $pdf,
        ]],
    ]);
    return [
        'ok' => !empty($sent['ok']),
        'path' => trim((string) ($sent['path'] ?? '')),
        'error' => isset($sent['error']) ? jeab_seom_scrub((string) $sent['error']) : null,
        'id' => trim((string) ($sent['id'] ?? '')),
    ];
}

function jeab_seom_hook_authorized(): bool
{
    return function_exists('jeab_inkorg_hook_authorized') && jeab_inkorg_hook_authorized();
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_seom_fullmakt_status_dispatch(array $input): array
{
    if (!jeab_seom_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $id = (int) ($input['order_id'] ?? $input['id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_ordrar_require')) {
        return ['ok' => false, 'error' => 'order_id saknas.', 'status' => 400];
    }
    try {
        $row = jeab_ordrar_require($id);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => jeab_seom_scrub($e->getMessage() !== '' ? $e->getMessage() : 'Ordern saknas.'), 'status' => 404];
    }
    if (!jeab_seom_fullmakt_applies($row)) {
        return ['ok' => false, 'error' => 'Ordern är inte SEOM.', 'status' => 400];
    }
    return [
        'ok' => true,
        'status' => 200,
        'hook' => jeab_seom_hook_payload('seom.fullmakt_status', $row),
    ];
}

/** @param array<string, mixed> $input @return array<string, mixed> */
function jeab_seom_customer_signed_dispatch(array $input): array
{
    if (!jeab_seom_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $id = (int) ($input['order_id'] ?? $input['id'] ?? 0);
    if ($id <= 0 || !function_exists('jeab_ordrar_require')) {
        return ['ok' => false, 'error' => 'order_id saknas.', 'status' => 400];
    }
    try {
        $row = jeab_ordrar_require($id);
        $row = jeab_seom_fullmakt_mark_customer_signed($row);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => jeab_seom_scrub($e->getMessage() !== '' ? $e->getMessage() : 'Kunde inte spara.'), 'status' => 400];
    }
    return [
        'ok' => true,
        'status' => 200,
        'hook' => jeab_seom_hook_payload('seom.customer_signed', $row),
    ];
}

function jeab_seom_fullmakt_panel_html(array $open, string $action): string
{
    if (!jeab_seom_fullmakt_applies($open)) {
        return '';
    }
    $status = jeab_seom_fullmakt_status($open);
    $flagOn = jeab_seom_customer_send_enabled();
    $sent = trim((string) ($open['seom_sam_sent_at'] ?? '')) !== '';
    $id = jeab_seom_order_id($open);
    $html = '<div class="v3-fullmakt" data-seom-panel>';
    if ($status === 'kund_signerad') {
        $html .= '<p class="v3-help2">Kunden har signerat SEOM-blanketten. Statusen är sparad.</p>';
    } elseif ($status === 'kund_skickad') {
        $html .= '<p class="v3-help2">Blanketten är skickad till kunden. Vi väntar på signering.</p>';
    } elseif ($sent) {
        $html .= '<p class="v3-help2">Blanketten är skickad till Sam för signering.</p>';
    } else {
        $html .= '<p class="v3-help2">SEOM: blanketten mejlas till Sam. Anläggnings-id krävs innan den skapas. Kunden får den inte automatiskt.</p>';
    }
    $err = trim((string) ($open['seom_fullmakt_error'] ?? ''));
    if ($err !== '' && $status === 'ej_paborjad') {
        $html .= '<p class="v3-alert">' . e(jeab_seom_scrub($err)) . '</p>';
    }
    if ($sent) {
        $html .= '<form method="post" action="' . e($action) . '" data-v3-form="seom-resend">'
            . (function_exists('jeab_csrf_field') ? jeab_csrf_field() : '')
            . '<input type="hidden" name="action" value="ordrar.foranmalan_resend">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<label class="v3-help"><input type="checkbox" name="new_version" value="1"> Ny version från kontakten</label>'
            . '<button type="submit" class="v3-btn is-sec">Skicka till Sam igen</button></form>';
        $html .= '<form method="post" action="' . e($action) . '" data-v3-form="seom-customer">'
            . (function_exists('jeab_csrf_field') ? jeab_csrf_field() : '')
            . '<input type="hidden" name="action" value="ordrar.seom_customer_send">'
            . '<input type="hidden" name="id" value="' . $id . '">';
        if ($flagOn) {
            $html .= '<button type="submit" class="v3-btn is-sec">Skicka till kunden</button>';
        } else {
            $html .= '<button type="submit" class="v3-btn is-sec" disabled>Skicka till kunden</button>';
            $html .= '<span class="v3-help is-inline">SEOM_CUSTOMER_SEND är av. Kunden får inget mejl.</span>';
        }
        $html .= '</form>';
    }
    return $html . '</div>';
}
