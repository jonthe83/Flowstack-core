<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/normalize.php';

/**
 * Notiser till slutkund. Läser samma funktioner och filer som utskicken.
 * Sparar bara tidpunkten i notis_sends. Skickar inget och kopierar inga bilagor.
 */

function jeab_notiser_products(): array
{
    return [
        'laddbox' => 'Elbilsladdare',
        'batteri' => 'Batteri',
        'sol' => 'Solceller',
        'service' => 'Service',
    ];
}

function jeab_notiser_companies(): array
{
    return [
        'jarfalla' => 'Järfälla',
        'malmo' => 'Malmö',
    ];
}

/** Filterchips. Sol heter Solpaneler här, kortnamnet på korten är oförändrat. */
function jeab_notiser_product_filters(): array
{
    return [
        'sol' => 'Solpaneler',
        'batteri' => 'Batteri',
        'laddbox' => 'Elbilsladdare',
        'service' => 'Service',
    ];
}

function jeab_notiser_plain(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = (string) preg_replace('/<\/div>/i', "\n", $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
    $text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
    $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

function jeab_notiser_zone(): DateTimeZone
{
    return new DateTimeZone(defined('JEAB_TZ') ? JEAB_TZ : 'Europe/Stockholm');
}

function jeab_notiser_moment(string $raw): ?DateTimeImmutable
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $tz = jeab_notiser_zone();
    if (preg_match('/(?:Z|[+\-]\d{2}:?\d{2})$/', $raw) === 1) {
        try {
            return (new DateTimeImmutable($raw))->setTimezone($tz);
        } catch (Exception) {
            return null;
        }
    }
    $parsed = function_exists('jeab_parse_datetime') ? jeab_parse_datetime($raw) : null;
    if (is_string($parsed) && $parsed !== '') {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $parsed, $tz);
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }
    }
    try {
        return new DateTimeImmutable($raw, $tz);
    } catch (Exception) {
        return null;
    }
}

function jeab_notiser_stamp(?DateTimeImmutable $dt): string
{
    return $dt instanceof DateTimeImmutable ? $dt->setTimezone(jeab_notiser_zone())->format('Y-m-d H:i') : '';
}

/** Tar bort adresser ur en felorsak innan den sparas eller visas. */
function jeab_notiser_safe_why(string $why): string
{
    $why = trim((string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '', $why));
    $why = trim((string) preg_replace('/\s+/u', ' ', $why));
    if (strlen($why) > 180) {
        $why = substr($why, 0, 180);
    }
    return $why;
}

/** @return array<string, string> nyckel => texten som ligger i settings eller sådden, innan Malmö-ifyllnaden */
function jeab_notiser_stored_templates(): array
{
    $seed = function_exists('jeab_auto_quote_seed') ? jeab_auto_quote_seed() : [];
    $stored = [];
    $d = function_exists('jeab_inkorg_json_file') ? jeab_inkorg_json_file('settings.json') : null;
    if (is_array($d) && is_array($d['quote_templates'] ?? null)) {
        $stored = $d['quote_templates'];
    }
    $out = [];
    foreach ($seed as $key => $seedText) {
        $out[(string) $key] = array_key_exists($key, $stored) ? (string) $stored[$key] : (string) $seedText;
    }
    return $out;
}

function jeab_notiser_mail_sender(string $email, string $name): array
{
    $email = function_exists('jeab_norm_email') ? jeab_norm_email($email) : strtolower(trim($email));
    $name = trim($name);
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['label' => '', 'problem' => 'avsändare saknas'];
    }
    $domainOk = str_ends_with($email, '@jarfalla.example.test') || str_ends_with($email, '@malmo.example.test');
    if (!$domainOk) {
        return ['label' => '', 'problem' => 'fel avsändare'];
    }
    return ['label' => ($name !== '' ? $name . ' · ' : '') . $email, 'problem' => ''];
}

function jeab_notiser_sms_sender(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['label' => '', 'problem' => 'avsändare saknas'];
    }
    $cfg = function_exists('jeab_suresms_config') ? jeab_suresms_config() : [];
    $secret = trim((string) ($cfg['password'] ?? ''));
    if ($secret !== '' && hash_equals($secret, $raw)) {
        return ['label' => '', 'problem' => 'fel avsändare'];
    }
    $phone = preg_match('/^\+?[0-9][0-9 \-]{5,18}$/', $raw) === 1;
    $alpha = preg_match('/^[A-Za-zÅÄÖåäö0-9][A-Za-zÅÄÖåäö0-9 .]{0,14}$/u', $raw) === 1;
    if (!$phone && !$alpha) {
        return ['label' => '', 'problem' => 'fel avsändare'];
    }
    return ['label' => $raw, 'problem' => ''];
}

function jeab_notiser_sms_config_from(string $company): string
{
    $cfg = function_exists('jeab_suresms_config') ? jeab_suresms_config() : [];
    $key = $company === 'malmo' ? 'from_malmo' : 'from';
    if (!array_key_exists($key, $cfg)) {
        return '';
    }
    return trim((string) $cfg[$key]);
}

function jeab_notiser_bytes_label(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1, ',', ' ') . ' kB';
    }
    return number_format($bytes / 1048576, 1, ',', ' ') . ' MB';
}

/**
 * Bilaga från filen utskicket läser. Ingen kopia.
 *
 * @return array{key:string,name:string,bytes:int,missing:bool,href:string}
 */
function jeab_notiser_asset_path(string $key): string
{
    if (!str_starts_with($key, 'team_') && function_exists('jeab_auto_pdf_path')) {
        return jeab_auto_pdf_path($key);
    }
    if (function_exists('jeab_auto_asset_file')) {
        return jeab_auto_asset_file($key);
    }

    return jeab_notiser_disk_path($key);
}

function jeab_notiser_asset(string $key, string $name): array
{
    $sqlBytes = jeab_notiser_quote_pdf_bytes($key);
    if (is_string($sqlBytes)) {
        return [
            'key' => $key,
            'name' => $name,
            'bytes' => strlen($sqlBytes),
            'missing' => $sqlBytes === '',
            'href' => $sqlBytes === '' ? '' : jeab_url('index.php?p=notiser&fil=' . rawurlencode($key)),
        ];
    }
    $path = jeab_notiser_asset_path($key);
    $exists = $path !== '' && is_file($path);
    $bytes = $exists ? (int) filesize($path) : 0;
    $emptyImage = str_starts_with($key, 'team_') && $bytes <= 0;
    $missing = !$exists || $emptyImage;
    return [
        'key' => $key,
        'name' => $name,
        'bytes' => $bytes,
        'missing' => $missing,
        'href' => $missing ? '' : jeab_url('index.php?p=notiser&fil=' . rawurlencode($key)),
    ];
}

/** Kända bilagor. Används när inkorg_auto inte är laddad (filhämtning). */
function jeab_notiser_known_file_keys(): array
{
    return [
        'laddbox_jarfalla',
        'batteri_jarfalla',
        'laddbox_malmo',
        'batteri_malmo',
        'sol_jarfalla',
        'sol_malmo',
        'team_jarfalla',
        'team_malmo',
    ];
}

function jeab_notiser_disk_name(string $key): string
{
    return match ($key) {
        'laddbox_jarfalla' => 'Elbilsladdare-offert.pdf',
        'batteri_jarfalla' => 'Batteri-offert.pdf',
        'laddbox_malmo' => 'Malmo-Easee-offert.pdf',
        'batteri_malmo' => 'Malmo-Batteri-offert.pdf',
        'sol_malmo' => 'Malmo-Solceller-offert.pdf',
        'sol_jarfalla' => 'Solceller offert 2608 Demo Energi Järfälla.pdf',
        'team_malmo' => 'team-malmo.jpg',
        'team_jarfalla' => 'team-jarfalla.jpg',
        default => 'offert.pdf',
    };
}

/** Samma sökväg som utskicket, utan att ladda inkorg_auto. */
function jeab_notiser_disk_path(string $key): string
{
    $root = defined('JEAB_ROOT') ? JEAB_ROOT : dirname(__DIR__);
    if ($key === 'batteri_jarfalla') {
        return $root . '/assets/offert/Batteri-offert.pdf';
    }
    if ($key === 'batteri_malmo') {
        return $root . '/assets/offert/Malmo-Batteri-offert.pdf';
    }
    if ($key === 'team_jarfalla' || $key === 'team_malmo') {
        $dir = function_exists('jeab_data_dir') ? jeab_data_dir() : $root . '/data';

        return $dir . '/inkorg/offert/team-' . ($key === 'team_malmo' ? 'malmo' : 'jarfalla') . '.jpg';
    }
    $safe = preg_replace('/[^a-z_]/', '', $key) ?? '';
    $dir = function_exists('jeab_data_dir') ? jeab_data_dir() : $root . '/data';

    return $dir . '/inkorg/offert/' . $safe . '.pdf';
}

function jeab_notiser_file_key(string $key): string
{
    $key = trim($key);
    if ($key === '') {
        return '';
    }
    $known = function_exists('jeab_auto_asset_keys') ? jeab_auto_asset_keys() : jeab_notiser_known_file_keys();
    if (!in_array($key, $known, true)) {
        return '';
    }

    return $key;
}

function jeab_notiser_file_miss(): void
{
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
    }
    echo "Bilagan finns inte.\n";
}

function jeab_notiser_file_emit(string $key, string $bytes): void
{
    $name = jeab_notiser_pdf_filename($key, $bytes);
    if ($name === '') {
        $name = str_starts_with($key, 'team_')
            ? jeab_notiser_disk_name($key)
            : (function_exists('jeab_auto_pdf_name') ? jeab_auto_pdf_name($key) : jeab_notiser_disk_name($key));
    }
    $type = str_ends_with(strtolower($name), '.jpg') ? 'image/jpeg' : 'application/pdf';
    if (!headers_sent()) {
        header('Content-Type: ' . $type);
        header('Content-Length: ' . (string) strlen($bytes));
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
    }
    echo $bytes;
}

function jeab_notiser_send_file(string $key): void
{
    try {
        $key = jeab_notiser_file_key($key);
        if ($key === '') {
            jeab_notiser_file_miss();

            return;
        }
        $sqlBytes = jeab_notiser_quote_pdf_bytes($key);
        if (is_string($sqlBytes) && $sqlBytes !== '') {
            jeab_notiser_file_emit($key, $sqlBytes);

            return;
        }
        $path = jeab_notiser_asset_path($key);
        if ($path === '' || !is_file($path)) {
            $fallback = jeab_notiser_disk_path($key);
            $bundled = function_exists('jeab_auto_bundled_pdf') ? jeab_auto_bundled_pdf($key) : '';
            if ($fallback !== '' && $fallback !== $bundled) {
                $path = $fallback;
            }
        }
        $size = $path !== '' && is_file($path) ? (int) filesize($path) : 0;
        $emptyImage = str_starts_with($key, 'team_') && $size <= 0;
        if ($path === '' || !is_file($path) || $emptyImage) {
            jeab_notiser_file_miss();

            return;
        }
        $name = jeab_notiser_pdf_filename($key, (string) file_get_contents($path));
        if ($name === '') {
            $name = str_starts_with($key, 'team_')
                ? jeab_notiser_disk_name($key)
                : (function_exists('jeab_auto_pdf_name') ? jeab_auto_pdf_name($key) : jeab_notiser_disk_name($key));
        }
        $type = str_ends_with(strtolower($name), '.jpg') ? 'image/jpeg' : 'application/pdf';
        if (!headers_sent()) {
            header('Content-Type: ' . $type);
            header('Content-Length: ' . (string) $size);
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
        }
        readfile($path);
    } catch (Throwable $e) {
        error_log('notiser file ' . $e::class);
        jeab_notiser_file_miss();
    }
}

function jeab_notiser_blank(string $id, string $name, string $channel, string $company, string $product, string $stage): array
{
    $companies = jeab_notiser_companies();
    $products = jeab_notiser_products();
    return [
        'id' => $id,
        'name' => $name,
        'channel' => $channel,
        'company' => $company,
        'company_label' => $companies[$company] ?? '',
        'product' => $product,
        'product_label' => $products[$product] ?? '',
        'stage' => $stage,
        'trigger' => '',
        'sender' => '',
        'body' => '',
        'source' => '',
        'edit' => '',
        'attachments' => [],
        'problems' => [],
        'last_used' => '',
        'sends_30d' => 0,
        'status' => 'Av',
        'problem' => '',
    ];
}

function jeab_notiser_quote_rows(): array
{
    if (!function_exists('jeab_auto_quote_seed') || !function_exists('jeab_auto_settings')) {
        return [];
    }
    $set = jeab_auto_settings();
    $used = (array) ($set['templates'] ?? []);
    $stored = jeab_notiser_stored_templates();
    $rows = [];
    foreach (jeab_auto_quote_seed() as $key => $unused) {
        $key = (string) $key;
        $parts = explode('_', $key, 2);
        $offer = (string) ($parts[0] ?? '');
        $company = (string) ($parts[1] ?? '');
        if ($offer === '' || $company === '') {
            continue;
        }
        $raw = trim((string) ($used[$key] ?? ''));
        $storedRaw = trim((string) ($stored[$key] ?? ''));
        if ($key === 'sol_jarfalla' && function_exists('jeab_auto_sol_jarfalla_placeholder') && jeab_auto_sol_jarfalla_placeholder($raw)) {
            $rows[] = jeab_notiser_quote_row($key, $offer, $company, '', 'mall saknas', 'quote_templates.' . $key, 'redigeras här');
            continue;
        }
        if ($key === 'sol_jarfalla' && function_exists('jeab_auto_sol_jarfalla_uses_sent_mall') && jeab_auto_sol_jarfalla_uses_sent_mall($raw)) {
            $rows[] = jeab_notiser_sol_mall_row('kopt', $company);
            $rows[] = jeab_notiser_sol_mall_row('eget', $company);
            continue;
        }
        $filled = $storedRaw === '' && $raw !== '' && str_ends_with($key, '_malmo');
        $source = $filled
            ? 'jeab_auto_malmo_template (samma ifyllnad som utskicket när mallen är tom)'
            : 'inkorg/settings.json quote_templates.' . $key;
        $edit = 'redigeras här';
        $problem = $raw === '' ? 'mall saknas' : '';
        $rows[] = jeab_notiser_quote_row($key, $offer, $company, $raw, $problem, $source, $edit);
    }
    return $rows;
}

