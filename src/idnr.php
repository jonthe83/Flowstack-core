<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/normalize.php';

/**
 * Gemensam städning av personnummer/organisationsnummer innan de sparas
 * eller skickas till Fortnox. Tar bort alla blanksteg (även NBSP/tunna)
 * och gör om alla streckvarianter (– — − ‐ ‑ ‒ ― ﹣ － m.fl.) till '-'.
 */
if (!function_exists('jeab_idnr_clean')) {
    function jeab_idnr_clean(string $raw): string
    {
        return jeab_norm_id_clean($raw);
    }
}

/**
 * Personnummer i Fortnox-format ÅÅÅÅMMDD-NNNN. 10 siffror får sekel
 * ('+' = över 100 år). Okänt format: returnera städad sträng.
 */
if (!function_exists('jeab_pnr_normalize')) {
    function jeab_pnr_normalize(string $raw): string
    {
        $clean = jeab_idnr_clean($raw);
        $digits = jeab_norm_pnr_digits($clean);
        if (strlen($digits) === 12) {
            return substr($digits, 0, 8) . '-' . substr($digits, 8);
        }
        return $clean;
    }
}

/**
 * Organisationsnummer/personnummer i kundkortet: NNNNNN-NNNN eller
 * ÅÅÅÅMMDD-NNNN, bara städat (sekel läggs inte till).
 */
if (!function_exists('jeab_orgnr_normalize')) {
    function jeab_orgnr_normalize(string $raw): string
    {
        $clean = jeab_idnr_clean($raw);
        $digits = preg_replace('/\D+/', '', $clean) ?? '';
        if (strlen($digits) === 10 || strlen($digits) === 12) {
            return substr($digits, 0, -4) . '-' . substr($digits, -4);
        }
        return $clean;
    }
}

/** Städar alla id-fält rekursivt i ett Fortnox-payload. */
if (!function_exists('jeab_fortnox_normalize_ids')) {
    function jeab_fortnox_normalize_ids(array $body): array
    {
        foreach ($body as $k => $v) {
            if (is_array($v)) {
                $body[$k] = jeab_fortnox_normalize_ids($v);
            } elseif (is_string($v) && $v !== '') {
                if ($k === 'SocialSecurityNumber') {
                    $body[$k] = jeab_pnr_normalize($v);
                } elseif ($k === 'OrganisationNumber') {
                    $body[$k] = jeab_orgnr_normalize($v);
                }
            }
        }
        return $body;
    }
}

/**
 * Personnummer blir ÅÅMMDD-XXXX eller ÅÅÅÅMMDD-XXXX.
 * Organisationsnummer (tio siffror, tredje siffran 2–9) blir NNNNNN-NNNN.
 * Maskerade värden lämnas orörda.
 */
if (!function_exists('jeab_idnr_normalize_saved')) {
    function jeab_idnr_normalize_saved(string $raw, string $key): string
    {
        if ($raw === '' || str_contains($raw, '•') || str_contains($raw, '*')) {
            return $raw;
        }
        if (in_array($key, ['OrganisationNumber', 'orgnr', 'organisationsnummer'], true)) {
            return jeab_orgnr_normalize($raw);
        }
        $digits = preg_replace('/\D+/', '', jeab_idnr_clean($raw)) ?? '';
        if (strlen($digits) === 10 && (int) $digits[2] >= 2) {
            return jeab_orgnr_normalize($raw);
        }
        if (function_exists('jeab_contacts_sole_pnr_digits_store') && jeab_contacts_sole_pnr_digits_store() && function_exists('jeab_contacts_sole_pnr_store_value')) {
            return jeab_contacts_sole_pnr_store_value($raw);
        }
        if (function_exists('jeab_contacts_sole') && jeab_contacts_sole() && preg_match('/\A\d{12}\z/', trim($raw)) === 1) {
            return trim($raw);
        }
        return jeab_pnr_normalize($raw);
    }
}

/** Icke-ASCII-streck eller blanksteg (även NBSP). Vanligt bindestreck räknas inte. */
if (!function_exists('jeab_idnr_has_bad_separator')) {
    function jeab_idnr_has_bad_separator(string $raw): bool
    {
        if ($raw === '') {
            return false;
        }
        if (preg_match('/[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u', $raw) === 1) {
            return true;
        }
        $without = str_replace('-', '', $raw);
        if (preg_match('/\p{Pd}/u', $without) === 1) {
            return true;
        }
        return preg_match('/[\x{2212}\x{00AD}\x{FE63}]/u', $without) === 1;
    }
}

/** Städar personnummer och organisationsnummer i en sparad post, på alla nivåer. */
if (!function_exists('jeab_idnr_normalize_row')) {
    function jeab_idnr_normalize_row(array $row): array
    {
        $keys = [
            'personnummer', 'pnr', 'huvudsokande_personnummer', 'objekt_personnummer',
            'SocialSecurityNumber', 'OrganisationNumber', 'orgnr', 'organisationsnummer',
        ];
        foreach ($row as $k => $v) {
            if (is_array($v)) {
                if ((string) $k === 'owner_pnr') {
                    foreach ($v as $i => $item) {
                        if (is_string($item) && trim($item) !== '') {
                            $row[$k][$i] = jeab_idnr_normalize_saved($item, 'personnummer');
                        }
                    }
                    continue;
                }
                $row[$k] = jeab_idnr_normalize_row($v);
                continue;
            }
            if (is_string($v) && in_array((string) $k, $keys, true) && trim($v) !== '') {
                $row[$k] = jeab_idnr_normalize_saved($v, (string) $k);
            }
        }
        return $row;
    }
}
