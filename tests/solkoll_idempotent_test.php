<?php

declare(strict_types=1);

/**
 * Solkoll: en anläggning per order, produkter från orderraderna.
 * Mocken följer Solkolls External API (openapi 1.0.0): okända fält ger 400,
 * komponenter kräver Id+Name, okänt id ger 404, sökningen kan släpa efter (index-lag).
 */

$tmp = sys_get_temp_dir() . '/jeab-solkoll-idem-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL kunde inte skapa tempdir\n");
    exit(1);
}
$GLOBALS['_JEAB_DATA_DIR'] = $tmp;
$GLOBALS['_JEAB_FELLOGG_DIR'] = $tmp;
putenv('JEAB_DISABLE_SQLITE=1');
$_ENV['JEAB_DISABLE_SQLITE'] = '1';

require dirname(__DIR__) . '/src/bootstrap.php';

function si_assert(bool $ok, string $message): void
{
    if ($ok) {
        echo "OK  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL {$message}\n");
    exit(1);
}

const SI_CATALOG = [
    ['ProductId' => "ec4f8ead-2156-4eba-bed5-3094beb80bd8", 'ProductName' => 'Aiko 535W  MCE60Db', 'ProductType' => 'SOLAR_PANEL'],
    ['ProductId' => "92545449-cdc9-4e08-8c50-86d42b2a8408", 'ProductName' => 'Longi X10 Scientist Bi-facial Black Frame 535W', 'ProductType' => 'SOLAR_PANEL'],
    ['ProductId' => "cd38b2f1-1183-4ab0-aafb-1ea9e015c3c0", 'ProductName' => 'Longi LR7-54HVB-475M', 'ProductType' => 'SOLAR_PANEL'],
    ['ProductId' => "ab90a7f7-86ea-40c6-8428-44c5292f9255", 'ProductName' => 'AIKO Neostar 3S54 470W Dual Glass MCE54Db', 'ProductType' => 'SOLAR_PANEL'],
    ['ProductId' => "fc85f093-96e9-490c-9050-0d339db432c7", 'ProductName' => 'Sigenergy: Hybrid TP2', 'ProductType' => 'INVERTER'],
    ['ProductId' => "c66d0559-d6cd-4b9d-974e-9e12a2af8353", 'ProductName' => 'Sigen: EV AC', 'ProductType' => 'WALLBOX'],
    ['ProductId' => "8db85c7f-5319-4362-a35c-33b529e11b3f", 'ProductName' => 'Solis: EASTRON SDM630MCT', 'ProductType' => 'ACCESSORY'],
    ['ProductId' => "a77aa563-d538-44a3-9262-7252b5e7ba0d", 'ProductName' => 'Nordmount: Flow', 'ProductType' => 'MOUNTING_SYSTEM'],
    ['ProductId' => "111a1ce3-8112-4394-8211-bbb347a4e206", 'ProductName' => 'Sigenergy: SigenStor BAT', 'ProductType' => 'BATTERY'],
    ['ProductId' => "7f9028bd-8fb0-4b0e-be10-4518e0f4cb74", 'ProductName' => 'Solis: S6-EH3P(12-20)K-ND-H', 'ProductType' => 'INVERTER'],
    ['ProductId' => "3c3603d1-7292-45da-9e8d-be59a325b3be", 'ProductName' => 'Sigenergy: Gateway HomePro TP', 'ProductType' => 'ACCESSORY'],
    ['ProductId' => "be4c6c69-6ed5-4213-9af6-258b8421e72c", 'ProductName' => 'AIKO: MAH60Db', 'ProductType' => 'SOLAR_PANEL'],
    ['ProductId' => "7348ab98-62dd-4fe8-ac6f-6b98d114dd6c", 'ProductName' => 'Nordmount: Falsad/Klickplåt', 'ProductType' => 'MOUNTING_SYSTEM'],
    ['ProductId' => "27710179-ea8b-4ba8-88ca-41e50c510481", 'ProductName' => 'Nordmount Panntak: Råspont', 'ProductType' => 'MOUNTING_SYSTEM'],
    ['ProductId' => "8ba9e353-0ee8-487b-83fd-d22d74acb909", 'ProductName' => 'Nordmount: Plåttak', 'ProductType' => 'MOUNTING_SYSTEM'],
    ['ProductId' => "599f5b6e-df0d-4eb8-b31c-988930a2437d", 'ProductName' => 'Nordmount: Papptak', 'ProductType' => 'MOUNTING_SYSTEM'],
    ['ProductId' => "95aac947-ae69-2ffd-d6e4-81cb51a024b2", 'ProductName' => 'Säkerhetsbrytare AC', 'ProductType' => 'ACCESSORY'],
    ['ProductId' => "f18efc7e-7430-4899-1b81-6329b01a8bf4", 'ProductName' => 'Undercentral', 'ProductType' => 'ACCESSORY'],
    ['ProductId' => "c3216afb-b5f9-45db-9afa-655a5fa9f218", 'ProductName' => 'MAFI: Plåttak Plegel', 'ProductType' => 'MOUNTING_SYSTEM'],
    ['ProductId' => "de4a6357-1c19-43a2-b4c3-51a2d7e7fc42", 'ProductName' => 'AIKO: A-MAH54Db', 'ProductType' => 'SOLAR_PANEL'],
    ['ProductId' => "8cef9155-aa57-4a36-8d64-d5577659f094", 'ProductName' => 'Eastron: Sigen Sensor TP-CT120-DH', 'ProductType' => 'ACCESSORY'],
    ['ProductId' => "6a2f8070-3045-45f3-bdd6-7f14f9533dea", 'ProductName' => 'SigenEnergy: SigenStor EC 5.0-25.0 TP', 'ProductType' => 'INVERTER'],
    ['ProductId' => "e5a66e9f-8b57-447f-9960-f102707a87e9", 'ProductName' => 'MAFI: plåttak Falsklämma', 'ProductType' => 'MOUNTING_SYSTEM'],
    ['ProductId' => "a449cd1a-deac-4237-a5ea-1e2ff17616be", 'ProductName' => 'MAFI: Concrete Tile Bracket Läktfästen', 'ProductType' => 'MOUNTING_SYSTEM'],
    ['ProductId' => "ce14790a-c09c-4606-a8b3-23f0bc1ca755", 'ProductName' => 'MAFI: Plåttak TRP', 'ProductType' => 'MOUNTING_SYSTEM'],
    ['ProductId' => "69519784-d99b-4195-a2f3-672687a2515d", 'ProductName' => 'Nordmount Panntak: Bärläkt', 'ProductType' => 'MOUNTING_SYSTEM'],
    ['ProductId' => "774d10f6-2295-dd4a-2e98-9b873612520c", 'ProductName' => 'Tigo: TS4-A-O', 'ProductType' => 'OPTIMIZER'],
    ['ProductId' => "f029dec7-794b-425d-80af-ed20fd059687", 'ProductName' => 'CHINT: DTSU666', 'ProductType' => 'ACCESSORY'],
    ['ProductId' => "285ad620-d5ef-419f-8462-e6645a6e93bc", 'ProductName' => 'ESDEC: Lutande Panntak', 'ProductType' => 'MOUNTING_SYSTEM'],
    ['ProductId' => "d7965c8b-8682-402b-a79d-8987b3f6bdef", 'ProductName' => ' Easee: Easee Home', 'ProductType' => 'WALLBOX'],
    ['ProductId' => "ccf4dd4f-43ab-49ad-8840-5ab2d91d8271", 'ProductName' => 'MAFI: Roof Tile Bracket Läktfästen', 'ProductType' => 'MOUNTING_SYSTEM'],
    ['ProductId' => "dabfe4c4-c2b4-de5c-7e52-0399d14a0b61", 'ProductName' => 'Trina: TSM-DE09.05', 'ProductType' => 'SOLAR_PANEL'],
];

