<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);

function jeab_rot_copy_btn(): string
{
    return '<button type="button" class="rot-copy" aria-label="Kopiera">'
        . '<svg class="rot-copy-ico" viewBox="0 0 24 24" aria-hidden="true">'
        . '<rect x="9" y="9" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.75"/>'
        . '<rect x="4" y="4" width="11" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.75"/>'
        . '</svg></button>';
}

function jeab_manuell_order_clean_address(string $address): string
{
    $address = trim($address);
    if ($address === '' || !function_exists('jeab_address_strip_leading_filler')) {
        return $address;
    }

    return jeab_address_strip_leading_filler($address);
}

function jeab_rot_owners_from_input(array $input): array
{
    $names = is_array($input['owner_name'] ?? null) ? $input['owner_name'] : [];
    $pnrs = is_array($input['owner_pnr'] ?? null) ? $input['owner_pnr'] : [];
    $addrs = is_array($input['owner_address'] ?? null) ? $input['owner_address'] : [];
    $andel = is_array($input['owner_andel'] ?? null) ? $input['owner_andel'] : [];
    $lagfart = is_array($input['owner_lagfart'] ?? null) ? $input['owner_lagfart'] : [];
    $roles = is_array($input['owner_role'] ?? null) ? $input['owner_role'] : [];
    $out = [];
    $n = max(count($names), count($pnrs), count($addrs), count($andel), count($lagfart), count($roles));
    for ($i = 0; $i < $n; $i++) {
        $role = trim((string) ($roles[$i] ?? ''));
        $person = [
            'name' => trim((string) ($names[$i] ?? '')),
            'personnummer' => jeab_rot_format_pnr(trim((string) ($pnrs[$i] ?? ''))),
            'address' => jeab_manuell_order_clean_address(trim((string) ($addrs[$i] ?? ''))),
            'andel' => trim((string) ($andel[$i] ?? '')),
            'lagfart' => trim((string) ($lagfart[$i] ?? '')),
            'role' => $role === 'huvudsokande' ? 'huvudsokande' : 'medsokande',
        ];
        if ($person['name'] === '' && $person['personnummer'] === '') {
            continue;
        }
        $out[] = $person;
    }
    $hasHuvud = false;
    foreach ($out as $person) {
        if (($person['role'] ?? '') === 'huvudsokande') {
            $hasHuvud = true;
            break;
        }
    }
    if (!$hasHuvud && $out !== []) {
        $out[0]['role'] = 'huvudsokande';
    }
    return $out;
}

function jeab_rot_owners_huvud_first(array $owners): array
{
    $huvud = [];
    $rest = [];
    foreach ($owners as $owner) {
        if ($huvud === [] && (($owner['role'] ?? '') === 'huvudsokande')) {
            $huvud[] = $owner;
        } else {
            $rest[] = $owner;
        }
    }
    return $huvud === [] ? $owners : array_merge($huvud, $rest);
}

/**
 * Hittar eller skapar kontakten innan Fortnox. Flaggan av gör inget.
 * Sidan skapar ingen lokal order. $reminderId sätter contact_id när en rad redan finns.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>|null
 */
function jeab_manuell_order_sole_contact(array $input, ?JeabStore $store = null, int $reminderId = 0): ?array
{
    if (!function_exists('jeab_contacts_sole') || !jeab_contacts_sole() || !function_exists('jeab_contacts_sole_inflow_upsert')) {
        return null;
    }
    $owners = jeab_rot_owners_from_input($input);
    $huvud = [];
    foreach ($owners as $person) {
        if (is_array($person) && ($person['role'] ?? '') === 'huvudsokande') {
            $huvud = $person;
            break;
        }
    }
    if ($huvud === [] && isset($owners[0]) && is_array($owners[0])) {
        $huvud = $owners[0];
    }
    $address = jeab_manuell_order_clean_address(trim((string) ($input['address'] ?? '')));
    if ($address === '') {
        $address = jeab_manuell_order_clean_address(trim((string) ($huvud['address'] ?? '')));
    }
    try {
        $contact = jeab_contacts_sole_inflow_upsert([
            'name' => trim((string) ($huvud['name'] ?? $input['name'] ?? '')),
            'email' => trim((string) ($input['email'] ?? '')),
            'phone' => trim((string) ($input['phone'] ?? '')),
            'address' => $address,
            'company' => trim((string) ($input['co'] ?? $input['company'] ?? '')),
            'fastighetsbeteckning' => trim((string) ($input['fastighetsbeteckning'] ?? '')),
        ]);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($contact)) {
        return null;
    }
    $contactId = trim((string) ($contact['id'] ?? ''));
    if ($contactId !== '' && function_exists('jeab_contacts_sole_divert_property')) {
        try {
            jeab_contacts_sole_divert_property([
                'contact_id' => $contactId,
                'reminder_id' => $reminderId,
                'fastighetsbeteckning' => trim((string) ($input['fastighetsbeteckning'] ?? '')),
                'owners' => $owners,
                'name' => trim((string) ($huvud['name'] ?? '')),
                'email' => trim((string) ($input['email'] ?? '')),
                'phone' => trim((string) ($input['phone'] ?? '')),
                'address' => $address,
                'company' => trim((string) ($input['co'] ?? $input['company'] ?? '')),
            ]);
        } catch (Throwable) {
        }
    }
    if ($contactId !== '' && $reminderId > 0 && $store instanceof JeabStore && function_exists('jeab_contacts_sole_inflow_link')) {
        jeab_contacts_sole_inflow_link($store, $reminderId, $contactId, true);
    }

    return $contact;
}

