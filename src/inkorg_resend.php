<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

/**
 * Omsändning och tilldelning.
 * Kedjan för en offert hinner inte inom nginx 60s: grok-4.7 medium väntar upp till 20s,
 * Graph väntar 25s per anrop, och jeab_gp_send läser Skickat upp till fyra gånger
 * med 1,5s paus. Help Scout med bilaga väntar 60s. Därför svarar resend direkt.
 */

function jeab_resend_profile_add(string $stage, int $ms): void
{
    if (empty($GLOBALS['_JEAB_RESEND_PROFILE_ON'])) {
        return;
    }
    if (!in_array($stage, ['pdf', 'grok', 'graph', 'helpscout'], true)) {
        return;
    }
    $bag = is_array($GLOBALS['_JEAB_RESEND_PROFILE'] ?? null) ? $GLOBALS['_JEAB_RESEND_PROFILE'] : [];
    $bag[$stage] = (int) ($bag[$stage] ?? 0) + max(0, $ms);
    $GLOBALS['_JEAB_RESEND_PROFILE'] = $bag;
}

/** @return array<string, mixed> */
function jeab_inkorg_resend_chain(): array
{
    return [
        'pdf' => ['budget_ms' => 50, 'note' => 'läser färdig PDF'],
        'grok' => ['budget_ms' => 20000, 'model' => 'grok-4.7', 'reasoning_effort' => 'medium', 'note' => 'kort svar innan utskick'],
        'graph' => ['budget_ms' => 25000, 'polls' => 4, 'sleep_ms' => 1500, 'note' => 'sendMail och upp till fyra uppslag i Skickat'],
        'helpscout' => ['budget_ms' => 60000, 'note' => 'samtal med bilaga'],
        'nginx_ms' => 60000,
        'slow_reason' => 'Ett enda utskick hinner inte inom 60s. Grok väntar upp till 20s. Graph väntar 25s, och personens brevlåda läser Skickat fyra gånger efteråt. Help Scout med bilaga väntar 60s. Nginx bryter innan svaret hinner ut.',
    ];
}

/** @param array<string, mixed> $row @return array<string, mixed> */
function jeab_inkorg_resend_safe(array $row): array
{
    $out = [];
    foreach ($row as $key => $value) {
        if (is_array($value)) {
            $out[$key] = jeab_inkorg_resend_safe($value);
            continue;
        }
        if (is_string($value) && str_contains($value, '@')) {
            continue;
        }
        $out[$key] = $value;
    }

    return $out;
}

function jeab_inkorg_resend_pdo(): ?PDO
{
    return function_exists('jeab_inkorg_learn_pdo') ? jeab_inkorg_learn_pdo() : null;
}

