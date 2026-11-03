<?php

if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    exit;
}

/**
 * Kopiera till data/config.php (rekommenderas — mappen är spärrad från webben)
 * eller till config.php i roten.
 *
 * Lämna tomt det du inte behöver. Appen fungerar utan den här filen.
 */
return [
    'timezone' => 'Europe/Stockholm',

    // Zapier Catch Hook (utgående). Kan också sättas på sidan ?p=zapier
    'outgoing_webhook_url' => '',

    // Delad hemlighet för hook.php. Genereras automatiskt om tom.
    'incoming_webhook_token' => '',

    // Loopia-cron (tick.php och sms-cron.php) tar fortfarande ?token= i URL:en.
    // tick.php läser privat/jeab_tick_token. sms-cron.php läser suresms tick_token.
    // De är inte hookens token och inte webbshopens nyckel. POST fungerar också.
    // Tom lista och 0 betyder av, så en befintlig cron-URL behöver ingen ändring.
    // 'cron_allow_ips' => ['203.0.113.10'],
    // 'cron_rate_limit' => 30,

    // Lämna bort eller null = gissa från SCRIPT_NAME (/bokning eller /).
    // Tom sträng eller '/' = appen bor i webbroten (flowstack.example.test/).
    // 'base_path' => '',

    // Lösenordshashar. Samma fil som inloggningen: privat/passwords.json bredvid public_html
    // när den filen är inloggningsbutiken, annars data/.private/passwords.json.
    // 'passwords_file' => __DIR__ . '/data/.private/passwords.json',

    'people' => [
        ['id' => 'Roberto', 'email' => 'saljare3@jarfalla.example.test', 'name' => 'Roberto', 'company' => 'jarfalla', 'job' => 'batteri'],
        ['id' => 'SamL', 'email' => 'demo@example.test', 'name' => 'Sam L', 'company' => 'jarfalla', 'job' => null],
        ['id' => 'Elin', 'email' => 'saljare4@jarfalla.example.test', 'name' => 'Elin', 'company' => 'jarfalla', 'job' => 'laddbox'],
        ['id' => 'Niman', 'email' => 'saljare5@jarfalla.example.test', 'name' => 'Niman', 'company' => 'jarfalla', 'job' => 'sol'],
        ['id' => 'Isaq', 'email' => 'saljare6@malmo.example.test', 'name' => 'Isaq', 'company' => 'malmo', 'job' => null],
        ['id' => 'SamW', 'email' => 'demo@malmo.example.test', 'name' => 'Sam W', 'company' => 'malmo', 'job' => null],
        ['id' => 'Abol', 'email' => 'saljare7@malmo.example.test', 'name' => 'Abol', 'company' => 'malmo', 'job' => 'sol'],
        ['id' => 'Kim', 'email' => 'saljare.syd@malmo.example.test', 'name' => 'Kim', 'company' => 'malmo', 'job' => 'laddbox'],
    ],

    // Kontakter. Utan de här nycklarna gäller standard:
    // egna adresser Dimvägen 36 och 38 i Järfälla, spärrat nummer +46700000003,
    // och bokningstyperna internt, filmning, lager, lagret.
    // 'kontakter' => [
    //     'internal_addresses' => ['Kontorsgatan 1, Malmö'],
    //     'blocked_phones' => ['070-000 00 00'],
    //     'internal_job_types' => ['internbokning'],
    // ],

    'db' => [
        // sqlite om PHP har pdo_sqlite, annars JSON-fil.
        // Båda hör hemma i data/.private/. Appen skapar ingen ny publik fil
        // om den katalogen inte går att skapa.
        // mysql = samma databas för påminnelser, bokningar, ordrar och veckologg.
        'driver' => 'sqlite',
        'sqlite_path' => __DIR__ . '/data/.private/app.sqlite',
        'json_path' => __DIR__ . '/data/.private/store.json',
        'mysql' => [
            'host' => 'localhost',
            'name' => '',
            'user' => '',
            'pass' => '',
            'charset' => 'utf8mb4',
        ],
    ],
];
