<?php

declare(strict_types=1);

/**
 * Skickat-kontrollen från quote_sent_diag återanvänds av utskicket i tio minuter.
 * inbox.queue_quote lägger en tråd i cron-kön. Webbens 30 s gäller inte där.
 * Påhittade adresser. Inget skickas mot nätet.
 */

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SKIP sqlite saknas\n";
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-quote-queue-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function qq(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$srcAuto = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_auto.php');
$srcTick = (string) file_get_contents(dirname(__DIR__) . '/src/tick.php');
qq(str_contains($srcAuto, 'function jeab_quote_chain_duplicate_block'), 'dubblettspärren finns kvar');
$drainAt = strpos($srcAuto, 'function jeab_inbox_queue_quote_drain');
$drainEnd = strpos($srcAuto, 'function jeab_auto_quote_sent_source_label');
$drainFn = $drainAt !== false && $drainEnd !== false ? substr($srcAuto, $drainAt, $drainEnd - $drainAt) : '';
qq($drainFn !== '' && str_contains($drainFn, 'jeab_auto_send_quote(jeab_quote_outbox_send_input((string) $tid, $product))'), 'cron kör send_quote med radens tråd');
qq(!str_contains($drainFn, 'confirm') && !str_contains($drainFn, 'SAM') && !str_contains($drainFn, 'offer'), 'tömningen har varken confirm, SAM eller offer');
qq(str_contains($srcTick, 'jeab_inbox_queue_quote_drain()'), 'tick tar offertkön');
qq(function_exists('jeab_inbox_sent_log'), 'sent_log finns kvar');
$srcHook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
$srcInkorg = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg.php');
qq(str_contains($srcHook, "str_starts_with(\$jeabHookAction, 'inbox.')") && str_contains($srcHook, 'jeab_inkorg_hook_authorized()'), 'queue_list och queue_remove går genom samma token som queue_quote');
qq(str_contains($srcInkorg, "case 'inbox.queue_list':") && str_contains($srcInkorg, "case 'inbox.queue_remove':"), 'hooken registrerar list och remove');
$listAt = strpos($srcAuto, 'function jeab_inbox_queue_list');
$listEnd = strpos($srcAuto, 'function jeab_inbox_queue_remove');
$listFn = $listAt !== false && $listEnd !== false ? substr($srcAuto, $listAt, $listEnd - $listAt) : '';
qq($listFn !== '' && !str_contains($listFn, 'jeab_inbox_quote_queue_save') && !str_contains($listFn, 'jeab_auto_send_quote') && !str_contains($listFn, 'jeab_inbox_queue_quote_drain'), 'listan varken sparar kön eller skickar');

$GLOBALS['_SENT'] = [];
$GLOBALS['_GRAPH_CALLS'] = 0;
$GLOBALS['_GRAPH_MODE'] = 'clean';
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['_SENT'][] = $opts;
    return ['ok' => true, 'path' => 'stub', 'id' => 'm1', 'from' => 'saljare3@jarfalla.example.test'];
};
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (): array {
    $GLOBALS['_GRAPH_CALLS']++;
    if (($GLOBALS['_GRAPH_MODE'] ?? '') === 'bad') {
        return ['code' => 200, 'body' => ['error' => ['code' => 'Request_BadRequest', 'message' => 'SECRET']]];
    }

    return ['code' => 200, 'body' => ['value' => [], 'id' => 'sent-folder']];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_auto_pdf_save(['key' => 'batteri_jarfalla', 'data' => base64_encode("%PDF-1.4 queue\n")]);
require __DIR__ . '/quote_notiser_seed.php';
jeab_test_seed_quote_notiser();

$ingest = static function (string $key, string $email, string $name, string $phone, string $street): string {
    $in = jeab_inkorg_ingest([
        'message_id' => $key . '@queue.test',
        'from' => 'info@wix-forms.com',
        'from_name' => 'Formulär',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Offert',
        'body_text' => "Namn: {$name}\nE-post: {$email}\nTelefon: {$phone}\nAdress: {$street}, 176 70 Järfälla\nProdukt: Batteri\n",
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'thread_key' => $key,
        'preset_labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
            'intent' => 'kop', 'offer' => 'batteri', 'product' => 'batteri', 'question' => '',
        ],
    ]);
    qq(!empty($in['ok']), 'ärendet ' . $key . ' tas in');

    return (string) $in['thread_id'];
};