function jeab_notiser_sol_mall_row(string $variant, string $company): array
{
    $hello = 'Hej,';
    $html = function_exists('jeab_offert_mall_body') ? jeab_offert_mall_body($variant, $hello) : '';
    $label = $variant === 'kopt' ? 'mall A, köpt lead' : 'mall B, egen lead';
    $row = jeab_notiser_quote_row(
        'sol_jarfalla_' . $variant,
        'sol',
        $company,
        jeab_notiser_plain($html),
        $html === '' ? 'mall saknas' : '',
        'jeab_offert_mall_body',
        'redigeras här'
    );
    $row['id'] = 'offert-sol-jarfalla-' . $variant;
    $row['name'] = 'Offert Solceller Järfälla, ' . $label;
    $row['trigger'] = $variant === 'kopt'
        ? 'Köpintresse solceller, bolag Järfälla, köpt lead (mall A). inbox.send_quote och automatisk offert använder den här texten när solmallen pekar på offertflödet.'
        : 'Köpintresse solceller, bolag Järfälla, egen lead (mall B: Wix, JEAB-formulär eller egen sajt). Samma utskick som mall A.';
    $medal = JEAB_ROOT . '/assets/img/medal-solpanel.png';
    $medalBytes = is_file($medal) ? (int) filesize($medal) : 0;
    $row['attachments'][] = [
        'key' => 'medal',
        'name' => 'medal-solpanel.png',
        'bytes' => $medalBytes,
        'missing' => $medalBytes <= 0,
        'href' => 'https://flowstack.example.test/assets/img/medal-solpanel.png',
    ];
    return $row;
}

function jeab_notiser_quote_row(string $key, string $offer, string $company, string $body, string $problem, string $source, string $edit): array
{
    $products = jeab_notiser_products();
    $companies = jeab_notiser_companies();
    $productLabel = $products[$offer] ?? $offer;
    $companyLabel = $companies[$company] ?? $company;
    $email = function_exists('jeab_gp_owner_email') ? jeab_gp_owner_email($offer, $company) : '';
    $name = function_exists('jeab_auto_from_name') ? jeab_auto_from_name($offer, $company) : '';
    $kimOffer = $company === 'malmo' && in_array($offer, ['batteri', 'laddbox'], true);
    if ($company === 'malmo' && $email === '') {
        $email = function_exists('jeab_gp_person_email') ? jeab_gp_person_email('Kim', 'malmo') : '';
        if ($email === '') {
            $email = 'saljare.syd@malmo.example.test';
        }
    }
    if ($company === 'malmo') {
        $name = 'Kim, Demo Energi Malmö';
    }
    $malmoKimSender = $company === 'malmo' && strcasecmp($email, 'saljare.syd@malmo.example.test') === 0;
    $sender = jeab_notiser_mail_sender($email, $name);
    $row = jeab_notiser_blank('offert-' . $offer . '-' . $company, 'Offert ' . $productLabel . ' ' . $companyLabel, 'mail', $company, $offer, 'Offert');
    $row['body'] = $body;
    $row['source'] = $source;
    $row['edit'] = $edit;
    $row['sender'] = $sender['label'];
    $problems = [];
    if ($problem !== '') {
        $problems[] = $problem;
    }
    if ($sender['problem'] !== '') {
        $problems[] = $sender['problem'];
    } elseif (!$malmoKimSender && (!function_exists('jeab_gp_mail_on') || !jeab_gp_mail_on())) {
        $problems[] = 'avsändare avstängd';
    }
    if ($company === 'malmo' && !$malmoKimSender && function_exists('jeab_inkorg_company_live') && !jeab_inkorg_company_live('malmo')) {
        $problems[] = 'flödet skickar inte';
    }
    $pdfKey = str_starts_with($key, 'sol_jarfalla_') ? 'sol_jarfalla' : $key;
    $pdfName = function_exists('jeab_auto_pdf_name') ? jeab_auto_pdf_name($pdfKey) : 'offert.pdf';
    $loaded = jeab_notiser_quote_pdf_load($pdfKey);
    if ($loaded['name'] !== '') {
        $pdfName = $loaded['name'];
    }
    if (function_exists('jeab_auto_asset_keys') && in_array($pdfKey, jeab_auto_asset_keys(), true)) {
        $pdf = jeab_notiser_asset($pdfKey, $pdfName);
        $row['attachments'][] = $pdf;
        if ($pdf['missing']) {
            $problems[] = 'bilaga saknas';
        }
    }
    $teamKey = 'team_' . ($company === 'malmo' ? 'malmo' : 'jarfalla');
    $solHtml = str_contains($source, 'jeab_offert_mall_body');
    if (!$solHtml && function_exists('jeab_auto_team_image_ok') && jeab_auto_team_image_ok($company)) {
        $row['attachments'][] = jeab_notiser_asset($teamKey, 'team-jarfalla.jpg');
    }
    $live = $company !== 'malmo' || (function_exists('jeab_inkorg_company_live') && jeab_inkorg_company_live('malmo'));
    $row['trigger'] = 'Köpintresse ' . $productLabel . ', bolag ' . $companyLabel
        . '. inbox.send_quote och automatisk offert. Kräver offert-PDF'
        . ($kimOffer
            ? '. Avsändare saljare.syd@malmo.example.test via systemmejlet (Help Scout, sedan Graph).'
            : ($live ? ' och personens brevlåda.' : '. Malmö ligger i Help Scout, så utskicket hålls.'));
    if ($offer === 'sol' && $company === 'malmo') {
        $row['trigger'] = 'Solceller i Malmö hålls (Malmö/Skåne). Avsändare saljare.syd@malmo.example.test. Utskicket sker inte automatiskt.';
    }
    $row['problems'] = array_values(array_unique($problems));
    return $row;
}

function jeab_notiser_sample_booking(string $company, string $job): array
{
    return [
        'id' => 0,
        'customer_name' => '',
        'company' => $company,
        'job_type' => $job,
        'starts_at' => '2026-06-02 09:00:00',
        'ends_at' => '2026-06-02 11:00:00',
        'status' => 'booked',
    ];
}

function jeab_notiser_booking_rows(): array
{
    $rows = [];
    foreach (jeab_notiser_companies() as $company => $companyLabel) {
        foreach (jeab_notiser_products() as $job => $productLabel) {
            $sample = jeab_notiser_sample_booking($company, $job);
            $rows[] = jeab_notiser_confirm_mail_row($sample, $company, $companyLabel, $job, $productLabel);
            $rows[] = jeab_notiser_confirm_sms_row($sample, $company, $companyLabel, $job, $productLabel);
        }
        $rows[] = jeab_notiser_move_mail_row($company, $companyLabel);
    }
    return $rows;
}

function jeab_notiser_move_notice_id(array $row): string
{
    $company = function_exists('jeab_notiser_booking_company') ? jeab_notiser_booking_company($row) : 'jarfalla';

    return 'flytt-' . ($company === 'malmo' ? 'malmo' : 'jarfalla');
}

function jeab_notiser_move_mail_row(string $company, string $companyLabel): array
{
    $id = 'flytt-' . ($company === 'malmo' ? 'malmo' : 'jarfalla');
    $sample = jeab_notiser_sample_booking($company, 'laddbox');
    $letter = function_exists('jeab_email_move_letter') ? jeab_email_move_letter() : '';
    $text = jeab_notiser_text($id, $letter);
    $from = function_exists('jeab_booking_confirm_mail_from') ? jeab_booking_confirm_mail_from($sample) : null;
    if ($from === null || $from === '') {
        $from = function_exists('jeab_system_mail_address') ? jeab_system_mail_address($company) : '';
    }
    $fromName = function_exists('jeab_system_mail_from_name') ? jeab_system_mail_from_name($company) : $companyLabel;
    $sender = jeab_notiser_mail_sender((string) $from, $fromName);
    $row = jeab_notiser_blank($id, 'Flyttad tid ' . $companyLabel, 'mail', $company, '', 'Bokning');
    $row['body'] = $text;
    $row['sender'] = $sender['label'];
    $row['source'] = 'jeab_email_move_text';
    $row['edit'] = 'redigeras här';
    $row['trigger'] = 'Personal flyttar en kundbokning i kalendern efter att bekräftelsen gått, bolag ' . $companyLabel
        . '. Sparningen skriver bara en väntande post. Samma sms-cron som skickar bekräftelserna (varannan timme) tar sluttiden.'
        . ' Flera flyttar mellan körningarna blir ett mejl. Tillbaka till senast meddelad tid skickar inget.'
        . ' Samma avsändare och samma tråd som bekräftelsen. Kräver BOOKING_MOVE_MAIL, av som standard.'
        . ' {hello} och {nar} fylls i vid utskicket.';
    if ($sender['problem'] !== '') {
        $row['problems'][] = $sender['problem'];
    }
    if ($row['body'] === '') {
        $row['problems'][] = 'mall saknas';
    }

    return $row;
}

function jeab_notiser_confirm_mail_row(array $sample, string $company, string $companyLabel, string $job, string $productLabel): array
{
    $letter = match ($job) {
        'laddbox' => function_exists('jeab_email_laddbox_letter') ? jeab_email_laddbox_letter() : '',
        'batteri' => function_exists('jeab_email_batteri_letter') ? jeab_email_batteri_letter() : '',
        'sol' => function_exists('jeab_email_sol_letter') ? jeab_email_sol_letter() : '',
        default => function_exists('jeab_email_service_letter') ? jeab_email_service_letter() : 'Bokad tid: {nar}.',
    };
    $text = jeab_notiser_text('bekraft-' . $job . '-' . $company, $letter);
    $from = function_exists('jeab_booking_confirm_mail_from') ? jeab_booking_confirm_mail_from($sample) : null;
    if ($from === null || $from === '') {
        $from = function_exists('jeab_system_mail_address') ? jeab_system_mail_address($company) : '';
    }
    $fromName = function_exists('jeab_system_mail_from_name') ? jeab_system_mail_from_name($company) : $companyLabel;
    $sender = jeab_notiser_mail_sender((string) $from, $fromName);
    $dedicated = in_array($job, ['laddbox', 'batteri', 'sol'], true);
    $row = jeab_notiser_blank('bekraft-' . $job . '-' . $company, 'Bekräftelse ' . $productLabel . ' ' . $companyLabel, 'mail', $company, $job, 'Bokning');
    $row['body'] = $text;
    $row['sender'] = $sender['label'];
    $row['source'] = $dedicated ? 'jeab_email_' . $job . '_text' : 'jeab_email_text';
    $row['edit'] = 'redigeras här';
    $row['trigger'] = 'Bokning ' . $productLabel . ', bolag ' . $companyLabel
        . '. Mejl en gång när bokningen varit orörd minst två timmar (sms-cron / jeab_booking_notify_customer).'
        . ' {hello} och {nar} fylls i vid utskicket.';
    if (!$dedicated) {
        $row['trigger'] .= ' Ingen egen servicemall: utskicket använder den gemensamma bokningstexten.';
    }
    if ($sender['problem'] !== '') {
        $row['problems'][] = $sender['problem'];
    }
    if ($row['body'] === '') {
        $row['problems'][] = 'mall saknas';
    }
    return $row;
}

function jeab_notiser_confirm_sms_row(array $sample, string $company, string $companyLabel, string $job, string $productLabel): array
{
    $skip = function_exists('jeab_booking_confirm_skips_sms') && jeab_booking_confirm_skips_sms($sample);
    $letter = function_exists('jeab_sms_letter') ? jeab_sms_letter() : '{brand} kommer imorgon{klocka}.';
    $text = jeab_notiser_text('sms-' . $job . '-' . $company, $letter);
    $override = function_exists('jeab_booking_confirm_sms_from') ? (string) (jeab_booking_confirm_sms_from($sample) ?? '') : '';
    $from = $override !== '' ? $override : jeab_notiser_sms_config_from($company);
    $sender = $skip ? ['label' => '', 'problem' => ''] : jeab_notiser_sms_sender($from);
    $row = jeab_notiser_blank('sms-' . $job . '-' . $company, 'SMS ' . $productLabel . ' ' . $companyLabel, 'sms', $company, $job, 'Bokning');
    $row['body'] = $skip ? '' : $text;
    $row['sender'] = $sender['label'];
    $row['source'] = 'jeab_sms_text';
    $row['edit'] = 'redigeras här';
    $row['trigger'] = 'Bokning ' . $productLabel . ', bolag ' . $companyLabel
        . '. SMS «kommer imorgon» när starten ligger inom 18 timmar (sms-cron / jeab_sms_tick).'
        . ' {brand} och {klocka} fylls i vid utskicket.';
    if (!in_array($job, ['laddbox', 'batteri', 'sol'], true)) {
        $row['trigger'] .= ' Skickas inte. Bara batteri, solceller och laddbox får SMS. Service och Outlook-möten utan den taggen hoppas över.';
    }
    $row['editable'] = !$skip;
    if ($skip) {
        $row['problems'][] = 'mall saknas';
        $row['trigger'] = 'Malmö och solceller: jeab_booking_confirm_skips_sms hoppar över SMS. Ingen mall skickas.';
    } elseif ($sender['problem'] !== '') {
        $row['problems'][] = $sender['problem'];
    }
    if (!$skip && $text === '') {
        $row['problems'][] = 'mall saknas';
    }
    return $row;
}

