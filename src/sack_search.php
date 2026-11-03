<?php

declare(strict_types=1);

/**
 * Hämta säck: förslag medan man skriver.
 *
 * Söker i påminnelser, kontakter (namn, mejl, telefon, adress) och ordrar
 * (kund, ordernummer). Varje träff har typ och etikett så rutan visar var den
 * kommer ifrån. En källa som kastar loggas och rapporteras i "errors", men de
 * andra källorna svarar ändå. Inget faller tyst.
 */

/** @return array{0:string,1:string} [gemener, bara siffror] */
function jeab_sack_search_needles(string $query): array
{
    $q = trim($query);
    $lower = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $digits = preg_replace('/\D+/', '', $q) ?? '';

    return [$lower, $digits];
}

function jeab_sack_search_lower(string $s): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/** Svenskt mobilnummer med 0 eller +46 matchar på samma siffror. */
function jeab_sack_search_phone_digits(string $phone): string
{
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    if (str_starts_with($d, '46') && strlen($d) >= 10) {
        $d = '0' . substr($d, 2);
    }

    return $d;
}

/**
 * Text eller telefon träffar? Siffror (minst 3) jämförs mot telefon/ordernummer utan mellanslag.
 *
 * @param list<string> $texts
 * @param list<string> $numbers
 */
function jeab_sack_search_match(string $lower, string $digits, array $texts, array $numbers = []): bool
{
    if ($lower !== '' && str_contains(jeab_sack_search_lower(implode(' ', $texts)), $lower)) {
        return true;
    }
    $needle = jeab_sack_search_phone_digits($digits);
    if (strlen($digits) >= 3 && strlen($digits) === strlen(preg_replace('/[\s\-+()]/', '', $lower) ?? '')) {
        foreach ($numbers as $n) {
            $hay = jeab_sack_search_phone_digits((string) $n);
            if ($hay !== '' && ($needle !== '' && str_contains($hay, $needle) || str_contains($hay, $digits))) {
                return true;
            }
        }
    }

    return false;
}

/** Gata och ort ur en hel adress "Gata 1, 123 45 Ort". @return array{0:string,1:string} */
function jeab_sack_search_split_address(string $address): array
{
    $address = trim($address);
    if ($address === '') {
        return ['', ''];
    }
    $parts = array_map('trim', explode(',', $address));
    $street = (string) array_shift($parts);
    $city = trim((string) (preg_replace('/^\d{3}\s?\d{2}\s*/', '', implode(', ', $parts)) ?? ''));

    return [$street, $city];
}

/**
 * @return array<string,mixed>
 */
function jeab_sack_search_hit(string $type, string $id, string $name, string $street, string $city, string $phone, string $email = '', string $ref = ''): array
{
    $labels = ['reminder' => 'Påminnelse', 'contact' => 'Kontakt', 'order' => 'Order'];
    $parts = array_values(array_filter([$name, $street, $city], static fn(string $p): bool => $p !== ''));

    return [
        'type' => $type,
        'type_label' => $labels[$type] ?? $type,
        'id' => $id,
        'ref' => $ref,
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'address' => trim($street . ($city !== '' ? ', ' . $city : '')),
        'street' => $street,
        'city' => $city,
        'label' => implode(' · ', $parts),
    ];
}

/** @return list<array<string,mixed>> */
function jeab_sack_search_contacts(string $query, string $company, int $limit): array
{
    [$lower, $digits] = jeab_sack_search_needles($query);
    $hits = [];
    foreach (jeab_kontakter_load() as $id => $row) {
        if (!is_array($row) || !empty($row['deleted']) || !empty($row['merged_into'])) {
            continue;
        }
        $rowCompany = trim((string) ($row['company'] ?? ''));
        if ($rowCompany !== '' && (jeab_normalize_company($rowCompany) ?? $rowCompany) !== $company) {
            continue;
        }
        $owners = is_array($row['owners'] ?? null) ? $row['owners'] : [];
        $street = trim((string) ($row['street'] ?? ''));
        $city = trim((string) ($row['city'] ?? ''));
        $postal = trim((string) ($row['postal_code'] ?? ''));
        $names = [];
        $emails = [];
        $phones = [];
        foreach ($owners as $o) {
            if (!is_array($o)) {
                continue;
            }
            $names[] = trim((string) ($o['name'] ?? ''));
            $emails[] = trim((string) ($o['email'] ?? ''));
            $phones[] = trim((string) ($o['phone'] ?? ''));
        }
        $names[] = trim((string) ($row['name'] ?? ''));
        $emails[] = trim((string) ($row['email'] ?? ''));
        $phones[] = trim((string) ($row['phone'] ?? ''));
        $phones[] = trim((string) ($row['phone2'] ?? ''));
        $names = array_values(array_filter(array_unique($names)));
        $emails = array_values(array_filter(array_unique($emails)));
        $phones = array_values(array_filter(array_unique($phones)));
        if (!jeab_sack_search_match($lower, $digits, array_merge($names, $emails, [$street, $postal, $city]), $phones)) {
            continue;
        }
        $hits[] = jeab_sack_search_hit('contact', (string) $id, $names[0] ?? '', $street, $city, $phones[0] ?? '', $emails[0] ?? '');
        if (count($hits) >= $limit) {
            break;
        }
    }

    return $hits;
}

