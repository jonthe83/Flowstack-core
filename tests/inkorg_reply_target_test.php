<?php

declare(strict_types=1);

/** Svar i vanlig tråd går till avsändaren. Bara plattform/formulär byter mottagare. Inget riktigt mejl. */

$tmp = sys_get_temp_dir() . '/jeab-reply-target-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'json', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function rt(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_SENT_BODIES'] = [];
$GLOBALS['_JEAB_GRAPH_HTTP'] = function (string $method, string $url, ?array $body) {
    if ($method === 'POST' && (str_ends_with($url, '/sendMail') || str_contains($url, '/reply'))) {
        $GLOBALS['_SENT_BODIES'][] = [$url, $body];
        return ['code' => 202, 'body' => []];
    }
    return ['code' => 500, 'body' => []];
};

rt(jeab_inkorg_own_address('saljare4@jarfalla.example.test') && jeab_inkorg_own_address('saljare3@malmo.example.test') && jeab_inkorg_own_address('agenter@flowstack.example.test') && jeab_inkorg_own_address('inkorg@example.test') && jeab_inkorg_own_address('inkorg+inkorg@example.test'), 'personal och inkorg@/inkorg+inkorg@ är egna');
rt(!jeab_inkorg_own_address('kund@gmail.com') && !jeab_inkorg_own_address('form@kalkyl.jarfalla.example.test') && !jeab_inkorg_own_address('inkorg+test-1@example.test') && !jeab_inkorg_own_address('kund@example.test'), 'kund, kalkyl och övriga example-adresser är inte egna brevlådor');
rt(jeab_inkorg_mailbox_address('kontakt@jarfalla.example.test') && !jeab_inkorg_mailbox_address('saljare4@jarfalla.example.test'), 'bara kontakt@ är den delade brevlådan');
rt(jeab_lead_platform('noreply@weply.dk') === 'weply.dk' && jeab_lead_platform('orders@wix.com') === 'wix.com' && jeab_lead_platform('bounce@mandrillapp.com') === 'mandrillapp.com', 'weply.dk, Wix Stores och mandrill är plattformar');

$take = function (): array {
    $last = $GLOBALS['_SENT_BODIES'][count($GLOBALS['_SENT_BODIES']) - 1] ?? ['', []];
    return $last;
};

$ingest = function (string $id, string $from, string $fromName, string $body, array $extra = []): array {
    return jeab_inkorg_ingest([
        'message_id' => $id . '@reply.test',
        'from' => $from,
        'from_name' => $fromName,
        'to' => 'kontakt@jarfalla.example.test',
        'subject' => (string) ($extra['subject'] ?? 'Fråga'),
        'body_text' => $body,
        'source' => 'graph-jarfalla',
        'graph_mailbox' => 'kontakt@jarfalla.example.test',
        'graph_id' => 'graph-' . $id,
        'thread_key' => 'reply-' . $id,
        'test' => true,
        'headers' => (array) ($extra['headers'] ?? []),
    ]);
};

$thread = function (string $tid): array {
    foreach (jeab_inkorg_threads() as $t) {
        if ($t['id'] === $tid) {
            return $t;
        }
    }
    return [];
};

// Vanligt gmail med en annan adress i brödtexten.
$gmail = $ingest('gmail', 'anna.privat@gmail.com', 'Anna Privat', "Hej, fakturan ligger hos ekonomi@fortnox.se.\nKan ni titta på batteriet?\n");
$tid = (string) $gmail['thread_id'];
$party = jeab_inkorg_party($thread($tid));
rt($party['email'] === 'anna.privat@gmail.com' && $party['source'] === 'from', 'gmail-kund är avsändaren, inte fortnox, fick ' . $party['email'] . '/' . $party['source']);
rt(!jeab_inkorg_address_blocked('anna.privat@gmail.com') && jeab_inkorg_address_blocked('saljare4@jarfalla.example.test'), 'personal är blockerad som kund, gmail är det inte');
$sent = jeab_inkorg_send_in_thread($tid, ['body' => 'Hej Anna, vi återkommer.'], 'Test');
$last = $take();
rt($sent['to'] === 'anna.privat@gmail.com' && str_contains($last[0], '/messages/graph-gmail/reply') && ($last[1]['message']['toRecipients'][0]['emailAddress']['address'] ?? '') === 'anna.privat@gmail.com', 'gmail-svar är Graph-reply till avsändaren');
rt(!str_contains($last[0], 'fortnox') && !str_contains(json_encode($last[1]), 'fortnox'), 'fortnox-adressen i texten blir inte mottagare');

