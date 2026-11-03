<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/shield.php';
jeab_deny_if_direct(__FILE__);

function fullmakt_mail_last_error(?string $set = null): string
{
    static $error = '';
    if ($set !== null) {
        $error = $set;
    }
    return $error;
}

function fullmakt_mail_last_path(?string $set = null): string
{
    static $path = '';
    if ($set !== null) {
        $path = $set;
    }
    return $path;
}

function fullmakt_mail_public_text(string $message): string
{
    $message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[mejl]', $message) ?? $message;
    $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
    if ($message === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($message, 0, 240, 'UTF-8');
    }
    return substr($message, 0, 240);
}

function fullmakt_mail_block_reason(string $to): string
{
    if (getenv('FULLMAKT_DISABLE_MAIL') === '1') {
        return 'E-post är avstängd (FULLMAKT_DISABLE_MAIL).';
    }
    $to = trim($to);
    if ($to === '') {
        return 'Mottagarens e-post saknas.';
    }
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        return 'Mottagarens e-post är ogiltig.';
    }
    return '';
}

function fullmakt_mail_from(): string
{
    $env = getenv('FULLMAKT_MAIL_FROM');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return 'Demo Energi Järfälla <kontakt@jarfalla.example.test>';
}

function fullmakt_staff_email(?array $doc = null): string
{
    if ($doc) {
        $fromDoc = trim((string) ($doc['staff_email'] ?? ''));
        if ($fromDoc !== '' && filter_var($fromDoc, FILTER_VALIDATE_EMAIL)) {
            return $fromDoc;
        }
    }
    $env = getenv('FULLMAKT_STAFF_EMAIL');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return 'kontakt@jarfalla.example.test';
}

function fullmakt_mail_boot(): bool
{
    if (function_exists('jeab_system_mail_send')) {
        return true;
    }
    $boot = dirname(__DIR__, 2) . '/src/bootstrap.php';
    if (!is_file($boot)) {
        return false;
    }
    require_once $boot;
    return function_exists('jeab_system_mail_send');
}

function fullmakt_mail_log(bool $ok, string $reason, string $path = ''): void
{
    if (!function_exists('fullmakt_log')) {
        return;
    }
    $reason = fullmakt_mail_public_text($reason);
    $path = trim($path);
    $line = 'send mailed=' . (int) $ok;
    if ($path !== '') {
        $line .= ' path=' . $path;
    }
    $line .= ' reason=' . ($reason !== '' ? $reason : 'ok');
    fullmakt_log('mail.log', $line);
}

/**
 * @param list<array<string, mixed>> $attachments
 * @return array{ok: bool, path: string, error: ?string, id: string}
 */
function fullmakt_mail_dispatch(string $to, string $subject, string $body, array $attachments, array $extra = []): array
{
    $opts = $extra + [
        'to' => $to,
        'subject' => $subject,
        'text' => $body,
        'tags' => ['fullmakt'],
        'attachments' => $attachments,
    ];
    if (isset($GLOBALS['JEAB_SYSTEM_MAIL']) && is_callable($GLOBALS['JEAB_SYSTEM_MAIL'])) {
        $mocked = ($GLOBALS['JEAB_SYSTEM_MAIL'])($opts);
        if (!is_array($mocked)) {
            return ['ok' => false, 'path' => '', 'error' => 'Systemmejlet svarade fel.', 'id' => ''];
        }
        return [
            'ok' => !empty($mocked['ok']),
            'path' => trim((string) ($mocked['path'] ?? '')),
            'error' => isset($mocked['error']) && is_string($mocked['error']) && $mocked['error'] !== ''
                ? $mocked['error']
                : (!empty($mocked['ok']) ? null : 'Mejlet kunde inte skickas.'),
            'id' => trim((string) ($mocked['id'] ?? '')),
        ];
    }
    if (!fullmakt_mail_boot()) {
        return ['ok' => false, 'path' => '', 'error' => 'Systemmejlet saknas (Help Scout/Graph).', 'id' => ''];
    }
    return jeab_system_mail_send($opts);
}

function fullmakt_send_mail(string $to, string $subject, string $body, ?string $pdfPath = null, ?string $pdfName = null, array $extra = []): bool
{
    $blocked = fullmakt_mail_block_reason($to);
    if ($blocked !== '') {
        fullmakt_mail_last_error($blocked);
        fullmakt_mail_last_path('');
        fullmakt_mail_log(false, $blocked);
        return false;
    }
    $attachments = [];
    if ($pdfPath && is_readable($pdfPath)) {
        $pdf = file_get_contents($pdfPath);
        if ($pdf === false) {
            $reason = 'Bilagan kunde inte läsas.';
            fullmakt_mail_last_error($reason);
            fullmakt_mail_last_path('');
            fullmakt_mail_log(false, $reason);
            return false;
        }
        $attachments[] = [
            'filename' => $pdfName ?: basename($pdfPath),
            'content_type' => 'application/pdf',
            'content' => $pdf,
        ];
    }
    $result = fullmakt_mail_dispatch($to, $subject, $body, $attachments, $extra);
    if (!empty($result['ok'])) {
        $path = trim((string) ($result['path'] ?? ''));
        fullmakt_mail_last_error('');
        fullmakt_mail_last_path($path);
        fullmakt_mail_log(true, 'ok', $path);
        return true;
    }
    $reason = fullmakt_mail_public_text((string) ($result['error'] ?? ''));
    if ($reason === '') {
        $reason = 'Mejlet kunde inte skickas.';
    }
    fullmakt_mail_last_error($reason);
    fullmakt_mail_last_path('');
    fullmakt_mail_log(false, $reason);
    return false;
}