/** Mock av Solkoll External API med samma regler som riktiga API:t. */
final class SiSolkoll
{
    /** @var array<string,array<string,mixed>> */
    public array $facilities = [];
    /** @var list<string> */
    public array $calls = [];
    public int $creates = 0;
    public int $patches = 0;
    /** Antal list-anrop som en ny anläggning är osynlig i sökningen. */
    public int $indexLag = 0;
    /** @var array<string,int> */
    public array $hiddenFor = [];
    public bool $failList = false;
    public ?array $lastPatch = null;
    public ?array $lastCreate = null;
    private int $seq = 0;

    private const CREATE_KEYS = ['InstallationDate', 'Address', 'County', 'ZipCode', 'City', 'FacilityStatus', 'Components', 'Roofs', 'SendList', 'LatLng', 'TeamId', 'Name', 'FirstName', 'LastName', 'Email', 'Phone', 'ResponsibleUserEmail'];
    private const ROOF_KEYS = ['Name', 'Azimuth', 'Tilt', 'Width', 'Length', 'ScaffoldingHeight', 'Type', 'Material', 'Accessories', 'Batteries', 'Inverters', 'MountingSystems', 'Optimizers', 'Panels', 'RoofTypes', 'Wallboxes'];
    private const BUCKETS = ['Accessories', 'Batteries', 'Inverters', 'MountingSystems', 'Optimizers', 'Panels', 'Wallboxes'];

    public function __invoke(string $method, string $path, ?array $body = null): array
    {
        $method = strtoupper($method);
        $this->calls[] = $method . ' ' . $path;
        $parts = parse_url('/' . ltrim($path, '/'));
        $route = trim((string) ($parts['path'] ?? ''), '/');
        parse_str((string) ($parts['query'] ?? ''), $q);
        if ($route === 'products' && $method === 'GET') {
            if (array_key_exists('query', $q) && trim((string) $q['query']) === '') {
                $this->bad('query must be non-empty');
            }
            return ['Items' => SI_CATALOG, 'Count' => count(SI_CATALOG)];
        }
        if ($route === 'facilities' && $method === 'GET') {
            if ($this->failList) {
                throw new RuntimeException('Solkoll: HTTP 502');
            }
            if (array_key_exists('query', $q) && trim((string) $q['query']) === '') {
                $this->bad('query must be non-empty');
            }
            $needle = mb_strtolower(trim((string) ($q['query'] ?? '')), 'UTF-8');
            $out = [];
            foreach ($this->facilities as $id => $f) {
                if (!empty($f['_deleted'])) {
                    continue;
                }
                if (($this->hiddenFor[$id] ?? 0) > 0) {
                    $this->hiddenFor[$id]--;
                    continue;
                }
                $hay = mb_strtolower(implode(' ', [$f['Address'] ?? '', $f['Name'] ?? '', $f['FirstName'] ?? '', $f['LastName'] ?? '', $f['Email'] ?? '']), 'UTF-8');
                if ($needle === '' || str_contains($hay, $needle)) {
                    $out[] = $this->public($f);
                }
            }
            return ['Items' => $out, 'Count' => count($out), 'TotalCount' => count($out)];
        }
        if ($route === 'facilities' && $method === 'POST') {
            $this->validate($body ?? [], self::CREATE_KEYS);
            $id = sprintf('fac-%04d-0000-0000-0000-000000000000', ++$this->seq);
            $f = $body + ['FacilityStatus' => 'NEW'];
            $f['FacilityId'] = $id;
            $f['CreatedAt'] = 1791000000000 + $this->seq;
            $f['TotalTodos'] = 4;
            $f['TodosCompleted'] = 0;
            $this->facilities[$id] = $f;
            $this->hiddenFor[$id] = $this->indexLag;
            $this->creates++;
            $this->lastCreate = $body;
            return $this->public($f);
        }
        if (preg_match('#^facilities/([^/]+)$#', $route, $m) === 1) {
            $id = rawurldecode($m[1]);
            if (!isset($this->facilities[$id]) || !empty($this->facilities[$id]['_deleted'])) {
                throw new JeabSolkollNotFound('Solkoll: Facility not found');
            }
            if ($method === 'GET') {
                return $this->public($this->facilities[$id]);
            }
            if ($method === 'PATCH' || $method === 'PUT') {
                $keys = array_values(array_diff(self::CREATE_KEYS, ['TeamId']));
                $this->validate($body ?? [], $keys);
                foreach ($body ?? [] as $k => $v) {
                    $this->facilities[$id][$k] = $v;
                }
                // Som riktiga Solkoll: utan FirstName+LastName i PATCH blir Name tomt.
                if (!isset($body['FirstName'], $body['LastName'])) {
                    $this->facilities[$id]['Name'] = '';
                }
                $this->patches++;
                $this->lastPatch = $body;
                return $this->public($this->facilities[$id]);
            }
        }
        throw new JeabSolkollNotFound('Solkoll: External API route not found');
    }

