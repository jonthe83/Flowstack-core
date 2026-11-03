<?php

declare(strict_types=1);

/** Tysta stopp lämnar anteckning och påminnelse. Klassning som tystnar prövas av tick. Inget skickas. */

$tmp = sys_get_temp_dir() . '/jeab-auto-skip-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/tick.php';

function sk(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT'] = [];
$GLOBALS['JEAB_GRAPH_SEND'] = static function (array $a): array {
    $GLOBALS['_SENT'][] = $a;

    return ['ok' => true, 'from' => 'saljare3@jarfalla.example.test', 'graph_id' => 'g-skip', 'conversation_id' => 'CV', 'message_id' => 'skip@x'];
};
$GLOBALS['_JEAB_INKORG_LLM'] = static fn(): string => '';

function sk_notes(string $tid): string
{
    $bits = [];
    $thread = jeab_auto_thread_by_id($tid);
    $bags = [];
    if (is_array($thread)) {
        $bags[] = $thread;
    }
    $meta = jeab_inkorg_thread_meta($tid);
    if (is_array($meta)) {
        $bags[] = $meta;
    }
    foreach ($bags as $bag) {
        foreach ((array) ($bag['drafts'] ?? []) as $d) {
            if (is_array($d) && ($d['kind'] ?? '') === 'note') {
                $bits[] = (string) ($d['body'] ?? '');
            }
        }
    }

    return implode("\n", $bits);
}

function sk_reminders_for(string $tid): int
{
    $n = 0;
    foreach (jeab_list_reminders(jeab_store(), true) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string) ($row['thread_id'] ?? '') === $tid) {
            $n++;
            continue;
        }
        if (str_contains((string) ($row['note'] ?? ''), $tid)) {
            $n++;
        }
    }

    return $n;
}

$leadBodyFor = static function (string $id): string {
    $email = $id . '.skip@gmail.com';

    return "Källa: Hitta-Solceller via Drip\nNamn: Nora Skip\nE-post: {$email}\nTelefon: 070-333 22 11\nAdress: Dripvägen 3\nPostnummer: 176 70\nOrt: Järfälla\n";
};
$labels = [
    'type' => 'Ny lead',
    'action' => 'svara',
    'priority' => 1,
    'confidence' => 95,
    'source' => 'manual',
    'intent' => 'kop',
    'offer' => 'sol',
    'needs_review' => false,
    'reason' => 'solceller',
];

$ingest = static function (string $id, array $extra = []) use ($leadBodyFor, $labels): array {
    $in = [
        'message_id' => $id . '@skip.test',
        'from' => 'notifications@drip.com',
        'from_name' => 'Hitta-Solceller',
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => 'Hitta-Solceller via Drip ' . $id,
        'body_text' => $leadBodyFor($id),
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'preset_labels' => $labels,
    ];
    foreach ($extra as $k => $v) {
        $in[$k] = $v;
    }

    return jeab_inkorg_ingest($in);
};

jeab_auto_settings_save([
    'auto_quote_enabled' => 1,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_min_confidence' => 50,
    'tpl' => jeab_auto_quote_seed(),
]);

$sent = static fn(): int => count($GLOBALS['_SENT']);

$GLOBALS['_JEAB_KLASSNING_NO_SEND'] = 1;
$om = $ingest('omklass');
$omAuto = jeab_auto_on_message((string) $om['id']);
$omTid = (string) $om['thread_id'];
sk(($omAuto['why'] ?? '') === 'omklassning' && ($omAuto['sent'] ?? 1) === 0, 'omklassning skickar inte');
sk(str_contains(sk_notes($omTid), 'Offert skickades inte automatiskt: omklassning'), 'omklassning antecknas');
sk(sk_reminders_for($omTid) === 1 && $sent() === 0, 'omklassning lämnar en påminnelse och skickar inget');
unset($GLOBALS['_JEAB_KLASSNING_NO_SEND']);