function jeab_notiser_rules_row(): array
{
    $text = function_exists('jeab_auto_svarsregler_text') ? jeab_auto_svarsregler_text() : '';
    $row = jeab_notiser_blank('svarsregler', 'Svarsregler för kort svar i offerten', 'mail', '', '', 'Offert');
    $row['body'] = $text;
    $row['source'] = 'config/svarsregler.php';
    $row['edit'] = 'redigeras här';
    $row['sender'] = 'Samma avsändare som offertmallen';
    $row['trigger'] = 'När kunden ställt en fråga läggs ett kort svar ovanför offertmallen. Reglerna är den text koden läser. De skickas inte som ett eget mejl.';
    if ($text === '') {
        $row['problems'][] = 'mall saknas';
    }
    return [$row];
}

function jeab_notiser_rows(): array
{
    $rows = array_merge(jeab_notiser_quote_rows(), jeab_notiser_rules_row(), jeab_notiser_booking_rows());
    if (function_exists('jeab_demo_mode') && jeab_demo_mode()) {
        return jeab_notiser_demo_rows($rows);
    }
    return $rows;
}

/** Demo: h\u00f6gst fem generiska mallar med exempeltext. */
function jeab_notiser_demo_rows(array $rows): array
{
    $sig = "\n\nVänliga hälsningar\n[Säljare]\nFlowstack\nExempelgatan 1, 123 45 Exempelstad\n08-000 00 00 · demo@example.test";
    $pick = [
        'bekraft-service-jarfalla' => ['Bokningsbekräftelse (exempel)', "Hej [Kundnamn]!\n\nTack för din bokning. Vi kommer [Datum] kl. [Tid] till [Adress].\nBehöver du ändra tiden? Svara på detta mejl." . $sig],
        'sms-service-jarfalla' => ['Påminnelse SMS (exempel)', "Hej [Kundnamn]! Påminnelse: vi kommer [Datum] kl. [Tid]. Frågor? Ring 08-000 00 00. /Flowstack"],
        'offert-batteri-jarfalla' => ['Offert (exempel)', "Hej [Kundnamn]!\n\nHär kommer offerten för [Produkt] till [Adress]. Pris: [Pris] kr inkl. moms.\nOfferten gäller i 30 dagar." . $sig],
        'flytt-jarfalla' => ['Tack / ny tid (exempel)', "Hej [Kundnamn]!\n\nTack för att du hörde av dig. Din nya tid är [Datum] kl. [Tid]." . $sig],
        'offert-batteri-malmo' => ['Uppföljning offert (exempel)', "Hej [Kundnamn]!\n\nVi ville höra om du hunnit titta på offerten för [Produkt]. Svara gärna på detta mejl." . $sig],
    ];
    $out = [];
    foreach ($rows as $row) {
        $id = (string) ($row['id'] ?? '');
        if (!isset($pick[$id])) {
            continue;
        }
        $row['name'] = $pick[$id][0];
        $row['body'] = $pick[$id][1];
        $row['sender'] = ($row['channel'] ?? '') === 'sms' ? 'Flowstack · 08-000 00 00' : 'Flowstack · demo@example.test';
        $row['attachments'] = [];
        $out[] = $row;
    }
    return $out;
}

function jeab_notiser_cut(): DateTimeImmutable
{
    return (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->modify('-30 days');
}

/** @return array<string, list<array{at:DateTimeImmutable,ok:bool,test:bool,why:string}>> */
function jeab_notiser_events(): array
{
    $events = [];
    $add = static function (string $id, ?DateTimeImmutable $at, bool $ok, bool $test = false, string $why = '') use (&$events): void {
        if ($id === '' || !$at instanceof DateTimeImmutable) {
            return;
        }
        $events[$id][] = ['at' => $at, 'ok' => $ok, 'test' => $test, 'why' => $why];
    };
    if (jeab_notiser_view_is_light()) {
        // Sidan summerar sparade rader. Den läser inte mejl, SMS-payload eller kalenderloggen.
        jeab_notiser_history_read($add);
        jeab_notiser_logged_events($add);
    } else {
        jeab_notiser_quote_events($add);
        jeab_notiser_logged_events($add);
        jeab_notiser_booking_events($add);
    }
    return $events;
}

/** Inloggad sidvisning. Tick, migrate, CLI och en skickning får skapa och läsa historik. */
function jeab_notiser_view_is_light(): bool
{
    if (function_exists('jeab_shell_wants_partial') && jeab_shell_wants_partial()) {
        return true;
    }

    return function_exists('jeab_schema_request_skips_ddl') && jeab_schema_request_skips_ddl();
}

/**
 * Offer och lead från ärendets mejl, nyast först.
 * Inkorgen sätter trådens labels från sista meddelandet. Efter ett utskick är det
 * den utgående kopian, och den har varken offer eller lead.
 *
 * @return array{offer:string,lead:string}
 */
function jeab_notiser_thread_face(array $thread): array
{
    $offer = '';
    $lead = '';
    $messages = array_reverse((array) ($thread['messages'] ?? []));
    $messages[] = ['labels' => (array) ($thread['labels'] ?? [])];
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        $labels = (array) ($message['labels'] ?? []);
        if ($offer === '') {
            $offer = trim((string) ($labels['offer'] ?? ''));
        }
        if ($lead === '') {
            $lead = trim((string) ($labels['lead'] ?? ''));
        }
        if ($offer !== '' && $lead !== '') {
            break;
        }
    }
    return ['offer' => $offer, 'lead' => $lead];
}

/** Självtest och testinlämning räknas inte som kundutskick. */
function jeab_notiser_thread_is_test(array $thread): bool
{
    if (str_starts_with((string) ($thread['id'] ?? ''), 'autotest-')) {
        return true;
    }
    if (function_exists('jeab_thread_counts_as_production_lead') && !jeab_thread_counts_as_production_lead($thread)) {
        return true;
    }
    foreach ((array) ($thread['messages'] ?? []) as $message) {
        if (!is_array($message)) {
            continue;
        }
        if (!empty($message['test']) || !empty($message['is_test']) || !empty($message['labels']['is_test'])) {
            return true;
        }
    }
    return false;
}

function jeab_notiser_quote_events(callable $add): void
{
    if (jeab_notiser_view_is_light()) {
        return;
    }
    $GLOBALS['_JEAB_NOTIS_HEAVY'] = (int) ($GLOBALS['_JEAB_NOTIS_HEAVY'] ?? 0) + 1;
    $set = function_exists('jeab_auto_settings') ? jeab_auto_settings() : ['templates' => []];
    $solSplit = function_exists('jeab_auto_sol_jarfalla_uses_sent_mall')
        && jeab_auto_sol_jarfalla_uses_sent_mall(trim((string) (($set['templates']['sol_jarfalla'] ?? ''))));
    if (function_exists('jeab_inkorg_use_sql') && jeab_inkorg_use_sql()) {
        jeab_notiser_quote_events_sql($add, $solSplit);
    } else {
        jeab_notiser_quote_events_threads($add, $solSplit);
    }
    jeab_notiser_inbox_sent_events($add, $solSplit);
    if (function_exists('jeab_offer_malmo_sent_rows')) {
        foreach (jeab_offer_malmo_sent_rows() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $product = (string) ($row['product'] ?? '');
            $at = jeab_notiser_moment((string) ($row['at'] ?? ''));
            if (!$at instanceof DateTimeImmutable) {
                $at = jeab_notiser_moment((string) ($row['date'] ?? ''));
            }
            $add(jeab_notiser_quote_event_id($product, 'malmo', '', false), $at, true, false, '');
        }
    }
}

/**
 * JSON-läge. Läser filerna direkt.
 * jeab_inkorg_threads() tar SQL så fort en PDO finns, även när inkorg_storage är json,
 * och då syns inte anteckningarna i threads.json.
 */
function jeab_notiser_quote_events_threads(callable $add, bool $solSplit): void
{
    if (!function_exists('jeab_inkorg_json_messages') || !function_exists('jeab_inkorg_json_threads')) {
        return;
    }
    try {
        $meta = jeab_inkorg_json_threads();
        $by = [];
        foreach (jeab_inkorg_json_messages() as $message) {
            if (!is_array($message)) {
                continue;
            }
            $tid = (string) ($message['thread_id'] ?? $message['id'] ?? '');
            if ($tid !== '') {
                $by[$tid][] = $message;
            }
        }
        $ids = array_unique(array_merge(array_keys($meta), array_keys($by)));
        foreach ($ids as $tid) {
            $msgs = $by[$tid] ?? [];
            $one = (array) ($meta[$tid] ?? []);
            $thread = function_exists('jeab_inkorg_thread_row')
                ? jeab_inkorg_thread_row((string) $tid, $msgs, $one)
                : $one + ['id' => (string) $tid, 'messages' => $msgs, 'drafts' => (array) ($one['drafts'] ?? [])];
            jeab_notiser_quote_events_on_thread($thread, $add, $solSplit);
        }
    } catch (Throwable) {
    }
}

/**
 * SQL-läge läser inte hela mejlboxen. Bara trådar med offertmärke eller
 * anteckning, plus mejl som är anteckningar eller har en offertbilaga.
 */