function jeab_rot_fortnox_account(?string $company): string
{
    $company = jeab_normalize_company($company) ?? 'jarfalla';
    return $company === 'malmo' ? 'Malmo' : 'Jarfalla';
}

function jeab_manuell_order_page(): void
{
    require_once __DIR__ . '/layout.php';
    require_once __DIR__ . '/address_lookup.php';
$query = trim((string) ($_POST['q'] ?? $_GET['q'] ?? ''));
$rawQuery = trim((string) ($_POST['q_raw'] ?? ''));
$company = jeab_normalize_company($_POST['co'] ?? $_GET['co'] ?? jeab_selected_company()) ?? 'jarfalla';
$intent = trim((string) ($_POST['intent'] ?? $_GET['intent'] ?? $_POST['action'] ?? ''));
$found = jeab_rot_lookup_empty();
$looked = false;
$error = null;
$flash = null;

if ($intent === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'query' => $query,
        'suggestions' => jeab_rot_suggest_addresses($query),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    jeab_require_csrf();
}

$lookupQuery = $rawQuery !== '' ? $rawQuery : $query;
$objektId = trim((string) ($_POST['q_id'] ?? ''));

if ($intent === 'search' || ($intent === '' && $lookupQuery !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['owner_name']))) {
    $looked = true;
    if ($lookupQuery === '') {
        $error = 'Skriv en adress.';
    } else {
        $found = jeab_lm_lookup_property($lookupQuery, $objektId);
        $fetchedNote = jeab_rot_fetched_address_text($found);
        if ($fetchedNote === '' && trim((string) ($found['fastighetsbeteckning'] ?? '')) === '' && ($found['owners'] ?? []) === []) {
            $error = 'Ingen fastighet hittades.';
        }
    }
}

if ($intent === 'fortnox' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $looked = true;
    $officeOk = function_exists('jeab_company_allowed') && jeab_company_allowed($company);
    if ($officeOk && function_exists('jeab_manuell_order_sole_contact')) {
        try {
            jeab_manuell_order_sole_contact($_POST);
        } catch (Throwable) {
        }
    }
    $found = [
        'fastighetsbeteckning' => jeab_rot_format_beteckning((string) ($_POST['fastighetsbeteckning'] ?? '')),
        'address' => jeab_rot_format_display_address(jeab_manuell_order_clean_address((string) ($_POST['address'] ?? ''))),
        'owners' => jeab_rot_owners_from_input($_POST),
    ];
    if ($query === '' && $found['address'] !== '') {
        $query = $found['address'];
    }
    if (!$officeOk) {
        $error = 'Inte behörig.';
    } else {
        try {
            require_once __DIR__ . '/fortnox.php';
            $fortnoxOwners = jeab_rot_owners_huvud_first($found['owners']);
            $owners = FortnoxAPI::collectOwners(['owners' => $fortnoxOwners]);
            if ($owners === []) {
                throw new InvalidArgumentException('Inga ägare att importera.');
            }
            $fast = trim((string) ($found['fastighetsbeteckning'] ?? ''));
            if ($fast === '') {
                throw new InvalidArgumentException('Fastighetsbeteckning saknas.');
            }
            $email = trim((string) ($_POST['email'] ?? ''));
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $address = FortnoxAPI::collectAddressFromOwners(['owners' => $fortnoxOwners]);
            if ($address === []) {
                $address = FortnoxAPI::collectAddress(['address' => $found['address']]);
            }
            $api = new FortnoxAPI(jeab_rot_fortnox_account($company));
            $result = $api->addProperty($owners, $fast, $email, $phone, $address);
            $taxErr = trim((string) ($result['taxReductionError'] ?? ''));
            if ($taxErr !== '') {
                throw new InvalidArgumentException($taxErr);
            }
            $orderNo = trim((string) ($result['orderNumber'] ?? ''));
            $custNo = trim((string) ($result['customerNumber'] ?? ''));
            $flash = $orderNo !== ''
                ? 'Fortnox-order ' . $orderNo . ($custNo !== '' ? ' · kund ' . $custNo : '') . '.'
                : 'Importerad till Fortnox.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
            if (function_exists('jeab_log_app_error')) {
                jeab_log_app_error('rot', $error);
            }
        }
    }
}

