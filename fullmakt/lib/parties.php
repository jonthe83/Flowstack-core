<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/shield.php';
jeab_deny_if_direct(__FILE__);

function fullmakt_party_keys(): array
{
    return [
        'namn',
        'personnummer',
        'telefon',
        'epost',
        'adress',
        'postnummer',
        'ort',
        'anlaggningsadress',
        'foretagsnamn',
    ];
}

function fullmakt_snapshot_party(array $source): array
{
    $party = [];
    foreach (fullmakt_party_keys() as $key) {
        $party[$key] = trim((string) ($source[$key] ?? ''));
    }
    return $party;
}

function fullmakt_party_usable(array $party): bool
{
    return trim((string) ($party['namn'] ?? '')) !== ''
        || trim((string) ($party['personnummer'] ?? '')) !== '';
}

function fullmakt_med_parties(array $doc): array
{
    $parter = is_array($doc['parter'] ?? null) ? $doc['parter'] : [];
    $list = $parter['medsokande'] ?? [];
    if (!is_array($list) || $list === []) {
        return [];
    }
    $isList = array_keys($list) === range(0, count($list) - 1);
    if (!$isList) {
        $list = [$list];
    }
    $out = [];
    foreach ($list as $party) {
        if (!is_array($party)) {
            continue;
        }
        $snap = fullmakt_snapshot_party($party);
        if (!fullmakt_party_usable($snap)) {
            continue;
        }
        $out[] = $snap;
    }
    return $out;
}

function fullmakt_load_jeab(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    $boot = dirname(__DIR__, 2) . '/src/bootstrap.php';
    if (!is_file($boot)) {
        return;
    }
    try {
        require_once $boot;
        // Utan jeab_auth laddar bootstrap bara kärnan. datastore.php drar in
        // jeab_cutover_sql, annars blir fullmakt_sql_active() falsk och
        // /fullmakt/s/{token} svarar 404 för rader som bara finns i SQL.
        if (!function_exists('jeab_cutover_sql')) {
            $store = dirname($boot) . '/datastore.php';
            if (is_file($store)) {
                require_once $store;
            }
        }
    } catch (Throwable $e) {
        if (function_exists('fullmakt_log')) {
            fullmakt_log('error.log', 'jeab ' . $e->getMessage());
        }
    }
}

function fullmakt_lookup_order_anlaggnings_id(string $token): string
{
    $token = trim($token);
    if ($token === '') {
        return '';
    }
    if (!function_exists('jeab_ellevio_fullmakt_anlaggnings_id_for_token')) {
        fullmakt_load_jeab();
    }
    if (!function_exists('jeab_ellevio_fullmakt_anlaggnings_id_for_token')) {
        return '';
    }
    try {
        return fullmakt_anlaggnings_id_digits(jeab_ellevio_fullmakt_anlaggnings_id_for_token($token));
    } catch (Throwable $e) {
        if (function_exists('fullmakt_log')) {
            fullmakt_log('error.log', 'anlaggnings-id ' . $e->getMessage());
        }
        return '';
    }
}

function fullmakt_backfill_anlaggnings_id(array $doc): array
{
    if (!fullmakt_anlaggnings_id_required($doc)) {
        return $doc;
    }
    // Nyckeln sätts när fullmakten skapas, även om ordern saknar id.
    // Då slår sidan inte upp ordern (det skulle dra in hela JEAB-appen).
    // Saknas nyckeln, som på äldre dokument, hämtas id:t från ordern en gång.
    // Ett id som fylls i efter utskicket skrivs på dokumentet från Ordrar vid omskick.
    if (array_key_exists('anlaggnings_id', $doc)) {
        $doc['anlaggnings_id'] = fullmakt_anlaggnings_id_digits((string) $doc['anlaggnings_id']);
        return $doc;
    }
    $fromOrder = fullmakt_lookup_order_anlaggnings_id((string) ($doc['token'] ?? ''));
    if ($fromOrder === '') {
        return $doc;
    }
    $doc['anlaggnings_id'] = $fromOrder;
    return $doc;
}

function fullmakt_lookup_med_parties(string $token): array
{
    if ($token === '') {
        return [];
    }
    fullmakt_load_jeab();
    if (!function_exists('jeab_ellevio_fullmakt_med_parties_for_token')) {
        return [];
    }
    try {
        $found = jeab_ellevio_fullmakt_med_parties_for_token($token);
    } catch (Throwable $e) {
        if (function_exists('fullmakt_log')) {
            fullmakt_log('error.log', 'medsokande ' . $e->getMessage());
        }
        return [];
    }
    return is_array($found) ? $found : [];
}

