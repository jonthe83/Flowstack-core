<?php

declare(strict_types=1);

/**
 * SMS-texten följer samma bolag som mejl-From.
 * Ingen SureSMS-förfrågan lämnar maskinen.
 */

$tmp = sys_get_temp_dir() . '/jeab-sms-company-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';

function fm_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

date_default_timezone_set('Europe/Stockholm');

$range = [
    'starts_at' => '2026-09-25 09:00:00',
    'ends_at' => '2026-09-25 12:00:00',
];
$allDay = [
    'starts_at' => '2026-09-25 00:00:00',
    'ends_at' => '2026-09-26 00:00:00',
];
$hour = [
    'starts_at' => '2026-09-25 09:00:00',
    'ends_at' => '2026-09-25 09:30:00',
];

$cases = [
    [$allDay + ['company' => 'jarfalla'], 'Demo Energi Järfälla kommer imorgon.'],
    [$range + ['company' => 'jarfalla'], 'Demo Energi Järfälla kommer imorgon kl 9–12.'],
    [$hour + ['company' => 'jarfalla'], 'Demo Energi Järfälla kommer imorgon kl 9.'],
    [$allDay + ['company' => 'malmo'], 'Demo Energi Malmö kommer imorgon.'],
    [$range + ['company' => 'malmo'], 'Demo Energi Malmö kommer imorgon kl 9–12.'],
    [$hour + ['company' => 'malmo'], 'Demo Energi Malmö kommer imorgon kl 9.'],
];
foreach ($cases as [$row, $expected]) {
    $got = jeab_sms_text($row);
    fm_assert($got === $expected, $expected . ' fick «' . $got . '»');
}
$malmoRow = $range + ['company' => 'malmo'];
fm_assert(
    jeab_calendar_mail_company($malmoRow) === 'malmo'
        && jeab_system_mail_from_name(jeab_calendar_mail_company($malmoRow)) === 'Demo Energi Malmö',
    'Malmö-texten använder mejl-From-bolaget'
);

$sam = $range + ['assignee' => 'Sam W'];
fm_assert(
    jeab_sms_text($sam) === 'Demo Energi Malmö kommer imorgon kl 9–12.',
    'Sam W utan company ger Malmö-text, samma som mejl'
);
fm_assert(
    jeab_system_mail_address(jeab_calendar_mail_company($sam)) === 'kontakt@malmo.example.test',
    'samma bolag väljer kontakt@malmo.example.test'
);

$bare = jeab_sms_request_query('+46700000000', 'text', 'malmo', ['login' => 'APIKey', 'password' => 'x']);
fm_assert(!array_key_exists('from', $bare), 'utan from_malmo skickas ingen from');
$bareJeab = jeab_sms_request_query('+46700000000', 'text', 'jarfalla', ['login' => 'APIKey', 'password' => 'x']);
fm_assert(!array_key_exists('from', $bareJeab), 'utan from skickas ingen from för Järfälla');
$with = jeab_sms_request_query('+46700000000', 'text', 'malmo', [
    'login' => 'APIKey',
    'password' => 'x',
    'from' => 'JEAB',
    'from_malmo' => 'MEAB',
]);
fm_assert(($with['from'] ?? '') === 'MEAB', 'Malmö använder from_malmo');
$jeabFrom = jeab_sms_request_query('+46700000000', 'text', 'jarfalla', [
    'login' => 'APIKey',
    'password' => 'x',
    'from' => 'JEAB',
    'from_malmo' => 'MEAB',
]);
fm_assert(($jeabFrom['from'] ?? '') === 'JEAB', 'Järfälla använder from');
$blank = jeab_sms_request_query('+46700000000', 'text', 'malmo', [
    'login' => 'APIKey',
    'password' => 'x',
    'from_malmo' => '  ',
]);
fm_assert(!array_key_exists('from', $blank), 'tom from_malmo utelämnas');

$blocked = jeab_sms_send('+46707654321', 'Demo Energi Malmö kommer imorgon kl 9.', 'malmo');
fm_assert(($blocked['ok'] ?? true) === false, 'tom SureSMS-nyckel skickar inget');
fm_assert(($blocked['error'] ?? '') === 'SureSMS-nyckel saknas', 'avsändningen stannar före HTTP');

echo "sms company text: ok\n";
