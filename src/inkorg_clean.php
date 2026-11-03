<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/*
 * Rensad brödtext för visning och förhandsvisning. Originalet i SQL rörs inte.
 * Kända leadkällor har egna mönster. Övrigt lärs: en rad som finns i minst tre mejl
 * från samma källa är standardtext, om den inte ser ut som kunduppgift.
 */

/**
 * Tar bort Help Scout-länkar (nöjdhet, notis, konversation) och {#HS:…}-koder.
 * Samma text en gång till ändras inte. Kundens egen text lämnas kvar.
 */
function jeab_inkorg_strip_hs_junk(string $text): string
{
    if ($text === '') {
        return '';
    }
    if (stripos($text, 'helpscout.') === false && stripos($text, '{#HS:') === false) {
        return $text;
    }
    $text = preg_replace('~<a\b[^>]*helpscout\.(?:net|com)[^>]*>.*?</a>~isu', '', $text) ?? $text;
    $text = preg_replace('/\{#HS:[^{}]{0,80}\}/iu', '', $text) ?? $text;
    $text = preg_replace('~(?<![\w@./])(?:(?:https?:)?//)?(?:[a-z0-9-]+\.)*helpscout\.(?:net|com)\b[^\s<>"\']*~iu', '', $text) ?? $text;
    $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;
    $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

    return trim($text);
}

function jeab_inkorg_html_to_text(string $body): string
{
    $body = str_replace("\r", '', $body);
    if (!preg_match('/<(?:html|body|div|p|br|table|td|tr|span|a|style|script|li)\b/i', $body)) {
        return $body;
    }
    $body = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $body) ?? $body;
    $body = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $body) ?? $body;
    $body = preg_replace('/<\/\s*(?:p|div|tr|h[1-6]|li|table|blockquote)\s*>/i', "\n", $body) ?? $body;
    $body = preg_replace('/<\/\s*t[dh]\s*>/i', "\t", $body) ?? $body;
    $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $body = jeab_inkorg_clean_invisibles($body);
    $body = preg_replace('/[ \t]*\t[ \t]*/', "\t", $body) ?? $body;
    $body = preg_replace('/[ ]{2,}/', ' ', $body) ?? $body;
    return $body;
}

function jeab_inkorg_clean_platform(array $msg): string
{
    $from = function_exists('jeab_msg_platform_address') ? jeab_msg_platform_address($msg) : (string) ($msg['from'] ?? '');
    return function_exists('jeab_lead_platform') ? jeab_lead_platform($from) : '';
}

/** Nyckel för «samma källa»: leadplattform, annars avsändaradressen. */
function jeab_inkorg_clean_source_key(array $msg): string
{
    $platform = jeab_inkorg_clean_platform($msg);
    if ($platform !== '') {
        return 'platform:' . $platform;
    }
    return 'from:' . mb_strtolower(trim((string) ($msg['from'] ?? '')));
}

