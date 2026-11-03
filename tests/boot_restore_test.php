<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/shield.php';

function boot_restore_fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$dir = sys_get_temp_dir() . '/jeab-boot-restore-' . bin2hex(random_bytes(4));
if (!mkdir($dir) && !is_dir($dir)) {
    boot_restore_fail('kunde inte skapa temp');
}

$live = $dir . '/ordrar.php';
$bak = $live . '.bak';
$tmp = $live . '.deploy-abc.tmp';
$body = "<?php\nfunction jeab_boot_restore_marker(): string { return 'ok'; }\n";

file_put_contents($bak, $body);
jeab_restore_boot_php($live);
if (!is_file($live) || is_file($bak) || file_get_contents($live) !== $body) {
    boot_restore_fail('bak lades inte tillbaka till live');
}

$kept = file_get_contents($live);
file_put_contents($bak, str_repeat('x', 40));
file_put_contents($tmp, str_repeat('y', 40));
jeab_restore_boot_php($live);
if (file_get_contents($live) !== $kept || !is_file($bak) || !is_file($tmp)) {
    boot_restore_fail('befintlig live-fil skulle ha lämnats orörd');
}

unlink($live);
unlink($bak);
file_put_contents($tmp, str_repeat('z', 40));
touch($tmp, time() - 10);
$newer = $live . '.deploy-newer.tmp';
file_put_contents($newer, str_repeat('n', 40));
touch($newer, time());
jeab_restore_boot_php($live);
if (!is_file($live) || file_get_contents($live) !== str_repeat('n', 40) || is_file($newer)) {
    boot_restore_fail('nyaste deploy-tmp skulle ha blivit live');
}
if (!is_file($tmp)) {
    boot_restore_fail('äldre deploy-tmp skulle ha lämnats kvar');
}

unlink($live);
unlink($tmp);
file_put_contents($bak, 'kort');
jeab_restore_boot_php($live);
if (is_file($live)) {
    boot_restore_fail('för kort .bak skulle inte ha blivit live');
}

echo "PASS boot restore\n";
