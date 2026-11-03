<?php

declare(strict_types=1);

/**
 * Merge av biz_code_people: backupens fp-poster skriver över, SQL-only behålls.
 */

require dirname(__DIR__) . '/src/data_cutover_all.php';

function rd(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$fpA = 'fp:' . str_repeat('a', 16);
$fpB = 'fp:' . str_repeat('b', 16);
$fpC = 'fp:' . str_repeat('c', 16);

$backup = [
    $fpA => ['person' => 'Kim', 'via' => 'code', 'head' => 'kim', 'at' => '2026-01-01 00:00:00'],
    $fpB => ['person' => 'Elin', 'via' => 'prefix', 'head' => 'eli', 'at' => '2026-01-02 00:00:00'],
    'extra' => ['via' => 'code'],
];
$sql = [
    $fpA => ['person' => 'Kim', 'via' => 'email', 'head' => 'kim', 'at' => '2026-03-01 00:00:00'],
    $fpC => ['person' => 'SamL', 'via' => 'manual', 'head' => 'jon', 'at' => '2026-04-01 00:00:00'],
    'note' => ['text' => 'behalls'],
];

$before = jeab_cutover_fp_count($sql);
$merged = jeab_cutover_merge_fp_posts($backup, $sql);
$after = jeab_cutover_fp_count($merged);

rd($before === 2 && $after === 3, 'antal fp före och efter');
rd(($merged[$fpA]['via'] ?? '') === 'code' && ($merged[$fpA]['at'] ?? '') === '2026-01-01 00:00:00', 'backup skriver över via och at');
rd(($merged[$fpA]['person'] ?? '') === 'Kim', 'person från backup');
rd(($merged[$fpC]['person'] ?? '') === 'SamL' && ($merged[$fpC]['via'] ?? '') === 'manual', 'fp som bara finns i SQL behålls');
rd(($merged['note']['text'] ?? '') === 'behalls', 'övriga nycklar i SQL behålls');
rd(!array_key_exists('extra', $merged), 'nycklar utan fp skrivs inte in');

$diffs = jeab_cutover_fp_diff($backup, $sql);
$byId = [];
foreach ($diffs as $row) {
    $byId[(string) ($row['id'] ?? '')] = $row;
}
rd(isset($byId[$fpA]) && ($byId[$fpA]['only'] ?? '') === '', 'gemensam fp är en skillnad');
$names = array_map(static fn (array $field): string => (string) ($field['name'] ?? ''), (array) ($byId[$fpA]['fields'] ?? []));
rd(in_array('via', $names, true) && in_array('at', $names, true) && !in_array('person', $names, true) && !in_array('head', $names, true), 'bara via och at skiljer');
rd(($byId[$fpB]['only'] ?? '') === 'backup' && ($byId[$fpC]['only'] ?? '') === 'sql', 'ensidiga poster syns');

echo "restore_doc merge: ok\n";
