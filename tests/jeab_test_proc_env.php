<?php

declare(strict_types=1);

/**
 * Kompletterar en utbytt miljö till en barnprocess.
 * PHP_INI_SCAN_DIR följer med bara när den redan är satt, så auto_prepend
 * gäller även php -S som inte ärver den omgivande miljön.
 *
 * @param array<string, string> $env
 * @return array<string, string>
 */
if (!function_exists('jeab_test_proc_env')) {
    function jeab_test_proc_env(array $env): array
    {
        $scan = getenv('PHP_INI_SCAN_DIR');
        if (is_string($scan) && $scan !== '') {
            $env['PHP_INI_SCAN_DIR'] = $scan;
        }

        return $env;
    }
}

/**
 * php -S kör en router med zend_execute_scripts och hoppar över auto_prepend.
 * Routern får därför själv ta in injektionen innan appen laddas.
 */
if (!function_exists('jeab_test_sqlite_boot')) {
    function jeab_test_sqlite_boot(): string
    {
        return 'require ' . var_export(__DIR__ . '/jeab_test_sqlite.php', true) . ";\n";
    }
}

/**
 * Lägger in hjälparen först i en redan skriven router-fil för php -S.
 */
if (!function_exists('jeab_test_router_boot')) {
    function jeab_test_router_boot(string $router): void
    {
        jeab_test_router_cleanup($router);
        $code = (string) @file_get_contents($router);
        $line = jeab_test_sqlite_boot();
        if ($code === '' || str_contains($code, $line) || !str_starts_with($code, '<?php')) {
            return;
        }
        file_put_contents($router, "<?php\n" . $line . substr($code, 5));
    }
}

/**
 * Stänger php -S-servern för routern när testet slutar, även vid fel eller exit,
 * och raderar en temp-katalog av formen jeab-*-http-*.
 */
if (!function_exists('jeab_test_router_cleanup')) {
    function jeab_test_router_cleanup(string $router): void
    {
        static $done = [];
        $router = (string) realpath($router) ?: $router;
        if (isset($done[$router])) {
            return;
        }
        $done[$router] = true;
        register_shutdown_function(static function () use ($router): void {
            if ($router === '' || !str_contains($router, '/')) {
                return;
            }
            @exec('pkill -f -- ' . escapeshellarg(' ' . preg_quote($router, '/') . '$') . ' 2>/dev/null');
            $tmp = rtrim(str_replace('\\', '/', (string) realpath(sys_get_temp_dir())), '/');
            $dir = dirname($router);
            while ($dir !== '' && $dir !== '/' && dirname($dir) !== $tmp) {
                $dir = dirname($dir);
            }
            if (dirname($dir) === $tmp && preg_match('/^jeab-[a-z0-9-]+-http-[a-f0-9]+$/', basename($dir)) === 1) {
                @exec('rm -rf -- ' . escapeshellarg($dir));
            }
        });
    }
}