function fullmakt_ensure_parties(array $doc, ?callable $medLookup = null): array
{
    $parter = is_array($doc['parter'] ?? null) ? $doc['parter'] : [];
    $part = (string) ($doc['part'] ?? 'huvudsokande');
    if ($part !== 'medsokande') {
        if (trim((string) ($doc['invite_epost'] ?? '')) === '') {
            $doc['invite_epost'] = trim((string) ($doc['epost'] ?? ''));
        }
        if (trim((string) ($doc['invite_namn'] ?? '')) === '') {
            $doc['invite_namn'] = trim((string) ($doc['namn'] ?? ''));
        }
        $huvud = is_array($parter['huvudsokande'] ?? null) ? $parter['huvudsokande'] : [];
        if (!fullmakt_party_usable($huvud)) {
            $parter['huvudsokande'] = fullmakt_snapshot_party($doc);
        }
    }
    $doc['parter'] = $parter;
    if (!array_key_exists('medsokande', $parter)) {
        if ($medLookup === null) {
            $medLookup = 'fullmakt_lookup_med_parties';
        }
        $found = $medLookup((string) ($doc['token'] ?? ''));
        $clean = [];
        if (is_array($found)) {
            foreach ($found as $party) {
                if (!is_array($party)) {
                    continue;
                }
                $snap = fullmakt_snapshot_party($party);
                if (fullmakt_party_usable($snap)) {
                    $clean[] = $snap;
                }
            }
        }
        $parter['medsokande'] = $clean;
        $doc['parter'] = $parter;
    }
    if (trim((string) ($doc['part'] ?? '')) === '') {
        $doc['part'] = 'huvudsokande';
    }
    return fullmakt_sole_fill($doc);
}

/**
 * Fyller tomma partfält från kontaktens ägare. Redan ifyllda fält lämnas,
 * utom personnummer och fastighetsbeteckning: de läses från kontakten när
 * CONTACTS_SOLE är på, och den nuvarande källan gäller när kontakten saknar värdet.
 * Signerat dokument och flaggan av lämnas orörda.
 *
 * @param array<string, mixed> $doc
 * @return array<string, mixed>
 */
