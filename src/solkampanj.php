<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Manuellt solmejl till befintliga Järfälla-kunder. En mottagare i taget.
 * Listan och eventuell offert-PDF ligger i privat/, aldrig i webbroten.
 * Företagsbroschyren ligger i src/solkampanj/brochure.php och bifogas i solutskicket.
 * Batteribroschyren ligger i src/solkampanj/battery.php och bifogas bara i batteriutskicket.
 * Den gamla .pdf-adressen är en platshållare så en deploy skriver över filen på plats.
 * Utskicket går genom jeab_system_mail_send (Help Scout först, Graph från
 * demo@example.test när Help Scout inte kan vara den avsändaren).
 */

const JEAB_SOLKAMPANJ_FROM = 'demo@example.test';
const JEAB_SOLKAMPANJ_FROM_NAME = 'Demo Säljare';
const JEAB_SOLKAMPANJ_TEST_TO = 'demo.person@example.invalid';
const JEAB_SOLKAMPANJ_CID = 'solkampanj-bild';
const JEAB_SOLKAMPANJ_BROCHURE_NAME = 'Demo Energi Järfälla - Solceller.pdf';
const JEAB_SOLKAMPANJ_BATTERY_NAME = 'Demo Energi Järfälla - Batteri.pdf';
const JEAB_SOLKAMPANJ_EXCLUDE_BY = 'exclude:fortnox>50k';
/** Kampanjen gäller Järfälla: bara kontakter i det bolaget får länkas. */
const JEAB_SOLKAMPANJ_COMPANY = 'jarfalla';

function jeab_solkampanj_copy(): array
{
    return [
        'subject' => 'Solceller till {gata}?',
        'hello' => 'Hej {first},',
        'intro' => 'Vi har ju varit i kontakt tidigare om laddbox, jag tänkte om ni kanske funderat på solceller?',
        'offer' => 'Nedan är {antal} paneler som är optimalt för ett hus som konsumerar mindre el än {kwh} kWh i maj eller juni. Med 15 kWh batteri kostar det {pris} kr. Är det intressant att titta närmare på?',
        'inspections' => 'Här är alla publika besiktningar installerade av oss och många andra så att du kan välja en installatör som motsvarar dina förväntningar: https://www.solenergikvalitet.se/leverantorer',
    ];
}

function jeab_solkampanj_dir(): string
{
    $forced = $GLOBALS['_JEAB_SOLKAMPANJ_DIR'] ?? null;
    if (is_string($forced) && trim($forced) !== '') {
        $dir = rtrim(str_replace('\\', '/', $forced), '/');
    } else {
        $base = function_exists('jeab_outside_privat_dir') ? jeab_outside_privat_dir() : dirname(JEAB_ROOT) . '/privat';
        $dir = rtrim(str_replace('\\', '/', $base), '/') . '/solkampanj';
    }
    if ($dir === '' || str_contains($dir, "\0")) {
        throw new RuntimeException('Mottagarlistan saknar en plats utanför webbroten.');
    }
    $web = function_exists('jeab_app_web_root') ? rtrim(str_replace('\\', '/', jeab_app_web_root()), '/') : '';
    if ($web !== '' && ($dir === $web || str_starts_with($dir, $web . '/'))) {
        throw new RuntimeException('Mottagarlistan får inte ligga i webbroten.');
    }

    return $dir;
}

function jeab_solkampanj_ensure_dir(): string
{
    $dir = jeab_solkampanj_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Kan inte spara mottagarlistan utanför webbroten.');
    }
    $real = realpath($dir);
    $web = function_exists('jeab_app_web_root') ? realpath(jeab_app_web_root()) : false;
    if (is_string($real) && is_string($web) && $web !== '' && ($real === $web || str_starts_with($real . '/', $web . '/'))) {
        throw new RuntimeException('Mottagarlistan får inte ligga i webbroten.');
    }

    return $dir;
}

function jeab_solkampanj_store_bytes(string $name, string $bytes): string
{
    if (!in_array($name, ['recipients.csv', 'soloofferten.pdf', 'settings.json', 'orts.json'], true)) {
        throw new InvalidArgumentException('Filen får inte sparas.');
    }
    $dir = jeab_solkampanj_ensure_dir();
    $path = $dir . '/' . $name;
    $tmp = $dir . '/.up-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $bytes, LOCK_EX) === false) {
        throw new RuntimeException('Kunde inte skriva filen.');
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Kunde inte skriva filen.');
    }
    @chmod($path, 0600);

    return $path;
}

function jeab_solkampanj_test_mode(): bool
{
    $path = jeab_solkampanj_dir() . '/settings.json';
    if (!is_file($path)) {
        return true;
    }
    $json = json_decode((string) file_get_contents($path), true);
    if (!is_array($json) || !array_key_exists('test_mode', $json)) {
        return true;
    }
    $flag = $json['test_mode'];

    return $flag === true || $flag === 1 || $flag === '1';
}

function jeab_solkampanj_set_test_mode(bool $on): void
{
    $json = json_encode(['test_mode' => $on], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Kunde inte spara testläget.');
    }
    jeab_solkampanj_store_bytes('settings.json', $json . "\n");
}

function jeab_solkampanj_pdf_path(): string
{
    return jeab_solkampanj_dir() . '/soloofferten.pdf';
}

/** PHP-fil som lämnar PDF-bytes vid include och 403 när den anropas som sida. */
function jeab_solkampanj_brochure_path(): string
{
    $root = defined('JEAB_ROOT') ? (string) JEAB_ROOT : dirname(__DIR__);

    return rtrim(str_replace('\\', '/', $root), '/') . '/src/solkampanj/brochure.php';
}

/** Gamla publika sökvägen. Deploy skriver över den på plats, den raderas inte. */
function jeab_solkampanj_brochure_placeholder_path(): string
{
    $root = defined('JEAB_ROOT') ? (string) JEAB_ROOT : dirname(__DIR__);

    return rtrim(str_replace('\\', '/', $root), '/') . '/src/solkampanj/jarfalla-energi-solceller.pdf';
}

/** @return array{filename:string,content_type:string,content:string}|null */
function jeab_solkampanj_brochure_attachment(): ?array
{
    $path = jeab_solkampanj_brochure_path();
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return null;
    }
    $bytes = include $path;
    if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-')) {
        return null;
    }

    return [
        'filename' => JEAB_SOLKAMPANJ_BROCHURE_NAME,
        'content_type' => 'application/pdf',
        'content' => $bytes,
    ];
}

/** PHP-fil som lämnar batteri-PDF vid include och 403 när den anropas som sida. */
function jeab_solkampanj_battery_path(): string
{
    $root = defined('JEAB_ROOT') ? (string) JEAB_ROOT : dirname(__DIR__);

    return rtrim(str_replace('\\', '/', $root), '/') . '/src/solkampanj/battery.php';
}

/** @return array{filename:string,content_type:string,content:string}|null */
function jeab_solkampanj_battery_attachment(): ?array
{
    $path = jeab_solkampanj_battery_path();
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return null;
    }
    $bytes = include $path;
    if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-')) {
        return null;
    }

    return [
        'filename' => JEAB_SOLKAMPANJ_BATTERY_NAME,
        'content_type' => 'application/pdf',
        'content' => $bytes,
    ];
}

function jeab_solkampanj_pdf_ready(): bool
{
    $path = jeab_solkampanj_pdf_path();
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }
    $head = (string) file_get_contents($path, false, null, 0, 5);

    return str_starts_with($head, '%PDF-');
}

function jeab_solkampanj_save_pdf(string $bytes): void
{
    if (!str_starts_with($bytes, '%PDF-')) {
        throw new InvalidArgumentException('Filen måste vara en PDF.');
    }
    if (strlen($bytes) > 15000000) {
        throw new InvalidArgumentException('PDF-filen är för stor.');
    }
    jeab_solkampanj_store_bytes('soloofferten.pdf', $bytes);
}

function jeab_solkampanj_pdo(): PDO
{
    if (!function_exists('jeab_store')) {
        throw new RuntimeException('SQL saknas.');
    }
    $store = jeab_store();
    if (!$store instanceof JeabPdoStore) {
        throw new RuntimeException('SQL saknas.');
    }

    return $store->pdo();
}

function jeab_solkampanj_driver(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'mysql' : 'sqlite';
}

function jeab_solkampanj_values_sql(PDO $pdo): string
{
    return jeab_solkampanj_driver($pdo) === 'mysql' ? '`values`' : '"values"';
}

function jeab_solkampanj_ensure(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    if (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo)) {
        return;
    }
    $done[$key] = true;
    if (jeab_solkampanj_driver($pdo) === 'mysql') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS solkampanj_recipients (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_number VARCHAR(64) NOT NULL DEFAULT \'\',
                namn VARCHAR(191) NOT NULL DEFAULT \'\',
                first_name VARCHAR(191) NOT NULL DEFAULT \'\',
                mejl VARCHAR(191) NOT NULL DEFAULT \'\',
                gata VARCHAR(255) NOT NULL DEFAULT \'\',
                postnr VARCHAR(32) NOT NULL DEFAULT \'\',
                ort VARCHAR(191) NOT NULL DEFAULT \'\',
                status VARCHAR(16) NOT NULL DEFAULT \'pending\',
                sent_at VARCHAR(40) NULL,
                sent_by VARCHAR(191) NOT NULL DEFAULT \'\',
                `values` JSON NULL,
                PRIMARY KEY (id),
                KEY solkampanj_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        jeab_solkampanj_ensure_contact($pdo);
        jeab_solkampanj_normalize_orts($pdo);

        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS solkampanj_recipients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_number TEXT NOT NULL DEFAULT \'\',
            namn TEXT NOT NULL DEFAULT \'\',
            first_name TEXT NOT NULL DEFAULT \'\',
            mejl TEXT NOT NULL DEFAULT \'\',
            gata TEXT NOT NULL DEFAULT \'\',
            postnr TEXT NOT NULL DEFAULT \'\',
            ort TEXT NOT NULL DEFAULT \'\',
            status TEXT NOT NULL DEFAULT \'pending\',
            sent_at TEXT,
            sent_by TEXT NOT NULL DEFAULT \'\',
            "values" TEXT NOT NULL DEFAULT \'{}\'
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS solkampanj_status ON solkampanj_recipients (status)');
    jeab_solkampanj_ensure_contact($pdo);
    jeab_solkampanj_normalize_orts($pdo);
}

