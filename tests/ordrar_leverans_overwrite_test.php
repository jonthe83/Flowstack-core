<?php

declare(strict_types=1);

/** Butikens faktiska leveransdatum skriver alltid över planerat/önskat (Gnejsvägen 15A). */

$tmp = sys_get_temp_dir() . '/jeab-levov-' . getmypid();
@mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
require dirname(__DIR__) . '/src/bootstrap.php';

function lo_assert(bool $ok, string $m): void
{
    if (!$ok) { fwrite(STDERR, "FAIL {$m}\n"); exit(1); }
    echo "OK  {$m}\n";
}

$row = ['leverans' => '2026-10-16', 'aprilice_order' => '2026105281'];
$out = jeab_ordrar_apply_placed_shop($row, ['aprilice' => '2026105281', 'leverans' => '2026-10-19']);
lo_assert(($out['leverans'] ?? '') === '2026-10-19', 'leverans skrivs över');
lo_assert(($out['faktisk_leverans'] ?? '') === '2026-10-19', 'faktisk_leverans skrivs över');
lo_assert(($out['leverans_planerad'] ?? '') === '2026-10-16', 'tidigare datum sparas som planerat');
lo_assert(($out['leverans_source'] ?? '') === 'shop', 'källa = butik');
$same = jeab_ordrar_apply_placed_shop($out, ['leverans' => '2026-10-19']);
lo_assert($same === $out, 'samma datum igen ändrar inget');
$keep = jeab_ordrar_apply_placed_shop($out, ['aprilice' => '2026105281']);
lo_assert(($keep['leverans'] ?? '') === '2026-10-19', 'utan leverans behålls datumet');
echo "ordrar leverans overwrite: ok\n";