/** @return list<string> */
function jeab_inkorg_clean_patterns(string $platform): array
{
    $common = [
        '/^[-_=*·.•]{3,}$/u',
        '/^©/',
        '/\b(unsubscribe|avregistrera|avprenumerera|avsluta prenumeration|opt[- ]?out|e-?postinställningar|hantera prenumeration|email preferences)\b/iu',
        '/\b(view in browser|visa i webbläsaren|view this (email|message) in your browser)\b/iu',
        '/\b(this (e-?mail|message) was sent|detta (mejl|meddelande|är ett automatiskt)|automatiskt meddelande|do not reply|svara inte på detta)\b/iu',
        '/\b(integritetspolicy|privacy policy|all rights reserved|alla rättigheter|användarvillkor|terms of service)\b/iu',
        '/^(org\.?\s*nr|organisationsnummer)\b/iu',
        '/^(skickat från min |sent from my )/iu',
        '/^(följ oss|follow us)\b/iu',
        '/^(läs mer|klicka här|logga in|se (förfrågan|lead|ärendet)|open (the )?lead|view (the )?lead).{0,80}https?:\/\//iu',
        '/^>+/',
        '/\b(utm_|trk=|click\.|track\.|list-manage\.com|mailchimp|getdrip\.com\/unsubscribe)\b/i',
    ];
    $wix = [
        '/^a site visitor just submitted/i',
        '/^submission summary\b/i',
        '/^you can view this submission/i',
        '/^submitted (from|via)\b/i',
        '/^form (name|id)$/i',
        '/^page url$/i',
        '/powered by wix/i',
        '/create your own (free )?website/i',
        '/^wix\.com$/i',
        '/this email was sent (to you )?via wix/i',
        '/^don\'t want to receive/i',
        '/^new form submission/i',
        '/kan du inte se meddelandet/iu',
        '/view submissions/i',
        '/det här mejl(?:at|et) har skickats som en avisering/iu',
        '/^kontakt\s+formulär$/iu',
    ];
    $by = [
        'wix-forms.com' => $wix,
        'crm.wix.com' => $wix,
        'wix.com' => $wix,
        'stores-emails.com' => $wix,
        'weply.se' => $weply = [
            '/via weply\b/iu',
            '/^weply(\s+ab)?$/iu',
            '/notis från weply/iu',
            '/ny förfrågan via weply/iu',
            '/weply\.(?:se|dk)\/(leads|unsubscribe|avregistrera)/i',
            '/,\s*weply\s*$/iu',
            '/du fick det här e-?mailet eftersom/iu',
            '/^id\s*#/iu',
            '/^kontakta oss\s+0\d/iu',
            '/den här chatten bemannas av människor/iu',
            '/vad händer nu\?/iu',
            '/ring leadet!?/iu',
            '/om du inte är den avsedda mottagaren/iu',
        ],
        'weply.dk' => $weply,
        'brabyggfirmor.se' => [
            '/bra byggfirmor/iu',
            '/brabyggfirmor\.se/i',
            '/logga in för att se förfrågan/iu',
        ],
        'leadkit.com' => $leadkit = [
            '/^ny lead från leadkit/iu',
            '/leadkit\b.{0,40}(fler leads|more leads|till ditt företag)/iu',
            '/leadkit\.com\/(app|unsubscribe|leads)/i',
            '/^leadkit$/iu',
            '/leadkit-(?:lead-id|field-)/i',
            '/\[leadkit inbox\]/i',
            '/hantera i leadkit inbox/iu',
            '/färska leads förfaller fort/iu',
            '/^ring\s+e-post\s*$/iu',
        ],
        'nettbureau.no' => $leadkit,
        'nettbureau.se' => $leadkit,
        'drip.se' => $drip = [
            '/\bdrip\b/iu',
            '/hitta-?solceller/iu',
            '/getdrip\.com/i',
            '/\[drip logo\]/i',
            '/lycka till!?\s*mvh gustav/iu',
            '/^lycka till!?\s*$/iu',
            '/^mvh[,!]?\s*$/iu',
            '/^gustav\s*(?:&|och)\s*alex\b/iu',
            '/^hitta-?solceller(?:\.se)?\s*$/iu',
            '/to make sure you keep getting these emails/i',
            '/minneapolis/i',
            '/^\d+\s+[NS]\s+\S+\s+(?:ave|avenue|street|st)\b/i',
            '/sendgrid\.net/i',
        ],
        'getdrip.com' => $drip,
        'drip.com' => $drip,
        'hitta-solceller.se' => [
            '/hitta-?solceller/iu',
            '/\bdrip\b/iu',
            '/du får detta (mejl )?eftersom/iu',
            '/^lycka till!?\s*$/iu',
            '/^mvh[,!]?\s*$/iu',
            '/^gustav\s*(?:&|och)\s*alex\b/iu',
        ],
        'hittasolceller.se' => [
            '/hitta-?solceller/iu',
            '/\bdrip\b/iu',
            '/^lycka till!?\s*$/iu',
            '/^mvh[,!]?\s*$/iu',
            '/^gustav\s*(?:&|och)\s*alex\b/iu',
        ],
        'solceller.nu' => array_merge([
            '/solceller\.nu/iu',
            '/^ny offertförfrågan\b/iu',
            '/ansluten till solceller/iu',
            '/klicka inte på länkarna/iu',
        ], $leadkit),
        'hemsol.se' => [
            '/mandrillapp\.com/i',
            '/hemsol\.se\/track/i',
        ],
        'mandrillapp.com' => [
            '/mandrillapp\.com/i',
        ],
        'offerta.se' => [
            '/\bofferta\b/iu',
            '/nytt uppdrag via offerta/iu',
            '/svara kunden direkt/iu',
            '/offerta\.se/i',
        ],
    ];
    return array_merge($common, $by[$platform] ?? []);
}

/** Osynliga tecken och NBSP-sviter. Namn och fri text lämnas kvar, utan ASCII-vikning. */
function jeab_inkorg_clean_invisibles(string $text): string
{
    $text = preg_replace('/[\x{00AD}\x{034F}\x{180E}\x{200B}-\x{200F}\x{2060}-\x{2064}\x{FEFF}]/u', '', $text) ?? $text;
    return preg_replace('/\x{00A0}+/u', ' ', $text) ?? $text;
}

/** Vinkelparenteslänkar, spårnings-URL:er och spårnings-id inne i en rad. Kundtexten runt omkring stannar. */
function jeab_inkorg_clean_scrub_line(string $line): string
{
    $line = jeab_inkorg_clean_invisibles($line);
    $line = preg_replace('/<(?:https?|tel|mailto):[^<>]*>/iu', '', $line) ?? $line;
    $line = preg_replace('#https?://[^\s<>]*(?:sendgrid\.net|mandrillapp\.com|list-manage\.com|trk\.|track\.|click\.|ct\.sendgrid|utm_|gclid|fbclid|fb\.1\.|EAIaIQ|wix\.com/(?:store|orders|track|dashboard)|stores\.wix|manage\.wix\.com)[^\s<>]*#iu', '', $line) ?? $line;
    $line = preg_replace('/\bfb\.1\.[A-Za-z0-9._\-]{6,}/', '', $line) ?? $line;
    $line = preg_replace('/\bEAIaIQ[A-Za-z0-9_\-]{6,}/', '', $line) ?? $line;
    $line = preg_replace('/\bCj[0-9A-Za-z][A-Za-z0-9_\-]{10,}/', '', $line) ?? $line;
    $line = preg_replace('/\b(?:gclid|fbclid|wbraid|gbraid|gclsrc|msclkid|dclid|ttclid|utm_[a-z0-9_]+)\s*[:=]\s*\S+/iu', '', $line) ?? $line;
    $line = preg_replace('/\b(?:gclid|fbclid)=[^\s&]{4,}/i', '', $line) ?? $line;
    $line = preg_replace('/[ ]{2,}/', ' ', $line) ?? $line;
    return rtrim($line);
}

