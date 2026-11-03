<?php

declare(strict_types=1);

/**
 * Dubbelklick: ett svar, en offert, en order och en bokning.
 * Inget riktigt mejl lämnar maskinen. Påhittade adresser.
 */

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'send-worker') {
    $dir = (string) ($argv[2] ?? '');
    $tid = (string) ($argv[3] ?? '');
    $key = (string) ($argv[4] ?? '');
    $body = (string) ($argv[5] ?? 'Samma svar');
    $GLOBALS['_JEAB_DATA_DIR'] = $dir;
    putenv('JEAB_DISABLE_SQLITE=0');
    $_ENV['JEAB_DISABLE_SQLITE'] = '0';
    require dirname(__DIR__) . '/src/bootstrap.php';
    $GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use ($dir): array {
        if ($method === 'POST' && (str_contains($url, '/reply') || str_contains($url, 'sendMail'))) {
            file_put_contents($dir . '/sends.log', "1\n", FILE_APPEND | LOCK_EX);
            return ['code' => 202, 'body' => []];
        }
        return ['code' => 200, 'body' => ['value' => []]];
    };
    try {
        jeab_inkorg_send_in_thread($tid, ['body' => $body, 'send_key' => $key, 'status_after' => 'closed'], 'Test');
    } catch (Throwable $e) {
        file_put_contents($dir . '/send-err-' . $key, $e->getMessage());
        exit(1);
    }
    exit(0);
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'vunnen-worker') {
    $dir = (string) ($argv[2] ?? '');
    $id = (int) ($argv[3] ?? 0);
    $GLOBALS['_JEAB_DATA_DIR'] = $dir;
    putenv('JEAB_DISABLE_SQLITE=0');
    $_ENV['JEAB_DISABLE_SQLITE'] = '0';
    require dirname(__DIR__) . '/src/bootstrap.php';
    jeab_ordrar_external_live(false);
    $store = jeab_store();
    $rem = jeab_get_reminder($store, $id);
    if (!is_array($rem)) {
        file_put_contents($dir . '/vunnen-err-' . $id, 'saknas');
        exit(1);
    }
    try {
        jeab_ordrar_vunnen_purchase($store, $id, $rem);
    } catch (Throwable $e) {
        file_put_contents($dir . '/vunnen-err-' . $id, $e->getMessage());
        exit(1);
    }
    exit(0);
}