$person = jeab_gp_owner_email('batteri', 'jarfalla');
qq($person !== '', 'batteri har en säljare');
$rcptA = 'fejk.cache@gmail.com';
$tidA = $ingest('cache-a', $rcptA, 'Fejk Cache', '070-000 00 31', 'Fejkgatan 8');
$threadA = jeab_auto_thread_by_id($tidA);
qq(is_array($threadA), 'tråd A finns');

$GLOBALS['_GRAPH_CALLS'] = 0;
$diag = jeab_inkorg_hook(['action' => 'inbox.quote_sent_diag', 'thread_id' => $tidA]);
$checkPath = jeab_data_dir() . '/graph-sent-check.json';
$scanPath = jeab_data_dir() . '/graph-sent-scan.json';
unset($GLOBALS['_JEAB_SENT_CHECK_MEM']);
$checkDoc = jeab_auto_sent_check_cache_load();
$checkRaw = json_encode($checkDoc, JSON_UNESCAPED_UNICODE) ?: '';
if (is_file($checkPath)) {
    $checkRaw .= (string) file_get_contents($checkPath);
}
$scanDoc = function_exists('jeab_remain_doc_get') ? jeab_remain_doc_get('sent_scan') : null;
$scanStored = is_file($scanPath) || (is_array($scanDoc) && $scanDoc !== []);
qq(($diag['ok'] ?? false) === true && (int) ($diag['cached'] ?? 0) === 1, 'diagnosen sparar en ren kontroll');
qq(isset($checkDoc[$tidA]) && !$scanStored, 'bara per-tråd-cachen skrivs');
qq(!str_contains($checkRaw, '@') && !isset(jeab_inkorg_thread_meta($tidA)['quote_sent']), 'cachen har ingen adress och ingen quote_sent');
qq(($diag['sources']['graph_scan']['hit'] ?? true) === false && ($diag['sources']['graph_search']['status'] ?? '') === 'ok', 'diagnosen hittar ingen tidigare offert');

$GLOBALS['_GRAPH_MODE'] = 'bad';
$GLOBALS['_GRAPH_CALLS'] = 0;
$why = jeab_auto_quote_already_sent($threadA, $person, $rcptA, 'batteri');
qq($why === '' && $GLOBALS['_GRAPH_CALLS'] === 0, 'färsk ren kontroll hoppar över Skickat');

$all = jeab_auto_sent_check_cache_load();
$all[$tidA]['at'] = time() - 601;
jeab_auto_sent_check_cache_save($all);
$GLOBALS['_GRAPH_CALLS'] = 0;
$stale = jeab_auto_quote_already_sent(jeab_auto_thread_by_id($tidA) ?? $threadA, $person, $rcptA, 'batteri');
qq($GLOBALS['_GRAPH_CALLS'] > 0 && str_contains($stale, 'kunde inte läsa Skickat (graph 200)'), 'gammal kontroll läses om och ett läsfel håller');

jeab_auto_sent_check_cache_put($tidA, $person, [], $rcptA, [
    'graph_search' => ['status' => 'ok', 'scanned' => 4, 'hit' => true, 'date' => '2026-10-01 12:00', 'why' => 'träff'],
    'graph_scan' => ['status' => 'ok', 'scanned' => 4, 'hit' => false, 'date' => '', 'why' => ''],
]);
$GLOBALS['_GRAPH_CALLS'] = 0;
$held = jeab_auto_quote_already_sent(jeab_auto_thread_by_id($tidA) ?? $threadA, $person, $rcptA, 'batteri');
qq($GLOBALS['_GRAPH_CALLS'] === 0 && str_contains($held, 'redan skickad'), 'sparad träff håller utan ny skanning');

jeab_auto_sent_check_cache_put($tidA, $person, [], $rcptA, [
    'graph_search' => ['status' => 'skip', 'scanned' => 0, 'hit' => false, 'date' => '', 'why' => ''],
    'graph_scan' => ['status' => 'skip', 'scanned' => 0, 'hit' => false, 'date' => '', 'why' => ''],
]);
$GLOBALS['_GRAPH_CALLS'] = 0;
$skipped = jeab_auto_quote_already_sent(jeab_auto_thread_by_id($tidA) ?? $threadA, $person, $rcptA, 'batteri');
qq($GLOBALS['_GRAPH_CALLS'] > 0 && str_contains($skipped, 'graph 200'), 'en överhoppad skanning återanvänds inte');

