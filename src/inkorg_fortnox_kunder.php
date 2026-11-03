<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/fortnox.php';

/**
 * inbox.fortnox_kunder: läser kundlistan i ett bolag.
 * Bara GET /customers via FortnoxAPI::listAll. Inga fakturor, inga skrivningar, ingen ny OAuth.
 * Svaret är en vitlista. Organisationsnummer och övriga fält lämnas kvar hos Fortnox och loggas inte.
 */

function jeab_inkorg_fortnox_kunder(array $input): array
{
    if (!function_exists('jeab_inkorg_hook_authorized') || !jeab_inkorg_hook_authorized()) {
        return ['ok' => false, 'error' => 'Fel token.', 'status' => 401];
    }
    $account = trim((string) ($input['account'] ?? ''));
    if ($account !== 'Jarfalla' && $account !== 'Malmo') {
        return ['ok' => false, 'error' => 'Ogiltigt bolag.', 'status' => 400];
    }
    try {
        $api = jeab_inkorg_fortnox_kunder_client($account);
        $rows = $api->listAll('/customers', 'Customers');
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Kundlistan gick inte att hämta.', 'status' => 400];
    }
    if (!is_array($rows)) {
        return ['ok' => false, 'error' => 'Kundlistan gick inte att hämta.', 'status' => 400];
    }
    $customers = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $customers[] = jeab_inkorg_fortnox_kund_row($row, $account);
    }
    return [
        'ok' => true,
        'account' => $account,
        'count' => count($customers),
        'customers' => $customers,
    ];
}

function jeab_inkorg_fortnox_kunder_client(string $account): object
{
    $factory = $GLOBALS['_JEAB_INKORG_FORTNOX_KUNDER_FACTORY'] ?? null;
    if ($factory !== null) {
        if (!is_callable($factory)) {
            throw new RuntimeException('Fortnox-klienten saknas.');
        }
        $api = $factory($account);
        if (!is_object($api) || !method_exists($api, 'listAll')) {
            throw new RuntimeException('Fortnox-klienten saknas.');
        }
        return $api;
    }
    return new FortnoxAPI($account);
}

function jeab_inkorg_fortnox_kund_row(array $row, string $bolag): array
{
    return [
        'CustomerNumber' => jeab_inkorg_fortnox_kund_text($row, 'CustomerNumber'),
        'Name' => jeab_inkorg_fortnox_kund_text($row, 'Name'),
        'Email' => jeab_inkorg_fortnox_kund_text($row, 'Email'),
        'Phone1' => jeab_inkorg_fortnox_kund_text($row, 'Phone1'),
        'Phone2' => jeab_inkorg_fortnox_kund_text($row, 'Phone2'),
        'Address1' => jeab_inkorg_fortnox_kund_text($row, 'Address1'),
        'ZipCode' => jeab_inkorg_fortnox_kund_text($row, 'ZipCode'),
        'City' => jeab_inkorg_fortnox_kund_city($row),
        'bolag' => $bolag,
    ];
}

function jeab_inkorg_fortnox_kund_city(array $row): string
{
    $city = jeab_inkorg_fortnox_kund_text($row, 'City');
    if (strcasecmp($city, 'Sverige') === 0 || strcasecmp($city, 'Sweden') === 0) {
        return '';
    }
    return $city;
}

function jeab_inkorg_fortnox_kund_text(array $row, string $key): string
{
    $value = $row[$key] ?? '';
    if (is_string($value)) {
        return trim($value);
    }
    if (is_int($value) || is_float($value)) {
        return trim((string) $value);
    }
    return '';
}