/** contact_id på mottagaren, för contacts.solkampanj_link. Listan droppas inte. */
function jeab_solkampanj_ensure_contact(PDO $pdo): void
{
    if (jeab_solkampanj_has_column($pdo, 'contact_id')) {
        return;
    }
    $pdo->exec(jeab_solkampanj_driver($pdo) === 'mysql'
        ? 'ALTER TABLE solkampanj_recipients ADD COLUMN contact_id VARCHAR(64) NOT NULL DEFAULT \'\''
        : 'ALTER TABLE solkampanj_recipients ADD COLUMN contact_id TEXT NOT NULL DEFAULT \'\'');
    if (function_exists('jeab_copy_columns_forget')) {
        jeab_copy_columns_forget();
    }
}

function jeab_solkampanj_has_column(PDO $pdo, string $column): bool
{
    try {
        $rows = jeab_solkampanj_driver($pdo) === 'mysql'
            ? $pdo->query('SHOW COLUMNS FROM solkampanj_recipients')->fetchAll(PDO::FETCH_ASSOC)
            : $pdo->query('PRAGMA table_info(solkampanj_recipients)')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return false;
    }
    foreach (is_array($rows) ? $rows : [] as $col) {
        if ((string) ($col['Field'] ?? $col['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

/** Listans egna fält. contacts.drop_customer_fields rör dem inte. */
function jeab_solkampanj_copy_cols(): array
{
    return ['namn', 'first_name', 'mejl', 'gata'];
}

function jeab_solkampanj_col_present(PDO $pdo, string $col): bool
{
    // Schemat, inte kopie-markören. En äldre drop som tog bort kolumnen syns fortfarande.
    return jeab_solkampanj_has_column($pdo, $col);
}

/**
 * Central SELECT-lista. Saknas ett eget fält (äldre drop) blir det NULL AS kolumn, och jeab_solkampanj_hydrate fyller från kontakten.
 *
 * @param list<string> $cols
 */
function jeab_solkampanj_select(PDO $pdo, array $cols): string
{
    $out = [];
    foreach ($cols as $col) {
        if (in_array($col, jeab_solkampanj_copy_cols(), true) && !jeab_solkampanj_col_present($pdo, $col)) {
            $out[] = 'NULL AS ' . $col;
            continue;
        }
        $out[] = $col;
    }
    if (!in_array('contact_id', $cols, true)) {
        $out[] = jeab_solkampanj_has_column($pdo, 'contact_id') ? 'contact_id' : "'' AS contact_id";
    }

    return implode(', ', $out);
}

/**
 * Central inläsare. Ett eget fält som saknas (kolumnen borta efter en äldre drop) läses från kontakten via contact_id.
 * Ett fält som finns kvar används som det är. Listan droppas inte.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function jeab_solkampanj_hydrate(array $row): array
{
    $missing = false;
    foreach (jeab_solkampanj_copy_cols() as $col) {
        if (array_key_exists($col, $row) && $row[$col] === null) {
            $missing = true;
        }
    }
    if (!$missing) {
        return $row;
    }
    $contactId = trim((string) ($row['contact_id'] ?? ''));
    $contact = $contactId !== '' && function_exists('jeab_kontakt_get') ? jeab_kontakt_get($contactId) : null;
    $view = is_array($contact) && function_exists('jeab_contacts_sole_view_from_contact') ? jeab_contacts_sole_view_from_contact($contact) : [];
    $from = [
        'namn' => trim((string) ($view['name'] ?? '')),
        'mejl' => strtolower(trim((string) ($view['email'] ?? ''))),
        'gata' => trim((string) ($view['street'] ?? '')),
    ];
    foreach ($from as $col => $value) {
        if (array_key_exists($col, $row) && $row[$col] === null) {
            $row[$col] = $value;
        }
    }
    if (array_key_exists('first_name', $row) && $row['first_name'] === null) {
        $row['first_name'] = jeab_solkampanj_first_name((string) ($row['namn'] ?? ''));
    }

    return $row;
}

/** Kontaktens bolag (jarfalla/malmo) eller '' om det inte går att avgöra. */
function jeab_solkampanj_contact_company(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (function_exists('jeab_address_company')) {
        return jeab_address_company($raw);
    }
    $low = strtolower($raw);

    return in_array($low, ['jarfalla', 'malmo'], true) ? $low : '';
}

/**
 * Index över kontakter: mejl och Fortnox-kundnummer => kontakt-id, och bolaget per kontakt.
 *
 * @return array{email:array<string, array<string, true>>,number:array<string, array<string, true>>,company:array<string, string>}
 */
function jeab_solkampanj_contact_index(): array
{
    $out = ['email' => [], 'number' => [], 'company' => []];
    $all = function_exists('jeab_kontakter_load') ? jeab_kontakter_load() : [];
    foreach ($all as $id => $contact) {
        if (!is_array($contact)) {
            continue;
        }
        $id = (string) ($contact['id'] ?? $id);
        $out['company'][$id] = jeab_solkampanj_contact_company(is_scalar($contact['company'] ?? null) ? (string) $contact['company'] : '');
        $emails = [];
        foreach (is_array($contact['owners'] ?? null) ? $contact['owners'] : [] as $owner) {
            $emails[] = (string) (is_array($owner) ? ($owner['email'] ?? '') : '');
        }
        if (function_exists('jeab_kontakt_secondary_list')) {
            foreach (jeab_kontakt_secondary_list($contact) as $one) {
                $emails[] = is_array($one) ? (string) ($one['email'] ?? '') : (string) $one;
            }
        }
        foreach ($emails as $email) {
            $email = strtolower(trim($email));
            if ($email !== '') {
                $out['email'][$email][$id] = true;
            }
        }
        foreach (['fortnox_customer', 'fortnox_customer_number', 'fortnox_kundnummer', 'CustomerNumber'] as $k) {
            $n = trim(is_scalar($contact[$k] ?? null) ? (string) $contact[$k] : '');
            if ($n !== '') {
                $out['number'][$n][$id] = true;
            }
        }
    }

    return $out;
}

/**
 * Exakt en träff över kundnummer och mejl, annars ''. Pekar kundnumret och mejlet
 * på olika kontakter, eller någon av dem på flera, blir det ingen länk.
 *
 * @param array{email:array<string, array<string, true>>,number:array<string, array<string, true>>} $index
 */
function jeab_solkampanj_match_contact(array $index, string $number, string $email): string
{
    $ids = [];
    $number = trim($number);
    $email = strtolower(trim($email));
    if ($number !== '') {
        $ids += $index['number'][$number] ?? [];
    }
    if ($email !== '') {
        $ids += $index['email'][$email] ?? [];
    }

    if (count($ids) !== 1) {
        return '';
    }
    $id = (string) array_key_first($ids);
    // Bara kontakter i kampanjens bolag: en Järfälla-mottagare länkas aldrig till en Malmö-kontakt.
    if (isset($index['company']) && ($index['company'][$id] ?? '') !== JEAB_SOLKAMPANJ_COMPANY) {
        return '';
    }

    return $id;
}

/**
 * contacts.solkampanj_link: sätter contact_id där exakt en kontakt matchar. dry=1 är standard.
 * Ett befintligt contact_id skrivs inte över. Svaret har antal och id, inga värden.
 *
 * @return array<string, mixed>
 */
function jeab_solkampanj_link_contacts(bool $dry = true, ?PDO $pdo = null): array
{
    $pdo ??= jeab_solkampanj_pdo();
    jeab_solkampanj_ensure($pdo);
    $index = jeab_solkampanj_contact_index();
    $stmt = $pdo->query('SELECT ' . jeab_solkampanj_select($pdo, ['id', 'customer_number', 'mejl', 'contact_id']) . ' FROM solkampanj_recipients ORDER BY id ASC');
    $link = [];
    $none = [];
    $already = 0;
    foreach ($stmt instanceof PDOStatement ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if (trim((string) ($row['contact_id'] ?? '')) !== '') {
            $already++;
            continue;
        }
        $hit = jeab_solkampanj_match_contact($index, (string) ($row['customer_number'] ?? ''), (string) ($row['mejl'] ?? ''));
        if ($hit === '') {
            $none[] = $id;
            continue;
        }
        $link[$id] = $hit;
    }
    if (!$dry && $link !== []) {
        $up = $pdo->prepare("UPDATE solkampanj_recipients SET contact_id = ? WHERE id = ? AND contact_id = ''");
        foreach ($link as $id => $contactId) {
            $up->execute([$contactId, $id]);
        }
    }

    return [
        'ok' => true,
        'action' => 'contacts.solkampanj_link',
        'dry' => $dry,
        'written' => !$dry && $link !== [],
        'linked' => count($link),
        'already' => $already,
        'no_match' => ['count' => count($none), 'ids' => array_slice($none, 0, 200)],
    ];
}

function jeab_solkampanj_normalize_orts(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT id, ort, postnr FROM solkampanj_recipients');
    if ($stmt === false) {
        return;
    }
    $update = $pdo->prepare('UPDATE solkampanj_recipients SET ort = ? WHERE id = ?');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ort = jeab_solkampanj_ort((string) ($row['ort'] ?? ''), (string) ($row['postnr'] ?? ''));
        if ($ort === (string) ($row['ort'] ?? '')) {
            continue;
        }
        $update->execute([$ort, (int) ($row['id'] ?? 0)]);
    }
}

/** Postnummer 175, 176 och 177 räknas som Järfälla. Övriga orter trimmas och skrivs lika oavsett versaler. */
function jeab_solkampanj_ort(string $ort, string $postnr): string
{
    $digits = preg_replace('/\D+/', '', $postnr) ?? '';
    if (strlen($digits) >= 5 && in_array(substr($digits, 0, 3), ['175', '176', '177'], true)) {
        return 'Järfälla';
    }
    $ort = trim((string) (preg_replace('/\s+/u', ' ', $ort) ?? ''));
    if ($ort === '') {
        return '';
    }
    $words = preg_split('/\s+/u', $ort) ?: [];
    $titled = [];
    foreach ($words as $word) {
        $titled[] = jeab_solkampanj_title_word((string) $word);
    }

    return trim(implode(' ', $titled));
}

function jeab_solkampanj_title_word(string $word): string
{
    $word = trim($word);
    if ($word === '') {
        return '';
    }
    $parts = preg_split('/(-)/u', $word, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $out = '';
    foreach ($parts as $part) {
        if ($part === '-') {
            $out .= '-';
            continue;
        }
        $lower = function_exists('mb_strtolower') ? mb_strtolower($part, 'UTF-8') : strtolower($part);
        $out .= function_exists('mb_convert_case')
            ? mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8')
            : ucfirst($lower);
    }

    return $out;
}

/** Första ordet. «Efternamn, Förnamn» tar ordet efter kommatecknet. */
function jeab_solkampanj_first_name(string $namn): string
{
    $namn = trim((string) (preg_replace('/\s+/u', ' ', $namn) ?? ''));
    if ($namn === '') {
        return '';
    }
    if (str_contains($namn, ',')) {
        $namn = trim(explode(',', $namn, 2)[1] ?? '');
    }
    $word = preg_split('/\s+/u', $namn)[0] ?? '';

    return jeab_solkampanj_title_word((string) $word);
}

function jeab_solkampanj_norm_header(string $header): string
{
    $header = trim(function_exists('mb_strtolower') ? mb_strtolower($header, 'UTF-8') : strtolower($header));
    $header = str_replace([' ', '-', 'å', 'ä', 'ö'], ['', '', 'a', 'a', 'o'], $header);

    return $header;
}

/** @return array<string, string> */
function jeab_solkampanj_header_map(array $header): array
{
    $aliases = [
        'namn' => ['namn', 'name', 'kundnamn', 'customername'],
        'gata' => ['gata', 'adress', 'address', 'address1', 'adress1'],
        'postnr' => ['postnr', 'postnummer', 'zip', 'zipcode', 'postkod'],
        'ort' => ['ort', 'stad', 'city'],
        'mejl' => ['mejl', 'mail', 'email', 'epost', 'e_post'],
        'customer_number' => ['customer_number', 'kundnummer', 'customernumber', 'kundnr', 'kund'],
    ];
    $map = [];
    foreach ($header as $i => $cell) {
        $norm = jeab_solkampanj_norm_header((string) $cell);
        foreach ($aliases as $field => $names) {
            if (in_array($norm, $names, true) && !isset($map[$field])) {
                $map[$field] = (string) $i;
            }
        }
    }

    return $map;
}

/**
 * @return list<array{customer_number:string,namn:string,first_name:string,mejl:string,gata:string,postnr:string,ort:string,values:array<string,string>}>
 */
function jeab_solkampanj_parse_csv(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
    $csv = str_replace(["\r\n", "\r"], "\n", $csv);
    if (str_contains($csv, '<?php') || str_contains($csv, '<?=')) {
        throw new InvalidArgumentException('CSV-filen går inte att läsa.');
    }
    if (strlen($csv) > 5000000) {
        throw new InvalidArgumentException('CSV-filen är för stor.');
    }
    $first = strtok($csv, "\n") ?: '';
    $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    $fh = fopen('php://temp', 'r+');
    if ($fh === false) {
        throw new RuntimeException('CSV-filen går inte att läsa.');
    }
    fwrite($fh, $csv);
    rewind($fh);
    $header = fgetcsv($fh, 0, $delim);
    if (!is_array($header)) {
        fclose($fh);
        throw new InvalidArgumentException('CSV-filen saknar en rubrikrad.');
    }
    $map = jeab_solkampanj_header_map($header);
    foreach (['namn', 'mejl'] as $need) {
        if (!isset($map[$need])) {
            fclose($fh);
            throw new InvalidArgumentException('CSV-filen saknar kolumnen ' . $need . '.');
        }
    }
    $rows = [];
    while (($cells = fgetcsv($fh, 0, $delim)) !== false) {
        if (!is_array($cells) || $cells === [null] || $cells === ['']) {
            continue;
        }
        $pick = static function (string $field) use ($map, $cells): string {
            if (!isset($map[$field])) {
                return '';
            }
            return trim((string) ($cells[(int) $map[$field]] ?? ''));
        };
        $namn = $pick('namn');
        $mejl = strtolower($pick('mejl'));
        $extra = [];
        foreach ($header as $i => $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            $field = null;
            foreach ($map as $name => $index) {
                if ((int) $index === (int) $i) {
                    $field = $name;
                    break;
                }
            }
            if ($field !== null) {
                continue;
            }
            $extra[$label] = trim((string) ($cells[$i] ?? ''));
        }
        $rows[] = [
            'customer_number' => $pick('customer_number'),
            'namn' => $namn,
            'first_name' => jeab_solkampanj_first_name($namn),
            'mejl' => $mejl,
            'gata' => $pick('gata'),
            'postnr' => $pick('postnr'),
            'ort' => $pick('ort'),
            'values' => $extra,
        ];
    }
    fclose($fh);

    return $rows;
}

function jeab_solkampanj_row_key(array $row): string
{
    $email = strtolower(trim((string) ($row['mejl'] ?? '')));
    $number = trim((string) ($row['customer_number'] ?? ''));

    return 'mejl:' . $email . '|nr:' . $number;
}

function jeab_solkampanj_email_ok(string $email): bool
{
    return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * @param array<string, mixed> $in
 * @return array{ok:bool,error?:string,status?:int,imported?:int,updated?:int,skipped?:int,capped?:int,total?:int,max_total?:float}
 */
function jeab_solkampanj_import_dispatch(array $in): array
{
    if (function_exists('jeab_restricted_is_solkampanj') && jeab_restricted_is_solkampanj()) {
        return ['ok' => false, 'error' => 'Inte behörig.', 'status' => 403];
    }
    $csv = (string) ($in['csv'] ?? '');
    if ($csv === '' && isset($in['csv_base64'])) {
        $decoded = base64_decode(preg_replace('/\s+/', '', (string) $in['csv_base64']) ?? '', true);
        $csv = is_string($decoded) ? $decoded : '';
    }
    if (trim($csv) === '') {
        return ['ok' => false, 'error' => 'CSV saknas.', 'status' => 422];
    }
    if (strlen($csv) > 5000000) {
        return ['ok' => false, 'error' => 'CSV-filen är för stor.', 'status' => 422];
    }
    if (!function_exists('jeab_fortnox_kund_fakturasummor_max')) {
        require_once __DIR__ . '/fortnox_kund_fakturasummor.php';
    }
    $max = jeab_fortnox_kund_fakturasummor_max($in);
    if ($max === null) {
        return ['ok' => false, 'error' => 'Ogiltigt tak.', 'status' => 422];
    }
    try {
        $over = jeab_solkampanj_over_emails($max);
        jeab_solkampanj_store_bytes('recipients.csv', $csv);
        $stats = jeab_solkampanj_import_csv($csv, $over);
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'status' => 422];
    } catch (Throwable $e) {
        if (function_exists('jeab_log_app_error')) {
            jeab_log_app_error('solkampanj', $e->getMessage());
        }
        $error = $e->getMessage() === 'Fakturorna gick inte att hämta.'
            ? 'Fakturorna gick inte att hämta.'
            : 'Importen gick inte att läsa.';

        return ['ok' => false, 'error' => $error, 'status' => 400];
    }

    return ['ok' => true, 'status' => 200, 'max_total' => $max] + $stats;
}

/**
 * Mejl vars fakturor i Järfälla och Malmö tillsammans ligger på eller över taket.
 * Summan är fortnox.kund_fakturasummor med basis total (inkl. moms) från start, per mejl.
 *
 * @return array<string, true>
 */
function jeab_solkampanj_over_emails(float $maxTotal): array
{
    if (!function_exists('jeab_fortnox_kund_fakturasummor_rows')) {
        require_once __DIR__ . '/fortnox_kund_fakturasummor.php';
    }
    $sums = [];
    foreach (['Jarfalla', 'Malmo'] as $account) {
        try {
            $api = jeab_fortnox_kund_fakturasummor_client($account);
            $invoices = $api->listAll('/invoices', 'Invoices');
            $customers = $api->listAll('/customers', 'Customers');
        } catch (Throwable) {
            throw new RuntimeException('Fakturorna gick inte att hämta.');
        }
        if (!is_array($invoices) || !is_array($customers)) {
            throw new RuntimeException('Fakturorna gick inte att hämta.');
        }
        $built = jeab_fortnox_kund_fakturasummor_rows($invoices, $customers, '', '', 'total', INF);
        foreach ($built['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $email = strtolower(trim((string) ($row['mejl'] ?? '')));
            if (!jeab_solkampanj_email_ok($email)) {
                continue;
            }
            $sums[$email] = round(($sums[$email] ?? 0.0) + (float) ($row['sum_total_inkl_moms'] ?? 0), 2);
        }
    }
    $over = [];
    foreach ($sums as $email => $sum) {
        if ($sum >= $maxTotal) {
            $over[$email] = true;
        }
    }

    return $over;
}

/**
 * @param array<string, true> $overEmails
 * @return array{imported:int,updated:int,skipped:int,capped:int,total:int}
 */
function jeab_solkampanj_import_csv(string $csv, array $overEmails = []): array
{
    $rows = jeab_solkampanj_parse_csv($csv);
    $pdo = jeab_solkampanj_pdo();
    jeab_solkampanj_ensure($pdo);
    jeab_solkampanj_normalize_orts($pdo);
    $valuesCol = jeab_solkampanj_values_sql($pdo);
    $have = [];
    $existing = $pdo->query('SELECT ' . jeab_solkampanj_select($pdo, ['id', 'customer_number', 'mejl', 'gata']) . ' FROM solkampanj_recipients ORDER BY id ASC');
    if ($existing !== false) {
        foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row = jeab_solkampanj_hydrate($row);
            $have[jeab_solkampanj_row_key($row)] = (int) $row['id'];
        }
    }
    $imported = 0;
    $updated = 0;
    $skipped = 0;
    $capped = 0;
    // Kolumnlistan efter vad som finns. Saknas egna fält efter en äldre drop hoppas olänkade rader över.
    $index = jeab_solkampanj_contact_index();
    $dropped = array_values(array_filter(jeab_solkampanj_copy_cols(), static fn (string $c): bool => !jeab_solkampanj_col_present($pdo, $c)));
    $names = ['customer_number', 'namn', 'first_name', 'mejl', 'gata', 'postnr', 'ort', 'contact_id'];
    $keep = array_values(array_filter($names, static fn (string $c): bool => !in_array($c, $dropped, true)));
    $insert = $pdo->prepare(
        'INSERT INTO solkampanj_recipients (' . implode(', ', $keep) . ', status, sent_at, sent_by, ' . $valuesCol . ')
         VALUES (' . implode(', ', array_fill(0, count($keep), '?')) . ', \'pending\', NULL, \'\', ?)'
    );
    $insertOver = $pdo->prepare(
        'INSERT INTO solkampanj_recipients (' . implode(', ', $keep) . ', status, sent_at, sent_by, ' . $valuesCol . ')
         VALUES (' . implode(', ', array_fill(0, count($keep), '?')) . ', \'skipped\', NULL, ?, ?)'
    );
    $update = $pdo->prepare(
        'UPDATE solkampanj_recipients
         SET ' . implode(', ', array_map(static fn (string $c): string => $c === 'contact_id' ? "contact_id = CASE WHEN contact_id = '' THEN ? ELSE contact_id END" : $c . ' = ?', $keep)) . ', ' . $valuesCol . ' = ?
         WHERE id = ?'
    );
    $unlinked = 0;
    $unlinkedRows = [];
    $line = 1;
    $cap = $pdo->prepare(
        'UPDATE solkampanj_recipients SET status = \'skipped\', sent_by = ? WHERE id = ? AND status = \'pending\''
    );
    $statusOf = $pdo->prepare('SELECT status FROM solkampanj_recipients WHERE id = ?');
    foreach ($rows as $row) {
        $line++;
        if (!jeab_solkampanj_email_ok($row['mejl'])) {
            $skipped++;
            continue;
        }
        $row['ort'] = jeab_solkampanj_ort($row['ort'], $row['postnr']);
        $key = jeab_solkampanj_row_key($row);
        $json = json_encode($row['values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }
        $contactId = jeab_solkampanj_match_contact($index, (string) $row['customer_number'], (string) $row['mejl']);
        if ($dropped !== [] && $contactId === '') {
            // De egna fälten saknas efter en äldre drop och ingen kontakt matchar exakt: raden skulle tappa namn och mejl.
            $skipped++;
            $unlinked++;
            if (count($unlinkedRows) < 200) {
                // Bara CSV-rad och befintligt id, inga kunddata.
                $unlinkedRows[] = ['line' => $line, 'id' => (int) ($have[$key] ?? 0)];
            }
            continue;
        }
        $all = [
            'customer_number' => $row['customer_number'],
            'namn' => $row['namn'],
            'first_name' => $row['first_name'],
            'mejl' => $row['mejl'],
            'gata' => $row['gata'],
            'postnr' => $row['postnr'],
            'ort' => $row['ort'],
            'contact_id' => $contactId,
        ];
        $fields = array_values(array_intersect_key($all, array_flip($keep)));
        $over = isset($overEmails[$row['mejl']]);
        if (isset($have[$key])) {
            $update->execute([...$fields, $json, $have[$key]]);
            $updated++;
            if ($over) {
                $statusOf->execute([$have[$key]]);
                if ((string) $statusOf->fetchColumn() === 'pending') {
                    $cap->execute([JEAB_SOLKAMPANJ_EXCLUDE_BY, $have[$key]]);
                    $capped++;
                }
            }
            continue;
        }
        if ($over) {
            $insertOver->execute([...$fields, JEAB_SOLKAMPANJ_EXCLUDE_BY, $json]);
            $have[$key] = (int) $pdo->lastInsertId();
            $skipped++;
            $capped++;
            continue;
        }
        $insert->execute([...$fields, $json]);
        $have[$key] = (int) $pdo->lastInsertId();
        $imported++;
    }
    $total = (int) $pdo->query('SELECT COUNT(*) FROM solkampanj_recipients')->fetchColumn();
    if ($unlinked > 0) {
        error_log('solkampanj import: ' . $unlinked . ' rader utan exakt en kontakt hoppades över');
    }

    return ['imported' => $imported, 'updated' => $updated, 'skipped' => $skipped, 'capped' => $capped, 'total' => $total, 'unlinked' => $unlinked, 'unlinked_rows' => $unlinkedRows];
}

/**
 * @return array{ok:bool,read_only:int,status:int,statuses:array<string,int>,towns:list<array{ort:string,pending:int,sent:int,skipped:int,total:int}>}
 */
function jeab_solkampanj_list_stats(): array
{
    $pdo = jeab_solkampanj_pdo();
    jeab_solkampanj_ensure($pdo);
    $statuses = ['pending' => 0, 'sent' => 0, 'skipped' => 0];
    $towns = [];
    $stmt = $pdo->query('SELECT ort, status, COUNT(*) AS n FROM solkampanj_recipients GROUP BY ort, status');
    if ($stmt !== false) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ort = trim((string) ($row['ort'] ?? ''));
            $status = (string) ($row['status'] ?? '');
            $n = (int) ($row['n'] ?? 0);
            if (!isset($statuses[$status])) {
                $statuses[$status] = 0;
            }
            $statuses[$status] += $n;
            if (!isset($towns[$ort])) {
                $towns[$ort] = ['ort' => $ort, 'pending' => 0, 'sent' => 0, 'skipped' => 0, 'total' => 0];
            }
            $towns[$ort]['total'] += $n;
            if (isset($towns[$ort][$status]) && $status !== 'ort' && $status !== 'total') {
                $towns[$ort][$status] += $n;
            }
        }
    }
    uasort($towns, static function (array $a, array $b): int {
        if ($a['ort'] === 'Järfälla') {
            return -1;
        }
        if ($b['ort'] === 'Järfälla') {
            return 1;
        }

        return strcmp($a['ort'], $b['ort']);
    });

    return [
        'ok' => true,
        'read_only' => 1,
        'status' => 200,
        'statuses' => $statuses,
        'towns' => array_values($towns),
    ];
}

/**
 * @param array<string, mixed> $in
 * @return array{ok:bool,error?:string,status:int,dry?:int,affected?:list<int>,undo?:list<array{id:int,status:string,sent_at:?string,sent_by:string}>}
 */
function jeab_solkampanj_exclude(array $in): array
{
    if (function_exists('jeab_restricted_is_solkampanj') && jeab_restricted_is_solkampanj()) {
        return ['ok' => false, 'error' => 'Inte behörig.', 'status' => 403];
    }
    $emails = jeab_solkampanj_exclude_emails($in);
    $ids = jeab_solkampanj_exclude_ids($in);
    if ($emails === [] && $ids === []) {
        return ['ok' => false, 'error' => 'Ange mejl eller id.', 'status' => 422];
    }
    $dry = jeab_solkampanj_exclude_dry($in);
    $confirm = array_key_exists('confirm', $in) && ($in['confirm'] === 1 || $in['confirm'] === '1');
    if (!$dry && !$confirm) {
        return ['ok' => false, 'error' => 'Bekräfta med confirm=1.', 'status' => 422];
    }
    $pdo = jeab_solkampanj_pdo();
    jeab_solkampanj_ensure($pdo);
    $stmt = $pdo->query('SELECT ' . jeab_solkampanj_select($pdo, ['id', 'mejl', 'status', 'sent_at', 'sent_by']) . ' FROM solkampanj_recipients ORDER BY id ASC');
    $affected = [];
    $undo = [];
    if ($stmt !== false) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row = jeab_solkampanj_hydrate($row);
            $id = (int) ($row['id'] ?? 0);
            $email = strtolower(trim((string) ($row['mejl'] ?? '')));
            if ($id < 1 || (!isset($ids[$id]) && !isset($emails[$email]))) {
                continue;
            }
            if ((string) ($row['status'] ?? '') !== 'pending') {
                continue;
            }
            $sentAt = $row['sent_at'] ?? null;
            $affected[] = $id;
            $undo[] = [
                'id' => $id,
                'status' => 'pending',
                'sent_at' => is_string($sentAt) && $sentAt !== '' ? $sentAt : null,
                'sent_by' => (string) ($row['sent_by'] ?? ''),
            ];
        }
    }
    if (!$dry && $affected !== []) {
        jeab_solkampanj_exclude_apply($pdo, $affected);
    }

    return [
        'ok' => true,
        'status' => 200,
        'dry' => $dry ? 1 : 0,
        'affected' => $affected,
        'undo' => $undo,
    ];
}

/** @param list<int> $ids */
function jeab_solkampanj_exclude_apply(PDO $pdo, array $ids): void
{
    $sqlite = jeab_solkampanj_driver($pdo) === 'sqlite';
    $started = false;
    $finish = static function (bool $keep) use ($pdo, $sqlite, &$started): void {
        if (!$started) {
            return;
        }
        $started = false;
        if ($sqlite) {
            $pdo->exec($keep ? 'COMMIT' : 'ROLLBACK');
            return;
        }
        if ($keep) {
            $pdo->commit();
            return;
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    };
    try {
        if ($sqlite) {
            $pdo->exec('BEGIN IMMEDIATE');
        } else {
            $pdo->beginTransaction();
        }
        $started = true;
        $upd = $pdo->prepare(
            'UPDATE solkampanj_recipients SET status = \'skipped\', sent_by = ? WHERE id = ? AND status = \'pending\''
        );
        foreach ($ids as $id) {
            $upd->execute([JEAB_SOLKAMPANJ_EXCLUDE_BY, $id]);
        }
        $finish(true);
    } catch (Throwable $e) {
        $finish(false);
        throw $e;
    }
}

function jeab_solkampanj_exclude_dry(array $in): bool
{
    if (!array_key_exists('dry', $in) || $in['dry'] === null || $in['dry'] === '') {
        return true;
    }
    $dry = $in['dry'];
    if ($dry === false || $dry === 0 || $dry === 0.0 || $dry === '0') {
        return false;
    }
    if (is_string($dry) && strcasecmp(trim($dry), 'false') === 0) {
        return false;
    }

    return true;
}

/** @param array<string, mixed> $in @return array<string, true> */
function jeab_solkampanj_exclude_emails(array $in): array
{
    $out = [];
    foreach (jeab_solkampanj_exclude_parts($in['emails'] ?? $in['email'] ?? []) as $part) {
        $email = strtolower($part);
        if (jeab_solkampanj_email_ok($email)) {
            $out[$email] = true;
        }
    }

    return $out;
}

/** @param array<string, mixed> $in @return array<int, true> */
function jeab_solkampanj_exclude_ids(array $in): array
{
    $out = [];
    foreach (jeab_solkampanj_exclude_parts($in['ids'] ?? $in['id'] ?? []) as $part) {
        if (preg_match('/\A[1-9]\d{0,9}\z/', $part) !== 1) {
            continue;
        }
        $out[(int) $part] = true;
    }

    return $out;
}

/** @return list<string> */
function jeab_solkampanj_exclude_parts(mixed $raw): array
{
    if (is_int($raw)) {
        $raw = [(string) $raw];
    } elseif (is_string($raw)) {
        $raw = preg_split('/[\s,;]+/', trim($raw)) ?: [];
    }
    if (!is_array($raw)) {
        return [];
    }
    $parts = [];
    foreach ($raw as $item) {
        if (is_array($item) || is_object($item) || is_bool($item)) {
            continue;
        }
        $text = trim((string) $item);
        if ($text === '') {
            continue;
        }
        foreach (preg_split('/[\s,;]+/', $text) ?: [] as $bit) {
            $bit = trim((string) $bit);
            if ($bit !== '') {
                $parts[] = $bit;
            }
        }
    }

    return $parts;
}

/** @return array<string, mixed>|null */
function jeab_solkampanj_fetch(PDO $pdo, int $id): ?array
{
    $col = jeab_solkampanj_values_sql($pdo);
    $stmt = $pdo->prepare(
        'SELECT ' . jeab_solkampanj_select($pdo, ['id', 'customer_number', 'namn', 'first_name', 'mejl', 'gata', 'postnr', 'ort', 'status', 'sent_at', 'sent_by']) . ', ' . $col . ' AS values_json
         FROM solkampanj_recipients WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? jeab_solkampanj_hydrate($row) : null;
}

function jeab_solkampanj_user_key(): string
{
    $candidates = [];
    $forced = $GLOBALS['_JEAB_SOLKAMPANJ_USER'] ?? null;
    if (is_string($forced) && trim($forced) !== '') {
        $candidates[] = strtolower(trim($forced));
    } else {
        if (function_exists('jeab_auth_user_fingerprint')) {
            $fp = strtolower(trim((string) jeab_auth_user_fingerprint()));
            if ($fp !== '') {
                $candidates[] = 'fp:' . $fp;
            }
        }
        if (function_exists('jeab_auth_user_email')) {
            $email = strtolower(trim((string) jeab_auth_user_email()));
            if ($email !== '') {
                $candidates[] = 'mail:' . $email;
            }
        }
    }
    foreach ($candidates as $key) {
        if (preg_match('/^(?:fp:[a-f0-9]{16}|mail:[^\s@]+@[^\s@]+)$/', $key) === 1) {
            return $key;
        }
    }

    return '';
}

/** @return array<string, string> */
function jeab_solkampanj_ort_read(): array
{
    $path = jeab_solkampanj_dir() . '/orts.json';
    if (!is_file($path)) {
        return [];
    }
    $json = json_decode((string) file_get_contents($path), true);
    if (!is_array($json)) {
        return [];
    }
    $clean = [];
    foreach ($json as $key => $ort) {
        if (!is_string($key) || !is_string($ort)) {
            continue;
        }
        $key = strtolower(trim($key));
        $ort = trim($ort);
        if (preg_match('/^(?:fp:[a-f0-9]{16}|mail:[^\s@]+@[^\s@]+)$/', $key) !== 1) {
            continue;
        }
        if ($ort === '' || strlen($ort) > 191) {
            continue;
        }
        $clean[$key] = $ort;
    }

    return $clean;
}

/** @param array<string, string> $map */
function jeab_solkampanj_ort_write(array $map): void
{
    $json = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    jeab_solkampanj_store_bytes('orts.json', ($json === false ? '{}' : $json) . "\n");
}

/**
 * @return array<string, array{ort:string,pending:int,total:int}>
 */
function jeab_solkampanj_towns(): array
{
    $pdo = jeab_solkampanj_pdo();
    jeab_solkampanj_ensure($pdo);
    jeab_solkampanj_normalize_orts($pdo);
    $stmt = $pdo->query('SELECT ort, status FROM solkampanj_recipients');
    $bag = [];
    if ($stmt !== false) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ort = trim((string) ($row['ort'] ?? ''));
            if ($ort === '') {
                continue;
            }
            if (!isset($bag[$ort])) {
                $bag[$ort] = ['ort' => $ort, 'pending' => 0, 'total' => 0];
            }
            $bag[$ort]['total']++;
            if ((string) ($row['status'] ?? '') === 'pending') {
                $bag[$ort]['pending']++;
            }
        }
    }
    uasort($bag, static function (array $a, array $b): int {
        if ($a['ort'] === 'Järfälla') {
            return -1;
        }
        if ($b['ort'] === 'Järfälla') {
            return 1;
        }

        return strcmp($a['ort'], $b['ort']);
    });

    return $bag;
}

/** @return list<array{ort:string,pending:int}> */
function jeab_solkampanj_towns_public(): array
{
    $out = [];
    foreach (jeab_solkampanj_towns() as $town) {
        $out[] = ['ort' => $town['ort'], 'pending' => $town['pending']];
    }

    return $out;
}

function jeab_solkampanj_saved_ort(): string
{
    $key = jeab_solkampanj_user_key();
    if ($key === '') {
        return '';
    }

    return trim((string) (jeab_solkampanj_ort_read()[$key] ?? ''));
}

function jeab_solkampanj_active_ort(): ?string
{
    $towns = jeab_solkampanj_towns();
    if ($towns === []) {
        return null;
    }
    $saved = jeab_solkampanj_saved_ort();
    if ($saved !== '' && isset($towns[$saved])) {
        return $saved;
    }
    if (isset($towns['Järfälla'])) {
        return 'Järfälla';
    }
    if (count($towns) === 1) {
        return (string) array_key_first($towns);
    }

    return (string) array_key_first($towns);
}

/** @return array{ok:bool,error?:string,ort?:string,towns?:list<array{ort:string,pending:int}>,progress?:array,next?:?array} */
function jeab_solkampanj_remember_ort(string $ort): array
{
    $ort = trim($ort);
    $key = jeab_solkampanj_user_key();
    if ($key === '') {
        return jeab_solkampanj_result(false, 'Inte behörig.');
    }
    $towns = jeab_solkampanj_towns();
    if ($ort !== '' && !isset($towns[$ort])) {
        return jeab_solkampanj_result(false, 'Orten finns inte i listan.');
    }
    $all = jeab_solkampanj_ort_read();
    if ($ort === '') {
        unset($all[$key]);
    } else {
        $all[$key] = $ort;
    }
    jeab_solkampanj_ort_write($all);

    return jeab_solkampanj_result(true);
}

function jeab_solkampanj_row_allowed(array $row): bool
{
    $active = jeab_solkampanj_active_ort();
    if ($active === null) {
        return false;
    }
    $ort = jeab_solkampanj_ort((string) ($row['ort'] ?? ''), (string) ($row['postnr'] ?? ''));

    return $ort === $active;
}

/** @return array<string, mixed>|null */
function jeab_solkampanj_next(): ?array
{
    $pdo = jeab_solkampanj_pdo();
    jeab_solkampanj_ensure($pdo);
    $ort = jeab_solkampanj_active_ort();
    if ($ort === null) {
        return null;
    }
    $col = jeab_solkampanj_values_sql($pdo);
    $stmt = $pdo->prepare(
        'SELECT ' . jeab_solkampanj_select($pdo, ['id', 'customer_number', 'namn', 'first_name', 'mejl', 'gata', 'postnr', 'ort', 'status', 'sent_at', 'sent_by']) . ', ' . $col . ' AS values_json
         FROM solkampanj_recipients WHERE status = \'pending\' AND ort = ? ORDER BY id ASC LIMIT 1'
    );
    $stmt->execute([$ort]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? jeab_solkampanj_hydrate($row) : null;
}

/** @return array{sent:int,total:int,skipped:int,pending:int} */
function jeab_solkampanj_progress(): array
{
    $empty = ['sent' => 0, 'skipped' => 0, 'pending' => 0, 'total' => 0];
    $pdo = jeab_solkampanj_pdo();
    jeab_solkampanj_ensure($pdo);
    $ort = jeab_solkampanj_active_ort();
    if ($ort === null) {
        return $empty;
    }
    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS n FROM solkampanj_recipients WHERE ort = ? GROUP BY status');
    $stmt->execute([$ort]);
    $sent = 0;
    $skipped = 0;
    $pending = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $n = (int) ($row['n'] ?? 0);
        $status = (string) ($row['status'] ?? '');
        if ($status === 'sent') {
            $sent = $n;
        } elseif ($status === 'skipped') {
            $skipped = $n;
        } elseif ($status === 'pending') {
            $pending = $n;
        }
    }

    return [
        'sent' => $sent,
        'skipped' => $skipped,
        'pending' => $pending,
        'total' => $sent + $pending,
    ];
}

/** @param array<string, mixed>|null $row */
function jeab_solkampanj_public_row(?array $row): ?array
{
    if ($row === null) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'namn' => (string) ($row['namn'] ?? ''),
        'first_name' => (string) ($row['first_name'] ?? ''),
        'mejl' => (string) ($row['mejl'] ?? ''),
        'gata' => (string) ($row['gata'] ?? ''),
        'postnr' => (string) ($row['postnr'] ?? ''),
        'ort' => (string) ($row['ort'] ?? ''),
    ];
}

function jeab_solkampanj_address_line(array $row): string
{
    return trim((string) ($row['gata'] ?? '')) . ', ' . trim((string) ($row['postnr'] ?? '')) . ' ' . trim((string) ($row['ort'] ?? ''));
}

function jeab_solkampanj_parse_number(string $raw): float
{
    $raw = trim(str_replace(["\u{00a0}", ' '], '', $raw));
    $raw = str_replace(',', '.', $raw);
    if ($raw === '' || preg_match('/^\d+(?:\.\d+)?$/', $raw) !== 1) {
        throw new InvalidArgumentException('Ogiltigt tal.');
    }

    return (float) $raw;
}

function jeab_solkampanj_format_number(float $n, bool $allowDecimal): string
{
    if (!$allowDecimal || abs($n - round($n)) < 0.0000001) {
        return number_format($n, 0, ',', ' ');
    }

    return number_format($n, 1, ',', ' ');
}

/** @return array{antal:string,kwh:string,pris:string} */
function jeab_solkampanj_quote(array $in): array
{
    $antal = jeab_solkampanj_parse_number((string) ($in['antal'] ?? ''));
    $kwh = jeab_solkampanj_parse_number((string) ($in['kwh'] ?? ''));
    $pris = jeab_solkampanj_parse_number((string) ($in['pris'] ?? ''));
    if ($antal < 1 || abs($antal - round($antal)) > 0.0000001) {
        throw new InvalidArgumentException('Antal paneler ska vara ett heltal större än noll.');
    }
    if ($kwh <= 0) {
        throw new InvalidArgumentException('kWh ska vara större än noll.');
    }
    if ($pris < 1 || abs($pris - round($pris)) > 0.0000001) {
        throw new InvalidArgumentException('Priset ska vara ett heltal i kronor.');
    }

    return [
        'antal' => jeab_solkampanj_format_number($antal, false),
        'kwh' => jeab_solkampanj_format_number($kwh, true),
        'pris' => jeab_solkampanj_format_number($pris, false),
    ];
}

function jeab_solkampanj_fill(string $template, array $vars): string
{
    $out = $template;
    foreach ($vars as $key => $value) {
        $out = str_replace('{' . $key . '}', (string) $value, $out);
    }

    return $out;
}

/** @return array{subject:string,text:string,address:string} */
function jeab_solkampanj_text(array $row, array $quote): array
{
    $copy = jeab_solkampanj_copy();
    $first = trim((string) ($row['first_name'] ?? ''));
    $hello = $first !== '' ? jeab_solkampanj_fill($copy['hello'], ['first' => $first]) : 'Hej,';
    $offer = jeab_solkampanj_fill($copy['offer'], $quote);
    $text = $hello . "\n\n" . $copy['intro'] . "\n\n" . $offer;
    $gata = trim((string) ($row['gata'] ?? ''));
    $subject = jeab_solkampanj_fill($copy['subject'], ['gata' => $gata !== '' ? $gata : 'er']);

    return [
        'subject' => $subject,
        'text' => $text,
        'address' => jeab_solkampanj_address_line($row),
    ];
}

function jeab_solkampanj_battery_copy(): array
{
    return [
        'subject' => 'Batteri till {gata}?',
        'hello' => 'Hej {first},',
        'intro' => 'Vi har ju varit i kontakt tidigare om laddbox, jag tänkte om ni kanske funderat på solceller men ser på satelitbild att ni redan har solceller. Men kanske har ni funderat på batteri?',
        'sizes' => 'T.ex. kostar 7,5 kWh bara 39 500 kr. Det kan bli så stort som 37,5 kWh i en och samma stapel som växelriktaren, för 94 500 kr, men då måste det stå utomhus eller i en fristående byggnad på grund av reglerna om brandcell. Batteriet fungerar med er befintliga växelriktare för solcellerna eller kan ersätta den, beroende på var den sitter och om det är SolarEdge eller ett annat märke.',
    ];
}

/** @return array{subject:string,text:string,address:string} */
function jeab_solkampanj_battery_text(array $row): array
{
    $copy = jeab_solkampanj_battery_copy();
    $first = trim((string) ($row['first_name'] ?? ''));
    $hello = $first !== '' ? jeab_solkampanj_fill($copy['hello'], ['first' => $first]) : 'Hej,';
    $text = $hello . "\n\n" . $copy['intro'] . "\n\n" . $copy['sizes'];
    $gata = trim((string) ($row['gata'] ?? ''));
    $subject = jeab_solkampanj_fill($copy['subject'], ['gata' => $gata !== '' ? $gata : 'er']);

    return [
        'subject' => $subject,
        'text' => $text,
        'address' => jeab_solkampanj_address_line($row),
    ];
}

function jeab_solkampanj_signature_html(): string
{
    $br = jeab_offert_div('<br>');
    $medal = 'https://flowstack.example.test/assets/img/medal-solpanel.png';
    $link = htmlspecialchars('https://www.solenergikvalitet.se/leverantorer', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $line = htmlspecialchars(
        'Här är alla publika besiktningar installerade av oss och många andra så att du kan välja en installatör som motsvarar dina förväntningar: ',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    ) . '<a href="' . $link . '">' . $link . '</a>';
    $parts = [
        jeab_offert_div('Demo Säljare'),
        jeab_offert_div('Elektriker'),
        jeab_offert_div('Demo Energi Järfälla'),
        $br,
        jeab_offert_div(jeab_offert_medal_img($medal)),
        $br,
        jeab_offert_div('08-000 00 02'),
        jeab_offert_div('<a href="mailto:demo@example.test">demo@example.test</a>'),
        jeab_offert_div('<a href="https://www.jarfalla.example.test">www.jarfalla.example.test</a>'),
        $br,
        jeab_offert_div($line),
    ];

    return implode('', $parts);
}

function jeab_solkampanj_html(string $text, string $address, bool $image = true): string
{
    $blocks = preg_split("/\n{2,}/", trim(str_replace(["\r\n", "\r"], "\n", $text))) ?: [];
    $out = [];
    foreach ($blocks as $block) {
        $lines = explode("\n", $block);
        $safe = array_map(
            static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $lines
        );
        $out[] = jeab_offert_div(implode('<br>', $safe));
    }
    $out[] = jeab_offert_div('<br>');
    if ($image) {
        $out[] = jeab_offert_div(
            '<img src="cid:' . JEAB_SOLKAMPANJ_CID . '" alt="Solceller" style="max-width:100%;height:auto;border:0">'
        );
        $out[] = jeab_offert_div('<br>');
    }
    $out[] = jeab_solkampanj_signature_html();
    $out[] = jeab_offert_div('<br>');
    $out[] = jeab_offert_div(htmlspecialchars($address, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

    return implode('', $out);
}

/** @return array{bytes:string,mime:string,ext:string} */
function jeab_solkampanj_image(string $raw): array
{
    $raw = trim($raw);
    if (preg_match('#^data:image/(?:png|jpeg|gif|webp);base64,#i', $raw, $m) === 1) {
        $raw = substr($raw, strlen($m[0]));
    }
    $raw = preg_replace('/\s+/', '', $raw) ?? '';
    $bytes = base64_decode($raw, true);
    if (!is_string($bytes) || $bytes === '') {
        throw new InvalidArgumentException('Klistra in en bild.');
    }
    if (strlen($bytes) > 6000000) {
        throw new InvalidArgumentException('Bilden är för stor.');
    }
    $mime = '';
    $ext = '';
    if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
        $mime = 'image/png';
        $ext = 'png';
    } elseif (str_starts_with($bytes, "\xFF\xD8\xFF")) {
        $mime = 'image/jpeg';
        $ext = 'jpg';
    } elseif (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
        $mime = 'image/gif';
        $ext = 'gif';
    } elseif (strlen($bytes) > 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
        $mime = 'image/webp';
        $ext = 'webp';
    }
    if ($mime === '') {
        throw new InvalidArgumentException('Bilden måste vara png, jpeg, gif eller webp.');
    }

    return ['bytes' => $bytes, 'mime' => $mime, 'ext' => $ext];
}

function jeab_solkampanj_killed(): bool
{
    if (!function_exists('jeab_send_guard_pdo') || !function_exists('jeab_send_guard_state')) {
        return false;
    }
    $pdo = jeab_send_guard_pdo();
    if (!$pdo instanceof PDO) {
        return false;
    }
    try {
        if (function_exists('jeab_send_guard_ensure')) {
            jeab_send_guard_ensure($pdo);
        }

        return (int) (jeab_send_guard_state($pdo)['killed'] ?? 0) === 1;
    } catch (Throwable) {
        return false;
    }
}

function jeab_solkampanj_actor(): string
{
    if (function_exists('jeab_restricted_current')) {
        $restricted = jeab_restricted_current();
        $name = is_array($restricted) ? trim((string) ($restricted['name'] ?? '')) : '';
        if ($name !== '' && ($restricted['role'] ?? '') === 'solkampanj') {
            return $name;
        }
    }
    $email = function_exists('jeab_auth_user_email') ? trim((string) jeab_auth_user_email()) : '';
    if ($email !== '') {
        return $email;
    }

    return JEAB_SOLKAMPANJ_FROM_NAME;
}

function jeab_solkampanj_transition(PDO $pdo, int $id, string $status, string $actor, string $stamp): bool
{
    if (!in_array($status, ['sent', 'skipped'], true)) {
        throw new InvalidArgumentException('Ogiltig status.');
    }
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Databasen är upptagen.');
    }
    $sqlite = jeab_solkampanj_driver($pdo) === 'sqlite';
    $started = false;
    $finish = static function (bool $keep) use ($pdo, $sqlite, &$started): void {
        if (!$started) {
            return;
        }
        $started = false;
        if ($sqlite) {
            $pdo->exec($keep ? 'COMMIT' : 'ROLLBACK');
            return;
        }
        if ($keep) {
            $pdo->commit();
            return;
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    };
    try {
        if ($sqlite) {
            $pdo->exec('BEGIN IMMEDIATE');
            $started = true;
        } else {
            $pdo->beginTransaction();
            $started = true;
            $sel = $pdo->prepare('SELECT status FROM solkampanj_recipients WHERE id = ? FOR UPDATE');
            $sel->execute([$id]);
            if ((string) $sel->fetchColumn() !== 'pending') {
                $finish(false);

                return false;
            }
        }
        $upd = $pdo->prepare(
            'UPDATE solkampanj_recipients SET status = ?, sent_at = ?, sent_by = ? WHERE id = ? AND status = \'pending\''
        );
        $upd->execute([$status, $stamp, mb_substr($actor, 0, 191), $id]);
        $ok = $upd->rowCount() === 1;
        $finish($ok);

        return $ok;
    } catch (Throwable $e) {
        $finish(false);
        throw $e;
    }
}

function jeab_solkampanj_revert(PDO $pdo, int $id, string $stamp): void
{
    $pdo->prepare(
        'UPDATE solkampanj_recipients SET status = \'pending\', sent_at = NULL, sent_by = \'\' WHERE id = ? AND status = \'sent\' AND sent_at = ?'
    )->execute([$id, $stamp]);
}

/** @return array{ok:bool,error?:string,already?:bool,progress:array,next:?array} */
function jeab_solkampanj_result(bool $ok, ?string $error = null, bool $already = false): array
{
    $out = [
        'ok' => $ok,
        'ort' => (string) (jeab_solkampanj_active_ort() ?? ''),
        'towns' => jeab_solkampanj_towns_public(),
        'progress' => jeab_solkampanj_progress(),
        'next' => jeab_solkampanj_public_row(jeab_solkampanj_next()),
    ];
    if ($error !== null && $error !== '') {
        $out['error'] = $error;
    }
    if ($already) {
        $out['already'] = true;
    }

    return $out;
}

/** @param array<string, mixed> $in */
function jeab_solkampanj_skip(array $in, string $actor): array
{
    $id = (int) ($in['id'] ?? 0);
    $pdo = jeab_solkampanj_pdo();
    jeab_solkampanj_ensure($pdo);
    $row = $id > 0 ? jeab_solkampanj_fetch($pdo, $id) : null;
    if ($row === null) {
        return jeab_solkampanj_result(false, 'Mottagaren finns inte.');
    }
    if (!jeab_solkampanj_row_allowed($row)) {
        return jeab_solkampanj_result(false, 'Mottagaren hör till en annan ort.');
    }
    if ((string) ($row['status'] ?? '') === 'skipped' || (string) ($row['status'] ?? '') === 'sent') {
        return jeab_solkampanj_result(true, null, true);
    }
    $stamp = date('c');
    if (!jeab_solkampanj_transition($pdo, $id, 'skipped', $actor, $stamp)) {
        return jeab_solkampanj_result(true, null, true);
    }

    return jeab_solkampanj_result(true);
}

/** @param array<string, mixed> $in */
function jeab_solkampanj_send(array $in, string $actor, bool $forceTest = false): array
{
    $id = (int) ($in['id'] ?? 0);
    $pdo = jeab_solkampanj_pdo();
    jeab_solkampanj_ensure($pdo);
    $row = $id > 0 ? jeab_solkampanj_fetch($pdo, $id) : null;
    if ($row === null) {
        return jeab_solkampanj_result(false, 'Mottagaren finns inte.');
    }
    if (!jeab_solkampanj_row_allowed($row)) {
        return jeab_solkampanj_result(false, 'Mottagaren hör till en annan ort.');
    }
    if ((string) ($row['status'] ?? '') === 'sent') {
        return jeab_solkampanj_result(true, null, true);
    }
    if ((string) ($row['status'] ?? '') !== 'pending') {
        return jeab_solkampanj_result(false, 'Mottagaren är inte väntande.');
    }
    if (!jeab_solkampanj_email_ok((string) ($row['mejl'] ?? ''))) {
        return jeab_solkampanj_result(false, 'Mottagaren saknar e-post.');
    }
    $quote = jeab_solkampanj_quote($in);
    $image = jeab_solkampanj_image((string) ($in['image'] ?? ''));
    if (jeab_solkampanj_killed()) {
        return jeab_solkampanj_result(false, 'Utskicken är stoppade.');
    }
    $built = jeab_solkampanj_text($row, $quote);
    $html = jeab_solkampanj_html($built['text'], $built['address']);
    $test = $forceTest || jeab_solkampanj_test_mode();
    $to = $test ? JEAB_SOLKAMPANJ_TEST_TO : (string) $row['mejl'];
    $attachments = [[
        'filename' => 'solceller.' . $image['ext'],
        'content_type' => $image['mime'],
        'content' => $image['bytes'],
        'content_id' => JEAB_SOLKAMPANJ_CID,
    ]];
    if (jeab_solkampanj_pdf_ready()) {
        $pdf = (string) file_get_contents(jeab_solkampanj_pdf_path());
        if (str_starts_with($pdf, '%PDF-')) {
            $attachments[] = [
                'filename' => 'soloofferten.pdf',
                'content_type' => 'application/pdf',
                'content' => $pdf,
            ];
        }
    }
    $brochure = jeab_solkampanj_brochure_attachment();
    if ($brochure === null) {
        return jeab_solkampanj_result(false, 'Företagsbroschyren saknas.');
    }
    $attachments[] = $brochure;
    $stamp = date('c');
    $mail = [
        'to' => $to,
        'subject' => $built['subject'],
        'text' => $built['text'] . "\n\n" . jeab_solkampanj_copy()['inspections'] . "\n\n" . $built['address'],
        'html' => $html,
        'company' => 'jarfalla',
        'from_email' => JEAB_SOLKAMPANJ_FROM,
        'from_name' => JEAB_SOLKAMPANJ_FROM_NAME,
        'person_from' => JEAB_SOLKAMPANJ_FROM,
        'attachments' => $attachments,
        'tags' => ['solkampanj'],
        'job_type' => 'sol',
        'guard_template' => 'solkampanj',
        'guard_mode' => 'manual',
        'guard_via' => 'manual',
        'guard_actor' => $actor,
        'guard_body_hash' => hash('sha256', $test
            ? 'solkampanj-test|' . $id . '|' . $stamp . '|' . bin2hex(random_bytes(4)) . '|' . $built['text']
            : 'solkampanj|' . $id . '|' . $built['subject'] . '|' . $built['text']),
    ];
    if ($test) {
        $sent = jeab_system_mail_send($mail);
        if (empty($sent['ok']) || !empty($sent['queued'])) {
            $why = trim((string) ($sent['error'] ?? ''));

            return jeab_solkampanj_result(false, $why !== '' ? $why : 'Mejlet kunde inte skickas.');
        }
        jeab_solkampanj_log_mail($id, $actor, $sent, true, $stamp);

        return jeab_solkampanj_result(true);
    }
    if (!jeab_solkampanj_transition($pdo, $id, 'sent', $actor, $stamp)) {
        return jeab_solkampanj_result(true, null, true);
    }
    $sent = jeab_system_mail_send($mail);
    if (empty($sent['ok']) || !empty($sent['queued'])) {
        jeab_solkampanj_revert($pdo, $id, $stamp);
        $why = trim((string) ($sent['error'] ?? ''));

        return jeab_solkampanj_result(false, $why !== '' ? $why : 'Mejlet kunde inte skickas.');
    }
    $values = [];
    $decoded = json_decode((string) ($row['values_json'] ?? ''), true);
    if (is_array($decoded)) {
        $values = $decoded;
    }
    $values['antal'] = $quote['antal'];
    $values['kwh'] = $quote['kwh'];
    $values['pris'] = $quote['pris'];
    $values['test_mode'] = 0;
    $values['path'] = (string) ($sent['path'] ?? '');
    $json = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        $pdo->prepare('UPDATE solkampanj_recipients SET ' . jeab_solkampanj_values_sql($pdo) . ' = ? WHERE id = ?')->execute([$json, $id]);
    }
    jeab_solkampanj_log_mail($id, $actor, $sent, false, $stamp);

    return jeab_solkampanj_result(true);
}

/**
 * Batterioffert till den aktuella mottagaren. Inga fält, ingen solbroschyr, ingen bild.
 *
 * @param array<string, mixed> $in
 */
function jeab_solkampanj_send_battery(array $in, string $actor, bool $forceTest = false): array
{
    $id = (int) ($in['id'] ?? 0);
    $pdo = jeab_solkampanj_pdo();
    jeab_solkampanj_ensure($pdo);
    $row = $id > 0 ? jeab_solkampanj_fetch($pdo, $id) : null;
    if ($row === null) {
        return jeab_solkampanj_result(false, 'Mottagaren finns inte.');
    }
    if (!jeab_solkampanj_row_allowed($row)) {
        return jeab_solkampanj_result(false, 'Mottagaren hör till en annan ort.');
    }
    if ((string) ($row['status'] ?? '') === 'sent') {
        return jeab_solkampanj_result(true, null, true);
    }
    if ((string) ($row['status'] ?? '') !== 'pending') {
        return jeab_solkampanj_result(false, 'Mottagaren är inte väntande.');
    }
    if (!jeab_solkampanj_email_ok((string) ($row['mejl'] ?? ''))) {
        return jeab_solkampanj_result(false, 'Mottagaren saknar e-post.');
    }
    if (jeab_solkampanj_killed()) {
        return jeab_solkampanj_result(false, 'Utskicken är stoppade.');
    }
    $built = jeab_solkampanj_battery_text($row);
    $html = jeab_solkampanj_html($built['text'], $built['address'], false);
    $battery = jeab_solkampanj_battery_attachment();
    if ($battery === null) {
        return jeab_solkampanj_result(false, 'Batteribroschyren saknas.');
    }
    $test = $forceTest || jeab_solkampanj_test_mode();
    $to = $test ? JEAB_SOLKAMPANJ_TEST_TO : (string) $row['mejl'];
    $stamp = date('c');
    $mail = [
        'to' => $to,
        'subject' => $built['subject'],
        'text' => $built['text'] . "\n\n" . jeab_solkampanj_copy()['inspections'] . "\n\n" . $built['address'],
        'html' => $html,
        'company' => 'jarfalla',
        'from_email' => JEAB_SOLKAMPANJ_FROM,
        'from_name' => JEAB_SOLKAMPANJ_FROM_NAME,
        'person_from' => JEAB_SOLKAMPANJ_FROM,
        'attachments' => [$battery],
        'tags' => ['solkampanj'],
        'job_type' => 'batteri',
        'guard_template' => 'solkampanj',
        'guard_mode' => 'manual',
        'guard_via' => 'manual',
        'guard_actor' => $actor,
        'guard_body_hash' => hash('sha256', $test
            ? 'solkampanj-batteri-test|' . $id . '|' . $stamp . '|' . bin2hex(random_bytes(4)) . '|' . $built['text']
            : 'solkampanj-batteri|' . $id . '|' . $built['subject'] . '|' . $built['text']),
    ];
    if ($test) {
        $sent = jeab_system_mail_send($mail);
        if (empty($sent['ok']) || !empty($sent['queued'])) {
            $why = trim((string) ($sent['error'] ?? ''));

            return jeab_solkampanj_result(false, $why !== '' ? $why : 'Mejlet kunde inte skickas.');
        }
        jeab_solkampanj_log_mail($id, $actor, $sent, true, $stamp, 'batteri');

        return jeab_solkampanj_result(true);
    }
    if (!jeab_solkampanj_transition($pdo, $id, 'sent', $actor, $stamp)) {
        return jeab_solkampanj_result(true, null, true);
    }
    $sent = jeab_system_mail_send($mail);
    if (empty($sent['ok']) || !empty($sent['queued'])) {
        jeab_solkampanj_revert($pdo, $id, $stamp);
        $why = trim((string) ($sent['error'] ?? ''));

        return jeab_solkampanj_result(false, $why !== '' ? $why : 'Mejlet kunde inte skickas.');
    }
    $values = jeab_solkampanj_values_of($row);
    $values['type'] = 'batteri';
    $values['test_mode'] = 0;
    $values['path'] = (string) ($sent['path'] ?? '');
    $json = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        $pdo->prepare('UPDATE solkampanj_recipients SET ' . jeab_solkampanj_values_sql($pdo) . ' = ? WHERE id = ?')->execute([$json, $id]);
    }
    jeab_solkampanj_log_mail($id, $actor, $sent, false, $stamp, 'batteri');

    return jeab_solkampanj_result(true);
}

/** @param array<string, mixed> $sent */
function jeab_solkampanj_log_mail(int $id, string $actor, array $sent, bool $test, string $stamp, string $type = 'sol'): void
{
    if (!function_exists('jeab_inbox_sent_record')) {
        return;
    }
    $battery = $type === 'batteri';
    $kind = $battery ? 'batteri' : 'solkampanj';
    $thread = ($test ? ($battery ? 'solkampanj-batteri-test-' : 'solkampanj-test-') : ($battery ? 'solkampanj-batteri-' : 'solkampanj-')) . $id;
    $idem = $test
        ? ($battery ? 'solkampanj-batteri-test|' : 'solkampanj-test|') . $id . '|' . $stamp
        : ($battery ? 'solkampanj|batteri|' : 'solkampanj|') . $id;
    try {
        jeab_inbox_sent_record($thread, 'manual', $actor, $kind, [
            'from' => (string) ($sent['from'] ?? JEAB_SOLKAMPANJ_FROM),
            'idem' => $idem,
            'outcome' => $test ? 'test' : 'sent',
            'channel' => 'email',
        ]);
    } catch (Throwable) {
    }
}

/** @return array{ok:bool,error:string,status:int}|null */
function jeab_solkampanj_admin_gate(): ?array
{
    if (function_exists('jeab_restricted_is_solkampanj') && jeab_restricted_is_solkampanj()) {
        return ['ok' => false, 'error' => 'Inte behörig.', 'status' => 403];
    }
    $admin = function_exists('jeab_is_admin') && jeab_is_admin();
    $hook = function_exists('jeab_inkorg_hook_authorized') && jeab_inkorg_hook_authorized();
    if (!$admin && !$hook) {
        return ['ok' => false, 'error' => 'Inte behörig.', 'status' => 403];
    }

    return null;
}

/**
 * Skickar mallen och broschyren till testadressen. Rör inte kön.
 *
 * @param array<string, mixed> $in
 */
function jeab_solkampanj_test_send(array $in, string $actor): array
{
    $denied = jeab_solkampanj_admin_gate();
    if ($denied !== null) {
        return $denied;
    }
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) {
        $next = jeab_solkampanj_next();
        $id = (int) ($next['id'] ?? 0);
        $in['id'] = $id;
    }
    if ($id <= 0) {
        return jeab_solkampanj_result(false, 'Ingen väntande mottagare.');
    }

    return jeab_solkampanj_send($in, $actor, true);
}

/**
 * Skickar batteriofferten och batteribroschyren till testadressen. Rör inte kön.
 *
 * @param array<string, mixed> $in
 */
function jeab_solkampanj_test_send_battery(array $in, string $actor): array
{
    $denied = jeab_solkampanj_admin_gate();
    if ($denied !== null) {
        return $denied;
    }
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) {
        $next = jeab_solkampanj_next();
        $id = (int) ($next['id'] ?? 0);
        $in['id'] = $id;
    }
    if ($id <= 0) {
        return jeab_solkampanj_result(false, 'Ingen väntande mottagare.');
    }

    return jeab_solkampanj_send_battery($in, $actor, true);
}

function jeab_solkampanj_values_of(array $row): array
{
    $decoded = json_decode((string) ($row['values_json'] ?? ''), true);

    return is_array($decoded) ? $decoded : [];
}

function jeab_solkampanj_was_test_send(array $row): bool
{
    if ((string) ($row['status'] ?? '') !== 'sent') {
        return false;
    }
    $flag = jeab_solkampanj_values_of($row)['test_mode'] ?? 0;

    return $flag === true || $flag === 1 || $flag === '1';
}

/** @return list<array{id:int,namn:string,mejl:string,gata:string,postnr:string,ort:string,sent_at:string,sent_by:string}> */
function jeab_solkampanj_test_consumed_rows(PDO $pdo): array
{
    jeab_solkampanj_ensure($pdo);
    $col = jeab_solkampanj_values_sql($pdo);
    $stmt = $pdo->query(
        'SELECT ' . jeab_solkampanj_select($pdo, ['id', 'namn', 'mejl', 'gata', 'postnr', 'ort', 'status', 'sent_at', 'sent_by']) . ', ' . $col . ' AS values_json
         FROM solkampanj_recipients WHERE status = \'sent\' ORDER BY id ASC'
    );
    $out = [];
    if ($stmt === false) {
        return $out;
    }
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!is_array($row) || !jeab_solkampanj_was_test_send($row)) {
            continue;
        }
        $row = jeab_solkampanj_hydrate($row);
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'namn' => (string) ($row['namn'] ?? ''),
            'mejl' => (string) ($row['mejl'] ?? ''),
            'gata' => (string) ($row['gata'] ?? ''),
            'postnr' => (string) ($row['postnr'] ?? ''),
            'ort' => (string) ($row['ort'] ?? ''),
            'sent_at' => (string) ($row['sent_at'] ?? ''),
            'sent_by' => (string) ($row['sent_by'] ?? ''),
        ];
    }

    return $out;
}

