<?php

declare(strict_types=1);

const JEAB_ROOT = __DIR__ . '/..';

function fm_assert(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$message}\n");
        exit(1);
    }
}

function jeab_email_send(
    string $to,
    string $text,
    ?string $subject = null,
    ?string $jobType = null,
    array $attachments = [],
    array $tags = ['kalender'],
    bool $logFailure = true,
    ?string $company = null
): array {
    $GLOBALS['hs_calls']++;
    $GLOBALS['hs_last'] = [
        'to' => $to,
        'subject' => $subject,
        'tags' => $tags,
        'log' => $logFailure,
        'files' => count($attachments),
        'company' => $company,
    ];
    return $GLOBALS['hs_result'];
}

function jeab_helpscout_mailbox_id(?string $company = null): ?int
{
    if ($company === 'malmo') {
        $id = (int) ($GLOBALS['hs_mailbox_malmo'] ?? 0);
        return $id > 0 ? $id : null;
    }
    return 372708;
}

function jeab_inkorg_company_live(string $company): bool
{
    unset($company);
    return !empty($GLOBALS['company_live']);
}

function jeab_graph_mailboxes(): array
{
    return ['jarfalla' => 'kontakt@jarfalla.example.test', 'malmo' => 'kontakt@malmo.example.test'];
}

function jeab_graph_configured(): bool
{
    return !empty($GLOBALS['graph_configured']);
}

function jeab_graph_recips(array $list): array
{
    return array_map(static fn ($a) => ['emailAddress' => ['address' => $a]], array_values($list));
}

function jeab_graph_api(string $method, string $path, ?array $body = null, array $extra = []): array
{
    $GLOBALS['graph_calls'][] = ['method' => $method, 'path' => $path, 'body' => $body];
    return $GLOBALS['graph_result'] ?? ['code' => 202, 'body' => ['id' => 'g-1']];
}

function jeab_outbound_system_mail(array $opts, string $text, array $attachments): array
{
    $GLOBALS['outbound_calls']++;
    unset($text, $attachments);
    return $GLOBALS['outbound_result'] ?? ['ok' => false, 'path' => '', 'error' => 'Inkorgen avvisade mejlet.', 'id' => ''];
}

function jeab_log_app_error(string $surface, ?string $message = null): void
{
    $GLOBALS['logged'][] = $surface . ':' . (string) $message;
}

require dirname(__DIR__) . '/src/system_mail.php';

$mailSrc = (string) file_get_contents(dirname(__DIR__) . '/src/system_mail.php');
fm_assert(!str_contains(strtolower($mailSrc), 'nylas'), 'systemmejlet nämner inte Nylas');
$mailCode = preg_replace('#/\*.*?\*/#s', '', $mailSrc) ?? '';
fm_assert(!preg_match('/(?<![A-Za-z0-9_])mail\s*\(/', $mailCode), 'systemmejlet anropar inte PHP mail()');
fm_assert(str_contains($mailSrc, 'function jeab_system_mail_graph'), 'Graph är reserven');

$GLOBALS['graph_configured'] = true;
$GLOBALS['company_live'] = false;
$GLOBALS['hs_calls'] = 0;
$GLOBALS['hs_result'] = ['ok' => true, 'error' => null];
$GLOBALS['graph_calls'] = [];
$GLOBALS['outbound_calls'] = 0;
$GLOBALS['logged'] = [];
$sent = jeab_system_mail_send([
    'to' => 'kund@example.com',
    'subject' => 'Fullmakt',
    'text' => "Hej\nLänk",
    'tags' => ['fullmakt'],
]);
fm_assert($sent['ok'] === true && $sent['path'] === 'helpscout', 'Help Scout wins before Graph');
fm_assert($GLOBALS['hs_calls'] === 1, 'Help Scout called once');
fm_assert($GLOBALS['graph_calls'] === [], 'Graph stays idle when Help Scout accepts');
fm_assert($GLOBALS['outbound_calls'] === 0, 'Inkorgen stays idle when Help Scout accepts');
fm_assert($GLOBALS['logged'] === [], 'success should not hit fellogg');

