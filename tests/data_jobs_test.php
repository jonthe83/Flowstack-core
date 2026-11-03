<?php

declare(strict_types=1);

/**
 * data_jobs: direkt svar, lås per typ och data.job_status. Syntetiska rader.
 */

$tmp = sys_get_temp_dir() . '/jeab-data-jobs-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
], true) . ";\n");
file_put_contents($tmp . '/inkorg/messages.json', '{"messages":[]}');
file_put_contents($tmp . '/inkorg/threads.json', '{"threads":[]}');
file_put_contents($tmp . '/inkorg/contacts.json', '{"contacts":[]}');
file_put_contents($tmp . '/kontakter.json', '{"contacts":[]}');
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/data_jobs.php';

function dj(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

$src = (string) file_get_contents(dirname(__DIR__) . '/src/data_jobs.php');
$hook = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
dj(str_contains($src, 'fastcgi_finish_request') && str_contains($src, 'ignore_user_abort') && str_contains($src, 'set_time_limit'), 'svaret släpps med fastcgi eller ignore_user_abort');
dj(str_contains($src, 'FOR UPDATE'), 'låset läses med FOR UPDATE');
dj(str_contains($hook, 'data.job_cancel'), 'hooken kan avbryta jobbet');
dj(str_contains((string) file_get_contents(dirname(__DIR__) . '/src/db.php'), 'function jeab_ensure_data_jobs'), 'data_jobs skapas för mysql och sqlite');
ob_start();
jeab_data_job_detach('{"ok":true,"job_id":"abc"}');
dj(ob_get_clean() === '{"ok":true,"job_id":"abc"}', 'detach skriver svaret direkt');

file_put_contents($tmp . '/secrets/inkorg-ingest.json', json_encode(['key' => 'tok-data-jobs']));
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tok-data-jobs';
$migrate = jeab_data_migrate_dispatch(['action' => 'data.migrate_all', 'dry' => 1]);
dj(!empty($migrate['ok']) && ($migrate['state'] ?? '') === 'queued' && !isset($migrate['migrate']), 'migrate_all köas');
$again = jeab_data_migrate_dispatch(['action' => 'data.migrate_all']);
dj(empty($again['ok']) && (int) ($again['status'] ?? 0) === 409 && ($again['job_id'] ?? '') === ($migrate['job_id'] ?? ''), 'låset stoppar samma typ');
$conflicts = jeab_kontakter_conflicts_dispatch(['action' => 'kontakter.conflicts', 'dry' => 1]);
dj(!empty($conflicts['ok']) && ($conflicts['job_id'] ?? '') !== ($migrate['job_id'] ?? ''), 'en annan typ får ett eget jobb');
$missing = jeab_data_job_status_dispatch(['job_id' => str_repeat('a', 32)]);
dj(empty($missing['ok']) && (int) ($missing['status'] ?? 0) === 404, 'okänt jobb saknas');
$bad = jeab_data_job_status_dispatch(['job_id' => 'nej']);
dj(empty($bad['ok']) && (int) ($bad['status'] ?? 0) === 400, 'job_id måste vara ett id');
$queued = jeab_data_job_status_dispatch(['job_id' => (string) $migrate['job_id']]);
dj(!empty($queued['ok']) && ($queued['state'] ?? '') === 'queued' && $queued['result'] === null, 'köat jobb har inget resultat än');

$GLOBALS['_JEAB_DATA_JOB_MAX_STEPS'] = 2;
jeab_data_job_run((string) $migrate['job_id']);
unset($GLOBALS['_JEAB_DATA_JOB_MAX_STEPS']);
$mid = jeab_data_job_status_dispatch(['job_id' => (string) $migrate['job_id']]);
dj(($mid['state'] ?? '') === 'running' && ($mid['progress']['phase'] ?? '') !== '', 'bitarna sparar förloppet');
dj(!jeab_data_maintenance(), 'torr bit slår inte på underhåll');
$resume = jeab_data_migrate_dispatch(['action' => 'data.migrate_all']);
dj(!empty($resume['resumed']) && ($resume['job_id'] ?? '') === ($migrate['job_id'] ?? ''), 'avbrutet jobb tas upp');
jeab_data_job_lock_release(jeab_data_pdo(), 'data.migrate_all', (string) $migrate['job_id']);

jeab_data_job_run((string) $conflicts['job_id']);
$conflictDone = jeab_data_job_status_dispatch(['job_id' => (string) $conflicts['job_id']]);
dj(($conflictDone['state'] ?? '') === 'done' && !empty($conflictDone['result']['dry']), 'konflikter sparar resultatet');
$late = jeab_data_job_cancel_dispatch(['job_id' => (string) $conflicts['job_id']]);
dj(empty($late['ok']) && ($late['error'] ?? '') === 'Jobbet är redan klart.', 'klart jobb avbryts inte');

$partial = jeab_kontakter_conflicts_dispatch(['action' => 'kontakter.conflicts', 'dry' => 1, 'limit' => 1]);
dj(!empty($partial['ok']), 'en ny konfliktkörning köas');
$GLOBALS['_JEAB_DATA_JOB_MAX_STEPS'] = 1;
jeab_data_job_run((string) $partial['job_id']);
unset($GLOBALS['_JEAB_DATA_JOB_MAX_STEPS']);
$partialStatus = jeab_data_job_status_dispatch(['job_id' => (string) $partial['job_id']]);
dj(($partialStatus['state'] ?? '') === 'running' && (int) ($partialStatus['progress']['limit'] ?? 0) === 1 && array_key_exists('cursor', (array) ($partialStatus['progress'] ?? [])), 'biten sparar cursor och limit');
$stopped = jeab_data_job_cancel_dispatch(['job_id' => (string) $partial['job_id']]);
dj(!empty($stopped['ok']) && ($stopped['state'] ?? '') === 'cancelled', 'avbrott markerar jobbet');
$stoppedAgain = jeab_data_job_cancel_dispatch(['job_id' => (string) $partial['job_id']]);
dj(!empty($stoppedAgain['ok']) && ($stoppedAgain['state'] ?? '') === 'cancelled', 'avbrott kan upprepas');
$lock = jeab_data_job_lock_row(jeab_data_pdo(), 'kontakter.conflicts');
dj($lock === null || (string) ($lock['job_id'] ?? '') !== (string) $partial['job_id'], 'avbrott släpper låset');
jeab_data_job_run((string) $partial['job_id']);
$still = jeab_data_job_status_dispatch(['job_id' => (string) $partial['job_id']]);
dj(($still['state'] ?? '') === 'cancelled' && $still['result'] === null, 'avbrutet jobb startar inte om');

echo "data_jobs_test ok\n";
