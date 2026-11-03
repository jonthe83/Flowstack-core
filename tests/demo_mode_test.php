<?php

declare(strict_types=1);

/**
 * Demo: inget utgående anrop. Av: samma grind som förut (ingen demo-nyckel).
 * php tests/demo_mode_test.php
 */

$root = dirname(__DIR__);
$php = PHP_BINARY;
$phase = $argv[1] ?? 'all';

function demo_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function demo_ok(bool $ok, string $message): void
{
    if (!$ok) {
        demo_fail($message);
    }
    echo "OK  {$message}\n";
}

function demo_tmp_config(bool $demoFlag): string
{
    $tmp = sys_get_temp_dir() . '/jeab-demo-mode-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0775, true);
    $config = [
        'timezone' => 'Europe/Stockholm',
        'incoming_webhook_token' => '',
        'outgoing_webhook_url' => '',
        'people' => [
            ['id' => 'DemoSalj', 'email' => 'demo.saljare@example.test', 'name' => 'Demo Säljare', 'company' => 'jarfalla', 'job' => 'batteri'],
        ],
        'db' => [
            'driver' => 'sqlite',
            'sqlite_path' => $tmp . '/app.sqlite',
            'json_path' => $tmp . '/store.json',
        ],
    ];
    if ($demoFlag) {
        $config['demo'] = true;
    }
    file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");

    return $tmp;
}

function demo_grep(string $root): void
{
    $dirs = [$root . '/src', $root . '/api', $root . '/rot', $root . '/gi', $root . '/bokning', $root . '/fullmakt'];
    $skip = '/vendor/';
    $needles = [
        'curl_init(',
        "file_get_contents('http",
        'file_get_contents("http',
        'file_get_contents($url',
        "file_get_contents(\$cfg[",
    ];
    $bad = [];
    $iterator = function (string $dir) use (&$iterator, $skip, $needles, &$bad): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_dir($path)) {
                if (str_contains($path, $skip)) {
                    continue;
                }
                $iterator($path);
                continue;
            }
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $i => $line) {
                $hit = false;
                foreach ($needles as $needle) {
                    if (str_contains($line, $needle)) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
                $from = max(0, $i - 40);
                $window = implode("\n", array_slice($lines, $from, $i - $from));
                if (!str_contains($window, 'jeab_demo_outbound(')) {
                    $bad[] = $path . ':' . ($i + 1);
                }
            }
        }
    };
    foreach ($dirs as $dir) {
        $iterator($dir);
    }
    demo_ok($bad === [], 'alla utgående anrop har demo-spärr' . ($bad === [] ? '' : ' ' . implode(' ', $bad)));
}

if ($phase === 'all') {
    demo_grep($root);
    $off = demo_tmp_config(false);
    $env = demo_tmp_config(false);
    $flag = demo_tmp_config(true);
    $run = static function (string $tmp, array $envVars) use ($php): int {
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__FILE__) . ' child ' . escapeshellarg($tmp);
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $spec, $pipes, null, $envVars);
        if (!is_resource($proc)) {
            demo_fail('proc');
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        echo $out;
        if ($code !== 0) {
            fwrite(STDERR, $err);
        }

        return $code;
    };
    $base = ['JEAB_DEMO' => '0'];
    demo_ok($run($off, $base) === 0, 'av-läge');
    demo_ok($run($env, ['JEAB_DEMO' => '1']) === 0, 'env-läge');
    demo_ok($run($flag, $base) === 0, 'config-läge');

    $tick = proc_open(
        escapeshellarg($php) . ' ' . escapeshellarg($root . '/tick.php'),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $tp,
        $root,
        ['JEAB_DEMO' => '1']
    );
    stream_get_contents($tp[1]);
    $terr = stream_get_contents($tp[2]);
    fclose($tp[1]);
    fclose($tp[2]);
    demo_ok(is_resource($tick) && proc_close($tick) === 1 && str_contains($terr, 'cron av'), 'tick 403/exit i demo');

    $hookCmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/hook.php');
    $hp = proc_open($hookCmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $hpp, $root, ['JEAB_DEMO' => '1']);
    $hout = stream_get_contents($hpp[1]);
    fclose($hpp[1]);
    fclose($hpp[2]);
    proc_close($hp);
    demo_ok(str_contains((string) $hout, 'Fel token'), 'hook 401 i demo');

    $gp = proc_open(
        escapeshellarg($php) . ' ' . escapeshellarg($root . '/graph-hook.php'),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $gpp,
        $root,
        ['JEAB_DEMO' => '1']
    );
    $gout = stream_get_contents($gpp[1]);
    $gerr = stream_get_contents($gpp[2]);
    fclose($gpp[1]);
    fclose($gpp[2]);
    $gcode = is_resource($gp) ? proc_close($gp) : 1;
    demo_ok($gcode === 1 && str_contains((string) $gerr, 'cron av') && !str_contains((string) $gout, 'validationToken'), 'graph-hook körs inte i demo');

    $ht = (string) file_get_contents($root . '/.htaccess.example');
    demo_ok(str_contains($ht, 'AuthUserFile /ABSOLUT/SOKVAG/TILL/.htpasswd') && str_contains($ht, 'noindex, nofollow'), 'htaccess-mall');
    demo_ok(!preg_match('/AuthUserFile\s+\/(?!ABSOLUT)/', $ht), 'inget riktigt AuthUserFile');
    $deny = (string) file_get_contents($root . '/data/.htaccess');
    demo_ok(str_contains($deny, 'Require all denied') && str_contains($deny, 'Deny from all'), 'data spärrad');
    $robots = (string) file_get_contents($root . '/robots.txt');
    demo_ok(str_contains($robots, 'Disallow: /'), 'robots.txt');
    $seed = (string) file_get_contents($root . '/tools/demo_seed.php');
    demo_ok(str_contains($seed, 'jeab_offert_neo_kundpris') && !str_contains($seed, '12345'), 'seed använder riktigt kundpris');
    demo_ok(!is_dir($root . '/data/.private/secrets'), 'ingen secrets-mapp under data/.private');
    exit(0);
}