function jeab_notiser_quote_events_sql(callable $add, bool $solSplit): void
{
    $store = function_exists('jeab_inkorg_sql_store_live') ? jeab_inkorg_sql_store_live() : null;
    if (!$store instanceof JeabPdoInkorgStore) {
        jeab_notiser_quote_events_threads($add, $solSplit);
        return;
    }
    try {
        $pdo = $store->pdo();
    } catch (Throwable) {
        return;
    }
    $companies = [];
    try {
        foreach ($pdo->query('SELECT id, company FROM inkorg_list') ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $companies[(string) ($row['id'] ?? '')] = (string) ($row['company'] ?? '');
        }
    } catch (Throwable) {
    }
    $threads = [];
    try {
        $sql = "SELECT id, row_json FROM inkorg_threads WHERE row_json LIKE '%quote_sent%' OR row_json LIKE '%Offert (%'";
        foreach ($pdo->query($sql) ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $decoded = json_decode((string) ($row['row_json'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $tid = (string) ($row['id'] ?? '');
            if ($tid === '') {
                continue;
            }
            $decoded['id'] = $tid;
            $threads[$tid] = jeab_notiser_thread_with_company($decoded, (string) ($companies[$tid] ?? ''));
        }
    } catch (Throwable) {
    }
    $byMessage = [];
    try {
        $sql = "SELECT thread_id, row_json FROM inkorg_messages WHERE is_note = 1 OR row_json LIKE '%[Bilaga:%' OR row_json LIKE '%Offert (%skickad%' OR row_json LIKE '%Offert skickades inte%' OR row_json LIKE '%utskicket misslyckades%' OR row_json LIKE '%Kort svar:%'";
        foreach ($pdo->query($sql) ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $decoded = json_decode((string) ($row['row_json'] ?? ''), true);
            $tid = (string) ($row['thread_id'] ?? '');
            if ($tid === '' || !is_array($decoded)) {
                continue;
            }
            $byMessage[$tid][] = $decoded;
            if (!isset($threads[$tid])) {
                $threads[$tid] = jeab_notiser_thread_with_company(['id' => $tid, 'drafts' => []], (string) ($companies[$tid] ?? ''));
            }
        }
    } catch (Throwable) {
    }
    $solNeed = [];
    foreach ($threads as $tid => $thread) {
        $blob = json_encode($thread, JSON_UNESCAPED_UNICODE);
        if (is_string($blob) && (str_contains($blob, 'Solceller') || str_contains($blob, '"sol"'))) {
            $solNeed[] = (string) $tid;
        }
    }
    if ($solNeed !== []) {
        try {
            foreach ($store->messagesForThreads($solNeed) as $tid => $msgs) {
                if (!isset($threads[$tid]) || !is_array($msgs)) {
                    continue;
                }
                $have = (array) ($threads[$tid]['messages'] ?? []);
                $threads[$tid]['messages'] = array_merge($have, $msgs);
            }
        } catch (Throwable) {
        }
    }
    foreach ($threads as $tid => $thread) {
        $extra = $byMessage[$tid] ?? [];
        if ($extra !== []) {
            $thread['messages'] = array_merge((array) ($thread['messages'] ?? []), $extra);
        }
        jeab_notiser_quote_events_on_thread($thread, $add, $solSplit);
    }
}

/** @param array<string, mixed> $thread */
function jeab_notiser_thread_with_company(array $thread, string $company): array
{
    if ($company === 'meab') {
        $company = 'malmo';
    }
    if ($company === 'jarfalla' || $company === 'malmo') {
        $thread['brand'] = $company;
        $thread['company'] = $company;
    }
    return $thread;
}

/** @return list<string> */
function jeab_notiser_quote_event_ids(string $offer, string $company, string $lead, bool $solSplit): array
{
    $id = jeab_notiser_quote_event_id($offer, $company, $lead, $solSplit);

    return $id === '' ? [] : [$id];
}

/** Lead bara när den står på ärendet eller märket. Ingen gissning mellan mall A och B. */
function jeab_notiser_known_lead(array $meta, array $mark): string
{
    $labels = (array) ($meta['labels'] ?? []);
    $lead = trim((string) ($labels['lead'] ?? ''));
    if ($lead === 'kopt' || $lead === 'eget') {
        return $lead;
    }
    $variant = strtolower(trim((string) ($mark['variant'] ?? $mark['mall'] ?? '')));
    return match ($variant) {
        'kopt', 'a' => 'kopt',
        'eget', 'b' => 'eget',
        default => '',
    };
}

/** Nyckel laddbox_jarfalla när texten innehåller den mallens PDF-namn. */
function jeab_notiser_pdf_key(string $blob): string
{
    if ($blob === '' || !function_exists('jeab_auto_pdf_name') || !function_exists('jeab_auto_quote_seed')) {
        return '';
    }
    foreach (array_keys(jeab_auto_quote_seed()) as $key) {
        $key = (string) $key;
        $name = jeab_auto_pdf_name($key);
        if ($name !== '' && $name !== 'Solceller-offert.pdf' && str_contains($blob, $name)) {
            return $key;
        }
    }
    return '';
}

function jeab_notiser_message_is_note(array $message): bool
{
    if (!empty($message['internal_note']) || (string) ($message['kind'] ?? '') === 'note') {
        return true;
    }
    return (string) ($message['hs_thread_type'] ?? '') === 'note';
}

/** @param array<string, mixed> $message */
function jeab_notiser_message_is_quote(array $message): bool
{
    $src = (string) ($message['source'] ?? '');
    $kind = (string) ($message['kind'] ?? '');
    $blob = (string) ($message['subject'] ?? '') . "\n" . (string) ($message['body'] ?? '') . "\n" . (string) ($message['body_text'] ?? '');
    if ($kind === 'offert' || str_contains($blob, '[Bilaga:') || jeab_notiser_pdf_key($blob) !== '') {
        return true;
    }
    if (!str_starts_with($src, 'outbound')) {
        return false;
    }
    return preg_match('/offert/iu', (string) ($message['subject'] ?? '')) === 1;
}

/** @param array<string, mixed> $thread */
function jeab_notiser_quote_company(array $thread): string
{
    $brand = (string) ($thread['brand'] ?? $thread['company'] ?? '');
    if ($brand === 'meab') {
        $brand = 'malmo';
    }
    if ($brand === 'malmo' || $brand === 'jarfalla') {
        return $brand;
    }
    if (isset($thread['messages']) && is_array($thread['messages']) && function_exists('jeab_inkorg_thread_company')) {
        $company = jeab_inkorg_thread_company($thread);
        if ($company === 'malmo' || $company === 'jarfalla') {
            return $company;
        }
    }
    return 'jarfalla';
}

/** @param array<string, mixed> $mark @return list<string> */
function jeab_notiser_mark_products(array $mark, string $fallback): array
{
    $products = [];
    foreach ((array) ($mark['products'] ?? []) as $product) {
        $product = trim((string) $product);
        if ($product !== '') {
            $products[$product] = $product;
        }
    }
    $one = trim((string) ($mark['product'] ?? ''));
    if ($one !== '') {
        $products[$one] = $one;
    }
    if ($products === [] && $fallback !== '') {
        $products[$fallback] = $fallback;
    }
    return array_values($products);
}

function jeab_notiser_quote_events_on_thread(array $thread, callable $add, bool $solSplit): void
{
    $company = jeab_notiser_quote_company($thread);
    $face = jeab_notiser_thread_face($thread);
    $offer = $face['offer'];
    $lead = $face['lead'];
    $test = jeab_notiser_thread_is_test($thread);
    $mark = (array) ($thread['quote_sent'] ?? []);
    $markAt = jeab_notiser_moment((string) ($mark['at'] ?? ''));
    if (!$markAt instanceof DateTimeImmutable) {
        $markAt = jeab_notiser_moment((string) ($mark['date'] ?? ''));
    }
    $updatedAt = jeab_notiser_moment((string) ($thread['updated_at'] ?? ''));
    $seenOk = [];
    $note = static function (string $body, string $created) use ($add, $thread, $company, $offer, $lead, $solSplit, $test, $markAt, $updatedAt, &$seenOk): void {
        if (preg_match('/(?:^|\n)Kort svar:/u', $body) === 1) {
            $answerAt = jeab_notiser_moment($created);
            if (!$answerAt instanceof DateTimeImmutable) {
                $answerAt = $markAt instanceof DateTimeImmutable ? $markAt : $updatedAt;
            }
            $add('svarsregler', $answerAt, true, $test, '');
        }
        $parsed = jeab_notiser_quote_note($body);
        if ($parsed === null) {
            return;
        }
        $useOffer = $parsed['offer'] !== '' ? $parsed['offer'] : $offer;
        if ($useOffer === '') {
            return;
        }
        $at = jeab_notiser_moment($created);
        if (!$at instanceof DateTimeImmutable) {
            $at = $markAt instanceof DateTimeImmutable ? $markAt : $updatedAt;
        }
        foreach (jeab_notiser_quote_event_ids($useOffer, $company, $lead, $solSplit) as $id) {
            $add($id, $at, $parsed['ok'], $test, $parsed['ok'] ? '' : $parsed['why']);
            if ($parsed['ok']) {
                $seenOk[$id] = true;
            }
        }
    };
    foreach ((array) ($thread['drafts'] ?? []) as $draft) {
        if (!is_array($draft) || (string) ($draft['kind'] ?? '') !== 'note') {
            continue;
        }
        $note((string) ($draft['body'] ?? ''), (string) ($draft['created_at'] ?? ''));
    }
    foreach ((array) ($thread['messages'] ?? []) as $message) {
        if (!is_array($message)) {
            continue;
        }
        $body = (string) ($message['body'] ?? '');
        $plain = $body . "\n" . (string) ($message['body_text'] ?? '');
        if (jeab_notiser_message_is_note($message)) {
            $note($plain, (string) ($message['received_at'] ?? $message['created_at'] ?? ''));
        }
        if (!jeab_notiser_message_is_quote($message)) {
            continue;
        }
        $blob = (string) ($message['subject'] ?? '') . "\n" . $plain;
        $pdfKey = jeab_notiser_pdf_key($blob);
        $useOffer = trim((string) (($message['labels']['offer'] ?? '')));
        $useCompany = $company;
        if ($pdfKey !== '') {
            $parts = explode('_', $pdfKey, 2);
            $useOffer = (string) ($parts[0] ?? $useOffer);
            $pdfCompany = (string) ($parts[1] ?? '');
            if ($pdfCompany === 'jarfalla' || $pdfCompany === 'malmo') {
                $useCompany = $pdfCompany;
            }
        }
        if ($useOffer === '') {
            $useOffer = $offer !== '' ? $offer : (string) (jeab_notiser_mark_products($mark, '')[0] ?? '');
        }
        if ($useOffer === '') {
            continue;
        }
        $at = jeab_notiser_moment((string) ($message['received_at'] ?? $message['sent_at'] ?? ''));
        if (!$at instanceof DateTimeImmutable) {
            $at = $markAt instanceof DateTimeImmutable ? $markAt : $updatedAt;
        }
        $ids = jeab_notiser_quote_event_ids($useOffer, $useCompany, $lead, $solSplit);
        foreach ($ids as $id) {
            $add($id, $at, true, $test, '');
            $seenOk[$id] = true;
        }
    }
    $markReady = trim((string) ($mark['source'] ?? '')) !== '' || trim((string) ($mark['date'] ?? '')) !== '' || trim((string) ($mark['at'] ?? '')) !== '';
    if (!$markReady || !$markAt instanceof DateTimeImmutable) {
        return;
    }
    foreach (jeab_notiser_mark_products($mark, $offer) as $product) {
        foreach (jeab_notiser_quote_event_ids($product, $company, $lead, $solSplit) as $id) {
            if (!empty($seenOk[$id])) {
                continue;
            }
            $add($id, $markAt, true, $test, '');
            $seenOk[$id] = true;
        }
    }
}

function jeab_notiser_inbox_sent_events(callable $add, bool $solSplit): void
{
    if (jeab_notiser_view_is_light()) {
        return;
    }
    if (!function_exists('jeab_store') || !function_exists('jeab_ensure_inbox_sent')) {
        return;
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return;
    }
    if (!$store instanceof JeabPdoStore) {
        return;
    }
    $pdo = $store->pdo();
    $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
    try {
        jeab_ensure_inbox_sent($pdo, $driver);
        $stmt = $pdo->query("SELECT created_at, thread_id, kind, outcome FROM inbox_sent WHERE kind IN ('offert', 'resend')");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable) {
        return;
    }
    $ids = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $outcome = (string) ($row['outcome'] ?? '');
        if ($outcome !== '' && $outcome !== 'sent') {
            continue;
        }
        $tid = (string) ($row['thread_id'] ?? '');
        if ($tid !== '') {
            $ids[$tid] = $tid;
        }
    }
    $metas = [];
    $inkorg = function_exists('jeab_inkorg_sql_store_live') ? jeab_inkorg_sql_store_live() : null;
    if ($inkorg instanceof JeabPdoInkorgStore && $ids !== []) {
        try {
            $metas = $inkorg->threadsByIds(array_values($ids));
        } catch (Throwable) {
            $metas = [];
        }
    }
    if ($metas === []) {
        $metas = jeab_notiser_list_companies($ids);
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $outcome = (string) ($row['outcome'] ?? '');
        if ($outcome !== '' && $outcome !== 'sent') {
            continue;
        }
        $tid = (string) ($row['thread_id'] ?? '');
        $meta = is_array($metas[$tid] ?? null) ? $metas[$tid] : [];
        $meta['id'] = $tid;
        $mark = (array) ($meta['quote_sent'] ?? []);
        $at = jeab_notiser_moment((string) ($row['created_at'] ?? ''));
        if (!$at instanceof DateTimeImmutable) {
            $at = jeab_notiser_moment((string) ($mark['at'] ?? ''));
        }
        if (!$at instanceof DateTimeImmutable) {
            $at = jeab_notiser_moment((string) ($mark['date'] ?? ''));
        }
        $company = jeab_notiser_quote_company($meta);
        $test = str_starts_with($tid, 'autotest-') || !empty($meta['test_lead']);
        $lead = jeab_notiser_known_lead($meta, $mark);
        foreach (jeab_notiser_mark_products($mark, '') as $product) {
            foreach (jeab_notiser_quote_event_ids($product, $company, $lead, $solSplit) as $id) {
                $add($id, $at, true, $test, '');
            }
        }
    }
}

function jeab_notiser_logged_events(callable $add): void
{
    foreach (jeab_notiser_send_log_rows() as $row) {
        $add(
            (string) ($row['notis_id'] ?? ''),
            jeab_notiser_moment((string) ($row['sent_at'] ?? '')),
            (int) ($row['ok'] ?? 0) === 1,
            (int) ($row['is_test'] ?? 0) === 1,
            jeab_notiser_safe_why((string) ($row['why'] ?? ''))
        );
    }
}

/**
 * Senaste raderna per notis, via indexet (notis_id, sent_at). Skapar ingen tabell.
 * Saknas tabellen blir listan tom.
 *
 * @return list<array<string, mixed>>
 */
function jeab_notiser_send_log_rows(): array
{
    if (!function_exists('jeab_store')) {
        return [];
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return [];
    }
    if (!$store instanceof JeabPdoStore) {
        return [];
    }
    $ids = jeab_notiser_switch_ids();
    if ($ids === []) {
        return [];
    }
    $place = implode(',', array_fill(0, count($ids), '?'));
    $cut = jeab_notiser_cut()->setTimezone(jeab_notiser_zone())->modify('-1 day')->format('Y-m-d H:i:s');
    try {
        $pdo = $store->pdo();
        $recent = $pdo->prepare(
            'SELECT notis_id, sent_at, ok, is_test, why FROM notis_sends WHERE notis_id IN (' . $place . ') AND sent_at >= ?'
        );
        $recent->execute(array_merge($ids, [$cut]));
        $latest = $pdo->prepare(
            'SELECT s.notis_id, s.sent_at, s.ok, s.is_test, s.why
             FROM notis_sends s
             INNER JOIN (
                SELECT notis_id, ok, is_test, MAX(sent_at) AS sent_at
                FROM notis_sends
                WHERE notis_id IN (' . $place . ')
                GROUP BY notis_id, ok, is_test
             ) t ON t.notis_id = s.notis_id AND t.ok = s.ok AND t.is_test = s.is_test AND t.sent_at = s.sent_at'
        );
        $latest->execute($ids);
        $rows = array_merge($recent->fetchAll(PDO::FETCH_ASSOC) ?: [], $latest->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return $out;
}

/** Sidan läser cachen. Saknas tabellen blir det ingen tid, inte ett schemabyggande. */
function jeab_notiser_history_read(callable $add): void
{
    if (!function_exists('jeab_store')) {
        return;
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return;
    }
    if (!$store instanceof JeabPdoStore) {
        return;
    }
    try {
        $stmt = $store->pdo()->query('SELECT notis_id, sent_at, ok, is_test, why FROM notis_history ORDER BY sent_at DESC LIMIT 200');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable) {
        return;
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $add(
            (string) ($row['notis_id'] ?? ''),
            jeab_notiser_moment((string) ($row['sent_at'] ?? '')),
            (int) ($row['ok'] ?? 0) === 1,
            (int) ($row['is_test'] ?? 0) === 1,
            jeab_notiser_safe_why((string) ($row['why'] ?? ''))
        );
    }
}

/**
 * Tick fyller cachen från anteckning, märke och bilaga. Högst 200 händelser.
 * Sidvisning anropar den inte.
 */
function jeab_notiser_history_refresh(): void
{
    if (jeab_notiser_view_is_light()) {
        return;
    }
    if (!function_exists('jeab_store') || !function_exists('jeab_ensure_notis_history')) {
        return;
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return;
    }
    if (!$store instanceof JeabPdoStore) {
        return;
    }
    $events = [];
    $add = static function (string $id, ?DateTimeImmutable $at, bool $ok, bool $test = false, string $why = '') use (&$events): void {
        if ($id === '' || !$at instanceof DateTimeImmutable) {
            return;
        }
        $stamp = jeab_notiser_stamp($at);
        if ($stamp === '') {
            return;
        }
        $key = $id . '|' . $stamp . '|' . ($ok ? '1' : '0') . '|' . ($test ? '1' : '0');
        if (isset($events[$key])) {
            return;
        }
        $events[$key] = [
            'id' => $id,
            'at' => $at,
            'stamp' => $at->setTimezone(jeab_notiser_zone())->format('Y-m-d H:i:s'),
            'ok' => $ok ? 1 : 0,
            'test' => $test ? 1 : 0,
            'why' => jeab_notiser_safe_why($why),
        ];
    };
    try {
        jeab_notiser_booking_backfill();
    } catch (Throwable) {
    }
    try {
        jeab_notiser_quote_events($add);
    } catch (Throwable) {
        return;
    }
    $rows = array_values($events);
    usort($rows, static function (array $a, array $b): int {
        return $b['at'] <=> $a['at'];
    });
    $rows = array_slice($rows, 0, 200);
    $prev = $GLOBALS['_JEAB_NOTIS_SEND_DDL'] ?? null;
    $GLOBALS['_JEAB_NOTIS_SEND_DDL'] = 1;
    try {
        $pdo = $store->pdo();
        $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
        jeab_ensure_notis_sends($pdo, $driver);
        jeab_ensure_notis_history($pdo, $driver);
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM notis_history');
        $stmt = $pdo->prepare('INSERT INTO notis_history (notis_id, sent_at, ok, is_test, why) VALUES (?, ?, ?, ?, ?)');
        foreach ($rows as $row) {
            $stmt->execute([$row['id'], $row['stamp'], $row['ok'], $row['test'], $row['why']]);
        }
        $pdo->commit();
    } catch (Throwable) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } finally {
        if ($prev === null) {
            unset($GLOBALS['_JEAB_NOTIS_SEND_DDL']);
        } else {
            $GLOBALS['_JEAB_NOTIS_SEND_DDL'] = $prev;
        }
    }
    jeab_notiser_catalog_forget();
}

/** @return array{offer:string,ok:bool,why:string}|null */
function jeab_notiser_quote_note(string $body): ?array
{
    $ok = preg_match('/Offert \((Elbilsladdare|Batteri|Solceller(?: med batteri)?)\) skickad/u', $body, $m) === 1;
    $fail = preg_match('/utskicket misslyckades|Offert skickades inte automatiskt/u', $body) === 1;
    if (!$ok && !$fail) {
        return null;
    }
    $label = $ok ? (string) ($m[1] ?? '') : '';
    if ($label === '' && preg_match('/(Elbilsladdare|Batteri|Solceller)/u', $body, $hit) === 1) {
        $label = (string) $hit[1];
    }
    $offer = match (true) {
        str_starts_with($label, 'Elbilsladdare') => 'laddbox',
        str_starts_with($label, 'Batteri') => 'batteri',
        str_starts_with($label, 'Solceller') => 'sol',
        default => '',
    };
    $success = $ok && !$fail;
    return ['offer' => $offer, 'ok' => $success, 'why' => $success ? '' : jeab_notiser_quote_why($body)];
}

/** Orsaken i «Offert skickades inte automatiskt: …» eller «utskicket misslyckades (…)». */
function jeab_notiser_quote_why(string $body): string
{
    if (preg_match('/Offert skickades inte automatiskt:\s*(.+)/u', $body, $m) === 1) {
        $why = trim((string) $m[1]);
        $why = (string) preg_replace('/\.\s*Ärendet lämnas till er\.?\s*$/u', '', $why);
        return rtrim($why, ". \t");
    }
    if (preg_match('/utskicket misslyckades(?:\s*\([^)]*\))?/u', $body, $m) === 1) {
        return trim((string) $m[0]);
    }
    return '';
}

function jeab_notiser_quote_event_id(string $offer, string $company, string $lead, bool $solSplit): string
{
    if ($offer === 'sol' && $company === 'jarfalla' && $solSplit) {
        if ($lead === 'kopt') {
            return 'offert-sol-jarfalla-kopt';
        }
        if ($lead === 'eget') {
            return 'offert-sol-jarfalla-eget';
        }
        return '';
    }
    if (!isset(jeab_notiser_products()[$offer]) || !isset(jeab_notiser_companies()[$company])) {
        return '';
    }
    return 'offert-' . $offer . '-' . $company;
}

function jeab_notiser_booking_face(int $id): array
{
    $face = ['job' => '', 'company' => ''];
    if ($id <= 0 || !function_exists('jeab_store')) {
        return $face;
    }
    try {
        $row = jeab_store()->getBooking($id);
    } catch (Throwable) {
        return $face;
    }
    if (!is_array($row)) {
        return $face;
    }
    $job = strtolower(trim((string) ($row['job_type'] ?? '')));
    if (function_exists('jeab_normalize_job_key')) {
        $job = (string) (jeab_normalize_job_key($job) ?? $job);
    }
    $company = function_exists('jeab_calendar_mail_company') ? jeab_calendar_mail_company($row) : (string) ($row['company'] ?? '');
    return ['job' => $job, 'company' => $company];
}

function jeab_notiser_booking_events(callable $add): void
{
    $GLOBALS['_JEAB_NOTIS_BOOKING_SCAN'] = (int) ($GLOBALS['_JEAB_NOTIS_BOOKING_SCAN'] ?? 0) + 1;
    /** @var list<array{kind:string,id:int,channel:string,at:?DateTimeImmutable,ok:bool}> $pending */
    $pending = [];
    $queue = static function (string $kind, int $id, string $channel, ?DateTimeImmutable $at, bool $ok) use (&$pending): void {
        $pending[] = ['kind' => $kind, 'id' => $id, 'channel' => $channel, 'at' => $at, 'ok' => $ok];
    };
    if (function_exists('jeab_sms_sent_store')) {
        foreach (jeab_sms_sent_store() as $id => $row) {
            if (!is_array($row)) {
                continue;
            }
            $bid = (int) $id;
            if (!empty($row['email'])) {
                $queue('channel', $bid, 'mail', jeab_notiser_moment((string) ($row['email_at'] ?? '')), empty($row['email_error']));
            }
            if (!empty($row['sms'])) {
                $queue('channel', $bid, 'sms', jeab_notiser_moment((string) ($row['sms_at'] ?? '')), empty($row['sms_error']));
            }
            if (!empty($row['email_error'])) {
                $queue('channel', $bid, 'mail', jeab_notiser_moment((string) ($row['email_error_at'] ?? $row['email_at'] ?? '')), false);
            }
            if (!empty($row['sms_error'])) {
                $queue('channel', $bid, 'sms', jeab_notiser_moment((string) ($row['sms_error_at'] ?? $row['sms_at'] ?? '')), false);
            }
            if (!empty($row['move_email'])) {
                $queue('move', $bid, 'mail', jeab_notiser_moment((string) ($row['move_email_at'] ?? '')), empty($row['move_email_error']));
            }
        }
    }
    if (function_exists('jeab_calendar_log_read')) {
        $log = jeab_calendar_log_read();
        foreach ((array) ($log['entries'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $type = (string) ($entry['type'] ?? '');
            if ($type !== 'mail' && $type !== 'sms') {
                continue;
            }
            $queue('channel', (int) ($entry['booking_id'] ?? 0), $type, jeab_notiser_moment((string) ($entry['at'] ?? '')), true);
        }
    }
    if ($pending !== [] && function_exists('jeab_bookings_warm') && function_exists('jeab_store')) {
        $ids = [];
        foreach ($pending as $item) {
            if ($item['id'] > 0) {
                $ids[$item['id']] = $item['id'];
            }
        }
        try {
            $store = jeab_store();
            if ($store instanceof JeabStore && $ids !== []) {
                jeab_bookings_warm($store, array_values($ids));
            }
        } catch (Throwable) {
        }
    }
    $seen = [];
    foreach ($pending as $item) {
        $at = $item['at'];
        $bid = $item['id'];
        if ($item['kind'] === 'move') {
            if (!$at instanceof DateTimeImmutable || $bid <= 0) {
                continue;
            }
            $moveFace = jeab_notiser_booking_face($bid);
            $moveCompany = $moveFace['company'] === 'malmo' ? 'malmo' : ($moveFace['company'] !== '' ? 'jarfalla' : '');
            if ($moveCompany !== '') {
                $add('flytt-' . $moveCompany, $at, $item['ok']);
            }
            continue;
        }
        if (!$at instanceof DateTimeImmutable || $bid <= 0) {
            continue;
        }
        $face = jeab_notiser_booking_face($bid);
        if ($face['job'] === '' || $face['company'] === '') {
            continue;
        }
        $key = $item['channel'] . '|' . $bid . '|' . $at->format('Y-m-d H:i');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $prefix = $item['channel'] === 'sms' ? 'sms-' : 'bekraft-';
        $add($prefix . $face['job'] . '-' . $face['company'], $at, $item['ok']);
    }
}

/**
 * Tick läser sms_sent och kalenderloggen en gång och skriver mall-id, tid och ok.
 * Sidan anropar den inte. Flaggan notis_booking_backfill hindrar en ny läsning.
 */
function jeab_notiser_booking_backfill(): void
{
    if (jeab_notiser_view_is_light()) {
        return;
    }
    if (!function_exists('jeab_store') || !function_exists('jeab_ensure_notis_sends') || !function_exists('jeab_settings_read')) {
        return;
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return;
    }
    if (!$store instanceof JeabPdoStore) {
        return;
    }
    $pdo = $store->pdo();
    $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
    $read = jeab_settings_read($pdo, $driver, 'notis_booking_backfill');
    if (empty($read['ok']) || ($read['value'] ?? null) === 'ok') {
        return;
    }
    $events = [];
    $add = static function (string $id, ?DateTimeImmutable $at, bool $ok, bool $test = false, string $why = '') use (&$events): void {
        if ($id === '' || !$at instanceof DateTimeImmutable || !in_array($id, jeab_notiser_switch_ids(), true)) {
            return;
        }
        $stamp = $at->setTimezone(jeab_notiser_zone())->format('Y-m-d H:i:s');
        $key = $id . '|' . $stamp . '|' . ($ok ? '1' : '0') . '|' . ($test ? '1' : '0');
        if (isset($events[$key])) {
            return;
        }
        $events[$key] = [$id, $stamp, $ok ? 1 : 0, $test ? 1 : 0, jeab_notiser_safe_why($why)];
    };
    try {
        jeab_notiser_booking_events($add);
    } catch (Throwable) {
        return;
    }
    $prev = $GLOBALS['_JEAB_NOTIS_SEND_DDL'] ?? null;
    $GLOBALS['_JEAB_NOTIS_SEND_DDL'] = 1;
    try {
        jeab_ensure_notis_sends($pdo, $driver);
        $have = [];
        $stmt = $pdo->query('SELECT notis_id, sent_at, ok, is_test FROM notis_sends');
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $have[(string) ($row['notis_id'] ?? '') . '|' . (string) ($row['sent_at'] ?? '') . '|' . (int) ($row['ok'] ?? 0) . '|' . (int) ($row['is_test'] ?? 0)] = true;
        }
        $ins = $pdo->prepare('INSERT INTO notis_sends (notis_id, sent_at, ok, is_test, why) VALUES (?, ?, ?, ?, ?)');
        foreach ($events as $key => $row) {
            if (!isset($have[$key])) {
                $ins->execute($row);
            }
        }
        if (function_exists('jeab_settings_put')) {
            jeab_settings_put($pdo, $driver, 'notis_booking_backfill', 'ok');
        }
    } catch (Throwable) {
        return;
    } finally {
        if ($prev === null) {
            unset($GLOBALS['_JEAB_NOTIS_SEND_DDL']);
        } else {
            $GLOBALS['_JEAB_NOTIS_SEND_DDL'] = $prev;
        }
    }
    jeab_notiser_catalog_forget();
}

function jeab_notiser_apply(array $rows): array
{
    $events = jeab_notiser_events();
    $cut = jeab_notiser_cut();
    $zone = jeab_notiser_zone();
    foreach ($rows as $i => $row) {
        $list = $events[$row['id']] ?? [];
        $unique = [];
        foreach ($list as $event) {
            if (!is_array($event) || !$event['at'] instanceof DateTimeImmutable) {
                continue;
            }
            $minute = $event['at']->setTimezone($zone)->format('Y-m-d H:i');
            $key = $minute . '|' . (!empty($event['ok']) ? '1' : '0') . '|' . (!empty($event['test']) ? '1' : '0');
            if (!isset($unique[$key])) {
                $unique[$key] = $event;
            }
        }
        $count = 0;
        $lastOk = null;
        $lastTest = null;
        $lastFail = null;
        foreach ($unique as $event) {
            if (!empty($event['test'])) {
                if (!empty($event['ok']) && ($lastTest === null || $event['at'] > $lastTest['at'])) {
                    $lastTest = $event;
                }
                continue;
            }
            if (!empty($event['ok'])) {
                if ($event['at'] >= $cut) {
                    $count++;
                }
                if ($lastOk === null || $event['at'] > $lastOk['at']) {
                    $lastOk = $event;
                }
                continue;
            }
            if ($lastFail === null || $event['at'] > $lastFail['at']) {
                $lastFail = $event;
            }
        }
        $problems = array_values(array_unique(array_filter(array_map('strval', (array) ($row['problems'] ?? [])))));
        if ($lastFail !== null && ($lastOk === null || $lastFail['at'] >= $lastOk['at'])) {
            $why = jeab_notiser_safe_why((string) ($lastFail['why'] ?? ''));
            $line = $why !== '' ? 'senaste utskicket misslyckades: ' . $why : 'senaste utskicket misslyckades';
            if (!in_array($line, $problems, true)) {
                $problems[] = $line;
            }
        }
        $rows[$i]['problems'] = $problems;
        $rows[$i]['problem'] = implode(' · ', $problems);
        $rows[$i]['last_used'] = $lastOk !== null ? jeab_notiser_stamp($lastOk['at']) : '';
        $rows[$i]['last_test'] = $lastTest !== null ? jeab_notiser_stamp($lastTest['at']) : '';
        $rows[$i]['sends_30d'] = $count;
        $enabled = jeab_notiser_is_on((string) ($row['id'] ?? ''));
        $rows[$i]['enabled'] = $enabled;
        if (!$enabled) {
            $rows[$i]['status'] = 'Av';
        } elseif ($problems !== []) {
            $rows[$i]['status'] = 'Problem';
        } else {
            $rows[$i]['status'] = 'Aktiv';
        }
    }
    return $rows;
}

function jeab_notiser_public_row(array $row): array
{
    $attachments = [];
    foreach ((array) ($row['attachments'] ?? []) as $att) {
        if (!is_array($att)) {
            continue;
        }
        $attachments[] = [
            'name' => (string) ($att['name'] ?? ''),
            'bytes' => (int) ($att['bytes'] ?? 0),
            'size' => jeab_notiser_bytes_label((int) ($att['bytes'] ?? 0)),
            'missing' => !empty($att['missing']),
            'href' => (string) ($att['href'] ?? ''),
        ];
    }
    return [
        'id' => (string) ($row['id'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'channel' => (string) ($row['channel'] ?? ''),
        'company' => (string) ($row['company_label'] ?? ''),
        'product' => (string) ($row['product_label'] ?? ''),
        'stage' => (string) ($row['stage'] ?? ''),
        'trigger' => (string) ($row['trigger'] ?? ''),
        'sender' => (string) ($row['sender'] ?? ''),
        'last_used' => (string) ($row['last_used'] ?? ''),
        'last_test' => (string) ($row['last_test'] ?? ''),
        'sends_30d' => (int) ($row['sends_30d'] ?? 0),
        'status' => (string) ($row['status'] ?? ''),
        'enabled' => !array_key_exists('enabled', $row) || !empty($row['enabled']),
        'problem' => (string) ($row['problem'] ?? ''),
        'source' => (string) ($row['source'] ?? ''),
        'edit' => (string) ($row['edit'] ?? ''),
        'body' => (string) ($row['body'] ?? ''),
        'updated_by' => (string) ($row['updated_by'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'can_restore' => !empty($row['can_restore']),
        'pdf_key' => (string) ($row['pdf_key'] ?? ''),
        'pdf_updated_by' => (string) ($row['pdf_updated_by'] ?? ''),
        'pdf_updated_at' => (string) ($row['pdf_updated_at'] ?? ''),
        'pdf_can_restore' => !empty($row['pdf_can_restore']),
        'editable' => !array_key_exists('editable', $row) || !empty($row['editable']),
        'attachments' => $attachments,
    ];
}

/** Bolag från inkorg_list för de id:n som behövs. Hela inkorgen byggs inte. */
function jeab_notiser_list_companies(array $ids): array
{
    $store = function_exists('jeab_inkorg_sql_store_live') ? jeab_inkorg_sql_store_live() : null;
    if (!$store instanceof JeabPdoInkorgStore) {
        return [];
    }
    $want = [];
    foreach ($ids as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
            $want[$id] = $id;
        }
    }
    $want = array_slice(array_values($want), 0, 200);
    if ($want === []) {
        return [];
    }
    $place = implode(',', array_fill(0, count($want), '?'));
    try {
        $stmt = $store->pdo()->prepare('SELECT id, company FROM inkorg_list WHERE id IN (' . $place . ')');
        $stmt->execute($want);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tid = (string) ($row['id'] ?? '');
        if ($tid === '') {
            continue;
        }
        $company = (string) ($row['company'] ?? '');
        $out[$tid] = ['id' => $tid, 'company' => $company, 'brand' => $company];
    }

    return $out;
}

function jeab_notiser_catalog_forget(): void
{
    unset($GLOBALS['_JEAB_NOTIS_CATALOG'], $GLOBALS['_JEAB_NOTIS_CATALOG_UNTIL']);
    if (function_exists('apcu_delete')) {
        apcu_delete('jeab_notis_cat_v1');
    }
    $file = jeab_notiser_catalog_cache_file();
    if (is_file($file)) {
        @unlink($file);
    }
}

function jeab_notiser_catalog_cache_file(): string
{
    $salt = function_exists('jeab_data_dir') ? jeab_data_dir() : 'jeab';

    return rtrim(sys_get_temp_dir(), '/') . '/jeab-notis-cat-' . substr(hash('sha256', $salt), 0, 16);
}

/** @return list<array<string, mixed>>|null */
function jeab_notiser_catalog_cached(): ?array
{
    $now = time();
    $mem = $GLOBALS['_JEAB_NOTIS_CATALOG'] ?? null;
    if (is_array($mem) && (int) ($GLOBALS['_JEAB_NOTIS_CATALOG_UNTIL'] ?? 0) >= $now) {
        return $mem;
    }
    if (function_exists('apcu_fetch')) {
        $ok = false;
        $hit = apcu_fetch('jeab_notis_cat_v1', $ok);
        if ($ok && is_array($hit) && is_array($hit['rows'] ?? null) && (int) ($hit['until'] ?? 0) >= $now) {
            $GLOBALS['_JEAB_NOTIS_CATALOG'] = $hit['rows'];
            $GLOBALS['_JEAB_NOTIS_CATALOG_UNTIL'] = (int) $hit['until'];

            return $hit['rows'];
        }
    }
    $file = jeab_notiser_catalog_cache_file();
    if (!is_file($file)) {
        return null;
    }
    $raw = file_get_contents($file);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || !is_array($decoded['rows'] ?? null) || (int) ($decoded['until'] ?? 0) < $now) {
        return null;
    }
    $GLOBALS['_JEAB_NOTIS_CATALOG'] = $decoded['rows'];
    $GLOBALS['_JEAB_NOTIS_CATALOG_UNTIL'] = (int) $decoded['until'];

    return $decoded['rows'];
}

/** @param list<array<string, mixed>> $rows */
function jeab_notiser_catalog_store(array $rows): void
{
    $until = time() + 45;
    $GLOBALS['_JEAB_NOTIS_CATALOG'] = $rows;
    $GLOBALS['_JEAB_NOTIS_CATALOG_UNTIL'] = $until;
    $bag = ['until' => $until, 'rows' => $rows];
    if (function_exists('apcu_store')) {
        apcu_store('jeab_notis_cat_v1', $bag, 45);
    }
    $json = json_encode($bag, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }
    $file = jeab_notiser_catalog_cache_file();
    if (file_put_contents($file, $json, LOCK_EX) !== false) {
        @chmod($file, 0600);
    }
}

function jeab_notiser_catalog(): array
{
    if (jeab_notiser_view_is_light()) {
        $cached = jeab_notiser_catalog_cached();
        if ($cached !== null) {
            return $cached;
        }
    }
    $GLOBALS['_JEAB_NOTIS_CATALOG_BUILDS'] = (int) ($GLOBALS['_JEAB_NOTIS_CATALOG_BUILDS'] ?? 0) + 1;
    $rows = [];
    foreach (jeab_notiser_rows() as $row) {
        $rows[] = jeab_notiser_decorate($row);
    }
    $out = jeab_notiser_apply($rows);
    if (jeab_notiser_view_is_light()) {
        jeab_notiser_catalog_store($out);
    }

    return $out;
}

function jeab_notiser_list(): array
{
    $items = [];
    foreach (jeab_notiser_catalog() as $row) {
        $items[] = jeab_notiser_public_row($row);
    }
    return ['ok' => true, 'items' => $items];
}

function jeab_notiser_counts(array $rows): array
{
    $counts = ['all' => count($rows), 'Aktiv' => 0, 'Av' => 0, 'Problem' => 0];
    foreach ($rows as $row) {
        $status = (string) ($row['status'] ?? '');
        if (isset($counts[$status])) {
            $counts[$status]++;
        }
    }
    return $counts;
}

function jeab_notiser_filter_from_request(): array
{
    $co = (string) ($_GET['co'] ?? '');
    $ch = (string) ($_GET['ch'] ?? '');
    $st = (string) ($_GET['st'] ?? '');
    $pr = (string) ($_GET['pr'] ?? '');
    return [
        'co' => isset(jeab_notiser_companies()[$co]) ? $co : '',
        'ch' => in_array($ch, ['mail', 'sms'], true) ? $ch : '',
        'st' => in_array($st, ['Aktiv', 'Av', 'Problem'], true) ? $st : '',
        'pr' => isset(jeab_notiser_product_filters()[$pr]) ? $pr : '',
    ];
}

function jeab_notiser_filter(array $rows, array $filter): array
{
    $out = [];
    foreach ($rows as $row) {
        if (($filter['co'] ?? '') !== '' && (string) ($row['company'] ?? '') !== $filter['co']) {
            continue;
        }
        if (($filter['ch'] ?? '') !== '' && (string) ($row['channel'] ?? '') !== $filter['ch']) {
            continue;
        }
        if (($filter['st'] ?? '') !== '' && (string) ($row['status'] ?? '') !== $filter['st']) {
            continue;
        }
        if (($filter['pr'] ?? '') !== '' && (string) ($row['product'] ?? '') !== $filter['pr']) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

function jeab_notiser_url(array $filter): string
{
    $flik = (string) ($filter['flik'] ?? '');
    if (!in_array($flik, ['alla', 'notiser', 'offerter'], true)) {
        $flik = 'notiser';
    }
    $q = ['p' => 'utskick', 'flik' => $flik];
    foreach (['co', 'ch', 'st', 'pr'] as $key) {
        $value = trim((string) ($filter[$key] ?? ''));
        if ($value !== '') {
            $q[$key] = $value;
        }
    }
    return jeab_url('index.php?' . http_build_query($q));
}

function jeab_notiser_store_path(): string
{
    return jeab_data_dir() . '/notiser/mallar.json';
}

function jeab_notiser_pdf_max_bytes(): int
{
    return 8 * 1024 * 1024;
}

/** @return array{items: array<string, array<string, mixed>>, pdf: array<string, array<string, mixed>>} */
function jeab_notiser_store_read(): array
{
    if (isset($GLOBALS['_JEAB_NOTIS_STORE']) && is_array($GLOBALS['_JEAB_NOTIS_STORE'])) {
        return $GLOBALS['_JEAB_NOTIS_STORE'];
    }
    if (function_exists('jeab_cutover_notiser_store')) {
        $sql = jeab_cutover_notiser_store();
        if (is_array($sql)) {
            $GLOBALS['_JEAB_NOTIS_STORE'] = $sql;

            return $sql;
        }
    }
    $path = jeab_notiser_store_path();
    if (!is_file($path)) {
        $GLOBALS['_JEAB_NOTIS_STORE'] = ['items' => [], 'pdf' => []];

        return $GLOBALS['_JEAB_NOTIS_STORE'];
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        $GLOBALS['_JEAB_NOTIS_STORE'] = ['items' => [], 'pdf' => []];

        return $GLOBALS['_JEAB_NOTIS_STORE'];
    }
    $store = [
        'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
        'pdf' => is_array($data['pdf'] ?? null) ? $data['pdf'] : [],
    ];
    $GLOBALS['_JEAB_NOTIS_STORE'] = $store;

    return $store;
}

/** @param array{items: array<string, array<string, mixed>>, pdf: array<string, array<string, mixed>>} $store */
function jeab_notiser_store_write(array $store): void
{
    unset($GLOBALS['_JEAB_NOTIS_STORE']);
    if (function_exists('jeab_notiser_catalog_forget')) {
        jeab_notiser_catalog_forget();
    }
    if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('notiser') && function_exists('jeab_cutover_locked')) {
        jeab_cutover_locked('notiser', static function (array $current) use ($store): array {
            unset($current);

            return $store;
        });

        return;
    }
    $dir = dirname(jeab_notiser_store_path());
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte spara mallen.');
    }
    $json = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents(jeab_notiser_store_path(), $json, LOCK_EX) === false) {
        throw new RuntimeException('Kunde inte spara mallen.');
    }
}

function jeab_notiser_active_body(string $id): string
{
    $item = jeab_notiser_store_read()['items'][$id] ?? null;
    if (!is_array($item)) {
        return '';
    }
    return trim((string) ($item['body'] ?? ''));
}

function jeab_notiser_text(string $id, string $fallback): string
{
    $body = jeab_notiser_active_body($id);
    return $body !== '' ? $body : $fallback;
}

function jeab_notiser_editable_ids(): array
{
    $ids = ['svarsregler', 'flytt-jarfalla', 'flytt-malmo'];
    foreach (array_keys(jeab_notiser_companies()) as $company) {
        foreach (array_keys(jeab_notiser_products()) as $job) {
            $ids[] = 'bekraft-' . $job . '-' . $company;
            if (!($job === 'sol' && $company === 'malmo')) {
                $ids[] = 'sms-' . $job . '-' . $company;
            }
            if ($job === 'service') {
                continue;
            }
            if ($job === 'sol' && $company === 'jarfalla') {
                $ids[] = 'offert-sol-jarfalla-kopt';
                $ids[] = 'offert-sol-jarfalla-eget';
                $ids[] = 'offert-sol-jarfalla';
                continue;
            }
            $ids[] = 'offert-' . $job . '-' . $company;
        }
    }
    return $ids;
}

function jeab_notiser_pdf_key_for(string $id): string
{
    if ($id === 'offert-sol-jarfalla-kopt' || $id === 'offert-sol-jarfalla-eget' || $id === 'offert-sol-jarfalla') {
        return 'sol_jarfalla';
    }
    if (preg_match('/^offert-(laddbox|batteri|sol)-(jarfalla|malmo)$/', $id, $m) !== 1) {
        return '';
    }
    $key = $m[1] . '_' . $m[2];
    return function_exists('jeab_auto_asset_keys') && in_array($key, jeab_auto_asset_keys(), true) ? $key : '';
}

function jeab_notiser_pdf_prev_path(string $key): string
{
    return jeab_auto_pdf_path($key) . '.prev';
}

function jeab_notiser_pdf_can_restore(string $key, array $pdf): bool
{
    if (trim((string) ($pdf['previous_b64'] ?? '')) !== '') {
        return true;
    }

    return !empty($pdf['has_previous']) && is_file(jeab_notiser_pdf_prev_path($key));
}

function jeab_notiser_quote_pdf_bytes(string $key): ?string
{
    if (!function_exists('jeab_cutover_sql') || !jeab_cutover_sql('notiser')) {
        return null;
    }
    $pdf = jeab_notiser_store_read()['pdf'][$key] ?? null;
    if (!is_array($pdf)) {
        return null;
    }
    $b64 = (string) ($pdf['bytes_b64'] ?? '');
    if ($b64 === '') {
        return null;
    }
    $raw = base64_decode($b64, true);

    return is_string($raw) && $raw !== '' ? $raw : null;
}

/** Filnamn som får ligga i ett mejl. Inget katalogtecken. */
function jeab_notiser_safe_pdf_name(string $name): string
{
    $name = str_replace(["\0", '\\', '/'], '', trim($name));
    $name = basename($name);
    if ($name === '' || $name === '.' || $name === '..') {
        return '';
    }
    if (!str_ends_with(strtolower($name), '.pdf')) {
        $name .= '.pdf';
    }
    if (strlen($name) > 180) {
        $name = substr($name, 0, 176) . '.pdf';
    }

    return $name;
}

/**
 * Dokumenttiteln i PDF:ens Info-objekt. Outline-rubriker (Slide 1) ignoreras.
 */
function jeab_notiser_pdf_title(string $bytes): string
{
    if (!preg_match('/\/Info\s+(\d+)\s+0\s+R/', $bytes, $info)) {
        return '';
    }
    $num = $info[1];
    if (!preg_match('/(?:\A|\n)' . preg_quote($num, '/') . ' 0 obj\s*<<(.*?)>>/s', $bytes, $obj)) {
        return '';
    }
    $dict = (string) $obj[1];
    $text = '';
    if (preg_match('/\/Title\s*<([0-9A-Fa-f]+)>/', $dict, $hex)) {
        $raw = $hex[1];
        if (str_starts_with(strtoupper($raw), 'FEFF')) {
            $raw = substr($raw, 4);
        }
        if (strlen($raw) < 4 || strlen($raw) % 4 !== 0) {
            return '';
        }
        $bin = hex2bin($raw);
        if (!is_string($bin) || $bin === '') {
            return '';
        }
        $text = mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
    } elseif (preg_match('/\/Title\s*\((.*?)\)/s', $dict, $lit)) {
        $text = stripcslashes($lit[1]);
    }
    $text = trim(str_replace(["\0", "\r", "\n"], '', $text));

    return $text;
}

/**
 * Namn på bilagan. Sparat uppladdningsnamn vinner. Annars dokumenttiteln.
 * En test-PDF utan titel behåller det historiska namnet. En riktig PDF med
 * titel som inte går att läsa ger tomt, så det gamla namnet inte används.
 */
function jeab_notiser_pdf_filename(string $key, string $bytes): string
{
    $meta = jeab_notiser_store_read()['pdf'][$key] ?? null;
    if (is_array($meta)) {
        $stored = jeab_notiser_safe_pdf_name((string) ($meta['name'] ?? ''));
        if ($stored !== '') {
            return $stored;
        }
    }
    $title = jeab_notiser_pdf_title($bytes);
    if ($title !== '') {
        return jeab_notiser_safe_pdf_name($title);
    }
    if (!str_contains($bytes, '/Title')) {
        return function_exists('jeab_auto_pdf_name') ? jeab_auto_pdf_name($key) : jeab_notiser_disk_name($key);
    }

    return '';
}

/**
 * PDF som Notiser sparat. SQL-bytes om de finns, annars filen save_pdf skriver.
 * Den incheckade NEO-PDF:en används inte.
 *
 * @return array{bytes:string,name:string}
 */
function jeab_notiser_quote_pdf_load(string $key): array
{
    $empty = ['bytes' => '', 'name' => ''];
    $bytes = jeab_notiser_quote_pdf_bytes($key);
    if (!is_string($bytes) || $bytes === '') {
        $path = function_exists('jeab_auto_pdf_path') ? jeab_auto_pdf_path($key) : jeab_notiser_disk_path($key);
        if ($path === '' || !is_file($path)) {
            return $empty;
        }
        $file = file_get_contents($path);
        $bytes = is_string($file) ? $file : '';
    }
    if ($bytes === '' || !str_starts_with($bytes, '%PDF')) {
        return $empty;
    }
    $name = jeab_notiser_pdf_filename($key, $bytes);
    if ($name === '') {
        return ['bytes' => $bytes, 'name' => ''];
    }

    return ['bytes' => $bytes, 'name' => $name];
}

/**
 * Text och PDF för sol och batteri, båda leadtyperna.
 * Bara det som är sparat i Notiser. Ingen gammal mall och ingen NEO-PDF.
 *
 * @return array{ok:bool,id:string,variant:string,body:string,pdf_key:string,pdf_name:string,pdf_bytes:string,error:string}
 */
function jeab_notiser_quote_material(string $offer, string $company, string $variant = ''): array
{
    $offer = $offer === 'sol+batteri' ? 'sol' : $offer;
    $blank = [
        'ok' => false,
        'id' => '',
        'variant' => '',
        'body' => '',
        'pdf_key' => '',
        'pdf_name' => '',
        'pdf_bytes' => '',
        'error' => 'notisen saknas',
    ];
    if (!in_array($offer, ['sol', 'batteri'], true) || !in_array($company, ['jarfalla', 'malmo'], true)) {
        $blank['error'] = 'notisen saknas för ' . $offer;

        return $blank;
    }
    $split = $offer === 'sol' && $company === 'jarfalla';
    if ($split && $variant !== 'kopt' && $variant !== 'eget') {
        $blank['error'] = 'notis-text saknas för offert-sol-jarfalla';

        return $blank;
    }
    $id = jeab_notiser_quote_notice_id($offer, $company, $variant, $split);
    $pdfKey = jeab_notiser_pdf_key_for($id);
    $blank['id'] = $id;
    $blank['variant'] = $split ? $variant : '';
    $blank['pdf_key'] = $pdfKey;
    $body = jeab_notiser_active_body($id);
    if ($body === '') {
        $blank['error'] = 'notis-text saknas för ' . $id;

        return $blank;
    }
    if ($pdfKey === '') {
        $blank['error'] = 'notis-pdf saknas för ' . $id;

        return $blank;
    }
    $pdf = jeab_notiser_quote_pdf_load($pdfKey);
    if ($pdf['bytes'] === '') {
        $blank['error'] = 'notis-PDF saknas för ' . $pdfKey;
        $blank['body'] = $body;

        return $blank;
    }
    if ($pdf['name'] === '') {
        $blank['error'] = 'notis-PDF-namn saknas för ' . $pdfKey;
        $blank['body'] = $body;
        $blank['pdf_bytes'] = $pdf['bytes'];

        return $blank;
    }

    return [
        'ok' => true,
        'id' => $id,
        'variant' => $split ? $variant : '',
        'body' => $body,
        'pdf_key' => $pdfKey,
        'pdf_name' => $pdf['name'],
        'pdf_bytes' => $pdf['bytes'],
        'error' => '',
    ];
}

function jeab_notiser_actor(): string
{
    $email = function_exists('jeab_auth_user_email') ? trim((string) (jeab_auth_user_email() ?? '')) : '';
    return $email !== '' && str_contains($email, '@') ? $email : 'inloggad';
}

function jeab_notiser_save_body(string $id, string $body, string $who): void
{
    if (!in_array($id, jeab_notiser_editable_ids(), true)) {
        throw new InvalidArgumentException('Den mallen kan inte redigeras.');
    }
    $body = trim(str_replace("\r\n", "\n", $body));
    if ($body === '') {
        throw new InvalidArgumentException('Texten är tom.');
    }
    if (strlen($body) > 50000) {
        throw new InvalidArgumentException('Texten är för lång.');
    }
    $apply = static function (array $store) use ($id, $body, $who): array {
        $current = is_array($store['items'][$id] ?? null) ? $store['items'][$id] : [];
        $previous = array_key_exists('body', $current) ? (string) $current['body'] : '';
        $store['items'][$id] = [
            'body' => $body,
            'previous' => $previous,
            'updated_by' => $who,
            'updated_at' => date('c'),
        ];

        return $store;
    };
    if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('notiser') && function_exists('jeab_cutover_locked')) {
        jeab_cutover_locked('notiser', $apply);

        return;
    }
    jeab_notiser_store_write($apply(jeab_notiser_store_read()));
}

function jeab_notiser_restore_body(string $id, string $who): void
{
    if (!in_array($id, jeab_notiser_editable_ids(), true)) {
        throw new InvalidArgumentException('Den mallen kan inte redigeras.');
    }
    $apply = static function (array $store) use ($id, $who): array {
        $current = is_array($store['items'][$id] ?? null) ? $store['items'][$id] : null;
        if ($current === null || !array_key_exists('previous', $current)) {
            throw new InvalidArgumentException('Det finns ingen tidigare version.');
        }
        $store['items'][$id] = [
            'body' => (string) $current['previous'],
            'previous' => (string) ($current['body'] ?? ''),
            'updated_by' => $who,
            'updated_at' => date('c'),
        ];

        return $store;
    };
    if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('notiser') && function_exists('jeab_cutover_locked')) {
        jeab_cutover_locked('notiser', $apply);

        return;
    }
    jeab_notiser_store_write($apply(jeab_notiser_store_read()));
}

function jeab_notiser_save_pdf(string $id, string $bytes, string $who, string $filename = ''): void
{
    $key = jeab_notiser_pdf_key_for($id);
    if ($key === '' || !function_exists('jeab_auto_pdf_path')) {
        throw new InvalidArgumentException('Den mallen har ingen PDF.');
    }
    $filename = jeab_notiser_safe_pdf_name($filename);
    if ($bytes === '' || !str_starts_with($bytes, '%PDF')) {
        throw new InvalidArgumentException('Filen är inte en PDF.');
    }
    if (strlen($bytes) > jeab_notiser_pdf_max_bytes()) {
        throw new InvalidArgumentException('PDF:en är större än 8 MB.');
    }
    $path = jeab_auto_pdf_path($key);
    if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('notiser') && function_exists('jeab_cutover_locked')) {
        jeab_cutover_locked('notiser', static function (array $store) use ($key, $bytes, $who, $path, $filename): array {
            $prevMeta = is_array($store['pdf'][$key] ?? null) ? $store['pdf'][$key] : [];
            $previousBytes = '';
            if (is_string($prevMeta['bytes_b64'] ?? null) && $prevMeta['bytes_b64'] !== '') {
                $decoded = base64_decode((string) $prevMeta['bytes_b64'], true);
                $previousBytes = is_string($decoded) ? $decoded : '';
            }
            if ($previousBytes === '' && is_file($path)) {
                $previousBytes = (string) file_get_contents($path);
            }
            $previousB64 = $previousBytes !== '' ? base64_encode($previousBytes) : '';
            $next = [
                'updated_by' => $who,
                'updated_at' => date('c'),
                'has_previous' => $previousB64 !== '',
                'previous_b64' => $previousB64,
                'bytes_b64' => base64_encode($bytes),
            ];
            if ($filename !== '') {
                $next['name'] = $filename;
            } elseif (is_string($prevMeta['name'] ?? null) && $prevMeta['name'] !== '') {
                $next['name'] = (string) $prevMeta['name'];
            }
            $store['pdf'][$key] = $next;

            return $store;
        });

        return;
    }
    $store = jeab_notiser_store_read();
    $prevMeta = is_array($store['pdf'][$key] ?? null) ? $store['pdf'][$key] : [];
    $previousBytes = '';
    if (is_string($prevMeta['bytes_b64'] ?? null) && $prevMeta['bytes_b64'] !== '') {
        $decoded = base64_decode((string) $prevMeta['bytes_b64'], true);
        $previousBytes = is_string($decoded) ? $decoded : '';
    }
    if ($previousBytes === '' && is_file($path)) {
        $previousBytes = (string) file_get_contents($path);
    }
    $previousB64 = $previousBytes !== '' ? base64_encode($previousBytes) : '';
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Kunde inte spara PDF:en.');
    }
    if (file_put_contents($path, $bytes, LOCK_EX) === false) {
        throw new RuntimeException('Kunde inte spara PDF:en.');
    }
    $next = [
        'updated_by' => $who,
        'updated_at' => date('c'),
        'has_previous' => $previousB64 !== '' || is_file(jeab_notiser_pdf_prev_path($key)),
        'previous_b64' => $previousB64,
    ];
    if ($filename !== '') {
        $next['name'] = $filename;
    } elseif (is_string($prevMeta['name'] ?? null) && $prevMeta['name'] !== '') {
        $next['name'] = (string) $prevMeta['name'];
    }
    $store['pdf'][$key] = $next;
    jeab_notiser_store_write($store);
}

function jeab_notiser_restore_pdf(string $id, string $who): void
{
    $key = jeab_notiser_pdf_key_for($id);
    if ($key === '') {
        throw new InvalidArgumentException('Det finns ingen tidigare PDF.');
    }
    if (function_exists('jeab_cutover_sql') && jeab_cutover_sql('notiser') && function_exists('jeab_cutover_locked')) {
        jeab_cutover_locked('notiser', static function (array $store) use ($key, $who): array {
            $meta = is_array($store['pdf'][$key] ?? null) ? $store['pdf'][$key] : [];
            $prevB64 = (string) ($meta['previous_b64'] ?? '');
            $old = $prevB64 !== '' ? (string) base64_decode($prevB64, true) : '';
            if (!str_starts_with($old, '%PDF')) {
                $prev = jeab_notiser_pdf_prev_path($key);
                $old = $prev !== '' && is_file($prev) ? (string) file_get_contents($prev) : '';
            }
            if (!str_starts_with($old, '%PDF')) {
                throw new InvalidArgumentException('Det finns ingen tidigare PDF.');
            }
            $curB64 = (string) ($meta['bytes_b64'] ?? '');
            $current = $curB64 !== '' ? (string) base64_decode($curB64, true) : '';
            $store['pdf'][$key] = [
                'updated_by' => $who,
                'updated_at' => date('c'),
                'has_previous' => $current !== '',
                'previous_b64' => $current !== '' ? base64_encode($current) : '',
                'bytes_b64' => base64_encode($old),
            ];

            return $store;
        });

        return;
    }
    $store = jeab_notiser_store_read();
    $meta = is_array($store['pdf'][$key] ?? null) ? $store['pdf'][$key] : [];
    $prevB64 = (string) ($meta['previous_b64'] ?? '');
    $old = $prevB64 !== '' ? (string) base64_decode($prevB64, true) : '';
    if (!str_starts_with($old, '%PDF')) {
        $prev = jeab_notiser_pdf_prev_path($key);
        $old = $prev !== '' && is_file($prev) ? (string) file_get_contents($prev) : '';
    }
    if (!str_starts_with($old, '%PDF')) {
        throw new InvalidArgumentException('Det finns ingen tidigare PDF.');
    }
    $path = jeab_auto_pdf_path($key);
    $current = '';
    $sql = function_exists('jeab_cutover_sql') && jeab_cutover_sql('notiser');
    if ($sql) {
        $curB64 = (string) ($meta['bytes_b64'] ?? '');
        $current = $curB64 !== '' ? (string) base64_decode($curB64, true) : '';
    } else {
        $current = is_file($path) ? (string) file_get_contents($path) : '';
        if (file_put_contents($path, $old, LOCK_EX) === false) {
            throw new RuntimeException('Kunde inte återställa PDF:en.');
        }
    }
    $store['pdf'][$key] = [
        'updated_by' => $who,
        'updated_at' => date('c'),
        'has_previous' => $current !== '',
        'previous_b64' => $current !== '' ? base64_encode($current) : '',
    ];
    if ($sql) {
        $store['pdf'][$key]['bytes_b64'] = base64_encode($old);
    }
    jeab_notiser_store_write($store);
}

/** @param array<string, mixed> $file */
function jeab_notiser_save_upload(string $id, string $body, array $file, string $who): string
{
    $note = 'Sparad.';
    if (trim($body) !== '') {
        jeab_notiser_save_body($id, $body, $who);
    }
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE || (string) ($file['tmp_name'] ?? '') === '') {
        if (trim($body) === '') {
            throw new InvalidArgumentException('Texten är tom.');
        }
        return $note;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('PDF:en kunde inte läsas.');
    }
    $tmp = (string) $file['tmp_name'];
    $size = (int) ($file['size'] ?? 0);
    if ($size > jeab_notiser_pdf_max_bytes()) {
        throw new InvalidArgumentException('PDF:en är större än 8 MB.');
    }
    $bytes = (string) file_get_contents($tmp);
    jeab_notiser_save_pdf($id, $bytes, $who, (string) ($file['name'] ?? ''));
    return 'Sparad.';
}

