<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Solcellsmallar (köpt lead och egen lead) som Notiser och offertutskick använder.
 * Transporten är Microsoft Graph. Den här filen skickar inget.
 */

/**
 * Hitta-solceller (bindestreck eller mellanslag, valfri versalisering).
 * Inte varje sträng som bara innehåller «hitta».
 */
function jeab_offert_is_hitta_solceller(mixed $source): bool
{
    if (is_array($source) || is_object($source)) {
        return false;
    }
    $raw = trim((string) $source);
    if ($raw === '') {
        return false;
    }
    $raw = function_exists('mb_strtolower')
        ? mb_strtolower($raw, 'UTF-8')
        : strtolower($raw);
    return preg_match('/(?<![\p{L}\p{N}])hitta(?:\s*-\s*|\s+)solceller(?![\p{L}\p{N}])/u', $raw) === 1;
}

/**
 * Källfält som offert-anropet kan skicka (Kundtjänst/Help Scout via samma POST).
 * Repot har ingen lista över andra köpta källor, så bara Hitta-solceller låses.
 *
 * @return list<string>
 */
function jeab_offert_sources(array $opts): array
{
    $out = [];
    foreach (['source', 'kalla', 'källa', 'lead_source', 'ursprung'] as $key) {
        if (!array_key_exists($key, $opts) || is_array($opts[$key])) {
            continue;
        }
        $s = trim((string) $opts[$key]);
        if ($s !== '') {
            $out[] = $s;
        }
    }
    return $out;
}

/**
 * Köpt lead om anropet säger kopt/kop/bought/purchased, eller om källan är Hitta-solceller.
 * Saknad variant och variant eget blir ändå kopt när källan är Hitta-solceller.
 */
function jeab_offert_variant(mixed $raw, mixed $source = null): string
{
    $sources = is_array($source) ? $source : [$source];
    foreach ($sources as $item) {
        if (jeab_offert_is_hitta_solceller($item)) {
            return 'kopt';
        }
    }
    if (jeab_offert_is_hitta_solceller($raw)) {
        return 'kopt';
    }
    $raw = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string) $raw), 'UTF-8')
        : strtolower(trim((string) $raw));
    $raw = strtr($raw, ['ä' => 'a', 'å' => 'a', 'ö' => 'o']);
    if (in_array($raw, ['kopt', 'kop', 'bought', 'purchased', 'kopth'], true)) {
        return 'kopt';
    }
    return 'eget';
}

/**
 * Lås Hitta-solceller till köpt mall även om variant saknas eller är eget.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function jeab_offert_lock_hitta_variant(array $input): array
{
    foreach (jeab_offert_sources($input) as $source) {
        if (jeab_offert_is_hitta_solceller($source)) {
            $input['variant'] = 'kopt';
            return $input;
        }
    }
    return $input;
}

function jeab_offert_first_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    $first = preg_split('/\s+/', $name)[0] ?? $name;
    return trim((string) $first);
}

function jeab_offert_pick_text(array $opts, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $opts) || is_array($opts[$key])) {
            continue;
        }
        $s = trim((string) $opts[$key]);
        if ($s !== '') {
            return $s;
        }
    }
    return '';
}

function jeab_offert_pick_email(array $opts): string
{
    foreach (['email', 'to'] as $key) {
        if (!array_key_exists($key, $opts)) {
            continue;
        }
        $raw = $opts[$key];
        if (is_array($raw)) {
            $first = $raw[0] ?? $raw;
            $raw = is_array($first) ? (string) ($first['email'] ?? '') : (string) $first;
        }
        $raw = trim((string) $raw);
        if (preg_match('/<([^>]+)>/', $raw, $m)) {
            $raw = trim($m[1]);
        }
        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return $raw;
        }
    }
    return '';
}

/**
 * Namn, adress, telefon och e-post ur ett sol-lead. Läggs inte i offertmejlet.
 */