function jeab_inkorg_clean_solceller_heading(string $line): bool
{
    return preg_match('/^nytt lead från solceller\.(?:nu|se)(?:\s*\/\s*(?:nu|se))?\s*$/iu', trim($line)) === 1;
}

/** Utdraget fält (produkt m.m.) efter att länkar och osynliga tecken är borta. */
function jeab_inkorg_clean_field(string $value): string
{
    return trim(jeab_inkorg_clean_scrub_line($value));
}

function jeab_inkorg_clean_drop_line(string $line, string $platform): bool
{
    $t = trim($line);
    if ($t === '') {
        return false;
    }
    if (jeab_inkorg_clean_solceller_heading($t)) {
        return false;
    }
    if (preg_match('#^https?://\S+$#i', $t) === 1) {
        return true;
    }
    if (preg_match('#^/[a-z0-9][a-z0-9_\-/]*$#i', $t) === 1) {
        return true;
    }
    if (preg_match('/^(?:gclid|fbclid|wbraid|gbraid|gclsrc|msclkid|dclid|ttclid|utm_[a-z0-9_]+)\s*[:=]/iu', $t) === 1) {
        return true;
    }
    foreach (jeab_inkorg_clean_patterns($platform) as $re) {
        if (preg_match($re, $t) === 1) {
            return true;
        }
    }
    return false;
}

/** Ser raden ut som kunddata (namn-fält, e-post, telefon, adress) som inte får läras bort. */
function jeab_inkorg_line_is_customer_data(string $line): bool
{
    $t = trim($line);
    if ($t === '') {
        return false;
    }
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $t) === 1) {
        return true;
    }
    if (preg_match('/(?:\+46|0046|0)\s?[1-9][\d\s\-]{6,}\d/', $t) === 1) {
        return true;
    }
    if (preg_match('/\b\d{3}\s?\d{2}\b/', $t) === 1) {
        return true;
    }
    if (preg_match('/\b[\p{L}]{2,}(?:\s+[\p{L}\.\-]+){0,4}\s+\d{1,4}[A-Za-z]?\b/u', $t) === 1) {
        return true;
    }
    return preg_match('/^(?:kundens\s+)?(?:namn|name|förnamn|efternamn|e-?post(?:adress)?|e-?mail|mail|telefon(?:nummer)?|tel|mobil(?:nummer)?|phone|adress|address|gatuadress|ort|postnummer|postnr|postort|stad|kommun|meddelande|message|produkt|product|tjänst|kategori|beskrivning|förfrågan|önskemål|övrigt)\b/iu', $t) === 1;
}

function jeab_inkorg_clean_norm_line(string $line): string
{
    $t = mb_strtolower(trim($line));
    $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
    return $t;
}

/**
 * Weply-chatten växlar kund och agent. Agentraderna är omärkta och följs av «Förnamn, Weply».
 * Varje chattrad kan ha en blankrad emellan. Bakåt hoppas blankrader över. Stopp vid kundens
 * namnrad, ett fält («Namn:», «E-post:» …), en talarrad eller en tidigare «X, Weply».
 * Upprepade namnrader blir en. Kundens egna rader och fält lämnas kvar.
 *
 * @param list<string> $lines
 * @return list<string>
 */
function jeab_inkorg_clean_weply_agents(array $lines): array
{
    $sig = '/^[\p{L}][\p{L}.\'’\-]{0,40}(?:\s+[\p{L}.\'’\-]{1,40}){0,3},\s*weply\s*$/iu';
    $field = '/^(?:kundens\s+)?(?:namn|name|förnamn|efternamn|e-?post(?:adress)?|e-?mail|mail|telefon(?:nummer)?|tel|mobil(?:nummer)?|phone|adress|address|gatuadress|ort|postnummer|postnr|postort|stad|kommun|meddelande|message|produkt|product|tjänst|kategori|beskrivning|förfrågan|önskemål|övrigt)\s*:/iu';
    $speaker = '/^[\p{L}][\p{L}.\'’\-]{0,40}(?:\s+[\p{L}.\'’\-]{1,40}){0,3}:\s+\S/u';
    $n = count($lines);
    $names = [];
    $remember = static function (string $value) use (&$names): void {
        $value = trim((string) (preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value)));
        if ($value === '' || mb_strlen($value) > 80 || str_contains($value, '@')) {
            return;
        }
        $names[mb_strtolower($value)] = true;
    };
    for ($i = 0; $i < $n; $i++) {
        $t = trim((string) $lines[$i]);
        if (preg_match('/^(?:kundens\s+)?(?:namn|name|kontaktperson)\s*:\s*(.*)$/iu', $t, $m) !== 1) {
            continue;
        }
        $v = trim((string) $m[1]);
        if ($v === '') {
            for ($k = $i + 1; $k < $n; $k++) {
                $nxt = trim((string) $lines[$k]);
                if ($nxt !== '') {
                    $v = $nxt;
                    break;
                }
            }
        }
        $remember($v);
    }
    $isCustomerName = static function (string $t) use ($names): bool {
        $t = trim($t);
        $t = preg_replace('/\s*:\s*$/u', '', $t) ?? $t;
        $t = trim((string) (preg_replace('/\s+/u', ' ', $t) ?? $t));
        return $t !== '' && isset($names[mb_strtolower($t)]);
    };
    $boundary = static function (string $t) use ($sig, $field, $speaker, $isCustomerName): bool {
        return preg_match($sig, $t) === 1
            || preg_match($field, $t) === 1
            || preg_match($speaker, $t) === 1
            || $isCustomerName($t);
    };
    $drop = array_fill(0, $n, false);
    for ($i = 0; $i < $n; $i++) {
        if (preg_match($sig, trim((string) $lines[$i])) !== 1) {
            continue;
        }
        $drop[$i] = true;
        for ($k = $i - 1; $k >= 0; $k--) {
            $t = trim((string) $lines[$k]);
            if ($t === '') {
                continue;
            }
            if ($boundary($t)) {
                break;
            }
            $drop[$k] = true;
        }
    }
    $out = [];
    $seenName = false;
    foreach ($lines as $i => $line) {
        if (!empty($drop[$i])) {
            continue;
        }
        if ($isCustomerName(trim((string) $line))) {
            if ($seenName) {
                continue;
            }
            $seenName = true;
        }
        $out[] = $line;
    }
    return $out;
}