function jeab_notiser_decorate(array $row): array
{
    $id = (string) ($row['id'] ?? '');
    $item = jeab_notiser_store_read()['items'][$id] ?? null;
    if (is_array($item) && trim((string) ($item['body'] ?? '')) !== '') {
        $row['body'] = trim((string) $item['body']);
        $row['source'] = 'notiser/mallar.json';
        $problems = array_values(array_filter(
            array_map('strval', (array) ($row['problems'] ?? [])),
            static fn(string $problem): bool => $problem !== 'mall saknas'
        ));
        $row['problems'] = $problems;
    }
    if (is_array($item)) {
        $row['updated_by'] = (string) ($item['updated_by'] ?? '');
        $row['updated_at'] = (string) ($item['updated_at'] ?? '');
        $row['can_restore'] = array_key_exists('previous', $item);
    }
    if (!array_key_exists('editable', $row)) {
        $row['editable'] = in_array($id, jeab_notiser_editable_ids(), true);
    }
    $pdfKey = jeab_notiser_pdf_key_for($id);
    if ($pdfKey !== '') {
        $row['pdf_key'] = $pdfKey;
        $pdf = jeab_notiser_store_read()['pdf'][$pdfKey] ?? null;
        if (is_array($pdf)) {
            $row['pdf_updated_by'] = (string) ($pdf['updated_by'] ?? '');
            $row['pdf_updated_at'] = (string) ($pdf['updated_at'] ?? '');
            $row['pdf_can_restore'] = jeab_notiser_pdf_can_restore($pdfKey, $pdf);
        }
    }
    return $row;
}