$GLOBALS['hs_result'] = ['ok' => false, 'error' => 'HTTP 401'];
$GLOBALS['graph_calls'] = [];
$GLOBALS['graph_result'] = ['code' => 202, 'body' => ['id' => 'g-34']];
$sent = jeab_system_mail_send([
    'to' => 'kund@example.com',
    'subject' => '',
    'text' => 'Påminnelse om bokad tid',
    'tags' => ['kalender'],
    'attachments' => [[
        'filename' => 'signerat.pdf',
        'content_type' => 'application/pdf',
        'content' => '%PDF-1.4',
    ]],
]);
fm_assert($sent['ok'] === true && $sent['path'] === 'graph' && $sent['id'] === 'g-34', 'Graph fallback after Help Scout rejection');
fm_assert(count($GLOBALS['graph_calls']) === 1, 'Graph sends once');
$call = $GLOBALS['graph_calls'][0];
fm_assert($call['method'] === 'POST' && str_contains($call['path'], 'users/kontakt%40jarfalla.example.test/sendMail'), 'sendMail on kontakt@jarfalla.example.test');
fm_assert(($call['body']['saveToSentItems'] ?? false) === true, 'saved to Sent');
fm_assert(($call['body']['message']['from']['emailAddress']['address'] ?? '') === 'kontakt@jarfalla.example.test', 'From is the mailbox');
fm_assert(($call['body']['message']['replyTo'][0]['emailAddress']['address'] ?? '') === 'kontakt@jarfalla.example.test', 'Reply-To stays kontakt@');
fm_assert(($call['body']['message']['subject'] ?? '') === 'Bokad tid', 'empty subject becomes Bokad tid');
fm_assert(($call['body']['message']['body']['contentType'] ?? '') === 'HTML', 'body is HTML');
fm_assert(str_contains((string) ($call['body']['message']['attachments'][0]['contentBytes'] ?? ''), base64_encode('%PDF-1.4')), 'PDF bytes reach Graph');
fm_assert($GLOBALS['logged'] === [], 'recovered send should not hit fellogg');

$GLOBALS['graph_calls'] = [];
$GLOBALS['graph_result'] = ['code' => 0, 'body' => []];
$GLOBALS['logged'] = [];
$sent = jeab_system_mail_send([
    'to' => 'kund@example.com',
    'subject' => 'Hej',
    'text' => 'Text',
]);
fm_assert($sent['ok'] === false && $sent['path'] === '', 'Graph code 0 stays failed');
fm_assert(count($GLOBALS['graph_calls']) === 1, 'failure must not send a second message');
fm_assert(str_contains((string) $sent['error'], 'Help Scout'), 'error names Help Scout');
fm_assert(str_contains((string) $sent['error'], 'Graph'), 'error names Graph');
fm_assert(count($GLOBALS['logged']) === 1, 'one fellogg row for the final failure');

$GLOBALS['graph_calls'] = [];
$GLOBALS['graph_result'] = ['code' => 500, 'body' => []];
$sent = jeab_system_mail_send(['to' => 'kund@example.com', 'subject' => 'Hej', 'text' => 'Text']);
fm_assert($sent['ok'] === false && count($GLOBALS['graph_calls']) === 1, 'HTTP 500 is one shot');

$GLOBALS['graph_configured'] = false;
$GLOBALS['graph_calls'] = [];
$sent = jeab_system_mail_send(['to' => 'kund@example.com', 'subject' => 'Hej', 'text' => 'Text']);
fm_assert($sent['ok'] === false && $GLOBALS['graph_calls'] === [] && str_contains((string) $sent['error'], 'Microsoft 365'), 'unconfigured Graph does not send');
$GLOBALS['graph_configured'] = true;
$GLOBALS['graph_result'] = ['code' => 202, 'body' => ['id' => 'g-1']];

$sent = jeab_system_mail_send(['to' => 'inte', 'subject' => 'Hej', 'text' => 'Text']);
fm_assert($sent['ok'] === false, 'bad address fails before transport');