function fullmakt_sole_fill(array $doc): array
{
    if (($doc['status'] ?? '') === 'signed') {
        return $doc;
    }
    if (!function_exists('jeab_contacts_sole') || !jeab_contacts_sole()) {
        return $doc;
    }
    fullmakt_load_jeab();
    if (!function_exists('jeab_contacts_sole_recipient') || !function_exists('jeab_purchase_all') || !function_exists('jeab_contacts_sole_recipient_contact')) {
        return $doc;
    }
    $token = trim((string) ($doc['token'] ?? ''));
    if ($token === '') {
        return $doc;
    }
    $order = null;
    try {
        foreach (jeab_purchase_all() as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row['fullmakt_token'] ?? '')) !== $token) {
                continue;
            }
            $order = $row;
            break;
        }
    } catch (Throwable $e) {
        if (function_exists('fullmakt_log')) {
            fullmakt_log('error.log', 'kontakt ' . $e->getMessage());
        }

        return $doc;
    }
    if (!is_array($order)) {
        return $doc;
    }
    $contact = jeab_contacts_sole_recipient_contact($order);
    if (!is_array($contact) || !function_exists('jeab_contacts_sole_view_from_contact')) {
        return $doc;
    }
    $view = jeab_contacts_sole_view_from_contact($contact);
    $rec = jeab_contacts_sole_recipient($order);
    $fill = static function (array &$target, string $key, string $value): void {
        if (trim($value) === '' || trim((string) ($target[$key] ?? '')) !== '') {
            return;
        }
        $target[$key] = $value;
    };
    $fromContact = function_exists('jeab_contacts_sole_contact_person_pnr')
        ? jeab_contacts_sole_contact_person_pnr($contact)
        : '';
    $docPnr = trim((string) ($doc['personnummer'] ?? ''));
    $orderPnr = function_exists('jeab_contacts_sole_pnr_text')
        ? jeab_contacts_sole_pnr_text((string) ($order['personnummer'] ?? ''))
        : trim((string) ($order['personnummer'] ?? ''));
    $chosenPnr = $fromContact !== '' ? $fromContact : ($docPnr !== '' ? $docPnr : $orderPnr);
    if ($chosenPnr !== '') {
        $doc['personnummer'] = $chosenPnr;
    }
    $contactFast = trim((string) ($contact['fastighetsbeteckning'] ?? ''));
    if ($contactFast === '') {
        $contactFast = trim((string) ($doc['fastighetsbeteckning'] ?? ''));
    }
    if ($contactFast === '') {
        $contactFast = trim((string) ($order['fastighetsbeteckning'] ?? ''));
    }
    if ($contactFast !== '') {
        $doc['fastighetsbeteckning'] = $contactFast;
    }
    if (trim((string) ($view['name'] ?? '')) !== '') {
        $fill($doc, 'namn', $rec['name']);
    }
    if (trim((string) ($view['email'] ?? '')) !== '') {
        $fill($doc, 'epost', $rec['email']);
    }
    if (trim((string) ($view['phone'] ?? '')) !== '') {
        $fill($doc, 'telefon', $rec['phone']);
    }
    if (trim((string) ($view['street'] ?? '')) !== '') {
        $fill($doc, 'adress', $rec['street']);
    }
    if (trim((string) ($view['postal'] ?? '')) !== '') {
        $fill($doc, 'postnummer', $rec['postal']);
    }
    if (trim((string) ($view['city'] ?? '')) !== '') {
        $fill($doc, 'ort', $rec['city']);
    }
    $inviteMail = trim((string) ($doc['epost'] ?? ''));
    if ($inviteMail !== '' && trim((string) ($doc['invite_epost'] ?? '')) === '') {
        $doc['invite_epost'] = $inviteMail;
    }
    $inviteName = trim((string) ($doc['namn'] ?? ''));
    if ($inviteName !== '' && trim((string) ($doc['invite_namn'] ?? '')) === '') {
        $doc['invite_namn'] = $inviteName;
    }
    $parter = is_array($doc['parter'] ?? null) ? $doc['parter'] : [];
    $hadHuvud = is_array($parter['huvudsokande'] ?? null);
    $huvud = $hadHuvud ? $parter['huvudsokande'] : [];
    if (trim((string) ($view['name'] ?? '')) !== '') {
        $fill($huvud, 'namn', $rec['name']);
    }
    if (trim((string) ($view['email'] ?? '')) !== '') {
        $fill($huvud, 'epost', $rec['email']);
    }
    if (trim((string) ($view['phone'] ?? '')) !== '') {
        $fill($huvud, 'telefon', $rec['phone']);
    }
    if (trim((string) ($view['street'] ?? '')) !== '') {
        $fill($huvud, 'adress', $rec['street']);
    }
    if (trim((string) ($view['postal'] ?? '')) !== '') {
        $fill($huvud, 'postnummer', $rec['postal']);
    }
    if (trim((string) ($view['city'] ?? '')) !== '') {
        $fill($huvud, 'ort', $rec['city']);
    }
    if ($fromContact !== '') {
        $huvud['personnummer'] = $fromContact;
    } elseif (trim((string) ($huvud['personnummer'] ?? '')) === '' && $chosenPnr !== '') {
        $huvud['personnummer'] = $chosenPnr;
    }
    if ($hadHuvud || $huvud !== []) {
        $parter['huvudsokande'] = $huvud;
    }
    $med = $parter['medsokande'] ?? null;
    if (is_array($med)) {
        foreach ($med as $i => $party) {
            if (!is_array($party)) {
                continue;
            }
            $name = trim((string) ($party['namn'] ?? ''));
            if ($name === '') {
                continue;
            }
            $match = null;
            foreach ($rec['owners'] as $owner) {
                if (!is_array($owner)) {
                    continue;
                }
                if (strcasecmp($name, trim((string) ($owner['name'] ?? ''))) === 0) {
                    $match = $owner;
                    break;
                }
            }
            if (!is_array($match)) {
                continue;
            }
            $fill($party, 'epost', (string) ($match['email'] ?? ''));
            $fill($party, 'telefon', (string) ($match['phone'] ?? ''));
            $fill($party, 'adress', (string) ($match['address'] ?? ''));
            $ownerPnr = function_exists('jeab_contacts_sole_contact_owner_pnr')
                ? jeab_contacts_sole_contact_owner_pnr($contact, $name)
                : '';
            if ($ownerPnr !== '') {
                $party['personnummer'] = $ownerPnr;
            }
            $med[$i] = $party;
        }
        $parter['medsokande'] = $med;
    }
    $doc['parter'] = $parter;

    return $doc;
}

function fullmakt_apply_party(array $doc, string $which, int $index = 0): array
{
    if ($which === 'medsokande') {
        $list = fullmakt_med_parties($doc);
        if (!isset($list[$index])) {
            return $doc;
        }
        $party = $list[$index];
        $doc['medsokande_index'] = $index;
        $doc['part'] = 'medsokande';
    } else {
        $parter = is_array($doc['parter'] ?? null) ? $doc['parter'] : [];
        $huvud = is_array($parter['huvudsokande'] ?? null) ? fullmakt_snapshot_party($parter['huvudsokande']) : [];
        if (!fullmakt_party_usable($huvud)) {
            return $doc;
        }
        $party = $huvud;
        $doc['part'] = 'huvudsokande';
    }
    foreach (fullmakt_party_keys() as $key) {
        $doc[$key] = $party[$key];
    }
    return $doc;
}