jeab_kontakt_quiet_enter();
$merge = $ingest('merge');
$mergeThread = jeab_auto_thread_by_id((string) $merge['thread_id']);
$mergeMsg = jeab_inkorg_get((string) $merge['id']);
$mergeAuto = jeab_auto_quote(is_array($mergeThread) ? $mergeThread : [], is_array($mergeMsg) ? $mergeMsg : [], jeab_auto_settings(), [], ['automatic' => true]);
sk(($mergeAuto['why'] ?? '') === 'sammanslagning eller import' && ($mergeAuto['sent'] ?? 1) === 0, 'sammanslagning stoppar offerten');
sk(str_contains(sk_notes((string) $merge['thread_id']), 'Offert skickades inte automatiskt: sammanslagning eller import'), 'sammanslagning antecknas');
sk(sk_reminders_for((string) $merge['thread_id']) === 1 && $sent() === 0, 'sammanslagning lämnar en påminnelse');
jeab_kontakt_quiet_leave();

$imp = $ingest('import', ['source' => 'helpscout_import']);
$impAuto = jeab_auto_on_message((string) $imp['id']);
sk(($impAuto['why'] ?? '') === 'importerat ärende' && $sent() === 0, 'importerat ärende skickar inte');
sk(str_contains(sk_notes((string) $imp['thread_id']), 'Offert skickades inte automatiskt: importerat ärende'), 'importerat ärende antecknas');
sk(sk_reminders_for((string) $imp['thread_id']) === 1, 'importerat ärende lämnar en påminnelse');

$no = $ingest('noauto', ['preset_labels' => $labels + ['no_auto' => true]]);
$noAuto = jeab_auto_on_message((string) $no['id']);
sk(($noAuto['why'] ?? '') === 'ingen automatik' && $sent() === 0, 'ingen automatik skickar inte');
sk(str_contains(sk_notes((string) $no['thread_id']), 'Offert skickades inte automatiskt: ingen automatik'), 'ingen automatik antecknas');
sk(sk_reminders_for((string) $no['thread_id']) === 1, 'ingen automatik lämnar en påminnelse');

$support = $ingest('support', ['preset_labels' => [
    'type' => 'Support',
    'action' => 'svara',
    'priority' => 2,
    'confidence' => 90,
    'source' => 'manual',
    'intent' => 'support',
    'offer' => '',
    'no_auto' => true,
    'needs_review' => false,
], 'from' => 'bo.support@gmail.com', 'subject' => 'Laddaren krånglar', 'body_text' => 'Befintlig laddare är långsam.']);
$supportBefore = count(jeab_list_reminders(jeab_store(), true));
$supportAuto = jeab_auto_on_message((string) $support['id']);
sk(($supportAuto['why'] ?? '') === 'ingen automatik' && sk_reminders_for((string) $support['thread_id']) === 0 && !str_contains(sk_notes((string) $support['thread_id']), 'Offert skickades inte automatiskt'), 'support utan automatik antecknas inte som lead');
sk(count(jeab_list_reminders(jeab_store(), true)) === $supportBefore, 'support skapar ingen påminnelse');

$blockBody = "Namn: Bo Block\nE-post: bo.block@gmail.com\nTelefon: 070-444 55 66\nAdress: Blockvägen 2\nPostnummer: 176 70\nOrt: Järfälla\n";
$block = jeab_inkorg_ingest([
    'message_id' => 'block@skip.test',
    'from' => 'bo.block@gmail.com',
    'from_name' => 'Bo Block',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Hej',
    'body_text' => $blockBody,
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'preset_labels' => [
        'type' => 'Ny lead',
        'action' => 'svara',
        'priority' => 1,
        'confidence' => 90,
        'source' => 'manual',
        'intent' => 'annat',
        'offer' => '',
        'needs_review' => false,
    ],
]);
$blockThread = jeab_auto_thread_by_id((string) $block['thread_id']);
$blockMsg = jeab_inkorg_get((string) $block['id']);
$blockAuto = jeab_auto_quote(is_array($blockThread) ? $blockThread : [], is_array($blockMsg) ? $blockMsg : [], jeab_auto_settings(), [], ['automatic' => true]);
sk(str_contains((string) ($blockAuto['why'] ?? ''), 'köpintresse') && ($blockAuto['sent'] ?? 1) === 0 && $sent() === 0, 'sales block skickar inte');
sk(str_contains(sk_notes((string) $block['thread_id']), 'Offert skickades inte automatiskt:'), 'sales block antecknas');
sk(sk_reminders_for((string) $block['thread_id']) === 1, 'sales block lämnar en påminnelse');