/** Id:n som har en på/av-knapp. Saknad rad i SQL betyder på. */
function jeab_notiser_switch_ids(): array
{
    $ids = jeab_notiser_editable_ids();
    $ids[] = 'sms-sol-malmo';
    return array_values(array_unique($ids));
}

function jeab_notiser_booking_notice_id(array $row, string $kind): string
{
    $job = function_exists('jeab_booking_job_type') ? jeab_booking_job_type($row) : (string) ($row['job_type'] ?? '');
    if (!in_array($job, ['laddbox', 'batteri', 'sol', 'service'], true)) {
        $job = 'service';
    }
    $company = function_exists('jeab_notiser_booking_company') ? jeab_notiser_booking_company($row) : 'jarfalla';
    $prefix = $kind === 'sms' ? 'sms' : 'bekraft';
    return $prefix . '-' . $job . '-' . $company;
}

function jeab_notiser_quote_notice_id(string $offer, string $company, string $variant, bool $splitSol): string
{
    if ($splitSol && $offer === 'sol' && $company === 'jarfalla' && ($variant === 'kopt' || $variant === 'eget')) {
        return 'offert-sol-jarfalla-' . $variant;
    }
    return 'offert-' . $offer . '-' . $company;
}

function jeab_notiser_switch_pdo(): ?PDO
{
    if (!function_exists('jeab_store')) {
        return null;
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return null;
    }
    return $store instanceof JeabPdoStore ? $store->pdo() : null;
}

