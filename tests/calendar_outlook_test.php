<?php

declare(strict_types=1);

/**
 * Tvåvägssynk mot Graph med påhittad bokning. Ingen kunddata, inget riktigt anrop.
 */

$tmp = sys_get_temp_dir() . '/jeab-outlook-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite'],
], true) . ";\n");

require dirname(__DIR__) . '/src/bootstrap.php';

function ol_ok(bool $cond, string $message): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
    echo "OK  {$message}\n";
}

$calls = [];
$phase = 'idle';
$seq = 0;
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body = null, array $headers = []) use (&$calls, &$phase, &$seq) {
    unset($headers);
    $calls[] = [$method, $url, $body];
    if (str_contains($url, 'sendMail') || str_contains($url, '/messages')) {
        return ['code' => 500, 'body' => ['error' => 'kundmejl är förbjudet i synken']];
    }
    if (str_contains($url, 'delta')) {
        if ($phase === 'move') {
            return ['code' => 200, 'body' => [
                'value' => [[
                    'id' => (string) ($GLOBALS['OL_FOCUS'] ?? ''),
                    'showAs' => 'free',
                    'isAllDay' => false,
                    'start' => ['dateTime' => '2026-10-26T08:00:00', 'timeZone' => 'Europe/Stockholm'],
                    'end' => ['dateTime' => '2026-10-26T16:00:00', 'timeZone' => 'Europe/Stockholm'],
                    'attendees' => [],
                ]],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/saljare5@jarfalla.example.test/calendarView/delta?$deltatoken=testtoken',
            ]];
        }
        if ($phase === 'gone') {
            return ['code' => 200, 'body' => [
                'value' => [[
                    'id' => (string) ($GLOBALS['OL_FOCUS'] ?? ''),
                    '@removed' => ['reason' => 'deleted'],
                ]],
                '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/users/saljare5@jarfalla.example.test/calendarView/delta?$deltatoken=next',
            ]];
        }
        return ['code' => 200, 'body' => ['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta?$deltatoken=empty']];
    }
    if ($method === 'GET' && str_contains($url, '/events/') && !str_contains($url, 'delta')) {
        $focus = (string) ($GLOBALS['OL_FOCUS'] ?? '');
        if ($phase === 'gone' && $focus !== '' && str_contains($url, $focus)) {
            return ['code' => 404, 'body' => []];
        }
        return ['code' => 200, 'body' => ['id' => 'x', 'subject' => 'x', 'attendees' => [], 'isCancelled' => false]];
    }
    if ($method === 'POST' && str_contains($url, '/events')) {
        $who = 'x';
        if (preg_match('#/users/([^/]+)/events#', $url, $match) === 1) {
            $local = strstr(rawurldecode($match[1]), '@', true);
            $who = is_string($local) && $local !== '' ? $local : 'x';
        }
        return ['code' => 201, 'body' => ['id' => 'evt-' . $who . '-' . (++$seq)]];
    }
    if ($method === 'PATCH') {
        return ['code' => 200, 'body' => ['id' => 'evt-patched']];
    }
    if ($method === 'DELETE') {
        return ['code' => 204, 'body' => []];
    }
    return ['code' => 200, 'body' => ['id' => 'x', 'subject' => 'x', 'attendees' => []]];
};

ol_ok(jeab_ordrar_book_who('sol') === 'Niman', 'ny solbokning pekar på Niman');
ol_ok(jeab_ordrar_book_who('sol', 'malmo') === 'Isaq', 'Malmö-sol pekar fortfarande på Isaq');
ol_ok(str_contains((string) file_get_contents(dirname(__DIR__) . '/assets/js/app.js'), 'function solDefaultAssignee'), 'kalenderkortet har sol-standard på installatören');
ol_ok(str_contains((string) file_get_contents(dirname(__DIR__) . '/hook.php'), 'jeab_outlook_hook_authorized'), 'hooken kräver befintlig hook-auth');

$_SERVER['REQUEST_METHOD'] = 'GET';
ol_ok(!jeab_outlook_hook_authorized(), 'GET utan token nekas');

