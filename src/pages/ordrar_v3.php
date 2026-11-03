<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shield.php';
jeab_deny_if_direct(__FILE__);
/** @var string $company */
/** @var array|null $open */
/** @var int $openId */
/** @var string|null $flash */
/** @var string|null $error */
/** @var JeabStore|null $store */

// Nya Ordrar-vyn (v3) för ett öppet ärende. Öppning skriver inte.
// Shopnummer, aktivitet och Ellevio-id fylls i av tick eller efter en sparande POST.
if (is_array($open) && function_exists('jeab_contacts_sole_paint_order')) {
    $open = jeab_contacts_sole_paint_order($open);
}
$v3Id = (int) ($open['reminder_id'] ?? 0);
if ($v3Id <= 0) {
    $v3Id = (int) ($_GET['id'] ?? $_GET['open'] ?? 0);
}
$v3Company = jeab_normalize_company($company ?? jeab_selected_company()) ?? 'jarfalla';
$v3Reminder = is_array($reminder ?? null) ? $reminder : jeab_ordrar_reminder_for_row($open, $v3Id);
$v3Flash = is_string($flash ?? null) ? $flash : (is_string($_GET['flash'] ?? null) ? (string) $_GET['flash'] : null);

return jeab_ordrar_v3_html(
    $open,
    $v3Company,
    $v3Id,
    $store ?? null,
    is_string($error ?? null) ? $error : null,
    $v3Flash,
    is_array($v3Reminder) ? $v3Reminder : null
) . '<script src="' . e(jeab_asset_url('assets/js/ordrar-v3.js')) . '" defer></script>'
    . '<script src="' . e(jeab_asset_url('assets/js/fastighet-refresh.js')) . '" defer></script>';
