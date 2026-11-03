<?php

declare(strict_types=1);

/** Transporter utanför spärren får inte anropa Graph, Help Scout, SureSMS eller mail(). */

require dirname(__DIR__) . '/src/bootstrap.php';

function tr_fail(string $message): void
{
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function tr_ok(bool $ok, string $message): void
{
    if (!$ok) {
        tr_fail($message);
    }
    echo "OK  {$message}\n";
}

$root = dirname(__DIR__);
$hits = jeab_send_guard_scan_transports($root);
tr_ok($hits === [], 'inga otillåtna utskick: ' . implode(' ', $hits));

$tmp = sys_get_temp_dir() . '/jeab-send-guard-scan-' . bin2hex(random_bytes(4));
mkdir($tmp . '/src', 0775, true);
file_put_contents($tmp . '/src/leak.php', "<?php\n\$url = 'https://api.suresms.com/script/v3/sendsms.aspx';\n");
file_put_contents($tmp . '/src/note.php', "<?php\n// Ingen mail() här.\n");
$found = jeab_send_guard_scan_transports($tmp);
tr_ok($found === ['src/leak.php:sms'], 'skannern ser SureSMS utanför spärren');
tr_ok(!in_array('src/note.php:mail', $found, true), 'kommentaren mail() räknas inte');

echo "OK  transportspärr\n";