    private function public(array $f): array
    {
        unset($f['_deleted']);
        return $f;
    }

    private function bad(string $why): never
    {
        throw new RuntimeException('Solkoll: ' . $why);
    }

    private function validate(array $body, array $keys): void
    {
        foreach (array_keys($body) as $k) {
            if (!in_array($k, $keys, true)) {
                $this->bad('unknown field ' . $k);
            }
        }
        if (isset($body['Components'])) {
            if (!is_array($body['Components'])) {
                $this->bad('Components must be object');
            }
            foreach ($body['Components'] as $bucket => $items) {
                if (!in_array($bucket, self::BUCKETS, true)) {
                    $this->bad('unknown component bucket ' . $bucket);
                }
                $this->items($items, 'Components.' . $bucket);
            }
        }
        if (isset($body['Roofs'])) {
            if (!is_array($body['Roofs']) || !array_is_list($body['Roofs'])) {
                $this->bad('Roofs must be array');
            }
            foreach ($body['Roofs'] as $i => $roof) {
                foreach (array_keys((array) $roof) as $k) {
                    if (!in_array($k, self::ROOF_KEYS, true)) {
                        $this->bad('unknown roof field ' . $k);
                    }
                }
                foreach (self::BUCKETS as $bucket) {
                    if (isset($roof[$bucket])) {
                        $this->items($roof[$bucket], 'Roofs.' . $i . '.' . $bucket);
                    }
                }
            }
        }
        foreach (['Address', 'Name', 'FirstName', 'LastName', 'Email', 'Phone', 'City', 'ZipCode'] as $k) {
            if (array_key_exists($k, $body) && $body[$k] !== null && (!is_string($body[$k]) || strlen($body[$k]) > 256)) {
                $this->bad($k . ' must be string<=256 or null');
            }
        }
    }

    private function items(mixed $items, string $where): void
    {
        if (!is_array($items) || !array_is_list($items)) {
            $this->bad($where . ' must be array');
        }
        $ids = array_column(SI_CATALOG, 'ProductId');
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['Id'] ?? null) || !is_string($item['Name'] ?? null)) {
                $this->bad($where . ' item needs Id and Name');
            }
            foreach (array_keys($item) as $k) {
                if (!in_array($k, ['Id', 'Name', 'Amount', 'Layout', 'Size'], true)) {
                    $this->bad($where . ' unknown item field ' . $k);
                }
            }
            foreach (['Amount', 'Size'] as $num) {
                if (isset($item[$num]) && !is_int($item[$num]) && !is_float($item[$num])) {
                    $this->bad($where . ' ' . $num . ' must be number');
                }
            }
            if ($item['Id'] !== '' && !in_array($item['Id'], $ids, true) && !str_starts_with($item['Id'], 'keep-')) {
                $this->bad($where . ' unknown product ' . $item['Id']);
            }
        }
    }
}

function si_mock(): SiSolkoll
{
    $mock = new SiSolkoll();
    $GLOBALS['_JEAB_SOLKOLL_HTTP'] = $mock;
    unset($GLOBALS['_JEAB_SOLKOLL_FACILITIES'], $GLOBALS['_JEAB_SOLKOLL_PRODUCTS']);
    return $mock;
}

function si_lines(array $rows): array
{
    $out = [];
    foreach ($rows as [$sku, $title, $qty]) {
        $out[] = ['sku' => $sku, 'title' => $title, 'qty' => $qty];
    }
    return $out;
}

/** Orderraderna på Hässelby strandväg 47 (order 23), som de ligger live. */
function si_hasselby_lines(): array
{
    return si_lines([
        ['91367', 'Krok', 32], ['913321', 'Skena 3600 mm', 11], ['913325', 'Skarv', 7], ['91350', 'Ändstycke', 8],
        ['9839', 'Speed clips', 70], ['9834', 'Bult', 70], ['91230', 'Klämma', 34], ['AIKO-A535-MCE60Db', 'AIKO 535W', 14],
        ['1151', 'Plåt', 0], ['2550', 'Korg', 1], ['11010017', 'Sigen 20 kW EC', 1], ['11170033', 'Bottenplatta', 1],
        ['11130012', 'Sigen batteri 9 kWh', 3], ['11070008', 'Sigen elbilsladdare AC uttag', 2], ['11070012', 'Sigen elbilsladdare AC 5m kabel', 2],
        ['MC4-EVO2-MALE-EVO2-6I-UR', 'DC kontakt minus', 50], ['MC4-EVO2-FEMALE-EVO2-6I-UR', 'DC kontakt plus', 50],
    ]);
}

/** Orderraderna på Gymnastikvägen 29 (order 102). */
function si_gymnastik_lines(): array
{
    return si_lines([
        ['11010186', 'Sigen Hybrid Inverter 12.0kW TP2', 1], ['913321', 'Skena 3600 mm', 21], ['1118001100', 'SigenStor BC', 1],
        ['91350', 'Ändstycke', 16], ['11130012', 'Sigen batteri 9 kWh', 1], ['9855', 'Fastener for Steel Roof 6.3x19 HEX A2', 150],
        ['91230', 'Klämma', 62], ['AIKO-A535-MCE60Db', 'AIKO 535W', 26], ['1151', 'Plåt', 2], ['2550', 'Korg', 2],
        ['MC4-EVO2-FEMALE-EVO2-6I-UR', 'DC kontakt plus', 5], ['MC4-EVO2-MALE-EVO2-6I-UR', 'DC kontakt minus', 5], ['9837', 'MAFI EPDM 40mm x 2mm L=10 meter', 4],
    ]);
}

function si_order(int $id, string $kind, array $lines, string $name = 'Karl Joachim Jan Adolfsson', string $address = 'Hässelby Strandväg 47 Hässelby, Hässelby'): array
{
    return jeab_purchase_put([
        'reminder_id' => $id,
        'kind' => $kind,
        'company' => 'jarfalla',
        'name' => $name,
        'huvudsokande' => $name,
        'huvudsokande_address' => $address,
        'email' => 'kund@example.test',
        'phone' => '+46700000000',
        'inkop_lines' => $lines,
        'steps' => [],
    ]);
}

