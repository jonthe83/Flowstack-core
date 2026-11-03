<?php

declare(strict_types=1);

/**
 * inbox.gp_sent body_check=1 läser brödtext med GET och svarar bara med flaggor,
 * antal och bilagenamn. Ingen brödtext lämnar funktionen. Inget mejl ändras.
 */

$tmp = sys_get_temp_dir() . '/jeab-gp-body-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa testkatalog\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;

require dirname(__DIR__) . '/src/bootstrap.php';

function bc(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

register_shutdown_function(static function () use ($tmp): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($tmp);
});

bc(jeab_gp_sent_body_flags('') === [
    'has_helpscout_link' => false,
    'has_kundens_fraga' => false,
    'has_internal_marker' => false,
], 'tom text har inga flaggor');
bc(jeab_gp_sent_body_flags('Se https://secure.helpscout.net/conversation/24563/1')['has_helpscout_link'] === true, 'helpscout.net är en länk');
bc(jeab_gp_sent_body_flags('Kundens FRÅGA om taket')['has_kundens_fraga'] === true, 'Kundens fråga är skiftlägesokänslig');
bc(jeab_gp_sent_body_flags('Kundens fr&aring;ga')['has_kundens_fraga'] === true, 'HTML-entiteten fråga räknas');
bc(jeab_gp_sent_body_flags('<p>hs: intern</p>')['has_internal_marker'] === true, 'HS: är en intern markör');
bc(jeab_gp_sent_body_flags('Här är ett Utkast till kunden')['has_internal_marker'] === true, 'Utkast är en intern markör');
bc(jeab_gp_sent_body_flags('utkastet skickades')['has_internal_marker'] === false, 'utkastet är inte markören Utkast');

$secretA = 'TOKEN-BODY-AAA';
$secretB = 'TOKEN-BODY-BBB';
$secretC = 'TOKEN-BODY-CCC';
$calls = [];
$bodies = [
    'AA' => '<p>' . $secretA . ' HS: <a href="https://secure.helpscout.net/conversation/9/1">ärende</a></p><p>Kundens fråga</p>',
    'BB' => $secretB . "\nUtkast till offert utan länk.",
    'CC' => 'Hej ' . $secretC . ' kundens fråga om batteriet.',
];
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body) use (&$calls, $bodies): array {
    $calls[] = [$method, $url, $body];
    if (str_contains($url, '/attachments?')) {
        return ['code' => 200, 'body' => ['value' => [
            ['name' => 'Offert.pdf', 'contentBytes' => 'PDFBYTES-SECRET'],
            ['name' => 'team.jpg'],
        ]]];
    }
    if (str_contains($url, '$select=body')) {
        $id = '';
        if (preg_match('~/messages/([^?]+)~', $url, $m) === 1) {
            $id = rawurldecode($m[1]);
        }
        return ['code' => 200, 'body' => [
            'body' => ['contentType' => 'HTML', 'content' => $bodies[$id] ?? ''],
            'bodyPreview' => 'PREVIEW-SECRET',
        ]];
    }
    return ['code' => 200, 'body' => ['value' => [
        [
            'id' => 'AA',
            'subject' => 'Offert batteri',
            'body' => ['content' => 'LIST-BODY-SHOULD-NOT-BE-USED'],
            'toRecipients' => [['emailAddress' => ['address' => 'kund@ex.se']]],
            'sentDateTime' => '2026-10-16T08:00:00Z',
            'hasAttachments' => true,
        ],
        [
            'id' => 'BB',
            'subject' => 'Utkastämne',
            'sentDateTime' => '2026-10-15T08:00:00Z',
            'hasAttachments' => false,
            'toRecipients' => [],
        ],
        [
            'id' => 'CC',
            'subject' => 'Fråga',
            'sentDateTime' => '2026-10-14T08:00:00Z',
            'hasAttachments' => false,
            'toRecipients' => [],
        ],
    ]]];
};

$calls = [];
$plain = jeab_gp_sent(['mailbox' => 'saljare3@jarfalla.example.test', 'n' => 3]);
bc(($plain['ok'] ?? false) === true && !array_key_exists('body_check', $plain) && !array_key_exists('counts', $plain), 'utan body_check saknas flaggsvaret');
bc(!array_key_exists('has_helpscout_link', $plain['messages'][0] ?? []), 'utan body_check hämtas inga flaggor');
bc(count(array_filter($calls, static fn (array $c): bool => str_contains($c[1], '$select=body'))) === 0, 'utan body_check hämtas ingen brödtext');

