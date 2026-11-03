<?php

declare(strict_types=1);

/**
 * Help Scout-adresser ska inte sparas i en påminnelse.
 * HS-numret läses först och ligger kvar som HS#.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

function hs_link_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

date_default_timezone_set('Europe/Stockholm');

$bare = 'https://secure.helpscout.net/conversation/24563/12345';
hs_link_assert(jeab_reminder_strip_hs_links($bare) === '', 'https secure.helpscout.net tas bort');
hs_link_assert(
    jeab_reminder_strip_hs_links('http://helpscout.net/conversation/24563') === '',
    'http helpscout.net tas bort'
);
hs_link_assert(
    jeab_reminder_strip_hs_links('https://www.secure.helpscout.net/conversation/9/1?x=1#frag') === '',
    'www, query och fragment tas bort'
);
hs_link_assert(
    jeab_reminder_strip_hs_links('//secure.helpscout.net/conversation/3/4') === '',
    'protokollös adress tas bort'
);
hs_link_assert(
    jeab_reminder_strip_hs_links('secure.helpscout.net/conversation/3/4') === '',
    'adress utan schema tas bort'
);
hs_link_assert(
    jeab_reminder_strip_hs_links('Ring imorgon | HS: https://secure.helpscout.net/conversation/24563/1') === 'Ring imorgon',
    'avskiljaren | HS: och adressen tas bort, övrig text står kvar'
);
hs_link_assert(
    jeab_reminder_strip_hs_links("HS: https://secure.helpscout.net/conversation/24563/1\noch sedan ring") === 'och sedan ring',
    'ledande HS: tas bort och raden under står kvar'
);
hs_link_assert(
    jeab_reminder_strip_hs_links('HS#24563 finns kvar https://secure.helpscout.net/conversation/24563/1') === 'HS#24563 finns kvar',
    'HS# står kvar när adressen tas bort'
);
hs_link_assert(
    jeab_reminder_strip_hs_links('Se https://example.com/helpscout.net tack') === 'Se https://example.com/helpscout.net tack',
    'en annan sajt med texten helpscout.net står kvar'
);
hs_link_assert(
    jeab_reminder_strip_hs_links('Skriv till kund@helpscout.net imorgon') === 'Skriv till kund@helpscout.net imorgon',
    'en e-postadress till helpscout.net står kvar'
);
hs_link_assert(
    jeab_reminder_strip_hs_links('HS: Ring kunden') === 'Ring kunden',
    'ledande HS: utan adress tas bort'
);
$once = jeab_reminder_strip_hs_links('Ring | HS: https://secure.helpscout.net/conversation/11/2 och HS#11');
hs_link_assert(jeab_reminder_strip_hs_links($once) === $once, 'strip är idempotent');
hs_link_assert(!str_contains($once, 'helpscout.net') && str_contains($once, 'Ring') && str_contains($once, 'HS#11'), 'idempotent resultat behåller texten');

$path = sys_get_temp_dir() . '/jeab-hs-strip-' . getmypid() . '.json';
@unlink($path);
$store = new JeabJsonStore($path);

$created = jeab_reminder_create_action($store, [
    'title' => 'Ring Test',
    'name' => 'Ring Test',
    'comment' => 'Ring imorgon | HS: https://secure.helpscout.net/conversation/24563/9',
    'bolag' => 'JEAB',
]);
$createdNote = (string) ($store->getReminder((int) $created['id'])['note'] ?? '');
hs_link_assert(!str_contains($createdNote, 'helpscout.net'), 'create sparar inte Help Scout-adressen');
hs_link_assert(str_contains($createdNote, 'Ring imorgon'), 'create behåller övrig text');
hs_link_assert(str_contains($createdNote, 'HS#24563'), 'create sparar HS-numret från adressen');

$again = jeab_reminder_create_action($store, [
    'title' => 'Ring Test',
    'name' => 'Ring Test',
    'comment' => "https://secure.helpscout.net/conversation/24563/9\nandra raden",
    'bolag' => 'JEAB',
]);
hs_link_assert((int) $again['id'] === (int) $created['id'], 'samma Help Scout-nummer återanvänder raden');
$merged = (string) ($store->getReminder((int) $created['id'])['note'] ?? '');
hs_link_assert(!str_contains($merged, 'helpscout.net') && str_contains($merged, 'andra raden') && str_contains($merged, 'HS#24563'), 'hopslagen anteckning saknar adressen');

$parsed = jeab_parse_incoming([
    'type' => 'reminder',
    'title' => 'Hook Test',
    'notes' => 'Hej https://helpscout.net/conversation/111/2',
]);
$hookRow = jeab_create_reminder($store, $parsed['title'], $parsed['due_at'], $parsed['note'], null, $parsed);
$hookNote = (string) ($store->getReminder((int) $hookRow['id'])['note'] ?? '');
hs_link_assert(!str_contains($hookNote, 'helpscout.net') && str_contains($hookNote, 'Hej') && str_contains($hookNote, 'HS#111'), 'hook-create sparar text och HS# utan adress');

$updated = jeab_update_reminder($store, (int) $hookRow['id'], [
    'note' => 'Kvar text https://www.helpscout.net/conversation/333/1',
]);
$updatedNote = (string) ($updated['note'] ?? '');
hs_link_assert(!str_contains($updatedNote, 'helpscout.net') && str_contains($updatedNote, 'Kvar text') && str_contains($updatedNote, 'HS#333'), 'update sparar text och HS# utan adress');

$long = str_repeat('Å', 70) . ' https://secure.helpscout.net/conversation/444/1 och mer text som ska klippas';
$raw = $store->insertReminder([
    'title' => 'Rå rad',
    'customer_name' => 'Rå Test',
    'note' => $long,
    'company' => 'jarfalla',
    'due_at' => '2026-10-19 00:00:00',
    'created_at' => '2026-10-19 00:00:00',
    'updated_at' => '2026-10-19 00:00:00',
]);
$clean = $store->insertReminder([
    'title' => 'Ren rad',
    'customer_name' => 'Ren Test',
    'note' => 'Bara en anteckning',
    'company' => 'jarfalla',
    'due_at' => '2026-10-19 00:00:00',
    'created_at' => '2026-10-19 00:00:00',
    'updated_at' => '2026-10-19 00:00:00',
]);
$beforeDisk = (string) file_get_contents($path);
$dry = jeab_reminders_strip_hs_links_run($store, []);
hs_link_assert((int) ($dry['dry'] ?? 0) === 1, 'dry saknas betyder dry=1');
hs_link_assert((int) ($dry['updated'] ?? 1) === 0, 'dry=1 skriver inget');
hs_link_assert((string) file_get_contents($path) === $beforeDisk, 'dry=1 lämnar filen orörd');
hs_link_assert($dry['ids'] === [(int) $raw['id']], 'bara raden med Help Scout-adress listas');
$preview = $dry['preview'][0] ?? [];
hs_link_assert((int) ($preview['id'] ?? 0) === (int) $raw['id'], 'förhandsvisningen pekar på samma rad');
hs_link_assert(mb_strlen((string) ($preview['before'] ?? '')) <= 80, 'before är högst 80 tecken');
hs_link_assert(mb_strlen((string) ($preview['after'] ?? '')) <= 80, 'after är högst 80 tecken');
hs_link_assert(str_contains((string) ($preview['before'] ?? ''), 'helpscout.net') === false || mb_strlen((string) $preview['before']) === 80, 'before är antingen kort eller avklippt');
hs_link_assert(!str_contains((string) ($preview['after'] ?? ''), 'helpscout.net'), 'after visar texten utan Help Scout-adress');
hs_link_assert(str_starts_with((string) ($preview['after'] ?? ''), str_repeat('Å', 70)), 'after behåller texten före adressen');
hs_link_assert(str_contains((string) ($store->getReminder((int) $raw['id'])['note'] ?? ''), 'helpscout.net'), 'raden är oförändrad efter dry=1');
hs_link_assert((string) ($store->getReminder((int) $clean['id'])['note'] ?? '') === 'Bara en anteckning', 'en ren anteckning rörs inte');

$denied = jeab_reminders_strip_hs_links_dispatch($store, ['dry' => 0]);
hs_link_assert(($denied['ok'] ?? true) === false && (int) ($denied['status'] ?? 0) === 401, 'hook utan token skriver inte');
hs_link_assert(str_contains((string) ($store->getReminder((int) $raw['id'])['note'] ?? ''), 'helpscout.net'), 'nekad hook lämnar anteckningen');

$live = jeab_reminders_strip_hs_links_run($store, ['dry' => 0]);
hs_link_assert((int) ($live['dry'] ?? 1) === 0 && (int) ($live['updated'] ?? 0) === 1, 'dry=0 skriver om den enda träffen');
$rewritten = (string) ($store->getReminder((int) $raw['id'])['note'] ?? '');
hs_link_assert(!str_contains($rewritten, 'helpscout.net') && str_contains($rewritten, 'HS#444'), 'dry=0 använder samma städning');
$againLive = jeab_reminders_strip_hs_links_run($store, ['dry' => '0']);
hs_link_assert((int) ($againLive['updated'] ?? 1) === 0 && ($againLive['ids'] ?? [1]) === [], 'en andra körning hittar inget och skriver inget');
hs_link_assert((string) ($store->getReminder((int) $raw['id'])['note'] ?? '') === $rewritten, 'andra körningen lämnar anteckningen');

$hookSrc = (string) file_get_contents(dirname(__DIR__) . '/hook.php');
hs_link_assert(str_contains($hookSrc, "'reminders.strip_hs_links'"), 'hook.php tar emot reminders.strip_hs_links');

@unlink($path);
echo "reminder strip hs links: all passed\n";