function jeab_inkorg_resend_ensure(PDO $pdo): void
{
    static $done = [];
    $key = spl_object_id($pdo);
    if (isset($done[$key]) || (function_exists('jeab_pdo_tx_busy') && jeab_pdo_tx_busy($pdo))) {
        return;
    }
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($mysql) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_resend_jobs (
                id VARCHAR(64) NOT NULL,
                thread_id VARCHAR(191) NOT NULL,
                message_id VARCHAR(191) NOT NULL,
                status VARCHAR(16) NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                started_at VARCHAR(40) NOT NULL,
                finished_at VARCHAR(40) NOT NULL,
                sent TINYINT NOT NULL DEFAULT 0,
                action VARCHAR(32) NOT NULL,
                sender VARCHAR(191) NOT NULL,
                template VARCHAR(64) NOT NULL,
                recipient VARCHAR(8) NOT NULL,
                profile MEDIUMTEXT NOT NULL,
                chain MEDIUMTEXT NOT NULL,
                error TEXT NULL,
                dry TINYINT NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                KEY inkorg_resend_jobs_status (status),
                KEY inkorg_resend_jobs_thread (thread_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_assignee_undo (
                batch VARCHAR(32) NOT NULL,
                thread_id VARCHAR(191) NOT NULL,
                thread_assignee VARCHAR(64) NOT NULL,
                reminder_id INT NOT NULL,
                reminder_assignee VARCHAR(191) NOT NULL,
                reminder_person VARCHAR(64) NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (batch)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS inkorg_resend_jobs (
                id TEXT PRIMARY KEY,
                thread_id TEXT NOT NULL,
                message_id TEXT NOT NULL,
                status TEXT NOT NULL,
                created_at TEXT NOT NULL,
                started_at TEXT NOT NULL,
                finished_at TEXT NOT NULL,
                sent INTEGER NOT NULL DEFAULT 0,
                action TEXT NOT NULL,
                sender TEXT NOT NULL,
                template TEXT NOT NULL,
                recipient TEXT NOT NULL,
                profile TEXT NOT NULL,
                chain TEXT NOT NULL,
                error TEXT NOT NULL DEFAULT '',
                dry INTEGER NOT NULL DEFAULT 0
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS inkorg_resend_jobs_status ON inkorg_resend_jobs (status)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS inkorg_assignee_undo (
                batch TEXT PRIMARY KEY,
                thread_id TEXT NOT NULL,
                thread_assignee TEXT NOT NULL,
                reminder_id INTEGER NOT NULL,
                reminder_assignee TEXT NOT NULL,
                reminder_person TEXT NOT NULL,
                created_at TEXT NOT NULL
            )'
        );
    }
    if (function_exists('jeab_ensure_table_columns')) {
        $driver = $mysql ? 'mysql' : 'sqlite';
        jeab_ensure_table_columns($pdo, 'inkorg_resend_jobs', [
            'error' => $mysql ? 'TEXT NULL' : 'TEXT NOT NULL DEFAULT \'\'',
            'dry' => $mysql ? 'TINYINT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0',
        ], $driver);
    }
    $done[$key] = true;
}

/** @param array<string, mixed> $row @return array<string, mixed> */
function jeab_inkorg_resend_job_row(array $row): array
{
    $profile = json_decode((string) ($row['profile'] ?? ''), true);
    $chain = json_decode((string) ($row['chain'] ?? ''), true);

    return [
        'id' => (string) ($row['id'] ?? ''),
        'thread_id' => (string) ($row['thread_id'] ?? ''),
        'message_id' => (string) ($row['message_id'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'started_at' => (string) ($row['started_at'] ?? ''),
        'finished_at' => (string) ($row['finished_at'] ?? ''),
        'sent' => (int) ($row['sent'] ?? 0),
        'action' => (string) ($row['action'] ?? ''),
        'sender' => (string) ($row['sender'] ?? ''),
        'template' => (string) ($row['template'] ?? ''),
        'recipient' => (string) ($row['recipient'] ?? 'no'),
        'profile' => is_array($profile) ? $profile : [],
        'chain' => is_array($chain) ? $chain : [],
        'error' => (string) ($row['error'] ?? ''),
        'dry' => (int) ($row['dry'] ?? 0) === 1 ? 1 : 0,
    ];
}

function jeab_inkorg_resend_reason(string $why): string
{
    $why = trim(preg_replace('/\s+/u', ' ', $why) ?? $why);
    if ($why === '') {
        return '';
    }
    if (str_contains($why, '@')) {
        return 'orsak utan adress';
    }

    return mb_substr($why, 0, 240);
}

function jeab_inkorg_resend_sapi(): string
{
    $over = $GLOBALS['_JEAB_RESEND_SAPI'] ?? '';

    return is_string($over) && $over !== '' ? $over : PHP_SAPI;
}

/**
 * Känd offert på meddelandet eller i texten.
 *
 * @param array<string, mixed> $thread
 * @param array<string, mixed> $msg
 */
function jeab_inkorg_resend_offer(array $thread, array $msg): string
{
    $labels = (array) ($msg['labels'] ?? []);
    $raw = (string) ($labels['offer'] ?? '');
    if (function_exists('jeab_auto_offer_is') && jeab_auto_offer_is($raw)) {
        return $raw;
    }
    $inferred = function_exists('jeab_lead_infer_offer') ? (string) jeab_lead_infer_offer($msg, (array) ($thread['tags'] ?? [])) : '';
    if (function_exists('jeab_auto_offer_is') && jeab_auto_offer_is($inferred)) {
        return $inferred;
    }

    return '';
}

/**
 * Bekräftad omsändning ska nå offertvägen även när typetiketten inte längre är Ny lead.
 * close_quoted före det avbrottet är Graph-steget som inte skickar något.
 * Skräp lämnas.
 */
function jeab_inkorg_resend_prepare_lead(array $thread, string $mid): void
{
    if ($mid === '' || !function_exists('jeab_inkorg_get') || !function_exists('jeab_inkorg_klassning_stamp')) {
        return;
    }
    $msg = jeab_inkorg_get($mid);
    if (!is_array($msg)) {
        return;
    }
    $labels = (array) ($msg['labels'] ?? []);
    $type = (string) ($labels['type'] ?? '');
    $intent = (string) ($labels['intent'] ?? '');
    if ($type === 'Skräp' || $intent === 'skrap') {
        return;
    }
    $offer = jeab_inkorg_resend_offer($thread, $msg);
    if ($offer === '') {
        return;
    }
    if ($type === 'Ny lead' && function_exists('jeab_auto_offer_is') && jeab_auto_offer_is((string) ($labels['offer'] ?? ''))) {
        return;
    }
    jeab_inkorg_klassning_stamp($mid, 'Ny lead', 'kop', $offer, true);
}

function jeab_inkorg_message_is_weply(array $msg): bool
{
    $from = function_exists('jeab_msg_platform_address') ? jeab_msg_platform_address($msg) : (string) ($msg['from'] ?? '');
    $platform = function_exists('jeab_lead_platform') ? jeab_lead_platform($from) : '';

    return in_array($platform, ['weply.se', 'weply.dk'], true);
}

function jeab_inkorg_weply_malmo_place(array $thread, array $msg): bool
{
    if (function_exists('jeab_inkorg_thread_company') && jeab_inkorg_thread_company($thread) === 'malmo') {
        return true;
    }
    $place = function_exists('jeab_inkorg_lead_place_text') ? jeab_inkorg_lead_place_text($thread, $msg) : '';
    if ($place !== '' && function_exists('jeab_inkorg_skane_text') && jeab_inkorg_skane_text($place)) {
        return true;
    }

    return $place !== '' && function_exists('jeab_inkorg_place_is_malmo') && jeab_inkorg_place_is_malmo($place);
}

function jeab_inkorg_weply_malmo_offer(array $thread, array $msg): string
{
    if (!jeab_inkorg_message_is_weply($msg) || !jeab_inkorg_weply_malmo_place($thread, $msg)) {
        return '';
    }
    $known = ['batteri', 'laddbox', 'sol', 'sol+batteri'];
    if (function_exists('jeab_inkorg_form_quote_offer')) {
        $form = jeab_inkorg_form_quote_offer($msg);
        if (in_array($form, $known, true)) {
            return $form === 'sol+batteri' ? 'sol' : $form;
        }
    }
    if (function_exists('jeab_inkorg_purchase_offer_detail')) {
        $detail = (string) (jeab_inkorg_purchase_offer_detail($msg)['offer'] ?? '');
        if (in_array($detail, $known, true)) {
            return $detail === 'sol+batteri' ? 'sol' : $detail;
        }
    }
    $labeled = function_exists('jeab_lead_offer') ? jeab_lead_offer((array) ($msg['labels'] ?? []), (array) ($thread['tags'] ?? [])) : '';
    if (in_array($labeled, $known, true)) {
        return $labeled === 'sol+batteri' ? 'sol' : $labeled;
    }
    $inferred = function_exists('jeab_lead_infer_offer') ? jeab_lead_infer_offer($msg, (array) ($thread['tags'] ?? [])) : '';
    if (in_array($inferred, $known, true)) {
        return $inferred === 'sol+batteri' ? 'sol' : $inferred;
    }

    return '';
}

/** kim = batteri eller laddbox. sol = lämnas. Tomt = inte Malmö-Weply. */
function jeab_inkorg_weply_malmo_class(array $thread, array $msg): string
{
    $offer = jeab_inkorg_weply_malmo_offer($thread, $msg);
    if (in_array($offer, ['batteri', 'laddbox'], true)) {
        return 'kim';
    }
    if ($offer === 'sol') {
        return 'sol';
    }

    return '';
}

function jeab_inkorg_weply_malmo_claim(string $tid, string $offer, string $mid): void
{
    if ($tid === '' || !function_exists('jeab_inkorg_thread_write')) {
        return;
    }
    jeab_inkorg_thread_write($tid, static function (array $t): array {
        $t['malmo_hs_kim'] = true;
        $t['brand'] = 'malmo';
        $t['assignee'] = 'Kim';
        unset($t['no_auto']);
        $t['updated_at'] = date('c');

        return $t;
    });
    if ($mid !== '' && in_array($offer, ['batteri', 'laddbox'], true) && function_exists('jeab_inkorg_klassning_stamp')) {
        jeab_inkorg_klassning_stamp($mid, 'Ny lead', 'kop', $offer, true);
    }
}

/** @return array{class: string, thread: array<string, mixed>, msg: array<string, mixed>} */
function jeab_inkorg_weply_malmo_prepare(array $thread, array $msg): array
{
    $class = jeab_inkorg_weply_malmo_class($thread, $msg);
    if ($class === 'sol' && function_exists('jeab_inkorg_thread_write')) {
        $tid = (string) ($thread['id'] ?? '');
        if ($tid !== '') {
            jeab_inkorg_thread_write($tid, static function (array $t): array {
                $t['brand'] = 'malmo';
                $t['assignee'] = 'SamW';
                unset($t['malmo_hs_kim']);
                $t['updated_at'] = date('c');

                return $t;
            });
            if (function_exists('jeab_auto_thread_by_id')) {
                $fresh = jeab_auto_thread_by_id($tid);
                if (is_array($fresh)) {
                    $thread = $fresh;
                }
            }
        }
    }
    if ($class === 'kim') {
        $offer = jeab_inkorg_weply_malmo_offer($thread, $msg);
        jeab_inkorg_weply_malmo_claim((string) ($thread['id'] ?? ''), $offer, (string) ($msg['id'] ?? ''));
        $tid = (string) ($thread['id'] ?? '');
        if ($tid !== '' && function_exists('jeab_auto_thread_by_id')) {
            $fresh = jeab_auto_thread_by_id($tid);
            if (is_array($fresh)) {
                $thread = $fresh;
            }
        }
        $mid = (string) ($msg['id'] ?? '');
        if ($mid !== '' && function_exists('jeab_inkorg_get')) {
            $freshMsg = jeab_inkorg_get($mid);
            if (is_array($freshMsg)) {
                $msg = $freshMsg;
            }
        }
    }

    return ['class' => $class, 'thread' => $thread, 'msg' => $msg];
}

/** @return array{sender: string, template: string, recipient: string} */
function jeab_inkorg_resend_preview(array $thread): array
{
    $msg = is_array($thread['first'] ?? null) ? $thread['first'] : [];
    if ($msg === [] && isset($thread['messages'][0]) && is_array($thread['messages'][0])) {
        $msg = $thread['messages'][0];
    }
    $party = jeab_inkorg_resend_party($thread);
    $company = (string) ($party['company'] ?? 'jarfalla');
    $offer = '';
    $labels = (array) ($msg['labels'] ?? []);
    $raw = (string) ($labels['offer'] ?? '');
    if (function_exists('jeab_auto_offer_is') && jeab_auto_offer_is($raw)) {
        $offer = $raw;
    } elseif (function_exists('jeab_lead_infer_offer')) {
        $offer = jeab_lead_infer_offer($msg, (array) ($thread['tags'] ?? []));
    }
    $mail = function_exists('jeab_auto_offer_mail') ? jeab_auto_offer_mail($offer) : $offer;
    if (!in_array($mail, ['batteri', 'laddbox', 'sol'], true)) {
        $mail = '';
    }
    $variant = '';
    if ($mail === 'sol' && $company === 'jarfalla' && function_exists('jeab_auto_sol_variant')) {
        $variant = jeab_auto_sol_variant($msg);
    }
    $template = '';
    if ($mail !== '' && function_exists('jeab_notiser_quote_notice_id')) {
        $template = jeab_notiser_quote_notice_id($mail, $company, $variant, $variant === 'kopt' || $variant === 'eget');
    }
    $sender = (string) ($party['sender'] ?? '');
    if ($sender === '' && $mail !== '' && function_exists('jeab_auto_from_name')) {
        $sender = jeab_auto_from_name($mail, $company);
    }
    $email = '';
    if (function_exists('jeab_lead_customer')) {
        $email = trim((string) (jeab_lead_customer($msg)['email'] ?? ''));
    }
    if ($email === '') {
        $from = trim((string) ($msg['from'] ?? ''));
        $platform = function_exists('jeab_lead_platform') ? jeab_lead_platform($from) : '';
        if ($platform === '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $email = $from;
        }
    }
    $present = filter_var($email, FILTER_VALIDATE_EMAIL) ? 'yes' : 'no';

    return jeab_inkorg_resend_safe([
        'sender' => $sender,
        'template' => $template,
        'recipient' => $present,
    ]);
}

/**
 * Kontor och avsändarnamn från trådens nuvarande person.
 * Utan person gäller mejlboxen och produktens vanliga namn.
 *
 * @param array<string, mixed> $thread
 * @return array{assignee: string, company: string, sender: string}
 */
function jeab_inkorg_resend_party(array $thread): array
{
    $assignee = trim((string) ($thread['assignee'] ?? ''));
    $name = '';
    $company = '';
    if ($assignee !== '' && function_exists('jeab_people')) {
        foreach (jeab_people() as $person) {
            if (!is_array($person) || (string) ($person['id'] ?? '') !== $assignee) {
                continue;
            }
            $office = (string) ($person['company'] ?? '');
            if ($office === 'malmo' || $office === 'jarfalla') {
                $company = $office;
                $name = trim((string) ($person['name'] ?? ''));
            }
            break;
        }
    }
    if ($company !== 'malmo' && $company !== 'jarfalla') {
        $company = function_exists('jeab_inkorg_thread_company') ? jeab_inkorg_thread_company($thread) : 'jarfalla';
        if ($company !== 'malmo') {
            $company = 'jarfalla';
        }
    }
    $sender = '';
    if ($name !== '') {
        $sender = $name . ', ' . ($company === 'malmo' ? 'Demo Energi Malmö' : 'Demo Energi Järfälla');
    }

    return ['assignee' => $assignee, 'company' => $company, 'sender' => $sender];
}

/** @param array<string, mixed> $thread */
function jeab_inkorg_resend_align_office(array $thread): void
{
    $party = jeab_inkorg_resend_party($thread);
    $tid = (string) ($thread['id'] ?? '');
    if ($tid === '' || $party['sender'] === '' || !function_exists('jeab_inkorg_thread_write')) {
        return;
    }
    $company = $party['company'];
    $assignee = $party['assignee'];
    jeab_inkorg_thread_write($tid, static function (array $t) use ($company, $assignee): array {
        $t['brand'] = $company;
        $t['assignee'] = $assignee;
        if ($company === 'malmo' && $assignee === 'Kim') {
            $t['malmo_hs_kim'] = true;
            unset($t['no_auto']);
        }
        if ($company !== 'malmo') {
            unset($t['malmo_hs_kim']);
        }
        $t['updated_at'] = date('c');

        return $t;
    });
}

/**
 * Verkligt utskick: sent_log, quote_sent_diag och Outlook Skickat.
 * missed_resend_at räknas inte.
 *
 * @param array<string, mixed> $thread
 * @return array{sent_log: string, quote_sent_diag: string, outlook_sent: string, any: string}
 */
function jeab_inkorg_resend_evidence(array $thread): array
{
    $tid = (string) ($thread['id'] ?? '');
    $log = 'no';
    $diag = 'no';
    $outlook = 'no';
    if ($tid !== '' && function_exists('jeab_inbox_sent_log')) {
        try {
            $rows = jeab_inbox_sent_log([]);
            foreach ((array) ($rows['rows'] ?? []) as $row) {
                if (is_array($row) && (string) ($row['thread_id'] ?? '') === $tid) {
                    $log = 'yes';
                    break;
                }
            }
        } catch (Throwable $e) {
            unset($e);
        }
    }
    if ($tid !== '' && function_exists('jeab_auto_quote_sent_diag')) {
        try {
            $sources = jeab_auto_quote_sent_diag(['thread_id' => $tid]);
            foreach ((array) ($sources['sources'] ?? []) as $name => $row) {
                if (!is_array($row) || empty($row['hit'])) {
                    continue;
                }
                $diag = 'yes';
                if ($name === 'graph_search' || $name === 'graph_scan') {
                    $outlook = 'yes';
                }
            }
        } catch (Throwable $e) {
            unset($e);
        }
    }

    return [
        'sent_log' => $log,
        'quote_sent_diag' => $diag,
        'outlook_sent' => $outlook,
        'any' => ($log === 'yes' || $diag === 'yes' || $outlook === 'yes') ? 'yes' : 'no',
    ];
}

function jeab_inkorg_resend_inflight(string $tid): string
{
    if ($tid === '') {
        return '';
    }
    $pdo = jeab_inkorg_resend_pdo();
    if (!$pdo instanceof PDO) {
        return '';
    }
    jeab_inkorg_resend_ensure($pdo);
    $stmt = $pdo->prepare("SELECT id FROM inkorg_resend_jobs WHERE thread_id = ? AND status IN ('queued', 'running') ORDER BY created_at ASC LIMIT 1");
    $stmt->execute([$tid]);
    $id = $stmt->fetchColumn();

    return is_string($id) ? $id : '';
}

/** @param array<string, mixed> $in @return array<string, mixed> */
function jeab_inkorg_resend_missed_body(array $in): array
{
    $dry = jeab_auto_send_quote_is_dry($in);
    if (!$dry && (string) ($in['confirm'] ?? '') !== 'SAM') {
        return ['ok' => false, 'error' => 'confirm', 'dry' => 0, 'sent' => 0, 'would_send' => false, 'leads' => []];
    }
    $ids = $in['ids'] ?? $in['thread_ids'] ?? null;
    if (!is_array($ids) || $ids === []) {
        $one = trim((string) ($in['thread'] ?? $in['thread_id'] ?? $in['id'] ?? ''));
        $ids = $one !== '' ? [$one] : jeab_inkorg_missed_sunday_ids();
    }
    $rows = [];
    $left = [];
    $queued = null;
    $seen = 0;
    $GLOBALS['_JEAB_QUOTE_CHAIN_BATCH'] = [];
    foreach ($ids as $raw) {
        $id = trim((string) $raw);
        if ($id === '') {
            continue;
        }
        $seen++;
        $thread = jeab_inkorg_resend_thread($id);
        if (!is_array($thread)) {
            $rows[] = ['id' => $id, 'found' => 0, 'quoted' => 0, 'reminder_id' => 0, 'action' => 'missing', 'sent' => 0, 'sender' => '', 'template' => '', 'recipient' => 'no'];
            continue;
        }
        $tid = (string) ($thread['id'] ?? '');
        $preview = jeab_inkorg_resend_preview($thread);
        $quoted = jeab_inkorg_lead_backfill_quoted($thread) ? 1 : 0;
        $rid = function_exists('jeab_quote_chain_leads_reminder_id') ? (int) jeab_quote_chain_leads_reminder_id($thread) : 0;
        if ($rid < 1 && function_exists('jeab_inkorg_thread_list_reminder_for')) {
            $rid = (int) jeab_inkorg_thread_list_reminder_for($thread);
        }
        $leadMsg = is_array($thread['first'] ?? null) ? $thread['first'] : [];
        $blocked = function_exists('jeab_quote_chain_duplicate_block') && jeab_quote_chain_duplicate_block($thread, $leadMsg);
        if ($blocked) {
            $rows[] = [
                'id' => $tid,
                'found' => 1,
                'quoted' => $quoted,
                'reminder_id' => $rid,
                'action' => 'blocked_duplicate',
                'sent' => 0,
                'backfilled' => (int) ($thread['backfilled'] ?? 0) === 1 ? 1 : 0,
            ] + $preview;
            continue;
        }
        if (!$dry && $queued !== null) {
            $left[] = $id;
            continue;
        }
        $evidence = jeab_inkorg_resend_evidence($thread);
        $already = ($evidence['any'] ?? 'no') === 'yes';
        if ($dry || $already) {
            if ($dry && !$already && function_exists('jeab_quote_chain_batch_claim')) {
                jeab_quote_chain_batch_claim($thread);
            }
            $rows[] = [
                'id' => $tid,
                'found' => 1,
                'quoted' => $quoted,
                'reminder_id' => $rid,
                'action' => $already ? 'already' : 'would',
                'sent' => 0,
                'backfilled' => (int) ($thread['backfilled'] ?? 0) === 1 ? 1 : 0,
                'evidence' => $evidence,
            ] + $preview;
            continue;
        }
        if (function_exists('jeab_quote_chain_batch_claim')) {
            jeab_quote_chain_batch_claim($thread);
        }
        $job = jeab_inkorg_resend_enqueue($thread);
        $rows[] = [
            'id' => $tid,
            'found' => 1,
            'quoted' => $quoted,
            'reminder_id' => $rid,
            'action' => (string) ($job['action'] ?? 'queued'),
            'sent' => 0,
            'job' => (string) ($job['job'] ?? ''),
        ] + $preview;
        $queued = $job;
    }
    unset($GLOBALS['_JEAB_QUOTE_CHAIN_BATCH']);
    if ($dry) {
        return jeab_inkorg_resend_safe([
            'ok' => true,
            'dry' => 1,
            'sent' => 0,
            'would_send' => false,
            'count' => count($rows),
            'leads' => $rows,
            'chain' => jeab_inkorg_resend_chain(),
        ]);
    }

    return jeab_inkorg_resend_safe([
        'ok' => true,
        'dry' => 0,
        'sent' => 0,
        'would_send' => false,
        'queued' => ($queued['action'] ?? '') === 'queued' ? 1 : 0,
        'background' => ($queued['action'] ?? '') === 'queued' ? 1 : 0,
        'job' => (string) ($queued['job'] ?? ''),
        'status' => (string) ($queued['action'] ?? ($seen === 0 ? 'missing' : 'already')),
        'count' => count($rows),
        'left' => $left,
        'leads' => $rows,
    ]);
}

/** @param array<string, mixed> $thread @return array{action: string, job: string} */
function jeab_inkorg_resend_enqueue(array $thread): array
{
    $tid = (string) ($thread['id'] ?? '');
    if ((jeab_inkorg_resend_evidence($thread)['any'] ?? 'no') === 'yes') {
        return ['action' => 'already', 'job' => ''];
    }
    $inflight = jeab_inkorg_resend_inflight($tid);
    if ($inflight !== '') {
        return ['action' => 'queued', 'job' => $inflight];
    }
    $jobId = 'rj' . bin2hex(random_bytes(8));
    $claimed = false;
    $at = '';
    jeab_inkorg_thread_write($tid, static function (array $t) use (&$claimed, &$at, $jobId): array {
        $at = date('c');
        $t['missed_resend_at'] = $at;
        $t['resend_job'] = $jobId;
        $claimed = true;

        return $t;
    });
    if (!$claimed) {
        return ['action' => 'error', 'job' => ''];
    }
    $first = (array) ($thread['first'] ?? []);
    $mid = (string) ($first['id'] ?? '');
    if ($mid === '' && isset($thread['messages'][0]) && is_array($thread['messages'][0])) {
        $mid = (string) ($thread['messages'][0]['id'] ?? '');
    }
    $preview = jeab_inkorg_resend_preview($thread);
    $job = [
        'id' => $jobId,
        'thread_id' => $tid,
        'message_id' => $mid,
        'status' => 'queued',
        'created_at' => $at,
        'started_at' => '',
        'finished_at' => '',
        'sent' => 0,
        'action' => 'queued',
        'sender' => (string) ($preview['sender'] ?? ''),
        'template' => (string) ($preview['template'] ?? ''),
        'recipient' => (string) ($preview['recipient'] ?? 'no'),
        'profile' => [],
        'chain' => jeab_inkorg_resend_chain(),
    ];
    $pdo = jeab_inkorg_resend_pdo();
    if (!$pdo instanceof PDO) {
        return ['action' => 'error', 'job' => ''];
    }
    jeab_inkorg_resend_ensure($pdo);
    $profile = json_encode($job['profile'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $chain = json_encode($job['chain'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare(
        'INSERT INTO inkorg_resend_jobs
            (id, thread_id, message_id, status, created_at, started_at, finished_at, sent, action, sender, template, recipient, profile, chain, error, dry)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $jobId,
        $tid,
        $mid,
        'queued',
        $at,
        '',
        '',
        0,
        'queued',
        (string) $job['sender'],
        (string) $job['template'],
        (string) $job['recipient'],
        is_string($profile) ? $profile : '[]',
        is_string($chain) ? $chain : '{}',
        '',
        0,
    ]);

    return ['action' => 'queued', 'job' => $jobId];
}

function jeab_inkorg_resend_after_response(string $jobId): void
{
    $sapi = jeab_inkorg_resend_sapi();
    if ($jobId === '' || $sapi === 'cli' || $sapi === 'phpdbg') {
        return;
    }
    ignore_user_abort(true);
    @set_time_limit(180);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_flush();
        flush();
    }
    jeab_inkorg_resend_work($jobId);
}

function jeab_inkorg_resend_drain_queued(): int
{
    $pdo = jeab_inkorg_resend_pdo();
    if (!$pdo instanceof PDO) {
        return 0;
    }
    jeab_inkorg_resend_ensure($pdo);
    $next = (string) $pdo->query("SELECT id FROM inkorg_resend_jobs WHERE status = 'queued' ORDER BY created_at ASC LIMIT 1")->fetchColumn();
    if ($next === '') {
        return 0;
    }
    ignore_user_abort(true);
    @set_time_limit(180);
    $done = jeab_inkorg_resend_work($next);

    return (string) ($done['status'] ?? '') === 'running' ? 0 : 1;
}

/** @return array<string, mixed> */
function jeab_inkorg_resend_work(string $jobId): array
{
    $jobId = trim($jobId);
    $pdo = jeab_inkorg_resend_pdo();
    if (!$pdo instanceof PDO || $jobId === '') {
        return jeab_inkorg_resend_status(['job' => $jobId]);
    }
    jeab_inkorg_resend_ensure($pdo);
    $claim = $pdo->prepare("UPDATE inkorg_resend_jobs SET status = 'running', started_at = ? WHERE id = ? AND status = 'queued'");
    $claim->execute([date('c'), $jobId]);
    if ($claim->rowCount() !== 1) {
        return jeab_inkorg_resend_status(['job' => $jobId]);
    }
    $job = jeab_inkorg_resend_job_get($jobId);
    $threadNow = jeab_inkorg_resend_thread((string) ($job['thread_id'] ?? ''));
    if (is_array($threadNow) && (jeab_inkorg_resend_evidence($threadNow)['any'] ?? 'no') === 'yes') {
        jeab_inkorg_resend_finish($pdo, $jobId, 'done', 'already', 0, [], 'redan skickad');

        return jeab_inkorg_resend_status(['job' => $jobId]);
    }
    $party = is_array($threadNow) ? jeab_inkorg_resend_party($threadNow) : ['company' => '', 'assignee' => '', 'sender' => ''];
    if (is_array($threadNow)) {
        jeab_inkorg_resend_align_office($threadNow);
    }
    $hadOffice = array_key_exists('_JEAB_RESEND_OFFICE', $GLOBALS);
    $hadKim = array_key_exists('_JEAB_RESEND_KIM', $GLOBALS);
    $oldOffice = $GLOBALS['_JEAB_RESEND_OFFICE'] ?? null;
    $oldKim = $GLOBALS['_JEAB_RESEND_KIM'] ?? null;
    if ((string) ($party['sender'] ?? '') !== '') {
        $GLOBALS['_JEAB_RESEND_OFFICE'] = (string) $party['company'];
        $GLOBALS['_JEAB_RESEND_KIM'] = (string) $party['company'] === 'malmo' && (string) $party['assignee'] === 'Kim';
    }
    ignore_user_abort(true);
    @set_time_limit(180);
    $mid = (string) ($job['message_id'] ?? '');
    if (is_array($threadNow)) {
        jeab_inkorg_resend_prepare_lead($threadNow, $mid);
    }
    $GLOBALS['_JEAB_RESEND_PROFILE_ON'] = true;
    $GLOBALS['_JEAB_RESEND_PROFILE'] = [];
    if (is_array($GLOBALS['_JEAB_RESEND_PROFILE_INJECT'] ?? null)) {
        foreach ($GLOBALS['_JEAB_RESEND_PROFILE_INJECT'] as $stage => $ms) {
            if (is_string($stage) && is_numeric($ms)) {
                jeab_resend_profile_add($stage, (int) $ms);
            }
        }
    }
    $res = ['action' => 'error', 'sent' => 0];
    try {
        if ($mid !== '' && function_exists('jeab_auto_on_message')) {
            $res = jeab_auto_on_message($mid, true);
        }
    } catch (Throwable $e) {
        unset($e);
        $res = ['action' => 'error', 'sent' => 0, 'why' => 'utskicket avbröts'];
    } finally {
        if ($hadOffice) {
            $GLOBALS['_JEAB_RESEND_OFFICE'] = $oldOffice;
        } else {
            unset($GLOBALS['_JEAB_RESEND_OFFICE']);
        }
        if ($hadKim) {
            $GLOBALS['_JEAB_RESEND_KIM'] = $oldKim;
        } else {
            unset($GLOBALS['_JEAB_RESEND_KIM']);
        }
    }
    $profile = is_array($GLOBALS['_JEAB_RESEND_PROFILE'] ?? null) ? $GLOBALS['_JEAB_RESEND_PROFILE'] : [];
    unset($GLOBALS['_JEAB_RESEND_PROFILE_ON'], $GLOBALS['_JEAB_RESEND_PROFILE']);
    $slow = '';
    $slowMs = -1;
    foreach (['pdf', 'grok', 'graph', 'helpscout'] as $stage) {
        $ms = (int) ($profile[$stage] ?? 0);
        if ($ms > $slowMs) {
            $slow = $stage;
            $slowMs = $ms;
        }
    }
    $profile['slow'] = $slow;
    $oneSent = ((string) ($res['action'] ?? '') === 'quoted' || (int) ($res['sent'] ?? 0) === 1) ? 1 : 0;
    $action = (string) ($res['action'] ?? 'error');
    $status = $action === 'error' ? 'failed' : ($oneSent === 1 ? 'sent' : 'done');
    $error = $oneSent === 1 ? '' : jeab_inkorg_resend_reason((string) ($res['why'] ?? ''));
    if ($oneSent !== 1 && $error === '') {
        $error = $action === 'error' ? 'utskicket avbröts' : $action;
    }
    jeab_inkorg_resend_finish($pdo, $jobId, $status, $action, $oneSent, $profile, $error);

    return jeab_inkorg_resend_status(['job' => $jobId]);
}

/**
 * @param array<string, mixed> $profile
 */
function jeab_inkorg_resend_finish(PDO $pdo, string $jobId, string $status, string $action, int $sent, array $profile, string $error): void
{
    $error = jeab_inkorg_resend_reason($error);
    $encoded = json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare(
        'UPDATE inkorg_resend_jobs SET status = ?, action = ?, sent = ?, finished_at = ?, profile = ?, error = ? WHERE id = ?'
    )->execute([$status, $action, $sent, date('c'), is_string($encoded) ? $encoded : '{}', $error, $jobId]);
    if (function_exists('jeab_log_app_error')) {
        $line = $sent === 1
            ? 'resend ' . $jobId . ' sent'
            : 'resend ' . $jobId . ' ' . ($error !== '' ? $error : $action);
        jeab_log_app_error('inkorg', $line, ['level' => 'info']);
    }
}

/** @return array<string, mixed> */
function jeab_inkorg_resend_job_get(string $jobId): array
{
    $pdo = jeab_inkorg_resend_pdo();
    if (!$pdo instanceof PDO || $jobId === '') {
        return [];
    }
    jeab_inkorg_resend_ensure($pdo);
    $stmt = $pdo->prepare('SELECT * FROM inkorg_resend_jobs WHERE id = ?');
    $stmt->execute([$jobId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? jeab_inkorg_resend_job_row($row) : [];
}

/** @param array<string, mixed> $in @return array<string, mixed> */
function jeab_inkorg_resend_status(array $in): array
{
    $jobId = trim((string) ($in['job'] ?? $in['job_id'] ?? ''));
    $tid = trim((string) ($in['thread_id'] ?? $in['thread'] ?? $in['id'] ?? ''));
    $found = [];
    $pdo = jeab_inkorg_resend_pdo();
    if ($pdo instanceof PDO) {
        jeab_inkorg_resend_ensure($pdo);
        if ($jobId !== '') {
            $stmt = $pdo->prepare('SELECT * FROM inkorg_resend_jobs WHERE id = ?');
            $stmt->execute([$jobId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $found = is_array($row) ? jeab_inkorg_resend_job_row($row) : [];
        }
        if ($found === [] && $tid !== '') {
            $stmt = $pdo->prepare('SELECT * FROM inkorg_resend_jobs WHERE thread_id = ? ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$tid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $found = is_array($row) ? jeab_inkorg_resend_job_row($row) : [];
        }
    }
    if ($found === []) {
        return ['ok' => false, 'error' => 'missing', 'sent' => 0, 'would_send' => false];
    }

    $status = (string) ($found['status'] ?? '');

    return jeab_inkorg_resend_safe([
        'ok' => true,
        'dry' => (int) ($found['dry'] ?? 0) === 1 ? 1 : 0,
        'sent' => (int) ($found['sent'] ?? 0),
        'would_send' => false,
        'job' => (string) ($found['id'] ?? ''),
        'thread_id' => (string) ($found['thread_id'] ?? ''),
        'status' => $status,
        'action' => (string) ($found['action'] ?? ''),
        'error' => (string) ($found['error'] ?? ''),
        'consumed' => in_array($status, ['queued', 'running'], true) ? 0 : 1,
        'sender' => (string) ($found['sender'] ?? ''),
        'template' => (string) ($found['template'] ?? ''),
        'recipient' => (string) ($found['recipient'] ?? 'no'),
        'profile' => is_array($found['profile'] ?? null) ? $found['profile'] : [],
        'chain' => is_array($found['chain'] ?? null) ? $found['chain'] : jeab_inkorg_resend_chain(),
    ]);
}

/**
 * Försök ett avslutat jobb igen. Samma id tas inte om.
 * Ett jobb som lämnat queued är förbrukat: work kräver status queued.
 * rjed31761b7048db00 skrev action och profile, alltså är det inte queued längre.
 * Nytt jobb bara när sent_log, quote_sent_diag och Outlook Skickat saknar offert
 * och inget annat jobb för tråden är queued eller running.
 *
 * @param array<string, mixed> $in
 * @return array<string, mixed>
 */
function jeab_inkorg_resend_retry(array $in): array
{
    $dry = jeab_auto_send_quote_is_dry($in);
    if (!$dry && (string) ($in['confirm'] ?? '') !== 'SAM') {
        return ['ok' => false, 'error' => 'confirm', 'dry' => 0, 'sent' => 0, 'would_send' => false];
    }
    $jobId = trim((string) ($in['job'] ?? $in['job_id'] ?? ''));
    $tid = trim((string) ($in['thread_id'] ?? $in['thread'] ?? $in['id'] ?? ''));
    $job = $jobId !== '' ? jeab_inkorg_resend_job_get($jobId) : [];
    if ($job === [] && $tid !== '') {
        $pdo = jeab_inkorg_resend_pdo();
        if ($pdo instanceof PDO) {
            jeab_inkorg_resend_ensure($pdo);
            $stmt = $pdo->prepare('SELECT * FROM inkorg_resend_jobs WHERE thread_id = ? ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$tid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $job = is_array($row) ? jeab_inkorg_resend_job_row($row) : [];
        }
    }
    if ($job === []) {
        return ['ok' => false, 'error' => 'missing', 'sent' => 0, 'would_send' => false];
    }
    $tid = (string) ($job['thread_id'] ?? $tid);
    $thread = jeab_inkorg_resend_thread($tid);
    if (!is_array($thread)) {
        return ['ok' => false, 'error' => 'missing', 'sent' => 0, 'would_send' => false, 'job' => (string) ($job['id'] ?? '')];
    }
    $previous = (string) ($job['id'] ?? '');
    $previousStatus = (string) ($job['status'] ?? '');
    $consumed = in_array($previousStatus, ['queued', 'running'], true) ? 0 : 1;
    $evidence = jeab_inkorg_resend_evidence($thread);
    $preview = jeab_inkorg_resend_preview($thread);
    if (($evidence['any'] ?? 'no') === 'yes') {
        return jeab_inkorg_resend_safe([
            'ok' => true,
            'dry' => $dry ? 1 : 0,
            'sent' => 0,
            'would_send' => false,
            'action' => 'already',
            'job' => $previous,
            'previous' => $previous,
            'status' => $previousStatus,
            'error' => 'redan skickad',
            'consumed' => $consumed,
            'evidence' => $evidence,
            'background' => 0,
        ] + $preview);
    }
    $inflight = jeab_inkorg_resend_inflight($tid);
    if ($inflight !== '') {
        return jeab_inkorg_resend_safe([
            'ok' => true,
            'dry' => $dry ? 1 : 0,
            'sent' => 0,
            'would_send' => false,
            'action' => 'queued',
            'job' => $inflight,
            'previous' => $previous,
            'status' => 'queued',
            'error' => '',
            'consumed' => 0,
            'evidence' => $evidence,
            'background' => 0,
        ] + $preview);
    }
    if ($dry) {
        return jeab_inkorg_resend_safe([
            'ok' => true,
            'dry' => 1,
            'sent' => 0,
            'would_send' => false,
            'action' => 'would',
            'job' => '',
            'previous' => $previous,
            'status' => $previousStatus,
            'error' => (string) ($job['error'] ?? ''),
            'consumed' => $consumed,
            'evidence' => $evidence,
            'background' => 0,
        ] + $preview);
    }
    $enqueued = jeab_inkorg_resend_enqueue($thread);
    $queued = (string) ($enqueued['action'] ?? '') === 'queued';

    return jeab_inkorg_resend_safe([
        'ok' => true,
        'dry' => 0,
        'sent' => 0,
        'would_send' => false,
        'action' => (string) ($enqueued['action'] ?? 'error'),
        'job' => (string) ($enqueued['job'] ?? ''),
        'previous' => $previous,
        'status' => (string) ($enqueued['action'] ?? ''),
        'error' => '',
        'consumed' => $consumed,
        'evidence' => $evidence,
        'background' => $queued ? 1 : 0,
    ] + $preview);
}

function jeab_inkorg_assignee_resolve(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '' || !function_exists('jeab_inkorg_staff')) {
        return ['id' => '', 'name' => ''];
    }
    $staff = jeab_inkorg_staff();
    if (isset($staff[$raw])) {
        return ['id' => $raw, 'name' => (string) $staff[$raw]];
    }
    $compact = preg_replace('/\s+/u', '', $raw) ?? $raw;
    if (isset($staff[$compact])) {
        return ['id' => $compact, 'name' => (string) $staff[$compact]];
    }
    foreach ($staff as $id => $name) {
        $nameCompact = preg_replace('/\s+/u', '', (string) $name) ?? (string) $name;
        if (strcasecmp((string) $name, $raw) === 0 || strcasecmp($nameCompact, $compact) === 0) {
            return ['id' => (string) $id, 'name' => (string) $name];
        }
    }

    return ['id' => '', 'name' => ''];
}

/** @return array<string, mixed> */
function jeab_inkorg_set_assignee_undo_get(string $batch): array
{
    $pdo = jeab_inkorg_resend_pdo();
    if (!$pdo instanceof PDO || $batch === '') {
        return [];
    }
    jeab_inkorg_resend_ensure($pdo);
    $stmt = $pdo->prepare('SELECT * FROM inkorg_assignee_undo WHERE batch = ?');
    $stmt->execute([$batch]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
}

/** @param array<string, mixed> $row */
function jeab_inkorg_set_assignee_undo_put(array $row): void
{
    $pdo = jeab_inkorg_resend_pdo();
    if (!$pdo instanceof PDO) {
        return;
    }
    jeab_inkorg_resend_ensure($pdo);
    $pdo->prepare(
        'INSERT INTO inkorg_assignee_undo
            (batch, thread_id, thread_assignee, reminder_id, reminder_assignee, reminder_person, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        (string) $row['batch'],
        (string) $row['thread_id'],
        (string) $row['thread_assignee'],
        (int) $row['reminder_id'],
        (string) $row['reminder_assignee'],
        (string) $row['reminder_person'],
        date('c'),
    ]);
    $ids = $pdo->query('SELECT batch FROM inkorg_assignee_undo ORDER BY created_at DESC')->fetchAll(PDO::FETCH_COLUMN);
    $drop = array_slice(is_array($ids) ? $ids : [], 20);
    if ($drop !== []) {
        $marks = implode(',', array_fill(0, count($drop), '?'));
        $pdo->prepare('DELETE FROM inkorg_assignee_undo WHERE batch IN (' . $marks . ')')->execute(array_values($drop));
    }
}

/** @param array<string, mixed> $in @return array<string, mixed> */
function jeab_inkorg_set_assignee(array $in): array
{
    $flags = ['sent' => 0, 'would_send' => false, 'deleted' => 0];
    if (!empty($in['undo'])) {
        return jeab_inkorg_set_assignee_undo($in) + $flags;
    }
    $dry = jeab_auto_send_quote_is_dry($in);
    $tid = trim((string) ($in['thread_id'] ?? $in['thread'] ?? ''));
    if ($tid === '') {
        return ['ok' => false, 'error' => 'thread_id saknas', 'dry' => $dry ? 1 : 0] + $flags;
    }
    $who = jeab_inkorg_assignee_resolve((string) ($in['assignee'] ?? ''));
    if ($who['id'] === '') {
        return ['ok' => false, 'error' => 'okänd person', 'dry' => $dry ? 1 : 0] + $flags;
    }
    $thread = function_exists('jeab_auto_thread_by_id') ? jeab_auto_thread_by_id($tid) : null;
    if (!is_array($thread)) {
        return ['ok' => false, 'error' => 'ärendet finns inte', 'dry' => $dry ? 1 : 0] + $flags;
    }
    $rid = 0;
    $rem = null;
    if (function_exists('jeab_lead_find_reminder')) {
        $rem = jeab_lead_find_reminder($tid, '');
        $rid = is_array($rem) ? (int) ($rem['id'] ?? 0) : 0;
    }
    $beforeThread = (string) ($thread['assignee'] ?? '');
    $beforeName = is_array($rem) ? (string) ($rem['assignee'] ?? '') : '';
    $beforePerson = is_array($rem) ? (string) ($rem['person'] ?? '') : '';
    if (!$dry && (string) ($in['confirm'] ?? '') !== 'SAM') {
        return ['ok' => false, 'error' => 'confirm', 'dry' => 0] + $flags;
    }
    if ($dry) {
        return jeab_inkorg_resend_safe([
            'ok' => true,
            'dry' => 1,
            'thread_id' => $tid,
            'assignee' => $who['name'],
            'assignee_id' => $who['id'],
            'reminder_id' => $rid,
            'written' => 0,
        ] + $flags);
    }
    jeab_inkorg_thread_write($tid, static function (array $t) use ($who): array {
        $t['assignee'] = $who['id'];
        $t['updated_at'] = date('c');

        return $t;
    });
    if ($rid > 0 && function_exists('jeab_store')) {
        jeab_store()->saveReminder($rid, [
            'assignee' => $who['name'],
            'assignees' => [$who['name']],
            'person' => $who['id'],
        ]);
    }
    $batch = 'sa' . bin2hex(random_bytes(6));
    jeab_inkorg_set_assignee_undo_put([
        'batch' => $batch,
        'thread_id' => $tid,
        'thread_assignee' => $beforeThread,
        'reminder_id' => $rid,
        'reminder_assignee' => $beforeName,
        'reminder_person' => $beforePerson,
    ]);

    return jeab_inkorg_resend_safe([
        'ok' => true,
        'dry' => 0,
        'thread_id' => $tid,
        'assignee' => $who['name'],
        'assignee_id' => $who['id'],
        'reminder_id' => $rid,
        'undo' => $batch,
        'written' => 1,
    ] + $flags);
}

/** @param array<string, mixed> $in @return array<string, mixed> */
function jeab_inkorg_set_assignee_undo(array $in): array
{
    $dry = jeab_auto_send_quote_is_dry($in);
    $batch = trim((string) ($in['batch'] ?? ''));
    if ($batch === '') {
        return ['ok' => false, 'error' => 'batch saknas', 'dry' => $dry ? 1 : 0];
    }
    $hit = jeab_inkorg_set_assignee_undo_get($batch);
    if ($hit === []) {
        return ['ok' => false, 'error' => 'batch saknas', 'dry' => $dry ? 1 : 0];
    }
    if ($dry) {
        return ['ok' => true, 'dry' => 1, 'batch' => $batch, 'thread_id' => (string) ($hit['thread_id'] ?? ''), 'written' => 0];
    }
    if ((string) ($in['confirm'] ?? '') !== 'SAM') {
        return ['ok' => false, 'error' => 'confirm', 'dry' => 0];
    }
    $tid = (string) ($hit['thread_id'] ?? '');
    $prev = (string) ($hit['thread_assignee'] ?? '');
    if ($tid !== '') {
        jeab_inkorg_thread_write($tid, static function (array $t) use ($prev): array {
            $t['assignee'] = $prev;
            $t['updated_at'] = date('c');

            return $t;
        });
    }
    $rid = (int) ($hit['reminder_id'] ?? 0);
    if ($rid > 0 && function_exists('jeab_store')) {
        $name = (string) ($hit['reminder_assignee'] ?? '');
        jeab_store()->saveReminder($rid, [
            'assignee' => $name,
            'assignees' => $name !== '' ? [$name] : [],
            'person' => (string) ($hit['reminder_person'] ?? ''),
        ]);
    }

    return ['ok' => true, 'dry' => 0, 'batch' => $batch, 'thread_id' => $tid, 'written' => 1, 'sent' => 0, 'would_send' => false];
}