$rcptB = 'fejk.ko@gmail.com';
$tidB = $ingest('queue-b', $rcptB, 'Fejk Ko', '070-000 00 32', 'Fejkgatan 9');
$GLOBALS['_GRAPH_MODE'] = 'clean';
$diagB = jeab_inkorg_hook(['action' => 'inbox.quote_sent_diag', 'thread_id' => $tidB]);
qq((int) ($diagB['cached'] ?? 0) === 1, 'tråd B får en färsk kontroll');
$GLOBALS['_GRAPH_MODE'] = 'bad';

$emptyList = jeab_inkorg_hook(['action' => 'inbox.queue_list']);
qq(($emptyList['ok'] ?? false) === true && ($emptyList['items'] ?? null) === [] && (int) ($emptyList['sent'] ?? 1) === 0 && !is_file(jeab_data_dir() . '/quote-queue.json'), 'tom kö listas utan att skapa filen');

$denied = jeab_inkorg_hook(['action' => 'inbox.queue_quote', 'thread' => $tidB]);
qq(($denied['ok'] ?? true) === false && ($denied['queued'] ?? 1) === 0 && !is_file(jeab_data_dir() . '/quote-queue.json'), 'utan confirm köas inget');
$missing = jeab_inkorg_hook(['action' => 'inbox.queue_quote', 'thread' => 'saknas-helt', 'confirm' => 1]);
qq(($missing['error'] ?? '') === 'not_found' && ($missing['sent'] ?? 1) === 0, 'okänd tråd köas inte');
$queued = jeab_inkorg_hook(['action' => 'inbox.queue_quote', 'thread' => $tidB, 'confirm' => '1']);
$again = jeab_inkorg_hook(['action' => 'inbox.queue_quote', 'thread_id' => $tidB, 'confirm' => 1]);
$queueSnap = static function (): string {
    $path = jeab_data_dir() . '/quote-queue.json';
    if (is_file($path)) {
        return (string) file_get_contents($path);
    }
    $encoded = json_encode(jeab_inbox_quote_queue_load(), JSON_UNESCAPED_UNICODE);

    return is_string($encoded) ? $encoded : '';
};
$queueRaw = $queueSnap();
$queue = json_decode($queueRaw, true);
qq(($queued['ok'] ?? false) === true && (int) ($queued['already'] ?? 1) === 0 && (int) ($queued['sent'] ?? 1) === 0, 'confirm=1 köar tråden');
qq((int) ($again['already'] ?? 0) === 1 && is_array($queue) && count($queue) === 1 && isset($queue[$tidB]), 'samma tråd köas inte två gånger');
qq(!str_contains($queueRaw, '@'), 'kön har ingen adress');

$queueHash = hash('sha256', $queueRaw);
$listed = jeab_inkorg_hook(['action' => 'inbox.queue_list']);
$listedRaw = json_encode($listed, JSON_UNESCAPED_UNICODE);
$listedItems = is_array($listed['items'] ?? null) ? $listed['items'] : [];
$listedOne = is_array($listedItems[0] ?? null) ? $listedItems[0] : [];
$atB = (int) ($queue[$tidB]['at'] ?? 0);
$expectAt = (new DateTimeImmutable('@' . $atB))->setTimezone(new DateTimeZone('Europe/Stockholm'))->format('c');
qq(($listed['ok'] ?? false) === true && ($listed['read_only'] ?? false) === true && (int) ($listed['sent'] ?? 1) === 0 && count($listedItems) === 1, 'listan läser den enda raden och skickar inget');
qq(array_keys($listedOne) === ['thread_id', 'queued_at', 'product', 'seller', 'template'], 'listan har bara de fem fälten');
qq(($listedOne['thread_id'] ?? '') === $tidB && ($listedOne['queued_at'] ?? '') === $expectAt && ($listedOne['product'] ?? '') === 'batteri' && ($listedOne['seller'] ?? '') === 'Roberto' && ($listedOne['template'] ?? '') === 'offert-batteri-jarfalla', 'raden visar tråd, tid, produkt, säljare och mall');
qq(is_string($listedRaw) && !str_contains($listedRaw, '@') && !str_contains($listedRaw, 'Fejk') && !str_contains($listedRaw, '070') && !str_contains($listedRaw, 'Fejkgatan'), 'listan har inget kundnamn, ingen adress och ingen text');
qq(hash('sha256', $queueSnap()) === $queueHash && count($GLOBALS['_SENT']) === 0, 'listan lämnar kön orörd');