$tmp = sys_get_temp_dir() . '/jeab-ux-dup-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
$_ENV['JEAB_DISABLE_SQLITE'] = '0';
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function uxdup(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg.php');
$app = (string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js');
uxdup(str_contains($page, "c.getAttribute('data-busy') === '1'") && str_contains($page, "sb.textContent = note ? 'Sparar…' : 'Skickar…'"), 'Skicka och stäng låser knappen och visar att det skickas');
uxdup(str_contains($page, 'data-send-quote="1"') && str_contains($page, "b.textContent='Skickar…'") && str_contains($page, "this.getAttribute('data-busy')==='1'"), 'Skicka offert låser knappen och visar att det skickas');
uxdup(str_contains($app, "form.getAttribute('data-busy') === '1'") && str_contains($app, 'bindVunnenForm'), 'Vunnen låser knappen');

jeab_graph_secret_save(['tenant_id' => 't', 'client_id' => 'c', 'client_secret' => 's']);
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use ($tmp): array {
    if ($method === 'POST' && (str_contains($url, '/reply') || str_contains($url, 'sendMail'))) {
        file_put_contents($tmp . '/sends.log', "1\n", FILE_APPEND | LOCK_EX);
        return ['code' => 202, 'body' => []];
    }
    return ['code' => 200, 'body' => ['value' => []]];
};
$ing = jeab_inkorg_ingest([
    'message_id' => '<dup@ux>',
    'from' => 'kund@ex.se',
    'from_name' => 'Kund',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Fråga',
    'body_text' => 'Hej',
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'graph_id' => 'G1',
    'thread_key' => 'ux-dup',
]);
uxdup(!empty($ing['ok']), 'ärendet finns');
$tid = (string) $ing['thread_id'];
$first = jeab_inkorg_send_in_thread($tid, ['body' => 'Samma svar', 'send_key' => 'aa11', 'status_after' => 'closed'], 'Test');
$second = jeab_inkorg_send_in_thread($tid, ['body' => 'Samma svar', 'send_key' => 'bb22', 'status_after' => 'closed'], 'Test');
$sends = substr_count((string) @file_get_contents($tmp . '/sends.log'), "1\n");
uxdup($sends === 1 && ($first['id'] ?? '') !== '' && ($second['id'] ?? '') === ($first['id'] ?? 'x'), 'två svar med samma text skickas en gång, fick ' . $sends);
$other = jeab_inkorg_send_in_thread($tid, ['body' => 'Ett annat svar', 'send_key' => 'cc33', 'status_after' => 'open'], 'Test');
$sends = substr_count((string) @file_get_contents($tmp . '/sends.log'), "1\n");
uxdup($sends === 2 && ($other['id'] ?? '') !== ($first['id'] ?? ''), 'en annan text skickas');
$fails = 0;
$missKey = hash('sha256', "fail\nsend\n");
$miss = jeab_send_once('inkorg-send', $missKey, 30, static function () use (&$fails): array {
    $fails++;
    throw new JeabSendOnceMiss();
});
$afterMiss = jeab_send_once('inkorg-send', $missKey, 30, static function () use (&$fails): array {
    $fails++;
    return ['id' => 'sent-after-miss'];
});
uxdup(!empty($miss['miss']) && empty($afterMiss['duplicate']) && ($afterMiss['result']['id'] ?? '') === 'sent-after-miss' && $fails === 2, 'misslyckat utskick sparas inte');
$oncePdo = jeab_send_once_pdo();
uxdup($oncePdo instanceof PDO && jeab_settings_value($oncePdo, jeab_send_once_driver($oncePdo), 'send_once_table') === '1', 'tabellen skapas en gång bakom flaggan');
jeab_send_once('inkorg-send', 'expired-key', 30, static fn(): array => ['id' => 'old']);
$oncePdo->prepare('UPDATE send_once SET expires_at = ? WHERE idem_key = ?')->execute([time() - 5, 'expired-key']);
uxdup(jeab_send_once_purge() >= 1 && jeab_send_once_peek('inkorg-send', 'expired-key', 30) === null, 'tick rensar utgångna rader');

$php = PHP_BINARY;
$raceA = proc_open([$php, __FILE__, 'send-worker', $tmp, $tid, 'dd01', 'Samtidigt svar'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesA);
$raceB = proc_open([$php, __FILE__, 'send-worker', $tmp, $tid, 'dd02', 'Samtidigt svar'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesB);
uxdup(is_resource($raceA) && is_resource($raceB), 'svarsarbetarna startade');
$errA = stream_get_contents($pipesA[2]);
$errB = stream_get_contents($pipesB[2]);
fclose($pipesA[1]);
fclose($pipesA[2]);
fclose($pipesB[1]);
fclose($pipesB[2]);
$codeA = proc_close($raceA);
$codeB = proc_close($raceB);
$sends = substr_count((string) @file_get_contents($tmp . '/sends.log'), "1\n");
uxdup($codeA === 0 && $codeB === 0 && $sends === 3, 'två samtidiga svar skickas en gång, fick ' . $sends . ' ' . $codeA . '/' . $codeB . ' ' . $errA . $errB);

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;
    return ['ok' => true, 'from' => 'saljare4@jarfalla.example.test', 'graph_id' => 'g', 'conversation_id' => 'c', 'message_id' => 'm@x'];
};
$GLOBALS['JEAB_SYSTEM_MAIL'] = static function (array $opts): array {
    $GLOBALS['_SENT'][] = $opts;
    return ['ok' => true, 'path' => 'stub', 'id' => 'm1', 'from' => 'saljare4@jarfalla.example.test'];
};
jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_send_per_hour' => 500,
    'auto_send_per_day' => 2000,
    'tpl' => jeab_auto_quote_seed(),
]);
jeab_inkorg_helpscout_companies_save(['helpscout_companies' => []]);
jeab_auto_pdf_save(['key' => 'laddbox_jarfalla', 'data' => base64_encode("%PDF-1.4 dup\n")]);
$quoteIn = jeab_inkorg_ingest([
    'message_id' => 'offert-dup@ux.test',
    'from' => 'info@wix-forms.com',
    'from_name' => 'Formulär',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Offert',
    'body_text' => "Namn: Fejk Kund\nE-post: fejk.dup@exempel.se\nTelefon: 070-000 00 19\nAdress: Fejkgatan 1, 176 70 Järfälla\nProdukt: Elbilsladdare\n",
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'graph_id' => 'graph-offert-dup',
    'thread_key' => 'ux-offert-dup',
    'preset_labels' => [
        'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 95,
        'intent' => 'kop', 'offer' => 'laddbox', 'product' => 'laddbox', 'question' => '',
    ],
]);
$quoteTid = (string) ($quoteIn['thread_id'] ?? '');
$before = count($GLOBALS['_SENT']);
$quoteA = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $quoteTid, 'dry' => '0']);
$quoteB = jeab_inkorg_hook(['action' => 'inbox.send_quote', 'thread_id' => $quoteTid, 'dry' => '0']);
uxdup(($quoteA['ok'] ?? false) === true && count($GLOBALS['_SENT']) === $before + 1, 'första offerten skickas en gång, fick ' . (string) ($quoteA['action'] ?? '') . ' ' . (string) ($quoteA['why'] ?? '') . ' ' . (string) ($quoteA['error'] ?? ''));
uxdup(!empty($quoteB['already_sent']) && (int) ($quoteB['status'] ?? 0) === 409 && ($quoteB['error'] ?? '') === 'already sent' && count($GLOBALS['_SENT']) === $before + 1, 'andra offerten blir already sent utan nytt utskick');
uxdup(jeab_inkorg_error_status(new JeabAlreadySentException('already sent')) === 409, 'sidan svarar 409');
uxdup(jeab_public_error_text(new JeabAlreadySentException('already sent')) === 'Offerten är redan skickad', 'redan skickad visas som Offerten är redan skickad');

