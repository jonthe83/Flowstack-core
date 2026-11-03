<?php
declare(strict_types=1);
// Vunnen order utan Fortnox-order: felet sparas alltid, och sparning läker.
$src = (string) file_get_contents(__DIR__ . '/../src/ordrar.php');
function fh(bool $ok, string $m): void { if (!$ok) { fwrite(STDERR, "FAIL $m\n"); exit(1); } echo "OK  $m\n"; }
$on = substr($src, strpos($src, 'function jeab_ordrar_fortnox_on_next('), 1400);
fh(strpos($on, "fortnox_error'] = \$msg") < strpos($on, 'Saknas för Fortnox-order'), 'fortnox_error sparas före Saknas-rethrow');
fh(str_contains($src, 'function jeab_ordrar_fortnox_heal('), 'heal finns');
$heal = substr($src, strpos($src, 'function jeab_ordrar_fortnox_heal('), 1200);
fh(str_contains($heal, 'jeab_ordrar_allt_klart'), 'stängda ordrar rörs inte');
fh(str_contains($heal, "fortnox_order'] ?? '')) !== ''"), 'befintlig order skrivs inte om');
fh(!str_contains($heal, 'make_invoice'), 'ingen faktura');
$i = strpos($src, 'function jeab_ordrar_save_batterisol(');
$b = substr($src, $i, strpos($src, "\nfunction ", $i + 10) - $i);
fh(substr_count($b, 'jeab_ordrar_fortnox_heal(') === 2, 'batteri/sol-spec läker i båda utgångarna');