function si_names(array $items): array
{
    return array_map(static fn(array $i): string => (string) $i['Name'], $items);
}

// ---- 1. Gatunyckel och felformat -------------------------------------------------
si_assert(jeab_solkoll_street_key('Gymnastikvägen 29, Järfälla') === jeab_solkoll_street_key('Gymnastikvägen 29 Järfälla'), 'gatunyckel: komma/ort spelar ingen roll');
si_assert(jeab_solkoll_street_key('Hässelby Strandväg 47 Hässelby') === 'hasselby strandvag 47', 'gatunyckel Hässelby strandväg 47');
si_assert(jeab_solkoll_street_key('Gymnastikvägen 27') !== jeab_solkoll_street_key('Gymnastikvägen 29'), 'grannhuset 27 matchar inte 29');
si_assert(jeab_solkoll_street_key('Stora Slingan 13 B') === 'stora slingan 13b' && jeab_solkoll_street_key('Stora Slingan 13B, Sollentuna') === 'stora slingan 13b', 'husbokstav normaliseras');
si_assert(jeab_solkoll_street_key('Utan nummer') === '', 'adress utan nummer jämförs inte');
si_assert(jeab_solkoll_error_message(['error' => ['code' => 'bad_request', 'message' => 'Invalid body']], 400) === 'Solkoll: Invalid body', 'API-felets message visas (inte «Array»)');
si_assert(jeab_solkoll_facility_url('afd1210a-7515-4e21-9256-ab0c6cb358a0') === 'https://app.solkoll.se/sv/facilities/afd1210a-7515-4e21-9256-ab0c6cb358a0', 'länk till anläggningen');

// ---- 2. Produktmatchning ----------------------------------------------------------
$map = jeab_solkoll_map_lines(si_hasselby_lines(), SI_CATALOG);
$it = $map['items'];
si_assert(count($it['Panels']) === 1 && $it['Panels'][0]['Id'] === 'ec4f8ead-2156-4eba-bed5-3094beb80bd8', 'Hässelby: AIKO 535W → «Aiko 535W MCE60Db»');
si_assert($it['Panels'][0]['Amount'] === 14 && $it['Panels'][0]['Size'] === 535 && $it['Panels'][0]['Layout'] === 'Portrait', 'Hässelby: 14 paneler, 535 W');
si_assert(count($it['MountingSystems']) === 1 && $it['MountingSystems'][0]['Id'] === 'a449cd1a-deac-4237-a5ea-1e2ff17616be', 'Hässelby: krok → MAFI Concrete Tile Bracket');
si_assert(!in_array('MAFI: plåttak Falsklämma', si_names($it['MountingSystems']), true), '«Klämma» blir inte Falsklämma');
si_assert(count($it['Inverters']) === 1 && $it['Inverters'][0]['Id'] === '6a2f8070-3045-45f3-bdd6-7f14f9533dea' && $it['Inverters'][0]['Size'] === 20, 'Hässelby: Sigen 20 kW EC → SigenStor EC 5.0-25.0 TP, 20 kW');
si_assert(count($it['Batteries']) === 1 && $it['Batteries'][0]['Size'] === 27, 'Hässelby: 3 × 9 kWh → SigenStor BAT 27 kWh');
si_assert($map['unmatched'] === [], 'Hässelby: inga omatchade produkter');

$map = jeab_solkoll_map_lines(si_gymnastik_lines(), SI_CATALOG);
$it = $map['items'];
si_assert($it['Panels'][0]['Amount'] === 26, 'Gymnastikvägen: 26 paneler');
si_assert($it['Inverters'][0]['Id'] === 'fc85f093-96e9-490c-9050-0d339db432c7' && $it['Inverters'][0]['Size'] === 12, 'Gymnastikvägen: Hybrid 12.0kW TP2 → Sigenergy Hybrid TP2, 12 kW');
si_assert($it['Batteries'][0]['Size'] === 9, 'Gymnastikvägen: 1 × 9 kWh');
si_assert($it['MountingSystems'] === [], 'Gymnastikvägen: plåttak utan sort gissas inte');
$miss = array_values(array_filter($map['unmatched'], static fn(array $u): bool => $u['what'] === 'Montage'));
si_assert(count($miss) === 1 && str_contains($miss[0]['reason'], 'plåttak'), 'Gymnastikvägen: montage visas som omatchat med orsak');

$map = jeab_solkoll_map_lines(si_lines([['', 'Longi 999W', 10], ['', 'AIKO 600W', 10], ['', 'Huawei SUN2000 10 kW', 1], ['', 'Skena', 4]]), SI_CATALOG);
$whats = array_map(static fn(array $u): string => $u['what'] . '|' . $u['title'], $map['unmatched']);
si_assert(in_array('Paneler|Longi 999W', $whats, true) && in_array('Paneler|AIKO 600W', $whats, true), 'okänd effekt loggas som omatchad panel');
si_assert(in_array('Växelriktare|Huawei SUN2000 10 kW', $whats, true), 'okänd växelriktare loggas');
si_assert(in_array('Montage|', $whats, true), 'saknat takfäste rapporteras');
si_assert($map['items']['Panels'] === [], 'ingen panel gissas vid fel effekt');

// ---- 3. Skapa en gång, andra körningen återanvänder ------------------------------
$mock = si_mock();
$mock->indexLag = 99; // sökningen ser inte nya anläggningar alls
si_order(501, 'sol', si_hasselby_lines());
$row = jeab_solkoll_run_order(501);
si_assert($mock->creates === 1, 'första körningen skapar en anläggning');
$fid = (string) $row['solkoll_facility_id'];
si_assert($fid !== '' && (jeab_purchase_get(501)['solkoll_facility_id'] ?? '') === $fid, 'facility-id sparas på ordern');
$create = $mock->lastCreate;
si_assert(($create['Address'] ?? '') === 'Hässelby Strandväg 47 Hässelby' && ($create['City'] ?? '') === 'Hässelby', 'adress och ort skickas separat');
si_assert(si_names($create['Roofs'][0]['Panels'] ?? []) === ['Aiko 535W  MCE60Db'] && si_names($create['Roofs'][0]['MountingSystems'] ?? []) === ['MAFI: Concrete Tile Bracket Läktfästen'], 'paneler och montage på taket');
si_assert(si_names($create['Components']['Inverters']) === ['SigenEnergy: SigenStor EC 5.0-25.0 TP'] && $create['Components']['Panels'] === [], 'växelriktare under Components, paneler inte dubbelt');
for ($i = 0; $i < 5; $i++) {
    jeab_solkoll_run_order(501);
}
si_assert($mock->creates === 1, 'fem klick till: fortfarande en anläggning (sparat id, trots index-lag)');
si_assert($mock->patches === 0, 'omkörning ändrar inget när allt redan finns');

