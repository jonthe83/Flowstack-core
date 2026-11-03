<?php

declare(strict_types=1);

require_once __DIR__ . '/shield.php';
jeab_deny_if_direct(__FILE__);
require_once __DIR__ . '/fortnox.php';
require_once __DIR__ . '/hook_scoped_tokens.php';

/**
 * Tokengrind för fortnox.*-hookar.
 * Inkommande webhook-token släpper igenom. Annars krävs ett scoped token för just den åtgärden.
 * Inkorgsnyckeln får bara fortnox.orders_read.
 */
function jeab_fortnox_lon_hook_authorized(JeabStore $store, ?string $action = null): bool
{
    $token = function_exists('jeab_ensure_incoming_token') ? jeab_ensure_incoming_token($store) : '';
    if ($token !== '' && function_exists('jeab_incoming_token_ok') && jeab_incoming_token_ok($token)) {
        return true;
    }
    $action = trim((string) $action);
    if ($action === 'fortnox.orders_read' && function_exists('jeab_inkorg_hook_authorized') && jeab_inkorg_hook_authorized()) {
        return true;
    }

    return $action !== '' && jeab_hook_scoped_authorized($action);
}
