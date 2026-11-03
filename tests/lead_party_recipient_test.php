<?php

declare(strict_types=1);

/** Offertmottagare: leadtext eller inbox_lead_party, aldrig kontaktens mejl. Påhittade adresser. */

$tmp = sys_get_temp_dir() . '/jeab-lead-party-' . bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
putenv('XAI_API_KEY');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $tmp . '/app.sqlite', 'json_path' => $tmp . '/store.json'],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function lp(bool $ok, string $m): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL {$m}\n");
        exit(1);
    }
    echo "OK  {$m}\n";
}

$GLOBALS['_JEAB_CONTACT_GET'] = static fn (): array => ['id' => 'k-1', 'email' => 'kontakt.annan@exempel.se'];
$msg = ['from' => 'hej@nordsolen.se', 'body' => "Vill ha batteri.\n", 'company' => 'jarfalla'];
$thread = ['id' => 'lp-1', 'contact_id' => 'k-1', 'lead_source' => 'nordsolen', 'customer_email' => ''];

lp(jeab_lead_sql() instanceof PDO, 'SQL finns');
lp(jeab_lead_party_get('lp-1') === [], 'ingen sparad mottagare än');
$r = jeab_lead_send_recipient($msg, $thread);
lp((string) ($r['email'] ?? '') === '' && !empty($r['lead']), 'utan leadtext och kopia blir mottagaren tom (offerten hålls)');
lp((string) ($r['email'] ?? '') !== 'kontakt.annan@exempel.se', 'kontaktens mejl används aldrig');

jeab_lead_party_remember('lp-1', 'party.kund@exempel.se', 'Exempel', '', 'nordsolen');
lp((string) (jeab_lead_party_get('lp-1')['email'] ?? '') === 'party.kund@exempel.se', 'sparad mottagare läses');
$r = jeab_lead_send_recipient($msg, $thread);
lp((string) ($r['email'] ?? '') === 'party.kund@exempel.se', 'mottagaren tas från inbox_lead_party när tråden saknar kopia');

$withText = $msg + [];
$withText['body'] = "Kund: Exempel Person · text.kund@exempel.se · 070-000 00 99\nVill ha batteri.\n";
$r = jeab_lead_send_recipient($withText, $thread);
lp((string) ($r['email'] ?? '') === 'text.kund@exempel.se', 'leadtexten går före den sparade kopian');

// Länkad tråd under hold: kontaktens mejl skiljer sig från leadets. Mottagaren är leadets.
$GLOBALS['_JEAB_CONTACTS_STRICT'] = true;
$GLOBALS['_JEAB_CONTACTS_SOLE'] = true;
lp(jeab_contacts_inbox_hold(), 'hold är på');
$store = new JeabPdoInkorgStore(jeab_lead_sql());
$ref = new ReflectionClass($store);
$apply = $ref->getMethod('applyListColumns');
$apply->setAccessible(true);
$listed = $apply->invoke($store, ['id' => 'lp-1', 'contact_id' => 'k-1', 'lead_source' => 'nordsolen'], ['id' => 'lp-1', 'customer_email' => '', 'contact_id' => 'k-1']);
lp(!array_key_exists('display_email', $listed) && !str_contains((string) json_encode($listed), 'kontakt.annan'), 'kontaktens mejl läses inte in i tråden');
lp(trim((string) ($listed['customer_email'] ?? '')) === '', 'kontaktens mejl fyller inte customer_email');
$r = jeab_lead_send_recipient($msg, $listed);
lp((string) ($r['email'] ?? '') === 'party.kund@exempel.se', 'länkad tråd: mottagaren är leadets mejl, inte kontaktens');
$stale = $listed;
$stale['customer_email'] = 'gammal.kopia@exempel.se';
$r = jeab_lead_send_recipient($msg, $stale);
lp((string) ($r['email'] ?? '') === 'party.kund@exempel.se', 'länkad tråd under hold: inbox_lead_party går före trådens customer_email');
$none = ['id' => 'lp-2', 'contact_id' => 'k-1', 'lead_source' => 'nordsolen', 'customer_email' => '', 'messages' => []];
$r = jeab_lead_send_recipient($msg, $none);
lp((string) ($r['email'] ?? '') === '' && !empty($r['lead']), 'varken leadmejl eller kopia: tom mottagare, offerten hålls');
unset($GLOBALS['_JEAB_CONTACTS_STRICT'], $GLOBALS['_JEAB_CONTACTS_SOLE']);

lp(jeab_lead_party_get('') === [], 'tomt tråd-id ger tom rad');
echo "klart\n";
