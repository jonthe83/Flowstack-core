<?php

declare(strict_types=1);

/**
 * Adressutvinning per bolag. Påhittade personer. Inga nätverksanrop.
 */

$tmp = sys_get_temp_dir() . '/jeab-address-extract-' . bin2hex(random_bytes(4));
mkdir($tmp . '/inkorg', 0775, true);
mkdir($tmp . '/secrets', 0775, true);
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_PUBLIC_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_ORDRAR_EXTERNAL_LIVE'] = false;
$GLOBALS['_JEAB_ADDRESS_EXTRACT_NETWORK'] = false;
$GLOBALS['_JEAB_ADDRESS_QUOTA_DAY'] = '2026-10-20';
putenv('XAI_API_KEY');
putenv('JEAB_DISABLE_SQLITE=0');
file_put_contents($tmp . '/config.php', "<?php\nreturn " . var_export([
    'timezone' => 'Europe/Stockholm',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => $tmp . '/app.sqlite',
        'json_path' => $tmp . '/store.json',
    ],
    'kontakter' => ['lookup_provider' => 'off'],
    'address_extract' => ['extra' => ['jarfalla' => ['Fiktivorten']]],
], true) . ";\n");
require dirname(__DIR__) . '/src/bootstrap.php';

function ax(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

ax(jeab_address_city_kind('jarfalla', 'Upplands Väsby') === 'inside', 'Upplands Väsby hör till JEAB');
ax(jeab_address_city_kind('jeab', 'Järfälla') === 'inside', 'Järfälla hör till JEAB');
ax(jeab_address_city_kind('jarfalla', 'Bromma') === 'inside', 'Bromma hör till Stockholm');
ax(jeab_address_city_kind('jarfalla', 'Örebro') === 'inside', 'Örebro hör till JEAB');
ax(jeab_address_city_kind('jarfalla', 'Uppsala') === 'inside', 'Uppsala hör till JEAB');
ax(jeab_address_city_kind('jarfalla', 'Västerås') === 'inside', 'Västerås hör till JEAB');
ax(jeab_address_city_kind('jarfalla', 'Eskilstuna') === 'inside', 'Eskilstuna hör till JEAB');
ax(jeab_address_city_kind('jarfalla', 'Älvsbyn') === 'outside', 'Älvsbyn ligger utanför JEAB');
ax(jeab_address_city_kind('jarfalla', 'Malmö') === 'outside', 'Malmö ligger utanför JEAB');
ax(jeab_address_city_kind('jarfalla', 'Teststad') === 'unknown', 'okänd text är inte en ort utanför området');
ax(jeab_address_city_kind('jarfalla', 'Fiktivorten') === 'inside', 'konfigurerad ort hör till JEAB');
ax(jeab_address_postal_kind('jarfalla', '194 36') === 'inside', '194 36 hör till JEAB');
ax(jeab_address_postal_kind('jarfalla', '94231') === 'outside', '942 31 ligger utanför JEAB');
ax(jeab_address_city_kind('malmo', 'Malmö') === 'inside', 'Malmö hör till MEAB');
ax(jeab_address_city_kind('meab', 'Lund') === 'inside', 'Lund hör till MEAB');
ax(jeab_address_city_kind('malmo', 'Järfälla') === 'outside', 'Järfälla ligger utanför MEAB');
ax(jeab_address_city_kind('malmo', 'Älvsbyn') === 'outside', 'Älvsbyn ligger utanför MEAB');
ax(jeab_address_postal_kind('malmo', '211 20') === 'inside', '211 20 hör till MEAB');
ax(jeab_address_postal_kind('malmo', '19436') === 'outside', '194 36 ligger utanför MEAB');
ax(!jeab_address_in_bbox('jarfalla', 21.0, 65.0), 'Norrbotten ligger utanför JEAB-rutan');
ax(jeab_address_in_bbox('jarfalla', 17.9, 59.52), 'Upplands Väsby ligger i JEAB-rutan');

$geoCalls = 0;
$grokCalls = 0;
$lmCalls = 0;
$GLOBALS['_JEAB_ADDRESS_GEOCODE'] = static function (string $street, string $company) use (&$geoCalls): array {
    $geoCalls++;
    if ($company === 'jarfalla' && str_contains($street, 'Vetegatan')) {
        return ['score' => 96, 'postal' => '19436', 'city' => 'Upplands Väsby', 'lon' => 17.91, 'lat' => 59.52];
    }
    return [];
};
$GLOBALS['_JEAB_ADDRESS_GROK'] = static function () use (&$grokCalls): array {
    $grokCalls++;
    return [];
};
$GLOBALS['_JEAB_ADDRESS_LM'] = static function () use (&$lmCalls): array {
    $lmCalls++;
    return ['matched' => false];
};

$form = jeab_address_extract([
    'company' => 'jarfalla',
    'street' => 'Vetegatan 15',
    'city' => 'Upplands Väsby',
    'postal' => '19436',
    'name' => 'Dold Person',
]);
ax($form['source'] === 'form' && $form['certain'] && $form['trace'] === ['form'], 'formulärets postnummer och ort vinner');
ax($form['postal'] === '19436' && $form['city'] === 'Upplands Väsby', 'formuläret sparar postnummer och ort');
ax($geoCalls === 0 && $grokCalls === 0 && $lmCalls === 0, 'säkert formulär anropar inte senare källor');

$geo = jeab_address_extract([
    'company' => 'jarfalla',
    'street' => 'Vetegatan 15',
    'city' => 'Älvsbyn',
    'postal' => '',
    'name' => 'Dold Person',
]);
ax($geo['source'] === 'geocode' && $geo['city'] === 'Upplands Väsby' && $geo['postal'] === '19436', 'geokod inom området ersätter Älvsbyn');
ax($geo['trace'] === ['form', 'geocode'], 'källorna är formulär sedan geokod');
ax($grokCalls === 0 && $lmCalls === 0, 'säker geokod anropar inte Grok eller LM');
ax($geo['review'] === false, 'säker geokod behöver inte granskas');

$geoCalls = 0;
$grokCalls = 0;
$GLOBALS['_JEAB_ADDRESS_GEOCODE'] = static function () use (&$geoCalls): array {
    $geoCalls++;
    return ['score' => 40, 'postal' => '19436', 'city' => 'Upplands Väsby', 'lon' => 17.91, 'lat' => 59.52];
};
$GLOBALS['_JEAB_ADDRESS_GROK'] = static function () use (&$grokCalls): array {
    $grokCalls++;
    return ['postal' => '19436', 'city' => 'Upplands Väsby', 'confidence' => 0.91];
};
$web = jeab_address_extract([
    'company' => 'jarfalla',
    'street' => 'Vetegatan 15',
    'city' => '',
    'name' => 'Dold Person',
]);
ax($geoCalls === 1 && $grokCalls === 1 && $lmCalls === 0, 'osäker geokod går vidare till Grok och inte till LM');
ax($web['source'] === 'grok' && $web['postal'] === '19436' && $web['trace'] === ['form', 'geocode', 'grok'], 'Grok kommer efter geokod');

$outsidePoint = jeab_address_geocode('jarfalla', 'Vetegatan 15', '', '');
ax($outsidePoint['city'] === 'Upplands Väsby', 'geokodstubben träffar inom området');
$blocked = jeab_address_geocode('jarfalla', 'Annan gata', '', '');
$GLOBALS['_JEAB_ADDRESS_GEOCODE'] = static function (): array {
    return ['score' => 99, 'postal' => '94231', 'city' => 'Älvsbyn', 'lon' => 21.0, 'lat' => 65.68];
};
$blocked = jeab_address_geocode('jarfalla', 'Vetegatan 15', '', '');
ax($blocked['certain'] === false && $blocked['candidate'] === false, 'träff utanför området godkänns inte');

file_put_contents($tmp . '/inkorg/messages.json', json_encode(['messages' => [[
    'id' => 'm-review',
    'thread_id' => 't-review',
    'from' => 'dold@example.test',
    'labels' => ['type' => 'Ny lead', 'needs_review' => false, 'confidence' => 90],
]]], JSON_UNESCAPED_UNICODE));
$GLOBALS['_JEAB_ADDRESS_GEOCODE'] = static function (): array {
    return [];
};
$GLOBALS['_JEAB_ADDRESS_GROK'] = static function (): array {
    return [];
};
$review = jeab_address_extract([
    'company' => 'jarfalla',
    'street' => 'Vetegatan 15',
    'city' => 'Älvsbyn',
    'postal' => '94231',
    'name' => 'Dold Person',
    'thread_id' => 't-review',
]);
ax($review['review'] === true && $review['label'] === 'Behöver granskas', 'osäker träff märks Behöver granskas');
ax($review['city'] === '' && $review['postal'] === '' && $review['certain'] === false, 'osäker träff lämnar ort och postnummer tomma');
ax($review['lm_locked'] === true && $lmCalls === 0, 'LM anropas inte när kvoten inte tillåter');
$marked = jeab_inkorg_get('m-review');
ax(is_array($marked) && !empty($marked['labels']['needs_review']) && (string) ($marked['labels']['reason'] ?? '') === 'Behöver granskas', 'ärendet hamnar i Behöver granskas');

$pdo = jeab_store()->pdo();
ax($pdo instanceof PDO, 'SQL finns');
$pdo->exec('DELETE FROM address_extract_quota');
$GLOBALS['_JEAB_ADDRESS_LM_DAILY_MAX'] = 20;
$lmCalls = 0;
$GLOBALS['_JEAB_ADDRESS_GEOCODE'] = static function (): array {
    return ['score' => 40, 'postal' => '19436', 'city' => 'Upplands Väsby', 'lon' => 17.91, 'lat' => 59.52];
};
$GLOBALS['_JEAB_ADDRESS_GROK'] = static function (): array {
    return ['postal' => '19436', 'city' => 'Upplands Väsby', 'confidence' => 0.2];
};
$GLOBALS['_JEAB_ADDRESS_LM'] = static function () use (&$lmCalls): array {
    $lmCalls++;
    return ['matched' => true, 'postal' => '19436', 'city' => 'Upplands Väsby'];
};
for ($i = 1; $i <= 20; $i++) {
    $hit = jeab_address_extract([
        'company' => 'jarfalla',
        'street' => 'Vetegatan 15',
        'city' => 'Älvsbyn',
        'name' => 'Dold Person',
    ]);
    if ($i < 10 || ($i > 10 && $i < 20)) {
        ax($hit['lm_locked'] === true, 'uppslag ' . $i . ' låser LM');
    }
    if ($i === 10 || $i === 20) {
        ax($hit['source'] === 'lm' && $hit['certain'] === true && $hit['postal'] === '19436', 'uppslag ' . $i . ' får använda LM när ägaren matchar');
    }
}
ax($lmCalls === 2, 'LM används högst en gång per tio uppslag');
$quota = jeab_address_quota_read();
ax($quota['lookups'] === 20 && $quota['lm_calls'] === 2, 'räknaren ligger i SQL');

$GLOBALS['_JEAB_ADDRESS_QUOTA_DAY'] = '2026-10-21';
$GLOBALS['_JEAB_ADDRESS_LM_DAILY_MAX'] = 1;
$lmCalls = 0;
for ($i = 1; $i <= 20; $i++) {
    jeab_address_extract([
        'company' => 'jarfalla',
        'street' => 'Vetegatan 15',
        'city' => 'Älvsbyn',
        'name' => 'Dold Person',
    ]);
}
ax($lmCalls === 1, 'dagsgränsen låser LM även när kvoten skulle tillåta');
$day = jeab_address_quota_read();
ax($day['lm_calls'] === 1 && $day['lookups'] === 20, 'dagsgränsen syns i SQL');

jeab_settings_put($pdo, 'sqlite', JEAB_KONTAKTER_STORAGE_KEY, 'sql');
ax(jeab_contact_store() instanceof JeabPdoContactStore, 'kontakter skrivs via SQL');
file_put_contents($tmp . '/kontakter.json', '{"contacts":{}}');
$jsonMtime = filemtime($tmp . '/kontakter.json');
$store = jeab_contact_store();
ax($store instanceof JeabPdoContactStore, 'store är PDO');
$secretName = 'Dold Person';
$secretStreet = 'Hemliggatan 9';
$bad = jeab_kontakt_blank([
    'name' => $secretName,
    'email' => 'dold@example.test',
    'street' => 'Vetegatan 15',
    'street_key' => 'vetegatan 15',
    'postal_code' => '94231',
    'city' => 'Älvsbyn',
    'company' => 'jeab',
]);
$badId = (string) $bad['id'];
$store->upsert($badId, $bad);
$other = jeab_kontakt_blank([
    'name' => 'Annan Person',
    'email' => 'annan@example.test',
    'street' => $secretStreet,
    'street_key' => 'hemliggatan 9',
    'company' => 'jeab',
    'city' => 'Kiruna',
]);
$store->upsert((string) $other['id'], $other);
$home = jeab_kontakt_blank([
    'name' => 'Hemma Person',
    'email' => 'hemma@example.test',
    'street' => 'Torget 1',
    'city' => 'Järfälla',
    'postal_code' => '17741',
]);
$store->upsert((string) $home['id'], $home);
$malmo = jeab_kontakt_blank([
    'name' => 'Skane Person',
    'email' => 'skane@example.test',
    'street' => 'Hamnen 2',
    'city' => 'Malmö',
    'postal_code' => '21120',
]);
$malmo['company'] = 'malmo';
$store->upsert((string) $malmo['id'], $malmo);

$dry = jeab_contacts_address_recheck(['co' => 'jeab', 'dry' => 1, 'limit' => 10]);
ax(($dry['dry'] ?? 0) === 1 && ($dry['count'] ?? 0) === 2, 'dry rapporterar antal utanför området');
ax(($dry['by_ort']['Älvsbyn'] ?? 0) === 1 && ($dry['by_ort']['Kiruna'] ?? 0) === 1, 'dry fördelar på ort');
$dryText = json_encode($dry, JSON_UNESCAPED_UNICODE);
ax(is_string($dryText) && !str_contains($dryText, $secretName) && !str_contains($dryText, $secretStreet) && !str_contains($dryText, 'dold@example.test'), 'dry innehåller inga namn');
ax((string) ($store->get($badId)['city'] ?? '') === 'Älvsbyn', 'dry skriver inte kontakten');
$auditDry = (int) $pdo->query('SELECT COUNT(*) FROM address_extract_audit')->fetchColumn();
ax($auditDry === 0, 'dry skriver ingen audit');
clearstatcache(true, $tmp . '/kontakter.json');
ax(filemtime($tmp . '/kontakter.json') === $jsonMtime, 'dry rör inte kontakter.json');

$GLOBALS['_JEAB_ADDRESS_GEOCODE'] = static function (string $street): array {
    if (str_contains($street, 'Vetegatan')) {
        return ['score' => 96, 'postal' => '19436', 'city' => 'Upplands Väsby', 'lon' => 17.91, 'lat' => 59.52];
    }
    return [];
};
$GLOBALS['_JEAB_ADDRESS_GROK'] = static function (): array {
    return [];
};
$beforeAudit = (int) $pdo->query('SELECT COUNT(*) FROM address_extract_audit')->fetchColumn();
$wet = jeab_contacts_address_recheck(['co' => 'jarfalla', 'dry' => 0, 'limit' => 10]);
ax(($wet['dry'] ?? 1) === 0 && ($wet['updated'] ?? 0) === 2, 'dry=0 rättar träffarna');
$fixed = $store->get($badId);
ax(is_array($fixed) && (string) ($fixed['city'] ?? '') === 'Upplands Väsby' && (string) ($fixed['postal_code'] ?? '') === '19436', 'fel ort byts och postnumret sparas');
$kiruna = $store->get((string) $other['id']);
ax(is_array($kiruna) && (string) ($kiruna['city'] ?? '') === '' && !empty($kiruna['address_review']), 'fortfarande osäker ort töms och granskas');
ax((string) ($store->get((string) $home['id'])['city'] ?? '') === 'Järfälla', 'ort inom området lämnas');
ax((string) ($store->get((string) $malmo['id'])['city'] ?? '') === 'Malmö', 'andra bolagets kontakt lämnas');
$audits = $pdo->query('SELECT contact_id, before_city, before_postal, after_city, after_postal, source, review FROM address_extract_audit')->fetchAll();
ax(count($audits) === $beforeAudit + 2, 'audit har före och efter');
$blob = json_encode($audits, JSON_UNESCAPED_UNICODE);
ax(is_string($blob) && str_contains($blob, 'Älvsbyn') && str_contains($blob, 'Upplands Väsby') && !str_contains($blob, $secretName) && !str_contains($blob, $secretStreet), 'auditen har ort före och efter, inga namn');
clearstatcache(true, $tmp . '/kontakter.json');
ax(filemtime($tmp . '/kontakter.json') === $jsonMtime, 'rättning skriver inte JSON');

$made = jeab_kontakt_find_or_create([
    'name' => 'Form Person',
    'email' => 'form.person@example.test',
    'address' => 'Vetegatan 15',
    'city' => 'Älvsbyn',
    'company' => 'jarfalla',
]);
ax(is_array($made) && (string) ($made['city'] ?? '') === 'Upplands Väsby' && (string) ($made['postal_code'] ?? '') === '19436', 'ny kontakt tar geokod i stället för Älvsbyn');

$denied = jeab_contacts_address_recheck_dispatch(['co' => 'jarfalla', 'dry' => 1]);
ax(($denied['status'] ?? 0) === 401, 'hooken kräver token');
ax(str_contains((string) file_get_contents(dirname(__DIR__) . '/hook.php'), "=== 'contacts.address_recheck'"), 'hook.php tar contacts.address_recheck');

unset($GLOBALS['_JEAB_REMINDER_CITY_LOOKUP']);
$row = [
    'address' => 'Vetegatan 15',
    'customer_name' => 'Dold Person',
    'company' => 'jarfalla',
    'ort' => null,
    'postal' => '',
];
ax(jeab_reminder_resolve_city($row, true) === 'Upplands Väsby', 'påminnelse utan ort tar geokod');
$ensured = jeab_reminder_ensure_ort(jeab_store(), $row);
ax((string) ($ensured['ort'] ?? '') === 'Upplands Väsby' && (string) ($ensured['postal'] ?? '') === '19436', 'påminnelsen sparar postnumret');
ax(jeab_reminder_resolve_city(['address' => 'Björkrisvägen 14, Bromma', 'company' => 'jarfalla'], false) === 'Bromma', 'känd ort inom området läses ur adressen');

$jeabLim = jeab_kontakt_blank([
    'name' => 'Ort A',
    'email' => 'orta@example.test',
    'street' => 'Limhamnsgatan 1',
    'city' => 'LIMHAMN',
    'company' => 'jeab',
]);
$jeabLim2 = jeab_kontakt_blank([
    'name' => 'Ort B',
    'email' => 'ortb@example.test',
    'street' => 'Limhamnsgatan 2',
    'city' => '  limhamn  ',
    'bolag' => 'JEAB',
]);
$meabBro = jeab_kontakt_blank([
    'name' => 'Ort C',
    'email' => 'ortc@example.test',
    'street' => 'Brogatan 1',
    'city' => 'UPPLANDS-BRO',
    'company' => 'meab',
]);
$meabBro2 = jeab_kontakt_blank([
    'name' => 'Ort D',
    'email' => 'ortd@example.test',
    'street' => 'Brogatan 2',
    'city' => 'upplands-bro',
    'co' => 'MEAB',
]);
$loose = jeab_kontakt_blank([
    'name' => 'Ort E',
    'email' => 'orte@example.test',
    'street' => 'Lösagatan 1',
    'city' => 'Malmö',
]);
foreach ([$jeabLim, $jeabLim2, $meabBro, $meabBro2, $loose] as $seed) {
    $store->upsert((string) $seed['id'], $seed);
}
$linkedReminder = jeab_store()->insertReminder([
    'title' => 'Kopplad',
    'customer_name' => 'Ort F',
    'address' => 'Koppladgatan 3',
    'ort' => 'Järfälla',
    'company' => 'malmo',
    'due_at' => '2026-10-20 09:00:00',
    'created_at' => '2026-10-20 09:00:00',
    'updated_at' => '2026-10-20 09:00:00',
]);
jeab_kontakt_stamp_reminder(jeab_store(), (int) ($linkedReminder['id'] ?? 0), (string) $loose['id']);
$loose['city'] = 'Järfälla';
$store->upsert((string) $loose['id'], $loose);

$jarfallaDry = jeab_contacts_address_recheck(['co' => 'jarfalla', 'dry' => 1, 'limit' => 50]);
$malmoDry = jeab_contacts_address_recheck(['co' => 'meab', 'dry' => 1, 'limit' => 50]);
ax(($jarfallaDry['by_ort']['Limhamn'] ?? 0) === 2, 'JEAB samlar LIMHAMN och limhamn som Limhamn');
ax(!isset($jarfallaDry['by_ort']['Upplands-Bro']) && !isset($jarfallaDry['by_ort']['Järfälla']), 'JEAB tar inte med MEAB eller ort i området');
ax(($malmoDry['by_ort']['Upplands-Bro'] ?? 0) === 2, 'MEAB samlar UPPLANDS-BRO som Upplands-Bro');
ax(($malmoDry['by_ort']['Järfälla'] ?? 0) === 1, 'MEAB tar den kopplade kontakten i Järfälla');
ax(!isset($malmoDry['by_ort']['Limhamn']) && !isset($malmoDry['by_ort']['Malmö']), 'MEAB tar inte med JEAB eller ort i Skåne');
$swapText = json_encode([$jarfallaDry, $malmoDry], JSON_UNESCAPED_UNICODE);
ax(is_string($swapText) && !str_contains($swapText, 'Ort A') && !str_contains($swapText, 'Limhamnsgatan'), 'fördelningen har inga namn eller gator');

$GLOBALS['_JEAB_ADDRESS_GEOCODE'] = static function (string $street): array {
    if (str_contains($street, 'Limhamnsgatan')) {
        return ['score' => 99, 'postal' => '21120', 'city' => 'Malmö', 'lon' => 13.0, 'lat' => 55.6];
    }
    if (str_contains($street, 'Brogatan')) {
        return ['score' => 99, 'postal' => '19436', 'city' => 'Upplands Väsby', 'lon' => 17.9, 'lat' => 59.5];
    }
    return [];
};
$GLOBALS['_JEAB_ADDRESS_GROK'] = static function (): array {
    return ['city' => 'Älvsbyn', 'postal' => '94231', 'confidence' => 0.99];
};
$GLOBALS['_JEAB_ADDRESS_LM'] = static function (string $street): array {
    if (str_contains($street, 'Limhamnsgatan')) {
        return ['matched' => true, 'city' => 'Malmö', 'postal' => '21120'];
    }
    if (str_contains($street, 'Brogatan')) {
        return ['matched' => true, 'city' => 'Upplands Väsby', 'postal' => '19436'];
    }
    return ['matched' => false, 'city' => '', 'postal' => ''];
};
$rejectJeab = jeab_contacts_address_recheck(['co' => 'jarfalla', 'dry' => 0, 'limit' => 50]);
$rejectMeab = jeab_contacts_address_recheck(['co' => 'malmo', 'dry' => 0, 'limit' => 50]);
ax(($rejectJeab['dry'] ?? 1) === 0 && ($rejectMeab['dry'] ?? 1) === 0, 'avvisning körs med dry=0 i båda riktningarna');
$keptLim = $store->get((string) $jeabLim['id']);
ax(is_array($keptLim) && (string) ($keptLim['city'] ?? '') !== 'Malmö' && (string) ($keptLim['postal_code'] ?? '') !== '21120', 'avvisat förslag för JEAB skrivs inte');
$keptBro = $store->get((string) $meabBro['id']);
ax(is_array($keptBro) && (string) ($keptBro['city'] ?? '') !== 'Upplands Väsby' && (string) ($keptBro['postal_code'] ?? '') !== '19436', 'avvisat förslag för MEAB skrivs inte');

echo "address extract ok\n";