/** @return array<string, bool> id => på */
function jeab_notiser_switch_map(bool $reload = false): array
{
    static $map = null;
    if ($reload) {
        $map = null;
    }
    if (is_array($map)) {
        return $map;
    }
    $map = [];
    $pdo = jeab_notiser_switch_pdo();
    if (!$pdo instanceof PDO) {
        return $map;
    }
    try {
        $stmt = $pdo->query('SELECT notis_id, enabled FROM notis_switches');
        if ($stmt === false) {
            return $map;
        }
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['notis_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $map[$id] = (int) ($row['enabled'] ?? 1) === 1;
        }
    } catch (Throwable) {
        $map = [];
    }
    return $map;
}

function jeab_notiser_is_on(string $id): bool
{
    $id = trim($id);
    if ($id === '' || !in_array($id, jeab_notiser_switch_ids(), true)) {
        return true;
    }
    $map = jeab_notiser_switch_map();
    if (!array_key_exists($id, $map)) {
        return true;
    }
    return $map[$id];
}

function jeab_notiser_set_enabled(string $id, bool $on, string $who): void
{
    $id = trim($id);
    if (!in_array($id, jeab_notiser_switch_ids(), true)) {
        throw new InvalidArgumentException('Den notisen finns inte.');
    }
    $pdo = jeab_notiser_switch_pdo();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Notisens på/av sparas i SQL.');
    }
    $who = trim($who);
    if (strlen($who) > 191) {
        $who = substr($who, 0, 191);
    }
    $now = date('Y-m-d H:i:s');
    $enabled = $on ? 1 : 0;
    $driver = function_exists('jeab_driver') ? jeab_driver() : 'sqlite';
    if ($driver === 'mysql') {
        $stmt = $pdo->prepare(
            'INSERT INTO notis_switches (notis_id, enabled, updated_at, updated_by)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)'
        );
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO notis_switches (notis_id, enabled, updated_at, updated_by)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(notis_id) DO UPDATE SET
                enabled = excluded.enabled,
                updated_at = excluded.updated_at,
                updated_by = excluded.updated_by'
        );
    }
    $stmt->execute([$id, $enabled, $now, $who]);
    jeab_notiser_switch_map(true);
    jeab_notiser_catalog_forget();
}

