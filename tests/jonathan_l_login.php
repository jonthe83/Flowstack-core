<?php

declare(strict_types=1);

/**
 * Testinloggning som Sam L med sparat kodhuvud.
 * Enbart demo@example.test är osäker och inte admin.
 */
function jeab_test_login_sam_l(): void
{
    $hash = 'sam-hash-fixture';
    $env = getenv('JEAB_PASSWORDS_FILE');
    if (!is_string($env) || $env === '' || !is_file($env)) {
        $dir = (string) ($GLOBALS['_JEAB_DATA_DIR'] ?? sys_get_temp_dir());
        $env = $dir . '/passwords.json';
        if (!is_file($env)) {
            file_put_contents($env, json_encode([
                'secret' => 'test-secret-value',
                'hashes' => [['hash' => $hash, 'email' => 'demo@example.test']],
            ], JSON_UNESCAPED_UNICODE));
        }
        putenv('JEAB_PASSWORDS_FILE=' . $env);
    }
    jeab_auth_data(true);
    jeab_code_people_save(jeab_auth_hash_fingerprint($hash), 'SamL', 'prefix', 'SamL');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_COOKIE['jeab_auth'] = (string) jeab_auth_issue_value('demo@example.test', $hash);
    $_SESSION['jeab_email'] = 'demo@example.test';
}
