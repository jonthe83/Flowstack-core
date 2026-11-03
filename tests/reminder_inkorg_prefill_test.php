<?php

declare(strict_types=1);

/**
 * "Skapa påminnelse" i Inkorg (index.php?p=reminders&ny=1&thread=…) ska öppna ett
 * förifyllt formulär även med den lata bootstrapen som index.php använder live.
 *
 * Felet: p=reminders laddade inte inkorg.php/inkorg_auto.php, så jeab_reminder_case_bundle
 * fick bara ['id' => tråd] och formuläret blev tomt. CLI-tester laddar allt och såg det inte.
 *
 * Testar också: ärende utan kontakt fylls från ärendet och kontakten skapas och kopplas
 * (contact_id) när påminnelsen sparas, och Mina-chippet visar 0 när ingen person är
 * kopplad till inloggningen (inte Allas tal). Påhittade kunder, bara tmp-data, inget mejl.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$root = dirname(__DIR__);
$php = getenv('PHP_BINARY') ?: PHP_BINARY;
$tmp = sys_get_temp_dir() . '/jeab-rem-ixpre-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'test-incoming-token',
    'outgoing_webhook_url' => '',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
        'mysql' => ['host' => '127.0.0.1', 'name' => 'jeab_test', 'user' => 'jeab_test', 'pass' => 'jeab-test-pass', 'charset' => 'utf8mb4'],
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");

function rip(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

/** Ritar Påminnelser i en egen process med lat bootstrap, som index.php live. */
function rip_render_lazy(string $php, string $root, string $tmp, array $get): string
{
    $code = '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
        . '$_SERVER["REQUEST_METHOD"]="GET";'
        . '$_GET=' . var_export($get, true) . ';'
        . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($tmp, true) . ';'
        . '$GLOBALS["_JEAB_PUBLIC_DATA_DIR"]=' . var_export($tmp, true) . ';'
        . 'putenv("XAI_API_KEY");'
        . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
        . 'jeab_bootstrap_load_page_modules();'
        . 'require_once ' . var_export($root . '/src/layout.php', true) . ';'
        . 'if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }'
        . '$GLOBALS["JEAB_GRAPH_SEND"]=static fn(): array => ["ok"=>false];'
        . '$GLOBALS["JEAB_SYSTEM_MAIL"]=static fn(): array => ["ok"=>false];'
        . '$store=jeab_store();'
        . '$view="all"; $counts=["today"=>0,"scheduled"=>0,"all"=>0,"waiting"=>0,"done"=>0]; $visible=[]; $search="";'
        . '$remindersAll=jeab_list_reminders($store, true);'
        . 'echo "LAZY_INKORG_AUTO ", (function_exists("jeab_lead_offer")?"1":"0"), "\n";'
        . '$html=require ' . var_export($root . '/src/pages/reminders.php', true) . ';'
        . 'echo is_string($html) ? $html : "";';
    $proc = proc_open([$php, '-d', 'display_errors=stderr', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($proc)) {
        rip(false, 'kunde inte starta php');
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    if ($exit !== 0) {
        rip(false, 'lat underprocess ' . $exit . ' ' . mb_substr(trim($err . "\n" . $out), 0, 600));
    }

    return $out;
}

/** Kontakt-id med e-postadressen i tmp-Kontakter, eller null. */
function rip_contact_by_email(string $email): ?string
{
    foreach (jeab_kontakter_load() as $id => $row) {
        if (is_array($row) && str_contains(mb_strtolower(json_encode($row, JSON_UNESCAPED_UNICODE) ?: ''), mb_strtolower($email))) {
            return (string) $id;
        }
    }

    return null;
}

function rip_dialog(string $html): string
{
    $at = strpos($html, 'id="reminder-create"');
    $end = $at === false ? false : strpos($html, '</dialog>', $at);

    return $at !== false && $end !== false ? substr($html, $at, $end - $at) : '';
}

// --- Seed (full bootstrap i den här processen, bara tmp) ---
require $root . '/src/bootstrap.php';
require_once $root . '/src/layout.php';
if (!jeab_settings_put(jeab_pdo_connect_sqlite(), 'sqlite', 'inkorg_storage', 'sql')) {
    rip(false, 'sql-flaggan går inte att sätta');
}
$ixStore = jeab_inkorg_pdo_store();
rip($ixStore instanceof JeabPdoInkorgStore, 'SQL-inkorgen är öppen i tmp');
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$GLOBALS['_RIP_SENT'] = 0;
$GLOBALS['JEAB_GRAPH_SEND'] = static function (): array {
    $GLOBALS['_RIP_SENT']++;

    return ['ok' => true];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (): array {
    $GLOBALS['_RIP_SENT']++;

    return ['ok' => true];
};
$store = jeab_store();
$_SERVER['REQUEST_METHOD'] = 'GET';

$cara = jeab_kontakt_find_or_create([
    'name' => 'Carola Provsson',
    'email' => 'carola.provsson@example.test',
    'phone' => '0701112233',
    'address' => 'Provgatan 7, 177 31 Järfälla',
    'city' => 'Järfälla',
    'postal_code' => '17731',
    'company' => 'jarfalla',
], false);
rip(is_array($cara) && (string) ($cara['id'] ?? '') !== '', 'kontakten skapas i tmp');
$caraId = (string) $cara['id'];

$ixStore->upsertMessage('ixpre-linked-m1', [
    'id' => 'ixpre-linked-m1',
    'thread_id' => 'ixpre-linked',
    'from' => 'carola.provsson@example.test',
    'from_name' => 'Carola Provsson',
    'to' => 'info@jarfalla.example.test',
    'subject' => 'Offertförfrågan batteri Provgatan',
    'body' => 'Hej, jag vill ha en offert på batteri.',
    'received_at' => '2026-10-28T08:00:00+00:00',
    'source' => 'graph',
    'labels' => ['type' => 'Lead', 'product' => 'batteri'],
]);
$ixStore->upsertThread('ixpre-linked', [
    'id' => 'ixpre-linked',
    'contact_id' => $caraId,
    'customer_name' => 'Carola Provsson',
    'customer_email' => 'carola.provsson@example.test',
    'status' => 'open',
    'tags' => ['batteri'],
    'company' => 'jarfalla',
    'created_at' => '2026-10-28T08:00:00+00:00',
    'updated_at' => '2026-10-28T08:00:00+00:00',
]);

$ixStore->upsertMessage('ixpre-new-m1', [
    'id' => 'ixpre-new-m1',
    'thread_id' => 'ixpre-new',
    'from' => 'nora.nyfall@example.test',
    'from_name' => 'Nora Nyfall',
    'to' => 'info@jarfalla.example.test',
    'subject' => 'Fråga om solceller',
    'body' => 'Hej, vad kostar solceller?',
    'received_at' => '2026-10-28T09:00:00+00:00',
    'source' => 'graph',
    'labels' => ['type' => 'Lead', 'product' => 'sol'],
]);
$ixStore->upsertThread('ixpre-new', [
    'id' => 'ixpre-new',
    'customer_name' => 'Nora Nyfall',
    'customer_email' => 'nora.nyfall@example.test',
    'lead_customer' => [
        'name' => 'Nora Nyfall',
        'email' => 'nora.nyfall@example.test',
        'phone' => '0709998877',
        'address' => 'Nyvägen 3, 177 30 Järfälla',
    ],
    'status' => 'open',
    'tags' => ['sol'],
    'company' => 'jarfalla',
    'created_at' => '2026-10-28T09:00:00+00:00',
    'updated_at' => '2026-10-28T09:00:00+00:00',
]);
rip(rip_contact_by_email('nora.nyfall@example.test') === null, 'Nora har ingen kontakt före');

// 1) Lat bootstrap: ärende med kontakt ger förifyllt formulär.
$out = rip_render_lazy($php, $root, $tmp, ['p' => 'reminders', 'co' => 'jarfalla', 'who' => 'all', 'ny' => '1', 'thread' => 'ixpre-linked']);
$dlg = rip_dialog($out);
rip($dlg !== '' && str_contains($dlg, 'name="from_case"'), 'Skapa påminnelse-dialogen ritas från ärendet');
rip(str_contains($dlg, 'value="Carola Provsson"'), 'Namn är förifyllt från kontakten (lat bootstrap)');
rip(str_contains($dlg, 'value="carola.provsson@example.test"'), 'E-post är förifylld');
rip(str_contains($dlg, 'value="0701112233"') || str_contains($dlg, 'value="070-111 22 33"') || preg_match('/name="phone"[^>]*value="[^"]*111[^"]*"/', $dlg) === 1, 'Telefon är förifylld');
rip(str_contains($dlg, 'Provgatan 7'), 'Adress är förifylld');
rip(str_contains($dlg, 'value="17731"') || str_contains($dlg, 'value="177 31"'), 'Postnummer är förifyllt');
rip(str_contains($dlg, 'value="Järfälla"'), 'Ort är förifylld');
rip(str_contains($dlg, 'name="contact_id" value="' . $caraId . '"'), 'contact_id följer med');
rip(str_contains($dlg, 'value="batteri" checked'), 'produkten läses från ärendet');
rip(str_contains($dlg, 'Offertförfrågan batteri Provgatan</textarea>'), 'Anteckning får ärendets rubrik');
rip(str_contains($out, 'LAZY_INKORG_AUTO 1'), 'p=reminders&ny=1&thread laddar inkorg_auto i lat bootstrap');

// 2) Lat bootstrap: ärende utan kontakt fylls från ärendets egna uppgifter, utan skrivning.
$out = rip_render_lazy($php, $root, $tmp, ['p' => 'reminders', 'co' => 'jarfalla', 'who' => 'all', 'ny' => '1', 'thread' => 'ixpre-new']);
$dlg = rip_dialog($out);
rip(str_contains($dlg, 'value="Nora Nyfall"') && str_contains($dlg, 'value="nora.nyfall@example.test"'), 'ärende utan kontakt: namn och e-post från ärendet');
rip(str_contains($dlg, 'value="0709998877"') && str_contains($dlg, 'Nyvägen 3') && (str_contains($dlg, 'value="17730"') || str_contains($dlg, 'value="177 30"')), 'ärende utan kontakt: telefon, adress och postnummer från ärendet');
rip(str_contains($dlg, 'name="contact_id" value=""'), 'ingen kontakt finns ännu, contact_id är tomt');
rip(rip_contact_by_email('nora.nyfall@example.test') === null, 'GET skapar ingen kontakt');

// 3) Spara: kontakten skapas och kopplas till ärendet, påminnelsen använder den.
$_SESSION['jeab_email'] = 'saljare4@jarfalla.example.test';
$made = jeab_reminder_create_action($store, [
    'ui' => '1',
    'from_case' => '1',
    'thread_id' => 'ixpre-new',
    'contact_id' => '',
    'product' => 'sol',
    'customer' => 'Nora Nyfall',
    'email' => 'nora.nyfall@example.test',
    'phone' => '0709998877',
    'title' => 'Nyvägen 3',
    'postal' => '17730',
    'ort' => 'Järfälla',
    'notes' => 'Ärende: Fråga om solceller',
    'who' => 'Elin',
    'company' => 'jarfalla',
]);
$newCid = trim((string) ($made['contact_id'] ?? ''));
rip((int) ($made['id'] ?? 0) > 0 && $newCid !== '', 'påminnelsen skapas och får ett contact_id');
$newContact = jeab_kontakt_get($newCid);
rip(is_array($newContact) && str_contains(mb_strtolower(json_encode($newContact, JSON_UNESCAPED_UNICODE) ?: ''), 'nora.nyfall@example.test'), 'kontakten finns i Kontakter med ärendets e-post');
$meta = jeab_inkorg_thread_meta('ixpre-new');
rip(trim((string) ($meta['contact_id'] ?? '')) === $newCid, 'ärendet kopplas till den nya kontakten (contact_id)');
rip((string) ($made['customer_name'] ?? '') === 'Nora Nyfall' && (string) ($made['thread_id'] ?? '') === 'ixpre-new', 'påminnelsen har kundnamnet och ärendet');
rip((int) $GLOBALS['_RIP_SENT'] === 0, 'inget mejl skickas');

$missing = null;
try {
    jeab_reminder_create_action($store, ['ui' => '1', 'from_case' => '1', 'thread_id' => 'ixpre-saknas', 'customer' => 'Trad Kopia', 'title' => 'Trad Kopia']);
} catch (InvalidArgumentException $e) {
    $missing = $e->getMessage();
}
rip($missing !== null && str_contains($missing, 'Kontakten saknas'), 'okänt ärende skapar fortfarande ingen kontakt');

// 4) Mina-chippet när ingen person är kopplad: 0, inte Allas tal. Listan visar Alla.
$out = rip_render_lazy($php, $root, $tmp, ['p' => 'reminders', 'co' => 'jarfalla', 'v' => 'all', 'who' => 'all']);
rip(str_contains($out, 'data-mina-fallback="1"'), 'okänd person visar raden "Ingen person är kopplad"');
rip(preg_match('/data-scope="alla"[^>]*>Alla<span class="chip-count">(\d+)<\/span>/', $out, $alla) === 1 && (int) $alla[1] > 0, 'Alla räknar påminnelserna');
rip(preg_match('/data-scope="mina"[^>]*>Mina<span class="chip-count">(\d+)<\/span>/', $out, $mina) === 1 && (int) $mina[1] === 0, 'Mina-chippet visar 0 för okänd person, fick ' . ($mina[1] ?? '?') . ' (Alla ' . ($alla[1] ?? '?') . ')');

echo "OK  påminnelse-från-inkorg-förifylld\n";