file_put_contents($tmp . '/graph-person-events.json', json_encode([
    'saljare5@jarfalla.example.test' => ['9001' => 'evt-legacy-niman'],
    'okand@example.test' => ['1' => 'evt-skip'],
], JSON_UNESCAPED_SLASHES));
$store = jeab_store();
ol_ok($store instanceof JeabPdoStore, 'testet kör SQLite');
ol_ok(jeab_outlook_event_id(9001, 'saljare5@jarfalla.example.test') === 'evt-legacy-niman', 'gammal JSON-karta läses in i SQL');
ol_ok(!is_file($tmp . '/graph-person-events.json'), 'JSON-kartan tas bort efter migreringen');
ol_ok(!function_exists('jeab_gp_events_update'), 'event-kartan skrivs inte till JSON');

$secret = 'hook-' . bin2hex(random_bytes(8));
@mkdir(jeab_data_dir() . '/secrets', 0700, true);
file_put_contents(jeab_data_dir() . '/secrets/inkorg-ingest.json', json_encode(['key' => $secret]));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = 'fel';
ol_ok(!jeab_outlook_hook_authorized(), 'fel token nekas');
$_SERVER['HTTP_X_WEBHOOK_TOKEN'] = $secret;
ol_ok(jeab_outlook_hook_authorized(), 'inkorgs-token släpper in');

$customerMail = 'synckund@ex.se';
$customerName = 'Papperskund';
$calls = [];
$row = jeab_create_booking($store, $customerName, ['Sam L'], '2026-10-26 00:00:00', '2026-10-27 00:00:00', 'sol', [
    'company' => 'jarfalla',
    'assignees' => ['Sam L'],
    'email' => $customerMail,
    'address' => 'Testgatan 1',
]);
$id = (int) $row['id'];
$updated = (string) $row['updated_at'];
$links = jeab_outlook_events_for_booking($id);
ol_ok(count($links) === 1 && $links[0]['resource'] === 'Sam L' && str_starts_with($links[0]['graph_event_id'], 'evt-sam-'), 'skapad bokning får event-id i SQL');
ol_ok(!is_file(jeab_data_dir() . '/graph-person-events.json'), 'ingen JSON-karta');
$posted = null;
$mailCalls = 0;
foreach ($calls as $call) {
    if (str_contains($call[1], 'sendMail') || str_contains($call[1], '/messages')) {
        $mailCalls++;
    }
    if ($call[0] === 'POST' && str_contains($call[1], '/events')) {
        $posted = $call[2];
    }
}
ol_ok(is_array($posted) && ($posted['attendees'] ?? null) === [] && $mailCalls === 0, 'skapandet bjuder inte in och skickar inget mejl');

$dryMove = jeab_outlook_hook($store, [
    'action' => 'calendar.booking_set_resources',
    'booking_id' => $id,
    'resources' => ['Niman'],
    'dry' => 1,
]);
ol_ok(!empty($dryMove['ok']) && $dryMove['dry'] === true && $dryMove['changed'] === true && $dryMove['resources'] === ['Niman'], 'dry flytt listar Niman');
ol_ok(jeab_assignees_from_row($store->getBooking($id) ?: []) === ['Sam L'], 'dry flytt skriver inte');
ol_ok(!str_contains(json_encode($dryMove), $customerMail) && !str_contains(json_encode($dryMove), $customerName), 'dry-svaret saknar kunduppgifter');

$calls = [];
$moved = jeab_outlook_hook($store, [
    'action' => 'calendar.booking_set_resources',
    'booking_id' => $id,
    'resources' => ['Niman', 'Elin'],
    'dry' => 0,
]);
$after = $store->getBooking($id) ?: [];
ol_ok(!empty($moved['ok']) && $moved['dry'] === false && $moved['resources'] === ['Niman', 'Elin'], 'två resurser sparas');
ol_ok((string) ($after['updated_at'] ?? '') === $updated, 'flytten rubbar inte mejlklockan');
$pair = jeab_outlook_events_for_booking($id);
$ids = [];
foreach ($pair as $link) {
    $ids[$link['resource']] = $link['graph_event_id'];
}
ol_ok(str_starts_with((string) ($ids['Niman'] ?? ''), 'evt-niman-') && str_starts_with((string) ($ids['Elin'] ?? ''), 'evt-elin-') && !isset($ids['Sam L']), 'varje resurs har ett eget event');
foreach ($calls as $call) {
    ol_ok(!str_contains($call[1], 'sendMail') && !str_contains($call[1], '/messages'), 'flytten skickar inget mejl');
    if ($call[0] === 'POST' && is_array($call[2])) {
        ol_ok(($call[2]['attendees'] ?? null) === [], 'nytt event saknar deltagare');
    }
}