/**
 * Sparar tidpunkten för ett försök. Testkopior markeras is_test och räknas inte som kundutskick.
 * Skriver inte om utskicket misslyckas att spara: själva mejlet är redan avgjort.
 */
function jeab_notiser_record_send(string $id, bool $ok, bool $test = false, string $why = '', ?string $at = null): void
{
    $id = trim($id);
    if ($id === '' || !in_array($id, jeab_notiser_switch_ids(), true)) {
        return;
    }
    if (!function_exists('jeab_store') || !function_exists('jeab_ensure_notis_sends')) {
        return;
    }
    try {
        $store = jeab_store();
    } catch (Throwable) {
        return;
    }
    if (!$store instanceof JeabPdoStore) {
        return;
    }
    $moment = $at !== null && trim($at) !== '' ? jeab_notiser_moment($at) : null;
    if (!$moment instanceof DateTimeImmutable) {
        $moment = new DateTimeImmutable('now', jeab_notiser_zone());
    }
    if (!$moment instanceof DateTimeImmutable) {
        return;
    }
    $stamp = $moment->setTimezone(jeab_notiser_zone())->format('Y-m-d H:i:s');
    $why = jeab_notiser_safe_why($why);
    $prev = $GLOBALS['_JEAB_NOTIS_SEND_DDL'] ?? null;
    $GLOBALS['_JEAB_NOTIS_SEND_DDL'] = 1;
    try {
        $pdo = $store->pdo();
        $driver = function_exists('jeab_driver') && jeab_driver() === 'mysql' ? 'mysql' : 'sqlite';
        jeab_ensure_notis_sends($pdo, $driver);
        $pdo->prepare('INSERT INTO notis_sends (notis_id, sent_at, ok, is_test, why) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $stamp, $ok ? 1 : 0, $test ? 1 : 0, $why]);
        jeab_notiser_catalog_forget();
    } catch (Throwable) {
    } finally {
        if ($prev === null) {
            unset($GLOBALS['_JEAB_NOTIS_SEND_DDL']);
        } else {
            $GLOBALS['_JEAB_NOTIS_SEND_DDL'] = $prev;
        }
    }
}

/**
 * Central grind för utskick till slutkund. Avstängd notis anropar inte $send.
 *
 * @return array<string, mixed>
 */
function jeab_notiser_send(string $id, callable $send): array
{
    if (!jeab_notiser_is_on($id)) {
        return [
            'ok' => false,
            'skipped' => true,
            'sent' => false,
            'error' => null,
            'reason' => 'notis-av',
            'path' => 'notis-av',
            'id' => '',
        ];
    }
    $result = $send();
    if (!is_array($result)) {
        $result = ['ok' => false, 'skipped' => false, 'error' => 'Utskicket svarade fel.', 'reason' => 'error'];
    }
    if (empty($result['skipped']) && empty($result['queued'])) {
        $sentOk = !empty($result['ok']) || !empty($result['sent']);
        $why = $sentOk ? '' : (string) ($result['error'] ?? $result['reason'] ?? '');
        jeab_notiser_record_send($id, $sentOk, false, $why);
    }
    return $result;
}
