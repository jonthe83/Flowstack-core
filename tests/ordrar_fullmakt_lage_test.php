<?php

declare(strict_types=1);

/**
 * Läge-kortet Fullmakt visar tre lägen: Ej påbörjad/manuell, Skickad, Signerad.
 * "Väntar" ska inte synas på kortet.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

jeab_ordrar_external_live(false);

function flage_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

function flage_fullmakt_slice(string $html): string
{
    if (!preg_match('/<article class="ordrar-lage-card">\s*<p class="ordrar-lage-kicker">Fullmakt<\/p>[\s\S]*?<\/article>/', $html, $m)) {
        return '';
    }
    return $m[0];
}

function flage_fullmakt_card(string $html): array
{
    if (!preg_match(
        '/ordrar-lage-kicker">Fullmakt<\/p>\s*<p class="ordrar-lage-label">([^<]*)<\/p>[\s\S]*?ordrar-lage-dot is-([a-z]+)/',
        $html,
        $m
    )) {
        return ['', ''];
    }
    return [html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[2]];
}

function flage_base(array $extra = []): array
{
    return $extra + [
        'kind' => 'sol',
        'company' => 'jarfalla',
        'name' => 'Ada Adolfsson',
        'natbolag' => 'Ellevio',
        'address' => 'Björkvägen 4, 181 30 Lidingö',
        'fortnox_order' => '',
    ];
}

flage_assert(
    jeab_ellevio_fullmakt_status_from_state([
        'applies' => true,
        'signed' => false,
        'viewed' => false,
        'sent' => false,
    ]) === 'ej_paborjad',
    'inget utskick är ej påbörjad'
);
flage_assert(
    jeab_ellevio_fullmakt_status_from_state([
        'applies' => true,
        'signed' => false,
        'viewed' => true,
        'sent' => false,
    ]) === 'skickad',
    'öppnad länk räknas som skickad'
);
flage_assert(
    jeab_ellevio_fullmakt_status_from_state([
        'applies' => true,
        'signed' => false,
        'viewed' => false,
        'sent' => true,
    ]) === 'skickad',
    'mejlat utskick är skickad'
);
flage_assert(
    jeab_ellevio_fullmakt_status_from_state([
        'applies' => true,
        'signed' => true,
        'viewed' => true,
        'sent' => true,
    ]) === 'signerad',
    'signerad vinner över skickad'
);
flage_assert(
    jeab_ellevio_fullmakt_status_from_state(['applies' => false, 'signed' => false]) === 'behovs_ej',
    'annan nätägare behövs ej'
);

foreach (['ej_paborjad', 'vantar', 'skickad', 'tittat', 'signerad', 'behovs_ej'] as $key) {
    flage_assert(
        jeab_ellevio_fullmakt_status_label($key) !== 'Väntar',
        'etiketten ' . $key . ' är inte Väntar'
    );
}
flage_assert(jeab_ellevio_fullmakt_status_label('ej_paborjad') === 'Ej påbörjad/manuell', 'ej påbörjad-etikett');
flage_assert(jeab_ellevio_fullmakt_status_label('vantar') === 'Ej påbörjad/manuell', 'gammal vantar-nyckel får samma etikett');
flage_assert(jeab_ellevio_fullmakt_status_label('tittat') === 'Skickad', 'tittat visas som Skickad');
flage_assert(jeab_ellevio_fullmakt_status_label('skickad') === 'Skickad', 'skickad-etikett');
flage_assert(jeab_ellevio_fullmakt_status_label('signerad') === 'Signerad', 'signerad-etikett');

$cases = [
    'ej påbörjad' => [
        flage_base([
            'fullmakt_token' => '',
            'fullmakt_mailed' => false,
            'fullmakt_error' => 'Saknas för fullmakt: e-post.',
        ]),
        'Ej påbörjad/manuell',
        'warn',
    ],
    'skickad via mejl' => [
        flage_base([
            'fullmakt_token' => 'tok-skickad',
            'fullmakt_mailed' => true,
            'fullmakt_sent_at' => '2026-09-20T10:00:00+02:00',
        ]),
        'Skickad',
        'brand',
    ],
    'skickad via sent_at' => [
        flage_base([
            'fullmakt_token' => 'tok-pdf',
            'fullmakt_mailed' => false,
            'fullmakt_sent_at' => '2026-09-21T10:00:00+02:00',
        ]),
        'Skickad',
        'brand',
    ],
    'signerad på ordern' => [
        flage_base([
            'fullmakt_token' => 'tok-signerad',
            'fullmakt_mailed' => true,
            'fullmakt_signed' => true,
        ]),
        'Signerad',
        'ok',
    ],
];

foreach ($cases as $name => [$row, $label, $tone]) {
    $html = jeab_ordrar_status_html($row, 'jarfalla', 11, null);
    [$gotLabel, $gotTone] = flage_fullmakt_card($html);
    flage_assert($gotLabel === $label, $name . ' visar ' . $label . ', fick ' . $gotLabel);
    flage_assert($gotTone === $tone, $name . ' har prick is-' . $tone . ', fick is-' . $gotTone);
    flage_assert(!str_contains(flage_fullmakt_slice($html), 'Väntar'), $name . ' nämner inte Väntar på fullmaktkortet');
}

$other = jeab_ordrar_status_html(flage_base(['natbolag' => 'E.ON']), 'jarfalla', 12, null);
[$otherLabel, $otherTone] = flage_fullmakt_card($other);
flage_assert($otherLabel === 'Behövs ej', 'E.ON visar Behövs ej, fick ' . $otherLabel);
flage_assert($otherTone === 'muted', 'E.ON-prick är muted, fick ' . $otherTone);
flage_assert(!str_contains(flage_fullmakt_slice($other), 'Väntar'), 'E.ON nämner inte Väntar på fullmaktkortet');
flage_assert(!str_contains(flage_fullmakt_slice($other), 'Öppna SEOM-fullmakt'), 'E.ON-kortet länkar inte till SEOM-flödet');

$seom = flage_base([
    'natbolag' => 'SEOM',
    'fastighetsbeteckning' => 'Sollentuna Lertavlan 4',
    'address' => 'Lertavlan 4, 192 51 Sollentuna',
    'huvudsokande_address' => 'Lertavlan 4, 192 51 Sollentuna',
]);
$seomHtml = jeab_ordrar_status_html($seom, 'jarfalla', 21, null);
[$seomLabel, $seomTone] = flage_fullmakt_card($seomHtml);
$seomSlice = flage_fullmakt_slice($seomHtml);
flage_assert($seomLabel === 'Behövs', 'SEOM Sollentuna Lertavlan 4 visar Behövs, fick ' . $seomLabel);
flage_assert($seomTone === 'warn', 'SEOM-prick är warn, fick is-' . $seomTone);
flage_assert(str_contains($seomSlice, 'Öppna SEOM-fullmakt'), 'SEOM-kortet länkar till fullmaktsflödet');
flage_assert(str_contains($seomSlice, 'stop=eon'), 'SEOM-länken öppnar föranmälan');
flage_assert(str_contains($seomSlice, 'id=21'), 'SEOM-länken pekar på ordern');

$sent = $seom;
$sent['seom_sam_sent_at'] = '2026-10-26T12:00:00+01:00';
$sentHtml = jeab_ordrar_status_html($sent, 'jarfalla', 21, null);
[$sentLabel, $sentTone] = flage_fullmakt_card($sentHtml);
$sentSlice = flage_fullmakt_slice($sentHtml);
flage_assert($sentLabel === 'Skickad till Sam', 'SEOM efter utskick visar Skickad till Sam, fick ' . $sentLabel);
flage_assert($sentTone === 'brand', 'SEOM efter utskick har brand-prick, fick is-' . $sentTone);
flage_assert(str_contains($sentSlice, 'Öppna SEOM-fullmakt'), 'SEOM-länken ligger kvar efter utskick till Sam');

$customer = $sent;
$customer['seom_customer_sent_at'] = '2026-10-26T13:00:00+01:00';
[$customerLabel, $customerTone] = flage_fullmakt_card(jeab_ordrar_status_html($customer, 'jarfalla', 21, null));
flage_assert($customerLabel === 'Skickad till kund', 'SEOM efter kundutskick visar Skickad till kund, fick ' . $customerLabel);
flage_assert($customerTone === 'brand', 'SEOM efter kundutskick har brand-prick, fick is-' . $customerTone);

$signed = $customer;
$signed['seom_customer_signed_at'] = '2026-10-26T14:00:00+01:00';
[$signedLabel, $signedTone] = flage_fullmakt_card(jeab_ordrar_status_html($signed, 'jarfalla', 21, null));
flage_assert($signedLabel === 'Signerad', 'SEOM efter kundsignering visar Signerad, fick ' . $signedLabel);
flage_assert($signedTone === 'ok', 'SEOM efter kundsignering har grön prick, fick is-' . $signedTone);

$eon = jeab_ordrar_eon_html($seom, 'jarfalla', 21, null);
flage_assert(str_contains($eon, 'data-seom-panel'), 'föranmälan-steget har SEOM-panelen');
flage_assert(str_contains($eon, 'Nästa skapar SEOM-blanketten och mejlar den till Sam'), 'föranmälan beskriver utskicket till Sam');

$overview = jeab_ordrar_v3_overview_html($seom, 'jarfalla', 21, null);
flage_assert(str_contains($overview, 'v3-k">Fullmakt</div><div class="v3-v">Behövs</div>'), 'v3 visar Behövs på Fullmakt');
flage_assert(str_contains($overview, 'href="#steg-foranmalan">Öppna SEOM-fullmakt</a>'), 'v3 länkar till föranmälan-steget');
$sentOverview = jeab_ordrar_v3_overview_html($sent, 'jarfalla', 21, null);
flage_assert(str_contains($sentOverview, 'v3-k">Fullmakt</div><div class="v3-v">Skickad till Sam</div>'), 'v3 visar Skickad till Sam');
flage_assert(str_contains($sentOverview, 'href="#steg-foranmalan">Öppna SEOM-fullmakt</a>'), 'v3-länken ligger kvar efter utskick');
$v3step = jeab_ordrar_v3_foranmalan_html($seom, 'jarfalla', 21, ['state' => 'todo', 'n' => 3, 'reason' => '']);
flage_assert(str_contains($v3step, 'id="steg-foranmalan"'), 'v3-målet är föranmälan-steget');
flage_assert(str_contains($v3step, 'Skicka SEOM-blankett'), 'v3-steget skapar och skickar blanketten till Sam');
flage_assert(str_contains($v3step, 'data-seom-panel'), 'v3-steget visar SEOM-statusen');

$pill = jeab_ellevio_fullmakt_status_html(flage_base([
    'fullmakt_error' => 'Saknas för fullmakt: e-post.',
]));
flage_assert(str_contains($pill, 'Ej påbörjad/manuell'), 'pillen säger Ej påbörjad/manuell');
flage_assert(str_contains($pill, 'Saknas för fullmakt: e-post.'), 'pillen behåller feltexten');
flage_assert(!str_contains($pill, 'Väntar'), 'pillen nämner inte Väntar');

$viewToken = '';
if (jeab_ellevio_fullmakt_load() && function_exists('fullmakt_create_doc')) {
    $doc = fullmakt_create_doc([
        'type' => 'ellevio',
        'namn' => 'Ada Adolfsson',
        'epost' => 'ada@example.com',
        'viewed_at' => '2026-09-22T08:00:00+02:00',
    ]);
    $viewToken = (string) ($doc['token'] ?? '');
    $viewed = jeab_ordrar_status_html(flage_base([
        'fullmakt_token' => $viewToken,
        'fullmakt_mailed' => false,
    ]), 'jarfalla', 13, null);
    [$viewLabel, $viewTone] = flage_fullmakt_card($viewed);
    flage_assert($viewLabel === 'Skickad', 'öppnad fullmakt visas som Skickad, fick ' . $viewLabel);
    flage_assert($viewTone === 'brand', 'öppnad fullmakt har brand-prick, fick is-' . $viewTone);

    $signedDoc = fullmakt_create_doc([
        'type' => 'overlay',
        'namn' => 'Bo Berg',
    ]);
    $signedToken = (string) ($signedDoc['token'] ?? '');
    $signedDoc['status'] = 'signed';
    fullmakt_write_json(fullmakt_pending_path($signedToken), $signedDoc);
    $signedHtml = jeab_ordrar_status_html(flage_base([
        'fullmakt_token' => $signedToken,
        'fullmakt_mailed' => true,
        'fullmakt_signed' => false,
    ]), 'jarfalla', 14, null);
    [$signedLabel, $signedTone] = flage_fullmakt_card($signedHtml);
    flage_assert($signedLabel === 'Signerad', 'signerat dokument visas som Signerad, fick ' . $signedLabel);
    flage_assert($signedTone === 'ok', 'signerat dokument har grön prick, fick is-' . $signedTone);

    foreach ([$viewToken, $signedToken] as $token) {
        $path = fullmakt_pending_path($token);
        if (is_file($path)) {
            @unlink($path);
        }
    }
} else {
    fwrite(STDERR, "FAIL fullmaktmodulen gick inte att ladda\n");
    exit(1);
}

echo "ordrar fullmakt läge: ok\n";