$beforeHook = count(jeab_list_reminders(jeab_store(), true));
$derived = jeab_inkorg_hook(['action' => 'inbox.auto_skip_reason', 'thread' => (string) $support['thread_id']]);
sk(($derived['ok'] ?? false) === true && ($derived['read_only'] ?? false) === true && ($derived['would_send'] ?? true) === false && ($derived['sent'] ?? 1) === 0 && ($derived['recorded'] ?? 1) === 0 && ($derived['reason'] ?? '') === 'ingen automatik', 'hooken härleder ingen automatik utan att skriva');
sk(count(jeab_list_reminders(jeab_store(), true)) === $beforeHook && !str_contains(json_encode($derived), '@'), 'hooken skickar inget och visar ingen adress');
$recorded = jeab_inkorg_hook(['action' => 'inbox.auto_skip_reason', 'thread' => $omTid]);
sk(($recorded['recorded'] ?? 0) === 1 && ($recorded['reason'] ?? '') === 'omklassning' && ($recorded['would_send'] ?? true) === false, 'hooken lämnar den sparade orsaken');

jeab_auto_settings_save([
    'auto_quote_enabled' => 0,
    'auto_trash_spam' => 0,
    'auto_quote_only_from' => '',
    'auto_min_confidence' => 50,
    'tpl' => jeab_auto_quote_seed(),
]);
$failN = 0;
$GLOBALS['_JEAB_INKORG_LLM'] = static function () use (&$failN): string {
    $failN++;

    return '';
};
$fail = jeab_inkorg_ingest([
    'message_id' => 'fail@skip.test',
    'from' => 'notifications@drip.com',
    'from_name' => 'Hitta-Solceller',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Hitta-Solceller via Drip fail',
    'body_text' => $leadBodyFor('fail'),
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
]);
$failId = (string) $fail['id'];
$failTid = (string) $fail['thread_id'];
$failLabels = (array) (jeab_inkorg_get($failId)['labels'] ?? []);
sk(($failLabels['source'] ?? '') === 'none' && ($failLabels['classify_error'] ?? '') === 'xAI svarade inte' && (int) ($failLabels['classify_attempts'] ?? 0) === 1, 'tyst xAI märks som misslyckad klassning');
$failAuto = jeab_auto_on_message($failId);
$failQueued = jeab_auto_classify_pending_read()[$failId] ?? null;
sk(($failAuto['action'] ?? '') === 'retry' && ($failAuto['sent'] ?? 1) === 0 && !str_contains(sk_notes($failTid), 'Offert skickades inte automatiskt') && is_array($failQueued) && (int) ($failQueued['next_at'] ?? 0) > time(), 'första missen köas utan anteckning');
sk(jeab_auto_classify_pending_drain(20) === 0 && !str_contains(sk_notes($failTid), 'Offert skickades inte automatiskt') && isset(jeab_auto_classify_pending_read()[$failId]), 'backoff håller nästa försök');
$failClock = time();
$failDrains = 0;
for ($failI = 0; $failI < 10 && isset(jeab_auto_classify_pending_read()[$failId]); $failI++) {
    $failRow = jeab_auto_classify_pending_read()[$failId] ?? null;
    $failNext = is_array($failRow) ? (int) ($failRow['next_at'] ?? 0) : 0;
    $failClock = max($failClock + 1, $failNext + 1);
    $GLOBALS['_JEAB_NOW'] = $failClock;
    $failDrains += jeab_auto_classify_pending_drain(20);
}
$failLabelsLater = (array) (jeab_inkorg_get($failId)['labels'] ?? []);
sk($failDrains === 7 && !isset(jeab_auto_classify_pending_read()[$failId]) && (int) ($failLabelsLater['classify_attempts'] ?? 0) === 8, 'sista försöket tar ärendet ur kön');
sk(str_contains(sk_notes($failTid), 'Offert skickades inte automatiskt: klassningen misslyckades (xAI svarade inte)'), 'klassningen antecknas efter tick');
sk(sk_reminders_for($failTid) === 1 && $sent() === 0, 'klassningen lämnar en påminnelse och skickar inget');
$failHook = jeab_inkorg_hook(['action' => 'inbox.auto_skip_reason', 'thread' => $failTid]);
sk(($failHook['reason'] ?? '') === 'klassningen misslyckades (xAI svarade inte)' && ($failHook['recorded'] ?? 0) === 1 && ($failHook['sent'] ?? 1) === 0, 'hooken visar klassningsorsaken');