/** @return list<array<string,mixed>> */
function jeab_sack_search_orders(string $query, string $company, int $limit): array
{
    [$lower, $digits] = jeab_sack_search_needles($query);
    $hits = [];
    foreach (jeab_purchase_all() as $key => $row) {
        if (!is_array($row) || !empty($row['deleted'])) {
            continue;
        }
        if ((jeab_normalize_company($row['company'] ?? null) ?? 'jarfalla') !== $company) {
            continue;
        }
        $id = (string) ($row['reminder_id'] ?? $key);
        $name = trim((string) ($row['name'] ?? $row['huvudsokande'] ?? ''));
        $address = trim((string) ($row['address'] ?? $row['huvudsokande_address'] ?? ''));
        $phone = trim((string) ($row['phone'] ?? ''));
        $email = trim((string) ($row['email'] ?? ''));
        $fortnox = trim((string) ($row['fortnox_order'] ?? ''));
        $numbers = array_values(array_filter([$phone, $id, $fortnox]));
        if (!jeab_sack_search_match($lower, $digits, [$name, $address, $email, $fortnox, '#' . $id], $numbers)) {
            continue;
        }
        [$street, $city] = jeab_sack_search_split_address($address);
        $kind = function_exists('jeab_purchase_kind_label') && (string) ($row['kind'] ?? '') !== ''
            ? jeab_purchase_kind_label((string) $row['kind'])
            : '';
        $ref = trim('#' . $id . ($fortnox !== '' ? ' · Fortnox ' . $fortnox : '') . ($kind !== '' ? ' · ' . $kind : ''));
        $hits[] = jeab_sack_search_hit('order', $id, $name, $street, $city, $phone, $email, $ref);
        if (count($hits) >= $limit) {
            break;
        }
    }

    return $hits;
}

/**
 * Alla källor. Påminnelser först (som förut), sedan kontakter och ordrar.
 * Samma person med samma gata visas en gång, med första källans typ.
 *
 * @return array{hits:list<array<string,mixed>>,errors:list<string>}
 */
function jeab_sack_search(JeabStore $store, string $query, ?string $company = null, int $perSource = 8): array
{
    $q = trim($query);
    $len = function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q);
    if ($len < 3) {
        return ['hits' => [], 'errors' => []];
    }
    $company = jeab_normalize_company($company) ?? jeab_selected_company();
    $sources = [
        'Påminnelser' => static function () use ($store, $q, $company, $perSource): array {
            $out = [];
            foreach (jeab_search_reminders_for_sack($store, $q, $company, $perSource) as $h) {
                $out[] = jeab_sack_search_hit('reminder', (string) $h['id'], (string) $h['name'], (string) $h['street'], (string) $h['city'], (string) $h['phone']);
            }
            // Telefon: påminnelsesöket tittar bara på namn och adress.
            if ($out === [] && strlen(preg_replace('/\D+/', '', $q) ?? '') >= 3) {
                [$lower, $digits] = jeab_sack_search_needles($q);
                foreach ($store->listReminders(true) as $row) {
                    if (!is_array($row) || jeab_reminder_company($row) !== $company) {
                        continue;
                    }
                    $view = jeab_reminder_shown_customer($row);
                    if (!jeab_sack_search_match('', $digits, [], [(string) $view['phone']])) {
                        continue;
                    }
                    $address = $view['display'] !== '' ? $view['display'] : jeab_reminder_address_source($row);
                    $street = $view['street'] !== '' ? $view['street'] : jeab_reminder_street($address);
                    $out[] = jeab_sack_search_hit('reminder', (string) ($row['id'] ?? ''), (string) $view['name'], $street, (string) $view['city'], (string) $view['phone']);
                    if (count($out) >= $perSource) {
                        break;
                    }
                }
            }

            return $out;
        },
        'Kontakter' => static fn(): array => jeab_sack_search_contacts($q, $company, $perSource),
        'Ordrar' => static fn(): array => jeab_sack_search_orders($q, $company, $perSource),
    ];
    $hits = [];
    $errors = [];
    $seen = [];
    foreach ($sources as $label => $fn) {
        try {
            $rows = $fn();
        } catch (Throwable $e) {
            $errors[] = $label;
            if (function_exists('jeab_log_app_error')) {
                jeab_log_app_error('sack_search', $label . ': ' . $e->getMessage());
            } else {
                error_log('sack_search ' . $label . ': ' . $e->getMessage());
            }
            continue;
        }
        foreach ($rows as $h) {
            $key = jeab_sack_search_lower($h['name'] . '|' . $h['street']);
            if ($h['name'] !== '' && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $hits[] = $h;
        }
    }

    return ['hits' => $hits, 'errors' => $errors];
}