$calls = [];
$checked = jeab_gp_sent(['mailbox' => 'saljare3@jarfalla.example.test', 'n' => 3, 'body_check' => 1]);
bc(($checked['ok'] ?? false) === true && (int) ($checked['body_check'] ?? 0) === 1, 'body_check=1 är på');
bc($checked['counts'] === [
    'has_helpscout_link' => 1,
    'has_kundens_fraga' => 2,
    'has_internal_marker' => 2,
], 'antalen räknar träffarna');
bc(($checked['messages'][0]['has_helpscout_link'] ?? false) === true
    && ($checked['messages'][0]['has_kundens_fraga'] ?? false) === true
    && ($checked['messages'][0]['has_internal_marker'] ?? false) === true
    && ($checked['messages'][0]['attachments'] ?? []) === ['Offert.pdf', 'team.jpg'], 'första mejlet har länk, fråga, HS: och bilagenamn');
bc(($checked['messages'][1]['has_helpscout_link'] ?? true) === false
    && ($checked['messages'][1]['has_kundens_fraga'] ?? true) === false
    && ($checked['messages'][1]['has_internal_marker'] ?? false) === true
    && ($checked['messages'][1]['attachments'] ?? null) === [], 'Utkast utan länk eller fråga');
bc(($checked['messages'][2]['has_kundens_fraga'] ?? false) === true
    && ($checked['messages'][2]['has_helpscout_link'] ?? true) === false
    && ($checked['messages'][2]['has_internal_marker'] ?? true) === false, 'liten fråga utan länk eller markör');
$bodyGets = array_values(array_filter($calls, static fn (array $c): bool => str_contains($c[1], '$select=body')));
bc(count($bodyGets) === 3, 'varje mejl hämtar brödtext en gång');
foreach ($calls as $call) {
    bc($call[0] === 'GET' && $call[2] === null, 'bara GET utan skrivkropp');
}
bc(str_contains($bodyGets[0][1], '/messages/AA?$select=body'), 'brödtexten hämtas för meddelande-id');
$encoded = json_encode($checked, JSON_UNESCAPED_UNICODE);
bc(is_string($encoded)
    && !str_contains($encoded, $secretA)
    && !str_contains($encoded, $secretB)
    && !str_contains($encoded, $secretC)
    && !str_contains($encoded, 'LIST-BODY-SHOULD-NOT-BE-USED')
    && !str_contains($encoded, 'PREVIEW-SECRET')
    && !str_contains($encoded, 'PDFBYTES-SECRET')
    && !str_contains($encoded, 'helpscout.net')
    && !str_contains($encoded, 'contentBytes')
    && !str_contains(strtolower($encoded), '"body"'), 'svaret innehåller varken brödtext, länktext eller bilagebyte');

$calls = [];
$asString = jeab_gp_sent(['mailbox' => 'saljare3@jarfalla.example.test', 'n' => 1, 'body_check' => '1']);
bc((int) ($asString['body_check'] ?? 0) === 1 && count(array_filter($calls, static fn (array $c): bool => str_contains($c[1], '$select=body'))) === 1, 'body_check=1 som sträng fungerar');

$calls = [];
$off = jeab_gp_sent(['mailbox' => 'saljare3@jarfalla.example.test', 'n' => 1, 'body_check' => 0]);
bc(!array_key_exists('body_check', $off) && count(array_filter($calls, static fn (array $c): bool => str_contains($c[1], '$select=body'))) === 0, 'body_check=0 läser ingen brödtext');

$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body) use (&$calls): array {
    $calls[] = [$method, $url, $body];
    if (str_contains($url, '$select=body')) {
        return ['code' => 502, 'body' => ['error' => ['message' => 'TOKEN-GRAPH-FEL']]];
    }
    return ['code' => 200, 'body' => ['value' => [[
        'id' => 'AA',
        'subject' => 'Ett',
        'sentDateTime' => '2026-10-16T08:00:00Z',
        'hasAttachments' => false,
        'toRecipients' => [],
    ]]]];
};
$calls = [];
$failed = jeab_gp_sent(['mailbox' => 'saljare3@jarfalla.example.test', 'n' => 1, 'body_check' => 1]);
bc($failed === ['ok' => false, 'error' => 'graph 502', 'mailbox' => 'saljare3@jarfalla.example.test'], 'Graph-fel på brödtexten ekar bara statuskod');
bc(!str_contains((string) json_encode($failed), 'TOKEN-GRAPH-FEL'), 'felkroppen kommer inte med');
bc($calls !== [] && array_reduce($calls, static fn (bool $ok, array $c): bool => $ok && $c[0] === 'GET', true), 'felvägen skriver inte heller');

echo "inkorg gp_sent body_check: all passed\n";