// Leverantör: domänen i From, inte en liknande adress i texten.
$sup = $ingest('rack', 'order@sl-rack.de', 'SL Rack', "Orderbekräftelse.\nKopia: info@sl-rack.com\n");
$tid = (string) $sup['thread_id'];
rt(jeab_inkorg_party($thread($tid))['email'] === 'order@sl-rack.de', 'leverantören är avsändaren');
$sent = jeab_inkorg_send_in_thread($tid, ['body' => 'Tack, vi har tagit emot ordern.'], 'Test');
$last = $take();
rt($sent['to'] === 'order@sl-rack.de' && str_contains($last[0], '/messages/graph-rack/reply'), 'leverantörssvar stannar i tråden till sl-rack.de');
rt(!str_contains(json_encode($last[1]), 'sl-rack.com'), 'sl-rack.com i texten byter inte mottagare');

// Personal vidarebefordrar. Kunden i texten och personalens adress är inte kunden. Svaret går till avsändaren.
$fwd = $ingest('fwd', 'saljare4@jarfalla.example.test', 'Elin', "Vidarebefordrat från kunden.\nE-post: kund.forward@gmail.com\nRoberto tar installationen, saljare3@jarfalla.example.test.\n");
$tid = (string) $fwd['thread_id'];
$party = jeab_inkorg_party($thread($tid));
rt($party['email'] === '' && $party['email'] !== 'kund.forward@gmail.com' && $party['email'] !== 'saljare4@jarfalla.example.test', 'vidarebefordran från personal blir inte en kundadress');
rt(jeab_inkorg_contact_for_thread($thread($tid)) === null, 'ingen kontakt skapas på personal eller adressen i texten');
$sent = jeab_inkorg_send_in_thread($tid, ['body' => 'Jag svarar i tråden.'], 'Test');
$last = $take();
rt($sent['to'] === 'saljare4@jarfalla.example.test' && str_contains($last[0], '/messages/graph-fwd/reply'), 'vidarebefordran svaras i tråden till avsändaren');
rt(!str_contains(json_encode($last[1]), 'kund.forward@gmail.com') && !str_contains(json_encode($last[1]), 'saljare3@'), 'varken kunden i texten eller saljare3@ blir mottagare');

// Wix-lead: nytt mejl till kunden, inte Graph-svar till formuläret.
$wixBody = "A site visitor just submitted your form Batteri lead\n\nNamn:\n\nAnna Svensson\n\nE-post:\n\nkund.b@example.invalid\n\nMeddelande:\n\nTaket är mot sydväst.\n";
$wix = $ingest('wix', 'info@wix-forms.com', 'Formulär JEAB', $wixBody, ['subject' => 'JEAB Batteri lead']);
$tid = (string) $wix['thread_id'];
$party = jeab_inkorg_party($thread($tid));
rt($party['email'] === 'kund.b@example.invalid' && $party['source'] === 'body', 'Wix-kunden läses ur brödtexten');
$sent = jeab_inkorg_send_in_thread($tid, ['body' => 'Hej Anna, här kommer en hälsning.'], 'Test');
$last = $take();
rt($sent['to'] === 'kund.b@example.invalid' && str_contains($last[0], '/sendMail') && !str_contains($last[0], '/reply'), 'Wix-svar är ett nytt mejl till kunden');
rt(!str_contains(json_encode($last[1]), 'wix-forms.com'), 'formuläradressen är inte mottagare');

// Weply.dk är plattform: Reply-To används när brödtexten saknar e-post.
$weply = jeab_inkorg_ingest([
    'message_id' => 'weply-dk@reply.test',
    'from' => 'noreply@weply.dk',
    'from_name' => 'Weply',
    'to' => 'kontakt@jarfalla.example.test',
    'subject' => 'Ny förfrågan',
    'body_text' => "Namn: Kim Alm\nMeddelande: Vill ha solceller.\n",
    'headers' => ['Reply-To' => 'Kim Alm <kim.alm@gmail.com>'],
    'source' => 'graph-jarfalla',
    'graph_mailbox' => 'kontakt@jarfalla.example.test',
    'graph_id' => 'graph-weply-dk',
    'thread_key' => 'reply-weply-dk',
    'test' => true,
]);
$tid = (string) $weply['thread_id'];
rt(jeab_inkorg_party($thread($tid))['email'] === 'kim.alm@gmail.com' && jeab_inkorg_party($thread($tid))['source'] === 'reply-to', 'Weply.dk använder Reply-To');
$sent = jeab_inkorg_send_in_thread($tid, ['body' => 'Hej Kim.'], 'Test');
$last = $take();
rt($sent['to'] === 'kim.alm@gmail.com' && str_contains($last[0], '/sendMail'), 'Weply.dk skickas som nytt mejl till kunden');

echo "inkorg reply target: ok\n";