$GLOBALS['hs_mailbox_malmo'] = 0;
$GLOBALS['hs_calls'] = 0;
$GLOBALS['hs_result'] = ['ok' => false, 'error' => 'ska inte anropas'];
$GLOBALS['graph_calls'] = [];
$GLOBALS['graph_result'] = ['code' => 202, 'body' => []];
$sent = jeab_system_mail_send([
    'to' => 'kund@example.com',
    'subject' => 'Bokad tid',
    'text' => 'Vi kommer.',
    'company' => 'malmo',
    'tags' => ['kalender'],
]);
fm_assert($sent['ok'] === true && $sent['path'] === 'graph' && $sent['id'] === 'graph', 'Malmö without a mailbox falls back to Graph');
fm_assert($GLOBALS['hs_calls'] === 0, 'Malmö without a mailbox skips Help Scout');
fm_assert(count($GLOBALS['graph_calls']) === 1, 'Malmö sends once');
fm_assert(str_contains($GLOBALS['graph_calls'][0]['path'], 'kontakt%40malmo.example.test'), 'Malmö mailbox is kontakt@malmo.example.test');
fm_assert(($GLOBALS['graph_calls'][0]['body']['message']['from']['emailAddress']['name'] ?? '') === 'Demo Energi Malmö', 'Malmö display name');
fm_assert(($GLOBALS['graph_calls'][0]['body']['message']['replyTo'][0]['emailAddress']['address'] ?? '') === 'kontakt@malmo.example.test', 'Malmö Reply-To');

$GLOBALS['hs_mailbox_malmo'] = 88001;
$GLOBALS['hs_result'] = ['ok' => true, 'error' => null];
$GLOBALS['hs_calls'] = 0;
$GLOBALS['graph_calls'] = [];
$sent = jeab_system_mail_send([
    'to' => 'kund@example.com',
    'subject' => 'Bokad tid',
    'text' => 'Vi kommer.',
    'company' => 'malmo',
]);
fm_assert($sent['ok'] === true && $sent['path'] === 'helpscout', 'configured MEAB mailbox wins');
fm_assert($GLOBALS['hs_calls'] === 1 && ($GLOBALS['hs_last']['company'] ?? '') === 'malmo', 'Help Scout gets company malmo');
fm_assert($GLOBALS['graph_calls'] === [], 'Graph stays idle when Help Scout accepts');

$GLOBALS['company_live'] = true;
$GLOBALS['hs_result'] = ['ok' => false, 'error' => 'Help Scout används inte längre; mejlet går via Inkorgen.'];
$GLOBALS['outbound_result'] = ['ok' => true, 'path' => 'inkorg', 'error' => null, 'id' => 'case-9'];
$GLOBALS['outbound_calls'] = 0;
$GLOBALS['graph_calls'] = [];
$sent = jeab_system_mail_send([
    'to' => 'kund@example.com',
    'subject' => 'Bokad tid',
    'text' => 'Vi kommer.',
    'company' => 'malmo',
]);
fm_assert($sent['ok'] === true && $sent['path'] === 'inkorg' && $sent['id'] === 'case-9', 'live company uses Inkorgen after Help Scout refuses');
fm_assert($GLOBALS['outbound_calls'] === 1 && $GLOBALS['graph_calls'] === [], 'Inkorgen sends once and raw Graph does not');
$GLOBALS['company_live'] = false;

$GLOBALS['hs_result'] = ['ok' => false, 'error' => 'HTTP 401'];
$GLOBALS['graph_calls'] = [];
$GLOBALS['graph_result'] = ['code' => 202, 'body' => ['id' => 'g-jeab']];
$sent = jeab_system_mail_send([
    'to' => 'kund@example.com',
    'subject' => 'Bokad tid',
    'text' => 'Vi kommer.',
]);
fm_assert($sent['ok'] === true && $sent['path'] === 'graph' && $sent['id'] === 'g-jeab', 'omitted company stays on the JEAB mailbox');
fm_assert(($GLOBALS['graph_calls'][0]['body']['message']['from']['emailAddress']['address'] ?? '') === 'kontakt@jarfalla.example.test', 'JEAB From unchanged');
fm_assert(($GLOBALS['graph_calls'][0]['body']['message']['from']['emailAddress']['name'] ?? '') === 'Demo Energi Järfälla', 'JEAB display name unchanged');
fm_assert(($GLOBALS['hs_last']['company'] ?? '') === 'jarfalla', 'JEAB Help Scout company');

