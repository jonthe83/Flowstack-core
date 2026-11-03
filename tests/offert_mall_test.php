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

function jeab_normalize_person_email(?string $email): ?string
{
    $email = strtolower(trim((string) $email));
    if ($email === '' || !str_contains($email, '@')) {
        return null;
    }
    return $email;
}

require dirname(__DIR__) . '/src/offert_mall.php';

function offert_opening(string $html): string
{
    if (!preg_match_all('/<div[^>]*>(.*?)<\\/div>/s', $html, $matches)) {
        return '';
    }
    foreach ($matches[1] as $inner) {
        $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '' || str_starts_with($text, 'Hej')) {
            continue;
        }
        return $text;
    }
    return '';
}

$kopt = jeab_offert_mall_body('kopt', 'Hej Anna,');
$eget = jeab_offert_mall_body('eget', 'Hej Anna,');
$koptOpen = 'Du har visat intresse för att installera solceller med oss!';
$egetOpen = 'För att välja rätt antal solceller så utgår jag ifrån hur mycket el ni köper i maj eller juni. Låt oss säga 800 kWh. Det brukar motsvara ca 12 st paneler. Men om ni i framtiden konsumerar mer el eller det är molnigt en dag så har ni för få paneler. Det kostar ungefär 20 000 kr att lägga till 10 st paneler. Om platsen finns på taket och utrymmet finns i er ekonomi så är det en god idé att ha en något högre elproduktion på sommaren än vad ni förbrukar.';
$neverSent = 'Vi fick din förfrågan att du vill ha in flera offerter på solceller.';

fm_assert(offert_opening($kopt) === $koptOpen, 'köpt inledning är det skickade mejlet');
fm_assert(!str_contains($kopt, $neverSent), 'aldrig skickad köpt-text är borta');
fm_assert(str_contains($kopt, 'satelitbild'), 'köpt behåller stavningen satelitbild');
fm_assert(str_contains($kopt, 'Så jag skippar säljsnacket =)'), 'köpt har säljsnacket');
fm_assert(str_contains($kopt, 'Bifogar en prislista/offert'), 'köpt bifogar prislista/offert');
fm_assert(str_contains($kopt, 'https://flowstack.example.test/assets/img/medal-solpanel.png'), 'köpt-medaljen är /assets/img/');
fm_assert(!str_contains($kopt, 'https://flowstack.example.test/signatur/medal-solpanel.png'), 'köpt använder inte signatur-medaljen');
fm_assert(offert_opening($eget) === $egetOpen, 'egen inledning');
fm_assert(str_contains($eget, 'satelitbild'), 'egen behåller stavningen satelitbild');
fm_assert(str_contains($eget, '1. Du svarar på detta mail eller ringer mig'), 'egen lead är numrerad');
fm_assert(str_contains($eget, 'https://flowstack.example.test/assets/img/medal-solpanel.png'), 'egen medalj är /assets/img/');
fm_assert(!str_contains($eget, 'https://flowstack.example.test/signatur/medal-solpanel.png'), 'egen lead använder inte signatur-medaljen');
fm_assert(!str_contains($eget, 'Du har visat intresse'), 'egen lead har inte köpt-meningen');
fm_assert(str_contains($kopt, '>Elektriker</div>'), 'köpt signatur Elektriker');
fm_assert(str_contains($eget, '>Elektriker</div>'), 'egen signatur Elektriker');

$hitta = jeab_offert_template([
    'name' => 'Anna Nord',
    'source' => 'Hitta-solceller',
]);
fm_assert($hitta['variant'] === 'kopt', 'saknad variant och Hitta-solceller blir köpt');
fm_assert($hitta['subject'] === 'Solceller med batteri', 'ämne oförändrat');
fm_assert(offert_opening($hitta['body']) === $koptOpen, 'mallen använder köpt inledning');
fm_assert(str_contains($hitta['body'], '>Elektriker</div>'), 'mallens signatur är Elektriker');
fm_assert(str_contains($hitta['body'], 'Hej Anna,'), 'hälsningen är förnamnet');
$afterSig = (string) substr($hitta['body'], (int) strpos($hitta['body'], '>Elektriker</div>'));
fm_assert(!str_contains($afterSig, 'Anna Nord'), 'hela namnet ligger inte efter signaturen');

$egetVariant = jeab_offert_template([
    'variant' => 'eget',
    'source' => 'hitta solceller',
]);
fm_assert($egetVariant['variant'] === 'kopt', 'variant eget med Hitta-solceller blir köpt');
fm_assert(offert_opening($egetVariant['body']) === $koptOpen, 'eget+Hitta får köpt inledning');

fm_assert(jeab_offert_variant('', 'HITTA-SOLCELLER') === 'kopt', 'versaler');
fm_assert(jeab_offert_variant('eget', 'Hitta-Solceller.se') === 'kopt', 'Hitta-Solceller.se');
fm_assert(jeab_offert_lock_hitta_variant(['variant' => 'eget', 'källa' => 'Hitta-solceller'])['variant'] === 'kopt', 'källa låses');

$own = jeab_offert_template(['variant' => 'eget', 'source' => '']);
fm_assert($own['variant'] === 'eget', 'eget utan köpt källa');
fm_assert(offert_opening($own['body']) === $egetOpen, 'egen lead behåller tack-meningen');
fm_assert($own['subject'] === 'Solceller med batteri', 'eget ämne');

$missing = jeab_offert_template(['name' => 'Egen Kund']);
fm_assert($missing['variant'] === 'eget', 'saknad variant utan köpt källa är eget');
fm_assert(jeab_offert_variant(null, null) === 'eget', 'tom variant är eget');
fm_assert(jeab_offert_variant('eget', 'hitta') === 'eget', 'bara hitta räcker inte');
fm_assert(jeab_offert_variant('', 'hittade solceller') === 'eget', 'hittade solceller är inte källan');
fm_assert(jeab_offert_variant('', 'Hemsol') === 'eget', 'okänd källa gissas inte till köpt');
fm_assert(jeab_offert_lock_hitta_variant(['variant' => 'eget', 'source' => 'JEAB Solceller lead'])['variant'] === 'eget', 'eget lead låses inte');

echo "offert mall: ok\n";