function fullmakt_party_switch_html(array $doc): string
{
    $meds = fullmakt_med_parties($doc);
    if ($meds === []) {
        return '';
    }
    $part = (string) ($doc['part'] ?? 'huvudsokande');
    $onMed = $part === 'medsokande';
    $parter = is_array($doc['parter'] ?? null) ? $doc['parter'] : [];
    $huvud = is_array($parter['huvudsokande'] ?? null) ? fullmakt_snapshot_party($parter['huvudsokande']) : [];
    $huvudNamn = trim((string) ($huvud['namn'] ?? ''));
    $index = (int) ($doc['medsokande_index'] ?? 0);
    if (!isset($meds[$index])) {
        $index = 0;
    }
    $active = $onMed ? $meds[$index] : $meds[0];
    $medNamn = trim((string) ($active['namn'] ?? ''));
    $missingContact = trim((string) ($active['telefon'] ?? '')) === ''
        && trim((string) ($active['epost'] ?? '')) === '';

    ob_start();
    ?>
<style>
.part-byte { border: 1px solid #0A84FF; }
.part-byte .part-now { margin: 0 0 8px; }
.part-byte .choices { display: grid; gap: 8px; margin: 0 0 12px; }
.part-byte .choices label { display: flex; gap: 8px; align-items: flex-start; }
.part-byte .primary { margin-top: 4px; }
.part-byte .ghost { display: block; width: 100%; margin-top: 8px; }
</style>
<section class="card part-byte" id="byt-till-medsokande">
    <h2>Vem står på fullmakten?</h2>
    <?php if ($onMed): ?>
        <p class="part-now">Blanketten står på medsökande <strong><?= e($medNamn) ?></strong>.</p>
        <p class="help">Namn, personnummer, adress, telefon, e-post och namnförtydligande är medsökandes. Huvudsökande står inte på avtalet.</p>
        <?php if ($missingContact): ?>
            <p class="help">Medsökande har inget telefonnummer eller ingen e-post på ordern. De fälten lämnas tomma.</p>
        <?php endif; ?>
        <form method="post" action="">
            <input type="hidden" name="action" value="byt-part">
            <input type="hidden" name="part" value="huvudsokande">
            <button type="submit" class="ghost">Byt tillbaka till huvudsökande<?= $huvudNamn !== '' ? ' ' . e($huvudNamn) : '' ?></button>
        </form>
    <?php else: ?>
        <p class="part-now">Blanketten är ifylld med huvudsökande<?= $huvudNamn !== '' ? ' <strong>' . e($huvudNamn) . '</strong>' : '' ?>.</p>
        <?php if (count($meds) === 1): ?>
            <p class="help">Medsökande<?= $medNamn !== '' ? ' ' . e($medNamn) : '' ?> kan stå på elabonnemanget. Byt innan du signerar, så namn, personnummer, adress, telefon, e-post och namnförtydligande blir medsökandes. Huvudsökande syns då inte på blanketten.</p>
        <?php else: ?>
            <p class="help">Välj medsökande innan du signerar. Hela blanketten — namn, personnummer, adress, telefon, e-post och namnförtydligande — byts till den personen. Huvudsökande syns då inte på avtalet.</p>
        <?php endif; ?>
        <?php if ($missingContact && count($meds) === 1): ?>
            <p class="help">Medsökande har inget telefonnummer eller ingen e-post på ordern. De fälten lämnas tomma.</p>
        <?php endif; ?>
        <p class="help">Signeringslänken går fortfarande till huvudsökandes e-post. Bytet ändrar bara blanketten.</p>
        <form method="post" action="">
            <input type="hidden" name="action" value="byt-part">
            <input type="hidden" name="part" value="medsokande">
            <?php if (count($meds) === 1): ?>
                <input type="hidden" name="med_index" value="0">
                <button type="submit" class="primary" id="byt-medsokande">Byt till medsökande<?= $medNamn !== '' ? ' ' . e($medNamn) : '' ?></button>
            <?php else: ?>
                <div class="choices" role="radiogroup" aria-label="Medsökande">
                    <?php foreach ($meds as $i => $med): ?>
                        <label>
                            <input type="radio" name="med_index" value="<?= (int) $i ?>"<?= $i === 0 ? ' checked' : '' ?>>
                            <span><?= e(trim((string) ($med['namn'] ?? '')) !== '' ? (string) $med['namn'] : 'Medsökande ' . ((int) $i + 1)) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="primary" id="byt-medsokande">Byt till medsökande</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</section>
    <?php
    return (string) ob_get_clean();
}