/**
 * Torr lista över rader som markerats skickade i testläge.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_solkampanj_test_consumed(array $in): array
{
    unset($in);
    $denied = jeab_solkampanj_admin_gate();
    if ($denied !== null) {
        return $denied;
    }
    $rows = jeab_solkampanj_test_consumed_rows(jeab_solkampanj_pdo());

    return ['ok' => true, 'dry' => 1, 'count' => count($rows), 'rows' => $rows];
}

/** @return list<int> */
function jeab_solkampanj_id_list(mixed $raw): array
{
    if (is_int($raw)) {
        return $raw > 0 ? [$raw] : [];
    }
    if (is_string($raw)) {
        $ids = [];
        foreach (preg_split('/[\s,;]+/', trim($raw)) ?: [] as $part) {
            if (preg_match('/^[1-9]\d*$/', $part) === 1) {
                $ids[(int) $part] = (int) $part;
            }
        }
        $list = array_values($ids);
        sort($list, SORT_NUMERIC);

        return $list;
    }
    if (!is_array($raw)) {
        return [];
    }
    $ids = [];
    foreach ($raw as $item) {
        foreach (jeab_solkampanj_id_list($item) as $id) {
            $ids[$id] = $id;
        }
    }
    $list = array_values($ids);
    sort($list, SORT_NUMERIC);

    return $list;
}