$denyRemove = jeab_inkorg_hook(['action' => 'inbox.queue_remove', 'thread' => $tidB, 'dry' => 0]);
qq(($denyRemove['ok'] ?? true) === false && ($denyRemove['error'] ?? '') === 'confirm=1 krävs' && (int) ($denyRemove['removed'] ?? 1) === 0 && (int) ($denyRemove['sent'] ?? 1) === 0, 'remove utan confirm tar inget');
qq(hash('sha256', $queueSnap()) === $queueHash, 'nekad remove lämnar kön');
$dryRemove = jeab_inkorg_hook(['action' => 'inbox.queue_remove', 'thread' => $tidB, 'confirm' => 1]);
qq(($dryRemove['ok'] ?? false) === true && (int) ($dryRemove['dry'] ?? 0) === 1 && (int) ($dryRemove['removed'] ?? 1) === 0 && (int) ($dryRemove['would_remove'] ?? 0) === 1 && (int) ($dryRemove['logged'] ?? 1) === 0 && (int) ($dryRemove['sent'] ?? 1) === 0, 'remove är torr som standard');
qq(hash('sha256', $queueSnap()) === $queueHash, 'torr remove lämnar kön');
$notesBefore = json_encode(jeab_inkorg_thread_meta($tidB)['drafts'] ?? [], JSON_UNESCAPED_UNICODE);
qq(is_string($notesBefore) && !str_contains($notesBefore, 'Borttagen från offertkön'), 'torr remove antecknar inget');

