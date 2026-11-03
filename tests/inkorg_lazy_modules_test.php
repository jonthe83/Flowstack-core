<?php

declare(strict_types=1);

/**
 * Inkorg i HTTP (lat bootstrap) laddar samma moduler som listan, räknarna och
 * Egna behöver. Saknades inkorg_klassning/Help Scout-importen föll inkorg_fast
 * tyst tillbaka till scope=allas, Inkorg-mappen och bara live-raderna i Historik
 * (Öppna 15 mot probens 23, Historik 9 mot ~20 000, Egna = Alla).
 */

$root = dirname(__DIR__);
$php = getenv('PHP_BINARY') ?: PHP_BINARY;
$tmp = sys_get_temp_dir() . '/jeab-ix-lazy-' . getmypid();
@mkdir($tmp . '/privat', 0775, true);

$need = [
    'jeab_inkorg_list_scope',
    'jeab_egna_me_id',
    'jeab_inkorg_fast_scope_menu',
    'jeab_inkorg_folder_by_id',
    'jeab_inkorg_folder_views',
    'jeab_inkorg_queue_counts',
    'jeab_hs_import_historik_count_noddl',
    'jeab_hs_import_historik_keys',
    'jeab_hs_import_rows_by_ids',
    'jeab_inkorg_historik_total_noddl',
];
$fail = 0;
foreach (['', 'list', 'all', 'thread'] as $part) {
    $code = '$GLOBALS["_JEAB_BOOT_LAZY"]=true;'
        . '$GLOBALS["_JEAB_DATA_DIR"]=' . var_export($tmp, true) . ';'
        . '$GLOBALS["_JEAB_PUBLIC_DATA_DIR"]=' . var_export($tmp, true) . ';'
        . '$GLOBALS["_JEAB_PRIVAT_DIR"]=' . var_export($tmp . '/privat', true) . ';'
        . '$_GET=["p"=>"inkorg"' . ($part !== '' ? ',"ix_part"=>' . var_export($part, true) : '') . '];'
        . '$_SERVER["SCRIPT_FILENAME"]=' . var_export($root . '/index.php', true) . ';'
        . 'require ' . var_export($root . '/src/bootstrap.php', true) . ';'
        . 'jeab_bootstrap_load_page_modules();'
        . 'foreach (' . var_export($need, true) . ' as $f) { if (!function_exists($f)) { echo "MISSING ", $f, "\n"; } }'
        . 'echo "DONE\n";';
    $proc = proc_open([$php, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $label = $part === '' ? 'sida' : $part;
    if (!str_contains($out, 'DONE') || str_contains($out, 'MISSING')) {
        fwrite(STDERR, "FAIL ix_part={$label}: " . trim($out) . "\n");
        $fail++;
        continue;
    }
    echo "OK  ix_part={$label} har Egna, mappar, köer och Historik-arkivet\n";
}
exit($fail > 0 ? 1 : 0);