/**
 * Lägg tillbaka test-markerade rader som väntande. dry är standard.
 * Skrivning kräver confirm=SAM. Riktiga utskick rörs inte.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_solkampanj_reset_pending(array $in): array
{
    $denied = jeab_solkampanj_admin_gate();
    if ($denied !== null) {
        return $denied;
    }
    $ids = jeab_solkampanj_id_list($in['ids'] ?? ($in['id'] ?? []));
    if ($ids === []) {
        return ['ok' => false, 'error' => 'id saknas.', 'status' => 422, 'dry' => 1];
    }
    $pdo = jeab_solkampanj_pdo();
    $eligible = [];
    foreach (jeab_solkampanj_test_consumed_rows($pdo) as $row) {
        $eligible[(int) $row['id']] = true;
    }
    $undo = [];
    $kept = [];
    foreach ($ids as $id) {
        if (isset($eligible[$id])) {
            $undo[] = $id;
        } else {
            $kept[] = $id;
        }
    }
    $dry = !array_key_exists('dry', $in) || !in_array($in['dry'], [0, '0'], true);
    if ($dry) {
        return ['ok' => true, 'dry' => 1, 'undo' => 1, 'ids' => $undo, 'kept' => $kept];
    }
    if ((string) ($in['confirm'] ?? '') !== 'SAM') {
        return ['ok' => false, 'error' => 'confirm saknas', 'status' => 400, 'dry' => 0, 'ids' => $undo, 'kept' => $kept];
    }
    $reset = [];
    $upd = $pdo->prepare(
        'UPDATE solkampanj_recipients SET status = \'pending\', sent_at = NULL, sent_by = \'\' WHERE id = ? AND status = \'sent\''
    );
    foreach ($undo as $id) {
        $upd->execute([$id]);
        if ($upd->rowCount() === 1) {
            $reset[] = $id;
        }
    }

    return ['ok' => true, 'dry' => 0, 'undo' => 1, 'ids' => $reset, 'kept' => $kept];
}

/**
 * @param array<string, mixed> $post
 * @param array<string, mixed> $files
 * @return array{ok:bool,error?:string}
 */
function jeab_solkampanj_apply_settings(array $post, array $files): array
{
    if (!function_exists('jeab_is_admin') || !jeab_is_admin()) {
        return ['ok' => false, 'error' => 'Inte behörig.'];
    }
    if (array_key_exists('test_mode', $post)) {
        $flag = $post['test_mode'];
        jeab_solkampanj_set_test_mode($flag === '1' || $flag === 1 || $flag === true || $flag === 'on');
    }
    $pdf = $files['pdf'] ?? null;
    if (is_array($pdf) && (int) ($pdf['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ((int) ($pdf['error'] ?? 0) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'PDF-filen gick inte att läsa.'];
        }
        $tmp = (string) ($pdf['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'PDF-filen gick inte att läsa.'];
        }
        jeab_solkampanj_save_pdf((string) file_get_contents($tmp));
    }

    return ['ok' => true];
}