// Gammal rad utan id (t.ex. autospar skrev över): adressuppslaget hittar den.
$mock->hiddenFor = [];
$stale = jeab_purchase_get(501);
unset($stale['solkoll_facility_id']);
jeab_purchase_put($stale);
$row = jeab_solkoll_run_order(501);
si_assert($mock->creates === 1 && $row['solkoll_facility_id'] === $fid, 'utan sparat id kopplas samma anläggning via adress');

// ---- 4. Per-order-lås ---------------------------------------------------------------
$lock = jeab_solkoll_lock_path(501);
$holder = proc_open([PHP_BINARY, '-r', '$h=fopen($argv[1],"c"); flock($h, LOCK_EX); echo "L\n"; fflush(STDOUT); usleep(1500000);', $lock], [1 => ['pipe', 'w']], $pipes);
fgets($pipes[1]);
$t0 = microtime(true);
$blocked = '';
try {
    jeab_solkoll_run_order(501, 0.3);
} catch (InvalidArgumentException $e) {
    $blocked = $e->getMessage();
}
si_assert(str_contains($blocked, 'arbetar redan'), 'samtidigt klick blockeras av låset');
$row = jeab_solkoll_run_order(501, 5.0);
si_assert(microtime(true) - $t0 >= 1.0 && $mock->creates === 1, 'nästa klick väntar på låset och skapar ingen dubblett');
proc_close($holder);

// ---- 5. Raderad anläggning och misslyckat uppslag ------------------------------------
$mock->facilities[$fid]['_deleted'] = true;
$keep = 'keep-0002-0000-0000-0000-000000000000';
$mock->facilities[$keep] = ['FacilityId' => $keep, 'Address' => 'Hässelby Strandväg 47 Hässelby', 'FirstName' => 'Karl Joachim Jan', 'LastName' => 'Adolfsson', 'FacilityStatus' => 'IN_PROGRESS', 'DocumentCount' => 3, 'CreatedAt' => 1, 'Components' => [], 'Roofs' => []];
$row = jeab_solkoll_run_order(501);
si_assert($row['solkoll_facility_id'] === $keep && $mock->creates === 1, 'sparat id som är raderat (404) kopplas om till anläggningen på adressen');
si_assert($row['solkoll_status'] === 'paborjad', 'status följer den kopplade anläggningen');

$mock2 = si_mock();
$mock2->failList = true;
si_order(502, 'sol', si_hasselby_lines(), 'Ny Kund', 'Testgatan 5, 177 00 Järfälla');
$err = '';
try {
    jeab_solkoll_run_order(502);
} catch (InvalidArgumentException $e) {
    $err = $e->getMessage();
}
si_assert($mock2->creates === 0, 'misslyckat uppslag skapar aldrig en ny anläggning');
si_assert(str_contains($err, 'Kunde inte söka i Solkoll') && str_contains($err, '502'), 'felet bär den konkreta orsaken: ' . $err);
si_assert((jeab_purchase_get(502)['solkoll_error'] ?? '') !== '', 'felet sparas på ordern');

// ---- 6. Befintlig anläggning: fyll tomt, skriv aldrig över ---------------------------
$mock3 = si_mock();
$gid = 'keep-0029-0000-0000-0000-000000000000';
$existing = [
    'FacilityId' => $gid, 'Address' => 'Gymnastikvägen 29 Järfälla', 'Name' => 'Anna Höglund', 'FirstName' => 'Anna', 'LastName' => 'Höglund',
    'FacilityStatus' => 'NEW', 'CreatedAt' => 5, 'TotalTodos' => 4, 'TodosCompleted' => 0,
    'Components' => ['Accessories' => [['Id' => '8cef9155-aa57-4a36-8d64-d5577659f094', 'Name' => 'Eastron: Sigen Sensor TP-CT120-DH', 'Amount' => 1]], 'Batteries' => [['Id' => '111a1ce3-8112-4394-8211-bbb347a4e206', 'Name' => 'Sigenergy: SigenStor BAT', 'Amount' => 3]], 'Inverters' => [], 'MountingSystems' => [['Id' => 'e5a66e9f-8b57-447f-9960-f102707a87e9', 'Name' => 'MAFI: plåttak Falsklämma', 'Amount' => 34]], 'Optimizers' => [], 'Panels' => [['Id' => 'ec4f8ead-2156-4eba-bed5-3094beb80bd8', 'Name' => 'Aiko 535W  MCE60Db', 'Amount' => 14]], 'Wallboxes' => []],
    'Roofs' => [['Name' => 'Tak 1', 'Type' => 'Panntak', 'MountingSystems' => [['Id' => 'e5a66e9f-8b57-447f-9960-f102707a87e9', 'Name' => 'MAFI: plåttak Falsklämma', 'Amount' => 34]], 'Panels' => [['Id' => 'ec4f8ead-2156-4eba-bed5-3094beb80bd8', 'Name' => 'Aiko 535W  MCE60Db', 'Amount' => 14]]]],
];
$mock3->facilities[$gid] = $existing;
si_order(503, 'sol', si_gymnastik_lines(), 'Anna Höglund', 'Gymnastikvägen 29, Järfälla');
$row = jeab_solkoll_run_order(503);
si_assert($mock3->creates === 0 && $row['solkoll_facility_id'] === $gid, 'Gymnastikvägen kopplas till befintlig anläggning');
si_assert($mock3->patches === 1 && array_keys($mock3->lastPatch) === ['Components', 'Roofs', 'Name', 'FirstName', 'LastName'], 'Components + tak (paneler) + oförändrade namnfält patchas');
si_assert($mock3->facilities[$gid]['Name'] === 'Anna Höglund' && $mock3->facilities[$gid]['FirstName'] === 'Anna', 'namnet i Solkoll finns kvar efter PATCH');
$wantPanel = [['Id' => 'ec4f8ead-2156-4eba-bed5-3094beb80bd8', 'Name' => 'Aiko 535W  MCE60Db', 'Amount' => 26, 'Size' => 535, 'Layout' => 'Portrait']];
$roof = $mock3->facilities[$gid]['Roofs'][0];
si_assert($roof['Panels'] === $wantPanel, 'taket: 26 × Aiko 535 W stående (som ordern)');
si_assert($roof['MountingSystems'] === $existing['Roofs'][0]['MountingSystems'] && $roof['Name'] === 'Tak 1' && $roof['Type'] === 'Panntak', 'montage och takdata orörda');
si_assert($mock3->facilities[$gid]['Components']['Panels'] === $wantPanel, 'Components.Panels samma som taket');
si_assert(si_names($mock3->lastPatch['Components']['Inverters']) === ['Sigenergy: Hybrid TP2'] && $mock3->lastPatch['Components']['Inverters'][0]['Size'] === 12, 'tom växelriktare fylls: Hybrid TP2 12 kW');
$before = $existing['Components'];
$after = $mock3->lastPatch['Components'];
unset($before['Inverters'], $after['Inverters'], $before['Panels'], $after['Panels']);
si_assert($before === $after, 'övriga komponenter skickas oförändrade (batteri, montage, tillbehör)');
si_assert(str_contains(implode(' ', $row['solkoll_fixed']), 'Aiko 535W MCE60Db 14 st × utan W utan layout → Aiko 535W MCE60Db 26 st × 535 W stående'), 'rättelsen rapporteras före → efter');
si_assert(!str_contains(implode(' ', $row['solkoll_differs']), 'Paneler'), 'paneler är inte längre en «skiljer sig»-rad');
jeab_ordrar_external_live(false);
$card = jeab_solkoll_card_html($row, 'jarfalla', 503);
si_assert(str_contains($card, 'Rättat efter ordern:'), 'kortet visar rättelsen');
$row = jeab_solkoll_run_order(503);
si_assert($mock3->patches === 1 && $mock3->creates === 0, 'andra körningen: inga fler skrivningar');