function fullmakt_invite_recipient(array $doc): string
{
    $invite = trim((string) ($doc['invite_epost'] ?? ''));
    if ($invite !== '') {
        return $invite;
    }
    return trim((string) ($doc['epost'] ?? ''));
}

function fullmakt_invite_greeting_name(array $doc): string
{
    $name = trim((string) ($doc['invite_namn'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    return (string) ($doc['namn'] ?? '');
}

/** Tre meningar som alltid ska med i Ellevio-mejlet, första skick och omskick. */
function fullmakt_invite_ellevio_notes(): string
{
    return "Du måste fylla i anläggnings-id. Det står på elmätaren och på elfakturan.\n"
        . "Den som signerar måste vara samma person som står på elfakturan.\n"
        . "I dokumentet kan du välja vem som signerar.\n";
}

function fullmakt_invite_has_ellevio_notes(string $body): bool
{
    return str_contains($body, 'måste fylla i anläggnings-id')
        && str_contains($body, 'samma person som står på elfakturan')
        && str_contains($body, 'välja vem som signerar');
}

function fullmakt_invite_with_ellevio_notes(string $body): string
{
    if (fullmakt_invite_has_ellevio_notes($body)) {
        return $body;
    }
    $notes = fullmakt_invite_ellevio_notes();
    $linkAt = strpos($body, 'Länk:');
    if ($linkAt === false) {
        return rtrim($body) . "\n\n" . $notes;
    }
    return rtrim(substr($body, 0, $linkAt)) . "\n\n" . $notes . "\n" . substr($body, $linkAt);
}

function fullmakt_invite_parts(array $doc, string $url): array
{
    $namn = fullmakt_invite_greeting_name($doc);
    $subject = fullmakt_copy($doc, 'invite_subject');
    $body = fullmakt_copy($doc, 'invite_body');
    $body = strtr($body, [
        '{namn}' => $namn,
        '{url}' => $url,
    ]);
    $ellevio = function_exists('fullmakt_anlaggnings_id_required')
        ? fullmakt_anlaggnings_id_required($doc)
        : (($doc['type'] ?? 'ellevio') !== 'overlay');
    if ($ellevio) {
        $body = fullmakt_invite_with_ellevio_notes($body);
    }
    if (strpos($body, $url) === false) {
        $body = rtrim($body) . "\nLänk: " . $url . "\n";
    }
    return [$subject, $body];
}

function fullmakt_send_invite(array $doc, string $url): bool
{
    $to = fullmakt_invite_recipient($doc);
    if ($to === '') {
        $reason = fullmakt_invite_missing_email_text();
        fullmakt_mail_last_error($reason);
        fullmakt_mail_last_path('');
        fullmakt_mail_log(false, $reason);
        return false;
    }
    [$subject, $body] = fullmakt_invite_parts($doc, $url);
    // Signeringsinbjudan går till kontaktens egen e-post via inkorg/Graph, aldrig Help Scout.
    return fullmakt_send_mail($to, $subject, $body, null, null, ['skip_helpscout' => true]);
}

function fullmakt_invite_missing_email_text(): string
{
    return 'Kontaktens e-post saknas. Lägg till e-post på kontakten och skicka fullmakten igen.';
}

function fullmakt_send_signed_pdf(array $doc): bool
{
    // Signerad PDF mejlas inte till personalen. Listan under Övrigt räcker.
    // Webhooken till Bokmalen ligger kvar i fullmakt_mark_signed och rörs inte här.
    // En redan begärd kundkopia (email_signer) skickas en gång, inte en andra gång till personal.
    if (empty($doc['email_signer'])) {
        fullmakt_mail_last_error('');
        fullmakt_mail_last_path('');
        return false;
    }
    $token = (string) ($doc['token'] ?? '');
    $pdf = fullmakt_signed_pdf_path($token);
    if ($token === '' || !is_readable($pdf)) {
        $reason = 'Signerad PDF saknas.';
        fullmakt_mail_last_error($reason);
        fullmakt_mail_last_path('');
        return false;
    }
    $signer = trim((string) ($doc['epost'] ?? ''));
    if ($signer === '') {
        fullmakt_mail_last_error('');
        fullmakt_mail_last_path('');
        return false;
    }
    $title = fullmakt_copy($doc, 'title');
    return fullmakt_send_mail(
        $signer,
        'Kopia: ' . $title,
        "Här är en kopia av det signerade dokumentet.\n",
        $pdf,
        'fullmakt.pdf'
    );
}