function jeab_offert_kundfooter(array $opts): string
{
    if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && function_exists('jeab_offert_kundblock') && trim((string) ($opts['contact_id'] ?? '')) !== '') {
        $block = jeab_offert_kundblock($opts);
        if ($block['name'] !== '') {
            $opts['name'] = $block['name'];
        }
        $address = $block['display'] !== '' ? $block['display'] : $block['street'];
        if ($address !== '') {
            $opts['address'] = $address;
        }
        if ($block['phone'] !== '') {
            $opts['phone'] = $block['phone'];
        }
        if ($block['email'] !== '') {
            $opts['email'] = $block['email'];
        }
    }
    $lines = [];
    foreach ([
        jeab_offert_pick_text($opts, ['name', 'customer']),
        jeab_offert_pick_text($opts, ['address', 'adress']),
        jeab_offert_pick_text($opts, ['phone', 'telefon', 'tel']),
        jeab_offert_pick_email($opts),
    ] as $line) {
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    if ($lines === []) {
        return '';
    }
    $open = '<div style="font-family:inherit; font-size:inherit; color:inherit; background-color:transparent">';
    $html = $open . '<br></div>';
    foreach ($lines as $line) {
        $html .= $open . htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    }
    return $html;
}

/**
 * Mall: Solceller eget / köpt lead. Alltid ny text — återanvänd aldrig Skickat.
 * Ämne alltid «Solceller med batteri» (aldrig eget/köpt/GODKÄNN i rubrik).
 * Signaturtitel: Elektriker. Brödtexten är de mejl Sam skickade från
 * demo@example.test (Skickat): mall A köpt lead, mall B egen lead.
 * Bara hälsningens förnamn varierar. Kundens namn och e-post läggs inte efter signaturen.
 * POST subject/body ignoreras alltid (Kundtjänst kan skicka stub).
 *
 * @return array{subject: string, body: string, variant: string}
 */
function jeab_offert_template(array $opts): array
{
    // Hitta-solceller är köpt lead även när variant saknas eller är eget.
    $variant = jeab_offert_variant(
        $opts['variant'] ?? $opts['kind'] ?? $opts['lead'] ?? '',
        jeab_offert_sources($opts)
    );
    $name = jeab_offert_pick_text($opts, ['name', 'customer']);
    $first = jeab_offert_first_name($name);
    $hello = $first !== '' ? 'Hej ' . $first . ',' : 'Hej,';
    // Sam: ämne alltid exakt detta — ignorera POST subject.
    $subject = 'Solceller med batteri';
    // Sparad text på Notiser vinner. Annars mallen. Aldrig POST body och inget kundblock efter signaturen.
    $custom = function_exists('jeab_notiser_active_body') ? jeab_notiser_active_body('offert-sol-jarfalla-' . $variant) : '';
    if ($custom !== '' && function_exists('jeab_auto_quote_html')) {
        $text = preg_match('/^Hej\b/u', $custom) === 1
            ? (string) preg_replace('/^Hej[^\n]*/u', $hello, $custom, 1)
            : $hello . "\n\n" . $custom;
        $body = jeab_auto_quote_html($text, 'jarfalla', [], false);
    } else {
        $body = jeab_offert_mall_body($variant, $hello);
    }
    return [
        'subject' => $subject,
        'body' => $body,
        'variant' => $variant,
    ];
}

function jeab_offert_div(string $inner): string
{
    return '<div style="font-family:inherit; font-size:inherit; color:inherit; background-color:transparent">' . $inner . '</div>';
}

/** Medaljen. /signatur/ stoppas av kampanjgrinden, så båda mallarna använder den publika bilden. */
function jeab_offert_medal_img(string $src): string
{
    return '<img alt="Bäst solpanelsinstallatör Stockholm Norrort" width="120" src="' . $src . '" style="width:120px; height:auto; border:0">';
}

/**
 * Mall A (kopt) och mall B (eget) ordagrant från Sam skickade mejl.
 * Stavningen satelitbild är med flit. Bara {{HELLO}} byts ut här.
 */