// ---- 6b. Paneler: rätta liggande/fel antal/fel W, rapportera tvetydigt ---------------------
$aiko = ['Id' => 'ec4f8ead-2156-4eba-bed5-3094beb80bd8', 'Name' => 'Aiko 535W  MCE60Db'];
$panelCase = static function (int $orderId, array $roofs, array $lines, array $components = []) use (&$mockP): array {
    $mockP = si_mock();
    $fid = 'keep-p' . $orderId;
    $mockP->facilities[$fid] = ['FacilityId' => $fid, 'Address' => 'Panelgatan ' . $orderId . ' Järfälla', 'Name' => 'Per Panel', 'FirstName' => 'Per', 'LastName' => 'Panel', 'CreatedAt' => 1,
        'Components' => $components + ['Inverters' => [['Id' => 'fc85f093-96e9-490c-9050-0d339db432c7', 'Name' => 'Sigenergy: Hybrid TP2', 'Size' => 12]]], 'Roofs' => $roofs];
    si_order($orderId, 'sol', $lines, 'Per Panel', 'Panelgatan ' . $orderId . ', Järfälla');
    $row = jeab_solkoll_run_order($orderId);
    return [$row, $mockP->facilities[$fid], $mockP];
};
$lines26 = [['title' => 'AIKO 535W MCE60Db', 'sku' => 'A535', 'qty' => 26], ['title' => 'Krok takpanna', 'sku' => 'K1', 'qty' => 40], ['title' => 'Sigen Hybrid 12 kW TP2', 'sku' => 'S12', 'qty' => 1]];
[$row, $f, $m] = $panelCase(540, [['Name' => 'Tak 1', 'Panels' => [$aiko + ['Amount' => 14, 'Layout' => 'Landscape']]]], $lines26);
si_assert($f['Roofs'][0]['Panels'] === [$aiko + ['Amount' => 26, 'Size' => 535, 'Layout' => 'Portrait']] && $m->patches === 1, '14 liggande utan W → 26 × 535 W stående');
si_assert(!isset($m->lastPatch['Components']) || $m->lastPatch['Components']['Inverters'] === $f['Components']['Inverters'], 'växelriktaren orörd');
[$row, $f, $m] = $panelCase(541, [['Name' => 'Tak 1', 'Panels' => [$aiko + ['Amount' => 26, 'Size' => 535, 'Layout' => 'Portrait']]]], $lines26);
si_assert(($row['solkoll_fixed'] ?? []) === [] && $f['Roofs'][0]['Panels'] === [$aiko + ['Amount' => 26, 'Size' => 535, 'Layout' => 'Portrait']] && ($m->lastPatch === null || !isset($m->lastPatch['Roofs']) || $m->lastPatch['Roofs'][0]['Panels'] === $f['Roofs'][0]['Panels']), 'redan rätt: panelerna rörs inte');
$runs = $m->patches;
jeab_solkoll_run_order(541);
si_assert($m->patches === $runs, 'redan rätt: andra körningen skriver inget');
[$row, $f, $m] = $panelCase(542, [['Name' => 'Tak 1', 'Panels' => [$aiko + ['Amount' => 26, 'Size' => 470, 'Layout' => 'Portrait']]]], $lines26);
si_assert($f['Roofs'][0]['Panels'][0]['Size'] === 535 && $m->patches === 1, 'fel W rättas till 535');
[$row, $f, $m] = $panelCase(543, [['Name' => 'Tak 1', 'Panels' => [['Id' => 'be4c6c69-6ed5-4213-9af6-258b8421e72c', 'Name' => 'AIKO: Neostar 2S+ A-MAH60Db', 'Amount' => 26]]]], $lines26);
si_assert($f['Roofs'][0]['Panels'] === [$aiko + ['Amount' => 26, 'Size' => 535, 'Layout' => 'Portrait']], 'annan panelmodell ersätts med orderns');
$two = [['Name' => 'Tak 1', 'Panels' => [$aiko + ['Amount' => 13, 'Layout' => 'Portrait']]], ['Name' => 'Tak 2', 'Panels' => [$aiko + ['Amount' => 3, 'Layout' => 'Landscape']]]];
[$row, $f, $m] = $panelCase(544, $two, $lines26);
si_assert(array_column($f['Roofs'], 'Panels') === array_column($two, 'Panels') && str_contains(implode(' ', $row['solkoll_differs']), 'ligger på 2 tak'), 'paneler på två tak: rapporteras, rättas inte');
$mixed = array_merge($lines26, [['title' => 'Longi LR7-54HVB-475M 475W', 'sku' => 'L475', 'qty' => 4]]);
[$row, $f, $m] = $panelCase(545, [['Name' => 'Tak 1', 'Panels' => [$aiko + ['Amount' => 14]]]], $mixed);
si_assert($f['Roofs'][0]['Panels'] === [$aiko + ['Amount' => 14]] && str_contains(implode(' ', $row['solkoll_differs']), 'flera paneltyper'), 'två paneltyper i ordern: rapporteras, rättas inte');
[$row, $f, $m] = $panelCase(546, [['Name' => 'Tak 1', 'Panels' => [['Id' => 'be4c6c69-6ed5-4213-9af6-258b8421e72c', 'Name' => 'AIKO: Neostar 2S+ A-MAH60Db', 'Amount' => 10]]]], [['title' => 'AIKO Neostar 2S+ A-MAH60Db', 'sku' => 'N2', 'qty' => 12], ['title' => 'Sigen Hybrid 12 kW TP2', 'sku' => 'S12', 'qty' => 1]]);
si_assert($f['Roofs'][0]['Panels'][0]['Amount'] === 10 && !isset($f['Roofs'][0]['Panels'][0]['Size']) && str_contains(implode(' ', $row['solkoll_differs']), 'ingen orderrad som går att koppla till en Solkoll-panel med W'), 'W saknas i orderraden: rapporteras, rättas inte');
[$row, $f, $m] = $panelCase(547, [['Name' => 'Tak 1', 'Panels' => [['Id' => 'be4c6c69-6ed5-4213-9af6-258b8421e72c', 'Name' => 'AIKO: Neostar 2S+ A-MAH60Db', 'Amount' => 10]]]], [['title' => 'AIKO Neostar 2S+ 475W A-MAH60Db', 'sku' => 'N2', 'qty' => 12], ['title' => 'Sigen Hybrid 12 kW TP2', 'sku' => 'S12', 'qty' => 1]]);
si_assert(($f['Roofs'][0]['Panels'][0]['Size'] ?? 0) === 475 && $f['Roofs'][0]['Panels'][0]['Amount'] === 12 && $f['Roofs'][0]['Panels'][0]['Layout'] === 'Portrait', 'W läses ur orderradens text när produktnamnet saknar W (475)');
$mapped = jeab_solkoll_map_lines([['title' => 'AIKO 535W MCE60Db', 'sku' => 'x', 'qty' => 26]], SI_CATALOG);
si_assert($mapped['items']['Panels'] === [$aiko + ['Amount' => 26, 'Layout' => 'Portrait', 'Size' => 535]], 'ny anläggning: panel med Antal, Storlek (W) och stående');

