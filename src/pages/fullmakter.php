<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);
/** @var list<array{name:string,address:string,time:string,id:string}> $rows */
$rows = $rows ?? [];
return jeab_fullmakt_signed_list_html($rows);