$audit = $store->pdo()->query('SELECT booking_id, resources, dry, changed FROM booking_resource_audit ORDER BY id')->fetchAll();
ol_ok(count($audit) === 2 && (int) $audit[0]['dry'] === 1 && (int) $audit[1]['dry'] === 0, 'både dry och skarp flytt audits i SQL');
ol_ok(!str_contains(json_encode($audit), $customerMail) && !str_contains(json_encode($audit), $customerName), 'auditen saknar kunduppgifter');

$GLOBALS['_JEAB_QUIET_BOOKING'] = true;
$bare = jeab_create_booking($store, $customerName, ['Niman'], '2026-10-19 00:00:00', '2026-10-20 00:00:00', 'sol', [
    'company' => 'jarfalla',
    'assignees' => ['Niman'],
    'email' => $customerMail,
]);
$old = jeab_create_booking($store, $customerName, ['Niman'], '2026-10-01 00:00:00', '2026-10-02 00:00:00', 'sol', [
    'company' => 'jarfalla',
    'assignees' => ['Niman'],
]);
unset($GLOBALS['_JEAB_QUIET_BOOKING']);
$calls = [];
$listed = jeab_outlook_hook($store, ['action' => 'calendar.outlook_backfill', 'dry' => 1]);
ol_ok(!empty($listed['ok']) && $listed['dry'] === true && !isset($listed['written']), 'dry backfill skriver inte');
$found = false;
$leaked = false;
foreach ($listed['items'] as $item) {
    ol_ok(array_keys($item) === ['id', 'date', 'resource'], 'raden är bara id, datum och resurs');
    if ((int) $item['id'] === (int) $old['id']) {
        $leaked = true;
    }
    if ((int) $item['id'] === (int) $bare['id'] && $item['date'] === '2026-10-19' && $item['resource'] === 'Niman') {
        $found = true;
    }
}
ol_ok($found && !$leaked, 'backfill tar 2026-10-18 och framåt, inte äldre');
ol_ok(!str_contains(json_encode($listed), $customerMail) && !str_contains(json_encode($listed), $customerName), 'backfill-listan saknar kunduppgifter');
ol_ok($calls === [], 'dry backfill anropar inte Graph');

$wrote = jeab_outlook_hook($store, ['action' => 'calendar.outlook_backfill', 'dry' => '0']);
ol_ok(!empty($wrote['ok']) && $wrote['dry'] === false && (int) ($wrote['written'] ?? 0) === 1, 'dry 0 skapar den saknade händelsen');
ol_ok(str_starts_with(jeab_outlook_event_id((int) $bare['id'], 'saljare5@jarfalla.example.test'), 'evt-niman-'), 'backfill sparar event-id');

$phase = 'move';
$GLOBALS['OL_FOCUS'] = jeab_outlook_event_id($id, 'saljare5@jarfalla.example.test');
$beforePull = (string) ($store->getBooking($id)['updated_at'] ?? '');
$pull = jeab_outlook_pull($store);
$pulled = $store->getBooking($id) ?: [];
ol_ok(($pull['updated'] ?? 0) >= 1 && ($pulled['starts_at'] ?? '') === '2026-10-26 08:00:00' && ($pulled['ends_at'] ?? '') === '2026-10-26 16:00:00', 'Outlook-tiden skrivs tillbaka');
ol_ok((string) ($pulled['updated_at'] ?? '') === $beforePull, 'tidssynk rubbar inte mejlklockan');
$show = '';
foreach (jeab_outlook_events_for_booking($id) as $link) {
    if ($link['resource'] === 'Niman') {
        $show = $link['show_as'];
    }
}
ol_ok($show === 'free', 'free/busy kommer tillbaka');
ol_ok(!jeab_ordrar_slot_busy([$pulled], 'Niman', '2026-10-26 08:00:00', '2026-10-26 16:00:00'), 'ledig Outlook-tid blockerar inte luckan');
ol_ok(jeab_ordrar_slot_busy([$pulled], 'Elin', '2026-10-26 08:00:00', '2026-10-26 16:00:00'), 'annan resurs är fortfarande upptagen');