/**
 * Sidfotens kontaktblock som hör till plattformens egen domän (Hemsol m.fl.).
 * Nakna etiketter, «[Hemsol]», «HemSol Group AB» och «Kontakta oss» räknas med.
 * Hittas blocket tas det bort tillsammans med känd eftertext (Kundomdömen, Trustpilot, TrustScore, sociala länkar) fram till slutet.
 * Kundens namn, e-post, telefon och adress ovanför rörs inte.
 *
 * @param list<string> $lines
 * @return list<string>
 */
function jeab_inkorg_clean_platform_footer(array $lines, string $platform): array
{
    $platform = mb_strtolower(trim($platform));
    if ($platform === '' || !str_contains($platform, '.')) {
        return $lines;
    }
    $dom = preg_quote($platform, '/');
    $n = count($lines);
    $hit = -1;
    for ($i = $n - 1; $i >= 0; $i--) {
        if (preg_match('/[A-Z0-9._%+\-]+@(?:[A-Z0-9.\-]+\.)?' . $dom . '\b/i', (string) $lines[$i]) === 1) {
            $hit = $i;
            break;
        }
    }
    if ($hit < 0) {
        return $lines;
    }
    $brand = preg_replace('/\.(?:se|com|nu|dk|no|net|org)$/i', '', $platform) ?? $platform;
    $brandQ = preg_quote((string) $brand, '/');
    $field = '/^(?:kundens\s+)?(?:namn|name|förnamn|efternamn|e-?post(?:adress)?|e-?mail|mail|telefon(?:nummer)?|tel|mobil(?:nummer)?|phone|adress|address|gatuadress|ort|postnummer|postnr|postort|stad|kommun|meddelande|message|produkt|product|tjänst|kategori|beskrivning|förfrågan|önskemål|övrigt)\s*:/iu';
    $looks = static function (string $line) use ($dom, $brandQ, $field): bool {
        $t = trim($line);
        if ($t === '') {
            return true;
        }
        if (preg_match($field, $t) === 1) {
            return false;
        }
        if (preg_match('/[A-Z0-9._%+\-]+@(?:[A-Z0-9.\-]+\.)?' . $dom . '\b/i', $t) === 1) {
            return true;
        }
        if ($brandQ !== '' && preg_match('/^\[?\s*' . $brandQ . '\b/iu', $t) === 1) {
            return true;
        }
        if (preg_match('/^kontakta oss\s*$/iu', $t) === 1) {
            return true;
        }
        if (preg_match('/^(?:e-?post|telefon|adress|hemsida|tel|mobil)\s*$/iu', $t) === 1) {
            return true;
        }
        if (preg_match('/^0\d{6,12}$/', preg_replace('/[\s\-]/', '', $t) ?? $t) === 1 && preg_match('/^[\d\s\-]+$/', $t) === 1) {
            return true;
        }
        if (preg_match('/(?:\+46|0046|0)\s?[1-9](?:[\d\s\-]){5,}\d/u', $t) === 1) {
            return true;
        }
        if (preg_match('/\b[\p{L}]{3,}(?:gatan|vägen|gränd|plan|torg)\s+\d+/iu', $t) === 1) {
            return true;
        }
        if (preg_match('/^\d{3}\s?\d{2}(?:\s+\p{L}+)?$/u', $t) === 1) {
            return true;
        }
        if (preg_match('/^(?:https?:\/\/)?(?:www\.)?' . $dom . '\s*$/iu', $t) === 1) {
            return true;
        }
        return false;
    };
    $trailer = static function (string $line): bool {
        $t = trim($line);
        if ($t === '') {
            return true;
        }
        if (preg_match('/kundomdömen|kundomdomen|trustpilot|trustscore|se vad våra kunder säger/iu', $t) === 1) {
            return true;
        }
        if (preg_match('/^(?:★|\*)/u', $t) === 1) {
            return true;
        }
        if (preg_match('/^\[.+\]$/u', $t) === 1) {
            return true;
        }
        if (preg_match('/\b(?:facebook|instagram|linkedin|youtube|tiktok|twitter)\b|\bx\.com\b/iu', $t) === 1) {
            return true;
        }
        return false;
    };
    $lo = $hit;
    $hi = $hit;
    $steps = 0;
    while ($lo > 0 && $steps < 40 && $looks((string) $lines[$lo - 1])) {
        $lo--;
        $steps++;
    }
    $steps = 0;
    while ($hi + 1 < $n && $steps < 40 && $looks((string) $lines[$hi + 1])) {
        $hi++;
        $steps++;
    }
    while ($hi + 1 < $n && $trailer((string) $lines[$hi + 1])) {
        $hi++;
    }
    $out = [];
    foreach ($lines as $i => $line) {
        if ($i < $lo || $i > $hi) {
            $out[] = $line;
        }
    }
    return $out;
}