jeab_ordrar_external_live(false);
$store = jeab_store();
$kept = jeab_create_booking($store, 'Annan Kund', ['Elin'], '2026-11-02 09:00:00', '2026-11-02 13:00:00', 'laddbox', [
    'company' => 'jarfalla',
    'address' => 'Annan 1',
    'email' => 'annan@exempel.se',
    'phone' => '0700000001',
]);
$keptId = (int) ($kept['id'] ?? 0);
$won = jeab_create_reminder($store, 'Batteri Kund', null, 'batteri', null, [
    'customer' => 'Batteri Kund',
    'company' => 'malmo',
    'assignee' => 'Kim',
    'tags' => ['vunnen'],
    'email' => 'batteri@exempel.se',
    'phone' => '0701112233',
    'address' => 'Testgatan 1',
]);
$wonId = (int) ($won['id'] ?? 0);
$ordersBefore = count(jeab_purchase_all());
$booksBefore = count($store->listAllBookings());
$purchaseA = jeab_ordrar_vunnen_purchase($store, $wonId, $won);
$purchaseB = jeab_ordrar_vunnen_purchase($store, $wonId, jeab_get_reminder($store, $wonId) ?: $won);
$bookA = (int) ($purchaseA['booking_id'] ?? 0);
$bookB = (int) ($purchaseB['booking_id'] ?? 0);
$still = jeab_get_reminder($store, $wonId);
$keptRow = $store->getBooking($keptId);
uxdup($bookA === 0 && $bookB === 0, 'två Vunnen skapar ingen bokning');
uxdup(count(jeab_purchase_all()) === $ordersBefore + 1, 'två Vunnen ger en order');
uxdup(count($store->listAllBookings()) === $booksBefore, 'Vunnen skriver ingen bokning');
uxdup(is_array($still) && is_array($keptRow) && ($keptRow['status'] ?? '') !== 'cancelled', 'befintlig bokning och påminnelse ligger kvar');

