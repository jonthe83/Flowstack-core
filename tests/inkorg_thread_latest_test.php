<?php

declare(strict_types=1);

/** Senaste svaret visas överst och komplett. Citat, samma Message-ID och HS-tvilling får inte ersätta det. */

$tmp = sys_get_temp_dir() . '/jeab-thread-latest-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function tl(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$sender = 'riktig.kund@exempel.test';
$old = 'Växelriktaren stannade igår kväll och startar inte.';
$fresh = 'Nu blinkar den rött igen efter omstart.';
$bottom = "Från: Support\nSkickat: den 1 oktober 2026 08:00\nTill: {$sender}\nÄmne: Trasig växelriktare\n\n{$old}\n\n{$fresh}";

$first = jeab_inkorg_ingest([
    'message_id' => 'hs-latest-1@helpscout.net',
    'from' => $sender,
    'from_name' => 'Riktig Kund',
    'to' => 'support@exempel.test',
    'subject' => 'Trasig växelriktare',
    'body_text' => $old,
    'received_at' => '2026-10-18T08:00:00Z',
    'source' => 'helpscout-live',
    'thread_key' => 'hs-latest',
    'hs_conversation_id' => 'latest',
    'company' => 'jarfalla',
]);
tl(!empty($first['ok']) && empty($first['duplicate']), 'första supportmejlet sparas');

$reply = jeab_inkorg_ingest([
    'message_id' => 'hs-latest-2@helpscout.net',
    'from' => $sender,
    'from_name' => 'Riktig Kund',
    'to' => 'support@exempel.test',
    'subject' => 'Re: Trasig växelriktare',
    'body_text' => $bottom,
    'received_at' => '2026-10-18T08:12:00Z',
    'in_reply_to' => 'hs-latest-1@helpscout.net',
    'source' => 'helpscout-live',
    'thread_key' => 'hs-latest',
    'hs_conversation_id' => 'latest',
    'company' => 'jarfalla',
]);
tl(!empty($reply['ok']) && empty($reply['duplicate']), 'svar med citat före den nya texten sparas');

$open = null;
foreach (jeab_inkorg_threads() as $t) {
    if ((string) ($t['id'] ?? '') === 'hs-latest') {
        $open = $t;
        break;
    }
}
tl(is_array($open), 'tråden går att öppna');
$view = jeab_inkorg_thread_view_messages((array) ($open['messages'] ?? []));
tl(count($view) === 2, 'båda mejlen finns i vyn');
$top = $view[0];
tl(str_contains((string) ($top['body'] ?? ''), $fresh), 'senaste mejlet ligger överst');
$visible = jeab_inkorg_message_visible($top, [(string) ($view[1]['body'] ?? '')]);
tl(str_contains($visible, $fresh) && !str_contains($visible, $old), 'citatet ersätter inte den nya texten');
tl(str_contains((string) (($open['last']['body'] ?? '')), $fresh) || str_contains((string) ($open['list_snippet'] ?? ''), 'blinkar'), 'listan tar texten från senaste mejlet');

$same = jeab_inkorg_ingest([
    'message_id' => 'hs-latest-1@helpscout.net',
    'from' => $sender,
    'from_name' => 'Riktig Kund',
    'to' => 'support@exempel.test',
    'subject' => 'Re: Trasig växelriktare',
    'body_text' => 'En helt annan mening som kom med samma identitet.',
    'received_at' => '2026-10-18T09:00:00Z',
    'in_reply_to' => 'hs-latest-2@helpscout.net',
    'source' => 'helpscout-live',
    'thread_key' => 'hs-latest',
    'company' => 'jarfalla',
]);
tl(!empty($same['ok']) && empty($same['duplicate']) && (string) ($same['id'] ?? '') !== (string) ($first['id'] ?? ''), 'samma Message-ID med ny text blir ett eget mejl');

$again = null;
foreach (jeab_inkorg_threads() as $t) {
    if ((string) ($t['id'] ?? '') === 'hs-latest') {
        $again = $t;
        break;
    }
}
$againView = jeab_inkorg_thread_view_messages((array) ($again['messages'] ?? []));
tl(count($againView) === 3 && str_contains((string) ($againView[0]['body'] ?? ''), 'helt annan mening'), 'det nya mejlet ligger överst efter id-krock');

$graph = jeab_graph_ingest_message('jarfalla', [
    'id' => 'G-latest-reply',
    'internetMessageId' => '<graph-latest-reply@exempel.test>',
    'conversationId' => 'C-latest-reply',
    'from' => ['emailAddress' => ['address' => $sender, 'name' => 'Riktig Kund']],
    'subject' => 'Re: Trasig växelriktare',
    'receivedDateTime' => '2026-10-18T08:20:00Z',
    'body' => ['content' => 'Graph-svaret kom tolv minuter senare och ska ligga kvar.'],
    'toRecipients' => [['emailAddress' => ['address' => 'support@exempel.test']]],
]);
tl(!empty($graph['ok']) && empty($graph['duplicate']), 'HS-tvilling inom tjugo minuter släpper ett nytt svar');
$afterGraph = null;
foreach (jeab_inkorg_threads() as $t) {
    if ((string) ($t['id'] ?? '') === 'hs-latest') {
        $afterGraph = $t;
        break;
    }
}
$graphView = jeab_inkorg_thread_view_messages((array) ($afterGraph['messages'] ?? []));
$sawGraph = false;
foreach ($graphView as $m) {
    if (str_contains((string) ($m['body'] ?? ''), 'Graph-svaret kom tolv minuter')) {
        $sawGraph = true;
    }
}
tl($sawGraph, 'Graph-svaret ligger i samma tråd');

$list = jeab_inkorg_fast_list_html([$afterGraph], '', '', ['p' => 'inkorg'], [], 1, 1);
tl(str_contains($list, 'data-ix-rev="') && str_contains($list, (string) ($graphView[0]['id'] ?? 'missing')), 'listan bär en rev med senaste mejl-id');
$rev = jeab_inkorg_thread_rev($afterGraph);
tl($rev !== '|' && str_contains($list, $rev), 'rev innehåller updated_at och senaste id');

$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/inkorg.php');
tl(str_contains($page, 'function ixRev') && str_contains($page, "ixRev(url)") && str_contains($page, 'data-ix-rev'), 'förhämtningen nycklas med trådens rev');
tl(str_contains($page, 'data-ix-latest'), 'senaste mejlet markeras i tråden');

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_GET = ['p' => 'inkorg', 'id' => 'hs-latest'];
require_once dirname(__DIR__) . '/src/layout.php';
$html = (string) require dirname(__DIR__) . '/src/pages/inkorg.php';
$mark = strpos($html, 'data-ix-latest="1"');
$clean = $mark === false ? '' : substr($html, $mark, 900);
tl($mark !== false && str_contains($clean, 'helt annan mening') && strpos($html, 'helt annan mening') < strpos($html, $old), 'öppnad tråd visar senaste texten överst');

echo "thread latest ok\n";