/** Citatrubrik: svensk eller engelsk svarshistorik, eller Outlooks ursprungliga meddelande. */
function jeab_inkorg_clean_quote_header(string $line): bool
{
    $t = trim($line);
    if ($t === '') {
        return false;
    }
    if (preg_match('/^-{3,}\s*(?:ursprungligt meddelande|original message|forwarded message|vidarebefordrat meddelande)\s*-{0,}$/iu', $t) === 1) {
        return true;
    }
    if (preg_match('/\d{1,2}\s+\p{L}{2,12}\.?\s+\d{4}\s+kl\.?\s+\d{1,2}[:.]\d{2}.+\bskrev\b/iu', $t) === 1) {
        return true;
    }
    if (preg_match('/^den\s+\d{1,2}\s+\p{L}{2,12}\.?\s+\d{4}.+\bskrev\b/iu', $t) === 1) {
        return true;
    }
    return preg_match('/^on\s+.{0,180}\bwrote\s*:?\s*$/iu', $t) === 1;
}

/**
 * Första raden i citerad historik. $n när mejlet inte har ett citat.
 *
 * @param list<string> $lines
 */
function jeab_inkorg_quote_start(array $lines): int
{
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $t = trim((string) $lines[$i]);
        if (jeab_inkorg_clean_quote_header($t)) {
            return $i;
        }
        if (preg_match('/^(?:från|from)\s*:/iu', $t) !== 1) {
            continue;
        }
        $window = '';
        for ($j = $i + 1; $j < min($n, $i + 7); $j++) {
            $window .= "\n" . trim((string) $lines[$j]);
        }
        if (preg_match('/^(?:skickat|sent|till|to|ämne|subject|datum|date)\s*:/imu', $window) === 1) {
            return $i;
        }
    }

    return $n;
}

/**
 * Klipper citerad historik. Rader som börjar med «>» faller bort.
 * «Från:» klipps bara när en följande rad är Skickat, Till, Ämne eller Datum,
 * så ett formulärfält «Från: Anna» lämnas kvar.
 *
 * @param list<string> $lines
 * @return list<string>
 */
function jeab_inkorg_clean_cut_quotes(array $lines): array
{
    $cut = jeab_inkorg_quote_start($lines);
    $out = [];
    for ($i = 0; $i < $cut; $i++) {
        if (preg_match('/^\s*>/', (string) $lines[$i]) === 1) {
            continue;
        }
        $out[] = (string) $lines[$i];
    }

    return $out;
}

/** Samma text som ett äldre mejl, så den inte får ersätta ett nytt svar. */
function jeab_inkorg_text_repeats_older(string $text, array $older): bool
{
    $norm = function_exists('jeab_inkorg_body_norm') ? jeab_inkorg_body_norm($text) : mb_strtolower(trim($text));
    if ($norm === '' || mb_strlen($norm) < 12) {
        return false;
    }
    foreach ($older as $old) {
        $have = function_exists('jeab_inkorg_body_norm') ? jeab_inkorg_body_norm((string) $old) : mb_strtolower(trim((string) $old));
        if ($have === '') {
            continue;
        }
        if ($norm === $have || (mb_strlen($have) >= 12 && str_contains($have, $norm)) || (mb_strlen($norm) >= 40 && str_contains($norm, $have))) {
            return true;
        }
    }

    return false;
}

/**
 * Ny text i ett svar. Text före citatet vinner. Saknas den behålls stycken efter citatet
 * som inte är det äldre mejlet.
 *
 * @param list<string> $older
 */
