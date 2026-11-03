<?php

declare(strict_types=1);

/**
 * Kalenderanteckning på befintlig bokning: Spara skriver till booking.notes
 * och formulärets data-notes läser den sparade texten — inte omkomponerad
 * install_note från ordern (det såg ut som att Spara misslyckades).
 */

$tmp = sys_get_temp_dir() . '/jeab-booking-notes-' . getmypid();
if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL temp dir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
file_put_contents($tmp . '/config.php', <<<'PHP'
<?php
return [
    'timezone' => 'Europe/Stockholm',
    'outgoing_webhook_url' => '',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => __DIR__ . '/app.sqlite',
        'json_path' => __DIR__ . '/store.json',
    ],
];
PHP);

require dirname(__DIR__) . '/src/bootstrap.php';

function bn_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

date_default_timezone_set('Europe/Stockholm');
$store = jeab_store(true);
$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Stockholm'));
$start = $now->modify('+1 day')->setTime(9, 0);
$created = jeab_create_booking(
    $store,
    'Anteckning Kund',
    ['Sam L'],
    $start->format('Y-m-d H:i:s'),
    $start->modify('+1 hour')->format('Y-m-d H:i:s'),
    'laddbox',
    [
        'company' => 'jarfalla',
        'email' => 'anteckning@kund.se',
        'notes' => 'gammal anteckning',
    ]
);

$attrs = jeab_booking_data_attrs($created);
bn_assert(str_contains($attrs, 'data-notes="gammal anteckning"'), 'data-notes visar sparad text');

$updated = jeab_update_booking($store, (int) $created['id'], [
    'customer' => 'Anteckning Kund',
    'assignees' => ['Sam L'],
    'company' => 'jarfalla',
    'starts_at' => (string) $created['starts_at'],
    'ends_at' => (string) $created['ends_at'],
    'job_type' => 'laddbox',
    'email' => 'anteckning@kund.se',
    'notes' => "ny anteckning från Spara\nrad två",
]);
bn_assert((string) ($updated['notes'] ?? '') === "ny anteckning från Spara\nrad två", 'update_booking sparar notes');

$fresh = $store->getBooking((int) $created['id']);
bn_assert((string) ($fresh['notes'] ?? '') === "ny anteckning från Spara\nrad två", 'notes ligger kvar i store');

$attrs2 = jeab_booking_data_attrs($fresh);
bn_assert(str_contains($attrs2, 'data-notes="ny anteckning från Spara'), 'data-notes följer sparad text efter Spara');
bn_assert(!str_contains($attrs2, 'data-notes="gammal anteckning"'), 'gammal text är borta från data-notes');

$src = (string) file_get_contents(dirname(__DIR__) . '/src/bookings.php');
bn_assert(str_contains($src, "'data-notes' => trim((string) (\$row['notes'] ?? ''))"), 'data-notes läser booking.notes');
bn_assert(!str_contains($src, "'data-notes' => jeab_booking_site_notes(\$row)"), 'data-notes anropar inte site_notes');

echo "ALL OK\n";
