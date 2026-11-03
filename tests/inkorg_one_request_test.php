<?php

declare(strict_types=1);

/**
 * Första laddningen hämtar bara den öppna tråden.
 * Listans uppdatering hämtar ix_part=all utan att bygga om listan.
 * De gamla delarna list, thread, counts och app finns kvar.
 */

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/src/pages/inkorg.php');
$boot = (string) file_get_contents($root . '/src/bootstrap.php');

function one(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

one(str_contains($boot, "'list', 'thread', 'counts', 'app', 'all'"), 'all är en giltig inkorgsdel');
$warm = strpos($page, 'function ixWarm()');
$warmBody = $warm === false ? '' : substr($page, $warm, 900);
one($warm !== false && str_contains($warmBody, 'ixLoadThread(url, false)') && !str_contains($warmBody, "set('ix_part', 'all')") && !str_contains($warmBody, 'ixCountsSoon()'), 'ixWarm hämtar bara den öppna tråden');
$fetch = strpos($page, 'function ixFetch(');
$fetchBody = $fetch === false ? '' : substr($page, $fetch, 8000);
one($fetch !== false && str_contains($fetchBody, "kind === 'list' ? 'all' : kind") && str_contains($fetchBody, 'X-Jeab-Interactive'), 'listuppdatering är all och interaktiv');
one(str_contains($page, "set('ix_part', 'thread')") && str_contains($page, "set('ix_part', 'counts')"), 'tråd och räknare finns kvar');
$scope = strpos($page, 'jeabInkorgBindScopeClicks');
$scopeBody = $scope === false ? '' : substr($page, $scope, 700);
one($scope !== false && str_contains($scopeBody, "ixFetch(url, 'list', true)") && !str_contains($scopeBody, 'ixCountsFor(url)'), 'scope hämtar inte räknarna en gång till');

require $root . '/src/bootstrap.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['p' => 'inkorg', 'ix_part' => 'all'];
one(jeab_inkorg_ix_part() === 'all' && jeab_request_skips_send_guard(), 'all hoppar över utskickskön');
$_GET['ix_part'] = 'list';
one(jeab_inkorg_ix_part() === 'list', 'list fungerar som förut');
$_GET['ix_part'] = 'nope';
one(jeab_inkorg_ix_part() === '', 'okänd del ignoreras');
$_SERVER['HTTP_X_JEAB_INTERACTIVE'] = '';
$_GET['ix_part'] = 'all';
one(jeab_pdo_is_background(), 'varm all räknas som bakgrund');
$_SERVER['HTTP_X_JEAB_INTERACTIVE'] = '1';
one(!jeab_pdo_is_background(), 'klick på all räknas som klick');

echo "ALLA OK\n";