function jeab_inkorg_fresh_reply_text(string $raw, array $older = []): string
{
    $lines = preg_split("/\n/", str_replace("\r", '', $raw)) ?: [];
    $cut = jeab_inkorg_quote_start($lines);
    $prefix = [];
    for ($i = 0; $i < $cut; $i++) {
        if (preg_match('/^\s*>/', (string) $lines[$i]) === 1) {
            continue;
        }
        $prefix[] = (string) $lines[$i];
    }
    $pre = trim(implode("\n", $prefix));
    if ($cut >= count($lines)) {
        return $pre;
    }
    $paras = [];
    $buf = [];
    $flush = static function () use (&$buf, &$paras): void {
        $text = trim(implode("\n", $buf));
        $buf = [];
        if ($text !== '') {
            $paras[] = $text;
        }
    };
    $n = count($lines);
    for ($i = $cut; $i < $n; $i++) {
        $line = (string) $lines[$i];
        $t = trim($line);
        if ($t === '') {
            $flush();
            continue;
        }
        if (preg_match('/^\s*>/', $line) === 1 || jeab_inkorg_clean_quote_header($t) || preg_match('/^(?:från|from|skickat|sent|till|to|ämne|subject|datum|date|cc)\s*:/iu', $t) === 1) {
            $flush();
            continue;
        }
        $buf[] = $line;
    }
    $flush();
    $kept = [];
    foreach ($paras as $para) {
        if (!jeab_inkorg_text_repeats_older($para, $older)) {
            $kept[] = $para;
        }
    }
    $parts = [];
    if ($pre !== '' && !jeab_inkorg_text_repeats_older($pre, $older)) {
        $parts[] = $pre;
    }
    foreach ($kept as $para) {
        $parts[] = $para;
    }
    if ($parts !== []) {
        return trim(implode("\n\n", $parts));
    }

    return $pre;
}

/**
 * Text vid öppning. Bara senaste inkommande mejlet rensas mot tidigare svar.
 * Äldre meddelanden klipps så en lång tråd inte blockerar öppningen.
 *
 * @param list<string> $older
 * @return array{clean: string, raw: string}
 */
