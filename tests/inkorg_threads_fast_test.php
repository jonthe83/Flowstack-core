<?php

declare(strict_types=1);

/** Lista, enstaka stängning, Support-offert och diagnos. Påhittade namn. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-threads-fast-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'incoming_webhook_token' => 'not-the-inbox-token',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function tf(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$battery = mb_strtolower(jeab_gp_owner_email('batteri', 'jarfalla'));
$charger = mb_strtolower(jeab_gp_owner_email('laddbox', 'jarfalla'));
$solar = mb_strtolower(jeab_gp_owner_email('sol', 'jarfalla'));
tf($battery !== '' && $charger !== '' && $solar !== '' && count(array_unique([$battery, $charger, $solar])) === 3, 'tre säljare');

$graphCalls = 0;
$graphUrls = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = static function (string $method, string $url) use (&$graphCalls, &$graphUrls, $battery, $charger): array {
    unset($method);
    $graphCalls++;
    $graphUrls[] = $url;
    $decoded = mb_strtolower(rawurldecode($url));
    if (str_contains($url, 'mailFolders/sentitems') && !str_contains($url, '/messages')) {
        return ['code' => 200, 'body' => ['id' => 'sent-box']];
    }
    $hit = false;
    $subject = 'Hej';
    $customer = '';
    if (str_contains($decoded, 'siv.holm@gmail.com') && str_contains($decoded, $battery) && str_contains($decoded, '$search=')) {
        $hit = true;
        $subject = 'Offert batteri';
        $customer = 'siv.holm@gmail.com';
    }
    if (str_contains($decoded, 'kim.dal@gmail.com') && str_contains($decoded, $charger) && str_contains($decoded, '$search=')) {
        $hit = true;
        $subject = 'Offert elbilsladdare';
        $customer = 'kim.dal@gmail.com';
    }
    if (!$hit) {
        return ['code' => 200, 'body' => ['value' => []]];
    }
    return ['code' => 200, 'body' => ['value' => [[
        'id' => 'Q-' . md5($customer),
        'subject' => $subject,
        'hasAttachments' => false,
        'toRecipients' => [['emailAddress' => ['address' => $customer]]],
        'ccRecipients' => [],
        'sentDateTime' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400),
        'parentFolderId' => 'sent-box',
    ]]]];
};

$lead = static function (string $id, string $email, string $name, string $type = 'Ny lead', string $offer = ''): array {
    $box = jeab_graph_mailboxes()['jarfalla'];
    return jeab_inkorg_ingest([
        'message_id' => $id . '@fast.test',
        'from' => $email,
        'from_name' => $name,
        'to' => $box,
        'subject' => 'Fråga från ' . $name,
        'body_text' => "Namn: {$name}\nE-post: {$email}\nTelefon: 070-555 20 20\nAdress: Björkvägen 4, 177 41 Järfälla\nHej.\n",
        'source' => 'graph-jarfalla',
        'graph_mailbox' => $box,
        'thread_key' => 'tf-' . $id,
        'preset_labels' => [
            'type' => $type, 'action' => 'svara', 'priority' => 1, 'confidence' => 90,
            'intent' => $type === 'Ny lead' ? 'kop' : '', 'offer' => $offer,
            'product' => $offer === '' ? 'okänd produkt' : $offer, 'question' => '',
        ],
    ]);
};
$remind = static function (string $tid, string $name, string $email, string $title): array {
    $wasOpen = in_array((string) (jeab_inkorg_thread_meta($tid)['status'] ?? 'open'), ['', 'open', 'waiting_customer', 'waiting_supplier'], true);
    $row = jeab_create_reminder(jeab_store(), $title, jeab_today_start(), null, null, [
        'customer' => $name,
        'email' => $email,
        'company' => 'jarfalla',
        'who' => 'Roberto',
        'note' => 'Ring och följ upp.',
    ]);
    if ($wasOpen) {
        jeab_inkorg_thread_write($tid, static function (array $t): array {
            $t['status'] = 'open';
            unset($t['hook_closed']);
            return $t;
        });
    }
    return $row;
};
$statusOf = static function (string $tid): string {
    return (string) (jeab_inkorg_thread_meta($tid)['status'] ?? '');
};

$ida = $lead('ida', 'ida.lund@gmail.com', 'Ida Lund');
$idaTid = (string) $ida['thread_id'];
$remind($idaTid, 'Ida Lund', 'ida.lund@gmail.com', 'Ida Lund – Batteri');
jeab_inkorg_thread_write($idaTid, static function (array $t): array {
    $t['status'] = 'open';
    unset($t['hook_closed']);
    return $t;
});
$sentAt = gmdate('Y-m-d\TH:i:s\Z', time() - 3 * 86400);
$GLOBALS['_JEAB_GRAPH_SCAN_CACHE_ALLOW'] = true;
jeab_auto_graph_scan_cache_put($battery, [[
    'to' => ['ida.lund@gmail.com'],
    'cc' => [],
    'sent' => $sentAt,
    'subject' => 'Offert batteri',
    'has_attachments' => false,
]]);
jeab_auto_graph_scan_cache_put($charger, []);
jeab_auto_graph_scan_cache_put($solar, []);
$before = $graphCalls;
$cached = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $idaTid]);
tf($graphCalls === $before && ($cached['reason'] ?? '') === 'offert och påminnelse' && ($cached['status'] ?? '') === 'open' && $statusOf($idaTid) === 'open', 'en stängning läser Skickat-cachen och anropar inte Graph');
unset($GLOBALS['_JEAB_GRAPH_SCAN_CACHE_ALLOW']);

$noa = $lead('noa', 'noa.berg@gmail.com', 'Noa Berg');
$noaTid = (string) $noa['thread_id'];
$GLOBALS['_JEAB_CLOSE_BUDGET_SECONDS'] = 0;
$before = $graphCalls;
$timed = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $noaTid]);
unset($GLOBALS['_JEAB_CLOSE_BUDGET_SECONDS']);
tf($graphCalls === $before && ($timed['reason'] ?? '') === 'timeout' && ($timed['status'] ?? '') === 'open' && $statusOf($noaTid) === 'open', 'timeout lämnar ärendet öppet');

$siv = $lead('siv', 'siv.holm@gmail.com', 'Siv Holm', 'Support');
$sivTid = (string) $siv['thread_id'];
$remind($sivTid, 'Siv Holm', 'siv.holm@gmail.com', 'Siv Holm – Batteri');
$sivClose = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $sivTid, 'dry' => 0]);
$sivThread = jeab_auto_thread_by_id($sivTid);
$sivType = (string) ($sivThread['first']['labels']['type'] ?? '');
tf($statusOf($sivTid) === 'closed' && ($sivClose['reason'] ?? '') === 'offert och påminnelse' && $sivType === 'Support' && in_array('batteri', (array) ($sivThread['tags'] ?? []), true), 'Support med offert i Skickat stängs och klassningen står kvar');

$lars = $lead('lars', 'lars.vik@gmail.com', 'Lars Vik', 'Support');
$larsTid = (string) $lars['thread_id'];
$remind($larsTid, 'Lars Vik', 'lars.vik@gmail.com', 'Lars Vik – Batteri');
$larsClose = jeab_inkorg_hook(['action' => 'inbox.close', 'thread_id' => $larsTid, 'dry' => 0]);
$larsType = (string) ((jeab_auto_thread_by_id($larsTid)['first']['labels']['type'] ?? ''));
tf($statusOf($larsTid) === 'open' && ($larsClose['reason'] ?? 'x') !== 'offert och påminnelse' && $larsType === 'Support', 'Support utan offertämne förblir öppet');

$kim = $lead('kim', 'kim.dal@gmail.com', 'Kim Dal');
$kimTid = (string) $kim['thread_id'];
$urlsBefore = count($graphUrls);
$diag = jeab_inkorg_hook(['action' => 'inbox.quote_sent_diag', 'thread_id' => $kimTid]);
$seen = ['batteri' => false, 'laddbox' => false, 'sol' => false];
foreach (array_slice($graphUrls, $urlsBefore) as $url) {
    $decoded = mb_strtolower(rawurldecode((string) $url));
    if (str_contains($decoded, $battery)) {
        $seen['batteri'] = true;
    }
    if (str_contains($decoded, $charger)) {
        $seen['laddbox'] = true;
    }
    if (str_contains($decoded, $solar)) {
        $seen['sol'] = true;
    }
}
$src = (array) ($diag['sources'] ?? []);
tf(($diag['ok'] ?? false) === true && $seen['batteri'] && $seen['laddbox'] && $seen['sol'] && !empty($src['graph_search']['hit']) && ($src['graph_search']['status'] ?? '') !== 'skip' && array_key_exists('graph_scan', $src), 'okänd produkt läser alla säljares Skickat');

$known = $lead('ebe', 'ebe.holm@gmail.com', 'Ebe Holm', 'Ny lead', 'batteri');
$knownTid = (string) $known['thread_id'];
$urlsBefore = count($graphUrls);
$knownDiag = jeab_inkorg_hook(['action' => 'inbox.quote_sent_diag', 'thread_id' => $knownTid]);
$others = false;
$own = false;
foreach (array_slice($graphUrls, $urlsBefore) as $url) {
    $decoded = mb_strtolower(rawurldecode((string) $url));
    if (str_contains($decoded, $charger) || str_contains($decoded, $solar)) {
        $others = true;
    }
    if (str_contains($decoded, $battery)) {
        $own = true;
    }
}
tf(($knownDiag['ok'] ?? false) === true && $own && !$others, 'känd produkt läser bara den säljarens Skickat');

$dir = jeab_inkorg_dir();
$messages = [];
$threads = [];
$existingMsg = json_decode((string) file_get_contents($dir . '/messages.json'), true);
$existingThreads = json_decode((string) file_get_contents($dir . '/threads.json'), true);
if (is_array($existingMsg['messages'] ?? null)) {
    $messages = $existingMsg['messages'];
}
if (is_array($existingThreads['threads'] ?? null)) {
    $threads = $existingThreads['threads'];
}
for ($i = 0; $i < 2000; $i++) {
    $id = sprintf('perf-%04d', $i);
    $messages[] = [
        'id' => $id,
        'thread_id' => $id,
        'from' => 'kund' . $i . '@gmail.com',
        'from_name' => 'Kund ' . $i,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Fråga',
        'body_text' => 'Hej.',
        'source' => 'graph-jarfalla',
        'received_at' => gmdate('c', time() - $i),
        'labels' => [
            'type' => 'Ny lead', 'action' => 'svara', 'priority' => 1, 'confidence' => 80,
            'intent' => 'kop', 'offer' => '', 'product' => 'okänd produkt', 'question' => '',
        ],
    ];
    $threads[$id] = ['status' => 'open', 'tags' => []];
}
file_put_contents($dir . '/messages.json', json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE));
file_put_contents($dir . '/threads.json', json_encode(['threads' => $threads], JSON_UNESCAPED_UNICODE));
$before = $graphCalls;
$started = hrtime(true);
$list = jeab_inkorg_hook(['action' => 'inbox.threads', 'mailbox' => 'jarfalla', 'status' => 'open', 'limit' => 50]);
$seconds = (hrtime(true) - $started) / 1e9;
$listJson = (string) json_encode($list, JSON_UNESCAPED_UNICODE);
tf(
    ($list['ok'] ?? false) === true
        && ($list['deep'] ?? 1) === 0
        && ($list['total'] ?? 0) >= 2000
        && count((array) ($list['threads'] ?? [])) === 50
        && $graphCalls === $before
        && $seconds < 5
        && !str_contains($listJson, '@'),
    'inbox.threads limit=50 på 2000 ärenden är under 5 s och utan Graph (' . number_format($seconds, 3) . ' s)'
);
$before = $graphCalls;
$deep = jeab_inkorg_hook(['action' => 'inbox.threads', 'mailbox' => 'jarfalla', 'status' => 'open', 'limit' => 1, 'deep' => 1]);
$deepCalls = $graphCalls - $before;
tf(($deep['deep'] ?? 0) === 1 && ($deep['total'] ?? 0) >= 2000 && count((array) ($deep['threads'] ?? [])) === 1 && $deepCalls > 0 && $deepCalls < 80, 'deep=1 läser bara sidan, inte alla 2000');

echo "OK  threads-fast\n";
