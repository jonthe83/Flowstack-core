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

function fullmakt_copy(array $doc, string $key): string
{
    return $key === 'invite_subject' ? 'Fullmakt att signera' : "Hej {namn}\nLänk: {url}\n";
}

require dirname(__DIR__) . '/src/system_mail.php';
require dirname(__DIR__) . '/fullmakt/lib/mailer.php';

function fi_reset(): void
{
    $GLOBALS['graph_configured'] = true;
    $GLOBALS['company_live'] = false;
    $GLOBALS['hs_calls'] = 0;
    $GLOBALS['hs_result'] = ['ok' => true, 'error' => null, 'id' => 'hs-1'];
    $GLOBALS['graph_calls'] = [];
    $GLOBALS['graph_result'] = ['code' => 202, 'body' => ['id' => 'g-9']];
    $GLOBALS['outbound_calls'] = 0;
    $GLOBALS['logged'] = [];
}

// 1. Inbjudan går till kontaktens mejl via Graph, aldrig Help Scout (även när HS skulle svara ok).
fi_reset();
$doc = ['type' => 'ellevio', 'invite_epost' => 'kund@example.com', 'invite_namn' => 'Anna'];
$ok = fullmakt_send_invite($doc, 'https://flowstack.example.test/fullmakt/s/abc');
fm_assert($ok === true, 'inbjudan skickas');
fm_assert($GLOBALS['hs_calls'] === 0, 'Help Scout anropas inte för e-signering');
fm_assert(count($GLOBALS['graph_calls']) === 1, 'Graph skickar inbjudan en gång');
$to = $GLOBALS['graph_calls'][0]['body']['message']['toRecipients'][0]['emailAddress']['address'] ?? '';
fm_assert($to === 'kund@example.com', 'mottagare är kontaktens mejl');
fm_assert(fullmakt_mail_last_path() === 'graph', 'vägen är graph, inte helpscout');

// 2. Live-bolag: inkorgens utskick (Graph) används, inte Help Scout.
fi_reset();
$GLOBALS['company_live'] = true;
$GLOBALS['outbound_result'] = ['ok' => true, 'path' => 'inkorg', 'error' => null, 'id' => 'ink-1'];
fm_assert(fullmakt_send_invite($doc, 'https://flowstack.example.test/fullmakt/s/abc') === true, 'inbjudan via inkorgen');
fm_assert($GLOBALS['hs_calls'] === 0 && $GLOBALS['outbound_calls'] === 1, 'inkorgen, inte Help Scout');
unset($GLOBALS['outbound_result']);

// 3. Graph fel: ingen tyst Help Scout-reserv, tydligt fel.
fi_reset();
$GLOBALS['graph_result'] = ['code' => 500, 'body' => []];
fm_assert(fullmakt_send_invite($doc, 'https://flowstack.example.test/fullmakt/s/abc') === false, 'Graph-fel ger false');
fm_assert($GLOBALS['hs_calls'] === 0, 'ingen Help Scout-reserv vid Graph-fel');
fm_assert(!str_contains(fullmakt_mail_last_error(), 'Help Scout'), 'felet nämner inte Help Scout');
fm_assert(fullmakt_mail_last_error() !== '', 'felet är ifyllt');

// 4. Kontakten saknar mejl: tydligt fel, inget skickas alls.
fi_reset();
fm_assert(fullmakt_send_invite(['type' => 'ellevio', 'invite_namn' => 'Anna'], 'https://x/s/a') === false, 'utan mejl: false');
fm_assert(fullmakt_mail_last_error() === fullmakt_invite_missing_email_text(), 'utan mejl: tydligt fel');
fm_assert(str_contains(fullmakt_mail_last_error(), 'Kontaktens e-post saknas'), 'felet säger att kontakten saknar e-post');
fm_assert($GLOBALS['hs_calls'] === 0 && $GLOBALS['graph_calls'] === [], 'utan mejl: inget utskick');

// 5. Vanliga systemmejl är oförändrade (Help Scout först).
fi_reset();
$sent = jeab_system_mail_send(['to' => 'kund@example.com', 'subject' => 'Bokad tid', 'text' => 'Hej']);
fm_assert($sent['path'] === 'helpscout' && $GLOBALS['hs_calls'] === 1, 'övriga systemmejl går som förut');

// 6. SEOM-blanketten till kunden går också utan Help Scout.
$seom = (string) file_get_contents(dirname(__DIR__) . '/src/seom_fullmakt.php');
fm_assert(str_contains($seom, "'skip_helpscout' => true"), 'SEOM-fullmakt hoppar över Help Scout');

echo "ALLA OK\n";
