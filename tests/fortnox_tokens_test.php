<?php

declare(strict_types=1);

/**
 * Fortnox-token för JEAB och MEAB.
 * Isolerad fil, engångsimport från gamla sökvägar, låst refresh.
 * Fixturer only — inga riktiga token.
 */

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'worker') {
    $GLOBALS['_JEAB_DATA_DIR'] = (string) ($argv[3] ?? '');
    $GLOBALS['_JEAB_FORTNOX_APP_ROOT'] = (string) ($argv[4] ?? '');
    require dirname(__DIR__) . '/src/bootstrap.php';
    require_once dirname(__DIR__) . '/src/fortnox.php';
    $account = (string) ($argv[2] ?? 'Jarfalla');
    $state = (string) ($argv[5] ?? '');
    try {
        jeab_fortnox_oauth_refresh($account, static function (string $refresh) use ($state, $account): array {
            $fh = fopen($state, 'c+');
            if ($fh === false) {
                return ['ok' => false, 'transient' => true, 'status' => 0];
            }
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $data = json_decode(is_string($raw) && $raw !== '' ? $raw : '{}', true);
            if (!is_array($data)) {
                $data = [];
            }
            $calls = is_array($data['calls'] ?? null) ? $data['calls'] : [];
            $calls[] = $account;
            $data['calls'] = $calls;
            $current = is_array($data['current'] ?? null) ? $data['current'] : [];
            $expected = (string) ($current[$account] ?? '');
            $stale = $refresh !== $expected;
            if ($stale) {
                $seen = is_array($data['stale'] ?? null) ? $data['stale'] : [];
                $seen[] = $account;
                $data['stale'] = $seen;
            } else {
                $current[$account] = $refresh . '-next';
                $data['current'] = $current;
            }
            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, json_encode($data));
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
            usleep(80000);
            if ($stale) {
                return ['ok' => false, 'status' => 400, 'error' => 'invalid_grant'];
            }
            return [
                'ok' => true,
                'status' => 200,
                'access_token' => 'fixture-access-' . $account,
                'refresh_token' => $refresh . '-next',
                'expires_in' => 3600,
            ];
        });
        echo "OK\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/jeab-ft-root-' . getmypid();
$data = sys_get_temp_dir() . '/jeab-ft-data-' . getmypid();
mkdir($root, 0775, true);
mkdir($data, 0775, true);
$GLOBALS['_JEAB_FORTNOX_APP_ROOT'] = $root;
$GLOBALS['_JEAB_DATA_DIR'] = $data;

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/fortnox.php';

function ft_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function ft_rm(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $path = $item->getPathname();
        if ($item->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function ft_store(): array
{
    return jeab_fortnox_tokens_normalize_store(
        jeab_fortnox_tokens_read_file(jeab_fortnox_tokens_canonical_path())
    );
}

function ft_refresh_is(string $account, string $expected, string $message): void
{
    $got = jeab_fortnox_token_refresh_value(ft_store()[$account] ?? null);
    if ($got === $expected) {
        ft_assert(true, $message);
        return;
    }
    $detail = str_starts_with($got, 'fixture-') || $got === '' ? ' fick ' . ($got === '' ? 'tomt' : $got) : '';
    ft_assert(false, $message . $detail);
}

function ft_seed_canonical(array $store): void
{
    $ok = jeab_fortnox_tokens_write_file(jeab_fortnox_tokens_canonical_path(), $store);
    ft_assert($ok, 'fixtur kunde skrivas');
}

function ft_workers(array $accounts, string $state): void
{
    $running = [];
    foreach ($accounts as $account) {
        $proc = proc_open(
            [PHP_BINARY, __FILE__, 'worker', $account, $GLOBALS['_JEAB_DATA_DIR'], $GLOBALS['_JEAB_FORTNOX_APP_ROOT'], $state],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        ft_assert(is_resource($proc), 'worker startade');
        $running[] = [$proc, $pipes];
    }
    foreach ($running as [$proc, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $errText = trim((string) $err);
        ft_assert($code === 0, 'worker slutade ' . $code . ($errText !== '' ? ' ' . $errText : ''));
        ft_assert(is_string($out) && str_contains($out, 'OK'), 'worker svarade OK');
    }
}

$savedData = $GLOBALS['_JEAB_DATA_DIR'];
unset($GLOBALS['_JEAB_DATA_DIR']);
$defaultDir = jeab_data_dir();
$GLOBALS['_JEAB_DATA_DIR'] = $savedData;
ft_assert($defaultDir === JEAB_ROOT . '/data/.private', 'standardkatalogen är data/.private');
ft_assert(
    $defaultDir . '/fortnox/tokens.json' === JEAB_ROOT . '/data/.private/fortnox/tokens.json',
    'kanonisk tokenfil är data/.private/fortnox/tokens.json'
);
ft_assert(
    jeab_fortnox_tokens_canonical_path() === $data . '/fortnox/tokens.json',
    'testets tokenfil ligger i den isolerade katalogen'
);
ft_assert(!str_contains(jeab_fortnox_tokens_canonical_path(), '/rot/'), 'kanonisk sökväg ligger inte under rot/');

$gitignore = (string) file_get_contents(dirname(__DIR__) . '/.gitignore');
ft_assert(str_contains($gitignore, '/data/.private/fortnox/'), 'gitignore täcker tokenkatalogen');
ft_assert(str_contains($gitignore, '/rot/fortnox_tokens.json'), 'gitignore täcker gamla rot-filen');

mkdir($data . '/rot', 0775, true);
mkdir($root . '/rot', 0775, true);
file_put_contents($data . '/rot/fortnox_tokens.json', "{}\n");
file_put_contents($root . '/rot/fortnox_tokens.json', json_encode([
    'JEAB' => 'fixture-jarfalla-refresh',
    'MEAB' => ['refresh_token' => 'fixture-malmo-refresh', 'access_token' => 'fixture-access-old'],
], JSON_PRETTY_PRINT) . "\n");

$path = jeab_fortnox_tokens_path();
ft_assert($path === $data . '/fortnox/tokens.json', 'läsning pekar på den isolerade filen');
ft_assert(is_file($path), 'importen skapade tokenfilen');
ft_refresh_is('Jarfalla', 'fixture-jarfalla-refresh', 'tom privat kopia döljer inte JEAB');
ft_refresh_is('Malmo', 'fixture-malmo-refresh', 'tom privat kopia döljer inte MEAB');
ft_assert(
    (ft_store()['Malmo']['access_token'] ?? '') === 'fixture-access-old',
    'befintlig access-token följde med importen'
);

file_put_contents($root . '/rot/fortnox_tokens.json', json_encode([
    'Jarfalla' => 'fixture-stale-jarfalla',
    'Malmo' => 'fixture-stale-malmo',
], JSON_PRETTY_PRINT) . "\n");
touch($root . '/rot/fortnox_tokens.json', time() + 50);
jeab_fortnox_tokens_migrate();
ft_refresh_is('Jarfalla', 'fixture-jarfalla-refresh', 'nyare gammal fil skriver inte över JEAB');
ft_refresh_is('Malmo', 'fixture-malmo-refresh', 'nyare gammal fil skriver inte över MEAB');

$privateRot = $data . '/rot/fortnox_tokens.json';
file_put_contents($privateRot, json_encode(['Jarfalla' => 'fixture-kept-jarfalla', 'Malmo' => 'fixture-kept-malmo']) . "\n");
$publicRot = $root . '/rot/clobber.json';
file_put_contents($publicRot, json_encode(['Jarfalla' => 'fixture-clobber-jarfalla']) . "\n");
touch($publicRot, time() + 80);
jeab_shield_move($publicRot, $privateRot);
$kept = json_decode((string) file_get_contents($privateRot), true);
ft_assert(
    is_array($kept) && ($kept['Jarfalla'] ?? '') === 'fixture-kept-jarfalla',
    'nyare källa ersätter inte en tokenfil som redan har refresh'
);

ft_seed_canonical([
    'Jarfalla' => ['refresh_token' => 'fixture-jarfalla-refresh'],
    'Malmo' => ['refresh_token' => 'fixture-malmo-refresh'],
]);
$calls = 0;
$firstWasStored = true;
$exchange = static function (string $refresh) use (&$calls, &$firstWasStored): array {
    $calls++;
    if ($refresh !== 'fixture-jarfalla-refresh') {
        $firstWasStored = false;
    }
    return [
        'ok' => true,
        'status' => 200,
        'access_token' => 'fixture-access-jarfalla',
        'refresh_token' => 'fixture-jarfalla-refresh-next',
        'expires_in' => 3600,
    ];
};
$first = jeab_fortnox_oauth_refresh('Jarfalla', $exchange);
$second = jeab_fortnox_oauth_refresh('Jarfalla', $exchange);
ft_assert($calls === 1, 'andra anropet roterar inte refresh igen');
ft_assert($firstWasStored, 'refresh som skickades var den sparade');
ft_assert($first === 'fixture-access-jarfalla' && $first === $second, 'samma access-token återanvänds');
ft_refresh_is('Jarfalla', 'fixture-jarfalla-refresh-next', 'nytt refresh sparades');
ft_refresh_is('Malmo', 'fixture-malmo-refresh', 'Malmö ligger kvar när Järfälla refreshas');
ft_assert((ft_store()['Jarfalla']['access_token'] ?? '') === 'fixture-access-jarfalla', 'access-token sparas i samma fil');

$jarfalla = ft_store()['Jarfalla'];
$jarfalla['expires_at'] = time() - 10;
$store = ft_store();
$store['Jarfalla'] = $jarfalla;
ft_seed_canonical($store);
$rejectCalls = 0;
$reject = static function (string $refresh) use (&$rejectCalls): array {
    $rejectCalls++;
    return ['ok' => false, 'status' => 400, 'error' => 'invalid_grant'];
};
$rejected = false;
try {
    jeab_fortnox_oauth_refresh('Jarfalla', $reject);
} catch (Exception $e) {
    $rejected = str_contains($e->getMessage(), 'kopplas om');
}
ft_assert($rejected, 'avvisat refresh ger omkoppling');
ft_assert($rejectCalls === 1, 'avvisat refresh skickades en gång');
ft_refresh_is('Jarfalla', 'fixture-jarfalla-refresh-next', 'avvisat refresh raderas inte');
ft_refresh_is('Malmo', 'fixture-malmo-refresh', 'avvisat refresh rör inte Malmö');
try {
    jeab_fortnox_oauth_refresh('Jarfalla', $reject);
    ft_assert(false, 'samma refresh ska inte försöka igen');
} catch (Exception $e) {
    ft_assert(str_contains($e->getMessage(), 'kopplas om'), 'andra försöket stoppas utan nytt anrop');
}
ft_assert($rejectCalls === 1, 'avvisat refresh skickas inte igen');

$relink = ft_store();
$relink['Jarfalla']['refresh_token'] = 'fixture-jarfalla-refresh-relink';
ft_seed_canonical($relink);
$relinkCalls = 0;
$relinked = jeab_fortnox_oauth_refresh('Jarfalla', static function (string $refresh) use (&$relinkCalls): array {
    $relinkCalls++;
    if ($refresh !== 'fixture-jarfalla-refresh-relink') {
        return ['ok' => false, 'status' => 400, 'error' => 'invalid_grant'];
    }
    return [
        'ok' => true,
        'status' => 200,
        'access_token' => 'fixture-access-relink',
        'refresh_token' => 'fixture-jarfalla-refresh-relinked',
        'expires_in' => 3600,
    ];
});
ft_assert($relinkCalls === 1 && $relinked === 'fixture-access-relink', 'nytt refresh efter omkoppling används');
ft_refresh_is('Jarfalla', 'fixture-jarfalla-refresh-relinked', 'omkopplat refresh sparades');

$again = ft_store();
$again['Jarfalla']['expires_at'] = time() - 5;
unset($again['Jarfalla']['rejected_refresh_sha256'], $again['Jarfalla']['rejected_at']);
ft_seed_canonical($again);
$transientCalls = 0;
$transient = static function () use (&$transientCalls): array {
    $transientCalls++;
    return ['ok' => false, 'status' => 503, 'transient' => true];
};
$sawContact = false;
try {
    jeab_fortnox_oauth_refresh('Jarfalla', $transient);
} catch (Exception $e) {
    $sawContact = str_contains($e->getMessage(), 'Ingen kontakt');
}
ft_assert($sawContact, 'tillfälligt fel är inte en omkoppling');
try {
    jeab_fortnox_oauth_refresh('Jarfalla', $transient);
} catch (Exception) {
}
ft_assert($transientCalls === 2, 'tillfälligt fel får försöka igen');
ft_refresh_is('Jarfalla', 'fixture-jarfalla-refresh-relinked', 'tillfälligt fel lämnar refresh orört');
ft_assert(empty(ft_store()['Jarfalla']['rejected_refresh_sha256']), 'tillfälligt fel markerar inte refresh som dött');

ft_seed_canonical([
    'Jarfalla' => ['refresh_token' => 'fixture-jarfalla-refresh'],
    'Malmo' => ['refresh_token' => 'fixture-malmo-refresh'],
]);
$classCalls = 0;
$GLOBALS['_JEAB_FORTNOX_OAUTH'] = static function (string $refresh, string $account) use (&$classCalls): array {
    $classCalls++;
    if ($account !== 'Jarfalla' || $refresh !== 'fixture-jarfalla-refresh') {
        return ['ok' => false, 'status' => 400, 'error' => 'invalid_grant'];
    }
    return [
        'ok' => true,
        'status' => 200,
        'access_token' => 'fixture-access-class',
        'refresh_token' => 'fixture-jarfalla-refresh-class',
        'expires_in' => 3600,
    ];
};
$api = new FortnoxAPI('Jarfalla');
$method = new ReflectionMethod(FortnoxAPI::class, 'getAccessToken');
$method->setAccessible(true);
$fromApi = $method->invoke($api);
$apiAgain = new FortnoxAPI('Jarfalla');
$fromAgain = $method->invoke($apiAgain);
unset($GLOBALS['_JEAB_FORTNOX_OAUTH']);
$fileProp = new ReflectionProperty(FortnoxAPI::class, 'tokensFile');
$fileProp->setAccessible(true);
ft_assert($classCalls === 1, 'FortnoxAPI hämtar access en gång');
ft_assert($fromApi === 'fixture-access-class' && $fromApi === $fromAgain, 'FortnoxAPI återanvänder sparad access');
ft_assert($fileProp->getValue($api) === jeab_fortnox_tokens_canonical_path(), 'FortnoxAPI pekar på den isolerade filen');

$state = $data . '/oauth-state.json';
file_put_contents($state, json_encode([
    'current' => [
        'Jarfalla' => 'fixture-jarfalla-refresh',
        'Malmo' => 'fixture-malmo-refresh',
    ],
    'calls' => [],
    'stale' => [],
]) . "\n");
ft_seed_canonical([
    'Jarfalla' => ['refresh_token' => 'fixture-jarfalla-refresh'],
    'Malmo' => ['refresh_token' => 'fixture-malmo-refresh'],
]);
ft_workers(['Jarfalla', 'Jarfalla'], $state);
$same = json_decode((string) file_get_contents($state), true);
ft_assert(is_array($same) && ($same['calls'] ?? null) === ['Jarfalla'], 'två samtidiga anrop refreshar Järfälla en gång');
ft_assert(($same['stale'] ?? []) === [], 'samtidiga anrop skickar inte ett redan använt refresh');
ft_refresh_is('Jarfalla', 'fixture-jarfalla-refresh-next', 'samtidig refresh sparade det nya värdet');
ft_refresh_is('Malmo', 'fixture-malmo-refresh', 'samtidig Järfälla-refresh lämnar Malmö');

file_put_contents($state, json_encode([
    'current' => [
        'Jarfalla' => 'fixture-jarfalla-refresh-next',
        'Malmo' => 'fixture-malmo-refresh',
    ],
    'calls' => [],
    'stale' => [],
]) . "\n");
$expired = ft_store();
$expired['Jarfalla']['expires_at'] = time() - 5;
ft_seed_canonical($expired);
ft_workers(['Jarfalla', 'Malmo'], $state);
$both = json_decode((string) file_get_contents($state), true);
$bothCalls = is_array($both['calls'] ?? null) ? $both['calls'] : [];
sort($bothCalls);
ft_assert($bothCalls === ['Jarfalla', 'Malmo'], 'båda bolagen refreshas var för sig');
ft_assert(($both['stale'] ?? []) === [], 'bolagen återanvänder inte varandras refresh');
ft_refresh_is('Jarfalla', 'fixture-jarfalla-refresh-next-next', 'Järfälla-refresh från den samtidiga körningen finns kvar');
ft_refresh_is('Malmo', 'fixture-malmo-refresh-next', 'Malmö-refresh från den samtidiga körningen finns kvar');

ft_rm($root);
ft_rm($data);
echo "PASS fortnox token store\n";