function jeab_inkorg_open_message_text(array $msg, bool $latest, array $older = []): array
{
    $body = (string) ($msg['body'] ?? '');
    if (strlen($body) > 20000) {
        $body = substr($body, 0, 20000);
    }
    $note = !empty($msg['internal_note']) || (string) ($msg['hs_thread_type'] ?? '') === 'note';
    $own = !$note && (str_starts_with((string) ($msg['source'] ?? ''), 'outbound-') || (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address((string) ($msg['from'] ?? ''))));
    if (!$latest) {
        $plain = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</tr>'], "\n", $body)));
        $plain = trim((string) preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', $plain)));
        $plain = trim((string) preg_replace("/(\s*\n){3,}/", "\n\n", $plain));
        if (mb_strlen($plain) > 280) {
            $plain = rtrim(mb_substr($plain, 0, 280)) . '…';
        }

        return ['clean' => $plain, 'raw' => $plain];
    }
    $msg['body'] = $body;
    $raw = function_exists('jeab_inkorg_strip_hs_junk')
        ? jeab_inkorg_strip_hs_junk(trim((string) preg_replace("/(\s*\n){3,}/", "\n\n", str_replace("\r", '', $body))))
        : $body;
    if ($note || $own || !function_exists('jeab_inkorg_message_visible')) {
        return ['clean' => $raw, 'raw' => $raw];
    }
    $snips = [];
    foreach ($older as $old) {
        if (count($snips) >= 6) {
            break;
        }
        $snips[] = mb_substr(trim(strip_tags((string) $old)), 0, 400);
    }

    $clean = jeab_inkorg_message_visible($msg, $snips);
    if (function_exists('jeab_text_drop_duplicate_via_lines')) {
        $clean = jeab_text_drop_duplicate_via_lines($clean);
        $raw = jeab_text_drop_duplicate_via_lines($raw);
    }

    return ['clean' => $clean, 'raw' => $raw];
}

/**
 * Texten som ska synas. Rensning får inte byta ut det nya svaret mot citatet,
 * och ett tomt resultat faller tillbaka på den nya texten.
 *
 * @param list<string> $older
 */
function jeab_inkorg_message_visible(array $msg, array $older = []): string
{
    $raw = jeab_inkorg_strip_hs_junk(trim((string) preg_replace("/(\s*\n){3,}/", "\n\n", str_replace("\r", '', (string) ($msg['body'] ?? '')))));
    $note = !empty($msg['internal_note']) || (string) ($msg['hs_thread_type'] ?? '') === 'note';
    $own = !$note && (str_starts_with((string) ($msg['source'] ?? ''), 'outbound-') || (function_exists('jeab_inkorg_own_address') && jeab_inkorg_own_address((string) ($msg['from'] ?? ''))));
    if ($note || $own) {
        return $raw;
    }
    $clean = trim(jeab_inkorg_clean_message($msg));
    $lines = preg_split("/\n/", str_replace("\r", '', $raw)) ?: [];
    $hasQuote = jeab_inkorg_quote_start($lines) < count($lines);
    if (!$hasQuote) {
        return $clean !== '' ? $clean : $raw;
    }
    $fresh = trim(jeab_inkorg_fresh_reply_text($raw, $older));
    if ($fresh !== '' && !jeab_inkorg_text_repeats_older($fresh, $older)) {
        return $fresh;
    }
    if ($clean !== '' && !jeab_inkorg_text_repeats_older($clean, $older)) {
        return $clean;
    }

    return $fresh !== '' ? $fresh : ($clean !== '' ? $clean : $raw);
}

/** HTML bort, tabellceller till «Etikett: värde», plattformens standardrader bort. */
function jeab_inkorg_clean_prepared(array $msg): string
{
    $platform = jeab_inkorg_clean_platform($msg);
    $text = jeab_inkorg_html_to_text((string) ($msg['body'] ?? ''));
    $text = jeab_inkorg_strip_hs_junk($text);
    $text = jeab_inkorg_clean_invisibles($text);
    // Malmö «Kontakt Formulär» klistras på e-posten. Dela dem så adressen går att läsa.
    $text = preg_replace('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})(Kontakt\s+Formulär)/iu', "$1\n$2", $text) ?? $text;
    $text = preg_replace('/(Kontakt\s+Formulär)([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/iu', "$1\n$2", $text) ?? $text;
    $rawLines = [];
    foreach (preg_split("/\n/", $text) ?: [] as $line) {
        $rawLines[] = jeab_inkorg_clean_scrub_line(rtrim((string) $line));
    }
    $product = '';
    if (in_array($platform, ['wix-forms.com', 'crm.wix.com', 'wix.com', 'stores-emails.com'], true)) {
        foreach ($rawLines as $line) {
            if (preg_match('/submitted your form[:\s]+(.+)$/iu', (string) $line, $m) === 1) {
                $product = jeab_inkorg_clean_field(trim((string) $m[1]));
                break;
            }
        }
    }
    if (in_array($platform, ['weply.se', 'weply.dk'], true)) {
        $rawLines = jeab_inkorg_clean_weply_agents($rawLines);
    }
    $rawLines = jeab_inkorg_clean_cut_quotes($rawLines);
    $out = [];
    $seenSolHeading = false;
    $cutParagraph = false;
    $leadkit = in_array($platform, ['leadkit.com', 'nettbureau.no', 'nettbureau.se', 'solceller.nu'], true);
    $weply = in_array($platform, ['weply.se', 'weply.dk'], true);
    foreach ($rawLines as $line) {
        $line = (string) $line;
        if (str_contains($line, "\t")) {
            $parts = array_values(array_filter(array_map(static fn(string $p): string => jeab_inkorg_clean_field($p), explode("\t", $line)), static fn(string $p): bool => $p !== ''));
            if (count($parts) >= 2) {
                $line = $parts[0] . ': ' . implode(' ', array_slice($parts, 1));
            } else {
                $line = $parts[0] ?? '';
            }
        }
        $trim = trim($line);
        if ($cutParagraph) {
            if ($trim === '') {
                $cutParagraph = false;
            }
            continue;
        }
        if ($weply && preg_match('/den här chatten bemannas av människor/iu', $trim) === 1) {
            $cutParagraph = true;
            continue;
        }
        if ($weply && preg_match('/om du inte är den avsedda mottagaren/iu', $trim) === 1) {
            break;
        }
        if ($leadkit && preg_match('/vad är leadkit inbox/iu', $trim) === 1) {
            break;
        }
        if (jeab_inkorg_clean_solceller_heading($trim)) {
            if ($seenSolHeading) {
                continue;
            }
            $seenSolHeading = true;
            $out[] = $trim;
            continue;
        }
        if (jeab_inkorg_clean_drop_line($line, $platform)) {
            continue;
        }
        $out[] = $line;
    }
    $out = jeab_inkorg_clean_platform_footer($out, $platform);
    $text = implode("\n", $out);
    if ($product !== '' && preg_match('/^produkt\s*:/imu', $text) !== 1) {
        $text = 'Produkt: ' . $product . "\n" . $text;
    }
    return $text;
}

/** @return array<string, int> */
function jeab_inkorg_boilerplate_counts(string $key): array
{
    static $cache = [];
    try {
        $fp = function_exists('jeab_inkorg_messages_fp') ? jeab_inkorg_messages_fp() : '0';
    } catch (Throwable) {
        $fp = 'nosql';
    }
    $ck = $key . '|' . $fp;
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    $disk = function_exists('jeab_inkorg_dir') ? jeab_inkorg_dir() . '/.cache/boilerplate.json' : '';
    $saved = $disk !== '' && is_file($disk) ? json_decode((string) file_get_contents($disk), true) : null;
    if (is_array($saved) && (string) ($saved['fp'] ?? '') === $fp && isset($saved['counts'][$key]) && is_array($saved['counts'][$key])) {
        return $cache[$ck] = $saved['counts'][$key];
    }
    $opening = function_exists('jeab_inkorg_ix_part') && jeab_inkorg_ix_part() === 'thread';
    if ($opening) {
        $stale = is_array($saved) && isset($saved['counts'][$key]) && is_array($saved['counts'][$key])
            ? $saved['counts'][$key]
            : [];

        return $cache[$ck] = $stale;
    }
    $counts = [];
    if ($fp === 'nosql' || !function_exists('jeab_inkorg_messages')) {
        return $cache[$ck] = [];
    }
    foreach (jeab_inkorg_messages() as $m) {
        if (!is_array($m) || jeab_inkorg_clean_source_key($m) !== $key) {
            continue;
        }
        $seen = [];
        foreach (preg_split("/\n/", jeab_inkorg_clean_prepared($m)) ?: [] as $line) {
            $norm = jeab_inkorg_clean_norm_line((string) $line);
            if ($norm === '' || isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $counts[$norm] = ($counts[$norm] ?? 0) + 1;
        }
    }
    $cache[$ck] = $counts;
    if ($disk !== '') {
        $dir = dirname($disk);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $all = is_array($saved) && (string) ($saved['fp'] ?? '') === $fp ? (array) ($saved['counts'] ?? []) : [];
        $all[$key] = $counts;
        @file_put_contents($disk, json_encode(['fp' => $fp, 'counts' => $all], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    return $counts;
}

function jeab_inkorg_clean_repeated(string $text, array $msg): string
{
    $counts = jeab_inkorg_boilerplate_counts(jeab_inkorg_clean_source_key($msg));
    $out = [];
    foreach (preg_split("/\n/", $text) ?: [] as $line) {
        $norm = jeab_inkorg_clean_norm_line((string) $line);
        if ($norm !== '' && ($counts[$norm] ?? 0) >= 3 && !jeab_inkorg_line_is_customer_data((string) $line)) {
            continue;
        }
        $out[] = (string) $line;
    }
    return implode("\n", $out);
}

/** «Formulär via …» och «Köpt lead via …» ska stå en gång, även på samma rad. */
function jeab_text_drop_duplicate_via_lines(string $text): string
{
    $pattern = '/(?:Formulär|Köpt lead) via \S+/u';
    $seen = false;
    $out = [];
    foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
        if (preg_match($pattern, $line) !== 1) {
            $out[] = $line;
            continue;
        }
        $line = preg_replace_callback($pattern, static function (array $m) use (&$seen): string {
            if ($seen) {
                return '';
            }
            $seen = true;

            return $m[0];
        }, $line) ?? $line;
        $line = preg_replace('/[ \t]{2,}/u', ' ', $line) ?? $line;
        if (trim($line) === '') {
            continue;
        }
        $out[] = $line;
    }

    return implode("\n", $out);
}

function jeab_inkorg_clean_collapse(string $text): string
{
    $lines = preg_split("/\n/", str_replace("\r", '', $text)) ?: [];
    $out = [];
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = trim((string) $lines[$i]);
        if (preg_match('/^(?:kundens\s+)?(?:namn|name|förnamn|efternamn|e-?post(?:adress)?|e-?mail|telefon(?:nummer)?|tel|mobil(?:nummer)?|phone|adress|address|gatuadress|ort|postnummer|postnr|meddelande|message|produkt|product|tjänst|kategori|beskrivning|förfrågan|övrigt)\s*:\s*$/iu', $line) === 1) {
            $j = $i + 1;
            while ($j < $n && trim((string) $lines[$j]) === '') {
                $j++;
            }
            $next = $j < $n ? trim((string) $lines[$j]) : '';
            if ($next !== '' && preg_match('/:\s*$/', $next) !== 1) {
                $out[] = $line . ' ' . $next;
                $i = $j;
                continue;
            }
        }
        $out[] = $line;
    }
    $text = implode("\n", $out);
    $text = jeab_text_drop_duplicate_via_lines($text);
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
    return trim($text);
}

/**
 * Bara läsning. threads = id-lista. Returnerar rensad text och kundens e-post.
 */
function jeab_inkorg_clean_preview(array $input): array
{
    $ids = $input['threads'] ?? $input['ids'] ?? $input['thread'] ?? [];
    if (is_string($ids)) {
        $ids = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $ids) ?: [])));
    }
    if (!is_array($ids)) {
        $ids = [];
    }
    $ids = array_values(array_unique(array_map('strval', $ids)));
    if ($ids === []) {
        return ['ok' => false, 'error' => 'Ange tråd-id i threads.'];
    }
    $want = array_fill_keys($ids, true);
    $out = [];
    $seen = [];
    foreach (function_exists('jeab_inkorg_threads') ? jeab_inkorg_threads() : [] as $t) {
        $tid = (string) $t['id'];
        $msgIds = array_map(static fn($m) => (string) ($m['id'] ?? ''), (array) $t['messages']);
        if (!isset($want[$tid]) && array_intersect(array_keys($want), $msgIds) === []) {
            continue;
        }
        $seen[$tid] = true;
        foreach ($msgIds as $mid) {
            $seen[$mid] = true;
        }
        $cust = function_exists('jeab_inkorg_resolve_thread_customer')
            ? jeab_inkorg_resolve_thread_customer($t)
            : ['email' => '', 'source' => 'none'];
        $messages = [];
        foreach ($t['messages'] as $m) {
            if (str_starts_with((string) ($m['source'] ?? ''), 'outbound-')) {
                continue;
            }
            $messages[] = ['id' => (string) ($m['id'] ?? ''), 'clean' => jeab_inkorg_clean_message($m)];
        }
        $out[] = [
            'thread' => $tid,
            'customer_email' => (string) ($cust['email'] ?? ''),
            'source' => (string) ($cust['source'] ?? ''),
            'clean' => (string) ($messages[0]['clean'] ?? ''),
            'messages' => $messages,
        ];
    }
    $missing = [];
    foreach ($ids as $id) {
        if (!isset($seen[$id])) {
            $missing[] = $id;
        }
    }
    return ['ok' => true, 'read_only' => true, 'threads' => $out, 'missing' => $missing];
}

/** Rensad text för visning. Lagrad brödtext lämnas orörd. */
function jeab_inkorg_clean_message(array $msg): string
{
    $text = jeab_inkorg_clean_prepared($msg);
    $text = jeab_inkorg_clean_repeated($text, $msg);
    return jeab_inkorg_clean_collapse($text);
}