fm_assert(jeab_system_mail_sender_key('Contact@malmo-demo.se') === 'kontakt@malmo.example.test', 'contact@malmo normaliseras');
fm_assert(jeab_system_mail_sender_key('Demo Energi Malmö <contact@malmo.example.test>') === 'kontakt@malmo.example.test', 'namn och <> tas bort');
fm_assert(jeab_system_mail_sender_key('contact@jarfalla.example.test') === 'kontakt@jarfalla.example.test', 'contact@jarfalla normaliseras');
fm_assert(jeab_system_mail_sender_key('demo@malmo.example.test') === 'demo@malmo.example.test', 'personlig adress lämnas');
fm_assert(jeab_system_mail_sender_key('contact@example.com') === 'contact@example.com', 'främmande domän lämnas');
fm_assert(jeab_system_mail_is_company_sender('malmo', 'contact@malmo.example.test'), 'contact@ är Malmös avsändare');
fm_assert(jeab_system_mail_is_company_sender('meab', 'KONTAKT@malmo.example.test'), 'kontakt@ är Malmös avsändare');
fm_assert(!jeab_system_mail_is_company_sender('malmo', 'contact@jarfalla.example.test'), 'Järfälla-adress är inte Malmös');
fm_assert(jeab_system_mail_is_company_sender('jarfalla', 'contact@jarfalla.example.test'), 'contact@ är Järfällas avsändare');
fm_assert(!jeab_system_mail_is_company_sender('jarfalla', 'demo@example.test'), 'personlig adress är inte bolagets');

$GLOBALS['hs_mailbox_malmo'] = 372709;
$GLOBALS['hs_result'] = ['ok' => true, 'error' => null];
$GLOBALS['hs_calls'] = 0;
$GLOBALS['graph_calls'] = [];
$GLOBALS['logged'] = [];
$sent = jeab_system_mail_send([
    'to' => 'kund@example.com',
    'subject' => 'Bokad tid för installation av laddbox',
    'text' => 'Vi kommer.',
    'company' => 'malmo',
    'from_email' => 'contact@malmo.example.test',
    'from_name' => 'Demo Energi Malmö',
]);
fm_assert($sent['ok'] === true && $sent['path'] === 'helpscout', 'Malmö contact@ går via Help Scout');
fm_assert($GLOBALS['hs_calls'] === 1 && ($GLOBALS['hs_last']['company'] ?? '') === 'malmo', 'Help Scout Malmö anropas en gång');
fm_assert($GLOBALS['graph_calls'] === [], 'Graph rörs inte när Help Scout tar emot');
$r = jeab_system_mail_hs_route(['company' => 'malmo', 'from_email' => 'contact@malmo.example.test']);
fm_assert($r['try'] === true, 'route: contact@ Malmö → Help Scout');

$GLOBALS['hs_calls'] = 0;
$sent = jeab_system_mail_send(['to' => 'kund@example.com', 'subject' => 'X', 'text' => 'Y', 'from_email' => 'contact@jarfalla.example.test']);
fm_assert($sent['path'] === 'helpscout' && $GLOBALS['hs_calls'] === 1, 'Järfälla contact@ går via Help Scout');

$GLOBALS['hs_result'] = ['ok' => false, 'error' => 'HTTP 500'];
$GLOBALS['hs_calls'] = 0;
$GLOBALS['graph_calls'] = [];
$GLOBALS['graph_result'] = ['code' => 202, 'body' => ['id' => 'g-meab']];
$sent = jeab_system_mail_send(['to' => 'kund@example.com', 'subject' => 'X', 'text' => 'Y', 'company' => 'malmo', 'from_email' => 'contact@malmo.example.test']);
fm_assert($GLOBALS['hs_calls'] === 1, 'Help Scout först');
fm_assert(count($GLOBALS['graph_calls']) === 1, 'Graph en gång efter Help Scout-fel');
fm_assert(($GLOBALS['graph_calls'][0]['body']['message']['replyTo'][0]['emailAddress']['address'] ?? '') === 'contact@malmo.example.test', 'Graph-reserven behåller Reply-To');
fm_assert(($GLOBALS['graph_calls'][0]['body']['message']['from']['emailAddress']['address'] ?? '') === 'kontakt@malmo.example.test', 'From är brevlådan');