function jeab_offert_mall_body(string $variant, string $hello): string
{
    $hello = trim($hello);
    if ($hello === '') {
        $hello = 'Hej,';
    }
    $br = jeab_offert_div('<br>');
    $panels = 'För att välja rätt antal solceller så utgår jag ifrån hur mycket el ni köper i maj eller juni. Låt oss säga 800 kWh. Det brukar motsvara ca 12 st paneler. Men om ni i framtiden konsumerar mer el eller det är molnigt en dag så har ni för få paneler. Det kostar ungefär 20 000 kr att lägga till 10 st paneler. Om platsen finns på taket och utrymmet finns i er ekonomi så är det en god idé att ha en något högre elproduktion på sommaren än vad ni förbrukar.';
    $visit = 'Jag kommer hem till dig och går igenom alla förutsättningar för installationen så att vi båda är trygga att installationen flyter på utan problem eller extra kostnader.';
    $bought = $variant === 'kopt';
    $medal = 'https://flowstack.example.test/assets/img/medal-solpanel.png';
    $parts = [jeab_offert_div(htmlspecialchars($hello, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), $br];
    if ($bought) {
        $parts[] = jeab_offert_div('Du har visat intresse för att installera solceller med oss!');
        $parts[] = $br;
    }
    $parts[] = jeab_offert_div($panels);
    $parts[] = $br;
    $parts[] = jeab_offert_div($bought
        ? 'Bifogar en prislista/offert med solpaneler och 9-27 kWh premiumbatteri från Sigenergy från 94 000 kr, installerat och klart efter grön teknik.'
        : 'Bifogar en offert med solpaneler och 9-27 kWh premiumbatteri från Sigenergy från 94 000 kr, installerat och klart efter grön teknik.');
    $parts[] = $br;
    $parts[] = jeab_offert_div($bought
        ? 'Jag gissar att du intresserat dig för oss just för att vi är små, lokala och gör en korrekt installation som klarar besiktning så att alla garantier gäller. Så jag skippar säljsnacket =)'
        : 'Jag gissar att du intresserat dig för oss just för att vi är små, lokala och gör en korrekt installation som klarar besiktning så att alla garantier gäller.');
    $parts[] = $br;
    $parts[] = jeab_offert_div('Vi brukar ha ungefär följande process från detta mail till order:');
    if ($bought) {
        $parts[] = jeab_offert_div('Du svarar på detta mail eller ringer mig');
        $parts[] = jeab_offert_div('Jag tittar på ditt hus på satelitbild och skickar konkret offert');
        $parts[] = jeab_offert_div($visit);
    } else {
        $parts[] = jeab_offert_div('1. Du svarar på detta mail eller ringer mig');
        $parts[] = jeab_offert_div('2. Jag tittar på ditt hus på satelitbild och skickar konkret offert');
        $parts[] = jeab_offert_div('3. ' . $visit);
    }
    $parts[] = $br;
    $parts[] = jeab_offert_div('Demo Säljare');
    $parts[] = jeab_offert_div('Elektriker');
    $parts[] = jeab_offert_div('Demo Energi Järfälla');
    $parts[] = $br;
    $parts[] = jeab_offert_div(jeab_offert_medal_img($medal));
    $parts[] = $br;
    $parts[] = jeab_offert_div('08-000 00 02');
    $parts[] = jeab_offert_div('<a href="mailto:demo@example.test">demo@example.test</a>');
    $parts[] = jeab_offert_div('<a href="https://www.jarfalla.example.test">www.jarfalla.example.test</a>');
    $parts[] = $br;
    $parts[] = jeab_offert_div('Här är alla publika besiktningar installerade av oss och många andra så får du en känsla vad du får från olika installatörer <a href="https://www.solenergikvalitet.se/leverantorer">https://www.solenergikvalitet.se/leverantorer</a>');
    $parts[] = $br;
    return implode('', $parts);
}

/**
 * @return list<array{filename: string, content_type: string, content: string}>
 */
function jeab_offert_bytes_are_pdf(string $bytes): bool
{
    return str_starts_with($bytes, '%PDF-');
}

function jeab_offert_require_pdf_bytes(string $bytes, string $name = 'offert.pdf'): array
{
    if (!jeab_offert_bytes_are_pdf($bytes)) {
        throw new InvalidArgumentException('Bifogan måste vara en PDF. / Attachment must be a PDF.');
    }
    $name = basename(trim($name)) ?: 'offert.pdf';
    if (!str_ends_with(strtolower($name), '.pdf')) {
        $name .= '.pdf';
    }
    return [
        'filename' => $name,
        'content_type' => 'application/pdf',
        'content' => $bytes,
    ];
}