$tmp = $argv[2] ?? '';
if ($tmp === '' || !is_file($tmp . '/config.php')) {
    demo_fail('tmp');
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
require $root . '/src/bootstrap.php';

$on = jeab_demo_mode();
if ($phase === 'child') {
    // Föräldern sätter JEAB_DEMO. 0 betyder av.
}

if (!$on) {
    demo_ok(jeab_demo_outbound('probe', 'off') === false, 'spärren är tyst när demon är av');
    $before = is_file($tmp . '/demo-send.log') ? (string) file_get_contents($tmp . '/demo-send.log') : '';
    demo_ok(!str_contains($before, 'probe'), 'ingen demo-logg när av');
    $gate = jeab_send_guard_gate('sms', '0700000099', 'hej', ['template' => 'sms', 'handler' => 'sms']);
    demo_ok(empty($gate['demo']) && empty($gate['result']['demo']), 'grinden märker inte demo när av');
    demo_ok(jeab_mail_queue_paused() === false, 'kön är inte tvångspausad när av');
    $attempt = jeab_auth_attempt('vilken-kod-som-helst');
    demo_ok(empty($attempt['ok']), 'fel kod släpps inte in när av');
    demo_ok(jeab_is_admin() === false, 'av-läge är inte admin');
    demo_ok(jeab_demo_feature_off('kalkyl') === false, 'kalkyl är inte avstängd när demon är av');
    exit(0);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
jeab_demo_bind_session();
demo_ok(($_SESSION['jeab_code_person'] ?? '') === 'DemoSalj', 'sessionen får demopersonen');
demo_ok(jeab_is_admin() === true, 'demopersonen är admin');
$offices = jeab_actor_companies();
demo_ok(is_array($offices) && in_array('jarfalla', $offices, true) && in_array('malmo', $offices, true), 'admin ser båda kontoren');
demo_ok(str_contains(jeab_demo_banner_html(), 'Flowstack-demo - demo.flowstack.se'), 'banner');
demo_ok(jeab_demo_feature_off('kalkyl') && jeab_demo_feature_off('omtago'), 'kalkyl och omtago av');
require_once $root . '/src/layout.php';
demo_ok(!str_contains(jeab_module_nav('reminders'), 'Kalkyl'), 'kalkyl saknas i menyn');
demo_ok(jeab_demo_title('Påminnelser · JEAB') === 'Påminnelser · Flowstack-demo - demo.flowstack.se', 'sidtitel');
demo_ok(jeab_mail_queue_enabled() === false, 'kön är av');
demo_ok(jeab_mail_queue_paused() === true, 'kön är pausad');
demo_ok(jeab_inkorg_llm_config() === null, 'xAI-config är null');
$gate = jeab_send_guard_gate('email', 'nora.exempel@example.test', 'hej', ['template' => 'email', 'handler' => 'outbound']);
demo_ok(($gate['action'] ?? '') === 'stop' && !empty($gate['result']['ok']) && !empty($gate['result']['demo']), 'grind stoppar och visar skickat');
$http = jeab_graph_http('GET', 'https://graph.microsoft.com/v1.0/me/messages', null, []);
demo_ok(($http['demo'] ?? false) === true && ($http['code'] ?? 0) === 200, 'Graph fejk utan anrop');
jeab_post_json('https://example.test/hook', '{}');
$sms = jeab_sms_send('0700000001', 'hej från demo', 'jarfalla');
demo_ok(!empty($sms['ok']) && !empty($sms['demo']), 'SMS visas som skickat');
$log = is_file(jeab_data_dir() . '/demo-send.log') ? (string) file_get_contents(jeab_data_dir() . '/demo-send.log') : '';
demo_ok(str_contains($log, 'gate:email') && str_contains($log, 'graph') && str_contains($log, 'webhook') && str_contains($log, 'sms'), 'blockerade anrop loggas');
demo_ok(!str_contains($log, 'nora.exempel@example.test'), 'loggen har inte adressen');
$attempt = jeab_auth_attempt('vad-som-helst');
demo_ok(!empty($attempt['ok']) && ($attempt['hash'] ?? '') === jeab_demo_auth_hash(), 'vilken kod som helst');
exit(0);