// Dubbletter på adressen: koppla till den med mest data.
$mock4 = si_mock();
$mock4->facilities['keep-a'] = ['FacilityId' => 'keep-a', 'Address' => 'Gymnastikvägen 29 Järfälla', 'Name' => 'Anna Höglund', 'CreatedAt' => 1, 'Components' => [], 'Roofs' => []];
$mock4->facilities['keep-b'] = ['FacilityId' => 'keep-b', 'Address' => 'Gymnastikvägen 29', 'Name' => 'Anna Höglund', 'CreatedAt' => 2, 'DocumentCount' => 7, 'Components' => [], 'Roofs' => []];
si_order(504, 'sol', [], 'Anna Höglund', 'Gymnastikvägen 29, Järfälla');
$row = jeab_solkoll_run_order(504);
si_assert($row['solkoll_facility_id'] === 'keep-b' && $row['solkoll_duplicates'] === 1 && $mock4->creates === 0, 'flera på adressen: den med mest data kopplas, dubbletten rapporteras');

// ---- 7. Bara sol -------------------------------------------------------------------------
foreach (['batteri', 'laddbox'] as $n => $kind) {
    $m = si_mock();
    si_order(510 + $n, $kind, si_hasselby_lines());
    $msg = '';
    try {
        jeab_solkoll_run_order(510 + $n);
    } catch (InvalidArgumentException $e) {
        $msg = $e->getMessage();
    }
    si_assert($msg === 'Solkoll gäller bara solceller.' && $m->calls === [], $kind . ': ingen Solkoll-körning och inga anrop');
    si_assert(jeab_solkoll_card_html(jeab_purchase_get(510 + $n), 'jarfalla', 510 + $n) === '', $kind . ': inget Solkoll-kort');
}

// ---- 8. Autospar av orderrader rör inte Solkoll ---------------------------------------
$m = si_mock();
si_order(520, 'sol', []);
jeab_ordrar_save_orderrader(['id' => 520, 'line_sku' => ['AIKO-A535-MCE60Db'], 'line_title' => ['AIKO 535W'], 'line_qty' => ['14']]);
si_assert($m->calls === [], 'autospar av orderrader gör inga Solkoll-anrop');

// ---- 9. Kortet ----------------------------------------------------------------------------
jeab_ordrar_external_live(false);
$card = jeab_solkoll_card_html(jeab_purchase_get(520), 'jarfalla', 520);
si_assert(str_contains($card, 'Skapa i Solkoll') && str_contains($card, 'value="ordrar.solkoll"'), 'kortet har en knapp som skapar/kopplar');
si_assert(substr_count($card, 'ordrar-primary') === 0, 'kortet tar ingen andra primärknapp (en primär per vy)');
$card = jeab_solkoll_card_html(jeab_purchase_get(503), 'jarfalla', 503);
si_assert(str_contains($card, 'Uppdatera Solkoll') && str_contains($card, 'https://app.solkoll.se/sv/facilities/' . $gid), 'kopplad order visar länk och Uppdatera');
si_assert(!str_contains($card, 'Lades inte till i Solkoll'), 'montage som redan finns i Solkoll visas inte som omatchat');
$m5 = si_mock();
si_order(530, 'sol', si_gymnastik_lines(), 'Per Plåt', 'Plåtvägen 3, Järfälla');
$row = jeab_solkoll_run_order(530);
$card = jeab_solkoll_card_html($row, 'jarfalla', 530);
si_assert($m5->creates === 1 && str_contains($card, 'Lades inte till i Solkoll') && str_contains($card, 'plåttak'), 'ny anläggning utan takfäste: omatchat montage syns i kortet');
$row502 = jeab_purchase_get(502);
$card = jeab_solkoll_card_html($row502, 'jarfalla', 502);
si_assert(str_contains($card, 'is-alert') && str_contains($card, 'Kunde inte spara.'), 'sparat fel visas rött med «Kunde inte spara»');