$stuffed = jeab_inbox_quote_queue_load();
$stuffed['orphan-hemmelig'] = ['at' => $atB, 'email' => 'secret.kund@gmail.com', 'name' => 'Fejk Ko', 'text' => 'Hej jag vill ha offert på Fejkgatan'];
jeab_inbox_quote_queue_save($stuffed);
$stuffedList = jeab_inkorg_hook(['action' => 'inbox.queue_list']);
$stuffedRaw = json_encode($stuffedList, JSON_UNESCAPED_UNICODE);
$stuffedItems = is_array($stuffedList['items'] ?? null) ? $stuffedList['items'] : [];
$orphanRow = [];
foreach ($stuffedItems as $row) {
    if (is_array($row) && ($row['thread_id'] ?? '') === 'orphan-hemmelig') {
        $orphanRow = $row;
    }
}
qq(count($stuffedItems) === 2 && ($orphanRow['product'] ?? 'x') === '' && ($orphanRow['seller'] ?? 'x') === '' && ($orphanRow['template'] ?? 'x') === '', 'en rad utan ärende listas utan produkt');
qq(is_string($stuffedRaw) && !str_contains($stuffedRaw, 'secret.kund') && !str_contains($stuffedRaw, '@') && !str_contains($stuffedRaw, 'Fejk') && !str_contains($stuffedRaw, 'Fejkgatan'), 'extra fält i kön följer inte med');
$dropOrphan = jeab_inkorg_hook(['action' => 'inbox.queue_remove', 'thread' => 'orphan-hemmelig', 'confirm' => '1', 'dry' => '0']);
qq(($dropOrphan['ok'] ?? false) === true && (int) ($dropOrphan['removed'] ?? 0) === 1 && (int) ($dropOrphan['logged'] ?? 1) === 0 && (int) ($dropOrphan['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === 0, 'en rad tas bort utan att skicka');
qq(isset(jeab_inbox_quote_queue_load()[$tidB]) && !isset(jeab_inbox_quote_queue_load()['orphan-hemmelig']), 'bara den valda raden försvinner');

$rcptC = 'fejk.bort@gmail.com';
$tidC = $ingest('queue-c', $rcptC, 'Fejk Bort', '070-000 00 33', 'Fejkgatan 10');
$queuedC = jeab_inkorg_hook(['action' => 'inbox.queue_quote', 'thread' => $tidC, 'confirm' => 1]);
qq((int) ($queuedC['already'] ?? 1) === 0 && isset(jeab_inbox_quote_queue_load()[$tidC]), 'en andra tråd köas');
$dropC = jeab_inkorg_hook(['action' => 'inbox.queue_remove', 'id' => $tidC, 'confirm' => 1, 'dry' => 0]);
$noteC = json_encode(jeab_inkorg_thread_meta($tidC)['drafts'] ?? [], JSON_UNESCAPED_UNICODE);
qq(($dropC['ok'] ?? false) === true && (int) ($dropC['removed'] ?? 0) === 1 && (int) ($dropC['logged'] ?? 0) === 1 && (int) ($dropC['sent'] ?? 1) === 0, 'confirm och dry=0 tar bort tråden');
qq(is_string($noteC) && str_contains($noteC, 'Borttagen från offertkön via hook. Inget skickades.') && !str_contains($noteC, '@') && !str_contains($noteC, 'Fejk Bort'), 'borttagningen antecknas utan kunduppgift');
qq(isset(jeab_inbox_quote_queue_load()[$tidB]) && !isset(jeab_inbox_quote_queue_load()[$tidC]) && count($GLOBALS['_SENT']) === 0, 'den andra tråden ligger kvar och inget har skickats');
$missingRemove = jeab_inkorg_hook(['action' => 'inbox.queue_remove', 'thread' => $tidC, 'confirm' => 1, 'dry' => 0]);
qq(($missingRemove['error'] ?? '') === 'not_found' && (int) ($missingRemove['removed'] ?? 1) === 0, 'en rad som inte finns tas inte bort igen');

$GLOBALS['_JEAB_SAPI'] = 'fpm';
$GLOBALS['_JEAB_AUTO_QUOTE_BUDGET_START'] = 1;
$web = jeab_inbox_queue_quote_drain();
qq(($web['skipped'] ?? '') === 'web' && (int) ($web['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === 0, 'webben skickar inte kön');
qq(isset(jeab_inbox_quote_queue_load()[$tidB]) && isset($GLOBALS['_JEAB_AUTO_QUOTE_BUDGET_START']), 'webben lämnar kön och budgeten');

$GLOBALS['_JEAB_SAPI'] = 'cli';
$GLOBALS['_GRAPH_CALLS'] = 0;
$cli = jeab_inbox_queue_quote_drain();
qq((int) ($cli['ran'] ?? 0) === 1 && (int) ($cli['sent'] ?? 0) === 1 && count($GLOBALS['_SENT']) === 1, 'cron skickar den köade tråden en gång');
qq(!isset($GLOBALS['_JEAB_AUTO_QUOTE_BUDGET_START']) && $GLOBALS['_GRAPH_CALLS'] === 0, 'cron har ingen 30 s-budget och läser inte om Skickat');
qq(!isset(jeab_inbox_quote_queue_load()[$tidB]), 'kön är tom efter utskicket');
$againDrain = jeab_inbox_queue_quote_drain();
qq((int) ($againDrain['ran'] ?? 1) === 0 && (int) ($againDrain['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === 1, 'tom kö skickar inget mer');

$re = jeab_inkorg_hook(['action' => 'inbox.queue_quote', 'thread' => $tidB, 'confirm' => 1]);
$blocked = jeab_inbox_queue_quote_drain();
qq((int) ($re['already'] ?? 0) === 1 && (int) ($blocked['ran'] ?? 1) === 0 && (int) ($blocked['sent'] ?? 1) === 0 && count($GLOBALS['_SENT']) === 1 && jeab_quote_outbox_state($tidB) === 'sent', 'redan skickad offert köas inte igen och går inte ut en andra gång');

$lockedPos = strpos($srcTick, 'function jeab_tick_run_work_locked');
$drainPos = strpos($srcTick, 'jeab_inbox_queue_quote_drain()');
qq($lockedPos !== false && $drainPos !== false && $drainPos > $lockedPos && str_contains($srcTick, "\$GLOBALS['_JEAB_TICK'] = true"), 'cron-vägen i tick anropar tömningen');

$GLOBALS['_GRAPH_MODE'] = 'clean';
$rcptC = 'fejk.tick@gmail.com';
$tidC = $ingest('queue-c', $rcptC, 'Fejk Tick', '070-000 00 33', 'Fejkgatan 10');
$diagC = jeab_inkorg_hook(['action' => 'inbox.quote_sent_diag', 'thread_id' => $tidC]);
qq((int) ($diagC['cached'] ?? 0) === 1, 'tråd C får en färsk kontroll');
$queuedC = jeab_inkorg_hook(['action' => 'inbox.queue_quote', 'thread' => $tidC, 'confirm' => 1]);
qq((int) ($queuedC['queued'] ?? 0) === 1 && isset(jeab_inbox_quote_queue_load()[$tidC]), 'tråd C ligger i kön');
$GLOBALS['_JEAB_SAPI'] = 'fpm';
$GLOBALS['_JEAB_TICK'] = true;
$GLOBALS['_JEAB_AUTO_QUOTE_BUDGET_START'] = 1;
$GLOBALS['_GRAPH_CALLS'] = 0;
$sentBefore = count($GLOBALS['_SENT']);
$tickDrain = jeab_inbox_queue_quote_drain();
qq((int) ($tickDrain['ran'] ?? 0) === 1 && (int) ($tickDrain['sent'] ?? 0) === 1 && count($GLOBALS['_SENT']) === $sentBefore + 1, 'php-fpm-cron tar en köad offert');
qq(!isset($GLOBALS['_JEAB_AUTO_QUOTE_BUDGET_START']) && $GLOBALS['_GRAPH_CALLS'] === 0 && !isset(jeab_inbox_quote_queue_load()[$tidC]), 'tick-cron har ingen 30 s-budget och läser inte om Skickat');
unset($GLOBALS['_JEAB_TICK']);
$left = jeab_inkorg_hook(['action' => 'inbox.queue_quote', 'thread' => $tidC, 'confirm' => 1]);
$webAgain = jeab_inbox_queue_quote_drain();
qq((int) ($left['queued'] ?? 0) === 1 && ($webAgain['skipped'] ?? '') === 'web' && count($GLOBALS['_SENT']) === $sentBefore + 1, 'vanlig webbsida tömmer fortfarande inte kön');

$GLOBALS['_JEAB_DATA_BACKEND'] = 'mysql';
// Bara i den här processen. jeab_data_type_mark skriver en delad typlista och
// får senare tester att tro att inkorgen är JSON trots inkorg_storage=sql.
$GLOBALS['_JEAB_DATA_TYPE_LISTS'] = [
    'json' => ['inbox', 'inbox_threads'],
    'sql' => [],
];
$GLOBALS['_JEAB_INKORG_PDO_DROP'] = true;
unset($GLOBALS['_JEAB_INKORG_MSGS'], $GLOBALS['_JEAB_INKORG_MSGS_FP'], $GLOBALS['_JEAB_INKORG_THREADS'], $GLOBALS['_JEAB_SENT_CHECK_MEM']);
qq(jeab_cutover_sql('sent_check') === true && is_array(jeab_migrate_find('sent_check')), 'sent_check är sql');
$checkFile = jeab_data_dir() . '/graph-sent-check.json';
@unlink($checkFile);
$threw = false;
$thrown = '';
try {
    $diagSql = jeab_inkorg_hook(['action' => 'inbox.quote_sent_diag', 'thread_id' => $tidC]);
} catch (Throwable $e) {
    $threw = true;
    $thrown = $e->getMessage();
    $diagSql = [];
}
$checkBody = is_file($checkFile) ? (string) file_get_contents($checkFile) : '';
qq(!$threw && ($diagSql['ok'] ?? false) === true && (int) ($diagSql['cached'] ?? 0) === 1 && $thrown === '' && !str_contains($checkBody, '@'), 'diagnosen sparar cachen utan Typen kan inte skrivas');
unset($GLOBALS['_JEAB_DATA_BACKEND'], $GLOBALS['_JEAB_SENT_CHECK_MEM']);

echo "inkorg quote queue ok\n";