$phase = 'gone';
$gone = jeab_outlook_pull($store);
$afterGone = $store->getBooking($id) ?: [];
ol_ok(($gone['removed'] ?? 0) >= 1 && jeab_assignees_from_row($afterGone) === ['Elin'] && ($afterGone['status'] ?? '') === 'booked', 'borttagen resurs lämnar de andra kvar');
$GLOBALS['OL_FOCUS'] = jeab_outlook_event_id($id, 'saljare4@jarfalla.example.test');
$last = jeab_outlook_pull($store);
$cancelled = $store->getBooking($id) ?: [];
ol_ok(($last['removed'] ?? 0) >= 1 && ($cancelled['status'] ?? '') === 'cancelled', 'borttagen sista händelse avbokar utan mejl');
ol_ok((string) ($cancelled['updated_at'] ?? '') === $beforePull, 'avbokningen från Outlook rubbar inte mejlklockan');
$mailCalls = 0;
foreach ($calls as $call) {
    if (str_contains($call[1], 'sendMail') || str_contains($call[1], '/messages')) {
        $mailCalls++;
    }
}
ol_ok($mailCalls === 0, 'synken skickar inget kundmejl');

$tick = jeab_sms_tick($store);
ol_ok(($tick['sent_email'] ?? 0) === 0 && ($tick['sent'] ?? 0) === 0, 'tickern skickar inget efter synken');
ol_ok(isset($tick['outlook']['pulled']), 'cron kör Outlook-dragningen');

try {
    jeab_outlook_hook($store, ['action' => 'calendar.booking_set_resources', 'booking_id' => $id, 'dry' => 0]);
    ol_ok(false, 'resources krävs');
} catch (InvalidArgumentException $e) {
    ol_ok($e->getMessage() === 'resources saknas.', 'saknad resources stoppas');
}

$GLOBALS['_JEAB_QUIET_BOOKING'] = true;
$unlinked = jeab_create_booking($store, $customerName, ['Niman'], '2026-11-02 07:00:00', '2026-11-02 12:00:00', 'laddbox', [
    'company' => 'jarfalla',
    'assignees' => ['Niman'],
    'email' => $customerMail,
]);
unset($GLOBALS['_JEAB_QUIET_BOOKING']);
ol_ok(jeab_outlook_events_for_booking((int) $unlinked['id']) === [], 'tyst skapande lämnar händelsen osynkad');
$calls = [];
jeab_ordrar_move_booking_for_delivery([
    'booking_id' => (int) $unlinked['id'],
    'kind' => 'laddbox',
    'company' => 'jarfalla',
], '2026-11-10', $store);
$postedMove = false;
foreach ($calls as $call) {
    if ($call[0] === 'POST' && str_contains($call[1], '/events') && str_contains(rawurldecode($call[1]), 'saljare5@')) {
        $postedMove = true;
    }
    ol_ok(!str_contains($call[1], 'sendMail'), 'leveransflytten skickar inget mejl');
}
$movedRow = $store->getBooking((int) $unlinked['id']) ?: [];
ol_ok($postedMove && jeab_outlook_event_id((int) $unlinked['id'], 'saljare5@jarfalla.example.test') !== '', 'leveransflytt skapar Outlook-händelsen');
ol_ok(($movedRow['starts_at'] ?? '') !== '2026-11-02 07:00:00', 'leveransflytten flyttar tiden');

echo "Alla calendar outlook-tester gick igenom.\n";