// ---- 10. Klientskydd mot dubbelklick ---------------------------------------------------
$page = (string) file_get_contents(dirname(__DIR__) . '/src/pages/ordrar.php');
si_assert(str_contains($page, 'window.jeabOrdrarBusy = window.jeabOrdrarBusy ||'), 'gemensam busy-hjälpare finns');
si_assert(str_contains($page, "if (form.getAttribute('data-busy') === '1') { return Promise.resolve(); }"), 'ajax-formulär (beställning, föranmälan, fakturering) skickas bara en gång');
si_assert(substr_count($page, 'if (confirmBusy) { return Promise.resolve(); }') === 2, 'Slutför (Fortnox) och Nästa skickas bara en gång');
si_assert(str_contains($page, 'data-once="1"') && str_contains($page, "once.getAttribute('data-sent') === '1'"), 'Skapa Fortnox-order skickas bara en gång');
si_assert(str_contains($page, 'function postSolkoll(') && str_contains($page, 'window.jeabOrdrarBusy(btn, false);'), 'Solkoll-knappen låses och släpps vid fel');
si_assert(str_contains($page, 'status.textContent = saveFailText('), 'fel visas som «Kunde inte spara» + orsak på samma plats');
$css = (string) file_get_contents(dirname(__DIR__) . '/assets/css/ordrar.css');
si_assert(str_contains($css, '.page-ordrar button.is-busy::after'), 'spinner i knappen');

// ---- 11. Läsvägen: status på tavlan gör inga anrop per order ---------------------------
$m = si_mock();
foreach (['keep-r1' => 'COMPLETED', 'keep-r2' => 'IN_PROGRESS', 'keep-r3' => 'NEW'] as $rid => $st) {
    $m->facilities[$rid] = ['FacilityId' => $rid, 'Address' => 'Läsgatan ' . substr($rid, -1), 'FacilityStatus' => $st, 'TodosCompleted' => 1, 'TotalTodos' => 4];
}
jeab_ordrar_external_live(true);
$labels = [];
foreach (['keep-r1', 'keep-r2', 'keep-r3'] as $n => $rid) {
    $labels[] = jeab_solkoll_status_for_row(['kind' => 'sol', 'name' => 'Läs Kund' . $n, 'huvudsokande_address' => 'Annan väg 9', 'solkoll_facility_id' => $rid])['key'];
}
$perOrder = array_filter($m->calls, static fn(string $c): bool => str_starts_with($c, 'GET facilities/'));
si_assert($labels === ['slutford', 'paborjad', 'ny'], 'status läses från Solkoll för varje order');
si_assert($perOrder === [] && count($m->calls) === 1, 'tre ordrar = ett listanrop, inga anrop per order (fick ' . count($m->calls) . ')');

// ---- 12. Kortet visar en anläggning som redan finns på adressen ---------------------------
$GLOBALS['_JEAB_SOLKOLL_FACILITIES'] = [['FacilityId' => 'keep-adr1', 'Address' => 'Violvägen 4', 'Name' => '', 'FirstName' => 'Anna Margareta', 'LastName' => 'Daho', 'FacilityStatus' => 'COMPLETED']];
jeab_ordrar_external_live(true);
$card = jeab_solkoll_card_html(['kind' => 'sol', 'name' => 'Anna Margareta Daho', 'huvudsokande' => 'Anna Margareta Daho', 'huvudsokande_address' => 'Violvägen 4, Järfälla', 'inkop_lines' => []], 'jarfalla', 14);
si_assert(str_contains($card, 'facilities/keep-adr1') && str_contains($card, 'inte kopplad än') && str_contains($card, 'Uppdatera Solkoll'), 'okopplad order: kortet länkar anläggningen på adressen');
unset($GLOBALS['_JEAB_SOLKOLL_FACILITIES']);

// ---- 13. Longi-modellkod ger W; annan Longi-modell gissas inte -------------------------
foreach (['Longi LR7-54HVB-475M Svart (Lightleap)' => 475, 'LR5-54HTH-430M' => 430, 'Longi LR7-54HVD-475M Transparent' => 475, 'LR7 54HVB 475M' => 475, 'AIKO 535W MCE60Db' => 535, 'Longi X10 Scientist 535W' => 535, 'Something-475M' => 0, 'AB-12-475M' => 0, '220010745' => 0] as $txt => $w) {
    si_assert(jeab_solkoll_watt((string) $txt) === $w, "W ur «{$txt}» = {$w}");
}
si_assert(jeab_solkoll_watt('Longi LR7-54HVB-475M 480W') === 480, '«tal före W» går före Longi-koden');
si_assert(jeab_solkoll_line_bucket('Longi LR7-54HVB-475M Svart') === 'Panels', 'Longi-kod utan W räknas som panelrad');
$m = jeab_solkoll_map_lines([['title' => 'Longi LR7-54HVB-475M Svart (Lightleap)', 'sku' => '220010706', 'qty' => 10]], SI_CATALOG);
si_assert(($m['items']['Panels'][0]['Id'] ?? '') === 'cd38b2f1-1183-4ab0-aafb-1ea9e015c3c0' && $m['items']['Panels'][0]['Size'] === 475 && $m['items']['Panels'][0]['Amount'] === 10 && $m['items']['Panels'][0]['Layout'] === 'Portrait', 'Longi HVB: rätt produkt, 10 × 475 W stående');
$m = jeab_solkoll_map_lines([['title' => 'Longi LR7-54HVD-475M Transparent', 'sku' => '220010745', 'qty' => 22]], SI_CATALOG);
si_assert($m['items']['Panels'] === [] && str_contains(json_encode($m['unmatched'], JSON_UNESCAPED_UNICODE), 'modellen LR7-54HVD-475M finns inte bland favoriterna'), 'Longi HVD finns inte bland favoriterna: ingen gissning på HVB, orsaken visas');

echo "ALLA OK\n";
