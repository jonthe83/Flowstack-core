<?php
declare(strict_types=1);

/**
 * Konfigurerbar adress for testleads och egen inkorg.
 * config-nyckel 'test_lead_address' (standard inkorg@example.test).
 * Egen inkorg: <lokal>@<doman> och <lokal>+inkorg@<doman>.
 * Testlead: <lokal>+test-*@<doman> (eller underdoman).
 */
if (!function_exists('jeab_test_lead_address')) {
    function jeab_test_lead_address(): string
    {
        $addr = '';
        if (isset($GLOBALS['_JEAB_TEST_LEAD_ADDRESS']) && is_string($GLOBALS['_JEAB_TEST_LEAD_ADDRESS'])) {
            $addr = $GLOBALS['_JEAB_TEST_LEAD_ADDRESS'];
        } elseif (function_exists('jeab_config')) {
            try {
                $cfg = jeab_config();
                $addr = is_string($cfg['test_lead_address'] ?? null) ? $cfg['test_lead_address'] : '';
            } catch (\Throwable $e) {
                $addr = '';
            }
        }
        $addr = strtolower(trim($addr));
        if ($addr === '' || substr_count($addr, '@') !== 1) {
            $addr = 'inkorg@example.test';
        }
        return $addr;
    }

    function jeab_test_lead_local(): string
    {
        return explode('@', jeab_test_lead_address())[0];
    }

    function jeab_test_lead_domain(): string
    {
        return explode('@', jeab_test_lead_address())[1];
    }

    function jeab_test_lead_domain_match(string $domain): bool
    {
        $d = jeab_test_lead_domain();
        $domain = strtolower($domain);
        return $domain === $d || str_ends_with($domain, '.' . $d);
    }

    /** @return list<string> egna inkorgsadresser */
    function jeab_test_lead_own_addresses(): array
    {
        $l = jeab_test_lead_local();
        $d = jeab_test_lead_domain();
        return [$l . '@' . $d, $l . '+inkorg@' . $d];
    }

    function jeab_test_lead_test_prefix(): string
    {
        return jeab_test_lead_local() . '+test-';
    }
}