$r = jeab_system_mail_hs_route(['company' => 'malmo', 'from_email' => 'demo@malmo.example.test']);
fm_assert($r['try'] === false, 'personlig From går inte via Help Scout');
$r = jeab_system_mail_hs_route(['company' => 'malmo', 'from_email' => 'contact@jarfalla.example.test']);
fm_assert($r['try'] === false, 'fel bolags From går inte via Help Scout');
unset($GLOBALS['hs_mailbox_malmo']);
$r = jeab_system_mail_hs_route(['company' => 'malmo', 'from_email' => 'contact@malmo.example.test']);
fm_assert($r['try'] === true, 'Malmö går via Inkorgen utan Help Scout-brevlåda');
fm_assert(jeab_system_mail_mask('anna.berg@example.com') === 'an***@example.com', 'mask visar inte hela adressen');

$GLOBALS['company_live'] = false;
$GLOBALS['graph_configured'] = true;
$GLOBALS['hs_mailbox_malmo'] = 88001;
$GLOBALS['hs_result'] = ['ok' => true, 'error' => null, 'id' => 'hs-created-not-sent'];
$GLOBALS['hs_calls'] = 0;
$GLOBALS['graph_calls'] = [];
$GLOBALS['graph_result'] = ['code' => 202, 'body' => ['id' => 'g-malmo-deliver']];
$sent = jeab_system_mail_send([
    'to' => 'demo.person@example.invalid',
    'subject' => 'Ny tid',
    'text' => 'Vi har flyttat tiden.',
    'company' => 'malmo',
    'from_email' => 'kontakt@malmo.example.test',
    'from_name' => 'Demo Energi Malmö',
    'deliver_direct' => 1,
]);
fm_assert($GLOBALS['hs_calls'] === 0, 'Malmö deliver_to skapar inget Help Scout-samtal');
fm_assert($sent['ok'] === true && $sent['path'] === 'graph' && $sent['id'] === 'g-malmo-deliver', 'Malmö deliver_to tar Graph-svaret');
fm_assert(($sent['provider']['code'] ?? 0) === 202 && ($sent['provider']['ok'] ?? false) === true, 'provider-koden är Graph 202');
fm_assert(($sent['provider']['from'] ?? '') === 'kontakt@malmo.example.test' && ($sent['provider']['to'] ?? '') === 'demo.person@example.invalid', 'provider visar avsändare och mottagare');
fm_assert(count($GLOBALS['graph_calls']) === 1 && str_contains($GLOBALS['graph_calls'][0]['path'], 'kontakt%40malmo.example.test/sendMail'), 'sendMail går från kontakt@malmo.example.test');
fm_assert(($GLOBALS['graph_calls'][0]['body']['message']['toRecipients'][0]['emailAddress']['address'] ?? '') === 'demo.person@example.invalid', 'Graph får deliver_to');
$GLOBALS['graph_calls'] = [];
$GLOBALS['graph_result'] = ['code' => 403, 'body' => []];
$denied = jeab_system_mail_send([
    'to' => 'demo.person@example.invalid',
    'subject' => 'Ny tid',
    'text' => 'Vi har flyttat tiden.',
    'company' => 'malmo',
    'from_email' => 'kontakt@malmo.example.test',
    'deliver_direct' => 1,
]);
fm_assert($denied['ok'] === false && $GLOBALS['hs_calls'] === 0 && ($denied['provider']['code'] ?? 0) === 403, 'Graph-fel blir inte ett Help Scout-samtal');
fm_assert(count($GLOBALS['graph_calls']) === 1, 'ett avvisat sendMail skickas inte om');

$search = (string) file_get_contents(dirname(__DIR__) . '/src/inkorg_auto.php');
fm_assert(str_contains($search, 'function jeab_auto_quote_sent_graph_search'), 'Skickat-sökningen finns i Graph');
fm_assert(!str_contains(strtolower($search), 'nylas'), 'Skickat-sökningen går inte via Nylas');

echo "system mail: ok\n";