$owners = is_array($found['owners'] ?? null) ? $found['owners'] : [];
$huvudIndex = 0;
foreach ($owners as $i => $owner) {
    if (($owner['role'] ?? '') === 'huvudsokande') {
        $huvudIndex = $i;
        break;
    }
}
$fast = trim((string) ($found['fastighetsbeteckning'] ?? ''));
$addr = trim((string) ($found['address'] ?? ''));
$hasHit = $fast !== '' || $owners !== [];
$copy = jeab_rot_copy_btn();

ob_start();
?>
<div class="rot-app">
    <h1>Manuell order</h1>
    <?= jeab_error_html($error) ?>
    <form id="search-form" method="post" action="<?= e(jeab_manuell_order_url()) ?>">
        <?= jeab_csrf_field() ?>
        <input type="hidden" name="intent" value="search">
        <input type="hidden" name="co" value="<?= e($company) ?>">
        <div class="address-wrapper">
            <input type="search" name="q" id="rot-q" value="<?= e($query) ?>" placeholder="Adress" autocomplete="off" inputmode="text" aria-autocomplete="list" aria-controls="rot-suggest">
            <input type="hidden" name="q_raw" value="">
            <input type="hidden" name="q_id" value="">
            <ul class="rot-suggest" id="rot-suggest" hidden role="listbox" aria-label="Adresser"></ul>
        </div>
    </form>
    <div class="loading-spinner" hidden>Söker…</div>

    <form class="rot-val" method="get" action="<?= e(jeab_manuell_order_url()) ?>">
        <input type="hidden" name="p" value="manuell-order">
        <?php if ($query !== ''): ?>
            <input type="hidden" name="q" value="<?= e($query) ?>">
        <?php endif; ?>
        <span class="rot-val-lbl">Val</span>
        <div class="chips cal-company" role="group" aria-label="Val">
            <label class="chip-opt">
                <input type="radio" name="co" value="jarfalla"<?= $company === 'jarfalla' ? ' checked' : '' ?> onchange="this.form.submit()">
                <span>Järfälla</span>
            </label>
            <label class="chip-opt">
                <input type="radio" name="co" value="malmo"<?= $company === 'malmo' ? ' checked' : '' ?> onchange="this.form.submit()">
                <span>Malmö</span>
            </label>
        </div>
    </form>

    <?php
    $fetchedNote = jeab_rot_fetched_address_text($found);
    $choiceNote = jeab_rot_lookup_choice_text($found);
    $choices = is_array($found['choices'] ?? null) ? $found['choices'] : [];
    ?>
    <?php if ($looked && $fetchedNote !== ''): ?>
        <p class="rot-fetched" role="status"><?= e($fetchedNote) ?></p>
        <?php if ($choiceNote !== ''): ?>
            <p class="rot-fetched"><?= e($choiceNote) ?></p>
            <?php foreach ($choices as $choice): ?>
                <?php
                if (!is_array($choice)) {
                    continue;
                }
                $choiceAddress = trim((string) ($choice['address'] ?? ''));
                $choiceId = trim((string) ($choice['id'] ?? ''));
                if ($choiceAddress === '') {
                    continue;
                }
                ?>
                <form method="post" action="<?= e(jeab_manuell_order_url()) ?>">
                    <?= jeab_csrf_field() ?>
                    <input type="hidden" name="intent" value="search">
                    <input type="hidden" name="co" value="<?= e($company) ?>">
                    <input type="hidden" name="q" value="<?= e($choiceAddress) ?>">
                    <input type="hidden" name="q_raw" value="<?= e($choiceAddress) ?>">
                    <input type="hidden" name="q_id" value="<?= e($choiceId) ?>">
                    <button type="submit" class="rot-action"><?= e($choiceAddress) ?></button>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($looked && $hasHit): ?>
        <form class="rot-order" method="post" action="<?= e(jeab_manuell_order_url()) ?>">
            <?= jeab_csrf_field() ?>
            <input type="hidden" name="intent" value="fortnox">
            <input type="hidden" name="co" value="<?= e($company) ?>">
            <input type="hidden" name="q" value="<?= e($query) ?>">
            <input type="hidden" name="fastighetsbeteckning" value="<?= e($fast) ?>">
            <input type="hidden" name="address" value="<?= e($addr) ?>">

            <article class="response-card">
                <h2>Fastighet</h2>
                <div class="rot-field-row">
                    <span class="rot-field-label">Beteckning</span>
                    <span class="rot-field-value"><?= e($fast) ?></span>
                    <?= $copy ?>
                </div>
                <?php if ($addr !== ''): ?>
                    <div class="rot-field-row">
                        <span class="rot-field-label">Adress</span>
                        <span class="rot-field-value"><?= e($addr) ?></span>
                        <?= $copy ?>
                    </div>
                <?php endif; ?>
            </article>

            <?php foreach ($owners as $i => $owner): ?>
                <?php
                $name = trim((string) ($owner['name'] ?? ''));
                $pnr = trim((string) ($owner['personnummer'] ?? ''));
                $ownerAddr = trim((string) ($owner['address'] ?? $addr));
                $andel = trim((string) ($owner['andel'] ?? ''));
                $lagfart = trim((string) ($owner['lagfart'] ?? ''));
                $isHuvud = $i === $huvudIndex;
                ?>
                <article class="response-card rot-owner">
                    <input type="hidden" name="owner_name[]" value="<?= e($name) ?>">
                    <input type="hidden" name="owner_pnr[]" value="<?= e($pnr) ?>">
                    <input type="hidden" name="owner_address[]" value="<?= e($ownerAddr) ?>">
                    <input type="hidden" name="owner_andel[]" value="<?= e($andel) ?>">
                    <input type="hidden" name="owner_lagfart[]" value="<?= e($lagfart) ?>">
                    <input type="hidden" name="owner_role[]" value="<?= $isHuvud ? 'huvudsokande' : 'medsokande' ?>">
                    <header class="rot-owner-head">
                        <strong>Ägare <?= (int) $i + 1 ?></strong>
                        <span class="rot-owner-pills" role="group" aria-label="Roll">
                            <button type="button" class="rot-role<?= $isHuvud ? ' is-selected' : '' ?>" data-role="huvudsokande" aria-pressed="<?= $isHuvud ? 'true' : 'false' ?>">Huvudsökande</button>
                            <button type="button" class="rot-role<?= $isHuvud ? '' : ' is-selected' ?>" data-role="medsokande" aria-pressed="<?= $isHuvud ? 'false' : 'true' ?>">Medsökande</button>
                        </span>
                    </header>
                    <div class="rot-field-row">
                        <span class="rot-field-label">Namn</span>
                        <span class="rot-field-value" data-field="name"><?= e($name) ?></span>
                        <?= $copy ?>
                    </div>
                    <div class="rot-field-row">
                        <span class="rot-field-label">Personnummer</span>
                        <span class="rot-field-value" data-field="pnr" x-apple-data-detectors="false"><?= e($pnr) ?></span>
                        <?= $copy ?>
                    </div>
                    <div class="rot-field-row">
                        <span class="rot-field-label">Adress</span>
                        <span class="rot-field-value" data-field="address"><?= e($ownerAddr) ?></span>
                        <?= $copy ?>
                    </div>
                    <div class="rot-field-row"<?= $andel === '' ? ' hidden' : '' ?>>
                        <span class="rot-field-label">Andel</span>
                        <span class="rot-field-value" data-field="andel"><?= e($andel) ?></span>
                        <?= $copy ?>
                    </div>
                    <div class="rot-field-row"<?= $lagfart === '' ? ' hidden' : '' ?>>
                        <span class="rot-field-label">Lagfart</span>
                        <span class="rot-field-value" data-field="lagfart"><?= e($lagfart) ?></span>
                        <?= $copy ?>
                    </div>
                </article>
            <?php endforeach; ?>

            <div class="rot-contact-row">
                <label class="rot-field-label" for="email">E-post</label>
                <input id="email" type="email" name="email" value="<?= e((string) ($_POST['email'] ?? '')) ?>" autocomplete="off">
            </div>
            <div class="rot-fortnox-row">
                <button class="rot-action" type="submit">Skapa Fortnox-order</button>
                <?php if ($flash): ?>
                    <p class="rot-fortnox-result" role="status"><?= e($flash) ?></p>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>
    <?= jeab_fortnox_repair_link_html() ?>
</div>
<?php
jeab_layout('Manuell order · JEAB', 'manuell-order', ob_get_clean());
}
