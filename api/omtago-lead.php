<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/deploy_guard.php';
jeab_deploy_guard();

/**
 * Omtago → flowstack.example.test. Bara POST. Nyckel i Authorization: Bearer eller X-Api-Key.
 * Nyckelfilen ligger utanför webbroten: privat/omtago_api_key (syskon till webbroten).
 */

$GLOBALS['JEAB_NO_SESSION'] = true;
$GLOBALS['_JEAB_EXTERNAL_ALLOW'] = true;
require dirname(__DIR__) . '/src/bootstrap.php';

if (function_exists('jeab_demo_forbid_feature')) {
    jeab_demo_forbid_feature('omtago');
}

jeab_omtago_http();