$plain = jeab_create_reminder($store, 'Elin Kund', null, 'laddbox', null, [
    'customer' => 'Elin Kund',
    'company' => 'jarfalla',
    'assignee' => 'Elin',
    'tags' => ['vunnen'],
    'email' => 'elin.kund@exempel.se',
    'phone' => '0702223344',
    'address' => 'Testgatan 2',
]);
$plainId = (int) ($plain['id'] ?? 0);
$plainPurchase = jeab_ordrar_vunnen_purchase($store, $plainId, $plain);
$plainPurchase['kind'] = 'laddbox';
$plainPurchase = jeab_purchase_put($plainPurchase);
$booksNow = count($store->listAllBookings());
$booked = jeab_ordrar_create_booking($store, ['id' => $plainId]);
$bookedAgain = jeab_ordrar_create_booking($store, ['id' => $plainId]);
uxdup((int) ($booked['booking_id'] ?? 0) > 0 && (int) ($bookedAgain['booking_id'] ?? 0) === (int) ($booked['booking_id'] ?? 0) && count($store->listAllBookings()) === $booksNow + 1, 'två bokningar av samma order blir en');

$raceRem = jeab_create_reminder($store, 'Race Kund', null, 'batteri', null, [
    'customer' => 'Race Kund',
    'company' => 'malmo',
    'assignee' => 'Kim',
    'tags' => ['vunnen'],
    'email' => 'race@exempel.se',
    'phone' => '0703334455',
    'address' => 'Testgatan 3',
]);
$raceId = (int) ($raceRem['id'] ?? 0);
$raceBefore = count($store->listAllBookings());
$ordersRace = count(jeab_purchase_all());
$w1 = proc_open([$php, __FILE__, 'vunnen-worker', $tmp, (string) $raceId], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $wp1);
$w2 = proc_open([$php, __FILE__, 'vunnen-worker', $tmp, (string) $raceId], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $wp2);
uxdup(is_resource($w1) && is_resource($w2), 'vunnen-arbetarna startade');
$werr1 = stream_get_contents($wp1[2]);
$werr2 = stream_get_contents($wp2[2]);
fclose($wp1[1]);
fclose($wp1[2]);
fclose($wp2[1]);
fclose($wp2[2]);
$wc1 = proc_close($w1);
$wc2 = proc_close($w2);
jeab_purchase_cache_forget();
$store = jeab_store();
$raceRow = jeab_purchase_get($raceId);
$raceRemAfter = jeab_get_reminder($store, $raceId);
$raceBookId = (int) ($raceRow['booking_id'] ?? 0);
$raceBooks = count($store->listAllBookings());
$raceOrders = count(jeab_purchase_all());
uxdup($wc1 === 0 && $wc2 === 0, 'vunnen-arbetarna gick igenom, ' . $wc1 . '/' . $wc2 . ' ' . $werr1 . $werr2 . ' ' . (string) @file_get_contents($tmp . '/vunnen-err-' . $raceId));
uxdup($raceOrders === $ordersRace + 1 && $raceBooks === $raceBefore && $raceBookId === 0, 'två samtidiga Vunnen ger en order och ingen bokning, ordrar ' . $raceOrders . ' mot ' . ($ordersRace + 1) . ', bokningar ' . $raceBooks . ' mot ' . $raceBefore . ', id ' . $raceBookId);
uxdup(is_array($raceRemAfter) && $store->getBooking($keptId) !== null, 'samtidig Vunnen tar inte bort påminnelse eller gammal bokning');

echo "klart\n";