$plain = jeab_inkorg_ingest([
    'message_id' => 'plain@skip.test',
    'from' => 'vanlig.kund@gmail.com',
    'from_name' => 'Vanlig Kund',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Hej',
    'body_text' => 'Bara ett hej utan lead.',
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
]);
$plainAuto = jeab_auto_on_message((string) $plain['id']);
sk(($plainAuto['action'] ?? '') !== 'retry' && !isset(jeab_auto_classify_pending_read()[(string) $plain['id']]) && !str_contains(sk_notes((string) $plain['thread_id']), 'Offert skickades inte automatiskt'), 'vanligt mejl utan lead köas inte');

$okN = 0;
$GLOBALS['_JEAB_INKORG_LLM'] = static function () use (&$okN): string {
    $okN++;
    if ($okN === 1) {
        return '';
    }

    return json_encode([
        'type' => 'Ny lead',
        'confidence' => 95,
        'intent' => 'kop',
        'offer' => 'sol',
        'reason' => 'kunden vill ha solceller',
        'label' => 'Solceller',
        'case_intent' => 'kopintresse',
        'primary_product' => 'Sol',
        'kind' => 'atgard',
    ], JSON_UNESCAPED_UNICODE);
};
$again = jeab_inkorg_ingest([
    'message_id' => 'again@skip.test',
    'from' => 'notifications@drip.com',
    'from_name' => 'Hitta-Solceller',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Hitta-Solceller via Drip again',
    'body_text' => $leadBodyFor('again'),
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
]);
$againAuto = jeab_auto_on_message((string) $again['id']);
sk(($againAuto['action'] ?? '') === 'retry' && $sent() === 0, 'ny tyst klassning skickar inte');
$againRow = jeab_auto_classify_pending_read()[(string) $again['id']] ?? null;
$GLOBALS['_JEAB_NOW'] = (int) (is_array($againRow) ? ($againRow['next_at'] ?? 0) : 0) + 1;
$againDrain = jeab_auto_classify_pending_drain(20);
$againFresh = (array) (jeab_inkorg_get((string) $again['id'])['labels'] ?? []);
sk($againDrain === 1 && ($againFresh['classify_error'] ?? '') === '' && ($againFresh['type'] ?? '') === 'Ny lead' && !isset(jeab_auto_classify_pending_read()[(string) $again['id']]) && $sent() === 0, 'tick klassar om utan att skicka');
sk(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/tick.php'), 'jeab_auto_classify_pending_drain') && str_contains((string) file_get_contents(dirname(__DIR__) . '/src/tick.php'), 'jeab_auto_classify_requeue_failed'), 'tick anropar kön');
sk(str_contains(implode("\n", jeab_auto_rule_lines()), 'inbox.auto_skip_reason'), 'regeln nämner hooken');

echo "OK  auto skip\n";
